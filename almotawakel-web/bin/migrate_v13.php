<?php
/**
 * ترحيل v13 — إصلاح ديون البوالص السالبة الناتجة عن «النولون المؤجَّل».
 *
 *   المشكلة: دين البوليصة = نولون − عهدة − تحويلة − خصم + بياتة. البوليصة التي لم يُدخَل
 *   نولونها بعد كانت تُنتج ديناً سالباً (خصومات بلا نولون) يلوّث أرصدة أصحاب البيان.
 *
 *   الحل (مطبَّق في Calc::bawala + Bill::statusFor): نولون = 0 ⇒ دين = 0 وحالتها «بانتظار النولون».
 *   هذه الهجرة تُعيد حساب الدين + المدفوع/المتبقّي/الحالة لكل البوالص الموجودة وفق القاعدة الجديدة.
 *
 * آمن لإعادة التشغيل (idempotent) — يعيد الحساب من نفس المدخلات في كل مرة.
 * التشغيل:  php bin/migrate_v13.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Calc;
use App\Models\Bill;

function say(string $s, string $c=''){ $x=['ok'=>"\033[32m",'hi'=>"\033[36m",'y'=>"\033[33m",'err'=>"\033[31m"];
    echo ($x[$c]??'').$s."\033[0m\n"; }

say("═══════════════════════════════════════════════", 'hi');
say("  ترحيل نظام المتوكل إلى الإصدار 13", 'hi');
say("  (إصلاح ديون البوالص السالبة — قاعدة النولون المؤجَّل)", 'hi');
say("═══════════════════════════════════════════════", 'hi');

$rows = Database::all('SELECT * FROM bawalis');
$total = count($rows);
say("• عدد البوالص: $total", 'y');

$fixedDebt = 0;   // بوالص تغيّر دَينها
$pending   = 0;   // بوالص صارت «بانتظار النولون»
$negBefore = 0;   // بوالص كانت سالبة الدين قبل الإصلاح

foreach ($rows as $r) {
    $id = (int) $r['id'];
    $oldDebt = round((float) $r['debt'], 2);
    if ($oldDebt < -0.005) { $negBefore++; }

    // إعادة حساب الدين وفق القاعدة الجديدة
    $newDebt = Calc::bawala($r)['debt'];
    if (abs($newDebt - $oldDebt) > 0.005) {
        Database::exec('UPDATE bawalis SET debt = ? WHERE id = ?', [$newDebt, $id]);
        $fixedDebt++;
    }
    // إعادة حساب المدفوع/المتبقّي/الحالة (يضبط «بانتظار النولون» للبوالص بلا نولون)
    Bill::recalc($id);
}

$pending = (int) Database::scalar("SELECT COUNT(*) FROM bawalis WHERE pay_status = 'pending'");
$negAfter = (int) Database::scalar('SELECT COUNT(*) FROM bawalis WHERE debt < -0.005');

say("✓ بوالص كانت سالبة الدين قبل الإصلاح: $negBefore", 'ok');
say("✓ بوالص أُعيد ضبط دَينها: $fixedDebt", 'ok');
say("✓ بوالص أصبحت «بانتظار النولون»: $pending", 'ok');
say(($negAfter === 0 ? "✓ لا توجد ديون سالبة بلا نولون بعد الإصلاح" : "⚠ لا تزال $negAfter بوليصة سالبة (نولونها مُدخَل وخصوماتها تتجاوزه — حالة حقيقية، راجعها)"),
    $negAfter === 0 ? 'ok' : 'y');

say("═══════════════════════════════════════════════", 'hi');
say("  اكتمل ترحيل v13 ✓", 'ok');
say("═══════════════════════════════════════════════", 'hi');
