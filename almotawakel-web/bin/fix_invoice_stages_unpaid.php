<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * تصحيح لمرّة واحدة (قرار المالك 2026-08-01):
 * البنود اللي اتضافت على الأذونات **تلقائيًّا من شاشة الفاتورة** تتعلّم
 * «غير مدفوع» (`permit_stages.unpaid = 1`) فما تتخصمش من خزنة المكتب.
 *
 * ⚠️ ليه «غير مدفوع» (v57) مش حذف:
 *    البند ظاهر في تايم لاين الإذن وفي فاتورة العميل المُصدَرة خلاص. حذفه
 *    بيشيله من المستند وبيسيب الفاتورة المطبوعة بتقول حاجة والنظام بيقول
 *    حاجة تانية. `unpaid = 1` بيسيبه مكانه بالضبط ويشيل أثره المالي بس.
 *
 * ⚠️ الوسم متنافي مع «مدفوع بالفيزا» (قاعدة v57) — البند الموسوم فيزا
 *    بيتخطّى، وهو أصلاً مش بيتخصم من الخزنة فمفيش حاجة تتصلّح فيه.
 *
 * ⚠️ اليوم المتقفل بيتخطّى افتراضيًّا: تعديله بيغيّر رصيدًا ثبّته المالك
 *    وراجعه. `--include-locked` بيسمح بيه عن قصد (قرار منفصل).
 *
 * التشغيل:  php bin/fix_invoice_stages_unpaid.php --dry-run
 *           php bin/fix_invoice_stages_unpaid.php --apply [--day=YYYY-MM-DD]
 *           php bin/fix_invoice_stages_unpaid.php --apply --include-locked
 *           php bin/fix_invoice_stages_unpaid.php --off --day=YYYY-MM-DD   (تراجع)
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Audit;
use App\Models\Closing;
use App\Models\Permit;

$args    = array_slice($argv, 1);
$apply   = in_array('--apply', $args, true);
$off     = in_array('--off', $args, true);
$locked  = in_array('--include-locked', $args, true);
$day     = null;
foreach ($args as $a) { if (strncmp($a, '--day=', 6) === 0) { $day = substr($a, 6); } }

if (!$apply && !$off && !in_array('--dry-run', $args, true)) {
    fwrite(STDERR, "لازم تحدّد --dry-run أو --apply أو --off\n");
    exit(2);
}
if ($day !== null && !preg_match('~^\d{4}-\d{2}-\d{2}$~', $day)) {
    fwrite(STDERR, "قيمة --day لازم تكون YYYY-MM-DD\n");
    exit(2);
}
if (!Permit::unpaidReady()) {
    fwrite(STDERR, "عمود `permit_stages.unpaid` مش موجود — شغّل migrate_v57 الأول.\n");
    exit(1);
}

/* البنود المستهدَفة = اللي `invoiceStore` أنشأها (سجل العمليات هو المصدر
   الوحيد لتحديدها — مفيش عمود بيميّزها في `permit_stages`). */
$ids = array_map('intval', array_column(Database::all(
    "SELECT record_id FROM audit_logs
      WHERE entity = 'permit_stages' AND action = 'create'
        AND description LIKE '%عند إصدار الفاتورة%'"), 'record_id'));

if (!$ids) { echo "مفيش أي بند اتضاف من شاشة الفاتورة — مفيش حاجة تتعمل.\n"; exit(0); }

$in   = implode(',', $ids);
$want = $off ? 0 : 1;
$rows = Database::all(
    "SELECT s.id, s.permit_id, s.entry_date, s.note, s.amount, s.is_visa, s.unpaid,
            s.paid_by_rep_id, p.permit_no
       FROM permit_stages s LEFT JOIN permits p ON p.id = s.permit_id
      WHERE s.id IN ($in) ORDER BY s.entry_date DESC, s.id DESC");

$targets = [];
$skipped = [];
foreach ($rows as $r) {
    $why = null;
    if ($day !== null && $r['entry_date'] !== $day)          { continue; }
    if ((int)$r['unpaid'] === $want)                          { $why = 'متعلّم خلاص'; }
    elseif ((int)$r['is_visa'] === 1)                         { $why = 'مدفوع بالفيزا (متنافي مع الوسم)'; }
    elseif (!$locked && Closing::guard($r['entry_date']))     { $why = 'يوم متقفل 🔒'; }
    if ($why !== null) { $skipped[] = [$r, $why]; continue; }
    $targets[] = $r;
}

$before = Closing::currentBalance();
printf("رصيد الخزنة قبل: %s\n", number_format($before, 2));
printf("المستهدَف: %d بند%s\n\n", count($targets), $day ? " على يوم {$day}" : '');

$sum = 0.0;
foreach ($targets as $r) {
    $sum += (float)$r['amount'];
    printf("  #%-5d %s  إذن %-16s  %-22s  %10s\n",
        $r['id'], $r['entry_date'], (string)($r['permit_no'] ?: $r['permit_id']),
        mb_substr((string)$r['note'], 0, 22), number_format((float)$r['amount'], 2));
}
printf("\nإجمالي المبالغ: %s\n", number_format($sum, 2));
foreach ($skipped as [$r, $why]) {
    printf("  ⤷ اتخطّى #%d (%s) — %s\n", $r['id'], $r['entry_date'], $why);
}

if (!$targets) { echo "\nمفيش حاجة تتغيّر.\n"; exit(0); }

/* ⚠️ الـ`--dry-run` بيكتب فعلاً جوّه معاملة ثم بيرجع — المعاينة اللي بتتخطّى
      الكتابة بتدّي رقمًا نظريًّا مش الرقم اللي هيحصل (مصيدة موثَّقة). */
$pdo = Database::conn();
$pdo->beginTransaction();
try {
    foreach ($targets as $r) {
        Database::exec('UPDATE permit_stages SET unpaid = ? WHERE id = ?', [$want, (int)$r['id']]);
        Audit::log('update', 'permit_stages', (int)$r['id'],
            ['unpaid' => (int)$r['unpaid']], ['unpaid' => $want],
            ($want
                ? 'تعليم البند «غير مدفوع» — اتضاف تلقائيًّا من شاشة الفاتورة فما يتخصمش من الخزنة'
                : 'إلغاء تعليم «غير مدفوع» عن بند اتضاف من شاشة الفاتورة')
            . ' — «' . (string)$r['note'] . '» بقيمة ' . number_format((float)$r['amount'], 2),
            ['permit_id' => (int)$r['permit_id'], 'permit_no' => $r['permit_no'],
             'record_date' => $r['entry_date'], 'fix' => 'invoice_stages_unpaid']);
    }
    $after = Closing::currentBalance();
    $delta = $after - $before;
    printf("\nرصيد الخزنة بعد: %s   (الفرق %+s)\n", number_format($after, 2), number_format($delta, 2));

    /* ⚠️ الفرق لازم يساوي مجموع البنود بالمليم — أي اختلاف معناه إن حاجة تانية
          اتحرّكت، والأأمن ساعتها إننا نرجع. */
    $expected = $want ? $sum : -$sum;
    if (abs($delta - $expected) > 0.001) {
        throw new RuntimeException('الفرق (' . number_format($delta, 2) . ') مش مساوي المتوقّع ('
            . number_format($expected, 2) . ') — اترجع كل حاجة.');
    }
    echo "✓ الفرق مطابق للمتوقّع بالمليم.\n";

    if ($apply || $off) { $pdo->commit(); echo "✅ اتطبّق.\n"; }
    else                { $pdo->rollBack(); echo "🔎 تجربة بس — ولا صف اتغيّر.\n"; }
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    fwrite(STDERR, "✗ " . $e->getMessage() . "\n");
    exit(1);
}
