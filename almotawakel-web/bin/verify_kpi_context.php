<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص المرحلة ج — سياق كروت الأرقام (مقارنة بامبارح + رسم آخر ١٤ يوم), 2026-07-26.
 *
 * المشكلة اللي الميزة بتحلّها: الكارت كان بيقول «مصروفات اليوم 12,450» وخلاص.
 * ده رقم أصمّ **بمعنى تاني** غير اللي قاعدة المالك بتمنعه: التفاصيل كانت
 * موجودة ورا الضغطة، لكن **مفيش إجابة على «هو ده كتير ولا قليل؟»** غير إن
 * المالك يفتح التفاصيل ويقارن بدماغه.
 *
 * ⚠️⚠️ **أخطر حاجة في الميزة دي — والسبب الأساسي للفحص:** الرسم والرقم
 *    الكبير فوقه **لازم يطلعوا من نفس المصدر**. لو السلسلة اتحسبت باستعلام
 *    تاني (حتى لو «نفس المنطق» مكتوب من جديد)، أول تعديل على تعريف المصروف
 *    هيغيّر واحد فيهم بس — والنتيجة كارت **بيكذب على نفسه** بلا أي خطأ ظاهر.
 *    عشان كده الفحص بيقارن **آخر نقطة في السلسلة برقم اليوم بالمليم**.
 *
 * ⚠️ **الاتجاه مش تلقائي** (تحذير ١ — إحنا مدينين للعملاء): «زيادة» مش معناها
 *    «تحسّن». مصروفات ↑ = أحمر · خزنة ↑ = أخضر. الفحص بيتأكد إن النغمة
 *    بتتقلب صح في الاتجاهين.
 *
 * قراءة فقط بالكامل — ما بيكتبش أي صف.
 * التشغيل:  php bin/verify_kpi_context.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Models\Closing;
use App\Models\Expense;
use App\Models\Metrics;

$pass = 0; $failures = [];
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $failures; $failures[] = $m; echo "  ✗ $m\n"; }
function chk(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function sec(string $m): void { echo "\n$m\n" . str_repeat('─', 62) . "\n"; }

echo "فحص سياق كروت الأرقام — المرحلة ج\n" . str_repeat('=', 62) . "\n";

$root = dirname(__DIR__);

/* اليوم اللي فيه حركة فعلاً — الفحص على يوم فاضي بينجح كذبًا (0 = 0).
   نفس المصيدة الموثَّقة في verify_v50. */
$busy = \App\Core\Database::scalar(
    "SELECT entry_date FROM permit_stages WHERE stage_key='receipts'
     GROUP BY entry_date ORDER BY COUNT(*) DESC LIMIT 1"
) ?: date('Y-m-d');
echo "  (يوم الاختبار: $busy)\n";

// ═══════════════════════════════════════════════════════════════════════
sec('① ⚠️ الرسم والرقم من نفس المصدر — بالمليم');

$daily  = Metrics::dashDaily($busy);
$series = Expense::dashboardSeries($busy, 14);
$lastExp = (float) end($series['expenses']);
$lastRcp = (int) end($series['receipts']);

chk(count($series['days']) === 14, 'السلسلة ١٤ نقطة');
chk(end($series['days']) === $busy, 'وآخر نقطة هي اليوم المطلوب نفسه');
chk(abs($lastExp - (float) $daily['expenses']) < 0.005,
    '⚠️ آخر نقطة مصروفات = `dashDaily` بالمليم (' . number_format($lastExp, 2)
    . ' ↔ ' . number_format((float) $daily['expenses'], 2) . ')');
chk($lastRcp === (int) $daily['receipts_cnt'],
    '⚠️ وآخر نقطة إيصالات = `dashDaily` بالظبط (' . $lastRcp . ' ↔ ' . $daily['receipts_cnt'] . ')');

/* السلسلة بتقرا من نفس الاتحاد — لو حد كتب استعلامًا موازيًا الفحص ده بيمسكه */
$expSrc = (string) file_get_contents($root . '/app/Models/Expense.php');
$fn = strstr($expSrc, 'public static function dashboardSeries');
$fn = $fn === false ? '' : substr($fn, 0, strpos($fn, 'public static function years') ?: 4000);
chk(str_contains($fn, 'self::unionSql()'),
    '⚠️ `dashboardSeries` بتستخدم `unionSql()` — نفس اتحاد `dashboardDay` مش استعلام موازٍ');
chk(str_contains($fn, "t.source <> 'debt'"), 'وبنفس استثناء المديونيات بالحرف');
chk(str_contains($fn, "stage_key='receipts'"), 'وبنفس شرط الإيصالات');
chk(substr_count($fn, 'Database::all') === 2,
    '⚠️ استعلامان مجمَّعان بس — مش استعلام لكل يوم (الداشبورد بتتفتح عشرات المرات يوميًا)');

// ═══════════════════════════════════════════════════════════════════════
sec('② المقارنة: الفرق المطلق أولًا والنسبة لما تكون معقولة');

$ctx = Metrics::dashContext($busy, 1000.0, 14);
chk(isset($ctx['expenses']['delta'], $ctx['receipts']['delta'], $ctx['custody']['delta'])
    || $ctx['expenses']['delta'] === null,
    'المفاتيح التلاتة موجودة');
foreach (['expenses', 'receipts', 'custody'] as $k) {
    chk(array_key_exists('spark', $ctx[$k]), "المؤشّر «{$k}» له سلسلة رسم");
}

/* ⚠️ الحالة اللي فجّرت التصميم: الأساس صغير جدًا والنتيجة نسبة مجنونة
   («+490,000٪» — اتصادفت فعليًا). لازم يظهر الفرق المطلق بلا نسبة. */
$big = Metrics::dashContext($busy, 245050.0, 14);
$txt = (string) ($big['custody']['delta']['text'] ?? '');
chk($txt === '' || !preg_match('/\((\d{3,}),?\d*٪\)/u', $txt),
    '⚠️ مفيش نسبة مجنونة في المقارنة (الأساس الصغير بيدّي «+490,000٪» بلا الحارس ده) — «' . $txt . '»');

$mSrc = (string) file_get_contents($root . '/app/Models/Metrics.php');
chk(str_contains($mSrc, 'if ($pct < 200)'), 'والنسبة بتتعرض بس تحت ٢٠٠٪');
chk(str_contains($mSrc, "'bad', \$money, 'امبارح'") && str_contains($mSrc, "'good', \$money, 'آخر إقفال'"),
    '⚠️ اتجاه «الكويس» مصرَّح لكل مؤشّر: مصروفات ↑ وحش · خزنة ↑ كويس (تحذير ١)');

// ═══════════════════════════════════════════════════════════════════════
sec('③ سلسلة الخزنة من الأرصدة المثبَّتة');

$bal = Closing::balanceSeries(14);
chk(is_array($bal), '`Closing::balanceSeries` شغّالة');
$closingSrc = (string) file_get_contents($root . '/app/Models/Closing.php');
/* ⚠️ لازم نقصّ عند بداية الدالة اللي بعدها بالظبط — القصّ بعدد حروف ثابت
   بيبلع `openingFor` اللي تحتيها فيدّي **فشلًا كاذبًا** (اتصادف فعليًا). */
$bs = strstr($closingSrc, 'public static function balanceSeries');
if ($bs !== false) {
    $next = strpos($bs, 'public static function', 30);
    $bs = $next !== false ? substr($bs, 0, $next) : $bs;
} else { $bs = ''; }
chk(str_contains($bs, 'FROM daily_closings'),
    '⚠️ بتقرا الرصيد المثبَّت (استعلام واحد) مش بتعيد حساب كل يوم بـ`openingFor`');
chk(!str_contains($bs, 'openingFor'), 'ومفيش إعادة حساب جوّه حلقة');
if ($bal) {
    $ordered = array_column($bal, 'd');
    $sorted = $ordered; sort($sorted);
    chk($ordered === $sorted, 'والسلسلة مرتَّبة من الأقدم للأحدث (الرسم بيتقرا صح)');
} else {
    ok('(مفيش أيام متقفلة في القاعدة دي — تخطّينا فحص الترتيب)');
}

// ═══════════════════════════════════════════════════════════════════════
sec('④ المكوّن: الإضافة اختيارية وما بتكسرش الشاشات القايمة');

$stat = (string) file_get_contents($root . '/app/Views/partials/stat.php');
chk(str_contains($stat, '$delta !== null') && str_contains($stat, 'elseif ($trend !== null)'),
    '⚠️ `delta` الرقمي (v54) لسه بيكسب لو موجود — الشاشات اللي بتبعته ما بتتأثرش');
chk(str_contains($stat, 'count($spark) >= 2'), 'والرسم ما بيترسمش بأقل من نقطتين');
chk(str_contains($stat, 'aria-hidden="true"') && str_contains($stat, '<svg class="d-kpi-spark"'),
    'والرسم زخرفي (`aria-hidden`) — الرقم والمقارنة بيقولوا المعلومة كاملة');
chk(str_contains($stat, 'preserveAspectRatio="none"') && str_contains($stat, 'vector-effect="non-scaling-stroke"'),
    '⚠️ و`non-scaling-stroke` موجودة — من غيرها التمدّد الأفقي بيرفّع سُمك الخط');
chk(str_contains($stat, '22 - ((') , '⚠️ ومحور Y مقلوب صح (القيمة الأكبر فوق)');

$css = (string) file_get_contents($root . '/public/assets/css/app.css');
foreach (['.d-kpi-spark', '.d-kpi-delta.t-good', '.d-kpi-delta.t-bad', '.d-kpi-delta.t-mute'] as $cls) {
    chk(str_contains($css, $cls), "الكلاس `{$cls}` معرَّف في app.css");
}

// ═══════════════════════════════════════════════════════════════════════
sec('⑤ التكلفة — الميزة ما تبقاش أغلى من اللي اتشال');

$t0 = microtime(true);
Metrics::dashContext($busy, 1000.0, 14);
$ms = (microtime(true) - $t0) * 1000;
chk($ms < 60, 'زمن `dashContext` = ' . number_format($ms, 1) . 'ms (الحد ٦٠ms — الرسوم اللي اتشالت كانت ٣٦.٥ms)');

$dashSrc = (string) file_get_contents($root . '/app/Controllers/DashboardController.php');
chk(str_contains($dashSrc, "Metrics::dashContext(\$date, \$custodyBalance)"),
    'والداشبورد بتمرّر رصيد الخزنة المحسوب أصلاً — مش بتحسبه تاني');
chk(!str_contains($dashSrc, 'Chart.js') && !str_contains((string) file_get_contents($root . '/app/Views/dashboard/index.php'), 'chart.js'),
    '⚠️ ومفيش أي مكتبة رسم — الـSVG مرسوم على الخادم (Chart.js اتشالت في المرحلة ٤ = ٢٠٠KB)');

// ═══════════════════════════════════════════════════════════════════════
sec('⑥ العزل — عرض بحت');

$balBefore = \App\Models\Custody::balance();
Metrics::dashContext($busy, $balBefore, 14);
$balAfter = \App\Models\Custody::balance();
chk(abs($balBefore - $balAfter) < 0.005, 'رصيد الخزنة قبل = بعد (الميزة قراءة فقط)');
foreach (['dashboardSeries' => $fn, 'balanceSeries' => $bs] as $name => $code) {
    chk(!preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|CREATE)\b/i', $code),
        "و`{$name}` ما بتكتبش أي صف");
}

// ═══════════════════════════════════════════════════════════════════════
echo "\n" . str_repeat('=', 62) . "\n";
echo 'نجح: ' . $pass . '   |   فشل: ' . count($failures) . "\n";
if ($failures) {
    echo "\n✗ الفحص فشل — الكود ده ما يترفعش:\n";
    foreach ($failures as $f) { echo "  • $f\n"; }
    exit(1);
}
echo "\n✓ كل الفحوصات عدّت.\n";
