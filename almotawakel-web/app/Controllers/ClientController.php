<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\FormState;
use App\Models\ClientAccount;
use App\Models\ClientPayment;
use App\Models\Closing;
use App\Models\Party;
use App\Models\PartyDoc;
use App\Models\PartyMaster;
use App\Models\PermitInvoice;
use App\Models\BankAccount;

/**
 * **حسابات العملاء (v93)** — الوحدة المستقلة اللي اتفصلت عن «دليل الأطراف».
 *
 *   /clients                        قائمة حسابات العملاء (بحث + فرز + إجماليات)
 *   /clients/{id}                   بروفايل العميل بتبويبات (?tab=)
 *   /clients/{id}/drill/{what}      تفاصيل أي رقم — «ممنوع رقم أصمّ»
 *   /clients/{id}/statement         كشف حساب A4 للطباعة (فترة اختيارية)
 *   /clients/{id}/edit              تعديل بيانات العميل
 *   /clients/{id}/pay               تسجيل تحصيل  (اتنقلت من DirectoryController)
 *   /clients/{id}/payment/{pid}/reverse   عكس تحصيل (اتنقلت كمان)
 *
 * ⚠️ **الكتابة الوحيدة هنا = التحصيل وبيانات الكارت.** الفوترة مكانها الإذن
 *    (قرار v20)، والمستندات في `DirectoryController` (مشتركة مع الشركات) —
 *    فمفيش تعريف تاني لأي عملية، والوحدة دي **عدسة** مش مسار مالي جديد.
 */
class ClientController extends Controller
{
    /** قائمة حسابات العملاء. */
    public function index(): void
    {
        $this->requireAuth();
        $q    = trim($_GET['q'] ?? '');
        $sort = ClientAccount::sort($_GET['sort'] ?? '');
        $rows = ClientAccount::list($q, $sort);

        $this->render('clients/index', [
            'title'  => 'حسابات العملاء',
            'q'      => $q,
            'sort'   => $sort,
            'rows'   => $rows,
            'totals' => ClientAccount::listTotals($rows),
        ]);
    }

    /** بروفايل العميل — تبويبات بروابط حقيقية. */
    public function show(string $id): void
    {
        $this->requireAuth();
        $agentId = (int) $id;
        $agent   = Party::agent($agentId);
        if (!$agent) { $this->notFound('العميل ده مش موجود أو اتدمج مع عميل تاني.'); }
        $name = (string) $agent['name'];
        $tab  = ClientAccount::tab($_GET['tab'] ?? '');

        $data = [
            'title'     => $agent['name'],
            'agent'     => $agent,
            'tab'       => $tab,
            'head'      => ClientAccount::head($agentId, $name),
            'aging'     => ClientAccount::aging($agentId),
            'billable'  => ClientAccount::billable($name),
            'payState'  => FormState::consume('clients.pay.' . $agentId, 'clients/' . $agentId),
            'bankAccounts' => BankAccount::all(true),
            'openInv'   => ClientAccount::openInvoices($agentId),
        ];

        $data += match ($tab) {
            'invoices' => ['invoices' => ClientAccount::invoices($name)],
            'payments' => ['payments' => ClientAccount::payments($name)],
            'work'     => ['work'     => ClientAccount::work($agentId, $name)],
            'card'     => ClientAccount::card($agentId) + [
                'docParty' => ['type' => 'agent', 'id' => $agentId, 'name' => $name],
                'docState' => FormState::consume('partydoc.agent.' . $agentId, 'clients/' . $agentId),
            ],
            default    => ['ledger' => ClientAccount::ledger($agentId, $name)],
        };

        $this->render('clients/show', $data);
    }

    /**
     * تفاصيل أي رقم في الترويسة — «ممنوع رقم أصمّ» (قاعدة ⑤).
     * `what`: charges | paid | remaining | overdue | open | aging
     */
    public function drill(string $id, string $what): void
    {
        $this->requireAuth();
        $agentId = (int) $id;
        $agent   = Party::agent($agentId);
        if (!$agent) { $this->notFound('العميل ده مش موجود أو اتدمج مع عميل تاني.'); }
        if (!in_array($what, ['charges', 'paid', 'remaining', 'overdue', 'open', 'aging'], true)) {
            $this->notFound('نوع التفاصيل ده مش موجود.');
        }
        $name = (string) $agent['name'];

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: private, no-store, max-age=0');
        }

        $this->render('clients/_drill', [
            'what'    => $what,
            'agentId' => $agentId,
            'name'    => $name,
            'head'    => ClientAccount::head($agentId, $name),
            'aging'   => ClientAccount::aging($agentId),
            'rows'    => match ($what) {
                'charges'   => ClientAccount::invoices($name),
                'paid'      => ClientAccount::payments($name),
                'overdue',
                'open'      => ClientAccount::openInvoices($agentId),
                default     => ClientAccount::ledger($agentId, $name)['rows'],
            },
        ], false);
    }

    /** كشف حساب A4 للطباعة — فترة اختيارية (`?from=&to=`). */
    public function statement(string $id): void
    {
        $this->requireAuth();
        $agentId = (int) $id;
        $agent   = Party::agent($agentId);
        if (!$agent) { $this->notFound('العميل ده مش موجود أو اتدمج مع عميل تاني.'); }
        $name = (string) $agent['name'];
        $date = static fn (string $k): string =>
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET[$k] ?? '') ? (string) $_GET[$k] : '';
        $from = $date('from');
        $to   = $date('to');

        $this->render('clients/statement_print', [
            'title'  => 'كشف حساب — ' . $name,
            'agent'  => $agent,
            'from'   => $from,
            'to'     => $to,
            'ledger' => ClientAccount::ledger($agentId, $name, $from, $to),
            'aging'  => ClientAccount::aging($agentId),
            'head'   => ClientAccount::head($agentId, $name),
        ], false);
    }

    // ═══════════════════════════════════════════════ تعديل بيانات العميل

    public function edit(string $id): void
    {
        $this->requireAuth();
        $agentId = (int) $id;
        $agent   = Party::agent($agentId);
        if (!$agent) { $this->notFound('العميل ده مش موجود أو اتدمج مع عميل تاني.'); }
        $fs = FormState::consume('clients.edit.' . $agentId, 'clients/' . $agentId . '/edit');

        $this->render('clients/form', [
            'title'   => 'بيانات العميل — ' . $agent['name'],
            'agent'   => $agent,
            'row'     => array_replace((array) $agent, $fs['old']),
            'formErr' => $fs['errors']['_form'] ?? '',
            'state'   => $fs,
        ]);
    }

    public function save(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $agentId = (int) $id;
        $agent   = Party::agent($agentId);
        if (!$agent) { $this->notFound('العميل ده مش موجود أو اتدمج مع عميل تاني.'); }
        $back = 'clients/' . $agentId;

        try {
            PartyMaster::update('agent', $agentId, $_POST, (int) ($_POST['lock_version'] ?? 0));
        } catch (\Throwable $e) {
            $this->formFailure('clients.edit.' . $agentId, 'clients/' . $agentId . '/edit',
                $_POST, ['_form' => $e->getMessage()], $back);
        }
        $this->done('اتحفظت بيانات العميل.', $back . '?tab=card');
    }

    // ═══════════════════════════════════════════════ التحصيل

    /**
     * تسجيل تحصيل من العميل (اتنقلت من `DirectoryController::addPayment` بلا
     * أي تغيير في المنطق — نفس `ClientPayment::create` ونفس الحُرّاس).
     *
     * الجديد بس: `invoice_id` اختياري = **تحصيل على فاتورة بعينها** بدل
     * التخصيص التلقائي على الأقدم — والاتنين في نفس النموذج.
     */
    public function pay(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $agentId = (int) $id;
        $agent   = Party::agent($agentId);
        if (!$agent) { $this->notFound('العميل ده مش موجود أو اتدمج مع عميل تاني.'); }
        $name = (string) $agent['name'];
        $back = 'clients/' . $agentId;

        $amount = ($_POST['amount'] ?? '') === '' ? 0 : (float) $_POST['amount'];
        if ($amount <= 0) { $this->payFailure($agentId, 'اكتب مبلغ الدفعة الأول.'); }

        $date = trim($_POST['pay_date'] ?? '');
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : work_date();

        // ⚠️ القايمة من `ClientPayment::routeChoices()` — المصدر الوحيد اللي
        //    الشاشة بتقرا منه كمان (وإلا خيار معروض والخادم يرفضه بالسكوت).
        $route = isset(ClientPayment::routeChoices()[$_POST['route'] ?? '']) ? (string) $_POST['route'] : 'none';
        $account = (int) ($_POST['bank_account_id'] ?? 0);

        if ($route === 'visa' && !BankAccount::isActive($account)) {
            $this->payFailure($agentId, 'اختار الحساب البنكي اللي الدفعة هتتوجّه له.');
        }
        // العهدة النقدية تدخل التقفيل اليومي → امنع الكتابة على يوم متقفل
        if ($route === 'cash' && ($err = Closing::guard($date))) { $this->payFailure($agentId, $err); }

        /* ⚠️ الفاتورة المختارة لازم تكون **بتاعة العميل ده** — الحارس في
           `ClientPayment::allocate` بيقارن `agent_id` كمان (قاعدة ③: الفورم
           مش مصدر ثقة، و`<select>` ممكن يتزوّر). */
        $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
        if ($invoiceId > 0) {
            $inv = PermitInvoice::find($invoiceId);
            if (!$inv || (int) ($inv['agent_id'] ?? 0) !== $agentId) {
                $this->payFailure($agentId, 'الفاتورة المختارة مش من فواتير العميل ده.');
            }
        }

        // بعد نجاح التحقّق وقبل الكتابة — دفعة مكررة تعني رصيد عميل غلط،
        // ولو `route=cash` تعني كمان إضافة عهدة مكررة تدخل التقفيل اليومي.
        $this->claimForm($back);

        try {
            ClientPayment::create([
                'client_name'   => $name,
                'agent_id'      => $agentId,
                'pay_date'      => $date,
                'amount'        => $amount,
                'note'          => trim($_POST['note'] ?? ''),
                'route'         => $route,
                'bank_account_id' => $account,
                'invoice_id'    => $invoiceId,
                'auto_allocate' => $invoiceId <= 0 && !empty($_POST['auto_allocate']),
            ]);
        } catch (\Throwable $e) { $this->payFailure($agentId, $e->getMessage()); }

        $this->done('اتسجّل التحصيل.', $back . '?tab=payments');
    }

    /** فشل تسجيل تحصيل — بيرجّع المبلغ والتاريخ والملاحظة بدل ما يضيعوا. */
    private function payFailure(int $agentId, string $msg): never
    {
        $path = 'clients/' . $agentId;
        $this->formFailure(
            'clients.pay.' . $agentId,
            $path,
            [
                'amount'        => (string) ($_POST['amount'] ?? ''),
                'pay_date'      => (string) ($_POST['pay_date'] ?? ''),
                'note'          => (string) ($_POST['note'] ?? ''),
                'route'         => (string) ($_POST['route'] ?? 'none'),
                'bank_account_id' => (string) ($_POST['bank_account_id'] ?? ''),
                'invoice_id'    => (string) ($_POST['invoice_id'] ?? ''),
                'auto_allocate' => !empty($_POST['auto_allocate']) ? '1' : '',
            ],
            ['_form' => $msg],
            $path
        );
    }

    /** عكس تحصيل مع بقاء سجله (اتنقلت من `DirectoryController::deletePayment`). */
    public function reversePayment(string $id, string $pid): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $agentId = (int) $id;
        $back    = 'clients/' . $agentId . '?tab=payments';
        $pay     = ClientPayment::find((int) $pid);
        if (!$pay) { $this->fail('التحصيل ده مش موجود أو اتمسح.', $back); }
        if ((int) ($pay['agent_id'] ?? 0) !== $agentId) {
            http_response_code(404);
            $this->fail('التحصيل ده مش من تحصيلات العميل ده.', $back);
        }
        // لو الدفعة أنشأت عهدة نقدية على يوم متقفل، امنع العكس حتى يُفتَح الإقفال
        if ($pay['route'] === 'cash' && ($err = Closing::guard($pay['pay_date']))) {
            $this->fail($err, $back);
        }
        try {
            ClientPayment::reverse((int) $pid, trim($_POST['reason'] ?? ''));
        } catch (\Throwable $e) { $this->fail($e->getMessage(), $back); }
        $this->done('اتعكس التحصيل مع الاحتفاظ بسجله.', $back);
    }

    /**
     * روابط قديمة لبروفايل الوكيل جوّه «دليل الأطراف» — بتحوّل هنا.
     * ⚠️ لازمة: المالك عنده روابط محفوظة، والبحث الشامل كان بيوديه هناك.
     */
    public function legacyProfile(string $id): never
    {
        redirect('clients/' . (int) $id);
    }

    /** مستندات العميل جوّه تبويب «البيانات» — نفس مكوّن الشركات (مصدر واحد). */
    public static function docsFor(int $agentId): array
    {
        return PartyDoc::forParty('agent', $agentId);
    }
}
