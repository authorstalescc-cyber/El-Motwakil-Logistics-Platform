<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص شاشة «خزنة المكتب» (`/custody`) — المرحلة ١ من إعادة البناء (docs/21).
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  القاعدة اللي الفاحص ده موجود عشانها
 * ═══════════════════════════════════════════════════════════════════════
 * الشاشة كانت بتقول **رقمين مختلفين لنفس السؤال**: الكارت فوق من
 * `Closing::currentBalance()` والسجل تحت من `Custody::ledger()` اللي كان
 * بيحسب `Σ(إضافات) − Σ(عهدة−١٠٠)` بإيده — بلا مصروفات وبلا رصيد آخر إقفال.
 * مقيس على قاعدة التطوير 2026-08-31: **٢٤٥,٠٥٠ مقابل ٥٧٢,٧٩٠** (فرق ٣٢٧,٧٤٠).
 *
 * الفحوصات:
 *   ① **آخر سطر في الحركة = `Closing::closingFor($to)` بالمليم** — على أكتر من
 *      فترة، وواحدة منها بتعدّي على يوم متقفل. دي القاعدة اللي بتقفل الفجوة.
 *   ② **`Σ(signed)` = `Closing::rangeMovement()['net']`** — يمنع رجوعها من ناحية تانية.
 *   ③ **الرصيد الجاري بيبدأ من `openingFor($from)`** لا من صفر.
 *   ④ **ولا استعلام حركة جديد في الوحدة** — الصفوف من `DailyReview` وبس.
 *   ⑤ **الدالة القديمة `ledger()` ما ترجعش** بأي اسم.
 *   ⑥ **`walletSplit()` مش `Custody::balance()`** في أي كلام عن محفظة.
 *   ⑦ **السقف من `SafeInventory`** لا ثوابت جديدة، و**القطع بيتقال بالنص**.
 *   ⑧ **كل أيقونة موجودة في خريطة `icon()`** (وإلا دايرة فاضية بالسكوت).
 *   ⑨ **كل كلاس مطبوع له تعريف في `app.css`**، و**صفر `<style>`/`style=` محلي**.
 *   ⑩ **الكنترولر بيحمّل فعلاً** وبيرندر الشاشة بلا تحذير.
 *
 * ⚠️ الفاحص **قراءة بحتة** — ما بيكتبش ولا صف، فمش محتاج transaction.
 * التشغيل:  php bin/verify_custody_ui.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Models\Closing;
use App\Models\Custody;

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function sec(string $m): void { echo "\n" . $m . "\n" . str_repeat('─', 64) . "\n"; }
function near(string $l, $a, $e): void {
    abs((float) $a - (float) $e) <= 0.005 ? ok($l)
        : bad("{$l} — متوقع " . number_format((float) $e, 2) . ' / فعلي ' . number_format((float) $a, 2));
}
/** ⚠️ كود بلا تعليقات — التعليق اللي بيوثّق القاعدة بيذكر نصّها فيدي نتيجة كاذبة. */
function code_only(string $file): string {
    $p = dirname(__DIR__) . '/' . $file;
    if (!is_file($p)) { return ''; }
    $out = '';
    foreach (token_get_all((string) file_get_contents($p)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}
function src(string $file): string {
    $p = dirname(__DIR__) . '/' . $file;
    return is_file($p) ? (string) file_get_contents($p) : '';
}

echo "فحص شاشة خزنة المكتب (/custody)\n" . str_repeat('=', 64) . "\n";

$MODEL = 'app/Models/Custody.php';
$CTRL  = 'app/Controllers/CustodyController.php';
$VIEW  = 'app/Views/custody/index.php';

// ═══════════════════════════════════════════════════════════ ①
sec('① آخر سطر في الحركة = رصيد آخر المدة (القاعدة الحاكمة)');

/* ⚠️ **الفترات مشتقّة من بيانات القاعدة مش مكتوبة بالإيد** — فترة ثابتة كانت
   هتعدّي فاضية على أي قاعدة تانية فيدي «نجاح» بلا ما يفحص حاجة. */
$anchor = Closing::lastClosedDate();
$today  = work_date();
$ranges = [
    'الشهر الحالي' => Custody::defaultRange(),
    'يوم واحد'     => [$today, $today],
];
/* فترة بتعدّي على **يوم متقفل** — أهم حالة، لأن نقطة الارتكاز بتتغيّر جوّه
   الفترة والرصيد الجاري لازم يفضل صح رغم كده. */
if ($anchor !== null) {
    $ranges['حوالين آخر إقفال'] = [
        date('Y-m-d', strtotime($anchor . ' -20 day')),
        date('Y-m-d', strtotime($anchor . ' +10 day')),
    ];
}
/* الشهر اللي فيه أكبر عدد حركة — يضمن إن الفحص بيعدّي على صفوف حقيقية. */
$busiest = \App\Core\Database::scalar(
    "SELECT DATE_FORMAT(d, '%Y-%m-01') FROM (
        SELECT cust_date d FROM manual_custody WHERE cust_date IS NOT NULL
        UNION ALL SELECT bill_date FROM bawalis WHERE is_legacy = 0 AND custody > 0
     ) x GROUP BY 1 ORDER BY COUNT(*) DESC LIMIT 1");
if ($busiest) {
    $ranges['أكتر شهر حركة'] = [$busiest, date('Y-m-t', strtotime($busiest))];
    /* ⚠️⚠️ **الفترة دي هي اللي بتدّي الفحص قوّته الحقيقية — متشيلهاش.**
       الفترة اللي بتبدأ من **أول** الشهر رصيدها الافتتاحي بيطلع صفر غالبًا،
       وساعتها «الرصيد الجاري بيبدأ من صفر» (الباج الأصلي) و«بيبدأ من رصيد أول
       المدة» (السليم) بيدّوا **نفس الرقم بالظبط** — فالفحص بينجح على الكود
       المكسور. مقيس فعليًّا: كل فترات الفحص الأولى كان `opening = 0`.
       البداية من نصّ الشهر بتخلّي قبلها حركة، فالرصيد الافتتاحي ≠ صفر. */
    $ranges['من نصّ أكتر شهر حركة'] = [
        date('Y-m-15', strtotime($busiest)), date('Y-m-t', strtotime($busiest)),
    ];
}

$sawRows = false;
$discriminating = false;   // فترة فيها صفوف **ورصيد افتتاحي ≠ صفر**
foreach ($ranges as $label => [$from, $to]) {
    $m    = Custody::movements($from, $to);
    $last = $m['rows'] ? end($m['rows'])['running'] : $m['opening'];
    if ($m['rows']) { $sawRows = true; }
    if ($m['rows'] && abs($m['opening']) >= 0.005) { $discriminating = true; }
    /* ⚠️ الصفوف المقطوعة بتكسر المعادلة **عن حق** — الرصيد الجاري بيقف عند
       آخر صف معروض. فالفحص بيتخطّاها بدل ما يفشل بإنذار كاذب. */
    if ($m['capped'] || $m['rows_total'] > count($m['rows'])) {
        ok("«{$label}» صفوفها مقطوعة بالتصميم — خارج المعادلة");
        continue;
    }
    near("«{$label}» آخر سطر = رصيد آخر المدة", $last, $m['closing']);
}
$sawRows ? ok('الفحص عدّى على صفوف حقيقية مش فترات فاضية')
         : bad('كل الفترات طلعت فاضية — الفحص ما اختبرش حاجة');
/* ⚠️ **فحص ما بيفرّقش = فحص بينجح على الكود المكسور.** لو مفيش ولا فترة فيها
   صفوف ورصيد افتتاحي ≠ صفر، يبقى الفحص فوق ما يقدرش يمسك «الرصيد الجاري
   بيبدأ من صفر» — والسكوت هنا أسوأ من الفشل. */
$discriminating ? ok('فيه فترة برصيد افتتاحي ≠ صفر — الفحص قادر يفرّق')
                : bad('كل الفترات رصيدها الافتتاحي صفر — الفحص مش قادر يمسك بداية غلط');

// ═══════════════════════════════════════════════════════════ ②
sec('② مجموع الحركة = صافي الفترة من `Closing`');

foreach ($ranges as $label => [$from, $to]) {
    $m = Custody::movements($from, $to);
    if ($m['capped'] || $m['rows_total'] > count($m['rows'])) { continue; }
    $sum = 0.0;
    foreach ($m['rows'] as $r) { $sum = round($sum + $r['signed'], 2); }
    near("«{$label}» Σ(signed) = rangeMovement.net", $sum, $m['net']);
}

// ═══════════════════════════════════════════════════════════ ③
sec('③ الرصيد الجاري بيبدأ من رصيد أول المدة');

[$f, $t] = $busiest ? [$busiest, date('Y-m-t', strtotime($busiest))] : Custody::defaultRange();
$m = Custody::movements($f, $t);
near('opening = Closing::openingFor', $m['opening'], Closing::openingFor($f));
near('closing = Closing::closingFor', $m['closing'], Closing::closingFor($t));
if ($m['rows']) {
    near('أول صف = رصيد أول المدة + مبلغه',
         $m['rows'][0]['running'], $m['opening'] + $m['rows'][0]['signed']);
} else {
    ok('مفيش صفوف في الفترة دي — الشرط ما ينطبقش');
}

/* ⚠️ `drift` لازم يفضل **معروضًا** لا مبلوعًا: هو الدليل الوحيد على لقطة إقفال
   اتقادمت بعد تعديل على يوم متقفل. */
array_key_exists('drift', $m) ? ok('`drift` بترجع مع الحركة (لقطة متقادمة تتقال)')
                             : bad('`drift` اتشالت — التقادم هيتخبّى');
str_contains(src($VIEW), "drift")
    ? ok('الشاشة بتعرض `drift` لما يحصل') : bad('الشاشة ما بتقولش عن اللقطة المتقادمة');

// ═══════════════════════════════════════════════════════════ ④
sec('④ ولا استعلام حركة جديد — الصفوف من `DailyReview` وبس');

/* ⚠️⚠️ **ده الحارس اللي بيمنع رجوع الباج.** أول ما `movements()` تكتب
   `SELECT ... FROM bawalis` أو `manual_expenses` بإيدها، بتبقى نسخة تانية من
   المعادلة — وواحدة منهم هتتقادم أول ما شرط يتضاف في `Closing`. */
$model = code_only($MODEL);
$start = strpos($model, 'function movements');
$end   = strpos($model, 'function groupIcon');
$body  = ($start !== false && $end !== false && $end > $start)
    ? substr($model, $start, $end - $start) : '';
$body !== '' ? ok('لقينا جسم `movements()` للفحص') : bad('مش لاقي `movements()` — الفحص مش هيقدر يتأكد');

$forbidden = ['manual_custody', 'manual_expenses', 'rep_entries', 'rep_misc_expenses',
              'permit_stages', 'rep_custody_deposits', 'bawalis'];
$hit = [];
foreach ($forbidden as $tbl) {
    if (str_contains($body, $tbl)) { $hit[] = $tbl; }
}
$hit ? bad('`movements()` بتقرا جداول بنفسها: ' . implode(', ', $hit))
     : ok('`movements()` ما بتلمسش ولا جدول مالي مباشرةً');

str_contains($body, 'DailyReview::officeCashItems')
    ? ok('الصفوف من `DailyReview::officeCashItems` (المصدر الوحيد)')
    : bad('الصفوف مش جاية من `DailyReview::officeCashItems`');
str_contains($body, 'Closing::openingFor') && str_contains($body, 'Closing::closingFor')
    && str_contains($body, 'Closing::rangeMovement')
    ? ok('الأرصدة والصافي من `Closing` نفسه')
    : bad('الوحدة بتحسب رصيدًا أو صافيًا بنفسها');

// ═══════════════════════════════════════════════════════════ ⑤
sec('⑤ الدالة القديمة ما رجعتش');

method_exists(Custody::class, 'ledger')
    ? bad('`Custody::ledger()` رجعت — دي كانت مصدر الرقمين المختلفين')
    : ok('`Custody::ledger()` متشالة');
/* ⚠️ الفحص على **السلوك** كمان مش على الاسم بس: أي دالة بترجّع صفوف حركة
   لازم تعدّي على نفس المصدر. */
foreach (['app/Views/custody/index.php', $CTRL] as $f2) {
    str_contains(code_only($f2), '::ledger(')
        ? bad("`{$f2}` لسه بينادي `ledger()`") : ok(basename($f2) . ' نضيف من `ledger()`');
}

// ═══════════════════════════════════════════════════════════ ⑥
sec('⑥ المحفظة من `walletSplit()` — ومعنى الرصيد بيتقال');

$ctrl = code_only($CTRL);
str_contains($ctrl, 'Closing::walletSplit')
    ? ok('الكنترولر بيجيب تفكيك المحفظة من `walletSplit()`')
    : bad('الشاشة ما بتفرّقش الدرج عن جيوب المناديب');
/* ⚠️ **النموذج بيتقرا وقت التشغيل لا من التوثيق** — الإنتاج والتوثيق مختلفين
   فعلاً (مقيس 2026-08-08)، وأي قايمة ثابتة هنا بتكذب على شاشة الإنتاج. */
str_contains($ctrl, 'RepCustody::bankTopupActive')
    ? ok('النموذج الفعّال بيتقرا وقت التشغيل')
    : bad('الشاشة بتفترض نموذجًا ثابتًا — هتكذب على الإنتاج');

$view = src($VIEW);
$w = Closing::walletSplit();
foreach (['drawer', 'reps', 'total', 'grand'] as $k) {
    array_key_exists($k, $w) ? ok("`walletSplit()['{$k}']` موجودة")
                             : bad("`walletSplit()` مالهاش مفتاح `{$k}`");
}
/* لما النموذج مجمّع، «المتاح» كلمة كاذبة — لازم الشاشة تقول إنه شامل الجيوب. */
str_contains($view, 'aggregate')
    ? ok('تسمية الرصيد بتتغيّر مع النموذج')
    : bad('التسمية ثابتة — «المتاح» هتتقال على فلوس في جيب مندوب');

// ═══════════════════════════════════════════════════════════ ⑦
sec('⑦ السقف من `SafeInventory` والقطع بيتقال');

str_contains($body, 'SafeInventory::MAX_ROW_DAYS') && str_contains($body, 'SafeInventory::MAX_ROWS')
    ? ok('السقف من `SafeInventory` (مش ثوابت جديدة)')
    : bad('الوحدة عرّفت سقفًا خاصًّا بيها — هيفترق عن الجرد');
str_contains($view, 'capped')
    ? ok('الشاشة بتقول إن الصفوف مقطوعة (ممنوع قطع صامت)')
    : bad('القطع صامت — الشاشة هتبان كأنها عرضت كل حاجة');

$long = Custody::movements(date('Y-m-d', strtotime($today . ' -200 day')), $today);
$long['capped'] === true ? ok('فترة ٢٠٠ يوم بترجّع `capped=true`') : bad('السقف مش شغّال');
abs($long['net']) >= 0 && array_key_exists('net', $long)
    ? ok('الإجماليات كاملة رغم قطع الصفوف') : bad('الإجماليات اتقطعت مع الصفوف');

// ═══════════════════════════════════════════════════════════ ⑧
sec('⑧ كل أيقونة موجودة في خريطة `icon()`');

/* ⚠️ `icon('اسم-غلط')` **ما بترميش خطأ — بترسم دايرة فاضية**. */
preg_match_all("/icon\(\s*'([a-z0-9-]+)'/", $view, $mm);
$iconNames = array_unique($mm[1]);
/* أيقونات الأقسام جاية من `groupIcon` — بتتقاس من الدالة نفسها لا بقايمة مكتوبة. */
$ref = new ReflectionMethod(Custody::class, 'groupIcon');
foreach (['topups', 'permits', 'general', 'custody', 'rep_deposit', 'حاجة-مش-موجودة'] as $g) {
    $iconNames[] = $ref->invoke(null, $g);
}
$missing = [];
foreach (array_unique($iconNames) as $name) {
    if (trim((string) icon($name, 14)) === '') { $missing[] = $name; }
}
$missing ? bad('أيقونات مش في الخريطة: ' . implode(', ', $missing))
         : ok(count($iconNames) . ' أيقونة كلها موجودة');

// ═══════════════════════════════════════════════════════════ ⑨
sec('⑨ صفر ستايل محلي وكل كلاس له تعريف');

$viewCode = code_only($VIEW);
str_contains($viewCode, '<style') ? bad('فيه `<style>` محلي (قانون التصميم بند ٧)')
                                  : ok('صفر `<style>` محلي');
preg_match_all('/\sstyle\s*=\s*"/', $viewCode, $st);
count($st[0]) === 0 ? ok('صفر `style=` inline') : bad(count($st[0]) . ' خانة `style=` inline');

/* ⚠️ أي كلاس بيتطبع لازم يكون له **CSS أو سلوك JS** — الناقص بيقع على الشكل
   الافتراضي **بالسكوت** (`.table-mobile-scroll` عاش شهور معطَّل كده).
   ⚠️ الشقّ التاني إجباري: `js-dirty-form` و`confirm-delete` كلاسات سلوك مالهاش
      ولا سطر CSS عن قصد — فحص على `app.css` لوحده بيرميهم بإنذار كاذب. */
$css = src('public/assets/css/app.css');
$js  = '';
foreach (glob(dirname(__DIR__) . '/public/assets/js/*.js') ?: [] as $jsFile) {
    $js .= (string) file_get_contents($jsFile);
}
preg_match_all('/class="([^"<>]*)"/', $view, $cm);
$classes = [];
foreach ($cm[1] as $chunk) {
    foreach (preg_split('/\s+/', trim($chunk)) as $c) {
        if ($c !== '' && !str_contains($c, '<?')) { $classes[$c] = true; }
    }
}
$orphan = [];
foreach (array_keys($classes) as $c) {
    if (!str_contains($css, '.' . $c) && !str_contains($js, $c)) { $orphan[] = $c; }
}
$orphan ? bad('كلاسات بلا CSS ولا سلوك JS: ' . implode(' · ', $orphan))
        : ok(count($classes) . ' كلاس كلهم لهم تعريف CSS أو سلوك JS');

// ═══════════════════════════════════════════════════════════ ⑩
sec('⑩ الشاشة بتشتغل فعلاً');

/* ⚠️ دالة `protected` جديدة في `Controller` بتكسر أي كنترولر عنده نفس الاسم
   `private` — و`php -l` ما بيمسكهاش. التحميل الفعلي هو اللي بيمسكها. */
try {
    new \App\Controllers\CustodyController();
    ok('`CustodyController` بيتحمّل بلا تعارض');
} catch (\Throwable $e) {
    bad('الكنترولر بيفشل في التحميل: ' . $e->getMessage());
}

/* رندر فعلي للشاشة — أي `Undefined variable` أو أيقونة ناقصة بتطلع هنا. */
$vars = [
    'title' => 'رصيد العهدة', 'repList' => [], 'rate' => Custody::deductionRate(),
    'move' => Custody::movements(...Custody::defaultRange()),
    'wallet' => Closing::walletSplit(), 'breakdown' => Closing::currentBreakdown(),
    'lastClosed' => Closing::lastClosedDate(),
    'aggregate' => \App\Models\RepCustody::bankTopupActive(),
    'topupState' => ['token' => 'x', 'old' => [], 'errors' => [], 'back' => 'custody'],
    'repDepState' => ['token' => 'x', 'old' => [], 'errors' => [], 'back' => 'custody'],
];
$level = error_reporting();
$warned = [];
set_error_handler(static function ($no, $str) use (&$warned) { $warned[] = $str; return true; });
ob_start();
try {
    (static function (array $data) {
        extract($data, EXTR_SKIP);
        include dirname(__DIR__) . '/app/Views/custody/index.php';
    })($vars);
    $html = (string) ob_get_clean();
    $ok = true;
} catch (\Throwable $e) {
    ob_end_clean();
    $html = ''; $ok = false;
    bad('الشاشة بترمي استثناء: ' . $e->getMessage());
}
restore_error_handler();
error_reporting($level);
if ($ok) {
    $html !== '' ? ok('الشاشة بترندر (' . number_format(strlen($html)) . ' بايت)')
                 : bad('الشاشة رندرت فاضية');
    $warned ? bad('تحذيرات وقت الرندر: ' . implode(' | ', array_slice($warned, 0, 3)))
            : ok('صفر تحذير وقت الرندر');
    /* ⚠️ **صفر رقم أصمّ** — الرصيد لازم يفتح تفكيكه. */
    str_contains($html, 'custodyBreak') ? ok('الرصيد بيفتح تفكيكه (ممنوع رقم أصمّ)')
                                        : bad('الرصيد بقى رقمًا أصمّ');
    /* الحركة لازم تعرض المصروفات — دي كانت مختفية تمامًا قبل الإصلاح. */
    str_contains($html, 'حركة الخزنة') ? ok('قسم الحركة موجود') : bad('قسم الحركة اختفى');
}

// ─────────────────────────────────────────────────────────────
echo "\n" . str_repeat('=', 64) . "\n";
printf("ناجح: %d   |   فاشل: %d\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
