<?php
/** @var array $permit @var array $old @var array $pocket @var array $errors
 *  @var array|null $submission @var string $draftKey @var string $workDate */
$movs = $permit['movements'];
$editing = !empty($submission);
$o = fn(string $k, $d = '') => e((string) ($old[$k] ?? $d));
$err = fn(string $k) => (string) ($errors[$k] ?? '');
$kind = (string) ($old['kind'] ?? 'receipt');
$qtyValue = max(1, (int) ($old['qty'] ?? 1));
$receiptNotes = array_values(array_unique(\App\Models\Lookup::ofType('mv_receipt')));
$expenseNotes = array_values(array_unique(\App\Models\Lookup::ofType('mv_expense')));
$action = $editing ? url('m/entry/' . (int) $submission['id']) : url('m/permit/' . $permit['id']);
$today = date('Y-m-d');
// تاريخ البند الافتراضي = عهدة يوم العمل (وعند التعديل بيفضل تاريخ البند الأصلي)
$wd = $workDate ?? $today;
$wdToday = $wd === $today;
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0F151E">
<title><?= $editing ? 'تعديل بند' : 'بند جديد' ?> — <?= e($permit['no']) ?></title>
<link rel="icon" href="<?= url('assets/img/logo.png') ?>">
<link rel="stylesheet" href="<?= url('assets/css/m.css') ?>?v=<?= @filemtime(\App\Core\App::basePath('public/assets/css/m.css')) ?>">
<?php require __DIR__ . "/_pwa.php"; ?>
</head>
<body class="hm-body hf-body">

<div class="hf-top">
  <header class="hf-bar">
    <a href="<?= url('m?tab=entries') ?>" class="hf-back" aria-label="رجوع">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </a>
    <span class="hf-title"><?= $editing ? 'تعديل بند' : 'تسجيل بند جديد' ?></span>
  </header>

  <div class="hf-context">
    <span class="hf-kind <?= ($permit['type'] ?? 'sader') === 'warid' ? 'is-warid' : 'is-sader' ?>"><?= e($permit['type_label']) ?></span>
    <b><?= e($permit['company'] ?: $permit['client'] ?: 'بدون شركة') ?></b>
    <span class="hf-no"><?= e($permit['no']) ?></span>
    <?php if ($permit['container_label'] !== ''): ?>
      <span class="hf-box">📦 <bdi dir="ltr"><?= e($permit['container_label']) ?></bdi></span>
    <?php endif; ?>
  </div>
</div>

<div class="net-state" id="netState" role="status" aria-live="polite" hidden>
  <span id="netText"></span>
  <button type="button" id="retryBtn" hidden>حاول مرة أخرى</button>
</div>

<form method="post" action="<?= $action ?>" enctype="multipart/form-data" id="f"
      novalidate data-draft-key="<?= e($draftKey) ?>" data-has-server-errors="<?= $errors ? '1' : '0' ?>">
  <?= csrfField() ?>
  <?php if (!$editing): ?>
    <input type="hidden" name="client_token" value="<?= $o('client_token') ?>">
  <?php endif; ?>

  <main class="hf-sheet">
    <span class="hm-grab" aria-hidden="true"></span>

    <div class="error-summary" id="errorSummary" role="alert" tabindex="-1" <?= $errors ? '' : 'hidden' ?>>
      <b>راجع البيانات دي:</b>
      <ul id="errorList">
        <?php foreach ($errors as $message): ?><li><?= e($message) ?></li><?php endforeach; ?>
      </ul>
    </div>

    <div class="draft-note" id="draftNote" hidden>
      <span>رجّعنا المسودة اللي ما اتبعتتش. راجعها وكمل.</span>
      <button type="button" id="discardDraft">امسح المسودة</button>
    </div>

    <fieldset class="field" id="field-kind" <?= $err('kind') ? 'data-error="1"' : '' ?>>
      <legend>ده إيه؟</legend>
      <div class="pick" role="radiogroup" aria-describedby="err-kind">
        <input type="radio" name="kind" id="k_r" value="receipt" <?= $kind === 'receipt' ? 'checked' : '' ?>>
        <label for="k_r">🧾 إيصال</label>
        <input type="radio" name="kind" id="k_e" value="expense" <?= $kind === 'expense' ? 'checked' : '' ?>>
        <label for="k_e">💸 مصروف</label>
      </div>
      <p class="field-error" id="err-kind" <?= $err('kind') ? '' : 'hidden' ?>><?= e($err('kind')) ?></p>
    </fieldset>

    <?php if (count($movs) > 1): ?>
      <div class="field" id="field-movement_id" <?= $err('movement_id') ? 'data-error="1"' : '' ?>>
        <label for="mv">الحركة اللي دفعت عليها</label>
        <select id="mv" name="movement_id" required aria-describedby="hint-mv err-movement_id"
                <?= $err('movement_id') ? 'aria-invalid="true"' : '' ?>>
          <option value="">— اختر الحركة —</option>
          <?php foreach ($movs as $mv): ?>
            <option value="<?= (int) $mv['id'] ?>" <?= (string) ($old['movement_id'] ?? '') === (string) $mv['id'] ? 'selected' : '' ?>>
              <?= e($mv['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="hint" id="hint-mv">اختيارك هنا يمنع تسجيل البند على نقلة غلط.</div>
        <p class="field-error" id="err-movement_id" <?= $err('movement_id') ? '' : 'hidden' ?>><?= e($err('movement_id')) ?></p>
      </div>
    <?php elseif ($movs): ?>
      <input type="hidden" name="movement_id" value="<?= (int) $movs[0]['id'] ?>">
    <?php endif; ?>

    <div class="field" id="field-note" <?= $err('note') ? 'data-error="1"' : '' ?>>
      <label for="note">البيان</label>
      <input type="text" id="note" name="note" required maxlength="255"
             list="notes-<?= $kind === 'expense' ? 'expense' : 'receipt' ?>"
             enterkeyhint="next" value="<?= $o('note') ?>"
             placeholder="مثال: تخصيص أو حمولة" aria-describedby="err-note"
             <?= $err('note') ? 'aria-invalid="true"' : '' ?>>
      <div class="suggestions" id="suggestions-receipt" <?= $kind === 'expense' ? 'hidden' : '' ?>>
        <?php foreach (array_slice($receiptNotes, 0, 4) as $v): ?>
          <button type="button" data-note="<?= e($v) ?>"><?= e($v) ?></button>
        <?php endforeach; ?>
      </div>
      <div class="suggestions" id="suggestions-expense" <?= $kind === 'expense' ? '' : 'hidden' ?>>
        <?php foreach (array_slice($expenseNotes, 0, 4) as $v): ?>
          <button type="button" data-note="<?= e($v) ?>"><?= e($v) ?></button>
        <?php endforeach; ?>
      </div>
      <datalist id="notes-receipt"><?php foreach ($receiptNotes as $v): ?><option value="<?= e($v) ?>"></option><?php endforeach; ?></datalist>
      <datalist id="notes-expense"><?php foreach ($expenseNotes as $v): ?><option value="<?= e($v) ?>"></option><?php endforeach; ?></datalist>
      <p class="field-error" id="err-note" <?= $err('note') ? '' : 'hidden' ?>><?= e($err('note')) ?></p>
    </div>

    <div class="field" id="field-unit_amount" <?= $err('unit_amount') ? 'data-error="1"' : '' ?>>
      <label for="amt">دفعت كام؟ <span class="optional">سعر الواحدة</span></label>
      <div class="money-in">
        <input type="text" id="amt" name="unit_amount" required inputmode="decimal"
               enterkeyhint="next" value="<?= $o('unit_amount') ?>" placeholder="0"
               autocomplete="off" aria-describedby="totalHint err-unit_amount"
               <?= $err('unit_amount') ? 'aria-invalid="true"' : '' ?>>
        <span class="cur">جنيه</span>
      </div>
      <div class="hint total-hint" id="totalHint" hidden></div>
      <p class="field-error" id="err-unit_amount" <?= $err('unit_amount') ? '' : 'hidden' ?>><?= e($err('unit_amount')) ?></p>
    </div>

    <div class="multi-toggle-row">
      <span>دفعت نفس المبلغ لأكتر من إيصال؟</span>
      <button type="button" class="mini-action" id="multiToggle" aria-expanded="<?= $qtyValue > 1 ? 'true' : 'false' ?>">
        <?= $qtyValue > 1 ? 'الكمية: ' . $qtyValue : 'أكتر من واحد' ?>
      </button>
    </div>
    <div class="field qty-extra" id="field-qty" <?= $qtyValue > 1 ? '' : 'hidden' ?> <?= $err('qty') ? 'data-error="1"' : '' ?>>
      <label for="qty">عدد الإيصالات بنفس السعر</label>
      <div class="qty">
        <button type="button" data-q="-1" aria-label="قلّل الكمية">−</button>
        <input type="number" id="qty" name="qty" min="1" max="10000" required inputmode="numeric"
               value="<?= $qtyValue ?>" aria-describedby="err-qty" <?= $err('qty') ? 'aria-invalid="true"' : '' ?>>
        <button type="button" data-q="1" aria-label="زوّد الكمية">+</button>
      </div>
      <p class="field-error" id="err-qty" <?= $err('qty') ? '' : 'hidden' ?>><?= e($err('qty')) ?></p>
    </div>

    <div class="field" id="field-ref_no" <?= $err('ref_no') ? 'data-error="1"' : '' ?>>
      <label for="ref">رقم الإيصال <span class="optional">اختياري</span></label>
      <input type="text" id="ref" name="ref_no" maxlength="120" inputmode="text"
             autocapitalize="characters" value="<?= $o('ref_no') ?>" aria-describedby="err-ref_no"
             <?= $err('ref_no') ? 'aria-invalid="true"' : '' ?>>
      <p class="field-error" id="err-ref_no" <?= $err('ref_no') ? '' : 'hidden' ?>><?= e($err('ref_no')) ?></p>
    </div>

    <div class="field" id="field-entry_date" <?= $err('entry_date') ? 'data-error="1"' : '' ?>>
      <?php if ($editing): ?>
        <label for="d">تاريخ الدفع</label>
        <input type="date" id="d" name="entry_date" required max="<?= e($today) ?>"
               value="<?= $o('entry_date', $wd) ?>" aria-describedby="err-entry_date"
               <?= $err('entry_date') ? 'aria-invalid="true"' : '' ?>>
      <?php else: ?>
        <?php /* v49: الخادم بيفرض يوم العمل — فالتاريخ عرض مش إدخال */ ?>
        <?php $wdLabel = 'تاريخ الدفع — من عهدة يوم'; require __DIR__ . '/_workday.php'; ?>
      <?php endif; ?>
      <p class="field-error" id="err-entry_date" <?= $err('entry_date') ? '' : 'hidden' ?>><?= e($err('entry_date')) ?></p>
    </div>

    <?php require __DIR__ . '/_visa_field.php'; ?>

    <div class="field" id="field-photo" <?= $err('photo') ? 'data-error="1"' : '' ?>>
      <label>صورة الإيصال <span class="optional">اختيارية</span></label>
      <?php if ($editing && !empty($submission['photo'])): ?>
        <div class="existing-photo">
          <img src="<?= url('attachments/submission/'.(int)$submission['id']) ?>" alt="الصورة الحالية">
          <label><input type="checkbox" name="remove_photo" value="1"> احذف الصورة الحالية</label>
        </div>
      <?php endif; ?>
      <label class="shot" id="shotBtn">
        <span id="shotTxt">📷 صوّر أو اختار صورة</span>
        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/heic,image/heif" id="shot"
               aria-describedby="photoHint err-photo">
      </label>
      <div class="hint" id="photoHint">الحد الأقصى 12 ميجا. الصورة لا تُحفظ داخل المسودة؛ اختارها تاني بعد إغلاق الصفحة.</div>
      <div class="shot-prev" id="prev" hidden><img alt="معاينة الصورة الجديدة" id="prevImg"></div>
      <p class="field-error" id="err-photo" <?= $err('photo') ? '' : 'hidden' ?>><?= e($err('photo')) ?></p>
    </div>
  </main>

  <div class="dock hf-dock">
    <button class="btn gold" type="submit" id="save"><?= $editing ? 'احفظ التعديل' : 'سجّل البند' ?></button>
  </div>
</form>

<script>
(function () {
  'use strict';
  var form = document.getElementById('f');
  var amt = document.getElementById('amt');
  var qty = document.getElementById('qty');
  var note = document.getElementById('note');
  var hint = document.getElementById('totalHint');
  var save = document.getElementById('save');
  var baseSaveText = <?= js_json($editing ? 'احفظ التعديل' : 'سجّل البند') ?>;
  var draftKey = form.dataset.draftKey;
  var draftTtl = 24 * 60 * 60 * 1000;
  var busy = false;

  function ar2en(v) {
    return String(v || '').replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); })
      .replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); })
      .replace(/[٬,\s]/g, '').replace('٫', '.');
  }
  function moneyValue() { return parseFloat(ar2en(amt.value)) || 0; }
  function total() {
    var a = moneyValue(), q = Math.max(1, parseInt(qty.value, 10) || 1), sum = a * q;
    if (a > 0) {
      hint.textContent = q > 1
        ? 'الإجمالي: ' + sum.toLocaleString('en-US', {maximumFractionDigits: 2}) + ' جنيه (' + q + ' × ' + a.toLocaleString('en-US') + ')'
        : 'الإجمالي: ' + sum.toLocaleString('en-US', {maximumFractionDigits: 2}) + ' جنيه';
      hint.hidden = false;
      save.textContent = (<?= $editing ? 'true' : 'false' ?> ? 'احفظ ' : 'سجّل ') + sum.toLocaleString('en-US', {maximumFractionDigits: 2}) + ' جنيه';
    } else {
      hint.hidden = true;
      save.textContent = baseSaveText;
    }
  }

  function setKind(kind) {
    var expense = kind === 'expense';
    note.setAttribute('list', expense ? 'notes-expense' : 'notes-receipt');
    document.getElementById('suggestions-expense').hidden = !expense;
    document.getElementById('suggestions-receipt').hidden = expense;
    note.placeholder = expense ? 'مثال: إكرامية أو انتقالات' : 'مثال: تخصيص أو حمولة';
  }
  form.querySelectorAll('input[name="kind"]').forEach(function (r) {
    r.addEventListener('change', function () { setKind(r.value); saveDraft(); });
  });
  form.querySelectorAll('[data-note]').forEach(function (b) {
    b.addEventListener('click', function () { note.value = b.dataset.note; note.focus(); saveDraft(); });
  });

  document.getElementById('multiToggle').addEventListener('click', function () {
    var box = document.getElementById('field-qty');
    box.hidden = !box.hidden;
    this.setAttribute('aria-expanded', box.hidden ? 'false' : 'true');
    if (box.hidden) { qty.value = '1'; }
    else { qty.focus(); }
    total(); saveDraft();
  });
  form.querySelectorAll('.qty button').forEach(function (b) {
    b.addEventListener('click', function () {
      var v = (parseInt(qty.value, 10) || 1) + parseInt(b.dataset.q, 10);
      qty.value = Math.max(1, Math.min(10000, v));
      document.getElementById('multiToggle').textContent = 'الكمية: ' + qty.value;
      total(); saveDraft();
    });
  });
  amt.addEventListener('input', total);
  qty.addEventListener('input', function () {
    document.getElementById('multiToggle').textContent = 'الكمية: ' + (parseInt(qty.value, 10) || 1);
    total();
  });

  var shot = document.getElementById('shot');
  shot.addEventListener('change', function () {
    clearFieldError('photo');
    var file = shot.files && shot.files[0];
    if (!file) { return; }
    if (file.size > 12 * 1024 * 1024) {
      shot.value = '';
      showErrors({photo: 'الصورة أكبر من 12 ميجا. اختار صورة أصغر.'});
      return;
    }
    document.getElementById('shotBtn').classList.add('has');
    document.getElementById('shotTxt').textContent = '✓ ' + file.name + ' — دوس تغيّرها';
    var img = document.getElementById('prevImg');
    img.src = URL.createObjectURL(file);
    img.onload = function () { document.getElementById('prev').hidden = false; };
    img.onerror = function () { document.getElementById('prev').hidden = true; };
  });

  function draftData() {
    var data = {updatedAt: Date.now(), values: {}};
    Array.from(form.elements).forEach(function (el) {
      if (!el.name || el.name === '_csrf' || el.name === 'photo' || el.name === 'remove_photo') { return; }
      if ((el.type === 'radio' || el.type === 'checkbox') && !el.checked) { return; }
      data.values[el.name] = el.value;
    });
    return data;
  }
  function saveDraft() {
    try { localStorage.setItem(draftKey, JSON.stringify(draftData())); } catch (e) {}
  }
  function restoreDraft() {
    if (form.dataset.hasServerErrors === '1') { return; }
    try {
      var raw = localStorage.getItem(draftKey);
      if (!raw) { return; }
      var draft = JSON.parse(raw);
      if (!draft.updatedAt || Date.now() - draft.updatedAt > draftTtl) { localStorage.removeItem(draftKey); return; }
      Object.keys(draft.values || {}).forEach(function (name) {
        var value = draft.values[name], control = form.elements[name];
        if (!control) { return; }
        if (control.length && control[0] && control[0].type === 'radio') {
          Array.from(control).forEach(function (r) { r.checked = r.value === value; });
        } else { control.value = value; }
      });
      document.getElementById('draftNote').hidden = false;
      var selectedKind = form.querySelector('input[name="kind"]:checked');
      setKind(selectedKind ? selectedKind.value : 'receipt');
      document.getElementById('field-qty').hidden = (parseInt(qty.value, 10) || 1) <= 1;
    } catch (e) {}
  }
  form.addEventListener('input', saveDraft);
  form.addEventListener('change', saveDraft);
  document.getElementById('discardDraft').addEventListener('click', function () {
    try { localStorage.removeItem(draftKey); } catch (e) {}
    window.location.reload();
  });

  function clearFieldError(name) {
    var field = document.getElementById('field-' + name), msg = document.getElementById('err-' + name);
    if (field) { field.removeAttribute('data-error'); }
    if (msg) { msg.hidden = true; msg.textContent = ''; }
    var control = form.elements[name];
    if (control && control.removeAttribute) { control.removeAttribute('aria-invalid'); }
  }
  // ── الفيزا (v45): إظهار اختيار الكارت مع الوسم، وتصفيره لو اتشال عشان
  //    ما يتبعتش card_id لبند كاش (الخادم بيتجاهله بردو، بس نفضل الفورم نضيف).
  var visaChk = document.getElementById('visaChk'),
      visaPick = document.getElementById('visaPick'),
      visaCard = document.getElementById('visaCard');
  function syncVisa() {
    if (!visaChk || !visaPick) { return; }
    visaPick.hidden = !visaChk.checked;
    if (!visaChk.checked && visaCard) { visaCard.value = ''; }
  }
  if (visaChk) { visaChk.addEventListener('change', syncVisa); syncVisa(); }

  function clearErrors() {
    ['kind','movement_id','note','unit_amount','qty','ref_no','entry_date','photo','visa_card_id'].forEach(clearFieldError);
    document.getElementById('errorSummary').hidden = true;
    document.getElementById('errorList').innerHTML = '';
  }
  function showErrors(errors) {
    clearErrors();
    var list = document.getElementById('errorList');
    Object.keys(errors || {}).forEach(function (name) {
      var li = document.createElement('li'); li.textContent = errors[name]; list.appendChild(li);
      if (name === '_form') { return; }
      var field = document.getElementById('field-' + name), msg = document.getElementById('err-' + name);
      if (field) { field.setAttribute('data-error', '1'); }
      if (msg) { msg.textContent = errors[name]; msg.hidden = false; }
      var control = form.elements[name];
      if (control && control.setAttribute) { control.setAttribute('aria-invalid', 'true'); }
    });
    var summary = document.getElementById('errorSummary');
    summary.hidden = false; summary.focus();
  }
  function clientErrors() {
    var errors = {}, movement = form.elements.movement_id;
    if (!form.querySelector('input[name="kind"]:checked')) { errors.kind = 'اختر إيصال أو مصروف.'; }
    if (movement && movement.tagName === 'SELECT' && !movement.value) { errors.movement_id = 'اختر الحركة اللي دفعت عليها.'; }
    if (!note.value.trim()) { errors.note = 'اكتب البيان، زي تخصيص أو حمولة.'; }
    if (moneyValue() <= 0) { errors.unit_amount = 'اكتب المبلغ اللي دفعته.'; }
    var q = parseInt(qty.value, 10); if (!q || q < 1 || q > 10000) { errors.qty = 'الكمية لازم تكون من 1 لـ10000.'; }
    // v49: البند الجديد بلا خانة تاريخ (الخادم بيفرض يوم العمل) — نتحقق بس لو الخانة ظاهرة (وضع التعديل).
    var dateEl = form.elements.entry_date;
    if (dateEl && !dateEl.value) { errors.entry_date = 'اكتب تاريخ الدفع.'; }
    else if (dateEl && dateEl.value > <?= js_json($today) ?>) { errors.entry_date = 'تاريخ الدفع ما ينفعش يكون في المستقبل.'; }
    var vc = document.getElementById('visaChk'), vcard = document.getElementById('visaCard');
    if (vc && vc.checked && vcard && !vcard.value) { errors.visa_card_id = 'اختر الكارت اللي دفعت بيه.'; }
    return errors;
  }

  var net = document.getElementById('netState'), netText = document.getElementById('netText'), retry = document.getElementById('retryBtn');
  function networkState(forceError) {
    var slow = navigator.connection && ['slow-2g','2g'].indexOf(navigator.connection.effectiveType) !== -1;
    if (!navigator.onLine || forceError) {
      net.hidden = false; net.className = 'net-state offline';
      netText.textContent = 'لا يوجد إنترنت — المسودة محفوظة على الموبايل.'; retry.hidden = false;
    } else if (slow) {
      net.hidden = false; net.className = 'net-state weak';
      netText.textContent = 'الاتصال ضعيف — استنى تأكيد التسجيل قبل ما تقفل الصفحة.'; retry.hidden = true;
    } else { net.hidden = true; retry.hidden = true; }
  }
  window.addEventListener('online', function () { networkState(false); });
  window.addEventListener('offline', function () { networkState(false); saveDraft(); });
  if (navigator.connection) { navigator.connection.addEventListener('change', function () { networkState(false); }); }
  retry.addEventListener('click', function () { form.requestSubmit(); });

  form.addEventListener('submit', async function (event) {
    event.preventDefault();
    if (busy) { return; }
    var errors = clientErrors();
    if (Object.keys(errors).length) { showErrors(errors); return; }
    if (!navigator.onLine) { saveDraft(); networkState(true); return; }

    clearErrors(); saveDraft(); busy = true; save.disabled = true; save.textContent = 'بيتسجّل…'; retry.hidden = true;
    try {
      var response = await fetch(form.action, {
        method: 'POST', body: new FormData(form), credentials: 'same-origin',
        headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}
      });
      var type = response.headers.get('content-type') || '';
      if (type.indexOf('application/json') === -1) {
        showErrors({_form: response.status === 419
          ? 'انتهت الجلسة. حدّث الصفحة وسجّل الدخول من جديد.'
          : 'الخدمة مش متاحة دلوقتي. المسودة محفوظة؛ حاول بعد شوية.'});
        busy = false; save.disabled = false; total(); return;
      }
      var result = await response.json();
      if (!response.ok || !result.ok) {
        showErrors(result.errors || {_form: 'راجع البيانات وحاول تاني.'});
        busy = false; save.disabled = false; total(); return;
      }
      try { localStorage.removeItem(result.clearDraft || draftKey); } catch (e) {}
      window.location.assign(result.redirect);
    } catch (e) {
      busy = false; save.disabled = false; total(); saveDraft(); networkState(true);
    }
  });

  restoreDraft();
  setKind((form.querySelector('input[name="kind"]:checked') || {}).value || 'receipt');
  total(); networkState(false);
})();
</script>
</body>
</html>
