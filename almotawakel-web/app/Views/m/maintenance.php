<?php
/**
 * شاشة «النظام بيتظبّط» — بوابة المندوب مقفولة للصيانة (v68).
 *
 * ⚠️ **صفحة كاملة بلا layout** زي `m/login.php` — بوابة `/m` ما بتحمّلش
 *    `app.css` ولا `app.js` أصلاً، فأي حاجة مشتركة لازم تعيش في `m.css`.
 *
 * ⚠️ **الرسالة كلها هنا وفي نصّ الرنّة** — مفيش رابط «اعرف أكتر»، لأن كل
 *    روابط البوابة بتوصّل لنفس الشاشة دي طول ما القفل شغّال.
 *
 * ⚠️ الصفحة بتسأل `/m/ping` (المستثنى الوحيد من القفل) وبتفتح لوحدها أول ما
 *    الصيانة تخلص — من غير ما المندوب يعمل تحديث ولا يفتكر يدخل تاني.
 *
 * @var array  $state  مخرَج RepMaintenance::state()
 * @var string $backAt نص «متوقع يرجع الساعة …» أو ''
 */
$msg = trim((string) ($state['message'] ?? ''));

// تواصل المكتب — نفس مصدر الشاشة الرئيسية بالظبط
$officePhone = trim((string) \App\Core\App::setting('company_phone', ''));
$officeWa    = wa_phone($officePhone);
$officeTel   = preg_replace('/[^\d+]/', '', ar2en_digits($officePhone));
$repName     = (string) \App\Core\RepAuth::repName();
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0F151E">
<title>النظام بيتظبّط — المتوكل</title>
<link rel="icon" href="<?= url('assets/img/logo.png') ?>">
<link rel="stylesheet" href="<?= url('assets/css/m.css') ?>?v=<?= @filemtime(\App\Core\App::basePath('public/assets/css/m.css')) ?>">
<?php require __DIR__ . "/_pwa.php"; ?>
</head>
<body class="mt-body">

<main class="mt-wrap">
  <span class="mt-ic" aria-hidden="true"><?= icon('wrench', 34) ?></span>

  <h1>النظام بيتظبّط دلوقتي</h1>

  <?php /* تحذير وظيفي في ≤٦ كلمات (قانون التصميم ⑤) — مش شرح تعليمي */ ?>
  <p class="mt-lead">الشغل واقف شوية. بنعتذر منك.</p>

  <?php if ($msg !== ''): ?>
    <p class="mt-msg"><?= e($msg) ?></p>
  <?php endif; ?>

  <?php if ($backAt !== ''): ?>
    <div class="mt-when">
      <span class="ic"><?= icon('clock', 15) ?></span>
      <span>متوقع يرجع الساعة <b><bdi dir="ltr"><?= e($backAt) ?></bdi></b></span>
    </div>
  <?php endif; ?>

  <?php /* ⚠️ **الطمأنة دي مش زخرفة** — أكتر خوف عملي عند المندوب إنه يكون
           كاتب بند نص ويفتكر إنه ضاع، فيعيد كتابته من الأول لما يرجع
           (وده بيدّي بندين). القفل فعلاً ما بيلمسش الجلسة ولا المسودة. */ ?>
  <div class="mt-keep">
    <span class="ic"><?= icon('check-circle', 15) ?></span>
    <span>أي بند كنت كاتبه محفوظ على موبايلك — هتلاقيه مستنّيك.</span>
  </div>

  <div class="mt-live" id="mtLive" role="status">
    <span class="mt-dot" aria-hidden="true"></span>
    <span>بنشوف كل شوية… الصفحة هتفتح لوحدها</span>
  </div>

  <div class="mt-acts">
    <button type="button" class="mt-btn is-primary" id="mtRetry">جرّب دلوقتي</button>
    <?php if ($officeWa !== ''): ?>
      <a class="mt-btn" target="_blank" rel="noopener"
         href="https://wa.me/<?= e($officeWa) ?>?text=<?= rawurlencode('السلام عليكم، أنا ' . $repName . ' — النظام مقفول للصيانة، محتاج أعرف هيرجع إمتى.') ?>">
        واتساب المكتب</a>
    <?php elseif ($officeTel !== ''): ?>
      <a class="mt-btn" href="tel:<?= e($officeTel) ?>">اتصل بالمكتب</a>
    <?php endif; ?>
  </div>
</main>

<script>
(function () {
  'use strict';
  var live  = document.getElementById('mtLive');
  var retry = document.getElementById('mtRetry');
  var busy  = false;

  /* ⚠️ `/m/ping` هو **المستثنى الوحيد** من قفل الصيانة (شوف
     `RepPortalController::ping`) — عشان بالظبط اللحظة دي: الصفحة تعرف
     إن الصيانة خلصت وتفتح لوحدها بدل ما المندوب يفضل يعمل تحديث. */
  function check() {
    if (busy || document.hidden) { return; }
    busy = true;
    fetch('<?= url('m/ping') ?>', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin', cache: 'no-store'
    }).then(function (r) {
      /* رد مش JSON = الجلسة خلصت. التحديث بيوديه على شاشة الدخول (اللي
         بتعرض نفس شاشة الصيانة طول ما القفل شغّال) — الحالتين صح. */
      var type = r.headers.get('content-type') || '';
      if (type.indexOf('application/json') === -1) { location.reload(); return null; }
      return r.json();
    }).then(function (d) {
      busy = false;
      if (d && !d.maintenance) { location.reload(); }
    }).catch(function () { busy = false; });
  }

  retry.addEventListener('click', function () {
    live.classList.add('is-busy');
    check();
    setTimeout(function () { live.classList.remove('is-busy'); }, 1200);
  });

  setInterval(check, 45000);
  // أول ما يفتح التطبيق تاني — أهم لحظة يتأكد فيها
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { check(); } });
})();
</script>

</body>
</html>
