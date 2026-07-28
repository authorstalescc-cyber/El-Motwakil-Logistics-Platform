<?php use App\Core\App; $formState=$formState??['token'=>'','old'=>[],'errors'=>[],'back'=>'login'];
$coName = App::setting('company_name','شركة المتوكل'); ?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>تسجيل الدخول — <?= e($coName) ?></title>
<?php /* ⚠️ نفس سكربت التخطيط بالحرف: السمة بتتحط على <html> **قبل** ملف
         التصميم. من غيره اللي مختار «غامق» صراحةً كان بيلاقي شاشة الدخول
         بتتبع إعداد الجهاز بس — وأول ما يدخل النظام يغمق فجأة. */ ?>
<script>(function(){try{var t=localStorage.getItem('almotawakel:theme');
  if(t==='dark'||t==='light')document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
<?php $appCss = App::basePath('public/assets/css/app.css'); $appCssV = is_file($appCss) ? filemtime($appCss) : 1; ?>
<link rel="stylesheet" href="<?= url('assets/css/app.css') ?>?v=<?= $appCssV ?>">
<?php /* ⚠️ لازم هنا كمان: أول صفحة بيشوفها المالك على الموبايل هي الدخول،
         والمتصفح ما بيعرضش «ثبّت التطبيق» غير لو الصفحة **اللي هو فيها**
         مربوطة بالـmanifest. من غير السطر ده التثبيت ما يظهرش قبل الدخول. */ ?>
<?php $pwaApp = 'office'; require dirname(__DIR__) . '/_pwa_head.php'; ?>
</head>
<body>
<button type="button" class="auth-theme" id="themeBtn" title="وضع العرض: تلقائي" aria-label="غيّر وضع العرض">
  <span class="th-ico th-auto"><?= icon('monitor',18) ?></span>
  <span class="th-ico th-light"><?= icon('sun',18) ?></span>
  <span class="th-ico th-dark"><?= icon('moon',18) ?></span>
</button>
<main class="login-page" id="main-content">
  <div class="auth-shell">

    <aside class="auth-side">
      <?php /* علامة الشاحنة والمخزن — نفس اللي على أيقونة التطبيق، فالمالك
               يلاقي الشاشة بتشبه الأيقونة اللي دوس عليها.
               ⚠️ `tex-mark-lg.png` **مش** الملف الأصلي: ده النسخة المقصوصة
               والمكبّرة اللي بيولّدها `bin/make_pwa_assets.py`. الأصلي
               250×250 والرسم جوّاه 146×68 بس، فعرضه كما هو بيسيب فراغًا
               شفافًا حواليه ويبوّظ توسيط اللوح. */ ?>
      <div class="auth-mark"><img src="<?= url('assets/img/tex-mark-lg.png') ?>" alt="" width="584" height="272"></div>
      <div class="auth-txt">
        <h1><?= e($coName) ?></h1>
        <p class="auth-tag">إدارة النقل والتخليص الجمركي</p>
      </div>
      <ul class="auth-points">
        <li><?= icon('file-text',16) ?> أذونات وبوالص وفواتير</li>
        <li><?= icon('wallet',16) ?> خزنة المكتب وعهدة المناديب</li>
        <li><?= icon('map-pin',16) ?> تتبّع الشحنات لحظة بلحظة</li>
      </ul>
      <div class="auth-road" aria-hidden="true"></div>
    </aside>

    <div class="auth-main">
      <div class="auth-head">
        <h2>تسجيل الدخول</h2>
        <p>أهلاً بيك — ادخل بياناتك عشان تكمّل شغلك.</p>
      </div>

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

      <form action="<?= url('login') ?>" method="post" id="loginForm" class="auth-form">
        <?= csrfField() ?>
        <?= formStateFields($formState['token'], 'login') ?>

        <div class="auth-field">
          <label for="login-username">اسم المستخدم</label>
          <div class="auth-in">
            <input id="login-username" type="text" name="username" placeholder="اسم المستخدم"
                   value="<?= e($formState['old']['username'] ?? '') ?>" autocomplete="username" autofocus required>
            <span class="auth-ico"><?= icon('user',18) ?></span>
          </div>
        </div>

        <div class="auth-field">
          <label for="login-password">كلمة السر</label>
          <div class="auth-in has-eye">
            <input id="login-password" type="password" name="password" placeholder="••••••••"
                   autocomplete="current-password" required>
            <span class="auth-ico"><?= icon('lock',18) ?></span>
            <?php /* aria-pressed هي مصدر الحقيقة: الـCSS بيبدّل الأيقونة منها،
                     والـJS بيقلبها ويغيّر نوع الحقل — فمفيش حالتين ممكن يفترقوا. */ ?>
            <button type="button" class="auth-eye" id="pwToggle" aria-pressed="false"
                    aria-controls="login-password" title="إظهار كلمة السر" aria-label="إظهار كلمة السر">
              <span class="eye-on"><?= icon('eye',18) ?></span>
              <span class="eye-off"><?= icon('eye-off',18) ?></span>
            </button>
          </div>
        </div>

        <label class="free-login-option">
          <input type="checkbox" name="free_edit_mode" value="1" <?= !empty($formState['old']['free_edit_mode'])?'checked':'' ?>>
          <span><b>دخول حر (للمصرّح لهم)</b><small>كتابة على الأيام المقفولة</small></span>
        </label>

        <button class="btn gold auth-submit" type="submit">دخول</button>
      </form>

      <p class="auth-foot"><?= icon('shield',15) ?> كل محاولة دخول بتتسجّل، والحساب بيتقفل مؤقّتًا بعد محاولات فاشلة متكرّرة.</p>
    </div>

  </div>
</main>
<?php $loginJs = App::basePath('public/assets/js/login.js'); $loginJsV = is_file($loginJs) ? filemtime($loginJs) : 1; ?>
<script src="<?= url('assets/js/login.js') ?>?v=<?= $loginJsV ?>"></script>
</body>
</html>
