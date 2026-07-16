<?php
/** اختبار آمن داخل Transaction: إذن قديم + بوليصة جديدة بلا تاريخ مُرسل. */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\PermitController;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Models\Audit;

Auth::start();
$user = Database::row('SELECT id, COALESCE(NULLIF(display_name,""),username) name FROM users ORDER BY id LIMIT 1');
if (!$user) { throw new RuntimeException('لا يوجد مستخدم للاختبار.'); }
$_SESSION['uid'] = (int)$user['id'];
$_SESSION['uname'] = 'اختبار سجل الإدخالات';
$_SESSION['work_date'] = date('Y-m-d');

$permit = Database::row(
    'SELECT p.*, (SELECT id FROM permit_movements WHERE permit_id=p.id ORDER BY seq,id LIMIT 1) movement_id
     FROM permits p WHERE p.permit_date < ? ORDER BY p.permit_date DESC,p.id DESC LIMIT 1',
    [Auth::workDate()]
);
if (!$permit || !$permit['movement_id']) { throw new RuntimeException('لا يوجد إذن قديم بحركة لاختبار السيناريو.'); }

$billNo = '__V29_TEST_' . bin2hex(random_bytes(5));
$pdo = Database::conn();
$pdo->beginTransaction();
try {
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'xmlhttprequest';
    $_POST = [
        '_csrf' => Auth::csrf(),
        'movement_id' => (string)$permit['movement_id'],
        'bill_date' => '', // جوهر الاختبار: الخادم يجب أن يستخدم تاريخ العمل، لا تاريخ الإذن.
        'bill_number' => $billNo,
        'container_count' => '0',
        'discount' => '0',
        'freight' => '0', 'custody' => '0', 'transfer' => '0', 'overnight_collection' => '0',
    ];

    ob_start();
    (new PermitController())->createBill((string)$permit['id']);
    $response = ob_get_clean();

    $bill = Database::row('SELECT * FROM bawalis WHERE bill_number=?', [$billNo]);
    if (!$bill || $bill['bill_date'] !== Auth::workDate()) {
        throw new RuntimeException('فشل: البوليصة الجديدة لم تأخذ تاريخ العمل.');
    }
    if ($bill['bill_date'] === $permit['permit_date']) {
        throw new RuntimeException('فشل: البوليصة ورثت تاريخ الإذن القديم.');
    }

    $log = Database::row('SELECT * FROM audit_logs WHERE entity="bawalis" AND record_id=? ORDER BY id DESC LIMIT 1', [(string)$bill['id']]);
    if (!$log || $log['action'] !== 'create' || $log['work_date'] !== Auth::workDate() || $log['record_date'] !== Auth::workDate()) {
        throw new RuntimeException('فشل: سجل الإدخال لم يحفظ تاريخ العمل وتاريخ المستند بصورة صحيحة.');
    }
    if (!str_contains($response, '"ok":true') || !str_contains($response, 'name=\"bill_date\" value=\"'.Auth::workDate().'\"')) {
        throw new RuntimeException('فشل: رد شاشة الإذن لا يعرض تاريخ العمل كبداية للبوليصة التالية.');
    }

    $entries = Audit::recent(10, 'bawalis', 'create', Auth::workDate(), Auth::workDate());
    if (!array_filter($entries, fn($row)=>(string)$row['record_id']===(string)$bill['id'])) {
        throw new RuntimeException('فشل: الإدخال لا يظهر في استعلام سجل الإدخالات.');
    }

    // تحقق بصري نصّي من أن اختلاف التاريخ يُعلَّم بتحذير واضح في الصفحة.
    Audit::log('create', 'manual_expenses', 'v29-alert', null,
        ['exp_date'=>$permit['permit_date'], 'amount'=>1], 'اختبار تنبيه اختلاف التاريخ');
    $allEntries = Audit::recent(20, '', 'create');
    ob_start();
    (new Controller())->render('audit/index', [
        'title'=>'سجل الإدخالات', 'logs'=>$allEntries, 'trash'=>[], 'mode'=>'entries',
        'entity'=>'', 'action'=>'create', 'from'=>'', 'to'=>'',
    ], false);
    $entryHtml = ob_get_clean();
    if (!str_contains($entryHtml, 'تاريخ مختلف') || !str_contains($entryHtml, 'date-alert')) {
        throw new RuntimeException('فشل: شاشة سجل الإدخالات لم تبرز اختلاف التاريخ.');
    }

    $pdo->rollBack();
    echo "✓ إذن قديم: {$permit['permit_no']} ({$permit['permit_date']})\n";
    echo '✓ تاريخ العمل والبوليصة الجديدة: ' . Auth::workDate() . "\n";
    echo "✓ سجل الإدخالات حفظ التاريخين وعرض السجل بنجاح\n";
    echo "✓ اختلاف التاريخ يظهر بتحذير واضح في الصفحة\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    throw $e;
}
