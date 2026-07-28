<?php
namespace App\Models;

use App\Core\App;
use App\Core\Database;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * التذكيرات التلقائية والملخّص اليومي والتنبيهات الذكية (المرحلة ٧ من docs/15).
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * لحد المرحلة ٦ كل الإشعارات كانت **رد فعل** على حاجة المستخدم عملها. هنا
 * النظام بقى **يبتدي هو**: بند مستنّي بقاله ٤ ساعات · يوم فاتت عليه أيام
 * ومتقفلش · إذن مفتوح من أسبوع بلا حركة · عهدة مندوب قربت تخلص · وملخّص
 * الصبح بأرقام إمبارح.
 *
 * ⚠️ **الملف ده بيتنادى من `bin/push_worker.php` (كرون كل ٥ دقايق).** يعني كل
 *    فحص هنا بيتنفّذ **٢٨٨ مرّة في اليوم** — فأي حدث بيتبعت من غير
 *    `Notify::emitOnce` معناه ٢٨٨ رنّة. **ممنوع تنادي `emit` مباشرةً من هنا.**
 *
 * ⚠️ **قراءة فقط من بيانات النظام.** بينادي نفس دوال المصدر اللي بتغذّي
 *    الشاشات (`RepInbox::pendingCount` · `Closing::lastClosedDate` · …) فمستحيل
 *    التذكير يقول رقمًا والشاشة تقول غيره. **صفر كتابة** غير في جدول الإشعارات.
 *
 * ⚠️ **وصفي بحت — صفر أثر مالي.** ما بيلمسش خزنة ولا تقفيل ولا عهدة.
 *
 * ⛔ **مفيش «ساعات هدوء»** (قرار المالك §٧.٤) — التذكير بيتبعت في وقته.
 */
class NotifyWatch
{
    /** بند مستنّي أكتر من كده = تذكير (ساعات). */
    private const SUBMISSION_WARN  = 4;
    /** ومستنّي أكتر من كده = تنبيه أهم. */
    private const SUBMISSION_URGENT = 24;

    /** إذن مفتوح بلا أي حركة أكتر من كده = ساكن (أيام). */
    private const PERMIT_IDLE_DAYS = 7;

    /** أيام مش متقفلة أكتر من كده = تذكير. */
    private const CLOSING_LATE_DAYS = 2;

    /** عهدة المندوب لما تقلّ عن كده = تنبيه (جنيه). */
    private const CUSTODY_LOW = 500.0;

    /** الملخّص اليومي بيتبعت الساعة كام (٢٤س، بساعة الخادم). */
    private const DIGEST_HOUR = 8;

    /**
     * تشغيلة واحدة — بيتنادى من العامل.
     * @return array<string,int> عدد الأحداث اللي اتولّدت من كل فحص
     */
    public static function run(): array
    {
        $out = ['reminders' => 0, 'closing' => 0, 'idle' => 0, 'custody' => 0, 'digest' => 0];

        // إعادة حساب رقم «مسميات مكرَّرة» لو بقى قديم — مكانه هنا لا في الجرس:
        // الحساب ١٤٠ مللي ثانية والجرس بينادي كل ٦٠ ثانية لكل مستخدم، و`Notification`
        // عقدها **قراءة فقط**. الكرون بيجري كل ٥ دقايق فالرقم بيفضل طازة.
        try {
            if (DataQuality::badgeStale()) { DataQuality::refreshBadge(); }
        } catch (\Throwable $e) { /* التنظيف مالوش علاقة بالإشعارات — ما يوقّفش الكرون */ }

        if (!Notify::available()) { return $out; }

        // كل فحص لوحده في try/catch: واحد بايظ ما يوقّفش الباقي ولا يكسر الكرون
        foreach ([
            'reminders' => [self::class, 'submissionReminders'],
            'closing'   => [self::class, 'closingReminder'],
            'idle'      => [self::class, 'idlePermits'],
            'custody'   => [self::class, 'lowCustody'],
            'digest'    => [self::class, 'dailyDigest'],
        ] as $key => $fn) {
            try { $out[$key] = (int) $fn(); }
            catch (\Throwable $e) { error_log('NotifyWatch::' . $key . ' failed: ' . $e->getMessage()); }
        }
        return $out;
    }

    /* ═══════════════════════════════════════════ ① تذكيرات تصاعدية */

    /**
     * بنود مناديب مستنية مراجعة بقالها فترة.
     *
     * ⚠️ **تصاعدي بمرحلتين**: بعد ٤ ساعات تذكير عادي، وبعد ٢٤ ساعة تنبيه
     *    «حرج». والاتنين بنافذة `emitOnce` مختلفة فما بيدوسوش على بعض.
     */
    private static function submissionReminders(): int
    {
        if (!RepCustody::available()) { return 0; }
        $sent = 0;

        foreach ([
            [self::SUBMISSION_URGENT, 24, 'urgent', '🔴'],
            [self::SUBMISSION_WARN,   6,  'warn',   '⏳'],
        ] as [$hours, $window, $sev, $mark]) {
            // ⚠️ المقارنة الزمنية جوّه SQL (قاعدة v48)
            $n = (int) Database::scalar(
                "SELECT COUNT(*) FROM rep_submissions
                  WHERE status = 'pending'
                    AND created_at < DATE_SUB(NOW(), INTERVAL $hours HOUR)");
            if ($n < 1) { continue; }

            // الأحدث بس: لو فيه بند بقاله ٢٤ ساعة، ما نبعتش تذكير الـ٤ ساعات كمان
            if ($sev === 'warn' && self::emittedWithin('remind.submissions', 24)) { break; }

            $id = Notify::emitOnce('remind.submissions', $window, [
                'title'    => $mark . ' ' . intf($n) . ' بند مستنّي مراجعتك',
                'body'     => 'بقالهم أكتر من ' . intf($hours) . ' ساعة في صندوق المراجعة.',
                'url'      => 'rep-custody/inbox',
                'severity' => $sev,
                'meta'     => ['count' => $n, 'hours' => $hours],
            ]);
            if ($id > 0) { $sent++; break; }   // تذكير واحد في المرّة يكفي
        }
        return $sent;
    }

    /** أيام فيها حركة ولسه مش متقفلة. */
    private static function closingReminder(): int
    {
        $last = Closing::lastClosedDate();
        if ($last === null) { return 0; }
        $days = (int) floor((strtotime(date('Y-m-d')) - strtotime($last)) / 86400) - 1;
        if ($days < self::CLOSING_LATE_DAYS) { return 0; }

        return Notify::emitOnce('remind.closing', 20, [
            'title'    => '📋 ' . intf($days) . ' يوم لسه مش متقفل',
            'body'     => 'آخر يوم متقفل ' . dfmt($last) . ' — الإقفال تسلسلي من الأقدم.',
            'url'      => 'closing',
            'severity' => $days >= 4 ? 'urgent' : 'warn',
            'meta'     => ['days' => $days, 'last' => $last],
        ]) > 0 ? 1 : 0;
    }

    /* ═══════════════════════════════════════════ ② تنبيهات ذكية */

    /**
     * أذونات مفتوحة بلا أي حركة من فترة.
     *
     * ⚠️ **حدث لكل إذن بموضوعه** (`subject_type=permit`) — فبصمة `emitOnce`
     *    بتفرّق بين إذن وإذن، ومستحيل إذن يبلع تنبيه إذن تاني.
     */
    private static function idlePermits(): int
    {
        $d = self::PERMIT_IDLE_DAYS;
        // آخر حركة = آخر بند اتسجّل على الإذن، وإلا تاريخ الإذن نفسه
        $rows = Database::all(
            "SELECT p.id, p.permit_no, p.client, p.company,
                    GREATEST(COALESCE(MAX(s.entry_date), p.permit_date), p.permit_date) AS last_move
               FROM permits p
               LEFT JOIN permit_stages s ON s.permit_id = p.id
              WHERE p.status = 'open'
              GROUP BY p.id, p.permit_no, p.client, p.company, p.permit_date
             HAVING last_move < DATE_SUB(CURDATE(), INTERVAL $d DAY)
              ORDER BY last_move ASC
              LIMIT 5");

        $sent = 0;
        foreach ($rows as $r) {
            $days = (int) floor((strtotime(date('Y-m-d')) - strtotime((string) $r['last_move'])) / 86400);
            $who  = trim((string) ($r['client'] ?? '')) ?: trim((string) ($r['company'] ?? ''));
            $id = Notify::emitOnce('alert.permit_idle', 168, [   // مرّة كل أسبوع للإذن الواحد
                'title'        => '⏸️ إذن ' . (($r['permit_no'] ?? '') !== '' ? $r['permit_no'] : '#' . $r['id']) . ' ساكن',
                'body'         => 'مفتوح من ' . intf($days) . ' يوم بلا أي حركة'
                                . ($who !== '' ? ' · ' . $who : ''),
                'url'          => 'permits/' . (int) $r['id'],
                'severity'     => 'warn',
                'subject_type' => 'permit',
                'subject_id'   => (int) $r['id'],
                'meta'         => ['idle_days' => $days],
            ]);
            if ($id > 0) { $sent++; }
        }
        return $sent;
    }

    /**
     * مناديب عهدتهم اليومية قربت تخلص.
     *
     * ⚠️ **بيقرا `RepCustody::dayTotalsByRep` نفسها** اللي بتغذّي شاشة العهدة
     *    والداشبورد — مفيش تعريف تاني للرصيد ينفع يختلف عنها.
     */
    private static function lowCustody(): int
    {
        if (!RepCustody::available()) { return 0; }
        $day = date('Y-m-d');
        $map = RepCustody::dayTotalsByRep($day);
        if (!$map) { return 0; }

        $sent = 0;
        foreach ($map as $repId => $t) {
            $deposits = (float) ($t['deposits'] ?? 0);
            $balance  = (float) ($t['balance'] ?? 0);
            // نزّلوا له فعلاً النهاردة، والفاضل قلّ — مش مندوب ما نزلش له أصلاً
            if ($deposits <= 0 || $balance > self::CUSTODY_LOW) { continue; }

            $name = (string) (Database::scalar('SELECT name FROM reps WHERE id = ?', [(int) $repId]) ?? 'مندوب');
            $id = Notify::emitOnce('alert.rep_custody', 12, [
                'title'        => '👛 عهدة ' . $name . ' قربت تخلص',
                'body'         => 'الفاضل ' . number_format($balance, 2) . ' ج من ' . number_format($deposits, 2) . ' ج نزلت النهاردة.',
                'url'          => 'rep-custody/' . (int) $repId,
                'severity'     => $balance <= 0 ? 'urgent' : 'warn',
                'subject_type' => 'rep',
                'subject_id'   => (int) $repId,
                'work_date'    => $day,
            ]);
            if ($id > 0) { $sent++; }
        }
        return $sent;
    }

    /* ═══════════════════════════════════════════ ③ الملخّص اليومي */

    /**
     * ملخّص الصبح بأرقام إمبارح.
     *
     * ⚠️ **بيتبعت مرّة واحدة في اليوم** — الحارس مزدوج: علامة في `settings`
     *    (أرخص فحص) + `emitOnce` بنافذة ٢٠ ساعة (الحارس الحقيقي لو العلامة
     *    ضاعت). العامل بيعدّي هنا ٢٨٨ مرّة، فالحارس مش رفاهية.
     */
    private static function dailyDigest(): int
    {
        if ((int) date('G') < self::DIGEST_HOUR) { return 0; }
        $today = date('Y-m-d');
        if ((string) App::setting('notify_digest_last', '') === $today) { return 0; }

        $y = date('Y-m-d', strtotime('-1 day'));
        $bits = [];

        try {
            $permits = (int) Database::scalar('SELECT COUNT(*) FROM permits WHERE permit_date = ?', [$y]);
            if ($permits > 0) { $bits[] = intf($permits) . ' إذن'; }
        } catch (\Throwable $e) {}
        try {
            $bills = (int) Database::scalar(
                'SELECT COUNT(*) FROM bawalis WHERE bill_date = ? AND (is_legacy = 0 OR is_legacy IS NULL)', [$y]);
            if ($bills > 0) { $bits[] = intf($bills) . ' بوليصة'; }
        } catch (\Throwable $e) {}
        try {
            if (RepCustody::available()) {
                $subs = (int) Database::scalar('SELECT COUNT(*) FROM rep_submissions WHERE entry_date = ?', [$y]);
                if ($subs > 0) { $bits[] = intf($subs) . ' بند من المناديب'; }
            }
        } catch (\Throwable $e) {}

        $balance = null;
        try { $balance = Closing::currentBalance(); } catch (\Throwable $e) {}

        // يوم فاضي مالوش لزمة يترنّ — بس بنعلّم إننا عدّينا عليه
        if (!$bits) {
            App::setSetting('notify_digest_last', $today);
            return 0;
        }

        $body = implode(' · ', $bits);
        if ($balance !== null) { $body .= ' — الخزنة ' . number_format($balance, 2) . ' ج'; }

        $id = Notify::emitOnce('digest.daily', 20, [
            'title'     => '📊 ملخّص ' . dfmt($y),
            'body'      => $body,
            'url'       => '',
            'severity'  => 'info',
            'work_date' => $y,
        ]);
        App::setSetting('notify_digest_last', $today);
        return $id > 0 ? 1 : 0;
    }

    /* ═══════════════════════════════════════════ مساعدات */

    /** اتبعت حدث من النوع ده خلال آخر N ساعة؟ (مقارنة جوّه SQL — قاعدة v48) */
    private static function emittedWithin(string $kind, int $hours): bool
    {
        $h = max(1, min(8760, $hours));
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM notifications
              WHERE kind = ? AND created_at >= DATE_SUB(NOW(), INTERVAL $h HOUR)", [$kind]) > 0;
    }

    /**
     * تحليلات: أنهي أنواع بتتقرا وأنهي بتتجاهل (للعرض في `/push`).
     *
     * ⚠️ **الفايدة الحقيقية منها**: النوع اللي نسبة قراءته صفر معناه إشعار
     *    مالوش لازمة — يتقفل من التفضيلات بدل ما يفضل يزنّ.
     *
     * @return array<int,array{kind:string,label:string,total:int,read:int,rate:float}>
     */
    public static function readStats(int $days = 30): array
    {
        if (!Notify::available()) { return []; }
        $d = max(1, min(365, $days));
        $rows = Database::all(
            "SELECT n.kind, COUNT(*) total, SUM(r.read_at IS NOT NULL) rd
               FROM notification_recipients r
               JOIN notifications n ON n.id = r.notification_id
              WHERE r.created_at >= DATE_SUB(NOW(), INTERVAL $d DAY)
              GROUP BY n.kind
              ORDER BY total DESC");

        $out = [];
        foreach ($rows as $r) {
            $total = (int) $r['total'];
            $read  = (int) $r['rd'];
            $out[] = [
                'kind'  => (string) $r['kind'],
                'label' => Notify::kindMeta((string) $r['kind'])['label'],
                'total' => $total,
                'read'  => $read,
                'rate'  => $total > 0 ? round($read / $total * 100, 1) : 0.0,
            ];
        }
        return $out;
    }
}
