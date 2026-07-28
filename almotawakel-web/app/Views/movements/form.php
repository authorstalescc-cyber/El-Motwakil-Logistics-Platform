<?php
use App\Models\Movement;
// دمج كل فئات البنود في قائمة واحدة للاقتراح
$allCats = array_merge($lookups['mv_expense']??[], $lookups['mv_receipt']??[], $lookups['mv_discount']??[]);
$allCats = array_values(array_unique($allCats));
$visaCards = \App\Models\VisaCard::all(true);
?>
<div class="page-actions">
  <a class="btn ghost" href="<?= url('movements') ?>">‹ رجوع للحركات</a>
</div>

<form class="form-card" method="post" action="<?= e($action) ?>">
  <?= csrfField() ?>

  <div class="form-group-title gt-identity">بيانات الحركة</div>
  <div class="form-grid">
    <div class="field"><label>التاريخ</label><input type="date" name="movement_date" value="<?= e($mv['movement_date']) ?>"></div>
    <div class="field"><label>رقم البوليصة (اختياري)</label><input list="dl_bills" name="bill_number" value="<?= e($billNumber) ?>" placeholder="اربط ببوليصة" autocomplete="off"></div>
    <div class="field"><label>السائق</label><input list="dl_driver" name="driver" value="<?= e($mv['driver']) ?>" autocomplete="off"></div>
    <div class="field"><label>مالك السيارة</label><input list="dl_owner" name="car_owner" value="<?= e($mv['car_owner']) ?>" autocomplete="off"></div>
    <div class="field"><label>شركة الشحن</label><?php partial('lookup_select', ['name'=>'shipping_co','type'=>'shipping_co','value'=>$mv['shipping_co']]); ?></div>
    <div class="field"><label>توكيل</label><?php partial('lookup_select', ['name'=>'agency','type'=>'agency','value'=>$mv['agency']]); ?></div>
    <div class="field"><label>الميناء</label><?php partial('lookup_select', ['name'=>'port','type'=>'port','value'=>$mv['port']]); ?></div>
    <div class="field"><label>ملاحظة</label><input name="note" value="<?= e($mv['note']) ?>"></div>
  </div>

  <div class="form-group-title gt-expense">البنود (مصروفات / إيصالات / خصومات)</div>
  <div style="padding:16px 20px">
    <table class="data" id="itemsTable" style="white-space:normal">
      <thead><tr>
        <th class="g-identity" style="width:130px">النوع</th>
        <th class="g-identity">الفئة / البيان</th>
        <th class="g-result" style="width:130px">المبلغ</th>
        <th class="g-identity" style="width:110px">الدفع</th>
        <th class="g-identity" style="width:150px">الكارت (فيزا)</th>
        <th class="g-identity">ملاحظة</th>
        <th class="g-identity" style="width:60px">حذف</th>
      </tr></thead>
      <tbody id="itemsBody">
        <?php
        $render = function($type='expense',$cat='',$amt='',$note='',$visa=0,$card=0) use ($visaCards) {
          ob_start(); ?>
          <tr>
            <td>
              <select name="it_type[]" style="width:100%;border:1px solid var(--line2);border-radius:var(--radius-1);padding:7px">
                <?php foreach (Movement::ITEM_TYPES as $k=>$lbl): ?>
                  <option value="<?= $k ?>" <?= $type===$k?'selected':'' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input list="dl_mvcat" name="it_category[]" value="<?= e($cat) ?>" style="width:100%;border:1px solid var(--line2);border-radius:var(--radius-1);padding:7px" autocomplete="off"></td>
            <td><input type="number" step="0.01" name="it_amount[]" value="<?= e($amt) ?>" placeholder="0" style="width:100%;border:1px solid var(--line2);border-radius:var(--radius-1);padding:7px;text-align:center"></td>
            <td>
              <select name="it_visa[]" title="طريقة الدفع" class="js-pay-sel" style="width:100%;border:1px solid var(--line2);border-radius:var(--radius-1);padding:7px">
                <option value="0" <?= (int)$visa===0?'selected':'' ?>>نقدي</option>
                <option value="1" <?= (int)$visa===1?'selected':'' ?>><?= icon('credit-card', 14) ?> فيزا</option>
              </select>
            </td>
            <td>
              <select name="it_card[]" title="كارت الفيزا" class="js-card-sel" style="width:100%;border:1px solid var(--line2);border-radius:var(--radius-1);padding:7px<?= (int)$visa===1?'':';display:none' ?>">
                <option value="">— الكارت —</option>
                <?php foreach ($visaCards as $vc): ?>
                  <option value="<?= (int)$vc['id'] ?>" <?= (int)$card===(int)$vc['id']?'selected':'' ?>><?= e($vc['name']) ?><?= $vc['owner']?' — '.e($vc['owner']):'' ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input name="it_note[]" value="<?= e($note) ?>" style="width:100%;border:1px solid var(--line2);border-radius:var(--radius-1);padding:7px"></td>
            <td style="text-align:center"><button type="button" class="del rm-row" style="width:30px;height:30px;border-radius:var(--radius-1);border:1px solid var(--line2);background:#fff;cursor:pointer"><?= icon('trash', 14) ?></button></td>
          </tr>
          <?php return ob_get_clean();
        };
        if ($items) { foreach ($items as $it) echo $render($it['item_type'],$it['category'],$it['amount'],$it['note'],$it['is_visa'] ?? 0,$it['visa_card_id'] ?? 0); }
        else { echo $render(); }
        ?>
      </tbody>
    </table>
    <button type="button" class="btn ghost sm" id="addRow" style="margin-top:12px"><?= icon('plus', 14) ?> إضافة بند</button>
    <div style="margin-top:14px;display:flex;gap:22px;flex-wrap:wrap;font-weight:500">
      <span>مصروفات: <span id="sumExpense" style="color:var(--red)">0</span></span>
      <span>إيصالات: <span id="sumReceipt" style="color:var(--green)">0</span></span>
      <span>خصومات: <span id="sumDiscount" style="color:#8a6d10">0</span></span>
      <span>الإجمالي المنصرف: <span id="sumAll" style="color:var(--navy)">0</span></span>
    </div>
  </div>

  <div class="form-actions">
    <button class="btn gold" type="submit"><?= icon('download', 14) ?> <?= $isEdit?'حفظ التعديلات':'حفظ الحركة' ?></button>
    <a class="btn ghost" href="<?= url('movements') ?>">إلغاء</a>
  </div>
</form>

<datalist id="dl_mvcat"><?php foreach ($allCats as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
<datalist id="dl_driver"><?php foreach (($lookups['driver']??[]) as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist>
<datalist id="dl_owner"><?php foreach (($lookups['car_owner']??[]) as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist>
<datalist id="dl_bills"></datalist>

<template id="rowTpl"><?= str_replace(["\n","  "],'', $render()) ?></template>

<script>
(function(){
  const body = document.getElementById('itemsBody');
  const tpl = document.getElementById('rowTpl').innerHTML;
  document.getElementById('addRow').addEventListener('click', function(){
    const tmp = document.createElement('tbody'); tmp.innerHTML = tpl;
    body.appendChild(tmp.firstElementChild); recalc();
  });
  body.addEventListener('click', function(e){
    if (e.target.closest('.rm-row')) {
      if (body.children.length > 1) e.target.closest('tr').remove();
      else e.target.closest('tr').querySelectorAll('input').forEach(i=>i.value='');
      recalc();
    }
  });
  body.addEventListener('input', recalc);
  body.addEventListener('change', function(e){
    if (e.target.classList.contains('js-pay-sel')) {
      const card = e.target.closest('tr').querySelector('.js-card-sel');
      if (card){ card.style.display = e.target.value==='1' ? '' : 'none'; if(e.target.value!=='1') card.value=''; }
    }
  });
  function recalc(){
    let ex=0,re=0,di=0;
    body.querySelectorAll('tr').forEach(function(tr){
      const t = tr.querySelector('[name="it_type[]"]').value;
      const a = parseFloat(tr.querySelector('[name="it_amount[]"]').value)||0;
      if (t==='expense') ex+=a; else if (t==='receipt') re+=a; else di+=a;
    });
    const f=(n)=>n.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    sumExpense.textContent=f(ex); sumReceipt.textContent=f(re); sumDiscount.textContent=f(di); sumAll.textContent=f(ex+re+di);
  }
  recalc();
})();
</script>
