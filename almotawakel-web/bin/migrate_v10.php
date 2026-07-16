<?php
/**
 * ترحيل v10 — نظام فوترة العملاء الخاص بالأذونات (النظام الأول).
 *   يُنشئ جدول permit_invoices الذي يخزّن لقطة ثابتة لكل فاتورة/كشف حساب صادر عن إذن:
 *     • النوع: فاتورة (invoice) أو كشف حساب (statement).
 *     • خاضعة للضريبة؟ + الرقم الداخلي (رقم الفاتورة) للفواتير الضريبية.
 *     • النولون المُدخَل عند الفوترة (غير النولون الفعلي المسجّل على البوالص).
 *     • ق. مضافة 14% تُحسب على النولون فقط.
 *     • بنود المصروفات المختارة (لقطة JSON) + الإجماليات — تبقى ثابتة مهما تغيّر الإذن لاحقاً.
 *
 * آمن لإعادة التشغيل (idempotent).
 * التشغيل:  php bin/migrate_v10.php
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

function colExists(string $table, string $col): bool
{
    return (bool) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
        [$table, $col]
    );
}

function addCol(string $table, string $col, string $definition): void
{
    if (colExists($table, $col)) { say("• $table.$col موجود مسبقاً — تخطٍّ", 'y'); return; }
    Database::exec("ALTER TABLE `$table` ADD COLUMN `$col` $definition");
    say("✓ $table.$col أُضيف", 'ok');
}

say("═══════════════════════════════════════════════", 'hi');
say("  ترحيل نظام المتوكل إلى الإصدار 10", 'hi');
say("  (نظام فوترة العملاء الخاص بالأذونات — permit_invoices)", 'hi');
say("═══════════════════════════════════════════════", 'hi');

if (tableExists('permit_invoices')) {
    say("• جدول permit_invoices موجود مسبقاً — تخطٍّ", 'y');
} else {
    Database::exec(
        "CREATE TABLE permit_invoices (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            permit_id    INT UNSIGNED NOT NULL,
            doc_no       VARCHAR(40)  NULL,                    -- رقم المستند النظامي (تلقائي: MT-00001)
            doc_kind     VARCHAR(12)  NOT NULL DEFAULT 'invoice', -- invoice=فاتورة | statement=كشف حساب
            taxable      TINYINT(1)   NOT NULL DEFAULT 0,      -- خاضعة للقيمة المضافة؟ (للفواتير فقط)
            internal_no  VARCHAR(120) NULL,                    -- الرقم الداخلي (رقم الفاتورة) — للفواتير الضريبية
            invoice_date DATE         NULL,
            client       VARCHAR(180) NULL,                    -- الوكيل/العميل (لقطة)
            company      VARCHAR(180) NULL,                    -- الشركة (لقطة)
            destination  VARCHAR(120) NULL,                    -- الجهة (لقطة)
            permit_no    VARCHAR(120) NULL,                    -- رقم الإذن/الشهادة (لقطة)
            permit_type  VARCHAR(10)  NULL,                    -- sader=صادر/إذن | warid=وارد/شهادة (لقطة)
            agency       VARCHAR(180) NULL,                    -- التوكيل الملاحي (لقطة)
            port         VARCHAR(120) NULL,                    -- الميناء (لقطة)
            containers   INT          NOT NULL DEFAULT 0,      -- عدد الحاويات (لقطة)
            freight      DECIMAL(14,2) NOT NULL DEFAULT 0,     -- النولون المُدخَل عند الفوترة
            vat_rate     DECIMAL(6,4)  NOT NULL DEFAULT 0,     -- نسبة الضريبة المطبّقة (0 لو غير خاضعة)
            vat_amount   DECIMAL(14,2) NOT NULL DEFAULT 0,     -- قيمة الضريبة = النولون × النسبة
            subtotal     DECIMAL(14,2) NOT NULL DEFAULT 0,     -- إجمالي البنود (نولون + مصروفات مختارة)
            total        DECIMAL(14,2) NOT NULL DEFAULT 0,     -- الإجمالي الكلي = subtotal + vat_amount
            items_json   MEDIUMTEXT   NULL,                    -- لقطة بنود الفاتورة [{label, amount}]
            note         VARCHAR(500) NULL,
            created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_permit (permit_id),
            KEY idx_date (invoice_date),
            KEY idx_kind (doc_kind),
            CONSTRAINT fk_pinv_permit FOREIGN KEY (permit_id) REFERENCES permits(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    say("✓ أُنشئ جدول permit_invoices", 'ok');
}

// أعمدة إضافية للقطة تفاصيل الإذن (آمنة على الجداول المُنشأة سابقاً في نفس الإصدار)
addCol('permit_invoices', 'permit_type', "VARCHAR(10) NULL AFTER `permit_no`");
addCol('permit_invoices', 'agency',      "VARCHAR(180) NULL AFTER `permit_type`");
addCol('permit_invoices', 'port',        "VARCHAR(120) NULL AFTER `agency`");

say("═══════════════════════════════════════════════", 'hi');
say("  اكتمل ترحيل v10 ✓", 'ok');
say("  (نسخة الويب PHP — لا يمسّ نظام الإكسل)", 'y');
say("═══════════════════════════════════════════════", 'hi');
