<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص شاشات الفلوس باللغة الجديدة — المرحلة ١٤ (2026-07-25).
 *
 * الوحدات دي أخطر حاجة في النظام، فجت **بعد** ما اللغة اتجرّبت على عهدة
 * المناديب والداشبورد والأذونات. الشغل هنا **عرض بحت** — ولا معادلة مالية
 * واحدة اتلمست، ولا قاعدة من v18/v22/v42/v49.
 *
 * أهم المحروس:
 *   • **ممنوع رقم أصمّ** — كل رقم في `/office` و`/custody` و`/payments` بيفتح
 *     بنوده، وفي `/expenses` و`/debts` بيوصّل لقايمته المفلترة (شكل أقوى).
 *   • **نافذة التحصيلات بتحترم الفلاتر** — الرقم محسوب على المعروض، فلو
 *     النافذة جابت الكل تبقى بتقول رقمًا تاني.
 *   • **مفيش استعلام جديد** — النوافذ بتقرا من نفس دوال الموديل.
 *   • **العزل المالي** — رصيد الخزنة قبل = بعد، والشاشات مفيهاش استعلام كتابة.
 *
 * بيرندر الشاشات ونوافذها **فعليًّا** بلا أي كتابة.
 * التشغيل:  php bin/verify_money_ui.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\CustodyController;
use App\Controllers\DebtController;
use App\Controllers\ExpenseController;
use App\Controllers\OfficeController;
use App\Controllers\PaymentController;
use App\Core\Access;
use App\Core\Database;
use App\Models\Closing;

$pass = 0; $failures = [];
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $failures; $failures[] = $m; echo "  ✗ $m\n"; }
function chk(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function sec(string $m): void { echo "\n$m\n" . str_repeat('─', 62) . "\n"; }

function code_only(string $src): string
{
    $src = preg_replace('~<!--.*?-->~s', '', $src) ?? $src;
    return preg_replace('~/\*.*?\*/|(?<!:)//[^\n]*~s', '', $src) ?? $src;
}

echo "فحص شاشات الفلوس — المرحلة ١٤\n" . str_repeat('=', 62) . "\n";

$root = dirname(__DIR__);
$read = static fn (string $rel): string => (string) file_get_contents($root . '/' . $rel);
$office   = code_only($read('app/Views/office/index.php'));
/* ⚠️ خزنة المكتب اتقسمت لتبويبات + درج (2026-08-31، docs/21): الفحص بيقرا
   **الشاشة كلها** مش الغلاف، وإلا بيفشل بإنذار كاذب لمجرد إن القسم اتنقل. */
/* ⚠️ `code_only()` هنا بتاخد **النص** مش المسار (توقيعها مختلف عن باقي
   الفاحصات) — تمرير المسار ليها بيدّي فحصًا على اسم الملف بلا ما يقع. */
$custodyFiles = glob($root . '/app/Views/custody/{index,_tab_*,_drawer,_drill,_period}.php',
                     GLOB_BRACE) ?: [];
$custody = '';
foreach ($custodyFiles as $__cf) { $custody .= code_only((string) file_get_contents($__cf)); }
if (!$custodyFiles) { $custody = '__مفيش_ملفات_شاشة_الخزنة__'; }
$payments = code_only($read('app/Views/payments/index.php'));
$expenses = code_only($read('app/Views/expenses/index.php'));
$debts    = code_only($read('app/Views/debts/index.php'));
$routes   = $read('public/index.php');

$treasuryBefore = Closing::currentBalance();

// ═══ ① كروت الفيزا
sec('① /office — كروت الفيزا');
chk(str_contains($office, "partial('stat_grid'") && !str_contains($office, 'class="kpi-grid'),
    'الأرقام التلاتة بمكوّن `stat`');
/* ⚠️ **بلا مسافات مكتوبة بالإيد**: كان `substr_count($office, "'drill' =>")`
   فوقع بإنذار كاذب لما v110 أعاد صياغة الشاشة بـ`'drill'=>$od(...)` (بلا
   مسافة) — الأرقام التلاتة لسه بتفتح بنودها والفحص بيقول لأ. الفحص على
   **المعنى**: كل رقم معاه مصدر تفاصيل. */
chk(preg_match_all("/'drill'\s*=>/", $office) >= 3, 'وكلهم بيفتحوا بنودهم');
chk(!str_contains($office, '<table'), 'الجدول (٧ أعمدة) اتشال — الكارت بقى `entity_card`');
chk(str_contains($office, "partial('entity_card'") && str_contains($office, "partial('row_item'"),
    'وبيستخدم كارت الكيان والصف');
/* ⚠️ **التوقّع اتقلب عن قصد (2026-08-08) — مش ترخية.** «إخفاء الأرصدة»
   (`data-secret` + `'secret' => true` + `.secret-toggle`) **اتشال من النظام
   كله بقرار المالك يوم 2026-08-02** من ١٢ ملف. الفاحص ده اتنسي فضل يحرس ميزة
   مشالة ويدّي إنذارًا كاذبًا دائمًا — نفس اللي اتعمل في
   `verify_rep_custody_day` §⑩ وقتها. الحارس دلوقتي بيمنع **رجوعها**، لأن
   الرجوع نص-نص (سمة بلا معالج) بيرجّع الباج القديم: رقم مقنّع للأبد بلا
   طريقة يتشاف بيها. */
chk(!str_contains($office, 'secret-toggle') && !str_contains($office, "'secret' => true"),
    'إخفاء الأرصدة مشال (قرار المالك 2026-08-02) — ولا رجع نص-نص');

// ═══ ② خزنة المكتب
sec('② /custody — خزنة المكتب');
/* ⚠️⚠️ **الفحوصات دي اتغيّرت مع إعادة البناء (2026-08-31، docs/21) — والسبب
   مسجَّل عشان ما يتقريش «حد ضعّف الفحص».** كانت مربوطة بتفاصيل التنفيذ القديم:
   `#custodyBreak` (تفكيك جاهز جوّه الصفحة) و`stat_grid` في الغلاف. والتنفيذ ده
   نفسه كان هو الضعف: **الأربع أرقام كانوا بيوجّهوا لنفس النافذة بالحرف**، يعني
   تقنيًا مفيش رقم أصمّ وعمليًا ضغط أي رقم بيدّي نفس الجواب.
   دلوقتي كل رقم له نافذته من `/custody/drill/{what}` — والفحص بقى على
   **المعنى**: كل رقم بيفتح تفاصيل، والتفاصيل دي مختلفة عن بعضها. */
chk(str_contains($custody, 'data-drill=') || str_contains($custody, "'drill' =>"),
    'أرقام الخزنة بتفتح تفاصيلها (ممنوع رقم أصمّ)');
/* ⚠️ الشاشة بتبني الرابط بمختصر (`$cd('balance')`) فالنص الكامل
   `custody/drill/balance` مش موجود حرفيًّا — الفحص لازم يمسك الشكلين. */
preg_match_all("~custody/drill/([a-z_]+)|\\\$cd\\('([a-z_]+)'\\)~", $custody, $cdm);
$drillTargets = array_unique(array_filter(array_merge($cdm[1] ?? [], $cdm[2] ?? [])));
chk(count($drillTargets) >= 4,
    '⚠️ وكل رقم بيفتح **نافذته هو** (' . count($drillTargets)
    . ' نافذة مختلفة) — مش الأربعة على نفس النافذة زي قبل كده');
$custodyCtrl = code_only($read('app/Controllers/CustodyController.php'));
chk(str_contains($custodyCtrl, 'const DRILL = ') && str_contains($custodyCtrl, 'notFound'),
    'ونوع التفاصيل محصور في قائمة بيضا');
chk(!str_contains($custody, '<table'), 'جدول السجل (٨ أعمدة مليانة `—`) اتشال');
chk(str_contains($custody, "partial('row_item'") && str_contains($custody, "partial('panel'"),
    'والسجل بقى صفوف جوّه لوح');
chk(str_contains($custody, 'الرصيد الجاري'),
    'والرصيد الجاري لسه ظاهر (بقى سطر جنب المبلغ بدل عمود لوحده)');
/* ⚠️ الضمانة الجديدة: الرصيد الجاري لازم يبدأ من رصيد أول المدة لا من صفر —
   دي كانت أصل «الكارت بيقول رقم والسجل بيقول رقم تاني». */
chk(str_contains(code_only($read('app/Models/Custody.php')), '$run = $opening;'),
    '⚠️⚠️ والرصيد الجاري بيبدأ من رصيد أول المدة (مش من صفر)');

// ═══ ③ التحصيلات
sec('③ /payments — تحصيلات العملاء');
chk(str_contains($payments, "partial('stat_grid'") && !str_contains($payments, 'class="stat-strip'),
    'الأربع أرقام بمكوّن `stat`');
chk(substr_count($payments, "'drill' =>") === 4, 'وكلهم بيفتحوا دفعاتهم');
chk(str_contains($payments, "http_build_query(\$pq)"),
    '⚠️ والنافذة بتاخد **فلاتر الشاشة** — الرقم محسوب على المعروض');
$pctrl = code_only($read('app/Controllers/PaymentController.php'));
chk(str_contains($pctrl, "private const DRILL = ['all', 'cash', 'visa', 'none']"),
    'النوع محصور في قائمة بيضا');
chk(str_contains($pctrl, "'route'  => \$what === 'all' ? trim(\$_GET['route'] ?? '') : \$what"),
    'والتوجيه بيتاخد من نوع النافذة مش من المستخدم');

// ═══ ④ المصروفات والمديونيات — الرقم بيوصّل لقايمته
sec('④ /expenses و/debts — الرقم بيوصّل لبنوده');
chk(str_contains($expenses, '<a class="exp-src'),
    '⚠️ كارت كل مصدر مصروف بقى **زرار فلترة** — الضغط بيعرض بنوده في السجل');
chk(str_contains($expenses, "\$qs(['source' => \$isOn ? '' : \$key])"),
    'والضغط تاني بيلغي التصفية');
chk(str_contains($expenses, '.exp-src.on{'), 'والكارت المعروض دلوقتي بيبان معلَّم');
/* ⚠️ **الفحص على المعنى مش على اسم كلاس**: البطاقات كانت ماركب `.kpi` مكتوبًا
   بالإيد وبقت مكوّن `stat` المشترك بمعامل `url` (2026-07-28). اللي بيهمّنا إن
   كل رقم لسه **بيوصّل لبنوده** — والمكوّن نفسه بيرفض يترسم بلا `drill` ولا
   `url` (قاعدة «ممنوع رقم أصمّ»)، فالحماية بقت في المنبع. */
chk(substr_count($debts, "partial('stat',") === 4 && substr_count($debts, "'url' =>") === 4,
    'أربع بطاقات في /debts بمكوّن `stat`، وكل واحدة معاها `url` لبنودها');
chk(str_contains($debts, "url('debts?tab=owners')")
    && str_contains($debts, "url('debts?tab=visa')"),
    'ورقم «إجمالي المديونيات» و«الفيزا» لسه بيوصّلوا لتبويبهم');
chk(!preg_match('~<div class="kpi [a-z]+"><div class="lbl">~', $debts),
    'ومفيش رقم باقي في /debts بلا طريق');

// ═══ ⑤ ⚠️ المسارات محصورة وحرفية قبل الجشعة
sec('⑤ ⚠️ مسارات التفاصيل');
chk(str_contains($routes, "\$r->get('/office/drill/{what}'"), 'مسار تفاصيل الكروت مسجَّل');
$oDrill = strpos($routes, "/office/drill/{what}");
$oGreedy = strpos($routes, "\$r->get('/office/{id}'");
chk($oDrill !== false && $oGreedy !== false && $oDrill < $oGreedy,
    'وهو **حرفي قبل** الجشع `/office/{id}` (تحذير ٤)');
chk(str_contains($routes, "\$r->get('/payments/drill/{what}'"), 'مسار تفاصيل التحصيلات مسجَّل');
$pDrill = strpos($routes, "/payments/drill/{what}");
$pGreedy = strpos($routes, "'/payments/{id}/delete'");
chk($pDrill !== false && $pGreedy !== false && $pDrill < $pGreedy,
    'وهو حرفي قبل `/payments/{id}/delete`');
chk(Access::allows('owner', 'OfficeController@drill', 'GET')
    && Access::allows('owner', 'PaymentController@drill', 'GET'),
    'المسارين بيمرّوا على Access');

// ═══ ⑥ رندر فعلي
sec('⑥ الشاشات ونوافذها بترندر فعليًّا');
$uid = (int) Database::scalar('SELECT MIN(id) FROM users');
$_SESSION = ['uid' => $uid, 'role' => 'owner', 'uname' => 'فحص',
             'last_activity' => time(), 'uid_checked' => time()];
$_GET = [];
$render = static function (callable $fn, string $where): string {
    ob_start();
    try { $fn(); $html = (string) ob_get_clean(); }
    catch (\Throwable $e) { ob_end_clean(); bad("استثناء في {$where}: " . $e->getMessage()); return ''; }
    preg_match('/Fatal error|Warning:|Undefined variable|Undefined array key/', $html, $m)
        ? bad("خطأ PHP في {$where}: " . ($m[0] ?? ''))
        : ok("{$where} بيرندر بلا أخطاء PHP");
    return $html;
};

$render(static fn () => (new OfficeController())->index(), '/office');
$render(static fn () => (new CustodyController())->index(), '/custody');
$render(static fn () => (new PaymentController())->index(), '/payments');
$render(static fn () => (new ExpenseController())->index(), '/expenses');
$render(static fn () => (new DebtController())->index(), '/debts');
foreach (['balance', 'deposits', 'payments', 'owners'] as $w) {
    $out = $render(static fn () => (new OfficeController())->drill($w), "نافذة office/$w");
    chk(!str_contains($out, '<html'), "نافذة office/$w بترجع جزءًا مش صفحة");
}
foreach (['all', 'cash', 'visa', 'none'] as $w) {
    $out = $render(static fn () => (new PaymentController())->drill($w), "نافذة payments/$w");
    chk(!str_contains($out, '<html'), "نافذة payments/$w بترجع جزءًا مش صفحة");
}

// ═══ ⑦ ⚠️ العزل المالي — الحد الأحمر
sec('⑦ ⚠️ العزل المالي — الشغل كله عرض');
foreach ([['office/_drill.php', 'office'], ['payments/_drill.php', 'payments']] as [$rel, $name]) {
    chk(!preg_match('~Database::(exec|insert|update|delete)|\b(INSERT INTO|UPDATE\s+\w+\s+SET|DELETE FROM)\b~i',
            code_only($read('app/Views/' . $rel))),
        "نافذة {$name} مفيهاش أي استعلام كتابة");
}
chk(abs($treasuryBefore - Closing::currentBalance()) < 0.005,
    'رصيد الخزنة قبل = بعد (' . money($treasuryBefore, false) . ')');

echo "\n" . str_repeat('=', 62) . "\n";
echo 'النتيجة: ' . $pass . ' ناجح · ' . count($failures) . " فاشل\n";
if ($failures) { foreach ($failures as $f) { echo "  ✗ $f\n"; } exit(1); }
exit(0);
