<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v41 — سلامة علاقات المناديب ومنع ازدواج الاعتماد.
 * إضافي بحت: قيود وفهارس فقط، بلا تعديل بيانات أو أرقام.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;
use App\Models\RepCustody;

function v41IndexExists(string $table, string $index): bool
{
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?',
        [$table, $index]
    );
}

function v41ConstraintExists(string $table, string $constraint): bool
{
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name=? AND constraint_name=?',
        [$table, $constraint]
    );
}

function v41AddUnique(string $table, string $name, string $column): void
{
    if (!v41IndexExists($table, $name)) {
        Database::exec("ALTER TABLE `$table` ADD UNIQUE KEY `$name` (`$column`)");
        echo "  + $table.$name\n";
    }
}

function v41AddFk(string $table, string $name, string $column, string $parent, string $onDelete): void
{
    if (!v41ConstraintExists($table, $name)) {
        Database::exec(
            "ALTER TABLE `$table` ADD CONSTRAINT `$name` FOREIGN KEY (`$column`) REFERENCES `$parent` (`id`) ON DELETE $onDelete"
        );
        echo "  + $table.$name\n";
    }
}

$cashBefore = Closing::currentBalance();
$repBefore = RepCustody::totalOutstanding();
$invoiceBefore = (string) Database::scalar("SELECT COALESCE(SUM(total),0) FROM permit_invoices WHERE status<>'void'");

$orphanChecks = [
    'rep_entries.rep_id' => 'SELECT COUNT(*) FROM rep_entries c LEFT JOIN reps p ON p.id=c.rep_id WHERE p.id IS NULL',
    'rep_entries.settlement_id' => 'SELECT COUNT(*) FROM rep_entries c LEFT JOIN rep_settlements p ON p.id=c.settlement_id WHERE c.settlement_id IS NOT NULL AND p.id IS NULL',
    'rep_settlements.rep_id' => 'SELECT COUNT(*) FROM rep_settlements c LEFT JOIN reps p ON p.id=c.rep_id WHERE p.id IS NULL',
    'rep_custody_deposits.rep_id' => 'SELECT COUNT(*) FROM rep_custody_deposits c LEFT JOIN reps p ON p.id=c.rep_id WHERE p.id IS NULL',
    'rep_custody_settlements.rep_id' => 'SELECT COUNT(*) FROM rep_custody_settlements c LEFT JOIN reps p ON p.id=c.rep_id WHERE p.id IS NULL',
    'rep_custody_settlements.custody_id' => 'SELECT COUNT(*) FROM rep_custody_settlements c LEFT JOIN manual_custody p ON p.id=c.custody_id WHERE c.custody_id IS NOT NULL AND p.id IS NULL',
    'rep_users.rep_id' => 'SELECT COUNT(*) FROM rep_users c LEFT JOIN reps p ON p.id=c.rep_id WHERE p.id IS NULL',
    'permit_assignments.permit_id' => 'SELECT COUNT(*) FROM permit_assignments c LEFT JOIN permits p ON p.id=c.permit_id WHERE p.id IS NULL',
    'permit_assignments.rep_id' => 'SELECT COUNT(*) FROM permit_assignments c LEFT JOIN reps p ON p.id=c.rep_id WHERE p.id IS NULL',
    'rep_submissions.rep_id' => 'SELECT COUNT(*) FROM rep_submissions c LEFT JOIN reps p ON p.id=c.rep_id WHERE p.id IS NULL',
    'rep_submissions.permit_id' => 'SELECT COUNT(*) FROM rep_submissions c LEFT JOIN permits p ON p.id=c.permit_id WHERE c.permit_id IS NOT NULL AND p.id IS NULL',
    'rep_submissions.movement_id' => 'SELECT COUNT(*) FROM rep_submissions c LEFT JOIN permit_movements p ON p.id=c.movement_id WHERE c.movement_id IS NOT NULL AND p.id IS NULL',
    'rep_submissions.stage_id' => 'SELECT COUNT(*) FROM rep_submissions c LEFT JOIN permit_stages p ON p.id=c.stage_id WHERE c.stage_id IS NOT NULL AND p.id IS NULL',
    'rep_submissions.misc_id' => 'SELECT COUNT(*) FROM rep_submissions c LEFT JOIN rep_misc_expenses p ON p.id=c.misc_id WHERE c.misc_id IS NOT NULL AND p.id IS NULL',
    'permit_stages.paid_by_rep_id' => 'SELECT COUNT(*) FROM permit_stages c LEFT JOIN reps p ON p.id=c.paid_by_rep_id WHERE c.paid_by_rep_id IS NOT NULL AND p.id IS NULL',
    'rep_misc_expenses.rep_id' => 'SELECT COUNT(*) FROM rep_misc_expenses c LEFT JOIN reps p ON p.id=c.rep_id WHERE p.id IS NULL',
    'rep_misc_expenses.submission_id' => 'SELECT COUNT(*) FROM rep_misc_expenses c LEFT JOIN rep_submissions p ON p.id=c.submission_id WHERE c.submission_id IS NOT NULL AND p.id IS NULL',
];
foreach ($orphanChecks as $label => $sql) {
    $count = (int) Database::scalar($sql);
    if ($count !== 0) { throw new RuntimeException("تعذّر v41: $label يحوي $count رابطًا يتيمًا."); }
}

$duplicateChecks = [
    'rep_submissions.stage_id' => 'SELECT COUNT(*) FROM (SELECT stage_id FROM rep_submissions WHERE stage_id IS NOT NULL GROUP BY stage_id HAVING COUNT(*)>1) x',
    'rep_submissions.misc_id' => 'SELECT COUNT(*) FROM (SELECT misc_id FROM rep_submissions WHERE misc_id IS NOT NULL GROUP BY misc_id HAVING COUNT(*)>1) x',
    'rep_misc_expenses.submission_id' => 'SELECT COUNT(*) FROM (SELECT submission_id FROM rep_misc_expenses WHERE submission_id IS NOT NULL GROUP BY submission_id HAVING COUNT(*)>1) x',
];
foreach ($duplicateChecks as $label => $sql) {
    $count = (int) Database::scalar($sql);
    if ($count !== 0) { throw new RuntimeException("تعذّر v41: $label يحوي $count تكرارًا."); }
}

v41AddUnique('rep_submissions', 'uq_sub_stage', 'stage_id');
v41AddUnique('rep_submissions', 'uq_sub_misc', 'misc_id');
v41AddUnique('rep_misc_expenses', 'uq_rme_submission', 'submission_id');

v41AddFk('rep_entries', 'fk_repentry_rep', 'rep_id', 'reps', 'RESTRICT');
v41AddFk('rep_entries', 'fk_repentry_settlement', 'settlement_id', 'rep_settlements', 'RESTRICT');
v41AddFk('rep_settlements', 'fk_repsettle_rep', 'rep_id', 'reps', 'RESTRICT');
v41AddFk('rep_custody_deposits', 'fk_rcdep_rep', 'rep_id', 'reps', 'RESTRICT');
v41AddFk('rep_custody_settlements', 'fk_rcset_rep', 'rep_id', 'reps', 'RESTRICT');
v41AddFk('rep_custody_settlements', 'fk_rcset_custody', 'custody_id', 'manual_custody', 'RESTRICT');
v41AddFk('rep_users', 'fk_repuser_rep', 'rep_id', 'reps', 'RESTRICT');
v41AddFk('permit_assignments', 'fk_assign_permit', 'permit_id', 'permits', 'RESTRICT');
v41AddFk('permit_assignments', 'fk_assign_rep', 'rep_id', 'reps', 'RESTRICT');
v41AddFk('rep_submissions', 'fk_sub_rep', 'rep_id', 'reps', 'RESTRICT');
v41AddFk('rep_submissions', 'fk_sub_permit', 'permit_id', 'permits', 'RESTRICT');
v41AddFk('rep_submissions', 'fk_sub_movement', 'movement_id', 'permit_movements', 'SET NULL');
v41AddFk('rep_submissions', 'fk_sub_stage', 'stage_id', 'permit_stages', 'RESTRICT');
v41AddFk('rep_submissions', 'fk_sub_misc', 'misc_id', 'rep_misc_expenses', 'RESTRICT');
v41AddFk('permit_stages', 'fk_stage_paid_rep', 'paid_by_rep_id', 'reps', 'RESTRICT');
v41AddFk('rep_misc_expenses', 'fk_rme_rep', 'rep_id', 'reps', 'RESTRICT');
v41AddFk('rep_misc_expenses', 'fk_rme_submission', 'submission_id', 'rep_submissions', 'RESTRICT');

$cashAfter = Closing::currentBalance();
$repAfter = RepCustody::totalOutstanding();
$invoiceAfter = (string) Database::scalar("SELECT COALESCE(SUM(total),0) FROM permit_invoices WHERE status<>'void'");
if (round($cashBefore, 2) !== round($cashAfter, 2)
    || round($repBefore, 2) !== round($repAfter, 2)
    || $invoiceBefore !== $invoiceAfter) {
    throw new RuntimeException('أوقف v41: تغير رصيد مالي أثناء إضافة القيود.');
}

echo "اكتمل v41: صفر أيتام، وروابط الاعتماد فريدة، والأرصدة لم تتغير.\n";
