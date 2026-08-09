<?php use App\Core\App; use App\Core\Auth;
$today = $today ?? date('Y-m-d');
$yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
$current = $current ?? $today;
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>تاريخ العمل — <?= e(App::setting('company_name','شركة المتوكل')) ?></title>
<link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
</head>
<body>
<div class="login-page">
  <div class="login-card">
    <div class="login-head">
      <div class="login-logo"><img src="<?= url('assets/img/logo.png') ?>" alt="<?= e(App::setting('company_name','شركة المتوكل')) ?>"></div>
      <h1>بتشتغل بتاريخ إمتى؟</h1>
      <p class="muted" style="margin:.35rem 0 0;font-size:var(--fs-3)">كل اللي هتدخّله النهاردة (مصروف / إيصال / بوليصة…) هيتسجّل بالتاريخ ده.</p>
    </div>
    <div class="login-body">
      <?php if ($m = flash('error')): ?><div class="flash error"><?= icon('alert',18) ?> <?= e($m) ?></div><?php endif; ?>
      <?php if (Auth::freeEditMode()): ?>
        <div class="wd-free-note"><?= icon('alert', 14) ?> <b>الدخول الحر مفعّل:</b> يمكنك اختيار يوم مقفول والتعديل عليه، وسيعيد النظام حساب الإقفالات تلقائيًا.
        </div>
      <?php endif; ?>
      <form action="<?= url('work-date') ?>" method="post" id="workdate-form">
        <?= csrfField() ?>
        <input type="hidden" name="return" value="">
        <div class="wd-quick">
          <button type="button" class="btn ghost wd-preset" data-date="<?= e($today) ?>">اليوم<br><small><?= e(dfmt($today)) ?></small></button>
          <button type="button" class="btn ghost wd-preset" data-date="<?= e($yesterday) ?>">إمبارح<br><small><?= e(dfmt($yesterday)) ?></small></button>
        </div>
        <div class="field">
          <label>أو اختر تاريخًا</label>
          <input type="date" name="work_date" id="wd-input" value="<?= e($current) ?>" max="<?= e($today) ?>" required>
        </div>
        <div class="wd-warn" id="wd-warn" hidden><?= icon('alert',16) ?> انتبه: انت هتشتغل بيوم سابق مش النهاردة.</div>
        <button class="btn gold" type="submit" style="width:100%">ابدأ الشغل ←</button>
      </form>
      <?php /* ⚠️ **مخرج من الشاشة** — كانت بلا أي طريق للخروج: صفحة برّه
               التخطيط (مفيش قائمة ولا رجوع)، والاختيار المرفوض بيرجّعك ليها
               تاني. فالمالك كان بيعمل **تسجيل خروج** عشان يخرج (بلاغ
               2026-08-09). ودي كانت الشكوى الأصلية كلها. */ ?>
      <div class="wd-outs">
        <?php if (($rejected ?? '') !== ''): ?>
          <?php /* القفل بيمنع الكتابة مش القراءة — فالعرض متاح لأي يوم */ ?>
          <a class="btn ghost" href="<?= url('rep-custody?day=' . rawurlencode((string) $rejected)) ?>">
            <?= icon('eye', 15) ?> شوف <?= e(dfmt((string) $rejected)) ?> للعرض بس
          </a>
        <?php endif; ?>
        <a class="wd-skip" href="<?= url('') ?>">أشتغل بتاريخ النهاردة</a>
      </div>
    </div>
  </div>
</div>
<script>
(function(){
  var today = <?= js_json($today) ?>;
  var input = document.getElementById('wd-input');
  var warn  = document.getElementById('wd-warn');
  function sync(){ warn.hidden = (input.value === today); }
  input.addEventListener('change', sync);
  document.querySelectorAll('.wd-preset').forEach(function(b){
    b.addEventListener('click', function(){ input.value = b.dataset.date; sync(); });
  });
  sync();
})();
</script>
<style>
.wd-free-note{margin-bottom:14px;padding:11px 13px;border:1px solid #df9b76;border-radius:var(--radius-2);background:var(--gold-light);color:var(--red-fg);font-size:var(--fs-2);line-height:1.7}
</style>
</body>
</html>
