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

    /**
     * ⚠️⚠️ **المصدر الوحيد لـ«اليوم بتاع كل جهة» (v94).**
     *
     * التحويل الواحد ليه **يومان**، مش يوم واحد:
     *   • `transfer_date` = اليوم اللي الفلوس خرجت فيه من عهدة **المُرسِل**.
     *   • `to_date`       = اليوم اللي دخلت فيه عهدة **المُستقبِل**.
     * غالبًا هما نفس اليوم (تسليم في الميدان)، لكن مش دايمًا: كاش اتسلّم آخر
     * يوم ١٢ ممكن يدخل عهدة الزميل يوم ١٣. اليوم الواحد كان بيخلّي المالك
     * **مجبَر يغلّط في طرف عشان يظبط التاني**.
     *
     * ⚠️ **ممنوع تكتب اسم العمود بإيدك في أي استعلام.** أي جهة استقبال بتترشّح
     *    بـ`transfer_date` = عهدة يوم غلط عند المُستقبِل **بلا أي خطأ ظاهر**
     *    (الرصيد التراكمي بيفضل مظبوط، واليوميّة هي اللي بتبوظ).
     *
     * ⚠️ **قبل migrate_v94 بترجّع `transfer_date` للجهتين** فالنظام يفضل شغّالًا
     *    زي ما كان بالحرف.
     */
    public static function dayCol(string $side): string
    {
        return $side === 'to_rep_id' && self::toDateReady() ? 'to_date' : 'transfer_date';
    }

    /** كاش جاهزية عمود `to_date` — `null` = لسه ما اتقراش. */
    private static ?bool $toDateReady = null;

    /** هل عمود `to_date` اتضاف (migrate_v94)؟ */
    public static function toDateReady(): bool
    {
        if (self::$toDateReady === null) {
            self::$toDateReady = self::available() && (bool) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'rep_custody_transfers'
                   AND column_name = 'to_date'"
            );
        }
        return self::$toDateReady;
    }

    /**
     * ⚠️ **إلزامية في آخر أي ترحيل بيضيف عمودًا للجدول ده.** الكاش الساكن
     *    بيتقرا قبل الترحيل فبيفضل بالقيمة القديمة بعده، فتحقّق ما بعد الترحيل
     *    يفشل بإنذار كاذب (مصيدة v57 الموثَّقة).
     */
    public static function resetCache(): void
    {
        self::$toDateReady = null;
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
        /* ⚠️ قفل الصيانة (v68) — الحارس العميق. المسار ده بوابة المندوب وحدها
           (المكتب بيحوّل من `/rep-custody`)، فالقفل هنا ما بيعطّلش المكتب. */
        RepMaintenance::assertOpen();

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
        // v49: التحويل بيحرّك عهدة يومه عند الطرفين، فاليوم المتقفل مرفوض.
        elseif (($lock = RepCustody::dayLockReason($date)) !== null) { $errors['transfer_date'] = $lock; }

        /* v94: يوم عهدة المُستقبِل. الغايب = نفس يوم المُرسِل (الحالة الشايعة،
           وهي اللي بوابة المندوب بتبعتها). */
        $toDate = trim((string) ($d['to_date'] ?? ''));
        if ($toDate === '') { $toDate = $date; }
        self::checkToDate($toDate, $date, $errors);

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

            $by = Auth::user() ?: (isset($_SESSION['rep_name']) ? 'مندوب: ' . $_SESSION['rep_name'] : null);
            if (self::toDateReady()) {
                Database::exec(
                    "INSERT INTO rep_custody_transfers
                        (from_rep_id, to_rep_id, amount, transfer_date, to_date, note, created_by)
                     VALUES (?,?,?,?,?,?,?)",
                    [$fromRepId, $toRepId, $amount, $date, $toDate, $note !== '' ? $note : null, $by]);
            } else {
                Database::exec(
                    "INSERT INTO rep_custody_transfers
                        (from_rep_id, to_rep_id, amount, transfer_date, note, created_by)
                     VALUES (?,?,?,?,?,?)",
                    [$fromRepId, $toRepId, $amount, $date, $note !== '' ? $note : null, $by]);
            }
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
     * **تعديل تحويل قايم** — المُرسِل · المُستقبِل · المبلغ · التاريخ · الملاحظة.
     *
     * ⚠️⚠️ **التعديل بينقل الفلوس مش بيكرّرها.** الصف واحد بيوصف الاتجاهين
     *    (−من المُرسِل · +للمُستقبِل)، فـ`UPDATE` واحدة بتحرّك الطرفين مع بعض —
     *    مستحيل مبلغ يتحسب مرتين أو يضيع من الاتنين. **ممنوع** يتعمل بحذف
     *    وإنشاء: بينهم لحظة رصيد المُستقبِل فيها ناقص، وأي فحص متزامن بيقرا رقمًا
     *    غلط، وسجل التدقيق بيبان كأنه عمليتين.
     *
     * ⚠️ **إعادة التوجيه من الطرفين مسموحة:** `from_rep_id` **و**`to_rep_id`
     *    الاتنين قابلين للتغيير في نفس التعديل (المالك سجّل التحويل على مندوب
     *    غلط أصلاً). فالتعديل الواحد ممكن يمسّ **٤ محافظ**: المُرسِل القديم
     *    والجديد والمُستقبِل القديم والجديد. لو الحقل مش مبعوت في `$d` بيفضل
     *    على قيمته القديمة (تعديل الملاحظة لوحدها ما بيلمسش حد).
     *
     * ⚠️ **حارس واحد موحَّد بدل حارسين منفصلين للطرفين:** بنحسب **صافي التغيّر
     *    على كل محفظة** (`رجوع الصف القديم` + `تطبيق الصف الجديد`)، وأي محفظة
     *    هتقع تحت الصفر بترفض التعديل باسم صاحبها. الطريقة دي بتغطّي الحالات
     *    اللي حارسان منفصلان بيفوّتوها — زي تحويل المُرسِل يبقى هو المُستقبِل
     *    القديم (الرجوع والتطبيق بيتقاصّوا على نفس المحفظة).
     *    والحُرّاس الباقية معاه: **اليومان** (القديم والجديد) لازم يكونوا
     *    مفتوحين، لأن التعديل بيغيّر عهدة اليومين (قاعدة v49).
     *
     * ⚠️ القفل بيتاخد على المناديب **بترتيب الـid تصاعديًّا** — تعديلان متزامنان
     *    على نفس المحفظتين بترتيب معكوس = deadlock.
     *
     * ⚠️ الحُرّاس هنا في **الموديل** مش في الكنترولر — الفورم مش مصدر ثقة،
     *    و`from_rep_id` الجاي من `<select>` ممكن يتزوّر زي أي قيمة تانية.
     *
     * @throws RepSubmissionValidationException رسايل عربية لكل حقل
     */
    public static function update(int $id, array $d): void
    {
        if (!self::available()) {
            throw new RuntimeException('شغّل php bin/migrate_v46.php الأول.');
        }
        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            $old = Database::row('SELECT * FROM rep_custody_transfers WHERE id = ? FOR UPDATE', [$id]);
            if (!$old) { throw new RuntimeException('التحويل ده مش موجود.'); }

            $oldFrom   = (int) $old['from_rep_id'];
            $oldTo     = (int) $old['to_rep_id'];
            $oldAmount = round((float) $old['amount'], 2);
            $oldDate   = (string) $old['transfer_date'];
            $oldToDate = (string) ($old['to_date'] ?? $old['transfer_date']);

            /* الحقل الغايب = «ما اتغيّرش» — تعديل الملاحظة لوحدها ما يحرّكش قرشًا. */
            $fromRepId = self::pickRep($d, 'from_rep_id', $oldFrom);
            $toRepId   = self::pickRep($d, 'to_rep_id', $oldTo);
            $errors    = [];

            if ($toRepId <= 0) {
                $errors['to_rep_id'] = 'اختر المندوب اللي بتحوّل له.';
            } elseif ($toRepId === $fromRepId) {
                $errors['to_rep_id'] = 'ما ينفعش تحوّل لنفسك.';
            } elseif (Database::scalar("SELECT status FROM reps WHERE id = ?", [$toRepId]) !== 'active') {
                $errors['to_rep_id'] = 'المندوب ده مش موجود أو متوقف.';
            }

            /* ⚠️ المُرسِل: **الموقوف مرفوض بس لو اتغيّر**. تحويل قديم صاحبه اتوقف
               لازم يفضل قابل لتصليح مبلغه وتاريخه — الشرط الصارم كان هيقفل
               تعديله للأبد بلا سبب مفهوم. */
            if ($fromRepId <= 0) {
                $errors['from_rep_id'] = 'اختر المندوب اللي حوّل من عهدته.';
            } elseif ($fromRepId !== $oldFrom
                      && Database::scalar("SELECT status FROM reps WHERE id = ?", [$fromRepId]) !== 'active') {
                $errors['from_rep_id'] = 'المندوب ده مش موجود أو متوقف.';
            } elseif ($fromRepId === $oldFrom
                      && !Database::scalar("SELECT id FROM reps WHERE id = ?", [$fromRepId])) {
                $errors['from_rep_id'] = 'المندوب ده مش موجود.';
            }

            /* ⚠️ **«الغايب = ما اتغيّرش» بتسري على كل حقل** — مش على المندوبين
               والتواريخ بس. الحقل اللي مالوش القاعدة دي بيبقى فخّ: تعديل جزئي
               (المبلغ لوحده مثلًا) بيوصل الملاحظة فاضية فتتمسح **بالسكوت**.
               الفاضي المبعوت صراحةً لسه بيترفض للمبلغ (صفر مش تحويل). */
            if (array_key_exists('amount', $d)) {
                $raw = strtr(ar2en_digits(trim((string) ($d['amount'] ?? ''))), [
                    ',' => '', '٬' => '', ' ' => '', '٫' => '.',
                ]);
                $amount = round((float) $raw, 2);
                if ($raw === '' || $amount <= 0) { $errors['amount'] = 'اكتب المبلغ اللي بتحوّله.'; }
                elseif ($amount > self::MAX_AMOUNT) { $errors['amount'] = 'المبلغ أكبر من الحد المسموح.'; }
            } else {
                $amount = $oldAmount;
            }

            $date = self::pickDate($d, 'transfer_date', $oldDate);
            if (!self::validDate($date)) { $errors['transfer_date'] = 'اكتب تاريخ تحويل صحيح.'; }
            elseif ($date > date('Y-m-d')) { $errors['transfer_date'] = 'تاريخ التحويل ما ينفعش يكون في المستقبل.'; }
            else {
                // ⚠️ اليومان: القديم بيتفضّى والجديد بيتملا، فالاتنين لازم مفتوحين.
                foreach (array_unique([$oldDate, $date]) as $chk) {
                    if (($lock = RepCustody::dayLockReason($chk)) !== null) {
                        $errors['transfer_date'] = $lock; break;
                    }
                }
            }

            /* v94 — يوم عهدة المُستقبِل. نفس منطق «الغايب = ما اتغيّرش»،
               ونفس حارس القفل على اليوم القديم والجديد. */
            $toDate = self::pickDate($d, 'to_date', $oldToDate);
            self::checkToDate($toDate, $date, $errors, $oldToDate);

            $note = array_key_exists('note', $d)
                ? preg_replace('/\s+/u', ' ', trim((string) ($d['note'] ?? '')))
                : (string) ($old['note'] ?? '');
            if (mb_strlen($note) > 255) { $errors['note'] = 'الملاحظة لازم تكون 255 حرف أو أقل.'; }

            if ($errors) { throw new RepSubmissionValidationException($errors); }

            /* ── الحارس الموحَّد: مفيش محفظة تقع تحت الصفر ──
               صافي التغيّر على كل محفظة = رجوع الصف القديم + تطبيق الصف الجديد.
               المحفظة اللي صافيها موجب أو صفر مستحيل تقع، فالفحص على السالب بس. */
            $delta = [];
            $bump = function (int $rep, float $v) use (&$delta): void {
                if ($rep > 0) { $delta[$rep] = round(($delta[$rep] ?? 0.0) + $v, 2); }
            };
            $bump($oldFrom, $oldAmount);      // رجوع القديم: المُرسِل القديم بيسترد
            $bump($oldTo, -$oldAmount);       //              والمُستقبِل القديم بيردّ
            $bump($fromRepId, -$amount);      // تطبيق الجديد: المُرسِل الجديد بيدفع
            $bump($toRepId, $amount);         //               والمُستقبِل الجديد بياخد

            ksort($delta);   // ⚠️ ترتيب القفل تصاعديًّا — حماية من الـdeadlock
            foreach ($delta as $rep => $diff) {
                if ($diff >= -0.005) { continue; }
                Database::row('SELECT id FROM reps WHERE id = ? FOR UPDATE', [$rep]);
                $after = round(RepCustody::balance($rep) + $diff, 2);
                if ($after >= -0.005) { continue; }
                if ($rep === $fromRepId) {
                    // اللي معاه = رصيده الحالي بعد ما نرجّع له تأثير الصف القديم
                    $avail = round($after + $amount, 2);
                    throw new RepSubmissionValidationException([
                        'amount' => 'المبلغ أكبر من اللي معاه (' . number_format(max(0, $avail), 2) . ' ج).',
                    ]);
                }
                throw new RepSubmissionValidationException([
                    'amount' => (RepCustody::repName($rep) ?: 'المندوب') . ' صرف المبلغ ده — '
                        . 'مش هينفع تقلّله ولا تنقله لحد تاني.',
                ]);
            }

            if (self::toDateReady()) {
                Database::exec(
                    "UPDATE rep_custody_transfers
                        SET from_rep_id = ?, to_rep_id = ?, amount = ?, transfer_date = ?,
                            to_date = ?, note = ?
                      WHERE id = ?",
                    [$fromRepId, $toRepId, $amount, $date, $toDate, $note !== '' ? $note : null, $id]);
            } else {
                Database::exec(
                    "UPDATE rep_custody_transfers
                        SET from_rep_id = ?, to_rep_id = ?, amount = ?, transfer_date = ?, note = ?
                      WHERE id = ?",
                    [$fromRepId, $toRepId, $amount, $date, $note !== '' ? $note : null, $id]);
            }

            $saved = self::find($id);
            $moved = $fromRepId !== $oldFrom || $toRepId !== $oldTo
                ? ' (اتحوّل من «' . (RepCustody::repName($oldFrom) ?: '#' . $oldFrom) . ' ← '
                  . (RepCustody::repName($oldTo) ?: '#' . $oldTo) . '»)'
                : '';
            if ($toDate !== $date) { $moved .= ' [خرجت ' . $date . ' · وصلت ' . $toDate . ']'; }
            Audit::log('update', 'rep_custody_transfers', $id, $old, $saved,
                'تعديل تحويل عهدة ' . number_format($oldAmount, 2, '.', ',') . ' ← '
                . number_format($amount, 2, '.', ',') . ' من '
                . (RepCustody::repName($fromRepId) ?: '#' . $fromRepId) . ' لـ '
                . (RepCustody::repName($toRepId) ?: '#' . $toRepId) . $moved,
                ['rep_id' => $fromRepId, 'to_rep_id' => $toRepId, 'record_date' => $date]);

            if ($own) { $pdo->commit(); }
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

    /**
     * إجمالي اللي حوّله المندوب لغيره (يتخصم من عهدته).
     * $day (v49): يوم واحد بالظبط = تحويلات عهدة اليوم ده بس. null = كل الأيام.
     */
    public static function outTotal(int $repId, ?string $day = null): float
    {
        return self::sideTotal('from_rep_id', $repId, $day);
    }

    /** إجمالي اللي استلمه المندوب من غيره (يتزاد لعهدته). */
    public static function inTotal(int $repId, ?string $day = null): float
    {
        return self::sideTotal('to_rep_id', $repId, $day);
    }

    /**
     * ⚠️ **خط الفصل (epoch) بيتقاس بـ`transfer_date` للجهتين — مش بيوم كل جهة.**
     *    خط الفصل سؤال عن **الصف نفسه** («الحركة دي من النظام الجديد؟») مش عن
     *    جهة فيه. لو كل جهة اتحاسبت بيومها، صف واقع على الخط (خرج قبله ووصل
     *    بعده) هيتحسب في محفظة ومش هيتحسب في التانية — **فالإجمالي يفقد مبلغًا
     *    من العدم**. أما فلترة اليوم فبتستخدم عمود كل جهة، وده المقصود.
     */
    private static function sideTotal(string $col, int $repId, ?string $day): float
    {
        if (!self::available()) { return 0.0; }
        $dcol = self::dayCol($col);
        $args = [$repId]; $dayW = '';
        if ($day !== null && $day !== '') { $dayW = " AND $dcol = ?"; $args[] = $day; }
        return round((float) Database::scalar(
            "SELECT COALESCE(SUM(amount),0) FROM rep_custody_transfers WHERE $col = ?$dayW"
            . RepCustody::epochSql('transfer_date'), $args), 2);
    }

    /** [rep_id => إجمالي محوَّل] — لصفحة الكروت بلا استعلام لكل مندوب. */
    public static function outTotalsByRep(?string $day = null): array
    {
        return self::totalsBy('from_rep_id', $day);
    }

    /** [rep_id => إجمالي مستلَم] — لصفحة الكروت. */
    public static function inTotalsByRep(?string $day = null): array
    {
        return self::totalsBy('to_rep_id', $day);
    }

    /** [تاريخ => إجمالي محوَّل] لمندوب واحد — لكشف «عهدة كل يوم» (v49). */
    public static function outTotalsByDay(int $repId): array
    {
        return self::totalsByDay('from_rep_id', $repId);
    }

    /** [تاريخ => إجمالي مستلَم] لمندوب واحد — لكشف «عهدة كل يوم» (v49). */
    public static function inTotalsByDay(int $repId): array
    {
        return self::totalsByDay('to_rep_id', $repId);
    }

    private static function totalsBy(string $col, ?string $day = null): array
    {
        if (!self::available()) { return []; }
        $dcol = self::dayCol($col);
        $args = []; $dayW = '';
        if ($day !== null && $day !== '') { $dayW = " AND $dcol = ?"; $args[] = $day; }
        $out = [];
        foreach (Database::all(
            "SELECT $col rid, SUM(amount) total FROM rep_custody_transfers WHERE 1$dayW"
            . RepCustody::epochSql('transfer_date') . " GROUP BY $col", $args) as $r) {
            $out[(int) $r['rid']] = round((float) $r['total'], 2);
        }
        return $out;
    }

    private static function totalsByDay(string $col, int $repId): array
    {
        if (!self::available()) { return []; }
        $dcol = self::dayCol($col);
        $out = [];
        foreach (Database::all(
            "SELECT $dcol d, SUM(amount) total FROM rep_custody_transfers
             WHERE $col = ?" . RepCustody::epochSql('transfer_date') . " GROUP BY $dcol", [$repId]) as $r) {
            $out[(string) $r['d']] = round((float) $r['total'], 2);
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
        return round((float) Database::scalar(
            "SELECT COALESCE(SUM(amount),0) FROM rep_custody_transfers WHERE 1" . RepCustody::epochSql('transfer_date')), 2);
    }

    /**
     * تحويلات مندوب مُرسِل (الأحدث أولاً) + اسم المستقبِل — لكشفه وموبايله.
     *
     * ⚠️ العمود `wallet_date` = **يوم الحركة في محفظة المندوب اللي بنعرضله**
     *    (هنا يوم خروجها منه). أي شاشة بتعرض تاريخ الصف تستخدمه بدل ما تختار
     *    عمودًا بإيدها — وإلا كشف المُستقبِل يقول يوم المُرسِل.
     */
    public static function outgoingFor(int $repId, int $limit = 0, ?string $day = null): array
    {
        if (!self::available()) { return []; }
        $lim = $limit > 0 ? ' LIMIT ' . (int) $limit : '';
        $dcol = self::dayCol('from_rep_id');
        // $day = عهدة يوم واحد (v49) — شاشة المندوب بتعرض يوم العمل بس.
        $where = 't.from_rep_id = ?'; $params = [$repId];
        if ($day !== null && $day !== '') { $where .= " AND t.$dcol = ?"; $params[] = $day; }
        return Database::all(
            "SELECT t.*, t.$dcol AS wallet_date, r.name AS other_name
             FROM rep_custody_transfers t JOIN reps r ON r.id = t.to_rep_id
             WHERE $where" . RepCustody::epochSql('t.transfer_date')
             . " ORDER BY t.$dcol DESC, t.id DESC$lim", $params);
    }

    /**
     * تحويلات وصلت لمندوب مُستقبِل (الأحدث أولاً) + اسم المُرسِل — لكشفه.
     *
     * ⚠️ `wallet_date` هنا = **يوم دخولها عهدته** (`to_date` بعد v94)، وهو اللي
     *    كشفه وعهدة يومه بيتحسبوا عليه — مش يوم خروجها من زميله.
     */
    public static function incomingFor(int $repId, int $limit = 0, ?string $day = null): array
    {
        if (!self::available()) { return []; }
        $lim = $limit > 0 ? ' LIMIT ' . (int) $limit : '';
        $dcol = self::dayCol('to_rep_id');
        $where = 't.to_rep_id = ?'; $params = [$repId];
        if ($day !== null && $day !== '') { $where .= " AND t.$dcol = ?"; $params[] = $day; }
        return Database::all(
            "SELECT t.*, t.$dcol AS wallet_date, r.name AS other_name
             FROM rep_custody_transfers t JOIN reps r ON r.id = t.from_rep_id
             WHERE $where" . RepCustody::epochSql('t.transfer_date')
             . " ORDER BY t.$dcol DESC, t.id DESC$lim", $params);
    }

    /**
     * قيمة مندوب من الفورم، أو القديمة لو الحقل غايب/فاضي.
     *
     * ⚠️ **الفرق بين «غايب» و«صفر» مهم:** `(int)($d['k'] ?? $old)` بتدّي صفرًا
     *    لو الحقل موجود وفاضي فتترفض العملية برسالة «اختر المندوب» وسط تعديل
     *    ملاحظة بريء. الفاضي هنا = ما اتغيّرش، والصفر الحقيقي (قيمة مزوّرة أو
     *    غير رقمية) بيفضل صفرًا عشان الحارس يمسكه.
     */
    private static function pickRep(array $d, string $key, int $fallback): int
    {
        if (!array_key_exists($key, $d)) { return $fallback; }
        $raw = trim((string) ($d[$key] ?? ''));
        return $raw === '' ? $fallback : (int) ar2en_digits($raw);
    }

    /** تاريخ من الفورم، أو القديم لو الحقل غايب/فاضي (نفس منطق `pickRep`). */
    private static function pickDate(array $d, string $key, string $fallback): string
    {
        if (!array_key_exists($key, $d)) { return $fallback; }
        $raw = trim((string) ($d[$key] ?? ''));
        return $raw === '' ? $fallback : $raw;
    }

    /**
     * حُرّاس **يوم عهدة المُستقبِل** (v94) — بيتنادوا من `create` و`update` مع بعض.
     *
     * ⚠️ **الفلوس ما تقدرش توصل قبل ما تخرج.** اليوم الأقدم من يوم المُرسِل
     *    بيترفض: حالة فيزيائيًّا مستحيلة، وأغلب الظن إن الخانتين اتقلبوا (وهما
     *    جنب بعض في الفورم). الرفض هنا أرحم من عهدة يومين غلط بلا أي علامة.
     *
     * ⚠️ **اليوم القديم لازم يكون مفتوح زي الجديد** — التعديل بيفضّي عهدة اليوم
     *    القديم وبيملا الجديد، فقفل أي واحد فيهم بيمنع (قاعدة v49).
     *
     * @param string|null $oldToDate اليوم القديم (للتعديل) أو null (للإنشاء)
     */
    private static function checkToDate(string $toDate, string $fromDate, array &$errors, ?string $oldToDate = null): void
    {
        if (!self::validDate($toDate)) {
            $errors['to_date'] = 'اكتب تاريخ صحيح ليوم عهدة المُستقبِل.'; return;
        }
        if ($toDate > date('Y-m-d')) {
            $errors['to_date'] = 'يوم عهدة المُستقبِل ما ينفعش يكون في المستقبل.'; return;
        }
        if (self::validDate($fromDate) && $toDate < $fromDate) {
            $errors['to_date'] = 'الفلوس ما توصلش قبل ما تخرج — يوم المُستقبِل لازم يكون '
                . 'نفس يوم المُرسِل أو بعده.';
            return;
        }
        $days = $oldToDate !== null ? array_unique([$oldToDate, $toDate]) : [$toDate];
        foreach ($days as $chk) {
            if (($lock = RepCustody::dayLockReason($chk)) !== null) { $errors['to_date'] = $lock; return; }
        }
    }

    private static function validDate(string $v): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { return false; }
        [$y, $m, $d] = array_map('intval', explode('-', $v));
        return checkdate($m, $d, $y);
    }
}
