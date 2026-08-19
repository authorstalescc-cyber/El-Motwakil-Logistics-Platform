<?php
/**
 * تبويب «السجل» — كل عمليات الجرد وفروقها.
 *
 * ⚠️ **الفرق هو المعلومة**، مش المبلغ المعدود. الترتيب والشارات كلها بتقول
 *    «طابق ولا لأ» الأول، والرقمين (دفتري/معدود) في سطر الميتا.
 *
 * ⚠️ **الحذف بيشيل الشهادة مش الفلوس** — الجرد ما قيّدش حاجة أصلاً، فحذفه
 *    بيشيل السجل بس (وبيتسجّل في `/audit` زي أي حذف).
 *
 * متغيّرات: $filters $rows $total $limit $stats
 */
use App\Models\SafeInventory;

$safeOpts = ['' => 'كل الخزائن'] + SafeInventory::labels();
$active   = ($filters['safe'] ?? '') !== '' || ($filters['from'] ?? '') !== ''
         || ($filters['to'] ?? '') !== '' || !empty($filters['only_diff']);
$cur      = App\Core\App::setting('currency', 'ج.م');
?>

<?php partial('stat_grid', ['items' => 4, 'class' => 'mb', 'body' => function () use ($stats, $cur) { ?>
  <?php partial('stat', [
    'label' => 'عمليات جرد', 'value' => intf($stats['count']),
    'icon'  => 'clipboard', 'tone' => 'navy',
    'drill' => url('safe-audit/drill/audits'), 'title' => 'آخر عمليات الجرد',
  ]); ?>
  <?php partial('stat', [
    'label' => 'طابقت بالظبط', 'value' => intf($stats['match']),
    'icon'  => 'check', 'tone' => 'green',
    'drill' => url('safe-audit/drill/audits'), 'title' => 'آخر عمليات الجرد',
  ]); ?>
  <?php partial('stat', [
    'label' => 'فيها عجز', 'value' => intf($stats['short']),
    'icon'  => 'trending-down', 'tone' => 'red',
    'drill' => url('safe-audit/drill/audits'), 'title' => 'آخر عمليات الجرد',
  ]); ?>
  <?php partial('stat', [
    'label' => 'أكبر فرق', 'value' => money0($stats['worst'], false), 'unit' => $cur,
    'icon'  => 'alert', 'tone' => $stats['worst'] > 0.005 ? 'red' : 'navy',
    'drill' => url('safe-audit/drill/audits'), 'title' => 'آخر عمليات الجرد',
  ]); ?>
<?php }]); ?>

<?php partial('filters', [
  'action' => url('safe-audit'),
  'active' => $active,
  'reset'  => url('safe-audit') . '?tab=history',
  'fields' => [
    ['type' => 'hidden', 'name' => 'tab',  'value' => 'history'],
    ['type' => 'select', 'name' => 'safe', 'value' => (string) $filters['safe'],
     'label' => 'الخزنة', 'options' => $safeOpts],
    ['type' => 'date', 'name' => 'from', 'value' => (string) $filters['from'], 'label' => 'من'],
    ['type' => 'date', 'name' => 'to',   'value' => (string) $filters['to'],   'label' => 'إلى'],
  ],
]); ?>

<?php partial('panel', [
  'title'  => 'سجل الجرد',
  'icon'   => 'clipboard',
  'badge'  => ['text' => intf($total) . ' عملية', 'tone' => 'navy'],
  'body'   => function () use ($rows) {
    if (!$rows) {
      partial('empty', [
        'icon'   => 'calculator',
        'title'  => 'مفيش جرد مسجَّل',
        'action' => ['label' => 'اعمل جرد', 'url' => url('safe-audit') . '?tab=count', 'class' => 'btn sm gold'],
      ]);
      return;
    }
    foreach ($rows as $r) {
      $diff = (float) $r['diff'];
      partial('row_item', [
        'title' => (string) $r['safe_label'],
        'icon'  => (string) $r['safe_icon'],
        'ref'   => dfmt((string) $r['audit_date']),
        'meta'  => array_values(array_filter([
          ['icon' => 'wallet', 'text' => 'دفتري ' . money((float) $r['book_balance'], false)],
          ['icon' => 'coins',  'text' => 'معدود ' . money((float) $r['counted'], false)],
          $r['note'] ? ['icon' => 'file-text', 'text' => (string) $r['note']] : null,
          $r['created_by'] ? ['icon' => 'user', 'text' => (string) $r['created_by']] : null,
        ])),
        'amount' => $r['state'] === 'match'
            ? 'مطابق'
            : (($diff > 0 ? '+ ' : '- ') . money(abs($diff), false)),
        'amount_tone' => $r['state'] === 'match' ? 'pos' : 'ex',
        'sub'    => $r['updated_at'] && $r['updated_by'] ? 'اتعدّل ' . dtfmt($r['updated_at']) : null,
        /* ⚠️ الحذف بيشيل **الشهادة** مش الفلوس — الجرد ما قيّدش حاجة أصلاً.
           والرسالة بتقول ده صراحةً عشان محدّش يفتكر إنه بيعكس حركة. */
        'extra'  => function () use ($r) { ?>
          <form method="post" action="<?= url('safe-audit/' . (int) $r['id'] . '/delete') ?>"
                class="confirm-delete" data-confirm="حذف شهادة الجرد دي؟ مفيش رصيد هيتغيّر.">
            <?= csrfField() ?>
            <button class="edit" type="submit" title="حذف"><?= icon('trash', 13) ?></button>
          </form>
        <?php },
      ]);
    }
  },
]); ?>

<?php partial('count_note', ['shown' => count($rows), 'total' => (int) $total, 'unit' => 'عملية']); ?>
