<?php
/**
 * ترحيل v7 — وحدة المديونيات:
 *   1) جدول debt_accounts  — حسابات الجهات التي تدين لها الشركة (دائنون).
 *        • حساب «الكشف والضمان» (ياسر أبو عمر / كونتراكت) — kind='inspection'
 *          مديونيته تُحتسب تلقائياً = Σ (حاويات كل إذن × قيمة الكشف والضمان).
 *        • حسابات يدوية (kind='manual') لأي مديونيات أخرى — أصلها opening.
 *   2) جدول debt_payments  — الدفعات الصادرة من الشركة لهذه الجهات (+ خيار Visa).
 *   3) بذر حساب الكشف والضمان مرة واحدة.
 *
 * آمن لإعادة التشغيل (idempotent).
 * التشغيل:  php bin/migrate_v7.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

function say(string $s, string $c=''){ $x=['ok'=>"\033[32m",'hi'=>"\033[36m",'y'=>"\033[33m",'err'=>"\033[31m"];
    echo ($x[$c]??'').$s."\033[0m\n"; }

/** هل يوجد جدول؟ */
function tableExists(string $table): bool
{
    return (bool) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?",
        [$table]
    );
}

say("═══════════════════════════════════════════════", 'hi');
say("  ترحيل نظام المتوكل إلى الإصدار 7", 'hi');
say("  (وحدة المديونيات: الكشف والضمان + الدفعات)", 'hi');
say("═══════════════════════════════════════════════", 'hi');

// (1) جدول حسابات المديونيات (الدائنون)
if (tableExists('debt_accounts')) {
    say("• جدول debt_accounts موجود مسبقاً — تخطٍّ", 'y');
} else {
    Database::exec("
        CREATE TABLE debt_accounts (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            account_key VARCHAR(64)  NULL,               -- مفتاح ثابت للحسابات الخاصة (inspection)
            name        VARCHAR(200) NOT NULL,           -- اسم الجهة الدائنة
            kind        VARCHAR(20)  NOT NULL DEFAULT 'manual', -- inspection | manual
            opening     DECIMAL(14,2) NOT NULL DEFAULT 0, -- أصل المديونية (للحسابات اليدوية)
            note        VARCHAR(255) NULL,
            created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_debt_key (account_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    say("✓ جدول debt_accounts أُنشئ", 'ok');
}

// (2) جدول دفعات المديونيات (مبالغ صادرة من الشركة للدائن)
if (tableExists('debt_payments')) {
    say("• جدول debt_payments موجود مسبقاً — تخطٍّ", 'y');
} else {
    Database::exec("
        CREATE TABLE debt_payments (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id INT UNSIGNED NOT NULL,
            pay_date   DATE          NULL,
            amount     DECIMAL(14,2) NOT NULL DEFAULT 0,
            note       VARCHAR(255)  NULL,
            is_visa    TINYINT(1)    NOT NULL DEFAULT 0,   -- دُفعت بالفيزا؟
            created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_debt_account (account_id),
            CONSTRAINT fk_debtpay_account FOREIGN KEY (account_id)
                REFERENCES debt_accounts(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    say("✓ جدول debt_payments أُنشئ", 'ok');
}

// (3) بذر حساب الكشف والضمان (ياسر أبو عمر / كونتراكت)
$exists = (int) Database::scalar("SELECT COUNT(*) FROM debt_accounts WHERE account_key = ?", ['inspection']);
if ($exists) {
    say("• حساب الكشف والضمان موجود مسبقاً — تخطٍّ", 'y');
} else {
    Database::exec(
        "INSERT INTO debt_accounts (account_key, name, kind, opening, note)
         VALUES (?,?,?,0,?)",
        ['inspection', 'ياسر أبو عمر / كونتراكت', 'inspection',
         'مديونية الكشف والضمان — تُحتسب تلقائياً من حاويات الأذونات']
    );
    say("✓ حساب الكشف والضمان (ياسر أبو عمر / كونتراكت) أُضيف", 'ok');
}

say("═══════════════════════════════════════════════", 'hi');
say("  اكتمل ترحيل v7 ✓", 'ok');
say("═══════════════════════════════════════════════", 'hi');
