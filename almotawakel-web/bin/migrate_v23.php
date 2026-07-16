<?php
/**
 * ترحيل v23 — دفعات سداد العملاء + توجيهها للعهدة/الفيزا:
 *   يسجّل الدفعات التي يسلّمها العميل للشركة (سداد على حسابه) في بروفايل الوكيل،
 *   مع إمكانية «توجيه» فلوس الدفعة إلى أحد رصيدَي العهدة:
 *     • route='cash'  → تُنشَأ إضافة عهدة يدوية (manual_custody) بتاريخ الدفعة،
 *                        فتزيد رصيد عهدة اليوم وتدخل التقفيل اليومي (topups).
 *     • route='visa'  → تُنشَأ شحنة رصيد (visa_deposits) على كارت فيزا مختار،
 *                        فتزيد رصيد الكارت في وحدة رصيد المكتب.
 *     • route='none'  → تُسجَّل في حساب العميل فقط بلا مساس بأي عهدة.
 *
 *   جدول: client_payments — الدفعة نفسها (مصدر الحقيقة). الأعمدة custody_id / deposit_id
 *   تربطها بالسجل المُولَّد في manual_custody / visa_deposits ليُحذَف معها تلقائياً.
 *
 *   الربط بالعميل نصّي (client_name) بلا FK — نفس عُرف النظام (الاسم القانوني من agents).
 *
 *   ملاحظات تزامن (زي تحذير 6 و1):
 *     • route='cash' يمرّ عبر نفس مسار العهدة اليدوية، فيحترم قاعدة الإقفال اليومي
 *       (Closing::guard) ويظهر في سجل العهدة والتقفيل — بلا تحسيب مزدوج.
 *     • route='visa' مجرد شحنة على الكارت (معزول عن التقفيل، زي كل الفيزا).
 *
 * آمن لإعادة التشغيل (idempotent).
 * التشغيل:  php bin/migrate_v23.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

function say(string $s, string $c=''){ $x=['ok'=>"\033[32m",'hi'=>"\033[36m",'y'=>"\033[33m",'err'=>"\033[31m"];
    echo ($x[$c]??'').$s."\033[0m\n"; }

$pdo = Database::conn();
say("═══════════════════════════════════════════════", 'hi');
say("  ترحيل نظام المتوكل إلى الإصدار 23 (دفعات سداد العملاء)", 'hi');
say("═══════════════════════════════════════════════", 'hi');

$pdo->exec("CREATE TABLE IF NOT EXISTS client_payments (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_name  VARCHAR(255)  NOT NULL,             -- ربط نصّي بالوكيل (بلا FK)
    pay_date     DATE          NULL,
    amount       DECIMAL(14,2) NOT NULL DEFAULT 0,   -- موجب = العميل سلّم للشركة
    note         VARCHAR(255)  NULL,
    route        VARCHAR(12)   NOT NULL DEFAULT 'none', -- none | cash | visa
    custody_id   INT UNSIGNED  NULL,                 -- manual_custody.id لو route=cash
    deposit_id   INT UNSIGNED  NULL,                 -- visa_deposits.id لو route=visa
    visa_card_id INT UNSIGNED  NULL,                 -- الكارت لو route=visa
    created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_client (client_name),
    KEY idx_date (pay_date),
    KEY idx_route (route)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
say("✓ جدول: client_payments", 'ok');

say("═══════════════════════════════════════════════", 'hi');
say("  اكتمل ترحيل v23 ✓", 'ok');
say("  (نسخة الويب PHP — لا يمسّ نظام الإكسل)", 'y');
say("═══════════════════════════════════════════════", 'hi');
