<?php $val=fn($k)=>$row[$k]??''; $errors=$errors??[]; ?>
<div class="page-actions"><a class="btn ghost" href="<?= url($back ?? 'office') ?>">‹ رجوع</a></div>
<?= formErrorSummary($errors) ?>
<form class="form-card js-dirty-form" method="post" action="<?= e($action) ?>">
  <?= csrfField() ?><?= formStateFields($formToken,$back ?? 'office') ?>
  <div class="form-group-title gt-identity">بيانات كارت الفيزا</div>
  <?php if (!$accounts): ?>
    <div class="flash warning">لازم تضيف حسابًا بنكيًا أولًا. <a href="<?= url('office/accounts/new') ?>">إضافة حساب</a></div>
  <?php endif; ?>
  <div class="form-grid">
    <div class="field"><label>اسم / رقم الكارت *</label>
      <input type="text" name="name" value="<?= e($val('name')) ?>" placeholder="مثال: فيزا 1234" required<?= fieldErrorAttrs('name',$errors) ?>>
      <?= fieldError('name',$errors) ?></div>
    <div class="field"><label>الحساب البنكي *</label>
      <select name="bank_account_id" required<?= fieldErrorAttrs('bank_account_id',$errors) ?>>
        <option value="">— اختر الحساب —</option>
        <?php foreach ($accounts as $a): ?>
          <option value="<?= (int)$a['id'] ?>" <?= (int)$val('bank_account_id')===(int)$a['id']?'selected':'' ?>>
            <?= e($a['name']) ?><?= $a['bank_name']?' — '.e($a['bank_name']):'' ?><?= $a['status']==='inactive'?' (متوقف)':'' ?>
          </option>
        <?php endforeach; ?>
      </select><?= fieldError('bank_account_id',$errors) ?></div>
    <div class="field"><label>صاحب الكارت</label><input type="text" name="owner" value="<?= e($val('owner')) ?>"></div>
    <div class="field"><label>الحالة</label><select name="status">
      <option value="active" <?= $val('status')!=='inactive'?'selected':'' ?>>نشط</option>
      <option value="inactive" <?= $val('status')==='inactive'?'selected':'' ?>>متوقف</option>
    </select></div>
    <div class="field" style="grid-column:1/-1"><label>ملاحظة</label><input type="text" name="note" value="<?= e($val('note')) ?>"></div>
  </div>
  <div class="form-actions"><button class="btn gold" type="submit" data-primary-submit <?= !$accounts?'disabled':'' ?>>
    <?= icon('download',14) ?> <?= $isEdit?'حفظ التعديلات':'حفظ الكارت' ?></button>
    <a class="btn ghost" href="<?= url($back ?? 'office') ?>">إلغاء</a></div>
</form>
