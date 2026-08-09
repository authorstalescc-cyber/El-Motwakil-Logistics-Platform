<?php
/**
 * تبويب «التقارير» — الشهر بالأرقام + كشف حساب جاهز للطباعة.
 *
 * ⚠️ الأرقام هنا من نفس دوال باقي التبويبات (`monthTotals` · `breakdown` ·
 *    `statement`) — فمستحيل التقرير يقول رقمًا يخالف كارت النظرة العامة.
 *
 * متغيّرات: $monthTotals $breakdown $trend $statement $balance $cd $mStart $mEnd $labels
 */
use App\Models\CompanySafe;

$monthNet = round($monthTotals['in'] - $monthTotals['out'], 2);
?>

<?php /* ═══ ① الشهر في ٤ أرقام ═══ */ ?>
<?php partial('stat_grid', ['items' => 4, 'class' => 'mb', 'body' => function () use ($monthTotals, $monthNet, $balance, $cd) { ?>
  <?php partial('stat', [
    'label' => 'داخل الشهر', 'value' => intf($monthTotals['in']),
    'icon'  => 'wallet', 'tone' => 'green', 'drill' => $cd('in'), 'title' => 'الكاش الداخل',
  ]); ?>
  <?php partial('stat', [
    'label' => 'خارج الشهر', 'value' => intf($monthTotals['out']),
    'icon'  => 'trending-down', 'tone' => 'red', 'drill' => $cd('out'), 'title' => 'الكاش الخارج',
  ]); ?>
  <?php partial('stat', [
    'label' => 'صافي الشهر', 'value' => intf($monthNet),
    'icon'  => $monthNet < 0 ? 'trending-down' : 'trending-up',
    'tone'  => $monthNet < 0 ? 'red' : 'green',
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
    'title' => 'الرصيد راح منين لفين',
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
  'title'  => 'كشف حساب ' . dfmt($mStart) . ' — ' . dfmt($mEnd),
  'icon'   => 'file-text',
  'action' => ['label' => 'اطبع', 'url' => url('company-safe/statement')
                                         . '?from=' . $mStart . '&to=' . $mEnd],
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
