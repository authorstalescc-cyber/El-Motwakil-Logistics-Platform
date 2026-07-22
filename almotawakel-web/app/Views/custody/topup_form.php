<?php /** نموذج تعديل إضافة عهدة يدوية (توب-أب للرصيد) */ $val = fn($k) => $row[$k] ?? ''; $errors = $errors ?? []; ?>
<div class="page-actions">
  <a class="btn ghost" href="<?= url($back ?? 'custody') ?>">‹ رجوع لرصيد العهدة</a>
</div>

<?= formErrorSummary($errors) ?>
<form class="form-card js-dirty-form" method="post" action="<?= e($action) ?>">
  <?= csrfField() ?>
  <?= formStateFields($formToken, $back ?? 'custody') ?>
  <div class="form-group-title gt-revenue">تعديل إضافة عهدة</div>
  <div class="form-grid">
    <div class="field">
      <label>التاريخ</label>
      <input type="date" name="cust_date" value="<?= e($val('cust_date')) ?>"<?= fieldErrorAttrs('cust_date', $errors) ?>>
      <?= fieldError('cust_date', $errors) ?>
    </div>
    <div class="field">
      <label>المبلغ</label>
      <input type="number" step="0.01" min="0.01" name="amount" value="<?= e($val('amount')) ?>" placeholder="0" required<?= fieldErrorAttrs('amount', $errors) ?>>
      <?= fieldError('amount', $errors) ?>
    </div>
    <div class="field" style="grid-column:1/-1">
      <label>البيان / المصدر</label>
      <input type="text" name="note" value="<?= e($val('note')) ?>" placeholder="مصدر العهدة (اختياري)">
    </div>
  </div>
  <div class="form-actions">
    <button class="btn gold" type="submit" data-primary-submit>💾 حفظ التعديلات</button>
    <a class="btn ghost" href="<?= url($back ?? 'custody') ?>">إلغاء</a>
  </div>
</form>
