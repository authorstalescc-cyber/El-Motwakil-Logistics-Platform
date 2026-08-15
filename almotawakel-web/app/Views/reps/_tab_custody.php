<?php
/**
 * تبويب «العهدة الميدانية» — محفظة المندوب، **معزولة تمامًا عن الخزنة** (v35).
 *
 * ⚠️ الأرقام دي **مش** جزء من راتبه: هي فلوس تحت إيده يصرفها على الشغل
 *    ويرجّع باقيها. الخلط بينها وبين المستحق له كان بيدّي رقمين متناقضين.
 *
 * @var array $custody @var int $rid
 */
if (empty($custody['ready'])): ?>
  <div class="card mb p5-pad-sm p5-edge-gold-r">
    <?= icon('info', 15) ?> وحدة عهدة المناديب مش مفعّلة.
  </div>
<?php return; endif;

$b = $custody['breakdown'];
?>
<?php partial('stat_grid', ['items' => 4, 'body' => function () use ($custody, $b, $dr) { ?>
  <?php partial('stat', ['label' => 'رصيد محفظته', 'value' => money0($custody['balance'], false),
    'icon' => 'briefcase', 'tone' => 'teal', 'unit' => 'ج', 'drill' => $dr('custody'),
    'title' => 'اللي صرفه من محفظته']); ?>
  <?php partial('stat', ['label' => 'نزّلناله', 'value' => money0($b['deposits'] ?? 0, false),
    'icon' => 'download', 'tone' => 'navy', 'unit' => 'ج', 'drill' => $dr('custody'),
    'title' => 'اللي صرفه من محفظته']); ?>
  <?php partial('stat', ['label' => 'صرف', 'value' => money0($b['spent'] ?? 0, false),
    'icon' => 'trending-down', 'tone' => 'red', 'unit' => 'ج', 'drill' => $dr('custody'),
    'title' => 'اللي صرفه من محفظته']); ?>
  <?php partial('stat', ['label' => 'رجّع', 'value' => money0($b['returned'] ?? 0, false),
    'icon' => 'undo', 'tone' => 'gold', 'unit' => 'ج', 'drill' => $dr('custody'),
    'title' => 'اللي صرفه من محفظته']); ?>
<?php }]); ?>

<div class="two-col">
  <div class="card">
    <div class="section-head rts-head"><?= icon('download', 17) ?> تنزيلات العهدة</div>
    <?php partial('table', [
      'cols'  => [['label' => 'التاريخ'], ['label' => 'المبلغ'], ['label' => 'المصدر'], ['label' => 'البيان']],
      'count' => count($custody['deposits']),
      'wrapClass' => 'rpf-tight', 'empty' => ['title' => 'مفيش تنزيلات'],
      'rows'  => function () use ($custody) {
        foreach ($custody['deposits'] as $d): ?>
          <tr>
            <td><?= e(dfmt((string) ($d['dep_date'] ?? ''))) ?></td>
            <td class="pos"><?= money((float) $d['amount'], false) ?></td>
            <td><?php $bd = \App\Models\RepCustody::sourceBadge($d['source'] ?? null); ?>
                <?= $bd ? '<span class="chip">' . e((string) $bd['label']) . '</span>' : '—' ?></td>
            <td class="txt"><?= e((string) ($d['note'] ?? '—')) ?></td>
          </tr>
        <?php endforeach;
      },
    ]); ?>
  </div>

  <div class="card">
    <div class="section-head rts-head"><?= icon('receipt', 17) ?> تسويات العهدة</div>
    <?php partial('table', [
      'cols'  => [['label' => 'التاريخ'], ['label' => 'المرتجع'], ['label' => 'البيان']],
      'count' => count($custody['settles']),
      'wrapClass' => 'rpf-tight', 'empty' => ['title' => 'مفيش تسويات'],
      'rows'  => function () use ($custody) {
        foreach ($custody['settles'] as $s): ?>
          <tr>
            <td><?= e(dfmt((string) ($s['settle_date'] ?? ''))) ?></td>
            <td><?= money((float) ($s['returned'] ?? 0), false) ?></td>
            <td class="txt"><?= e((string) ($s['note'] ?? '—')) ?></td>
          </tr>
        <?php endforeach;
      },
    ]); ?>
  </div>
</div>

<?php if ($custody['out'] || $custody['in']): ?>
  <div class="card mb">
    <div class="section-head rts-head"><?= icon('shuffle', 17) ?> تحويلات مع الزمايل</div>
    <?php partial('table', [
      'cols'  => [['label' => 'التاريخ'], ['label' => 'الاتجاه'], ['label' => 'الزميل'],
                  ['label' => 'المبلغ'], ['label' => 'البيان']],
      'count' => count($custody['out']) + count($custody['in']),
      'wrapClass' => 'rpf-tight', 'empty' => ['title' => 'مفيش تحويلات'],
      'rows'  => function () use ($custody) {
        foreach ($custody['out'] as $t): ?>
          <tr>
            <td><?= e(dfmt((string) ($t['wallet_date'] ?? $t['transfer_date'] ?? ''))) ?></td>
            <td><span class="chip"><?= icon('trending-down', 12) ?> طالع</span></td>
            <td><?= e((string) ($t['other_name'] ?? '—')) ?></td>
            <td class="neg"><?= money((float) $t['amount'], false) ?></td>
            <td class="txt"><?= e((string) ($t['note'] ?? '—')) ?></td>
          </tr>
        <?php endforeach;
        foreach ($custody['in'] as $t): ?>
          <tr>
            <td><?= e(dfmt((string) ($t['wallet_date'] ?? $t['transfer_date'] ?? ''))) ?></td>
            <td><span class="chip"><?= icon('trending-up', 12) ?> جاي</span></td>
            <td><?= e((string) ($t['other_name'] ?? '—')) ?></td>
            <td class="pos"><?= money((float) $t['amount'], false) ?></td>
            <td class="txt"><?= e((string) ($t['note'] ?? '—')) ?></td>
          </tr>
        <?php endforeach;
      },
    ]); ?>
  </div>
<?php endif; ?>
