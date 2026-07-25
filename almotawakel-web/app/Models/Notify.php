<?php
namespace App\Models;

use App\Core\Access;
use App\Core\Database;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * نظام الإشعارات الموحَّد — **نقطة الكتابة الوحيدة** (v52، المرحلة ١).
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * أي حدث بيحصل في النظام بيعدّي من هنا، فبيتسجّل **مرّة واحدة** وبيتوزّع على
 * مستلميه وقنواته تلقائيًّا. النتيجة: الجرس والموبايل بيقروا من نفس المصدر،
 * فمستحيل يقولوا كلامين مختلفين.
 *
 *     Notify::emit('permit.new', [
 *         'title' => '🗂️ إذن 12345',
 *         'body'  => 'المصرية الألمانية — عميل',
 *         'url'   => 'permits/7/manage',
 *         'subject_type' => 'permit', 'subject_id' => 7,
 *     ]);
 *
 * ⚠️ **حدث ≠ حالة.** اللي بيتسجّل هنا هو **حدث وقع وانتهى** (إذن اتفتح، بند
 *    اتعمد). أما **الحالة المستمرة** (٣ أيام مش متقفلة، أرقام سرية ضعيفة) فمكانها
 *    `App\Models\Notification` وبتفضل محسوبة لحظيًّا وما بتدخلش الجدول ده أبدًا.
 *    الخلط بينهم هو أصل مشكلة «الإشعارات المضلِّلة» (docs/15 §٠).
 *
 * ⚠️ **نادِ `emit()` بعد نجاح العملية وخارج أي transaction.** جوّه transaction
 *    وبعدين rollback = بعتنا خبرًا عن حاجة ما حصلتش.
 *
 * ⚠️ **مستحيل يوقّع أي عملية:** كل الدالة ملفوفة في try/catch؛ فشل تسجيل
 *    الإشعار بيتبلع (مع تسجيله في سجل الأخطاء) — إشعار ضايع أهون بكتير من
 *    حفظ بوليصة بيقع.
 *
 * ⚠️ **الصلاحية بتتفحص لكل مستلم على حِدة** (قرار المالك ٧.٥): «إشعارات المكتب
 *    لكل المكتب» — بس البند ما يوصلش حد ممنوع يفتح موضوعه أصلاً. الرفض هو
 *    الافتراضي زي كل النظام.
 *
 * ⚠️ **وصفي بحت — صفر أثر مالي.** ما بيقراش ولا بيكتب أي رصيد/خزنة/عهدة.
 */
class Notify
{
    /**
     * خريطة التوجيه: نوع الحدث → [الجمهور الافتراضي، معالِج الصلاحية].
     *
     * `office` = كل مستخدمي المكتب · `rep` = كل المناديب · `both` = الاتنين.
     * والجمهور ممكن يتحدّد وقت النداء (`rep:12` لمندوب واحد).
     *
     * ⚠️ **ده المكان الوحيد اللي بيقرّر مين ياخد إيه.** ما تكتبش شرط جمهور
     *    بإيدك في أي كنترولر — غيّره هنا وهو بيسري على الجرس والموبايل مع بعض.
     */
    public const ROUTES = [
        'permit.new'            => ['both',   'PermitController@index'],
        'day.closed'            => ['office', 'DailyClosingController@index'],
        'submission.new'        => ['office', 'RepCustodyController@inbox'],
        'bill.new'              => ['office', 'CrudController@index'],
        'submission.approved'   => ['rep',    null],
        'submission.rejected'   => ['rep',    null],
        'custom'                => ['both',   null],
        'test'                  => ['both',   null],
    ];

    /** بيتقفل في الفحوصات لو احتجنا نمنع أي كتابة. */
    private static bool $muted = false;

    public static function mute(bool $on = true): void { self::$muted = $on; }

    /** الجداول اترحّلت؟ (النظام لازم يشتغل عادي قبل الترحيل) */
    public static function available(): bool
    {
        static $has = null;
        if ($has === null) {
            $has = (int) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name IN ('notifications','notification_recipients')") === 2;
        }
        return $has;
    }

    /**
     * تسجيل حدث + توزيعه.
     *
     * @param array{
     *   title:string, body?:string, url?:string, severity?:string,
     *   audience?:string, actor_type?:string, actor_name?:?string,
     *   subject_type?:?string, subject_id?:?int, work_date?:?string,
     *   meta?:array<string,mixed>
     * } $d
     * @return int رقم الحدث، أو 0 لو اتخطّى (بلا ما يرمي أبدًا)
     */
    public static function emit(string $kind, array $d): int
    {
        if (self::$muted || !self::available()) { return 0; }

        // ⚠️ لازم يتعرّف برّه الـtry: الـcatch محتاج يعرف هل المعاملة بتاعتنا
        //    ولا بتاعة المنادي. رجوع معاملة المنادي بسبب فشل إشعار كان هيلغي
        //    **حفظ البوليصة نفسها** — أخطر باج ممكن في الوحدة دي.
        $own = false;

        try {
            [$defaultAudience, $handler] = self::ROUTES[$kind] ?? ['office', null];
            $audience = (string) ($d['audience'] ?? $defaultAudience);

            $parties = self::recipientsFor($audience, $handler);
            if (!$parties) { return 0; }             // مفيش مستلمين → مفيش داعي نسجّل

            // ⚠️ اتحسبوا قبل الاستعلام: `$d['severity'] ?? 'x'` جوّه الشرط الثلاثي
            //    كان بيقرا المفتاح **مرّتين**، فالفرع الصح كان بيرجّع null لما
            //    المفتاح مش موجود ← «Column cannot be null» (اتصادف فعليًّا).
            $severity  = $d['severity'] ?? 'info';
            if (!in_array($severity, ['info', 'warn', 'urgent'], true)) { $severity = 'info'; }
            $actorType = $d['actor_type'] ?? 'system';
            if (!in_array($actorType, ['user', 'rep', 'system'], true)) { $actorType = 'system'; }

            $pdo = Database::conn();
            $own = !$pdo->inTransaction();
            if ($own) { $pdo->beginTransaction(); }

            Database::exec(
                "INSERT INTO notifications
                    (kind, severity, actor_type, actor_name, subject_type, subject_id,
                     work_date, title, body, url, meta_json)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                [
                    mb_substr($kind, 0, 40),
                    $severity,
                    $actorType,
                    isset($d['actor_name']) ? mb_substr((string) $d['actor_name'], 0, 120) : null,
                    isset($d['subject_type']) ? mb_substr((string) $d['subject_type'], 0, 30) : null,
                    isset($d['subject_id']) ? (int) $d['subject_id'] : null,
                    $d['work_date'] ?? (function_exists('work_date') ? work_date() : null),
                    self::clip((string) $d['title'], 120),
                    self::clip((string) ($d['body'] ?? ''), 255),
                    isset($d['url']) ? mb_substr((string) $d['url'], 0, 255) : null,
                    isset($d['meta']) ? json_encode($d['meta'], JSON_UNESCAPED_UNICODE) : null,
                ]
            );
            $id = Database::lastId();

            // ⚠️ الحالة الابتدائية بتيجي من القناة نفسها: في سطر الأوامر بتبقى
            //    `skipped` فالعامل ما يعيدش إرسال حاجة اتولّدت من سكربت استيراد.
            $state = PushNotify::initialState();
            foreach ($parties as $p) {
                Database::exec(
                    "INSERT IGNORE INTO notification_recipients
                        (notification_id, party_type, party_key, push_state)
                     VALUES (?,?,?,?)",
                    [$id, $p['type'], $p['key'], $state]
                );
            }

            if ($own) { $pdo->commit(); }

            // القنوات بعد الحفظ — فشلها ما يرجّعش الحدث نفسه
            PushNotify::dispatch($id);
            return $id;
        } catch (\Throwable $e) {
            // بنرجّع **معاملتنا احنا بس**؛ لو المعاملة بتاعة المنادي نسيبها ليه
            if ($own) {
                try {
                    $pdo = Database::conn();
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                } catch (\Throwable $ignored) {}
            }
            error_log('Notify::emit failed for ' . $kind . ': ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * توسيع الجمهور لمستلمين حقيقيين.
     *
     * ⚠️ **الصلاحية بتتفحص هنا لكل مستخدم مكتب** — «إذن جديد» بيوصل الكل،
     *    لكن أي حدث موضوعه صفحة مقفولة (زي المراقبة الأمنية) بيوصل اللي
     *    بيقدر يفتحها بس.
     *
     * @return array<int,array{type:string,key:string}>
     */
    public static function recipientsFor(string $audience, ?string $handler = null): array
    {
        $out = [];

        if ($audience === 'office' || $audience === 'both' || $audience === 'all') {
            foreach (Database::all("SELECT username, role FROM users") as $u) {
                if ($handler !== null && !Access::allows((string) $u['role'], $handler, 'GET')) { continue; }
                $out[] = ['type' => 'user', 'key' => (string) $u['username']];
            }
        }

        if ($audience === 'rep' || $audience === 'both' || $audience === 'all') {
            foreach (Database::all("SELECT id FROM reps WHERE status = 'active'") as $r) {
                $out[] = ['type' => 'rep', 'key' => (string) $r['id']];
            }
        } elseif (str_starts_with($audience, 'rep:')) {
            $rid = (int) substr($audience, 4);
            if ($rid > 0) { $out[] = ['type' => 'rep', 'key' => (string) $rid]; }
        }

        return $out;
    }

    /** الحدث كامل بصفّه. */
    public static function find(int $id): ?array
    {
        if (!self::available()) { return null; }
        return Database::row("SELECT * FROM notifications WHERE id = ?", [$id]);
    }

    /** مستلمو حدث معيّن. */
    public static function recipientsOf(int $notificationId): array
    {
        if (!self::available()) { return []; }
        return Database::all(
            "SELECT * FROM notification_recipients WHERE notification_id = ?", [$notificationId]);
    }

    /**
     * صندوق وارد شخص (للجرس وصفحة السجل — بيتفعّل عرضه في المرحلة ٢).
     * @return array<int,array<string,mixed>>
     */
    public static function inbox(string $partyType, string $partyKey, int $limit = 30, int $offset = 0): array
    {
        if (!self::available()) { return []; }
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        return Database::all(
            "SELECT n.*, r.read_at, r.id AS recipient_id
               FROM notification_recipients r
               JOIN notifications n ON n.id = r.notification_id
              WHERE r.party_type = ? AND r.party_key = ?
              ORDER BY n.id DESC
              LIMIT $limit OFFSET $offset",
            [$partyType, $partyKey]);
    }

    /** عدد غير المقروء لشخص. */
    public static function unreadCount(string $partyType, string $partyKey): int
    {
        if (!self::available()) { return 0; }
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM notification_recipients
              WHERE party_type = ? AND party_key = ? AND read_at IS NULL",
            [$partyType, $partyKey]);
    }

    /** تعليم بند كمقروء — بتخصّ الشخص فبتتزامن على كل أجهزته. */
    public static function markRead(string $partyType, string $partyKey, int $notificationId): void
    {
        if (!self::available()) { return; }
        Database::exec(
            "UPDATE notification_recipients SET read_at = NOW()
              WHERE party_type = ? AND party_key = ? AND notification_id = ? AND read_at IS NULL",
            [$partyType, $partyKey, $notificationId]);
    }

    /** تعليم الكل كمقروء. */
    public static function markAllRead(string $partyType, string $partyKey): int
    {
        if (!self::available()) { return 0; }
        return Database::exec(
            "UPDATE notification_recipients SET read_at = NOW()
              WHERE party_type = ? AND party_key = ? AND read_at IS NULL",
            [$partyType, $partyKey]);
    }

    private static function clip(string $s, int $len): string
    {
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        return mb_strlen($s) > $len ? mb_substr($s, 0, $len - 1) . '…' : $s;
    }
}
