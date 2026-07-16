<?php
use App\Models\VisaCard;
/** صفحة كارت الفيزا — رصيد مخفي بزر إظهار + ملخّص + أداء + سجل بالوقت + كشف حساب. */
$fmtTime = fn($ts) => $ts ? substr((string)$ts, 11, 5) : '';       // HH:MM
$depTotal = array_sum(array_map(fn($r)=>$r['kind']==='deposit'?$r['amount']:0, $ledger));
$payTotal = -array_sum(array_map(fn($r)=>$r['kind']==='payment'?$r['amount']:0, $ledger));
$recent = array_slice($ledger, 0, 4);   // آخر المعاملات للملخّص
?>
<div class="page-actions">
  <a class="btn ghost" href="<?= url('office') ?>">‹ رجوع لرصيد المكتب</a>
  <a class="btn gold" href="<?= url('office/'.$card['id'].'/statement') ?>" target="_blank">🧾 كشف حساب</a>
  <a class="btn ghost" href="<?= url('office/'.$card['id'].'/edit') ?>">✏️ تعديل الكارت</a>
  <form method="post" action="<?= url('office/'.$card['id'].'/delete') ?>" class="confirm-delete" data-confirm="حذف الكارت؟ سيُلغى ربط عملياته بالفيزا (لا تُحذف العمليات)." style="display:inline">
    <?= csrfField() ?>
    <button class="btn ghost" type="submit" style="color:var(--red)">🗑️ حذف</button>
  </form>
</div>

<!-- شريط الملخّص أعلى الصفحة: الرصيد (مخفي) + آخر رصيد مضاف + آخر معاملات -->
<div class="two-col mb">
  <div class="kpi <?= $balance<0?'red':'gold' ?>" style="display:flex;flex-direction:column;justify-content:center;align-items:center;padding:22px">
    <div class="lbl">الرصيد المتاح في الكارت</div>
    <div class="val" style="font-size:2rem" data-secret data-val="<?= e(intf($balance)) ?>">••••••</div>
    <button type="button" class="btn ghost sm secret-toggle" style="margin-top:8px">👁 إظهار الرصيد</button>
    <div style="opacity:.85;font-size:.82rem;margin-top:8px">
      💳 <?= e($card['name']) ?><?= $card['owner']?' — '.e($card['owner']):'' ?><?= $card['bank']?' — '.e($card['bank']):'' ?>
    </div>
  </div>

  <div class="card" style="padding:16px 18px">
    <div class="section-head" style="margin:0 0 10px">📌 نظرة سريعة</div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px">
      <div style="flex:1;min-width:140px;background:var(--bg);border-radius:10px;padding:10px 12px">
        <div style="color:var(--grey);font-size:.78rem">آخر رصيد مضاف</div>
        <?php if($lastDep): ?>
          <div class="pos" style="font-weight:800;font-size:1.05rem">+<?= money($lastDep['amount'],false) ?></div>
          <div style="color:var(--grey);font-size:.78rem"><?= e(dfmt($lastDep['dep_date'])) ?><?= $lastDep['note']?' — '.e($lastDep['note']):'' ?></div>
        <?php else: ?><div style="color:var(--grey)">لا شحنات بعد</div><?php endif; ?>
      </div>
      <div style="flex:1;min-width:140px;background:var(--bg);border-radius:10px;padding:10px 12px">
        <div style="color:var(--grey);font-size:.78rem">إجمالي المصروف بالفيزا</div>
        <div class="neg" style="font-weight:800;font-size:1.05rem"><?= money($payTotal,false) ?></div>
        <div style="color:var(--grey);font-size:.78rem"><?= intf($stats['count']) ?> عملية</div>
      </div>
    </div>
    <div style="color:var(--grey);font-size:.8rem;font-weight:700;margin-bottom:6px">آخر المعاملات</div>
    <?php if($recent): foreach($recent as $r): ?>
      <div style="display:flex;align-items:center;gap:8px;padding:5px 0;border-top:1px solid var(--line)">
        <span style="font-size:.78rem;color:var(--grey);min-width:74px"><?= $r['d']?e(dfmt($r['d'])):'—' ?><?= $fmtTime($r['ts']??null)?' '.e($fmtTime($r['ts'])):'' ?></span>
        <span style="flex:1;font-size:.85rem"><?= e(mb_strimwidth($r['label'],0,28,'…')) ?></span>
        <span class="<?= $r['amount']>=0?'pos':'neg' ?>" style="font-weight:700;font-size:.85rem"><?= $r['amount']>=0?'+':'' ?><?= money($r['amount'],false) ?></span>
      </div>
    <?php endforeach; else: ?><div style="color:var(--grey);font-size:.82rem">لا معاملات بعد</div><?php endif; ?>
  </div>
</div>

<!-- أداء الكارت: تفكيك المصروف حسب المصدر + إجماليات -->
<div class="card mb" style="padding:16px 18px">
  <div class="section-head" style="margin:0 0 12px">📊 أداء الكارت</div>
  <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px">
    <div style="flex:1;min-width:130px"><div style="color:var(--grey);font-size:.78rem">عدد العمليات</div><div style="font-weight:800;font-size:1.2rem"><?= intf($stats['count']) ?></div></div>
    <div style="flex:1;min-width:130px"><div style="color:var(--grey);font-size:.78rem">إجمالي المصروف</div><div class="neg" style="font-weight:800;font-size:1.2rem"><?= money($stats['total'],false) ?></div></div>
    <div style="flex:1;min-width:130px"><div style="color:var(--grey);font-size:.78rem">أكبر عملية</div><div style="font-weight:800;font-size:1.2rem"><?= money($stats['max'],false) ?></div></div>
    <div style="flex:1;min-width:130px"><div style="color:var(--grey);font-size:.78rem">آخر نشاط</div><div style="font-weight:800;font-size:1.05rem"><?= $stats['last']?e(dfmt(substr((string)$stats['last'],0,10))):'—' ?></div></div>
  </div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th class="g-identity">المصدر</th><th class="g-identity">عدد العمليات</th><th class="g-expense">إجمالي المصروف</th><th class="g-identity">النسبة</th></tr></thead>
    <tbody>
      <?php $any=false; foreach($stats['by_source'] as $k=>$v): if(!$v['count']) continue; $any=true;
        $pct = $stats['total']>0 ? round($v['total']/$stats['total']*100) : 0; ?>
        <tr>
          <td style="text-align:right;font-weight:700"><?= e($v['label']) ?></td>
          <td><?= intf($v['count']) ?></td>
          <td class="neg"><?= money($v['total'],false) ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div style="flex:1;height:8px;background:var(--bg);border-radius:99px;overflow:hidden"><div style="height:100%;width:<?= $pct ?>%;background:var(--gold)"></div></div>
              <span style="font-size:.8rem;color:var(--grey);min-width:34px"><?= $pct ?>%</span>
            </div>
          </td>
        </tr>
      <?php endforeach; if(!$any): ?>
        <tr><td colspan="4"><div class="empty" style="padding:18px"><div class="ic">📊</div>لا مصروفات بالفيزا على هذا الكارت بعد.</div></td></tr>
      <?php endif; ?>
    </tbody>
  </table></div>
</div>

<!-- تفكيك الرصيد + شحن رصيد -->
<div class="two-col mb">
  <div class="card" style="padding:16px 18px">
    <div class="section-head" style="margin:0 0 10px">🧮 من إيه اتكوّن الرصيد</div>
    <table class="data">
      <?php if((float)$card['opening_balance'] != 0): ?>
      <tr><td style="background:var(--bg);font-weight:700;text-align:right">رصيد افتتاحي</td><td><?= money($card['opening_balance']) ?></td></tr>
      <?php endif; ?>
      <tr><td style="background:var(--bg);font-weight:700;text-align:right">+ الشحنات</td><td class="pos"><?= money($depTotal) ?></td></tr>
      <tr><td style="background:var(--bg);font-weight:700;text-align:right">− المدفوع بالفيزا</td><td class="neg"><?= money($payTotal) ?></td></tr>
      <tr><td style="background:var(--gold-light);font-weight:800;text-align:right">= الرصيد المتاح</td><td style="font-weight:800;background:var(--gold-light)" data-secret data-val="<?= e(money($balance)) ?>">••••••</td></tr>
    </table>
  </div>
  <div class="card" style="padding:16px 18px">
    <div class="section-head" style="margin:0 0 10px">➕ شحن رصيد للكارت</div>
    <form method="post" action="<?= url('office/'.$card['id'].'/deposit') ?>" style="display:flex;flex-wrap:wrap;gap:10px;align-items:end">
      <?= csrfField() ?>
      <div class="field" style="margin:0;width:150px"><label>التاريخ</label><input type="date" name="dep_date" value="<?= e(work_date()) ?>"></div>
      <div class="field" style="margin:0;flex:1;min-width:160px"><label>البيان (اختياري)</label><input type="text" name="note" placeholder="مصدر الشحن"></div>
      <div class="field" style="margin:0;width:140px"><label>المبلغ</label><input type="number" step="0.01" name="amount" placeholder="0" required></div>
      <button class="btn gold" type="submit">شحن الرصيد</button>
    </form>
  </div>
</div>

<!-- السجل الكامل بالوقت -->
<div class="card">
  <div class="section-head" style="padding:16px 18px 0;margin:0">📒 سجل حركات الكارت <span style="color:var(--grey);font-weight:400;font-size:.85rem">(بتوقيت الإدخال)</span></div>
  <div class="table-wrap"><table class="data">
    <thead><tr>
      <th class="g-identity">التاريخ / الوقت</th>
      <th class="g-identity">الحركة</th>
      <th class="g-identity">الطرف / البيان</th>
      <th class="g-revenue">شحن (+)</th>
      <th class="g-expense">مدفوع (−)</th>
      <th class="g-identity">الرصيد الجارٍ</th>
      <th class="g-identity"></th>
    </tr></thead>
    <tbody>
      <?php foreach ($ledger as $r): ?>
        <tr>
          <td style="white-space:nowrap">
            <?= $r['d'] ? e(dfmt($r['d'])) : '—' ?>
            <?php if($fmtTime($r['ts']??null)): ?><div style="color:var(--grey);font-size:.78rem">🕒 <?= e($fmtTime($r['ts'])) ?></div><?php endif; ?>
          </td>
          <td>
            <?php if($r['kind']==='opening'): ?>
              <span class="pill st-paid">افتتاحي</span>
            <?php elseif($r['kind']==='deposit'): ?>
              <span class="pill st-paid">شحن رصيد</span>
            <?php else: ?>
              <span class="pill st-partial"><?= e(VisaCard::SOURCES[$r['source']] ?? 'فيزا') ?></span>
              <?= e($r['label']) ?>
            <?php endif; ?>
          </td>
          <td style="text-align:right"><?= e($r['party'] ?: '—') ?>
            <?php if($r['kind']==='payment' && !empty($r['note'])): ?><div style="color:var(--grey);font-size:.82rem"><?= e($r['note']) ?></div><?php endif; ?>
          </td>
          <td class="<?= $r['amount']>0 && $r['kind']!=='opening'?'pos':'' ?>"><?= $r['amount']>0 && $r['kind']!=='opening' ? money($r['amount'],false) : '—' ?></td>
          <td class="<?= $r['amount']<0?'neg':'' ?>"><?= $r['amount']<0 ? money(-$r['amount'],false) : '—' ?></td>
          <td style="font-weight:700" data-secret data-val="<?= e(money($r['running'],false)) ?>">••••</td>
          <td style="white-space:nowrap">
            <?php if($r['kind']==='deposit'): ?>
              <form method="post" action="<?= url('office/deposit/'.$r['id'].'/delete') ?>" class="confirm-delete" data-confirm="حذف هذا الشحن؟" style="display:inline">
                <?= csrfField() ?>
                <button class="edit" type="submit" title="حذف" style="background:none;border:0;cursor:pointer">🗑️</button>
              </form>
            <?php elseif($r['kind']==='payment' && !empty($r['url'])): ?>
              <a class="edit" href="<?= e($r['url']) ?>" title="فتح العملية">🔎</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<script>
(function(){
  // إظهار/إخفاء كل القيم الحسّاسة (data-secret) بضغطة زر — يبدأ مخفيًّا (بيانات حسّاسة)
  var shown = false;
  function apply(){
    document.querySelectorAll('[data-secret]').forEach(function(el){
      el.textContent = shown ? (el.getAttribute('data-val') || '') : (el.dataset.mask || '••••••');
    });
    document.querySelectorAll('.secret-toggle').forEach(function(b){
      b.textContent = shown ? '🙈 إخفاء الرصيد' : '👁 إظهار الرصيد';
    });
  }
  document.querySelectorAll('[data-secret]').forEach(function(el){ el.dataset.mask = el.textContent; });
  document.addEventListener('click', function(e){
    if (e.target.closest('.secret-toggle')) { shown = !shown; apply(); }
  });
})();
</script>
