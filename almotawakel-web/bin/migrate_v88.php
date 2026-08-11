<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v88 — بنود يدوية في راتب الفترة (زيادة · خصم · **جزاء**) + بروفايل المندوب.
 *
 * ═══ اللزمة (طلب المالك 2026-08-11) ═══
 * «المفروض يكون في بروفايل أراجع بنود المندوب أو أضيفله بند معيّن سواء خصم أو
 *  زيادة قبل اعتماد مرتب كل مندوب + الجزاءات في حال أردت إنزال جزاء.»
 *
 * البنود اليدوية كانت **موجودة** في `rep_entries` من v21، بس كانت **برّه حساب
 * الفترة**: تسجّل خصمًا في بروفايل المندوب فرصيده يتغيّر، وشاشة الرواتب تفضل
 * تقول رقم تاني — نفس مصيدة «كارت يقول رقم وكشف يقول رقم تاني».
 *
 * ═══ ⚠️⚠️ الترحيل ده **صفر أثر مالي** ═══
 * عمود واحد بقيمة ٠ افتراضية على جدول لقطة. ولا مليم بيتحرّك، والفترات
 * المعتمدة قبله بتفضل بأرقامها بالحرف (`adjustments = 0` = اللي كانت عليه).
 *
 * ⚠️ **نوع `penalty` مش محتاج ترحيل**: `rep_entries.kind` عمود `VARCHAR(24)`
 *    بلا `ENUM` ولا قيد، والفصل بين «خصم» و«جزاء» **وسم** — المعاملة المالية
 *    واحدة (الاتنين `DEBIT` مش كاش). التحقّق تحت بيثبت إنه في السجل.
 *
 * التشغيل:  php bin/migrate_v88.php [--dry-run]
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Payroll;
use App\Models\Rep;

$v88Dry    = in_array('--dry-run', $argv ?? [], true);
$v88Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
$v88Say = static function (string $m) use ($v88Direct): void { if ($v88Direct) { echo $m . "\n"; } };

$v88HasCol = static fn (string $t, string $c): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
$v88HasTbl = static fn (string $t): bool => (bool) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = ?', [$t]);

$v88Up = static function () use ($v88Dry, $v88Say, $v88HasCol, $v88HasTbl): void {
    $pdo = Database::conn();

    if (!$v88HasTbl('payroll_lines')) {
        $v88Say('  ✗ payroll_lines مش موجود — شغّل bin/migrate_v87.php الأول.');
        return;
    }

    if (!$v88HasCol('payroll_lines', 'adjustments')) {
        if (!$v88Dry) {
            /* ⚠️ `AFTER expense_share` عشان ترتيب الأعمدة يمشي مع ترتيب
               الحساب نفسه (ثابت → سلف → كومسيون → مصروف → تعديلات → صافي). */
            $pdo->exec("ALTER TABLE payroll_lines
                        ADD COLUMN adjustments DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER expense_share");
        }
        $v88Say('  ✓ عمود: payroll_lines.adjustments');
    } else {
        $v88Say('  • payroll_lines.adjustments موجود');
    }

    if (!$v88Dry) { Payroll::refreshReady(); }
};

/** ⚠️ التحقّق على **حالة بنيوية دائمة** لا لقطة بيانات. */
$v88Verify = static function () use ($v88Say, $v88HasCol): bool {
    $ok = true;

    if (!$v88HasCol('payroll_lines', 'adjustments')) {
        $v88Say('✗ عمود payroll_lines.adjustments ناقص.'); $ok = false;
    } else { $v88Say('  ✓ عمود التعديلات اليدوية موجود'); }

    if (!isset(Rep::KINDS['penalty'])) {
        $v88Say('✗ نوع «جزاء» مش في سجل بنود المندوب.'); $ok = false;
    } else { $v88Say('  ✓ نوع «جزاء» متسجّل'); }

    if (!in_array('penalty', Rep::DEBIT_KINDS, true)) {
        $v88Say('✗ «جزاء» مش من البنود المخصومة — كان هيزوّد رصيد المندوب!'); $ok = false;
    } else { $v88Say('  ✓ و«جزاء» بيقلّل المستحق مش بيزوّده'); }

    /* ⚠️⚠️ الحارس الأهم: الجزاء **مش كاش**. لو دخل `CASH_KINDS` كان هيتخصم من
       الخزنة ومن المصروفات العامة كأنه فلوس خرجت — وهو مجرد خصم من مستحق. */
    if (in_array('penalty', Rep::CASH_KINDS, true)) {
        $v88Say('✗ «جزاء» وقع في أنواع الكاش — هيتخصم من الخزنة بالغلط.'); $ok = false;
    } else { $v88Say('  ✓ و«جزاء» ما بيمسّش خزنة'); }

    // البنود اليدوية اللي بتدخل حساب الفترة = كل نوع مش كاش (محسوبة لا مكتوبة)
    $adj = Payroll::adjustKinds();
    if (!in_array('penalty', $adj, true)) {
        $v88Say('✗ «جزاء» مش داخل حساب الفترة.'); $ok = false;
    } else { $v88Say('  ✓ و«جزاء» داخل حساب راتب الفترة'); }
    foreach (Rep::CASH_KINDS as $c) {
        if (in_array($c, $adj, true)) {
            $v88Say("✗ نوع الكاش «{$c}» دخل التعديلات — تحسيب مزدوج."); $ok = false;
        }
    }

    return $ok;
};

if ($v88Direct) {
    echo 'v88 — بنود يدوية في راتب الفترة' . ($v88Dry ? '  (تجربة — مفيش كتابة)' : '') . "\n";
    echo str_repeat('=', 62) . "\n";
    $v88Up();
    if ($v88Dry) { echo "\nشغّل من غير --dry-run عشان يتطبّق.\n"; exit(0); }
    if ($v88Verify() !== true) { fwrite(STDERR, "فشل التحقق بعد تطبيق v88.\n"); exit(1); }
    echo "\n✓ تمّ.\n";
}

return ['up' => $v88Up, 'verify' => $v88Verify];
