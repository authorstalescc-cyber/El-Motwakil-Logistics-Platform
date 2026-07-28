<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص «بوليصة المندوب» (v60) — المندوب بيضيف البوليصة من موبايله، وعهدة السائق
 * بتتخصم من محفظته هو مش من خزنة المكتب.
 *
 * ⚠️⚠️ **أخطر حاجة في الوحدة والمحروسة هنا: الاتجاهان لازم يمشوا مع بعض.**
 *    استثناء البوليصة من الخزنة **بلا** إضافتها لعهدة المندوب = فلوس بتتبخّر من
 *    النظام (نفس تحذير v45 عن الفيزا). القسم ٤ بيقيس الاتنين على بوليصة حقيقية:
 *    خزنة المكتب قبل = بعد، وعهدة المندوب نقصت بالمبلغ **بالمليم**.
 *
 * وكمان محروس:
 *   • `RepBillCustody::officeExcludeSql()` هي المصدر الوحيد — مفيش ملف خزنة
 *     كاتب الشرط بإيده، وكل نقاط التزامن الـ٩ بتنادّيها.
 *   • قواعد شاشة المندوب: مفيش صاحب بيان · مفيش خانة تاريخ · مفيش أي رقم
 *     بيخصّ العميل (v35).
 *   • شاشة «تسليم عهدة لسائق» اتشالت **بس جدولها وسجلاته ما اتلمسوش**.
 *
 * كله جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_rep_bill.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Custody;
use App\Models\RepBillCustody;
use App\Models\RepCustody;
use App\Models\RepPortal;

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function sec(string $m): void { echo "\n" . $m . "\n" . str_repeat('─', 62) . "\n"; }
function near(string $l, $a, $e): void {
    abs((float) $a - (float) $e) <= 0.005 ? ok($l)
        : bad("$l — متوقع " . number_format((float) $e, 2) . " / فعلي " . number_format((float) $a, 2));
}
function src(string $file): string {
    $p = dirname(__DIR__) . '/' . $file;
    return is_file($p) ? (string) file_get_contents($p) : '';
}
/** الكود بلا تعليقات — عشان تعليق بيشرح نصًّا قديمًا ما يديش إنذارًا كاذبًا. */
function code_only(string $s): string {
    $s = preg_replace('~/\*.*?\*/~s', '', $s);
    return (string) preg_replace('~^\s*(//|\#|\*).*$~m', '', (string) $s);
}
function has(string $l, string $file, string $needle): void {
    str_contains(src($file), $needle) ? ok($l) : bad("{$l} — «{$needle}» مش موجودة في {$file}");
}
function hasnt(string $l, string $file, string $needle): void {
    !str_contains(code_only(src($file)), $needle) ? ok($l) : bad("{$l} — «{$needle}» لسه في {$file}");
}

echo "فحص بوليصة المندوب (v60)\n" . str_repeat('=', 62) . "\n";

if (!RepBillCustody::ready()) {
    fwrite(STDERR, "شغّل php bin/migrate_v60.php الأول.\n");
    exit(1);
}
if (!RepCustody::available()) {
    fwrite(STDERR, "وحدة عهدة المناديب مش متاحة (migrate_v35).\n");
    exit(1);
}

// ══════════════════════════════════════════════════ ١) البنية
sec('١) البنية — الموديل والكنترولر والمسارات');
foreach (['ready', 'officeExcludeSql', 'totalFor', 'totalsByDay', 'totalsByRep', 'totalAll', 'forRep']
         as $m) {
    method_exists(RepBillCustody::class, $m)
        ? ok("RepBillCustody::$m موجودة") : bad("RepBillCustody::$m ناقصة");
}
foreach (['plateParts', 'fillPresets'] as $m) {
    method_exists(\App\Models\OfficeCar::class, $m)
        ? ok("OfficeCar::$m موجودة (زرار عربية المكتب)") : bad("OfficeCar::$m ناقصة");
}
// ⚠️ تفكيك اللوحة لازم يرفض أي شكل غير ٣ حروف + ٤ أرقام، وإلا الزرار يملا خانات غلط
\App\Models\OfficeCar::plateParts('س ع م 8737') !== null
    ? ok('plateParts بتفكّ «ح ح ح 1234»') : bad('plateParts فشلت على الشكل القانوني');
\App\Models\OfficeCar::plateParts('8737 س ع م') !== null
    ? ok('plateParts بتقبل الترتيب المعكوس كمان') : bad('plateParts رفضت الترتيب المعكوس');
foreach (['س ع 8737', 'س ع م 873', 'س ع م 87370', ''] as $badPlate) {
    \App\Models\OfficeCar::plateParts($badPlate) === null
        ? ok('plateParts بترفض «' . ($badPlate ?: 'فاضي') . '»')
        : bad('plateParts قبلت لوحة مش ٣+٤: «' . $badPlate . '»');
}
foreach (['createBill', 'deleteMyBill', 'myBills'] as $m) {
    method_exists(RepPortal::class, $m) ? ok("RepPortal::$m موجودة") : bad("RepPortal::$m ناقصة");
}
foreach (['billForm', 'billStore', 'billDelete'] as $m) {
    method_exists(\App\Controllers\RepPortalController::class, $m)
        ? ok("RepPortalController::$m موجودة") : bad("RepPortalController::$m ناقصة");
}
has('مسار GET /m/bill/new',  'public/index.php', "\$r->get('/m/bill/new'");
has('مسار POST /m/bill/new', 'public/index.php', "\$r->post('/m/bill/new'");
has('مسار حذف البوليصة',      'public/index.php', "/m/bill/{bid}/delete");
is_file(dirname(__DIR__) . '/app/Views/m/bill_new.php')
    ? ok('شاشة m/bill_new.php موجودة') : bad('شاشة m/bill_new.php ناقصة');

// ⚠️ المسار الحرفي لازم يفضل **قبل** الجشع `/m/permit/{id}` (تحذير ٤).
$routes = src('public/index.php');
$posBill = strpos($routes, "\$r->get('/m/bill/new'");
$posGreedy = strpos($routes, "\$r->get('/m/permit/{id}'");
($posBill !== false && $posGreedy !== false && $posBill < $posGreedy)
    ? ok('⚠️ /m/bill/new حرفي قبل الجشع /m/permit/{id} (تحذير ٤)')
    : bad('/m/bill/new لازم يتسجّل قبل /m/permit/{id}');

// ══════════════════════════════════════════════════ ٢) المصدر الوحيد للشرط
sec('٢) المصدر الوحيد لشرط «العهدة من خزنة المكتب»');
$safeFiles = [
    'app/Models/Custody.php', 'app/Models/Closing.php', 'app/Models/DailyReview.php',
    'app/Controllers/DailyClosingController.php', 'bin/backfill_closings.php',
];
foreach ($safeFiles as $f) {
    $code = code_only(src($f));
    // الشرط لازم يتنادى، وممنوع يتكتب بالإيد.
    if (!str_contains($code, 'officeExcludeSql')) {
        bad("$f مش بينادي RepBillCustody::officeExcludeSql");
        continue;
    }
    // أي ذكر لـcustody_rep_id برّه نداء الدالة = شرط مكتوب بالإيد.
    $stripped = str_replace(['officeExcludeSql'], '', $code);
    str_contains($stripped, 'custody_rep_id')
        ? bad("⚠️ $f كاتب شرط custody_rep_id بإيده — لازم يمرّ على officeExcludeSql")
        : ok("$f بيمرّ على المصدر الوحيد");
}
// عدد نقاط التزامن — لو نقصت يبقى موضع اتنسي.
$callSites = 0;
foreach ($safeFiles as $f) { $callSites += substr_count(code_only(src($f)), 'officeExcludeSql('); }
$callSites >= 9
    ? ok("نقاط التزامن الـ٩ كلها بتنادي الشرط (لقينا {$callSites})")
    : bad("نقاط التزامن ناقصة — لقينا {$callSites} والمتوقع ٩ على الأقل");

sec('٣) الاتجاه التاني — عهدة المندوب بتشوف البوليصة');
$rc = code_only(src('app/Models/RepCustody.php'));
foreach ([
    'spentTotal'      => 'RepBillCustody::totalFor',
    'dayLedger'       => 'RepBillCustody::totalsByDay',
    'dayTotalsByRep'  => 'RepBillCustody::totalsByRep',
    'totalAll'        => 'RepBillCustody::totalAll',
    'spending'        => 'RepBillCustody::forRep',
] as $where => $needle) {
    str_contains($rc, $needle)
        ? ok("RepCustody بينادي $needle ($where)")
        : bad("⚠️ RepCustody مش بينادي $needle — عهدة المندوب هتقول رقم غلط");
}
// cards + dashboard بيستخدموا totalsByRep — لازم مرّتين على الأقل.
substr_count($rc, 'RepBillCustody::totalsByRep()') >= 2
    ? ok('cards و dashboard الاتنين بيضيفوا عهد البوالص')
    : bad('cards أو dashboard ناسي عهد البوالص — الكارت هيقول رقم والكشف رقم تاني');

// ══════════════════════════════════════════════════ ٤) الأثر المالي الحقيقي
sec('٤) ⚠️ الأثر المالي — الخزنة قبل=بعد وعهدة المندوب نقصت');

$pdo = Database::conn();
$pdo->beginTransaction();
try {
    $day = date('Y-m-d');
    // ⚠️ اليوم المتقفل بيمنع الكتابة (v49) — لو النهاردة متقفل، نفتح إقفاله
    //    جوّه الـtransaction (بيترجع مع الrollback فما بيسيبش أثر).
    Database::exec('DELETE FROM daily_closings WHERE close_date >= ?', [$day]);

    // مندوب اختبار + عهدة نازلة له
    Database::exec("INSERT INTO reps (name, status) VALUES ('«فحص v60»', 'active')");
    $repId = (int) Database::lastId();
    Database::exec(
        'INSERT INTO rep_custody_deposits (rep_id, amount, dep_date, note) VALUES (?,?,?,?)',
        [$repId, 50000, $day, 'فحص v60']);

    // إذن مفتوح + حركة بسعة معلومة
    Database::exec(
        "INSERT INTO permits (permit_type, permit_no, permit_date, company, client, status, containers, container_type)
         VALUES ('sader', ?, ?, 'شركة فحص v60', 'عميل فحص v60', 'open', 10, '40 قدم')",
        ['V60-' . substr((string) getmypid(), -5), $day]);
    $pid = (int) Database::lastId();
    Database::exec(
        'INSERT INTO permit_movements (permit_id, seq, container_count) VALUES (?,1,?)', [$pid, 6]);
    $mid = (int) Database::lastId();

    $safeBefore = round((float) Custody::balance(), 2);
    $repBefore  = round(RepCustody::balance($repId), 2);
    $repDayBefore = round(RepCustody::balance($repId, $day), 2);

    $AMOUNT = 4321.50;
    $res = RepPortal::createBill($repId, [
        'permit_id' => $pid, 'movement_id' => $mid,
        'bill_number' => 'V60-BILL-' . substr((string) getmypid(), -5),
        'container_count' => 2, 'container_type' => '40 قدم',
        'container_no' => 'AAAU1234567-BBBU7654321',
        'driver' => 'سائق فحص v60', 'car_owner' => 'مالك فحص v60',
        'car_l1' => 'ف', 'car_l2' => 'ح', 'car_l3' => 'ص', 'car_digits' => '6000',
        'custody' => (string) $AMOUNT, 'bill_date' => $day,
        'newname_driver' => '1', 'newname_car_owner' => '1',
    ]);
    $billId = (int) $res['id'];
    ok('البوليصة اتسجّلت من مسار المندوب (#' . $billId . ')');

    // ① خزنة المكتب ما اتمستش خالص
    near('⚠️ خزنة المكتب قبل = بعد (العهدة مش من الدرج)',
         round((float) Custody::balance(), 2), $safeBefore);

    // ② عهدة المندوب نقصت بالمبلغ **كامل** (مش «المبلغ − 100»)
    near('⚠️ عهدة المندوب التراكمية نقصت بالمبلغ كامل',
         round(RepCustody::balance($repId), 2), round($repBefore - $AMOUNT, 2));
    near('⚠️ عهدة اليوم نقصت بنفس المبلغ',
         round(RepCustody::balance($repId, $day), 2), round($repDayBefore - $AMOUNT, 2));

    // ③ الكارت والكشف والداشبورد لازم يقولوا نفس الرقم
    $cards = [];
    foreach (RepCustody::cards() as $c) { $cards[(int) $c['id']] = $c; }
    near('كارت المندوب (cards) بيطابق balance بالمليم',
         (float) ($cards[$repId]['balance'] ?? 0), RepCustody::balance($repId));
    $dayMap = RepCustody::dayTotalsByRep($day);
    near('dayTotalsByRep بيطابق عهدة اليوم بالمليم',
         (float) ($dayMap[$repId]['balance'] ?? 0), RepCustody::balance($repId, $day));

    $rows = RepCustody::spending($repId, $day);
    $billRows = array_values(array_filter($rows, static fn ($r) => ($r['src'] ?? '') === 'bill'));
    count($billRows) === 1
        ? ok("كشف الصرف فيه صف بـsrc='bill'") : bad('كشف الصرف مالوش صف للبوليصة');
    if ($billRows) { near('مبلغ الصف = العهدة كاملة', (float) $billRows[0]['amount'], $AMOUNT); }

    $act = RepCustody::dayActivity($repId, $day);
    ((int) ($act['bill']['cnt'] ?? 0)) === 1
        ? ok('أداء اليوم فيه خانة «bill»') : bad('dayActivity مالهاش خانة bill');

    // ④ إجمالي «برّه مع المناديب» شايف البوليصة
    $out = RepCustody::totalOutstanding();
    $sum = 0.0;
    foreach (RepCustody::cards() as $c) { $sum += (float) $c['balance']; }
    near('totalOutstanding = مجموع أرصدة الكروت', $out, round($sum, 2));

    // ⑤ البوليصة موسومة، وصاحب البيان فاضي، والتاريخ = يوم العمل
    $saved = Database::row('SELECT * FROM bawalis WHERE id = ?', [$billId]);
    ((int) $saved['custody_rep_id']) === $repId
        ? ok('البوليصة موسومة بـcustody_rep_id') : bad('الوسم ما اتحطش');
    ($saved['declaration_owner'] === null && $saved['declaration_owner_id'] === null)
        ? ok('⚠️ صاحب البيان فاضي (طلب المالك)') : bad('صاحب البيان اتكتب من شاشة المندوب');
    ((string) $saved['bill_date'] === $day)
        ? ok('⚠️ التاريخ = يوم العمل') : bad('التاريخ مش يوم العمل');
    ((float) $saved['freight'] == 0.0 && (float) $saved['transfer'] == 0.0
        && (float) $saved['overnight_collection'] == 0.0)
        ? ok('⚠️ أرقام العميل (نولون/تحويلة/بياتة) اتسابت صفر (قاعدة v35)')
        : bad('رقم بيخصّ العميل اتكتب من شاشة المندوب');
    ((string) $saved['company'] === 'شركة فحص v60' && (int) $saved['permit_id'] === $pid)
        ? ok('الشركة والإذن اتاخدوا من الإذن نفسه') : bad('بيانات الإذن ما اتنقلتش');

    // ⑥ بوليصة بعهدة صفر ما بتتوسمش (مفيش فلوس خرجت من محفظة حد)
    $res0 = RepPortal::createBill($repId, [
        'permit_id' => $pid, 'movement_id' => $mid,
        'bill_number' => 'V60-ZERO-' . substr((string) getmypid(), -5),
        'container_count' => 1, 'container_type' => '40 قدم', 'container_no' => 'CCCU1112223',
        'driver' => 'سائق فحص v60', 'car_owner' => 'مالك فحص v60',
        'car_l1' => 'ف', 'car_l2' => 'ح', 'car_l3' => 'ص', 'car_digits' => '6000',
        'custody' => '0', 'bill_date' => $day,
    ]);
    $zero = Database::row('SELECT custody_rep_id FROM bawalis WHERE id = ?', [(int) $res0['id']]);
    $zero['custody_rep_id'] === null
        ? ok('بوليصة بعهدة صفر ما بتتوسمش (مفيش فلوس اتدفعت)')
        : bad('بوليصة بصفر اتوسمت لمندوب');

    // ⑦ بوليصة المكتب (بلا وسم) لسه بتتخصم من الخزنة زي ما كانت
    $safeNow = round((float) Custody::balance(), 2);
    Database::exec(
        "INSERT INTO bawalis (permit_id, movement_id, bill_type, bill_date, bill_number,
                              company, container_count, driver, car_no, custody, discount)
         VALUES (?,?,'sader',?,?,'شركة فحص v60',1,'سائق فحص v60','ف ح ص 6000',?,0)",
        [$pid, $mid, $day, 'V60-OFFICE-' . substr((string) getmypid(), -5), 1000]);
    near('⚠️ بوليصة المكتب لسه بتخصم من الخزنة (السلوك القديم سليم)',
         round((float) Custody::balance(), 2),
         round($safeNow - Custody::billDeduction(1000, 'ف ح ص 6000'), 2));

    // ⑧ الحذف بيرجّع العهدة
    $repNow = round(RepCustody::balance($repId), 2);
    RepPortal::deleteMyBill($repId, $billId, $day);
    near('حذف البوليصة رجّع العهدة لمحفظة المندوب',
         round(RepCustody::balance($repId), 2), round($repNow + $AMOUNT, 2));

    /* ══════════════════════════════════════ ٤ب) زرار «عربية المكتب»
       ⚠️ **أهم فحص في الميزة:** زرار بيملا قيم الخادم بيرفضها أسوأ من مفيش
          زرار خالص. بناخد نفس القيم من `OfficeCar::fillPresets()` بالحرف
          ونحفظ بيها **بلا أي تأكيد اسم جديد** — لازم تعدّي. */
    if (\App\Models\OfficeCar::available()) {
        $presets = \App\Models\OfficeCar::fillPresets();
        $presets ? ok('فيه عربية مكتب واحدة على الأقل جاهزة للزرار')
                 : bad('مفيش أي عربية مكتب بلوحة ٣ حروف + ٤ أرقام');
        foreach ($presets as $oc) {
            $oc['owner'] === \App\Models\OfficeCar::OWNER_LABEL
                ? ok('صاحب العربية من الثابت الواحد OWNER_LABEL')
                : bad('صاحب العربية مكتوب بالإيد');
            try {
                $r = RepPortal::createBill($repId, [
                    'permit_id' => $pid, 'movement_id' => $mid,
                    'bill_number' => 'V60-OC-' . mt_rand(10000, 99999),
                    'container_count' => 1, 'container_type' => '40 قدم',
                    'container_no' => 'OCU' . mt_rand(1000000, 9999999),
                    'driver' => $oc['driver'], 'car_owner' => $oc['owner'],
                    'car_l1' => $oc['letters'][0], 'car_l2' => $oc['letters'][1],
                    'car_l3' => $oc['letters'][2], 'car_digits' => $oc['digits'],
                    'custody' => '100', 'bill_date' => $day,
                ]);
                $b = Database::row('SELECT car_no, driver, car_owner FROM bawalis WHERE id = ?',
                                   [(int) $r['id']]);
                ok('⚠️ قيم الزرار بتعدّي تحقّق الخادم بلا تأكيد اسم جديد');
                // اللوحة المخزَّنة لازم تربط على **نفس** سيارة السجل، وإلا
                // رحلات العربية تتفرّق على لوحتين في وحدة سيارات المكتب.
                \App\Models\OfficeCar::plateKey((string) $b['car_no'])
                    === \App\Models\OfficeCar::plateKey($oc['plate'])
                    ? ok('⚠️ اللوحة المحفوظة بتربط على نفس سيارة السجل')
                    : bad('اللوحة المحفوظة مفتاحها مختلف — الرحلات هتتفرّق');
                (string) $b['car_owner'] === $oc['owner']
                    ? ok('صاحب العربية اتخزّن «' . $oc['owner'] . '»')
                    : bad('صاحب العربية اتغيّر عند الحفظ');
            } catch (\Throwable $e) {
                bad('⚠️ قيم زرار عربية المكتب اترفضت من الخادم: ' . $e->getMessage());
            }
        }
    } else {
        ok('وحدة سيارات المكتب مش مفعّلة — الزرار بيختفي بأمان');
    }

    // ══════════════════════════════════════════ ٥) الحُرّاس
    sec('٥) الحُرّاس — الفورم مش مصدر ثقة');
    $reject = static function (string $label, array $data, string $field) use ($repId): void {
        try {
            RepPortal::createBill($repId, $data);
            bad("$label — اتقبلت وكان المفروض ترفض");
        } catch (\App\Models\RepSubmissionValidationException $e) {
            $errs = method_exists($e, 'errors') ? $e->errors() : [];
            ($field === '' || isset($errs[$field]))
                ? ok($label)
                : bad("$label — اترفضت بس على مفتاح تاني (" . implode(',', array_keys($errs)) . ")");
        } catch (\Throwable $e) {
            bad("$label — استثناء غير متوقّع: " . $e->getMessage());
        }
    };
    $base = [
        'permit_id' => $pid, 'movement_id' => $mid, 'bill_number' => 'V60-X-' . mt_rand(1000, 9999),
        'container_count' => 1, 'container_type' => '40 قدم', 'container_no' => 'AAAU1234567',
        'driver' => 'سائق فحص v60', 'car_owner' => 'مالك فحص v60',
        'car_l1' => 'ف', 'car_l2' => 'ح', 'car_l3' => 'ص', 'car_digits' => '6000',
        'custody' => '10', 'bill_date' => $day,
    ];
    $reject('رقم بوليصة مكرّر بيترفض', ['bill_number' => (string) $res0['label'] === ''
        ? 'x' : 'V60-ZERO-' . substr((string) getmypid(), -5)] + $base, 'bill_number');
    $reject('رقم بوليصة فاضي بيترفض', ['bill_number' => ''] + $base, 'bill_number');
    $reject('حركة من إذن تاني بترفض', ['movement_id' => 999999] + $base, 'movement_id');
    $reject('عدد حاويات أكتر من سعة الحركة بيترفض',
            ['container_count' => 99] + $base, 'container_count');
    $reject('تصنيف حاويات برّه القائمة المقفولة بيترفض',
            ['container_type' => 'حاجة مش في القائمة'] + $base, 'container_type');
    $reject('سائق فاضي بيترفض', ['driver' => ''] + $base, 'driver');
    $reject('صاحب عربية فاضي بيترفض (بقى إجباري)', ['car_owner' => ''] + $base, 'car_owner');
    // ⚠️ رقم العربية = ٣ حروف + ٤ أرقام بالظبط
    $reject('حرف عربية ناقص بيترفض', ['car_l3' => ''] + $base, 'car_no');
    $reject('رقم مكان الحرف بيترفض', ['car_l2' => '7'] + $base, 'car_no');
    $reject('حرف إنجليزي بيترفض', ['car_l1' => 'A'] + $base, 'car_no');
    $reject('أرقام عربية ٣ بس بترفض', ['car_digits' => '123'] + $base, 'car_no');
    $reject('أرقام عربية ٥ بترفض', ['car_digits' => '12345'] + $base, 'car_no');
    $reject('أرقام عربية فاضية بترفض', ['car_digits' => ''] + $base, 'car_no');
    // ⚠️ أرقام الحاويات إجبارية — رقم لكل حاوية
    $reject('بلا رقم حاوية بيترفض', ['container_no' => ''] + $base, 'container_no');
    $reject('حاويتين ورقم واحد بيترفض',
            ['container_count' => 2, 'container_no' => 'AAAU1234567'] + $base, 'container_no');
    $reject('عدد حاويات صفر بيترفض', ['container_count' => 0] + $base, 'container_count');
    $reject('عهدة فاضية بترفض (مش بتتحسب صفر بالسكوت)', ['custody' => ''] + $base, 'custody');
    $reject('عهدة بالسالب بترفض', ['custody' => '-5'] + $base, 'custody');

    // الإذن المقفول — الحد الأمني الحقيقي (قاعدة v43)
    Database::exec("UPDATE permits SET status='closed' WHERE id=?", [$pid]);
    $reject('⚠️ الكتابة على إذن مقفول بترفض (قاعدة v43)', $base, 'permit_id');
    Database::exec("UPDATE permits SET status='open' WHERE id=?", [$pid]);

    // اليوم المتقفل بيمنع الكتابة (قاعدة v49) — بس لما فيه عهدة فعلاً
    Database::exec(
        'INSERT INTO daily_closings (close_date, opening, topups, expenses, driver_custody,
                                     closing_balance, is_locked, closed_by)
         VALUES (?,0,0,0,0,0,1,?)', [$day, 'فحص v60']);
    $reject('⚠️ اليوم المتقفل بيمنع بوليصة بعهدة (قاعدة v49)', $base, 'bill_date');
    Database::exec('DELETE FROM daily_closings WHERE closed_by = ?', ['فحص v60']);

    $pdo->rollBack();
    ok('كل الـfixtures اترجعت (rollback) — مفيش أي أثر');
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    bad('استثناء أثناء فحص الأثر المالي: ' . $e->getMessage()
        . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

// ══════════════════════════════════════════════════ ٦) قواعد شاشة المندوب
sec('٦) شاشة المندوب — قواعد v35 و v49');
$view = src('app/Views/m/bill_new.php');
foreach ([
    'declaration_owner' => 'مفيش خانة صاحب البيان (طلب المالك)',
    'name="freight"'    => 'مفيش خانة نولون (فلوس العميل — قاعدة v35)',
    'actual_amount'     => 'مفيش سعر فعلي للعميل (قاعدة v35)',
    'actual_unit'       => 'مفيش سعر وحدة للعميل (قاعدة v35)',
    'name="bill_date"'  => 'مفيش خانة تاريخ (يوم العمل بيتفرض — قاعدة v49)',
    'type="date"'       => 'مفيش منتقي تاريخ',
] as $needle => $label) {
    !str_contains($view, $needle) ? ok($label) : bad("$label — «$needle» موجودة في الشاشة");
}
str_contains($view, "_workday.php")
    ? ok('الشاشة بتعرض بطاقة يوم العمل المشتركة') : bad('بطاقة يوم العمل ناقصة');
str_contains($view, 'container_no[]')
    ? ok('خانات الحاويات اسمها container_no[] (بيلمّها bootstrap)')
    : bad('⚠️ اسم خانات الحاويات غلط — هتتكتب «Array» في القاعدة');
str_contains($view, 'js-office-car')
    ? ok('زرار «عربية المكتب» موجود في الشاشة') : bad('زرار عربية المكتب ناقص');
foreach (['data-letters', 'data-digits', 'data-driver', 'data-owner'] as $attr) {
    str_contains($view, $attr)
        ? ok("الزرار بيحمل $attr") : bad("الزرار ناقصه $attr");
}
// ⚠️ الملحوظة: الجملة الأولى بس (طلب المالك) — جملة العهدة اتشالت
str_contains($view, 'اكتب بيانات البوليصة زي ما هي في الورقة')
    ? ok('ملحوظة «اكتب بيانات البوليصة زي ما هي في الورقة» موجودة')
    : bad('الملحوظة الأساسية اتشالت');
!str_contains($view, 'بتتخصم من عهدتك انت')
    ? ok('جملة العهدة الزيادة اتشالت من الملحوظة (طلب المالك)')
    : bad('جملة العهدة لسه في الملحوظة');
str_contains($view, 'name="_form_token"')
    ? ok('رمز لمرة واحدة ضد الإرسال المزدوج') : bad('_form_token ناقص — الحفظ هيتكرر');
str_contains($view, 'js_json(')
    ? ok('البيانات بتتهرّب بـjs_json (القاعدة الأمنية)') : bad('js_json مش مستخدَمة');
!str_contains($view, 'json_encode')
    ? ok('⚠️ مفيش json_encode في الـview (قاعدة XSS المخزَّن)')
    : bad('json_encode في view — ممنوع، استخدم js_json');
// التاريخ بيتاخد من الجلسة مش من الفورم
str_contains(code_only(src('app/Controllers/RepPortalController.php')),
             "'bill_date'       => RepAuth::workDate()")
    ? ok('⚠️ الكنترولر بياخد التاريخ من الجلسة مش من الفورم')
    : bad('التاريخ مش مفروض من الجلسة');

// ⚠️ كل كلاس بيتحقن من السكربت لازم يكون له تعريف CSS (مصيدة .table-mobile-scroll)
$css = src('public/assets/css/m.css');
foreach (['bn-step', 'bn-num', 'bn-permit', 'bn-cap', 'bn-left', 'bn-nos', 'bn-no',
          'bn-review', 'bn-review-h', 'bn-car', 'bn-car-l', 'bn-car-d', 'req',
          'bn-quick', 'bn-quick-btn'] as $cls) {
    str_contains($css, '.' . $cls)
        ? ok("الكلاس .$cls معرَّف في m.css") : bad("⚠️ .$cls بيتطبع وملوش CSS — هيفشل بالسكوت");
}
foreach (['bn-cap is-over', 'bn-left is-neg', 'bn-quick-btn on'] as $state) {
    $sel = '.' . str_replace(' ', '.', $state);
    str_contains($css, $sel) ? ok("حالة $sel معرَّفة") : bad("حالة $sel بتتحقن وملهاش CSS");
}

// ⚠️ صيغة اللوحة المخزَّنة لازم تفضل مطابقة لـ`PermitController::composeCarNo`
//    («حروف مسافة أرقام»)، وإلا نفس العربية تتقري لوحتين في وحدة سيارات المكتب.
$rp = code_only(src('app/Models/RepPortal.php'));
// ⚠️ علامتان مفردتان: النص فيه $letters و$carDigits ولو اتحطّ في "" PHP هتفسّرهم
//    متغيّرات فاضية والفحص يفشل بإنذار كاذب (اتصاد فعليًّا).
str_contains($rp, 'implode(\' \', array_filter($letters)) . \' \' . $carDigits')
    ? ok('⚠️ صيغة اللوحة مطابقة لصيغة المكتب («ح ح ح 1234»)')
    : bad('صيغة اللوحة اتغيّرت — وحدة سيارات المكتب هتشوفها لوحة تانية');
str_contains($rp, "\\x{0621}-\\x{064A}")
    ? ok('خانة الحرف بتقبل حرف عربي واحد بس') : bad('فحص حرف اللوحة ناقص');

// ══════════════════════════════════════════════════ ٧) شاشة التسليم القديمة
sec('٧) «تسليم عهدة لسائق» — الشاشة اتشالت والسجلات ما اتلمستش');
!is_file(dirname(__DIR__) . '/app/Views/m/handover.php')
    ? ok('شاشة m/handover.php اتشالت') : bad('شاشة التسليم لسه موجودة');
foreach (['handoverForm', 'handoverStore', 'handoverDelete'] as $m) {
    !method_exists(\App\Controllers\RepPortalController::class, $m)
        ? ok("RepPortalController::$m اتشالت") : bad("$m لسه موجودة");
}
hasnt('مسار /m/handover اتشال', 'public/index.php', "\$r->get('/m/handover'");
hasnt('زرار «تسليم سائق» اتشال من /m', 'app/Views/m/home.php', "url('m/handover')");
has('زرار «بوليصة جديدة» مكانه في /m', 'app/Views/m/home.php', "url('m/bill/new')");

// ⚠️ الجدول والموديل **لازم يفضلوا** — سجلاته بتتخصم من عهدة المناديب،
//    وشيلها بيغيّر أرصدة تاريخية.
class_exists(\App\Models\RepDriverHandover::class)
    ? ok('⚠️ موديل RepDriverHandover لسه موجود (سجلاته بتتخصم من العهدة)')
    : bad('الموديل اتشال — أرصدة المناديب هتتغيّر');
(bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = 'rep_driver_handovers'")
    ? ok('⚠️ جدول rep_driver_handovers لسه موجود') : bad('الجدول اتمسح — أرصدة تاريخية ضاعت');
has('RepCustody لسه بيخصم التسليمات القديمة', 'app/Models/RepCustody.php',
    'RepDriverHandover::totalFor');
has("كشف الصرف لسه فيه src='handover'", 'app/Models/RepCustody.php',
    "RepDriverHandover::forRep");

echo "\n" . str_repeat('=', 62) . "\n";
echo "النتيجة: $pass نجح · $fail فشل\n";
exit($fail > 0 ? 1 : 0);
