<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** اختبار آمن: سعة الحاويات تُحسب على مستوى الحركة المطابق للعداد الظاهر. */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\PermitController;
use App\Core\Auth;
use App\Core\Database;
use App\Models\Permit;

Auth::start();
$user = Database::row('SELECT id FROM users ORDER BY id LIMIT 1');
if (!$user) { throw new RuntimeException('لا يوجد مستخدم للاختبار.'); }
$_SESSION['uid'] = (int)$user['id'];
$_SESSION['uname'] = 'اختبار سعة الحركة';
$_SESSION['work_date'] = date('Y-m-d');

$pdo = Database::conn();
$pdo->beginTransaction();
try {
    // نلتقط حركة ممتلئة في إذن إجماله ممتلئ، ثم ننقل منها حاوية مؤقتًا داخل نفس الإذن
    // لنصنع حالة 6/7 نفسها. كل ذلك يُلغى في نهاية الاختبار.
    $target = Database::row(
        "SELECT pm.id movement_id,pm.permit_id,pm.seq,pm.container_count movement_total,
                p.permit_no,p.containers permit_total,
                (SELECT COALESCE(SUM(b.container_count),0) FROM bawalis b WHERE b.movement_id=pm.id) movement_used,
                (SELECT COALESCE(SUM(b.container_count),0) FROM bawalis b WHERE b.permit_id=pm.permit_id) permit_used
           FROM permit_movements pm JOIN permits p ON p.id=pm.permit_id
          WHERE pm.container_count>0
            AND (SELECT COALESCE(SUM(b.container_count),0) FROM bawalis b WHERE b.movement_id=pm.id) >= pm.container_count
            AND (SELECT COALESCE(SUM(b.container_count),0) FROM bawalis b WHERE b.permit_id=pm.permit_id) >= p.containers
          ORDER BY pm.id LIMIT 1"
    );
    if (!$target) { throw new RuntimeException('لا توجد حركة ممتلئة مناسبة للاختبار.'); }
    $movedBill = Database::row(
        'SELECT id,container_count FROM bawalis WHERE movement_id=? AND container_count>0 ORDER BY id DESC LIMIT 1',
        [(int)$target['movement_id']]
    );
    if (!$movedBill || (int)$movedBill['container_count'] !== 1) {
        throw new RuntimeException('الاختبار يحتاج بوليصة بحاوية واحدة لصنع حالة المتبقي.');
    }
    $otherMovement = (int) Database::scalar(
        'SELECT id FROM permit_movements WHERE permit_id=? AND id<>? ORDER BY seq,id LIMIT 1',
        [(int)$target['permit_id'], (int)$target['movement_id']]
    );
    if (!$otherMovement) {
        $otherMovement = Permit::createMovement((int)$target['permit_id'], 'حركة اختبار مؤقتة', 1, null);
    }
    Database::exec('UPDATE bawalis SET movement_id=? WHERE id=?', [$otherMovement, (int)$movedBill['id']]);

    $capacity = Permit::billContainerCapacity((int)$target['permit_id'], (int)$target['movement_id']);
    if ($capacity['scope'] !== 'movement' || $capacity['left'] !== 1) {
        throw new RuntimeException('فشل: حساب السعة لم يستخدم الحركة أو لم يجد المتبقي 1.');
    }

    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'xmlhttprequest';
    $billNo = '__MOVE_CAP_' . bin2hex(random_bytes(5));
    $_POST = [
        '_csrf'=>Auth::csrf(), 'movement_id'=>(string)$target['movement_id'], 'bill_date'=>'',
        'bill_number'=>$billNo, 'container_count'=>'1', 'discount'=>'0',
        'freight'=>'0', 'custody'=>'0', 'transfer'=>'0', 'overnight_collection'=>'0',
    ];
    ob_start();
    (new PermitController())->createBill((string)$target['permit_id']);
    $okResponse = ob_get_clean();
    $created = Database::row('SELECT id,movement_id,container_count FROM bawalis WHERE bill_number=?', [$billNo]);
    if (!$created || (int)$created['movement_id'] !== (int)$target['movement_id'] || (int)$created['container_count'] !== 1) {
        throw new RuntimeException('فشل: البوليصة لم تُضف إلى الحركة رغم وجود سعة ظاهرة.');
    }
    if (!str_contains($okResponse, '"ok":true')) {
        throw new RuntimeException('فشل: رد الإضافة لم يرجع نجاحًا.');
    }

    // بعد ملء آخر مكان، أي حاوية إضافية يجب أن تُرفض على مستوى الحركة نفسها.
    $overNo = $billNo . '_OVER';
    $_POST['bill_number'] = $overNo;
    ob_start();
    (new PermitController())->createBill((string)$target['permit_id']);
    $blockedResponse = ob_get_clean();
    if (Database::scalar('SELECT id FROM bawalis WHERE bill_number=?', [$overNo])) {
        throw new RuntimeException('فشل: تم تجاوز سعة الحركة بعد اكتمالها.');
    }
    if (!str_contains($blockedResponse, '"ok":false') || !str_contains($blockedResponse, 'الحركة')) {
        throw new RuntimeException('فشل: رسالة تجاوز سعة الحركة غير صحيحة.');
    }

    $pdo->rollBack();
    echo '✓ الإذن: '.$target['permit_no']."\n";
    echo '✓ الحركة '.$target['seq'].': '.($target['movement_total']-1).'/'.$target['movement_total'].' سمحت بآخر حاوية متاحة' . "\n";
    echo "✓ بعد الاكتمال رُفض التجاوز على مستوى الحركة\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    throw $e;
}
