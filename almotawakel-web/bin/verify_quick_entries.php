<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** اختبار آمن داخل transaction للإدخال السريع والمراجعة وعدم الحفظ الجزئي. */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\DashboardController;
use App\Core\Auth;
use App\Core\Database;
use App\Models\PermitQuickEntry;

Auth::start();
$user = Database::row('SELECT id FROM users ORDER BY id LIMIT 1');
if (!$user) { throw new RuntimeException('لا يوجد مستخدم للاختبار.'); }
$_SESSION['uid'] = (int)$user['id'];
$_SESSION['uname'] = 'اختبار الإدخال السريع';
$_SESSION['work_date'] = date('Y-m-d');

$target = Database::row(
    "SELECT p.id permit_id,p.permit_no,m.id movement_id,m.seq
       FROM permits p JOIN permit_movements m ON m.permit_id=p.id
      ORDER BY p.status='closed',p.id DESC,m.seq LIMIT 1"
);
$other = Database::row(
    'SELECT id,permit_id FROM permit_movements WHERE permit_id<>? ORDER BY id LIMIT 1',
    [(int)$target['permit_id']]
);
if (!$target || !$other) { throw new RuntimeException('لا توجد أذونات كافية للاختبار.'); }
$cardId = (int) Database::scalar("SELECT id FROM visa_cards WHERE status='active' ORDER BY id LIMIT 1");
if (!$cardId) { throw new RuntimeException('لا يوجد كارت فيزا نشط لاختبار مسار الفيزا.'); }
$option = null;
foreach (PermitQuickEntry::options() as $candidate) {
    if ((int)$candidate['id'] === (int)$target['permit_id']) { $option = $candidate; break; }
}
if (!$option || empty($option['company']) || empty($option['container_label']) || empty($option['search'])) {
    throw new RuntimeException('بيانات بطاقة اختيار الإذن غير مكتملة.');
}

$pdo = Database::conn();
$pdo->beginTransaction();
try {
    $input = [
        'permit_id'=>[$target['permit_id'],$target['permit_id']],
        'movement_id'=>[$target['movement_id'],$target['movement_id']],
        'kind'=>['receipt','expense'],
        'note'=>['__اختبار إيصال سريع__','__اختبار مصروف سريع__'],
        'qty'=>['2','1'],
        'amount'=>['125.50','74.50'],
        'actual_amount'=>['150','999'], // يجب تجاهل الفعلي للمصروف حتى لو أُرسل يدويًا
        'entry_date'=>[Auth::workDate(),Auth::workDate()],
        'ref_no'=>['QE-TEST-1','QE-TEST-2'],
        'payment_method'=>['cash','visa'],
        'visa_card_id'=>['',(string)$cardId],
    ];
    $result = PermitQuickEntry::save($input);
    if ($result['count'] !== 2 || abs($result['totals']['receipt']-251)>.001 || abs($result['totals']['expense']-74.5)>.001) {
        throw new RuntimeException('فشل حساب عدد أو إجماليات الدفعة.');
    }
    $saved = Database::all(
        'SELECT stage_key,direction,amount,qty,actual_amount,permit_id,movement_id,is_visa,visa_card_id FROM permit_stages WHERE id IN (?,?) ORDER BY id',
        $result['ids']
    );
    if (count($saved)!==2 || $saved[0]['stage_key']!=='receipts' || (int)$saved[0]['qty']!==2 || abs((float)$saved[0]['amount']-251)>.001 || abs((float)$saved[0]['actual_amount']-300)>.001 || $saved[1]['stage_key']!=='custom' || $saved[1]['actual_amount']!==null ||
        $saved[1]['direction']!=='expense' || (int)$saved[1]['is_visa']!==1 || (int)$saved[1]['visa_card_id']!==$cardId) {
        throw new RuntimeException('فشل توزيع الإيصال والمصروف داخل مراحل الإذن الصحيحة.');
    }
    $auditCount = (int) Database::scalar(
        "SELECT COUNT(*) FROM audit_logs WHERE JSON_UNQUOTE(JSON_EXTRACT(meta_json,'$.quick_batch'))=?",
        [$result['batch']]
    );
    if ($auditCount !== 2) { throw new RuntimeException('فشل تسجيل البنود في سجل الإدخالات.'); }

    // سطر ثانٍ بحركة من إذن آخر: يجب رفض الدفعة قبل إدراج أي صف جديد.
    $before = (int) Database::scalar('SELECT COUNT(*) FROM permit_stages');
    $bad = $input;
    $bad['movement_id'][1] = $other['id'];
    try {
        PermitQuickEntry::save($bad);
        throw new RuntimeException('فشل: قُبلت حركة لا تتبع الإذن.');
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), 'لا تتبع الإذن')) { throw $e; }
    }
    $after = (int) Database::scalar('SELECT COUNT(*) FROM permit_stages');
    if ($before !== $after) { throw new RuntimeException('فشل: حدث حفظ جزئي عند وجود صف خاطئ.'); }

    $badQty = $input;
    $badQty['qty'][0] = '0';
    try {
        PermitQuickEntry::save($badQty);
        throw new RuntimeException('فشل: قُبلت كمية صفر.');
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), 'الكمية')) { throw $e; }
    }

    // رندر الداشبورد الكامل: الزر، شاشة الإدخال، والمراجعة موجودون.
    $_SERVER['REQUEST_URI'] = '/';
    ob_start();
    (new DashboardController())->index();
    $html = ob_get_clean();
    foreach (['quickEntryOpen','quickEntryModal','qePermitBrowser','qeMovementBrowser','qe-kind-switch','data-kind="receipt"','data-kind="expense"','اختيار تلقائي','الشركة','الحاويات','name="qty[]"','مراجعة الإدخالات','تطبيق داخل الأذونات'] as $needle) {
        if (!str_contains($html, $needle)) { throw new RuntimeException('فشل رندر واجهة الإدخال السريع: '.$needle); }
    }
    preg_match_all('~<script(?:\s[^>]*)?>(.*?)</script>~s', $html, $scripts);
    $quickJs = '';
    foreach ($scripts[1] ?? [] as $script) {
        if (str_contains($script, 'quickEntryModal')) { $quickJs = $script; break; }
    }
    if ($quickJs === '') { throw new RuntimeException('تعذر استخراج JavaScript الإدخال السريع من الرندر.'); }

    if (in_array('--js', $argv ?? [], true)) {
        $pdo->rollBack();
        echo $quickJs;
        exit(0);
    }

    $pdo->rollBack();
    echo '✓ حُفظ إيصال بكمية 2 ومصروف تجريبيان داخل '.$target['permit_no'].' ثم أُلغي الاختبار' . "\n";
    echo "✓ بطاقة الإذن تعرض الشركة وتصنيف الحاويات، ورُفضت الكمية صفر\n";
    echo "✓ الحركة بطاقة باختيار تلقائي للوحيدة، ونوع البند زران مباشران\n";
    echo "✓ حركة من إذن آخر ألغت الدفعة كلها بلا حفظ جزئي\n";
    echo "✓ زر الداشبورد وشاشتا الإدخال والمراجعة ظهرت في الرندر الكامل\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    throw $e;
}
