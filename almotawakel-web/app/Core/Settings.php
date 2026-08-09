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

    private static ?bool $historyReady = null;
    private static bool  $recording    = false;   // حارس ضد التكرار الذاتي

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
