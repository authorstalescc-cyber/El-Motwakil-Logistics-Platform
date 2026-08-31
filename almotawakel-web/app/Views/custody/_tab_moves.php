<?php
/**
 * تبويب «الحركات» — سجل الفترة الكامل بكل مصادره.
 *
 * ⚠️⚠️ **الصفوف والرصيد الجاري من `Custody::movements()`** المبنية على
 *    `DailyReview::officeCashItems` + `Closing`. السجل القديم كان بيجمع
 *    الإضافات ناقص عهد السائقين وبس (بلا مصروفات وبلا رصيد آخر إقفال) فكان
 *    بيقول رقمًا يخالف الكارت اللي فوقه بمئات الألوف.
 *
 * ⚠️ **آخر سطر لازم يساوي رصيد آخر المدة** — محروسة في `verify_custody_ui`.
 *
 * متغيّرات: $move $from $to $day $retPath $cd
 */
?>
<?php include __DIR__ . '/_period.php'; ?>

<?php /* تحذيرات وظيفية — ≤٦ كلمات، ولا واحدة منهم شرح تعليمي. */ ?>
<?php if (abs($move['drift']) >= 0.005): ?>
  <div class="cs-alert bad">
    <?= icon('alert', 15) ?> لقطة إقفال متقادمة — فرق <?= money($move['drift'], false) ?>.
    <span class="spacer"></span>
    <a class="btn ghost sm" href="<?= url('closing') ?>">راجع التقفيل</a>
  </div>
<?php endif; ?>
<?php if ($move['capped']): ?>
  <div class="cs-alert">
    <?= icon('info', 15) ?> الفترة أطول من <?= intf(\App\Models\SafeInventory::MAX_ROW_DAYS) ?> يوم — الإجماليات كاملة والصفوف مخفية.
  </div>
<?php endif; ?>
<?php /* ⚠️ **ممنوع قطع صامت** — والرقم بيتقال بالظبط لا بكلمة «بعض». */ ?>
<?php if ($move['truncated']): ?>
  <div class="cs-alert">
    <?= icon('info', 15) ?> آخر <?= intf(count($move['rows'])) ?> حركة من <?= intf($move['rows_total']) ?> — قصّر الفترة تشوفها كلها.
  </div>
<?php endif; ?>

<?php /* ═══ إجماليات الفترة — كل رقم بيفتح بنوده ═══ */ ?>
<?php partial('stat_grid', ['items' => 4, 'class' => 'mb', 'body' => function () use ($move, $cd) { ?>
  <?php partial('stat', [
    'label' => 'رصيد أول المدة', 'value' => intf($move['opening']),
    'unit'  => dfmt($move['from']),
    'icon'  => 'lock', 'tone' => 'navy', 'drill' => $cd('balance'), 'title' => 'من إيه اتكوّن الرصيد',
  ]); ?>
  <?php partial('stat', [
    'label' => 'داخل', 'value' => intf($move['in']),
    'icon'  => 'wallet', 'tone' => 'green', 'drill' => $cd('topups'), 'title' => 'إضافات العهدة',
  ]); ?>
  <?php partial('stat', [
    'label' => 'خارج', 'value' => intf($move['out']),
    'unit'  => 'مصروفات ' . intf($move['parts']['expenses']) . ' · عهد ' . intf($move['parts']['driver']),
    'icon'  => 'trending-down', 'tone' => 'red', 'drill' => $cd('expenses'), 'title' => 'المصروفات',
  ]); ?>
  <?php partial('stat', [
    'label' => 'رصيد آخر المدة', 'value' => intf($move['closing']),
    'unit'  => dfmt($move['to']),
    'icon'  => 'briefcase', 'tone' => $move['closing'] < 0 ? 'red' : 'gold',
    'drill' => $cd('balance'), 'title' => 'من إيه اتكوّن الرصيد',
  ]); ?>
<?php }]); ?>

<?php partial('panel', [
  'title'  => 'حركة الخزنة',
  'icon'   => 'layers',
  'badge'  => $move['rows_total']
    ? ['text' => $move['truncated']
        ? intf(count($move['rows'])) . ' من ' . intf($move['rows_total'])
        : intf($move['rows_total']), 'tone' => 'navy']
    : null,
  'scroll' => true,
  'body'   => function () use ($move, $retPath) {
    if (!$move['rows']) {
      partial('empty', ['emoji' => '📭', 'title' => $move['capped']
        ? 'اختار فترة أقصر عشان تشوف الصفوف'
        : 'مفيش حركة في الفترة دي']);
      return;
    }
    /* سطر افتتاحي — من غيره الرصيد الجاري في أول صف بيبان كأنه طالع من العدم.
       ⚠️ لما الصفوف تتقص بيبقى المعروض **آخر** الصفوف، فسطر البداية بيقول
          الرصيد قبل أول صف معروض لا رصيد أول المدة — وإلا الحساب ما يقفلش. */
    partial('row_item', [
      'title'  => $move['truncated']
        ? 'الرصيد قبل أول صف معروض'
        : 'رصيد أول المدة (' . dfmt($move['from']) . ')',
      'icon'   => 'lock',
      'meta'   => [['icon' => 'info', 'text' => $move['truncated']
        ? 'رصيد أول المدة ' . money($move['opening'], false)
        : 'نقطة البداية']],
      'amount' => money($move['lead'], false),
    ]);
    $day = null;
    foreach ($move['rows'] as $r) {
      if ($r['date'] !== $day) {
        $day = $r['date'];
        echo '<div class="cs-day">' . icon('calendar', 15) . ' ' . e(dfmt($day)) . '</div>';
      }
      $meta = [];
      if ($r['ref'])    { $meta[] = ['icon' => 'file-text', 'text' => $r['ref']]; }
      if ($r['party'])  { $meta[] = ['icon' => 'user', 'text' => $r['party']]; }
      if ($r['by_rep']) { $meta[] = ['icon' => 'briefcase', 'text' => 'دفعها ' . $r['by_rep']]; }
      if ($r['note'])   { $meta[] = ['icon' => 'info', 'text' => $r['note']]; }
      $meta[] = ['icon' => 'briefcase', 'text' => 'الرصيد الجاري ' . money($r['running'], false)];
      partial('row_item', [
        'title'  => $r['title'],
        'icon'   => $r['icon'],
        'url'    => $r['link'] ? url($r['link']) : null,
        'meta'   => $meta,
        'amount' => ($r['signed'] >= 0 ? '+ ' : '- ') . money(abs($r['signed']), false),
        'amount_tone' => $r['signed'] >= 0 ? 'pos' : 'ex',
        /* الإضافة اليدوية بس هي اللي بتتعدّل من هنا — الباقي بيتعدّل من مصدره
           (البوليصة · الإذن · المصروف)، والرابط فوق بيوصّل له. */
        'extra'  => $r['type'] === 'topup' ? function () use ($r, $retPath) { ?>
          <a class="edit" href="<?= url('custody/topup/' . $r['id'] . '/edit') ?>?back=<?= urlencode($retPath) ?>"
             title="تعديل"><?= icon('edit', 13) ?></a>
          <form method="post" action="<?= url('custody/topup/' . $r['id'] . '/delete') ?>"
                class="confirm-delete" data-confirm="حذف هذه الإضافة؟">
            <?= csrfField() ?>
            <?= formStateFields(\App\Core\FormState::token(), $retPath) ?>
            <button class="edit" type="submit" title="حذف"><?= icon('trash', 13) ?></button>
          </form>
        <?php } : '',
      ]);
    }
    partial('row_item', [
      'title'  => 'رصيد آخر المدة (' . dfmt($move['to']) . ')',
      'icon'   => 'briefcase',
      'meta'   => [['icon' => 'info', 'text' => 'بعد كل حركة الفترة']],
      'amount' => money($move['closing'], false),
      'amount_tone' => $move['closing'] < 0 ? 'neg' : 'pos',
    ]);
  },
]); ?>
