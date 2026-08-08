<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص المرحلة ١٥ — الطباعة الموحّدة + الوضع الليلي + الشاشات الصغيرة (2026-07-25).
 *
 * ⚠️ **الوضع الليلي جه آخر حاجة عن قصد.** هو مش «قلب ألوان» — هو **تصنيف
 *    دلالي**: `#fff` كان مستعمل ١٦٨ مرة بمعنيين مختلفين تمامًا (خلفية سطح ≠
 *    نص أبيض على داكن). لو اتحوّلوا كلهم لتوكن واحد كان الوضع الليلي هيتكسر
 *    بدل ما يتجهّز — فالتصنيف اتعمل الأول (`background:#fff` → `--card` ·
 *    `color:#fff` → `--white`)، وبعدين اتكتب الوضع الليلي على **طبقة التوكنز
 *    بس** بلا ولا مُحدِّد شاشة واحد.
 *
 * ⚠️ **الطباعة دايمًا فاتحة** مهما كان وضع الشاشة — الورقة بتتطبع على ورق
 *    أبيض، والغامق بياكل الحبر ويطلع رمادي.
 *
 * أهم المحروس كمان:
 *   • **السمة بتتحط قبل أول رسمة** — لو اتأخرت الصفحة بتلمع أبيض ثم تغمق.
 *   • **`AppUI.toast` متاح فعلاً** — استدعاؤه من برّه الـIIFE من غير تصدير
 *     كان بيبقى خطّاف بلا تنفيذ فيفشل بالسكوت (عائلة `.table-mobile-scroll`).
 *   • **هوية الطباعة من مصدر واحد** — كانت ٧ نسخ من نفس التوكنز.
 *   • **مفيش إيموجي في عناوين الأقسام** — الأيقونات SVG.
 *
 * قراءة فقط بالكامل — ما بيلمسش القاعدة.
 * التشغيل:  php bin/verify_dark_print.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$pass = 0; $failures = [];
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $failures; $failures[] = $m; echo "  ✗ $m\n"; }
function chk(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function sec(string $m): void { echo "\n$m\n" . str_repeat('─', 62) . "\n"; }

echo "فحص المرحلة ١٥ — الطباعة والوضع الليلي\n" . str_repeat('=', 62) . "\n";

$root = dirname(__DIR__);
$css    = (string) file_get_contents($root . '/public/assets/css/app.css');
/* ⚠️ التعليق اللي بيوثّق القاعدة بيذكر النص نفسه — لازم يتشال قبل الفحص وإلا
   إنذار كاذب (نفس مصيدة `code_only` في verify_security). */
$cssCode = preg_replace('~/\*.*?\*/~s', '', $css) ?? $css;
$js     = (string) file_get_contents($root . '/public/assets/js/app.js');
$layout = (string) file_get_contents($root . '/app/Views/layout.php');
$base   = (string) file_get_contents($root . '/app/Views/partials/print_base.php');

// ═══ ① ⚠️ التصنيف الدلالي — الأساس اللي الوضع الليلي واقف عليه
sec('① ⚠️ التصنيف الدلالي لـ #fff (٩٠٪ من شغل الوضع الليلي)');
chk(!preg_match('/background(-color)?:#fff\b/i', $cssCode),
    'مفيش خلفية بيضا مكتوبة بالإيد — كلها بقت `--card` (سطح)');
chk(!preg_match('/(?<!background-)color:#fff\b/i', $cssCode),
    'ومفيش نص أبيض مكتوب بالإيد — كله بقى `--white` (نص على داكن)');
chk(str_contains($css, '--card:#FFFFFF') && str_contains($css, '--white:#FFFFFF'),
    'والتوكنان لسه بنفس القيمة في الوضع الفاتح — الفرق **معنى** مش لون');
chk(str_contains($css, 'خلفية سطح') && str_contains($css, 'نص أبيض على خلفية داكنة'),
    'والفرق موثَّق جنب الكود للي هيعدّل بعدين');

// ═══ ② الوضع الليلي — طبقة التوكنز بس
sec('② الوضع الليلي على طبقة التوكنز');
chk(str_contains($css, '@media (prefers-color-scheme: dark)'), 'بيتبع الجهاز لما المستخدم ما يختارش');
chk(str_contains($css, ':root[data-theme="dark"]'), 'والاختيار الصريح بيغلب إعداد الجهاز');
chk(str_contains($css, ':root:not([data-theme="light"])'),
    '⚠️ و«فاتح» الصريح بيغلب جهازًا غامقًا — الاتجاهين شغّالين');
/* المقطع الليلي لازم يكون توكنز بس — مفيش أي مُحدِّد شاشة.
   ⚠️ **الفحص ده كان بيقيس غلط، والغلط اتصاد فعليًّا (2026-07-28):** كان بياخد
      **كل اللي بعد رأس المقطع لآخر الملف** على إنه «المقطع الليلي». `app.css`
      بيتزوّد بالإلحاق في الآخر (`cat >>`)، فأي كتلة شاشة جديدة بتقع «جوّه»
      المقطع في نظر الفحص وتفشّله بإنذار كاذب — حصل مرتين في يوم واحد.
      (وفي المقابل كان بيعدّي على مقطع «مستندات الأطراف» v56 اللي تحته من
      زمان لأن بادئته `.pdoc-` مش في القايمة — يعني كان بيفشل غلط **وبيعدّي
      غلط** في نفس الوقت.)
   الصح: نحدّ المقطع من رأسه **لأول رأس مقطع بعده** فنقيس المقطع نفسه بالظبط. */
$darkStart = strpos($css, 'الوضع الليلي — المرحلة ١٥');
$darkPart  = '';
if ($darkStart !== false) {
    // أول رأس مقطع (`/* ════`) بعد رأس المقطع الليلي = نهايته
    $nextHead = null;
    if (preg_match('~/\*\s*═{6,}~u', substr($css, $darkStart), $m2, PREG_OFFSET_CAPTURE)) {
        $nextHead = $darkStart + $m2[0][1];
    }
    $darkPart = $nextHead !== null
        ? substr($css, $darkStart, $nextHead - $darkStart)
        : substr($css, $darkStart);
}
chk($darkPart !== '' && !preg_match('/^\s*\.(d-|rp-|pv-|qe-|card|kpi)/m', $darkPart),
    '⚠️ مفيش ولا مُحدِّد شاشة في المقطع الليلي — التوكنز بس');

/* ⚠️ **جرّبت أشدّد الفحص لـ«ولا مُحدِّد كلاس خالص» فطلع غلط** — المقطع فيه
   `.theme-btn`/`.auth-theme` وهُمّ **آلة تبديل السمة نفسها** (تبديل أيقونة
   الزرار حسب الوضع)، مش إعادة تنسيق شاشة. القاعدة المقصودة هي: **ممنوع
   تعيد تنسيق شاشة للوضع الليلي — التوكنز بس** — وبادئات الشاشات فوق هي
   اللي بتعبّر عنها. ما تشدّدهاش لكل الكلاسات. */
foreach (['--bg', '--card', '--ink', '--line', '--zebra', '--input', '--gold-light'] as $tok) {
    chk(preg_match('/:root\[data-theme="dark"\][^}]*' . preg_quote($tok, '/') . ':/s', $darkPart) === 1,
        "التوكن `$tok` له قيمة ليلية");
}
chk(preg_match('/:root\[data-theme="dark"\][^}]*--navy:/s', $darkPart) !== 1,
    '⚠️ وألوان الهوية (navy/gold) **ما بتتغيّرش** — الشركة ليها لون واحد');
chk(str_contains($darkPart, 'color-scheme:dark'),
    'و`color-scheme` متظبّط فعناصر المتصفح نفسها تغمق');

// ═══ ③ ⚠️ الطباعة دايمًا فاتحة
sec('③ ⚠️ الطباعة فاتحة مهما كان وضع الشاشة');
chk(preg_match('/@media print\{\s*:root, :root\[data-theme="dark"\]/', $darkPart) === 1,
    'قاعدة الطباعة بتعيد التوكنز الفاتحة صراحةً');
chk(str_contains($darkPart, 'الغامق بياكل الحبر'),
    'والسبب مكتوب جنبها');

// ═══ ④ ⚠️ السمة قبل أول رسمة
sec('④ ⚠️ مفيش لمعة بيضا قبل ما الصفحة تغمق');
$headScript = strpos($layout, "localStorage.getItem('almotawakel:theme')");
$cssLink    = strpos($layout, '<link rel="stylesheet"');
chk($headScript !== false && $cssLink !== false && $headScript < $cssLink,
    'سكربت السمة في `<head>` **قبل** ملف التصميم');
chk(str_contains($layout, "setAttribute('data-theme',t)"), 'وبيحطّ السمة على `<html>` مباشرةً');
chk(str_contains($layout, 'flash of wrong theme'), 'والمصيدة موثَّقة جنب الكود');
chk(str_contains($layout, 'id="themeBtn"'), 'وزرار التبديل موجود في الشريط العلوي');
chk(str_contains($js, 'themeToggle'), 'ومعالجه في app.js');
chk(str_contains($js, "{ auto: 'light', light: 'dark', dark: 'auto' }"),
    'وتلات حالات: تلقائي ← فاتح ← غامق');

// ═══ ⑤ ⚠️ كل كلاس محقون له CSS
sec('⑤ ⚠️ مفيش خطّاف بلا تنفيذ');
foreach (['theme-btn', 'th-ico', 'th-auto', 'th-light', 'th-dark'] as $cls) {
    chk(str_contains($css, '.' . $cls), "`.$cls` معرَّف في app.css");
}
chk(preg_match('/window\.AppUI = Object\.assign\(window\.AppUI \|\| \{\}, \{ toast \}\)/', $js) === 1,
    '⚠️ `AppUI.toast` متاح فعلاً — من غيره النداء بيفشل بالسكوت');

// ═══ ⑥ الطباعة — مصدر واحد للهوية
sec('⑥ هوية الطباعة من مصدر واحد');
chk(is_file($root . '/app/Views/partials/print_base.php'), 'مكوّن `print_base` موجود');
chk(str_contains($base, '--navy:#15355B') && str_contains($base, '--gold:#C9A227'),
    'وفيه توكنز الهوية');
chk(!str_contains($base, '.sheet') && !str_contains($base, '@page'),
    '⚠️ وهو **توكنز بس مش تخطيط** — كل مستند تخطيطه مختلف فعلاً (فاتورة ≠ كشف)');
$printViews = [
    'closing/print.php', 'reports/print.php', 'reps/settlement_print.php',
    'statements/print.php', 'statements/batch_print.php',
    'permits/invoice_print.php', 'permits/_print_styles.php', 'legacy/statement.php',
];
foreach ($printViews as $rel) {
    $src = (string) file_get_contents($root . '/app/Views/' . $rel);
    chk(str_contains($src, "partial('print_base')"), "`$rel` بياخد الهوية من الأساس");
}
/* اللي لسه فيه `:root` خاص لازم يكون **override موثَّق** مش نسخة تانية */
foreach (['permits/_print_styles.php', 'statements/batch_print.php'] as $rel) {
    $src = (string) file_get_contents($root . '/app/Views/' . $rel);
    chk(str_contains($src, 'override مقصود'), "`$rel`: الفرق عن الأساس موثَّق إنه مقصود");
}

// ═══ ⑦ عناوين الأقسام
sec('⑦ عناوين الأقسام بأيقونات SVG');
$emoji = [];
foreach (glob($root . '/app/Views/*/*.php') as $f) {
    if (str_contains($f, '/m/') || str_contains($f, '/partials/')) { continue; }
    $src = (string) file_get_contents($f);
    if (preg_match('/class="section-head"[^>]*>\s*[\x{1F300}-\x{1FAFF}]/u', $src)) {
        $emoji[] = str_replace($root . '/app/Views/', '', $f);
    }
}
chk(!$emoji, 'مفيش إيموجي في أي عنوان قسم' . ($emoji ? ' — لقينا: ' . implode(' · ', $emoji) : ''));

echo "\n" . str_repeat('=', 62) . "\n";
echo 'النتيجة: ' . $pass . ' ناجح · ' . count($failures) . " فاشل\n";
if ($failures) { foreach ($failures as $f) { echo "  ✗ $f\n"; } exit(1); }
exit(0);
