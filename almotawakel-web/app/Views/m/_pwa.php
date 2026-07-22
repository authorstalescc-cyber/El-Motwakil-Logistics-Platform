<?php /* وسوم الـPWA لشاشات المندوب — تتضمّن داخل <head>. آمنة على أي شاشة. */ ?>
<link rel="manifest" href="<?= url('manifest.webmanifest') ?>">
<link rel="apple-touch-icon" href="<?= url('assets/img/logo.png') ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="المتوكل">
<meta name="application-name" content="المتوكل">
<script>
(function () {
  'use strict';
  // تسجيل الـService Worker — بيخلّي التطبيق يفتح أسرع وبصفحة أوفلاين لطيفة.
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('<?= url('sw.js') ?>', { scope: '/' }).catch(function () {});
    });
  }
  // نمسك حدث «التثبيت» بدري ونخزّنه، والصفحة تعرض زرار «ثبّت التطبيق» لما يتاح.
  window.__almInstall = null;
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    window.__almInstall = e;
    window.dispatchEvent(new CustomEvent('alm-installable'));
  });
})();
</script>
