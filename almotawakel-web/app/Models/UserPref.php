<?php
namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use InvalidArgumentException;

/**
 * **تفضيلات العرض بتتبع الحساب مش المتصفح** (docs/19 · المرحلة ٢ · v75).
 *
 * ═══ اللزمة ═══
 * كل طبقة العرض كانت في `localStorage`: الأعمدة المخفية في كل جدول · كثافة
 * الصفوف · تثبيت أول عمود · **عرض كل عمود بالبكسل** · مناظر الفلاتر. يعني في
 * المتصفح مش في الحساب — الموظف يظبط أعمدته على جهاز ويلاقيها راجعة على
 * التاني، ومسح الكاش بيضيّع كل حاجة، **والمالك ما يقدرش يفرض شكلًا للشركة**.
 *
 * ═══ ثلاث مستويات ═══
 *   افتراضي الكود  ←  إعداد الشركة (`user_id = 0`)  ←  تفضيل المستخدم
 *
 * ⚠️⚠️ **القفل بيتفرض هنا مش في الشاشة** (قاعدة ③): لو المالك قفل مفتاحًا،
 *    `set()` بترفض كتابة المستخدم و`resolve()` بتتجاهل أي صف قديم له.
 *    إخفاء الزرار في الواجهة راحة عرض — مش حماية.
 *
 * ═══ ⚠️ العزل بالبناء (قاعدة ①) ═══
 * تفضيل عرض **عمره ما يدخل حسابًا**. الجدول ده ولا كود خزنة ولا تقفيل ولا عهدة
 * بيعرف إنه موجود، و`verify_user_prefs` بيفشل لو أي ملف مالي ذكره.
 *
 * ═══ مساحة الأسماء ═══
 * المفتاح بيتكوّن بنقطتين: `table.<path>.<sig>.<n>` · `views.<path>` · `ui.theme`.
 * الشكل الجوّاني (JSON) بيحدّده المستهلك — الموديل ده بيخزّن ويقرا وبس.
 */
class UserPref
{
    /** صف الشركة — `users.id` بيبدأ من 1 فمستحيل يتصادم. */
    public const COMPANY = 0;

    /** أقصى طول للمفتاح والقيمة — حارس ضد تخزين مفتوح من العميل. */
    private const KEY_MAX   = 180;
    private const VALUE_MAX = 20000;

    /**
     * **البادئات المسموحة وبس** — العميل بيبعت المفتاح، فمن غير القايمة دي
     * الجدول بيبقى مخزن مفتوح لأي حاجة الواجهة تخترعها.
     */
    private const ALLOWED_PREFIXES = ['table.', 'views.', 'ui.'];

    private static ?bool $ready = null;
    private static array $cache = [];   // "uid|key" => value|null

    public static function ready(): bool
    {
        if (self::$ready !== null) { return self::$ready; }
        try {
            self::$ready = (bool) Database::scalar(
                'SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = ?', ['user_prefs']);
        } catch (\Throwable $e) {
            self::$ready = false;
        }
        return self::$ready;
    }

    /** ⚠️ إلزامية في آخر أي ترحيل بينشئ الجدول (مصيدة الكاش الساكن v57). */
    public static function refreshReady(): bool
    {
        self::$ready = null;
        self::$cache = [];
        return self::ready();
    }

    /** هل المفتاح ده في مساحة أسماء معروفة؟ */
    public static function validKey(string $key): bool
    {
        if ($key === '' || mb_strlen($key) > self::KEY_MAX) { return false; }
        // ⚠️ محارف محدودة عن قصد: المفتاح بيتكوّن من مسار + بصمة، مش نص حر
        if (!preg_match('~^[A-Za-z0-9_.:/\-]+$~', $key)) { return false; }
        foreach (self::ALLOWED_PREFIXES as $p) {
            if (str_starts_with($key, $p)) { return true; }
        }
        return false;
    }

    // ───────────────────────────────────────────────────────── القراءة

    /**
     * **القيمة الفعّالة للمستخدم** بعد تطبيق المستويات التلاتة.
     *
     * بترجّع `null` لو مفيش تفضيل على أي مستوى — والمستهلك بيقع على افتراضي
     * الكود، فالسلوك القديم بيفضل شغّالًا قبل ما حد يظبط أي حاجة.
     */
    public static function resolve(string $key, ?int $userId = null): ?string
    {
        if (!self::ready() || !self::validKey($key)) { return null; }
        $uid = $userId ?? (int) ($_SESSION['uid'] ?? 0);

        $company = self::raw(self::COMPANY, $key);
        // ⚠️ الشركة مقفولة ⇒ تفضيل المستخدم يتجاهَل تمامًا (مش بيتشال، بيتجاهَل)
        if ($company !== null && (int) $company['is_locked'] === 1) {
            return (string) $company['pref_value'];
        }
        if ($uid > 0) {
            $mine = self::raw($uid, $key);
            if ($mine !== null) { return (string) $mine['pref_value']; }
        }
        return $company !== null ? (string) $company['pref_value'] : null;
    }

    /**
     * كل تفضيلات المستخدم الفعّالة دفعة واحدة — للحقن في الصفحة.
     *
     * ⚠️ **استعلام واحد لكل الصفحة**: القراءة صف-بصف كانت هتعمل استعلامًا لكل
     *    جدول في الشاشة، والشاشات فيها لحد ٤ جداول.
     *
     * @return array<string,array{value:string,locked:bool}>
     */
    public static function allFor(?int $userId = null): array
    {
        if (!self::ready()) { return []; }
        $uid  = $userId ?? (int) ($_SESSION['uid'] ?? 0);
        $rows = Database::all(
            'SELECT user_id, pref_key, pref_value, is_locked FROM user_prefs
              WHERE user_id IN (?, ?) ORDER BY user_id ASC', [self::COMPANY, $uid]
        );
        $out = [];
        foreach ($rows as $r) {
            $key = (string) $r['pref_key'];
            $isCompany = (int) $r['user_id'] === self::COMPANY;
            $locked = $isCompany && (int) $r['is_locked'] === 1;
            // ترتيب `user_id ASC` بيخلّي صف الشركة أولًا، وصف المستخدم بيدهسه
            // إلا لو الشركة مقفولة.
            if (isset($out[$key]) && $out[$key]['locked']) { continue; }
            $out[$key] = ['value' => (string) $r['pref_value'], 'locked' => $locked];
        }
        return $out;
    }

    /** صف خام لمستخدم بعينه (بلا حلّ مستويات). */
    private static function raw(int $userId, string $key): ?array
    {
        $ck = $userId . '|' . $key;
        if (array_key_exists($ck, self::$cache)) { return self::$cache[$ck]; }
        $row = Database::row(
            'SELECT * FROM user_prefs WHERE user_id = ? AND pref_key = ?', [$userId, $key]);
        return self::$cache[$ck] = ($row ?: null);
    }

    // ───────────────────────────────────────────────────────── الكتابة

    /**
     * حفظ تفضيل **المستخدم الحالي**.
     *
     * @throws InvalidArgumentException مفتاح مرفوض · قيمة أطول من الحد · مقفول
     */
    public static function set(string $key, string $value): void
    {
        if (!self::ready()) { return; }
        $uid = (int) ($_SESSION['uid'] ?? 0);
        if ($uid <= 0) { throw new InvalidArgumentException('لازم تكون داخل بحسابك.'); }
        self::guard($key, $value);

        /* ⚠️⚠️ **حارس القفل — ده الحارس الحقيقي**. الواجهة بتخفي الزرار، بس
           الطلب ممكن يتبعت بإيد. لو المالك قافل المفتاح ده، كتابة المستخدم
           بترفض هنا مهما كانت الشاشة عاملة إيه. */
        $company = self::raw(self::COMPANY, $key);
        if ($company !== null && (int) $company['is_locked'] === 1) {
            throw new InvalidArgumentException('الإعداد ده مثبَّت من الإدارة — مش قابل للتغيير.');
        }

        self::write($uid, $key, $value, 0);
    }

    /**
     * حفظ **افتراضي الشركة** — للمالك بس.
     *
     * @throws InvalidArgumentException
     */
    public static function setCompany(string $key, string $value, bool $locked): void
    {
        if (!self::ready()) { return; }
        if (!Auth::isOwner()) { throw new InvalidArgumentException('افتراضي الشركة للمالك بس.'); }
        self::guard($key, $value);
        self::write(self::COMPANY, $key, $value, $locked ? 1 : 0);
    }

    /** شيل تفضيل المستخدم الحالي (رجوع لافتراضي الشركة ثم الكود). */
    public static function forget(string $key): void
    {
        if (!self::ready()) { return; }
        $uid = (int) ($_SESSION['uid'] ?? 0);
        if ($uid <= 0 || !self::validKey($key)) { return; }
        Database::exec('DELETE FROM user_prefs WHERE user_id = ? AND pref_key = ?', [$uid, $key]);
        self::$cache = [];
    }

    /** صفّر كل تفضيلات المستخدم الحالي. بترجّع عدد اللي اتشال. */
    public static function forgetAll(): int
    {
        if (!self::ready()) { return 0; }
        $uid = (int) ($_SESSION['uid'] ?? 0);
        if ($uid <= 0) { return 0; }
        $n = (int) Database::scalar('SELECT COUNT(*) FROM user_prefs WHERE user_id = ?', [$uid]);
        Database::exec('DELETE FROM user_prefs WHERE user_id = ?', [$uid]);
        self::$cache = [];
        return $n;
    }

    /**
     * **ترحيل تفضيلات المتصفح لحساب المستخدم — مرة واحدة**.
     *
     * ⚠️ **بتضيف اللي ناقص وبس، وما بتدهسش الموجود**: لو المستخدم ظبّط حاجة
     *    على الخادم خلاص، نسخة المتصفح القديمة ما تفوقهاش.
     * ⚠️ والواجهة **ما بتمسحش `localStorage`** بعدها — بيفضل شبكة أمان لحد ما
     *    يتأكد إن الترحيل مشي (الخطر: ترحيل نص طريق يضيّع الشكل بلا رجعة).
     *
     * @param array<string,string> $pairs
     * @return int عدد اللي اتنقل فعلاً
     */
    public static function adopt(array $pairs): int
    {
        if (!self::ready()) { return 0; }
        $uid = (int) ($_SESSION['uid'] ?? 0);
        if ($uid <= 0) { return 0; }

        $moved = 0;
        foreach ($pairs as $key => $value) {
            $key = (string) $key;
            $value = (string) $value;
            if (!self::validKey($key) || mb_strlen($value) > self::VALUE_MAX) { continue; }
            if (self::raw($uid, $key) !== null) { continue; }          // عنده واحد أحدث
            $company = self::raw(self::COMPANY, $key);
            if ($company !== null && (int) $company['is_locked'] === 1) { continue; }
            self::write($uid, $key, $value, 0);
            $moved++;
        }
        return $moved;
    }

    /** @throws InvalidArgumentException */
    private static function guard(string $key, string $value): void
    {
        if (!self::validKey($key)) {
            throw new InvalidArgumentException('مفتاح التفضيل ده مش معروف.');
        }
        if (mb_strlen($value) > self::VALUE_MAX) {
            throw new InvalidArgumentException('قيمة التفضيل أطول من المسموح.');
        }
        // القيمة لازم تكون JSON صالح — المستهلك بيتوقّعها كده
        if ($value !== '') {
            json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException('قيمة التفضيل لازم تكون JSON صالح.');
            }
        }
    }

    private static function write(int $userId, string $key, string $value, int $locked): void
    {
        Database::exec(
            'INSERT INTO user_prefs (user_id, pref_key, pref_value, is_locked, updated_by)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value),
                                     is_locked  = VALUES(is_locked),
                                     updated_by = VALUES(updated_by)',
            [$userId, $key, $value, $locked, Auth::user()]
        );
        self::$cache = [];
    }

    // ───────────────────────────────────────────────── عرض للمالك

    /** ملخّص لكل مفاتيح الشركة — لشاشة الإعدادات. */
    public static function companyRows(): array
    {
        if (!self::ready()) { return []; }
        return Database::all(
            'SELECT * FROM user_prefs WHERE user_id = ? ORDER BY pref_key', [self::COMPANY]);
    }

    /** كام مستخدم ظابط تفضيلات خاصة — رقم بيقول للمالك إن فيه اختلافات. */
    public static function usersWithPrefs(): int
    {
        if (!self::ready()) { return 0; }
        return (int) Database::scalar(
            'SELECT COUNT(DISTINCT user_id) FROM user_prefs WHERE user_id <> ?', [self::COMPANY]);
    }
}
