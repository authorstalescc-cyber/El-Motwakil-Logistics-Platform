<?php
/**
 * تبويب «كشف الحساب» — سجل المندوب بطرفين برصيد جارٍ.
 *
 * ⚠️ الرصيد الجاري بيتحسب في `Rep::ledger` مش هنا — الشاشة بتعرض بس.
 * ⚠️ البند اللي النظام ولّده من اعتماد الراتب **مالوش زرار حذف**: شيله
 *    بيفضّي لقطة الفترة، والطريق الصح إلغاء الاعتماد من `/payroll`.
 *
 * @var array $ledger @var array $summary @var int $rid
 */
use App\Models\Rep;
?>
<?php partial('stat_grid', ['items' => 4, 'body' => function () use ($summary, $dr) { ?>
  <?php partial('stat', ['label' => 'مستحقات له', 'value' => money0($summary['due']),
    'icon' => 'trending-up', 'tone' => 'green', 'unit' => 'ج', 'drill' => $dr('balance'),
    'title' => 'كل بنود الكشف']); ?>
  <?php partial('stat', ['label' => 'خصومات وجزاءات', 'value' => money0($summary['deductions']),
    'icon' => 'trending-down', 'tone' => 'red', 'unit' => 'ج', 'drill' => $dr('balance'),
    'title' => 'كل بنود الكشف']); ?>
  <?php partial('stat', ['label' => 'سلف مصروفة', 'value' => money0($summary['advances']),
    'icon' => 'wallet', 'tone' => 'gold', 'unit' => 'ج', 'drill' => $dr('advances'),
    'title' => 'سلف الفترة']); ?>
  <?php partial('stat', ['label' => 'تسويات مدفوعة', 'value' => money0($summary['paid']),
    'icon' => 'receipt', 'tone' => 'navy', 'unit' => 'ج', 'drill' => $dr('balance'),
    'title' => 'كل بنود الكشف']); ?>
<?php }]); ?>

<div class="card mb">
  <div class="section-head rts-head"><?= icon('layers', 17) ?> كشف حساب المندوب</div>
  <?php partial('table', [
    'cols'  => [['label' => 'التاريخ'], ['label' => 'النوع'], ['label' => 'البيان'],
                ['label' => 'له (+)'], ['label' => 'عليه (−)'], ['label' => 'الرصيد'], ['label' => '']],
    'count' => count($ledger),
    'empty' => ['title' => 'مفيش بنود في سجل المندوب'],
    'rows'  => function () use ($ledger) {
      foreach ($ledger as $r):
        $isCredit = in_array($r['kind'], Rep::CREDIT_KINDS, true);
        $fromPayroll = !empty($r['payroll_period_id']);
      ?>
        <tr>
          <td><?= $r['d'] ? e(dfmt((string) $r['d'])) : '—' ?></td>
          <td>
            <span class="chip"><?= e((string) $r['label']) ?></span>
            <?php if (!empty($r['is_visa'])): ?><span class="chip"><?= icon('credit-card', 12) ?> فيزا</span><?php endif; ?>
          </td>
          <td class="txt"><?= e((string) ($r['note'] ?: '—')) ?>
            <?php if ($r['settlement_id']): ?><span class="chip"><?= icon('lock', 12) ?> مقفول</span><?php endif; ?>
          </td>
          <td class="pos"><?= $isCredit ? money((float) $r['amount'], false) : '—' ?></td>
          <td class="neg"><?= !$isCredit ? money((float) $r['amount'], false) : '—' ?></td>
          <td class="mv-ref<?= $r['running'] < 0 ? ' neg' : '' ?>"><?= money((float) $r['running'], false) ?></td>
          <td class="nowrap">
            <?php if (!$r['settlement_id'] && !$fromPayroll): ?>
              <form method="post" action="<?= url('reps/entry/' . (int) $r['id'] . '/delete') ?>"
                    class="confirm-delete" data-confirm="حذف البند ده؟">
                <?= csrfField() ?>
                <button class="edit oc-btn-bare" type="submit" title="حذف"><?= icon('trash', 14) ?></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach;
    },
  ]); ?>
</div>
