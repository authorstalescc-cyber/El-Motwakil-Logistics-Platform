<?php
namespace App\Models;

use App\Core\Database;

/**
 * رصيد المكتب — كروت الفيزا.
 *
 * القاعدة:
 *   • كل كارت له رصيد افتتاحي + شحنات يدوية (visa_deposits) تزيده.
 *   • كل عملية مدفوعة بالفيزا (is_visa=1) في النظام كله تُنسَب لكارت (visa_card_id)
 *     فتُخصم من رصيده. مصادر المدفوعات الأربعة:
 *       manual_expenses / permit_stages / movement_items / debt_payments
 *   • الرصيد = الرصيد الافتتاحي + Σ(الشحنات) − Σ(المدفوعات المنسوبة للكارت).
 *
 * ملاحظة: visa_card_id وسم للنسبة فقط — لا يمسّ أي حساب قائم ولا التقفيل اليومي.
 */
class VisaCard
{
    /** تسميات مصادر مدفوعات الفيزا (للشارات والتصفية) */
    public const SOURCES = [
        'manual'  => 'مصروف يدوي',
        'permits' => 'الإصدارات',
        'bawalis' => 'البوالص',
        'debt'    => 'سداد مديونية',
    ];

    // ─────────────────────────────────────────────── الكروت (CRUD)

    /** كل الكروت (مع خيار الحالة). */
    public static function all(bool $activeOnly = false): array
    {
        $where = $activeOnly ? "WHERE status = 'active'" : '';
        return Database::all("SELECT * FROM visa_cards $where ORDER BY status='inactive', name");
    }

    public static function find(int $id): ?array
    {
        return Database::row("SELECT * FROM visa_cards WHERE id = ?", [$id]);
    }

    /** خريطة [id => name] للعرض السريع في الجداول والسجلات. */
    public static function nameMap(): array
    {
        $out = [];
        foreach (Database::all("SELECT id, name, owner FROM visa_cards") as $c) {
            $out[(int) $c['id']] = $c['name'] . ($c['owner'] ? ' — ' . $c['owner'] : '');
        }
        return $out;
    }

    public static function create(array $d): int
    {
        Database::exec(
            "INSERT INTO visa_cards (name, owner, bank, opening_balance, status, note)
             VALUES (:name, :owner, :bank, :opening_balance, :status, :note)", self::bind($d));
        return Database::lastId();
    }

    public static function update(int $id, array $d): void
    {
        $p = self::bind($d);
        $p[':id'] = $id;
        Database::exec(
            "UPDATE visa_cards SET name=:name, owner=:owner, bank=:bank,
                    opening_balance=:opening_balance, status=:status, note=:note WHERE id=:id", $p);
    }

    /** حذف الكارت + فكّ نسبة عملياته (visa_card_id → NULL) حتى لا تختفي من السجلات. */
    public static function delete(int $id): void
    {
        foreach (['manual_expenses', 'permit_stages', 'movement_items', 'debt_payments'] as $t) {
            Database::exec("UPDATE $t SET visa_card_id = NULL WHERE visa_card_id = ?", [$id]);
        }
        Database::exec("DELETE FROM visa_deposits WHERE card_id = ?", [$id]);
        Database::exec("DELETE FROM visa_cards WHERE id = ?", [$id]);
    }

    private static function bind(array $d): array
    {
        return [
            ':name'            => trim((string) ($d['name'] ?? '')),
            ':owner'           => ($d['owner'] ?? '') !== '' ? $d['owner'] : null,
            ':bank'            => ($d['bank'] ?? '') !== '' ? $d['bank'] : null,
            ':opening_balance' => (float) ($d['opening_balance'] ?? 0),
            ':status'          => ($d['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            ':note'            => ($d['note'] ?? '') !== '' ? $d['note'] : null,
        ];
    }

    // ─────────────────────────────────────────────── الشحنات (الإيداعات)

    public static function findDeposit(int $id): ?array
    {
        return Database::row("SELECT * FROM visa_deposits WHERE id = ?", [$id]);
    }

    public static function deposits(int $cardId): array
    {
        return Database::all(
            "SELECT * FROM visa_deposits WHERE card_id = ? ORDER BY (dep_date IS NULL), dep_date DESC, id DESC",
            [$cardId]);
    }

    public static function createDeposit(int $cardId, array $d): int
    {
        Database::exec(
            "INSERT INTO visa_deposits (card_id, dep_date, amount, note)
             VALUES (?,?,?,?)",
            [$cardId, $d['dep_date'] ?: null, (float) ($d['amount'] ?? 0),
             ($d['note'] ?? '') !== '' ? $d['note'] : null]);
        return Database::lastId();
    }

    public static function updateDeposit(int $id, array $d): void
    {
        Database::exec(
            "UPDATE visa_deposits SET dep_date=?, amount=?, note=? WHERE id=?",
            [$d['dep_date'] ?: null, (float) ($d['amount'] ?? 0),
             ($d['note'] ?? '') !== '' ? $d['note'] : null, $id]);
    }

    public static function deleteDeposit(int $id): void
    {
        Database::exec("DELETE FROM visa_deposits WHERE id = ?", [$id]);
    }

    // ─────────────────────────────────────────────── المدفوعات (اتحاد المصادر)

    /**
     * اتحاد كل عمليات الفيزا المنسوبة لكارت (is_visa=1 AND visa_card_id IS NOT NULL).
     * أعمدة موحّدة: source, card_id, ref_id, d, category, party, amount, note.
     */
    private static function paymentsUnion(): string
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
        $dep = (float) Database::scalar("SELECT COALESCE(SUM(amount),0) FROM visa_deposits WHERE card_id = ?", [$cardId]);
        $pay = self::paymentsByCard()[$cardId] ?? 0.0;
        return round((float) $c['opening_balance'] + $dep - $pay, 2);
    }

    /** إجماليات لوحة الوحدة: عدد الكروت، إجمالي الأرصدة، الشحنات، المدفوعات. */
    public static function summary(): array
    {
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
            if (!isset($out[$key])) { $out[$key] = ['owner' => $key, 'cards' => 0, 'balance' => 0.0]; }
            $out[$key]['cards']++;
            $out[$key]['balance'] = round($out[$key]['balance'] + (float) $c['balance'], 2);
        }
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
        $depCount = (int) Database::scalar("SELECT COUNT(*) FROM visa_deposits WHERE card_id = ?", [$cardId]);

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
