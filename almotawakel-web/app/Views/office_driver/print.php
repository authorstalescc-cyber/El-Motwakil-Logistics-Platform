<?php use App\Core\App; use App\Models\OfficeDriverStatement; $s=$statement; ?>
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?></title><?php partial('print_base'); ?>
<style>
*{box-sizing:border-box}body{margin:0;padding:14px;background:var(--bg);color:var(--ink);font:11px var(--font)}
.sheet{max-width:820px;margin:auto;background:#fff;padding:22px;border:1px solid var(--line)}
.hd{display:flex;border-bottom:3px solid var(--gold);padding-bottom:10px;gap:16px}.hd h1{margin:0;color:var(--navy);font-size:19px}.rt{margin-inline-start:auto;text-align:end;color:var(--muted)}
.meta,.sum{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin:12px 0}.box{border:1px solid var(--line);padding:8px}.box em{display:block;font-style:normal;color:var(--muted);font-size:9px}.box b{font-size:13px}
table{width:100%;border-collapse:collapse;margin-top:10px}th,td{border:1px solid var(--line);padding:5px;text-align:start;vertical-align:top}th{background:var(--bg);color:var(--navy)}.n{text-align:end;white-space:nowrap}.detail{font-size:9px;color:var(--muted);margin-top:2px}.neg{color:var(--red)}.pos{color:var(--green)}
.sign{display:grid;grid-template-columns:1fr 1fr;gap:70px;margin-top:38px}.sign div{border-top:1px solid var(--ink);text-align:center;padding-top:5px}
.noprint{text-align:center;margin:10px}.noprint button{font:inherit;background:var(--navy);color:#fff;border:0;border-radius:6px;padding:8px 20px}
@media print{body{padding:0;background:#fff}.sheet{border:0;max-width:none;padding:8mm}.noprint{display:none}@page{size:A4;margin:9mm}}
</style></head><body>
<div class="noprint"><button onclick="window.print()">اطبع / احفظ PDF</button></div>
<div class="sheet">
 <div class="hd"><div><h1>بيان حساب سائق</h1><div><?= e(App::setting('company_name','شركة المتوكل')) ?></div></div>
 <div class="rt"><b><?= e(dfmt($s['from'])) ?> — <?= e(dfmt($s['to'])) ?></b>
  <?php if(!empty($s['_issued'])): ?><br>بيان محفوظ #<?= (int)$s['_issued']['id'] ?> · <?= e($s['_issued']['created_at']) ?>
  <?php else: ?><br>معاينة حية · طُبعت <?= e(dfmt(date('Y-m-d'))) ?><?php endif; ?>
 </div></div>
 <div class="meta">
  <div class="box"><em>السائق</em><b><?= e($s['driver']) ?></b></div>
  <div class="box"><em>السيارة</em><b><?= e($s['car']['name']) ?></b></div>
  <div class="box"><em>اللوحة</em><b><?= e($s['car']['plate']) ?></b></div>
  <div class="box"><em>رصيد سابق</em><b><?= e(money0($s['opening'])) ?></b></div>
 </div>
 <table><thead><tr><th>التاريخ</th><th>البوليصة / البيان</th><th>الجهة</th><th class="n">عهدة + إضافات</th><th class="n">مصروفات + مردود</th><th class="n">صافي</th><th class="n">الرصيد</th></tr></thead><tbody>
 <?php foreach($s['rows'] as $r): ?>
  <tr><td><?= e(dfmt($r['date'])) ?></td>
  <?php if($r['kind']==='bill'): $b=$r['bill']; ?>
   <td><b><?= e($b['bill_number']?:('#'.(int)$b['id'])) ?></b>
    <?php foreach($r['items'] as $x): ?><div class="detail">− <?= e($x['description']?:OfficeDriverStatement::CATEGORIES[$x['category']]) ?>: <?= e(money0($x['amount'],false)) ?></div><?php endforeach; ?>
    <?php foreach($r['allocations'] as $x): ?><div class="detail">− إيصال <?= e($x['receipt_note']?:$x['ref_no']?:('#'.(int)$x['stage_id'])) ?>: <?= e(money0($x['amount'],false)) ?></div><?php endforeach; ?>
    <?php foreach($r['movements'] as $x): ?><div class="detail"><?= OfficeDriverStatement::movementSigned($x)>=0?'+':'−' ?> <?= e(OfficeDriverStatement::movementLabel($x['kind'])) ?>: <?= e(money0($x['amount'],false)) ?></div><?php endforeach; ?>
   </td><td><?= e($b['destination']?:'—') ?></td>
  <?php else: $m=$r['movement']; ?><td><b><?= e(OfficeDriverStatement::movementLabel($m['kind'])) ?></b><div class="detail"><?= e($m['note']?:'') ?></div></td><td>—</td><?php endif; ?>
   <td class="n"><?= e(money0($r['custody']+$r['additions'],false)) ?></td>
   <td class="n"><?= e(money0($r['expenses']+$r['receipts']+$r['returns'],false)) ?></td>
   <td class="n"><?= e(money0($r['net'],false)) ?></td>
   <td class="n"><b><?= e(money0($r['running'],false)) ?></b></td></tr>
 <?php endforeach; ?></tbody></table>
 <div class="sum">
  <div class="box"><em>إجمالي العهد والإضافات</em><b><?= e(money0($s['totals']['custody']+$s['totals']['additions'])) ?></b></div>
  <div class="box"><em>المصروفات</em><b><?= e(money0($s['totals']['expenses'])) ?></b></div>
  <div class="box"><em>إيصالات دفعها السائق</em><b><?= e(money0($s['totals']['receipts'])) ?></b></div>
  <div class="box"><em>الرصيد النهائي</em><b class="<?= $s['closing']<0?'neg':'pos' ?>"><?= e(money0($s['closing'])) ?></b></div>
 </div>
 <p><b>قراءة الرصيد:</b> الموجب = مبلغ جارٍ مع السائق. السالب = مستحق للسائق ويُرحَّل تلقائيًا للبيان التالي.</p>
 <div class="sign"><div>مسؤول الحسابات</div><div>السائق / المستلم</div></div>
</div></body></html>
