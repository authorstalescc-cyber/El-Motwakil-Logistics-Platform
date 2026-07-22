<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * ترحيل v22 — رصيد المكتب (كروت الفيزا):
 *   وحدة مستقلة لإدارة كروت الفيزا وأصحابها وأرصدتها.
 *   كل عملية مدفوعة بالفيزا (is_visa=1) في النظام كله تُنسَب لكارت معيّن (visa_card_id)
 *   فتُخصم تلقائيًا من رصيده. الرصيد يزيد بالرصيد الافتتاحي + شحنات يدوية (visa_deposits).
 *
 *   جداول:
 *     • visa_cards    — كارت الفيزا (اسم/رقم + صاحبه + بنك + رصيد افتتاحي + حالة).
 *     • visa_deposits — شحنات/إيداعات رصيد على الكارت (تزيد الرصيد).
 *
 *   أعمدة مُضافة (visa_card_id — الكارت المستخدَم في العملية، NULL لو نقدي):
 *     • manual_expenses.visa_card_id
 *     • permit_stages.visa_card_id
 *     • movement_items.visa_card_id
 *     • debt_payments.visa_card_id
 *
 *   ملاحظة مهمة: visa_card_id مجرد وسم للنسبة — لا يمسّ أي حساب قائم ولا التقفيل
 *   اليومي ولا رصيد العهدة. الرصيد المخصوم = فقط العمليات المعلَّمة is_visa=1.
 *
 * آمن لإعادة التشغيل (idempotent).
 * التشغيل:  php bin/migrate_v22.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

function say(string $s, string $c=''){ $x=['ok'=>"\033[32m",'hi'=>"\033[36m",'y'=>"\033[33m",'err'=>"\033[31m"];
    echo ($x[$c]??'').$s."\033[0m\n"; }

$pdo = Database::conn();
say("═══════════════════════════════════════════════", 'hi');
say("  ترحيل نظام المتوكل إلى الإصدار 22 (رصيد المكتب — كروت الفيزا)", 'hi');
say("═══════════════════════════════════════════════", 'hi');

// ─────────────────────────────────────────── كارت الفيزا
$pdo->exec("CREATE TABLE IF NOT EXISTS visa_cards (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(180)  NOT NULL,           -- اسم/رقم الكارت (آخر 4 أرقام مثلاً)
    owner           VARCHAR(180)  NULL,               -- صاحب الكارت (الفيزا ملك لأكثر من شخص)
    bank            VARCHAR(180)  NULL,               -- البنك
    opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0, -- الرصيد الافتتاحي وقت بدء التسجيل
    status          VARCHAR(20)   NOT NULL DEFAULT 'active',  -- active|inactive
    note            VARCHAR(255)  NULL,
    created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_owner (owner),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
say("✓ جدول: visa_cards", 'ok');

// ─────────────────────────────────────────── شحنات/إيداعات الرصيد
$pdo->exec("CREATE TABLE IF NOT EXISTS visa_deposits (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    card_id     INT UNSIGNED NOT NULL,
    dep_date    DATE          NULL,
    amount      DECIMAL(14,2) NOT NULL DEFAULT 0,   -- موجب = شحن للكارت
    note        VARCHAR(255)  NULL,
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_card (card_id),
    KEY idx_date (dep_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
say("✓ جدول: visa_deposits", 'ok');

// ─────────────────────────────────────────── عمود visa_card_id على مصادر الفيزا الأربعة
/** يضيف عمود visa_card_id + فهرسه لجدول لو مش موجود (idempotent). */
$addCard = function(string $table) use ($pdo) {
    $exists = $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($table) . "
           AND COLUMN_NAME = 'visa_card_id'")->fetchColumn();
    if ($exists) { say("• $table.visa_card_id موجود — تخطّي", 'y'); return; }
    $pdo->exec("ALTER TABLE $table ADD COLUMN visa_card_id INT UNSIGNED NULL DEFAULT NULL,
                ADD KEY idx_visa_card (visa_card_id)");
    say("✓ عمود: $table.visa_card_id", 'ok');
};
foreach (['manual_expenses', 'permit_stages', 'movement_items', 'debt_payments'] as $t) {
    $addCard($t);
}

say("═══════════════════════════════════════════════", 'hi');
say("  اكتمل ترحيل v22 ✓", 'ok');
say("  (نسخة الويب PHP — لا يمسّ نظام الإكسل ولا أي حساب قائم)", 'y');
say("═══════════════════════════════════════════════", 'hi');
