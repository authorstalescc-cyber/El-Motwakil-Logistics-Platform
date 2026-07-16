<?php
/**
 * ترحيل v21 — وحدة المناديب (Field Reps):
 *   المناديب اللي بيلفّوا يجمّعوا ويورّدوا الإيصالات، وليهم مرتب ومواصلات وسجل كامل،
 *   وبيتقفل حسابهم دوريًا (يدوي من–إلى).
 *
 *   جداول:
 *     • reps            — كارت المندوب (اسم/تليفون/مرتب/تعيين/حالة).
 *     • rep_entries     — بنود سجله (مستحقات + خصومات + سُلف + دفعات تسوية).
 *     • rep_settlements — تقفيلات الحساب الدورية (فترة + إجماليات + صافي مدفوع).
 *
 *   الربط بالمصروفات/العهدة (قرار صاحب الشركة):
 *     بس البنود اللي بتطلّع فلوس من الخزنة فعلاً — «سُلفة» (advance) و«دفعة تسوية»
 *     (settlement) — بتدخل «المصروفات العامة» (Expense مصدر reps) وبتتخصم من رصيد
 *     العهدة والتقفيل اليومي (Closing). باقي البنود (مرتب/مواصلات/مصروف لوجيستي/…)
 *     استحقاقات في سجل المندوب بس، بتتغطّى جوه صافي التسوية — فمفيش تحسيب مزدوج.
 *
 * آمن لإعادة التشغيل (idempotent).
 * التشغيل:  php bin/migrate_v21.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

function say(string $s, string $c=''){ $x=['ok'=>"\033[32m",'hi'=>"\033[36m",'y'=>"\033[33m",'err'=>"\033[31m"];
    echo ($x[$c]??'').$s."\033[0m\n"; }

$pdo = Database::conn();
say("═══════════════════════════════════════════════", 'hi');
say("  ترحيل نظام المتوكل إلى الإصدار 21 (وحدة المناديب)", 'hi');
say("═══════════════════════════════════════════════", 'hi');

// ─────────────────────────────────────────── كارت المندوب
$pdo->exec("CREATE TABLE IF NOT EXISTS reps (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name           VARCHAR(180)  NOT NULL,
    phone          VARCHAR(60)   NULL,
    monthly_salary DECIMAL(14,2) NOT NULL DEFAULT 0,
    hire_date      DATE          NULL,
    status         VARCHAR(20)   NOT NULL DEFAULT 'active',
    note           VARCHAR(255)  NULL,
    created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_status (status),
    KEY idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
say("✓ جدول: reps", 'ok');

// ─────────────────────────────────────────── تقفيلات الحساب الدورية
// (يُنشأ قبل rep_entries لأن العمود settlement_id يشير إليه منطقيًا — بلا FK صارم زي باقي النظام)
$pdo->exec("CREATE TABLE IF NOT EXISTS rep_settlements (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    rep_id           INT UNSIGNED NOT NULL,
    period_from      DATE          NULL,
    period_to        DATE          NULL,
    total_due        DECIMAL(14,2) NOT NULL DEFAULT 0,  -- مستحقات الفترة (مرتب+مواصلات+لوجيستي+مكافآت)
    total_deductions DECIMAL(14,2) NOT NULL DEFAULT 0,  -- خصومات الفترة (مصروف يُخصم + خصم)
    total_advances   DECIMAL(14,2) NOT NULL DEFAULT 0,  -- سُلف الفترة (اتصرفت قبل كده)
    net              DECIMAL(14,2) NOT NULL DEFAULT 0,  -- الصافي = due − deductions − advances
    paid_amount      DECIMAL(14,2) NOT NULL DEFAULT 0,  -- المدفوع فعلاً وقت التقفيل
    is_visa          TINYINT(1)    NOT NULL DEFAULT 0,
    note             VARCHAR(255)  NULL,
    settled_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    settled_by       VARCHAR(120)  NULL,
    pay_date         DATE          NULL,                -- تاريخ دفعة التسوية (لحساب العهدة/الإقفال)
    PRIMARY KEY (id),
    KEY idx_rep (rep_id),
    KEY idx_period (period_from, period_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
say("✓ جدول: rep_settlements", 'ok');

// ─────────────────────────────────────────── بنود السجل
$pdo->exec("CREATE TABLE IF NOT EXISTS rep_entries (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    rep_id        INT UNSIGNED NOT NULL,
    entry_date    DATE          NULL,
    kind          VARCHAR(24)   NOT NULL,   -- salary|transport|expense_logistic|bonus|expense_deducted|deduction|advance|settlement
    amount        DECIMAL(14,2) NOT NULL DEFAULT 0,
    is_visa       TINYINT(1)    NOT NULL DEFAULT 0,
    note          VARCHAR(255)  NULL,
    settlement_id INT UNSIGNED  NULL,       -- البند اتحاسب في هذا التقفيل (أو هو نفسه دفعة تسوية)
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rep (rep_id),
    KEY idx_date (entry_date),
    KEY idx_kind (kind),
    KEY idx_settlement (settlement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
say("✓ جدول: rep_entries", 'ok');

say("═══════════════════════════════════════════════", 'hi');
say("  اكتمل ترحيل v21 ✓", 'ok');
say("  (نسخة الويب PHP — لا يمسّ نظام الإكسل)", 'y');
say("═══════════════════════════════════════════════", 'hi');
