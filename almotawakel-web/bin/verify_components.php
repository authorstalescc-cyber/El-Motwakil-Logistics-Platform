<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص مكتبة المكوّنات المشتركة — المرحلة ٧ (docs/13-master-redesign-plan.md).
 *
 * المرحلة ٧ عقدها: **أساسات بلا أي تغيير مرئي**. الفحص ده بيحرس العقد ده:
 *
 *   1) `partial()`/`partial_str()`/`partial_body()` موجودين وشغّالين
 *   2) **مصيدة تصادم المفاتيح** — متغيّرات `partial()` الداخلية كلها `__p_`
 *      (نفس مصيدة `Controller::render` اللي معاملها اسمه `$data` فبيبلع أي
 *      مفتاح بنفس الاسم **بالسكوت**)
 *   3) كل مكوّن **بيرندر فعليًّا** بلا خطأ، ومخرجه فيه الكلاسات المتوقّعة
 *   4) **التهريب** — قيمة فيها `<script>` بتخرج مهرَّبة من كل مكوّن
 *   5) مفيش `json_encode` في أي مكوّن (قاعدة أمنية قايمة — الصح `js_json()`)
 *   6) **بلا تغيير مرئي**: المكوّنات الجديدة (`page_head`/`tabs`/`count_note`)
 *      مش مستخدمة في أي شاشة قائمة، والكلاسات الجديدة كلها معرَّفة في `app.css`
 *   7) `partial()` مش بيتنادى باسم مكوّن مش موجود في أي شاشة
 *   8) **مفيش أثر مالي** — المكوّنات عرض بحت
 *
 * آمن تمامًا: **قراءة فقط** + رندر في الذاكرة. مفيش أي كتابة ولا transaction.
 *
 * التشغيل:  php bin/verify_components.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$pass = 0; $fail = 0; $skip = 0;

function ok(string $m): void   { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  ✗ $m\n"; }
function skip(string $m): void { global $skip; $skip++; echo "  ~ $m (تخطّي)\n"; }
function sec(string $m): void  { echo "\n" . $m . "\n" . str_repeat('─', 62) . "\n"; }

$root     = dirname(__DIR__);
$partDir  = $root . '/app/Views/partials';
$css      = (string) file_get_contents($root . '/public/assets/css/app.css');
$helpers  = (string) file_get_contents($root . '/app/Core/helpers.php');

/** يشيل تعليقات PHP — تعليق بيشرح نمطًا ما يعتبرش استخدامًا له. */
function pcode(string $s): string
{
    $s = (string) preg_replace('#/\*.*?\*/#s', '', $s);
    return (string) preg_replace('#^\s*//.*$#m', '', $s);
}

/** كل شاشات المكتب (بلا بوابة المناديب وبلا المكوّنات نفسها). */
function office_views_only(string $root): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app/Views'));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'php') { continue; }
        $p = $f->getPathname();
        if (str_contains($p, '/Views/m/') || str_contains($p, '/Views/partials/')) { continue; }
        $out[$p] = (string) file_get_contents($p);
    }
    ksort($out);
    return $out;
}
$views = office_views_only($root);

echo "\n╔══════════════════════════════════════════════════════════════╗\n";
echo   "║  فحص مكتبة المكوّنات المشتركة — المرحلة ٧                    ║\n";
echo   "╚══════════════════════════════════════════════════════════════╝\n";

/* ══════════════ 1) الدوال الأساسية ══════════════ */
sec('1) دوال الاستدعاء');

foreach (['partial', 'partial_str', 'partial_body'] as $fn) {
    function_exists($fn) ? ok("`$fn()` معرَّفة") : bad("`$fn()` مش معرَّفة");
}

/* ⚠️ مصيدة تصادم المفاتيح: لو أي متغيّر داخلي في partial() اتسمّى باسم عادي
   (زي $name أو $file)، أول استدعاء بيمرّر مفتاح بنفس الاسم هيدهسه أو يتخطّى
   بالسكوت. نفس مصيدة Controller::render اللي معاملها اسمه $data. */
if (preg_match('/function partial\(([^)]*)\)/', $helpers, $m)) {
    preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)/', $m[1], $vars);
    $bad = array_filter($vars[1], fn($v) => !str_starts_with($v, '__p_'));
    $bad
        ? bad('متغيّرات `partial()` مش كلها `__p_`: ' . implode(', ', $bad))
        : ok('متغيّرات `partial()` كلها ببادئة `__p_` (مفيش تصادم مع مفاتيح الاستدعاء)');
} else {
    bad('مش لاقي توقيع `partial()`');
}

// نفس الحاجة لجسم الدالة
if (preg_match('/function partial\(.*?\n\}/s', $helpers, $m)) {
    preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=/', $m[0], $assigned);
    $bad = array_filter($assigned[1], fn($v) => !str_starts_with($v, '__p_'));
    $bad
        ? bad('متغيّرات داخل جسم `partial()` مش `__p_`: ' . implode(', ', $bad))
        : ok('جسم `partial()` ما بيعرّفش أي متغيّر ممكن يتصادم');
}

str_contains($helpers, 'EXTR_SKIP')
    ? ok('`partial()` بتستخدم `EXTR_SKIP` (المكوّن ما يدهسش متغيّراته الداخلية)')
    : bad('`partial()` مش بتستخدم `EXTR_SKIP`');

/* ══════════════ 2) الرندر الفعلي ══════════════ */
sec('2) رندر كل مكوّن فعليًّا');

$XSS = '</script><img src=x onerror=alert(1)>';

/** يرندر مكوّنًا ويرجّع الناتج، أو null لو رمى استثناء. */
function try_render(string $name, array $vars): ?string
{
    try { return partial_str($name, $vars); }
    catch (\Throwable $e) { echo "    (استثناء: " . $e->getMessage() . ")\n"; return null; }
}

$cases = [
    'empty' => [
        'vars'   => ['emoji' => '📭', 'title' => $XSS, 'note' => 'وصف', 'action' => ['label' => 'زر', 'url' => '/x']],
        'expect' => ['class="empty"', '<b>', 'btn sm'],
    ],
    'pager' => [
        'vars'   => ['page' => 3, 'pages' => 9, 'qs' => fn(array $o) => http_build_query($o)],
        'expect' => ['class="pager"', 'class="cur"', 'السابق', 'التالي'],
    ],
    'kpi' => [
        'vars'   => ['label' => $XSS, 'value' => '1,200', 'tone' => 'gold', 'unit' => 'ج.م', 'note' => 'ملاحظة'],
        'expect' => ['class="kpi gold"', 'class="lbl"', 'class="val"', 'kpi-note'],
    ],
    'kpi_grid' => [
        'vars'   => ['items' => 3, 'body' => '<i>x</i>'],
        'expect' => ['kpi-grid three', '<i>x</i>'],
    ],
    'card' => [
        'vars'   => ['title' => $XSS, 'sub' => 'وصف', 'body' => '<p>محتوى</p>'],
        'expect' => ['class="card"', 'card-head', 'section-head', '<p>محتوى</p>'],
    ],
    'table' => [
        'vars'   => ['cols' => ['أ', ['label' => 'ب', 'class' => 'g-identity']], 'rows' => '<tr><td>1</td><td>2</td></tr>', 'count' => 1],
        'expect' => ['table-wrap', 'table class="data"', 'g-identity', '<tr><td>1</td>'],
    ],
    'toolbar' => [
        'vars'   => ['title' => $XSS, 'count' => '5 بند', 'end' => '<a class="btn">زر</a>'],
        'expect' => ['class="toolbar"', 'class="spacer"', 'count-badge'],
    ],
    'filters' => [
        'vars'   => ['action' => '/expenses', 'active' => true, 'reset' => '/expenses', 'fields' => [
            ['type' => 'search', 'name' => 'q', 'value' => $XSS, 'placeholder' => 'بحث'],
            ['type' => 'select', 'name' => 's', 'value' => 'a', 'options' => ['a' => 'أ', 'b' => 'ب']],
            ['type' => 'hidden', 'name' => 'h', 'value' => '1'],
        ]],
        'expect' => ['method="get"', 'filter-form', '<select', 'type="hidden"', 'إلغاء'],
    ],
    'field' => [
        'vars'   => ['name' => 'amount', 'label' => $XSS, 'type' => 'number', 'value' => '10',
                     'required' => true, 'hint' => 'تلميح', 'errors' => []],
        'expect' => ['class="field"', '<label for="f-amount"', 'class="req"', 'required', 'class="hint"'],
    ],
    'tabs' => [
        'vars'   => ['items' => [['label' => $XSS, 'url' => '/a', 'active' => true, 'count' => 7],
                                 ['label' => 'ب', 'url' => '/b']]],
        'expect' => ['class="tabs"', 'class="tab active"', 'aria-current="page"', 'tab-cnt'],
    ],
    'page_head' => [
        'vars'   => ['title' => $XSS, 'sub' => 'وصف', 'back' => ['label' => 'رجوع', 'url' => '/x'],
                     'primary' => ['label' => 'جديد', 'url' => '/new']],
        'expect' => ['class="page-head"', '<h1 class="page-h1"', 'page-back', 'page-head-actions'],
    ],
    'count_note' => [
        'vars'   => ['shown' => 300, 'total' => 1240, 'unit' => 'صف'],
        'expect' => ['count-note', '300', '1,240'],
    ],
];

$rendered = [];
foreach ($cases as $name => $c) {
    $out = try_render($name, $c['vars']);
    if ($out === null || trim($out) === '') { bad("المكوّن `$name` ما رندرش"); continue; }
    if (str_contains($out, 'partial مفقود')) { bad("المكوّن `$name` مش موجود على القرص"); continue; }
    $rendered[$name] = $out;
    $missing = array_values(array_filter($c['expect'], fn($n) => !str_contains($out, $n)));
    $missing
        ? bad("`$name` رندر بس ناقصه: " . implode(' · ', $missing))
        : ok("`$name` بيرندر صح وفيه كل الكلاسات المتوقّعة");
}

/* ══════════════ 3) التهريب ══════════════ */
sec('3) التهريب — قيمة خبيثة ما تخرجش خام');

foreach ($rendered as $name => $out) {
    if (!str_contains(json_encode($cases[$name]['vars']), 'onerror')) { continue; }
    str_contains($out, '<img src=x onerror')
        ? bad("`$name` بيطبع HTML خام من قيمة المستخدم (XSS مخزَّن)")
        : ok("`$name` بيهرّب قيم المستخدم");
}

/* ⚠️ قاعدة أمنية قايمة: ممنوع `json_encode` في أي ملف داخل app/Views —
   بتسيب `/` فنص «</script>» بيقفل الوسم. الصح `js_json()`. */
$je = [];
foreach (glob($partDir . '/*.php') as $f) {
    if (preg_match('/\bjson_encode\s*\(/', pcode((string) file_get_contents($f)))) { $je[] = basename($f); }
}
$je ? bad('`json_encode` في مكوّنات: ' . implode(', ', $je)) : ok('مفيش `json_encode` في أي مكوّن (الصح `js_json()`)');

/* ══════════════ 4) بلا تغيير مرئي ══════════════ */
sec('4) عقد المرحلة ٧ — بلا أي تغيير مرئي');

/* المكوّنات دي شكلها **جديد**، فلو اتنادت من شاشة قائمة يبقى المرحلة غيّرت
   شكل شاشة — وده كسر لعقد المرحلة ٧. التبنّي مكانه المراحل ٨ و٩.
   ⚠️ **المكوّنات بتتشال من القائمة دي وقت ما تتبنّى فعلاً:**
      • `count_note` اتشال في الدفعة ٨ج — اتبنّى في `movements` و`audit`
        عشان قاعدة «مفيش قطع صامت».
      • `tabs` اتشال في المرحلة ٩ — اتبنّى في شريط «أصحاب البيان» الموحّد.
      • `page_head` اتشال — اتبنّى في شاشتَي `/notifications` (القائمة والتفضيلات).
      أي مكوّن بيتبنّى لازم يتشال من هنا **في نفس الدفعة**، وإلا الفحص بيفشل
      صح وإحنا نظن إنه إنذار كاذب. */
$newLook = [];
$used = [];
foreach ($views as $path => $src) {
    foreach ($newLook as $n) {
        if (preg_match("/partial(_str)?\(\s*['\"]" . preg_quote($n, '/') . "['\"]/", pcode($src))) {
            $used[] = basename(dirname($path)) . '/' . basename($path) . " → $n";
        }
    }
}
$used
    ? bad('مكوّن بشكل جديد اتنادى من شاشة قائمة (المرحلة ٧ لازم تفضل بلا تغيير مرئي): ' . implode(' · ', $used))
    : ok('المكوّنات ذات الشكل الجديد لسه مش مستخدمة في أي شاشة (بلا تغيير مرئي)');

/* كل كلاس جديد بيطبعه أي مكوّن لازم يكون معرَّف في app.css — وإلا بيقع على
   الشكل الافتراضي بالسكوت (نفس باج `.btn.primary` و`.table-mobile-scroll`). */
$newClasses = [
    'card-head', 'card-head-tools', 'kpi-note', 'kpi-grid.two',
    'toolbar-title', 'filter-form', 'filter-lbl', 'tab-ic', 'tab-lbl', 'tab-cnt',
    'page-head', 'page-h1', 'page-back', 'page-head-actions',
    'count-note',
];
$undef = array_values(array_filter($newClasses, fn($c) => !str_contains($css, '.' . $c)));
$undef
    ? bad('كلاسات بيطبعها المكوّن وغير معرَّفة في CSS: ' . implode(', ', $undef))
    : ok('كل الكلاسات الجديدة (' . count($newClasses) . ') معرَّفة في `app.css`');

/* المكوّنات اللي ليها شكل قائم لازم تستعمل كلاساته الأصلية، مش تخترع جديد. */
$reuse = ['empty' => 'class="empty"', 'pager' => 'class="pager"', 'kpi' => 'class="kpi',
          'card' => 'class="card', 'table' => 'table-wrap', 'toolbar' => 'class="toolbar',
          'field' => 'class="field', 'tabs' => 'class="tabs"'];
$broke = [];
foreach ($reuse as $n => $needle) {
    if (isset($rendered[$n]) && !str_contains($rendered[$n], $needle)) { $broke[] = $n; }
}
$broke
    ? bad('مكوّنات ما استعملتش الكلاس القائم: ' . implode(', ', $broke))
    : ok('المكوّنات ذات الشكل القائم بتستعمل كلاساته الأصلية (التحويل بلا فرق بصري)');

/* ══════════════ 5) سلامة الاستدعاءات ══════════════ */
sec('5) سلامة الاستدعاءات في الشاشات');

$known = array_map(fn($p) => basename($p, '.php'), glob($partDir . '/*.php'));
$missingCalls = [];
foreach ($views as $path => $src) {
    if (!preg_match_all("/partial(?:_str)?\(\s*['\"]([a-z_0-9\/]+)['\"]/", pcode($src), $m)) { continue; }
    foreach ($m[1] as $n) {
        if (!in_array($n, $known, true)) {
            $missingCalls[] = basename(dirname($path)) . '/' . basename($path) . " → $n";
        }
    }
}
$missingCalls
    ? bad('استدعاء لمكوّن مش موجود: ' . implode(' · ', $missingCalls))
    : ok('كل استدعاءات `partial()` بتشاور على مكوّن موجود');

/* `partial()` بتطبع تعليق واضح لو المكوّن مش موجود بدل ما تفشل بالسكوت */
$probe = partial_str('__does_not_exist__');
str_contains($probe, 'partial مفقود')
    ? ok('المكوّن الناقص بيبان في مصدر الصفحة بدل ما يختفي بالسكوت')
    : bad('المكوّن الناقص بيفشل بالسكوت');

/* ══════════════ 6) العزل المالي ══════════════ */
sec('6) العزل المالي — المكوّنات عرض بحت');

$fin = ['INSERT', 'UPDATE ', 'DELETE', 'Database::', 'Closing::', 'Custody::', 'RepCustody::'];
$dirty = [];
foreach (glob($partDir . '/*.php') as $f) {
    $src = pcode((string) file_get_contents($f));
    foreach ($fin as $needle) {
        if (str_contains($src, $needle)) { $dirty[] = basename($f) . " ($needle)"; }
    }
}
$dirty
    ? bad('مكوّن بيلمس القاعدة أو حسابًا ماليًّا: ' . implode(', ', $dirty))
    : ok('مفيش مكوّن بيلمس القاعدة ولا أي حساب مالي');

/* رصيد الخزنة قبل = بعد (نفس قاعدة كل فحوصات المشروع) */
try {
    $b1 = (float) \App\Models\Custody::balance();
    foreach ($cases as $n => $c) { try_render($n, $c['vars']); }
    $b2 = (float) \App\Models\Custody::balance();
    abs($b1 - $b2) < 0.001
        ? ok('رصيد خزنة المكتب ما اتغيّرش (قبل ' . number_format($b1, 2) . ' = بعد)')
        : bad('⚠️ رصيد الخزنة اتغيّر: ' . $b1 . ' → ' . $b2);
} catch (\Throwable $e) {
    skip('تعذّر قياس رصيد الخزنة: ' . $e->getMessage());
}

/* ══════════════ 7) التوثيق ══════════════ */
sec('7) التوثيق');

is_file($partDir . '/README.md')
    ? ok('`app/Views/partials/README.md` موجود')
    : bad('مفيش README للمكتبة');

$undoc = [];
$readme = is_file($partDir . '/README.md') ? (string) file_get_contents($partDir . '/README.md') : '';
foreach ($known as $n) {
    if (!str_contains($readme, $n . '.php')) { $undoc[] = $n; }
}
$undoc ? bad('مكوّنات مش في جدول README: ' . implode(', ', $undoc))
       : ok('كل المكوّنات (' . count($known) . ') موثّقة في README');

/* ══════════════ الخلاصة ══════════════ */
echo "\n" . str_repeat('=', 62) . "\n";
echo "نجح: $pass   |   فشل: $fail   |   تخطّي: $skip\n";
if ($fail > 0) {
    echo "\n✗ الفحص فشل — الكود ده ما يترفعش.\n";
    exit(1);
}
echo "\n✓ كل الفحوصات عدّت.\n";
exit(0);
