<?php
/** شريط إجراءات موحّد لأي جدول بوالص يحمل checkboxes بالفئة js-bill-pick. */
$bulkBack = ltrim((string)($_SERVER['REQUEST_URI'] ?? 'bawalis'), '/');
$ownerOptions = $ownerOptions ?? [];
$bulkAllowFreight = $bulkAllowFreight ?? true;
$bulkAllowOwner   = $bulkAllowOwner ?? true;
?>
<div class="bill-bulk-bar" id="billBulkBar">
  <span>المحدّد: <b id="bulkSelectedCount">0</b> بوليصة</span>
  <?php if ($bulkAllowFreight): ?><button type="button" class="btn gold sm" id="openFreightBulk" disabled><?= icon('plus', 14) ?> إضافة نولون</button><?php endif; ?>
  <?php if ($bulkAllowOwner): ?><button type="button" class="btn ghost sm" id="openOwnerBulk" disabled><?= icon('user', 14) ?> تغيير صاحب البيان</button><?php endif; ?>
  <?php /* ⚠️ كشف الحساب **لصاحب بيان واحد** — الزرار بيتفعّل بس لو كل المحدّد
           لنفس الاسم، وبيقول ليه لو لأ. `OwnerStatement::form` بتسحب بوالص
           الاسم بنفسها، فاللي بنبعته هو الاسم مش قايمة البوالص. */ ?>
  <a class="btn ghost sm is-off" id="openStatementBulk" href="#" aria-disabled="true"><?= icon('receipt', 14) ?> إصدار كشف حساب</a>
  <button type="button" class="btn ghost sm" id="clearBillBulk" disabled>إلغاء التحديد</button>
</div>

<?php if ($bulkAllowFreight): ?>
<div class="bulk-modal" id="freightBulkModal" hidden>
  <div class="bulk-modal-card" role="dialog" aria-modal="true" aria-labelledby="freightBulkTitle">
    <div class="bulk-modal-head"><b id="freightBulkTitle">إضافة نولون للبوالص المحدّدة</b><button type="button" class="bulk-close" aria-label="إغلاق">×</button></div>
    <form method="post" action="<?= url('bawalis/bulk-freight') ?>" id="freightBulkForm">
      <?= csrfField() ?><input type="hidden" name="back" value="<?= e($bulkBack) ?>">
      <div class="bulk-unified">
        <label>قيمة موحّدة (اختياري)</label>
        <input type="number" min="0" step="0.01" id="bulkUnifiedFreight" placeholder="قيمة">
        <button type="button" class="btn ghost sm" id="applyUnifiedFreight">تطبيق على الكل</button>
      </div>
      <p class="bulk-hint">يمكنك تعديل نولون كل بوليصة منفردًا. القيمة تستبدل النولون الحالي وتعيد حساب المديونية؛ البوالص القديمة تظل معزولة عن العهدة والتقفيل.</p>
      <div class="bulk-lines" id="freightBulkLines"></div>
      <div class="bulk-modal-actions"><button class="btn gold" type="submit"><?= icon('download', 14) ?> حفظ النولون للمحدّد</button><button class="btn ghost bulk-close" type="button">إلغاء</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($bulkAllowOwner): ?>
<div class="bulk-modal" id="ownerBulkModal" hidden>
  <div class="bulk-modal-card small" role="dialog" aria-modal="true" aria-labelledby="ownerBulkTitle">
    <div class="bulk-modal-head"><b id="ownerBulkTitle">تغيير صاحب البيان للمحدّد</b><button type="button" class="bulk-close" aria-label="إغلاق">×</button></div>
    <form method="post" action="<?= url('bawalis/bulk-owner') ?>" id="ownerBulkForm">
      <?= csrfField() ?><input type="hidden" name="back" value="<?= e($bulkBack) ?>">
      <div id="ownerBulkIds"></div>
      <label class="bulk-owner-label">صاحب البيان الجديد</label>
      <input type="text" name="owner" list="bulkOwnerOptions" maxlength="150" required autocomplete="off" data-smart-name="owner" placeholder="الاسم">
      <datalist id="bulkOwnerOptions"><?php foreach ($ownerOptions as $name): ?><option value="<?= e($name) ?>"><?php endforeach; ?></datalist>
      <p class="bulk-hint">سيُطبّق الاسم على كل البوالص المحدّدة دون تغيير أي مبلغ أو إذن أو حركة.</p>
      <div class="bulk-modal-actions"><button class="btn gold" type="submit"><?= icon('download', 14) ?> تغيير صاحب البيان</button><button class="btn ghost bulk-close" type="button">إلغاء</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<style>
.bill-bulk-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 12px;padding:10px 14px;border:1px solid var(--gold-line);border-radius:var(--radius-2);background:var(--gold-light);position:sticky;top:8px;z-index:12;box-shadow:0 4px 14px rgba(21,53,91,.07)}
.bill-bulk-bar span{font-weight:600;color:var(--accent)}.bill-bulk-bar button[disabled]{opacity:.45;cursor:not-allowed}
.bulk-modal[hidden]{display:none}.bulk-modal{position:fixed;inset:0;z-index:1000;background:rgba(13,29,49,.58);display:flex;align-items:center;justify-content:center;padding:20px}
.bulk-modal-card{width:min(820px,96vw);max-height:88vh;overflow:auto;background:var(--card);border-radius:var(--radius-3);box-shadow:0 24px 70px rgba(0,0,0,.28)}.bulk-modal-card.small{width:min(560px,96vw)}
.bulk-modal-head{position:sticky;top:0;z-index:2;display:flex;justify-content:space-between;align-items:center;padding:15px 18px;background:var(--navy);color:#fff}.bulk-modal-head b{font-size:var(--fs-4)}.bulk-close{border:0;background:transparent;color:inherit;font-size:var(--fs-7);cursor:pointer;line-height:1}
.bulk-modal form{padding:18px}.bulk-unified{display:flex;align-items:end;gap:10px;flex-wrap:wrap}.bulk-unified label,.bulk-owner-label{display:block;font-weight:600;color:var(--accent);font-size:var(--fs-2)}.bulk-unified input,.bulk-modal input[type=text],.bulk-modal input[type=number]{border:1px solid var(--line2);border-radius:var(--radius-1);padding:9px 11px;font-family:inherit}.bulk-modal input[type=text]{display:block;width:100%;margin-top:6px}
.bulk-hint{font-size:var(--fs-1);color:var(--grey);line-height:1.7;margin:11px 0}.bulk-lines{border:1px solid var(--line);border-radius:var(--radius-2);overflow:hidden}.bulk-line{display:grid;grid-template-columns:1.4fr .8fr .9fr;gap:10px;align-items:center;padding:9px 12px;border-bottom:1px solid var(--line);font-size:var(--fs-2)}.bulk-line:last-child{border-bottom:0}.bulk-line:nth-child(even){background:var(--zebra)}.bulk-line .ref{font-weight:600;color:var(--accent)}.bulk-line small{color:var(--grey)}.bulk-line input{width:100%;text-align:center}
.bulk-modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:16px}
.bill-pick{width:18px;height:18px;accent-color:var(--gold,var(--gold));cursor:pointer}
@media(max-width:700px){.bulk-line{grid-template-columns:1fr}.paid-summary-pick{grid-template-columns:auto 1fr}.bill-bulk-bar{position:static}}
</style>

<script>
(function(){
 function init(){
  var picks = function(){ return Array.prototype.slice.call(document.querySelectorAll('.js-bill-pick')); };
  var selected = function(){ return picks().filter(function(c){ return c.checked; }); };
  var count = document.getElementById('bulkSelectedCount');
  var buttons = ['openFreightBulk','openOwnerBulk','clearBillBulk'].map(function(id){ return document.getElementById(id); });
  var master = document.querySelector('.js-bill-pick-all');

  var stBtn = document.getElementById('openStatementBulk');
  var stBase = <?= js_json(url('statements/form')) ?>;

  /* ⚠️ كشف الحساب لصاحب بيان **واحد** — `OwnerStatement::form` بتاخد `?owner=`
     وبتسحب بوالصه بنفسها. فلو المحدّد لأكتر من اسم الزرار بيتقفل ويقول السبب،
     ولو الاسم فاضي كمان (بوليصة بلا صاحب بيان مالهاش كشف). */
  function refreshStatement(n){
    if(!stBtn) return;
    var names = {}, blank = 0;
    selected().forEach(function(c){
      var o = (c.getAttribute('data-owner')||'').trim();
      if(o==='') { blank++; } else { names[o] = 1; }
    });
    var keys = Object.keys(names), ok = n>0 && keys.length===1 && blank===0;
    stBtn.classList.toggle('is-off', !ok);
    stBtn.setAttribute('aria-disabled', ok ? 'false' : 'true');
    if(ok){
      stBtn.href = stBase + '?owner=' + encodeURIComponent(keys[0]);
      stBtn.title = 'كشف حساب: ' + keys[0];
    } else {
      stBtn.removeAttribute('href');
      stBtn.title = n===0 ? 'علّم بوالص الأول'
        : (blank ? 'فيه بوالص بلا صاحب بيان' : 'المحدّد لأكتر من صاحب بيان — اختار اسم واحد');
    }
  }

  function refresh(){
    var n=selected().length; count.textContent=n; buttons.forEach(function(b){ if(b)b.disabled=!n; });
    if(master){ master.checked=picks().length>0&&n===picks().length; master.indeterminate=n>0&&n<picks().length; }
    refreshStatement(n);
    document.dispatchEvent(new CustomEvent('billselectionchange'));
  }
  picks().forEach(function(c){ c.addEventListener('change',refresh); });
  if(master) master.addEventListener('change',function(){ picks().forEach(function(c){c.checked=master.checked;});refresh(); });
  document.getElementById('clearBillBulk').addEventListener('click',function(){picks().forEach(function(c){c.checked=false;});refresh();});

  function openModal(id){ var m=document.getElementById(id); m.hidden=false; document.body.style.overflow='hidden'; }
  function closeModal(m){ m.hidden=true; document.body.style.overflow=''; }
  document.querySelectorAll('.bulk-modal').forEach(function(m){
    m.querySelectorAll('.bulk-close').forEach(function(b){b.addEventListener('click',function(){closeModal(m);});});
    m.addEventListener('click',function(e){if(e.target===m)closeModal(m);});
  });
  document.addEventListener('keydown',function(e){if(e.key==='Escape')document.querySelectorAll('.bulk-modal:not([hidden])').forEach(closeModal);});

  var openFreight=document.getElementById('openFreightBulk');
  if(openFreight) openFreight.addEventListener('click',function(){
    var box=document.getElementById('freightBulkLines'); box.innerHTML='';
    selected().forEach(function(c){
      var line=document.createElement('div'); line.className='bulk-line';
      var ref=document.createElement('div'); ref.className='ref'; ref.textContent='بوليصة '+(c.dataset.no||('#'+c.value));
      var meta=document.createElement('small'); meta.textContent=(c.dataset.date||'')+(c.dataset.legacy==='1'?' · قديمة':'');
      var input=document.createElement('input'); input.type='number'; input.min='0'; input.step='0.01'; input.name='freight['+c.value+']'; input.value=c.dataset.freight||'0'; input.required=true;
      line.appendChild(ref); line.appendChild(meta); line.appendChild(input); box.appendChild(line);
    });
    openModal('freightBulkModal');
  });
  var applyFreight=document.getElementById('applyUnifiedFreight');
  if(applyFreight) applyFreight.addEventListener('click',function(){var v=document.getElementById('bulkUnifiedFreight').value;if(v==='')return;document.querySelectorAll('#freightBulkLines input').forEach(function(i){i.value=v;});});

  var openOwner=document.getElementById('openOwnerBulk');
  if(openOwner) openOwner.addEventListener('click',function(){
    var box=document.getElementById('ownerBulkIds'); box.innerHTML='';
    selected().forEach(function(c){var i=document.createElement('input');i.type='hidden';i.name='ids[]';i.value=c.value;box.appendChild(i);});
    openModal('ownerBulkModal'); setTimeout(function(){document.querySelector('#ownerBulkForm input[name=owner]').focus();},20);
  });
  document.addEventListener('billbulkrefresh',refresh);
  refresh();
 }
 if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
})();
</script>
