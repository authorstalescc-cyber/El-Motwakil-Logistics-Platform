<?php
$fmt = static function (array $field, string $side): string {
    $value = $field[$side] ?? null;
    if ($value === null || trim((string) $value) === '') { return '—'; }
    if (in_array($field['kind'], ['money','payment'], true)) { return money((float)$value, false); }
    if ($field['kind'] === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$value)) { return dfmt((string)$value); }
    return (string)$value;
};
$byGroup = [];
foreach ($fields as $field) { $byGroup[$field['group']][] = $field; }
$selectableCount = count(array_filter($fields, static fn($f) => !empty($f['selectable'])));
?>

<div class="page-head">
  <div>
    <span class="eyebrow">صف Excel <?= intf($row['excel_row']) ?></span>
    <h1><?= icon('clipboard', 24) ?> بوليصة <?= e($row['bill_number']) ?></h1>
    <p>النظام وExcel جنبًا إلى جنب. علّم فقط على القيم التي تريد نقلها إلى النظام.</p>
  </div>
  <?php partial('back_link', ['to' => 'bawalis/excel-sync', 'label' => 'رجوع للقائمة']); ?>
</div>

<?php if (empty($sourceState['ready'])): ?>
  <div class="flash warning"><?= icon('alert', 17) ?> <?= e($sourceState['reason'] ?? 'حدّث ملف Excel أولًا.') ?> الاختيار متوقف حتى رفع أحدث نسخة.</div>
<?php endif; ?>
<?php foreach ($row['conflicts'] as $message): ?><div class="flash error"><?= icon('alert', 17) ?> <?= e($message) ?></div><?php endforeach; ?>
<?php foreach ($row['warnings'] as $message): ?><div class="flash warning"><?= icon('alert', 17) ?> <?= e($message) ?></div><?php endforeach; ?>

<div class="excel-compare-head card">
  <div><small>بوليصة النظام</small><b><?= e($row['bill']['bill_number'] ?? '—') ?></b><span><?= e($row['bill']['declaration_owner'] ?? '—') ?></span></div>
  <div><small>صف Excel</small><b><?= e($row['bill_number'] ?? '—') ?></b><span><?= e($row['declaration_owner'] ?? '—') ?></span></div>
</div>

<form method="post" action="<?= url('bawalis/excel-sync/confirm') ?>" class="excel-pick-form">
  <?= csrfField() ?>
  <input type="hidden" name="_form_token" value="<?= e($formToken) ?>">
  <input type="hidden" name="_back" value="bawalis/excel-sync/review?row=<?= (int)$row['excel_row'] ?>">
  <input type="hidden" name="excel_row" value="<?= (int)$row['excel_row'] ?>">
  <input type="hidden" name="row_token" value="<?= e($rowToken) ?>">

  <?php foreach ($groups as $groupKey=>$groupLabel): $groupFields = $byGroup[$groupKey] ?? []; if (!$groupFields) continue; ?>
    <section class="card excel-field-group">
      <div class="excel-group-title">
        <h2><?= e($groupLabel) ?></h2>
        <span><?= intf(count($groupFields)) ?> بيان</span>
      </div>
      <div class="excel-field-head" aria-hidden="true">
        <span>اختيار</span><span>البيان</span><span>في النظام حاليًا</span><span>في Excel</span><span>الحالة</span>
      </div>
      <?php foreach ($groupFields as $field): ?>
        <label class="excel-field-row <?= $field['changed']?'is-different':'is-same' ?> <?= $field['selectable']?'can-pick':'read-only' ?>">
          <span class="excel-field-check">
            <?php if ($field['selectable'] && !empty($sourceState['ready']) && !$row['conflicts']): ?>
              <input type="checkbox" name="fields[]" value="<?= e($field['key']) ?>">
            <?php else: ?><span class="excel-lock"><?= icon('lock', 14) ?></span><?php endif; ?>
          </span>
          <b><?= e($field['label']) ?></b>
          <span class="excel-value system"><?= e($fmt($field, 'system')) ?></span>
          <span class="excel-value excel"><?= e($fmt($field, 'excel')) ?></span>
          <span class="excel-field-state">
            <?php if (!$field['has_excel']): ?><em>غير موجود في Excel</em>
            <?php elseif (!$field['changed']): ?><em class="same">متطابق</em>
            <?php elseif ($field['selectable']): ?><em class="pick">يمكن اختياره</em>
            <?php else: ?><em>للمراجعة فقط</em><?php endif; ?>
            <?php if (!empty($field['note'])): ?><small><?= e($field['note']) ?></small><?php endif; ?>
            <?php if (!empty($field['blocked_reason'])): ?><small><?= e($field['blocked_reason']) ?></small><?php endif; ?>
          </span>
        </label>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>

  <div class="card excel-pick-actions">
    <div>
      <b>اختياراتك فقط هي التي ستنتقل للخطوة التالية</b>
      <p>لن يُحفظ شيء الآن. الصفحة التالية ستعرض قائمة نهائية بالقيمة القديمة والجديدة والنتائج المحسوبة.</p>
    </div>
    <button class="btn" type="submit" <?= empty($sourceState['ready']) || !$selectableCount || !empty($row['conflicts']) ? 'disabled' : '' ?>>
      مراجعة الاختيارات قبل التنفيذ
    </button>
  </div>
</form>

<style>
.eyebrow{font-size:var(--fs-1);color:var(--muted)}.flash.warning{background:var(--gold-light);color:var(--gold-dark);border:1px solid var(--gold)}
.excel-compare-head{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-bottom:14px}
.excel-compare-head>div{display:grid;gap:3px;padding:5px 10px}.excel-compare-head>div+div{border-inline-start:1px solid var(--line)}
.excel-compare-head small{color:var(--muted)}.excel-compare-head b{font-size:var(--fs-5)}.excel-compare-head span{color:var(--muted)}
.excel-pick-form{display:grid;gap:13px}.excel-field-group{padding:0;overflow:hidden}
.excel-group-title{display:flex;justify-content:space-between;align-items:center;padding:14px 16px;border-bottom:1px solid var(--line)}
.excel-group-title h2{font-size:var(--fs-4);margin:0}.excel-group-title span{color:var(--muted);font-size:var(--fs-2)}
.excel-field-head,.excel-field-row{display:grid;grid-template-columns:55px minmax(135px,.8fr) minmax(150px,1fr) minmax(150px,1fr) minmax(190px,1.2fr);gap:10px;align-items:center}
.excel-field-head{padding:8px 14px;background:var(--zebra);font-size:var(--fs-1);color:var(--muted);font-weight:700}
.excel-field-row{padding:11px 14px;border-top:1px solid var(--line);cursor:default}
.excel-field-row.can-pick{cursor:pointer}.excel-field-row.can-pick:hover{background:var(--gold-light)}
.excel-field-row.is-different{border-inline-start:4px solid var(--gold)}.excel-field-row.is-same{opacity:.78}
.excel-field-check{display:flex;justify-content:center}.excel-field-check input{width:19px;height:19px;accent-color:var(--green)}
.excel-lock{color:var(--grey)}.excel-value{padding:7px 9px;border-radius:8px;min-height:35px;overflow-wrap:anywhere}
.excel-value.system{background:var(--zebra);border:1px solid var(--line)}.excel-value.excel{background:#f1fbf6;border:1px solid #b9dfc7}
.excel-field-state{display:grid;gap:3px}.excel-field-state em{font-style:normal;color:var(--muted);font-size:var(--fs-2)}
.excel-field-state em.same{color:var(--green)}.excel-field-state em.pick{color:var(--gold-dark);font-weight:700}
.excel-field-state small{font-size:var(--fs-1);line-height:1.45;color:var(--muted)}
.excel-pick-actions{position:sticky;bottom:10px;display:flex;justify-content:space-between;align-items:center;gap:18px;border:1px solid var(--navy);box-shadow:0 8px 28px #13233a22}
.excel-pick-actions p{margin:5px 0 0;color:var(--muted);font-size:var(--fs-2)}
@media(max-width:850px){.excel-field-head{display:none}.excel-field-row{grid-template-columns:42px 1fr 1fr}.excel-field-row>b{grid-column:2/-1}.excel-field-state{grid-column:2/-1}.excel-pick-actions{position:static;align-items:stretch;flex-direction:column}.excel-compare-head{grid-template-columns:1fr}.excel-compare-head>div+div{border-inline-start:0;border-top:1px solid var(--line)}}
</style>
