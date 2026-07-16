<?php
/**
 * ترحيل v12 — نظام كشف حساب صاحب البيان (النظام الثاني للفوترة).
 *   يُنشئ جدول owner_statements الذي يخزّن لقطة ثابتة لكل «كشف حساب» يُصدَر لصاحب بيان:
 *     • ملخّص صاحب البيان وقت الإصدار: إجمالي الدين (علينا) + ما استلمه (المدفوع) + الباقي له.
 *     • قائمتان (لقطة JSON): البوالص التي استلم عليها + البوالص الباقية له — بكافة التفاصيل.
 *     • بلا ضريبة إطلاقاً — مستند تشغيلي.
 *   المبالغ تُؤخذ من البوالص مباشرةً (bawalis.debt/paid/remaining) وتبقى ثابتة مهما تغيّرت لاحقاً.
 *
 * آمن لإعادة التشغيل (idempotent).
 * التشغيل:  php bin/migrate_v12.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

function say(string $s, string $c=''){ $x=['ok'=>"\033[32m",'hi'=>"\033[36m",'y'=>"\033[33m",'err'=>"\033[31m"];
    echo ($x[$c]??'').$s."\033[0m\n"; }

function tableExists(string $table): bool
{
    return (bool) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?",
        [$table]
    );
}

say("═══════════════════════════════════════════════", 'hi');
say("  ترحيل نظام المتوكل إلى الإصدار 12", 'hi');
say("  (كشف حساب صاحب البيان — owner_statements)", 'hi');
say("═══════════════════════════════════════════════", 'hi');

if (tableExists('owner_statements')) {
    say("• جدول owner_statements موجود مسبقاً — تخطٍّ", 'y');
} else {
    Database::exec(
        "CREATE TABLE owner_statements (
            id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            doc_no         VARCHAR(40)  NULL,                    -- رقم المستند النظامي (تلقائي: MT-S-00001)
            statement_date DATE         NULL,                   -- تاريخ إصدار الكشف
            owner          VARCHAR(190) NULL,                   -- صاحب البيان (لقطة الاسم)
            -- ملخّص صاحب البيان وقت الإصدار (كل بوالصه، لقطة ثابتة)
            stat_bills     INT          NOT NULL DEFAULT 0,     -- إجمالي عدد بوالصه
            stat_debt      DECIMAL(16,2) NOT NULL DEFAULT 0,    -- إجمالي الدين (علينا له)
            stat_paid      DECIMAL(16,2) NOT NULL DEFAULT 0,    -- إجمالي ما استلمه (المدفوع له)
            stat_remaining DECIMAL(16,2) NOT NULL DEFAULT 0,    -- إجمالي الباقي له
            -- ملخّص القائمتين المدرَجتين في هذا الكشف
            paid_count      INT          NOT NULL DEFAULT 0,    -- عدد البوالص المدرَجة كمستلَمة
            paid_sum        DECIMAL(16,2) NOT NULL DEFAULT 0,   -- مجموع ما استلمه في القائمة المدرَجة
            remaining_count INT          NOT NULL DEFAULT 0,    -- عدد البوالص الباقية المدرَجة
            remaining_sum   DECIMAL(16,2) NOT NULL DEFAULT 0,   -- مجموع الباقي في القائمة المدرَجة
            items_json     MEDIUMTEXT   NULL,                   -- لقطة القائمتين {\"paid\":[...],\"remaining\":[...]}
            note           VARCHAR(500) NULL,
            created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_owner (owner),
            KEY idx_date (statement_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    say("✓ أُنشئ جدول owner_statements", 'ok');
}

say("═══════════════════════════════════════════════", 'hi');
say("  اكتمل ترحيل v12 ✓", 'ok');
say("  (نسخة الويب PHP — لا يمسّ نظام الإكسل)", 'y');
say("═══════════════════════════════════════════════", 'hi');
