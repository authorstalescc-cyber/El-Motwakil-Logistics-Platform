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

/* ⚠️ أرقام الكروت **من `dayTotals()` مباشرةً** بمفتاح كل نوع — مفيش جمع ولا
   اشتقاق في الشاشة، فالكارت ونافذته بيقروا نفس الشرط بالظبط. */
$dayIn       = (float) $dayTotals['in'];
$dayToOffice = (float) $dayTotals['to_office'];
$dayExpense  = (float) $dayTotals['expense'];
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

    <?php /* ⚠️⚠️ **كل كارت رقمه هو ونافذته هي** (طلب المالك: «أبعد عن أي بيانات
             مضللة… كل كارت بيعرض بياناته التفصيلية فقط»). التلاتة الأولى نطاقهم
             **يوم العمل** بالظبط، والاتنين الأخيرة تفكيك محفظة المكتب. */ ?>
    <div class="cs-kpis">
      <button type="button" class="cs-kpi" data-drill="<?= e($cd('day_in')) ?>"
              data-drill-title="اللي دخل خزنة الشركة النهارده">
        <span class="k">دخل النهارده</span>
        <b class="<?= $dayIn > 0.005 ? 'pos' : '' ?>">+<?= money0($dayIn, false) ?></b>
        <em>إيداع وتحصيل</em>
      </button>

      <button type="button" class="cs-kpi" data-drill="<?= e($cd('day_to_office')) ?>"
              data-drill-title="اللي نزل لخزنة المكتب النهارده">
        <span class="k">نزل للمكتب</span>
        <b class="<?= $dayToOffice > 0.005 ? 'neg' : '' ?>"><?= money0($dayToOffice, false) ?></b>
        <em>تحويل للخزنة</em>
      </button>

      <button type="button" class="cs-kpi" data-drill="<?= e($cd('day_expense')) ?>"
              data-drill-title="مصروفات خزنة الشركة النهارده">
        <span class="k">مصروفات النهارده</span>
        <b class="<?= $dayExpense > 0.005 ? 'neg' : '' ?>"><?= money0($dayExpense, false) ?></b>
        <em>خرجت من الشركة</em>
      </button>

      <?php /* ⚠️ **الرقمان دول مختلفان فعلاً ولازم يفضلوا منفصلين**: «عهدة
               المكتب» = كل كاش المكتب (الدرج + اللي في جيوب المناديب)،
               و«الفعلي في الدرج» = اللي المالك يقدر يعدّه بإيده دلوقتي.
               عرض واحد منهم باسم التاني هو بالظبط البيان المضلِّل. */ ?>
      <button type="button" class="cs-kpi" data-drill="<?= e($cd('office_total')) ?>"
              data-drill-title="عهدة المكتب الحالية">
        <span class="k">عهدة المكتب</span>
        <b><?= money0($wallets['total'], false) ?></b>
        <em>درج + مناديب</em>
      </button>

      <button type="button" class="cs-kpi" data-drill="<?= e($cd('office_drawer')) ?>"
              data-drill-title="الفعلي في درج المكتب">
        <span class="k">الفعلي في الدرج</span>
        <b class="<?= $wallets['drawer'] < 0.005 ? 'neg' : '' ?>"><?= money0($wallets['drawer'], false) ?></b>
        <em>كاش في المكتب</em>
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
      if (!empty($r['wallet']))  { $meta[] = ['icon' => $r['kind'] === 'to_rep' ? 'truck' : 'credit-card',
                                            'text' => (string) $r['wallet']]; }
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
