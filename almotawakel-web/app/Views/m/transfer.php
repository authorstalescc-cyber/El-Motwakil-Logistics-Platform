<?php
/**
 * تحويل عهدة لمندوب (v46) — شاشة المندوب، بنفس روح التصميم الجديد (hf-).
 *
 * ⚠️ الشاشة **مالهاش أي رقم بيخصّ المكتب** (قاعدة v35): بس عهدة المندوب نفسه.
 *
 * @var array $old @var array $errors @var array $mates @var array $recent @var array $pocket
 */
$o = fn(string $k, $d = '') => e((string) ($old[$k] ?? $d));
$err = fn(string $k) => (string) ($errors[$k] ?? '');
$today = date('Y-m-d');
$num = fn($v) => number_format((float) $v, 0);
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0F151E">
<title>تحويل عهدة لمندوب</title>
<link rel="icon" href="<?= url('assets/img/logo.png') ?>">
<link rel="stylesheet" href="<?= url('assets/css/m.css') ?>?v=<?= @filemtime(\App\Core\App::basePath('public/assets/css/m.css')) ?>">
<?php require __DIR__ . "/_pwa.php"; ?>
</head>
<body class="hm-body hf-body">

<div class="hf-top">
  <header class="hf-bar">
    <?php $backTo = 'm'; require __DIR__ . '/_back.php'; ?>
    <span class="hf-title">تحويل عهدة لمندوب</span>
  </header>

  <div class="hf-context hf-context-bal">
    <span class="hf-bal-lbl">اللي معاك دلوقتي</span>
    <b class="hf-bal-amt"><?= $num($pocket['pocket']) ?> <small>جنيه</small></b>
    <span class="hf-note-line">المبلغ بيتخصم من عهدتك ويتزاد لعهدة زميلك. الخزنة ما بتتأثرش.</span>
  </div>
</div>

<form method="post" action="<?= url('m/transfer') ?>" id="f" novalidate>
  <?= csrfField() ?>
  <?php /* ⚠️ رمز لمرة واحدة يستهلكه `claimForm` في transferStore — تحويل مكرر
           بيخصم من عهدة المُرسِل مرتين ويزوّد عهدة الزميل مرتين.
           شيل السطر ده = كل تحويل هيترفض. */ ?>
  <input type="hidden" name="_form_token" value="<?= e($formToken ?? '') ?>">

  <main class="hf-sheet">
    <span class="hm-grab" aria-hidden="true"></span>

    <?php if ($m = flash('success')): ?><div class="flash ok"><?= e($m) ?></div><?php endif; ?>
    <?php if ($m = flash('error')): ?><div class="flash err"><?= e($m) ?></div><?php endif; ?>

    <?php if ($errors): ?>
      <div class="error-summary" role="alert">
        <b>راجع البيانات دي:</b>
        <ul><?php foreach ($errors as $msg): ?><li><?= e($msg) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <?php if (!$mates): ?>
      <div class="hm-empty"><span class="ic"><?= icon('user', 14) ?>‍<?= icon('briefcase', 14) ?></span><b>مفيش مناديب تانيين</b>
        <p>لازم يكون فيه مندوب تاني نشط عشان تحوّل له.</p></div>
    <?php else: ?>

    <div class="field" id="field-to_rep_id" <?= $err('to_rep_id') ? 'data-error="1"' : '' ?>>
      <label for="to">تحوّل لمين؟</label>
      <select id="to" name="to_rep_id" required aria-describedby="err-to_rep_id"
              <?= $err('to_rep_id') ? 'aria-invalid="true"' : '' ?>>
        <option value="">— اختر المندوب —</option>
        <?php foreach ($mates as $mate): ?>
          <option value="<?= (int) $mate['id'] ?>" <?= (string) ($old['to_rep_id'] ?? '') === (string) $mate['id'] ? 'selected' : '' ?>>
            <?= e($mate['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <p class="field-error" id="err-to_rep_id" <?= $err('to_rep_id') ? '' : 'hidden' ?>><?= e($err('to_rep_id')) ?></p>
    </div>

    <div class="field" id="field-amount" <?= $err('amount') ? 'data-error="1"' : '' ?>>
      <label for="amt">تحوّل كام؟</label>
      <div class="money-in">
        <input type="text" id="amt" name="amount" required inputmode="decimal"
               value="<?= $o('amount') ?>" placeholder="0" autocomplete="off"
               aria-describedby="err-amount" <?= $err('amount') ? 'aria-invalid="true"' : '' ?>>
        <span class="cur">جنيه</span>
      </div>
      <p class="field-error" id="err-amount" <?= $err('amount') ? '' : 'hidden' ?>><?= e($err('amount')) ?></p>
    </div>

    <div class="field" id="field-transfer_date" <?= $err('transfer_date') ? 'data-error="1"' : '' ?>>
      <?php /* v49: التحويل بيتحرّك على عهدة يوم العمل — الخادم بيفرض التاريخ */ ?>
      <?php $wd = $workDate ?? date('Y-m-d'); $wdLabel = 'تاريخ التحويل — من عهدة يوم';
            require __DIR__ . '/_workday.php'; ?>
      <p class="field-error" id="err-transfer_date" <?= $err('transfer_date') ? '' : 'hidden' ?>><?= e($err('transfer_date')) ?></p>
    </div>

    <div class="field" id="field-note">
      <label for="nt">ملاحظة <span class="optional">اختيارية</span></label>
      <input type="text" id="nt" name="note" maxlength="255" value="<?= $o('note') ?>"
             placeholder="مثال: سلّمته كاش في الميناء">
    </div>
    <?php endif; ?>

    <?php if ($recent): ?>
      <h3 class="hm-group" style="margin-top:22px">تحويلاتك في اليوم ده</h3>
      <?php foreach ($recent as $t): ?>
        <article class="hm-slip">
          <span class="hm-slip-ic" aria-hidden="true"><?= icon('shuffle', 14) ?></span>
          <div class="hm-slip-mid">
            <b><?= e($t['other_name']) ?></b>
            <div class="hm-slip-meta">
              <span><?= e(dfmt($t['transfer_date'])) ?></span>
              <?php if (!empty($t['note'])): ?><span>· <?= e($t['note']) ?></span><?php endif; ?>
            </div>
          </div>
          <div class="hm-slip-end">
            <span class="hm-slip-amt">−<?= $num($t['amount']) ?></span>
          </div>
          <div class="hm-slip-acts">
            <form method="post" action="<?= url('m/transfer/' . (int) $t['id'] . '/delete') ?>"
                  onsubmit="return confirm('تلغي التحويل ده؟ المبلغ هيرجع لعهدتك.')">
              <?= csrfField() ?>
              <button type="submit" aria-label="ألغِ التحويل">ألغِ</button>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </main>

  <?php if ($mates): ?>
  <div class="dock hf-dock">
    <button class="btn gold" type="submit" id="save">حوّل العهدة</button>
  </div>
  <?php endif; ?>
</form>

<script>
(function () {
  'use strict';
  // تطبيع الأرقام العربية في خانة المبلغ (نفس سلوك باقي الشاشات)
  var amt = document.getElementById('amt');
  if (amt) {
    amt.addEventListener('input', function () {
      amt.value = amt.value.replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); })
                           .replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); });
    });
  }
})();
</script>
</body>
</html>
