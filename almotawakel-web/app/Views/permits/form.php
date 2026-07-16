<?php use App\Models\Permit; ?>
<div class="page-actions">
  <a class="btn ghost" href="<?= url('permits') ?>">‹ رجوع للإصدارات</a>
</div>

<form class="form-card" method="post" action="<?= e($action) ?>">
  <?= csrfField() ?>
  <?php if($isEdit): ?><input type="hidden" name="lock_version" value="<?= (int)($p['lock_version'] ?? 1) ?>"><?php endif; ?>

  <div class="form-group-title gt-identity">نوع الإصدار</div>
  <div style="padding:16px 20px;display:flex;gap:14px;flex-wrap:wrap">
    <?php foreach (Permit::TYPES as $k=>$t): ?>
      <label class="type-choice">
        <input type="radio" name="permit_type" value="<?= $k ?>" <?= ($p['permit_type']??'sader')===$k?'checked':'' ?>>
        <span><?= e($t['label']) ?></span>
      </label>
    <?php endforeach; ?>
  </div>

  <div class="form-group-title gt-identity">بيانات الإذن / الشهادة</div>
  <div class="form-grid">
    <div class="field"><label>رقم الإذن / الشهادة</label><input name="permit_no" value="<?= e($p['permit_no']) ?>" autocomplete="off"></div>
    <div class="field"><label>التاريخ</label><input type="date" name="permit_date" value="<?= e($p['permit_date']) ?>"></div>
    <div class="field"><label>الوكيل (العميل)</label><input list="dl_client" data-smart-name="client" id="permit_client" name="client" value="<?= e($p['client']) ?>" autocomplete="off"></div>
    <div class="field"><label>لصالح شركة</label><input list="dl_company" data-smart-name="company" id="permit_company" name="company" value="<?= e($p['company']) ?>" autocomplete="off"></div>
    <div class="field"><label>الجهة</label><input list="dl_dest" data-smart-name="destination" name="destination" value="<?= e($p['destination']) ?>" autocomplete="off"></div>
    <div class="field"><label>🚢 شركة الشحن</label><input list="dl_shipping" name="shipping_co" value="<?= e($p['shipping_co'] ?? '') ?>" autocomplete="off"></div>
    <div class="field"><label>🧾 التوكيل</label><input list="dl_agency" name="agency" value="<?= e($p['agency'] ?? '') ?>" autocomplete="off"></div>
    <div class="field"><label>⚓ الميناء</label><input list="dl_port" name="port" value="<?= e($p['port'] ?? '') ?>" autocomplete="off"></div>
    <div class="field"><label>عدد الحاويات</label><input type="number" min="0" name="containers" value="<?= e($p['containers'] ?? 0) ?>" placeholder="0"></div>
    <div class="field"><label>تصنيف الحاويات</label><input list="dl_ctype" name="container_type" value="<?= e($p['container_type'] ?? '') ?>" placeholder="مثال: 40 قدم / 20 قدم" autocomplete="off"></div>
    <div class="field"><label>سعر الكشف لكل حاوية <span style="color:var(--grey);font-weight:600">(لهذا الإذن)</span></label><input type="number" step="0.01" min="0" name="inspection_rate" value="<?= e($p['inspection_rate'] ?? '') ?>" placeholder="يُدخَل يدوياً"></div>
    <div class="field"><label>سعر الضمان لكل حاوية <span style="color:var(--grey);font-weight:600">(لهذا الإذن)</span></label><input type="number" step="0.01" min="0" name="guarantee_rate" value="<?= e($p['guarantee_rate'] ?? '') ?>" placeholder="يُدخَل يدوياً"></div>
    <div class="field"><label>ملاحظة</label><input name="note" value="<?= e($p['note']) ?>"></div>
  </div>

  <div class="form-actions">
    <button class="btn gold" type="submit">💾 <?= $isEdit?'حفظ التعديلات':'إنشاء الإصدار وفتح التايم لاين' ?></button>
    <a class="btn ghost" href="<?= url('permits') ?>">إلغاء</a>
  </div>
</form>

<datalist id="dl_company"><?php foreach (($lookups['company']??[]) as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
<datalist id="dl_client"><?php foreach (($lookups['client']??[]) as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
<datalist id="dl_dest"><?php foreach (($lookups['destination']??[]) as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
<datalist id="dl_shipping"><?php foreach (($lookups['shipping_co']??[]) as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
<datalist id="dl_agency"><?php foreach (($lookups['agency']??[]) as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
<datalist id="dl_port"><?php foreach (($lookups['port']??[]) as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
<datalist id="dl_ctype"><?php foreach (($lookups['container_type']??[]) as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>

<script>
(function(){
  var MAP = <?= json_encode($nameMap ?? ['agentCompanies'=>[],'companyAgents'=>[]], JSON_UNESCAPED_UNICODE) ?>;
  var ALL_COMPANIES = <?= json_encode(array_values($lookups['company'] ?? []), JSON_UNESCAPED_UNICODE) ?>;
  var clientEl  = document.getElementById('permit_client');
  var companyEl = document.getElementById('permit_company');
  var companyDl = document.getElementById('dl_company');
  function fillCompanyOptions(list){
    if(!companyDl) return; companyDl.innerHTML='';
    (list||[]).forEach(function(n){ var o=document.createElement('option'); o.value=n; companyDl.appendChild(o); });
  }
  if (clientEl && companyEl){
    clientEl.addEventListener('change', function(){
      var comps = MAP.agentCompanies[clientEl.value.trim()];
      if (comps && comps.length){ fillCompanyOptions(comps);
        if (comps.length===1 && companyEl.value.trim()==='') companyEl.value=comps[0];
      } else { fillCompanyOptions(ALL_COMPANIES); }
    });
    companyEl.addEventListener('change', function(){
      var ags = MAP.companyAgents[companyEl.value.trim()];
      if (ags && ags.length===1){ clientEl.value=ags[0]; }
    });
    if (clientEl.value.trim()){ var pc=MAP.agentCompanies[clientEl.value.trim()]; if(pc&&pc.length) fillCompanyOptions(pc); }
  }
})();
</script>
