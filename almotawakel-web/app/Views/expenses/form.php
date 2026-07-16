<?php
/** نموذج إضافة/تعديل مصروف يدوي */
$val = fn($k) => $row[$k] ?? '';
?>
<div class="page-actions">
  <a class="btn ghost" href="<?= url('expenses') ?>">‹ رجوع للسجل</a>
</div>

<form class="form-card" method="post" action="<?= e($action) ?>" id="expForm">
  <?= csrfField() ?>

  <?php if (empty($isEdit)): ?>
  <!-- تخصيص المصروف لمندوب (اختياري) — يتسجّل في حساب المندوب مباشرةً -->
  <div class="field" style="grid-column:1/-1;background:var(--bg);border:1px solid var(--line);border-radius:12px;padding:12px 14px;margin-bottom:14px">
    <label style="font-weight:800">🧑‍💼 مصروف / سُلفة لمندوب؟ (اختياري)</label>
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:end;margin-top:6px">
      <div class="field" style="margin:0;min-width:200px;flex:1">
        <label>المندوب</label>
        <select name="rep_id" id="repSel">
          <option value="">— مصروف عام (مش لمندوب) —</option>
          <?php foreach (($reps ?? []) as $rp): ?>
            <option value="<?= (int)$rp['id'] ?>" <?= (int)($repPre ?? 0)===(int)$rp['id']?'selected':'' ?>><?= e($rp['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field" id="repKindWrap" style="margin:0;min-width:220px;flex:1;display:none">
        <label>نوع البند في حساب المندوب</label>
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
    <div id="repHint" style="display:none;color:var(--grey);font-size:.82rem;margin-top:8px"></div>
  </div>
  <?php endif; ?>

  <div class="form-group-title gt-expense">بيانات المصروف اليدوي</div>
  <div class="form-grid">
    <div class="field">
      <label>التاريخ</label>
      <input type="date" name="exp_date" value="<?= e($val('exp_date')) ?>">
    </div>
    <div class="field expense-only">
      <label>الفئة</label>
      <input list="dl_expense_cat" name="category" value="<?= e($val('category')) ?>" autocomplete="off" placeholder="إيجار / مرتبات / كهرباء…">
    </div>
    <div class="field expense-only">
      <label>الجهة / المستفيد</label>
      <input type="text" name="beneficiary" value="<?= e($val('beneficiary')) ?>" placeholder="المدفوع له">
    </div>
    <div class="field">
      <label>المبلغ</label>
      <input type="number" step="0.01" name="amount" value="<?= e($val('amount')) ?>" placeholder="0" required>
    </div>
    <div class="field" style="grid-column:1/-1">
      <label>البيان / ملاحظة</label>
      <input type="text" name="note" value="<?= e($val('note')) ?>">
    </div>
    <div class="field" style="grid-column:1/-1">
      <label class="visa-toggle" style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:700">
        <input type="checkbox" name="is_visa" value="1" <?= !empty($val('is_visa'))?'checked':'' ?> style="width:18px;height:18px;accent-color:var(--gold)">
        💳 مدفوع بالفيزا — لا يُحتسب ضمن مصروفات التقفيل اليومي (يظل في سجل المصروفات)
      </label>
    </div>
    <div class="field expense-only" style="grid-column:1/-1">
      <label>💳 الكارت المستخدَم (لو مدفوع بالفيزا — رصيد المكتب)</label>
      <?= visa_card_select($val('visa_card_id')) ?>
    </div>
  </div>

  <div class="form-actions">
    <button class="btn gold" type="submit" id="expSubmit">💾 <?= $isEdit?'حفظ التعديلات':'حفظ المصروف' ?></button>
    <a class="btn ghost" href="<?= url('expenses') ?>">إلغاء</a>
  </div>
</form>

<datalist id="dl_expense_cat">
  <?php foreach (($cats ?? []) as $opt): ?><option value="<?= e($opt) ?>"><?php endforeach; ?>
</datalist>

<?php if (empty($isEdit)): ?>
<script>
(function(){
  var sel = document.getElementById('repSel');
  if (!sel) return;
  var kindWrap = document.getElementById('repKindWrap');
  var kind     = document.getElementById('repKind');
  var hint     = document.getElementById('repHint');
  var submit   = document.getElementById('expSubmit');
  var expenseOnly = document.querySelectorAll('.expense-only');

  var CASH = {advance:1, settlement:1};
  function sync(){
    var isRep = sel.value !== '';
    kindWrap.style.display = isRep ? '' : 'none';
    expenseOnly.forEach(function(el){ el.style.display = isRep ? 'none' : ''; });
    if (isRep) {
      var k = kind.value;
      hint.style.display = '';
      hint.textContent = CASH[k]
        ? 'هيتسجّل في حساب المندوب ويظهر في المصروفات العامة ويتخصم من العهدة (كاش خارج من الخزنة).'
        : 'هيتسجّل في حساب المندوب بس، وبيتحاسب عليه وقت تقفيل حساب المندوب (مش بيتخصم من العهدة دلوقتي).';
      submit.textContent = '💾 حفظ في حساب المندوب';
    } else {
      hint.style.display = 'none';
      submit.textContent = '💾 حفظ المصروف';
    }
  }
  sel.addEventListener('change', sync);
  kind.addEventListener('change', sync);
  sync();
})();
</script>
<?php endif; ?>
