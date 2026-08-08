<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * نقل تسوية بداية النظام الجديد من 2026-07-29 إلى **2026-07-28** (طلب المالك).
 *
 * ═══ ليه ═══
 * تصحيح Q4 (docs/16) خصم 10,930 من الدرج عشان رقم الخزنة يبقى كاش الدرج الحقيقي.
 * حطيته يوم **29/7** عشان ما ألمسش يومًا متقفلًا. المالك راجعه وقال:
 *   «عاوز اللي يظهر في الرصيد المرحّل 56,780 — افتح يوم 28 وحط التسوية فيه.»
 * وهو **محقّ**: المبلغ ده فعلاً كان مع المناديب **آخر يوم 28**، فمكانه الصح
 * يوم 28، والرصيد المرحّل ساعتها بيقول كاش الدرج الحقيقي بدل الرقم المجمَّع.
 *
 * ⚠️ **وتحفّظي الأصلي كان على حاجة تانية خالص.** كنت خايف من **كتابة الرقم
 *    بالإيد** في `daily_closings.closing_balance`، لأن `refreshLockedChainFrom`
 *    بتعيد حساب اليوم من الصفوف الخام وكانت هترجّعه 67,710 بالسكوت. بس إحنا هنا
 *    بنحطّ **صفًّا حقيقيًّا مؤرَّخًا جوّه اليوم** وبنقفل اليوم من جديد — فإعادة
 *    الحساب بتلاقي الصف وبتطلّع 56,780 كل مرة. يعني **دايم وسليم**.
 *
 * ═══ اللي بيحصل، جوّه معاملة واحدة ═══
 *   ① فتح إقفال 2026-07-28 (هو آخر يوم متقفل، فالفتح مسموح بالترتيب)
 *   ② نقل صف التسوية في `manual_custody` من 29/7 إلى 28/7
 *   ③ إقفال 2026-07-28 من جديد → بيتحسب من الصفوف الخام فيطلع 56,780
 *
 * ⚠️ **الرصيد الحالي ما بيتغيّرش** — التسوية بتتنقل جوّه النطاق مش بتتشال:
 *      قبل: مرحّل 67,710 ثم −10,930 يوم 29  ⇒ الحالي 56,055
 *      بعد: مرحّل 56,780 والتسوية جوّاه       ⇒ الحالي 56,055
 *    والسكربت بيرفض يكمّل لو الرقم اتغيّر.
 *
 * ⚠️ **تنزيلات المناديب المرحَّلة (`carry`) ما بتتلمسش** — هي **موجبة** ومكانها
 *    صح يوم 29 (أول يوم في النظام الجديد): عبدالرحمن +2,930 والسيد +8,000.
 *
 * التشغيل:  php bin/move_carry_adjustment.php --dry-run
 *           php bin/move_carry_adjustment.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;
use App\Models\RepCustody;

const FROM_DATE = '2026-07-29';
const TO_DATE   = '2026-07-28';
const ADJ_NOTE  = 'تسوية بداية النظام الجديد — الكاش اللي كان مع المناديب';

$dry = in_array('--dry-run', $argv, true);

$snap = static fn(): array => [
    'safe'  => round(Closing::currentBalance(), 2),
    'reps'  => round(RepCustody::totalOutstanding(), 2),
    'close' => round((float) Database::scalar(
        'SELECT closing_balance FROM daily_closings WHERE close_date = ?', [TO_DATE]), 2),
];
$show = static function (string $t, array $s): void {
    printf("  %-8s مرحّل %s = %12s   ·   الخزنة الحالية %12s   ·   المناديب %12s\n",
        $t, TO_DATE, number_format($s['close'], 2),
        number_format($s['safe'], 2), number_format($s['reps'], 2));
};

echo "\n═══ نقل تسوية بداية النظام الجديد لـ" . TO_DATE . " ═══"
   . ($dry ? '   (تجربة — كله بيترجع)' : '') . "\n\n";

$row = Database::row(
    'SELECT * FROM manual_custody WHERE note = ? ORDER BY id DESC LIMIT 1', [ADJ_NOTE]);
if (!$row) {
    fwrite(STDERR, "✗ مش لاقي صف التسوية. شغّل bin/activate_safe_rule.php الأول.\n");
    exit(1);
}
if ((string) $row['cust_date'] === TO_DATE) {
    echo "  = التسوية متسجّلة على " . TO_DATE . " خلاص — مفيش حاجة تتعمل.\n";
    exit(0);
}

$before = $snap();
$show('قبل:', $before);
printf("  صف التسوية #%d بمبلغ %s على %s\n",
    (int) $row['id'], number_format((float) $row['amount'], 2), $row['cust_date']);

$last = Closing::lastClosedDate();
if ($last !== TO_DATE) {
    fwrite(STDERR, "✗ آخر يوم متقفل هو {$last} مش " . TO_DATE
        . " — الفتح لازم يبدأ من الأحدث. اتوقف.\n");
    exit(1);
}

$pdo = Database::conn();
$pdo->beginTransaction();
$fail = null;
try {
    // ① فتح اليوم
    $r = Closing::reopenDay(TO_DATE);
    if (empty($r['ok'])) { throw new RuntimeException('فشل فتح ' . TO_DATE . ': ' . ($r['msg'] ?? '')); }
    echo "  ① اتفتح إقفال " . TO_DATE . "\n";

    // ② نقل الصف
    Database::exec('UPDATE manual_custody SET cust_date = ? WHERE id = ?', [TO_DATE, (int) $row['id']]);
    echo "  ② التسوية اتنقلت من " . FROM_DATE . " لـ" . TO_DATE . "\n";

    // ③ إقفال اليوم من جديد — بيتحسب من الصفوف الخام
    $c = Closing::closeDay(TO_DATE, (string) ($row['created_by'] ?? 'تصحيح docs/16 Q4'));
    if (empty($c['ok'])) { throw new RuntimeException('فشل إقفال ' . TO_DATE . ': ' . ($c['msg'] ?? '')); }
    // ⚠️ `closeDay` بترجّع `ok`+`msg` بس — مفيش مفتاح `closing`. قراءته كانت
    //    بتطبع «0.00» وتبان كأن اليوم اتقفل على صفر (إنذار كاذب مقلق).
    echo "  ③ " . ($c['msg'] ?? 'اتقفل') . "\n\n";

    $after = $snap();
    $show('بعد:', $after);

    /* ⚠️ الحُرّاس التلاتة — أي واحد يفشل معناه إن التصحيح غيّر حاجة ما كانش
       المفروض يغيّرها، والسكربت بيرجع كل حاجة. */
    if (abs($after['safe'] - $before['safe']) > 0.005) {
        throw new RuntimeException('الخزنة الحالية اتغيّرت (' . number_format($before['safe'], 2)
            . ' ← ' . number_format($after['safe'], 2) . ') — التسوية اتنقلت مش اتكررت المفروض.');
    }
    if (abs($after['reps'] - $before['reps']) > 0.005) {
        throw new RuntimeException('أرصدة المناديب اتغيّرت — التسوية مالهاش أي علاقة بيهم.');
    }
    $want = round($before['close'] + (float) $row['amount'], 2);
    if (abs($after['close'] - $want) > 0.005) {
        throw new RuntimeException('المرحّل طلع ' . number_format($after['close'], 2)
            . ' والمتوقع ' . number_format($want, 2));
    }
    echo "  ✓ الخزنة الحالية وأرصدة المناديب ما اتغيّروش — التسوية اتنقلت بس\n";
    echo "  ✓ المرحّل بقى " . number_format($after['close'], 2) . " = كاش الدرج الحقيقي\n";

    /* ⚠️ **فحص الدوام**: إعادة حساب اليوم من الصفوف الخام (اللي بتحصل لوحدها مع
       «الدخول الحر») لازم تطلّع **نفس الرقم** — ده الفرق بين صف حقيقي مؤرَّخ
       وبين رقم متكتوب بالإيد كان هيتبخّر. */
    Closing::refreshLockedChainFrom(TO_DATE);
    $again = $snap();
    if (abs($again['close'] - $after['close']) > 0.005) {
        throw new RuntimeException('إعادة الحساب غيّرت المرحّل — التصحيح مش دايم!');
    }
    echo "  ✓ إعادة حساب اليوم بتطلّع نفس الرقم — التصحيح دايم\n";

    if ($dry) { throw new RuntimeException('__DRY__'); }
    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    if ($e->getMessage() !== '__DRY__') { $fail = $e; }
}

if ($fail) { fwrite(STDERR, "\n✗ " . $fail->getMessage() . "\n"); exit(1); }
echo $dry
    ? "\n(تجربة) كله اترجع. شغّل من غير --dry-run عشان يتطبّق.\n"
    : "\n✓ اتطبّق.\n";
