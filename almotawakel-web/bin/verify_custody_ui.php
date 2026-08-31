<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص شاشة «خزنة المكتب» (`/custody`) — المراحل ١→٥ من إعادة البناء (docs/21).
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
 *   ⑨ **كل كلاس مطبوع له تعريف في `app.css`**، و**صفر `<style>`/`style=` محلي**
 *      في كل ملفات الشاشة (`statement.php` مستثنى — مستند A4 مستقل).
 *   ⑩ **رندر فعلي عبر الكنترولر** لكل تبويب وكل نافذة وكشف الطباعة، و**كل رقم
 *      بيفتح نافذة مختلفة** (الشاشة القديمة كانت بتوجّه أرقامها الأربعة لنفس
 *      النافذة بالحرف — تقنيًا مفيش رقم أصمّ وعمليًا نفس الجواب أربع مرات).
 *   ⑪ **التبويبات روابط `?tab=`** لا جافاسكريبت، والمسارات الحرفية قبل اللي
 *      بمعامل، وقايمة التفاصيل بيضا مقفولة و`notFound` لأي حاجة برّاها.
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

/* ⚠️ **الفحص لازم يشوف الشاشة كلها لا الغلاف بس.** بعد التقسيم لتبويبات
   (docs/21 المرحلة ٣) بقى المحتوى في `_tab_*.php`، وفحص على `index.php`
   لوحده كان هيقول «الشاشة مش بتقول عن القطع» وهي بتقول — أو أسوأ: ينجح
   وهو مش شايف الملف اللي فيه الغلط.
   ⚠️ `statement.php` **مستثنى من فحص الستايل** عن قصد: مستند A4 مستقل
      ما بيحمّلش `app.css`، فالـ`<style>` فيه هو القاعدة زي كل كشوف النظام. */
$SCREEN_VIEWS = ['app/Views/custody/index.php', 'app/Views/custody/_period.php',
                 'app/Views/custody/_drawer.php', 'app/Views/custody/_drill.php'];
foreach (App\Controllers\CustodyController::TABS as $__t) {
    $SCREEN_VIEWS[] = 'app/Views/custody/_tab_' . $__t . '.php';
}
$STATEMENT = 'app/Views/custody/statement.php';
$views = '';
foreach ($SCREEN_VIEWS as $__v) { $views .= src($__v); }

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
    /* ⚠️ `capped` بس هي اللي بتتخطّى (مفيش صفوف أصلاً — فترة أطول من السقف).
       ⚠️⚠️ **القطع بـ`MAX_ROWS` مش عذر** — القطع بياخد **آخر** الصفوف عن قصد،
          فالمعادلة لازم تفضل صحيحة. أول نسخة كانت بتقص الأول وتتخطّى الفحص،
          وده كان بيدّي على الإنتاج (١,٩١٠ صف) آخر رصيد معروض ٣١٣,٦٧٠ والفوتر
          ٢٦٠,٣٣٠ — **نفس مرض الرقمين اللي الإصلاح موجود عشانه**. */
    if ($m['capped']) {
        ok("«{$label}» فترة أطول من السقف — بلا صفوف بالتصميم");
        continue;
    }
    near("«{$label}» آخر سطر = رصيد آخر المدة", $last, $m['closing']);
    if ($m['truncated']) {
        ok("«{$label}» مقطوعة (" . count($m['rows']) . " من {$m['rows_total']}) والمعادلة لسه صح");
    }
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

/* ⚠️ التخطّي هنا **بيتقال** مش صامت: الفترة المقطوعة بتعرض آخر الصفوف بس،
   فمجموعها مش المفروض يساوي صافي الفترة كلها — والمعادلة اللي بتخصّها
   (`lead + Σ = closing`) محروسة في ① فوق. */
$checked = 0;
foreach ($ranges as $label => [$from, $to]) {
    $m = Custody::movements($from, $to);
    if ($m['capped'] || $m['truncated']) { continue; }
    $sum = 0.0;
    foreach ($m['rows'] as $r) { $sum = round($sum + $r['signed'], 2); }
    near("«{$label}» Σ(signed) = rangeMovement.net", $sum, $m['net']);
    $checked++;
}
$checked > 0 ? ok("اتفحصت {$checked} فترة كاملة (المقطوعة محروسة في ①)")
             : bad('ولا فترة كاملة اتفحصت — قصّر الفترات أو راجع السقف');

/* ⚠️⚠️ **إجماليات الأقسام = تفكيك `Closing` بالمليم — على الفترة كلها.**
   دي اللي نوافذ التفاصيل بتقولها. لو اتحسبت من الصفوف **المعروضة** بتقول رقمًا
   يخالف الكارت أول ما الصفوف تتقص: مقيس على الإنتاج 2026-08-31 المصروفات من
   الصفوف المعروضة ٥١١,٠٧١ والحقيقية ١,٢٣٨,٧٣٢ (**فرق ٧٢٧,٦٦١**) — نفس مرض
   «رقمين على نفس الشاشة» في صورة تالتة. */
foreach ($ranges as $label => [$from, $to]) {
    $m = Custody::movements($from, $to);
    if ($m['capped']) { continue; }
    $gt = $m['group_totals'];
    near("«{$label}» إجمالي الإضافات = parts.topups",
         (float) ($gt['topups'] ?? 0), $m['parts']['topups']);
    near("«{$label}» إجمالي المصروفات = parts.expenses",
         round((float) ($gt['permits'] ?? 0) + ($gt['general'] ?? 0) + ($gt['rep_deposit'] ?? 0), 2),
         $m['parts']['expenses']);
    near("«{$label}» إجمالي عهد السائقين = parts.driver",
         (float) ($gt['custody'] ?? 0), $m['parts']['driver']);
}

// ═══════════════════════════════════════════════════════════ ③
sec('③ الرصيد الجاري بيبدأ من رصيد أول المدة');

[$f, $t] = $busiest ? [$busiest, date('Y-m-t', strtotime($busiest))] : Custody::defaultRange();
$m = Custody::movements($f, $t);
near('opening = Closing::openingFor', $m['opening'], Closing::openingFor($f));
near('closing = Closing::closingFor', $m['closing'], Closing::closingFor($t));
/* ⚠️ الأساس هنا **`lead` مش `opening`**: لما الصفوف تتقص بيتعرض آخرها، فأول صف
   معروض بيتبع الرصيد اللي **قبله** لا رصيد أول المدة. الفحص القديم كان مكتوب
   على `opening` فوقع على الإنتاج بفرق ١٢٩ ألف — وهو كان غلط في الفحص لا في
   الكود. و`lead == opening` بالتعريف طول ما مفيش قطع. */
if ($m['rows']) {
    near('أول صف معروض = الرصيد اللي قبله + مبلغه',
         $m['rows'][0]['running'], $m['lead'] + $m['rows'][0]['signed']);
    if ($m['truncated']) {
        /* فيه قطع ⇒ `lead` لازم يساوي رصيد المدة ناقص مجموع الصفوف المعروضة.
           (مش تحصيل حاصل: بيثبت إن `lead` متحسوب من نفس السلسلة لا رقم مرمي.) */
        $shown = 0.0;
        foreach ($m['rows'] as $r) { $shown = round($shown + $r['signed'], 2); }
        near('فيه قطع ⇒ lead + مجموع المعروض = رصيد آخر المدة',
             round($m['lead'] + $shown, 2), $m['closing']);
    } else {
        near('مفيش قطع ⇒ lead = رصيد أول المدة', $m['lead'], $m['opening']);
    }
} else {
    ok('مفيش صفوف في الفترة دي — الشرط ما ينطبقش');
}

/* ⚠️ `drift` لازم يفضل **معروضًا** لا مبلوعًا: هو الدليل الوحيد على لقطة إقفال
   اتقادمت بعد تعديل على يوم متقفل. */
array_key_exists('drift', $m) ? ok('`drift` بترجع مع الحركة (لقطة متقادمة تتقال)')
                             : bad('`drift` اتشالت — التقادم هيتخبّى');
str_contains($views, "drift")
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
str_contains($views, 'aggregate')
    ? ok('تسمية الرصيد بتتغيّر مع النموذج')
    : bad('التسمية ثابتة — «المتاح» هتتقال على فلوس في جيب مندوب');

// ═══════════════════════════════════════════════════════════ ⑦
sec('⑦ السقف من `SafeInventory` والقطع بيتقال');

str_contains($body, 'SafeInventory::MAX_ROW_DAYS') && str_contains($body, 'SafeInventory::MAX_ROWS')
    ? ok('السقف من `SafeInventory` (مش ثوابت جديدة)')
    : bad('الوحدة عرّفت سقفًا خاصًّا بيها — هيفترق عن الجرد');
str_contains($views, 'capped') && str_contains($views, 'truncated')
    ? ok('الشاشة بتقول إن الصفوف مقطوعة (ممنوع قطع صامت)')
    : bad('القطع صامت — الشاشة هتبان كأنها عرضت كل حاجة');

/* ⚠️⚠️ **اتجاه القطع بيتفحص بالكود لا بالبيانات — وده مقصود.** القطع ما بيحصلش
   إلا فوق ٨٠٠ صف في الفترة، وقاعدة التطوير أصغر من كده بكتير، فالفحص السلوكي
   بينجح هنا **على الاتجاهين** (اتجرّب: قلب الاتجاه ما غيّرش النتيجة محليًّا).
   على الإنتاج الشهر فيه ١,٩١٠ صف فالسلوكي بيشتغل — بس الاعتماد عليه لوحده
   معناه إن الارتداد يعدّي محليًّا ويتمسك في الإنتاج بس. */
str_contains($body, 'array_slice($rows, -SafeInventory::MAX_ROWS)')
    ? ok('القطع بياخد **آخر** الصفوف — فآخر سطر يفضل = رصيد آخر المدة')
    : bad('القطع مش بياخد آخر الصفوف — آخر رصيد معروض هيخالف الفوتر');
str_contains($body, "'lead'") && str_contains($views, "\$move['lead']")
    ? ok('سطر البداية بيعرض الرصيد قبل أول صف **معروض**')
    : bad('سطر البداية بيعرض رصيد أول المدة مع إن المعروض آخر الصفوف');

$anyTruncated = false;
foreach ($ranges as [$rf, $rt]) {
    $rm = Custody::movements($rf, $rt);
    if ($rm['truncated']) { $anyTruncated = true; break; }
}
echo $anyTruncated
    ? "  ℹ القطع اتجرّب بالسلوك كمان (فيه فترة فوق السقف)\n"
    : "  ℹ مفيش فترة فوق السقف في القاعدة دي — اتجاه القطع متفحوص بالكود بس\n";

$long = Custody::movements(date('Y-m-d', strtotime($today . ' -200 day')), $today);
$long['capped'] === true ? ok('فترة ٢٠٠ يوم بترجّع `capped=true`') : bad('السقف مش شغّال');
abs($long['net']) >= 0 && array_key_exists('net', $long)
    ? ok('الإجماليات كاملة رغم قطع الصفوف') : bad('الإجماليات اتقطعت مع الصفوف');

// ═══════════════════════════════════════════════════════════ ⑧
sec('⑧ كل أيقونة موجودة في خريطة `icon()`');

/* ⚠️ `icon('اسم-غلط')` **ما بترميش خطأ — بترسم دايرة فاضية**. */
preg_match_all("/icon\(\s*'([a-z0-9-]+)'/", $views . src($STATEMENT), $mm);
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

/* ⚠️ الفحص على **كل ملفات الشاشة** لا الغلاف بس — بعد التقسيم لتبويبات
   الغلاف بقى ٦٠ سطرًا، وفحصه لوحده كان هيعدّي على أي `<style>` في تبويب. */
$styleHits = $inlineHits = [];
foreach ($SCREEN_VIEWS as $v) {
    $c = code_only($v);
    if (str_contains($c, '<style')) { $styleHits[] = basename($v); }
    preg_match_all('/\sstyle\s*=\s*"/', $c, $st);
    if ($st[0]) { $inlineHits[] = basename($v) . '(' . count($st[0]) . ')'; }
}
$styleHits ? bad('`<style>` محلي في: ' . implode(' · ', $styleHits))
           : ok('صفر `<style>` محلي في ' . count($SCREEN_VIEWS) . ' ملف');
$inlineHits ? bad('`style=` inline في: ' . implode(' · ', $inlineHits))
            : ok('صفر `style=` inline');

/* ⚠️ أي كلاس بيتطبع لازم يكون له **CSS أو سلوك JS** — الناقص بيقع على الشكل
   الافتراضي **بالسكوت** (`.table-mobile-scroll` عاش شهور معطَّل كده).
   ⚠️ الشقّ التاني إجباري: `js-dirty-form` و`confirm-delete` كلاسات سلوك مالهاش
      ولا سطر CSS عن قصد — فحص على `app.css` لوحده بيرميهم بإنذار كاذب. */
$css = src('public/assets/css/app.css');
$js  = '';
foreach (glob(dirname(__DIR__) . '/public/assets/js/*.js') ?: [] as $jsFile) {
    $js .= (string) file_get_contents($jsFile);
}
preg_match_all('/class="([^"<>]*)"/', $views, $cm);
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

/* ⚠️⚠️ **الرندر بيمرّ على الكنترولر الحقيقي لا على `include` للـview.**
   الـ`include` بمصفوفة متغيّرات مكتوبة بالإيد بيفحص الـview بس، وبيفوته أي
   متغيّر الكنترولر نسي يبعته — وده بالظبط اللي حصل بعد التقسيم لتبويبات. */
$uid = (int) \App\Core\Database::scalar('SELECT MIN(id) FROM users');
$_SESSION = ['uid' => $uid, 'role' => 'owner', 'uname' => 'فحص',
             'last_activity' => time(), 'uid_checked' => time()];

$warned = [];
$run = static function (callable $fn, string $where) use (&$warned): string {
    $seen = [];
    set_error_handler(static function ($no, $str) use (&$seen) { $seen[] = $str; return true; });
    ob_start();
    try { $fn(); $html = (string) ob_get_clean(); }
    catch (\Throwable $e) { ob_end_clean(); restore_error_handler(); bad("استثناء في {$where}: " . $e->getMessage()); return ''; }
    restore_error_handler();
    if ($seen) { $warned[] = $where . ': ' . $seen[0]; }
    /* ⚠️ الاستثناء بيتمسك فوق، لكن `Warning` بيتلزق **جوّه** الـHTML — فلازم
       نفتّش النص كمان مش نعتمد على المعالج بس. */
    preg_match('/Fatal error|Warning:|Undefined variable|Undefined array key/', $html, $m)
        ? bad("خطأ PHP في {$where}: " . ($m[0] ?? ''))
        : ok("{$where} بيرندر بلا أخطاء PHP (" . number_format(strlen($html)) . " بايت)");
    return $html;
};

/* ⚠️ الأسماء هنا **مش** `tab`/`drill`/`statement`: الأب عنده `tab()` خاصة
   ساكنة، وإعادة تعريفها بتوقيع مختلف مصيدة موثَّقة (`php -l` ما بيمسكهاش). */
$probe = new class extends \App\Controllers\CustodyController {
    public function openTab(string $t): void { $_GET = ['tab' => $t]; $this->index(); }
    public function drl(string $w): void { $_GET = []; $this->drill($w); }
    public function stmt(): void { $_GET = []; $this->statement(); }
};

$tabHtml = [];
foreach (\App\Controllers\CustodyController::TABS as $t) {
    $tabHtml[$t] = $run(static fn () => $probe->openTab($t), "تبويب «{$t}»");
}
foreach (\App\Controllers\CustodyController::DRILL as $w) {
    $h = $run(static fn () => $probe->drl($w), "نافذة «{$w}»");
    /* نافذة فاضية = رقم أصمّ فعليًّا — الزرار بيفتح لوحًا مفيهوش حاجة. */
    if ($h !== '' && strlen(trim(strip_tags($h))) < 5) { bad("نافذة «{$w}» فاضية"); }
}
$stmtHtml = $run(static fn () => $probe->stmt(), 'كشف الحساب');
$_GET = [];

$warned ? bad('تحذيرات وقت الرندر: ' . implode(' | ', array_slice($warned, 0, 3)))
        : ok('صفر تحذير وقت الرندر');

/* ⚠️ **صفر رقم أصمّ** — كل رقم لازم يفتح بنوده، وبنوافذ **مختلفة**.
   الشاشة القديمة كانت بتوجّه أرقامها الأربعة لنفس النافذة (`#custodyBreak`)
   فتقنيًا مفيش رقم أصمّ وعمليًا الأربعة بيدّوا نفس الجواب. */
preg_match_all('/data-drill="([^"]+)"/', $tabHtml['overview'] ?? '', $dm);
$targets = array_unique($dm[1] ?? []);
count($targets) >= 4
    ? ok('«نظرة عامة» فيها ' . count($targets) . ' نافذة **مختلفة**')
    : bad('أرقام النظرة العامة بتفتح ' . count($targets) . ' نافذة بس — رجعنا لمرض «نفس الجواب»');
foreach ($targets as $t) {
    if (!str_contains($t, '/custody/drill/')) { bad("هدف نافذة مش من مسار الوحدة: {$t}"); }
}

/* المصروفات كانت **مختفية تمامًا** من السجل قبل الإصلاح — لازم تفضل ظاهرة. */
str_contains($tabHtml['moves'] ?? '', 'حركة الخزنة')
    ? ok('قسم الحركة موجود') : bad('قسم الحركة اختفى');
str_contains($tabHtml['drivers'] ?? '', 'المحتجز')
    ? ok('«المحتجز» ظاهر (مصدر كومسيون المناديب)') : bad('«المحتجز» مش ظاهر');

/* الكشف: مستند A4 حقيقي بهوية النظام. */
str_contains($stmtHtml, '<!doctype html') && str_contains($stmtHtml, 'كشف حساب خزنة المكتب')
    ? ok('كشف الحساب مستند A4 كامل') : bad('كشف الحساب مش مستند مكتمل');
/* ⚠️ ممنوع أي مستند يكتب اسم خط بإيده — الهوية من `print_base`. */
str_contains(src($STATEMENT), "partial('print_base')")
    ? ok('الكشف بياخد هويته من `print_base` (المصدر الوحيد)')
    : bad('الكشف بيكتب هوية طباعة بنفسه');
preg_match('/font-family\s*:\s*(?!var\(--font\))[\'"a-zA-Z]/', src($STATEMENT))
    ? bad('الكشف بيكتب اسم خط بإيده — لازم `var(--font)`')
    : ok('الكشف ما بيكتبش اسم خط بإيده');

// ═══════════════════════════════════════════════════════════ ⑪
sec('⑪ التبويبات والمسارات');

/* ⚠️ التبويب **رابط حقيقي** (`?tab=`) لا تبديل بالجافاسكريبت — الرجوع
   بالمتصفح والمشاركة والفتح في تبويب جديد كلها بتشتغل. */
str_contains($views, "?tab=") && !preg_match('/onclick\s*=\s*"[^"]*tab/i', $views)
    ? ok('التبويبات روابط `?tab=` مش جافاسكريبت')
    : bad('التبويبات بتتبدّل بالجافاسكريبت — الرجوع بالمتصفح هيكسر');

foreach ($SCREEN_VIEWS as $v) {
    is_file(dirname(__DIR__) . '/' . $v)
        ? ok(basename($v) . ' موجود') : bad("ملف الشاشة ناقص: {$v}");
}

$routes = code_only('public/index.php');
foreach (["\$r->get('/custody/drill/{what}'", "\$r->get('/custody/statement'"] as $needle) {
    str_contains($routes, $needle) ? ok("المسار مسجَّل: {$needle}") : bad("المسار مش مسجَّل: {$needle}");
}
/* ⚠️ الحرفي قبل أي مسار بمعامل — الراوتر first-match-wins (تحذير ٤). */
$pDrill = strpos($routes, "/custody/drill/{what}");
$pStmt  = strpos($routes, "/custody/statement");
$pParam = strpos($routes, "/custody/topup/{id}");
($pDrill !== false && $pStmt !== false && $pParam !== false && $pDrill < $pParam && $pStmt < $pParam)
    ? ok('المسارات الحرفية قبل اللي بمعامل (تحذير ٤)')
    : bad('ترتيب المسارات غلط');

/* ⚠️⚠️ **الرفض هو الافتراضي — والفحص بالكود مش بالنداء.**
   نداء `drill()` بنوع مزوّر بيوصل لـ`notFound()` اللي **بتطبع وبتعمل exit**،
   فبتقتل باقي الفاحص بلا رسالة (مصيدة موثَّقة في CLAUDE.md — اتصادفت هنا فعلاً:
   الفاحص طبع صفحة ٤٠٤ في الطرفية ووقف قبل السطر ده). */
$drillBody = code_only($CTRL);
$ds = strpos($drillBody, 'function drill');
$de = strpos($drillBody, 'function statement');
$drillOnly = ($ds !== false && $de !== false && $de > $ds) ? substr($drillBody, $ds, $de - $ds) : '';
str_contains($drillOnly, 'in_array($what, self::DRILL, true)') && str_contains($drillOnly, 'notFound')
    ? ok('نوع التفاصيل محصور في قايمة بيضا والباقي `notFound`')
    : bad('`drill()` بيقبل أي نوع من الرابط');
/* والقايمة نفسها لازم تفضل مقفولة — مفيش `$_GET` جوّه الشرط. */
!preg_match('/DRILL\s*=\s*\[[^\]]*\$/', src($CTRL))
    ? ok('قايمة التفاصيل ثابتة مش مبنية من الرابط')
    : bad('قايمة التفاصيل بتتبني من مدخلات — مش قايمة بيضا');

// ─────────────────────────────────────────────────────────────
echo "\n" . str_repeat('=', 64) . "\n";
printf("ناجح: %d   |   فاشل: %d\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
