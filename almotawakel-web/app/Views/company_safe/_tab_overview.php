<?php
/**
 * تبويب «نظرة عامة» — سؤال واحد: الفلوس فين دلوقتي وهل فيه حاجة محتاجة تصرّف؟
 *
 * الترتيب: كارت الرصيد (البطل) ← توزيع الكاش ← تنبيهات وظيفية ← آخر الحركات.
 *
 * ⚠️ كل رقم هنا **زرار** بيفتح بنوده — «ممنوع رقم أصمّ».
 * متغيّرات: $balance $wallets $breakdown $dayTotals $trend $recent $budget
 *           $officeBal $lowOffice $cd $tabUrl $labels $day
 */
use App\Models\CompanySafe;

$grand = max((float) $wallets['grand'], 0.0001);
$pc    = static fn (float $v): float => round(max($v, 0) / $grand * 100, 2);
$net   = round($dayTotals['in'] - $dayTotals['out'], 2);
?>

<?php /* ═══ ① كارت الرصيد — الرقم هو البطل والاتجاه جنبه ═══ */ ?>
<section class="cs-card hero">
  <div class="cs-card-main">
    <button type="button" class="cs-big" data-drill="<?= e($cd('balance')) ?>"
            data-drill-title="رصيد خزنة الشركة">
      <span class="lbl"><?= icon('home', 14) ?> رصيد خزنة الشركة</span>
      <span class="num<?= $balance < 0 ? ' neg' : '' ?>">
        <?= money0($balance, false) ?>
        <em><?= e(App\Core\App::config('currency', 'ج.م')) ?></em>
      </span>
    </button>

    <div class="cs-kpis">
      <button type="button" class="cs-kpi" data-drill="<?= e($cd('day')) ?>" data-drill-title="حركة اليوم">
        <span class="k">حركة اليوم</span>
        <b class="<?= $net < 0 ? 'neg' : 'pos' ?>"><?= ($net >= 0 ? '+' : '−') . money0(abs($net), false) ?></b>
        <em><?= intf($dayTotals['count']) ?> حركة</em>
      </button>
      <a class="cs-kpi" href="<?= url('custody') ?>">
        <span class="k">درج المكتب</span>
        <b class="<?= $officeBal < 0.005 ? 'neg' : '' ?>"><?= money0($officeBal, false) ?></b>
        <em>افتح الخزنة</em>
      </a>
      <button type="button" class="cs-kpi" data-drill="<?= e($cd('budget')) ?>" data-drill-title="مصروفات الشهر">
        <span class="k">مصروفات الشهر</span>
        <b class="<?= $budget['over'] ? 'neg' : '' ?>"><?= money0($budget['spent'], false) ?></b>
        <em><?= $budget['on'] ? intf($budget['pct']) . '% من السقف' : 'بلا سقف' ?></em>
      </button>
    </div>
  </div>

  <?php /* توزيع كل الكاش — كل جزء زرار، فالرسم نقطة دخول مش صورة. */ ?>
  <div class="cs-split">
    <div class="cs-bar">
      <?php if ($balance > 0): ?>
        <button type="button" class="cs-seg co" style="width:<?= $pc((float) $balance) ?>%"
                data-drill="<?= e($cd('balance')) ?>" data-drill-title="رصيد خزنة الشركة"></button>
      <?php endif; ?>
      <?php if ($wallets['drawer'] > 0): ?>
        <button type="button" class="cs-seg dr" style="width:<?= $pc((float) $wallets['drawer']) ?>%"
                data-drill="<?= e($cd('wallets')) ?>" data-drill-title="كل الكاش برّه البنك"></button>
      <?php endif; ?>
      <?php if ($wallets['reps_total'] > 0): ?>
        <button type="button" class="cs-seg rp" style="width:<?= $pc((float) $wallets['reps_total']) ?>%"
                data-drill="<?= e($cd('wallets')) ?>" data-drill-title="كل الكاش برّه البنك"></button>
      <?php endif; ?>
    </div>
    <div class="cs-legend">
      <button type="button" class="cs-leg" data-drill="<?= e($cd('balance')) ?>" data-drill-title="رصيد خزنة الشركة">
        <span class="cs-dot co"></span> الشركة <b><?= money0($balance, false) ?></b>
      </button>
      <button type="button" class="cs-leg" data-drill="<?= e($cd('wallets')) ?>" data-drill-title="كل الكاش برّه البنك">
        <span class="cs-dot dr"></span> الدرج <b><?= money0($wallets['drawer'], false) ?></b>
      </button>
      <button type="button" class="cs-leg" data-drill="<?= e($cd('wallets')) ?>" data-drill-title="كل الكاش برّه البنك">
        <span class="cs-dot rp"></span> المناديب <b><?= money0($wallets['reps_total'], false) ?></b>
        <em><?= intf(count($wallets['reps'])) ?></em>
      </button>
      <span class="cs-grand">كل الكاش <b><?= money0($wallets['grand'], false) ?></b></span>
    </div>
  </div>
</section>

<?php /* ═══ ② تنبيهات وظيفية — بتظهر بس لما يلزم، ومعاها القرار في إيدك ═══
         ⚠️ دي **تحذيرات مش شروح**: المدير مايعرفش إن الدرج فاضي غير لما يفتح
            شاشة تانية، والإجراء لازم يبقى جنب التحذير. */ ?>
<?php
$newUrl = static fn (string $kind): string =>
    url('company-safe') . '?tab=overview&new=1&kind=' . $kind;
?>
<?php if ($balance < -0.005): ?>
  <div class="cs-alert bad">
    <?= icon('alert', 17) ?> رصيد خزنة الشركة بالسالب
    <span class="spacer"></span>
    <a class="btn small" href="<?= e($newUrl('deposit')) ?>"><?= icon('plus', 14) ?> إيداع</a>
  </div>
<?php elseif ($officeBal < 0.005): ?>
  <div class="cs-alert">
    <?= icon('alert', 17) ?> درج المكتب فاضي
    <span class="spacer"></span>
    <a class="btn small gold" href="<?= e($newUrl('to_office')) ?>"><?= icon('landmark', 14) ?> حوّل للمكتب</a>
  </div>
<?php elseif ($lowOffice): ?>
  <div class="cs-alert">
    <?= icon('alert', 17) ?> درج المكتب قرب يخلص — <?= money($officeBal, false) ?>
    <span class="spacer"></span>
    <a class="btn small gold" href="<?= e($newUrl('to_office')) ?>"><?= icon('landmark', 14) ?> حوّل للمكتب</a>
  </div>
<?php endif; ?>
<?php if ($budget['over']): ?>
  <div class="cs-alert bad">
    <?= icon('alert', 17) ?> عدّيت ميزانية الشهر بـ<?= money(abs($budget['left']), false) ?>
    <span class="spacer"></span>
    <a class="btn small" href="<?= e($tabUrl('expenses')) ?>"><?= icon('search', 14) ?> شوف البنود</a>
  </div>
<?php endif; ?>

<?php /* ═══ ③ الاتجاه — كل عمود بيفلتر تبويب الحركات على يومه ═══ */ ?>
<?php if ($trend): ?>
  <?php partial('panel', [
    'title' => 'حركة آخر ١٤ يوم',
    'icon'  => 'trending-up',
    'class' => 'mb',
    'body'  => function () use ($trend) {
      partial('mini_bars', ['bars' => $trend, 'tone' => 'gold', 'height' => 96]);
    },
  ]); ?>
<?php endif; ?>

<?php /* ═══ ④ آخر الحركات — ٨ بس ومعاها باب لتبويب الحركات ═══ */ ?>
<?php partial('panel', [
  'title'  => 'آخر الحركات',
  'icon'   => 'layers',
  'action' => ['label' => 'كل الحركات', 'url' => $tabUrl('moves')],
  'body'   => function () use ($recent) {
    if (!$recent) { partial('empty', ['icon' => 'home', 'title' => 'مفيش حركات']); return; }
    foreach ($recent as $r) {
      /* ⚠️ العنوان = **الجهة** لما تكون موجودة، والنوع بيتحوّل لسطر الميتا
         (`subtitle` من الموديل) — «مصروف من خزنة الشركة» متكرّرة ٨ مرات كانت
         بتخفي المعلومة الوحيدة اللي بتفرّق الصفوف. */
      $meta = [['icon' => 'calendar', 'text' => dfmt((string) $r['entry_date'])]];
      if ($r['subtitle'])          { $meta[] = ['icon' => (string) $r['icon'], 'text' => (string) $r['subtitle']]; }
      if (!empty($r['cat_label'])) { $meta[] = ['icon' => 'tag', 'text' => (string) $r['cat_label']]; }
      // ⚠️ الملاحظة لازم تفضل ظاهرة — هي المكان الوحيد اللي المالك بيكتب فيه
      //    «شيك رقم كذا» أو سبب الحركة، وإخفاؤها بيخلّي الصف مبهمًا.
      if ($r['note'])              { $meta[] = ['icon' => 'file-text', 'text' => (string) $r['note']]; }
      partial('row_item', [
        'title'  => (string) $r['title'],
        'icon'   => (string) $r['icon'],
        'meta'   => $meta,
        'amount' => ($r['is_in'] ? '+ ' : '- ') . money((float) $r['amount'], false),
        'amount_tone' => $r['is_in'] ? 'pos' : 'ex',
      ]);
    }
  },
]); ?>
