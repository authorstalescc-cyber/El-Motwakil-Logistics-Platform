<?php
/* إعادة تعبئة نموذجي «بند السجل» و«تقفيل الحساب» بعد الرفض (المرحلة ٢). */
$eOld = $entryState['old'] ?? []; $eErr = $entryState['errors']['_form'] ?? '';
$sOld = $settleState['old'] ?? []; $sErr = $settleState['errors']['_form'] ?? '';
?>
<?php
use App\Models\Rep;
/**
 * كارت المندوب — بروفايل + كشف حساب بطرفين + إدخال بنود + تقفيل دوري.
 * @var array $rep @var array $summary @var array $ledger @var array $settlements @var array $kinds
 */
$s = $summary;
// تجميع الأنواع في optgroups للفورم
$creditKinds = Rep::CREDIT_KINDS;                       // مستحقات (+)
$cashKinds   = Rep::CASH_KINDS;                          // كاش يخرج من الخزنة
$kindColor = function(string $k) use ($creditKinds): string {
    return in_array($k, $creditKinds, true) ? 'pos' : 'neg';
};
?>
<style>
.rep-hero{display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:16px}
.rep-hero .av{width:60px;height:60px;border-radius:50%;background:var(--navy);color:var(--gold);
  display:flex;align-items:center;justify-content:center;font-weight:600;font-size:var(--fs-6)}
.rep-hero h2{margin:0;color:var(--accent)}
.rep-hero .meta{color:var(--grey);font-size:var(--fs-3)}
.rep-sum{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:18px}
.rep-sum .sc{background:var(--card);border:1px solid var(--line);border-radius:var(--radius-2);padding:13px 12px;text-align:center}
.rep-sum .sc .l{font-size:var(--fs-1);color:var(--grey);font-weight:500}
.rep-sum .sc .v{font-size:var(--fs-6);font-weight:600;margin-top:4px;color:var(--accent)}
.rep-sum .sc.bal{background:var(--navy)} .rep-sum .sc.bal .l{color:#dfe8f2} .rep-sum .sc.bal .v{color:var(--gold)}
.rep-sum .sc .v.pos{color:var(--green-fg)} .rep-sum .sc .v.neg{color:var(--red-fg)}
.entry-form{display:flex;flex-wrap:wrap;gap:10px;align-items:end}
.entry-form .field{margin:0}
.st-settled{font-size:var(--fs-1);background:var(--zebra);color:var(--accent);padding:1px 8px;border-radius:var(--radius-2);font-weight:500}
</style>

<div class="page-actions p5-row tight">
  <a class="btn ghost" href="<?= url('reps') ?>">‹ رجوع للمناديب</a>
  <div class="spacer ps-sp"></div>
  <a class="btn" href="<?= url('reps/'.$rep['id'].'/edit') ?>"><?= icon('edit', 14) ?> تعديل الكارت</a>
  <form method="post" action="<?= url('reps/'.$rep['id'].'/delete') ?>" class="confirm-delete" data-confirm="حذف المندوب وكل سجله نهائيًا؟" >
    <?= csrfField() ?>
    <button class="btn ghost danger-tx" type="submit"><?= icon('trash', 14) ?> حذف</button>
  </form>
</div>

<div class="rep-hero">
  <span class="av"><?= e(initials($rep['name'])) ?></span>
  <div>
    <h2><?= e($rep['name']) ?>
      <span class="rc-status <?= $rep['status']==='active'?'active':'inactive' ?>" style="font-size:.6em;vertical-align:middle;padding:2px 10px;border-radius:var(--radius-4);<?= $rep['status']==='active'?'background:var(--green-lt);color:var(--green-fg)':'background:#f1f1f4;color:var(--grey)' ?>"><?= $rep['status']==='active'?'شغّال':'موقوف' ?></span>
    </h2>
    <div class="meta">
      <?php if($rep['phone']): ?><bdi dir="ltr"><?= e($rep['phone']) ?></bdi> • <?php endif; ?>
      المرتب: <?= money($rep['monthly_salary']) ?>
      <?php if($rep['hire_date']): ?> • معيّن من <?= e(dfmt($rep['hire_date'])) ?><?php endif; ?>
    </div>
  </div>
</div>

<!-- ملخّص الحساب -->
<div class="rep-sum">
  <div class="sc"><div class="l">مستحقات (مرتب+مواصلات+لوجيستي)</div><div class="v pos"><?= money($s['due'],false) ?></div></div>
  <div class="sc"><div class="l">خصومات</div><div class="v neg"><?= money($s['deductions'],false) ?></div></div>
  <div class="sc"><div class="l">سُلف مصروفة</div><div class="v neg"><?= money($s['advances'],false) ?></div></div>
  <div class="sc"><div class="l">تسويات مدفوعة</div><div class="v"><?= money($s['paid'],false) ?></div></div>
  <div class="sc bal"><div class="l">المستحق له علينا الآن</div><div class="v"><?= money($s['balance'],false) ?></div></div>
</div>

<!-- إضافة بند للسجل -->
<div class="card mb oc-pad">
  <div class="section-head oc-sec"><?= icon('plus', 17) ?> إضافة بند لسجل المندوب</div>
  <?php if ($eErr !== ''): ?><div class="flash error" role="alert"><?= icon('alert',17) ?> <?= e($eErr) ?></div><?php endif; ?>
  <form method="post" action="<?= url('reps/'.$rep['id'].'/entry') ?>" class="entry-form">
    <?= csrfField() ?>
    <?php /* رمز النموذج يمنع تسجيل نفس البند مرتين (ضغطتان / رجوع المتصفح ثم
             إعادة إرسال). ⚠️ شيله = `claimForm` في الكنترولر هترفض كل حفظ. */ ?>
    <?= formStateFields($entryState['token'], $entryState['back']) ?>
    <div class="field w150">
      <label>التاريخ</label>
      <input type="date" name="entry_date" value="<?= e($eOld['entry_date'] ?? work_date()) ?>">
    </div>
    <div class="field p5-mw200">
      <label>النوع</label>
      <select name="kind" required>
        <optgroup label="مستحقات له (+)">
          <?php foreach ($creditKinds as $k): ?><option value="<?= $k ?>" <?= ($eOld['kind'] ?? '') === $k ? 'selected' : '' ?>><?= e($kinds[$k]) ?></option><?php endforeach; ?>
        </optgroup>
        <optgroup label="خصومات / صرف عليه (−)">
          <option value="expense_deducted" <?= ($eOld['kind'] ?? '') === 'expense_deducted' ? 'selected' : '' ?>><?= e($kinds['expense_deducted']) ?></option>
          <option value="deduction" <?= ($eOld['kind'] ?? '') === 'deduction' ? 'selected' : '' ?>><?= e($kinds['deduction']) ?></option>
          <option value="advance" <?= ($eOld['kind'] ?? '') === 'advance' ? 'selected' : '' ?>><?= e($kinds['advance']) ?> — كاش من الخزنة</option>
        </optgroup>
      </select>
    </div>
    <div class="field w140">
      <label>المبلغ</label>
      <input data-money type="number" step="0.01" name="amount" value="<?= e($eOld['amount'] ?? '') ?>" placeholder="0" required>
    </div>
    <div class="field grow">
      <label>البيان / ملاحظة</label>
      <input type="text" name="note" value="<?= e($eOld['note'] ?? '') ?>" placeholder="اختياري">
    </div>
    <label class="visa-toggle p5-chk pb">
      <input type="checkbox" name="is_visa" value="1" class="dbt-cbx"><?= icon('credit-card', 14) ?> فيزا
    </label>
    <button class="btn gold" type="submit">إضافة</button>
  </form>
  <div class="p5-p-mt">
    💡 «سُلفة» و«دفعة التسوية» بس هما اللي بيتخصموا من العهدة ويظهروا في المصروفات العامة. باقي البنود استحقاقات في الكشف بتتغطّى وقت التقفيل.
  </div>
</div>

<!-- كشف الحساب -->
<div class="card mb">
  <div class="section-head rts-head"><?= icon('layers', 17) ?> كشف حساب المندوب</div>
  <div class="table-wrap"><table class="data">
    <thead><tr>
      <th>التاريخ</th><th>النوع</th><th>البيان</th>
      <th class="g-revenue">له (+)</th><th class="g-expense">عليه (−)</th>
      <th>الرصيد الجاري</th><th></th>
    </tr></thead>
    <tbody>
      <?php if(!$ledger): ?>
        <tr><td colspan="7"><div class="empty"><div class="ic"><?= icon('inbox', 14) ?></div>لا بنود في سجل المندوب بعد.</div></td></tr>
      <?php else: foreach ($ledger as $r): $isCredit = in_array($r['kind'], $creditKinds, true); ?>
        <tr>
          <td><?= $r['d']?e(dfmt($r['d'])):'—' ?></td>
          <td>
            <span class="pill <?= $isCredit?'st-paid':'st-partial' ?>"><?= e($r['label']) ?></span>
            <?php if(!empty($r['is_visa'])): ?><span class="st-settled"><?= icon('credit-card', 14) ?> فيزا</span><?php endif; ?>
          </td>
          <td class="txt"><?= e($r['note'] ?: '—') ?>
            <?php if($r['settlement_id']): ?><span class="st-settled" title="اتحاسب في تقفيل">مُقفَل</span><?php endif; ?>
          </td>
          <td class="pos"><?= $isCredit?money($r['amount'],false):'—' ?></td>
          <td class="neg"><?= !$isCredit?money($r['amount'],false):'—' ?></td>
          <td class="mv-ref<?= $r['running']<0?' neg':'' ?>"><?= money($r['running'],false) ?></td>
          <td class="nowrap">
            <?php if(!$r['settlement_id'] || $r['kind']!=='settlement'): ?>
              <form method="post" action="<?= url('reps/entry/'.$r['id'].'/delete') ?>" class="confirm-delete" data-confirm="حذف هذا البند؟" >
                <?= csrfField() ?>
                <button class="edit oc-btn-bare" type="submit" title="حذف"><?= icon('trash', 14) ?></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<!-- تقفيل الحساب -->
<div class="two-col">
  <div class="card oc-pad">
    <div class="section-head oc-sec"><?= icon('receipt', 17) ?> تقفيل الحساب (يدوي من–إلى)</div>
    <?php if ($sErr !== ''): ?><div class="flash error" role="alert"><?= icon('alert',17) ?> <?= e($sErr) ?></div><?php endif; ?>
    <form method="post" action="<?= url('reps/'.$rep['id'].'/settle') ?>" class="confirm-financial" data-confirm="تقفيل حساب المندوب عن الفترة المحدّدة وتسجيل دفعة التسوية؟">
      <?= csrfField() ?>
      <?php /* رمز النموذج يمنع تسجيل دفعة التسوية مرتين. ⚠️ اقرأ التعليق فوق. */ ?>
      <?= formStateFields($settleState['token'], $settleState['back']) ?>
      <div class="form-grid two">
        <div class="field"><label>من تاريخ</label><input type="date" name="period_from" value="<?= e($sOld['period_from'] ?? '') ?>"></div>
        <div class="field"><label>إلى تاريخ</label><input type="date" name="period_to" value="<?= e($sOld['period_to'] ?? '') ?>"></div>
        <div class="field"><label>تاريخ الدفع</label><input type="date" name="pay_date" value="<?= e($sOld['pay_date'] ?? work_date()) ?>"></div>
        <div class="field">
          <label>طريقة الدفع</label>
          <label class="visa-toggle p5-chk pt">
            <input type="checkbox" name="is_visa" value="1" class="dbt-cbx"><?= icon('credit-card', 14) ?> فيزا (لا يُخصم من العهدة)
          </label>
        </div>
        <div class="field field-full"><label>ملاحظة على التسوية</label><input type="text" name="note" value="<?= e($sOld['note'] ?? '') ?>" placeholder="اختياري"></div>
      </div>
      <div class="p5-p-sm">
        سيبها فاضية عشان تحاسب على كل البنود غير المتحاسبة. الصافي = المستحقات − الخصومات − السُلف.
      </div>
      <button class="btn gold" type="submit"><?= icon('receipt', 14) ?> تقفيل ودفع الصافي</button>
    </form>
  </div>

  <div class="card oc-pad">
    <div class="section-head oc-sec"><?= icon('receipt', 17) ?> تقفيلات سابقة</div>
    <?php if(!$settlements): ?>
      <div class="empty dag-pad-lg"><div class="ic"><?= icon('folder', 14) ?></div>لسه مفيش تقفيلات.</div>
    <?php else: ?>
      <div class="table-wrap"><table class="data">
        <thead><tr><th>التاريخ</th><th>الفترة</th><th>الصافي</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($settlements as $st): ?>
            <tr>
              <td><?= e(dfmt($st['pay_date'] ?: substr($st['settled_at'],0,10))) ?></td>
              <td class="rts-sm"><?= $st['period_from']||$st['period_to'] ? e(dfmt($st['period_from'])).' – '.e(dfmt($st['period_to'])) : 'الكل' ?></td>
              <td class="mv-ref"><?= money($st['net'],false) ?><?= $st['is_visa']?' 💳':'' ?></td>
              <td><a class="btn ghost sm" href="<?= url('reps/'.$rep['id'].'/settlement/'.$st['id'].'/print') ?>" target="_blank"><?= icon('file-text', 14) ?> طباعة</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>
</div>
