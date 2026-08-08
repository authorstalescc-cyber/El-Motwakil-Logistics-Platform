<?php
/**
 * زرار «تصفية العهدة» — بضغطة واحدة رصيد المندوب يرجع **صفر**.
 *
 * ⚠️ جزء مشترك بين تبويب «اليوم» وتبويب «الحساب الكامل» عن قصد: لو اتكتب مرتين
 *    هيفترقوا مع أول تعديل، والمالك يشوف رقمين لنفس الحاجة.
 *
 * ⚠️ **كل يوم بيتقفل على تاريخه هو** (2026-07-27): فيه زراران —
 *    «صفّي عهدة اليوم ده» و«صفّي كل الأيام المفتوحة». التاني بيصفّر التراكمي
 *    كمان، لأن مجموع أرصدة الأيام = التراكمي بالظبط (شوف `RepCustody::openDays`).
 *
 * ⚠️ **البنود المستنية بتوقف التصفية** (قرار المالك): قبل كده كانت بتتخصم من
 *    المبلغ فالزرار يسيب وراه رصيدًا والمالك يقول «مش بيصفّر».
 *
 * ⚠️ المبلغ المعروض هنا **عرض بس** — الخادم بيعيد حسابه من `clearSnapshot()`
 *    وقت الحفظ (الفورم مش مصدر ثقة، والرقم ممكن يكون قديم لو المندوب سجّل بند
 *    والصفحة مفتوحة).
 *
 * متغيّرات: $repId $clear (لقطة اليوم) $clearAll (كل الأيام) $day
 *           $back (اختياري) $anchor (اختياري)
 */
use App\Core\FormState;

$anchor   = $anchor ?? 'settle-zero';
$back     = $back ?? ('rep-custody/' . $repId . '?tab=day');
$clearAll = $clearAll ?? $clear;

$amt      = (float) $clear['amount'];          // اليوم المعروض
$allAmt   = (float) $clearAll['amount'];       // كل الأيام المفتوحة
$allDays  = (int) $clearAll['day_count'];
$blocked  = !empty($clearAll['blocked']);
$dayEmpty = abs($amt) < 0.005;
$allEmpty = abs($allAmt) < 0.005 && $allDays === 0;
$owedToRep = $amt < -0.005;

/** أيام تانية مفتوحة غير اليوم المعروض */
$others = array_values(array_filter(
    $clearAll['days'] ?? [],
    static fn (array $d): bool => (string) $d['day'] !== (string) $day && abs((float) $d['balance']) > 0.005
));
?>
<div class="d-card mb rc-zero<?= $allEmpty ? ' is-clean' : '' ?>" id="<?= e($anchor) ?>">
  <div class="d-card-head"><h3><?= icon('check-circle', 18) ?>
    <span>تصفية العهدة</span></h3></div>

  <div class="rc-zero-body">
    <div class="rc-zero-num">
      <span class="k"><?= $dayEmpty ? 'عهدة يوم ' . e(dfmt($day)) : ($owedToRep ? 'المكتب مستحق له' : 'فاضل من عهدة اليوم') ?></span>
      <b class="v<?= $owedToRep ? ' neg' : '' ?>"><?= intf(abs($amt)) ?></b>
      <span class="u">جنيه</span>
    </div>

    <div class="rc-zero-txt">
      <?php if ($allEmpty): ?>
        <p><b>العهدة مصفّرة <?= icon('check', 14) ?></b> — مفيش أي يوم مفتوح بينك وبينه.</p>
      <?php elseif ($dayEmpty): ?>
        <p>يوم <?= e(dfmt($day)) ?> مقفول خلاص. لسه فيه
           <b><?= intf(count($others)) ?></b> يوم تاني مفتوح تحت.</p>
      <?php elseif ($owedToRep): ?>
        <p>صرف في اليوم ده أكتر من عهدته. لو المكتب دفع له الفرق، دوس تصفية
           وعهدة اليوم ترجع <b>صفر</b>.</p>
      <?php else: ?>
        <p>سلّمك الفاضل من عهدة اليوم؟ دوس تصفية وعهدة اليوم ترجع <b>صفر</b> —
           بلا ما تكتب أي رقم.</p>
      <?php endif; ?>

      <?php if ($blocked): ?>
        <?php /* ⚠️ ده سبب «مش بيصفّر» القديم — دلوقتي بيتقال صريح وبيتوقف */ ?>
        <div class="rc-zero-block">
          <?= icon('alert', 15) ?>
          <div>
            <b>لازم تراجع البنود المستنية الأول.</b>
            فيه <b><?= intf($clearAll['pending_cnt']) ?></b> بند بـ<b><?= intf($clearAll['pending']) ?></b>
            جنيه لسه مستني اعتمادك — التصفية مش هتشتغل قبلها عشان ما يتقفلش
            مبلغ وانت مش شايفه. بعد ما تعتمدهم، التصفية هتصفّر الحساب بالمليم.
            <div style="margin-top:6px">
              <a class="btn ghost sm" href="<?= url('rep-custody/inbox') ?>">
                <?= icon('inbox', 14) ?> راجع البنود دلوقتي
              </a>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($others): ?>
        <div class="rc-zero-days">
          <b><?= icon('calendar', 14) ?> أيام تانية لسه مفتوحة:</b>
          <ul>
            <?php foreach (array_slice($others, 0, 8) as $d): ?>
              <li><span><?= e(dfmt($d['day'])) ?></span>
                  <b class="<?= (float) $d['balance'] < 0 ? 'neg' : '' ?>"><?= intf($d['balance']) ?></b></li>
            <?php endforeach; ?>
            <?php if (count($others) > 8): ?>
              <li class="more">+ <?= intf(count($others) - 8) ?> يوم كمان</li>
            <?php endif; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php /* ⚠️ كانت فقرة ٢٥ كلمة بتشرح «ليه» الخزنة ما بتتأثرش. القاعدة:
               الشرح التعليمي يتشال والتحذير الوظيفي يفضل في ≤٦ كلمات — فبقت
               شارة. المعلومة نفسها ما ضاعتش: هي **أثر** الإجراء لا درس فيه. */ ?>
      <span class="pill st-partial">بلا أثر على الخزنة</span>
    </div>

    <?php if (!$allEmpty && !$blocked): ?>
      <div class="rc-zero-acts">
        <?php if (!$dayEmpty): ?>
          <form method="post" action="<?= url('rep-custody/' . $repId . '/settle-all') ?>"
                data-confirm="<?= e($owedToRep
                  ? 'تأكيد: المكتب دفع للمندوب ' . intf(abs($amt)) . ' جنيه عن يوم ' . dfmt($day) . '، وعهدة اليوم هترجع صفر.'
                  : 'تأكيد: استلمت من المندوب ' . intf($amt) . ' جنيه عن يوم ' . dfmt($day) . '، وعهدة اليوم هترجع صفر.') ?>">
            <?= csrfField() ?>
            <?= formStateFields(FormState::token(), $back) ?>
            <input type="hidden" name="scope" value="day">
            <input type="hidden" name="settle_date" value="<?= e($day) ?>">
            <button class="btn gold" type="submit" data-primary-submit>
              <?= icon('check-circle', 15) ?> صفّي عهدة يوم <?= e(dfmt($day)) ?>
            </button>
          </form>
        <?php endif; ?>

        <?php if ($allDays > ($dayEmpty ? 0 : 1)): ?>
          <?php /* ⚠️ ده اللي بيصفّر التراكمي: تسوية لكل يوم على تاريخه هو */ ?>
          <form method="post" action="<?= url('rep-custody/' . $repId . '/settle-all') ?>"
                data-confirm="<?= e('تأكيد: تصفية ' . intf($allDays) . ' يوم مفتوح بإجمالي '
                    . intf(abs($allAmt)) . ' جنيه — كل يوم هيتقفل على تاريخه، والرصيد كله هيرجع صفر.') ?>">
            <?= csrfField() ?>
            <?= formStateFields(FormState::token(), $back) ?>
            <input type="hidden" name="scope" value="all">
            <button class="btn<?= $dayEmpty ? ' gold' : ' ghost' ?>" type="submit">
              <?= icon('check-circle', 15) ?> صفّي كل الأيام المفتوحة
              (<?= intf($allDays) ?> يوم · <?= intf(abs($allAmt)) ?>)
            </button>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

