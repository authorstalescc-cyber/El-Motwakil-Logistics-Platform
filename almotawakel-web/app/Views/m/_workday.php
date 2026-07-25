<?php
/**
 * جزء «عهدة يوم العمل» (v49) — بيتحط مكان خانة التاريخ في كل فورم في بوابة المندوب.
 *
 * ⚠️ التاريخ **مش خانة إدخال**: الخادم بيفرض يوم العمل على أي بند جديد
 *    (RepPortalController::postedData). فلو عرضنا خانة تاريخ هنا هتبقى بتكدب على
 *    المندوب — يغيّرها والخادم يتجاهلها. الطريقة الوحيدة لتغيير اليوم هي شاشة
 *    «بتشتغل بتاريخ إمتى؟» عشان العهدة اللي بيشوفها تتغيّر معاه في نفس اللحظة.
 *
 * @var string $wd      يوم العمل (Y-m-d)
 * @var array  $pocket  ناتج RepPortal::dayPocket — عهدة اليوم ده بالتحديد
 * @var string $wdLabel عنوان السطر (تاريخ الدفع / تاريخ الصرف / تاريخ التسليم…)
 */
$wdToday  = $wd === date('Y-m-d');
$wdLocked = !empty($pocket['locked']);
$wdLeft   = (float) ($pocket['pocket'] ?? 0);
$wdMoney  = static fn(float $v): string => number_format($v, 2);
?>
<div class="wd-card <?= $wdLocked ? 'is-locked' : ($wdToday ? '' : 'is-past') ?>">
  <div class="wd-card-top">
    <span class="wd-card-ic" aria-hidden="true">📅</span>
    <div class="wd-card-txt">
      <small><?= e($wdLabel ?? 'اليوم اللي بتسجّل عليه') ?></small>
      <b><?= e(dfmt($wd)) ?><?= $wdToday ? ' (النهاردة)' : '' ?></b>
    </div>
    <a class="wd-card-go" href="<?= url('m/work-date') ?>">غيّر اليوم</a>
  </div>

  <?php if ($wdLocked): ?>
    <p class="wd-card-lock">🔒 <?= e((string) $pocket['lock_reason']) ?></p>
  <?php else: ?>
    <div class="wd-card-bal">
      <span>الفاضل من عهدة اليوم ده</span>
      <b class="<?= $wdLeft < 0 ? 'is-neg' : '' ?>"
         data-wd-left="<?= e((string) $wdLeft) ?>"><?= $wdMoney($wdLeft) ?> ج</b>
    </div>
    <?php if ((float) ($pocket['deposits'] ?? 0) <= 0 && $wdLeft <= 0): ?>
      <p class="wd-card-hint">ماحدش نزّل لك عهدة في اليوم ده. تقدر تسجّل عادي، والرصيد هيبان سالب لحد ما المكتب ينزّل.</p>
    <?php endif; ?>
    <?php /* تحذير حيّ لما المبلغ يتخطّى الفاضل — تنبيه بس، مش منع (قرار المالك) */ ?>
    <p class="wd-card-over" id="wdOver" hidden>⚠️ المبلغ ده أكبر من الفاضل في عهدة اليوم — هيبقى عليك سالب.</p>
  <?php endif; ?>
</div>
<script>
/* سلوك بطاقة عهدة اليوم — مكتوب هنا مرة واحدة فيسري على الفورمات الأربعة.
   (١) اليوم المتقفل: بنقفل زرار الحفظ. الحارس الحقيقي في الخادم
       (RepPortal::assertDayOpen)، وده بس عشان المندوب ما يملاش فورم هيترفض.
   (٢) تجاوز عهدة اليوم: تحذير حيّ **بلا منع** — قرار المالك، لأن الميدان
       كتير بيدفع فيه المندوب من جيبه والسالب معناه «المكتب مدين له». */
(function () {
  var card = document.currentScript.previousElementSibling;
  if (!card || !card.classList.contains('wd-card')) { return; }
  var form = card.closest('form');
  if (!form) { return; }

  if (card.classList.contains('is-locked')) {
    form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (b) {
      b.disabled = true;
      b.setAttribute('aria-disabled', 'true');
    });
    return;
  }

  var left = card.querySelector('[data-wd-left]');
  var warn = card.querySelector('.wd-card-over');
  if (!left || !warn) { return; }
  var remaining = parseFloat(left.getAttribute('data-wd-left'));
  if (!isFinite(remaining)) { return; }

  /* المبلغ المدخل سعر وحدة في فورم البند، ومبلغ كامل في التسليم/التحويل. */
  var unit = form.querySelector('[name="unit_amount"], [name="amount"]');
  var qty  = form.querySelector('[name="qty"]');
  if (!unit) { return; }

  function sync() {
    var u = parseFloat(String(unit.value).replace(/[^\d.\-]/g, ''));
    var q = qty ? parseInt(qty.value, 10) : 1;
    if (!isFinite(u) || u <= 0) { warn.hidden = true; return; }
    if (!isFinite(q) || q < 1) { q = 1; }
    warn.hidden = !(u * q > remaining);
  }
  unit.addEventListener('input', sync);
  if (qty) { qty.addEventListener('input', sync); }
  sync();
})();
</script>
