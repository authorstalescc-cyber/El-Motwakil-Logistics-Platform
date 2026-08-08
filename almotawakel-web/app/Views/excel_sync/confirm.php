<?php
$fmt = static function (array $field, string $side): string {
    $value = $field[$side] ?? null;
    if ($value === null || trim((string)$value) === '') { return '—'; }
    if (in_array($field['kind'], ['money','payment'], true)) { return money((float)$value, false); }
    if ($field['kind'] === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$value)) { return dfmt((string)$value); }
    return (string)$value;
};
?>

<div class="page-head">
  <div>
    <span class="eyebrow">الخطوة الأخيرة — لم يُحفظ شيء بعد</span>
    <h1><?= icon('check-circle', 24) ?> تأكيد بوليصة <?= e($row['bill_number']) ?></h1>
    <p>هذه هي التغييرات الوحيدة التي سينفذها النظام. أي حقل غير موجود هنا لن يتغير.</p>
  </div>
  <a class="btn ghost" href="<?= url('bawalis/excel-sync/review?row=' . (int)$row['excel_row']) ?>">تعديل الاختيارات</a>
</div>

<div class="flash warning"><?= icon('alert', 17) ?> راجع القيمة الحالية وقيمة Excel لكل سطر قبل الضغط على التنفيذ.</div>

<section class="card excel-confirm-list">
  <div class="excel-confirm-head"><span>البيان</span><span>القيمة الحالية</span><span>ستصبح</span></div>
  <?php foreach ($selection['fields'] as $field): ?>
    <div class="excel-confirm-row">
      <b><?= e($field['label']) ?></b>
      <span><?= e($fmt($field, 'system')) ?></span>
      <strong><?= e($fmt($field, 'excel')) ?></strong>
      <?php if (!empty($field['note'])): ?><small><?= e($field['note']) ?></small><?php endif; ?>
    </div>
  <?php endforeach; ?>
</section>

<section class="card excel-result-card">
  <h2>النتيجة المحسوبة بعد الاختيارات</h2>
  <div class="excel-results">
    <div><small>المستحق</small><b><?= money($selection['calculated']['debt'], false) ?></b></div>
    <div><small>المدفوع</small><b><?= money($selection['calculated']['paid'], false) ?></b></div>
    <div><small>المتبقي</small><b><?= money($selection['calculated']['remaining'], false) ?></b></div>
    <?php if (!empty($row['is_office_excel'])): ?>
      <div><small>إجمالي مصروف السيارة</small><b><?= money($selection['calculated']['total_expense'], false) ?></b></div>
      <div><small>الباقي من العهدة</small><b><?= money($selection['calculated']['custody_remaining'], false) ?></b></div>
      <div><small>صافي السيارة</small><b><?= money($selection['calculated']['car_net'], false) ?></b></div>
    <?php endif; ?>
  </div>
</section>

<form method="post" action="<?= url('bawalis/excel-sync/apply') ?>" class="card excel-final-form confirm-financial"
      data-confirm="تنفيذ الحقول المختارة الظاهرة في هذه الصفحة فقط؟">
  <?= csrfField() ?>
  <input type="hidden" name="_form_token" value="<?= e($formToken) ?>">
  <input type="hidden" name="_back" value="bawalis/excel-sync/confirm?token=<?= e($confirmationToken) ?>">
  <input type="hidden" name="confirmation_token" value="<?= e($confirmationToken) ?>">
  <label>
    <input type="checkbox" name="confirmed" value="1" required>
    <span>راجعت <?= intf(count($selection['fields'])) ?> تغييرًا أعلاه وأوافق على تنفيذها فقط.</span>
  </label>
  <button class="btn" type="submit"><?= icon('check-circle', 16) ?> تنفيذ التغييرات المختارة</button>
</form>

<style>
.eyebrow{font-size:var(--fs-1);color:var(--muted)}.flash.warning{background:var(--gold-light);color:var(--gold-dark);border:1px solid var(--gold)}
.excel-confirm-list{padding:0;overflow:hidden}.excel-confirm-head,.excel-confirm-row{display:grid;grid-template-columns:minmax(150px,.8fr) minmax(150px,1fr) minmax(150px,1fr);gap:12px;align-items:center}
.excel-confirm-head{padding:9px 15px;background:var(--zebra);color:var(--muted);font-size:var(--fs-1);font-weight:700}
.excel-confirm-row{padding:13px 15px;border-top:1px solid var(--line)}.excel-confirm-row span{color:var(--muted)}
.excel-confirm-row strong{color:var(--green);background:#f1fbf6;border:1px solid #b9dfc7;border-radius:8px;padding:8px}
.excel-confirm-row small{grid-column:2/-1;color:var(--gold-dark)}
.excel-result-card{margin-top:14px}.excel-result-card h2{margin-top:0;font-size:var(--fs-4)}
.excel-results{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px}.excel-results>div{background:var(--zebra);border:1px solid var(--line);border-radius:9px;padding:11px}
.excel-results small{display:block;color:var(--muted);margin-bottom:5px}.excel-results b{font-size:var(--fs-5)}
.excel-final-form{display:flex;justify-content:space-between;align-items:center;gap:18px;margin-top:14px;border:2px solid var(--navy)}
.excel-final-form label{display:flex;align-items:center;gap:9px}.excel-final-form input[type=checkbox]{width:19px;height:19px;accent-color:var(--green)}
@media(max-width:750px){.excel-confirm-head{display:none}.excel-confirm-row{grid-template-columns:1fr 1fr}.excel-confirm-row>b{grid-column:1/-1}.excel-confirm-row small{grid-column:1/-1}.excel-results{grid-template-columns:1fr 1fr}.excel-final-form{align-items:stretch;flex-direction:column}}
</style>
