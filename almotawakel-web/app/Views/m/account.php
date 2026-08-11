<?php
/**
 * «حسابي» (v72) — راتب المندوب وسلفه ومصروفاته في فترة الرواتب.
 *
 * ⚠️ **عرض بحت**: مفيش ولا فورم كتابة في الشاشة دي غير زرار الخروج.
 * ⚠️ **ممنوع رقم أصمّ**: كل رقم هنا `<details>` بيفتح صفوفه — بلا جافاسكريبت
 *    خالص (العنصر أصلي في المتصفح، وبوابة `/m` ما بتحمّلش `app.js`).
 *
 * @var string $repName @var array $period @var array $prev @var array|null $next
 * @var array $sum @var array $advances @var array $expenses @var int $pending
 */
$num  = fn($v) => number_format((float) $v, 2, '.', ',');
$num0 = fn($v) => number_format((float) $v, 0, '.', ',');
$kind = fn($r) => \App\Models\RepMiscKind::label($r['misc_kind'] ?? null);
$owed = $sum['net'] < 0;   // سحب أكتر من راتبه
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0F151E">
<title>حسابي — المتوكل</title>
<link rel="icon" href="<?= url('assets/img/logo.png') ?>">
<link rel="stylesheet" href="<?= url('assets/css/m.css') ?>?v=<?= @filemtime(\App\Core\App::basePath('public/assets/css/m.css')) ?>">
<?php require __DIR__ . "/_pwa.php"; ?>
</head>
<?php /* ⚠️ **`hm-body hf-body` إلزامية**: قوالب `.hf-top`/`.hf-back` بتقرا
         `--hm-ink` و`--hm-ink-2` **المعرَّفة على `.hm-body` وبس**. من غير
         الكلاس، `var(--توكن-مش-موجود)` بيبطّل الإعلان كله **بالسكوت** فالترويسة
         بتطلع بلا خلفية (المصيدة الموثَّقة — اتصادت ١٩ مرة). */ ?>
<body class="hm-body hf-body">

<?php /* ⚠️ زرار الرجوع من `_back.php` لا مكتوبًا بالإيد: هو المصدر الوحيد
         لاتجاه السهم في RTL (شاشتان كانتا بسهم مقلوب قبل توحيده)، و
         `verify_navigation` بيعدّ الأزرار المكتوبة بالإيد بسقف متناقص. */ ?>
<div class="hf-top">
  <header class="hf-bar">
    <?php $backTo = 'm/start'; require __DIR__ . '/_back.php'; ?>
    <span class="hf-title">حسابي — <?= e($repName) ?></span>
  </header>
</div>

<main class="hf-sheet">
  <span class="hm-grab" aria-hidden="true"></span>

  <?php /* شريط الفترة — فترة رواتب لا شهر تقويمي (يوم البداية من الإعدادات) */ ?>
  <nav class="ma-period" aria-label="الفترة">
    <a class="ma-arrow" href="<?= url('m/account?p=' . e($prev['key'])) ?>" aria-label="الفترة السابقة">→</a>
    <b><?= e($period['label']) ?></b>
    <?php if ($next): ?>
      <a class="ma-arrow" href="<?= url('m/account?p=' . e($next['key'])) ?>" aria-label="الفترة التالية">←</a>
    <?php else: ?>
      <span class="ma-arrow is-off" aria-hidden="true">←</span>
    <?php endif; ?>
  </nav>

  <?php if ($sum['salary'] <= 0): ?>
    <?php /* تحذير وظيفي: الراتب مش متسجّل في كارته بالمكتب، فالمتبقي مالوش معنى */ ?>
    <div class="ma-warn"><?= icon('alert', 14) ?> راتبك لسه مش مسجّل — كلّم المكتب.</div>
  <?php endif; ?>

  <?php /* الرقم البطل: المتبقي = الراتب − السلف (المواصلات مش داخلة — قرار المالك) */ ?>
  <section class="ma-hero <?= $owed ? 'is-owed' : '' ?>">
    <span><?= $owed ? 'سحبت أكتر من راتبك' : 'المتبقي من راتبك' ?></span>
    <p class="ma-amt"><i>ج</i><b><?= $num0(abs($sum['net'])) ?></b></p>
  </section>

  <?php /* الراتب والسلف — كل واحد بيفتح تفاصيله */ ?>
  <details class="ma-row">
    <summary>
      <span class="ma-ic"><?= icon('banknote', 16) ?></span>
      <span class="ma-lbl">راتب الشهر</span>
      <span class="ma-val"><?= $num0($sum['salary']) ?></span>
    </summary>
    <div class="ma-body">
      <?php /* تفاصيل الرقم = الفترة اللي بيغطّيها + مين بيحطّه. مش شرح للواجهة. */ ?>
      <div class="ma-line">
        <span class="ma-line-n">فترة <?= e($period['label']) ?></span>
        <span class="ma-line-v"><?= $num($sum['salary']) ?></span>
      </div>
      <p class="ma-note">المكتب هو اللي بيحطّه.</p>
    </div>
  </details>

  <details class="ma-row is-minus" <?= $advances ? '' : 'data-empty="1"' ?>>
    <summary>
      <span class="ma-ic"><?= icon('wallet', 16) ?></span>
      <span class="ma-lbl">سلف سحبتها<?= $advances ? ' · ' . count($advances) : '' ?></span>
      <span class="ma-val">− <?= $num0($sum['advances']) ?></span>
    </summary>
    <div class="ma-body">
      <?php if (!$advances): ?>
        <p class="ma-note">ما سحبتش سلف في الفترة دي.</p>
      <?php else: foreach ($advances as $a): ?>
        <div class="ma-line">
          <span class="ma-line-d"><?= e(dfmt($a['exp_date'])) ?></span>
          <span class="ma-line-n"><?= e($a['note']) ?></span>
          <span class="ma-line-v"><?= $num($a['amount']) ?></span>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </details>

  <?php /* حصّتا الحوضين (v87) — كومسيون البوالص وباقي مصروف البوالص.
           ⚠️ الرقم بييجي من `Payroll` جاهزًا؛ الشاشة **ما بتحسبش** حاجة.
           ⚠️ حصة المصروف ممكن تبقى **بالسالب** لو المالك قرّر خصم الزيادة —
              وساعتها الصف بيتقلب لناقص بدل ما يعرض سالبًا في خانة موجب. */ ?>
  <details class="ma-row">
    <summary>
      <span class="ma-ic"><?= icon('coins', 16) ?></span>
      <span class="ma-lbl">كومسيون البوالص</span>
      <span class="ma-val"><?= $num0($sum['commission']) ?></span>
    </summary>
    <div class="ma-body">
      <div class="ma-line">
        <span class="ma-line-n">حصتك من فرق الـ١٠٠</span>
        <span class="ma-line-v"><?= $num($sum['commission']) ?></span>
      </div>
      <?php if (!$sum['approved']): ?>
        <p class="ma-note">لسه مسوّدة — ممكن يتغيّر.</p>
      <?php endif; ?>
    </div>
  </details>

  <details class="ma-row <?= $sum['expenseShare'] < 0 ? 'is-minus' : '' ?>">
    <summary>
      <span class="ma-ic"><?= icon('crane', 16) ?></span>
      <span class="ma-lbl">حصتك من مصروف البوالص</span>
      <span class="ma-val"><?= ($sum['expenseShare'] < 0 ? '− ' : '') . $num0(abs($sum['expenseShare'])) ?></span>
    </summary>
    <div class="ma-body">
      <div class="ma-line">
        <span class="ma-line-n">الباقي بعد مصروفاتكم</span>
        <span class="ma-line-v"><?= $num($sum['expenseShare']) ?></span>
      </div>
      <?php if (!$sum['approved']): ?>
        <p class="ma-note">لسه مسوّدة — ممكن يتغيّر.</p>
      <?php endif; ?>
    </div>
  </details>

  <?php /* زيادة / خصم / جزاء (v88) — البنود اللي المكتب سجّلها في بروفايله.
           ⚠️ الصف بيختفي خالص لما تكون صفر: صفر جنيه جزاء مش معلومة، وعرضه
              دايمًا بيخلّي المندوب يفتكر إن فيه حاجة عليه. */ ?>
  <?php if (abs($sum['adjustments']) > 0.005): $isCut = $sum['adjustments'] < 0; ?>
    <details class="ma-row <?= $isCut ? 'is-minus' : '' ?>">
      <summary>
        <span class="ma-ic"><?= icon($isCut ? 'alert' : 'star', 16) ?></span>
        <span class="ma-lbl"><?= $isCut ? 'خصومات وجزاءات' : 'مكافآت' ?></span>
        <span class="ma-val"><?= ($isCut ? '− ' : '') . $num0(abs($sum['adjustments'])) ?></span>
      </summary>
      <div class="ma-body">
        <div class="ma-line">
          <span class="ma-line-n">المكتب هو اللي بيحطّها</span>
          <span class="ma-line-v"><?= $num(abs($sum['adjustments'])) ?></span>
        </div>
      </div>
    </details>
  <?php endif; ?>

  <?php /* مصروفاتي — الأنواع اللي المالك اختارها من الإعدادات.
           ⚠️ **مش بتتخصم من الراتب** (قرار المالك): مصروف شركة دفعه من عهدته،
              وبيتحاسب من مصروف المناديب الأسبوعي. القسم كله بيختفي لو الإعداد فاضي. */ ?>
  <?php if ($sum['expenses']): ?>
    <h2 class="ma-head">مصروفاتي <span><?= $num0($sum['expensesTotal']) ?> ج</span></h2>

    <?php foreach ($sum['expenses'] as $k => $total): ?>
      <?php $rows = array_values(array_filter($expenses,
              fn($r) => \App\Models\RepMiscKind::normalize($r['misc_kind'] ?? null) === $k)); ?>
      <details class="ma-row">
        <summary>
          <span class="ma-ic"><?= icon(\App\Models\RepMiscKind::icon($k), 16) ?></span>
          <span class="ma-lbl"><?= e(\App\Models\RepMiscKind::label($k)) ?><?= $rows ? ' · ' . count($rows) : '' ?></span>
          <span class="ma-val"><?= $num0($total) ?></span>
        </summary>
        <div class="ma-body">
          <?php if (!$rows): ?>
            <p class="ma-note">مفيش حاجة في الفترة دي.</p>
          <?php else: foreach ($rows as $r): ?>
            <div class="ma-line">
              <span class="ma-line-d"><?= e(dfmt($r['exp_date'])) ?></span>
              <span class="ma-line-n"><?= e($r['note']) ?></span>
              <span class="ma-line-v"><?= $num($r['amount']) ?></span>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </details>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($pending > 0): ?>
    <?php /* تحذير وظيفي (≤٦ كلمات): الأرقام فوق معتمدة بس */ ?>
    <p class="ma-pending"><?= icon('clock', 14) ?> <?= (int) $pending ?> لسه تحت المراجعة</p>
  <?php endif; ?>

  <a class="ma-work" href="<?= url('m') ?>"><?= icon('truck', 15) ?> روح لشاشة العمل</a>
</main>

</body>
</html>
