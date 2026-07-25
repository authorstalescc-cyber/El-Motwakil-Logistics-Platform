<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص v42 — «صرف المندوب بيدخل تقفيل يومه».
 *
 * v35 كان بيستثني بنود عهدة المناديب من الخزنة (نسخ نموذج كروت الفيزا). ودي كانت
 * غلطة: كارت الفيزا بنك (فلوس تانية خالص)، لكن عهدة المندوب كاش بيطلع من الدرج —
 * فلا التنزيل ولا الصرف كان بيتخصم، والخزنة تفضل مضخّمة بقيمة اللي في جيوب
 * المناديب، والإيصالات تختفي من تقفيل يومها.
 *
 * قاعدة v42:  التنزيل مالوش أثر · الصرف بيتخصم يوم صرفه · المرتجع مالوش أثر.
 * فعلى مدار الدورة الكاملة الخزنة بتنقص بالظبط اللي اتصرف فعلاً — مفيش تحسيب مزدوج.
 *
 * الفحص ده **بيعمل rollback في الآخر فما بيسيبش أي أثر في القاعدة**.
 *
 *   php bin/verify_v42.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;
use App\Models\DailyReview;
use App\Models\Permit;
use App\Models\RepCustody;

$pass = 0; $fail = 0;
function ok(string $m): void   { global $pass; $pass++; echo "  ✅ $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  ❌ $m\n"; }
function head(string $m): void { echo "\n\033[1m$m\033[0m\n"; }
function chk(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function near(float $a, float $b): bool { return abs($a - $b) < 0.005; }

echo "\n\033[1m═══ فحص v42 — صرف المناديب يدخل التقفيل ═══\033[0m\n";

// ─────────────────────────────────────── 1. بقايا استثناء v35 في كود الخزنة
head('1) مفيش بقايا استثناء v35 في أي حساب خزنة');

chk(!method_exists(RepCustody::class, 'excludeSql'),
    'RepCustody::excludeSql اتشالت (مفيش دالة استثناء ممكن ترجع بالغلط)');

$drawerFiles = [
    'app/Models/Closing.php',
    'app/Models/DailyReview.php',
    'app/Controllers/DailyClosingController.php',
    'app/Controllers/PermitController.php',
];
foreach ($drawerFiles as $f) {
    $src = @file_get_contents(dirname(__DIR__) . '/' . $f);
    if ($src === false) { bad("$f مش موجود"); continue; }
    // التعليقات مسموحة؛ الممنوع هو شرط SQL فعلي بيستثني بنود المناديب
    $hasPredicate = (bool) preg_match('/paid_by_rep_id\s+IS\s+(NOT\s+)?NULL/i', $src)
                 || str_contains($src, 'RepCustody::excludeSql');
    chk(!$hasPredicate, "$f مافيهوش شرط استثناء paid_by_rep_id");
}

// ─────────────────────────────────────── 2. الحارس على اليوم المتقفل
head('2) الاعتماد بيحترم قفل اليوم');

$inbox = @file_get_contents(dirname(__DIR__) . '/app/Models/RepInbox.php');
chk($inbox !== false && str_contains($inbox, 'Closing::guard'),
    'RepInbox بينادي Closing::guard قبل الاعتماد');

// ─────────────────────────────────────── 3. المرتجع مابيزوّدش الخزنة
head('3) مرتجع التسوية مابيرحّلش للخزنة (منع التحسيب المزدوج)');

$rc = @file_get_contents(dirname(__DIR__) . '/app/Models/RepCustody.php');
chk($rc !== false && !str_contains($rc, 'Custody::createTopup'),
    'RepCustody مابيناديش Custody::createTopup (مفيش إضافة للخزنة من التسوية)');

$card = @file_get_contents(dirname(__DIR__) . '/app/Views/rep_custody/card.php');
chk($card !== false && !str_contains($card, 'name="to_custody"'),
    'خانة «أضف المرتجع للخزنة» اتشالت من فورم التسوية');

// ─────────────────────────────────────── 4. الأرقام الحقيقية دلوقتي
head('4) البيانات الحقيقية في القاعدة');

$lastRepDay = Database::scalar(
    "SELECT MAX(entry_date) FROM permit_stages WHERE paid_by_rep_id IS NOT NULL");
if ($lastRepDay === null || $lastRepDay === false) {
    echo "  ℹ️  مفيش بنود مناديب في القاعدة — الفحوص دي اتساب.\n";
} else {
    $expSql = Permit::expenseSql('s');
    $repSum = (float) Database::scalar(
        "SELECT COALESCE(SUM(s.amount),0) FROM permit_stages s
         WHERE $expSql AND s.is_visa=0 AND s.paid_by_rep_id IS NOT NULL AND s.entry_date=?",
        [$lastRepDay]);
    $allCash = (float) Database::scalar(
        "SELECT COALESCE(SUM(s.amount),0) FROM permit_stages s
         WHERE $expSql AND s.is_visa=0 AND s.entry_date=?", [$lastRepDay]);
    $withoutRep = $allCash - $repSum;

    echo "  ℹ️  يوم $lastRepDay: بنود مناديب = " . number_format($repSum, 2)
       . " · كل البنود النقدية = " . number_format($allCash, 2) . "\n";

    $day = Closing::dayView($lastRepDay);
    chk($repSum > 0, 'فيه بنود مناديب في اليوم ده (' . number_format($repSum, 2) . ')');
    chk((float) $day['expenses'] > $withoutRep + 0.005 || near($repSum, 0.0),
        'مصروفات التقفيل بتشمل بنود المناديب (مش بتستثنيها)');

    // v50: بدل «إجمالي السيستم» في المطابقة القديمة، بنقيس إجمالي أقسام المراجعة النقدية.
    if (DailyReview::available()) {
        $view = DailyReview::view($lastRepDay);
        $cash = 0.0;
        foreach (['permits', 'general', 'custody'] as $g) { $cash += (float) $view['groups'][$g]['total']; }
        chk($cash > $withoutRep + 0.005 || near($repSum, 0.0),
            'مراجعة التقفيل بتشوف بنود المناديب في الإجمالي النقدي');
    } else {
        echo "  ℹ️  جدول المراجعة مش موجود — الفحص اتساب.\n";
    }
}

// ─────────────────────────────────────── 5. اختبار حي للدورة الكاملة
head('5) اختبار حي: الدورة الكاملة مافيهاش تحسيب مزدوج');

$pdo = Database::conn();
$pdo->beginTransaction();
try {
    $balBefore = Closing::currentBalance();
    $rep    = Database::row('SELECT id FROM reps ORDER BY id LIMIT 1');
    $permit = Database::row('SELECT id FROM permits ORDER BY id DESC LIMIT 1');

    if (!$rep || !$permit) {
        echo "  ℹ️  محتاج مندوب وإذن على الأقل — الاختبار الحي اتساب.\n";
    } else {
        $repId = (int) $rep['id'];
        $repBefore = RepCustody::balance($repId);
        // يوم مفتوح مضمون: بعد آخر إقفال بيوم
        $lastClosed = Closing::lastClosedDate();
        $day = $lastClosed ? date('Y-m-d', strtotime($lastClosed . ' +1 day')) : date('Y-m-d');

        // (أ) تنزيل عهدة 10,000 → الخزنة ما تتحركش
        Database::exec(
            'INSERT INTO rep_custody_deposits (rep_id, dep_date, amount, note, created_by)
             VALUES (?,?,?,?,?)', [$repId, $day, 10000, 'اختبار v42', 'verify']);
        chk(near(Closing::currentBalance(), $balBefore),
            'تنزيل العهدة مابيخصمش من الخزنة (فضلت ' . number_format($balBefore, 2) . ')');
        chk(near(RepCustody::balance($repId), $repBefore + 10000),
            'وبيزوّد رصيد عهدة المندوب 10,000');

        // (ب) صرف 3,000 من عهدته → الخزنة تنقص 3,000
        Database::exec(
            "INSERT INTO permit_stages
                (permit_id, movement_id, stage_key, direction, amount, qty, actual_amount,
                 entry_date, ref_no, note, status, is_visa, visa_card_id, paid_by_rep_id)
             VALUES (?,NULL,'receipts',NULL,?,1,NULL,?,NULL,'اختبار v42','done',0,NULL,?)",
            [(int) $permit['id'], 3000, $day, $repId]);
        chk(near(Closing::currentBalance(), $balBefore - 3000),
            'صرف المندوب بيخصم 3,000 من الخزنة يوم صرفه');
        chk(near(RepCustody::balance($repId), $repBefore + 7000),
            'وبيتخصم من عهدة المندوب كمان (باقي معاه 7,000)');

        // (ج) التسوية: يرجّع الباقي → الخزنة ما تتحركش (حتى لو اتبعت toCustody=true)
        $beforeSettle = Closing::currentBalance();
        RepCustody::settle($repId, RepCustody::balance($repId), $day, true, 'اختبار v42');
        chk(near(Closing::currentBalance(), $beforeSettle),
            'المرتجع مابيزوّدش الخزنة حتى مع toCustody=true (مفيش تحسيب مزدوج)');
        chk(near(RepCustody::balance($repId), 0.0),
            'وعهدة المندوب اتصفّرت بعد التسوية');

        // (د) صافي الدورة كلها = المصروف الفعلي بالظبط
        chk(near(Closing::currentBalance(), $balBefore - 3000),
            'صافي الدورة الكاملة = 3,000 المصروفة بالظبط (مش 10,000 ولا 7,000)');

        // (هـ) البند بيظهر في تقفيل يومه وفي المطابقة
        $dayView = Closing::dayView($day);
        chk((float) $dayView['expenses'] >= 3000 - 0.005,
            'البند ظاهر في مصروفات تقفيل يومه (' . number_format((float) $dayView['expenses'], 2) . ')');
    }
    $pdo->rollBack();
    ok('اتعمل rollback — القاعدة رجعت زي ما كانت بالظبط');
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    bad('الاختبار الحي رمى استثناء: ' . $e->getMessage());
}

// ─────────────────────────────────────── النتيجة
echo "\n" . str_repeat('─', 52) . "\n";
echo $fail === 0
    ? "\033[32m✅ كل الفحوص نجحت ($pass)\033[0m\n\n"
    : "\033[31m❌ فشل $fail فحص (نجح $pass)\033[0m\n\n";
exit($fail === 0 ? 0 : 1);
