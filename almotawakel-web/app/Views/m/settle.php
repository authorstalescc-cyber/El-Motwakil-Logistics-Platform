<?php
/**
 * تصفية العهدة مع المكتب — شاشة المندوب.
 *
 * ⚠️ **دي الشاشة الوحيدة في البوابة اللي بتعرض رقمًا تراكميًّا** (كل الأيام) —
 *    مقصود: هو بيسلّم الكاش اللي في جيبه فعلاً مش عهدة يوم واحد. باقي الشاشات
 *    بتفضل على عهدة اليوم بالحرف (قاعدة v49).
 * ⚠️ **مفيش أي رقم بيخصّ المكتب ولا العميل** هنا (قاعدة v35) — بس فلوس المندوب.
 *
 * @var array $snap @var array $recent @var array $pocket @var string $workDate
 */
$num = fn($v) => number_format((float) $v, 0);
$amt      = (float) $snap['amount'];
$pending  = (float) $snap['pending'];
$blocked  = !empty($snap['blocked']);   // v2026-07-27: بنود مستنية بتوقف التصفية
$nothing  = abs($amt) < 0.005;
$owedToMe = $amt < -0.005;
$canClear = $amt > 0.005 && !$blocked;
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0F151E">
<title>تصفية العهدة مع المكتب</title>
<link rel="icon" href="<?= url('assets/img/logo.png') ?>">
<link rel="stylesheet" href="<?= url('assets/css/m.css') ?>?v=<?= @filemtime(\App\Core\App::basePath('public/assets/css/m.css')) ?>">
<?php require __DIR__ . "/_pwa.php"; ?>
</head>
<body class="hm-body hf-body">

<div class="hf-top">
  <header class="hf-bar">
    <?php $backTo = 'm'; require __DIR__ . '/_back.php'; ?>
    <span class="hf-title">تصفية العهدة مع المكتب</span>
  </header>

  <div class="hf-context hf-context-bal">
    <span class="hf-bal-lbl">
      <?= $nothing ? 'رصيدك مع المكتب' : ($owedToMe ? 'المكتب مستحق لك' : 'اللي معاك كاش من كل الأيام') ?>
    </span>
    <b class="hf-bal-amt"><?= $num(abs($amt)) ?> <small>جنيه</small></b>
    <span class="hf-note-line">
      <?= $nothing
            ? 'مفيش أي مبلغ مفتوح بينك وبين المكتب.'
            : ($owedToMe
                ? 'صرفت من جيبك أكتر من عهدتك — التصفية دي بيعملها المكتب لما يدفعلك.'
                : 'التصفية بتقفل الحساب كله وترجّع رصيدك صفر. مفيش ترحيل لبكرة.') ?>
    </span>
  </div>
</div>

<form method="post" action="<?= url('m/settle') ?>" id="f">
  <?= csrfField() ?>

  <main class="hf-sheet">
    <span class="hm-grab" aria-hidden="true"></span>

    <?php if ($m = flash('success')): ?><div class="flash ok"><?= e($m) ?></div><?php endif; ?>
    <?php if ($m = flash('error')): ?><div class="flash err"><?= e($m) ?></div><?php endif; ?>

    <?php if ($nothing): ?>
      <div class="hm-empty"><span class="ic"><?= icon('check', 14) ?></span><b>عهدتك مصفّرة</b>
        <p>مفيش مبلغ تسلّمه للمكتب دلوقتي.</p></div>
    <?php elseif ($owedToMe): ?>
      <div class="hm-empty"><span class="ic"><?= icon('building', 14) ?></span><b>المكتب هو اللي مستحق لك</b>
        <p>كلّم المكتب يستلم منك الورق ويدفعلك <?= $num(abs($amt)) ?> جنيه، وهو اللي هيقفل الحساب.</p></div>
    <?php else: ?>

      <?php /* تفكيك الرقم — عشان يعرف الرقم جه منين قبل ما يسلّم */ ?>
      <div class="ms-calc">
        <div><dt>نزل لك إجمالًا</dt><dd>+<?= $num($snap['deposits']) ?></dd></div>
        <?php if (($snap['transfer_in'] ?? 0) > 0): ?>
          <div><dt>وصلك من زمايلك</dt><dd>+<?= $num($snap['transfer_in']) ?></dd></div>
        <?php endif; ?>
        <div><dt>صرفته (معتمَد)</dt><dd>−<?= $num($snap['spent']) ?></dd></div>
        <?php if (($snap['transfer_out'] ?? 0) > 0): ?>
          <div><dt>حوّلته لزمايلك</dt><dd>−<?= $num($snap['transfer_out']) ?></dd></div>
        <?php endif; ?>
        <?php if (($snap['returned'] ?? 0) != 0): ?>
          <div><dt>سلّمته قبل كده</dt><dd>−<?= $num($snap['returned']) ?></dd></div>
        <?php endif; ?>
        <div class="ms-res"><dt>اللي هتسلّمه</dt><dd><?= $num($amt) ?></dd></div>
      </div>

      <?php if ($blocked): ?>
        <?php /* ⚠️ البنود المستنية بتوقف التصفية دلوقتي (بدل ما تتخصم من
                 المبلغ وتسيب رصيدًا مفتوح) — قرار المالك 2026-07-27. */ ?>
        <div class="hm-locked" style="background:#FFF7E0;border-color:#E0B44A;color:#4A3A10">
          <b>استنى — فيه <?= $num($pending) ?> ج لسه مستني مراجعة المكتب</b>
          <span>لازم المكتب يراجع البنود دي الأول (<?= (int) ($snap['pending_cnt'] ?? 0) ?> بند).
                بعدها التصفية هتقفل حسابك بالظبط.</span>
        </div>
      <?php endif; ?>

      <p class="ms-hint">
        سلّم الـ<b><?= $num($amt) ?></b> جنيه للمكتب الأول، وبعدين دوس الزرار تحت.
        بعد التصفية رصيدك هيبقى <b>صفر</b> ومش هيترحّل منه حاجة لبكرة.
      </p>
    <?php endif; ?>

    <?php if ($recent): ?>
      <h3 class="hm-group" style="margin-top:22px">آخر تصفياتك</h3>
      <?php foreach (array_slice($recent, 0, 10) as $s): ?>
        <?php $r = (float) $s['returned']; ?>
        <article class="hm-slip">
          <span class="hm-slip-ic" aria-hidden="true">🤝</span>
          <div class="hm-slip-mid">
            <b><?= $r < 0 ? 'المكتب دفع لك' : 'سلّمت للمكتب' ?></b>
            <div class="hm-slip-meta">
              <span><?= e(dfmt($s['settle_date'])) ?></span>
              <?php if (!empty($s['note'])): ?><span>· <?= e($s['note']) ?></span><?php endif; ?>
            </div>
          </div>
          <div class="hm-slip-end">
            <span class="hm-slip-amt"><?= $num(abs($r)) ?></span>
          </div>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </main>

  <?php if ($canClear): ?>
  <div class="dock hf-dock">
    <?php /* ⚠️ التأكيد مطلوب: العملية بتقفل الحساب كله ومش بتترجع غير من المكتب */ ?>
    <button class="btn gold" type="submit" id="save"
            onclick="return confirm('متأكد إنك سلّمت <?= $num($amt) ?> جنيه للمكتب؟ رصيدك هيبقى صفر.')">
      صفّي العهدة — سلّمت <?= $num($amt) ?> ج
    </button>
  </div>
  <?php endif; ?>
</form>

<style>
.ms-calc{display:flex;flex-direction:column;gap:2px;margin:4px 0 14px}
.ms-calc > div{display:flex;justify-content:space-between;align-items:center;
  padding:10px 12px;border-bottom:1px solid rgba(0,0,0,.06)}
.ms-calc dt{font-weight:500;color:#4A5568;margin:0}
.ms-calc dd{margin:0;font-weight:700;font-variant-numeric:tabular-nums}
.ms-calc .ms-res{border:0;margin-top:6px;border-radius:var(--radius-2);background:#F3EAD3}
.ms-calc .ms-res dt{color:#1C2B45}
.ms-calc .ms-res dd{font-size:var(--fs-6)}
.ms-hint{margin:14px 2px;line-height:1.9;color:#4A5568}
</style>
</body>
</html>
