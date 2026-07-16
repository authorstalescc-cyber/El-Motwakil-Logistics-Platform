<?php
/**
 * صفحة السائق العامة (/t/{token}) — تتفتح على موبايله من رابط الواتساب.
 *
 * ⚠️ قواعد الصفحة دي:
 *   - بلا layout: مفيش قائمة نظام ولا تسجيل دخول — السائق مالوش حساب.
 *   - **ممنوع عرض أي مبلغ** (نولون/عهدة/دفعة/دين). بيانات وصفية بس.
 *   - كل CSS/JS جوّه الملف: الصفحة لازم تفتح بسرعة على نت ضعيف.
 *   - محتاجة HTTPS عشان المتصفح يدي الموقع (رابط النفق https — تمام).
 */
$driver = trim((string) ($t['driver'] ?? ''));
$dest   = trim((string) ($bill['destination'] ?? ''));
$billNo = trim((string) ($bill['bill_number'] ?? ''));
$cont   = trim((string) ($bill['container_no'] ?? ''));
$car    = trim((string) ($t['car_no'] ?? ''));
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#15355B">
<title>ابعت موقعك — <?= e($company) ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='20' fill='%2315355B'/><text x='50' y='68' font-size='54' text-anchor='middle' fill='%23C9A227' font-family='Arial' font-weight='bold'>م</text></svg>">
<style>
*{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
body{font-family:system-ui,-apple-system,"Segoe UI",Tahoma,Arial,sans-serif;background:#0f2340;color:#123;
     min-height:100dvh;display:flex;flex-direction:column;align-items:center;padding:16px;
     padding-bottom:calc(16px + env(safe-area-inset-bottom))}
.card{background:#fff;border-radius:22px;width:100%;max-width:460px;padding:22px;
      box-shadow:0 18px 50px rgba(0,0,0,.35);display:flex;flex-direction:column;gap:16px}
.head{text-align:center;border-bottom:2px solid #eef1f6;padding-bottom:14px}
.head .logo{width:52px;height:52px;border-radius:14px;background:#15355B;color:#C9A227;font-weight:900;
            font-size:1.7rem;display:flex;align-items:center;justify-content:center;margin:0 auto 8px}
.head h1{font-size:1.15rem;color:#15355B;font-weight:900}
.head p{font-size:.85rem;color:#6b7a90;margin-top:3px}
.hi{font-size:1.05rem;font-weight:800;color:#15355B;text-align:center}
.info{background:#f6f8fb;border:1px solid #e4e9f1;border-radius:14px;padding:12px 14px;display:grid;gap:8px}
.info .row{display:flex;justify-content:space-between;gap:10px;font-size:.9rem}
.info .row b{color:#15355B;font-weight:800;text-align:end;word-break:break-word}
.info .row span{color:#6b7a90;flex-shrink:0}
.btn-main{border:0;border-radius:18px;background:linear-gradient(160deg,#1e9e5a,#127a45);
   color:#fff;font-size:1.35rem;font-weight:900;padding:26px 16px;width:100%;cursor:pointer;
   box-shadow:0 10px 26px rgba(18,122,69,.4);transition:transform .1s,filter .15s;
   display:flex;flex-direction:column;align-items:center;gap:6px;font-family:inherit}
.btn-main:active{transform:scale(.97)}
.btn-main:disabled{filter:grayscale(.7);opacity:.65;cursor:default;box-shadow:none}
.btn-main .pin{font-size:2rem;line-height:1}
.btn-main small{font-size:.78rem;font-weight:700;opacity:.9}
.live{border:2px solid #e4e9f1;border-radius:16px;padding:14px;display:flex;align-items:center;
      justify-content:space-between;gap:12px;background:#fbfcfe}
.live.on{border-color:#1e9e5a;background:#f0fbf5}
.live .lt{font-size:.92rem;font-weight:800;color:#15355B}
.live .ls{font-size:.75rem;color:#6b7a90;margin-top:3px;line-height:1.5}
.sw{position:relative;width:56px;height:32px;flex-shrink:0;border:0;border-radius:99px;background:#c9d2df;
    cursor:pointer;transition:background .2s;padding:0}
.sw::after{content:"";position:absolute;top:3px;right:3px;width:26px;height:26px;border-radius:50%;
    background:#fff;transition:transform .2s;box-shadow:0 2px 5px rgba(0,0,0,.2)}
.sw.on{background:#1e9e5a}
.sw.on::after{transform:translateX(-24px)}
.msg{border-radius:14px;padding:14px;font-size:.92rem;font-weight:700;line-height:1.6;display:none}
.msg.show{display:block}
.msg.ok{background:#eaf9f0;color:#0f6b3d;border:1px solid #b6e7cb}
.msg.err{background:#fdeeee;color:#a12525;border:1px solid #f3c4c4}
.msg.wait{background:#fff8e6;color:#8a6100;border:1px solid #f2dfa8}
.msg .addr{display:block;font-weight:600;font-size:.82rem;margin-top:6px;opacity:.85}
.hist{font-size:.8rem;color:#6b7a90;text-align:center;line-height:1.7}
.hist b{color:#15355B}
.foot{text-align:center;font-size:.72rem;color:#8fa0b6;line-height:1.7}
.pulse{display:inline-block;width:9px;height:9px;border-radius:50%;background:#1e9e5a;margin-inline-end:6px;
       animation:pl 1.4s infinite}
@keyframes pl{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.35;transform:scale(.75)}}
@media(max-width:380px){.card{padding:16px}.btn-main{font-size:1.2rem;padding:22px 12px}}
</style>
</head>
<body>
<div class="card">
  <div class="head">
    <div class="logo">م</div>
    <h1><?= e($company) ?></h1>
    <p>تتبع الشحنة</p>
  </div>

  <?php if ($driver !== ''): ?>
    <div class="hi">أهلاً يا <?= e($driver) ?> 👋</div>
  <?php endif; ?>

  <div class="info">
    <?php if ($billNo !== ''): ?>
      <div class="row"><span>البوليصة</span><b><?= e($billNo) ?></b></div>
    <?php endif; ?>
    <?php if ($car !== ''): ?>
      <div class="row"><span>السيارة</span><b><?= e($car) ?></b></div>
    <?php endif; ?>
    <?php if ($cont !== ''): ?>
      <div class="row"><span>الحاوية</span><b><?= e($cont) ?></b></div>
    <?php endif; ?>
    <?php if ($dest !== ''): ?>
      <div class="row"><span>الوجهة</span><b><?= e($dest) ?></b></div>
    <?php endif; ?>
    <?php if (!empty($t['note'])): ?>
      <div class="row"><span>ملاحظة</span><b><?= e($t['note']) ?></b></div>
    <?php endif; ?>
  </div>

  <button type="button" class="btn-main" id="send">
    <span class="pin">📍</span>
    ابعت موقعي
    <small>اضغط الزرار ووافق على طلب الموقع</small>
  </button>

  <div class="msg" id="msg"></div>

  <div class="live" id="liveBox">
    <div>
      <div class="lt">🛰️ تتبع مباشر</div>
      <div class="ls" id="liveTxt">شغّله وسيب الصفحة مفتوحة، فموقعك هيتبعت لوحده كل <?= (int) $liveSecs ?> ثانية.</div>
    </div>
    <button type="button" class="sw" id="sw" aria-label="تشغيل التتبع المباشر"></button>
  </div>

  <?php if ($last): ?>
    <div class="hist" id="hist">
      آخر موقع بعتّه: <b><?= e(date('d/m/Y — H:i', strtotime($last['created_at']))) ?></b>
      <?php if (!empty($last['address'])): ?><br><?= e($last['address']) ?><?php endif; ?>
    </div>
  <?php else: ?>
    <div class="hist" id="hist">لسه مبعتش موقعك في الرحلة دي.</div>
  <?php endif; ?>

  <div class="foot">
    الرابط ده خاص بالشحنة دي بس، وبيسجّل مكانك وقت ما تضغط فقط.<br>
    لو فيه أي مشكلة كلّم المكتب.
  </div>
</div>

<script>
(function(){
  var POST = <?= json_encode($postUrl, JSON_UNESCAPED_SLASHES) ?>;
  var LIVE_MS = <?= (int) $liveSecs ?> * 1000;
  var btn = document.getElementById('send'), msg = document.getElementById('msg'),
      sw = document.getElementById('sw'), liveBox = document.getElementById('liveBox'),
      liveTxt = document.getElementById('liveTxt'), hist = document.getElementById('hist');
  var liveOn = false, watchId = null, timer = null, lastPos = null, busy = false;

  function show(kind, text, addr){
    msg.className = 'msg show ' + kind;
    msg.innerHTML = '';
    msg.appendChild(document.createTextNode(text));
    if (addr) {
      var s = document.createElement('span');
      s.className = 'addr'; s.textContent = addr;
      msg.appendChild(s);
    }
  }

  // رسائل بلغة السائق بدل أكواد المتصفح
  function geoErr(err){
    if (err && err.code === 1) {
      return 'المتصفح رفض يدّي الموقع. افتح إعدادات المتصفح واسمح بالموقع (Location) للصفحة دي، وبعدين حاول تاني.';
    }
    if (err && err.code === 2) { return 'مش لاقيين موقعك. اتأكد إن الـ GPS مفتوح واطلع لمكان مكشوف وحاول تاني.'; }
    if (err && err.code === 3) { return 'الموقع أخد وقت طويل. حاول تاني.'; }
    return 'حصلت مشكلة في تحديد الموقع. حاول تاني.';
  }

  function send(pos, source){
    if (busy) return;
    busy = true;
    var c = pos.coords;
    return fetch(POST, {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({
        lat: c.latitude, lng: c.longitude,
        accuracy: c.accuracy, speed: (c.speed == null ? '' : c.speed),
        source: source
      })
    })
    .then(function(r){ return r.json(); })
    .then(function(j){
      if (j.ok) {
        show('ok', '✅ ' + j.msg + ' الساعة ' + j.time, j.address || '');
        if (hist) hist.textContent = 'آخر موقع بعتّه: ' + j.time;
        return true;
      }
      // التتبع المباشر بيتجاهل رفض «استنى ثواني» بصمت — مش خطأ حقيقي
      if (source === 'live') return false;
      show('err', '⚠️ ' + (j.msg || 'حاول تاني.'));
      return false;
    })
    .catch(function(){
      show('err', '⚠️ النت ضعيف — الموقع مابعتش. حاول تاني.');
      return false;
    })
    .finally(function(){ busy = false; });
  }

  function locate(source){
    return new Promise(function(resolve){
      if (!navigator.geolocation) {
        show('err', 'الموبايل ده مش بيدعم تحديد الموقع من المتصفح. جرّب تفتح الرابط من كروم.');
        return resolve(null);
      }
      navigator.geolocation.getCurrentPosition(
        function(pos){ lastPos = pos; resolve(pos); },
        function(err){ if (source !== 'live') show('err', geoErr(err)); resolve(null); },
        {enableHighAccuracy: true, timeout: 20000, maximumAge: source === 'live' ? 15000 : 0}
      );
    });
  }

  btn.addEventListener('click', function(){
    btn.disabled = true;
    show('wait', '⏳ بنحدّد مكانك… سيب الصفحة مفتوحة لحظة.');
    locate('manual').then(function(pos){
      if (pos) return send(pos, 'manual');
    }).finally(function(){ btn.disabled = false; });
  });

  // ---- التتبع المباشر: watchPosition يخلّي الموقع دايمًا حديث، والمؤقّت يبعته كل X ثانية
  function startLive(){
    if (!navigator.geolocation) { show('err','الموبايل ده مش بيدعم تحديد الموقع.'); return; }
    liveOn = true;
    sw.classList.add('on'); liveBox.classList.add('on');
    liveTxt.innerHTML = '<span class="pulse"></span>شغّال — سيب الصفحة مفتوحة والشاشة مفتوحة قدر الإمكان.';
    watchId = navigator.geolocation.watchPosition(
      function(pos){ lastPos = pos; },
      function(err){ if (err.code === 1) { stopLive(); show('err', geoErr(err)); } },
      {enableHighAccuracy: true, timeout: 25000, maximumAge: 10000}
    );
    locate('live').then(function(pos){ if (pos && liveOn) send(pos, 'live'); });
    timer = setInterval(function(){ if (liveOn && lastPos) send(lastPos, 'live'); }, LIVE_MS);
  }
  function stopLive(){
    liveOn = false;
    sw.classList.remove('on'); liveBox.classList.remove('on');
    liveTxt.textContent = 'شغّله وسيب الصفحة مفتوحة، فموقعك هيتبعت لوحده كل <?= (int) $liveSecs ?> ثانية.';
    if (watchId !== null) { navigator.geolocation.clearWatch(watchId); watchId = null; }
    if (timer) { clearInterval(timer); timer = null; }
  }
  sw.addEventListener('click', function(){ liveOn ? stopLive() : startLive(); });

  // لو رجع للصفحة بعد ما قفل الشاشة والتتبع شغّال — نبعت فورًا بدل ما نستنى الدورة
  document.addEventListener('visibilitychange', function(){
    if (!document.hidden && liveOn && lastPos) send(lastPos, 'live');
  });
  window.addEventListener('beforeunload', function(){ if (liveOn) stopLive(); });
})();
</script>
</body>
</html>
