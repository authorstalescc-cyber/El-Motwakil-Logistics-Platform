<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص حساب سائق سيارة المكتب (v65) — كل الكتابة داخل transaction ثم rollback.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;
use App\Models\DailyReview;
use App\Models\OfficeCar;
use App\Models\OfficeDriverStatement;

$pass=0; $fail=0;
function v65ok(string $m): void { global $pass; $pass++; echo "  ✓ {$m}\n"; }
function v65bad(string $m): void { global $fail; $fail++; echo "  ✗ {$m}\n"; }
function v65near(string $m, float $actual, float $expected): void {
    abs($actual-$expected)<=0.005 ? v65ok($m) : v65bad("{$m}: متوقع {$expected} / فعلي {$actual}");
}
function v65src(string $f): string {
    $p=dirname(__DIR__).'/'.$f; return is_file($p)?(string)file_get_contents($p):'';
}

echo "فحص حساب سائق سيارة المكتب (v65)\n".str_repeat('=',58)."\n";
if (!OfficeDriverStatement::ready()) {
    echo "شغّل php bin/migrate_v65.php أولاً.\n"; exit(1);
}

$car = Database::row("SELECT * FROM office_cars WHERE status='active' ORDER BY id LIMIT 1");
if (!$car) { echo "مفيش سيارة مكتب نشطة للاختبار.\n"; exit(1); }
$safeBefore = Closing::currentBalance();
$pdo=Database::conn(); $pdo->beginTransaction();

try {
    Database::exec(
        "INSERT INTO permits (permit_no,permit_type,permit_date,status,company,client,destination)
         VALUES ('__v65','sader','2037-08-01','open','__v65 co','__v65 client','بني سويف')"
    );
    $pid=Database::lastId();
    Database::exec("INSERT INTO permit_movements (permit_id,seq,note) VALUES (?,1,'اختبار')",[$pid]);
    $mvid=Database::lastId();
    Database::exec(
        "INSERT INTO bawalis
            (bill_type,bill_date,bill_number,company,destination,car_no,driver,car_owner,
             custody,freight,permit_id,movement_id,is_legacy)
         VALUES ('sader','2037-08-01','__v65_bill','__v65 co','بني سويف',?,?,?,?,?,?,?,0)",
        [$car['plate'],$car['driver'] ?: 'جلال حسني','المكتب',4000,0,$pid,$mvid]
    );
    $bid=Database::lastId();
    Database::exec(
        "INSERT INTO permit_stages
            (permit_id,movement_id,stage_key,direction,amount,entry_date,note,ref_no,status,is_visa,unpaid)
         VALUES (?,?,'receipts','expense',1150,'2037-08-01','وصل جيش','ARMY-1','done',0,0)",
        [$pid,$mvid]
    );
    $sid=Database::lastId();

    $items=[
        ['category'=>'driver_share','description'=>'دور السائق','amount'=>2200],
        ['category'=>'assistant_share','description'=>'دور التباع','amount'=>900],
        ['category'=>'thabtan','description'=>'جمرك + ثبتان جمرك','amount'=>100],
        ['category'=>'tahia_masr','description'=>'دخول جمرك','amount'=>60],
        ['category'=>'cards','description'=>'محافظة','amount'=>100],
        ['category'=>'cards','description'=>'طرق وكباري','amount'=>350],
        ['category'=>'permits','description'=>'تصريح','amount'=>300],
        ['category'=>'other_expense','description'=>'حمولة سائق','amount'=>700],
        ['category'=>'other_expense','description'=>'جراج محرم بك','amount'=>50],
        ['category'=>'cards','description'=>'كارتة تحيا مصر','amount'=>60],
        ['category'=>'other_expense','description'=>'ميناء خزانين','amount'=>40],
        ['category'=>'cards','description'=>'كارتة','amount'=>180],
    ];
    OfficeDriverStatement::saveBill(
        (int)$car['id'],$bid,$items,[$sid=>1150],
        [['kind'=>'advance','move_date'=>'2037-08-01','note'=>'تكملة عهدة','amount'=>500]],
        '__verify_v65'
    );

    $statement=OfficeDriverStatement::statement((int)$car['id'],'2037-08-01','2037-08-01');
    v65near('إجمالي المصروفات اليدوية في المثال = 5040', $statement['totals']['expenses'], 5040);
    v65near('إيصال الجيش المخصّص = 1150', $statement['totals']['receipts'], 1150);
    v65near('العهدة الإضافية = 500', $statement['totals']['additions'], 500);
    v65near('★ صافي بيان المثال نفسه = -1690', (float)$statement['rows'][0]['net'], -1690);
    v65near('والرصيد النهائي = السابق + صافي المثال',
        (float)$statement['closing'], (float)$statement['opening'] - 1690);
    $docId=OfficeDriverStatement::issue((int)$car['id'],'2037-08-01','2037-08-01','__verify_v65');
    $savedDoc=OfficeDriverStatement::issuedStatement((int)$car['id'],$docId);
    v65near('الإصدار المحفوظ يثبت نفس الرصيد', (float)$savedDoc['closing'], (float)$statement['closing']);
    OfficeDriverStatement::addMovement((int)$car['id'],[
        'move_date'=>'2037-08-01','kind'=>'credit_adjustment','amount'=>100,'note'=>'بعد الإصدار'
    ],'__verify_v65');
    $liveAfter=OfficeDriverStatement::statement((int)$car['id'],'2037-08-01','2037-08-01');
    v65near('المعاينة الحية تتغير بعد حركة لاحقة', (float)$liveAfter['closing'], (float)$statement['closing']+100);
    $savedAfter=OfficeDriverStatement::issuedStatement((int)$car['id'],$docId);
    v65near('★ والنسخة الصادرة تظل ثابتة', (float)$savedAfter['closing'], (float)$statement['closing']);

    $bill=Database::row('SELECT driver_share,assistant_share,thabtan,tahia_masr,cards,permits,other_expense,
                                total_expense,custody_remaining,car_net,custody,freight FROM bawalis WHERE id=?',[$bid]);
    v65near('دور السائق اتزامن مع البوليصة', (float)$bill['driver_share'], 2200);
    v65near('الكرتات المجمّعة اتزامنت مع البوليصة', (float)$bill['cards'], 690);
    v65near('الأخرى المجمّعة اتزامنت مع البوليصة', (float)$bill['other_expense'], 790);

    /* ⚠️⚠️ حارس ارتداد: التلات أعمدة دي **مخزَّنة مش محسوبة تلقائيًا**، والمسار
       العادي بيملاها عبر `Calc::apply` بينما بيان السائق بيتخطّاه. لو اتسابت
       بتفضل صفر والبنود مليانة، فشاشة البوليصة تقول «إجمال المصروف = ٠» وتقرير
       أداء الشركات يقرا `car_net` الخام فيدّي صافي غلط — **بلا أي خطأ ظاهر**.
       الفحص على **المعادلة** لا على أرقام الـfixture: أي تعديل في البنود فوق
       بيفضل صحيح، وشيل السطور من `saveBill` بيفشّله فورًا. */
    v65near('★ إجمال المصروف المخزَّن اتحدّث مع البنود', (float)$bill['total_expense'], 5040);
    v65near('★ والباقى من العهدة = العهدة − المصروف',
        (float)$bill['custody_remaining'], round((float)$bill['custody']-5040,2));
    v65near('★ وصافى السيارة = النولون − المصروف',
        (float)$bill['car_net'], round((float)$bill['freight']-5040,2));

    $safeExpr=OfficeDriverStatement::stageSafeAmountSql('s');
    $safeStage=(float)Database::scalar("SELECT {$safeExpr} FROM permit_stages s WHERE s.id=?",[$sid]);
    v65near('★ الإيصال الكامل المخصّص لجلال لا يخرج من الدرج ثانية', $safeStage, 0);
    v65near('وحارس حذف البوليصة يرى التخصيص', OfficeDriverStatement::billAllocationTotal($bid), 1150);
    v65near('وحارس حذف الحركة يراه', OfficeDriverStatement::movementAllocationTotal($mvid), 1150);
    v65near('وحارس حذف الإذن يراه', OfficeDriverStatement::permitAllocationTotal($pid), 1150);
    $review=DailyReview::view('2037-08-01');
    v65near('ومراجعة اليوم تعرضه في محفظة السائق', (float)$review['groups']['driver_wallet']['total'], 1150);
    v65near('ولا تعرضه ضمن مصروفات درج الإذن', (float)$review['groups']['permits']['total'], 0);

    $rejected=false;
    try {
        OfficeDriverStatement::saveBill(
            (int)$car['id'],$bid,$items,[$sid=>1150.01],
            [['kind'=>'advance','move_date'=>'2037-08-01','amount'=>500]],'__verify_v65'
        );
    } catch (RuntimeException $e) { $rejected=true; }
    $rejected ? v65ok('التخصيص الأكبر من الإيصال مرفوض') : v65bad('قَبِل تخصيصًا أكبر من الإيصال');

    $routes=v65src('public/index.php');
    (strpos($routes,"'/cars/{id}/driver/bill/{bid}'") < strpos($routes,"'/cars/{id}'"))
        ? v65ok('مسارات بيان السائق قبل المسار الجشع') : v65bad('ترتيب المسارات غير آمن');
    str_contains(v65src('app/Views/office_driver/print.php'),'رصيد سابق')
        ? v65ok('بيان A4 يعرض الرصيد السابق') : v65bad('الرصيد السابق ناقص من الطباعة');
    str_contains(v65src('app/Views/office_driver/print.php'),'السالب = مستحق للسائق')
        ? v65ok('الطباعة تشرح معنى الرصيد السالب') : v65bad('اتجاه الرصيد غير مشروح');
    $permitController=v65src('app/Controllers/PermitController.php');
    (substr_count($permitController,'OfficeDriverStatement::') >= 5
      && str_contains(v65src('app/Controllers/BawalisController.php'),'billAllocationTotal')
      && str_contains(v65src('app/Models/RepPortal.php'),'billAllocationTotal'))
        ? v65ok('حُرّاس الحذف والنقل تمنع يتْم تخصيصات السائق')
        : v65bad('نقطة حذف ممكن تترك تخصيص سائق يتيمًا');

    $render = static function (string $view, array $vars): string {
        extract($vars);
        ob_start();
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try { include dirname(__DIR__).'/app/Views/'.$view.'.php'; }
        finally { restore_error_handler(); }
        return (string) ob_get_clean();
    };
    $accountHtml=$render('office_driver/index',[
        'car'=>$car,'statement'=>$statement,'ready'=>true,'moveToken'=>str_repeat('a',64),
        'issueToken'=>str_repeat('c',64),'issued'=>OfficeDriverStatement::issued((int)$car['id']),
    ]);
    (str_contains($accountHtml,'حساب سائق السيارة')
      && str_contains($accountHtml,'حركة الحساب')
      && str_contains($accountHtml,'od-hero')
      && !str_contains($accountHtml,'<table'))
        ? v65ok('شاشة حساب السائق ترندر بلغة النظام بلا جدول خام') : v65bad('رندر شاشة الحساب ناقص أو رجع للجدول الخام');
    $billNow=OfficeDriverStatement::billForCar((int)$car['id'],$bid);
    $billHtml=$render('office_driver/bill',[
        'car'=>$car,'bill'=>$billNow,
        'items'=>OfficeDriverStatement::billItems((int)$car['id'],$billNow),
        'receipts'=>OfficeDriverStatement::availableReceipts((int)$car['id'],$billNow),
        'movements'=>OfficeDriverStatement::billMovements((int)$car['id'],$bid),
        'state'=>['old'=>[],'errors'=>[],'token'=>str_repeat('b',64),'back'=>'cars/'.(int)$car['id'].'/driver'],
        'back'=>'cars/'.(int)$car['id'].'/driver',
    ]);
    (str_contains($billHtml,'وصل جيش')
      && str_contains($billHtml,'حفظ البيان')
      && str_contains($billHtml,'od-entry-row')
      && !str_contains($billHtml,'<table'))
        ? v65ok('وشاشة البوليصة ترندر كمحرر بطاقات بلا جدول خام') : v65bad('رندر شاشة البوليصة ناقص أو رجع للجدول الخام');
    $driverViews=v65src('app/Views/office_driver/index.php').v65src('app/Views/office_driver/bill.php');
    $driverCss=v65src('public/assets/css/app.css');
    (!str_contains($driverViews,'<table')
      && str_contains($driverViews,"partial('panel'")
      && str_contains($driverViews,"partial('row_item'")
      && str_contains($driverCss,'@media(max-width:640px)')
      && str_contains($driverCss,'.od-totalbar')
      && str_contains($driverCss,'position:static;display:grid')
      && !str_contains($driverCss,'position:sticky;bottom:10px'))
        ? v65ok('★ حارس التصميم: مكوّنات النظام + تجاوب + صفر جداول خام')
        : v65bad('حارس تصميم بيان السائق فشل');
    $printHtml=$render('office_driver/print',['title'=>'اختبار','statement'=>$statement]);
    str_contains($printHtml,'بيان حساب سائق') && str_contains($printHtml,'صافي')
        ? v65ok('وبيان A4 يرندر فعليًا') : v65bad('رندر بيان A4 ناقص');
    $savedPrint=$render('office_driver/print',['title'=>'محفوظ','statement'=>$savedAfter]);
    str_contains($savedPrint,'بيان محفوظ #'.$docId)
        ? v65ok('والطباعة تميّز النسخة المحفوظة عن المعاينة') : v65bad('ختم البيان المحفوظ ناقص');
} catch (Throwable $e) {
    v65bad($e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}

v65near('الـrollback رجّع رصيد الخزنة كما كان', Closing::currentBalance(), $safeBefore);
echo "\nالنتيجة: {$pass} ناجح / {$fail} فاشل\n";
exit($fail ? 1 : 0);
