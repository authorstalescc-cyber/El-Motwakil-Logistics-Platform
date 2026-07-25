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
  display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.4rem}
.rep-hero h2{margin:0;color:var(--navy)}
.rep-hero .meta{color:var(--grey);font-size:.9rem}
.rep-sum{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:18px}
.rep-sum .sc{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:13px 12px;text-align:center}
.rep-sum .sc .l{font-size:.78rem;color:var(--grey);font-weight:700}
.rep-sum .sc .v{font-size:1.3rem;font-weight:800;margin-top:4px;color:var(--navy)}
.rep-sum .sc.bal{background:var(--navy)} .rep-sum .sc.bal .l{color:#dfe8f2} .rep-sum .sc.bal .v{color:var(--gold)}
.rep-sum .sc .v.pos{color:var(--green)} .rep-sum .sc .v.neg{color:var(--red)}
.entry-form{display:flex;flex-wrap:wrap;gap:10px;align-items:end}
.entry-form .field{margin:0}
.st-settled{font-size:.72rem;background:#eef2f7;color:var(--navy);padding:1px 8px;border-radius:12px;font-weight:700}
</style>

<div class="page-actions" style="display:flex;gap:8px;flex-wrap:wrap">
  <a class="btn ghost" href="<?= url('reps') ?>">‹ رجوع للمناديب</a>
  <div class="spacer" style="flex:1"></div>
  <a class="btn" href="<?= url('reps/'.$rep['id'].'/edit') ?>">✏️ تعديل الكارت</a>
  <form method="post" action="<?= url('reps/'.$rep['id'].'/delete') ?>" class="confirm-delete" data-confirm="حذف المندوب وكل سجله نهائيًا؟" style="margin:0">
    <?= csrfField() ?>
    <button class="btn ghost" type="submit" style="color:var(--red)">🗑️ حذف</button>
  </form>
</div>

<div class="rep-hero">
  <span class="av"><?= e(initials($rep['name'])) ?></span>
  <div>
    <h2><?= e($rep['name']) ?>
      <span class="rc-status <?= $rep['status']==='active'?'active':'inactive' ?>" style="font-size:.6em;vertical-align:middle;padding:2px 10px;border-radius:20px;<?= $rep['status']==='active'?'background:#e6f4ec;color:var(--green)':'background:#f1f1f4;color:var(--grey)' ?>"><?= $rep['status']==='active'?'شغّال':'موقوف' ?></span>
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
<div class="card mb" style="padding:16px 18px">
  <div class="section-head" style="margin:0 0 10px"><?= icon('plus', 17) ?> إضافة بند لسجل المندوب</div>
  <?php if ($eErr !== ''): ?><div class="flash error" role="alert"><?= icon('alert',17) ?> <?= e($eErr) ?></div><?php endif; ?>
  <form method="post" action="<?= url('reps/'.$rep['id'].'/entry') ?>" class="entry-form">
    <?= csrfField() ?>
    <div class="field" style="width:150px">
      <label>التاريخ</label>
      <input type="date" name="entry_date" value="<?= e($eOld['entry_date'] ?? work_date()) ?>">
    </div>
    <div class="field" style="min-width:200px">
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
    <div class="field" style="width:140px">
      <label>المبلغ</label>
      <input type="number" step="0.01" name="amount" value="<?= e($eOld['amount'] ?? '') ?>" placeholder="0" required>
    </div>
    <div class="field" style="flex:1;min-width:160px">
      <label>البيان / ملاحظة</label>
      <input type="text" name="note" value="<?= e($eOld['note'] ?? '') ?>" placeholder="اختياري">
    </div>
    <label class="visa-toggle" style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:700;padding-bottom:8px">
      <input type="checkbox" name="is_visa" value="1" style="width:16px;height:16px;accent-color:var(--gold)">💳 فيزا
    </label>
    <button class="btn gold" type="submit">إضافة</button>
  </form>
  <div style="color:var(--grey);font-size:.8rem;margin-top:8px">
    💡 «سُلفة» و«دفعة التسوية» بس هما اللي بيتخصموا من العهدة ويظهروا في المصروفات العامة. باقي البنود استحقاقات في الكشف بتتغطّى وقت التقفيل.
  </div>
</div>

<!-- كشف الحساب -->
<div class="card mb">
  <div class="section-head" style="padding:16px 18px 0;margin:0"><?= icon('layers', 17) ?> كشف حساب المندوب</div>
  <div class="table-wrap"><table class="data">
    <thead><tr>
      <th>التاريخ</th><th>النوع</th><th>البيان</th>
      <th class="g-revenue">له (+)</th><th class="g-expense">عليه (−)</th>
      <th>الرصيد الجاري</th><th></th>
    </tr></thead>
    <tbody>
      <?php if(!$ledger): ?>
        <tr><td colspan="7"><div class="empty"><div class="ic">📭</div>لا بنود في سجل المندوب بعد.</div></td></tr>
      <?php else: foreach ($ledger as $r): $isCredit = in_array($r['kind'], $creditKinds, true); ?>
        <tr>
          <td><?= $r['d']?e(dfmt($r['d'])):'—' ?></td>
          <td>
            <span class="pill <?= $isCredit?'st-paid':'st-partial' ?>"><?= e($r['label']) ?></span>
            <?php if(!empty($r['is_visa'])): ?><span class="st-settled">💳 فيزا</span><?php endif; ?>
          </td>
          <td style="text-align:right"><?= e($r['note'] ?: '—') ?>
            <?php if($r['settlement_id']): ?><span class="st-settled" title="اتحاسب في تقفيل">مُقفَل</span><?php endif; ?>
          </td>
          <td class="pos"><?= $isCredit?money($r['amount'],false):'—' ?></td>
          <td class="neg"><?= !$isCredit?money($r['amount'],false):'—' ?></td>
          <td style="font-weight:700;color:<?= $r['running']<0?'var(--red)':'inherit' ?>"><?= money($r['running'],false) ?></td>
          <td style="white-space:nowrap">
            <?php if(!$r['settlement_id'] || $r['kind']!=='settlement'): ?>
              <form method="post" action="<?= url('reps/entry/'.$r['id'].'/delete') ?>" class="confirm-delete" data-confirm="حذف هذا البند؟" style="display:inline">
                <?= csrfField() ?>
                <button class="edit" type="submit" title="حذف" style="background:none;border:0;cursor:pointer">🗑️</button>
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
  <div class="card" style="padding:16px 18px">
    <div class="section-head" style="margin:0 0 10px"><?= icon('receipt', 17) ?> تقفيل الحساب (يدوي من–إلى)</div>
    <?php if ($sErr !== ''): ?><div class="flash error" role="alert"><?= icon('alert',17) ?> <?= e($sErr) ?></div><?php endif; ?>
    <form method="post" action="<?= url('reps/'.$rep['id'].'/settle') ?>" class="confirm-financial" data-confirm="تقفيل حساب المندوب عن الفترة المحدّدة وتسجيل دفعة التسوية؟">
      <?= csrfField() ?>
      <div class="form-grid two">
        <div class="field"><label>من تاريخ</label><input type="date" name="period_from" value="<?= e($sOld['period_from'] ?? '') ?>"></div>
        <div class="field"><label>إلى تاريخ</label><input type="date" name="period_to" value="<?= e($sOld['period_to'] ?? '') ?>"></div>
        <div class="field"><label>تاريخ الدفع</label><input type="date" name="pay_date" value="<?= e($sOld['pay_date'] ?? work_date()) ?>"></div>
        <div class="field">
          <label>طريقة الدفع</label>
          <label class="visa-toggle" style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:700;padding-top:8px">
            <input type="checkbox" name="is_visa" value="1" style="width:16px;height:16px;accent-color:var(--gold)">💳 فيزا (لا يُخصم من العهدة)
          </label>
        </div>
        <div class="field" style="grid-column:1/-1"><label>ملاحظة على التسوية</label><input type="text" name="note" value="<?= e($sOld['note'] ?? '') ?>" placeholder="اختياري"></div>
      </div>
      <div style="color:var(--grey);font-size:.82rem;margin:6px 0 12px">
        سيبها فاضية عشان تحاسب على كل البنود غير المتحاسبة. الصافي = المستحقات − الخصومات − السُلف.
      </div>
      <button class="btn gold" type="submit">🧾 تقفيل ودفع الصافي</button>
    </form>
  </div>

  <div class="card" style="padding:16px 18px">
    <div class="section-head" style="margin:0 0 10px"><?= icon('receipt', 17) ?> تقفيلات سابقة</div>
    <?php if(!$settlements): ?>
      <div class="empty" style="padding:20px"><div class="ic">🗂️</div>لسه مفيش تقفيلات.</div>
    <?php else: ?>
      <div class="table-wrap"><table class="data">
        <thead><tr><th>التاريخ</th><th>الفترة</th><th>الصافي</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($settlements as $st): ?>
            <tr>
              <td><?= e(dfmt($st['pay_date'] ?: substr($st['settled_at'],0,10))) ?></td>
              <td style="font-size:.85rem"><?= $st['period_from']||$st['period_to'] ? e(dfmt($st['period_from'])).' – '.e(dfmt($st['period_to'])) : 'الكل' ?></td>
              <td style="font-weight:700"><?= money($st['net'],false) ?><?= $st['is_visa']?' 💳':'' ?></td>
              <td><a class="btn ghost sm" href="<?= url('reps/'.$rep['id'].'/settlement/'.$st['id'].'/print') ?>" target="_blank">🖨️ طباعة</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>
</div>
