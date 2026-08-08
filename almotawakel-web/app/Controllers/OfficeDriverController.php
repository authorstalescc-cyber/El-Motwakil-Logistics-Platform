<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\FormState;
use App\Models\Audit;
use App\Models\OfficeCar;
use App\Models\OfficeDriverStatement;

/** شاشة حساب وبيانات سائق سيارة المكتب (v65). */
class OfficeDriverController extends Controller
{
    public function index(string $id): void
    {
        $this->requireAuth();
        $car = $this->car((int) $id);
        [$from, $to] = $this->period();
        $this->render('office_driver/index', [
            'title' => 'بيان السائق — ' . ($car['driver'] ?: $car['name']),
            'car' => $car,
            'statement' => OfficeDriverStatement::statement((int) $id, $from, $to),
            'ready' => OfficeDriverStatement::ready(),
            'moveToken' => FormState::token(),
            'issueToken' => FormState::token(),
            'issued' => OfficeDriverStatement::issued((int) $id),
        ]);
    }

    public function bill(string $id, string $bid): void
    {
        $this->requireAuth();
        $car = $this->car((int) $id);
        $bill = OfficeDriverStatement::billForCar((int) $id, (int) $bid);
        if (!$bill) { $this->notFound('البوليصة دي لا تخص السيارة المختارة.'); }
        if (!OfficeDriverStatement::ready()) {
            $this->notFound('شغّل هجرة v65 أولاً عشان تفتح بيان البوليصة.');
        }
        $back = 'cars/' . (int) $id . '/driver';
        $state = FormState::consume('office_driver.bill.' . (int) $bid, $back);
        $this->render('office_driver/bill', [
            'title' => 'بيان بوليصة ' . ($bill['bill_number'] ?: ('#' . (int) $bid)),
            'car' => $car,
            'bill' => $bill,
            'items' => OfficeDriverStatement::billItems((int) $id, $bill),
            'receipts' => OfficeDriverStatement::availableReceipts((int) $id, $bill),
            'movements' => OfficeDriverStatement::billMovements((int) $id, (int) $bid),
            'state' => $state,
            'back' => $back,
        ]);
    }

    public function saveBill(string $id, string $bid): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $car = $this->car((int) $id, true);
        $bill = OfficeDriverStatement::billForCar((int) $id, (int) $bid);
        if (!$bill) { $this->fail('البوليصة دي لا تخص السيارة المختارة.', 'cars/' . (int) $id . '/driver'); }

        $items = $this->rows(['category','description','amount'], 'item');
        $moves = $this->rows(['kind','move_date','note','amount'], 'move');
        $alloc = is_array($_POST['receipt_amount'] ?? null) ? $_POST['receipt_amount'] : [];
        $back = 'cars/' . (int) $id . '/driver/bill/' . (int) $bid;

        try {
            $this->claimForm($back);
            $saved = OfficeDriverStatement::saveBill(
                (int) $id, (int) $bid, $items, $alloc, $moves, (string) (Auth::user() ?? '')
            );
            Audit::log('update', 'office_driver_bill', (int) $bid, null, $saved,
                'حفظ بيان سائق لبوليصة ' . ($bill['bill_number'] ?: ('#' . (int) $bid)),
                ['work_date' => $bill['bill_date'] ?: work_date()]
            );
        } catch (\RuntimeException $e) {
            $this->formFailure(
                'office_driver.bill.' . (int) $bid,
                $back,
                $_POST,
                ['_form' => $e->getMessage()],
                'cars/' . (int) $id . '/driver'
            );
        }
        $this->done('اتحفظ بيان البوليصة واترحّل في حساب ' . ($car['driver'] ?: 'السائق') . '.',
            'cars/' . (int) $id . '/driver');
    }

    public function addMovement(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $car = $this->car((int) $id, true);
        $back = 'cars/' . (int) $id . '/driver';
        try {
            $this->claimForm($back);
            $mid = OfficeDriverStatement::addMovement((int) $id, [
                'move_date' => $_POST['move_date'] ?? work_date(),
                'kind' => $_POST['kind'] ?? '',
                'amount' => $_POST['amount'] ?? 0,
                'note' => $_POST['note'] ?? '',
            ], (string) (Auth::user() ?? ''));
            Audit::log('create', 'office_driver_movement', $mid, null, $_POST,
                'حركة حساب سائق: ' . ($car['driver'] ?: $car['name']),
                ['work_date' => $_POST['move_date'] ?? work_date()]
            );
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage(), $back);
        }
        $this->done('اتسجّلت الحركة في حساب السائق.', $back, 'driver-movements');
    }

    public function deleteMovement(string $id, string $mid): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $this->car((int) $id, true);
        $back = 'cars/' . (int) $id . '/driver';
        $this->claimForm($back);
        $old = OfficeDriverStatement::deleteMovement((int) $id, (int) $mid);
        if (!$old) { $this->fail('الحركة مش موجودة أو مرتبطة ببيان بوليصة.', $back); }
        Audit::log('delete', 'office_driver_movement', (int) $mid, $old, null, 'حذف حركة من حساب السائق');
        $this->done('اتشالت الحركة من حساب السائق.', $back, 'driver-movements');
    }

    public function printable(string $id): void
    {
        $this->requireAuth();
        $car = $this->car((int) $id);
        [$from, $to] = $this->period();
        $this->render('office_driver/print', [
            'title' => 'بيان السائق — ' . ($car['driver'] ?: $car['name']),
            'statement' => OfficeDriverStatement::statement((int) $id, $from, $to),
        ], false);
    }

    public function issue(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $car = $this->car((int) $id, true);
        [$from, $to] = $this->periodFromPost();
        $this->claimForm('cars/' . (int) $id . '/driver');
        try {
            $sid = OfficeDriverStatement::issue((int) $id, $from, $to, (string) (Auth::user() ?? ''));
            Audit::log('create', 'office_driver_statement', $sid, null,
                ['car_id'=>(int)$id,'from'=>$from,'to'=>$to],
                'إصدار بيان سائق: ' . ($car['driver'] ?: $car['name'])
            );
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage(), 'cars/' . (int) $id . '/driver');
        }
        $this->done('اتحفظت نسخة ثابتة من البيان.', 'cars/' . (int) $id . '/driver/print/' . $sid);
    }

    public function printSaved(string $id, string $sid): void
    {
        $this->requireAuth();
        $car = $this->car((int) $id);
        $statement = OfficeDriverStatement::issuedStatement((int) $id, (int) $sid);
        if (!$statement) { $this->notFound('نسخة البيان دي مش موجودة.'); }
        $this->render('office_driver/print', [
            'title' => 'بيان محفوظ #' . (int) $sid . ' — ' . ($car['driver'] ?: $car['name']),
            'statement' => $statement,
        ], false);
    }

    private function car(int $id, bool $post = false): array
    {
        $car = OfficeCar::find($id);
        if ($car) { return $car; }
        if ($post) { $this->fail('السيارة دي مش موجودة.', 'cars'); }
        $this->notFound('السيارة دي مش موجودة.');
    }

    private function period(): array
    {
        $anchor = work_date();
        $from = $this->date((string) ($_GET['from'] ?? '')) ?: substr($anchor, 0, 7) . '-01';
        $to = $this->date((string) ($_GET['to'] ?? '')) ?: $anchor;
        return $from <= $to ? [$from, $to] : [$to, $from];
    }

    private function periodFromPost(): array
    {
        $anchor = work_date();
        $from = $this->date((string) ($_POST['from'] ?? '')) ?: substr($anchor, 0, 7) . '-01';
        $to = $this->date((string) ($_POST['to'] ?? '')) ?: $anchor;
        return $from <= $to ? [$from, $to] : [$to, $from];
    }

    private function date(string $date): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return null; }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date ? $date : null;
    }

    /** يحوّل أعمدة المصفوفة المتوازية في POST إلى صفوف. */
    private function rows(array $columns, string $prefix): array
    {
        $count = 0;
        foreach ($columns as $column) {
            $values = $_POST[$prefix . '_' . $column] ?? [];
            if (is_array($values)) { $count = max($count, count($values)); }
        }
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $row = [];
            foreach ($columns as $column) {
                $values = $_POST[$prefix . '_' . $column] ?? [];
                $row[$column] = is_array($values) ? ($values[$i] ?? '') : '';
            }
            $rows[] = $row;
        }
        return $rows;
    }
}
