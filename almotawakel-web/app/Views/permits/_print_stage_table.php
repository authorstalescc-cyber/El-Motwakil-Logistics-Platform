<?php
/**
 * closure مشترَك يرندر جدول بنود مرحلة مالية (إيصالات/مصاريف/رسوم) لصفحات الطباعة.
 * الاستخدام: $stageTable = require __DIR__.'/_print_stage_table.php';
 *   $stageTable(array $entries, bool $showActual = true, bool $showDir = false): [html, sumAmount, sumActual]
 */
return function(array $entries, bool $showActual = true, bool $showDir = false) {
    $real = array_values(array_filter($entries, fn($e) => ($e['status'] ?? '') !== 'none'));
    $sumA = 0.0; $sumC = 0.0;
    ob_start(); ?>
    <table class="grid">
      <thead><tr>
        <th style="width:30px">م</th>
        <th style="width:88px">التاريخ</th>
        <th class="r">البيان</th>
        <?php if ($showDir): ?><th style="width:64px">النوع</th><?php endif; ?>
        <th style="width:70px">الكمية</th>
        <th style="width:110px">المبلغ</th>
        <?php if ($showActual): ?><th style="width:110px">السعر الفعلي</th><?php endif; ?>
        <th style="width:90px">مرجع</th>
      </tr></thead>
      <tbody>
      <?php $i=0; foreach ($real as $en): $i++;
        $qty = max(1, (int)($en['qty'] ?? 1));
        $dir = $en['direction'] ?? '';
        $amtCls = $dir === 'revenue' ? 'pos' : ($dir === 'expense' ? 'neg' : '');
        $sumA += (float)$en['amount'];
        $hasAct = isset($en['actual_amount']) && $en['actual_amount'] !== null && $en['actual_amount'] !== '';
        if ($hasAct) { $sumC += (float)$en['actual_amount']; }
      ?>
        <tr>
          <td class="mut"><?= $i ?></td>
          <td class="num"><?= dfmt($en['entry_date']) ?></td>
          <td class="r"><?= e($en['note'] ?: '—') ?><?php if(!empty($en['is_visa'])): ?> <span class="chip visa"><?= icon('credit-card', 14) ?> فيزا</span><?php endif; ?></td>
          <?php if ($showDir): ?><td><?= $dir==='revenue' ? '<span class="chip pos-t">محصّل</span>' : ($dir==='expense' ? '<span class="chip neg-t">مصروف</span>' : '—') ?></td><?php endif; ?>
          <td class="num"><?= $qty > 1 ? intf($qty) : '1' ?></td>
          <td class="num <?= $amtCls ?>"><?= money($en['amount'], false) ?></td>
          <?php if ($showActual): ?><td class="num act"><?= $hasAct ? money($en['actual_amount'], false) : '—' ?></td><?php endif; ?>
          <td class="num mut"><?= e($en['ref_no'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      <tr class="sumrow">
        <td colspan="<?= $showDir ? 5 : 4 ?>">الإجمالي</td>
        <td class="num"><?= money($sumA, false) ?></td>
        <?php if ($showActual): ?><td class="num act"><?= $sumC > 0 ? money($sumC, false) : '—' ?></td><?php endif; ?>
        <td></td>
      </tr>
      </tbody>
    </table>
    <?php
    return [ob_get_clean(), $sumA, $sumC];
};
