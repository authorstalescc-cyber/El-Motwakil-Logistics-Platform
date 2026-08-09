<?php
namespace App\Models;

use App\Core\Access;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\Database;
use App\Core\RepAuth;

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
        'submission.deleted'    => ['rep',    null],
        'custom'                => ['both',   null],
        'test'                  => ['both',   null],

        /* ── أنواع المرحلة ٣ (docs/15 §٣.٤) ────────────────────────────────
           كلها للمكتب، وكلها بتمرّ على معالِج صفحتها فالممنوع ما بياخدهاش. */
        'invoice.new'           => ['office', 'InvoiceController@index'],
        'client.payment'        => ['office', 'PaymentController@index'],
        'rep.settlement'        => ['office', 'RepCustodyController@index'],
        'rep.deposit'           => ['office', 'RepCustodyController@index'],
        'bill.linked'           => ['office', 'PermitController@index'],
        'permit.closed'         => ['office', 'PermitController@index'],
        'driver.handover'       => ['office', 'RepCustodyController@index'],
        /* ⚠️ `both` بقرار المالك (2026-08-02): المندوب في الميدان محتاج يعرف
           إن الشحنة تحرّكت زي المكتب بالظبط. ولأن صفحات المكتب مقفولة عليه،
           الحدث **لازم** يحمل `meta.rep_url` وإلا `Notify::urlFor` هتشيل
           الرابط ويوصله خبر بلا وجهة. */
        'track.stage'           => ['both',   'PermitController@index'],   // رحلة الشحنة (v62)
        'rep.transfer'          => ['office', 'RepCustodyController@index'],

        /* ── قفل بوابة المندوب للصيانة (v68) ───────────────────────────────
           ⚠️ `rep` **بلا معالِج صلاحية**: المعالِج بيفلتر بصلاحية فتح صفحة
              مكتب، والحدث ده جمهوره مناديب وبس ووجهته `m`. حطّ معالِجًا هنا
              = الخبر يتفلتر عن اللي هو موجّه له أصلاً. */
        'rep.maintenance.on'    => ['rep',    null],
        'rep.maintenance.off'   => ['rep',    null],

        /* ── أنواع المرحلة ٧ (تلقائية — بيولّدها العامل مش المستخدم) ────────
           ⚠️ كلها بتعدّي على `Notify::emitOnce` فمستحيل تتكرّر كل ٥ دقايق. */
        'remind.submissions'    => ['office', 'RepCustodyController@inbox'],
        'remind.closing'        => ['office', 'DailyClosingController@index'],
        'alert.permit_idle'     => ['office', 'PermitController@index'],
        'alert.rep_custody'     => ['office', 'RepCustodyController@index'],
        'digest.daily'          => ['office', null],
    ];

    /**
     * وصف كل نوع للعرض — **المصدر الوحيد** لاسم النوع وأيقونته في الجرس
     * وصفحة السجل وفلترتها.
     *
     * ⚠️ اسم الأيقونة لازم يكون موجود في **الاتنين**: `icon()` في
     *    `helpers.php` (للصفحة) و**خريطة الأيقونات في `app.js`** (للجرس).
     *    `bin/verify_notifications_ui.php` بيفشل لو اسم ناقص من أي واحدة فيهم
     *    — الأيقونة الناقصة بتقع على شكل افتراضي **بالسكوت**.
     */
    public const KINDS = [
        'permit.new'          => ['label' => 'إذن أو شهادة جديدة', 'icon' => 'folder'],
        'permit.closed'       => ['label' => 'إذن اتقفل',           'icon' => 'check-circle'],
        'day.closed'          => ['label' => 'تقفيل يوم',           'icon' => 'calculator'],
        'submission.new'      => ['label' => 'بند مندوب جديد',      'icon' => 'inbox'],
        'submission.approved' => ['label' => 'بند اتعمد',           'icon' => 'check-circle'],
        'submission.rejected' => ['label' => 'بند اترفض',           'icon' => 'x'],
        'submission.deleted'  => ['label' => 'بند اتشال',           'icon' => 'trash'],
        'bill.new'            => ['label' => 'بوليصة جديدة',        'icon' => 'truck'],
        'bill.linked'         => ['label' => 'بوليصة على إذن',      'icon' => 'truck'],
        'invoice.new'         => ['label' => 'مستند اتصدر',         'icon' => 'receipt'],
        'client.payment'      => ['label' => 'تحصيل من عميل',       'icon' => 'banknote'],
        'rep.deposit'         => ['label' => 'عهدة نزلت لمندوب',    'icon' => 'wallet'],
        'rep.settlement'      => ['label' => 'تسوية مندوب',         'icon' => 'check-circle'],
        'rep.transfer'        => ['label' => 'تحويل بين مناديب',    'icon' => 'shuffle'],
        'driver.handover'     => ['label' => 'تسليم عهدة لسائق',    'icon' => 'coins'],
        'track.stage'         => ['label' => 'حالة رحلة اتحدّثت',   'icon' => 'map-pin'],
        'rep.maintenance.on'  => ['label' => 'بوابة المناديب اتقفلت', 'icon' => 'wrench'],
        'rep.maintenance.off' => ['label' => 'بوابة المناديب رجعت',  'icon' => 'check-circle'],
        'custom'              => ['label' => 'إشعار مكتوب',         'icon' => 'send'],
        'test'                => ['label' => 'تجربة',               'icon' => 'bell'],
        // المرحلة ٧ — تلقائية
        'remind.submissions'  => ['label' => 'تذكير: بنود مستنية',  'icon' => 'clock'],
        'remind.closing'      => ['label' => 'تذكير: يوم مش متقفل', 'icon' => 'clock'],
        'alert.permit_idle'   => ['label' => 'إذن ساكن',            'icon' => 'hourglass'],
        'alert.rep_custody'   => ['label' => 'عهدة مندوب قربت تخلص','icon' => 'wallet'],
        'digest.daily'        => ['label' => 'ملخّص اليوم',          'icon' => 'bar-chart'],
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

            /* ⚠️ استثناء اللي عمل الإجراء بنفسه — بيتمرّر من النداء لا مفروض
               على كل الأنواع. لازم للأحداث اللي جمهورها واسع (`both`): اللي
               لسه دايس الزرار وشايف نتيجته قدّامه، رنّة عليه ضوضاء صافية. */
            if (!empty($d['exclude']['type']) && isset($d['exclude']['key'])) {
                $xt = (string) $d['exclude']['type'];
                $xk = (string) $d['exclude']['key'];
                $parties = array_values(array_filter(
                    $parties, fn(array $p) => !($p['type'] === $xt && $p['key'] === $xk)));
            }

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
            $hasPrefs = NotifyPref::available();
            foreach ($parties as $p) {
                /* ⚠️ **التفضيل بيتطبّق هنا — وقت التسجيل مش وقت العرض** (v53).
                   لو اتطبّق وقت العرض، تغيير التفضيل كان هيخفي أحداثًا قديمة
                   من السجل بأثر رجعي، والسجل المفروض يفضل شاهدًا على اللي حصل.

                   ⚠️ والتفضيل بيجي **بعد** فلترة `Access` في `recipientsFor` —
                   مستحيل حد يفتح لنفسه إشعارًا عن صفحة ممنوعة بعلامة صح. */
                $inApp    = true;
                $wantPush = true;
                if ($hasPrefs) {
                    $inApp    = NotifyPref::allows($p['type'], $p['key'], $kind, 'in_app');
                    $wantPush = NotifyPref::allows($p['type'], $p['key'], $kind, 'push');
                }
                // قافل القناتين = مش عايز الحدث ده خالص، فما بنسجّلش له صفًّا
                if (!$inApp && !$wantPush) { continue; }

                Database::exec(
                    "INSERT IGNORE INTO notification_recipients
                        (notification_id, party_type, party_key, push_state" . ($hasPrefs ? ', in_app' : '') . ")
                     VALUES (?,?,?,?" . ($hasPrefs ? ',?' : '') . ")",
                    $hasPrefs
                        ? [$id, $p['type'], $p['key'], $wantPush ? $state : 'skipped', $inApp ? 1 : 0]
                        : [$id, $p['type'], $p['key'], $state]
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

    /* ═══════════════════════════════════════════════ القراءة (المرحلة ٢)
       الجرس وصفحة `/notifications` بيقروا من هنا. **صفر كتابة** غير «مقروء».
       ═══════════════════════════════════════════════════════════════════ */

    /**
     * مين «أنا» دلوقتي؟ — جلسة مكتب (`user` + اسم المستخدم) أو جلسة مندوب
     * (`rep` + رقمه)، ونفس المفاتيح اللي `recipientsFor()` بتكتب بيها بالظبط.
     *
     * ⚠️ جلسة المندوب **ما فيهاش `uid`** (v35) فـ`Auth::check()` بترجّع false
     *    لها — عشان كده بنسأل عن جلسة المكتب الأول ثم المندوب.
     *
     * @return array{0:string,1:string} ['user'|'rep', المفتاح] أو ['', ''] لو مفيش جلسة
     */
    public static function me(): array
    {
        if (Auth::check()) { return ['user', (string) (Auth::user() ?? '')]; }
        if (class_exists(RepAuth::class) && RepAuth::check()) {
            return ['rep', (string) RepAuth::repId()];
        }
        return ['', ''];
    }

    /**
     * شرط «البند ده يبان في جرس صاحبه دلوقتي؟» — **المصدر الوحيد**.
     *
     * ⚠️ لازم يتنادى من **كل** استعلام بيقرا صندوق وارد شخص (`inbox` ·
     *    `unreadCount` · `listFor`). لو اتنسي في واحد منهم، البند المقفول أو
     *    المؤجَّل يظهر في مكان ويختفي من التاني والعدّاد يخالف القايمة.
     *
     * ⚠️ **المقارنة الزمنية جوّه SQL** (قاعدة v48) — ساعة القاعدة UTC وPHP القاهرة.
     */
    private static function visibleSql(string $alias = 'r'): string
    {
        $sql = '';
        if (self::hasCol('in_app'))        { $sql .= " AND {$alias}.in_app = 1"; }
        if (self::hasCol('snoozed_until')) { $sql .= " AND ({$alias}.snoozed_until IS NULL OR {$alias}.snoozed_until <= NOW())"; }
        return $sql;
    }

    /** أعمدة v53 موجودة؟ (النظام لازم يشتغل عادي قبل الترحيل) */
    private static function hasCol(string $col): bool
    {
        static $cache = [];
        if (isset($cache[$col])) { return $cache[$col]; }
        return $cache[$col] = (bool) Database::scalar(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'notification_recipients'
                AND column_name = ?", [$col]);
    }

    /**
     * صندوق وارد شخص — الصفوف الخام.
     * @return array<int,array<string,mixed>>
     */
    public static function inbox(string $partyType, string $partyKey, int $limit = 30, int $offset = 0): array
    {
        if (!self::available() || $partyKey === '') { return []; }
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $at = Clock::displayExpr('n.created_at');
        $vis = self::visibleSql('r');
        return Database::all(
            "SELECT n.*, $at AS at_disp, r.read_at, r.id AS recipient_id
               FROM notification_recipients r
               JOIN notifications n ON n.id = r.notification_id
              WHERE r.party_type = ? AND r.party_key = ? $vis
              ORDER BY n.id DESC
              LIMIT $limit OFFSET $offset",
            [$partyType, $partyKey]);
    }

    /** صندوق الوارد **جاهزًا للعرض** (الجرس + الصفحة). */
    public static function feed(string $partyType, string $partyKey, int $limit = 20, int $offset = 0): array
    {
        return array_map(fn(array $r) => self::shape($r, $partyType),
                         self::inbox($partyType, $partyKey, $limit, $offset));
    }

    /** عدد غير المقروء لشخص. */
    public static function unreadCount(string $partyType, string $partyKey): int
    {
        if (!self::available() || $partyKey === '') { return 0; }
        $vis = self::visibleSql('notification_recipients');
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM notification_recipients
              WHERE party_type = ? AND party_key = ? AND read_at IS NULL $vis",
            [$partyType, $partyKey]);
    }

    /**
     * تأجيل بند — «فكّرني بعدين» (المرحلة ٦).
     * بيختفي من الجرس والصفحة ويرجع لوحده لما الوقت يعدّي.
     *
     * ⚠️ الحساب جوّه SQL (`DATE_ADD(NOW(), …)`) — قاعدة v48.
     */
    public static function snooze(string $partyType, string $partyKey, int $notificationId, int $minutes): bool
    {
        if (!self::available() || !self::hasCol('snoozed_until')) { return false; }
        $minutes = max(5, min(10080, $minutes));           // من ٥ دقايق لأسبوع
        return Database::exec(
            "UPDATE notification_recipients
                SET snoozed_until = DATE_ADD(NOW(), INTERVAL $minutes MINUTE)
              WHERE party_type = ? AND party_key = ? AND notification_id = ?",
            [$partyType, $partyKey, $notificationId]) > 0;
    }

    /** بنود مؤجَّلة لسه ما رجعتش — للعرض في الصفحة عشان ما تختفيش بالسكوت. */
    public static function snoozedCount(string $partyType, string $partyKey): int
    {
        if (!self::available() || !self::hasCol('snoozed_until') || $partyKey === '') { return 0; }
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM notification_recipients
              WHERE party_type = ? AND party_key = ? AND snoozed_until > NOW()",
            [$partyType, $partyKey]);
    }

    /**
     * سجل مفلتر لصفحة `/notifications`.
     *
     * `scope = 'all'` بتقرا **كل أحداث النظام** بلا نظر للمستلم — للمالك بس،
     * والكنترولر هو اللي بيفرض ده (قرار ٧.١).
     *
     * @param array{kind?:string,q?:string,from?:string,to?:string,unread?:bool,scope?:string} $f
     * @return array{items:array<int,array<string,mixed>>, total:int}
     */
    public static function listFor(string $partyType, string $partyKey, array $f = [], int $limit = 40, int $offset = 0): array
    {
        if (!self::available()) { return ['items' => [], 'total' => 0]; }
        $all = ($f['scope'] ?? 'mine') === 'all';
        if (!$all && $partyKey === '') { return ['items' => [], 'total' => 0]; }

        $where = [];
        $args  = [];
        if (!$all) {
            // ⚠️ نفس شرط الرؤية بتاع الجرس بالحرف — من غيره الصفحة تقول رقمًا
            //    والجرس يقول غيره (المقفول بالتفضيل والمؤجَّل).
            $where[] = 'r.party_type = ? AND r.party_key = ?' . self::visibleSql('r');
            $args[]  = $partyType;
            $args[]  = $partyKey;
        }
        if (!empty($f['kind']) && isset(self::KINDS[$f['kind']])) {
            $where[] = 'n.kind = ?';
            $args[]  = $f['kind'];
        }
        if (!empty($f['q'])) {
            $where[] = '(n.title LIKE ? OR n.body LIKE ? OR n.actor_name LIKE ?)';
            $like    = '%' . $f['q'] . '%';
            array_push($args, $like, $like, $like);
        }
        // ⚠️ الفلترة بالتاريخ على `created_at` **بساعة العرض** — بنزحزح الحدود
        //    بنفس فرق `Clock` بدل ما نلفّ العمود، فالفهرس يفضل مستخدَمًا.
        $shift = Clock::dbOffsetMinutes();
        if (!empty($f['from'])) {
            $where[] = 'n.created_at >= DATE_SUB(?, INTERVAL ' . $shift . ' MINUTE)';
            $args[]  = $f['from'] . ' 00:00:00';
        }
        if (!empty($f['to'])) {
            $where[] = 'n.created_at <= DATE_SUB(?, INTERVAL ' . $shift . ' MINUTE)';
            $args[]  = $f['to'] . ' 23:59:59';
        }
        if (!empty($f['unread']) && !$all) { $where[] = 'r.read_at IS NULL'; }

        $sql = $all
            ? 'FROM notifications n'
            : 'FROM notification_recipients r JOIN notifications n ON n.id = r.notification_id';
        $sql .= $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $total = (int) Database::scalar("SELECT COUNT(*) $sql", $args);
        $limit  = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $at   = Clock::displayExpr('n.created_at');
        $cols = $all ? "n.*, $at AS at_disp, NULL AS read_at" : "n.*, $at AS at_disp, r.read_at";
        $rows = Database::all("SELECT $cols $sql ORDER BY n.id DESC LIMIT $limit OFFSET $offset", $args);

        return ['items' => array_map(fn(array $r) => self::shape($r, $partyType), $rows), 'total' => $total];
    }

    /** الأنواع الموجودة فعلاً في السجل — لقائمة الفلترة (بلا أنواع فاضية). */
    public static function kindsInUse(): array
    {
        if (!self::available()) { return []; }
        $out = [];
        foreach (Database::all("SELECT DISTINCT kind FROM notifications") as $r) {
            $k = (string) $r['kind'];
            if (isset(self::KINDS[$k])) { $out[$k] = self::KINDS[$k]['label']; }
        }
        return $out;
    }

    /** وصف نوع للعرض (بيرجّع افتراضيًّا آمنًا للأنواع القديمة/المجهولة). */
    public static function kindMeta(string $kind): array
    {
        return self::KINDS[$kind] ?? ['label' => 'إشعار', 'icon' => 'bell'];
    }

    /** كل الأنواع المعروفة — قائمة بيضا لشاشة التفضيلات. */
    public static function kindList(): array
    {
        return array_keys(self::KINDS);
    }

    /**
     * تسجيل حدث **مرّة واحدة بس** في نافذة زمنية (المرحلة ٧).
     *
     * ⚠️ **ده اللي بيخلّي التذكيرات التلقائية ممكنة أصلاً.** العامل بيشتغل كل
     *    ٥ دقايق، فبلاها كان «فيه بند مستنّي من ٤ ساعات» هيترنّ **٢٨٨ مرّة في
     *    اليوم**. البصمة = النوع + الموضوع، والنافذة بتتقاس **جوّه SQL**
     *    (قاعدة v48 — ساعة القاعدة مختلفة عن ساعة PHP).
     *
     * @return int رقم الحدث، أو 0 لو اتخطّى (موجود بالفعل في النافذة)
     */
    public static function emitOnce(string $kind, int $windowHours, array $d): int
    {
        if (!self::available()) { return 0; }
        $w = max(1, min(8760, $windowHours));

        $sql  = "SELECT COUNT(*) FROM notifications
                  WHERE kind = ? AND created_at >= DATE_SUB(NOW(), INTERVAL $w HOUR)";
        $args = [$kind];
        // الموضوع جزء من البصمة: «إذن ٧ ساكن» غير «إذن ٩ ساكن»
        if (isset($d['subject_type'], $d['subject_id'])) {
            $sql .= ' AND subject_type = ? AND subject_id = ?';
            $args[] = (string) $d['subject_type'];
            $args[] = (int) $d['subject_id'];
        } elseif (!empty($d['work_date'])) {
            $sql .= ' AND work_date = ?';
            $args[] = (string) $d['work_date'];
        }

        try {
            if ((int) Database::scalar($sql, $args) > 0) { return 0; }
        } catch (\Throwable $e) {
            return 0;   // فشل الفحص = ما نبعتش، أهون من إننا نغرق المالك
        }
        return self::emit($kind, $d);
    }

    /**
     * تحويل الصف الخام لبند عرض.
     *
     * ⚠️ **الوقت:** `at_disp` جاي من `Clock::displayExpr` يعني **بساعة الحيطة
     *    عند المستخدم**، و`strtotime` بيفكّه بتوقيت PHP (القاهرة) — فالناتج
     *    `ts` هو اللحظة الحقيقية بالثواني. الواجهة بتحسب «من ٣ دقايق» منه
     *    لوحدها بلا أي طلب للخادم. **ممنوع تستخدم `ts` في أي مقارنة على
     *    الخادم** — المقارنات مكانها جوّه SQL (قاعدة v48).
     */
    /**
     * ═══════════════════════════════════════════════════════════════════════
     * الرابط اللي **الطرف ده** يقدر يفتحه فعلاً — المصدر الوحيد.
     * ═══════════════════════════════════════════════════════════════════════
     *
     * ⚠️ الحدث بيتخزّن برابط **واحد**، وصفحات المكتب مقفولة على المندوب (جلسته
     *    مالهاش `uid` فـ`requireAuth` بترفضه — v35). يعني أي حدث جمهوره `both`
     *    بيدّي المندوب **رابطًا ميتًا**: يدوس على الإشعار ويتقذف على شاشة دخول.
     *
     * القاعدة: المندوب بياخد `meta.rep_url` لو الحدث حاططه؛ ولو مفيش بديل
     * والرابط المخزَّن صفحة مكتب → **بلا رابط** (الإشعار يفضل خبرًا يُقرا) بدل
     * رابط بيرمي ٤٠٣. اللي المندوب بيفتحه فعلاً: بوابته `m/…` وصفحة الإشعارات
     * (`NotificationController` حارسه `requireAnySession` — v53).
     *
     * ⚠️ **لازم تتنادى من المكانين**: `shape()` (الجرس والصفحة) و
     *    `PushNotify::deliverMany` (رنّة الموبايل). لو اتنسيت في واحد، الرابط
     *    يشتغل في مكان ويموت في التاني.
     *
     * @return string مسار خام بلا `url()` — المنادي هو اللي بيلفّه
     */
    public static function urlFor(array $row, string $partyType): string
    {
        $stored = trim((string) ($row['url'] ?? ''));
        if ($partyType !== 'rep') { return $stored; }

        $meta = [];
        if (!empty($row['meta_json'])) {
            $meta = json_decode((string) $row['meta_json'], true) ?: [];
        }
        $repUrl = trim((string) ($meta['rep_url'] ?? ''));
        if ($repUrl !== '') { return $repUrl; }

        if ($stored === '') { return ''; }
        return (str_starts_with($stored, 'm/') || $stored === 'm'
                || str_starts_with($stored, 'notifications')) ? $stored : '';
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * الوجهة الاحتياطية اللي **الطرف ده يقدر يفتحها فعلاً** — المصدر الوحيد.
     * ═══════════════════════════════════════════════════════════════════════
     *
     * ⚠️⚠️ **باج متحقَّق على الإنتاج (2026-08-03) — لا ترجّع `url('')` هنا.**
     *    `urlFor()` بترجّع `''` لما المندوب ياخد حدثًا رابطه صفحة مكتب بلا
     *    `meta.rep_url` (كل أحداث `custom` — **١٩٠ مستلم**، وكل `permit.new`
     *    اللي اتسجّل **قبل** ما `rep_url` تتضاف يوم 2026-08-02). وقتها كانت
     *    رنّة الموبايل بتقع على `url('')` = **لوحة تحكم المكتب** → `requireAuth`
     *    بترفض جلسة المندوب (مالهاش `uid` — v35) → **المندوب يتقذف على شاشة
     *    دخول المكتب**، وهو بالظبط بلاغ المالك.
     *
     *    القاعدة: الرابط الفاضي = **صفحة إشعارات نفس الطرف**، فالخبر يفضل
     *    مقروءًا في سياقه بدل ما يودّيه على صفحة مقفولة عليه.
     *
     * ⚠️ نفس تقسيم `NotificationController::ping` (`page`) بالحرف — لو اتغيّر
     *    واحد فيهم لازم يتغيّر التاني وإلا الجرس بيوصّل لمكان والرنّة لمكان تاني.
     *
     * @return string مسار خام بلا `url()` — المنادي هو اللي بيلفّه
     */
    public static function fallbackUrlFor(string $partyType): string
    {
        return $partyType === 'rep' ? 'm/notifications' : 'notifications';
    }

    private static function shape(array $r, string $partyType = 'user'): array
    {
        $kind = (string) $r['kind'];
        $meta = self::kindMeta($kind);
        $at   = (string) ($r['at_disp'] ?? $r['created_at'] ?? '');
        $ts   = $at !== '' ? (strtotime($at) ?: time()) : time();
        $path = self::urlFor($r, $partyType);

        return [
            'id'         => (int) $r['id'],
            'kind'       => $kind,
            'kind_label' => $meta['label'],
            'icon'       => $meta['icon'],
            'severity'   => (string) ($r['severity'] ?? 'info'),
            'title'      => (string) ($r['title'] ?? ''),
            'body'       => (string) ($r['body'] ?? ''),
            'url'        => $path !== '' ? url($path) : '',
            'actor'      => (string) ($r['actor_name'] ?? ''),
            'work_date'  => $r['work_date'] ?? null,
            'ts'         => $ts,
            'rel'        => self::relLabel($ts),
            'at_label'   => date('H:i', $ts) . ' · ' . dfmt(date('Y-m-d', $ts)),
            'read'       => !empty($r['read_at']),
        ];
    }

    /**
     * «من ٣ دقايق» — النسخة الخادمية.
     *
     * الواجهة بتحدّثها كل دقيقة من `ts` بلا طلب للخادم، لكن **الرندر الأولي
     * لازم يكون صحيحًا** عشان المستخدم بلا جافاسكريبت (والطباعة) يشوف وقتًا
     * مفهومًا بدل خانة فاضية.
     */
    public static function relLabel(int $ts): string
    {
        $d = time() - $ts;
        if ($d < 0)     { return 'دلوقتي'; }
        if ($d < 60)    { return 'دلوقتي'; }
        if ($d < 3600)  { $n = intdiv($d, 60);    return 'من ' . intf($n) . ' دقيقة'; }
        if ($d < 86400) { $n = intdiv($d, 3600);  return 'من ' . intf($n) . ' ساعة'; }
        if ($d < 604800){ $n = intdiv($d, 86400); return 'من ' . intf($n) . ' يوم'; }
        return dfmt(date('Y-m-d', $ts));
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
