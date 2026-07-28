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

    /**
     * حارس الانفجار (المرحلة ٣، قرار المالك ٧.٣) — **للموبايل بس**.
     *
     * أكتر من `BURST_MAX` إشعار **لنفس الشخص في نفس الدفعة** → بيتبعت العدد
     * ده ورنّة واحدة «و N كمان». **السجل بيفضل بند لكل حدث** بلا أي تجميع —
     * اللي اتلغى هو الرنّة لا البند. البند المكتوم بياخد `push_state='skipped'`
     * وعلامة `BURST_MARK` في `push_last_error` فيبان في التشخيص وما يتعادش.
     */
    private const BURST_MAX  = 5;
    private const BURST_MARK = 'burst';

    /**
     * أقصى عدد محاولات إرسال قبل ما نسيب البند (المرحلة ٥).
     * التباعد بين المحاولات: ٥ دقايق ← ٣٠ ← ١٢٠ (في `deliverPending`).
     */
    public const MAX_ATTEMPTS = 3;

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

    /**
     * مندوب سجّل بند جديد مستنّي مراجعة — للمكتب.
     * `$permitNo` بيخلّي المكتب يعرف البند على أنهي إذن **من الإشعار نفسه**
     * بدل ما يفتح الصندوق يدوّر (المرحلة ٣ — نصوص أغنى).
     */
    public static function submissionNew(string $repName, string $note, float $amount, bool $isMisc, string $permitNo = ''): void
    {
        $bits = [($isMisc ? '🧷 نثرية: ' : '') . $note, number_format($amount, 2) . ' ج'];
        if (!$isMisc && trim($permitNo) !== '') { $bits[] = 'إذن ' . trim($permitNo); }
        Notify::emit('submission.new', [
            'title'      => '📥 بند جديد من ' . $repName,
            'body'       => implode(' · ', $bits) . ' — مستنّي مراجعتك',
            'url'        => 'rep-custody/inbox',
            'actor_name' => $repName,
            'actor_type' => 'rep',
            'meta'       => ['amount' => $amount, 'misc' => $isMisc, 'permit_no' => $permitNo],
        ]);
    }

    /**
     * قرار في بند مندوب — **للمندوب صاحب البند بس**، **بند لكل حدث**.
     *
     * ⚠️ قاعدة v35: المبلغ هنا هو اللي المندوب دخّله بنفسه، مش سعر العميل
     *    (`actual_amount`/`actual_unit`) — ممنوع يوصل شاشته ولا إشعاره.
     *
     * ⚠️ القرار الجماعي بينادّيها **مرّة لكل بند** (المرحلة ٣) — التقليل مكانه
     *    قناة الموبايل بس (`deliverMany` → حارس الانفجار).
     */
    public static function submissionDecided(int $repId, bool $approved, string $note, float $amount, string $reason = '', string $permitNo = '', string $day = ''): void
    {
        if ($repId <= 0) { return; }
        $bits = [$note, number_format($amount, 2) . ' ج'];
        if (trim($permitNo) !== '') { $bits[] = 'إذن ' . trim($permitNo); }
        if (trim($day) !== '')      { $bits[] = 'يوم ' . dfmt($day); }
        $body = implode(' · ', $bits);
        if (!$approved && trim($reason) !== '') { $body .= ' — السبب: ' . $reason; }
        Notify::emit($approved ? 'submission.approved' : 'submission.rejected', [
            'title'      => $approved ? '✅ اتعمد بندك' : '❌ اترفض بندك',
            'body'       => $body,
            'url'        => 'm',
            'audience'   => 'rep:' . $repId,
            'severity'   => $approved ? 'info' : 'warn',
            'actor_type' => 'user',
            'work_date'  => $day !== '' ? $day : null,
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

    // ═══════════════════════════════════════ أنواع المرحلة ٣ (docs/15 §٣.٤)

    /** بوليصة اتربطت بإذن (اتعملت من جوّه صفحة الإذن) — للمكتب. */
    public static function billLinked(int $billId, string $billNo, int $permitId, string $permitNo, string $owner): void
    {
        $line = array_values(array_filter([
            $permitNo !== '' ? 'إذن ' . $permitNo : 'إذن #' . $permitId,
            trim($owner) !== '' ? 'صاحب البيان ' . trim($owner) : '',
        ]));
        Notify::emit('bill.linked', [
            'title'        => '🚚 بوليصة ' . ($billNo !== '' ? $billNo : '#' . $billId) . ' على إذن',
            'body'         => implode(' · ', $line),
            'url'          => 'permits/' . $permitId . '/manage',
            'subject_type' => 'bill',
            'subject_id'   => $billId,
            'actor_type'   => 'user',
        ]);
    }

    /** مستند مالي اتصدر (فاتورة/كشف/مطالبة) — للمكتب. */
    public static function invoiceIssued(int $invoiceId, string $docLabel, string $client, float $total, string $by): void
    {
        Notify::emit('invoice.new', [
            'title'        => '🧾 ' . $docLabel . ' اتصدر',
            'body'         => array_filter([trim($client), number_format($total, 2) . ' ج'])
                              ? implode(' · ', array_filter([trim($client), number_format($total, 2) . ' ج'])) . ' — أصدره ' . $by
                              : 'أصدره ' . $by,
            'url'          => 'permits/invoice/' . $invoiceId,
            'actor_name'   => $by,
            'actor_type'   => 'user',
            'subject_type' => 'invoice',
            'subject_id'   => $invoiceId,
            'meta'         => ['total' => $total, 'client' => $client],
        ]);
    }

    /** إذن اتقفل أو اترجع مفتوح — للمكتب. */
    public static function permitClosed(int $permitId, string $permitNo, bool $closed, string $by): void
    {
        Notify::emit('permit.closed', [
            'title'        => ($closed ? '✅ اتقفل إذن ' : '↩️ اترجع مفتوح إذن ') . ($permitNo !== '' ? $permitNo : '#' . $permitId),
            'body'         => ($closed ? 'الإذن خلص وبقى جاهز للفوترة' : 'الإذن اترجع مفتوح تاني') . ' · ' . $by,
            'url'          => 'permits/' . $permitId,
            'actor_name'   => $by,
            'actor_type'   => 'user',
            'subject_type' => 'permit',
            'subject_id'   => $permitId,
        ]);
    }

    /** تحصيل من عميل — للمكتب. */
    public static function clientPaid(string $client, float $amount, string $route, string $date, string $by): void
    {
        $routeLbl = ['cash' => 'دخلت الخزنة', 'visa' => 'اتشحنت على كارت فيزا'][$route] ?? 'في حساب العميل بس';
        Notify::emit('client.payment', [
            'title'      => '💵 تحصيل من ' . ($client !== '' ? $client : 'عميل'),
            'body'       => number_format($amount, 2) . ' ج · ' . $routeLbl . ' · سجّله ' . $by,
            'url'        => 'payments',
            'actor_name' => $by,
            'actor_type' => 'user',
            'work_date'  => $date,
            'meta'       => ['amount' => $amount, 'route' => $route],
        ]);
    }

    /** عهدة نزلت لمندوب — للمكتب. */
    public static function repDeposit(int $repId, string $repName, float $amount, string $date, string $by): void
    {
        Notify::emit('rep.deposit', [
            'title'        => '👛 نزلت عهدة لـ' . $repName,
            'body'         => number_format($amount, 2) . ' ج · يوم ' . dfmt($date) . ' · نزّلها ' . $by,
            'url'          => 'rep-custody/' . $repId,
            'actor_name'   => $by,
            'actor_type'   => 'user',
            'subject_type' => 'rep',
            'subject_id'   => $repId,
            'work_date'    => $date,
            'meta'         => ['amount' => $amount],
        ]);
    }

    /** تسوية حساب عهدة مندوب — للمكتب. */
    public static function repSettled(int $repId, string $repName, float $returned, string $date, string $by): void
    {
        Notify::emit('rep.settlement', [
            'title'        => '🤝 اتسوّى حساب ' . $repName,
            'body'         => 'المرتجع ' . number_format($returned, 2) . ' ج · يوم ' . dfmt($date) . ' · سوّاه ' . $by,
            'url'          => 'rep-custody/' . $repId,
            'actor_name'   => $by,
            'actor_type'   => 'user',
            'subject_type' => 'rep',
            'subject_id'   => $repId,
            'work_date'    => $date,
            'meta'         => ['returned' => $returned],
        ]);
    }

    /** مندوب سلّم عهدة لسائق — للمكتب. */
    public static function driverHandover(string $repName, string $driver, float $amount, string $date): void
    {
        Notify::emit('driver.handover', [
            'title'      => '🚛 ' . $repName . ' سلّم عهدة لسائق',
            'body'       => ($driver !== '' ? $driver . ' · ' : '') . number_format($amount, 2) . ' ج · يوم ' . dfmt($date),
            'url'        => 'rep-custody',
            'actor_name' => $repName,
            'actor_type' => 'rep',
            'work_date'  => $date,
            'meta'       => ['amount' => $amount],
        ]);
    }

    /** تحويل عهدة من مندوب لمندوب — للمكتب. */
    public static function repTransfer(string $fromName, string $toName, float $amount, string $date): void
    {
        Notify::emit('rep.transfer', [
            'title'      => '🔁 ' . $fromName . ' حوّل لـ' . $toName,
            'body'       => number_format($amount, 2) . ' ج · يوم ' . dfmt($date),
            'url'        => 'rep-custody',
            'actor_name' => $fromName,
            'actor_type' => 'rep',
            'work_date'  => $date,
            'meta'       => ['amount' => $amount],
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

        try { self::deliverMany($ids); }
        catch (\Throwable $e) { error_log('PushNotify::flush failed: ' . $e->getMessage()); }
    }

    /** توصيل حدث واحد — غلاف رفيع فوق `deliverMany`. */
    public static function deliver(int $notificationId): array
    {
        return self::deliverMany([$notificationId]);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * توصيل دفعة أحداث — **وهنا حارس الانفجار** (المرحلة ٣، قرار ٧.٣).
     * ═══════════════════════════════════════════════════════════════════════
     *
     * ⚠️ **القاعدة اللي الوحدة كلها قايمة عليها:** السجل بيفضل **بند لكل حدث
     *    دايمًا**؛ الحارس ده بيقلّل **رنّات الموبايل بس**. اعتماد ٧ بنود =
     *    ٧ صفوف في السجل، لكن ٥ رنّات + رنّة «و٢ كمان».
     *
     * ⚠️ **ليه على مستوى الدفعة مش لكل حدث لوحده:** القرار الجماعي بيولّد كل
     *    أحداثه في **نفس الطلب**، فالطابور بيشوفهم مع بعض ويعرف **N بالظبط**.
     *    لو الحارس كان جوّه `deliver()` لحدث واحد، كان لازم يخمّن الجاي —
     *    ولا كان هيقدر يقول «و N كمان» أصلاً.
     *
     * ⚠️ التجميع **بالشخص مش بالحدث**: الحدث الواحد ممكن يروح ١٢ مستلمًا،
     *    وكل واحد فيهم له سقفه هو.
     *
     * @param array<int,int> $ids
     * @return array{sent:int,failed:int,skipped:int,burst:int}
     */
    public static function deliverMany(array $ids): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'burst' => 0];
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_filter($ids, static fn(int $i): bool => $i > 0);
        if (!$ids) { return $out; }

        $notifs = [];
        foreach ($ids as $id) {
            $n = Notify::find($id);
            if ($n) { $notifs[$id] = $n; }
        }
        if (!$notifs) { return $out; }

        $in = implode(',', array_map('intval', array_keys($notifs)));
        $recips = Database::all(
            "SELECT * FROM notification_recipients
              WHERE notification_id IN ($in) AND push_state IN ('pending','failed')
              ORDER BY notification_id ASC, id ASC");
        if (!$recips) { return $out; }

        // تجميع بالشخص — «كام إشعار لفلان في الدفعة دي»
        $byParty = [];
        foreach ($recips as $r) {
            $byParty[$r['party_type'] . '|' . $r['party_key']][] = $r;
        }

        // ok/fail لكل حدث عشان سجل `push_messages` يفضل دقيقًا
        $stats = [];
        foreach (array_keys($notifs) as $id) { $stats[$id] = ['ok' => 0, 'fail' => 0]; }

        foreach ($byParty as $key => $rows) {
            [$partyType, $partyKey] = explode('|', $key, 2);
            $devices = PushSubscription::forParty($partyType, $partyKey);
            if (!$devices) {
                // ⚠️ مفيش أجهزة = **متخطّى** مش فاشل — وإلا العامل يفضل يعيد للأبد
                foreach ($rows as $r) { self::setState((int) $r['id'], 'skipped', 0, null); $out['skipped']++; }
                continue;
            }
            $devices = array_slice($devices, 0, self::MAX_DEVICES_PER_SEND);

            $send = array_slice($rows, 0, self::BURST_MAX);
            $rest = array_slice($rows, self::BURST_MAX);

            foreach ($send as $r) {
                $nid = (int) $r['notification_id'];
                $n   = $notifs[$nid];
                $res = WebPush::send($devices, [
                    'title' => (string) $n['title'],
                    'body'  => (string) $n['body'],
                    'url'   => $n['url'] ? url((string) $n['url']) : url(''),
                    'kind'  => (string) $n['kind'],
                    'tag'   => (string) $n['kind'] . ':' . $nid,
                ]);
                $anyOk = false; $lastErr = null;
                foreach ($devices as $i => $dev) {
                    $x = $res[$i] ?? ['ok' => false, 'gone' => false, 'status' => 0, 'error' => ''];
                    if ($x['ok']) { $anyOk = true; $stats[$nid]['ok']++; }
                    else { $stats[$nid]['fail']++; $lastErr = ((int) $x['status'] ?: '؟') . ' ' . (string) $x['error']; }
                    PushSubscription::recordResult((int) $dev['id'], (bool) $x['ok'], (bool) $x['gone']);
                }
                self::setState((int) $r['id'], $anyOk ? 'sent' : 'failed',
                               (int) $r['push_attempts'] + 1, $anyOk ? null : $lastErr);
                $anyOk ? $out['sent']++ : $out['failed']++;
            }

            if ($rest) {
                // ⚠️ البنود دي **موجودة في السجل كاملة** — اللي اتلغى هو الرنّة بس
                foreach ($rest as $r) {
                    self::setState((int) $r['id'], 'skipped', (int) $r['push_attempts'] + 1, self::BURST_MARK);
                    $out['burst']++;
                }
                $more = count($rest);
                WebPush::send($devices, [
                    'title' => '🔔 و' . $more . ' إشعار كمان',
                    'body'  => 'حصلت حاجات كتير مع بعض — افتح النظام تشوفها كلها.',
                    'url'   => url('notifications'),
                    'kind'  => 'burst',
                    'tag'   => 'burst',
                ]);
            }
        }

        foreach ($stats as $id => $s) {
            if ($s['ok'] || $s['fail']) { self::log($notifs[$id], $s['ok'], $s['fail']); }
        }
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

        /* ⚠️ **التباعد المتزايد** (المرحلة ٥): من غيره العامل بيعيد على نفس
           الجهاز الميت كل ٥ دقايق لحد ما يوصل الحد — ٣ محاولات في ١٥ دقيقة
           كلها بتفشل لنفس السبب. دلوقتي: ٥ دقايق ← ٣٠ ← ١٢٠.

           ⚠️ **المقارنة الزمنية جوّه SQL** (قاعدة v48) — ساعة القاعدة UTC
           وPHP القاهرة، فأي مقارنة في PHP بتفشل بفرق ٣ ساعات. */
        $backoff = self::hasCol('push_last_try')
            ? "(push_last_try IS NULL
                OR push_last_try < DATE_SUB(NOW(), INTERVAL "
                    . "CASE push_attempts WHEN 0 THEN 5 WHEN 1 THEN 30 ELSE 120 END MINUTE))"
            : '1';

        $ids = Database::all(
            "SELECT DISTINCT notification_id FROM notification_recipients
              WHERE ((push_state = 'failed' AND push_attempts < " . self::MAX_ATTEMPTS . " AND $backoff)
                 OR (push_state = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)))
              ORDER BY notification_id DESC
              LIMIT $limit");
        // ⚠️ دفعة واحدة عن قصد — عشان حارس الانفجار يشوف كل المتأخّر لنفس
        //    الشخص مع بعض. لو اتنادوا واحدًا واحدًا، تعطُّل الكرون ساعة كان
        //    هيرجع فجأة بعشرات الرنّات.
        $total = self::deliverMany(array_column($ids, 'notification_id'));
        $total['events'] = count($ids);
        return $total;
    }

    private static function setState(int $recipientId, string $state, int $attempts, ?string $err): void
    {
        // ⚠️ `push_last_try = NOW()` هو أساس التباعد المتزايد — من غيره العامل
        //    بيعيد على طول ومفيش فرق بين محاولة لسه حاصلة وواحدة من ساعة.
        $stamp = self::hasCol('push_last_try') ? ', push_last_try = NOW()' : '';
        Database::exec(
            "UPDATE notification_recipients
                SET push_state = ?, push_attempts = ?, push_last_error = ? $stamp
              WHERE id = ?",
            [$state, min(255, $attempts), $err !== null ? mb_substr($err, 0, 160) : null, $recipientId]);
    }

    /** عمود v53 موجود؟ (النظام لازم يشتغل عادي قبل الترحيل) */
    private static function hasCol(string $col): bool
    {
        static $cache = [];
        if (isset($cache[$col])) { return $cache[$col]; }
        return $cache[$col] = (bool) Database::scalar(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'notification_recipients'
                AND column_name = ?", [$col]);
    }

    // ═══════════════════════════════════════════════ ④ صحة الإشعارات (المرحلة ٥)

    /**
     * لوحة صحة نظام الإشعارات — **قراءة فقط**، لشاشة `/push`.
     *
     * ⚠️ **«اتقرا فعلاً» أهم رقم هنا.** `push_messages` القديم كان بيقول «وصل ٣
     *    فشل ٠» وبس — يعني الرسالة وصلت الجهاز، مش إن حد شافها. `read_at` على
     *    صف المستلم هو الوحيد اللي بيقول إن بني آدم فتحها فعلاً.
     *
     * ⚠️ **كل المقارنات الزمنية جوّه SQL** (قاعدة v48).
     *
     * @return array<string,mixed>
     */
    public static function health(int $days = 7): array
    {
        $out = [
            'ready' => false, 'days' => max(1, min(90, $days)),
            'total' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'pending' => 0,
            'read' => 0, 'delivery_rate' => 0.0, 'read_rate' => 0.0,
            'devices' => 0, 'dead' => 0, 'retrying' => 0, 'gave_up' => 0,
            'burst' => 0, 'muted' => 0, 'errors' => [], 'last_run' => null,
        ];
        if (!Notify::available()) { return $out; }
        $out['ready'] = true;
        $d = $out['days'];

        $row = Database::row(
            "SELECT COUNT(*) total,
                    SUM(push_state = 'sent')    sent,
                    SUM(push_state = 'failed')  failed,
                    SUM(push_state = 'skipped') skipped,
                    SUM(push_state = 'pending') pending,
                    SUM(read_at IS NOT NULL)    rd
               FROM notification_recipients
              WHERE created_at >= DATE_SUB(NOW(), INTERVAL $d DAY)") ?: [];
        foreach (['total', 'sent', 'failed', 'skipped', 'pending'] as $k) {
            $out[$k] = (int) ($row[$k] ?? 0);
        }
        $out['read'] = (int) ($row['rd'] ?? 0);

        // نسبة الوصول محسوبة على اللي **حاولنا نبعته** بس (المتخطّى مش فشل)
        $tried = $out['sent'] + $out['failed'];
        $out['delivery_rate'] = $tried > 0 ? round($out['sent'] / $tried * 100, 1) : 0.0;
        $out['read_rate']     = $out['total'] > 0 ? round($out['read'] / $out['total'] * 100, 1) : 0.0;

        $out['retrying'] = (int) Database::scalar(
            "SELECT COUNT(*) FROM notification_recipients
              WHERE push_state = 'failed' AND push_attempts < " . self::MAX_ATTEMPTS);
        $out['gave_up'] = (int) Database::scalar(
            "SELECT COUNT(*) FROM notification_recipients
              WHERE push_state = 'failed' AND push_attempts >= " . self::MAX_ATTEMPTS);
        $out['burst'] = (int) Database::scalar(
            "SELECT COUNT(*) FROM notification_recipients
              WHERE push_state = 'skipped' AND push_last_error = ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL $d DAY)", [self::BURST_MARK]);
        if (self::hasCol('in_app')) {
            $out['muted'] = (int) Database::scalar(
                "SELECT COUNT(*) FROM notification_recipients
                  WHERE in_app = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL $d DAY)");
        }

        // آخر الأخطاء بأسبابها — «فشل ٣» بلا سبب ما بيفيدش
        $out['errors'] = Database::all(
            "SELECT push_last_error err, COUNT(*) c
               FROM notification_recipients
              WHERE push_state = 'failed' AND push_last_error IS NOT NULL
                AND created_at >= DATE_SUB(NOW(), INTERVAL $d DAY)
              GROUP BY push_last_error ORDER BY c DESC LIMIT 8");

        if (PushSubscription::available()) {
            // ⚠️ العمود اسمه `is_active` مش `status` — الأسماء اتفحصت من المخطَّط
            $out['devices'] = (int) Database::scalar("SELECT COUNT(*) FROM push_subscriptions WHERE is_active = 1");
            $out['dead']    = (int) Database::scalar("SELECT COUNT(*) FROM push_subscriptions WHERE is_active = 0");
        }

        $out['last_run'] = App::setting('push_worker_last_run', '') ?: null;
        return $out;
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
