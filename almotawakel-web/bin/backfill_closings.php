<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * الترحيل بأثر رجعي للإقفال اليومي — يقفل كل الأيام التي بها حركة، بالترتيب من الأقدم،
 * حتى تاريخ نهاية تحدّده أنت (شامل). لا يلمس الأيام بعد هذا التاريخ فتبقى مفتوحة للإدخال.
 *
 * ⚠️ مهم: لا تقفل يومًا ما زلت تُدخل فيه بيانات — بعد الإقفال لن تُقبل الكتابة عليه
 *          إلا بعد فتحه من صفحة «التقفيل اليومي». اختر تاريخ نهاية = آخر يوم اكتملت بياناته.
 *
 * الاستخدام:
 *   php bin/backfill_closings.php 2026-07-09        # يقفل كل الأيام حتى 9 يوليو (شامل)
 *   php bin/backfill_closings.php 2026-07-09 --dry  # عرض ما سيحدث دون تنفيذ
 *
 * آمن لإعادة التشغيل: الأيام المتقفلة بالفعل تُتخطّى.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;
use App\Models\Permit;
use App\Models\RepMiscExpense;

function say(string $s, string $c=''){ $x=['ok'=>"\033[32m",'hi'=>"\033[36m",'y'=>"\033[33m",'err'=>"\033[31m"];
    echo ($x[$c]??'').$s."\033[0m\n"; }

$end = $argv[1] ?? '';
$dry = in_array('--dry', $argv, true);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
    say("الاستخدام: php bin/backfill_closings.php <تاريخ-النهاية YYYY-MM-DD> [--dry]", 'y');
    say("مثال:      php bin/backfill_closings.php 2026-07-09", 'y');
    say("سيقفل كل الأيام التي بها حركة حتى هذا التاريخ (شامل)، بالترتيب من الأقدم.", '');
    exit(1);
}

say("═══════════════════════════════════════════════", 'hi');
say("  ترحيل الإقفال اليومي بأثر رجعي حتى $end" . ($dry ? "  (تجربة فقط)" : ""), 'hi');
say("═══════════════════════════════════════════════", 'hi');

// كل الأيام التي بها حركة مالية مؤثّرة (عهد مضافة / مصروفات / عهد سائقين)، حتى تاريخ النهاية
$expSql = Permit::expenseSql('s');
// v45: الكاش بس — النثرية المدفوعة بالفيزا ما بتحرّكش الخزنة فما بتفتحش يوم تقفيل.
$miscDatesSql = RepMiscExpense::available()
    ? 'UNION SELECT exp_date FROM rep_misc_expenses WHERE ' . RepMiscExpense::cashOnlySql()
    : '';
$dates = Database::all(
    "SELECT d FROM (
        SELECT cust_date d FROM manual_custody
        UNION SELECT s.entry_date FROM permit_stages s WHERE $expSql AND s.is_visa=0
        UNION SELECT exp_date FROM manual_expenses WHERE is_visa=0
        UNION SELECT bill_date FROM bawalis WHERE custody>0
        $miscDatesSql
     ) t WHERE d IS NOT NULL AND d <= ? ORDER BY d",
    [$end]
);
$dates = array_values(array_filter(array_column($dates, 'd')));

if (!$dates) { say("لا توجد أيام بها حركة حتى $end.", 'y'); exit(0); }

$closed = 0; $skipped = 0; $failed = 0;
foreach ($dates as $d) {
    if (Closing::isClosedExact($d)) { say("• $d — متقفل بالفعل، تخطٍّ", 'y'); $skipped++; continue; }
    if ($dry) {
        $v = Closing::dayView($d);
        say("~ $d — سيُقفل على رصيد " . number_format($v['closing'], 2), '');
        continue;
    }
    $r = Closing::closeDay($d, 'ترحيل أولي');
    if ($r['ok']) { say("✓ " . $r['msg'], 'ok'); $closed++; }
    else          { say("✗ $d — " . $r['msg'], 'err'); $failed++; }
}

say("═══════════════════════════════════════════════", 'hi');
if ($dry) {
    say("  تجربة فقط — لم يُحفَظ شيء. أعِد التشغيل بدون --dry للتنفيذ.", 'y');
} else {
    say("  تم: أُقفل $closed يوم — تُخطّي $skipped — فشل $failed", $failed ? 'err' : 'ok');
    $last = Closing::lastClosedDate();
    say("  آخر يوم متقفل الآن: " . ($last ?: '—') . " | الرصيد الحالي: " . number_format(\App\Models\Custody::balance(), 2), 'hi');
}
say("═══════════════════════════════════════════════", 'hi');
