<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\FormState;
use App\Models\PermitInvoice;

/**
 * إصدار الفواتير — التبويب المستقل.
 *   /invoices        سجل كل الفواتير/كشوف الحساب المُصدرة (بحث/فلترة/إجماليات)
 *   /invoices/new    اختيار الإذن المراد إصدار فاتورة له
 *
 * الإصدار نفسه يتم من داخل الإذن (PermitController::invoiceForm/Store)،
 * والطباعة/الحذف كذلك (invoicePrint/invoiceDelete) — هذا التبويب هو البوابة والسجل.
 */
class InvoiceController extends Controller
{
    private const PER_PAGE = 50;

    /** سجل الفواتير المُصدرة. */
    public function index(): void
    {
        $this->requireAuth();
        PermitInvoice::refreshAllStatuses();

        $q    = trim($_GET['q'] ?? '');
        $kind = trim($_GET['kind'] ?? '');           // invoice | statement | claim
        $tax  = trim($_GET['tax'] ?? '');            // 1 = ضريبية فقط | 0 = غير ضريبية
        $from = trim($_GET['from'] ?? '');
        $to   = trim($_GET['to'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));

        $conds = []; $params = [];
        if ($q !== '') {
            $conds[] = '(doc_no LIKE ? OR internal_no LIKE ? OR client LIKE ? OR company LIKE ? OR permit_no LIKE ?)';
            array_push($params, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%");
        }
        if (isset(PermitInvoice::KINDS[$kind])) { $conds[] = 'doc_kind = ?'; $params[] = $kind; }
        if ($tax === '1' || $tax === '0') { $conds[] = 'taxable = ?'; $params[] = (int)$tax; }
        if ($from !== '') { $conds[] = 'invoice_date >= ?'; $params[] = $from; }
        if ($to   !== '') { $conds[] = 'invoice_date <= ?'; $params[] = $to; }
        if (isset(PermitInvoice::STATUSES[$status])) { $conds[]='status=?'; $params[]=$status; }
        $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

        $total = (int) Database::scalar("SELECT COUNT(*) FROM permit_invoices $where", $params);
        $sums  = Database::row(
            "SELECT COALESCE(SUM(CASE WHEN status<>'void' THEN total ELSE 0 END),0) AS grand,
                    COALESCE(SUM(CASE WHEN doc_kind='invoice' THEN 1 ELSE 0 END),0) AS inv_cnt,
                    COALESCE(SUM(CASE WHEN doc_kind='statement' THEN 1 ELSE 0 END),0) AS stmt_cnt,
                    COALESCE(SUM(CASE WHEN status<>'void' THEN vat_amount ELSE 0 END),0) AS vat,
                    COALESCE(SUM(balance_due),0) AS balance_due
             FROM permit_invoices $where", $params
        ) ?: ['grand'=>0,'inv_cnt'=>0,'stmt_cnt'=>0,'vat'=>0,'balance_due'=>0];

        $pages  = max(1, (int) ceil($total / self::PER_PAGE));
        $page   = min($page, $pages);
        $offset = ($page - 1) * self::PER_PAGE;
        $rows = Database::all(
            "SELECT * FROM permit_invoices $where ORDER BY id DESC LIMIT " . self::PER_PAGE . " OFFSET $offset",
            $params
        );
        $backQs = http_build_query(array_filter(['q'=>$q,'kind'=>$kind,'tax'=>$tax,'status'=>$status,'from'=>$from,'to'=>$to,'page'=>$page]));
        $backPath = 'invoices' . ($backQs ? '?' . $backQs : '');
        $bulkState = FormState::consume('invoices.bulk.void', $backPath);
        $voidState = FormState::consume('invoices.void', $backPath);

        $this->render('invoices/index', [
            'title'  => 'إصدار الفواتير',
            'rows'   => $rows,
            'total'  => $total,
            'sums'   => $sums,
            'page'   => $page,
            'pages'  => $pages,
            'q' => $q, 'kind' => $kind, 'tax' => $tax, 'from' => $from, 'to' => $to, 'status'=>$status,
            'bulkState' => $bulkState,
            'voidState' => $voidState,
        ]);
    }

    /** حذف جماعي لعدة فواتير دفعةً واحدة من سجل الفواتير. */
    public function bulkDelete(): void
    {
        $this->requireAuth();
        $this->requireCsrf();

        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array)($_POST['ids'] ?? [])),
            fn($v) => $v > 0
        )));
        $reason = trim($_POST['reason'] ?? '');
        $back = safe_back('invoices', $_POST['_back'] ?? ($_POST['back'] ?? null));
        $errors = [];
        if ($reason === '') { $errors['reason'] = 'سبب الإلغاء مطلوب.'; }
        if (!$ids) { $errors['ids'] = 'حدّد مستندًا واحدًا على الأقل.'; }
        if ($errors) {
            $this->formFailure('invoices.bulk.void', $back, $_POST, $errors, 'invoices');
        }
        $this->claimForm($back);
        $n = 0;
        foreach ($ids as $id) {
            try { if (PermitInvoice::void($id,$reason) !== null) { $n++; } }
            catch (\Throwable $e) { /* يكمل الباقي ويعرض العدد الفعلي */ }
        }
        flash($n ? 'success' : 'error', $n ? "تم إلغاء $n مستند مع الاحتفاظ بأرقامها." : 'لم يتم إلغاء أي مستند.');

        // العودة لسجل الفواتير بنفس الفلترة/الصفحة إن وُجدت
        redirect($back);
    }

    /** اختيار إذن لإصدار فاتورة له. */
    public function pick(): void
    {
        $this->requireAuth();
        $q = trim($_GET['q'] ?? '');
        $conds = []; $params = [];
        if ($q !== '') {
            $conds[] = '(p.permit_no LIKE ? OR p.company LIKE ? OR p.client LIKE ? OR p.destination LIKE ?)';
            array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
        }
        $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
        $rows = Database::all(
            "SELECT p.id, p.permit_type, p.permit_no, p.permit_date, p.company, p.client,
                    p.destination, p.containers, p.status,
                    (SELECT COUNT(*) FROM bawalis b WHERE b.permit_id = p.id) AS bills,
                    (SELECT COUNT(*) FROM permit_invoices pi WHERE pi.permit_id = p.id) AS inv_cnt
             FROM permits p
             $where
             ORDER BY p.permit_date DESC, p.id DESC
             LIMIT 200",
            $params
        );
        $this->render('invoices/pick', [
            'title' => 'إصدار فاتورة — اختر الإذن',
            'rows'  => $rows,
            'q'     => $q,
        ]);
    }
}
