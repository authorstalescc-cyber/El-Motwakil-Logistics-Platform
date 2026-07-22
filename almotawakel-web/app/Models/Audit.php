<?php
namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use RuntimeException;

/** سجل العمليات وسلة المحذوفات. لا يرمي بيانات مالية بصمت. */
class Audit
{
    private const RESTORABLE = ['bawalis', 'takhlis', 'generators', 'users'];
    private const UNDOABLE_UPDATES = ['bawalis', 'takhlis', 'generators'];

    public static function restorableEntities(): array
    {
        return self::RESTORABLE;
    }

    public static function log(
        string $action,
        string $entity,
        $recordId,
        ?array $old = null,
        ?array $new = null,
        string $description = '',
        array $meta = [],
        bool $canUndo = false
    ): int {
        $workDate = self::validDate($meta['work_date'] ?? null) ?: Auth::workDate();
        $recordDate = self::recordDate($new ?? $old ?? [], $meta);
        Database::exec(
            'INSERT INTO audit_logs
             (user_id,user_name,action,entity,record_id,description,old_json,new_json,meta_json,work_date,record_date,can_undo)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                (int)($_SESSION['uid'] ?? 0) ?: null,
                self::actorName(),
                $action,
                $entity,
                $recordId !== null ? (string)$recordId : null,
                $description !== '' ? $description : null,
                self::json($old),
                self::json($new),
                self::json($meta),
                $workDate,
                $recordDate,
                $canUndo ? 1 : 0,
            ]
        );
        return Database::lastId();
    }

    /**
     * اسم المنفّذ: مستخدم المكتب، وإلا المندوب.
     * جلسة المندوب (`/m`) عمرها ما بتحط `uid` ولا `uname` (v35)، فبدون الحيلة دي
     * كان أي إجراء بيعمله المندوب يتسجّل بلا اسم في سجل الإدخالات.
     */
    private static function actorName(): ?string
    {
        $user = Auth::user();
        if ($user !== null && trim($user) !== '') { return $user; }
        $rep = trim((string) ($_SESSION['rep_name'] ?? ''));
        return $rep !== '' ? 'مندوب: ' . $rep : null;
    }

    public static function trash(string $entity, array $row, string $label = '', array $related = []): int
    {
        if (!in_array($entity, self::RESTORABLE, true)) {
            throw new RuntimeException('هذا النوع غير مدعوم في سلة المحذوفات.');
        }
        Database::exec(
            'INSERT INTO deleted_records
             (entity,record_id,label,data_json,related_json,deleted_by,deleted_by_name)
             VALUES (?,?,?,?,?,?,?)',
            [
                $entity,
                isset($row['id']) ? (string)$row['id'] : null,
                $label !== '' ? $label : null,
                self::json($row),
                self::json($related),
                (int)($_SESSION['uid'] ?? 0) ?: null,
                Auth::user(),
            ]
        );
        $trashId = Database::lastId();
        self::log('delete', $entity, $row['id'] ?? null, $row, null, 'نقل إلى سلة المحذوفات', ['trash_id'=>$trashId], false);
        return $trashId;
    }

    public static function recent(
        int $limit = 200,
        string $entity = '',
        string $action = '',
        string $from = '',
        string $to = ''
    ): array
    {
        $where = []; $params = [];
        if ($entity !== '') { $where[] = 'entity=?'; $params[] = $entity; }
        if ($action !== '') { $where[] = 'action=?'; $params[] = $action; }
        if (self::validDate($from)) { $where[] = 'COALESCE(record_date,work_date,DATE(created_at))>=?'; $params[] = $from; }
        if (self::validDate($to)) { $where[] = 'COALESCE(record_date,work_date,DATE(created_at))<=?'; $params[] = $to; }
        $sql = 'SELECT * FROM audit_logs' . ($where ? ' WHERE '.implode(' AND ', $where) : '')
             . ' ORDER BY id DESC LIMIT ' . max(1, min(500, $limit));
        return Database::all($sql, $params);
    }

    public static function trashRows(bool $includeRestored = false): array
    {
        return Database::all(
            'SELECT * FROM deleted_records ' . ($includeRestored ? '' : 'WHERE restored_at IS NULL ')
            . 'ORDER BY id DESC LIMIT 300'
        );
    }

    /** تراجع آمن عن تحديث بسيط. البوليصة الحية لا تُسترجع مباشرة حتى لا يختل التقفيل. */
    public static function undo(int $id): void
    {
        $log = Database::row('SELECT * FROM audit_logs WHERE id=? FOR UPDATE', [$id]);
        if (!$log || $log['action'] !== 'update' || !(int)$log['can_undo'] || $log['undone_at']) {
            throw new RuntimeException('هذه العملية غير متاحة للتراجع أو تم التراجع عنها بالفعل.');
        }
        if (!in_array($log['entity'], self::UNDOABLE_UPDATES, true)) {
            throw new RuntimeException('التراجع غير مدعوم لهذا النوع.');
        }
        $old = json_decode((string)$log['old_json'], true);
        $current = Database::row('SELECT * FROM `'.$log['entity'].'` WHERE id=?', [(int)$log['record_id']]);
        if (!$old || !$current) { throw new RuntimeException('تعذّر العثور على السجل الحالي أو نسخته السابقة.'); }
        if ($log['entity'] === 'bawalis' && empty($old['is_legacy'])) {
            throw new RuntimeException('لأمان العهدة والتقفيل لا يتم التراجع المباشر عن بوليصة حية. راجعها يدويًا.');
        }

        $pdo = Database::conn();
        $pdo->beginTransaction();
        try {
            self::replaceRow($log['entity'], (int)$log['record_id'], $old);
            Database::exec('UPDATE audit_logs SET undone_at=NOW(), undone_by=? WHERE id=?', [(int)($_SESSION['uid'] ?? 0) ?: null, $id]);
            self::log('undo', $log['entity'], $log['record_id'], $current, $old, 'تراجع عن العملية #'.$id, ['audit_id'=>$id], false);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    public static function restore(int $trashId): void
    {
        $trash = Database::row('SELECT * FROM deleted_records WHERE id=? FOR UPDATE', [$trashId]);
        if (!$trash || $trash['restored_at']) { throw new RuntimeException('السجل غير موجود في السلة أو تم استرجاعه.'); }
        $entity = $trash['entity'];
        if (!in_array($entity, self::RESTORABLE, true)) { throw new RuntimeException('الاسترجاع غير مدعوم لهذا النوع.'); }
        $row = json_decode((string)$trash['data_json'], true);
        $related = json_decode((string)$trash['related_json'], true) ?: [];
        if (!$row) { throw new RuntimeException('نسخة السجل المحذوف غير صالحة.'); }
        if (Database::scalar('SELECT COUNT(*) FROM `'.$entity.'` WHERE id=?', [(int)$row['id']])) {
            throw new RuntimeException('تعذّر الاسترجاع لأن رقم السجل استُخدم من جديد.');
        }
        if ($entity === 'bawalis' && empty($row['is_legacy'])) {
            if ($err = Closing::guard($row['bill_date'] ?? null)) { throw new RuntimeException($err); }
        }

        $pdo = Database::conn();
        $pdo->beginTransaction();
        try {
            self::insertRow($entity, $row);
            if ($entity === 'bawalis' && !empty($related['bill_payments'])) {
                foreach ($related['bill_payments'] as $payment) { self::insertRow('bill_payments', $payment); }
            }
            if ($entity === 'bawalis' && !empty($related['movement_ids'])) {
                $ids=array_values(array_filter(array_map('intval',$related['movement_ids'])));
                if ($ids) { Database::exec('UPDATE movements SET bill_id=? WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')',array_merge([(int)$row['id']],$ids)); }
            }
            Database::exec('UPDATE deleted_records SET restored_at=NOW(), restored_by=? WHERE id=?', [(int)($_SESSION['uid'] ?? 0) ?: null, $trashId]);
            self::log('restore', $entity, $row['id'] ?? null, null, $row, 'استرجاع من سلة المحذوفات', ['trash_id'=>$trashId], false);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    private static function replaceRow(string $table, int $id, array $row): void
    {
        $columns = self::columns($table);
        unset($row['id']);
        $row = array_intersect_key($row, array_flip($columns));
        $sets=[]; $params=[];
        foreach ($row as $column=>$value) { $sets[]='`'.$column.'`=?'; $params[]=$value; }
        $params[]=$id;
        Database::exec('UPDATE `'.$table.'` SET '.implode(',', $sets).' WHERE id=?', $params);
    }

    private static function insertRow(string $table, array $row): void
    {
        $row = array_intersect_key($row, array_flip(self::columns($table)));
        $cols = array_keys($row);
        Database::exec(
            'INSERT INTO `'.$table.'` (`'.implode('`,`', $cols).'`) VALUES ('.implode(',', array_fill(0,count($cols),'?')).')',
            array_values($row)
        );
    }

    private static function columns(string $table): array
    {
        return array_column(Database::all('SHOW COLUMNS FROM `'.$table.'`'), 'Field');
    }

    private static function json(?array $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }

    /** يستخرج تاريخ المستند من الـmeta أو من أشهر أسماء حقول التاريخ في النظام. */
    private static function recordDate(array $row, array $meta): ?string
    {
        $explicit = self::validDate($meta['record_date'] ?? null);
        if ($explicit) { return $explicit; }
        foreach (['bill_date','entry_date','permit_date','movement_date','exp_date','pay_date',
                  'cert_date','gen_date','invoice_date','cust_date','dep_date','statement_date'] as $key) {
            $date = self::validDate($row[$key] ?? null);
            if ($date) { return $date; }
        }
        return null;
    }

    private static function validDate($value): ?string
    {
        $value = is_string($value) ? trim($value) : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) { return null; }
        [$y,$m,$d] = array_map('intval', explode('-', $value));
        return checkdate($m, $d, $y) ? $value : null;
    }
}
