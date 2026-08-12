<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v91 — الرقم المسلسل في مستند العميل بقى فريدًا **لكل عميل** لا لكل النظام.
 *
 * ═══ اللزمة (بلاغ المالك 2026-08-12) ═══
 * «عند إدخال رقم كشف للعميل بيقولي رقم الكشف موجود، علمًا المفروض هذا رقم كشف
 *  لا يتكرر للعميل فقط ومش لكل العملاء.»
 *
 * الخانة اسمها «رقم مسلسل خاص بالعميل» — مرجع العميل نفسه («كشف رقم ٧ عند
 * فلان»)، فطبيعي عميلان مختلفان يبقى عندهما نفس الرقم. القيد اللي دخل في v31
 * (`uq_pinv_serial_no` على العمود لوحده) كان بيحجز الرقم على النظام كله،
 * فأول عميل ياخد «١» يمنع كل باقي العملاء منه — منع إصدار مشروع.
 *
 * ═══ اللي بيتعمل ═══
 *   ١. تعبئة `agent_id` الناقص من اسم اللقطة (هو أساس النطاق الجديد).
 *   ٢. رفع الفريد العام `uq_pinv_serial_no`.
 *   ٣. فريد مركّب `uq_pinv_agent_serial (agent_id, serial_no)` = عميل واحد
 *      ما ياخدش نفس الرقم مرتين، وعميلان مختلفان ياخدوه عادي.
 *   ٤. `idx_serial` (فهرس عادي على العمود) بيفضل للبحث النصّي.
 *
 * ⚠️ **صفر أثر مالي**: قيد فهرسة بحت — ولا صفّ اتغيّر رقمه ولا مليم اتحرّك.
 *    والتوسعة في اتجاه السماح لا المنع، فالبيانات القايمة كلها بتعدّي من
 *    القيد الجديد بالضرورة (اللي كان فريدًا عامًّا فريدٌ لكل عميل تلقائيًّا).
 *
 * ⚠️ الطبقة التانية للحراسة في الموديل: `PermitInvoice::serialExists()` —
 *    **نفس النطاق بالظبط** (بترجع لاسم اللقطة للصفوف اللي `agent_id` بتاعها
 *    فاضي، لأن الفريد في MySQL بيسيب NULL يتكرّر). الاتنين لازم يتغيّروا مع بعض.
 *
 * التشغيل:  php bin/migrate_v91.php [--dry-run]
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

$v91Dry    = in_array('--dry-run', $argv ?? [], true);
$v91Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
$v91Say = static function (string $m) use ($v91Direct): void { if ($v91Direct) { echo $m . "\n"; } };

$v91HasTbl = static fn (string $t): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
$v91HasCol = static fn (string $t, string $c): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
$v91HasIdx = static fn (string $t, string $i): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.statistics
      WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$t, $i]);

$v91Up = static function () use ($v91Dry, $v91Say, $v91HasTbl, $v91HasCol, $v91HasIdx): void {
    $pdo = Database::conn();

    if (!$v91HasTbl('permit_invoices')) {
        $v91Say('  ✗ permit_invoices مش موجود — شغّل bin/migrate_v10.php الأول.');
        return;
    }
    if (!$v91HasCol('permit_invoices', 'agent_id')) {
        $v91Say('  ✗ عمود agent_id ناقص — شغّل bin/migrate_v31.php الأول.');
        return;
    }

    // ①  ربط الصفوف القديمة باسم العميل القانوني — النطاق الجديد قايم عليه.
    if (!$v91Dry) {
        $pdo->exec('UPDATE permit_invoices i JOIN agents a ON a.name = i.client
                    SET i.agent_id = a.id WHERE i.agent_id IS NULL');
    }
    $orphans = (int) Database::scalar(
        "SELECT COUNT(*) FROM permit_invoices
          WHERE agent_id IS NULL AND serial_no IS NOT NULL AND serial_no <> ''");
    $v91Say($orphans > 0
        ? "  • {$orphans} مستند برقم مسلسل بلا ربط عميل — حراسته في الموديل بالاسم"
        : '  ✓ كل مستند برقم مسلسل مربوط بعميل');

    // ②  تصادم محتمل قبل القيد الجديد (المفروض صفر — العام كان أضيق).
    $clash = (int) Database::scalar(
        "SELECT COUNT(*) FROM (
            SELECT agent_id, serial_no FROM permit_invoices
             WHERE serial_no IS NOT NULL AND serial_no <> '' AND agent_id IS NOT NULL
             GROUP BY agent_id, serial_no HAVING COUNT(*) > 1
         ) t");
    if ($clash > 0) {
        $v91Say("  ✗ في {$clash} رقم مسلسل متكرّر عند نفس العميل — اتصلّح الأول.");
        return;
    }

    // ③  تبديل الفريد: من العمود لوحده → (العميل + الرقم).
    if ($v91HasIdx('permit_invoices', 'uq_pinv_serial_no')) {
        if (!$v91Dry) { $pdo->exec('ALTER TABLE permit_invoices DROP INDEX uq_pinv_serial_no'); }
        $v91Say('  ✓ اترفع الفريد العام uq_pinv_serial_no');
    } else {
        $v91Say('  • الفريد العام مرفوع أصلاً');
    }

    if (!$v91HasIdx('permit_invoices', 'uq_pinv_agent_serial')) {
        if (!$v91Dry) {
            $pdo->exec('ALTER TABLE permit_invoices
                        ADD UNIQUE KEY uq_pinv_agent_serial (agent_id, serial_no)');
        }
        $v91Say('  ✓ فريد مركّب uq_pinv_agent_serial (agent_id, serial_no)');
    } else {
        $v91Say('  • الفريد المركّب موجود');
    }

    // ④  فهرس البحث على العمود لوحده (كان بيتوفّر ضمنًا من الفريد المرفوع).
    if (!$v91HasIdx('permit_invoices', 'idx_serial')) {
        if (!$v91Dry) { $pdo->exec('ALTER TABLE permit_invoices ADD KEY idx_serial (serial_no)'); }
        $v91Say('  ✓ فهرس البحث idx_serial');
    } else {
        $v91Say('  • فهرس idx_serial موجود');
    }
};

/** ⚠️ التحقّق على **حالة بنيوية دائمة** لا لقطة بيانات. */
$v91Verify = static function () use ($v91Say, $v91HasIdx): bool {
    $ok = true;

    if ($v91HasIdx('permit_invoices', 'uq_pinv_serial_no')) {
        $v91Say('✗ الفريد العام لسه موجود — الرقم لسه محجوز على كل العملاء.'); $ok = false;
    } else { $v91Say('  ✓ مفيش فريد عام على الرقم المسلسل'); }

    if (!$v91HasIdx('permit_invoices', 'uq_pinv_agent_serial')) {
        $v91Say('✗ الفريد المركّب (عميل + رقم) ناقص.'); $ok = false;
    } else { $v91Say('  ✓ الفريد المركّب (عميل + رقم) فعّال'); }

    // القيد لازم يكون على العمودين بالترتيب ده بالذات — قيد على عمود واحد بنفس الاسم بيخدع.
    $cols = array_column(Database::all(
        "SELECT column_name AS c FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = 'permit_invoices'
            AND index_name = 'uq_pinv_agent_serial' ORDER BY seq_in_index"), 'c');
    if ($cols !== ['agent_id', 'serial_no']) {
        $v91Say('✗ أعمدة الفريد المركّب مش (agent_id, serial_no).'); $ok = false;
    }

    if (!$v91HasIdx('permit_invoices', 'idx_serial')) {
        $v91Say('✗ فهرس البحث على الرقم المسلسل ناقص.'); $ok = false;
    } else { $v91Say('  ✓ فهرس البحث موجود'); }

    return $ok;
};

if ($v91Direct) {
    echo 'v91 — الرقم المسلسل فريد لكل عميل' . ($v91Dry ? '  (تجربة — مفيش كتابة)' : '') . "\n";
    echo str_repeat('=', 62) . "\n";
    $v91Up();
    if ($v91Dry) { echo "\nشغّل من غير --dry-run عشان يتطبّق.\n"; exit(0); }
    if ($v91Verify() !== true) { fwrite(STDERR, "فشل التحقق بعد تطبيق v91.\n"); exit(1); }
    echo "\n✓ تمّ.\n";
}

return ['up' => $v91Up, 'verify' => $v91Verify];
