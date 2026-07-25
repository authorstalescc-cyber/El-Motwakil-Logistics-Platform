<?php
/** @var array $company @var array $agents @var array $summary
 *  @var array $permits @var array $invoices */
$p = $summary['permits']; $inv = $summary['invoices'];
?>
<div class="dir-toolbar">
  <a class="btn ghost sm" href="<?= url('directory?tab=companies') ?>">‹ رجوع لقائمة الشركات</a>
</div>

<div class="dir-hero company">
  <div class="dir-hero-avatar"><?= e(initials($company['name'])) ?></div>
  <div class="dir-hero-info">
    <span class="role">🏢 شركة — تُصدَر الأذونات لصالحها</span>
    <h1><?= e($company['name']) ?></h1>
    <p>يمثّلها <b><?= intf(count($agents)) ?></b> وكيل من عملائنا.</p>
  </div>
  <div class="dir-hero-spacer"></div>
</div>

<div class="stat-strip mb">
  <div class="stat-item"><div class="si-ic gold">👤</div><div><b><?= intf(count($agents)) ?></b><span>الوكلاء الممثّلون</span></div></div>
  <div class="stat-item"><div class="si-ic navy">🗂️</div><div><b><?= intf($p['cnt']) ?></b><span><?= intf($p['open_cnt']) ?> جارٍ · <?= intf($p['closed_cnt']) ?> مقفول</span></div></div>
  <div class="stat-item"><div class="si-ic teal">🧾</div><div><b><?= intf($inv['cnt']) ?></b><span>فواتير — <?= money($inv['total'],false) ?></span></div></div>
</div>

<div class="card" style="padding:20px;margin-bottom:16px">
  <div class="section-head"><?= icon('user', 17) ?> الوكلاء الذين يمثّلون هذه الشركة <span style="color:var(--grey);font-weight:600">(<?= count($agents) ?>)</span></div>
  <?php if($agents): ?>
    <div class="co-grid">
      <?php foreach($agents as $a): ?>
        <div class="co-card agent" onclick="location.href='<?= url('directory/agent/'.$a['id']) ?>'">
          <div class="co-ava"><?= e(initials($a['name'])) ?></div>
          <b title="<?= e($a['name']) ?>"><?= e($a['name']) ?></b>
          <span class="chev">←</span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="empty-soft">لا يوجد وكيل مرتبط بهذه الشركة.</div>
  <?php endif; ?>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="section-head" style="padding:18px 20px 0"><?= icon('folder', 17) ?> الأذونات الصادرة لصالح هذه الشركة <span style="color:var(--grey);font-weight:600">(<?= count($permits) ?>)</span></div>
  <div class="table-wrap"><table class="data">
    <thead><tr>
      <th class="g-identity">النوع</th><th class="g-identity">الرقم</th><th class="g-identity">التاريخ</th>
      <th class="g-identity">الوكيل</th><th class="g-identity">الجهة</th><th class="g-identity">الحالة</th>
    </tr></thead>
    <tbody>
      <?php if(!$permits): ?>
        <tr><td colspan="6"><div class="empty-soft">لا أذونات مسجّلة لصالح هذه الشركة.</div></td></tr>
      <?php else: foreach($permits as $r): ?>
        <tr onclick="location.href='<?= url('permits/'.$r['id']) ?>'" style="cursor:pointer">
          <td><span class="pill pill-<?= e($r['permit_type']) ?>"><?= $r['permit_type']==='warid'?'شهادة':'إذن' ?></span></td>
          <td style="font-weight:800"><?= e($r['permit_no'] ?: '#'.$r['id']) ?></td>
          <td><?= dfmt($r['permit_date']) ?></td>
          <td style="text-align:right"><?= e($r['client'] ?: '—') ?></td>
          <td style="text-align:right"><?= e($r['destination'] ?: '—') ?></td>
          <td><span class="pill <?= $r['status']==='closed'?'st-paid':'st-partial' ?>"><?= $r['status']==='closed'?'مقفول':'جارٍ' ?></span></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<?php if($invoices): ?>
<div class="card">
  <div class="section-head" style="padding:18px 20px 0"><?= icon('receipt', 17) ?> فواتير شامل <span style="color:var(--grey);font-weight:600">(<?= count($invoices) ?>)</span></div>
  <div class="table-wrap"><table class="data">
    <thead><tr>
      <th class="g-identity">التاريخ</th><th class="g-identity">رقم الفاتورة</th><th class="g-identity">الوكيل</th>
      <th class="g-revenue">إجمالي الفاتورة</th><th class="g-revenue">الدفعات</th><th class="g-result">إجمالي الحساب</th>
    </tr></thead>
    <tbody>
      <?php foreach($invoices as $r): ?>
        <tr>
          <td><?= dfmt($r['invoice_date']) ?></td>
          <td style="font-weight:800"><?= e($r['invoice_no'] ?: '#'.$r['id']) ?></td>
          <td style="text-align:right"><?= e($r['client'] ?: '—') ?></td>
          <td class="pos"><?= money($r['invoice_total'],false) ?></td>
          <td><?= money($r['payments'],false) ?></td>
          <td class="g-result"><?= money($r['account_total'],false) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php $pinv = $permitInvoices ?? []; if($pinv): ?>
<div class="card" style="margin-top:16px">
  <div class="section-head" style="padding:18px 20px 0"><?= icon('receipt', 17) ?> فواتير الأذونات
    <span style="color:var(--grey);font-weight:600">(<?= count($pinv) ?> — إجمالي <?= money($permitInvSum['total'] ?? 0,false) ?>)</span></div>
  <div class="table-wrap"><table class="data">
    <thead><tr>
      <th class="g-identity">رقم المستند</th><th class="g-identity">النوع</th><th class="g-identity">التاريخ</th>
      <th class="g-identity">العميل</th><th class="g-identity">الإذن</th>
      <th class="g-revenue">الإجمالي</th><th class="g-identity"></th>
    </tr></thead>
    <tbody>
      <?php foreach($pinv as $iv): ?>
        <tr>
          <td style="font-weight:800"><?= e($iv['doc_no'] ?: '#'.$iv['id']) ?></td>
          <td><span class="pill <?= $iv['doc_kind']==='statement'?'st-partial':'st-paid' ?>"><?= e(\App\Models\PermitInvoice::kindLabel($iv['doc_kind'])) ?><?= $iv['taxable']?' • ضريبية':'' ?></span></td>
          <td><?= dfmt($iv['invoice_date']) ?></td>
          <td><?= e($iv['client'] ?: '—') ?></td>
          <td><?php if($iv['permit_id']): ?><a href="<?= url('permits/'.$iv['permit_id']) ?>"><?= e($iv['permit_no'] ?: '#'.$iv['permit_id']) ?></a><?php else: ?><?= e($iv['permit_no'] ?: '—') ?><?php endif; ?></td>
          <td class="pos" style="font-weight:800"><?= money($iv['total'],false) ?></td>
          <td><a class="edit" href="<?= url('permits/invoice/'.$iv['id']) ?>" target="_blank" title="طباعة">🖨️</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
