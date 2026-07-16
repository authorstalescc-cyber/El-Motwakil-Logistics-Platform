<?php
/**
 * فحص v34 — نقاط الوجهات والمسافة المتبقية والوصول المتوقع.
 *
 * يختبر: مطابقة الوجهات (تام/اختصار/جزئي/إملائي) على **بياناتك الحقيقية**،
 * حساب المسافة والوقت، والعزل (ما يمسّش أي حساب ولا بوليصة).
 *
 * الاستخدام: php bin/verify_v34.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\App;
use App\Models\Track;
use App\Models\Custody;

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ✅ $m" . PHP_EOL; }
function bad(string $m): void { global $fail; $fail++; echo "  ❌ $m" . PHP_EOL; }
function check(bool $c, string $m): void { $c ? ok($m) : bad($m); }

echo "فحص v34 — الوجهات والوصول المتوقع…" . PHP_EOL . PHP_EOL;

// ---------------------------------------------------------------------------
echo "1) الجدول والإعدادات" . PHP_EOL;
check((bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',
    ['destination_points']
), 'جدول destination_points موجود');
check(count(Track::points()) >= 40, 'الوجهات المبذورة اتحمّلت (' . count(Track::points()) . ')');
check(App::setting('track_avg_speed_kmh', null) !== null, 'إعداد السرعة الافتراضية موجود');
check(App::setting('track_road_factor', null) !== null, 'إعداد معامل الطريق موجود');

// ---------------------------------------------------------------------------
echo PHP_EOL . "2) مطابقة الوجهات — الاختصارات اللي بتكتبها فعلاً" . PHP_EOL;
$cases = [
    ['العاشر',                'العاشر من رمضان', 'alias'],
    ['العاشر من رمضان',      'العاشر من رمضان', 'exact'],
    ['ب سويف',                'بني سويف',        'alias'],
    ['بنى سويف',              'بني سويف',        'exact'],   // normkey: ى→ي
    ['اكتوبر',                '6 أكتوبر',        'alias'],
    ['ك الدوار',              'كفر الدوار',      'alias'],
    ['م نصر',                 'مدينة نصر',       'alias'],
    ['المحله',                'المحلة الكبرى',   'alias'],
    ['الاسماعيليه',          'الإسماعيلية',     'exact'],   // normkey: إ→ا، ة→ه
    ['مدينة بدر المنطقة 6',  'مدينة بدر',       'partial'],
    ['السادات',               'مدينة السادات',   'alias'],
];
foreach ($cases as [$in, $want, $kind]) {
    $m = Track::resolveDestination($in);
    check($m !== null && $m['point']['name'] === $want && $m['match'] === $kind,
          sprintf('«%s» → %s [%s]', $in, $want, $kind)
          . ($m && $m['point']['name'] !== $want ? ' — طلع: ' . $m['point']['name'] : ''));
}
check(Track::resolveDestination('حاجة مش موجودة خالص') === null, 'وجهة مجهولة ترجّع لا شيء (مش تخمين غلط)');
check(Track::resolveDestination('') === null, 'وجهة فاضية ترجّع لا شيء');

// ---------------------------------------------------------------------------
echo PHP_EOL . "3) الحسابات" . PHP_EOL;
check(Track::hoursText(0.5) === 'متوقع يوصل خلال ~30 دقيقة', 'نص ساعة → «30 دقيقة»');
check(str_contains(Track::hoursText(2.0), 'ساعتين'), 'ساعتين → «ساعتين» (مش «2 ساعات»)');
check(str_contains(Track::hoursText(1.0), 'ساعة'), 'ساعة واحدة → «ساعة»');
check(str_contains(Track::hoursText(5.5), '5 ساعات'), '5.5 → «5 ساعات و30 دقيقة»');

// ---------------------------------------------------------------------------
echo PHP_EOL . "4) دورة كاملة: سائق في القاهرة رايح الإسكندرية" . PHP_EOL;

$custodyBefore = Custody::balance();
$billsBefore   = (int) Database::scalar('SELECT COUNT(*) FROM bawalis');

Database::exec(
    "INSERT INTO bawalis (bill_date, bill_number, driver, car_no, destination, freight, debt)
     VALUES (CURDATE(), 'V34-TEST', 'سائق v34', 'ت ت ت 888', 'اسكندريه', 7000, 7000)"
);
$billId  = Database::lastId();
$trackId = 0;

try {
    $t = Track::createForBill($billId, '', 'اختبار v34', 'verify34');
    $trackId = (int) $t['id'];

    // مفيش موقع لسه → مفيش تقدير
    check(Track::eta($t, 'اسكندريه') === null, 'قبل ما يبعت موقعه: مفيش تقدير (مش تخمين)');

    // موقعه في القاهرة
    Track::addPing($t, 30.0444, 31.2357, ['source' => 'manual']);
    $t = Track::find($trackId);

    $eta = Track::eta($t, 'اسكندريه');
    check($eta !== null, 'بعد الموقع: فيه تقدير');
    check($eta['point']['name'] === 'الإسكندرية', 'الوجهة «اسكندريه» اتطابقت بالإسكندرية');
    check(!$eta['arrived'], 'مش «وصل» وهو في القاهرة');
    check($eta['straight_km'] > 170 && $eta['straight_km'] < 190,
          sprintf('الخط المستقيم %.0f كم (متوقع ~180)', $eta['straight_km']));
    check($eta['km'] > $eta['straight_km'], 'مسافة الطريق أكبر من الخط المستقيم (معامل الطريق اشتغل)');
    check($eta['speed_src'] === 'default', 'رحلة قصيرة → بيستخدم السرعة الافتراضية');
    check($eta['hours'] > 3 && $eta['hours'] < 8,
          sprintf('الوقت المتوقع %.1f ساعة (معقول للقاهرة→إسكندرية بنقل)', $eta['hours']));

    // وصل الإسكندرية
    Database::exec("UPDATE track_pings SET created_at = DATE_SUB(NOW(), INTERVAL 4 HOUR) WHERE track_id = ?", [$trackId]);
    $t = Track::find($trackId);
    Track::addPing($t, 31.2001, 29.9187, ['source' => 'manual']);
    $t = Track::find($trackId);

    $eta2 = Track::eta($t, 'اسكندريه');
    check($eta2['arrived'] === true, 'لما وصل الإسكندرية: «وصل»');
    check($eta2['hours'] == 0.0, 'الوصول: صفر ساعات');

    // السرعة الفعلية بقت متاحة (180كم في 4 ساعات)
    $sum = Track::summary($trackId);
    check($sum['kmh'] > 30 && $sum['kmh'] < 60, sprintf('سرعته الفعلية اتحسبت: %.0f كم/س', $sum['kmh']));

    // وجهة مجهولة → مفيش تقدير بدل تخمين غلط
    check(Track::eta($t, 'مكان مخترع') === null, 'وجهة مجهولة → مفيش تقدير (مش رقم غلط)');

    // ---------------------------------------------------------------------------
    echo PHP_EOL . "5) العزل" . PHP_EOL;
    check(abs(Custody::balance() - $custodyBefore) < 0.01, 'رصيد العهدة ما اتغيّرش');
    $b = Database::row('SELECT freight, debt, destination FROM bawalis WHERE id = ?', [$billId]);
    check((float) $b['freight'] === 7000.0 && (float) $b['debt'] === 7000.0, 'أرقام البوليصة ما اتغيّرتش');
    check($b['destination'] === 'اسكندريه', 'نص الوجهة في البوليصة ما اتلمسش (المطابقة قراءة بس)');
    $moneyCols = (int) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema=DATABASE() AND table_name='destination_points'
            AND column_name REGEXP 'amount|freight|debt|paid|custody|expense|price'"
    );
    check($moneyCols === 0, 'مفيش أي عمود مالي في destination_points');

} finally {
    if ($trackId > 0) { Track::delete($trackId); }
    Database::exec('DELETE FROM shipment_tracks WHERE bill_id = ?', [$billId]);
    Database::exec('DELETE FROM bawalis WHERE id = ?', [$billId]);
}

echo PHP_EOL . "6) التنضيف" . PHP_EOL;
check((int) Database::scalar('SELECT COUNT(*) FROM bawalis') === $billsBefore, 'بوليصة الاختبار اتمسحت');
check(abs(Custody::balance() - $custodyBefore) < 0.01, 'رصيد العهدة زي ما كان');

// ---------------------------------------------------------------------------
// فلتر التحديد التلقائي — أهم اختبار هنا. البحث الخام في OpenStreetMap بيرجّع
// «مقبرة رمسيس العاشر في الأقصر» لكلمة «العاشر» (بترتيب أعلى من المدينة!)
// و«حديقة في حلوان» لـ«6 أكتوبر». لو الفلتر باظ، السائق الرايح العاشر من رمضان
// هيبان إنه فاضله 600 كم على الأقصر. الاختبارات دي بتحمي من ده بالظبط.
// (محتاجة إنترنت — بتتخطّى لو مفيش)
echo PHP_EOL . "7) فلتر التحديد التلقائي (يحتاج إنترنت)" . PHP_EOL;
$net = Track::geocodePlace('المنصورة');
if ($net === null) {
    echo '  ⏭️  مفيش إنترنت — الفحوصات دي اتخطّت' . PHP_EOL;
} else {
    check($net['kind'] === 'city', 'مدينة معروفة → [city]');

    $g = Track::geocodePlace('العاشر');
    check($g !== null && str_contains($g['label'], 'العاشر من رمضان'),
          '«العاشر» → العاشر من رمضان **مش** مقبرة رمسيس العاشر في الأقصر');
    check($g !== null && $g['lat'] > 30.0 && $g['lat'] < 30.6,
          '«العاشر» في الشرقية مش في الأقصر (خط عرض ' . ($g ? round($g['lat'], 2) : '؟') . ')');

    $g6 = Track::geocodePlace('6 أكتوبر');
    check($g6 === null, '«6 أكتوبر» → مرفوضة (الحديقة والكافيه اترفضوا — أحسن من مكان غلط)');

    usleep(1100000);
    $gr = Track::geocodePlace('طريق التحدي');
    check($gr !== null && $gr['confidence'] === 'low', '«طريق التحدي» → طريق بثقة ضعيفة (موسوم للمراجعة)');

    check(Track::geocodePlace('زقزقة مش موجودة خالص ابدا') === null, 'اسم مخترع → مفيش نتيجة');
    check(Track::geocodePlace('ab') === null, 'اسم قصير جدًا → مرفوض بلا طلب شبكة');
}

// ---------------------------------------------------------------------------
echo PHP_EOL . "8) تغطية وجهاتك الحقيقية" . PHP_EOL;
$cov = Track::destinationCoverage();
$hit = 0; $tot = 0;
foreach ($cov as $c) { $tot += $c['count']; if ($c['point']) { $hit += $c['count']; } }
$pctv = $tot ? $hit / $tot * 100 : 0;
check($pctv >= 85, sprintf('تغطية %.0f%% من البوالص (%d/%d)', $pctv, $hit, $tot));

echo PHP_EOL . str_repeat('─', 50) . PHP_EOL;
echo $fail === 0
    ? "✅ كل الفحوصات نجحت ($pass/$pass)" . PHP_EOL
    : "❌ فشل $fail فحص من " . ($pass + $fail) . PHP_EOL;
exit($fail === 0 ? 0 : 1);
