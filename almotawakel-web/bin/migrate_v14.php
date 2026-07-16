<?php
/**
 * ترحيل v14 — توحيد بنود الإيصالات المنفصلة في بند «إيصالات» واحد متعدّد.
 *
 *   المشكلة: التايم لاين كان فيه 5 مراحل إيصالات منفصلة، كل واحدة قيمة واحدة:
 *     takhsis (التخصيص) · hemola (الحمولة) · fees (الرسوم المحصلة) · ta3teeq (تعتيق) · hay2a (هيئة)
 *   ده كان بيبعثر الإيصالات ويصعّب حوكمتها عند إصدار فاتورة العميل.
 *
 *   الحل: كل بنود المراحل الخمس تتحوّل إلى المرحلة الجديدة stage_key='receipts' (متعدّدة، مصروف)،
 *   مع الحفاظ الكامل على كل البيانات الفعلية (المبلغ، السعر الفعلي، التاريخ، المرجع، المرفق، الفيزا، الحالة).
 *   واسم كل إيصال يُحفَظ في «البيان» (note): لو البيان فاضي يتحطّ فيه اسم الإيصال القديم
 *   (تخصيص/حمولة/الرسوم المحصلة/تعتيق/هيئة) عشان مانضيّعش هُويّته.
 *
 *   الرسوم الأولية (primary_fees = الكشف + الضمان) تفضل مستقلة كما هي (محسوبة تلقائياً).
 *
 *   ملاحظة: صفوف «لا يوجد» (status='none') الخاصة بالمراحل القديمة كانت مجرد علامات
 *   «لا يوجد بند هنا» — تُحذَف لأنها بلا معنى داخل بند إيصالات حر متعدّد.
 *
 * آمن لإعادة التشغيل (idempotent): بعد أول تشغيل لا تبقى صفوف بالمفاتيح القديمة، فإعادته لا تفعل شيئاً.
 * التشغيل:  php bin/migrate_v14.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

function say(string $s, string $c=''){ $x=['ok'=>"\033[32m",'hi'=>"\033[36m",'y'=>"\033[33m",'err'=>"\033[31m"];
    echo ($x[$c]??'').$s."\033[0m\n"; }

say("═══════════════════════════════════════════════", 'hi');
say("  ترحيل نظام المتوكل إلى الإصدار 14", 'hi');
say("  (توحيد الإيصالات المنفصلة في بند «إيصالات» واحد)", 'hi');
say("═══════════════════════════════════════════════", 'hi');

// أسماء الإيصالات القديمة (تُستخدم كبيان افتراضي لو البيان فاضي)
$labels = [
    'takhsis' => 'تخصيص',
    'hemola'  => 'الحمولة',
    'fees'    => 'الرسوم المحصلة',
    'ta3teeq' => 'تعتيق',
    'hay2a'   => 'هيئة',
];
$oldKeys = array_keys($labels);
$in = "'" . implode("','", $oldKeys) . "'";

// عدّاد قبل الترحيل
$before = (int) Database::scalar("SELECT COUNT(*) FROM permit_stages WHERE stage_key IN ($in)");
if ($before === 0) {
    say("• لا بنود بالمفاتيح القديمة — التوحيد تمّ مسبقاً أو لا بيانات. تخطٍّ.", 'y');
    say("  اكتمل ترحيل v14 ✓", 'ok');
    return;
}
say("• بنود إيصالات قديمة موجودة: $before", 'y');

// (1) احذف صفوف «لا يوجد» (علامات فارغة) للمراحل القديمة — بلا معنى في بند متعدّد حر
$noneCount = (int) Database::scalar("SELECT COUNT(*) FROM permit_stages WHERE stage_key IN ($in) AND status = 'none'");
if ($noneCount > 0) {
    Database::exec("DELETE FROM permit_stages WHERE stage_key IN ($in) AND status = 'none'");
    say("✓ حُذِفت $noneCount علامة «لا يوجد» قديمة (بلا بيانات فعلية)", 'ok');
}

// (2) املأ «البيان» الفارغ باسم الإيصال القديم قبل الدمج (حفاظاً على الهُويّة)
$filled = 0;
foreach ($labels as $key => $label) {
    $n = (int) Database::scalar(
        "SELECT COUNT(*) FROM permit_stages WHERE stage_key = ? AND (note IS NULL OR TRIM(note) = '')",
        [$key]
    );
    if ($n > 0) {
        Database::exec(
            "UPDATE permit_stages SET note = ? WHERE stage_key = ? AND (note IS NULL OR TRIM(note) = '')",
            [$label, $key]
        );
        $filled += $n;
    }
}
if ($filled > 0) { say("✓ مُلِئ «البيان» باسم الإيصال القديم في $filled بند كان بلا بيان", 'ok'); }

// (3) حوّل كل البنود المتبقية إلى المرحلة الموحّدة receipts باتجاه مصروف
$moved = (int) Database::scalar("SELECT COUNT(*) FROM permit_stages WHERE stage_key IN ($in)");
Database::exec(
    "UPDATE permit_stages
        SET stage_key = 'receipts',
            direction = 'expense'
      WHERE stage_key IN ($in)"
);
say("✓ حُوِّل $moved بند إيصال إلى المرحلة الموحّدة «إيصالات» (receipts / مصروف)", 'ok');

// تحقّق نهائي
$left = (int) Database::scalar("SELECT COUNT(*) FROM permit_stages WHERE stage_key IN ($in)");
$now  = (int) Database::scalar("SELECT COUNT(*) FROM permit_stages WHERE stage_key = 'receipts'");
say(($left === 0 ? "✓ لم تبقَ أي بنود بالمفاتيح القديمة" : "⚠ لا تزال $left بنود بالمفاتيح القديمة — راجعها"),
    $left === 0 ? 'ok' : 'y');
say("• إجمالي بنود «إيصالات» الآن: $now", 'y');

say("═══════════════════════════════════════════════", 'hi');
say("  اكتمل ترحيل v14 ✓", 'ok');
say("═══════════════════════════════════════════════", 'hi');
