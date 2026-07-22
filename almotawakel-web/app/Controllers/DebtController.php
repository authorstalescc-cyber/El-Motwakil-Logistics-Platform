<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\FormState;
use App\Models\Debt;
use App\Models\Expense;
use App\Models\Lookup;

/**
 * وحدة المديونيات — نظرة موحّدة على الجهات التي تدين لها الشركة.
 *
 * صفحة /debts بتبويبات:
 *   • inspection — الكشف والضمان (ياسر أبو عمر / كونتراكت): محتسب تلقائياً + دفعات + باقي.
 *   • owners     — ملخّص أصحاب بيان البوالص + رابط لصفحة سداد البوالص.
 *   • others     — مديونيات أخرى (حسابات يدوية) + إضافة/دفع.
 *   • visa       — تتبع كل العمليات المدفوعة بالفيزا + إضافة عملية.
 * والمسار /debts/account/{id} صفحة حساب مديونية يدوية واحدة (دفعات).
 */
class DebtController extends Controller
{
    private const TABS = ['inspection', 'owners', 'others', 'visa'];

    // ---------------------------------------------------------------- الصفحة الرئيسية
    public function index(): void
    {
        $this->requireAuth();

        $tab = trim($_GET['tab'] ?? 'inspection');
        if (!in_array($tab, self::TABS, true)) { $tab = 'inspection'; }

        // حسابا الكشف والضمان (منفصلان)
        $inspCharges  = Debt::inspectionCharges();
        $inspAcc      = Debt::inspectionAccount();   // الكشف
        $guarAcc      = Debt::guaranteeAccount();    // الضمان
        $inspTotals   = Debt::accountTotals($inspAcc);
        $guarTotals   = Debt::accountTotals($guarAcc);
        $inspPayments = Debt::payments((int) $inspAcc['id']);
        $guarPayments = Debt::payments((int) $guarAcc['id']);

        // مديونيات أخرى (حسابات يدوية) مع إجمالياتها
        $manual = [];
        foreach (Debt::manualAccounts() as $a) {
            $manual[] = $a + ['totals' => Debt::accountTotals($a)];
        }

        // فلاتر الفيزا
        $vf = [
            'q'      => trim($_GET['q'] ?? ''),
            'source' => trim($_GET['source'] ?? ''),
            'from'   => $this->cleanDate($_GET['from'] ?? ''),
            'to'     => $this->cleanDate($_GET['to'] ?? ''),
        ];

        $paymentStates = [];
        foreach ([$inspAcc, $guarAcc] as $account) {
            $paymentStates[(int) $account['id']] = FormState::consume(
                'debts.payment.' . (int) $account['id'], 'debts?tab=inspection'
            );
        }
        $accountState = FormState::consume('debts.account.create', 'debts?tab=others');
        $visaState = FormState::consume('debts.visa.create', 'debts?tab=visa');

        $this->render('debts/index', [
            'title'        => 'المديونيات',
            'tab'          => $tab,
            'ov'           => Debt::overview(),
            // الكشف والضمان (حسابان منفصلان)
            'inspAcc'      => $inspAcc,
            'guarAcc'      => $guarAcc,
            'inspCharges'  => $inspCharges,
            'inspTotals'   => $inspTotals,
            'guarTotals'   => $guarTotals,
            'inspPayments' => $inspPayments,
            'guarPayments' => $guarPayments,
            // أصحاب البيان
            'ownersSum'    => Debt::ownersSummary(),
            'topOwners'    => Debt::topOwners(8),
            // مديونيات أخرى
            'manual'       => $manual,
            // الفيزا
            'vf'           => $vf,
            'visaRows'     => Debt::visaLedger($vf, 500),
            'visaTotals'   => Debt::visaTotals($vf),
            'visaSources'  => Debt::VISA_SOURCES,
            'visaCats'     => Lookup::ofType('expense_cat'),
            'paymentStates'=> $paymentStates,
            'accountState' => $accountState,
            'visaState'    => $visaState,
        ]);
    }

    // ---------------------------------------------------------------- صفحة حساب يدوي واحد
    public function account(string $id): void
    {
        $this->requireAuth();
        $acc = Debt::account((int) $id);
        if (!$acc) { flash('error', 'حساب المديونية غير موجود.'); redirect('debts?tab=others'); }
        $state = FormState::consume('debts.payment.' . (int) $id, 'debts/account/' . (int) $id);
        $this->render('debts/account', [
            'title'    => 'مديونية — ' . $acc['name'],
            'acc'      => $acc,
            'totals'   => Debt::accountTotals($acc),
            'payments' => Debt::payments((int) $acc['id']),
            'paymentState' => $state,
        ]);
    }

    // ---------------------------------------------------------------- دفعات المديونية
    public function addPayment(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $acc = Debt::account((int) $id);
        if (!$acc) { flash('error', 'حساب المديونية غير موجود.'); redirect('debts'); }

        $fallback = (($acc['kind'] ?? '') === 'manual')
            ? 'debts/account/' . (int) $acc['id']
            : 'debts?tab=inspection';
        $path = safe_back($fallback, $_POST['_back'] ?? ($_POST['back'] ?? null));
        $amount = (float) ($_POST['amount'] ?? 0);
        if ($amount <= 0) {
            $this->formFailure('debts.payment.' . (int) $id, $path, $_POST,
                ['amount' => 'اكتب مبلغ سداد أكبر من صفر.'], $fallback);
        }
        $this->claimForm($path);

        Debt::addPayment(
            (int) $id,
            $amount,
            $this->cleanDate($_POST['pay_date'] ?? '') ?: null,
            trim($_POST['note'] ?? '') ?: 'سداد',
            !empty($_POST['is_visa']),
            (!empty($_POST['is_visa']) && !empty($_POST['visa_card_id'])) ? (int) $_POST['visa_card_id'] : null
        );
        flash('success', 'تم تسجيل الدفعة.');
        $this->redirectBack($acc);
    }

    public function deletePayment(string $paymentId): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $this->claimForm('debts?tab=inspection');
        Debt::deletePayment((int) $paymentId);
        flash('success', 'تم حذف الدفعة.');
        redirect(safe_back('debts?tab=inspection', $_POST['_back'] ?? ($_POST['back'] ?? null)));
    }

    // ---------------------------------------------------------------- حسابات يدوية (مديونيات أخرى)
    public function storeAccount(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $this->formFailure('debts.account.create', 'debts?tab=others', $_POST,
                ['name' => 'اكتب اسم الجهة الدائنة.'], 'debts?tab=others');
        }
        $this->claimForm('debts?tab=others');
        $opening = ($_POST['opening'] ?? '') === '' ? 0 : (float) $_POST['opening'];
        Debt::createManualAccount($name, $opening, trim($_POST['note'] ?? ''));
        flash('success', 'تمت إضافة المديونية.');
        redirect('debts?tab=others');
    }

    public function deleteAccount(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $this->claimForm('debts?tab=others');
        Debt::deleteManualAccount((int) $id);
        flash('success', 'تم حذف المديونية.');
        redirect('debts?tab=others');
    }

    // ---------------------------------------------------------------- إضافة عملية فيزا
    // تُخزَّن كمصروف يدوي بعلَم الفيزا (تظهر في المصروفات وفي تتبع الفيزا معاً).
    public function storeVisa(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $data = [
            'exp_date'    => $this->cleanDate($_POST['exp_date'] ?? '') ?: work_date(),
            'category'    => trim($_POST['category'] ?? ''),
            'beneficiary' => trim($_POST['beneficiary'] ?? ''),
            'amount'      => ($_POST['amount'] ?? '') === '' ? 0 : (float) $_POST['amount'],
            'note'        => trim($_POST['note'] ?? ''),
            'is_visa'     => 1,
            'visa_card_id'=> !empty($_POST['visa_card_id']) ? (int) $_POST['visa_card_id'] : null,
        ];
        if ($data['amount'] == 0) {
            $this->formFailure('debts.visa.create', 'debts?tab=visa', $_POST,
                ['amount' => 'اكتب مبلغ العملية.'], 'debts?tab=visa');
        }
        $this->claimForm('debts?tab=visa');
        Expense::createManual($data);
        if ($data['category'] !== '') { Lookup::add('expense_cat', $data['category']); }
        flash('success', 'تمت إضافة عملية الفيزا.');
        redirect('debts?tab=visa');
    }

    // ---------------------------------------------------------------- أدوات
    /** رجوع بعد دفعة: لحساب يدوي → صفحته، للكشف والضمان → تبويبه */
    private function redirectBack(array $acc): void
    {
        $back = safe_back('', $_POST['_back'] ?? ($_POST['back'] ?? null));
        if ($back !== '') { redirect($back); }
        if (($acc['kind'] ?? '') === 'manual') {
            redirect('debts/account/' . (int) $acc['id']);
        }
        redirect('debts?tab=inspection');
    }

    private function cleanDate($v): string
    {
        $v = trim((string) $v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    }
}
