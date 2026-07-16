<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\ClientPayment;
use App\Models\Closing;
use App\Models\Party;
use App\Models\VisaCard;

/**
 * تحصيلات العملاء — تسجيل التحصيل وتخصيصه على الفواتير أو عكسه دون حذف.
 *   /payments                 السجل + الإجماليات + نموذج تسجيل دفعة
 *   /payments/pay             تسجيل دفعة (مع توجيه اختياري للعهدة/الفيزا)
 *   /payments/{id}/delete     حذف دفعة (مع عكس ما تولّد في العهدة/الفيزا)
 *
 * نفس منطق دفعات بروفايل الوكيل (App\Models\ClientPayment) — هنا عبر كل العملاء.
 * route='cash' يحترم Closing::guard (تدخل التقفيل اليومي). الربط بالعميل نصّي بلا FK.
 */
class PaymentController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();
        $f = [
            'client' => trim($_GET['client'] ?? ''),
            'route'  => trim($_GET['route'] ?? ''),
            'from'   => trim($_GET['from'] ?? ''),
            'to'     => trim($_GET['to'] ?? ''),
            'q'      => trim($_GET['q'] ?? ''),
        ];
        $this->render('payments/index', [
            'title'     => 'تحصيلات العملاء',
            'f'         => $f,
            'payments'  => ClientPayment::all($f),
            'summary'   => ClientPayment::summary($f),
            'agents'    => Party::agents(),
            'visaCards' => VisaCard::all(true),
        ]);
    }

    public function pay(): void
    {
        $this->requireAuth();
        $this->requireCsrf();

        $name = trim($_POST['client_name'] ?? '');
        if ($name === '') { flash('error', 'اختر العميل أولاً.'); redirect('payments'); }

        $amount = ($_POST['amount'] ?? '') === '' ? 0 : (float) $_POST['amount'];
        if ($amount <= 0) { flash('error', 'أدخل مبلغ الدفعة.'); redirect('payments'); }

        $date  = trim($_POST['pay_date'] ?? '');
        $date  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : work_date();
        $route = in_array($_POST['route'] ?? 'none', ['cash', 'visa'], true) ? $_POST['route'] : 'none';
        $card  = (int) ($_POST['visa_card_id'] ?? 0);

        if ($route === 'visa' && $card <= 0) {
            flash('error', 'اختر كارت الفيزا لتوجيه الدفعة إليه.'); redirect('payments');
        }
        if ($route === 'cash' && ($err = Closing::guard($date))) { flash('error', $err); redirect('payments'); }

        try {
            ClientPayment::create([
                'client_name'  => $name,
                'agent_id'     => (int)($_POST['agent_id'] ?? 0),
                'pay_date'     => $date,
                'amount'       => $amount,
                'note'         => trim($_POST['note'] ?? ''),
                'route'        => $route,
                'visa_card_id' => $card,
                'auto_allocate'=> !empty($_POST['auto_allocate']),
            ]);
            flash('success', 'تم تسجيل التحصيل' . (!empty($_POST['auto_allocate']) ? ' وتخصيصه على أقدم الفواتير المستحقة.' : '.'));
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('payments');
    }

    public function delete(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $pay = ClientPayment::find((int) $id);
        if (!$pay) { flash('error', 'الدفعة غير موجودة.'); redirect('payments'); }
        if ($pay['route'] === 'cash' && ($err = Closing::guard($pay['pay_date']))) {
            flash('error', $err); redirect('payments');
        }
        try {
            ClientPayment::reverse((int)$id, trim($_POST['reason'] ?? ''));
            flash('success', 'تم عكس التحصيل مع الاحتفاظ بسجله.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('payments');
    }
}
