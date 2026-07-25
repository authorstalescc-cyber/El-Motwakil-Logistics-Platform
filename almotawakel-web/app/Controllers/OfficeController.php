<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\FormState;
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
            'cards'   => VisaCard::withBalances(),
            'summary' => VisaCard::summary(),
            'byOwner' => VisaCard::byOwner(),
        ], false);
    }

    // ─────────────────────────────────────────── صفحة كارت
    public function card(string $id): void
    {
        $this->requireAuth();
        $card = VisaCard::find((int) $id);
        if (!$card) { flash('error', 'الكارت غير موجود.'); redirect('office'); }
        $state = FormState::consume('office.deposit.' . (int) $id, 'office/' . (int) $id);
        $this->render('office/card', [
            'title'   => 'كارت: ' . $card['name'],
            'card'    => $card,
            'ledger'  => VisaCard::ledger((int) $id),
            'balance' => VisaCard::balanceOf((int) $id),
            'stats'   => VisaCard::stats((int) $id),
            'lastDep' => VisaCard::lastDeposit((int) $id),
            'depositState' => $state,
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
        $state = FormState::consume('office.card.create', 'office');
        $defaults = ['name' => '', 'owner' => '', 'bank' => '', 'opening_balance' => '', 'status' => 'active', 'note' => '', 'init_amount' => '', 'init_date' => work_date()];
        $this->render('office/form', [
            'title'  => 'إضافة كارت فيزا',
            'row'    => array_replace($defaults, $state['old']),
            'action' => url('office'),
            'isEdit' => false,
            'errors' => $state['errors'],
            'formToken' => $state['token'],
            'back' => $state['back'],
        ]);
    }

    public function store(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $data = $this->collect();
        if ($data['name'] === '') {
            $this->formFailure('office.card.create', 'office/new', $_POST,
                ['name' => 'اكتب اسم أو رقم الكارت.'], 'office');
        }
        $this->claimForm('office');
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
        $state = FormState::consume('office.card.edit.' . (int) $id, 'office/' . (int) $id);
        $this->render('office/form', [
            'title'  => 'تعديل كارت فيزا',
            'row'    => array_replace($row, $state['old']),
            'action' => url('office/' . $id),
            'isEdit' => true,
            'errors' => $state['errors'],
            'formToken' => $state['token'],
            'back' => $state['back'],
        ]);
    }

    public function update(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $data = $this->collect();
        if ($data['name'] === '') {
            $this->formFailure('office.card.edit.' . (int) $id, 'office/' . (int) $id . '/edit', $_POST,
                ['name' => 'اكتب اسم أو رقم الكارت.'], 'office/' . (int) $id);
        }
        $this->claimForm('office/' . (int) $id);
        VisaCard::update((int) $id, $data);
        flash('success', 'تم حفظ التعديلات.');
        redirect('office/' . $id);
    }

    public function destroy(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $this->claimForm('office');
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
        if ($d['amount'] <= 0) {
            $this->formFailure('office.deposit.' . (int) $id, 'office/' . (int) $id . '#deposit', $_POST,
                ['amount' => 'اكتب مبلغ شحن أكبر من صفر.'], 'office/' . (int) $id);
        }
        $this->claimForm('office/' . (int) $id . '#deposit');
        VisaCard::createDeposit((int) $id, $d);
        flash('success', 'تمت إضافة الشحن للرصيد.');
        redirect('office/' . $id);
    }

    public function deleteDeposit(string $depId): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $dep = VisaCard::findDeposit((int) $depId);
        $this->claimForm($dep ? 'office/' . (int) $dep['card_id'] : 'office');
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
