<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** تحقق آمن داخل transaction لنقل الإيصال بين إذنين مع الحفاظ على بياناته. */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Permit;
use App\Models\PermitInvoice;

function transferAssert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException('فشل: '.$message); }
    echo "✓ $message\n";
}

Auth::start();
$user = Database::row('SELECT id FROM users ORDER BY id LIMIT 1');
if (!$user) { throw new RuntimeException('لا يوجد مستخدم للاختبار.'); }
$_SESSION['uid'] = (int)$user['id'];
$_SESSION['uname'] = 'اختبار نقل الإيصال';
$_SESSION['work_date'] = date('Y-m-d');

$pdo = Database::conn();
$pdo->beginTransaction();
try {
    $stamp = 'MOVE-'.bin2hex(random_bytes(4));
    $futureDate = '2099-12-31';
    Database::exec(
        "INSERT INTO permits (permit_type,permit_no,permit_date,client,company,status)
         VALUES ('sader',?,?,?,?,'open')",
        [$stamp.'-SRC',$futureDate,$stamp.'-عميل',$stamp.'-شركة']
    );
    $sourcePermit = Database::lastId();
    Database::exec('INSERT INTO permit_movements (permit_id,seq,note) VALUES (?,1,?)', [$sourcePermit,'مصدر']);
    $sourceMovement = Database::lastId();

    Database::exec(
        "INSERT INTO permits (permit_type,permit_no,permit_date,client,company,status)
         VALUES ('sader',?,?,?,?,'open')",
        [$stamp.'-DST',$futureDate,$stamp.'-عميل',$stamp.'-شركة']
    );
    $targetPermit = Database::lastId();
    Database::exec('INSERT INTO permit_movements (permit_id,seq,note) VALUES (?,1,?)', [$targetPermit,'هدف']);
    $targetMovement = Database::lastId();

    Database::exec(
        "INSERT INTO permit_stages
            (permit_id,movement_id,stage_key,direction,amount,qty,actual_amount,entry_date,ref_no,note,status,attachment,is_visa,visa_card_id)
         VALUES (?,?,'receipts',NULL,246.90,2,300.50,?,? ,?,'pending',?,0,NULL)",
        [$sourcePermit,$sourceMovement,$futureDate,$stamp.'-REF',$stamp.'-إيصال','uploads/'.$stamp.'.pdf']
    );
    $receiptId = Database::lastId();
    $before = Database::row('SELECT * FROM permit_stages WHERE id=?', [$receiptId]);
    $result = Permit::moveReceipt($receiptId, $targetMovement);
    $after = Database::row('SELECT * FROM permit_stages WHERE id=?', [$receiptId]);

    transferAssert((int)$after['id'] === $receiptId, 'النقل يحتفظ بمعرّف سجل الإيصال نفسه');
    transferAssert((int)$after['permit_id'] === $targetPermit && (int)$after['movement_id'] === $targetMovement,
        'الإيصال ينتقل إلى الإذن والحركة الهدف');
    $beforeStable = $before; $afterStable = $after;
    unset($beforeStable['permit_id'],$beforeStable['movement_id'],$afterStable['permit_id'],$afterStable['movement_id']);
    transferAssert($beforeStable === $afterStable,
        'كل بيانات الإيصال والتاريخ والمرجع والمرفق وطريقة الدفع تبقى بلا تغيير');
    transferAssert((int)$result['source_permit_id'] === $sourcePermit && (int)$result['target_permit_id'] === $targetPermit,
        'نتيجة النقل تعيد المصدر والهدف الصحيحين');
    transferAssert(abs((float)Permit::totals($sourcePermit)['expense']) < .001
        && abs((float)Permit::totals($targetPermit)['expense'] - 246.90) < .001,
        'إجماليات الإذنين تُعاد نسبتها بعد النقل دون تغيير الإجمالي العام');

    $log = Database::row("SELECT * FROM audit_logs WHERE action='move' AND entity='permit_stages' AND record_id=? ORDER BY id DESC LIMIT 1", [$receiptId]);
    $meta = json_decode((string)($log['meta_json'] ?? ''), true) ?: [];
    transferAssert($log && (int)($meta['source_permit_id'] ?? 0) === $sourcePermit
        && (int)($meta['permit_id'] ?? 0) === $targetPermit
        && ($meta['record_date'] ?? '') === $futureDate,
        'النقل مسجل في سجل العمليات بالمصدر والهدف وتاريخ الإيصال');

    $sameBlocked = false;
    try { Permit::moveReceipt($receiptId, $targetMovement); }
    catch (RuntimeException $e) { $sameBlocked = str_contains($e->getMessage(), 'إذنًا آخر'); }
    transferAssert($sameBlocked, 'لا يمكن تنفيذ نقل وهمي داخل الإذن نفسه');

    Database::exec(
        "INSERT INTO permit_stages (permit_id,movement_id,stage_key,amount,qty,actual_amount,entry_date,note,status,is_visa)
         VALUES (?,?,'receipts',100,1,120,?,?,'done',0)",
        [$sourcePermit,$sourceMovement,$futureDate,$stamp.'-مفوتر']
    );
    $billedReceipt = Database::lastId();
    PermitInvoice::create([
        'permit_id'=>$sourcePermit,'doc_kind'=>'statement','taxable'=>0,'invoice_date'=>$futureDate,'due_date'=>$futureDate,
        'client'=>$stamp.'-عميل','company'=>$stamp.'-شركة','destination'=>'','permit_no'=>$stamp.'-SRC',
        'permit_type'=>'sader','agency'=>'','port'=>'','containers'=>0,'vat_rate'=>0,
        'items'=>[['label'=>$stamp.'-مفوتر','qty'=>1,'price'=>120,'freight'=>0,'source'=>'stage:'.$billedReceipt]],
    ]);
    $invoiceBlocked = false;
    try { Permit::moveReceipt($billedReceipt, $targetMovement); }
    catch (RuntimeException $e) { $invoiceBlocked = str_contains($e->getMessage(), 'داخل المستند'); }
    transferAssert($invoiceBlocked, 'الإيصال المثبت داخل مستند مالي لا يُنقل قبل إلغاء المستند');

    $pdo->rollBack();
    echo "\nاكتمل اختبار نقل الإيصال؛ تم التراجع عن كل بيانات الاختبار.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}
