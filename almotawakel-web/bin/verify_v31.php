<?php
/** تحقق v31 داخل معاملة تُلغى بالكامل في النهاية. */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\ClientPayment;
use App\Models\PartyMaster;
use App\Models\PermitInvoice;
use App\Models\OwnerStatement;

function v31Assert(bool $condition,string $message): void
{
    if(!$condition){throw new RuntimeException('فشل: '.$message);}
    echo "✓ $message\n";
}

$pdo=Database::conn();
$pdo->beginTransaction();
try{
    $stamp='اختبار-v31-'.bin2hex(random_bytes(4));

    $sourceId=PartyMaster::create('agent',['name'=>$stamp.'-مصدر','payment_terms_days'=>15]);
    $targetId=PartyMaster::create('agent',['name'=>$stamp.'-هدف','payment_terms_days'=>30]);
    PartyMaster::addAlias('agent',$sourceId,$stamp.'-قديم');
    PartyMaster::merge('agent',$sourceId,$targetId,1);
    $resolved=PartyMaster::resolve('agent',$stamp.'-قديم');
    v31Assert((int)($resolved['id']??0)===$targetId,'الـ alias القديم يحل إلى هدف الدمج');

    $target=PartyMaster::find('agent',$targetId);
    PartyMaster::update('agent',$targetId,['name'=>$target['name'],'payment_terms_days'=>45],(int)$target['lock_version']);
    $conflict=false;
    try{PartyMaster::update('agent',$targetId,['name'=>$target['name'],'payment_terms_days'=>60],(int)$target['lock_version']);}
    catch(Throwable $e){$conflict=str_contains($e->getMessage(),'مستخدم آخر');}
    v31Assert($conflict,'القفل التفاؤلي يرفض نسخة قديمة من سجل الطرف');

    $ownerId=PartyMaster::create('owner',['name'=>$stamp.'-صاحب-بيان']);
    PartyMaster::addAlias('owner',$ownerId,$stamp.'-اسم-قديم');
    Database::exec(
        "INSERT INTO owner_statements (statement_date,status,owner,declaration_owner_id,items_json)
         VALUES (?,'issued',?,?,?)",
        [work_date(),$stamp.'-صاحب-بيان',$ownerId,'{}']
    );
    $ownerDocs=OwnerStatement::forOwner($stamp.'-اسم-قديم');
    v31Assert(count($ownerDocs)===1 && (int)$ownerDocs[0]['declaration_owner_id']===$ownerId,
        'اسم صاحب البيان البديل يصل إلى كشوف المعرف الأساسي');

    $permit=Database::row('SELECT * FROM permits WHERE agent_id IS NOT NULL ORDER BY id LIMIT 1');
    v31Assert((bool)$permit,'يوجد إذن مرتبط بمعرف وكيل للاختبار');
    $invoiceId=PermitInvoice::create([
        'permit_id'=>(int)$permit['id'],'doc_kind'=>'invoice','taxable'=>0,'invoice_date'=>work_date(),
        'due_date'=>work_date(),'client'=>$permit['client'],'company'=>$permit['company'],
        'destination'=>$permit['destination'],'permit_no'=>$permit['permit_no'],'permit_type'=>$permit['permit_type'],
        'agency'=>$permit['agency'],'port'=>$permit['port'],'containers'=>(int)$permit['containers'],
        'vat_rate'=>0,'items'=>[['label'=>'اختبار دورة محاسبية','qty'=>1,'price'=>1000,'freight'=>1]],
    ]);
    $invoice=PermitInvoice::find($invoiceId);
    v31Assert($invoice['status']==='issued' && abs((float)$invoice['balance_due']-1000)<.01,'الفاتورة تصدر برصيد مستحق صحيح');

    $paymentId=ClientPayment::create([
        'agent_id'=>(int)$invoice['agent_id'],'client_name'=>$invoice['client'],'pay_date'=>work_date(),
        'amount'=>400,'route'=>'none','note'=>'اختبار تخصيص','auto_allocate'=>1,
    ]);
    $invoice=PermitInvoice::find($invoiceId);$payment=ClientPayment::find($paymentId);
    v31Assert($invoice['status']==='partially_paid' && abs((float)$invoice['balance_due']-600)<.01,'التحصيل الجزئي يحدّث حالة ورصيد الفاتورة');
    v31Assert(abs((float)$payment['unallocated_amount'])<.01,'التخصيص التلقائي يستهلك التحصيل المخصص');

    ClientPayment::reverse($paymentId,'اختبار العكس');
    $invoice=PermitInvoice::find($invoiceId);$payment=ClientPayment::find($paymentId);
    v31Assert($payment['status']==='reversed','العكس يحفظ التحصيل بحالة معكوس');
    v31Assert($invoice['status']==='issued' && abs((float)$invoice['balance_due']-1000)<.01,'عكس التحصيل يعيد رصيد الفاتورة');

    PermitInvoice::void($invoiceId,'اختبار الإلغاء',(int)$invoice['lock_version']);
    $invoice=PermitInvoice::find($invoiceId);
    v31Assert($invoice['status']==='void' && abs((float)$invoice['balance_due'])<.01,'إلغاء الفاتورة يحفظها ويصفر المستحق');

    $unique=false;
    try{
        Database::exec('INSERT INTO permits (permit_type,permit_no) VALUES (?,?)',[$permit['permit_type'],$permit['permit_no']]);
    }catch(Throwable $e){$unique=true;}
    v31Assert($unique,'قيد قاعدة البيانات يمنع رقم إذن مكرر');

    v31Assert((int)Database::scalar('SELECT COUNT(*) FROM audit_logs WHERE entity IN (\'permit_invoices\',\'client_payments\') AND action IN (\'issue\',\'allocate\',\'reverse\',\'void\')')>=4,
        'الإصدار والتخصيص والعكس والإلغاء مسجلة في سجل العمليات');

    $pdo->rollBack();
    echo "\nاكتمل تحقق v31؛ تم التراجع عن كل بيانات الاختبار.\n";
}catch(Throwable $e){
    if($pdo->inTransaction()){$pdo->rollBack();}
    fwrite(STDERR,$e->getMessage()."\n");exit(1);
}
