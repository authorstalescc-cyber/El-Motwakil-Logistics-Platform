<?php
use App\Core\FormState;
use App\Models\PermitInvoice;
/** @var array $rows @var array $sums */
// رابط العودة لنفس السياق (الفلترة/الصفحة) بعد الحذف
$backQs  = http_build_query(array_filter(['q'=>$q,'kind'=>$kind,'tax'=>$tax,'status'=>$status,'from'=>$from,'to'=>$to,'page'=>$page]));
$backUrl = 'invoices' . ($backQs ? '?'.$backQs : '');
$bulkState = $bulkState ?? ['token'=>FormState::token(),'old'=>[],'errors'=>[],'back'=>$backUrl];
$voidState = $voidState ?? ['token'=>FormState::token(),'old'=>[],'errors'=>[],'back'=>$backUrl];
$voidOldId = (int)($voidState['old']['_void_id'] ?? 0);
?>
<div class="page-head">
  <div><h1 ><?= icon('receipt', 14) ?> إصدار الفواتير</h1></div>
  <a class="btn gold" href="<?= url('invoices/new') ?>"><?= icon('plus', 14) ?> إصدار فاتورة جديدة</a>
</div>

<!-- بطاقات ملخّص -->
<div class="stat-row mb">
  <div class="stat-item"><div class="si-ic navy"><?= icon('receipt', 14) ?></div><div><b><?= intf($total) ?></b><span>إجمالي المستندات</span></div></div>
  <div class="stat-item"><div class="si-ic gold"><?= icon('file-text', 14) ?></div><div><b><?= intf($sums['inv_cnt']) ?></b><span>فواتير</span></div></div>
  <div class="stat-item"><div class="si-ic gold"><?= icon('layers', 14) ?></div><div><b><?= intf($sums['stmt_cnt']) ?></b><span>كشوف حساب</span></div></div>
  <div class="stat-item"><div class="si-ic green"><?= icon('coins', 14) ?></div><div><b><?= money($sums['grand'],false) ?></b><span>إجمالي القيم</span></div></div>
  <div class="stat-item"><div class="si-ic green"><?= icon('calculator', 14) ?></div><div><b><?= money($sums['balance_due'],false) ?></b><span>الرصيد المستحق</span></div></div>
</div>

<!-- فلترة -->
<form class="card mb" method="get" action="<?= url('invoices') ?>" class="p5-pad p5-row end">
  <div><label class="flt-l">بحث</label>
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="بحث"
           class="p5-fin w280"></div>
  <div><label class="flt-l">النوع</label>
    <select name="kind" class="p5-fin">
      <option value="">الكل</option>
      <option value="invoice"   <?= $kind==='invoice'?'selected':'' ?>>فاتورة</option>
      <option value="statement" <?= $kind==='statement'?'selected':'' ?>>كشف حساب</option>
    </select></div>
  <div><label class="flt-l">الضريبة</label>
    <select name="tax" class="p5-fin">
      <option value="">الكل</option>
      <option value="1" <?= $tax==='1'?'selected':'' ?>>ضريبية</option>
      <option value="0" <?= $tax==='0'?'selected':'' ?>>غير ضريبية</option>
    </select></div>
  <div><label class="flt-l">الحالة</label>
    <select name="status" class="p5-fin">
      <option value="">الكل</option>
      <?php foreach(PermitInvoice::STATUSES as $sk=>$sl): ?><option value="<?= e($sk) ?>" <?= $status===$sk?'selected':'' ?>><?= e($sl) ?></option><?php endforeach; ?>
    </select></div>
  <div><label class="flt-l">من</label><input type="date" name="from" value="<?= e($from) ?>" class="p5-fin sq"></div>
  <div><label class="flt-l">إلى</label><input type="date" name="to" value="<?= e($to) ?>" class="p5-fin sq"></div>
  <button class="btn" type="submit"><?= icon('search', 14) ?> بحث</button>
  <?php if($q||$kind||$tax||$status||$from||$to): ?><a class="btn ghost" href="<?= url('invoices') ?>">مسح</a><?php endif; ?>
</form>

<!-- إلغاء جماعي: لا حذف للمستندات المالية الصادرة -->
<?= formErrorSummary($bulkState['errors']) ?>
<form id="bulkForm" class="js-dirty-form" method="post" action="<?= url('invoices/delete') ?>"
      data-danger="1" data-confirm="إلغاء المستندات المحددة مع الاحتفاظ بأرقامها وسجلها؟">
  <?= csrfField() ?>
  <?= formStateFields($bulkState['token'], $bulkState['back']) ?>
  <div class="field p5-ib m8">
    <input type="text" name="reason" value="<?= e($bulkState['old']['reason'] ?? '') ?>" required placeholder="سبب الإلغاء" class="p5-fin sm"<?= fieldErrorAttrs('reason', $bulkState['errors']) ?>>
    <?= fieldError('reason', $bulkState['errors']) ?>
    <?= fieldError('ids', $bulkState['errors']) ?>
  </div>
</form>

<?php if ($voidState['errors']): ?><?= formErrorSummary($voidState['errors']) ?><?php endif; ?>

<!-- الجدول -->
<div class="card" >
  <?php if (!$rows): ?>
    <div class="empty p5-pad-xl"><div class="ic"><?= icon('receipt', 14) ?></div>لا توجد فواتير مطابقة.
      <div class="mt-3"><a class="btn gold" href="<?= url('invoices/new') ?>"><?= icon('plus', 14) ?> إصدار فاتورة جديدة</a></div></div>
  <?php else: ?>
    <!-- شريط الحذف الجماعي -->
    <div class="bulkbar" id="bulkbar">
      <label class="bchk"><input type="checkbox" id="selAll"> تحديد الكل</label>
      <span class="bcount" id="bcount">لم يُحدَّد شيء</span>
      <button type="submit" form="bulkForm" class="btn del-btn" id="bulkBtn" disabled>إلغاء المحدَّد</button>
    </div>
    <div class="table-wrap"><table class="data">
      <thead><tr>
        <th class="col-pick"></th>
        <th class="g-identity">رقم المستند</th><th class="g-identity">النوع</th><th class="g-identity">التاريخ</th>
        <th class="g-identity">العميل</th><th class="g-identity">الحالة</th><th class="g-identity">الاستحقاق</th><th class="g-identity">الإذن</th>
        <th class="g-revenue">الإجمالي</th><th class="g-revenue">المتبقي</th><th class="g-identity"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $iv): ?>
        <tr>
          <td class="center"><input type="checkbox" class="rowchk" name="ids[]" value="<?= (int)$iv['id'] ?>" form="bulkForm"></td>
          <td class="dbt-tot"><?= e($iv['doc_no'] ?: '#'.$iv['id']) ?></td>
          <td><span class="pill <?= $iv['doc_kind']==='statement'?'st-partial':'st-paid' ?>"><?= e(PermitInvoice::kindLabel($iv['doc_kind'])) ?><?= $iv['taxable']?' • ضريبية':'' ?></span></td>
          <td><?= dfmt($iv['invoice_date']) ?></td>
          <td><?= e($iv['client'] ?: '—') ?></td>
          <td><span class="pill <?= $iv['status']==='paid'?'st-paid':($iv['status']==='void'?'st-unpaid':'st-partial') ?>"><?= e(PermitInvoice::statusLabel($iv['status'],$iv['due_date'])) ?></span></td>
          <td><?= $iv['due_date']?dfmt($iv['due_date']):'—' ?></td>
          <td><?php if($iv['permit_id']): ?><a href="<?= url('permits/'.$iv['permit_id']) ?>"><?= e($iv['permit_no'] ?: '#'.$iv['permit_id']) ?></a><?php else: ?>—<?php endif; ?></td>
          <td class="p5-h-inline"><?= money($iv['total'],false) ?></td>
          <td class="dbt-tot <?= (float)$iv['balance_due']>0?'neg':'pos' ?>"><?= money($iv['balance_due'],false) ?></td>
          <td><div class="actions">
            <a class="edit" href="<?= url('permits/invoice/'.$iv['id']) ?>" target="_blank" title="طباعة"><?= icon('file-text', 14) ?></a>
            <a class="edit" href="<?= url('permits/'.$iv['permit_id'].'/invoice') ?>" title="إصدار مستند آخر لنفس الإذن"><?= icon('plus', 14) ?></a>
            <?php if($iv['status']!=='void'): ?><form method="post" action="<?= url('permits/invoice/'.$iv['id'].'/delete') ?>" 
                  data-danger="1" data-confirm="إلغاء هذا المستند؟ سيظل رقمه محفوظاً.">
              <?= csrfField() ?><?= formStateFields($voidState['token'], $voidState['back']) ?><input type="hidden" name="_void_id" value="<?= (int)$iv['id'] ?>"><input type="hidden" name="lock_version" value="<?= (int)$iv['lock_version'] ?>">
              <span class="field p5-ib"><input type="text" name="reason" value="<?= $voidOldId===(int)$iv['id'] ? e($voidState['old']['reason'] ?? '') : '' ?>" required placeholder="سبب الإلغاء" class="w110"<?= $voidOldId===(int)$iv['id'] ? fieldErrorAttrs('reason', $voidState['errors']) : '' ?>><?= $voidOldId===(int)$iv['id'] ? fieldError('reason', $voidState['errors']) : '' ?></span><button class="del" type="submit" data-primary-submit title="إلغاء"><?= icon('x', 14) ?></button>
            </form><?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>

<?php if ($pages > 1): ?>
  <div class="pager">
    <?php for ($i=1;$i<=$pages;$i++):
      $qs = http_build_query(array_filter(['q'=>$q,'kind'=>$kind,'tax'=>$tax,'status'=>$status,'from'=>$from,'to'=>$to,'page'=>$i])); ?>
      <a class="<?= $i===$page?'active':'' ?>" href="<?= url('invoices').'?'.$qs ?>"><?= $i ?></a>
    <?php endfor; ?>
  </div>
<?php endif; ?>

<style>
  .page-head{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:16px;flex-wrap:wrap}
  .stat-row{display:grid;grid-template-columns:repeat(5,1fr);gap:12px}
  .stat-item{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid var(--line);border-radius:var(--radius-2);padding:12px 14px}
  .si-ic{width:40px;height:40px;border-radius:var(--radius-2);display:flex;align-items:center;justify-content:center;font-size:19px;flex:0 0 auto}
  .si-ic.navy{background:#eaf0f8}.si-ic.gold{background:#fff4d9}.si-ic.green{background:#e4f5ec}
  .stat-item b{display:block;font-size:var(--fs-5);color:var(--navy)}.stat-item span{font-size:var(--fs-1);color:var(--grey)}
  .flt-l{display:block;font-size:var(--fs-1);color:var(--grey);font-weight:500;margin-bottom:3px}
  .del{background:none;border:none;cursor:pointer;font-size:var(--fs-4)}
  .bulkbar{display:flex;align-items:center;gap:14px;padding:10px 14px;border-bottom:1px solid var(--line);background:#fafbfd;flex-wrap:wrap}
  .bulkbar .bchk{display:flex;align-items:center;gap:6px;font-weight:500;color:var(--navy);font-size:var(--fs-2);cursor:pointer}
  .bulkbar .bcount{font-size:var(--fs-2);color:var(--grey);font-weight:500}
  .btn.del-btn{background:#fbe7e9;color:#b4383e;border:1px solid #f0c3c7;padding:7px 14px;font-weight:600;border-radius:var(--radius-1);cursor:pointer}
  .btn.del-btn:disabled{opacity:.5;cursor:not-allowed}
  .pager{display:flex;gap:6px;justify-content:center;margin-top:16px;flex-wrap:wrap}
  .pager a{padding:7px 13px;border:1px solid var(--line);border-radius:var(--radius-1);background:#fff;color:var(--navy);font-weight:500;text-decoration:none}
  .pager a.active{background:var(--navy);color:#fff}
  @media(max-width:800px){.stat-row{grid-template-columns:repeat(2,1fr)}}
</style>

<script>
(function(){
  var selAll = document.getElementById('selAll');
  if(!selAll) return;   // لا صفوف
  var chks  = Array.prototype.slice.call(document.querySelectorAll('.rowchk'));
  var count = document.getElementById('bcount');
  var btn   = document.getElementById('bulkBtn');
  function refresh(){
    var n = chks.filter(function(c){ return c.checked; }).length;
    count.textContent = n ? (n + ' مستند محدَّد') : 'لم يُحدَّد شيء';
    btn.disabled = n === 0;
    selAll.checked = n > 0 && n === chks.length;
    selAll.indeterminate = n > 0 && n < chks.length;
  }
  selAll.addEventListener('change', function(){
    chks.forEach(function(c){ c.checked = selAll.checked; });
    refresh();
  });
  chks.forEach(function(c){ c.addEventListener('change', refresh); });
  refresh();
})();
</script>
