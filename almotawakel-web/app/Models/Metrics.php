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
        $row = Database::row(
            "SELECT
                (SELECT COUNT(*) FROM bawalis WHERE is_legacy = 0) AS bawalis,
                (SELECT COUNT(*) FROM shamil) AS invoices,
                ((SELECT COALESCE(SUM(containers),0) FROM shamil)
                  + (SELECT COUNT(*) FROM bawalis
                      WHERE is_legacy = 0 AND container_no IS NOT NULL AND container_no <> '')) AS containers,
                (SELECT COALESCE(SUM(gen_count),0) FROM generators) AS generators,
                (SELECT COUNT(*) FROM takhlis) AS certs"
        ) ?: [];
        return [
            'bawalis'    => (int) ($row['bawalis'] ?? 0),
            'invoices'   => (int) ($row['invoices'] ?? 0),
            'containers' => (int) ($row['containers'] ?? 0),
            'generators' => (int) ($row['generators'] ?? 0),
            'certs'      => (int) ($row['certs'] ?? 0),
        ];
    }

    /** ملخص الأذونات (لشريط الهايلايتس في لوحة التحكم) */
    public static function permitsSummary(): array
    {
        $today = date('Y-m-d');
        $expSql = \App\Models\Permit::expenseSql('s');
        $c = Database::row(
            "SELECT COUNT(*) total,
                    COALESCE(SUM(p.status='open'),0) AS open_cnt,
                    COALESCE(SUM(p.status='closed'),0) AS closed_cnt,
                    COALESCE(SUM(p.containers),0) AS containers,
                    (SELECT COUNT(*) FROM permits p2 WHERE p2.status='open' AND (
                        (SELECT COUNT(*) FROM bawalis b WHERE b.is_legacy=0 AND b.permit_id=p2.id)=0
                        OR (SELECT COUNT(*) FROM permit_stages s WHERE s.permit_id=p2.id)=0
                        OR (SELECT COUNT(*) FROM permit_stages s WHERE s.permit_id=p2.id AND s.status='pending')>0
                    )) AS incomplete,
                    (SELECT COALESCE(SUM(s.amount),0) FROM permit_stages s
                      WHERE $expSql AND s.entry_date = ?) AS today_expense,
                    (SELECT COALESCE(SUM(b.custody),0) FROM bawalis b
                      WHERE b.is_legacy=0 AND b.bill_date = ?) AS today_custody
             FROM permits p",
            [$today, $today]
        ) ?: [];

        return [
            'total'      => (int) ($c['total'] ?? 0),
            'open'       => (int) ($c['open_cnt'] ?? 0),
            'closed'     => (int) ($c['closed_cnt'] ?? 0),
            'incomplete' => (int) ($c['incomplete'] ?? 0),
            'containers' => (int) ($c['containers'] ?? 0),
            'today_expense' => round((float) ($c['today_expense'] ?? 0), 2),
            'today_custody' => round((float) ($c['today_custody'] ?? 0), 2),
        ];
    }

    /**
     * الأذونات «الجاهزة للفوترة» — أذونات مُغلقة (status=closed) لم يصدر لها أي مستند
     * فوترة بعد (لا فاتورة ولا كشف حساب). بمجرد فوترتها تختفي من القائمة تلقائياً.
     * لكل صف: بيانات الإذن + النولون الفعلي (مجموع نولون بوالصه) + عدد بوالصه.
     */
    public static function readyToInvoice(int $limit = 8): array
    {
        return self::readyToInvoiceDashboard($limit)['rows'];
    }

    /** قائمة الجاهز + إجمالي كل الجاهز في الاستعلام نفسه (قبل LIMIT). */
    /**
     * ⚠️ **`$upto` — احترام يوم العمل (بلاغ المالك 2026-07-28).**
     *    الشاشة كلها بتشتغل بيوم عمل مختار، فلمّا المالك يبصّ على يوم ٢٦ ما
     *    ينفعش يشوف إذنًا تاريخه ٢٨ — ده إذن **من مستقبل** سياق شغله.
     *    (متحقَّق: ٥ من ١٠ أذونات مفتوحة كانوا تاريخهم بعد يوم العمل.)
     *    القائمة تفضل **متأخرات** (اللي قبل اليوم بيفضل ظاهر) — الحد على
     *    الأمام بس. `null` = بلا حد (السلوك القديم لأي مستدعٍ تاني).
     */
    public static function readyToInvoiceDashboard(int $limit = 8, ?string $upto = null): array
    {
        $uptoSql = $upto !== null ? ' AND (p.permit_date IS NULL OR p.permit_date <= ?)' : '';
        $rows = Database::all(
            "SELECT p.id, p.permit_no, p.permit_type, p.permit_date,
                    p.company, p.client, p.destination, p.containers, p.container_type,
                    COALESCE(NULLIF(p.company,''), NULLIF(p.client,''), '—') AS party,
                    COALESCE(bs.bills,0) AS bills,
                    COALESCE(NULLIF(ct.ct_label,''),
                        CASE WHEN p.containers>0 AND COALESCE(p.container_type,'')<>'' THEN CONCAT(p.containers,'×',p.container_type)
                             WHEN p.containers>0 THEN CONCAT(p.containers,' حاوية') ELSE '' END) AS ct_label,
                    COUNT(*) OVER() AS _summary_count,
                    COALESCE(SUM(COALESCE(bs.freight,0)) OVER(),0) AS _summary_freight,
                    COALESCE(SUM(p.containers) OVER(),0) AS _summary_containers
             FROM permits p
             LEFT JOIN (
                SELECT permit_id, COUNT(*) bills, SUM(freight) freight
                FROM bawalis WHERE is_legacy=0 GROUP BY permit_id
             ) bs ON bs.permit_id=p.id
             LEFT JOIN (
                SELECT z.permit_id,
                       GROUP_CONCAT(CONCAT(z.cnt,'×',z.type) ORDER BY z.type SEPARATOR '، ') ct_label
                FROM (
                    SELECT permit_id, container_type type, SUM(container_count) cnt
                    FROM bawalis
                    WHERE is_legacy=0 AND container_type IS NOT NULL AND container_type<>'' AND container_count>0
                    GROUP BY permit_id, container_type
                ) z GROUP BY z.permit_id
             ) ct ON ct.permit_id=p.id
             WHERE p.status = 'closed'
               AND NOT EXISTS (SELECT 1 FROM permit_invoices i WHERE i.permit_id = p.id)
               $uptoSql
             ORDER BY p.permit_date DESC, p.id DESC
             LIMIT $limit",
            $upto !== null ? [$upto] : []
        );
        $first = $rows[0] ?? [];
        $summary = [
            'count' => (int) ($first['_summary_count'] ?? 0),
            'freight' => round((float) ($first['_summary_freight'] ?? 0), 2),
            'containers' => (int) ($first['_summary_containers'] ?? 0),
        ];
        foreach ($rows as &$row) {
            unset($row['_summary_count'], $row['_summary_freight'], $row['_summary_containers']);
        }
        unset($row);
        return ['rows' => $rows, 'summary' => $summary];
    }

    /**
     * الأذونات المفتوحة مرتّبة بأحدث نشاط (آخر إذن تم إضافة بيانات له).
     * لكل صف: رقم الإذن + الجهة + تصنيف الحاويات وعددها + عدد البوالص والمراحل + تاريخ آخر نشاط.
     */
    /**
     * ⚠️ **`$upto` — احترام يوم العمل (بلاغ المالك 2026-07-28).**
     *    الشاشة كلها بتشتغل بيوم عمل مختار، فلمّا المالك يبصّ على يوم ٢٦ ما
     *    ينفعش يشوف إذنًا تاريخه ٢٨ — ده إذن **من مستقبل** سياق شغله.
     *    (متحقَّق: ٥ من ١٠ أذونات مفتوحة كانوا تاريخهم بعد يوم العمل.)
     *    القائمة تفضل **متأخرات** (اللي قبل اليوم بيفضل ظاهر) — الحد على
     *    الأمام بس. `null` = بلا حد (السلوك القديم لأي مستدعٍ تاني).
     */
    public static function openPermitsList(int $limit = 8, ?string $upto = null): array
    {
        $uptoSql = $upto !== null ? ' AND (p.permit_date IS NULL OR p.permit_date <= ?)' : '';
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
                    ) AS last_activity,
                    COALESCE(NULLIF(ct.ct_label,''),
                        CASE WHEN p.containers>0 AND COALESCE(p.container_type,'')<>'' THEN CONCAT(p.containers,'×',p.container_type)
                             WHEN p.containers>0 THEN CONCAT(p.containers,' حاوية') ELSE '' END) AS ct_label
             FROM permits p
             LEFT JOIN (
                SELECT z.permit_id,
                       GROUP_CONCAT(CONCAT(z.cnt,'×',z.type) ORDER BY z.type SEPARATOR '، ') ct_label
                FROM (
                    SELECT permit_id, container_type type, SUM(container_count) cnt
                    FROM bawalis
                    WHERE is_legacy=0 AND container_type IS NOT NULL AND container_type<>'' AND container_count>0
                    GROUP BY permit_id, container_type
                ) z GROUP BY z.permit_id
             ) ct ON ct.permit_id=p.id
             WHERE p.status = 'open'
               $uptoSql
             ORDER BY last_activity DESC, p.id DESC
             LIMIT $limit",
            $upto !== null ? [$upto] : []
        );
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
        $summary = Expense::dashboardDay($date);
        return [
            'date'         => $date,
            'expenses'     => $summary['expenses'],
            'receipts_cnt' => $summary['receipts_cnt'],
            'receipts_val' => $summary['receipts_val'],
            'payments'     => $summary['payments'],
            'permits_new'  => $summary['permits_new'],
            'movements'    => $summary['movements'],
        ];
    }

    /**
     * سياق كروت الأرقام — «الرقم لوحده مش بيقول كتير» (المرحلة ج).
     *
     * كل كارت بياخد: **مقارنة باليوم اللي قبله** + **رسم صغير لآخر ١٤ يوم**.
     * قبل كده الكارت كان بيقول «مصروفات اليوم 12,450» وخلاص — والمالك مالوش
     * طريقة يعرف إن ده كتير ولا قليل غير إنه يفتح التفاصيل ويقارن بدماغه.
     *
     * ⚠️ **مفيش تعريف تاني لأي رقم:** السلسلة من `Expense::dashboardSeries`
     *    (نفس اتحاد `dashboardDay` بالحرف) وسلسلة الخزنة من الأرصدة المثبَّتة
     *    في `daily_closings`. الفاحص بيقارن آخر نقطة برقم الكارت بالمليم.
     *
     * ⚠️ **اتجاه اللون مش تلقائي.** في نظام إحنا فيه **مدينين للعملاء**
     *    (تحذير ١)، «زيادة» مش معناها «تحسّن»: مصروفات ↑ = وحش، خزنة ↑ =
     *    كويس. عشان كده كل مؤشّر بيحدّد `tone` بنفسه، والدالة دي ما بتخمّنش.
     *
     * @return array{expenses:array,receipts:array,custody:array}
     */
    public static function dashContext(string $date, float $custodyNow, int $days = 14): array
    {
        $series = Expense::dashboardSeries($date, $days);
        $last   = static fn(array $v): float => (float) (end($v) ?: 0);
        $prev   = static function (array $v): ?float {
            $n = count($v);
            return $n >= 2 ? (float) $v[$n - 2] : null;
        };

        /**
         * فرق مقروء بين رقم النهاردة ورقم اليوم اللي قبله.
         *
         * ⚠️ **الفرق المطلق هو الأساس والنسبة إضافة** — مش العكس. المالك بيهمّه
         *    «زيادة ١٬٢٥٠ جنيه» أكتر من «+12٪». وكمان النسبة بتبقى مضلِّلة لما
         *    الأساس صغير: رصيد امبارح ٥٠ والنهاردة ٢٤٥٬٠٥٠ بيدّي «+490,000٪»
         *    (اتصادف فعليًا وأنا بجرّب) — رقم صحيح حسابيًا وبلا أي فايدة.
         *    فالنسبة بتتعرض بس لما تكون في نطاق معقول (< ٢٠٠٪).
         */
        $delta = static function (float $now, ?float $before, string $upTone, callable $fmt, string $when): ?array {
            if ($before === null) { return null; }
            $diff = round($now - $before, 2);
            if (abs($diff) < 0.005) { return ['dir' => 'flat', 'text' => 'زي ' . $when, 'tone' => 'mute']; }
            $dir  = $diff > 0 ? 'up' : 'down';
            $text = ($diff > 0 ? '+' : '−') . $fmt(abs($diff));
            if (abs($before) >= 0.005) {
                $pct = abs($diff) / abs($before) * 100;
                if ($pct < 200) { $text .= ' (' . number_format($pct, $pct < 10 ? 1 : 0) . '٪)'; }
            }
            return [
                'dir'  => $dir,
                'text' => $text . ' عن ' . $when,
                'tone' => $dir === 'up' ? $upTone : ($upTone === 'bad' ? 'good' : 'bad'),
            ];
        };
        $money = static fn(float $v): string => money0($v, false);
        $count = static fn(float $v): string => intf($v);

        $balSeries = Closing::balanceSeries($days);
        $balValues = array_column($balSeries, 'v');
        // الرصيد الحالي هو آخر نقطة دايمًا (الأيام المفتوحة مالهاش صف مثبَّت)
        $balValues[] = round($custodyNow, 2);
        $balPrev = count($balValues) >= 2 ? (float) $balValues[count($balValues) - 2] : null;

        return [
            // فلوس طالعة: الزيادة **وحشة**
            'expenses' => [
                'spark' => $series['expenses'],
                'delta' => $delta($last($series['expenses']), $prev($series['expenses']), 'bad', $money, 'امبارح'),
            ],
            // عدد الإيصالات = حجم شغل: الزيادة **كويسة**
            'receipts' => [
                'spark' => array_map('floatval', $series['receipts']),
                'delta' => $delta($last($series['receipts']), $prev($series['receipts']), 'good', $count, 'امبارح'),
            ],
            // رصيد الخزنة: الزيادة **كويسة**، والمقارنة مع آخر يوم متقفل
            'custody' => [
                'spark' => $balValues,
                'delta' => $delta(round($custodyNow, 2), $balPrev, 'good', $money, 'آخر إقفال'),
            ],
        ];
    }

    /**
     * تدفّق آخر المصروفات والإيصالات المُضافة حتى تاريخ العمل (للشريط المتحرك والسجل).
     * كل صف يحمل مرجعه: بند الإذن يظهر جنبه رقم الإذن، بند البوليصة رقمها،
     * المصروف اليدوي المستفيد. لكل صف: kind (expense|receipt), label, owner, ref, ref_kind, amount, note, d.
     */
    /**
     * ⚠️⚠️ **`$exactDay` — بلاغ المالك (2026-07-28): «جدول فلوس ٢٧ بيعرض بنود من ٢٦».**
     *
     * الدالة دي أصلها **سجل تراكمي** (`entry_date <= ?`) بيرجّع آخر N بند
     * **لحد** التاريخ ده — عشان لوح «آخر ما اتسجّل». لكن لوح «فلوس اليوم» في
     * الداشبورد كان بياخد مخرجها **بلا فلترة**، فكان بيعرض بنود أيام فاتت
     * على إنها بتاعة اليوم.
     *
     * التلات مستهلكين التانيين (`dayExpenses` · `dayReceipts` · نافذة
     * الداشبورد) كانوا بيفلتروا `d === $date` **بالإيد بعد الاستعلام** — تلات
     * نسخ من نفس الشرط، واللوح الرابع نسي يعمله. دلوقتي الفلترة **جوّه
     * الاستعلام** ومعامل واحد بيحكمها، فمستحيل مستهلك جديد ينساها.
     *
     * @param bool $exactDay true = اليوم ده بالظبط · false = تراكمي لحد اليوم
     */
    public static function expenseReceiptFeed(string $date, int $limit = 14, bool $exactDay = false): array
    {
        $cmp = $exactDay ? '=' : '<=';
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
                WHERE $expSql AND s.amount <> 0 AND s.entry_date $cmp ?
                UNION ALL
                SELECT 'expense', COALESCE(NULLIF(me.category,''),'مصروف يدوي'),
                       COALESCE(NULLIF(me.beneficiary,''),''), NULL, 'manual',
                       me.amount, me.note, me.exp_date, me.id, me.created_at
                FROM manual_expenses me WHERE me.amount <> 0 AND me.exp_date $cmp ?
                UNION ALL
                SELECT 'expense', COALESCE(NULLIF(mi.category,''),'مصروف'),
                       COALESCE(NULLIF(b.company,''), NULLIF(b.declaration_owner,''),''),
                       b.bill_number, 'bill', mi.amount, mi.note, m.movement_date, mi.id, m.updated_at
                FROM movement_items mi JOIN movements m ON m.id = mi.movement_id
                     LEFT JOIN bawalis b ON b.id = m.bill_id
                WHERE mi.item_type='expense' AND mi.amount <> 0 AND m.movement_date $cmp ?
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
            self::expenseReceiptFeed($date, 300, true),
            static fn ($r) => !$r['is_receipt']
        ));
    }

    /** قائمة إيصالات يوم محدَّد (للبوب-أب) — بنود «إيصالات» في مراحل الإصدارات. */
    public static function dayReceipts(string $date): array
    {
        return array_values(array_filter(
            self::expenseReceiptFeed($date, 300, true),
            static fn ($r) => (bool) $r['is_receipt']
        ));
    }

    /** تفصيل رصيد العهدة لليوم المحدَّد (للبوب-أب) — الرصيد + حركة اليوم. */
    public static function custodyDay(string $date, ?float $currentBalance = null): array
    {
        $rows = Database::all(
            "SELECT kind, d, driver, amount, note FROM (
                SELECT 'topup' kind, cust_date d, driver, amount, note, id
                FROM manual_custody WHERE cust_date = ?
                UNION ALL
                SELECT 'driver', custody_date, driver, amount, note, id
                FROM driver_custody WHERE custody_date = ?
                UNION ALL
                SELECT 'expense', NULL, NULL, COALESCE(SUM(s.amount),0), NULL, 0
                FROM permit_stages s WHERE " . \App\Models\Permit::expenseSql('s') . " AND s.entry_date = ?
             ) x ORDER BY kind, id DESC",
            [$date, $date, $date]
        );
        $topups = []; $drivers = []; $dayExpense = 0.0;
        foreach ($rows as $row) {
            $kind = $row['kind'];
            unset($row['kind']);
            if ($kind === 'topup') { $topups[] = $row; }
            elseif ($kind === 'driver') { $drivers[] = $row; }
            else { $dayExpense = (float) $row['amount']; }
        }
        return [
            'balance'      => $currentBalance ?? \App\Models\Custody::balance(),
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
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $out[$m] = ['expenses' => 0.0, 'collections' => 0.0];
        }
        foreach (Expense::monthlyDashboard($year) as $r) {
            $m = (int) $r['m'];
            if ($m >= 1 && $m <= 12) {
                $out[$m]['expenses'] = round((float) $r['expenses'], 2);
                $out[$m]['collections'] = round((float) $r['collections'], 2);
            }
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
        $parts = []; $params = [];
        $p=[]; $w=self::dateCond('m.movement_date',$from,$to,$p);
        $parts[] = "
            SELECT 'category' AS source, mi.category AS label,
                   COUNT(*) AS cnt, COALESCE(SUM(mi.amount),0) AS value
            FROM movement_items mi
            JOIN movements m ON m.id = mi.movement_id
            WHERE mi.item_type = 'expense' AND mi.category IS NOT NULL AND mi.category <> ''" . ($w ? " AND $w" : "") . "
            GROUP BY mi.category";
        array_push($params, ...$p);

        $pp = []; $pw = self::dateCond('s.entry_date', $from, $to, $pp);
        $parts[] = "
            SELECT 'permits', '', COUNT(*), COALESCE(SUM(s.amount),0)
            FROM permit_stages s
            WHERE " . \App\Models\Permit::expenseSql('s') . " AND s.amount <> 0" . ($pw ? " AND $pw" : "");
        array_push($params, ...$pp);

        foreach ([
            ['takhlis', 'takhlis', 't', 'cert_date', 'expenses'],
            ['shamil', 'shamil', 'sh', 'invoice_date', 'expense'],
            ['manual', 'manual_expenses', 'me', 'exp_date', 'amount'],
            ['debt', 'debt_payments', 'dp', 'pay_date', 'amount'],
        ] as [$source, $table, $alias, $dateColumn, $amountColumn]) {
            $sp = []; $sw = self::dateCond("$alias.$dateColumn", $from, $to, $sp);
            $parts[] = "SELECT " . Database::conn()->quote($source) . ", '', COUNT(*),
                               COALESCE(SUM($alias.$amountColumn),0)
                        FROM $table $alias" . ($sw ? " WHERE $sw" : '');
            array_push($params, ...$sp);
        }

        $categories = [];
        $totals = ['takhlis'=>0.0, 'shamil'=>0.0, 'permits'=>0.0, 'manual'=>0.0, 'debt'=>0.0];
        foreach (Database::all(implode("\nUNION ALL\n", $parts), $params) as $row) {
            if ($row['source'] === 'category') {
                $categories[] = [
                    'label' => $row['label'], 'cnt' => (int)$row['cnt'], 'value' => (float)$row['value'],
                ];
            } elseif (array_key_exists($row['source'], $totals)) {
                $totals[$row['source']] = (float)$row['value'];
            }
        }
        usort($categories, static fn(array $a, array $b): int => $b['value'] <=> $a['value']);

        return [
            'categories' => $categories,
            'takhlis'    => $totals['takhlis'],
            'shamil'     => $totals['shamil'],
            'permits'    => round($totals['permits'], 2),
            'manual'     => $totals['manual'],
            'debt'       => $totals['debt'],
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
        $parts = []; $params = [];
        foreach ([['bawalis','bill_date'],['shamil','invoice_date'],
                  ['takhlis','cert_date'],['generators','gen_date'],['permits','permit_date']] as [$tbl,$col]) {
            $extra = $tbl === 'bawalis' ? " AND is_legacy = 0" : "";
            $parts[] = "SELECT `$col` d, COUNT(*) c FROM `$tbl`
                        WHERE `$col` BETWEEN ? AND ?$extra GROUP BY `$col`";
            array_push($params, $from, $anchor);
        }
        foreach (Database::all(implode("\nUNION ALL\n", $parts), $params) as $r) {
            if (isset($map[$r['d']])) $map[$r['d']] += (int)$r['c'];
        }
        return ['labels' => $labels, 'data' => array_values($map)];
    }

    /**
     * ⚠️ **أذونات اتحرّك عليها فلوس في يوم واحد — معتمد ومستنّي مع بعض.**
     *
     * طلب المالك بالحرف: «محتاج أفهم اليوم ده الأذونات اللي اتضاف لها إيصالات
     * أو مصروفات، من النظام أو المندوب، **حتى لو لم يتم اعتمادها**».
     *
     * عشان كده الاستعلام **اتحادان**:
     *   ① `permit_stages` — اللي اتسجّل فعلاً (مكتب أو مندوب معتمَد).
     *   ② `rep_submissions` بحالة `pending` — لسه في صندوق المراجعة.
     * البند المعتمَد بيختفي من ② ويظهر في ① تلقائيًّا، فمفيش عدّ مزدوج.
     *
     * ⚠️ **التاريخ `entry_date` مش `created_at`** — البند بيتحسب على **يوم
     *    صرفه** لا يوم تسجيله (قاعدة v29)، والمندوب بيسجّل بند إمبارح النهاردة.
     *
     * ⚠️ **مفيش أي تراكمي** (قرار المالك): كل رقم هنا عن اليوم ده وبس.
     *
     * @return array<int,array> صف لكل إذن، مرتّب بالأكتر حركة.
     */
    public static function permitsTouched(string $day): array
    {
        if ($day === '') { return []; }

        $repsReady = Database::scalar(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'rep_submissions'"
        );

        /* الجزء ①: البنود المسجَّلة — **خانتان متنافيتان**:
             • الإيصال = `stage_key='receipts'`.
             • المصروف = `Permit::expenseSql()` **ناقص الإيصالات** (رسوم أولية +
               مصروفات إضافية اتجاهها مصروف).

           ⚠️ **الإصلاح (2026-08-17):** الشرط كان `direction='expense'` لوحده،
              والإيصال المسجَّل من فورم المكتب بيتحفظ بـ`direction='expense'`
              كمان — فكان **بيتعدّ إيصالًا ومصروفًا في نفس السطر ومبلغه بيتجمع
              مرّتين** في مجموع الكارت (متحقَّق على الإنتاج: ٢٤ بند، وإذن من
              ١٥ في يوم واحد). والعكس كان بيحصل مع الرسوم الأولية:
              `direction` بتاعتها NULL فما كانتش بتتعدّ مصروفًا خالص.
              `verify_dashboard` بيزرع الحالتين ويفشل لو رجعت. */
        $exOnly = Permit::expenseSql('s') . " AND s.stage_key <> 'receipts'";
        $parts = ["
            SELECT s.permit_id,
                   SUM(CASE WHEN s.stage_key = 'receipts' THEN 1 ELSE 0 END)        AS rc_cnt,
                   SUM(CASE WHEN s.stage_key = 'receipts' THEN s.amount ELSE 0 END) AS rc_val,
                   SUM(CASE WHEN $exOnly THEN 1 ELSE 0 END)                         AS ex_cnt,
                   SUM(CASE WHEN $exOnly THEN s.amount ELSE 0 END)                  AS ex_val,
                   0 AS pend_cnt, 0 AS pend_val,
                   SUM(CASE WHEN s.paid_by_rep_id IS NOT NULL THEN 1 ELSE 0 END)    AS by_rep
              FROM permit_stages s
             WHERE s.entry_date = ? AND s.permit_id IS NOT NULL
             GROUP BY s.permit_id"];
        $params = [$day];

        if ((int) $repsReady > 0) {
            $parts[] = "
            SELECT b.permit_id, 0, 0, 0, 0,
                   COUNT(*) AS pend_cnt, COALESCE(SUM(b.amount), 0) AS pend_val, 0
              FROM rep_submissions b
             WHERE b.entry_date = ? AND b.status = 'pending' AND b.permit_id IS NOT NULL
             GROUP BY b.permit_id";
            $params[] = $day;
        }

        $union = implode(' UNION ALL ', $parts);
        $rows = Database::all(
            "SELECT p.id, p.permit_no, p.permit_type, p.company, p.client, p.status,
                    SUM(t.rc_cnt) rc_cnt, SUM(t.rc_val) rc_val,
                    SUM(t.ex_cnt) ex_cnt, SUM(t.ex_val) ex_val,
                    SUM(t.pend_cnt) pend_cnt, SUM(t.pend_val) pend_val,
                    SUM(t.by_rep) by_rep
               FROM ($union) t
               JOIN permits p ON p.id = t.permit_id
              GROUP BY p.id, p.permit_no, p.permit_type, p.company, p.client, p.status
              ORDER BY (SUM(t.rc_cnt) + SUM(t.ex_cnt) + SUM(t.pend_cnt)) DESC, p.id DESC",
            $params
        );

        foreach ($rows as &$r) {
            $r['id']       = (int) $r['id'];
            $r['rc_cnt']   = (int) $r['rc_cnt'];
            $r['ex_cnt']   = (int) $r['ex_cnt'];
            $r['pend_cnt'] = (int) $r['pend_cnt'];
            $r['by_rep']   = (int) $r['by_rep'];
            $r['rc_val']   = (float) $r['rc_val'];
            $r['ex_val']   = (float) $r['ex_val'];
            $r['pend_val'] = (float) $r['pend_val'];
            $r['items']    = $r['rc_cnt'] + $r['ex_cnt'] + $r['pend_cnt'];
        }
        unset($r);
        return $rows;
    }

    /**
     * ⚠️ **الأذونات اللي اتفتحت في يوم العمل ده.**
     *
     * المصدر `audit_logs.work_date` **مش `permits.created_at`** — لسببين
     * متحقَّقين في البيانات:
     *   ① `created_at` **طابع زمني بتوقيت السيرفر (UTC)** والنظام بيشتغل على
     *      `Africa/Cairo` بفرق ٣ ساعات (قاعدة v48)، فأي إذن اتفتح بعد ٩ مساءً
     *      بيتحسب على اليوم اللي بعده.
     *   ② المالك ممكن يفتح إذنًا وهو شغّال على **يوم عمل قديم** — و`work_date`
     *      هي اللي بتحترم ده (قاعدة v29). متحقَّق: فيه أذونات `work_date=18/07`
     *      و`created_at=19/07`.
     */
    public static function permitsOpened(string $day): array
    {
        if ($day === '') { return []; }
        return Database::all(
            /* ⚠️ `MAX(a.id)` مش `a.id` في الترتيب — `only_full_group_by` مفعّلة
               وبترفض عمود غير مجمَّع في ORDER BY (اتصادف فعليًا). والتجميع
               لازم لأن الإذن ممكن يكون له أكتر من سطر تسجيل. */
            "SELECT p.id, p.permit_no, p.permit_type, p.company, p.client, p.status,
                    p.permit_date, MAX(a.user_name) AS user_name, MAX(a.id) AS log_id
               FROM audit_logs a
               JOIN permits p ON p.id = a.record_id
              WHERE a.entity = 'permits' AND a.action = 'create' AND a.work_date = ?
              GROUP BY p.id, p.permit_no, p.permit_type, p.company, p.client, p.status, p.permit_date
              ORDER BY log_id DESC",
            [$day]
        );
    }

    // ═══════════════════════════════ نظرة عامة على يوم الأذونات (v99)

    /**
     * ⚠️ **بنود اليوم على الأذونات — سطر لكل بند، المعتمَد والمستنّي مع بعض.**
     *
     * طلب المالك: «عايز أشوف كل إذن جاله إيصالات إيه النهاردة، حتى لو لسه
     * ما اتعمدتش». `permitsTouched()` بتجاوب على ده **مجمَّعًا** (كام بند وبكام)
     * — والدالة دي بتجاوب عليه **بندًا بندًا**: مين دخّله، إمتى، بكام، وناقصه إيه.
     *
     * الاتحاد نفس اتحاد `permitsTouched()` بالظبط:
     *   ① `permit_stages` — اللي اتسجّل فعلاً (مكتب أو بند مندوب معتمَد).
     *   ② `rep_submissions` بحالة `pending` — لسه في صندوق المراجعة.
     * البند المعتمَد بيختفي من ② ويظهر في ① تلقائيًّا، فمفيش عدّ مزدوج.
     *
     * ⚠️ **التاريخ `entry_date` مش `created_at`** — البند بيتحسب على **يوم صرفه**
     *    لا يوم تسجيله (قاعدة v29)؛ المندوب بيسجّل بند إمبارح النهاردة.
     * ⚠️ **`status='none'` بيتشال**: دي علامة «الإيصال ده مش محتاجه في الإذن»
     *    (v32) — مش بند اتصرف، ونفس الاستثناء في `receiptsMissingData()`.
     * ⚠️ **«طلّع كاش؟» من `Permit::cashOutSql()`** — ممنوع يتكتب بالإيد. وبتشتغل
     *    على `rep_submissions` كمان لأن العمودين (`is_visa`/`unpaid`) هناك بنفس
     *    المعنى بالحرف، و`RepInbox::approve()` بتنقلهم للبند المعتمَد زي ما هُمّ.
     * ⚠️ **`actual_unit` سعر الوحدة** في بنود المناديب، و`actual_amount` **إجمالي**
     *    في البند المعتمَد — فبنضرب في الكمية هنا زي `RepInbox::approve()` بالظبط،
     *    وإلا بند كميته ٣ يبان ناقصًا للتلت.
     */
    public static function permitDayItems(string $day): array
    {
        if ($day === '') { return []; }

        $repsReady = (int) Database::scalar(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'rep_submissions'"
        );

        $parts = ["
            SELECT 'stage' AS src, s.id, s.permit_id, s.movement_id, s.stage_key, s.direction,
                   s.note, s.qty, s.amount, s.actual_amount, s.ref_no,
                   s.is_visa, (" . Permit::cashOutSql('s') . ") AS is_cash,
                   s.attachment AS photo, s.status, s.entry_date, s.created_at,
                   s.paid_by_rep_id AS rep_id
              FROM permit_stages s
             WHERE s.entry_date = ? AND s.permit_id IS NOT NULL AND s.status <> 'none'"];
        $params = [$day];

        if ($repsReady > 0) {
            $parts[] = "
            SELECT 'pending', b.id, b.permit_id, b.movement_id,
                   CASE WHEN b.kind = 'expense' THEN 'custom' ELSE 'receipts' END,
                   CASE WHEN b.kind = 'expense' THEN 'expense' ELSE NULL END,
                   b.note, b.qty, b.amount,
                   CASE WHEN b.actual_unit IS NULL THEN NULL
                        ELSE ROUND(b.actual_unit * b.qty, 2) END,
                   b.ref_no,
                   b.is_visa, (" . Permit::cashOutSql('b') . "),
                   b.photo, b.status, b.entry_date, b.created_at, b.rep_id
              FROM rep_submissions b
             WHERE b.entry_date = ? AND b.status = 'pending' AND b.permit_id IS NOT NULL";
            $params[] = $day;
        }

        $rows = Database::all(
            "SELECT t.*, p.permit_no, p.permit_type, p.company, p.client, p.permit_date,
                    p.status AS permit_status, m.seq AS movement_seq, r.name AS rep_name
               FROM (" . implode(' UNION ALL ', $parts) . ") t
               JOIN permits p ON p.id = t.permit_id
               LEFT JOIN permit_movements m ON m.id = t.movement_id
               LEFT JOIN reps r ON r.id = t.rep_id
              ORDER BY t.created_at DESC, t.id DESC",
            $params
        );

        foreach ($rows as &$r) {
            $r['id']        = (int) $r['id'];
            $r['permit_id'] = (int) $r['permit_id'];
            $r['qty']       = (int) $r['qty'];
            $r['rep_id']    = $r['rep_id'] !== null ? (int) $r['rep_id'] : 0;
            $r['amount']    = (float) $r['amount'];
            $r['actual_amount'] = $r['actual_amount'] !== null ? (float) $r['actual_amount'] : null;
            $r['is_visa']   = (int) $r['is_visa'] === 1;
            $r['is_cash']   = (int) $r['is_cash'] === 1;
            $r['pending']   = $r['src'] === 'pending';
            $r['by_rep']    = $r['rep_id'] > 0;
            $r['has_photo'] = trim((string) ($r['photo'] ?? '')) !== '';

            /* تصنيف البند من **تعريف المراحل نفسه** (`Permit::STAGES`) مش من
               اسم مكتوب بالإيد: إيصال · رسوم أولية · محصَّل (إيراد) · مصروف. */
            $key = (string) $r['stage_key'];
            $r['kind'] = $key === 'receipts' ? 'receipt'
                : ($key === 'primary_fees' ? 'fees'
                : (((string) ($r['direction'] ?? '')) === Permit::DIR_REVENUE ? 'revenue' : 'expense'));

            /* «ناقص بياناته» = **نفس تعريف `Permit::receiptsMissingData()`**:
               الفعلي للعميل أو رقم الإيصال ناقص. والبند المستنّي مستثنى — المالك
               بيكمّل بياناته وقت الاعتماد أصلاً، فوسمه «ناقص» إنذار كاذب. */
            $r['missing'] = $r['kind'] === 'receipt' && !$r['pending']
                && ($r['actual_amount'] === null || trim((string) ($r['ref_no'] ?? '')) === '');
        }
        unset($r);
        return $rows;
    }

    /** فلاتر نافذة النظرة العامة — **المصدر الوحيد** لأسماء الفلاتر ومعناها. */
    public const DAY_FILTERS = [
        'all'      => 'الكل',
        'reps'     => 'من المناديب',
        'pending'  => 'مستنّي اعتمادك',
        'office'   => 'من المكتب',
        'receipts' => 'إيصالات',
        'expense'  => 'مصروفات',
        'missing'  => 'ناقص بياناته',
        'visa'     => 'بالفيزا',
    ];

    /** هل البند ده داخل الفلتر المطلوب؟ — شرط واحد بيخدم العدّ والعرض معًا. */
    private static function dayItemMatches(array $item, string $filter): bool
    {
        return match ($filter) {
            'reps'     => (bool) $item['by_rep'],
            'pending'  => (bool) $item['pending'],
            'office'   => !$item['by_rep'],
            'receipts' => $item['kind'] === 'receipt',
            'expense'  => in_array($item['kind'], ['expense', 'fees'], true),
            'missing'  => (bool) $item['missing'],
            'visa'     => (bool) $item['is_visa'],
            default    => true,
        };
    }

    /**
     * نظرة عامة على يوم الأذونات: الأرقام + البنود مجمّعة بالإذن + المناديب.
     *
     * ⚠️ **الأرقام بتتحسب على اليوم كله دايمًا**، والفلتر بيصفّي **العرض** بس —
     *    وإلا الرقم اللي المالك دوس عليه بيتغيّر تحت إيده فيفتكره غلط.
     *
     * @param string $filter مفتاح من `DAY_FILTERS`
     * @param int    $repId  >0 = بنود المندوب ده بس (شريحة المندوب في الشريط)
     */
    public static function permitDayOverview(string $day, string $filter = 'all', int $repId = 0): array
    {
        $filter = isset(self::DAY_FILTERS[$filter]) ? $filter : 'all';
        $items  = self::permitDayItems($day);

        $stats = ['items' => 0, 'permits' => 0, 'out_val' => 0.0, 'cash_val' => 0.0,
                  'receipt_cnt' => 0, 'receipt_val' => 0.0, 'reps_cnt' => 0,
                  'rep_cnt' => 0, 'rep_val' => 0.0, 'pending_cnt' => 0, 'pending_val' => 0.0,
                  'office_cnt' => 0, 'office_val' => 0.0, 'expense_cnt' => 0, 'expense_val' => 0.0,
                  'revenue_cnt' => 0, 'revenue_val' => 0.0, 'missing_cnt' => 0,
                  'visa_cnt' => 0, 'visa_val' => 0.0, 'photo_cnt' => 0, 'client_val' => 0.0];

        $permits = [];
        $reps    = [];

        foreach ($items as $it) {
            $amount = (float) $it['amount'];
            $stats['items']++;
            if ($it['kind'] === 'revenue') {
                $stats['revenue_cnt']++; $stats['revenue_val'] += $amount;
            } else {
                $stats['out_val'] += $amount;
                if ($it['is_cash']) { $stats['cash_val'] += $amount; }
            }
            if ($it['kind'] === 'receipt') { $stats['receipt_cnt']++; $stats['receipt_val'] += $amount; }
            if (in_array($it['kind'], ['expense', 'fees'], true)) {
                $stats['expense_cnt']++; $stats['expense_val'] += $amount;
            }
            if ($it['by_rep'])  { $stats['rep_cnt']++;    $stats['rep_val'] += $amount; }
            else                { $stats['office_cnt']++; $stats['office_val'] += $amount; }
            if ($it['pending']) { $stats['pending_cnt']++; $stats['pending_val'] += $amount; }
            if ($it['missing']) { $stats['missing_cnt']++; }
            if ($it['is_visa']) { $stats['visa_cnt']++; $stats['visa_val'] += $amount; }
            if ($it['has_photo']) { $stats['photo_cnt']++; }
            if ($it['actual_amount'] !== null) { $stats['client_val'] += (float) $it['actual_amount']; }

            if ($it['by_rep']) {
                $rid = (int) $it['rep_id'];
                $reps[$rid] ??= ['rep_id' => $rid, 'name' => (string) ($it['rep_name'] ?: 'مندوب #' . $rid),
                                 'cnt' => 0, 'val' => 0.0, 'pending_cnt' => 0, 'permits' => []];
                $reps[$rid]['cnt']++;
                $reps[$rid]['val'] += $amount;
                if ($it['pending']) { $reps[$rid]['pending_cnt']++; }
                $reps[$rid]['permits'][(int) $it['permit_id']] = true;
            }

            if (!self::dayItemMatches($it, $filter)) { continue; }
            if ($repId > 0 && (int) $it['rep_id'] !== $repId) { continue; }

            $pid = (int) $it['permit_id'];
            $permits[$pid] ??= [
                'id' => $pid, 'permit_no' => $it['permit_no'], 'permit_type' => $it['permit_type'],
                'company' => $it['company'], 'client' => $it['client'],
                'permit_status' => $it['permit_status'], 'permit_date' => $it['permit_date'],
                'cnt' => 0, 'val' => 0.0, 'receipt_cnt' => 0, 'pending_cnt' => 0,
                'rep_cnt' => 0, 'missing_cnt' => 0, 'reps' => [], 'items' => [],
            ];
            $permits[$pid]['cnt']++;
            $permits[$pid]['val'] += $amount;
            if ($it['kind'] === 'receipt') { $permits[$pid]['receipt_cnt']++; }
            if ($it['pending']) { $permits[$pid]['pending_cnt']++; }
            if ($it['missing']) { $permits[$pid]['missing_cnt']++; }
            if ($it['by_rep']) {
                $permits[$pid]['rep_cnt']++;
                $permits[$pid]['reps'][(string) ($it['rep_name'] ?: '—')] = true;
            }
            $permits[$pid]['items'][] = $it;
        }

        $stats['permits']  = count(array_unique(array_map(static fn ($i) => (int) $i['permit_id'], $items)));
        $stats['reps_cnt'] = count($reps);

        /* بيانات الإذن اللي بتميّزه بعين المالك: **الحاويات بتصنيفها** والتوكيل
           والميناء والجهة. طلب المالك: «جنب رقم الإذن عاوز عدد الحاويات الأصلية
           للإذن × تصنيفها عشان أعرف أميّزها + التوكيل + الميناء».

           ⚠️ استعلامان لكل الأذونات المعروضة مع بعض (مش واحد لكل إذن)، والتصنيف
              من `Permit::containerBreakdown()` + `Permit::containerLabel()` —
              **نفس** اللي بترسمه كروت شاشة الأذونات، فمستحيل الرقمان يفترقوا. */
        if ($permits) {
            $ids  = array_keys($permits);
            $in   = implode(',', array_map('intval', $ids));
            $meta = [];
            foreach (Database::all(
                "SELECT id, containers, container_type, agency, port, destination
                   FROM permits WHERE id IN ($in)") as $m) {
                $meta[(int) $m['id']] = $m;
            }
            $ctMap = Permit::containerBreakdown('permit_id', $ids);
            foreach ($permits as $pid2 => &$pr) {
                $m = $meta[$pid2] ?? [];
                $pr['containers']  = (int) ($m['containers'] ?? 0);
                $pr['agency']      = (string) ($m['agency'] ?? '');
                $pr['port']        = (string) ($m['port'] ?? '');
                $pr['destination'] = (string) ($m['destination'] ?? '');
                $pr['containers_label'] = Permit::containerLabel(
                    $ctMap[$pid2] ?? [], (int) ($m['containers'] ?? 0), $m['container_type'] ?? null);
            }
            unset($pr);
        }

        foreach ($permits as &$p) { $p['reps'] = array_keys($p['reps']); }
        unset($p);
        foreach ($reps as &$rp) { $rp['permits'] = count($rp['permits']); }
        unset($rp);

        /* الترتيب: الأكتر حركة الأول — الإذن اللي جاله ٦ إيصالات أهم من اللي جاله واحد. */
        $permits = array_values($permits);
        usort($permits, static fn ($a, $b) => [$b['cnt'], $b['val']] <=> [$a['cnt'], $a['val']]);
        $reps = array_values($reps);
        usort($reps, static fn ($a, $b) => [$b['cnt'], $b['val']] <=> [$a['cnt'], $a['val']]);

        return ['day' => $day, 'filter' => $filter, 'rep_id' => $repId, 'stats' => $stats,
                'permits' => $permits, 'reps' => $reps, 'shown' => count($permits)];
    }
}
