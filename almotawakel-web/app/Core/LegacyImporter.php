<?php
namespace App\Core;

use App\Calc;

/** استيراد النظام القديم؛ لا يُستدعى إلا من وضع CLI الصريح import-legacy. */
class LegacyImporter
{
    /** @return array{lookups:int,balances:int,bawalis:int,takhlis:int,generators:int} */
    public static function inspect(string $path): array
    {
        $data = self::read($path);
        $lookups = 0;
        foreach (($data['lookups'] ?? []) as $values) {
            foreach ((array) $values as $value) {
                if ($value !== null && trim((string) $value) !== '') { $lookups++; }
            }
        }
        return [
            'lookups' => $lookups,
            'balances' => count($data['balances'] ?? []),
            'bawalis' => count($data['bawalis'] ?? []),
            'takhlis' => count($data['takhlis'] ?? []),
            'generators' => count($data['generators'] ?? []),
        ];
    }

    /** @return array{lookups:int,balances:int,bawalis:int,takhlis:int,generators:int} */
    public static function import(string $path): array
    {
        $data = self::read($path);
        $pdo = Database::conn();
        $counts = ['lookups' => 0, 'balances' => 0, 'bawalis' => 0, 'takhlis' => 0, 'generators' => 0];
        $pdo->beginTransaction();
        try {
            // --fresh يمسح وحدة البوالص التابعة أولًا، وبـDELETE داخل معاملة قابلة للتراجع.
            // هذا يتجنب TRUNCATE ذي الـimplicit commit ويحترم علاقات المفاتيح الأجنبية.
            foreach (['movement_items', 'movements', 'bill_payments', 'driver_custody', 'bawalis', 'takhlis', 'generators', 'balances'] as $table) {
                $pdo->exec("DELETE FROM `$table`");
            }

            foreach (($data['lookups'] ?? []) as $type => $values) {
                foreach ((array) $values as $value) {
                    if ($value === null || trim((string) $value) === '') { continue; }
                    Database::exec('INSERT IGNORE INTO lookups (list_type,value) VALUES (?,?)', [$type, $value]);
                    $counts['lookups']++;
                }
            }
            foreach (($data['balances'] ?? []) as $row) {
                Database::exec(
                    'INSERT INTO balances (party_type,name,balance) VALUES (?,?,?)',
                    [$row['party_type'], $row['name'], $row['balance']]
                );
                $counts['balances']++;
            }

            foreach (['bawalis', 'takhlis', 'generators'] as $table) {
                $rows = $data[$table] ?? [];
                if (!$rows) { continue; }
                $statement = null; $columns = [];
                foreach ($rows as $rowIndex => $row) {
                    if ($table === 'bawalis') {
                        // أرشيف Excel قد يحوي رقم بوليصة مكررًا؛ لا نضعف تفرد السجلات الحية.
                        $row['is_legacy'] = 1;
                        $row['legacy_id'] = isset($row['legacy_id']) ? (int) $row['legacy_id'] : $rowIndex + 1;
                    }
                    $row = Calc::apply($table, $row);
                    if ($statement === null) {
                        $columns = array_keys($row);
                        $quoted = '`' . implode('`,`', $columns) . '`';
                        $placeholders = implode(',', array_fill(0, count($columns), '?'));
                        $statement = $pdo->prepare("INSERT INTO `$table` ($quoted) VALUES ($placeholders)");
                    }
                    $statement->execute(array_map(fn(string $column) => $row[$column] ?? null, $columns));
                    $counts[$table]++;
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return $counts;
    }

    private static function read(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('ملف database/old_data.json غير موجود أو غير مقروء.');
        }
        $raw = file_get_contents($path);
        $data = json_decode((string) $raw, true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('ملف database/old_data.json تالف: ' . json_last_error_msg());
        }
        foreach (['lookups', 'balances', 'bawalis', 'takhlis', 'generators'] as $key) {
            if (isset($data[$key]) && !is_array($data[$key])) {
                throw new \RuntimeException("بنية old_data.json غير صحيحة عند الحقل $key.");
            }
        }
        return $data;
    }
}
