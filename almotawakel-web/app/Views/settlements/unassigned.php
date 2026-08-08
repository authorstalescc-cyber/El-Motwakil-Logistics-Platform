<?php
use App\Core\FormState;
/**
 * البوالص بلا صاحب بيان — تعيين سريع (فردي) أو جماعي (عدّة بوالص لصاحب بيان واحد).
 * @var array $bills         البوالص التي declaration_owner فيها فارغ
 * @var array $tot           إجماليات (n / debt / remaining)
 * @var array $ownerOptions  أسماء أصحاب بيان مقترحة (توحيد التهجئة)
 */
$typeLabel = fn($t) => $t === 'warid' ? 'وارد' : 'صادر';
?>
<?php partial('owner_tabs', ['active' => 'unassigned']); ?>
<div class="page-actions">
  <a class="btn ghost" href="<?= url('settlements') ?>">‹ رجوع لأصحاب البيان</a>
  <span class="ps-sp"></span>
  <span class="pill bs-pill st-unpaid">
    <?= intf($tot['n']) ?> بوليصة بلا صاحب بيان
  </span>
</div>

<div class="set-hero warn">
  <div class="set-hero-av"><?= icon('alert', 14) ?></div>
  <div class="st-grow">
    <h2>بوالص بلا صاحب بيان</h2>
  </div>
  <div class="set-hero-rem">
    <span>إجمالي المتبقّي لهذه البوالص</span>
    <b class="<?= (float)$tot['remaining']>0.005?'neg':'pos' ?>"><?= money($tot['remaining']) ?></b>
  </div>
</div>

<?php if (!$bills): ?>
  <div class="card"><div class="empty empty-mv-in"><div class="ic"><?= icon('check', 14) ?></div>كل البوالص لها صاحب بيان.</div></div>
<?php else: ?>

<?php $bulkAllowOwner=false; require __DIR__ . '/../bawalis/_bulk_actions.php'; ?>

<form method="post" action="<?= url('settlements/assign-owner') ?>" id="assignForm" data-confirm="حفظ تعيين صاحب البيان للبوالص؟">
  <?= csrfField() ?>
  <?= formStateFields(FormState::token(), 'settlements?unassigned=1') ?>

  <!-- شريط التعيين الجماعي -->
  <div class="bulk-bar">
    <div class="bulk-info">المحدّد: <b id="selCount">0</b> بوليصة</div>
    <span class="ps-sp"></span>
    <button type="button" class="btn ghost sm" id="btnSelAll">تحديد الكل</button>
    <button type="button" class="btn ghost sm" id="btnSelNone">إلغاء التحديد</button>
    <input list="dl_owner" id="bulkOwner" placeholder="اسم صاحب البيان للمحدّد" autocomplete="off" class="un-search">
    <button type="button" class="btn green sm" id="btnApplyBulk">تطبيق على المحدّد ↓</button>
  </div>

  <div class="card">
    <div class="table-wrap">
      <table class="data" id="unTable">
        <thead><tr>
          <th class="col-pick" data-no-sort><input type="checkbox" id="chkAll" class="bill-pick js-bill-pick-all" title="تحديد الكل"></th>
          <th class="g-identity">التاريخ</th>
          <th class="g-identity">النوع</th>
          <th class="g-identity">رقم البوليصة</th>
          <th class="g-identity">إذن/شهادة</th>
          <th class="g-identity">الشركة / العميل</th>
          <th class="g-identity">الجهة</th>
          <th class="g-identity">الحاوية</th>
          <th class="g-identity">السيارة</th>
          <th class="g-result">الدين</th>
          <th class="g-expense">المتبقّي</th>
          <th class="g-revenue col-owner">صاحب البيان</th>
        </tr></thead>
        <tbody>
          <?php foreach ($bills as $b):
            $ref  = $b['bill_type'] === 'warid' ? $b['cert_number'] : $b['permit_no'];
            $cont = trim(($b['container_type'] ?? '') . ' ' . ($b['container_no'] ?? ''));
          ?>
            <tr class="un-row">
              <td><input type="checkbox" class="row-sel bill-pick js-bill-pick" value="<?= (int)$b['id'] ?>"
                data-no="<?= e($b['bill_number'] ?: '#'.$b['id']) ?>" data-date="<?= e($b['bill_date'] ?? '') ?>"
                data-freight="<?= e(number_format((float)($b['freight'] ?? 0),2,'.','')) ?>" data-legacy="<?= (int)($b['is_legacy'] ?? 0) ?>" title="تحديد"></td>
              <td><?= dfmt($b['bill_date']) ?></td>
              <td><span class="pill pill-<?= e($b['bill_type']) ?>"><?= $typeLabel($b['bill_type']) ?></span></td>
              <td class="mv-ref"><?= e($b['bill_number'] ?: '—') ?></td>
              <td><?= e($ref ?: '—') ?></td>
              <td class="txt clip-170"><?= e($b['company'] ?: '—') ?></td>
              <td><?= e($b['destination'] ?: '—') ?></td>
              <td class="txt clip-150"><?= e($cont ?: '—') ?></td>
              <td><?= e($b['car_no'] ?: '—') ?></td>
              <td><?= money($b['debt'], false) ?></td>
              <td class="<?= (float)$b['remaining'] > 0.005 ? 'neg' : '' ?>"><?= money($b['remaining'], false) ?></td>
              <td>
                <input list="dl_owner" name="owner[<?= $b['id'] ?>]" class="owner-inp"
                       value="<?= e(($assignState['old']['owner'][$b['id']] ?? '')) ?>"
                       placeholder="اكتب اسم صاحب البيان" autocomplete="off" class="un-owner-in">
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="pay-bar">
      <div class="pay-bar-info">
        <span>بوالص سيُحفظ لها صاحب بيان: <b id="fillCount" class="pos">0</b></span>
      </div>
      <span class="ps-sp"></span>
      <button class="btn gold" type="submit" id="btnSave" disabled><?= icon('download', 14) ?> حفظ التعيين</button>
    </div>
  </div>
</form>

<datalist id="dl_owner"><?php foreach ($ownerOptions as $o): ?><option value="<?= e($o) ?>"><?php endforeach; ?></datalist>

<?php if (count($bills) >= 1000): ?>
  <div class="dbt-cut">عُرض أول 1000 بوليصة — احفظ هذه الدفعة ليظهر الباقي.</div>
<?php endif; ?>

<?php endif; ?>



<script>
(function(){
  var table = document.getElementById('unTable');
  if (!table) return;
  var rows     = Array.prototype.slice.call(table.querySelectorAll('tr.un-row'));
  var chkAll   = document.getElementById('chkAll');
  var selCount = document.getElementById('selCount');
  var fillCount= document.getElementById('fillCount');
  var btnSave  = document.getElementById('btnSave');

  function refresh(){
    var sel=0, filled=0;
    rows.forEach(function(r){
      var chk = r.querySelector('.row-sel');
      var inp = r.querySelector('.owner-inp');
      var on  = chk.checked;
      var has = inp.value.trim() !== '';
      r.classList.toggle('sel', on);
      r.classList.toggle('filled', has);
      if (on) sel++;
      if (has) filled++;
    });
    selCount.textContent  = sel;
    fillCount.textContent = filled;
    btnSave.disabled = (filled === 0);
    chkAll.checked = (rows.length>0 && sel===rows.length);
    document.dispatchEvent(new CustomEvent('billbulkrefresh'));
  }

  rows.forEach(function(r){
    r.querySelector('.row-sel').addEventListener('change', refresh);
    r.querySelector('.owner-inp').addEventListener('input', refresh);
  });

  chkAll.addEventListener('change', function(){
    rows.forEach(function(r){ r.querySelector('.row-sel').checked = chkAll.checked; });
    refresh();
  });
  document.getElementById('btnSelAll').addEventListener('click', function(){
    rows.forEach(function(r){ r.querySelector('.row-sel').checked = true; }); refresh();
  });
  document.getElementById('btnSelNone').addEventListener('click', function(){
    rows.forEach(function(r){ r.querySelector('.row-sel').checked = false; }); refresh();
  });

  // تطبيق اسم صاحب بيان واحد على كل البوالص المحدّدة
  document.getElementById('btnApplyBulk').addEventListener('click', function(){
    var name = document.getElementById('bulkOwner').value.trim();
    if (name === ''){ window.AppUI.notice('اكتب اسم صاحب البيان أولاً.','بيانات ناقصة'); return; }
    var n = 0;
    rows.forEach(function(r){
      if (r.querySelector('.row-sel').checked){ r.querySelector('.owner-inp').value = name; n++; }
    });
    if (n === 0){ window.AppUI.notice('حدّد بوالص أولاً بوضع علامة أمامها.','بيانات ناقصة'); return; }
    refresh();
  });

  refresh();
})();
</script>
