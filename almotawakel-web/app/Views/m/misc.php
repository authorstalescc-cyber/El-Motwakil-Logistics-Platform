<?php
/** شاشة النثرية (v39) — مصروف عام **مش مربوط بأي إذن**.
 *  الفرق عن m/entry.php: مفيش إذن ولا حركة ولا نوع بند — نثرية وخلاص.
 *  @var array $old @var array $errors @var array|null $submission
 *  @var array $pocket @var string $draftKey @var string $workDate */
$editing = !empty($submission);
$o = fn(string $k, $d = '') => e((string) ($old[$k] ?? $d));
$err = fn(string $k) => (string) ($errors[$k] ?? '');
$qtyValue = max(1, (int) ($old['qty'] ?? 1));
$miscNotes = array_values(array_unique(\App\Models\Lookup::ofType('mv_misc')));
if (!$miscNotes) {
    // أول مرة: اقتراحات مبدئية لحد ما المالك يعتمد نثريات فتتعلّم من الواقع
    $miscNotes = ['مواصلات', 'شاي وقهوة', 'تصوير مستندات', 'إكرامية'];
}
$action = $editing ? url('m/entry/' . (int) $submission['id']) : url('m/misc');
$today = date('Y-m-d');
// تاريخ الصرف الافتراضي = عهدة يوم العمل (وعند التعديل بيفضل تاريخ النثرية الأصلي)
$wd = $workDate ?? $today;
$wdToday = $wd === $today;
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0E2440">
<title><?= $editing ? 'تعديل نثرية' : 'نثرية جديدة' ?> — المتوكل</title>
<link rel="icon" href="<?= url('assets/img/logo.png') ?>">
<link rel="stylesheet" href="<?= url('assets/css/m.css') ?>?v=<?= @filemtime(\App\Core\App::basePath('public/assets/css/m.css')) ?>">
<?php require __DIR__ . "/_pwa.php"; ?>
</head>
<body>

<div class="m-bar">
  <a href="<?= url('m?tab=entries') ?>" class="out">→ رجوع</a>
  <span class="sp"></span>
  <span class="who">🧷 <?= $editing ? 'تعديل نثرية' : 'نثرية جديدة' ?></span>
</div>

<div class="net-state" id="netState" role="status" aria-live="polite" hidden>
  <span id="netText"></span>
  <button type="button" id="retryBtn" hidden>حاول مرة أخرى</button>
</div>

<form method="post" action="<?= $action ?>" enctype="multipart/form-data" id="f"
      novalidate data-draft-key="<?= e($draftKey) ?>" data-has-server-errors="<?= $errors ? '1' : '0' ?>">
  <?= csrfField() ?>
  <input type="hidden" name="kind" value="misc">
  <?php if (!$editing): ?>
    <input type="hidden" name="client_token" value="<?= $o('client_token') ?>">
  <?php endif; ?>

  <div class="wrap entry-wrap">
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

    <div class="entry-context misc-context">
      <b>🧷 نثرية</b>
      <span>مصروف عام مش على إذن</span>
    </div>

    <div class="hint misc-hint">
      النثرية دي بتتخصم من عهدتك وبتتحسب <b>مصروف على الشركة</b> — مش على حساب أي عميل.
      لو المبلغ ده مخصوص بإذن، سجّله من صفحة الإذن نفسه مش من هنا.
    </div>

    <div class="field" id="field-note" <?= $err('note') ? 'data-error="1"' : '' ?>>
      <label for="note">النثرية دي إيه؟</label>
      <input type="text" id="note" name="note" required maxlength="255"
             list="notes-misc" enterkeyhint="next" value="<?= $o('note') ?>"
             placeholder="مثال: مواصلات أو تصوير مستندات" aria-describedby="err-note"
             <?= $err('note') ? 'aria-invalid="true"' : '' ?>>
      <div class="suggestions">
        <?php foreach (array_slice($miscNotes, 0, 4) as $v): ?>
          <button type="button" data-note="<?= e($v) ?>"><?= e($v) ?></button>
        <?php endforeach; ?>
      </div>
      <datalist id="notes-misc"><?php foreach ($miscNotes as $v): ?><option value="<?= e($v) ?>"></option><?php endforeach; ?></datalist>
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
      <div class="hint" id="totalHint" hidden></div>
      <p class="field-error" id="err-unit_amount" <?= $err('unit_amount') ? '' : 'hidden' ?>><?= e($err('unit_amount')) ?></p>
    </div>

    <div class="multi-toggle-row">
      <span>دفعت نفس المبلغ أكتر من مرة؟</span>
      <button type="button" class="mini-action" id="multiToggle" aria-expanded="<?= $qtyValue > 1 ? 'true' : 'false' ?>">
        <?= $qtyValue > 1 ? 'العدد: ' . $qtyValue : 'أكتر من مرة' ?>
      </button>
    </div>
    <div class="field qty-extra" id="field-qty" <?= $qtyValue > 1 ? '' : 'hidden' ?> <?= $err('qty') ? 'data-error="1"' : '' ?>>
      <label for="qty">العدد بنفس السعر</label>
      <div class="qty">
        <button type="button" data-q="-1" aria-label="قلّل العدد">−</button>
        <input type="number" id="qty" name="qty" min="1" max="10000" required inputmode="numeric"
               value="<?= $qtyValue ?>" aria-describedby="err-qty" <?= $err('qty') ? 'aria-invalid="true"' : '' ?>>
        <button type="button" data-q="1" aria-label="زوّد العدد">+</button>
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
        <label for="d">تاريخ الصرف</label>
        <input type="date" id="d" name="entry_date" required max="<?= e($today) ?>"
               value="<?= $o('entry_date', $wd) ?>" aria-describedby="err-entry_date"
               <?= $err('entry_date') ? 'aria-invalid="true"' : '' ?>>
      <?php else: ?>
        <?php /* v49: الخادم بيفرض يوم العمل — فالتاريخ عرض مش إدخال */ ?>
        <?php $wdLabel = 'تاريخ الصرف — من عهدة يوم'; require __DIR__ . '/_workday.php'; ?>
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
  </div>

  <div class="dock">
    <button class="btn gold" type="submit" id="save"><?= $editing ? 'احفظ التعديل' : 'سجّل النثرية' ?></button>
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
  var baseSaveText = <?= js_json($editing ? 'احفظ التعديل' : 'سجّل النثرية') ?>;
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
      document.getElementById('multiToggle').textContent = 'العدد: ' + qty.value;
      total(); saveDraft();
    });
  });
  amt.addEventListener('input', total);
  qty.addEventListener('input', function () {
    document.getElementById('multiToggle').textContent = 'العدد: ' + (parseInt(qty.value, 10) || 1);
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
        control.value = value;
      });
      document.getElementById('draftNote').hidden = false;
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
    ['note','unit_amount','qty','ref_no','entry_date','photo','visa_card_id'].forEach(clearFieldError);
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
    var errors = {};
    if (!note.value.trim()) { errors.note = 'اكتب النثرية دي إيه، زي مواصلات.'; }
    if (moneyValue() <= 0) { errors.unit_amount = 'اكتب المبلغ اللي دفعته.'; }
    var q = parseInt(qty.value, 10); if (!q || q < 1 || q > 10000) { errors.qty = 'العدد لازم يكون من 1 لـ10000.'; }
    // v49: النثرية الجديدة بلا خانة تاريخ (الخادم بيفرض يوم العمل) — نتحقق بس لو الخانة ظاهرة (وضع التعديل).
    var dateEl = form.elements.entry_date;
    if (dateEl && !dateEl.value) { errors.entry_date = 'اكتب تاريخ الصرف.'; }
    else if (dateEl && dateEl.value > <?= js_json($today) ?>) { errors.entry_date = 'تاريخ الصرف ما ينفعش يكون في المستقبل.'; }
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
  total(); networkState(false);
})();
</script>
</body>
</html>
