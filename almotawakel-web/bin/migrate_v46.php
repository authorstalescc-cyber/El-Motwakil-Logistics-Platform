<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v46 — تحويل عهدة من مندوب لمندوب.
 *
 * المندوب بيسلّم كاش من عهدته لمندوب تاني في الميدان. الأثر:
 *   • عهدة المُرسِل تنقص بالمبلغ.
 *   • عهدة المُستقبِل تزيد بالمبلغ.
 *
 * ⚠️ **الخزنة والمكتب ما بيتأثروش إطلاقًا** — الفلوس بتتحرّك جوّه محافظ المناديب
 *    بس (زي «تنزيل العهدة» و«تسليم السائق» بالظبط، مالهمش أثر على الخزنة).
 *    فمجموع الفلوس اللي برّه مع المناديب **ما بيتغيّرش** بالتحويل (بينتقل من
 *    محفظة لمحفظة).
 *
 * ⚠️ **العزل بالبناء زي rep_driver_handovers (v45):** جدول خاص
 *    `rep_custody_transfers` كل كود الخزنة عمره ما سمع عنه، فمفيش نقطة مزامنة
 *    جديدة. `bin/verify_v46.php` بيفشل لو أي ملف خزنة ذكر الجدول.
 *
 * إضافي بحت + idempotent. بيتحقّق إن الخزنة **وأرصدة كل المناديب** ما اتغيّروش.
 *
 * التشغيل:  php bin/migrate_v46.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;
use App\Models\RepCustody;

function v46Say(string $m): void { echo $m . "\n"; }

function v46TableExists(string $t): bool
{
    return (bool) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?", [$t]);
}

function v46ConstraintExists(string $t, string $c): bool
{
    return (bool) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.table_constraints
         WHERE constraint_schema = DATABASE() AND table_name = ? AND constraint_name = ?", [$t, $c]);
}

v46Say("v46 — تحويل عهدة من مندوب لمندوب\n" . str_repeat('=', 60));

if (!v46TableExists('reps') || !v46TableExists('rep_custody_deposits')) {
    v46Say('✗ جداول المناديب مش موجودة — شغّل migrate_v35 الأول.');
    exit(1);
}

$balanceBefore = Closing::currentBalance();
$custodyBefore = [];
foreach (Database::all("SELECT id FROM reps") as $r) {
    $custodyBefore[(int) $r['id']] = RepCustody::balance((int) $r['id']);
}

// ── الجدول
if (v46TableExists('rep_custody_transfers')) {
    v46Say('  = جدول rep_custody_transfers (موجود)');
} else {
    Database::exec("
        CREATE TABLE rep_custody_transfers (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            from_rep_id   INT UNSIGNED NOT NULL              COMMENT 'المندوب اللي سلّم من عهدته (تنقص)',
            to_rep_id     INT UNSIGNED NOT NULL              COMMENT 'المندوب اللي استلم (تزيد)',
            amount        DECIMAL(14,2) NOT NULL DEFAULT 0   COMMENT 'المبلغ المحوَّل',
            transfer_date DATE NOT NULL                      COMMENT 'يوم التحويل',
            note          VARCHAR(255) NULL,
            created_by    VARCHAR(120) NULL                  COMMENT 'مين سجّل التحويل (مندوب أو مكتب)',
            created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_rct_from (from_rep_id, transfer_date),
            KEY idx_rct_to (to_rep_id, transfer_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='تحويل عهدة بين المناديب (v46) — داخلي بحت، معزول عن الخزنة'
    ");
    v46Say('  + جدول rep_custody_transfers');
}

// ── FKs (RESTRICT زي v41): حذف مندوب عليه تحويلات مرفوض عشان السجل يفضل سليم
foreach ([['fk_rct_from', 'from_rep_id'], ['fk_rct_to', 'to_rep_id']] as [$name, $col]) {
    if (v46ConstraintExists('rep_custody_transfers', $name)) {
        v46Say("  = $name (موجود)");
    } else {
        Database::exec(
            "ALTER TABLE rep_custody_transfers
             ADD CONSTRAINT $name FOREIGN KEY ($col) REFERENCES reps(id) ON DELETE RESTRICT");
        v46Say("  + $name");
    }
}

// ── تحقّق السلامة
v46Say("\n" . str_repeat('─', 60));
$balanceAfter = Closing::currentBalance();
printf("رصيد الخزنة قبل: %s  |  بعد: %s\n", number_format($balanceBefore, 2), number_format($balanceAfter, 2));
if (abs($balanceAfter - $balanceBefore) > 0.005) {
    v46Say('✗✗ الخزنة اتغيّرت — مستحيل في هجرة إضافية. راجع فورًا.');
    exit(1);
}
v46Say('✓ الخزنة ما اتغيّرتش.');

$drift = [];
foreach ($custodyBefore as $rid => $bal) {
    $now = RepCustody::balance($rid);
    if (abs($now - $bal) > 0.005) { $drift[] = "#$rid: $bal→$now"; }
}
$drift ? v46Say('✗✗ رصيد عهدة اتغيّر: ' . implode(' · ', $drift)) && exit(1)
       : v46Say('✓ أرصدة عهدة كل المناديب ما اتغيّرتش (' . count($custodyBefore) . ' مندوب).');

v46Say("\nتمام. شغّل php bin/verify_v46.php للتأكد.");
