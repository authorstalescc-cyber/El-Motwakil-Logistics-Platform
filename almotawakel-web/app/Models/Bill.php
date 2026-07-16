<?php
namespace App\Models;

use App\Core\Database;

/**
 * منطق البوليصة كدَين: المدفوع / المتبقي / حالة السداد.
 */
class Bill
{
    public const STATUS_PAID    = 'paid';     // مدفوع
    public const STATUS_PARTIAL = 'partial';  // مدفوع جزئياً
    public const STATUS_UNPAID  = 'unpaid';   // غير مدفوع
    public const STATUS_PENDING = 'pending';  // بانتظار النولون (لم يُدخَل النولون بعد — ليست ديناً)

    public const STATUS_LABELS = [
        'paid'    => 'مدفوع',
        'partial' => 'مدفوع جزئياً',
        'unpaid'  => 'غير مدفوع',
        'pending' => 'بانتظار النولون',
    ];

    public const TYPE_LABELS = [
        'sader' => 'صادر',
        'warid' => 'وارد',
    ];

    /** أسماء أصحاب البيان الموحّدة للاقتراح في إجراءات المجموعات. */
    public static function ownerOptions(): array
    {
        $rows = Database::all(
            "SELECT DISTINCT TRIM(declaration_owner) AS name
               FROM bawalis
              WHERE declaration_owner IS NOT NULL AND TRIM(declaration_owner) <> ''
              ORDER BY name"
        );
        $names = array_column($rows, 'name');
        try {
            foreach (Database::all('SELECT name FROM agents ORDER BY name') as $r) {
                $names[] = $r['name'];
            }
        } catch (\Throwable $e) { /* جدول الوكلاء اختياري وقت التثبيت */ }
        $names = array_values(array_unique(array_filter(array_map('trim', $names), fn($v) => $v !== '')));
        sort($names);
        return $names;
    }

    /**
     * هل رقم البوليصة مُستخدَم من قبل؟ (طبقة حماية ضد التكرار).
     * الفارغ/NULL لا يُعتبر تكرارًا (بوالص كثيرة بلا رقم بعد)، و$exceptId يستثني السجل نفسه عند التعديل.
     */
    public static function numberExists(?string $num, ?int $exceptId = null): bool
    {
        $num = trim((string)$num);
        if ($num === '') { return false; }
        $sql = 'SELECT COUNT(*) FROM bawalis WHERE bill_number = ?';
        $params = [$num];
        if ($exceptId !== null) { $sql .= ' AND id <> ?'; $params[] = $exceptId; }
        return (int) Database::scalar($sql, $params) > 0;
    }

    /** مجموع دفعات بوليصة */
    public static function paidOf(int $billId): float
    {
        return (float) Database::scalar(
            'SELECT COALESCE(SUM(amount),0) FROM bill_payments WHERE bill_id = ?', [$billId]
        );
    }

    /**
     * حساب الحالة من الدفعات المسجّلة فقط — وليس من قيمة الدين/النولون.
     * البوليصة لا تُعتبر مدفوعة إلا إذا أُدخلت عليها دفعة فعلية.
     */
    public static function statusOf(float $debt, float $paid): string
    {
        if ($paid <= 0) {
            return self::STATUS_UNPAID;         // مفيش دفعات → غير مدفوعة (حتى لو الدين صفر أو سالب)
        }
        if ($debt <= 0) {
            return self::STATUS_PAID;           // فيه دفعة والدين غير مستحق → مدفوعة
        }
        if ($paid + 0.005 >= $debt) {
            return self::STATUS_PAID;           // الدفعات غطّت الدين بالكامل
        }
        return self::STATUS_PARTIAL;            // دفعة جزئية
    }

    /**
     * الحالة مع مراعاة النولون: البوليصة بلا نولون مُدخَل (ولا دفعات) = «بانتظار النولون»،
     * فهي ليست ديناً بعد وتُستثنى تلقائياً من قوائم «غير المسدّد» (pay_status IN unpaid/partial).
     * ما إن يُدخَل النولون حتى تُحسب حالتها الطبيعية.
     */
    public static function statusFor(float $freight, float $debt, float $paid): string
    {
        if ($freight <= 0.005 && $paid <= 0) {
            return self::STATUS_PENDING;        // لا نولون ولا دفعات → معلّقة
        }
        return self::statusOf($debt, $paid);
    }

    /** إعادة حساب المدفوع/المتبقي/الحالة لبوليصة وتخزينها */
    public static function recalc(int $billId): void
    {
        $row = Database::row('SELECT debt, freight, is_legacy FROM bawalis WHERE id = ?', [$billId]);
        if (!$row) { return; }
        $debt = (float) $row['debt'];
        $freight = (float) $row['freight'];
        $paid = self::paidOf($billId);
        $remaining = round($debt - $paid, 2);
        // حتى البوليصة القديمة: غياب النولون والدفعات يعني أن بياناتها غير مكتملة،
        // وليس أنها مدفوعة. بعض صفوف Excel تحمل باقيًا سالبًا ناتجًا عن العهدة/الخصم
        // قبل إدخال النولون، فلا يجوز أن يحوّلها هذا السالب إلى «مدفوع».
        if ((int)($row['is_legacy'] ?? 0) === 1) {
            $status = ($freight <= 0.005 && $paid <= 0.005)
                ? self::STATUS_PENDING
                : ($remaining <= 0.005
                    ? self::STATUS_PAID
                    : ($paid > 0.005 ? self::STATUS_PARTIAL : self::STATUS_UNPAID));
        } else {
            $status = self::statusFor($freight, $debt, $paid);
        }
        Database::exec(
            'UPDATE bawalis SET paid = ?, remaining = ?, pay_status = ? WHERE id = ?',
            [round($paid, 2), $remaining, $status, $billId]
        );
    }

    /** تسجيل دفعة سداد */
    public static function addPayment(int $billId, float $amount, ?string $date, ?string $note): void
    {
        if ($amount == 0) { return; }
        Database::exec(
            'INSERT INTO bill_payments (bill_id, pay_date, amount, note) VALUES (?,?,?,?)',
            [$billId, $date ?: work_date(), $amount, $note]
        );
        $paymentId = Database::lastId();
        self::recalc($billId);
        Audit::log('create', 'bill_payments', $paymentId, null,
            Database::row('SELECT * FROM bill_payments WHERE id=?', [$paymentId]),
            'تسجيل دفعة سداد لبوليصة #'.$billId, ['bill_id'=>$billId]);
    }

    public static function deletePayment(int $paymentId): bool
    {
        $p = Database::row('SELECT bill_id, is_legacy FROM bill_payments WHERE id = ?', [$paymentId]);
        if ($p) {
            // الدفعة التاريخية جزء من لقطة الاستيراد الأصلية؛ تُعرض ولا تُحذف من الواجهة.
            if ((int)($p['is_legacy'] ?? 0) === 1) { return false; }
            Database::exec('DELETE FROM bill_payments WHERE id = ?', [$paymentId]);
            self::recalc((int)$p['bill_id']);
            Audit::log('delete', 'bill_payments', $paymentId, $p, null, 'حذف دفعة بوليصة', ['bill_id'=>(int)$p['bill_id']]);
            return true;
        }
        return false;
    }

    public static function payments(int $billId): array
    {
        return Database::all(
            'SELECT * FROM bill_payments WHERE bill_id = ? ORDER BY pay_date, id', [$billId]
        );
    }
}
