<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * ترحيل لمرّة واحدة (idempotent): إغلاق كل إذن له مستند مالي صادر غير ملغى.
 * قرار المالك: أي إذن يصدر له فاتورة يجب إغلاقه.
 *
 * الاستخدام:
 *   php bin/close_invoiced_permits.php --dry-run
 *   php bin/close_invoiced_permits.php
 *
 * ⚠️ لا يمسّ أي رقم مالي: يغيّر عمود permits.status فقط، ويتحقّق أن رصيد الخزنة لم يتغيّر.
 */
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Models\Audit;
use App\Models\Closing;

$dry = in_array('--dry-run', $argv, true);

$rows = Database::all(
    "SELECT p.id, p.permit_no, p.permit_type, p.status, COUNT(i.id) inv_cnt
       FROM permits p
       JOIN permit_invoices i ON i.permit_id = p.id AND i.status <> 'void'
      WHERE p.status <> 'closed'
      GROUP BY p.id, p.permit_no, p.permit_type, p.status
      ORDER BY p.id"
);

echo "أذونات لها مستند مالي صادر وما زالت مفتوحة: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo "  - #{$r['id']} " . ($r['permit_no'] ?: '(بلا رقم)') . " — مستندات: {$r['inv_cnt']}\n";
}
if (!$rows) { echo "لا يوجد ما يُغلَق ✓\n"; exit(0); }
if ($dry)   { echo "\n[dry-run] لم يُكتب أي شيء.\n"; exit(0); }

$balanceBefore = Closing::currentBalance();
$ids = array_map(fn($r) => (int)$r['id'], $rows);
$ph  = implode(',', array_fill(0, count($ids), '?'));

$pdo = Database::conn();
$pdo->beginTransaction();
try {
    Database::exec("UPDATE permits SET status='closed' WHERE id IN ($ph)", $ids);
    Audit::log('bulk_update', 'permits', null, ['before' => $rows], ['status' => 'closed'],
        'إغلاق تلقائي لأذونات صدرت لها مستندات مالية (قاعدة: الفاتورة تُغلق الإذن)', ['ids' => $ids]);
    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo "فشل: " . $e->getMessage() . "\n";
    exit(1);
}

$balanceAfter = Closing::currentBalance();
if (abs($balanceBefore - $balanceAfter) > 0.005) {
    echo "⚠️ تحذير: رصيد الخزنة تغيّر ({$balanceBefore} → {$balanceAfter}) — راجع فورًا.\n";
    exit(1);
}

$left = (int) Database::scalar(
    "SELECT COUNT(DISTINCT p.id) FROM permits p
       JOIN permit_invoices i ON i.permit_id=p.id AND i.status<>'void'
      WHERE p.status<>'closed'"
);
echo "\nتم إغلاق " . count($ids) . " إذن ✓ | متبقٍ مفتوح بمستند: {$left} | رصيد الخزنة كما هو ({$balanceAfter}) ✓\n";
exit($left === 0 ? 0 : 1);
