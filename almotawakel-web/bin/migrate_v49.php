<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v49 — عهدة المناديب بقت **عهدة يوم** (قرار المالك 2026-07-25).
 *
 * القاعدة الجديدة: كل يوم عمل له عهدة مستقلة تمامًا عن اليوم اللي قبله واللي بعده.
 *
 *     عهدة يوم D للمندوب R
 *       = المنزَّل بتاريخ D + المحوَّل له في D
 *       − المصروف بتاريخ D − المرتجع في D − المحوَّل منه في D
 *
 * **مفيش ترحيل** — الفاضل من إمبارح ما بيدخلش عهدة النهاردة (قرار المالك: كل يوم
 * بيبدأ من صفر). الرصيد التراكمي (RepCustody::balance بلا يوم) فضل زي ما هو
 * بالحرف لأنه «الفلوس اللي برّه مع المندوب فعلاً» وبتقراه التسويات والكارت
 * والداشبورد — العهدة اليومية **عدسة جديدة فوقه مش بديل عنه**.
 *
 * ⚠️ الهجرة دي **بنيوية بحتة**: مفيش جدول جديد ولا عمود مالي جديد، لأن كل مصادر
 *    عهدة المندوب مؤرَّخة أصلاً (dep_date/entry_date/exp_date/handover_date/
 *    transfer_date/settle_date). العهدة اليومية بتتحسب من نفس الصفوف بفلتر تاريخ،
 *    فمفيش أي رقم بيتخزَّن مرتين ومفيش نقطة مزامنة جديدة ممكن تتنسي.
 *
 * اللي بيتغيّر في القاعدة:
 *   1. `rep_custody_deposits.dep_date` كان **يقبل NULL** — والتنزيل بلا تاريخ
 *      مالوش يوم يتحسب عليه، فكان هيختفي من كل الأيام. بيتعبّى من تاريخ التسجيل
 *      ثم بيبقى NOT NULL.
 *   2. فهرسان للقراءة اليومية: `permit_stages(paid_by_rep_id, entry_date)` و
 *      `rep_submissions(rep_id, entry_date)`.
 *
 * ⚠️ **مفيش أي أثر مالي** — الهجرة بتتحقّق إن رصيد الخزنة ما اتغيّرش.
 * idempotent — عادي تشغّلها أكتر من مرة.
 *
 * التشغيل:  php bin/migrate_v49.php        (أو تلقائيًّا من bin/migration.php)
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;

/** رصيد الخزنة قبل أي كتابة — بيتقارن في verify. */
$v49BalanceBefore = Closing::currentBalance();

$v49TableExists = static fn(string $t): bool => (bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = ?", [$t]);

$v49IndexExists = static fn(string $t, string $i): bool => (bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?", [$t, $i]);

$v49ColumnNullable = static fn(string $t, string $c): ?string => Database::scalar(
    "SELECT is_nullable FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?", [$t, $c]) ?: null;

$v49Say = static function (string $line): void {
    // الصمت مش خيار: مخرجات الترحيل بتتسجّل في سجل الأحداث كدليل تنفيذ.
    echo $line . PHP_EOL;
};

$v49Up = static function () use ($v49TableExists, $v49IndexExists, $v49ColumnNullable, $v49Say): void {
    // ── 1. تنزيلات العهدة لازم يكون لها يوم
    if ($v49TableExists('rep_custody_deposits')) {
        $orphans = (int) Database::scalar(
            "SELECT COUNT(*) FROM rep_custody_deposits WHERE dep_date IS NULL");
        if ($orphans > 0) {
            // تاريخ التسجيل هو أقرب تقدير أمين لليوم اللي نزلت فيه العهدة.
            Database::exec(
                "UPDATE rep_custody_deposits
                 SET dep_date = DATE(created_at)
                 WHERE dep_date IS NULL AND created_at IS NOT NULL");
            // احتياط لصف قديم بلا created_at (مستحيل عمليًّا، لكن NOT NULL بترفضه).
            Database::exec(
                "UPDATE rep_custody_deposits SET dep_date = CURDATE() WHERE dep_date IS NULL");
            $v49Say("  ✎ اتعبّى تاريخ $orphans تنزيل عهدة كان بلا يوم.");
        } else {
            $v49Say('  = كل تنزيلات العهدة ليها تاريخ.');
        }

        if ($v49ColumnNullable('rep_custody_deposits', 'dep_date') === 'YES') {
            Database::exec(
                "ALTER TABLE rep_custody_deposits
                 MODIFY dep_date DATE NOT NULL COMMENT 'يوم العهدة — كل يوم عهدة مستقلة (v49)'");
            $v49Say('  + rep_custody_deposits.dep_date بقى إلزاميًّا.');
        } else {
            $v49Say('  = rep_custody_deposits.dep_date إلزامي بالفعل.');
        }
    } else {
        $v49Say('  ! جدول rep_custody_deposits مش موجود — اتخطّى (شغّل migrate_v35 الأول).');
    }

    // ── 2. فهارس القراءة اليومية (كل استعلامات العهدة بقت بفلتر يوم)
    foreach ([
        ['permit_stages',   'idx_stage_rep_date', '(paid_by_rep_id, entry_date)'],
        ['rep_submissions', 'idx_sub_rep_date',   '(rep_id, entry_date)'],
    ] as [$table, $index, $cols]) {
        if (!$v49TableExists($table)) { $v49Say("  ! جدول $table مش موجود — اتخطّى."); continue; }
        if ($v49IndexExists($table, $index)) { $v49Say("  = فهرس $index موجود."); continue; }
        Database::exec("ALTER TABLE `$table` ADD INDEX `$index` $cols");
        $v49Say("  + فهرس $index على $table.");
    }
};

$v49Verify = static function () use (
    $v49TableExists, $v49IndexExists, $v49ColumnNullable, $v49BalanceBefore, $v49Say
): bool {
    $ok = true;
    $fail = static function (string $why) use (&$ok, $v49Say): void {
        $ok = false;
        $v49Say("  ✗ $why");
    };

    if ($v49TableExists('rep_custody_deposits')) {
        if ($v49ColumnNullable('rep_custody_deposits', 'dep_date') !== 'NO') {
            $fail('dep_date لسه بيقبل NULL — العهدة اليومية محتاجة يوم لكل تنزيل.');
        }
        if ((int) Database::scalar("SELECT COUNT(*) FROM rep_custody_deposits WHERE dep_date IS NULL") > 0) {
            $fail('لسه فيه تنزيلات عهدة بلا تاريخ.');
        }
    }
    foreach ([
        ['permit_stages', 'idx_stage_rep_date'],
        ['rep_submissions', 'idx_sub_rep_date'],
    ] as [$table, $index]) {
        if ($v49TableExists($table) && !$v49IndexExists($table, $index)) {
            $fail("فهرس $index مش موجود على $table.");
        }
    }

    // العزل المالي: الهجرة بنيوية، فرصيد الخزنة ممنوع يتحرّك ولا مليم.
    $after = Closing::currentBalance();
    if (abs($after - $v49BalanceBefore) > 0.005) {
        $fail('رصيد الخزنة اتغيّر (' . number_format($v49BalanceBefore, 2)
            . ' ← ' . number_format($after, 2) . ') — الهجرة دي ممنوع يكون لها أثر مالي.');
    }

    if ($ok) { $v49Say('  ✓ التحقق بعد التطبيق عدّى.'); }
    return $ok;
};

// ⚠️ الملف ده بيتنفّذ بطريقتين، ولازم يشتغل صح في الاتنين:
//   • `bin/migration.php apply-one` بيعمل require وبيشغّل up/verify بنفسه (v49+).
//   • `install.php` (runPending) بيشغّل الملف كسكربت مستقل عبر proc_open وبيرمي
//     القيمة المُرجَعة — فلو ما نفّذناش بنفسنا هنا، الترحيل هيتسجّل بلا ما يتطبّق.
$v49Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
if ($v49Direct) {
    echo "v49 — عهدة المناديب بقت عهدة يوم\n" . str_repeat('=', 60) . "\n";
    $v49Up();
    if ($v49Verify() !== true) {
        fwrite(STDERR, "فشل التحقق بعد تطبيق v49.\n");
        exit(1);
    }
    echo "تمام.\n";
}

return ['up' => $v49Up, 'verify' => $v49Verify];
