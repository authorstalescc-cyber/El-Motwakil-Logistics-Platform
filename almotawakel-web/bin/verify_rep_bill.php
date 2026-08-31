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

/* ⚠️⚠️ ١ب) **الكنترولر بيوصّل للموديل كل حقل بيقراه** — باج حقيقي اتصاد
   2026-08-02 ببلاغ المالك «مشكلة في إضافة البوليصة من المندوب»:
   الشاشة بتبعت رقم العربية في **٤ خانات** (`car_l1..3` + `car_digits`)
   و`normaliseBill` بيقراهم بالأسماء دي، لكن `billStore` كان بيبني حزمة فيها
   `car_no` **اللي الفورم أصلاً مش بيبعته** — فالأربعة كانوا بيتسقطوا و**كل**
   بوليصة بتترفض بـ«اكتب حروف العربية التلاتة» والمندوب مالي الخانات.
   كان على الإنتاج كمان (`grep -c car_l1` على الكنترولر = صفر).

   ⚠️ **ليه الفحص القديم ما مسكهوش:** كل اختباراته بتنادي `RepPortal::createBill`
      **مباشرةً** بحزمة مكتوبة بالإيد، فالوصلة بين الفورم والموديل ما كانتش
      متغطّية. الفحص ده بيقارن **الحقول اللي الموديل بيقراها** بـ**اللي الكنترولر
      بيبعتها** — فأي حقل جديد يتضاف للموديل وينتسي في الكنترولر بيتمسك فورًا. */
sec('١ب) وصلة الفورم ← الكنترولر ← الموديل (الباج اللي وقف إضافة البوالص)');
$modelSrc = src('app/Models/RepPortal.php');
$ctrlSrc  = src('app/Controllers/RepPortalController.php');
$viewSrc  = src('app/Views/m/bill_new.php');

// الحقول اللي `normaliseBill` بيقراها من الحزمة: `$d['xxx']`
$nb = strpos($modelSrc, 'function normaliseBill');
$nbEnd = strpos($modelSrc, 'public static function', $nb + 30);
$nbBody = substr($modelSrc, $nb, ($nbEnd !== false ? $nbEnd - $nb : 9000));
preg_match_all("/\\\$d\\['([a-z0-9_]+)'\\]/i", $nbBody, $mm);
$needs = array_values(array_unique($mm[1] ?? []));
count($needs) >= 8 ? ok('اتقرا ' . count($needs) . ' حقل من `normaliseBill`')
                   : bad('اشتقاق حقول `normaliseBill` فشل — لقينا ' . count($needs));

// الحزمة اللي `billStore` بيبعتها
$bs = strpos($ctrlSrc, 'function billStore');
$bsEnd = strpos($ctrlSrc, 'PushNotify::billLinked', $bs);
$bsBody = substr($ctrlSrc, $bs, ($bsEnd !== false ? $bsEnd - $bs : 4000));
$missing = [];
foreach ($needs as $f) {
    if (!preg_match("/'" . preg_quote($f, '/') . "'\s*=>/", $bsBody)) { $missing[] = $f; }
}
$missing === []
    ? ok('★ الكنترولر بيوصّل كل حقل بيقراه الموديل')
    : bad('★ حقول بيقراها الموديل ومش بتوصل من الكنترولر: ' . implode(' · ', $missing));

/* وخانات اللوحة الأربعة بالذات — دي اللي وقعت.
   ⚠️ الشاشة بتبني تلات أسماء منها في حلقة (`name="car_l<?= $i + 1 ?>"`)، فالبحث
      عن `name="car_l1"` حرفيًّا في الملف بيدّي **فشلًا كاذبًا**. الفحص على
      البادئة + وجود الاسم في حزمة الكنترولر. */
str_contains($viewSrc, 'name="car_l')
    ? ok('الشاشة بتبعت خانات حروف اللوحة') : bad('الشاشة مش بتبعت خانات حروف اللوحة');
foreach (['car_l1', 'car_l2', 'car_l3', 'car_digits'] as $f) {
    str_contains($bsBody, "'" . $f . "'")
        ? ok("خانة اللوحة «{$f}» بتتقرا في الكنترولر")
        : bad("خانة اللوحة «{$f}» مقطوعة بين الشاشة والكنترولر");
}
// ⚠️ `car_no` ممنوع يرجع للحزمة: الموديل بيبنيه بنفسه، ووجوده بيوهم إن الحقل واصل
!preg_match("/'car_no'\s*=>/", $bsBody)
    ? ok('⚠️ `car_no` مش في الحزمة (الموديل بيركّبه من الأربعة)')
    : bad('⚠️ `car_no` رجع للحزمة — بيوهم إن اللوحة واصلة وهي مش واصلة');

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
/* عدد نقاط التزامن — لو نقصت يبقى موضع اتنسي.
   ⚠️⚠️ **الرقم نزل من ٩ لـ٨ في 2026-08-31 — والسبب متسجّل هنا عشان ما يتقريش
      «حد ضعّف الحارس».** `Custody::ledger()` اتشالت بالكامل (كانت بتحسب سجل
      الخزنة بإيدها فبتدّي رقمًا يخالف الرصيد بمئات الألوف — docs/21). النقطة
      اللي راحت كانت جوّه الدالة المتشالة نفسها، **والتغطية ما نقصتش**: البديل
      `Custody::movements()` ما بيقراش `bawalis` خالص، بيمرّ على
      `DailyReview::officeCashItems` — وهي في القايمة فوق وبتنادي الشرط وبتعدّي
      الفحص. يعني الاستعلام المباشر اتشال، مش الحارس.
   ⚠️ الاستعلام التاني الفاضل في `Custody.php` هو `capError()` — قراءة صفّ واحد
      بالـ`id` بلا أي تجميع، فمالوش علاقة بالاستثناء أصلاً.
   ⚠️ **لو الرقم ده نزل تاني، ما تنزّلوش قبل ما تتأكد إن الاستعلام راح لمصدر
      محروس** — النزول بلا سبب مكتوب معناه نقطة اتنسيت. */
$callSites = 0;
foreach ($safeFiles as $f) { $callSites += substr_count(code_only(src($f)), 'officeExcludeSql('); }
$callSites >= 8
    ? ok("نقاط التزامن كلها بتنادي الشرط (لقينا {$callSites})")
    : bad("نقاط التزامن ناقصة — لقينا {$callSites} والمتوقع ٨ على الأقل");

/* ⚠️ حارس مضاف مع النزول: البديل لازم يفضل **ما بيقراش `bawalis`** — أول ما
   يرجع يقراها بإيده يبقى لازم ينادي الشرط، والفحص ده بيمسك اللحظة دي. */
$movBody = code_only(src('app/Models/Custody.php'));
$mStart  = strpos($movBody, 'function movements');
$mEnd    = strpos($movBody, 'function groupIcon');
$movOnly = ($mStart !== false && $mEnd !== false && $mEnd > $mStart)
    ? substr($movBody, $mStart, $mEnd - $mStart) : '';
$movOnly !== '' && !str_contains($movOnly, 'bawalis')
    ? ok('`Custody::movements()` ما بتقراش `bawalis` — بتمرّ على `DailyReview`')
    : bad('`Custody::movements()` بقت تقرا `bawalis` بإيدها — لازم تنادي `officeExcludeSql`');

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
        // السيناريو المُبلَّغ عنه: كيبورد الموبايل يرسل أرقامًا عربية.
        'car_l1' => 'ف', 'car_l2' => 'ح', 'car_l3' => 'ص', 'car_digits' => '٦٠٠٠',
        'custody' => (string) $AMOUNT, 'bill_date' => $day,
        'newname_driver' => '1', 'newname_car_owner' => '1',
    ]);
    $billId = (int) $res['id'];
    ok('البوليصة اتسجّلت من مسار المندوب (#' . $billId . ')');

    /* ① أثر البوليصة على رقم «خزنة المكتب» — **بيختلف حسب النموذج الشغّال**،
       والاتنين صح كل واحد في نموذجه:
         • **الخزنة = الدرج بس**: الرقم ما بيتغيّرش. الكاش خرج من جيب المندوب
           والدرج برّه الحكاية تمامًا.
         • **الخزنة = كل كاش الشركة** (`bankTopupActive`): محفظة المندوب **جوّه**
           الرقم، فالكاش اللي راح للسائق خرج من الشركة فعلاً والرقم لازم ينقص
           بالمخصوم. (لو فضل ثابت هنا يبقى الفلوس بتتبخّر — ده بالظبط الباج
           اللي اتقفل في 2026-07-31.)
       ⚠️ التوقّع اتقلب عن قصد مش اترخّى — القسم ٨ بيقيس نفس الحاجة من الاتجاه
          التاني (وسم بوليصة قايمة). */
    $expSafe = RepCustody::bankTopupActive()
        ? round($safeBefore - Custody::billDeduction($AMOUNT,
              Database::scalar('SELECT car_no FROM bawalis WHERE id = ?', [$billId])), 2)
        : $safeBefore;
    near(RepCustody::bankTopupActive()
            ? '⚠️ الخزنة (= كل كاش الشركة) نقصت بالمخصوم — الكاش راح للسائق'
            : '⚠️ خزنة المكتب قبل = بعد (العهدة مش من الدرج)',
         round((float) Custody::balance(), 2), $expSafe);

    // ② عهدة المندوب نقصت بـ**`العهدة − 100`** — نفس قاعدة خصم الخزنة بالظبط.
    //
    // ⚠️ **التوقّع ده اتقلب في 2026-07-30 بقرار المالك.** v60 كان بيخصم العهدة
    //    **كاملة** بناءً على فهم إن الـ100 قاعدة خزنة. الصح: الـ100 **رقم ورقي**
    //    («بتتساب لصاحب البوليصة يسترزق بيها لو باعها») والسائق بياخد الباقي
    //    فعلاً — فاللي طلع من إيد المندوب `custody − 100` مش `custody`.
    //    المصدر الوحيد `Custody::billDeduction()`، بما فيه استثناء السيارات.
    // ⚠️ رقم السيارة بيتقرا **من الصف المحفوظ** لا يتكتب بالإيد: لو اللوحة
    //    كانت في `custody_deduction_exempt_cars` الخصم بيبقى كامل، والفحص
    //    لازم يتبع نفس القاعدة بدل ما يفترض −100 دايمًا.
    $NET = Custody::billDeduction($AMOUNT,
        Database::scalar('SELECT car_no FROM bawalis WHERE id = ?', [$billId]));
    near('⚠️ عهدة المندوب التراكمية نقصت بـ«العهدة − 100»',
         round(RepCustody::balance($repId), 2), round($repBefore - $NET, 2));
    near('⚠️ عهدة اليوم نقصت بنفس المبلغ',
         round(RepCustody::balance($repId, $day), 2), round($repDayBefore - $NET, 2));

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
    // ⚠️ كشف الصرف لازم يقول **نفس** رقم الرصيد (`العهدة − 100`) وإلا الكشف
    //    يخالف الرصيد. الإجمالي الخام موجود جنبه في `gross_custody`.
    if ($billRows) { near('مبلغ الصف = العهدة − 100 (زي الرصيد)', (float) $billRows[0]['amount'], $NET); }

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
         round(RepCustody::balance($repId), 2), round($repNow + $NET, 2));

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
    // ⚠️ `{$needle}` إجبارية — بايتات «» بتتحسب جزءًا من اسم المتغيّر (مصيدة موثَّقة)
    !str_contains($view, $needle) ? ok($label) : bad("{$label} — «{$needle}» موجودة في الشاشة");
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
$billView = code_only(src('app/Views/m/bill_new.php'));
str_contains($billView, 'replace(/[٠-٩]/g')
    && str_contains($billView, 'replace(/[۰-۹]/g')
    && str_contains($billView, "latinDigits(dBox.value).replace(/[^0-9]/g, '')")
    ? ok('⚠️ خانة أرقام العربية توحّد ١٢٣٤ و۱۲۳۴ قبل التنظيف')
    : bad('خانة أرقام العربية ممكن تمسح الأرقام العربية/الفارسية قبل الإرسال');

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
/* ⚠️ الفحص ده كان بيدوّر على الزرار في `m/home.php` حرفيًّا، والمالك نقله جوّه
   الإذن (v64): «البوليصة لازمها إذن، وفتحها من الرئيسية كانت بتبدأ بسؤال أنهي
   إذن؟ على ٣٧ إذن». فالفحص بقى على **وجود مدخل للشاشة** لا على مكانه —
   مكان الزرار قرار تصميم بيتغيّر، ووجود المدخل هو اللي لو ضاع الميزة تموت. */
$billEntry = false;
foreach (glob(__DIR__ . '/../app/Views/m/*.php') as $__v) {
    if (str_contains((string) file_get_contents($__v), "url('m/bill/new")) { $billEntry = true; break; }
}
$billEntry ? ok('فيه مدخل لشاشة «بوليصة جديدة» في بوابة المندوب')
           : bad('مفيش أي مدخل لـ m/bill/new في app/Views/m — الشاشة بقت بلا زرار');

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

// ══════════════════════════════════════════ ٨) النموذج المجمّع للخزنة (2026-07-31)
sec('٨) عهدة السائق اللي دفعها المندوب بتدخل الحساب في النموذج المجمّع');

/* ⚠️⚠️ **الباج اللي القسم ده اتعمل عشانه (مقيس على الإنتاج):** لما بقى معنى
   «رصيد الخزنة» = الدرج + جيوب المناديب (`rep_bank_topup_from`)، فضل
   `officeExcludeSql` بيستثني عهدة السائق اللي دفعها المندوب. النتيجة إن
   المحفظة بتتخصم والخزنة لأ — **والمحفظة جوّه الخزنة** ⇒ الفلوس بتتبخّر.
   ٢١,٠٠٠ في ٣ بوالص يوم 2026-07-29 كانوا مخفيين تمامًا من التقفيل. */

$pooled = RepCustody::bankTopupActive();
$clause = RepBillCustody::officeExcludeSql('b');

if ($pooled) {
    str_contains($clause, 'bill_date >=')
        ? ok('⚠️ النموذج المجمّع شغّال والشرط بيسمح لعهد المناديب تدخل الحساب')
        : bad('⚠️⚠️ النموذج المجمّع شغّال لكن الشرط لسه بيستثني عهد المناديب — فلوس بتتبخّر');
    // ⚠️ الحدّ على `bill_date` بتاع الصف نفسه — بيحمي الأيام المتقفلة من
    //    إعادة الحساب (`Closing::refreshLockedChainFrom`).
    str_contains($clause, RepCustody::bankTopupFrom())
        ? ok('⚠️ الحدّ على تاريخ البوليصة نفسها — الأيام المتقفلة ما بتتغيّرش')
        : bad('⚠️ الشرط بلا حدّ تاريخ — إعادة حساب يوم متقفل هتعيد كتابته');
} else {
    $clause === " AND b.custody_rep_id IS NULL"
        ? ok('النموذج غير المجمّع: الاستثناء شغّال زي ما هو (الخزنة = الدرج بس)')
        : bad('النموذج غير المجمّع لكن الشرط اتغيّر — عهدة المندوب هتتخصم من الدرج');
}

/* ⚠️ **شرط السلامة بين الإعدادين.** جانب المحفظة محدود بخط الفصل
   (`epochSql('bill_date')`) وجانب الخزنة بتاريخ النموذج المجمّع. لو تاريخ
   النموذج **بعد** خط الفصل، بتفضل فيه بوالص متخصومة من المحفظة وبرّه الخزنة
   = نفس التبخّر بالظبط. */
$epoch = RepCustody::epoch();
if ($pooled && $epoch !== '') {
    RepCustody::bankTopupFrom() <= $epoch
        ? ok('⚠️ تاريخ النموذج المجمّع ≤ خط الفصل — مفيش بوليصة بره الاتنين')
        : bad('⚠️⚠️ تاريخ النموذج المجمّع بعد خط الفصل — بوالص هتتخصم من المحفظة وبرّه الخزنة');
}

// المصدر الوحيد لعرض «مين سلّم» — عشان ملفات الخزنة ما تذكرش العمود بإيدها.
foreach (['app/Models/DailyReview.php', 'app/Controllers/DailyClosingController.php'] as $f) {
    str_contains(code_only(src($f)), 'payerSelectSql')
        ? ok("$f بيعرض «مين سلّم» من المصدر الوحيد")
        : bad("$f مش بيعرض «مين سلّم» — الرقم هيزيد والمالك مش عارف ليه");
}
has('شاشة التقفيل بتعرض شارة «دفعها المندوب»', 'app/Views/closing/index.php', "\$it['by_rep']");
has('الطباعة فيها عمود «سلّمها»', 'app/Views/closing/print.php', 'custody_rep_name');

/* ⚠️ كان فحصًا على تسمية شرطية (`empty($pooled) ? ' للدرج' : ''`) اتشالت بطلب
   المالك 2026-08-01 لصالح حل أوضح: **خانتان منفصلتان** «عهدة مضافة للدرج» و
   «عهدة منزلة للمناديب» بدل رقم واحد اسمه بيتقلب. الخطر اللي كان بيتحرس منه
   (رقم اسمه «للدرج» وهو مجمّع) بقى **ممنوع بالبناء** لأن كل خانة ليها متغيّرها.
   فالفحص بقى على **الفصل نفسه**: لو حد لمّ الاتنين في رقم واحد، بيفشل. */
has('«عهدة مضافة للدرج» رقم مستقل', 'app/Views/closing/index.php', '$totTopupsDrawer');
has('«عهدة منزلة للمناديب» رقم مستقل', 'app/Views/closing/index.php', '$totBankToReps');
has('الكنترولر بيمرّر علم النموذج للشاشة', 'app/Controllers/DailyClosingController.php',
    "'pooled'        => RepCustody::bankTopupActive()");

// ── فحص سلوكي: بوليصة حقيقية، الاتجاهان مع بعض ──────────────────────────
if ($pooled) {
    $pdo = Database::conn();
    $pdo->beginTransaction();
    try {
        $bill = Database::row(
            "SELECT id, custody, car_no, bill_date FROM bawalis
              WHERE is_legacy = 0 AND custody > 0 AND custody_rep_id IS NULL
              ORDER BY id DESC LIMIT 1");
        $rep = Database::row("SELECT id FROM reps WHERE status = 'active' ORDER BY id LIMIT 1");
        if (!$bill || !$rep) {
            ok('(اتخطّى الفحص السلوكي — مفيش بوليصة/مندوب صالحين للاختبار)');
        } else {
            /* ⚠️ **البوليصة لازم تكون على يوم حيّ وإلا الفحص يفشل بإنذار كاذب.**
               رصيد أي يوم متقفل مجمَّد في `daily_closings`، فوسم بوليصة قديمة
               ما بيحركش `currentBalance` خالص — وده صح، مش باج. بنحرّك تاريخ
               البوليصة لأول يوم بعد آخر إقفال (وبعد بداية النموذج المجمّع)،
               وكله بيترجع بالـrollback. */
            $last = \App\Models\Closing::lastClosedDate();
            $live = $last !== null ? date('Y-m-d', strtotime($last . ' +1 day')) : RepCustody::bankTopupFrom();
            if ($live < RepCustody::bankTopupFrom()) { $live = RepCustody::bankTopupFrom(); }
            Database::exec('UPDATE bawalis SET bill_date = ? WHERE id = ?', [$live, (int) $bill['id']]);
            $bill['bill_date'] = $live;

            $rid = (int) $rep['id'];
            $ded = Custody::billDeduction((float) $bill['custody'], $bill['car_no'] ?? null);
            $safeBefore = \App\Models\Closing::currentBalance();
            $walBefore  = RepCustody::balance($rid);

            Database::exec('UPDATE bawalis SET custody_rep_id = ? WHERE id = ?', [$rid, (int) $bill['id']]);
            $safeAfter = \App\Models\Closing::currentBalance();
            $walAfter  = RepCustody::balance($rid);

            /* ⚠️⚠️ **جوهر التصحيح، والتوقّع هنا عكس الحدس الأول:**
               في النموذج المجمّع الخزنة = كل كاش الشركة، والكاش خرج للسائق
               في الحالتين — فوسم «مين دفع» **ما بيغيّرش إجمالي الخزنة خالص**.
               اللي بيتغيّر هو **مصدر الدفع**: محفظة المندوب بتنقص، وبالتالي
               الدرج (= الخزنة − Σ المحافظ) بيرجع له المبلغ لأنه مدفعش.

               ⚠️ **ده بالظبط الباج اللي اتقفل:** قبل التصحيح الوسم كان بيشيل
                  البوليصة من الخزنة **والمحفظة تنقص برضه** ⇒ الخزنة كانت
                  بتعلى بالمخصوم والفلوس تتبخّر. لو الفحص ده فشل بفرق =
                  المخصوم، يبقى الاستثناء رجع. */
            near('⚠️⚠️ إجمالي الخزنة ما اتغيّرش بالوسم — الكاش خرج في الحالتين',
                $safeAfter - $safeBefore, 0);
            near('⚠️ محفظة المندوب نقصت بالمخصوم (العهدة − الخصم)', $walBefore - $walAfter, $ded);
            near('⚠️ الدرج رجع له المبلغ — المندوب هو اللي دفع مش الدرج',
                ($safeAfter - $walAfter) - ($safeBefore - $walBefore), $ded);

            /* ⚠️ قاعدة v50: مجموع قسم «عهد السائقين» في شاشة المراجعة لازم
               يساوي رقم `Closing` بالمليم، وإلا الشاشة بتعرض حاجة والرصيد
               بيقول حاجة تانية. */
            near('⚠️ مجموع قسم المراجعة = رقم عهد السائقين في التقفيل',
                \App\Models\DailyReview::view($live)['groups']['custody']['total'],
                \App\Models\Closing::dayView($live)['driver']);

            // البند بيبان في شاشة المراجعة باسم الدافع
            $rows = \App\Models\DailyReview::view((string) $bill['bill_date'])['groups']['custody']['items'];
            $found = null;
            foreach ($rows as $r) { if ((int) $r['id'] === (int) $bill['id']) { $found = $r; break; } }
            $found !== null
                ? ok('⚠️ البوليصة بتبان في قسم «عهد السائقين» بعد الوسم')
                : bad('⚠️⚠️ البوليصة اختفت من قسم «عهد السائقين» — نفس الباج الأصلي');
            ($found['by_rep'] ?? null) !== null
                ? ok('البند بيقول مين سلّمها')
                : bad('البند مش بيقول مين سلّمها — الرقم زاد بلا تفسير');
        }
    } finally {
        // ⚠️ rollback إجباري — الفحص بيوسم بوليصة حقيقية عشان يقيس الاتجاهين.
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
    }
}

echo "\n" . str_repeat('=', 62) . "\n";
echo "النتيجة: $pass نجح · $fail فشل\n";
exit($fail > 0 ? 1 : 0);
