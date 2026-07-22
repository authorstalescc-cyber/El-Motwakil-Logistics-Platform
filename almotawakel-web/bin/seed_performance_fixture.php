<?php
/**
 * يبني عينة أداء حتمية ومجهّلة داخل قاعدة اختبار فارغة فقط.
 *
 * الاستخدام:
 *   ALMOTAWAKEL_TEST_DB=almotawakel_test_xxxxxx php bin/seed_performance_fixture.php \
 *     --size=medium --confirm-database=almotawakel_test_xxxxxx
 *
 * لا يحذف هذا السكربت أي صف. إذا وجد بيانات أعمال سابقة يرفض التشغيل، ولذلك
 * يُستخدم بعد تثبيت قاعدة اختبار جديدة مباشرة.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Database;

/** @return array<string,string> */
function fixtureOptions(array $arguments): array
{
    array_shift($arguments);
    $options = [];
    foreach ($arguments as $argument) {
        if ($argument === '--help') { $options['help'] = '1'; continue; }
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            throw new RuntimeException("معامل غير معروف: $argument");
        }
        [$name, $value] = explode('=', substr($argument, 2), 2);
        if (!in_array($name, ['size', 'confirm-database'], true)) {
            throw new RuntimeException("خيار غير معروف: --$name");
        }
        $options[$name] = $value;
    }
    return $options;
}

function fixtureUsage(): void
{
    echo <<<'TXT'
الاستخدام:
  ALMOTAWAKEL_TEST_DB=almotawakel_test_<suffix> php bin/seed_performance_fixture.php \
    --size=small|medium|large --confirm-database=almotawakel_test_<suffix>

الأمان:
  • يعمل من CLI فقط وعلى اسم قاعدة اختبار مطابق almotawakel_test_*.
  • يتطلب تأكيد اسم القاعدة حرفيًا.
  • يرفض قاعدة فيها أي بيانات أعمال، ولا يحذف أي صف موجود.
TXT;
    echo PHP_EOL;
}

function fixtureDate(int $index, int $window = 180): string
{
    $days = $index % $window;
    return (new DateTimeImmutable('2026-07-20'))->modify("-$days days")->format('Y-m-d');
}

try {
    if (PHP_SAPI !== 'cli') { throw new RuntimeException('مولّد العينة يعمل من CLI فقط.'); }
    $options = fixtureOptions($argv);
    if (isset($options['help'])) { fixtureUsage(); exit(0); }

    $sizes = [
        'small'  => ['permits'=>50,   'agents'=>10, 'companies'=>8,  'reps'=>10],
        'medium' => ['permits'=>500,  'agents'=>40, 'companies'=>24, 'reps'=>50],
        'large'  => ['permits'=>3000, 'agents'=>120,'companies'=>60, 'reps'=>200],
    ];
    $size = $options['size'] ?? '';
    if (!isset($sizes[$size])) {
        throw new RuntimeException('اختر حجمًا صريحًا: --size=small أو medium أو large.');
    }

    $database = (string) (App::config('db')['name'] ?? '');
    if (!preg_match('/^almotawakel_test_[a-z0-9_]{6,48}$/', $database)) {
        throw new RuntimeException('حارس DSN: هذه ليست قاعدة اختبار معزولة.');
    }
    if (($options['confirm-database'] ?? '') !== $database) {
        throw new RuntimeException("أعد التشغيل مع --confirm-database=$database");
    }
    if ((string) Database::scalar('SELECT DATABASE()') !== $database) {
        throw new RuntimeException('قاعدة الاتصال الفعلية لا تطابق القاعدة المؤكدة.');
    }

    // الأدلة المرجعية (agents/companies) قد تُبنى من lookups أثناء التثبيت؛
    // لا تُعد بيانات تشغيلية، ونضيف إليها أسماء __perf_ مميزة بلا حذف أو تعديل.
    $businessTables = [
        'permits', 'permit_movements', 'bawalis', 'permit_stages', 'permit_invoices',
        'manual_expenses', 'client_payments', 'reps',
    ];
    $occupied = [];
    foreach ($businessTables as $table) {
        $count = (int) Database::scalar("SELECT COUNT(*) FROM `$table`");
        if ($count > 0) { $occupied[$table] = $count; }
    }
    if ($occupied) {
        $details = implode(', ', array_map(
            static fn(string $table, int $count): string => "$table=$count",
            array_keys($occupied), array_values($occupied)
        ));
        throw new RuntimeException("العينة تتطلب قاعدة أعمال فارغة؛ الموجود: $details");
    }

    $shape = $sizes[$size];
    $pdo = Database::conn();
    $pdo->beginTransaction();
    try {
        $agentIds = [];
        $insertAgent = $pdo->prepare(
            'INSERT INTO agents(name,normalized_name,note,payment_terms_days) VALUES(?,?,?,?)'
        );
        for ($i = 1; $i <= $shape['agents']; $i++) {
            $name = sprintf('__perf_agent_%03d', $i);
            $insertAgent->execute([$name, $name, 'performance fixture', 15 + ($i % 4) * 15]);
            $agentIds[] = (int) $pdo->lastInsertId();
        }

        $companyIds = [];
        $insertCompany = $pdo->prepare(
            'INSERT INTO companies(name,normalized_name,note) VALUES(?,?,?)'
        );
        for ($i = 1; $i <= $shape['companies']; $i++) {
            $name = sprintf('__perf_company_%03d', $i);
            $insertCompany->execute([$name, $name, 'performance fixture']);
            $companyIds[] = (int) $pdo->lastInsertId();
        }
        $linkCompany = $pdo->prepare('INSERT INTO agent_company(agent_id,company_id) VALUES(?,?)');
        foreach ($agentIds as $i => $agentId) {
            $linkCompany->execute([$agentId, $companyIds[$i % count($companyIds)]]);
        }

        $repIds = [];
        $insertRep = $pdo->prepare(
            'INSERT INTO reps(name,phone,monthly_salary,hire_date,status,note) VALUES(?,?,?,?,?,?)'
        );
        $insertDeposit = $pdo->prepare(
            'INSERT INTO rep_custody_deposits(rep_id,dep_date,amount,note,created_by) VALUES(?,?,?,?,?)'
        );
        for ($i = 1; $i <= $shape['reps']; $i++) {
            $insertRep->execute([
                sprintf('__perf_rep_%03d', $i), sprintf('010%08d', $i), 6000 + ($i % 5) * 500,
                fixtureDate($i, 720), $i % 17 === 0 ? 'inactive' : 'active', 'performance fixture',
            ]);
            $repId = (int) $pdo->lastInsertId();
            $repIds[] = $repId;
            $insertDeposit->execute([$repId, fixtureDate($i), 10000 + ($i % 7) * 750, '__perf_deposit_a', 'fixture']);
            $insertDeposit->execute([$repId, fixtureDate($i + 40), 5000 + ($i % 4) * 500, '__perf_deposit_b', 'fixture']);
        }

        $insertPermit = $pdo->prepare(
            'INSERT INTO permits
             (permit_type,permit_no,permit_date,company,company_id,client,agent_id,destination,containers,
              container_type,note,status)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $insertMovement = $pdo->prepare(
            'INSERT INTO permit_movements(permit_id,seq,note,container_count,container_type) VALUES(?,?,?,?,?)'
        );
        $insertBill = $pdo->prepare(
            'INSERT INTO bawalis
             (permit_id,movement_id,bill_type,bill_date,bill_number,permit_no,
              declaration_owner,company,company_id,destination,container_no,container_type,container_count,
              freight,custody,discount,debt,paid,remaining,pay_status,is_legacy)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)'
        );
        $insertStage = $pdo->prepare(
            'INSERT INTO permit_stages
             (permit_id,movement_id,stage_key,direction,amount,qty,actual_amount,entry_date,ref_no,note,
              status,is_visa,visa_card_id,paid_by_rep_id)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,0,NULL,?)'
        );
        $insertExpense = $pdo->prepare(
            'INSERT INTO manual_expenses(exp_date,category,beneficiary,amount,note,is_visa,visa_card_id)
             VALUES(?,?,?,?,?,0,NULL)'
        );
        $insertPayment = $pdo->prepare(
            'INSERT INTO client_payments
             (client_name,agent_id,pay_date,amount,status,unallocated_amount,note,route)
             VALUES(?,?,?,?,\'posted\',?,?,\'none\')'
        );

        $stageKinds = [
            ['receipts', null], ['custom', 'expense'], ['custom', 'expense'],
            ['custom', 'revenue'], ['primary_fees', 'expense'],
        ];
        for ($i = 1; $i <= $shape['permits']; $i++) {
            $agentOffset = ($i - 1) % count($agentIds);
            $companyOffset = ($i - 1) % count($companyIds);
            $agentId = $agentIds[$agentOffset];
            $companyId = $companyIds[$companyOffset];
            $agentName = sprintf('__perf_agent_%03d', $agentOffset + 1);
            $companyName = sprintf('__perf_company_%03d', $companyOffset + 1);
            $permitNo = sprintf('__PERF-%06d', $i);
            $date = fixtureDate($i);
            $type = $i % 4 === 0 ? 'warid' : 'sader';
            $status = $i % 3 === 0 ? 'closed' : 'open';
            $insertPermit->execute([
                $type, $permitNo, $date, $companyName, $companyId, $agentName, $agentId,
                ['الإسكندرية','الدخيلة','العين السخنة','دمياط'][$i % 4], 2,
                $i % 2 ? '40 قدم' : '20 قدم', 'performance fixture', $status,
            ]);
            $permitId = (int) $pdo->lastInsertId();
            $insertMovement->execute([$permitId, 1, '__perf_movement', 2, $i % 2 ? '40 قدم' : '20 قدم']);
            $movementId = (int) $pdo->lastInsertId();

            for ($bill = 1; $bill <= 2; $bill++) {
                $freight = 7000 + (($i + $bill) % 19) * 250;
                $custody = 1500 + (($i + $bill) % 7) * 100;
                $discount = 200.0;
                $debt = $freight - $custody - $discount;
                $paid = $i % 5 === 0 ? $debt : ($i % 5 === 1 ? round($debt / 2, 2) : 0.0);
                $remaining = $debt - $paid;
                $payStatus = $remaining <= 0.005 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');
                $billNo = sprintf('__PERF-B-%06d-%d', $i, $bill);
                $insertBill->execute([
                    $permitId, $movementId, $type, fixtureDate($i + $bill), $billNo,
                    $permitNo, $agentName, $companyName, $companyId, 'ميناء اختبار',
                    sprintf('__PERF-C-%06d-%d', $i, $bill), $i % 2 ? '40 قدم' : '20 قدم', 1,
                    $freight, $custody, $discount, $debt, $paid, $remaining, $payStatus,
                ]);
            }

            foreach ($stageKinds as $stageOffset => [$stageKey, $direction]) {
                $amount = 250 + (($i + $stageOffset) % 23) * 35;
                $insertStage->execute([
                    $permitId, $movementId, $stageKey, $direction, $amount, 1,
                    $stageKey === 'receipts' ? $amount + 50 : null,
                    fixtureDate($i + $stageOffset), sprintf('__PERF-R-%06d-%d', $i, $stageOffset + 1),
                    '__perf_stage', $i % 13 === 0 && $stageOffset === 1 ? 'pending' : 'done',
                    $stageOffset < 2 ? $repIds[($i + $stageOffset) % count($repIds)] : null,
                ]);
            }

            for ($expense = 1; $expense <= 2; $expense++) {
                $insertExpense->execute([
                    fixtureDate($i + $expense), ['مصاريف مكتب','صيانة','اتصالات'][$i % 3],
                    sprintf('__perf_beneficiary_%02d', $i % 20), 100 + (($i + $expense) % 17) * 25,
                    '__perf_manual_expense',
                ]);
            }
            for ($payment = 1; $payment <= 2; $payment++) {
                $amount = 1200 + (($i + $payment) % 29) * 100;
                $insertPayment->execute([
                    $agentName, $agentId, fixtureDate($i + $payment), $amount, $amount,
                    '__perf_client_payment',
                ]);
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }

    $expected = [
        'permits' => $shape['permits'],
        'permit_movements' => $shape['permits'],
        'bawalis' => $shape['permits'] * 2,
        'permit_stages' => $shape['permits'] * 5,
        'manual_expenses' => $shape['permits'] * 2,
        'client_payments' => $shape['permits'] * 2,
        'reps' => $shape['reps'],
    ];
    foreach ($expected as $table => $count) {
        $actual = (int) Database::scalar("SELECT COUNT(*) FROM `$table`");
        if ($actual !== $count) { throw new RuntimeException("فشل تحقق $table: $actual بدل $count"); }
    }

    echo "✓ بُنيت عينة الأداء $size داخل $database\n";
    foreach ($expected as $table => $count) { echo "  • $table: $count\n"; }
} catch (Throwable $error) {
    fwrite(STDERR, '✗ ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
