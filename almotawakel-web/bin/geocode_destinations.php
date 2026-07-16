<?php
/**
 * يحدّد مكان الوجهات الناقصة تلقائيًا من OpenStreetMap (v34).
 *
 * يمشي على كل وجهة مستعملة في البوالص ومالهاش نقطة، ويدوّر عليها بفلتر صارم
 * (Track::geocodePlace) يقبل الأماكن السكنية بس ويرفض القمامة — أثبتنا إن البحث
 * الخام بيرجّع «مقبرة رمسيس العاشر في الأقصر» لكلمة «العاشر»، و«حديقة في حلوان»
 * لـ«6 أكتوبر». اللي يتحدّد تلقائيًا يتوسم is_auto=1 عشان تراجعه.
 *
 * الاستخدام:
 *   php bin/geocode_destinations.php --dry-run   ← يوريك هيعمل إيه بلا حفظ (ابدأ بده)
 *   php bin/geocode_destinations.php             ← التنفيذ الفعلي
 *
 * ⚠️ وصفي بحت: ما يمسّش أي بوليصة ولا حساب — بيضيف نقاط على الخريطة بس.
 * آمن لإعادة التشغيل (بيتخطّى اللي متحدّد خلاص).
 * بيحترم حدود OpenStreetMap المجانية (طلب/ثانية).
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Track;
use App\Models\Maintenance;

$dry = in_array('--dry-run', $argv, true);

echo ($dry ? '🔍 معاينة (بلا حفظ)' : '💾 تنفيذ فعلي') . ' — تحديد الوجهات الناقصة تلقائيًا' . PHP_EOL . PHP_EOL;

// الوجهات المستعملة فعلاً ومالهاش نقطة — الأكثر استخدامًا الأول
$missing = [];
foreach (Track::destinationCoverage() as $c) {
    if ($c['point'] === null) { $missing[] = $c; }
}

if (!$missing) {
    echo '✅ كل وجهاتك متحدّدة على الخريطة بالفعل — مفيش حاجة تتعمل.' . PHP_EOL;
    exit(0);
}

echo 'وجهات ناقصة: ' . count($missing) . PHP_EOL . PHP_EOL;

$done = 0; $skip = 0;
foreach ($missing as $m) {
    $name = $m['name'];
    printf('── «%s» (%d بوليصة)' . PHP_EOL, $name, $m['count']);

    $g = Track::geocodePlace($name);
    if (!$g) {
        echo '   ❌ مالقيناش حاجة موثوقة — حدّدها بإيدك من /tracking/places' . PHP_EOL;
        $skip++;
        usleep(1100000);
        continue;
    }

    $conf = ['high' => 'ثقة عالية', 'medium' => 'ثقة متوسطة', 'low' => 'ثقة ضعيفة — راجعها'][$g['confidence']] ?? '';
    printf('   ✓ %s' . PHP_EOL, mb_substr($g['label'], 0, 66));
    printf('     [%s] %s — %.4f, %.4f' . PHP_EOL, $g['kind'], $conf, $g['lat'], $g['lng']);

    if (!$dry) {
        $nk = Maintenance::normkey($name);
        if ($nk === '' || Database::scalar('SELECT COUNT(*) FROM destination_points WHERE normkey=?', [$nk])) {
            echo '     (اتخطّت — بقت موجودة)' . PHP_EOL;
            $skip++;
        } else {
            Database::exec(
                'INSERT INTO destination_points (name, normkey, lat, lng, note, is_auto)
                 VALUES (?, ?, ?, ?, ?, 1)',
                [mb_substr($name, 0, 120), $nk, round($g['lat'], 7), round($g['lng'], 7),
                 mb_substr('اتحدّدت تلقائيًا: ' . $g['label'], 0, 160)]
            );
            Track::clearPointsCache();
            $done++;
        }
    } else {
        $done++;
    }

    usleep(1100000);   // حد OpenStreetMap المجاني: طلب واحد في الثانية
}

echo PHP_EOL . str_repeat('─', 50) . PHP_EOL;
if ($dry) {
    echo "🔍 المعاينة: هيتحدّد $done وجهة، و$skip محتاجة إيدك." . PHP_EOL;
    echo '   للتنفيذ: php bin/geocode_destinations.php' . PHP_EOL;
} else {
    echo "✅ اتحدّد $done وجهة تلقائيًا" . ($skip ? "، و$skip محتاجة إيدك." : '.') . PHP_EOL;
    if ($done) {
        echo '   ⚠️ كلها موسومة «تلقائي» — بصّ عليها من /tracking/places واتأكد إنها مظبوطة.' . PHP_EOL;
    }
}

// تقرير التغطية النهائي
$cov = Track::destinationCoverage();
$hit = 0; $tot = 0;
foreach ($cov as $c) { $tot += $c['count']; if ($c['point']) { $hit += $c['count']; } }
printf(PHP_EOL . 'التغطية دلوقتي: %d من %d بوليصة (%.0f%%)' . PHP_EOL, $hit, $tot, $tot ? $hit / $tot * 100 : 0);
