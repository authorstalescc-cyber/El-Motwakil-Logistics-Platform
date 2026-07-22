<?php
/**
 * لوحة تتبع الشحنات — خريطة بكل السيارات المتحرّكة + كارت لكل شحنة.
 * ⚠️ ما تعرضش أي مبلغ: الوحدة وصفية بحتة ولا تمسّ أي حساب.
 */
use App\Models\Track;
$hasBase = $base !== '';
?>
<link rel="stylesheet" href="<?= url('assets/vendor/leaflet/leaflet.css') ?>">

<div class="pg-head">
  <div>
    <h2 class="pg-title"><?= icon('map-pin', 22) ?> تتبع الشحنات</h2>
    <p class="pg-sub">ابعت رابط للسائق على الواتساب، يضغط زرار، فتشوف مكان الشحنة بالتاريخ والساعة.</p>
  </div>
  <div class="pg-actions">
    <a class="btn ghost" href="<?= url('tracking/places') ?>"><?= icon('anchor', 16) ?> وجهات الخريطة</a>
    <a class="btn primary" href="<?= url('tracking/new') ?>"><?= icon('plus', 16) ?> رابط تتبع جديد</a>
  </div>
</div>

<?php if (!$hasBase): ?>
  <div class="flash error" style="margin-bottom:12px">
    <?= icon('alert', 18) ?>
    <div>
      <b>مفيش رابط عام دلوقتي — الروابط مش هتفتح عند السائقين.</b><br>
      اقفل النظام وافتحه من ملف <b>«تشغيل-عام-على-النت»</b> (مش «تشغيل-النظام»)، وهو هيجهّز الرابط العام لوحده.
    </div>
  </div>
<?php endif; ?>

<div class="trk-tabs">
  <?php
  $tabs = ['active' => 'في الطريق', 'done' => 'وصلت', 'cancelled' => 'ملغاة', 'all' => 'الكل'];
  foreach ($tabs as $k => $lbl):
      $n = $k === 'all' ? array_sum($counts) : ($counts[$k] ?? 0);
  ?>
    <a href="<?= url('tracking?status=' . $k . ($q !== '' ? '&q=' . urlencode($q) : '')) ?>"
       class="trk-tab <?= $status === $k ? 'active' : '' ?>"><?= e($lbl) ?> <span class="n"><?= (int) $n ?></span></a>
  <?php endforeach; ?>
  <form method="get" action="<?= url('tracking') ?>" class="trk-search">
    <input type="hidden" name="status" value="<?= e($status) ?>">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="سائق، سيارة، بوليصة، حاوية، وجهة…">
    <button class="btn sm" type="submit"><?= icon('search', 15) ?></button>
  </form>
</div>

<?php
$onMap = array_values(array_filter($tracks, fn($t) => $t['last_lat'] !== null));
if ($onMap):
?>
<div class="card" style="padding:0;overflow:hidden;margin-bottom:14px">
  <div id="allMap" style="height:420px;width:100%"></div>
</div>
<?php endif; ?>

<?php if (!$tracks): ?>
  <div class="card empty-state">
    <div class="empty-ic"><?= icon('map-pin', 40) ?></div>
    <h3>مفيش شحنات بتتتبع دلوقتي</h3>
    <p>اضغط «رابط تتبع جديد»، اختار الشحنة، وابعت الرابط للسائق على الواتساب.</p>
    <a class="btn primary" href="<?= url('tracking/new') ?>"><?= icon('plus', 16) ?> رابط تتبع جديد</a>
  </div>
<?php else: ?>
<div class="trk-grid">
  <?php foreach ($tracks as $t):
      [$pk, $ptxt] = Track::pulse($t);
      $link  = Track::publicLink($t);
      $wa    = wa_phone($t['driver_phone'] ?? '');
      $stale = Track::isStaleLink($t);
      $cont  = containerLabel($t['container_type'] ?? '', $t['container_count'] ?? 0);
  ?>
  <div class="card trk-card">
    <div class="trk-top">
      <div class="trk-who">
        <span class="trk-av"><?= e(initials((string) ($t['driver'] ?: '؟'))) ?></span>
        <div>
          <a class="trk-name" href="<?= url('tracking/' . (int) $t['id']) ?>"><?= e($t['driver'] ?: 'بلا سائق') ?></a>
          <div class="trk-car"><?= icon('truck', 13) ?> <?= e($t['car_no'] ?: '—') ?></div>
        </div>
      </div>
      <span class="trk-pulse <?= e($pk) ?>"><?= e($ptxt) ?></span>
    </div>

    <div class="trk-meta">
      <?php if (!empty($t['bill_number'])): ?>
        <span><?= icon('file-text', 13) ?> بوليصة <b><?= e($t['bill_number']) ?></b></span>
      <?php endif; ?>
      <?php if (!empty($t['destination'])): ?>
        <span><?= icon('anchor', 13) ?> <b><?= e($t['destination']) ?></b></span>
      <?php endif; ?>
      <?php if ((int) ($t['container_count'] ?? 0) > 0): ?>
        <span><?= icon('package', 13) ?> <b><?= e($cont) ?></b></span>
      <?php endif; ?>
    </div>

    <?php if ($t['last_lat'] !== null): ?>
      <div class="trk-loc">
        <?= icon('map-pin', 14) ?>
        <div>
          <b><?= e($t['last_address'] ?: 'موقع مسجّل') ?></b>
          <small><?= e(date('d/m — H:i', strtotime($t['last_at']))) ?>
            · <?= e(Track::ago($t['last_at'])) ?>
            <?php if ((float) $t['total_m'] > 0): ?> · قطع <?= e(number_format((float) $t['total_m'] / 1000, 1)) ?> كم<?php endif; ?>
          </small>
        </div>
      </div>
      <?php // المسافة المتبقية والوصول المتوقع — تقديري بحت (v34)
      $eta = Track::eta($t, $t['destination'] ?? null);
      if ($eta): ?>
        <div class="trk-eta <?= $eta['arrived'] ? 'done' : '' ?>">
          <?= icon($eta['arrived'] ? 'check-circle' : 'anchor', 14) ?>
          <div>
            <?php if ($eta['arrived']): ?>
              <b>وصل <?= e($eta['point']['name']) ?> تقريبًا</b>
              <small>على بُعد أقل من <?= e(number_format($eta['straight_km'], 1)) ?> كم من مركزها</small>
            <?php else: ?>
              <b>فاضل ~<?= e(number_format($eta['km'], 0)) ?> كم على <?= e($eta['point']['name']) ?></b>
              <small><?= e(Track::hoursText($eta['hours'])) ?>
                — حوالي الساعة <?= e(Track::etaClock($eta['hours'])) ?>
                <span class="trk-est">تقديري</span>
              </small>
            <?php endif; ?>
          </div>
        </div>
      <?php elseif (!empty($t['destination'])): ?>
        <div class="trk-eta none">
          <?= icon('alert', 13) ?>
          <div><small>«<?= e($t['destination']) ?>» لسه مش متحدّدة على الخريطة —
            <a href="<?= url('tracking/places?name=' . urlencode($t['destination'])) ?>">حدّدها</a>
            عشان نحسب المسافة.</small></div>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="trk-loc none"><?= icon('clock', 14) ?> <b>لسه مبعتش موقعه</b></div>
    <?php endif; ?>

    <?php if ($stale): ?>
      <div class="trk-stale"><?= icon('alert', 13) ?> الرابط ده اتولّد على عنوان قديم — جدّده قبل ما تبعته.</div>
    <?php endif; ?>

    <div class="trk-btns">
      <a class="btn sm ghost" href="<?= url('tracking/' . (int) $t['id']) ?>"><?= icon('map-pin', 14) ?> الخريطة</a>
      <?php if ($link !== ''): ?>
        <?php if ($wa !== ''): ?>
          <a class="btn sm wa" target="_blank" rel="noopener"
             href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode(Track::waMessage($t, $link)) ?>">واتساب</a>
        <?php endif; ?>
        <button type="button" class="btn sm ghost js-copy" data-copy="<?= e($link) ?>"><?= icon('external', 14) ?> نسخ الرابط</button>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<style>
.trk-tabs{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-bottom:12px}
.trk-tab{padding:7px 14px;border-radius:99px;border:1px solid var(--line);background:var(--card);
  font-size:.83rem;font-weight:800;color:var(--grey);text-decoration:none;display:flex;align-items:center;gap:6px}
.trk-tab.active{background:var(--navy);color:#fff;border-color:var(--navy)}
.trk-tab .n{background:rgba(0,0,0,.08);border-radius:99px;padding:1px 7px;font-size:.74rem}
.trk-tab.active .n{background:rgba(255,255,255,.22)}
.trk-search{display:flex;gap:6px;margin-inline-start:auto}
.trk-search input{min-width:230px}
.trk-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:12px}
.trk-card{display:flex;flex-direction:column;gap:10px;padding:14px}
.trk-top{display:flex;justify-content:space-between;align-items:flex-start;gap:10px}
.trk-who{display:flex;gap:10px;align-items:center}
.trk-av{width:40px;height:40px;border-radius:12px;background:var(--navy);color:var(--gold);display:flex;
  align-items:center;justify-content:center;font-weight:900;font-size:.9rem;flex-shrink:0}
.trk-name{font-weight:900;color:var(--navy);text-decoration:none;font-size:.98rem}
.trk-name:hover{text-decoration:underline}
.trk-car{font-size:.78rem;color:var(--grey);display:flex;align-items:center;gap:4px;margin-top:2px}
.trk-pulse{font-size:.7rem;font-weight:800;padding:4px 9px;border-radius:99px;white-space:nowrap;flex-shrink:0}
.trk-pulse.live{background:#e8f8ee;color:#0f7a42}
.trk-pulse.ok{background:#eef4fd;color:#1f5fa8}
.trk-pulse.warn{background:#fff6e5;color:#8a6100}
.trk-pulse.cold{background:#f1f3f6;color:#75808f}
.trk-meta{display:flex;flex-wrap:wrap;gap:6px}
.trk-meta span{font-size:.75rem;color:var(--grey);background:var(--bg);border:1px solid var(--line);
  border-radius:8px;padding:3px 8px;display:flex;align-items:center;gap:4px}
.trk-meta b{color:var(--ink);font-weight:800}
.trk-loc{display:flex;gap:8px;align-items:flex-start;background:#f4f9f5;border:1px solid #cfe8d8;
  border-radius:10px;padding:9px 11px}
.trk-loc.none{background:var(--bg);border-color:var(--line);align-items:center}
.trk-loc b{font-size:.83rem;font-weight:800;display:block;line-height:1.5}
.trk-loc small{font-size:.72rem;color:var(--grey);display:block;margin-top:2px}
.trk-stale{font-size:.72rem;font-weight:700;color:#8a6100;background:#fff6e5;border:1px solid #f2dfa8;
  border-radius:8px;padding:6px 9px;display:flex;align-items:center;gap:5px}
.trk-eta{display:flex;gap:8px;align-items:flex-start;background:#eef4fd;border:1px solid #cddff5;
  border-radius:10px;padding:8px 11px;color:#1f5fa8}
.trk-eta.done{background:#e8f8ee;border-color:#bfe6cf;color:#0f7a42}
.trk-eta.none{background:var(--bg);border-color:var(--line);color:var(--grey);align-items:center}
.trk-eta b{font-size:.82rem;font-weight:800;display:block;line-height:1.5}
.trk-eta small{font-size:.72rem;display:block;margin-top:2px;opacity:.85}
.trk-eta a{color:inherit;font-weight:800;text-decoration:underline}
.trk-est{background:rgba(0,0,0,.07);border-radius:99px;padding:1px 6px;font-size:.65rem;font-weight:800;
  margin-inline-start:4px}
.trk-btns{display:flex;gap:6px;flex-wrap:wrap;margin-top:auto;padding-top:4px}
.btn.wa{background:#25D366;color:#fff;border-color:#25D366;font-weight:800}
.btn.wa:hover{background:#1eb356;border-color:#1eb356}
.empty-state{text-align:center;padding:42px 20px}
.empty-ic{color:var(--grey);opacity:.45;margin-bottom:10px}
.empty-state h3{color:var(--navy);margin-bottom:6px}
.empty-state p{color:var(--grey);font-size:.87rem;margin-bottom:16px}
.leaflet-container{font-family:inherit}
.trk-pop{font-family:inherit;min-width:170px;line-height:1.7}
.trk-pop b{color:#15355B;font-size:.92rem}
.trk-pop small{color:#6b7a90;display:block}
.trk-pop a{color:#1f5fa8;font-weight:800;font-size:.8rem}
@media(max-width:640px){.trk-search{margin-inline-start:0;width:100%}.trk-search input{flex:1;min-width:0}}
</style>

<?php if ($onMap): ?>
<script src="<?= url('assets/vendor/leaflet/leaflet.js') ?>"></script>
<script>
(function(){
  // مسار الأيقونات: Leaflet بيدوّر عليها جنب الـ CSS افتراضيًا
  L.Icon.Default.prototype.options.imagePath = <?= js_json(url('assets/vendor/leaflet/images/')) ?>;

  var map = L.map('allMap', {scrollWheelZoom:false});
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19, attribution: '© OpenStreetMap'
  }).addTo(map);
  map.on('click', function(){ map.scrollWheelZoom.enable(); });

  var COLORS = {live:'#1e9e5a', ok:'#1f5fa8', warn:'#c98a00', cold:'#8c97a6'};
  var layer = L.layerGroup().addTo(map);

  function draw(cars){
    layer.clearLayers();
    var pts = [];
    cars.forEach(function(c){
      var m = L.circleMarker([c.lat, c.lng], {
        radius: 9, color:'#fff', weight: 2,
        fillColor: COLORS[c.pulse] || COLORS.cold, fillOpacity: 1
      }).addTo(layer);
      var h = '<div class="trk-pop"><b>' + esc(c.driver) + '</b>';
      if (c.car)  h += '<small>🚚 ' + esc(c.car) + '</small>';
      if (c.bill) h += '<small>بوليصة ' + esc(c.bill) + '</small>';
      if (c.dest) h += '<small>رايحة ' + esc(c.dest) + '</small>';
      if (c.addr) h += '<small>📍 ' + esc(c.addr) + '</small>';
      h += '<small>' + esc(c.ago) + '</small>';
      h += '<a href="' + c.url + '">افتح الرحلة ←</a></div>';
      m.bindPopup(h);
      pts.push([c.lat, c.lng]);
    });
    return pts;
  }
  function esc(s){ var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

  var initial = <?= js_json(array_map(fn($t) => [
        'lat'    => (float) $t['last_lat'],
        'lng'    => (float) $t['last_lng'],
        'driver' => $t['driver'] ?: '—',
        'car'    => $t['car_no'] ?: '',
        'bill'   => $t['bill_number'] ?: '',
        'dest'   => $t['destination'] ?: '',
        'addr'   => $t['last_address'] ?: '',
        'ago'    => Track::ago($t['last_at']),
        'pulse'  => Track::pulse($t)[0],
        'url'    => url('tracking/' . (int) $t['id']),
      ], $onMap)) ?>;

  var pts = draw(initial);
  if (pts.length === 1) { map.setView(pts[0], 12); }
  else { map.fitBounds(L.latLngBounds(pts).pad(0.18)); }

  // تحديث تلقائي كل دقيقة — الكروت تحت ما بتتحدّثش إلا بإعادة التحميل
  <?php if ($status === 'active'): ?>
  setInterval(function(){
    fetch(<?= js_json(url('tracking/feed')) ?>, {headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(r){ return r.json(); })
      .then(function(j){ if (j.ok && j.cars.length) draw(j.cars); })
      .catch(function(){});
  }, 60000);
  <?php endif; ?>
})();
</script>
<?php endif; ?>

<script>
// نسخ الرابط — يشتغل حتى لو المتصفح مش مدّي clipboard API (http)
document.querySelectorAll('.js-copy').forEach(function(b){
  b.addEventListener('click', function(){
    var txt = b.dataset.copy, done = function(){
      var o = b.innerHTML; b.innerHTML = '✓ اتنسخ'; setTimeout(function(){ b.innerHTML = o; }, 1600);
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(txt).then(done).catch(function(){ window.AppUI.ask('انسخ الرابط التالي:',function(){},{defaultValue:txt,readOnly:true,required:false,title:'نسخ الرابط'}); });
    } else {
      var ta = document.createElement('textarea');
      ta.value = txt; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); done(); } catch(e) { window.AppUI.ask('انسخ الرابط التالي:',function(){},{defaultValue:txt,readOnly:true,required:false,title:'نسخ الرابط'}); }
      document.body.removeChild(ta);
    }
  });
});
</script>
