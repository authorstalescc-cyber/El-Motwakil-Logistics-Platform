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
  <div class="section-head" ><?= icon('banknote', 18) ?> تحصيلات العملاء</div>
  <div class="spacer"></div>
  <span class="count-badge"><?= intf($summary['count']) ?> دفعة</span>
</div>

<?php /* ⚠️ **ممنوع رقم أصمّ**: كل رقم بيفتح دفعاته من `GET /payments/drill/{what}`،
         و**بيمرّر الفلاتر الحالية** — الرقم محسوب على المعروض، فلو النافذة
         جابت الكل تبقى بتقول رقمًا تاني. */ ?>
<?php $pq = array_filter(['client' => $f['client'], 'route' => $f['route'],
                          'from' => $f['from'], 'to' => $f['to'], 'q' => $f['q']], static fn ($x) => $x !== '');
      $pd = static fn (string $w): string => url('payments/drill/' . $w
            . ($pq ? '?' . http_build_query($pq) : '')); ?>
<?php partial('stat_grid', ['items' => 4, 'class' => 'mb', 'body' => function () use ($summary, $br, $pd) { ?>
  <?php partial('stat', [
    'label' => 'إجمالي المحصّل', 'value' => intf($summary['grand']),
    'unit'  => intf($summary['count']) . ' دفعة',
    'icon'  => 'banknote', 'tone' => 'gold',
    'drill' => $pd('all'), 'title' => 'كل التحصيلات المعروضة',
  ]); ?>
  <?php partial('stat', [
    'label' => 'عهدة نقدية', 'value' => intf($br['cash']['value']),
    'unit'  => intf($br['cash']['count']) . ' دفعة',
    'icon'  => 'wallet', 'tone' => 'green',
    'drill' => $pd('cash'), 'title' => 'التحصيلات المتوجّهة للعهدة النقدية',
  ]); ?>
  <?php partial('stat', [
    'label' => 'شحن فيزا', 'value' => intf($br['visa']['value']),
    'unit'  => intf($br['visa']['count']) . ' دفعة',
    'icon'  => 'credit-card', 'tone' => 'teal',
    'drill' => $pd('visa'), 'title' => 'التحصيلات المتوجّهة لشحن الكروت',
  ]); ?>
  <?php partial('stat', [
    'label' => 'بدون توجيه', 'value' => intf($br['none']['value']),
    'unit'  => intf($br['none']['count']) . ' دفعة',
    'icon'  => 'file-text', 'tone' => 'navy',
    'drill' => $pd('none'), 'title' => 'تحصيلات في حساب العميل بس',
  ]); ?>
<?php }]); ?>

<!-- نموذج تسجيل دفعة -->
<div class="card cp-wrap mb">
  <div class="section-head p5-head-20"><?= icon('plus', 17) ?> تسجيل تحصيل من عميل</div>
  <div class="cp-form-box no-b">
    <?= formErrorSummary($formErrors) ?>
    <form method="post" action="<?= url('payments/pay') ?>" id="cpForm" class="js-dirty-form">
      <?= csrfField() ?>
      <?= formStateFields($formState['token'], $formState['back']) ?>
      <div class="cp-form-grid">
        <div class="field flush grow200">
          <label>العميل (الوكيل) *</label>
          <select name="client_name" required<?= fieldErrorAttrs('client_name', $formErrors) ?>>
            <option value="">— اختر العميل —</option>
            <?php foreach($agents as $ag): ?>
              <option value="<?= e($ag['name']) ?>" <?= (($formOld['client_name'] ?? $f['client'])===$ag['name'])?'selected':'' ?>><?= e($ag['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?= fieldError('client_name', $formErrors) ?>
        </div>
        <div class="field flush w150">
          <label>التاريخ</label>
          <input type="date" name="pay_date" value="<?= e($formOld['pay_date'] ?? work_date()) ?>"<?= fieldErrorAttrs('pay_date', $formErrors) ?>>
          <?= fieldError('pay_date', $formErrors) ?>
        </div>
        <div class="field flush w150">
          <label>المبلغ *</label>
          <input type="number" step="0.01" min="0.01" name="amount" value="<?= e($formOld['amount'] ?? '') ?>" placeholder="0" required<?= fieldErrorAttrs('amount', $formErrors) ?>>
          <?= fieldError('amount', $formErrors) ?>
        </div>
        <div class="field flush grow">
          <label>البيان (اختياري)</label>
          <input type="text" name="note" value="<?= e($formOld['note'] ?? '') ?>" placeholder="ملاحظة على الدفعة">
        </div>
      </div>
      <div class="cp-route">
        <span class="cp-route-lbl">توجيه الدفعة:</span>
        <?php $formRoute = $formOld['route'] ?? 'none'; ?>
        <label class="cp-route-opt"><input type="radio" name="route" value="none" <?= $formRoute==='none'?'checked':'' ?> onchange="cpRoute()"> <span>بدون توجيه</span></label>
        <label class="cp-route-opt"><input type="radio" name="route" value="cash" <?= $formRoute==='cash'?'checked':'' ?> onchange="cpRoute()"> <span><?= icon('banknote', 14) ?> عهدة نقدية (تُضاف لعهدة اليوم)</span></label>
        <label class="cp-route-opt"><input type="radio" name="route" value="visa" <?= $formRoute==='visa'?'checked':'' ?> onchange="cpRoute()"> <span><?= icon('credit-card', 14) ?> شحن كارت فيزا</span></label>
        <?php /* ⚠️ الخيار بيظهر بس لو `routeChoices()` فيه `company` — نفس القايمة
                 اللي الكنترولر بيتحقق بيها، وإلا خيار معروض والخادم يرفضه بالسكوت. */ ?>
        <?php if (isset(\App\Models\ClientPayment::routeChoices()['company'])): ?>
          <label class="cp-route-opt"><input type="radio" name="route" value="company" <?= $formRoute==='company'?'checked':'' ?> onchange="cpRoute()"> <span><?= icon('home', 14) ?> خزنة الشركة</span></label>
        <?php endif; ?>
        <span id="cpVisaBox" class="cp-visa-box"<?= $formRoute==='visa' ? '' : ' hidden' ?>><?= visa_card_select($formOld['visa_card_id'] ?? null) ?><?= fieldError('visa_card_id', $formErrors) ?></span>
      </div>
      <label class="cp-route-opt p5-mb"><input type="checkbox" name="auto_allocate" value="1" <?= $autoAllocateChecked?'checked':'' ?>>
        <span><b>تخصيص تلقائي</b> على أقدم الفواتير المستحقة لهذا العميل</span></label>
      <div class="cp-form-actions">
        <button class="btn gold" type="submit" data-primary-submit>＋ تسجيل التحصيل</button>
        <span class="cp-hint">«نقدي» تدخل التقفيل اليومي (عهدة اليوم)، و«فيزا» تشحن رصيد الكارت.</span>
      </div>
    </form>
  </div>
</div>

<!-- الفلاتر -->
<div class="card mb p5-pad-sm">
  <form method="get" action="<?= url('payments') ?>" class="p5-row end">
    <div class="field flush grow">
      <label>العميل</label>
      <select name="client">
        <option value="">— الكل —</option>
        <?php foreach($agents as $ag): ?>
          <option value="<?= e($ag['name']) ?>" <?= $f['client']===$ag['name']?'selected':'' ?>><?= e($ag['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field flush w150">
      <label>التوجيه</label>
      <select name="route">
        <option value="">— الكل —</option>
        <option value="none" <?= $f['route']==='none'?'selected':'' ?>>بدون توجيه</option>
        <option value="cash" <?= $f['route']==='cash'?'selected':'' ?>>عهدة نقدية</option>
        <option value="visa" <?= $f['route']==='visa'?'selected':'' ?>>شحن فيزا</option>
        <?php if (isset(\App\Models\ClientPayment::routeChoices()['company'])): ?>
          <option value="company" <?= $f['route']==='company'?'selected':'' ?>>خزنة الشركة</option>
        <?php endif; ?>
      </select>
    </div>
    <div class="field flush w150"><label>من تاريخ</label><input type="date" name="from" value="<?= e($f['from']) ?>"></div>
    <div class="field flush w150"><label>إلى تاريخ</label><input type="date" name="to" value="<?= e($f['to']) ?>"></div>
    <div class="field flush grow150"><label>بحث</label><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="عميل / بيان"></div>
    <button class="btn" type="submit">تصفية</button>
    <?php if($hasFilter): ?><a class="btn ghost" href="<?= url('payments') ?>"><?= icon('x', 14) ?> مسح</a><?php endif; ?>
  </form>
</div>

<!-- السجل -->
<div class="card">
  <div class="section-head rts-head"><?= icon('layers', 17) ?> سجل الدفعات <span class="oc-note">(<?= count($payments) ?>)</span></div>
  <div class="table-wrap"><table class="data">
    <thead><tr>
      <th class="g-identity">التاريخ</th><th class="g-identity">العميل</th><th class="g-identity">المبلغ</th>
      <th class="g-identity">التوجيه</th><th class="g-identity">التخصيص</th><th class="g-identity">الحالة</th><th class="g-identity">البيان</th><th class="g-identity"></th>
    </tr></thead>
    <tbody>
      <?php if(!$payments): ?>
        <tr><td colspan="8"><div class="empty"><div class="ic"><?= icon('inbox', 14) ?></div><?= $hasFilter?'لا نتائج مطابقة للفلاتر.':'لا تحصيلات مسجّلة بعد. سجّل أول تحصيل من الأعلى.' ?></div></td></tr>
      <?php else: foreach($payments as $pm): ?>
        <tr>
          <td><?= $pm['pay_date'] ? e(dfmt($pm['pay_date'])) : '—' ?></td>
          <td class="txt mv-ref"><?= e($pm['client_name']) ?></td>
          <td class="pos dbt-tot"><?= money($pm['amount'],false) ?></td>
          <td>
            <?php if($pm['route']==='cash'): ?>
              <span class="pill st-paid"><?= icon('banknote', 14) ?> عهدة نقدية</span>
            <?php elseif($pm['route']==='visa'): ?>
              <span class="pill st-partial"><?= icon('credit-card', 14) ?> <?= e($cardName[(int)$pm['visa_card_id']] ?? 'فيزا') ?></span>
            <?php elseif($pm['route']==='company'): ?>
              <span class="pill st-paid"><?= icon('home', 14) ?> خزنة الشركة</span>
            <?php else: ?>
              <span class="pill dag-mut-cell">بدون توجيه</span>
            <?php endif; ?>
          </td>
          <td><?= $pm['status']==='posted' ? ('غير مخصص: '.money($pm['unallocated_amount'],false)) : '—' ?></td>
          <td><span class="pill <?= $pm['status']==='reversed'?'st-unpaid':'st-paid' ?>"><?= $pm['status']==='reversed'?'معكوس':'مرحّل' ?></span></td>
          <td class="txt"><?= e($pm['note'] ?: '—') ?></td>
          <td class="nowrap">
            <?php if($pm['status']==='posted'): ?><form method="post" action="<?= url('payments/'.$pm['id'].'/delete') ?>"
                   data-danger="1" data-confirm="عكس هذا التحصيل؟ ستُلغى تخصيصاته وأثره في العهدة/الفيزا مع بقاء السجل.">
              <?= csrfField() ?>
              <?= formStateFields(FormState::token(), current_app_path()) ?>
              <input type="text" name="reason" required placeholder="سبب العكس" class="w110">
              <button class="edit oc-btn-bare" type="submit" title="عكس التحصيل"><?= icon('undo', 14) ?></button>
            </form><?php else: ?><small><?= e($pm['reverse_reason'] ?? '') ?></small><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
    <?php if($payments): ?>
    <tfoot><tr>
      <td colspan="2" class="dag-tot end">إجمالي المعروض</td>
      <td class="dag-tot"><?= money(array_sum(array_map(fn($p)=>(float)$p['amount'],$payments)),false) ?></td>
      <td colspan="5" class="bg-gold"></td>
    </tr></tfoot>
    <?php endif; ?>
  </table></div>
</div>

<style>
.cp-form-box{padding:14px 20px 18px;background:linear-gradient(180deg,var(--zebra),var(--card))}
.cp-form-grid{display:flex;flex-wrap:wrap;gap:12px;align-items:end;margin-bottom:12px}
.cp-route{display:flex;flex-wrap:wrap;align-items:center;gap:14px;margin-bottom:12px}
.cp-route-lbl{font-weight:600;color:var(--accent);font-size:var(--fs-3)}
.cp-route-opt{display:flex;align-items:center;gap:6px;font-size:var(--fs-2);font-weight:400;cursor:pointer}
.cp-visa-box{display:flex;align-items:center}
.cp-visa-box select{padding:7px 10px;border:1px solid var(--line2);border-radius:var(--radius-1);font-family:inherit}
.cp-form-actions{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.cp-hint{font-size:var(--fs-1);color:var(--grey)}
</style>
<script>
function cpRoute(){
  var v=document.querySelector('#cpForm input[name=route]:checked');
  /* ⚠️ سمة `hidden` مش `style.display`: `[hidden]{display:none!important}` في
     app.css بتغلب أي `display` بيتحط من الجافاسكريبت، فلو فضلنا على
     `style.display` الخانة ما بتظهرش أبدًا. */
  document.getElementById('cpVisaBox').hidden = !(v&&v.value==='visa');
}
</script>
