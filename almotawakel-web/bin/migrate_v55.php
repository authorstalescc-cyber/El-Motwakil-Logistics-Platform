<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v55 — تصحيح سجل سيارات المكتب + قايمة اللوحات المتجاهَلة.
 *
 * **قرار المالك (2026-07-26): سيارة المكتب الوحيدة لحد دلوقتي «س ع م 8737».**
 *
 * ترحيل v54 بذر السجل من كل لوحة مكتوب قدامها `car_owner = 'المكتب'` في
 * البوالص، فطلع ٣ سيارات. لكن `car_owner` بيتكتب بالإيد، و**متحقَّق فعليًّا**
 * إن اللوحتين التانيتين بيظهروا كمان بأسماء ملّاكهم الحقيقيين:
 *
 *     «س ن ر 3739» → المكتب ×2 · همام عشري ×1 · همام عشرى ×1
 *     «ط ف ر 1474» → المكتب ×2 · خليفة ×2 · خوليف ×1
 *
 * يعني دول عربيات مستأجرة واتكتب «المكتب» غلط في بعض بوالصهم.
 *
 * الترحيل:
 *   ① بيشيل من `office_cars` أي سيارة غير «س ع م 8737» (ومصاريفها لو فيه).
 *   ② بيسجّل مفاتيح لوحاتهم في إعداد `car_ignored_plates`.
 *
 * ⚠️ **الخطوة ② هي اللي بتمنع رجوعهم.** من غيرها: تنبيه «لوحات مكتوب عليها
 *    المكتب ومش في السجل» هيفضل يزنّ عليهم للأبد، و**إعادة تشغيل v54 يدويًّا
 *    كانت هترجّع تسجّلهم**. القايمة مفاتيح تطبيع (`OfficeCar::plateKey`) مش
 *    نصوص خام، فبتغطّي كل تهجئات نفس اللوحة.
 *
 * ⚠️ **البوالص ما بتتلمسش خالص.** الرحلات بتفضل مكانها بالظبط، و`car_owner`
 *    الغلط فيها **قرار تصحيحه للمالك** من شاشة البوليصة (تحذير ٣: التصادمات
 *    ما بتتدمجش تلقائيًّا).
 *
 * ⚠️ **صفر أثر مالي** — بيتحقّق إن رصيد الخزنة ما اتغيّرش.
 *
 * idempotent — عادي تشغّلها أكتر من مرة.
 *
 * التشغيل:  php bin/migrate_v55.php        (أو تلقائيًّا من bin/migration.php)
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;
use App\Models\OfficeCar;

/** لوحة سيارة المكتب الوحيدة (قرار المالك). */
const V55_KEEP_PLATE = 'س ع م 8737';

$v55BalanceBefore = Closing::currentBalance();
$v55Say = static function (string $line): void { echo $line . PHP_EOL; };

$v55Up = static function () use ($v55Say): void {
    if (!OfficeCar::available()) {
        throw new \RuntimeException('v55 محتاجة v54 الأول — شغّل php bin/migrate_v54.php');
    }
    $keep = OfficeCar::plateKey(V55_KEEP_PLATE);

    $removed = 0;
    foreach (OfficeCar::all() as $car) {
        if ((string) $car['plate_key'] === $keep) { continue; }
        // ② الأول نتجاهل اللوحة (بكل تهجئاتها) ثم نشيل السجل
        OfficeCar::ignorePlate((string) $car['plate']);
        foreach (OfficeCar::aliasList($car) as $alias) { OfficeCar::ignorePlate($alias); }
        OfficeCar::delete((int) $car['id']);
        $removed++;
        $v55Say('  − اتشالت من السجل واتسجّلت «مش بتاعتنا»: ' . $car['plate']);
    }

    if ($removed === 0) { $v55Say('  = السجل متظبّط بالفعل — مفيش سيارة زيادة.'); }

    $left = OfficeCar::all();
    $v55Say('  ✓ السجل فيه دلوقتي ' . count($left) . ' سيارة: '
          . implode(' · ', array_column($left, 'plate')));
    $v55Say('  ✓ لوحات متجاهَلة: ' . count(OfficeCar::ignoredPlates()));
};

$v55Verify = static function () use ($v55BalanceBefore, $v55Say): bool {
    $ok = true;
    $fail = static function (string $m) use (&$ok, $v55Say): void { $ok = false; $v55Say('  ✗ ' . $m); };

    $keep = OfficeCar::plateKey(V55_KEEP_PLATE);
    $cars = OfficeCar::all();
    $extra = array_values(array_filter($cars, static fn ($c) => (string) $c['plate_key'] !== $keep));
    if ($extra) {
        $fail('لسه فيه سيارة غير «' . V55_KEEP_PLATE . '» في السجل: '
            . implode(', ', array_column($extra, 'plate')));
    }

    // ⚠️ اللوحات المشالة لازم تبقى متجاهَلة، وإلا التنبيه يزنّ وv54 ترجّعها
    $orphans = OfficeCar::unregisteredOfficePlates();
    if ($orphans) {
        $fail('لسه فيه لوحات «المكتب» مش مسجَّلة ولا متجاهَلة: '
            . implode(', ', array_column($orphans, 'car_no')));
    }

    // البوالص ما اتلمستش — العدد ثابت وسيارة المكتب لسه ليها رحلات
    $keepCar = null;
    foreach ($cars as $c) { if ((string) $c['plate_key'] === $keep) { $keepCar = $c; } }
    if (!$keepCar) {
        $fail('سيارة المكتب «' . V55_KEEP_PLATE . '» مش موجودة في السجل!');
    } else {
        $trips = count(OfficeCar::trips((int) $keepCar['id']));
        $trips > 0 ? $v55Say('  ✓ سيارة المكتب لسه شايفة ' . $trips . ' رحلة.')
                   : $fail('سيارة المكتب مش شايفة أي رحلة — المطابقة اتكسرت.');
    }

    // البوالص اللي كانت للسيارتين المشالتين لسه موجودة (الوحدة قراءة فقط)
    $still = (int) Database::scalar(
        "SELECT COUNT(*) FROM bawalis WHERE is_legacy = 0 AND car_owner = 'المكتب'");
    $still > 0 ? $v55Say('  ✓ بوالص «المكتب» لسه ' . $still . ' بوليصة (ما اتلمستش).')
               : $fail('بوالص «المكتب» اختفت — الترحيل ممنوع يلمس جدول البوالص.');

    $after = Closing::currentBalance();
    if (abs($after - $v55BalanceBefore) > 0.005) {
        $fail('رصيد الخزنة اتغيّر (' . number_format($v55BalanceBefore, 2)
            . ' ← ' . number_format($after, 2) . ') — الترحيل ده ممنوع يكون له أثر مالي.');
    }

    if ($ok) { $v55Say('  ✓ التحقق بعد التطبيق عدّى.'); }
    return $ok;
};

// ⚠️ مصيدة v49 الموثَّقة: الملف بيتنفّذ بطريقتين ولازم يشتغل صح في الاتنين.
$v55Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
if ($v55Direct) {
    echo "v55 — تصحيح سجل سيارات المكتب\n" . str_repeat('=', 60) . "\n";
    $v55Up();
    if ($v55Verify() !== true) {
        fwrite(STDERR, "فشل التحقق بعد تطبيق v55.\n");
        exit(1);
    }
    echo "تمام.\n";
}

return ['up' => $v55Up, 'verify' => $v55Verify];
