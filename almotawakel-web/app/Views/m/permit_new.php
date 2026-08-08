<?php
/** فتح إذن/شهادة من موبايل المندوب (v43).
 *  ⚠️ **بلا أي رقم مالي** — النِسَب (الكشف/الضمان) شغل المكتب، والمندوب ما بيشوفهاش.
 *  فورم عادي بلا AJAX ولا مسودة: بيتملّى مرة واحدة والخادم بيرجّع الأخطاء بالـsession.
 *  @var array $old @var array $errors @var array $lookups */
$o   = fn(string $k, $d = '') => e((string) ($old[$k] ?? $d));
$err = fn(string $k) => (string) ($errors[$k] ?? '');
$type = ($old['permit_type'] ?? 'sader') === 'warid' ? 'warid' : 'sader';
$today = date('Y-m-d');
/** قائمة منسدلة من القوائم المتعلّمة — نفس مصادر شاشة المكتب. */
$dl = function (string $id, string $lookupType) use ($lookups) {
    $items = $lookups[$lookupType] ?? [];
    echo '<datalist id="' . e($id) . '">';
    foreach ($items as $v) { echo '<option value="' . e((string) $v) . '"></option>'; }
    echo '</datalist>';
};
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0E2440">
<title>إذن جديد — المتوكل</title>
<link rel="icon" href="<?= url('assets/img/logo.png') ?>">
<link rel="stylesheet" href="<?= url('assets/css/m.css') ?>?v=<?= @filemtime(\App\Core\App::basePath('public/assets/css/m.css')) ?>">
<?php require __DIR__ . "/_pwa.php"; ?>
</head>
<body>

<div class="m-bar">
  <a href="<?= url('m') ?>" class="out">→ رجوع</a>
  <span class="sp"></span>
  <span class="who"><?= icon('file-text', 14) ?> إذن جديد</span>
</div>

<form method="post" action="<?= url('m/permit/new') ?>" id="f">
  <?= csrfField() ?>

  <div class="wrap entry-wrap">
    <div class="error-summary" id="errorSummary" role="alert" tabindex="-1" <?= $errors ? '' : 'hidden' ?>>
      <b>راجع البيانات دي:</b>
      <ul>
        <?php foreach ($errors as $message): ?><li><?= e($message) ?></li><?php endforeach; ?>
      </ul>
    </div>

    <div class="hint misc-hint">
      اكتب بيانات الإذن زي ما هي في الورقة. الإذن هيتفتح على طول وتقدر تسجّل عليه إيصالاتك،
      والمكتب هيكمّل باقي بياناته.
    </div>

    <fieldset class="field" id="field-permit_type" <?= $err('permit_type') ? 'data-error="1"' : '' ?>>
      <legend>ده إيه؟</legend>
      <div class="pick" role="radiogroup" aria-describedby="err-permit_type">
        <input type="radio" name="permit_type" id="t_s" value="sader" <?= $type === 'sader' ? 'checked' : '' ?>>
        <label for="t_s">↗ إذن صادر</label>
        <input type="radio" name="permit_type" id="t_w" value="warid" <?= $type === 'warid' ? 'checked' : '' ?>>
        <label for="t_w">↘ شهادة وارد</label>
      </div>
      <p class="field-error" id="err-permit_type" <?= $err('permit_type') ? '' : 'hidden' ?>><?= e($err('permit_type')) ?></p>
    </fieldset>

    <div class="field" id="field-permit_no" <?= $err('permit_no') ? 'data-error="1"' : '' ?>>
      <label for="pno">الرقم <span class="optional">رقم الإذن أو الشهادة</span></label>
      <input type="text" id="pno" name="permit_no" required maxlength="60" inputmode="text"
             enterkeyhint="next" autocomplete="off" value="<?= $o('permit_no') ?>"
             aria-describedby="err-permit_no" <?= $err('permit_no') ? 'aria-invalid="true"' : '' ?>>
      <p class="field-error" id="err-permit_no" <?= $err('permit_no') ? '' : 'hidden' ?>><?= e($err('permit_no')) ?></p>
    </div>

    <div class="field" id="field-permit_date" <?= $err('permit_date') ? 'data-error="1"' : '' ?>>
      <label for="pdate">تاريخ الإذن</label>
      <input type="date" id="pdate" name="permit_date" required max="<?= e($today) ?>"
             value="<?= $o('permit_date', $today) ?>"
             aria-describedby="err-permit_date" <?= $err('permit_date') ? 'aria-invalid="true"' : '' ?>>
      <p class="field-error" id="err-permit_date" <?= $err('permit_date') ? '' : 'hidden' ?>><?= e($err('permit_date')) ?></p>
    </div>

    <?php
    /* الأسماء الثلاثة دي بتعدّي على حارس الأسماء في الخادم: لو الاسم قريب من اسم
       مسجّل بتهجئة تانية بيوقف ويطلب تأكيد، عشان ما يتعملش كيان مكرَّر (تحذير 3). */
    foreach ([
      ['company',     'الشركة',        'dl_company', 'اللي لصالحها الإذن', true],
      ['client',      'العميل',        'dl_client',  'الوكيل صاحب الشغل',  true],
      ['destination', 'الوجهة',        'dl_dest',    'اختياري',            false],
    ] as [$name, $label, $listId, $sub, $required]):
    ?>
    <div class="field" id="field-<?= $name ?>" <?= $err($name) ? 'data-error="1"' : '' ?>>
      <label for="f_<?= $name ?>"><?= e($label) ?> <span class="optional"><?= e($sub) ?></span></label>
      <input type="text" id="f_<?= $name ?>" name="<?= $name ?>" maxlength="120" list="<?= $listId ?>"
             enterkeyhint="next" autocomplete="off" value="<?= $o($name) ?>" <?= $required ? 'required' : '' ?>
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

    <?php
    /* ⚠️⚠️ **التسميتان كانتا غلط** (اتصلّحوا 2026-08-02 — بلاغ المالك «تكرار
       للتوكيل مرة باسم التوكيل ومرة باسم التوكيل الملاحي»): `shipping_co` كان
       مكتوب عليه «التوكيل الملاحي» و`agency` «التوكيل»، فالمندوب يشوف حقلين
       اسمهم واحد. التسمية المعتمدة هي بتاعة شاشة المكتب بالحرف:
       `shipping_co` = **شركة الشحن** · `agency` = **التوكيل الملاحي**.
       ممنوع تتفرّق التسمية بين الشاشتين — نفس العمود لازم يبقى له اسم واحد.

       ⚠️ والحقول دي **قوائم مقفولة** (v58): كانت `<input list=…>` فالمندوب يكتب
       قيمة والخادم يرفضها بعد ما يبعت. بقت `lookup_select` زي المكتب — بيختار
       من الموجود وخلاص. */
    foreach ([
      ['shipping_co', '🚢 شركة الشحن',  'اختياري — غالبًا نفس العميل'],
      ['agency',      '⚓ التوكيل الملاحي', 'اختياري'],
      ['port',        '🏗 الميناء',        'اختياري'],
    ] as [$name, $label, $sub]): ?>
    <div class="field" id="field-<?= $name ?>" <?= $err($name) ? 'data-error="1"' : '' ?>>
      <label for="f_<?= $name ?>"><?= e($label) ?> <span class="optional"><?= e($sub) ?></span></label>
      <?php partial('lookup_select', [
        'name'  => $name,
        'type'  => $name,
        'value' => html_entity_decode((string) $o($name), ENT_QUOTES, 'UTF-8'),
        'blank' => '— اختر —',
        'attrs' => 'id="f_' . $name . '" aria-describedby="err-' . $name . '"'
                 /* شركة الشحن بتتملى من العميل تلقائيًا (lookup-autofill.js) */
                 . ($name === 'shipping_co' ? ' data-autofill-from="client"' : '')
                 . ($err($name) ? ' aria-invalid="true"' : ''),
      ]); ?>
      <p class="field-error" id="err-<?= $name ?>" <?= $err($name) ? '' : 'hidden' ?>><?= e($err($name)) ?></p>
    </div>
    <?php endforeach; ?>

    <div class="field" id="field-containers" <?= $err('containers') ? 'data-error="1"' : '' ?>>
      <label for="cnt">عدد الحاويات <span class="optional">اختياري — سيبه 0 لو مش عارف</span></label>
      <div class="qty">
        <button type="button" data-q="-1" aria-label="قلّل العدد">−</button>
        <input type="number" id="cnt" name="containers" min="0" max="10000" inputmode="numeric"
               value="<?= $o('containers', '0') ?>"
               aria-describedby="err-containers" <?= $err('containers') ? 'aria-invalid="true"' : '' ?>>
        <button type="button" data-q="1" aria-label="زوّد العدد">+</button>
      </div>
      <p class="field-error" id="err-containers" <?= $err('containers') ? '' : 'hidden' ?>><?= e($err('containers')) ?></p>
    </div>

    <div class="field" id="field-container_type" <?= $err('container_type') ? 'data-error="1"' : '' ?>>
      <label for="ctype">تصنيف الحاويات <span class="optional">اختياري</span></label>
      <?php partial('lookup_select', [
        'name'  => 'container_type',
        'type'  => 'container_type',
        'value' => html_entity_decode((string) $o('container_type'), ENT_QUOTES, 'UTF-8'),
        'blank' => '— اختر التصنيف —',
        'attrs' => 'id="ctype" aria-describedby="err-container_type"'
                 . ($err('container_type') ? ' aria-invalid="true"' : ''),
      ]); ?>
      <p class="field-error" id="err-container_type" <?= $err('container_type') ? '' : 'hidden' ?>><?= e($err('container_type')) ?></p>
    </div>

    <div class="field" id="field-note" <?= $err('note') ? 'data-error="1"' : '' ?>>
      <label for="pnote">ملاحظة للمكتب <span class="optional">اختيارية</span></label>
      <input type="text" id="pnote" name="note" maxlength="500" enterkeyhint="done"
             value="<?= $o('note') ?>" aria-describedby="err-note"
             <?= $err('note') ? 'aria-invalid="true"' : '' ?>>
      <p class="field-error" id="err-note" <?= $err('note') ? '' : 'hidden' ?>><?= e($err('note')) ?></p>
    </div>

    <?php
      $dl('dl_company',  'company');
      $dl('dl_client',   'client');
      $dl('dl_dest',     'destination');
    ?>
  </div>

  <div class="dock">
    <button class="btn gold" type="submit" id="save">افتح الإذن</button>
  </div>
</form>

<script>
(function () {
  'use strict';
  var form = document.getElementById('f'), save = document.getElementById('save');
  var cnt = document.getElementById('cnt');
  form.querySelectorAll('.qty button').forEach(function (b) {
    b.addEventListener('click', function () {
      var v = (parseInt(cnt.value, 10) || 0) + parseInt(b.dataset.q, 10);
      cnt.value = Math.max(0, Math.min(10000, v));
    });
  });
  // منع الإرسال المزدوج على شبكة بطيئة — الإذن ما ينفعش يتفتح مرتين.
  form.addEventListener('submit', function () {
    setTimeout(function () { save.disabled = true; save.textContent = 'بيتفتح…'; }, 0);
  });
  var summary = document.getElementById('errorSummary');
  if (!summary.hidden) { summary.focus(); }
})();
</script>
</body>
</html>
