<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\FormState;
use App\Core\Database;
use App\Models\BankAccount;
use App\Models\VisaCard;

/**
 * الحسابات البنكية وكروت الفيزا التابعة لها.
 * الحساب هو المحفظة صاحبة الرصيد؛ الكارت أداة صرف وهوية للعملية فقط.
 */
class OfficeController extends Controller
{
    // ─────────────────────────────────────────── اللوحة
    public function index(): void
    {
        $this->requireAuth();
        $this->render('office/index', [
            'title'   => 'الحسابات البنكية وكروت الفيزا',
            'accounts'=> BankAccount::withBalances(),
            'cards'   => VisaCard::withBalances(),
            'summary' => BankAccount::summary(),
            'byOwner' => VisaCard::byOwner(),
        ]);
    }

    /**
     * تفاصيل أي رقم في شاشة الكروت — قاعدة المالك «ممنوع رقم أصمّ».
     *
     * ⚠️ **مسار خاص بالوحدة — مش مسار تفاصيل عام** ياخد اسم جدول ويرجّع صفوف.
     *    النوع محصور في قائمة بيضا، والمسار بيمرّ على `Access::enforce` زي أي
     *    مسار (الرفض هو الافتراضي — تحذير ٥). بيرندر بلا layout ويرجّع HTML.
     *
     * ⚠️ **ما بيكتبش استعلامًا جديدًا** — بيقرا من نفس دوال `VisaCard` اللي
     *    الأرقام اتحسبت منها، فمستحيل النافذة تقول رقمًا والكارت يقول غيره.
     */
    private const DRILL = ['balance', 'deposits', 'payments', 'owners'];

    public function drill(string $what): void
    {
        $this->requireAuth();
        if (!in_array($what, self::DRILL, true)) {
            $this->notFound('نوع التفاصيل ده مش موجود.');
        }
        // ⚠️ محروسة بـ`headers_sent()` — أي مخرَج سابق بيلزق Warning جوّه جسم
        //    الرد فالنافذة تستقبل HTML متلوّث (باج v50).
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: private, no-store, max-age=0');
        }
        $this->render('office/_drill', [
            'what'    => $what,
            'accounts'=> BankAccount::withBalances(),
            'cards'   => VisaCard::withBalances(),
            'summary' => BankAccount::summary(),
            'byOwner' => VisaCard::byOwner(),
        ], false);
    }

    // ─────────────────────────────────────────── صفحة كارت
    public function card(string $id): void
    {
        $this->requireAuth();
        $card = VisaCard::find((int) $id);
        if (!$card) { flash('error', 'الكارت غير موجود.'); redirect('office'); }
        $this->render('office/card', [
            'title'   => 'كارت: ' . $card['name'],
            'card'    => $card,
            'payments'=> VisaCard::payments((int) $id),
            'balance' => VisaCard::balanceOf((int) $id),
            'stats'   => VisaCard::stats((int) $id),
            'account' => BankAccount::find((int) ($card['bank_account_id'] ?? 0)),
        ]);
    }

    /** كشف حساب A4 للكارت (صفحة مستقلّة للطباعة/حفظ PDF). */
    public function statement(string $id): void
    {
        $this->requireAuth();
        $card = VisaCard::find((int) $id);
        if (!$card) { flash('error', 'الكارت غير موجود.'); redirect('office'); }
        $this->render('office/statement', [
            'title'   => 'كشف حساب — ' . $card['name'],
            'card'    => $card,
            'payments'=> VisaCard::payments((int) $id),
            'balance' => VisaCard::balanceOf((int) $id),
            'stats'   => VisaCard::stats((int) $id),
        ], false);
    }

    // ─────────────────────────────────────────── إضافة/تعديل كارت
    public function create(): void
    {
        $this->requireAuth();
        $state = FormState::consume('office.card.create', 'office');
        $defaults = ['name' => '', 'owner' => '', 'bank_account_id' => '', 'status' => 'active', 'note' => ''];
        $this->render('office/form', [
            'title'  => 'إضافة كارت فيزا',
            'row'    => array_replace($defaults, $state['old']),
            'action' => url('office'),
            'isEdit' => false,
            'errors' => $state['errors'],
            'formToken' => $state['token'],
            'back' => $state['back'],
            'accounts' => BankAccount::all(true),
        ]);
    }

    public function store(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $data = $this->collect();
        $errors = [];
        if ($data['name'] === '') {
            $errors['name'] = 'اكتب اسم أو رقم الكارت.';
        }
        if (!BankAccount::isActive((int) $data['bank_account_id'])) {
            $errors['bank_account_id'] = 'اختار حسابًا بنكيًا نشطًا.';
        }
        if ($errors) {
            $this->formFailure('office.card.create', 'office/new', $_POST,
                $errors, 'office');
        }
        $this->claimForm('office');
        try { $id = VisaCard::create($data); }
        catch (\Throwable $e) {
            $this->formFailure('office.card.create', 'office/new', $_POST, ['_form' => $e->getMessage()], 'office');
        }
        flash('success', 'تمت إضافة الكارت.');
        redirect('office/' . $id);
    }

    public function edit(string $id): void
    {
        $this->requireAuth();
        $row = VisaCard::find((int) $id);
        if (!$row) { flash('error', 'الكارت غير موجود.'); redirect('office'); }
        $state = FormState::consume('office.card.edit.' . (int) $id, 'office/' . (int) $id);
        $this->render('office/form', [
            'title'  => 'تعديل كارت فيزا',
            'row'    => array_replace($row, $state['old']),
            'action' => url('office/' . $id),
            'isEdit' => true,
            'errors' => $state['errors'],
            'formToken' => $state['token'],
            'back' => $state['back'],
            'accounts' => BankAccount::all(false),
        ]);
    }

    public function update(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $data = $this->collect();
        $errors = [];
        if ($data['name'] === '') { $errors['name'] = 'اكتب اسم أو رقم الكارت.'; }
        if ((int) $data['bank_account_id'] <= 0) { $errors['bank_account_id'] = 'اختار الحساب البنكي.'; }
        if ($errors) {
            $this->formFailure('office.card.edit.' . (int) $id, 'office/' . (int) $id . '/edit', $_POST,
                $errors, 'office/' . (int) $id);
        }
        $this->claimForm('office/' . (int) $id);
        try { VisaCard::update((int) $id, $data); }
        catch (\Throwable $e) {
            $this->formFailure('office.card.edit.' . (int) $id, 'office/' . (int) $id . '/edit', $_POST,
                ['_form' => $e->getMessage()], 'office/' . (int) $id);
        }
        flash('success', 'تم حفظ التعديلات.');
        redirect('office/' . $id);
    }

    public function destroy(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $this->claimForm('office');
        try { VisaCard::delete((int) $id); }
        catch (\Throwable $e) { flash('error', $e->getMessage()); redirect('office/' . $id); }
        flash('success', 'تم حذف الكارت غير المستخدم.');
        redirect('office');
    }

    // ─────────────────────────────────────────── الشحنات (إيداعات الرصيد)
    public function storeDeposit(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        if (!VisaCard::find((int) $id)) { flash('error', 'الكارت غير موجود.'); redirect('office'); }
        $d = [
            'dep_date' => $this->cleanDate($_POST['dep_date'] ?? '') ?: work_date(),
            'amount'   => ($_POST['amount'] ?? '') === '' ? 0 : (float) $_POST['amount'],
            'note'     => trim($_POST['note'] ?? ''),
        ];
        if ($d['amount'] <= 0) {
            $this->formFailure('office.deposit.' . (int) $id, 'office/' . (int) $id . '#deposit', $_POST,
                ['amount' => 'اكتب مبلغ شحن أكبر من صفر.'], 'office/' . (int) $id);
        }
        $this->claimForm('office/' . (int) $id . '#deposit');
        try { VisaCard::createDeposit((int) $id, $d); }
        catch (\Throwable $e) { flash('error', $e->getMessage()); redirect('office/' . $id); }
        $card = VisaCard::find((int) $id);
        flash('success', 'تمت إضافة الإيداع للحساب البنكي.');
        redirect('office/accounts/' . (int) ($card['bank_account_id'] ?? 0));
    }

    public function deleteDeposit(string $depId): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $dep = VisaCard::findDeposit((int) $depId);
        $back = $dep && !empty($dep['bank_account_id']) ? 'office/accounts/' . (int) $dep['bank_account_id'] : 'office';
        $this->claimForm($back);
        try { VisaCard::deleteDeposit((int) $depId); }
        catch (\Throwable $e) { flash('error', $e->getMessage()); redirect($back); }
        flash('success', 'تم حذف حركة الحساب.');
        redirect($back);
    }

    // ─────────────────────────────────────────── أدوات
    private function collect(): array
    {
        return [
            'name'            => trim($_POST['name'] ?? ''),
            'owner'           => trim($_POST['owner'] ?? ''),
            'bank_account_id' => (int) ($_POST['bank_account_id'] ?? 0),
            'bank'            => '',
            // الرصيد الافتتاحي أُلغِيَ لصالح «الشحنات المؤرَّخة» (visa_deposits) — يبقى 0 دائمًا.
            'opening_balance' => 0,
            'status'          => ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            'note'            => trim($_POST['note'] ?? ''),
        ];
    }

    // ─────────────────────────────────────────── الحسابات البنكية

    public function accountCreate(): void
    {
        $this->requireAuth();
        $state = FormState::consume('office.account.create', 'office');
        $this->render('office/account_form', [
            'title' => 'إضافة حساب بنكي', 'isEdit' => false, 'action' => url('office/accounts'),
            'row' => array_replace(['name'=>'','bank_name'=>'','account_ref'=>'','owner'=>'','status'=>'active',
                'note'=>'','init_amount'=>'','init_date'=>work_date()], $state['old']),
            'errors'=>$state['errors'], 'formToken'=>$state['token'], 'back'=>$state['back'],
        ]);
    }

    public function accountStore(): void
    {
        $this->requireAuth(); $this->requireCsrf();
        $data = $this->collectAccount();
        if ($data['name'] === '') {
            $this->formFailure('office.account.create', 'office/accounts/new', $_POST,
                ['name'=>'اكتب اسم الحساب البنكي.'], 'office');
        }
        $init = round((float) ($_POST['init_amount'] ?? 0), 2);
        if ($init < 0) {
            $this->formFailure('office.account.create', 'office/accounts/new', $_POST,
                ['init_amount'=>'الرصيد المبدئي لا يمكن يكون سالبًا.'], 'office');
        }
        if ($init > 0 && $data['status'] !== 'active') {
            $this->formFailure('office.account.create', 'office/accounts/new', $_POST,
                ['status'=>'فعّل الحساب أولًا لإضافة رصيد مبدئي.'], 'office');
        }
        $this->claimForm('office');
        $pdo = Database::conn(); $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            $id = BankAccount::create($data);
            if ($init > 0) {
                BankAccount::createEntry($id, ['dep_date'=>$this->cleanDate($_POST['init_date'] ?? '') ?: work_date(),
                    'amount'=>$init, 'note'=>'رصيد مبدئي']);
            }
            if ($own) { $pdo->commit(); }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            $this->formFailure('office.account.create', 'office/accounts/new', $_POST,
                ['_form'=>$e->getMessage()], 'office');
        }
        flash('success', 'تمت إضافة الحساب البنكي.'); redirect('office/accounts/' . $id);
    }

    public function account(string $id): void
    {
        $this->requireAuth();
        $account = BankAccount::find((int) $id);
        if (!$account) { flash('error','الحساب البنكي مش موجود.'); redirect('office'); }
        $state = FormState::consume('office.account.entry.' . (int) $id, 'office/accounts/' . (int) $id);
        $this->render('office/account', [
            'title'=>'حساب: ' . $account['name'], 'account'=>$account,
            'cards'=>BankAccount::cards((int)$id), 'ledger'=>BankAccount::ledger((int)$id),
            'balance'=>BankAccount::balanceOf((int)$id), 'entryState'=>$state,
        ]);
    }

    public function accountStatement(string $id): void
    {
        $this->requireAuth();
        $account = BankAccount::find((int) $id);
        if (!$account) { flash('error','الحساب البنكي مش موجود.'); redirect('office'); }
        $this->render('office/account_statement', [
            'title'=>'كشف حساب — ' . $account['name'], 'account'=>$account,
            'cards'=>BankAccount::cards((int)$id), 'ledger'=>BankAccount::ledger((int)$id),
            'balance'=>BankAccount::balanceOf((int)$id),
        ], false);
    }

    public function accountEdit(string $id): void
    {
        $this->requireAuth();
        $row = BankAccount::find((int)$id);
        if (!$row) { flash('error','الحساب البنكي مش موجود.'); redirect('office'); }
        $state = FormState::consume('office.account.edit.' . (int)$id, 'office/accounts/' . (int)$id);
        $this->render('office/account_form', [
            'title'=>'تعديل حساب بنكي', 'isEdit'=>true, 'action'=>url('office/accounts/'.$id),
            'row'=>array_replace($row,$state['old']), 'errors'=>$state['errors'],
            'formToken'=>$state['token'], 'back'=>$state['back'],
        ]);
    }

    public function accountUpdate(string $id): void
    {
        $this->requireAuth(); $this->requireCsrf();
        $data = $this->collectAccount();
        if ($data['name'] === '') {
            $this->formFailure('office.account.edit.'.(int)$id, 'office/accounts/'.(int)$id.'/edit', $_POST,
                ['name'=>'اكتب اسم الحساب البنكي.'], 'office/accounts/'.(int)$id);
        }
        $this->claimForm('office/accounts/'.(int)$id);
        try { BankAccount::update((int)$id,$data); }
        catch (\Throwable $e) {
            $this->formFailure('office.account.edit.'.(int)$id, 'office/accounts/'.(int)$id.'/edit', $_POST,
                ['_form'=>$e->getMessage()], 'office/accounts/'.(int)$id);
        }
        flash('success','تم حفظ الحساب.'); redirect('office/accounts/'.$id);
    }

    public function accountDestroy(string $id): void
    {
        $this->requireAuth(); $this->requireCsrf(); $this->claimForm('office');
        try { BankAccount::delete((int)$id); flash('success','تم حذف الحساب الفارغ.'); }
        catch (\Throwable $e) { flash('error',$e->getMessage()); }
        redirect('office');
    }

    public function storeAccountEntry(string $id): void
    {
        $this->requireAuth(); $this->requireCsrf();
        $kind = ($_POST['kind'] ?? 'deposit') === 'withdraw' ? 'withdraw' : 'deposit';
        $amount = round((float) ($_POST['amount'] ?? 0), 2);
        $back = 'office/accounts/' . (int)$id . '#entry';
        if ($amount <= 0) {
            $this->formFailure('office.account.entry.'.(int)$id, $back, $_POST,
                ['amount'=>'اكتب مبلغًا أكبر من صفر.'], 'office/accounts/'.(int)$id);
        }
        $this->claimForm($back);
        try {
            BankAccount::createEntry((int)$id, [
                'dep_date'=>$this->cleanDate($_POST['dep_date'] ?? '') ?: work_date(),
                'amount'=>$kind === 'withdraw' ? -$amount : $amount,
                'note'=>trim((string)($_POST['note'] ?? '')),
            ]);
        } catch (\Throwable $e) {
            $this->formFailure('office.account.entry.'.(int)$id, $back, $_POST,
                ['_form'=>$e->getMessage()], 'office/accounts/'.(int)$id);
        }
        flash('success',$kind === 'withdraw' ? 'تم تسجيل السحب.' : 'تم تسجيل الإيداع.');
        redirect('office/accounts/'.$id);
    }

    public function deleteAccountEntry(string $entryId): void
    {
        $this->requireAuth(); $this->requireCsrf();
        $row = BankAccount::findEntry((int)$entryId);
        $back = $row ? 'office/accounts/'.(int)$row['bank_account_id'] : 'office';
        $this->claimForm($back);
        try { BankAccount::deleteEntry((int)$entryId); flash('success','تم حذف حركة الحساب.'); }
        catch (\Throwable $e) { flash('error',$e->getMessage()); }
        redirect($back);
    }

    private function collectAccount(): array
    {
        return [
            'name'=>trim((string)($_POST['name'] ?? '')),
            'bank_name'=>trim((string)($_POST['bank_name'] ?? '')),
            'account_ref'=>trim((string)($_POST['account_ref'] ?? '')),
            'owner'=>trim((string)($_POST['owner'] ?? '')),
            'status'=>($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            'note'=>trim((string)($_POST['note'] ?? '')),
        ];
    }

    private function cleanDate($v): string
    {
        $v = trim((string) $v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    }
}
