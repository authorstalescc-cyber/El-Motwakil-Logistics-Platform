<?php
/**
 * ترحيل v5 — وحدة المصروفات:
 *   1) إنشاء جدول manual_expenses (المصاريف اليدوية)
 *   2) سيد فئات المصروفات اليدوية في lookups (list_type='expense_cat')
 *
 * لا تُنشئ أي جداول لباقي المصروفات — فوحدة المصروفات تسحبها من جداولها
 * الأصلية (permit_stages / movement_items / takhlis / shamil) وقت العرض.
 *
 * آمن لإعادة التشغيل (idempotent).
 * التشغيل:  php bin/migrate_v5.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

function say(string $s, string $c=''){ $x=['ok'=>"\033[32m",'hi'=>"\033[36m",'y'=>"\033[33m",'err'=>"\033[31m"];
    echo ($x[$c]??'').$s."\033[0m\n"; }

$pdo = Database::conn();
say("═══════════════════════════════════════════════", 'hi');
say("  ترحيل نظام المتوكل إلى الإصدار 5 (وحدة المصروفات)", 'hi');
say("═══════════════════════════════════════════════", 'hi');

// (1) جدول المصروفات اليدوية
$pdo->exec("CREATE TABLE IF NOT EXISTS manual_expenses (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    exp_date    DATE          NULL,
    category    VARCHAR(150)  NULL,
    beneficiary VARCHAR(180)  NULL,
    amount      DECIMAL(14,2) NOT NULL DEFAULT 0,
    note        VARCHAR(255)  NULL,
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_date (exp_date),
    KEY idx_cat (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
say("✓ جدول: manual_expenses", 'ok');

// (2) سيد فئات المصروفات اليدوية
$cats = ['إيجار','مرتبات','كهرباء','مياه','تليفون وإنترنت','صيانة','بنزين وسولار',
         'مصاريف مكتب','ضيافة','رسوم حكومية','نظافة','أخرى'];
foreach ($cats as $c) {
    Database::exec("INSERT IGNORE INTO lookups (list_type, value) VALUES ('expense_cat', ?)", [$c]);
}
$n = (int) Database::scalar("SELECT COUNT(*) FROM lookups WHERE list_type='expense_cat'");
say("✓ فئات المصروفات اليدوية: $n فئة", 'ok');

say("═══════════════════════════════════════════════", 'hi');
say("  اكتمل ترحيل v5 ✓", 'ok');
say("═══════════════════════════════════════════════", 'hi');
