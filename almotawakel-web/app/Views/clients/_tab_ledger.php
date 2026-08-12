<?php
/**
 * تبويب «كشف الحساب» — سطر لكل حركة برصيد تراكمي.
 *
 * ⚠️ ده اللي بيجاوب على «الرصيد ده اتكوّن إزاي»: الفاتورة بتزوّد المطلوب،
 *    والتحصيل بيقلّله، والرصيد بعد كل سطر ظاهر جنبه. الشاشة القديمة كانت
 *    بتعرض المجموع النهائي وبس.
 * ⚠️ الملغي والمعكوس بيفضلوا في الكشف **بصفر** — اختفاؤهم بيخلّي المالك
 *    يدوّر على مستند شافه ومش لاقيه.
 *
 * @var array $agent @var array $ledger @var array $head
 */
$id    = (int) $agent['id'];
$money = static fn ($v) => money((float) $v, false);
$rows  = array_reverse($ledger['rows']);   // الأحدث فوق
?>

<div class="card">
  <div class="section-head">
    <?= icon('layers', 17) ?> كشف الحساب
    <span class="cl-mut"><?= intf(count($ledger['rows'])) ?> حركة</span>
    <span class="cl-spacer"></span>
    <a class="btn ghost sm" href="<?= url('clients/' . $id . '/statement') ?>" target="_blank" rel="noopener">
      <?= icon('file-text', 14) ?> نسخة A4
    </a>
  </div>

  <?php if (!$rows): ?>
    <?php partial('empty', ['emoji' => '🧾', 'title' => 'مفيش حركة على الحساب ده لسه']); ?>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data cl-ledger">
        <thead><tr>
          <th class="g-identity">التاريخ</th>
          <th class="g-identity">البيان</th>
          <th class="g-identity">المرجع</th>
          <th class="g-revenue">مدين (عليه)</th>
          <th class="g-revenue">دائن (دفع)</th>
          <th class="g-result">الرصيد</th>
        </tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr class="<?= $r['void'] ? 'cl-void' : '' ?>">
              <td class="nowrap"><?= $r['date'] ? e(dfmt($r['date'])) : '—' ?></td>
              <td class="txt">
                <?= icon($r['kind'] === 'payment' ? 'banknote' : 'receipt', 14) ?>
                <?= e($r['label']) ?>
                <?php if ($r['void']): ?><span class="pill st-unpaid"><?= $r['kind'] === 'payment' ? 'معكوس' : 'ملغي' ?></span><?php endif; ?>
                <?php if ($r['note'] !== ''): ?><span class="cl-mut"><?= e($r['note']) ?></span><?php endif; ?>
              </td>
              <td class="nowrap">
                <?php if ($r['url']): ?>
                  <a href="<?= url((string) $r['url']) ?>" target="_blank" rel="noopener"><?= e($r['ref']) ?></a>
                <?php else: ?><?= e($r['ref']) ?><?php endif; ?>
              </td>
              <td class="<?= $r['debit'] > 0.005 ? 'ex' : 'cl-mut' ?>"><?= $r['debit'] > 0.005 ? $money($r['debit']) : '—' ?></td>
              <td class="<?= $r['credit'] > 0.005 ? 'pos' : 'cl-mut' ?>"><?= $r['credit'] > 0.005 ? $money($r['credit']) : '—' ?></td>
              <td class="dbt-tot"><?= $money($r['balance']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot><tr>
          <td colspan="3" class="end">الإجمالي</td>
          <td class="ex"><?= $money($ledger['debit']) ?></td>
          <td class="pos"><?= $money($ledger['credit']) ?></td>
          <td class="dbt-tot"><?= $money($ledger['balance']) ?></td>
        </tr></tfoot>
      </table>
    </div>
  <?php endif; ?>
</div>
