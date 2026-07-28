<?php
/**
 * متابعة المناديب (v47) — شاشة المندوب المشرف على الموبايل.
 *
 * ⚠️ **عرض فقط.** مفيش هنا أي فورم ولا زرار بيكتب: الاعتماد والرفض وتنزيل
 *    العهدة والتسوية كلها فضلت في المكتب (`/rep-custody`).
 * ⚠️ **مفيش أي رقم بيخصّ العميل ولا الخزنة** (قاعدة v35) — بس عهدة كل مندوب.
 * ⚠️ الكلاسات ببادئة `tw-` (والمشترك `hm-`) عشان ما تلمسش الشاشات التانية.
 *
 * @var array $reps @var array $totals
 */
$num = fn($v) => number_format((float) $v, 0);
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0F151E">
<title>المناديب — المتوكل</title>
<link rel="icon" href="<?= url('assets/img/logo.png') ?>">
<link rel="stylesheet" href="<?= url('assets/css/m.css') ?>?v=<?= @filemtime(\App\Core\App::basePath('public/assets/css/m.css')) ?>">
<?php require __DIR__ . "/_pwa.php"; ?>
</head>
<body class="hm-body">

<div class="hm-top">
  <header class="hm-bar">
    <?php $backTo = 'm'; require __DIR__ . '/_back.php'; ?>
    <span class="hf-title">متابعة المناديب</span>
  </header>

  <?php /* ⚠️ الرقم الكبير = **عهدة يوم العمل بعد المستنّي**، مش التراكمي.
           عهدة المناديب يومية بلا ترحيل (v49)، فرقم بيجمع أيامًا سابقة بيتقري
           وكأنه فاضل من عهدة النهاردة. التراكمي («برّه معاهم») جوّه التفاصيل
           بتسمية صريحة لأنه الحقيقة المالية لكنه مش «باقي عهدة اليوم». */ ?>
  <section class="hm-balance">
    <div class="hm-balance-head">
      <span>الفاضل من عهدة <?= e(dfmt((string) $totals['day'])) ?></span>
    </div>
    <p class="hm-amt"><i>ج</i><b><?= $num($totals['day_pocket']) ?></b></p>
    <div class="hm-more-box">
      <dl class="hm-dl">
        <div><dt>نزل لهم</dt><dd><?= $num($totals['day_deposits']) ?></dd></div>
        <div><dt>صرفوا</dt><dd><?= $num($totals['day_spent']) ?></dd></div>
        <?php if ((float) $totals['day_pending'] > 0): ?>
          <div><dt>مستني مراجعة</dt><dd><?= $num($totals['day_pending']) ?></dd></div>
        <?php endif; ?>
        <div><dt>مناديب</dt><dd><?= $num($totals['reps']) ?></dd></div>
      </dl>
      <p class="tw-hint">كل الأرقام والبنود في الشاشة دي عن <b>يوم <?= e(dfmt((string) $totals['day'])) ?></b> بس — ولا بند من يوم تاني.</p>
    </div>
  </section>

  <?php /* ⚠️ شريط اليوم إلزامي بعد ما الشاشة بقت يوم واحد: من غيره المشرف يلاقي
           القوايم فاضية ومايعرفش إن السبب إنه شغّال على يوم تاني. */ ?>
  <a class="hm-wd <?= $totals['day'] === date('Y-m-d') ? '' : 'is-past' ?>" href="<?= url('m/work-date') ?>">
    <span class="hm-wd-ic"><?= icon('calendar', 16) ?></span>
    <span class="hm-wd-txt">
      <small>بتشوف بيانات يوم</small>
      <b><?= e(dfmt((string) $totals['day'])) ?><?= $totals['day'] === date('Y-m-d') ? ' (النهاردة)' : ' — مش النهاردة' ?></b>
    </span>
    <span class="hm-wd-go">غيّر</span>
  </a>

  <?php if ($totals['low'] > 0): ?>
    <div class="hm-low">
      <div class="hm-low-txt">
        <b><?= $num($totals['low']) ?> مندوب عهدته قربت تخلص</b>
        <span>محتاجين تنزيل جديد من المكتب</span>
      </div>
    </div>
  <?php endif; ?>
</div>

<main class="hm-sheet">
  <span class="hm-grab" aria-hidden="true"></span>

  <?php if ($m = flash('success')): ?><div class="flash ok"><?= e($m) ?></div><?php endif; ?>
  <?php if ($m = flash('error')): ?><div class="flash err"><?= e($m) ?></div><?php endif; ?>

  <div class="hm-sheet-head">
    <h2>كل مندوب</h2>
    <?php if ($reps): ?><span class="hm-count"><?= count($reps) ?></span><?php endif; ?>
  </div>

  <?php if (count($reps) > 5): ?>
    <div class="hm-search">
      <input type="search" id="repSearch" placeholder="دوّر باسم المندوب"
             autocomplete="off" aria-label="بحث في المناديب">
    </div>
  <?php endif; ?>

  <?php if (!$reps): ?>
    <div class="hm-empty">
      <span class="ic"><?= icon('user', 14) ?>‍<?= icon('briefcase', 14) ?></span>
      <b>مفيش مندوب عليه حركة</b>
      <p>أول ما تنزل عهدة لمندوب هيبان هنا.</p>
    </div>
  <?php else: foreach ($reps as $r): ?>
    <?php
      // ⚠️ الرقم الأساسي = عهدة اليوم بعد المستنّي (مش التراكمي — قاعدة v49).
      //    سالب = صرف في اليوم ده أكتر من اللي نزل له فيه ⇒ ليه فلوس عندنا.
      $owed  = (float) $r['day_pocket'] < 0;
      $shown = abs((float) $r['day_pocket']);
      $initial = mb_substr(trim((string) $r['name']) !== '' ? trim((string) $r['name']) : '؟', 0, 1);
    ?>
    <a class="tw-rep <?= !empty($r['low']) ? 'is-low' : '' ?>"
       href="<?= url('m/team/' . (int) $r['id']) ?>"
       data-search="<?= e(mb_strtolower((string) $r['name'])) ?>">
      <div class="tw-rep-head">
        <span class="tw-face" aria-hidden="true"><?= e($initial) ?></span>
        <span class="tw-rep-id">
          <b><?= e($r['name']) ?></b>
          <?php /* ⚠️ «آخر إدخال ٢٥/٧» اتشال: الشاشة بقت يوم واحد، وتاريخ من يوم
                   فات كان بيدّي إحساس إن فيه شغل النهاردة وهو مش كده. */ ?>
          <small>
            <?php if ((int) $r['day_entries'] > 0): ?>
              <?= (int) $r['day_entries'] ?> بند النهاردة<?php
                if ((int) $r['day_pending_cnt'] > 0) { echo ' · ' . (int) $r['day_pending_cnt'] . ' مستني'; } ?>
            <?php else: ?>
              ما دخّلش حاجة في اليوم ده
            <?php endif; ?>
          </small>
        </span>
        <span class="tw-rep-bal <?= $owed ? 'is-owed' : '' ?>">
          <b><?= $num($shown) ?></b>
          <small><?= $owed ? 'دفع أكتر — ليه عندنا' : 'فاضل من عهدة اليوم' ?></small>
        </span>
      </div>

      <?php /* الشريط بيوصف نفس الرقم الكبير: المصروف ثم المستنّي من عهدة اليوم */ ?>
      <div class="tw-bar" role="img"
           aria-label="صرف <?= $num($r['day_spent']) ?> من <?= $num($r['day_deposits']) ?> النازل اليوم">
        <i style="width:<?= round((float) $r['day_pc_spent'], 1) ?>%"></i>
        <i class="is-wait" style="width:<?= round((float) $r['day_pc_pending'], 1) ?>%"></i>
      </div>

      <?php /* معادلة عهدة اليوم — من فين طلع الرقم الكبير */ ?>
      <div class="tw-day">
        <span>عهدة <?= e(dfmt((string) $r['day'])) ?></span>
        <b class="<?= (float) $r['day_pocket'] < 0 ? 'is-neg' : '' ?>"><?= $num($r['day_pocket']) ?></b>
        <small>نزل <?= $num($r['day_deposits']) ?> · صرف <?= $num($r['day_spent']) ?><?php
          if ((float) $r['day_pending'] > 0) { echo ' · مستني ' . $num($r['day_pending']); } ?></small>
      </div>

      <?php /* ⚠️ الحقائق كلها عن اليوم بس (قرار المالك 2026-07-26): «برّه معاه (كل
               الأيام)» و«مستني كله» و«رجّع» التراكميين اتشالوا — كانوا بيخلطوا
               أرقام أيام تانية على كارت المفروض يوصف النهاردة. */ ?>
      <div class="tw-facts">
        <?php if ((float) $r['day_pending'] > 0): ?>
          <span class="is-wait">منهم <b><?= $num($r['day_pending']) ?></b> لسه مستني اعتماد</span>
        <?php endif; ?>
        <?php if ((float) $r['day_returned'] > 0.005): ?>
          <span>رجّع <b><?= $num($r['day_returned']) ?></b></span>
        <?php elseif ((float) $r['day_returned'] < -0.005): ?>
          <span>دفعناله <b><?= $num(abs((float) $r['day_returned'])) ?></b></span>
        <?php endif; ?>
        <?php if ((float) ($r['day_transfer_in'] ?? 0) > 0): ?>
          <span>وصله من زميل <b><?= $num($r['day_transfer_in']) ?></b></span>
        <?php endif; ?>
        <?php if ((float) ($r['day_transfer_out'] ?? 0) > 0): ?>
          <span>حوّل لزميل <b><?= $num($r['day_transfer_out']) ?></b></span>
        <?php endif; ?>
        <?php if (!empty($r['low'])): ?><span class="is-low"><?= icon('alert', 14) ?> عهدته قربت تخلص</span><?php endif; ?>
        <?php if (!empty($r['settled'])): ?><span class="is-done"><?= icon('check', 14) ?> متسوّي حسابه</span><?php endif; ?>
      </div>
    </a>
  <?php endforeach; endif; ?>

  <div class="hm-empty" id="repNoResults" hidden>
    <span class="ic"><?= icon('search', 14) ?></span><b>مفيش مندوب مطابق</b>
  </div>

  <p class="tw-note">
    <?= icon('search', 14) ?> دي شاشة متابعة بس — الاعتماد والرفض وتنزيل العهدة بيتعملوا من المكتب.
  </p>
</main>

<script>
(function () {
  'use strict';
  var search = document.getElementById('repSearch');
  if (!search) { return; }
  search.addEventListener('input', function () {
    var q = search.value.trim().toLocaleLowerCase('ar'), shown = 0;
    document.querySelectorAll('.tw-rep[data-search]').forEach(function (card) {
      card.hidden = q !== '' && card.dataset.search.indexOf(q) === -1;
      if (!card.hidden) { shown++; }
    });
    document.getElementById('repNoResults').hidden = shown !== 0;
  });
})();
</script>

</body>
</html>
