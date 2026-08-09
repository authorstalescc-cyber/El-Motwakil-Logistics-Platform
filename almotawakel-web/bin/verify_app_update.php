<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص «التحديث الإجباري» (2026-07-28) — نظام المكتب وبوابة المناديب.
 *
 * الميزة دي **بتفشل بالسكوت** بطبيعتها: لو البصمة ما اتغيّرتش مع الرفع، أو
 * حلقة الـping بطّلت تمرّرها، أو الملف ما اتحمّلش في أحد التطبيقين — مفيش أي
 * خطأ بيظهر، بس المستخدمين بيفضلوا على نسخة قديمة ومحدش واخد باله.
 *
 * بيحرس:
 *   1) `App::buildId()` بتتغيّر مع كود العميل، بما فيه قوالب بوابة المندوب
 *   2) البصمة بتترسم في الصفحة نفسها (الأساس)، مش بتتاخد من أول رد
 *   3) الملف متحمّل في **التلات مداخل**: تخطيط المكتب · الدخول · بوابة المناديب
 *   4) الـping الاتنين بيمرّروا `build` (تغذية بصفر طلب زيادة)
 *   5) `/build` مسجَّل، ورده **ختم وبس** بلا أي بيانات
 *   6) حماية الإدخال: مفيش إعادة تحميل والشاشة فيها كتابة نص طريق
 *   7) حزام أمان ضد حلقة إعادة تحميل
 *   8) كل كلاس بتحقنه الوحدة له CSS في **app.css وm.css** مع بعض
 *   9) عزل كامل: ولا لمسة للقاعدة ولا لأي حساب مالي
 *
 * آمن تمامًا: **قراءة فقط** — مفيش أي كتابة على القرص ولا على القاعدة.
 *
 * التشغيل:  php bin/verify_app_update.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;

$pass = 0; $failures = [];
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $failures; $failures[] = $m; echo "  ✗ $m\n"; }
function chk(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function sec(string $m): void { echo "\n$m\n" . str_repeat('─', 62) . "\n"; }

/** يشيل تعليقات PHP/JS — تعليق بيشرح نمطًا ما يعتبرش استخدامًا له. */
function code_only(string $s): string
{
    $s = (string) preg_replace('#/\*.*?\*/#s', '', $s);
    return (string) preg_replace('#^\s*//.*$#m', '', $s);
}

$root   = dirname(__DIR__);
$js     = (string) file_get_contents($root . '/public/assets/js/app-update.js');
$jsCode = code_only($js);
$appJs  = (string) file_get_contents($root . '/public/assets/js/app.js');
$head   = (string) file_get_contents($root . '/app/Views/_pwa_head.php');
$layout = (string) file_get_contents($root . '/app/Views/layout.php');
$login  = (string) file_get_contents($root . '/app/Views/auth/login.php');
$mPwa   = (string) file_get_contents($root . '/app/Views/m/_pwa.php');
$mHome  = (string) file_get_contents($root . '/app/Views/m/home.php');
$router = (string) file_get_contents($root . '/public/index.php');
$notifC = (string) file_get_contents($root . '/app/Controllers/NotificationController.php');
$repC   = (string) file_get_contents($root . '/app/Controllers/RepPortalController.php');
$appPhp = (string) file_get_contents($root . '/app/Core/App.php');
$css    = (string) file_get_contents($root . '/public/assets/css/app.css');
$mCss   = (string) file_get_contents($root . '/public/assets/css/m.css');

echo "فحص التحديث الإجباري\n" . str_repeat('=', 62) . "\n";

// ═══ ① البصمة
sec('① بصمة النسخة — بتتغيّر لوحدها');
$b1 = App::buildId();
chk((bool) preg_match('/^[a-f0-9]{8}$/', $b1), "بتطلع بصمة قصيرة صالحة ($b1)");
chk(App::buildId() === $b1, 'وثابتة داخل نفس الطلب (static cache — مفيش glob متكرر)');

/* ⚠️ المصدر لازم يفضل **تواريخ الملفات** لا رقم مكتوب بالإيد ولا ملف بيكتبه
   سكربت النشر: الرفع بيحصل أحيانًا بسكربت مقصور، فأي خطوة «افتكر تزوّد الرقم»
   بتتنسي والميزة تقف بالسكوت. */
chk(str_contains($appPhp, 'filemtime') && str_contains($appPhp, "glob(self::basePath('public/assets/css/*.css')"),
    '⚠️ مشتقّة من تواريخ ملفات العميل (مش رقم بالإيد يتنسي مع الرفع المقصور)');
chk(str_contains($appPhp, "public/sw.js"), 'والـService Worker داخل الحساب');
/* الـHTML المفتوح في تطبيق المندوب يفضل في الذاكرة حتى لو Service Worker
   network-first؛ لازم تعديل القالب نفسه يغيّر البصمة ويجبره يحدّث. */
chk(str_contains($appPhp, "glob(self::basePath('app/Views/m/*.php')"),
    '⚠️ وقوالب بوابة المندوب داخل الحساب (إصلاح PHP يوصل للشاشة المفتوحة)');

// ═══ ② الأساس من الصفحة
sec('② الأساس بيترسم في الصفحة — مش بيتاخد من أول رد');
chk(str_contains($head, 'window.__almBuild='),
    '⚠️ `_pwa_head.php` بيرسم `__almBuild` وقت الرندر');
chk(str_contains($head, 'App::buildId()'), 'من نفس المصدر (`App::buildId`)');
chk(str_contains($jsCode, 'window.__almBuild'), 'والوحدة بتقرا الأساس منه');
chk(str_contains($jsCode, 'if (!BASE) { return; }'),
    'ولو الصفحة ما رسمتش بصمة بتقف بدل ما تخمّن');
/* لو الأساس اتاخد من أول رد، اللي فتح الصفحة قبل الرفع وأول نداء له جه بعده
   كان هياخد الجديدة أساسًا ويفضل على صفحة قديمة للأبد */
chk(str_contains($js, 'لو الأساس اتاخد من أول رد'), 'والمصيدة موثَّقة جنب الكود');

// ═══ ③ التلات مداخل
sec('③ الوحدة متحمّلة في التطبيقين');
chk(str_contains($head, 'assets/js/app-update.js'),
    'بتتحمّل من `_pwa_head.php` (سطر واحد بيخدم التلاتة)');
chk(str_contains($head, "?v=<?= is_file(\$__updJs)"), 'وبـcache busting');
foreach ([['layout.php', $layout], ['auth/login.php', $login], ['m/_pwa.php', $mPwa]] as [$name, $src]) {
    chk(str_contains($src, '_pwa_head.php'), "`$name` بيضمّ `_pwa_head.php` فبياخدها");
}
/* بوابة المناديب ما بتحمّلش app.js — لو المنطق عاش هناك كان هيخدم نص النظام بس */
chk(!str_contains($mPwa, 'assets/js/app.js'),
    '⚠️ والبوابة فعلاً ما بتحمّلش `app.js` (فمكان المنطق في ملف مشترك مش فيه)');

// ═══ ④ التغذية بصفر طلب زيادة
sec('④ التغذية من نداءات الـping القايمة');
chk(str_contains($notifC, "'build'  => \\App\\Core\\App::buildId()"),
    'ping المكتب بيرجّع `build`');
/* ⚠️ **بمطابقة مرنة مش بالنص الحرفي**: الفحص كان مربوطًا بشكل المصفوفة
   بالظبط (`['build' => …]`)، فأول ما `ping` ضاف مفاتيح تانية (حالة الصيانة
   وعدّاد الجرس — v68) فشل بإنذار كاذب رغم إن `build` لسه بترجع. المطلوب
   إثباته إن النداء بيحمل `build`، مش إزاي المصفوفة متكتبة. */
chk((bool) preg_match('~[\'"]build[\'"]\s*=>\s*\\\\App\\\\Core\\\\App::buildId\(\)~', $repC),
    'وping المندوب كمان');
chk(str_contains($appJs, 'window.AppUpdate.seen(d.build)'),
    'و`app.js` بيمرّرها للوحدة');
chk(str_contains($mHome, 'window.AppUpdate.seen(d.build)'),
    'وشاشة المندوب الرئيسية كمان');
/* الشاشات اللي مالهاش نداء بتسأل بنفسها — بس بعد ما تتأكد إن مفيش حد بيغذّيها */
chk(str_contains($jsCode, 'if (lastSeenAt) { return; }'),
    '⚠️ والنداء الذاتي بيتسلّح **بس** لو مفيش تغذية (مفيش طلب مكرر)');

// ═══ ⑤ مسار /build
sec('⑤ مسار `/build`');
chk(str_contains($router, "\$r->get('/build',                     'NotificationController@build');"),
    'مسجَّل في الراوتر');
chk(str_contains($notifC, 'public function build(): void'), 'والأكشن موجود');
/* ⚠️ الرد **ختم وبس** — أول ما يرجّع أي حاجة تخصّ مستخدمًا أو النظام بيتحوّل
   من ختم عام لتسريب، وساعتها يبقى محتاج حارس والحارس بيكسر شاشة الدخول. */
if (preg_match('/public function build\(\): void\s*\{(.*?)\n    \}/s', $notifC, $m)) {
    $body = $m[1];
    chk(str_contains($body, "\$this->json(['build' =>") && substr_count($body, '=>') === 1,
        '⚠️ ورده مفتاح واحد بس (`build`) — مفيش أي بيانات تتسرّب');
    chk(str_contains($body, 'exit;'), 'وفيه `exit` بعد `json()` (قاعدة v50)');
    chk(!preg_match('/requireAuth|requireRep|requireAnySession/', $body),
        'وبلا حارس جلسة عن قصد — عشان شاشة الدخول ما تفضلش تاخد 401 كل دقيقة');
} else {
    bad('مش لاقي جسم `build()` — الفحص ما قدرش يتأكد من محتوى الرد');
}

// ═══ ⑥ حماية الإدخال — قرار المالك
sec('⑥ إجباري بس ما بيضيّعش إدخال');
chk(str_contains($jsCode, "if (dirty) { banner(); } else { go(); }"),
    '⚠️ الشاشة النضيفة بتتحدّث لوحدها، واللي فيها كتابة بتاخد لافتة');
chk(str_contains($jsCode, "document.addEventListener('input', function () { dirty = true; }, true)"),
    'وأي كتابة بتخلّي الشاشة «متّسخة»');
chk(str_contains($jsCode, ", true);") && substr_count($jsCode, ', true);') >= 3,
    '⚠️ والاستماع في مرحلة الالتقاط (معالج تاني ممكن يوقف الحدث)');
chk(str_contains($jsCode, "document.addEventListener('submit', function () { dirty = false; }, true)"),
    'والإرسال بيفكّ الاتّساخ (الانتقال جاي بأحدث كود لوحده)');
chk(str_contains($jsCode, 'window.confirm('),
    'وزرار «حدّث دلوقتي» بيأكّد لو فيه إدخال (ضغطة غلط ما تضيّعش شغل)');
chk(str_contains($jsCode, "if (!document.hidden && pending && !dirty) { go(); }"),
    'وبيعيد المحاولة لما المستخدم يرجع للتبويب وهو مش بيكتب');

// ═══ ⑦ أحزمة الأمان
sec('⑦ أحزمة الأمان');
chk(str_contains($jsCode, "sessionStorage.getItem(RELOAD_KEY) === pending"),
    '⚠️ مفيش حلقة إعادة تحميل (بيتحقّق إنه ما رجعش لنفس النسخة)');
chk(str_contains($jsCode, 'r.update()'),
    'وبيحدّث الـService Worker قبل إعادة التحميل (وإلا عامل قديم يخدم الصفحة)');
chk(str_contains($jsCode, 'document.hidden'),
    'والنداء الذاتي بيقف والتبويب متخفي');
chk(str_contains($jsCode, 'textContent'), 'ونص اللافتة بيتحطّ بـ`textContent`');
chk(!preg_match('/innerHTML/', $jsCode), 'وبلا أي `innerHTML` (قاعدة XSS القايمة)');

// ═══ ⑧ كل كلاس محقون له CSS في الاتنين
sec('⑧ مفيش خطّاف بلا تنفيذ');
/* ⚠️ نفس مصيدة `.table-mobile-scroll`: كلاس بيتحقن من الجافاسكريبت وما لوش
   قاعدة = بيقع على الشكل الافتراضي **بالسكوت**. والبوابة ما بتحمّلش app.css
   فلازم يكون معرَّفًا في `m.css` كمان وإلا اللافتة تطلع بلا شكل هناك. */
foreach (['upd-bar', 'upd-btn'] as $cls) {
    chk(str_contains($css, '.' . $cls), "`.$cls` معرَّف في `app.css`");
    chk(str_contains($mCss, '.' . $cls), "`.$cls` معرَّف في `m.css` كمان (البوابة)");
}
chk(str_contains($css, '.upd-txt'), '`.upd-txt` معرَّف في `app.css`');
/* في البوابة فيه تاب بار وشريط حفظ ثابتين — اللافتة لازم تبقى فوقهم */
chk((bool) preg_match('/\.upd-bar\{[^}]*z-index:60/s', $mCss),
    '⚠️ ولافتة البوابة فوق التاب بار وشريط الحفظ (z-index:60 > 40)');
chk((bool) preg_match('/\.upd-bar\{[^}]*z-index:var\(--z-toast\)/s', $css),
    'ولافتة المكتب على مستوى التوستات');
chk(str_contains($css, '@media(prefers-reduced-motion:reduce){.upd-bar{animation:none}}')
    && str_contains($mCss, '@media(prefers-reduced-motion:reduce){.upd-bar{animation:none}}'),
    'وبتحترم «تقليل الحركة» في الاتنين');
/* ⚠️ ما بتزقّش المحتوى: لو زقّت الصفحة والمستخدم بيملا فورم، الحقول تتزحلق
   من تحت إيده وهو بيكتب — وده بالظبط اللي بنحاول نحميه. */
chk(!preg_match('/has-upd-bar[^{]*\{[^}]*padding/s', $css),
    '⚠️ واللافتة بتطفو ولا بتزقّ المحتوى (الفورم ما يتزحلقش تحت إيد المستخدم)');

// ═══ ⑨ العزل
sec('⑨ العزل — عرض بحت');
/* ⚠️ الفحص على **وحدة التحديث نفسها**، مش على `App.php` كله — الملف ده فيه
   `setSetting()` اللي بتكتب في `settings` من زمان، فمسحه بالكامل كان بيدّي
   إنذارًا كاذبًا. النطاق الصح هو الكود اللي الميزة أضافته. */
/* الخاصية اللي تستاهل الحراسة فعلاً: الوحدة **ما بتبعتش حاجة للخادم** غير
   قراءة الختم. أي `POST` هنا معناه إنها بقت بتغيّر حالة — وهي المفروض
   تقرا وتعيد تحميل وبس. */
$dirty = [];
foreach (["method: 'POST'", 'method:"POST"', 'FormData', '_csrf'] as $needle) {
    if (str_contains($jsCode, $needle)) { $dirty[] = $needle; }
}
chk($dirty === [], 'الوحدة ما بتبعتش أي كتابة للخادم (قراءة الختم وبس)'
    . ($dirty ? ': ' . implode(', ', $dirty) : ''));
foreach (['Closing', 'Custody', 'amount', 'balance', 'مبلغ'] as $needle) {
    if (stripos($jsCode, $needle) !== false) { bad("مفهوم مالي تسرّب للوحدة: {$needle}"); }
}
ok('ومفيهاش أي مفهوم مالي');
if (preg_match('/public static function buildId\(\): string\s*\{(.*?)\n    \}/s', $appPhp, $m)) {
    chk(!preg_match('/Database::|INSERT|UPDATE|DELETE/', $m[1]),
        '⚠️ و`buildId()` ما بتلمسش القاعدة خالص (بتقرا تواريخ ملفات وبس)');
} else {
    bad('مش لاقي `buildId()`');
}

echo "\n" . str_repeat('=', 62) . "\n";
echo 'النتيجة: ' . $pass . ' ناجح · ' . count($failures) . " فاشل\n";
if ($failures) { foreach ($failures as $f) { echo "  ✗ $f\n"; } exit(1); }
echo "\n✓ كل الفحوصات عدّت.\n";
exit(0);
