<?php
/** @var array $permit @var array $old @var array $pocket @var array $errors
 *  @var array|null $submission @var string $draftKey @var string $workDate */
$movs = $permit['movements'];
$editing = !empty($submission);
$o = fn(string $k, $d = '') => e((string) ($old[$k] ?? $d));
$err = fn(string $k) => (string) ($errors[$k] ?? '');
$kind = (string) ($old['kind'] ?? 'receipt');

/* ══ الوضع اللي الشاشة بتفتح عليه (v109.1) ══
   المصدر واحد: `$old` — سواء جاية من بند بيتعدّل (`formValues`) أو من محاولة
   فشلت (`$_POST`). القاعدة: فيه أسعار مفصّلة ⇒ «مختلفة» · العدد > ١ ⇒ «بنفس
   السعر» · غير كده ⇒ إيصال واحد (وده الافتراضي الساحق).
   ⚠️ الوضع **عرض بحت** — الخادم بيحكم من `amounts` و`qty` مش منه. */
$startRows = array_values(array_filter(array_map(
    static fn ($v) => trim((string) $v),
    (array) ($old['amounts'] ?? [])
), static fn ($v) => $v !== ''));
$startQty  = max(1, (int) ($old['qty'] ?? 1));
$startMode = $startRows ? 'diff' : ($startQty > 1 ? 'same' : 'one');
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
    <?php $backTo = 'm?tab=entries'; require __DIR__ . '/_back.php'; ?>
    <span class="hf-title"><?= $editing ? 'تعديل بند' : 'تسجيل بند جديد' ?></span>
  </header>

  <div class="hf-context">
    <span class="hf-kind <?= ($permit['type'] ?? 'sader') === 'warid' ? 'is-warid' : 'is-sader' ?>"><?= e($permit['type_label']) ?></span>
    <b><?= e($permit['company'] ?: $permit['client'] ?: 'بدون شركة') ?></b>
    <span class="hf-no"><?= e($permit['no']) ?></span>
    <?php if ($permit['container_label'] !== ''): ?>
      <span class="hf-box"><?= icon('package', 14) ?> <bdi dir="ltr"><?= e($permit['container_label']) ?></bdi></span>
    <?php endif; ?>
    <?php /* رحلة الشحنة (v62) — وصفي بحت، بلا أي رقم مالي */ ?>
    <a class="hf-docs" href="<?= url('m/permit/' . (int) $permit['id'] . '/track') ?>">
      <?= icon('map-pin', 14) ?> <?= e($permit['track']['label'] ?? 'حالة الرحلة') ?></a>
    <?php /* مستندات الأطراف (v56) — بتظهر بس لو فيه مستندات فعلاً */ ?>
    <?php if (!empty($permit['has_docs'])): ?>
      <a class="hf-docs" href="<?= url('m/permit/' . (int) $permit['id'] . '/docs') ?>">
        <?= icon('folder', 14) ?> المستندات</a>
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
        <label for="k_r"><?= icon('receipt', 14) ?> إيصال</label>
        <input type="radio" name="kind" id="k_e" value="expense" <?= $kind === 'expense' ? 'checked' : '' ?>>
        <label for="k_e"><?= icon('trending-down', 14) ?> مصروف</label>
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

    <?php /* ══ المبلغ — تلات أوضاع، واحد ظاهر في المرة (v109.1) ══
             ① إيصال واحد        → سعر بس (الشكل الأصلي بالحرف)
             ② أكتر بنفس السعر   → سعر + عدد  (الشكل القديم بالحرف — المالك
                                    محتاجه لأن الكميات بتلزم فعلاً)
             ③ أكتر بأسعار مختلفة → عدد، وبعدين خانة لكل إيصال

             ⚠️ **الوضع ① هو الافتراضي دايمًا** — أغلب البنود إيصال واحد،
                وأي وضع تاني بيتفتح بضغطة واعية. النسخة اللي قبلها خلّت خانة
                الأسعار المتعددة هي الافتراضي، والمندوب اللي بيسجّل إيصالًا
                واحدًا كان بيدفع تمن تعقيد مش محتاجه.

             ⚠️ **الفورم `novalidate`** (فوق) — فحقل `required` مخبّي ما
                بيمنعش الإرسال. لولاه كان لازم نشيل `required` مع كل تبديل
                وضع، ونسيان واحدة = زرار حفظ مش بيعمل حاجة **بلا أي رسالة**. */ ?>
    <div class="field" id="field-unit_amount" <?= $err('unit_amount') ? 'data-error="1"' : '' ?>>
      <label for="amt">دفعت كام؟ <span class="optional" id="amtHintLbl">سعر الواحدة</span></label>
      <div class="money-in">
        <input data-money type="text" id="amt" name="unit_amount" required inputmode="decimal"
               enterkeyhint="next" value="<?= $o('unit_amount') ?>" placeholder="0"
               autocomplete="off" aria-describedby="totalHint err-unit_amount"
               <?= $err('unit_amount') ? 'aria-invalid="true"' : '' ?>>
        <span class="cur">جنيه</span>
      </div>
      <div class="hint total-hint" id="totalHint" hidden></div>
      <p class="field-error" id="err-unit_amount" <?= $err('unit_amount') ? '' : 'hidden' ?>><?= e($err('unit_amount')) ?></p>
    </div>

    <?php /* الزرار بيفتح اختيارًا من اتنين — مش بيفتح وضعًا على طول، عشان
             المندوب يقرّر «نفس السعر ولا مختلف» قبل ما يشوف أي خانات. */ ?>
    <div class="multi-toggle-row" id="multiRow">
      <span>البند فيه أكتر من إيصال؟</span>
      <button type="button" class="mini-action" id="multiToggle" aria-expanded="false">أكتر من إيصال</button>
    </div>
    <div class="field qty-extra" id="modePick" hidden>
      <div class="pick" role="radiogroup" aria-label="نوع الإيصالات">
        <?php /* ⚠️ الأيقونتين من كتالوج `icon()` فعلاً — الاسم الغلط ما بيرميش
                 خطأ، بيرسم **دايرة فاضية** (مصيدة موثّقة، و`verify_v109`
                 بيفحصها). */ ?>
        <input type="radio" name="amount_mode" id="mode_same" value="same">
        <label for="mode_same"><?= icon('layers', 14) ?> بنفس السعر</label>
        <input type="radio" name="amount_mode" id="mode_diff" value="diff">
        <label for="mode_diff"><?= icon('columns', 14) ?> أسعار مختلفة</label>
      </div>
    </div>

    <?php /* عدّاد العدد — مشترك بين الوضعين ②③ (نفس الرقم بيقود الاتنين). */ ?>
    <div class="field qty-extra" id="field-qty" hidden <?= $err('qty') ? 'data-error="1"' : '' ?>>
      <label for="qty" id="qtyLbl">عدد الإيصالات بنفس السعر</label>
      <div class="qty">
        <button type="button" data-q="-1" aria-label="قلّل العدد">−</button>
        <input type="number" id="qty" name="qty" min="1" max="200" inputmode="numeric"
               value="<?= max(1, (int) ($old['qty'] ?? 1)) ?>" aria-describedby="err-qty"
               <?= $err('qty') ? 'aria-invalid="true"' : '' ?>>
        <button type="button" data-q="1" aria-label="زوّد العدد">+</button>
      </div>
      <p class="field-error" id="err-qty" <?= $err('qty') ? '' : 'hidden' ?>><?= e($err('qty')) ?></p>
    </div>

    <?php /* ③ خانة لكل إيصال — بتتولّد بالجافاسكريبت من العدد.
             ⚠️ الاسم `amounts[]` **مصفوفة**: الخادم بيستقبلها عن طريق
                `ReceiptLine::rawText()` اللي بتلمّها لنص واحد، فالقاعدة
                بتفضل معرَّفة في `parse()` وحدها. */ ?>
    <div class="field qty-extra" id="field-amounts" hidden <?= $err('amounts') ? 'data-error="1"' : '' ?>>
      <label>سعر كل إيصال</label>
      <div class="amt-rows" id="amtRows"></div>
      <div class="hint total-hint" id="variedHint" hidden></div>
      <p class="field-error" id="err-amounts" <?= $err('amounts') ? '' : 'hidden' ?>><?= e($err('amounts')) ?></p>
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

    <?php /* v57 — «غير مدفوع»: بند على الإذن بلا خصم من العهدة ولا الخزنة */ ?>
    <?php require __DIR__ . '/_unpaid_field.php'; ?>

    <div class="field" id="field-photo" <?= $err('photo') ? 'data-error="1"' : '' ?>>
      <label>صورة الإيصال <span class="optional">اختيارية</span></label>
      <?php if ($editing && !empty($submission['photo'])): ?>
        <div class="existing-photo">
          <img src="<?= url('attachments/submission/'.(int)$submission['id']) ?>" alt="الصورة الحالية">
          <label><input type="checkbox" name="remove_photo" value="1"> احذف الصورة الحالية</label>
        </div>
      <?php endif; ?>
      <label class="shot" id="shotBtn">
        <span id="shotTxt"><?= icon('search', 14) ?> صوّر أو اختار صورة</span>
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
  var amt  = document.getElementById('amt');            // سعر الواحدة (①②)
  var qty  = document.getElementById('qty');            // العدد (②③)
  var rows = document.getElementById('amtRows');        // خانات الأسعار (③)
  var note = document.getElementById('note');
  var hint = document.getElementById('totalHint');
  var vHint = document.getElementById('variedHint');
  var save = document.getElementById('save');
  var MAX_ROWS = 200;                                   // حدّ عملي لخانات الشاشة

  /* ⚠️ **قواعد الخانة نازلة من `ReceiptLine` مش مكتوبة هنا تاني.** الشاشة
     لازم تقرا النص بنفس طريقة الخادم بالظبط، وإلا المعاينة تقول «٣ إيصالات ·
     ٩٠٠» والحفظ يرفض — والمندوب مش هيفهم ليه. */
  var SEPS    = <?= js_json(\App\Models\ReceiptLine::SEPARATORS) ?>;
  var TOKEN_RE = new RegExp(<?= js_json(\App\Models\ReceiptLine::TOKEN_RE) ?>);
  var MULT_RE  = new RegExp(<?= js_json(\App\Models\ReceiptLine::MULT_RE) ?>);
  var MAX_LINES = <?= (int) \App\Models\ReceiptLine::MAX ?>;
  var baseSaveText = <?= js_json($editing ? 'احفظ التعديل' : 'سجّل البند') ?>;
  var draftKey = form.dataset.draftKey;
  var draftTtl = 24 * 60 * 60 * 1000;
  var busy = false;

  function ar2en(v) {
    return String(v || '').replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); })
      .replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); })
      .replace(/[٬,\s]/g, '').replace('٫', '.');
  }
  /** نفس `ReceiptLine::parse` — بيرجّع {list, bad} و`bad` = الرمز اللي مش رقم. */
  function parseAmounts(raw) {
    var s = ar2en(String(raw || '')).trim();
    SEPS.forEach(function (sep) { s = s.split(sep).join(' '); });
    s = s.split('٫').join('.').replace(/\s+/g, ' ').trim();
    if (s === '') { return {list: [], bad: null}; }

    var list = [];
    var toks = s.split(' ');
    for (var i = 0; i < toks.length; i++) {
      var tok = toks[i];
      if (tok === '') { continue; }
      var times = 1, m = MULT_RE.exec(tok);
      if (m) { tok = m[1]; times = parseInt(m[2], 10) || 0; if (times < 1) { return {list: [], bad: toks[i]}; } }
      if (!TOKEN_RE.test(tok)) { return {list: [], bad: toks[i]}; }
      for (var k = 0; k < times; k++) {
        list.push(Math.round(parseFloat(tok) * 100) / 100);
        if (list.length > MAX_LINES) { return {list: [], bad: toks[i]}; }
      }
    }
    return {list: list, bad: null};
  }

  /* ══ الوضع الحالي ══
     'one'  إيصال واحد · 'same' أكتر بنفس السعر · 'diff' أكتر بأسعار مختلفة
     ⚠️ **الوضع بيتحدّد من الشاشة بس، والخادم مش بيثق فيه**: هو بيقرا
        `amounts` لو موجودة وإلا `qty`×`unit_amount` — فأي تلاعب في الوضع
        مالوش أثر (القاعدة ③: الحارس في الموديل مش في الشاشة). */
  var mode = 'one';

  function unitValue() { return parseFloat(ar2en(amt.value)) || 0; }
  function qtyValue()  { return Math.max(1, Math.min(MAX_ROWS, parseInt(ar2en(qty.value), 10) || 1)); }

  /** أسعار الخانات في وضع «مختلفة» — الفاضية بتتحسب صفرًا لحد ما تتملّى. */
  function rowValues() {
    return Array.prototype.map.call(rows.querySelectorAll('input'), function (i) {
      return Math.round((parseFloat(ar2en(i.value)) || 0) * 100) / 100;
    });
  }
  /** فيه خانة فاضية لسه؟ (الحفظ بيترفض لحد ما تتملّى كلها) */
  function rowsIncomplete() {
    return Array.prototype.some.call(rows.querySelectorAll('input'), function (i) {
      return String(i.value).trim() === '' || (parseFloat(ar2en(i.value)) || 0) <= 0;
    });
  }

  /** القايمة اللي هتتبعت فعلاً — نفس اللي الخادم هيحسب منها. */
  function amountList() {
    if (mode === 'diff') { return rowValues(); }
    var u = unitValue();
    if (u <= 0) { return []; }
    var n = mode === 'same' ? qtyValue() : 1, out = [];
    for (var i = 0; i < n; i++) { out.push(u); }
    return out;
  }
  function moneyValue() {
    return amountList().reduce(function (a, b) { return a + b; }, 0);
  }

  /** بيبني خانة لكل إيصال، وبيحافظ على اللي اتكتب قبل تغيير العدد. */
  function buildRows(n) {
    var keep = rowValues();
    rows.innerHTML = '';
    for (var i = 0; i < n; i++) {
      var wrap = document.createElement('label');
      wrap.className = 'amt-row';
      var idx = document.createElement('span');
      idx.className = 'amt-idx';
      idx.textContent = (i + 1);
      var box = document.createElement('input');
      box.type = 'text';
      box.inputMode = 'decimal';
      box.autocomplete = 'off';
      box.enterKeyHint = i === n - 1 ? 'done' : 'next';
      box.name = 'amounts[]';
      box.placeholder = '0';
      box.setAttribute('aria-label', 'سعر الإيصال ' + (i + 1));
      if (keep[i] !== undefined && keep[i] > 0) { box.value = String(keep[i]); }
      wrap.appendChild(idx);
      wrap.appendChild(box);
      rows.appendChild(wrap);
    }
  }

  /** بيظهر/بيخفي الحقول حسب الوضع — **نقطة واحدة** بتقرّر شكل الشاشة. */
  function applyMode(next, focus) {
    mode = next;
    var diff = mode === 'diff';
    var multi = mode !== 'one';

    document.getElementById('field-unit_amount').hidden = diff;
    document.getElementById('field-qty').hidden = !multi;
    document.getElementById('field-amounts').hidden = !diff;
    document.getElementById('modePick').hidden = !multi;
    document.getElementById('multiToggle').setAttribute('aria-expanded', multi ? 'true' : 'false');
    document.getElementById('multiToggle').textContent = multi
      ? (diff ? 'أسعار مختلفة' : 'العدد: ' + qtyValue())
      : 'أكتر من إيصال';
    document.getElementById('qtyLbl').textContent = diff ? 'عدد الإيصالات' : 'عدد الإيصالات بنفس السعر';

    var r = document.getElementById('mode_' + (diff ? 'diff' : 'same'));
    if (multi && r) { r.checked = true; }

    if (diff) {
      var n = qtyValue();
      if (rows.querySelectorAll('input').length !== n) { buildRows(n); }
      if (focus) { var first = rows.querySelector('input'); if (first) { first.focus(); } }
    }
    total();
  }

  /** «٣×١٨٠ · ٢×٢٥٠» — نفس تجميع `ReceiptLine::summary`. */
  function groupLabel(list) {
    var counts = {}, order = [];
    list.forEach(function (v) {
      var k = v.toFixed(2);
      if (counts[k] === undefined) { counts[k] = 0; order.push(k); }
      counts[k]++;
    });
    order.sort(function (a, b) { return counts[b] - counts[a]; });
    return order.slice(0, 4).map(function (k) {
      return counts[k] + '×' + parseFloat(k).toLocaleString('en-US', {maximumFractionDigits: 2});
    }).join(' · ') + (order.length > 4 ? ' · +' + (order.length - 4) + ' غيرهم' : '');
  }

  function total() {
    var list = amountList();
    var sum = list.reduce(function (a, b) { return a + b; }, 0);
    var money = sum.toLocaleString('en-US', {maximumFractionDigits: 2});

    /* ⚠️ التلميح بيتعرض **جنب الخانة اللي المندوب بيكتب فيها**: تحت السعر في
       ①②، وتحت خانات الأسعار في ③ (خانة السعر مخبّية وقتها أصلاً). */
    if (mode === 'diff') {
      hint.hidden = true;
      var filled = list.filter(function (v) { return v > 0; }).length;
      vHint.textContent = sum > 0
        ? filled + ' من ' + list.length + ' · إجمالي ' + money + ' جنيه'
          + (filled > 1 ? ' — ' + groupLabel(list.filter(function (v) { return v > 0; })) : '')
        : '';
      vHint.hidden = sum <= 0;
    } else {
      vHint.hidden = true;
      hint.textContent = list.length > 1
        ? list.length + ' إيصال · إجمالي ' + money + ' جنيه (' + list.length + ' × '
          + unitValue().toLocaleString('en-US', {maximumFractionDigits: 2}) + ')'
        : 'الإجمالي: ' + money + ' جنيه';
      hint.hidden = sum <= 0;
    }

    save.textContent = sum > 0
      ? (<?= $editing ? 'true' : 'false' ?> ? 'احفظ ' : 'سجّل ') + money + ' جنيه'
      : baseSaveText;
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

  // ── أزرار الأوضاع
  document.getElementById('multiToggle').addEventListener('click', function () {
    /* الزرار بيفتح **الاختيار** مش وضعًا بعينه: المندوب يقرّر «نفس السعر ولا
       مختلف» قبل ما يشوف أي خانات. وضغطة تانية بترجّعه لإيصال واحد. */
    applyMode(mode === 'one' ? 'same' : 'one', false);
    saveDraft();
  });
  form.querySelectorAll('input[name="amount_mode"]').forEach(function (r) {
    r.addEventListener('change', function () { applyMode(r.value, r.value === 'diff'); saveDraft(); });
  });
  form.querySelectorAll('.qty button').forEach(function (b) {
    b.addEventListener('click', function () {
      qty.value = Math.max(1, Math.min(MAX_ROWS, qtyValue() + parseInt(b.dataset.q, 10)));
      if (mode === 'diff') { buildRows(qtyValue()); }
      applyMode(mode, false);
      saveDraft();
    });
  });
  qty.addEventListener('input', function () {
    if (mode === 'diff') { buildRows(qtyValue()); }
    applyMode(mode, false);
  });

  amt.addEventListener('input', total);
  /* ⚠️ الاستماع على الحاوية مش على كل خانة: الخانات بتتبني من جديد مع كل
     تغيير في العدد، فمستمع على الخانة نفسها كان بيضيع بعد أول إعادة بناء. */
  rows.addEventListener('input', function () { total(); saveDraft(); });

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
      /* ⚠️ خانات الأسعار **بتتخزّن مستقلّة**: كلها بنفس الاسم `amounts[]`،
         فحفظها في `values` بالاسم كان بيخلّي الأخيرة تدهس اللي قبلها
         والمسودة ترجع بسعر واحد بدل أربعة. */
      if (el.name === 'amounts[]') { return; }
      if ((el.type === 'radio' || el.type === 'checkbox') && !el.checked) { return; }
      data.values[el.name] = el.value;
    });
    data.mode = mode;
    data.rows = Array.prototype.map.call(rows.querySelectorAll('input'), function (i) { return i.value; });
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

      /* الوضع بيترجع قبل الخانات — `applyMode('diff')` هي اللي بتبنيها. */
      applyMode(draft.mode === 'diff' || draft.mode === 'same' ? draft.mode : 'one', false);
      if (draft.mode === 'diff' && draft.rows && draft.rows.length) {
        buildRows(draft.rows.length);
        Array.prototype.forEach.call(rows.querySelectorAll('input'), function (i, k) {
          i.value = draft.rows[k] || '';
        });
        qty.value = draft.rows.length;
      }
      total();
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

  // ── v57: «غير مدفوع» و«مدفوع بالفيزا» متنافيان — الخادم بيرفضهم مع بعض،
  //    والشاشة بتمنعهم من الأول عشان المندوب ما يوصلش لرسالة خطأ أصلاً.
  var unpaidChk = document.getElementById('unpaidChk');
  function syncPay(src) {
    if (!unpaidChk || !visaChk) { return; }
    if (src === 'unpaid' && unpaidChk.checked && visaChk.checked) { visaChk.checked = false; syncVisa(); }
    if (src === 'visa' && visaChk.checked && unpaidChk.checked) { unpaidChk.checked = false; }
  }
  if (unpaidChk) {
    unpaidChk.addEventListener('change', function () { syncPay('unpaid'); });
    if (visaChk) { visaChk.addEventListener('change', function () { syncPay('visa'); }); }
    syncPay('unpaid');
  }

  function clearErrors() {
    ['kind','movement_id','note','unit_amount','qty','amounts','ref_no','entry_date','photo','visa_card_id','unpaid'].forEach(clearFieldError);
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
    /* ⚠️ **الرسالة بتتعلّق على الخانة الظاهرة فعلاً**: في وضع «مختلفة» خانة
       السعر مخبّية، فرسالة عليها كانت هتخلّي المندوب يشوف «فيه غلط» ومايشوفش
       الغلط فين. */
    if (mode === 'diff') {
      var vals = rowValues();
      if (!vals.length) { errors.amounts = 'حدّد عدد الإيصالات الأول.'; }
      else if (rowsIncomplete()) { errors.amounts = 'فيه إيصال لسه من غير سعر.'; }
      else if (vals.reduce(function (a, b) { return a + b; }, 0) <= 0) { errors.amounts = 'إجمالي الأسعار لازم يكون أكبر من صفر.'; }
    } else {
      if (unitValue() <= 0) { errors.unit_amount = 'اكتب المبلغ اللي دفعته.'; }
      var q = parseInt(ar2en(qty.value), 10);
      if (mode === 'same' && (!q || q < 1 || q > MAX_ROWS)) { errors.qty = 'العدد لازم يكون من 1 لـ' + MAX_ROWS + '.'; }
    }
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
  /* ⚠️ **النت رجع ≠ البند اتبعت.** قبل كده البانر كان بيختفي وزرار «إعادة
     المحاولة» بيختفي معاه، فالبند يفضل واقف في الفورم لحد ما المندوب يفتكر
     يدوس «سجّل» تاني — وهو أصلاً واقف في الميناء وفاكر إنه خلص. دلوقتي
     الشاشة بتفضل تقوله صراحةً إن فيه بند لسه ما اتبعتش، والزرار باقي. */
  var pendingSend = false;
  window.addEventListener('online', function () {
    if (pendingSend) {
      net.hidden = false; net.className = 'net-state weak';
      netText.textContent = 'النت رجع — دوس «سجّل البند» عشان يروح للمكتب.';
      retry.hidden = false;
      return;
    }
    networkState(false);
  });
  window.addEventListener('offline', function () { networkState(false); saveDraft(); });
  if (navigator.connection) { navigator.connection.addEventListener('change', function () { networkState(false); }); }
  retry.addEventListener('click', function () { form.requestSubmit(); });

  form.addEventListener('submit', async function (event) {
    event.preventDefault();
    if (busy) { return; }
    var errors = clientErrors();
    if (Object.keys(errors).length) { showErrors(errors); return; }
    if (!navigator.onLine) { pendingSend = true; saveDraft(); networkState(true); return; }

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
      pendingSend = false;
      try { localStorage.removeItem(result.clearDraft || draftKey); } catch (e) {}
      window.location.assign(result.redirect);
    } catch (e) {
      pendingSend = true;
      busy = false; save.disabled = false; total(); saveDraft(); networkState(true);
    }
  });

  /* ⚠️ **الوضع الابتدائي قبل `restoreDraft`**: المسودة بتكتب فوقه لو موجودة،
     لكن لو مفيش مسودة (تعديل بند، أو محاولة رجعت بخطأ) لازم الشاشة تفتح على
     نفس وضع البند — وإلا المندوب يلاقي أسعاره المختلفة اختفت. */
  var START_ROWS = <?= js_json($startRows) ?>;
  applyMode(<?= js_json($startMode) ?>, false);
  if (START_ROWS.length) {
    buildRows(START_ROWS.length);
    Array.prototype.forEach.call(rows.querySelectorAll('input'), function (i, k) {
      i.value = START_ROWS[k] || '';
    });
    qty.value = START_ROWS.length;
  }

  restoreDraft();
  setKind((form.querySelector('input[name="kind"]:checked') || {}).value || 'receipt');
  total(); networkState(false);
})();
</script>

<?php /* ⚠️ بوب-أب التأكيد بعد الحفظ (طلب المالك) — بيظهر **مرة واحدة** بعد
         حفظ ناجح على نفس الإذن، وبيخيّر: بند تاني (الفورم قدّامه فاضي) ولا
         رجوع للأذونات. العلامة one-shot في الجلسة فما بيرجعش مع أي ريفريش. */ ?>
<?php if (!empty($justSaved)): ?>
<dialog class="m-done" id="mDone" aria-labelledby="mDoneT">
  <div class="m-done-box">
    <span class="m-done-ic"><?= icon('check-circle', 30) ?></span>
    <?php /* ⚠️ العلامة اتشالت من النص: فوقها `check-circle` بحجم ٣٠ في نفس
             الصندوق — فكانت **نفس الخبر مرتين** في سطر واحد. */ ?>
    <b id="mDoneT">اتسجّل</b>
    <p>البند راح للمكتب للمراجعة. تسجّل بند تاني على نفس الإذن؟</p>
    <div class="m-done-acts">
      <button type="button" class="is-primary" id="mDoneMore">سجّل بند تاني</button>
      <a href="<?= url('m') ?>">رجوع للأذونات</a>
    </div>
  </div>
</dialog>
<script>
(function () {
  var d = document.getElementById('mDone');
  if (!d) { return; }
  try { d.showModal(); } catch (e) { d.setAttribute('open', ''); }
  document.getElementById('mDoneMore').addEventListener('click', function () {
    try { d.close(); } catch (e) { d.removeAttribute('open'); }
  });
})();
</script>
<?php endif; ?>
</body>
</html>
