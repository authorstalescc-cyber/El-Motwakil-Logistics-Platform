<?php
namespace App\Core;

/**
 * بصمة schema دلالية قابلة للمقارنة بين MySQL وMariaDB.
 *
 * تطبع الفروق الشكلية المعروفة في information_schema، مثل عرض INT والاقتباس
 * حول defaults وصيغة CURRENT_TIMESTAMP، من غير إخفاء اختلاف بنيوي حقيقي.
 */
class SchemaFingerprint
{
    /** @return array<string,mixed> */
    public static function capture(): array
    {
        $categories = [
            'tables' => self::signatures(
                "SELECT table_name,engine,table_collation
                 FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_type='BASE TABLE'",
                static fn(array $v): array => self::strings($v)
            ),
            'columns' => self::signatures(
                'SELECT table_name,ordinal_position,column_name,column_type,
                        is_nullable,column_default,extra,collation_name
                 FROM information_schema.columns
                 WHERE table_schema=DATABASE()',
                static fn(array $v): array => self::normalizeColumn($v)
            ),
            'indexes' => self::signatures(
                'SELECT table_name,index_name,non_unique,seq_in_index,
                        column_name,sub_part,index_type
                 FROM information_schema.statistics
                 WHERE table_schema=DATABASE()',
                static fn(array $v): array => self::strings($v)
            ),
            'constraints' => self::signatures(
                'SELECT table_name,constraint_name,constraint_type
                 FROM information_schema.table_constraints
                 WHERE constraint_schema=DATABASE()',
                static fn(array $v): array => self::strings($v)
            ),
            'relations' => self::signatures(
                'SELECT table_name,constraint_name,column_name,ordinal_position,
                        referenced_table_name,referenced_column_name
                 FROM information_schema.key_column_usage
                 WHERE constraint_schema=DATABASE()',
                static fn(array $v): array => self::strings($v)
            ),
            'triggers' => self::signatures(
                'SELECT trigger_name,event_manipulation,event_object_table,action_timing,action_statement
                 FROM information_schema.triggers
                 WHERE trigger_schema=DATABASE()',
                static fn(array $v): array => self::strings($v)
            ),
        ];

        $result = ['format_version' => 1, 'categories' => []];
        foreach ($categories as $name => $signatures) {
            $result['categories'][$name] = [
                'count' => count($signatures),
                'sha256' => hash('sha256', implode("\n", $signatures)),
            ];
        }
        $result['overall_sha256'] = hash(
            'sha256',
            json_encode(
                $result['categories'],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            )
        );
        return $result;
    }

    /**
     * @param callable(array<int,mixed>):array<int,string> $normalizer
     * @return array<int,string>
     */
    private static function signatures(string $sql, callable $normalizer): array
    {
        $signatures = [];
        foreach (Database::all($sql) as $row) {
            // أسماء حقول information_schema قد تعود بحالة أحرف مختلفة بين
            // MySQL وMariaDB؛ ترتيب SELECT ثابت لذلك نعتمد القيم فقط.
            $values = $normalizer(array_values($row));
            $signatures[] = json_encode(
                $values,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        }
        sort($signatures, SORT_STRING);
        return $signatures;
    }

    /** @return array<int,string> */
    private static function normalizeColumn(array $values): array
    {
        $type = preg_replace_callback(
            '/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/i',
            static fn(array $match): string => strtolower($match[1]),
            (string) $values[3]
        );

        $default = $values[5];
        $default = $default === null || strtoupper((string) $default) === 'NULL'
            ? '<NULL>'
            : (string) $default;
        if (strlen($default) >= 2
            && $default[0] === "'"
            && $default[strlen($default) - 1] === "'") {
            $default = substr($default, 1, -1);
        }
        $default = strtolower(str_replace('()', '', $default));

        $extra = strtolower(str_replace(
            ['DEFAULT_GENERATED', 'default_generated', '()'],
            '',
            (string) $values[6]
        ));
        $extra = trim((string) preg_replace('/\s+/', ' ', $extra));

        return [
            (string) $values[0],
            (string) $values[1],
            (string) $values[2],
            (string) $type,
            (string) $values[4],
            $default,
            $extra,
            $values[7] === null ? '<NULL>' : (string) $values[7],
        ];
    }

    /** @return array<int,string> */
    private static function strings(array $values): array
    {
        return array_map(
            static fn(mixed $value): string => $value === null ? '<NULL>' : (string) $value,
            $values
        );
    }
}
