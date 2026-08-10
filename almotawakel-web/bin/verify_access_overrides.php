<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص استثناءات الصلاحيات — المرحلة ٥ من docs/19-settings-control-center-plan.md
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  ليه الفاحص ده موجود — اقرأ قبل ما تعدّل
 * ═══════════════════════════════════════════════════════════════════════
 * سياسة الصلاحيات كانت ٤ قوايم ثابتة في `Access.php` بلا أي شاشة تفرجي المالك
 * مين يقدر يعمل إيه. v78 ضاف **طبقة استثناءات فوق السياسة** — والطبقة دي
 * أخطر حاجة اتعملت في الخطة كلها: غلطة فيها إما **بتفتح** حاجة مقفولة أو
 * **بتقفل المالك برّه النظام**.
 *
 * ⚠️ **الفحص بيغطّي الاتجاهين:**
 *   ① الجدول الفاضي = **نفس السياسة الأصلية بالحرف** (بصمة كاملة قبل/بعد).
 *   ② الاستثناء بيشتغل فعلاً (مسموح وممنوع).
 *   ③ **مستحيل المالك يتقفل برّه** — دوره وطريق الرجوع محميّان في ٣ نقاط.
 *   ④ استثناء المستخدم بيغلب استثناء الدور.
 *   ⑤ المصفوفة المعروضة = `Access::allows` نفسها (مفيش مصدرين).
 *
 * التشغيل:  php bin/verify_access_overrides.php
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Access;
use App\Core\Database;
use App\Models\AccessOverride;
use App\Models\Custody;

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function sec(string $m): void { echo "\n" . $m . "\n" . str_repeat('─', 66) . "\n"; }

$root = dirname(__DIR__);
echo "فحص استثناءات الصلاحيات (docs/19 · المرحلة ٥)\n" . str_repeat('=', 66) . "\n";

if (!AccessOverride::ready()) {
    echo "\n⚠️ جدول access_overrides مش موجود — شغّل bin/migrate_v78.php الأول.\n";
    exit(1);
}

/** بصمة كل الإجابات — الأداة اللي بتثبت «صفر تغيير». */
$fingerprint = static function (): string {
    $out = [];
    foreach (AccessOverride::controllers() as $ctrl => $m) {
        foreach (array_keys(Access::ROLES) as $role) {
            $out[] = (Access::allows($role, $m['handler'], 'GET') ? '1' : '0')
                   . (Access::allows($role, $ctrl . '@store', 'POST') ? '1' : '0');
        }
    }
    return implode('', $out);
};

$ownerId = (int) Database::scalar("SELECT MIN(id) FROM users WHERE role='owner'");
$otherId = (int) Database::scalar("SELECT MIN(id) FROM users WHERE role <> 'owner'");
$asOwner = static function () use ($ownerId) {
    $_SESSION = ['uid' => $ownerId, 'role' => 'owner', 'uname' => 'فحص',
                 'last_activity' => time(), 'uid_checked' => time()];
};

/** وحدة اختبار غير محميّة. */
$ctrl = null; $handler = null;
foreach (AccessOverride::controllers() as $c => $m) {
    if (in_array($c, AccessOverride::PROTECTED_CTRL, true)) { continue; }
    $ctrl = $c; $handler = $m['handler']; break;
}

$pdo = Database::conn();
$pdo->beginTransaction();
try {
$asOwner();

// ═══════════════════════════════════════════════════════════ ①
sec('① الجدول الفاضي = السياسة الأصلية بالحرف');

Database::exec('DELETE FROM access_overrides');
AccessOverride::refreshReady();
$baseline = $fingerprint();
strlen($baseline) > 50
    ? ok('بصمة السياسة اتقاست (' . strlen($baseline) . ' إجابة)')
    : bad('البصمة رجعت قصيرة — المصفوفة فاضية؟');

/* ⚠️ الفحص ده هو اللي بيخلّي الطبقة آمنة: لو الاستثناءات اتشالت، النظام لازم
   يرجع **بالضبط** لسياسة الكود. أي فرق معناه إن الطبقة بتسرّب حالة. */
Database::exec('DELETE FROM access_overrides');
AccessOverride::refreshReady();
$fingerprint() === $baseline
    ? ok('وتفضل هي هي بعد تصفير الجدول')
    : bad('السياسة اتغيّرت بعد تصفير الجدول — الطبقة بتسرّب حالة');

// ═══════════════════════════════════════════════════════════ ②
sec('② الاستثناء بيشتغل في الاتجاهين');

$roleBefore = Access::allows('read_only', $ctrl . '@store', 'POST');
AccessOverride::set($ctrl, AccessOverride::ALLOW, 'read_only');
Access::allows('read_only', $ctrl . '@store', 'POST')
    ? ok("«مسموح» بيفتح {$ctrl} لقراءة-فقط") : bad('«مسموح» ما فتحش');

AccessOverride::set($ctrl, AccessOverride::DENY, 'manager');
!Access::allows('manager', $handler, 'GET')
    ? ok("«ممنوع» بيقفل {$ctrl} على المدير") : bad('«ممنوع» ما قفلش');

AccessOverride::set($ctrl, '', 'manager');
AccessOverride::set($ctrl, '', 'read_only');
(Access::allows('read_only', $ctrl . '@store', 'POST') === $roleBefore && $fingerprint() === $baseline)
    ? ok('وشيل الاستثناء بيرجّع السياسة الأصلية بالحرف')
    : bad('الرجوع ما رجّعش السياسة');

// ═══════════════════════════════════════════════════════════ ③
sec('③ ⚠️ مستحيل المالك يتقفل برّه');

// (أ) دور المالك ما بيتأثرش حتى لو اتزرع صف في القاعدة يدويًّا
Database::exec(
    'INSERT INTO access_overrides (role, controller, effect) VALUES (?,?,?)',
    ['owner', $ctrl, AccessOverride::DENY]);
AccessOverride::refreshReady();
Access::allows('owner', $handler, 'GET')
    ? ok('صف مزروع على المالك في القاعدة **ما بيأثرش** — الحارس في `apply`')
    : bad('⚠️ المالك اتقفل بصف في القاعدة');
Database::exec("DELETE FROM access_overrides WHERE role = 'owner'");
AccessOverride::refreshReady();

// (ب) الكتابة على المالك بترفض من الموديل
$denied = static function (callable $fn): bool {
    try { $fn(); return false; } catch (\Throwable $e) { return true; }
};
$denied(static fn() => AccessOverride::set($ctrl, AccessOverride::DENY, 'owner'))
    ? ok('والكتابة على دور المالك بترفض من الموديل') : bad('اتكتب استثناء على المالك');

// (ج) طريق الرجوع محميّ
foreach (AccessOverride::PROTECTED_CTRL as $p) {
    $denied(static fn() => AccessOverride::set($p, AccessOverride::DENY, 'manager'))
        ? ok("«{$p}» محميّ — ما ينفعش يتقيّد") : bad("«{$p}» اتقيّد — طريق الرجوع اتشال");
}
// وحتى لو اتزرع صف يدويًّا، `apply` بتتجاهله
Database::exec(
    'INSERT INTO access_overrides (role, controller, effect) VALUES (?,?,?)',
    ['manager', 'SettingsController', AccessOverride::DENY]);
AccessOverride::refreshReady();
Access::allows('manager', 'SettingsController@index', 'GET')
    ? ok('وصف مزروع على الإعدادات ما بيأثرش كمان')
    : bad('⚠️ الإعدادات اتقفلت على المدير بصف مزروع');
Database::exec("DELETE FROM access_overrides WHERE controller = 'SettingsController'");
AccessOverride::refreshReady();

$denied(static fn() => AccessOverride::set('كنترولر-مخترع', AccessOverride::DENY, 'manager'))
    ? ok('الكنترولر المجهول بيرفض — مفيش صف يتيم') : bad('اتكتب استثناء لكنترولر مجهول');

// ═══════════════════════════════════════════════════════════ ④
sec('④ استثناء المستخدم بيغلب استثناء الدور');

if ($otherId > 0) {
    $role = (string) Database::scalar('SELECT role FROM users WHERE id = ?', [$otherId]);
    AccessOverride::set($ctrl, AccessOverride::DENY, $role);
    AccessOverride::set($ctrl, AccessOverride::ALLOW, null, $otherId);
    AccessOverride::apply(false, $role, $ctrl, $otherId) === true
        ? ok('«فلان بالذات» بيغلب «كل الدور»') : bad('استثناء المستخدم ما غلبش');

    // ومستخدم تاني من نفس الدور بيفضل ممنوع
    AccessOverride::apply(true, $role, $ctrl, 999999) === false
        ? ok('وباقي الدور بيفضلوا على استثناء الدور') : bad('استثناء المستخدم اتسرّب لغيره');

    AccessOverride::set($ctrl, '', $role);
    AccessOverride::set($ctrl, '', null, $otherId);
    ok('واتشالوا الاتنين');
} else {
    bad('مفيش مستخدم غير المالك للاختبار');
}

// ═══════════════════════════════════════════════════════════ ⑤
sec('⑤ المصفوفة المعروضة = `Access::allows` نفسها');

AccessOverride::set($ctrl, AccessOverride::DENY, 'accountant');
$m = AccessOverride::matrix();
$cell = $m[$ctrl]['cells']['accountant'] ?? null;
($cell !== null && $cell['read'] === Access::allows('accountant', $handler, 'GET')
 && $cell['write'] === Access::allows('accountant', $ctrl . '@store', 'POST'))
    ? ok('خانة المصفوفة بتطابق إجابة النظام بالظبط')
    : bad('المصفوفة بتوري حاجة والنظام بيعمل تانية');
($cell['ov'] ?? '') === AccessOverride::DENY
    ? ok('والاستثناء ظاهر في الخانة') : bad('الاستثناء مش ظاهر');
AccessOverride::set($ctrl, '', 'accountant');

$mSrc = (string) file_get_contents($root . '/app/Models/AccessOverride.php');
substr_count($mSrc, 'Access::allows(') >= 2
    ? ok('المصفوفة بتنادي `Access::allows` مش بتعيد المنطق')
    : bad('المصفوفة بتحسب بإيدها — مصدران للحقيقة');

// ═══════════════════════════════════════════════════════════ ⑥
sec('⑥ الشاشة والمسار وصفر أثر مالي');

$routes = (string) file_get_contents($root . '/public/index.php');
str_contains($routes, "\$r->post('/settings/access'")
    ? ok('مسار حفظ الصلاحيات مسجَّل') : bad('المسار مش مسجَّل');
Access::allows('owner', 'SettingsController@saveAccess', 'POST')
    ? ok('المالك يقدر يعدّل الصلاحيات') : bad('المالك مرفوض');
!Access::allows('manager', 'SettingsController@saveAccess', 'POST')
    ? ok('المدير ما يقدرش — قرار مالك بالتعريف') : bad('المدير بيعدّل الصلاحيات');
is_file($root . '/app/Views/settings/sections/access.php')
    ? ok('جزء الشاشة موجود') : bad('جزء الشاشة مفقود');

$safeBefore = round((float) Custody::balance(), 2);
AccessOverride::set($ctrl, AccessOverride::DENY, 'operator');
abs($safeBefore - round((float) Custody::balance(), 2)) < 0.005
    ? ok('تعديل صلاحية ما بيغيّرش رصيد الخزنة') : bad('الرصيد اتغيّر');

} finally {
    $_SESSION = [];
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    AccessOverride::refreshReady();
}

echo "\n" . str_repeat('=', 66) . "\n";
echo "النتيجة: $pass ناجح · $fail فاشل\n";
if ($fail > 0) { echo "\n⚠️ الطبقة بتغيّر سياسة قائمة أو ممكن تقفل المالك برّه — راجع الفوق.\n"; exit(1); }
echo "✓ الاستثناءات فوق السياسة، والمالك مستحيل يتقفل برّه.\n";
exit(0);
