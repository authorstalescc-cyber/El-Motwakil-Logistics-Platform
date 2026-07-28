<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Models\OwnerStatement;

/**
 * كشوف حساب أصحاب البيان — النظام الثاني للفوترة.
 *   /statements            سجل كل الكشوف المُصدرة (بحث/فلترة/إجماليات)
 *   /statements/new        اختيار صاحب البيان (دليل بأرصدتهم)
 *   /statements/form?owner= شاشة إعداد الكشف (معاينة القائمتين + اختيار البوالص)
 *   POST /statements        حفظ الكشف كلقطة ثابتة
 *   /statements/{id}        طباعة A4
 *   POST /statements/{id}/delete  حذف كشف محفوظ
 *
 * المبالغ تُؤخذ من البوالص مباشرةً، والمستند بلا ضريبة (تشغيلي).
 */
class OwnerStatementController extends Controller
{
    private const PER_PAGE = 50;

    /** سجل الكشوف المُصدرة. */
    public function index(): void
    {
        $this->requireAuth();
        if (!OwnerStatement::hasTable()) {
            flash('error', 'جدول الكشوف غير موجود — شغّل php bin/migrate_v12.php');
            redirect('settlements');
        }

        $q    = trim($_GET['q'] ?? '');
        $from = trim($_GET['from'] ?? '');
        $to   = trim($_GET['to'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));

        $conds = []; $params = [];
        if ($q !== '') { $conds[] = '(doc_no LIKE ? OR owner LIKE ?)'; array_push($params, "%$q%", "%$q%"); }
        if ($from !== '') { $conds[] = 'statement_date >= ?'; $params[] = $from; }
        if ($to   !== '') { $conds[] = 'statement_date <= ?'; $params[] = $to; }
        $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

        $total = (int) Database::scalar("SELECT COUNT(*) FROM owner_statements $where", $params);
        $sums  = Database::row(
            "SELECT COALESCE(SUM(paid_sum),0)      AS paid,
                    COALESCE(SUM(remaining_sum),0) AS remaining,
                    COUNT(DISTINCT owner)          AS owners
             FROM owner_statements $where", $params
        ) ?: ['paid'=>0,'remaining'=>0,'owners'=>0];

        $pages  = max(1, (int) ceil($total / self::PER_PAGE));
        $page   = min($page, $pages);
        $offset = ($page - 1) * self::PER_PAGE;
        $rows = Database::all(
            "SELECT * FROM owner_statements $where ORDER BY id DESC LIMIT " . self::PER_PAGE . " OFFSET $offset",
            $params
        );

        $this->render('statements/index', [
            'title' => 'كشوف أصحاب البيان',
            'rows'  => $rows,
            'total' => $total,
            'sums'  => $sums,
            'page'  => $page,
            'pages' => $pages,
            'q' => $q, 'from' => $from, 'to' => $to,
        ]);
    }

    /** اختيار صاحب البيان لإصدار كشف له (بطاقات بأرصدتهم). */
    public function pick(): void
    {
        $this->requireAuth();
        $q = trim($_GET['q'] ?? '');
        $key = "COALESCE(NULLIF(TRIM(declaration_owner),''),'')";
        $conds = ["$key <> ''"]; $params = [];
        if ($q !== '') { $conds[] = 'declaration_owner LIKE ?'; $params[] = "%$q%"; }
        $where = 'WHERE ' . implode(' AND ', $conds);

        $owners = Database::all(
            "SELECT $key AS owner,
                    COUNT(*)                       AS bills,
                    SUM(paid > 0.005)              AS paid_bills,
                    SUM(remaining > 0.005)         AS due_bills,
                    COALESCE(SUM(paid),0)          AS paid,
                    COALESCE(SUM(remaining),0)     AS remaining,
                    MAX(bill_date)                 AS last_date
             FROM bawalis $where
             GROUP BY owner
             ORDER BY (SUM(remaining) > 0.005) DESC, SUM(remaining) DESC, COUNT(*) DESC
             LIMIT 600",
            $params
        );

        $this->render('statements/pick', [
            'title'  => 'إصدار كشف حساب — اختر صاحب البيان',
            'owners' => $owners,
            'q'      => $q,
        ]);
    }

    /** شاشة إعداد الكشف: ملخّص صاحب البيان + قائمتا البوالص (مستلَمة/باقية) قابلتان للاختيار. */
    public function form(): void
    {
        $this->requireAuth();
        if (!OwnerStatement::hasTable()) {
            flash('error', 'جدول الكشوف غير موجود — شغّل php bin/migrate_v12.php');
            redirect('settlements');
        }
        $owner = trim((string)($_GET['owner'] ?? ''));
        if ($owner === '') { redirect('statements/new'); }

        $stats = OwnerStatement::ownerStats($owner);
        if ((int)($stats['bills'] ?? 0) === 0) {
            flash('error', 'لا توجد بوالص لصاحب البيان المحدّد.');
            redirect('statements/new');
        }
        $cands = OwnerStatement::candidates($owner);

        $this->render('statements/form', [
            'title'     => 'إصدار كشف حساب — ' . $owner,
            'owner'     => $owner,
            'stats'     => $stats,
            'paidSummary' => $cands['paid_summary'],
            'remaining' => $cands['remaining'],
            'allBills'  => OwnerStatement::billsForOwner($owner),
            'previous'  => OwnerStatement::forOwner($owner),
            'formState' => \App\Core\FormState::consume(
                'statements.form.' . mb_strtolower(trim($owner)),
                'statements/form?owner=' . urlencode($owner)
            ),
        ]);
    }

    /**
     * فشل إصدار الكشف — بيرجّع **اختيار البوالص كله** مع التاريخ والملاحظة.
     */
    private function statementFailure(string $owner, string $msg): never
    {
        $this->formFailure(
            'statements.form.' . mb_strtolower(trim($owner)),
            'statements/form?owner=' . urlencode($owner),
            [
                'paid_ids'             => array_map('intval', (array)($_POST['paid_ids'] ?? [])),
                'remaining_ids'        => array_map('intval', (array)($_POST['remaining_ids'] ?? [])),
                'include_paid_summary' => !empty($_POST['include_paid_summary']) ? '1' : '',
                'statement_date'       => (string)($_POST['statement_date'] ?? ''),
                'note'                 => (string)($_POST['note'] ?? ''),
            ],
            ['_form' => $msg],
            'statements/form?owner=' . urlencode($owner)
        );
    }

    /** حفظ الكشف كلقطة ثابتة ثم فتح صفحة الطباعة. */
    public function store(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $owner = trim((string)($_POST['owner'] ?? ''));
        if ($owner === '') { $this->fail('صاحب البيان مطلوب.', 'statements/new'); }

        $paidIds = array_map('intval', (array)($_POST['paid_ids'] ?? []));
        $remIds  = array_map('intval', (array)($_POST['remaining_ids'] ?? []));
        $includePaid = !empty($_POST['include_paid_summary']);
        if (!$includePaid && !$paidIds && !$remIds) {
            // ⚠️ كان بيضيّع اختيار البوالص كله — المستخدم بيراجع عشرات البوالص
            // ويشيل ويحط، وأول رفض كان بيرجّعه للاختيار الافتراضي من أول وجديد.
            $this->statementFailure($owner, 'اختار بوليصة واحدة على الأقل تدخل في الكشف.');
        }

        // ⚠️ بعد نجاح التحقّق وقبل الكتابة: يستهلك رمز النموذج مرة واحدة فقط،
        //    فإرسال نفس الشاشة مرتين (رجوع المتصفح ← إعادة إرسال، أو ضغطتان
        //    متتاليتان) يُرفض بدل أن يُنشئ كشفًا مكررًا بأرقام صاحب البيان نفسها.
        $this->claimForm('statements/form?owner=' . urlencode($owner));

        $id = OwnerStatement::create([
            'owner'          => $owner,
            'statement_date' => $_POST['statement_date'] ?? work_date(),
            'note'           => $_POST['note'] ?? '',
            'paid_ids'       => $paidIds,
            'include_paid_summary' => $includePaid,
            'remaining_ids'  => $remIds,
        ]);
        $this->done('تم إصدار كشف الحساب وحفظه ✓', 'statements/' . $id);
    }

    /** صفحة الطباعة A4 أفقية (مستقلة بدون التخطيط العام). */
    public function printDoc(string $id): void
    {
        $this->requireAuth();
        $st = OwnerStatement::find((int)$id);
        if (!$st) { flash('error', 'الكشف غير موجود.'); redirect('statements'); }
        $this->render('statements/print', [
            'title' => 'كشف حساب ' . ($st['doc_no'] ?: '#'.$st['id']),
            'st'    => $st,
        ], false);
    }

    /** مستند واحد يضم كشفًا لكل صاحب بيان؛ كل صاحب يبدأ في صفحة A4 أفقية. */
    public function batchPrint(): void
    {
        $this->requireAuth();
        $scope=($_GET['scope']??'due')==='all'?'all':'due';
        $date=trim((string)($_GET['date']??work_date()));
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){$date=work_date();}
        $owners=OwnerStatement::ownersForBatch($scope!=='all');$documents=[];
        foreach($owners as $owner){$documents[]=OwnerStatement::liveDocument($owner,$date);}
        $this->render('statements/batch_print',[
            'title'=>'كشوف حساب مجمعة — '.count($documents).' صاحب بيان','documents'=>$documents,'scope'=>$scope,'statementDate'=>$date,
        ],false);
    }

    /** إلغاء كشف محفوظ مع الاحتفاظ برقمه. */
    public function delete(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        try {
            OwnerStatement::void((int)$id,trim($_POST['reason'] ?? ''),isset($_POST['lock_version'])?(int)$_POST['lock_version']:null);
            flash('success','تم إلغاء الكشف مع الاحتفاظ برقمه.');
        } catch (\Throwable $e) { flash('error',$e->getMessage()); }
        redirect('statements');
    }
}
