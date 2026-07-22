<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\Closing;
use App\Models\Rep;

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

    // ─────────────────────────────────────────── كارت المندوب (بروفايل + كشف)
    public function show(string $id): void
    {
        $this->requireAuth();
        $rep = Rep::find((int) $id);
        if (!$rep) { flash('error', 'المندوب غير موجود.'); redirect('reps'); }

        $this->render('reps/show', [
            'title'       => 'المندوب: ' . $rep['name'],
            'rep'         => $rep,
            'summary'     => Rep::summary((int) $id),
            'ledger'      => Rep::ledger((int) $id),
            'settlements' => Rep::settlements((int) $id),
            'kinds'       => Rep::KINDS,
        ]);
    }

    // ─────────────────────────────────────────── إنشاء / تعديل كارت
    public function create(): void
    {
        $this->requireAuth();
        $this->render('reps/form', [
            'title'  => 'إضافة مندوب',
            'row'    => ['name' => '', 'phone' => '', 'monthly_salary' => '', 'hire_date' => work_date(), 'status' => 'active', 'note' => ''],
            'action' => url('reps'),
            'isEdit' => false,
        ]);
    }

    public function store(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $data = $this->collectRep();
        if ($data['name'] === '') { flash('error', 'اسم المندوب مطلوب.'); redirect('reps/new'); }
        $id = Rep::create($data);
        flash('success', 'تمت إضافة المندوب.');
        redirect('reps/' . $id);
    }

    public function edit(string $id): void
    {
        $this->requireAuth();
        $row = Rep::find((int) $id);
        if (!$row) { flash('error', 'المندوب غير موجود.'); redirect('reps'); }
        $this->render('reps/form', [
            'title'  => 'تعديل مندوب',
            'row'    => $row,
            'action' => url('reps/' . $id),
            'isEdit' => true,
        ]);
    }

    public function update(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        if (!Rep::find((int) $id)) { flash('error', 'المندوب غير موجود.'); redirect('reps'); }
        $data = $this->collectRep();
        if ($data['name'] === '') { flash('error', 'اسم المندوب مطلوب.'); redirect('reps/' . $id . '/edit'); }
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
        if (!$rep) { flash('error', 'المندوب غير موجود.'); redirect('reps'); }

        $data = $this->collectEntry();
        // الكاش (سُلفة/تسوية) بس هو اللي بيدخل العهدة/الإقفال → احرس قفل اليوم عليه
        if (Rep::isCashKind($data['kind']) && ($err = Closing::guard($data['entry_date']))) {
            flash('error', $err); redirect('reps/' . $id);
        }
        Rep::createEntry((int) $id, $data);
        flash('success', 'تمت إضافة البند لسجل المندوب.');
        redirect('reps/' . $id);
    }

    public function deleteEntry(string $eid): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $entry = Rep::findEntry((int) $eid);
        if (!$entry) { flash('error', 'البند غير موجود.'); redirect('reps'); }
        // امنع حذف كاش داخل يوم متقفل (يغيّر الرصيد المرحّل)
        if (Rep::isCashKind($entry['kind']) && ($err = Closing::guard($entry['entry_date']))) {
            flash('error', $err); redirect('reps/' . $entry['rep_id']);
        }
        Rep::deleteEntry((int) $eid);
        flash('success', 'تم حذف البند.');
        redirect('reps/' . $entry['rep_id']);
    }

    // ─────────────────────────────────────────── التقفيل الدوري
    public function settle(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $rep = Rep::find((int) $id);
        if (!$rep) { flash('error', 'المندوب غير موجود.'); redirect('reps'); }

        $from    = $this->cleanDate($_POST['period_from'] ?? '');
        $to      = $this->cleanDate($_POST['period_to'] ?? '');
        $payDate = $this->cleanDate($_POST['pay_date'] ?? '') ?: work_date();
        $isVisa  = !empty($_POST['is_visa']);
        $note    = trim($_POST['note'] ?? '');

        // دفعة التسوية كاش يخرج فعلاً → احرس قفل يوم الدفع
        if (!$isVisa && ($err = Closing::guard($payDate))) { flash('error', $err); redirect('reps/' . $id); }

        $pre = Rep::settlementPreview((int) $id, $from ?: null, $to ?: null);
        if ($pre['count'] === 0) {
            flash('error', 'مفيش بنود غير متحاسبة في الفترة دي للتقفيل.');
            redirect('reps/' . $id);
        }
        $sid = Rep::settle((int) $id, $from ?: null, $to ?: null, $payDate, $isVisa, $note);
        flash('success', 'تم تقفيل الحساب على صافي ' . money($pre['net']) . '.');
        redirect('reps/' . $id . '/settlement/' . $sid . '/print');
    }

    public function settlementPrint(string $id, string $sid): void
    {
        $this->requireAuth();
        $rep = Rep::find((int) $id);
        $s   = Rep::settlement((int) $sid);
        if (!$rep || !$s || (int) $s['rep_id'] !== (int) $id) {
            flash('error', 'التقفيل غير موجود.'); redirect('reps/' . $id);
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
