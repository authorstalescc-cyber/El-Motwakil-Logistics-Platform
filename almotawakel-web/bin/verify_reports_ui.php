<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص المرحلة ٢ — التقارير بلغة النظام (2026-07-27).
 *
 * `/reports` كانت **أسوأ شاشة في النظام** قبل الشغل ده، بالأرقام المقيسة:
 *   · **٥ بطاقات `.kpi` أرقام صمّاء** بلا أي طريق للتفاصيل — أوضح مخالفة
 *     لقاعدة المالك «ممنوع رقم أصمّ».
 *   · **٦ تبويبات بتتبدّل بالجافاسكريبت** والعنوان ما بيتغيّرش، فالرجوع
 *     بالمتصفح كان بيطلّعك من الصفحة والرابط مش قابل للمشاركة.
 *   · **٤ رسوم Chart.js** = ٢٠٠KB بتتحمّل مع كل فتحة (المكتبة اترفعت من
 *     الداشبورد في المرحلة ٤ وفضلت هنا).
 *   · **٨ جداول خام** و**٣١ `style=` inline**.
 *   · والكنترولر كان بيشغّل **استعلامات الستة تبويبات** مع كل فتحة.
 *
 * ⚠️ **الفحص بيرندر الشاشة فعليًّا ببيانات حقيقية** — بيمسك الفشل الصامت في
 *    `Controller::render` (مصيدة `extract($data)` الموثَّقة) واللي فحص النص
 *    وحده ما بيشوفهوش.
 *
 * قراءة فقط بالكامل — ما بيكتبش أي حاجة في القاعدة.
 * التشغيل:  php bin/verify_reports_ui.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\ReportsController;
use App\Models\Custody;
use App\Models\Metrics;

$pass = 0; $failures = [];
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $failures; $failures[] = $m; echo "  ✗ $m\n"; }
function chk(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function sec(string $m): void { echo "\n$m\n" . str_repeat('─', 66) . "\n"; }
function php_code(string $s): string {
    $s = preg_replace('~/\*.*?\*/~s', '', $s) ?? $s;
    return preg_replace('~^\s*(//|\*|#).*$~m', '', $s) ?? $s;
}

echo "فحص المرحلة ٢ — التقارير\n" . str_repeat('=', 66) . "\n";

$root = dirname(__DIR__);
$idx  = (string) file_get_contents($root . '/app/Views/reports/index.php');
$cli  = (string) file_get_contents($root . '/app/Views/reports/client.php');
$ctl  = (string) file_get_contents($root . '/app/Controllers/ReportsController.php');
$css  = (string) file_get_contents($root . '/public/assets/css/app.css');
$routes = (string) file_get_contents($root . '/public/index.php');

$balBefore = (float) Custody::balance();

/* جلسة مكتب للرندر — الفحص ما بيكتبش، بس الكنترولر بيطلب تسجيل دخول. */
$_SESSION['uid'] = 1; $_SESSION['uname'] = 'فحص'; $_SESSION['role'] = 'owner';
$_SESSION['perms'] = []; $_SESSION['work_date'] = date('Y-m-d');
$_SESSION['work_date_set'] = date('Y-m-d');

/** بيرندر أكشن وبيرجّع الـHTML، وبيقلب أي استثناء لنص. */
$grab = static function (callable $fn): string {
    ob_start();
    try { $fn(); return (string) ob_get_clean(); }
    catch (\Throwable $e) { ob_end_clean(); return 'EXC:' . $e->getMessage(); }
};
$clean = static fn (string $h): bool =>
    !str_starts_with($h, 'EXC:')
    && !preg_match('/Fatal error|Warning:|Undefined variable|Undefined array key/', $h);

// ═══════════════════════════════════════════════════════════════════════
sec('① صفر رقم أصمّ — البطاقات الخمسة بقت `stat` بتفتح تفاصيلها');

chk(!preg_match('~class="kpi~', php_code($idx)), '`/reports` مفيهاش ولا `.kpi` قديمة');
chk(!preg_match('~class="kpi~', php_code($cli)), 'وبروفايل العميل كمان');
chk(!str_contains($idx, 'kpi-grid') && !str_contains($cli, 'kpi-grid'), 'ولا شبكة `.kpi-grid` القديمة');

/* ⚠️ `stat_grid` بياخد **عدد أعمدة رقمًا** و`body` مُصيِّرًا. لو حد بعت مصفوفة
   بطاقات، `(int)` بتاعها = 1 والشبكة بترندر **فاضية بالسكوت** — اتصادف فعليًا. */
chk(!preg_match("~partial\('stat_grid',\s*\['items'\s*=>\s*\[~", $idx . $cli),
    "⚠️ ومفيش `stat_grid` بياخد **مصفوفة** في `items` (بترندر فاضية بالسكوت)");

// ═══════════════════════════════════════════════════════════════════════
sec('② التبويبات روابط حقيقية — والكنترولر بيحمّل المعروض بس');

chk(str_contains($ctl, "private const TABS"), 'قائمة التبويبات معرَّفة في الكنترولر');
chk(preg_match("~partial\('tabs'~", $idx) === 1, 'والشاشة بتستخدم مكوّن `tabs` (روابط `<a href>`)');
chk(!preg_match('~\.tab-panel|data-panel=~', $idx), '⚠️ ومفيش أي لوح بيتبدّل بالجافاسكريبت (`.tab-panel`)');
chk(!preg_match('~activateTab|querySelectorAll\(.\.tab.\)~', $idx), 'ومفيش سكربت تبديل تبويبات');
chk(str_contains($ctl, '$want(') && substr_count($ctl, "if (\$want(") >= 5,
    '⚠️ وكل استعلام تبويب محكوم بـ`$want()` — كانوا الستة بيتشغّلوا مع كل فتحة');

// ═══════════════════════════════════════════════════════════════════════
sec('③ Chart.js اتشالت من التقارير خالص');

foreach (['reports/index.php' => $idx, 'reports/client.php' => $cli,
          'reports/print.php' => (string) file_get_contents($root . '/app/Views/reports/print.php')] as $f => $src) {
    chk(!str_contains($src, 'chart.umd') && !str_contains($src, 'new Chart(') && !str_contains($src, '<canvas'),
        "`{$f}` بلا Chart.js ولا canvas");
}
chk(str_contains($idx, "partial('mini_bars'"), 'والرسوم بقت `mini_bars` (SVG/CSS على الخادم)');

// ═══════════════════════════════════════════════════════════════════════
sec('④ صفر `style=` inline (كانوا ٣١ + ١٤)');

foreach (['reports/index.php' => $idx, 'reports/client.php' => $cli] as $f => $src) {
    $n = substr_count($src, 'style="');
    chk($n === 0, "`{$f}` مفيهاش `style=` inline (لقينا {$n})");
}
/* ⚠️ أي كلاس بتطبعه الشاشة **لازم** يكون له CSS، وإلا بيقع على الشكل
   الافتراضي بالسكوت (مصيدة `.table-mobile-scroll` الموثَّقة). */
foreach (['mt', 'card.pad', 'cprof-hero', 'cprof-id', 'cprof-due'] as $cls) {
    chk(str_contains($css, '.' . $cls), "الكلاس `.{$cls}` معرَّف في app.css");
}
chk(preg_match('~\.data td\.strong~', $css) === 1 && preg_match('~\.data td\.start~', $css) === 1,
    'و`td.strong` و`td.start` كمان');

// ═══════════════════════════════════════════════════════════════════════
sec('⑤ مسار التفاصيل — قائمة بيضا ومسجَّل في الراوتر');

chk(str_contains($routes, "\$r->get('/reports/drill/{what}'"), 'المسار `GET /reports/drill/{what}` موجود');
chk(str_contains($ctl, 'private const DRILL'), '⚠️ وفيه **قائمة بيضا** — ممنوع مسار تفاصيل عام');
chk(str_contains($ctl, 'notFound('), 'والنوع غير المعروف بيرجّع 404 برسالة');
chk(str_contains($ctl, '$this->requireAuth()'), 'و`requireAuth` على المسار');

// ═══════════════════════════════════════════════════════════════════════
sec('⑥ الرندر الفعلي — كل تبويب وكل نافذة تفاصيل');

$c = new ReportsController();
foreach (['overview', 'customers', 'payments', 'expenses', 'movements', 'permits'] as $t) {
    $_GET = ['tab' => $t];
    $h = $grab(static fn () => $c->index());
    chk($clean($h) && strlen($h) > 3000, "تبويب `{$t}` بيرندر (" . number_format(strlen($h)) . ' بايت)'
        . ($clean($h) ? '' : ' — ' . substr($h, 0, 90)));
}
foreach (['freight', 'custody', 'expenses', 'collections', 'outstanding'] as $d) {
    $_GET = [];
    $h = $grab(static fn () => $c->drill($d));
    chk($clean($h), "نافذة `{$d}` بترندر" . ($clean($h) ? '' : ' — ' . substr($h, 0, 90)));
}

/* الأرقام لازم تترسم فعلاً — «الشاشة رندرت» مش كفاية لو الشبكة طلعت فاضية. */
$_GET = ['tab' => 'overview'];
$ov = $grab(static fn () => $c->index());
chk(substr_count($ov, 'class="d-kpi ') === 5, '⚠️ وتبويب «نظرة عامة» فيه **٥ بطاقات مرسومة فعلاً** — لقينا '
    . substr_count($ov, 'class="d-kpi '));
chk(substr_count($ov, 'data-drill=') >= 5, 'وكل واحدة معاها `data-drill`');

$_GET = [];
$h = $grab(static fn () => $c->printable());
chk($clean($h), 'وصفحة الطباعة بترندر' . ($clean($h) ? '' : ' — ' . substr($h, 0, 90)));

$rows = Metrics::customerProfit(1, null, null);
if ($rows) {
    $_GET = ['name' => $rows[0]['name']];
    $h = $grab(static fn () => $c->client());
    chk($clean($h) && substr_count($h, 'class="d-kpi ') === 5,
        'وبروفايل العميل بيرندر بـ٥ بطاقات (' . substr_count($h, 'class="d-kpi ') . ')');
    chk(str_contains($h, 'id="cpBills"') && str_contains($h, 'id="cpPays"'),
        'ومحتوى نافذتَيه جاهز في الصفحة');
} else {
    echo "  ⓘ مفيش عملاء في القاعدة — فحص البروفايل اتخطّى\n";
}

// ═══════════════════════════════════════════════════════════════════════
sec('⑦ ⚠️ التفاصيل بتطابق الرقم اللي فتحها');

/* ⚠️ **باج اتصاد فعليًّا:** كارت المصروفات بيقرا `totals['expenses']` اللي
   بيجمع كل المصادر، بينما `expenseReport()['categories']` فيها بنود الحركات
   بس — فالكارت كان بيقول ٥١٣٬٣٥٠ والنافذة تفتح **فاضية**. الرقم اللي تفاصيله
   ما بتفسّرهوش أسوأ من رقم بلا تفاصيل. */
$m = new ReflectionMethod(ReportsController::class, 'expenseRows');
$rowsE = $m->invoke($c, null, null);
$t = Metrics::totals(null, null);
$sumE = array_sum(array_column($rowsE, 'value'));
chk(abs($sumE - (float) $t['expenses']) < 0.01,
    '⚠️ مجموع نافذة المصروفات = رقم الكارت بالمليم ('
    . number_format($sumE, 2) . ' / ' . number_format((float) $t['expenses'], 2) . ')');

$sumF = array_sum(array_column(Metrics::byCompany(500, null, null), 'freight'));
chk(abs($sumF - (float) $t['freight']) < 0.01,
    'ومجموع نافذة النولون = رقم الكارت (' . number_format($sumF, 2) . ')');

// ═══════════════════════════════════════════════════════════════════════
sec('⑧ العزل — الشاشة عرض بحت');

foreach (['reports/index.php' => $idx, 'reports/client.php' => $cli] as $f => $src) {
    chk(!preg_match('~\b(INSERT|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b~i', php_code($src)),
        "`{$f}` مفيهاش أي استعلام كتابة");
}
chk(!preg_match('~\b(INSERT|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b~i', php_code($ctl)),
    'والكنترولر كمان — التقارير قراءة فقط');

$balAfter = (float) Custody::balance();
chk(abs($balBefore - $balAfter) < 0.001,
    'ورصيد خزنة المكتب قبل = بعد (' . number_format($balBefore, 2) . ') — الحد الأحمر');

// ═══════════════════════════════════════════════════════════════════════
echo "\n" . str_repeat('=', 66) . "\n";
echo 'نجح: ' . $pass . '   |   فشل: ' . count($failures) . "\n";
if ($failures) {
    echo "\n✗ الفحص فشل — الكود ده ما يترفعش.\n";
    foreach ($failures as $f) { echo "   · $f\n"; }
    exit(1);
}
echo "\n✓ كل الفحوصات عدّت.\n";
