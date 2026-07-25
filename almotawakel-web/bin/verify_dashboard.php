<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص إعادة بناء لوحة التحكم — المرحلة ١١ (2026-07-25).
 *
 * الداشبورد كانت ٩ أقسام ورا بعض (٧٢٩ سطر) والمالك بينزل ٤ شاشات عشان يوصل
 * لآخرها، ولوحة المناديب جوّاها كانت **بتكرّر صفحة `/rep-custody` كلها**.
 * البناء الجديد ٤ طبقات: ① اليوم · ② محتاج منك · ③ ٤ أرقام · ④ الشغل الجاري.
 *
 * ⚠️ الفحص **بيرندر الصفحة ونوافذ التفاصيل السبعة فعليًّا** ببيانات حقيقية —
 *    مش فحص نصّي — عشان يمسك الفشل الصامت الموثَّق في `Controller::render`
 *    (`extract($data, EXTR_SKIP)` بيتخطّى أي مفتاح اسمه `data`، فالصفحة بتطلع
 *    غلط **بلا أي خطأ PHP**).
 *
 * أهم المحروس:
 *   • **ممنوع رقم أصمّ** — كل رقم في الصفحة معاه `data-drill`.
 *   • **البوب-أب القديم اتشال** — `#dModal` و`#popStore` كانوا بيرندروا تفاصيل
 *     كل الأرقام مع **كل** فتحة للصفحة حتى لو ما اتفتحتش.
 *   • **مصدر واحد للأرقام** — «محتاج منك» و«الجرس» و«شارات القائمة» بيقروا
 *     من `Notification::feed()` نفسها.
 *   • **مُصيّر صف واحد** للصفحة والنافذة (`_rows.php`) — مستحيل الشكل يفترق.
 *   • **القائمة البيضا** لنوع التفاصيل — مش اسم جدول جاي من المستخدم.
 *   • **العزل المالي** — رصيد الخزنة قبل = بعد (الصفحة قراءة فقط).
 *
 * قراءة فقط بالكامل — ما بيكتبش أي صف.
 * التشغيل:  php bin/verify_dashboard.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\DashboardController;
use App\Core\Access;
use App\Core\Database;
use App\Models\Closing;
use App\Models\Notification;

$pass = 0; $failures = [];
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $failures; $failures[] = $m; echo "  ✗ $m\n"; }
function chk(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function sec(string $m): void { echo "\n$m\n" . str_repeat('─', 62) . "\n"; }

/** يشيل التعليقات قبل الفحص — تعليق بيوثّق قاعدة قديمة كان بيدّي إنذارًا كاذبًا. */
function php_code(string $src): string
{
    $src = preg_replace('~<!--.*?-->~s', '', $src) ?? $src;
    return preg_replace('~/\*.*?\*/|(?<!:)//[^\n]*~s', '', $src) ?? $src;
}

echo "فحص لوحة التحكم — المرحلة ١١\n" . str_repeat('=', 62) . "\n";

$root  = dirname(__DIR__);
$idx   = (string) file_get_contents($root . '/app/Views/dashboard/index.php');
$idxC  = php_code($idx);
$drillV = (string) file_get_contents($root . '/app/Views/dashboard/_drill.php');
$rowsV = (string) file_get_contents($root . '/app/Views/dashboard/_rows.php');
$ctrl  = php_code((string) file_get_contents($root . '/app/Controllers/DashboardController.php'));
$routes = (string) file_get_contents($root . '/public/index.php');

$treasuryBefore = Closing::currentBalance();

// ═══ ① الطبقات الأربعة
sec('① البناء الجديد — أربع طبقات بأولوية واضحة');
chk(str_contains($idxC, "partial('day_bar'"), '① اليوم: شريط يوم العمل الموحّد');
chk(str_contains($idxC, 'خزنة المكتب') && str_contains($idxC, "'facts'"),
    'ورصيد الخزنة ظاهر جنب التاريخ');
chk(str_contains($idxC, 'id="quickEntryOpen"') && str_contains($idxC, "'actions'"),
    'وزر الإدخال السريع جوّه الشريط نفسه');
chk(str_contains($idxC, 'Notification::feed()'), '② محتاج منك: من مجمّع الإشعارات');
chk(str_contains($idxC, "partial('stat_grid'"), '③ الأرقام: شبكة `stat_grid`');
/* خمس استدعاءات لأن الرابع فرعان (عهدة المناديب / أذونات مفتوحة حسب توفّر
   الوحدة) — العدد **المرسوم** أربعة، والفحص عليه في القسم ⑥. */
chk(substr_count($idxC, "partial('stat',") === 5, 'أربع أرقام مرسومة (خمس استدعاءات بفرعين)');
chk(str_contains($idxC, 'الشغل الجاري'), '④ الشغل الجاري: قسم واحد للمتابعة');
chk(substr_count($idxC, "partial('panel'") === 3, 'وفيه تلات لوحات (جاهز للفوترة · مفتوحة · فلوس اليوم)');

// ═══ ② اللي اتشال عن قصد
sec('② اللي اتشال — والسبب');
chk(!str_contains($idxC, 'd-hero'),
    'ترويسة التحية والوقت اتشالت (كانت بتاخد شاشة وما بتقولش حاجة)');
chk(!str_contains($idxC, 'rp-panel') && !str_contains($idxC, 'rp-strip'),
    'لوحة المناديب المفصّلة اتشالت (كانت بتكرّر صفحة /rep-custody كلها)');
chk(!str_contains($ctrl, 'Metrics::recentActivity(10)'),
    'استعلام «آخر ما اتسجّل» ما بقاش بيتدفع مع كل فتحة — بقى في مسار التفاصيل');
chk(str_contains($ctrl, "case 'activity'") || str_contains($ctrl, 'Metrics::recentActivity(20)'),
    'والمعلومة نفسها لسه موجودة — نافذة من ترويسة ④');
chk(!str_contains($ctrl, "'custodyDay'") && !str_contains($ctrl, "'dayExp'"),
    'تفاصيل الخزنة ومصروفات/إيصالات اليوم بقت كسولة');
/* ملاحظة موثَّقة من المرحلة ٤: الداشبورد **بلا نولون وبلا «المتبقي للعملاء»** */
chk(!str_contains($idxC, 'المتبقي للعملاء'), 'ومفيش «المتبقي للعملاء» (قرار المالك من المرحلة ٤)');
chk(!str_contains($idxC, 'Chart') && !str_contains($idxC, 'chart.js'), 'ومفيش رسوم — مكانها /reports');

// ═══ ③ ⚠️ ممنوع رقم أصمّ
sec('③ ⚠️ ممنوع رقم أصمّ (قاعدة المالك الدائمة)');
chk(substr_count($idxC, "'drill' =>") === substr_count($idxC, "partial('stat',") + 2,
    'كل رقم معاه مصدر تفاصيل (+ لوحتان بنافذة تفاصيل)');
chk(!preg_match('/class="d-kpi(?![^>]*data-drill)/', $idxC), 'مفيش رقم مرسوم بالإيد بلا تفاصيل');
chk(str_contains($idxC, 'data-drill=') && str_contains($idxC, 'd-sec-act'),
    'وحتى «آخر ما اتسجّل» بقى نافذة تفاصيل مش قسم');
chk(!str_contains($idxC, 'id="dModal"') && !str_contains($idxC, 'id="popStore"'),
    '⚠️ البوب-أب المحلي اتشال — كان بيرندر تفاصيل كل الأرقام مع كل فتحة');
chk(!str_contains($idxC, 'data-pop='), 'ومفيش خطّاف `data-pop` باقي بلا محرّك');

// ═══ ④ مصدر واحد للأرقام والصفوف
sec('④ مفيش تعريف تاني لأي رقم ولا لأي شكل');
chk(str_contains($idx, "require __DIR__ . '/_rows.php'") &&
    str_contains($drillV, "require __DIR__ . '/_rows.php'"),
    '⚠️ الصفحة والنافذة بيستخدموا **نفس** مُصيّر الصف (`_rows.php`)');
chk(str_contains($rowsV, 'function_exists'),
    'والمُصيّر محروس بـfunction_exists (الملفان بيتحمّلوا في نفس الطلب)');
chk(!str_contains($rowsV, '<table'), 'الصفوف بمكوّن `row_item` — مفيش جدول خام');
chk(substr_count($drillV, "partial('drill'") === 7, 'كل نوع تفاصيل بيستخدم مكوّن `drill` الموحّد');
$feedNow = Notification::feed();
chk(is_array($feedNow) && array_key_exists('count', $feedNow) && array_key_exists('items', $feedNow),
    'مجمّع الإشعارات بيرد بنفس الشكل اللي الداشبورد بتقراه');

// ═══ ⑤ ⚠️ مسار التفاصيل — محصور وبيمرّ على الصلاحيات
sec('⑤ ⚠️ مسار التفاصيل مش عام');
chk(str_contains($routes, "\$r->get('/dashboard/drill/{what}'"), 'المسار مسجَّل');
chk(preg_match("/in_array\(\\\$what, \['expenses', 'receipts', 'custody', 'reps', 'ready', 'permits_open', 'activity'\], true\)/", $ctrl) === 1,
    'النوع محصور في قائمة بيضا — مش اسم جدول جاي من المستخدم');
chk(str_contains($ctrl, 'headers_sent()'),
    '⚠️ محروس بـheaders_sent — من غيره Warning بيتلزق جوّه جسم الرد ويبوّظ النافذة (باج v50)');
chk(str_contains($ctrl, "\$this->render('dashboard/_drill', \$data, false)"),
    'بيرندر بلا layout ويرجّع HTML مش JSON');
chk(Access::allows('owner', 'DashboardController@drill', 'GET'), 'المالك مسموح له');
chk(!Access::allows('guest', 'DashboardController@drill', 'GET')
    || Access::allows('guest', 'DashboardController@index', 'GET'),
    'الصلاحية بتمرّ على Access نفسها مش شرط مكتوب بالإيد');

// ═══ ⑥ رندر فعلي للصفحة
sec('⑥ الصفحة بترندر فعليًّا ببيانات حقيقية');
$uid = (int) Database::scalar('SELECT MIN(id) FROM users');
$_SESSION = ['uid' => $uid, 'role' => 'owner', 'uname' => 'فحص',
             'last_activity' => time(), 'uid_checked' => time()];
$_GET = [];
$render = static function (callable $fn): string {
    ob_start();
    try { $fn(); return (string) ob_get_clean(); }
    catch (\Throwable $e) { ob_end_clean(); bad('استثناء وقت الرندر: ' . $e->getMessage()); return ''; }
};
$noPhpError = static function (string $h, string $where): void {
    preg_match('/Fatal error|Warning:|Undefined variable|Undefined array key/', $h, $m)
        ? bad("خطأ PHP في {$where}: " . ($m[0] ?? ''))
        : ok("{$where} بيرندر بلا أخطاء PHP");
};

$c = new DashboardController();
$html = $render(static fn () => $c->index());
if ($html === '') {
    bad('الداشبورد ما رندرتش خالص');
} else {
    $noPhpError($html, 'الداشبورد');
    chk(str_contains($html, 'class="day-bar'), 'شريط اليوم اتطبع');
    chk(str_contains($html, 'محتاج منك'), 'قسم «محتاج منك» اتطبع');
    chk(substr_count($html, 'class="d-kpi ') === 4, 'أربعة أرقام اتطبعوا فعلاً');
    chk(substr_count($html, 'data-drill=') >= 5, 'وكلهم + «آخر ما اتسجّل» ليهم مصدر تفاصيل');
    chk(!str_contains($html, 'ممنوع رقم أصمّ'),
        '⚠️ مفيش رقم اترفض رندره (المكوّن بيسيب أثرًا في المصدر لو حصل)');
    chk(!str_contains($html, 'id="popStore"'), 'ومفيش مخزن تفاصيل مرندَر مسبقًا في الصفحة');
}

// ═══ ⑦ رندر كل نافذة تفاصيل
sec('⑦ نوافذ التفاصيل السبعة بترندر فعليًّا');
foreach (['expenses', 'receipts', 'custody', 'reps', 'ready', 'permits_open', 'activity'] as $what) {
    $out = $render(static fn () => (new DashboardController())->drill($what));
    if ($out === '') { bad("نافذة `$what` ما رندرتش"); continue; }
    $noPhpError($out, "نافذة `$what`");
    chk(str_contains($out, 'drill-how') || str_contains($out, 'class="empty'),
        "نافذة `$what` فيها «إزاي اتحسب» أو حالة فراغ واضحة");
    chk(!str_contains($out, '<html'), "نافذة `$what` بترجع جزءًا مش صفحة كاملة");
}

// ═══ ⑧ العزل المالي — الحد الأحمر
sec('⑧ ⚠️ العزل المالي — الداشبورد قراءة فقط');
/* ⚠️ الفحص على **استعلامات** لا على كلمة `delete` (اللي بتظهر في مسار حذف
   ملاحظة وهو `POST` عادي بيتنفّذ في الكنترولر مش في الشاشة). */
chk(!preg_match('~Database::(exec|insert|update|delete)|\b(INSERT INTO|UPDATE\s+\w+\s+SET|DELETE FROM)\b~i',
        php_code($drillV) . php_code($rowsV) . $idxC),
    'مفيش أي استعلام كتابة في شاشة الداشبورد ولا نوافذها');
$treasuryAfter = Closing::currentBalance();
chk(abs($treasuryBefore - $treasuryAfter) < 0.005,
    'رصيد الخزنة قبل = بعد (' . money($treasuryBefore, false) . ')');

echo "\n" . str_repeat('=', 62) . "\n";
echo 'النتيجة: ' . $pass . ' ناجح · ' . count($failures) . " فاشل\n";
if ($failures) { foreach ($failures as $f) { echo "  ✗ $f\n"; } exit(1); }
exit(0);
