<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص الوحدات والقائمة — المرحلة ٤ من docs/19-settings-control-center-plan.md
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  ليه الفاحص ده موجود — اقرأ قبل ما تعدّل
 * ═══════════════════════════════════════════════════════════════════════
 * القائمة كانت **مصفوفة PHP مكتوبة بالإيد في `layout.php`**: ترتيب الـ٢٩ وحدة
 * وأسماؤها وأيقوناتها ما تتغيّرش إلا بتعديل كود، ومفيش طريقة تطفي وحدة مش
 * مستعملة. دلوقتي الافتراضي في الكود والفرق في القاعدة.
 *
 * ⚠️ **أخطر بندين هنا:**
 *   ① **الإطفاء لازم يترفض على الخادم** — لو اتشال اللينك بس، أي حد يكتب
 *      المسار بإيده ويدخل، فـ«مطفية» تبقى وعد كاذب.
 *   ② **الوحدة المقفولة ما تتطفيش** — إطفاء `/settings` بيقفل المالك برّه
 *      النظام وطريق الرجوع الوحيد يبقى قاعدة البيانات.
 * الاتنين بيتقاسوا **بسلوك حقيقي** جوّه transaction.
 *
 * الأقسام:
 *   ① الافتراضي = القائمة القديمة بالحرف (صفر تغيير على قاعدة فاضية)
 *   ② الإخفاء بيشيل من القائمة والمسار بيفضل شغّال
 *   ③ الإطفاء بيترفض في `Access` — للمالك كمان
 *   ④ الوحدة المقفولة محميّة
 *   ⑤ الترتيب والاسم البديل بيشتغلوا
 *   ⑥ الشاشة والمسار والصلاحية
 *   ⑦ صفر أثر مالي وصفر مساس ببيانات الوحدة
 *
 * التشغيل:  php bin/verify_modules.php
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Access;
use App\Core\Database;
use App\Models\Custody;
use App\Models\NavModule;

$pass = 0; $fail = 0; $skip = 0;
function ok(string $m): void   { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  ✗ $m\n"; }
function sec(string $m): void  { echo "\n" . $m . "\n" . str_repeat('─', 66) . "\n"; }

/** ⚠️ إجبارية قبل أي فحص نصّي — التعليق بيذكر النص الممنوع. */
function code_only(string $src): string
{
    return preg_replace('~/\*.*?\*/|(?<!:)//[^\n]*~s', '', $src) ?? $src;
}

$root = dirname(__DIR__);
echo "فحص الوحدات والقائمة (docs/19 · المرحلة ٤)\n" . str_repeat('=', 66) . "\n";

if (!NavModule::ready()) {
    echo "\n⚠️ جدول module_settings مش موجود — شغّل bin/migrate_v77.php الأول.\n";
    exit(1);
}

$uid = (int) Database::scalar("SELECT MIN(id) FROM users WHERE role='owner'")
     ?: (int) Database::scalar('SELECT MIN(id) FROM users');
$_SESSION = ['uid' => $uid, 'role' => 'owner', 'uname' => 'فحص',
             'last_activity' => time(), 'uid_checked' => time()];

/** وحدة اختبار غير مقفولة — بنختارها من الافتراضي مش بالاسم. */
$victim = null; $victimHandler = null;
foreach (NavModule::DEFAULTS as $item) {
    if ($item[2] === null || $item[0] === '' || in_array($item[0], NavModule::LOCKED, true)) { continue; }
    $victim = (string) $item[0]; $victimHandler = (string) $item[3]; break;
}

$pdo = Database::conn();
$pdo->beginTransaction();
try {

// ═══════════════════════════════════════════════════════════ ①
sec('① الافتراضي = القائمة القديمة بالحرف');

Database::exec('DELETE FROM module_settings');
NavModule::refreshReady();

$menu  = NavModule::menu();
$links = array_values(array_filter($menu, static fn($i) => $i[2] !== null));
count($links) > 20
    ? ok('القائمة بترجع من الموديل (' . count($links) . ' وحدة ظاهرة للمالك)')
    : bad('القائمة رجعت ناقصة: ' . count($links));

/* ⚠️ الترتيب لازم يفضل **زي ترتيب الكود** على قاعدة فاضية — أي فرق معناه إن
   الدمج بيعيد الترتيب من نفسه، وده بيحرّك القائمة على المستخدم بلا سبب. */
$codeOrder = [];
foreach (NavModule::DEFAULTS as $item) { if ($item[2] !== null) { $codeOrder[] = (string) $item[0]; } }
$menuOrder = array_map(static fn($i) => (string) $i[0], $links);
$filtered  = array_values(array_intersect($codeOrder, $menuOrder));
$filtered === $menuOrder
    ? ok('وترتيبها = ترتيب الكود بالحرف (قاعدة فاضية = صفر تغيير)')
    : bad('الترتيب اتغيّر على قاعدة فاضية');

// ولا سطر في layout لسه بيبني القائمة بإيده
$layout = code_only((string) file_get_contents($root . '/app/Views/layout.php'));
(!str_contains($layout, '$navRaw') && str_contains($layout, 'NavModule::menu'))
    ? ok('`layout.php` بيقرا من الموديل مش من مصفوفة مكتوبة بالإيد')
    : bad('لسه فيه مصفوفة قائمة في layout');

// ═══════════════════════════════════════════════════════════ ②
sec('② الإخفاء بيشيل من القائمة والمسار بيفضل شغّال');

NavModule::save($victim, ['hidden' => true, 'order' => null, 'label' => '']);
$menu = NavModule::menu();
$has  = in_array($victim, array_map(static fn($i) => (string) $i[0], $menu), true);
!$has ? ok("«{$victim}» اتشالت من القائمة") : bad('المخفية لسه في القائمة');

Access::allows('owner', $victimHandler, 'GET')
    ? ok('ومسارها لسه شغّال — «مخفية» مش «مطفية»')
    : bad('المخفية اترفضت من Access — الفرق بين الحالتين ضاع');

// ═══════════════════════════════════════════════════════════ ③
sec('③ ⚠️ الإطفاء بيترفض في Access — للمالك كمان');

NavModule::save($victim, ['disabled' => true, 'order' => null, 'label' => '']);
NavModule::isDisabled($victim) ? ok('الموديل بيقول إنها مطفية') : bad('الموديل مش شايفها مطفية');

!Access::allows('owner', $victimHandler, 'GET')
    ? ok('**المالك نفسه** بيترفض — الإطفاء قراره فتخطّيه بيلغي معناه')
    : bad('⚠️ الوحدة المطفية لسه بتفتح — «مطفية» وعد كاذب');

foreach (['manager', 'accountant', 'read_only'] as $role) {
    !Access::allows($role, $victimHandler, 'GET')
        ? ok("«{$role}» بيترفض كمان") : bad("«{$role}» بيدخل وحدة مطفية");
}

// وبعد الرجوع بتشتغل تاني — الإطفاء قابل للتراجع
NavModule::save($victim, ['disabled' => false, 'hidden' => false, 'order' => null, 'label' => '']);
Access::allows('owner', $victimHandler, 'GET')
    ? ok('والرجوع بضغطة بيرجّعها شغّالة') : bad('الرجوع ما رجّعش الوحدة');

// ═══════════════════════════════════════════════════════════ ④
sec('④ الوحدة المقفولة محميّة');

$denied = static function (callable $fn): bool {
    try { $fn(); return false; } catch (\Throwable $e) { return true; }
};
foreach (NavModule::LOCKED as $locked) {
    $denied(static fn() => NavModule::save($locked, ['disabled' => true]))
        ? ok("«" . ($locked === '' ? 'لوحة التحكم' : $locked) . "» ما ينفعش تتطفي")
        : bad("وحدة مقفولة «{$locked}» اتطفت — المالك ممكن يتقفل برّه");
    NavModule::isDisabled($locked) === false
        ? ok('وبتفضل شغّالة مهما كان في القاعدة') : bad('المقفولة اتقريت مطفية');
}

$denied(static fn() => NavModule::save('وحدة-مخترعة', ['hidden' => true]))
    ? ok('الوحدة المجهولة بترفض — مفيش صف يتيم') : bad('اتكتب صف لوحدة مجهولة');

// ═══════════════════════════════════════════════════════════ ⑤
sec('⑤ الترتيب والاسم البديل');

NavModule::save($victim, ['order' => -5, 'label' => 'اسم-اختبار', 'hidden' => false, 'disabled' => false]);
$menu = NavModule::menu();
$first = null;
foreach ($menu as $i) { if ($i[2] !== null) { $first = $i; break; } }
((string) ($first[0] ?? '')) === $victim
    ? ok('الترتيب السالب بيوديها أول القائمة') : bad('الترتيب المخصَّص ما اشتغلش');

$label = null;
foreach ($menu as $i) { if ((string) $i[0] === $victim) { $label = (string) $i[1]; break; } }
$label === 'اسم-اختبار' ? ok('والاسم البديل بيتعرض') : bad("الاسم البديل ما اتعرضش (لقيت: {$label})");

// الاسم الفاضي بيرجّع الافتراضي مش بيمسحه
NavModule::save($victim, ['order' => null, 'label' => '', 'hidden' => false, 'disabled' => false]);
$label = null;
foreach (NavModule::menu() as $i) { if ((string) $i[0] === $victim) { $label = (string) $i[1]; break; } }
$default = null;
foreach (NavModule::DEFAULTS as $i) { if ((string) $i[0] === $victim) { $default = (string) $i[1]; break; } }
$label === $default ? ok('والاسم الفاضي بيرجّع الافتراضي') : bad('الاسم الفاضي ما رجّعش الافتراضي');

// ═══════════════════════════════════════════════════════════ ⑥
sec('⑥ الشاشة والمسار والصلاحية');

$routes = (string) file_get_contents($root . '/public/index.php');
str_contains($routes, "\$r->post('/settings/modules'")
    ? ok('مسار حفظ القائمة مسجَّل') : bad('المسار مش مسجَّل');

$posSave = strpos($routes, "'/settings/modules'");
$posGreedy = strpos($routes, "\$r->get('/settings/{section}'");
($posSave !== false && $posGreedy !== false)
    ? ok('والمسار الجشع `/settings/{section}` موجود (GET مختلف عن POST)')
    : bad('مسار القسم الجشع مفقود');

Access::allows('owner', 'SettingsController@saveModules', 'POST')
    ? ok('المالك يقدر يحفظ القائمة') : bad('المالك مرفوض');
!Access::allows('manager', 'SettingsController@saveModules', 'POST')
    ? ok('المدير ما يقدرش — قرار مالك') : bad('المدير بيقدر يطفي وحدات');

is_file($root . '/app/Views/settings/sections/modules.php')
    ? ok('جزء الشاشة موجود') : bad('جزء الشاشة مفقود');

// ═══════════════════════════════════════════════════════════ ⑦
sec('⑦ صفر أثر مالي وصفر مساس ببيانات الوحدة');

$safeBefore = round((float) Custody::balance(), 2);
$billsBefore = (int) Database::scalar('SELECT COUNT(*) FROM bawalis');
NavModule::save($victim, ['disabled' => true]);
$safeAfter = round((float) Custody::balance(), 2);
$billsAfter = (int) Database::scalar('SELECT COUNT(*) FROM bawalis');

abs($safeBefore - $safeAfter) < 0.005
    ? ok('إطفاء وحدة ما بيغيّرش رصيد الخزنة') : bad('الرصيد اتغيّر');
$billsBefore === $billsAfter
    ? ok('**وولا صف بيانات اتمسح** — الإطفاء إخفاء مش حذف') : bad('صفوف اتمسحت مع الإطفاء');

/** ⚠️ الحارس ده لازم يفضل: إعداد قائمة عمره ما يدخل حسابًا (قاعدة ①). */
$financial = ['app/Models/Closing.php', 'app/Models/Custody.php', 'app/Models/CompanySafe.php',
              'app/Models/Expense.php', 'app/Models/DailyReview.php', 'app/Models/RepCustody.php'];
$breach = [];
foreach ($financial as $rel) {
    $p = $root . '/' . $rel;
    if (is_file($p) && str_contains(code_only((string) file_get_contents($p)), 'module_settings')) {
        $breach[] = $rel;
    }
}
$breach ? bad('ملف مالي بيذكر module_settings: ' . implode(' · ', $breach))
        : ok('ولا ملف مالي بيعرف الجدول (' . count($financial) . ' ملف مفحوص)');

} finally {
    $_SESSION = [];
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    NavModule::refreshReady();
}

echo "\n" . str_repeat('=', 66) . "\n";
echo "النتيجة: $pass ناجح · $fail فاشل · $skip متخطّي\n";
if ($fail > 0) { echo "\n⚠️ الإطفاء مش محروس أو القائمة اتغيّرت بلا سبب — راجع الفوق.\n"; exit(1); }
echo "✓ الوحدات والقائمة من القاعدة، والإطفاء محروس على الخادم.\n";
exit(0);
