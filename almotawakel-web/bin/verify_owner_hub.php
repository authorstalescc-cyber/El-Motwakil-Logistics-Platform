<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص دمج وحدة «أصحاب البيان» — المرحلة ٩، الدفعة الأولى
 * (docs/13-master-redesign-plan.md).
 *
 * المشكلة: «صاحب البيان» **طرف واحد في ذهن المالك** لكنه متفرّق على وحدتين
 * في القائمة (`/settlements` و`/statements`) و**١٠ شاشات**، وكل واحدة مالهاش
 * أي رابط للتانية — فالمالك كان بيرجع للقائمة الجانبية عشان ينتقل بين رصيد
 * صاحب البيان وكشف حسابه.
 *
 * الدفعة دي: **شريط تبويبات واحد** يربط الشاشات، **بلا تحريك أي مسار**.
 *
 * بيحرس:
 *   1) الشريط موجود في كل شاشات الوحدتين
 *   2) كل تبويب بيشاور على **مسار موجود فعلاً في الراوتر**
 *   3) التبويب النشط صح في كل شاشة (مفيش شاشة بتقول إنك في مكان تاني)
 *   4) الشريط بيرندر فعليًّا بلا خطأ ومخرجه فيه الروابط التلاتة
 *   5) **ولا مسار قديم اتغيّر** — المالك عنده روابط محفوظة
 *   6) **مفيش أثر مالي** — الدمج عرض بحت
 *
 * آمن تمامًا: **قراءة فقط** + رندر في الذاكرة.
 *
 * التشغيل:  php bin/verify_owner_hub.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$pass = 0; $fail = 0; $skip = 0;
function ok(string $m): void   { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  ✗ $m\n"; }
function skip(string $m): void { global $skip; $skip++; echo "  ~ $m (تخطّي)\n"; }
function sec(string $m): void  { echo "\n" . $m . "\n" . str_repeat('─', 62) . "\n"; }

$root   = dirname(__DIR__);
$router = (string) file_get_contents($root . '/public/index.php');

echo "\n╔══════════════════════════════════════════════════════════════╗\n";
echo   "║  فحص دمج «أصحاب البيان» — المرحلة ٩ (الدفعة الأولى)          ║\n";
echo   "╚══════════════════════════════════════════════════════════════╝\n";

/* ══════════════ 1) الشريط في كل الشاشات ══════════════ */
sec('1) الشريط الموحّد في كل شاشات الوحدتين');

/* الشاشة → التبويب اللي المفروض يبان نشط فيها */
$screens = [
    'settlements/index.php'      => 'settlements',
    'settlements/owner.php'      => 'settlements',
    'settlements/show.php'       => 'settlements',
    'settlements/unassigned.php' => 'unassigned',
    'statements/index.php'       => 'statements',
    'statements/pick.php'        => 'statements',
    'statements/form.php'        => 'statements',
];
foreach ($screens as $rel => $expected) {
    $path = $root . '/app/Views/' . $rel;
    if (!is_file($path)) { bad("الشاشة مش موجودة: $rel"); continue; }
    $src = (string) file_get_contents($path);
    if (!preg_match("/partial\(\s*'owner_tabs'\s*,\s*\[\s*'active'\s*=>\s*'([a-z]+)'/", $src, $m)) {
        bad("مفيش شريط تبويبات في $rel");
        continue;
    }
    $m[1] === $expected
        ? ok("$rel → التبويب النشط «{$expected}» صح")
        : bad("$rel → التبويب النشط «{$m[1]}» والمفروض «{$expected}»");
}

/* الشاشات دي **مقصود** إنها بلا شريط: صفحات طباعة A4 */
foreach (['statements/print.php', 'statements/batch_print.php'] as $rel) {
    $src = (string) file_get_contents($root . '/app/Views/' . $rel);
    !str_contains($src, 'owner_tabs')
        ? ok("$rel بلا شريط (صفحة طباعة — صح)")
        : bad("$rel فيها شريط تبويبات وهي صفحة طباعة");
}

/* ══════════════ 2) الروابط بتشاور على مسارات موجودة ══════════════ */
sec('2) كل تبويب بيشاور على مسار موجود في الراوتر');

/* لو اتغيّر مسار وما اتغيّرش الشريط، التبويب بيوصّل ٤٠٤ — والمستخدم بيتقذف
   برّه الوحدة. الفحص بيقارن بالراوتر نفسه مش بقايمة مكتوبة بالإيد. */
foreach (['/settlements', '/statements'] as $route) {
    str_contains($router, "'" . $route . "'")
        ? ok("المسار `$route` موجود في الراوتر")
        : bad("المسار `$route` مش موجود — تبويب هيوصّل ٤٠٤");
}
/* تبويب «بلا صاحب بيان» مش مسار مستقل — هو وضع على `/settlements` */
$sc = (string) file_get_contents($root . '/app/Controllers/SettlementController.php');
str_contains($sc, 'unassigned')
    ? ok('وضع «بلا صاحب بيان» لسه مدعوم في الكنترولر (`?unassigned=1`)')
    : bad('وضع «بلا صاحب بيان» اتشال — التبويب هيفتح صفحة غلط');

/* ══════════════ 3) الرندر الفعلي ══════════════ */
sec('3) الشريط بيرندر فعليًّا');

$outs = [];
foreach (['settlements', 'statements', 'unassigned'] as $active) {
    try {
        $html = partial_str('owner_tabs', ['active' => $active]);
    } catch (\Throwable $e) {
        bad("الشريط رمى استثناء عند «{$active}»: " . $e->getMessage());
        continue;
    }
    $outs[$active] = $html;
    $checks = ['class="tabs"', 'الأرصدة والسداد', 'كشوف الحساب', 'بوالص بلا صاحب بيان'];
    $missing = array_values(array_filter($checks, fn($n) => !str_contains($html, $n)));
    $missing
        ? bad("رندر «{$active}» ناقصه: " . implode(' · ', $missing))
        : ok("رندر «{$active}» فيه التلات تبويبات");
}

/* تبويب واحد بس هو النشط — لو اتنين نشطين المستخدم مش هيعرف هو فين */
foreach ($outs as $active => $html) {
    $n = substr_count($html, 'class="tab active"');
    $n === 1
        ? ok("«{$active}» فيه تبويب نشط واحد بالظبط")
        : bad("«{$active}» فيه $n تبويب نشط");
    str_contains($html, 'aria-current="page"')
        ? ok("«{$active}» بيعلّم التبويب النشط لقارئ الشاشة")
        : bad("«{$active}» بلا `aria-current`");
}

/* ══════════════ 3ب) دمج المحتوى — كشوف صاحب البيان في صفحته ══════════════ */
sec('3ب) كشوف صاحب البيان معروضة في صفحته (الدفعة ٢)');

$ownerView = (string) file_get_contents($root . '/app/Views/settlements/owner.php');
$ctrl      = (string) file_get_contents($root . '/app/Controllers/SettlementController.php');

str_contains($ctrl, 'OwnerStatement::forOwner($owner)')
    ? ok('الكنترولر بيمرّر كشوف صاحب البيان لصفحته')
    : bad('الكنترولر مش بيمرّر الكشوف — القسم هيطلع فاضي دايمًا');

/* ⚠️ `forOwner()` بتمرّ على `PartyMaster` فبتلمّ تهجئات الاسم المختلفة
   (تحذير ٣: الربط نصّي بلا FK). لو اتبدلت باستعلام مباشر على `owner = ?`
   الكشوف المسجَّلة بتهجئة تانية هتختفي من الصفحة بالسكوت. */
$osm = (string) file_get_contents($root . '/app/Models/OwnerStatement.php');
preg_match('/function forOwner[\s\S]{0,300}?statementOwnerClause/', $osm)
    ? ok('`forOwner()` لسه بتمرّ على مطابقة الأسماء الموحّدة (تحذير ٣)')
    : bad('`forOwner()` بقت بتقارن الاسم مباشرة — تهجئة مختلفة = كشوف هتختفي');

str_contains($ownerView, 'id="owner-statements"')
    ? ok('قسم الكشوف موجود في صفحة صاحب البيان')
    : bad('قسم الكشوف مش موجود في صفحة صاحب البيان');

str_contains($ownerView, "url('statements/form?owner=' . urlencode(\$owner))")
    ? ok('زر «إصدار كشف جديد» محمّل باسم صاحب البيان (مش بيرجّعه يدوّر من الأول)')
    : bad('زر الإصدار مش محمّل بالاسم');

/* الاتجاه التاني: من شاشات الكشوف ارجع لصفحة صاحب البيان */
foreach (['statements/form.php', 'statements/pick.php', 'statements/index.php'] as $rel) {
    $src = (string) file_get_contents($root . '/app/Views/' . $rel);
    str_contains($src, "settlements?owner=")
        ? ok("$rel فيها رجوع لصفحة صاحب البيان")
        : bad("$rel مفيهاش أي رابط لصفحة صاحب البيان");
}

/* ══════════════ 3ج) الرندر الفعلي لصفحة صاحب البيان ══════════════ */
sec('3ج) صفحة صاحب البيان بترندر ببيانات حقيقية');

try {
    $realOwner = (string) (\App\Core\Database::scalar(
        "SELECT declaration_owner FROM bawalis
         WHERE declaration_owner IS NOT NULL AND TRIM(declaration_owner) <> ''
         GROUP BY declaration_owner ORDER BY COUNT(*) DESC LIMIT 1"
    ) ?? '');
} catch (\Throwable $e) { $realOwner = ''; }

if ($realOwner === '') {
    skip('مفيش صاحب بيان في القاعدة — رندر الصفحة اتخطّى');
} else {
    $uid = (int) \App\Core\Database::scalar('SELECT MIN(id) FROM users');
    $savedSession = $_SESSION ?? [];
    $savedGet     = $_GET;
    $_SESSION = ['uid' => $uid, 'role' => 'owner', 'uname' => 'فحص',
                 'last_activity' => time(), 'uid_checked' => time()];
    $_GET = ['owner' => $realOwner];

    ob_start();
    try {
        (new \App\Controllers\SettlementController())->index();
        $html = (string) ob_get_clean();
    } catch (\Throwable $e) {
        ob_end_clean(); $html = '';
        bad('استثناء وقت رندر صفحة صاحب البيان: ' . $e->getMessage());
    }
    $_SESSION = $savedSession; $_GET = $savedGet;

    if ($html === '') {
        skip('الصفحة ما رندرتش — باقي فحوصات الرندر اتخطّت');
    } else {
        preg_match('/Fatal error|Warning:|Undefined variable|Undefined array key/', $html, $m)
            ? bad('خطأ PHP في صفحة صاحب البيان: ' . ($m[0] ?? ''))
            : ok('بترندر بلا أخطاء PHP (صاحب البيان: ' . mb_substr($realOwner, 0, 24) . ')');

        str_contains($html, 'owner-statements')
            ? ok('قسم الكشوف ظاهر فعليًّا في المخرج')
            : bad('قسم الكشوف مش ظاهر في المخرج');

        str_contains($html, 'class="tabs"')
            ? ok('شريط التبويبات الموحّد ظاهر في صفحة صاحب البيان')
            : bad('شريط التبويبات مش ظاهر');

        str_contains($html, 'إصدار كشف جديد')
            ? ok('زر إصدار الكشف ظاهر')
            : bad('زر إصدار الكشف مش ظاهر');
    }
}

/* ══════════════ 4) مفيش مسار اتحرّك ══════════════ */
sec('4) ولا مسار قديم اتغيّر (المالك عنده روابط محفوظة)');

$mustKeep = [
    "\$r->get('/settlements'",
    "\$r->get('/settlements/{id}'",
    "\$r->post('/settlements/pay'",
    "\$r->post('/settlements/assign-owner'",
    "\$r->get('/statements'",
    "\$r->get('/statements/new'",
    "\$r->get('/statements/form'",
    "\$r->get('/statements/batch'",
    "\$r->get('/statements/{id}'",
];
$moved = array_values(array_filter($mustKeep, fn($x) => !str_contains($router, $x)));
$moved
    ? bad('مسارات اتغيّرت أو اتشالت: ' . implode(' · ', $moved))
    : ok('كل الـ' . count($mustKeep) . ' مسار زي ما هم — الدمج عرض بحت');

/* ⚠️ ترتيب المسارات حسّاس: الحرفي لازم **قبل** الجشع `/{id}` (تحذير ٤) */
$posBatch = strpos($router, "\$r->get('/statements/batch'");
$posId    = strpos($router, "\$r->get('/statements/{id}'");
($posBatch !== false && $posId !== false && $posBatch < $posId)
    ? ok('`/statements/batch` لسه قبل الجشع `/statements/{id}`')
    : bad('ترتيب المسارات اتقلب — `batch` هيتبلع في `{id}`');

/* ══════════════ 5) العزل المالي ══════════════ */
sec('5) العزل المالي — الدمج عرض بحت');

$tabsSrc = (string) file_get_contents($root . '/app/Views/partials/owner_tabs.php');
$dirty = [];
foreach (['Database::', 'INSERT', 'UPDATE ', 'DELETE'] as $needle) {
    if (str_contains($tabsSrc, $needle)) { $dirty[] = $needle; }
}
$dirty ? bad('الشريط بيلمس القاعدة: ' . implode(', ', $dirty))
       : ok('الشريط ما بيلمسش القاعدة خالص');

try {
    $b1 = (float) \App\Models\Custody::balance();
    foreach (['settlements', 'statements', 'unassigned'] as $a) { partial_str('owner_tabs', ['active' => $a]); }
    $b2 = (float) \App\Models\Custody::balance();
    abs($b1 - $b2) < 0.001
        ? ok('رصيد خزنة المكتب ما اتغيّرش (' . number_format($b1, 2) . ')')
        : bad('⚠️ رصيد الخزنة اتغيّر: ' . $b1 . ' → ' . $b2);
} catch (\Throwable $e) {
    skip('تعذّر قياس رصيد الخزنة: ' . $e->getMessage());
}

echo "\n" . str_repeat('=', 62) . "\n";
echo "نجح: $pass   |   فشل: $fail   |   تخطّي: $skip\n";
if ($fail > 0) { echo "\n✗ الفحص فشل — الكود ده ما يترفعش.\n"; exit(1); }
echo "\n✓ كل الفحوصات عدّت.\n";
exit(0);
