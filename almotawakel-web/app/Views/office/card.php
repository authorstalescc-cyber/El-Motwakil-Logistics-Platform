<?php
use App\Models\VisaCard;
use App\Core\FormState;
/** صفحة كارت الفيزا — رصيد مخفي بزر إظهار + ملخّص + أداء + سجل بالوقت + كشف حساب. */
$fmtTime = fn($ts) => $ts ? substr((string)$ts, 11, 5) : '';       // HH:MM
$depTotal = array_sum(array_map(fn($r)=>$r['kind']==='deposit'?$r['amount']:0, $ledger));
$payTotal = -array_sum(array_map(fn($r)=>$r['kind']==='payment'?$r['amount']:0, $ledger));
$recent = array_slice($ledger, 0, 4);   // آخر المعاملات للملخّص
$depositState = $depositState ?? ['token'=>FormState::token(), 'old'=>[], 'errors'=>[], 'back'=>'office/'.(int)$card['id']];
$depositOld = $depositState['old'];
$depositErrors = $depositState['errors'];
$returnPath = current_app_path() ?: 'office/'.(int)$card['id'];
?>
<div class="page-actions">
  <a class="btn ghost" href="<?= url('office') ?>">‹ رجوع لرصيد المكتب</a>
  <a class="btn gold" href="<?= url('office/'.$card['id'].'/statement') ?>" target="_blank"><?= icon('receipt', 14) ?> كشف حساب</a>
  <a class="btn ghost" href="<?= url('office/'.$card['id'].'/edit') ?>"><?= icon('edit', 14) ?> تعديل الكارت</a>
  <form method="post" action="<?= url('office/'.$card['id'].'/delete') ?>" class="confirm-delete" data-confirm="حذف الكارت؟ سيُلغى ربط عملياته بالفيزا (لا تُحذف العمليات).">
    <?= csrfField() ?>
    <?= formStateFields(FormState::token(), 'office') ?>
    <button class="btn ghost danger" type="submit"><?= icon('trash', 14) ?> حذف</button>
  </form>
</div>

<!-- شريط الملخّص أعلى الصفحة: الرصيد (مخفي) + آخر رصيد مضاف + آخر معاملات -->
<div class="two-col mb">
  <div class="kpi oc-hero <?= $balance<0?'red':'gold' ?>">
    <div class="lbl">الرصيد المتاح في الكارت</div>
    <div class="val oc-hero-val"><?= e(intf($balance)) ?></div>
    <div class="oc-hero-meta">
      <?= icon('credit-card', 14) ?> <?= e($card['name']) ?><?= $card['owner']?' — '.e($card['owner']):'' ?><?= $card['bank']?' — '.e($card['bank']):'' ?>
    </div>
  </div>

  <div class="card oc-pad">
    <div class="section-head oc-sec"><?= icon('info', 17) ?> نظرة سريعة</div>
    <div class="oc-quick">
      <div class="oc-qbox">
        <div class="dbt-sub">آخر رصيد مضاف</div>
        <?php if($lastDep): ?>
          <div class="pos oc-num sm">+<?= money($lastDep['amount'],false) ?></div>
          <div class="dbt-sub"><?= e(dfmt($lastDep['dep_date'])) ?><?= $lastDep['note']?' — '.e($lastDep['note']):'' ?></div>
        <?php else: ?><div class="dbt-none">لا شحنات بعد</div><?php endif; ?>
      </div>
      <div class="oc-qbox">
        <div class="dbt-sub">إجمالي المصروف بالفيزا</div>
        <div class="neg oc-num sm"><?= money($payTotal,false) ?></div>
        <div class="dbt-sub"><?= intf($stats['count']) ?> عملية</div>
      </div>
    </div>
    <div class="oc-sub-h">آخر المعاملات</div>
    <?php if($recent): foreach($recent as $r): ?>
      <div class="oc-rec">
        <span class="oc-rec-d"><?= $r['d']?e(dfmt($r['d'])):'—' ?><?= $fmtTime($r['ts']??null)?' '.e($fmtTime($r['ts'])):'' ?></span>
        <span class="oc-rec-l"><?= e(mb_strimwidth($r['label'],0,28,'…')) ?></span>
        <span class="<?= $r['amount']>=0?'pos':'neg' ?> oc-rec-a"><?= $r['amount']>=0?'+':'' ?><?= money($r['amount'],false) ?></span>
      </div>
    <?php endforeach; else: ?><div class="dbt-none">لا معاملات بعد</div><?php endif; ?>
  </div>
</div>

<!-- أداء الكارت: تفكيك المصروف حسب المصدر + إجماليات -->
<div class="card mb oc-pad">
  <div class="section-head oc-sec wide"><?= icon('bar-chart', 17) ?> أداء الكارت</div>
  <div class="oc-stats">
    <div class="oc-stat"><div class="dbt-sub">عدد العمليات</div><div class="oc-num"><?= intf($stats['count']) ?></div></div>
    <div class="oc-stat"><div class="dbt-sub">إجمالي المصروف</div><div class="neg oc-num"><?= money($stats['total'],false) ?></div></div>
    <div class="oc-stat"><div class="dbt-sub">أكبر عملية</div><div class="oc-num"><?= money($stats['max'],false) ?></div></div>
    <div class="oc-stat"><div class="dbt-sub">آخر نشاط</div><div class="oc-num sm"><?= $stats['last']?e(dfmt(substr((string)$stats['last'],0,10))):'—' ?></div></div>
  </div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th class="g-identity">المصدر</th><th class="g-identity">عدد العمليات</th><th class="g-expense">إجمالي المصروف</th><th class="g-identity">النسبة</th></tr></thead>
    <tbody>
      <?php $any=false; foreach($stats['by_source'] as $k=>$v): if(!$v['count']) continue; $any=true;
        $pct = $stats['total']>0 ? round($v['total']/$stats['total']*100) : 0; ?>
        <tr>
          <td class="txt mv-ref"><?= e($v['label']) ?></td>
          <td><?= intf($v['count']) ?></td>
          <td class="neg"><?= money($v['total'],false) ?></td>
          <td>
            <div class="oc-bar-row">
              <div class="oc-bar"><div class="oc-bar-fill" style="width:<?= $pct ?>%"></div></div>
              <span class="oc-pct"><?= $pct ?>%</span>
            </div>
          </td>
        </tr>
      <?php endforeach; if(!$any): ?>
        <tr><td colspan="4"><div class="empty oc-empty"><div class="ic"><?= icon('bar-chart', 14) ?></div>لا مصروفات بالفيزا على هذا الكارت بعد.</div></td></tr>
      <?php endif; ?>
    </tbody>
  </table></div>
</div>

<!-- تفكيك الرصيد + شحن رصيد -->
<div class="two-col mb">
  <div class="card oc-pad">
    <div class="section-head oc-sec"><?= icon('bar-chart', 17) ?> من إيه اتكوّن الرصيد</div>
    <table class="data">
      <?php if((float)$card['opening_balance'] != 0): ?>
      <tr><td class="dba-k txt">رصيد افتتاحي</td><td><?= money($card['opening_balance']) ?></td></tr>
      <?php endif; ?>
      <tr><td class="dba-k txt">+ الشحنات</td><td class="pos"><?= money($depTotal) ?></td></tr>
      <tr><td class="dba-k txt">− المدفوع بالفيزا</td><td class="neg"><?= money($payTotal) ?></td></tr>
      <tr><td class="oc-sum-k txt">= الرصيد المتاح</td><td class="oc-sum-v"><?= e(money($balance)) ?></td></tr>
    </table>
  </div>
  <div class="card oc-pad" id="deposit">
    <div class="section-head oc-sec"><?= icon('plus', 17) ?> شحن رصيد للكارت</div>
    <?= formErrorSummary($depositErrors) ?>
    <form class="js-dirty-form" method="post" action="<?= url('office/'.$card['id'].'/deposit') ?>" class="oc-dep-form">
      <?= csrfField() ?>
      <?= formStateFields($depositState['token'], $depositState['back']) ?>
      <div class="field flush w150"><label>التاريخ</label><input type="date" name="dep_date" value="<?= e($depositOld['dep_date'] ?? work_date()) ?>"></div>
      <div class="field flush grow"><label>البيان (اختياري)</label><input type="text" name="note" value="<?= e($depositOld['note'] ?? '') ?>" placeholder="مصدر الشحن"></div>
      <div class="field flush w140"><label>المبلغ</label><input data-money type="number" step="0.01" min="0.01" name="amount" value="<?= e($depositOld['amount'] ?? '') ?>" placeholder="0" required<?= fieldErrorAttrs('amount', $depositErrors) ?>><?= fieldError('amount', $depositErrors) ?></div>
      <button class="btn gold" type="submit" data-primary-submit>شحن الرصيد</button>
    </form>
  </div>
</div>

<!-- السجل الكامل بالوقت -->
<div class="card">
  <div class="section-head oc-sec-in"><?= icon('layers', 17) ?> سجل حركات الكارت <span class="oc-note">(بتوقيت الإدخال)</span></div>
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
          <td class="nowrap">
            <?= $r['d'] ? e(dfmt($r['d'])) : '—' ?>
            <?php if($fmtTime($r['ts']??null)): ?><div class="dbt-sub"><?= icon('clock', 12) ?> <?= e($fmtTime($r['ts'])) ?></div><?php endif; ?>
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
          <td class="txt"><?= e($r['party'] ?: '—') ?>
            <?php if($r['kind']==='payment' && !empty($r['note'])): ?><div class="dbt-sub"><?= e($r['note']) ?></div><?php endif; ?>
          </td>
          <td class="<?= $r['amount']>0 && $r['kind']!=='opening'?'pos':'' ?>"><?= $r['amount']>0 && $r['kind']!=='opening' ? money($r['amount'],false) : '—' ?></td>
          <td class="<?= $r['amount']<0?'neg':'' ?>"><?= $r['amount']<0 ? money(-$r['amount'],false) : '—' ?></td>
          <td class="mv-ref"><?= e(money($r['running'],false)) ?></td>
          <td class="nowrap">
            <?php if($r['kind']==='deposit'): ?>
              <form method="post" action="<?= url('office/deposit/'.$r['id'].'/delete') ?>" class="confirm-delete" data-confirm="حذف هذا الشحن؟">
                <?= csrfField() ?>
                <?= formStateFields(FormState::token(), $returnPath) ?>
                <button class="edit oc-btn-bare" type="submit" title="حذف"><?= icon('trash', 14) ?></button>
              </form>
            <?php elseif($r['kind']==='payment' && !empty($r['url'])): ?>
              <a class="edit" href="<?= e($r['url']) ?>" title="فتح العملية"><?= icon('search', 14) ?></a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
