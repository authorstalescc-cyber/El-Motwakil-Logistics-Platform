<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\Closing;
use App\Models\Rep;
use App\Models\RepAccount;
use App\Models\RepCustody;
use App\Models\RepPortal;
use App\Models\RepProfile;

/**
 * وحدة المناديب — كارت لكل مندوب + كشف حساب بطرفين + تقفيل دوري يدوي.
 *
 * الكاش الوحيد اللي بيطلّع من الخزنة (سُلفة/دفعة تسوية) بيحترم قفل اليوم (Closing::guard)
 * وبيظهر في المصروفات العامة والعهدة. باقي البنود استحقاقات في سجل المندوب فقط.
 * لا middleware — requireAuth/requireCsrf يدوية داخل كل action (تحذير 5).
 */
class RepController extends Controller
{
    // ─────────────────────────────────────────── القائمة (الكروت)
    public function index(): void
    {
        $this->requireAuth();
        $q      = trim($_GET['q'] ?? '');
        $status = trim($_GET['status'] ?? '');
        if (!in_array($status, ['active', 'inactive'], true)) { $status = ''; }

        $reps = Rep::all($q, $status);
        $totalOwed = 0.0;
        foreach ($reps as $r) { $totalOwed += (float) $r['balance']; }

        $this->render('reps/index', [
            'title'     => 'المناديب',
            'reps'      => $reps,
            'q'         => $q,
            'status'    => $status,
            'totalOwed' => round($totalOwed, 2),
        ]);
    }

    // ─────────────────────────────────────────── بروفايل المندوب (v88)

    /**
     * البروفايل بتبويبات — **روابط حقيقية** (`?tab=`) لا تبديل بجافاسكريبت،
     * فالرجوع بالمتصفح والمشاركة والفتح في تبويب جديد كلهم شغّالين.
     *
     * ⚠️ كل تبويب بيجيب **بياناته هو بس**: البروفايل فيه ٨ مصادر، وجلبهم كلهم
     *    في كل فتحة كان بيدّي عشرات الاستعلامات عشان يتعرض واحد.
     */
    public function show(string $id): void
    {
        $this->requireAuth();
        $repId = (int) $id;
        $rep   = Rep::find($repId);
        if (!$rep) { $this->notFound('المندوب ده مش موجود أو اتمسح.'); }

        $tab    = RepProfile::tab($_GET['tab'] ?? '');
        $period = RepAccount::period(trim($_GET['at'] ?? ''));

        $data = [
            'entryState'  => \App\Core\FormState::consume('reps.entry.' . $repId, 'reps/' . $id),
            'settleState' => \App\Core\FormState::consume('reps.settle.' . $repId, 'reps/' . $id),
            'title'       => 'المندوب: ' . $rep['name'],
            'rep'         => $rep,
            'tab'         => $tab,
            'period'      => $period,
            'prev'        => RepAccount::shift($period, -1),
            'next'        => RepAccount::shift($period, 1),
            'isFuture'    => RepAccount::isFuture(RepAccount::shift($period, 1)),
            'head'        => RepProfile::head($repId, $period),
            'kinds'       => Rep::KINDS,
        ];

        $data += match ($tab) {
            'salary'  => ['pay'     => RepProfile::salary($repId, $period)],
            'ledger'  => ['ledger'  => Rep::ledger($repId),
                          'summary' => Rep::summary($repId)],
            'custody' => ['custody' => RepProfile::custody($repId)],
            'work'    => ['work'    => RepProfile::work($repId)],
            default   => ['card'    => RepProfile::card($repId)],
        };

        $this->render('reps/show', $data);
    }

    /**
     * تفاصيل أي رقم في الترويسة — «ممنوع رقم أصمّ» (قاعدة ⑤).
     * `what`: balance | custody | advances | net | pending
     */
    public function drill(string $id, string $what): void
    {
        $this->requireAuth();
        $repId = (int) $id;
        if (!Rep::find($repId)) { $this->notFound('المندوب ده مش موجود أو اتمسح.'); }
        if (!in_array($what, ['balance', 'custody', 'advances', 'net', 'pending'], true)) {
            $this->notFound('نوع التفاصيل ده مش موجود.');
        }

        $period = RepAccount::period(trim($_GET['at'] ?? ''));
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: private, no-store, max-age=0');
        }
        $this->render('reps/_drill', [
            'what'   => $what,
            'repId'  => $repId,
            'period' => $period,
            'rows'   => match ($what) {
                'balance'  => Rep::ledger($repId),
                'custody'  => RepCustody::available() ? RepCustody::spending($repId) : [],
                'advances' => RepAccount::advanceRows($repId, $period),
                'net'      => RepProfile::salary($repId, $period)['rows'],
                default    => RepCustody::available()
                    ? RepPortal::mySubmissions($repId, 40, 'pending') : [],
            },
            'pay'    => $what === 'net' ? RepProfile::salary($repId, $period) : null,
        ], false);
    }

    // ─────────────────────────────────────────── إنشاء / تعديل كارت
    public function create(): void
    {
        $this->requireAuth();
        $fs = \App\Core\FormState::consume('reps.create', 'reps/new');
        $this->render('reps/form', [
            'title'  => 'إضافة مندوب',
            'row'    => array_replace(
                ['name' => '', 'phone' => '', 'monthly_salary' => '', 'hire_date' => work_date(), 'status' => 'active', 'note' => ''],
                $fs['old']
            ),
            'action' => url('reps'),
            'isEdit' => false,
            'formErr'=> $fs['errors']['_form'] ?? '',
        ]);
    }

    public function store(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $data = $this->collectRep();
        if ($data['name'] === '') {
            $this->repFailure('reps.create', 'reps/new', $data, 'اسم المندوب مطلوب.');
        }
        $id = Rep::create($data);
        $this->done('تمت إضافة المندوب.', 'reps/' . $id);
    }

    /** فشل في نموذج مندوب — بيرجّع كل اللي اتكتب بدل ما يضيع. */
    private function repFailure(string $formId, string $path, array $old, string $msg): never
    {
        $this->formFailure($formId, $path, $old, ['_form' => $msg], $path);
    }

    public function edit(string $id): void
    {
        $this->requireAuth();
        $row = Rep::find((int) $id);
        if (!$row) { $this->notFound('المندوب ده مش موجود أو اتمسح.'); }
        $fs = \App\Core\FormState::consume('reps.edit.' . (int) $id, 'reps/' . $id . '/edit');
        $this->render('reps/form', [
            'title'  => 'تعديل مندوب',
            'row'    => array_replace($row, $fs['old']),
            'action' => url('reps/' . $id),
            'isEdit' => true,
            'formErr'=> $fs['errors']['_form'] ?? '',
        ]);
    }

    public function update(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        if (!Rep::find((int) $id)) { $this->fail('المندوب ده مش موجود أو اتمسح.', 'reps'); }
        $data = $this->collectRep();
        if ($data['name'] === '') {
            $this->repFailure('reps.edit.' . (int) $id, 'reps/' . $id . '/edit', $data, 'اسم المندوب مطلوب.');
        }
        Rep::update((int) $id, $data);
        flash('success', 'تم حفظ التعديلات.');
        redirect('reps/' . $id);
    }

    public function destroy(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $result = Rep::delete((int) $id);
        flash('success', $result['deleted']
            ? 'تم حذف كارت المندوب لأنه بلا أي سجل.'
            : ($result['deactivated']
                ? 'للمندوب تاريخ محفوظ؛ تم تعطيله وإيقاف دخوله بدل حذف السجل.'
                : 'المندوب غير موجود.'));
        redirect('reps');
    }

    // ─────────────────────────────────────────── بنود السجل
    public function addEntry(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $rep = Rep::find((int) $id);
        if (!$rep) { $this->fail('المندوب ده مش موجود أو اتمسح.', 'reps'); }

        $data = $this->collectEntry();
        // الكاش (سُلفة/تسوية) بس هو اللي بيدخل العهدة/الإقفال → احرس قفل اليوم عليه
        if (Rep::isCashKind($data['kind']) && ($err = Closing::guard($data['entry_date']))) {
            $this->repFailure('reps.entry.' . (int) $id, 'reps/' . $id, $data, $err);
        }
        // بعد نجاح التحقّق وقبل الكتابة — إرسال نفس الشاشة مرتين يُرفض
        // بدل أن يسجّل نفس المرتب/الخصم/السلفة مرتين في سجل المندوب.
        $this->claimForm('reps/' . $id);
        /* ⚠️ حارس «الفترة اتعتمدت» بيرمي من الموديل (قاعدة ③) — الشاشة بتخفي
           الفورم، بس الطلب المزوَّر أو التبويب القديم لازم يترفض هنا كمان. */
        try {
            Rep::createEntry((int) $id, $data);
        } catch (\RuntimeException $e) {
            $this->repFailure('reps.entry.' . (int) $id, 'reps/' . $id, $data, $e->getMessage());
        }
        $this->done('تمت إضافة البند لسجل المندوب.', 'reps/' . $id . '?tab=salary', 'entries');
    }

    public function deleteEntry(string $eid): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $entry = Rep::findEntry((int) $eid);
        if (!$entry) { $this->fail('البند ده مش موجود أو اتمسح.', 'reps'); }
        // امنع حذف كاش داخل يوم متقفل (يغيّر الرصيد المرحّل)
        if (Rep::isCashKind($entry['kind']) && ($err = Closing::guard($entry['entry_date']))) {
            $this->fail($err, 'reps/' . $entry['rep_id']);
        }
        try {
            Rep::deleteEntry((int) $eid);
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage(), 'reps/' . $entry['rep_id']);
        }
        flash('success', 'تم حذف البند.');
        redirect('reps/' . $entry['rep_id']);
    }

    // ─────────────────────────────────────────── التقفيل الدوري
    public function settle(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $rep = Rep::find((int) $id);
        if (!$rep) { $this->fail('المندوب ده مش موجود أو اتمسح.', 'reps'); }

        $from    = $this->cleanDate($_POST['period_from'] ?? '');
        $to      = $this->cleanDate($_POST['period_to'] ?? '');
        $payDate = $this->cleanDate($_POST['pay_date'] ?? '') ?: work_date();
        $isVisa  = !empty($_POST['is_visa']);
        $note    = trim($_POST['note'] ?? '');

        // دفعة التسوية كاش يخرج فعلاً → احرس قفل يوم الدفع
        $settleOld = ['period_from' => $from, 'period_to' => $to, 'pay_date' => $payDate, 'is_visa' => $isVisa ? '1' : '', 'note' => $note];
        if (!$isVisa && ($err = Closing::guard($payDate))) {
            $this->repFailure('reps.settle.' . (int) $id, 'reps/' . $id, $settleOld, $err);
        }

        $pre = Rep::settlementPreview((int) $id, $from ?: null, $to ?: null);
        if ($pre['count'] === 0) {
            $this->repFailure('reps.settle.' . (int) $id, 'reps/' . $id, $settleOld, 'مفيش بنود غير متحاسبة في الفترة دي عشان تتقفل.');
        }
        // بعد نجاح التحقّق وقبل الكتابة — تسوية مكررة تعني دفعة كاش مكررة
        $this->claimForm('reps/' . $id);
        $sid = Rep::settle((int) $id, $from ?: null, $to ?: null, $payDate, $isVisa, $note);
        $this->done('تم تقفيل الحساب على صافي ' . money($pre['net']) . '.', 'reps/' . $id . '/settlement/' . $sid . '/print');
    }

    public function settlementPrint(string $id, string $sid): void
    {
        $this->requireAuth();
        $rep = Rep::find((int) $id);
        $s   = Rep::settlement((int) $sid);
        if (!$rep || !$s || (int) $s['rep_id'] !== (int) $id) {
            $this->notFound('التقفيل ده مش موجود أو اتمسح.');
        }
        $this->render('reps/settlement_print', [
            'title'   => 'تسوية حساب المندوب',
            'rep'     => $rep,
            's'       => $s,
            'entries' => Rep::settlementEntries((int) $sid),
            'kinds'   => Rep::KINDS,
        ], false); // بلا layout — صفحة طباعة مستقلة
    }

    // ─────────────────────────────────────────── أدوات
    private function collectRep(): array
    {
        return [
            'name'           => trim($_POST['name'] ?? ''),
            'phone'          => trim($_POST['phone'] ?? ''),
            'monthly_salary' => ($_POST['monthly_salary'] ?? '') === '' ? 0 : (float) $_POST['monthly_salary'],
            'hire_date'      => $this->cleanDate($_POST['hire_date'] ?? ''),
            'status'         => ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            'note'           => trim($_POST['note'] ?? ''),
        ];
    }

    private function collectEntry(): array
    {
        return [
            'entry_date' => $this->cleanDate($_POST['entry_date'] ?? '') ?: work_date(),
            'kind'       => trim($_POST['kind'] ?? ''),
            'amount'     => ($_POST['amount'] ?? '') === '' ? 0 : (float) $_POST['amount'],
            'is_visa'    => !empty($_POST['is_visa']) ? 1 : 0,
            'note'       => trim($_POST['note'] ?? ''),
        ];
    }

    private function cleanDate($v): string
    {
        $v = trim((string) $v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    }
}
