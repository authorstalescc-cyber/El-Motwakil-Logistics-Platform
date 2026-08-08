<?php
use App\Core\FormState;
/**
 * صفحة حساب مديونية يدوية واحدة: الأصل / المدفوع / المتبقّي + سجل الدفعات.
 * @var array $acc       صف debt_accounts
 * @var array $totals    ['charges','paid','remaining']
 * @var array $payments  دفعات هذا الحساب
 */
$paymentState = $paymentState ?? ['token'=>FormState::token(),'old'=>[],'errors'=>[],'back'=>'debts/account/'.(int)$acc['id']];
$payOld = $paymentState['old']; $payErrors = $paymentState['errors'];
?>
<div class="page-actions">
  <a class="btn ghost" href="<?= url('debts') ?>?tab=others">‹ رجوع للمديونيات الأخرى</a>
</div>

<div class="two-col">
  <div class="card pad">
    <div class="section-head"><?= icon('info', 17) ?> <?= e($acc['name']) ?></div>
    <table class="data"><tbody>
      <tr><td class="dba-k">أصل المديونية</td><td class="dbt-tot"><?= money($totals['charges']) ?></td></tr>
      <tr><td class="dba-k">المدفوع</td><td class="pos"><?= money($totals['paid']) ?></td></tr>
      <tr><td class="dba-k">المتبقّي</td><td class="dbt-tot <?= $totals['remaining']>0.005?'neg':'' ?>"><?= money($totals['remaining']) ?></td></tr>
      <?php if (!empty($acc['note'])): ?>
      <tr><td class="dba-k">ملاحظة</td><td><?= e($acc['note']) ?></td></tr>
      <?php endif; ?>
    </tbody></table>

    <div class="section-head dba-sec">تسجيل دفعة</div>
    <?= formErrorSummary($payErrors) ?>
    <form class="inline-form js-dirty-form" method="post" action="<?= url('debts/account/'.$acc['id'].'/pay') ?>">
      <?= csrfField() ?>
      <?= formStateFields($paymentState['token'], $paymentState['back']) ?>
      <div class="field flush"><input type="number" step="0.01" min="0.01" name="amount" value="<?= e($payOld['amount'] ?? '') ?>" placeholder="المبلغ" required class="dbt-amt"<?= fieldErrorAttrs('amount', $payErrors) ?>><?= fieldError('amount', $payErrors) ?></div>
      <input type="date" name="pay_date" value="<?= e($payOld['pay_date'] ?? work_date()) ?>">
      <input type="text" name="note" value="<?= e($payOld['note'] ?? '') ?>" placeholder="ملاحظة (اختياري)">
      <label class="dbt-visa-lbl">
        <input type="checkbox" name="is_visa" value="1" <?= !empty($payOld['is_visa'])?'checked':'' ?> class="dbt-cbx"><?= icon('credit-card', 14) ?> فيزا</label>
      <?= visa_card_select((int)($payOld['visa_card_id'] ?? 0), 'visa_card_id', 'narrow') ?>
      <button class="btn gold sm" type="submit" data-primary-submit><?= icon('plus', 14) ?> دفع</button>
    </form>
  </div>

  <div class="card pad">
    <div class="section-head">سجل الدفعات (<?= count($payments) ?>)</div>
    <div class="table-wrap"><table class="data">
      <thead><tr><th class="g-identity">التاريخ</th><th class="g-revenue">المبلغ</th><th class="g-identity">ملاحظة</th><th class="g-identity">حذف</th></tr></thead>
      <tbody>
        <?php $t=0; foreach ($payments as $p): $t+=$p['amount']; ?>
          <tr>
            <td><?= dfmt($p['pay_date']) ?></td>
            <td class="pos"><?= money($p['amount'],false) ?><?= !empty($p['is_visa'])?' <span class="visa-badge">فيزا</span>':'' ?></td>
            <td><?= e($p['note']) ?></td>
            <td>
              <form class="confirm-delete" method="post" action="<?= url('debts/payment/'.$p['id'].'/delete') ?>">
                <?= csrfField() ?><?= formStateFields(FormState::token(), 'debts/account/'.(int)$acc['id']) ?>
                <button class="del" type="submit" title="حذف"><?= icon('trash', 14) ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$payments): ?><tr><td colspan="4"><div class="empty">لا دفعات بعد</div></td></tr><?php endif; ?>
      </tbody>
      <?php if($payments): ?><tfoot><tr><td>الإجمالي</td><td><?= money($t,false) ?></td><td colspan="2"></td></tr></tfoot><?php endif; ?>
    </table></div>
  </div>
</div>
