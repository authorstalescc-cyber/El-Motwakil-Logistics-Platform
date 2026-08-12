<?php
/**
 * تبويب «التحصيلات» — نموذج تسجيل تحصيل + سجل التحصيلات.
 *
 * ⚠️ النموذج ده **نفس المنطق القديم بالحرف** (`ClientPayment::create`) —
 *    الجديد إنه بقى يقدر يتحصّل على **فاتورة بعينها** بدل التخصيص التلقائي
 *    على الأقدم، والاتنين في نفس الفورم.
 * ⚠️ `visa_card_select()` هي المصدر الوحيد لقايمة الكروت، و`routeChoices()`
 *    للتوجيهات — خيار معروض والخادم يرفضه = دفعة بتتسجّل «بدون توجيه» بالسكوت.
 *
 * @var array $agent @var array $payments @var array $payState @var array $openInv
 */
use App\Models\ClientPayment;

$id      = (int) $agent['id'];
$money   = static fn ($v) => money((float) $v, false);
$payOld  = $payState['old'] ?? [];
$payErr  = $payState['errors']['_form'] ?? '';
$routeOld= (string) ($payOld['route'] ?? 'none');
$cards   = ClientPayment::routeChoices();

$sumPosted = 0.0; $sumOpen = 0.0;
foreach ($payments as $pm) {
    if (($pm['status'] ?? '') !== 'posted') { continue; }
    $sumPosted += (float) $pm['amount'];
    $sumOpen   += (float) $pm['unallocated_amount'];
}
?>

<?php if ($payErr !== ''): ?>
  <div class="flash error" role="alert"><?= icon('alert', 18) ?> <?= e($payErr) ?></div>
<?php endif; ?>

<div class="card mb" id="pay">
  <div class="section-head"><?= icon('banknote', 17) ?> تسجيل تحصيل</div>
  <form method="post" action="<?= url('clients/' . $id . '/pay') ?>" class="cl-pay">
    <?= csrfField() ?>
    <?php /* ⚠️ رمز النموذج بيمنع تسجيل نفس الدفعة مرتين — والدفعة الموجَّهة
             «كاش» بتنشئ عهدة، فتكرارها بيزوّد الخزنة بمبلغ ما دخلش.
             شيله = `claimForm` في الكنترولر هترفض كل حفظ. */ ?>
    <?= formStateFields($payState['token'], $payState['back']) ?>

    <div class="cl-pay-row">
      <div class="field flush w150">
        <label for="pay-date">التاريخ</label>
        <input id="pay-date" type="date" name="pay_date" value="<?= e($payOld['pay_date'] ?? work_date()) ?>">
      </div>
      <div class="field flush w150">
        <label for="pay-amount">المبلغ <span class="req">*</span></label>
        <input id="pay-amount" data-money type="number" step="0.01" min="0" name="amount"
               value="<?= e($payOld['amount'] ?? '') ?>" placeholder="0" required>
      </div>
      <div class="field flush grow">
        <label for="pay-note">البيان</label>
        <input id="pay-note" type="text" name="note" value="<?= e($payOld['note'] ?? '') ?>" placeholder="اختياري">
      </div>
    </div>

    <div class="cl-pay-row">
      <div class="field flush grow">
        <label for="pay-invoice">تخصيص التحصيل</label>
        <select id="pay-invoice" name="invoice_id">
          <option value="">تلقائي — على أقدم فاتورة مستحقة</option>
          <?php foreach ($openInv as $iv): ?>
            <option value="<?= (int) $iv['id'] ?>" <?= (string) ($payOld['invoice_id'] ?? '') === (string) $iv['id'] ? 'selected' : '' ?>>
              <?= e($iv['doc_no'] ?: '#' . $iv['id']) ?> — باقي <?= e(money((float) $iv['balance_due'], false)) ?><?= $iv['due_date'] ? ' — استحقاق ' . e(dfmt($iv['due_date'])) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field flush grow">
        <label for="pay-route">توجيه المبلغ</label>
        <select id="pay-route" name="route" data-toggle-visa="cl-visa">
          <?php foreach ($cards as $k => $lbl): ?>
            <option value="<?= e($k) ?>" <?= $routeOld === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field flush grow" id="cl-visa" <?= $routeOld === 'visa' ? '' : 'hidden' ?>>
        <label for="pay-card">كارت الفيزا</label>
        <?= visa_card_select((int) ($payOld['visa_card_id'] ?? 0), 'visa_card_id', '', 'pay-card') ?>
      </div>
    </div>

    <div class="cl-pay-foot">
      <label class="cl-check"><input type="checkbox" name="auto_allocate" value="1" checked> تخصيص تلقائي على الأقدم</label>
      <button class="btn gold" type="submit"><?= icon('plus', 15) ?> تسجيل التحصيل</button>
      <?php /* تحذير **وظيفي**: التوجيه بيحرّك فلوس في مكان تاني. */ ?>
      <span class="cl-mut">«نقدي» يدخل التقفيل · «فيزا» يشحن الكارت</span>
    </div>
  </form>
</div>

<div class="card">
  <div class="section-head">
    <?= icon('layers', 17) ?> سجل التحصيلات
    <span class="cl-mut"><?= intf(count($payments)) ?></span>
    <span class="cl-spacer"></span>
    <span class="cl-mut">غير مخصَّص: <b><?= $money($sumOpen) ?></b></span>
  </div>

  <?php if (!$payments): ?>
    <?php partial('empty', ['emoji' => '💵', 'title' => 'مفيش تحصيلات للعميل ده لسه']); ?>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr>
          <th class="g-identity">التاريخ</th>
          <th class="g-revenue">المبلغ</th>
          <th class="g-identity">التوجيه</th>
          <th class="g-revenue">غير مخصَّص</th>
          <th class="g-identity">الحالة</th>
          <th class="g-identity">البيان</th>
          <th class="g-identity"></th>
        </tr></thead>
        <tbody>
          <?php foreach ($payments as $pm): $posted = ($pm['status'] ?? '') === 'posted'; ?>
            <tr class="<?= $posted ? '' : 'cl-void' ?>">
              <td class="nowrap"><?= $pm['pay_date'] ? e(dfmt($pm['pay_date'])) : '—' ?></td>
              <td class="pos dbt-tot"><?= $money($pm['amount']) ?></td>
              <td class="nowrap">
                <span class="pill <?= $pm['route'] === 'cash' ? 'st-paid' : ($pm['route'] === 'none' ? '' : 'st-partial') ?>">
                  <?= icon($pm['route'] === 'visa' ? 'credit-card' : ($pm['route'] === 'company' ? 'home' : 'banknote'), 13) ?>
                  <?= e(ClientPayment::routeLabel((string) $pm['route'])) ?>
                </span>
              </td>
              <td><?= $posted ? $money($pm['unallocated_amount']) : '—' ?></td>
              <td><span class="pill <?= $posted ? 'st-paid' : 'st-unpaid' ?>"><?= $posted ? 'مرحّل' : 'معكوس' ?></span></td>
              <td class="txt"><?= e($pm['note'] ?: ($pm['reverse_reason'] ?? '') ?: '—') ?></td>
              <td class="nowrap">
                <?php if ($posted): ?>
                  <form method="post" action="<?= url('clients/' . $id . '/payment/' . (int) $pm['id'] . '/reverse') ?>"
                        class="cl-rev" data-danger="1" data-confirm="عكس التحصيل ده مع بقاء سجله؟">
                    <?= csrfField() ?>
                    <input name="reason" required placeholder="سبب العكس" class="w100">
                    <button class="edit" type="submit" title="عكس التحصيل"><?= icon('undo', 14) ?></button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot><tr>
          <td class="end">الإجمالي</td>
          <td class="pos dbt-tot"><?= $money($sumPosted) ?></td>
          <td colspan="5"></td>
        </tr></tfoot>
      </table>
    </div>
  <?php endif; ?>
</div>
