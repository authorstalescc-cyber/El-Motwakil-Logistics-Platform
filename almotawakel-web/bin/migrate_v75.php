<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v75 — تفضيلات العرض بتتبع الحساب مش المتصفح.
 *       (docs/19-settings-control-center-plan.md · المرحلة ٢)
 *
 * ═══ اللزمة ═══
 * جرد 2026-08-09 لقى إن **كل طبقة العرض عايشة في `localStorage`**: الأعمدة
 * المخفية في كل جدول · كثافة الصفوف · تثبيت أول عمود · **عرض كل عمود بالبكسل** ·
 * مناظر الفلاتر المحفوظة. يعني في المتصفح، مش في الحساب. والنتايج العملية:
 *   • الموظف يظبط أعمدته على جهاز، يقعد على جهاز تاني يلاقي كله رجع للأول.
 *   • مسح كاش المتصفح = ضاع كل شيء بلا إنذار.
 *   • **المالك ما يقدرش يقول «الشركة كلها تشوف الأعمدة دي»** ولا يقفل عمود مهم.
 *   • مفيش أي أثر ولا معرفة إن الموظف مخفي عمودًا مهمًّا.
 *
 * ═══ ⚠️⚠️ العزل بالبناء (قاعدة ①) ═══
 * `user_prefs` **جدول مستقل تمامًا**: ولا كود خزنة ولا تقفيل ولا عهدة ولا
 * مصروفات بيعرف إنه موجود. تفضيل عرض عمره ما يدخل حسابًا — و`verify_user_prefs`
 * بيفشل لو أي ملف مالي ذكر الجدول.
 *
 * ═══ ثلاث مستويات ═══
 *   افتراضي الكود  ←  إعداد الشركة (`user_id = 0`)  ←  تفضيل المستخدم
 * والمالك يقدر **يقفل** مستوى الشركة (`is_locked`) فتفضيل المستخدم يتجاهَل.
 * ⚠️ **القفل بيتفرض في الموديل مش في الشاشة** — إخفاء زرار مش حماية.
 *
 * ═══ ⚠️ ليه `user_id = 0` بدل عمود scope؟ ═══
 * عشان `UNIQUE(user_id, pref_key)` تحرس المستويين مع بعض بمفتاح واحد. صف
 * الشركة صف عادي بمستخدم «صفر» — و`users.id` بيبدأ من 1 فمستحيل يتصادم.
 *
 * ⚠️ الترحيل ده **صفر أثر مالي وصفر تغيير سلوك**: الجدول بيتولد فاضي،
 *    والواجهة بتفضل تقرا من `localStorage` لحد ما المستخدم يفتح صفحة فتترحّل
 *    تفضيلاته مرة واحدة (`UserPref::adopt`).
 *
 * ⚠️ `verify()` بتثبت **حالة بنيوية دائمة** (جدول · أعمدة · فهرس فريد) مش
 *    لقطة بيانات (درس v61 الموثَّق).
 *
 * التشغيل:  php bin/migrate_v75.php [--dry-run]
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

use App\Models\CompanySafe;
use App\Models\Custody;
use App\Models\UserPref;

$v75Dry    = in_array('--dry-run', $argv ?? [], true);
$v75Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
$v75Say = static function (string $m) use ($v75Direct): void { if ($v75Direct) { echo $m . "\n"; } };

$v75HasTbl = static function (string $t): bool {
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
};
$v75HasCol = static function (string $t, string $c): bool {
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
};
$v75HasIdx = static function (string $t, string $i): bool {
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$t, $i]);
};

$v75Cols = ['id', 'user_id', 'pref_key', 'pref_value', 'is_locked', 'updated_by', 'updated_at'];

$v75Up = static function () use ($v75Dry, $v75Say, $v75HasTbl, $v75HasIdx): void {
    /* ⚠️ لقطة «ممنوع يتغيّر». بنقيس **قبل** أي DDL لأن `CREATE TABLE` بيعمل
       commit ضمني ومستحيل يترجع (المصيدة الموثَّقة في v59). */
    $before = [
        'safe'     => round((float) Custody::balance(), 2),
        'company'  => round((float) CompanySafe::balance(), 2),
        'closings' => (string) Database::scalar(
            "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS('|',close_date,opening,topups,expenses,
                    driver_custody,closing_balance) ORDER BY close_date),'') FROM daily_closings"),
    ];

    if ($v75HasTbl('user_prefs')) {
        $v75Say('  = جدول user_prefs (موجود)');
    } elseif ($v75Dry) {
        $v75Say('  • (تجربة) هيتعمل جدول user_prefs');
    } else {
        Database::exec(
            "CREATE TABLE user_prefs (
               id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               user_id    INT UNSIGNED NOT NULL COMMENT '0 = افتراضي الشركة · وإلا users.id (بلا FK، تحذير ٣)',
               pref_key   VARCHAR(180) NOT NULL COMMENT 'مفتاح التفضيل — مساحة أسماء بنقطتين',
               pref_value TEXT         NULL     COMMENT 'JSON — الشكل بيحدّده المستهلك',
               is_locked  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'لصف الشركة بس: يتجاهل تفضيل المستخدم',
               updated_by VARCHAR(80)  NULL     COMMENT 'مين آخر من عدّله',
               updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
               UNIQUE KEY uq_pref (user_id, pref_key),
               INDEX idx_pref_key (pref_key)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
               COMMENT='تفضيلات العرض — معزول بالبناء، صفر أثر مالي (v75)'"
        );
        $v75Say('  + جدول user_prefs');
    }

    if (!$v75Dry && $v75HasTbl('user_prefs')) {
        /* ⚠️ تصفير الكاش الساكن **إلزامي**: `UserPref::ready()` اتقرت قبل الـDDL
           فبترجّع false للأبد جوّه نفس العملية (مصيدة v57 الموثَّقة). */
        UserPref::refreshReady();
        $v75Say('  ✓ UserPref::ready اتصفّرت واتقرت من جديد');
    }

    $after = [
        'safe'     => round((float) Custody::balance(), 2),
        'company'  => round((float) CompanySafe::balance(), 2),
        'closings' => (string) Database::scalar(
            "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS('|',close_date,opening,topups,expenses,
                    driver_custody,closing_balance) ORDER BY close_date),'') FROM daily_closings"),
    ];
    foreach ($before as $k => $v) {
        if ($after[$k] !== $v) {
            // ⚠️ `{$k}` مش `$k` — بايتات «» بتتحسب جزءًا من اسم المتغيّر (مصيدة v38).
            throw new \RuntimeException("v75: «{$k}» اتغيّر أثناء الترحيل — الترحيل ده عرض بس.");
        }
    }
    $v75Say('  ✓ الأرصدة وصفوف الإقفال ما اتغيّروش');
};

$v75Verify = static function () use ($v75Say, $v75HasTbl, $v75HasCol, $v75HasIdx, $v75Cols): bool {
    if (!$v75HasTbl('user_prefs')) { $v75Say('✗ جدول user_prefs ناقص.'); return false; }
    $v75Say('  ✓ جدول user_prefs موجود');

    foreach ($v75Cols as $col) {
        if (!$v75HasCol('user_prefs', $col)) { $v75Say("✗ العمود user_prefs.$col ناقص."); return false; }
    }
    $v75Say('  ✓ كل أعمدة v75 موجودة (' . count($v75Cols) . ')');

    /* ⚠️ الفهرس الفريد **شرط سلامة**: صفّان لنفس المستخدم ونفس المفتاح معناهم
       تفضيلان متعارضان، والقراءة بتختار واحدًا عشوائيًّا فالشاشة تتغيّر لوحدها. */
    if (!$v75HasIdx('user_prefs', 'uq_pref')) { $v75Say('✗ الفهرس الفريد uq_pref ناقص.'); return false; }
    $v75Say('  ✓ فهرس فريد (مستخدم + مفتاح) — مستحيل تفضيلان متعارضان');

    // شرط بنيوي دائم: القفل لصف الشركة بس — قفل على صف مستخدم بلا معنى
    $bad = (int) Database::scalar('SELECT COUNT(*) FROM user_prefs WHERE is_locked = 1 AND user_id <> 0');
    if ($bad > 0) { $v75Say("✗ فيه $bad صف مستخدم متعلَّم مقفول — القفل لصف الشركة بس."); return false; }
    $v75Say('  ✓ ولا قفل على صف مستخدم');

    if (!UserPref::refreshReady()) { $v75Say('✗ UserPref::ready بترجّع false.'); return false; }
    $v75Say('  ✓ UserPref::ready فعّالة');
    return true;
};

if ($v75Direct) {
    echo 'v75 — تفضيلات العرض بتتبع الحساب' . ($v75Dry ? '  (تجربة — مفيش كتابة)' : '') . "\n";
    echo str_repeat('=', 62) . "\n";
    $v75Up();
    if ($v75Dry) { echo "\nشغّل من غير --dry-run عشان يتطبّق.\n"; exit(0); }
    if ($v75Verify() !== true) { fwrite(STDERR, "فشل التحقق بعد تطبيق v75.\n"); exit(1); }
    echo "\n✓ تمّ.\n";
}

return ['up' => $v75Up, 'verify' => $v75Verify];
