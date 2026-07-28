<?php
/** نموذج إضافة/تعديل مصروف يدوي */
$val = fn($k) => $row[$k] ?? '';
$errors = $errors ?? [];
?>
<div class="page-actions">
  <a class="btn ghost" href="<?= url($back ?? 'expenses') ?>">‹ رجوع للسجل</a>
</div>

<?= formErrorSummary($errors) ?>
<form class="form-card js-dirty-form" method="post" action="<?= e($action) ?>" id="expForm">
  <?= csrfField() ?>
  <?= formStateFields($formToken, $back ?? 'expenses') ?>

  <?php if (empty($isEdit)): ?>
  <!-- تخصيص المصروف لمندوب (اختياري) — يتسجّل في حساب المندوب مباشرةً -->
  <div class="field expense-rep-panel">
    <div class="expense-rep-title"><?= icon('user', 14) ?>‍<?= icon('briefcase', 14) ?> مصروف / سُلفة لمندوب؟ (اختياري)</div>
    <div class="expense-rep-fields">
      <div class="field expense-rep-field">
        <label for="repSel">المندوب</label>
        <select name="rep_id" id="repSel"<?= fieldErrorAttrs('rep_id', $errors) ?>>
          <option value="">— مصروف عام (مش لمندوب) —</option>
          <?php foreach (($reps ?? []) as $rp): ?>
            <option value="<?= (int)$rp['id'] ?>" <?= (int)($repPre ?? 0)===(int)$rp['id']?'selected':'' ?>><?= e($rp['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?= fieldError('rep_id', $errors) ?>
      </div>
      <div class="field expense-rep-field expense-rep-kind" id="repKindWrap" hidden>
        <label for="repKind">نوع البند في حساب المندوب</label>
        <select name="rep_kind" id="repKind">
          <option value="advance">سُلفة / عهدة (كاش — يظهر في المصروفات)</option>
          <option value="expense_logistic">مصروف لوجيستي (على الشركة)</option>
          <option value="expense_deducted">مصروف يُخصم من المرتب</option>
          <option value="transport">مواصلات</option>
          <option value="bonus">مكافأة</option>
          <option value="deduction">خصم</option>
        </select>
      </div>
    </div>
    <div id="repHint" class="expense-rep-hint" role="status" aria-live="polite" hidden></div>
  </div>
  <?php endif; ?>

  <div class="form-group-title gt-expense">بيانات المصروف اليدوي</div>
  <div class="form-grid">
    <div class="field">
      <label for="expense-date">التاريخ</label>
      <input id="expense-date" type="date" name="exp_date" value="<?= e($val('exp_date')) ?>" required<?= fieldErrorAttrs('exp_date', $errors) ?>>
      <?= fieldError('exp_date', $errors) ?>
    </div>
    <div class="field expense-only">
      <label for="expense-category">الفئة</label>
      <input id="expense-category" list="dl_expense_cat" name="category" value="<?= e($val('category')) ?>" autocomplete="off" placeholder="إيجار / مرتبات / كهرباء…">
    </div>
    <div class="field expense-only">
      <label for="expense-beneficiary">الجهة / المستفيد</label>
      <input id="expense-beneficiary" type="text" name="beneficiary" value="<?= e($val('beneficiary')) ?>" placeholder="المدفوع له">
    </div>
    <div class="field">
      <label for="expense-amount">المبلغ</label>
      <input id="expense-amount" type="number" step="0.01" min="0.01" name="amount" value="<?= e($val('amount')) ?>" placeholder="0" required<?= fieldErrorAttrs('amount', $errors) ?>>
      <?= fieldError('amount', $errors) ?>
    </div>
    <div class="field field-full">
      <label for="expense-note">البيان / ملاحظة</label>
      <input id="expense-note" type="text" name="note" value="<?= e($val('note')) ?>">
    </div>
    <div class="field field-full">
      <label class="visa-toggle expense-visa-toggle" for="expense-is-visa">
        <input id="expense-is-visa" type="checkbox" name="is_visa" value="1" <?= !empty($val('is_visa'))?'checked':'' ?>>
        <?= icon('credit-card', 14) ?> مدفوع بالفيزا — لا يُحتسب ضمن مصروفات التقفيل اليومي (يظل في سجل المصروفات)
      </label>
    </div>
    <div class="field expense-only field-full">
      <label for="expense-visa-card"><?= icon('credit-card', 14) ?> الكارت المستخدَم (لو مدفوع بالفيزا — رصيد المكتب)</label>
      <?= visa_card_select($val('visa_card_id'), 'visa_card_id', '', 'expense-visa-card') ?>
    </div>
  </div>

  <div class="form-actions">
    <button class="btn gold" type="submit" id="expSubmit" data-primary-submit><?= icon('download', 14) ?> <?= $isEdit?'حفظ التعديلات':'حفظ المصروف' ?></button>
    <?php if (!$isEdit): ?><button class="btn ghost" type="submit" name="save_and_new" value="1">حفظ وإضافة آخر</button><?php endif; ?>
    <a class="btn ghost" href="<?= url($back ?? 'expenses') ?>">إلغاء</a>
  </div>
</form>

<datalist id="dl_expense_cat">
  <?php foreach (($cats ?? []) as $opt): ?><option value="<?= e($opt) ?>"><?php endforeach; ?>
</datalist>

<?php if (empty($isEdit)): $expenseJs=\App\Core\App::basePath('public/assets/js/expenses-form.js'); ?>
<script src="<?= url('assets/js/expenses-form.js') ?>?v=<?= is_file($expenseJs)?filemtime($expenseJs):1 ?>"></script>
<?php endif; ?>
