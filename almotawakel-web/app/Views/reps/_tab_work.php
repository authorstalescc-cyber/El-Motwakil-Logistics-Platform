<?php
/**
 * تبويب «الشغل» — كل حاجة المندوب عملها: أذونه · بنوده تحت المراجعة ·
 * عهد البوالص اللي دفعها · تسليماته للسائقين · نثرياته.
 *
 * ⚠️ **عرض بحت**: ولا فورم كتابة هنا. الاعتماد والرفض مكانهم صندوق المراجعة
 *    في `/rep-custody` — نسختان من نفس الإجراء بتفترقا أول تعديل.
 *
 * @var array $work @var int $rid
 */
?>
<?php partial('stat_grid', ['items' => 4, 'body' => function () use ($work, $dr) { ?>
  <?php partial('stat', ['label' => 'أذونات منزّلة له', 'value' => intf(count($work['permits'])),
    'icon' => 'folder', 'tone' => 'navy', 'drill' => $dr('pending'), 'title' => 'بنود تحت المراجعة']); ?>
  <?php partial('stat', ['label' => 'تحت المراجعة', 'value' => intf(count($work['pending'])),
    'icon' => 'clock', 'tone' => 'gold', 'drill' => $dr('pending'), 'title' => 'بنود تحت المراجعة']); ?>
  <?php partial('stat', ['label' => 'عهد بوالص دفعها', 'value' => intf(count($work['bills'])),
    'icon' => 'truck', 'tone' => 'teal', 'drill' => $dr('custody'), 'title' => 'اللي صرفه من محفظته']); ?>
  <?php partial('stat', ['label' => 'تسليمات لسائقين', 'value' => intf(count($work['handovers'])),
    'icon' => 'handshake', 'tone' => 'purple', 'drill' => $dr('custody'), 'title' => 'اللي صرفه من محفظته']); ?>
<?php }]); ?>

<div class="card mb">
  <div class="section-head rts-head"><?= icon('folder', 17) ?> الأذونات المنزّلة له</div>
  <?php partial('table', [
    'cols'  => [['label' => 'الإذن'], ['label' => 'العميل'], ['label' => 'الشركة'],
                ['label' => 'التاريخ'], ['label' => 'الحالة'], ['label' => 'بنوده']],
    'count' => count($work['permits']),
    'empty' => ['title' => 'مفيش أذونات منزّلة له'],
    'rows'  => function () use ($work) {
      foreach ($work['permits'] as $a): ?>
        <tr>
          <td><a href="<?= e(url('permits/' . (int) $a['permit_id'])) ?>"><?= e((string) ($a['permit_no'] ?: '#' . (int) $a['permit_id'])) ?></a></td>
          <td class="txt"><?= e((string) ($a['client'] ?? '—')) ?></td>
          <td class="txt"><?= e((string) ($a['company'] ?? '—')) ?></td>
          <td><?= $a['permit_date'] ? e(dfmt((string) $a['permit_date'])) : '—' ?></td>
          <td><span class="chip"><?= ($a['status'] ?? '') === 'done' ? 'خلص' : 'شغّال' ?></span></td>
          <td><?= intf((int) ($a['subs'] ?? 0)) ?></td>
        </tr>
      <?php endforeach;
    },
  ]); ?>
</div>

<div class="two-col">
  <div class="card">
    <div class="section-head rts-head"><?= icon('truck', 17) ?> عهد بوالص دفعها من محفظته</div>
    <?php partial('table', [
      'cols'  => [['label' => 'البوليصة'], ['label' => 'التاريخ'], ['label' => 'السائق'], ['label' => 'العهدة']],
      'count' => count($work['bills']),
      'empty' => ['title' => 'مفيش عهد بوالص'],
      'rows'  => function () use ($work) {
        foreach ($work['bills'] as $b): ?>
          <tr>
            <td><a href="<?= e(url('bawalis/' . (int) $b['id'])) ?>"><?= e((string) ($b['bill_number'] ?: '#' . (int) $b['id'])) ?></a></td>
            <td><?= $b['bill_date'] ? e(dfmt((string) $b['bill_date'])) : '—' ?></td>
            <td class="txt"><?= e((string) ($b['driver'] ?: '—')) ?></td>
            <td class="neg"><?= money((float) $b['custody'], false) ?></td>
          </tr>
        <?php endforeach;
      },
    ]); ?>
  </div>

  <div class="card">
    <div class="section-head rts-head"><?= icon('handshake', 17) ?> تسليمات للسائقين</div>
    <?php partial('table', [
      'cols'  => [['label' => 'التاريخ'], ['label' => 'السائق'], ['label' => 'المبلغ'], ['label' => 'البيان']],
      'count' => count($work['handovers']),
      'empty' => ['title' => 'مفيش تسليمات'],
      'rows'  => function () use ($work) {
        foreach ($work['handovers'] as $h): ?>
          <tr>
            <td><?= $h['handover_date'] ? e(dfmt((string) $h['handover_date'])) : '—' ?></td>
            <td class="txt"><?= e((string) ($h['driver'] ?? $h['driver_name'] ?? '—')) ?></td>
            <td class="neg"><?= money((float) $h['amount'], false) ?></td>
            <td class="txt"><?= e((string) ($h['note'] ?? '—')) ?></td>
          </tr>
        <?php endforeach;
      },
    ]); ?>
  </div>
</div>

<div class="card mb">
  <div class="section-head rts-head"><?= icon('coins', 17) ?> نثرياته</div>
  <?php partial('table', [
    'cols'  => [['label' => 'التاريخ'], ['label' => 'النوع'], ['label' => 'البيان'], ['label' => 'المبلغ']],
    'count' => count($work['misc']),
    'empty' => ['title' => 'مفيش نثريات'],
    'rows'  => function () use ($work) {
      foreach ($work['misc'] as $m): ?>
        <tr>
          <td><?= $m['exp_date'] ? e(dfmt((string) $m['exp_date'])) : '—' ?></td>
          <td><span class="chip"><?= icon(\App\Models\RepMiscKind::icon($m['misc_kind'] ?? null), 12) ?>
              <?= e(\App\Models\RepMiscKind::label($m['misc_kind'] ?? null)) ?></span></td>
          <td class="txt"><?= e((string) ($m['note'] ?? '—')) ?></td>
          <td class="neg"><?= money((float) $m['amount'], false) ?></td>
        </tr>
      <?php endforeach;
    },
  ]); ?>
</div>
