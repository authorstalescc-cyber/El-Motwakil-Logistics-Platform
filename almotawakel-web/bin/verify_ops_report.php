<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص وحدة «تقارير التشغيل» `/ops`.
 *
 * أهم خمس حاجات:
 *   ١) **قراءة فقط بالكامل** — ولا `INSERT/UPDATE/DELETE` في الموديل ولا
 *      الكنترولر، وكل المسارات `GET`، ورصيد الخزنة قبل = بعد.
 *   ٢) **مفيش نولون** (طلب المالك الحرفي «بعيدًا عن الناولون») — لو رجع،
 *      الوحدة بتتحوّل لنسخة تانية من `/cars` وبتفقد سبب وجودها.
 *   ٣) **الأرقام ما تختلفش عن بعضها**: مجموع أي تقسيم = الإجمالي فوق بالظبط،
 *      ومجموع مخطط الاتجاه = مجموع الأيام.
 *   ٤) **العزل والاستبعاد**: بوالص الأرشيف (`is_legacy=1`) والبوالص بلا
 *      تاريخ مستبعدة، والمخصوم من الخزنة بيتحسب بـ`Custody::deductionSql()`
 *      نفسها بتاعة رصيد الخزنة.
 *   ٥) **رندر فعلي** للشاشة والنوافذ والطباعة ببيانات مزروعة — الرندر على
 *      صفحة فاضية بينجح كذبًا (0=0)، فبنزرع ونتأكد إن فيه بنود فعلاً.
 *
 * كله جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_ops_report.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Access;
use App\Core\Database;
use App\Models\Closing;
use App\Models\Custody;
use App\Models\OfficeCar;
use App\Models\OpsReport;

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function sec(string $m): void { echo "\n" . $m . "\n" . str_repeat('─', 62) . "\n"; }
function same(string $l, $a, $e): void {
    $a == $e ? ok($l) : bad("$l — متوقع " . var_export($e, true) . " / فعلي " . var_export($a, true));
}
function near(string $l, $a, $e): void {
    abs((float) $a - (float) $e) <= 0.005 ? ok($l)
        : bad("$l — متوقع " . number_format((float) $e, 2) . " / فعلي " . number_format((float) $a, 2));
}
function yes(string $l, bool $c): void { $c ? ok($l) : bad($l); }

$root = dirname(__DIR__);
/** بيشيل التعليقات قبل أي فحص على الكود — تعليق بيشرح القاعدة ما يعديش كإنذار كاذب. */
function php_code(string $path): string
{
    if (!is_file($path)) { return ''; }
    $out = '';
    foreach (token_get_all((string) file_get_contents($path)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

echo "فحص وحدة تقارير التشغيل /ops\n" . str_repeat('=', 62) . "\n";

$MODEL = $root . '/app/Models/OpsReport.php';
$CTRL  = $root . '/app/Controllers/OpsReportController.php';
$VIEWS = glob($root . '/app/Views/ops/*.php') ?: [];

// ═══════════════════════════════════════════ ٠) الملفات
sec('٠) الملفات موجودة');
yes('الموديل App\Models\OpsReport', is_file($MODEL));
yes('الكنترولر OpsReportController', is_file($CTRL));
yes('شاشات ops/index · _drill · print · _trip_row', count($VIEWS) === 4);
foreach (array_merge([$MODEL, $CTRL], $VIEWS) as $f) {
    $out = []; $rc = 0;
    if (function_exists('exec')) {
        @exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
        if ($rc !== 0) { bad('نحو سليم: ' . basename($f) . ' — ' . implode(' ', $out)); }
    }
}
ok('نحو كل ملفات الوحدة سليم');

// ═══════════════════════════════════════════ ١) قراءة فقط
sec('١) ⚠️ الوحدة قراءة فقط بالكامل');
$modelCode = php_code($MODEL);
$ctrlCode  = php_code($CTRL);
$writes = [];
foreach (['INSERT', 'UPDATE ', 'DELETE', 'REPLACE INTO', 'TRUNCATE', 'ALTER ', 'CREATE TABLE', 'DROP '] as $kw) {
    if (stripos($modelCode, $kw) !== false) { $writes[] = "OpsReport: $kw"; }
    if (stripos($ctrlCode, $kw) !== false)  { $writes[] = "Controller: $kw"; }
}
yes('مفيش أي كتابة في الموديل ولا الكنترولر', !$writes)
    ?: print('      ' . implode(' · ', $writes) . "\n");

$routes = (string) file_get_contents($root . '/public/index.php');
preg_match_all("~\\\$r->(get|post)\('(/ops[^']*)'~", $routes, $rm, PREG_SET_ORDER);
same('عدد مسارات /ops', count($rm), 3);
yes('كل مسارات /ops من نوع GET', !array_filter($rm, static fn ($m) => $m[1] !== 'get'));
$paths = array_column($rm, 2);
yes('  › /ops مسجَّل', in_array('/ops', $paths, true));
yes('  › /ops/print مسجَّل', in_array('/ops/print', $paths, true));
yes('  › /ops/drill/{what} مسجَّل', in_array('/ops/drill/{what}', $paths, true));
// ⚠️ تحذير ٤: المسار الحرفي قبل أي مسار فيه معامل.
yes('/ops/print قبل /ops/drill/{what} في الراوتر',
    array_search('/ops/print', $paths, true) < array_search('/ops/drill/{what}', $paths, true));

$formInViews = false;
foreach ($VIEWS as $v) {
    if (preg_match('~<form[^>]*method\s*=\s*["\']?post~i', (string) file_get_contents($v))) {
        $formInViews = true;
    }
}
yes('مفيش أي فورم POST في شاشات الوحدة', !$formInViews);

// ═══════════════════════════════════════════ ٢) ممنوع النولون
sec('٢) ⚠️ النولون مستبعَد عن قصد (طلب المالك)');
// ⚠️ الممنوع هو **العمود** `freight` — مش كلمة «نولون» في نص موجَّه للمالك.
//    النص اللي بيقول «النولون مش داخل في التقرير ده» مطلوب عشان ما حدّش
//    يفتكر إن التقرير ناقص، فبنفحص وجوده مش غيابه.
$freightHit = [];
foreach (array_merge([$MODEL, $CTRL], $VIEWS) as $f) {
    if (preg_match('~\bfreight\b~', php_code($f))) { $freightHit[] = basename($f); }
}
yes('مفيش عمود freight في كود الوحدة كله', !$freightHit)
    ?: print('      ' . implode(' · ', array_unique($freightHit)) . "\n");
$idxSrc = (string) file_get_contents($root . '/app/Views/ops/index.php');
$prtSrc = (string) file_get_contents($root . '/app/Views/ops/print.php');
/* ⚠️ اتغيّر الشكل مش المعنى (2026-07-27، قانون التصميم بند ١): الفقرة اللي
   كانت ٣ أسطر بقت **شارة «بلا نولون»** والتفصيل الكامل في `title`.
   الفحص بقى على **المعنى** — إن المالك لسه بيعرف إن النولون مستبعد — مش على
   نصّ بعينه. لو الشارة اتشالت المالك هيفتكر التقرير ناقص. */
yes('الشاشة بتقول للمالك إن النولون مستبعد (شارة)', str_contains($idxSrc, 'بلا نولون'));
yes('  › والتفصيل الكامل في `title` بتاع الشارة',
    (bool) preg_match('~class="pill" title="[^"]*النولون[^"]*"~u', $idxSrc));
yes('والتقرير المطبوع كمان', str_contains($prtSrc, 'النولون مش داخل في التقرير ده'));

// ═══════════════════════════════════════════ ٣) الفترات
sec('٣) الفترات والمقارنة');
$m = OpsReport::period('month', '2026-07-16');
same('الشهر بيبدأ من أول الشهر', $m['from'], '2026-07-01');
same('الشهر بينتهي بآخر يوم فيه', $m['to'], '2026-07-31');
same('الشهر السابق للمقارنة', $m['prev_from'], '2026-06-01');

$w = OpsReport::period('week', '2026-07-16');   // 2026-07-16 = الخميس
same('الأسبوع بيبدأ السبت (نفس تعريف /cars)', $w['from'], '2026-07-11');
same('الأسبوع سبعة أيام', $w['to'], '2026-07-17');
// ⚠️ تفويض `OfficeCar::period` — تعريف واحد للأسبوع والشهر في النظام كله.
$wc = OfficeCar::period('week', '2026-07-16');
yes('الأسبوع مطابق حرفيًّا لتعريف /cars', $w['from'] === $wc['from'] && $w['to'] === $wc['to']);

$d1 = OpsReport::period('day', '2026-07-16');
same('اليوم = يوم واحد', $d1['from'] . '|' . $d1['to'], '2026-07-16|2026-07-16');
$all = OpsReport::period('all');
yes('«كل الفترات» بلا حدود وبلا مقارنة',
    $all['from'] === null && $all['to'] === null && $all['prev_label'] === null);

$c = OpsReport::period('custom', null, '2026-07-05', '2026-07-14');
same('الفترة المخصّصة بتاخد حدودها', $c['from'] . '|' . $c['to'], '2026-07-05|2026-07-14');
same('  › والمقارنة بنفس الطول (١٠ أيام)', $c['prev_from'] . '|' . $c['prev_to'], '2026-06-25|2026-07-04');
$cr = OpsReport::period('custom', null, '2026-07-14', '2026-07-05');
same('التاريخين مقلوبين بيتصلّحوا', $cr['from'] . '|' . $cr['to'], '2026-07-05|2026-07-14');
$cn = OpsReport::period('custom', '2026-07-16', '', '');
// مخصّص بلا حدود مالوش معنى — لازم يرجع للشهر مش يعرض «كل الفترات» بالغلط
same('مخصّص بلا حدود بيرجع للشهر', $cn['kind'], 'month');
$bad = OpsReport::period('حاجة-مش-موجودة', '2026-07-16');
same('نوع فترة مش موجود بيرجع للشهر', $bad['kind'], 'month');

// ═══════════════════════════════════════════ ٤) البذرة والحسابات
sec('٤) الأرقام (على بيانات مزروعة داخل transaction)');
$balanceBefore = Closing::currentBalance();
$pdo = Database::conn();
$pdo->beginTransaction();
$cleanup = static function () use ($pdo): void {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
};
$_SESSION['uid'] = $_SESSION['uid'] ?? 1;
$_SESSION['role'] = 'owner';

try {
    $DAY  = '2026-07-14';
    $DAY2 = '2026-07-15';
    $OUT  = '2026-06-02';   // برّه الفترة

    Database::exec(
        "INSERT INTO permits (permit_type, permit_no, permit_date, company, client, destination,
                              shipping_co, agency, port, containers, container_type, status)
         VALUES ('sader', '__OPS-P1', ?, '__ops شركة أ', '__ops عميل', 'القاهرة',
                 '__ops شحن', '__ops توكيل', '__ops ميناء الشمس', 5, '40 قدم', 'open')",
        [$DAY]);
    $p1 = Database::lastId();

    Database::exec(
        "INSERT INTO permits (permit_type, permit_no, permit_date, company, client, destination,
                              port, agency, containers, status)
         VALUES ('warid', '__OPS-P2', ?, '__ops شركة ب', '__ops عميل ٢', 'طنطا',
                 '__ops ميناء القمر', '__ops توكيل ٢', 3, 'open')",
        [$DAY]);
    $p2 = Database::lastId();

    $bill = static function (array $d): int {
        Database::exec(
            "INSERT INTO bawalis (bill_type, bill_date, bill_number, company, destination,
                                  container_type, container_count, custody, car_no, driver,
                                  declaration_owner, permit_id, is_legacy)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [$d['type'] ?? 'sader', $d['date'], $d['no'], $d['co'], $d['dest'] ?? 'القاهرة',
             $d['ct'] ?? '40 قدم', $d['cc'], $d['cu'], $d['car'] ?? '__ops ع ع ع 1',
             $d['drv'] ?? '__ops سواق', $d['own'] ?? '__ops صاحب بيان',
             $d['pid'], $d['legacy'] ?? 0]);
        return Database::lastId();
    };

    // داخل الفترة: 3 + 2 + 1 = 6 حاويات · عهد 5000 + 3000 + 2000 = 10000
    $bill(['date' => $DAY,  'no' => '__OPS-1', 'co' => '__ops شركة أ', 'cc' => 3, 'cu' => 5000, 'pid' => $p1]);
    $bill(['date' => $DAY,  'no' => '__OPS-2', 'co' => '__ops شركة أ', 'cc' => 2, 'cu' => 3000, 'pid' => $p1,
           'ct' => '1*20']);   // ⚠️ الصيغة القديمة — لازم `container_norm` توحّدها
    $bill(['date' => $DAY2, 'no' => '__OPS-3', 'co' => '__ops شركة ب', 'cc' => 1, 'cu' => 2000, 'pid' => $p2,
           'type' => 'warid', 'dest' => 'طنطا']);
    // برّه الفترة
    $bill(['date' => $OUT,  'no' => '__OPS-OUT', 'co' => '__ops شركة أ', 'cc' => 99, 'cu' => 99000, 'pid' => $p1]);
    // ⚠️ من الأرشيف — لازم تتستبعد تمامًا
    $bill(['date' => $DAY,  'no' => '__OPS-LEG', 'co' => '__ops شركة أ', 'cc' => 500, 'cu' => 500000,
           'pid' => $p1, 'legacy' => 1]);
    // ⚠️ بلا تاريخ — لازم تتستبعد كمان
    Database::exec(
        "INSERT INTO bawalis (bill_type, bill_date, bill_number, company, container_count, custody,
                              car_no, permit_id, is_legacy)
         VALUES ('sader', NULL, '__OPS-NODATE', '__ops شركة أ', 700, 700000, '__ops ع ع ع 1', ?, 0)",
        [$p1]);

    $P = OpsReport::period('custom', null, $DAY, $DAY2);
    $T = OpsReport::totals($P['from'], $P['to']);

    // نطرح أرقام النظام الحقيقية عشان الفحص يشتغل على قاعدة فيها بيانات
    $baseArgs = [$DAY, $DAY2];
    $realC = (int) Database::scalar(
        "SELECT COALESCE(SUM(GREATEST(container_count,0)),0) FROM bawalis
         WHERE is_legacy=0 AND bill_date IS NOT NULL AND bill_date>=? AND bill_date<=?
           AND (bill_number IS NULL OR bill_number NOT LIKE '__OPS%')", $baseArgs);
    $realCu = (float) Database::scalar(
        "SELECT COALESCE(SUM(custody),0) FROM bawalis
         WHERE is_legacy=0 AND bill_date IS NOT NULL AND bill_date>=? AND bill_date<=?
           AND (bill_number IS NULL OR bill_number NOT LIKE '__OPS%')", $baseArgs);

    same('الحاويات = المزروع (٦) + الحقيقي', $T['containers'], 6 + $realC);
    near('العهد = المزروع (١٠٬٠٠٠) + الحقيقي', $T['custody'], 10000 + $realCu);
    yes('⚠️ بوليصة الأرشيف (٥٠٠ حاوية) مستبعدة', $T['containers'] < 500);
    yes('⚠️ البوليصة بلا تاريخ (٧٠٠ حاوية) مستبعدة', $T['containers'] < 700);
    yes('⚠️ البوليصة اللي برّه الفترة (٩٩ حاوية) مستبعدة', $T['containers'] < 99 + 6 + $realC + 1);

    // ⚠️ المخصوم لازم يتحسب بنفس تعبير الخزنة — مش بحساب موازٍ
    [$dedSql, $dedArgs] = Custody::deductionSql('custody', 'car_no');
    $expectDed = (float) Database::scalar(
        "SELECT COALESCE(SUM(CASE WHEN custody > 0 THEN $dedSql ELSE 0 END),0) FROM bawalis
         WHERE is_legacy=0 AND bill_date IS NOT NULL AND bill_date>=? AND bill_date<=?",
        array_merge($dedArgs, $baseArgs));
    near('⚠️ المخصوم من الخزنة = نفس تعبير Custody::deductionSql', $T['deducted'], $expectDed);
    yes('المخصوم أقل من العهدة الكاملة (بيتطرح ١٠٠ لكل بوليصة)', $T['deducted'] < $T['custody']);

    same('الأذونات المزروعة داخلة في العدّ', $T['permits'] >= 2, true);

    sec('٥) ⚠️ مجموع أي تقسيم = الإجمالي فوق (مستحيل يختلفوا)');
    foreach (array_keys(OpsReport::DIMS) as $dim) {
        $rows = OpsReport::breakdown($dim, $P['from'], $P['to']);
        $sumC = array_sum(array_column($rows, 'containers'));
        $sumT = array_sum(array_column($rows, 'trips'));
        $sumU = array_sum(array_column($rows, 'custody'));
        $lbl = OpsReport::DIMS[$dim][0];
        yes("تقسيم «{$lbl}»: حاويات وبوالص وعهد = الإجمالي",
            $sumC === $T['containers'] && $sumT === $T['trips'] && abs($sumU - $T['custody']) < 0.005);
    }
    $mix = OpsReport::containerMix($P['from'], $P['to']);
    same('تصنيف الحاويات: المجموع = الإجمالي', array_sum(array_column($mix, 'containers')), $T['containers']);
    // ⚠️ «1*20» لازم تتوحّد لـ«20 قدم» عبر container_norm (v30)
    $mixNames = array_column($mix, 'name');
    yes('⚠️ «1*20» اتوحّدت لـ«20 قدم» (container_norm)',
        in_array('20 قدم', $mixNames, true) && !in_array('1*20', $mixNames, true));

    $ports = OpsReport::breakdown('port', $P['from'], $P['to']);
    $portNames = array_column($ports, 'name');
    yes('الميناء بيتقرا من الإذن المرتبط', in_array('__ops ميناء الشمس', $portNames, true));
    yes('  › والإذن التاني كمان', in_array('__ops ميناء القمر', $portNames, true));
    $agencies = array_column(OpsReport::breakdown('agency', $P['from'], $P['to']), 'name');
    yes('التوكيل الملاحي بيتقرا من الإذن', in_array('__ops توكيل', $agencies, true));
    $cos = array_column(OpsReport::breakdown('company', $P['from'], $P['to']), 'name');
    yes('الشركة بتتقرا من البوليصة', in_array('__ops شركة أ', $cos, true));

    // ⚠️ مفيش دمج تلقائي لتهجئتين — قرار المالك (تحذير ٣)
    $bill(['date' => $DAY, 'no' => '__OPS-SP', 'co' => '__ops شركة أ ', 'cc' => 0, 'cu' => 0, 'pid' => $p1]);
    $cos2 = array_column(OpsReport::breakdown('company', $P['from'], $P['to']), 'name');
    same('التهجئة بمسافة زايدة بتتقصّ (TRIM) مش بتتقسم', count(array_filter($cos2,
        static fn ($n) => $n === '__ops شركة أ')), 1);

    sec('٦) الاتجاه = مجموع الأيام (مستحيل الرسم يخالف الرقم)');
    $daily = OpsReport::daily($DAY, $DAY2);
    same('daily فيها اليومين', count($daily), 2);
    same('مجموع daily للحاويات = الإجمالي',
        array_sum(array_column($daily, 'containers')), $T['containers']);
    near('مجموع daily للعهد = الإجمالي', array_sum(array_column($daily, 'custody')), $T['custody']);

    $tr = OpsReport::trend('month', 12, $DAY2);
    yes('الاتجاه بيرجّع فترات', count($tr) >= 3);
    $cur = array_values(array_filter($tr, static fn ($x) => !empty($x['on'])));
    yes('الفترة الحالية معلَّمة في الاتجاه', count($cur) === 1);
    $monthT = OpsReport::totals(OpsReport::period('month', $DAY2)['from'],
                                OpsReport::period('month', $DAY2)['to']);
    same('عمود الشهر الحالي = إجمالي الشهر', $cur[0]['containers'] ?? -1, $monthT['containers']);

    sec('٧) رندر فعلي (ببنود مزروعة — مش صفحة فاضية)');
    $ctl  = new \App\Controllers\OpsReportController();
    $grab = static function (callable $fn): string {
        ob_start();
        try { $fn(); } catch (\Throwable $e) { ob_end_clean(); return 'EXC: ' . $e->getMessage(); }
        return (string) ob_get_clean();
    };

    $_GET = ['p' => 'custom', 'from' => $DAY, 'to' => $DAY2];
    $idx = $grab(static fn () => $ctl->index());
    yes('الشاشة بترندر', !str_starts_with($idx, 'EXC') && strlen($idx) > 5000)
        ?: print('      ' . substr($idx, 0, 200) . "\n");
    yes('  › وفيها الرقم البطل (حاويات)', str_contains($idx, 'ops-hero-num'));
    yes('  › وفيها الميناء المزروع في التوزيع', str_contains($idx, '__ops ميناء الشمس'));
    yes('  › وفيها شريط الفلوس بالتلات أرقام', substr_count($idx, 'ops-money-cell') === 3);
    /* ⚠️ الشرح اتنقل من الشاشة **لنافذة التفاصيل** (قانون التصميم: أي معلومة
       زيادة مكانها ورا ضغطة على الرقم). فالفحص بقى: الشاشة **نضيفة من الجملة**،
       والمعلومة **موجودة فعلاً** في سطر «إزاي اتحسب الرقم ده» بتاع `deducted`.
       المعلومة ما ضاعتش — غيّرت مكانها بس. */
    yes('  › والشاشة بقت بلا جملة شرح الفرق', !str_contains($idx, 'بيتطرح ١٠٠ لكل بوليصة'));
    $_GET = ['p' => 'custom', 'from' => $DAY, 'to' => $DAY2];
    $dedDrill = $grab(static fn () => $ctl->drill('deducted'));
    yes('  › والشرح موجود في نافذة «المخصوم من الخزنة»',
        str_contains($dedDrill, 'أقل بـ١٠٠ لكل بوليصة'));
    yes('  › وفيها شرايح الفترة الخمسة', substr_count($idx, 'cars-pchip') >= 5);
    yes('  › وفيها فورم الفترة المخصّصة', str_contains($idx, 'ops-range'));
    yes('  › ومفيش أي شبكة grid-template-columns inline',
        !preg_match('~style="[^"]*grid-template-columns~', $idx));
    yes('  › والبوليصة المُرحَّلة مش ظاهرة', !str_contains($idx, '__OPS-LEG'));
    yes('  › والبوليصة بلا تاريخ مش ظاهرة', !str_contains($idx, '__OPS-NODATE'));

    sec('٧ب) ⚠️ إعادة التصميم: صفر تكرار (قرار المالك)');
    // ⚠️ اللي اتشال ولا يرجع — كل واحد فيهم كان بيقول حاجة معروضة أصلاً.
    yes('مفيش لوح «قراءة سريعة» (كان بيعيد صياغة نفس الأرقام)',
        !str_contains($idx, 'قراءة سريعة') && !str_contains($idx, 'ins-list'));
    yes('مفيش قايمة بوالص في الشاشة (كل رقم بيدرِّل عليها)',
        !str_contains($idx, '__OPS-1') && !str_contains($idx, '__OPS-3'));
    same('لوح توزيع **واحد** بمبدّل عدسة (كانوا ٦ لوحات متطابقة)',
        substr_count($idx, 'ops-seg'), 1);
    same('مخطط اتجاه واحد (كانوا اتنين)', substr_count($idx, 'mb-chart'), 1);

    // ⚠️ ممنوع رقم أصمّ — الشاشة بقت بماركب خاص، فالفحص على العناصر الجديدة.
    foreach (['ops-chip', 'ops-money-cell', 'ops-rank-row'] as $cls) {
        $n = substr_count($idx, 'class="' . $cls);
        $withDrill = preg_match_all('~class="' . preg_quote($cls, '~') . '[^"]*"[^>]*data-drill=~', $idx);
        yes("كل «{$cls}» ليه نافذة تفاصيل (ممنوع رقم أصمّ)", $n > 0 && $withDrill === $n)
            ?: print("      عناصر={$n} · بتفاصيل={$withDrill}\n");
    }
    // الصف بيفتح **بنوده هو** مش نفس القايمة تاني
    yes('صف التوزيع بيدرِّل على قيمته هو (`rows&dim=&v=`)',
        (bool) preg_match('~ops/drill/rows\?[^"]*dim=~', $idx));
    // ⚠️ زرار «كل الصفوف» بيفتح **نفس القايمة** لو مفيش صفوف مقطوعة — تكرار.
    // (الشريحة في الترويسة بتدرِّل على نفس المسار — الفحص على زرار اللوح بس)
    yes('زرار «كل الصفوف» مختفي لما كل الصفوف ظاهرة',
        !preg_match('~class="btn ghost sm" data-drill="[^"]*ops/drill/port~', $idx));

    sec('٧ج) مبدّل العدسة');
    foreach (array_keys(OpsReport::dimTabs()) as $k) {
        $_GET = ['p' => 'custom', 'from' => $DAY, 'to' => $DAY2, 'dim' => $k];
        $h = $grab(static fn () => $ctl->index());
        yes("عدسة «{$k}» بترندر وفيها صفوف",
            !str_starts_with($h, 'EXC') && str_contains($h, 'ops-rank-row'))
            ?: print('      ' . substr($h, 0, 160) . "\n");
    }
    $_GET = ['p' => 'custom', 'from' => $DAY, 'to' => $DAY2, 'dim' => 'حاجة-غلط'];
    $h = $grab(static fn () => $ctl->index());
    yes('عدسة مش موجودة بترجع للموانئ بدل ما تكسر',
        !str_starts_with($h, 'EXC') && str_contains($h, '__ops ميناء الشمس'));
    // ⚠️ تغيير الفترة لازم يحافظ على العدسة وإلا بترجع لأول تبويب كل مرة
    $_GET = ['p' => 'custom', 'from' => $DAY, 'to' => $DAY2, 'dim' => 'driver'];
    $h = $grab(static fn () => $ctl->index());
    yes('شرايح الفترة بتحافظ على العدسة المختارة',
        (bool) preg_match('~ops\?[^"]*dim=driver[^"]*p=day|ops\?[^"]*p=day[^"]*dim=driver~', $h));

    sec('٧د) «محتاج انتباهك» — بنود تصرّف مش أرقام مكرّرة');
    $_GET = ['p' => 'custom', 'from' => $DAY, 'to' => $DAY2];
    $idx = $grab(static fn () => $ctl->index());
    yes('اللوح موجود', str_contains($idx, 'محتاج انتباهك'));
    // الإذن المزروع فيه ٥ حاويات واتحرّك منها ٥ → مش ناقص؛ نزرع إذن بلا حركة
    Database::exec(
        "INSERT INTO permits (permit_type, permit_no, permit_date, company, client, containers, status)
         VALUES ('sader', '__OPS-EMPTY', ?, '__ops شركة أ', '__ops عميل', 9, 'open')", [$DAY]);
    $att = OpsReport::attention($DAY, $DAY2);
    $ids = array_column($att['partial'], 'permit_no');
    // ⚠️ الإذن اللي **مافيهوش ولا حركة** لازم يظهر — أول نسخة كانت بتشوف
    //    اللي اتحرّك بس فكان بيختفي، وهو أهم بند (متحقَّق: إذن #70 بـ١٨ حاوية).
    yes('⚠️ إذن مفتوح بلا أي حركة بيظهر في «محتاج انتباهك»',
        in_array('__OPS-EMPTY', $ids, true));
    $row = null;
    foreach ($att['partial'] as $r) { if ($r['permit_no'] === '__OPS-EMPTY') { $row = $r; } }
    same('  › والباقي = ٩ من ٩', ($row['left'] ?? 0) . '/' . ($row['total'] ?? 0), '9/9');
    yes('  › والبيانات الناقصة معدودة', is_array($att['gaps']) && count($att['gaps']) === 4);

    sec('٧هـ) ⚠️ حجم الصفحة (حارس ارتداد)');
    // النسخة الأولى كانت **٦٢٣ كيلوبايت** لأنها بترسم ٤٠٠ صف × ٨ أيقونات SVG.
    // الحد ده بيمنع رجوع أي قايمة تقيلة للشاشة بلا سقف عرض.
    $kb = strlen($idx) / 1024;
    yes('الشاشة أقل من ١٥٠ كيلوبايت (كانت ٦٢٣)', $kb < 150)
        ?: print('      الحجم الحالي: ' . round($kb) . ' كيلوبايت\n');

    sec('٧و) المعدلات المشتقّة');
    $dailyT = OpsReport::daily($DAY, $DAY2);
    $rt = OpsReport::rates($T, $dailyT);
    same('أيام الشغل = أيام فيها حركة', $rt['work_days'], count($dailyT));
    near('عهدة لكل حاوية = العهد ÷ الحاويات', $rt['per_container'],
        round($T['custody'] / max(1, $T['containers']), 2));
    near('متوسط الحاويات في البوليصة', $rt['per_trip'],
        round($T['containers'] / max(1, $T['trips']), 2));
    // ⚠️ المقام صفر = «مفيش بيانات» مش صفر
    $zero = OpsReport::rates(['containers' => 0, 'trips' => 0, 'permits' => 0, 'custody' => 0], []);
    yes('⚠️ المعدل بلا بيانات بيرجّع null مش صفر',
        $zero['per_day'] === null && $zero['per_trip'] === null && $zero['per_container'] === null);

    sec('٨) كل نوافذ التفاصيل بترندر وبنودها فيها');
    $drills = array_merge(
        ['containers', 'trips', 'permits', 'custody', 'deducted', 'mix'],
        array_keys(OpsReport::DIMS));
    // `rows` بتاخد `dim`+`v` فبتتفحص لوحدها تحت
    foreach ($drills as $wt) {
        $_GET = ['p' => 'custom', 'from' => $DAY, 'to' => $DAY2];
        $h = $grab(static fn () => $ctl->drill($wt));
        // ⚠️ مصيدة موثَّقة (v38/v49/…): `«$wt»` بتدّي «Undefined variable $wt»»
        //    لأن بايتات `»` بتتحسب جزءًا من اسم المتغيّر. لازم `{$wt}`.
        yes("نافذة «{$wt}» بترندر وفيها بنود",
            !str_starts_with($h, 'EXC') && str_contains($h, 'drill-foot') && str_contains($h, 'd-row'))
            ?: print('      ' . substr($h, 0, 160) . "\n");
    }

    // نافذة صف واحد من التوزيع — البند بيفتح **بنوده هو**
    foreach ([['port', '__ops ميناء الشمس', 2], ['mix', '20 قدم', 1],
              ['company', '__ops شركة ب', 1]] as [$sd, $sv, $minRows]) {
        $_GET = ['p' => 'custom', 'from' => $DAY, 'to' => $DAY2, 'dim' => $sd, 'v' => $sv];
        $h = $grab(static fn () => $ctl->drill('rows'));
        $n = substr_count($h, '<a class="d-row');
        yes("نافذة صف «{$sd} = {$sv}» فيها بنودها هي بس", $n >= $minRows
            && !str_starts_with($h, 'EXC') && str_contains($h, 'drill-foot'))
            ?: print("      صفوف={$n}\n");
    }
    // القيمة الفاضية لازم تشتغل كمان (ثابت UNSET_LABEL في الاتجاهين)
    $_GET = ['p' => 'custom', 'from' => $DAY, 'to' => $DAY2,
             'dim' => 'shipping_co', 'v' => OpsReport::UNSET_LABEL];
    $h = $grab(static fn () => $ctl->drill('rows'));
    yes('⚠️ نافذة «(غير محدَّد)» بترجّع بنود (ثابت UNSET_LABEL متطابق)',
        substr_count($h, '<a class="d-row') > 0);

    // ⚠️ **ممنوع ننادي `drill()` بنوع غلط من هنا**: بترمي على `notFound()` اللي
    //    بتعمل `exit`، فبتقتل باقي الفحوصات **بلا أي رسالة** (مصيدة موثَّقة).
    //    الفحص على القايمة البيضا نفسها + وجود الحارس قبل أي قراءة.
    $guard = preg_match(
        '~isset\(OpsReport::DIMS\[\$what\]\).*?notFound~s', $ctrlCode);
    yes('نوع تفاصيل مش في القايمة البيضا بيترفض (حارس قبل أي قراءة)', (bool) $guard);
    $r = new \ReflectionClass($ctl);
    $wl = $r->getConstant('DRILL_STATS');
    // البُعد اللي بيجي في `?dim=` بيتفحص على `dimTabs()` — الفورم مش مصدر ثقة
    yes('  › وبُعد نافذة الصف بيتفحص على قايمة بيضا',
        (bool) preg_match('~isset\(OpsReport::dimTabs\(\)\[\$sd\]\).*?notFound~s', $ctrlCode));
    yes('  › القايمة البيضا ثابتة في الكنترولر مش جاية من الرابط', is_array($wl) && $wl);
    yes('  › وكل أبعاد DIMS ليها نافذة',
        count(array_diff(array_keys(OpsReport::DIMS), $drills)) === 0);

    sec('٩) تقرير الطباعة A4');
    $_GET = ['p' => 'custom', 'from' => $DAY, 'to' => $DAY2];
    $pr = $grab(static fn () => $ctl->printable());
    yes('التقرير بيرندر', !str_starts_with($pr, 'EXC') && str_contains($pr, 'تقرير التشغيل'));
    yes('  › وبيستخدم توكنز الطباعة المشتركة', str_contains($pr, '--navy:#15355B'));
    yes('  › وفيه جدول الموانئ', str_contains($pr, '__ops ميناء الشمس'));
    yes('  › وفيه تصنيف الحاويات', str_contains($pr, 'تصنيف الحاويات'));
    yes('  › والنسخة الملخّصة بلا جدول البوالص', !str_contains($pr, 'كل البوالص بالتفصيل'));

    $_GET = ['p' => 'custom', 'from' => $DAY, 'to' => $DAY2, 'detail' => '1'];
    $prd = $grab(static fn () => $ctl->printable());
    yes('النسخة المفصّلة فيها جدول كل البوالص', str_contains($prd, 'كل البوالص بالتفصيل'));
    yes('  › وفيها البوليصة المزروعة', str_contains($prd, '__OPS-1'));

    $_GET = [];
} finally {
    $cleanup();
}

sec('١٠) ⚠️ العزل المالي');
near('رصيد خزنة المكتب قبل = بعد', Closing::currentBalance(), $balanceBefore);
yes('البوالص المزروعة اتشالت بالـrollback', !Database::scalar(
    "SELECT COUNT(*) FROM bawalis WHERE bill_number LIKE '__OPS%'"));
yes('الأذونات المزروعة اتشالت بالـrollback', !Database::scalar(
    "SELECT COUNT(*) FROM permits WHERE permit_no LIKE '__OPS-P%'"));

// الوحدة ما بتعرفش أي جدول مالي غير قراءة البوالص/الأذونات
foreach (['manual_expenses', 'manual_custody', 'daily_closings', 'permit_stages',
          'rep_custody_deposits', 'visa_cards', 'permit_invoices'] as $tbl) {
    if (str_contains($modelCode, $tbl)) { bad("الموديل بيلمس جدول {$tbl}"); }
}
ok('الموديل ما بيلمسش أي جدول خزنة/تقفيل/عهدة/فوترة');

// ═══════════════════════════════════════════ ١١) الصلاحيات والتكامل
sec('١١) الصلاحيات والتكامل مع النظام');
foreach (['owner', 'manager', 'accountant', 'operator', 'read_only'] as $role) {
    yes("الدور «{$role}» يقدر يفتح التقرير (قراءة)",
        Access::allows($role, 'OpsReportController@index', 'GET'));
}
yes('ومحدش يقدر يكتب (مفيش POST أصلاً)',
    !Access::allows('read_only', 'OpsReportController@index', 'POST'));

$layout = (string) file_get_contents($root . '/app/Views/layout.php');
/* ⚠️ القائمة اتنقلت لـ`NavModule::DEFAULTS` في v77 — الفحص على وجود البند
   في القائمة، مش على نص في `layout.php`. */
yes('بند القائمة الجانبية موجود',
    str_contains(file_get_contents(dirname(__DIR__) . '/app/Models/NavModule.php'), "'OpsReportController@index'"));
yes('  › ومرادفات البحث (Ctrl+K) موجودة', str_contains($layout, "'ops'     =>"));

// ⚠️ أي أيقونة مش في `icon()` بتقع على شكل افتراضي **بالسكوت**.
$helpers = (string) file_get_contents($root . '/app/Core/helpers.php');
$icons = array_unique(array_column(OpsReport::DIMS, 2));
$icons = array_merge($icons, ['package', 'truck', 'folder', 'wallet', 'briefcase', 'calendar',
                              'sparkles', 'layers', 'info', 'search', 'file-text', 'download',
                              'trending-up', 'trending-down']);
$missing = array_values(array_filter($icons,
    static fn ($i) => !str_contains($helpers, "'" . $i . "'") && !preg_match("~'" . preg_quote($i, '~') . "'\s*=>~", $helpers)));
yes('كل أيقونات الوحدة معرَّفة في icon()', !$missing)
    ?: print('      ناقصة: ' . implode(' · ', $missing) . "\n");

// ⚠️ قاعدة أمنية قايمة: ممنوع json_encode في app/Views (الصح js_json)
$jsonHit = array_values(array_filter($VIEWS,
    static fn ($v) => str_contains(php_code($v), 'json_encode')));
yes('مفيش json_encode في شاشات الوحدة (القاعدة الأمنية)', !$jsonHit);

// الكلاسات المشتركة لازم تعيش في app.css مش في <style> محلي
$css = (string) file_get_contents($root . '/public/assets/css/app.css');
foreach (['ops-range', 'cars-pchip', 'ins-row', 'grid-2', 'd-kpis'] as $cls) {
    yes("الكلاس .{$cls} معرَّف في app.css", str_contains($css, '.' . $cls));
}

$cat = (string) file_get_contents($root . '/bin/run_verifiers.php');
yes('الفاحص متسجَّل في كتالوج run_verifiers', str_contains($cat, 'verify_ops_report.php'));

// ═══════════════════════════════════════════ النتيجة
echo "\n" . str_repeat('=', 62) . "\n";
echo "نجح: $pass   ·   فشل: $fail\n";
exit($fail > 0 ? 1 : 0);
