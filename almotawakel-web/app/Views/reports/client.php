<?php
use App\Core\App;
use App\Models\Bill;
$cur = App::setting('currency','ج.م');
$s   = $profile['summary'];
$initial = mb_substr($profile['name'], 0, 1);
?>
<div class="reports-page">
<div class="page-actions">
  <a class="btn ghost" href="<?= url('reports') ?>">‹ رجوع للتقارير</a>
  <a class="btn ghost" href="<?= url('settlements') ?>">💵 سداد البوالص</a>
  <span style="flex:1"></span>
  <a class="btn gold" href="#" onclick="window.print();return false">🖨️ طباعة البروفايل</a>
</div>

<div class="card mb" style="padding:22px">
  <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
    <div class="cust-av"><?= e($initial) ?></div>
    <div>
      <div style="font-size:1.5rem;font-weight:800;color:var(--navy)"><?= e($profile['name']) ?></div>
      <div style="color:var(--grey);font-weight:600">عميل — <?= intf($s['trips']) ?> حركة مسجّلة</div>
    </div>
    <span style="flex:1"></span>
    <div style="text-align:center;background:var(--navy);color:#fff;border-radius:14px;padding:14px 26px">
      <div style="font-size:.8rem;opacity:.85;font-weight:700">إجمالي المستحق للعميل</div>
      <div style="font-size:1.8rem;font-weight:800;color:var(--gold)"><?= money($s['remaining']) ?></div>
    </div>
  </div>
</div>

<div class="kpi-grid mb">
  <div class="kpi navy"><div class="lbl">إجمالي النولون</div><div class="val"><?= intf($s['freight']) ?><span class="cur"><?= e($cur) ?></span></div></div>
  <div class="kpi gold"><div class="lbl">إجمالي العهد</div><div class="val"><?= intf($s['custody']) ?><span class="cur"><?= e($cur) ?></span></div></div>
  <div class="kpi red"><div class="lbl">إجمالي المصروفات</div><div class="val"><?= intf($s['expense']) ?><span class="cur"><?= e($cur) ?></span></div></div>
  <div class="kpi teal"><div class="lbl">إجمالي المدفوع للعميل</div><div class="val"><?= intf($s['paid']) ?><span class="cur"><?= e($cur) ?></span></div></div>
  <div class="kpi purple"><div class="lbl">المتبقي للعميل</div><div class="val"><?= intf($s['remaining']) ?><span class="cur"><?= e($cur) ?></span></div></div>
</div>

<div class="chart-card full mb"><h3>التعامل الشهري (النولون مقابل المدفوعات)</h3><div class="chart-box"><canvas id="chCust"></canvas></div></div>

<div class="card mb" style="padding:18px">
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
          <td style="font-weight:700"><?= e($b['bill_number']) ?></td>
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
      <?php if(!$profile['bills']): ?><tr><td colspan="12"><div class="empty">لا بوالص</div></td></tr><?php endif; ?>
    </tbody>
    <?php if($profile['bills']): ?>
    <tfoot><tr><td colspan="6">الإجمالي</td>
      <td><?= money($s['freight'],false) ?></td><td><?= money($s['expense'],false) ?></td>
      <td><?= money($s['debt'],false) ?></td><td><?= money($s['paid'],false) ?></td><td><?= money($s['remaining'],false) ?></td><td></td></tr></tfoot>
    <?php endif; ?>
  </table></div>
</div>

<?php if (!empty($profile['shamil'])): ?>
<div class="card mb" style="padding:18px">
  <div class="section-head">فواتير «شامل» (<?= count($profile['shamil']) ?>)</div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th class="g-identity">التاريخ</th><th class="g-identity">رقم الفاتورة</th><th class="g-vehicle">الجهة</th>
      <th class="g-revenue">إجمالي الفاتورة</th><th class="g-revenue">المدفوع</th><th class="g-expense">المتبقي</th></tr></thead>
    <tbody>
      <?php foreach ($profile['shamil'] as $sh): ?>
        <tr><td><?= dfmt($sh['invoice_date']) ?></td><td style="font-weight:700"><?= e($sh['invoice_no']) ?></td>
          <td><?= e($sh['destination']) ?></td><td><?= money($sh['invoice_total'],false) ?></td>
          <td class="pos"><?= money($sh['payments'],false) ?></td>
          <td class="<?= $sh['account_total']>0?'neg':'' ?>"><?= money($sh['account_total'],false) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<div class="card mb" style="padding:18px">
  <div class="section-head">سجل الدفعات (<?= count($profile['payments']) ?>)</div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th class="g-identity">التاريخ</th><th class="g-identity">رقم البوليصة</th><th class="g-revenue">المبلغ</th><th class="g-identity">ملاحظة</th></tr></thead>
    <tbody>
      <?php $pt=0; foreach ($profile['payments'] as $p): $pt+=$p['amount']; ?>
        <tr><td><?= dfmt($p['pay_date']) ?></td><td><?= e($p['bill_number']) ?></td>
          <td class="pos"><?= money($p['amount'],false) ?></td><td><?= e($p['note']) ?></td></tr>
      <?php endforeach; ?>
      <?php if(!$profile['payments']): ?><tr><td colspan="4"><div class="empty">لا دفعات مسجّلة بعد</div></td></tr><?php endif; ?>
    </tbody>
    <?php if($profile['payments']): ?><tfoot><tr><td colspan="2">إجمالي المدفوع</td><td><?= money($pt,false) ?></td><td></td></tr></tfoot><?php endif; ?>
  </table></div>
</div>

</div>
<script src="<?= url('assets/js/chart.umd.js') ?>"></script>
<script>
const CP = <?= js_json([
  'labels'    => $monthNames,
  'freight'   => array_map(fn($m)=>round($profile['monthly'][$m]['freight'],2), range(1,12)),
  'collected' => array_map(fn($m)=>round($profile['monthly'][$m]['collected'],2), range(1,12)),
]) ?>;
Chart.defaults.font.family = "'Segoe UI','Tahoma',sans-serif";
Chart.defaults.plugins.legend.rtl = true;
Chart.defaults.plugins.tooltip.rtl = true;
const fmt = (v)=> new Intl.NumberFormat('en-US').format(Math.round(v));
new Chart(document.getElementById('chCust'),{type:'bar',
  data:{labels:CP.labels,datasets:[
    {label:'النولون',data:CP.freight,backgroundColor:'#15355B',borderRadius:5},
    {label:'المدفوعات',data:CP.collected,backgroundColor:'#14746F',borderRadius:5}]},
  options:{responsive:true,maintainAspectRatio:false,scales:{y:{ticks:{callback:fmt}}},plugins:{legend:{position:'top'}}}});
</script>
