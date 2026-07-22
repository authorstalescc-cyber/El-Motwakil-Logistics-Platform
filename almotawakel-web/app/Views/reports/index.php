<?php
use App\Core\App;
$cur = App::setting('currency','ج.م');

// إجماليات الأداء الشهري (تشغيلي: نولون/عهد/مصاريف/مدفوعات)
$sumRow = ['freight'=>0,'custody'=>0,'expenses'=>0,'collections'=>0];
foreach ($monthly as $m) foreach ($sumRow as $k=>$_) $sumRow[$k]+=$m[$k];

// بطاقات المؤشرات — حقائق تشغيلية بلا أرباح
$cards = [
  ['freight',    'إجمالي النولون',   'navy'],
  ['custody',    'إجمالي العهد',      'gold'],
  ['expenses',   'إجمالي المصاريف',   'red'],
  ['collections','إجمالي المدفوعات',  'green'],
  ['outstanding','المتبقي للعملاء',   'purple'],
];

// إجماليات جداول التبويبات
$cusTot = ['freight'=>0,'custody'=>0,'expense'=>0,'paid'=>0,'remaining'=>0];
foreach ($customers as $c){ foreach($cusTot as $k=>$_) $cusTot[$k]+=$c[$k]; }
$expCatTotal = 0; foreach ($expenses['categories'] as $r) $expCatTotal += $r['value'];
// إجمالي المصروفات = نفس رقم صفحة المصروفات (حركات + أذونات + يدوي + تخليص + فواتير)
// «سداد المديونيات» ليس مصروفاً فلا يدخل هذا الإجمالي.
$expGrand = $expCatTotal + $expenses['takhlis'] + $expenses['shamil']
          + ($expenses['permits'] ?? 0) + ($expenses['manual'] ?? 0);
$permitTypes = ['sader'=>'إذن (صادر)','warid'=>'شهادة (وارد)'];

$hasFilter = ($from!=='' || $to!=='');
$printQs = http_build_query(array_filter(['from'=>$from,'to'=>$to]));
?>
<div class="reports-page">
<form method="get" id="filterForm" class="filter-bar" aria-label="فلترة التقارير">
  <input type="hidden" name="tab" id="tabInput" value="overview">
  <div class="fld"><label for="report-from">من تاريخ</label><input id="report-from" type="date" name="from" value="<?= e($from) ?>"></div>
  <div class="fld"><label for="report-to">إلى تاريخ</label><input id="report-to" type="date" name="to" value="<?= e($to) ?>"></div>
  <button class="btn navy sm" type="submit">🔎 تطبيق</button>
  <a class="btn ghost sm" href="<?= url('reports') ?>">↺ تفريغ</a>
  <span class="presets" id="presets">
    <span class="lbl">اختصارات:</span>
    <button type="button" data-preset="today">النهاردة</button>
    <button type="button" data-preset="week">الأسبوع ده</button>
    <button type="button" data-preset="month">الشهر ده</button>
    <button type="button" data-preset="quarter">الربع ده</button>
    <button type="button" data-preset="year">السنة دي</button>
    <button type="button" data-preset="all">كل الفترات</button>
  </span>
  <span style="flex:1"></span>
  <a class="btn gold sm" href="<?= url('reports/print'.($printQs?'?'.$printQs:'')) ?>" target="_blank">🖨️ طباعة / PDF</a>
</form>

<?php if ($hasFilter): ?>
<div class="filter-tag">
  📅 التقرير معروض للفترة:
  <strong><?= e($from!==''?dfmt($from):'البداية') ?></strong> ← <strong><?= e($to!==''?dfmt($to):'النهاية') ?></strong>
  <a href="<?= url('reports') ?>">✕ إلغاء الفلتر</a>
</div>
<?php endif; ?>

<div class="kpi-grid mb">
  <?php foreach ($cards as [$k,$lbl,$cls]): ?>
    <div class="kpi <?= $cls ?>"><div class="lbl"><?= $lbl ?></div>
      <div class="val"><?= intf($totals[$k]) ?><span class="cur"><?= e($cur) ?></span></div></div>
  <?php endforeach; ?>
</div>

<div class="tabs" role="tablist">
  <button type="button" class="tab active" data-tab="overview">📊 نظرة عامة</button>
  <button type="button" class="tab" data-tab="customers">👥 العملاء</button>
  <button type="button" class="tab" data-tab="payments">💵 المدفوعات</button>
  <button type="button" class="tab" data-tab="expenses">💸 المصاريف</button>
  <button type="button" class="tab" data-tab="movements">🔄 الحركات</button>
  <button type="button" class="tab" data-tab="permits">🗂️ الأذون</button>
</div>

<!-- ═══════════ نظرة عامة ═══════════ -->
<section class="tab-panel active" data-panel="overview">
  <div class="chart-card full mb"><h3>الأداء الشهري — <?= e($year) ?></h3><div class="chart-box"><canvas id="chMonthly"></canvas></div></div>

  <div class="card mb" style="padding:18px">
    <div class="section-head">الأداء الشهري — <?= e($year) ?></div>
    <div class="table-wrap"><table class="data">
      <thead><tr><th class="g-identity">الشهر</th><th class="g-revenue">النولون</th><th class="g-revenue">العهد</th><th class="g-expense">مصروف تشغيلي</th><th class="g-revenue">المدفوعات</th></tr></thead>
      <tbody>
        <?php foreach ($monthNames as $idx=>$mn): $m=$monthly[$idx+1]; ?>
          <tr><td style="font-weight:700"><?= $mn ?></td>
            <td><?= money($m['freight'],false) ?></td>
            <td><?= money($m['custody'],false) ?></td>
            <td class="neg"><?= money($m['expenses'],false) ?></td>
            <td class="pos"><?= money($m['collections'],false) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td>الإجمالي</td><td><?= money($sumRow['freight'],false) ?></td><td><?= money($sumRow['custody'],false) ?></td>
        <td><?= money($sumRow['expenses'],false) ?></td><td><?= money($sumRow['collections'],false) ?></td></tr></tfoot>
    </table></div>
  </div>

  <div class="two-col">
    <div class="card" style="padding:18px">
      <div class="tbl-head"><div class="section-head">النولون حسب الشركة</div>
        <input type="search" class="table-search" data-target="tblCompany" aria-label="بحث في جدول الشركات" placeholder="بحث باسم الشركة…"></div>
      <div class="table-wrap"><table class="data" id="tblCompany">
        <thead><tr><th class="g-identity">الشركة</th><th class="g-revenue">النولون</th></tr></thead>
        <tbody>
          <?php foreach ($byCompany as $r): ?>
            <tr><td style="font-weight:700"><a class="lnk" href="<?= url('reports/client?name='.urlencode($r['name'])) ?>"><?= e($r['name']) ?></a></td>
              <td><?= money($r['freight'],false) ?></td></tr>
          <?php endforeach; ?>
          <?php if(!$byCompany): ?><tr><td colspan="2"><div class="empty">لا بيانات</div></td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>

    <div class="card" style="padding:18px">
      <div class="tbl-head"><div class="section-head">مستحقات العملاء (المتبقي لهم)</div>
        <input type="search" class="table-search" data-target="tblDebts" aria-label="بحث في جدول مستحقات العملاء" placeholder="بحث باسم العميل…"></div>
      <div class="table-wrap"><table class="data" id="tblDebts">
        <thead><tr><th class="g-identity">العميل</th><th class="g-revenue">إجمالي التعامل</th><th class="g-expense">المتبقي له</th><th class="g-identity">البروفايل</th></tr></thead>
        <tbody>
          <?php foreach ($debts as $r): ?>
            <tr><td style="font-weight:700"><?= e($r['name']) ?></td><td><?= money($r['invoices'],false) ?></td>
              <td class="<?= $r['remaining']>0?'neg':'' ?>"><?= money($r['remaining'],false) ?></td>
              <td><a class="btn ghost sm" href="<?= url('reports/client?name='.urlencode($r['name'])) ?>">عرض ›</a></td></tr>
          <?php endforeach; ?>
          <?php if(!$debts): ?><tr><td colspan="4"><div class="empty">لا بيانات</div></td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>
</section>

<!-- ═══════════ العملاء (تشغيلي) ═══════════ -->
<section class="tab-panel" data-panel="customers">
  <div class="card" style="padding:18px">
    <div class="tbl-head"><div class="section-head">العملاء — النولون والعهد والمدفوعات</div>
      <input type="search" class="table-search" data-target="tblCustomers" aria-label="بحث في جدول العملاء" placeholder="بحث باسم العميل…"></div>
    <div class="table-wrap"><table class="data" id="tblCustomers">
      <thead><tr>
        <th class="g-identity">العميل</th><th class="g-identity">عدد الحركات</th>
        <th class="g-revenue">النولون</th><th class="g-revenue">العهد</th><th class="g-expense">المصروف</th>
        <th class="g-revenue">المدفوع له</th><th class="g-expense">المتبقي له</th><th class="g-identity">البروفايل</th>
      </tr></thead>
      <tbody>
        <?php foreach ($customers as $c): ?>
          <tr>
            <td style="font-weight:700"><a class="lnk" href="<?= url('reports/client?name='.urlencode($c['name'])) ?>"><?= e($c['name']) ?></a></td>
            <td><?= intf($c['trips']) ?></td>
            <td><?= money($c['freight'],false) ?></td>
            <td><?= money($c['custody'],false) ?></td>
            <td class="neg"><?= money($c['expense'],false) ?></td>
            <td class="pos"><?= money($c['paid'],false) ?></td>
            <td class="<?= $c['remaining']>0?'neg':'' ?>"><?= money($c['remaining'],false) ?></td>
            <td><a class="btn ghost sm" href="<?= url('reports/client?name='.urlencode($c['name'])) ?>">عرض ›</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$customers): ?><tr><td colspan="8"><div class="empty">لا بيانات</div></td></tr><?php endif; ?>
      </tbody>
      <?php if($customers): ?>
      <tfoot><tr><td>الإجمالي</td><td></td>
        <td><?= money($cusTot['freight'],false) ?></td><td><?= money($cusTot['custody'],false) ?></td>
        <td><?= money($cusTot['expense'],false) ?></td><td><?= money($cusTot['paid'],false) ?></td>
        <td><?= money($cusTot['remaining'],false) ?></td><td></td></tr></tfoot>
      <?php endif; ?>
    </table></div>
  </div>
</section>

<!-- ═══════════ المدفوعات ═══════════ -->
<section class="tab-panel" data-panel="payments">
  <div class="card" style="padding:18px">
    <div class="tbl-head"><div class="section-head">المدفوعات — دفعات خارجة للعملاء (<?= intf($payments['count']) ?>)</div>
      <input type="search" class="table-search" data-target="tblPays" aria-label="بحث في جدول المدفوعات" placeholder="بحث برقم بوليصة أو عميل…"></div>
    <div class="table-wrap"><table class="data" id="tblPays">
      <thead><tr><th class="g-identity">التاريخ</th><th class="g-identity">رقم البوليصة</th><th class="g-identity">العميل / الجهة</th><th class="g-revenue">المبلغ</th><th class="g-identity">ملاحظة</th></tr></thead>
      <tbody>
        <?php foreach ($payments['rows'] as $p): ?>
          <tr><td><?= dfmt($p['d']) ?></td><td style="font-weight:700"><?= e($p['bill_number']) ?></td>
            <td style="text-align:right"><?= e($p['party']) ?></td>
            <td class="pos"><?= money($p['amount'],false) ?></td><td><?= e($p['note']) ?></td></tr>
        <?php endforeach; ?>
        <?php if(!$payments['rows']): ?><tr><td colspan="5"><div class="empty">لا مدفوعات في هذه الفترة</div></td></tr><?php endif; ?>
      <?php if($payments['rows']): ?>
      <tfoot><tr><td colspan="3">إجمالي المدفوعات</td><td><?= money($payments['total'],false) ?></td><td></td></tr></tfoot>
      <?php endif; ?>
    </table></div>
    <?php if(count($payments['rows'])>=500): ?><div style="padding:10px 4px;color:var(--grey)">عُرض أول 500 دفعة — ضيّق الفترة لعرض غيرها.</div><?php endif; ?>
  </div>
</section>

<!-- ═══════════ المصاريف ═══════════ -->
<section class="tab-panel" data-panel="expenses">
  <div class="two-col">
    <div class="chart-card"><h3>توزيع المصروفات حسب البند</h3><div class="chart-box"><canvas id="chExp"></canvas></div></div>
    <div class="card" style="padding:18px">
      <div class="section-head">تفصيل المصروفات</div>
      <div class="table-wrap"><table class="data">
        <thead><tr><th class="g-identity">البند</th><th class="g-identity">عدد المرات</th><th class="g-expense">القيمة</th><th class="g-result">النسبة</th></tr></thead>
        <tbody>
          <?php foreach ($expenses['categories'] as $r): $p=$expGrand!=0?$r['value']/$expGrand:0; ?>
            <tr><td style="font-weight:700"><?= e($r['label']) ?></td><td><?= intf($r['cnt']) ?></td>
              <td class="neg"><?= money($r['value'],false) ?></td><td><?= pct($p) ?></td></tr>
          <?php endforeach; ?>
          <?php if($expenses['takhlis']>0): ?>
            <tr><td style="font-weight:700">مصروفات التخليص</td><td>—</td><td class="neg"><?= money($expenses['takhlis'],false) ?></td><td><?= pct($expGrand!=0?$expenses['takhlis']/$expGrand:0) ?></td></tr>
          <?php endif; ?>
          <?php if($expenses['shamil']>0): ?>
            <tr><td style="font-weight:700">مصروفات الفواتير (شامل)</td><td>—</td><td class="neg"><?= money($expenses['shamil'],false) ?></td><td><?= pct($expGrand!=0?$expenses['shamil']/$expGrand:0) ?></td></tr>
          <?php endif; ?>
          <?php if(($expenses['permits'] ?? 0)>0): ?>
            <tr><td style="font-weight:700">مصروفات الأذونات</td><td>—</td><td class="neg"><?= money($expenses['permits'],false) ?></td><td><?= pct($expGrand!=0?$expenses['permits']/$expGrand:0) ?></td></tr>
          <?php endif; ?>
          <?php if(($expenses['manual'] ?? 0)>0): ?>
            <tr><td style="font-weight:700">مصاريف يدوية</td><td>—</td><td class="neg"><?= money($expenses['manual'],false) ?></td><td><?= pct($expGrand!=0?$expenses['manual']/$expGrand:0) ?></td></tr>
          <?php endif; ?>
          <?php if(!$expenses['categories'] && $expGrand==0): ?><tr><td colspan="4"><div class="empty">لا بيانات</div></td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr><td colspan="2">إجمالي المصروفات</td><td><?= money($expGrand,false) ?></td><td>100%</td></tr></tfoot>
      </table></div>
    </div>
  </div>
</section>

<!-- ═══════════ الحركات ═══════════ -->
<section class="tab-panel" data-panel="movements">
  <div class="card" style="padding:18px">
    <div class="tbl-head"><div class="section-head">الحركات — مصروفات وإيصالات وخصومات البوالص (<?= intf($movements['totals']['c']) ?>)</div>
      <input type="search" class="table-search" data-target="tblMoves" aria-label="بحث في جدول الحركات" placeholder="بحث برقم بوليصة أو سائق…"></div>
    <div class="table-wrap"><table class="data" id="tblMoves">
      <thead><tr>
        <th class="g-identity">التاريخ</th><th class="g-identity">رقم البوليصة</th><th class="g-identity">العميل</th>
        <th class="g-vehicle">السائق</th><th class="g-vehicle">صاحب السيارة</th>
        <th class="g-expense">المصروف</th><th class="g-revenue">الإيصالات</th><th class="g-revenue">الخصومات</th><th class="g-identity">ملاحظة</th>
      </tr></thead>
      <tbody>
        <?php foreach ($movements['rows'] as $mv): ?>
          <tr><td><?= dfmt($mv['d']) ?></td><td style="font-weight:700"><?= e($mv['bill_number']) ?></td>
            <td style="text-align:right"><?= e($mv['company']) ?></td>
            <td><?= e($mv['driver']) ?></td><td><?= e($mv['car_owner']) ?></td>
            <td class="neg"><?= money($mv['total_expense'],false) ?></td>
            <td><?= money($mv['total_receipt'],false) ?></td>
            <td><?= money($mv['total_discount'],false) ?></td>
            <td><?= e($mv['note']) ?></td></tr>
        <?php endforeach; ?>
        <?php if(!$movements['rows']): ?><tr><td colspan="9"><div class="empty">لا حركات في هذه الفترة</div></td></tr><?php endif; ?>
      </tbody>
      <?php if($movements['rows']): ?>
      <tfoot><tr><td colspan="5">الإجمالي</td>
        <td><?= money($movements['totals']['e'],false) ?></td>
        <td><?= money($movements['totals']['r'],false) ?></td>
        <td><?= money($movements['totals']['d'],false) ?></td><td></td></tr></tfoot>
      <?php endif; ?>
    </table></div>
    <?php if(count($movements['rows'])>=500): ?><div style="padding:10px 4px;color:var(--grey)">عُرض أول 500 حركة — ضيّق الفترة لعرض غيرها.</div><?php endif; ?>
  </div>
</section>

<!-- ═══════════ الأذون ═══════════ -->
<section class="tab-panel" data-panel="permits">
  <div class="counters mb" style="grid-template-columns:repeat(4,1fr)">
    <div class="counter"><div class="n"><?= intf($permitsList['totals']['c']) ?></div><div class="l">إجمالي الأذون</div></div>
    <div class="counter"><div class="n" style="color:var(--green)"><?= intf($permitsList['totals']['open_cnt']) ?></div><div class="l">مفتوحة</div></div>
    <div class="counter"><div class="n" style="color:var(--grey)"><?= intf($permitsList['totals']['closed_cnt']) ?></div><div class="l">مقفولة</div></div>
    <div class="counter"><div class="n" style="color:var(--gold)"><?= intf($permitsList['totals']['containers']) ?></div><div class="l">إجمالي الحاويات</div></div>
  </div>
  <div class="card" style="padding:18px">
    <div class="tbl-head"><div class="section-head">الأذون والشهادات</div>
      <input type="search" class="table-search" data-target="tblPermits" aria-label="بحث في جدول الأذونات" placeholder="بحث برقم الإذن أو الشركة…"></div>
    <div class="table-wrap"><table class="data" id="tblPermits">
      <thead><tr>
        <th class="g-identity">رقم الإذن</th><th class="g-identity">النوع</th><th class="g-identity">التاريخ</th>
        <th class="g-identity">الشركة</th><th class="g-identity">العميل</th>
        <th class="g-vehicle">الحاويات</th><th class="g-vehicle">البوالص</th><th class="g-expense">المصروف</th><th class="g-identity">الحالة</th>
      </tr></thead>
      <tbody>
        <?php foreach ($permitsList['rows'] as $pm): ?>
          <tr><td style="font-weight:700"><a class="lnk" href="<?= url('permits/'.$pm['id']) ?>"><?= e($pm['permit_no'] ?: '—') ?></a></td>
            <td><?= e($permitTypes[$pm['permit_type']] ?? $pm['permit_type']) ?></td>
            <td><?= dfmt($pm['d']) ?></td>
            <td style="text-align:right"><?= e($pm['company']) ?></td>
            <td style="text-align:right"><?= e($pm['client']) ?></td>
            <td><?= intf($pm['containers']) ?></td>
            <td><?= intf($pm['bills']) ?></td>
            <td class="neg"><?= money($pm['expense'],false) ?></td>
            <td><span class="pill st-<?= $pm['status']==='closed'?'paid':'partial' ?>"><?= $pm['status']==='closed'?'مقفول':'جارٍ' ?></span></td></tr>
        <?php endforeach; ?>
        <?php if(!$permitsList['rows']): ?><tr><td colspan="9"><div class="empty">لا أذون في هذه الفترة</div></td></tr><?php endif; ?>
      </tbody>
    </table></div>
    <?php if(count($permitsList['rows'])>=500): ?><div style="padding:10px 4px;color:var(--grey)">عُرض أول 500 إذن — ضيّق الفترة لعرض غيرها.</div><?php endif; ?>
  </div>
</section>
</div>

<script src="<?= url('assets/js/chart.umd.js') ?>"></script>
<script>
const RP = <?= js_json([
  'monthly'   => ['labels'=>$monthNames,
                  'freight'=>array_map(fn($m)=>round($monthly[$m]['freight'],2),range(1,12)),
                  'custody'=>array_map(fn($m)=>round($monthly[$m]['custody'],2),range(1,12)),
                  'expenses'=>array_map(fn($m)=>round($monthly[$m]['expenses'],2),range(1,12)),
                  'collections'=>array_map(fn($m)=>round($monthly[$m]['collections'],2),range(1,12))],
  'expenses'  => array_map(fn($r)=>['label'=>$r['label'],'value'=>(float)$r['value']], $expenses['categories']),
]) ?>;

const C = {navy:'#15355B',navyMid:'#1E4B7A',gold:'#C9A227',green:'#1E6E3C',red:'#9B2C2C',teal:'#14746F',purple:'#5B3E7A'};
const PALETTE = [C.navy,C.gold,C.green,C.teal,C.purple,C.red,C.navyMid,'#B8922F','#2E8B57','#8B5E9B','#C97B27','#4A6FA5'];
Chart.defaults.font.family = "'Segoe UI','Tahoma',sans-serif";
Chart.defaults.plugins.legend.rtl = true;
Chart.defaults.plugins.tooltip.rtl = true;
const fmt = (v)=> new Intl.NumberFormat('en-US').format(Math.round(v));
const rendered = {};

function draw(id){
  if (rendered[id]) return; rendered[id]=true;
  if (id==='chMonthly') new Chart(document.getElementById('chMonthly'),{type:'line',
    data:{labels:RP.monthly.labels,datasets:[
      {label:'النولون',data:RP.monthly.freight,borderColor:C.navy,backgroundColor:'rgba(21,53,91,.08)',tension:.35,fill:true,borderWidth:2},
      {label:'العهد',data:RP.monthly.custody,borderColor:C.gold,backgroundColor:'rgba(201,162,39,.08)',tension:.35,fill:true,borderWidth:2},
      {label:'المصاريف',data:RP.monthly.expenses,borderColor:C.red,backgroundColor:'rgba(155,44,44,.06)',tension:.35,fill:true,borderWidth:2},
      {label:'المدفوعات',data:RP.monthly.collections,borderColor:C.green,backgroundColor:'rgba(30,110,60,.08)',tension:.35,fill:true,borderWidth:2}]},
    options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},scales:{y:{ticks:{callback:fmt}}},plugins:{legend:{position:'top'}}}});
  if (id==='chExp') new Chart(document.getElementById('chExp'),{type:'doughnut',
    data:{labels:RP.expenses.map(r=>r.label),datasets:[{data:RP.expenses.map(r=>r.value),backgroundColor:PALETTE,borderWidth:2,borderColor:'#fff'}]},
    options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'right'}}}});
}

// ─── التبويبات (مع حفظ التبويب النشِط عبر الفلترة) ───
const chartOf = {overview:'chMonthly',customers:null,payments:null,expenses:'chExp',movements:null,permits:null};
const tabInput = document.getElementById('tabInput');
function activateTab(t){
  if(!(t in chartOf)) t='overview';
  document.querySelectorAll('.tab').forEach(b=>{const on=b.dataset.tab===t;b.classList.toggle('active',on);b.setAttribute('aria-selected',on?'true':'false');b.tabIndex=on?0:-1;});
  document.querySelectorAll('.tab-panel').forEach(p=>{const on=p.dataset.panel===t;p.classList.toggle('active',on);p.hidden=!on;});
  if(tabInput) tabInput.value=t;
  if(chartOf[t]) draw(chartOf[t]);
}
document.querySelectorAll('.tab').forEach(btn=>{
  const panel=document.querySelector('.tab-panel[data-panel="'+btn.dataset.tab+'"]');
  btn.id='report-tab-'+btn.dataset.tab;btn.setAttribute('role','tab');btn.setAttribute('aria-controls','report-panel-'+btn.dataset.tab);
  if(panel){panel.id='report-panel-'+btn.dataset.tab;panel.setAttribute('role','tabpanel');panel.setAttribute('aria-labelledby',btn.id);}
  btn.addEventListener('click',()=>activateTab(btn.dataset.tab));
});
activateTab(new URLSearchParams(location.search).get('tab') || 'overview');

// ─── اختصارات الفترة (أزرار سريعة) ───
const pad=n=>String(n).padStart(2,'0');
const iso=d=>`${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
const form=document.getElementById('filterForm');
const fFrom=form.querySelector('[name=from]'), fTo=form.querySelector('[name=to]');
document.querySelectorAll('#presets button').forEach(b=>{
  b.addEventListener('click',()=>{
    const now=new Date(); let from='', to='';
    const p=b.dataset.preset;
    if(p==='today'){ from=to=iso(now); }
    else if(p==='week'){ const s=new Date(now); s.setDate(now.getDate()-((now.getDay()+1)%7)); from=iso(s); to=iso(now); } // الأسبوع يبدأ السبت
    else if(p==='month'){ from=iso(new Date(now.getFullYear(),now.getMonth(),1)); to=iso(now); }
    else if(p==='quarter'){ const q=Math.floor(now.getMonth()/3)*3; from=iso(new Date(now.getFullYear(),q,1)); to=iso(now); }
    else if(p==='year'){ from=`${now.getFullYear()}-01-01`; to=`${now.getFullYear()}-12-31`; }
    else if(p==='all'){ from=''; to=''; }
    fFrom.value=from; fTo.value=to;
    form.submit();
  });
});

</script>
