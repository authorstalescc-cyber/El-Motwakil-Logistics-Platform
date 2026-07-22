<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0E2440">
<title>دخول المندوب — المتوكل</title>
<link rel="icon" href="<?= url('assets/img/logo.png') ?>">
<link rel="stylesheet" href="<?= url('assets/css/m.css') ?>?v=<?= @filemtime(\App\Core\App::basePath('public/assets/css/m.css')) ?>">
<?php require __DIR__ . "/_pwa.php"; ?>
</head>
<body>
<div class="login">
  <div class="logo">
    <img src="<?= url('assets/img/logo.png') ?>" alt="">
    <h1>المتوكل</h1>
    <p>دخول المناديب</p>
  </div>

  <?php if ($m = flash('error')): ?><div class="flash err"><?= e($m) ?></div><?php endif; ?>
  <?php if ($m = flash('success')): ?><div class="flash ok"><?= e($m) ?></div><?php endif; ?>

  <form method="post" action="<?= url('m/login') ?>" class="box" autocomplete="on">
    <?= csrfField() ?>
    <div class="field">
      <label for="u">اسم الدخول</label>
      <input type="text" id="u" name="username" required autocapitalize="none"
             autocorrect="off" spellcheck="false" inputmode="text" enterkeyhint="next"
             autocomplete="username" autofocus>
    </div>
    <div class="field">
      <label for="p">الرقم السري</label>
      <div class="password-wrap">
        <input type="password" id="p" name="pin" required inputmode="numeric"
               pattern="[0-9]*" autocomplete="current-password" enterkeyhint="go" placeholder="••••">
        <button type="button" id="showPin" aria-controls="p" aria-pressed="false">إظهار</button>
      </div>
    </div>
    <button class="btn gold" type="submit">ادخل</button>
  </form>

  <p style="text-align:center;font-size:.8rem;color:var(--grey);margin-top:18px">
    نسيت الرقم السري؟ كلّم المكتب.
  </p>
</div>
<script>
(function () {
  var button = document.getElementById('showPin'), pin = document.getElementById('p');
  button.addEventListener('click', function () {
    var show = pin.type === 'password';
    pin.type = show ? 'text' : 'password';
    button.textContent = show ? 'إخفاء' : 'إظهار';
    button.setAttribute('aria-pressed', show ? 'true' : 'false');
    pin.focus();
  });
})();
</script>
</body>
</html>
