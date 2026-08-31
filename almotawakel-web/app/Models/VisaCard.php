<?php
namespace App\Models;

use App\Core\Database;

/**
 * كروت الفيزا التابعة للحسابات البنكية.
 *
 * القاعدة:
 *   • كل كارت تابع لحساب بنكي واحد، وكل كروت الحساب تشترك في رصيده.
 *   • كل عملية مدفوعة بالفيزا تُنسَب للكارت فتُخصم من **حسابه البنكي**.
 *     مصادر المدفوعات:
 *       manual_expenses / permit_stages / movement_items / debt_payments / rep_misc_expenses
 * الرصيد وحركاته في `BankAccount`؛ هذا الموديل يحتفظ بهوية الكارت ونشاطه فقط.
 */
class VisaCard
{
    /** تسميات مصادر مدفوعات الفيزا (للشارات والتصفية) */
    public const SOURCES = [
        'manual'  => 'مصروف يدوي',
        'permits' => 'الإصدارات',
        'bawalis' => 'البوالص',
        'debt'    => 'سداد مديونية',
        'rep_misc'=> 'نثريات المناديب',   // v45
    ];

    // ─────────────────────────────────────────────── الكروت (CRUD)

    /** كل الكروت (مع خيار الحالة). */
    public static function all(bool $activeOnly = false): array
    {
        $where = $activeOnly ? "WHERE c.status='active' AND a.status='active'" : '';
        if (!BankAccount::ready()) {
            $legacy = $activeOnly ? "WHERE status='active'" : '';
            return Database::all("SELECT * FROM visa_cards $legacy ORDER BY status='inactive',name");
        }
        return Database::all(
            "SELECT c.*,a.name account_name,a.bank_name account_bank,a.status account_status
               FROM visa_cards c JOIN bank_accounts a ON a.id=c.bank_account_id
               $where ORDER BY c.status='inactive',a.name,c.name"
        );
    }

    public static function find(int $id): ?array
    {
        if (!BankAccount::ready()) { return Database::row('SELECT * FROM visa_cards WHERE id=?', [$id]); }
        return Database::row(
            'SELECT c.*,a.name account_name,a.bank_name account_bank,a.status account_status
               FROM visa_cards c JOIN bank_accounts a ON a.id=c.bank_account_id WHERE c.id=?', [$id]
        );
    }

    /**
     * هل الكارت موجود وشغّال؟ — للتحقق من أي اختيار جاي من فورم (v45).
     * المصدر الوحيد لفحص صلاحية كارت مختار؛ الفورم مش مصدر ثقة.
     */
    public static function isActive(int $id): bool
    {
        if ($id <= 0) { return false; }
        if (!BankAccount::ready()) {
            return (string) Database::scalar('SELECT status FROM visa_cards WHERE id=?', [$id]) === 'active';
        }
        return (bool) Database::scalar(
            "SELECT COUNT(*) FROM visa_cards c JOIN bank_accounts a ON a.id=c.bank_account_id
              WHERE c.id=? AND c.status='active' AND a.status='active'", [$id]
        );
    }

    /** حساب الكارت؛ `$active=true` يشترط نشاط الكارت والحساب معًا. */
    public static function accountId(int $id, bool $active = true): int
    {
        if ($id <= 0 || !BankAccount::ready()) { return 0; }
        $where = $active ? " AND c.status='active' AND a.status='active'" : '';
        return (int) Database::scalar(
            "SELECT c.bank_account_id FROM visa_cards c
              JOIN bank_accounts a ON a.id=c.bank_account_id WHERE c.id=?$where", [$id]
        );
    }

    /** خريطة [id => name] للعرض السريع في الجداول والسجلات. */
    public static function nameMap(): array
    {
        $out = [];
        foreach (self::all(false) as $c) {
            $out[(int) $c['id']] = $c['name'] . ($c['owner'] ? ' — ' . $c['owner'] : '')
                . (!empty($c['account_name']) ? ' — ' . $c['account_name'] : '');
        }
        return $out;
    }

    public static function create(array $d): int
    {
        $accountId = (int) ($d['bank_account_id'] ?? 0);
        if (BankAccount::ready() && !BankAccount::isActive($accountId)) {
            throw new \RuntimeException('اختار حسابًا بنكيًا نشطًا للكارت.');
        }
        $bind = self::bind($d);
        if (BankAccount::ready()) {
            Database::exec(
                "INSERT INTO visa_cards (name,owner,bank,bank_account_id,opening_balance,status,note)
                 VALUES (:name,:owner,:bank,:bank_account_id,:opening_balance,:status,:note)",
                $bind + [':bank_account_id' => $accountId]
            );
            return Database::lastId();
        }
        Database::exec(
            "INSERT INTO visa_cards (name, owner, bank, opening_balance, status, note)
             VALUES (:name, :owner, :bank, :opening_balance, :status, :note)", $bind);
        return Database::lastId();
    }

    public static function update(int $id, array $d): void
    {
        $p = self::bind($d);
        $p[':id'] = $id;
        if (BankAccount::ready()) {
            $old = self::find($id);
            if (!$old) { throw new \RuntimeException('الكارت مش موجود.'); }
            $accountId = (int) ($d['bank_account_id'] ?? 0);
            if (!BankAccount::isActive($accountId) && $accountId !== (int) $old['bank_account_id']) {
                throw new \RuntimeException('اختار حسابًا بنكيًا نشطًا للكارت.');
            }
            if ($accountId !== (int) $old['bank_account_id'] && self::usageCount($id) > 0) {
                throw new \RuntimeException('الكارت عليه عمليات؛ ما ينفعش تنقله لحساب تاني لأن ده هينقل تاريخه المالي. أضف كارتًا جديدًا.');
            }
            $p[':bank_account_id'] = $accountId;
            Database::exec(
                "UPDATE visa_cards SET name=:name,owner=:owner,bank=:bank,
                        bank_account_id=:bank_account_id,opening_balance=:opening_balance,
                        status=:status,note=:note WHERE id=:id", $p
            );
            return;
        }
        Database::exec(
            "UPDATE visa_cards SET name=:name, owner=:owner, bank=:bank,
                    opening_balance=:opening_balance, status=:status, note=:note WHERE id=:id", $p);
    }

    /** الكارت المستخدم ماليًا لا يُحذف؛ يُوقَف حتى يظل التاريخ منسوبًا له. */
    public static function delete(int $id): void
    {
        if (BankAccount::ready()) {
            if (self::usageCount($id) > 0) {
                throw new \RuntimeException('الكارت عليه عمليات مالية؛ وقّفه بدل الحذف عشان السجل يفضل صحيحًا.');
            }
            Database::exec('DELETE FROM visa_cards WHERE id=?', [$id]);
            return;
        }
        $tables = ['manual_expenses', 'permit_stages', 'movement_items', 'debt_payments'];
        // v45: نثريات المناديب مصدر خامس — لازم تتفكّ هي كمان وإلا بتفضل منسوبة
        // لكارت اتمسح، فتختفي من كشوف الكروت وتبقى يتيمة.
        if (RepMiscExpense::visaReady()) { $tables[] = 'rep_misc_expenses'; }
        foreach ($tables as $t) {
            Database::exec("UPDATE $t SET visa_card_id = NULL WHERE visa_card_id = ?", [$id]);
        }
        Database::exec("DELETE FROM visa_deposits WHERE card_id = ?", [$id]);
        Database::exec("DELETE FROM visa_cards WHERE id = ?", [$id]);
    }

    private static function bind(array $d): array
    {
        $bank = ($d['bank'] ?? '') !== '' ? $d['bank'] : null;
        if (BankAccount::ready() && !empty($d['bank_account_id'])) {
            $account = BankAccount::find((int) $d['bank_account_id']);
            $bank = $account['bank_name'] ?? $account['name'] ?? $bank;
        }
        return [
            ':name'            => trim((string) ($d['name'] ?? '')),
            ':owner'           => ($d['owner'] ?? '') !== '' ? $d['owner'] : null,
            ':bank'            => $bank,
            ':opening_balance' => (float) ($d['opening_balance'] ?? 0),
            ':status'          => ($d['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            ':note'            => ($d['note'] ?? '') !== '' ? $d['note'] : null,
        ];
    }

    private static function paymentCount(int $id): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM (' . self::paymentsUnion() . ') t WHERE t.card_id=?', [$id]
        );
    }

    /** أي أثر تاريخي يمنع حذف الكارت أو نقله حتى لا تتغيّر نسبة السجل. */
    private static function usageCount(int $id): int
    {
        $count = self::paymentCount($id);
        if (!BankAccount::ready()) { return $count; }
        $count += (int) Database::scalar('SELECT COUNT(*) FROM visa_deposits WHERE card_id=?', [$id]);
        $count += (int) Database::scalar('SELECT COUNT(*) FROM client_payments WHERE visa_card_id=?', [$id]);
        $count += (int) Database::scalar('SELECT COUNT(*) FROM company_safe_entries WHERE visa_card_id=?', [$id]);
        return $count;
    }

    // ─────────────────────────────────────────────── الشحنات (الإيداعات)

    public static function findDeposit(int $id): ?array
    {
        return Database::row("SELECT * FROM visa_deposits WHERE id = ?", [$id]);
    }

    public static function deposits(int $cardId): array
    {
        if (BankAccount::ready()) {
            $accountId = self::accountId($cardId, false);
            return $accountId ? BankAccount::entries($accountId) : [];
        }
        return Database::all(
            "SELECT * FROM visa_deposits WHERE card_id = ? ORDER BY (dep_date IS NULL), dep_date DESC, id DESC",
            [$cardId]);
    }

    public static function createDeposit(int $cardId, array $d): int
    {
        if (BankAccount::ready()) {
            $accountId = self::accountId($cardId, true);
            if (!$accountId) { throw new \RuntimeException('كارت الفيزا أو حسابه البنكي مش موجود أو متوقف.'); }
            return BankAccount::createEntry($accountId, $d + ['card_id' => $cardId]);
        }
        Database::exec(
            "INSERT INTO visa_deposits (card_id, dep_date, amount, note)
             VALUES (?,?,?,?)",
            [$cardId, $d['dep_date'] ?: null, (float) ($d['amount'] ?? 0),
             ($d['note'] ?? '') !== '' ? $d['note'] : null]);
        return Database::lastId();
    }

    public static function updateDeposit(int $id, array $d): void
    {
        if (BankAccount::ready()) {
            $old = BankAccount::findEntry($id);
            if (!$old) { throw new \RuntimeException('حركة الحساب مش موجودة.'); }
            $amount = round((float) ($d['amount'] ?? 0), 2);
            if (abs($amount) < 0.005) { throw new \RuntimeException('اكتب مبلغًا غير صفر.'); }
            $accountId = (int) $old['bank_account_id'];
            BankAccount::withAccountSpendDeltas(
                [$accountId => round((float) $old['amount'] - $amount, 2)],
                static function () use ($id, $d, $amount): void {
                    Database::exec(
                        'UPDATE visa_deposits SET dep_date=?,amount=?,note=? WHERE id=?',
                        [$d['dep_date'] ?: null, $amount,
                         ($d['note'] ?? '') !== '' ? $d['note'] : null, $id]
                    );
                }
            );
            return;
        }
        Database::exec(
            "UPDATE visa_deposits SET dep_date=?, amount=?, note=? WHERE id=?",
            [$d['dep_date'] ?: null, (float) ($d['amount'] ?? 0),
             ($d['note'] ?? '') !== '' ? $d['note'] : null, $id]);
    }

    public static function deleteDeposit(int $id): void
    {
        if (BankAccount::ready()) { BankAccount::deleteEntry($id); return; }
        Database::exec("DELETE FROM visa_deposits WHERE id = ?", [$id]);
    }

    // ─────────────────────────────────────────────── المدفوعات (اتحاد المصادر)

    /**
     * اتحاد كل عمليات الفيزا المنسوبة لكارت (is_visa=1 AND visa_card_id IS NOT NULL).
     * أعمدة موحّدة: source, card_id, ref_id, d, category, party, amount, note.
     */
    public static function paymentsUnion(): string
    {
        $stageCase = "CASE s.stage_key";
        foreach (array_keys(Permit::STAGES) as $k) {
            $stageCase .= " WHEN " . Database::conn()->quote($k)
                        . " THEN " . Database::conn()->quote(Permit::label($k));
        }
        $stageCase .= " ELSE COALESCE(NULLIF(s.note,''),'بند مخصّص') END";

        return "
            SELECT 'manual' AS source, me.visa_card_id AS card_id, me.id AS ref_id, me.exp_date AS d,
                   COALESCE(NULLIF(me.category,''),'مصروف يدوي') AS category,
                   COALESCE(NULLIF(me.beneficiary,''),'') AS party,
                   me.amount AS amount, me.note AS note, me.created_at AS ts
            FROM manual_expenses me
            WHERE me.is_visa = 1 AND me.visa_card_id IS NOT NULL

            UNION ALL

            SELECT 'permits', s.visa_card_id, p.id, s.entry_date,
                   $stageCase,
                   COALESCE(NULLIF(p.company,''), NULLIF(p.client,''), ''),
                   s.amount, COALESCE(s.note, s.ref_no), s.created_at
            FROM permit_stages s
            JOIN permits p ON p.id = s.permit_id
            WHERE s.is_visa = 1 AND s.visa_card_id IS NOT NULL AND s.amount <> 0

            UNION ALL

            SELECT 'bawalis', mi.visa_card_id, m.bill_id, m.movement_date,
                   COALESCE(NULLIF(mi.category,''),'مصروف'),
                   COALESCE(NULLIF(b.company,''), NULLIF(b.declaration_owner,''), ''),
                   mi.amount, COALESCE(mi.note, b.bill_number), m.created_at
            FROM movement_items mi
            JOIN movements m ON m.id = mi.movement_id
            LEFT JOIN bawalis b ON b.id = m.bill_id
            WHERE mi.item_type = 'expense' AND mi.is_visa = 1 AND mi.visa_card_id IS NOT NULL AND mi.amount <> 0

            UNION ALL

            SELECT 'debt', dp.visa_card_id, da.id, dp.pay_date,
                   CONCAT('سداد مديونية — ', da.name),
                   da.name, dp.amount, dp.note, dp.created_at
            FROM debt_payments dp
            JOIN debt_accounts da ON da.id = dp.account_id
            WHERE dp.is_visa = 1 AND dp.visa_card_id IS NOT NULL AND dp.amount <> 0
        " . self::repMiscLeg();
    }

    /**
     * الجزء الخامس (v45) — نثريات المناديب المدفوعة بكارت (`rep_misc_expenses`).
     *
     * ⚠️ بيرجع '' قبل migrate_v45 فالاتحاد يفضل رباعي زي ما كان بالظبط.
     *    من غير الجزء ده، النثرية المدفوعة بالفيزا كانت هتتستثنى من الخزنة
     *    (RepMiscExpense::cashOnlySql) **ومن غير ما تتخصم من أي كارت** — يعني
     *    فلوس تتبخّر من النظام. الاتنين لازم يمشوا مع بعض.
     */
    private static function repMiscLeg(): string
    {
        if (!RepMiscExpense::visaReady()) { return ''; }
        return "
            UNION ALL

            SELECT 'rep_misc', x.visa_card_id, x.rep_id, x.exp_date,
                   COALESCE(NULLIF(x.category,''),'" . RepMiscExpense::CATEGORY . "'),
                   COALESCE(NULLIF(r.name,''),''),
                   x.amount, COALESCE(x.note, x.ref_no), x.created_at
            FROM rep_misc_expenses x
            JOIN reps r ON r.id = x.rep_id
            WHERE x.is_visa = 1 AND x.visa_card_id IS NOT NULL AND x.amount <> 0
        ";
    }

    /** مدفوعات كارت واحد (مرتّبة بالأحدث). */
    public static function payments(int $cardId): array
    {
        return Database::all(
            "SELECT * FROM (" . self::paymentsUnion() . ") t WHERE t.card_id = ?
             ORDER BY (t.ts IS NULL), t.ts DESC, (t.d IS NULL), t.d DESC, t.source",
            [$cardId]);
    }

    /** إجمالي مدفوعات كل كارت [card_id => total]. */
    public static function paymentsByCard(): array
    {
        $out = [];
        foreach (Database::all(
            "SELECT t.card_id, COALESCE(SUM(t.amount),0) v
             FROM (" . self::paymentsUnion() . ") t GROUP BY t.card_id") as $r) {
            $out[(int) $r['card_id']] = round((float) $r['v'], 2);
        }
        return $out;
    }

    /** إجمالي الشحنات لكل كارت [card_id => total]. */
    public static function depositsByCard(): array
    {
        $out = [];
        foreach (Database::all(
            "SELECT card_id, COALESCE(SUM(amount),0) v FROM visa_deposits GROUP BY card_id") as $r) {
            $out[(int) $r['card_id']] = round((float) $r['v'], 2);
        }
        return $out;
    }

    // ─────────────────────────────────────────────── الأرصدة والعرض

    /**
     * كل الكروت مع رصيدها المحسوب (opening + deposits − payments) وتفكيكه.
     * كل صف = صف الكارت + مفاتيح: dep_total, pay_total, balance.
     */
    public static function withBalances(bool $activeOnly = false): array
    {
        $cards = self::all($activeOnly);
        if (BankAccount::ready()) {
            $accounts = [];
            foreach (BankAccount::withBalances() as $a) { $accounts[(int) $a['id']] = $a; }
            $cardPayments = self::paymentsByCard();
            foreach ($cards as &$c) {
                $a = $accounts[(int) $c['bank_account_id']] ?? [];
                $c['dep_total'] = (float) ($a['entry_total'] ?? 0);
                $c['pay_total'] = $cardPayments[(int) $c['id']] ?? 0.0;
                $c['balance'] = (float) ($a['balance'] ?? 0);
            }
            unset($c);
            return $cards;
        }
        $deps  = self::depositsByCard();
        $pays  = self::paymentsByCard();
        foreach ($cards as &$c) {
            $id  = (int) $c['id'];
            $dep = $deps[$id] ?? 0.0;
            $pay = $pays[$id] ?? 0.0;
            $c['dep_total'] = $dep;
            $c['pay_total'] = $pay;
            $c['balance']   = round((float) $c['opening_balance'] + $dep - $pay, 2);
        }
        unset($c);
        return $cards;
    }

    /** رصيد كارت واحد. */
    public static function balanceOf(int $cardId): float
    {
        $c = self::find($cardId);
        if (!$c) { return 0.0; }
        if (BankAccount::ready()) { return BankAccount::balanceOf((int) $c['bank_account_id']); }
        $dep = (float) Database::scalar("SELECT COALESCE(SUM(amount),0) FROM visa_deposits WHERE card_id = ?", [$cardId]);
        $pay = self::paymentsByCard()[$cardId] ?? 0.0;
        return round((float) $c['opening_balance'] + $dep - $pay, 2);
    }

    /** إجماليات لوحة الوحدة: عدد الكروت، إجمالي الأرصدة، الشحنات، المدفوعات. */
    public static function summary(): array
    {
        if (BankAccount::ready()) {
            $s = BankAccount::summary();
            return ['count' => $s['cards'], 'balance' => $s['balance'], 'deposits' => $s['entries'],
                    'payments' => $s['payments'], 'opening' => 0.0];
        }
        $cards = self::withBalances();
        $bal = 0.0; $dep = 0.0; $pay = 0.0; $open = 0.0;
        foreach ($cards as $c) {
            $bal  += (float) $c['balance'];
            $dep  += (float) $c['dep_total'];
            $pay  += (float) $c['pay_total'];
            $open += (float) $c['opening_balance'];
        }
        return [
            'count'    => count($cards),
            'balance'  => round($bal, 2),
            'deposits' => round($dep, 2),
            'payments' => round($pay, 2),
            'opening'  => round($open, 2),
        ];
    }

    /** تجميع الأرصدة حسب صاحب الكارت (الفيزا ملك لأكثر من شخص). */
    public static function byOwner(): array
    {
        $out = [];
        foreach (self::withBalances() as $c) {
            $key = ($c['owner'] ?? '') !== '' ? $c['owner'] : '(بدون صاحب)';
            if (!isset($out[$key])) { $out[$key] = ['owner' => $key, 'cards' => 0, 'accounts' => 0]; }
            $out[$key]['cards']++;
            $accountKey = BankAccount::ready() ? (int) $c['bank_account_id'] : (int) $c['id'];
            $out[$key]['_accounts'][$accountKey] = true;
        }
        foreach ($out as &$row) {
            // الرصيد لا يُقسَّم على أصحاب الكروت؛ الحساب قد يحمل كروتًا لأكثر
            // من شخص، وعرض الرصيد لكل واحد كان سيكرر نفس المال.
            $row['accounts'] = count($row['_accounts'] ?? []);
            unset($row['_accounts']);
        }
        unset($row);
        return array_values($out);
    }

    /**
     * سجل كارت موحّد: الرصيد الافتتاحي + الشحنات (+) + المدفوعات (−) مع رصيد جارٍ.
     * مرتّب من الأحدث للأقدم للعرض.
     */
    public static function ledger(int $cardId): array
    {
        $card = self::find($cardId);
        if (!$card) { return []; }

        $rows = [];
        // الرصيد الافتتاحي كأول حركة (بلا تاريخ → يظل في القاع عند الترتيب التصاعدي).
        // أُلغِيَ لصالح الشحنات المؤرَّخة — لا يُعرض إلا لو له قيمة (كروت قديمة).
        if (round((float) $card['opening_balance'], 2) != 0.0) {
            $rows[] = [
                'kind' => 'opening', 'd' => null, 'ts' => null, 'label' => 'رصيد افتتاحي',
                'party' => '—', 'source' => null, 'amount' => round((float) $card['opening_balance'], 2), 'url' => null,
            ];
        }
        foreach (self::deposits($cardId) as $dp) {
            $rows[] = [
                'kind' => 'deposit', 'd' => $dp['dep_date'], 'ts' => $dp['created_at'] ?? null, 'label' => 'شحن رصيد',
                'party' => $dp['note'] ?: '—', 'source' => null, 'id' => (int) $dp['id'],
                'amount' => round((float) $dp['amount'], 2), 'url' => null,
            ];
        }
        foreach (self::payments($cardId) as $p) {
            $rows[] = [
                'kind'   => 'payment',
                'd'      => $p['d'],
                'ts'     => $p['ts'] ?? null,
                'label'  => $p['category'],
                'party'  => $p['party'] ?: '—',
                'source' => $p['source'],
                'note'   => $p['note'],
                'amount' => -round((float) $p['amount'], 2),
                'url'    => \App\Models\Expense::originUrl($p['source'], $p['ref_id']),
            ];
        }

        // مفتاح الترتيب = الوقت الدقيق إن وُجد، وإلا التاريخ (منتصف الليل). opening بلا مفتاح → أول.
        $key = function (array $r): string {
            if (!empty($r['ts']))  { return (string) $r['ts']; }
            if (!empty($r['d']))   { return $r['d'] . ' 00:00:00'; }
            return '';
        };
        // ترتيب تصاعدي لحساب الرصيد الجارٍ (opening أولاً لأنه بلا مفتاح)
        usort($rows, function ($a, $b) use ($key) {
            $ka = $key($a); $kb = $key($b);
            if ($ka === '' && $kb !== '') return -1;
            if ($kb === '' && $ka !== '') return 1;
            return $ka <=> $kb;
        });
        $run = 0.0;
        foreach ($rows as &$r) { $run = round($run + $r['amount'], 2); $r['running'] = $run; }
        unset($r);

        return array_reverse($rows);
    }

    /** آخر شحنة رصيد على الكارت (الأحدث) أو null. */
    public static function lastDeposit(int $cardId): ?array
    {
        if (BankAccount::ready()) {
            $accountId = self::accountId($cardId, false);
            return $accountId > 0 ? Database::row(
                "SELECT * FROM visa_deposits WHERE bank_account_id=? AND amount>0
                 ORDER BY (dep_date IS NULL),dep_date DESC,id DESC LIMIT 1", [$accountId]
            ) : null;
        }
        return Database::row(
            "SELECT * FROM visa_deposits WHERE card_id = ?
             ORDER BY (dep_date IS NULL), dep_date DESC, id DESC LIMIT 1", [$cardId]);
    }

    /**
     * أداء الكارت: تفكيك المدفوعات حسب المصدر + الإجماليات (عدد/مجموع/أكبر عملية/آخر نشاط).
     * يُعيد ['by_source'=>[key=>['label','count','total']], 'count','total','max','last','deposits_count'].
     */
    public static function stats(int $cardId): array
    {
        $rows = Database::all(
            "SELECT t.source, COUNT(*) cnt, COALESCE(SUM(t.amount),0) total,
                    COALESCE(MAX(t.amount),0) mx, MAX(COALESCE(t.ts, t.d)) last_ts
             FROM (" . self::paymentsUnion() . ") t
             WHERE t.card_id = ? GROUP BY t.source",
            [$cardId]);

        $bySource = [];
        foreach (self::SOURCES as $k => $label) {
            $bySource[$k] = ['label' => $label, 'count' => 0, 'total' => 0.0];
        }
        $count = 0; $total = 0.0; $max = 0.0; $last = null;
        foreach ($rows as $r) {
            $src = $r['source'];
            if (!isset($bySource[$src])) { $bySource[$src] = ['label' => $src, 'count' => 0, 'total' => 0.0]; }
            $bySource[$src]['count'] = (int) $r['cnt'];
            $bySource[$src]['total'] = round((float) $r['total'], 2);
            $count += (int) $r['cnt'];
            $total += (float) $r['total'];
            if ((float) $r['mx'] > $max) { $max = (float) $r['mx']; }
            if ($r['last_ts'] && (!$last || $r['last_ts'] > $last)) { $last = $r['last_ts']; }
        }
        $depCount = BankAccount::ready()
            ? (int) Database::scalar('SELECT COUNT(*) FROM visa_deposits WHERE bank_account_id=?', [self::accountId($cardId, false)])
            : (int) Database::scalar("SELECT COUNT(*) FROM visa_deposits WHERE card_id = ?", [$cardId]);

        return [
            'by_source'      => $bySource,
            'count'          => $count,
            'total'          => round($total, 2),
            'max'            => round($max, 2),
            'last'           => $last,
            'deposits_count' => $depCount,
        ];
    }
}
