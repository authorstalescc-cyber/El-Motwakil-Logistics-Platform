<?php
namespace App\Models;

use App\Core\Database;

/**
 * **حسابات العملاء (v93)** — عدسة واحدة على كل بيانات العميل في النظام.
 *
 * ═══ اللزمة (طلب المالك 2026-08-12) ═══
 * «محتاج نفصل العملاء من دليل الأطراف ونعملها منفصلة اسمها حسابات العملاء
 *  بشكل احترافي وقوي، يتم عرض كافة تفاصيل العميل ويمكنني إضافة دفعة سداد
 *  والفواتير السابقة وما إلى ذلك. البروفايل الموجود غير احترافي بالمرة.»
 *
 * البروفايل القديم كان **صفحة واحدة طويلة** جوّه «دليل الأطراف»: بتعرض
 * رقمين مجمّعين بلا أي تفاصيل ورا الرقم، وبلا **أعمار ديون** (أهم رقم في أي
 * حساب عميل: الفاتورة دي متأخرة من إمتى)، وبلا **كشف حساب زمني** برصيد
 * تراكمي — يعني مستحيل تجاوب على «الرصيد ده اتكوّن إزاي؟».
 *
 * ═══ ⚠️⚠️ الوحدة دي **قراءة بحتة** — معزولة بالبناء ═══
 * ولا `INSERT` ولا `UPDATE` ولا `DELETE` ولا جدول جديد. الكتابة الوحيدة في
 * الوحدة كلها هي **تسجيل/عكس التحصيل** وهي في `ClientPayment` من زمان بلا
 * أي تغيير. وكل رقم هنا بيتجاب من **الموديل صاحبه**
 * (`PermitInvoice::summaryFor` · `ClientPayment::totalFor` · `Party::summary`)
 * لا باستعلام موازٍ — تعريفان بيختلفوا = «الكارت يقول رقم والكشف يقول تاني».
 * `verify_clients` بيفشل لو أي كلمة كتابة دخلت الملف ده.
 *
 * ⚠️ **اتجاه المال هنا معكوس عن باقي النظام** (تحذير ① في CLAUDE.md): الشركة
 *    مدينة لأصحاب البيان، **لكن العميل (الوكيل) مدين للشركة** — بيستلم خدمة
 *    وبيتفوتر عليها. فـ«المتبقّي» هنا = **مطلوب من العميل** لا علينا.
 */
final class ClientAccount
{
    /**
     * تبويبات البروفايل — **روابط حقيقية** (`?tab=`) لا تبديل بجافاسكريبت،
     * فالرجوع بالمتصفح والمشاركة والفتح في تبويب جديد كلهم شغّالين.
     */
    public const TABS = [
        'ledger'   => ['label' => 'كشف الحساب', 'icon' => 'layers'],
        'invoices' => ['label' => 'الفواتير',   'icon' => 'receipt'],
        'payments' => ['label' => 'التحصيلات',  'icon' => 'banknote'],
        'work'     => ['label' => 'الشغل',      'icon' => 'folder'],
        'card'     => ['label' => 'البيانات',   'icon' => 'user'],
    ];

    /** أعمار الدين — **المصدر الوحيد** لحدود الشرايح (الشاشة والنافذة والكشف). */
    public const AGING = [
        'current' => ['label' => 'لسه مستحقة',  'from' => null, 'to' => 0,    'tone' => 'teal'],
        'd30'     => ['label' => 'متأخرة ≤٣٠',  'from' => 1,    'to' => 30,   'tone' => 'gold'],
        'd60'     => ['label' => 'متأخرة ٣١-٦٠','from' => 31,   'to' => 60,   'tone' => 'purple'],
        'd90'     => ['label' => 'متأخرة +٦٠',  'from' => 61,   'to' => null, 'tone' => 'red'],
    ];

    /** أي قيمة مجهولة بتقع على أول تبويب — الرابط المزوَّر ما يوقعش الشاشة. */
    public static function tab(?string $raw): string
    {
        $t = trim((string) $raw);
        return isset(self::TABS[$t]) ? $t : 'ledger';
    }

    /** ترتيب القائمة — قيمة مجهولة بتقع على «الأكتر مديونية». */
    public const SORTS = [
        'due'   => 'الأعلى مديونية',
        'late'  => 'الأكثر تأخيرًا',
        'name'  => 'الاسم',
        'recent'=> 'آخر حركة',
    ];

    public static function sort(?string $raw): string
    {
        $s = trim((string) $raw);
        return isset(self::SORTS[$s]) ? $s : 'due';
    }

    // ═══════════════════════════════════════════════ القائمة

    /**
     * قائمة حسابات العملاء + أرقام كل عميل في **٤ استعلامات مجمّعة** لا
     * استعلام لكل عميل (٦٠ عميل × ٤ = ٢٤٠ استعلام كانت هتخلّي الشاشة تزحف).
     *
     * @return array<int,array> صف لكل عميل: بياناته + charges/paid/remaining/overdue/…
     */
    public static function list(string $q = '', string $sort = 'due'): array
    {
        PermitInvoice::refreshAllStatuses();

        $rows     = Party::agents($q);
        $charges  = PermitInvoice::totalsByClient();
        $paid     = ClientPayment::totalsByClient();
        $due      = self::dueByAgent();
        $activity = self::activityByAgent();

        $out = [];
        foreach ($rows as $r) {
            $id   = (int) $r['id'];
            $name = (string) $r['name'];
            $c    = (float) ($charges[$name] ?? 0);
            $p    = (float) ($paid[$name] ?? 0);
            $d    = $due[$id] ?? ['open' => 0, 'balance' => 0.0, 'overdue' => 0.0, 'oldest' => null];

            $out[] = $r + [
                'charges'      => round($c, 2),
                'paid'         => round($p, 2),
                'remaining'    => round($c - $p, 2),
                'open_cnt'     => (int) $d['open'],
                'open_balance' => round((float) $d['balance'], 2),
                'overdue'      => round((float) $d['overdue'], 2),
                'oldest_due'   => $d['oldest'],
                'late_days'    => self::lateDays($d['oldest']),
                'last_move'    => $activity[$id] ?? null,
            ];
        }

        usort($out, static fn (array $a, array $b) => match ($sort) {
            'name'   => strcmp((string) $a['name'], (string) $b['name']),
            'late'   => ($b['late_days'] <=> $a['late_days']) ?: ($b['overdue'] <=> $a['overdue']),
            'recent' => strcmp((string) ($b['last_move'] ?? ''), (string) ($a['last_move'] ?? '')),
            default  => ($b['remaining'] <=> $a['remaining']),
        });

        return $out;
    }

    /** إجماليات شريط القائمة — **محسوبة من نفس الصفوف المعروضة** فمستحيل تخالفها. */
    public static function listTotals(array $rows): array
    {
        $t = ['clients' => count($rows), 'charges' => 0.0, 'paid' => 0.0,
              'remaining' => 0.0, 'overdue' => 0.0, 'late_clients' => 0, 'open_cnt' => 0];
        foreach ($rows as $r) {
            $t['charges']   += (float) $r['charges'];
            $t['paid']      += (float) $r['paid'];
            $t['remaining'] += (float) $r['remaining'];
            $t['overdue']   += (float) $r['overdue'];
            $t['open_cnt']  += (int) $r['open_cnt'];
            if ((float) $r['overdue'] > 0.005) { $t['late_clients']++; }
        }
        foreach (['charges', 'paid', 'remaining', 'overdue'] as $k) { $t[$k] = round($t[$k], 2); }
        return $t;
    }

    /** فواتير مفتوحة + متأخر + أقدم استحقاق — لكل عميل في استعلام واحد. */
    private static function dueByAgent(): array
    {
        if (!PermitInvoice::hasTable()) { return []; }
        $out = [];
        foreach (Database::all(
            "SELECT agent_id,
                    COUNT(*) open_cnt,
                    COALESCE(SUM(balance_due),0) balance,
                    COALESCE(SUM(CASE WHEN due_date IS NOT NULL AND due_date < CURDATE()
                                      THEN balance_due ELSE 0 END),0) overdue,
                    MIN(CASE WHEN due_date IS NOT NULL AND due_date < CURDATE()
                             THEN due_date END) oldest_due
               FROM permit_invoices
              WHERE agent_id IS NOT NULL AND status IN ('issued','partially_paid') AND balance_due > 0.005
              GROUP BY agent_id") as $r) {
            $out[(int) $r['agent_id']] = [
                'open'    => (int) $r['open_cnt'],
                'balance' => (float) $r['balance'],
                'overdue' => (float) $r['overdue'],
                'oldest'  => $r['oldest_due'],
            ];
        }
        return $out;
    }

    /** آخر حركة (فاتورة أو تحصيل) لكل عميل. */
    private static function activityByAgent(): array
    {
        $out = [];
        if (PermitInvoice::hasTable()) {
            foreach (Database::all(
                "SELECT agent_id, MAX(invoice_date) d FROM permit_invoices
                  WHERE agent_id IS NOT NULL GROUP BY agent_id") as $r) {
                $out[(int) $r['agent_id']] = (string) $r['d'];
            }
        }
        foreach (Database::all(
            "SELECT agent_id, MAX(pay_date) d FROM client_payments
              WHERE agent_id IS NOT NULL AND status='posted' GROUP BY agent_id") as $r) {
            $id = (int) $r['agent_id'];
            $d  = (string) $r['d'];
            if (!isset($out[$id]) || $d > $out[$id]) { $out[$id] = $d; }
        }
        return $out;
    }

    /**
     * كام يوم فات على أقدم استحقاق متأخر؟
     *
     * ⚠️ المقارنة بتحصل على **تاريخ** (بلا وقت) فمفيش مصيدة فرق التوقيت
     *    بين القاعدة (UTC) وPHP (القاهرة) — التاريخ نفسه بيتجاب من SQL
     *    بـ`CURDATE()` والفرق هنا حسابي بحت.
     */
    public static function lateDays(?string $dueDate): int
    {
        if (!$dueDate) { return 0; }
        $days = (int) floor((strtotime(work_date()) - strtotime($dueDate)) / 86400);
        return max(0, $days);
    }

    // ═══════════════════════════════════════════════ أرقام الترويسة

    /**
     * أرقام البروفايل الخمسة — كل واحد بيفتح بنوده (قاعدة «ممنوع رقم أصمّ»).
     *
     * @return array{charges:float,paid:float,remaining:float,overdue:float,open_cnt:int,
     *               open_balance:float,oldest_due:?string,late_days:int,permits:array}
     */
    public static function head(int $agentId, string $name): array
    {
        $invSum  = PermitInvoice::summaryFor('client', $name);
        $shamil  = Party::summary('client', $name);
        $charges = (float) ($invSum['total'] ?? 0) + (float) ($shamil['invoices']['total'] ?? 0);
        $paid    = ClientPayment::totalFor($name);
        $due     = self::dueByAgent()[$agentId] ?? ['open' => 0, 'balance' => 0.0, 'overdue' => 0.0, 'oldest' => null];

        return [
            'charges'      => round($charges, 2),
            'paid'         => round($paid, 2),
            'remaining'    => round($charges - $paid, 2),
            'overdue'      => round((float) $due['overdue'], 2),
            'open_cnt'     => (int) $due['open'],
            'open_balance' => round((float) $due['balance'], 2),
            'oldest_due'   => $due['oldest'],
            'late_days'    => self::lateDays($due['oldest']),
            'permits'      => $shamil['permits'],
            'inv_cnt'      => (int) ($invSum['cnt'] ?? 0),
        ];
    }

    /**
     * أعمار الدين — **الفواتير المفتوحة موزّعة على شرايح التأخير**.
     * الشريحة بتحمل مبلغها وعدد فواتيرها، والمجموع = `head['open_balance']` بالحرف.
     */
    public static function aging(int $agentId): array
    {
        $out = [];
        foreach (self::AGING as $k => $meta) { $out[$k] = $meta + ['value' => 0.0, 'count' => 0]; }
        foreach (self::openInvoices($agentId) as $iv) {
            $late = self::lateDays($iv['due_date'] ?? null);
            $key  = $late <= 0 ? 'current' : ($late <= 30 ? 'd30' : ($late <= 60 ? 'd60' : 'd90'));
            $out[$key]['value'] += (float) $iv['balance_due'];
            $out[$key]['count']++;
        }
        foreach ($out as $k => $b) { $out[$k]['value'] = round($b['value'], 2); }
        return $out;
    }

    /** الفواتير المفتوحة (لها باقي) مرتّبة بالأقدم استحقاقًا — مصدر الأعمار والتخصيص. */
    public static function openInvoices(int $agentId): array
    {
        return $agentId > 0 ? PermitInvoice::openForAgent($agentId) : [];
    }

    // ═══════════════════════════════════════════════ كشف الحساب الزمني

    /**
     * **كشف الحساب** — سطر لكل حركة مرتّب زمنيًّا برصيد تراكمي.
     *
     * الفاتورة **مدين** (بتزوّد المطلوب من العميل)، والتحصيل **دائن**
     * (بيقلّله)، والرصيد بعد كل سطر = مجموع اللي قبله. ده اللي بيجاوب على
     * «الرصيد ده اتكوّن إزاي» — والصفحة القديمة كانت بتعرض المجموع وبس.
     *
     * ⚠️ المستند الملغي (`void`) والتحصيل المعكوس بيظهروا **بصفر** لا
     *    بيختفوا: اختفاؤهم بيخلّي المالك يدوّر على مستند شافه ومش لاقيه.
     *
     * @return array{rows:array,opening:float,debit:float,credit:float,balance:float}
     */
    public static function ledger(int $agentId, string $name, string $from = '', string $to = ''): array
    {
        $rows = [];

        foreach (PermitInvoice::forName('client', $name) as $iv) {
            $void = ($iv['status'] ?? '') === 'void';
            $rows[] = [
                'date'   => (string) ($iv['invoice_date'] ?? ''),
                'kind'   => 'invoice',
                'ref'    => (string) ($iv['doc_no'] ?: '#' . $iv['id']),
                'label'  => PermitInvoice::kindLabel((string) $iv['doc_kind'])
                            . ($iv['permit_no'] ? ' — ' . $iv['permit_no'] : ''),
                'note'   => (string) ($iv['note'] ?? ''),
                'debit'  => $void ? 0.0 : (float) $iv['total'],
                'credit' => 0.0,
                'url'    => 'permits/invoice/' . (int) $iv['id'],
                'void'   => $void,
                'status' => (string) ($iv['status'] ?? ''),
                'due'    => $iv['due_date'] ?? null,
                'id'     => (int) $iv['id'],
            ];
        }

        foreach (ClientPayment::forClient($name) as $pm) {
            $rev = ($pm['status'] ?? '') === 'reversed';
            $rows[] = [
                'date'   => (string) ($pm['pay_date'] ?? ''),
                'kind'   => 'payment',
                'ref'    => '#' . (int) $pm['id'],
                'label'  => 'تحصيل — ' . ClientPayment::routeLabel((string) $pm['route']),
                'note'   => (string) ($pm['note'] ?? ''),
                'debit'  => 0.0,
                'credit' => $rev ? 0.0 : (float) $pm['amount'],
                'url'    => null,
                'void'   => $rev,
                'status' => (string) ($pm['status'] ?? ''),
                'due'    => null,
                'id'     => (int) $pm['id'],
            ];
        }

        /* الترتيب بالتاريخ ثم بالنوع (الفاتورة قبل تحصيلها في نفس اليوم) ثم
           بالرقم — ترتيب ثابت مهما اتكرر الاستدعاء، فالرصيد التراكمي ما بيرقصش. */
        usort($rows, static function (array $a, array $b): int {
            return [$a['date'], $a['kind'] === 'payment' ? 1 : 0, $a['id']]
               <=> [$b['date'], $b['kind'] === 'payment' ? 1 : 0, $b['id']];
        });

        /* فترة اختيارية: اللي قبل `from` بيتلمّ في «رصيد أول المدة» بدل ما
           يتشال — كشف بيبدأ من الصفر وهو مش صفر بيغلط المالك في المطالبة. */
        $opening = 0.0; $kept = []; $debit = 0.0; $credit = 0.0;
        foreach ($rows as $r) {
            $d = (string) $r['date'];
            if ($from !== '' && $d !== '' && $d < $from) { $opening += $r['debit'] - $r['credit']; continue; }
            if ($to !== '' && $d !== '' && $d > $to) { continue; }
            $kept[] = $r;
        }

        $bal = round($opening, 2);
        foreach ($kept as $i => $r) {
            $bal += $r['debit'] - $r['credit'];
            $kept[$i]['balance'] = round($bal, 2);
            $debit  += $r['debit'];
            $credit += $r['credit'];
        }

        return [
            'rows'    => $kept,
            'opening' => round($opening, 2),
            'debit'   => round($debit, 2),
            'credit'  => round($credit, 2),
            'balance' => round($bal, 2),
        ];
    }

    // ═══════════════════════════════════════════════ تبويبات

    /** الفواتير والمستندات الصادرة للعميل (لقطة كل واحدة كما صدرت). */
    public static function invoices(string $name): array
    {
        return PermitInvoice::forName('client', $name);
    }

    /** تحصيلات العميل — الأحدث أولاً. */
    public static function payments(string $name): array
    {
        return ClientPayment::forClient($name);
    }

    /** أذونات العميل + الشركات اللي بينوب عنها. */
    public static function work(int $agentId, string $name): array
    {
        $permits  = Party::permitsBy('client', $name);
        $invoiced = [];
        foreach (PermitInvoice::forName('client', $name) as $iv) {
            if (!empty($iv['permit_id']) && ($iv['status'] ?? '') !== 'void') {
                $invoiced[(int) $iv['permit_id']] = true;
            }
        }
        return [
            'permits'   => $permits,
            'invoiced'  => $invoiced,
            'companies' => Party::agentCompanies($agentId),
            'shamil'    => Party::invoicesBy('client', $name),
        ];
    }

    /** بيانات الكارت + المستندات + الأسماء البديلة. */
    public static function card(int $agentId): array
    {
        return [
            'docs'    => PartyDoc::forParty('agent', $agentId),
            'aliases' => PartyMaster::aliases('agent', $agentId),
        ];
    }

    /**
     * أذونات العميل **المفتوحة اللي لسه ما اتصدرلهاش مستند** — «فوتر دلوقتي».
     * الزرار السريع بيوديه على الإذن نفسه لأن الإصدار مكانه هناك (قرار v20).
     */
    public static function billable(string $name, int $limit = 8): array
    {
        $out = [];
        foreach (Party::permitsBy('client', $name) as $p) {
            if (($p['status'] ?? '') !== 'open') { continue; }
            if (PermitInvoice::hasActive((int) $p['id'])) { continue; }
            $out[] = $p;
            if (count($out) >= $limit) { break; }
        }
        return $out;
    }
}
