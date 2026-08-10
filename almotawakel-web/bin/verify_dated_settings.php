<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص الإعداد المالي المؤرَّخ — المرحلة ١ب من docs/19-settings-control-center-plan.md
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  ليه الفاحص ده موجود — اقرأ قبل ما تعدّل
 * ═══════════════════════════════════════════════════════════════════════
 * المرحلة ١ حطّت `custody_bill_deduction` في الشاشة لأول مرة. الرقم ده بيتضرب
 * في **كل بوليصة في التاريخ**، فتغييره من ١٠٠ لـ١٥٠ كان بيعيد حساب رصيد
 * الخزنة وصفوف الإقفال وكشوف السائقين **بأثر رجعي وبالسكوت** — المالك بيغيّر
 * قاعدة النهارده فيلاقي أرقام الشهر اللي فات اتحرّكت.
 *
 * v74 حلّها بسلسلة قيم مؤرَّخة. والفاحص ده **بيثبت الضمانة بالتجربة مش بالنية**:
 * بيزرع تغييرًا مؤرَّخًا حقيقيًّا في المستقبل وسط بيانات حقيقية، وبيقيس إن
 * إجماليات الماضي **ما اتحركتش ولا مليم** — وإن بوليصة بعد التاريخ اتغيّرت فعلاً.
 *
 * ⚠️ **الفحص على السلوك مش على النص**: مفيش grep على أسماء دوال. كل بند
 *    بيتقاس بحساب حقيقي على `bawalis` جوّه transaction بتترجع.
 *
 * الأقسام:
 *   ① السلسلة بتترجم التاريخ لقيمة صح (حدود اليوم بالظبط)
 *   ② تعبير SQL بيدّي نفس نتيجة الحساب في PHP
 *   ③ **الضمانة الكبرى**: تغيير مؤرَّخ ما بيحركش الماضي
 *   ④ الحرّاس: صف الأزل محميّ · المفتاح غير المؤرَّخ مرفوض · الدور
 *   ⑤ تغطية نقاط الاستدعاء — ولا نقطة بتحسب بلا تاريخ
 *   ⑥ الصف الواحد = نفس شكل SQL القديم (صفر فرق قبل أول تغيير)
 *
 * التشغيل:  php bin/verify_dated_settings.php
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\Settings;
use App\Models\Custody;

$pass = 0; $fail = 0; $skip = 0;
function ok(string $m): void   { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  ✗ $m\n"; }
function skp(string $m): void  { global $skip; $skip++; echo "  ~ $m (تخطّي)\n"; }
function sec(string $m): void  { echo "\n" . $m . "\n" . str_repeat('─', 66) . "\n"; }

/** ⚠️ إجبارية قبل أي فحص نصّي — التعليق بيذكر النص الممنوع. */
function code_only(string $src): string
{
    return preg_replace('~/\*.*?\*/|(?<!:)//[^\n]*~s', '', $src) ?? $src;
}

$root = dirname(__DIR__);
const KEY = 'custody_bill_deduction';

echo "فحص الإعداد المالي المؤرَّخ (docs/19 · المرحلة ١ب)\n" . str_repeat('=', 66) . "\n";

if (!Settings::scheduleReady()) {
    echo "\n⚠️ جدول setting_schedules مش موجود — شغّل bin/migrate_v74.php الأول.\n";
    exit(1);
}

/** إجمالي المخصوم من كل البوالص — الرقم اللي كل الفحص بيدور حواليه. */
$totalDeducted = static function (?string $upto = null): float {
    [$sql, $args] = Custody::deductionSql('custody', 'car_no', 'bill_date');
    $w = '';
    if ($upto !== null) { $w = ' AND bill_date <= ?'; }
    $params = $args;
    if ($upto !== null) { $params[] = $upto; }
    return round((float) Database::scalar(
        "SELECT COALESCE(SUM($sql),0) FROM bawalis WHERE is_legacy = 0 AND custody > 0$w", $params), 2);
};

$pdo = Database::conn();
$pdo->beginTransaction();
try {

// ═══════════════════════════════════════════════════════════ ①
sec('① السلسلة بتترجم التاريخ لقيمة صح');

$base = Settings::valueOn(KEY, '2000-01-01');
$base !== '' ? ok("قيمة الأزل موجودة ({$base})") : bad('السلسلة بلا قيمة أساس');

Settings::schedule(KEY, '2026-09-01', '150', 'فحص');
Settings::valueOn(KEY, '2026-08-31') === $base
    ? ok('اليوم اللي قبل السريان بياخد القيمة القديمة')
    : bad('اليوم اللي قبل السريان اتغيّر');
Settings::valueOn(KEY, '2026-09-01') === '150'
    ? ok('يوم السريان نفسه بياخد الجديدة (الحد شامل)')
    : bad('يوم السريان ما اتغيّرش');
Settings::valueOn(KEY, '2027-01-01') === '150'
    ? ok('أي يوم بعده بياخد الجديدة') : bad('اليوم اللي بعده غلط');

// نقطة تالتة — التسلسل بيتحل بالأحدث الأقل من التاريخ
Settings::schedule(KEY, '2026-10-01', '200', 'فحص');
(Settings::valueOn(KEY, '2026-09-15') === '150' && Settings::valueOn(KEY, '2026-10-02') === '200')
    ? ok('٣ نقاط: كل تاريخ بياخد أقرب قيمة قبله') : bad('التسلسل بـ٣ نقاط غلط');

// ═══════════════════════════════════════════════════════════ ②
sec('② تعبير SQL بيدّي نفس نتيجة PHP');

$mismatch = 0; $checked = 0;
foreach (Database::all(
    'SELECT bill_date, custody, car_no FROM bawalis
      WHERE is_legacy = 0 AND custody > 0 ORDER BY id LIMIT 40') as $b) {
    $php = Custody::billDeduction((float) $b['custody'], $b['car_no'], (string) $b['bill_date']);
    [$sql, $args] = Custody::deductionSql('?', '?', '?');
    // ⚠️ ترتيب البارامترات: المستثناة → التاريخ/القيمة، وبعدين أعمدة التعبير
    [$sqlReal, $argsReal] = Custody::deductionSql('custody', 'car_no', 'bill_date');
    $db = (float) Database::scalar(
        "SELECT $sqlReal FROM bawalis WHERE bill_date = ? AND custody = ? LIMIT 1",
        array_merge($argsReal, [$b['bill_date'], $b['custody']]));
    $checked++;
    if (abs($php - $db) > 0.005) { $mismatch++; }
}
$checked === 0
    ? skp('مفيش بوالص بعهدة للمقارنة')
    : ($mismatch === 0
        ? ok("PHP وSQL بيدّوا نفس الرقم على {$checked} بوليصة حقيقية")
        : bad("فرق بين PHP وSQL في {$mismatch} من {$checked}"));

// ═══════════════════════════════════════════════════════════ ③
sec('③ ⚠️ الضمانة الكبرى: التغيير المؤرَّخ ما بيحركش الماضي');

// نرجّع السلسلة لنقطة واحدة (الأزل) ونقيس خط الأساس
Database::exec('DELETE FROM setting_schedules WHERE setting_key = ? AND effective_from <> ?',
    [KEY, Settings::EPOCH_DATE]);
Settings::refreshScheduleReady();

$maxDate = (string) Database::scalar(
    'SELECT COALESCE(MAX(bill_date), CURDATE()) FROM bawalis WHERE is_legacy = 0 AND custody > 0');
$before = $totalDeducted();
$beforePast = $totalDeducted($maxDate);

// تغيير سارٍ **بعد** آخر بوليصة — المفروض ما يلمسش ولا رقم تاريخي
$future = date('Y-m-d', strtotime($maxDate . ' +1 day'));
Settings::schedule(KEY, $future, '999', 'فحص المستقبل');
$afterPast = $totalDeducted($maxDate);

abs($beforePast - $afterPast) < 0.005
    ? ok('تغيير سارٍ من بكرة: إجمالي المخصوم التاريخي ما اتحركش ولا مليم')
    : bad("الماضي اتحرّك: قبل {$beforePast} · بعد {$afterPast}");

// ونفس الحكاية على رصيد الخزنة نفسه — الرقم اللي المالك بيشوفه
$balBefore = round((float) Custody::balance(), 2);
Settings::schedule(KEY, $future, '777', 'فحص المستقبل ٢');
$balAfter = round((float) Custody::balance(), 2);
abs($balBefore - $balAfter) < 0.005
    ? ok('ورصيد الخزنة نفسه ما اتغيّرش')
    : bad("رصيد الخزنة اتحرّك: قبل {$balBefore} · بعد {$balAfter}");

/* والعكس لازم يشتغل كمان: تغيير سارٍ من **قبل** أقدم بوليصة **لازم** يحرّك
   الأرقام — وإلا الآلية بتتجاهل السلسلة أصلاً والفحص فوق بينجح كذبًا. */
$minDate = (string) Database::scalar(
    'SELECT MIN(bill_date) FROM bawalis WHERE is_legacy = 0 AND custody > 0');
if ($minDate !== '' && $minDate !== null) {
    $early = date('Y-m-d', strtotime($minDate . ' -1 day'));
    $t0 = $totalDeducted($maxDate);
    Settings::schedule(KEY, $early, '1', 'فحص الحساسية');
    $t1 = $totalDeducted($maxDate);
    abs($t0 - $t1) > 0.005
        ? ok('تغيير سارٍ من قبل أقدم بوليصة **بيحرّك** الأرقام (الآلية شغّالة فعلاً)')
        : bad('السلسلة متجاهَلة — الأرقام ما اتغيّرتش رغم تغيير سارٍ على الكل');
    Database::exec('DELETE FROM setting_schedules WHERE setting_key = ? AND effective_from = ?', [KEY, $early]);
    Settings::refreshScheduleReady();
} else {
    skp('مفيش بوالص بعهدة لاختبار الحساسية');
}

// ═══════════════════════════════════════════════════════════ ④
sec('④ الحرّاس');

$denied = static function (callable $fn): bool {
    try { $fn(); return false; } catch (\Throwable $e) { return true; }
};

$denied(static fn() => Settings::schedule(KEY, Settings::EPOCH_DATE, '5'))
    ? ok('صف الأزل ما يتغيّرش — الماضي محميّ من الجذر')
    : bad('صف الأزل اتغيّر!');

$denied(static fn() => Settings::schedule('vat_rate', '2027-01-01', '0.2'))
    ? ok('المفتاح غير المؤرَّخ بيترفض من الجدولة')
    : bad('مفتاح غير مؤرَّخ اتجدول');

$denied(static fn() => Settings::schedule(KEY, '01/09/2026', '150'))
    ? ok('تاريخ بصيغة غلط بيترفض') : bad('تاريخ غلط عدّى');

$denied(static fn() => Settings::schedule(KEY, '2027-01-01', '-5'))
    ? ok('قيمة برّه النطاق بترفض حتى في الجدولة')
    : bad('قيمة سالبة اتجدولت');

// حذف صف الأزل ممنوع
$epochRow = Database::row('SELECT id FROM setting_schedules WHERE setting_key = ? AND effective_from = ?',
    [KEY, Settings::EPOCH_DATE]);
$epochRow
    ? ($denied(static fn() => Settings::unschedule((int) $epochRow['id']))
        ? ok('حذف صف الأزل ممنوع') : bad('صف الأزل اتحذف!'))
    : bad('صف الأزل مش موجود أصلاً');

// ═══════════════════════════════════════════════════════════ ⑤
sec('⑤ تغطية نقاط الاستدعاء — ولا نقطة بتحسب بلا تاريخ');

/* ⚠️ **الحد ده ما يترخّيش**: أي نقطة بتحسب المخصوم بلا تاريخ بتاخد القيمة
   السارية النهارده على صف تاريخي — يعني رقم غلط بالسكوت بعد أول تغيير. */
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app'));
foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') { $files[] = $f->getPathname(); }
}
$noDate = [];
foreach ($files as $file) {
    if (str_ends_with($file, 'Models/Custody.php')) { continue; }   // التعريف نفسه
    $code = code_only((string) file_get_contents($file));
    $rel  = str_replace($root . '/', '', $file);
    // deductionSql بمعاملين بس = بلا عمود تاريخ
    if (preg_match_all('~deductionSql\(\s*[^)]*?\)~s', $code, $m)) {
        foreach ($m[0] as $call) {
            if (substr_count($call, ',') < 2) { $noDate[] = $rel . ' :: ' . trim($call); }
        }
    }
    // billDeduction بمعاملين بس = بلا تاريخ
    if (preg_match_all('~billDeduction\(\s*(?:[^()]|\([^()]*\))*\)~s', $code, $m2)) {
        foreach ($m2[0] as $call) {
            if (substr_count($call, ',') < 2) { $noDate[] = $rel . ' :: ' . trim(preg_replace('/\s+/', ' ', $call)); }
        }
    }
}
$noDate
    ? (function () use ($noDate) { foreach ($noDate as $n) { bad('حساب بلا تاريخ: ' . $n); } })()
    : ok('كل نقاط حساب المخصوم بتمرّر تاريخ البوليصة');

// ═══════════════════════════════════════════════════════════ ⑥
sec('⑥ الصف الواحد = نفس شكل SQL القديم');

Database::exec('DELETE FROM setting_schedules WHERE setting_key = ? AND effective_from <> ?',
    [KEY, Settings::EPOCH_DATE]);
Settings::refreshScheduleReady();
[$oneSql, ] = Settings::datedSql(KEY, 'bill_date');
$oneSql === '?'
    ? ok('سلسلة بصف واحد بتطلّع `?` عادي — الاستعلامات زي ما كانت بالحرف')
    : bad("سلسلة بصف واحد طلّعت تعبيرًا: {$oneSql}");

Settings::schedule(KEY, '2026-12-01', '120', 'فحص الشكل');
[$twoSql, $twoArgs] = Settings::datedSql(KEY, 'bill_date');
(str_starts_with($twoSql, 'CASE') && count($twoArgs) === 3)
    ? ok('سلسلة بصفّين بتطلّع CASE ببارامتراتها الصح')
    : bad("شكل CASE غلط: {$twoSql} · " . count($twoArgs) . ' بارامتر');

} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    Settings::refreshScheduleReady();
}

// ═══════════════════════════════════════════════════════════ الخلاصة
echo "\n" . str_repeat('=', 66) . "\n";
echo "النتيجة: $pass ناجح · $fail فاشل · $skip متخطّي\n";
if ($fail > 0) {
    echo "\n⚠️ الماضي مش متجمّد أو حارس مكسور — راجع الفوق.\n";
    exit(1);
}
echo "✓ الإعداد المالي المؤرَّخ سليم — الماضي متجمّد بالبناء.\n";
exit(0);
