<?php
/**
 * ترحيل v6 — تحسينات الإذن والمصروفات:
 *   1) أعمدة بيانات الشحن على الإذن:   permits.shipping_co / agency / port
 *   2) خيار «Visa» على بنود المصروفات:  permit_stages.is_visa
 *                                        manual_expenses.is_visa
 *                                        movement_items.is_visa
 *   3) سيد قوائم منسدلة جديدة في lookups: shipping_co / agency / port
 *
 * آمن لإعادة التشغيل (idempotent).
 * التشغيل:  php bin/migrate_v6.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

function say(string $s, string $c=''){ $x=['ok'=>"\033[32m",'hi'=>"\033[36m",'y'=>"\033[33m",'err'=>"\033[31m"];
    echo ($x[$c]??'').$s."\033[0m\n"; }

/** هل يوجد عمود في جدول؟ */
function colExists(string $table, string $col): bool
{
    return (bool) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
        [$table, $col]
    );
}

/** أضف عموداً إن لم يكن موجوداً */
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
say("  ترحيل نظام المتوكل إلى الإصدار 6", 'hi');
say("  (بيانات الشحن على الإذن + خيار Visa للمصروفات)", 'hi');
say("═══════════════════════════════════════════════", 'hi');

// (1) بيانات الشحن على الإذن
addCol('permits', 'shipping_co', "VARCHAR(180) NULL AFTER `destination`");
addCol('permits', 'agency',      "VARCHAR(180) NULL AFTER `shipping_co`");
addCol('permits', 'port',        "VARCHAR(180) NULL AFTER `agency`");

// (2) خيار Visa على بنود المصروفات
addCol('permit_stages',   'is_visa', "TINYINT(1) NOT NULL DEFAULT 0");
addCol('manual_expenses', 'is_visa', "TINYINT(1) NOT NULL DEFAULT 0");
addCol('movement_items',  'is_visa', "TINYINT(1) NOT NULL DEFAULT 0");

// (3) سيد قوائم الشحن الجديدة
$seed = [
    'port' => ['ميناء الإسكندرية','ميناء الدخيلة','ميناء دمياط','ميناء بورسعيد',
               'ميناء غرب بورسعيد','ميناء شرق بورسعيد','ميناء السخنة','ميناء الأدبية'],
    'shipping_co' => [],   // يضيفها المستخدم حسب تعامله
    'agency'      => [],
];
$total = 0;
foreach ($seed as $type => $vals) {
    foreach ($vals as $v) {
        Database::exec("INSERT IGNORE INTO lookups (list_type, value) VALUES (?, ?)", [$type, $v]);
    }
    $n = (int) Database::scalar("SELECT COUNT(*) FROM lookups WHERE list_type = ?", [$type]);
    say("✓ قائمة $type: $n عنصر", 'ok');
    $total += $n;
}

say("═══════════════════════════════════════════════", 'hi');
say("  اكتمل ترحيل v6 ✓", 'ok');
say("═══════════════════════════════════════════════", 'hi');
