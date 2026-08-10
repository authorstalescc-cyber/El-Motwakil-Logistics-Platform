<?php
/**
 * شاشة «بتشتغل بتاريخ إمتى؟» لبوابة المندوب — بتظهر عقب الدخول وعند «غيّر».
 *
 * ⚠️ **الكلاسات هنا `wdp-` مش `wd-`**: نظيرة الشاشة في المكتب
 *    (`app/Views/workdate/pick.php`) لسه ماسكة `wd-quick`/`wd-preset`/`wd-warn`
 *    من `app.css`. الاسم المتطابق على ملفَي CSS مختلفين كان هيخلّي أي تعديل
 *    هنا يبان كأنه اتعمل هناك كمان.
 *
 * ⚠️ **خانة التاريخ الأصلية هي الحقل المرسَل فعلاً** (`name="work_date"`) —
 *    مغطّية كارت «يوم تاني» بشفافية كاملة. الأزرار بتكتب فيها، فلو الجافاسكريبت
 *    وقع الفورم لسه بيشتغل بالمنتقي الأصلي.
 *
 * @var string $current @var string $today
 */
$today     = $today ?? date('Y-m-d');
$yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
$current   = $current ?? $today;
$isOther   = $current !== $today && $current !== $yesterday;
/* شارة الرأس بتقول **بُعد اليوم** لا حالته: «يوم سابق» كانت بتتكرّر حرفيًا مع
   سطر التحذير تحتها في نفس اللوح. الرقم هنا معلومة زيادة (قاعدة ⑤). */
$ago = (int) round((strtotime($today) - strtotime($current)) / 86400);
$tag = $ago === 0 ? 'النهاردة' : ($ago === 1 ? 'إمبارح' : 'من ' . $ago . ' يوم');
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
<div class="login wdp">
  <div class="logo">
    <img src="<?= url('assets/img/logo.png') ?>" alt="">
    <h1>بتشتغل بتاريخ إمتى؟</h1>
  </div>

  <?php if ($m = flash('error')): ?><div class="flash err"><?= e($m) ?></div><?php endif; ?>

  <form method="post" action="<?= url('m/work-date') ?>" class="box wdp-box" id="wdForm">
    <?= csrfField() ?>

    <?php /* الرقم البطل: اليوم اللي هيتشغل عليه. لونه هو الإنذار — كحلي =
             النهاردة، برونزي = يوم سابق، فالفرق بيتقري من بعيد بلا قراية. */ ?>
    <div class="wdp-hero<?= $current === $today ? '' : ' is-past' ?>" id="wdpHero">
      <span class="wdp-tag" id="wdpTag"><?= e($tag) ?></span>
      <span class="wdp-week" id="wdpWeek"><?= e(ar_weekday($current)) ?></span>
      <b class="wdp-date" id="wdpDate"><?= e(dfmt_long($current)) ?></b>
      <span class="wdp-warn" id="wdWarn"<?= $current === $today ? ' hidden' : '' ?>><?= icon('alert', 14) ?> انت شغّال بيوم سابق</span>
    </div>

    <p class="wdp-note"><?= icon('info', 13) ?> إدخالاتك هتتسجّل على اليوم ده</p>

    <div class="wdp-opts">
      <button type="button" class="wdp-opt<?= $current === $today ? ' is-on' : '' ?>" data-date="<?= e($today) ?>">
        <span class="wdp-ck"><?= icon('check', 13) ?></span>
        <span class="wdp-opt-t">النهاردة</span>
        <span class="wdp-opt-d"><?= e(date('d/m', strtotime($today))) ?></span>
      </button>
      <button type="button" class="wdp-opt<?= $current === $yesterday ? ' is-on' : '' ?>" data-date="<?= e($yesterday) ?>">
        <span class="wdp-ck"><?= icon('check', 13) ?></span>
        <span class="wdp-opt-t">إمبارح</span>
        <span class="wdp-opt-d"><?= e(date('d/m', strtotime($yesterday))) ?></span>
      </button>
      <label class="wdp-opt wdp-other<?= $isOther ? ' is-on' : '' ?>" id="wdpOther">
        <span class="wdp-ck"><?= icon('check', 13) ?></span>
        <span class="wdp-opt-t">يوم تاني</span>
        <span class="wdp-opt-d" id="wdpOtherD"><?= $isOther ? e(date('d/m', strtotime($current))) : icon('calendar', 15) ?></span>
        <input type="date" name="work_date" id="wd" value="<?= e($current) ?>" max="<?= e($today) ?>" required>
      </label>
    </div>

    <button class="btn gold wdp-go" type="submit">ابدأ الشغل <span aria-hidden="true">←</span></button>
  </form>
</div>
<script>
(function () {
  var today = <?= js_json($today) ?>, yest = <?= js_json($yesterday) ?>;
  var WD    = <?= js_json(ar_weekday_names()) ?>, MO = <?= js_json(ar_month_names()) ?>;
  var input = document.getElementById('wd');
  var warn  = document.getElementById('wdWarn');
  var hero  = document.getElementById('wdpHero');
  var otherCard = document.getElementById('wdpOther');
  var otherD    = document.getElementById('wdpOtherD');
  var calIcon   = otherD.innerHTML;              // أيقونة النتيجة الفاضية — بترجع لو رجع لبريسِت
  var opts  = Array.prototype.slice.call(document.querySelectorAll('.wdp-opt'));

  function pad(n) { return (n < 10 ? '0' : '') + n; }

  function sync() {
    var v = input.value || today;
    // ⚠️ التقسيم اليدوي مقصود: `new Date('2026-08-10')` بتتقري UTC فبترجّع
    //    اليوم السابق في أي منطقة زمنية سالبة — واسم اليوم بيطلع غلط.
    var p = v.split('-'), d = new Date(+p[0], +p[1] - 1, +p[2]);
    var t = today.split('-'), td = new Date(+t[0], +t[1] - 1, +t[2]);
    var ago = Math.round((td - d) / 86400000);
    var isToday = (v === today), isYest = (v === yest);

    document.getElementById('wdpWeek').textContent = WD[d.getDay()];
    document.getElementById('wdpDate').textContent = d.getDate() + ' ' + MO[d.getMonth()] + ' ' + d.getFullYear();
    document.getElementById('wdpTag').textContent  = isToday ? 'النهاردة' : (isYest ? 'إمبارح' : 'من ' + ago + ' يوم');
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
