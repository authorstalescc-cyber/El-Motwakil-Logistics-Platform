<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Models\Movement;
use App\Models\Lookup;

class MovementController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();
        $q      = trim($_GET['q'] ?? '');
        $driver = trim($_GET['driver'] ?? '');
        // افتراضياً يعرض حركات اليوم؛ يمكن مسح التاريخ لعرض الكل
        $date   = array_key_exists('date', $_GET) ? trim($_GET['date']) : work_date();

        $conds = []; $params = [];
        if ($q !== '') {
            $conds[] = '(b.bill_number LIKE ? OR m.driver LIKE ? OR m.car_owner LIKE ? OR m.shipping_co LIKE ?)';
            array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
        }
        if ($driver !== '') { $conds[] = 'm.driver = ?'; $params[] = $driver; }
        if ($date !== '')   { $conds[] = 'm.movement_date = ?'; $params[] = $date; }
        $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

        $rows = Database::all(
            "SELECT m.*, b.bill_number, b.company, b.destination
             FROM movements m LEFT JOIN bawalis b ON b.id = m.bill_id
             $where ORDER BY m.movement_date DESC, m.id DESC LIMIT 300",
            $params
        );
        $total = (int) Database::scalar("SELECT COUNT(*) FROM movements m LEFT JOIN bawalis b ON b.id=m.bill_id $where", $params);

        $this->render('movements/index', [
            'title'   => 'الحركات',
            'rows'    => $rows,
            'total'   => $total,
            'q'=>$q, 'driver'=>$driver, 'date'=>$date,
            'drivers' => Lookup::ofType('driver'),
        ]);
    }

    public function create(): void
    {
        $this->requireAuth();
        $this->form(null);
    }

    public function edit(string $id): void
    {
        $this->requireAuth();
        $mv = Movement::find((int)$id);
        if (!$mv) { $this->notFound('الحركة دي مش موجودة أو اتمسحت.'); }
        $this->form($mv);
    }

    private function form(?array $mv): void
    {
        $ent = ['route'=>'movements'];
        $billNumber = '';
        if ($mv && $mv['bill_id']) {
            $billNumber = (string) Database::scalar('SELECT bill_number FROM bawalis WHERE id=?', [$mv['bill_id']]);
        }
        $this->render('movements/form', [
            'title'    => $mv ? 'تعديل حركة' : 'إضافة حركة',
            'mv'       => $mv ?? ['movement_date'=>work_date(),'driver'=>'','car_owner'=>'','shipping_co'=>'','agency'=>'','port'=>'','note'=>''],
            'items'    => $mv ? Movement::items((int)$mv['id']) : [],
            'billNumber'=> $billNumber,
            'action'   => $mv ? url('movements/'.$mv['id']) : url('movements'),
            'isEdit'   => (bool)$mv,
            'lookups'  => Lookup::allGrouped(),
        ]);
    }

    public function store(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $id = $this->save(null);
        $this->done('تمت إضافة الحركة.', 'movements', 'mv-' . $id);
    }

    public function update(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $this->save((int)$id);
        $this->done('تم حفظ الحركة.', 'movements', 'mv-' . (int)$id);
    }

    private function save(?int $id): int
    {
        $billId = null;
        $billNumber = trim($_POST['bill_number'] ?? '');
        if ($billNumber !== '') {
            // البوالص القديمة معزولة ولا يجوز إنشاء/ربط حركة بها.
            $billId = Database::scalar(
                'SELECT id FROM bawalis WHERE is_legacy=0 AND bill_number = ? ORDER BY id DESC LIMIT 1',
                [$billNumber]
            );
            $billId = $billId ? (int)$billId : null;
        }
        $data = [
            'movement_date' => $_POST['movement_date'] ?: null,
            'bill_id'       => $billId,
            'driver'        => trim($_POST['driver'] ?? '') ?: null,
            'car_owner'     => trim($_POST['car_owner'] ?? '') ?: null,
            'shipping_co'   => trim($_POST['shipping_co'] ?? '') ?: null,
            'agency'        => trim($_POST['agency'] ?? '') ?: null,
            'port'          => trim($_POST['port'] ?? '') ?: null,
            'note'          => trim($_POST['note'] ?? '') ?: null,
        ];
        if ($id === null) {
            $cols = array_keys($data);
            Database::exec(
                "INSERT INTO movements (`".implode('`,`',$cols)."`) VALUES (".implode(',', array_map(fn($c)=>":$c",$cols)).")",
                array_combine(array_map(fn($c)=>":$c",$cols), array_values($data))
            );
            $id = Database::lastId();
        } else {
            $sets = implode(',', array_map(fn($c)=>"`$c`=:$c", array_keys($data)));
            $params = array_combine(array_map(fn($c)=>":$c",array_keys($data)), array_values($data));
            $params[':id'] = $id;
            Database::exec("UPDATE movements SET $sets WHERE id=:id", $params);
        }
        // البنود
        Movement::saveItems(
            $id,
            $_POST['it_type'] ?? [],
            $_POST['it_category'] ?? [],
            $_POST['it_amount'] ?? [],
            $_POST['it_note'] ?? [],
            $_POST['it_visa'] ?? [],
            $_POST['it_card'] ?? []
        );
        // تعلّم الفئات الجديدة
        foreach (($_POST['it_type'] ?? []) as $i => $t) {
            $cat = trim(($_POST['it_category'][$i] ?? ''));
            if ($cat !== '' && isset(Movement::TYPE_LOOKUP[$t])) {
                Lookup::add(Movement::TYPE_LOOKUP[$t], $cat);
            }
        }
        return $id;
    }

    public function destroy(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        Database::exec('DELETE FROM movements WHERE id = ?', [(int)$id]);
        flash('success', 'تم حذف الحركة.');
        redirect('movements');
    }
}
