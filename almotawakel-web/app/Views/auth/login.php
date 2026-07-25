<?php use App\Core\App; $formState=$formState??['token'=>'','old'=>[],'errors'=>[],'back'=>'login']; ?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>تسجيل الدخول — <?= e(App::setting('company_name','شركة المتوكل')) ?></title>
<?php $appCss = App::basePath('public/assets/css/app.css'); $appCssV = is_file($appCss) ? filemtime($appCss) : 1; ?>
<link rel="stylesheet" href="<?= url('assets/css/app.css') ?>?v=<?= $appCssV ?>">
<?php /* ⚠️ لازم هنا كمان: أول صفحة بيشوفها المالك على الموبايل هي الدخول،
         والمتصفح ما بيعرضش «ثبّت التطبيق» غير لو الصفحة **اللي هو فيها**
         مربوطة بالـmanifest. من غير السطر ده التثبيت ما يظهرش قبل الدخول. */ ?>
<?php $pwaApp = 'office'; require dirname(__DIR__) . '/_pwa_head.php'; ?>
</head>
<body>
<main class="login-page" id="main-content">
  <div class="login-card">
    <div class="login-head">
      <div class="login-logo"><img src="<?= url('assets/img/logo.png') ?>" alt="<?= e(App::setting('company_name','شركة المتوكل')) ?>"></div>
      <h1><?= e(App::setting('company_name','شركة المتوكل')) ?></h1>
    </div>
    <div class="login-body">
      <?php if (isset($_GET['bye'])): ?>
        <div class="flash success" role="status"><?= icon('check',17) ?> تم تسجيل الخروج بأمان.</div>
      <?php endif; ?>
      <?php
      /* صفحة الدخول مستقلة عن الـlayout، فلازم تفضّي الطابور بنفسها — وإلا
         رسالة زي «تم تسجيل الخروج» تفضل في الجلسة وتطلع كتوست بعد أول دخول
         وكأنها حصلت دلوقتي (المرحلة ٢). */
      foreach (flash_all() as $__f):
        $__cls = $__f['type'] === 'error' ? 'error' : 'success'; ?>
        <div class="flash <?= $__cls ?>" role="status"><?= icon($__cls === 'error' ? 'alert' : 'check', 17) ?> <?= e($__f['msg']) ?></div>
      <?php endforeach; ?>
      <?= formErrorSummary($formState['errors']) ?>
      <form action="<?= url('login') ?>" method="post" id="loginForm">
        <?= csrfField() ?>
        <?= formStateFields($formState['token'], 'login') ?>
        <div class="field">
          <label for="login-username">اسم المستخدم</label>
          <input id="login-username" type="text" name="username" value="<?= e($formState['old']['username'] ?? '') ?>" autocomplete="username" autofocus required>
        </div>
        <div class="field">
          <label for="login-password">كلمة السر</label>
          <input id="login-password" type="password" name="password" autocomplete="current-password" required>
        </div>
        <label class="free-login-option">
          <input type="checkbox" name="free_edit_mode" value="1" <?= !empty($formState['old']['free_edit_mode'])?'checked':'' ?>>
          <span><b>دخول حر (للمصرّح لهم)</b><small>السماح بالإضافة والتعديل والحذف على الأيام المقفولة دون فتحها يدويًا.</small></span>
        </label>
        <button class="btn gold" type="submit">دخول</button>
      </form>
    </div>
  </div>
</main>
<?php $loginJs = App::basePath('public/assets/js/login.js'); $loginJsV = is_file($loginJs) ? filemtime($loginJs) : 1; ?>
<script src="<?= url('assets/js/login.js') ?>?v=<?= $loginJsV ?>"></script>
</body>
</html>
