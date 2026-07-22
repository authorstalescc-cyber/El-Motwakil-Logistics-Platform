<?php
/**
 * وجهات الخريطة (v34) — الإحداثيات المرجعية اللي بتتحسب منها المسافة والوصول المتوقع.
 * ⚠️ وصفية بحتة: ما تمسّش أي حساب ولا بوليصة. تعديلها بيغيّر التقديرات بس.
 */
$e = $edit ?: null;
?>
<link rel="stylesheet" href="<?= url('assets/vendor/leaflet/leaflet.css') ?>">

<div class="pg-head">
  <div>
    <h2 class="pg-title"><?= icon('anchor', 22) ?> وجهات الخريطة</h2>
    <p class="pg-sub">مكان كل وجهة على الخريطة — منها بنحسب السائق بيبعد قد إيه ومتوقع يوصل إمتى.</p>
  </div>
  <div class="pg-actions">
    <a class="btn ghost" href="<?= url('tracking') ?>">← رجوع للتتبع</a>
  </div>
</div>

<div class="pl-cols">
  <div class="card" style="padding:14px">
    <b style="display:block;margin-bottom:10px;color:var(--navy)">
      <?= icon($e ? 'edit' : 'plus', 15) ?> <?= $e ? 'تعديل: ' . e($e['name']) : 'وجهة جديدة' ?>
    </b>
    <form method="post" action="<?= url('tracking/places') ?>" class="no-confirm" id="plForm">
      <?= csrfField() ?>
      <input type="hidden" name="id" value="<?= (int) ($e['id'] ?? 0) ?>">

      <label class="fld">
        <span>اسم الوجهة</span>
        <input type="text" name="name" id="plName" required maxlength="120"
               value="<?= e($e['name'] ?? $prefill) ?>" placeholder="مثلاً: مدينة السادات">
      </label>

      <label class="fld">
        <span>أسماء بديلة <small>(الاختصارات اللي بتكتبها في البوالص — افصلها بفاصلة)</small></span>
        <input type="text" name="aliases" maxlength="400"
               value="<?= e(str_replace('|', '، ', (string) ($e['aliases'] ?? ''))) ?>"
               placeholder="مثلاً: السادات، م السادات">
      </label>

      <div class="fld-row">
        <label class="fld"><span>خط العرض (lat)</span>
          <input type="text" name="lat" id="plLat" required inputmode="decimal"
                 value="<?= e($e['lat'] ?? '') ?>" placeholder="30.3661"></label>
        <label class="fld"><span>خط الطول (lng)</span>
          <input type="text" name="lng" id="plLng" required inputmode="decimal"
                 value="<?= e($e['lng'] ?? '') ?>" placeholder="30.5289"></label>
      </div>

      <label class="fld">
        <span>ملاحظة <small>(اختياري)</small></span>
        <input type="text" name="note" maxlength="160" value="<?= e($e['note'] ?? '') ?>">
      </label>

      <p class="pl-hint">
        <?= icon('map-pin', 13) ?> <b>اضغط على الخريطة</b> تحت عشان تحدّد المكان — الأرقام هتتملي لوحدها.
      </p>

      <div style="display:flex;gap:6px;margin-top:10px">
        <button class="btn primary" type="submit" style="flex:1">
          <?= icon('check', 15) ?> <?= $e ? 'احفظ التعديل' : 'أضِف الوجهة' ?>
        </button>
        <?php if ($e): ?>
          <a class="btn ghost" href="<?= url('tracking/places') ?>">إلغاء</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card" style="padding:0;overflow:hidden">
    <div id="plMap" style="height:420px;width:100%"></div>
  </div>
</div>

<?php $autoN = count(array_filter($points, fn($p) => (int) ($p['is_auto'] ?? 0) === 1)); ?>
<?php if ($autoN): ?>
<div class="flash" style="background:#eef4fd;border:1px solid #cddff5;color:#1f5fa8;margin-bottom:12px">
  <?= icon('sparkles', 18) ?>
  <div>
    <b><?= intf($autoN) ?> وجهة النظام حدّدها لوحده من خرائط OpenStreetMap.</b><br>
    شغّالة عادي، بس ابصّ عليها بصّة سريعة (عليها شارة «تلقائي» تحت) —
    الخرائط أحيانًا بتلخبط الأسماء المختصرة. أول ما تعدّل أي واحدة الشارة بتختفي.
  </div>
</div>
<?php endif; ?>

<h3 class="pl-h"><?= icon('truck', 17) ?> الوجهات اللي في بوالصك</h3>
<p class="pl-sub">الأكثر استخدامًا الأول. اللي عليها ⚠️ لسه مش متحدّدة، فالشحنات الرايحة لها مش هتوريك مسافة.</p>

<div class="card" style="padding:0;overflow-x:auto;margin-bottom:16px">
  <table class="data">
    <thead><tr><th>الوجهة في البوليصة</th><th>عدد البوالص</th><th>على الخريطة</th><th>نوع المطابقة</th><th data-no-sort></th></tr></thead>
    <tbody>
    <?php
    $mlbl = ['exact' => 'تام', 'alias' => 'اختصار', 'partial' => 'جزئي', 'fuzzy' => 'إملائي'];
    foreach ($coverage as $c): ?>
      <tr class="<?= $c['point'] ? '' : 'pl-missing' ?>">
        <td><b><?= e($c['name']) ?></b></td>
        <td><?= intf($c['count']) ?></td>
        <td>
          <?php if ($c['point']): ?>
            <span class="pl-ok">✓ <?= e($c['point']['name']) ?></span>
          <?php else: ?>
            <span class="pl-no">⚠️ مش متحدّدة</span>
          <?php endif; ?>
        </td>
        <td><?= $c['match'] ? '<span class="pl-m ' . e($c['match']) . '">' . e($mlbl[$c['match']]) . '</span>' : '—' ?></td>
        <td style="white-space:nowrap">
          <?php if ($c['point']): ?>
            <a class="btn sm ghost" href="<?= url('tracking/places?edit=' . (int) $c['point']['id']) ?>">تعديل</a>
          <?php else: ?>
            <form method="post" action="<?= url('tracking/places/auto') ?>" class="no-confirm" style="display:inline">
              <?= csrfField() ?>
              <input type="hidden" name="name" value="<?= e($c['name']) ?>">
              <button class="btn sm primary" type="submit" title="النظام يدوّر عليها في الخرائط لوحده">
                ✨ حدّدها تلقائيًا
              </button>
            </form>
            <a class="btn sm ghost" href="<?= url('tracking/places?name=' . urlencode($c['name'])) ?>">بإيدي</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<h3 class="pl-h"><?= icon('anchor', 17) ?> كل الوجهات المحدّدة (<?= count($points) ?>)</h3>
<div class="card" style="padding:0;overflow-x:auto">
  <table class="data">
    <thead><tr><th>الوجهة</th><th>الأسماء البديلة</th><th>الإحداثيات</th><th data-no-sort></th></tr></thead>
    <tbody>
    <?php foreach ($points as $p): ?>
      <tr>
        <td>
          <b><?= e($p['name']) ?></b>
          <?php if ((int) ($p['is_auto'] ?? 0) === 1): ?>
            <span class="pl-auto" title="النظام حدّدها لوحده — راجعها">✨ تلقائي</span>
          <?php endif; ?>
          <?= $p['note'] ? '<br><small style="color:var(--grey)">' . e($p['note']) . '</small>' : '' ?>
        </td>
        <td style="font-size:.78rem;color:var(--grey)"><?= e(str_replace('|', '، ', (string) $p['aliases'])) ?: '—' ?></td>
        <td style="font-size:.75rem;direction:ltr;text-align:left;font-family:ui-monospace,monospace">
          <a href="https://www.google.com/maps?q=<?= e($p['lat']) ?>,<?= e($p['lng']) ?>" target="_blank" rel="noopener">
            <?= e(number_format((float) $p['lat'], 4)) ?>, <?= e(number_format((float) $p['lng'], 4)) ?>
          </a>
        </td>
        <td style="white-space:nowrap">
          <a class="btn sm ghost" href="<?= url('tracking/places?edit=' . (int) $p['id']) ?>">تعديل</a>
          <form method="post" action="<?= url('tracking/places/' . (int) $p['id'] . '/delete') ?>"
                style="display:inline" data-confirm="تمسح «<?= e($p['name']) ?>»؟ الشحنات الرايحة لها مش هتوريك مسافة بعد كده.">
            <?= csrfField() ?>
            <button class="btn sm danger" type="submit"><?= icon('trash', 13) ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<style>
.pl-cols{display:grid;grid-template-columns:340px 1fr;gap:12px;margin-bottom:16px;align-items:start}
.fld{display:block;margin-bottom:9px}
.fld > span{display:block;font-size:.78rem;font-weight:800;color:var(--navy);margin-bottom:4px}
.fld > span small{font-weight:600;color:var(--grey);font-size:.72rem}
.fld input{width:100%}
.fld-row{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.pl-hint{font-size:.75rem;color:var(--grey);background:var(--bg);border:1px dashed var(--line);
  border-radius:8px;padding:7px 9px;margin-top:4px;line-height:1.6}
.pl-h{color:var(--navy);font-size:1rem;margin:0 0 3px}
.pl-sub{font-size:.8rem;color:var(--grey);margin-bottom:8px}
.pl-ok{color:#0f7a42;font-weight:800;font-size:.82rem}
.pl-no{color:#8a6100;font-weight:800;font-size:.82rem}
.pl-missing{background:#fffbf0}
.pl-m{font-size:.7rem;font-weight:800;padding:2px 8px;border-radius:99px}
.pl-m.exact{background:#e8f8ee;color:#0f7a42}
.pl-m.alias{background:#eef4fd;color:#1f5fa8}
.pl-m.partial{background:#fff6e5;color:#8a6100}
.pl-m.fuzzy{background:#fdeeee;color:#a12525}
.pl-auto{background:#eef4fd;color:#1f5fa8;font-size:.66rem;font-weight:800;padding:2px 7px;
  border-radius:99px;margin-inline-start:5px;white-space:nowrap;cursor:help}
.leaflet-container{font-family:inherit}
@media(max-width:900px){.pl-cols{grid-template-columns:1fr}}
</style>

<script src="<?= url('assets/vendor/leaflet/leaflet.js') ?>"></script>
<script>
(function(){
  L.Icon.Default.prototype.options.imagePath = <?= js_json(url('assets/vendor/leaflet/images/')) ?>;
  var latI = document.getElementById('plLat'), lngI = document.getElementById('plLng');

  // مصر كلها لو مفيش نقطة محدّدة
  var start = (latI.value && lngI.value) ? [parseFloat(latI.value), parseFloat(lngI.value)] : [30.0444, 31.2357];
  var map = L.map('plMap').setView(start, (latI.value ? 11 : 6));
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom:19, attribution:'© OpenStreetMap'}).addTo(map);

  // كل الوجهات المحفوظة كنقط ذهبية
  var all = <?= js_json(array_map(fn($p) => [
        'lat' => (float) $p['lat'], 'lng' => (float) $p['lng'],
        'name' => $p['name'], 'id' => (int) $p['id'],
      ], $points)) ?>;
  var EDIT_ID = <?= (int) ($e['id'] ?? 0) ?>;
  var EDIT_URL = <?= js_json(url('tracking/places?edit=')) ?>;

  all.forEach(function(p){
    if (p.id === EDIT_ID) return;   // اللي بنعدّله بيبان كماركر متحرّك تحت
    var d = document.createElement('div'); d.textContent = p.name;
    L.circleMarker([p.lat, p.lng], {radius:5, color:'#fff', weight:1.5, fillColor:'#C9A227', fillOpacity:.9})
      .addTo(map).bindPopup('<b>' + d.innerHTML + '</b><br><a href="' + EDIT_URL + p.id + '">تعديل ←</a>');
  });

  // الماركر اللي بيتحرك بالضغط أو بالسحب
  var mk = null;
  function place(lat, lng){
    latI.value = lat.toFixed(6); lngI.value = lng.toFixed(6);
    if (mk) { mk.setLatLng([lat, lng]); }
    else {
      mk = L.marker([lat, lng], {draggable:true}).addTo(map);
      mk.on('dragend', function(){ var c = mk.getLatLng(); place(c.lat, c.lng); });
    }
  }
  if (latI.value && lngI.value) { place(parseFloat(latI.value), parseFloat(lngI.value)); }
  map.on('click', function(ev){ place(ev.latlng.lat, ev.latlng.lng); });

  // لو الاسم متكتب ومفيش إحداثيات — دوّر عليه في OpenStreetMap
  var nameI = document.getElementById('plName');
  var btn = document.createElement('button');
  btn.type = 'button'; btn.className = 'btn sm ghost'; btn.style.marginTop = '5px';
  btn.textContent = '🔍 دوّر على الاسم ده في الخريطة';
  btn.addEventListener('click', function(){
    var q = nameI.value.trim();
    if (!q) { window.AppUI.notice('اكتب اسم الوجهة الأول.'); nameI.focus(); return; }
    btn.disabled = true; btn.textContent = '⏳ بندوّر…';
    // ⚠️ البحث بيمرّ على السيرفر مش من المتصفح مباشرة. سببين: الـCSP بتمنع أي
    //    اتصال خارجي من الصفحة (`connect-src 'self'`)، والأهم إن السيرفر عنده
    //    فلترة `Track::geocodePlace` اللي بتمنع فخ «أول نتيجة» (البحث الخام عن
    //    «العاشر» بيرجّع مقبرة في الأقصر). ما ترجّعش الاتصال المباشر.
    var fd = new FormData();
    fd.append('name', q);
    fd.append('_csrf', <?= js_json(App\Core\Auth::csrf()) ?>);
    fetch(<?= js_json(url('tracking/places/lookup')) ?>, {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: {'X-Requested-With': 'XMLHttpRequest'}
      })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (j && j.ok) {
          place(j.lat, j.lng); map.setView([j.lat, j.lng], 12);
          btn.textContent = '✓ لقيناه — راجعه على الخريطة';
        } else { btn.textContent = '✗ ' + ((j && j.msg) || 'مالقيناش — حدّده بإيدك على الخريطة'); }
      })
      .catch(function(){ btn.textContent = '✗ مفيش نت — حدّده بإيدك'; })
      .finally(function(){ btn.disabled = false;
        setTimeout(function(){ btn.textContent = '🔍 دوّر على الاسم ده في الخريطة'; }, 4000); });
  });
  nameI.parentNode.appendChild(btn);
})();
</script>
