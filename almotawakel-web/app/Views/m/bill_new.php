<?php
/**
 * بوليصة جديدة من موبايل المندوب (v60) — بديل شاشة «تسليم عهدة لسائق» (v45).
 *
 * ⚠️ **مفيش أي رقم بيخصّ العميل** (قاعدة v35): النولون والتحويلة والبياتة شغل
 *    المكتب وما بيظهروش هنا خالص. الرقم الوحيد هو **عهدة السائق** — فلوس
 *    المندوب دفعها من محفظته، وبتتخصم من عهدة يومه.
 *
 * ⚠️ **مفيش خانة صاحب البيان** (طلب المالك الحرفي) و**مفيش خانة تاريخ**
 *    (التاريخ = يوم العمل، الخادم بيفرضه — قاعدة v49). لو ضفت أي واحدة منهم
 *    هتبقى بتكدب على المندوب: يملاها والخادم يتجاهلها.
 *
 * الشاشة مقسومة خطوات مرقّمة عشان المندوب ما يتوهش، وكل خطوة بتأكّد اللي
 * قبلها (لوح بيانات الإذن · عدّاد سعة الحركة · مراجعة قبل الحفظ).
 *
 * @var array $old @var array $errors @var array $permits @var array $caps
 * @var array $recent @var array $pocket @var string $workDate @var string $formToken
 */
$o    = fn(string $k, $d = '') => e((string) ($old[$k] ?? $d));
$err  = fn(string $k) => (string) ($errors[$k] ?? '');
$money = fn($v) => number_format((float) $v, 2, '.', ',');
$oldCount = max(1, (int) ($old['container_count'] ?? 1));
$oldNos   = container_nos_split((string) ($old['container_no'] ?? ''), $oldCount);
/* رقم العربية = ٣ حروف + ٤ أرقام، كل حرف في خانته. عند إعادة التعبئة بعد خطأ
   بنرجّع اللي كتبه زي ما هو — الخانات جاية منفصلة أصلاً من الفورم. */
$carL = [
    (string) ($old['car_l1'] ?? ''),
    (string) ($old['car_l2'] ?? ''),
    (string) ($old['car_l3'] ?? ''),
];
$carD = (string) ($old['car_digits'] ?? '');
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0E2440">
<title>بوليصة جديدة — المتوكل</title>
<link rel="icon" href="<?= url('assets/img/logo.png') ?>">
<link rel="stylesheet" href="<?= url('assets/css/m.css') ?>?v=<?= @filemtime(\App\Core\App::basePath('public/assets/css/m.css')) ?>">
<?php require __DIR__ . "/_pwa.php"; ?>
</head>
<body>

<div class="m-bar">
  <a href="<?= url('m') ?>" class="out">→ رجوع</a>
  <span class="sp"></span>
  <span class="who"><?= icon('truck', 14) ?> بوليصة جديدة</span>
</div>

<form method="post" action="<?= url('m/bill/new') ?>" id="f" novalidate>
  <?= csrfField() ?>
  <?php /* ⚠️ رمز لمرة واحدة يستهلكه `claimForm` في billStore. شبكة الميناء بطيئة
           والمندوب بيدوس مرتين — وكل ضغطة كانت هتسجّل بوليصة وتخصم العهدة تاني.
           شيل السطر ده = كل حفظة هترفض. */ ?>
  <input type="hidden" name="_form_token" value="<?= e($formToken ?? '') ?>">

  <div class="wrap entry-wrap">
    <?php if ($m = flash('success')): ?><div class="flash ok"><?= e($m) ?></div><?php endif; ?>
    <?php if ($m = flash('error')): ?><div class="flash err"><?= e($m) ?></div><?php endif; ?>

    <?php if ($errors): ?>
      <div class="error-summary" id="errorSummary" role="alert" tabindex="-1">
        <b>راجع البيانات دي:</b>
        <ul><?php foreach ($errors as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <div class="hand-note">اكتب بيانات البوليصة زي ما هي في الورقة.</div>

    <?php /* v49: البوليصة بتتخصم من عهدة يوم العمل — الخادم بيفرض التاريخ */ ?>
    <?php $wd = $workDate ?? date('Y-m-d'); $wdLabel = 'تاريخ البوليصة — من عهدة يوم';
          require __DIR__ . '/_workday.php'; ?>

    <?php if (!$permits): ?>
      <div class="empty"><div class="ic"><?= icon('inbox', 14) ?></div>
        مفيش أذونات مفتوحة دلوقتي — كلّم المكتب.</div>
    <?php else: ?>

    <!-- ① الإذن ───────────────────────────────────────────────────────── -->
    <div class="bn-step"><span class="bn-num">1</span> الإذن والحركة</div>

    <div class="field" id="field-permit_id" <?= $err('permit_id') ? 'data-error="1"' : '' ?>>
      <label for="pm">الإذن / الشهادة</label>
      <select id="pm" name="permit_id" required aria-describedby="err-permit_id"
              <?= $err('permit_id') ? 'aria-invalid="true"' : '' ?>>
        <option value="">— اختار الإذن —</option>
        <?php foreach ($permits as $p): ?>
          <option value="<?= (int) $p['id'] ?>"
                  <?= (string) ($old['permit_id'] ?? '') === (string) $p['id'] ? 'selected' : '' ?>>
            <?= e($p['type_label']) ?> <?= e($p['no']) ?><?= $p['company'] ? ' — ' . e($p['company']) : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
      <p class="field-error" id="err-permit_id" <?= $err('permit_id') ? '' : 'hidden' ?>><?= e($err('permit_id')) ?></p>
      <?php /* لوح تأكيد: بيوري بيانات الإذن اللي اتختار عشان المندوب يتأكد إنه الصح */ ?>
      <div class="bn-permit" id="permitInfo" hidden></div>
    </div>

    <div class="field" id="field-movement_id" <?= $err('movement_id') ? 'data-error="1"' : '' ?>>
      <label for="mv">الحركة (النقلة)</label>
      <select id="mv" name="movement_id" aria-describedby="err-movement_id"
              <?= $err('movement_id') ? 'aria-invalid="true"' : '' ?>>
        <option value="">— اختار الإذن الأول —</option>
      </select>
      <div class="hint">لو الإذن ليه حركة واحدة بس بتتختار لوحدها.</div>
      <p class="field-error" id="err-movement_id" <?= $err('movement_id') ? '' : 'hidden' ?>><?= e($err('movement_id')) ?></p>
    </div>

    <!-- ② البوليصة ────────────────────────────────────────────────────── -->
    <div class="bn-step"><span class="bn-num">2</span> بيانات البوليصة</div>

    <div class="field" id="field-bill_number" <?= $err('bill_number') ? 'data-error="1"' : '' ?>>
      <label for="bno">رقم البوليصة</label>
      <input type="text" id="bno" name="bill_number" required maxlength="80" inputmode="text"
             enterkeyhint="next" autocomplete="off" value="<?= $o('bill_number') ?>"
             aria-describedby="err-bill_number" <?= $err('bill_number') ? 'aria-invalid="true"' : '' ?>>
      <p class="field-error" id="err-bill_number" <?= $err('bill_number') ? '' : 'hidden' ?>><?= e($err('bill_number')) ?></p>
    </div>

    <div class="field" id="field-container_count" <?= $err('container_count') ? 'data-error="1"' : '' ?>>
      <label for="cnt">عدد الحاويات</label>
      <div class="qty">
        <button type="button" data-q="-1" aria-label="قلّل العدد">−</button>
        <input type="number" id="cnt" name="container_count" min="1" max="10000" inputmode="numeric"
               value="<?= e((string) $oldCount) ?>"
               aria-describedby="err-container_count" <?= $err('container_count') ? 'aria-invalid="true"' : '' ?>>
        <button type="button" data-q="1" aria-label="زوّد العدد">+</button>
      </div>
      <?php /* عدّاد سعة الحركة — بيمنع الغلط قبل الحفظ. الخادم بيعيد الفحص. */ ?>
      <div class="bn-cap" id="capNote" hidden></div>
      <p class="field-error" id="err-container_count" <?= $err('container_count') ? '' : 'hidden' ?>><?= e($err('container_count')) ?></p>
    </div>

    <div class="field" id="field-container_type" <?= $err('container_type') ? 'data-error="1"' : '' ?>>
      <label for="ctype">تصنيف الحاويات</label>
      <?php partial('lookup_select', [
        'name'  => 'container_type',
        'type'  => 'container_type',
        'value' => html_entity_decode((string) $o('container_type'), ENT_QUOTES, 'UTF-8'),
        'blank' => '— اختار التصنيف —',
        'attrs' => 'id="ctype" aria-describedby="err-container_type"'
                 . ($err('container_type') ? ' aria-invalid="true"' : ''),
      ]); ?>
      <p class="field-error" id="err-container_type" <?= $err('container_type') ? '' : 'hidden' ?>><?= e($err('container_type')) ?></p>
    </div>

    <?php /* خانة لكل حاوية — بتتزوّد/تقلّ حيًّا مع العدد. الاسم `container_no[]`
             و`container_nos_normalize_request()` في bootstrap بتلمّهم في نصّ
             واحد قبل أي كنترولر (⚠️ تغيير الاسم بيكتب «Array» في القاعدة). */ ?>
    <div class="field" id="field-container_no" <?= $err('container_no') ? 'data-error="1"' : '' ?>>
      <label>أرقام الحاويات <span class="req">مطلوب رقم لكل حاوية</span></label>
      <div class="bn-nos" id="nosList">
        <?php foreach ($oldNos as $i => $one): ?>
          <div class="bn-no"><span><?= $i + 1 ?></span>
            <input name="container_no[]" value="<?= e($one) ?>" autocomplete="off" required
                   inputmode="text" aria-label="رقم الحاوية <?= $i + 1 ?>"></div>
        <?php endforeach; ?>
      </div>
      <p class="field-error" id="err-container_no" <?= $err('container_no') ? '' : 'hidden' ?>><?= e($err('container_no')) ?></p>
    </div>

    <!-- ③ السائق والعربية ─────────────────────────────────────────────── -->
    <div class="bn-step"><span class="bn-num">3</span> السائق والعربية</div>

    <?php /* ⚠️ زرار اختصار بحت — بيملا الخانات وبس. القيم بتعدّي على نفس تحقّق
             الخادم بالحرف (حارس الأسماء + شكل اللوحة)، والمندوب يقدر يعدّلها
             قبل الحفظ (السائق بيتغيّر على نفس العربية). */ ?>
    <?php if (!empty($officeCars)): ?>
      <div class="bn-quick" id="officeCars">
        <?php foreach ($officeCars as $oc): ?>
          <button type="button" class="bn-quick-btn js-office-car"
                  data-letters="<?= e(implode('|', $oc['letters'])) ?>"
                  data-digits="<?= e($oc['digits']) ?>"
                  data-driver="<?= e($oc['driver']) ?>"
                  data-owner="<?= e($oc['owner']) ?>">
            <?= icon('truck', 14) ?>
            <span>عربية المكتب<?= count($officeCars) > 1 ? ' — ' . e($oc['label']) : '' ?></span>
            <small><?= e($oc['plate']) ?><?= $oc['driver'] !== '' ? ' · ' . e($oc['driver']) : '' ?></small>
          </button>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php /* الاتنين إجباريين، وبيعدّوا على حارس الأسماء (تحذير 3). */ ?>
    <?php foreach ([
      ['driver',    'اسم السائق',   'dl_driver'],
      ['car_owner', 'صاحب العربية', 'dl_owner'],
    ] as [$name, $label, $listId]): ?>
    <div class="field" id="field-<?= $name ?>" <?= $err($name) ? 'data-error="1"' : '' ?>>
      <label for="f_<?= $name ?>"><?= e($label) ?></label>
      <input type="text" id="f_<?= $name ?>" name="<?= $name ?>" maxlength="150"
             list="<?= $listId ?>" enterkeyhint="next" autocomplete="off" required
             value="<?= $o($name) ?>"
             aria-describedby="err-<?= $name ?>" <?= $err($name) ? 'aria-invalid="true"' : '' ?>>
      <?php if ($err($name) !== ''): ?>
        <label class="newname-confirm">
          <input type="checkbox" name="newname_<?= $name ?>" value="1">
          ده اسم جديد فعلاً — سجّله زي ما كتبته
        </label>
      <?php endif; ?>
      <p class="field-error" id="err-<?= $name ?>" <?= $err($name) ? '' : 'hidden' ?>><?= e($err($name)) ?></p>
    </div>
    <?php endforeach; ?>

    <?php /* ⚠️ رقم العربية = **٣ حروف + ٤ أرقام**، كل حرف في خانته لوحده.
             الشكل ده هو 98.5% من اللوحات في بيانات الشركة، وتقييده بيمنع
             الإدخال الغلط تمامًا. الناتج المخزَّن «ح ح ح 1234» — نفس صيغة
             شاشة المكتب بالحرف، وإلا نفس العربية تتقري لوحتين مختلفتين. */ ?>
    <div class="field" id="field-car_no" <?= $err('car_no') ? 'data-error="1"' : '' ?>>
      <label for="car_l1">رقم العربية</label>
      <div class="bn-car">
        <div class="bn-car-l">
          <?php foreach ([0, 1, 2] as $i): ?>
            <input type="text" id="car_l<?= $i + 1 ?>" name="car_l<?= $i + 1 ?>" class="js-car-l"
                   maxlength="1" required autocomplete="off" inputmode="text"
                   value="<?= e($carL[$i]) ?>" aria-label="حرف العربية <?= $i + 1 ?>"
                   <?= $err('car_no') ? 'aria-invalid="true"' : '' ?>>
          <?php endforeach; ?>
        </div>
        <input type="text" id="car_digits" name="car_digits" class="bn-car-d" maxlength="4"
               required inputmode="numeric" autocomplete="off" pattern="[0-9]{4}"
               value="<?= e($carD) ?>" placeholder="1234" aria-label="أرقام العربية"
               <?= $err('car_no') ? 'aria-invalid="true"' : '' ?>>
      </div>
      <div class="hint">٣ حروف و٤ أرقام — زي ما هي في رخصة العربية.</div>
      <p class="field-error" id="err-car_no" <?= $err('car_no') ? '' : 'hidden' ?>><?= e($err('car_no')) ?></p>
    </div>

    <!-- ④ العهدة ─────────────────────────────────────────────────────── -->
    <div class="bn-step"><span class="bn-num">4</span> العهدة اللي سلّمتها</div>

    <div class="field" id="field-custody" <?= $err('custody') ? 'data-error="1"' : '' ?>>
      <label for="cus">عهدة السائق</label>
      <div class="money-in">
        <input type="text" id="cus" name="custody" required inputmode="decimal"
               value="<?= $o('custody') ?>" placeholder="0" autocomplete="off"
               aria-describedby="err-custody" <?= $err('custody') ? 'aria-invalid="true"' : '' ?>>
        <span class="cur">جنيه</span>
      </div>
      <div class="hint">اكتب <b>0</b> لو ما سلّمتش السائق فلوس.</div>
      <?php /* حساب حيّ: هيفضل معاك كام بعد البوليصة دي */ ?>
      <div class="bn-left" id="leftNote" hidden></div>
      <p class="field-error" id="err-custody" <?= $err('custody') ? '' : 'hidden' ?>><?= e($err('custody')) ?></p>
    </div>

    <?php /* مراجعة قبل الحفظ — آخر فرصة يشوف كل حاجة في سطرين */ ?>
    <div class="bn-review" id="review" hidden>
      <div class="bn-review-h"><?= icon('check', 14) ?> راجع قبل ما تحفظ</div>
      <dl id="reviewList"></dl>
    </div>

    <?php
      /* قوائم الاقتراح — نفس مصادر شاشة المكتب. */
      $lk = \App\Models\Lookup::allGrouped();
      foreach ([['dl_driver', 'driver'], ['dl_owner', 'car_owner']] as [$id, $type]) {
          echo '<datalist id="' . e($id) . '">';
          foreach (($lk[$type] ?? []) as $v) { echo '<option value="' . e((string) $v) . '"></option>'; }
          echo '</datalist>';
      }
    ?>
    <?php endif; ?>
  </div>

  <?php if ($permits): ?>
  <div class="dock">
    <button class="btn gold" type="submit" id="save">سجّل البوليصة</button>
  </div>
  <?php endif; ?>
</form>

<?php if ($recent): ?>
<div class="wrap">
  <div class="sec-h">بوالصك في اليوم ده</div>
  <?php foreach ($recent as $b): ?>
    <div class="slip hand-slip">
      <div class="s-main">
        <b><?= e($b['bill_number'] ?: ('بوليصة #' . (int) $b['id'])) ?></b>
        <span class="s-amt"><?= $money($b['custody']) ?></span>
      </div>
      <div class="s-meta">
        <?= e(dfmt($b['bill_date'])) ?>
        <?php if (!empty($b['driver'])): ?> · <?= e($b['driver']) ?><?php endif; ?>
        <?php if (!empty($b['car_no'])): ?> · <bdi dir="ltr"><?= e($b['car_no']) ?></bdi><?php endif; ?>
        <?php if (!empty($b['permit_no'])): ?> · <?= e($b['permit_no']) ?><?php endif; ?>
        <?php if ((int) $b['container_count'] > 0): ?>
          · <?= (int) $b['container_count'] ?><?= $b['container_type'] ? '×' . e(container_norm($b['container_type'])) : ' حاوية' ?>
        <?php endif; ?>
      </div>
      <form method="post" action="<?= url('m/bill/' . (int) $b['id'] . '/delete') ?>"
            onsubmit="return confirm('تمسح البوليصة دي؟ العهدة هترجع لعهدتك.')">
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
  var PERMITS = <?= js_json(array_map(static fn(array $p): array => [
        'id' => $p['id'], 'label' => $p['type_label'] . ' ' . $p['no'],
        'company' => $p['company'], 'client' => $p['client'],
        'destination' => $p['destination'], 'port' => $p['port'],
        'ctype' => $p['container_type'],
        'movements' => $p['movements'],
      ], $permits ?: [])) ?>;
  var CAPS = <?= js_json($caps ?? []) ?>;
  var OLD_MV = <?= js_json((string) ($old['movement_id'] ?? '')) ?>;
  var LEFT = <?= js_json((float) ($pocket['pocket'] ?? 0)) ?>;

  var pm = document.getElementById('pm'), mv = document.getElementById('mv'),
      info = document.getElementById('permitInfo'), cnt = document.getElementById('cnt'),
      cap = document.getElementById('capNote'), nos = document.getElementById('nosList'),
      cus = document.getElementById('cus'), left = document.getElementById('leftNote'),
      ctype = document.getElementById('ctype'),
      review = document.getElementById('review'), reviewList = document.getElementById('reviewList'),
      form = document.getElementById('f'), save = document.getElementById('save');
  if (!pm || !mv) { return; }

  var byId = {};
  PERMITS.forEach(function (p) { byId[String(p.id)] = p; });

  function money(v) {
    return (Math.round(Number(v) * 100) / 100).toLocaleString('en-US', {minimumFractionDigits: 2});
  }
  function num(el) { var v = parseFloat(String(el.value).replace(/[^\d.\-]/g, '')); return isFinite(v) ? v : 0; }

  /* ① اختيار الإذن: لوح تأكيد + ملء الحركات + تصنيف الحاويات الافتراضي */
  function syncPermit(keepValues) {
    var p = byId[pm.value];
    mv.innerHTML = '';
    if (!p) {
      info.hidden = true;
      mv.appendChild(new Option('— اختار الإذن الأول —', ''));
      syncCap(); return;
    }
    var bits = [];
    if (p.company)     { bits.push('<b>' + esc(p.company) + '</b>'); }
    if (p.client)      { bits.push('العميل: ' + esc(p.client)); }
    if (p.destination) { bits.push('الجهة: ' + esc(p.destination)); }
    if (p.port)        { bits.push('الميناء: ' + esc(p.port)); }
    info.innerHTML = bits.join(' · ');
    info.hidden = bits.length === 0;

    var mvs = p.movements || [];
    if (mvs.length !== 1) { mv.appendChild(new Option('— اختار الحركة —', '')); }
    mvs.forEach(function (m) { mv.appendChild(new Option(m.label, m.id)); });
    // حركة واحدة بس ⇒ بتتختار لوحدها (أقل ضغطة على المندوب)
    if (mvs.length === 1) { mv.value = String(mvs[0].id); }
    else if (OLD_MV && keepValues) { mv.value = OLD_MV; }
    mv.disabled = mvs.length === 0;

    // تصنيف الحاويات الافتراضي من الإذن — المندوب يقدر يغيّره
    if (ctype && !ctype.value && p.ctype) {
      for (var i = 0; i < ctype.options.length; i++) {
        if (ctype.options[i].value === p.ctype) { ctype.selectedIndex = i; break; }
      }
    }
    syncCap();
  }

  function esc(s) { var d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; }

  /* ② عدّاد سعة الحركة — تحذير حيّ قبل ما يحفظ ويترفض */
  function syncCap() {
    var c = CAPS[pm.value];
    if (!c) { cap.hidden = true; return; }
    var box = (mv.value && c.mv && c.mv[mv.value]) ? c.mv[mv.value] : c.permit;
    if (!box || !box.total) { cap.hidden = true; return; }
    var want = parseInt(cnt.value, 10) || 0;
    var over = box.allocated + want > box.total;
    cap.className = 'bn-cap' + (over ? ' is-over' : '');
    cap.textContent = over
      ? ('العدد ده أكتر من الفاضل — فاضل ' + box.left + ' من ' + box.total + '.')
      : ('فاضل ' + (box.left - want) + ' حاوية من ' + box.total
         + (box === c.permit ? ' في الإذن' : ' في الحركة دي'));
    cap.hidden = false;
  }

  /* ③ خانة لكل حاوية — بتتزوّد/تقلّ مع العدد.
     ⚠️ التقليل بيشيل الخانات **الفاضية من الآخر** بس، عشان رقم متكتب ما يضيعش. */
  function syncNos() {
    var want = Math.max(1, Math.min(50, parseInt(cnt.value, 10) || 1));
    var rows = nos.querySelectorAll('.bn-no');
    for (var i = rows.length - 1; i >= want; i--) {
      var input = rows[i].querySelector('input');
      if (input && input.value.trim() !== '') { break; }
      rows[i].remove();
    }
    var have = nos.querySelectorAll('.bn-no').length;
    for (var j = have; j < want; j++) {
      var row = document.createElement('div');
      row.className = 'bn-no';
      row.innerHTML = '<span>' + (j + 1) + '</span>';
      var el = document.createElement('input');
      el.name = 'container_no[]'; el.autocomplete = 'off';
      el.setAttribute('aria-label', 'رقم الحاوية ' + (j + 1));
      row.appendChild(el);
      nos.appendChild(row);
    }
    Array.prototype.forEach.call(nos.querySelectorAll('.bn-no span'), function (s, k) {
      s.textContent = String(k + 1);
    });
  }

  /* ④ الفاضل بعد العهدة — تحذير مش منع (قرار المالك في v49) */
  function syncLeft() {
    var v = num(cus);
    if (!v) { left.hidden = true; return; }
    var after = LEFT - v;
    left.className = 'bn-left' + (after < 0 ? ' is-neg' : '');
    left.textContent = after < 0
      ? ('هيبقى عليك ' + money(-after) + ' جنيه — عهدة اليوم مش مكفية.')
      : ('هيفضل معاك ' + money(after) + ' جنيه من عهدة اليوم.');
    left.hidden = false;
  }

  /* ⑤ لوح المراجعة — بيتحدّث مع أي تغيير فيبقى دايمًا صادق */
  function syncReview() {
    var p = byId[pm.value], bno = document.getElementById('bno').value.trim(),
        drv = document.getElementById('f_driver').value.trim(),
        own = document.getElementById('f_car_owner').value.trim(),
        car = plate();
    if (!p || !bno) { review.hidden = true; return; }
    var mvLabel = mv.options[mv.selectedIndex] ? mv.options[mv.selectedIndex].text : '';
    var rows = [
      ['البوليصة', bno],
      ['على', p.label + (mvLabel && mv.value ? ' — ' + mvLabel : '')],
      ['السائق', drv + (car ? ' · ' + car : '')],
      ['صاحب العربية', own || '—'],
      ['الحاويات', (parseInt(cnt.value, 10) || 0) + (ctype && ctype.value ? ' × ' + ctype.value : '')],
      ['العهدة', money(num(cus)) + ' جنيه من عهدتك']
    ];
    reviewList.innerHTML = rows.map(function (r) {
      return '<div><dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd></div>';
    }).join('');
    review.hidden = false;
  }

  function syncAll() { syncCap(); syncNos(); syncLeft(); syncReview(); }

  pm.addEventListener('change', function () { syncPermit(false); syncAll(); });
  mv.addEventListener('change', syncAll);
  cnt.addEventListener('input', syncAll);
  cus.addEventListener('input', function () { syncLeft(); syncReview(); });
  if (ctype) { ctype.addEventListener('change', syncReview); }
  ['bno', 'f_driver', 'f_car_owner', 'car_l1', 'car_l2', 'car_l3', 'car_digits']
    .forEach(function (id) {
      var el = document.getElementById(id);
      if (el) { el.addEventListener('input', syncReview); }
    });

  /* رقم العربية: ٣ خانات حرف + خانة أرقام.
     ⚠️ التنقّل التلقائي بيخلّي المندوب يكتب من غير ما يدوس على كل خانة —
        وده أهم حاجة تخلّي الشكل المقسوم أسهل من خانة واحدة مش أصعب. */
  var lBoxes = Array.prototype.slice.call(form.querySelectorAll('.js-car-l'));
  var dBox = document.getElementById('car_digits');
  function plate() {
    var ls = lBoxes.map(function (b) { return b.value.trim(); }).filter(Boolean);
    var ds = dBox ? dBox.value.trim() : '';
    return ls.length === 3 && ds ? ls.join(' ') + ' ' + ds : '';
  }
  lBoxes.forEach(function (box, i) {
    box.addEventListener('input', function () {
      // حرف واحد بس في كل خانة، والأرقام ما تدخلش خانة حرف
      box.value = box.value.replace(/[0-9\s]/g, '').slice(0, 1);
      if (box.value) { (lBoxes[i + 1] || dBox || box).focus(); }
    });
    // Backspace على خانة فاضية بيرجّع للي قبلها
    box.addEventListener('keydown', function (ev) {
      if (ev.key === 'Backspace' && !box.value && lBoxes[i - 1]) { lBoxes[i - 1].focus(); }
    });
  });
  if (dBox) {
    dBox.addEventListener('input', function () {
      dBox.value = dBox.value.replace(/\D/g, '').slice(0, 4);
    });
    dBox.addEventListener('keydown', function (ev) {
      if (ev.key === 'Backspace' && !dBox.value && lBoxes[2]) { lBoxes[2].focus(); }
    });
  }

  /* زرار «عربية المكتب» — بيملا اللوحة والسائق وصاحب العربية بضغطة.
     ⚠️ اختصار عرض بحت: القيم بتتحط في نفس الخانات وبتعدّي على نفس تحقّق
        الخادم، والمندوب يقدر يعدّل أي واحدة (السائق بيتغيّر على نفس العربية).
     ⚠️ الزرار بيتعلّم `on` عشان يبان إنه مضغوط، والتعليم بيتشال أول ما المندوب
        يغيّر أي خانة منهم — وإلا يفضل واصف حالة مبقتش صح. */
  var ocBtns = Array.prototype.slice.call(form.querySelectorAll('.js-office-car'));
  var drvBox = document.getElementById('f_driver');
  var ownBox = document.getElementById('f_car_owner');
  function clearOfficeMark() { ocBtns.forEach(function (b) { b.classList.remove('on'); }); }
  ocBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var ls = String(btn.dataset.letters || '').split('|');
      lBoxes.forEach(function (b, i) { b.value = ls[i] || ''; });
      if (dBox) { dBox.value = String(btn.dataset.digits || ''); }
      if (drvBox && btn.dataset.driver) { drvBox.value = btn.dataset.driver; }
      if (ownBox && btn.dataset.owner)  { ownBox.value = btn.dataset.owner; }
      clearOfficeMark();
      btn.classList.add('on');
      syncReview();
      // نودّي التركيز لأول خانة ناقصة بعد الملء عشان يكمّل من غير ما يدوّر
      var next = form.querySelector('#bno').value.trim() === '' ? form.querySelector('#bno') : cus;
      if (next) { next.focus(); }
    });
  });
  [drvBox, ownBox, dBox].concat(lBoxes).forEach(function (el) {
    if (el) { el.addEventListener('input', clearOfficeMark); }
  });
  form.querySelectorAll('.qty button').forEach(function (b) {
    b.addEventListener('click', function () {
      cnt.value = Math.max(1, Math.min(10000, (parseInt(cnt.value, 10) || 1) + parseInt(b.dataset.q, 10)));
      syncAll();
    });
  });

  // منع الإرسال المزدوج على شبكة بطيئة — البوليصة ما تتسجّلش مرتين.
  form.addEventListener('submit', function () {
    setTimeout(function () { save.disabled = true; save.textContent = 'بيتسجّل…'; }, 0);
  });

  syncPermit(true);
  syncAll();
  var summary = document.getElementById('errorSummary');
  if (summary) { summary.focus(); }
})();
</script>
</body>
</html>
