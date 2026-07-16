<?php
/**
 * صفحة حساب مديونية يدوية واحدة: الأصل / المدفوع / المتبقّي + سجل الدفعات.
 * @var array $acc       صف debt_accounts
 * @var array $totals    ['charges','paid','remaining']
 * @var array $payments  دفعات هذا الحساب
 */
?>
<style>.two-col .section-head{justify-content:flex-start}</style>
<div class="page-actions">
  <a class="btn ghost" href="<?= url('debts') ?>?tab=others">‹ رجوع للمديونيات الأخرى</a>
</div>

<div class="two-col">
  <div class="card" style="padding:20px">
    <div class="section-head">📌 <?= e($acc['name']) ?></div>
    <table class="data"><tbody>
      <tr><td style="background:var(--bg);font-weight:700">أصل المديونية</td><td style="font-weight:800"><?= money($totals['charges']) ?></td></tr>
      <tr><td style="background:var(--bg);font-weight:700">المدفوع</td><td class="pos"><?= money($totals['paid']) ?></td></tr>
      <tr><td style="background:var(--bg);font-weight:700">المتبقّي</td><td class="<?= $totals['remaining']>0.005?'neg':'' ?>" style="font-weight:800"><?= money($totals['remaining']) ?></td></tr>
      <?php if (!empty($acc['note'])): ?>
      <tr><td style="background:var(--bg);font-weight:700">ملاحظة</td><td><?= e($acc['note']) ?></td></tr>
      <?php endif; ?>
    </tbody></table>

    <div class="section-head" style="margin-top:22px">تسجيل دفعة</div>
    <form class="inline-form" method="post" action="<?= url('debts/account/'.$acc['id'].'/pay') ?>">
      <?= csrfField() ?>
      <input type="hidden" name="back" value="debts/account/<?= (int)$acc['id'] ?>">
      <input type="number" step="0.01" name="amount" placeholder="المبلغ" required style="max-width:130px">
      <input type="date" name="pay_date" value="<?= e(work_date()) ?>">
      <input type="text" name="note" placeholder="ملاحظة (اختياري)">
      <label style="display:flex;align-items:center;gap:6px;font-weight:700;font-size:.85rem;white-space:nowrap">
        <input type="checkbox" name="is_visa" value="1" style="width:16px;height:16px;accent-color:var(--gold)">💳 فيزا</label>
      <?= visa_card_select(0, 'visa_card_id', 'max-width:170px') ?>
      <button class="btn gold sm" type="submit">➕ دفع</button>
    </form>
  </div>

  <div class="card" style="padding:20px">
    <div class="section-head">سجل الدفعات (<?= count($payments) ?>)</div>
    <div class="table-wrap"><table class="data">
      <thead><tr><th class="g-identity">التاريخ</th><th class="g-revenue">المبلغ</th><th class="g-identity">ملاحظة</th><th class="g-identity">حذف</th></tr></thead>
      <tbody>
        <?php $t=0; foreach ($payments as $p): $t+=$p['amount']; ?>
          <tr>
            <td><?= dfmt($p['pay_date']) ?></td>
            <td class="pos"><?= money($p['amount'],false) ?><?= !empty($p['is_visa'])?' <span style="background:#fdf0e3;color:#8a5a12;border-radius:999px;padding:1px 8px;font-size:.72rem;font-weight:700">فيزا</span>':'' ?></td>
            <td><?= e($p['note']) ?></td>
            <td>
              <form class="confirm-delete" method="post" action="<?= url('debts/payment/'.$p['id'].'/delete') ?>" style="margin:0">
                <?= csrfField() ?><input type="hidden" name="back" value="debts/account/<?= (int)$acc['id'] ?>">
                <button class="del" type="submit" title="حذف">🗑️</button>
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
