<?php
/**
 * خانة «مدفوع بالفيزا» في بوابة المندوب (v45) — مشتركة بين فورم البند والنثرية.
 *
 * ⚠️ **ممنوع تعرض رصيد أي كارت** (قاعدة v35: مفيش أرقام المكتب على شاشة المندوب)
 *    — أسامي الكروت بس عشان يختار اللي في إيده.
 *
 * ⚠️ ما بتستخدمش `visa_card_select()` بتاعة شاشات المكتب عن قصد: دي محتاجة
 *    ماركب مختلف (خانة الخطأ + aria + إخفاء/إظهار بالـJS) وبتقرا كروت نشطة بس.
 *
 * بيتوقّع: $o (قارئ القيم القديمة) · $err (قارئ الأخطاء) · $visaCards (مصفوفة)
 */
$visaOn = (string) $o('is_visa') === '1';
$selectedCard = (string) $o('visa_card_id');
?>
<div class="field visa-field" id="field-visa_card_id" <?= $err('visa_card_id') ? 'data-error="1"' : '' ?>>
  <?php if (!$visaCards): ?>
    <div class="hint">مفيش كروت فيزا مسجّلة — كلّم المكتب لو دفعت بكارت.</div>
  <?php else: ?>
    <label class="visa-toggle" for="visaChk">
      <input type="checkbox" id="visaChk" name="is_visa" value="1" <?= $visaOn ? 'checked' : '' ?>>
      <span><?= icon('credit-card', 14) ?> دفعت بكارت الفيزا</span>
    </label>
    <div class="hint" id="visaHint">سيبها فاضية لو دفعت كاش من العهدة اللي معاك.</div>

    <div class="visa-pick" id="visaPick" <?= $visaOn ? '' : 'hidden' ?>>
      <label for="visaCard">أنهي كارت؟</label>
      <select id="visaCard" name="visa_card_id" aria-describedby="err-visa_card_id"
              <?= $err('visa_card_id') ? 'aria-invalid="true"' : '' ?>>
        <option value="">— اختر الكارت —</option>
        <?php foreach ($visaCards as $vc): ?>
          <option value="<?= (int) $vc['id'] ?>" <?= $selectedCard === (string) $vc['id'] ? 'selected' : '' ?>>
            <?= e($vc['name']) ?><?= $vc['owner'] ? ' — ' . e($vc['owner']) : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <p class="field-error" id="err-visa_card_id" <?= $err('visa_card_id') ? '' : 'hidden' ?>><?= e($err('visa_card_id')) ?></p>
  <?php endif; ?>
</div>
