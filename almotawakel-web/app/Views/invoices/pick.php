<?php
use App\Models\Permit;
/** @var array $rows */
?>
<div class="page-head">
  <div><h1 style="margin:0"><?= icon('receipt', 14) ?> إصدار فاتورة — اختر الإذن</h1></div>
  <a class="btn ghost" href="<?= url('invoices') ?>">‹ سجل الفواتير</a>
</div>

<form class="card mb" method="get" action="<?= url('invoices/new') ?>" style="padding:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:end">
  <div><label class="flt-l">بحث في الأذونات</label>
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="بحث" autofocus
           style="width:320px;padding:8px 11px;border:1px solid var(--line);border-radius:8px"></div>
  <button class="btn" type="submit"><?= icon('search', 14) ?> بحث</button>
  <?php if($q): ?><a class="btn ghost" href="<?= url('invoices/new') ?>">مسح</a><?php endif; ?>
</form>

<div class="card" style="padding:0;overflow:hidden">
  <?php if (!$rows): ?>
    <div class="empty" style="padding:40px"><div class="ic"><?= icon('folder', 14) ?></div>لا توجد أذونات مطابقة.</div>
  <?php else: ?>
    <div class="table-wrap"><table class="data">
      <thead><tr>
        <th class="g-identity">النوع</th><th class="g-identity">رقم الإذن/الشهادة</th><th class="g-identity">التاريخ</th>
        <th class="g-identity">العميل</th><th class="g-identity">لصالح شركة</th><th class="g-identity">الجهة</th>
        <th class="g-vehicle">الحاويات</th><th class="g-vehicle">البوالص</th><th class="g-identity">الحالة</th>
        <th class="g-identity">فواتير</th><th class="g-identity"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $p): ?>
        <tr>
          <td><span class="pill <?= $p['permit_type']==='warid'?'st-partial':'st-paid' ?>"><?= $p['permit_type']==='warid'?'وارد':'صادر' ?></span></td>
          <td style="font-weight:600"><a href="<?= url('permits/'.$p['id']) ?>"><?= e($p['permit_no'] ?: '#'.$p['id']) ?></a></td>
          <td><?= dfmt($p['permit_date']) ?></td>
          <td><?= e($p['client'] ?: '—') ?></td>
          <td><?= e($p['company'] ?: '—') ?></td>
          <td><?= e($p['destination'] ?: '—') ?></td>
          <td><?= intf($p['containers'] ?? 0) ?></td>
          <td><?= intf($p['bills']) ?></td>
          <td><span class="pill <?= ($p['status']??'open')==='closed'?'st-paid':'st-partial' ?>"><?= ($p['status']??'open')==='closed'?'مقفول':'جارٍ' ?></span></td>
          <td><?= (int)$p['inv_cnt'] > 0 ? intf($p['inv_cnt']) : '—' ?></td>
          <td><a class="btn gold sm" href="<?= url('permits/'.$p['id'].'/invoice') ?>"><?= icon('receipt', 14) ?> إصدار</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>

<style>
  .page-head{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:16px;flex-wrap:wrap}
  .flt-l{display:block;font-size:var(--fs-1);color:var(--grey);font-weight:500;margin-bottom:3px}
</style>
