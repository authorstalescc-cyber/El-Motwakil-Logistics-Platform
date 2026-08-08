<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * إصلاح القيم التي أكد المالك أنها تخص الشيء نفسه:
 *   جلال حسنى + جلال الزين → جلال حسني
 *   40                    → 40 قدم
 *
 * العملية idempotent وداخل transaction، وتتحقق أن كل الأعداد والمبالغ ثابتة.
 *
 * التشغيل:
 *   php bin/fix_office_car_data.php --dry-run
 *   php bin/fix_office_car_data.php --apply
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\DataAlias;
use App\Models\DataQuality;
use App\Models\OfficeCar;

$dry = in_array('--dry-run', $argv, true);
$apply = in_array('--apply', $argv, true);
if ($dry === $apply) {
    fwrite(STDERR, "استخدم --dry-run للمعاينة أو --apply للتنفيذ.\n");
    exit(2);
}

/** لقطة كل القيم التي يمنع أن تتغير بالدمج الوصفي. */
$snapshot = static function (): array {
    $bill = Database::row(
        'SELECT COUNT(*) rows_count,
                COALESCE(SUM(container_count),0) container_total,
                COALESCE(SUM(freight),0) freight_total,
                COALESCE(SUM(custody),0) custody_total,
                COALESCE(SUM(transfer),0) transfer_total,
                COALESCE(SUM(discount),0) discount_total,
                COALESCE(SUM(debt),0) debt_total,
                COALESCE(SUM(paid),0) paid_total,
                COALESCE(SUM(remaining),0) remaining_total
           FROM bawalis'
    ) ?? [];
    $permit = Database::row(
        'SELECT COUNT(*) rows_count, COALESCE(SUM(containers),0) container_total FROM permits'
    ) ?? [];
    return ['bawalis' => $bill, 'permits' => $permit];
};

$car = OfficeCar::matchPlate('س ع م 8737');
if (!$car) {
    fwrite(STDERR, "سيارة المكتب «س ع م 8737» غير موجودة في السجل.\n");
    exit(1);
}
$carId = (int) $car['id'];
$before = $snapshot();
$beforeTrips = OfficeCar::trips($carId);
$beforeTripCount = count($beforeTrips);
$beforeCarContainers = array_sum(array_map(
    static fn (array $t): int => max(0, (int) $t['container_count']),
    $beforeTrips
));
$beforeMix = OfficeCar::containerMix($beforeTrips);
$expected40 = (int) ($beforeMix['40 قدم'] ?? 0) + (int) ($beforeMix['40'] ?? 0);
$expectedDriverNames = [];
foreach ($beforeTrips as $trip) {
    $driverName = trim((string) ($trip['driver'] ?? ''));
    if (in_array($driverName, ['جلال حسني', 'جلال حسنى', 'جلال الزين'], true)) {
        $driverName = 'جلال حسني';
    }
    if ($driverName !== '') { $expectedDriverNames[$driverName] = true; }
}
$expectedDrivers = count($expectedDriverNames);

$pdo = Database::conn();
$pdo->beginTransaction();
try {
    $driver = DataQuality::merge(
        'driver',
        'جلال حسني',
        ['جلال حسنى', 'جلال الزين']
    );
    $container = DataQuality::merge(
        'container_type',
        '40 قدم',
        ['40']
    );

    OfficeCar::clearCache();
    $after = $snapshot();
    if ($after !== $before) {
        throw new RuntimeException('تغيّرت أعداد أو مبالغ محمية؛ أُلغي الدمج.');
    }

    $afterTrips = OfficeCar::trips($carId);
    $afterTripCount = count($afterTrips);
    $afterCarContainers = array_sum(array_map(
        static fn (array $t): int => max(0, (int) $t['container_count']),
        $afterTrips
    ));
    if ($afterTripCount !== $beforeTripCount || $afterCarContainers !== $beforeCarContainers) {
        throw new RuntimeException('تغيّر عدد رحلات السيارة أو إجمالي حاوياتها؛ أُلغي الدمج.');
    }

    $perf = OfficeCar::perf($carId);
    $mix  = OfficeCar::containerMix($afterTrips);
    if ((int) $perf['drivers'] !== $expectedDrivers) {
        throw new RuntimeException(
            'عدد السائقين بعد الدمج لا يطابق العدد المتوقع (' . $expectedDrivers . ')؛ أُلغي الدمج.'
        );
    }
    if ((int) ($mix['40 قدم'] ?? 0) !== $expected40 || isset($mix['40'])) {
        throw new RuntimeException(
            'دمج «40» في «40 قدم» لم يحافظ على الإجمالي المتوقع (' . $expected40 . ')؛ أُلغي الدمج.'
        );
    }
    if (DataAlias::canonical('driver', 'جلال الزين') !== 'جلال حسني') {
        throw new RuntimeException('الاسم البديل للسائق لم يُحفَظ؛ أُلغي الدمج.');
    }
    if (DataAlias::canonical('container_type', '40') !== '40 قدم') {
        throw new RuntimeException('الاسم البديل لتصنيف 40 لم يُحفَظ؛ أُلغي الدمج.');
    }

    if ($dry) {
        $pdo->rollBack();
        echo "✓ المعاينة نجحت — لم تُكتب أي بيانات.\n";
    } else {
        $pdo->commit();
        echo "✓ تم الدمج وحفظ الأسماء البديلة.\n";
    }
    printf(
        "  السائقون: %d سائق · الرحلات: %d\n"
        . "  40 قدم: %d حاوية · إجمالي حاويات السيارة: %d\n"
        . "  السجلات المحدّثة: سائقون=%d · تصنيف=%d\n",
        (int) $perf['drivers'],
        $afterTripCount,
        (int) ($mix['40 قدم'] ?? 0),
        $afterCarContainers,
        (int) $driver['rows'],
        (int) $container['rows']
    );
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    DataAlias::clearCache();
    OfficeCar::clearCache();
    fwrite(STDERR, "✗ " . $e->getMessage() . "\n");
    exit(1);
}
