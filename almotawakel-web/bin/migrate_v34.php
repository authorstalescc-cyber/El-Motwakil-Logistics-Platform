<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * ترحيل v34 — نقاط الوجهات + المسافة المتبقية والوصول المتوقع (فوق v33).
 *
 * بيدّي لكل وجهة معروفة إحداثيات مرجعية، فنقدر نحسب:
 *   - السائق بيبعد قد إيه عن وجهته دلوقتي
 *   - متوقع يوصل خلال كام ساعة
 *
 * ⚠️ **استرشادي بحت — تقدير مش وعد** (زي مرشد الإيصالات v32):
 *    - المسافة خط مستقيم (Haversine) × معامل طريق، مش مسافة طريق حقيقية.
 *    - الوصول المتوقع = المسافة ÷ سرعة (سرعة الرحلة الفعلية إن توفّرت، وإلا الافتراضية).
 *    - **لا يمنع أي إدخال ولا يمسّ أي حساب/عهدة/تقفيل** — وصفي بحت زي باقي v33.
 *
 * الربط بالوجهة **نصّي بلا FK** (تحذير 3) عبر normkey + أسماء بديلة، لأن الوجهات
 * في `bawalis.destination` مكتوبة باختصارات («العاشر»، «ب سويف»، «ط التحدى»).
 *
 * الإحداثيات متحقَّق منها مقابل OpenStreetMap (الأغلب خلال 3 كم).
 * آمن لإعادة التشغيل (idempotent) — ولا يكتب فوق أي تعديل سابق للمالك.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\App;
use App\Models\Maintenance;

function v34Say(string $m): void { echo $m . PHP_EOL; }

v34Say('ترحيل v34 — نقاط الوجهات والوصول المتوقع…');

// ----------------------------------------------------------------------------
// 1) جدول نقاط الوجهات
// ----------------------------------------------------------------------------
$exists = (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',
    ['destination_points']
);
if (!$exists) {
    Database::exec("
        CREATE TABLE destination_points (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name       VARCHAR(120) NOT NULL COMMENT 'الاسم القانوني للوجهة',
            normkey    VARCHAR(120) NOT NULL COMMENT 'الاسم مُوحّدًا (Maintenance::normkey)',
            aliases    VARCHAR(400) NULL COMMENT 'أسماء بديلة مُوحّدة مفصولة بـ | (الاختصارات المستعملة فعلاً)',
            lat        DECIMAL(10,7) NOT NULL,
            lng        DECIMAL(10,7) NOT NULL,
            note       VARCHAR(160) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_dest_normkey (normkey)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    v34Say('  + جدول destination_points');
} else {
    v34Say('  = جدول destination_points (موجود)');
}

// عمود «اتحدّدت تلقائيًا» — نقاط جابها النظام من OpenStreetMap لوحده تستاهل مراجعة
// سريعة من المالك (أثبتنا إن OSM بيرجّع مقبرة في الأقصر لـ«العاشر»، فالفلتر بيرفضها
// لكن يفضل المالك يبصّ على أي نقطة تلقائية قبل ما يعتمد مسافتها).
$hasAuto = (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
    ['destination_points', 'is_auto']
);
if (!$hasAuto) {
    Database::exec(
        "ALTER TABLE destination_points
           ADD COLUMN is_auto TINYINT(1) NOT NULL DEFAULT 0
           COMMENT 'اتحدّدت تلقائيًا من OpenStreetMap — تستاهل مراجعة'"
    );
    v34Say('  + destination_points.is_auto');
} else {
    v34Say('  = destination_points.is_auto (موجود)');
}

// ----------------------------------------------------------------------------
// 2) الإعدادات
// ----------------------------------------------------------------------------
if (App::setting('track_avg_speed_kmh', null) === null) {
    App::setSetting('track_avg_speed_kmh', '45');
    v34Say('  + إعداد: متوسط سرعة النقل الافتراضي = 45 كم/س');
}
if (App::setting('track_road_factor', null) === null) {
    // الخط المستقيم أقصر من الطريق الحقيقي — 1.3 تقدير شائع للطرق المصرية
    App::setSetting('track_road_factor', '1.3');
    v34Say('  + إعداد: معامل الطريق (خط مستقيم → طريق) = 1.3');
}

// ----------------------------------------------------------------------------
// 3) بذر الوجهات — مبنية على الوجهات المستعملة فعلاً في البوالص
//    [الاسم القانوني, lat, lng, [الاختصارات المستعملة]]
//    الاختصارات هنا هي اللي normkey لوحده مش هيمسكها.
// ----------------------------------------------------------------------------
$seed = [
    // ── مدن ومناطق الوجهات الشائعة ──
    ['العاشر من رمضان', 30.2966, 31.7419, ['العاشر']],
    ['بني سويف',        29.0661, 31.0994, ['ب سويف', 'بنى سويف']],
    ['6 أكتوبر',         29.9285, 30.9188, ['اكتوبر', 'أكتوبر', '٦ اكتوبر', 'ستة اكتوبر']],
    ['مدينة السادات',   30.3661, 30.5289, ['السادات', 'م السادات']],
    ['الصف',             29.5720, 31.2860, []],
    ['الإسماعيلية',     30.5965, 32.2715, ['اسماعيليه']],
    ['النوبارية',        30.6600, 30.1000, ['نوباريه']],
    ['العبور',           30.2033, 31.4667, ['مدينة العبور']],
    ['المحلة الكبرى',   30.9700, 31.1669, ['المحلة', 'المحله']],
    ['برج العرب',        30.9003, 29.5513, ['برج']],
    ['بورسعيد',          31.2653, 32.3019, ['بور سعيد']],
    ['الفيوم',           29.3084, 30.8428, []],
    ['شبرا الخيمة',     30.1286, 31.2422, ['شبرا']],
    ['أبو حماد',         30.5333, 31.6667, []],
    ['إنشاص',            30.3833, 31.4167, []],
    ['ميت غمر',          30.7167, 31.2667, []],
    ['أبيس',             31.1333, 30.0333, []],
    ['القناطر الخيرية', 30.1936, 31.1350, ['القناطر']],
    ['الزقازيق',         30.5877, 31.5020, ['الشرقية', 'الشرقيه']],
    ['كفر الدوار',       31.1333, 30.1333, ['ك الدوار', 'كفر دوار']],
    ['الخطاطبة',         30.3167, 30.7333, []],
    ['بلبيس',            30.4188, 31.5646, []],
    ['مدينة بدر',        30.1428, 31.7428, ['م بدر', 'مد بدر', 'بدر']],
    ['العامرية',         31.0167, 29.8000, ['عامريه']],
    ['جسر السويس',       30.1000, 31.3500, ['ج السويس']],
    ['قها',              30.2833, 31.2000, []],
    ['مدينة نصر',        30.0561, 31.3300, ['م نصر']],
    ['المنيا',           28.1099, 30.7503, []],
    ['جمصة',             31.4333, 31.5000, []],
    ['التجمع الخامس',   30.0300, 31.4700, ['التجمع', 'تجمع']],
    // ── الموانئ ونقاط الانطلاق المعتادة ──
    ['الإسكندرية',      31.2001, 29.9187, ['اسكندريه', 'اسكندرية']],
    ['الدخيلة',          31.1333, 29.8167, ['دخيله']],
    ['دمياط',            31.4165, 31.8133, []],
    ['العين السخنة',    29.6396, 32.3057, ['السخنة', 'السخنه', 'ع السخنة']],
    ['السويس',           29.9668, 32.5498, []],
    ['القاهرة',          30.0444, 31.2357, ['القاهره', 'مصر']],
    ['الجيزة',           30.0131, 31.2089, ['الجيزه']],
    ['طنطا',             30.7865, 31.0004, []],
    ['المنصورة',         31.0409, 31.3785, ['المنصوره']],
    ['بنها',             30.4667, 31.1833, []],
    ['أسيوط',            27.1809, 31.1837, []],
    ['العريش',           31.1249, 33.7996, []],
    ['مرسى مطروح',      31.3543, 27.2373, ['مطروح']],
    ['حلوان',            29.8419, 31.3341, []],
    ['كفر الشيخ',        31.1117, 30.9394, ['ك الشيخ']],
];

$added = 0; $kept = 0;
foreach ($seed as [$name, $lat, $lng, $aliases]) {
    $nk = Maintenance::normkey($name);
    // ما نكتبش فوق أي وجهة موجودة (احترام تعديل المالك للإحداثيات)
    if (Database::scalar('SELECT COUNT(*) FROM destination_points WHERE normkey = ?', [$nk])) {
        $kept++;
        continue;
    }
    $al = array_values(array_unique(array_filter(array_map(
        fn($a) => Maintenance::normkey($a), $aliases
    ), fn($a) => $a !== '' && $a !== $nk)));

    Database::exec(
        'INSERT INTO destination_points (name, normkey, aliases, lat, lng) VALUES (?, ?, ?, ?, ?)',
        [$name, $nk, $al ? implode('|', $al) : null, $lat, $lng]
    );
    $added++;
}
v34Say("  + وجهات جديدة: $added" . ($kept ? " (موجودة سلفًا: $kept — ما اتلمستش)" : ''));

// ----------------------------------------------------------------------------
// 4) تقرير التغطية — الوجهات المستعملة اللي لسه بلا إحداثيات
// ----------------------------------------------------------------------------
$rows = Database::all(
    "SELECT destination d, COUNT(*) n FROM bawalis
      WHERE destination IS NOT NULL AND destination <> '' AND is_legacy = 0
      GROUP BY destination ORDER BY n DESC"
);
$points = Database::all('SELECT normkey, aliases FROM destination_points');
$known  = [];
foreach ($points as $p) {
    $known[$p['normkey']] = true;
    foreach (explode('|', (string) $p['aliases']) as $a) {
        if (trim($a) !== '') { $known[$a] = true; }
    }
}
$hit = 0; $miss = 0; $missList = [];
foreach ($rows as $r) {
    if (isset($known[Maintenance::normkey($r['d'])])) { $hit += (int) $r['n']; }
    else { $miss += (int) $r['n']; $missList[$r['d']] = (int) $r['n']; }
}
$tot = $hit + $miss;
v34Say('');
v34Say(sprintf('  تغطية الوجهات: %d من %d بوليصة (%.0f%%) لها نقطة على الخريطة',
       $hit, $tot, $tot ? $hit / $tot * 100 : 0));
if ($missList) {
    arsort($missList);
    v34Say('  وجهات لسه بلا إحداثيات (أضِفها من /tracking/places وقت ما تحب):');
    $i = 0;
    foreach ($missList as $d => $n) {
        if ($i++ >= 12) { v34Say('     … و' . (count($missList) - 12) . ' غيرها'); break; }
        v34Say(sprintf('     %4d × %s', $n, $d));
    }
}

v34Say('');
v34Say('اكتمل ترحيل v34 بنجاح.');
