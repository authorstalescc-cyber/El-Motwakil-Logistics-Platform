<?php
namespace App\Models;

use App\Core\App;
use App\Core\Database;
use App\Core\WebPush;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * إشعارات التطبيق — **واجهة الأحداث + قناة الموبايل** (اتغيّرت في v52).
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * الملف ده كان **مصدر الأحداث** بنفسه (بيبني النص ويبعت للجمهور مباشرةً).
 * من v52 بقى حاجتين واضحتين:
 *
 *   ١) **واجهة الأحداث** (`permitCreated`, `dayClosed`, …) — بتبني النص وبتنادي
 *      `Notify::emit()`. الكنترولرات ما اتغيّرش فيها ولا سطر.
 *   ٢) **قناة الموبايل** (`dispatch`/`flush`/`deliver`) — بتاخد حدثًا متسجّلًا
 *      وتوصّله لأجهزة كل مستلم، وتسجّل نتيجة كل واحد في
 *      `notification_recipients.push_state` عشان العامل يقدر يعيد الفاشل.
 *
 * قرارات المالك (2026-07-25) عن **مين ياخد إيه** موثَّقة ومفروضة في
 * `Notify::ROUTES` — **مش هنا**. غيّرها هناك عشان تسري على الجرس والموبايل معًا.
 *
 * ⚠️ **الإرسال ما بيأخّرش أي عملية:** `dispatch()` بتحطّ رقم الحدث في طابور
 *    ذاكرة، والإرسال في `register_shutdown_function` — يعني **بعد** ما الصفحة
 *    تروح للمستخدم. وأي استثناء بيتبلع ويتسجّل.
 *
 * ⚠️ **سطر الأوامر:** السكربتات والهجرات بتولّد بيانات بالجملة، فالمستلم اللي
 *    بيتعمل من CLI بياخد `push_state='skipped'` — **ما يتبعتش دلوقتي ولا بعدين**.
 *    الاستثناء الوحيد هو **العامل** (`bin/push_worker.php`) اللي بيشغّل
 *    `workerMode(true)` عشان يقدر يبعت المتأخّر والفاشل.
 */
class PushNotify
{
    /** أقصى عدد أجهزة نبعت لها في نداء واحد (حماية من الانفجار). */
    private const MAX_DEVICES_PER_SEND = 200;

    /** @var array<int,int> أرقام الأحداث المستنية إرسال في الطلب ده */
    private static array $queue = [];
    private static bool $hooked = false;
    private static bool $workerMode = false;

    /**
     * ⚠️ **سطر الأوامر مكتوم افتراضيًّا** — من غير الكتم أي سكربت استيراد كان
     * هيبعت مئات الإشعارات الحقيقية لكل الشركة.
     */
    private static ?bool $muted = null;

    public static function mute(bool $on = true): void { self::$muted = $on; }

    /** العامل بس هو اللي بيشغّلها — بتسمح بالإرسال من سطر الأوامر. */
    public static function workerMode(bool $on = true): void { self::$workerMode = $on; }

    /** الإرسال متاح دلوقتي؟ (إعداد + مفاتيح + جداول) */
    public static function enabled(): bool
    {
        if (self::$muted ?? (PHP_SAPI === 'cli' && !self::$workerMode)) { return false; }
        if ((string) App::setting('push_enabled', '1') !== '1') { return false; }
        return PushSubscription::available() && WebPush::available();
    }

    /** حالة الإرسال الابتدائية للمستلم الجديد — مكتوم = `skipped` فمحدش يعيده. */
    public static function initialState(): string
    {
        return self::enabled() ? 'pending' : 'skipped';
    }

    // ═══════════════════════════════════════════════ ① واجهة الأحداث

    /** إذن/شهادة جديدة — للمكتب والمناديب. */
    public static function permitCreated(int $permitId, string $permitNo, string $company, string $client, string $by): void
    {
        $kind = trim($permitNo) !== '' ? 'إذن ' . $permitNo : 'إصدار جديد';
        $line = array_values(array_filter([trim($company), trim($client)]));
        Notify::emit('permit.new', [
            'title'        => '🗂️ ' . $kind,
            'body'         => ($line ? implode(' — ', $line) : 'اتفتح إصدار جديد') . ' · فتحه ' . $by,
            'url'          => 'permits/' . $permitId . '/manage',
            'actor_name'   => $by,
            'actor_type'   => str_starts_with($by, 'مندوب') ? 'rep' : 'user',
            'subject_type' => 'permit',
            'subject_id'   => $permitId,
            'meta'         => ['permit_no' => $permitNo, 'company' => $company, 'client' => $client],
        ]);
    }

    /** تقفيل اليوم — للمكتب. */
    public static function dayClosed(string $date, float $closing, ?string $by): void
    {
        Notify::emit('day.closed', [
            'title'        => '🔒 اتقفل يوم ' . dfmt($date),
            'body'         => 'الرصيد النهائي ' . number_format($closing, 2) . ' ج' . ($by ? ' · قفله ' . $by : ''),
            'url'          => 'closing',
            'actor_name'   => $by,
            'actor_type'   => 'user',
            'subject_type' => 'day',
            'work_date'    => $date,
            'meta'         => ['closing' => $closing],
        ]);
    }

    /** مندوب سجّل بند جديد مستنّي مراجعة — للمكتب. */
    public static function submissionNew(string $repName, string $note, float $amount, bool $isMisc): void
    {
        Notify::emit('submission.new', [
            'title'      => '📥 بند جديد من ' . $repName,
            'body'       => ($isMisc ? '🧷 نثرية: ' : '') . $note
                          . ' · ' . number_format($amount, 2) . ' ج — مستنّي مراجعتك',
            'url'        => 'rep-custody/inbox',
            'actor_name' => $repName,
            'actor_type' => 'rep',
            'meta'       => ['amount' => $amount, 'misc' => $isMisc],
        ]);
    }

    /**
     * قرار في بند مندوب — **للمندوب صاحب البند بس**.
     * ⚠️ قاعدة v35: المبلغ هنا هو اللي المندوب دخّله بنفسه، مش سعر العميل.
     */
    public static function submissionDecided(int $repId, bool $approved, string $note, float $amount, string $reason = ''): void
    {
        if ($repId <= 0) { return; }
        $body = $note . ' · ' . number_format($amount, 2) . ' ج';
        if (!$approved && trim($reason) !== '') { $body .= ' — السبب: ' . $reason; }
        Notify::emit($approved ? 'submission.approved' : 'submission.rejected', [
            'title'      => $approved ? '✅ اتعمد بندك' : '❌ اترفض بندك',
            'body'       => $body,
            'url'        => 'm',
            'audience'   => 'rep:' . $repId,
            'severity'   => $approved ? 'info' : 'warn',
            'actor_type' => 'user',
        ]);
    }

    /**
     * قرار جماعي.
     * ⚠️ **مؤقّتة لحد المرحلة ٣** — قرار المالك إن يبقى **إشعار لكل بند**؛
     *    التجميع هيتشال من هنا ويتحوّل لحارس انفجار في القناة بس.
     */
    public static function submissionsDecidedBulk(int $repId, bool $approved, int $count, string $reason = ''): void
    {
        if ($repId <= 0 || $count <= 0) { return; }
        $body = $approved ? 'المكتب اعتمد بنودك — شوفها في التطبيق' : 'شوف السبب في التطبيق';
        if (!$approved && trim($reason) !== '') { $body = 'السبب: ' . $reason; }
        Notify::emit($approved ? 'submission.approved' : 'submission.rejected', [
            'title'      => $approved ? '✅ اتعمدوا ' . $count . ' بند' : '❌ اترفضوا ' . $count . ' بند',
            'body'       => $body,
            'url'        => 'm',
            'audience'   => 'rep:' . $repId,
            'severity'   => $approved ? 'info' : 'warn',
            'actor_type' => 'user',
        ]);
    }

    /** بوليصة جديدة — للمكتب. */
    public static function billCreated(int $billId, string $billNo, string $company, string $destination): void
    {
        $line = array_values(array_filter([trim($company), trim($destination)]));
        Notify::emit('bill.new', [
            'title'        => '🚚 بوليصة ' . ($billNo !== '' ? $billNo : '#' . $billId),
            'body'         => $line ? implode(' — ', $line) : 'اتسجّلت بوليصة جديدة',
            'url'          => 'bawalis/' . $billId,
            'subject_type' => 'bill',
            'subject_id'   => $billId,
            'actor_type'   => 'user',
        ]);
    }

    /** إشعار مكتوب باليد من شاشة `/push`. */
    public static function custom(string $title, string $body, string $audience, ?string $url, string $by): void
    {
        Notify::emit('custom', [
            'title'      => $title,
            'body'       => $body,
            'url'        => $url,
            'audience'   => $audience,
            'actor_name' => $by,
            'actor_type' => 'user',
        ]);
    }

    // ═══════════════════════════════════════════════ ② قناة الموبايل

    /** بيتنادى من `Notify::emit` بعد ما الحدث يتسجّل. */
    public static function dispatch(int $notificationId): void
    {
        if ($notificationId <= 0) { return; }
        if (!self::enabled()) {
            // مكتوم (CLI مثلاً) → نعلّم المستلمين «متخطّى» فالعامل ما يعيدهمش
            try {
                Database::exec(
                    "UPDATE notification_recipients SET push_state='skipped'
                      WHERE notification_id = ? AND push_state='pending'", [$notificationId]);
            } catch (\Throwable $e) {}
            return;
        }
        self::$queue[] = $notificationId;
        if (!self::$hooked) {
            self::$hooked = true;
            register_shutdown_function([self::class, 'flush']);
        }
    }

    /**
     * الإرسال الفعلي — بعد ما الصفحة تكون اتبعتت.
     *
     * ⚠️ `fastcgi_finish_request` بتقفل الاتصال بالمستخدم الأول — من غيرها
     *    المتصفح بيفضل مستنّي رد جوجل/آبل والمالك يحسّ إن الحفظ تقيل.
     */
    public static function flush(): void
    {
        $ids = self::$queue;
        self::$queue = [];
        if (!$ids) { return; }

        if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
        elseif (function_exists('litespeed_finish_request')) { @litespeed_finish_request(); }

        foreach ($ids as $id) {
            try { self::deliver((int) $id); }
            catch (\Throwable $e) { error_log('PushNotify::deliver failed #' . $id . ': ' . $e->getMessage()); }
        }
    }

    /**
     * توصيل حدث لكل مستلميه المستنيين.
     * @return array{sent:int,failed:int,skipped:int}
     */
    public static function deliver(int $notificationId): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        $n = Notify::find($notificationId);
        if (!$n) { return $out; }

        $recips = Database::all(
            "SELECT * FROM notification_recipients
              WHERE notification_id = ? AND push_state IN ('pending','failed')",
            [$notificationId]);
        if (!$recips) { return $out; }

        $payload = [
            'title' => (string) $n['title'],
            'body'  => (string) $n['body'],
            'url'   => $n['url'] ? url((string) $n['url']) : url(''),
            'kind'  => (string) $n['kind'],
            'tag'   => (string) $n['kind'] . ':' . $notificationId,
        ];

        $okTotal = 0; $failTotal = 0;
        foreach ($recips as $r) {
            $devices = PushSubscription::forParty((string) $r['party_type'], (string) $r['party_key']);
            if (!$devices) {
                // ⚠️ مفيش أجهزة = **متخطّى** مش فاشل — وإلا العامل يفضل يعيد للأبد
                self::setState((int) $r['id'], 'skipped', 0, null);
                $out['skipped']++;
                continue;
            }
            $devices = array_slice($devices, 0, self::MAX_DEVICES_PER_SEND);
            $res = WebPush::send($devices, $payload);

            $anyOk = false; $lastErr = null;
            foreach ($devices as $i => $dev) {
                $x = $res[$i] ?? ['ok' => false, 'gone' => false, 'status' => 0, 'error' => ''];
                if ($x['ok']) { $anyOk = true; $okTotal++; }
                else { $failTotal++; $lastErr = ((int) $x['status'] ?: '؟') . ' ' . (string) $x['error']; }
                PushSubscription::recordResult((int) $dev['id'], (bool) $x['ok'], (bool) $x['gone']);
            }
            self::setState((int) $r['id'], $anyOk ? 'sent' : 'failed',
                           (int) $r['push_attempts'] + 1, $anyOk ? null : $lastErr);
            $anyOk ? $out['sent']++ : $out['failed']++;
        }

        self::log($n, $okTotal, $failTotal);
        return $out;
    }

    /**
     * الطابور المتأخّر: اللي فشل أو اتسجّل ومااتبعتش.
     * بيستخدمه `bin/push_worker.php` بس.
     *
     * ⚠️ المقارنة الزمنية **جوّه SQL** — ساعة القاعدة UTC وPHP القاهرة (قاعدة v48).
     */
    public static function deliverPending(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $ids = Database::all(
            "SELECT DISTINCT notification_id FROM notification_recipients
              WHERE (push_state = 'failed' AND push_attempts < 3)
                 OR (push_state = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE))
              ORDER BY notification_id DESC
              LIMIT $limit");
        $total = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'events' => count($ids)];
        foreach ($ids as $row) {
            $r = self::deliver((int) $row['notification_id']);
            $total['sent'] += $r['sent']; $total['failed'] += $r['failed']; $total['skipped'] += $r['skipped'];
        }
        return $total;
    }

    private static function setState(int $recipientId, string $state, int $attempts, ?string $err): void
    {
        Database::exec(
            "UPDATE notification_recipients
                SET push_state = ?, push_attempts = ?, push_last_error = ?
              WHERE id = ?",
            [$state, min(255, $attempts), $err !== null ? mb_substr($err, 0, 160) : null, $recipientId]);
    }

    // ═══════════════════════════════════════════════ ③ تشخيص وسجل

    /**
     * إرسال **فوري لأجهزة جمهور** بيرجّع النتيجة الحقيقية — لزرار «إشعار تجربة».
     *
     * ⚠️ ده اختبار **للقناة نفسها** فمش بيمرّ على جدول الأحداث عن قصد: لو فيه
     *    مشكلة في التوجيه أو التسجيل، التجربة لازم تفضل قادرة تقول لك إن
     *    الأجهزة نفسها سليمة.
     *
     * @return array{total:int,ok:int,fail:int,errors:array<int,string>}
     */
    public static function sendNow(string $title, string $body, string $audience, ?string $url, string $by): array
    {
        $subs = PushSubscription::forAudience($audience);
        $job = ['kind' => 'test', 'audience' => $audience, 'title' => $title, 'body' => $body, 'url' => $url, 'by' => $by];
        if (!$subs) { self::log($job, 0, 0); return ['total' => 0, 'ok' => 0, 'fail' => 0, 'errors' => []]; }

        $res = WebPush::send($subs, [
            'title' => $title, 'body' => $body,
            'url'   => $url ? url($url) : url(''), 'kind' => 'test', 'tag' => 'test',
        ]);
        $ok = 0; $fail = 0; $errors = [];
        foreach ($subs as $i => $sub) {
            $r = $res[$i] ?? ['ok' => false, 'gone' => false, 'status' => 0, 'error' => ''];
            if ($r['ok']) { $ok++; } else {
                $fail++;
                $errors[] = ($sub['audience'] === 'rep' ? 'مندوب' : 'مكتب') . ' — ' . (int) $r['status']
                          . ($r['error'] ? ' (' . $r['error'] . ')' : '');
            }
            PushSubscription::recordResult((int) $sub['id'], (bool) $r['ok'], (bool) $r['gone']);
        }
        self::log($job, $ok, $fail);
        return ['total' => count($subs), 'ok' => $ok, 'fail' => $fail, 'errors' => $errors];
    }

    /** سجل ملخّص الإرسال (للعرض في `/push`). */
    private static function log(array $job, int $ok, int $fail): void
    {
        try {
            Database::exec(
                "INSERT INTO push_messages (kind, audience, title, body, url, sent_by, ok_count, fail_count)
                 VALUES (?,?,?,?,?,?,?,?)",
                [
                    mb_substr((string) $job['kind'], 0, 30),
                    mb_substr((string) ($job['audience'] ?? '-'), 0, 30),
                    mb_substr((string) $job['title'], 0, 120),
                    mb_substr((string) $job['body'], 0, 255),
                    isset($job['url']) && $job['url'] !== null ? mb_substr((string) $job['url'], 0, 255) : null,
                    $job['by'] ?? null, $ok, $fail,
                ]);
        } catch (\Throwable $e) {
            // السجل رفاهية — فشله ما يعطّلش حاجة
        }
    }

    /** عدد الأجهزة اللي هيوصلها جمهور معيّن. */
    public static function audienceCount(string $audience): int
    {
        return count(PushSubscription::forAudience($audience));
    }

    /** آخر الإشعارات المرسَلة — لشاشة `/push`. */
    public static function recent(int $limit = 30): array
    {
        if (!PushSubscription::available()) { return []; }
        $limit = max(1, min(200, $limit));
        return Database::all("SELECT * FROM push_messages ORDER BY id DESC LIMIT $limit");
    }
}
