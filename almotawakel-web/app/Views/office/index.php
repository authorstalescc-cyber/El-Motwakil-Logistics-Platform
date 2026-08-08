<?php
/**
 * كروت الفيزا — رصيد المكتب (اتحوّلت للغة النظام في المرحلة ١٤).
 *
 * ⚠️ **ممنوع رقم أصمّ**: التلات أرقام فوق بيفتحوا بنودهم من
 *    `GET /office/drill/{what}` — بيتجابوا عند الضغط بس.
 *
 * ⚠️ **العزل المالي زي ما هو (v22)**: الكارت حساب مستقل — المدفوع بالفيزا
 *    ما بيتخصمش من خزنة المكتب ولا من التقفيل اليومي. الشاشة عرض بحت.
 */
$od = static fn (string $what): string => url('office/drill/' . $what);
?>
<div class="toolbar">
  <div class="section-head" style="margin:0"><?= icon('credit-card', 18) ?> رصيد المكتب — كروت الفيزا</div>
  <div class="spacer"></div>
  <a class="btn gold" href="<?= url('office/new') ?>?back=<?= urlencode(current_app_path() ?: 'office') ?>"><?= icon('plus', 15) ?> إضافة كارت</a>
</div>

<?php partial('stat_grid', ['items' => 3, 'class' => 'mb', 'body' => function () use ($summary, $od) { ?>
  <?php partial('stat', [
    'label' => 'إجمالي الأرصدة المتاحة', 'value' => intf($summary['balance']),
    'unit'  => intf($summary['count']) . ' كارت',
    'icon'  => 'credit-card', 'tone' => 'gold', 'drill' => $od('balance'), 'title' => 'رصيد كل كارت',
  ]); ?>
  <?php partial('stat', [
    'label' => 'إجمالي الشحنات', 'value' => intf($summary['deposits'] + $summary['opening']),
    'icon'  => 'wallet', 'tone' => 'green',
    'drill' => $od('deposits'), 'title' => 'الشحنات على الكروت',
  ]); ?>
  <?php partial('stat', [
    'label' => 'إجمالي المدفوع بالفيزا', 'value' => intf($summary['payments']),
    'icon'  => 'trending-down', 'tone' => 'red',
    'drill' => $od('payments'), 'title' => 'المدفوع من الكروت',
  ]); ?>
<?php }]); ?>

<?php /* الجدول (٧ أعمدة) اتشال: كل صف هنا **كارت** — كيان له اسم وصاحب ورصيد،
         مش سطر مقارنة رقمية. الكارت بيتقرا بنظرة وبيشتغل على الموبايل. */ ?>
<?php partial('panel', [
  'title'  => 'الكروت',
  'icon'   => 'layers',
  'badge'  => $cards ? ['text' => intf(count($cards)), 'tone' => 'navy'] : null,
  'action' => $cards ? ['label' => 'كل الأرصدة', 'drill' => $od('balance'), 'title' => 'رصيد كل كارت'] : null,
  'class'  => 'mb',
  'body'   => function () use ($cards) {
    if (!$cards) {
      partial('empty', [
        'emoji' => '💳', 'title' => 'مفيش كروت فيزا',
        'action' => ['label' => 'إضافة كارت', 'url' => url('office/new')],
      ]);
      return;
    }
    echo '<div class="rp-cards flat">';
    foreach ($cards as $c) {
      $tags = [
        ['icon' => 'wallet', 'text' => 'شحن ' . money($c['dep_total'] + $c['opening_balance'], false)],
        ['icon' => 'trending-down', 'text' => 'مدفوع ' . money($c['pay_total'], false), 'tone' => 'ex'],
      ];
      if ($c['bank']) { $tags[] = ['icon' => 'home', 'text' => $c['bank']]; }
      if ($c['status'] === 'inactive') { $tags[] = ['icon' => 'clock', 'text' => 'متوقف', 'tone' => 'low']; }
      partial('entity_card', [
        'name'  => $c['name'],
        'url'   => url('office/' . (int) $c['id']),
        'value' => money($c['balance'], false),
        'value_label' => 'الرصيد المتاح',
        'value_tone'  => $c['balance'] < 0 ? 'neg' : 'pos',
        'alert'  => $c['balance'] < 0,
        'tags'   => $tags,
      ]);
    }
    echo '</div>';
  },
]); ?>

<?php if ($byOwner): ?>
  <?php partial('panel', [
    'title'  => 'الأرصدة حسب صاحب الكارت',
    'icon'   => 'users',
    'badge'  => ['text' => intf(count($byOwner)), 'tone' => 'navy'],
    'action' => ['label' => 'التفاصيل', 'drill' => $od('owners'), 'title' => 'الأرصدة حسب صاحب الكارت'],
    'scroll' => true,
    'body'   => function () use ($byOwner) {
      foreach ($byOwner as $o) {
        partial('row_item', [
          'title'  => $o['owner'],
          'icon'   => 'user',
          'meta'   => [['icon' => 'credit-card', 'text' => intf($o['cards']) . ' كارت']],
          'amount' => money($o['balance'], false),
          'amount_tone' => $o['balance'] < 0 ? 'neg' : 'pos',
        ]);
      }
    },
  ]); ?>
<?php endif; ?>

