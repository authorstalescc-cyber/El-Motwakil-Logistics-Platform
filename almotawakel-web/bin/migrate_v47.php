<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v47 — صلاحية «متابعة المناديب» لمندوب مشرف.
 *
 * المالك طلب إن محمد فكري يشوف من موبايله شغل باقي المناديب: إدخالاتهم،
 * عهدتهم، صرفوا كام، فاضل كام، وكل حركة اتعملت من العهدة بالتفصيل.
 *
 * الهجرة دي بتضيف `reps.can_watch_reps` (0/1) و**الافتراضي 0 = ممنوع**
 * (تحذير 5: الرفض هو السلوك الافتراضي مش استثناء بنضيفه) — نفس نمط v44
 * بالظبط. المالك بيدّي/بيسحب الصلاحية من كارت المندوب `/rep-custody/{id}`.
 *
 * ⚠️ إضافي بحت: عمود وصفي واحد. **مفيش أي أثر مالي** — لا خزنة ولا عهدة ولا
 *    تقفيل. والشاشة نفسها **قراءة فقط**: المشرف بيتفرّج، والاعتماد والرفض
 *    والتنزيل يفضلوا شغل المكتب لوحده.
 *
 * بتدّي الصلاحية تلقائيًا للمناديب المذكورين في V47_GRANT (بحساب الدخول).
 * idempotent — عادي تشغّلها أكتر من مرة.
 *
 * التشغيل:  php bin/migrate_v47.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;

/** أسماء دخول المناديب اللي المالك طلب يتابعوا باقي المناديب. */
const V47_GRANT = ['fekry'];

echo "v47 — صلاحية متابعة المناديب\n" . str_repeat('=', 60) . "\n";

$balanceBefore = Closing::currentBalance();

$exists = (bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'reps' AND column_name = 'can_watch_reps'"
);

if ($exists) {
    echo "  = reps.can_watch_reps موجود قبل كده\n";
} else {
    Database::exec(
        "ALTER TABLE `reps`
         ADD COLUMN `can_watch_reps` TINYINT(1) NOT NULL DEFAULT 0
         COMMENT 'يتابع إدخالات وعهد باقي المناديب من الموبايل (v47)' AFTER `can_create_permit`"
    );
    echo "  + reps.can_watch_reps (الافتراضي 0 = ممنوع للكل)\n";
}

// المنح بحساب الدخول عشان الاسم بيتكتب بتهجئات مختلفة (تحذير 3).
echo "\nمنح الصلاحية:\n";
foreach (V47_GRANT as $username) {
    $rep = Database::row(
        "SELECT r.id, r.name, r.can_watch_reps
         FROM rep_users ru JOIN reps r ON r.id = ru.rep_id
         WHERE ru.username = ?", [$username]
    );
    if (!$rep) {
        echo "  ~ «$username» — مفيش حساب بالاسم ده، اتخطّى\n";
        continue;
    }
    if ((int) $rep['can_watch_reps'] === 1) {
        echo "  = {$rep['name']} ($username) — عنده الصلاحية أصلاً\n";
        continue;
    }
    Database::exec("UPDATE reps SET can_watch_reps = 1 WHERE id = ?", [(int) $rep['id']]);
    echo "  ✓ {$rep['name']} ($username) — بقى يتابع المناديب\n";
}

// ── تحقّق السلامة: العمود وصفي، فممنوع يكون غيّر أي رقم
$balanceAfter = Closing::currentBalance();
echo "\n" . str_repeat('─', 60) . "\n";
printf("رصيد الخزنة قبل: %s  |  بعد: %s\n", number_format($balanceBefore, 2), number_format($balanceAfter, 2));
if (abs($balanceAfter - $balanceBefore) > 0.005) {
    echo "✗✗ الرصيد اتغيّر — ده مستحيل في هجرة وصفية. راجع فورًا.\n";
    exit(1);
}
echo "✓ الرصيد ما اتغيّرش (الهجرة وصفية بحتة).\n";

$allowed = Database::all(
    "SELECT r.name, COALESCE(ru.username,'—') username
     FROM reps r LEFT JOIN rep_users ru ON ru.rep_id = r.id
     WHERE r.can_watch_reps = 1 ORDER BY r.name"
);
echo "\nالمسموح لهم يتابعوا المناديب دلوقتي (" . count($allowed) . "):\n";
foreach ($allowed as $a) { echo "  • {$a['name']} ({$a['username']})\n"; }
if (!$allowed) { echo "  (مفيش حد — امنح الصلاحية من كارت المندوب /rep-custody/{id})\n"; }

echo "\nتمام. شغّل php bin/verify_v47.php للتأكد.\n";
