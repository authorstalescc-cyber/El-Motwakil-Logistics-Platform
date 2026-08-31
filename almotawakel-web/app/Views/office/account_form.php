<?php $val=fn($k)=>$row[$k]??''; $errors=$errors??[]; ?>
<?= formErrorSummary($errors) ?>
<form class="form-card js-dirty-form" method="post" action="<?= e($action) ?>">
  <?= csrfField() ?><?= formStateFields($formToken,$back ?? 'office') ?>
  <div class="form-group-title gt-identity">بيانات الحساب البنكي</div>
  <div class="form-grid">
    <div class="field"><label>اسم الحساب داخل النظام *</label><input name="name" value="<?= e($val('name')) ?>" placeholder="مثال: حساب الأهلي الرئيسي" required<?= fieldErrorAttrs('name',$errors) ?>><?= fieldError('name',$errors) ?></div>
    <div class="field"><label>اسم البنك</label><input name="bank_name" value="<?= e($val('bank_name')) ?>" placeholder="البنك الأهلي المصري"></div>
    <div class="field"><label>مرجع / آخر أرقام الحساب</label><input name="account_ref" value="<?= e($val('account_ref')) ?>" placeholder="مثال: 7788"></div>
    <div class="field"><label>صاحب الحساب</label><input name="owner" value="<?= e($val('owner')) ?>"></div>
    <?php if (!$isEdit): ?>
      <div class="field"><label>رصيد مبدئي (اختياري)</label><input data-money type="number" min="0" step="0.01" name="init_amount" value="<?= e($val('init_amount')) ?>"<?= fieldErrorAttrs('init_amount',$errors) ?>><?= fieldError('init_amount',$errors) ?></div>
      <div class="field"><label>تاريخ الرصيد</label><input type="date" name="init_date" value="<?= e($val('init_date') ?: work_date()) ?>"></div>
    <?php endif; ?>
    <div class="field"><label>الحالة</label><select name="status"<?= fieldErrorAttrs('status',$errors) ?>><option value="active" <?= $val('status')!=='inactive'?'selected':'' ?>>نشط</option><option value="inactive" <?= $val('status')==='inactive'?'selected':'' ?>>متوقف</option></select><?= fieldError('status',$errors) ?></div>
    <div class="field" style="grid-column:1/-1"><label>ملاحظة</label><input name="note" value="<?= e($val('note')) ?>"></div>
  </div>
  <div class="form-actions"><button class="btn gold" type="submit" data-primary-submit><?= icon('download',14) ?> <?= $isEdit?'حفظ التعديلات':'حفظ الحساب' ?></button><a class="btn ghost" href="<?= url($back ?? 'office') ?>">إلغاء</a></div>
</form>
