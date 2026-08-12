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
    <span class="role"><?= icon('building', 14) ?> شركة — تُصدَر الأذونات لصالحها</span>
    <h1><?= e($company['name']) ?></h1>
    <p>يمثّلها <b><?= intf(count($agents)) ?></b> وكيل من عملائنا.</p>
  </div>
  <div class="dir-hero-spacer"></div>
</div>

<div class="stat-strip mb">
  <div class="stat-item"><div class="si-ic gold"><?= icon('user', 14) ?></div><div><b><?= intf(count($agents)) ?></b><span>الوكلاء الممثّلون</span></div></div>
  <div class="stat-item"><div class="si-ic navy"><?= icon('folder', 14) ?></div><div><b><?= intf($p['cnt']) ?></b><span><?= intf($p['open_cnt']) ?> جارٍ · <?= intf($p['closed_cnt']) ?> مقفول</span></div></div>
  <div class="stat-item"><div class="si-ic teal"><?= icon('receipt', 14) ?></div><div><b><?= intf($inv['cnt']) ?></b><span>فواتير — <?= money($inv['total'],false) ?></span></div></div>
</div>

<div class="card dag-pad-lg mb">
  <div class="section-head"><?= icon('user', 17) ?> الوكلاء الذين يمثّلون هذه الشركة <span class="dag-mut">(<?= count($agents) ?>)</span></div>
  <?php if($agents): ?>
    <div class="co-grid">
      <?php foreach($agents as $a): ?>
        <?php /* ⚠️ الوكيل بقى له وحدته (v93) — اللينك على طول بدل ما يعدّي بتحويل. */ ?>
        <div class="co-card agent" onclick="location.href='<?= url('clients/'.$a['id']) ?>'">
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

<div class="card mb">
  <div class="section-head dag-head-p"><?= icon('folder', 17) ?> الأذونات الصادرة لصالح هذه الشركة <span class="dag-mut">(<?= count($permits) ?>)</span></div>
  <div class="table-wrap"><table class="data">
    <thead><tr>
      <th class="g-identity">النوع</th><th class="g-identity">الرقم</th><th class="g-identity">التاريخ</th>
      <th class="g-identity">الوكيل</th><th class="g-identity">الجهة</th><th class="g-identity">الحالة</th>
    </tr></thead>
    <tbody>
      <?php if(!$permits): ?>
        <tr><td colspan="6"><div class="empty-soft">لا أذونات مسجّلة لصالح هذه الشركة.</div></td></tr>
      <?php else: foreach($permits as $r): ?>
        <tr onclick="location.href='<?= url('permits/'.$r['id']) ?>'" class="pointer">
          <td><span class="pill pill-<?= e($r['permit_type']) ?>"><?= $r['permit_type']==='warid'?'شهادة':'إذن' ?></span></td>
          <td class="dbt-tot"><?= e($r['permit_no'] ?: '#'.$r['id']) ?></td>
          <td><?= dfmt($r['permit_date']) ?></td>
          <td class="txt"><?= e($r['client'] ?: '—') ?></td>
          <td class="txt"><?= e($r['destination'] ?: '—') ?></td>
          <td><span class="pill <?= $r['status']==='closed'?'st-paid':'st-partial' ?>"><?= $r['status']==='closed'?'مقفول':'جارٍ' ?></span></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<?php if($invoices): ?>
<div class="card">
  <div class="section-head dag-head-p"><?= icon('receipt', 17) ?> فواتير شامل <span class="dag-mut">(<?= count($invoices) ?>)</span></div>
  <div class="table-wrap"><table class="data">
    <thead><tr>
      <th class="g-identity">التاريخ</th><th class="g-identity">رقم الفاتورة</th><th class="g-identity">الوكيل</th>
      <th class="g-revenue">إجمالي الفاتورة</th><th class="g-revenue">الدفعات</th><th class="g-result">إجمالي الحساب</th>
    </tr></thead>
    <tbody>
      <?php foreach($invoices as $r): ?>
        <tr>
          <td><?= dfmt($r['invoice_date']) ?></td>
          <td class="dbt-tot"><?= e($r['invoice_no'] ?: '#'.$r['id']) ?></td>
          <td class="txt"><?= e($r['client'] ?: '—') ?></td>
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
<div class="card mt-4">
  <div class="section-head dag-head-p"><?= icon('receipt', 17) ?> فواتير الأذونات
    <span class="dag-mut">(<?= count($pinv) ?> — إجمالي <?= money($permitInvSum['total'] ?? 0,false) ?>)</span></div>
  <div class="table-wrap"><table class="data">
    <thead><tr>
      <th class="g-identity">رقم المستند</th><th class="g-identity">النوع</th><th class="g-identity">التاريخ</th>
      <th class="g-identity">العميل</th><th class="g-identity">الإذن</th>
      <th class="g-revenue">الإجمالي</th><th class="g-identity"></th>
    </tr></thead>
    <tbody>
      <?php foreach($pinv as $iv): ?>
        <tr>
          <td class="dbt-tot"><?= e($iv['doc_no'] ?: '#'.$iv['id']) ?></td>
          <td><span class="pill <?= $iv['doc_kind']==='statement'?'st-partial':'st-paid' ?>"><?= e(\App\Models\PermitInvoice::kindLabel($iv['doc_kind'])) ?><?= $iv['taxable']?' • ضريبية':'' ?></span></td>
          <td><?= dfmt($iv['invoice_date']) ?></td>
          <td><?= e($iv['client'] ?: '—') ?></td>
          <td><?php if($iv['permit_id']): ?><a href="<?= url('permits/'.$iv['permit_id']) ?>"><?= e($iv['permit_no'] ?: '#'.$iv['permit_id']) ?></a><?php else: ?><?= e($iv['permit_no'] ?: '—') ?><?php endif; ?></td>
          <td class="pos dbt-tot"><?= money($iv['total'],false) ?></td>
          <td><a class="edit" href="<?= url('permits/invoice/'.$iv['id']) ?>" target="_blank" title="طباعة"><?= icon('file-text', 14) ?></a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php /* مستندات الطرف (v56) — جزء مشترك مع بروفايل الوكيل */ ?>
<?php include __DIR__ . '/_docs.php'; ?>
