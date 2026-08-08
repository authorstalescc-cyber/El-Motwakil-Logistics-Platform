<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\FormState;
use App\Models\Closing;
use App\Models\Custody;
use App\Models\PushNotify;
use App\Models\RepCustody;

/**
 * رصيد عهدة المكتب (محفظة العهدة) — صفحة مستقلة.
 * تعرض الرصيد الحالي + سجل تراكمي بكل الحركات، وتدير الإضافات اليدوية (توب-أب).
 * عهد السائقين تُسحب حيًّا من البوالص (غير قابلة للتعديل من هنا).
 */
class CustodyController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();
        $state = FormState::consume('custody.topup.create', 'custody');
        $this->render('custody/index', [
            'title'      => 'رصيد العهدة',
            // نزّل عهدة لمندوب من الدرج — بتظهر بس لو فيه مناديب نشطين
            'repList'    => RepCustody::available() ? RepCustody::activeReps() : [],
            'repDepState'=> FormState::consume('custody.repdep.create', 'custody'),
            'balance'    => Custody::balance(),
            'topups'     => Custody::topupsTotal(),
            'driverOut'  => Custody::driverCustodyOut(),
            'rate'       => Custody::deductionRate(),
            'ledger'     => Custody::ledger(),
            'breakdown'  => Closing::currentBreakdown(),
            'lastClosed' => Closing::lastClosedDate(),
            'topupState' => $state,
        ]);
    }

    // ─────────────────────────────────────────── الإضافات اليدوية (توب-أب)

    public function storeTopup(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $data = $this->collect();
        if ($data['amount'] <= 0) {
            $this->formFailure('custody.topup.create', 'custody#topup', $_POST,
                ['amount' => 'اكتب مبلغ إضافة أكبر من صفر.'], 'custody');
        }
        if ($err = Closing::guard($data['cust_date'])) {
            $this->formFailure('custody.topup.create', 'custody#topup', $_POST,
                ['cust_date' => $err], 'custody');
        }
        $this->claimForm('custody#topup');
        Custody::createTopup($data);
        flash('success', 'تمت إضافة العهدة إلى الرصيد.');
        redirect('custody');
    }

    public function editTopup(string $id): void
    {
        $this->requireAuth();
        $row = Custody::findTopup((int) $id);
        if (!$row) { flash('error', 'الإضافة غير موجودة.'); redirect('custody'); }
        $state = FormState::consume('custody.topup.edit.' . (int) $id, 'custody');
        $this->render('custody/topup_form', [
            'title'  => 'تعديل إضافة عهدة',
            'row'    => array_replace($row, $state['old']),
            'action' => url('custody/topup/' . $id),
            'errors' => $state['errors'],
            'formToken' => $state['token'],
            'back' => $state['back'],
        ]);
    }

    public function updateTopup(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $data = $this->collect();
        $old  = Custody::findTopup((int) $id);
        $formId = 'custody.topup.edit.' . (int) $id;
        $formPath = 'custody/topup/' . (int) $id . '/edit';
        if ($data['amount'] <= 0) {
            $this->formFailure($formId, $formPath, $_POST,
                ['amount' => 'اكتب مبلغ إضافة أكبر من صفر.'], 'custody');
        }
        // امنع لو التاريخ الجديد أو القديم يقع ضمن فترة متقفلة
        if ($err = (Closing::guard($data['cust_date']) ?? ($old ? Closing::guard($old['cust_date']) : null))) {
            $this->formFailure($formId, $formPath, $_POST, ['cust_date' => $err], 'custody');
        }
        $this->claimForm('custody');
        Custody::updateTopup((int) $id, $data);
        flash('success', 'تم حفظ التعديلات.');
        redirect('custody');
    }

    public function deleteTopup(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $old = Custody::findTopup((int) $id);
        if ($old && ($err = Closing::guard($old['cust_date']))) { flash('error', $err); redirect('custody'); }
        $this->claimForm('custody');
        Custody::deleteTopup((int) $id);
        flash('success', 'تم حذف الإضافة.');
        redirect('custody');
    }

    // ───────────────────── نزّل عهدة لمندوب **من درج المكتب** (المصدر = office)

    /**
     * الكاش بيخرج من الدرج لجيب المندوب — **من صفحة الخزنة نفسها** بدل ما المالك
     * يفتح كارت المندوب ويفتكر يختار المصدر صح.
     *
     * ⚠️⚠️ **المصدر مثبَّت `office` في الكود مش جاي من الفورم** — الشاشة دي معناها
     *    «الفلوس دي طلعت من الدرج اللي قدّامي»، فقبول مصدر من الفورم كان بيسمح
     *    بتسجيل `bank` (فلوس جديدة داخلة) من مكان بيقول العكس.
     *
     * ⚠️ **مفيش منطق حساب جديد هنا**: نفس `RepCustody::createDeposit()` ونفس
     *    `depositAffectsSafe()` بتوع كارت المندوب بالحرف — الشاشة نقطة دخول تانية
     *    لنفس الإجراء، مش مسار تاني ممكن يفترق عنه.
     */
    public function storeRepDeposit(): void
    {
        $this->requireAuth();
        $this->requireCsrf();

        $repId  = (int) ($_POST['rep_id'] ?? 0);
        $amount = round((float) ($_POST['amount'] ?? 0), 2);
        $date   = trim((string) ($_POST['dep_date'] ?? ''));
        $date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : work_date();
        $note   = trim((string) ($_POST['note'] ?? ''));
        $fail   = fn (array $errors) => $this->formFailure(
            'custody.repdep.create', 'custody#rep-deposit', $_POST, $errors, 'custody');

        // الفورم مش مصدر ثقة: لازم مندوب **نشط** موجود فعلاً
        if (!isset(RepCustody::activeReps()[$repId])) { $fail(['rep_id' => 'اختار المندوب الأول.']); }
        if ($amount <= 0) { $fail(['amount' => 'اكتب مبلغ العهدة (أكبر من صفر).']); }

        $source = 'office';
        if (RepCustody::depositAffectsSafe($source) && ($lock = Closing::guard($date)) !== null) {
            $fail(['dep_date' => $lock]);
        }

        $this->claimForm('custody#rep-deposit');
        RepCustody::createDeposit($repId, $amount, $date, $note, $source);
        // ⚠️ الإشعار بعد نجاح العملية وخارج أي transaction (docs/15 §٨.٢)
        PushNotify::repDeposit($repId, (string) (RepCustody::activeReps()[$repId] ?? 'مندوب'),
                               $amount, $date, (string) (Auth::user() ?? 'المكتب'));
        $this->done('نزلت عهدة ' . money($amount, false) . ' من الدرج لـ'
                    . (RepCustody::activeReps()[$repId] ?? 'المندوب') . '.', 'custody', 'rep-deposit');
    }

    private function collect(): array
    {
        $date = trim($_POST['cust_date'] ?? '');
        return [
            'cust_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : work_date(),
            'driver'    => trim($_POST['driver'] ?? ''),
            'amount'    => ($_POST['amount'] ?? '') === '' ? 0 : (float) $_POST['amount'],
            'note'      => trim($_POST['note'] ?? ''),
        ];
    }
}
