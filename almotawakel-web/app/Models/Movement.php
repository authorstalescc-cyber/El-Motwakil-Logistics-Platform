<?php
namespace App\Models;

use App\Core\Database;

/**
 * الحركة: تُربط ببوليصة وتحمل بنوداً (مصروف/إيصال/خصم).
 */
class Movement
{
    public const ITEM_TYPES = [
        'expense'  => 'مصروف',
        'receipt'  => 'إيصال',
        'discount' => 'خصم',
    ];

    /** فئة القائمة المنسدلة المقابلة لكل نوع بند */
    public const TYPE_LOOKUP = [
        'expense'  => 'mv_expense',
        'receipt'  => 'mv_receipt',
        'discount' => 'mv_discount',
    ];

    public static function find(int $id): ?array
    {
        return Database::row('SELECT * FROM movements WHERE id = ?', [$id]);
    }

    /**
     * يضمن وجود حركة مرتبطة بالبوليصة (تُنشأ أوتوماتيك فور إنشاء البوليصة).
     * يزامن التاريخ/السائق/مالك السيارة مع البوليصة.
     */
    public static function ensureForBill(int $billId): int
    {
        // بيانات الشحن مصدرها الإذن (لو البوليصة مرتبطة بإذن) وتظهر في الحركة أيضاً
        $b = Database::row(
            'SELECT b.bill_date, b.driver, b.car_owner,
                    p.shipping_co, p.agency, p.port
             FROM bawalis b LEFT JOIN permits p ON p.id = b.permit_id
             WHERE b.id = ?', [$billId]);
        if (!$b) { return 0; }
        $mvId = Database::scalar('SELECT id FROM movements WHERE bill_id = ? ORDER BY id LIMIT 1', [$billId]);
        if ($mvId) {
            Database::exec(
                'UPDATE movements SET movement_date = ?,
                        driver      = COALESCE(driver, ?),
                        car_owner   = COALESCE(car_owner, ?),
                        shipping_co = COALESCE(NULLIF(shipping_co,""), ?),
                        agency      = COALESCE(NULLIF(agency,""), ?),
                        port        = COALESCE(NULLIF(port,""), ?)
                 WHERE id = ?',
                [$b['bill_date'], $b['driver'], $b['car_owner'],
                 $b['shipping_co'], $b['agency'], $b['port'], (int)$mvId]
            );
            return (int)$mvId;
        }
        Database::exec(
            'INSERT INTO movements (movement_date, bill_id, driver, car_owner, shipping_co, agency, port)
             VALUES (?,?,?,?,?,?,?)',
            [$b['bill_date'], $billId, $b['driver'], $b['car_owner'],
             $b['shipping_co'], $b['agency'], $b['port']]
        );
        return Database::lastId();
    }

    public static function items(int $movementId): array
    {
        return Database::all(
            'SELECT * FROM movement_items WHERE movement_id = ? ORDER BY item_type, id', [$movementId]
        );
    }

    /** حفظ بنود الحركة (يحذف القديمة ويعيد الإدراج) */
    public static function saveItems(int $movementId, array $types, array $categories, array $amounts, array $notes, array $visas = [], array $cards = []): void
    {
        Database::exec('DELETE FROM movement_items WHERE movement_id = ?', [$movementId]);
        $n = count($amounts);
        for ($i = 0; $i < $n; $i++) {
            $amt = (float)($amounts[$i] ?? 0);
            $type = $types[$i] ?? 'expense';
            if ($amt == 0 || !isset(self::ITEM_TYPES[$type])) { continue; }
            $isVisa = !empty($visas[$i]) ? 1 : 0;
            $cardId = ($isVisa && !empty($cards[$i])) ? (int) $cards[$i] : null;
            Database::exec(
                'INSERT INTO movement_items (movement_id, item_type, category, amount, note, is_visa, visa_card_id) VALUES (?,?,?,?,?,?,?)',
                [$movementId, $type, trim($categories[$i] ?? '') ?: null, $amt, trim($notes[$i] ?? '') ?: null,
                 $isVisa, $cardId]
            );
        }
        self::recalc($movementId);
    }

    /** إعادة حساب إجماليات الحركة من بنودها */
    public static function recalc(int $movementId): void
    {
        $sums = Database::row(
            "SELECT
                COALESCE(SUM(CASE WHEN item_type='expense'  THEN amount END),0) e,
                COALESCE(SUM(CASE WHEN item_type='receipt'  THEN amount END),0) r,
                COALESCE(SUM(CASE WHEN item_type='discount' THEN amount END),0) d
             FROM movement_items WHERE movement_id = ?",
            [$movementId]
        ) ?: ['e'=>0,'r'=>0,'d'=>0];
        Database::exec(
            'UPDATE movements SET total_expense=?, total_receipt=?, total_discount=? WHERE id=?',
            [$sums['e'], $sums['r'], $sums['d'], $movementId]
        );
    }
}
