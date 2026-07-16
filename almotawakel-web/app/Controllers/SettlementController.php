<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Models\Bill;
use App\Models\Lookup;

/**
 * سداد البوالص — متمحور حول «صاحب البيان» (صاحب المديونية الذي نصرف له).
 *
 * المسار /settlements ثلاثة أوضاع:
 *   1) الدليل   (بلا بحث/فلترة)  : بطاقات أصحاب البيان بإجمالي مديونية كلٍّ منهم.
 *   2) نتائج بحث (q أو أي فلتر)  : البوالص المستهدفة نفسها في جدول قابل للتحديد والدفع الجماعي.
 *   3) صاحب البيان (owner=..)    : إحصاءات فورية + بحث/فلترة داخل بوالصه + دفع جماعي.
 * والمسار /settlements/{id} يظل صفحة سداد بوليصة واحدة.
 */
class SettlementController extends Controller
{
    /** الأعمدة التي يغطّيها البحث الحر داخل البوالص */
    private const SEARCH_COLS = [
        'bill_number', 'cert_number', 'permit_no', 'declaration_owner', 'company',
        'classification', 'destination', 'container_no', 'container_type',
        'car_no', 'driver', 'car_owner',
    ];

    /** أعمدة صف البوليصة المعروض في الجداول */
    private const BILL_COLS =
        'id, bill_type, bill_date, bill_number, cert_number, permit_no,
         declaration_owner, company, destination, classification, container_no,
         container_type, container_count, car_no, driver, freight, debt, paid, remaining, pay_status, is_legacy';

    public function index(): void
    {
        $this->requireAuth();
        // owner موجود → صفحة صاحب البيان (حتى لو فارغة = بدون صاحب بيان)
        if (isset($_GET['owner'])) {
            $this->ownerView((string)$_GET['owner']);
            return;
        }
        // شاشة البوالص بلا صاحب بيان (تعيين سريع)
        if (isset($_GET['unassigned'])) {
            $this->unassignedView();
            return;
        }
        $this->directoryView();
    }

    // -------------------------------- الدليل / نتائج البحث عن البوالص المستهدفة
    private function directoryView(): void
    {
        $f = $this->collectFilters();
        $dueOnly = ($_GET['owners'] ?? '') === 'due';

        // أي بحث أو فلترة → نعرض البوالص المستهدفة مباشرةً (لا بطاقات أصحاب البيان)
        if ($this->anyFilter($f)) {
            $this->searchResultsView($f);
            return;
        }

        // وضع الدليل: بطاقات أصحاب البيان
        $key = "COALESCE(NULLIF(TRIM(declaration_owner),''),'')";
        $owners = Database::all(
            "SELECT $key AS owner_key,
                    COUNT(*)                                AS bills,
                    SUM(pay_status IN ('unpaid','partial')) AS due_bills,
                    COALESCE(SUM(debt),0)                   AS debt,
                    COALESCE(SUM(paid),0)                   AS paid,
                    COALESCE(SUM(remaining),0)              AS remaining,
                    MAX(bill_date)                          AS last_date
             FROM bawalis
             GROUP BY owner_key
             " . ($dueOnly ? "HAVING owner_key<>'' AND SUM(remaining)>0.005" : '') . "
             ORDER BY (SUM(remaining) > 0.005) DESC, SUM(remaining) DESC, COUNT(*) DESC
             LIMIT 600"
        );
        $tot = Database::row(
            "SELECT COUNT(DISTINCT $key)      AS owners,
                    COALESCE(SUM(debt),0)      AS debt,
                    COALESCE(SUM(paid),0)      AS paid,
                    COALESCE(SUM(remaining),0) AS remaining
             FROM bawalis"
        ) ?: ['owners' => 0, 'debt' => 0, 'paid' => 0, 'remaining' => 0];
        $dueOwners = 0;
        foreach ($owners as $o) { if ((float)$o['remaining'] > 0.005) { $dueOwners++; } }

        // البوالص التي لا صاحب بيان لها (تحذير + تعيين سريع)
        $unassigned = Database::row(
            "SELECT COUNT(*) AS n, COALESCE(SUM(debt),0) AS debt, COALESCE(SUM(remaining),0) AS remaining
             FROM bawalis
             WHERE declaration_owner IS NULL OR TRIM(declaration_owner) = ''"
        ) ?: ['n' => 0, 'debt' => 0, 'remaining' => 0];

        $this->render('settlements/index', [
            'title'      => 'سداد البوالص — أصحاب البيان',
            'mode'       => 'directory',
            'owners'     => $owners,
            'tot'        => $tot,
            'dueOwners'  => $dueOwners,
            'unassigned' => $unassigned,
            'dueOnly'    => $dueOnly,
            'f'          => $f,
        ] + $this->lookups());
    }

    /** نتائج البحث/الفلترة: البوالص المستهدفة عبر كل أصحاب البيان */
    private function searchResultsView(array $f): void
    {
        [$conds, $params] = $this->billWhere($f);
        $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

        $bills = Database::all(
            'SELECT ' . self::BILL_COLS . "
             FROM bawalis $where
             ORDER BY (pay_status='paid'), bill_date DESC, id DESC
             LIMIT 500",
            $params
        );
        $tot = Database::row(
            "SELECT COUNT(*) AS n, COALESCE(SUM(debt),0) AS debt,
                    COALESCE(SUM(paid),0) AS paid, COALESCE(SUM(remaining),0) AS remaining
             FROM bawalis $where",
            $params
        ) ?: ['n' => 0, 'debt' => 0, 'paid' => 0, 'remaining' => 0];

        // العودة لنفس البحث بعد الدفع
        $qs = [];
        foreach ($f as $k => $v) { if ($v !== '') { $qs[$k] = $v; } }

        $this->render('settlements/index', [
            'title'     => 'سداد البوالص — نتائج البحث',
            'mode'      => 'search',
            'bills'     => $bills,
            'resTot'    => $tot,
            'f'         => $f,
            'backQS'    => http_build_query($qs),
        ] + $this->lookups());
    }

    // --------------------------------------------- وضع صاحب البيان: التفاصيل
    private function ownerView(string $owner): void
    {
        [$ownerCond, $ownerParams, $ownerLabel] = $this->ownerClause($owner);

        // إحصاءات صاحب البيان كاملة (لا تتأثّر بالبحث/الفلترة داخل الصفحة)
        $stats = Database::row(
            "SELECT COUNT(*)                                AS bills,
                    SUM(pay_status='unpaid')                AS unpaid_bills,
                    SUM(pay_status='partial')               AS partial_bills,
                    SUM(pay_status='paid')                  AS paid_bills,
                    SUM(pay_status IN ('unpaid','partial')) AS due_bills,
                    COALESCE(SUM(debt),0)                   AS debt,
                    COALESCE(SUM(paid),0)                   AS paid,
                    COALESCE(SUM(remaining),0)              AS remaining,
                    MIN(bill_date)                          AS first_date,
                    MAX(bill_date)                          AS last_date
             FROM bawalis WHERE $ownerCond",
            $ownerParams
        );
        if (!$stats || (int)$stats['bills'] === 0) {
            flash('error', 'لا توجد بوالص لصاحب البيان المحدّد.');
            redirect('settlements');
        }

        $f = $this->collectFilters();
        [$conds, $params] = $this->billWhere($f, [$ownerCond], $ownerParams);
        $where = 'WHERE ' . implode(' AND ', $conds);

        $bills = Database::all(
            'SELECT ' . self::BILL_COLS . "
             FROM bawalis $where
             ORDER BY (pay_status='paid'), bill_date DESC, id DESC
             LIMIT 1000",
            $params
        );
        $shown = Database::row(
            "SELECT COUNT(*) AS n, COALESCE(SUM(debt),0) AS debt,
                    COALESCE(SUM(paid),0) AS paid, COALESCE(SUM(remaining),0) AS remaining
             FROM bawalis $where",
            $params
        ) ?: ['n' => 0, 'debt' => 0, 'paid' => 0, 'remaining' => 0];

        // العودة لنفس السياق (صاحب البيان + الفلاتر) بعد الدفع
        $qs = ['owner' => $owner];
        foreach ($f as $k => $v) { if ($v !== '') { $qs[$k] = $v; } }

        $this->render('settlements/owner', [
            'title'      => 'سداد بوالص — ' . $ownerLabel,
            'owner'      => $owner,
            'ownerLabel' => $ownerLabel,
            'stats'      => $stats,
            'shown'      => $shown,
            'bills'      => $bills,
            'f'          => $f,
            'filtered'   => $this->anyFilter($f),
            'backQS'     => http_build_query($qs),
        ] + $this->lookups());
    }

    // ------------------------------- البوالص بلا صاحب بيان: تعيين سريع + جماعي
    private function unassignedView(): void
    {
        $noOwner = "(declaration_owner IS NULL OR TRIM(declaration_owner) = '')";

        $bills = Database::all(
            "SELECT id, bill_type, bill_date, bill_number, cert_number, permit_no,
                    company, destination, classification, container_no, container_type,
                    container_count, car_no, driver, freight, debt, remaining, pay_status, is_legacy
             FROM bawalis
             WHERE $noOwner
             ORDER BY bill_date DESC, id DESC
             LIMIT 1000"
        );
        $tot = Database::row(
            "SELECT COUNT(*) AS n, COALESCE(SUM(debt),0) AS debt,
                    COALESCE(SUM(remaining),0) AS remaining
             FROM bawalis WHERE $noOwner"
        ) ?: ['n' => 0, 'debt' => 0, 'remaining' => 0];

        $this->render('settlements/unassigned', [
            'title'        => 'بوالص بلا صاحب بيان — تعيين سريع',
            'bills'        => $bills,
            'tot'          => $tot,
            'ownerOptions' => $this->ownerOptions(),
        ]);
    }

    /** تعيين صاحب البيان لعدّة بوالص دفعة واحدة (فردي أو جماعي) */
    public function assignOwner(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $owners = $_POST['owner'] ?? [];   // owner[bill_id] = اسم صاحب البيان
        $count = 0;
        foreach ($owners as $billId => $name) {
            $name = trim((string)$name);
            if ($name === '') { continue; }
            // لا نكتب إلا فوق البوالص التي ما زالت بلا صاحب بيان (أمان ضد التعارض)
            $n = Database::exec(
                "UPDATE bawalis SET declaration_owner = ?
                 WHERE id = ? AND (declaration_owner IS NULL OR TRIM(declaration_owner) = '')",
                [$name, (int)$billId]
            );
            $count += $n;
        }
        flash(
            $count ? 'success' : 'error',
            $count ? "تم تعيين صاحب البيان لـ $count بوليصة." : 'لم تُدخَل أي أسماء لحفظها.'
        );
        redirect('settlements?unassigned=1');
    }

    /** أسماء أصحاب البيان المقترحة (الموجودون فعلاً + الوكلاء) لضمان توحيد التهجئة */
    private function ownerOptions(): array
    {
        return Bill::ownerOptions();
    }

    /** تسجيل دفعات دفعة واحدة لعدة بوالص محدّدة */
    public function pay(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $payDate = $_POST['pay_date'] ?? work_date();
        $note    = trim($_POST['note'] ?? '') ?: 'سداد';
        $amounts = $_POST['amount'] ?? [];   // amount[bill_id] = القيمة
        $count = 0; $total = 0.0;
        foreach ($amounts as $billId => $amt) {
            $amt = (float)$amt;
            if ($amt != 0) {
                Bill::addPayment((int)$billId, $amt, $payDate, $note);
                $count++; $total += $amt;
            }
        }
        flash('success', $count ? "تم تسجيل $count دفعة بإجمالي " . money($total) : 'لم تُحدَّد أي بوالص للدفع.');

        $back = trim($_POST['back'] ?? '');
        redirect('settlements' . ($back !== '' ? '?' . $back : ''));
    }

    /** صفحة سداد بوليصة واحدة: سجل الدفعات + إضافة/حذف */
    public function show(string $id): void
    {
        $this->requireAuth();
        $bill = Database::row("SELECT * FROM bawalis WHERE id = ?", [(int)$id]);
        if (!$bill) { redirect('settlements'); }
        $this->render('settlements/show', [
            'title'    => 'سداد بوليصة ' . ($bill['bill_number'] ?: '#' . $id),
            'bill'     => $bill,
            'payments' => Bill::payments((int)$id),
        ]);
    }

    public function addPayment(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        Bill::addPayment((int)$id, (float)($_POST['amount'] ?? 0), $_POST['pay_date'] ?? null, trim($_POST['note'] ?? '') ?: 'سداد');
        flash('success', 'تم تسجيل الدفعة.');
        redirect('settlements/' . (int)$id);
    }

    public function deletePayment(string $id, string $paymentId): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $deleted = Bill::deletePayment((int)$paymentId);
        flash($deleted ? 'success' : 'error', $deleted
            ? 'تم حذف الدفعة.'
            : 'الدفعة التاريخية المُرحَّلة من الأرشيف ثابتة ولا يمكن حذفها.');
        redirect('settlements/' . (int)$id);
    }

    // --------------------------------------------------------------- مساعدات
    /** قراءة قيم البحث/الفلترة من الـ GET */
    private function collectFilters(): array
    {
        return [
            'q'              => trim($_GET['q'] ?? ''),
            'status'         => trim($_GET['status'] ?? ''),
            'type'           => trim($_GET['type'] ?? ''),
            'from'           => trim($_GET['from'] ?? ''),
            'to'             => trim($_GET['to'] ?? ''),
            'company'        => trim($_GET['company'] ?? ''),
            'destination'    => trim($_GET['destination'] ?? ''),
            'classification' => trim($_GET['classification'] ?? ''),
            'container_type' => trim($_GET['container_type'] ?? ''),
        ];
    }

    /** بناء شروط البوالص من الفلاتر (فوق شروط أساسية اختيارية) */
    private function billWhere(array $f, array $baseConds = [], array $baseParams = []): array
    {
        $conds = $baseConds;
        $params = $baseParams;

        [$searchSql, $searchParams] = $this->searchClause($f['q']);
        if ($searchSql) { $conds[] = $searchSql; $params = array_merge($params, $searchParams); }

        if ($f['status'] === 'due') {
            $conds[] = "pay_status IN ('unpaid','partial')";
        } elseif (in_array($f['status'], ['unpaid', 'partial', 'paid', 'pending'], true)) {
            $conds[] = 'pay_status = ?'; $params[] = $f['status'];
        }
        if (in_array($f['type'], ['sader', 'warid'], true)) { $conds[] = 'bill_type = ?'; $params[] = $f['type']; }
        if ($f['from'] !== '')           { $conds[] = 'bill_date >= ?';     $params[] = $f['from']; }
        if ($f['to'] !== '')             { $conds[] = 'bill_date <= ?';     $params[] = $f['to']; }
        if ($f['company'] !== '')        { $conds[] = 'company = ?';        $params[] = $f['company']; }
        if ($f['destination'] !== '')    { $conds[] = 'destination = ?';    $params[] = $f['destination']; }
        if ($f['classification'] !== '') { $conds[] = 'classification = ?'; $params[] = $f['classification']; }
        if ($f['container_type'] !== '') { $conds[] = 'container_type = ?'; $params[] = $f['container_type']; }

        return [$conds, $params];
    }

    /** هل توجد أي فلترة/بحث نشطة؟ (status='' أو 'all' = الكل) */
    private function anyFilter(array $f): bool
    {
        foreach ($f as $k => $v) {
            if ($v === '') { continue; }
            if ($k === 'status' && $v === 'all') { continue; }
            return true;
        }
        return false;
    }

    /** شرط مطابقة صاحب البيان: يرجّع [SQL, params, label] */
    private function ownerClause(string $owner): array
    {
        $key = trim($owner);
        if ($key === '' || $key === 'None') {
            return ["(declaration_owner IS NULL OR TRIM(declaration_owner) = '')", [], '(بدون صاحب بيان)'];
        }
        return ['TRIM(declaration_owner) = ?', [$key], $key];
    }

    /** شرط البحث الحر عبر كل الأعمدة: يرجّع [SQL, params] */
    private function searchClause(string $q): array
    {
        if ($q === '') { return ['', []]; }
        $like  = '%' . $q . '%';
        $parts = [];
        $params = [];
        foreach (self::SEARCH_COLS as $c) { $parts[] = "$c LIKE ?"; $params[] = $like; }
        return ['(' . implode(' OR ', $parts) . ')', $params];
    }

    /** قوائم منسدلة للفلاتر */
    private function lookups(): array
    {
        return [
            'companies'    => Lookup::ofType('company'),
            'destinations' => Lookup::ofType('destination'),
            'classifs'     => Lookup::ofType('classification'),
            'ctypes'       => Lookup::ofType('container_type'),
            'ownerOptions' => $this->ownerOptions(),
        ];
    }
}
