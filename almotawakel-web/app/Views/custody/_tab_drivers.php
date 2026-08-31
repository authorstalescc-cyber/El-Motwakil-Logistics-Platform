<?php
/**
 * تبويب «عهد السائقين» — الوجه اللي مالوش شاشة قبل كده.
 *
 * بيجاوب على سؤالين ما كانوش ظاهرين في أي مكان:
 *   ① العهدة المسلَّمة للسائق كام، والمخصوم من الدرج كام (بعد قاعدة الـ١٠٠)؟
 *   ② **المحتجز** كام — وهو الجزء اللي ما خرجش للسائق وفضل في الدرج، وهو نفسه
 *      **مصدر كومسيون المناديب**. مكانش ليه أي شاشة تعرضه.
 *
 * ⚠️⚠️ **الصفوف من نفس `Custody::movements()` بتاعة تبويب الحركات** — بفلترة
 *    قسم `custody` وبس. استعلام تاني للبوالص كان معناه رقمان لنفس العهدة،
 *    وواحد فيهم هيتقادم أول ما شرط يتضاف في `Closing` (نفس مرض `ledger()`).
 *
 * ⚠️ **المحتجز من `Custody::retained()`** — المصدر الوحيد. ممنوع تكتب
 *    `min(custody,100)` بإيدك: المحتجز مش دايمًا = المعدّل (عهدة ٦٠ ومعدّل ١٠٠
 *    ⇒ المحتجز ٦٠، والسيارة المعفاة ⇒ صفر).
 *
 * متغيّرات: $move $from $to $tab $rate $cd
 */
use App\Models\Custody;

$rows = [];
$sumGross = $sumOut = $sumKept = 0.0;
foreach ($move['rows'] as $r) {
    if ($r['group'] !== 'custody') { continue; }
    $gross = (float) ($r['gross'] ?? 0);
    $out   = abs((float) $r['signed']);
    $kept  = Custody::retained($gross, $r['car'] ?? null, $r['date']);
    $rows[] = $r + ['gross_v' => $gross, 'out_v' => $out, 'kept_v' => $kept,
                    'exempt' => Custody::isExempt($r['car'] ?? null)];
    $sumGross += $gross; $sumOut += $out; $sumKept += $kept;
}
?>
<?php include __DIR__ . '/_period.php'; ?>

<?php if ($move['capped']): ?>
  <div class="cs-alert">
    <?= icon('info', 15) ?> الفترة أطول من <?= intf(\App\Models\SafeInventory::MAX_ROW_DAYS) ?> يوم — اختار فترة أقصر.
  </div>
<?php elseif ($move['truncated']): ?>
  <div class="cs-alert">
    <?= icon('info', 15) ?> الصفوف مقطوعة عند <?= intf(count($move['rows'])) ?> من <?= intf($move['rows_total']) ?> — العهد دي مش كل الفترة.
  </div>
<?php endif; ?>

<?php partial('stat_grid', ['items' => 3, 'class' => 'mb',
  'body' => function () use ($sumGross, $sumOut, $sumKept, $rate, $cd) { ?>
  <?php partial('stat', [
    'label' => 'العهدة المسلَّمة للسائقين', 'value' => intf($sumGross),
    'icon'  => 'truck', 'tone' => 'navy', 'drill' => $cd('driver'), 'title' => 'عهد السائقين',
  ]); ?>
  <?php partial('stat', [
    'label' => 'المخصوم من الدرج', 'value' => intf($sumOut),
    'unit'  => 'بعد خصم ' . intf($rate),
    'icon'  => 'trending-down', 'tone' => 'red', 'drill' => $cd('driver'), 'title' => 'عهد السائقين',
  ]); ?>
  <?php /* ⚠️ ده الرقم اللي المالك ما كانش بيشوفه في أي مكان. */ ?>
  <?php partial('stat', [
    'label' => 'المحتجز (فضل في الدرج)', 'value' => intf($sumKept),
    'unit'  => 'مصدر كومسيون المناديب',
    'icon'  => 'wallet', 'tone' => 'gold', 'drill' => $cd('retained'), 'title' => 'المحتجز من كل بوليصة',
  ]); ?>
<?php }]); ?>

<?php partial('panel', [
  'title'  => 'عهد السائقين في الفترة',
  'icon'   => 'truck',
  'badge'  => $rows ? ['text' => intf(count($rows)), 'tone' => 'navy'] : null,
  'scroll' => true,
  'body'   => function () use ($rows) {
    if (!$rows) {
      partial('empty', ['emoji' => '🚚', 'title' => 'مفيش عهد سائقين في الفترة دي']);
      return;
    }
    foreach ($rows as $r) {
      $meta = [];
      if ($r['ref'])   { $meta[] = ['icon' => 'file-text', 'text' => $r['ref']]; }
      if ($r['car'])   { $meta[] = ['icon' => 'truck', 'text' => $r['car']]; }
      $meta[] = ['icon' => 'wallet', 'text' => 'المسلَّم ' . money($r['gross_v'], false)];
      /* ⚠️ السيارة المعفاة عهدتها بتتخصم **كاملة** ومحتجزها صفر — يعني
         بوليصتها ما جمّعتش كومسيون رغم إن المندوب اشتغل عليها. لازم تتقال. */
      $meta[] = $r['exempt']
        ? ['icon' => 'alert', 'text' => 'سيارة معفاة — بلا احتجاز']
        : ['icon' => 'briefcase', 'text' => 'محتجز ' . money($r['kept_v'], false)];
      if ($r['by_rep']) { $meta[] = ['icon' => 'user', 'text' => 'دفعها ' . $r['by_rep']]; }
      partial('row_item', [
        'title'  => $r['party'] ?: 'سائق',
        'icon'   => 'truck',
        'url'    => $r['link'] ? url($r['link']) : null,
        'meta'   => $meta,
        'amount' => '- ' . money($r['out_v'], false),
        'amount_tone' => 'ex',
      ]);
    }
  },
]); ?>
