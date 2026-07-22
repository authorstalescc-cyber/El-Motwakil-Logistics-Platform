<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص v36 — منع التكرار، أخطاء الحقول، تعديل المعلّق، وقفل المراجعة.
 * كل بيانات الفحص داخل transaction ويُعمل لها rollback.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\RepAssignment;
use App\Models\RepInbox;
use App\Models\RepPortal;
use App\Models\RepSubmissionValidationException;

$pass = 0; $fail = 0;
function v36ok(string $m): void { global $pass; $pass++; echo "  ✓ $m\n"; }
function v36bad(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function v36is(string $m, $actual, $expected): void
{
    $actual == $expected ? v36ok($m) : v36bad($m . ' — فعلي: ' . var_export($actual, true));
}

echo "فحص v36 — موثوقية إدخال المندوب\n" . str_repeat('=', 52) . "\n";

foreach (['client_token', 'review_started_at', 'review_started_by', 'updated_at'] as $column) {
    $exists = (bool) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema=DATABASE() AND table_name='rep_submissions' AND column_name=?",
        [$column]
    );
    $exists ? v36ok("العمود $column موجود") : v36bad("العمود $column ناقص");
}
$index = (bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema=DATABASE() AND table_name='rep_submissions' AND index_name='uq_rep_submission_token'"
);
$index ? v36ok('قيد منع التكرار موجود') : v36bad('قيد منع التكرار ناقص');

if ($fail) { exit(1); }

$rep = Database::row('SELECT id FROM reps ORDER BY id LIMIT 1');
$pm = Database::row("SELECT p.id, m.id mid FROM permits p JOIN permit_movements m ON m.permit_id=p.id WHERE p.status='open' LIMIT 1");
if (!$rep || !$pm) {
    echo "مفيش مندوب/إذن صالح للفحص الحي.\n";
    exit(0);
}

$repId = (int) $rep['id']; $permitId = (int) $pm['id']; $movementId = (int) $pm['mid'];
$pdo = Database::conn();
$pdo->beginTransaction();
try {
    RepAssignment::assign($permitId, $repId, '__v36');
    $token = bin2hex(random_bytes(16));
    $data = [
        'permit_id' => $permitId, 'movement_id' => $movementId, 'kind' => 'receipt',
        'note' => '__v36', 'qty' => 2, 'unit_amount' => '١٬٢٥٠٫٥٠',
        'entry_date' => date('Y-m-d'), 'ref_no' => 'A-10', 'client_token' => $token,
    ];

    $id = RepPortal::submit($repId, $data);
    v36is('الأرقام العربية والفواصل اتطبّعت صح',
        (float) Database::scalar('SELECT amount FROM rep_submissions WHERE id=?', [$id]), 2501.0);

    $same = RepPortal::submit($repId, $data);
    v36is('إعادة نفس المحاولة رجّعت نفس البند', $same, $id);
    v36is('مفيش صف مكرر لنفس الرمز',
        (int) Database::scalar('SELECT COUNT(*) FROM rep_submissions WHERE rep_id=? AND client_token=?', [$repId, $token]), 1);

    $data['unit_amount'] = '900';
    RepPortal::updateMine($repId, $id, $data);
    v36is('التعديل قبل المراجعة مسموح',
        (float) Database::scalar('SELECT amount FROM rep_submissions WHERE id=?', [$id]), 1800.0);

    RepInbox::startReview($id);
    v36is('بدء المراجعة اتسجّل',
        (int) Database::scalar('SELECT review_started_at IS NOT NULL FROM rep_submissions WHERE id=?', [$id]), 1);
    try { RepPortal::updateMine($repId, $id, $data); v36bad('التعديل بعد بدء المراجعة اتقبل'); }
    catch (Throwable $e) { v36ok('التعديل بعد بدء المراجعة اترفض'); }
    try { RepPortal::deleteMine($repId, $id); v36bad('الحذف بعد بدء المراجعة اتقبل'); }
    catch (Throwable $e) { v36ok('الحذف بعد بدء المراجعة اترفض'); }

    $future = $data;
    $future['client_token'] = bin2hex(random_bytes(16));
    $future['entry_date'] = date('Y-m-d', strtotime('+1 day'));
    try { RepPortal::submit($repId, $future); v36bad('تاريخ مستقبل اتقبل'); }
    catch (RepSubmissionValidationException $e) {
        isset($e->errors()['entry_date']) ? v36ok('تاريخ المستقبل رجع خطأ على حقل التاريخ')
                                           : v36bad('خطأ التاريخ مش مربوط بالحقل');
    }
} finally {
    $pdo->rollBack();
    echo "(كل بيانات الفحص اترجعت)\n";
}

echo "نجح: $pass | فشل: $fail\n";
exit($fail ? 1 : 0);
