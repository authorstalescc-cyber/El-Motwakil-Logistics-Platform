<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v48 — تقوية دخول المناديب (المرحلة 2 من خطة الأمان).
 *
 * المشكلة اللي بتتصلّح (متحقَّق منها في فحص أمني 2026-07-22):
 *   دخول المندوب كان أضعف بكتير من دخول المكتب —
 *     • الرقم السري **4 أرقام** = 10,000 احتمال بس.
 *     • القفل بعد 5 غلطات كان **بيصفّر عدّاد المحاولات**، فما كانش بيتصاعد:
 *       5 محاولات كل 10 دقايق للأبد = 720 محاولة في اليوم → الحساب بيتكسر
 *       في حوالي أسبوعين.
 *     • **مفيش أي تسجيل للمحاولات** → الهجوم ما كانش هيظهر في أي سجل.
 *     • مفيش حد على مستوى الـIP، ومفيش انتهاء بالخمول.
 *
 * الهجرة دي بتضيف عمودين وصفيين على `rep_users`:
 *   • `weak_pin`     — 1 = الرقم السري اتعمل أيام قاعدة الـ4 أرقام. **بيتحط 1
 *                      لكل الحسابات الموجودة** لأن الهاش مستحيل نعرف طوله منه.
 *                      كارت المندوب في المكتب بيعرض تنبيه للحسابات دي.
 *   • `pin_set_at`   — آخر مرة اتغيّر فيها الرقم السري (للمتابعة).
 *
 * ⚠️ **مش بنقفل حساب أي مندوب.** لو منعنا الدخول على الحسابات القديمة كان
 *    الشغل الميداني هيقف فورًا. القرار: نفرض 6 أرقام على أي رقم **جديد**،
 *    ونعلّم القديم بتنبيه واضح للمالك يجدّده، والحماية الفورية بتيجي من
 *    القفل المتصاعد + حد الـIP + التسجيل (كلها في `RepAuth` بلا هجرة).
 *
 * ⚠️ **مفيش أي أثر مالي** — عمودان وصفيان على جدول حسابات الدخول. الهجرة
 *    بتتحقّق إن رصيد الخزنة ما اتغيّرش.
 *
 * idempotent — عادي تشغّلها أكتر من مرة.
 *
 * التشغيل:  php bin/migrate_v48.php [--dry-run]
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;

$dry = in_array('--dry-run', $argv, true);

echo "v48 — تقوية دخول المناديب\n" . str_repeat('=', 60) . "\n";
if ($dry) { echo "(معاينة — مش هيتكتب أي حاجة)\n\n"; }

$balanceBefore = Closing::currentBalance();

$has = static fn(string $col): bool => (bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'rep_users' AND column_name = ?",
    [$col]
);

$tableExists = (bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'rep_users'"
);
if (!$tableExists) {
    echo "  ! جدول rep_users مش موجود — شغّل migrate_v35.php الأول.\n";
    exit(1);
}

$plan = [];
if (!$has('weak_pin')) {
    $plan['weak_pin'] = "ALTER TABLE `rep_users`
        ADD COLUMN `weak_pin` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'الرقم السري اتعمل أيام قاعدة الـ4 أرقام — محتاج تجديد (v48)'";
}
if (!$has('pin_set_at')) {
    $plan['pin_set_at'] = "ALTER TABLE `rep_users`
        ADD COLUMN `pin_set_at` DATETIME NULL
        COMMENT 'آخر مرة اتغيّر فيها الرقم السري (v48)'";
}

$accounts = (int) Database::scalar("SELECT COUNT(*) FROM rep_users");
echo "حسابات دخول المناديب الحالية: $accounts\n\n";

foreach (['weak_pin', 'pin_set_at'] as $col) {
    echo isset($plan[$col]) ? "  + rep_users.$col\n" : "  = rep_users.$col موجود قبل كده\n";
}

if ($dry) {
    $toFlag = $has('weak_pin')
        ? (int) Database::scalar("SELECT COUNT(*) FROM rep_users WHERE weak_pin = 0 AND pin_set_at IS NULL")
        : $accounts;
    echo "\n  هيتعلّم بـ«رقم سري ضعيف»: $toFlag حساب\n";
    echo "\n✓ المعاينة انتهت بلا كتابة.\n";
    exit(0);
}

Database::exec('START TRANSACTION');
try {
    foreach ($plan as $sql) { Database::exec($sql); }

    // كل الحسابات الموجودة اتعملت أيام قاعدة الـ4 أرقام. بنعلّمها مرة واحدة
    // بس: الشرط `pin_set_at IS NULL` بيخلّي إعادة التشغيل ما تعلّمش من جديد
    // رقمًا سريًّا المالك جدّده بالفعل (idempotent حقيقي).
    $flagged = Database::exec(
        "UPDATE rep_users SET weak_pin = 1
         WHERE pin_set_at IS NULL AND weak_pin = 0"
    );

    Database::exec('COMMIT');
    echo "\n  ✓ اتعلّم بـ«رقم سري ضعيف»: {$flagged} حساب\n";
} catch (\Throwable $e) {
    Database::exec('ROLLBACK');
    echo "\n✗ فشلت الهجرة واترجعت بالكامل: " . $e->getMessage() . "\n";
    exit(1);
}

// ── تحقّق السلامة: أعمدة وصفية، فممنوع يكون اتغيّر أي رقم ──────────────
$balanceAfter = Closing::currentBalance();
echo "\n" . str_repeat('─', 60) . "\n";
printf("رصيد الخزنة قبل : %s\n", number_format($balanceBefore, 2));
printf("رصيد الخزنة بعد : %s\n", number_format($balanceAfter, 2));
if (abs($balanceBefore - $balanceAfter) > 0.0001) {
    echo "\n✗ الرصيد اتغيّر — ده غلط، الهجرة دي وصفية بحتة. راجع فورًا.\n";
    exit(1);
}
echo "✓ الرصيد ما اتغيّرش (زي المتوقع — الهجرة وصفية).\n";

$weak = (int) Database::scalar("SELECT COUNT(*) FROM rep_users WHERE weak_pin = 1");
echo "\n" . str_repeat('=', 60) . "\n";
echo "✓ v48 تمّت.\n";
if ($weak > 0) {
    echo "\n⚠️ فيه $weak حساب مندوب برقم سري قديم (4 أرقام).\n";
    echo "   افتح كارت كل مندوب في /rep-custody ودوس «🎲 رقم سري جديد وابعتهوله»\n";
    echo "   عشان يتولّد رقم 6 أرقام ويتبعت له على الواتساب.\n";
}
