<?php use App\Core\App; ?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>تسجيل الدخول — <?= e(App::setting('company_name','شركة المتوكل')) ?></title>
<link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
</head>
<body>
<div class="login-page">
  <div class="login-card">
    <div class="login-head">
      <div class="login-logo"><img src="<?= url('assets/img/logo.png') ?>" alt="<?= e(App::setting('company_name','شركة المتوكل')) ?>"></div>
      <h1><?= e(App::setting('company_name','شركة المتوكل')) ?></h1>
    </div>
    <div class="login-body">
      <?php if ($m = flash('error')): ?><div class="flash error">⚠ <?= e($m) ?></div><?php endif; ?>
      <form action="<?= url('login') ?>" method="post">
        <?= csrfField() ?>
        <div class="field">
          <label>اسم المستخدم</label>
          <input type="text" name="username" value="<?= e(old('username')) ?>" autofocus required>
        </div>
        <div class="field">
          <label>كلمة السر</label>
          <input type="password" name="password" required>
        </div>
        <label class="free-login-option">
          <input type="checkbox" name="free_edit_mode" value="1">
          <span><b>دخول حر (للمصرّح لهم)</b><small>السماح بالإضافة والتعديل والحذف على الأيام المقفولة دون فتحها يدويًا.</small></span>
        </label>
        <button class="btn gold" type="submit">دخول</button>
      </form>
    </div>
  </div>
</div>
<style>
.free-login-option{display:flex;align-items:flex-start;gap:10px;margin:4px 0 18px;padding:12px;border:1px solid #e5c86e;border-radius:10px;background:#fff8dc;cursor:pointer}
.free-login-option input{width:18px;height:18px;margin-top:2px;accent-color:#b4383e}.free-login-option span{display:block}.free-login-option b{display:block;color:#8a3b22}.free-login-option small{display:block;margin-top:3px;color:#6d6253;line-height:1.5}
</style>
<?php unset($_SESSION['_old']); ?>
</body>
</html>
