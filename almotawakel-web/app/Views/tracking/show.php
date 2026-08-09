<?php
/**
 * صفحة الرحلة: الخريطة + خط السير + سجل النقاط + إدارة الرابط.
 * ⚠️ ما تعرضش أي مبلغ من البوليصة — بيانات الشحنة الوصفية بس.
 */
use App\Models\Track;
[$pk, $ptxt] = Track::pulse($t);
$wa    = wa_phone($t['driver_phone'] ?? '');
$stale = Track::isStaleLink($t);
$open  = Track::isOpen($t);
$msg   = $link !== '' ? Track::waMessage($t + ['bill_number' => $bill['bill_number'] ?? '',
                                               'destination' => $bill['destination'] ?? ''], $link) : '';
?>
<link rel="stylesheet" href="<?= url('assets/vendor/leaflet/leaflet.css') ?>">

<div class="pg-head">
  <div>
    <h2 class="pg-title"><?= icon('map-pin', 22) ?> <?= e($t['driver'] ?: 'بلا سائق') ?>
      <span class="trk-pulse <?= e($pk) ?> tsh-xs"><?= e($ptxt) ?></span>
    </h2>
    <p class="pg-sub">
      <?= icon('truck', 13) ?> <?= e($t['car_no'] ?: '—') ?>
      <?php if (!empty($bill['bill_number'])): ?> · بوليصة
        <a href="<?= url('bawalis/' . (int) $t['bill_id']) ?>"><?= e($bill['bill_number']) ?></a>
      <?php endif; ?>
      <?php if (!empty($bill['destination'])): ?> · رايحة <b><?= e($bill['destination']) ?></b><?php endif; ?>
      <?php if (!empty($bill['container_no'])): ?> · حاوية <?= e($bill['container_no']) ?><?php endif; ?>
    </p>
    <?php if (Track::isOpen($t)): ?><span class="live-pill" id="trackLive1">● بيتحدّث تلقائيًا</span><?php endif; ?>
  </div>
  <div class="pg-actions">
    <a class="btn ghost" href="<?= url('tracking') ?>">‹ كل الشحنات</a>
  </div>
</div>

<?php // شريط المسافة المتبقية والوصول المتوقع — تقديري بحت (v34)
if ($eta): ?>
<div class="trk-eta-bar <?= $eta['arrived'] ? 'done' : '' ?>">
  <span class="eb-ic"><?= icon($eta['arrived'] ? 'check-circle' : 'anchor', 22) ?></span>
  <?php if ($eta['arrived']): ?>
    <div class="eb-main">
      <b>وصل <?= e($eta['point']['name']) ?> تقريبًا</b>
      <small>آخر موقع على بُعد <?= e(number_format($eta['straight_km'], 1)) ?> كم من مركز الوجهة</small>
    </div>
  <?php else: ?>
    <div class="eb-main">
      <b>فاضل ~<?= e(number_format($eta['km'], 0)) ?> كم على <?= e($eta['point']['name']) ?></b>
      <small><?= e(Track::hoursText($eta['hours'])) ?> — يعني حوالي الساعة
        <b><?= e(Track::etaClock($eta['hours'])) ?></b></small>
    </div>
    <div class="eb-side">
      <span>السرعة المحسوب بيها</span>
      <b><?= e(number_format($eta['speed'], 0)) ?> كم/س</b>
      <small><?= $eta['speed_src'] === 'actual' ? 'سرعته الفعلية في الرحلة دي' : 'المتوسط الافتراضي' ?></small>
    </div>
  <?php endif; ?>
  <span class="eb-note" title="المسافة خط مستقيم مضروب في معامل طريق (<?= e(App\Core\App::setting('track_road_factor','1.3')) ?>)، مش مسافة طريق حقيقية">
    تقديري ⓘ
  </span>
</div>
<?php elseif (!empty($bill['destination'])): ?>
<div class="trk-eta-bar none">
  <span class="eb-ic"><?= icon('alert', 20) ?></span>
  <div class="eb-main">
    <b>«<?= e($bill['destination']) ?>» مش متحدّدة على الخريطة</b>
    <small>حدّد إحداثياتها مرة واحدة وهتتحسب لكل الشحنات الرايحة لها بعد كده —
      <a href="<?= url('tracking/places?name=' . urlencode($bill['destination'])) ?>">حدّدها دلوقتي</a></small>
  </div>
</div>
<?php endif; ?>

<div class="trk-stats">
  <div class="trk-stat"><span>النقاط</span><b><?= intf($summary['pings']) ?></b></div>
  <div class="trk-stat"><span>المسافة</span><b><?= e(number_format($summary['km'], 1)) ?> كم</b></div>
  <div class="trk-stat"><span>المدة</span><b>
    <?php $h = intdiv($summary['secs'], 3600); $m = intdiv($summary['secs'] % 3600, 60); ?>
    <?= $summary['secs'] > 0 ? ($h > 0 ? $h . ' س ' . $m . ' د' : $m . ' د') : '—' ?>
  </b></div>
  <div class="trk-stat"><span>متوسط السرعة</span><b><?= $summary['kmh'] > 0 ? e($summary['kmh']) . ' كم/س' : '—' ?></b></div>
  <div class="trk-stat"><span>الحالة</span><b><?= e(Track::STATUSES[$t['status']] ?? $t['status']) ?></b></div>
</div>

<div class="trk-cols">
  <div>
    <?php if ($pings): ?>
      <div class="card mb">
        <div id="map" class="tsh-map"></div>
      </div>
    <?php else: ?>
      <div class="card tsh-empty">
        <div class="tsh-dim"><?= icon('map-pin', 40) ?></div>
        <h3 class="tsh-h">لسه مفيش مواقع</h3>
        <p class="tsh-mut-m">
          السائق لسه مضغطش الزرار.
          <?= empty($t['opened_at']) ? 'ولا فتح الرابط أصلاً.' : 'بس هو فتح الرابط ' . e(Track::ago($t['opened_at'])) . '.' ?>
        </p>
      </div>
    <?php endif; ?>

    <?php if ($pings): ?>
    <div class="card" >
      <div class="card-head tsh-pad-sm"><b><?= icon('clock', 15) ?> سجل المواقع</b>
        <small class="tsh-mut-s">الأحدث فوق</small></div>
      <div class="tsh-scroll">
        <table class="data" data-no-sort>
          <thead><tr><th>الوقت</th><th>المكان</th><th>المسافة</th><th>الطريقة</th></tr></thead>
          <tbody>
          <?php foreach (array_reverse($pings) as $p): ?>
            <tr>
              <td class="nowrap">
                <b><?= e(dtfmt($p['created_at'], 'H:i')) ?></b><br>
                <small class="dbt-none"><?= e(dtfmt($p['created_at'], 'd/m')) ?></small>
              </td>
              <td>
                <?= e($p['address'] ?: '—') ?><br>
                <small class="dbt-none">
                  <a href="https://www.google.com/maps?q=<?= e($p['lat']) ?>,<?= e($p['lng']) ?>"
                     target="_blank" rel="noopener">افتح في خرائط جوجل ←</a>
                  <?php if ($p['accuracy_m'] !== null): ?> · دقة ±<?= intf($p['accuracy_m']) ?>م<?php endif; ?>
                </small>
              </td>
              <td class="nowrap"><?= (int) $p['dist_m'] > 0 ? e(number_format($p['dist_m'] / 1000, 2)) . ' كم' : '—' ?></td>
              <td><span class="src <?= e($p['source']) ?>"><?= $p['source'] === 'live' ? 'تتبع مباشر' : 'ضغط الزرار' ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <aside class="trk-side">
    <div class="card tsh-pad">
      <b class="tsh-lbl"><?= icon('send', 15) ?> رابط السائق</b>

      <?php if ($link === ''): ?>
        <div class="flash error tsh-p">
          <?= icon('alert', 15) ?> مفيش رابط عام — شغّل «تشغيل-عام-على-النت» الأول.
        </div>
      <?php else: ?>
        <?php if ($stale): ?>
          <div class="trk-stale tsh-mb">
            <?= icon('alert', 13) ?> الرابط اتولّد على عنوان قديم — اضغط «جدّد» قبل ما تبعته.
          </div>
        <?php endif; ?>
        <?php if (!$open): ?>
          <div class="trk-stale tsh-mb">
            <?= icon('alert', 13) ?> الرابط مقفول دلوقتي (<?= e(Track::STATUSES[$t['status']] ?? '') ?>
            <?php if (!empty($t['expires_at']) && strtotime($t['expires_at']) < time()): ?>/ انتهت صلاحيته<?php endif; ?>).
          </div>
        <?php endif; ?>

        <div class="trk-link" title="<?= e($link) ?>"><?= e($link) ?></div>
        <div class="tsh-row wrap my">
          <?php if ($wa !== '' && $open): ?>
            <a class="btn sm wa" target="_blank" rel="noopener"
               href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode($msg) ?>">ابعت واتساب</a>
          <?php endif; ?>
          <button type="button" class="btn sm ghost js-copy" data-copy="<?= e($link) ?>">نسخ الرابط</button>
          <button type="button" class="btn sm ghost js-copy" data-copy="<?= e($msg) ?>">نسخ الرسالة</button>
        </div>
      <?php endif; ?>

      <form method="post" action="<?= url('tracking/' . (int) $t['id'] . '/phone') ?>"
            class="no-confirm" class="tsh-row tsh-mb">
        <?= csrfField() ?>
        <input type="tel" name="driver_phone" value="<?= e($t['driver_phone'] ?? '') ?>"
               placeholder="رقم واتساب السائق" class="ps-sp" inputmode="tel">
        <button class="btn sm" type="submit">حفظ</button>
      </form>

      <form method="post" action="<?= url('tracking/' . (int) $t['id'] . '/renew') ?>" class="no-confirm">
        <?= csrfField() ?>
        <button class="btn sm ghost w-full" type="submit">
          <?= icon('undo', 14) ?> جدّد الرابط ومدّ الصلاحية
        </button>
      </form>

      <div class="tsh-note">
        <?php if (!empty($t['expires_at'])): ?>
          الصلاحية: <b><?= e(date('d/m — H:i', strtotime($t['expires_at']))) ?></b><br>
        <?php endif; ?>
        <?= empty($t['opened_at']) ? 'السائق لسه مفتحش الرابط.' : 'فتحه أول مرة ' . e(dtfmt($t['opened_at'], 'd/m — H:i')) ?>
      </div>
    </div>

    <div class="card tsh-pad">
      <b class="tsh-lbl"><?= icon('check-circle', 15) ?> حالة الرحلة</b>
      <div class="tsh-row wrap">
        <?php foreach (Track::STATUSES as $k => $lbl): ?>
          <form method="post" action="<?= url('tracking/' . (int) $t['id'] . '/status') ?>" class="no-confirm" >
            <?= csrfField() ?>
            <input type="hidden" name="status" value="<?= e($k) ?>">
            <button class="btn sm <?= $t['status'] === $k ? 'primary' : 'ghost' ?>" type="submit"><?= e($lbl) ?></button>
          </form>
        <?php endforeach; ?>
      </div>
      <p class="tsh-note">
        «وصلت» أو «ملغاة» بتقفل الرابط فورًا — السائق ما يقدرش يبعت موقعه تاني.
      </p>
    </div>

    <form method="post" action="<?= url('tracking/' . (int) $t['id'] . '/delete') ?>"
          data-confirm="تمسح تتبع الشحنة دي وكل مواقعها؟ البوليصة نفسها مش هتتأثر.">
      <?= csrfField() ?>
      <button class="btn sm danger w-full" type="submit"><?= icon('trash', 14) ?> امسح التتبع</button>
    </form>
  </aside>
</div>

<style>
.trk-eta-bar{display:flex;align-items:center;gap:12px;background:var(--tint-navy);border:1px solid var(--navy-line);
  border-radius:var(--radius-2);padding:12px 15px;margin-bottom:12px;color:var(--accent);flex-wrap:wrap}
.trk-eta-bar.done{background:var(--green-lt);border-color:var(--green-line);color:var(--green-fg)}
.trk-eta-bar.none{background:var(--gold-light);border-color:var(--gold-line);color:var(--gold-fg)}
.eb-ic{flex-shrink:0;opacity:.9}
.eb-main{flex:1;min-width:180px}
.eb-main b{font-size:var(--fs-4);font-weight:700;display:block;line-height:1.5}
.eb-main small{font-size:var(--fs-1);display:block;margin-top:2px;opacity:.9}
.eb-main a{color:inherit;font-weight:700;text-decoration:underline}
.eb-side{text-align:center;border-inline-start:1px solid currentColor;padding-inline-start:12px;opacity:.85}
.eb-side span{font-size:var(--fs-1);display:block}
.eb-side b{font-size:var(--fs-4);font-weight:700;display:block}
.eb-side small{font-size:var(--fs-1);display:block}
.eb-note{background:rgba(0,0,0,.07);border-radius:99px;padding:3px 9px;font-size:var(--fs-1);font-weight:600;
  cursor:help;white-space:nowrap}
.trk-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:9px;margin-bottom:12px}
.trk-stat{background:var(--card);border:1px solid var(--line);border-radius:var(--radius-2);padding:10px 12px}
.trk-stat span{font-size:var(--fs-1);color:var(--grey);display:block}
.trk-stat b{font-size:var(--fs-4);color:var(--accent);font-weight:700}
.trk-cols{display:grid;grid-template-columns:1fr 320px;gap:12px;align-items:start}
.trk-side{display:flex;flex-direction:column;gap:10px;position:sticky;top:12px}
.trk-link{background:var(--bg);border:1px dashed var(--line);border-radius:var(--radius-1);padding:8px 10px;
  font-size:var(--fs-1);font-family:ui-monospace,monospace;word-break:break-all;color:var(--accent);direction:ltr;text-align:left}
.trk-pulse{font-weight:600;padding:4px 9px;border-radius:99px;white-space:nowrap}
.trk-pulse.live{background:var(--green-lt);color:var(--green-fg)}
.trk-pulse.ok{background:var(--tint-navy);color:var(--accent)}
.trk-pulse.warn{background:var(--gold-light);color:var(--gold-fg)}
.trk-pulse.cold{background:var(--zebra);color:var(--grey)}
.trk-stale{font-size:var(--fs-1);font-weight:500;color:var(--gold-fg);background:var(--gold-light);border:1px solid var(--gold-line);
  border-radius:var(--radius-1);padding:6px 9px;display:flex;align-items:center;gap:5px;line-height:1.6}
.src{font-size:var(--fs-1);font-weight:600;padding:3px 8px;border-radius:99px;white-space:nowrap}
.src.manual{background:var(--tint-navy);color:var(--accent)}
.src.live{background:var(--green-lt);color:var(--green-fg)}
.btn.wa{background:#25D366;color:#08331A;border-color:#25D366;font-weight:600}
.btn.wa:hover{background:#1eb356;border-color:#1eb356}
.leaflet-container{font-family:inherit}
.trk-pop{font-family:inherit;line-height:1.7}
.trk-pop b{color:var(--accent)}
.trk-pop small{color:var(--grey);display:block}
@media(max-width:900px){.trk-cols{grid-template-columns:1fr}.trk-side{position:static}}
</style>

<?php if ($pings): ?>
<script src="<?= url('assets/vendor/leaflet/leaflet.js') ?>"></script>
<script>
(function(){
  L.Icon.Default.prototype.options.imagePath = <?= js_json(url('assets/vendor/leaflet/images/')) ?>;
  var FEED = <?= js_json(url('tracking/' . (int) $t['id'] . '/feed')) ?>;
  var LIVE = <?= $pk === 'live' ? 'true' : 'false' ?>;
  var DEST = <?= $eta ? js_json([
        'lat'  => (float) $eta['point']['lat'],
        'lng'  => (float) $eta['point']['lng'],
        'name' => $eta['point']['name'],
      ]) : 'null' ?>;

  var map = L.map('map', {scrollWheelZoom:false});
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19, attribution: '© OpenStreetMap'
  }).addTo(map);
  map.on('click', function(){ map.scrollWheelZoom.enable(); });

  var layer = L.layerGroup().addTo(map);
  function esc(s){ var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

  function draw(pings, fit){
    layer.clearLayers();
    if (!pings.length) return;
    var pts = pings.map(function(p){ return [p.lat, p.lng]; });

    // خط السير
    if (pts.length > 1) {
      L.polyline(pts, {color:'#15355B', weight:3, opacity:.55}).addTo(layer);
    }
    // النقاط الوسيطة صغيرة
    pings.forEach(function(p, i){
      if (i === pings.length - 1) return;
      L.circleMarker([p.lat, p.lng], {
        radius: 4, color:'#fff', weight:1.5,
        fillColor: (i === 0 ? '#1e9e5a' : '#8c97a6'), fillOpacity: 1
      }).addTo(layer).bindPopup('<div class="trk-pop"><b>' + (i === 0 ? 'البداية' : 'نقطة ' + (i+1)) +
        '</b><small>' + esc(p.date) + ' — ' + esc(p.at) + '</small>' +
        (p.addr ? '<small>📍 ' + esc(p.addr) + '</small>' : '') + '</div>');
    });
    // آخر نقطة = الماركر الكبير
    var last = pings[pings.length - 1];
    L.marker([last.lat, last.lng]).addTo(layer)
      .bindPopup('<div class="trk-pop"><b>آخر موقع</b><small>' + esc(last.date) + ' — ' + esc(last.at) +
        '</small>' + (last.addr ? '<small>📍 ' + esc(last.addr) + '</small>' : '') +
        '<small><a href="https://www.google.com/maps?q=' + last.lat + ',' + last.lng +
        '" target="_blank" rel="noopener">خرائط جوجل ←</a></small></div>');

    // الوجهة + خط متقطّع من آخر موقع ليها (تقديري — خط مستقيم مش طريق حقيقي)
    if (DEST) {
      L.circleMarker([DEST.lat, DEST.lng], {
        radius: 8, color: '#fff', weight: 2, fillColor: '#C9A227', fillOpacity: 1
      }).addTo(layer).bindPopup('<div class="trk-pop"><b>🏁 ' + esc(DEST.name) +
        '</b><small>وجهة الشحنة</small></div>');
      L.polyline([[last.lat, last.lng], [DEST.lat, DEST.lng]], {
        color: '#C9A227', weight: 2, opacity: .75, dashArray: '7,7'
      }).addTo(layer);
      pts = pts.concat([[DEST.lat, DEST.lng]]);
    }

    if (fit) {
      if (pts.length === 1) { map.setView(pts[0], 14); }
      else { map.fitBounds(L.latLngBounds(pts).pad(0.15)); }
    }
  }

  var initial = <?= js_json(array_map(fn($p) => [
        'lat'  => (float) $p['lat'],
        'lng'  => (float) $p['lng'],
        'addr' => $p['address'] ?: '',
        'at'   => dtfmt($p['created_at'], 'H:i'),
        'date' => dtfmt($p['created_at'], 'd/m'),
      ], $pings)) ?>;

  draw(initial, true);

  // السائق شغّال دلوقتي؟ نحدّث الخريطة كل 20 ثانية من غير ما نعيد تحميل الصفحة
  if (LIVE) {
    var n = initial.length;
    setInterval(function(){
      fetch(FEED, {headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(function(r){ return r.json(); })
        .then(function(j){
          if (j.ok && j.count !== n) {
            n = j.count;
            draw(j.pings.map(function(p){
              return {lat:p.lat, lng:p.lng, addr:p.addr, at:p.at,
                      date:p.date.slice(8,10) + '/' + p.date.slice(5,7)};
            }), false);
          }
        }).catch(function(){ var el=document.getElementById('trackLive1'); if(el){ el.classList.add('stale'); el.textContent='⚠ التحديث التلقائي واقف — حدّث الصفحة'; } });
    }, 20000);
  }
})();
</script>
<?php endif; ?>

<script>
document.querySelectorAll('.js-copy').forEach(function(b){
  b.addEventListener('click', function(){
    var txt = b.dataset.copy, done = function(){
      var o = b.innerHTML; b.innerHTML = '✓ اتنسخ'; setTimeout(function(){ b.innerHTML = o; }, 1600);
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(txt).then(done).catch(function(){ window.AppUI.ask('انسخ النص التالي:',function(){},{defaultValue:txt,readOnly:true,required:false,title:'نسخ'}); });
    } else {
      var ta = document.createElement('textarea');
      ta.value = txt; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); done(); } catch(e) { window.AppUI.ask('انسخ النص التالي:',function(){},{defaultValue:txt,readOnly:true,required:false,title:'نسخ'}); }
      document.body.removeChild(ta);
    }
  });
});
</script>
