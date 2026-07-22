<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * دمج أرشيف البوالص القديمة في جدول bawalis للمتابعة وكشوف أصحاب البيان.
 *
 * قواعد الأمان:
 *   - لا يُنشئ permits أو permit_movements أو movements أو movement_items.
 *   - كل سجل is_legacy=1 وpermit_id/movement_id=NULL.
 *   - legacy_id يحفظ src_row (رقم صف Excel الثابت)، لذلك التشغيل المتكرر لا يكرر السجلات.
 *   - الدفعة القديمة تُسجّل في bill_payments بوسم is_legacy=1 وتاريخ NULL؛ لا تدخل التقارير.
 *   - لا يمسّ سجلًا سبق ترحيله، حفاظًا على تعديلات صاحب البيان والدفعات الجديدة.
 *   - أي تعارض رقم مع بوليصة حية يوقف العملية كاملة قبل الكتابة.
 *
 * الاستخدام:
 *   php bin/import_legacy_to_live.php --dry-run   # فحص فقط
 *   php bin/import_legacy_to_live.php             # تنفيذ داخل معاملة واحدة
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

function out(string $s, string $c = ''): void
{
    $colors = ['ok'=>"\033[32m", 'hi'=>"\033[36m", 'warn'=>"\033[33m", 'err'=>"\033[31m"];
    echo ($colors[$c] ?? '') . $s . "\033[0m\n";
}

function fail(string $message): never
{
    out('✗ ' . $message, 'err');
    exit(1);
}

function columnExists(string $table, string $column): bool
{
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
        [$table, $column]
    );
}

function containerCount(?string $classification): int
{
    $s = trim((string)$classification);
    return preg_match('/^(\d+)\s*[*x×]/u', $s, $m) ? max(0, (int)$m[1]) : 0;
}

function legacyStatus(float $freight, float $debt, float $paid): string
{
    if ($freight <= 0.005 && $paid <= 0.005) { return 'pending'; }
    $remaining = round($debt - $paid, 2);
    if ($remaining <= 0.005) { return 'paid'; }
    return $paid > 0.005 ? 'partial' : 'unpaid';
}

$dryRun = in_array('--dry-run', $argv, true);
$pdo = Database::conn();

out('══════════════════════════════════════════════════', 'hi');
out('  دمج البوالص القديمة في النظام الجديد' . ($dryRun ? ' — فحص جاف' : ''), 'hi');
out('══════════════════════════════════════════════════', 'hi');

foreach ([['bawalis','is_legacy'], ['bawalis','legacy_id'], ['bill_payments','is_legacy']] as [$t,$c]) {
    if (!columnExists($t, $c)) { fail("العمود $t.$c غير موجود؛ شغّل php bin/migrate_v26.php أولاً."); }
}

$source = Database::all('SELECT * FROM legacy_bawalis ORDER BY src_row, id');
if (!$source) { fail('جدول legacy_bawalis فارغ.'); }

$srcRows = [];
foreach ($source as $r) {
    $src = (int)($r['src_row'] ?? 0);
    if ($src <= 0) { fail('يوجد سجل أرشيفي بلا src_row صالح (legacy_bawalis.id=' . (int)$r['id'] . ').'); }
    if (isset($srcRows[$src])) { fail("src_row=$src مكرر في جدول الأرشيف."); }
    $srcRows[$src] = true;
}

// لا نقبل أي رقم موجود في البوالص الحية؛ الأرشيف الحالي مفروض سبق تنظيفه من تداخل 7 يوليو وما بعده.
$collisions = Database::all(
    "SELECT DISTINCT l.bill_number
       FROM legacy_bawalis l
       JOIN bawalis b ON b.is_legacy=0 AND TRIM(b.bill_number)=TRIM(l.bill_number)
      WHERE l.bill_number IS NOT NULL AND TRIM(l.bill_number)<>''
      ORDER BY l.bill_number"
);
if ($collisions) {
    fail('تعارض مع أرقام بوالص حية: ' . implode('، ', array_column($collisions, 'bill_number')) . '. لم تتم أي كتابة.');
}

// البوليصة القديمة المضافة يدويًا لا تحمل legacy_id. إذا ظهرت لاحقًا في ملف Excel
// نوقف الاستيراد بدل إنشاء نسخة ثانية منها بصمت.
$manualCollisions = Database::all(
    "SELECT DISTINCT l.bill_number
       FROM legacy_bawalis l
       JOIN bawalis b ON b.is_legacy=1 AND b.legacy_id IS NULL
        AND TRIM(b.bill_number)=TRIM(l.bill_number)
      WHERE l.bill_number IS NOT NULL AND TRIM(l.bill_number)<>''
      ORDER BY l.bill_number"
);
if ($manualCollisions) {
    fail('بوالص قديمة مضافة يدويًا أصبحت موجودة في ملف الأرشيف: '
        . implode('، ', array_column($manualCollisions, 'bill_number'))
        . '. اربطها بالمصدر يدويًا قبل إعادة الاستيراد؛ لم تتم أي كتابة.');
}

$existingRows = Database::all('SELECT id, legacy_id, is_legacy FROM bawalis WHERE legacy_id IS NOT NULL');
$existing = [];
foreach ($existingRows as $r) {
    $key = (int)$r['legacy_id'];
    if ((int)$r['is_legacy'] !== 1) { fail("السجل bawalis.id={$r['id']} يحمل legacy_id لكنه غير موسوم كقديم."); }
    if (isset($existing[$key])) { fail("legacy_id=$key مكرر بالفعل في bawalis."); }
    $existing[$key] = (int)$r['id'];
}

$unknown = array_diff(array_keys($existing), array_keys($srcRows));
if ($unknown) {
    fail('توجد بوالص مرحّلة لا يقابلها صف في الأرشيف الحالي: ' . implode('، ', array_slice($unknown, 0, 20)) . '.');
}

$newCount = 0;
$existingCount = 0;
$historicalPaymentCount = 0;
$srcDebt = $srcPaid = $srcRemaining = 0.0;
foreach ($source as $r) {
    $src = (int)$r['src_row'];
    if (isset($existing[$src])) { $existingCount++; continue; }
    $newCount++;
    $debt = round((float)$r['remaining'], 2);
    $paid = round((float)$r['payment'], 2);
    $srcDebt += $debt;
    $srcPaid += $paid;
    $srcRemaining += round($debt - $paid, 2);
    if (abs($paid) > 0.005) { $historicalPaymentCount++; }
}

out('• مصدر الأرشيف: ' . count($source) . ' بوليصة', 'warn');
out("• موجودة سابقًا: $existingCount | جديدة للدمج: $newCount", 'warn');
out('• دفعات تاريخية ستُضاف: ' . $historicalPaymentCount, 'warn');
out('• البوالص الحية الحالية لن تتغير: ' . (int)Database::scalar('SELECT COUNT(*) FROM bawalis WHERE is_legacy=0'), 'warn');

if ($dryRun) {
    out('✓ الفحص الجاف نجح: لا تعارضات ولم تتم أي كتابة.', 'ok');
    exit(0);
}

$billCols = [
    'permit_id','movement_id','bill_type','bill_date','bill_number','cert_number','permit_no',
    'declaration_owner','classification','company','destination','container_no','container_type','container_count',
    'car_no','driver','car_owner','freight','custody','transfer','discount','overnight_collection','payment',
    'driver_share','assistant_share','overnight_expense','expense','cards','tahia_masr','thabtan','z_kawtsh',
    'permits','kawtsh','other_expense','gas','total_collection','total_expense','custody_remaining','car_net','profit_pct',
    'debt','paid','remaining','pay_status','is_legacy','legacy_id',
];
$billSql = 'INSERT INTO bawalis (`' . implode('`,`', $billCols) . '`)
            VALUES (' . implode(',', array_fill(0, count($billCols), '?')) . ')';
$paymentSql = 'INSERT INTO bill_payments (bill_id, pay_date, amount, note, is_legacy)
               VALUES (?,NULL,?,?,1)';

$pdo->beginTransaction();
try {
    $billSt = $pdo->prepare($billSql);
    $paymentSt = $pdo->prepare($paymentSql);
    $inserted = $payments = 0;

    foreach ($source as $r) {
        $src = (int)$r['src_row'];
        if (isset($existing[$src])) { continue; }

        $class = trim((string)($r['classification'] ?? '')) ?: null;
        // العدد يُشتق من نص الأرشيف الخام «العدد*المقاس» أولاً، ثم نوحّد نص التصنيف
        // على الصيغة القانونية (40 قدم…) حتى لا يعود التلوّث عند إعادة الاستيراد.
        $count     = containerCount($class);       // من الخام قبل التوحيد
        $classNorm = container_norm($class);       // «1*40» → «40 قدم»
        $debt = round((float)$r['remaining'], 2);       // «باقى» القديم = أصل المستحق له
        $paid = round((float)$r['payment'], 2);         // «دفعة» القديمة = ما استلمه تاريخياً
        $remaining = round($debt - $paid, 2);

        $values = [
            null, null, 'sader', $r['bill_date'] ?: null, $r['bill_number'] ?: null, null, null,
            $r['declaration_owner'] ?: null, $classNorm, $r['company'] ?: null, $r['destination'] ?: null,
            $r['container_no'] ?: null, $classNorm, $count,
            $r['car_no'] ?: null, $r['driver'] ?: null, $r['car_owner'] ?: null,
            (float)$r['freight'], (float)$r['custody'], (float)$r['transfer'], (float)$r['discount'],
            (float)$r['overnight_collection'], $paid,
            (float)$r['driver_share'], (float)$r['assistant_share'], (float)$r['overnight_expense'],
            (float)$r['expense'], (float)$r['cards'], (float)$r['tahia_masr'], (float)$r['thabtan'],
            (float)$r['z_kawtsh'], (float)$r['permits_fee'], (float)$r['kawtsh'],
            (float)$r['other_expense'], (float)$r['gas'],
            0.0, (float)$r['total_expense'], (float)$r['custody_remaining'], (float)$r['car_net'], 0.0,
            $debt, $paid, $remaining, legacyStatus((float)$r['freight'], $debt, $paid), 1, $src,
        ];
        $billSt->execute($values);
        $billId = (int)$pdo->lastInsertId();
        $inserted++;

        if (abs($paid) > 0.005) {
            $paymentSt->execute([$billId, $paid, "دفعة تاريخية مرحّلة من الأرشيف — صف Excel $src"]);
            $payments++;
        }
    }

    // تحقق داخل نفس المعاملة: العدد، العزل البنيوي، وعدم إنشاء أي حركة.
    $sourceCount = count($source);
    $mergedCount = (int)Database::scalar('SELECT COUNT(*) FROM bawalis WHERE is_legacy=1 AND legacy_id IS NOT NULL');
    if ($mergedCount !== $sourceCount) { throw new RuntimeException("عدد المدمج ($mergedCount) لا يساوي المصدر ($sourceCount)."); }

    $linked = (int)Database::scalar(
        'SELECT COUNT(*) FROM bawalis WHERE is_legacy=1 AND (permit_id IS NOT NULL OR movement_id IS NOT NULL)'
    );
    if ($linked !== 0) { throw new RuntimeException("وُجدت $linked بوليصة قديمة مرتبطة بإذن/حركة."); }

    $movementCount = (int)Database::scalar(
        'SELECT COUNT(*) FROM movements m JOIN bawalis b ON b.id=m.bill_id WHERE b.is_legacy=1'
    );
    if ($movementCount !== 0) { throw new RuntimeException("أُنشئت $movementCount حركة لبوالص قديمة."); }

    $sums = Database::row(
        'SELECT COALESCE(SUM(debt),0) debt, COALESCE(SUM(paid),0) paid,
                COALESCE(SUM(remaining),0) remaining
           FROM bawalis WHERE is_legacy=1 AND legacy_id IS NOT NULL'
    );
    $sourceSums = Database::row(
        'SELECT COALESCE(SUM(remaining),0) debt, COALESCE(SUM(payment),0) paid,
                COALESCE(SUM(remaining-payment),0) remaining FROM legacy_bawalis'
    );
    foreach (['debt','paid','remaining'] as $key) {
        if (abs((float)$sums[$key] - (float)$sourceSums[$key]) > 0.01) {
            throw new RuntimeException("إجمالي $key لا يطابق الأرشيف.");
        }
    }

    $pdo->commit();
    out("✓ تم دمج $inserted بوليصة وإضافة $payments دفعة تاريخية داخل معاملة واحدة.", 'ok');
    out('✓ كل البوالص القديمة معزولة وبلا إذن أو حركة، والإجماليات مطابقة للمصدر.', 'ok');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    fail('فشل الدمج وتراجعت المعاملة كاملة: ' . $e->getMessage());
}
