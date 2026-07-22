<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** تحقق قراءة فقط من سلامة دمج البوالص القديمة وعزلها. */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Controllers\BawalisController;
use App\Models\Bill;
use App\Models\Closing;
use App\Models\Custody;
use App\Models\Debt;
use App\Models\Metrics;

$errors = [];
$check = function (bool $ok, string $label, string $detail = '') use (&$errors): void {
    echo ($ok ? '✓ ' : '✗ ') . $label . ($detail !== '' ? " — $detail" : '') . PHP_EOL;
    if (!$ok) { $errors[] = $label; }
};

$source = (int)Database::scalar('SELECT COUNT(*) FROM legacy_bawalis');
$merged = (int)Database::scalar('SELECT COUNT(*) FROM bawalis WHERE is_legacy=1 AND legacy_id IS NOT NULL');
$manual = (int)Database::scalar('SELECT COUNT(*) FROM bawalis WHERE is_legacy=1 AND legacy_id IS NULL');
$live   = (int)Database::scalar('SELECT COUNT(*) FROM bawalis WHERE is_legacy=0');
$check($source === $merged, 'عدد البوالص القديمة المُرحّلة يطابق مصدر الأرشيف', "$merged / $source");
$check(true, 'بوالص قديمة مضافة يدويًا (خارج عدّ المصدر)', (string)$manual);
$check($live > 0, 'البوالص الحية ما زالت موجودة', (string)$live);

$missing = (int)Database::scalar(
    'SELECT COUNT(*) FROM legacy_bawalis l
      LEFT JOIN bawalis b ON b.is_legacy=1 AND b.legacy_id=l.src_row
     WHERE b.id IS NULL'
);
$check($missing === 0, 'كل صف Excel له بوليصة واحدة مرحّلة', (string)$missing);

$linked = (int)Database::scalar(
    'SELECT COUNT(*) FROM bawalis WHERE is_legacy=1 AND (permit_id IS NOT NULL OR movement_id IS NOT NULL)'
);
$check($linked === 0, 'لا بوليصة قديمة مرتبطة بإذن أو حركة', (string)$linked);

$movements = (int)Database::scalar(
    'SELECT COUNT(*) FROM movements m JOIN bawalis b ON b.id=m.bill_id WHERE b.is_legacy=1'
);
$check($movements === 0, 'لم تُنشأ حركات للبوالص القديمة', (string)$movements);

$collisions = (int)Database::scalar(
    "SELECT COUNT(*) FROM bawalis old_b
      JOIN bawalis live_b ON live_b.is_legacy=0 AND old_b.is_legacy=1
       AND TRIM(live_b.bill_number)=TRIM(old_b.bill_number)
     WHERE old_b.bill_number IS NOT NULL AND TRIM(old_b.bill_number)<>''"
);
$check($collisions === 0, 'لا تداخل أرقام بين القديم والحي', (string)$collisions);

$actual = Database::row(
    'SELECT COALESCE(SUM(debt),0) debt, COALESCE(SUM(paid),0) paid,
            COALESCE(SUM(remaining),0) remaining FROM bawalis WHERE is_legacy=1'
);
$expected = Database::row(
    'SELECT COALESCE(SUM(remaining),0) debt, COALESCE(SUM(payment),0) paid,
            COALESCE(SUM(remaining-payment),0) remaining FROM legacy_bawalis'
);

// بعد الدمج البوالص القديمة قابلة للتعديل عمدًا؛ فلا يصح أن يعتبر الفاحص
// تغيير نولون/صاحب بيان لاحق تلفًا. نطابق بالمصدر فقط ما لم يُعدَّل، ونختبر
// الاتساق المالي للجميع. updated_at=created_at هو وسم الصف المستورد الذي لم يُلمس.
$pristineMismatch = (int) Database::scalar(
    'SELECT COUNT(*) FROM bawalis b
       JOIN legacy_bawalis l ON l.src_row=b.legacy_id
      WHERE b.is_legacy=1 AND b.updated_at=b.created_at
        AND (ABS(b.freight-l.freight)>0.005 OR ABS(b.debt-l.remaining)>0.005
          OR ABS(b.paid-l.payment)>0.005 OR ABS(b.remaining-(l.remaining-l.payment))>0.005)'
);
$check($pristineMismatch === 0, 'البوالص القديمة غير المعدّلة تطابق الأرشيف', (string)$pristineMismatch);

$editedLegacy = (int) Database::scalar(
    'SELECT COUNT(*) FROM bawalis WHERE is_legacy=1 AND updated_at<>created_at'
);
$check(true, 'البوالص القديمة المعدّلة لاحقًا (مسموح)', (string)$editedLegacy);

$financialMismatch = (int) Database::scalar(
    'SELECT COUNT(*) FROM bawalis b
      WHERE b.is_legacy=1 AND (
        ABS(b.paid-COALESCE((SELECT SUM(p.amount) FROM bill_payments p WHERE p.bill_id=b.id),0))>0.005
        OR ABS(b.remaining-(b.debt-b.paid))>0.005
      )'
);
$check($financialMismatch === 0, 'اتساق الدين/المدفوع/الباقي بعد التعديل', (string)$financialMismatch);

$wrongPending = (int) Database::scalar(
    "SELECT COUNT(*) FROM bawalis
      WHERE is_legacy=1 AND freight<=0.005 AND paid<=0.005 AND pay_status<>'pending'"
);
$check($wrongPending === 0, 'البوالص القديمة بلا نولون أو دفعة تظهر بانتظار النولون', (string)$wrongPending);

$payCount = (int)Database::scalar(
    'SELECT COUNT(*) FROM bill_payments p JOIN bawalis b ON b.id=p.bill_id
      WHERE p.is_legacy=1 AND b.is_legacy=1 AND b.legacy_id IS NOT NULL'
);
$sourcePayCount = (int)Database::scalar('SELECT COUNT(*) FROM legacy_bawalis WHERE ABS(payment)>0.005');
$paySum = (float)Database::scalar(
    'SELECT COALESCE(SUM(p.amount),0) FROM bill_payments p JOIN bawalis b ON b.id=p.bill_id
      WHERE p.is_legacy=1 AND b.is_legacy=1 AND b.legacy_id IS NOT NULL'
);
$check($payCount === $sourcePayCount, 'عدد الدفعات التاريخية مطابق', "$payCount / $sourcePayCount");
$check(abs($paySum - (float)$expected['paid']) < 0.01, 'مجموع الدفعات التاريخية مطابق', (string)$paySum);

// تشغيل أهم مجاميع العرض فعليًا يكشف أي خطأ SQL في فلاتر is_legacy قبل تسليم النظام.
$smoke = [
    'Metrics::totals'                 => fn() => Metrics::totals(),
    'Metrics::counters'               => fn() => Metrics::counters(),
    'Metrics::permitsSummary'         => fn() => Metrics::permitsSummary(),
    'Metrics::readyToInvoice'         => fn() => Metrics::readyToInvoice(),
    'Metrics::openPermitsList'        => fn() => Metrics::openPermitsList(),
    'Metrics::monthly'                => fn() => Metrics::monthly(2026),
    'Metrics::byCompany'              => fn() => Metrics::byCompany(),
    'Metrics::clientDebts'            => fn() => Metrics::clientDebts(),
    'Metrics::carPerformance'         => fn() => Metrics::carPerformance(),
    'Metrics::customerProfit'         => fn() => Metrics::customerProfit(),
    'Metrics::paymentsReport'         => fn() => Metrics::paymentsReport(),
    'Metrics::recentActivity'         => fn() => Metrics::recentActivity(),
    'Metrics::activityTrend'          => fn() => Metrics::activityTrend(),
    'Debt::ownersSummary (مقصود قديم)' => fn() => Debt::ownersSummary(),
    'Closing::currentBalance'         => fn() => Closing::currentBalance(),
    'Custody::ledger'                 => fn() => Custody::ledger(),
];
foreach ($smoke as $label => $fn) {
    try { $fn(); $check(true, $label); }
    catch (Throwable $e) { $check(false, $label, $e->getMessage()); }
}

// بروفة تعديل حقيقية داخل معاملة تُلغى: التاريخ القديم لا يمنع التعديل، ولا تُنشأ حركة، ولا يتغير الرصيد.
$probeBill = Database::row('SELECT * FROM bawalis WHERE is_legacy=1 ORDER BY id LIMIT 1');
if ($probeBill) {
    $pdo = Database::conn();
    $pdo->beginTransaction();
    try {
        $controller = new class extends BawalisController {
            public function probeValidate(array $data, int $id): ?string { return $this->validate($data, $id); }
            public function probeAfterSave(int $id, array $data): void { $this->afterSave($id, $data, false); }
        };
        $probeData = [
            'bill_date' => $probeBill['bill_date'], 'bill_number' => $probeBill['bill_number'],
            'custody' => $probeBill['custody'], 'container_count' => $probeBill['container_count'],
            'freight' => $probeBill['freight'], 'transfer' => $probeBill['transfer'],
            'discount' => $probeBill['discount'], 'overnight_collection' => $probeBill['overnight_collection'],
        ];
        $validation = $controller->probeValidate($probeData, (int)$probeBill['id']);
        $check($validation === null, 'تعديل البوليصة القديمة يتجاوز قفل اليوم بأمان', $validation ?? 'مسموح');

        $balanceBeforeProbe = Closing::currentBalance();
        Database::exec('UPDATE bawalis SET declaration_owner=? WHERE id=?', ['__V26_ROLLBACK_PROBE__', $probeBill['id']]);
        $controller->probeAfterSave((int)$probeBill['id'], $probeData);
        $ownerChanged = Database::scalar('SELECT declaration_owner FROM bawalis WHERE id=?', [$probeBill['id']]);
        $probeMovements = (int)Database::scalar('SELECT COUNT(*) FROM movements WHERE bill_id=?', [$probeBill['id']]);
        $check($ownerChanged === '__V26_ROLLBACK_PROBE__', 'صاحب البيان قابل للتعديل');
        $check($probeMovements === 0, 'التعديل لا ينشئ حركة', (string)$probeMovements);
        $check(abs(Closing::currentBalance() - $balanceBeforeProbe) < 0.01, 'التعديل لا يغير رصيد العهدة');

        $historicPayment = Database::row(
            'SELECT id FROM bill_payments WHERE bill_id=? AND is_legacy=1 ORDER BY id LIMIT 1',
            [$probeBill['id']]
        );
        if ($historicPayment) {
            $beforePayRows = (int)Database::scalar('SELECT COUNT(*) FROM bill_payments WHERE bill_id=?', [$probeBill['id']]);
            $deleted = Bill::deletePayment((int)$historicPayment['id']);
            $afterPayRows = (int)Database::scalar('SELECT COUNT(*) FROM bill_payments WHERE bill_id=?', [$probeBill['id']]);
            $check($deleted === false && $beforePayRows === $afterPayRows, 'الدفعة التاريخية محمية من الحذف');
        }
        $pdo->rollBack();
        $restoredOwner = Database::scalar('SELECT declaration_owner FROM bawalis WHERE id=?', [$probeBill['id']]);
        $check($restoredOwner === $probeBill['declaration_owner'], 'تم التراجع عن بروفة التعديل بالكامل');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $check(false, 'بروفة تعديل البوليصة القديمة', $e->getMessage());
    }
}

echo PHP_EOL . 'رصيد العهدة الحالي: ' . number_format(Custody::balance(), 2) . PHP_EOL;
echo 'إجمالي باقي البوالص القديمة (مقصود في المديونيات): ' . number_format((float)$actual['remaining'], 2) . PHP_EOL;

exit($errors ? 1 : 0);
