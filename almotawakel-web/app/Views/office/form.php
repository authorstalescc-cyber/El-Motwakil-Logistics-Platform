<?php $val = fn($k) => $row[$k] ?? ''; $errors = $errors ?? []; ?>
<div class="page-actions">
  <a class="btn ghost" href="<?= url($back ?? 'office') ?>">‹ رجوع لرصيد المكتب</a>
</div>

<?= formErrorSummary($errors) ?>
<form class="form-card js-dirty-form" method="post" action="<?= e($action) ?>">
  <?= csrfField() ?>
  <?= formStateFields($formToken, $back ?? 'office') ?>
  <div class="form-group-title gt-identity">بيانات كارت الفيزا</div>
  <div class="form-grid">
    <div class="field">
      <label>اسم / رقم الكارت *</label>
      <input type="text" name="name" value="<?= e($val('name')) ?>" placeholder="مثال: فيزا الأهلي 1234" required<?= fieldErrorAttrs('name', $errors) ?>>
      <?= fieldError('name', $errors) ?>
    </div>
    <div class="field">
      <label>صاحب الكارت</label>
      <input type="text" name="owner" value="<?= e($val('owner')) ?>" placeholder="اسم مالك الفيزا">
    </div>
    <div class="field">
      <label>البنك</label>
      <input type="text" name="bank" value="<?= e($val('bank')) ?>" placeholder="اسم البنك">
    </div>
    <?php if (!$isEdit): ?>
    <div class="field">
      <label>رصيد مبدئي (اختياري)</label>
      <input data-money type="number" step="0.01" name="init_amount" value="<?= e($val('init_amount')) ?>" placeholder="0">
    </div>
    <div class="field">
      <label>بتاريخ الرصيد المبدئي</label>
      <input type="date" name="init_date" value="<?= e($val('init_date') ?: work_date()) ?>">
    </div>
    <?php endif; ?>
    <div class="field">
      <label>الحالة</label>
      <select name="status">
        <option value="active" <?= $val('status')!=='inactive'?'selected':'' ?>>نشط</option>
        <option value="inactive" <?= $val('status')==='inactive'?'selected':'' ?>>متوقف</option>
      </select>
    </div>
    <div class="field" style="grid-column:1/-1">
      <label>ملاحظة</label>
      <input type="text" name="note" value="<?= e($val('note')) ?>">
    </div>
  </div>

  <div class="form-actions">
    <button class="btn gold" type="submit" data-primary-submit><?= icon('download', 14) ?> <?= $isEdit?'حفظ التعديلات':'حفظ الكارت' ?></button>
    <a class="btn ghost" href="<?= url($back ?? 'office') ?>">إلغاء</a>
  </div>
</form>
