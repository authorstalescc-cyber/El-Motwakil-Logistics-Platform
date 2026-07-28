<?php /* إعادة تعبئة نموذج التحصيل بعد الرفض (المرحلة ٢). */
$payOld = $payState['old'] ?? []; $payErr = $payState['errors']['_form'] ?? ''; ?>
<?php if ($payErr !== ''): ?><div class="flash error" role="alert"><?= icon('alert',18) ?> <?= e($payErr) ?> — اللي كتبته زي ما هو.</div><?php endif; ?>
<?php
/** @var array $agent @var array $companies @var array $summary
 *  @var array $permits @var array $invoices @var ?array $balance
 *  @var array $payments @var array $statement @var array $visaCards @var array $permitInvSum
 *  @var array $invoicedPermitIds */
$invoicedPermitIds = $invoicedPermitIds ?? [];
$p = $summary['permits']; $inv = $summary['invoices'];
$bal = $balance ? (float)$balance['balance'] : null;
$st  = $statement;
$paidCash = 0.0; $paidVisa = 0.0; $paidNone = 0.0;
foreach ($payments as $pm) {
  if ($pm['route']==='cash') $paidCash += (float)$pm['amount'];
  elseif ($pm['route']==='visa') $paidVisa += (float)$pm['amount'];
  else $paidNone += (float)$pm['amount'];
}
$cardName = [];
foreach ($visaCards as $vc) { $cardName[(int)$vc['id']] = $vc['name'].($vc['owner']?' — '.$vc['owner']:''); }
?>
<div class="dir-toolbar">
  <a class="btn ghost sm" href="<?= url('directory?tab=agents') ?>">‹ رجوع لقائمة الوكلاء</a>
</div>

<div class="dir-hero">
  <div class="dir-hero-avatar"><?= e(initials($agent['name'])) ?></div>
  <div class="dir-hero-info">
    <span class="role"><?= icon('user', 14) ?> وكيل — عميلنا الفعلي</span>
    <h1><?= e($agent['name']) ?></h1>
    <p>ينوب عن <b><?= intf(count($companies)) ?></b> شركة، وتُصدَر الأذونات لصالحها.</p>
  </div>
  <div class="dir-hero-spacer"></div>
  <div class="dir-hero-bal">
    <span>المتبقّي على العميل</span>
    <b class="<?= $st['remaining']>0.005?'neg':($st['remaining']<-0.005?'pos':'') ?>"><?= money($st['remaining'],false) ?></b>
    <small>جنيه</small>
  </div>
</div>

<div class="stat-strip mb">
  <div class="stat-item"><div class="si-ic teal"><?= icon('building', 14) ?></div><div><b><?= intf(count($companies)) ?></b><span>الشركات التابعة</span></div></div>
  <div class="stat-item"><div class="si-ic navy"><?= icon('folder', 14) ?></div><div><b><?= intf($p['cnt']) ?></b><span><?= intf($p['open_cnt']) ?> جارٍ · <?= intf($p['closed_cnt']) ?> مقفول</span></div></div>
  <div class="stat-item"><div class="si-ic gold"><?= icon('receipt', 14) ?></div><div><b><?= money($st['charges'],false) ?></b><span>إجمالي المطلوب (فواتير)</span></div></div>
  <div class="stat-item"><div class="si-ic green"><?= icon('banknote', 14) ?></div><div><b><?= money($st['paid'],false) ?></b><span>إجمالي المدفوع</span></div></div>
</div>

<!-- كشف حساب العميل -->
<div class="card cp-wrap mb" style="padding:18px 20px">
  <div class="section-head" style="margin:0 0 14px"><?= icon('bar-chart', 17) ?> كشف حساب العميل</div>
  <div class="cp-stmt">
    <div class="cp-stmt-cell">
      <span>المطلوب من العميل</span>
      <b><?= money($st['charges'],false) ?></b>
      <small>فواتير الأذونات<?= $inv['cnt']?' + شامل':'' ?></small>
    </div>
    <div class="cp-op">−</div>
    <div class="cp-stmt-cell">
      <span>المحصّل من العميل</span>
      <b class="pos"><?= money($st['paid'],false) ?></b>
      <small><?= intf(count($payments)) ?> دفعة</small>
    </div>
    <div class="cp-op">=</div>
    <div class="cp-stmt-cell cp-result <?= $st['remaining']>0.005?'is-due':($st['remaining']<-0.005?'is-credit':'is-zero') ?>">
      <span>المتبقّي عليه</span>
      <b><?= money($st['remaining'],false) ?></b>
      <small><?= $st['remaining']>0.005?'مطلوب سداده':($st['remaining']<-0.005?'دفع بالزيادة':'الحساب صفر') ?></small>
    </div>
  </div>
</div>

<!-- التحصيلات + التخصيص والتوجيه للعهدة -->
<div class="card cp-wrap mb">
  <div class="section-head" style="padding:18px 20px 0;margin:0"><?= icon('credit-card', 17) ?> تحصيلات العميل
    <span style="color:var(--grey);font-weight:400">(<?= count($payments) ?>)</span></div>

  <div class="cp-form-box">
    <form method="post" action="<?= url('directory/agent/'.$agent['id'].'/pay') ?>" id="cpForm">
      <?= csrfField() ?>
      <?php /* رمز النموذج يمنع تسجيل نفس دفعة السداد مرتين — والدفعة الموجَّهة
               «كاش» بتنشئ إضافة عهدة، فتكرارها بيزوّد الخزنة بمبلغ ما دخلش.
               ⚠️ شيله = `claimForm` في الكنترولر هترفض كل حفظ. */ ?>
      <?= formStateFields($payState['token'], $payState['back']) ?>
      <div class="cp-form-grid">
        <div class="field" style="margin:0;width:150px">
          <label>التاريخ</label>
          <input type="date" name="pay_date" value="<?= e($payOld['pay_date'] ?? work_date()) ?>">
        </div>
        <div class="field" style="margin:0;width:150px">
          <label>المبلغ *</label>
          <input type="number" step="0.01" min="0" name="amount" value="<?= e($payOld['amount'] ?? '') ?>" placeholder="0" required>
        </div>
        <div class="field" style="margin:0;flex:1;min-width:160px">
          <label>البيان (اختياري)</label>
          <input type="text" name="note" value="<?= e($payOld['note'] ?? '') ?>" placeholder="ملاحظة على الدفعة">
        </div>
      </div>
      <div class="cp-route">
        <span class="cp-route-lbl">توجيه الدفعة:</span>
        <label class="cp-route-opt"><input type="radio" name="route" value="none" checked onchange="cpRoute()"> <span>بدون توجيه</span></label>
        <label class="cp-route-opt"><input type="radio" name="route" value="cash" onchange="cpRoute()"> <span><?= icon('banknote', 14) ?> عهدة نقدية (تُضاف لعهدة اليوم)</span></label>
        <label class="cp-route-opt"><input type="radio" name="route" value="visa" onchange="cpRoute()"> <span><?= icon('credit-card', 14) ?> شحن كارت فيزا</span></label>
        <span id="cpVisaBox" class="cp-visa-box" style="display:none"><?= visa_card_select() ?></span>
      </div>
      <div class="cp-form-actions">
        <label class="cp-route-opt"><input type="checkbox" name="auto_allocate" value="1" checked> تخصيص على أقدم الفواتير المستحقة</label>
        <button class="btn gold" type="submit">＋ تسجيل التحصيل</button>
        <span class="cp-hint">الدفعة الموجّهة «نقدي» تدخل التقفيل اليومي، و«فيزا» تشحن رصيد الكارت.</span>
      </div>
    </form>
  </div>

  <div class="table-wrap"><table class="data">
    <thead><tr>
      <th class="g-identity">التاريخ</th><th class="g-identity">المبلغ</th>
      <th class="g-identity">التوجيه</th><th class="g-identity">غير مخصص</th><th class="g-identity">الحالة</th><th class="g-identity">البيان</th><th class="g-identity"></th>
    </tr></thead>
    <tbody>
      <?php if(!$payments): ?>
        <tr><td colspan="7"><div class="empty-soft">لا تحصيلات مسجّلة لهذا العميل بعد.</div></td></tr>
      <?php else: foreach($payments as $pm): ?>
        <tr>
          <td><?= $pm['pay_date'] ? e(dfmt($pm['pay_date'])) : '—' ?></td>
          <td class="pos" style="font-weight:600"><?= money($pm['amount'],false) ?></td>
          <td>
            <?php if($pm['route']==='cash'): ?>
              <span class="pill st-paid"><?= icon('banknote', 14) ?> عهدة نقدية</span>
            <?php elseif($pm['route']==='visa'): ?>
              <span class="pill st-partial"><?= icon('credit-card', 14) ?> <?= e($cardName[(int)$pm['visa_card_id']] ?? 'فيزا') ?></span>
            <?php else: ?>
              <span class="pill" style="background:var(--bg);color:var(--grey)">بدون توجيه</span>
            <?php endif; ?>
          </td>
          <td><?= $pm['status']==='posted'?money($pm['unallocated_amount'],false):'—' ?></td>
          <td><span class="pill <?= $pm['status']==='reversed'?'st-unpaid':'st-paid' ?>"><?= $pm['status']==='reversed'?'معكوس':'مرحّل' ?></span></td>
          <td style="text-align:right"><?= e($pm['note'] ?: '—') ?></td>
          <td style="white-space:nowrap">
            <?php if($pm['status']==='posted'): ?><form method="post" action="<?= url('directory/agent/'.$agent['id'].'/payment/'.$pm['id'].'/delete') ?>"
                  style="display:inline" data-danger="1" data-confirm="عكس هذا التحصيل مع بقاء سجله؟">
              <?= csrfField() ?>
              <input name="reason" required placeholder="سبب العكس" style="width:100px"><button class="edit" type="submit" title="عكس" style="background:none;border:0;cursor:pointer"><?= icon('undo', 14) ?></button>
            </form><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
    <?php if($payments): ?>
    <tfoot><tr>
      <td style="background:var(--gold-light);font-weight:600;text-align:left">الإجمالي</td>
      <td style="background:var(--gold-light);font-weight:600"><?= money($st['paid'],false) ?></td>
      <td colspan="5" style="background:var(--gold-light);font-weight:400;text-align:right;color:var(--grey)">
        نقدي <?= money($paidCash,false) ?> · فيزا <?= money($paidVisa,false) ?> · بدون توجيه <?= money($paidNone,false) ?>
      </td>
    </tr></tfoot>
    <?php endif; ?>
  </table></div>
</div>

<div class="card" style="padding:20px;margin-bottom:16px">
  <div class="section-head"><?= icon('home', 17) ?> الشركات التي يمثّلها هذا الوكيل <span style="color:var(--grey);font-weight:400">(<?= count($companies) ?>)</span></div>
  <?php if($companies): ?>
    <div class="co-grid">
      <?php foreach($companies as $c): ?>
        <div class="co-card" onclick="location.href='<?= url('directory/company/'.$c['id']) ?>'">
          <div class="co-ava"><?= e(initials($c['name'])) ?></div>
          <b title="<?= e($c['name']) ?>"><?= e($c['name']) ?></b>
          <span class="chev">←</span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="empty-soft">عميل مباشر — لا توجد شركات مرتبطة.</div>
  <?php endif; ?>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="section-head" style="padding:18px 20px 0"><?= icon('folder', 17) ?> الأذونات لصالح شركات هذا الوكيل <span style="color:var(--grey);font-weight:400">(<?= count($permits) ?>)</span></div>
  <div class="table-wrap"><table class="data">
    <thead><tr>
      <th class="g-identity">النوع</th><th class="g-identity">الرقم</th><th class="g-identity">التاريخ</th>
      <th class="g-identity">لصالح شركة</th><th class="g-identity">الجهة</th><th class="g-identity">الحالة</th>
    </tr></thead>
    <tbody>
      <?php if(!$permits): ?>
        <tr><td colspan="6"><div class="empty-soft">لا أذونات مسجّلة باسم هذا الوكيل.</div></td></tr>
      <?php else: foreach($permits as $r): $invoiced = isset($invoicedPermitIds[(int)$r['id']]); ?>
        <tr onclick="location.href='<?= url('permits/'.$r['id']) ?>'" style="cursor:pointer" class="<?= $invoiced?'perm-invoiced':'' ?>">
          <td><span class="pill pill-<?= e($r['permit_type']) ?>"><?= $r['permit_type']==='warid'?'شهادة':'إذن' ?></span></td>
          <td style="font-weight:600"><?= e($r['permit_no'] ?: '#'.$r['id']) ?>
            <?php if($invoiced): ?><span class="perm-inv-badge"><?= icon('receipt', 14) ?> مصدر له فاتورة</span><?php endif; ?>
          </td>
          <td><?= dfmt($r['permit_date']) ?></td>
          <td style="text-align:right"><?= e($r['company'] ?: '—') ?></td>
          <td style="text-align:right"><?= e($r['destination'] ?: '—') ?></td>
          <td><span class="pill <?= $r['status']==='closed'?'st-paid':'st-partial' ?>"><?= $r['status']==='closed'?'مقفول':'جارٍ' ?></span></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<?php if($invoices): ?>
<div class="card">
  <div class="section-head" style="padding:18px 20px 0"><?= icon('receipt', 17) ?> فواتير شامل <span style="color:var(--grey);font-weight:400">(<?= count($invoices) ?>)</span></div>
  <div class="table-wrap"><table class="data">
    <thead><tr>
      <th class="g-identity">التاريخ</th><th class="g-identity">رقم الفاتورة</th><th class="g-identity">لصالح شركة</th>
      <th class="g-revenue">إجمالي الفاتورة</th><th class="g-revenue">الدفعات</th><th class="g-result">إجمالي الحساب</th>
    </tr></thead>
    <tbody>
      <?php foreach($invoices as $r): ?>
        <tr>
          <td><?= dfmt($r['invoice_date']) ?></td>
          <td style="font-weight:600"><?= e($r['invoice_no'] ?: '#'.$r['id']) ?></td>
          <td style="text-align:right"><?= e($r['company'] ?: '—') ?></td>
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
    <span style="color:var(--grey);font-weight:400">(<?= count($pinv) ?> — إجمالي <?= money($permitInvSum['total'] ?? 0,false) ?>)</span></div>
  <div class="table-wrap"><table class="data">
    <thead><tr>
      <th class="g-identity">رقم المستند</th><th class="g-identity">النوع</th><th class="g-identity">التاريخ</th>
      <th class="g-identity">الإذن</th><th class="g-identity">الرقم الداخلي</th>
      <th class="g-revenue">الإجمالي</th><th class="g-identity"></th>
    </tr></thead>
    <tbody>
      <?php foreach($pinv as $iv): ?>
        <tr>
          <td style="font-weight:600"><?= e($iv['doc_no'] ?: '#'.$iv['id']) ?></td>
          <td><span class="pill <?= $iv['doc_kind']==='statement'?'st-partial':'st-paid' ?>"><?= e(\App\Models\PermitInvoice::kindLabel($iv['doc_kind'])) ?><?= $iv['taxable']?' • ضريبية':'' ?></span></td>
          <td><?= dfmt($iv['invoice_date']) ?></td>
          <td><?php if($iv['permit_id']): ?><a href="<?= url('permits/'.$iv['permit_id']) ?>"><?= e($iv['permit_no'] ?: '#'.$iv['permit_id']) ?></a><?php else: ?><?= e($iv['permit_no'] ?: '—') ?><?php endif; ?></td>
          <td><?= e($iv['internal_no'] ?: '—') ?></td>
          <td class="pos" style="font-weight:600"><?= money($iv['total'],false) ?></td>
          <td><a class="edit" href="<?= url('permits/invoice/'.$iv['id']) ?>" target="_blank" title="طباعة"><?= icon('file-text', 14) ?></a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php /* مستندات الطرف (v56) — جزء مشترك مع بروفايل الشركة */ ?>
<?php include __DIR__ . '/_docs.php'; ?>

<style>
/* بروفايل العميل — كشف الحساب والدفعات */
.dir-hero-bal{display:flex;flex-direction:column;align-items:center;justify-content:center;
  background:var(--navy-dark);border-radius:var(--radius-2);padding:14px 22px;min-width:150px}
.dir-hero-bal span{font-size:var(--fs-1);color:#cdd6e2;font-weight:500}
.dir-hero-bal b{font-size:var(--fs-7);font-weight:600;color:#fff;line-height:1.1;margin:2px 0}
.dir-hero-bal b.neg{color:#ffb4b4}.dir-hero-bal b.pos{color:#a9e6bd}
.dir-hero-bal small{font-size:var(--fs-1);color:#9fb0c4}
.cp-stmt{display:flex;align-items:stretch;gap:12px;flex-wrap:wrap}
.cp-stmt-cell{flex:1;min-width:170px;background:var(--bg);border:1px solid var(--line2);border-radius:var(--radius-2);
  padding:14px 16px;display:flex;flex-direction:column;gap:3px}
.cp-stmt-cell span{font-size:var(--fs-2);color:var(--grey);font-weight:500}
.cp-stmt-cell b{font-size:var(--fs-6);font-weight:600;color:var(--navy)}
.cp-stmt-cell small{font-size:var(--fs-1);color:var(--grey)}
.cp-op{display:flex;align-items:center;font-size:var(--fs-6);font-weight:600;color:var(--grey)}
.cp-result.is-due{background:var(--red-lt);border-color:#e6b8b8}.cp-result.is-due b{color:var(--red)}
.cp-result.is-credit{background:var(--green-lt);border-color:#bfe0c9}.cp-result.is-credit b{color:var(--green)}
.cp-result.is-zero{background:var(--gold-light);border-color:#e6d9a8}
.cp-form-box{padding:14px 20px 18px;border-bottom:1px solid var(--line2);background:linear-gradient(180deg,#fafbfd,#fff)}
.cp-form-grid{display:flex;flex-wrap:wrap;gap:12px;align-items:end;margin-bottom:12px}
.cp-route{display:flex;flex-wrap:wrap;align-items:center;gap:14px;margin-bottom:12px}
.cp-route-lbl{font-weight:600;color:var(--navy);font-size:var(--fs-3)}
.cp-route-opt{display:flex;align-items:center;gap:6px;font-size:var(--fs-2);font-weight:400;cursor:pointer}
.cp-visa-box{display:flex;align-items:center}
.cp-visa-box select{padding:7px 10px;border:1px solid var(--line2);border-radius:var(--radius-1);font-family:inherit}
.cp-form-actions{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.cp-hint{font-size:var(--fs-1);color:var(--grey)}
/* إذن صُدِرت له فاتورة — تمييز بلون */
tr.perm-invoiced td{background:var(--green-lt)!important}
tr.perm-invoiced:hover td{background:#d5ecdd!important}
tr.perm-invoiced td:first-child{box-shadow:inset 3px 0 0 var(--green)}
.perm-inv-badge{display:inline-block;margin-inline-start:6px;font-size:var(--fs-1);font-weight:600;
  color:var(--green);background:#fff;border:1px solid #bfe0c9;border-radius:var(--radius-4);padding:1px 8px;vertical-align:middle}
@media(max-width:640px){.cp-op{display:none}}
</style>
<script>
function cpRoute(){
  var v=document.querySelector('#cpForm input[name=route]:checked');
  document.getElementById('cpVisaBox').style.display=(v&&v.value==='visa')?'flex':'none';
}
</script>
