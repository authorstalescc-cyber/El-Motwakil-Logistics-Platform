<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\VisaCard;

/**
 * رصيد المكتب — كروت الفيزا وأرصدتها.
 * لوحة بكل الكروت + رصيد كل كارت (افتتاحي + شحنات − مدفوعات بالفيزا)،
 * صفحة تفصيل لكل كارت بسجل حركاته، وإدارة الكروت والشحنات.
 * المدفوعات تُسحب حيًّا من عمليات الفيزا المنسوبة للكارت (غير قابلة للتعديل من هنا).
 */
class OfficeController extends Controller
{
    // ─────────────────────────────────────────── اللوحة
    public function index(): void
    {
        $this->requireAuth();
        $this->render('office/index', [
            'title'   => 'رصيد المكتب',
            'cards'   => VisaCard::withBalances(),
            'summary' => VisaCard::summary(),
            'byOwner' => VisaCard::byOwner(),
        ]);
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
            'ledger'  => VisaCard::ledger((int) $id),
            'balance' => VisaCard::balanceOf((int) $id),
            'stats'   => VisaCard::stats((int) $id),
            'lastDep' => VisaCard::lastDeposit((int) $id),
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
            'ledger'  => VisaCard::ledger((int) $id),
            'balance' => VisaCard::balanceOf((int) $id),
            'stats'   => VisaCard::stats((int) $id),
        ], false);
    }

    // ─────────────────────────────────────────── إضافة/تعديل كارت
    public function create(): void
    {
        $this->requireAuth();
        $this->render('office/form', [
            'title'  => 'إضافة كارت فيزا',
            'row'    => ['name' => '', 'owner' => '', 'bank' => '', 'opening_balance' => '', 'status' => 'active', 'note' => ''],
            'action' => url('office'),
            'isEdit' => false,
        ]);
    }

    public function store(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $data = $this->collect();
        if ($data['name'] === '') { flash('error', 'اكتب اسم أو رقم الكارت.'); redirect('office/new'); }
        $id = VisaCard::create($data);
        // رصيد مبدئي اختياري → يُسجَّل كشحنة مؤرَّخة (بدل الرصيد الافتتاحي)
        $init = ($_POST['init_amount'] ?? '') === '' ? 0 : (float) $_POST['init_amount'];
        if ($init != 0) {
            VisaCard::createDeposit($id, [
                'dep_date' => $this->cleanDate($_POST['init_date'] ?? '') ?: work_date(),
                'amount'   => $init,
                'note'     => 'رصيد مبدئي',
            ]);
        }
        flash('success', 'تمت إضافة الكارت.');
        redirect('office/' . $id);
    }

    public function edit(string $id): void
    {
        $this->requireAuth();
        $row = VisaCard::find((int) $id);
        if (!$row) { flash('error', 'الكارت غير موجود.'); redirect('office'); }
        $this->render('office/form', [
            'title'  => 'تعديل كارت فيزا',
            'row'    => $row,
            'action' => url('office/' . $id),
            'isEdit' => true,
        ]);
    }

    public function update(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $data = $this->collect();
        if ($data['name'] === '') { flash('error', 'اكتب اسم أو رقم الكارت.'); redirect('office/' . $id . '/edit'); }
        VisaCard::update((int) $id, $data);
        flash('success', 'تم حفظ التعديلات.');
        redirect('office/' . $id);
    }

    public function destroy(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        VisaCard::delete((int) $id);
        flash('success', 'تم حذف الكارت (وفُكّت نسبة عملياته).');
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
        if ($d['amount'] <= 0) { flash('error', 'اكتب مبلغ الشحن.'); redirect('office/' . $id); }
        VisaCard::createDeposit((int) $id, $d);
        flash('success', 'تمت إضافة الشحن للرصيد.');
        redirect('office/' . $id);
    }

    public function deleteDeposit(string $depId): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $dep = VisaCard::findDeposit((int) $depId);
        VisaCard::deleteDeposit((int) $depId);
        flash('success', 'تم حذف الشحن.');
        redirect($dep ? 'office/' . (int) $dep['card_id'] : 'office');
    }

    // ─────────────────────────────────────────── أدوات
    private function collect(): array
    {
        return [
            'name'            => trim($_POST['name'] ?? ''),
            'owner'           => trim($_POST['owner'] ?? ''),
            'bank'            => trim($_POST['bank'] ?? ''),
            // الرصيد الافتتاحي أُلغِيَ لصالح «الشحنات المؤرَّخة» (visa_deposits) — يبقى 0 دائمًا.
            'opening_balance' => 0,
            'status'          => ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            'note'            => trim($_POST['note'] ?? ''),
        ];
    }

    private function cleanDate($v): string
    {
        $v = trim((string) $v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    }
}
