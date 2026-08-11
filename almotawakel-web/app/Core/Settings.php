<?php
namespace App\Core;

use App\Models\Audit;
use InvalidArgumentException;
use RuntimeException;

/**
 * **البوابة الوحيدة لكتابة أي إعداد** (docs/19 · المرحلة ٠).
 *
 * ═══ اللزمة ═══
 * `App::setSetting()` كانت بتكتب في القاعدة **بلا أي حارس**:
 *   • بلا تحقّق من النوع أو النطاق (`vat_rate = 14` بدل `0.14` بتعدّي)
 *   • بلا تحقّق من الدور (أي كود يقدر يكتب أي مفتاح)
 *   • **بلا أي أثر** — مفيش صف في `Audit` ولا سجل يقول مين غيّر إيه وإمتى
 *   • بلا إمكانية رجوع
 * والوحيدة اللي كانت بتسجّل بنفسها هي `RepMaintenance`.
 *
 * ═══ ⚠️ ليه جدول `settings_history` غير `audit_logs`؟ ═══
 * `audit_logs` سجل عام بيستقبل **كل** حاجة في النظام، والقيم فيه JSON. السؤال
 * «القيمة السابقة للمفتاح ده كانت إيه؟» عليه كان هيبقى مسح للجدول كله وفكّ JSON.
 * `settings_history` **سلسلة لكل مفتاح** — بتخلّي «رجوع بضغطة» و«آخر ١٠ تغييرات»
 * استعلامًا واحدًا مفهرسًا. **والاتنين بيتكتبوا مع بعض** (`Audit` للأثر العام).
 *
 * ═══ ⚠️ مسارات الكتابة التلاتة — مقصود إنها مختلفة ═══
 *   `set()`      ← الشاشة: تحقّق صارم + دور + سجل + Audit + تصفير الكاش
 *   `setState()` ← العمّال الخلفية (نسخة احتياطية · عامل الإشعارات): بلا سجل
 *                  ولا Audit، لأن نبضة كل ٥ دقايق كانت هتدفن التغييرات الحقيقية
 *   `record()`   ← بيتنادى من `App::setSetting` عشان الهجرات والسكربتات القديمة
 *                  تفضل شغّالة **بلا كسر** وفي نفس الوقت تسيب أثرًا
 *
 * ⚠️ **المرحلة ٠ ما بتغيّرش أي سلوك قايم**: كل مستدعي `App::setSetting` الحالي
 *    (٢٠ نقطة) بيشتغل بالظبط زي ما كان — الفرق الوحيد إنه بقى بيسيب أثرًا.
 */
class Settings
{
    /** ترتيب الأدوار من الأقوى — لتقييم «أقل دور مسموح». */
    private const ROLE_RANK = [
        'owner' => 5, 'manager' => 4, 'accountant' => 3, 'operator' => 2, 'read_only' => 1,
    ];

    /**
     * **المفاتيح اللي قيمتها بتتغيّر «من تاريخ» لا بأثر رجعي** (v74).
     *
     * ⚠️ المفتاح هنا معناه إن حساباته بتضرب في **صفوف تاريخية**، فتغيير القيمة
     *    من غير تأريخ بيعيد حساب الماضي بالسكوت. `verify_settings_registry`
     *    بيفرض إن كل مفتاح `TIER_DANGER` يبقى إما هنا أو تاريخًا بنفسه.
     */
    /* ⚠️ المفتاح اللي هنا **بيتجمّد ماضيه**: أي حساب بيمرّ على `valueOn`/`datedSql`
       بياخد القيمة اللي كانت سارية يوم البند لا السارية النهارده. `rep_expense_per_bill`
       انضم في v87 لأن المالك قال صراحةً «١٥٠ من الممكن أن يتغير» — ومن غير
       التأريخ أول تغيير كان هيعيد حساب كل فترة رواتب قديمة بالسكوت. */
    public const DATED_KEYS = ['custody_bill_deduction', 'rep_expense_per_bill'];

    /** بداية الزمن للسلسلة — «القيمة دي كانت سارية من الأزل». */
    public const EPOCH_DATE = '1970-01-01';

    private static ?bool $historyReady  = null;
    private static ?bool $scheduleReady = null;
    private static array $chainCache    = [];    // key => [[from, value], …] الأحدث أولاً
    private static bool  $recording     = false; // حارس ضد التكرار الذاتي

    // ─────────────────────────────────────────────────────────── القراءة

    /**
     * قراءة إعداد. الافتراضي بيجي من السجل المعرَّف لو مش متمرَّر.
     *
     * ⚠️ بتنادي `App::setting` مش بتقرا من القاعدة بنفسها — الكاش واحد.
     */
    public static function get(string $key, $default = null)
    {
        $fallback = $default ?? (SettingsSchema::has($key) ? SettingsSchema::defaultOf($key) : null);
        return App::setting($key, $fallback);
    }

    // ─────────────────────────────────────────────────────────── الكتابة

    /**
     * **كتابة إعداد من الشاشة** — الطريق الوحيد المسموح للمستخدم.
     *
     * بترجّع `true` لو القيمة اتغيّرت فعلاً، و`false` لو هي هي (فمفيش صف
     * سجل ولا صف Audit بيتكتبوا على تغيير وهمي).
     *
     * ⚠️ **الحارس هنا في الموديل مش في الشاشة** (قاعدة ③): إخفاء الخانة مش
     *    حماية، والقيمة الجاية من الفورم ممكن تتزوّر.
     *
     * @throws InvalidArgumentException مفتاح مجهول · قيمة خارج النطاق · دور أقل
     */
    public static function set(string $key, $value, string $reason = ''): bool
    {
        $def = SettingsSchema::get($key);
        if ($def === null) {
            throw new InvalidArgumentException("الإعداد «{$key}» مش معرَّف في سجل الإعدادات.");
        }

        // ① حالة بيكتبها عامل — ما تتكتبش من الشاشة أبدًا
        if ($def['tier'] === SettingsSchema::TIER_STATE) {
            throw new InvalidArgumentException(
                "«{$def['label']}» حالة بيكتبها النظام لوحده" .
                ($def['writer'] !== '' ? " ({$def['writer']})" : '') . ' — للعرض بس.'
            );
        }

        // ② مفتاح ليه موديل بيكتبه ويسجّله بنفسه — التوجيه أوضح من السماح
        if ($def['tier'] === SettingsSchema::TIER_MANAGED) {
            throw new InvalidArgumentException(
                "«{$def['label']}» بيتعدّل من " . ($def['writer'] !== '' ? $def['writer'] : 'شاشته الخاصة') . '.'
            );
        }

        // ③ الدور — الرفض هو الافتراضي. تحت CLI مفيش جلسة فالحارس ده مش مطبَّق
        //    (السكربت أصلاً عنده وصول كامل للقاعدة، فالتظاهر بحمايته كذب).
        if (Auth::check() && !self::roleAllows(Auth::role(), $def['role'])) {
            throw new InvalidArgumentException(
                "«{$def['label']}» مسموح تعديله لـ" . (Access::ROLES[$def['role']] ?? $def['role']) . ' بس.'
            );
        }

        $new = SettingsSchema::normalize($key, $value);
        $old = (string) (App::setting($key, SettingsSchema::defaultOf($key)) ?? '');
        if ($old === $new) { return false; }

        App::setSetting($key, $new);          // بتنادي record() جوّاها
        self::refresh($def);

        Audit::log(
            'update', 'settings', $key,
            ['setting_key' => $key, 'value' => self::maskIfSecret($def, $old)],
            ['setting_key' => $key, 'value' => self::maskIfSecret($def, $new)],
            'تعديل إعداد: ' . $def['label'] . ($reason !== '' ? ' — ' . $reason : ''),
            ['tier' => $def['tier'], 'section' => $def['section']]
        );
        return true;
    }

    /** رجوع للافتراضي المعرَّف. */
    public static function reset(string $key, string $reason = ''): bool
    {
        return self::set($key, SettingsSchema::defaultOf($key), $reason !== '' ? $reason : 'رجوع للافتراضي');
    }

    /**
     * كتابة حالة من عامل خلفي — **بلا سجل ولا Audit عن قصد**.
     *
     * ⚠️ نبضة كل ٥ دقايق × ٥ مفاتيح = ١٤٤٠ صف في اليوم كانت هتدفن التغييرات
     *    الحقيقية اللي السجل موجود عشانها.
     */
    public static function setState(string $key, string $value): void
    {
        $def = SettingsSchema::get($key);
        if ($def !== null && $def['tier'] !== SettingsSchema::TIER_STATE) {
            throw new InvalidArgumentException("«{$def['label']}» مش مفتاح حالة — استخدم set().");
        }
        self::$recording = true;              // يمنع record() من تسجيل النبضة
        try { App::setSetting($key, $value); } finally { self::$recording = false; }
    }

    /**
     * **تسجيل تغيير — بيتنادى من `App::setSetting` وبس.**
     *
     * ⚠️ **متسامح عن قصد**: بيقبل أي مفتاح حتى لو مش في السجل، وما بيرميش
     *    استثناء أبدًا. السبب: الدالة دي بتشتغل جوّه **الهجرات والمثبّت** —
     *    ولو رمت، ترحيل بيفشل نص الطريق و`ALTER TABLE` مالوش رجوع.
     *    التحقّق الصارم مكانه `set()` (بوابة الشاشة).
     */
    public static function record(string $key, string $old, string $new, string $reason = ''): void
    {
        if (self::$recording || $old === $new) { return; }
        $def = SettingsSchema::get($key);
        if ($def !== null && $def['tier'] === SettingsSchema::TIER_STATE) { return; }
        if (!self::historyReady()) { return; }

        self::$recording = true;
        try {
            Database::exec(
                'INSERT INTO settings_history
                   (setting_key, old_value, new_value, changed_by, changed_by_id, reason, source)
                 VALUES (?,?,?,?,?,?,?)',
                [
                    mb_substr($key, 0, 64),
                    $def !== null ? self::maskIfSecret($def, $old) : $old,
                    $def !== null ? self::maskIfSecret($def, $new) : $new,
                    self::actor(),
                    (int) ($_SESSION['uid'] ?? 0) ?: null,
                    $reason !== '' ? mb_substr($reason, 0, 255) : null,
                    PHP_SAPI === 'cli' ? 'cli' : (Auth::check() ? 'ui' : 'system'),
                ]
            );
        } catch (\Throwable $e) {
            // السجل مساعِد — عمره ما يوقف كتابة إعداد ولا يكسر ترحيل.
        } finally {
            self::$recording = false;
        }
    }

    // ─────────────────────────────────────────────────────────── السجل

    /** تاريخ مفتاح واحد — الأحدث الأول. */
    public static function history(string $key, int $limit = 20): array
    {
        if (!self::historyReady()) { return []; }
        $limit = max(1, min(200, $limit));
        return Database::all(
            "SELECT * FROM settings_history WHERE setting_key = ? ORDER BY id DESC LIMIT $limit",
            [$key]
        );
    }

    /** آخر التغييرات على مستوى النظام كله — لرأس مركز التحكّم. */
    public static function recent(int $limit = 10): array
    {
        if (!self::historyReady()) { return []; }
        $limit = max(1, min(200, $limit));
        $rows = Database::all("SELECT * FROM settings_history ORDER BY id DESC LIMIT $limit");
        foreach ($rows as &$r) {
            $def = SettingsSchema::get((string) $r['setting_key']);
            $r['label']   = $def['label']   ?? (string) $r['setting_key'];
            $r['section'] = $def['section'] ?? '';
        }
        return $rows;
    }

    /**
     * رجوع بضغطة: بيرجّع المفتاح لقيمته **قبل** الصف ده.
     *
     * ⚠️ بيمرّ على `set()` — يعني التحقّق والدور والسجل بيتطبّقوا على الرجوع
     *    زي أي تعديل. الرجوع تغيير مش استثناء.
     */
    public static function revert(int $historyId): bool
    {
        if (!self::historyReady()) { return false; }
        $row = Database::row('SELECT * FROM settings_history WHERE id = ?', [$historyId]);
        if (!$row) { throw new RuntimeException('صف السجل ده مش موجود.'); }

        $key = (string) $row['setting_key'];
        $def = SettingsSchema::get($key);
        if ($def === null) { throw new RuntimeException("الإعداد «{$key}» مش معرَّف — مينفعش يرجع."); }
        if ($def['type'] === SettingsSchema::T_SECRET) {
            // القيمة في السجل **مقنّعة** فالرجوع بيها هيكتب نجوم مكان المفتاح.
            throw new RuntimeException("«{$def['label']}» مفتاح سرّي — القيمة القديمة مش متخزّنة، اكتبه من تاني.");
        }
        return self::set($key, (string) $row['old_value'], 'رجوع لتغيير سابق #' . $historyId);
    }

    // ─────────────────────────────────────────────────────────── مساعِدات

    /** هل جدول السجل موجود؟ (قبل ترحيل v70 بيكون لأ — والنظام بيفضل شغّال.) */
    public static function historyReady(): bool
    {
        if (self::$historyReady !== null) { return self::$historyReady; }
        try {
            self::$historyReady = (bool) Database::scalar(
                'SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = ?', ['settings_history']
            );
        } catch (\Throwable $e) {
            self::$historyReady = false;
        }
        return self::$historyReady;
    }

    /** ⚠️ إلزامية في آخر أي ترحيل بينشئ الجدول (مصيدة الكاش الساكن v57). */
    public static function refreshHistoryReady(): bool
    {
        self::$historyReady = null;
        return self::historyReady();
    }

    // ═══════════════════════════════════ السلسلة المؤرَّخة (v74)

    /** هل جدول السلسلة موجود؟ (قبل v74 بيكون لأ — والنظام بيفضل شغّال بالأساس.) */
    public static function scheduleReady(): bool
    {
        if (self::$scheduleReady !== null) { return self::$scheduleReady; }
        try {
            self::$scheduleReady = (bool) Database::scalar(
                'SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = ?', ['setting_schedules']
            );
        } catch (\Throwable $e) {
            self::$scheduleReady = false;
        }
        return self::$scheduleReady;
    }

    /** ⚠️ إلزامية في آخر أي ترحيل بينشئ الجدول (مصيدة الكاش الساكن v57). */
    public static function refreshScheduleReady(): bool
    {
        self::$scheduleReady = null;
        self::$chainCache = [];
        return self::scheduleReady();
    }

    /**
     * سلسلة قيم المفتاح — **الأحدث أولاً**.
     *
     * ⚠️ بترجّع مصفوفة فاضية لو الجدول مش موجود أو المفتاح مالوش صفوف، والقارئ
     *    بيرجع للأساس في `settings` — يعني **السلوك القديم بالحرف** (مهم عشان
     *    الكود يشتغل قبل ترحيل v74 وبعده بنفس النتيجة).
     *
     * @return array<int,array{from:string,value:string}>
     */
    public static function chain(string $key): array
    {
        if (isset(self::$chainCache[$key])) { return self::$chainCache[$key]; }
        if (!self::scheduleReady()) { return self::$chainCache[$key] = []; }
        $rows = Database::all(
            'SELECT effective_from, value FROM setting_schedules
              WHERE setting_key = ? ORDER BY effective_from DESC', [$key]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['from' => (string) $r['effective_from'], 'value' => (string) $r['value']];
        }
        return self::$chainCache[$key] = $out;
    }

    /**
     * **القيمة السارية على تاريخ معيّن** — المصدر الوحيد لأي حساب مؤرَّخ.
     *
     * `$onDate = null` معناها «السارية النهارده» — وده اللي بيخلّي أي مستدعي
     * قديم (ما بيمرّرش تاريخ) يفضل شغّال بنفس النتيجة.
     *
     * ⚠️ **التاريخ الحقيقي مش `work_date()`**: «يوم العمل» اختيار المستخدم
     *    للإدخال، لكن «القاعدة السارية دلوقتي» بتتحدّد بالتقويم.
     */
    public static function valueOn(string $key, ?string $onDate = null): string
    {
        $date  = ($onDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $onDate)) ? $onDate : date('Y-m-d');
        foreach (self::chain($key) as $point) {
            if ($point['from'] <= $date) { return $point['value']; }
        }
        // مفيش صف سارٍ (أو السلسلة فاضية) → الأساس المخزَّن في `settings`
        return (string) self::get($key, SettingsSchema::defaultOf($key));
    }

    /**
     * **تعبير SQL بيترجم عمود تاريخ لقيمة الإعداد السارية** فيه.
     *
     * ⚠️⚠️ **الصف الواحد بيطلّع `?` عادي مش `CASE`** — ودي مش تحسين أداء:
     *    معناها إن الاستعلامات تفضل **حرفيًّا زي ما كانت** لحد ما المالك يعمل
     *    أول تغيير مؤرَّخ فعلاً. يعني v74 بيدخل النظام بصفر فرق في أي خطة تنفيذ.
     *
     * @return array{0:string,1:array} [التعبير, البارامترات بالترتيب]
     */
    public static function datedSql(string $key, string $dateCol): array
    {
        $chain = self::chain($key);
        if (count($chain) <= 1) {
            return ['?', [ $chain ? $chain[0]['value'] : self::valueOn($key) ]];
        }
        // الأحدث أولاً: أول شرط بيتحقّق هو الصح. الأقدم بيبقى ELSE.
        $sql = 'CASE';
        $params = [];
        $last = array_pop($chain);              // الأقدم — بيروح ELSE
        foreach ($chain as $point) {
            $sql .= " WHEN $dateCol >= ? THEN ?";
            $params[] = $point['from'];
            $params[] = $point['value'];
        }
        $sql .= ' ELSE ? END';
        $params[] = $last['value'];
        return [$sql, $params];
    }

    /**
     * إضافة/تعديل نقطة في السلسلة — **الطريق الوحيد لتغيير إعداد مؤرَّخ**.
     *
     * ⚠️ **الحارس هنا مش في الشاشة** (قاعدة ③): الدور والنوع والنطاق بيتفحصوا
     *    بنفس `SettingsSchema::normalize()` بتاعة أي إعداد.
     * ⚠️ **بيحدّث `settings` كمان** بالقيمة السارية النهارده، عشان أي مستدعي
     *    قديم ما بيمرّرش تاريخ يفضل شايف الرقم الصح.
     *
     * @throws InvalidArgumentException
     */
    public static function schedule(string $key, string $from, $value, string $note = ''): void
    {
        $def = SettingsSchema::get($key);
        if ($def === null) {
            throw new InvalidArgumentException("الإعداد «{$key}» مش معرَّف في سجل الإعدادات.");
        }
        if (!in_array($key, self::DATED_KEYS, true)) {
            throw new InvalidArgumentException("«{$def['label']}» مش إعداد مؤرَّخ — استخدم الحفظ العادي.");
        }
        if (!self::scheduleReady()) {
            throw new RuntimeException('جدول السلسلة المؤرَّخة مش موجود — شغّل bin/migrate_v74.php.');
        }
        if (Auth::check() && !self::roleAllows(Auth::role(), $def['role'])) {
            throw new InvalidArgumentException(
                "«{$def['label']}» مسموح تعديله لـ" . (Access::ROLES[$def['role']] ?? $def['role']) . ' بس.'
            );
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            throw new InvalidArgumentException('تاريخ السريان لازم يكون بصيغة سنة-شهر-يوم.');
        }
        /* ⚠️ **ممنوع تغيير صف الأزل** — هو اللي بيمثّل «القيمة اللي كانت شغّالة
           على كل الماضي». تعديله = إعادة حساب التاريخ كله، وهو بالظبط اللي
           الآلية دي اتعملت تمنعه. */
        if ($from === self::EPOCH_DATE) {
            throw new InvalidArgumentException('صف الأساس التاريخي ما يتغيّرش — ضيف تاريخ سريان جديد.');
        }

        $norm = SettingsSchema::normalize($key, $value);
        $old  = self::valueOn($key, $from);

        Database::exec(
            'INSERT INTO setting_schedules (setting_key, effective_from, value, note, created_by)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), note = VALUES(note), created_by = VALUES(created_by)',
            [$key, $from, $norm, $note !== '' ? mb_substr($note, 0, 255) : null, self::actor()]
        );
        self::$chainCache = [];

        // الأساس بيتحدّث بالسارية النهارده — للمستدعي اللي ما بيمرّرش تاريخ
        $today = self::valueOn($key);
        if ((string) self::get($key) !== $today) {
            App::setSetting($key, $today);
            self::refresh($def);
        }

        Audit::log(
            'update', 'setting_schedules', $key,
            ['setting_key' => $key, 'effective_from' => $from, 'value' => $old],
            ['setting_key' => $key, 'effective_from' => $from, 'value' => $norm],
            'تغيير مؤرَّخ: ' . $def['label'] . ' من ' . $from . ($note !== '' ? ' — ' . $note : ''),
            ['tier' => $def['tier'], 'dated' => true]
        );
    }

    /** حذف نقطة من السلسلة — صف الأزل محميّ. */
    public static function unschedule(int $id): void
    {
        if (!self::scheduleReady()) { return; }
        $row = Database::row('SELECT * FROM setting_schedules WHERE id = ?', [$id]);
        if (!$row) { throw new RuntimeException('نقطة التغيير دي مش موجودة.'); }
        if ((string) $row['effective_from'] === self::EPOCH_DATE) {
            throw new RuntimeException('صف الأساس التاريخي ما يتشالش — من غيره الماضي بيفضل بلا قيمة.');
        }
        $key = (string) $row['setting_key'];
        $def = SettingsSchema::get($key);
        if ($def !== null && Auth::check() && !self::roleAllows(Auth::role(), $def['role'])) {
            throw new RuntimeException('مالكش صلاحية تشيل نقطة التغيير دي.');
        }
        Database::exec('DELETE FROM setting_schedules WHERE id = ?', [$id]);
        self::$chainCache = [];

        $today = self::valueOn($key);
        if ((string) self::get($key) !== $today) {
            App::setSetting($key, $today);
            if ($def !== null) { self::refresh($def); }
        }
        Audit::log(
            'delete', 'setting_schedules', $key,
            ['effective_from' => (string) $row['effective_from'], 'value' => (string) $row['value']],
            null, 'شيل نقطة تغيير مؤرَّخة: ' . ($def['label'] ?? $key)
        );
    }

    /** هل الدور ده كفاية للحد الأدنى المطلوب؟ */
    public static function roleAllows(string $role, string $required): bool
    {
        return (self::ROLE_RANK[$role] ?? 0) >= (self::ROLE_RANK[$required] ?? 99);
    }

    /** تصفير الكاش الساكن بتاع الموديل اللي بيقرا المفتاح. */
    private static function refresh(array $def): void
    {
        $cb = $def['refresh'] ?? null;
        if ($cb === null) { return; }
        if (!is_callable($cb)) {
            throw new RuntimeException('دالة تصفير الكاش المعرَّفة للإعداد مش موجودة.');
        }
        $cb();
    }

    /** المفتاح السرّي عمره ما يتكتب صريحًا في سجل ولا في Audit. */
    private static function maskIfSecret(array $def, string $value): string
    {
        if ($def['type'] !== SettingsSchema::T_SECRET || $value === '') { return $value; }
        return '••••••••' . mb_substr($value, -4);
    }

    /** اسم المنفّذ — نفس منطق `Audit::actorName` (مستخدم المكتب وإلا المندوب). */
    private static function actor(): ?string
    {
        $user = Auth::user();
        if ($user !== null && trim($user) !== '') { return mb_substr(trim($user), 0, 80); }
        $rep = trim((string) ($_SESSION['rep_name'] ?? ''));
        if ($rep !== '') { return mb_substr('مندوب: ' . $rep, 0, 80); }
        return PHP_SAPI === 'cli' ? 'سطر الأوامر' : null;
    }
}
