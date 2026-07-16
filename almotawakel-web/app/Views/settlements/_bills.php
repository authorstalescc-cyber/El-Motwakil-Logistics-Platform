<?php
use App\Models\Bill;
/**
 * جدول بوالص قابل للتحديد + شريط دفع جماعي (مشترك بين نتائج البحث وصفحة صاحب البيان).
 * المتغيّرات المتوقّعة: $bills (array), $backQS (string), $showOwner (bool, افتراضي false)
 */
$showOwner = $showOwner ?? false;
$cols      = $showOwner ? 16 : 15;
$typeLabel = fn($t) => $t === 'warid' ? 'وارد' : 'صادر';
?>
<?php require __DIR__ . '/../bawalis/_bulk_actions.php'; ?>
<form method="post" action="<?= url('settlements/pay') ?>" id="payForm" data-confirm="تسجيل سداد للبوالص المحدّدة؟">
  <?= csrfField() ?>
  <input type="hidden" name="back" value="<?= e($backQS ?? '') ?>">
  <div class="card">
    <div class="table-wrap">
      <table class="data" id="billsTable">
        <thead><tr>
          <th class="g-result" style="width:38px"><input type="checkbox" id="selAll" class="bill-pick js-bill-pick-all" title="تحديد كل بوالص الجدول"></th>
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
          <th class="g-revenue" style="min-width:130px">دفعة الآن</th>
          <th class="g-identity">تفاصيل</th>
        </tr></thead>
        <tbody>
          <?php if (!$bills): ?>
            <tr><td colspan="<?= $cols ?>"><div class="empty"><div class="ic">🔎</div>لا بوالص مطابقة.</div></td></tr>
          <?php else: foreach ($bills as $b):
            $rem  = (float)$b['remaining'];
            $due  = $b['pay_status'] !== 'paid' && $rem > 0.005;
            $ref  = $b['bill_type'] === 'warid' ? $b['cert_number'] : $b['permit_no'];
            $cont = trim(($b['container_type'] ?? '') . ' ' . ($b['container_no'] ?? ''));
            $own  = trim((string)($b['declaration_owner'] ?? ''));
          ?>
            <tr class="bill-row<?= $due ? ' payable' : '' ?>" data-rem="<?= e(number_format($rem, 2, '.', '')) ?>">
              <td><input type="checkbox" class="row-sel bill-pick js-bill-pick" value="<?= (int)$b['id'] ?>"
                data-no="<?= e($b['bill_number'] ?: '#'.$b['id']) ?>" data-date="<?= e($b['bill_date'] ?? '') ?>"
                data-freight="<?= e(number_format((float)($b['freight'] ?? 0),2,'.','')) ?>" data-legacy="<?= (int)($b['is_legacy'] ?? 0) ?>" title="تحديد البوليصة"></td>
              <td><?= dfmt($b['bill_date']) ?></td>
              <td><span class="pill pill-<?= e($b['bill_type']) ?>"><?= $typeLabel($b['bill_type']) ?></span></td>
              <td style="font-weight:700"><?= e($b['bill_number'] ?: '—') ?><?php if ((int)($b['is_legacy'] ?? 0) === 1): ?> <span class="legacy-settle">قديم</span><?php endif; ?></td>
              <td><?= e($ref ?: '—') ?></td>
              <?php if ($showOwner): ?>
                <td style="text-align:right;max-width:150px;overflow:hidden;text-overflow:ellipsis">
                  <a class="lnk" href="<?= url('settlements') ?>?owner=<?= urlencode($own) ?>"><?= e($own !== '' ? $own : '(بدون صاحب بيان)') ?></a>
                </td>
              <?php endif; ?>
              <td style="text-align:right;max-width:170px;overflow:hidden;text-overflow:ellipsis"><?= e($b['company'] ?: '—') ?></td>
              <td><?= e($b['destination'] ?: '—') ?></td>
              <td style="text-align:right;max-width:150px;overflow:hidden;text-overflow:ellipsis"><?= e($cont ?: '—') ?></td>
              <td><?= e($b['car_no'] ?: '—') ?></td>
              <td><?= money($b['debt'], false) ?></td>
              <td class="pos"><?= money($b['paid'], false) ?></td>
              <td class="<?= $rem > 0.005 ? 'neg' : '' ?>"><?= money($rem, false) ?></td>
              <td><span class="pill st-<?= e($b['pay_status']) ?>"><?= e(Bill::STATUS_LABELS[$b['pay_status']]) ?></span></td>
              <td>
                <?php if ($due): ?>
                  <input type="number" step="0.01" min="0" max="<?= e(number_format($rem, 2, '.', '')) ?>"
                         name="amount[<?= $b['id'] ?>]" class="pay-amt" placeholder="0"
                         style="width:118px;border:1px solid var(--line2);border-radius:8px;padding:6px 8px;text-align:center">
                <?php elseif ($b['pay_status'] === Bill::STATUS_PAID): ?>
                  <span style="color:var(--green)">✓ مكتمل</span>
                <?php elseif ($b['pay_status'] === Bill::STATUS_PENDING): ?>
                  <span style="color:var(--grey)" title="لم يُدخَل النولون بعد — ليست ديناً">⏳ بانتظار النولون</span>
                <?php else: ?>
                  <span style="color:var(--grey)">—</span>
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
      <span style="flex:1"></span>
      <button type="button" class="btn ghost sm" id="btnAllDue">تحديد كل غير المسدّد</button>
      <button type="button" class="btn ghost sm" id="btnFillRem">دفع كامل المتبقّي للمحدّد</button>
      <label style="font-weight:700;color:var(--grey)">تاريخ الدفع</label>
      <input type="date" name="pay_date" value="<?= e(work_date()) ?>" style="border:1px solid var(--line2);border-radius:9px;padding:8px 10px">
      <input type="text" name="note" placeholder="ملاحظة (اختياري)" style="border:1px solid var(--line2);border-radius:9px;padding:8px 10px;width:150px">
      <button class="btn gold" type="submit" id="btnPay" disabled>💾 دفع المحدّد</button>
    </div>
  </div>
</form>

<style>
table.data tr.bill-row.on{background:var(--gold-light)!important}
table.data tr.bill-row.on td{border-color:#e6d9a8}
.legacy-settle{display:inline-block;background:#7a5f0e;color:#fff;border-radius:999px;padding:1px 7px;font-size:.65rem;font-weight:800}
.pay-bar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:14px 18px;border-top:2px solid var(--line2);background:var(--zebra);position:sticky;bottom:0;z-index:5}
.pay-bar-info{display:flex;gap:18px;font-weight:700;color:var(--navy)}
.pay-bar-info b{font-size:1.05rem}
.pay-bar .btn[disabled]{opacity:.5;cursor:not-allowed}
</style>

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
