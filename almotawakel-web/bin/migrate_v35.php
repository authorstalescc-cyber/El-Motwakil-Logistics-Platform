<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * ترحيل v35 — عهدة المناديب والإدخال الميداني من الموبايل.
 *
 * المندوب بينزل له إذن (أو مجموعة أذون)، بيدفع إيصالاته ومصروفاته من **عهدة
 * نازلة له**، وبيسجّلها من موبايله. البند بيقعد في صندوق انتظار لحد ما المالك
 * يعتمده، وساعتها بس بيتحوّل لبند حقيقي على الإذن.
 *
 * ⚠️ عهدة المناديب معزولة تمامًا عن خزنة المكتب (قرار المالك):
 *    نفس نموذج كروت الفيزا (v22) بالحرف:
 *      • رصيد عهدة المندوب = Σ التنزيلات − Σ الإيصالات المعتمدة − Σ المرتجعات.
 *      • تنزيل العهدة **لا يخصم** من الخزنة (زي شحن كارت الفيزا بالظبط).
 *      • البند المدفوع من عهدة مندوب **لا يخصم** من الخزنة ولا يدخل التقفيل
 *        اليومي ولا مطابقة v28 — لأن فلوسه خرجت من العهدة مش من الدرج.
 *      • لكنه **يظهر عادي** في سجل المصروفات وعلى تايم لاين الإذن وفي فاتورة
 *        العميل — زي أي بند، بشارة «عهدة مندوب» (زي شارة الفيزا).
 *
 * ⚠️ تحذير تزامن (زي تحذير 6): أي منطق يعرّف «الكاش الطالع من الخزنة» لازم
 *    يستثني permit_stages.paid_by_rep_id IS NOT NULL في كل الأماكن دي معًا:
 *      Closing::sumExpenses · Closing::hasMovementBetween · Closing::earliestMovementDate
 *      · DailyReconciliation · Custody
 *    نسيان أي واحد فيهم = رصيد العهدة يبوّظ.
 *
 * ملاحظة: v35 **لا يمسّ وحدة المناديب القديمة (v21)** — سجل المرتبات والمكافآت
 * والخصومات بيفضل زي ما هو بالظبط. عهدة المناديب حاجة منفصلة جنبه.
 *
 * آمن لإعادة التشغيل (idempotent) + يتحقق أن رصيد الخزنة لم يتغيّر.
 * التشغيل:  php bin/migrate_v35.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;

function v35Say(string $m): void { echo $m . PHP_EOL; }

function v35TableExists(string $table): bool
{
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',
        [$table]
    );
}

function v35ColumnExists(string $table, string $column): bool
{
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
        [$table, $column]
    );
}

v35Say('ترحيل v35 — عهدة المناديب والإدخال الميداني…');

// رصيد الخزنة قبل الترحيل — لازم يطلع نفس الرقم بالظبط بعده (إثبات العزل)
$balanceBefore = Closing::currentBalance();
v35Say('  · رصيد الخزنة قبل الترحيل: ' . number_format($balanceBefore, 2) . ' ج');

// ----------------------------------------------------------------------------
// 1) تنزيلات العهدة — الفلوس اللي بتنزل للمندوب (نظير visa_deposits)
//    موجب = تنزيل يزوّد عهدته. **لا يخصم من الخزنة.**
// ----------------------------------------------------------------------------
if (!v35TableExists('rep_custody_deposits')) {
    Database::exec("
        CREATE TABLE rep_custody_deposits (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            rep_id     INT UNSIGNED  NOT NULL COMMENT 'المندوب — بلا FK (تحذير 3)',
            dep_date   DATE          NULL COMMENT 'تاريخ تنزيل العهدة',
            amount     DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'موجب = تنزيل يزوّد العهدة',
            note       VARCHAR(255)  NULL,
            created_by VARCHAR(120)  NULL,
            created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_rcd_rep (rep_id, dep_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    v35Say('  + جدول rep_custody_deposits (تنزيلات العهدة)');
} else {
    v35Say('  = جدول rep_custody_deposits (موجود)');
}

// ----------------------------------------------------------------------------
// 2) تسويات العهدة — «عدّينا وراجع معايا كام ورجّع كام»
//    returned بيتخصم من الرصيد فيرجع صفر لما يرجّع كل الباقي.
//    to_custody اختياري: لو المالك حبّ الباقي يرجع للخزنة يتسجّل صف manual_custody
//    ويتربط هنا (نفس نمط ClientPayment::route في v23). افتراضيًا مقفول = معزول.
// ----------------------------------------------------------------------------
if (!v35TableExists('rep_custody_settlements')) {
    Database::exec("
        CREATE TABLE rep_custody_settlements (
            id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            rep_id         INT UNSIGNED  NOT NULL COMMENT 'المندوب — بلا FK',
            settle_date    DATE          NOT NULL COMMENT 'تاريخ التسوية',
            deposits_total DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'لقطة: إجمالي المنزَّل وقت التسوية',
            spent_total    DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'لقطة: إجمالي المصروف على الأذونات',
            returned       DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'اللي رجّعه المندوب فعلاً — يخصم من الرصيد',
            to_custody     TINYINT(1)    NOT NULL DEFAULT 0 COMMENT 'هل رُحّل المرتجع للخزنة؟ (اختياري)',
            custody_id     INT UNSIGNED  NULL COMMENT 'صف manual_custody المُولَّد لو to_custody=1',
            note           VARCHAR(255)  NULL,
            settled_by     VARCHAR(120)  NULL,
            created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_rcs_rep (rep_id, settle_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    v35Say('  + جدول rep_custody_settlements (تسويات العهدة)');
} else {
    v35Say('  = جدول rep_custody_settlements (موجود)');
}

// ----------------------------------------------------------------------------
// 3) حسابات دخول المناديب — للموبايل فقط
//    ⚠️ جلسة المندوب **عمرها ما تحط $_SESSION['uid']**، فكل صفحات المكتب
//    بترفضه تلقائيًا عبر Auth::check() بلا تعديل أي كنترولر (تحذير 5).
//    الرقم السري متشفّر بـpassword_hash، وفيه قفل مؤقت ضد التخمين.
// ----------------------------------------------------------------------------
if (!v35TableExists('rep_users')) {
    Database::exec("
        CREATE TABLE rep_users (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            rep_id        INT UNSIGNED NOT NULL COMMENT 'المندوب — بلا FK',
            username      VARCHAR(60)  NOT NULL COMMENT 'اسم الدخول (لاتيني بسيط)',
            pin_hash      VARCHAR(255) NOT NULL COMMENT 'الرقم السري متشفّر (password_hash)',
            status        VARCHAR(12)  NOT NULL DEFAULT 'active' COMMENT 'active|disabled',
            failed_tries  TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'محاولات فاشلة متتالية',
            locked_until  DATETIME     NULL COMMENT 'قفل مؤقت بعد تكرار الغلط',
            last_login_at DATETIME     NULL,
            created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_repuser_name (username),
            UNIQUE KEY uq_repuser_rep (rep_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    v35Say('  + جدول rep_users (دخول المناديب)');
} else {
    v35Say('  = جدول rep_users (موجود)');
}

// ----------------------------------------------------------------------------
// 4) تنزيل الأذونات للمناديب — «الإذن ده مسؤول عنه فلان»
//    جدول ربط عشان يسمح بأكتر من مندوب على الإذن + تاريخ كامل مين استلم إيه.
// ----------------------------------------------------------------------------
if (!v35TableExists('permit_assignments')) {
    Database::exec("
        CREATE TABLE permit_assignments (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            permit_id   INT UNSIGNED NOT NULL COMMENT 'الإذن — بلا FK',
            rep_id      INT UNSIGNED NOT NULL COMMENT 'المندوب — بلا FK',
            status      VARCHAR(12)  NOT NULL DEFAULT 'active' COMMENT 'active|done',
            note        VARCHAR(255) NULL,
            assigned_by VARCHAR(120) NULL,
            assigned_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            done_at     DATETIME     NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_assign (permit_id, rep_id),
            KEY idx_assign_rep (rep_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    v35Say('  + جدول permit_assignments (تنزيل الأذونات للمناديب)');
} else {
    v35Say('  = جدول permit_assignments (موجود)');
}

// ----------------------------------------------------------------------------
// 5) صندوق الانتظار — البنود اللي دخّلها المندوب لسه مستنية اعتماد المالك
//    ⚠️ مفيش أي أثر مالي هنا إطلاقًا. البند بيبقى حقيقي لما يتعتمد وبس،
//    وساعتها بيتولد صف permit_stages ويتربط عبر stage_id.
//    amount مخزَّن = qty × unit_amount (نفس قاعدة v17).
// ----------------------------------------------------------------------------
if (!v35TableExists('rep_submissions')) {
    Database::exec("
        CREATE TABLE rep_submissions (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            rep_id      INT UNSIGNED  NOT NULL COMMENT 'المندوب اللي دخّل البند — بلا FK',
            permit_id   INT UNSIGNED  NOT NULL COMMENT 'الإذن — بلا FK',
            movement_id INT UNSIGNED  NULL COMMENT 'الحركة داخل الإذن',
            kind        VARCHAR(10)   NOT NULL DEFAULT 'receipt' COMMENT 'receipt=إيصال | expense=مصروف',
            note        VARCHAR(255)  NOT NULL COMMENT 'البيان',
            qty         INT           NOT NULL DEFAULT 1 COMMENT 'الكمية',
            unit_amount DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'سعر الوحدة',
            amount      DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'الإجمالي = qty × unit_amount',
            entry_date  DATE          NOT NULL COMMENT 'اليوم اللي المندوب دفع فيه فعلاً (مش يوم الاعتماد — قاعدة v29)',
            ref_no      VARCHAR(120)  NULL COMMENT 'رقم الإيصال',
            photo       VARCHAR(255)  NULL COMMENT 'صورة الإيصال (مسار نسبي من public/)',
            status      VARCHAR(12)   NOT NULL DEFAULT 'pending' COMMENT 'pending|approved|rejected',
            review_note VARCHAR(255)  NULL COMMENT 'سبب الرفض — يشوفه المندوب',
            reviewed_by VARCHAR(120)  NULL,
            reviewed_at DATETIME      NULL,
            stage_id    INT UNSIGNED  NULL COMMENT 'صف permit_stages المُولَّد عند الاعتماد',
            created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_sub_status (status, entry_date),
            KEY idx_sub_rep (rep_id, status),
            KEY idx_sub_permit (permit_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    v35Say('  + جدول rep_submissions (صندوق الانتظار)');
} else {
    v35Say('  = جدول rep_submissions (موجود)');
}

// ----------------------------------------------------------------------------
// 6) الوسم — «البند ده اتدفع من عهدة المندوب ده»
//    عمود واحد بس، على permit_stages وحده: الإيصال (receipts) والمصروف
//    (custom/expense) الاتنين بيتخزنوا هنا، فده بيغطّي كل بنود المناديب.
//    NULL = زي ما هو دلوقتي بالظبط (كاش من الخزنة أو فيزا).
//    ⚠️ الوسم ده هو المفتاح اللي بيستثني البند من الخزنة — زي is_visa بالظبط.
// ----------------------------------------------------------------------------
if (!v35ColumnExists('permit_stages', 'paid_by_rep_id')) {
    Database::exec("
        ALTER TABLE permit_stages
            ADD COLUMN paid_by_rep_id INT UNSIGNED NULL DEFAULT NULL
                COMMENT 'مدفوع من عهدة المندوب ده (v35) — NULL = كاش/فيزا زي المعتاد'
                AFTER visa_card_id,
            ADD KEY idx_stage_rep (paid_by_rep_id)
    ");
    v35Say('  + عمود permit_stages.paid_by_rep_id');
} else {
    v35Say('  = عمود permit_stages.paid_by_rep_id (موجود)');
}

// ----------------------------------------------------------------------------
// 7) التحقق — الترحيل إضافي بحت، فرصيد الخزنة لازم يطلع نفس الرقم بالمليم
// ----------------------------------------------------------------------------
$balanceAfter = Closing::currentBalance();
v35Say('  · رصيد الخزنة بعد الترحيل: ' . number_format($balanceAfter, 2) . ' ج');

if (round($balanceBefore, 2) !== round($balanceAfter, 2)) {
    v35Say('');
    v35Say('✗ خطر: رصيد الخزنة اتغيّر أثناء الترحيل! (' .
        number_format($balanceBefore, 2) . ' ← ' . number_format($balanceAfter, 2) . ')');
    v35Say('  الترحيل ده مفروض إضافي بحت. راجع قبل أي استخدام.');
    exit(1);
}

v35Say('  ✓ رصيد الخزنة لم يتغيّر — العزل سليم.');
v35Say('');
v35Say('اكتمل ترحيل v35 بنجاح.');
