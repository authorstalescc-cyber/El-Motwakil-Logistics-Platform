<?php use App\Models\Bill; use App\Core\FormState;
$paymentState = $paymentState ?? ['token'=>FormState::token(),'old'=>[],'errors'=>[],'back'=>'settlements/'.(int)$bill['id']];
$payOld = $paymentState['old']; $payErrors = $paymentState['errors']; ?>
<?php partial('owner_tabs', ['active' => 'settlements']); ?>
<div class="page-actions">
  <a class="btn ghost" href="<?= url('settlements') ?>">‹ رجوع لقائمة السداد</a>
  <a class="btn ghost" href="<?= url('bawalis/'.$bill['id'].'/edit') ?>"><?= icon('edit', 14) ?> تعديل البوليصة</a>
</div>

<?php if ((int)($bill['is_legacy'] ?? 0) === 1): ?>
<div class="card" style="margin-bottom:14px;padding:13px 16px;border-inline-start:5px solid #c9a227;background:#fff9e8;color:#6d5510">
  <b>بوليصة قديمة:</b> متابعتها وسدادها هنا لا ينشئ إذنًا أو حركة، وقيمها التاريخية لا تدخل العهدة أو التقفيل.
</div>
<?php endif; ?>

<div class="two-col">
  <div class="card" style="padding:20px">
    <div class="section-head">بيانات البوليصة</div>
    <table class="data">
      <tbody>
        <tr><td style="background:var(--bg);font-weight:500">النوع</td><td><span class="pill pill-<?= $bill['bill_type'] ?>"><?= Bill::TYPE_LABELS[$bill['bill_type']]??'' ?></span></td></tr>
        <tr><td style="background:var(--bg);font-weight:500">رقم البوليصة</td><td><?= e($bill['bill_number']) ?></td></tr>
        <tr><td style="background:var(--bg);font-weight:500">العميل</td><td><?= e($bill['company'] ?: $bill['declaration_owner']) ?></td></tr>
        <tr><td style="background:var(--bg);font-weight:500">التاريخ</td><td><?= dfmt($bill['bill_date']) ?></td></tr>
        <tr><td style="background:var(--bg);font-weight:500">دين البوليصة</td><td style="font-weight:600"><?= money($bill['debt']) ?></td></tr>
        <tr><td style="background:var(--bg);font-weight:500">المدفوع</td><td class="pos"><?= money($bill['paid']) ?></td></tr>
        <tr><td style="background:var(--bg);font-weight:500">المتبقي</td><td class="<?= $bill['remaining']>0?'neg':'' ?>"><?= money($bill['remaining']) ?></td></tr>
        <tr><td style="background:var(--bg);font-weight:500">الحالة</td><td><span class="pill st-<?= $bill['pay_status'] ?>"><?= Bill::STATUS_LABELS[$bill['pay_status']] ?></span></td></tr>
      </tbody>
    </table>

    <?php if ($bill['remaining'] > 0): ?>
    <div class="section-head" style="margin-top:22px">تسجيل دفعة</div>
    <?= formErrorSummary($payErrors) ?>
    <form class="inline-form js-dirty-form" method="post" action="<?= url('settlements/'.$bill['id'].'/pay') ?>">
      <?= csrfField() ?>
      <?= formStateFields($paymentState['token'], $paymentState['back']) ?>
      <div class="field" style="margin:0"><input type="number" step="0.01" min="0.01" name="amount" value="<?= e($payOld['amount'] ?? '') ?>" placeholder="المبلغ" required style="max-width:130px"<?= fieldErrorAttrs('amount', $payErrors) ?>><?= fieldError('amount', $payErrors) ?></div>
      <div class="field" style="margin:0"><input type="date" name="pay_date" value="<?= e($payOld['pay_date'] ?? work_date()) ?>"<?= fieldErrorAttrs('pay_date', $payErrors) ?>><?= fieldError('pay_date', $payErrors) ?></div>
      <input type="text" name="note" value="<?= e($payOld['note'] ?? '') ?>" placeholder="ملاحظة (اختياري)">
      <button class="btn gold sm" type="submit" data-primary-submit><?= icon('plus', 14) ?> دفع</button>
    </form>
    <?php endif; ?>
  </div>

  <div class="card" style="padding:20px">
    <div class="section-head">سجل الدفعات (<?= count($payments) ?>)</div>
    <div class="table-wrap"><table class="data">
      <thead><tr><th class="g-identity">التاريخ</th><th class="g-revenue">المبلغ</th><th class="g-identity">ملاحظة</th><th class="g-identity">حذف</th></tr></thead>
      <tbody>
        <?php $t=0; foreach ($payments as $p): $t+=$p['amount']; ?>
          <tr>
            <td><?= dfmt($p['pay_date']) ?></td>
            <td class="pos"><?= money($p['amount'],false) ?></td>
            <td><?= e($p['note']) ?><?php if ((int)($p['is_legacy'] ?? 0) === 1): ?> <span class="pill" style="background:#7a5f0e;color:#fff">تاريخي</span><?php endif; ?></td>
            <td>
              <?php if ((int)($p['is_legacy'] ?? 0) !== 1): ?>
              <form class="confirm-delete" method="post" action="<?= url('settlements/'.$bill['id'].'/payment/'.$p['id'].'/delete') ?>" style="margin:0">
                <?= csrfField() ?><?= formStateFields(FormState::token(), 'settlements/'.(int)$bill['id']) ?><button class="del" type="submit" title="حذف"><?= icon('trash', 14) ?></button>
              </form>
              <?php else: ?><span title="دفعة تاريخية ثابتة"><?= icon('alert', 14) ?></span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$payments): ?><tr><td colspan="4"><div class="empty">لا دفعات بعد</div></td></tr><?php endif; ?>
      </tbody>
      <?php if($payments): ?><tfoot><tr><td>الإجمالي</td><td><?= money($t,false) ?></td><td colspan="2"></td></tr></tfoot><?php endif; ?>
    </table></div>
  </div>
</div>
