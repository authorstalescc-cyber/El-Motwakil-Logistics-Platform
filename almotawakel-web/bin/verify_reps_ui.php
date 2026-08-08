<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص تحويل وحدة المناديب للغة النظام — المرحلة ١٢ (2026-07-25).
 *
 * الوحدة ٧ شاشات مكتب + ١٣ شاشة موبايل. قبل المرحلة دي كان كارت المندوب
 * وتبويباته **جداول خام** (لحد ٦ أعمدة مليانة `—`) وأرقامه بمكوّن `kpi`
 * القديم **بلا أي تفاصيل ورا الرقم**، وشريط اليوم مكتوبًا بالإيد (`.rc-daybar`)
 * فبقى نسخة تانية بتفترق عن باقي الشاشات مع أول تعديل.
 *
 * أهم المحروس:
 *   • **ممنوع رقم أصمّ** — كل رقم في كارت المندوب وصندوق المراجعة معاه `drill`.
 *   • **مفيش استعلام جديد للتفاصيل** — `repSpendRows` بتفلتر `spending()` نفسها
 *     اللي العدّاد اتحسب منها، فمستحيل الشريحة تقول رقمًا والنافذة تقول غيره.
 *   • **الجداول اللي كل صف فيها كيان اتحوّلت لصفوف**، والجدول الوحيد الباقي
 *     (عهدة كل يوم) **مقصود** لأنه مقارنة رقمية بين أيام.
 *   • **ألوان الهوية متطابقة بين `app.css` و`m.css`** — اختلافها معناه إن المالك
 *     والمندوب بيشوفوا نفس الحاجة بلونين.
 *   • **العزل المالي** — رصيد الخزنة قبل = بعد.
 *
 * قراءة فقط بالكامل (بيرندر بلا كتابة).
 * التشغيل:  php bin/verify_reps_ui.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\RepCustodyController;
use App\Core\Access;
use App\Core\Database;
use App\Models\Closing;
use App\Models\Rep;

$pass = 0; $failures = [];
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $failures; $failures[] = $m; echo "  ✗ $m\n"; }
function chk(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function sec(string $m): void { echo "\n$m\n" . str_repeat('─', 62) . "\n"; }

/** يشيل التعليقات — تعليق بيوثّق ماركب قديم كان بيدّي إنذارًا كاذبًا. */
function code_only(string $src): string
{
    $src = preg_replace('~<!--.*?-->~s', '', $src) ?? $src;
    return preg_replace('~/\*.*?\*/|(?<!:)//[^\n]*~s', '', $src) ?? $src;
}

echo "فحص وحدة المناديب — المرحلة ١٢\n" . str_repeat('=', 62) . "\n";

$root = dirname(__DIR__);
$v = static fn (string $f): string => (string) file_get_contents($root . '/app/Views/rep_custody/' . $f);
$card    = code_only($v('card.php'));
$tabDay  = code_only($v('_tab_day.php'));
$tabAcc  = code_only($v('_tab_account.php'));
$tabPerm = code_only($v('_tab_permits.php'));
$inbox   = code_only($v('inbox.php'));
$drillV  = code_only($v('_drill.php'));
$ctrl    = code_only((string) file_get_contents($root . '/app/Controllers/RepCustodyController.php'));

$treasuryBefore = Closing::currentBalance();

// ═══ ① كارت المندوب اتحوّل
sec('① كارت المندوب باللغة الجديدة');
chk(str_contains($card, "partial('day_bar'") && !str_contains($card, 'rc-daybar'),
    '⚠️ شريط اليوم بقى المكوّن الموحّد — النسخة المكتوبة بالإيد اتشالت');
chk(str_contains($card, "partial('stat_grid'") && !str_contains($card, "partial('kpi_grid'"),
    'الأرقام بمكوّن `stat` مش `kpi` القديم');
chk(substr_count($card, "'drill' =>") === 4, 'أربع أرقام كلها معاها مصدر تفاصيل');
chk(!str_contains($card, 'rc-tot'),
    'سطر «التراكمي» المكرَّر اتشال — الرقم بقى حقيقة في شريط اليوم');
chk(str_contains($card, "partial('tabs'"), 'التبويبات لسه بمكوّن `tabs` (روابط حقيقية)');

// ═══ ② التبويبات: الجداول اللي كل صف فيها كيان اتحوّلت
sec('② الجداول اللي كل صف فيها كيان بقت صفوف');
chk(!str_contains($tabDay, '<table'), 'تبويب «اليوم» بلا أي جدول خام');
chk(!str_contains($tabPerm, '<table'), 'تبويب «أذوناته» بلا أي جدول خام');
chk(substr_count($tabAcc, '<table') === 1,
    'تبويب «الحساب الكامل» فيه **جدول واحد بس** — «عهدة كل يوم»');
chk(str_contains($v('_tab_account.php'), 'مقارنة رقمية بين أيام'),   // في تعليق فالقراءة خام
    '⚠️ والجدول ده **مقصود** وموثَّق: مقارنة رقمية بين أيام، وده اللي الجدول أحسن فيه');
foreach ([['اليوم', $tabDay], ['الحساب', $tabAcc], ['الأذونات', $tabPerm]] as [$name, $src]) {
    chk(str_contains($src, "partial('row_item'"), "تبويب «{$name}» بيستخدم `row_item`");
    chk(str_contains($src, "partial('panel'") || str_contains($src, 'd-card'),
        "تبويب «{$name}» بيستخدم لوح اللغة الجديدة");
}
chk(str_contains($tabDay, 'data-drill') && str_contains($tabDay, 'rc-chip'),
    '⚠️ شرائح أداء اليوم بقت أزرار بتفتح بنودها — مش أرقام صمّاء');

// ═══ ③ صندوق المراجعة
sec('③ صندوق المراجعة — أرقامه بقت تفتح بنودها');
chk(str_contains($inbox, "partial('stat_grid'") && !str_contains($inbox, 'class="kpi-grid'),
    'الأرقام الأربعة بمكوّن `stat`');
chk(substr_count($inbox, "'drill' =>") === 4, 'وكلهم معاهم مصدر تفاصيل');
chk(str_contains($inbox, "inbox_locked"),
    '⚠️ «على أيام متقفلة» بيفتح **البنود اللي اعتمادها هيترفض** بالتحديد');
/* الجدول الوحيد الباقي (المعتمد/المرفوض) سجل بيتفلتر ويتصدّر — أدوات الجداول
   العامة (ترتيب/إخفاء أعمدة/CSV) قيمتها هناك أعلى من الصف. */
chk(substr_count($inbox, '<table') === 1, 'وسجل المعتمد/المرفوض لسه جدول (بيتفرز ويتصدّر)');

// ═══ ④ ⚠️ مفيش استعلام جديد للتفاصيل
sec('④ ⚠️ مفيش تعريف تاني لأي رقم');
chk(str_contains($ctrl, 'RepCustody::spending($repId, $day)')
    && str_contains((string) file_get_contents($root . '/app/Controllers/RepCustodyController.php'),
                    'ما بتكتبش استعلامًا جديدًا'),   // القاعدة في تعليق فالقراءة خام
    'تفاصيل شرائح اليوم بتفلتر `spending()` نفسها اللي العدّاد اتحسب منها');
chk(!preg_match('~private static function repSpendRows[\s\S]{0,900}Database::~', $ctrl),
    'وما فيهاش أي استعلام SQL مباشر');
chk(str_contains($drillV, '$spendRow') && substr_count($drillV, "partial('row_item'") >= 6,
    'كل صفوف النوافذ بمكوّن `row_item` نفسه بتاع الشاشات');

// ═══ ⑤ ⚠️ القائمة البيضا والصلاحيات
sec('⑤ ⚠️ مسارات التفاصيل محصورة');
chk(str_contains($ctrl, 'private const DRILL_ALL')
    && str_contains($ctrl, 'private const DRILL_REP')
    && str_contains($ctrl, 'private const DRILL_INBOX'),
    'تلات قوايم بيضا مقفولة — مش اسم جدول جاي من المستخدم');
chk(str_contains($ctrl, "\$this->notFound('المندوب ده مش موجود.')"),
    '⚠️ تفاصيل مندوب لازم `?rep=` لمندوب موجود فعلاً — الفورم مش مصدر ثقة');
chk(Access::allows('owner', 'RepCustodyController@drill', 'GET'), 'المالك مسموح له');

// ═══ ⑥ رندر فعلي
sec('⑥ الشاشات والنوافذ بترندر فعليًّا');
$repId = (int) (Database::scalar('SELECT MIN(id) FROM reps') ?? 0);
$uid = (int) Database::scalar('SELECT MIN(id) FROM users');
$_SESSION = ['uid' => $uid, 'role' => 'owner', 'uname' => 'فحص',
             'last_activity' => time(), 'uid_checked' => time()];
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

if ($repId <= 0) {
    ok('مفيش مناديب في القاعدة — تخطّي رندر الكارت');
} else {
    foreach (['day', 'permits', 'account', 'settings'] as $tab) {
        $_GET = ['tab' => $tab];
        $html = $render(static fn () => (new RepCustodyController())->card((string) $repId));
        if ($html === '') { bad("تبويب `$tab` ما رندرش"); continue; }
        $noPhpError($html, "تبويب `$tab`");
        chk(!str_contains($html, 'ممنوع رقم أصمّ'), "تبويب `$tab`: مفيش رقم اترفض رندره");
    }
    $_GET = [];
    $repDrills = ['rep_deposits', 'rep_spent', 'rep_pending', 'rep_receipts',
                  'rep_expenses', 'rep_misc', 'rep_handover', 'rep_transfer',
                  'rep_visa', 'rep_unpaid'];
    foreach ($repDrills as $w) {
        $_GET = ['rep' => (string) $repId];
        $out = $render(static fn () => (new RepCustodyController())->drill($w));
        if ($out === '') { bad("نافذة `$w` ما رندرتش"); continue; }
        $noPhpError($out, "نافذة `$w`");
        chk(!str_contains($out, '<html'), "نافذة `$w` بترجع جزءًا مش صفحة");
    }
    $_GET = [];
}
foreach (['inbox_pending', 'inbox_locked', 'inbox_reps'] as $w) {
    $out = $render(static fn () => (new RepCustodyController())->drill($w));
    if ($out === '') { bad("نافذة `$w` ما رندرتش"); continue; }
    $noPhpError($out, "نافذة `$w`");
}

// ═══ ⑦ ⚠️ ألوان الهوية بين النظامين
sec('⑦ ⚠️ ألوان الهوية متطابقة بين المكتب والبوابة');
$appCss = (string) file_get_contents($root . '/public/assets/css/app.css');
$mCss   = (string) file_get_contents($root . '/public/assets/css/m.css');
$grab = static function (string $css, string $name): ?string {
    return preg_match('~--' . preg_quote($name, '~') . '\s*:\s*(#[0-9A-Fa-f]{3,8})~', $css, $m)
        ? strtoupper($m[1]) : null;
};
foreach (['navy', 'gold', 'sader', 'warid', 'green', 'red'] as $tok) {
    $a = $grab($appCss, $tok); $b = $grab($mCss, $tok);
    chk($a !== null && $a === $b, "لون `--$tok` واحد في النظامين (" . ($a ?? '؟') . ')');
}
chk(str_contains($mCss, 'لازم تفضل مطابقة'),
    'والقاعدة مكتوبة في رأس m.css للي هيعدّل فيه بعدين');
chk(str_contains($mCss, 'مختلفة عن قصد'),
    'والثلاثة المختلفة (ink · line · bg) موثَّق إنها مقصودة لشمس الميناء');

// ═══ ⑧ العزل المالي
sec('⑧ ⚠️ العزل المالي — الحد الأحمر');
$treasuryAfter = Closing::currentBalance();
chk(abs($treasuryBefore - $treasuryAfter) < 0.005,
    'رصيد الخزنة قبل = بعد (' . money($treasuryBefore, false) . ')');

echo "\n" . str_repeat('=', 62) . "\n";
echo 'النتيجة: ' . $pass . ' ناجح · ' . count($failures) . " فاشل\n";
if ($failures) { foreach ($failures as $f) { echo "  ✗ $f\n"; } exit(1); }
exit(0);
