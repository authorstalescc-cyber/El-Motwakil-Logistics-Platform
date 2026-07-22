<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * ترحيل v37 — تعديل بنود المناديب قبل الاعتماد.
 *
 * المالك بيراجع البند اللي بعته المندوب، وممكن **يصلّحه قبل ما يعتمده** بدل ما
 * يرفضه ويستنى المندوب يعيده: المبلغ، الكمية، التاريخ، البيان، رقم الإيصال،
 * و**السعر الفعلي للعميل** (اللي المندوب ممنوع يشوفه أصلاً — v35).
 *
 * `actual_unit` = سعر الوحدة الفعلي للعميل. عند الاعتماد بيتحوّل لـ
 * `permit_stages.actual_amount = actual_unit × qty` (نفس قاعدة v17: المخزَّن =
 * الإجمالي). فاضي = زي ما كان بالظبط، والمالك يحطه بعدين من `/expenses/receipts`.
 *
 * ⚠️ ما بيمسّش أي حساب: `rep_submissions` جدول انتظار بلا أي أثر مالي، والقيمة
 *    دي بتتنقل للبند وقت الاعتماد بس.
 *
 * آمن لإعادة التشغيل (idempotent).
 * التشغيل:  php bin/migrate_v37.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;

function v37Say(string $m): void { echo $m . PHP_EOL; }

function v37ColumnExists(string $table, string $column): bool
{
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
        [$table, $column]
    );
}

v37Say('ترحيل v37 — تعديل بنود المناديب قبل الاعتماد…');

$balanceBefore = Closing::currentBalance();
v37Say('  · رصيد الخزنة قبل الترحيل: ' . number_format($balanceBefore, 2) . ' ج');

if (!Database::scalar(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema=DATABASE() AND table_name='rep_submissions'")) {
    v37Say('✗ جدول rep_submissions مش موجود — شغّل php bin/migrate_v35.php الأول.');
    exit(1);
}

if (!v37ColumnExists('rep_submissions', 'actual_unit')) {
    Database::exec("
        ALTER TABLE rep_submissions
            ADD COLUMN actual_unit DECIMAL(14,2) NULL DEFAULT NULL
                COMMENT 'سعر الوحدة الفعلي للعميل (v37) — المالك بيحطه وقت المراجعة'
                AFTER unit_amount
    ");
    v37Say('  + عمود rep_submissions.actual_unit');
} else {
    v37Say('  = عمود rep_submissions.actual_unit (موجود)');
}

// مين عدّل البند قبل الاعتماد — شفافية في سجل المراجعة
if (!v37ColumnExists('rep_submissions', 'edited_by')) {
    Database::exec("
        ALTER TABLE rep_submissions
            ADD COLUMN edited_by VARCHAR(120) NULL DEFAULT NULL
                COMMENT 'مين عدّل البند قبل الاعتماد (v37)' AFTER review_started_by,
            ADD COLUMN edited_at DATETIME NULL DEFAULT NULL AFTER edited_by
    ");
    v37Say('  + عمودا rep_submissions.edited_by/edited_at');
} else {
    v37Say('  = عمودا edited_by/edited_at (موجودين)');
}

$balanceAfter = Closing::currentBalance();
v37Say('  · رصيد الخزنة بعد الترحيل: ' . number_format($balanceAfter, 2) . ' ج');

if (round($balanceBefore, 2) !== round($balanceAfter, 2)) {
    v37Say('');
    v37Say('✗ خطر: رصيد الخزنة اتغيّر أثناء الترحيل! راجع قبل أي استخدام.');
    exit(1);
}

v37Say('  ✓ رصيد الخزنة لم يتغيّر — العزل سليم.');
v37Say('');
v37Say('اكتمل ترحيل v37 بنجاح.');
