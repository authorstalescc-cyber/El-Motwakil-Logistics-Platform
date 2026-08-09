<?php
use App\Models\Bill;
use App\Core\FormState;
/**
 * جدول بوالص قابل للتحديد + شريط دفع جماعي (مشترك بين نتائج البحث وصفحة صاحب البيان).
 * المتغيّرات المتوقّعة: $bills (array), $backQS (string), $showOwner (bool, افتراضي false)
 */
$showOwner = $showOwner ?? false;
$cols      = $showOwner ? 16 : 15;
$typeLabel = fn($t) => $t === 'warid' ? 'وارد' : 'صادر';
$payState = $payState ?? ['token'=>FormState::token(),'old'=>[],'errors'=>[],'back'=>'settlements'];
$payOld = $payState['old'];
?>
<?php require __DIR__ . '/../bawalis/_bulk_actions.php'; ?>
<?= formErrorSummary($payState['errors']) ?>
<form class="js-dirty-form" method="post" action="<?= url('settlements/pay') ?>" id="payForm" data-confirm="تسجيل سداد للبوالص المحدّدة؟">
  <?= csrfField() ?>
  <?= formStateFields($payState['token'], $payState['back']) ?>
  <input type="hidden" name="back" value="<?= e($backQS ?? '') ?>">
  <div class="card">
    <div class="table-wrap">
      <table class="data" id="billsTable">
        <thead><tr>
          <th class="g-result col-pick" data-no-sort><input type="checkbox" id="selAll" class="bill-pick js-bill-pick-all" title="تحديد كل بوالص الجدول"></th>
          <th class="g-identity">التاريخ</th>
          <th class="g-identity">النوع</th>
          <th class="g-identity">رقم البوليصة</th>
          <th class="g-identity">إذن/شهادة</th>
          <?php if ($showOwner): ?><th class="g-identity">صاحب البيان</th><?php endif; ?>
          <th class="g-identity">الشركة / العميل</th>
          <th class="g-identity">الجهة</th>
          <th class="g-identity">الحاوية</th>
          <th class="g-identity">السيارة</th>
          <th class="g-result">الدين</th>
          <th class="g-revenue">المدفوع</th>
          <th class="g-expense">المتبقّي</th>
          <th class="g-identity">الحالة</th>
          <th class="g-revenue col-pay">دفعة الآن</th>
          <th class="g-identity">تفاصيل</th>
        </tr></thead>
        <tbody>
          <?php if (!$bills): ?>
            <tr><td colspan="<?= $cols ?>"><div class="empty"><div class="ic"><?= icon('search', 14) ?></div>لا بوالص مطابقة.</div></td></tr>
          <?php else: foreach ($bills as $b):
            $rem  = (float)$b['remaining'];
            $due  = $b['pay_status'] !== 'paid' && $rem > 0.005;
            $ref  = $b['bill_type'] === 'warid' ? $b['cert_number'] : $b['permit_no'];
            $cont = trim(($b['container_type'] ?? '') . ' ' . ($b['container_no'] ?? ''));
            $own  = trim((string)($b['declaration_owner'] ?? ''));
          ?>
            <tr class="bill-row<?= $due ? ' payable' : '' ?>" data-rem="<?= e(number_format($rem, 2, '.', '')) ?>">
              <td><input type="checkbox" class="row-sel bill-pick js-bill-pick" value="<?= (int)$b['id'] ?>" <?= (float)($payOld['amount'][$b['id']] ?? 0)>0?'checked':'' ?>
                data-no="<?= e($b['bill_number'] ?: '#'.$b['id']) ?>" data-date="<?= e($b['bill_date'] ?? '') ?>"
                data-freight="<?= e(number_format((float)($b['freight'] ?? 0),2,'.','')) ?>" data-legacy="<?= (int)($b['is_legacy'] ?? 0) ?>" title="تحديد البوليصة"></td>
              <td><?= dfmt($b['bill_date']) ?></td>
              <td><span class="pill pill-<?= e($b['bill_type']) ?>"><?= $typeLabel($b['bill_type']) ?></span></td>
              <td class="mv-ref"><?= e($b['bill_number'] ?: '—') ?><?php if ((int)($b['is_legacy'] ?? 0) === 1): ?> <span class="legacy-settle">قديم</span><?php endif; ?></td>
              <td><?= e($ref ?: '—') ?></td>
              <?php if ($showOwner): ?>
                <td class="txt clip-150">
                  <a class="lnk" href="<?= url('settlements') ?>?owner=<?= urlencode($own) ?>"><?= e($own !== '' ? $own : '(بدون صاحب بيان)') ?></a>
                </td>
              <?php endif; ?>
              <td class="txt clip-170"><?= e($b['company'] ?: '—') ?></td>
              <td><?= e($b['destination'] ?: '—') ?></td>
              <td class="txt clip-150"><?= e($cont ?: '—') ?></td>
              <td><?= e($b['car_no'] ?: '—') ?></td>
              <td><?= money($b['debt'], false) ?></td>
              <td class="pos"><?= money($b['paid'], false) ?></td>
              <td class="<?= $rem > 0.005 ? 'neg' : '' ?>"><?= money($rem, false) ?></td>
              <td><span class="pill st-<?= e($b['pay_status']) ?>"><?= e(Bill::STATUS_LABELS[$b['pay_status']]) ?></span></td>
              <td>
                <?php if ($due): ?>
                  <input data-money type="number" step="0.01" min="0" max="<?= e(number_format($rem, 2, '.', '')) ?>"
                         name="amount[<?= $b['id'] ?>]" value="<?= e($payOld['amount'][$b['id']] ?? '') ?>" class="pay-amt" placeholder="0" class="st-pay-in"<?= fieldErrorAttrs('amount.'.(int)$b['id'], $payState['errors']) ?>>
                  <?= fieldError('amount.'.(int)$b['id'], $payState['errors']) ?>
                <?php elseif ($b['pay_status'] === Bill::STATUS_PAID): ?>
                  <span class="st-ok"><?= icon('check', 14) ?> مكتمل</span>
                <?php elseif ($b['pay_status'] === Bill::STATUS_PENDING): ?>
                  <span class="dbt-none" title="لم يُدخَل النولون بعد — ليست ديناً"><?= icon('hourglass', 14) ?> بانتظار النولون</span>
                <?php else: ?>
                  <span class="dbt-none">—</span>
                <?php endif; ?>
              </td>
              <td><a class="btn ghost sm" href="<?= url('settlements/' . $b['id']) ?>" title="سجل الدفعات">↗</a></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- شريط الدفع الجماعي -->
    <div class="pay-bar">
      <div class="pay-bar-info">
        <span>المحدّد: <b id="selCount">0</b> بوليصة</span>
        <span>الإجمالي: <b id="selTotal" class="pos">0.00</b> ج.م</span>
      </div>
      <span class="ps-sp"></span>
      <button type="button" class="btn ghost sm" id="btnAllDue">تحديد كل غير المسدّد</button>
      <button type="button" class="btn ghost sm" id="btnFillRem">دفع كامل المتبقّي للمحدّد</button>
      <label class="st-lbl">تاريخ الدفع</label>
      <span class="field flush"><input type="date" name="pay_date" value="<?= e($payOld['pay_date'] ?? work_date()) ?>" class="st-date-in"<?= fieldErrorAttrs('pay_date', $payState['errors']) ?>><?= fieldError('pay_date', $payState['errors']) ?></span>
      <input type="text" name="note" value="<?= e($payOld['note'] ?? '') ?>" placeholder="ملاحظة (اختياري)" class="st-note-in">
      <button class="btn gold" type="submit" id="btnPay" data-primary-submit disabled><?= icon('download', 14) ?> دفع المحدّد</button>
    </div>
  </div>
</form>



<script>
(function(){
  var table = document.getElementById('billsTable');
  if (!table) return;
  var rows    = Array.prototype.slice.call(table.querySelectorAll('tr.bill-row'));
  var selAll  = document.getElementById('selAll');
  var btnPay  = document.getElementById('btnPay');
  var elCount = document.getElementById('selCount');
  var elTotal = document.getElementById('selTotal');

  function fmt(n){ return n.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }

  function refresh(){
    var count=0, total=0;
    rows.forEach(function(r){
      var chk = r.querySelector('.row-sel');
      var amt = r.querySelector('.pay-amt');
      var on  = chk && chk.checked;
      r.classList.toggle('on', !!on);
      if (on){ count++; if(amt) total += parseFloat(amt.value)||0; }
    });
    elCount.textContent = count;
    elTotal.textContent = fmt(total);
    btnPay.disabled = (count===0 || total<=0);
    if (selAll) selAll.checked = (rows.length>0 && count===rows.length);
  }

  rows.forEach(function(r){
    var chk = r.querySelector('.row-sel');
    var amt = r.querySelector('.pay-amt');
    var rem = parseFloat(r.getAttribute('data-rem'))||0;
    chk.addEventListener('change', function(){
      if (amt){
        if (chk.checked){ if (!(parseFloat(amt.value)>0)) amt.value = rem.toFixed(2); }
        else { amt.value = ''; }
      }
      refresh();
    });
    if(amt) amt.addEventListener('input', function(){ chk.checked = (parseFloat(amt.value)>0); refresh(); });
  });

  document.getElementById('btnAllDue').addEventListener('click', function(){
    rows.forEach(function(r){ var amt=r.querySelector('.pay-amt'); r.querySelector('.row-sel').checked=!!amt;
      if(amt) amt.value=(parseFloat(r.getAttribute('data-rem'))||0).toFixed(2); });
    refresh();
  });
  document.getElementById('btnFillRem').addEventListener('click', function(){
    rows.forEach(function(r){ var chk=r.querySelector('.row-sel');
      if (chk.checked) r.querySelector('.pay-amt').value=(parseFloat(r.getAttribute('data-rem'))||0).toFixed(2); });
    refresh();
  });

  document.addEventListener('billselectionchange', refresh);

  refresh();
})();
</script>
