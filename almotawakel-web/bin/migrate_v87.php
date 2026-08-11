<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v87 — رواتب المناديب: الثابت + حوض الكومسيون + حوض المصروف.
 *
 * ═══ اللزمة (قرار المالك 2026-08-11) ═══
 * راتب المندوب **٣ حاجات مختلفة** كانت متفرّقة في ٣ أماكن ومحدش بيجمّعها:
 *   ① راتب ثابت فردي  (`reps.monthly_salary`) — منه بس تتخصم السلف
 *   ② حوض كومسيون مشترك = Σ الـ١٠٠ المحتجزة من بوالص الفترة ÷ المناديب
 *   ③ حوض مصروف مشترك  = عدد البوالص × ١٥٠ − المصروف المسموح ÷ المناديب
 * شاشة «حسابي» (v72) كانت بتعرض ① بس، والـ② و③ ما كانوش موجودين أصلاً.
 *
 * ═══ ⚠️⚠️ الترحيل ده **صفر أثر مالي** ═══
 * بيعمل **جدولين فاضيين** + عمود ربط + ٣ إعدادات بقيم افتراضية.
 * ولا مليم بيتحرّك: الفلوس بتتحرّك بس لما المالك **يعتمد فترة** من الشاشة،
 * وساعتها بتمرّ من **قناة `rep_entries` القايمة** (استحقاق) و`rep_settlements`
 * (صرف) — مفيش مسار مالي جديد اتخلق هنا.
 *
 * ⚠️ **ليه الصرف ما بيتسجّلش `advance` في `rep_entries`؟**
 *    السلفة اللي المندوب سحبها من عهدته **اتصرفت فعلاً** ودخلت `/expenses`
 *    والخزنة يوم صرفها (v42). لو سجّلناها تاني بنوع `advance` (وده من
 *    `Rep::CASH_KINDS`) كانت هتتخصم **مرتين**. عشان كده بتتسجّل `deduction`
 *    — بتقلّل المستحق للمندوب بلا ما تمسّ خزنة. والمحصّلة صح:
 *      الشركة صرفت (سلفة) + (صافي التسوية) = الراتب كامل بالظبط.
 *
 * ⚠️ **المعدّلان مؤرَّخان** (`custody_bill_deduction` كان مؤرَّخًا من v74،
 *    و`rep_expense_per_bill` بينضمّ له): تغيير الرقم بكرة **ما يعيدش حساب**
 *    الشهور اللي فاتت. وفوق ده الفترة المعتمدة **لقطة ثابتة** في
 *    `payroll_lines` — زي الفواتير بالظبط.
 *
 * التشغيل:  php bin/migrate_v87.php [--dry-run]
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\Settings;
use App\Core\SettingsSchema;
use App\Models\Payroll;
use App\Models\RepMiscKind;

$v87Dry    = in_array('--dry-run', $argv ?? [], true);
$v87Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
$v87Say = static function (string $m) use ($v87Direct): void { if ($v87Direct) { echo $m . "\n"; } };

$v87HasTbl = static fn (string $t): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
$v87HasCol = static fn (string $t, string $c): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);

$v87Up = static function () use ($v87Dry, $v87Say, $v87HasTbl, $v87HasCol): void {
    $pdo = Database::conn();

    /* ── ① فترة الرواتب (الرأس + اللقطة الجماعية) ──────────────────────────
       ⚠️ كل رقم مشترك بيتخزّن هنا **وقت الاعتماد** عشان الشاشة تفضل تقول نفس
          الحقيقة بعد أي تغيير في المعدّلات أو في بيانات البوالص. */
    if (!$v87HasTbl('payroll_periods')) {
        if (!$v87Dry) {
            $pdo->exec("CREATE TABLE payroll_periods (
                id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
                period_from       DATE          NOT NULL,
                period_to         DATE          NOT NULL,
                status            VARCHAR(16)   NOT NULL DEFAULT 'draft',  -- draft | approved
                bills_counted     INT           NOT NULL DEFAULT 0,   -- البوالص اللي دخلت الحساب
                bills_excluded    INT           NOT NULL DEFAULT 0,   -- المستثناة بنوع الشحنة
                commission_pool   DECIMAL(14,2) NOT NULL DEFAULT 0,   -- Σ الـ١٠٠ المحتجزة
                expense_pool      DECIMAL(14,2) NOT NULL DEFAULT 0,   -- عدد البوالص × ١٥٠
                allowed_expenses  DECIMAL(14,2) NOT NULL DEFAULT 0,   -- المصروف المسموح المعتمد
                expense_remainder DECIMAL(14,2) NOT NULL DEFAULT 0,   -- الباقي اللي اتقسم (صفر لو عجز)
                deficit           DECIMAL(14,2) NOT NULL DEFAULT 0,   -- الزيادة فوق الحوض (موجب)
                deficit_decision  VARCHAR(16)   NOT NULL DEFAULT 'office', -- office | deduct
                reps_count        INT           NOT NULL DEFAULT 0,   -- اللي اتقسم عليهم
                note              VARCHAR(255)  NULL,
                approved_at       TIMESTAMP     NULL,
                approved_by       VARCHAR(120)  NULL,
                created_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_period (period_from, period_to),
                KEY idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        $v87Say('  ✓ جدول: payroll_periods');
    } else {
        $v87Say('  • payroll_periods موجود');
    }

    /* ── ② سطر كل مندوب في الفترة ──────────────────────────────────────────
       ⚠️ `included` بيخلّي المالك يشيل مندوب من القسمة **قبل الاعتماد**
          (سافر · دخل نص الشهر) والحوض يتعاد قسمته على الباقي. */
    if (!$v87HasTbl('payroll_lines')) {
        if (!$v87Dry) {
            $pdo->exec("CREATE TABLE payroll_lines (
                id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
                period_id        INT UNSIGNED NOT NULL,
                rep_id           INT UNSIGNED NOT NULL,
                rep_name         VARCHAR(180)  NULL,      -- لقطة الاسم وقت الاعتماد
                included         TINYINT(1)    NOT NULL DEFAULT 1,
                base_salary      DECIMAL(14,2) NOT NULL DEFAULT 0,
                advances         DECIMAL(14,2) NOT NULL DEFAULT 0,   -- سلف الفترة (اتصرفت قبل كده)
                commission_share DECIMAL(14,2) NOT NULL DEFAULT 0,
                expense_share    DECIMAL(14,2) NOT NULL DEFAULT 0,   -- سالب لو المالك قرّر خصم العجز
                net              DECIMAL(14,2) NOT NULL DEFAULT 0,
                note             VARCHAR(255)  NULL,
                created_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_line (period_id, rep_id),
                KEY idx_rep (rep_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        $v87Say('  ✓ جدول: payroll_lines');
    } else {
        $v87Say('  • payroll_lines موجود');
    }

    /* ── ③ ربط بند السجل بالفترة اللي ولّدته ────────────────────────────────
       من غيره «إلغاء الاعتماد» ما يعرفش يشيل أنهي بنود، فبيفضل الاعتماد
       طريقًا في اتجاه واحد. */
    if ($v87HasTbl('rep_entries') && !$v87HasCol('rep_entries', 'payroll_period_id')) {
        if (!$v87Dry) {
            // ⚠️ ALTER بيعمل commit ضمني — عشان كده الترحيل ده مالوش transaction أصلاً.
            $pdo->exec("ALTER TABLE rep_entries
                        ADD COLUMN payroll_period_id INT UNSIGNED NULL AFTER settlement_id,
                        ADD KEY idx_payroll (payroll_period_id)");
        }
        $v87Say('  ✓ عمود: rep_entries.payroll_period_id');
    } else {
        $v87Say('  • rep_entries.payroll_period_id موجود (أو الجدول لسه ما اتعملش)');
    }

    /* ── ④ الإعدادات ───────────────────────────────────────────────────────
       ⚠️ بتتكتب بالقيمة الافتراضية من السجل المعرَّف — مفيش رقم مكتوب هنا
          بإيدنا، عشان تعريفان ما يختلفوش (قاعدة ② — المصدر الوحيد). */
    foreach (Payroll::SETTINGS as $key) {
        $def = SettingsSchema::get($key);
        if ($def === null) { $v87Say("  ✗ الإعداد «{$key}» مش معرَّف في السجل"); continue; }
        if (!$v87Dry) { Settings::set($key, (string) $def['default']); }
        $v87Say("  ✓ إعداد: {$def['label']}");
    }

    $v87Say('  • نوع النثرية «' . RepMiscKind::label('printing') . '» متسجّل في الكتالوج');

    /* ⚠️ **الكاش الساكن لازم يتصفّر في آخر أي ترحيل بيضيف جداوله** — من غير ده
       أي كود اتنفّذ قبل الـCREATE في نفس العملية بيفضل شايف `available()=false`. */
    if (!$v87Dry) { Payroll::refreshReady(); }
};

/**
 * ⚠️ التحقّق بيثبت **حالة بنيوية دائمة** (جدول · عمود · إعداد · مفتاح فريد)
 *    لا لقطة بيانات — عشان يفضل قابلًا لإعادة التشغيل للأبد.
 */
$v87Verify = static function () use ($v87Say, $v87HasTbl, $v87HasCol): bool {
    $ok = true;

    foreach (['payroll_periods', 'payroll_lines'] as $t) {
        if (!$v87HasTbl($t)) { $v87Say("✗ الجدول «{$t}» مش موجود."); $ok = false; continue; }
        $v87Say("  ✓ جدول $t موجود");
    }

    if (!$v87HasCol('rep_entries', 'payroll_period_id')) {
        $v87Say('✗ عمود rep_entries.payroll_period_id ناقص.'); $ok = false;
    } else { $v87Say('  ✓ عمود الربط بالفترة موجود'); }

    // المفتاح الفريد هو اللي بيمنع فترتين بنفس النطاق (اعتماد مرتين).
    $uniq = (int) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = 'payroll_periods'
            AND index_name = 'uniq_period'");
    if ($uniq < 2) { $v87Say('✗ المفتاح الفريد uniq_period ناقص.'); $ok = false; }
    else { $v87Say('  ✓ فترتان بنفس النطاق مستحيلتان بنيويًّا'); }

    $uniqL = (int) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = 'payroll_lines'
            AND index_name = 'uniq_line'");
    if ($uniqL < 2) { $v87Say('✗ المفتاح الفريد uniq_line ناقص.'); $ok = false; }
    else { $v87Say('  ✓ سطران لنفس المندوب في نفس الفترة مستحيلان'); }

    foreach (Payroll::SETTINGS as $key) {
        if (SettingsSchema::get($key) === null) { $v87Say("✗ الإعداد «{$key}» مش في السجل."); $ok = false; }
    }
    if ($ok) { $v87Say('  ✓ الإعدادات الثلاثة معرَّفة'); }

    // المعدّل الجديد لازم يكون **مؤرَّخًا** — من غيره تغييره بيعيد حساب الماضي.
    if (!in_array(Payroll::SETTING_EXPENSE_RATE, Settings::DATED_KEYS, true)) {
        $v87Say('✗ معدّل مصروف البوليصة مش في Settings::DATED_KEYS.'); $ok = false;
    } else { $v87Say('  ✓ معدّل المصروف مؤرَّخ (تغييره ما بيمسّش الماضي)'); }

    if (!RepMiscKind::has('printing')) {
        $v87Say('✗ نوع النثرية «تصوير وطباعة» مش في الكتالوج.'); $ok = false;
    } else { $v87Say('  ✓ نوع «تصوير وطباعة» موجود'); }

    return $ok;
};

if ($v87Direct) {
    echo 'v87 — رواتب المناديب' . ($v87Dry ? '  (تجربة — مفيش كتابة)' : '') . "\n";
    echo str_repeat('=', 62) . "\n";
    $v87Up();
    if ($v87Dry) { echo "\nشغّل من غير --dry-run عشان يتطبّق.\n"; exit(0); }
    if ($v87Verify() !== true) { fwrite(STDERR, "فشل التحقق بعد تطبيق v87.\n"); exit(1); }
    echo "\n✓ تمّ.\n";
}

return ['up' => $v87Up, 'verify' => $v87Verify];
