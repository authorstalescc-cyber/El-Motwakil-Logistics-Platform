<?php
namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use RuntimeException;

/**
 * تحويل عهدة من مندوب لمندوب (v46) — المندوب بيسلّم كاش من عهدته لزميله.
 *
 * ⚠️ الأثر الوحيد: **عهدة المُرسِل تنقص وعهدة المُستقبِل تزيد بنفس المبلغ.**
 *    مفيش أي أثر على الخزنة ولا المكتب ولا الفواتير — المال بيتحرّك جوّه محافظ
 *    المناديب بس (زي «تنزيل العهدة» و«تسليم السائق»). فمجموع الفلوس اللي برّه
 *    مع كل المناديب **ثابت** بعد أي تحويل (بينتقل من محفظة لمحفظة).
 *
 * ⚠️ **العزل بالبناء زي `rep_driver_handovers` (v45):** جدول خاص
 *    `rep_custody_transfers` كل كود الخزنة عمره ما سمع عنه — فمفيش «استثناء»
 *    لازم نفتكره في أماكن الخزنة. `bin/verify_v46.php` بيحرس ده.
 *
 * ⚠️ **بيتسجّل فورًا بلا مراجعة** (زي تسليم السائق — التحويل حصل فعلاً في الميدان).
 *    الحماية: (أ) المُرسِل ما ينفعش يبعت أكتر من رصيده؛ (ب) الحذف مرفوض لو
 *    المُستقبِل صرف المبلغ (رصيده هيبقى سالب)؛ (ج) المالك بيراجع الكل من كارت
 *    المندوب ويقدر يحذف.
 */
class RepTransfer
{
    private const MAX_AMOUNT = 999999999999.99;

    /** هل الجدول موجود؟ (يخلّي النظام يشتغل عادي قبل migrate_v46) */
    public static function available(): bool
    {
        static $ok = null;
        if ($ok === null) {
            $ok = (bool) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'rep_custody_transfers'"
            );
        }
        return $ok;
    }

    // ─────────────────────────────────────────────── الكتابة

    /**
     * تسجيل تحويل. بيقفل المُرسِل ويتأكد إن رصيده يكفي، ثم بيسجّل.
     *
     * $d: to_rep_id, amount, transfer_date, note
     * بيرجع id السطر.
     *
     * @throws RepSubmissionValidationException لو أي حقل غلط (رسايل عربية للمندوب)
     */
    public static function create(int $fromRepId, array $d): int
    {
        if (!self::available()) {
            throw new RuntimeException('شغّل php bin/migrate_v46.php الأول.');
        }

        $toRepId = (int) ($d['to_rep_id'] ?? 0);
        $errors = [];

        if ($toRepId <= 0) {
            $errors['to_rep_id'] = 'اختر المندوب اللي بتحوّل له.';
        } elseif ($toRepId === $fromRepId) {
            $errors['to_rep_id'] = 'ما ينفعش تحوّل لنفسك.';
        } elseif (Database::scalar("SELECT status FROM reps WHERE id = ?", [$toRepId]) !== 'active') {
            $errors['to_rep_id'] = 'المندوب ده مش موجود أو متوقف.';
        }

        $raw = strtr(ar2en_digits(trim((string) ($d['amount'] ?? ''))), [
            ',' => '', '٬' => '', ' ' => '', '٫' => '.',
        ]);
        $amount = round((float) $raw, 2);
        if ($raw === '' || $amount <= 0) { $errors['amount'] = 'اكتب المبلغ اللي بتحوّله.'; }
        elseif ($amount > self::MAX_AMOUNT) { $errors['amount'] = 'المبلغ أكبر من الحد المسموح.'; }

        $date = trim((string) ($d['transfer_date'] ?? ''));
        if (!self::validDate($date)) { $errors['transfer_date'] = 'اكتب تاريخ تحويل صحيح.'; }
        elseif ($date > date('Y-m-d')) { $errors['transfer_date'] = 'تاريخ التحويل ما ينفعش يكون في المستقبل.'; }

        $note = preg_replace('/\s+/u', ' ', trim((string) ($d['note'] ?? '')));
        if (mb_strlen($note) > 255) { $errors['note'] = 'الملاحظة لازم تكون 255 حرف أو أقل.'; }

        if ($errors) { throw new RepSubmissionValidationException($errors); }

        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            // قفل المُرسِل ثم حساب رصيده بعد القفل: تحويلان متزامنان ما يقروش نفس
            // الرصيد القديم فيبعت المندوب أكتر مما معاه.
            Database::row('SELECT id FROM reps WHERE id = ? FOR UPDATE', [$fromRepId]);
            $bal = RepCustody::balance($fromRepId);
            if ($amount > $bal + 0.005) {
                throw new RepSubmissionValidationException([
                    'amount' => 'المبلغ أكبر من اللي معاك (' . number_format(max(0, $bal), 2) . ' ج).',
                ]);
            }

            Database::exec(
                "INSERT INTO rep_custody_transfers
                    (from_rep_id, to_rep_id, amount, transfer_date, note, created_by)
                 VALUES (?,?,?,?,?,?)",
                [$fromRepId, $toRepId, $amount, $date, $note !== '' ? $note : null,
                 Auth::user() ?: (isset($_SESSION['rep_name']) ? 'مندوب: ' . $_SESSION['rep_name'] : null)]);
            $id = Database::lastId();

            $saved = self::find($id);
            Audit::log('create', 'rep_custody_transfers', $id, null, $saved,
                'تحويل عهدة ' . number_format($amount, 2, '.', ',') . ' من '
                . (RepCustody::repName($fromRepId) ?: '#' . $fromRepId) . ' لـ '
                . (RepCustody::repName($toRepId) ?: '#' . $toRepId),
                ['rep_id' => $fromRepId, 'to_rep_id' => $toRepId, 'record_date' => $date]);

            if ($own) { $pdo->commit(); }
            return $id;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /**
     * حذف تحويل. بيترفض لو المُستقبِل صرف المبلغ (إلغاؤه هيخلّي رصيده سالب).
     */
    public static function delete(int $id): void
    {
        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            $row = Database::row('SELECT * FROM rep_custody_transfers WHERE id = ? FOR UPDATE', [$id]);
            if (!$row) { if ($own) { $pdo->commit(); } return; }

            $toRep = (int) $row['to_rep_id'];
            $amount = round((float) $row['amount'], 2);
            Database::row('SELECT id FROM reps WHERE id = ? FOR UPDATE', [$toRep]);
            // بعد شيل التحويل، رصيد المُستقبِل هيقلّ بالمبلغ. لو ده هيوديه تحت الصفر
            // يبقى صرفه فعلاً — نمنع الحذف عشان ما نكسرش حسابه.
            if (RepCustody::balance($toRep) - $amount < -0.005) {
                throw new RuntimeException('المندوب المستقبِل صرف المبلغ ده — مش هينفع تلغي التحويل.');
            }

            Database::exec('DELETE FROM rep_custody_transfers WHERE id = ?', [$id]);
            Audit::log('delete', 'rep_custody_transfers', $id, $row, null,
                'حذف تحويل عهدة ' . number_format($amount, 2, '.', ',') . ' من '
                . (RepCustody::repName((int) $row['from_rep_id']) ?: '#' . $row['from_rep_id']) . ' لـ '
                . (RepCustody::repName($toRep) ?: '#' . $toRep),
                ['rep_id' => (int) $row['from_rep_id'], 'record_date' => $row['transfer_date']]);

            if ($own) { $pdo->commit(); }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    // ─────────────────────────────────────────────── القراءة

    public static function find(int $id): ?array
    {
        if (!self::available()) { return null; }
        return Database::row("SELECT * FROM rep_custody_transfers WHERE id = ?", [$id]);
    }

    /** تحويل يخصّ مندوب مُرسِل (للتأكد قبل ما يمسحه من موبايله). */
    public static function findFrom(int $fromRepId, int $id): ?array
    {
        if (!self::available()) { return null; }
        return Database::row(
            "SELECT * FROM rep_custody_transfers WHERE id = ? AND from_rep_id = ?", [$id, $fromRepId]);
    }

    /** إجمالي اللي حوّله المندوب لغيره (يتخصم من عهدته). */
    public static function outTotal(int $repId): float
    {
        if (!self::available()) { return 0.0; }
        return round((float) Database::scalar(
            "SELECT COALESCE(SUM(amount),0) FROM rep_custody_transfers WHERE from_rep_id = ?", [$repId]), 2);
    }

    /** إجمالي اللي استلمه المندوب من غيره (يتزاد لعهدته). */
    public static function inTotal(int $repId): float
    {
        if (!self::available()) { return 0.0; }
        return round((float) Database::scalar(
            "SELECT COALESCE(SUM(amount),0) FROM rep_custody_transfers WHERE to_rep_id = ?", [$repId]), 2);
    }

    /** [rep_id => إجمالي محوَّل] — لصفحة الكروت بلا استعلام لكل مندوب. */
    public static function outTotalsByRep(): array
    {
        return self::totalsBy('from_rep_id');
    }

    /** [rep_id => إجمالي مستلَم] — لصفحة الكروت. */
    public static function inTotalsByRep(): array
    {
        return self::totalsBy('to_rep_id');
    }

    private static function totalsBy(string $col): array
    {
        if (!self::available()) { return []; }
        $out = [];
        foreach (Database::all(
            "SELECT $col rid, SUM(amount) total FROM rep_custody_transfers GROUP BY $col") as $r) {
            $out[(int) $r['rid']] = round((float) $r['total'], 2);
        }
        return $out;
    }

    /**
     * الإجمالي الكلي المحوَّل (= المستلَم؛ كل تحويل ليه طرفان). للتوثيق والتحقق —
     * بيدخل totalOutstanding بطرفيه فيلغي بعضه (التحويل ما بيغيّرش الإجمالي).
     */
    public static function totalAll(): float
    {
        if (!self::available()) { return 0.0; }
        return round((float) Database::scalar("SELECT COALESCE(SUM(amount),0) FROM rep_custody_transfers"), 2);
    }

    /** تحويلات مندوب مُرسِل (الأحدث أولاً) + اسم المستقبِل — لكشفه وموبايله. */
    public static function outgoingFor(int $repId, int $limit = 0): array
    {
        if (!self::available()) { return []; }
        $lim = $limit > 0 ? ' LIMIT ' . (int) $limit : '';
        return Database::all(
            "SELECT t.*, r.name AS other_name
             FROM rep_custody_transfers t JOIN reps r ON r.id = t.to_rep_id
             WHERE t.from_rep_id = ? ORDER BY t.transfer_date DESC, t.id DESC$lim", [$repId]);
    }

    /** تحويلات وصلت لمندوب مُستقبِل (الأحدث أولاً) + اسم المُرسِل — لكشفه. */
    public static function incomingFor(int $repId, int $limit = 0): array
    {
        if (!self::available()) { return []; }
        $lim = $limit > 0 ? ' LIMIT ' . (int) $limit : '';
        return Database::all(
            "SELECT t.*, r.name AS other_name
             FROM rep_custody_transfers t JOIN reps r ON r.id = t.from_rep_id
             WHERE t.to_rep_id = ? ORDER BY t.transfer_date DESC, t.id DESC$lim", [$repId]);
    }

    private static function validDate(string $v): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { return false; }
        [$y, $m, $d] = array_map('intval', explode('-', $v));
        return checkdate($m, $d, $y);
    }
}
