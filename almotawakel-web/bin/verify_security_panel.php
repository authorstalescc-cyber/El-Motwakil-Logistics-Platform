<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص لوحة المراقبة الأمنية `/security`.
 *
 * أهم 4 حاجات بيتأكد منها:
 *   1) **للمالك بس** — والمنع مفروض في `Access` (الراوتر) مش في إخفاء الرابط.
 *   2) **قراءة فقط** — مفيش أي INSERT/UPDATE/DELETE في الموديل ولا فورم في الشاشة.
 *   3) **ربط البصمات شغّال** — اللوحة بتعرف الحساب المستهدَف من الهاش المتشفّر
 *      (ده جوهر فايدتها: من غيره بتعرض هاشات مالهاش معنى).
 *   4) **العزل** — مفيش أي عمود مالي ورصيد الخزنة ما بيتغيّرش.
 *
 * آمن: كل حاجة جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 *
 * التشغيل:  php bin/verify_security_panel.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Access;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\Database;
use App\Core\RepAuth;
use App\Models\Closing;
use App\Models\SecurityMonitor;

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function sec(string $m): void { echo "\n" . $m . "\n" . str_repeat('─', 60) . "\n"; }
function is_(string $l, $a, $e): void {
    $a == $e ? ok($l) : bad("$l — متوقع: " . var_export($e, true) . " / فعلي: " . var_export($a, true));
}

echo "فحص لوحة المراقبة الأمنية\n" . str_repeat('=', 60) . "\n";

$root = dirname(__DIR__);
$balanceBefore = Closing::currentBalance();

// ═══ 1) الصلاحية ═════════════════════════════════════════════════════════
sec('1) للمالك بس — مفروض في الراوتر مش في الواجهة');
foreach (['manager', 'accountant', 'operator', 'read_only'] as $role) {
    is_("دور «{$role}» ممنوع من GET /security",
        Access::allows($role, 'SecurityController@index', 'GET'), false);
}
is_('المالك مسموح', Access::allows('owner', 'SecurityController@index', 'GET'), true);

$acc = (string) file_get_contents("$root/app/Core/Access.php");
(str_contains($acc, "'SecurityController@*'"))
    ? ok('مسجَّلة في OWNER_ONLY')
    : bad('مش مسجَّلة في OWNER_ONLY — أي دور هيقدر يقراها');

$ctl = (string) file_get_contents("$root/app/Controllers/SecurityController.php");
(str_contains($ctl, "requireRole('owner')"))
    ? ok('وفيه حارس تاني في الكنترولر')
    : bad('مفيش حارس في الكنترولر');

// ═══ 2) قراءة فقط ════════════════════════════════════════════════════════
sec('2) قراءة فقط — مفيش أي كتابة');
$model = (string) file_get_contents("$root/app/Models/SecurityMonitor.php");
$code  = '';
foreach (token_get_all($model) as $t) {
    if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
    $code .= is_array($t) ? $t[1] : $t;
}
foreach (['INSERT', 'UPDATE ', 'DELETE', 'DROP', 'TRUNCATE', 'ALTER'] as $w) {
    (stripos($code, $w) === false)
        ? ok("مفيش «" . trim($w) . "» في الموديل")
        : bad("فيه «" . trim($w) . "» في الموديل — اللوحة المفروض قراءة فقط");
}
$view = (string) file_get_contents("$root/app/Views/security/index.php");
(stripos($view, '<form') === false)
    ? ok('مفيش أي فورم في الشاشة (كل إجراء من مكانه الأصلي)')
    : bad('فيه فورم في الشاشة — ده بيفتح مسار كتابة جديد');

// ═══ 3) العزل المالي ═════════════════════════════════════════════════════
sec('3) العزل — مفيش أي عمود مالي');
// ⚠️ لاحظ `{$w}` مش `$w` — مصيدة PHP الموثَّقة في v38: البايتات «» بتتحسب
//    جزءًا من اسم المتغيّر فيطلع «Undefined variable $w»».
foreach (['amount', 'balance', 'custody', 'permit_stages', 'manual_expenses', 'bill_payments'] as $w) {
    (stripos($code, $w) === false)
        ? ok("مفيش «{$w}»")
        : bad("فيه «{$w}» — اللوحة مالهاش دعوة بأي حساب");
}

// ═══ 4) ربط البصمات — جوهر اللوحة ════════════════════════════════════════
Database::exec('START TRANSACTION');
$exit = 0;
try {
    sec('4) ربط البصمات بالحسابات (جوهر فايدة اللوحة)');

    $user = Database::row('SELECT username, display_name FROM users ORDER BY id LIMIT 1');
    if (!$user) { throw new RuntimeException('مفيش مستخدمين للاختبار'); }

    Auth::attempt((string) $user['username'], '__كلمة_سر_غلط_للفحص__');
    $d = SecurityMonitor::overview(24);

    $office = array_values(array_filter($d['targeted'], static fn($t) => $t['kind'] === 'office'));
    (count($office) > 0)
        ? ok('محاولة فاشلة على حساب مكتب اتربطت باسمه: ' . ($office[0]['label'] ?? ''))
        : bad('اللوحة ما عرفتش تربط البصمة بالحساب — هتعرض هاشات بلا معنى');

    // اسم مستخدم مش موجود لازم يتعلّم «تخمين أسماء»
    Auth::attempt('__حساب_مش_موجود_خالص__', 'x');
    $d2 = SecurityMonitor::overview(24);
    $unknown = array_filter($d2['targeted'], static fn($t) => $t['kind'] === 'unknown');
    (count($unknown) > 0)
        ? ok('محاولة باسم مش موجود بتتعلّم «حد بيخمّن أسماء»')
        : bad('الاسم المش موجود ما اتفرقش عن الحساب الحقيقي');

    // بصمة المندوب لازم تطابق اللي `RepAuth::record` بيكتبه (بادئة rep|)
    $rep = Database::row('SELECT username FROM rep_users LIMIT 1');
    if ($rep) {
        $expected = hash('sha256',
            (string) (App\Core\App::config('session_name') ?? 'almotawakel')
            . '|rep|' . mb_strtolower(trim((string) $rep['username']), 'UTF-8'));
        is_('بصمة المندوب مطابقة لصيغة التخزين',
            RepAuth::usernameHash((string) $rep['username']), $expected);
    }

    // ═══ 5) القفل والأرقام الضعيفة ═══════════════════════════════════════
    sec('5) الحسابات المقفولة والأرقام الضعيفة');
    $before = count($d['locked']);
    Database::exec(
        'UPDATE users SET locked_until = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id = (SELECT id FROM (SELECT MIN(id) AS id FROM users) x)');
    $d3 = SecurityMonitor::overview(24);
    is_('الحساب المقفول ظهر في اللوحة', count($d3['locked']), $before + 1);

    // ⚠️ القفل بيتقارن جوّه SQL (قاعدة v48) — لو اتقارن في PHP الفحص ده بيفشل
    //    على الإنتاج بسبب فرق التوقيت.
    (str_contains($code, '> NOW()'))
        ? ok('مقارنة القفل جوّه SQL مش بوقت PHP')
        : bad('مقارنة القفل في PHP — هتغلط 3 ساعات على الإنتاج');

    if (RepAuth::pinMetaReady()) {
        $weak = (int) Database::scalar(
            "SELECT COUNT(*) FROM rep_users WHERE weak_pin = 1 AND status = 'active'");
        is_('عدد الأرقام السرية الضعيفة مطابق للقاعدة', count($d3['weakPins']), $weak);
    }

    // ═══ 6) تصحيح الساعة في العرض ════════════════════════════════════════
    sec('6) تصحيح فرق ساعة القاعدة في العرض');
    $off = Clock::dbOffsetMinutes();
    ok("فرق الساعة المقيس: {$off} دقيقة");
    $expr = Clock::displayExpr('attempted_at');
    ($off === 0)
        ? is_('مفيش فرق فالتعبير يفضل زي ما هو', $expr, 'attempted_at')
        : (str_contains($expr, 'DATE_ADD')
            ? ok('العرض بيتظبط على ساعة المستخدم')
            : bad('الفرق موجود والعرض مش بيتظبط'));
    (str_contains($code, 'Clock::displayExpr'))
        ? ok('الموديل بيستخدم تصحيح الساعة في العرض')
        : bad('الموديل بيعرض وقت القاعدة الخام');

    // ═══ 6ب) الصفحة بترندر فعلاً ═════════════════════════════════════════
    // ⚠️ الفحص ده بيمسك فشلًا **صامتًا** اتصادف فعليًا: `Controller::render`
    //    بتعمل `extract($data, EXTR_SKIP)` واسم معاملها نفسه `$data` — فمفتاح
    //    اسمه `data` بيتخطّى بصمت والصفحة تعرض «السجل مش متفعّل» وهو متفعّل.
    //    مفيش خطأ PHP بيظهر، فالفحص البنيوي لوحده ما كانش هيمسكها.
    sec('6ب) الصفحة بترندر بيانات حقيقية مش رسالة «مش متفعّل»');
    $_SESSION['uid'] = (int) Database::scalar('SELECT MIN(id) FROM users');
    $_SESSION['role'] = 'owner';
    $_SESSION['uname'] = 'فحص';
    $_SESSION['last_activity'] = time();
    $_SESSION['uid_checked'] = time();
    ob_start();
    (new App\Controllers\SecurityController())->index();
    $html = (string) ob_get_clean();
    $_SESSION = [];

    (!preg_match('/Fatal error|Warning:|Undefined variable/', $html))
        ? ok('الصفحة بترندر بلا أخطاء PHP')
        : bad('فيه أخطاء PHP في الصفحة');
    (!str_contains($html, 'مش متفعّل'))
        ? ok('بتعرض البيانات مش رسالة «السجل مش متفعّل»')
        : bad('بتعرض «مش متفعّل» رغم إن السجل شغّال — غالبًا مفتاح render اتخطّى');
    foreach (['محاولة دخول فاشلة', 'حسابات اتحاول الدخول', 'مصادر المحاولات', 'آخر دخولات ناجحة'] as $needle) {
        (str_contains($html, $needle))
            ? ok("القسم موجود: {$needle}")
            : bad("القسم ناقص: {$needle}");
    }

} catch (\Throwable $e) {
    bad('استثناء: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    $exit = 1;
} finally {
    Database::exec('ROLLBACK');
    echo "\n(اترجع كل شيء — الفحص ما سابش أي أثر)\n";
}

// ═══ 7) الخزنة ما اتغيّرتش ═══════════════════════════════════════════════
sec('7) العزل النهائي');
$balanceAfter = Closing::currentBalance();
(abs($balanceBefore - $balanceAfter) < 0.0001)
    ? ok('رصيد الخزنة قبل = بعد')
    : bad("رصيد الخزنة اتغيّر: $balanceBefore → $balanceAfter");

echo "\n" . str_repeat('=', 60) . "\n";
echo "نجح: $pass | فشل: $fail\n";
exit($fail > 0 ? 1 : $exit);
