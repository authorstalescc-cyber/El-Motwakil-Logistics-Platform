<?php
/**
 * شاشة «بتشتغل بتاريخ إمتى؟» — نظام المكتب. بتظهر عقب الدخول وعند التغيير اليدوي.
 *
 * ⚠️ **دي نظيرة `app/Views/m/workdate.php`** (بوابة المندوب) بنفس الشكل بالظبط،
 *    بس **الكلاسات هنا `wd-` مش `wdp-`**: البوابة ما بتحمّلش `app.css` خالص،
 *    فقواعدها عايشة في `m.css` باسم تاني عن قصد عشان تعديل في ملف ما يبانش
 *    كأنه اتعمل في التاني. **أي تغيير في الشكل هنا لازم يتكرّر هناك.**
 *
 * ⚠️ **خانة التاريخ الأصلية هي الحقل المرسَل فعلاً** (`name="work_date"`) —
 *    مغطّية كارت «يوم تاني» بشفافية كاملة. الأزرار بتكتب فيها، فلو الجافاسكريبت
 *    وقع الفورم لسه بيشتغل بالمنتقي الأصلي.
 *
 * @var string $current @var string $today @var string $rejected
 */
use App\Core\App; use App\Core\Auth;
$today     = $today ?? date('Y-m-d');
$yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
$current   = $current ?? $today;
$isOther   = $current !== $today && $current !== $yesterday;
/* الشارة بتقول **بُعد اليوم** لا حالته — «يوم سابق» مكتوبة تحتها في التحذير،
   وتكرارها حرفيًّا في نفس اللوح بيضيّع المعلومة الزيادة (قاعدة ⑤). */
$ago = (int) round((strtotime($today) - strtotime($current)) / 86400);
$tag = $ago === 0 ? 'النهاردة' : ($ago === 1 ? 'إمبارح' : 'من ' . $ago . ' يوم');
$coName = App::setting('company_name', 'شركة المتوكل');
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>تاريخ العمل — <?= e($coName) ?></title>
<?php /* ⚠️ نفس سكربت شاشة الدخول بالحرف: السمة بتتحط على <html> **قبل** ملف
         التصميم، وإلا الشاشة دي (أول حاجة بعد الدخول) بتلمع أبيض ثم تغمق. */ ?>
<script>(function(){try{var t=localStorage.getItem('almotawakel:theme');
  if(t==='dark'||t==='light')document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
<?php $appCss = App::basePath('public/assets/css/app.css'); $appCssV = is_file($appCss) ? filemtime($appCss) : 1; ?>
<link rel="stylesheet" href="<?= url('assets/css/app.css') ?>?v=<?= $appCssV ?>">
</head>
<body>
<main class="login-page">
  <div class="wd-wrap">
    <div class="wd-brand">
      <img src="<?= url('assets/img/logo.png') ?>" alt="<?= e($coName) ?>">
      <h1>بتشتغل بتاريخ إمتى؟</h1>
    </div>

    <div class="login-card">
      <div class="wd-box">
        <?php if ($m = flash('error')): ?><div class="flash error"><?= icon('alert', 17) ?> <?= e($m) ?></div><?php endif; ?>
        <?php if (Auth::freeEditMode()): ?>
          <div class="wd-free"><?= icon('alert', 15) ?> <span><b>الدخول الحر مفعّل:</b> تقدر تختار يوم مقفول وتعدّل عليه، والنظام هيعيد حساب الإقفالات.</span></div>
        <?php endif; ?>

        <form action="<?= url('work-date') ?>" method="post" id="workdate-form">
          <?= csrfField() ?>
          <input type="hidden" name="return" value="">

          <?php /* الرقم البطل: اليوم اللي هيتشغل عليه. لونه هو الإنذار — كحلي =
                   النهاردة، برونزي = يوم سابق، فالفرق بيتقري من بعيد بلا قراية. */ ?>
          <div class="wd-hero<?= $current === $today ? '' : ' is-past' ?>" id="wdHero">
            <span class="wd-tag" id="wdTag"><?= e($tag) ?></span>
            <span class="wd-week" id="wdWeek"><?= e(ar_weekday($current)) ?></span>
            <b class="wd-date" id="wdDate"><?= e(dfmt_long($current)) ?></b>
            <span class="wd-warn" id="wd-warn"<?= $current === $today ? ' hidden' : '' ?>><?= icon('alert', 15) ?> انت شغّال بيوم سابق</span>
          </div>

          <p class="wd-note"><?= icon('info', 14) ?> كل اللي هتدخّله هيتسجّل على اليوم ده</p>

          <div class="wd-opts">
            <button type="button" class="wd-opt<?= $current === $today ? ' is-on' : '' ?>" data-date="<?= e($today) ?>">
              <span class="wd-ck"><?= icon('check', 13) ?></span>
              <span class="wd-opt-t">النهاردة</span>
              <span class="wd-opt-d"><?= e(date('d/m', strtotime($today))) ?></span>
            </button>
            <button type="button" class="wd-opt<?= $current === $yesterday ? ' is-on' : '' ?>" data-date="<?= e($yesterday) ?>">
              <span class="wd-ck"><?= icon('check', 13) ?></span>
              <span class="wd-opt-t">إمبارح</span>
              <span class="wd-opt-d"><?= e(date('d/m', strtotime($yesterday))) ?></span>
            </button>
            <label class="wd-opt wd-other<?= $isOther ? ' is-on' : '' ?>" id="wdOther">
              <span class="wd-ck"><?= icon('check', 13) ?></span>
              <span class="wd-opt-t">يوم تاني</span>
              <span class="wd-opt-d" id="wdOtherD"><?= $isOther ? e(date('d/m', strtotime($current))) : icon('calendar', 15) ?></span>
              <input type="date" name="work_date" id="wd-input" value="<?= e($current) ?>" max="<?= e($today) ?>" required>
            </label>
          </div>

          <button class="btn gold wd-go" type="submit">ابدأ الشغل <span aria-hidden="true">←</span></button>
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
</main>
<script>
(function () {
  var today = <?= js_json($today) ?>, yest = <?= js_json($yesterday) ?>;
  var WD    = <?= js_json(ar_weekday_names()) ?>, MO = <?= js_json(ar_month_names()) ?>;
  var input = document.getElementById('wd-input');
  var warn  = document.getElementById('wd-warn');
  var hero  = document.getElementById('wdHero');
  var otherCard = document.getElementById('wdOther');
  var otherD    = document.getElementById('wdOtherD');
  var calIcon   = otherD.innerHTML;              // أيقونة النتيجة الفاضية — بترجع لو رجع لبريسِت
  var opts  = Array.prototype.slice.call(document.querySelectorAll('.wd-opt'));

  function pad(n) { return (n < 10 ? '0' : '') + n; }

  function sync() {
    var v = input.value || today;
    // ⚠️ التقسيم اليدوي مقصود: `new Date('2026-08-10')` بتتقري UTC فبترجّع
    //    اليوم السابق في أي منطقة زمنية سالبة — واسم اليوم بيطلع غلط.
    var p = v.split('-'), d = new Date(+p[0], +p[1] - 1, +p[2]);
    var t = today.split('-'), td = new Date(+t[0], +t[1] - 1, +t[2]);
    var ago = Math.round((td - d) / 86400000);
    var isToday = (v === today), isYest = (v === yest);

    document.getElementById('wdWeek').textContent = WD[d.getDay()];
    document.getElementById('wdDate').textContent = d.getDate() + ' ' + MO[d.getMonth()] + ' ' + d.getFullYear();
    document.getElementById('wdTag').textContent  = isToday ? 'النهاردة' : (isYest ? 'إمبارح' : 'من ' + ago + ' يوم');
    hero.classList.toggle('is-past', !isToday);
    warn.hidden = isToday;

    opts.forEach(function (o) { o.classList.toggle('is-on', o.dataset.date === v); });
    var other = !isToday && !isYest;
    otherCard.classList.toggle('is-on', other);
    otherD.innerHTML = other ? (pad(d.getDate()) + '/' + pad(d.getMonth() + 1)) : calIcon;
  }

  input.addEventListener('change', sync);
  opts.forEach(function (b) {
    if (!b.dataset.date) { return; }
    b.addEventListener('click', function () { input.value = b.dataset.date; sync(); });
  });
  sync();
})();
</script>
</body>
</html>
