<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص طبقة تجربة الاستخدام (docs/11-ux-redesign-plan.md).
 *
 * ده **سكربت الحراسة الدائم** لكل مراحل إعادة التصميم — مش فحص مرحلة واحدة.
 * كل مرحلة بتضيف عليه أقسامها، والأقسام القديمة بتفضل تحرس اللي اتعمل قبلها.
 *
 * المرحلة ٠ (الأساسات) — بيتأكد من:
 *   1) توكنز التصميم موجودة في :root
 *   2) المتغيّرات المكسورة اتصلّحت وما رجعتش (var(--text) وvar(--rule) برّه نطاقه)
 *   3) `.btn.primary` معرَّف (كان مستعمل في 5 مواضع وغير معرَّف)
 *   4) `flash()` بقت طابور و`flash_all()` موجودة وشغّالة
 *   5) `old()`/`$_SESSION['_old']` اتشالت وما رجعتش
 *   6) مفيش `json_encode` في أي view (قاعدة أمنية قايمة من المرحلة الأمنية ١)
 *   7) **مفيش أثر مالي** — الطبقة دي عرض بحت
 *
 * آمن تمامًا: **قراءة فقط**، مفيش أي كتابة ولا transaction.
 *
 * التشغيل:  php bin/verify_ux.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$pass = 0; $fail = 0; $skip = 0;

function ok(string $m): void   { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  ✗ $m\n"; }
function skip(string $m): void { global $skip; $skip++; echo "  ~ $m (تخطّي)\n"; }
function sec(string $m): void  { echo "\n" . $m . "\n" . str_repeat('─', 62) . "\n"; }

$root    = dirname(__DIR__);
$cssPath = $root . '/public/assets/css/app.css';
$css     = is_file($cssPath) ? (string) file_get_contents($cssPath) : '';
$helpers = (string) file_get_contents($root . '/app/Core/helpers.php');

/** يشيل تعليقات CSS قبل الفحص — تعليق بيشرح كود قديم ما يعتبرش استخدامًا له. */
function css_code(string $s): string
{
    return (string) preg_replace('#/\*.*?\*/#s', '', $s);
}
/** يشيل تعليقات PHP للسبب نفسه (نفس أسلوب code_only في verify_security.php). */
function php_code(string $s): string
{
    $s = (string) preg_replace('#/\*.*?\*/#s', '', $s);
    return (string) preg_replace('#^\s*//.*$#m', '', $s);
}
/** كل ملفات الشاشات ما عدا بوابة المناديب (خارج نطاق الخطة). */
function office_views(string $root): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app/Views'));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'php') { continue; }
        $p = $f->getPathname();
        if (str_contains($p, '/Views/m/')) { continue; }
        $out[$p] = (string) file_get_contents($p);
    }
    ksort($out);
    return $out;
}

echo "فحص تجربة الاستخدام — المرحلة ٠ (الأساسات)\n" . str_repeat('=', 62) . "\n";

if ($css === '') { bad('app.css مش موجود'); exit(1); }
$cssCode  = css_code($css);
$helpCode = php_code($helpers);
$views    = office_views($root);

/* ══════════════════════ 1) توكنز التصميم ══════════════════════ */
sec('١) توكنز التصميم في :root');

preg_match('/:root\s*\{(.*?)\}/s', $css, $m);
$rootBlock = $m[1] ?? '';
if ($rootBlock === '') { bad('مش لاقي بلوك :root'); }

$tokenGroups = [
    'المسافات'     => ['--space-1', '--space-2', '--space-3', '--space-4', '--space-5', '--space-6', '--space-7', '--space-8'],
    'أحجام الخط'   => ['--fs-1', '--fs-2', '--fs-3', '--fs-4', '--fs-5', '--fs-6', '--fs-7', '--fs-8'],
    'ارتفاع السطر' => ['--lh-tight', '--lh-base', '--lh-loose'],
    'الاستدارة'    => ['--radius-1', '--radius-2', '--radius-3', '--radius-4', '--radius-pill'],
    'الظلال'       => ['--shadow-1', '--shadow-2', '--shadow-3'],
    'الطبقات'      => ['--z-sticky', '--z-nav', '--z-drawer', '--z-modal', '--z-toast', '--z-skip'],
    'الحركة'       => ['--dur-fast', '--dur-base', '--dur-slow', '--ease'],
    'ألوان ناقصة'  => ['--white', '--gold-dark', '--rule', '--rule-2', '--paper', '--ink-mut'],
    'نقطة الكسر'   => ['--bp-mobile'],
];
foreach ($tokenGroups as $label => $tokens) {
    $missing = array_values(array_filter($tokens, fn(string $t): bool => !str_contains($rootBlock, $t . ':')));
    $missing ? bad("توكنز {$label} ناقصة: " . implode('، ', $missing)) : ok("توكنز {$label} (" . count($tokens) . ')');
}

// أرضية حجم الخط: أصغر توكن لازم يكون >= .75rem
if (preg_match('/--fs-1:\s*([\d.]+)rem/', $rootBlock, $mm)) {
    ((float) $mm[1] >= 0.75)
        ? ok('أرضية حجم الخط --fs-1 = ' . $mm[1] . 'rem (>= .75rem)')
        : bad('--fs-1 أصغر من .75rem — نص المحتوى هيبقى غير مقروء');
} else {
    bad('مش قادر أقرا قيمة --fs-1');
}

/* ══════════════ 2) المتغيّرات المكسورة ══════════════ */
sec('٢) المتغيّرات المكسورة');

// var(--text) — مش معرَّف في المشروع كله، فأي استعمال ليه بيرجع فاضي
if (preg_match('/--text\s*:/', $rootBlock)) {
    skip('--text اتعرّف في :root — الفحص ده كان بيمنع استعماله وهو غير معرَّف');
} elseif (str_contains($cssCode, 'var(--text)')) {
    bad('var(--text) رجع في app.css وهو متغيّر غير معرَّف — استخدم var(--ink)');
} else {
    ok('مفيش var(--text) (المتغيّر غير المعرَّف) في app.css');
}
foreach ($views as $path => $src) {
    if (str_contains($src, 'var(--text)')) {
        bad('var(--text) غير المعرَّف في ' . str_replace($root . '/', '', $path));
    }
}

// --rule/--rule-2 لازم يفضلوا في :root: مستعملين في .form-modal-head و.bf-head برّه .permits-v2
foreach (['--rule', '--rule-2'] as $v) {
    str_contains($rootBlock, $v . ':')
        ? ok("{$v} معرَّف في :root (مستعمل برّه .permits-v2)")
        : bad("{$v} مش في :root — .form-modal-head و.bf-head هيرجعوا بلا حدود");
}

/* ══════════════ 3) .btn.primary ══════════════ */
sec('٣) أصناف الأزرار المستعملة كلها معرَّفة');

$btnClasses = [];
foreach ($views as $src) {
    if (preg_match_all('/class="[^"]*\bbtn\b([^"]*)"/', $src, $bm)) {
        foreach ($bm[1] as $rest) {
            foreach (preg_split('/\s+/', trim($rest)) ?: [] as $c) {
                if ($c !== '' && preg_match('/^[a-z][a-z0-9-]*$/', $c)) { $btnClasses[$c] = true; }
            }
        }
    }
}
// أصناف مساعدة/تخطيط مش تنويعات لون
/* الصنف يعتبر معرَّفًا لو ظهر كمُحدِّد في **أي** مصدر CSS — لا `.btn.x` ولا `.x`
   لوحده، لأن أسلوب المشروع بيعرّف أغلبها كأصناف مستقلة، وكتير منها جوّه بلوك
   `<style>` في الشاشة نفسها. الهدف هو صيد الصنف اللي مش معرَّف **في أي مكان**
   (زي `.btn.primary` اللي كان بيقع على الشكل الافتراضي بالسكوت). */
$allCss = $cssCode;
foreach (glob($root . '/public/assets/css/*.css') ?: [] as $f) {
    $allCss .= "\n" . css_code((string) file_get_contents($f));
}
foreach ($views as $src) {
    if (preg_match_all('#<style[^>]*>(.*?)</style>#s', $src, $sm)) {
        $allCss .= "\n" . css_code(implode("\n", $sm[1]));
    }
}
/* وفيه أصناف مقصود إنها **خطّاف جافاسكريبت** لا شكل (`.js-copy`, `.ai-test`,
   `.secret-toggle`…) — دي مشروعة تمامًا، فبنعتبرها معرَّفة لو الجافاسكريبت
   بينادّيها. اللي بيفشل هو الصنف اللي مالوش شكل **ولا** سلوك. */
$allJs = (string) file_get_contents($root . '/public/assets/js/app.js');
foreach ($views as $src) {
    if (preg_match_all('#<script[^>]*>(.*?)</script>#s', $src, $jm)) {
        $allJs .= "\n" . implode("\n", $jm[1]);
    }
}
// أصناف مساعدة/تخطيط، وقيم بتتولّد من PHP (زي '' أو null) مش تنويعات حقيقية
$ignore = ['sm', 'xs', 'lg', 'block', 'icon-btn', 'js-busy', 'wide', 'full', 'null'];
$undefined = [];
foreach (array_keys($btnClasses) as $c) {
    if (in_array($c, $ignore, true)) { continue; }
    $q = preg_quote($c, '/');
    $styled  = (bool) preg_match('/\.' . $q . '(?![\w-])/', $allCss);
    $scripted = (bool) preg_match('/[\'"`]\.?' . $q . '(?![\w-])/', $allJs);
    if (!$styled && !$scripted) { $undefined[] = ".btn.$c"; }
}
$undefined
    ? bad('أصناف أزرار مستعملة في الشاشات وغير معرَّفة في app.css: ' . implode('، ', $undefined))
    : ok('كل أصناف .btn المستعملة في الشاشات معرَّفة (' . count($btnClasses) . ' صنف اتفحص)');
preg_match('/\.btn\.primary\b/', $cssCode)
    ? ok('.btn.primary معرَّف (كان مستعمل في 5 مواضع وغير معرَّف)')
    : bad('.btn.primary مش معرَّف');

/* ══════════════ 4) طابور الفلاش ══════════════ */
sec('٤) flash() بقت طابور + flash_all()');

function_exists('flash_all') ? ok('flash_all() موجودة') : bad('flash_all() مش موجودة');
str_contains($helpCode, "\$_SESSION['_flash'][\$type][] =")
    ? ok("flash() بتخزّن في طابور (\$_SESSION['_flash'][\$type][])")
    : bad('flash() لسه بتدهس الرسالة السابقة بدل ما تكوّم');

// سلوك فعلي — الطابور بيراكم، والقراءة بترجّع الأولى وبتفضّي النوع
$_SESSION['_flash'] = [];
flash('success', 'أول رسالة');
flash('success', 'تانية رسالة');
flash('error', 'رسالة خطأ');
$queued = $_SESSION['_flash']['success'] ?? null;
(is_array($queued) && count($queued) === 2)
    ? ok('رسالتان نجاح اتراكموا بلا ما واحدة تمسح التانية')
    : bad('الطابور مش بيراكم — الرسالة التانية داست على الأولى');

$first = flash('success');
($first === 'أول رسالة') ? ok('flash() بترجّع أول رسالة (توافق رجعي مع 12 موضع قراءة)')
                         : bad('flash() رجّعت: ' . var_export($first, true));
!isset($_SESSION['_flash']['success'])
    ? ok('القراءة بتفضّي النوع بالكامل — مفيش رسالة بتتسرّب للصفحة اللي بعدها')
    : bad('فيه رسالة فاضلة في الجلسة بعد القراءة — هتظهر في صفحة تانية بالغلط');

$_SESSION['_flash'] = [];
flash('success', 'ن١'); flash('success', 'ن٢'); flash('error', 'خ١');
$all = flash_all();
(count($all) === 3) ? ok('flash_all() رجّعت الـ3 رسايل كلها') : bad('flash_all() رجّعت ' . count($all) . ' بدل 3');
empty($_SESSION['_flash']) ? ok('flash_all() فضّت الطابور') : bad('flash_all() ما فضّتش الطابور');

// توافق رجعي: جلسة قديمة فيها نص مخزَّن قبل الترقية
$_SESSION['_flash'] = ['success' => 'نص قديم من جلسة سابقة'];
(flash('success') === 'نص قديم من جلسة سابقة')
    ? ok('توافق رجعي: جلسة قديمة بنص مفرد بتتقرا صح')
    : bad('جلسة قديمة بنص مفرد بتكسر flash()');
$_SESSION['_flash'] = [];

/* ══════════════ 5) الكود الميت ══════════════ */
sec('٥) الكود الميت اتشال وما رجعش');

function_exists('old') ? bad("دالة old() رجعت — الاحتفاظ بالمدخلات مكانه FormState") : ok('دالة old() اتشالت');
$offenders = [];
foreach (['app/Core', 'app/Controllers', 'app/Models'] as $dir) {
    foreach (glob($root . '/' . $dir . '/*.php') ?: [] as $f) {
        if (str_contains(php_code((string) file_get_contents($f)), "_SESSION['_old']")) {
            $offenders[] = str_replace($root . '/', '', $f);
        }
    }
}
foreach ($views as $path => $src) {
    if (str_contains($src, "_SESSION['_old']") || preg_match('/\bold\s*\(\s*[\'"]/', $src)) {
        $offenders[] = str_replace($root . '/', '', $path);
    }
}
$offenders ? bad("\$_SESSION['_old'] رجع في: " . implode('، ', array_unique($offenders)))
           : ok("مفيش أي استخدام لـ \$_SESSION['_old'] — FormState هو المصدر الوحيد");

/* ══════════════ 6) قواعد الشاشات القايمة ══════════════ */
sec('٦) قواعد الشاشات');

$jsonEncode = [];
foreach ($views as $path => $src) {
    if (str_contains(php_code($src), 'json_encode')) { $jsonEncode[] = str_replace($root . '/', '', $path); }
}
$jsonEncode ? bad('json_encode في الشاشات (ثغرة XSS مخزَّن — استخدم js_json): ' . implode('، ', $jsonEncode))
            : ok('مفيش json_encode في أي شاشة — js_json هو المصدر الوحيد');

/* ══════════════ 7) العزل المالي ══════════════ */
sec('٧) العزل المالي — الطبقة دي عرض بحت');

/* الملفات اللي المرحلة ٠ لمستها كلها **عرض بحت** — ممنوع يكون فيها أي استعلام
   كتابة. لو حد حط منطق مالي في helpers.php أو app.css الفحص لازم يفشل. */
$displayOnly = ['app/Core/helpers.php'];
$writeHits = [];
foreach ($displayOnly as $rel) {
    $src = php_code((string) file_get_contents($root . '/' . $rel));
    if (preg_match('/\b(INSERT\s+INTO|UPDATE\s+`?\w+`?\s+SET|DELETE\s+FROM)\b/i', $src)) {
        $writeHits[] = $rel;
    }
}
$writeHits ? bad('استعلام كتابة في ملف عرض: ' . implode('، ', $writeHits))
           : ok('ملفات المرحلة ٠ عرض بحت — مفيش أي استعلام كتابة');

// رصيد الخزنة لازم يفضل مقروءًا وسليمًا — الطبقة دي ما بتمسّهوش
try {
    $balance = (float) \App\Models\Custody::balance();
    ok('رصيد الخزنة اتقرا سليم: ' . number_format($balance, 2) . ' — والفحص قراءة فقط');
} catch (\Throwable $e) {
    skip('مش قادر أقرا رصيد الخزنة (' . $e->getMessage() . ')');
}

/* ══════════════ ٨) المرحلة ١ — التنبيهات ══════════════ */
sec('٨) نظام التنبيهات الموحّد (المرحلة ١)');

$js = (string) file_get_contents($root . '/public/assets/js/app.js');
$layout = (string) file_get_contents($root . '/app/Views/layout.php');

// تطبيق واحد بس للتوست — القديم كان عنصر مفرد بيتمسح بتاني رسالة
(substr_count($js, 'function toast(') === 1)
    ? ok('فيه تطبيق واحد بس لـtoast() (كان اتنين)')
    : bad('فيه أكتر من تطبيق لـtoast() — لازم واحد');
!preg_match("/querySelector\('\.toast'\)/", $js)
    ? ok('التوست المفرد القديم (querySelector(\'.toast\')) اتشال')
    : bad('التوست المفرد القديم لسه موجود — الرسايل هتمسح بعضها');
str_contains($js, 'toastQueue')  ? ok('فيه طابور للتوستات الزيادة') : bad('مفيش طابور — الرسالة الرابعة هتضيع');
str_contains($js, "err: 0")      ? ok('رسالة الخطأ ما بتختفيش لوحدها') : bad('الخطأ بيختفي تلقائيًا — المستخدم ممكن ما يشوفهوش');
str_contains($js, 'toast-x')     ? ok('كل تنبيه له زر إغلاق') : bad('مفيش زر إغلاق');
str_contains($js, "'alert' : 'status'") ? ok('الخطأ بيتعلن assertive والباقي polite') : bad('مفيش دور ARIA مناسب لكل نوع');
str_contains($js, 'mouseenter')  ? ok('الوقوف بالماوس/التركيز بيوقف العدّاد') : bad('العدّاد ما بيقفش');

// حقن HTML: نص التنبيه لازم يتحط بـtextContent لا innerHTML
preg_match('/function toastShow\(.*?\n  \}/s', $js, $tm);
$toastFn = $tm[0] ?? '';
($toastFn !== '' && str_contains($toastFn, 'text.textContent = msg'))
    ? ok('نص التنبيه بيتحط بـtextContent — مفيش حقن HTML')
    : bad('نص التنبيه ممكن يتحط كـHTML — ثغرة XSS');

// الخادم والـAJAX لازم يمرّوا على نفس المكوّن
str_contains($layout, 'flash_all()')
    ? ok('الـlayout بيسحب الطابور كله بـflash_all()')
    : bad('الـlayout لسه بيقرا رسالة واحدة — الباقي هيضيع');
!preg_match('/class="flash (success|error)"/', $layout)
    ? ok('بانرات .flash الثابتة اتشالت من الـlayout')
    : bad('بانر .flash لسه في الـlayout — نظامان تنبيه من تاني');
str_contains($layout, 'id="toastStack"') ? ok('كومة التوستات في الـlayout') : bad('مفيش كومة توستات');

/* ══════════════ ٩) مركز الإشعارات ══════════════ */
sec('٩) مركز الإشعارات — عزل وصلاحيات');

$notif = $root . '/app/Models/Notification.php';
if (!is_file($notif)) {
    bad('app/Models/Notification.php مش موجود');
} else {
    $nsrc = php_code((string) file_get_contents($notif));

    // ⚠️ الوحدة قراءة فقط — أي كتابة تكسر مبدأ العزل
    preg_match('/\b(INSERT\s+INTO|UPDATE\s+`?\w+`?\s+SET|DELETE\s+FROM|CREATE\s+TABLE|ALTER\s+TABLE)\b/i', $nsrc)
        ? bad('Notification فيه استعلام كتابة — الوحدة لازم تفضل قراءة فقط')
        : ok('Notification قراءة فقط — مفيش INSERT/UPDATE/DELETE/CREATE/ALTER');

    // ⚠️ بلا جدول جديد ولا هجرة
    (!preg_match('/migrate_v\d+/', $nsrc))
        ? ok('مفيش هجرة مرتبطة — الإشعارات مبنية على مصادر قائمة')
        : bad('Notification بيشاور على هجرة — المفروض بلا جدول جديد');

    // ⚠️ الصلاحيات بتتفحص جوّه الموديل مش في الواجهة
    str_contains($nsrc, 'Access::allows')
        ? ok('الصلاحية بتتفحص جوّه الموديل (إخفاء الجرس مش حماية)')
        : bad('مفيش فحص صلاحيات — ممكن يوصل إشعار عن صفحة ممنوعة');

    // ⚠️ قاعدة v48: المقارنة الزمنية جوّه SQL
    (!preg_match('/strtotime\([^)]*attempted_at|time\(\)\s*[<>]/', $nsrc))
        ? ok('مفيش مقارنة وقت قاعدة بوقت PHP (قاعدة v48)')
        : bad('فيه مقارنة وقت بين القاعدة وPHP — فرق التوقيت هيكسرها');

    // ⚠️ مفيش تعريف تاني لأي رقم
    $reuses = ['RepInbox::pendingCount', 'Closing::lastClosedDate', 'Metrics::readyToInvoiceSummary', 'Permit::receiptsMissingData'];
    $missing = array_values(array_filter($reuses, fn(string $m): bool => !str_contains($nsrc, $m)));
    $missing ? bad('مصادر مش بتستخدم دالة المصدر الأصلية: ' . implode('، ', $missing))
             : ok('كل الأرقام من دوال المصدر نفسها — مفيش تعريف تاني');
}

// المسار حرفي وموجود
$routes = (string) file_get_contents($root . '/public/index.php');
str_contains($routes, "'/notifications/ping'") ? ok('مسار /notifications/ping متسجّل') : bad('المسار مش متسجّل');
str_contains($routes, "'NotificationController@ping'") ? ok('المعالج متوصّل') : bad('المعالج مش متوصّل');

// سلوك فعلي: الأدوار بتشوف بنود مختلفة، والمالك بس بيشوف الأمني
if (class_exists('App\\Models\\Notification')) {
    $keysFor = static function (string $role): array {
        $_SESSION['uid'] = 1; $_SESSION['role'] = $role; $_SESSION['uname'] = 'verify';
        $f = \App\Models\Notification::feed(true);
        return array_column($f['items'], 'key');
    };
    $ownerKeys = $keysFor('owner');
    $roKeys    = $keysFor('read_only');
    !in_array('failed_logins', $roKeys, true)
        ? ok('«قراءة فقط» ما بيشوفش إشعار محاولات الدخول (للمالك بس)')
        : bad('إشعار أمني وصل لدور غير المالك');
    !in_array('weak_pins', $roKeys, true)
        ? ok('«قراءة فقط» ما بيشوفش إشعار الأرقام السرية الضعيفة')
        : bad('إشعار الأرقام السرية وصل لدور غير مسموح');
    (count($ownerKeys) >= count($roKeys))
        ? ok('المالك بيشوف كل ما يشوفه غيره أو أكتر (' . count($ownerKeys) . ' مقابل ' . count($roKeys) . ')')
        : bad('المالك بيشوف أقل من دور أدنى — منطق الصلاحيات معكوس');

    // العزل المالي: الجرس ما بيمسّش أي رصيد
    try {
        $b1 = (float) \App\Models\Custody::balance();
        \App\Models\Notification::feed(true);
        $b2 = (float) \App\Models\Custody::balance();
        (abs($b1 - $b2) < 0.005)
            ? ok('رصيد الخزنة ما اتغيّرش بعد نداء الإشعارات')
            : bad('رصيد الخزنة اتغيّر بعد نداء الإشعارات!');
    } catch (\Throwable $e) {
        skip('مش قادر أقارن رصيد الخزنة (' . $e->getMessage() . ')');
    }
    unset($_SESSION['uid'], $_SESSION['role'], $_SESSION['uname']);
}

/* ══════════════ ١٠) المرحلة ٢ — عقد المسار بعد الحفظ ══════════════ */
sec('١٠) عقد المسار بعد الحفظ + صفحات الأخطاء (المرحلة ٢)');

$ctrlCore = php_code((string) file_get_contents($root . '/app/Core/Controller.php'));
$accessSrc = php_code((string) file_get_contents($root . '/app/Core/Access.php'));
$routerSrc = php_code((string) file_get_contents($root . '/app/Core/Router.php'));

// done() لازم تفرض الرسالة — مستحيل إجراء ناجح يعدّي بالسكوت
str_contains($ctrlCore, 'function done(')
    ? ok('Controller::done() موجودة (المسار الموحّد بعد الحفظ)')
    : bad('Controller::done() مش موجودة');
preg_match('/function done\(string \$msg[^)]*\).*?\{(.*?)\n    \}/s', $ctrlCore, $dm);
(isset($dm[1]) && str_contains($dm[1], 'flash(') && str_contains($dm[1], 'safe_back('))
    ? ok('done() بتضيف الرسالة **دايمًا** وبتنضّف الرجوع بـsafe_back')
    : bad('done() مش بتضمن الرسالة أو مش بتنضّف الرجوع');
str_contains($ctrlCore, 'function fail(')
    ? ok('Controller::fail() موجودة (رفض بسبب واضح بدل الرمي الصامت)')
    : bad('Controller::fail() مش موجودة');

// صفحات الأخطاء
foreach (['errors/403.php', 'errors/404.php', 'errors/404_guest.php'] as $v) {
    is_file($root . '/app/Views/' . $v) ? ok("صفحة $v موجودة") : bad("صفحة $v ناقصة");
}
!preg_match("/flash\('error'.*?\n\s*redirect\(''\);/s", $accessSrc)
    ? ok('Access::deny ما بقاش بيرمي المستخدم على الداشبورد بالسكوت')
    : bad('Access::deny لسه بيعمل flash + redirect للداشبورد');
str_contains($accessSrc, "render('errors/403'")
    ? ok('Access::deny بيعرض صفحة 403 حقيقية')
    : bad('Access::deny مش بيعرض صفحة 403');
str_contains($accessSrc, "'flash' => \$msg")
    ? ok('رد 403 بالـAJAX فيه مفتاح flash (اللي app.js بيقراه)')
    : bad('رد 403 بالـAJAX مفيهوش flash — الواجهة هتعرض رسالة عامة');
str_contains($routerSrc, "'errors/404_guest'")
    ? ok('404 بتترندر جوّه الـlayout للمسجَّل ومستقلة للزائر')
    : bad('404 لسه بتترندر بلا layout للكل');

// أمان referer_path — ده مصدر غير موثوق جاي من المتصفح
if (function_exists('referer_path')) {
    ok('referer_path() موجودة');
    $hostBefore = $_SERVER['HTTP_HOST'] ?? null;
    $refBefore  = $_SERVER['HTTP_REFERER'] ?? null;
    $_SERVER['HTTP_HOST'] = 'localhost';
    $cases = [
        'http://localhost/permits' => '/permits',
        'https://evil.com/steal'   => '',
        '//evil.com/steal'         => '',
        'javascript:alert(1)'      => '',
        'data:text/html,x'         => '',
        ''                         => '',
    ];
    $bad = [];
    foreach ($cases as $ref => $want) {
        $_SERVER['HTTP_REFERER'] = $ref;
        if (referer_path() !== $want) { $bad[] = ($ref === '' ? '(فاضي)' : $ref); }
    }
    $bad ? bad('referer_path بتقبل مصادر خطر: ' . implode('، ', $bad))
         : ok('referer_path بترفض أي موقع خارجي وأي scheme غير http/https (6 حالات)');
    if ($hostBefore === null) { unset($_SERVER['HTTP_HOST']); } else { $_SERVER['HTTP_HOST'] = $hostBefore; }
    if ($refBefore === null) { unset($_SERVER['HTTP_REFERER']); } else { $_SERVER['HTTP_REFERER'] = $refBefore; }
} else {
    bad('referer_path() مش موجودة');
}

/* ══════════════ ١١) مفيش إجراء صامت ══════════════ */
sec('١١) كل إجراء له نتيجة مرئية (المرحلة ٢)');

/* أي `redirect()` في كنترولر لازم يكون قبلها بشوية حاجة بتقول للمستخدم إيه
   اللي حصل. الاستثناءات الوحيدة المسموح بها موصوفة تحت بأسبابها. */
$exempt = [
    // دالة رجوع داخلية بتتنادى **بعد** ما المُنادي حطّ الرسالة
    'DebtController.php'      => ['redirectBack'],
    // بوابة المناديب — خارج نطاق إعادة التصميم، ليها نظام رسايل خاص بيها
    'RepPortalController.php' => ['*'],
];
$silent = [];
foreach (glob($root . '/app/Controllers/*.php') ?: [] as $file) {
    $base = basename($file);
    if (($exempt[$base][0] ?? '') === '*') { continue; }
    $lines = file($file);
    $fn = '';
    foreach ($lines as $i => $line) {
        if (preg_match('/function ([a-zA-Z]+)\(/', $line, $fm)) { $fn = $fm[1]; }
        if (!preg_match('/^\s*redirect\(/', $line)) { continue; }
        if (in_array($fn, $exempt[$base] ?? [], true)) { continue; }
        $ctx = implode('', array_slice($lines, max(0, $i - 6), 7));
        if (!preg_match('/flash\(|->done\(|->fail\(|->notFound\(|formFailure|json\(|\?bye=1/', $ctx)) {
            $silent[] = $base . ':' . ($i + 1);
        }
    }
}
$silent ? bad('إجراءات بتخلص بالسكوت (بلا رسالة): ' . implode('، ', $silent))
        : ok('مفيش أي إجراء بيخلص بلا رسالة للمستخدم');

// الحراس الصامتة في الأذونات — كانت 18 حارس بيرموك على القايمة بلا كلمة
$permCtrl = php_code((string) file_get_contents($root . '/app/Controllers/PermitController.php'));
$bareGuards = preg_match_all("/if \(![^)]+\) \{ redirect\('permits'\); \}/", $permCtrl);
$bareGuards === 0
    ? ok('مفيش حارس في الأذونات بيرمي المستخدم على القايمة بالسكوت (كانوا 18)')
    : bad("لسه فيه $bareGuards حارس صامت في PermitController");

// دوال بترجّع الحالة الجديدة عشان الرسالة تقول الصح مش تخمّن
$noteSrc = php_code((string) file_get_contents($root . '/app/Models/Note.php'));
str_contains($noteSrc, 'function toggleDone(int $id): bool')
    ? ok('Note::toggleDone بترجّع الحالة الجديدة (الرسالة بتقول الصح)')
    : bad('Note::toggleDone لسه void — الرسالة هتخمّن الحالة');
$permSrc = php_code((string) file_get_contents($root . '/app/Models/Permit.php'));
str_contains($permSrc, 'function toggleDismissedReceipt(int $permitId, string $label): bool')
    ? ok('Permit::toggleDismissedReceipt بترجّع الحالة الجديدة')
    : bad('toggleDismissedReceipt لسه void — الرسالة هتخمّن');

// الخروج: ممنوع flash بعد session_destroy لأنها بتضيع بالسكوت
$authSrc = php_code((string) file_get_contents($root . '/app/Controllers/AuthController.php'));
!preg_match('/Auth::logout\(\);\s*\n\s*flash\(/', $authSrc)
    ? ok('مفيش flash بعد Auth::logout (كانت هتضيع — session_destroy)')
    : bad('فيه flash بعد logout — هتضيع لأن الجلسة اتدمّرت');
str_contains($authSrc, "login?bye=1")
    ? ok('تأكيد الخروج بيتبعت في الرابط بدل الجلسة')
    : bad('مفيش تأكيد خروج للمستخدم');

// صفحة الدخول لازم تفضّي الطابور بنفسها (مستقلة عن الـlayout)
$loginView = (string) file_get_contents($root . '/app/Views/auth/login.php');
str_contains($loginView, 'flash_all()')
    ? ok('صفحة الدخول بتفضّي طابور الرسايل (ما تتسربش لبعد الدخول)')
    : bad('صفحة الدخول مش بتفضّي الطابور — الرسالة هتطلع بعد الدخول بالغلط');

/* ══════════════ ١٢) الحفاظ على المُدخَل عند الفشل ══════════════ */
sec('١٢) اللي المستخدم كتبه ما يضيعش (المرحلة ٢ج)');

// لصق الإكسل — كان أسوأ ضياع في النظام (200 صف بيروحوا بسبب غلطة في صف)
$bawSrc = php_code((string) file_get_contents($root . '/app/Controllers/BawalisController.php'));
$crudView = (string) file_get_contents($root . '/app/Views/crud/index.php');
str_contains($bawSrc, 'function pasteFailure')
    ? ok('لصق الإكسل: كل فشل بيمرّ على pasteFailure')
    : bad('لصق الإكسل: مفيش مسار فشل بيحافظ على اللصق');
!preg_match("/flash\('error'[^;]*\);\s*redirect\('bawalis'\);/", $bawSrc)
    ? ok('لصق الإكسل: مفيش فشل بيرمي على القايمة والـtextarea فاضية')
    : bad('لصق الإكسل: لسه فيه مسار بيضيّع اللصق');
str_contains($crudView, "\$pasteState['old']['rows']")
    ? ok('نافذة اللصق بتترجّع متعبّية')
    : bad('نافذة اللصق مش بتترجّع متعبّية');
str_contains($crudView, 'data-reopen')
    ? ok('نافذة اللصق بتتفتح لوحدها عشان المستخدم يشوف الخطأ')
    : bad('نافذة اللصق مش بتتفتح بعد الفشل — الخطأ هيتخبّى');

// مطابقة التقفيل — المالك بيملّي عشرات الصفوف بإيده
$clSrc = php_code((string) file_get_contents($root . '/app/Controllers/DailyClosingController.php'));
$clView = (string) file_get_contents($root . '/app/Views/closing/index.php');
str_contains($clSrc, "formFailure(\n                'closing.reconcile.")
    || str_contains($clSrc, "'closing.reconcile.'")
    ? ok('مطابقة التقفيل: الرفض بيحفظ كل المبالغ المكتوبة')
    : bad('مطابقة التقفيل: المبالغ لسه بتضيع');
$allFour = str_contains($clView, "\$reconOld['manual_total']")
    && str_contains($clView, "\$reconOld['row_note']")
    && str_contains($clView, "\$reconOld['manual_general_expenses']")
    && str_contains($clView, "\$reconOld['reconciliation_note']");
$allFour ? ok('شاشة المطابقة بترجّع الأربع مجموعات (مبالغ/ملاحظات/عام/ملاحظة)')
         : bad('شاشة المطابقة مش بترجّع كل المُدخَل');
str_contains($clView, '$reconErr')
    ? ok('سبب رفض المطابقة بيتعرض للمستخدم')
    : bad('سبب رفض المطابقة مش بيتعرض');

/* ⚠️ مصيدة تصادم أسماء مفاتيح render (زي مصيدة `data` الموثَّقة): الشاشة
   بتستخدم `$reconState` لكلاس الحالة، فمفتاح الرندر اتسمّى `reconRestore`. */
!preg_match("/'reconState'\s*=>/", $clSrc)
    ? ok('مفتاح الرندر مش بيتصادم مع $reconState اللي في الشاشة')
    : bad('مفتاح render اسمه reconState — هيتصادم مع متغيّر الشاشة');

/* ══════════════ ١٣) كل نموذج بيحافظ على مُدخَله ══════════════ */
sec('١٣) كل الوحدات بتحافظ على المُدخَل (المرحلة ٢ج)');

/* كل وحدة: [الكنترولر، معرّف حقيبة النموذج، الشاشة، مفتاح الاسترجاع فيها] */
$preserve = [
    'كشف صاحب البيان' => ['OwnerStatementController.php', 'statements.form.',  'statements/form.php',        '$fsOld'],
    'توزيع أصحاب البيان' => ['SettlementController.php',  'settlements.assign', 'settlements/unassigned.php', '$assignState'],
    'مستخدمو الإعدادات' => ['SettingsController.php',     'settings.user.add',  'settings/index.php',        '$userState'],
    'كارت المندوب'     => ['RepController.php',           'reps.create',        'reps/form.php',             '$formErr'],
    'بنود المندوب'     => ['RepController.php',           'reps.entry.',        'reps/show.php',             '$eOld'],
    'تسوية المندوب'    => ['RepController.php',           'reps.settle.',       'reps/show.php',             '$sOld'],
    'وجهات الخريطة'    => ['TrackController.php',         'tracking.place',     'tracking/places.php',       '$pOld'],
    'تحصيل العميل'     => ['DirectoryController.php',     'directory.pay.',     'directory/agent.php',       '$payOld'],
];
foreach ($preserve as $label => [$ctrl, $formId, $view, $marker]) {
    $cSrc = php_code((string) file_get_contents($root . '/app/Controllers/' . $ctrl));
    $vSrc = (string) file_get_contents($root . '/app/Views/' . $view);
    $hasCtrl = str_contains($cSrc, "'" . $formId);
    $hasView = str_contains($vSrc, $marker);
    ($hasCtrl && $hasView)
        ? ok("{$label}: بيحفظ المُدخَل والشاشة بترجّعه")
        : bad("{$label}: " . (!$hasCtrl ? 'الكنترولر مش بيحفظ' : 'الشاشة مش بترجّع') . " المُدخَل");
}

// حقلا الملاحظة في كارت المندوب لازم يبقوا من حقيبتين مختلفتين (اتصادفت غلطة فعلية)
$repShow = (string) file_get_contents($root . '/app/Views/reps/show.php');
(substr_count($repShow, "\$eOld['note']") === 1 && substr_count($repShow, "\$sOld['note']") === 1)
    ? ok('ملاحظة البند وملاحظة التسوية كل واحدة من حقيبتها')
    : bad('حقلا الملاحظة في كارت المندوب بيقروا من نفس الحقيبة');

/* ══════════════ ١٤) مُنضِّف رجوع واحد ══════════════ */
sec('١٤) مُنضِّف واحد للرجوع (المرحلة ٢د)');

$weak = [];
foreach (glob($root . '/app/Controllers/*.php') ?: [] as $file) {
    $src = php_code((string) file_get_contents($file));
    // النمط القديم: فحص يدوي بـ'://' بدل ما يعدّي على safe_back
    if (preg_match("/str_contains\(\\\$back, ':\/\/'\)/", $src)) {
        $weak[] = basename($file);
    }
}
$weak ? bad('لسه فيه مُنضِّف رجوع مكتوب بالإيد أضعف من safe_back: ' . implode('، ', $weak))
      : ok('كل مُنضِّفات الرجوع بتمرّ على safe_back (كانوا 4 مكتوبين بالإيد)');

// سلوك فعلي — safe_back لازم ترفض كل صور الهجوم
$attacks = [
    'https://evil.com/x'    => 'FB',
    '//evil.com/x'          => 'FB',
    'javascript:alert(1)'   => 'FB',
    "permits\nSet-Cookie: x" => 'FB',
    ''                      => 'FB',
    'permits?view=records'  => 'permits?view=records',
    '/permits/12/manage'    => 'permits/12/manage',
];
$leaks = [];
foreach ($attacks as $in => $want) {
    if (safe_back('FB', $in) !== $want) { $leaks[] = $in === '' ? '(فاضي)' : $in; }
}
$leaks ? bad('safe_back بتقبل مسارات خطر: ' . implode('، ', $leaks))
       : ok('safe_back بترفض كل صور الرجوع الخطرة (7 حالات)');

/* ══════════════ ١٥) كل الكنترولرات بتتحمّل فعلاً ══════════════ */
sec('١٥) تحميل كل الكنترولرات (بيمسك اللي php -l ما بيمسكهوش)');

/* ⚠️ اتصادفت فعليًا: إضافة `protected notFound()` للكلاس الأساسي كسرت
   TrackController وDirectoryController اللي كان عندهم `private notFound()`
   — **تضييق مستوى الوصول خطأ قاتل وقت التشغيل**، و`php -l` بيعدّيه لأنه
   بيفحص كل ملف لوحده. الصفحتان كانتا بترجّعا 500 صامت. */
$broken = [];
foreach (glob($root . '/app/Controllers/*.php') ?: [] as $file) {
    $class = 'App\\Controllers\\' . basename($file, '.php');
    try {
        if (!class_exists($class)) { $broken[] = basename($file) . ' (مش لاقي الكلاس)'; }
    } catch (\Throwable $e) {
        $broken[] = basename($file) . ': ' . $e->getMessage();
    }
}
$broken ? bad('كنترولرات مش بتتحمّل: ' . implode(' · ', $broken))
        : ok('كل الكنترولرات (' . count(glob($root . '/app/Controllers/*.php') ?: []) . ') بتتحمّل بلا تعارض');

// ونفس الحاجة للموديلات
$brokenM = [];
foreach (glob($root . '/app/Models/*.php') ?: [] as $file) {
    $class = 'App\\Models\\' . basename($file, '.php');
    try {
        if (!class_exists($class)) { $brokenM[] = basename($file); }
    } catch (\Throwable $e) {
        $brokenM[] = basename($file) . ': ' . $e->getMessage();
    }
}
$brokenM ? bad('موديلات مش بتتحمّل: ' . implode(' · ', $brokenM))
         : ok('كل الموديلات (' . count(glob($root . '/app/Models/*.php') ?: []) . ') بتتحمّل بلا تعارض');

/* ══════════════ الخلاصة ══════════════ */
echo "\n" . str_repeat('=', 62) . "\n";
echo "نجح: $pass   |   فشل: $fail   |   تخطّي: $skip\n";
if ($fail > 0) {
    echo "\n✗ الفحص فشل — الكود ده ما يترفعش.\n";
    exit(1);
}
echo "\n✓ كل الفحوصات عدّت.\n";
exit(0);
