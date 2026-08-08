<?php
namespace App\Models;

use App\Calc;
use App\Core\Database;

/**
 * الحساب الجاري لسائق سيارة المكتب (v65).
 *
 * الإشارة في الحساب من وجهة نظر السائق:
 *   + عهدة استلمها / إضافة له.
 *   - مصروف دفعه / مبلغ وردّه للمكتب.
 * الرصيد الموجب = فلوس ما زالت مع السائق، والسالب = مستحق له على المكتب.
 */
class OfficeDriverStatement
{
    public const CATEGORIES = [
        'driver_share'      => 'دور السائق',
        'assistant_share'   => 'دور التباع',
        'gas'               => 'سولار / بنزين',
        'kawtsh'            => 'كاوتش',
        'z_kawtsh'          => 'ظرف كاوتش',
        'tahia_masr'        => 'تحيا مصر',
        'thabtan'           => 'ثبتان / جمرك',
        'permits'           => 'تصاريح',
        'cards'             => 'كرتات',
        'overnight_expense' => 'بياتة',
        'expense'           => 'مصروف',
        'other_expense'     => 'أخرى',
    ];

    public const MOVEMENT_KINDS = [
        'advance'           => ['label' => 'عهدة إضافية وصلت للسائق', 'sign' => 1],
        'return'            => ['label' => 'مبلغ وردّه السائق للمكتب', 'sign' => -1],
        'credit_adjustment' => ['label' => 'تسوية لصالح السائق', 'sign' => 1],
        'debit_adjustment'  => ['label' => 'تسوية على السائق', 'sign' => -1],
    ];

    private static ?bool $ready = null;
    private static ?bool $statementsReady = null;

    public static function ready(): bool
    {
        if (self::$ready === null) {
            $n = (int) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema=DATABASE()
                    AND table_name IN ('office_driver_bill_items',
                                       'office_driver_stage_allocations',
                                       'office_driver_movements')"
            );
            self::$ready = $n === 3;
        }
        return self::$ready;
    }

    public static function refreshReady(): bool
    {
        self::$ready = null;
        self::$statementsReady = null;
        return self::ready();
    }

    public static function statementsReady(): bool
    {
        if (self::$statementsReady === null) {
            self::$statementsReady = (bool) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema=DATABASE() AND table_name='office_driver_statements'"
            );
        }
        return self::$statementsReady;
    }

    /**
     * قيمة بند الإذن التي تظل على درج المكتب بعد طرح ما دفعه سائق المكتب.
     * هذا هو المصدر الوحيد لكل حسابات الخزنة والطباعة والمراجعة اليومية.
     */
    public static function stageSafeAmountSql(string $alias = 's'): string
    {
        $p = $alias === '' ? '' : $alias . '.';
        if (!self::ready()) { return "COALESCE({$p}amount,0)"; }
        return "GREATEST(COALESCE({$p}amount,0) - COALESCE(("
             . "SELECT SUM(odsa.amount) FROM office_driver_stage_allocations odsa "
             . "JOIN bawalis odb ON odb.id=odsa.bill_id "
             . "WHERE odsa.stage_id={$p}id),0),0)";
    }

    public static function stageDriverAmountSql(string $alias = 's'): string
    {
        $p = $alias === '' ? '' : $alias . '.';
        if (!self::ready()) { return '0'; }
        return "LEAST(COALESCE({$p}amount,0), COALESCE(("
             . "SELECT SUM(odsa.amount) FROM office_driver_stage_allocations odsa "
             . "JOIN bawalis odb ON odb.id=odsa.bill_id "
             . "WHERE odsa.stage_id={$p}id),0))";
    }

    public static function stageAllocationTotal(int $stageId): float
    {
        if (!self::ready() || $stageId <= 0) { return 0.0; }
        return round((float) Database::scalar(
            'SELECT COALESCE(SUM(a.amount),0)
               FROM office_driver_stage_allocations a
               JOIN bawalis b ON b.id=a.bill_id
              WHERE a.stage_id=?', [$stageId]
        ), 2);
    }

    public static function billAllocationTotal(int $billId): float
    {
        if (!self::ready() || $billId <= 0) { return 0.0; }
        return round((float) Database::scalar(
            'SELECT COALESCE(SUM(amount),0) FROM office_driver_stage_allocations WHERE bill_id=?',
            [$billId]
        ), 2);
    }

    public static function movementAllocationTotal(int $movementId): float
    {
        if (!self::ready() || $movementId <= 0) { return 0.0; }
        return round((float) Database::scalar(
            'SELECT COALESCE(SUM(a.amount),0)
               FROM office_driver_stage_allocations a
               JOIN permit_stages s ON s.id=a.stage_id
              WHERE s.movement_id=?', [$movementId]
        ), 2);
    }

    public static function permitAllocationTotal(int $permitId): float
    {
        if (!self::ready() || $permitId <= 0) { return 0.0; }
        return round((float) Database::scalar(
            'SELECT COALESCE(SUM(a.amount),0)
               FROM office_driver_stage_allocations a
               JOIN permit_stages s ON s.id=a.stage_id
              WHERE s.permit_id=?', [$permitId]
        ), 2);
    }

    public static function billForCar(int $carId, int $billId): ?array
    {
        if ($carId <= 0 || $billId <= 0) { return null; }
        $bill = Database::row(
            'SELECT b.*,p.port,p.agency,p.shipping_co,p.client
               FROM bawalis b LEFT JOIN permits p ON p.id=b.permit_id
              WHERE b.id=? AND b.is_legacy=0', [$billId]
        );
        if (!$bill) { return null; }
        $matched = OfficeCar::matchPlate((string) ($bill['car_no'] ?? ''));
        return $matched && (int) $matched['id'] === $carId ? $bill : null;
    }

    /** تفاصيل محفوظة؛ ولو البوليصة قديمة نولّد سطرًا من كل عمود غير صفري. */
    public static function billItems(int $carId, array $bill): array
    {
        $rows = self::ready() ? Database::all(
            'SELECT * FROM office_driver_bill_items
              WHERE car_id=? AND bill_id=? ORDER BY sort_order,id',
            [$carId, (int) $bill['id']]
        ) : [];
        if ($rows) { return $rows; }

        $out = [];
        foreach (self::CATEGORIES as $key => $label) {
            $amount = round((float) ($bill[$key] ?? 0), 2);
            if (abs($amount) < 0.005) { continue; }
            $out[] = [
                'id' => 0, 'car_id' => $carId, 'bill_id' => (int) $bill['id'],
                'category' => $key, 'description' => $label, 'amount' => $amount,
            ];
        }
        return $out;
    }

    public static function billMovements(int $carId, int $billId): array
    {
        if (!self::ready()) { return []; }
        return Database::all(
            'SELECT * FROM office_driver_movements
              WHERE car_id=? AND bill_id=? ORDER BY move_date,id', [$carId, $billId]
        );
    }

    /**
     * الإيصالات النقدية الممكن ربطها بالبوليصة: نفس الحركة، أو بند عام على
     * نفس الإذن. المبلغ المتاح يحترم تخصيصات البوالص الأخرى.
     */
    public static function availableReceipts(int $carId, array $bill): array
    {
        if (!self::ready() || empty($bill['permit_id'])) { return []; }
        $exp = Permit::expenseSql('s');
        $cash = Permit::cashOutSql('s');
        $unpaid = Permit::unpaidReady() ? 's.unpaid=0' : '1';
        $movement = (int) ($bill['movement_id'] ?? 0);
        $scope = $movement > 0
            ? '(s.movement_id=? OR (s.movement_id IS NULL AND s.permit_id=?))'
            : '(s.movement_id IS NULL AND s.permit_id=?)';
        $params = $movement > 0
            ? [$carId, (int) $bill['id'], $movement, (int) $bill['permit_id']]
            : [$carId, (int) $bill['id'], (int) $bill['permit_id']];

        return Database::all(
            "SELECT s.id,s.entry_date,s.note,s.ref_no,s.amount,s.stage_key,
                    p.permit_no,
                    COALESCE((SELECT SUM(a.amount) FROM office_driver_stage_allocations a
                              WHERE a.stage_id=s.id),0) AS allocated_total,
                    COALESCE((SELECT a.amount FROM office_driver_stage_allocations a
                              WHERE a.stage_id=s.id AND a.car_id=? AND a.bill_id=?),0) AS this_amount
               FROM permit_stages s JOIN permits p ON p.id=s.permit_id
              WHERE {$scope} AND {$exp} AND {$cash} AND {$unpaid}
                AND s.paid_by_rep_id IS NULL AND s.amount>0
              ORDER BY s.entry_date,s.id",
            $params
        );
    }

    /** حفظ بيان بوليصة كامل داخل transaction واحدة. */
    public static function saveBill(
        int $carId,
        int $billId,
        array $items,
        array $allocations,
        array $movements,
        string $user
    ): array {
        if (!self::ready()) { throw new \RuntimeException('شغّل migrate_v65.php أولاً.'); }
        $bill = self::billForCar($carId, $billId);
        if (!$bill) { throw new \RuntimeException('البوليصة لا تخص السيارة دي.'); }

        $cleanItems = [];
        foreach ($items as $i => $row) {
            $category = (string) ($row['category'] ?? '');
            $amount = round((float) ($row['amount'] ?? 0), 2);
            if ($amount <= 0) { continue; }
            if (!isset(self::CATEGORIES[$category])) {
                throw new \RuntimeException('نوع مصروف غير صحيح في السطر ' . ($i + 1) . '.');
            }
            $cleanItems[] = [
                'category' => $category,
                'description' => mb_substr(trim((string) ($row['description'] ?? '')), 0, 190),
                'amount' => $amount,
            ];
        }

        $receiptMap = [];
        foreach (self::availableReceipts($carId, $bill) as $r) {
            $receiptMap[(int) $r['id']] = $r;
        }
        $cleanAlloc = [];
        foreach ($allocations as $stageId => $amountRaw) {
            $stageId = (int) $stageId;
            $amount = round((float) $amountRaw, 2);
            if ($amount <= 0) { continue; }
            $r = $receiptMap[$stageId] ?? null;
            if (!$r) { throw new \RuntimeException('إيصال غير متاح أو لا يخص البوليصة.'); }
            $max = round((float) $r['amount'] - (float) $r['allocated_total'] + (float) $r['this_amount'], 2);
            if ($amount - $max > 0.005) {
                throw new \RuntimeException('المبلغ المخصّص على «' . ($r['note'] ?: 'إيصال') . '» أكبر من المتاح.');
            }
            if ($err = Closing::guard((string) $r['entry_date'])) {
                throw new \RuntimeException($err);
            }
            $cleanAlloc[$stageId] = $amount;
        }

        $oldAlloc = Database::all(
            'SELECT a.stage_id,s.entry_date FROM office_driver_stage_allocations a
              JOIN permit_stages s ON s.id=a.stage_id
             WHERE a.car_id=? AND a.bill_id=?', [$carId, $billId]
        );
        foreach ($oldAlloc as $r) {
            if ($err = Closing::guard((string) $r['entry_date'])) {
                throw new \RuntimeException($err);
            }
        }

        $cleanMoves = [];
        foreach ($movements as $i => $row) {
            $kind = (string) ($row['kind'] ?? '');
            $amount = round((float) ($row['amount'] ?? 0), 2);
            if ($amount <= 0) { continue; }
            if (!isset(self::MOVEMENT_KINDS[$kind])) {
                throw new \RuntimeException('نوع حركة غير صحيح في السطر ' . ($i + 1) . '.');
            }
            $date = self::cleanDate((string) ($row['move_date'] ?? ''))
                ?: ((string) ($bill['bill_date'] ?? '') ?: work_date());
            $cleanMoves[] = [
                'kind' => $kind, 'amount' => $amount, 'move_date' => $date,
                'note' => mb_substr(trim((string) ($row['note'] ?? '')), 0, 190),
            ];
        }

        $pdo = Database::conn();
        $own = !$pdo->inTransaction();
        if ($own) { $pdo->beginTransaction(); }
        try {
            Database::exec('DELETE FROM office_driver_bill_items WHERE car_id=? AND bill_id=?', [$carId, $billId]);
            $totals = array_fill_keys(array_keys(self::CATEGORIES), 0.0);
            foreach ($cleanItems as $i => $row) {
                Database::exec(
                    'INSERT INTO office_driver_bill_items
                         (car_id,bill_id,category,description,amount,sort_order,created_by)
                     VALUES (?,?,?,?,?,?,?)',
                    [$carId,$billId,$row['category'],$row['description'] ?: null,$row['amount'],$i,$user ?: null]
                );
                $totals[$row['category']] += $row['amount'];
            }

            if (array_keys(self::CATEGORIES) !== Calc::EXPENSE_COLS) {
                throw new \RuntimeException('تصنيفات بيان السائق لا تطابق أعمدة مصروفات البوليصة.');
            }
            $sets = []; $params = [];
            foreach (Calc::EXPENSE_COLS as $column) {
                $sets[] = "`{$column}`=?";
                $params[] = round($totals[$column], 2);
            }
            /* ⚠️⚠️ الأعمدة التلاتة دي **مخزَّنة في `bawalis` مش محسوبة تلقائيًا** —
               المسار العادي بيملاها عبر `Calc::apply($ent['calc'], …)`، وبيان السائق
               بيتخطّاه. لو اتسابت: شاشة البوليصة بتقول «إجمال المصروف = ٠» والبنود
               مليانة، وتقرير أداء الشركات بيقرا `car_net` الخام من `bawalis`
               (`Metrics::companyPerformance`) فيدّي صافي غلط — **بلا أي خطأ ظاهر**.
               المصدر الوحيد للمعادلة `Calc::bawala` (تحذير ٦: المعادلة في
               `Calc.php` + `build_system.py` + `app.js`) — ممنوع تكتبها هنا بإيدك.
               و`debt` **برّه القايمة عن قصد**: بنود مصروف السيارة مالهاش أي أثر على
               حساب العميل (القاعدة الموثَّقة)، فما بنكتبش عمود الدين من هنا خالص. */
            $derived = Calc::bawala(array_merge($bill, $totals));
            foreach (['total_expense', 'custody_remaining', 'car_net'] as $column) {
                $sets[] = "`{$column}`=?";
                $params[] = round((float) $derived[$column], 2);
            }
            $params[] = $billId;
            Database::exec('UPDATE bawalis SET ' . implode(',', $sets) . ' WHERE id=?', $params);

            Database::exec(
                'DELETE FROM office_driver_stage_allocations WHERE car_id=? AND bill_id=?',
                [$carId, $billId]
            );
            foreach ($cleanAlloc as $stageId => $amount) {
                Database::exec(
                    'INSERT INTO office_driver_stage_allocations
                         (car_id,bill_id,stage_id,amount,created_by)
                     VALUES (?,?,?,?,?)',
                    [$carId,$billId,$stageId,$amount,$user ?: null]
                );
            }

            Database::exec(
                'DELETE FROM office_driver_movements WHERE car_id=? AND bill_id=?',
                [$carId, $billId]
            );
            foreach ($cleanMoves as $row) {
                Database::exec(
                    'INSERT INTO office_driver_movements
                         (car_id,bill_id,move_date,kind,amount,note,created_by)
                     VALUES (?,?,?,?,?,?,?)',
                    [$carId,$billId,$row['move_date'],$row['kind'],$row['amount'],$row['note'] ?: null,$user ?: null]
                );
            }
            if ($own) { $pdo->commit(); }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }

        return [
            'items' => $cleanItems,
            'allocations' => $cleanAlloc,
            'movements' => $cleanMoves,
        ];
    }

    public static function addMovement(int $carId, array $d, string $user): int
    {
        if (!self::ready()) { throw new \RuntimeException('شغّل migrate_v65.php أولاً.'); }
        if (!OfficeCar::find($carId)) { throw new \RuntimeException('السيارة غير موجودة.'); }
        $kind = (string) ($d['kind'] ?? '');
        if (!isset(self::MOVEMENT_KINDS[$kind])) { throw new \RuntimeException('اختار نوع الحركة.'); }
        $amount = round((float) ($d['amount'] ?? 0), 2);
        if ($amount <= 0) { throw new \RuntimeException('اكتب مبلغًا أكبر من صفر.'); }
        $date = self::cleanDate((string) ($d['move_date'] ?? '')) ?: work_date();
        Database::exec(
            'INSERT INTO office_driver_movements
                 (car_id,bill_id,move_date,kind,amount,note,created_by)
             VALUES (?,NULL,?,?,?,?,?)',
            [$carId,$date,$kind,$amount,mb_substr(trim((string) ($d['note'] ?? '')),0,190) ?: null,$user ?: null]
        );
        return Database::lastId();
    }

    public static function deleteMovement(int $carId, int $id): ?array
    {
        if (!self::ready()) { return null; }
        $row = Database::row(
            'SELECT * FROM office_driver_movements WHERE id=? AND car_id=? AND bill_id IS NULL',
            [$id, $carId]
        );
        if (!$row) { return null; }
        Database::exec('DELETE FROM office_driver_movements WHERE id=?', [$id]);
        return $row;
    }

    /**
     * بيان فترة مع رصيد افتتاحي وترحيل بعد كل نقلة.
     * البنود المخصّصة من الإيصالات تُحسب فقط طالما الإيصال نقدي وغير منسوب لمندوب.
     */
    public static function statement(int $carId, string $from, string $to): array
    {
        $car = OfficeCar::find($carId);
        if (!$car) { throw new \RuntimeException('السيارة غير موجودة.'); }
        if ($from > $to) { [$from, $to] = [$to, $from]; }

        $bills = OfficeCar::trips($carId, null, $to);
        usort($bills, static fn($a, $b) =>
            (($a['bill_date'] ?? '9999-99-99') <=> ($b['bill_date'] ?? '9999-99-99'))
            ?: ((int) $a['id'] <=> (int) $b['id'])
        );
        $opening = 0.0; $rows = [];
        $totals = ['custody'=>0.0,'additions'=>0.0,'returns'=>0.0,'expenses'=>0.0,'receipts'=>0.0];

        foreach ($bills as $bill) {
            $date = (string) ($bill['bill_date'] ?? '');
            if ($date === '' || $date > $to) { continue; }
            $items = self::billItems($carId, $bill);
            $itemTotal = round(array_sum(array_map(static fn($r) => (float) $r['amount'], $items)), 2);
            $allocs = self::allocationsForBill($carId, (int) $bill['id']);
            $receiptTotal = round(array_sum(array_map(static fn($r) => (float) $r['amount'], $allocs)), 2);
            $moves = self::billMovements($carId, (int) $bill['id']);
            $additions = 0.0; $returns = 0.0;
            foreach ($moves as $m) {
                $signed = self::movementSigned($m);
                if ($signed >= 0) { $additions += $signed; } else { $returns += abs($signed); }
            }
            $custody = round(Custody::billDeduction((float) ($bill['custody'] ?? 0), $bill['car_no'] ?? null), 2);
            $net = round($custody + $additions - $returns - $itemTotal - $receiptTotal, 2);
            if ($date < $from) { $opening += $net; continue; }

            $rows[] = [
                'kind'=>'bill','date'=>$date,'bill'=>$bill,'items'=>$items,'allocations'=>$allocs,
                'movements'=>$moves,'custody'=>$custody,'additions'=>round($additions,2),
                'returns'=>round($returns,2),'expenses'=>$itemTotal,'receipts'=>$receiptTotal,'net'=>$net,
            ];
            $totals['custody'] += $custody;
            $totals['additions'] += $additions;
            $totals['returns'] += $returns;
            $totals['expenses'] += $itemTotal;
            $totals['receipts'] += $receiptTotal;
        }

        if (self::ready()) {
            $general = Database::all(
                'SELECT * FROM office_driver_movements
                  WHERE car_id=? AND bill_id IS NULL AND move_date<=?
                  ORDER BY move_date,id', [$carId,$to]
            );
            foreach ($general as $m) {
                $signed = self::movementSigned($m);
                if ((string) $m['move_date'] < $from) { $opening += $signed; continue; }
                $rows[] = [
                    'kind'=>'movement','date'=>$m['move_date'],'movement'=>$m,
                    'custody'=>0.0,'additions'=>$signed > 0 ? $signed : 0.0,
                    'returns'=>$signed < 0 ? abs($signed) : 0.0,
                    'expenses'=>0.0,'receipts'=>0.0,'net'=>$signed,
                ];
                if ($signed > 0) { $totals['additions'] += $signed; }
                else { $totals['returns'] += abs($signed); }
            }
        }
        usort($rows, static fn($a,$b) => ($a['date'] <=> $b['date'])
            ?: (($a['kind'] === 'movement' ? 1 : 0) <=> ($b['kind'] === 'movement' ? 1 : 0)));

        $running = round($opening, 2);
        foreach ($rows as &$row) {
            $running = round($running + $row['net'], 2);
            $row['running'] = $running;
        }
        unset($row);
        foreach ($totals as $k => $v) { $totals[$k] = round($v, 2); }

        return [
            'car'=>$car,'driver'=>trim((string) ($car['driver'] ?? '')) ?: 'سائق السيارة',
            'from'=>$from,'to'=>$to,'opening'=>round($opening,2),'rows'=>$rows,
            'totals'=>$totals,'closing'=>$running,
        ];
    }

    /** يحفظ لقطة ثابتة؛ الإصدار نفسه لا يحرّك الرصيد. */
    public static function issue(int $carId, string $from, string $to, string $user): int
    {
        if (!self::ready() || !self::statementsReady()) { throw new \RuntimeException('شغّل migrate_v65.php أولاً.'); }
        $statement = self::statement($carId, $from, $to);
        $totals = json_encode($statement['totals'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $items = json_encode($statement, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($totals === false || $items === false) {
            throw new \RuntimeException('تعذّر تجهيز نسخة البيان للحفظ.');
        }
        Database::exec(
            'INSERT INTO office_driver_statements
                 (car_id,from_date,to_date,opening,closing,totals_json,items_json,created_by)
             VALUES (?,?,?,?,?,?,?,?)',
            [$carId,$statement['from'],$statement['to'],$statement['opening'],$statement['closing'],
             $totals,$items,$user ?: null]
        );
        return Database::lastId();
    }

    public static function issued(int $carId, int $limit = 20): array
    {
        if (!self::statementsReady()) { return []; }
        return Database::all(
            'SELECT id,car_id,from_date,to_date,opening,closing,created_by,created_at
               FROM office_driver_statements WHERE car_id=?
              ORDER BY id DESC LIMIT '.max(1,min(100,$limit)), [$carId]
        );
    }

    public static function issuedStatement(int $carId, int $statementId): ?array
    {
        if (!self::statementsReady()) { return null; }
        $row = Database::row(
            'SELECT * FROM office_driver_statements WHERE id=? AND car_id=?',
            [$statementId,$carId]
        );
        if (!$row) { return null; }
        $statement = json_decode((string) $row['items_json'], true);
        if (!is_array($statement)) { return null; }
        $statement['_issued'] = [
            'id'=>(int)$row['id'],'created_by'=>$row['created_by'],'created_at'=>$row['created_at'],
        ];
        return $statement;
    }

    private static function allocationsForBill(int $carId, int $billId): array
    {
        if (!self::ready()) { return []; }
        $cash = Permit::cashOutSql('s');
        $exp = Permit::expenseSql('s');
        return Database::all(
            "SELECT a.*,s.entry_date,s.note AS receipt_note,s.ref_no,s.stage_key,
                    p.permit_no
               FROM office_driver_stage_allocations a
               JOIN permit_stages s ON s.id=a.stage_id
               JOIN permits p ON p.id=s.permit_id
              WHERE a.car_id=? AND a.bill_id=? AND {$exp} AND {$cash}
                AND s.paid_by_rep_id IS NULL
              ORDER BY s.entry_date,a.id",
            [$carId,$billId]
        );
    }

    public static function movementSigned(array $row): float
    {
        $kind = (string) ($row['kind'] ?? '');
        $sign = self::MOVEMENT_KINDS[$kind]['sign'] ?? 0;
        return round($sign * (float) ($row['amount'] ?? 0), 2);
    }

    public static function movementLabel(string $kind): string
    {
        return self::MOVEMENT_KINDS[$kind]['label'] ?? 'حركة حساب';
    }

    private static function cleanDate(string $date): ?string
    {
        $date = trim($date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return null; }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $dt && $dt->format('Y-m-d') === $date ? $date : null;
    }
}
