<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * ترحيل v39 — نثريات المناديب (مصروف عام بلا إذن).
 *
 * المندوب بيصرف نثريات مش مربوطة بأي إذن (مواصلات، شاي، تصوير، إكراميات).
 * بيسجّلها من موبايله زي أي بند، بتقعد في نفس صندوق الانتظار، والمالك بيعتمدها
 * من `/rep-custody/inbox` — وساعتها بتتحوّل لصف حقيقي في `rep_misc_expenses`.
 *
 * ⚠️ **قرار المالك:** النثرية بتروح **للمصروفات** وبس — **عمرها ما تدخل حساب
 *    العميل ولا فاتورة إذن**، لأنها مش مربوطة بإذن أصلاً.
 *
 * ⚠️ **العزل عن الخزنة بالبناء (الفكرة الأساسية للترحيل ده):**
 *    النثرية المعتمدة **ما بتتخزنش في `manual_expenses`** — دي كانت هتخصم من
 *    الخزنة والتقفيل اليومي فورًا، وكان لازم نفتكر نستثنيها في 8 أماكن
 *    (Closing::sumExpenses/hasMovementBetween/earliestMovementDate ·
 *     DailyClosingController · DailyReconciliation · Metrics · backfill_closings).
 *    بدل كده بتقعد في **جدول خاص بيها `rep_misc_expenses`** — جدول الحسابات
 *    القديمة كلها عمرها ما سمعت عنه، فالعزل مضمون بلا أي نقطة مزامنة جديدة
 *    تتنسي. نفس منطق عزل `legacy_bawalis` و`dashboard_notes` بالظبط.
 *
 *    فهي تظهر في:  سجل المصروفات `/expenses` (مصدر `rep_misc`)
 *                   + رصيد عهدة المندوب (بتتخصم منه — هو دفعها من عهدته)
 *    وما تظهرش في: الخزنة · التقفيل اليومي · مطابقة v28 · فاتورة أي عميل.
 *
 * آمن لإعادة التشغيل (idempotent) + يتحقق أن رصيد الخزنة لم يتغيّر.
 * التشغيل:  php bin/migrate_v39.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;

function v39Say(string $m): void { echo $m . PHP_EOL; }

function v39TableExists(string $table): bool
{
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',
        [$table]
    );
}

function v39ColumnExists(string $table, string $column): bool
{
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
        [$table, $column]
    );
}

function v39ColumnNullable(string $table, string $column): bool
{
    return Database::scalar(
        'SELECT IS_NULLABLE FROM information_schema.columns
         WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
        [$table, $column]
    ) === 'YES';
}

v39Say('ترحيل v39 — نثريات المناديب…');

if (!v39TableExists('rep_submissions')) {
    v39Say('✗ جدول rep_submissions مش موجود — شغّل bin/migrate_v35.php الأول.');
    exit(1);
}

// رصيد الخزنة قبل الترحيل — لازم يطلع نفس الرقم بالمليم بعده (إثبات العزل)
$balanceBefore = Closing::currentBalance();
v39Say('  · رصيد الخزنة قبل الترحيل: ' . number_format($balanceBefore, 2) . ' ج');

// ----------------------------------------------------------------------------
// 1) النثرية بلا إذن — فـ permit_id لازم يقبل NULL.
//    الصفوف القديمة كلها ليها إذن فعلاً، فالتعديل ده ما بيغيّرش أي بيانات.
// ----------------------------------------------------------------------------
if (!v39ColumnNullable('rep_submissions', 'permit_id')) {
    Database::exec("
        ALTER TABLE rep_submissions
            MODIFY COLUMN permit_id INT UNSIGNED NULL DEFAULT NULL
                COMMENT 'الإذن — بلا FK. NULL = نثرية عامة مش مربوطة بإذن (v39)'
    ");
    v39Say('  ~ rep_submissions.permit_id بقى يقبل NULL (نثرية بلا إذن)');
} else {
    v39Say('  = rep_submissions.permit_id بيقبل NULL (موجود)');
}

// ----------------------------------------------------------------------------
// 2) رابط البند المعتمد لما يكون نثرية.
//    `stage_id` بيربط بـpermit_stages، والنثرية مالهاش صف هناك — فليها عمود خاص.
// ----------------------------------------------------------------------------
if (!v39ColumnExists('rep_submissions', 'misc_id')) {
    Database::exec("
        ALTER TABLE rep_submissions
            ADD COLUMN misc_id INT UNSIGNED NULL DEFAULT NULL
                COMMENT 'صف rep_misc_expenses المُولَّد عند اعتماد نثرية (v39)'
                AFTER stage_id
    ");
    v39Say('  + عمود rep_submissions.misc_id');
} else {
    v39Say('  = عمود rep_submissions.misc_id (موجود)');
}

// ----------------------------------------------------------------------------
// 3) النثريات المعتمدة — الجدول المعزول.
//    ⚠️ مقصود إنه جدول مستقل مش صف في manual_expenses: كده مفيش أي كود خزنة
//    قديم بيشوفه، فمستحيل يخصم من الدرج أو يدخل تقفيل بالغلط.
// ----------------------------------------------------------------------------
if (!v39TableExists('rep_misc_expenses')) {
    Database::exec("
        CREATE TABLE rep_misc_expenses (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            rep_id        INT UNSIGNED  NOT NULL COMMENT 'المندوب اللي دفعها من عهدته — بلا FK',
            submission_id INT UNSIGNED  NULL COMMENT 'البند الأصلي في rep_submissions',
            exp_date      DATE          NOT NULL COMMENT 'يوم الصرف الفعلي (مش يوم الاعتماد — قاعدة v29)',
            category      VARCHAR(120)  NOT NULL DEFAULT 'نثريات' COMMENT 'تصنيف العرض في سجل المصروفات',
            note          VARCHAR(255)  NOT NULL COMMENT 'البيان',
            qty           INT           NOT NULL DEFAULT 1 COMMENT 'الكمية',
            unit_amount   DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'سعر الوحدة',
            amount        DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'الإجمالي = qty × unit_amount (قاعدة v17)',
            ref_no        VARCHAR(120)  NULL COMMENT 'رقم الإيصال لو فيه',
            attachment    VARCHAR(255)  NULL COMMENT 'صورة الإيصال (مسار نسبي من public/)',
            created_by    VARCHAR(120)  NULL COMMENT 'اللي اعتمدها',
            created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_rme_rep (rep_id, exp_date),
            KEY idx_rme_sub (submission_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='نثريات المناديب (v39) — مصروف عام بلا إذن، معزول عن الخزنة'
    ");
    v39Say('  + جدول rep_misc_expenses (النثريات المعتمدة)');
} else {
    v39Say('  = جدول rep_misc_expenses (موجود)');
}

// ----------------------------------------------------------------------------
// 4) التحقق — الترحيل إضافي بحت، فرصيد الخزنة لازم يطلع نفس الرقم بالمليم
// ----------------------------------------------------------------------------
$balanceAfter = Closing::currentBalance();
v39Say('  · رصيد الخزنة بعد الترحيل: ' . number_format($balanceAfter, 2) . ' ج');

if (round($balanceBefore, 2) !== round($balanceAfter, 2)) {
    v39Say('');
    v39Say('✗ خطر: رصيد الخزنة اتغيّر أثناء الترحيل! (' .
        number_format($balanceBefore, 2) . ' ← ' . number_format($balanceAfter, 2) . ')');
    exit(1);
}

v39Say('  ✓ رصيد الخزنة لم يتغيّر — العزل سليم.');
v39Say('');
v39Say('اكتمل ترحيل v39 بنجاح. بعده شغّل: php bin/verify_v39.php');
