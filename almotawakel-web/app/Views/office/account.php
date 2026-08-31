<?php use App\Core\FormState; $st=$entryState; $old=$st['old']??[]; $errors=$st['errors']??[]; ?>
<div class="page-actions">
  <a class="btn ghost" href="<?= url('office') ?>">‹ كل الحسابات</a>
  <a class="btn gold" href="<?= url('office/accounts/'.$account['id'].'/statement') ?>" target="_blank"><?= icon('receipt',14) ?> كشف حساب</a>
  <a class="btn ghost" href="<?= url('office/accounts/'.$account['id'].'/edit') ?>"><?= icon('edit',14) ?> تعديل</a>
  <a class="btn ghost" href="<?= url('office/new') ?>"><?= icon('plus',14) ?> إضافة كارت</a>
  <form method="post" action="<?= url('office/accounts/'.$account['id'].'/delete') ?>" data-danger="1" data-confirm="حذف الحساب الفارغ؟"><?= csrfField() ?><?= formStateFields(FormState::token(),'office') ?><button class="btn ghost danger"><?= icon('trash',14) ?> حذف</button></form>
</div>

<div class="two-col mb">
  <div class="kpi oc-hero <?= $balance<0?'red':'gold' ?>"><div class="lbl">الرصيد المتاح لكل كروت الحساب</div><div class="val oc-hero-val"><?= intf($balance) ?></div><div class="oc-hero-meta"><?= icon('landmark',14) ?> <?= e($account['name']) ?><?= $account['bank_name']?' — '.e($account['bank_name']):'' ?></div></div>
  <div class="card oc-pad"><div class="section-head oc-sec"><?= icon('credit-card',17) ?> الكروت التابعة</div>
    <?php foreach ($cards as $c): ?><a class="oc-rec" href="<?= url('office/'.$c['id']) ?>"><span><?= e($c['name']) ?></span><span class="dbt-sub"><?= e($c['owner'] ?: '') ?><?= $c['status']==='inactive'?' · متوقف':'' ?></span></a><?php endforeach; ?>
    <?php if (!$cards): ?><div class="empty">لا توجد كروت على الحساب.</div><?php endif; ?>
  </div>
</div>

<div class="card mb oc-pad" id="entry"><div class="section-head oc-sec"><?= icon('plus',17) ?> حركة حساب يدوية</div>
  <?= formErrorSummary($errors) ?>
  <?php if ($account['status']==='inactive'): ?><div class="flash warning">الحساب متوقف؛ فعّله من التعديل قبل تسجيل حركة جديدة.</div><?php else: ?>
  <form method="post" action="<?= url('office/accounts/'.$account['id'].'/entry') ?>" class="js-dirty-form p5-row end">
    <?= csrfField() ?><?= formStateFields($st['token'],$st['back']) ?>
    <div class="field flush"><label>النوع</label><select name="kind"><option value="deposit" <?= ($old['kind']??'')!=='withdraw'?'selected':'' ?>>إيداع (+)</option><option value="withdraw" <?= ($old['kind']??'')==='withdraw'?'selected':'' ?>>سحب (−)</option></select></div>
    <div class="field flush"><label>التاريخ</label><input type="date" name="dep_date" value="<?= e($old['dep_date']??work_date()) ?>"></div>
    <div class="field flush grow"><label>البيان</label><input name="note" value="<?= e($old['note']??'') ?>"></div>
    <div class="field flush"><label>المبلغ</label><input data-money type="number" min="0.01" step="0.01" name="amount" value="<?= e($old['amount']??'') ?>" required<?= fieldErrorAttrs('amount',$errors) ?>><?= fieldError('amount',$errors) ?></div>
    <button class="btn gold" data-primary-submit>تسجيل الحركة</button>
  </form><?php endif; ?>
</div>

<div class="card"><div class="section-head oc-sec-in"><?= icon('layers',17) ?> دفتر الحساب</div><div class="table-wrap"><table class="data">
  <thead><tr><th>التاريخ</th><th>الحركة</th><th>الكارت</th><th>الطرف / البيان</th><th>داخل</th><th>خارج</th><th>الرصيد</th><th></th></tr></thead><tbody>
  <?php foreach ($ledger as $r): ?><tr>
    <td><?= $r['d']?e(dfmt($r['d'])):'—' ?></td><td><?= e($r['label']) ?></td><td><?= e($r['card'] ?: '—') ?></td><td class="txt"><?= e($r['party'] ?: '—') ?></td>
    <td class="pos"><?= $r['amount']>0?money($r['amount'],false):'—' ?></td><td class="neg"><?= $r['amount']<0?money(-$r['amount'],false):'—' ?></td><td class="mv-ref"><?= money($r['running'],false) ?></td>
    <td><?php if ($r['kind']==='entry' && empty($r['linked'])): ?><form method="post" action="<?= url('office/accounts/entry/'.$r['id'].'/delete') ?>" data-danger="1" data-confirm="حذف الحركة؟"><?= csrfField() ?><?= formStateFields(FormState::token(),current_app_path()) ?><button class="edit oc-btn-bare"><?= icon('trash',14) ?></button></form><?php elseif (!empty($r['url'])): ?><a class="edit" href="<?= e($r['url']) ?>"><?= icon('search',14) ?></a><?php endif; ?></td>
  </tr><?php endforeach; ?>
  <?php if (!$ledger): ?><tr><td colspan="8"><div class="empty">لا توجد حركات بعد.</div></td></tr><?php endif; ?>
  </tbody></table></div></div>
