<?php
/**
 * ترحيل v19 — لوحة ملاحظات لوحة التحكم:  جدول dashboard_notes
 *
 *   مساحة ملاحظات سريعة على الصفحة الرئيسية (تذكيرات/مهام) — إضافة وحذف.
 *   معزولة تمامًا: لا يقرأها أي حساب أو تقفيل أو عهدة.
 *
 * آمن لإعادة التشغيل (idempotent).
 * التشغيل:  php bin/migrate_v19.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

function say(string $s, string $c=''){ $x=['ok'=>"\033[32m",'hi'=>"\033[36m",'y'=>"\033[33m",'err'=>"\033[31m"];
    echo ($x[$c]??'').$s."\033[0m\n"; }

say("═══════════════════════════════════════════════", 'hi');
say("  ترحيل نظام المتوكل إلى الإصدار 19", 'hi');
say("  (ملاحظات لوحة التحكم — dashboard_notes)", 'hi');
say("═══════════════════════════════════════════════", 'hi');

Database::exec(
    "CREATE TABLE IF NOT EXISTS dashboard_notes (
        id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
        body        VARCHAR(500)  NOT NULL,
        color       VARCHAR(12)   NOT NULL DEFAULT 'gold',
        author      VARCHAR(120)  DEFAULT NULL,
        created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
say("✓ جدول dashboard_notes جاهز.", 'ok');
say("تم بنجاح.", 'ok');
