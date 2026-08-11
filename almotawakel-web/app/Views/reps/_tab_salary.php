<?php
/**
 * تبويب «الراتب» — كشف الفترة + البنود اليدوية + إصدار الراتب.
 *
 * ⚠️ **كل رقم هنا مصدره `RepProfile::salary`** اللي بتقرا من `RepAccount` و
 *    `Payroll` — يعني **نفس اللي المندوب بيشوفه على موبايله** بالحرف.
 * ⚠️ **فورم البند بيختفي لو الفترة اتعتمدت** — والحارس الحقيقي في
 *    `Rep::createEntry` مش في الإخفاء ده (قاعدة ③).
 *
 * @var array $pay @var array $rep @var int $rid @var array $period
 * @var array $kinds @var array $entryState @var array $settleState
 */
use App\Models\Rep;

$eOld = $entryState['old'] ?? [];  $eErr = $entryState['errors']['_form'] ?? '';
$sOld = $settleState['old'] ?? []; $sErr = $settleState['errors']['_form'] ?? '';
$locked  = $pay['locked'];
$preview = $pay['preview'];

/** سطر في كشف الحساب — البند واحد فمفيش نسختين بيفترقوا. */
$line = static function (string $label, float $v, string $tone = '', ?string $drill = null, ?string $title = null): void { ?>
  <div class="rpf-line">
    <span class="rpf-k"><?= e($label) ?></span>
    <?php if ($drill !== null): ?>
      <button type="button" class="num-btn rpf-v <?= e($tone) ?>" data-drill="<?= e($drill) ?>"
              data-drill-title="<?= e($title ?? $label) ?>"><?= money($v, false) ?></button>
    <?php else: ?>
      <span class="rpf-v <?= e($tone) ?>"><?= money($v, false) ?></span>
    <?php endif; ?>
  </div>
<?php };
?>

<div class="two-col">

  <?php /* ═══ كشف الراتب ═══ */ ?>
  <div class="card oc-pad">
    <div class="section-head oc-sec">
      <?= icon('banknote', 17) ?> راتب <?= e($period['label']) ?>
      <div class="spacer"></div>
      <span class="chip"><?= icon($pay['approved'] ? 'check' : 'edit', 13) ?>
        <?= $pay['approved'] ? 'متعمد' : 'مسوّدة' ?></span>
    </div>

    <div class="rpf-calc">
      <?php $line('راتب ثابت', $pay['salary']); ?>
      <?php $line('سلف الفترة', -$pay['advances'], 'neg', $dr('advances'), 'سلف الفترة'); ?>
      <?php $line('كومسيون البوالص', $pay['commission'], 'pos'); ?>
      <?php $line('حصة مصروف البوالص', $pay['expense'], $pay['expense'] < 0 ? 'neg' : 'pos'); ?>
      <?php $line('زيادة / خصم / جزاءات', $pay['adjustments'],
                  $pay['adjustments'] < 0 ? 'neg' : 'pos',
                  $dr('net'), 'البنود اليدوية'); ?>
      <div class="rpf-line total">
        <span class="rpf-k">الصافي المستحق</span>
        <span class="rpf-v <?= $pay['net'] < 0 ? 'neg' : '' ?>"><?= money($pay['net'], false) ?></span>
      </div>
    </div>

    <?php /* تحذير وظيفي (≤٦ كلمات) */ ?>
    <?php if ($locked): ?>
      <div class="card mb p5-pad-sm p5-edge-gold-r">
        <?= icon('lock', 15) ?> الفترة متعمدة — البنود مقفولة.
      </div>
    <?php endif; ?>
  </div>

  <?php /* ═══ إصدار الراتب عن الفترة ═══ */ ?>
  <div class="card oc-pad">
    <div class="section-head oc-sec"><?= icon('receipt', 17) ?> إصدار راتب الفترة</div>
    <?php if ($sErr !== ''): ?>
      <div class="flash error" role="alert"><?= icon('alert', 17) ?> <?= e($sErr) ?></div>
    <?php endif; ?>

    <div class="rpf-calc">
      <?php $line('مستحقات غير متحاسبة', (float) $preview['due'], 'pos'); ?>
      <?php $line('خصومات', -(float) $preview['deductions'], 'neg'); ?>
      <?php $line('سلف مصروفة', -(float) $preview['advances'], 'neg'); ?>
      <div class="rpf-line total">
        <span class="rpf-k">اللي هيتصرف دلوقتي</span>
        <span class="rpf-v"><?= money((float) $preview['net'], false) ?></span>
      </div>
    </div>

    <?php if ((int) $preview['count'] === 0): ?>
      <div class="card mb p5-pad-sm">
        <?= icon('info', 15) ?> مفيش بنود غير متحاسبة في الفترة.
      </div>
    <?php else: ?>
      <form method="post" action="<?= url('reps/' . $rid . '/settle') ?>" class="confirm-financial"
            data-confirm="تصرف صافي <?= e(money((float) $preview['net'], false)) ?> للمندوب؟">
        <?= csrfField() ?>
        <?php /* رمز النموذج يمنع تسجيل دفعة التسوية مرتين (ضغطتان / رجوع
                 المتصفح ثم إعادة إرسال). ⚠️ شيله = كل حفظ هيترفض. */ ?>
        <?= formStateFields($settleState['token'], $settleState['back']) ?>
        <?php /* ⚠️ الفترة **متعبّية من فترة الرواتب** لا فاضية: الشاشة القديمة
                 كانت بتسيبها للمالك يكتبها بإيده، فأي يوم غلط = تقفيل بيغطّي
                 بنودًا من فترة تانية. */ ?>
        <input type="hidden" name="period_from" value="<?= e($period['from']) ?>">
        <input type="hidden" name="period_to"   value="<?= e($period['to']) ?>">
        <div class="form-grid two">
          <div class="field">
            <label>تاريخ الصرف</label>
            <input type="date" name="pay_date" value="<?= e($sOld['pay_date'] ?? work_date()) ?>">
          </div>
          <div class="field">
            <label>طريقة الصرف</label>
            <label class="p5-chk pt">
              <input type="checkbox" name="is_visa" value="1" class="dbt-cbx">
              <?= icon('credit-card', 14) ?> فيزا
            </label>
          </div>
          <div class="field field-full">
            <label>ملاحظة</label>
            <input type="text" name="note" value="<?= e($sOld['note'] ?? '') ?>" placeholder="اختياري">
          </div>
        </div>
        <button class="btn gold" type="submit">
          <?= icon('receipt', 16) ?> اصرف <?= money((float) $preview['net'], false) ?>
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php /* ═══ البنود اليدوية: زيادة · خصم · جزاء ═══ */ ?>
<div class="card mb oc-pad">
  <div class="section-head oc-sec">
    <?= icon('plus', 17) ?> بند يدوي في راتب الفترة
    <div class="spacer"></div>
    <span class="chip"><?= icon('layers', 13) ?> <?= intf(count($pay['rows'])) ?></span>
  </div>

  <?php if ($eErr !== ''): ?>
    <div class="flash error" role="alert"><?= icon('alert', 17) ?> <?= e($eErr) ?></div>
  <?php endif; ?>

  <?php if ($locked): ?>
    <div class="card mb p5-pad-sm p5-edge-gold-r">
      <?= icon('lock', 15) ?> الغِ اعتماد الفترة عشان تعدّل.
    </div>
  <?php else: ?>
    <form method="post" action="<?= url('reps/' . $rid . '/entry') ?>" class="rpf-form">
      <?= csrfField() ?>
      <?= formStateFields($entryState['token'], $entryState['back']) ?>
      <div class="field w150">
        <label>التاريخ</label>
        <?php /* ⚠️ الافتراضي **جوّه الفترة المعروضة** لا `work_date()`: المالك
                 بيتفرّج على فترة ويكتب بند، فبند بتاريخ النهاردة كان بيقع في
                 فترة تانية ويختفي من قدامه. */ ?>
        <input type="date" name="entry_date" min="<?= e($period['from']) ?>" max="<?= e($period['to']) ?>"
               value="<?= e($eOld['entry_date'] ?? (work_date() >= $period['from'] && work_date() <= $period['to'] ? work_date() : $period['to'])) ?>">
      </div>
      <div class="field p5-mw200">
        <label>النوع</label>
        <select name="kind" required>
          <optgroup label="زيادة له (+)">
            <?php foreach (Rep::CREDIT_KINDS as $k): ?>
              <option value="<?= e($k) ?>" <?= ($eOld['kind'] ?? '') === $k ? 'selected' : '' ?>><?= e($kinds[$k]) ?></option>
            <?php endforeach; ?>
          </optgroup>
          <optgroup label="خصم عليه (−)">
            <?php foreach (Rep::DEBIT_KINDS as $k): if (Rep::isCashKind($k)) { continue; } ?>
              <option value="<?= e($k) ?>" <?= ($eOld['kind'] ?? '') === $k ? 'selected' : '' ?>><?= e($kinds[$k]) ?></option>
            <?php endforeach; ?>
          </optgroup>
          <optgroup label="كاش من الخزنة">
            <option value="advance" <?= ($eOld['kind'] ?? '') === 'advance' ? 'selected' : '' ?>><?= e($kinds['advance']) ?></option>
          </optgroup>
        </select>
      </div>
      <div class="field w140">
        <label>المبلغ</label>
        <input data-money type="number" step="0.01" name="amount"
               value="<?= e($eOld['amount'] ?? '') ?>" placeholder="0" required>
      </div>
      <div class="field grow200">
        <label>البيان</label>
        <input type="text" name="note" value="<?= e($eOld['note'] ?? '') ?>" placeholder="سبب الجزاء أو المكافأة">
      </div>
      <button class="btn gold" type="submit"><?= icon('plus', 15) ?> إضافة</button>
    </form>
  <?php endif; ?>

  <?php partial('table', [
    'cols'  => [['label' => 'التاريخ'], ['label' => 'النوع'], ['label' => 'البيان'],
                ['label' => 'المبلغ'], ['label' => '']],
    'count' => count($pay['rows']),
    'wrapClass' => 'rpf-tight', 'empty' => ['title' => 'مفيش بنود يدوية في الفترة'],
    'rows'  => function () use ($pay, $locked) {
      foreach ($pay['rows'] as $r): ?>
        <tr>
          <td><?= $r['entry_date'] ? e(dfmt((string) $r['entry_date'])) : '—' ?></td>
          <td><span class="chip"><?= e((string) $r['label']) ?></span></td>
          <td class="txt"><?= e((string) ($r['note'] ?: '—')) ?></td>
          <td class="<?= $r['signed'] < 0 ? 'neg' : 'pos' ?>"><?= money((float) $r['signed'], false) ?></td>
          <td class="nowrap">
            <?php if (!$locked && empty($r['settlement_id'])): ?>
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

<?php /* ═══ سجل رواتب سابقة ═══ */ ?>
<div class="card mb">
  <div class="section-head rts-head"><?= icon('folder', 17) ?> فترات سابقة</div>
  <?php partial('table', [
    'cols'  => [['label' => 'الفترة'], ['label' => 'ثابت'], ['label' => 'سلف'],
                ['label' => 'كومسيون'], ['label' => 'مصروف'], ['label' => 'يدوي'],
                ['label' => 'الصافي'], ['label' => 'الحالة']],
    'count' => count($pay['history']),
    'wrapClass' => 'rpf-tight', 'empty' => ['title' => 'لسه مفيش فترات معتمدة'],
    'rows'  => function () use ($pay, $rid) {
      foreach ($pay['history'] as $h): ?>
        <tr>
          <td class="rts-sm">
            <a href="<?= e(url('reps/' . $rid)) ?>?tab=salary&at=<?= e((string) $h['period_from']) ?>">
              <?= e(dfmt((string) $h['period_from'])) ?> – <?= e(dfmt((string) $h['period_to'])) ?></a>
          </td>
          <td><?= money((float) $h['base_salary'], false) ?></td>
          <td class="neg"><?= money((float) $h['advances'], false) ?></td>
          <td><?= money((float) $h['commission_share'], false) ?></td>
          <td><?= money((float) $h['expense_share'], false) ?></td>
          <td><?= money((float) ($h['adjustments'] ?? 0), false) ?></td>
          <td><b><?= money((float) $h['net'], false) ?></b></td>
          <td><span class="chip"><?= $h['status'] === 'approved' ? 'متعمد' : 'مسوّدة' ?></span></td>
        </tr>
      <?php endforeach;
    },
  ]); ?>
</div>
