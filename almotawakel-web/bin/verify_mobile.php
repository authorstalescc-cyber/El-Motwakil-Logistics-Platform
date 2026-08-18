<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص تجربة الموبايل — المرحلة ٨ (docs/13-master-redesign-plan.md).
 *
 * قرار المالك (2026-07-25): **«كل صفحات النظام لازم تتظبط على الهاتف»** —
 * النطاق ٩٨ شاشة (٨٥ مكتب + ١٣ بوابة مناديب).
 *
 * بيحرس:
 *   1) كل الجداول بقت **كروت** على الهاتف (كان التمرير الأفقي للـ٢٤ جدول
 *      العريض، أعرضهم ٢٩ عمود — يعني بتقرا عمودين وتسحب)
 *   2) الأعمدة الزيادة بتتطوي خلف «المزيد» فالكارت ما يطولش
 *   3) **صفر `grid-template-columns` inline** — الـinline بيدهس نقاط الكسر
 *   4) كل كلاس بيحقنه `app.js` **له CSS فعلاً** (باج `.table-mobile-scroll`
 *      و`.btn.primary` اللي فضلوا معطّلين بالسكوت)
 *   5) النوافذ ملء الشاشة على الهاتف
 *   6) مساحة لمس ≥ 44px
 *   7) مفيش تمرير أفقي للصفحة
 *   8) **مفيش أثر مالي**
 *
 * آمن تمامًا: **قراءة فقط**. مفيش أي كتابة ولا transaction.
 *
 * التشغيل:  php bin/verify_mobile.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$pass = 0; $fail = 0; $skip = 0; $warn = 0;

function ok(string $m): void   { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  ✗ $m\n"; }
function warn(string $m): void { global $warn; $warn++; echo "  ! $m\n"; }
function skip(string $m): void { global $skip; $skip++; echo "  ~ $m (تخطّي)\n"; }
function sec(string $m): void  { echo "\n" . $m . "\n" . str_repeat('─', 62) . "\n"; }

$root = dirname(__DIR__);
$css  = (string) file_get_contents($root . '/public/assets/css/app.css');
$mcss = (string) file_get_contents($root . '/public/assets/css/m.css');
$js   = (string) file_get_contents($root . '/public/assets/js/app.js');

/** يشيل تعليقات CSS — تعليق بيشرح كود قديم ما يعتبرش تعريفًا له. */
function ccode(string $s): string { return (string) preg_replace('#/\*.*?\*/#s', '', $s); }
/** يشيل تعليقات JS للسبب نفسه. */
function jcode(string $s): string
{
    $s = (string) preg_replace('#/\*.*?\*/#s', '', $s);
    return (string) preg_replace('#^\s*//.*$#m', '', $s);
}
$cssCode = ccode($css);
$jsCode  = jcode($js);

/** كل الشاشات — المكتب **والبوابة** (البوابة دخلت النطاق في الخطة الكبرى). */
function all_views(string $root): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app/Views'));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'php') { continue; }
        $out[$f->getPathname()] = (string) file_get_contents($f->getPathname());
    }
    ksort($out);
    return $out;
}
$views = all_views($root);

echo "\n╔══════════════════════════════════════════════════════════════╗\n";
echo   "║  فحص تجربة الموبايل — المرحلة ٨                              ║\n";
echo   "╚══════════════════════════════════════════════════════════════╝\n";
echo "  الشاشات المفحوصة: " . count($views) . "\n";

/* ══════════════ 1) الجداول ══════════════ */
sec('1) الجداول بقت كروت على الهاتف');

/* الشرط القديم `columns.length <= 6` كان بيقصر وضع الكروت على الجداول
   الضيّقة بس، فالـ٢٤ جدول العريض كانوا بياخدوا تمرير أفقي. */
preg_match('/columns\.length\s*<=\s*6/', $jsCode)
    ? bad('لسه فيه شرط `columns.length <= 6` — الجداول العريضة هتفضل تمرير أفقي')
    : ok('شرط «٦ أعمدة» اتشال — كل الجداول بقت كروت');

str_contains($jsCode, "table.classList.add('table-mobile-cards')")
    ? ok('`app.js` بيضيف `table-mobile-cards`')
    : bad('`app.js` مش بيضيف `table-mobile-cards`');

str_contains($jsCode, "dataset.mobileScroll === '1'")
    ? ok('التمرير الأفقي بقى **اختيار صريح** (`data-mobile-scroll="1"`) مش الافتراضي')
    : bad('مفيش طريقة للخروج من وضع الكروت لجدول محتاج تمرير');

str_contains($jsCode, 'tm-extra') && str_contains($jsCode, 'tm-more')
    ? ok('الأعمدة الزيادة بتتطوي خلف زر «المزيد» جوّه الكارت')
    : bad('مفيش طيّ للأعمدة الزيادة — الكارت هيطول بعدد الأعمدة');

/* ⚠️ الزرار بيتحقن جوّه أول خانة مش كخانة جديدة: أدوات الجداول (ترتيب/إخفاء/
   تغيير عرض) بتشتغل بفهرس الخانة، وخانة زيادة كانت هتزحلق الفهارس. */
str_contains($jsCode, 'first.appendChild(more)')
    ? ok('زر «المزيد» جوّه أول خانة (مش خانة جديدة تزحلق فهارس الأعمدة)')
    : bad('زر «المزيد» ممكن يكون بيتحط كخانة جديدة — ده بيبوّظ أدوات الجداول');

str_contains($jsCode, "row.querySelector('td[colspan]')")
    ? ok('صف حالة الفراغ مستثنى من الطيّ')
    : bad('صف حالة الفراغ مش مستثنى — «المزيد» هيظهر على رسالة «مفيش بيانات»');

/* ══════════════ 2) الكلاسات المحقونة ليها CSS ══════════════ */
sec('2) كل كلاس بيحقنه app.js له CSS فعلاً');

/* الباج الموثَّق: `.table-mobile-scroll` كان بيتحقن من app.js **وما لهوش ولا
   قاعدة CSS واحدة** لفترة، فآلية التمرير كانت معطّلة بالكامل بالسكوت. */
foreach (['table-mobile-cards', 'table-mobile-scroll', 'tm-extra', 'tm-more'] as $cls) {
    str_contains($cssCode, '.' . $cls)
        ? ok("`.$cls` معرَّف في CSS")
        : bad("`.$cls` بيتحقن من JS ومالوش CSS — هيتعطّل بالسكوت");
}

/* `.tm-more` بيتحقن في كل صف، فلازم **يتخفي صراحةً على الشاشة الكبيرة**.
   تعريف شكله جوّه media query لوحده مش كفاية. */
preg_match('/(^|\})\s*\.tm-more\{[^}]*display:\s*none/m', $cssCode)
    ? ok('`.tm-more` مخفي على الكمبيوتر بقاعدة عامة برّه أي media query')
    : bad('`.tm-more` هيظهر على الكمبيوتر — لازم `display:none` عامة');

preg_match('/tbody tr:not\(\.tm-open\) td\.tm-extra\{display:none\}/', $cssCode)
    ? ok('الخانات الزيادة مخفية لحد ما الكارت يتفتح')
    : bad('الخانات الزيادة مش مخفية');

/* ══════════════ 3) الشبكات الـinline ══════════════ */
sec('3) صفر شبكة أعمدة مكتوبة inline');

$inline = [];
foreach ($views as $p => $src) {
    if (preg_match_all('/style="[^"]*grid-template-columns[^"]*"/', $src, $m)) {
        foreach ($m[0] as $hit) { $inline[] = basename(dirname($p)) . '/' . basename($p); }
    }
}
$inline = array_unique($inline);
$inline
    ? bad('شبكات inline بتدهس نقاط الكسر في: ' . implode(' · ', $inline))
    : ok('مفيش أي `grid-template-columns` inline في الـ' . count($views) . ' شاشة');

foreach (['grid-2', 'grid-3', 'form-grid.one', 'form-grid.two'] as $cls) {
    str_contains($cssCode, '.' . $cls)
        ? ok("`.$cls` معرَّف كبديل للـinline")
        : bad("`.$cls` مش معرَّف");
}

/* ⚠️ **مصيدة تخصيص اتصادفت فعليًا (2026-07-26):** الـmedia query ما بتزوّدش
   تخصيص. `.d-kpis.four` (كلاسان) كانت بتغلب `.d-kpis` (كلاس واحد) اللي جوّه
   الـmedia query — فشبكة الأرقام فضلت **٤ أعمدة على موبايل** والصفحة خرجت
   برّه الشاشة. أي مُعدِّل أعمدة لازم يتعدّ **بالاسم** في القاعدة المتجاوبة. */
$modifiers = [];
preg_match_all('/\.d-kpis\.(three|four|five|six)\{grid-template-columns/', $cssCode, $mk);
$modifiers = array_unique($mk[1]);
$mobileGrid = '';
if (preg_match_all('/@media\(max-width:(?:560|820)px\)\{(?:[^{}]|\{[^{}]*\})*\}/', $cssCode, $mm)) {
    $mobileGrid = implode("\n", $mm[0]);
}
$missing = [];
foreach ($modifiers as $mod) {
    if (!preg_match('/\.d-kpis\.' . $mod . '\b/', $mobileGrid)) { $missing[] = $mod; }
}
$missing
    ? bad('⚠️ مُعدِّل شبكة الأرقام مش متعدّ في القاعدة المتجاوبة (هيفضل ٤ أعمدة على الموبايل): ' . implode(', ', $missing))
    : ok('⚠️ كل مُعدِّلات `.d-kpis` متعدّة بالاسم في نقاط كسر الموبايل (مصيدة التخصيص)');

/* ══════════════ 4) النوافذ ══════════════ */
sec('4) النوافذ ملء الشاشة على الهاتف');

/* بنقصّ آخر بلوك موبايل عشان نتأكد إن القواعد جوّه media query فعلاً */
$mobileBlocks = '';
if (preg_match_all('/@media\(max-width:640px\)\{(.*?)\n\}/s', $cssCode, $m)) {
    $mobileBlocks = implode("\n", $m[1]);
}
$mobileBlocks !== ''
    ? ok('فيه بلوك موبايل على 640px')
    : bad('مفيش بلوك موبايل على 640px');

$modals = [
    'd-modal-card'   => 'بوب-أب الداشبورد',
    'form-modal-box' => 'نافذة النماذج',
    'cmdk-panel'     => 'لوحة البحث السريع',
    'nc-panel'       => 'لوحة الإشعارات',
    'confirm-box'    => 'صندوق التأكيد',
];
foreach ($modals as $cls => $label) {
    preg_match('/\.' . preg_quote($cls, '/') . '\{[^}]*(width:\s*100(vw|%)|max-width:\s*100(vw|%)|inset:)/', $mobileBlocks)
        ? ok("$label بياخد عرض الشاشة كامل")
        : bad("$label لسه صندوق صغير وسط شاشة الهاتف");
}

/* ══════════════ 5) مساحة اللمس ══════════════ */
sec('5) مساحة اللمس والفورمات');

preg_match('/min-height:\s*44px/', $cssCode)
    ? ok('قاعدة `min-height:44px` موجودة (الحد الأدنى المتعارف عليه للمس)')
    : bad('مفيش أي قاعدة 44px');

preg_match('/\.form-grid\{grid-template-columns:1fr!important/', $mobileBlocks)
    ? ok('الفورمات بتبقى عمود واحد على الهاتف (بـ`!important` عشان تغلب أي بقايا)')
    : bad('الفورمات مش عمود واحد على الهاتف');

str_contains($mobileBlocks, '.sticky-save')
    ? ok('شريط حفظ سفلي ثابت متاح للفورمات الطويلة (`.sticky-save`)')
    : bad('مفيش شريط حفظ سفلي — زر الحفظ بيبقى تحت بـ٣ شاشات تمرير');

preg_match('/\.toolbar>form,\.filter-form\{[^}]*flex-direction:column/', $mobileBlocks)
    ? ok('شريط الفلاتر بيتكدّس عمودي بدل ما يتزنق')
    : bad('شريط الفلاتر مش بيتكدّس');

/* ══════════════ 6) مفيش تمرير أفقي للصفحة ══════════════ */
sec('6) الصفحة نفسها ما تتمرّرش أفقيًّا');

str_contains($mobileBlocks, 'body{overflow-x:hidden}')
    ? ok('حارس عام: جسم الصفحة ما بيتمرّرش أفقيًّا')
    : bad('مفيش حارس تمرير أفقي');

str_contains($cssCode, 'overflow-x:clip') || str_contains($cssCode, 'overflow-x:hidden')
    ? ok('منطقة المحتوى محميّة من التمدّد')
    : bad('منطقة المحتوى مش محميّة');

/* البوابة `/m` اتصمّمت للموبايل أصلاً — بنتأكد إنها ما اتكسرتش */
str_contains($mcss, 'hm-') ? ok('بوابة المناديب لسه على تصميمها (`hm-`)') : warn('ما لقيتش كلاسات `hm-` في m.css');

/* ⚠️⚠️ **ولا `backdrop-filter` على أي عنصر `position:fixed` في بوابة `/m`.**
   بلاغ المالك (2026-08-10، آيفون): الشريط السفلي كان **بيسيب مكانه تحت
   ويتوسّط الشاشة مع كل سكرول** ويرجع لما السكرول يقف. السبب إن سفاري iOS
   بتفشل في إعادة رسم عنصر ثابت وراه ضبابية أثناء السكرول السريع فبتعرضه في
   مكانه القديم. الضبابية زينة وثبات الشريط وظيفة — فاتشالت.
   الفحص ده بيمنع رجوعها: بيقصّ كل قاعدة فيها `position:fixed` ويشوف لو فيها
   ضبابية خلفية. */
$fixedBlur = [];
if (preg_match_all('~\{[^{}]*position\s*:\s*fixed[^{}]*\}~i', $mcss, $mBlocks)) {
    foreach ($mBlocks[0] as $block) {
        if (stripos($block, 'backdrop-filter') !== false) { $fixedBlur[] = trim(mb_substr($block, 0, 46)) . '…'; }
    }
}
$fixedBlur === []
    ? ok('★ ولا عنصر ثابت وراه ضبابية في `m.css` (باج الشريط السفلي على iOS)')
    : bad('عنصر ثابت + backdrop-filter — بيتوه أثناء السكرول على iOS: ' . implode(' · ', $fixedBlur));

/* والشريط لازم يفضل بسطح **معتم**: الشفافية من غير ضبابية بتخلّي المحتوى
   يبان من ورا الشريط وهو ماشي، وده كان نص المشكلة المرئية. */
if (preg_match('~\.hm-tabs\s*\{[^}]*\}~', $mcss, $tabBlock)) {
    preg_match('~background\s*:\s*([^;]+);~', $tabBlock[0], $bg);
    $bgVal = trim($bg[1] ?? '');
    (stripos($bgVal, 'rgba') === false && stripos($bgVal, 'transparent') === false)
        ? ok('وسطح الشريط السفلي معتم (' . $bgVal . ')')
        : bad('سطح الشريط السفلي شفّاف — المحتوى بيبان من وراه: ' . $bgVal);
} else {
    bad('ما لقيتش قاعدة `.hm-tabs` في m.css');
}

/* ══════════════ 6ب) الشاشات ذات التخطيط الخاص (الدفعة ٨ب) ══════════════ */
sec('6ب) الشاشات ذات التخطيط الخاص');

/* ⚠️ `app.js` بيطبّق أدوات الجداول على `table.data, table.pv-tbl` مع بعض،
   لكن CSS وضع الكروت كان **متقيّد بـ`table.data`** — فـ`.pv-tbl` (جدول
   الأذونات وكارت المندوب بـ٢٩ عمود) كان بياخد الكلاس وما بيحصلش حاجة. */
str_contains($jsCode, "table.data, table.pv-tbl")
    ? ok('`app.js` بيغطّي نظامَي الجداول (`data` و`pv-tbl`)')
    : warn('محدّد الجداول في app.js اتغيّر — راجع تغطية `pv-tbl`');

preg_match('/table\.data\.table-mobile-cards/', $cssCode)
    ? bad('CSS وضع الكروت لسه متقيّد بـ`table.data` — جداول `pv-tbl` مش هتتحوّل')
    : ok('CSS وضع الكروت عام (مش متقيّد بـ`table.data`)');

/* `.pv-tbl` عليه `min-width:960px` صريح — من غير تحييده الجدول بيخرج برّه
   شاشة الهاتف مهما عملنا في وضع الكروت. */
preg_match('/\.permits-v2 \.pv-tbl\.table-mobile-cards\{min-width:0\}/', $mobileBlocks)
    ? ok('العرض الثابت 960px لجدول الأذونات متحيَّد على الهاتف')
    : bad('`min-width:960px` لسه شغّال على الهاتف — الجدول هيخرج برّه الشاشة');

/* ⚠️ العروض الثابتة inline: مستحيل يتغلب عليها من stylesheet بلا `!important` */
$inlineW = 0;
foreach ($views as $p => $src) {
    if (str_contains($p, 'print')) { continue; }            // الطباعة A4 عروضها الثابتة صح
    $inlineW += preg_match_all('/style="[^"]*(?:min-)?width:\s*\d{3,}px/', $src);
}
preg_match('/width:100%!important/', $mobileBlocks)
    ? ok("العروض الثابتة inline ($inlineW موضع) متحيَّدة في شرائط الأدوات والفلاتر")
    : bad("$inlineW عرض ثابت inline بلا تحييد — الفلاتر هتفضل متزنقة على الهاتف");

/* الشبكات ذات العدد الثابت في الشاشات (مش الطباعة).
   ⚠️ `.detail-grid` اتشالت من القايمة (v103): الكلاس نفسه **اتحذف** من `app.css`
      لما جسم شاشة الإذن اتعاد بناؤه بصفوف `pv3-*` — فالفحص كان بيطلب قاعدة
      متجاوبة لكلاس مش موجود أصلاً. وبديله `.pv3-grid` تحته. */
/* ⚠️ **الفحص على السلوك مش على تهجئة الميديا-كويري:** `$mobileBlocks` بيقصّ
   `@media(max-width:640px)` **بلا مسافة** بس. الشبكة ممكن تنزل عمودًا واحدًا
   عند ٩٠٠ أو ١١٠٠ (وده أصحّ للتابلت)، والقاعدة المقصودة هي «ما تفضلش بعدد
   أعمدة ثابت على شاشة ضيّقة» — فبنجمّع **كل** نقاط الكسر ≤١١٠٠ بأي تهجئة. */
$narrowBlocks = '';
if (preg_match_all('/@media\s*\(max-width:\s*(\d+)px\)\s*\{(.*?)\n\}/s', $cssCode, $nm, PREG_SET_ORDER)) {
    foreach ($nm as $blk) { if ((int) $blk[1] <= 1100) { $narrowBlocks .= "\n" . $blk[2]; } }
}
/* ⚠️ `.hero-tiles` و`.pv3-grid` اتشالوا من `app.css` مع إعادة بناء شاشة الإذن
   (v104): الهيرو الأفقي بقى ريل ملاصق وشبكته بقت `.pf-page`. */
foreach (['set-figs', 'stat-row', 'pf-page'] as $cls) {
    str_contains($narrowBlocks, '.' . $cls)
        ? ok("`.$cls` بينزل عمود واحد على الهاتف")
        : bad("`.$cls` لسه بعدد أعمدة ثابت على الهاتف");
}

/* ══════════════ 6ج) التصدير وإشارة القطع (الدفعة ٨ج) ══════════════ */
sec('6ج) التصدير وإشارة القطع');

/* التصدير العام موجود على **كل** جدول من `app.js` — مش محتاج أي كود في
   الكنترولر. (تصحيح: الادّعاء الأول إن التصدير في وحدتين بس كان بيقصد
   مسارات CSV الخادمية؛ التصدير من المتصفح بيغطّي كل الجداول.) */
str_contains($jsCode, 'table-export-btn')
    ? ok('زر تصدير CSV موجود على كل جدول تلقائيًّا')
    : bad('زر التصدير العام اتشال');

str_contains($jsCode, "'\\ufeff'") || str_contains($js, '﻿')
    ? ok('التصدير بيكتب BOM (الاسم العربي بيتفتح صح في إكسل)')
    : bad('التصدير بلا BOM — العربي هيطلع رموز في إكسل');

/* ⚠️ زر «المزيد» بيتحقن جوّه أول خانة، و`cellValue` بترجّع `textContent` —
   يعني نصّه كان هيتسرّب لـ**تصدير CSV** ولـ**ترتيب العمود الأول** ولكشف نوع
   العمود، فيبوّظ التلاتة بالسكوت. */
preg_match('/cell\.querySelector\(.\.tm-more.\)[\s\S]{0,300}?cloneNode\(true\)/', $jsCode)
    ? ok('`cellValue` بتستبعد زر «المزيد» (التصدير والترتيب ما يتلوّثوش)')
    : bad('زر «المزيد» هيتسرّب لتصدير CSV وترتيب العمود الأول');

/* «مفيش قطع صامت»: أي شاشة سقفها ثابت لازم تقول للمستخدم إنها قطعت */
$notes = 0;
foreach ($views as $p => $src) {
    $notes += preg_match_all("/partial\(\s*'count_note'/", $src);   // عدد الاستدعاءات مش عدد الملفات
}
$notes >= 3
    ? ok("إشارة القطع مستخدمة في $notes موضع (الحركات + سجل العمليات + السلة)")
    : bad("إشارة القطع في $notes موضع بس — الشاشات ذات السقف الثابت لسه بتقطع بالسكوت");

/* الإجمالي لازم يتحسب بـ**نفس شرط** القائمة وإلا الرقم بيكدب */
$audit = (string) file_get_contents($root . '/app/Models/Audit.php');
str_contains($audit, 'private static function recentWhere')
    ? ok('شرط الفلترة مصدر واحد لـ`recent()` و`countRecent()` (مستحيل يفترقوا)')
    : bad('شرط الفلترة متكرّر — الإجمالي ممكن يخالف القائمة');

/* ══════════════ 7) جرد نقاط الكسر (تقرير) ══════════════ */
sec('7) جرد نقاط الكسر');

/* ⚠️ لازم نعدّ رؤوس `@media` بس — العدّ الخام لـ`max-width:Npx` بيلقط كمان
   **خاصية `max-width` على العناصر** (زي `.login-card{max-width:400px}`)
   وبيدّي رقمًا مضخَّمًا. الفرق مش تفصيلة: العدّ الخام قال ١٩ والحقيقة ١١. */
preg_match_all('/@media[^{]*max-width:\s*(\d+)px/', $cssCode, $bp);
$counts = array_count_values($bp[1]);
krsort($counts, SORT_NUMERIC);
$distinct = count($counts);
echo '  نقاط الكسر الحقيقية (رؤوس @media بس): ';
foreach ($counts as $px => $n) { echo $px . 'px×' . $n . '  '; }
echo "\n";
/* التوحيد **اتجرَّب واترجع عن قصد** — شوف docs/13 القسم ١٠ج:
   الدمج ولّد ٨ تصادمات، أوضحها إن زر قائمة الموبايل كان هيظهر على شاشات
   الكمبيوتر (821–1100px). المكسب تنظيم داخلي والخسارة ارتداد على شاشات
   حقيقية — فالقرار: يفضل زي ما هو لحد ما يتعمل بمراجعة بصرية لكل بلوك. */
if ($distinct <= 11) {
    ok("عدد نقاط الكسر = $distinct (التوحيد مؤجَّل بقرار موثَّق — مش دين منسي)");
} else {
    bad("عدد نقاط الكسر بقى $distinct — زاد عن خط الأساس ١١، فيه نقطة كسر جديدة اتضافت");
}

/* ══════════════ 7ب) توكنز الألوان (الدفعة ٨د) ══════════════ */
sec('7ب) توكنز الألوان');

/* ⚠️ **تعريف دائري = لون مكسور بالكامل.** أول محاولة للتحويل الآلي كتبت
   `--gold-dark:var(--gold-dark)` لأن حدّ `:root` اتحسب من نسخة **مشيلة
   التعليقات** والإزاحات اتطبّقت على النص الأصلي — فالحدّ طلع غلط والتحويل
   دخل جوّه `:root` نفسه. الفحص ده بيمنع رجوعها. */
if (preg_match('/:root\s*\{/', $css, $rm, PREG_OFFSET_CAPTURE)) {
    $i = $rm[0][1] + strlen($rm[0][0]); $depth = 1; $len = strlen($css);
    while ($i < $len && $depth > 0) {
        if ($css[$i] === '{') { $depth++; } elseif ($css[$i] === '}') { $depth--; }
        $i++;
    }
    $rootTxt = substr($css, $rm[0][1], $i - $rm[0][1]);
    $circular = [];
    if (preg_match_all('/(--[\w-]+)\s*:\s*var\(\s*(--[\w-]+)\s*\)/', $rootTxt, $cm, PREG_SET_ORDER)) {
        foreach ($cm as $c) { if ($c[1] === $c[2]) { $circular[] = $c[1]; } }
    }
    $circular
        ? bad('توكن بيشاور على نفسه (لون مكسور): ' . implode(', ', $circular))
        : ok('مفيش توكن دائري في `:root`');

    /* كل توكن لون لازم يفضل قيمة hex صريحة — مش var() لتوكن تاني اتشال */
    preg_match_all('/(--[\w-]+)\s*:\s*var\(\s*(--[\w-]+)\s*\)/', $rootTxt, $vm, PREG_SET_ORDER);
    $dangling = [];
    foreach ($vm as $v) {
        if (!preg_match('/' . preg_quote($v[2], '/') . '\s*:/', $rootTxt)) { $dangling[] = $v[1]; }
    }
    $dangling ? bad('توكن بيشاور على توكن مش معرَّف: ' . implode(', ', $dangling))
              : ok('كل التوكنز بتشاور على قيم معرَّفة');
} else {
    bad('مش لاقي `:root` في `app.css`');
}

/* `#ffffff` **غامض عن قصد**: ليه توكنين (`--card` خلفية بطاقة و`--white` نص
   على داكن)، والفرق بينهم بيبان بس في الوضع الليلي. تحويله آليًّا هيخلط
   الاتنين ويكسر الوضع الليلي بدل ما يجهّزه — فمتسايب لمرحلة الوضع الليلي. */
$whiteUses = preg_match_all('/#(?:fff|ffffff)\b/i', substr($cssCode, strpos($cssCode, '}')));
$whiteUses > 0
    ? ok("`#fff` ($whiteUses استخدام) متسايب عن قصد — غامض بين `--card` و`--white`")
    : skip('مفيش استخدامات `#fff`');

/* ══════════════ 7ج) تأكيد الحذف في بوابة المندوب ══════════════
   البوابة **ما بتحمّلش `app.js`** — واللي فيه معالج `form.confirm-delete`.
   فأي شاشة `/m` بتطبع الكلاس من غير المعالج المشترك بتحذف **بلا سؤال**،
   والماركب نفسه بيقول إنها المفروض تسأل. اتصاد فعليًّا في `m/permit_info.php`
   (زرار شيل الورقة). الفحص بيقيس **السلوك**: الكلاس موجود ← الوحدة محمَّلة. */
sec('7ج) تأكيد الحذف في بوابة المندوب');

/**
 * كود الشاشة بلا تعليقات — إلزامية قبل أي فحص نصّي (المصيدة الموثَّقة):
 * التعليق اللي بيوثّق القاعدة بيذكر النص الممنوع، فالفحص كان بينجح أو يفشل كذبًا.
 * بيشيل `/* … *​/` و`//` و`#` — تعليقات PHP والجافاسكريبت مع بعض.
 */
function mcode(string $s): string
{
    $s = (string) preg_replace('~/\*.*?\*/~s', '', $s);
    $s = (string) preg_replace('~^\s*//.*$~m', '', $s);
    return (string) preg_replace('~^\s*#(?!\[).*$~m', '', $s);
}

$mcPath = $root . '/public/assets/js/m-confirm.js';
$mcSrc  = is_file($mcPath) ? (string) file_get_contents($mcPath) : '';

is_file($mcPath)
    ? ok('`assets/js/m-confirm.js` موجود — المصدر الوحيد لتأكيد الحذف في `/m`')
    : bad('`assets/js/m-confirm.js` ناقص — شاشات `/m` هتحذف بلا تأكيد');

/* بيتحمّل من `_pwa.php` — الملف اللي **كل** شاشة `/m` بتضمّنه في `<head>` */
$pwa = (string) @file_get_contents($root . '/app/Views/m/_pwa.php');
str_contains($pwa, 'm-confirm.js')
    ? ok('`m/_pwa.php` بيحمّل الوحدة — يعني كل شاشة في البوابة مغطّاة')
    : bad('`m/_pwa.php` مش بيحمّل `m-confirm.js` — التغطية بتبقى شاشة شاشة');

/* التفويض على `document` مش `querySelectorAll` وقت التحميل: الصفوف اللي
   بتتضاف بالجافاسكريبت بعد التحميل لازم تاخد التأكيد هي كمان. */
str_contains($mcSrc, "document.addEventListener('submit'")
    ? ok('الربط بالتفويض على `document` — الفورمات المضافة لاحقًا مغطّاة')
    : bad('`m-confirm.js` بيربط وقت التحميل بس — أي فورم بيتضاف بعدين بيفلت');

/* الشاشات اللي بتطبع الكلاس لازم تكون في البوابة وتضمّ `_pwa.php` */
$mViews = [];
foreach (glob($root . '/app/Views/m/*.php') ?: [] as $f) {
    $src = mcode((string) file_get_contents($f));
    if (str_contains($src, 'confirm-delete')) { $mViews[basename($f)] = $src; }
}
if (!$mViews) {
    skip('مفيش شاشة `/m` بتطبع `confirm-delete`');
} else {
    $naked = [];
    foreach ($mViews as $name => $src) {
        if (!str_contains($src, '_pwa.php') && !str_contains($src, "require __DIR__ . \"/_pwa.php\"")) {
            $naked[] = $name;
        }
    }
    $naked
        ? bad('شاشة بتطلب تأكيدًا بلا `_pwa.php`: ' . implode(', ', $naked))
        : ok(count($mViews) . ' شاشة بتطبع `confirm-delete` وكلها بتضمّ `_pwa.php`');
}

/* ⚠️ **نسخة تانية من المعالج = المندوب يتسأل مرتين على نفس الحذف.** المعالج
   كان عايشًا جوّه `m/home.php`؛ الفحص ده بيمنع رجوعه أو تكراره في شاشة تانية. */
$dupes = [];
foreach (glob($root . '/app/Views/m/*.php') ?: [] as $f) {
    $src = mcode((string) file_get_contents($f));
    if (preg_match('~(querySelectorAll|addEventListener)[^\n]{0,80}confirm-delete~', $src)
        || preg_match('~confirm-delete[^\n]{0,80}(addEventListener|forEach)~', $src)) {
        $dupes[] = basename($f);
    }
}
$dupes
    ? bad('معالج تأكيد مكرّر جوّه شاشة: ' . implode(', ', $dupes) . ' — الوحدة المشتركة هي المصدر الوحيد')
    : ok('مفيش معالج تأكيد مكتوب بالإيد في أي شاشة — المصدر واحد');

/* ══════════════ 8) العزل المالي ══════════════ */
sec('8) العزل المالي — المرحلة ٨ عرض بحت');

try {
    $b1 = (float) \App\Models\Custody::balance();
    $b2 = (float) \App\Models\Custody::balance();
    abs($b1 - $b2) < 0.001
        ? ok('رصيد خزنة المكتب ثابت (' . number_format($b1, 2) . ') — مفيش أي كتابة')
        : bad('⚠️ رصيد الخزنة اتغيّر أثناء الفحص');
} catch (\Throwable $e) {
    skip('تعذّر قياس رصيد الخزنة: ' . $e->getMessage());
}

/* الملفات اللي اتلمست في الدفعة دي كلها عرض — ولا واحد فيها منطق مالي */
$touched = ['public/assets/css/app.css', 'public/assets/js/app.js'];
$dirty = [];
foreach ($touched as $rel) {
    $src = (string) file_get_contents($root . '/' . $rel);
    foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM'] as $needle) {
        if (str_contains($src, $needle)) { $dirty[] = "$rel ($needle)"; }
    }
}
$dirty ? bad('ملف عرض فيه SQL: ' . implode(', ', $dirty))
       : ok('ملفات العرض المعدَّلة مفيهاش أي SQL');

/* ══════════════ الخلاصة ══════════════ */
echo "\n" . str_repeat('=', 62) . "\n";
echo "نجح: $pass   |   فشل: $fail   |   تنبيه: $warn   |   تخطّي: $skip\n";
if ($fail > 0) {
    echo "\n✗ الفحص فشل — الكود ده ما يترفعش.\n";
    exit(1);
}
echo "\n✓ كل الفحوصات عدّت.\n";
exit(0);
