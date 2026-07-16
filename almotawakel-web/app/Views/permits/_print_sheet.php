<?php
use App\Models\Permit;

/**
 * ورقة مراجعة إذن واحد (تُستخدَم في print.php المفرد وprint_batch.php المجمّع).
 * تتوقّع في النطاق: $p, $movements (بـ bills+stages), $permitStages, $generators,
 * $totals, $ctBreakdown, $mvCtMap, $primaryDef, $rcpCoverage
 * + المشترَك من الغلاف: $stageTable (closure)، $company، $currency، $logoRel.
 */
$pid      = (int)$p['id'];
$type     = $p['permit_type'];
$th       = Permit::theme($type);
$noLabel  = Permit::noLabel($type);
$isClosed = ($p['status'] ?? 'open') === 'closed';
$ctLbl    = Permit::containerLabel($ctBreakdown ?? [], (int)($p['containers'] ?? 0), $p['container_type'] ?? null);
$printedAt = dfmt(work_date());
?>
<div class="sheet">
  <div class="topbar"></div>
  <div class="wm"><?php if ($logoRel): ?><img src="<?= url($logoRel) ?>" alt=""><?php endif; ?></div>
  <div class="pad">

    <!-- الترويسة -->
    <div class="head">
      <div class="brand">
        <?php if ($logoRel): ?><img class="brandlogo" src="<?= url($logoRel) ?>" alt="<?= e($company) ?>">
        <?php else: ?><div class="logo">م</div><?php endif; ?>
        <div class="cap">لمقاولات النقل والتخليص</div>
      </div>
      <div class="doc-tag">
        <span class="word">كشف مراجعة الإذن</span>
        <div class="meta">
          <?= e($noLabel) ?>: <b><?= e($p['permit_no'] ?: '#'.$pid) ?></b>
          &nbsp;•&nbsp; تاريخ الإذن: <b><?= dfmt($p['permit_date']) ?: '—' ?></b>
          &nbsp;•&nbsp; تاريخ الطباعة: <b><?= e($printedAt) ?></b>
        </div>
        <div>
          <span class="type-chip <?= $th['cls'] ?>"><?= $th['arrow'] ?> <?= $th['full'] ?></span>
          <span class="st-chip <?= $isClosed?'closed':'open' ?>"><?= $isClosed?'مقفول ✓':'جارٍ' ?></span>
        </div>
      </div>
    </div>

    <!-- بيانات الإذن -->
    <div class="sec">
      <div class="sec-h">📄 بيانات الإذن</div>
      <div class="info">
        <?php
        $cells = [
          ['لصالح شركة', $p['company'] ?: '—'],
          ['الوكيل / العميل', $p['client'] ?: '—'],
          ['الجهة', $p['destination'] ?: '—'],
          ['الميناء', ($p['port'] ?? '') ?: '—'],
          ['شركة الشحن', ($p['shipping_co'] ?? '') ?: '—'],
          ['التوكيل الملاحي', ($p['agency'] ?? '') ?: '—'],
          ['الحاويات (عدد×تصنيف)', $ctLbl !== '' ? $ctLbl : '—'],
          ['عدد الحاويات', intf($p['containers'] ?? 0)],
          ['سعر الكشف / حاوية', $p['inspection_rate']!==null ? money($p['inspection_rate'],false) : 'غير مُدخَل'],
          ['سعر الضمان / حاوية', $p['guarantee_rate']!==null ? money($p['guarantee_rate'],false) : 'غير مُدخَل'],
        ];
        foreach ($cells as [$l,$v]): ?>
          <div class="cell"><div class="l"><?= e($l) ?></div><div class="v"><?= e($v) ?></div></div>
        <?php endforeach; ?>
        <?php if (($p['note'] ?? '') !== ''): ?>
          <div class="cell wide"><div class="l">ملاحظة الإذن</div><div class="v"><?= e($p['note']) ?></div></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- الملخّص المالي -->
    <div class="sec">
      <div class="sec-h">📊 الملخّص المالي</div>
      <div class="tiles">
        <div class="tile"><span class="tl">عدد الحركات</span><span class="tv"><?= intf(count($movements)) ?></span></div>
        <div class="tile"><span class="tl">عدد البوالص</span><span class="tv"><?= intf($totals['bills']) ?></span></div>
        <div class="tile pos"><span class="tl">إجمالي العهدة</span><span class="tv"><?= money($totals['custody'],false) ?></span></div>
        <div class="tile neg"><span class="tl">إجمالي المصروفات</span><span class="tv"><?= money($totals['expense'],false) ?></span></div>
        <div class="tile gold"><span class="tl">السعر الفعلي (للعميل)</span><span class="tv"><?= money($totals['charged'] ?? 0,false) ?></span></div>
        <div class="tile"><span class="tl">دين البوالص</span><span class="tv"><?= money($totals['debt'],false) ?></span></div>
        <div class="tile <?= $totals['remaining']>0?'neg':'pos' ?>"><span class="tl">المتبقّي على الشركة</span><span class="tv"><?= money($totals['remaining'],false) ?></span></div>
        <div class="tile"><span class="tl">المحصّل الإضافي</span><span class="tv"><?= money($totals['fees'] ?? 0,false) ?></span></div>
      </div>
    </div>

    <!-- رسوم الإذن (مستوى الإذن) -->
    <?php
    $pfEntries = $permitStages['primary_fees'] ?? [];
    $pfReal    = array_values(array_filter($pfEntries, fn($e)=>($e['status']??'')!=='none'));
    $pfNone    = (!$pfReal && $pfEntries);
    $pd        = $primaryDef ?? ['containers'=>0];
    $autoPF    = (!$pfReal && !$pfNone && ($pd['containers'] ?? 0) > 0 && !empty($pd['rates_set']));
    if ($pfReal || $pfNone || $autoPF): ?>
    <div class="sec">
      <div class="sec-h">🪙 الرسوم الأولية (الكشف والضمان)</div>
      <?php if ($pfReal): [$html,,] = $stageTable($pfReal, false, false); echo $html;
      elseif ($autoPF): ?>
        <div class="auto">
          <div class="ah">محسوبة تلقائيًا من حاويات الإذن (<?= intf($pd['containers']) ?> حاوية):</div>
          <div class="ar"><span>الكشف</span><span><?= intf($pd['containers']) ?> × <?= money($pd['inspection_rate'],false) ?></span><b><?= money($pd['inspection'],false) ?></b></div>
          <div class="ar"><span>الضمان</span><span><?= intf($pd['containers']) ?> × <?= money($pd['guarantee_rate'],false) ?></span><b><?= money($pd['guarantee'],false) ?></b></div>
          <div class="ar tot"><span>الإجمالي</span><span></span><b><?= money($pd['total'],false) ?></b></div>
        </div>
      <?php else: ?>
        <div class="none-note">🚫 معلَّمة «لا يوجد».</div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- الحركات -->
    <div class="sec">
      <div class="sec-h">🔀 الحركات <span class="cnt"><?= intf(count($movements)) ?> حركة</span></div>

      <?php if (!$movements): ?>
        <div class="empty-row">لا حركات مسجّلة على هذا الإذن.</div>
      <?php else: $mvNo=0; foreach ($movements as $mv): $mid=(int)$mv['id']; $mvNo++;
        $mvBills = $mv['bills']; $mvStagesG = $mv['stages'];
        $mvExp=0; $mvCust=0; $mvCharged=0;
        foreach ($mvStagesG as $kk=>$es){ foreach ($es as $e){
          if (($e['status']??'')==='none') continue;
          if ($kk==='receipts' || ($kk==='custom' && ($e['direction']??'')==='expense')) $mvExp += (float)$e['amount'];
          if (isset($e['actual_amount']) && $e['actual_amount']!==null && $e['actual_amount']!=='') $mvCharged += (float)$e['actual_amount'];
        } }
        foreach ($mvBills as $b){ $mvCust += (float)$b['custody']; }
        $mvCt = $mvCtMap[$mid] ?? [];
        $mvCtLbl = Permit::containerLabel($mvCt, (int)($mv['container_count'] ?? 0), $mv['container_type'] ?? null);
      ?>
        <div class="mv">
          <div class="mv-head">
            <span class="seq">🔀 حركة #<?= intf($mv['seq']) ?></span>
            <?php if($mv['note']): ?><span class="note"><?= e($mv['note']) ?></span><?php endif; ?>
            <?php if($mvCtLbl!==''): ?><span class="ct">📦 <?= e($mvCtLbl) ?></span><?php endif; ?>
            <span class="st">
              <span>🚛 <span class="v"><?= intf(count($mvBills)) ?></span></span>
              <span>💰 <span class="v"><?= money($mvCust,false) ?></span></span>
              <span>💸 <span class="v"><?= money($mvExp,false) ?></span></span>
              <?php if($mvCharged>0): ?><span>👤 <span class="v"><?= money($mvCharged,false) ?></span></span><?php endif; ?>
            </span>
          </div>
          <div class="mv-body">

            <!-- البوالص -->
            <div class="blk">
              <div class="blk-h">🚛 البوالص <span class="n"><?= intf(count($mvBills)) ?></span></div>
              <?php if ($mvBills): ?>
                <table class="grid">
                  <thead><tr>
                    <th style="width:5%">م</th><th style="width:16%">رقم البوليصة</th><th style="width:13%">التاريخ</th>
                    <th style="width:22%">السائق</th><th style="width:18%">السيارة</th><th style="width:14%">الحاوية</th>
                    <th style="width:12%">العهدة</th>
                  </tr></thead>
                  <tbody>
                  <?php $bi=0; $tCust=0; foreach ($mvBills as $bl): $bi++;
                    $tCust+=(float)$bl['custody']; ?>
                    <tr>
                      <td class="mut"><?= $bi ?></td>
                      <td class="num" style="font-weight:700"><?= e($bl['bill_number'] ?: '—') ?></td>
                      <td class="num"><?= dfmt($bl['bill_date']) ?></td>
                      <td><?= e($bl['driver'] ?: '—') ?></td>
                      <td class="num"><?= e($bl['car_no'] ?: '—') ?></td>
                      <td class="num"><?= e($bl['container_no'] ?: '—') ?><?php if($bl['container_type']??''): ?> <span class="chip ct"><?= e($bl['container_type']) ?></span><?php endif; ?></td>
                      <td class="num pos"><?= money($bl['custody'],false) ?></td>
                    </tr>
                  <?php endforeach; ?>
                    <tr class="sumrow">
                      <td colspan="6">الإجمالي</td>
                      <td class="num"><?= money($tCust,false) ?></td>
                    </tr>
                  </tbody>
                </table>
              <?php else: ?><div class="none-note">لا بوالص في هذه الحركة.</div><?php endif; ?>
            </div>

            <!-- الإيصالات -->
            <?php
            $rc = $mvStagesG['receipts'] ?? [];
            $rcReal = array_filter($rc, fn($e)=>($e['status']??'')!=='none');
            if ($rcReal): ?>
              <div class="blk">
                <div class="blk-h">🧾 الإيصالات <span class="n"><?= intf(count($rcReal)) ?></span></div>
                <?php [$html,,] = $stageTable(array_values($rcReal), true, false); echo $html; ?>
              </div>
            <?php elseif (isset($mvStagesG['receipts'])): ?>
              <div class="blk"><div class="blk-h">🧾 الإيصالات</div><div class="none-note">🚫 معلَّمة «لا يوجد».</div></div>
            <?php endif; ?>

            <!-- المصاريف الإضافية -->
            <?php
            $cs = $mvStagesG['custom'] ?? [];
            $csReal = array_filter($cs, fn($e)=>($e['status']??'')!=='none');
            if ($csReal): ?>
              <div class="blk">
                <div class="blk-h">➕ مصروفات / بنود إضافية <span class="n"><?= intf(count($csReal)) ?></span></div>
                <?php [$html,,] = $stageTable(array_values($csReal), true, true); echo $html; ?>
              </div>
            <?php endif; ?>

          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- المولدات (على مستوى رقم الإذن) -->
    <?php if (!empty($generators)): ?>
    <div class="sec">
      <div class="sec-h">⚙️ المولدات <span class="cnt"><?= intf(count($generators)) ?></span></div>
      <table class="grid">
        <thead><tr><th style="width:30px">م</th><th style="width:90px">التاريخ</th><th class="r">الشركة</th>
          <th style="width:60px">العدد</th><th style="width:120px">النولون</th><th style="width:120px">المتبقّي</th></tr></thead>
        <tbody>
        <?php $gi=0;$gF=0;$gR=0; foreach ($generators as $g): $gi++; $gF+=(float)$g['freight']; $gR+=(float)$g['remaining']; ?>
          <tr>
            <td class="mut"><?= $gi ?></td>
            <td class="num"><?= dfmt($g['gen_date']) ?></td>
            <td class="r"><?= e($g['company'] ?: '—') ?></td>
            <td class="num"><?= intf($g['gen_count']) ?></td>
            <td class="num pos"><?= money($g['freight'],false) ?></td>
            <td class="num <?= $g['remaining']>0?'neg':'pos' ?>"><?= money($g['remaining'],false) ?></td>
          </tr>
        <?php endforeach; ?>
          <tr class="sumrow"><td colspan="4">الإجمالي</td><td class="num"><?= money($gF,false) ?></td><td class="num"><?= money($gR,false) ?></td></tr>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

  </div>
</div>
