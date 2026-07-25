<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص «تثبيت النظام على الهاتف» (PWA) — الأيقونة بلوجو الشركة + شاشة البدء.
 *
 * الميزة دي **بتفشل بالسكوت** بطبيعتها: رابط لأيقونة مش موجودة، أو مقاس صورة
 * بدء مش مطابق لاسمها، أو manifest مكسور — كل دي ما بتديش أي خطأ، بس التثبيت
 * ما يشتغلش أو الشاشة تطلع بيضا على موبايل المالك. عشان كده الفحص بيتأكد من
 * **وجود كل ملف ومقاسه الحقيقي** مش من وجود السطر في الكود.
 *
 * بيحرس:
 *   1) الـmanifestان صالحان، وكل أيقونة فيهم موجودة وبالمقاس المعلَن
 *   2) كل صورة شاشة بدء مربوطة في الرأس موجودة وبمقاس = عرض×كثافة
 *   3) الملف المشترك متضمّن في **المكتب والبوابة** مع بعض
 *   4) الـService Worker **ما بيكاشش أي HTML** (قاعدة قايمة — تطبيق ورا login)
 *   5) زرار التثبيت موجود ومخفي افتراضيًّا وكلاسه معرَّف في CSS
 *   6) شاشة البدء: للتطبيق المثبَّت بس + مرّة كل تشغيل + حزام أمان يشيلها
 *   7) عزل كامل: مفيش أي لمسة لقاعدة ولا حساب مالي
 *
 * آمن تمامًا: **قراءة فقط** — مفيش أي كتابة ولا اتصال بالقاعدة.
 *
 * التشغيل:  php bin/verify_pwa.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$pass = 0; $fail = 0; $skip = 0; $warn = 0;

function ok(string $m): void   { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  ✗ $m\n"; }
function warn(string $m): void { global $warn; $warn++; echo "  ! $m\n"; }
function skip(string $m): void { global $skip; $skip++; echo "  ~ $m (تخطّي)\n"; }
function sec(string $m): void  { echo "\n" . $m . "\n" . str_repeat('─', 62) . "\n"; }

$root   = dirname(__DIR__);
$head   = (string) file_get_contents($root . '/app/Views/_pwa_head.php');
$layout = (string) file_get_contents($root . '/app/Views/layout.php');
$mPwa   = (string) file_get_contents($root . '/app/Views/m/_pwa.php');
$sw     = (string) file_get_contents($root . '/public/sw.js');
$css    = (string) file_get_contents($root . '/public/assets/css/app.css');

/** يشيل تعليقات PHP/JS — تعليق بيشرح نمطًا ما يعتبرش استخدامًا له. */
function code_only(string $s): string
{
    $s = (string) preg_replace('#/\*.*?\*/#s', '', $s);
    return (string) preg_replace('#^\s*//.*$#m', '', $s);
}

/** مقاس PNG من ترويسة الملف مباشرةً — بلا أي مكتبة صور. */
function png_size(string $file): ?array
{
    $fh = @fopen($file, 'rb');
    if (!$fh) { return null; }
    $bytes = (string) fread($fh, 24);
    fclose($fh);
    if (strlen($bytes) < 24 || substr($bytes, 1, 3) !== 'PNG') { return null; }
    $w = unpack('N', substr($bytes, 16, 4));
    $h = unpack('N', substr($bytes, 20, 4));
    return [(int) $w[1], (int) $h[1]];
}

echo "\n╔══════════════════════════════════════════════════════════════╗\n";
echo   "║  فحص تثبيت النظام على الهاتف (PWA) + شاشة البدء              ║\n";
echo   "╚══════════════════════════════════════════════════════════════╝\n";

/* ══════════════ 1) الـmanifest ══════════════ */
sec('1) ملفا التطبيق (manifest)');

$manifests = [
    'manifest-office.webmanifest' => ['id' => '/',  'start' => '/'],
    'manifest.webmanifest'        => ['id' => '/m', 'start' => '/m'],
];
$allIcons = [];
foreach ($manifests as $file => $want) {
    $path = $root . '/public/' . $file;
    if (!is_file($path)) { bad("`$file` مش موجود"); continue; }
    $json = json_decode((string) file_get_contents($path), true);
    if (!is_array($json)) { bad("`$file` مش JSON صالح"); continue; }
    ok("`$file` JSON صالح");

    // الهوية: تغييرها بيخلّي المتصفح يعتبره تطبيقًا جديدًا فالمثبَّت القديم يتيه
    ($json['id'] ?? null) === $want['id']
        ? ok("`$file` هويته `{$want['id']}` (المثبَّت القديم بيفضل نفسه)")
        : bad("`$file` هويته اتغيّرت — المفروض `{$want['id']}`");
    ($json['start_url'] ?? null) === $want['start']
        ? ok("`$file` بيفتح على `{$want['start']}`")
        : bad("`$file` `start_url` غلط");

    foreach (['name', 'short_name', 'display', 'background_color', 'theme_color', 'icons'] as $k) {
        if (!isset($json[$k])) { bad("`$file` ناقصه `$k`"); }
    }
    ($json['display'] ?? '') === 'standalone'
        ? ok("`$file` بيفتح كتطبيق مستقل (بلا شريط المتصفح)")
        : bad("`$file` مش `display: standalone`");

    // ⚠️ من غير أيقونة `maskable` أندرويد بيحطّ اللوجو جوّه مربّع أبيض وحش
    $purposes = array_map(fn($i) => (string) ($i['purpose'] ?? 'any'), $json['icons'] ?? []);
    in_array('maskable', $purposes, true)
        ? ok("`$file` فيه أيقونة `maskable` (شكل نضيف على أندرويد)")
        : bad("`$file` مفيهوش أيقونة `maskable`");

    foreach (($json['icons'] ?? []) as $ic) { $allIcons[] = $ic; }
    foreach (($json['shortcuts'] ?? []) as $sc) {
        foreach (($sc['icons'] ?? []) as $ic) { $allIcons[] = $ic; }
    }
}

/* ══════════════ 2) الأيقونات موجودة فعلاً وبمقاسها ══════════════ */
sec('2) ملفات الأيقونات (المقاس من الملف نفسه)');

$badIcons = [];
$seen = [];
foreach ($allIcons as $ic) {
    $src = (string) ($ic['src'] ?? '');
    if ($src === '' || isset($seen[$src . ($ic['sizes'] ?? '')])) { continue; }
    $seen[$src . ($ic['sizes'] ?? '')] = true;
    $file = $root . '/public' . $src;
    if (!is_file($file)) { $badIcons[] = "$src مش موجود"; continue; }
    $size = png_size($file);
    if ($size === null) { $badIcons[] = "$src مش PNG سليم"; continue; }
    $declared = (string) ($ic['sizes'] ?? '');
    if ($declared !== '' && $declared !== 'any' && $declared !== $size[0] . 'x' . $size[1]) {
        $badIcons[] = "$src معلَن {$declared} والحقيقي {$size[0]}x{$size[1]}";
    }
    if ($size[0] !== $size[1]) { $badIcons[] = "$src مش مربّع"; }
}
$badIcons
    ? bad('أيقونات ناقصة أو مقاسها غلط: ' . implode(' · ', $badIcons))
    : ok('كل الأيقونات (' . count($seen) . ') موجودة ومربّعة وبالمقاس المعلَن');

$appleIcon = $root . '/public/assets/img/pwa/apple-touch-icon.png';
is_file($appleIcon) && png_size($appleIcon) === [180, 180]
    ? ok('أيقونة الآيفون 180×180 موجودة')
    : bad('أيقونة الآيفون (apple-touch-icon.png 180×180) ناقصة');

// ⚠️ الأيقونة مولَّدة من لوجو الشركة الحقيقي — مش من رسم بديل
is_file($root . '/bin/make_pwa_assets.py')
    ? ok('مولّد الأصول من اللوجو (`bin/make_pwa_assets.py`) موجود')
    : bad('مولّد الأصول مش موجود — إعادة توليد الأيقونات هتبقى يدوية');

/* ══════════════ 3) شاشة البدء — كل صورة مربوطة موجودة بمقاسها ══════════════ */
sec('3) صور شاشة البدء (آيفون/آيباد)');

/* ⚠️ المقاسات في الملف نفسها متغيّرات PHP، فالفحص لازم يبقى على **الناتج
   المرندَر** مش على الكود — فحص النص كان بيعدّي بإنذار كاذب. */
$renderHead = static function (string $app) use ($root): string {
    ob_start();
    $pwaApp = $app;
    require $root . '/app/Views/_pwa_head.php';
    return (string) ob_get_clean();
};
$outOffice = $renderHead('office');

preg_match_all(
    '/apple-touch-startup-image.*?device-width:\s*(\d+)px.*?device-height:\s*(\d+)px.*?pixel-ratio:\s*(\d+)/s',
    $outOffice,
    $m,
    PREG_SET_ORDER
);
if (!$m) {
    bad('مفيش أي رابط `apple-touch-startup-image` — الآيفون هيفتح على شاشة بيضا');
} else {
    $missing = [];
    foreach ($m as [$all, $w, $h, $d]) {
        $pw = (int) $w * (int) $d; $ph = (int) $h * (int) $d;
        $file = $root . "/public/assets/img/pwa/splash/apple-splash-{$pw}x{$ph}.png";
        if (!is_file($file)) { $missing[] = "{$pw}x{$ph} مش موجودة"; continue; }
        if (png_size($file) !== [$pw, $ph]) { $missing[] = "{$pw}x{$ph} مقاسها الحقيقي مختلف"; }
    }
    $missing
        ? bad('صور بدء ناقصة أو مقاسها غلط: ' . implode(' · ', $missing))
        : ok('كل صور شاشة البدء (' . count($m) . ' مقاس) موجودة وبمقاسها الصح');

    count($m) >= 12
        ? ok('التغطية شاملة أجهزة آيفون الحديثة والآيباد (' . count($m) . ' مقاس)')
        : warn('تغطية مقاسات قليلة (' . count($m) . ') — أجهزة هتقع على اللون الخالص');
}

/* الشاشة اللي بترسمها الصفحة لازم تبقى بنفس هوية الصور المولَّدة، وإلا
   المستخدم يشوف كحلي بعدين لون تاني عند الفتح. */
str_contains($head, '#15355B') && str_contains($head, '#0B1E38')
    ? ok('شاشة البدء بالـHTML بنفس تدرّج الصور المولَّدة (كحلي #15355B → #0B1E38)')
    : bad('تدرّج شاشة البدء اختلف عن الصور المولَّدة');
str_contains($head, 'logo.png')
    ? ok('شاشة البدء بتعرض لوجو الشركة نفسه')
    : bad('شاشة البدء مش بتعرض اللوجو');

/* ══════════════ 4) الربط — المكتب والبوابة مع بعض ══════════════ */
sec('4) الملف المشترك متضمّن في النظامين');

str_contains($layout, "_pwa_head.php")
    ? ok('شاشات المكتب بتضمّن `_pwa_head.php` (كل صفحة تبقى قابلة للتثبيت)')
    : bad('`layout.php` مش بيضمّن `_pwa_head.php` — المكتب مش هيتثبّت');
str_contains($mPwa, "_pwa_head.php")
    ? ok('بوابة المناديب بتضمّن نفس الملف (شاشة بدء واحدة للنظامين)')
    : bad('`m/_pwa.php` مش بيضمّن الملف المشترك');
preg_match("/\\\$pwaApp\s*=\s*'office'/", $layout) ? ok('المكتب بيمرّر `office`') : bad('المكتب مش بيمرّر `office`');
preg_match("/\\\$pwaApp\s*=\s*'rep'/", $mPwa)      ? ok('البوابة بتمرّر `rep`')  : bad('البوابة مش بتمرّر `rep`');

/* كل شاشات البوابة لازم تفضل بتضمّن _pwa.php وإلا شاشة تفتح بلا التطبيق */
$mViews = glob($root . '/app/Views/m/*.php') ?: [];
$noPwa = [];
foreach ($mViews as $f) {
    $b = basename($f);
    if (str_starts_with($b, '_')) { continue; }
    if (!str_contains((string) file_get_contents($f), '_pwa.php')) { $noPwa[] = $b; }
}
$noPwa ? bad('شاشات مندوب بلا وسوم التطبيق: ' . implode(', ', $noPwa))
       : ok('كل شاشات البوابة (' . (count($mViews) - 2) . ') بتضمّن وسوم التطبيق');

/* توافق رجعي: بانر التثبيت في `m/home.php` بيعتمد على الاسمين دول بالحرف */
$hcode = code_only($head);
str_contains($hcode, '__almInstall') && str_contains($hcode, 'alm-installable')
    ? ok('`window.__almInstall` و`alm-installable` لسه موجودين (بانر المندوب معتمد عليهم)')
    : bad('اسم حدث التثبيت اتغيّر — بانر «ثبّت التطبيق» في `/m` هيقف بالسكوت');

/* ══════════════ 5) زرار التثبيت في المكتب ══════════════ */
sec('5) زرار «ثبّت التطبيق»');

preg_match('/id="pwaInstallBtn"[^>]*hidden/', $layout)
    ? ok('الزرار مخفي افتراضيًّا (بيظهر بس لو التثبيت متاح على الجهاز)')
    : bad('زرار التثبيت مش موجود أو مش مخفي افتراضيًّا');
str_contains($hcode, 'pwaInstallBtn')
    ? ok('الزرار متوصّل بمنطق التثبيت')
    : bad('الزرار مش متوصّل بأي منطق');

/* ⚠️ قاعدة المشروع: أي كلاس مستعمل وغير معرَّف بيقع على الشكل الافتراضي
   بالسكوت (نفس باج `.btn.primary` و`.table-mobile-scroll`). */
str_contains($css, '.pwa-install')
    ? ok('`.pwa-install` معرَّف في `app.css`')
    : bad('`.pwa-install` مستعمل وغير معرَّف — بيقع على الشكل الافتراضي بالسكوت');

/* آيفون مالوش حدث تثبيت — لازم نوريه الخطوات بدل ما الزرار ما يعملش حاجة */
str_contains($hcode, 'iPad|iPhone|iPod')
    ? ok('الآيفون بياخد خطوات «إضافة إلى الشاشة الرئيسية» (مفيش حدث تثبيت عنده)')
    : bad('مفيش تعامل مع الآيفون — الزرار هيبقى بلا فايدة هناك');

/* ══════════════ 6) شاشة البدء — السلوك ══════════════ */
sec('6) سلوك شاشة البدء');

str_contains($hcode, "display-mode: standalone")
    ? ok('بتظهر بس لما التطبيق يكون مثبَّتًا (مش في المتصفح العادي)')
    : bad('شاشة البدء مش متقيّدة بالتطبيق المثبَّت — هتزعّج في المتصفح');
str_contains($hcode, 'sessionStorage')
    ? ok('مرّة واحدة كل تشغيل — مش مع كل صفحة')
    : bad('شاشة البدء هتتكرّر مع كل صفحة (إزعاج مش لمسة)');
str_contains($hcode, 'setTimeout(hide, 6000)')
    ? ok('حزام أمان: بتتشال بعد ٦ ثواني مهما حصل')
    : bad('مفيش حزام أمان — ممكن تفضل عالقة على الشاشة');
str_contains($hcode, 'removeChild')
    ? ok('بتتشال من الصفحة خالص بعد الاختفاء (مش بتفضل تغطّي الضغطات)')
    : bad('شاشة البدء ما بتتشالش من الـDOM');
str_contains($hcode, 'document.body || document.documentElement')
    ? ok('بتتلزق في `<html>` (لأن `body` لسه ما اتفكّش وقت الرأس)')
    : bad('اللزق في `body` بس — هتفشل لأنها بتتنفّذ من الرأس');
str_contains($head, 'prefers-reduced-motion')
    ? ok('بتحترم «تقليل الحركة»')
    : bad('مفيش احترام لـ`prefers-reduced-motion`');

/* عربية النقل على الطريق — الحركة اللي بتقول «إحنا شركة نقل» */
str_contains($hcode, 'pwa-truck') && str_contains($hcode, 'pwa-road')
    ? ok('عربية النقل والطريق موجودين في شاشة البدء')
    : bad('عربية النقل اتشالت من شاشة البدء');
str_contains($head, '@keyframes pwaWheel') && str_contains($head, '@keyframes pwaAsphalt')
    ? ok('العجل بيلفّ والطريق بيجري (مش صورة واقفة)')
    : bad('حركة العجل أو الطريق ناقصة');

/* ⚠️ نفس مصيدة `.table-mobile-scroll` الموثَّقة: كلاس بيتحقن من الجافاسكريبت
   وما لوش ولا قاعدة CSS = بيقع على الشكل الافتراضي **بالسكوت**. */
$injected = ['pwa-stage', 'pwa-card', 'pwa-road', 'pwa-truck', 'pwa-sub', 'wh', 'sp'];
$noStyle = [];
foreach ($injected as $cls) {
    $needle = $cls === 'wh' || $cls === 'sp' ? '.' . $cls . '{' : '.' . $cls;
    if (!str_contains($head, $needle)) { $noStyle[] = $cls; }
}
$noStyle
    ? bad('كلاسات بتتحقن بلا أي CSS (هتقع على الشكل الافتراضي بالسكوت): ' . implode(', ', $noStyle))
    : ok('كل كلاس بتحقنه الشاشة (' . count($injected) . ') له تنسيق فعلاً');

/* أول فريم على الآيفون صورة ثابتة — لازم تكون فيها نفس العربية والطريق */
$gen = (string) @file_get_contents($root . '/bin/make_pwa_assets.py');
str_contains($gen, 'draw_road')
    ? ok('صورة البدء الثابتة فيها نفس الطريق والعربية (مفيش قفزة بين الاتنين)')
    : bad('صورة البدء الثابتة اختلفت عن شاشة الـHTML');

/* ══════════════ 7) الـService Worker ══════════════ */
sec('7) الـService Worker');

$swc = code_only($sw);
str_contains($swc, "mode === 'navigate'") && str_contains($swc, 'fetch(req).catch')
    ? ok('كل تنقّل بيروح للشبكة الأول — **مفيش كاش HTML** (تطبيق ورا تسجيل دخول)')
    : bad('⚠️ الـSW ممكن يكاش HTML — خطر عرض بيانات مستخدم تاني');
!preg_match('/cache\.put\(\s*req/', str_replace("url.pathname.startsWith('/assets/')", '', $swc)) || str_contains($swc, "startsWith('/assets/')")
    ? ok('الكاش للأصول الثابتة (`/assets/`) بس')
    : bad('الكاش بيشمل حاجة غير الأصول الثابتة');

foreach (['/assets/offline.html', '/assets/m-offline.html'] as $off) {
    if (!str_contains($sw, $off)) { bad("صفحة «مفيش نت» `$off` مش مربوطة في الـSW"); continue; }
    is_file($root . '/public' . $off)
        ? ok("صفحة «مفيش نت» موجودة: `$off`")
        : bad("`$off` مربوطة في الـSW ومش موجودة على القرص — الكاش هيفشل بالكامل");
}
preg_match("/VERSION\s*=\s*'([^']+)'/", $sw, $vm) && $vm[1] !== 'alm-m-v1'
    ? ok("نسخة الكاش اتزوّدت (`{$vm[1]}`) فالأجهزة المثبَّتة هتاخد التحديث")
    : bad('نسخة الكاش ما اتغيّرتش — الأجهزة القديمة هتفضل بالنسخة القديمة');

/* نوع الملف لازم يتظبط في .htaccess وإلا بعض السيرفرات بترفض الـmanifest */
$ht = (string) @file_get_contents($root . '/public/.htaccess');
str_contains($ht, '.webmanifest')
    ? ok('`.htaccess` بيظبط نوع ملف الـmanifest')
    : bad('`.htaccess` مفيهوش `AddType` للـwebmanifest');

/* ══════════════ 8) العزل ══════════════ */
sec('8) العزل — عرض بحت بلا أي أثر مالي');

$dirty = [];
foreach (['INSERT', 'UPDATE ', 'DELETE', 'Database::', 'Closing::', 'Custody::', 'RepCustody::'] as $needle) {
    if (str_contains($hcode, $needle)) { $dirty[] = $needle; }
}
$dirty ? bad('ملف التطبيق بيلمس القاعدة أو حسابًا ماليًّا: ' . implode(', ', $dirty))
       : ok('`_pwa_head.php` ما بيلمسش القاعدة ولا أي حساب مالي');

/* ⚠️ قاعدة أمنية قايمة: ممنوع `json_encode` في أي ملف داخل app/Views */
preg_match('/\bjson_encode\s*\(/', $hcode)
    ? bad('`json_encode` في ملف view — الصح `js_json()` (نص «</script>» بيقفل الوسم)')
    : ok('بيستخدم `js_json()` مش `json_encode`');

/* رندر فعلي للوسوم — الفشل الصامت الوحيد اللي فحص النص ما بيمسكهوش */
foreach (['office' => 'manifest-office.webmanifest', 'rep' => 'manifest.webmanifest'] as $app => $expect) {
    ob_start();
    try {
        $pwaApp = $app;
        require $root . '/app/Views/_pwa_head.php';
        $out = (string) ob_get_clean();
    } catch (\Throwable $e) {
        ob_end_clean();
        bad("رندر وسوم `$app` رمى استثناء: " . $e->getMessage());
        continue;
    }
    str_contains($out, $expect) && str_contains($out, 'apple-touch-startup-image')
        ? ok("وسوم `$app` بترندر صح وبتشاور على `$expect`")
        : bad("وسوم `$app` مش بترندر الـmanifest الصح");
    !str_contains($out, '<script src=')
        ? ok("وسوم `$app` مفيهاش أي سكربت خارجي (CSP `default-src 'self'`)")
        : bad("وسوم `$app` بتحمّل سكربت خارجي");
}

/* ══════════════ الخلاصة ══════════════ */
echo "\n" . str_repeat('=', 62) . "\n";
echo "نجح: $pass   |   فشل: $fail   |   تنبيه: $warn   |   تخطّي: $skip\n";
if ($fail > 0) {
    echo "\n✗ الفحص فشل — الكود ده ما يترفعش.\n";
    exit(1);
}
echo "\n✓ كل الفحوصات عدّت.\n";
exit(0);
