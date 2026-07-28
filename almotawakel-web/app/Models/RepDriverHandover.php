<?php
namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use RuntimeException;

/**
 * تسليم عهدة لسائق (v45) — المندوب بيدّي كاش لسائق على بوليصة معيّنة.
 *
 * ⚠️⚠️ **قرار المالك حرفيًّا: العملية دي ما بتأثّرش على أي حاجة خالص.**
 *
 *    الأثر الوحيد: **رصيد عهدة المندوب بينقص بالمبلغ.** وخلاص.
 *
 *    ما بيتغيّرش: البوليصة (ولا عمود `custody` ولا أي رقم فيها) · خزنة المكتب ·
 *    التقفيل اليومي · مطابقة v28 · سجل المصروفات `/expenses` · العهدة العامة
 *    `/custody` · أي فاتورة عميل · أي تايم لاين إذن.
 *
 * ⚠️ **إزاي العزل مضمون — نفس منطق `rep_misc_expenses` (v39) و`legacy_bawalis`:**
 *
 *    ليه **جدول خاص بيه** `rep_driver_handovers` كل كود الخزنة القديم عمره ما
 *    سمع عنه. فمفيش «استثناء» لازم نفتكر نضيفه في ٨ أماكن (وننسى واحد فيبوّظ
 *    الرصيد) — العزل بالبناء مش بالانضباط. `bin/verify_v45.php` بيحرس القرار
 *    ده: بيفشل لو أي ملف خزنة ذكر الجدول.
 *
 *    وعشان كده كمان **مفيش `Closing::guard`** هنا: التسليم ما بيدخلش أي تقفيل
 *    أصلاً، فتسجيله على يوم متقفل ما بيغيّرش أي رصيد مثبَّت.
 *
 * ⚠️ الربط بالبوليصة والإذن **بالـid بلا FK** (تحذير 3) و**وصفي بحت**: حذف
 *    البوليصة ما بيكسرش سجل تسليم حصل فعلاً، والاسم بيتخزّن لقطة نصية وقت
 *    التسليم عشان يفضل مقروء حتى لو البوليصة اتغيّرت أو اتمسحت.
 *
 * ⚠️ **بيتخصم على طول بلا مراجعة** (قرار المالك) — بعكس بنود `rep_submissions`
 *    اللي بتقعد pending. فالمندوب مسؤول عن اللي بيسجّله، والمالك بيراجعه من
 *    كارت المندوب وبيقدر يمسحه.
 */
class RepDriverHandover
{
    /** أقصى مبلغ تسليم واحد — حاجز غلط إدخال مش سياسة. */
    private const MAX_AMOUNT = 999999999999.99;

    /** هل الجدول موجود؟ (يخلّي النظام يشتغل عادي قبل migrate_v45) */
    public static function available(): bool
    {
        static $ok = null;
        if ($ok === null) {
            $ok = (bool) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'rep_driver_handovers'"
            );
        }
        return $ok;
    }

    // ─────────────────────────────────────────────── الكتابة

    /**
     * تسجيل تسليم. بيخصم من عهدة المندوب فورًا (مفيش اعتماد).
     *
     * $d: bawali_id, permit_id, amount, handover_date, note, attachment
     * بيرجع id السطر.
     *
     * @throws RepSubmissionValidationException لو أي حقل غلط (رسايل عربية للمندوب)
     */
    public static function create(int $repId, array $d): int
    {
        if (!self::available()) {
            throw new RuntimeException('شغّل php bin/migrate_v45.php الأول.');
        }

        $n = self::normalise($repId, $d);

        // صف المندوب هو mutex كل مصادر عهدته (اعتماد/تنزيل/تسوية/تسليم) — نفس
        // القفل اللي بيستخدمه RepInbox::approve، عشان فحص الرصيد ما يتسابقش.
        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            Database::row('SELECT id FROM reps WHERE id=? FOR UPDATE', [$repId]);

            Database::exec(
                "INSERT INTO rep_driver_handovers
                    (rep_id, permit_id, bawali_id, driver_name, car_no, amount,
                     handover_date, note, attachment, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?)",
                [$repId, $n['permit_id'], $n['bawali_id'], $n['driver_name'], $n['car_no'],
                 $n['amount'], $n['handover_date'], $n['note'], $n['attachment'],
                 Auth::user() ?: (isset($_SESSION['rep_name']) ? 'مندوب: ' . $_SESSION['rep_name'] : null)]);
            $id = Database::lastId();

            $saved = self::find($id);
            Audit::log('create', 'rep_driver_handovers', $id, null, $saved,
                'تسليم عهدة لسائق «' . $n['driver_name'] . '» بقيمة '
                . number_format($n['amount'], 2, '.', ',')
                . ($n['bill_number'] !== null ? ' — بوليصة ' . $n['bill_number'] : '')
                . ' — من عهدة ' . (RepCustody::repName($repId) ?: '#' . $repId),
                [
                    'rep_id'      => $repId,
                    'permit_id'   => $n['permit_id'],
                    'bawali_id'   => $n['bawali_id'],
                    'record_date' => $n['handover_date'],
                ]);

            if ($own) { $pdo->commit(); }
            return $id;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /** حذف تسليم (المالك من كارت المندوب، أو المندوب لسطره في نفس اليوم). */
    public static function delete(int $id): ?string
    {
        $row = self::find($id);
        if (!$row) { throw new RuntimeException('التسليم مش موجود.'); }
        Database::exec("DELETE FROM rep_driver_handovers WHERE id = ?", [$id]);
        Audit::log('delete', 'rep_driver_handovers', $id, $row, null,
            'حذف تسليم عهدة لسائق «' . $row['driver_name'] . '» بقيمة '
            . number_format((float) $row['amount'], 2, '.', ','),
            ['rep_id' => (int) $row['rep_id'], 'record_date' => $row['handover_date']]);
        return $row['attachment'] ?: null;
    }

    // ─────────────────────────────────────────────── القراءة

    public static function find(int $id): ?array
    {
        if (!self::available()) { return null; }
        return Database::row("SELECT * FROM rep_driver_handovers WHERE id = ?", [$id]);
    }

    /** تسليم يخصّ مندوب معيّن (للتأكد قبل ما يمسحه). */
    public static function findMine(int $repId, int $id): ?array
    {
        if (!self::available()) { return null; }
        return Database::row(
            "SELECT * FROM rep_driver_handovers WHERE id = ? AND rep_id = ?", [$id, $repId]);
    }

    /**
     * إجمالي تسليمات مندوب — بيتخصم من عهدته.
     * $day (v49): يوم واحد بالظبط = تسليمات عهدة اليوم ده بس. null = كل الأيام.
     */
    public static function totalFor(int $repId, ?string $day = null): float
    {
        if (!self::available()) { return 0.0; }
        $args = [$repId]; $dayW = '';
        if ($day !== null && $day !== '') { $dayW = ' AND handover_date = ?'; $args[] = $day; }
        return round((float) Database::scalar(
            "SELECT COALESCE(SUM(amount),0) FROM rep_driver_handovers WHERE rep_id = ?$dayW", $args), 2);
    }

    /** [تاريخ => إجمالي] لمندوب واحد — لكشف «عهدة كل يوم» (v49). */
    public static function totalsByDay(int $repId): array
    {
        if (!self::available()) { return []; }
        $out = [];
        foreach (Database::all(
            "SELECT handover_date d, SUM(amount) total FROM rep_driver_handovers
             WHERE rep_id = ? GROUP BY handover_date", [$repId]) as $r) {
            $out[(string) $r['d']] = round((float) $r['total'], 2);
        }
        return $out;
    }

    /** إجمالي تسليمات كل المناديب (لبطاقة الملخّص). */
    public static function totalAll(): float
    {
        if (!self::available()) { return 0.0; }
        return round((float) Database::scalar(
            "SELECT COALESCE(SUM(amount),0) FROM rep_driver_handovers"), 2);
    }

    /**
     * [rep_id => إجمالي] — لصفحة كروت العهدة بلا استعلام لكل مندوب.
     * $day (v49): يوم واحد بالظبط، أو null لكل الأيام (السلوك القديم).
     */
    public static function totalsByRep(?string $day = null): array
    {
        if (!self::available()) { return []; }
        $args = []; $dayW = '';
        if ($day !== null && $day !== '') { $dayW = ' WHERE handover_date = ?'; $args[] = $day; }
        $out = [];
        foreach (Database::all(
            "SELECT rep_id, SUM(amount) total FROM rep_driver_handovers$dayW GROUP BY rep_id", $args) as $r) {
            $out[(int) $r['rep_id']] = round((float) $r['total'], 2);
        }
        return $out;
    }

    /** تسليمات مندوب (الأحدث أولاً) + بيانات البوليصة والإذن للعرض. */
    public static function forRep(int $repId, int $limit = 0, ?string $day = null): array
    {
        if (!self::available()) { return []; }
        $lim = $limit > 0 ? ' LIMIT ' . (int) $limit : '';
        // $day = عهدة يوم واحد (v49): شاشة المندوب بتعرض يوم العمل بس، والمكتب
        // بيشوف الكل. null = السلوك القديم بالحرف.
        $where = 'h.rep_id = ?'; $params = [$repId];
        if ($day !== null && $day !== '') { $where .= ' AND h.handover_date = ?'; $params[] = $day; }
        return Database::all(
            "SELECT h.*, b.bill_number, b.container_no, p.permit_no, p.permit_type
             FROM rep_driver_handovers h
             LEFT JOIN bawalis b ON b.id = h.bawali_id
             LEFT JOIN permits p ON p.id = h.permit_id
             WHERE $where
             ORDER BY h.handover_date DESC, h.id DESC$lim", $params);
    }

    /**
     * بوالص إذن معيّن — لاختيار البوليصة في فورم التسليم.
     * بترجع السائق ورقم العربية عشان الفورم يتعبّى تلقائيًا.
     *
     * ⚠️ عمود `custody` بتاع البوليصة **بيترجع للعرض بس** (عشان المندوب يعرف
     *    العهدة المفروضة كام) — التسليم **عمره ما بيكتب عليه**.
     */
    public static function billsOf(int $permitId): array
    {
        return Database::all(
            "SELECT b.id, b.bill_number, b.container_no, b.container_type, b.driver, b.car_no,
                    b.custody, b.destination
             FROM bawalis b
             WHERE b.permit_id = ? AND b.is_legacy = 0
             ORDER BY b.id", [$permitId]);
    }

    // ─────────────────────────────────────────────── التحقق

    /** تحقق واحد؛ الخادم مصدر الحقيقة مهما بعت الفورم. */
    private static function normalise(int $repId, array $d): array
    {
        $errors = [];

        $bawaliId = (int) ($d['bawali_id'] ?? 0) ?: null;
        $bill = null;
        if ($bawaliId === null) {
            $errors['bawali_id'] = 'اختر البوليصة اللي بتسلّم عليها.';
        } else {
            $bill = Database::row(
                "SELECT b.id, b.bill_number, b.driver, b.car_no, b.permit_id
                 FROM bawalis b WHERE b.id = ? AND b.is_legacy = 0", [$bawaliId]);
            if (!$bill) { $errors['bawali_id'] = 'البوليصة دي مش موجودة.'; }
        }

        // الإذن بيتاخد من البوليصة نفسها مش من الفورم — البوليصة هي مصدر الحقيقة،
        // فمستحيل يتسجّل تسليم على بوليصة في إذن تاني حتى لو الفورم اتزوّر.
        $permitId = $bill && $bill['permit_id'] ? (int) $bill['permit_id'] : null;
        if ($bill && $permitId !== null) {
            // نفس قاعدة v43: المندوب بيشتغل على الأذونات المفتوحة بس.
            try { RepPortal::assertAssigned($repId, $permitId); }
            catch (\Throwable $e) { $errors['_form'] = $e->getMessage(); }
        }

        $driver = preg_replace('/\s+/u', ' ', trim((string) ($d['driver_name'] ?? '')));
        if ($driver === '' && $bill) { $driver = trim((string) ($bill['driver'] ?? '')); }
        if ($driver === '') { $errors['driver_name'] = 'اكتب اسم السائق.'; }
        elseif (mb_strlen($driver) > 180) { $errors['driver_name'] = 'اسم السائق أطول من 180 حرف.'; }

        $carNo = preg_replace('/\s+/u', ' ', trim((string) ($d['car_no'] ?? '')));
        if ($carNo === '' && $bill) { $carNo = trim((string) ($bill['car_no'] ?? '')); }
        if (mb_strlen($carNo) > 120) { $carNo = mb_substr($carNo, 0, 120); }

        $raw = strtr(ar2en_digits(trim((string) ($d['amount'] ?? ''))), [
            ',' => '', '٬' => '', ' ' => '', '٫' => '.',
        ]);
        $amount = round((float) $raw, 2);
        if ($raw === '' || $amount <= 0) { $errors['amount'] = 'اكتب المبلغ اللي سلّمته.'; }
        elseif ($amount > self::MAX_AMOUNT) { $errors['amount'] = 'المبلغ أكبر من الحد المسموح.'; }

        $date = trim((string) ($d['handover_date'] ?? ''));
        if (!self::validDate($date)) { $errors['handover_date'] = 'اكتب تاريخ تسليم صحيح.'; }
        elseif ($date > date('Y-m-d')) { $errors['handover_date'] = 'تاريخ التسليم ما ينفعش يكون في المستقبل.'; }
        // v49: التسليم بيخصم من عهدة يومه، فاليوم المتقفل مرفوض زي أي بند.
        elseif (($lock = RepCustody::dayLockReason($date)) !== null) { $errors['handover_date'] = $lock; }

        $note = preg_replace('/\s+/u', ' ', trim((string) ($d['note'] ?? '')));
        if (mb_strlen($note) > 255) { $errors['note'] = 'الملاحظة لازم تكون 255 حرف أو أقل.'; }

        if ($errors) { throw new RepSubmissionValidationException($errors); }

        return [
            'bawali_id'     => $bawaliId,
            'permit_id'     => $permitId,
            'bill_number'   => $bill['bill_number'] ?? null,
            'driver_name'   => $driver,
            'car_no'        => $carNo !== '' ? $carNo : null,
            'amount'        => $amount,
            'handover_date' => $date,
            'note'          => $note !== '' ? $note : null,
            'attachment'    => ($d['attachment'] ?? '') !== '' ? $d['attachment'] : null,
        ];
    }

    private static function validDate(string $v): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { return false; }
        [$y, $m, $d] = array_map('intval', explode('-', $v));
        return checkdate($m, $d, $y);
    }
}
