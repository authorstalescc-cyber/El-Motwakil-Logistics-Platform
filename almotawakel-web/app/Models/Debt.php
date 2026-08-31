<?php
namespace App\Models;

use App\Core\Database;
use App\Core\App;

/**
 * وحدة المديونيات — الجهات التي تدين لها الشركة (دائنون).
 *
 * اتجاه المال (كباقي النظام) معكوس: «المديونية» = التزام على الشركة،
 * و«الدفعات» = مبالغ صادرة من الشركة للدائن. المتبقّي = المديونية − الدفعات.
 *
 * أنواع المديونيات:
 *   • أصحاب بيان البوالص  — مشتقّة حيًّا من bawalis (تُدار في وحدة سداد البوالص).
 *   • الكشف والضمان        — حساب خاص «ياسر أبو عمر / كونتراكت» (kind='inspection')،
 *                            مديونيته تُحتسب تلقائياً = Σ (حاويات كل إذن × قيمة الكشف والضمان).
 *   • مديونيات أخرى        — حسابات يدوية (kind='manual'، أصلها opening).
 *
 * كل حساب (عدا أصحاب البيان) في جدول debt_accounts، ودفعاته في debt_payments.
 */
class Debt
{
    /** مفتاح حساب الكشف الثابت (المستحق يُختار من الإعدادات) */
    public const INSPECTION_KEY = 'inspection';
    /** مفتاح حساب الضمان الثابت (المستحق: ياسر أبو عمر افتراضياً) */
    public const GUARANTEE_KEY  = 'guarantee';
    /** الاسم الافتراضي لمستحق الضمان */
    public const GUARANTEE_DEFAULT_NAME = 'ياسر أبو عمر / كونتراكت';

    // ==================================================================== الكشف والضمان

    /** أقصى عدد حاويات في الكشف/الضمان الواحد (من الإعدادات) — الزيادة = كشف وضمان جديد */
    public static function inspectionBatch(): int
    {
        $b = (int) App::setting('inspection_batch', '10');
        return $b > 0 ? $b : 10;
    }

    /** عدد المستندات (كشوفات = ضمانات) لعدد حاويات = تقريب لأعلى (حاويات ÷ الحد الأقصى) */
    public static function unitsFor(int $containers): int
    {
        return $containers > 0 ? (int) ceil($containers / self::inspectionBatch()) : 0;
    }

    /**
     * تفصيل مديونية الكشف والضمان لكل إذن.
     * ★ السعر لكل إذن على حِدة (permits.inspection_rate / guarantee_rate) — لا سعر عام.
     *   الأذونات التي لم يُدخَل لها سعر بعد = صفر (NULL → 0).
     * لكل إذن:  الكشف  = حاويات × سعر الكشف الخاص به،  الضمان = حاويات × سعر الضمان الخاص به.
     *           عدد الكشوفات (= الضمانات) = تقريب لأعلى (حاويات ÷ الحد الأقصى).
     *           الإجمالي = الكشف + الضمان.
     * يُعيد المفاتيح: rows(+units/inspection/guarantee/amount/inspection_rate/guarantee_rate),
     *                 batch, inspection_total, guarantee_total, total, containers, units.
     */
    public static function inspectionCharges(): array
    {
        $batch = self::inspectionBatch();
        $rows = Database::all(
            "SELECT id, permit_type, permit_no, permit_date, company, client,
                    COALESCE(containers,0) AS containers, inspection_rate, guarantee_rate, status
             FROM permits
             ORDER BY (containers > 0) DESC, permit_date DESC, id DESC"
        );
        $tInsp = 0.0; $tGuar = 0.0; $containers = 0; $units = 0;
        foreach ($rows as &$r) {
            $n = (int) $r['containers'];
            $insp = $r['inspection_rate'] !== null ? (float) $r['inspection_rate'] : 0.0;  // سعر هذا الإذن
            $guar = $r['guarantee_rate']  !== null ? (float) $r['guarantee_rate']  : 0.0;
            $r['containers']      = $n;
            $r['inspection_rate'] = $insp;
            $r['guarantee_rate']  = $guar;
            $r['units']      = $n > 0 ? (int) ceil($n / $batch) : 0;   // عدد الكشوفات = عدد الضمانات
            $r['inspection'] = round($n * $insp, 2);                   // مبلغ الكشف
            $r['guarantee']  = round($n * $guar, 2);                   // مبلغ الضمان
            $r['amount']     = round($r['inspection'] + $r['guarantee'], 2);
            $tInsp     += $r['inspection'];
            $tGuar     += $r['guarantee'];
            $containers += $n;
            $units      += $r['units'];
        }
        unset($r);
        return [
            'batch'            => $batch,
            'rows'             => $rows,
            'inspection_total' => round($tInsp, 2),
            'guarantee_total'  => round($tGuar, 2),
            'total'            => round($tInsp + $tGuar, 2),
            'containers'       => $containers,
            'units'            => $units,
        ];
    }

    // ==================================================================== حسابات المديونيات

    /** يضمن وجود حساب خاص (كشف/ضمان) بمفتاحه ويُعيد معرّفه */
    private static function ensureSpecial(string $key, string $kind, string $name, string $note): int
    {
        $id = (int) Database::scalar(
            "SELECT id FROM debt_accounts WHERE account_key = ?", [$key]);
        if ($id) { return $id; }
        Database::exec(
            "INSERT INTO debt_accounts (account_key, name, kind, opening, note)
             VALUES (?,?,?,0,?)",
            [$key, $name, $kind, $note]
        );
        return Database::lastId();
    }

    /** حساب الكشف — المستحق يُختار من الإعدادات (يبدأ فارغاً = غير محدّد) */
    public static function ensureInspectionAccount(): int
    {
        return self::ensureSpecial(self::INSPECTION_KEY, 'inspection', '',
            'مديونية الكشف — تُحتسب تلقائياً من حاويات الأذونات');
    }

    /** حساب الضمان — المستحق ياسر أبو عمر افتراضياً */
    public static function ensureGuaranteeAccount(): int
    {
        return self::ensureSpecial(self::GUARANTEE_KEY, 'guarantee', self::GUARANTEE_DEFAULT_NAME,
            'مديونية الضمان — تُحتسب تلقائياً من حاويات الأذونات');
    }

    public static function account(int $id): ?array
    {
        return Database::row("SELECT * FROM debt_accounts WHERE id = ?", [$id]);
    }

    public static function inspectionAccount(): array
    {
        return self::account(self::ensureInspectionAccount());
    }

    public static function guaranteeAccount(): array
    {
        return self::account(self::ensureGuaranteeAccount());
    }

    /** تحديد اسم مستحق الكشف (يُسمح بالفراغ = غير محدّد) */
    public static function setInspectionCreditor(string $name): void
    {
        $id = self::ensureInspectionAccount();
        Database::exec("UPDATE debt_accounts SET name = ? WHERE id = ?", [trim($name), $id]);
    }

    /** تحديد اسم مستحق الضمان (مثل «ياسر أبو عمر») */
    public static function setGuaranteeCreditor(string $name): void
    {
        $name = trim($name);
        if ($name === '') { return; }
        $id = self::ensureGuaranteeAccount();
        Database::exec("UPDATE debt_accounts SET name = ? WHERE id = ?", [$name, $id]);
    }

    /** الحسابات اليدوية (المديونيات الأخرى) فقط */
    public static function manualAccounts(): array
    {
        return Database::all("SELECT * FROM debt_accounts WHERE kind = 'manual' ORDER BY name, id");
    }

    public static function createManualAccount(string $name, float $opening, ?string $note): int
    {
        Database::exec(
            "INSERT INTO debt_accounts (account_key, name, kind, opening, note)
             VALUES (NULL, ?, 'manual', ?, ?)",
            [$name, $opening, $note !== '' ? $note : null]
        );
        return Database::lastId();
    }

    /** لا يُحذف حساب الكشف والضمان (خاص) */
    public static function deleteManualAccount(int $id): void
    {
        Database::exec("DELETE FROM debt_accounts WHERE id = ? AND kind = 'manual'", [$id]);
    }

    // ==================================================================== الدفعات

    public static function paidOf(int $accountId): float
    {
        return (float) Database::scalar(
            "SELECT COALESCE(SUM(amount),0) FROM debt_payments WHERE account_id = ?", [$accountId]);
    }

    public static function payments(int $accountId): array
    {
        return Database::all(
            "SELECT * FROM debt_payments WHERE account_id = ? ORDER BY pay_date, id", [$accountId]);
    }

    public static function addPayment(int $accountId, float $amount, ?string $date, ?string $note, bool $isVisa = false, ?int $cardId = null): void
    {
        if ($amount == 0) { return; }
        $visaCardId = ($isVisa && $cardId) ? $cardId : null;
        BankAccount::withCardSpendChanges([[
            'new_card' => $visaCardId, 'new_amount' => $isVisa ? $amount : 0,
        ]], static function () use ($accountId, $amount, $date, $note, $isVisa, $visaCardId): void {
            Database::exec(
                "INSERT INTO debt_payments (account_id, pay_date, amount, note, is_visa, visa_card_id)
                 VALUES (?,?,?,?,?,?)",
                [$accountId, $date ?: work_date(), $amount, $note !== '' ? $note : null,
                 $isVisa ? 1 : 0, $visaCardId]
            );
        });
    }

    public static function deletePayment(int $paymentId): void
    {
        $old = Database::row('SELECT * FROM debt_payments WHERE id=?', [$paymentId]);
        if (!$old) { return; }
        BankAccount::withCardSpendChanges([[
            'old_card' => !empty($old['is_visa']) ? $old['visa_card_id'] : null,
            'old_amount' => !empty($old['is_visa']) ? $old['amount'] : 0,
        ]], static function () use ($paymentId): void {
            Database::exec("DELETE FROM debt_payments WHERE id = ?", [$paymentId]);
        });
    }

    /**
     * أصل المديونية لحساب:
     *   • inspection → إجمالي الكشف المحتسب من الأذونات.
     *   • guarantee  → إجمالي الضمان المحتسب من الأذونات.
     *   • manual     → opening.
     */
    public static function chargesOf(array $account): float
    {
        $kind = $account['kind'] ?? '';
        if ($kind === 'inspection') { return self::inspectionCharges()['inspection_total']; }
        if ($kind === 'guarantee')  { return self::inspectionCharges()['guarantee_total']; }
        return (float) ($account['opening'] ?? 0);
    }

    /** إجماليات حساب: ['charges','paid','remaining'] */
    public static function accountTotals(array $account): array
    {
        $charges = self::chargesOf($account);
        $paid    = self::paidOf((int) $account['id']);
        return [
            'charges'   => round($charges, 2),
            'paid'      => round($paid, 2),
            'remaining' => round($charges - $paid, 2),
        ];
    }

    // ==================================================================== أصحاب بيان البوالص

    /** ملخّص مديونيات أصحاب البيان (نفس منطق وحدة سداد البوالص) */
    public static function ownersSummary(): array
    {
        $key = "COALESCE(NULLIF(TRIM(declaration_owner),''),'')";
        $row = Database::row(
            "SELECT COUNT(DISTINCT $key)                    AS owners,
                    COUNT(*)                                AS bills,
                    SUM(pay_status IN ('unpaid','partial')) AS due_bills,
                    COALESCE(SUM(debt),0)                   AS debt,
                    COALESCE(SUM(paid),0)                   AS paid,
                    COALESCE(SUM(remaining),0)              AS remaining
             FROM bawalis"
        ) ?: ['owners'=>0,'bills'=>0,'due_bills'=>0,'debt'=>0,'paid'=>0,'remaining'=>0];

        // عدد أصحاب البيان الذين لهم متبقٍّ فعلي
        $row['due_owners'] = (int) Database::scalar(
            "SELECT COUNT(*) FROM (
                SELECT $key AS k FROM bawalis GROUP BY k HAVING SUM(remaining) > 0.005
             ) t"
        );
        return $row;
    }

    /** أعلى أصحاب البيان مديونيةً (متبقٍّ) — لعرض مختصر */
    public static function topOwners(int $limit = 8): array
    {
        $limit = max(1, $limit);
        $key = "COALESCE(NULLIF(TRIM(declaration_owner),''),'')";
        return Database::all(
            "SELECT $key AS owner_key,
                    COUNT(*)                   AS bills,
                    COALESCE(SUM(debt),0)      AS debt,
                    COALESCE(SUM(paid),0)      AS paid,
                    COALESCE(SUM(remaining),0) AS remaining,
                    MAX(bill_date)             AS last_date
             FROM bawalis
             GROUP BY owner_key
             HAVING SUM(remaining) > 0.005
             ORDER BY SUM(remaining) DESC
             LIMIT $limit"
        );
    }

    // ==================================================================== تتبع الفيزا

    /** تسميات مصادر عمليات الفيزا */
    public const VISA_SOURCES = [
        'manual'  => 'مصروف يدوي',
        'permits' => 'الإصدارات',
        'bawalis' => 'البوالص',
        'debt'    => 'سداد مديونية',
    ];

    /** استعلام اتحاد كل العمليات المدفوعة بالفيزا (is_visa=1) من كل المصادر */
    private static function visaUnion(): string
    {
        // CASE لتحويل مفتاح مرحلة الإصدار إلى تسمية عربية
        $stageCase = "CASE s.stage_key";
        foreach (array_keys(Permit::STAGES) as $k) {
            $stageCase .= " WHEN " . Database::conn()->quote($k)
                        . " THEN " . Database::conn()->quote(Permit::label($k));
        }
        $stageCase .= " ELSE COALESCE(NULLIF(s.note,''),'بند مخصّص') END";

        return "
            SELECT 'manual' AS source, me.id AS ref_id, me.exp_date AS d,
                   COALESCE(NULLIF(me.category,''),'مصروف يدوي') AS category,
                   COALESCE(NULLIF(me.beneficiary,''),'') AS party,
                   me.amount AS amount, me.note AS note,
                   (SELECT vc.name FROM visa_cards vc WHERE vc.id = me.visa_card_id) AS card
            FROM manual_expenses me
            WHERE me.is_visa = 1

            UNION ALL

            SELECT 'permits', p.id, s.entry_date,
                   $stageCase,
                   COALESCE(NULLIF(p.company,''), NULLIF(p.client,''), ''),
                   s.amount, COALESCE(s.note, s.ref_no),
                   (SELECT vc.name FROM visa_cards vc WHERE vc.id = s.visa_card_id)
            FROM permit_stages s
            JOIN permits p ON p.id = s.permit_id
            WHERE s.is_visa = 1 AND s.amount <> 0

            UNION ALL

            SELECT 'bawalis', m.bill_id, m.movement_date,
                   COALESCE(NULLIF(mi.category,''),'مصروف'),
                   COALESCE(NULLIF(b.company,''), NULLIF(b.declaration_owner,''), ''),
                   mi.amount, COALESCE(mi.note, b.bill_number),
                   (SELECT vc.name FROM visa_cards vc WHERE vc.id = mi.visa_card_id)
            FROM movement_items mi
            JOIN movements m ON m.id = mi.movement_id
            LEFT JOIN bawalis b ON b.id = m.bill_id
            WHERE mi.item_type = 'expense' AND mi.is_visa = 1 AND mi.amount <> 0

            UNION ALL

            SELECT 'debt', da.id, dp.pay_date,
                   CONCAT('سداد مديونية — ', da.name),
                   da.name, dp.amount, dp.note,
                   (SELECT vc.name FROM visa_cards vc WHERE vc.id = dp.visa_card_id)
            FROM debt_payments dp
            JOIN debt_accounts da ON da.id = dp.account_id
            WHERE dp.is_visa = 1 AND dp.amount <> 0
        ";
    }

    /** شرط WHERE الخارجي على جدول الفيزا الموحّد */
    private static function visaWhere(array $f): array
    {
        $conds = []; $params = [];
        if (!empty($f['source']) && isset(self::VISA_SOURCES[$f['source']])) {
            $conds[] = "t.source = ?"; $params[] = $f['source'];
        }
        if (!empty($f['from'])) { $conds[] = "t.d >= ?"; $params[] = $f['from']; }
        if (!empty($f['to']))   { $conds[] = "t.d <= ?"; $params[] = $f['to']; }
        if (!empty($f['q'])) {
            $conds[] = "(t.category LIKE ? OR t.party LIKE ? OR t.note LIKE ?)";
            $like = '%' . $f['q'] . '%';
            array_push($params, $like, $like, $like);
        }
        $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
        return [$where, $params];
    }

    /** صفوف عمليات الفيزا (مرتّبة بالأحدث) */
    public static function visaLedger(array $f, int $limit = 500): array
    {
        [$where, $params] = self::visaWhere($f);
        $limit = max(1, $limit);
        return Database::all(
            "SELECT * FROM (" . self::visaUnion() . ") t $where
             ORDER BY (t.d IS NULL), t.d DESC, t.source
             LIMIT $limit",
            $params
        );
    }

    /** إجماليات الفيزا: المجموع الكلّي + لكل مصدر + العدد */
    public static function visaTotals(array $f = []): array
    {
        [$where, $params] = self::visaWhere($f);
        $rows = Database::all(
            "SELECT t.source, COALESCE(SUM(t.amount),0) AS value, COUNT(*) AS cnt
             FROM (" . self::visaUnion() . ") t $where GROUP BY t.source",
            $params
        );
        $bySource = [];
        foreach (self::VISA_SOURCES as $k => $label) {
            $bySource[$k] = ['label' => $label, 'value' => 0.0, 'count' => 0];
        }
        $grand = 0.0; $count = 0;
        foreach ($rows as $r) {
            $src = $r['source'];
            if (!isset($bySource[$src])) { $bySource[$src] = ['label'=>$src,'value'=>0.0,'count'=>0]; }
            $bySource[$src]['value'] = (float) $r['value'];
            $bySource[$src]['count'] = (int) $r['cnt'];
            $grand += (float) $r['value'];
            $count += (int) $r['cnt'];
        }
        return ['grand' => round($grand, 2), 'count' => $count, 'by_source' => $bySource];
    }

    // ==================================================================== نظرة عامة

    /** إجماليات كل أنواع المديونيات لبطاقات النظرة العامة */
    public static function overview(): array
    {
        $owners = self::ownersSummary();
        $insp   = self::accountTotals(self::inspectionAccount());  // الكشف
        $guar   = self::accountTotals(self::guaranteeAccount());   // الضمان
        // الكشف والضمان مجمّعين (لبطاقة النظرة العامة)
        $insGuar = [
            'charges'   => round($insp['charges'] + $guar['charges'], 2),
            'paid'      => round($insp['paid'] + $guar['paid'], 2),
            'remaining' => round($insp['remaining'] + $guar['remaining'], 2),
        ];

        $manualCharges = 0.0; $manualPaid = 0.0;
        foreach (self::manualAccounts() as $a) {
            $manualCharges += (float) $a['opening'];
            $manualPaid    += self::paidOf((int) $a['id']);
        }
        $manualRemaining = round($manualCharges - $manualPaid, 2);

        $grandRemaining = round((float)$owners['remaining'] + $insGuar['remaining'] + $manualRemaining, 2);

        return [
            'owners'          => $owners,
            'inspection'      => $insGuar,   // الكشف + الضمان مجمّعين
            'manual_charges'  => round($manualCharges, 2),
            'manual_paid'     => round($manualPaid, 2),
            'manual_remaining'=> $manualRemaining,
            'grand_remaining' => $grandRemaining,
            'visa'            => self::visaTotals(),
        ];
    }
}
