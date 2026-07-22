<?php
namespace App\Models;

use App\Core\Database;

/**
 * وحدة المصروفات — سجل موحّد لكل مصروفات النظام في مكان واحد.
 *
 * تسحب البنود من مصادرها الأصلية وقت العرض (بدون تخزين مكرَّر):
 *   • manual   — المصاريف اليدوية (جدول manual_expenses)  [قابلة للتعديل/الحذف]
 *   • permits  — بنود مصروفات مراحل الإصدارات (permit_stages)
 *   • bawalis  — بنود مصروفات حركات البوالص (movement_items)
 *   • takhlis  — مصروفات شهادات التخليص (takhlis.expenses)
 *   • shamil   — مصروفات الفواتير (shamil.expense)
 *   • rep_misc — نثريات المناديب (rep_misc_expenses) — مصروف عام بلا إذن (v39)
 *
 * كل صف مُوحَّد الأعمدة: source | ref_id | d | category | party | amount | note
 * المصادر عدا manual للعرض فقط (تُعدَّل من وحداتها الأصلية).
 */
class Expense
{
    /** تسميات المصادر (للشارات والتصفية) */
    public const SOURCES = [
        'manual'  => 'يدوي',
        'permits' => 'الإصدارات',
        'bawalis' => 'البوالص',
        'takhlis' => 'التخليص',
        'shamil'  => 'الفواتير',
        'reps'    => 'المناديب',
        'rep_misc'=> 'نثريات المناديب',
        'debt'    => 'سداد مديونيات',
    ];

    /** رابط البند الأصلي (للمصادر غير اليدوية) */
    public static function originUrl(string $source, $refId): ?string
    {
        $id = (int) $refId;
        return match ($source) {
            'permits' => $id ? url('permits/' . $id) : null,
            'bawalis' => $id ? url('bawalis/' . $id) : url('movements'),
            'takhlis' => $id ? url('takhlis/' . $id . '/edit') : null,
            // ⚠️ `shamil` **أرشيف** (قرار المالك 2026-07-22): مفيش مسار `/shamil`
            //    في الراوتر خالص، فالرابط القديم كان بيوصّل **404**. البيانات
            //    نفسها لسه بتتقرا في المجاميع والتقارير زي ما هي.
            'shamil'  => null,
            'reps'    => $id ? url('reps/' . $id) : url('reps'),
            // نثرية المندوب مالهاش إذن، فالرابط بيوّدي لكارت عهدته (v39)
            'rep_misc'=> $id ? url('rep-custody/' . $id) : url('rep-custody'),
            'debt'    => $id ? url('debts/account/' . $id) : url('debts'),
            default   => null,
        };
    }

    /**
     * يبني استعلام الاتحاد (UNION ALL) لكل مصادر المصروفات كجدول فرعي `t`.
     * يُعيد [sql, params].
     */
    private static function unionSql(): array
    {
        // CASE لتحويل مفتاح مرحلة الإصدار إلى تسمية عربية
        $stageCase = "CASE s.stage_key";
        foreach (Permit::expenseKeys() as $k) {
            $stageCase .= " WHEN " . Database::conn()->quote($k)
                        . " THEN " . Database::conn()->quote(Permit::label($k));
        }
        $stageCase .= " ELSE COALESCE(NULLIF(s.note,''),'بند مخصّص') END";

        $expSql = Permit::expenseSql('s');

        // نثريات المناديب (v39) — '' قبل تشغيل migrate_v39 فتتخطّى بأمان
        $miscSql = RepMiscExpense::expenseUnionSql();

        $sql = "
            SELECT 'manual' AS source, me.id AS ref_id, me.exp_date AS d,
                   COALESCE(NULLIF(me.category,''),'مصروف يدوي') AS category,
                   COALESCE(NULLIF(me.beneficiary,''),'') AS party,
                   me.amount AS amount, me.note AS note, me.is_visa AS is_visa
            FROM manual_expenses me

            UNION ALL

            SELECT 'permits', p.id, s.entry_date,
                   $stageCase,
                   COALESCE(NULLIF(p.company,''), NULLIF(p.client,''), '') ,
                   s.amount, COALESCE(s.note, s.ref_no), s.is_visa
            FROM permit_stages s
            JOIN permits p ON p.id = s.permit_id
            WHERE $expSql AND s.amount <> 0

            UNION ALL

            SELECT 'bawalis', m.bill_id, m.movement_date,
                   COALESCE(NULLIF(mi.category,''),'مصروف'),
                   COALESCE(NULLIF(b.company,''), NULLIF(b.declaration_owner,''), ''),
                   mi.amount, COALESCE(mi.note, b.bill_number), mi.is_visa
            FROM movement_items mi
            JOIN movements m ON m.id = mi.movement_id
            LEFT JOIN bawalis b ON b.id = m.bill_id
            WHERE mi.item_type = 'expense' AND mi.amount <> 0

            UNION ALL

            SELECT 'takhlis', t.id, t.cert_date,
                   'مصروفات تخليص',
                   COALESCE(NULLIF(t.cert_name,''), ''),
                   t.expenses, t.cert_number, 0
            FROM takhlis t
            WHERE t.expenses <> 0

            UNION ALL

            SELECT 'shamil', sh.id, sh.invoice_date,
                   'مصروف فاتورة',
                   COALESCE(NULLIF(sh.client,''), NULLIF(sh.company,''), ''),
                   sh.expense, sh.invoice_no, 0
            FROM shamil sh
            WHERE sh.expense <> 0

            UNION ALL

            " . Rep::expenseUnionSql() . "

            " . ($miscSql !== '' ? "UNION ALL\n\n            $miscSql\n" : '') . "

            UNION ALL

            SELECT 'debt', da.id, dp.pay_date,
                   CASE da.kind WHEN 'inspection' THEN 'سداد الكشف'
                                WHEN 'guarantee'  THEN 'سداد الضمان'
                                ELSE 'سداد مديونية' END,
                   da.name,
                   dp.amount, dp.note, dp.is_visa
            FROM debt_payments dp
            JOIN debt_accounts da ON da.id = dp.account_id
            WHERE dp.amount <> 0
        ";
        return [$sql, []];
    }

    /** يبني شرط WHERE الخارجي على الجدول الموحّد + المُعامَلات */
    private static function outerWhere(array $f): array
    {
        $conds = [];
        $params = [];
        if (!empty($f['source']) && isset(self::SOURCES[$f['source']])) {
            $conds[] = "t.source = ?"; $params[] = $f['source'];
        }
        if (!empty($f['from'])) { $conds[] = "t.d >= ?"; $params[] = $f['from']; }
        if (!empty($f['to']))   { $conds[] = "t.d <= ?"; $params[] = $f['to']; }
        if (($f['visa'] ?? '') === 'only')    { $conds[] = "t.is_visa = 1"; }
        elseif (($f['visa'] ?? '') === 'cash'){ $conds[] = "t.is_visa = 0"; }
        if (!empty($f['q'])) {
            $conds[] = "(t.category LIKE ? OR t.party LIKE ? OR t.note LIKE ?)";
            $like = '%' . $f['q'] . '%';
            array_push($params, $like, $like, $like);
        }
        $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
        return [$where, $params];
    }

    /** صفوف السجل (صفحة واحدة) مرتّبة بالأحدث */
    public static function ledger(array $f, int $limit, int $offset): array
    {
        [$union, $up] = self::unionSql();
        [$where, $wp] = self::outerWhere($f);
        $sql = "SELECT * FROM ($union) t $where
                ORDER BY (t.d IS NULL), t.d DESC, t.source
                LIMIT $limit OFFSET $offset";
        return Database::all($sql, array_merge($up, $wp));
    }

    /** إجمالي عدد البنود المطابقة للفلتر */
    public static function count(array $f): int
    {
        [$union, $up] = self::unionSql();
        [$where, $wp] = self::outerWhere($f);
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM ($union) t $where", array_merge($up, $wp));
    }

    /**
     * الإجماليات: المجموع الكلّي + مجموع كل مصدر (مع نفس الفلتر).
     * يُعيد ['grand'=>float, 'by_source'=>[src=>['label','value','count']]].
     */
    public static function totals(array $f): array
    {
        [$union, $up] = self::unionSql();
        [$where, $wp] = self::outerWhere($f);
        $rows = Database::all(
            "SELECT t.source, COALESCE(SUM(t.amount),0) AS value, COUNT(*) AS cnt
             FROM ($union) t $where GROUP BY t.source",
            array_merge($up, $wp));

        $bySource = [];
        foreach (self::SOURCES as $key => $label) {
            $bySource[$key] = ['label' => $label, 'value' => 0.0, 'count' => 0];
        }
        // ملاحظة: «سداد المديونيات» يظل ظاهراً كبند/مصدر مستقل للاطلاع،
        // لكنه ليس مصروفاً فلا يدخل «إجمالي المصاريف» (قرار صاحب الشركة).
        $grand = 0.0; $debt = 0.0;
        foreach ($rows as $r) {
            $src = $r['source'];
            if (!isset($bySource[$src])) {
                $bySource[$src] = ['label' => $src, 'value' => 0.0, 'count' => 0];
            }
            $bySource[$src]['value'] = (float) $r['value'];
            $bySource[$src]['count'] = (int) $r['cnt'];
            if ($src === 'debt') { $debt += (float) $r['value']; }
            else                 { $grand += (float) $r['value']; }
        }
        return ['grand' => round($grand, 2), 'debt' => round($debt, 2), 'by_source' => $bySource];
    }

    /**
     * إجمالي المصروفات لكل شهر في سنة معيّنة (يستبعد سداد المديونيات — كقاعدة النظام).
     * يعيد مصفوفة [1..12] => قيمة. يستخدم نفس مصادر «إجمالي المصاريف» بالضبط.
     */
    public static function monthlyExpenses(int $year): array
    {
        [$union, $up] = self::unionSql();
        $out = array_fill(1, 12, 0.0);
        $rows = Database::all(
            "SELECT MONTH(t.d) m, COALESCE(SUM(t.amount),0) v
             FROM ($union) t
             WHERE t.source <> 'debt' AND t.d IS NOT NULL AND YEAR(t.d) = ?
             GROUP BY MONTH(t.d)",
            array_merge($up, [$year])
        );
        foreach ($rows as $r) {
            $m = (int) $r['m'];
            if ($m >= 1 && $m <= 12) { $out[$m] = round((float) $r['v'], 2); }
        }
        return $out;
    }

    /** المصروفات ومدفوعات البوالص الشهرية في استعلام واحد لرسم الداشبورد. */
    public static function monthlyDashboard(int $year): array
    {
        [$union, $up] = self::unionSql();
        return Database::all(
            "SELECT x.m, COALESCE(SUM(x.expenses),0) expenses,
                    COALESCE(SUM(x.collections),0) collections
             FROM (
                SELECT MONTH(t.d) m, t.amount expenses, 0 collections
                FROM ($union) t
                WHERE t.source <> 'debt' AND t.d IS NOT NULL AND YEAR(t.d)=?
                UNION ALL
                SELECT MONTH(bp.pay_date), 0, bp.amount
                FROM bill_payments bp
                WHERE bp.is_legacy=0 AND YEAR(bp.pay_date)=?
             ) x
             GROUP BY x.m",
            array_merge($up, [$year, $year])
        );
    }

    /**
     * أرقام ملخص يوم الداشبورد في رحلة واحدة لقاعدة البيانات.
     * المصروفات تستخدم نفس اتحاد صفحة المصروفات وتستبعد سداد المديونيات كما هو.
     */
    public static function dashboardDay(string $date): array
    {
        [$union, $up] = self::unionSql();
        $row = Database::row(
            "SELECT
                (SELECT COALESCE(SUM(t.amount),0) FROM ($union) t
                 WHERE t.source <> 'debt' AND t.d = ?) AS expenses,
                (SELECT COUNT(*) FROM permit_stages s
                 WHERE s.stage_key='receipts' AND s.entry_date = ?) AS receipts_cnt,
                (SELECT COALESCE(SUM(s.amount),0) FROM permit_stages s
                 WHERE s.stage_key='receipts' AND s.entry_date = ?) AS receipts_val,
                (SELECT COALESCE(SUM(bp.amount),0) FROM bill_payments bp
                 WHERE bp.is_legacy = 0 AND bp.pay_date = ?) AS payments,
                (SELECT COUNT(*) FROM permits p WHERE p.permit_date = ?) AS permits_new,
                (SELECT COUNT(*) FROM movements m WHERE m.movement_date = ?) AS movements",
            array_merge($up, [$date, $date, $date, $date, $date, $date])
        ) ?: [];
        return [
            'expenses'     => round((float) ($row['expenses'] ?? 0), 2),
            'receipts_cnt' => (int) ($row['receipts_cnt'] ?? 0),
            'receipts_val' => round((float) ($row['receipts_val'] ?? 0), 2),
            'payments'     => (float) ($row['payments'] ?? 0),
            'permits_new'  => (int) ($row['permits_new'] ?? 0),
            'movements'    => (int) ($row['movements'] ?? 0),
        ];
    }

    /** السنوات المتاحة في السجل (لفلتر سريع) */
    public static function years(): array
    {
        [$union, $up] = self::unionSql();
        $rows = Database::all(
            "SELECT DISTINCT YEAR(t.d) y FROM ($union) t WHERE t.d IS NOT NULL ORDER BY y DESC", $up);
        return array_column($rows, 'y');
    }

    // ------------------------------------------------------------ المصاريف اليدوية

    public static function findManual(int $id): ?array
    {
        return Database::row("SELECT * FROM manual_expenses WHERE id = ?", [$id]);
    }

    public static function createManual(array $d): int
    {
        Database::exec(
            "INSERT INTO manual_expenses (exp_date, category, beneficiary, amount, note, is_visa, visa_card_id)
             VALUES (:exp_date, :category, :beneficiary, :amount, :note, :is_visa, :visa_card_id)",
            self::bind($d));
        $id = Database::lastId();
        $saved = self::findManual($id);
        Audit::log('create', 'manual_expenses', $id, null, $saved,
            'إضافة مصروف يدوي'.(!empty($d['category']) ? ' — '.$d['category'] : '').
            ' بقيمة '.number_format((float)$d['amount'], 2, '.', ','),
            ['record_date'=>$d['exp_date'] ?? null]);
        return $id;
    }

    public static function updateManual(int $id, array $d): void
    {
        $p = self::bind($d);
        $p[':id'] = $id;
        Database::exec(
            "UPDATE manual_expenses SET exp_date=:exp_date, category=:category,
                    beneficiary=:beneficiary, amount=:amount, note=:note, is_visa=:is_visa,
                    visa_card_id=:visa_card_id WHERE id=:id", $p);
    }

    public static function deleteManual(int $id): void
    {
        Database::exec("DELETE FROM manual_expenses WHERE id = ?", [$id]);
    }

    private static function bind(array $d): array
    {
        return [
            ':exp_date'    => $d['exp_date'] ?: null,
            ':category'    => $d['category'] !== '' ? $d['category'] : null,
            ':beneficiary' => $d['beneficiary'] !== '' ? $d['beneficiary'] : null,
            ':amount'      => (float) $d['amount'],
            ':note'        => $d['note'] !== '' ? $d['note'] : null,
            ':is_visa'     => !empty($d['is_visa']) ? 1 : 0,
            ':visa_card_id'=> !empty($d['is_visa']) && !empty($d['visa_card_id']) ? (int) $d['visa_card_id'] : null,
        ];
    }
}
