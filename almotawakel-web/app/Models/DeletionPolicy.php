<?php
namespace App\Models;

use App\Core\Auth;
use App\Core\Database;

/** سياسة مركزية تمنع محو تاريخ المناديب والاعتمادات المالية. */
class DeletionPolicy
{
    /** @return array{invoices:int,submissions:int,assignments:int} */
    public static function permit(int $permitId): array
    {
        return [
            'invoices' => (int) Database::scalar(
                "SELECT COUNT(*) FROM permit_invoices WHERE permit_id=? AND status<>'void'", [$permitId]
            ),
            'submissions' => (int) Database::scalar(
                'SELECT COUNT(*) FROM rep_submissions WHERE permit_id=?', [$permitId]
            ),
            'assignments' => (int) Database::scalar(
                'SELECT COUNT(*) FROM permit_assignments WHERE permit_id=?', [$permitId]
            ),
        ];
    }

    public static function stageSubmission(int $stageId): ?array
    {
        return Database::row(
            'SELECT id,rep_id,status FROM rep_submissions WHERE stage_id=? LIMIT 1', [$stageId]
        );
    }

    /**
     * فكّ ارتباط بند الإذن بسجل اعتماد المندوب قبل حذفه (قرار المالك: الأدمن له حرية الحذف).
     * السجل بيفضل محفوظ بحالته `approved` — بس `stage_id` بيرجع NULL ومعاه ملاحظة إن البند اتحذف،
     * فلا التاريخ بيتمسح ولا البند بيرجع لصندوق الانتظار (الاعتماد بيطلب status='pending').
     */
    public static function detachStage(int $stageId): void
    {
        $rows = Database::all('SELECT * FROM rep_submissions WHERE stage_id=?', [$stageId]);
        foreach ($rows as $row) {
            Database::exec(
                "UPDATE rep_submissions
                    SET stage_id = NULL,
                        review_note = LEFT(TRIM(CONCAT(COALESCE(review_note,''), ' ', ?)), 255)
                  WHERE id = ?",
                ['[البند المرتبط اتحذف من الإذن بواسطة ' . (Auth::user() ?: 'الأدمن') . ']', (int) $row['id']]
            );
            Audit::log('update', 'rep_submissions', (int) $row['id'], $row,
                Database::row('SELECT * FROM rep_submissions WHERE id=?', [(int) $row['id']]),
                'فكّ ارتباط بند مندوب معتمَد بعد حذف بند الإذن #' . $stageId,
                ['rep_id' => (int) $row['rep_id'], 'record_date' => $row['entry_date']]);
        }
    }

    /** نفس الفكرة للنثريات: السجل يفضل، والربط يتفكّ. */
    public static function detachMisc(int $miscId): void
    {
        $rows = Database::all('SELECT * FROM rep_submissions WHERE misc_id=?', [$miscId]);
        foreach ($rows as $row) {
            Database::exec(
                "UPDATE rep_submissions
                    SET misc_id = NULL,
                        review_note = LEFT(TRIM(CONCAT(COALESCE(review_note,''), ' ', ?)), 255)
                  WHERE id = ?",
                ['[النثرية المرتبطة اتحذفت بواسطة ' . (Auth::user() ?: 'الأدمن') . ']', (int) $row['id']]
            );
            Audit::log('update', 'rep_submissions', (int) $row['id'], $row,
                Database::row('SELECT * FROM rep_submissions WHERE id=?', [(int) $row['id']]),
                'فكّ ارتباط نثرية معتمَدة بعد حذفها #' . $miscId,
                ['rep_id' => (int) $row['rep_id'], 'record_date' => $row['entry_date']]);
        }
    }

    public static function movementApprovedSubmissions(int $movementId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM rep_submissions WHERE movement_id=? AND (stage_id IS NOT NULL OR misc_id IS NOT NULL)',
            [$movementId]
        );
    }

    /** @return array<string,int> */
    public static function repHistory(int $repId): array
    {
        $sources = [
            'entries' => 'rep_entries',
            'salary_settlements' => 'rep_settlements',
            'custody_deposits' => 'rep_custody_deposits',
            'custody_settlements' => 'rep_custody_settlements',
            'assignments' => 'permit_assignments',
            'submissions' => 'rep_submissions',
            'stages' => 'permit_stages',
            'misc' => 'rep_misc_expenses',
        ];
        $out = [];
        foreach ($sources as $key => $table) {
            $column = $table === 'permit_stages' ? 'paid_by_rep_id' : 'rep_id';
            $out[$key] = (int) Database::scalar("SELECT COUNT(*) FROM `$table` WHERE `$column`=?", [$repId]);
        }
        return $out;
    }

    public static function hasRepHistory(int $repId): bool
    {
        return array_sum(self::repHistory($repId)) > 0;
    }

    /** @return array<string,int> */
    public static function orphanCounts(): array
    {
        $checks = [
            'assignment_permit' => 'SELECT COUNT(*) FROM permit_assignments c LEFT JOIN permits p ON p.id=c.permit_id WHERE p.id IS NULL',
            'assignment_rep' => 'SELECT COUNT(*) FROM permit_assignments c LEFT JOIN reps p ON p.id=c.rep_id WHERE p.id IS NULL',
            'submission_rep' => 'SELECT COUNT(*) FROM rep_submissions c LEFT JOIN reps p ON p.id=c.rep_id WHERE p.id IS NULL',
            'submission_permit' => 'SELECT COUNT(*) FROM rep_submissions c LEFT JOIN permits p ON p.id=c.permit_id WHERE c.permit_id IS NOT NULL AND p.id IS NULL',
            'submission_stage' => 'SELECT COUNT(*) FROM rep_submissions c LEFT JOIN permit_stages p ON p.id=c.stage_id WHERE c.stage_id IS NOT NULL AND p.id IS NULL',
            'submission_misc' => 'SELECT COUNT(*) FROM rep_submissions c LEFT JOIN rep_misc_expenses p ON p.id=c.misc_id WHERE c.misc_id IS NOT NULL AND p.id IS NULL',
            'stage_rep' => 'SELECT COUNT(*) FROM permit_stages c LEFT JOIN reps p ON p.id=c.paid_by_rep_id WHERE c.paid_by_rep_id IS NOT NULL AND p.id IS NULL',
            'misc_rep' => 'SELECT COUNT(*) FROM rep_misc_expenses c LEFT JOIN reps p ON p.id=c.rep_id WHERE p.id IS NULL',
        ];
        $out = [];
        foreach ($checks as $key => $sql) { $out[$key] = (int) Database::scalar($sql); }
        return $out;
    }
}
