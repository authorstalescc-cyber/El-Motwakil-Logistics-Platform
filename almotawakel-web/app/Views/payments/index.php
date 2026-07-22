<?php
/** @var array $f @var array $payments @var array $summary @var array $agents @var array $visaCards */
use App\Core\FormState;
$formState = $formState ?? ['token'=>'','old'=>[],'errors'=>[],'back'=>'payments'];
$formOld = $formState['old'];
$formErrors = $formState['errors'];
$autoAllocateChecked = $formErrors ? !empty($formOld['auto_allocate']) : true;
$br = $summary['by_route'];
$cardName = [];
foreach ($visaCards as $vc) { $cardName[(int)$vc['id']] = $vc['name'].($vc['owner']?' — '.$vc['owner']:''); }
$hasFilter = ($f['client']!=='' || $f['route']!=='' || $f['from']!=='' || $f['to']!=='' || $f['q']!=='');
?>
<div class="toolbar">
  <div class="section-head" style="margin:0">💳 تحصيلات العملاء</div>
  <div class="spacer"></div>
  <span class="count-badge"><?= intf($summary['count']) ?> دفعة</span>
</div>

<!-- الإجماليات -->
<div class="stat-strip mb">
  <div class="stat-item"><div class="si-ic gold">💰</div><div><b><?= money($summary['grand'],false) ?></b><span>إجمالي المحصّل</span></div></div>
  <div class="stat-item"><div class="si-ic green">💵</div><div><b><?= money($br['cash']['value'],false) ?></b><span>عهدة نقدية (<?= intf($br['cash']['count']) ?>)</span></div></div>
  <div class="stat-item"><div class="si-ic teal">💳</div><div><b><?= money($br['visa']['value'],false) ?></b><span>شحن فيزا (<?= intf($br['visa']['count']) ?>)</span></div></div>
  <div class="stat-item"><div class="si-ic navy">📝</div><div><b><?= money($br['none']['value'],false) ?></b><span>بدون توجيه (<?= intf($br['none']['count']) ?>)</span></div></div>
</div>

<!-- نموذج تسجيل دفعة -->
<div class="card cp-wrap mb">
  <div class="section-head" style="padding:16px 20px 0;margin:0">➕ تسجيل تحصيل من عميل</div>
  <div class="cp-form-box" style="border-bottom:0">
    <?= formErrorSummary($formErrors) ?>
    <form method="post" action="<?= url('payments/pay') ?>" id="cpForm" class="js-dirty-form">
      <?= csrfField() ?>
      <?= formStateFields($formState['token'], $formState['back']) ?>
      <div class="cp-form-grid">
        <div class="field" style="margin:0;flex:1;min-width:200px">
          <label>العميل (الوكيل) *</label>
          <select name="client_name" required<?= fieldErrorAttrs('client_name', $formErrors) ?>>
            <option value="">— اختر العميل —</option>
            <?php foreach($agents as $ag): ?>
              <option value="<?= e($ag['name']) ?>" <?= (($formOld['client_name'] ?? $f['client'])===$ag['name'])?'selected':'' ?>><?= e($ag['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?= fieldError('client_name', $formErrors) ?>
        </div>
        <div class="field" style="margin:0;width:150px">
          <label>التاريخ</label>
          <input type="date" name="pay_date" value="<?= e($formOld['pay_date'] ?? work_date()) ?>"<?= fieldErrorAttrs('pay_date', $formErrors) ?>>
          <?= fieldError('pay_date', $formErrors) ?>
        </div>
        <div class="field" style="margin:0;width:150px">
          <label>المبلغ *</label>
          <input type="number" step="0.01" min="0.01" name="amount" value="<?= e($formOld['amount'] ?? '') ?>" placeholder="0" required<?= fieldErrorAttrs('amount', $formErrors) ?>>
          <?= fieldError('amount', $formErrors) ?>
        </div>
        <div class="field" style="margin:0;flex:1;min-width:160px">
          <label>البيان (اختياري)</label>
          <input type="text" name="note" value="<?= e($formOld['note'] ?? '') ?>" placeholder="ملاحظة على الدفعة">
        </div>
      </div>
      <div class="cp-route">
        <span class="cp-route-lbl">توجيه الدفعة:</span>
        <?php $formRoute = $formOld['route'] ?? 'none'; ?>
        <label class="cp-route-opt"><input type="radio" name="route" value="none" <?= $formRoute==='none'?'checked':'' ?> onchange="cpRoute()"> <span>بدون توجيه</span></label>
        <label class="cp-route-opt"><input type="radio" name="route" value="cash" <?= $formRoute==='cash'?'checked':'' ?> onchange="cpRoute()"> <span>💵 عهدة نقدية (تُضاف لعهدة اليوم)</span></label>
        <label class="cp-route-opt"><input type="radio" name="route" value="visa" <?= $formRoute==='visa'?'checked':'' ?> onchange="cpRoute()"> <span>💳 شحن كارت فيزا</span></label>
        <span id="cpVisaBox" class="cp-visa-box" style="display:<?= $formRoute==='visa'?'flex':'none' ?>"><?= visa_card_select($formOld['visa_card_id'] ?? null) ?><?= fieldError('visa_card_id', $formErrors) ?></span>
      </div>
      <label class="cp-route-opt" style="margin-bottom:12px"><input type="checkbox" name="auto_allocate" value="1" <?= $autoAllocateChecked?'checked':'' ?>>
        <span><b>تخصيص تلقائي</b> على أقدم الفواتير المستحقة لهذا العميل</span></label>
      <div class="cp-form-actions">
        <button class="btn gold" type="submit" data-primary-submit>＋ تسجيل التحصيل</button>
        <span class="cp-hint">«نقدي» تدخل التقفيل اليومي (عهدة اليوم)، و«فيزا» تشحن رصيد الكارت.</span>
      </div>
    </form>
  </div>
</div>

<!-- الفلاتر -->
<div class="card mb" style="padding:12px 16px">
  <form method="get" action="<?= url('payments') ?>" style="display:flex;flex-wrap:wrap;gap:10px;align-items:end">
    <div class="field" style="margin:0;flex:1;min-width:160px">
      <label>العميل</label>
      <select name="client">
        <option value="">— الكل —</option>
        <?php foreach($agents as $ag): ?>
          <option value="<?= e($ag['name']) ?>" <?= $f['client']===$ag['name']?'selected':'' ?>><?= e($ag['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="margin:0;width:150px">
      <label>التوجيه</label>
      <select name="route">
        <option value="">— الكل —</option>
        <option value="none" <?= $f['route']==='none'?'selected':'' ?>>بدون توجيه</option>
        <option value="cash" <?= $f['route']==='cash'?'selected':'' ?>>عهدة نقدية</option>
        <option value="visa" <?= $f['route']==='visa'?'selected':'' ?>>شحن فيزا</option>
      </select>
    </div>
    <div class="field" style="margin:0;width:150px"><label>من تاريخ</label><input type="date" name="from" value="<?= e($f['from']) ?>"></div>
    <div class="field" style="margin:0;width:150px"><label>إلى تاريخ</label><input type="date" name="to" value="<?= e($f['to']) ?>"></div>
    <div class="field" style="margin:0;flex:1;min-width:150px"><label>بحث</label><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="عميل / بيان"></div>
    <button class="btn" type="submit">تصفية</button>
    <?php if($hasFilter): ?><a class="btn ghost" href="<?= url('payments') ?>">✕ مسح</a><?php endif; ?>
  </form>
</div>

<!-- السجل -->
<div class="card">
  <div class="section-head" style="padding:16px 18px 0;margin:0">📒 سجل الدفعات <span style="color:var(--grey);font-weight:400;font-size:.85rem">(<?= count($payments) ?>)</span></div>
  <div class="table-wrap"><table class="data">
    <thead><tr>
      <th class="g-identity">التاريخ</th><th class="g-identity">العميل</th><th class="g-identity">المبلغ</th>
      <th class="g-identity">التوجيه</th><th class="g-identity">التخصيص</th><th class="g-identity">الحالة</th><th class="g-identity">البيان</th><th class="g-identity"></th>
    </tr></thead>
    <tbody>
      <?php if(!$payments): ?>
        <tr><td colspan="8"><div class="empty"><div class="ic">📭</div><?= $hasFilter?'لا نتائج مطابقة للفلاتر.':'لا تحصيلات مسجّلة بعد. سجّل أول تحصيل من الأعلى.' ?></div></td></tr>
      <?php else: foreach($payments as $pm): ?>
        <tr>
          <td><?= $pm['pay_date'] ? e(dfmt($pm['pay_date'])) : '—' ?></td>
          <td style="text-align:right;font-weight:700"><?= e($pm['client_name']) ?></td>
          <td class="pos" style="font-weight:800"><?= money($pm['amount'],false) ?></td>
          <td>
            <?php if($pm['route']==='cash'): ?>
              <span class="pill st-paid">💵 عهدة نقدية</span>
            <?php elseif($pm['route']==='visa'): ?>
              <span class="pill st-partial">💳 <?= e($cardName[(int)$pm['visa_card_id']] ?? 'فيزا') ?></span>
            <?php else: ?>
              <span class="pill" style="background:var(--bg);color:var(--grey)">بدون توجيه</span>
            <?php endif; ?>
          </td>
          <td><?= $pm['status']==='posted' ? ('غير مخصص: '.money($pm['unallocated_amount'],false)) : '—' ?></td>
          <td><span class="pill <?= $pm['status']==='reversed'?'st-unpaid':'st-paid' ?>"><?= $pm['status']==='reversed'?'معكوس':'مرحّل' ?></span></td>
          <td style="text-align:right"><?= e($pm['note'] ?: '—') ?></td>
          <td style="white-space:nowrap">
            <?php if($pm['status']==='posted'): ?><form method="post" action="<?= url('payments/'.$pm['id'].'/delete') ?>"
                  style="display:inline" data-danger="1" data-confirm="عكس هذا التحصيل؟ ستُلغى تخصيصاته وأثره في العهدة/الفيزا مع بقاء السجل.">
              <?= csrfField() ?>
              <?= formStateFields(FormState::token(), current_app_path()) ?>
              <input type="text" name="reason" required placeholder="سبب العكس" style="width:110px">
              <button class="edit" type="submit" title="عكس التحصيل" style="background:none;border:0;cursor:pointer">↩️</button>
            </form><?php else: ?><small><?= e($pm['reverse_reason'] ?? '') ?></small><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
    <?php if($payments): ?>
    <tfoot><tr>
      <td colspan="2" style="background:var(--gold-light);font-weight:800;text-align:left">إجمالي المعروض</td>
      <td style="background:var(--gold-light);font-weight:800"><?= money(array_sum(array_map(fn($p)=>(float)$p['amount'],$payments)),false) ?></td>
      <td colspan="5" style="background:var(--gold-light)"></td>
    </tr></tfoot>
    <?php endif; ?>
  </table></div>
</div>

<style>
.cp-form-box{padding:14px 20px 18px;background:linear-gradient(180deg,#fafbfd,#fff)}
.cp-form-grid{display:flex;flex-wrap:wrap;gap:12px;align-items:end;margin-bottom:12px}
.cp-route{display:flex;flex-wrap:wrap;align-items:center;gap:14px;margin-bottom:12px}
.cp-route-lbl{font-weight:800;color:var(--navy);font-size:.88rem}
.cp-route-opt{display:flex;align-items:center;gap:6px;font-size:.86rem;font-weight:600;cursor:pointer}
.cp-visa-box{display:flex;align-items:center}
.cp-visa-box select{padding:7px 10px;border:1px solid var(--line2);border-radius:8px;font-family:inherit}
.cp-form-actions{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.cp-hint{font-size:.78rem;color:var(--grey)}
</style>
<script>
function cpRoute(){
  var v=document.querySelector('#cpForm input[name=route]:checked');
  document.getElementById('cpVisaBox').style.display=(v&&v.value==='visa')?'flex':'none';
}
</script>
