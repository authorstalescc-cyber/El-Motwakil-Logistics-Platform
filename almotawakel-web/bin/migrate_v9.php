<?php
/**
 * ترحيل v9 — ثلاثة تحسينات على الأذونات والتايم لاين:
 *   1) سعر الكشف والضمان صار لكل إذن على حِدة (مش ثابت في الإعدادات):
 *        permits.inspection_rate / permits.guarantee_rate  (NULL = غير مُدخَل)
 *      • الأذونات القديمة تُملأ بالسعر الحالي من الإعدادات (20 افتراضياً) حفاظاً على أرقامها.
 *      • الأذونات الجديدة تبدأ فارغة — يُدخِل المستخدم السعر في الإذن نفسه.
 *   2) «السعر الفعلي» (اللي هنحاسب بيه العميل) على بنود التايم لاين (ماعدا البوالص):
 *        permit_stages.actual_amount   (NULL = لم يُدخَل)
 *   3) توحيد «بنود مخصّصة» مع «المصاريف الإضافية» في قسم واحد (المفتاح الباقي = custom):
 *        كل بنود stage_key='extra' تتحوّل إلى stage_key='custom' باتجاه expense.
 *
 * آمن لإعادة التشغيل (idempotent).
 * التشغيل:  php bin/migrate_v9.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\App;

function say(string $s, string $c=''){ $x=['ok'=>"\033[32m",'hi'=>"\033[36m",'y'=>"\033[33m",'err'=>"\033[31m"];
    echo ($x[$c]??'').$s."\033[0m\n"; }

function colExists(string $table, string $col): bool
{
    return (bool) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
        [$table, $col]
    );
}

function addCol(string $table, string $col, string $definition): void
{
    if (colExists($table, $col)) {
        say("• $table.$col موجود مسبقاً — تخطٍّ", 'y');
        return;
    }
    Database::exec("ALTER TABLE `$table` ADD COLUMN `$col` $definition");
    say("✓ $table.$col أُضيف", 'ok');
}

say("═══════════════════════════════════════════════", 'hi');
say("  ترحيل نظام المتوكل إلى الإصدار 9", 'hi');
say("  (سعر الكشف/الضمان لكل إذن + السعر الفعلي + توحيد البنود)", 'hi');
say("═══════════════════════════════════════════════", 'hi');

// (1) سعر الكشف والضمان لكل إذن
$newRateCols = !colExists('permits', 'inspection_rate');
addCol('permits', 'inspection_rate', "DECIMAL(10,2) NULL AFTER `containers`");
addCol('permits', 'guarantee_rate',  "DECIMAL(10,2) NULL AFTER `inspection_rate`");

// backfill: الأذونات القديمة تأخذ السعر الحالي من الإعدادات (مرّة واحدة، فقط للأعمدة الجديدة)
if ($newRateCols) {
    $insp = (float) App::setting('inspection_rate', '20');
    $guar = (float) App::setting('guarantee_rate', '20');
    $n = (int) Database::scalar("SELECT COUNT(*) FROM permits");
    Database::exec("UPDATE permits SET inspection_rate = ?, guarantee_rate = ? WHERE inspection_rate IS NULL", [$insp, $guar]);
    say("✓ نُقِل السعر الحالي (كشف=$insp / ضمان=$guar) إلى $n إذن قديم", 'ok');
} else {
    say("• أعمدة السعر موجودة مسبقاً — تخطّي النقل", 'y');
}

// (2) السعر الفعلي على بنود التايم لاين
addCol('permit_stages', 'actual_amount', "DECIMAL(14,2) NULL AFTER `amount`");

// (3) توحيد «المصاريف الإضافية» (extra) داخل «بنود مخصّصة» (custom) باتجاه مصروف
$extraCount = (int) Database::scalar("SELECT COUNT(*) FROM permit_stages WHERE stage_key = 'extra'");
if ($extraCount > 0) {
    Database::exec(
        "UPDATE permit_stages
            SET stage_key = 'custom',
                direction = COALESCE(NULLIF(direction,''), 'expense')
          WHERE stage_key = 'extra'"
    );
    say("✓ حُوِّل $extraCount بند من «المصاريف الإضافية» إلى القسم الموحّد (custom / مصروف)", 'ok');
} else {
    say("• لا بنود «extra» للتحويل — تخطٍّ", 'y');
}

say("═══════════════════════════════════════════════", 'hi');
say("  اكتمل ترحيل v9 ✓", 'ok');
say("═══════════════════════════════════════════════", 'hi');
