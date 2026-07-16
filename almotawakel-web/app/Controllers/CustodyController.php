<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\Closing;
use App\Models\Custody;

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
        $this->render('custody/index', [
            'title'      => 'رصيد العهدة',
            'balance'    => Custody::balance(),
            'topups'     => Custody::topupsTotal(),
            'driverOut'  => Custody::driverCustodyOut(),
            'rate'       => Custody::deductionRate(),
            'ledger'     => Custody::ledger(),
            'breakdown'  => Closing::currentBreakdown(),
            'lastClosed' => Closing::lastClosedDate(),
        ]);
    }

    // ─────────────────────────────────────────── الإضافات اليدوية (توب-أب)

    public function storeTopup(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $data = $this->collect();
        if ($err = Closing::guard($data['cust_date'])) { flash('error', $err); redirect('custody'); }
        Custody::createTopup($data);
        flash('success', 'تمت إضافة العهدة إلى الرصيد.');
        redirect('custody');
    }

    public function editTopup(string $id): void
    {
        $this->requireAuth();
        $row = Custody::findTopup((int) $id);
        if (!$row) { flash('error', 'الإضافة غير موجودة.'); redirect('custody'); }
        $this->render('custody/topup_form', [
            'title'  => 'تعديل إضافة عهدة',
            'row'    => $row,
            'action' => url('custody/topup/' . $id),
        ]);
    }

    public function updateTopup(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $data = $this->collect();
        $old  = Custody::findTopup((int) $id);
        // امنع لو التاريخ الجديد أو القديم يقع ضمن فترة متقفلة
        if ($err = (Closing::guard($data['cust_date']) ?? ($old ? Closing::guard($old['cust_date']) : null))) {
            flash('error', $err); redirect('custody');
        }
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
        Custody::deleteTopup((int) $id);
        flash('success', 'تم حذف الإضافة.');
        redirect('custody');
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
