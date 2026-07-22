<?php
namespace App\Models;

use App\Core\Database;

/**
 * القوائم المنسدلة (التصنيفات/الشركات/الجهات/السائقين/أصحاب السيارات/العملاء).
 */
class Lookup
{
    public const TYPES = [
        'classification' => 'التصنيفات',
        'company'        => 'الشركات',
        'destination'    => 'الجهات',
        'driver'         => 'السائقون',
        'car_owner'      => 'أصحاب السيارات',
        'client'         => 'العملاء',
        'container_type' => 'تصنيف الحاويات',
        'shipping_co'    => 'شركات الشحن',
        'agency'         => 'التوكيلات',
        'port'           => 'الموانئ',
        'mv_expense'     => 'فئات المصروفات (الحركة)',
        'mv_receipt'     => 'فئات الإيصالات (الحركة)',
        'mv_discount'    => 'فئات الخصومات (الحركة)',
        'expense_cat'    => 'فئات المصروفات اليدوية',
    ];

    /** كل القوائم مجمّعة حسب النوع */
    public static function allGrouped(): array
    {
        $out = array_fill_keys(array_keys(self::TYPES), []);
        foreach (Database::all('SELECT list_type, value FROM lookups ORDER BY value') as $r) {
            $out[$r['list_type']][] = $r['value'];
        }
        return $out;
    }

    public static function ofType(string $type): array
    {
        return array_column(
            Database::all('SELECT value FROM lookups WHERE list_type = ? ORDER BY value', [$type]),
            'value'
        );
    }

    /** عدة قوائم مطلوبة في الشاشة نفسها باستعلام واحد. */
    public static function ofTypes(array $types): array
    {
        $types = array_values(array_unique(array_filter(
            $types,
            static fn($type): bool => is_string($type) && isset(self::TYPES[$type])
        )));
        $out = array_fill_keys($types, []);
        if (!$types) { return $out; }
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        foreach (Database::all(
            "SELECT list_type, value FROM lookups WHERE list_type IN ($placeholders) ORDER BY value",
            $types
        ) as $row) {
            $out[$row['list_type']][] = $row['value'];
        }
        return $out;
    }

    /** إضافة قيمة إن لم تكن موجودة */
    public static function add(string $type, string $value): void
    {
        $value = trim($value);
        if ($value === '' || !isset(self::TYPES[$type])) {
            return;
        }
        Database::exec(
            'INSERT IGNORE INTO lookups (list_type, value) VALUES (?, ?)',
            [$type, $value]
        );
    }

    public static function delete(int $id): void
    {
        Database::exec('DELETE FROM lookups WHERE id = ?', [$id]);
    }

    public static function withIds(): array
    {
        $out = array_fill_keys(array_keys(self::TYPES), []);
        foreach (Database::all('SELECT id, list_type, value FROM lookups ORDER BY value') as $r) {
            $out[$r['list_type']][] = ['id' => $r['id'], 'value' => $r['value']];
        }
        return $out;
    }
}
