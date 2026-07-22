<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v45 — دفع المندوب بالفيزا + تسليم عهدة لسائق.
 *
 * ١) **الفيزا للمناديب:** المندوب بقى يقدر يقول «البند ده دفعته بكارت الفيزا»
 *    ويختار الكارت — في بنود الأذونات وفي النثريات. القاعدة (قرار المالك):
 *
 *      المبلغ يتخصم من **رصيد الكارت وبس**. لا الخزنة ولا عهدة المندوب الكاش
 *      بتتمسّ — لأنه ما دفعش من فلوسه، دفع بكارت المكتب.
 *
 *    ⚠️ ده معناه إن النثرية المدفوعة بالفيزا لازم **تتستثنى من كل حساب خزنة**
 *       بيقرا `rep_misc_expenses` (Closing + DailyReconciliation +
 *       DailyClosingController + backfill_closings)، و**تتضاف لاتحاد مدفوعات
 *       الفيزا** في VisaCard. الأعمدة الجديدة بتتحط بـDEFAULT 0 فكل السجلات
 *       القديمة بتفضل كاش زي ما هي بالظبط.
 *
 * ٢) **تسليم عهدة لسائق:** المندوب بيسلّم كاش لسائق على بوليصة معيّنة في إذن
 *    معيّن. **الأثر الوحيد: عهدة المندوب بتنقص.** ولا البوليصة ولا الخزنة ولا
 *    المصروفات ولا العهدة العامة بتتغيّر (قرار المالك حرفيًّا).
 *
 *    ⚠️ عشان كده ليه **جدول خاص `rep_driver_handovers`** — نفس منطق عزل
 *       `rep_misc_expenses` (v39) و`legacy_bawalis`: كود الخزنة عمره ما سمع
 *       عن الجدول ده، فالعزل مضمون **بلا أي نقطة مزامنة جديدة**.
 *       `bin/verify_v45.php` بيفشل لو أي ملف خزنة ذكر الجدول.
 *
 * إضافي بحت + idempotent. بيتحقّق إن رصيد الخزنة ما اتغيّرش.
 *
 * التشغيل:  php bin/migrate_v45.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;

function v45Say(string $m): void { echo $m . "\n"; }

function v45TableExists(string $t): bool
{
    return (bool) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?", [$t]);
}

function v45ColumnExists(string $t, string $c): bool
{
    return (bool) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?", [$t, $c]);
}

/** بيضيف عمودَي الفيزا على جدول — بنفس شكلهم في permit_stages بالظبط. */
function v45AddVisaColumns(string $table, string $after): void
{
    if (!v45TableExists($table)) { v45Say("  ~ $table مش موجود — اتخطّى"); return; }

    if (v45ColumnExists($table, 'is_visa')) {
        v45Say("  = $table.is_visa (موجود)");
    } else {
        Database::exec(
            "ALTER TABLE `$table`
             ADD COLUMN `is_visa` TINYINT(1) NOT NULL DEFAULT 0
             COMMENT 'مدفوع بكارت فيزا — يتخصم من الكارت لا من الخزنة/العهدة (v45)'
             AFTER `$after`");
        v45Say("  + $table.is_visa (الافتراضي 0 = كاش زي ما كان)");
    }

    if (v45ColumnExists($table, 'visa_card_id')) {
        v45Say("  = $table.visa_card_id (موجود)");
    } else {
        Database::exec(
            "ALTER TABLE `$table`
             ADD COLUMN `visa_card_id` INT UNSIGNED NULL
             COMMENT 'الكارت المنسوب له الدفع (v45)'
             AFTER `is_visa`,
             ADD KEY `idx_" . substr($table, 0, 12) . "_visa` (`visa_card_id`)");
        v45Say("  + $table.visa_card_id");
    }
}

v45Say("v45 — فيزا المناديب + تسليم عهدة لسائق\n" . str_repeat('=', 60));

// ── متطلبات
foreach (['rep_submissions', 'reps'] as $t) {
    if (!v45TableExists($t)) {
        v45Say("✗ جدول $t مش موجود — شغّل migrate_v35 الأول.");
        exit(1);
    }
}

$balanceBefore = Closing::currentBalance();
$custodyBefore = [];
foreach (Database::all("SELECT id FROM reps") as $r) {
    $custodyBefore[(int) $r['id']] = \App\Models\RepCustody::balance((int) $r['id']);
}

// ═══════════════════════════════ 1) أعمدة الفيزا
v45Say("\n1) أعمدة الفيزا");
v45AddVisaColumns('rep_submissions', 'photo');
v45AddVisaColumns('rep_misc_expenses', 'attachment');

// ═══════════════════════════════ 2) جدول تسليم السائقين
v45Say("\n2) تسليم العهدة للسائقين");
if (v45TableExists('rep_driver_handovers')) {
    v45Say('  = جدول rep_driver_handovers (موجود)');
} else {
    Database::exec("
        CREATE TABLE rep_driver_handovers (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            rep_id        INT UNSIGNED NOT NULL              COMMENT 'المندوب اللي سلّم من عهدته',
            permit_id     INT UNSIGNED NULL                  COMMENT 'الإذن اللي البوليصة تابعة له (وصفي)',
            bawali_id     INT UNSIGNED NULL                  COMMENT 'البوليصة — وصفي بحت، البوليصة نفسها ما بتتغيّرش',
            driver_name   VARCHAR(180) NOT NULL              COMMENT 'اسم السائق وقت التسليم (لقطة نصية)',
            car_no        VARCHAR(120) NULL                  COMMENT 'رقم العربية وقت التسليم (لقطة)',
            amount        DECIMAL(14,2) NOT NULL DEFAULT 0   COMMENT 'المسلَّم — بيتخصم من عهدة المندوب وبس',
            handover_date DATE NOT NULL                      COMMENT 'يوم التسليم',
            note          VARCHAR(255) NULL,
            attachment    VARCHAR(255) NULL                  COMMENT 'صورة الاستلام لو رفعها',
            created_by    VARCHAR(120) NULL,
            created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_rdh_rep (rep_id, handover_date),
            KEY idx_rdh_bawali (bawali_id),
            KEY idx_rdh_permit (permit_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='تسليم عهدة لسائق (v45) — بيخصم من عهدة المندوب وبس، معزول تمامًا'
    ");
    v45Say('  + جدول rep_driver_handovers');
}

// FK على المندوب بس (RESTRICT زي v41). البوليصة/الإذن **بلا FK عن قصد** —
// وصفيان، وحذف أي منهم ما ينفعش يكسر سجل تسليم حصل فعلاً (تحذير 3).
$fkExists = (bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE constraint_schema = DATABASE() AND table_name = 'rep_driver_handovers'
       AND constraint_name = 'fk_rdh_rep'");
if ($fkExists) {
    v45Say('  = fk_rdh_rep (موجود)');
} else {
    Database::exec(
        "ALTER TABLE rep_driver_handovers
         ADD CONSTRAINT fk_rdh_rep FOREIGN KEY (rep_id) REFERENCES reps(id) ON DELETE RESTRICT");
    v45Say('  + fk_rdh_rep');
}

// ═══════════════════════════════ 3) تحقّق السلامة
v45Say("\n" . str_repeat('─', 60));
$balanceAfter = Closing::currentBalance();
printf("رصيد الخزنة قبل: %s  |  بعد: %s\n", number_format($balanceBefore, 2), number_format($balanceAfter, 2));
if (abs($balanceAfter - $balanceBefore) > 0.005) {
    v45Say('✗✗ الخزنة اتغيّرت — الهجرة إضافية بحتة، ده مستحيل. راجع فورًا.');
    exit(1);
}
v45Say('✓ الخزنة ما اتغيّرتش.');

$drift = [];
foreach ($custodyBefore as $rid => $bal) {
    $now = \App\Models\RepCustody::balance($rid);
    if (abs($now - $bal) > 0.005) { $drift[] = "المندوب #$rid: $bal → $now"; }
}
if ($drift) {
    v45Say('✗✗ رصيد عهدة اتغيّر: ' . implode(' · ', $drift));
    exit(1);
}
v45Say('✓ أرصدة عهدة كل المناديب ما اتغيّرتش (' . count($custodyBefore) . ' مندوب).');

v45Say("\nتمام. شغّل php bin/verify_v45.php للتأكد.");
