<?php
/**
 * تعديل بيانات العميل — نفس حقول `PartyMaster::editableFields` بالظبط.
 *
 * ⚠️ `lock_version` إجباري: تعديل اتنين على نفس العميل في نفس الوقت بيترفض
 *    برسالة بدل ما واحد يدهس التاني بالسكوت (قفل تفاؤلي في `PartyMaster`).
 * ⚠️ **تغيير الاسم** بيعدّي على حارس الأسماء — الاسم اللي يخصّ عميل تاني
 *    بيترفض والدمج مكانه «دليل الأطراف».
 *
 * @var array $agent @var array $row @var string $formErr @var array $state
 */
$id = (int) $agent['id'];
?>

<?php partial('page_head', [
  'title' => 'بيانات العميل',
  'back'  => ['label' => (string) $agent['name'], 'url' => url('clients/' . $id)],
]); ?>

<?php if ($formErr !== ''): ?>
  <div class="flash error" role="alert"><?= icon('alert', 18) ?> <?= e($formErr) ?></div>
<?php endif; ?>

<form method="post" action="<?= url('clients/' . $id . '/edit') ?>" class="card cl-form">
  <?= csrfField() ?>
  <?= formStateFields($state['token'], $state['back']) ?>
  <input type="hidden" name="lock_version" value="<?= (int) ($agent['lock_version'] ?? 0) ?>">

  <div class="cl-pay-row">
    <div class="field flush grow">
      <label for="f-name">الاسم القانوني <span class="req">*</span></label>
      <input id="f-name" name="name" value="<?= e($row['name'] ?? '') ?>" required>
    </div>
    <div class="field flush w150">
      <label for="f-phone">الهاتف</label>
      <input id="f-phone" name="phone" value="<?= e($row['phone'] ?? '') ?>">
    </div>
    <div class="field flush w150">
      <label for="f-terms">مهلة السداد (يوم)</label>
      <input id="f-terms" type="number" min="0" max="365" name="payment_terms_days"
             value="<?= (int) ($row['payment_terms_days'] ?? 30) ?>">
    </div>
  </div>

  <div class="cl-pay-row">
    <div class="field flush grow">
      <label for="f-email">البريد</label>
      <input id="f-email" type="email" name="email" value="<?= e($row['email'] ?? '') ?>">
    </div>
    <div class="field flush grow">
      <label for="f-tax">الرقم الضريبي</label>
      <input id="f-tax" name="tax_id" value="<?= e($row['tax_id'] ?? '') ?>">
    </div>
  </div>

  <div class="cl-pay-row">
    <div class="field flush grow">
      <label for="f-addr">العنوان</label>
      <input id="f-addr" name="address" value="<?= e($row['address'] ?? '') ?>">
    </div>
    <div class="field flush grow">
      <label for="f-note">ملاحظة</label>
      <input id="f-note" name="note" value="<?= e($row['note'] ?? '') ?>">
    </div>
  </div>

  <?php
  /* ═══ تفضيلات الفوترة (v100) ═══
     ⚠️ **«حسب التاريخ» هو الخيار الأول عن قصد** — وهو `NULL` في القاعدة.
        القيمة الفاضية معناها «ما قلتش» فالنظام يستنتج؛ ولو حطّينا خيارًا
        افتراضيًّا محدَّدًا كنا هنعلن قرارًا المالك ما اتخذوش **بالسكوت**.
     ⚠️ النسبة بتتكتب مئوية (١٤) وبتتخزّن كسرًا (0.14) — التحويل في
        `PartyMaster::editableFields` مكان واحد لا هنا. */
  $prefKind = (string) ($row['default_doc_kind'] ?? '');
  $prefTax  = $row['default_taxable'] ?? null;
  ?>
  <div class="cl-pay-row">
    <div class="field flush grow">
      <label for="f-dk">المستند المعتاد</label>
      <select id="f-dk" name="default_doc_kind">
        <option value="">حسب التاريخ</option>
        <?php foreach (\App\Models\PermitInvoice::KINDS as $k => $lbl): ?>
          <option value="<?= e($k) ?>"<?= $prefKind === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field flush grow">
      <label for="f-dt">الضريبة</label>
      <select id="f-dt" name="default_taxable">
        <option value="">حسب التاريخ</option>
        <option value="1"<?= ($prefTax !== null && (int) $prefTax === 1) ? ' selected' : '' ?>>خاضع</option>
        <option value="0"<?= ($prefTax !== null && $prefTax !== '' && (int) $prefTax === 0) ? ' selected' : '' ?>>غير خاضع</option>
      </select>
    </div>
    <?php /* ⚠️ **«نسبة ضريبة خاصة بالعميل» مش معروضة عن قصد.** العمود
             (`agents.default_vat_rate`) اتعمل في v100 لكنه **مش موصَّل**:
             `PermitController::invoiceStore` بياخد النسبة من الإعدادات العامة
             وقت الحفظ، فخانة في الشاشة كانت هتوري نسبة **ما بتتطبّقش** —
             وكذبة صامتة أسوأ من ميزة ناقصة. تتعرض لما نقطة الحفظ تتعدّل. */ ?>
  </div>

  <div class="cl-pay-foot">
    <button class="btn gold" type="submit"><?= icon('check', 15) ?> حفظ</button>
    <a class="btn ghost" href="<?= url('clients/' . $id) ?>">إلغاء</a>
  </div>
</form>
