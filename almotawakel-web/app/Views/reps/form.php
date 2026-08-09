<?php
/** نموذج إضافة/تعديل كارت مندوب. @var array $row @var string $action @var bool $isEdit */
$val = fn($k) => $row[$k] ?? '';
?>
<div class="page-actions">
  <a class="btn ghost" href="<?= url('reps') ?>">‹ رجوع للمناديب</a>
</div>

<?php if (($formErr ?? '') !== ''): ?>
  <div class="flash error" role="alert"><?= icon('alert',18) ?> <?= e($formErr) ?> — اللي كتبته زي ما هو تحت.</div>
<?php endif; ?>
<form class="form-card" method="post" action="<?= e($action) ?>">
  <?= csrfField() ?>
  <div class="form-group-title">بيانات المندوب</div>
  <div class="form-grid">
    <div class="field">
      <label>الاسم <span style="color:var(--red-fg)">*</span></label>
      <input type="text" name="name" value="<?= e($val('name')) ?>" required autofocus placeholder="اسم المندوب">
    </div>
    <div class="field">
      <label>التليفون</label>
      <input type="text" name="phone" value="<?= e($val('phone')) ?>" placeholder="01xxxxxxxxx" dir="ltr">
    </div>
    <div class="field">
      <label>المرتب الشهري</label>
      <input type="number" step="0.01" name="monthly_salary" value="<?= e($val('monthly_salary')) ?>" placeholder="0">
    </div>
    <div class="field">
      <label>تاريخ التعيين</label>
      <input type="date" name="hire_date" value="<?= e($val('hire_date')) ?>">
    </div>
    <div class="field">
      <label>الحالة</label>
      <select name="status">
        <option value="active"   <?= $val('status')!=='inactive'?'selected':'' ?>>شغّال</option>
        <option value="inactive" <?= $val('status')==='inactive'?'selected':'' ?>>موقوف</option>
      </select>
    </div>
    <div class="field" style="grid-column:1/-1">
      <label>ملاحظات</label>
      <input type="text" name="note" value="<?= e($val('note')) ?>" placeholder="أي ملاحظة (اختياري)">
    </div>
  </div>

  <div class="form-actions">
    <button class="btn gold" type="submit"><?= icon('download', 14) ?> <?= $isEdit?'حفظ التعديلات':'حفظ المندوب' ?></button>
    <a class="btn ghost" href="<?= url('reps') ?>">إلغاء</a>
  </div>
</form>
