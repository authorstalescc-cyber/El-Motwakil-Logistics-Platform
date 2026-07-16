<?php
/**
 * تصحيح حالة البوالص القديمة التي لا تحمل نولونًا ولا دفعة.
 * يغيّر pay_status فقط إلى pending؛ لا يغيّر الدين أو العهدة أو المدفوع أو الباقي.
 * آمن لإعادة التشغيل (idempotent).
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

$rows = Database::all(
    "SELECT id, bill_number, freight, paid, debt, remaining
       FROM bawalis
      WHERE is_legacy=1 AND freight<=0.005 AND paid<=0.005 AND pay_status<>'pending'
      ORDER BY id"
);

if (!$rows) {
    echo "✓ لا توجد حالات تحتاج إلى تصحيح.\n";
    exit(0);
}

$before = Database::row(
    "SELECT COALESCE(SUM(debt),0) debt, COALESCE(SUM(paid),0) paid,
            COALESCE(SUM(remaining),0) remaining, COALESCE(SUM(custody),0) custody
       FROM bawalis WHERE is_legacy=1"
);

$pdo = Database::conn();
$pdo->beginTransaction();
try {
    $changed = Database::exec(
        "UPDATE bawalis SET pay_status='pending'
          WHERE is_legacy=1 AND freight<=0.005 AND paid<=0.005 AND pay_status<>'pending'"
    );
    $after = Database::row(
        "SELECT COALESCE(SUM(debt),0) debt, COALESCE(SUM(paid),0) paid,
                COALESCE(SUM(remaining),0) remaining, COALESCE(SUM(custody),0) custody
           FROM bawalis WHERE is_legacy=1"
    );
    foreach (['debt','paid','remaining','custody'] as $key) {
        if (abs((float)$before[$key] - (float)$after[$key]) > 0.005) {
            throw new RuntimeException("تغيّر إجمالي $key بصورة غير متوقعة.");
        }
    }
    if ($changed !== count($rows)) {
        throw new RuntimeException('عدد الصفوف المصححة لا يطابق عدد الحالات المقروءة.');
    }
    $pdo->commit();

    echo '✓ صُححت ' . $changed . " بوليصة إلى «بانتظار النولون»: \n";
    foreach ($rows as $r) {
        echo '  - ' . ($r['bill_number'] ?: '#'.$r['id']) . "\n";
    }
    echo "✓ لم تتغير أي قيمة مالية أو عهدة.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    fwrite(STDERR, '✗ فشل التصحيح وتراجعت المعاملة كاملة: ' . $e->getMessage() . "\n");
    exit(1);
}
