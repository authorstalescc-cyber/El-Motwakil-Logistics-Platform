<?php
/**
 * تسليم عهدة لسائق (v45) — شاشة المندوب.
 *
 * ⚠️ الشاشة دي **مالهاش أي رقم بيخصّ المكتب** (قاعدة v35): بس عهدة المندوب
 *    نفسه، وعهدة البوليصة المفروضة (معلومة تشغيلية للسائق مش ربح المكتب).
 *
 * @var array $old @var array $errors @var array $permits @var array $bills
 * @var array $recent @var array $pocket
 */
$o = fn(string $k, $d = '') => e((string) ($old[$k] ?? $d));
$err = fn(string $k) => (string) ($errors[$k] ?? '');
$today = date('Y-m-d');
$money = fn($v) => number_format((float) $v, 2, '.', ',');
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0E2440">
<title>تسليم عهدة لسائق</title>
<link rel="icon" href="<?= url('assets/img/logo.png') ?>">
<link rel="stylesheet" href="<?= url('assets/css/m.css') ?>?v=<?= @filemtime(\App\Core\App::basePath('public/assets/css/m.css')) ?>">
<?php require __DIR__ . "/_pwa.php"; ?>
</head>
<body>

<div class="m-bar">
  <a href="<?= url('m') ?>" class="out">→ رجوع</a>
  <span class="sp"></span>
  <span class="who">تسليم عهدة لسائق</span>
</div>

<form method="post" action="<?= url('m/handover') ?>" enctype="multipart/form-data" id="f" novalidate>
  <?= csrfField() ?>

  <div class="wrap entry-wrap">
    <?php if ($m = flash('success')): ?><div class="flash ok"><?= e($m) ?></div><?php endif; ?>
    <?php if ($m = flash('error')): ?><div class="flash err"><?= e($m) ?></div><?php endif; ?>

    <?php if ($errors): ?>
      <div class="error-summary" role="alert">
        <b>راجع البيانات دي:</b>
        <ul><?php foreach ($errors as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <div class="hand-note">
      المبلغ ده <b>بيتخصم من عهدتك انت وبس</b>. مابيغيّرش البوليصة ولا أي حاجة تانية.
      <div class="hand-bal">الفاضل معاك دلوقتي: <b><?= $money($pocket['pocket']) ?></b> جنيه</div>
    </div>

    <?php if (!$permits): ?>
      <div class="empty"><div class="ic">📭</div>مفيش أذونات مفتوحة دلوقتي.</div>
    <?php else: ?>

    <div class="field" id="field-permit" >
      <label for="pm">الإذن</label>
      <select id="pm" name="_permit_pick">
        <option value="">— اختر الإذن —</option>
        <?php foreach ($permits as $p): ?>
          <?php if (empty($bills[(int) $p['id']])) { continue; } ?>
          <option value="<?= (int) $p['id'] ?>"
                  <?= (string) ($old['_permit_pick'] ?? '') === (string) $p['id'] ? 'selected' : '' ?>>
            <?= e($p['type_label']) ?> <?= e($p['no']) ?><?= $p['company'] ? ' — ' . e($p['company']) : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
      <div class="hint">الأذونات اللي ليها بوالص بس هي اللي بتظهر هنا.</div>
    </div>

    <div class="field" id="field-bawali_id" <?= $err('bawali_id') ? 'data-error="1"' : '' ?>>
      <label for="bw">البوليصة والسائق</label>
      <select id="bw" name="bawali_id" required aria-describedby="err-bawali_id"
              <?= $err('bawali_id') ? 'aria-invalid="true"' : '' ?>>
        <option value="">— اختر الإذن الأول —</option>
      </select>
      <p class="field-error" id="err-bawali_id" <?= $err('bawali_id') ? '' : 'hidden' ?>><?= e($err('bawali_id')) ?></p>
      <div class="bill-info" id="billInfo" hidden></div>
    </div>

    <div class="field" id="field-driver_name" <?= $err('driver_name') ? 'data-error="1"' : '' ?>>
      <label for="drv">اسم السائق</label>
      <input type="text" id="drv" name="driver_name" maxlength="180" value="<?= $o('driver_name') ?>"
             placeholder="بيتملّى لوحده من البوليصة" aria-describedby="err-driver_name"
             <?= $err('driver_name') ? 'aria-invalid="true"' : '' ?>>
      <p class="field-error" id="err-driver_name" <?= $err('driver_name') ? '' : 'hidden' ?>><?= e($err('driver_name')) ?></p>
    </div>

    <div class="field" id="field-car_no">
      <label for="car">رقم العربية <span class="optional">اختياري</span></label>
      <input type="text" id="car" name="car_no" maxlength="120" value="<?= $o('car_no') ?>"
             placeholder="بيتملّى لوحده من البوليصة">
    </div>

    <div class="field" id="field-amount" <?= $err('amount') ? 'data-error="1"' : '' ?>>
      <label for="amt">سلّمته كام؟</label>
      <div class="money-in">
        <input type="text" id="amt" name="amount" required inputmode="decimal"
               value="<?= $o('amount') ?>" placeholder="0" autocomplete="off"
               aria-describedby="err-amount" <?= $err('amount') ? 'aria-invalid="true"' : '' ?>>
        <span class="cur">جنيه</span>
      </div>
      <p class="field-error" id="err-amount" <?= $err('amount') ? '' : 'hidden' ?>><?= e($err('amount')) ?></p>
    </div>

    <div class="field" id="field-handover_date" <?= $err('handover_date') ? 'data-error="1"' : '' ?>>
      <?php /* v49: التسليم بيتخصم من عهدة يوم العمل — الخادم بيفرض التاريخ */ ?>
      <?php $wd = $workDate ?? date('Y-m-d'); $wdLabel = 'تاريخ التسليم — من عهدة يوم';
            require __DIR__ . '/_workday.php'; ?>
      <p class="field-error" id="err-handover_date" <?= $err('handover_date') ? '' : 'hidden' ?>><?= e($err('handover_date')) ?></p>
    </div>

    <div class="field" id="field-note">
      <label for="nt">ملاحظة <span class="optional">اختيارية</span></label>
      <input type="text" id="nt" name="note" maxlength="255" value="<?= $o('note') ?>">
    </div>

    <div class="field">
      <label>صورة الاستلام <span class="optional">اختيارية</span></label>
      <label class="shot" id="shotBtn">
        <span id="shotTxt">📷 صوّر أو اختار صورة</span>
        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/heic,image/heif" id="shot">
      </label>
      <div class="shot-prev" id="prev" hidden><img alt="معاينة" id="prevImg"></div>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($permits): ?>
  <div class="dock">
    <button class="btn gold" type="submit" id="save">سجّل التسليم</button>
  </div>
  <?php endif; ?>
</form>

<?php if ($recent): ?>
<div class="wrap">
  <div class="sec-h">آخر تسليماتك</div>
  <?php foreach ($recent as $h): ?>
    <div class="slip hand-slip">
      <div class="s-main">
        <b><?= e($h['driver_name']) ?></b>
        <span class="s-amt"><?= $money($h['amount']) ?></span>
      </div>
      <div class="s-meta">
        <?= e(dfmt($h['handover_date'])) ?>
        <?php if (!empty($h['bill_number'])): ?> · بوليصة <?= e($h['bill_number']) ?><?php endif; ?>
        <?php if (!empty($h['car_no'])): ?> · <bdi dir="ltr"><?= e($h['car_no']) ?></bdi><?php endif; ?>
        <?php if (!empty($h['permit_no'])): ?> · <?= e($h['permit_no']) ?><?php endif; ?>
      </div>
      <?php if (!empty($h['note'])): ?><div class="s-note"><?= e($h['note']) ?></div><?php endif; ?>
      <form method="post" action="<?= url('m/handover/' . (int) $h['id'] . '/delete') ?>"
            onsubmit="return confirm('تمسح التسليم ده؟ المبلغ هيرجع لعهدتك.')">
        <?= csrfField() ?>
        <button type="submit" class="mini-action danger">امسح</button>
      </form>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
(function () {
  'use strict';
  var BILLS = <?= js_json($bills) ?>;
  var pm = document.getElementById('pm'), bw = document.getElementById('bw'),
      drv = document.getElementById('drv'), car = document.getElementById('car'),
      info = document.getElementById('billInfo');
  if (!pm || !bw) { return; }

  var OLD_BILL = <?= js_json((string) ($old['bawali_id'] ?? '')) ?>;

  function money(v) {
    return (Math.round(Number(v) * 100) / 100).toLocaleString('en-US', {minimumFractionDigits: 2});
  }

  function fillBills() {
    var rows = BILLS[pm.value] || [];
    bw.innerHTML = '';
    var ph = document.createElement('option');
    ph.value = ''; ph.textContent = rows.length ? '— اختر البوليصة —' : '— اختر الإذن الأول —';
    bw.appendChild(ph);
    rows.forEach(function (b) {
      var op = document.createElement('option');
      op.value = b.id;
      var bits = [];
      if (b.bill_number) { bits.push('بوليصة ' + b.bill_number); }
      if (b.driver) { bits.push(b.driver); }
      if (b.car_no) { bits.push(b.car_no); }
      op.textContent = bits.join(' · ') || ('بوليصة #' + b.id);
      op.dataset.driver = b.driver || '';
      op.dataset.car = b.car_no || '';
      op.dataset.custody = b.custody || 0;
      op.dataset.dest = b.destination || '';
      if (String(b.id) === OLD_BILL) { op.selected = true; }
      bw.appendChild(op);
    });
    syncBill();
  }

  // اختيار البوليصة بيملّى السائق والعربية تلقائيًا — والمندوب يقدر يعدّلهم
  // (بيحصل إن السائق يتغيّر بعد ما البوليصة اتكتبت).
  function syncBill() {
    var op = bw.options[bw.selectedIndex];
    if (!op || !op.value) { info.hidden = true; return; }
    if (drv && !drv.value.trim()) { drv.value = op.dataset.driver || ''; }
    if (car && !car.value.trim()) { car.value = op.dataset.car || ''; }
    var bits = [];
    if (Number(op.dataset.custody) > 0) { bits.push('عهدة البوليصة: ' + money(op.dataset.custody) + ' جنيه'); }
    if (op.dataset.dest) { bits.push('الوجهة: ' + op.dataset.dest); }
    info.textContent = bits.join(' · ');
    info.hidden = bits.length === 0;
  }

  pm.addEventListener('change', function () { drv.value = ''; car.value = ''; fillBills(); });
  bw.addEventListener('change', syncBill);
  fillBills();

  var shot = document.getElementById('shot');
  if (shot) {
    shot.addEventListener('change', function () {
      var f = shot.files && shot.files[0];
      document.getElementById('shotTxt').textContent = f ? '✓ ' + f.name : '📷 صوّر أو اختار صورة';
      var prev = document.getElementById('prev'), img = document.getElementById('prevImg');
      if (f && img) { img.src = URL.createObjectURL(f); prev.hidden = false; } else if (prev) { prev.hidden = true; }
    });
  }
})();
</script>
</body>
</html>
