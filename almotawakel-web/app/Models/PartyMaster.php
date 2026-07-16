<?php
namespace App\Models;

use App\Core\Database;
use RuntimeException;

/** مصدر الحقيقة للوكلاء والشركات وأصحاب البيان مع aliases ودمج آمن. */
class PartyMaster
{
    public const TYPES = [
        'agent'   => ['table' => 'agents', 'label' => 'وكيل'],
        'company' => ['table' => 'companies', 'label' => 'شركة'],
        'owner'   => ['table' => 'declaration_owners', 'label' => 'صاحب بيان'],
    ];

    public static function normalizeName(string $name): string
    {
        return Maintenance::normkey($name);
    }

    public static function all(string $type, bool $activeOnly = true): array
    {
        $table = self::table($type);
        return Database::all(
            "SELECT * FROM `$table` " . ($activeOnly ? 'WHERE is_active=1 AND merged_into_id IS NULL ' : '') .
            'ORDER BY name'
        );
    }

    public static function find(string $type, int $id): ?array
    {
        $table = self::table($type);
        return Database::row("SELECT * FROM `$table` WHERE id=?", [$id]);
    }

    /** يحل الاسم الأساسي أو أي alias إلى السجل الفعّال النهائي. */
    public static function resolve(string $type, string $name): ?array
    {
        self::table($type);
        $key = self::normalizeName($name);
        if ($key === '') { return null; }
        $alias = Database::row(
            'SELECT party_id FROM party_aliases WHERE party_type=? AND normalized_alias=? LIMIT 1',
            [$type, $key]
        );
        if (!$alias) { return null; }
        $row = self::find($type, (int)$alias['party_id']);
        $seen = [];
        while ($row && !empty($row['merged_into_id'])) {
            if (isset($seen[$row['id']])) { throw new RuntimeException('حلقة غير صالحة في دمج الأطراف.'); }
            $seen[$row['id']] = true;
            $row = self::find($type, (int)$row['merged_into_id']);
        }
        return $row;
    }

    /** يعيد السجل الموجود أو ينشئ master جديداً مع alias أساسي. */
    public static function ensure(string $type, string $name): ?array
    {
        $name = trim($name);
        if ($name === '') { return null; }
        $found = self::resolve($type, $name);
        if ($found) { return $found; }
        $id = self::create($type, ['name' => $name]);
        return self::find($type, $id);
    }

    public static function create(string $type, array $data): int
    {
        $table = self::table($type);
        $name = trim((string)($data['name'] ?? ''));
        $norm = self::normalizeName($name);
        if ($name === '' || $norm === '') { throw new RuntimeException('اسم الطرف مطلوب.'); }
        if (self::resolve($type, $name)) { throw new RuntimeException('الاسم أو أحد أشكاله مسجّل بالفعل.'); }

        $pdo = Database::conn();
        $started = !$pdo->inTransaction();
        if ($started) { $pdo->beginTransaction(); }
        try {
            $fields = self::editableFields($type, $data);
            $fields = ['name'=>$name, 'normalized_name'=>$norm] + $fields;
            $cols = array_keys($fields);
            Database::exec(
                "INSERT INTO `$table` (`" . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')',
                array_values($fields)
            );
            $id = Database::lastId();
            self::insertAlias($type, $id, $name, true);
            $row = self::find($type, $id);
            Audit::log('create', self::entity($type), $id, null, $row, 'إضافة ' . self::label($type) . ' إلى البيانات الأساسية');
            if ($started) { $pdo->commit(); }
            return $id;
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /** تحديث بقفل تفاؤلي؛ يرمي تعارضاً إن تغير السجل منذ فتح النموذج. */
    public static function update(string $type, int $id, array $data, int $expectedVersion): void
    {
        $table = self::table($type);
        $before = self::find($type, $id);
        if (!$before || !empty($before['merged_into_id'])) { throw new RuntimeException('الطرف غير موجود أو تم دمجه.'); }
        $name = trim((string)($data['name'] ?? ''));
        $norm = self::normalizeName($name);
        if ($name === '' || $norm === '') { throw new RuntimeException('اسم الطرف مطلوب.'); }

        $resolved = self::resolve($type, $name);
        if ($resolved && (int)$resolved['id'] !== $id) { throw new RuntimeException('الاسم أو أحد أشكاله يخص طرفاً آخر. استخدم الدمج بدلاً من تغيير الاسم.'); }

        $fields = ['name'=>$name, 'normalized_name'=>$norm] + self::editableFields($type, $data);
        $sets = [];
        foreach (array_keys($fields) as $column) { $sets[] = "`$column`=?"; }
        $params = array_values($fields);
        $params[] = $id;
        $params[] = $expectedVersion;
        $changed = Database::exec(
            "UPDATE `$table` SET " . implode(',', $sets) . ',lock_version=lock_version+1 WHERE id=? AND lock_version=? AND merged_into_id IS NULL',
            $params
        );
        if ($changed !== 1) { throw new RuntimeException('عدّل مستخدم آخر هذا السجل. أعد تحميل الصفحة وراجع التغييرات قبل الحفظ.'); }
        self::insertAlias($type, $id, $name, true);
        Audit::log('update', self::entity($type), $id, $before, self::find($type, $id), 'تعديل بيانات ' . self::label($type));
    }

    public static function aliases(string $type, int $id): array
    {
        self::table($type);
        return Database::all(
            'SELECT * FROM party_aliases WHERE party_type=? AND party_id=? ORDER BY is_primary DESC, alias',
            [$type, $id]
        );
    }

    public static function addAlias(string $type, int $id, string $alias): void
    {
        if (!self::find($type, $id)) { throw new RuntimeException('الطرف غير موجود.'); }
        self::insertAlias($type, $id, $alias, false);
        Audit::log('alias', self::entity($type), $id, null, ['alias'=>trim($alias)], 'إضافة اسم بديل');
    }

    /** دمج المصدر داخل الهدف: يحرك المعرفات والعلاقات ويحتفظ بالنصوص كلقطات تاريخية. */
    public static function merge(string $type, int $sourceId, int $targetId, int $expectedVersion): void
    {
        $table = self::table($type);
        if ($sourceId === $targetId) { throw new RuntimeException('اختر طرفين مختلفين للدمج.'); }
        $source = self::find($type, $sourceId);
        $target = self::find($type, $targetId);
        if (!$source || !$target || !$source['is_active'] || !$target['is_active']) { throw new RuntimeException('أحد طرفي الدمج غير متاح.'); }
        if ((int)$source['lock_version'] !== $expectedVersion) { throw new RuntimeException('تغير سجل المصدر. أعد تحميل الصفحة قبل الدمج.'); }

        $pdo = Database::conn();
        $started = !$pdo->inTransaction();
        if ($started) { $pdo->beginTransaction(); }
        try {
            foreach (self::references($type) as [$refTable, $column]) {
                Database::exec("UPDATE `$refTable` SET `$column`=? WHERE `$column`=?", [$targetId, $sourceId]);
            }
            if ($type === 'agent') {
                Database::exec('INSERT IGNORE INTO agent_company (agent_id,company_id) SELECT ?,company_id FROM agent_company WHERE agent_id=?', [$targetId,$sourceId]);
                Database::exec('DELETE FROM agent_company WHERE agent_id=?', [$sourceId]);
            } elseif ($type === 'company') {
                Database::exec('INSERT IGNORE INTO agent_company (agent_id,company_id) SELECT agent_id,? FROM agent_company WHERE company_id=?', [$targetId,$sourceId]);
                Database::exec('DELETE FROM agent_company WHERE company_id=?', [$sourceId]);
            }

            foreach (self::aliases($type, $sourceId) as $alias) {
                Database::exec(
                    'INSERT INTO party_aliases (party_type,party_id,alias,normalized_alias,is_primary,created_by)
                     VALUES (?,?,?,?,0,?) ON DUPLICATE KEY UPDATE party_id=VALUES(party_id), alias=VALUES(alias), is_primary=0',
                    [$type,$targetId,$alias['alias'],$alias['normalized_alias'],(int)($_SESSION['uid'] ?? 0) ?: null]
                );
            }
            Database::exec('DELETE FROM party_aliases WHERE party_type=? AND party_id=?', [$type,$sourceId]);
            self::insertAlias($type, $targetId, $source['name'], false);
            $changed = Database::exec(
                "UPDATE `$table` SET is_active=0,merged_into_id=?,lock_version=lock_version+1 WHERE id=? AND lock_version=?",
                [$targetId,$sourceId,$expectedVersion]
            );
            if ($changed !== 1) { throw new RuntimeException('حدث تعارض أثناء الدمج؛ لم تُحفظ أي تغييرات.'); }
            Audit::log('merge', self::entity($type), $sourceId, $source, self::find($type, $sourceId),
                'دمج ' . self::label($type) . ' «'.$source['name'].'» داخل «'.$target['name'].'»',
                ['target_id'=>$targetId]
            );
            if ($started) { $pdo->commit(); }
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    private static function insertAlias(string $type, int $id, string $alias, bool $primary): void
    {
        $alias = trim($alias);
        $norm = self::normalizeName($alias);
        if ($alias === '' || $norm === '') { throw new RuntimeException('الاسم البديل فارغ.'); }
        $existing = Database::row('SELECT party_id FROM party_aliases WHERE party_type=? AND normalized_alias=?', [$type,$norm]);
        if ($existing && (int)$existing['party_id'] !== $id) { throw new RuntimeException('هذا الاسم البديل مرتبط بطرف آخر. استخدم الدمج.'); }
        if ($primary) { Database::exec('UPDATE party_aliases SET is_primary=0 WHERE party_type=? AND party_id=?', [$type,$id]); }
        Database::exec(
            'INSERT INTO party_aliases (party_type,party_id,alias,normalized_alias,is_primary,created_by)
             VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE alias=VALUES(alias),is_primary=GREATEST(is_primary,VALUES(is_primary))',
            [$type,$id,$alias,$norm,$primary?1:0,(int)($_SESSION['uid'] ?? 0) ?: null]
        );
    }

    private static function editableFields(string $type, array $data): array
    {
        $fields = [
            'note'    => trim((string)($data['note'] ?? '')) ?: null,
            'phone'   => trim((string)($data['phone'] ?? '')) ?: null,
            'email'   => trim((string)($data['email'] ?? '')) ?: null,
            'tax_id'  => trim((string)($data['tax_id'] ?? '')) ?: null,
            'address' => trim((string)($data['address'] ?? '')) ?: null,
        ];
        if ($type === 'agent') { $fields['payment_terms_days'] = max(0, min(365, (int)($data['payment_terms_days'] ?? 30))); }
        return $fields;
    }

    private static function references(string $type): array
    {
        return match ($type) {
            'agent'   => [['permits','agent_id'],['permit_invoices','agent_id'],['client_payments','agent_id']],
            'company' => [['permits','company_id'],['bawalis','company_id'],['permit_invoices','company_id']],
            'owner'   => [['bawalis','declaration_owner_id'],['owner_statements','declaration_owner_id']],
        };
    }

    private static function table(string $type): string
    {
        if (!isset(self::TYPES[$type])) { throw new RuntimeException('نوع طرف غير صالح.'); }
        return self::TYPES[$type]['table'];
    }

    private static function label(string $type): string { self::table($type); return self::TYPES[$type]['label']; }
    private static function entity(string $type): string { return $type === 'owner' ? 'declaration_owners' : self::table($type); }
}
