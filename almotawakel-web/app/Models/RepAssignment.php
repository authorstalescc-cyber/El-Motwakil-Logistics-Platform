<?php
namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use RuntimeException;

/**
 * تنزيل الأذونات للمناديب (v35) — «الإذن ده مسؤول عنه فلان».
 *
 * ⚠️ وصفي بحت — مفيش أي أثر مالي. بيحدّد بس المندوب يشوف إيه على موبايله
 *    ويقدر يسجّل بنود على إيه. الربط بالـid **بلا FK** (تحذير 3).
 */
class RepAssignment
{
    /** الأذونات المنزّلة لمندوب (النشطة أولاً). */
    public static function forRep(int $repId): array
    {
        if (!RepCustody::available()) { return []; }
        return Database::all(
            /* ⚠️ `containers` بيتجاب هنا (v88ب) عشان البروفايل يقول «اشتغل على
               كام حاوية» — طلب المالك. عدد الإذن الرسمي هو نفسه اللي الكشف
               والضمان بيتحسبوا عليه، فمفيش تعريفان للحاوية. */
            "SELECT a.*, p.permit_no, p.permit_type, p.company, p.client, p.permit_date,
                    p.containers, p.status AS permit_status,
                    (SELECT COUNT(*) FROM rep_submissions s
                      WHERE s.permit_id = a.permit_id AND s.rep_id = a.rep_id) AS subs
             FROM permit_assignments a
             LEFT JOIN permits p ON p.id = a.permit_id
             WHERE a.rep_id = ?
             ORDER BY a.status='done', p.permit_date DESC, a.id DESC", [$repId]);
    }

    /**
     * الأذونات المتاحة للتنزيل: **المفتوحة بس** اللي ليها حركات ومش منزّلة للمندوب ده.
     * الإذن المقفول ما بيظهرش خالص — الشرط مفروض كمان على الخادم في `assign()`
     * عشان لو الإذن اتقفل بين ما الصفحة اتفتحت وما المالك دوس «نزّلهم له».
     */
    public static function available(int $repId): array
    {
        return Database::all(
            "SELECT p.id, p.permit_no, p.permit_type, p.company, p.client, p.permit_date,
                    p.containers, p.container_type, p.destination,
                    (SELECT COUNT(*) FROM permit_movements m WHERE m.permit_id = p.id) AS movs,
                    (SELECT COUNT(*) FROM permit_assignments a2
                      JOIN reps r2 ON r2.id = a2.rep_id
                     WHERE a2.permit_id = p.id AND a2.status = 'active') AS other_reps
             FROM permits p
             WHERE EXISTS (SELECT 1 FROM permit_movements m WHERE m.permit_id = p.id)
               AND p.status = 'open'
               AND NOT EXISTS (SELECT 1 FROM permit_assignments a
                               WHERE a.permit_id = p.id AND a.rep_id = ? AND a.status = 'active')
             ORDER BY p.permit_date DESC, p.id DESC
             LIMIT 300", [$repId]);
    }

    /**
     * نزّل إذن لمندوب. لو كان منزّل قبل كده وخلص، بيرجّعه نشط.
     *
     * ⚠️ **الإذن المقفول مرفوض** — الفورم مش مصدر ثقة (قاعدة `RepPortal::assertAssigned`):
     *    القائمة بتتبني وقت فتح الصفحة، والإذن ممكن يتقفل قبل ما المالك يدوس «نزّلهم له».
     */
    public static function assign(int $permitId, int $repId, string $note = ''): void
    {
        $p = Database::row("SELECT permit_no, permit_type, status FROM permits WHERE id = ?", [$permitId]);
        if (!$p) {
            throw new RuntimeException('الإذن مش موجود.');
        }
        if (($p['status'] ?? 'open') !== 'open') {
            throw new RuntimeException(
                'الإذن ' . ($p['permit_no'] ?: '#' . $permitId) . ' مقفول — افتحه الأول عشان تنزّله للمندوب.');
        }
        if (!Database::scalar("SELECT COUNT(*) FROM reps WHERE id = ?", [$repId])) {
            throw new RuntimeException('المندوب مش موجود.');
        }
        $exists = Database::row(
            "SELECT id FROM permit_assignments WHERE permit_id = ? AND rep_id = ?", [$permitId, $repId]);
        if ($exists) {
            Database::exec(
                "UPDATE permit_assignments SET status='active', done_at=NULL, note=?, assigned_by=?, assigned_at=NOW()
                 WHERE id=?",
                [$note !== '' ? $note : null, Auth::user(), (int) $exists['id']]);
            return;
        }
        Database::exec(
            "INSERT INTO permit_assignments (permit_id, rep_id, note, assigned_by) VALUES (?,?,?,?)",
            [$permitId, $repId, $note !== '' ? $note : null, Auth::user()]);
    }

    /** خلّص التنزيل (المندوب ما بقاش يشوف الإذن) — بيفضل في السجل. */
    public static function markDone(int $id): void
    {
        Database::exec(
            "UPDATE permit_assignments SET status='done', done_at=NOW() WHERE id=?", [$id]);
    }

    /** رجّع الإذن نشط للمندوب — نفس حارس `assign()`: المقفول مرفوض. */
    public static function reactivate(int $id): void
    {
        $p = Database::row(
            "SELECT p.permit_no, p.status FROM permit_assignments a
             JOIN permits p ON p.id = a.permit_id WHERE a.id = ?", [$id]);
        if ($p && ($p['status'] ?? 'open') !== 'open') {
            throw new RuntimeException(
                'الإذن ' . ($p['permit_no'] ?: '') . ' مقفول — افتحه الأول عشان ترجّعه نشط للمندوب.');
        }
        Database::exec(
            "UPDATE permit_assignments SET status='active', done_at=NULL WHERE id=?", [$id]);
    }

    /**
     * شيل التنزيل خالص. بيترفض لو المندوب سجّل عليه بنود — عشان السجل ما يتكسرش
     * (البنود بتفضل مرتبطة بالإذن والمندوب).
     */
    public static function remove(int $id): void
    {
        $a = Database::row("SELECT * FROM permit_assignments WHERE id = ?", [$id]);
        if (!$a) { return; }
        $subs = (int) Database::scalar(
            "SELECT COUNT(*) FROM rep_submissions WHERE permit_id = ? AND rep_id = ?",
            [(int) $a['permit_id'], (int) $a['rep_id']]);
        if ($subs > 0) {
            throw new RuntimeException(
                'المندوب سجّل ' . $subs . ' بند على الإذن ده. اقفل التنزيل بدل ما تشيله عشان السجل يفضل سليم.');
        }
        Database::exec("DELETE FROM permit_assignments WHERE id = ?", [$id]);
    }

    /** المناديب المنزّل لهم إذن معيّن (للعرض في صفحة الإذن). */
    public static function repsFor(int $permitId): array
    {
        if (!RepCustody::available()) { return []; }
        return Database::all(
            "SELECT a.*, r.name FROM permit_assignments a
             JOIN reps r ON r.id = a.rep_id
             WHERE a.permit_id = ? AND a.status='active' ORDER BY r.name", [$permitId]);
    }
}
