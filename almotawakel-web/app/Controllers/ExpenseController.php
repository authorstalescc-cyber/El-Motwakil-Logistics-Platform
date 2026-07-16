<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\Closing;
use App\Models\Expense;
use App\Models\Lookup;
use App\Models\Permit;
use App\Models\Rep;

/**
 * وحدة المصروفات — سجل موحّد لكل مصروفات النظام + إدخال مصاريف يدوية.
 * السجل (index) يسحب من كل الوحدات؛ الإضافة/التعديل/الحذف للمصاريف اليدوية فقط.
 */
class ExpenseController extends Controller
{
    private int $perPage = 50;

    // ------------------------------------------------------------ السجل الموحّد
    public function index(): void
    {
        $this->requireAuth();

        $f = [
            'q'      => trim($_GET['q'] ?? ''),
            'source' => trim($_GET['source'] ?? ''),
            'visa'   => trim($_GET['visa'] ?? ''),   // '' | only | cash
            'from'   => $this->cleanDate($_GET['from'] ?? ''),
            'to'     => $this->cleanDate($_GET['to'] ?? ''),
        ];
        // فلتر سنة سريع → يحوَّل إلى مدى تاريخي (لا يطغى على من/إلى الصريحة)
        $year = trim($_GET['year'] ?? '');
        if ($year !== '' && $f['from'] === '' && $f['to'] === '') {
            $f['from'] = "$year-01-01";
            $f['to']   = "$year-12-31";
        }

        $page   = max(1, (int)($_GET['page'] ?? 1));
        $total  = Expense::count($f);
        $pages  = max(1, (int) ceil($total / $this->perPage));
        $page   = min($page, $pages);
        $offset = ($page - 1) * $this->perPage;

        $this->render('expenses/index', [
            'title'   => 'المصروفات',
            'rows'    => Expense::ledger($f, $this->perPage, $offset),
            'totals'  => Expense::totals($f),
            'sources' => Expense::SOURCES,
            'total'   => $total,
            'page'    => $page,
            'pages'   => $pages,
            'years'   => Expense::years(),
            'f'       => $f,
            'year'    => $year,
        ]);
    }

    // ------------------------------------------------------------ استكمال بيانات الإيصالات
    /**
     * شاشة سريعة لاستكمال «السعر الفعلي للعميل» و«رقم الإيصال» للإيصالات الناقصة،
     * مقيَّدة بيوم العمل المحدَّد فقط. لا تمسّ المبلغ النقدي ولا الإقفال.
     */
    public function receiptsComplete(): void
    {
        $this->requireAuth();
        $day = work_date();
        $this->render('expenses/receipts_complete', [
            'title' => 'استكمال بيانات الإيصالات',
            'day'   => $day,
            'rows'  => Permit::receiptsMissingData($day),
        ]);
    }

    /** حفظ الاستكمال — تحديث الفعلي/الرقم فقط لكل صف أُدخِلت له قيمة. */
    public function receiptsCompleteSave(): void
    {
        $this->requireAuth();
        $this->requireCsrf();

        $ids     = $_POST['id']           ?? [];
        $actuals = $_POST['actual_unit']  ?? [];
        $refs    = $_POST['ref_no']       ?? [];

        $done = 0;
        $pdo  = \App\Core\Database::conn();
        $pdo->beginTransaction();
        try {
            foreach ((array)$ids as $i => $rawId) {
                $sid = (int)$rawId;
                if ($sid <= 0) { continue; }
                $aRaw = trim((string)($actuals[$i] ?? ''));
                $rRaw = trim((string)($refs[$i] ?? ''));
                $unit = ($aRaw !== '' && is_numeric($aRaw) && (float)$aRaw > 0) ? (float)$aRaw : null;
                $ref  = $rRaw !== '' ? $rRaw : null;
                if ($unit === null && $ref === null) { continue; }
                if (Permit::completeReceipt($sid, $unit, $ref)) { $done++; }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            flash('error', 'تعذّر حفظ الاستكمال: ' . $e->getMessage());
            redirect('expenses/receipts');
        }

        flash($done > 0 ? 'success' : 'error',
            $done > 0 ? "تم استكمال بيانات {$done} إيصال." : 'لم تُدخِل أي بيانات لحفظها.');
        redirect('expenses/receipts');
    }

    // ------------------------------------------------------------ نموذج جديد
    public function create(): void
    {
        $this->requireAuth();
        $this->render('expenses/form', [
            'title'    => 'إضافة مصروف يدوي',
            'row'      => ['exp_date' => work_date(), 'category' => '', 'beneficiary' => '', 'amount' => '', 'note' => ''],
            'cats'     => Lookup::ofType('expense_cat'),
            'action'   => url('expenses'),
            'isEdit'   => false,
            'reps'     => Rep::all(),
            'repKinds' => Rep::KINDS,
            'repPre'   => (int) ($_GET['rep'] ?? 0),   // اختيار مسبق للمندوب (رابط سريع من كارت المندوب)
        ]);
    }

    // ------------------------------------------------------------ حفظ جديد
    public function store(): void
    {
        $this->requireAuth();
        $this->requireCsrf();

        // لو اتخصّص لمندوب → يتسجّل في سجل المندوب (rep_entries) لا في المصاريف اليدوية.
        // كده السُلفة/التسوية تظهر في المصروفات العامة تلقائيًا عبر مصدر «reps» بلا تحسيب مزدوج،
        // وباقي الأنواع تتحاسب في كشف المندوب وقت التقفيل.
        $repId = (int) ($_POST['rep_id'] ?? 0);
        if ($repId > 0) {
            $this->storeForRep($repId);
            return;
        }

        $data = $this->collect();
        if ($err = Closing::guard($data['exp_date'])) { flash('error', $err); redirect('expenses'); }
        Expense::createManual($data);
        if ($data['category'] !== '') { Lookup::add('expense_cat', $data['category']); }
        flash('success', 'تمت إضافة المصروف بنجاح.');
        redirect('expenses');
    }

    /** حفظ بند لمندوب من صفحة المصروفات (سُلفة / مصروف لوجيستي / خصم…). */
    private function storeForRep(int $repId): void
    {
        $rep = Rep::find($repId);
        if (!$rep) { flash('error', 'المندوب غير موجود.'); redirect('expenses/new'); }

        $kind = trim($_POST['rep_kind'] ?? '');
        if (!isset(Rep::KINDS[$kind]) || $kind === 'settlement') { $kind = 'advance'; }

        $entry = [
            'entry_date' => $this->cleanDate($_POST['exp_date'] ?? '') ?: work_date(),
            'kind'       => $kind,
            'amount'     => ($_POST['amount'] ?? '') === '' ? 0 : (float) $_POST['amount'],
            'is_visa'    => !empty($_POST['is_visa']) ? 1 : 0,
            'note'       => trim($_POST['note'] ?? '') ?: trim($_POST['beneficiary'] ?? ''),
        ];

        // الكاش (سُلفة/تسوية) بس هو اللي بيدخل العهدة/الإقفال → احرس قفل اليوم عليه
        if (Rep::isCashKind($kind) && !$entry['is_visa'] && ($err = Closing::guard($entry['entry_date']))) {
            flash('error', $err); redirect('reps/' . $repId);
        }
        Rep::createEntry($repId, $entry);
        flash('success', 'تم تسجيل «' . Rep::kindLabel($kind) . '» في حساب المندوب ' . $rep['name'] . '.');
        redirect('reps/' . $repId);
    }

    // ------------------------------------------------------------ تعديل
    public function edit(string $id): void
    {
        $this->requireAuth();
        $row = Expense::findManual((int)$id);
        if (!$row) { flash('error', 'المصروف غير موجود أو ليس مصروفاً يدوياً.'); redirect('expenses'); }
        $this->render('expenses/form', [
            'title'   => 'تعديل مصروف يدوي',
            'row'     => $row,
            'cats'    => Lookup::ofType('expense_cat'),
            'action'  => url('expenses/' . $id),
            'isEdit'  => true,
        ]);
    }

    // ------------------------------------------------------------ تحديث
    public function update(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $data = $this->collect();
        $old  = Expense::findManual((int)$id);
        // امنع لو التاريخ الجديد أو القديم يقع ضمن فترة متقفلة
        if ($err = (Closing::guard($data['exp_date']) ?? ($old ? Closing::guard($old['exp_date']) : null))) {
            flash('error', $err); redirect('expenses');
        }
        Expense::updateManual((int)$id, $data);
        if ($data['category'] !== '') { Lookup::add('expense_cat', $data['category']); }
        flash('success', 'تم حفظ التعديلات.');
        redirect('expenses');
    }

    // ------------------------------------------------------------ حذف
    public function destroy(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $old = Expense::findManual((int)$id);
        if ($old && ($err = Closing::guard($old['exp_date']))) { flash('error', $err); redirect('expenses'); }
        Expense::deleteManual((int)$id);
        flash('success', 'تم حذف المصروف.');
        redirect('expenses');
    }

    // ------------------------------------------------------------ أدوات
    private function collect(): array
    {
        return [
            'exp_date'    => $this->cleanDate($_POST['exp_date'] ?? ''),
            'category'    => trim($_POST['category'] ?? ''),
            'beneficiary' => trim($_POST['beneficiary'] ?? ''),
            'amount'      => ($_POST['amount'] ?? '') === '' ? 0 : (float)$_POST['amount'],
            'note'        => trim($_POST['note'] ?? ''),
            'is_visa'     => !empty($_POST['is_visa']) ? 1 : 0,
            'visa_card_id'=> (!empty($_POST['is_visa']) && !empty($_POST['visa_card_id'])) ? (int) $_POST['visa_card_id'] : null,
        ];
    }

    private function cleanDate($v): string
    {
        $v = trim((string)$v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    }
}
