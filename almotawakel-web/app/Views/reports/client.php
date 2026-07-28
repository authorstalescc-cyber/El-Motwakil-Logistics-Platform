<?php
use App\Core\App;
use App\Models\Bill;
$cur = App::setting('currency','ج.م');
$s   = $profile['summary'];
$initial = mb_substr($profile['name'], 0, 1);
?>
<div class="reports-page">
<div class="toolbar">
  <?php partial('back_link', ['to' => 'reports', 'label' => 'التقارير']); ?>
  <div class="spacer"></div>
  <a class="btn ghost sm" href="<?= url('settlements') ?>"><?= icon('banknote', 14) ?> سداد البوالص</a>
  <a class="btn gold sm" href="#" onclick="window.print();return false"><?= icon('file-text', 14) ?> طباعة</a>
</div>

<div class="card mb cprof-hero">
  <div class="cust-av"><?= e($initial) ?></div>
  <div class="cprof-id">
    <b><?= e($profile['name']) ?></b>
    <span><?= intf($s['trips']) ?> حركة مسجّلة</span>
  </div>
  <div class="cprof-due">
    <em>إجمالي المستحق للعميل</em>
    <b><?= money($s['remaining']) ?></b>
  </div>
</div>

<?php /* ⚠️ الخمسة دول كانوا `.kpi` **أرقام صمّاء بلا أي طريق للتفاصيل**.
         كلهم بيفتحوا دلوقتي **بوالص العميل** — وهي المصدر اللي اتحسبوا منه. */ ?>
<?php
/* ⚠️ `stat_grid` بياخد **عدد الأعمدة** رقمًا و`body` مُصيِّرًا (شوف reports/index). */
$cpStats = [
  ['label' => 'إجمالي النولون',  'value' => intf($s['freight']),  'unit' => $cur, 'icon' => 'banknote',
   'tone' => 'navy',   'drill' => '#cpBills', 'title' => 'بوالص ' . $profile['name']],
  ['label' => 'إجمالي العهد',    'value' => intf($s['custody']),  'unit' => $cur, 'icon' => 'wallet',
   'tone' => 'gold',   'drill' => '#cpBills', 'title' => 'بوالص ' . $profile['name']],
  ['label' => 'إجمالي المصروفات','value' => intf($s['expense']),  'unit' => $cur, 'icon' => 'trending-down',
   'tone' => 'red',    'drill' => '#cpBills', 'title' => 'بوالص ' . $profile['name']],
  ['label' => 'المدفوع للعميل',  'value' => intf($s['paid']),     'unit' => $cur, 'icon' => 'coins',
   'tone' => 'teal',   'drill' => '#cpPays',  'title' => 'دفعات ' . $profile['name']],
  ['label' => 'المتبقي للعميل',  'value' => intf($s['remaining']),'unit' => $cur, 'icon' => 'credit-card',
   'tone' => 'purple', 'drill' => '#cpBills', 'title' => 'بوالص ' . $profile['name']],
];
partial('stat_grid', ['items' => count($cpStats), 'class' => 'mb', 'body' => function () use ($cpStats) {
    foreach ($cpStats as $st) { partial('stat', $st); }
}]);
?>

<?php
/* Chart.js (٢٠٠KB) اتشالت — الرسم بقى SVG/CSS على الخادم وكل عمود بيقول قيمته. */
$cpBars = [];
foreach ($monthNames as $i => $mn) {
    $mm = $profile['monthly'][$i + 1];
    $cpBars[] = ['label' => mb_substr($mn, 0, 3), 'value' => (float) $mm['freight'], 'display' => '',
                 'title' => $mn . ' — نولون ' . money0($mm['freight']) . ' · مدفوعات ' . money0($mm['collected'])];
}
partial('panel', ['title' => 'النولون شهريًا', 'icon' => 'trending-up', 'class' => 'mb',
  'body' => function () use ($cpBars) { partial('mini_bars', ['bars' => $cpBars, 'tone' => 'navy', 'height' => 104]); }]);
?>

<div class="card mb pad">
  <div class="section-head">سجل الحركات — البوالص (<?= count($profile['bills']) ?>)</div>
  <div class="table-wrap"><table class="data">
    <thead><tr>
      <th class="g-identity">التاريخ</th><th class="g-identity">رقم البوليصة</th><th class="g-identity">النوع</th>
      <th class="g-vehicle">الجهة</th><th class="g-vehicle">السيارة</th><th class="g-vehicle">السائق</th>
      <th class="g-revenue">النولون</th><th class="g-expense">المصروف</th>
      <th class="g-result">الدين</th><th class="g-revenue">المدفوع</th><th class="g-expense">المتبقي</th><th class="g-identity">الحالة</th>
    </tr></thead>
    <tbody>
      <?php foreach ($profile['bills'] as $b): ?>
        <tr>
          <td><?= dfmt($b['bill_date']) ?></td>
          <td class="strong"><?= e($b['bill_number']) ?></td>
          <td><span class="pill pill-<?= e($b['bill_type']) ?>"><?= e(Bill::TYPE_LABELS[$b['bill_type']] ?? '') ?></span></td>
          <td><?= e($b['destination']) ?></td>
          <td><?= e($b['car_no']) ?></td>
          <td><?= e($b['driver']) ?></td>
          <td><?= money($b['freight'],false) ?></td>
          <td class="<?= $b['mv_expense']>0?'neg':'' ?>"><?= money($b['mv_expense'],false) ?></td>
          <td><?= money($b['debt'],false) ?></td>
          <td class="pos"><?= money($b['paid'],false) ?></td>
          <td class="<?= $b['remaining']>0?'neg':'' ?>"><?= money($b['remaining'],false) ?></td>
          <td><span class="pill st-<?= e($b['pay_status']) ?>"><?= e(Bill::STATUS_LABELS[$b['pay_status']] ?? '') ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$profile['bills']): ?><tr><td colspan="12"><?php partial('empty', ['icon' => 'truck', 'title' => 'مفيش بوالص']); ?></td></tr><?php endif; ?>
    </tbody>
    <?php if($profile['bills']): ?>
    <tfoot><tr><td colspan="6">الإجمالي</td>
      <td><?= money($s['freight'],false) ?></td><td><?= money($s['expense'],false) ?></td>
      <td><?= money($s['debt'],false) ?></td><td><?= money($s['paid'],false) ?></td><td><?= money($s['remaining'],false) ?></td><td></td></tr></tfoot>
    <?php endif; ?>
  </table></div>
</div>

<?php if (!empty($profile['shamil'])): ?>
<div class="card mb pad">
  <div class="section-head">فواتير «شامل» (<?= count($profile['shamil']) ?>)</div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th class="g-identity">التاريخ</th><th class="g-identity">رقم الفاتورة</th><th class="g-vehicle">الجهة</th>
      <th class="g-revenue">إجمالي الفاتورة</th><th class="g-revenue">المدفوع</th><th class="g-expense">المتبقي</th></tr></thead>
    <tbody>
      <?php foreach ($profile['shamil'] as $sh): ?>
        <tr><td><?= dfmt($sh['invoice_date']) ?></td><td class="strong"><?= e($sh['invoice_no']) ?></td>
          <td><?= e($sh['destination']) ?></td><td><?= money($sh['invoice_total'],false) ?></td>
          <td class="pos"><?= money($sh['payments'],false) ?></td>
          <td class="<?= $sh['account_total']>0?'neg':'' ?>"><?= money($sh['account_total'],false) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<div class="card mb pad">
  <div class="section-head">سجل الدفعات (<?= count($profile['payments']) ?>)</div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th class="g-identity">التاريخ</th><th class="g-identity">رقم البوليصة</th><th class="g-revenue">المبلغ</th><th class="g-identity">ملاحظة</th></tr></thead>
    <tbody>
      <?php $pt=0; foreach ($profile['payments'] as $p): $pt+=$p['amount']; ?>
        <tr><td><?= dfmt($p['pay_date']) ?></td><td><?= e($p['bill_number']) ?></td>
          <td class="pos"><?= money($p['amount'],false) ?></td><td><?= e($p['note']) ?></td></tr>
      <?php endforeach; ?>
      <?php if(!$profile['payments']): ?><tr><td colspan="4"><?php partial('empty', ['icon' => 'banknote', 'title' => 'مفيش دفعات']); ?></td></tr><?php endif; ?>
    </tbody>
    <?php if($profile['payments']): ?><tfoot><tr><td colspan="2">إجمالي المدفوع</td><td><?= money($pt,false) ?></td><td></td></tr></tfoot><?php endif; ?>
  </table></div>
</div>


<?php /* ⚠️ محتوى نافذتَي التفاصيل — **جاهز في الصفحة** (`inline`) لأن البيانات
         محمّلة أصلاً للجداول فوق، فمفيش داعي لطلب تاني للخادم. */ ?>
<div id="cpBills" hidden>
  <?php partial('drill', [
    'how'   => 'كل بوالص ' . $profile['name'] . ' — ومنها اتحسبت أرقام النولون والعهد والمصروف والمتبقّي.',
    'total' => ['label' => 'المتبقّي', 'value' => money0($s['remaining'], false) . ' ' . $cur],
    'count' => count($profile['bills']),
    'empty' => ['title' => 'مفيش بوالص للعميل ده'],
    'body'  => function () use ($profile) {
      foreach (array_slice($profile['bills'], 0, 40) as $b) {
        partial('row_item', [
          'title'  => 'بوليصة ' . ($b['bill_number'] ?: '—'),
          'icon'   => 'truck',
          'tone'   => $b['bill_type'] === 'warid' ? 'warid' : 'sader',
          'url'    => url('bawalis/' . (int) $b['id']),
          'meta'   => array_values(array_filter([dfmt($b['bill_date']), $b['destination'] ?: null, $b['driver'] ?: null])),
          'amount' => money0($b['freight'], false), 'sub' => 'نولون',
        ]);
      }
    },
  ]); ?>
</div>
<div id="cpPays" hidden>
  <?php partial('drill', [
    'how'   => 'الدفعات اللي اتصرفت لـ' . $profile['name'] . '.',
    'total' => ['label' => 'إجمالي المدفوع', 'value' => money0($s['paid'], false) . ' ' . $cur],
    'count' => count($profile['payments']),
    'empty' => ['title' => 'مفيش دفعات مسجّلة'],
    'body'  => function () use ($profile) {
      foreach (array_slice($profile['payments'], 0, 40) as $p) {
        partial('row_item', [
          'title'  => $p['bill_number'] !== '' ? 'بوليصة ' . $p['bill_number'] : 'دفعة',
          'icon'   => 'banknote',
          'meta'   => array_values(array_filter([dfmt($p['pay_date']), $p['note'] ?: null])),
          'amount' => money0($p['amount'], false), 'amount_tone' => 'pos',
        ]);
      }
    },
  ]); ?>
</div>
</div>
