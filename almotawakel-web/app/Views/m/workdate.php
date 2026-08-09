<?php
/**
 * شاشة «بتشتغل بتاريخ إمتى؟» لبوابة المندوب — بتظهر عقب الدخول وعند «غيّر».
 * نفس فكرة workdate/pick.php بتاع المكتب، بس بجلسة المندوب وشكل الموبايل.
 * @var string $current @var string $today
 */
$today     = $today ?? date('Y-m-d');
$yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
$current   = $current ?? $today;
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0E2440">
<title>تاريخ العمل — المتوكل</title>
<link rel="icon" href="<?= url('assets/img/logo.png') ?>">
<link rel="stylesheet" href="<?= url('assets/css/m.css') ?>?v=<?= @filemtime(\App\Core\App::basePath('public/assets/css/m.css')) ?>">
<?php require __DIR__ . "/_pwa.php"; ?>
</head>
<body>
<div class="login">
  <div class="logo">
    <img src="<?= url('assets/img/logo.png') ?>" alt="">
    <h1>بتشتغل بتاريخ إمتى؟</h1>
    <p>إدخالاتك (إيصالات ومصروفات) هتتسجّل على اليوم اللي تختاره.</p>
  </div>

  <?php if ($m = flash('error')): ?><div class="flash err"><?= e($m) ?></div><?php endif; ?>

  <form method="post" action="<?= url('m/work-date') ?>" class="box" id="wdForm">
    <?= csrfField() ?>
    <div class="wd-quick">
      <button type="button" class="btn ghost wd-preset" data-date="<?= e($today) ?>">
        النهاردة<br><small><?= e(dfmt($today)) ?></small>
      </button>
      <button type="button" class="btn ghost wd-preset" data-date="<?= e($yesterday) ?>">
        إمبارح<br><small><?= e(dfmt($yesterday)) ?></small>
      </button>
    </div>
    <div class="field">
      <label for="wd">أو اختار يوم تاني</label>
      <input type="date" name="work_date" id="wd" value="<?= e($current) ?>" max="<?= e($today) ?>" required>
    </div>
    <div class="wd-warn" id="wdWarn" hidden><?= icon('alert', 14) ?> انتبه: انت هتشتغل بيوم سابق مش النهاردة.</div>
    <button class="btn gold" type="submit">ابدأ الشغل ←</button>
  </form>
</div>
<script>
(function () {
  var today = <?= js_json($today) ?>;
  var input = document.getElementById('wd');
  var warn  = document.getElementById('wdWarn');
  function sync() { warn.hidden = (input.value === today); }
  input.addEventListener('change', sync);
  document.querySelectorAll('.wd-preset').forEach(function (b) {
    b.addEventListener('click', function () { input.value = b.dataset.date; sync(); });
  });
  sync();
})();
</script>
</body>
</html>
