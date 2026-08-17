<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص تعريفة النولون وتوقّعه في الفاتورة (v102).
 *
 * ⚠️ **بيفحص السلوك مش أرقام القوايم**: مفيش تأكيد على «الإسماعيلية = ١٩٬٨٠٠»
 *    — الأسعار بتتغيّر والمالك بيعدّلها من الشاشة، والفحص المكتوب عليها بيفشل
 *    بإنذار كاذب أول تعديل مشروع. اللي بيتفحص هو **القواعد**: إزاي بتتطابق،
 *    وإمتى بتسكت، ومين بيغلب مين.
 *
 * ⚠️ **بيزرع تعريفته الخاصة بتاريخ سريان قديم** (2020) على إذن بتاريخ 2020،
 *    فقوايم ٢٠٢٦ الحقيقية مستبعدة تمامًا من الحساب — الفحص ما بيعتمدش على
 *    بيانات الإنتاج ولا بيتأثر بيها.
 *
 * ⚠️ كله جوّه transaction + rollback، وبلقطة مالية قبل/بعد.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\FreightQuote;
use App\Models\FreightTariff;

$fails = 0;
$ok  = static function (string $m): void { echo "  ✓ $m\n"; };
$bad = static function (string $m) use (&$fails): void { echo "  ✗ $m\n"; $fails++; };

echo "فحص v102 — تعريفة النولون وتوقّعه\n" . str_repeat('=', 66) . "\n";

/* ───────────────────────────────────────────────────────────────────────
   ① العزل: ولا ملف مالي بيعرف جداول التعريفة
   ─────────────────────────────────────────────────────────────────────── */
echo "\n① العزل بالبناء\n";
$code_only = static function (string $f): string {
    $src = (string) @file_get_contents($f);
    return $src === '' ? '' : (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~', '~^\s*\*.*$~m'], '', $src);
};
$tables = ['freight_tariffs', 'freight_surcharges'];
$moneyFiles = array_merge(
    glob(dirname(__DIR__) . '/app/Models/{Closing,Custody,CompanySafe,Payroll,Expense,Movement,Debt}*.php', GLOB_BRACE) ?: [],
    glob(dirname(__DIR__) . '/app/Controllers/{DailyClosing,CompanySafe,Custody,Payroll,Expense,Settlement}*.php', GLOB_BRACE) ?: []
);
$leaks = [];
foreach ($moneyFiles as $f) {
    $src = $code_only($f);
    foreach ($tables as $t) { if (str_contains($src, $t)) { $leaks[] = basename($f) . " ← $t"; } }
}
$leaks ? $bad('ملف مالي بيعرف جدول التعريفة: ' . implode(' · ', $leaks))
       : $ok('ولا ملف خزنة/تقفيل/عهدة ذكر جداول التعريفة (' . count($moneyFiles) . ' ملف)');

/* ───────────────────────────────────────────────────────────────────────
   ② تصنيف الميناء والوحدة
   ─────────────────────────────────────────────────────────────────────── */
echo "\n② تصنيف الميناء والوحدة\n";
$portCases = [
    'ميناء الإسكندرية' => 'alex', 'الإسكندرية' => 'alex', 'ميناء الدخيلة' => 'alex',
    'ميناء ابوقير' => 'alex', 'بورسعيد شرق' => 'psd_east', 'ميناء بورسعيد (غرب)' => 'psd_west',
    'ميناء دمياط' => null, '' => null,
];
$pOk = true;
foreach ($portCases as $in => $want) {
    $got = FreightTariff::portGroup($in);
    if ($got !== $want) { $bad("الميناء «$in» اتصنّف " . var_export($got, true) . ' بدل ' . var_export($want, true)); $pOk = false; }
}
if ($pOk) { $ok('٨ صيغ ميناء اتصنّفت صح — ودمياط بيرجّع null (مالوش قائمة) مش تخمين'); }

/* الوحدة: السيارة هي وحدة التسعير — حاويتين ٢٠ على سيارة = سطر «2×20» */
$u1 = FreightTariff::unitOf('20 قدم', 1);
$u2 = FreightTariff::unitOf('20 قدم', 2);
($u1['unit'] ?? '') === '20' && ($u2['unit'] ?? '') === '2x20'
    ? $ok('حاوية ٢٠ لوحدها ⇒ «20» · واتنين على سيارة ⇒ «2x20»')
    : $bad('وحدة الـ٢٠ غلط: ' . json_encode([$u1, $u2], JSON_UNESCAPED_UNICODE));

(FreightTariff::unitOf('40 قدم', 1)['unit'] ?? '') === '40'
    ? $ok('حاوية ٤٠ ⇒ «40»') : $bad('وحدة الـ٤٠ غلط');
(FreightTariff::unitOf('40 مبرّد', 1)['service'] ?? '') === 'reefer'
    ? $ok('«40 مبرّد» ⇒ خدمة ثلاجات') : $bad('المبرّد ما اتصنّفش ثلاجة');
FreightTariff::unitOf('فرش', 1) === null
    ? $ok('«فرش» مش نوع مسعّر ⇒ null (مش تخمين)') : $bad('«فرش» اتسعّر وهو مش في القوايم');
FreightTariff::unitOf('', 1) === null
    ? $ok('التصنيف الفاضي بيرجّع null') : $bad('التصنيف الفاضي اتسعّر');

/* ───────────────────────────────────────────────────────────────────────
   ③ مطابقة الجهة — الأخصّ يكسب، والجزئي جوّه كلمة مرفوض
   ─────────────────────────────────────────────────────────────────────── */
echo "\n③ مطابقة الجهة\n";
$rows = [
    ['zone_label' => 'العاشر — العبور — مدينة نصر'],
    ['zone_label' => 'بني سويف — السويس — السخنة'],
    ['zone_label' => 'دمنهور — إيتاي البارود — كوم حمادة — رشيد'],
];
$m = FreightTariff::matchZone($rows, 'العبور');
($m['how'] ?? '') === 'exact' && ($m['zone'] ?? '') === 'العاشر — العبور — مدينة نصر'
    ? $ok('«العبور» طابقت سطرها بالحرف (اسم جوّه سطر متعدّد)')
    : $bad('المطابقة التامة فشلت: ' . json_encode($m, JSON_UNESCAPED_UNICODE));

$m = FreightTariff::matchZone($rows, 'العاشر من رمضان');
($m['how'] ?? '') === 'contains'
    ? $ok('«العاشر من رمضان» طابقت «العاشر» باحتواء كلمة كاملة')
    : $bad('الاحتواء فشل: ' . json_encode($m, JSON_UNESCAPED_UNICODE));

$m = FreightTariff::matchZone($rows, 'السويس');
($m['zone'] ?? '') === 'بني سويف — السويس — السخنة' && ($m['how'] ?? '') === 'exact'
    ? $ok('«السويس» ما اتلغبطتش مع «بني سويف»')
    : $bad('«السويس» طابقت غلط: ' . json_encode($m, JSON_UNESCAPED_UNICODE));

/* ⚠️ الحارس الأهم: «رشيد» جوّه «مرشيد» **مش** مطابقة — الاحتواء بكلمة كاملة. */
$m = FreightTariff::matchZone([['zone_label' => 'رشيد']], 'مرشيدة الجديدة');
($m === null || ($m['how'] ?? '') === 'near')
    ? $ok('«مرشيدة» ما اعتبرتش «رشيد» مطابقة تامة (الاحتواء بكلمة كاملة)')
    : $bad('احتواء جزئي جوّه كلمة اتقبل — ده بيدّي سعر جهة تانية');

FreightTariff::matchZone($rows, 'مكان مش موجود خالص') === null
    ? $ok('الجهة المجهولة بترجّع null — صمت مش أقرب سعر')
    : $bad('الجهة المجهولة اتطابقت بحاجة');

/* ───────────────────────────────────────────────────────────────────────
   ④ السلوك على تعريفة وأذون مزروعة
   ─────────────────────────────────────────────────────────────────────── */
echo "\n④ التوقّع على بيانات مزروعة\n";

$snap = static fn (): array => [
    'inv'   => (int) Database::scalar('SELECT COUNT(*) FROM permit_invoices'),
    'total' => (float) Database::scalar('SELECT COALESCE(SUM(total),0) FROM permit_invoices'),
];
$before = $snap();

$pdo = Database::conn();
$pdo->beginTransaction();
try {
    $OLD = '2020-01-01';   // تعريفة الفحص — بعيدة تمامًا عن قوايم ٢٠٢٦
    $seed = static function (string $unit, float $price, string $zone = 'مدينة الفحص') use ($OLD): void {
        Database::exec(
            "INSERT INTO freight_tariffs (port_group, direction, service, zone_label, unit, price, effective_from, source_ref, active)
                  VALUES ('alex','export','container',?,?,?,?,'TEST',1)
             ON DUPLICATE KEY UPDATE price = VALUES(price)", [$zone, $unit, $price, $OLD]);
    };
    $seed('20', 1000);
    $seed('40', 1800);
    $seed('2x20', 1500);

    /** إذن اختبار بتاريخ ٢٠٢٠ عشان يقرا تعريفة الفحص لا تعريفة ٢٠٢٦. */
    $mk = static function (string $type, int $conts, int $cars, string $dest = 'مدينة الفحص',
                           ?string $client = null, ?string $company = null) use ($OLD): int {
        Database::exec(
            "INSERT INTO permits (permit_type, permit_no, permit_date, client, company, destination, port,
                                  container_type, containers, status)
             VALUES ('sader', ?, ?, ?, ?, ?, 'ميناء الإسكندرية', ?, ?, 'open')",
            ['V102-' . uniqid(), $OLD, $client, $company, $dest, $type, $conts]);
        $pid = (int) Database::conn()->lastInsertId();
        for ($i = 0; $i < $cars; $i++) {
            Database::exec(
                "INSERT INTO bawalis (permit_id, bill_type, bill_date, container_type, container_count, car_no)
                 VALUES (?, 'sader', ?, ?, ?, ?)",
                [$pid, $OLD, $type, (int) ceil($conts / max(1, $cars)), 'ف-' . $pid . '-' . $i]);
        }
        return $pid;
    };

    // (أ) حاوية ٤٠ واحدة ⇒ سعر الـ٤٠
    $q = FreightQuote::forPermit($mk('40 قدم', 1, 1));
    ((float) ($q['price'] ?? 0) === 1800.0 && $q['source'] === 'tariff')
        ? $ok('٤٠ قدم ⇒ سعر سطر «40» من التعريفة')
        : $bad('٤٠ قدم دّى ' . json_encode([$q['price'], $q['source']]));

    // (ب) حاويتين ٢٠ على سيارة واحدة ⇒ سطر «2×20» لا ضِعف سعر الـ٢٠
    $q = FreightQuote::forPermit($mk('20 قدم', 2, 1));
    ((float) ($q['price'] ?? 0) === 1500.0)
        ? $ok('حاويتين ٢٠ على سيارة ⇒ سطر «2x20» (مش ٢×سعر الـ٢٠)')
        : $bad('الـ2x20 دّى ' . var_export($q['price'], true) . ' بدل 1500');

    // (ج) الإجمالي = سعر السيارة × عدد السيارات
    $pid = $mk('40 قدم', 3, 3);
    $q = FreightQuote::forPermit($pid);
    ((int) ($q['cars'] ?? 0) === 3 && (float) ($q['total'] ?? 0) === 5400.0)
        ? $ok('٣ سيارات ⇒ الإجمالي = السعر × ٣')
        : $bad('الإجمالي غلط: ' . json_encode([$q['cars'], $q['total']]));

    // (د) جهة مش في التعريفة ⇒ صمت بسبب مكتوب
    $q = FreightQuote::forPermit($mk('40 قدم', 1, 1, 'جهة مالهاش سعر خالص'));
    ($q['ready'] === false && trim((string) $q['note']) !== '')
        ? $ok('الجهة المجهولة ⇒ صمت **بسبب مكتوب** مش خانة فاضية')
        : $bad('الجهة المجهولة دّت رقمًا أو سكتت بلا سبب');

    // (هـ) نوع مش مسعّر ⇒ السبب بيقول التصنيف لا الجهة
    $q = FreightQuote::forPermit($mk('فرش', 1, 1));
    (str_contains((string) $q['note'], 'فرش'))
        ? $ok('التصنيف غير المسعّر ⇒ السبب بيسمّيه بالاسم')
        : $bad('سبب الصمت ما سمّاش التصنيف: ' . (string) $q['note']);

    /* ───────── (و) الحارس الأهم: العميل فاضي والشركة ليها فواتير ─────────
       ⚠️ ده بالظبط العطل اللي المالك صادفه: إذن «ميتاكيم للتوريد» عميله فاضي
          فالاقتراح رجع صفر **بلا أي سبب ظاهر**. */
    $pidH = $mk('40 قدم', 1, 1, 'مدينة الفحص', null, 'شركة فحص ١٠٢');
    for ($i = 0; $i < 3; $i++) {
        Database::exec(
            "INSERT INTO permit_invoices (permit_id, doc_no, invoice_date, client, company, destination,
                                          freight, total, items_json, status)
             VALUES (NULL, ?, ?, NULL, 'شركة فحص ١٠٢', 'مدينة الفحص', 5000, 5000, ?, 'issued')",
            ['TST-' . uniqid(), date('Y-m-d'),
             json_encode([['freight' => 1, 'price' => 5000, 'qty' => 1]], JSON_UNESCAPED_UNICODE)]);
    }
    $q = FreightQuote::forPermit($pidH);
    ((float) ($q['price'] ?? 0) === 5000.0 && $q['source'] === 'history')
        ? $ok('عميل فاضي + شركة ليها فواتير ⇒ الاقتراح طلع من الشركة (العطل اللي اتصلّح)')
        : $bad('الرجوع للشركة ما اشتغلش: ' . json_encode([$q['price'], $q['source']]));

    (($q['history']['party'] ?? '') === 'الشركة' && str_contains((string) ($q['history']['scope'] ?? ''), 'الشركة'))
        ? $ok('والوسم بيقول «الشركة» — مش بيكدب ويقول «العميل»')
        : $bad('الوسم بيقول ' . json_encode($q['history'] ?? null, JSON_UNESCAPED_UNICODE));

    // (ز) التاريخ بيغلب التعريفة، والفرق بيتحسب ويتعرض
    ($q['tariff'] !== null && $q['gap'] !== null && abs((float) $q['gap']['diff'] - (5000 - 1800)) < 0.01)
        ? $ok('التاريخ غلب التعريفة، والفرق بينهم متحسوب ومعروض')
        : $bad('الفرق بين التعريفة والتاريخ مش متحسوب صح');

    // (ح) الإضافة: ثلاجة بورسعيد بتتزوّد على النولون
    Database::exec(
        "INSERT INTO freight_tariffs (port_group, direction, service, zone_label, unit, price, effective_from, source_ref, active)
              VALUES ('psd_east','any','container','مدينة الفحص','40',2000,?,'TEST',1)
         ON DUPLICATE KEY UPDATE price = VALUES(price)", [$OLD]);
    Database::exec(
        "INSERT INTO freight_surcharges (port_group, code, label, amount, effective_from, active)
              VALUES ('psd_east','reefer_trip','زيادة ثلاجة',400,?,1)
         ON DUPLICATE KEY UPDATE amount = VALUES(amount)", [$OLD]);
    $s = FreightTariff::surcharge('psd_east', 'reefer_trip', $OLD);
    ((float) ($s['amount'] ?? 0) === 400.0)
        ? $ok('إضافة ثلاجة بورسعيد بتتقرا من جدولها')
        : $bad('إضافة الثلاجة مش بتتقرا');

    // (ط) التعريفة بتتقرا بتاريخ الإذن: قايمة ٢٠٢٦ ما بتأثرش على إذن ٢٠٢٠
    $rows2020 = FreightTariff::rows('alex', 'export', 'container', $OLD);
    $eff = array_unique(array_column($rows2020, 'effective_from'));
    (count($eff) === 1 && reset($eff) === $OLD)
        ? $ok('إذن ٢٠٢٠ بيقرا تعريفة ٢٠٢٠ بس — قوايم ٢٠٢٦ مستبعدة')
        : $bad('التعريفة اتخلطت بين سنتين: ' . implode('،', $eff));

} finally {
    $pdo->rollBack();
}

$after = $snap();
($after['inv'] === $before['inv'] && abs($after['total'] - $before['total']) < 0.005)
    ? $ok('ولا فاتورة اتغيّرت — الفحص ما لمسش مليم')
    : $bad('الفحص غيّر أرقام الفواتير');

echo "\n" . str_repeat('─', 66) . "\n";
if ($fails > 0) { echo "✗ $fails فحص فشل.\n"; exit(1); }
echo "✓ كل الفحوص عدّت.\n";
exit(0);
