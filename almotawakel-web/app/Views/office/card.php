<?php use App\Models\VisaCard; use App\Models\Expense; use App\Core\FormState; ?>
<div class="page-actions">
  <a class="btn ghost" href="<?= url('office') ?>">‹ الحسابات والكروت</a>
  <?php if ($account): ?><a class="btn gold" href="<?= url('office/accounts/'.$account['id']) ?>"><?= icon('landmark',14) ?> فتح الحساب</a><?php endif; ?>
  <a class="btn ghost" href="<?= url('office/'.$card['id'].'/statement') ?>" target="_blank"><?= icon('receipt',14) ?> كشف نشاط</a>
  <a class="btn ghost" href="<?= url('office/'.$card['id'].'/edit') ?>"><?= icon('edit',14) ?> تعديل</a>
  <form method="post" action="<?= url('office/'.$card['id'].'/delete') ?>" data-danger="1" data-confirm="حذف الكارت غير المستخدم؟">
    <?= csrfField() ?><?= formStateFields(FormState::token(),'office') ?>
    <button class="btn ghost danger" type="submit"><?= icon('trash',14) ?> حذف</button>
  </form>
</div>

<div class="two-col mb">
  <div class="kpi oc-hero gold"><div class="lbl">رصيد الحساب المشترك</div>
    <div class="val oc-hero-val"><?= intf($balance) ?></div>
    <div class="oc-hero-meta"><?= icon('landmark',14) ?> <?= e($card['account_name'] ?? '—') ?> — الرصيد مش خاص بالكارت وحده</div>
  </div>
  <div class="card oc-pad"><div class="section-head oc-sec"><?= icon('credit-card',17) ?> بيانات الكارت</div>
    <p><b><?= e($card['name']) ?></b><?= $card['owner']?' — '.e($card['owner']):'' ?></p>
    <p class="dbt-sub"><?= $card['status']==='inactive'?'الكارت متوقف':'الكارت نشط' ?> · الحساب <?= ($card['account_status']??'active')==='inactive'?'متوقف':'نشط' ?></p>
  </div>
</div>

<div class="card mb oc-pad">
  <div class="section-head oc-sec"><?= icon('bar-chart',17) ?> صرف هذا الكارت</div>
  <div class="oc-stats">
    <div class="oc-stat"><div class="dbt-sub">عدد العمليات</div><div class="oc-num"><?= intf($stats['count']) ?></div></div>
    <div class="oc-stat"><div class="dbt-sub">إجمالي المصروف</div><div class="neg oc-num"><?= money($stats['total'],false) ?></div></div>
    <div class="oc-stat"><div class="dbt-sub">أكبر عملية</div><div class="oc-num"><?= money($stats['max'],false) ?></div></div>
    <div class="oc-stat"><div class="dbt-sub">آخر نشاط</div><div class="oc-num sm"><?= $stats['last']?e(dfmt(substr((string)$stats['last'],0,10))):'—' ?></div></div>
  </div>
</div>

<div class="card"><div class="section-head oc-sec-in"><?= icon('layers',17) ?> العمليات المنسوبة للكارت</div>
  <div class="table-wrap"><table class="data"><thead><tr><th>التاريخ</th><th>المصدر</th><th>البيان</th><th>الطرف</th><th>المبلغ</th><th></th></tr></thead><tbody>
  <?php foreach ($payments as $p): ?><tr>
    <td><?= $p['d']?e(dfmt($p['d'])):'—' ?></td>
    <td><span class="pill st-partial"><?= e(VisaCard::SOURCES[$p['source']] ?? $p['source']) ?></span></td>
    <td class="txt"><?= e($p['category']) ?><?= $p['note']?'<div class="dbt-sub">'.e($p['note']).'</div>':'' ?></td>
    <td><?= e($p['party'] ?: '—') ?></td><td class="neg"><?= money($p['amount'],false) ?></td>
    <td><?php $u=Expense::originUrl($p['source'],$p['ref_id']); if ($u): ?><a class="edit" href="<?= e($u) ?>"><?= icon('search',14) ?></a><?php endif; ?></td>
  </tr><?php endforeach; ?>
  <?php if (!$payments): ?><tr><td colspan="6"><div class="empty">لا توجد عمليات على الكارت.</div></td></tr><?php endif; ?>
  </tbody></table></div>
</div>
