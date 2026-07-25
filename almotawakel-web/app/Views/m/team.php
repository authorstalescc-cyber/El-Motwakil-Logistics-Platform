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
    <a class="hf-back" href="<?= url('m') ?>" aria-label="رجوع">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 5l-7 7 7 7" fill="none"
           stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </a>
    <span class="hf-title">متابعة المناديب</span>
  </header>

  <section class="hm-balance">
    <div class="hm-balance-head"><span>فلوس برّه مع المناديب دلوقتي</span></div>
    <p class="hm-amt"><i>ج</i><b><?= $num($totals['balance']) ?></b></p>
    <div class="hm-more-box">
      <dl class="hm-dl">
        <div><dt>عهدة <?= e(dfmt((string) $totals['day'])) ?></dt><dd><?= $num($totals['day_balance']) ?></dd></div>
        <div><dt>نزل لهم</dt><dd><?= $num($totals['deposits']) ?></dd></div>
        <div><dt>صرفوا</dt><dd><?= $num($totals['spent']) ?></dd></div>
        <div><dt>مناديب</dt><dd><?= $num($totals['reps']) ?></dd></div>
        <div><dt>مستني مراجعة</dt><dd><?= $num($totals['pending_sum']) ?></dd></div>
      </dl>
    </div>
  </section>

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
      <span class="ic">🧑‍💼</span>
      <b>مفيش مندوب عليه حركة</b>
      <p>أول ما تنزل عهدة لمندوب هيبان هنا.</p>
    </div>
  <?php else: foreach ($reps as $r): ?>
    <?php
      $owed  = $r['balance'] < 0;                  // صرف أكتر مما نزل له = ليه فلوس عندنا
      $shown = abs((float) $r['balance']);
      $initial = mb_substr(trim((string) $r['name']) !== '' ? trim((string) $r['name']) : '؟', 0, 1);
    ?>
    <a class="tw-rep <?= !empty($r['low']) ? 'is-low' : '' ?>"
       href="<?= url('m/team/' . (int) $r['id']) ?>"
       data-search="<?= e(mb_strtolower((string) $r['name'])) ?>">
      <div class="tw-rep-head">
        <span class="tw-face" aria-hidden="true"><?= e($initial) ?></span>
        <span class="tw-rep-id">
          <b><?= e($r['name']) ?></b>
          <small>
            <?php if (!empty($r['last_entry'])): ?>
              آخر إدخال <?= e(dfmt($r['last_entry'])) ?>
            <?php else: ?>
              لسه ما دخّلش حاجة
            <?php endif; ?>
          </small>
        </span>
        <span class="tw-rep-bal <?= $owed ? 'is-owed' : '' ?>">
          <b><?= $num($shown) ?></b>
          <small><?= $owed ? 'ليه عندنا' : 'فاضل معاه' ?></small>
        </span>
      </div>

      <div class="tw-bar" role="img"
           aria-label="صرف <?= $num($r['spent']) ?> من <?= $num($r['deposits']) ?>">
        <i style="width:<?= round((float) $r['pc_spent'], 1) ?>%"></i>
      </div>

      <?php /* v49 — عهدة يوم العمل نفسه؛ الرقم الكبير فوق هو التراكمي (كل الأيام) */ ?>
      <div class="tw-day">
        <span>عهدة <?= e(dfmt((string) $r['day'])) ?></span>
        <b class="<?= (float) $r['day_balance'] < 0 ? 'is-neg' : '' ?>"><?= $num($r['day_balance']) ?></b>
        <small>نزل <?= $num($r['day_deposits']) ?> · صرف <?= $num($r['day_spent']) ?></small>
      </div>

      <div class="tw-facts">
        <span>نزل له <b><?= $num($r['deposits']) ?></b></span>
        <span>صرف <b><?= $num($r['spent']) ?></b></span>
        <?php if ((float) $r['returned'] > 0): ?><span>رجّع <b><?= $num($r['returned']) ?></b></span><?php endif; ?>
        <?php if ((int) $r['pending_cnt'] > 0): ?>
          <span class="is-wait">مستني <b><?= (int) $r['pending_cnt'] ?></b> بند · <?= $num($r['pending_sum']) ?></span>
        <?php endif; ?>
        <?php if (!empty($r['low'])): ?><span class="is-low">⚠️ عهدته قربت تخلص</span><?php endif; ?>
        <?php if (!empty($r['settled'])): ?><span class="is-done">✅ متسوّي حسابه</span><?php endif; ?>
      </div>
    </a>
  <?php endforeach; endif; ?>

  <div class="hm-empty" id="repNoResults" hidden>
    <span class="ic">🔎</span><b>مفيش مندوب مطابق</b>
  </div>

  <p class="tw-note">
    👀 دي شاشة متابعة بس — الاعتماد والرفض وتنزيل العهدة بيتعملوا من المكتب.
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
