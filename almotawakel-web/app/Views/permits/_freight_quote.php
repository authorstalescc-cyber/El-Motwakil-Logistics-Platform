<?php
/**
 * كارت «النولون المتوقّع» الجانبي في شاشة إصدار الفاتورة (v102).
 *
 * طلب المالك: «النظام يتوقّع النولون ويقولّي في كارت جانبي على الشمال عند
 * إصدار الفاتورة: النولون المتوقّع وبناءً على إيه».
 *
 * ⚠️ **الرقم اقتراح — الزرار هو اللي بيحطّه، والمالك شايفه قبل الحفظ.** ولا
 *    خانة بتتملى تلقائيًّا (نفس عقيدة v97/v98: «الفلوس اقتراح يتداس»).
 *
 * ⚠️ **الرقم مضغوط** ويفتح الدليل: سطر التعريفة اللي طابق + الفواتير السابقة
 *    (قاعدة «ممنوع رقم أصمّ»).
 *
 * ⚠️ **لما التعريفة والتاريخ يختلفوا بيتعرضوا الاتنين.** الفرق معلومة مش
 *    تناقض — يا العميل عنده سعر متفق عليه، يا القايمة اتحدّثت والفواتير على
 *    القديم. إخفاء واحد منهم بيخلّي المالك يفوتر برقم وهو فاكر إنه التاني.
 *
 * متغيّرات: $quote (ناتج `FreightQuote::forPermit`) · $pid
 */
$q   = $quote ?? null;
$pid = (int) ($pid ?? 0);
if ($q === null) { return; }

$srcWord = ['history' => 'من فواتير سابقة', 'tariff' => 'من قائمة الأسعار'];
?>
<aside class="inv-side">
  <div class="fq-card<?= $q['ready'] ? '' : ' quiet' ?>">
    <div class="fq-head">
      <?= icon('banknote', 15) ?> <h3>النولون المتوقّع</h3>
      <?php if ($q['ready']): ?>
        <span class="fq-src"><?= e($srcWord[$q['source']] ?? '') ?></span>
      <?php endif; ?>
    </div>

    <?php if (!$q['ready']): ?>
      <div class="fq-none">
        <?= icon('info', 15) ?>
        <span><?= e((string) ($q['note'] ?? 'مفيش سعر متوقّع للسياق ده.')) ?></span>
      </div>
    <?php else: ?>

      <button type="button" class="fq-big" data-drill="<?= e(url('permits/'.$pid.'/freight-why')) ?>"
              data-drill-title="النولون المتوقّع — جاي منين؟">
        <b><?= money((float) $q['price'], false) ?></b>
        <i>للسيارة الواحدة</i>
      </button>

      <?php if ($q['cars']): ?>
        <div class="fq-total">
          <span>× <?= intf((int) $q['cars']) ?> سيارة</span>
          <b><?= money((float) $q['total'], false) ?></b>
        </div>
      <?php endif; ?>

      <?php /* الفرق بين المصدرين — بيتعرض لما يوجد بس */ ?>
      <?php if ($q['gap'] !== null && $q['tariff'] !== null && $q['history'] !== null): ?>
        <div class="fq-gap<?= abs((float) $q['gap']['pct']) >= 15 ? ' wide' : '' ?>">
          <?= icon('alert', 13) ?>
          <span>القايمة <b><?= money((float) $q['tariff']['price'], false) ?></b>
            · المعتاد <b><?= money((float) $q['history']['price'], false) ?></b>
            <em><?= e(($q['gap']['pct'] > 0 ? '+' : '') . number_format((float) $q['gap']['pct'], 0)) ?>٪</em></span>
        </div>
      <?php endif; ?>

      <button type="button" class="btn sm block js-fq-apply"
              data-price="<?= e(number_format((float) $q['price'], 2, '.', '')) ?>">
        <?= icon('check', 14) ?> حطّه في الخانة
      </button>
    <?php endif; ?>

    <?php /* «بناءً على إيه» — كل سطر حقيقة من الإذن، والناقص بيبان */ ?>
    <div class="fq-basis">
      <?php foreach ($q['basis'] as $b): ?>
        <div class="fq-b<?= empty($b['ok']) ? ' miss' : '' ?>">
          <span class="fq-b-l"><?= icon($b['icon'], 13) ?> <?= e($b['label']) ?></span>
          <span class="fq-b-v"><?= e((string) $b['value']) ?></span>
          <?php if (!empty($b['note'])): ?><span class="fq-b-n"><?= e($b['note']) ?></span><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($q['ready'] && !empty($q['note'])): ?>
      <div class="fq-warn"><?= icon('alert', 13) ?> <?= e((string) $q['note']) ?></div>
    <?php endif; ?>

    <a class="fq-more" href="<?= url('tariffs') ?>"><?= icon('external', 13) ?> قائمة الأسعار</a>
  </div>
</aside>
