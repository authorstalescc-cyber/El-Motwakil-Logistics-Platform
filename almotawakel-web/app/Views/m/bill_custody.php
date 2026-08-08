<?php
/**
 * «سلّمت السائق عهدته» على بوليصة **موجودة** (المرحلة ٣.٣ من docs/16).
 *
 * ⚠️ الشاشة **مالهاش أي رقم بيخصّ العميل** (قاعدة v35): النولون والتحويلة
 *    والبياتة والفعلي عمرهم ما بيوصلوا هنا — بس عهدة السائق وعهدة المندوب.
 *
 * ⚠️ **وسم مش إدخال مبلغ**: مفيش خانة مبلغ في الشاشة خالص. المبلغ زي ما المكتب
 *    سجّله، والمندوب بيقول «مين دفع» وبس — عشان `bawalis.custody` داخلة في
 *    معادلة دين العميل، فخانة مبلغ هنا كانت هتخلّي غلطة رقم تغيّر حساب عميل.
 *    الشرح الكامل في `RepPortal::claimableBills`.
 *
 * @var array $bills @var array $mine @var array $pocket @var bool $ready
 * @var string $workDate @var string $formToken
 */
$num  = fn($v) => number_format((float) $v, 0);
$ded  = fn(array $b) => \App\Models\Custody::billDeduction(
    (float) ($b['custody'] ?? 0), $b['car_no'] ?? null);
$ctLbl = function (array $b): string {
    $c = (int) ($b['container_count'] ?? 0);
    $t = container_norm((string) ($b['container_type'] ?? ''));
    if ($c > 0 && $t !== '') { return $c . '×' . $t; }
    if ($c > 0) { return $c . ' حاوية'; }
    return $t;
};
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0F151E">
<title>سلّمت السائق عهدته</title>
<link rel="icon" href="<?= url('assets/img/logo.png') ?>">
<link rel="stylesheet" href="<?= url('assets/css/m.css') ?>?v=<?= @filemtime(\App\Core\App::basePath('public/assets/css/m.css')) ?>">
<?php require __DIR__ . "/_pwa.php"; ?>
</head>
<body class="hm-body hf-body">

<div class="hf-top">
  <header class="hf-bar">
    <?php $backTo = 'm'; require __DIR__ . '/_back.php'; ?>
    <span class="hf-title">سلّمت السائق عهدته</span>
  </header>

  <div class="hf-context hf-context-bal">
    <span class="hf-bal-lbl">اللي معاك دلوقتي</span>
    <b class="hf-bal-amt"><?= $num($pocket['pocket']) ?> <small>جنيه</small></b>
    <span class="hf-note-line">لو انت اللي سلّمت السائق عهدته، دوسها هنا — المبلغ بيتشيل من
      خزنة المكتب ويتحسب على عهدتك. المبلغ زي ما المكتب سجّله في البوليصة.</span>
  </div>
</div>

<form method="post" action="<?= url('m/bill/custody') ?>" id="f" novalidate>
  <?= csrfField() ?>
  <?php /* ⚠️ رمز لمرة واحدة يستهلكه `claimForm` — ضغطة مكرّرة على شبكة بطيئة
           كانت هتوسم البوليصة ثم ترجّع «حد سبقك» ورسالة مقلقة بلا سبب. */ ?>
  <input type="hidden" name="_form_token" value="<?= e($formToken ?? '') ?>">

  <main class="hf-sheet">
    <span class="hm-grab" aria-hidden="true"></span>

    <?php if ($m = flash('success')): ?><div class="flash ok"><?= e($m) ?></div><?php endif; ?>
    <?php if ($m = flash('error')): ?><div class="flash err"><?= e($m) ?></div><?php endif; ?>

    <?php $wd = $workDate; $wdLabel = 'العهدة بتتحسب على عهدة يوم البوليصة';
          require __DIR__ . '/_workday.php'; ?>

    <?php if (!$ready): ?>
      <div class="hm-empty"><span class="ic"><?= icon('alert', 14) ?></span>
        <b>الميزة مش متفعّلة</b>
        <p>كلّم المكتب — محتاج تحديث على النظام.</p></div>

    <?php elseif (!$bills): ?>
      <div class="hm-empty"><span class="ic"><?= icon('truck', 14) ?></span>
        <b>مفيش بوالص متاحة دلوقتي</b>
        <p>البوليصة بتظهر هنا لو المكتب سجّلها على إذن مفتوح، وكاتب عهدة سائقها،
          ولسه محدش قال إنه دفعها. لو سلّمت سائق عهدة وبوليصته مش هنا — كلّم المكتب
          يسجّلها الأول، أو اعملها من «بوليصة جديدة».</p>
        <a class="btn ghost" href="<?= url('m/bill/new') ?>">بوليصة جديدة</a></div>

    <?php else: ?>

      <div class="bc-find">
        <label for="q" class="bc-find-lbl"><?= icon('search', 14) ?> دوّر بالبوليصة أو السائق أو العربية</label>
        <input type="search" id="q" autocomplete="off" placeholder="بحث…">
      </div>

      <h3 class="hm-group"><?= count($bills) ?> بوليصة عهدتها لسه على المكتب</h3>

      <div class="bc-list" id="bcList">
        <?php foreach ($bills as $b): $bid = (int) $b['id']; ?>
          <?php /* نص البحث كله في سمة واحدة عشان الفلترة تبقى مقارنة واحدة */ ?>
          <label class="bc-item" for="b<?= $bid ?>" data-find="<?= e(mb_strtolower(implode(' ', array_filter([
                    (string) ($b['bill_number'] ?? ''), (string) ($b['driver'] ?? ''),
                    (string) ($b['car_no'] ?? ''), (string) ($b['permit_no'] ?? ''),
                    (string) ($b['company'] ?? ''), (string) ($b['destination'] ?? ''),
                    (string) ($b['container_no'] ?? ''),
                  ])))) ?>">
            <input type="radio" name="bill_id" id="b<?= $bid ?>" value="<?= $bid ?>" required>
            <span class="bc-mid">
              <span class="bc-hd">
                <b>بوليصة <?= e($b['bill_number'] ?: '#' . $bid) ?></b>
                <span class="bc-day"><?= e(dfmt($b['bill_date'])) ?></span>
              </span>
              <span class="bc-meta">
                <?php if (!empty($b['driver'])): ?><span><?= icon('user', 12) ?> <?= e($b['driver']) ?></span><?php endif; ?>
                <?php if (!empty($b['car_no'])): ?><span><?= icon('truck', 12) ?> <?= e($b['car_no']) ?></span><?php endif; ?>
                <?php if (($cl = $ctLbl($b)) !== ''): ?><span><?= icon('package', 12) ?> <?= e($cl) ?></span><?php endif; ?>
                <?php if (!empty($b['destination'])): ?><span><?= icon('map-pin', 12) ?> <?= e($b['destination']) ?></span><?php endif; ?>
              </span>
              <span class="bc-meta bc-meta-2">
                <span><?= ($b['permit_type'] ?? 'sader') === 'warid' ? 'شهادة' : 'إذن' ?>
                  <?= e($b['permit_no'] ?: '#' . (int) $b['permit_id']) ?></span>
                <?php if (!empty($b['company'])): ?><span>· <?= e($b['company']) ?></span><?php endif; ?>
              </span>
            </span>
            <span class="bc-end">
              <b><?= $num($ded($b)) ?></b>
              <small>هيتخصم من عهدتك</small>
              <?php if (abs($ded($b) - (float) $b['custody']) > 0.005): ?>
                <?php /* الـ100 رقم ورقي — السائق بياخد العهدة ناقصها، فالمخصوم من
                         محفظة المندوب هو اللي طلع من إيده فعلاً. عرض الرقمين مع
                         بعض بيمنع سؤال «ليه الرقم مش زي الورقة؟». */ ?>
                <small class="bc-gross">في الورقة <?= $num($b['custody']) ?></small>
              <?php endif; ?>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
      <p class="bc-none" id="bcNone" hidden>مفيش بوليصة بالبحث ده.</p>
    <?php endif; ?>

    <?php if ($mine): ?>
      <h3 class="hm-group bc-mine-h">بوالص سجّلتها على عهدتك في اليوم ده</h3>
      <?php foreach ($mine as $b): ?>
        <article class="hm-slip">
          <span class="hm-slip-ic" aria-hidden="true"><?= icon('truck', 14) ?></span>
          <div class="hm-slip-mid">
            <b>بوليصة <?= e($b['bill_number'] ?: '#' . (int) $b['id']) ?></b>
            <div class="hm-slip-meta">
              <span><?= e(dfmt($b['bill_date'])) ?></span>
              <?php if (!empty($b['driver'])): ?><span>· <?= e($b['driver']) ?></span><?php endif; ?>
              <?php if (!empty($b['car_no'])): ?><span>· <?= e($b['car_no']) ?></span><?php endif; ?>
            </div>
          </div>
          <div class="hm-slip-end">
            <span class="hm-slip-amt">−<?= $num($ded($b)) ?></span>
          </div>
          <div class="hm-slip-acts">
            <?php /* الصفحة كلها جوّه فورم الوسم `f`، وHTML يمنع فورم جوّه فورم —
                     فالإلغاء بيغيّر وجهة الإرسال بـ`formaction` بنفس CSRF بدل
                     فورم متداخل (نفس حل شاشة التحويل v46). */ ?>
            <button type="submit"
                    formaction="<?= url('m/bill/' . (int) $b['id'] . '/unclaim') ?>"
                    formmethod="post"
                    onclick="return confirm('تلغي تسجيلها على عهدتك؟ العهدة هترجع على خزنة المكتب.')"
                    aria-label="ألغِ التسجيل">ألغِ</button>
          </div>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </main>

  <?php if ($ready && $bills): ?>
  <div class="dock hf-dock">
    <?php /* الربط الصريح بـ`form` يخلي الزر يرسل الفورم حتى لو المتصفح أعاد
             ترتيب الـDOM حول عناصر الصفحة — ما نعتمدش على الاحتواء البصري. */ ?>
    <button class="btn gold" type="submit" id="save" form="f" disabled>أنا اللي سلّمت السائق</button>
  </div>
  <?php endif; ?>
</form>

<script>
(function () {
  'use strict';
  var list = document.getElementById('bcList');
  var save = document.getElementById('save');
  if (!list) { return; }

  // الزر مقفول لحد ما يختار بوليصة — «أنا اللي سلّمت السائق» بلا بوليصة مالهاش معنى
  list.addEventListener('change', function () {
    if (save) { save.disabled = !list.querySelector('input[name="bill_id"]:checked'); }
    Array.prototype.forEach.call(list.querySelectorAll('.bc-item'), function (el) {
      var r = el.querySelector('input[name="bill_id"]');
      el.classList.toggle('on', !!(r && r.checked));
    });
  });

  // بحث في المتصفح — مفيش أي طلب للخادم
  var q = document.getElementById('q');
  var none = document.getElementById('bcNone');
  if (q) {
    q.addEventListener('input', function () {
      var v = q.value.trim().toLowerCase()
        .replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); })
        .replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); });
      var shown = 0;
      Array.prototype.forEach.call(list.querySelectorAll('.bc-item'), function (el) {
        var hit = v === '' || (el.getAttribute('data-find') || '').indexOf(v) !== -1;
        el.hidden = !hit;
        if (hit) { shown++; return; }
        // ⚠️ البوليصة المخفية بالفلترة بيتشال اختيارها — مستحيل يسجّل حاجة مش شايفها
        var r = el.querySelector('input[name="bill_id"]');
        if (r && r.checked) { r.checked = false; el.classList.remove('on'); }
      });
      if (none) { none.hidden = shown !== 0; }
      if (save) { save.disabled = !list.querySelector('input[name="bill_id"]:checked'); }
    });
  }
})();
</script>
</body>
</html>
