<?php
/**
 * إضافة/تعديل سيارة مكتب (v54).
 *
 * ⚠️ «تهجئات بديلة» مش رفاهية: نفس اللوحة بتتكتب بترتيب مختلف في البوالص
 *    («1474 ط ف ر» / «ط ف ر 1474»). مفتاح التطبيع بيمسك اختلاف الترتيب
 *    والمسافات لوحده، والبدائل للحالات اللي التطبيع مش بيوصلها (حروف
 *    مكتوبة غلط، أو رقم ناقص).
 */
$val = static fn (string $k) => $row[$k] ?? '';
$errors = $errors ?? [];
?>
<div class="page-actions">
  <?php partial('back_link', ['to' => $back ?? 'cars', 'label' => 'رجوع لسيارات المكتب']); ?>
</div>

<?= formErrorSummary($errors) ?>
<form class="form-card js-dirty-form" method="post" action="<?= e($action) ?>">
  <?= csrfField() ?>
  <?= formStateFields($formToken, $back ?? 'cars') ?>

  <div class="form-group-title gt-identity">بيانات السيارة</div>
  <div class="form-grid">
    <div class="field">
      <label for="c-plate">رقم اللوحة *</label>
      <input id="c-plate" type="text" name="plate" required value="<?= e($val('plate')) ?>"
             placeholder="مثال: س ع م 8737"<?= fieldErrorAttrs('plate', $errors) ?>>
      <?= fieldError('plate', $errors) ?>
      <span class="hint">الرحلات بتتربط بالسيارة عن طريق اللوحة دي — ترتيب الأرقام والحروف والمسافات مش بيفرق.</span>
    </div>
    <div class="field">
      <label for="c-name">اسم مختصر</label>
      <input id="c-name" type="text" name="name" value="<?= e($val('name')) ?>"
             placeholder="لو سبته فاضي هياخد رقم اللوحة">
    </div>
    <div class="field">
      <label for="c-model">الموديل / النوع</label>
      <input id="c-model" type="text" name="model" value="<?= e($val('model')) ?>" placeholder="مثال: فولفو FH 2018">
    </div>
    <div class="field">
      <label for="c-driver">السائق الثابت</label>
      <input id="c-driver" type="text" name="driver" value="<?= e($val('driver')) ?>">
      <span class="hint">للعرض بس — سائق كل رحلة بيتاخد من البوليصة نفسها.</span>
    </div>
    <div class="field">
      <label for="c-status">الحالة</label>
      <select id="c-status" name="status">
        <option value="active"<?= $val('status') !== 'inactive' ? ' selected' : '' ?>>شغّالة</option>
        <option value="inactive"<?= $val('status') === 'inactive' ? ' selected' : '' ?>>متوقفة</option>
      </select>
    </div>
    <div class="field">
      <label for="c-alias">تهجئات بديلة للّوحة</label>
      <input id="c-alias" type="text" name="aliases" value="<?= e($val('aliases')) ?>"
             placeholder="افصل بينهم بعلامة |">
      <span class="hint">لو نفس السيارة اتكتبت بشكل تاني في بوالص قديمة، حطّه هنا عشان رحلاتها تتحسب معاها.</span>
    </div>
    <div class="field field-full">
      <label for="c-note">ملاحظة</label>
      <input id="c-note" type="text" name="note" value="<?= e($val('note')) ?>">
    </div>
  </div>

  <div class="form-actions">
    <button class="btn gold" type="submit" data-primary-submit>
      <?= icon('check', 15) ?> <?= $isEdit ? 'حفظ التعديلات' : 'حفظ السيارة' ?></button>
    <a class="btn ghost" href="<?= url($back ?? 'cars') ?>">إلغاء</a>
  </div>
</form>

<?php if (!empty($isEdit) && !empty($row['id'])): ?>
  <div class="card">
    <div class="section-head"><?= icon('trash', 17) ?> حذف السيارة</div>
    <p style="color:var(--grey);margin:0 0 12px">
      الحذف بيشيل السيارة ومصاريفها من الوحدة بس — <b>البوالص ورحلاتها ما بتتلمسش</b>.
    </p>
    <form method="post" action="<?= e(url('cars/' . (int) $row['id'] . '/delete')) ?>"
          data-confirm="متأكد إنك عايز تشيل السيارة دي ومصاريفها؟">
      <?= csrfField() ?>
      <button class="btn ghost" type="submit"><?= icon('trash', 15) ?> احذف السيارة</button>
    </form>
  </div>
<?php endif; ?>
