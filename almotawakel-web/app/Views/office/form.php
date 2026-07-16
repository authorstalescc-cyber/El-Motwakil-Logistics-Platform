<?php $val = fn($k) => $row[$k] ?? ''; ?>
<div class="page-actions">
  <a class="btn ghost" href="<?= url('office') ?>">‹ رجوع لرصيد المكتب</a>
</div>

<form class="form-card" method="post" action="<?= e($action) ?>">
  <?= csrfField() ?>
  <div class="form-group-title gt-identity">بيانات كارت الفيزا</div>
  <div class="form-grid">
    <div class="field">
      <label>اسم / رقم الكارت *</label>
      <input type="text" name="name" value="<?= e($val('name')) ?>" placeholder="مثال: فيزا الأهلي 1234" required>
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
      <input type="number" step="0.01" name="init_amount" value="" placeholder="0">
    </div>
    <div class="field">
      <label>بتاريخ الرصيد المبدئي</label>
      <input type="date" name="init_date" value="<?= e(work_date()) ?>">
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
    <button class="btn gold" type="submit">💾 <?= $isEdit?'حفظ التعديلات':'حفظ الكارت' ?></button>
    <a class="btn ghost" href="<?= url('office') ?>">إلغاء</a>
  </div>
</form>
