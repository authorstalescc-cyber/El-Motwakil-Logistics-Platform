<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v74 — الإعداد المالي بيتغيّر **من تاريخ**، مش بأثر رجعي.
 *       (docs/19-settings-control-center-plan.md · المرحلة ١ب)
 *
 * ═══ اللزمة ═══
 * المرحلة ١ حطّت `custody_bill_deduction` (المخصوم من عهدة كل بوليصة) في الشاشة
 * لأول مرة — وده **خطر بشكله ده**: الرقم بيتضرب في `Custody::deductionSql()`
 * على **كل بوليصة في التاريخ**، فتغييره من ١٠٠ لـ١٥٠ كان بيعيد حساب رصيد
 * الخزنة وكل صفوف الإقفال وكشوف السائقين **بأثر رجعي وبالسكوت**. المالك بيغيّر
 * قاعدة النهارده فيلاقي أرقام الشهر اللي فات اتحرّكت.
 *
 * ═══ الحل: سلسلة قيم مؤرَّخة ═══
 * `setting_schedules` = صف لكل «القيمة بقت كذا ابتداءً من تاريخ كذا».
 * القيمة السارية على بوليصة = صاحبة **أكبر `effective_from` ≤ `bill_date`**.
 * يعني تغيير النهارده بيسري على اللي جاي بس، والماضي متجمّد بالبناء لا بالنية.
 *
 * ═══ ⚠️⚠️ الترحيل ده حياديّ ماليًّا بالكامل ═══
 * بيزرع **صف واحد** بالقيمة الحالية بالحرف على تاريخ `1970-01-01`، يعني
 * «القيمة دي كانت سارية من الأزل» — فكل حساب في النظام بيدّي **نفس الرقم
 * بالضبط** قبل وبعد. و`$up` بياخد لقطة قبل/بعد لرصيد الخزنة وإجمالي المخصوم
 * وصفوف الإقفال وبيرمي استثناء لو مليم اتحرّك.
 *
 * ═══ ⚠️ ليه جدول مش JSON في `settings`؟ ═══
 * لأن `Custody::deductionSql()` بتتحطّ **جوّه SQL** على مئات الصفوف: التعبير
 * محتاج يترجم `bill_date` لقيمة، وده بيتعمل بـ`CASE` مبنيّ من السلسلة. JSON
 * كان هيتفكّ في PHP الأول وبعدين يتحوّل CASE برضه — فالجدول هو الشكل الطبيعي،
 * وكمان بيدّي فهرسًا وترتيبًا وسطرًا لكل تغيير في الواجهة.
 *
 * ⚠️ **الصف الوحيد = نفس شكل SQL القديم**: لو السلسلة فيها صف واحد، البنّاء
 *    بيطلّع رقمًا عاديًّا (`?`) مش `CASE` — فالاستعلامات تفضل حرفيًّا زي ما كانت
 *    لحد ما المالك يعمل أول تغيير مؤرَّخ.
 *
 * ⚠️ `verify()` بتثبت **حالة بنيوية دائمة** (جدول · أعمدة · فهرس فريد · وجود
 *    صف الأساس) مش لقطة بيانات لحظية (درس v61 الموثَّق).
 *
 * التشغيل:  php bin/migrate_v74.php [--dry-run]
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Database;
use App\Core\Settings;

use App\Models\CompanySafe;
use App\Models\Custody;

$v74Dry    = in_array('--dry-run', $argv ?? [], true);
$v74Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
$v74Say = static function (string $m) use ($v74Direct): void { if ($v74Direct) { echo $m . "\n"; } };

$v74HasTbl = static function (string $t): bool {
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
};
$v74HasCol = static function (string $t, string $c): bool {
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
};
$v74HasIdx = static function (string $t, string $i): bool {
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$t, $i]);
};

$v74Cols = ['id', 'setting_key', 'effective_from', 'value', 'note', 'created_by', 'created_at'];

$v74Up = static function () use ($v74Dry, $v74Say, $v74HasTbl, $v74HasIdx): void {
    /* ⚠️ لقطة «ممنوع يتغيّر». بنقيس **قبل** أي DDL لأن `CREATE TABLE` بيعمل
       commit ضمني ومستحيل يترجع (المصيدة الموثَّقة في v59). */
    $before = [
        'safe'     => round((float) Custody::balance(), 2),
        'company'  => round((float) CompanySafe::balance(), 2),
        // إجمالي المخصوم من كل البوالص — الرقم اللي الترحيل ده ممنوع يلمسه
        'deducted' => (static function (): float {
            [$sql, $args] = Custody::deductionSql('custody', 'car_no');
            return round((float) Database::scalar(
                "SELECT COALESCE(SUM($sql),0) FROM bawalis WHERE is_legacy = 0 AND custody > 0", $args), 2);
        })(),
        'closings' => (string) Database::scalar(
            "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS('|',close_date,opening,topups,expenses,
                    driver_custody,closing_balance) ORDER BY close_date),'') FROM daily_closings"),
    ];

    // ①  جدول السلسلة المؤرَّخة
    if ($v74HasTbl('setting_schedules')) {
        $v74Say('  = جدول setting_schedules (موجود)');
    } elseif ($v74Dry) {
        $v74Say('  • (تجربة) هيتعمل جدول setting_schedules');
    } else {
        Database::exec(
            "CREATE TABLE setting_schedules (
               id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               setting_key    VARCHAR(64) NOT NULL COMMENT 'مفتاح الإعداد',
               effective_from DATE        NOT NULL COMMENT 'القيمة سارية من اليوم ده (شامل)',
               value          VARCHAR(64) NOT NULL COMMENT 'القيمة كنص — نفس تخزين settings',
               note           VARCHAR(255) NULL     COMMENT 'سبب التغيير لو اتكتب',
               created_by     VARCHAR(80)  NULL     COMMENT 'مين ضاف الصف',
               created_at     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
               UNIQUE KEY uq_sched (setting_key, effective_from),
               INDEX idx_sched_key (setting_key, effective_from)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
               COMMENT='سلسلة قيم الإعداد المؤرَّخة — الماضي ما بيتحركش (v74)'"
        );
        $v74Say('  + جدول setting_schedules');
    }

    /* ② صف الأساس: القيمة الحالية **بالحرف** على `1970-01-01`.
       ⚠️ ده اللي بيخلّي الترحيل حياديًّا: كل بوليصة في التاريخ بتلاقي نفس
          الرقم اللي كانت بتلاقيه قبل الترحيل بالظبط. */
    if (!$v74Dry && $v74HasTbl('setting_schedules')) {
        foreach (Settings::DATED_KEYS as $key) {
            $has = (int) Database::scalar(
                'SELECT COUNT(*) FROM setting_schedules WHERE setting_key = ?', [$key]);
            if ($has > 0) { $v74Say("  = سلسلة $key (فيها $has صف)"); continue; }
            /* ⚠️ **`Settings::get` مش `App::setting`**: المفتاح ده عمره ما اتكتب
               في جدول `settings` — كان دايمًا بيقع على الافتراضي المكتوب في
               مكان الاستدعاء (`'100'`). فالقيمة السارية فعلاً = افتراضي السجل،
               و`App::setting($key, '')` كانت بترجّع فراغًا وتتخطّى البذرة. */
            $current = (string) Settings::get($key);
            if ($current === '') { $v74Say("  ! $key بلا قيمة حالية ولا افتراضي — اتخطّى"); continue; }
            /* والأساس بيتكتب صراحةً في `settings` كمان: من دلوقتي القيمة
               **مخزَّنة** مش مستنتَجة، فالشاشة والسلسلة بيقولوا نفس الرقم. */
            if ((string) App::setting($key, '') === '') { App::setSetting($key, $current); }
            Database::exec(
                'INSERT INTO setting_schedules (setting_key, effective_from, value, note, created_by)
                 VALUES (?, ?, ?, ?, ?)',
                [$key, Settings::EPOCH_DATE, $current, 'القيمة السارية وقت تفعيل التأريخ (v74)', 'ترحيل v74']
            );
            $v74Say("  + صف أساس $key = $current من " . Settings::EPOCH_DATE);
        }
        Settings::refreshScheduleReady();
        $v74Say('  ✓ Settings::scheduleReady اتصفّرت واتقرت من جديد');
    }

    // ③  إثبات إن ولا مليم اتحرّك
    $after = [
        'safe'     => round((float) Custody::balance(), 2),
        'company'  => round((float) CompanySafe::balance(), 2),
        'deducted' => (static function (): float {
            [$sql, $args] = Custody::deductionSql('custody', 'car_no');
            return round((float) Database::scalar(
                "SELECT COALESCE(SUM($sql),0) FROM bawalis WHERE is_legacy = 0 AND custody > 0", $args), 2);
        })(),
        'closings' => (string) Database::scalar(
            "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS('|',close_date,opening,topups,expenses,
                    driver_custody,closing_balance) ORDER BY close_date),'') FROM daily_closings"),
    ];
    foreach ($before as $k => $v) {
        if ($after[$k] !== $v) {
            // ⚠️ `{$k}` مش `$k` — بايتات «» بتتحسب جزءًا من اسم المتغيّر (مصيدة v38).
            throw new \RuntimeException("v74: «{$k}» اتغيّر أثناء الترحيل — الترحيل ده تأريخ بس.");
        }
    }
    $v74Say('  ✓ رصيد الخزنة وخزنة الشركة وإجمالي المخصوم وصفوف الإقفال ما اتغيّروش');
};

$v74Verify = static function () use ($v74Say, $v74HasTbl, $v74HasCol, $v74HasIdx, $v74Cols): bool {
    if (!$v74HasTbl('setting_schedules')) { $v74Say('✗ جدول setting_schedules ناقص.'); return false; }
    $v74Say('  ✓ جدول setting_schedules موجود');

    foreach ($v74Cols as $col) {
        if (!$v74HasCol('setting_schedules', $col)) {
            $v74Say("✗ العمود setting_schedules.$col ناقص."); return false;
        }
    }
    $v74Say('  ✓ كل أعمدة v74 موجودة (' . count($v74Cols) . ')');

    /* ⚠️ الفهرس الفريد **شرط سلامة مش تحسين أداء**: صفّان بنفس المفتاح ونفس
       التاريخ معناهم قيمتان ساريتان في نفس اليوم — والـ`ORDER BY … LIMIT 1`
       كان هيختار واحدة **عشوائيًّا** فالرقم يتغيّر بين استعلام والتاني. */
    if (!$v74HasIdx('setting_schedules', 'uq_sched')) { $v74Say('✗ الفهرس الفريد uq_sched ناقص.'); return false; }
    $v74Say('  ✓ فهرس فريد (مفتاح + تاريخ) — مستحيل قيمتان في نفس اليوم');

    // كل مفتاح مؤرَّخ لازم يكون له سلسلة، وإلا القراءة بترجع للأساس بالسكوت
    foreach (Settings::DATED_KEYS as $key) {
        $n = (int) Database::scalar('SELECT COUNT(*) FROM setting_schedules WHERE setting_key = ?', [$key]);
        if ($n < 1) { $v74Say("✗ المفتاح المؤرَّخ $key بلا أي صف في السلسلة."); return false; }
    }
    $v74Say('  ✓ كل مفتاح مؤرَّخ له سلسلة (' . count(Settings::DATED_KEYS) . ' مفتاح)');

    // شرط بنيوي دائم: ولا مفتاح في الجدول برّه قايمة المفاتيح المؤرَّخة
    $in  = implode(',', array_fill(0, count(Settings::DATED_KEYS), '?'));
    $bad = Database::all(
        "SELECT DISTINCT setting_key FROM setting_schedules WHERE setting_key NOT IN ($in)",
        Settings::DATED_KEYS);
    if ($bad) {
        $v74Say('✗ مفتاح مجدوَل مش معلَّم كمؤرَّخ: ' . implode(', ', array_column($bad, 'setting_key')));
        return false;
    }
    $v74Say('  ✓ ولا مفتاح في الجدول برّه قايمة المؤرَّخة');

    if (!Settings::refreshScheduleReady()) { $v74Say('✗ Settings::scheduleReady بترجّع false.'); return false; }
    $v74Say('  ✓ Settings::scheduleReady فعّالة');
    return true;
};

if ($v74Direct) {
    echo 'v74 — الإعداد المالي بيتغيّر من تاريخ' . ($v74Dry ? '  (تجربة — مفيش كتابة)' : '') . "\n";
    echo str_repeat('=', 62) . "\n";
    $v74Up();
    if ($v74Dry) { echo "\nشغّل من غير --dry-run عشان يتطبّق.\n"; exit(0); }
    if ($v74Verify() !== true) { fwrite(STDERR, "فشل التحقق بعد تطبيق v74.\n"); exit(1); }
    echo "\n✓ تمّ.\n";
}

return ['up' => $v74Up, 'verify' => $v74Verify];
