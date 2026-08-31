<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\FormState;
use App\Models\ClientPayment;
use App\Models\Closing;
use App\Models\Party;
use App\Models\BankAccount;

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
        $state = FormState::consume('payments.pay', current_app_path());
        $this->render('payments/index', [
            'title'     => 'تحصيلات العملاء',
            'f'         => $f,
            'payments'  => ClientPayment::all($f),
            'summary'   => ClientPayment::summary($f),
            'agents'    => Party::agents(),
            'bankAccounts' => BankAccount::all(true),
            'formState' => $state,
        ]);
    }

    /**
     * تفاصيل أرقام شاشة التحصيلات — قاعدة المالك «ممنوع رقم أصمّ».
     *
     * ⚠️ **مسار خاص بالوحدة** بقائمة بيضا مقفولة، بيمرّ على `Access::enforce`.
     * ⚠️ **بيحترم فلاتر الشاشة** — الرقم اللي المالك ضغط عليه محسوب على
     *    المعروض، فلو النافذة جابت الكل تبقى بتقول رقمًا تاني.
     */
    private const DRILL = ['all', 'cash', 'visa', 'none'];

    public function drill(string $what): void
    {
        $this->requireAuth();
        if (!in_array($what, self::DRILL, true)) {
            $this->notFound('نوع التفاصيل ده مش موجود.');
        }
        $f = [
            'client' => trim($_GET['client'] ?? ''),
            'route'  => $what === 'all' ? trim($_GET['route'] ?? '') : $what,
            'from'   => trim($_GET['from'] ?? ''),
            'to'     => trim($_GET['to'] ?? ''),
            'q'      => trim($_GET['q'] ?? ''),
        ];
        // ⚠️ محروسة بـ`headers_sent()` (باج v50 — Warning جوّه جسم الرد)
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: private, no-store, max-age=0');
        }
        $this->render('payments/_drill', [
            'what'      => $what,
            'rows'      => ClientPayment::all($f),
            'summary'   => ClientPayment::summary($f),
            'bankAccounts' => BankAccount::all(false),
        ], false);
    }

    public function pay(): void
    {
        $this->requireAuth();
        $this->requireCsrf();

        $name = trim($_POST['client_name'] ?? '');

        $amount = ($_POST['amount'] ?? '') === '' ? 0 : (float) $_POST['amount'];

        $date  = trim($_POST['pay_date'] ?? '');
        $date  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : work_date();
        // ⚠️ القايمة من `ClientPayment::routeChoices()` — المصدر الوحيد اللي
        //    الشاشة بتقرا منه كمان (وإلا خيار معروض والخادم يرفضه بالسكوت).
        $route = isset(ClientPayment::routeChoices()[$_POST['route'] ?? '']) ? (string) $_POST['route'] : 'none';
        $account = (int) ($_POST['bank_account_id'] ?? 0);

        $errors = [];
        if ($name === '') { $errors['client_name'] = 'اختر العميل أولاً.'; }
        if ($amount <= 0) { $errors['amount'] = 'أدخل مبلغًا أكبر من صفر.'; }
        if ($route === 'visa' && !BankAccount::isActive($account)) {
            $errors['bank_account_id'] = 'اختر حسابًا بنكيًا نشطًا لتوجيه الدفعة إليه.';
        }
        $back = safe_back('payments', $_POST['_back'] ?? null);
        if ($errors) { $this->formFailure('payments.pay', $back, $_POST, $errors, 'payments'); }
        if ($route === 'cash' && ($err = Closing::guard($date))) {
            $this->formFailure('payments.pay', $back, $_POST, ['pay_date'=>$err], 'payments');
        }

        $this->claimForm('payments');
        try {
            ClientPayment::create([
                'client_name'  => $name,
                'agent_id'     => (int)($_POST['agent_id'] ?? 0),
                'pay_date'     => $date,
                'amount'       => $amount,
                'note'         => trim($_POST['note'] ?? ''),
                'route'        => $route,
                'bank_account_id' => $account,
                'auto_allocate'=> !empty($_POST['auto_allocate']),
            ]);
            // ⚠️ الإشعار **بعد** نجاح الحفظ وخارج أي transaction (docs/15 §٨.٢)
            \App\Models\PushNotify::clientPaid($name, $amount, $route, $date,
                                               (string) (\App\Core\Auth::user() ?? 'المكتب'));
            flash('success', 'تم تسجيل التحصيل' . (!empty($_POST['auto_allocate']) ? ' وتخصيصه على أقدم الفواتير المستحقة.' : '.'));
        } catch (\Throwable $e) {
            $this->formFailure('payments.pay', $back, $_POST, ['_form'=>$e->getMessage()], 'payments');
        }
        redirect($back);
    }

    public function delete(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $pay = ClientPayment::find((int) $id);
        $back = safe_back('payments', $_POST['_back'] ?? null);
        if (!$pay) { flash('error', 'الدفعة غير موجودة.'); redirect($back); }
        if ($pay['route'] === 'cash' && ($err = Closing::guard($pay['pay_date']))) {
            flash('error', $err); redirect($back);
        }
        $this->claimForm('payments');
        try {
            ClientPayment::reverse((int)$id, trim($_POST['reason'] ?? ''));
            flash('success', 'تم عكس التحصيل مع الاحتفاظ بسجله.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect($back);
    }
}
