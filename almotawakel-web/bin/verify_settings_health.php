<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص «صحة النظام» والتصدير والاستيراد والرجوع — المرحلة ٧ من docs/19.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  ليه الفاحص ده موجود
 * ═══════════════════════════════════════════════════════════════════════
 * بعد ٦ مراحل بقى فيه **٦٠+ مفتاح** وسلاسل مؤرَّخة وتفضيلات وقائمة وصلاحيات،
 * والمالك مالوش طريقة يعرف بيها إن حاجة متعارضة أو خارج نطاقها.
 *
 * ⚠️ **أخطر بند هنا هو التصدير**: ملف الإعدادات بيتبعت بالإيميل أو بيتحط في
 *    مجلد. لو مفتاح Groq أو VAPID خرج فيه = **تسريب دائم**. الفاحص بيدوّر
 *    على قيمة كل مفتاح سرّي **جوّه ناتج التصدير الحقيقي**، مش بيسأل الكود.
 *
 * ⚠️ **والاستيراد مش باب خلفي**: بيمرّ على `Settings::set()` فالنطاق والدور
 *    والتسجيل بيتطبّقوا — والفاحص بيجرّب يستورد قيمة خارج النطاق ويتأكد
 *    إنها بترفض بدل ما تعدّي.
 *
 * التشغيل:  php bin/verify_settings_health.php
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Access;
use App\Core\App;
use App\Core\Database;
use App\Core\Settings;
use App\Core\SettingsSchema;
use App\Models\Custody;
use App\Models\SettingsHealth;

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function sec(string $m): void { echo "\n" . $m . "\n" . str_repeat('─', 66) . "\n"; }

$root = dirname(__DIR__);
echo "فحص صحة الإعدادات والتصدير (docs/19 · المرحلة ٧)\n" . str_repeat('=', 66) . "\n";

$_SESSION = ['uid' => (int) Database::scalar("SELECT MIN(id) FROM users WHERE role='owner'"),
             'role' => 'owner', 'uname' => 'فحص', 'last_activity' => time(), 'uid_checked' => time()];

$pdo = Database::conn();
$pdo->beginTransaction();
try {

// ═══════════════════════════════════════════════════════════ ①
sec('① ⚠️ التصدير ما بيطلّعش أي مفتاح سرّي');

// نزرع قيمًا مميّزة في كل مفتاح سرّي وندوّر عليها في الناتج
$secrets = [];
foreach (SettingsSchema::all() as $k => $d) {
    if (($d['type'] ?? '') !== SettingsSchema::T_SECRET) { continue; }
    $token = 'سر-' . bin2hex(random_bytes(6));
    App::setSetting($k, $token);
    $secrets[$k] = $token;
}
$json = json_encode(SettingsHealth::export(), JSON_UNESCAPED_UNICODE);
$leaked = [];
foreach ($secrets as $k => $token) {
    if (str_contains((string) $json, $token)) { $leaked[] = $k; }
}
$leaked ? bad('⚠️ التصدير سرّب: ' . implode(' · ', $leaked))
        : ok('ولا مفتاح سرّي في ناتج التصدير (' . count($secrets) . ' مفتاح مزروع ومتفحوص)');

/* ⚠️ والحالة برّه كمان: نبضة عامل بتتنقل بين بيئتين تدّي «آخر نسخة احتياطية»
   كذبًا — والمالك بيفتكر إن عنده نسخة وهو ماعندوش. */
$exp = SettingsHealth::export();
$stateIn = [];
foreach (SettingsSchema::all() as $k => $d) {
    if (($d['tier'] ?? '') === SettingsSchema::TIER_STATE && isset($exp['settings'][$k])) { $stateIn[] = $k; }
}
$stateIn ? bad('مفاتيح حالة في التصدير: ' . implode(' · ', $stateIn))
         : ok('ولا مفتاح حالة في التصدير');

count($exp['settings'] ?? []) > 5
    ? ok('والتصدير فيه إعدادات فعلاً (' . count($exp['settings']) . ')')
    : bad('التصدير طلع فاضي');

// ═══════════════════════════════════════════════════════════ ②
sec('② الاستيراد بيتحقّق زي أي تعديل يدوي');

$before = (string) Settings::get('track_expire_hours');
$r = SettingsHealth::import(['settings' => ['track_expire_hours' => '96']]);
((int) $r['applied'] === 1 && (string) Settings::get('track_expire_hours') === '96')
    ? ok('القيمة الصالحة بتتطبّق') : bad('الاستيراد ما طبّقش قيمة صالحة');

$r = SettingsHealth::import(['settings' => ['track_expire_hours' => '99999']]);
(count($r['errors']) === 1 && (string) Settings::get('track_expire_hours') === '96')
    ? ok('والقيمة خارج النطاق **بترفض** بدل ما تعدّي — الاستيراد مش باب خلفي')
    : bad('⚠️ قيمة خارج النطاق عدّت من الاستيراد');

$r = SettingsHealth::import(['settings' => ['ai_key_1' => 'مفتاح-مزوَّر']]);
((int) $r['skipped'] === 1 && (string) App::setting('ai_key_1', '') !== 'مفتاح-مزوَّر')
    ? ok('والمفتاح السرّي بيتخطّى في الاستيراد كمان') : bad('الاستيراد كتب مفتاحًا سرّيًّا');

$r = SettingsHealth::import(['settings' => ['مفتاح_مخترع' => 'x']]);
(int) $r['skipped'] === 1 ? ok('والمفتاح المجهول بيتخطّى') : bad('مفتاح مجهول اتكتب');

$r = SettingsHealth::import(['غلط' => 1]);
count($r['errors']) === 1 ? ok('والملف بلا إعدادات بيترفض بوضوح') : bad('ملف فاضي عدّى');

Settings::set('track_expire_hours', $before !== '' ? $before : '48');

// ═══════════════════════════════════════════════════════════ ③
sec('③ فحص الصحة بيمسك المشاكل الحقيقية');

$base = count(SettingsHealth::check());

// (أ) قيمة متعارضة: «عاجل» أقل من «متأخّر»
Settings::set('notify_submission_warn_hours', '10');
App::setSetting('notify_submission_urgent_hours', '5');   // كتابة خام تتخطّى التحقّق
App::flushSettingsCache();
$hits = SettingsHealth::check();
$found = false;
foreach ($hits as $h) { if (str_contains($h[1], 'عاجل')) { $found = true; break; } }
$found ? ok('بيمسك تعارض «عاجل» أقل من «متأخّر»') : bad('التعارض ما اتمسكش');
Settings::set('notify_submission_warn_hours', '4');
Settings::set('notify_submission_urgent_hours', '24');

// (ب) قيمة خارج نطاقها اتكتبت خام (زي سكربت قديم)
App::setSetting('track_expire_hours', '99999');
App::flushSettingsCache();
$found = false;
foreach (SettingsHealth::check() as $h) { if (str_contains($h[1], 'صالحة')) { $found = true; break; } }
$found ? ok('وبيمسك قيمة خارج نطاقها اتكتبت خام') : bad('القيمة الخارجة ما اتمسكتش');
Settings::set('track_expire_hours', '48');

// (ج) مفتاح مجهول في القاعدة
App::setSetting('مفتاح_يتيم_للاختبار', '1');
App::flushSettingsCache();
$found = false;
foreach (SettingsHealth::check() as $h) { if (str_contains($h[1], 'يتيم')) { $found = true; break; } }
$found ? ok('وبيمسك مفتاحًا في القاعدة برّه السجل') : bad('المفتاح اليتيم ما اتمسكش');
Database::exec('DELETE FROM settings WHERE setting_key = ?', ['مفتاح_يتيم_للاختبار']);
App::flushSettingsCache();

/* ⚠️ **كل بند لازم يقول الحل** — «الرقم ده غلط» بلا «أعمل إيه» بيخلّي المالك
   يقفل الشاشة، والمشكلة تفضل مكانها. */
$noFix = 0;
foreach (SettingsHealth::check() as $h) {
    if (count($h) < 3 || trim((string) $h[2]) === '') { $noFix++; }
}
$noFix === 0 ? ok('وكل بند بيقول المشكلة **والحل**') : bad("فيه {$noFix} بند بلا حل");

// ═══════════════════════════════════════════════════════════ ④
sec('④ الرجوع بضغطة');

Settings::set('track_avg_speed_kmh', '45');
Settings::set('track_avg_speed_kmh', '70');
$hist = Settings::history('track_avg_speed_kmh', 1);
if ($hist) {
    Settings::revert((int) $hist[0]['id']);
    (string) Settings::get('track_avg_speed_kmh') === '45'
        ? ok('الرجوع بيرجّع القيمة السابقة') : bad('الرجوع ما رجّعش');
} else { bad('السجل فاضي بعد التعديل'); }

// ═══════════════════════════════════════════════════════════ ⑤
sec('⑤ المسارات والصلاحيات');

$routes = (string) file_get_contents($root . '/public/index.php');
/* ⚠️ **ترتيب حسّاس**: `/settings/export` لازم تكون **قبل** `/settings/{section}`
   وإلا بتتقري كأنها اسم قسم وترندر «عام» بدل ما تنزّل الملف — بلا خطأ ظاهر. */
$posExport = strpos($routes, "'/settings/export'");
$posGreedy = strpos($routes, "\$r->get('/settings/{section}'");
($posExport !== false && $posGreedy !== false && $posExport < $posGreedy)
    ? ok('`/settings/export` قبل المسار الجشع للأقسام')
    : bad('التصدير بعد الجشع — هيتقري كأنه اسم قسم');

foreach (['exportSettings', 'importSettings', 'revertSetting'] as $a) {
    Access::allows('owner', "SettingsController@$a", $a === 'exportSettings' ? 'GET' : 'POST')
        ? ok("المالك يقدر: $a") : bad("المالك مرفوض: $a");
    !Access::allows('manager', "SettingsController@$a", $a === 'exportSettings' ? 'GET' : 'POST')
        ? ok("والمدير لأ: $a") : bad("المدير بيقدر: $a");
}

is_file($root . '/app/Views/settings/sections/health.php')
    ? ok('جزء الشاشة موجود') : bad('جزء الشاشة مفقود');

// ═══════════════════════════════════════════════════════════ ⑥
sec('⑥ صفر أثر مالي');

$safeBefore = round((float) Custody::balance(), 2);
SettingsHealth::check();
SettingsHealth::export();
abs($safeBefore - round((float) Custody::balance(), 2)) < 0.005
    ? ok('الفحص والتصدير ما بيغيّروش رصيد الخزنة') : bad('الرصيد اتغيّر');

} finally {
    $_SESSION = [];
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    App::flushSettingsCache();
}

echo "\n" . str_repeat('=', 66) . "\n";
echo "النتيجة: $pass ناجح · $fail فاشل\n";
if ($fail > 0) { echo "\n⚠️ تسريب في التصدير أو الاستيراد باب خلفي — راجع الفوق.\n"; exit(1); }
echo "✓ الفحص والتصدير والاستيراد والرجوع سليمين.\n";
exit(0);
