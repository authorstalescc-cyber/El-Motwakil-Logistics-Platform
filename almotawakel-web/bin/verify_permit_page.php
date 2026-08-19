<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص **شارة المندوب على البنود** (v103، وباقية بعد رجوع التصميم القديم v107).
 *
 * طلب المالك بالحرف: «في البنود يجب أن يكون بجانبها badge للمندوب الذي قام
 * بإدخال هذا البند».
 *
 * ⚠️ **الطلب ده مستقل عن شكل الصفحة**: التصميم رجع للقديم بطلب المالك
 *    (2026-08-18) والشارة فضلت — عشان كده الفحص ده **مش بيفحص أي تخطيط**،
 *    بيفحص المعلومة نفسها بس.
 *
 * المحروس:
 *   ① الشارة من **مكوّن واحد** بيخدم وضع العرض ووضع التعديل ونافذة النظرة العامة.
 *   ② **بند المكتب مالوش شارة** — الغياب معلومة، والشارة على كل سطر ضجيج.
 *   ③ الاسم جاي من **مصدر قراءة واحد** للبنود (`Permit::STAGE_SELECT`) —
 *      مش استعلام لكل سطر في الشاشة.
 *   ④ عرض بحت: ولا استعلام كتابة، ورصيد الخزنة قبل = بعد.
 *
 * fixtures جوّه transaction بترجع بالكامل.
 * التشغيل:  php bin/verify_permit_page.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\PermitController;
use App\Core\Database;
use App\Models\Closing;
use App\Models\Permit;

$pass = 0; $failures = [];
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $failures; $failures[] = $m; echo "  ✗ $m\n"; }
function chk(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function sec(string $m): void { echo "\n$m\n" . str_repeat('─', 64) . "\n"; }

/** التعليق بيوثّق الممنوع نفسه — لازم يتشال قبل أي فحص نصّي (مصيدة `code_only`). */
function php_code(string $s): string {
    $s = preg_replace('~/\*.*?\*/~s', '', $s) ?? $s;
    return preg_replace('~^\s*(//|\*|#).*$~m', '', $s) ?? $s;
}

echo "فحص شارة المندوب على البنود\n" . str_repeat('=', 64) . "\n";

$root  = dirname(__DIR__);
$view  = php_code((string) file_get_contents($root . '/app/Views/permits/_body_view.php'));
$mvV   = php_code((string) file_get_contents($root . '/app/Views/permits/_movement.php'));
$ovV   = php_code((string) file_get_contents($root . '/app/Views/permits/_overview.php'));
$badge = (string) file_get_contents($root . '/app/Views/partials/rep_badge.php');
$model = php_code((string) file_get_contents($root . '/app/Models/Permit.php'));
$css   = (string) file_get_contents($root . '/public/assets/css/app.css');

$balanceBefore = Closing::currentBalance();

// ═══════════════════════════════════════════════════════════════════════
sec('① مكوّن واحد لكل الشاشات');

chk(is_file($root . '/app/Views/partials/rep_badge.php'), 'المكوّن موجود في مكتبة المكوّنات');
chk(str_contains($badge, 'rep-badge') && str_contains($css, '.rep-badge{'),
    'وله CSS — الكلاس اللي بلا شكل بيقع على الافتراضي بالسكوت');
chk(preg_match('~if\s*\(\s*\$__rb_name\s*===\s*\'\'\s*\)\s*\{\s*return~', $badge) === 1,
    '⚠️ وبند المكتب (بلا مندوب) الشارة بتختفي عنده — الغياب معلومة مش فراغ');
foreach (['_body_view.php' => $view, '_movement.php' => $mvV, '_overview.php' => $ovV] as $f => $src) {
    chk(str_contains($src, "partial('rep_badge'"), "و`{$f}` بينادي المكوّن مش بيرسم شارة بإيده");
}
chk(!str_contains($view, 'rep-badge') && !str_contains($mvV, 'rep-badge'),
    'ومفيش ماركب شارة مكتوب بالإيد في أي شاشة');

// ═══════════════════════════════════════════════════════════════════════
sec('② الاسم من مصدر قراءة واحد');

chk(str_contains($model, 'STAGE_SELECT') && str_contains($model, 'LEFT JOIN reps r ON r.id = s.paid_by_rep_id'),
    '⚠️ `rep_name` جاي مع البند نفسه من `Permit::STAGE_SELECT`');
$viewsWithRepQuery = [];
foreach (glob($root . '/app/Views/permits/*.php') ?: [] as $f) {
    if (preg_match('~FROM\s+reps|Rep::(all|find)~i', php_code((string) file_get_contents($f)))) {
        $viewsWithRepQuery[] = basename($f);
    }
}
chk($viewsWithRepQuery === [], 'ومفيش استعلام أسماء مناديب جوّه أي شاشة'
    . ($viewsWithRepQuery ? ' — في: ' . implode(', ', $viewsWithRepQuery) : ''));

// ═══════════════════════════════════════════════════════════════════════
sec('③ ⚠️ السلوك: بند بمندوب معاه شارة، وبند المكتب لأ');

$pid = (int) (Database::scalar('SELECT permit_id FROM permit_movements ORDER BY id DESC LIMIT 1') ?? 0);
$rid = (int) (Database::scalar("SELECT id FROM reps WHERE status = 'active' LIMIT 1") ?? 0);

if ($pid <= 0 || $rid <= 0) {
    echo "  ⓘ مفيش إذن بحركات أو مندوب نشط — فحص السلوك اتخطّى\n";
} else {
    $mid = (int) Database::scalar('SELECT id FROM permit_movements WHERE permit_id = ? ORDER BY id LIMIT 1', [$pid]);
    $uid = (int) Database::scalar('SELECT MIN(id) FROM users');
    $pdo = Database::conn();
    $pdo->beginTransaction();
    try {
        Database::exec(
            "INSERT INTO permit_stages (permit_id, movement_id, stage_key, amount, qty, entry_date,
                                        note, status, paid_by_rep_id)
             VALUES (?, ?, 'receipts', 321, 1, ?, 'بند فحص الشاشة', 'done', ?)",
            [$pid, $mid, work_date(), $rid]);
        Database::exec(
            "INSERT INTO permit_stages (permit_id, movement_id, stage_key, amount, qty, entry_date,
                                        note, status)
             VALUES (?, ?, 'receipts', 123, 1, ?, 'بند فحص المكتب', 'done')",
            [$pid, $mid, work_date()]);

        $_SESSION = ['uid' => $uid, 'role' => 'owner', 'uname' => 'فحص',
                     'last_activity' => time(), 'uid_checked' => time()];
        $_GET = [];
        ob_start();
        try { (new PermitController())->view((string) $pid); $html = (string) ob_get_clean(); }
        catch (\Throwable $e) { ob_end_clean(); $html = 'EXCEPTION: ' . $e->getMessage(); }

        chk(!str_starts_with($html, 'EXCEPTION:') &&
            !preg_match('~Fatal error|Warning:|Undefined variable|Undefined array key~', $html),
            'الشاشة بترندر بلا أي خطأ PHP');
        chk(str_contains($html, 'بند فحص الشاشة') && str_contains($html, 'بند فحص المكتب'),
            'والبندان المزروعان ظاهران');

        $repName = (string) Database::scalar('SELECT name FROM reps WHERE id = ?', [$rid]);
        /* ⚠️ **الفحص على السطر نفسه** مش على الصفحة كلها: وجود الاسم في أي
           مكان مش دليل إنه جنب البند الصح. */
        $rowRep    = preg_match('~بند فحص الشاشة(?:(?!</tr>).){0,700}rep-badge~su', $html) === 1;
        $rowOffice = preg_match('~بند فحص المكتب(?:(?!</tr>).){0,700}rep-badge~su', $html) === 1;
        chk($rowRep, '⚠️ **بند المندوب معاه شارة في سطره** — طلب المالك المباشر');
        chk(!$rowOffice, 'وبند المكتب **مالوش شارة** — الغياب معلومة');
        chk($repName === '' || str_contains($html, $repName) || str_contains($html, explode(' ', $repName)[0]),
            'واسم المندوب مكتوب فعلاً (مش «مندوب» عامة)');

        $dbSum = (float) Database::scalar(
            "SELECT COALESCE(SUM(amount),0) FROM permit_stages
              WHERE movement_id = ? AND stage_key = 'receipts' AND status <> 'none'", [$mid]);
        $shown = 0.0;
        foreach (Permit::movementStagesGrouped($mid)['receipts'] ?? [] as $r) {
            if (($r['status'] ?? '') !== 'none') { $shown += (float) $r['amount']; }
        }
        chk(abs($dbSum - $shown) < 0.01,
            'ومجموع بنود الحركة في الشاشة = مجموعها في القاعدة (' . number_format($dbSum, 2) . ')');
    } finally {
        $pdo->rollBack();
    }
    chk((int) Database::scalar("SELECT COUNT(*) FROM permit_stages WHERE note LIKE 'بند فحص%'") === 0,
        'والزرع اترجع بالكامل — الفحص ما بيسيبش أثر');
}

// ═══════════════════════════════════════════════════════════════════════
sec('④ العزل — الشاشة عرض بحت');

chk(!preg_match('~\b(INSERT|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b~i', $view),
    'مفيش استعلام كتابة في جسم العرض');
$balanceAfter = Closing::currentBalance();
chk(abs($balanceBefore - $balanceAfter) < 0.001,
    'ورصيد خزنة المكتب قبل = بعد (' . number_format($balanceAfter, 2) . ') — الحد الأحمر');

// ═══════════════════════════════════════════════════════════════════════
echo "\n" . str_repeat('=', 64) . "\n";
echo 'نجح: ' . $pass . '   |   فشل: ' . count($failures) . "\n";
if ($failures) {
    echo "\n✗ الفحص فشل — الكود ده ما يترفعش.\n";
    foreach ($failures as $f) { echo "   · $f\n"; }
    exit(1);
}
echo "\n✓ كل الفحوصات عدّت.\n";
