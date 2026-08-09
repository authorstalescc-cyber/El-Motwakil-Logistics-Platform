<?php
/**
 * وسوم «التطبيق المثبَّت» (PWA) — ملف واحد بيخدم **نظام المكتب وبوابة المناديب** مع بعض.
 *
 *   <?php $pwaApp = 'office'; require __DIR__ . '/_pwa_head.php'; ?>
 *   $pwaApp = 'office' (الافتراضي)  |  'rep'
 *
 * بيطبع: رابط الـmanifest + أيقونات + وسوم آبل + صور شاشة البدء للآيفون،
 * وبيسجّل الـService Worker، وبيمسك حدث التثبيت، وبيرسم **شاشة البدء** عند فتح
 * التطبيق المثبَّت.
 *
 * ⚠ ثلاث نقاط لو اتكسرت واحدة فيهم الميزة بتفشل بالسكوت:
 *  ١) لازم يتضمّن جوّه <head> **قبل** أي CSS تقيل — شاشة البدء بتترسم من سكربت
 *     في الرأس عشان تظهر من أول رسمة، ولو اتأخّرت المستخدم هيشوف ومضة بيضا.
 *  ٢) السكربت بيلزق العنصر في `documentElement` مش `body` — لأن `body` لسه
 *     ما اتفكّش وقت تنفيذ الرأس. `position:fixed` بيشتغل عادي كابن لـ<html>.
 *  ٣) شاشة البدء دي **لازم تفضل مطابقة** لصور `assets/img/pwa/splash/*`
 *     اللي بيولّدها `bin/make_pwa_assets.py` (نفس التدرّج الكحلي + نفس الكارت
 *     الأبيض)، وإلا المستخدم هيشوف شاشتين مختلفتين ورا بعض عند الفتح.
 *
 * بيظهر **بس** لما التطبيق يكون متفتّح مثبَّتًا (standalone) و**مرّة واحدة كل
 * تشغيل** (`sessionStorage`) — مش مع كل صفحة، وإلا يبقى إزعاج مش لمسة.
 */

$__pwa_app   = (($pwaApp ?? 'office') === 'rep') ? 'rep' : 'office';
$__pwa_man   = $__pwa_app === 'rep' ? 'manifest.webmanifest' : 'manifest-office.webmanifest';
$__pwa_theme = $__pwa_app === 'rep' ? '#0F151E' : '#FFFFFF';
$__pwa_bar   = $__pwa_app === 'rep' ? 'black-translucent' : 'default';
$__pwa_title = 'المتوكل';

/* ⚠️ **أيقونتان مختلفتان — والفرق مقصود** (قرار المالك 2026-07-27):
   المالك بيثبّت التطبيقين على نفس الموبايل، فلو ليهم نفس الأيقونة بيبقى
   مستحيل يفرّق بينهم من شاشة الهاتف.

     • المكتب  (`/`)  → `pwa/office/` — خلفية **زرقاء** + علامة الشاحنة البيضا
     • المندوب (`/m`) → `pwa/`        — لوجو الشركة على كارت فاتح

   لازم يتزامن مع `icons` في الـmanifest المقابل — الرابط هنا للآيفون
   (`apple-touch-icon`) وللمتصفح، والـmanifest لأندرويد. لو اتغيّر واحد
   بس، الأيقونة تختلف بين الجهازين **بلا أي خطأ ظاهر**.
   المولّد: `python3 bin/make_pwa_assets.py`. */
$__pwa_ico = $__pwa_app === 'rep' ? 'assets/img/pwa/' : 'assets/img/pwa/office/';

/* مقاسات شاشة البدء للآيفون/الآيباد: [عرض CSS, ارتفاع CSS, كثافة البكسل].
   اسم الصورة بيتحسب من الضرب، فمستحيل يختلف عن اللي بيولّده السكربت.
   الجهاز اللي مالوش مقاس مطابق بيقع على `background_color` وبعدها شاشة الـHTML. */
$__pwa_splash = [
    [440, 956, 3], [430, 932, 3], [428, 926, 3], [402, 874, 3], [393, 852, 3],
    [390, 844, 3], [375, 812, 3], [414, 896, 3], [414, 736, 3], [414, 896, 2],
    [375, 667, 2], [320, 568, 2],
    [1024, 1366, 2], [834, 1194, 2], [834, 1112, 2], [820, 1180, 2], [810, 1080, 2], [768, 1024, 2],
];
?>
<?php
/* ⚠️ **بصمة النسخة لازم تترسم في الصفحة نفسها** — هي الأساس اللي التحديث
   الإجباري بيقارن عليه. لو الأساس اتاخد من أول رد ping، اللي فتح الصفحة
   **قبل** الرفع وأول نداء له جه **بعده** كان هياخد النسخة الجديدة أساسًا
   ويفضل شغّال على صفحة قديمة للأبد. والملف بيتحمّل من هنا عشان يوصل التلات
   مداخل بسطر واحد: تخطيط المكتب · شاشة الدخول · بوابة المناديب (اللي **ما
   بتحمّلش `app.js`** — نفس مصيدة `resume-scroll.js`). */
$__updJs = \App\Core\App::basePath('public/assets/js/app-update.js');
?>
<script>window.__almBuild=<?= js_json(\App\Core\App::buildId()) ?>;window.__almBuildUrl=<?= js_json(url('build')) ?>;</script>
<script defer src="<?= url('assets/js/app-update.js') ?>?v=<?= is_file($__updJs) ? filemtime($__updJs) : 1 ?>"></script>
<link rel="manifest" href="<?= url($__pwa_man) ?>">
<meta name="theme-color" content="<?= $__pwa_theme ?>">
<link rel="apple-touch-icon" sizes="180x180" href="<?= url($__pwa_ico . 'apple-touch-icon.png') ?>">
<link rel="icon" type="image/png" sizes="192x192" href="<?= url($__pwa_ico . 'icon-192.png') ?>">
<link rel="icon" type="image/png" sizes="512x512" href="<?= url($__pwa_ico . 'icon-512.png') ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="<?= $__pwa_bar ?>">
<meta name="apple-mobile-web-app-title" content="<?= $__pwa_title ?>">
<meta name="application-name" content="<?= $__pwa_title ?>">
<?php foreach ($__pwa_splash as [$__w, $__h, $__d]): ?>
<link rel="apple-touch-startup-image"
      media="(device-width: <?= $__w ?>px) and (device-height: <?= $__h ?>px) and (-webkit-device-pixel-ratio: <?= $__d ?>) and (orientation: portrait)"
      href="<?= url('assets/img/pwa/splash/apple-splash-' . ($__w * $__d) . 'x' . ($__h * $__d) . '.png') ?>">
<?php endforeach; ?>
<style>
/* ⚠ الشاشة دي بتترسم قبل أي CSS للنظام (وعلى `/m` بيشتغل ملف تنسيق تاني
   خالص)، فلازم تكون مكتفية بنفسها — من غير السطر ده الكارت بياخد عرضه
   + الحشو فيطلع أعرض من الشاشة وينزاح على جنب. */
#pwaSplash, #pwaSplash *{box-sizing:border-box}
#pwaSplash{
  position:fixed; inset:0; z-index:2147483000; margin:0;
  display:flex; flex-direction:column; align-items:center; justify-content:center;
  background:linear-gradient(180deg,#15355B 0%,#0B1E38 100%);
  opacity:1; transition:opacity .42s ease;
  -webkit-user-select:none; user-select:none; overflow:hidden;
}
/* الهالة الذهبية بتتنفّس ببطء — بتدّي إحساس إن الشاشة حيّة مش صورة واقفة */
#pwaSplash::before{
  content:''; position:absolute; inset:0; pointer-events:none;
  background:radial-gradient(58% 34% at 50% 30%, rgba(201,162,39,.17), rgba(201,162,39,0) 72%);
  animation:pwaBreathe 3.6s ease-in-out infinite alternate;
}
/* الخروج: الشاشة بتبهت والكارت بيكبر شوية — إحساس إنك «داخل» جوّه النظام
   مش إن حاجة اتشالت من قدّامك. */
#pwaSplash.is-out{opacity:0; pointer-events:none}
#pwaSplash.is-out .pwa-stage{transform:scale(1.1) translateY(-6px)}

/* ⚠ الحركة متقسّمة على عنصرين عن قصد: `.pwa-stage` مسؤول عن **الخروج**،
   و`.pwa-card` عن **الدخول والطفو**. لو الاتنين على عنصر واحد، الـtransition
   بتاع الخروج بيتصارع مع الـanimation المستمرة وبيبوظ الاتنين. */
#pwaSplash .pwa-stage{
  position:relative; transition:transform .5s cubic-bezier(.4,0,.2,1);
}
/* حلقة ذهبية بتتوسّع مرّة واحدة من ورا الكارت */
#pwaSplash .pwa-stage::before{
  content:''; position:absolute; inset:-3%; pointer-events:none;
  border:1px solid rgba(201,162,39,.55); border-radius:min(10vmin,52px);
  animation:pwaRing 1.15s .42s cubic-bezier(.2,.7,.3,1) both;
}
#pwaSplash .pwa-card{
  position:relative; width:min(66vmin,330px); padding:min(4.4vmin,22px) min(5vmin,26px);
  border-radius:min(9vmin,44px); background:linear-gradient(180deg,var(--card) 0%,var(--tint-navy) 100%);
  box-shadow:0 18px 46px rgba(0,0,0,.42), 0 2px 0 rgba(255,255,255,.5) inset;
  display:flex; align-items:center; justify-content:center; overflow:hidden;
  animation:pwaPop .68s cubic-bezier(.22,.9,.28,1.2) both,
            pwaFloat 3.8s .8s ease-in-out infinite;
}
/* لمعة زجاجية بتعدّي على الكارت مرّة (من اليمين للشمال — اتجاه القراءة) */
#pwaSplash .pwa-card::after{
  content:''; position:absolute; inset:0; pointer-events:none;
  background:linear-gradient(255deg, rgba(255,255,255,0) 34%, rgba(255,255,255,.72) 48%, rgba(255,255,255,0) 62%);
  transform:translateX(115%);
  animation:pwaShine 1.05s .58s cubic-bezier(.35,0,.25,1) both;
}
#pwaSplash .pwa-card img{
  display:block; width:100%; height:auto; position:relative;
  animation:pwaLogo .8s .18s cubic-bezier(.2,.8,.3,1) both;
}
/* ── الطريق والعربية ─────────────────────────────────────────────────────
   مؤشّر الانتظار بقى **عربية نقل واقفة في النص والطريق بيجري تحتها** (زي
   السير المتحرّك)، مش عربية بتعدّي وتختفي — كده تفضل ظاهرة طول الانتظار
   وتقول «نقل» من أول لحظة. الحركة كلها CSS بلا أي صورة زيادة تتحمّل. */
#pwaSplash .pwa-road{
  position:relative; margin-top:min(6vmin,30px); width:min(54vmin,250px); height:min(11vmin,52px);
  animation:pwaRise .6s .34s cubic-bezier(.2,.8,.3,1) both;
}
/* خط الأسفلت */
#pwaSplash .pwa-road::before{
  content:''; position:absolute; inset-inline:0; inset-block-end:0; height:2px; border-radius:99px;
  background:rgba(255,255,255,.18);
}
/* الشرَط البيضا اللي بتجري — هي اللي بتدّي إحساس السرعة */
#pwaSplash .pwa-road::after{
  content:''; position:absolute; inset-inline:0; inset-block-end:0; height:2px;
  background:repeating-linear-gradient(90deg, rgba(201,162,39,.95) 0 16px, rgba(201,162,39,0) 16px 40px);
  animation:pwaAsphalt .55s linear infinite;
  -webkit-mask-image:linear-gradient(90deg, transparent, #000 18%, #000 82%, transparent);
  mask-image:linear-gradient(90deg, transparent, #000 18%, #000 82%, transparent);
}
/* العربية متوسّطة بالـflex مش بـ`translate` — الخاصية دي مش مدعومة على
   المتصفحات الأقدم، وكانت هتسيب العربية منزاحة على جنب بلا أي خطأ. */
#pwaSplash .pwa-truck{
  position:absolute; inset:0; display:flex; align-items:flex-end; justify-content:center;
  padding-block-end:1px;
}
#pwaSplash .pwa-truck svg{
  display:block; width:min(24vmin,112px); height:auto;
  transform:scaleX(-1);                       /* الكابينة تبقى ناحية اليسار = اتجاه السير */
  animation:pwaBump .34s ease-in-out infinite alternate;
}
#pwaSplash .pwa-truck .wh{animation:pwaWheel .5s linear infinite; transform-origin:center; transform-box:fill-box}
/* خطوط سرعة ورا العربية */
#pwaSplash .pwa-truck .sp{animation:pwaSpeed .5s linear infinite}
#pwaSplash .pwa-truck .sp2{animation-delay:.16s}
#pwaSplash .pwa-sub{
  margin-top:min(4vmin,18px); color:rgba(243,233,198,.82); font-size:var(--fs-2); font-weight:500;
  letter-spacing:.2px; text-align:center;
  /* ⚠️ مكتوب صراحةً مش `var(--font)`: شاشة البدء بتترسم من سكربت في `<head>`
     على `documentElement` **قبل** ما ورقة الأنماط تتطبّق، فالتوكن لسه فاضي. */
  font-family:'Cairo',system-ui,-apple-system,Tahoma,Arial,sans-serif;
  animation:pwaRise .7s .46s cubic-bezier(.2,.8,.3,1) both;
}
@keyframes pwaPop{from{opacity:0; transform:translateY(22px) scale(.88)} to{opacity:1; transform:none}}
@keyframes pwaFloat{0%,100%{transform:translateY(0)} 50%{transform:translateY(-5px)}}
@keyframes pwaLogo{from{opacity:0; transform:scale(1.14)} to{opacity:1; transform:none}}
@keyframes pwaShine{from{transform:translateX(115%)} to{transform:translateX(-115%)}}
@keyframes pwaRing{
  0%{opacity:0; transform:scale(.86)}
  35%{opacity:.85}
  100%{opacity:0; transform:scale(1.24)}
}
@keyframes pwaRise{from{opacity:0; transform:translateY(10px)} to{opacity:1; transform:none}}
@keyframes pwaBreathe{from{opacity:.55; transform:scale(.94)} to{opacity:1; transform:scale(1.06)}}
@keyframes pwaAsphalt{from{background-position-x:0} to{background-position-x:-40px}}
@keyframes pwaWheel{from{transform:rotate(0)} to{transform:rotate(360deg)}}
@keyframes pwaBump{from{transform:scaleX(-1) translateY(0)} to{transform:scaleX(-1) translateY(-1.5px)}}
@keyframes pwaSpeed{0%{opacity:0; transform:translateX(0)} 40%{opacity:.9} 100%{opacity:0; transform:translateX(9px)}}
/* «تقليل الحركة»: تظهر بتلاشي بسيط والعربية واقفة — بلا أي حركة ولا لمعة */
@media (prefers-reduced-motion:reduce){
  #pwaSplash::before,
  #pwaSplash .pwa-stage::before,
  #pwaSplash .pwa-card,
  #pwaSplash .pwa-card::after,
  #pwaSplash .pwa-card img,
  #pwaSplash .pwa-road,
  #pwaSplash .pwa-road::after,
  #pwaSplash .pwa-truck svg,
  #pwaSplash .pwa-truck .wh,
  #pwaSplash .pwa-truck .sp,
  #pwaSplash .pwa-sub{animation:none}
  #pwaSplash .pwa-stage::before,#pwaSplash .pwa-card::after,#pwaSplash .pwa-truck .sp{display:none}
  #pwaSplash.is-out .pwa-stage{transform:none}
}
</style>
<script>
(function () {
  'use strict';
  var APP = <?= js_json($__pwa_app) ?>;

  /* ١) الـService Worker — بيخلّي التطبيق يفتح أسرع وبصفحة «مفيش نت» لطيفة.
        ⚠ ممنوع يكاش أي HTML (تفاصيل القاعدة في public/sw.js). */
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(<?= js_json(url('sw.js')) ?>, { scope: '/' }).catch(function () {});
    });
  }

  /* ══════════════════════════════════════════════════════════════════════
     ١ب) إشعارات التطبيق (Web Push) — واجهة واحدة للمكتب والمندوب.

     `window.almPush.state()/enable()/disable()` — الشاشات بتناديها وبس،
     فمنطق الاشتراك مكتوب مرّة واحدة هنا مش في كل شاشة.

     ⚠ **الإذن لازم يتطلب بضغطة من المستخدم.** طلبه وقت التحميل بترفضه
        المتصفحات وبتحرق النطاق كله (يبقى مستحيل تطلبه تاني على نفس المتصفح).
     ⚠ **على الآيفون الإشعارات بتشتغل بس لو التطبيق متثبَّت** على الشاشة
        الرئيسية — بنقول للمستخدم كده بالنص بدل ما يدوس ومايحصلش حاجة.
     ══════════════════════════════════════════════════════════════════════ */
  var PUSH_KEY = <?= js_json(\App\Core\WebPush::publicKey()) ?>;
  var CSRF     = <?= js_json(\App\Core\Auth::csrf()) ?>;
  var U_SUB    = <?= js_json(url('push/subscribe')) ?>;
  var U_UNSUB  = <?= js_json(url('push/unsubscribe')) ?>;

  function pushSupported() {
    return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  }
  function isIosDevice() {
    var ua = navigator.userAgent || '';
    return /iPad|iPhone|iPod/.test(ua) || (ua.indexOf('Mac') > -1 && 'ontouchend' in document);
  }
  function keyBytes(b64) {
    var pad = '='.repeat((4 - b64.length % 4) % 4);
    var raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
    var out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) { out[i] = raw.charCodeAt(i); }
    return out;
  }
  function post(url, fields) {
    var body = new URLSearchParams();
    body.set('_csrf', CSRF);
    Object.keys(fields).forEach(function (k) { body.set(k, fields[k]); });
    return fetch(url, {
      method: 'POST', credentials: 'same-origin', body: body,
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }
    }).then(function (r) { return r.json().catch(function () { return { ok: false }; }); });
  }
  function subKeys(sub) {
    var j = sub.toJSON ? sub.toJSON() : sub;
    return { endpoint: sub.endpoint, p256dh: (j.keys || {}).p256dh || '', auth: (j.keys || {}).auth || '' };
  }
  function describe(subscribed) {
    if (!pushSupported()) { return { supported: false, subscribed: false, canEnable: false,
      message: 'المتصفح ده ما بيدعمش إشعارات التطبيق.' }; }
    if (Notification.permission === 'denied') {
      return { supported: true, subscribed: false, canEnable: false,
        message: 'الإشعارات متمنوعة من إعدادات المتصفح على الجهاز ده — افتحها من إعدادات الموقع.' };
    }
    if (subscribed) {
      return { supported: true, subscribed: true, canEnable: false, message: '✓ الإشعارات شغّالة على الجهاز ده.' };
    }
    if (isIosDevice() && !standalone()) {
      return { supported: true, subscribed: false, canEnable: false,
        message: 'على الآيفون لازم تثبّت التطبيق على الشاشة الرئيسية الأول، وبعدين تفعّل الإشعارات من جوّاه.' };
    }
    return { supported: true, subscribed: false, canEnable: true,
      message: 'الإشعارات مقفولة على الجهاز ده.' };
  }

  window.almPush = {
    supported: pushSupported,
    state: function () {
      if (!pushSupported() || !PUSH_KEY) { return Promise.resolve(describe(false)); }
      return navigator.serviceWorker.ready
        .then(function (reg) { return reg.pushManager.getSubscription(); })
        .then(function (sub) { return describe(!!sub && Notification.permission === 'granted'); })
        .catch(function () { return describe(false); });
    },
    enable: function () {
      if (!pushSupported() || !PUSH_KEY) { return Promise.resolve(describe(false)); }
      return Notification.requestPermission().then(function (perm) {
        if (perm !== 'granted') { return describe(false); }
        return navigator.serviceWorker.ready.then(function (reg) {
          return reg.pushManager.getSubscription().then(function (existing) {
            return existing || reg.pushManager.subscribe({
              userVisibleOnly: true, applicationServerKey: keyBytes(PUSH_KEY)
            });
          });
        }).then(function (sub) {
          return post(U_SUB, subKeys(sub)).then(function (res) {
            if (!res.ok) { return { supported: true, subscribed: false, canEnable: true,
              message: res.msg || 'تعذّر تفعيل الإشعارات — جرّب تاني.' }; }
            return describe(true);
          });
        });
      }).catch(function () {
        return { supported: true, subscribed: false, canEnable: true,
          message: 'تعذّر تفعيل الإشعارات على الجهاز ده.' };
      });
    },
    disable: function () {
      if (!pushSupported()) { return Promise.resolve(describe(false)); }
      return navigator.serviceWorker.ready
        .then(function (reg) { return reg.pushManager.getSubscription(); })
        .then(function (sub) {
          if (!sub) { return describe(false); }
          var ep = sub.endpoint;
          return sub.unsubscribe().then(function () { return post(U_UNSUB, { endpoint: ep }); })
                    .then(function () { return describe(false); });
        })
        .catch(function () { return describe(false); });
    }
  };

  /* ٢) حدث «التثبيت» — بنمسكه بدري ونخزّنه، والواجهة تعرض الزرار لما يتاح. */
  window.__almInstall = null;
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    window.__almInstall = e;
    window.dispatchEvent(new CustomEvent('alm-installable'));
  });
  window.addEventListener('appinstalled', function () {
    window.__almInstall = null;
    window.dispatchEvent(new CustomEvent('alm-installed'));
  });

  /* ٣) زرار «ثبّت التطبيق» في شريط المكتب — بيظهر بس لو التثبيت متاح فعلاً.
        آيفون/سفاري مالوش حدث تثبيت، فبنوريه خطوات الإضافة للشاشة الرئيسية. */
  function wireInstallButton() {
    var btn = document.getElementById('pwaInstallBtn');
    if (!btn) { return; }
    var ua = navigator.userAgent || '';
    var isIos = /iPad|iPhone|iPod/.test(ua) || (ua.indexOf('Mac') > -1 && 'ontouchend' in document);

    function refresh() {
      if (standalone()) { btn.hidden = true; return; }        // مثبَّت خلاص
      btn.hidden = !(window.__almInstall || isIos);
    }
    window.addEventListener('alm-installable', refresh);
    window.addEventListener('alm-installed', refresh);
    refresh();

    btn.addEventListener('click', function () {
      var d = window.__almInstall;
      if (d) {
        d.prompt();
        d.userChoice.finally(function () { window.__almInstall = null; refresh(); });
        return;
      }
      var steps = 'من المتصفح: اضغط زرار المشاركة (المربّع والسهم لفوق) ← '
                + '«إضافة إلى الشاشة الرئيسية» ← «إضافة». هتلاقي التطبيق بلوجو الشركة على شاشة موبايلك.';
      if (window.AppUI && typeof window.AppUI.notice === 'function') {
        window.AppUI.notice(steps, 'ثبّت التطبيق على موبايلك');
      } else { alert(steps); }
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', wireInstallButton);
  } else { wireInstallButton(); }

  /* ٤) شاشة البدء — للتطبيق المثبَّت بس، ومرّة واحدة كل تشغيل. */
  function standalone() {
    try {
      if (navigator.standalone === true) { return true; }
      if (!window.matchMedia) { return false; }
      return matchMedia('(display-mode: standalone)').matches
          || matchMedia('(display-mode: fullscreen)').matches
          || matchMedia('(display-mode: minimal-ui)').matches;
    } catch (e) { return false; }
  }
  window.__almStandalone = standalone;

  var KEY = 'alm:splash:' + APP, seen = false;
  try { seen = sessionStorage.getItem(KEY) === '1'; } catch (e) {}
  if (seen || !standalone()) { return; }
  try { sessionStorage.setItem(KEY, '1'); } catch (e) {}

  var el = document.createElement('div');
  el.id = 'pwaSplash';
  el.setAttribute('role', 'presentation');
  el.setAttribute('aria-hidden', 'true');
  var stage = document.createElement('div'); stage.className = 'pwa-stage';
  var card = document.createElement('div'); card.className = 'pwa-card';
  var img = document.createElement('img');
  img.src = <?= js_json(url('assets/img/logo.png')) ?>; img.alt = ''; img.decoding = 'async';
  card.appendChild(img); stage.appendChild(card);

  /* الطريق والعربية. الماركب ثابت في الكود (مفيش أي قيمة من المستخدم) فآمن
     على `innerHTML`، والـSVG مرسوم بخط ذهبي بنفس هوية النظام. */
  var road = document.createElement('div'); road.className = 'pwa-road';
  road.innerHTML =
    '<span class="pwa-truck"><svg viewBox="0 0 108 44" aria-hidden="true" fill="none" stroke="#C9A227"'
  + ' stroke-width="3" stroke-linecap="round" stroke-linejoin="round">'
  + '<path d="M14 8h46v26H14z"/>'                                  /* الصندوق */
  + '<path d="M60 16h14l12 12v6H60z"/>'                            /* الكابينة */
  + '<circle cx="30" cy="36" r="6.5" fill="#0B1E38" class="wh"/>'
  + '<circle cx="78" cy="36" r="6.5" fill="#0B1E38" class="wh"/>'
  + '<path d="M30 33.5v5M27.5 36h5" stroke-width="2" class="wh"/>' /* شعاع بيبيّن الدوران */
  + '<path d="M78 33.5v5M75.5 36h5" stroke-width="2" class="wh"/>'
  + '<path class="sp" d="M4 14h8" stroke-width="2.4" opacity="0"/>'
  + '<path class="sp sp2" d="M2 24h6" stroke-width="2.4" opacity="0"/>'
  + '</svg></span>';

  var sub = document.createElement('div'); sub.className = 'pwa-sub';
  sub.textContent = <?= js_json($__pwa_app === 'rep' ? 'شغل المندوب' : 'إدارة النقل والتخليص الجمركي') ?>;
  el.appendChild(stage); el.appendChild(road); el.appendChild(sub);
  // ⚠ body لسه ما اتفكّش هنا — بنلزق في <html> عشان الشاشة تظهر من أول رسمة.
  (document.body || document.documentElement).appendChild(el);

  /* أقل مدة عرض: 1.5 ثانية — قد الحركة بالظبط (الكارت + اللمعة + الحلقة)
     عشان الشاشة ما تختفيش في نصّها لو النظام حمّل بسرعة. */
  var born = new Date().getTime(), MIN = 1500, done = false;
  function hide() {
    if (done) { return; }
    done = true;
    var wait = Math.max(0, MIN - (new Date().getTime() - born));
    setTimeout(function () {
      el.className = 'is-out';
      setTimeout(function () { if (el.parentNode) { el.parentNode.removeChild(el); } }, 520);
    }, wait);
  }
  if (document.readyState === 'complete') { hide(); }
  else { window.addEventListener('load', hide); }
  setTimeout(hide, 6000);   // حزام أمان: عمرها ما تفضل عالقة لو حصل أي خطأ
})();
</script>
