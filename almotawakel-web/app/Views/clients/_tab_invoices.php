<?php
/**
 * تبويب «الفواتير» — كل مستندات العميل: الإجمالي والمدفوع والباقي وحالة كل واحد.
 *
 * ⚠️ الحالة والباقي **من `permit_invoices` نفسها** (بيتحدّثوا في
 *    `PermitInvoice::refreshAllStatuses`) — مش محسوبين هنا من جديد، فمستحيل
 *    الكارت فوق يقول رقم والجدول ده يقول غيره.
 *
 * @var array $agent @var array $invoices @var array $head
 */
use App\Models\PermitInvoice;

$id    = (int) $agent['id'];
$money = static fn ($v) => money((float) $v, false);
$today = work_date();

$sumTotal = 0.0; $sumPaid = 0.0; $sumDue = 0.0;
foreach ($invoices as $iv) {
    if (($iv['status'] ?? '') === 'void') { continue; }
    $sumTotal += (float) $iv['total'];
    $sumPaid  += (float) $iv['paid_amount'];
    $sumDue   += (float) $iv['balance_due'];
}
?>

<div class="card">
  <div class="section-head">
    <?= icon('receipt', 17) ?> مستندات العميل
    <span class="cl-mut"><?= intf(count($invoices)) ?></span>
    <span class="cl-spacer"></span>
    <a class="btn ghost sm" href="<?= url('invoices') ?>?q=<?= e(rawurlencode((string) $agent['name'])) ?>">
      <?= icon('external', 14) ?> في سجل الفواتير
    </a>
  </div>

  <?php if (!$invoices): ?>
    <?php partial('empty', ['emoji' => '🧾', 'title' => 'مفيش مستندات صادرة للعميل ده']); ?>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr>
          <th class="g-identity">المستند</th>
          <th class="g-identity">النوع</th>
          <th class="g-identity">التاريخ</th>
          <th class="g-identity">الاستحقاق</th>
          <th class="g-identity">الإذن</th>
          <th class="g-revenue">الإجمالي</th>
          <th class="g-revenue">المدفوع</th>
          <th class="g-result">الباقي</th>
          <th class="g-identity">الحالة</th>
          <th class="g-identity"></th>
        </tr></thead>
        <tbody>
          <?php foreach ($invoices as $iv):
            $void = ($iv['status'] ?? '') === 'void';
            $late = !$void && $iv['due_date'] && $iv['due_date'] < $today && (float) $iv['balance_due'] > 0.005;
          ?>
            <tr class="<?= $void ? 'cl-void' : ($late ? 'cl-late' : '') ?>">
              <td class="dbt-tot nowrap"><?= e($iv['doc_no'] ?: '#' . $iv['id']) ?></td>
              <td class="nowrap"><span class="pill <?= $iv['doc_kind'] === 'statement' ? 'st-partial' : 'st-paid' ?>">
                <?= e(PermitInvoice::kindLabel((string) $iv['doc_kind'])) ?><?= !empty($iv['taxable']) ? ' • ضريبية' : '' ?>
              </span></td>
              <td class="nowrap"><?= e(dfmt($iv['invoice_date'])) ?></td>
              <td class="nowrap"><?= $iv['due_date'] ? e(dfmt($iv['due_date'])) : '—' ?></td>
              <td class="nowrap">
                <?php if (!empty($iv['permit_id'])): ?>
                  <a href="<?= url('permits/' . (int) $iv['permit_id']) ?>"><?= e($iv['permit_no'] ?: '#' . (int) $iv['permit_id']) ?></a>
                <?php else: ?><?= e($iv['permit_no'] ?: '—') ?><?php endif; ?>
              </td>
              <td class="dbt-tot"><?= $money($iv['total']) ?></td>
              <td class="pos"><?= $money($iv['paid_amount']) ?></td>
              <td class="<?= (float) $iv['balance_due'] > 0.005 ? 'ex dbt-tot' : 'cl-mut' ?>"><?= $money($iv['balance_due']) ?></td>
              <td class="nowrap"><span class="pill <?= $void ? 'st-unpaid' : ($late ? 'st-unpaid' : (($iv['status'] ?? '') === 'paid' ? 'st-paid' : 'st-partial')) ?>">
                <?= e(PermitInvoice::statusLabel((string) $iv['status'], $iv['due_date'] ?? null)) ?>
              </span></td>
              <td class="nowrap">
                <a class="edit" href="<?= url('permits/invoice/' . (int) $iv['id']) ?>" target="_blank" rel="noopener" title="فتح المستند"><?= icon('file-text', 14) ?></a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot><tr>
          <td colspan="5" class="end">الإجمالي (بلا الملغي)</td>
          <td class="dbt-tot"><?= $money($sumTotal) ?></td>
          <td class="pos"><?= $money($sumPaid) ?></td>
          <td class="ex dbt-tot"><?= $money($sumDue) ?></td>
          <td colspan="2"></td>
        </tr></tfoot>
      </table>
    </div>
  <?php endif; ?>
</div>
