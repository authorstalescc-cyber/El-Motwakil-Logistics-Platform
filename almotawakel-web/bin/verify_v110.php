<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v110 — الحساب البنكي محفظة أم، والكروت التابعة تشترك في رصيده.
 * كل بيانات الاختبار داخل transaction وتنتهي بـrollback.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\BankAccount;
use App\Models\ClientPayment;
use App\Models\CompanySafe;
use App\Models\Expense;
use App\Models\VisaCard;

$pass=0; $fail=0;
function v110ok(string $m): void { global $pass; $pass++; echo "  ✓ $m\n"; }
function v110bad(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function v110near(string $m,float $got,float $want): void {
    abs($got-$want)<=0.005 ? v110ok($m) : v110bad($m.' — متوقع '.number_format($want,2).' / فعلي '.number_format($got,2));
}
function v110reject(string $m,callable $fn,string $needle=''): void {
    try { $fn(); v110bad($m.' — العملية اتقبلت'); }
    catch (\RuntimeException $e) {
        ($needle==='' || str_contains($e->getMessage(),$needle)) ? v110ok($m)
            : v110bad($m.' — رسالة غير متوقعة: '.$e->getMessage());
    }
}
function v110src(string $file): string {
    $p=dirname(__DIR__).'/'.$file; if(!is_file($p)) return '';
    $out=''; foreach(token_get_all((string)file_get_contents($p)) as $t) {
        if(is_array($t) && in_array($t[0],[T_COMMENT,T_DOC_COMMENT],true)) continue;
        $out.=is_array($t)?$t[1]:$t;
    } return $out;
}

echo "فحص الحسابات البنكية والرصيد الموحّد (v110)\n".str_repeat('=',68)."\n";
if (!BankAccount::ready()) { echo "\n⚠️ شغّل bin/migrate_v110.php أولًا.\n"; exit(1); }

// ① بنية دائمة ومفاتيح مرجعية.
$required=[
    'visa_cards'=>'fk_vc_bank_account', 'visa_deposits'=>'fk_vd_bank_account',
    'client_payments'=>'fk_cp_bank_account', 'company_safe_entries'=>'fk_cse_bank_account',
];
foreach($required as $table=>$fk) {
    $has=(int)Database::scalar('SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name=? AND constraint_name=?',[$table,$fk]);
    $has===1 ? v110ok("$table مربوط بالحساب البنكي") : v110bad("المفتاح $fk ناقص");
}
(int)Database::scalar("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='visa_cards' AND column_name='bank_account_id' AND is_nullable='NO'")===1
    ? v110ok('كل كارت لازم يكون له حساب') : v110bad('visa_cards.bank_account_id يسمح بـNULL');

// ② بيانات الترحيل نفسها متوازنة، ولا بنك واحد منقسم على حسابين.
$derived=round((float)Database::scalar('SELECT COALESCE(SUM(amount),0) FROM visa_deposits')
    -(float)Database::scalar('SELECT COALESCE(SUM(amount),0) FROM ('.VisaCard::paymentsUnion().') t'),2);
v110near('إجمالي أرصدة الحسابات = الحركات − صرف كل الكروت',BankAccount::summary()['balance'],$derived);
$split=(int)Database::scalar("SELECT COUNT(*) FROM (SELECT LOWER(TRIM(bank)) k FROM visa_cards WHERE TRIM(COALESCE(bank,''))<>'' GROUP BY LOWER(TRIM(bank)) HAVING COUNT(DISTINCT bank_account_id)<>1) x");
$split===0 ? v110ok('نفس اسم البنك متجمع في حساب واحد') : v110bad('فيه اسم بنك متقسم على حسابات');

// ③ سلوك حقيقي على كارتين في حساب واحد.
$pdo=Database::conn(); $pdo->beginTransaction();
try {
    $accountId=BankAccount::create(['name'=>'اختبار v110 مشترك','bank_name'=>'بنك اختبار','status'=>'active']);
    $cardA=VisaCard::create(['name'=>'اختبار A','owner'=>'الفاحص','bank_account_id'=>$accountId,'status'=>'active']);
    $cardB=VisaCard::create(['name'=>'اختبار B','owner'=>'الفاحص','bank_account_id'=>$accountId,'status'=>'active']);
    $entryId=BankAccount::createEntry($accountId,['dep_date'=>work_date(),'amount'=>1000,'note'=>'رصيد الاختبار']);
    v110near('الرصيد يظهر نفسه على الكارتين',VisaCard::balanceOf($cardA),VisaCard::balanceOf($cardB));
    v110near('الإيداع وصل للحساب',BankAccount::balanceOf($accountId),1000);

    $expA=Expense::createManual(['exp_date'=>work_date(),'category'=>'اختبار','beneficiary'=>'v110',
        'amount'=>400,'note'=>'A','is_visa'=>1,'visa_card_id'=>$cardA]);
    $expB=Expense::createManual(['exp_date'=>work_date(),'category'=>'اختبار','beneficiary'=>'v110',
        'amount'=>500,'note'=>'B','is_visa'=>1,'visa_card_id'=>$cardB]);
    v110near('صرف كارتين اتخصم من نفس الرصيد',BankAccount::balanceOf($accountId),100);
    v110near('الكارت الأول يعرض الرصيد المشترك',VisaCard::balanceOf($cardA),100);
    v110near('الكارت الثاني يعرض الرصيد المشترك',VisaCard::balanceOf($cardB),100);

    VisaCard::update($cardB,['name'=>'اختبار B','owner'=>'الفاحص','bank_account_id'=>$accountId,
        'status'=>'inactive','note'=>'']);
    v110reject('الكارت المتوقف لا يقبل صرفًا جديدًا',static function() use($cardB): void {
        Expense::createManual(['exp_date'=>work_date(),'category'=>'اختبار','beneficiary'=>'v110',
            'amount'=>1,'note'=>'رفض متوقف','is_visa'=>1,'visa_card_id'=>$cardB]);
    },'متوقف');
    VisaCard::update($cardB,['name'=>'اختبار B','owner'=>'الفاحص','bank_account_id'=>$accountId,
        'status'=>'active','note'=>'']);

    BankAccount::update($accountId,['name'=>'اختبار v110 مشترك','bank_name'=>'بنك اختبار محدّث',
        'status'=>'active']);
    $legacyBanks=Database::all('SELECT DISTINCT bank FROM visa_cards WHERE bank_account_id=?',[$accountId]);
    (count($legacyBanks)===1 && ($legacyBanks[0]['bank']??'')==='بنك اختبار محدّث')
        ? v110ok('تعديل البنك يزامن عمود الكروت القديم') : v110bad('عمود البنك القديم اتفرّع عن الحساب');

    v110reject('الكارت المستخدم لا ينتقل لحساب آخر',static function() use($cardA): void {
        $other=BankAccount::create(['name'=>'اختبار نقل مرفوض','status'=>'active']);
        VisaCard::update($cardA,['name'=>'اختبار A','owner'=>'الفاحص','bank_account_id'=>$other,
            'status'=>'active','note'=>'']);
    },'ما ينفعش تنقله');
    v110reject('الكارت المستخدم لا يُحذف',static fn()=>VisaCard::delete($cardA),'وقّفه بدل الحذف');

    $countBefore=(int)Database::scalar('SELECT COUNT(*) FROM manual_expenses');
    v110reject('عملية تجعل الحساب سالبًا مرفوضة',static function() use($cardB): void {
        Expense::createManual(['exp_date'=>work_date(),'category'=>'اختبار','beneficiary'=>'v110',
            'amount'=>100.01,'note'=>'رفض','is_visa'=>1,'visa_card_id'=>$cardB]);
    },'الرصيد لا يكفي');
    (int)Database::scalar('SELECT COUNT(*) FROM manual_expenses')===$countBefore
        ? v110ok('العملية المرفوضة لم تترك صفًا جزئيًا') : v110bad('العملية المرفوضة كتبت صفًا');

    v110reject('سحب يدوي أكبر من المتاح مرفوض',static fn()=>BankAccount::createEntry($accountId,
        ['dep_date'=>work_date(),'amount'=>-100.01,'note'=>'رفض']),'الرصيد لا يكفي');
    v110reject('حذف إيداع مستخدم لو هيسلّب الحساب مرفوض',static fn()=>BankAccount::deleteEntry($entryId),'الرصيد لا يكفي');

    // ردّ 500 ثم سحبها: الحذف يرد الرصيد والحارس يسمح بالحد الأقصى بالضبط.
    Expense::deleteManual($expB);
    BankAccount::createEntry($accountId,['dep_date'=>work_date(),'amount'=>-600,'note'=>'سحب حتى صفر']);
    v110near('السحب حتى صفر مسموح',BankAccount::balanceOf($accountId),0);

    // ④ التحصيل يتوجه للحساب نفسه، والعكس محروس.
    $cp=ClientPayment::create(['client_name'=>'عميل اختبار v110','pay_date'=>work_date(),'amount'=>250,
        'route'=>'visa','bank_account_id'=>$accountId,'note'=>'اختبار']);
    $saved=ClientPayment::find($cp);
    ((int)($saved['bank_account_id']??0)===$accountId && empty($saved['visa_card_id']))
        ? v110ok('تحصيل العميل مربوط بالحساب لا بكارت') : v110bad('تحصيل العميل ما زال مربوطًا بكارت');
    v110near('التحصيل زوّد الرصيد الموحّد',BankAccount::balanceOf($accountId),250);
    ClientPayment::reverse($cp,'اختبار العكس');
    v110near('عكس التحصيل شال أثره من الحساب',BankAccount::balanceOf($accountId),0);

    // ⑤ خزنة الشركة تكتب حسابًا، والاتجاه السالب يمر من الحارس.
    if (CompanySafe::ready()) {
        CompanySafe::create(['entry_date'=>work_date(),'kind'=>'deposit','amount'=>1000,'source'=>'فحص v110']);
        $move=CompanySafe::create(['entry_date'=>work_date(),'kind'=>'to_visa','amount'=>300,
            'bank_account_id'=>$accountId,'source'=>'فحص v110']);
        $moveRow=CompanySafe::find($move);
        ((int)($moveRow['bank_account_id']??0)===$accountId && empty($moveRow['visa_card_id']))
            ? v110ok('تحويل خزنة الشركة مربوط بالحساب لا بكارت') : v110bad('تحويل الخزنة ما زال مربوطًا بكارت');
        v110near('تحويل الخزنة زوّد الحساب',BankAccount::balanceOf($accountId),300);
        CompanySafe::delete($move);
        v110near('حذف تحويل الخزنة عكس الحساب',BankAccount::balanceOf($accountId),0);
    }

    // ⑥ الحراس موجودة عند كل مصادر الصرف الخمسة.
    $guarded=[
        'app/Models/Expense.php','app/Models/Debt.php','app/Models/Movement.php',
        'app/Models/PermitQuickEntry.php','app/Controllers/PermitController.php',
        'app/Models/RepMiscExpense.php','app/Models/RepInbox.php',
    ];
    foreach($guarded as $file) {
        str_contains(v110src($file),'BankAccount::withCardSpendChanges')
            ? v110ok(basename($file).' يمر من حارس الرصيد') : v110bad($file.' بلا حارس رصيد');
    }
    str_contains(v110src('app/Models/BankAccount.php'),'if (!$clean) { return self::transactionalWrite($write); }')
        ? v110ok('الحفظ يظل ذريًا حتى لو صافي التغيير صفر')
        : v110bad('مسار صافي الصفر خارج transaction');

    // ⑦ المسارات الخاصة بالحساب قبل المسار الجشع للكارت.
    $routes=(string)file_get_contents(dirname(__DIR__).'/public/index.php');
    // قارن تعريفَي GET نفسيهما؛ ذكر المسار في التعليق أعلى الملف لا يُعتبر route.
    $pAccount=strpos($routes,"\$r->get('/office/accounts/{id}'");
    $pCard=strpos($routes,"\$r->get('/office/{id}'");
    ($pAccount!==false && $pCard!==false && $pAccount<$pCard)
        ? v110ok('مسارات الحسابات قبل مسار الكارت الجشع') : v110bad('ترتيب مسارات office غير آمن');
} catch (\Throwable $e) {
    v110bad('استثناء غير متوقع: '.$e->getMessage());
} finally {
    if($pdo->inTransaction()) $pdo->rollBack();
}

echo "\n".str_repeat('=',68)."\n";
echo ($fail===0?'✅ كل الفحوصات نجحت':'❌ فيه فحوصات فشلت')." — نجح $pass · فشل $fail\n";
exit($fail===0?0:1);
