<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** اختبار آمن لشاشة «استكمال بيانات الإيصالات» — يقرأ الناقص ويجرّب الاستكمال داخل transaction يُلغى. */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Permit;

Auth::start();
$user = Database::row('SELECT id FROM users ORDER BY id LIMIT 1');
if (!$user) { throw new RuntimeException('لا يوجد مستخدم للاختبار.'); }
$_SESSION['uid'] = (int)$user['id'];
$_SESSION['uname'] = 'اختبار الاستكمال';

// 1) تحقّق أن الاستعلام يعمل ضد المخطط الحقيقي — نجرّب على كل يومٍ فيه إيصالات ناقصة.
$day = Database::scalar(
    "SELECT entry_date FROM permit_stages
      WHERE stage_key='receipts' AND status<>'none' AND entry_date IS NOT NULL
        AND (actual_amount IS NULL OR ref_no IS NULL OR ref_no='')
      ORDER BY entry_date DESC LIMIT 1"
);
if (!$day) {
    echo "لا توجد إيصالات ناقصة في أي يوم — الاستعلام صحيح لكن لا بيانات لاختبار الاستكمال.\n";
    $rows = Permit::receiptsMissingData(date('Y-m-d'));
    echo "receiptsMissingData(today) نجح، الصفوف: " . count($rows) . "\n";
    exit(0);
}

$rows = Permit::receiptsMissingData($day);
echo "يوم عمل به نواقص: $day — عدد الإيصالات الناقصة: " . count($rows) . "\n";
if (!$rows) { throw new RuntimeException('تناقض: scalar وجد نواقص لكن الدالة أرجعت صفراً.'); }

$sample = $rows[0];
printf("عيّنة: إيصال #%d | إذن %s | qty=%d | amount=%s | actual=%s | ref=%s\n",
    $sample['id'], $sample['permit_no'] ?? '—', $sample['qty'],
    $sample['amount'], var_export($sample['actual_amount'], true), var_export($sample['ref_no'], true));

// 2) جرّب completeReceipt داخل transaction ثم ألغِه — لا يتغيّر أي شيء فعلاً.
$pdo = Database::conn();
$pdo->beginTransaction();
$ok = Permit::completeReceipt((int)$sample['id'], 123.50, 'TEST-REF-XYZ');
$after = Database::row('SELECT qty, actual_amount, ref_no FROM permit_stages WHERE id=?', [$sample['id']]);
$expected = round(123.50 * max(1, (int)$sample['qty']), 2);
$pdo->rollBack();

echo "completeReceipt رجّع: " . ($ok ? 'true' : 'false') . "\n";
echo "بعد التحديث (قبل الإلغاء): actual={$after['actual_amount']} ref={$after['ref_no']} (المتوقع actual=$expected)\n";

$passActual = (float)$after['actual_amount'] === (float)$expected;
$passRef    = $after['ref_no'] === 'TEST-REF-XYZ';

// تأكّد أن الإلغاء أعاد كل شيء
$restored = Database::row('SELECT actual_amount, ref_no FROM permit_stages WHERE id=?', [$sample['id']]);
$passRollback = ($restored['actual_amount'] === $sample['actual_amount']) && ($restored['ref_no'] === $sample['ref_no']);

echo "\nالنتيجة: "
    . ($passActual ? '✔ الفعلي=سعر×كمية  ' : '�’ الفعلي خطأ  ')
    . ($passRef ? '✔ رقم الإيصال  ' : '✗ رقم الإيصال  ')
    . ($passRollback ? '✔ الإلغاء أعاد الأصل (لا تغيير حقيقي)' : '✗ الإلغاء فشل!')
    . "\n";

exit($passActual && $passRef && $passRollback ? 0 : 1);
