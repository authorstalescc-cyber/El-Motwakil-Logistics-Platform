<?php
use App\Models\Permit;
use App\Models\Bill;

$pid  = (int)$p['id'];
$type = $p['permit_type'];
$th = Permit::theme($type);
$noLabel = Permit::noLabel($type);
$isClosed = ($p['status'] ?? 'open') === 'closed';
$pStages = Permit::scopedStages('permit');
$mStages = Permit::scopedStages('movement');
$primaryDef = Permit::primaryFeesDefault($pid);

// التقدّم (نفس منطق الإدارة)
$done = 0; $tot = 0;
foreach ($pStages as $k => $m) { if ($k==='generators') continue; $tot++;
  $has = $k==='data' ? true : !empty($permitStages[$k] ?? []); if ($has) $done++; }
foreach ($movements as $mv) { foreach ($mStages as $k => $m) { if ($k==='generators') continue; $tot++;
  $has = $k==='bills' ? !empty($mv['bills']) : !empty($mv['stages'][$k] ?? []); if ($has) $done++; } }
$progress = $tot ? (int) round($done / $tot * 100) : 0;

$statusBadge = fn(string $st) => $st === 'pending'
    ? '<span class="tl-st pending">ناقصة</span>' : '<span class="tl-st done">تمّت</span>';

$manageUrl = fn(string $key, int $mid=0) => url('permits/'.$pid.'/manage').'#stage-'.$key.($mid?'-'.$mid:'');

// عرض مرحلة مالية للقراءة فقط
$roStage = function(string $key, array $st, array $entries, int $idx, int $mid=0) use ($statusBadge, $primaryDef, $manageUrl) {
    // فصل علامة «لا يوجد» (بند بحالة none) عن البنود الحقيقية
    $noneRow = null; $realEntries = [];
    foreach ($entries as $en) { if (($en['status'] ?? '') === 'none') { $noneRow = $en; } else { $realEntries[] = $en; } }
    $isNone = ($noneRow !== null && !$realEntries);

    $stageTotal = array_sum(array_map(fn($x)=>(float)$x['amount'], $realEntries));
    // الرسوم الأولية تُعرض تلقائيًا (الكشف + الضمان) طالما لا بنود مسجّلة وعدد الحاويات > 0 والسعر مُدخَل في الإذن
    $autoPrimary = ($key === 'primary_fees' && !$realEntries && !$isNone && ($primaryDef['containers'] ?? 0) > 0 && !empty($primaryDef['rates_set']));
    $headTotal   = $autoPrimary ? (float)$primaryDef['total'] : $stageTotal;
    $hasData     = !empty($realEntries) || $autoPrimary || $isNone;
    $showTotal   = $key!=='custom' && $headTotal > 0;
    $entries     = $realEntries;
    ob_start(); ?>
    <details class="stage <?= $hasData?'has':'' ?>" <?= $hasData?'open':'' ?>>
      <summary class="stage-sum">
        <span class="ss-ic <?= $hasData?'ok':'' ?>"><?= $hasData?'✓':$st['icon'] ?></span>
        <span class="ss-num"><?= $idx ?></span>
        <span class="ss-name"><?= e($st['label']) ?></span>
        <?php if ($st['dir']===Permit::DIR_EXPENSE): ?><span class="tl-dir neg">مصروف</span>
        <?php elseif ($st['dir']===Permit::DIR_REVENUE): ?><span class="tl-dir pos">محصّل</span><?php endif; ?>
        <span style="flex:1"></span>
        <?php if ($isNone): ?><span class="ss-none" title="مُعلَّم: لا يوجد بند لهذه المرحلة">🚫 لا يوجد</span>
        <?php elseif (count($entries)): ?><span class="ss-count"><?= intf(count($entries)) ?></span>
        <?php elseif ($autoPrimary): ?><span class="ss-count" title="محسوبة تلقائيًا">تلقائي</span><?php endif; ?>
        <?php if ($showTotal): ?><span class="ss-amt <?= $st['dir']===Permit::DIR_EXPENSE?'neg':'pos' ?>"><?= money($headTotal,false) ?></span><?php endif; ?>
        <a class="ss-act" href="<?= $manageUrl($key,$mid) ?>" title="تعديل سريع" onclick="event.stopPropagation()">✏️</a>
        <a class="ss-act add" href="<?= $manageUrl($key,$mid) ?>" title="إضافة بند" onclick="event.stopPropagation()">➕</a>
        <span class="ss-chev">▾</span>
      </summary>
      <div class="stage-body">
        <?php if ($isNone): ?>
          <div class="tl-none"><span>🚫 هذه المرحلة معلَّمة <b>«لا يوجد»</b> ومحسوبة كمكتملة.</span></div>
        <?php elseif ($entries): ?>
          <div class="table-wrap"><table class="data">
            <thead><tr><th class="g-identity">التاريخ</th><th class="g-result">المبلغ</th>
              <?php if($key!=='primary_fees'): ?><th class="g-result">السعر الفعلي</th><?php endif; ?>
              <th class="g-identity">البيان</th><th class="g-identity">مرجع</th><th class="g-identity">الحالة</th><th class="g-identity">مرفق</th></tr></thead>
            <tbody>
            <?php foreach ($entries as $en):
              $eDir = $key==='custom' ? ($en['direction'] ?: 'expense') : $st['dir'];
              $amtCls = $eDir===Permit::DIR_EXPENSE ? 'neg' : ($eDir===Permit::DIR_REVENUE ? 'pos' : ''); ?>
              <tr>
                <td><?= dfmt($en['entry_date']) ?></td>
                <td class="<?= $amtCls ?>"><?= money($en['amount'],false) ?>
                  <?php $eqty=max(1,(int)($en['qty']??1)); if($eqty>1): ?><span class="te-qty" title="الكمية × سعر الوحدة"><?= intf($eqty) ?> × <?= money(round((float)$en['amount']/$eqty,2),false) ?></span><?php endif; ?>
                  <?php if(!empty($en['is_visa'])): ?><span class="tl-visa-badge">💳 فيزا</span><?php endif; ?></td>
                <?php if($key!=='primary_fees'): ?><td style="font-weight:700;color:var(--navy)"><?= (isset($en['actual_amount'])&&$en['actual_amount']!==null&&$en['actual_amount']!=='') ? money($en['actual_amount'],false) : '—' ?></td><?php endif; ?>
                <td style="text-align:right"><?= e($en['note'] ?: '—') ?></td>
                <td><?= e($en['ref_no'] ?: '—') ?></td>
                <td><?= $statusBadge($en['status']) ?></td>
                <td><?php if($en['attachment']): ?><a href="<?= url('attachments/stage/'.(int)$en['id']) ?>" target="_blank">📎</a><?php else: ?>—<?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php elseif ($autoPrimary): ?>
          <div class="tl-auto">
            <div class="tl-auto-h">🪙 رسوم أولية محسوبة تلقائيًا من حاويات الإذن (<?= intf($primaryDef['containers']) ?> حاوية):</div>
            <div class="tl-auto-row"><span>الكشف</span><span><?= intf($primaryDef['containers']) ?> × <?= money($primaryDef['inspection_rate'],false) ?></span><b><?= money($primaryDef['inspection'],false) ?></b></div>
            <div class="tl-auto-row"><span>الضمان</span><span><?= intf($primaryDef['containers']) ?> × <?= money($primaryDef['guarantee_rate'],false) ?></span><b><?= money($primaryDef['guarantee'],false) ?></b></div>
            <div class="tl-auto-row tot"><span>الإجمالي</span><span></span><b><?= money($primaryDef['total'],false) ?></b></div>
          </div>
        <?php else: ?>
          <div class="tl-hint">لا بنود مسجّلة.</div>
        <?php endif; ?>
      </div>
    </details>
    <?php return ob_get_clean();
};
?>
<!-- شريط الإجراءات — جزء مشترك مع شاشة الإدارة -->
<?php $mode = 'view'; include __DIR__ . '/_bar.php'; ?>

<!-- HERO — جزء مشترك (كان مكرَّرًا حرفيًا في الشاشتين) -->
<?php include __DIR__ . '/_hero.php'; ?>

<!-- ===== مرشد الإيصالات الذكي (استرشادي — v32) ===== -->
<?php $back = 'view'; include __DIR__ . '/_rcp_guide.php'; ?>

<!-- ===== تفاصيل إضافية (بلا تكرار لبيانات الهيرو) ===== -->
<?php
$rows = [
  ['📦 تصنيف الحاويات', ($p['container_type'] ?? '') !== '' ? $p['container_type'] : null],
  ['سعر الكشف لكل حاوية', $p['inspection_rate']!==null ? money($p['inspection_rate'],false) : 'غير مُدخَل'],
  ['سعر الضمان لكل حاوية', $p['guarantee_rate']!==null ? money($p['guarantee_rate'],false) : 'غير مُدخَل'],
  ['🚢 شركة الشحن', ($p['shipping_co'] ?? '') !== '' ? $p['shipping_co'] : null],
  ['🧾 التوكيل', ($p['agency'] ?? '') !== '' ? $p['agency'] : null],
  ['📝 ملاحظة', ($p['note'] ?? '') !== '' ? $p['note'] : null],
];
$rows = array_values(array_filter($rows, fn($r)=>$r[1]!==null));
if ($rows): ?>
<div class="sec-head"><span>📄 تفاصيل إضافية</span></div>
<div class="card mb detail-grid">
  <?php foreach ($rows as [$lbl, $val]): ?>
    <div class="detail-cell">
      <div class="dc-lbl"><?= $lbl ?></div>
      <div class="dc-val"><?= e($val) ?></div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- رسوم الإذن (مراحل مستوى الإذن) -->
<?php
$permitMoney = array_filter($pStages, fn($k)=>$k!=='data' && $k!=='generators', ARRAY_FILTER_USE_KEY);
if ($permitMoney):
  // ترقيم طبيعي ثابت + ترتيب المكتمل أولًا ثم غير المكتمل
  $natNum=[]; $n=0; $doneS=[]; $pendS=[];
  foreach ($permitMoney as $key=>$st){ $natNum[$key]=++$n;
    $ents=$permitStages[$key]??[]; $real=array_filter($ents,fn($e)=>($e['status']??'')!=='none'); $none=(!$real && $ents);
    $autoP=($key==='primary_fees' && !$real && !$none && ($primaryDef['containers']??0)>0 && !empty($primaryDef['rates_set']));
    if($real||$none||$autoP) $doneS[$key]=$st; else $pendS[$key]=$st;
  }
  $orderedPermit = $doneS + $pendS; ?>
  <div class="sec-head"><span>🪙 رسوم الإذن</span></div>
  <div class="stages mb">
    <?php foreach ($orderedPermit as $key=>$st){ echo $roStage($key, $st, $permitStages[$key] ?? [], $natNum[$key]); } ?>
  </div>
<?php endif; ?>

<!-- ===== الحركات (قراءة) ===== -->
<div class="sec-head"><span>🔀 الحركات (<?= intf(count($movements)) ?>)</span></div>

<?php if (!$movements): ?>
  <div class="card"><div class="empty" style="padding:32px"><div class="ic">🔀</div>لا حركات مسجّلة على هذا الإذن.</div></div>
<?php else: ?>
  <?php $mvNo=0; foreach ($movements as $mv): $mid=(int)$mv['id']; $mvNo++;
    $mvBills = $mv['bills']; $mvStagesG = $mv['stages'];
    $mvExp = 0; $mvCust = 0; $mvCharged = 0;
    foreach ($mvStagesG as $kk=>$es){ foreach ($es as $e){
      if ($kk==='receipts' || ($kk==='custom' && ($e['direction']??'')==='expense')) $mvExp += (float)$e['amount'];
      if (isset($e['actual_amount']) && $e['actual_amount']!==null && $e['actual_amount']!=='') $mvCharged += (float)$e['actual_amount'];
    } }
    foreach ($mvBills as $b){ $mvCust += (float)$b['custody']; }
  ?>
    <details class="movement card" id="movement-<?= $mid ?>" <?= $mvNo===1?'open':'' ?>>
      <summary class="mv-head">
        <span class="mv-seq"><?= icon('shuffle',16) ?> حركة #<?= intf($mv['seq']) ?></span>
        <?php if($mv['note']): ?><span class="mv-note"><?= e($mv['note']) ?></span><?php endif; ?>
        <span style="flex:1"></span>
        <span class="mv-stats">
          <span title="عدد البوالص">🚛 <?= intf(count($mvBills)) ?></span>
          <span class="pos" title="العهدة">💰 <?= money($mvCust,false) ?></span>
          <span class="neg" title="المصروفات">💸 <?= money($mvExp,false) ?></span>
          <?php if($mvCharged>0): ?><span class="chg" title="إجمالي السعر الفعلي (للعميل)">👤 <?= money($mvCharged,false) ?></span><?php endif; ?>
        </span>
        <span class="ss-chev">▾</span>
      </summary>
      <div class="mv-body">
        <!-- شريط المراحل الأفقي -->
        <div class="stepper">
          <?php $sn=0; foreach ($mStages as $sk => $sm): $sn++;
            if ($sk==='bills')          { $shas = !empty($mvBills); }
            elseif ($sk==='generators') { $shas = !empty($generators); }
            else { $srows = array_filter($mvStagesG[$sk] ?? [], fn($x)=>($x['status']??'')!=='none');
                   $shas = !empty($srows) || (empty($srows) && !empty($mvStagesG[$sk] ?? [])); } ?>
            <span class="step-chip <?= $shas?'done':'' ?>"><span class="sc-dot"><?= $shas?'✓':$sn ?></span><span><?= e($sm['label']) ?></span></span>
          <?php endforeach; ?>
        </div>
        <div class="stages">
        <?php
        $mvItems = []; $si=0;
        foreach ($mStages as $key => $st): $si++;
          ob_start(); $has=false;
          if ($key === 'bills'):
            $bills = $mvBills; $has = !empty($bills); ?>
            <details class="stage <?= $bills?'has':'' ?>" <?= $bills?'open':'' ?>>
              <summary class="stage-sum">
                <span class="ss-ic <?= $bills?'ok':'' ?>"><?= $bills?'✓':'🚛' ?></span>
                <span class="ss-num"><?= $si ?></span><span class="ss-name">البوالص</span><span style="flex:1"></span>
                <?php if (count($bills)): ?><span class="ss-count"><?= intf(count($bills)) ?></span><?php endif; ?>
                <a class="ss-act" href="<?= $manageUrl('bills',$mid) ?>" title="تعديل البوالص" onclick="event.stopPropagation()">✏️</a>
                <a class="ss-act add" href="<?= $manageUrl('bills',$mid) ?>" title="إضافة بوليصة" onclick="event.stopPropagation()">➕</a>
                <span class="ss-chev">▾</span>
              </summary>
              <div class="stage-body">
                <?php if ($bills): ?>
                  <div class="table-wrap"><table class="data">
                    <thead><tr>
                      <th class="g-identity">رقم البوليصة</th><th class="g-identity">التاريخ</th>
                      <th class="g-vehicle">السائق</th><th class="g-vehicle">السيارة</th>
                      <th class="g-identity">الحاوية</th><th class="g-vehicle">العدد</th><th class="g-revenue">العهدة</th>
                      <th class="g-result">الدين</th><th class="g-result">المتبقي</th><th class="g-identity">الحالة</th><th class="g-identity"></th>
                    </tr></thead><tbody>
                    <?php foreach ($bills as $bl): ?>
                      <tr>
                        <td style="font-weight:700"><?= e($bl['bill_number'] ?: '—') ?></td>
                        <td><?= dfmt($bl['bill_date']) ?></td>
                        <td><?= e($bl['driver']) ?></td>
                        <td><?= e($bl['car_no']) ?></td>
                        <td><?= e($bl['container_no'] ?: '—') ?><?php if($bl['container_type']??''): ?> <span class="ctype-chip"><?= e($bl['container_type']) ?></span><?php endif; ?></td>
                        <td><?= intf($bl['container_count'] ?? 0) ?></td>
                        <td class="pos"><?= money($bl['custody'],false) ?></td>
                        <td><?= money($bl['debt'],false) ?></td>
                        <td class="<?= $bl['remaining']>0?'neg':'pos' ?>"><?= money($bl['remaining'],false) ?></td>
                        <td><span class="pill st-<?= e($bl['pay_status']) ?>"><?= e(Bill::STATUS_LABELS[$bl['pay_status']] ?? '') ?></span></td>
                        <td><div class="actions">
                          <a class="edit" href="<?= url('bawalis/'.$bl['id']) ?>" title="تفاصيل">🔎</a>
                          <a class="edit" href="<?= url('settlements/'.$bl['id']) ?>" title="سداد">💵</a>
                        </div></td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                <?php else: ?><div class="tl-hint">لا بوالص في هذه الحركة.</div><?php endif; ?>
              </div>
            </details>
          <?php elseif ($key === 'generators'):
            $has = !empty($generators);
            if ($has): ?>
            <details class="stage has">
              <summary class="stage-sum"><span class="ss-ic ok">✓</span><span class="ss-num"><?= $si ?></span>
                <span class="ss-name">المولدات</span><span style="flex:1"></span>
                <span class="ss-count"><?= intf(count($generators)) ?></span><span class="ss-chev">▾</span></summary>
              <div class="stage-body"><div class="table-wrap"><table class="data">
                <thead><tr><th class="g-identity">التاريخ</th><th class="g-identity">الشركة</th>
                  <th class="g-vehicle">العدد</th><th class="g-revenue">النولون</th><th class="g-result">المتبقي</th></tr></thead>
                <tbody><?php foreach ($generators as $g): ?>
                  <tr><td><?= dfmt($g['gen_date']) ?></td><td style="text-align:right"><?= e($g['company']) ?></td>
                    <td><?= intf($g['gen_count']) ?></td><td class="pos"><?= money($g['freight'],false) ?></td>
                    <td class="<?= $g['remaining']>0?'neg':'pos' ?>"><?= money($g['remaining'],false) ?></td></tr>
                <?php endforeach; ?></tbody></table></div></div>
            </details>
            <?php endif;
          else:
            $ents = $mvStagesG[$key] ?? [];
            $real = array_filter($ents, fn($e)=>($e['status']??'')!=='none'); $none=(!$real && $ents);
            $has = (bool)($real || $none);
            echo $roStage($key, $st, $ents, $si, $mid);
          endif;
          $html = ob_get_clean();
          if (trim($html) !== '') $mvItems[] = ['has'=>$has, 'html'=>$html];
        endforeach;
        // المكتمل أولًا ثم غير المكتمل (مع الحفاظ على الترتيب النسبي)
        $dH=''; $pH='';
        foreach ($mvItems as $it){ if($it['has']) $dH.=$it['html']; else $pH.=$it['html']; }
        echo $dH.$pH;
        ?>
        </div>
      </div>
    </details>
  <?php endforeach; ?>
<?php endif; ?>

<script>
(function(){
  if (location.hash){
    var t = document.querySelector(location.hash);
    if(t){ if(t.tagName==='DETAILS') t.open=true; setTimeout(function(){ t.scrollIntoView({block:'center'}); },60); }
  }
})();
</script>
