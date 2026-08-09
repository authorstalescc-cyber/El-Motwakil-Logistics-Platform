<?php
/**
 * تبويب «التقارير» — الفترة بالأرقام + كشف حساب جاهز للطباعة.
 *
 * ⚠️ **الافتراضي «اليوم»** (طلب المالك) — الشهر كان بيخلّي أرقام التقرير تخالف
 *    كروت النظرة العامة اليومية بلا سبب ظاهر. والفترة كلها من
 *    `CompanySafe::periodRange()` — المصدر الوحيد لأي فترة في الوحدة.
 * ⚠️ الأرقام من نفس دوال باقي التبويبات — فمستحيل التقرير يقول رقمًا يخالفهم.
 *
 * متغيّرات: $period $choices $rangeTotals $breakdown $trend $statement $balance $cd $labels
 */
use App\Models\CompanySafe;

$net    = round($rangeTotals['in'] - $rangeTotals['out'], 2);
$pUrl   = static fn (string $k): string => url('company-safe') . '?tab=reports&period=' . $k;
$isCustom = $period['key'] === 'custom';
?>

<?php /* ═══ شريط الفترة ═══ */ ?>
<form class="cs-period" method="get" action="<?= url('company-safe') ?>">
  <input type="hidden" name="tab" value="reports">
  <div class="cs-period-tabs">
    <?php foreach ($choices as $k => $lbl): ?>
      <?php if ($k === 'custom'): ?>
        <button type="button" class="cs-period-btn<?= $isCustom ? ' on' : '' ?>"
                data-cs-period-toggle><?= icon('calendar', 13) ?> <?= e($lbl) ?></button>
      <?php else: ?>
        <a class="cs-period-btn<?= $period['key'] === $k ? ' on' : '' ?>"
           href="<?= e($pUrl($k)) ?>"><?= e($lbl) ?></a>
      <?php endif; ?>
    <?php endforeach; ?>
    <span class="cs-period-now"><?= e($period['label']) ?></span>
  </div>
  <?php /* الفترة المخصصة — بتظهر لو مختارة، والزرار فوق بيفتحها بالجافاسكريبت.
           بدون سكربت بتفضل ظاهرة والفورم شغّال زي ما هو. */ ?>
  <div class="cs-period-custom" id="csPeriodCustom" <?= $isCustom ? '' : 'hidden' ?>>
    <input type="hidden" name="period" value="custom">
    <label>من <input type="date" name="from" value="<?= e($period['from']) ?>" required></label>
    <label>إلى <input type="date" name="to" value="<?= e($period['to']) ?>" required></label>
    <button class="btn" type="submit"><?= icon('filter', 14) ?> اعرض</button>
  </div>
</form>

<?php /* ═══ ① الشهر في ٤ أرقام ═══ */ ?>
<?php partial('stat_grid', ['items' => 4, 'class' => 'mb', 'body' => function () use ($rangeTotals, $net, $balance, $cd, $period) { ?>
  <?php partial('stat', [
    'label' => 'داخل ' . $period['label'], 'value' => intf($rangeTotals['in']),
    'icon'  => 'wallet', 'tone' => 'green', 'drill' => $cd('in'), 'title' => 'الكاش الداخل',
  ]); ?>
  <?php partial('stat', [
    'label' => 'خارج ' . $period['label'], 'value' => intf($rangeTotals['out']),
    'icon'  => 'trending-down', 'tone' => 'red', 'drill' => $cd('out'), 'title' => 'الكاش الخارج',
  ]); ?>
  <?php partial('stat', [
    'label' => 'صافي ' . $period['label'], 'value' => intf($net),
    'icon'  => $net < 0 ? 'trending-down' : 'trending-up',
    'tone'  => $net < 0 ? 'red' : 'green',
    'drill' => $cd('balance'), 'title' => 'رصيد خزنة الشركة',
  ]); ?>
  <?php partial('stat', [
    'label' => 'الرصيد الحالي', 'value' => intf($balance),
    'icon'  => 'home', 'tone' => 'navy', 'drill' => $cd('balance'), 'title' => 'رصيد خزنة الشركة',
  ]); ?>
<?php }]); ?>

<?php /* ═══ ② تفكيك الرصيد بالنوع ═══
         ⚠️ الأقسام بتتولّد من `KINDS` + `isIn()` — قايمة مكتوبة بالإيد كانت
            هتسيب أي نوع جديد برّه التفكيك **بلا أي خطأ ظاهر**. */ ?>
<div class="cs-two">
  <?php partial('panel', [
    'title' => 'راح منين لفين — ' . $period['label'],
    'icon'  => 'shuffle',
    'body'  => function () use ($breakdown, $labels, $cd) {
      $any = false;
      foreach (CompanySafe::KINDS as $k) {
        if (abs((float) ($breakdown[$k] ?? 0)) < 0.005) { continue; }
        $any = true;
        $in  = CompanySafe::isIn($k);
        partial('row_item', [
          'title'  => $labels[$k], 'icon' => CompanySafe::icon($k),
          'drill'  => $cd($k === 'to_office' ? 'to_office' : ($k === 'expense' ? 'expense' : ($in ? 'in' : 'out'))),
          'amount' => ($in ? '+ ' : '- ') . money((float) $breakdown[$k], false),
          'amount_tone' => $in ? 'pos' : 'ex',
        ]);
      }
      if (!$any) { partial('empty', ['icon' => 'home', 'title' => 'مفيش حركات لسه']); }
    },
  ]); ?>

  <?php partial('panel', [
    'title' => 'حركة آخر ١٤ يوم',
    'icon'  => 'trending-up',
    'body'  => function () use ($trend) {
      if (!$trend) { partial('empty', ['icon' => 'trending-up', 'title' => 'مفيش حركة في الفترة']); return; }
      partial('mini_bars', ['bars' => $trend, 'tone' => 'gold', 'height' => 120]);
    },
  ]); ?>
</div>

<?php /* ═══ ③ كشف حساب الشهر — نفس أرقام الورقة المطبوعة بالحرف ═══ */ ?>
<?php partial('panel', [
  'title'  => 'كشف حساب ' . dfmt($period['from']) . ' — ' . dfmt($period['to']),
  'icon'   => 'file-text',
  'action' => ['label' => 'اطبع', 'url' => url('company-safe/statement')
                                         . '?from=' . $period['from'] . '&to=' . $period['to']],
  'body'   => function () use ($statement) { ?>
    <div class="cs-stmt">
      <div class="cs-stmt-cell">
        <span>رصيد أول المدة</span>
        <b><?= money0($statement['opening'], false) ?></b>
      </div>
      <div class="cs-stmt-cell">
        <span>داخل</span>
        <b class="pos">+ <?= money0($statement['in'], false) ?></b>
      </div>
      <div class="cs-stmt-cell">
        <span>خارج</span>
        <b class="neg">− <?= money0($statement['out'], false) ?></b>
      </div>
      <div class="cs-stmt-cell res">
        <span>رصيد آخر المدة</span>
        <b><?= money0($statement['closing'], false) ?></b>
      </div>
    </div>
    <?php if ($statement['by_kind']): ?>
      <div class="cs-kindchips">
        <?php foreach ($statement['by_kind'] as $k => $v):
          $in = CompanySafe::isIn($k); ?>
          <span class="cs-kindchip">
            <?= icon(CompanySafe::icon($k), 13) ?>
            <span><?= e(CompanySafe::label($k)) ?></span>
            <b class="<?= $in ? 'pos' : 'neg' ?>"><?= money0($in ? $v['in'] : $v['out'], false) ?></b>
            <em><?= intf($v['count']) ?></em>
          </span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php },
]); ?>
