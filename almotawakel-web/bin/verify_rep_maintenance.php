<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص «قفل بوابة المندوب للصيانة» (v68).
 *
 * الميزة: المكتب بيقفل `/m` وهو بيظبّط حاجة، المناديب بياخدوا رنّة، وشاشتهم
 * بتفتح لوحدها أول ما الصيانة تخلص + رنّة تانية.
 *
 * أخطر ٦ حاجات محروسة هنا — كل واحدة سبب فشل حقيقي لو اتكسرت:
 *
 *   ١) **كل action في البوابة بيعدّي على `requireRep()`.** ده مكان القفل
 *      الوحيد، فـaction جديد بينسى النداء = ثغرة بتفضل مفتوحة والبوابة
 *      «مقفولة» ظاهريًّا. الفحص بيعدّ نقاط الاستدعاء مش بيقرا الكود بعينه.
 *
 *   ٢) **`ping` مستثنى فعلاً.** هو اللي بيخلّي التطبيق المفتوح يعرف إن
 *      الصيانة بدأت أو خلصت. لو اتقفل زيّهم، الشاشة بتتجمّد على حالة قديمة
 *      وأهم نص في الميزة («بيفتح لوحده») بيبقى كذب.
 *
 *   ٣) **الحارس العميق في الموديل.** الفورم مش مصدر ثقة (قاعدة ③): POST
 *      مزوّر لازم يترفض قبل أي صف يتكتب.
 *
 *   ٤) **صفر أثر مالي.** رصيد الخزنة وعهدة المناديب قبل = بعد. الوحدة دي
 *      عمرها ما تلمس مليم.
 *
 *   ٥) **الصيانة ما بتعملش تسجيل خروج.** لو لمست الجلسة، مسودة البند اللي
 *      المندوب كاتبه نص في الميدان بتضيع.
 *
 *   ٦) **الانتهاء التلقائي شغّال بلا العامل الخلفي.** الاستضافة مقفّلة
 *      تشغيل العمليات، فلو البوابة مستنية كرون عشان تفتح ممكن تفضل مقفولة.
 *
 * ⚠️ الفحص بيلفّ نفسه في transaction + rollback، وبيرجّع مفاتيح `settings`
 *    لحالتها الأصلية في `finally` — فما بيسيبش أي أثر حتى لو وقع في نصّه.
 *
 * التشغيل:  php bin/verify_rep_maintenance.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Database;
use App\Models\Notify;
use App\Models\RepMaintenance;

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function sec(string $m): void { echo "\n" . $m . "\n" . str_repeat('─', 66) . "\n"; }
function chk(bool $c, string $m): void { $c ? ok($m) : bad($m); }

/** كود الملف بلا تعليقات — المصيدة الموثَّقة: التعليق بيذكر النص الممنوع. */
function codeOf(string $file): string
{
    $p = dirname(__DIR__) . '/' . $file;
    if (!is_file($p)) { return ''; }
    $out = '';
    foreach (token_get_all((string) file_get_contents($p)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

echo "فحص قفل بوابة المندوب للصيانة (v68)\n" . str_repeat('=', 66) . "\n";

// نحفظ الحالة الأصلية ونرجّعها مهما حصل
$saved = [
    'rep_portal_maint'         => (string) App::setting('rep_portal_maint', '0'),
    'rep_portal_maint_msg'     => (string) App::setting('rep_portal_maint_msg', ''),
    'rep_portal_maint_until'   => (string) App::setting('rep_portal_maint_until', '0'),
    'rep_portal_maint_started' => (string) App::setting('rep_portal_maint_started', '0'),
];

try {

// ═══════════════════════════════════════════════════════════════════════
sec('① نقطة القفل الوحيدة — كل action في البوابة بيعدّي عليها');

$ctrl = codeOf('app/Controllers/RepPortalController.php');

/* بنجمع كل الـactions العامة (اللي الراوتر بينادّيها) ونتأكد إن كل واحدة
   بتنادي `requireRep(`. القايمة البيضا **معلَنة هنا صراحةً** فأي استثناء
   جديد لازم يعدّي على الفحص ده، مش يتزوّغ منه. */
$whitelist = ['showLogin', 'login', 'logout'];   // بيتحرسوا بـguardMaintenance مباشرةً أو مستثنيين

preg_match_all('~public function ([a-zA-Z0-9_]+)\s*\([^)]*\)\s*:\s*void\s*\{(.*?)\n    \}~s', $ctrl, $mm);
$actions = [];
foreach ($mm[1] as $i => $name) { $actions[$name] = $mm[2][$i]; }

chk(count($actions) >= 20, 'الفحص شايف actions البوابة فعلاً (' . count($actions) . ' action)');

$missing = [];
foreach ($actions as $name => $body) {
    if (in_array($name, $whitelist, true)) { continue; }
    if (!str_contains($body, 'requireRep(')) { $missing[] = $name; }
}
chk($missing === [],
    'كل action بينادي `requireRep()` — مكان القفل الوحيد'
    . ($missing ? ' — ناقصة في: ' . implode(', ', $missing) : ''));

// والمستثنيين لازم يكونوا بيحرسوا نفسهم بنداء مباشر
foreach (['showLogin', 'login'] as $name) {
    chk(isset($actions[$name]) && str_contains($actions[$name], 'guardMaintenance('),
        "`{$name}` بيحرس نفسه بـ`guardMaintenance()` (شاشة الدخول مقفولة هي كمان)");
}

chk(str_contains($ctrl, 'private function guardMaintenance')
    && str_contains($ctrl, 'RepMaintenance::isOn()'),
    'و`guardMaintenance()` بتسأل `RepMaintenance::isOn()` — مصدر واحد للحقيقة');

chk(str_contains($ctrl, 'http_response_code(503)'),
    'وبترجّع 503 مش 200 (الحالة مؤقتة — الـPWA والمتصفح ما يعاملوهاش كصفحة النظام)');

// ═══════════════════════════════════════════════════════════════════════
sec('② `ping` مستثنى — وده اللي بيخلّي الشاشة تفتح لوحدها');

chk(isset($actions['ping']) && str_contains($actions['ping'], 'requireRep(true)'),
    '`ping` بينادي `requireRep(true)` — المستثنى المعلَن الوحيد');

chk(isset($actions['ping']) && str_contains($actions['ping'], "'maintenance' => 1"),
    'وبيرجّع `maintenance` فالتطبيق المفتوح يعرف يقفل نفسه فورًا');

$homeView = (string) file_get_contents(dirname(__DIR__) . '/app/Views/m/home.php');
chk(str_contains($homeView, 'd.maintenance'),
    'والشاشة الرئيسية بتقرا العلامة وبتحوّل نفسها لشاشة الصيانة');

$mtView = (string) file_get_contents(dirname(__DIR__) . '/app/Views/m/maintenance.php');
chk(str_contains($mtView, "url('m/ping')") && str_contains($mtView, 'location.reload'),
    'وشاشة الصيانة بتسأل `m/ping` وبتفتح لوحدها أول ما القفل يترفع');

/* ⚠️ **مفيش أي `requireRep()` تاني بلا `true` في `ping`** — لو حد ضاف
   واحدة تانية بالغلط، الاستثناء بيتلغي والشاشة تتجمّد. */
chk(isset($actions['ping']) && substr_count($actions['ping'], 'requireRep(') === 1,
    'ونداء واحد بس جوّه `ping` (التاني بيلغي الاستثناء بالسكوت)');

// ═══════════════════════════════════════════════════════════════════════
sec('③ الحارس العميق في الموديل — الفورم مش مصدر ثقة');

$portal = codeOf('app/Models/RepPortal.php');
$n = substr_count($portal, 'RepMaintenance::assertOpen()');
chk($n >= 8, "`assertOpen()` متنادية في {$n} نقطة كتابة في `RepPortal` (الحد ٨)");

foreach (['submit', 'createPermit', 'deleteMine', 'createBill'] as $fn) {
    $found = preg_match('~function ' . $fn . '\([^)]*\)[^{]*\{(.{0,400})~s', $portal, $m)
             && str_contains($m[1], 'RepMaintenance::assertOpen()');
    chk($found, "و`{$fn}()` بترفض قبل أي كتابة");
}

chk(str_contains(codeOf('app/Models/RepTransfer.php'), 'RepMaintenance::assertOpen()'),
    'وتحويل العهدة بين المناديب محروس كمان');

/* ⚠️ **الحارس على مسارات المندوب وحدها.** لو دخل على موديل مشترك زي
   `RepCustody::clearSnapshot`، الصيانة كانت هتوقّف **المكتب** عن تسوية
   حساب مندوب — وهي المفروض تقفل بوابته هو بس. */
chk(!str_contains(codeOf('app/Models/RepCustody.php'), 'RepMaintenance::'),
    'ومش داخل أي موديل مشترك مع المكتب (`RepCustody` نضيفة)');

// ═══════════════════════════════════════════════════════════════════════
sec('④ الصيانة ما بتعملش تسجيل خروج — المسودة بتنجو');

$model = codeOf('app/Models/RepMaintenance.php');
foreach (['session_destroy', 'RepAuth::logout', '$_SESSION'] as $forbidden) {
    chk(!str_contains($model, $forbidden),
        "`RepMaintenance` ما بتلمسش `{$forbidden}`");
}
preg_match('~private function guardMaintenance\(\)\s*:\s*void\s*\{(.*?)\n    \}~s', $ctrl, $gm);
$guardBody = $gm[1] ?? '';
chk($guardBody !== '' && !preg_match('~logout|session_destroy|session_regenerate~', $guardBody),
    'و`guardMaintenance()` بتعرض شاشة وبس — ولا سطر طرد جوّاها');

// ═══════════════════════════════════════════════════════════════════════
sec('⑤ الإشعار — النوعين مسجَّلين في التلات خرايط');

foreach (['rep.maintenance.on', 'rep.maintenance.off'] as $kind) {
    chk(isset(Notify::ROUTES[$kind]), "`{$kind}` في `Notify::ROUTES`");
    chk(isset(Notify::KINDS[$kind]),  "و في `Notify::KINDS`");
    chk((Notify::ROUTES[$kind][0] ?? '') === 'rep',
        'وجمهوره **المناديب وحدهم** (المكتب شايف الشريط الأحمر أصلاً)');
}

/* ⚠️ أيقونة نوع الإشعار لازم في **الاتنين**: `icon()` للصفحة وخريطة
   `app.js` للجرس. الناقصة بتقع على شكل افتراضي بالسكوت. */
$fallback = icon('__لا_يوجد__', 14);
foreach (['rep.maintenance.on', 'rep.maintenance.off'] as $kind) {
    $ic = Notify::KINDS[$kind]['icon'] ?? '';
    chk($ic !== '' && icon($ic, 14) !== $fallback, "وأيقونة `{$kind}` معرَّفة في `icon()`");
}
$appJs = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js');
chk(str_contains($appJs, 'wrench:'), 'و`wrench` في خريطة أيقونات الجرس في `app.js`');

chk(str_contains(codeOf('app/Models/PushNotify.php'), "'rep_url' => 'm'"),
    'والرنّة حاملة `meta.rep_url` — بلاها `urlFor` بتشيل الرابط ويوصل خبر بلا وجهة');

// ═══════════════════════════════════════════════════════════════════════
sec('⑥ الانتهاء التلقائي — شغّال بلا العامل الخلفي');

Database::conn()->beginTransaction();

// (أ) مقفولة بلا وقت → مقفولة
App::setSetting('rep_portal_maint', '1');
App::setSetting('rep_portal_maint_until', '0');
chk(RepMaintenance::isOn(), 'مقفولة بلا وقت محدَّد → مقفولة');

// (ب) وقتها لسه ما عدّاش → مقفولة
App::setSetting('rep_portal_maint_until', (string) (time() + 600));
chk(RepMaintenance::isOn(), 'ووقتها لسه فاضل → مقفولة');
chk(RepMaintenance::minutesLeft() >= 9 && RepMaintenance::minutesLeft() <= 10,
    'والدقايق الفاضلة بتتحسب صح (' . RepMaintenance::minutesLeft() . ')');

// (ج) وقتها عدّى → **مفتوحة فورًا** بلا أي عامل خلفي
App::setSetting('rep_portal_maint_until', (string) (time() - 60));
chk(!RepMaintenance::isOn(),
    'ووقتها عدّى → **مفتوحة فورًا** بلا انتظار كرون (الاستضافة مقفّلة العمليات)');

$st = RepMaintenance::state();
chk($st['flagged'] === true && $st['due'] === true && $st['on'] === false,
    'والحالة بتقول «المفتاح مرفوع بس مستحق التصفير» — ده شغل العامل');

// (د) `assertOpen` بترفض وهي مقفولة وبتعدّي وهي مفتوحة
App::setSetting('rep_portal_maint_until', (string) (time() + 600));
$threw = false;
try { RepMaintenance::assertOpen(); } catch (\RuntimeException $e) { $threw = true; }
chk($threw, 'و`assertOpen()` بترمي وهي مقفولة');

App::setSetting('rep_portal_maint', '0');
$threw = false;
try { RepMaintenance::assertOpen(); } catch (\RuntimeException $e) { $threw = true; }
chk(!$threw, 'وبتعدّي عادي وهي مفتوحة');

// (هـ) المدة بتتحبس في حدودها — الفورم مش مصدر ثقة
App::setSetting('rep_portal_maint', '0');
$before = (int) App::setting('rep_portal_maint_until', '0');
chk(RepMaintenance::MAX_MINUTES === 720 && RepMaintenance::MIN_MINUTES === 5,
    'وحدود المدة معرَّفة (٥ دقايق → ١٢ ساعة)');

// ═══════════════════════════════════════════════════════════════════════
sec('⑦ العزل — صفر أثر مالي');

/* ⚠️ الفحص ده هو الخط الأحمر: الوحدة وصفية بحتة، فأي استعلام مالي جوّاها
   معناه إن قفل شاشة بقى بيحرّك فلوس. */
foreach (['safe', 'closing', 'movement', 'rep_deposits', 'permit_stages', 'manual_expenses'] as $tbl) {
    chk(!str_contains(mb_strtolower($model), $tbl),
        "`RepMaintenance` ما بتذكرش `{$tbl}`");
}

/* ⚠️ من `Custody::balance()` — **المصدر الرسمي** لرصيد الخزنة، زي
   `verify_density`. استعلام مكتوب بالإيد هنا بيتفرّع عن المعادلة الحقيقية. */
$safeBefore = (float) \App\Models\Custody::balance();

RepMaintenance::start('فحص آلي', 30, 'الفاحص');
$onOk = RepMaintenance::isOn();
RepMaintenance::end('الفاحص');
$offOk = !RepMaintenance::isOn();

chk($onOk && $offOk, 'و`start()` بتقفل و`end()` بتفتح فعلاً');

$safeAfter = (float) \App\Models\Custody::balance();
chk(abs($safeAfter - $safeBefore) < 0.005,
    'ورصيد خزنة المكتب قبل = بعد (' . number_format($safeBefore, 2) . ') — الخط الأحمر');

// ═══════════════════════════════════════════════════════════════════════
sec('⑧ المكتب بيشوف إن البوابة مقفولة — في كل شاشة');

$layout = (string) file_get_contents(dirname(__DIR__) . '/app/Views/layout.php');
chk(str_contains($layout, 'RepMaintenance::isOn()') && str_contains($layout, 'maint-bar'),
    'الشريط في `layout.php` — يعني كل شاشة مكتب، مش صفحة الإعدادات بس');

$appCss = (string) file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');
chk(str_contains($appCss, '.maint-bar{'),
    'وله CSS فعلاً (الكلاس بلا قاعدة بيقع على الشكل الافتراضي بالسكوت)');

/* ⚠️ لازم يكون **قبل** المقطع الليلي في `app.css` — الإلحاق في الآخر
   بيحطّه جوّه `@media (prefers-color-scheme: dark)` فيشتغل بالليل بس. */
$posBar  = strpos($appCss, '.maint-bar{');
$posDark = strpos($appCss, '@media (prefers-color-scheme: dark)');
chk($posBar !== false && $posDark !== false && $posBar < $posDark,
    'وقبل المقطع الليلي (الإلحاق في الآخر بيدفنه جوّه الوضع الليلي)');

chk(str_contains(codeOf('app/Controllers/SettingsController.php'), 'RepMaintenance::start('),
    'وفيه مكان في الإعدادات بيقفل ويفتح');

chk(str_contains(codeOf('bin/push_worker.php'), 'RepMaintenance::expireIfDue()'),
    'والعامل الخلفي بيصفّر المفتاح ويبعت «رجعنا»');

} finally {
    if (Database::conn()->inTransaction()) { Database::conn()->rollBack(); }
    // ⚠️ `settings` بتتكتب برّه المعاملة في بعض المسارات — بنرجّعها صراحةً
    foreach ($saved as $k => $v) { App::setSetting($k, $v); }
}

// ═══════════════════════════════════════════════════════════════════════
echo "\n" . str_repeat('=', 66) . "\n";
echo "نجح: {$pass}   |   فشل: {$fail}\n";
if ($fail) {
    echo "\n✗ الفحص فشل — الكود ده ما يترفعش.\n";
    exit(1);
}
echo "\n✓ كل الفحوصات عدّت.\n";
