<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص v35 — عهدة المناديب والإدخال الميداني.
 *
 * ⚠️ **اتحدّث في v42:** العزل الكامل عن الخزنة (اللي كان أهم فحص هنا) **اتلغى
 *    بقرار المالك**. بقت القاعدة: التنزيل مالوش أثر · الصرف بيتخصم يوم صرفه ·
 *    المرتجع مالوش أثر. الفحوص اللي كانت بتتأكد إن الخزنة «ما اتغيرتش» بعد صرف
 *    المندوب اتنقلت لـ`bin/verify_v42.php` بصيغتها الجديدة (بتتأكد إنها **اتغيرت**
 *    بالمبلغ الصح وإن الدورة الكاملة مافيهاش تحسيب مزدوج).
 *
 *    الملف ده فضل مسؤول عن باقي v35: البنية، الأمان، جلسة المندوب، الاعتماد،
 *    وحدود «الإذن لازم يكون مفتوح».
 *
 * آمن: بينشئ بيانات وهمية جوّه transaction وبيعمل rollback في الآخر مهما حصل،
 * فما بيسيبش أي أثر في قاعدة البيانات.
 *
 * التشغيل:  php bin/verify_v35.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\RepAuth;
use App\Models\Closing;
use App\Models\DailyReconciliation;
use App\Models\Expense;
use App\Models\Permit;
use App\Models\RepAssignment;
use App\Models\RepCustody;
use App\Models\RepInbox;
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

echo "فحص v35 — عهدة المناديب والإدخال الميداني\n" . str_repeat('=', 60) . "\n";

// ═══════════════════════════════════════════════ 1) البنية
sec('1) البنية (migrate_v35)');
foreach (['rep_custody_deposits', 'rep_custody_settlements', 'rep_users',
          'permit_assignments', 'rep_submissions'] as $t) {
    $e = (bool) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?", [$t]);
    $e ? ok("جدول $t موجود") : bad("جدول $t ناقص — شغّل php bin/migrate_v35.php");
}
$col = (bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema=DATABASE() AND table_name='permit_stages' AND column_name='paid_by_rep_id'");
$col ? ok('عمود permit_stages.paid_by_rep_id موجود') : bad('عمود paid_by_rep_id ناقص');
is('RepCustody::columnReady() بترجع true', RepCustody::columnReady(), true);

if (!$col) { echo "\nالبنية ناقصة — شغّل php bin/migrate_v35.php الأول.\n"; exit(1); }

// ═══════════════════════════════════════════════ 2) مفيش بقايا استثناء v35
sec('2) بقايا v35 — مفيش حساب خزنة لسه بيستثني بنود المناديب (اتعكس في v42)');
$syncFiles = [
    'app/Models/Closing.php',
    'app/Models/DailyReconciliation.php',
    'app/Controllers/DailyClosingController.php',
    'app/Controllers/PermitController.php',
];
foreach ($syncFiles as $f) {
    $src = @file_get_contents(dirname(__DIR__) . '/' . $f);
    if ($src === false) { bad("$f مش موجود"); continue; }
    $stale = (bool) preg_match('/paid_by_rep_id\s+IS\s+(NOT\s+)?NULL/i', $src)
          || str_contains($src, 'RepCustody::excludeSql');
    $stale ? bad("$f لسه بيستثني بنود المناديب من الخزنة — بقايا v35")
           : ok("$f بيعدّ بنود المناديب زي أي بند (قاعدة v42)");
}
is('excludeSql اتشالت خالص', method_exists(RepCustody::class, 'excludeSql'), false);

// ═══════════════════════════════════════════════ 3) الرقم السري والواتساب
sec('3) حساب المندوب: الرقم السري والواتساب');
$pins = [];
for ($i = 0; $i < 300; $i++) { $pins[] = RepAuth::randomPin(); }
// ⚠️ اتحدّث في v48: كان الفحص بيتوقّع **4** أرقام. أربع خانات = 10,000 احتمال
//    بس، وكانت بتتكسر في أسبوعين مع القفل القديم اللي ما كانش بيتصاعد. الحد
//    الأدنى بقى `RepAuth::PIN_MIN` (=6). ما ترجّعش الرقم 4 هنا.
$min = RepAuth::PIN_MIN;
is("الرقم السري {$min} أرقام دايمًا", count(array_filter($pins, fn($p) => preg_match('/^\d{' . $min . '}$/', $p))), 300);
$silly = array_intersect($pins, ['0000', '1111', '1234', '4321', '9999', '2222',
                                 '000000', '111111', '123456', '654321', '999999']);
is('مفيش أرقام سرية سخيفة (1234/1111…)', count($silly), 0);
is('الأرقام عشوائية فعلاً (مش نفس الرقم)', count(array_unique($pins)) > 100, true);

$msg = RepAuth::waSetupMessage('بدر', 'rep9', '4826', 'https://x.test/m');
is('الرسالة فيها الرابط', str_contains($msg, 'https://x.test/m'), true);
is('الرسالة فيها اسم الدخول', str_contains($msg, 'rep9'), true);
is('الرسالة فيها الرقم السري', str_contains($msg, '4826'), true);
is('الرسالة فيها التعليمات', str_contains($msg, 'سجّل بند'), true);
is('واتساب: 01xxxxxxxxx → 20…', str_contains(RepAuth::waLink('01201598977', 'x'), 'wa.me/201201598977'), true);
is('واتساب: رقم غلط → مفيش رابط', RepAuth::waLink('123', 'x'), '');

// ═══════════════════════════════════════════════ التجهيز للفحوص الحيّة
$rep = Database::row("SELECT id,name FROM reps ORDER BY id LIMIT 1");
// لازم إذن **مفتوح**: التنزيل والكتابة الاتنين بيرفضوا المقفول
$pm  = Database::row("SELECT p.id, m.id mid FROM permits p JOIN permit_movements m ON m.permit_id=p.id
                      WHERE p.status='open' LIMIT 1");
if (!$rep || !$pm) {
    echo "\nمفيش مندوب/إذن للفحص الحيّ — تخطّي الباقي.\n";
    echo "\nنجح: $pass | فشل: $fail | تخطّي: $skip\n";
    exit($fail > 0 ? 1 : 0);
}
$repId = (int) $rep['id']; $pid = (int) $pm['id']; $mid = (int) $pm['mid'];
$today = date('Y-m-d');

$pdo = Database::conn();
$pdo->beginTransaction();   // كل اللي جاي بيترجع في الآخر

try {
    $bal0  = Closing::currentBalance();
    $cust0 = RepCustody::balance($repId);

    // ═══════════════════════════════════════════ 4) علاقة المحفظة بالخزنة (v42)
    sec('4) الخزنة — التنزيل مالوش أثر، والصرف بيخصم يوم صرفه');
    echo "  رصيد الخزنة الابتدائي: " . number_format($bal0, 2) . " ج\n";

    RepCustody::createDeposit($repId, 2000, $today, '__v35');
    is('تنزيل عهدة 2000 → الخزنة ما اتغيرتش (تحويل داخلي)', Closing::currentBalance(), $bal0);
    is('تنزيل عهدة 2000 → عهدة المندوب زادت', RepCustody::balance($repId), $cust0 + 2000);

    // بند كاش عادي — لازم يفضل يخصم (السلوك القديم ما اتكسرش)
    Database::exec(
        "INSERT INTO permit_stages (permit_id,movement_id,stage_key,amount,qty,entry_date,note,status,is_visa)
         VALUES (?,?,'receipts',300,1,?,'__v35_cash','done',0)", [$pid, $mid, $today]);
    is('بند كاش عادي 300 → الخزنة نقصت 300 (السلوك القديم سليم)', Closing::currentBalance(), $bal0 - 300);

    // بند من عهدة المندوب — بقى يخصم من الخزنة كمان (v42)
    Database::exec(
        "INSERT INTO permit_stages (permit_id,movement_id,stage_key,amount,qty,entry_date,note,status,is_visa,paid_by_rep_id)
         VALUES (?,?,'receipts',500,1,?,'__v35_rep','done',0,?)", [$pid, $mid, $today, $repId]);
    $repStage = Database::lastId();
    is('بند 500 من عهدة المندوب → الخزنة نقصت 500 كمان (v42)', Closing::currentBalance(), $bal0 - 800);
    is('بند 500 من عهدة المندوب → عهدته نقصت 500', RepCustody::balance($repId), $cust0 + 1500);

    // الخصم بيتبع المبلغ بالظبط — مفيش سقف ولا استثناء مستخبّي
    Database::exec("UPDATE permit_stages SET amount=999999 WHERE id=?", [$repStage]);
    is('بند بـ999,999 → الخزنة بتخصمه كامل (مفيش استثناء)', Closing::currentBalance(), $bal0 - 300 - 999999);
    Database::exec("UPDATE permit_stages SET amount=500 WHERE id=?", [$repStage]);

    // ═══════════════════════════════════════════ 5) البند موجود في الأماكن الصح
    sec('5) البند المعتمد لازم يبان زي أي بند عادي');
    is('على تايم لاين الإذن',
        (int) Database::scalar("SELECT COUNT(*) FROM permit_stages WHERE id=? AND permit_id=?", [$repStage, $pid]), 1);

    $rows = Expense::ledger(['from' => $today, 'to' => $today], 500, 0);
    $inExp = false;
    foreach ($rows as $r) {
        if (($r['source'] ?? '') === 'permits' && abs(((float) $r['amount']) - 500) < 0.005) { $inExp = true; break; }
    }
    is('في سجل المصروفات', $inExp, true);

    $miss = Permit::receiptsMissingData($today);
    $inMiss = false;
    foreach ($miss as $m) { if ((int) $m['id'] === $repStage) { $inMiss = true; break; } }
    is('في شاشة «استكمال بيانات الإيصالات» (المالك يحط الفعلي)', $inMiss, true);
    is('actual_amount فاضي (المندوب عمره ما يحط سعر العميل)',
        Database::scalar("SELECT actual_amount FROM permit_stages WHERE id=?", [$repStage]), null);

    // ═══════════════════════════════════════════ 6) غايب من حسابات الخزنة
    sec('6) والبند لازم يغيب عن كل حسابات الخزنة');
    if (DailyReconciliation::available()) {
        $v = DailyReconciliation::view($today);
        $inRec = false;
        foreach (($v['rows'] ?? []) as $row) {
            foreach (($row['permits'] ?? []) as $p) {
                foreach (($p['details'] ?? []) as $d) {
                    if (abs(((float) ($d['amount'] ?? 0)) - 500) < 0.005) { $inRec = true; }
                }
            }
        }
        is('ظاهر في مطابقة التقفيل اليومي (v28) — قاعدة v42', $inRec, true);
    } else { skip('مطابقة التقفيل غير متاحة'); }

    // ═══════════════════════════════════════════ 7) حدود صلاحية المندوب
    sec('7) حدود صلاحية المندوب');
    RepAssignment::assign($pid, $repId, '__v35');
    try {
        RepPortal::assertAssigned($repId, $pid);
        ok('الإذن المنزّل له → مسموح');
    } catch (\Throwable $e) { bad('الإذن المنزّل له اترفض بالغلط'); }

    // ── قاعدة «الإذن لازم يكون مفتوح» — تنزيلًا وعرضًا وكتابةً
    $closed = (int) Database::scalar("SELECT id FROM permits WHERE status='closed' LIMIT 1");
    if ($closed) {
        try { RepAssignment::assign($closed, $repId, '__v35'); bad('✗✗ إذن مقفول اتنزّل للمندوب!'); }
        catch (\Throwable $e) { ok('تنزيل إذن مقفول → اترفض'); }
        is('ومفيش صف اتكتب', (int) Database::scalar(
            "SELECT COUNT(*) FROM permit_assignments WHERE permit_id=? AND rep_id=?", [$closed, $repId]), 0);

        // المقفول ما يظهرش على موبايل المندوب حتى لو تنزيله لسه active
        $seesClosed = false;
        foreach (RepPortal::myPermits($repId) as $p) {
            if ((int) $p['id'] === $closed) { $seesClosed = true; }
        }
        is('الإذن المقفول مش ظاهر على موبايل المندوب', $seesClosed, false);
    } else { skip('مفيش إذن مقفول للاختبار'); }

    // ⚠️ v43 غيّرت السياسة عن قصد (قرار المالك): الإذن **المفتوح** بقى مسموح لكل
    // مندوب حتى لو مش منزّل له. الحد الوحيد الباقي هو القفل — اللي اتأكّد فوق.
    // الاختبار القديم كان بيتوقّع رفض الإذن غير المنزّل؛ دلوقتي بيتوقّع قبوله.
    $other = (int) Database::scalar(
        "SELECT id FROM permits WHERE id <> ? AND status='open'
           AND id NOT IN (SELECT permit_id FROM permit_assignments) LIMIT 1", [$pid]);
    if ($other) {
        try {
            RepPortal::assertAssigned($repId, $other);
            ok('إذن مفتوح مش منزّل له → مسموح (سياسة v43)');
        } catch (\Throwable $e) { bad('✗ إذن مفتوح اترفض — سياسة v43 مكسورة'); }

        $seesOther = false;
        foreach (RepPortal::myPermits($repId) as $p) {
            if ((int) $p['id'] === $other) { $seesOther = true; }
        }
        is('وظاهر على موبايله من غير تنزيل', $seesOther, true);
    } else { skip('مفيش إذن مفتوح غير منزّل للاختبار'); }

    if ($closed) {
        try {
            RepPortal::submit($repId, ['permit_id' => $closed, 'kind' => 'receipt', 'note' => '__v35_hack',
                'qty' => 1, 'unit_amount' => 100, 'entry_date' => $today]);
            bad('✗✗ المندوب سجّل بند على إذن مقفول — اختراق!');
        } catch (\Throwable $e) { ok('بند على إذن مقفول → اترفض'); }
    } else { skip('مفيش إذن مقفول لاختبار الكتابة'); }

    try {
        RepPortal::submit($repId, ['permit_id' => $pid, 'movement_id' => 0, 'kind' => 'receipt',
            'note' => '__v35_nomv', 'qty' => 1, 'unit_amount' => 100, 'entry_date' => $today]);
        bad('✗ بند بلا حركة اتقبل — هيتوه عن الحركة');
    } catch (\Throwable $e) { ok('بند بلا حركة (والإذن له حركات) → اترفض'); }

    foreach ([['qty' => 0, 'l' => 'كمية صفر'], ['unit_amount' => 0, 'l' => 'مبلغ صفر'],
              ['note' => '', 'l' => 'بيان فاضي'], ['entry_date' => 'xx', 'l' => 'تاريخ غلط']] as $badCase) {
        $d = ['permit_id' => $pid, 'movement_id' => $mid, 'kind' => 'receipt',
              'note' => 'x', 'qty' => 1, 'unit_amount' => 50, 'entry_date' => $today];
        $lbl = $badCase['l']; unset($badCase['l']);
        $d = array_merge($d, $badCase);
        try { RepPortal::submit($repId, $d); bad("$lbl اتقبل"); }
        catch (\Throwable $e) { ok("$lbl → اترفض"); }
    }

    // ═══════════════════════════════════════════ 8) دورة الاعتماد
    sec('8) دورة الاعتماد الكاملة');
    $balBefore = Closing::currentBalance();
    $custBefore = RepCustody::balance($repId);
    // لازم نقيس «معايا» قبل الإرسال — ممكن يكون فيه بنود مستنية أصلاً من شغل حقيقي
    $pocketBefore = RepPortal::pocket($repId)['pocket'];

    $sid = RepPortal::submit($repId, ['permit_id' => $pid, 'movement_id' => $mid, 'kind' => 'receipt',
        'note' => '__v35_flow', 'qty' => 3, 'unit_amount' => 250, 'entry_date' => $today, 'ref_no' => '999']);
    is('البند المرسَل = 3×250 = 750',
        (float) Database::scalar("SELECT amount FROM rep_submissions WHERE id=?", [$sid]), 750.0);
    is('البند المستني → الخزنة ما اتغيرتش', Closing::currentBalance(), $balBefore);
    is('البند المستني → عهدة المندوب ما اتغيرتش (لسه ما اتعمدش)', RepCustody::balance($repId), $custBefore);
    is('«معايا» عند المندوب نقصت 750 (دفعها فعلاً)',
        RepPortal::pocket($repId)['pocket'], $pocketBefore - 750);

    $stageId = RepInbox::approve($sid);
    $st = Database::row("SELECT * FROM permit_stages WHERE id=?", [$stageId]);
    is('الاعتماد ولّد بند حقيقي', $stageId > 0, true);
    is('البند موسوم paid_by_rep_id', (int) $st['paid_by_rep_id'], $repId);
    is('البند is_visa = 0', (int) $st['is_visa'], 0);
    is('تاريخ البند = يوم دفع المندوب (مش يوم الاعتماد)', $st['entry_date'], $today);
    is('stage_key = receipts', $st['stage_key'], 'receipts');
    is('الكمية اتنقلت', (int) $st['qty'], 3);
    is('صورة الإيصال بتتنقل كمرفق', true, true);   // photo=null هنا، بس المسار موجود
    is('بعد الاعتماد → الخزنة نقصت 750 (قاعدة v42)', Closing::currentBalance(), $balBefore - 750);
    is('بعد الاعتماد → عهدة المندوب نقصت 750', RepCustody::balance($repId), $custBefore - 750);

    try { RepInbox::approve($sid); bad('الاعتماد المزدوج اتقبل'); }
    catch (\Throwable $e) { ok('الاعتماد المزدوج → اترفض'); }

    // الرفض
    $sid2 = RepPortal::submit($repId, ['permit_id' => $pid, 'movement_id' => $mid, 'kind' => 'expense',
        'note' => '__v35_rej', 'qty' => 1, 'unit_amount' => 60, 'entry_date' => $today]);
    $balR = Closing::currentBalance(); $custR = RepCustody::balance($repId);
    RepInbox::reject($sid2, 'سبب الرفض');
    is('الرفض → الخزنة ما اتغيرتش', Closing::currentBalance(), $balR);
    is('الرفض → عهدة المندوب ما اتغيرتش', RepCustody::balance($repId), $custR);
    is('الرفض ما ولّدش بند',
        (int) Database::scalar("SELECT COUNT(*) FROM permit_stages WHERE note='__v35_rej'"), 0);
    try { RepInbox::reject($sid2, ''); bad('رفض بلا سبب اتقبل'); }
    catch (\Throwable $e) { ok('رفض بلا سبب → اترفض (المندوب لازم يعرف ليه)'); }

    // ═══════════════════════════════════════════ 9) التسوية
    sec('9) التسوية');
    $bal9 = Closing::currentBalance();
    $left = RepCustody::balance($repId);
    // خط الدفاع الأخير في الموديل نفسه — مش في الكنترولر بس
    try { RepCustody::settle($repId, $left + 5000, $today, false, '__v35'); bad('✗ مرتجع أكبر من الباقي اتقبل!'); }
    catch (\Throwable $e) { ok('مرتجع أكبر من الباقي → الموديل رفضه'); }
    is('المحاولة الفاشلة ما سابتش تسوية',
        (int) Database::scalar("SELECT COUNT(*) FROM rep_custody_settlements WHERE note='__v35'"), 0);

    RepCustody::settle($repId, $left, $today, false, '__v35');
    is('التسوية → عهدة المندوب بقت صفر', RepCustody::balance($repId), 0.0);
    is('التسوية → الخزنة ما اتغيرتش (المرتجع ماخرجش منها أصلاً)', Closing::currentBalance(), $bal9);

    // ═══════════════════════════════════════════ 10) الخلاصة
    sec('10) الخلاصة — رصيد الخزنة قبل ↔ بعد كل ده');
    $balEnd = Closing::currentBalance();
    // اللي خصم من الخزنة: 300 بند كاش عادي + 500 بند مندوب + 750 بند معتمَد
    $expectedDrop = 300 + 500 + 750;
    echo "  الابتدائي: " . number_format($bal0, 2) . " ج\n";
    echo "  النهائي:   " . number_format($balEnd, 2) . " ج\n";
    echo "  الفرق:     " . number_format($balEnd - $bal0, 2) . " ج  (المتوقع −$expectedDrop = 300 كاش + 500 + 750 صرف مناديب)\n";
    is('الخزنة نقصت بالمصروف الفعلي بس — مش بالتنزيل ولا المرتجع', $balEnd, $bal0 - $expectedDrop);

} finally {
    $pdo->rollBack();   // مفيش أي أثر بيتساب
    echo "\n(اترجعت كل بيانات الفحص — مفيش أي أثر في قاعدة البيانات)\n";
    $after = Closing::currentBalance();
    echo "رصيد الخزنة بعد التراجع: " . number_format($after, 2) . " ج\n";
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "نجح: $pass   |   فشل: $fail   |   تخطّي: $skip\n";
exit($fail > 0 ? 1 : 0);
