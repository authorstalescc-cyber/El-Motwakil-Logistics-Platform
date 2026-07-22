<?php
/**
 * «غير موجود» للزائر غير المسجَّل — صفحة مستقلة عن قصد (مفيش قائمة نعرضها
 * لحد لسه ما دخلش). المستخدم المسجَّل بياخد `errors/404.php` جوّه الـlayout.
 */
?><!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>غير موجود</title>
<link rel="stylesheet" href="<?= url('assets/css/app.css') ?>"></head>
<body>
<div class="login-page">
  <div class="err-guest">
    <div class="err-code">404</div>
    <p>الصفحة غير موجودة</p>
    <a class="btn gold" href="<?= url('login') ?>">تسجيل الدخول</a>
  </div>
</div>
</body></html>
