<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * ترحيل v11 — العهد اليدوية (عهدة إضافية):
 *   إنشاء جدول manual_custody لتسجيل عهدة إضافية غير مرتبطة ببوليصة،
 *   تدخل «عهد اليوم» في التقفيل اليومي وتتصرّف تمامًا مثل عهدة البوالص
 *   (إيراد يلغي نفسه — لا يؤثّر على الرصيد المرحّل).
 *
 * آمن لإعادة التشغيل (idempotent).
 * التشغيل:  php bin/migrate_v11.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

function say(string $s, string $c=''){ $x=['ok'=>"\033[32m",'hi'=>"\033[36m",'y'=>"\033[33m",'err'=>"\033[31m"];
    echo ($x[$c]??'').$s."\033[0m\n"; }

$pdo = Database::conn();
say("═══════════════════════════════════════════════", 'hi');
say("  ترحيل نظام المتوكل إلى الإصدار 11 (العهد اليدوية)", 'hi');
say("═══════════════════════════════════════════════", 'hi');

$pdo->exec("CREATE TABLE IF NOT EXISTS manual_custody (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cust_date   DATE          NULL,
    driver      VARCHAR(180)  NULL,
    amount      DECIMAL(14,2) NOT NULL DEFAULT 0,
    note        VARCHAR(255)  NULL,
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_date (cust_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
say("✓ جدول: manual_custody", 'ok');

say("═══════════════════════════════════════════════", 'hi');
say("  اكتمل ترحيل v11 ✓", 'ok');
say("═══════════════════════════════════════════════", 'hi');
