<?php
/**
 * ترحيل v2 — تحويل النظام إلى نموذج «البوليصة = دَين»:
 *   1) إضافة أعمدة البوالص الجديدة (نوع/شهادة/إذن/دين/مدفوع/حالة) + الجداول الجديدة
 *   2) تحويل «دفعة» القديمة إلى سجل دفعة سداد
 *   3) تحويل مصروفات كل بوليصة إلى «حركة» ببنودها
 *   4) إعادة حساب المدفوع/المتبقي/الحالة
 *
 * آمن لإعادة التشغيل (idempotent).  التشغيل:  php bin/migrate_v2.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Database;
use App\Calc;
use App\Models\Bill;

function say(string $s, string $c=''){ $x=['ok'=>"\033[32m",'hi'=>"\033[36m",'y'=>"\033[33m",'err'=>"\033[31m"];
    echo ($x[$c]??'').$s."\033[0m\n"; }

$pdo = Database::conn();
say("═══════════════════════════════════════════════", 'hi');
say("  ترحيل نظام المتوكل إلى الإصدار 2 (البوليصة = دَين)", 'hi');
say("═══════════════════════════════════════════════", 'hi');

// (1أ) إنشاء الجداول الجديدة + بذور الفئات (من schema.sql — CREATE IF NOT EXISTS آمنة)
$schema = file_get_contents(App::basePath('database/schema.sql'));
$lines = array_filter(explode("\n", $schema), fn($l) => !preg_match('/^\s*--/', $l));
$stmts = array_filter(array_map('trim', explode(";", implode("\n", $lines))));
foreach ($stmts as $st) {
    // نفّذ فقط جُمَل الجداول الجديدة والبذور (تجاهل الجداول الموجودة لتفادي الأخطاء)
    if (preg_match('/CREATE TABLE IF NOT EXISTS (bill_payments|movements|movement_items|driver_custody)/', $st)
        || (str_starts_with($st, 'INSERT IGNORE INTO lookups') && str_contains($st, 'mv_'))) {
        try { $pdo->exec($st); } catch (Throwable $e) { /* موجود */ }
    }
}
say("✓ الجداول الجديدة: bill_payments, movements, movement_items, driver_custody", 'ok');

// (1ب) إضافة أعمدة البوالص الجديدة إن لم تكن موجودة
function colExists(string $table, string $col): bool {
    return (bool) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
        [$table, $col]
    );
}
$newCols = [
    'bill_type'  => "VARCHAR(10) NOT NULL DEFAULT 'sader' AFTER id",
    'cert_number'=> "VARCHAR(120) NULL AFTER bill_number",
    'permit_no'  => "VARCHAR(120) NULL AFTER cert_number",
    'debt'       => "DECIMAL(14,2) NOT NULL DEFAULT 0",
    'paid'       => "DECIMAL(14,2) NOT NULL DEFAULT 0",
    'pay_status' => "VARCHAR(12) NOT NULL DEFAULT 'unpaid'",
];
$added = [];
foreach ($newCols as $col => $def) {
    if (!colExists('bawalis', $col)) {
        $pdo->exec("ALTER TABLE bawalis ADD COLUMN `$col` $def");
        $added[] = $col;
    }
}
say($added ? ("✓ أُضيفت أعمدة: " . implode(', ', $added)) : "• أعمدة البوالص موجودة مسبقاً", $added?'ok':'y');

// (2-4) ترحيل البيانات
$expMap = [
    'driver_share'=>'دور سواق','assistant_share'=>'دور تبّاع','overnight_expense'=>'بياتة',
    'expense'=>'مصروف','cards'=>'كرتات','tahia_masr'=>'تحيا مصر','thabtan'=>'ثبتان',
    'z_kawtsh'=>'ظ كاوتش','permits'=>'تصاريح','kawtsh'=>'كاوتش','other_expense'=>'م أخرى','gas'=>'جاز',
];
$hasExpenseCols = colExists('bawalis', 'gas');   // أعمدة النظام القديم

$bills = Database::all("SELECT * FROM bawalis");
$pdo->beginTransaction();
$nPay = 0; $nMv = 0;
foreach ($bills as $b) {
    $id = (int)$b['id'];

    // (2) الدين
    $debt = Calc::bawala($b)['debt'];
    Database::exec("UPDATE bawalis SET debt = ? WHERE id = ?", [$debt, $id]);

    // (3) دفعة قديمة -> سجل سداد (مرة واحدة)
    $oldPay = (float)($b['payment'] ?? 0);
    if ($oldPay != 0) {
        $has = (int)Database::scalar("SELECT COUNT(*) FROM bill_payments WHERE bill_id = ?", [$id]);
        if ($has === 0) {
            Database::exec(
                "INSERT INTO bill_payments (bill_id, pay_date, amount, note) VALUES (?,?,?,?)",
                [$id, $b['bill_date'], $oldPay, 'دفعة مُرحّلة من النظام القديم']
            );
            $nPay++;
        }
    }

    // (4) مصروفات -> حركة ببنودها (مرة واحدة)
    if ($hasExpenseCols) {
        $totalExp = 0.0;
        foreach ($expMap as $col => $_) { $totalExp += (float)($b[$col] ?? 0); }
        if ($totalExp != 0) {
            $has = (int)Database::scalar("SELECT COUNT(*) FROM movements WHERE bill_id = ?", [$id]);
            if ($has === 0) {
                Database::exec(
                    "INSERT INTO movements (movement_date, bill_id, driver, car_owner, total_expense) VALUES (?,?,?,?,?)",
                    [$b['bill_date'], $id, $b['driver'] ?? null, $b['car_owner'] ?? null, round($totalExp,2)]
                );
                $mvId = Database::lastId();
                foreach ($expMap as $col => $label) {
                    $amt = (float)($b[$col] ?? 0);
                    if ($amt != 0) {
                        Database::exec(
                            "INSERT INTO movement_items (movement_id, item_type, category, amount) VALUES (?,?,?,?)",
                            [$mvId, 'expense', $label, $amt]
                        );
                    }
                }
                $nMv++;
            }
        }
    }
}
$pdo->commit();
say("✓ دفعات سداد مُرحّلة: $nPay", 'ok');
say("✓ حركات أُنشئت من المصروفات: $nMv", 'ok');

// إعادة حساب المدفوع/المتبقي/الحالة لكل البوالص
foreach ($bills as $b) { Bill::recalc((int)$b['id']); }
say("✓ أُعيد حساب حالة السداد لكل البوالص (" . count($bills) . ")", 'ok');

say("═══════════════════════════════════════════════", 'hi');
say("  اكتمل الترحيل ✓", 'ok');
say("═══════════════════════════════════════════════", 'hi');
