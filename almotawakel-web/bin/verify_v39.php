<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص v39 — نثريات المناديب (مصروف عام بلا إذن).
 *
 * ⚠️ **اتحدّث في v42:** العزل عن الخزنة (اللي كان الفحص رقم 1 هنا) **اتلغى بقرار
 *    المالك** — النثرية بقت مصروف شركة بيتخصم من الخزنة يوم صرفه زي أي مصروف،
 *    وبيظهر في الصف العام في مطابقة التقفيل. الفلوس دي أصلها من الدرج.
 *
 * ⚠️ **اللي لسه قائم وما اتغيّرش: العزل عن حساب العميل.** النثرية ما تظهرش في أي
 *    فاتورة إذن، وما تولّدش أي صف في permit_stages إطلاقًا (لأن دي كانت هتوصلها
 *    لفاتورة عميل). ده قرار مالك مستقل تمامًا عن موضوع الخزنة.
 *
 * آمن: كل البيانات الوهمية جوّه transaction وبيعمل rollback في الآخر مهما حصل،
 * فما بيسيبش أي أثر في قاعدة البيانات.
 *
 * التشغيل:  php bin/verify_v39.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;
use App\Models\Expense;
use App\Models\PermitInvoice;
use App\Models\RepCustody;
use App\Models\RepInbox;
use App\Models\RepMiscExpense;
use App\Models\RepPortal;

$pass = 0; $fail = 0; $skip = 0;

function ok(string $m): void   { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  ✗ $m\n"; }
function skip(string $m): void { global $skip; $skip++; echo "  ~ $m (تخطّي)\n"; }
function sec(string $m): void  { echo "\n" . $m . "\n" . str_repeat('─', 60) . "\n"; }

function is(string $label, $actual, $expected): void
{
    $same = (is_float($expected) || is_float($actual))
        ? abs((float) $actual - (float) $expected) < 0.005
        : $actual == $expected;
    $same ? ok($label) : bad("$label — متوقع: " . var_export($expected, true) . " / فعلي: " . var_export($actual, true));
}

echo "فحص v39 — نثريات المناديب\n" . str_repeat('=', 60) . "\n";

// ═══════════════════════════════════════════════ 1) البنية
sec('1) البنية (migrate_v39)');
$tbl = (bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema=DATABASE() AND table_name='rep_misc_expenses'");
$tbl ? ok('جدول rep_misc_expenses موجود') : bad('جدول rep_misc_expenses ناقص — شغّل php bin/migrate_v39.php');

$nullable = Database::scalar(
    "SELECT IS_NULLABLE FROM information_schema.columns
     WHERE table_schema=DATABASE() AND table_name='rep_submissions' AND column_name='permit_id'");
is('rep_submissions.permit_id بيقبل NULL (نثرية بلا إذن)', $nullable, 'YES');

$miscCol = (bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema=DATABASE() AND table_name='rep_submissions' AND column_name='misc_id'");
$miscCol ? ok('عمود rep_submissions.misc_id موجود') : bad('عمود misc_id ناقص');

is('RepMiscExpense::available() = true', RepMiscExpense::available(), true);

if (!$tbl || !$miscCol) { echo "\nالبنية ناقصة — شغّل php bin/migrate_v39.php الأول.\n"; exit(1); }

// ═══════════════════════════════════════════════ 2) الوصل بالخزنة (v42)
sec('2) الوصل بالخزنة — كود التقفيل لازم يشوف النثريات دلوقتي');
// v39 كان بيعزلها بالبناء (جدول مستقل عمره ما سمع عنه كود الخزنة). v42 عكس القرار:
// النثرية كاش خرج من الدرج عبر عهدة المندوب، فلازم تتخصم وتظهر في التقفيل.
$cashFiles = [
    'app/Models/Closing.php'                     => true,   // sumExpenses + hasMovementBetween + earliestMovementDate
    'app/Models/DailyReview.php'         => true,   // الصف العام
    'app/Controllers/DailyClosingController.php' => true,   // جدول التفاصيل
    'bin/backfill_closings.php'                  => true,   // اكتشاف أيام الترحيل
];
foreach ($cashFiles as $f => $mustSee) {
    $src = @file_get_contents(dirname(__DIR__) . '/' . $f);
    if ($src === false) { skip("$f مش موجود"); continue; }
    $sees = str_contains($src, 'rep_misc_expenses') || str_contains($src, 'RepMiscExpense');
    $sees === $mustSee
        ? ok("$f بيشوف النثريات (قاعدة v42)")
        : bad("$f مش بيشوف النثريات — هتختفي من التقفيل وترجع فتحة v35");
}

// النثرية لازم تكون ظاهرة في سجل المصروفات — ده المطلوب فعلاً
$expSrc = (string) @file_get_contents(dirname(__DIR__) . '/app/Models/Expense.php');
is('Expense بتضم النثريات في سجل المصروفات',
    str_contains($expSrc, 'RepMiscExpense::expenseUnionSql'), true);
is("Expense::SOURCES فيها 'rep_misc'", isset(Expense::SOURCES['rep_misc']), true);

// ولا تظهر في فاتورة العميل — الفاتورة بتسحب من permit_stages بس
$invSrc = (string) @file_get_contents(dirname(__DIR__) . '/app/Models/PermitInvoice.php');
is('PermitInvoice ما بتعرفش النثريات إطلاقًا',
    !str_contains($invSrc, 'rep_misc') && !str_contains($invSrc, 'RepMiscExpense'), true);

// ═══════════════════════════════════════════════ 3) النوع
sec('3) نوع البند');
is("KINDS فيها 'misc'", isset(RepPortal::KINDS['misc']), true);
is('isMisc(misc) = true', RepPortal::isMisc('misc'), true);
is('isMisc(receipt) = false', RepPortal::isMisc('receipt'), false);
is('isMisc(expense) = false', RepPortal::isMisc('expense'), false);
is('isMisc(null) = false', RepPortal::isMisc(null), false);

// ═══════════════════════════════════════════════ التجهيز للفحوص الحيّة
$rep = Database::row("SELECT id,name FROM reps ORDER BY id LIMIT 1");
if (!$rep) {
    echo "\nمفيش مندوب للفحص الحيّ — تخطّي الباقي.\n";
    echo "\nنجح: $pass | فشل: $fail | تخطّي: $skip\n";
    exit($fail > 0 ? 1 : 0);
}
$repId = (int) $rep['id'];
$today = date('Y-m-d');

$pdo = Database::conn();
$pdo->beginTransaction();   // كل اللي جاي بيترجع في الآخر

try {
    $bal0   = Closing::currentBalance();
    $cust0  = RepCustody::balance($repId);
    $stages0 = (int) Database::scalar("SELECT COUNT(*) FROM permit_stages");

    // ═══════════════════════════════════════════ 4) الأثر على الخزنة
    sec('4) الأثر على الخزنة — التنزيل لا يخصم، والصرف يخصم يومه');
    echo "  رصيد الخزنة الابتدائي: " . number_format($bal0, 2) . " ج\n";

    RepCustody::createDeposit($repId, 3000, $today, '__v39');
    /* ⚠️ **التوقّع واعي بالنموذج** — قرار المالك (2026-07-30): «أي عهدة تتضاف
   للمندوب كأنها في الدرج»، فتنزيل البنك بقى **بيزوّد** رصيد الخزنة.
   `RepCustody::bankTopupActive()` هي المصدر الوحيد للفحص. */
    $depAdds = \App\Models\RepCustody::bankTopupActive();
    is($depAdds ? 'تنزيل عهدة 3000 → الخزنة زادت 3000 (الخزنة = كل كاش الشركة)'
                : 'تنزيل عهدة 3000 → الخزنة ما اتغيرتش',
       Closing::currentBalance(), $bal0 + ($depAdds ? 3000 : 0));
    $bal0 = Closing::currentBalance();   // الأساس بقى بعد التنزيل
    is('تنزيل عهدة 3000 → عهدة المندوب زادت', RepCustody::balance($repId), $cust0 + 3000);

    $miscId = RepMiscExpense::create([
        'rep_id' => $repId, 'exp_date' => $today,
        'note' => '__v39_مواصلات', 'qty' => 1, 'unit_amount' => 250,
    ]);
    /* ⚠️ **التوقّع واعي بالعصر — القلب مقصود مش ترخية.** المرحلة ٢ من docs/16
       شالت صرف المندوب من الدرج بالكامل (النثرية بتتدفع من محفظته، والفلوس دي
       جاية من البنك للمندوب على طول مش من الدرج). القاعدة القديمة (v42) لسه
       سارية على أي نثرية **قبل** بداية القاعدة الجديدة. */
    /* ⚠️ **الشرط مفتاح + تاريخ مع بعض** — `safeChargeSql` محكومة بالاتنين
   (`$dateCol < safeRuleFrom`)، فربط التوقّع بالمفتاح لوحده بيفشل على أي
   يوم زرع قبل تاريخ البداية. المصدر الوحيد `RepCustody::safeRuleFrom()`. */
    $newRule = \App\Models\RepCustody::newSafeRuleActive()
             && $today >= \App\Models\RepCustody::safeRuleFrom();
    $hits = $newRule ? 0 : 1;   // مضروب في المبلغ
    is($newRule ? 'نثرية 250 → الخزنة **ما اتغيّرتش** (محفظة المندوب مستقلة)'
                : 'نثرية 250 → الخزنة نقصت 250',
       Closing::currentBalance(), $bal0 - 250 * $hits);
    is('نثرية 250 → عهدة المندوب نقصت 250', RepCustody::balance($repId), $cust0 + 2750);

    // اختبار الضغط: مبلغ ضخم يثبت عدم وجود حد أو استثناء خفي.
    Database::exec("UPDATE rep_misc_expenses SET amount=999999 WHERE id=?", [$miscId]);
    is($newRule ? 'نثرية بـ999,999 → الخزنة ما اتغيّرتش (مفيش تسريب من المحفظة للدرج)'
                : 'نثرية بـ999,999 → الخزنة تخصمها كاملة',
       Closing::currentBalance(), $bal0 - 999999 * $hits);
    Database::exec("UPDATE rep_misc_expenses SET amount=250 WHERE id=?", [$miscId]);

    // ═══════════════════════════════════════════ 5) العزل عن حساب العميل
    sec('5) العزل — النثرية ما تلمسش أي حساب عميل');
    is('النثرية ما ولّدتش أي صف في permit_stages',
        (int) Database::scalar("SELECT COUNT(*) FROM permit_stages"), $stages0);
    is('الجدول مفيهوش أي عمود بيربط بإذن',
        (int) Database::scalar(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema=DATABASE() AND table_name='rep_misc_expenses'
               AND column_name IN ('permit_id','movement_id','actual_amount','client')"), 0);

    // ═══════════════════════════════════════════ 6) بتظهر في المصروفات
    sec('6) النثرية بتظهر في سجل المصروفات');
    $rows = Expense::ledger(['from' => $today, 'to' => $today, 'source' => 'rep_misc'], 200, 0);
    $mine = array_filter($rows, fn($x) => str_contains((string) ($x['note'] ?? ''), '__v39'));
    is('النثرية ظهرت في /expenses بمصدر rep_misc', count($mine) >= 1, true);
    $one = $mine ? reset($mine) : [];
    is('التصنيف = نثريات', $one['category'] ?? '', RepMiscExpense::CATEGORY);
    is('المبلغ صح', (float) ($one['amount'] ?? 0), 250.0);
    is('is_visa = 0 دايمًا (كاش من العهدة)', (int) ($one['is_visa'] ?? 1), 0);

    // ═══════════════════════════════════════════ 7) رحلة البند كاملة
    sec('7) الرحلة: المندوب يبعت ← المالك يعتمد');
    $subId = RepPortal::submit($repId, [
        'permit_id' => 0, 'kind' => 'misc', 'note' => '__v39_شاي',
        // v71 — الوسم بقى إجباريًّا على أي نثرية (شوف verify_v71)
        'misc_kind' => 'general',
        'qty' => 3, 'unit_amount' => 40, 'entry_date' => $today,
    ]);
    $sub = Database::row("SELECT * FROM rep_submissions WHERE id=?", [$subId]);
    is('البند اتسجّل بـpermit_id = NULL', $sub['permit_id'], null);
    is('البند اتسجّل بـmovement_id = NULL', $sub['movement_id'], null);
    is('الإجمالي = 3 × 40 (قاعدة v17)', (float) $sub['amount'], 120.0);
    is('البند مستني اعتماد', $sub['status'], 'pending');

    $balBefore = Closing::currentBalance();
    $custBefore = RepCustody::balance($repId);
    is('البند المستني ما خصمش من العهدة لسه', $custBefore, $cust0 + 2750);

    $newMiscId = RepInbox::approve($subId);
    $sub = Database::row("SELECT * FROM rep_submissions WHERE id=?", [$subId]);
    is('البند اتعمد', $sub['status'], 'approved');
    is('اتربط بصف النثرية (misc_id)', (int) $sub['misc_id'], (int) $newMiscId);
    is('ما اتولّدش أي stage_id', $sub['stage_id'], null);
    is('الاعتماد ما ولّدش صف في permit_stages',
        (int) Database::scalar("SELECT COUNT(*) FROM permit_stages"), $stages0);
    // ⚠️ نفس قاعدة النثريات فوق: بعد المرحلة ٢ النثرية مبقاش لها أثر على الدرج
    is($newRule ? 'بعد الاعتماد: الخزنة ما اتغيّرتش (نثرية من محفظة المندوب)'
                : 'بعد الاعتماد: الخزنة نقصت 120',
       Closing::currentBalance(), $balBefore - 120 * $hits);
    is('بعد الاعتماد: عهدة المندوب نقصت 120', RepCustody::balance($repId), $custBefore - 120);

    $saved = RepMiscExpense::find($newMiscId);
    is('تاريخ النثرية = يوم صرف المندوب (قاعدة v29)', $saved['exp_date'], $today);
    is('الكمية اتحفظت', (int) $saved['qty'], 3);
    is('سعر الوحدة اتحفظ', (float) $saved['unit_amount'], 40.0);

    // ═══════════════════════════════════════════ 8) حراسة التحويل بين الأنواع
    sec('8) ما ينفعش تقلب نثرية لبند على إذن أو العكس');
    $sub2 = RepPortal::submit($repId, [
        'permit_id' => 0, 'kind' => 'misc', 'note' => '__v39_تصوير',
        'misc_kind' => 'general',   // v71
        'qty' => 1, 'unit_amount' => 15, 'entry_date' => $today,
    ]);
    try {
        RepInbox::update($sub2, ['kind' => 'receipt']);
        bad('✗ قلب النثرية لإيصال اتقبل!');
    } catch (\Throwable $e) { ok('قلب النثرية لإيصال → اترفض'); }
    is('النوع فضل misc',
        Database::scalar("SELECT kind FROM rep_submissions WHERE id=?", [$sub2]), 'misc');

    // التعديل العادي شغّال
    RepInbox::update($sub2, ['note' => '__v39_تصوير_معدّل', 'unit_amount' => 20]);
    $r2 = Database::row("SELECT * FROM rep_submissions WHERE id=?", [$sub2]);
    is('تعديل البيان والمبلغ شغّال', (float) $r2['amount'], 20.0);
    is('السعر الفعلي للعميل بيفضل NULL للنثرية', $r2['actual_unit'], null);

    // حتى لو الفورم بعت سعر فعلي، الخادم بيتجاهله
    RepInbox::update($sub2, ['actual_unit' => '999']);
    is('السعر الفعلي المبعوت اتجاهل (النثرية مالهاش عميل)',
        Database::scalar("SELECT actual_unit FROM rep_submissions WHERE id=?", [$sub2]), null);

    // ═══════════════════════════════════════════ 9) كشف حساب المندوب
    sec('9) النثرية في كشف حساب المندوب');
    $spending = RepCustody::spending($repId);
    $miscRows = array_filter($spending, fn($x) => ($x['src'] ?? '') === 'misc');
    is('النثريات بتظهر في كشف حساب المندوب', count($miscRows) >= 2, true);
    $noPermit = array_filter($miscRows, fn($x) => $x['permit_id'] !== null);
    is('كل صفوف النثريات بـpermit_id = null', count($noPermit), 0);

    $b = RepCustody::breakdown($repId);
    is('breakdown.spent فيه النثريات', $b['spent'] >= 370, true);
    is('breakdown.balance = deposits − spent − returned',
        $b['balance'], round($b['deposits'] - $b['spent'] - $b['returned'], 2));

    // الكارت لازم يقول نفس رقم الرصيد بالمليم — وإلا الصفحة تعرض رقم والكشف تاني
    $card = null;
    foreach (RepCustody::cards() as $c) { if ((int) $c['id'] === $repId) { $card = $c; break; } }
    if ($card) {
        is('رصيد الكارت = RepCustody::balance() بالمليم', $card['balance'], RepCustody::balance($repId));
        is('الكارت بيعرض إجمالي النثريات', $card['misc'] >= 370, true);
    } else { skip('المندوب مش ظاهر في الكروت'); }

    // ═══════════════════════════════════════════ 10) الخلاصة
    sec('10) الخلاصة — رصيد الخزنة قبل ↔ بعد كل ده');
    $balEnd = Closing::currentBalance();
    echo "  الابتدائي: " . number_format($bal0, 2) . " ج\n";
    echo "  النهائي:   " . number_format($balEnd, 2) . " ج\n";
    is($newRule ? 'الخزنة ما اتأثرتش بالنثريات خالص (كلها من محفظة المندوب)'
                : 'الخزنة نقصت بالمصروف المعتمد فقط (250 + 120)',
       $balEnd, $bal0 - 370 * $hits);

} finally {
    $pdo->rollBack();   // مفيش أي أثر بيتساب
    echo "\n(اترجعت كل بيانات الفحص — مفيش أي أثر في قاعدة البيانات)\n";
    echo "رصيد الخزنة بعد التراجع: " . number_format(Closing::currentBalance(), 2) . " ج\n";
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "نجح: $pass   |   فشل: $fail   |   تخطّي: $skip\n";
exit($fail > 0 ? 1 : 0);
