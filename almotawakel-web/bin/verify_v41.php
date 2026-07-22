<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** اختبارات v41 التزامنية والحذف؛ تُرفض على قاعدة العمل. */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Database;
use App\Models\Closing;
use App\Models\DeletionPolicy;
use App\Models\Rep;
use App\Models\RepCustody;
use App\Models\RepInbox;

$database = (string) App::config('db')['name'];
if (!str_starts_with($database, 'almotawakel_test_')) {
    fwrite(STDERR, "يُرفض verify_v41 على قاعدة غير معزولة.\n");
    exit(2);
}
if (!function_exists('pcntl_fork')) {
    fwrite(STDERR, "امتداد pcntl مطلوب لاختبار التزامن الحقيقي.\n");
    exit(2);
}

$fail = [];
$check = function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? '✓ ' : '✗ ') . $label . PHP_EOL;
    if (!$ok) { $fail[] = $label; }
};
$snapshot = function (): array {
    return [
        round(Closing::currentBalance(), 2),
        round(RepCustody::totalOutstanding(), 2),
        (string) Database::scalar("SELECT COALESCE(SUM(total),0) FROM permit_invoices WHERE status<>'void'"),
    ];
};
$before = $snapshot();
$v41Constraints = (int) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE constraint_schema=DATABASE() AND constraint_name IN
     ('fk_repentry_rep','fk_repentry_settlement','fk_repsettle_rep','fk_rcdep_rep','fk_rcset_rep','fk_rcset_custody',
      'fk_repuser_rep','fk_assign_permit','fk_assign_rep','fk_sub_rep','fk_sub_permit','fk_sub_movement','fk_sub_stage',
      'fk_sub_misc','fk_stage_paid_rep','fk_rme_rep','fk_rme_submission','uq_sub_stage','uq_sub_misc','uq_rme_submission')"
);
$check($v41Constraints === 20, 'كل قيود v41 العشرين موجودة');

// كل طفل يفتح PDO مستقلًا. 0=نجاح، 3=رفض عمل متوقع، 1=خطأ غير متوقع.
$race = function (callable $operation): array {
    $children = [];
    for ($i = 0; $i < 2; $i++) {
        $pid = pcntl_fork();
        if ($pid === -1) { throw new RuntimeException('تعذّر fork.'); }
        if ($pid === 0) {
            Database::disconnect();
            usleep(180000);
            try { $operation(); exit(0); }
            catch (RuntimeException $e) { exit(3); }
            catch (Throwable $e) { exit(1); }
        }
        $children[] = $pid;
    }
    $codes = [];
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        $codes[] = pcntl_wexitstatus($status);
    }
    sort($codes);
    Database::disconnect();
    return $codes;
};

$suffix = bin2hex(random_bytes(4));
Database::exec(
    "INSERT INTO reps(name,status,note) VALUES(?, 'active', 'v41 fixture')", ['v41-rep-' . $suffix]
);
$repId = Database::lastId();
Database::exec(
    "INSERT INTO permits(permit_type,permit_no,permit_date,status,note) VALUES('sader',?,CURRENT_DATE,'open','v41 fixture')",
    ['V41-' . $suffix]
);
$permitId = Database::lastId();
Database::exec('INSERT INTO permit_movements(permit_id,seq,note) VALUES(?,1,?)', [$permitId, 'v41 fixture']);
$movementId = Database::lastId();
Database::exec(
    "INSERT INTO permit_assignments(permit_id,rep_id,status,note) VALUES(?,?,'active','v41 fixture')",
    [$permitId, $repId]
);
Database::exec(
    "INSERT INTO rep_submissions(rep_id,permit_id,movement_id,kind,note,qty,unit_amount,amount,entry_date,status)
     VALUES(?,?,?,'expense','v41 race',1,10,10,CURRENT_DATE,'pending')",
    [$repId, $permitId, $movementId]
);
$submissionId = Database::lastId();

$approveCodes = $race(fn() => RepInbox::approve($submissionId));
$check($approveCodes === [0, 3], 'اعتمادان متزامنان: واحد فقط ينجح والآخر يرى القرار السابق');
$sub = Database::row('SELECT * FROM rep_submissions WHERE id=?', [$submissionId]);
$check($sub && $sub['status'] === 'approved' && !empty($sub['stage_id']), 'التقديم مرتبط ببند معتمد واحد');
$check((int) Database::scalar('SELECT COUNT(*) FROM permit_stages WHERE id=?', [(int) ($sub['stage_id'] ?? 0)]) === 1,
    'لم يُنشأ بند مالي مكرر');
$check(DeletionPolicy::stageSubmission((int) $sub['stage_id']) !== null, 'سياسة الحذف تمنع محو بند اعتماد المندوب');
$check(DeletionPolicy::permit($permitId)['submissions'] === 1, 'سياسة حذف الإذن ترى تاريخ المندوب');
$check(RepCustody::balance($repId) === -10.0, 'الرصيد السالب مسموح ومحسوب كمستحق للمندوب');
$deleteResult = Rep::delete($repId);
$check($deleteResult['deactivated'] && !empty(Rep::find($repId)), 'حذف مندوب ذي تاريخ يتحول إلى تعطيل لا محو');
$check((Rep::find($repId)['status'] ?? '') === 'inactive', 'حالة المندوب أصبحت متوقفًا مع بقاء التاريخ');

// سباق التسوية: رصيد 100، ومحاولتان لإرجاع 80؛ الثانية يجب أن ترى الباقي 20.
Database::exec("INSERT INTO reps(name,status,note) VALUES(?,'active','v41 settle fixture')", ['v41-settle-' . $suffix]);
$settleRepId = Database::lastId();
RepCustody::createDeposit($settleRepId, 100, date('Y-m-d'), 'v41 race');
$settleCodes = $race(fn() => RepCustody::settle($settleRepId, 80, date('Y-m-d'), false, 'v41 race'));
$check($settleCodes === [0, 3], 'تسويتان متزامنتان: واحدة فقط تقبل مرتجع 80 من رصيد 100');
$check((int) Database::scalar('SELECT COUNT(*) FROM rep_custody_settlements WHERE rep_id=?', [$settleRepId]) === 1,
    'لا توجد تسوية مرتجع مكررة');
$check(RepCustody::balance($settleRepId) === 20.0, 'التسوية الثانية قرأت الرصيد بعد القفل وبقي 20');

$orphans = DeletionPolicy::orphanCounts();
$check(array_sum($orphans) === 0, 'صفر روابط يتيمة بعد سيناريوهات التزامن والتعطيل');
$cardSource = (string) file_get_contents(App::basePath('app/Views/rep_custody/card.php'));
$check(str_contains($cardSource, 'مستحق للمندوب'), 'الواجهة تسمي الرصيد السالب «مستحق للمندوب»');

// تنظيف fixtures مع فك روابط الاعتماد صراحة؛ قاعدة الاختبار قابلة لإعادة الفحص.
Database::exec('UPDATE rep_submissions SET stage_id=NULL WHERE id=?', [$submissionId]);
Database::exec('DELETE FROM permit_stages WHERE id=?', [(int) $sub['stage_id']]);
Database::exec('DELETE FROM rep_submissions WHERE id=?', [$submissionId]);
Database::exec('DELETE FROM permit_assignments WHERE permit_id=?', [$permitId]);
Database::exec('DELETE FROM permit_movements WHERE permit_id=?', [$permitId]);
Database::exec('DELETE FROM permits WHERE id=?', [$permitId]);
Database::exec('DELETE FROM reps WHERE id=?', [$repId]);
Database::exec('DELETE FROM rep_custody_settlements WHERE rep_id=?', [$settleRepId]);
Database::exec('DELETE FROM rep_custody_deposits WHERE rep_id=?', [$settleRepId]);
Database::exec('DELETE FROM reps WHERE id=?', [$settleRepId]);
Database::disconnect();

$check($snapshot() === $before, 'الخزنة وعهدة المناديب والفواتير عادت للقطة ما قبل الاختبار');
$check(array_sum(DeletionPolicy::orphanCounts()) === 0, 'صفر أيتام بعد التنظيف');

if ($fail) { echo 'فشل ' . count($fail) . " اختبار.\n"; exit(1); }
echo "اكتملت اختبارات v41 بنجاح.\n";
