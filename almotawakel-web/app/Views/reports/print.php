<?php
use App\Core\App;
$sumRow = ['freight'=>0,'custody'=>0,'expenses'=>0,'collections'=>0];
foreach ($monthly as $m) foreach ($sumRow as $k=>$_) $sumRow[$k]+=$m[$k];
$company = App::setting('company_name','شركة المتوكل');
$cusTot = ['freight'=>0,'custody'=>0,'expense'=>0,'paid'=>0,'remaining'=>0];
foreach ($customers as $c){ foreach($cusTot as $k=>$_) $cusTot[$k]+=$c[$k]; }
$permitTypes = ['sader'=>'إذن','warid'=>'شهادة'];
?><!doctype html><html lang="ar" dir="rtl"><head>
<meta charset="utf-8"><title>تقارير <?= e($company) ?> — <?= e($year) ?></title>
<?php partial('print_base'); ?>
<style>
@page{size:A4;margin:14mm}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);color:var(--ink);direction:rtl;font-size:12px;line-height:1.5}
.head{text-align:center;border-bottom:3px solid var(--gold);padding-bottom:12px;margin-bottom:18px}
.head h1{color:var(--navy);font-size:22px} .head .g{color:var(--gold);font-weight:500}
.head p{color:var(--muted);font-size:12px;margin-top:3px}
.kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:18px}
.kpi{border:1px solid #D5DBE2;border-radius:var(--radius-1);padding:10px 12px;border-top:3px solid var(--navy)}
.kpi .l{font-size:11px;color:var(--muted);font-weight:500} .kpi .v{font-size:16px;font-weight:600;color:var(--navy);margin-top:3px}
h2{color:var(--navy);font-size:14px;margin:18px 0 8px;border-right:4px solid var(--gold);padding-right:8px}
table{width:100%;border-collapse:collapse;margin-bottom:10px;font-size:11px}
th{background:var(--navy);color:#fff;padding:7px 6px;text-align:center;border:1px solid #0E2440}
td{padding:6px;text-align:center;border:1px solid #D5DBE2}
tr:nth-child(even) td{background:#F7F9FB}
tfoot td{background:var(--navy);color:var(--gold);font-weight:600}
.neg{color:#9B2C2C;font-weight:500}
.foot{margin-top:20px;text-align:center;color:var(--muted);font-size:10px;border-top:1px solid #D5DBE2;padding-top:8px}
.two{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media print{.noprint{display:none}}
</style></head><body onload="window.print()">
<div class="noprint" style="text-align:center;margin-bottom:14px">
  <button onclick="window.print()" style="padding:8px 20px;background:var(--gold);border:none;border-radius:var(--radius-1);font-weight:500;cursor:pointer"><?= icon('file-text', 14) ?> طباعة</button>
</div>
<div class="head">
  <h1>شركة <span class="g"><?= e(str_replace('شركة ','',$company)) ?></span></h1>
  <p>التقارير التشغيلية والإدارية —
    <?php if(!empty($from)||!empty($to)): ?>
      الفترة من <?= e(!empty($from)?dfmt($from):'البداية') ?> إلى <?= e(!empty($to)?dfmt($to):'النهاية') ?>
    <?php else: ?>
      كل الفترات
    <?php endif; ?>
    — صدر بتاريخ <?= dfmt(date('Y-m-d')) ?></p>
</div>

<h2>الملخص التشغيلي العام</h2>
<div class="kpis">
  <div class="kpi"><div class="l">إجمالي النولون</div><div class="v"><?= money0($totals['freight']) ?></div></div>
  <div class="kpi"><div class="l">إجمالي العهد</div><div class="v"><?= money0($totals['custody']) ?></div></div>
  <div class="kpi"><div class="l">إجمالي المصاريف</div><div class="v"><?= money0($totals['expenses']) ?></div></div>
  <div class="kpi"><div class="l">إجمالي المدفوعات</div><div class="v"><?= money0($totals['collections']) ?></div></div>
  <div class="kpi"><div class="l">المتبقي للعملاء</div><div class="v"><?= money0($totals['outstanding']) ?></div></div>
  <div class="kpi"><div class="l">عدد الأذون</div><div class="v"><?= intf($permitsList['totals']['c']) ?></div></div>
</div>

<h2>الأداء الشهري</h2>
<table>
  <thead><tr><th>الشهر</th><th>النولون</th><th>العهد</th><th>مصروف تشغيلي</th><th>المدفوعات</th></tr></thead>
  <tbody>
    <?php foreach ($monthNames as $idx=>$mn): $m=$monthly[$idx+1]; ?>
      <tr><td><?= $mn ?></td><td><?= money($m['freight'],false) ?></td><td><?= money($m['custody'],false) ?></td>
        <td class="neg"><?= money($m['expenses'],false) ?></td><td><?= money($m['collections'],false) ?></td></tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot><tr><td>الإجمالي</td><td><?= money($sumRow['freight'],false) ?></td><td><?= money($sumRow['custody'],false) ?></td>
    <td><?= money($sumRow['expenses'],false) ?></td><td><?= money($sumRow['collections'],false) ?></td></tr></tfoot>
</table>

<div class="two">
  <div>
    <h2>النولون حسب الشركة</h2>
    <table><thead><tr><th>الشركة</th><th>النولون</th></tr></thead><tbody>
      <?php foreach ($byCompany as $r): ?><tr><td><?= e($r['name']) ?></td><td><?= money($r['freight'],false) ?></td></tr><?php endforeach; ?>
    </tbody></table>
  </div>
  <div>
    <h2>مستحقات العملاء</h2>
    <table><thead><tr><th>العميل</th><th>إجمالي التعامل</th><th>المتبقي له</th></tr></thead><tbody>
      <?php foreach ($debts as $r): ?><tr><td><?= e($r['name']) ?></td><td><?= money($r['invoices'],false) ?></td><td class="<?= $r['remaining']>0?'neg':'' ?>"><?= money($r['remaining'],false) ?></td></tr><?php endforeach; ?>
    </tbody></table>
  </div>
</div>

<h2>العملاء — النولون والعهد والمدفوعات</h2>
<table>
  <thead><tr><th>العميل</th><th>الحركات</th><th>النولون</th><th>العهد</th><th>المصروف</th><th>المدفوع له</th><th>المتبقي له</th></tr></thead>
  <tbody>
    <?php foreach ($customers as $c): ?>
      <tr><td><?= e($c['name']) ?></td><td><?= intf($c['trips']) ?></td>
        <td><?= money($c['freight'],false) ?></td><td><?= money($c['custody'],false) ?></td>
        <td class="neg"><?= money($c['expense'],false) ?></td>
        <td><?= money($c['paid'],false) ?></td><td class="<?= $c['remaining']>0?'neg':'' ?>"><?= money($c['remaining'],false) ?></td></tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot><tr><td>الإجمالي</td><td></td><td><?= money($cusTot['freight'],false) ?></td><td><?= money($cusTot['custody'],false) ?></td>
    <td><?= money($cusTot['expense'],false) ?></td><td><?= money($cusTot['paid'],false) ?></td><td><?= money($cusTot['remaining'],false) ?></td></tr></tfoot>
</table>

<?php
$expCatTotal = 0; foreach ($expenses['categories'] as $r) $expCatTotal += $r['value'];
$expGrand = $expCatTotal + $expenses['takhlis'] + $expenses['shamil']
          + ($expenses['permits'] ?? 0) + ($expenses['manual'] ?? 0);
?>
<h2>تقرير المصروفات</h2>
<table>
  <thead><tr><th>البند</th><th>عدد المرات</th><th>القيمة</th><th>النسبة</th></tr></thead>
  <tbody>
    <?php foreach ($expenses['categories'] as $r): ?>
      <tr><td><?= e($r['label']) ?></td><td><?= intf($r['cnt']) ?></td><td class="neg"><?= money($r['value'],false) ?></td>
        <td><?= pct($expGrand!=0?$r['value']/$expGrand:0) ?></td></tr>
    <?php endforeach; ?>
    <?php if($expenses['takhlis']>0): ?><tr><td>مصروفات التخليص</td><td>—</td><td class="neg"><?= money($expenses['takhlis'],false) ?></td><td><?= pct($expGrand!=0?$expenses['takhlis']/$expGrand:0) ?></td></tr><?php endif; ?>
    <?php if($expenses['shamil']>0): ?><tr><td>مصروفات الفواتير</td><td>—</td><td class="neg"><?= money($expenses['shamil'],false) ?></td><td><?= pct($expGrand!=0?$expenses['shamil']/$expGrand:0) ?></td></tr><?php endif; ?>
    <?php if(($expenses['permits'] ?? 0)>0): ?><tr><td>مصروفات الأذونات</td><td>—</td><td class="neg"><?= money($expenses['permits'],false) ?></td><td><?= pct($expGrand!=0?$expenses['permits']/$expGrand:0) ?></td></tr><?php endif; ?>
    <?php if(($expenses['manual'] ?? 0)>0): ?><tr><td>مصاريف يدوية</td><td>—</td><td class="neg"><?= money($expenses['manual'],false) ?></td><td><?= pct($expGrand!=0?$expenses['manual']/$expGrand:0) ?></td></tr><?php endif; ?>
  </tbody>
  <tfoot><tr><td colspan="2">إجمالي المصروفات</td><td><?= money($expGrand,false) ?></td><td>100%</td></tr></tfoot>
</table>

<h2>الأذون والشهادات (<?= intf($permitsList['totals']['c']) ?> — حاويات: <?= intf($permitsList['totals']['containers']) ?>)</h2>
<table>
  <thead><tr><th>رقم الإذن</th><th>النوع</th><th>التاريخ</th><th>الشركة</th><th>الحاويات</th><th>المصروف</th><th>الحالة</th></tr></thead>
  <tbody>
    <?php foreach ($permitsList['rows'] as $pm): ?>
      <tr><td><?= e($pm['permit_no'] ?: '—') ?></td><td><?= e($permitTypes[$pm['permit_type']] ?? $pm['permit_type']) ?></td>
        <td><?= dfmt($pm['d']) ?></td><td><?= e($pm['company']) ?></td><td><?= intf($pm['containers']) ?></td>
        <td class="neg"><?= money($pm['expense'],false) ?></td><td><?= $pm['status']==='closed'?'مقفول':'جارٍ' ?></td></tr>
    <?php endforeach; ?>
    <?php if(!$permitsList['rows']): ?><tr><td colspan="7">لا أذون في هذه الفترة</td></tr><?php endif; ?>
  </tbody>
</table>

<div class="foot"><?= e($company) ?> — نظام إدارة النقل والتخليص الجمركي</div>
</body></html>
