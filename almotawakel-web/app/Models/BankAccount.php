<?php
namespace App\Models;

use App\Core\Database;

/**
 * الحسابات البنكية — المحفظة الأم لكل كروت الفيزا.
 *
 * الحقيقة المالية الوحيدة:
 *   رصيد الحساب = Σ حركات الحساب الموقّعة في visa_deposits
                − Σ المدفوعات المنسوبة لكل الكروت التابعة له.
 *
 * اسم `visa_deposits` باقٍ في القاعدة للتوافق مع مراجع التحصيل وخزنة الشركة،
 * لكن الصف من v110 حركة **حساب بنكي** وليس شحنة كارت.
 */
class BankAccount
{
    private static ?bool $ready = null;

    public static function ready(): bool
    {
        if (self::$ready === null) {
            self::$ready = (bool) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema=DATABASE() AND table_name='bank_accounts'"
            ) && (bool) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.columns
                  WHERE table_schema=DATABASE() AND table_name='visa_cards'
                    AND column_name='bank_account_id'"
            );
        }
        return self::$ready;
    }

    public static function refreshReady(): void
    {
        self::$ready = null;
    }

    public static function all(bool $activeOnly = false): array
    {
        if (!self::ready()) { return []; }
        $where = $activeOnly ? "WHERE status='active'" : '';
        return Database::all("SELECT * FROM bank_accounts $where ORDER BY status='inactive', name, id");
    }

    public static function find(int $id): ?array
    {
        if ($id <= 0 || !self::ready()) { return null; }
        return Database::row('SELECT * FROM bank_accounts WHERE id=?', [$id]);
    }

    public static function isActive(int $id): bool
    {
        return $id > 0 && self::ready() && (string) Database::scalar(
            'SELECT status FROM bank_accounts WHERE id=?', [$id]) === 'active';
    }

    public static function create(array $d): int
    {
        if (!self::ready()) { throw new \RuntimeException('وحدة الحسابات البنكية مش مفعّلة.'); }
        $v = self::normalise($d);
        Database::exec(
            'INSERT INTO bank_accounts(name,bank_name,account_ref,owner,status,note)
             VALUES(?,?,?,?,?,?)',
            [$v['name'], $v['bank_name'], $v['account_ref'], $v['owner'], $v['status'], $v['note']]
        );
        return Database::lastId();
    }

    public static function update(int $id, array $d): void
    {
        if (!self::find($id)) { throw new \RuntimeException('الحساب البنكي مش موجود.'); }
        $v = self::normalise($d);
        self::transactionalWrite(static function () use ($id, $v): void {
            Database::exec(
                'UPDATE bank_accounts SET name=?,bank_name=?,account_ref=?,owner=?,status=?,note=? WHERE id=?',
                [$v['name'], $v['bank_name'], $v['account_ref'], $v['owner'], $v['status'], $v['note'], $id]
            );
            // العمود القديم باقٍ للتوافق مع التقارير/النسخ السابقة؛ الحساب هو مصدره الوحيد.
            Database::exec('UPDATE visa_cards SET bank=? WHERE bank_account_id=?', [
                $v['bank_name'] ?? $v['name'], $id,
            ]);
        });
    }

    public static function delete(int $id): void
    {
        $account = self::find($id);
        if (!$account) { return; }
        $cards = (int) Database::scalar('SELECT COUNT(*) FROM visa_cards WHERE bank_account_id=?', [$id]);
        $entries = (int) Database::scalar('SELECT COUNT(*) FROM visa_deposits WHERE bank_account_id=?', [$id]);
        if ($cards || $entries) {
            throw new \RuntimeException('الحساب عليه كروت أو حركات؛ وقّفه بدل الحذف عشان السجل المالي يفضل محفوظ.');
        }
        Database::exec('DELETE FROM bank_accounts WHERE id=?', [$id]);
    }

    /** @return array{name:string,bank_name:?string,account_ref:?string,owner:?string,status:string,note:?string} */
    private static function normalise(array $d): array
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') { throw new \RuntimeException('اكتب اسم الحساب البنكي.'); }
        $clean = static fn ($v) => trim((string) $v) !== '' ? trim((string) $v) : null;
        return [
            'name'        => mb_substr($name, 0, 180),
            'bank_name'   => $clean($d['bank_name'] ?? null),
            'account_ref' => $clean($d['account_ref'] ?? null),
            'owner'       => $clean($d['owner'] ?? null),
            'status'      => ($d['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            'note'        => $clean($d['note'] ?? null),
        ];
    }

    public static function cards(int $accountId, bool $activeOnly = false): array
    {
        $where = $activeOnly ? " AND status='active'" : '';
        return Database::all(
            "SELECT * FROM visa_cards WHERE bank_account_id=?$where ORDER BY status='inactive',name,id",
            [$accountId]
        );
    }

    public static function entries(int $accountId): array
    {
        return Database::all(
            'SELECT d.*,
                    EXISTS(SELECT 1 FROM client_payments cp WHERE cp.deposit_id=d.id) client_linked,
                    EXISTS(SELECT 1 FROM company_safe_entries cs WHERE cs.visa_deposit_id=d.id) company_linked
               FROM visa_deposits d WHERE d.bank_account_id=?
              ORDER BY (d.dep_date IS NULL),d.dep_date DESC,d.id DESC',
            [$accountId]
        );
    }

    public static function findEntry(int $id): ?array
    {
        return Database::row('SELECT * FROM visa_deposits WHERE id=?', [$id]);
    }

    /**
     * حركة موقّعة: الموجب إيداع، والسالب سحب. منع السالب يحصل هنا داخل قفل الحساب.
     */
    public static function createEntry(int $accountId, array $d): int
    {
        $account = self::find($accountId);
        if (!$account || $account['status'] !== 'active') {
            throw new \RuntimeException('الحساب البنكي مش موجود أو متوقف.');
        }
        $amount = round((float) ($d['amount'] ?? 0), 2);
        if (abs($amount) < 0.005) { throw new \RuntimeException('اكتب مبلغًا غير صفر.'); }
        $date = trim((string) ($d['dep_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = work_date(); }
        $note = trim((string) ($d['note'] ?? ''));
        $cardId = !empty($d['card_id']) ? (int) $d['card_id'] : null;
        if ($cardId !== null && self::ready()) {
            $cardAccountId = VisaCard::accountId($cardId, false);
            if ($cardAccountId !== $accountId) {
                throw new \RuntimeException('الكارت المحدد مش تابع للحساب البنكي ده.');
            }
        }

        return self::withAccountSpendDeltas([$accountId => -$amount], static function () use (
            $accountId, $date, $amount, $note, $cardId
        ): int {
            Database::exec(
                'INSERT INTO visa_deposits(card_id,bank_account_id,dep_date,amount,note)
                 VALUES(?,?,?,?,?)',
                [$cardId, $accountId, $date, $amount, $note !== '' ? $note : null]
            );
            return Database::lastId();
        });
    }

    /**
     * حذف الحركة اليدوية. حذف إيداع يُرفض لو هيجعل الرصيد سالبًا.
     * `$linked=true` للمصدر الأصلي فقط (تحصيل عميل/خزنة شركة) أثناء العكس.
     */
    public static function deleteEntry(int $id, bool $linked = false, bool $guard = true): void
    {
        $row = self::findEntry($id);
        if (!$row) { return; }
        if (!$linked) {
            $used = (int) Database::scalar(
                'SELECT (EXISTS(SELECT 1 FROM client_payments WHERE deposit_id=?))
                      + (EXISTS(SELECT 1 FROM company_safe_entries WHERE visa_deposit_id=?))',
                [$id, $id]
            );
            if ($used) { throw new \RuntimeException('الحركة مولّدة من وحدة تانية؛ اعكسها من مصدرها.'); }
        }
        $accountId = (int) $row['bank_account_id'];
        if (!$guard) {
            if (!Database::conn()->inTransaction()) {
                throw new \RuntimeException('الحذف الداخلي بلا حارس لازم يكون داخل معاملة.');
            }
            Database::exec('DELETE FROM visa_deposits WHERE id=?', [$id]);
            return;
        }
        self::withAccountSpendDeltas([$accountId => (float) $row['amount']], static function () use ($id): void {
            Database::exec('DELETE FROM visa_deposits WHERE id=?', [$id]);
        });
    }

    /** إجمالي الحركات الموقّعة للحساب. */
    public static function entriesTotal(int $accountId): float
    {
        return round((float) Database::scalar(
            'SELECT COALESCE(SUM(amount),0) FROM visa_deposits WHERE bank_account_id=?', [$accountId]
        ), 2);
    }

    /** إجمالي الصرف بكل كروت الحساب. */
    public static function paymentsTotal(int $accountId): float
    {
        if (!self::ready()) { return 0.0; }
        return round((float) Database::scalar(
            'SELECT COALESCE(SUM(t.amount),0)
               FROM (' . VisaCard::paymentsUnion() . ') t
               JOIN visa_cards c ON c.id=t.card_id
              WHERE c.bank_account_id=?', [$accountId]
        ), 2);
    }

    public static function balanceOf(int $accountId): float
    {
        if (!self::find($accountId)) { return 0.0; }
        return round(self::entriesTotal($accountId) - self::paymentsTotal($accountId), 2);
    }

    /** كل الحسابات مع الأرصدة من استعلامات مجمّعة بلا N+1. */
    public static function withBalances(bool $activeOnly = false): array
    {
        $accounts = self::all($activeOnly);
        if (!$accounts) { return []; }
        $entries = [];
        foreach (Database::all(
            'SELECT bank_account_id id,COALESCE(SUM(amount),0) total,COUNT(*) cnt
               FROM visa_deposits GROUP BY bank_account_id'
        ) as $r) {
            $entries[(int) $r['id']] = ['total' => round((float) $r['total'], 2), 'count' => (int) $r['cnt']];
        }
        $payments = [];
        foreach (Database::all(
            'SELECT c.bank_account_id id,COALESCE(SUM(t.amount),0) total,COUNT(*) cnt
               FROM (' . VisaCard::paymentsUnion() . ') t
               JOIN visa_cards c ON c.id=t.card_id
              GROUP BY c.bank_account_id'
        ) as $r) {
            $payments[(int) $r['id']] = ['total' => round((float) $r['total'], 2), 'count' => (int) $r['cnt']];
        }
        $cardCounts = [];
        foreach (Database::all('SELECT bank_account_id id,COUNT(*) cnt FROM visa_cards GROUP BY bank_account_id') as $r) {
            $cardCounts[(int) $r['id']] = (int) $r['cnt'];
        }
        foreach ($accounts as &$a) {
            $id = (int) $a['id'];
            $a['entry_total'] = $entries[$id]['total'] ?? 0.0;
            $a['entry_count'] = $entries[$id]['count'] ?? 0;
            $a['pay_total']   = $payments[$id]['total'] ?? 0.0;
            $a['pay_count']   = $payments[$id]['count'] ?? 0;
            $a['cards_count'] = $cardCounts[$id] ?? 0;
            $a['balance']     = round($a['entry_total'] - $a['pay_total'], 2);
        }
        unset($a);
        return $accounts;
    }

    public static function summary(): array
    {
        $out = ['count' => 0, 'cards' => 0, 'balance' => 0.0, 'entries' => 0.0, 'payments' => 0.0];
        foreach (self::withBalances() as $a) {
            $out['count']++;
            $out['cards'] += (int) $a['cards_count'];
            $out['balance'] += (float) $a['balance'];
            $out['entries'] += (float) $a['entry_total'];
            $out['payments'] += (float) $a['pay_total'];
        }
        foreach (['balance', 'entries', 'payments'] as $k) { $out[$k] = round($out[$k], 2); }
        return $out;
    }

    /** مدفوعات الحساب مع اسم الكارت. */
    public static function payments(int $accountId): array
    {
        return Database::all(
            'SELECT t.*,c.name card_name,c.owner card_owner
               FROM (' . VisaCard::paymentsUnion() . ') t
               JOIN visa_cards c ON c.id=t.card_id
              WHERE c.bank_account_id=?
              ORDER BY (t.ts IS NULL),t.ts DESC,(t.d IS NULL),t.d DESC,t.source',
            [$accountId]
        );
    }

    /** دفتر الحساب: حركات (+/−) + صرف الكروت (−) مع رصيد جارٍ. */
    public static function ledger(int $accountId): array
    {
        $rows = [];
        foreach (self::entries($accountId) as $e) {
            $rows[] = [
                'kind' => 'entry', 'id' => (int) $e['id'], 'd' => $e['dep_date'],
                'ts' => $e['created_at'] ?? null, 'label' => (float) $e['amount'] >= 0 ? 'إيداع' : 'سحب',
                'party' => $e['note'] ?: '—', 'card' => null, 'source' => null,
                'amount' => round((float) $e['amount'], 2), 'url' => null,
                'linked' => !empty($e['client_linked']) || !empty($e['company_linked']),
            ];
        }
        foreach (self::payments($accountId) as $p) {
            $rows[] = [
                'kind' => 'payment', 'd' => $p['d'], 'ts' => $p['ts'] ?? null,
                'label' => $p['category'], 'party' => $p['party'] ?: '—',
                'card' => $p['card_name'], 'source' => $p['source'], 'note' => $p['note'],
                'amount' => -round((float) $p['amount'], 2),
                'url' => Expense::originUrl($p['source'], $p['ref_id']), 'linked' => true,
            ];
        }
        // تاريخ المستند هو محور الكشف؛ وقت الإنشاء يفصل فقط بين حركتين في اليوم
        // نفسه. استخدام created_at كاملًا كان يضع قيدًا رجعيًا في يوم إنشائه.
        $key = static function (array $r): string {
            if (!empty($r['d'])) {
                $time = !empty($r['ts']) && strlen((string) $r['ts']) >= 19
                    ? substr((string) $r['ts'], 11, 8) : '00:00:00';
                return (string) $r['d'] . ' ' . $time;
            }
            return !empty($r['ts']) ? (string) $r['ts'] : '';
        };
        usort($rows, static function ($a, $b) use ($key): int {
            $ka = $key($a); $kb = $key($b);
            if ($ka === '' && $kb !== '') { return -1; }
            if ($kb === '' && $ka !== '') { return 1; }
            return $ka <=> $kb;
        });
        $running = 0.0;
        foreach ($rows as &$r) { $running = round($running + $r['amount'], 2); $r['running'] = $running; }
        unset($r);
        return array_reverse($rows);
    }

    /**
     * يطبّق تغيّرًا في صرف كروت مع قفل الحسابات. كل عنصر: old_card/old_amount/new_card/new_amount.
     * الموجب في delta = صرف إضافي؛ السالب = ردّ رصيد.
     */
    public static function withCardSpendChanges(array $changes, callable $write): mixed
    {
        if (!self::ready()) { return self::transactionalWrite($write); }
        $deltas = [];
        foreach ($changes as $change) {
            $oldCard = (int) ($change['old_card'] ?? 0);
            $newCard = (int) ($change['new_card'] ?? 0);
            // المبالغ الطبيعيّة موجبة، لكن الحساب الموقّع يجعل الحارس صحيحًا
            // حتى لو وُجد تصحيح تاريخي سالب: حذف الائتمان قد يخفض الرصيد.
            $oldAmount = round((float) ($change['old_amount'] ?? 0), 2);
            $newAmount = round((float) ($change['new_amount'] ?? 0), 2);
            if (abs($newAmount) > 0.005 && $newCard <= 0) {
                throw new \RuntimeException('اختار كارت الفيزا اللي اتدفعت منه العملية.');
            }
            if ($oldCard > 0 && abs($oldAmount) > 0.005) {
                $oldAccount = VisaCard::accountId($oldCard, false);
                if ($oldAccount > 0) { $deltas[$oldAccount] = ($deltas[$oldAccount] ?? 0.0) - $oldAmount; }
            }
            if ($newCard > 0 && abs($newAmount) > 0.005) {
                $newAccount = VisaCard::accountId($newCard, false);
                if ($newAccount <= 0) { throw new \RuntimeException('كارت الفيزا أو حسابه البنكي مش موجود.'); }
                // تعديل وصفي لبند تاريخي على نفس الكارت المتوقف مسموح؛ أي صرف
                // جديد أو زيادة فعلية تظل محتاجة كارتًا وحسابًا نشطين.
                $needsActive = $newCard !== $oldCard || $newAmount > $oldAmount + 0.005;
                if ($needsActive && !VisaCard::isActive($newCard)) {
                    throw new \RuntimeException('كارت الفيزا أو حسابه البنكي متوقف.');
                }
                $deltas[$newAccount] = ($deltas[$newAccount] ?? 0.0) + $newAmount;
            }
        }
        return self::withAccountSpendDeltas($deltas, $write);
    }

    /** @param array<int,float> $deltas موجب = خفض الرصيد */
    public static function withAccountSpendDeltas(array $deltas, callable $write): mixed
    {
        if (!self::ready()) { return self::transactionalWrite($write); }
        $clean = [];
        foreach ($deltas as $id => $delta) {
            $id = (int) $id;
            if ($id > 0 && abs((float) $delta) > 0.005) {
                $clean[$id] = round(($clean[$id] ?? 0.0) + (float) $delta, 2);
            }
        }
        // حتى لو صافي التغيير صفر، callback قد يحذف صفًا مرتبطًا ثم يعيد إنشاءه؛
        // لازم يفضل ذريًا بالكامل لو الكتابة الثانية فشلت.
        if (!$clean) { return self::transactionalWrite($write); }
        ksort($clean, SORT_NUMERIC);
        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            foreach ($clean as $accountId => $delta) {
                $account = Database::row('SELECT id,name,status FROM bank_accounts WHERE id=? FOR UPDATE', [$accountId]);
                if (!$account) { throw new \RuntimeException('الحساب البنكي مش موجود.'); }
                if ($delta > 0.005 && $account['status'] !== 'active') {
                    throw new \RuntimeException('الحساب البنكي «' . $account['name'] . '» متوقف.');
                }
                $balance = self::balanceOf($accountId);
                if ($delta > $balance + 0.005) {
                    throw new \RuntimeException(
                        'الرصيد لا يكفي في حساب «' . $account['name'] . '»؛ المتاح '
                        . money($balance, false) . ' والمطلوب ' . money($delta, false) . '.'
                    );
                }
            }
            $result = $write();
            if ($own) { $pdo->commit(); }
            return $result;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /** يحافظ على ذرّية مسارات الكتابة نفسها قبل تطبيق v110 أيضًا. */
    private static function transactionalWrite(callable $write): mixed
    {
        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            $result = $write();
            if ($own) { $pdo->commit(); }
            return $result;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }
}
