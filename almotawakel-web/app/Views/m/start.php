<?php
/**
 * «تحب تروح فين؟» (v72) — بوابة الاختيار عقب دخول المندوب (طلب المالك).
 *
 * ⚠️ **توجيه مش حماية**: الشاشتين الاتنين لهم حرّاسهم (`requireRep`)، والمندوب
 *    يقدر يفتح أي واحدة فيهم مباشرةً من اختصار على شاشته. الشاشة دي بتوفّر
 *    عليه لفّة، مش بتقفل عليه طريق.
 *
 * ⚠️ **الرقم على الكارت مش زينة**: قاعدة «الرقم هو البطل» — «حسابي» بتقول
 *    المتبقي على طول، فالمندوب يعرف الإجابة من غير ما يدخل أصلاً.
 *
 * @var string $repName @var array $account
 */
$num = fn($v) => number_format((float) $v, 0, '.', ',');
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0E2440">
<title>تحب تروح فين؟ — المتوكل</title>
<link rel="icon" href="<?= url('assets/img/logo.png') ?>">
<link rel="stylesheet" href="<?= url('assets/css/m.css') ?>?v=<?= @filemtime(\App\Core\App::basePath('public/assets/css/m.css')) ?>">
<?php require __DIR__ . "/_pwa.php"; ?>
</head>
<body>

<div class="login ms-wrap">
  <div class="logo">
    <img src="<?= url('assets/img/logo.png') ?>" alt="">
    <h1>أهلاً <?= e($repName) ?></h1>
  </div>

  <?php if ($m = flash('error')): ?><div class="flash err"><?= e($m) ?></div><?php endif; ?>
  <?php if ($m = flash('success')): ?><div class="flash ok"><?= e($m) ?></div><?php endif; ?>

  <div class="ms-picks">
    <a class="ms-pick is-work" href="<?= url('m/work-date') ?>">
      <span class="ms-ic"><?= icon('truck', 22) ?></span>
      <b>شاشة العمل</b>
      <em>أذوناتك وبنودك وعهدة اليوم</em>
      <span class="ms-go" aria-hidden="true">←</span>
    </a>

    <a class="ms-pick is-acct" href="<?= url('m/account') ?>">
      <span class="ms-ic"><?= icon('wallet', 22) ?></span>
      <b>حسابي</b>
      <?php /* المتبقي = الراتب − السلف (قرار المالك). صفر راتب = لسه ما اتحطّش
               في كارت المندوب بالمكتب، فبنقول «كلّم المكتب» بدل رقم كاذب. */ ?>
      <?php if ($account['salary'] > 0): ?>
        <em>المتبقي من راتبك</em>
        <span class="ms-amt"><i>ج</i><b><?= $num($account['net']) ?></b></span>
      <?php else: ?>
        <em>راتبك والسلف والمصروفات</em>
      <?php endif; ?>
      <span class="ms-go" aria-hidden="true">←</span>
    </a>
  </div>

  <form method="post" action="<?= url('m/logout') ?>" class="ms-out">
    <?= csrfField() ?>
    <button type="submit"><?= icon('log-out', 14) ?> خروج</button>
  </form>
</div>

</body>
</html>
