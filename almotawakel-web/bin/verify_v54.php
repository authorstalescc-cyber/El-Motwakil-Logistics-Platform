<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص v54 — وحدة سيارات المكتب.
 *
 * أهم أربع حاجات:
 *   ١) **العزل عن الخزنة** (قرار المالك): مصروف بـ999,999 ما بيحركش رصيد
 *      الخزنة ولا مليم، وولا ملف خزنة بيعرف بجدول `car_expenses`.
 *   ٢) **قراءة فقط من البوالص**: الوحدة ما بتكتبش ولا بايت في `bawalis`،
 *      وحذف السيارة ما بيمسحش أي بوليصة.
 *   ٣) **تطبيع اللوحة**: «1474 ط ف ر» و«ط ف ر 1474» سيارة واحدة، والأرقام
 *      العربية والألف بأشكالها بتتوحّد. والموديل بيدّي نفس نتيجة الترحيل.
 *   ٤) **رندر فعلي** لكل الشاشات ونوافذ التفاصيل ببيانات مزروعة — الرندر
 *      على صفحة فاضية بينجح كذبًا (0=0)، فبنزرع ونتأكد إن فيه بنود فعلاً.
 *
 * كله جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_v54.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Access;
use App\Core\Database;
use App\Models\Closing;
use App\Models\OfficeCar;

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function sec(string $m): void { echo "\n" . $m . "\n" . str_repeat('─', 62) . "\n"; }
function same(string $l, $a, $e): void {
    $a == $e ? ok($l) : bad("$l — متوقع " . var_export($e, true) . " / فعلي " . var_export($a, true));
}
function near(string $l, $a, $e): void {
    abs((float) $a - (float) $e) <= 0.005 ? ok($l)
        : bad("$l — متوقع " . number_format((float) $e, 2) . " / فعلي " . number_format((float) $a, 2));
}
function yes(string $l, bool $c): void { $c ? ok($l) : bad($l); }

$root = dirname(__DIR__);
/** بيشيل التعليقات قبل أي فحص على الكود — تعليق بيشرح القاعدة ما يعديش كإنذار كاذب. */
function php_code(string $path): string
{
    if (!is_file($path)) { return ''; }
    $out = '';
    foreach (token_get_all((string) file_get_contents($path)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

echo "فحص v54 — سيارات المكتب\n" . str_repeat('=', 62) . "\n";

// ═══════════════════════════════════════════ ٠) البنية
sec('٠) البنية والترحيل');
yes('جدول office_cars موجود', OfficeCar::available());
if (!OfficeCar::available()) {
    echo "\nالوحدة مش مترحّلة — شغّل php bin/migrate_v54.php\n";
    exit(1);
}
yes('جدول car_expenses موجود', (bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'car_expenses'"));
yes('plate_key فريد في القاعدة', (bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'office_cars'
       AND index_name = 'uq_car_plate_key' AND non_unique = 0"));

// ⚠️ مفيش وسم فيزا على مصاريف السيارة عن قصد — إضافته بتفتح نقطة مزامنة جديدة.
$visaCols = Database::all(
    "SELECT column_name FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'car_expenses'
       AND (column_name LIKE '%visa%')");
yes('مفيش عمود فيزا على car_expenses (نقطة مزامنة كنّا هنفتحها)', !$visaCols);

yes('bawalis ما اتضافلهاش عمود car_id (الربط نصّي)', !Database::scalar(
    "SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'bawalis' AND column_name = 'car_id'"));

// ═══════════════════════════════════════════ ١) العزل بالبناء
sec('١) ⚠️ العزل عن خزنة المكتب (قرار المالك)');
$cashFiles = [
    'app/Models/Closing.php', 'app/Models/Custody.php', 'app/Models/Expense.php',
    'app/Models/DailyReview.php', 'app/Models/Metrics.php',
    'app/Controllers/DailyClosingController.php', 'app/Controllers/CustodyController.php',
    'app/Controllers/ExpenseController.php', 'bin/backfill_closings.php',
];
$leak = [];
foreach ($cashFiles as $f) {
    if (str_contains(php_code($root . '/' . $f), 'car_expenses')) { $leak[] = $f; }
}
$leak ? bad('✗✗ الجدول اتذكر في ملف خزنة: ' . implode(', ', $leak))
      : ok('ولا ملف خزنة بيعرف الجدول (' . count($cashFiles) . ' اتفحصوا)');

// الوحدة ممنوعة تكتب في البوالص
$carModel = php_code($root . '/app/Models/OfficeCar.php');
$carCtl   = php_code($root . '/app/Controllers/OfficeCarController.php');
yes('الموديل ما بيكتبش في bawalis', !preg_match('~(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+bawalis~i', $carModel));
yes('الكنترولر ما فيهوش SQL خام', !preg_match('~\b(INSERT INTO|UPDATE |DELETE FROM)\b~i', $carCtl));

// ═══════════════════════════════════════════ ٢) تطبيع اللوحة
sec('٢) تطبيع اللوحة (المصدر الوحيد OfficeCar::plateKey)');
same('«1474 ط ف ر» = «ط ف ر 1474»', OfficeCar::plateKey('1474 ط ف ر'), OfficeCar::plateKey('ط ف ر 1474'));
same('المسافات الزيادة مش بتفرق', OfficeCar::plateKey('س ع م  8737'), OfficeCar::plateKey('سعم8737'));
same('الأرقام العربية بتتحوّل', OfficeCar::plateKey('س ع م ٨٧٣٧'), OfficeCar::plateKey('س ع م 8737'));
same('الألف بأشكالها بتتوحّد', OfficeCar::plateKey('أ ب ج 12'), OfficeCar::plateKey('ا ب ج 12'));
same('لوحة فاضية بترجّع فاضي', OfficeCar::plateKey('  '), '');
same('لوحة مختلفة ≠ لوحة تانية', OfficeCar::plateKey('س ع م 8737') !== OfficeCar::plateKey('س ن ر 3739'), true);

// ⚠️ الترحيل فيه نسخة مستقلة من التطبيع — لازم الاتنين يدّوا نفس النتيجة
$mig = (string) file_get_contents($root . '/bin/migrate_v54.php');
yes('الترحيل بيوثّق إن نسخته مستقلة عن الموديل', str_contains($mig, 'OfficeCar::plateKey()'));
$mismatch = [];
foreach (Database::all("SELECT id, plate, plate_key FROM office_cars") as $c) {
    if (OfficeCar::plateKey((string) $c['plate']) !== (string) $c['plate_key']) { $mismatch[] = $c['plate']; }
}
$mismatch ? bad('مفتاح مخزَّن مخالف للموديل: ' . implode(', ', $mismatch))
          : ok('كل المفاتيح المخزَّنة مطابقة لحساب الموديل (' . count(OfficeCar::all()) . ' سيارة)');

// ═══════════════════════════════════════════ ٣) الفترات والمقارنة
sec('٣) الفترات والمقارنة');
$w = OfficeCar::period('week', '2026-07-16');   // خميس
same('الأسبوع بيبدأ سبت', date('N', strtotime($w['from'])), '6');
same('الأسبوع ٧ أيام', (int) round((strtotime($w['to']) - strtotime($w['from'])) / 86400) + 1, 7);
same('الأسبوع السابق بينتهي قبل الحالي بيوم',
    date('Y-m-d', strtotime('+1 day', strtotime($w['prev_to']))), $w['from']);
$m = OfficeCar::period('month', '2026-07-16');
same('الشهر تقويمي (أول الشهر)', $m['from'], '2026-07-01');
same('الشهر تقويمي (آخر الشهر)', $m['to'], '2026-07-31');
same('الشهر السابق يونيو', $m['prev_from'], '2026-06-01');
$d = OfficeCar::period('day', '2026-07-16');
same('اليوم = يوم واحد', $d['from'] === $d['to'] && $d['from'] === '2026-07-16', true);
$a = OfficeCar::period('all');
same('«كل الفترات» بلا حدود ولا مقارنة', $a['from'] === null && $a['prev_from'] === null, true);
same('نوع فترة مش معروف بيقع على الشهر', OfficeCar::period('hack', '2026-07-16')['kind'], 'month');
same('نسبة التغيّر', OfficeCar::delta(150, 100), 50.0);
same('التغيّر بلا أساس = null', OfficeCar::delta(150, 0), null);

// ═══════════════════════════════════════════ ٤) المطابقة والرحلات
sec('٤) مطابقة الرحلات (قراءة فقط من البوالص)');
/* ⚠️ الفحص ده كان بيتأكد إن فيه لوحة بتهجئتين في **البيانات الحقيقية** — وده
   فشل لما v55 شالت السيارة اللي كانت كده. الفحص الصح على **السلوك**: القسم ٥
   بيزرع سيارة ببديل وبوليصتين بتهجئتين مختلفتين وبيتأكد إن الاتنين اتلمّوا.
   اللي هنا بقى تقرير على الوضع الحالي مش شرط. */
$map = OfficeCar::carNoMap();
$groups = [];
foreach ($map as $no => $cid) { $groups[$cid][] = $no; }
$merged = array_filter($groups, static fn ($v) => count($v) > 1);
ok('خريطة اللوحات: ' . count($map) . ' تهجئة على ' . count($groups) . ' سيارة'
   . ($merged ? ' (فيها ' . count($merged) . ' سيارة بأكتر من تهجئة)' : ''));
foreach ($merged as $cid => $nos) { ok('  › سيارة #' . $cid . ' بتلمّ: ' . implode(' / ', $nos)); }

$legacyMatched = 0;
foreach (OfficeCar::all() as $c) {
    foreach (OfficeCar::trips((int) $c['id']) as $t) {
        if (!empty($t['is_legacy'])) { $legacyMatched++; }
    }
}
same('البوالص المُرحَّلة مستبعدة من الرحلات', $legacyMatched, 0);
yes('استعلام الرحلات بيفلتر is_legacy = 0', str_contains($carModel, 'is_legacy = 0'));

// ═══════════════════════════════════════════ ٥ … ١٠) داخل transaction
$pdo = Database::conn();
$pdo->beginTransaction();
try {
    $_SESSION['uid'] = $_SESSION['uid'] ?? 1;
    $_SESSION['uname'] = 'فاحص v54';
    $_SESSION['role'] = 'owner';
    $_SESSION['work_date'] = '2026-07-16';
    $_SESSION['_csrf'] = 'v54';

    $bal0   = Closing::currentBalance();
    $bills0 = (int) Database::scalar("SELECT COUNT(*) FROM bawalis");

    // سيارة اختبار + رحلة عليها
    $cid = OfficeCar::create(['name' => '__v54 سيارة', 'plate' => 'ق ب ر 9911',
                              'aliases' => '9911 ق ب ر', 'driver' => 'سواق اختبار']);
    Database::exec(
        "INSERT INTO bawalis (bill_date, bill_number, car_no, car_owner, driver, company,
                              destination, container_count, container_type, freight, custody, is_legacy)
         VALUES ('2026-07-10','__V54-1','ق ب ر 9911','المكتب','سواق اختبار','شركة اختبار',
                 'بني سويف', 2, '20 قدم', 20000, 5000, 0)");
    // نفس السيارة بتهجئة بديلة — لازم تتحسب معاها
    Database::exec(
        "INSERT INTO bawalis (bill_date, bill_number, car_no, car_owner, driver, company,
                              destination, container_count, container_type, freight, custody, is_legacy)
         VALUES ('2026-07-12','__V54-2','9911 ق ب ر','المكتب','سواق تاني','شركة تانية',
                 'العاشر من رمضان', 1, '40 قدم', 10000, 3000, 0)");
    // بوليصة مُرحَّلة بنفس اللوحة — **ممنوع** تتحسب
    Database::exec(
        "INSERT INTO bawalis (bill_date, bill_number, car_no, car_owner, freight, custody, is_legacy)
         VALUES ('2026-07-11','__V54-L','ق ب ر 9911','المكتب', 99999, 99999, 1)");
    OfficeCar::clearCache();

    sec('٥) أداء السيارة والمعادلة');
    $p = OfficeCar::period('month', '2026-07-16');
    $perf = OfficeCar::perf($cid, $p['from'], $p['to']);
    same('الرحلتين اتلمّوا (التهجئتين)', $perf['trips'], 2);
    same('الحاويات 2+1', $perf['containers'], 3);
    near('النولون 30,000', $perf['freight'], 30000);
    near('العهد 8,000', $perf['custody'], 8000);
    same('البوليصة المُرحَّلة مستبعدة', $perf['freight'] < 99999, true);
    same('عدد السواقين', $perf['drivers'], 2);
    same('عدد الشركات', $perf['companies'], 2);

    OfficeCar::addExpense($cid, ['exp_date' => '2026-07-13', 'category' => 'fuel', 'amount' => 4000]);
    OfficeCar::addExpense($cid, ['exp_date' => '2026-07-14', 'category' => 'repair', 'amount' => 2000]);
    $perf = OfficeCar::perf($cid, $p['from'], $p['to']);
    near('المصاريف 6,000', $perf['expenses'], 6000);
    near('الصافي = 30,000 − 8,000 − 6,000', $perf['net'], 16000);

    sec('٥ب) تعديل مصروف مسجَّل');
    $edId = OfficeCar::addExpense($cid, ['exp_date' => '2026-07-14', 'category' => 'fuel',
                                         'amount' => 500, 'vendor' => 'محطة قديمة', 'note' => 'قديم']);
    OfficeCar::updateExpense($edId, ['exp_date' => '2026-07-15', 'category' => 'tyres',
                                     'amount' => 750, 'vendor' => 'ورشة جديدة', 'ref_no' => 'R-9', 'note' => 'جديد']);
    $edRow = OfficeCar::findExpense($edId);
    same('التاريخ اتعدّل', $edRow['exp_date'], '2026-07-15');
    same('الفئة اتعدّلت', $edRow['category'], 'tyres');
    near('المبلغ اتعدّل', $edRow['amount'], 750);
    same('المحطة اتعدّلت', $edRow['vendor'], 'ورشة جديدة');
    same('رقم الإيصال اتحفظ', $edRow['ref_no'], 'R-9');
    same('البيان اتعدّل', $edRow['note'], 'جديد');
    // ⚠️ التعديل ما ينقلش المصروف لسيارة تانية — `car_id` برّه الـUPDATE عن قصد.
    same('السيارة ما اتغيّرتش', (int) $edRow['car_id'], $cid);
    // ⚠️ فئة برّه القائمة البيضا بتقع على «أخرى» في التعديل زي الإضافة بالظبط.
    OfficeCar::updateExpense($edId, ['exp_date' => '2026-07-15', 'category' => '../hack', 'amount' => 750]);
    same('فئة مش في القائمة البيضا بتتخزَّن other في التعديل',
        (string) Database::scalar("SELECT category FROM car_expenses WHERE id = ?", [$edId]), 'other');
    // ⚠️ العزل: التعديل زي الإضافة — ما بيلمسش الخزنة.
    near('رصيد الخزنة قبل = بعد التعديل', Closing::currentBalance(), $bal0);
    OfficeCar::deleteExpense($edId);
    same('المصروف اتشال بعد الاختبار', OfficeCar::findExpense($edId), null);

    /* ═══════════════════════════════════════════════════════════════════
       ٥ج) مصروفات البوليصة — بنود نظام الإكسل رجعت لسيارات المكتب (2026-07-28)
       ═══════════════════════════════════════════════════════════════════ */
    sec('٥ج) مصروفات البوليصة (سيارات المكتب بس)');

    // المعادلات التلاتة — مستخرجة من `legacy_bawalis` ومتحقَّق منها على ٧٣ صف.
    $bw = \App\Calc::bawala([
        'freight' => 15500, 'custody' => 6000, 'transfer' => 0, 'discount' => 0,
        'overnight_collection' => 0, 'driver_share' => 1500, 'assistant_share' => 500,
        'gas' => 2000, 'tahia_masr' => 60, 'permits' => 200,
        'overnight_expense' => 500, 'expense' => 500,
    ]);
    near('إجمال المصروف = مجموع الـ١٢', $bw['total_expense'], 5260);
    near('الباقى من العهدة = عهدة − إجمال المصروف', $bw['custody_remaining'], 740);
    near('صافى السيارة = نولون − إجمال المصروف', $bw['car_net'], 10240);

    /* ⚠️⚠️ أهم فحص في الميزة: **دين البوليصة ما بيتأثرش بأي بند مصروف**.
       الدين حساب العميل، والمصروفات دي تكلفة تشغيل عربيتنا. لو دخلت المعادلة
       بالغلط، كل ديون العملاء والكشوف بتتغيّر **بلا أي خطأ ظاهر**. */
    $bare = \App\Calc::bawala(['freight' => 15500, 'custody' => 6000]);
    same('⚠️ الدين مع المصروفات = الدين بدونها', $bw['debt'], $bare['debt']);
    near('   › والدين نفسه صح', $bw['debt'], 9500);
    // الباقى من العهدة بيطلع سالب عادي (السواق صرف أكتر من عهدته) — مش زي الدين.
    $neg = \App\Calc::bawala(['freight' => 10000, 'custody' => 1000, 'gas' => 3000]);
    near('الباقى من العهدة بيقبل السالب', $neg['custody_remaining'], -2000);

    // ⚠️ قايمة الأعمدة مصدرها واحد: `Calc::EXPENSE_COLS`.
    same('عدد أعمدة المصروفات = ١٢', count(\App\Calc::EXPENSE_COLS), 12);
    $schemaOffice = [];
    foreach (\App\Schema::entities()['bawalis']['fields'] as $f) {
        if (!empty($f['office']) && empty($f['calc'])) { $schemaOffice[] = $f['key']; }
    }
    sort($schemaOffice); $expCols = \App\Calc::EXPENSE_COLS; sort($expCols);
    same('⚠️ خانات Schema = Calc::EXPENSE_COLS بالظبط', $schemaOffice, $expCols);
    foreach (\App\Calc::EXPENSE_COLS as $c) {
        if (!Database::scalar("SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name='bawalis' AND column_name=?", [$c])) {
            bad("عمود `$c` مش موجود في bawalis"); $missingCol = true;
        }
    }
    if (empty($missingCol)) { ok('كل الـ١٢ عمود موجودين في bawalis'); }

    // مطابقة اللوحة — المصدر الوحيد لسؤال «سيارة مكتب؟»
    yes('matchPlate بتلاقي السيارة بلوحتها', OfficeCar::isOfficePlate('ق ب ر 9911'));
    yes('  › وباللوحة المقلوبة كمان', OfficeCar::isOfficePlate('9911 ق ب ر'));
    yes('  › ولوحة غريبة بترجّع لأ', !OfficeCar::isOfficePlate('ط ط ط 1'));

    // الأثر على أداء السيارة (قرار المالك: تنضاف لمصاريف السيارة)
    $perfBefore = OfficeCar::perf($cid, $p['from'], $p['to']);
    Database::exec("UPDATE bawalis SET driver_share=1500, gas=2000, tahia_masr=60, permits=200
                    WHERE bill_number = ?", ['__V54-1']);
    OfficeCar::clearCache();
    $perfAfter = OfficeCar::perf($cid, $p['from'], $p['to']);
    near('مصروفات البوالص اتحسبت', $perfAfter['bill_expenses'] - $perfBefore['bill_expenses'], 3760);
    near('إجمالي المصاريف = مصاريف السيارة + مصروفات البوالص',
        $perfAfter['expenses_total'], $perfAfter['expenses'] + $perfAfter['bill_expenses']);
    near('الصافي = نولون − عهد − إجمالي المصاريف',
        $perfAfter['net'], $perfAfter['freight'] - $perfAfter['custody'] - $perfAfter['expenses_total']);
    // ⚠️ daily() بتغذّي الاتجاه والمعدلات — لازم تطابق perf وإلا الرسم يقول رقمًا والكارت غيره.
    $dailyBe = 0.0;
    foreach (OfficeCar::daily([$cid], $p['from'], $p['to']) as $v) { $dailyBe += (float) $v['bill_expenses']; }
    near('daily() = perf في مصروفات البوالص', $dailyBe, $perfAfter['bill_expenses']);
    $fs = OfficeCar::fleetSummary(OfficeCar::fleet($p['from'], $p['to']));
    yes('fleetSummary فيها bill_expenses', isset($fs['bill_expenses'], $fs['expenses_total']));
    // ⚠️ العزل: بنود البوليصة عمرها ما تلمس الخزنة.
    near('⚠️ رصيد الخزنة قبل = بعد إضافة مصروفات البوليصة', Closing::currentBalance(), $bal0);
    Database::exec("UPDATE bawalis SET driver_share=0, gas=0, tahia_masr=0, permits=0
                    WHERE bill_number = ?", ['__V54-1']);
    OfficeCar::clearCache();

    sec('٦) ⚠️ العزل الفعلي: مصروف ضخم ما بيحركش الخزنة');
    OfficeCar::addExpense($cid, ['exp_date' => '2026-07-15', 'category' => 'other', 'amount' => 999999]);
    near('رصيد الخزنة قبل = بعد', Closing::currentBalance(), $bal0);
    near('مصاريف السيارة شافت الـ999,999', OfficeCar::expenseTotal($cid, $p['from'], $p['to']), 1005999);

    sec('٧) الفئة الغلط بتقع على «أخرى» + التقسيمات');
    $badId = OfficeCar::addExpense($cid, ['exp_date' => '2026-07-15', 'category' => '../hack', 'amount' => 10]);
    same('فئة مش في القائمة البيضا بتتخزَّن other',
        (string) Database::scalar("SELECT category FROM car_expenses WHERE id = ?", [$badId]), 'other');
    OfficeCar::deleteExpense($badId);

    $trips = OfficeCar::trips($cid, $p['from'], $p['to']);
    same('تقسيم الشركات صفّين', count(OfficeCar::breakdown($trips, 'company')), 2);
    same('تقسيم السواقين صفّين', count(OfficeCar::breakdown($trips, 'driver')), 2);
    $mix = OfficeCar::containerMix($trips);
    same('تقسيم الحاويات: 20 قدم = 2', $mix['20 قدم'] ?? 0, 2);
    same('تقسيم الحاويات: 40 قدم = 1', $mix['40 قدم'] ?? 0, 1);
    $sumMix = array_sum($mix);
    same('مجموع تقسيم الحاويات = العدد فوق', $sumMix, 3);

    sec('٨) رندر فعلي للشاشات (ببنود مزروعة — مش صفحة فاضية)');
    $ctl = new \App\Controllers\OfficeCarController();
    $grab = static function (callable $fn): string {
        ob_start();
        try { $fn(); } catch (\Throwable $e) { ob_end_clean(); return 'EXC: ' . $e->getMessage(); }
        return (string) ob_get_clean();
    };

    $_GET = ['p' => 'month', 'd' => '2026-07-16'];
    $idx = $grab(static fn () => $ctl->index());
    yes('شاشة الأسطول بترندر', !str_starts_with($idx, 'EXC') && strlen($idx) > 5000);
    yes('  › وفيها كارت السيارة المزروعة', str_contains($idx, '__v54 سيارة'));
    yes('  › وفيها تنبيه «مش داخلة خزنة المكتب»', str_contains($idx, 'مش داخلة خزنة المكتب'));

    $_GET = ['p' => 'month', 'd' => '2026-07-16'];
    $card = $grab(static fn () => $ctl->card((string) $cid));
    yes('كارت السيارة بيرندر', !str_starts_with($card, 'EXC') && strlen($card) > 5000);
    yes('  › وفيه الرحلتين', substr_count($card, '__V54-') === 2);
    yes('  › والبوليصة المُرحَّلة مش ظاهرة', !str_contains($card, '__V54-L'));
    yes('  › وفيه معادلة الصافي', str_contains($card, 'car-eq'));
    yes('  › وفيه زر تعديل لكل مصروف', str_contains($card, 'aria-label="تعديل المصروف"'));

    /* ⚠️ **فحص سلوكي على الناتج المرندَر** — الرمز اللي بيوصل المتصفح لازم
       يعدّي من `FormState::claim()` بالظبط (`^[a-f0-9]{32}$`)، وإلا الزرار
       بيترفض برسالة «هذا الطلب نُفّذ أو انتهت صلاحيته» **والمالك مش فاهم ليه**. */
    $delChunk = '';
    foreach (explode('cars/expense/', $card) as $i => $c) {
        if ($i > 0 && str_contains($c, '/delete')) { $delChunk = explode('</form>', $c)[0]; break; }
    }
    $hasTok = (bool) preg_match('/name="_form_token" value="([a-f0-9]{32})"/', $delChunk, $m);
    yes('  › وفورم حذف المصروف بيبعت رمز صالح لـclaimForm', $hasTok);
    if ($hasTok) { yes('  › والرمز شكله اللي claim() بتقبله', (bool) preg_match('/^[a-f0-9]{32}$/', $m[1])); }
    else { bad('  › الرمز مش في الناتج — الحذف هيترفض'); }
    yes('  › والنموذج على وضع الإضافة', str_contains($card, 'سجّل مصروف على السيارة'));

    // ⚠️ وضع التعديل: `?edit_exp=` بيقلب النموذج لتعديل ويعبّيه من المصروف.
    $exId = (int) OfficeCar::expenses($cid, $p['from'], $p['to'])[0]['id'];
    $_GET = ['p' => 'month', 'd' => '2026-07-16', 'edit_exp' => (string) $exId];
    $cardEd = $grab(static fn () => $ctl->card((string) $cid));
    yes('كارت السيارة بيرندر على وضع التعديل', !str_starts_with($cardEd, 'EXC') && strlen($cardEd) > 5000);
    yes('  › والنموذج بقى «عدّل المصروف»', str_contains($cardEd, 'عدّل المصروف'));
    yes('  › وبيبعت لمسار التعديل', str_contains($cardEd, 'cars/expense/' . $exId . '/update'));
    yes('  › وفيه زر إلغاء', str_contains($cardEd, 'احفظ التعديل') && str_contains($cardEd, '>إلغاء<'));
    yes('  › والصف اللي بيتعدّل معلَّم', str_contains($cardEd, 'بتعدّله دلوقتي'));

    // ⚠️ **الرابط مش مصدر ثقة** — مصروف بتاع سيارة تانية لازم يترفض، وإلا
    //    تقدر تعدّل مصروف أي سيارة من كارت أي سيارة.
    $otherCar = OfficeCar::create(['name' => '__v54 سيارة تانية', 'plate' => 'س س س 9999',
                                   'aliases' => '', 'model' => '', 'driver' => '', 'status' => 'active', 'note' => '']);
    $otherExp = OfficeCar::addExpense($otherCar, ['exp_date' => '2026-07-15', 'category' => 'fuel', 'amount' => 100]);
    $_GET = ['p' => 'month', 'd' => '2026-07-16', 'edit_exp' => (string) $otherExp];
    $cardX = $grab(static fn () => $ctl->card((string) $cid));
    yes('مصروف سيارة تانية بيترفض في وضع التعديل',
        !str_contains($cardX, 'عدّل المصروف') && str_contains($cardX, 'سجّل مصروف على السيارة'));
    OfficeCar::delete($otherCar);
    $_GET = ['p' => 'month', 'd' => '2026-07-16'];

    $rep = $grab(static fn () => $ctl->report((string) $cid));
    yes('تقرير A4 بيرندر', !str_starts_with($rep, 'EXC') && str_contains($rep, 'تقرير أداء سيارة'));
    yes('  › والتقرير بيستخدم توكنز الطباعة المشتركة', str_contains($rep, '--navy:#15355B'));

    sec('٩) كل نوافذ التفاصيل بترندر وبنودها فيها');
    $fleetDrills = ['trips', 'containers', 'freight', 'custody', 'expenses', 'net'];
    foreach ($fleetDrills as $wt) {
        $_GET = ['p' => 'month', 'd' => '2026-07-16'];
        $h = $grab(static fn () => $ctl->drill($wt));
        // ⚠️ مصيدة موثَّقة (v38/v49/…): `«$wt»` بتدّي «Undefined variable $wt»»
        //    لأن بايتات `»` بتتحسب جزءًا من اسم المتغيّر. لازم `{$wt}`.
        yes("نافذة الأسطول «{$wt}»", !str_starts_with($h, 'EXC') && str_contains($h, 'drill-foot'));
    }
    $carDrills = ['car_trips', 'car_containers', 'car_freight', 'car_custody', 'car_expenses',
                  'car_net', 'car_companies', 'car_ports', 'car_agencies', 'car_destinations',
                  'car_drivers', 'car_mix'];
    $emptyDrills = [];
    foreach ($carDrills as $wt) {
        $_GET = ['p' => 'month', 'd' => '2026-07-16', 'car' => (string) $cid];
        $h = $grab(static fn () => $ctl->drill($wt));
        if (str_starts_with($h, 'EXC')) { bad("نافذة «{$wt}» رمت استثناء: {$h}"); continue; }
        if (!str_contains($h, 'd-row')) { $emptyDrills[] = $wt; }
    }
    $emptyDrills ? bad('نوافذ رجعت بلا بنود رغم إن فيه بيانات: ' . implode(', ', $emptyDrills))
                 : ok('كل الـ' . count($carDrills) . ' نافذة سيارة فيها بنود فعلاً');

    sec('١٠) الحذف ما بيلمسش البوالص');
    OfficeCar::delete($cid);
    same('عدد البوالص زي ما هو', (int) Database::scalar("SELECT COUNT(*) FROM bawalis"), $bills0 + 3);
    same('مصاريف السيارة اتمسحت معاها',
        (int) Database::scalar("SELECT COUNT(*) FROM car_expenses WHERE car_id = ?", [$cid]), 0);
    near('رصيد الخزنة لسه زي ما هو بعد الحذف', Closing::currentBalance(), $bal0);
} finally {
    $pdo->rollBack();
    OfficeCar::clearCache();
}

// ═══════════════════════════════════════════ ١١) المسارات والصلاحيات
sec('١١) المسارات والصلاحيات');
$routes = (string) file_get_contents($root . '/public/index.php');
$pos = static fn (string $needle): int => (int) (strpos($routes, $needle) ?: PHP_INT_MAX);
yes('مسار /cars مسجَّل', str_contains($routes, "'/cars',"));
// ⚠️ تحذير ٤ — الحرفي قبل الجشع
yes('/cars/drill/{what} قبل الجشع /cars/{id}', $pos("'/cars/drill/{what}'") < $pos("\$r->get('/cars/{id}',"));
yes('/cars/expense/{eid}/delete قبل الجشع', $pos("'/cars/expense/{eid}/delete'") < $pos("\$r->post('/cars/{id}',"));
yes('/cars/expense/{eid}/update قبل الجشع', $pos("'/cars/expense/{eid}/update'") < $pos("\$r->post('/cars/{id}',"));
yes('/cars/{id}/report قبل الجشع', $pos("'/cars/{id}/report'") < $pos("\$r->get('/cars/{id}',"));
yes('/cars/new قبل الجشع', $pos("'/cars/new'") < $pos("\$r->get('/cars/{id}',"));

// كل action في الكنترولر ليه requireAuth (تحذير ٥ — مفيش middleware)
$missing = [];
foreach (get_class_methods(\App\Controllers\OfficeCarController::class) as $mth) {
    $rm = new ReflectionMethod(\App\Controllers\OfficeCarController::class, $mth);
    if (!$rm->isPublic() || $rm->isStatic() || $rm->getDeclaringClass()->getName() !== \App\Controllers\OfficeCarController::class) { continue; }
    $src = implode('', array_slice(file($rm->getFileName()), $rm->getStartLine() - 1,
        $rm->getEndLine() - $rm->getStartLine() + 1));
    if (!str_contains($src, 'requireAuth()')) { $missing[] = $mth; }
}
$missing ? bad('actions بلا requireAuth: ' . implode(', ', $missing))
         : ok('كل الـactions فيها requireAuth');

// كل POST فيه requireCsrf
$missingCsrf = [];
foreach (['store', 'update', 'destroy', 'storeExpense', 'updateExpense', 'deleteExpense'] as $mth) {
    $rm = new ReflectionMethod(\App\Controllers\OfficeCarController::class, $mth);
    $src = implode('', array_slice(file($rm->getFileName()), $rm->getStartLine() - 1,
        $rm->getEndLine() - $rm->getStartLine() + 1));
    if (!str_contains($src, 'requireCsrf()')) { $missingCsrf[] = $mth; }
}
$missingCsrf ? bad('POST بلا requireCsrf: ' . implode(', ', $missingCsrf))
             : ok('كل مسارات الكتابة فيها requireCsrf');

/* ⚠️⚠️ **كل فورم كتابة لازم يبعت `_form_token`** — `claimForm()` بترفض أي طلب
   من غيره برسالة «هذا الطلب نُفّذ أو انتهت صلاحيته»، يعني **الزرار ما بيشتغلش
   خالص** والمالك مش فاهم ليه (اتصاد فعليًّا 2026-07-28: أزرار «حذف المصروف»
   و«احذف السيارة» و«مش بتاعتنا» و«رجّعها للتنبيه» كانوا مكسورين من v54).
   `csrfField()` **مش كفاية** — دي حماية تانية خالص. */
// ⚠️ **التعليقات لازم تتشال قبل الفحص** — التعليق اللي بيوثّق القاعدة بيذكر
//    اسم الحقل نفسه، فالفحص كان **بينجح كذبًا** حتى لو الحقل اتشال فعلاً
//    (نفس مصيدة `code_only()` الموثَّقة في `verify_security.php`).
$stripComments = static function (string $s): string {
    $s = (string) preg_replace('~/\*.*?\*/~s', '', $s);
    return (string) preg_replace('~(?<!:)//[^\n]*~', '', $s);
};
$noToken = [];
foreach (['card', 'form', 'index'] as $v) {
    $src = (string) file_get_contents($root . '/app/Views/office_cars/' . $v . '.php');
    // كل فورم في الشاشة: بندوّر على وسم الفتح ونشوف جسمه لحد أول </form>
    foreach (explode('method="post"', $src) as $i => $chunk) {
        if ($i === 0) { continue; }
        $body = $stripComments(explode('</form>', $chunk)[0] ?? '');
        if (!str_contains($body, 'name="_form_token"') && !str_contains($body, 'formStateFields(')) {
            $noToken[] = $v . '.php (فورم #' . $i . ')';
        }
    }
}
$noToken ? bad('⚠️ فورم كتابة بلا _form_token — الزرار هيترفض: ' . implode(' · ', $noToken))
         : ok('⚠️ كل فورمات الكتابة بتبعت _form_token (claimForm مش هترفضها)');

yes('«قراءة فقط» يقدر يفتح الشاشة', Access::allows('read_only', 'OfficeCarController@index', 'GET'));
yes('«قراءة فقط» ممنوع يكتب', !Access::allows('read_only', 'OfficeCarController@storeExpense', 'POST'));
yes('المحاسب يقدر يسجّل مصروف سيارة', Access::allows('accountant', 'OfficeCarController@storeExpense', 'POST'));
yes('التشغيل ممنوع يكتب', !Access::allows('operator', 'OfficeCarController@storeExpense', 'POST'));

// ═══════════════════════════════════════════ ١٢) الشاشات والواجهة
sec('١٢) الشاشات والواجهة');
$layout = (string) file_get_contents($root . '/app/Views/layout.php');
yes('بند «سيارات المكتب» في القائمة', str_contains($layout, "'OfficeCarController@index'"));
yes('مرادفات Ctrl+K موجودة', str_contains($layout, "'cars'    =>"));

/* ⚠️ مصيدة `.table-mobile-scroll`: أي كلاس بتطبعه الشاشة لازم يكون له CSS.
   ⚠️ ومصيدة تانية في كتابة الفحص نفسه (درس `verify_ux`): أسلوب المشروع بيعرّف
      كتير من الأصناف في `<style>` **جوّه الشاشة**، فالفحص لازم يبص على كل
      مصادر الـCSS مش على `app.css` بس، وإلا يدّي إنذارات كاذبة. */
$cssSources = (string) file_get_contents($root . '/public/assets/css/app.css');
foreach (glob($root . '/app/Views/office_cars/*.php') as $v) {
    if (preg_match_all('~<style>(.*?)</style>~s', (string) file_get_contents($v), $mm)) {
        $cssSources .= implode("\n", $mm[1]);
    }
}
$classes = ['cars-period', 'cars-pchips', 'cars-pchip', 'cars-note', 'car-hero', 'car-hero-ic',
            'car-hero-life', 'car-eq', 'car-eq-op', 'car-grid', 'car-cats', 'car-cat',
            'd-kpi-delta', 'drill-sec'];
$noCss = [];
foreach ($classes as $c) {
    if (!preg_match('~\.' . preg_quote($c, '~') . '(?![\w-])~', $cssSources)) { $noCss[] = $c; }
}
$noCss ? bad('كلاس مستعمل وبلا CSS: ' . implode(', ', $noCss))
       : ok('كل كلاسات الوحدة ليها CSS (' . count($classes) . ' اتفحصوا)');

// ⚠️ الكلاسات المشتركة بين شاشتين ممنوع تعيش في `<style>` محلي — نسختين بيفترقوا
$shared = ['cars-period', 'cars-pchip', 'cars-note'];
$appCss = (string) file_get_contents($root . '/public/assets/css/app.css');
$local = array_values(array_filter($shared, static fn ($c) => !str_contains($appCss, '.' . $c)));
$local ? bad('كلاس مشترك بين شاشتين ومعرَّف محليًّا: ' . implode(', ', $local))
       : ok('الكلاسات المشتركة بين الشاشتين في app.css');

// ⚠️ قاعدة أمنية قايمة: ممنوع json_encode في app/Views
$badJson = [];
foreach (glob($root . '/app/Views/office_cars/*.php') as $v) {
    if (str_contains(php_code($v), 'json_encode')) { $badJson[] = basename($v); }
}
$badJson ? bad('json_encode في شاشة (الصح js_json): ' . implode(', ', $badJson))
         : ok('مفيش json_encode في شاشات الوحدة');

// ⚠️ ممنوع رقم أصمّ — كل .d-kpi معاه data-drill
$dumb = [];
foreach (glob($root . '/app/Views/office_cars/*.php') as $v) {
    if (preg_match('/class="d-kpi(?![^>]*data-drill)/', (string) file_get_contents($v))) { $dumb[] = basename($v); }
}
$dumb ? bad('رقم بلا تفاصيل في: ' . implode(', ', $dumb)) : ok('مفيش رقم أصمّ في شاشات الوحدة');

// المقارنة إضافة اختيارية — غيابها = الشكل القديم بالحرف
$statSrc = (string) file_get_contents($root . '/app/Views/partials/stat.php');
yes('`stat` لسه بيرفض يترسم بلا drill', str_contains($statSrc, 'ممنوع رقم أصمّ'));
yes('`delta` اختيارية (الافتراضي null)', str_contains($statSrc, "\$delta  = isset(\$delta)"));
ob_start(); partial('stat', ['label' => 'x', 'value' => '1', 'drill' => '/x']); $noDelta = ob_get_clean();
yes('`stat` بلا delta ما بيطبعش شارة المقارنة', !str_contains($noDelta, 'd-kpi-delta'));
ob_start(); partial('stat', ['label' => 'x', 'value' => '1', 'drill' => '/x', 'delta' => -20.0, 'delta_good' => 'down']); $withDelta = ob_get_clean();
yes('نقص المصروفات بيبان أخضر (delta_good=down)', str_contains($withDelta, 'd-kpi-delta up'));

// ═══════════════════════════════════════════ ١٣) سجل السيارات واللوحات المتجاهَلة (v55)
sec('١٣) تصحيح السجل واللوحات المتجاهَلة (v55)');
$keepKey = OfficeCar::plateKey('س ع م 8737');
$cars = OfficeCar::all();
$extra = array_values(array_filter($cars, static fn ($c) => (string) $c['plate_key'] !== $keepKey));
// ⚠️ قرار المالك (2026-07-26): سيارة المكتب الوحيدة «س ع م 8737».
$extra ? bad('فيه سيارة غير «س ع م 8737» في السجل: ' . implode(', ', array_column($extra, 'plate')))
       : ok('السجل فيه سيارة المكتب بس (' . count($cars) . ')');
yes('اللوحات اللي المالك قال «مش بتاعتنا» متسجَّلة', count(OfficeCar::ignoredPlates()) >= 2);
// ⚠️ الإخفاء ده هو اللي بيمنع تنبيه اللوحات إنه يزنّ ويمنع v54 من إعادة تسجيلها
yes('مفيش لوحات «المكتب» غير مسجَّلة ولا متجاهَلة', !OfficeCar::unregisteredOfficePlates());
yes('التجاهل بمفتاح التطبيع مش بالنص الخام',
    OfficeCar::isIgnored('ط ف ر 1474') && OfficeCar::isIgnored('1474 ط ف ر'));
yes('قايمة المتجاهَل بتتعرض بأسمائها الخام', (bool) OfficeCar::ignoredDetail());
yes('سيارة المكتب لسه شايفة رحلاتها',
    count(OfficeCar::trips((int) ($cars[0]['id'] ?? 0))) > 0);

// ═══════════════════════════════════════════ ١٤) الإحصائيات الذكية (v55)
sec('١٤) الإحصائيات الذكية');
$carId = (int) ($cars[0]['id'] ?? 0);
$pM = OfficeCar::period('month', '2026-07-16');
$perfM  = OfficeCar::perf($carId, $pM['from'], $pM['to']);
$dailyM = OfficeCar::daily([$carId], $pM['from'], $pM['to']);

// ⚠️ مفيش تعريف تاني لأي رقم: مجموع `daily` لازم يساوي `perf` بالمليم
$sumTrips = 0; $sumFreight = 0.0; $sumCustody = 0.0; $sumConts = 0;
foreach ($dailyM as $v) {
    $sumTrips   += $v['trips'];
    $sumConts   += $v['containers'];
    $sumFreight += $v['freight'];
    $sumCustody += $v['custody'];
}
same('daily::trips = perf::trips', $sumTrips, (int) $perfM['trips']);
same('daily::containers = perf::containers', $sumConts, (int) $perfM['containers']);
near('daily::freight = perf::freight', $sumFreight, $perfM['freight']);
near('daily::custody = perf::custody', $sumCustody, $perfM['custody']);

$ratesM = OfficeCar::rates($perfM, $pM, $dailyM);
if ((int) $perfM['trips'] > 0) {
    near('نولون الرحلة = النولون ÷ الرحلات',
        $ratesM['freight_per_trip'], $perfM['freight'] / $perfM['trips']);
    same('أيام الشغل ≤ أيام الفترة', $ratesM['work_days'] <= $ratesM['period_days'], true);
} else {
    ok('مفيش رحلات في الفترة — تخطّي فحوص المعدلات');
}
// ⚠️ المقام صفر بيرجع null مش صفر («مفيش بيانات» غير «صفر بالمية»)
$zeroPerf = ['trips' => 0, 'containers' => 0, 'freight' => 0.0,
             'custody' => 0.0, 'expenses' => 0.0, 'net' => 0.0];
$zeroRates = OfficeCar::rates($zeroPerf, $pM, []);
same('نولون الرحلة بلا رحلات = null', $zeroRates['freight_per_trip'], null);
same('نسبة العهد بلا نولون = null', $zeroRates['custody_pct'], null);

$trendM = OfficeCar::trend([$carId], 'month', 12, '2026-07-16');
same('الاتجاه بيرجّع ٣ فترات على الأقل', count($trendM) >= 3, true);
same('آخر فترة في الاتجاه = الفترة الحالية', $trendM[count($trendM) - 1]['from'], $pM['from']);
// ⚠️ الفترات الفاضية قبل أول حركة بتتقصّ — ١٢ عمود فاضي مش رسم
same('الفترة الأولى مش فاضية إلا لو من التلاتة المحفوظة',
    (int) $trendM[0]['trips'] > 0 || count($trendM) <= 3, true);
$tLast = $trendM[count($trendM) - 1];
same('اتجاه الفترة الحالية = perf', (int) $tLast['trips'], (int) $perfM['trips']);
near('نولون الفترة الحالية في الاتجاه = perf', $tLast['freight'], $perfM['freight']);

$tripsM = OfficeCar::trips($carId, $pM['from'], $pM['to']);
$ins = OfficeCar::insights($perfM, null, $ratesM, $tripsM, $pM, $trendM);
yes('القراءة السريعة بترجّع جُمل', (bool) $ins);
$badTone = array_values(array_filter($ins,
    static fn ($i) => !in_array($i['tone'], ['good', 'warn', 'bad', 'info'], true)));
yes('كل جملة ليها نبرة معروفة', !$badTone);
// ⚠️ فترة فاضية ممنوع تطلع جُمل على صفر («الصافي نزل ١٠٠٪» وهو مفيش رحلات)
$pEmpty = OfficeCar::period('month', '2020-01-15');
$insEmpty = OfficeCar::insights(
    OfficeCar::perf($carId, $pEmpty['from'], $pEmpty['to']), null,
    OfficeCar::rates($zeroPerf, $pEmpty, []), [], $pEmpty, []);
same('فترة بلا رحلات بتدّي جملة واحدة واضحة', count($insEmpty) <= 2, true);
yes('  › وبتقول «مفيش ولا رحلة»', str_contains($insEmpty[0]['text'] ?? '', 'مفيش ولا رحلة'));

// أيقونات القراءة السريعة لازم تكون معروفة لـicon() وإلا تقع على شكل افتراضي
$iconSrc = (string) file_get_contents($root . '/app/Core/helpers.php');
$missingIcons = [];
foreach ($ins as $i) {
    if (!str_contains($iconSrc, "'" . $i['icon'] . "'")) { $missingIcons[] = $i['icon']; }
}
$missingIcons ? bad('أيقونة مش في icon(): ' . implode(', ', $missingIcons))
              : ok('كل أيقونات القراءة السريعة معروفة');

// مكوّن الرسم: بلا مكتبة خارجية (الـCSP بتمنعها) وكل عمود رابط لفترته
$mb = (string) file_get_contents($root . '/app/Views/partials/mini_bars.php');
yes('مخطط الأعمدة بلا أي <script> ولا مصدر خارجي',
    !str_contains($mb, '<script') && !str_contains($mb, 'http'));
yes('مخطط الأعمدة مسجَّل في README',
    str_contains((string) file_get_contents($root . '/app/Views/partials/README.md'), 'mini_bars.php'));

// ⚠️ ملف تحليلات واحد للشاشتين — نسختين كانوا هيفترقوا
$idxSrc  = (string) file_get_contents($root . '/app/Views/office_cars/index.php');
$cardSrc = (string) file_get_contents($root . '/app/Views/office_cars/card.php');
yes('شاشة الأسطول بتضمّ _analytics', str_contains($idxSrc, "_analytics.php"));
yes('كارت السيارة بيضمّ _analytics', str_contains($cardSrc, "_analytics.php"));
yes('مفيش نسخة تانية من الرسم مكتوبة في الشاشات',
    !str_contains($idxSrc, 'mb-chart') && !str_contains($cardSrc, 'mb-chart'));

// مسارات التجاهل حرفية قبل الجشع
yes('/cars/ignore قبل الجشع', $pos("'/cars/ignore'") < $pos("\$r->post('/cars/{id}',"));
yes('/cars/unignore قبل الجشع', $pos("'/cars/unignore'") < $pos("\$r->post('/cars/{id}',"));

// كلاسات القراءة الذكية ليها CSS
$anaCss = (string) file_get_contents($root . '/public/assets/css/app.css');
/* ⚠️ `mb-note` اتشال (2026-07-27، قانون التصميم بند ١) — كان بيطبع «اضغط
   أي عمود عشان الشاشة تنقل للفترة دي»، وده شرح لطريقة استخدام حاجة مبيّنة
   أصلاً (العمود رابط بمؤشّر يد و`title`). الماركب والـCSS اتشالوا مع بعض. */
$anaClasses = ['mb-chart', 'mb-col', 'mb-bar', 'mb-lbl', 'mb-val',
               'ins-list', 'ins-row', 'rate-grid', 'rate-cell', 'cars-ignored'];
$anaMissing = array_values(array_filter($anaClasses,
    static fn ($c) => !preg_match('~\.' . preg_quote($c, '~') . '(?![\w-])~', $anaCss)));
$anaMissing ? bad('كلاس بلا CSS: ' . implode(', ', $anaMissing))
            : ok('كل كلاسات القراءة الذكية ليها CSS (' . count($anaClasses) . ')');

// ═══════════════════════════════════════════ الخلاصة
echo "\n" . str_repeat('=', 62) . "\n";
echo "نجح: $pass   فشل: $fail\n";
exit($fail > 0 ? 1 : 0);
