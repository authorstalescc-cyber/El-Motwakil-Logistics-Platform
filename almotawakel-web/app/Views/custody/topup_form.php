<?php /** نموذج تعديل إضافة عهدة يدوية (توب-أب للرصيد) */ $val = fn($k) => $row[$k] ?? ''; ?>
<div class="page-actions">
  <a class="btn ghost" href="<?= url('custody') ?>">‹ رجوع لرصيد العهدة</a>
</div>

<form class="form-card" method="post" action="<?= e($action) ?>">
  <?= csrfField() ?>
  <div class="form-group-title gt-revenue">تعديل إضافة عهدة</div>
  <div class="form-grid">
    <div class="field">
      <label>التاريخ</label>
      <input type="date" name="cust_date" value="<?= e($val('cust_date')) ?>">
    </div>
    <div class="field">
      <label>المبلغ</label>
      <input type="number" step="0.01" name="amount" value="<?= e($val('amount')) ?>" placeholder="0" required>
    </div>
    <div class="field" style="grid-column:1/-1">
      <label>البيان / المصدر</label>
      <input type="text" name="note" value="<?= e($val('note')) ?>" placeholder="مصدر العهدة (اختياري)">
    </div>
  </div>
  <div class="form-actions">
    <button class="btn gold" type="submit">💾 حفظ التعديلات</button>
    <a class="btn ghost" href="<?= url('custody') ?>">إلغاء</a>
  </div>
</form>
