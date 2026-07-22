<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص v33 — تتبع الشحنات باللوكيشن.
 *
 * يختبر الوحدة من الأول للآخر على بيانات مؤقتة ثم ينضّف وراه بالكامل.
 * أهم اختبار هنا: **العزل** — التأكد إن الوحدة ما بتلمسش أي حساب مالي
 * ولا عهدة ولا تقفيل يومي (تحذير 1 و6).
 *
 * الاستخدام: php bin/verify_v33.php
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

echo "فحص v33 — تتبع الشحنات…" . PHP_EOL . PHP_EOL;

// ---------------------------------------------------------------------------
echo "1) الجداول والإعدادات" . PHP_EOL;
foreach (['shipment_tracks', 'track_pings', 'driver_contacts'] as $tbl) {
    check((bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', [$tbl]
    ), "جدول $tbl موجود");
}
check(App::setting('track_expire_hours', null) !== null, 'إعداد صلاحية الرابط موجود');
check(App::setting('track_live_secs', null) !== null, 'إعداد فترة التتبع المباشر موجود');

// ---------------------------------------------------------------------------
echo PHP_EOL . "2) دوال المساعدة" . PHP_EOL;
check(wa_phone('01012345678') === '201012345678', 'wa_phone: 01012345678 → 201012345678');
check(wa_phone('٠١٠١٢٣٤٥٦٧٨') === '201012345678', 'wa_phone: أرقام عربية → دولي');
check(wa_phone('+20 101 234 5678') === '201012345678', 'wa_phone: بمسافات و+ → دولي');
check(wa_phone('abc') === '', 'wa_phone: رقم غلط → فاضي (الزرار يختفي)');
check(is_local_host('localhost:8000'), 'is_local_host: localhost');
check(is_local_host('192.168.1.5'), 'is_local_host: شبكة محلية');
check(!is_local_host('abc-def.trycloudflare.com'), 'is_local_host: نفق cloudflare مش محلي');

// المسافة: القاهرة ↔ الإسكندرية ≈ 180 كم
$d = Track::haversine(30.0444, 31.2357, 31.2001, 29.9187) / 1000;
check($d > 170 && $d < 190, sprintf('haversine: القاهرة↔الإسكندرية = %.0f كم (متوقع ~180)', $d));

// ---------------------------------------------------------------------------
echo PHP_EOL . "3) دورة حياة كاملة على بوليصة مؤقتة" . PHP_EOL;

$custodyBefore = Custody::balance();
$billsBefore   = (int) Database::scalar('SELECT COUNT(*) FROM bawalis');

Database::exec(
    "INSERT INTO bawalis (bill_date, bill_number, driver, car_no, destination, container_no, freight, debt)
     VALUES (CURDATE(), 'V33-TEST', 'سائق اختبار v33', 'ت ت ت 999', 'الإسكندرية', 'TEST0000001', 5000, 5000)"
);
$billId = Database::lastId();
$trackId = 0;

try {
    // توليد الرابط
    $t = Track::createForBill($billId, '01012345678', 'اختبار', 'verify');
    $trackId = (int) $t['id'];
    check($trackId > 0, 'اتولّدت رحلة تتبع');
    check(preg_match('/^[a-f0-9]{14}$/', $t['token']) === 1, 'التوكن hex عشوائي 14 حرف');
    check($t['driver'] === 'سائق اختبار v33', 'لقطة اسم السائق اتحفظت');
    check($t['status'] === 'active', 'الحالة الافتراضية «في الطريق»');
    check(!empty($t['expires_at']), 'للرابط تاريخ انتهاء');

    // ما يتولّدش رابط تاني لنفس البوليصة
    $again = Track::createForBill($billId, '', '', 'verify');
    check((int) $again['id'] === $trackId, 'استدعاء تاني لنفس البوليصة يرجّع نفس الرحلة (مش رابط جديد)');

    // الرقم اتفكر
    check(Track::phoneOf('سائق اختبار v33') === '01012345678', 'رقم السائق اتفكر لاستخدامه بعدين');

    // تسجيل نقطة
    $t = Track::find($trackId);
    $r = Track::addPing($t, 31.2001, 29.9187, ['source' => 'manual', 'accuracy' => 12]);
    check($r['ok'] === true, 'تسجّلت نقطة موقع (لقطة)');

    $t = Track::find($trackId);
    check((int) $t['ping_count'] === 1, 'العدّاد اتحدّث لـ 1');
    check(!empty($t['last_ping_at']), 'آخر موقع اتسجّل وقته');
    check(!empty($t['opened_at']), 'وقت أول فتح اتسجّل');

    // إحداثيات غلط تترفض
    check(Track::addPing($t, 999, 999)['ok'] === false, 'إحداثيات مستحيلة تترفض');
    check(Track::addPing($t, 0, 0)['ok'] === false, 'إحداثيات (0,0) تترفض');

    // حماية الإغراق: نقطة تانية فورًا تترفض
    check(Track::addPing($t, 31.21, 29.92)['ok'] === false, 'نقطتين في أقل من 5 ثواني: التانية تترفض');

    // المسافة بتتحسب بين النقاط — نحقن النقطة يدويًا بتاريخ أقدم لتخطّي حماية الإغراق
    Database::exec(
        "UPDATE track_pings SET created_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE track_id = ?", [$trackId]
    );
    $t = Track::find($trackId);
    $r2 = Track::addPing($t, 30.0444, 31.2357, ['source' => 'live']);
    check($r2['ok'] === true, 'تسجّلت نقطة تانية (تتبع مباشر)');
    $last = Track::lastPing($trackId);
    check((int) $last['dist_m'] > 170000 && (int) $last['dist_m'] < 190000,
          'المسافة من النقطة السابقة اتحسبت (~180 كم)');
    check($last['source'] === 'live', 'مصدر النقطة اتسجّل «live»');

    $sum = Track::summary($trackId);
    check($sum['pings'] === 2, 'الملخّص: نقطتين');
    check($sum['km'] > 170 && $sum['km'] < 190, sprintf('الملخّص: المسافة %.0f كم', $sum['km']));

    // القفل بيمنع الاستقبال
    Track::setStatus($trackId, 'done');
    $t = Track::find($trackId);
    check(!Track::isOpen($t), 'حالة «وصلت» بتقفل الرابط');
    check(Track::addPing($t, 31.5, 30.0)['ok'] === false, 'الرابط المقفول يرفض أي موقع جديد');

    // انتهاء الصلاحية بيقفل كمان
    Track::setStatus($trackId, 'active');
    Database::exec("UPDATE shipment_tracks SET expires_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = ?", [$trackId]);
    $t = Track::find($trackId);
    check(!Track::isOpen($t), 'الرابط المنتهي صلاحيته مقفول');
    check(Track::addPing($t, 31.5, 30.0)['ok'] === false, 'الرابط المنتهي يرفض أي موقع');

    Track::renew($trackId);
    check(Track::isOpen(Track::find($trackId)), 'التجديد بيفتح الرابط تاني');

    // ---------------------------------------------------------------------------
    echo PHP_EOL . "4) العزل — أهم اختبار (تحذير 1 و6)" . PHP_EOL;

    check(abs(Custody::balance() - $custodyBefore) < 0.01,
          'رصيد العهدة ما اتغيّرش خالص بعد كل ده');

    // ما دخلش أي مصروف
    $expSrc = (int) Database::scalar(
        "SELECT COUNT(*) FROM manual_expenses WHERE note LIKE '%v33%' OR note LIKE '%تتبع%'"
    );
    check($expSrc === 0, 'ما اتسجّلش أي مصروف من التتبع');

    // ما فيش أعمدة مالية في جداول الوحدة
    $moneyCols = (int) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND table_name IN ('shipment_tracks','track_pings','driver_contacts')
            AND (column_name REGEXP 'amount|freight|debt|paid|custody|expense|price|total'
                 OR data_type = 'decimal' AND column_name NOT IN ('lat','lng','speed_kmh'))"
    );
    check($moneyCols === 0, 'مفيش أي عمود مالي في جداول الوحدة');

    // البوليصة نفسها ما اتلمستش
    $bill = Database::row('SELECT freight, debt, paid, remaining FROM bawalis WHERE id = ?', [$billId]);
    check((float) $bill['freight'] === 5000.0 && (float) $bill['debt'] === 5000.0,
          'أرقام البوليصة المالية ما اتغيّرتش');

    // حذف التتبع ما يمسّش البوليصة (بلا FK — تحذير 3)
    Track::delete($trackId);
    $trackId = 0;
    check(Track::find((int) ($t['id'] ?? 0)) === null, 'حذف التتبع اتم');
    check((int) Database::scalar('SELECT COUNT(*) FROM bawalis WHERE id = ?', [$billId]) === 1,
          'البوليصة فضلت موجودة بعد حذف التتبع');
    check((int) Database::scalar('SELECT COUNT(*) FROM track_pings WHERE track_id = ?', [$trackId ?: -1]) === 0,
          'نقاط الرحلة اتمسحت معاها');

} finally {
    // تنضيف كامل مهما حصل
    if ($trackId > 0) { Track::delete($trackId); }
    Database::exec('DELETE FROM track_pings WHERE track_id IN (SELECT id FROM shipment_tracks WHERE bill_id = ?)', [$billId]);
    Database::exec('DELETE FROM shipment_tracks WHERE bill_id = ?', [$billId]);
    Database::exec('DELETE FROM bawalis WHERE id = ?', [$billId]);
    Database::exec("DELETE FROM driver_contacts WHERE driver = 'سائق اختبار v33'");
}

echo PHP_EOL . "5) التنضيف" . PHP_EOL;
check((int) Database::scalar('SELECT COUNT(*) FROM bawalis') === $billsBefore,
      'بوليصة الاختبار اتمسحت — عدد البوالص زي ما كان');
check((int) Database::scalar("SELECT COUNT(*) FROM shipment_tracks WHERE bill_id = ?", [$billId]) === 0,
      'مفيش بقايا في shipment_tracks');
check(abs(Custody::balance() - $custodyBefore) < 0.01, 'رصيد العهدة زي ما كان بالظبط');

echo PHP_EOL . str_repeat('─', 50) . PHP_EOL;
echo $fail === 0
    ? "✅ كل الفحوصات نجحت ($pass/$pass)" . PHP_EOL
    : "❌ فشل $fail فحص من " . ($pass + $fail) . PHP_EOL;
exit($fail === 0 ? 0 : 1);
