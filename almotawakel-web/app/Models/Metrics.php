<?php
namespace App\Models;

use App\Core\Database;
use App\Core\App;

/**
 * محرّك التجميعات المالية — مطابق لمعادلات لوحة التحكم والتقارير في الإكسل.
 */
class Metrics
{
    private static function sum(string $table, string $col, string $where = '', array $p = []): float
    {
        $w = $where ? "WHERE $where" : '';
        return (float) Database::scalar("SELECT COALESCE(SUM(`$col`),0) FROM `$table` $w", $p);
    }

    /** يبني شرط نطاق تاريخي (من/إلى) على عمود مُعطى ويضيف المُعامَلات إلى $p */
    private static function dateCond(string $col, ?string $from, ?string $to, array &$p): string
    {
        $c = [];
        if ($from !== null && $from !== '') { $c[] = "$col >= ?"; $p[] = $from; }
        if ($to   !== null && $to   !== '') { $c[] = "$col <= ?"; $p[] = $to; }
        return $c ? implode(' AND ', $c) : '';
    }

    /** مجموع عمود مع تصفية بنطاق تاريخي على عمود التاريخ (+ شرط إضافي اختياري) */
    private static function sumD(string $table, string $col, string $dateCol, ?string $from, ?string $to, string $extra = ''): float
    {
        $p = []; $w = self::dateCond("`$dateCol`", $from, $to, $p);
        $conds = array_filter([$extra, $w]);
        return (float) Database::scalar(
            "SELECT COALESCE(SUM(`$col`),0) FROM `$table`" . ($conds ? ' WHERE ' . implode(' AND ', $conds) : ''), $p);
    }

    /** المؤشرات الست الرئيسية (مع تصفية اختيارية بنطاق تاريخي).
     *  is_legacy=0: البوالص المُرحَّلة من الأرشيف (v26) ودفعاتها المُرحَّلة خارج كل المؤشرات. */
    public static function totals(?string $from = null, ?string $to = null): array
    {
        $bawFreight = self::sumD('bawalis','freight','bill_date',$from,$to,'is_legacy = 0');
        $bawExpense = self::sumD('movements','total_expense','movement_date',$from,$to);   // مصروفات البوالص انتقلت للحركات
        $billPaid   = self::sumD('bill_payments','amount','pay_date',$from,$to,'is_legacy = 0');
        return [
            'revenue' => $bawFreight + self::sumD('shamil','invoice_total','invoice_date',$from,$to)
                       + self::sumD('takhlis','total_statement','cert_date',$from,$to) + self::sumD('generators','freight','gen_date',$from,$to),
            // إجمالي المصروفات = نفس رقم صفحة المصروفات بالضبط (المصدر الوحيد للحقيقة):
            // حركات البوالص + الأذونات + المصاريف اليدوية + التخليص + الفواتير + سداد المديونيات.
            'expenses'=> Expense::totals(['from'=>$from,'to'=>$to])['grand'],
            'profit'  => self::sumD('shamil','profit_total','invoice_date',$from,$to) + self::sumD('shamil','other_profit','invoice_date',$from,$to)
                       + ($bawFreight - $bawExpense),
            // «المدفوعات» = فلوس خارجة من المتوكل للعملاء/أصحاب البيانات (وليست متحصلات)
            'collections' => $billPaid + self::sumD('shamil','payments','invoice_date',$from,$to)
                       + self::sumD('takhlis','payments','cert_date',$from,$to) + self::sumD('generators','payments','gen_date',$from,$to),
            // العهد المخصّصة للبوالص (فلوس العميل التي في عهدتنا)
            'custody' => self::sumD('bawalis','custody','bill_date',$from,$to,'is_legacy = 0'),
            // المتبقي = دين علينا للعملاء حتى الآن (لا يُصفّى بالتاريخ)
            'outstanding' => self::sum('bawalis','remaining','is_legacy = 0') + self::sum('shamil','account_total')
                       + self::sum('takhlis','remaining') + self::sum('generators','remaining'),
            'freight' => $bawFreight + self::sumD('shamil','freight','invoice_date',$from,$to) + self::sumD('generators','freight','gen_date',$from,$to),
        ];
    }

    /** العدّادات */
    public static function counters(): array
    {
        $containers = (int) Database::scalar("SELECT COALESCE(SUM(containers),0) FROM shamil")
                    + (int) Database::scalar("SELECT COUNT(*) FROM bawalis WHERE is_legacy = 0 AND container_no IS NOT NULL AND container_no <> ''");
        return [
            'bawalis'    => (int) Database::scalar("SELECT COUNT(*) FROM bawalis WHERE is_legacy = 0"),
            'invoices'   => (int) Database::scalar("SELECT COUNT(*) FROM shamil"),
            'containers' => $containers,
            'generators' => (int) Database::scalar("SELECT COALESCE(SUM(gen_count),0) FROM generators"),
            'certs'      => (int) Database::scalar("SELECT COUNT(*) FROM takhlis"),
        ];
    }

    /** ملخص الأذونات (لشريط الهايلايتس في لوحة التحكم) */
    public static function permitsSummary(): array
    {
        $c = Database::row(
            "SELECT COUNT(*) total,
                    COALESCE(SUM(status='open'),0)   AS open_cnt,
                    COALESCE(SUM(status='closed'),0) AS closed_cnt
             FROM permits"
        ) ?: ['total'=>0,'open_cnt'=>0,'closed_cnt'=>0];

        $incomplete = (int) Database::scalar(
            "SELECT COUNT(*) FROM permits p WHERE p.status='open' AND (
                (SELECT COUNT(*) FROM bawalis b WHERE b.is_legacy=0 AND b.permit_id=p.id)=0
                OR (SELECT COUNT(*) FROM permit_stages s WHERE s.permit_id=p.id)=0
                OR (SELECT COUNT(*) FROM permit_stages s WHERE s.permit_id=p.id AND s.status='pending')>0)"
        );

        // إجمالي الحاويات (على مستوى الأذونات)
        $containers = (int) Database::scalar("SELECT COALESCE(SUM(containers),0) FROM permits");

        $today = date('Y-m-d');
        $todayExpense = (float) Database::scalar(
            "SELECT COALESCE(SUM(s.amount),0) FROM permit_stages s WHERE " . \App\Models\Permit::expenseSql('s') . " AND s.entry_date = ?",
            [$today]
        );
        $todayCustody = (float) Database::scalar(
            "SELECT COALESCE(SUM(custody),0) FROM bawalis WHERE is_legacy = 0 AND bill_date = ?", [$today]
        );

        return [
            'total'      => (int)$c['total'],
            'open'       => (int)$c['open_cnt'],
            'closed'     => (int)$c['closed_cnt'],
            'incomplete' => $incomplete,
            'containers' => $containers,
            'today_expense' => round($todayExpense, 2),
            'today_custody' => round($todayCustody, 2),
        ];
    }

    /**
     * الأذونات «الجاهزة للفوترة» — أذونات مُغلقة (status=closed) لم يصدر لها أي مستند
     * فوترة بعد (لا فاتورة ولا كشف حساب). بمجرد فوترتها تختفي من القائمة تلقائياً.
     * لكل صف: بيانات الإذن + النولون الفعلي (مجموع نولون بوالصه) + عدد بوالصه.
     */
    public static function readyToInvoice(int $limit = 8): array
    {
        $rows = Database::all(
            "SELECT p.id, p.permit_no, p.permit_type, p.permit_date,
                    p.company, p.client, p.destination, p.containers, p.container_type,
                    COALESCE(NULLIF(p.company,''), NULLIF(p.client,''), '—') AS party,
                    (SELECT COUNT(*) FROM bawalis b WHERE b.is_legacy=0 AND b.permit_id = p.id) AS bills
             FROM permits p
             WHERE p.status = 'closed'
               AND NOT EXISTS (SELECT 1 FROM permit_invoices i WHERE i.permit_id = p.id)
             ORDER BY p.permit_date DESC, p.id DESC
             LIMIT $limit"
        );
        self::attachContainerLabels($rows);
        return $rows;
    }

    /**
     * الأذونات المفتوحة مرتّبة بأحدث نشاط (آخر إذن تم إضافة بيانات له).
     * لكل صف: رقم الإذن + الجهة + تصنيف الحاويات وعددها + عدد البوالص والمراحل + تاريخ آخر نشاط.
     */
    public static function openPermitsList(int $limit = 8): array
    {
        $rows = Database::all(
            "SELECT p.id, p.permit_no, p.permit_type, p.permit_date,
                    p.container_type, p.containers,
                    COALESCE(NULLIF(p.company,''), NULLIF(p.client,''), '—') AS party,
                    (SELECT COUNT(*) FROM bawalis b       WHERE b.is_legacy=0 AND b.permit_id = p.id) AS bills,
                    (SELECT COUNT(*) FROM permit_stages s WHERE s.permit_id = p.id) AS stages,
                    GREATEST(
                        COALESCE(p.permit_date, '1900-01-01'),
                        COALESCE((SELECT MAX(s.entry_date) FROM permit_stages s WHERE s.permit_id = p.id), '1900-01-01'),
                        COALESCE((SELECT MAX(b.bill_date)  FROM bawalis b       WHERE b.is_legacy=0 AND b.permit_id = p.id), '1900-01-01')
                    ) AS last_activity
             FROM permits p
             WHERE p.status = 'open'
             ORDER BY last_activity DESC, p.id DESC
             LIMIT $limit"
        );
        self::attachContainerLabels($rows);
        return $rows;
    }

    /**
     * يضيف لكل صف إذن حقل `ct_label` = تاج الحاويات المعتاد «عدد×تصنيف»
     * مجمَّعًا من تصنيفات بوالص الإذن (نفس منطق صفحة الإصدار)، مع رجوع لعدد/تصنيف الإذن.
     */
    private static function attachContainerLabels(array &$rows): void
    {
        if (!$rows) return;
        $ids = array_column($rows, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $bd  = Database::all(
            "SELECT permit_id, container_type AS type, SUM(container_count) AS cnt
             FROM bawalis
             WHERE is_legacy=0 AND permit_id IN ($ph) AND container_type IS NOT NULL AND container_type <> '' AND container_count > 0
             GROUP BY permit_id, container_type",
            $ids
        );
        $byPermit = [];
        foreach ($bd as $r) { $byPermit[(int)$r['permit_id']][] = ['type'=>$r['type'], 'cnt'=>(int)$r['cnt']]; }
        foreach ($rows as &$row) {
            $row['ct_label'] = \App\Models\Permit::containerLabel(
                $byPermit[(int)$row['id']] ?? [],
                (int)($row['containers'] ?? 0),
                $row['container_type'] ?? null
            );
        }
    }

    /**
     * مؤشرات اليوم المحدَّد (تاريخ العمل) — بدون أي نولون.
     * مصروفات/إيصالات/مدفوعات/أذونات جديدة في ذلك اليوم + أرصدة عامة (عهدة/متبقٍّ).
     */
    public static function dashDaily(string $date): array
    {
        $expenses = Expense::totals(['from'=>$date,'to'=>$date])['grand'];
        $receipts = Database::row(
            "SELECT COUNT(*) c, COALESCE(SUM(amount),0) v FROM permit_stages
             WHERE stage_key='receipts' AND entry_date = ?", [$date]
        ) ?: ['c'=>0,'v'=>0];
        return [
            'date'         => $date,
            'expenses'     => round((float)$expenses, 2),
            'receipts_cnt' => (int)$receipts['c'],
            'receipts_val' => round((float)$receipts['v'], 2),
            'payments'     => (float) Database::scalar(
                "SELECT COALESCE(SUM(amount),0) FROM bill_payments WHERE is_legacy = 0 AND pay_date = ?", [$date]),
            'permits_new'  => (int) Database::scalar(
                "SELECT COUNT(*) FROM permits WHERE permit_date = ?", [$date]),
            'movements'    => (int) Database::scalar(
                "SELECT COUNT(*) FROM movements WHERE movement_date = ?", [$date]),
        ];
    }

    /**
     * تدفّق آخر المصروفات والإيصالات المُضافة حتى تاريخ العمل (للشريط المتحرك والسجل).
     * كل صف يحمل مرجعه: بند الإذن يظهر جنبه رقم الإذن، بند البوليصة رقمها،
     * المصروف اليدوي المستفيد. لكل صف: kind (expense|receipt), label, owner, ref, ref_kind, amount, note, d.
     */
    public static function expenseReceiptFeed(string $date, int $limit = 14): array
    {
        $expSql = \App\Models\Permit::expenseSql('s');
        $stageCase = "CASE s.stage_key";
        foreach (\App\Models\Permit::expenseKeys() as $k) {
            $stageCase .= " WHEN " . Database::conn()->quote($k) . " THEN " . Database::conn()->quote(\App\Models\Permit::label($k));
        }
        $stageCase .= " ELSE COALESCE(NULLIF(s.note,''),'بند مخصّص') END";

        $rows = Database::all("
            SELECT * FROM (
                SELECT (CASE WHEN s.stage_key='receipts' THEN 'receipt' ELSE 'expense' END) AS kind,
                       $stageCase AS label,
                       COALESCE(NULLIF(p.company,''), NULLIF(p.client,''), '') AS owner,
                       p.permit_no AS ref, 'permit' AS ref_kind,
                       s.amount AS amount, COALESCE(NULLIF(s.note,''), s.ref_no) AS note,
                       s.entry_date AS d, s.id AS sid, s.created_at AS ts
                FROM permit_stages s JOIN permits p ON p.id = s.permit_id
                WHERE $expSql AND s.amount <> 0 AND s.entry_date <= ?
                UNION ALL
                SELECT 'expense', COALESCE(NULLIF(me.category,''),'مصروف يدوي'),
                       COALESCE(NULLIF(me.beneficiary,''),''), NULL, 'manual',
                       me.amount, me.note, me.exp_date, me.id, me.created_at
                FROM manual_expenses me WHERE me.amount <> 0 AND me.exp_date <= ?
                UNION ALL
                SELECT 'expense', COALESCE(NULLIF(mi.category,''),'مصروف'),
                       COALESCE(NULLIF(b.company,''), NULLIF(b.declaration_owner,''),''),
                       b.bill_number, 'bill', mi.amount, mi.note, m.movement_date, mi.id, m.updated_at
                FROM movement_items mi JOIN movements m ON m.id = mi.movement_id
                     LEFT JOIN bawalis b ON b.id = m.bill_id
                WHERE mi.item_type='expense' AND mi.amount <> 0 AND m.movement_date <= ?
            ) t
            ORDER BY (t.d IS NULL), t.d DESC, t.ts DESC
            LIMIT $limit",
            [$date, $date, $date]
        );
        foreach ($rows as &$r) { $r['is_receipt'] = $r['kind'] === 'receipt' ? 1 : 0; }
        return $rows;
    }

    /** قائمة مصروفات يوم محدَّد (للبوب-أب) — كل مصروفات ذلك اليوم بمرجعها. */
    public static function dayExpenses(string $date): array
    {
        return array_values(array_filter(
            self::expenseReceiptFeed($date, 300),
            fn($r) => $r['d'] === $date && !$r['is_receipt']
        ));
    }

    /** قائمة إيصالات يوم محدَّد (للبوب-أب) — بنود «إيصالات» في مراحل الإصدارات. */
    public static function dayReceipts(string $date): array
    {
        return array_values(array_filter(
            self::expenseReceiptFeed($date, 300),
            fn($r) => $r['d'] === $date && $r['is_receipt']
        ));
    }

    /** تفصيل رصيد العهدة لليوم المحدَّد (للبوب-أب) — الرصيد + حركة اليوم. */
    public static function custodyDay(string $date): array
    {
        $topups = Database::all(
            "SELECT cust_date d, driver, amount, note FROM manual_custody
             WHERE cust_date = ? ORDER BY id DESC", [$date]);
        $drivers = Database::all(
            "SELECT custody_date d, driver, amount, note FROM driver_custody
             WHERE custody_date = ? ORDER BY id DESC", [$date]);
        $dayExpense = (float) Database::scalar(
            "SELECT COALESCE(SUM(s.amount),0) FROM permit_stages s WHERE " .
            \App\Models\Permit::expenseSql('s') . " AND s.entry_date = ?", [$date]);
        return [
            'balance'      => \App\Models\Custody::balance(),
            'topups'       => $topups,
            'topups_sum'   => array_sum(array_map(fn($r)=>(float)$r['amount'], $topups)),
            'drivers'      => $drivers,
            'drivers_sum'  => array_sum(array_map(fn($r)=>(float)$r['amount'], $drivers)),
            'day_expense'  => round($dayExpense, 2),
        ];
    }

    /** عدد وإجمالي نولون الأذونات الجاهزة للفوترة (مُغلقة وغير مفوترة) */
    public static function readyToInvoiceSummary(): array
    {
        $row = Database::row(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM((SELECT COALESCE(SUM(b.freight),0) FROM bawalis b WHERE b.is_legacy=0 AND b.permit_id = p.id)),0) AS freight,
                    COALESCE(SUM(p.containers),0) AS containers
             FROM permits p
             WHERE p.status = 'closed'
               AND NOT EXISTS (SELECT 1 FROM permit_invoices i WHERE i.permit_id = p.id)"
        ) ?: ['cnt'=>0,'freight'=>0,'containers'=>0];
        return [
            'count'      => (int)$row['cnt'],
            'freight'    => round((float)$row['freight'], 2),
            'containers' => (int)$row['containers'],
        ];
    }

    /**
     * سلسلة شهرية للوحة التحكم: المصروفات والمدفوعات لكل شهر (بدون أي نولون).
     * المصروفات = نفس تعريف صفحة المصروفات؛ المدفوعات = دفعات سداد البوالص بالشهر.
     * (متسقة تمامًا مع مؤشرات «مصروفات اليوم» و«مدفوعات اليوم».)
     */
    public static function monthlyExpensePayments(int $year): array
    {
        $exp = Expense::monthlyExpenses($year);
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $out[$m] = ['expenses' => $exp[$m] ?? 0.0, 'collections' => 0.0];
        }
        foreach (Database::all(
            "SELECT MONTH(pay_date) m, COALESCE(SUM(amount),0) a
             FROM bill_payments WHERE is_legacy = 0 AND YEAR(pay_date) = ? GROUP BY MONTH(pay_date)", [$year]) as $r) {
            $m = (int) $r['m'];
            if ($m >= 1 && $m <= 12) { $out[$m]['collections'] = round((float) $r['a'], 2); }
        }
        return $out;
    }

    /** الأداء الشهري لسنة معيّنة (12 صف) */
    public static function monthly(int $year): array
    {
        $months = array_fill(1, 12, ['revenue'=>0.0,'expenses'=>0.0,'profit'=>0.0,'collections'=>0.0,'freight'=>0.0,'custody'=>0.0]);

        $add = function (string $table, string $dateCol, array $map) use (&$months, $year) {
            $cols = [];
            foreach ($map as $expr => $_) { $cols[] = "$expr"; }
            $sql = "SELECT MONTH(`$dateCol`) m,
                       " . implode(',', array_map(fn($e,$i)=>"$e AS c$i", array_keys($map), range(0,count($map)-1))) . "
                    FROM `$table` WHERE YEAR(`$dateCol`) = ? GROUP BY MONTH(`$dateCol`)";
            foreach (Database::all($sql, [$year]) as $r) {
                $m = (int)$r['m'];
                if ($m < 1 || $m > 12) continue;
                $i = 0;
                foreach ($map as $expr => $target) {
                    $months[$m][$target] += (float)$r['c'.$i];
                    $i++;
                }
            }
        };

        // البوالص: النولون إيراد وربح (يُخصم منه مصروف الحركات لاحقاً)، والعهدة تشغيلية
        foreach (Database::all("SELECT MONTH(bill_date) m, COALESCE(SUM(freight),0) f, COALESCE(SUM(custody),0) c
                                FROM bawalis WHERE is_legacy = 0 AND YEAR(bill_date) = ? GROUP BY MONTH(bill_date)", [$year]) as $r) {
            $m = (int)$r['m']; if ($m<1||$m>12) continue;
            $months[$m]['revenue'] += (float)$r['f'];
            $months[$m]['profit']  += (float)$r['f'];
            $months[$m]['freight'] += (float)$r['f'];
            $months[$m]['custody'] += (float)$r['c'];
        }
        $add('shamil','invoice_date', [
            'COALESCE(SUM(invoice_total),0)'                   => 'revenue',
            'COALESCE(SUM(freight),0)'                         => 'freight',
            'COALESCE(SUM(expense),0)'                         => 'expenses',
            'COALESCE(SUM(profit_total),0)+COALESCE(SUM(other_profit),0)' => 'profit',
            'COALESCE(SUM(payments),0)'                        => 'collections',
        ]);
        $add('takhlis','cert_date', [
            'COALESCE(SUM(total_statement),0)' => 'revenue',
            'COALESCE(SUM(expenses),0)'        => 'expenses',
            'COALESCE(SUM(payments),0)'        => 'collections',
        ]);
        $add('generators','gen_date', [
            'COALESCE(SUM(freight),0)'    => 'revenue',
            'COALESCE(SUM(freight),0)+0'  => 'freight',   // تعبير مختلف لتفادي تكرار مفتاح المصفوفة
            'COALESCE(SUM(payments),0)'   => 'collections',
        ]);

        // مصروفات الحركات (بديل مصروفات البوالص) — تُضاف للمصروفات وتُخصم من ربح البوالص
        foreach (Database::all("SELECT MONTH(movement_date) m, COALESCE(SUM(total_expense),0) e
                                FROM movements WHERE YEAR(movement_date) = ? GROUP BY MONTH(movement_date)", [$year]) as $r) {
            $m = (int)$r['m']; if ($m<1||$m>12) continue;
            $months[$m]['expenses'] += (float)$r['e'];
            $months[$m]['profit']   -= (float)$r['e'];
        }
        // التحصيل من دفعات السداد (بلا الدفعات المُرحَّلة من الأرشيف)
        foreach (Database::all("SELECT MONTH(pay_date) m, COALESCE(SUM(amount),0) a
                                FROM bill_payments WHERE is_legacy = 0 AND YEAR(pay_date) = ? GROUP BY MONTH(pay_date)", [$year]) as $r) {
            $m = (int)$r['m']; if ($m<1||$m>12) continue;
            $months[$m]['collections'] += (float)$r['a'];
        }

        return $months;
    }

    /** الربح والنولون حسب الشركة (أعلى N) — مع تصفية اختيارية بنطاق تاريخي */
    public static function byCompany(int $limit = 15, ?string $from = null, ?string $to = null): array
    {
        $pb=[]; $wb=self::dateCond('bill_date',$from,$to,$pb);
        $ps=[]; $ws=self::dateCond('invoice_date',$from,$to,$ps);
        return Database::all("
            SELECT company AS name,
                   SUM(freight) AS freight,
                   SUM(net) AS net
            FROM (
                SELECT company, freight, car_net AS net FROM bawalis
                    WHERE is_legacy = 0 AND company IS NOT NULL AND company <> ''" . ($wb ? " AND $wb" : "") . "
                UNION ALL
                SELECT company, freight, profit_total AS net FROM shamil
                    WHERE company IS NOT NULL AND company <> ''" . ($ws ? " AND $ws" : "") . "
            ) t
            GROUP BY company
            ORDER BY freight DESC
            LIMIT $limit", array_merge($pb,$ps));
    }

    /** مستحقات العملاء (أعلى N بالمتبقي) — دين علينا للعملاء من البوالص والفواتير */
    public static function clientDebts(int $limit = 15): array
    {
        return Database::all("
            SELECT name,
                   SUM(invoices)  AS invoices,
                   SUM(remaining) AS remaining
            FROM (
                SELECT company AS name, freight AS invoices, remaining
                FROM bawalis WHERE is_legacy = 0 AND company IS NOT NULL AND company <> ''
                UNION ALL
                SELECT client AS name, invoice_total AS invoices, account_total AS remaining
                FROM shamil WHERE client IS NOT NULL AND client <> ''
            ) t
            GROUP BY name
            HAVING remaining > 0
            ORDER BY remaining DESC
            LIMIT $limit");
    }

    /**
     * أداء السيارات مقابل المصاريف — لكل سيارة: عدد الرحلات، النولون، المصروف، الصافي.
     * المصروف من الحركات المربوطة بالبوليصة.
     */
    public static function carPerformance(int $limit = 25, ?string $from = null, ?string $to = null): array
    {
        $p=[]; $w=self::dateCond('b.bill_date',$from,$to,$p);
        return Database::all("
            SELECT b.car_no                         AS name,
                   MAX(b.car_owner)                 AS owner,
                   COUNT(*)                         AS trips,
                   COALESCE(SUM(b.freight),0)       AS freight,
                   COALESCE(SUM(mv.exp),0)          AS expense
            FROM bawalis b
            LEFT JOIN (SELECT bill_id, SUM(total_expense) exp FROM movements GROUP BY bill_id) mv
                   ON mv.bill_id = b.id
            WHERE b.is_legacy = 0 AND b.car_no IS NOT NULL AND b.car_no <> ''" . ($w ? " AND $w" : "") . "
            GROUP BY b.car_no
            ORDER BY freight DESC
            LIMIT $limit", $p);
    }

    /**
     * العملاء (تشغيلي) — لكل عميل: النولون، العهد، المصروف، المدفوع، المتبقي.
     * يجمع البوالص (بحسب الشركة) مع فواتير «شامل» (بحسب العميل). بدون أرباح.
     */
    public static function customerProfit(int $limit = 25, ?string $from = null, ?string $to = null): array
    {
        $pb=[]; $wb=self::dateCond('b.bill_date',$from,$to,$pb);
        $ps=[]; $ws=self::dateCond('invoice_date',$from,$to,$ps);
        return Database::all("
            SELECT name,
                   SUM(trips)     AS trips,
                   SUM(freight)   AS freight,
                   SUM(custody)   AS custody,
                   SUM(expense)   AS expense,
                   SUM(paid)      AS paid,
                   SUM(remaining) AS remaining
            FROM (
                SELECT b.company AS name, 1 AS trips,
                       b.freight AS freight, b.custody AS custody, COALESCE(mv.exp,0) AS expense,
                       b.paid AS paid, b.remaining AS remaining
                FROM bawalis b
                LEFT JOIN (SELECT bill_id, SUM(total_expense) exp FROM movements GROUP BY bill_id) mv
                       ON mv.bill_id = b.id
                WHERE b.is_legacy = 0 AND b.company IS NOT NULL AND b.company <> ''" . ($wb ? " AND $wb" : "") . "
                UNION ALL
                SELECT client AS name, 1 AS trips,
                       freight AS freight, 0 AS custody, expense AS expense,
                       payments AS paid, account_total AS remaining
                FROM shamil WHERE client IS NOT NULL AND client <> ''" . ($ws ? " AND $ws" : "") . "
            ) t
            GROUP BY name
            ORDER BY freight DESC
            LIMIT $limit", array_merge($pb,$ps));
    }

    /** تقرير المصروفات — تفصيل بنود مصروفات الحركات حسب الفئة + مصروفات التخليص والفواتير */
    public static function expenseReport(?string $from = null, ?string $to = null): array
    {
        $p=[]; $w=self::dateCond('m.movement_date',$from,$to,$p);
        $categories = Database::all("
            SELECT mi.category            AS label,
                   COUNT(*)               AS cnt,
                   COALESCE(SUM(mi.amount),0) AS value
            FROM movement_items mi
            JOIN movements m ON m.id = mi.movement_id
            WHERE mi.item_type = 'expense' AND mi.category IS NOT NULL AND mi.category <> ''" . ($w ? " AND $w" : "") . "
            GROUP BY mi.category
            ORDER BY value DESC", $p);
        // مصروفات الأذونات (بنود permit_stages المصروفة) في المدى الزمني — على entry_date
        $pp = []; $pw = self::dateCond('s.entry_date', $from, $to, $pp);
        $permits = (float) Database::scalar(
            "SELECT COALESCE(SUM(s.amount),0) FROM permit_stages s
             WHERE " . \App\Models\Permit::expenseSql('s') . " AND s.amount <> 0" . ($pw ? " AND $pw" : ""),
            $pp
        );

        return [
            'categories' => $categories,                                                   // مصروفات حركات البوالص (بالفئة)
            'takhlis'    => self::sumD('takhlis','expenses','cert_date',$from,$to),
            'shamil'     => self::sumD('shamil','expense','invoice_date',$from,$to),
            'permits'    => round($permits, 2),                                            // مصروفات الأذونات
            'manual'     => self::sumD('manual_expenses','amount','exp_date',$from,$to),   // مصاريف يدوية
            'debt'       => self::sumD('debt_payments','amount','pay_date',$from,$to),     // سداد مديونيات
        ];
    }

    /** تقرير المدفوعات — دفعات السداد الخارجة للعملاء/أصحاب البيانات (تفصيل + إجمالي) */
    public static function paymentsReport(?string $from = null, ?string $to = null, int $limit = 500): array
    {
        $p = []; $w = self::dateCond('p.pay_date', $from, $to, $p);
        // p.is_legacy=0: الدفعات المُرحَّلة من الأرشيف مخفيّة؛ الدفعات الجديدة على بوالص قديمة تظهر (فلوس فعلية)
        $rows = Database::all("
            SELECT p.pay_date AS d, p.amount, p.note,
                   b.bill_number,
                   COALESCE(NULLIF(b.company,''), NULLIF(b.declaration_owner,''), '—') AS party
            FROM bill_payments p JOIN bawalis b ON b.id = p.bill_id
            WHERE p.is_legacy = 0" . ($w ? " AND $w" : "") . "
            ORDER BY p.pay_date DESC, p.id DESC
            LIMIT $limit", $p);
        return [
            'rows'  => $rows,
            'total' => self::sumD('bill_payments','amount','pay_date',$from,$to,'is_legacy = 0'),
            'count' => (int) Database::scalar(
                "SELECT COUNT(*) FROM bill_payments p WHERE p.is_legacy = 0" . ($w ? " AND $w" : ""), $p),
        ];
    }

    /** تقرير الحركات — حركات البوالص ببنودها الإجمالية (مصروف/إيصال/خصم) */
    public static function movementsReport(?string $from = null, ?string $to = null, int $limit = 500): array
    {
        $p = []; $w = self::dateCond('m.movement_date', $from, $to, $p);
        $rows = Database::all("
            SELECT m.movement_date AS d, m.driver, m.car_owner, m.shipping_co, m.note,
                   m.total_expense, m.total_receipt, m.total_discount,
                   b.bill_number, b.company
            FROM movements m LEFT JOIN bawalis b ON b.id = m.bill_id
            " . ($w ? "WHERE $w" : "") . "
            ORDER BY m.movement_date DESC, m.id DESC
            LIMIT $limit", $p);
        $t = Database::row("
            SELECT COUNT(*) c, COALESCE(SUM(total_expense),0) e,
                   COALESCE(SUM(total_receipt),0) r, COALESCE(SUM(total_discount),0) d
            FROM movements m" . ($w ? " WHERE $w" : ""), $p)
            ?: ['c'=>0,'e'=>0,'r'=>0,'d'=>0];
        return ['rows' => $rows, 'totals' => $t];
    }

    /** تقرير الأذون/الشهادات — قائمة الإصدارات بعدد الحاويات ومصروفاتها وحالتها */
    public static function permitsReport(?string $from = null, ?string $to = null, int $limit = 500): array
    {
        $p = []; $w = self::dateCond('p.permit_date', $from, $to, $p);
        $expSql = \App\Models\Permit::expenseSql('s');
        $rows = Database::all("
            SELECT p.id, p.permit_no, p.permit_type, p.permit_date AS d,
                   p.company, p.client, p.containers, p.status,
                   (SELECT COALESCE(SUM(s.amount),0) FROM permit_stages s
                      WHERE s.permit_id = p.id AND $expSql) AS expense,
                   (SELECT COUNT(*) FROM bawalis b WHERE b.is_legacy=0 AND b.permit_id = p.id) AS bills
            FROM permits p
            " . ($w ? "WHERE $w" : "") . "
            ORDER BY p.permit_date DESC, p.id DESC
            LIMIT $limit", $p);
        $t = Database::row("
            SELECT COUNT(*) c, COALESCE(SUM(containers),0) containers,
                   COALESCE(SUM(status='open'),0) open_cnt,
                   COALESCE(SUM(status='closed'),0) closed_cnt
            FROM permits p" . ($w ? " WHERE $w" : ""), $p)
            ?: ['c'=>0,'containers'=>0,'open_cnt'=>0,'closed_cnt'=>0];
        return ['rows' => $rows, 'totals' => $t];
    }

    /** بروفايل عميل واحد: ملخص مالي + كل البوالص + سجل الدفعات + سلسلة شهرية */
    public static function customerProfile(string $name): ?array
    {
        $bills = Database::all("
            SELECT b.*, COALESCE(mv.exp,0) AS mv_expense
            FROM bawalis b
            LEFT JOIN (SELECT bill_id, SUM(total_expense) exp FROM movements GROUP BY bill_id) mv
                   ON mv.bill_id = b.id
            WHERE b.is_legacy = 0 AND b.company = ?
            ORDER BY b.bill_date DESC, b.id DESC", [$name]);

        $shamilBills = Database::all("
            SELECT * FROM shamil WHERE client = ? ORDER BY invoice_date DESC, id DESC", [$name]);

        if (!$bills && !$shamilBills) return null;

        $sum = ['trips'=>0,'freight'=>0.0,'custody'=>0.0,'expense'=>0.0,'debt'=>0.0,'paid'=>0.0,'remaining'=>0.0];
        foreach ($bills as $b) {
            $sum['trips']++;
            $sum['freight']   += (float)$b['freight'];
            $sum['custody']   += (float)$b['custody'];
            $sum['expense']   += (float)$b['mv_expense'];
            $sum['debt']      += (float)$b['debt'];
            $sum['paid']      += (float)$b['paid'];
            $sum['remaining'] += (float)$b['remaining'];
        }
        foreach ($shamilBills as $s) {
            $sum['trips']++;
            $sum['freight']   += (float)$s['invoice_total'];
            $sum['expense']   += (float)$s['expense'];
            $sum['paid']      += (float)$s['payments'];
            $sum['remaining'] += (float)$s['account_total'];
        }

        $payments = Database::all("
            SELECT p.pay_date, p.amount, p.note, b.bill_number
            FROM bill_payments p JOIN bawalis b ON b.id = p.bill_id
            WHERE b.is_legacy = 0 AND b.company = ?
            ORDER BY p.pay_date DESC, p.id DESC", [$name]);

        $monthly = array_fill(1, 12, ['freight'=>0.0,'collected'=>0.0]);
        foreach (Database::all("SELECT MONTH(bill_date) m, COALESCE(SUM(freight),0) f
                                FROM bawalis WHERE is_legacy = 0 AND company = ? AND bill_date IS NOT NULL
                                GROUP BY MONTH(bill_date)", [$name]) as $r) {
            $mo = (int)$r['m']; if ($mo>=1 && $mo<=12) $monthly[$mo]['freight'] += (float)$r['f'];
        }
        foreach (Database::all("SELECT MONTH(p.pay_date) m, COALESCE(SUM(p.amount),0) a
                                FROM bill_payments p JOIN bawalis b ON b.id = p.bill_id
                                WHERE b.is_legacy = 0 AND b.company = ? AND p.pay_date IS NOT NULL
                                GROUP BY MONTH(p.pay_date)", [$name]) as $r) {
            $mo = (int)$r['m']; if ($mo>=1 && $mo<=12) $monthly[$mo]['collected'] += (float)$r['a'];
        }

        return [
            'name'     => $name,
            'summary'  => $sum,
            'bills'    => $bills,
            'shamil'   => $shamilBills,
            'payments' => $payments,
            'monthly'  => $monthly,
        ];
    }

    /** توزيع مصروفات البوالص */
    public static function bawalisExpenses(): array
    {
        $map = [
            'driver_share'=>'دور سواق','assistant_share'=>'دور تبّاع','overnight_expense'=>'بياتة',
            'expense'=>'مصروف','cards'=>'كرتات','tahia_masr'=>'تحيا مصر','thabtan'=>'ثبتان',
            'z_kawtsh'=>'ظ كاوتش','permits'=>'تصاريح','kawtsh'=>'كاوتش','other_expense'=>'م أخرى','gas'=>'جاز',
        ];
        $selects = [];
        foreach ($map as $col => $_) { $selects[] = "COALESCE(SUM(`$col`),0) AS `$col`"; }
        $row = Database::row("SELECT " . implode(',', $selects) . " FROM bawalis WHERE is_legacy = 0") ?: [];
        $out = [];
        foreach ($map as $col => $label) {
            $out[] = ['label' => $label, 'value' => (float)($row[$col] ?? 0)];
        }
        return $out;
    }

    /** مقارنة نولون المولدات */
    public static function generatorsComparison(): array
    {
        $rows = Database::all("SELECT source, COALESCE(SUM(freight),0) freight FROM generators GROUP BY source");
        $names = ['egycool'=>'ايجي كول','shujaie'=>'الشجيع'];
        $out = ['egycool'=>0.0,'shujaie'=>0.0];
        foreach ($rows as $r) { $out[$r['source']] = (float)$r['freight']; }
        return [
            ['label'=>$names['egycool'],'value'=>$out['egycool']],
            ['label'=>$names['shujaie'],'value'=>$out['shujaie']],
        ];
    }

    /** أرصدة الموردين */
    public static function supplierBalances(int $limit = 12): array
    {
        return Database::all("SELECT name, balance FROM balances
                              WHERE party_type='supplier' AND balance <> 0
                              ORDER BY balance DESC LIMIT $limit");
    }

    public static function reportYear(): int
    {
        return (int) App::setting('report_year', date('Y'));
    }

    /** إجمالي مستحقات الموردين */
    public static function suppliersTotal(): float
    {
        return (float) Database::scalar(
            "SELECT COALESCE(SUM(balance),0) FROM balances WHERE party_type='supplier'");
    }

    /** أحدث تاريخ نشاط في المنظومة (لتثبيت نافذة الرسوم على بيانات حقيقية) */
    private static function anchorDate(): string
    {
        $d = Database::scalar(
            "SELECT MAX(d) FROM (
                SELECT MAX(bill_date) d FROM bawalis WHERE is_legacy = 0
                UNION ALL SELECT MAX(invoice_date) FROM shamil
                UNION ALL SELECT MAX(permit_date) FROM permits
                UNION ALL SELECT MAX(cert_date)   FROM takhlis
                UNION ALL SELECT MAX(gen_date)    FROM generators
             ) x");
        return $d ?: date('Y-m-d');
    }

    /**
     * نبض اليوم — العمليات المسجّلة على تاريخ مرجعي واحد (أحدث يوم به نشاط).
     * لا يعرض أرباحاً؛ فقط حجم التشغيل: عمليات، نولون، تحصيل، حاويات.
     */
    public static function todayPulse(): array
    {
        $d = self::anchorDate();
        return [
            'date'        => $d,
            'bawalis'     => (int)   Database::scalar("SELECT COUNT(*) FROM bawalis WHERE is_legacy = 0 AND bill_date = ?", [$d]),
            'invoices'    => (int)   Database::scalar("SELECT COUNT(*) FROM shamil  WHERE invoice_date = ?", [$d]),
            'permits'     => (int)   Database::scalar("SELECT COUNT(*) FROM permits WHERE permit_date = ?", [$d]),
            'freight'     => (float) Database::scalar("SELECT COALESCE(SUM(freight),0) FROM bawalis WHERE is_legacy = 0 AND bill_date = ?", [$d]),
            'collections' => (float) Database::scalar("SELECT COALESCE(SUM(amount),0)  FROM bill_payments WHERE is_legacy = 0 AND pay_date = ?", [$d]),
            'containers'  => (int)   Database::scalar("SELECT COALESCE(SUM(containers),0) FROM permits WHERE permit_date = ?", [$d]),
        ];
    }

    /**
     * آخر العمليات عبر كل الوحدات (بوالص/فواتير/تخليص/مولدات/أذونات/سداد).
     * يعرض ملخصاً تشغيلياً موحّداً بالترتيب الزمني.
     */
    public static function recentActivity(int $limit = 12): array
    {
        return Database::all("
            SELECT * FROM (
                SELECT 'bawalis' AS kind, id,
                       COALESCE(NULLIF(bill_number,''),'—') AS ref,
                       COALESCE(NULLIF(company,''),'') AS party,
                       freight AS amount, bill_type AS extra,
                       bill_date AS d, created_at AS ts
                FROM bawalis WHERE is_legacy = 0
                UNION ALL
                SELECT 'shamil', id, COALESCE(NULLIF(invoice_no,''),'—'),
                       COALESCE(NULLIF(client,''),''), invoice_total, '', invoice_date, created_at
                FROM shamil
                UNION ALL
                SELECT 'takhlis', id, COALESCE(NULLIF(cert_number,''),'—'),
                       COALESCE(NULLIF(cert_name,''),''), total_statement, '', cert_date, created_at
                FROM takhlis
                UNION ALL
                SELECT 'generators', id, COALESCE(NULLIF(permit_no,''),'—'),
                       COALESCE(NULLIF(company,''),''), freight, source, gen_date, created_at
                FROM generators
                UNION ALL
                SELECT 'permits', id, COALESCE(NULLIF(permit_no,''),'—'),
                       COALESCE(NULLIF(company,''),''), containers, permit_type, permit_date, created_at
                FROM permits
                UNION ALL
                SELECT 'payment', p.id, COALESCE(NULLIF(b.bill_number,''),'—'),
                       COALESCE(NULLIF(b.company,''),''), p.amount, '', p.pay_date, p.created_at
                FROM bill_payments p JOIN bawalis b ON b.id = p.bill_id
                WHERE p.is_legacy = 0
            ) t
            ORDER BY ts DESC, d DESC
            LIMIT $limit");
    }

    /** حجم العمليات اليومي خلال آخر N يوم (منتهياً بأحدث يوم نشاط) — لرسم الأعمدة */
    public static function activityTrend(int $days = 14, ?string $anchor = null): array
    {
        $anchor = ($anchor !== null && $anchor !== '') ? $anchor : self::anchorDate();
        $labels = []; $map = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("$anchor -$i days"));
            $labels[] = date('d/m', strtotime($d));
            $map[$d]  = 0;
        }
        $from = date('Y-m-d', strtotime("$anchor -" . ($days - 1) . " days"));
        foreach ([['bawalis','bill_date'],['shamil','invoice_date'],
                  ['takhlis','cert_date'],['generators','gen_date'],['permits','permit_date']] as [$tbl,$col]) {
            $extra = $tbl === 'bawalis' ? " AND is_legacy = 0" : "";
            foreach (Database::all(
                "SELECT `$col` d, COUNT(*) c FROM `$tbl` WHERE `$col` BETWEEN ? AND ?$extra GROUP BY `$col`",
                [$from, $anchor]) as $r) {
                if (isset($map[$r['d']])) $map[$r['d']] += (int)$r['c'];
            }
        }
        return ['labels' => $labels, 'data' => array_values($map)];
    }
}
