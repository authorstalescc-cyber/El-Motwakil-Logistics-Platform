<?php
use App\Models\OfficeDriverStatement;

$cid = (int) $car['id'];
$bid = (int) $bill['id'];
$old = $state['old'] ?? [];

if (!empty($old['item_category']) && is_array($old['item_category'])) {
  $items = [];
  foreach ($old['item_category'] as $i => $cat) {
    $items[] = [
      'category' => $cat,
      'description' => $old['item_description'][$i] ?? '',
      'amount' => $old['item_amount'][$i] ?? '',
    ];
  }
}
if (!empty($old['move_kind']) && is_array($old['move_kind'])) {
  $movements = [];
  foreach ($old['move_kind'] as $i => $kind) {
    $movements[] = [
      'kind' => $kind,
      'move_date' => $old['move_move_date'][$i] ?? '',
      'note' => $old['move_note'][$i] ?? '',
      'amount' => $old['move_amount'][$i] ?? '',
    ];
  }
}

$blankItem = ['category' => 'other_expense', 'description' => '', 'amount' => ''];
$rows = $items ?: [$blankItem];
$moveRows = $movements ?? [];
$billNo = $bill['bill_number'] ?: ('#' . $bid);
?>
<div class="dash2 od-page od-bill-page">
  <div class="page-actions">
    <?php partial('back_link', ['to' => $back, 'label' => 'حساب السائق']); ?>
  </div>

  <section class="od-hero od-bill-hero">
    <div class="od-driver">
      <span class="od-avatar"><?= icon('truck', 24) ?></span>
      <div>
        <span class="od-kicker">بيان نقلة</span>
        <h1>بوليصة <?= e($billNo) ?></h1>
        <div class="od-driver-meta">
          <span><?= icon('user', 13) ?> <?= e($bill['driver'] ?: ($car['driver'] ?: '—')) ?></span>
          <span><?= icon('map-pin', 13) ?> <?= e($bill['destination'] ?: '—') ?></span>
          <span><?= icon('calendar', 13) ?> <?= e($bill['bill_date'] ? dfmt($bill['bill_date']) : '—') ?></span>
        </div>
      </div>
    </div>
    <div class="od-balance">
      <span>عهدة النقلة</span>
      <b><?= e(money0($bill['custody'] ?? 0)) ?></b>
      <?php if ($bill['permit_no']): ?><small>إذن <?= e($bill['permit_no']) ?></small><?php endif; ?>
    </div>
  </section>

  <?= formErrorSummary($state['errors'] ?? []) ?>
  <form method="post" action="<?= e(url('cars/' . $cid . '/driver/bill/' . $bid)) ?>"
        class="js-dirty-form od-bill-form" id="driver-bill-form">
    <?= csrfField() . formStateFields($state['token'], $back) ?>

    <?php partial('panel', [
      'title' => 'مصروفات النقلة',
      'icon' => 'coins',
      'badge' => $items ? ['text' => intf(count($items)), 'tone' => 'navy'] : null,
      'class' => 'mb',
      'body' => function () use ($rows) {
        ?><div class="od-entry-list" id="expense-lines"><?php
        foreach ($rows as $i => $r) {
          ?><div class="od-entry-row">
            <span class="od-line-no"><?= intf($i + 1) ?></span>
            <div class="field">
              <label>النوع</label>
              <select name="item_category[]"><?php foreach (OfficeDriverStatement::CATEGORIES as $k => $label): ?>
                <option value="<?= e($k) ?>"<?= ($r['category'] ?? 'other_expense') === $k ? ' selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?></select>
            </div>
            <div class="field od-entry-desc">
              <label>البيان</label>
              <input name="item_description[]" value="<?= e($r['description'] ?? '') ?>"
                     placeholder="مثال: طرق وكباري">
            </div>
            <div class="field od-entry-amount">
              <label>المبلغ</label>
              <input class="js-driver-expense" type="number" step="0.01" min="0"
                     name="item_amount[]" value="<?= e($r['amount'] ?? '') ?>" placeholder="0">
            </div>
            <button class="od-remove js-remove-line" type="button" title="حذف السطر"
                    aria-label="حذف السطر"><?= icon('trash', 14) ?></button>
          </div><?php
        }
        ?></div>
        <div class="od-panel-actions">
          <button class="btn ghost sm" type="button" id="add-expense-line"><?= icon('plus', 14) ?> إضافة مصروف</button>
        </div><?php
      },
    ]); ?>

    <?php partial('panel', [
      'title' => 'إيصالات دفعها السائق',
      'icon' => 'clipboard',
      'badge' => $receipts ? ['text' => intf(count($receipts)), 'tone' => 'navy'] : null,
      'class' => 'mb',
      'body' => function () use ($receipts, $old) {
        if (!$receipts) {
          partial('empty', ['icon' => 'clipboard', 'title' => 'لا توجد إيصالات متاحة']);
          return;
        }
        echo '<div class="od-receipt-list">';
        foreach ($receipts as $r) {
          $posted = $old['receipt_amount'][$r['id']] ?? $r['this_amount'];
          $other = max(0, (float) $r['allocated_total'] - (float) $r['this_amount']);
          $max = max(0, (float) $r['amount'] - $other);
          ?><div class="od-receipt">
            <span class="od-receipt-icon"><?= icon('clipboard', 18) ?></span>
            <div class="od-receipt-body">
              <b><?= e($r['note'] ?: 'إيصال') ?></b>
              <div>
                <span><?= icon('calendar', 12) ?> <?= e(dfmt($r['entry_date'])) ?></span>
                <?php if ($r['ref_no']): ?><span><?= icon('file-text', 12) ?> <?= e($r['ref_no']) ?></span><?php endif; ?>
                <span>الإجمالي <?= e(money0($r['amount'], false)) ?></span>
                <?php if ($other > 0): ?><span>مخصص <?= e(money0($other, false)) ?></span><?php endif; ?>
              </div>
            </div>
            <label class="od-receipt-input">
              <span>دفع السائق</span>
              <input class="js-driver-expense" type="number" step="0.01" min="0" max="<?= e($max) ?>"
                     name="receipt_amount[<?= (int) $r['id'] ?>]"
                     value="<?= e((float) $posted > 0 ? $posted : '') ?>" placeholder="0">
            </label>
          </div><?php
        }
        echo '</div>';
      },
    ]); ?>

    <details class="d-card od-fold mb" id="bill-movements"<?= $moveRows ? ' open' : '' ?>>
      <summary class="d-card-head">
        <h3><?= icon('wallet', 18) ?><span>عهدة إضافية ومردود</span>
          <?php if ($moveRows): ?><span class="d-badge navy"><?= intf(count($moveRows)) ?></span><?php endif; ?>
        </h3>
        <span class="od-fold-arrow"><?= icon('chevron', 15) ?></span>
      </summary>
      <div class="od-entry-list" id="move-lines">
        <?php foreach ($moveRows as $i => $m): ?>
          <div class="od-entry-row od-move-row">
            <span class="od-line-no"><?= intf($i + 1) ?></span>
            <div class="field">
              <label>الحركة</label>
              <select class="js-driver-move-kind" name="move_kind[]"><?php foreach (OfficeDriverStatement::MOVEMENT_KINDS as $k => $def): ?>
                <option value="<?= e($k) ?>"<?= ($m['kind'] ?? 'advance') === $k ? ' selected' : '' ?>><?= e($def['label']) ?></option>
              <?php endforeach; ?></select>
            </div>
            <div class="field">
              <label>التاريخ</label>
              <input type="date" name="move_move_date[]" value="<?= e($m['move_date'] ?? ($bill['bill_date'] ?: work_date())) ?>">
            </div>
            <div class="field od-entry-desc">
              <label>البيان</label>
              <input name="move_note[]" value="<?= e($m['note'] ?? '') ?>" placeholder="سبب الحركة">
            </div>
            <div class="field od-entry-amount">
              <label>المبلغ</label>
              <input class="js-driver-move" type="number" step="0.01" min="0"
                     name="move_amount[]" value="<?= e($m['amount'] ?? '') ?>" placeholder="0">
            </div>
            <button class="od-remove js-remove-line" type="button" title="حذف السطر"
                    aria-label="حذف السطر"><?= icon('trash', 14) ?></button>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="od-panel-actions">
        <button class="btn ghost sm" type="button" id="add-move-line"><?= icon('plus', 14) ?> إضافة حركة</button>
      </div>
    </details>

    <div class="od-totalbar">
      <div><span>العهدة</span><b><?= e(money0($bill['custody'] ?? 0, false)) ?></b></div>
      <div><span>المنصرف</span><b id="driver-total">0</b></div>
      <div><span>باقي النقلة</span><b id="driver-net">—</b></div>
      <button class="btn primary" type="submit"><?= icon('check', 15) ?> حفظ البيان</button>
    </div>
  </form>
</div>

<template id="expense-row-template">
  <div class="od-entry-row">
    <span class="od-line-no"></span>
    <div class="field"><label>النوع</label><select name="item_category[]"><?php foreach (OfficeDriverStatement::CATEGORIES as $k => $label): ?>
      <option value="<?= e($k) ?>"><?= e($label) ?></option>
    <?php endforeach; ?></select></div>
    <div class="field od-entry-desc"><label>البيان</label><input name="item_description[]" placeholder="مثال: طرق وكباري"></div>
    <div class="field od-entry-amount"><label>المبلغ</label><input class="js-driver-expense" type="number" step="0.01" min="0" name="item_amount[]" placeholder="0"></div>
    <button class="od-remove js-remove-line" type="button" title="حذف السطر" aria-label="حذف السطر"><?= icon('trash', 14) ?></button>
  </div>
</template>

<template id="move-row-template">
  <div class="od-entry-row od-move-row">
    <span class="od-line-no"></span>
    <div class="field"><label>الحركة</label><select class="js-driver-move-kind" name="move_kind[]"><?php foreach (OfficeDriverStatement::MOVEMENT_KINDS as $k => $def): ?>
      <option value="<?= e($k) ?>"><?= e($def['label']) ?></option>
    <?php endforeach; ?></select></div>
    <div class="field"><label>التاريخ</label><input type="date" name="move_move_date[]" value="<?= e($bill['bill_date'] ?: work_date()) ?>"></div>
    <div class="field od-entry-desc"><label>البيان</label><input name="move_note[]" placeholder="سبب الحركة"></div>
    <div class="field od-entry-amount"><label>المبلغ</label><input class="js-driver-move" type="number" step="0.01" min="0" name="move_amount[]" placeholder="0"></div>
    <button class="od-remove js-remove-line" type="button" title="حذف السطر" aria-label="حذف السطر"><?= icon('trash', 14) ?></button>
  </div>
</template>

<script>
(function () {
  var form = document.getElementById('driver-bill-form');
  var expenseBody = document.getElementById('expense-lines');
  var moveBody = document.getElementById('move-lines');
  var expenseTemplate = document.getElementById('expense-row-template');
  var moveTemplate = document.getElementById('move-row-template');

  function renumber(list) {
    list.querySelectorAll('.od-entry-row').forEach(function (row, index) {
      var no = row.querySelector('.od-line-no');
      if (no) no.textContent = (index + 1).toLocaleString('ar-EG');
    });
  }
  function add(list, template) {
    list.appendChild(template.content.cloneNode(true));
    renumber(list);
    var input = list.lastElementChild && list.lastElementChild.querySelector('input,select');
    if (input) input.focus();
  }
  function signed(value) {
    return (value < 0 ? '−' : '') + Math.abs(value).toLocaleString('ar-EG') + ' ج.م';
  }
  function calc() {
    var total = 0;
    var moveNet = 0;
    form.querySelectorAll('.js-driver-expense').forEach(function (input) {
      total += parseFloat(input.value) || 0;
    });
    moveBody.querySelectorAll('.od-entry-row').forEach(function (row) {
      var amount = parseFloat((row.querySelector('.js-driver-move') || {}).value) || 0;
      var kind = (row.querySelector('.js-driver-move-kind') || {}).value || '';
      moveNet += (kind === 'advance' || kind === 'credit_adjustment') ? amount : -amount;
    });
    var custody = <?= js_json((float) ($bill['custody'] ?? 0)) ?>;
    var net = custody + moveNet - total;
    var totalNode = document.getElementById('driver-total');
    var netNode = document.getElementById('driver-net');
    totalNode.textContent = total.toLocaleString('ar-EG') + ' ج.م';
    netNode.textContent = signed(net);
    netNode.classList.toggle('neg', net < 0);
    netNode.classList.toggle('pos', net >= 0);
  }

  document.getElementById('add-expense-line').addEventListener('click', function () {
    add(expenseBody, expenseTemplate);
  });
  document.getElementById('add-move-line').addEventListener('click', function () {
    add(moveBody, moveTemplate);
  });
  form.addEventListener('click', function (event) {
    var button = event.target.closest('.js-remove-line');
    if (!button) return;
    var row = button.closest('.od-entry-row');
    var list = row.parentElement;
    row.remove();
    renumber(list);
    calc();
  });
  form.addEventListener('input', function (event) {
    if (event.target.matches('.js-driver-expense,.js-driver-move')) calc();
  });
  form.addEventListener('change', function (event) {
    if (event.target.classList.contains('js-driver-move-kind')) calc();
  });

  renumber(expenseBody);
  renumber(moveBody);
  calc();
})();
</script>
