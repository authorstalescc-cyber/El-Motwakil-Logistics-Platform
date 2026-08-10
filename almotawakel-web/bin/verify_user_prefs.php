<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص تفضيلات العرض — المرحلة ٢ من docs/19-settings-control-center-plan.md
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  ليه الفاحص ده موجود — اقرأ قبل ما تعدّل
 * ═══════════════════════════════════════════════════════════════════════
 * كل طبقة العرض كانت في `localStorage`: الأعمدة المخفية في كل جدول · الكثافة ·
 * تثبيت أول عمود · **عرض كل عمود بالبكسل** · مناظر الفلاتر. يعني في المتصفح
 * مش في الحساب — الموظف يظبط شاشته على جهاز ويلاقيها راجعة على التاني، ومسح
 * الكاش بيضيّع كل حاجة، **والمالك ما يقدرش يفرض شكلًا للشركة**.
 *
 * ⚠️ **أخطر بند هنا هو القفل**: لو الواجهة بتخفي الزرار والخادم مش رافض، يبقى
 *    «التثبيت» وعد كاذب — أي طلب مزوَّر بيعدّي. الفحص بيجرّب الكتابة فعليًّا
 *    بحساب مستخدم عادي على مفتاح مقفول ويتأكد إنها **بترفض**.
 *
 * الأقسام:
 *   ① المستويات التلاتة بتتحل بالترتيب الصح
 *   ② القفل بيتفرض على الخادم (مش إخفاء زرار)
 *   ③ الترحيل من المتصفح بيضيف الناقص وما بيدهسش
 *   ④ حرّاس المفاتيح والقيم
 *   ⑤ الواجهة موصولة بالمخزن مش بـ localStorage
 *   ⑥ الصلاحيات: أي مستخدم يظبط شاشته · الشركة للمالك بس
 *   ⑦ العزل بالبناء — صفر أثر مالي
 *
 * الكتابة كلها جوّه transaction + rollback.
 *
 * التشغيل:  php bin/verify_user_prefs.php
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Access;
use App\Core\Database;
use App\Models\Custody;
use App\Models\UserPref;

$pass = 0; $fail = 0; $skip = 0;
function ok(string $m): void   { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  ✗ $m\n"; }
function skp(string $m): void  { global $skip; $skip++; echo "  ~ $m (تخطّي)\n"; }
function sec(string $m): void  { echo "\n" . $m . "\n" . str_repeat('─', 66) . "\n"; }

/** ⚠️ إجبارية قبل أي فحص نصّي — التعليق بيذكر النص الممنوع (localStorage). */
function code_only(string $src): string
{
    return preg_replace('~/\*.*?\*/|(?<!:)//[^\n]*~s', '', $src) ?? $src;
}

$root = dirname(__DIR__);
const K = 'table./permits.abc123.0.view';

echo "فحص تفضيلات العرض (docs/19 · المرحلة ٢)\n" . str_repeat('=', 66) . "\n";

if (!UserPref::ready()) {
    echo "\n⚠️ جدول user_prefs مش موجود — شغّل bin/migrate_v75.php الأول.\n";
    exit(1);
}

$uid = (int) Database::scalar("SELECT MIN(id) FROM users WHERE role <> 'owner'")
     ?: (int) Database::scalar('SELECT MIN(id) FROM users');
$ownerId = (int) Database::scalar("SELECT MIN(id) FROM users WHERE role='owner'") ?: $uid;

$asUser = static function (int $id, string $role) {
    $_SESSION = ['uid' => $id, 'role' => $role, 'uname' => 'فحص',
                 'last_activity' => time(), 'uid_checked' => time()];
};

$pdo = Database::conn();
$pdo->beginTransaction();
try {

// ═══════════════════════════════════════════════════════════ ①
sec('① المستويات التلاتة بتتحل بالترتيب الصح');

$asUser($uid, 'accountant');
UserPref::refreshReady();

UserPref::resolve(K, $uid) === null
    ? ok('مفيش تفضيل ⇒ null (المستهلك بيقع على افتراضي الكود)')
    : bad('رجّع قيمة وهو المفروض فاضي');

$asUser($ownerId, 'owner');
UserPref::setCompany(K, '{"density":"compact"}', false);
$asUser($uid, 'accountant');
UserPref::resolve(K, $uid) === '{"density":"compact"}'
    ? ok('افتراضي الشركة بيوصل للمستخدم') : bad('افتراضي الشركة ما وصلش');

UserPref::set(K, '{"density":"normal"}');
UserPref::resolve(K, $uid) === '{"density":"normal"}'
    ? ok('تفضيل المستخدم بيغلب افتراضي الشركة') : bad('تفضيل المستخدم ما غلبش');

UserPref::forget(K);
UserPref::resolve(K, $uid) === '{"density":"compact"}'
    ? ok('لما المستخدم يصفّر بيرجع لافتراضي الشركة') : bad('التصفير ما رجّعش للشركة');

// ═══════════════════════════════════════════════════════════ ②
sec('② ⚠️ القفل بيتفرض على الخادم — مش إخفاء زرار');

$asUser($ownerId, 'owner');
UserPref::setCompany(K, '{"density":"compact"}', true);
$asUser($uid, 'accountant');

$refused = false;
try { UserPref::set(K, '{"density":"normal"}'); }
catch (\Throwable $e) { $refused = true; }
$refused ? ok('كتابة المستخدم على مفتاح مثبَّت **بترفض** من الموديل')
         : bad('⚠️ المستخدم كتب على مفتاح مثبَّت — «التثبيت» وعد كاذب');

UserPref::resolve(K, $uid) === '{"density":"compact"}'
    ? ok('والقيمة الفعّالة فضلت بتاعة الشركة') : bad('القيمة اتغيّرت رغم القفل');

/* ⚠️ ولازم يتجاهل صفًّا **قديمًا** للمستخدم كان موجود قبل القفل — الحذف مش
   شرط، التجاهل هو المطلوب (عشان لو المالك فتح القفل يرجع تفضيله زي ما كان). */
$asUser($ownerId, 'owner');
UserPref::setCompany(K, '{"density":"compact"}', false);
$asUser($uid, 'accountant');
UserPref::set(K, '{"density":"normal"}');
$asUser($ownerId, 'owner');
UserPref::setCompany(K, '{"density":"compact"}', true);
UserPref::resolve(K, $uid) === '{"density":"compact"}'
    ? ok('القفل بيتجاهل تفضيل المستخدم القديم بدل ما يمسحه')
    : bad('تفضيل المستخدم القديم غلب القفل');

$asUser($ownerId, 'owner');
UserPref::setCompany(K, '{"density":"compact"}', false);
UserPref::resolve(K, $uid) === '{"density":"normal"}'
    ? ok('وفتح القفل بيرجّع تفضيل المستخدم زي ما كان')
    : bad('فتح القفل ما رجّعش تفضيل المستخدم');

// المستخدم العادي ما يقدرش يكتب افتراضي شركة
$asUser($uid, 'accountant');
$refused = false;
try { UserPref::setCompany(K, '{"x":1}', true); }
catch (\Throwable $e) { $refused = true; }
$refused ? ok('غير المالك ما يقدرش يكتب افتراضي شركة') : bad('غير المالك كتب افتراضي شركة');

// ═══════════════════════════════════════════════════════════ ③
sec('③ الترحيل من المتصفح بيضيف الناقص وما بيدهسش');

$asUser($uid, 'accountant');
Database::exec('DELETE FROM user_prefs');
UserPref::refreshReady();

UserPref::set(K, '{"mine":1}');
$moved = UserPref::adopt([K => '{"old":1}', 'table./x.y.0.cols' => '[1,2]']);
$moved === 1 ? ok('الترحيل ضاف الناقص وبس (١ من ٢)') : bad("الترحيل نقل {$moved} بدل 1");
UserPref::resolve(K, $uid) === '{"mine":1}'
    ? ok('وما دهسش تفضيل المستخدم الأحدث') : bad('الترحيل دهس تفضيلًا موجودًا');

// مفتاح خارج مساحة الأسماء بيتجاهَل في الترحيل
$moved = UserPref::adopt(['hack.evil' => '{"a":1}']);
$moved === 0 ? ok('المفتاح برّه مساحة الأسماء بيتجاهَل في الترحيل')
             : bad('مفتاح مجهول اتنقل');

// ═══════════════════════════════════════════════════════════ ④
sec('④ حرّاس المفاتيح والقيم');

$denied = static function (callable $fn): bool {
    try { $fn(); return false; } catch (\Throwable $e) { return true; }
};

$denied(static fn() => UserPref::set('hack.evil', '{"a":1}'))
    ? ok('المفتاح برّه مساحة الأسماء بيترفض') : bad('مفتاح مجهول اتكتب');

$denied(static fn() => UserPref::set('table.x', 'مش-JSON'))
    ? ok('القيمة اللي مش JSON بترفض') : bad('قيمة تالفة اتكتبت');

$denied(static fn() => UserPref::set('table.x', '"' . str_repeat('ا', 25000) . '"'))
    ? ok('القيمة الأطول من الحد بترفض') : bad('قيمة عملاقة اتكتبت');

UserPref::validKey('table.' . str_repeat('a', 300)) === false
    ? ok('المفتاح الأطول من الحد بيترفض') : bad('مفتاح عملاق عدّى');

UserPref::validKey('table.a b') === false
    ? ok('المحارف برّه المسموح بترفض') : bad('محارف غريبة عدّت');

// ═══════════════════════════════════════════════════════════ ⑤
sec('⑤ الواجهة موصولة بالمخزن مش بـ localStorage');

$js = code_only((string) file_get_contents($root . '/public/assets/js/app.js'));

str_contains($js, 'window.AppPrefs')
    ? ok('مخزن التفضيلات معرَّف في app.js') : bad('المخزن مش موجود');

/* ⚠️ **الفحص على الاستعمال مش الوجود**: المخزن ممكن يتعرَّف والجداول لسه
   بتقرا من `localStorage` مباشرةً — وده اللي المرحلة دي اتعملت تمنعه. */
$directTable = preg_match_all(
    '~localStorage\.(getItem|setItem)\(\s*(storeKey|prefKey)\b~', $js);
$directTable === 0
    ? ok('ولا جدول بيقرا/يكتب مفاتيحه من localStorage مباشرةً')
    : bad("فيه {$directTable} نقطة لسه بتستعمل localStorage للجداول");

foreach (['AppPrefs.get', 'AppPrefs.set', 'AppPrefs.locked', 'adoptLegacy'] as $fn) {
    str_contains($js, $fn) ? ok("الواجهة بتستعمل `{$fn}`") : bad("`{$fn}` مش مستعملة");
}

$layout = (string) file_get_contents($root . '/app/Views/layout.php');
(str_contains($layout, '__PREFS_API') && str_contains($layout, 'UserPref::allFor'))
    ? ok('التفضيلات بتتحقن في الصفحة باستعلام واحد')
    : bad('التفضيلات مش متحقنة في layout');

// ═══════════════════════════════════════════════════════════ ⑥
sec('⑥ الصلاحيات');

/* ⚠️ **`read_only` لازم يقدر يظبط شاشته**: إخفاء عمود مالوش أي أثر على بيانات،
   ومنعه كان هيخلّي الميزة عديمة الفايدة لنص المستخدمين. */
foreach (['read_only', 'operator', 'accountant', 'manager', 'owner'] as $role) {
    Access::allows($role, 'PrefController@save', 'POST')
        ? ok("«{$role}» يقدر يحفظ تفضيلاته") : bad("«{$role}» ممنوع من تفضيلاته");
}
!Access::allows('manager', 'SettingsController@prefLock', 'POST')
    ? ok('المدير ما يقدرش يثبّت شكل الشركة — قرار مالك')
    : bad('المدير بيقدر يثبّت شكل الشركة');
Access::allows('owner', 'SettingsController@prefLock', 'POST')
    ? ok('المالك يقدر') : bad('المالك ممنوع!');

// ═══════════════════════════════════════════════════════════ ⑦
sec('⑦ العزل بالبناء — صفر أثر مالي');

$safeBefore = round((float) Custody::balance(), 2);
$asUser($uid, 'accountant');
UserPref::set('table./closing.z.0.view', '{"density":"compact"}');
$safeAfter = round((float) Custody::balance(), 2);
abs($safeBefore - $safeAfter) < 0.005
    ? ok('حفظ تفضيل ما بيغيّرش رصيد الخزنة')
    : bad("رصيد الخزنة اتغيّر: {$safeBefore} → {$safeAfter}");

/** ⚠️ الحارس ده لازم يفضل: تفضيل عرض عمره ما يدخل حسابًا (قاعدة ①). */
$financial = ['app/Models/Closing.php', 'app/Models/Custody.php', 'app/Models/CompanySafe.php',
              'app/Models/Expense.php', 'app/Models/DailyReview.php', 'app/Models/RepCustody.php',
              'app/Models/Debt.php', 'app/Models/OpsReport.php'];
$breach = [];
foreach ($financial as $rel) {
    $p = $root . '/' . $rel;
    if (is_file($p)) {
        $c = code_only((string) file_get_contents($p));
        if (str_contains($c, 'user_prefs') || str_contains($c, 'UserPref')) { $breach[] = $rel; }
    }
}
$breach ? bad('ملف مالي بيعرف تفضيلات العرض: ' . implode(' · ', $breach))
        : ok('ولا ملف مالي بيعرف الجدول (' . count($financial) . ' ملف مفحوص)');

} finally {
    $_SESSION = [];
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    UserPref::refreshReady();
}

echo "\n" . str_repeat('=', 66) . "\n";
echo "النتيجة: $pass ناجح · $fail فاشل · $skip متخطّي\n";
if ($fail > 0) {
    echo "\n⚠️ التفضيلات مش بتتبع الحساب أو القفل مكسور — راجع الفوق.\n";
    exit(1);
}
echo "✓ تفضيلات العرض بتتبع الحساب، والتثبيت محروس على الخادم.\n";
exit(0);
