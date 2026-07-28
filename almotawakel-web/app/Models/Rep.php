<?php
namespace App\Models;

use App\Core\Database;

/**
 * وحدة المناديب — المناديب اللي بيجمّعوا ويورّدوا الإيصالات، وليهم مرتب ومواصلات
 * وسجل كامل، وبيتقفل حسابهم دوريًا (يدوي من–إلى).
 *
 * سجل المندوب كشف من طرفين:
 *   مستحقات له (+): salary | transport | expense_logistic | bonus
 *   خصومات/صرف عليه (−): expense_deducted | deduction | advance | settlement
 *   الرصيد = Σ(المستحقات) − Σ(الخصومات) = المستحق للمندوب علينا (الدين علينا — تحذير 1).
 *
 * الربط بالمصروفات/العهدة (قرار صاحب الشركة):
 *   بس البنود اللي بتطلّع فلوس من الخزنة فعلاً — advance + settlement — بتدخل
 *   «المصروفات العامة» وبتتخصم من العهدة/التقفيل اليومي. باقي البنود استحقاقات
 *   في سجل المندوب بس، بتتغطّى جوه صافي التسوية → مفيش تحسيب مزدوج.
 *   انظر Expense::unionSql() و Closing::sumExpenses() (لازم يمشوا مع هذا الموديل).
 */
class Rep
{
    /** تسميات أنواع البنود (للشارات والفورم). */
    public const KINDS = [
        'salary'            => 'مرتب',
        'transport'         => 'مواصلات',
        'expense_logistic'  => 'مصروف لوجيستي (على الشركة)',
        'bonus'             => 'مكافأة',
        'expense_deducted'  => 'مصروف يُخصم من المرتب',
        'deduction'         => 'خصم',
        'advance'           => 'سُلفة / عهدة',
        'settlement'        => 'دفعة تسوية',
    ];

    /** البنود المستحقة للمندوب (+). */
    public const CREDIT_KINDS = ['salary', 'transport', 'expense_logistic', 'bonus'];

    /** البنود المخصومة / المصروفة عليه (−). */
    public const DEBIT_KINDS = ['expense_deducted', 'deduction', 'advance', 'settlement'];

    /** البنود اللي بتطلّع فلوس من الخزنة فعلاً (تدخل المصروفات العامة والعهدة). */
    public const CASH_KINDS = ['advance', 'settlement'];

    /** إشارة البند في الرصيد: +1 للمستحقات، −1 للخصومات. */
    public static function sign(string $kind): int
    {
        return in_array($kind, self::CREDIT_KINDS, true) ? 1 : -1;
    }

    /** هل البند بيطلّع كاش من الخزنة؟ (advance/settlement) */
    public static function isCashKind(string $kind): bool
    {
        return in_array($kind, self::CASH_KINDS, true);
    }

    /** تسمية عربية لنوع البند. */
    public static function kindLabel(string $kind): string
    {
        return self::KINDS[$kind] ?? $kind;
    }

    // ─────────────────────────────────────────────── المناديب (كروت)

    /** قائمة المناديب + رصيد كل واحد (المستحق له). */
    public static function all(string $q = '', string $status = ''): array
    {
        $where = []; $args = [];
        if ($q !== '')      { $where[] = "(r.name LIKE ? OR r.phone LIKE ?)"; $args[] = "%$q%"; $args[] = "%$q%"; }
        if ($status !== '') { $where[] = "r.status = ?"; $args[] = $status; }
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $credit = "'" . implode("','", self::CREDIT_KINDS) . "'";
        $rows = Database::all(
            "SELECT r.*,
                    COALESCE(SUM(CASE WHEN e.kind IN ($credit) THEN e.amount ELSE -e.amount END),0) AS balance,
                    COUNT(e.id) AS entries
             FROM reps r
             LEFT JOIN rep_entries e ON e.rep_id = r.id
             $w
             GROUP BY r.id
             ORDER BY r.status='inactive', r.name", $args
        );
        foreach ($rows as &$row) { $row['balance'] = round((float) $row['balance'], 2); }
        return $rows;
    }

    public static function find(int $id): ?array
    {
        return Database::row("SELECT * FROM reps WHERE id = ?", [$id]);
    }

    // ───────────────────────────── صلاحية فتح إذن من الموبايل (v44)

    /** هل عمود الصلاحية اترحّل؟ (نمط RepCustody::available — النظام يشتغل قبل الهجرة) */
    public static function permitPermReady(): bool
    {
        static $ok = null;
        if ($ok === null) {
            $ok = (bool) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'reps' AND column_name = 'can_create_permit'"
            );
        }
        return $ok;
    }

    /**
     * هل المندوب ده مسموح له يفتح إذن/شهادة من موبايله؟
     *
     * ⚠️ **الرفض هو الافتراضي** (تحذير 5): قبل تشغيل migrate_v44 بترجّع false
     * لكل الناس، يعني الزرار بيختفي والحفظ بيترفض — مش بيفتح للكل بالغلط.
     * ده المصدر الوحيد للفحص؛ ممنوع تكتب الشرط بإيدك في أي مكان تاني.
     */
    public static function canCreatePermit(int $repId): bool
    {
        if ($repId <= 0 || !self::permitPermReady()) { return false; }
        return (int) Database::scalar(
            "SELECT can_create_permit FROM reps WHERE id = ?", [$repId]) === 1;
    }

    /** المالك بيدّي/بيسحب الصلاحية من كارت المندوب. */
    public static function setCanCreatePermit(int $repId, bool $allowed): void
    {
        if (!self::permitPermReady()) {
            throw new \RuntimeException('شغّل php bin/migrate_v44.php الأول عشان تتحكّم في الصلاحية دي.');
        }
        Database::exec("UPDATE reps SET can_create_permit = ? WHERE id = ?", [$allowed ? 1 : 0, $repId]);
    }

    // ───────────────────────────── صلاحية «متابعة المناديب» من الموبايل (v47)

    /** هل عمود المتابعة اترحّل؟ (نفس نمط permitPermReady — النظام يشتغل قبل الهجرة) */
    public static function watchPermReady(): bool
    {
        static $ok = null;
        if ($ok === null) {
            $ok = (bool) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'reps' AND column_name = 'can_watch_reps'"
            );
        }
        return $ok;
    }

    /**
     * هل المندوب ده مشرف يتابع باقي المناديب من موبايله؟
     *
     * ⚠️ **الرفض هو الافتراضي** (تحذير 5): قبل migrate_v47 بترجّع false لكل
     * الناس، فالتبويب بيختفي والشاشة بترفض. ده المصدر الوحيد للفحص؛ ممنوع
     * تكتب الشرط بإيدك في أي مكان تاني.
     */
    public static function canWatchReps(int $repId): bool
    {
        if ($repId <= 0 || !self::watchPermReady()) { return false; }
        return (int) Database::scalar(
            "SELECT can_watch_reps FROM reps WHERE id = ?", [$repId]) === 1;
    }

    /** المالك بيدّي/بيسحب صلاحية المتابعة من كارت المندوب. */
    public static function setCanWatchReps(int $repId, bool $allowed): void
    {
        if (!self::watchPermReady()) {
            throw new \RuntimeException('شغّل php bin/migrate_v47.php الأول عشان تتحكّم في الصلاحية دي.');
        }
        Database::exec("UPDATE reps SET can_watch_reps = ? WHERE id = ?", [$allowed ? 1 : 0, $repId]);
    }

    public static function create(array $d): int
    {
        Database::exec(
            "INSERT INTO reps (name, phone, monthly_salary, hire_date, status, note)
             VALUES (:name, :phone, :monthly_salary, :hire_date, :status, :note)",
            self::bindRep($d));
        return Database::lastId();
    }

    public static function update(int $id, array $d): void
    {
        $p = self::bindRep($d);
        $p[':id'] = $id;
        Database::exec(
            "UPDATE reps SET name=:name, phone=:phone, monthly_salary=:monthly_salary,
                    hire_date=:hire_date, status=:status, note=:note WHERE id=:id", $p);
    }

    /** حذف الكارت النظيف فقط؛ أي تاريخ يحوّل العملية إلى تعطيل يحفظ السجل. */
    public static function delete(int $id): array
    {
        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            $rep = Database::row('SELECT * FROM reps WHERE id=? FOR UPDATE', [$id]);
            if (!$rep) { if ($own) { $pdo->commit(); } return ['deleted' => false, 'deactivated' => false, 'history' => []]; }
            $history = DeletionPolicy::repHistory($id);
            if (array_sum($history) > 0) {
                Database::exec("UPDATE reps SET status='inactive' WHERE id=?", [$id]);
                Database::exec("UPDATE rep_users SET status='disabled' WHERE rep_id=?", [$id]);
                Database::exec("UPDATE permit_assignments SET status='done',done_at=COALESCE(done_at,NOW()) WHERE rep_id=? AND status='active'", [$id]);
                Audit::log('deactivate', 'reps', $id, $rep, self::find($id),
                    'تعطيل مندوب بدل حذفه لوجود تاريخ مالي أو تشغيلي', ['history' => $history]);
                if ($own) { $pdo->commit(); }
                return ['deleted' => false, 'deactivated' => true, 'history' => $history];
            }
            Database::exec('DELETE FROM rep_users WHERE rep_id=?', [$id]);
            Database::exec('DELETE FROM reps WHERE id=?', [$id]);
            Audit::log('delete', 'reps', $id, $rep, null, 'حذف كارت مندوب بلا أي تاريخ', [], false);
            if ($own) { $pdo->commit(); }
            return ['deleted' => true, 'deactivated' => false, 'history' => $history];
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    private static function bindRep(array $d): array
    {
        return [
            ':name'           => trim((string) ($d['name'] ?? '')),
            ':phone'          => ($d['phone'] ?? '') !== '' ? $d['phone'] : null,
            ':monthly_salary' => (float) ($d['monthly_salary'] ?? 0),
            ':hire_date'      => ($d['hire_date'] ?? '') !== '' ? $d['hire_date'] : null,
            ':status'         => ($d['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            ':note'           => ($d['note'] ?? '') !== '' ? $d['note'] : null,
        ];
    }

    // ─────────────────────────────────────────────── الرصيد والملخص

    /** رصيد المندوب = المستحق له (Σ مستحقات − Σ خصومات). */
    public static function balance(int $repId): float
    {
        $credit = "'" . implode("','", self::CREDIT_KINDS) . "'";
        return round((float) Database::scalar(
            "SELECT COALESCE(SUM(CASE WHEN kind IN ($credit) THEN amount ELSE -amount END),0)
             FROM rep_entries WHERE rep_id = ?", [$repId]), 2);
    }

    /**
     * ملخّص أرقام المندوب (لبطاقات البروفايل):
     *   due (مستحقات), deductions, advances, paid (تسويات), balance.
     */
    public static function summary(int $repId): array
    {
        $rows = Database::all(
            "SELECT kind, COALESCE(SUM(amount),0) v FROM rep_entries WHERE rep_id = ? GROUP BY kind",
            [$repId]);
        $by = [];
        foreach ($rows as $r) { $by[$r['kind']] = (float) $r['v']; }
        $sum = fn(array $keys) => array_sum(array_map(fn($k) => $by[$k] ?? 0.0, $keys));

        $due        = $sum(self::CREDIT_KINDS);
        $deductions = $sum(['expense_deducted', 'deduction']);
        $advances   = $sum(['advance']);
        $paid       = $sum(['settlement']);
        return [
            'due'        => round($due, 2),
            'deductions' => round($deductions, 2),
            'advances'   => round($advances, 2),
            'paid'       => round($paid, 2),
            'balance'    => round($due - $deductions - $advances - $paid, 2),
        ];
    }

    // ─────────────────────────────────────────────── الكشف (سجل بطرفين)

    /**
     * كشف حساب المندوب من الأقدم للأحدث مع رصيد جارٍ، ثم معكوس للعرض (الأحدث أولاً).
     * كل صف: id, d, kind, label, amount(المخزَّن), signed(±), is_visa, note, settlement_id, running.
     */
    public static function ledger(int $repId): array
    {
        $rows = Database::all(
            "SELECT id, entry_date AS d, kind, amount, is_visa, note, settlement_id
             FROM rep_entries WHERE rep_id = ?
             ORDER BY (entry_date IS NULL), entry_date, id", [$repId]);

        $run = 0.0;
        foreach ($rows as &$r) {
            $signed = self::sign($r['kind']) * (float) $r['amount'];
            $run = round($run + $signed, 2);
            $r['label']   = self::kindLabel($r['kind']);
            $r['signed']  = round($signed, 2);
            $r['amount']  = round((float) $r['amount'], 2);
            $r['running'] = $run;
        }
        unset($r);
        return array_reverse($rows);
    }

    // ─────────────────────────────────────────────── إدارة البنود

    public static function findEntry(int $id): ?array
    {
        return Database::row("SELECT * FROM rep_entries WHERE id = ?", [$id]);
    }

    public static function createEntry(int $repId, array $d): int
    {
        Database::exec(
            "INSERT INTO rep_entries (rep_id, entry_date, kind, amount, is_visa, note)
             VALUES (:rep_id, :entry_date, :kind, :amount, :is_visa, :note)",
            self::bindEntry($repId, $d));
        return Database::lastId();
    }

    public static function updateEntry(int $id, int $repId, array $d): void
    {
        $p = self::bindEntry($repId, $d);
        $p[':id'] = $id;
        Database::exec(
            "UPDATE rep_entries SET entry_date=:entry_date, kind=:kind, amount=:amount,
                    is_visa=:is_visa, note=:note WHERE id=:id", $p);
    }

    public static function deleteEntry(int $id): void
    {
        Database::exec("DELETE FROM rep_entries WHERE id = ?", [$id]);
    }

    private static function bindEntry(int $repId, array $d): array
    {
        $kind = (string) ($d['kind'] ?? '');
        if (!isset(self::KINDS[$kind])) { $kind = 'expense_logistic'; }
        return [
            ':rep_id'     => $repId,
            ':entry_date' => ($d['entry_date'] ?? '') !== '' ? $d['entry_date'] : null,
            ':kind'       => $kind,
            ':amount'     => abs((float) ($d['amount'] ?? 0)),
            ':is_visa'    => !empty($d['is_visa']) ? 1 : 0,
            ':note'       => ($d['note'] ?? '') !== '' ? $d['note'] : null,
        ];
    }

    // ─────────────────────────────────────────────── التقفيل الدوري

    /**
     * معاينة تقفيل فترة [from,to]: يجمّع البنود غير المتحاسبة (settlement_id IS NULL)
     * وليست دفعات تسوية، ويحسب الصافي المستحق دفعه الآن.
     * يُعيد ['due','deductions','advances','net','count', 'rows'].
     */
    public static function settlementPreview(int $repId, ?string $from, ?string $to): array
    {
        [$w, $p] = self::periodWhere($repId, $from, $to);
        $rows = Database::all(
            "SELECT id, entry_date AS d, kind, amount, is_visa, note
             FROM rep_entries
             WHERE $w AND settlement_id IS NULL AND kind <> 'settlement'
             ORDER BY (entry_date IS NULL), entry_date, id", $p);

        $due = 0.0; $ded = 0.0; $adv = 0.0;
        foreach ($rows as $r) {
            $a = (float) $r['amount'];
            if (in_array($r['kind'], self::CREDIT_KINDS, true))       { $due += $a; }
            elseif ($r['kind'] === 'advance')                         { $adv += $a; }
            else                                                      { $ded += $a; } // expense_deducted | deduction
        }
        return [
            'due'        => round($due, 2),
            'deductions' => round($ded, 2),
            'advances'   => round($adv, 2),
            'net'        => round($due - $ded - $adv, 2),
            'count'      => count($rows),
            'rows'       => $rows,
        ];
    }

    /**
     * تنفيذ التقفيل: يثبّت صف tssettlement، يربط بنود الفترة به، ويُنشئ بند «دفعة تسوية»
     * (settlement) بتاريخ الدفع (كاش يخرج من الخزنة → يدخل المصروفات/العهدة).
     * يُعيد settlement id. لازم الاستدعاء يمرّ بـ Closing::guard($payDate) قبلها.
     */
    public static function settle(int $repId, ?string $from, ?string $to, string $payDate, bool $isVisa, string $note = ''): int
    {
        $pre = self::settlementPreview($repId, $from, $to);

        Database::exec(
            "INSERT INTO rep_settlements
                (rep_id, period_from, period_to, total_due, total_deductions, total_advances,
                 net, paid_amount, is_visa, note, settled_by, pay_date)
             VALUES (:rep_id,:pf,:pt,:due,:ded,:adv,:net,:paid,:visa,:note,:by,:pay)",
            [
                ':rep_id' => $repId,
                ':pf'     => $from ?: null,
                ':pt'     => $to ?: null,
                ':due'    => $pre['due'],
                ':ded'    => $pre['deductions'],
                ':adv'    => $pre['advances'],
                ':net'    => $pre['net'],
                ':paid'   => $pre['net'],
                ':visa'   => $isVisa ? 1 : 0,
                ':note'   => $note !== '' ? $note : null,
                ':by'     => \App\Core\Auth::user(),
                ':pay'    => $payDate,
            ]);
        $sid = Database::lastId();

        // اربط بنود الفترة غير المتحاسبة بالتقفيل (تتجمّد ومتدخلش تقفيل تاني)
        [$w, $p] = self::periodWhere($repId, $from, $to);
        Database::exec(
            "UPDATE rep_entries SET settlement_id = ?
             WHERE $w AND settlement_id IS NULL AND kind <> 'settlement'",
            array_merge([$sid], $p));

        // بند «دفعة التسوية» — كاش يخرج فعلاً (يدخل المصروفات/العهدة)
        Database::exec(
            "INSERT INTO rep_entries (rep_id, entry_date, kind, amount, is_visa, note, settlement_id)
             VALUES (?,?,?,?,?,?,?)",
            [$repId, $payDate, 'settlement', abs($pre['net']), $isVisa ? 1 : 0,
             'تسوية حساب' . ($from || $to ? ' (' . dfmt($from) . ' – ' . dfmt($to) . ')' : ''), $sid]);

        return $sid;
    }

    /** بيانات تقفيل واحد (للطباعة). */
    public static function settlement(int $id): ?array
    {
        return Database::row("SELECT * FROM rep_settlements WHERE id = ?", [$id]);
    }

    /** بنود تقفيل معيّن (المرتبطة به). */
    public static function settlementEntries(int $settlementId): array
    {
        return Database::all(
            "SELECT id, entry_date AS d, kind, amount, is_visa, note
             FROM rep_entries WHERE settlement_id = ? AND kind <> 'settlement'
             ORDER BY (entry_date IS NULL), entry_date, id", [$settlementId]);
    }

    /** كل تقفيلات المندوب (الأحدث أولاً). */
    public static function settlements(int $repId): array
    {
        return Database::all(
            "SELECT * FROM rep_settlements WHERE rep_id = ? ORDER BY settled_at DESC, id DESC", [$repId]);
    }

    /** شرط الفترة على rep_entries. from/to شاملان (inclusive). */
    private static function periodWhere(int $repId, ?string $from, ?string $to): array
    {
        $w = ["rep_id = ?"]; $p = [$repId];
        if ($from) { $w[] = "(entry_date IS NULL OR entry_date >= ?)"; $p[] = $from; }
        if ($to)   { $w[] = "(entry_date IS NULL OR entry_date <= ?)"; $p[] = $to; }
        return [implode(' AND ', $w), $p];
    }

    // ─────────────────────────────────────────────── مصدر المصروفات العامة / العهدة

    /**
     * تعبير SQL موحّد لبنود المناديب اللي بتطلّع كاش من الخزنة (advance + settlement).
     * أعمدة موحّدة زي باقي مصادر Expense: source, ref_id, d, category, party, amount, note, is_visa.
     * ref_id = rep_id (عشان الرابط يروح لكارت المندوب).
     */
    public static function expenseUnionSql(): string
    {
        $cash = "'" . implode("','", self::CASH_KINDS) . "'";
        return "
            SELECT 'reps' AS source, e.rep_id AS ref_id, e.entry_date AS d,
                   CASE e.kind WHEN 'advance' THEN 'سُلفة مندوب' ELSE 'تسوية حساب مندوب' END AS category,
                   COALESCE(NULLIF(r.name,''),'') AS party,
                   e.amount AS amount, COALESCE(e.note, r.name) AS note, e.is_visa AS is_visa,
                   0 AS unpaid
            FROM rep_entries e
            JOIN reps r ON r.id = e.rep_id
            WHERE e.kind IN ($cash) AND e.amount <> 0
        ";
    }

    /** قائمة أنواع الكاش كنص SQL جاهز للـ IN(...) — يستخدمها Closing. */
    public static function cashKindsList(): string
    {
        return "'" . implode("','", self::CASH_KINDS) . "'";
    }
}
