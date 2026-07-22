<?php
/**
 * إعداد كشف حساب صاحب البيان: ملخّصه + قائمتا البوالص (مستلَمة/باقية) قابلتان للاختيار.
 * @var string $owner @var array $stats @var array $paidSummary @var array $remaining @var array $allBills @var array $previous
 */
$billNo = fn($b) => ($b['bill_number'] ?? '') ?: (($b['cert_number'] ?? '') ?: '—');
$allBills = $allBills ?? [];
$paidBills = array_values(array_filter($allBills, fn($b) => (float)($b['paid'] ?? 0) > 0.005));
$dueBills = array_values(array_filter($allBills, fn($b) => in_array($b['pay_status'] ?? '', ['unpaid','partial'], true)));
$pendingBills = array_values(array_filter($allBills, fn($b) => ($b['pay_status'] ?? '') === 'pending'));
$drillBack = 'statements/form?owner=' . urlencode($owner);
$statusLabel = fn($b) => \App\Models\Bill::STATUS_LABELS[$b['pay_status'] ?? ''] ?? ($b['pay_status'] ?? '—');
?>
<div class="permit-bar">
  <a class="btn ghost sm" href="<?= url('settlements?owner=' . urlencode($owner)) ?>">‹ رجوع لصاحب البيان</a>
  <span class="pb-title">📑 إصدار كشف حساب — <b><?= e($owner) ?></b></span>
  <a class="btn ghost sm" href="<?= url('statements/form?owner=' . urlencode($owner)) ?>" title="تحديث الأرقام بعد تعديل بوليصة">↻ تحديث البيانات</a>
  <a class="btn ghost sm" href="<?= url('statements') ?>">سجل الكشوف</a>
</div>

<!-- ملخّص صاحب البيان (استلم كام / فاضل كام) -->
<div class="sec-head"><span>📋 ملخّص صاحب البيان</span></div>
<div class="card mb" style="padding:0;overflow:hidden">
  <div class="st-tiles">
    <button type="button" class="stile" data-st-pop="all" data-st-title="كل بوالص <?= e($owner) ?>"><b><?= intf($stats['bills']) ?></b><span>إجمالي البوالص</span><small>اضغط للتفاصيل</small></button>
    <button type="button" class="stile" data-st-pop="paid" data-st-title="البوالص التي سبق الدفع عليها"><b class="pos"><?= money($stats['paid'],false) ?></b><span>استلم (المدفوع له)</span><small>اضغط للتفاصيل</small></button>
    <button type="button" class="stile" data-st-pop="due" data-st-title="البوالص الباقية له"><b class="neg"><?= money($stats['remaining'],false) ?></b><span>الباقي له</span><small>اضغط للتفاصيل</small></button>
  </div>
  <?php if ($stats['first_date'] || $stats['last_date']): ?>
    <button type="button" class="st-range" data-st-pop="all" data-st-title="كل البوالص خلال الفترة">بوالص من <b><?= dfmt($stats['first_date']) ?></b> إلى <b><?= dfmt($stats['last_date']) ?></b> <small>— اضغط للعرض</small></button>
  <?php endif; ?>
  <?php if ((int)($stats['pending_bills'] ?? 0) > 0): ?>
    <button type="button" class="st-pending-note" data-st-pop="pending" data-st-title="بوالص بانتظار إدخال النولون">⏳ <b><?= intf($stats['pending_bills']) ?></b> بوليصة بانتظار إدخال النولون. <small>اضغط للعرض والإضافة السريعة</small></button>
  <?php endif; ?>
</div>

<!-- نافذة drill-down موحدة لكل أرقام الملخّص -->
<div class="d-modal" id="stDrillModal" hidden>
  <div class="d-modal-back" data-st-close></div>
  <div class="d-modal-card st-drill-card" role="dialog" aria-modal="true" aria-labelledby="stDrillTitle">
    <div class="d-modal-head"><h3 id="stDrillTitle"></h3><button type="button" class="d-modal-x" data-st-close aria-label="إغلاق">✕</button></div>
    <div class="d-modal-body" id="stDrillBody"></div>
  </div>
</div>

<div id="stDrillStore" hidden>
  <?php foreach (['all'=>$allBills, 'paid'=>$paidBills, 'due'=>$dueBills] as $popKey=>$popRows): ?>
  <div data-st-template="<?= e($popKey) ?>">
    <div class="d-modal-strip<?= $popKey==='due' ? ' red' : ($popKey==='paid' ? ' gold' : '') ?>">
      <b><?= intf(count($popRows)) ?></b> بوليصة
      <?php if ($popKey==='paid'): ?> · إجمالي المدفوع <b><?= money(array_sum(array_column($popRows,'paid')),false) ?></b><?php endif; ?>
      <?php if ($popKey==='due'): ?> · إجمالي الباقي <b><?= money(array_sum(array_column($popRows,'remaining')),false) ?></b><?php endif; ?>
    </div>
    <?php if (!$popRows): ?><div class="empty" style="padding:28px">لا توجد بوالص في هذه المجموعة.</div><?php else: ?>
    <div class="table-wrap"><table class="data st-drill-table"><thead><tr>
      <th>الرقم</th><th>التاريخ</th><th>الشركة</th><th>الجهة</th><th>التصنيف</th><th>نولون</th><th>عهدة</th><th>استلم</th><th>الباقي</th><th>الحالة</th><th>تعديل</th>
    </tr></thead><tbody><?php foreach ($popRows as $b): ?><tr>
      <td><a href="<?= url('bawalis/'.(int)$b['id']) ?>" style="font-weight:800"><?= e($billNo($b)) ?></a></td>
      <td><?= dfmt($b['bill_date']) ?></td><td><?= e($b['company'] ?: '—') ?></td><td><?= e($b['destination'] ?: '—') ?></td><td><?= e($b['classification'] ?: '—') ?></td>
      <td><?= money($b['freight'],false) ?></td><td><?= money($b['custody'],false) ?></td><td class="pos"><?= money($b['paid'],false) ?></td><td class="<?= (float)$b['remaining']>0.005?'neg':'' ?>"><?= money($b['remaining'],false) ?></td><td><?= e($statusLabel($b)) ?></td>
      <td><a class="btn ghost sm" href="<?= url('bawalis/'.(int)$b['id'].'/edit') ?>" target="_blank" rel="noopener" title="تعديل البوليصة ثم تحديث بيانات الكشف">✏️ تعديل</a></td>
    </tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>

  <div data-st-template="pending">
    <div class="d-modal-strip gold"><b><?= intf(count($pendingBills)) ?></b> بوليصة — أدخل نولون كل بوليصة واحفظها من نفس الصف.</div>
    <?php if (!$pendingBills): ?><div class="empty" style="padding:28px">لا توجد بوالص بانتظار النولون.</div><?php else: ?>
    <div class="table-wrap"><table class="data st-drill-table pending"><thead><tr>
      <th>الرقم</th><th>التاريخ</th><th>الشركة</th><th>الجهة</th><th>التصنيف</th><th>الحاوية</th><th>عهدة</th><th>الخصم</th><th>النولون</th><th></th><th>تعديل</th>
    </tr></thead><tbody><?php foreach ($pendingBills as $b): ?><tr>
      <td><a href="<?= url('bawalis/'.(int)$b['id']) ?>" style="font-weight:800"><?= e($billNo($b)) ?></a></td>
      <td><?= dfmt($b['bill_date']) ?></td><td><?= e($b['company'] ?: '—') ?></td><td><?= e($b['destination'] ?: '—') ?></td><td><?= e($b['classification'] ?: '—') ?></td><td><?= e($b['container_no'] ?: '—') ?></td><td><?= money($b['custody'],false) ?></td><td><?= money($b['discount'],false) ?></td>
      <td colspan="2"><form method="post" action="<?= url('bawalis/bulk-freight') ?>" class="st-quick-freight">
        <?= csrfField() ?><input type="hidden" name="back" value="<?= e($drillBack) ?>">
        <input type="number" name="freight[<?= (int)$b['id'] ?>]" min="0.01" step="0.01" required placeholder="النولون">
        <button type="submit" class="btn gold sm">حفظ النولون</button>
      </form></td>
      <td><a class="btn ghost sm" href="<?= url('bawalis/'.(int)$b['id'].'/edit') ?>" target="_blank" rel="noopener">✏️ تعديل</a></td>
    </tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
  </div>
</div>

<form method="post" action="<?= url('statements') ?>" id="stForm">
  <?= csrfField() ?>
  <?php
  /* اختيار البوالص كان بيضيع كله عند الرفض؛ دلوقتي بيرجع زي ما المستخدم سابه. */
  $fsOld  = $formState['old'] ?? [];
  $fsErr  = $formState['errors']['_form'] ?? '';
  $fsHas  = $fsOld !== [];
  $remPicked  = array_map('intval', (array)($fsOld['remaining_ids'] ?? []));
  $paidPicked = array_map('intval', (array)($fsOld['paid_ids'] ?? []));
  $isPicked = fn(int $id, array $list) => !$fsHas || in_array($id, $list, true);
  ?>
  <?php if ($fsErr !== ''): ?>
    <div class="flash error" role="alert"><?= icon('alert',18) ?> <?= e($fsErr) ?> — اختيارك زي ما هو تحت.</div>
  <?php endif; ?>
  <input type="hidden" name="owner" value="<?= e($owner) ?>">

  <!-- (1) ملخّص البوالص التي سبق الدفع عليها: بند واحد مهما كان عددها -->
  <?php
    $paidCount = (int)($paidSummary['count'] ?? 0);
    $paidTotal = (float)($paidSummary['paid'] ?? 0);
  ?>
  <div class="sec-head"><span>① ملخّص البوالص التي سبق الدفع عليها</span></div>
  <div class="card mb" style="padding:0;overflow:hidden">
    <?php if ($paidCount === 0): ?>
      <div class="empty" style="padding:24px">لا توجد بوالص استلم عليها بعد.</div>
    <?php else: ?>
      <label class="paid-summary-pick" id="paidSummary"
             data-count="<?= $paidCount ?>" data-paid="<?= e($paidTotal) ?>">
        <input type="checkbox" id="includePaid" name="include_paid_summary" value="1" <?= (!$fsHas || ($fsOld['include_paid_summary'] ?? '') !== '') ? 'checked' : '' ?>>
        <span><b><?= intf($paidCount) ?> بوليصة</b><small>حتى تاريخ <strong id="paidUntilLabel"><?= e(dfmt(work_date())) ?></strong></small></span>
        <span><b class="pos"><?= money($paidTotal,false) ?></b><small>إجمالي المدفوع له</small></span>
        <em>سيظهر هذا الملخّص كبند واحد في الكشف بدل سرد كل البوالص.</em>
      </label>
    <?php endif; ?>
  </div>

  <!-- (2) البوالص الباقية له -->
  <div class="sec-head"><span>② البوالص الباقية له بالتفصيل (<?= intf(count($remaining)) ?>)</span>
    <?php if ($remaining): ?><label class="chk-all"><input type="checkbox" class="pick-all" data-scope="rem" checked> تحديد الكل</label><?php endif; ?>
  </div>
  <div class="card mb" style="padding:0;overflow:hidden">
    <?php if (!$remaining): ?>
      <div class="empty" style="padding:24px">لا توجد بوالص باقية — كل مستحقاته مسدّدة ✓</div>
    <?php else: ?>
      <div class="table-wrap"><table class="data st-list" data-scope="rem">
        <thead><tr>
          <th style="width:38px"></th><th class="g-identity">رقم البوليصة</th><th class="g-identity">التاريخ</th>
          <th class="g-identity">الشركة</th><th class="g-identity">الحاوية</th><th class="g-identity">العدد</th>
          <th class="g-identity">الجهة</th><th class="g-identity">التصنيف</th>
          <th class="g-revenue">نولون</th><th class="g-revenue">عهدة</th><th class="g-revenue">تحويلة</th>
          <th class="g-revenue">خصم</th><th class="g-revenue">بياتة</th><th class="g-revenue">استلم</th><th class="g-result">الباقي</th><th class="g-identity">تعديل</th>
        </tr></thead>
        <tbody>
        <?php foreach ($remaining as $b): ?>
          <tr>
            <td><input type="checkbox" name="remaining_ids[]" value="<?= (int)$b['id'] ?>" class="pick" data-remaining="<?= e($b['remaining']) ?>" <?= $isPicked((int)$b['id'], $remPicked) ? 'checked' : '' ?>></td>
            <td style="font-weight:700"><?= e($billNo($b)) ?></td>
            <td><?= dfmt($b['bill_date']) ?></td>
            <td><?= e($b['company'] ?: '—') ?></td>
            <td><?= e($b['container_no'] ?: '—') ?><?php if($b['container_type']??''): ?> <span class="ct-chip"><?= e($b['container_type']) ?></span><?php endif; ?></td>
            <td><?= intf($b['container_count'] ?? 0) ?></td>
            <td><?= e($b['destination'] ?: '—') ?></td>
            <td><?= e($b['classification'] ?: '—') ?></td>
            <td><?= money($b['freight'],false) ?></td>
            <td><?= money($b['custody'],false) ?></td>
            <td><?= money($b['transfer'],false) ?></td>
            <td><?= money($b['discount'],false) ?></td>
            <td><?= money($b['overnight_collection'],false) ?></td>
            <td class="pos"><?= money($b['paid'],false) ?></td>
            <td class="neg" style="font-weight:800"><?= money($b['remaining'],false) ?></td>
            <td><a class="btn ghost sm" href="<?= url('bawalis/'.(int)$b['id'].'/edit') ?>" target="_blank" rel="noopener" title="تعديل البوليصة ثم اضغط تحديث البيانات">✏️ تعديل</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>

  <!-- (3) الإصدار -->
  <div class="sec-head"><span>③ الإصدار</span></div>
  <div class="card mb" style="padding:16px">
    <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:end">
      <div>
        <label style="font-size:.8rem;font-weight:700;color:var(--grey)">تاريخ الكشف</label>
        <input type="date" name="statement_date" value="<?= e($fsOld['statement_date'] ?? work_date()) ?>"
               style="display:block;margin-top:5px;padding:9px 12px;border:1px solid var(--line);border-radius:9px">
      </div>
      <div style="flex:1;min-width:240px">
        <label style="font-size:.8rem;font-weight:700;color:var(--grey)">ملاحظة على الكشف (اختياري)</label>
        <textarea name="note" rows="2"
                  style="display:block;width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--line);border-radius:9px;font-family:inherit"><?= e($fsOld['note'] ?? '') ?></textarea>
      </div>
    </div>

    <div class="st-summary">
      <div class="r"><span>عدد البوالص المستلَمة المختارة</span><b id="cntPaid">0</b></div>
      <div class="r"><span>مجموع المستلَم المختار</span><b id="sumPaid">0.00</b></div>
      <div class="r"><span>عدد البوالص الباقية المختارة</span><b id="cntRem">0</b></div>
      <div class="r grand"><span>مجموع الباقي المختار</span><b id="sumRem">0.00</b></div>
    </div>

    <div style="margin-top:16px;display:flex;gap:10px">
      <button type="submit" class="btn gold">📑 إصدار وحفظ ثم طباعة</button>
      <a class="btn ghost" href="<?= url('settlements?owner=' . urlencode($owner)) ?>">إلغاء</a>
    </div>
  </div>
</form>

<!-- كشوف سابقة لصاحب البيان -->
<?php if ($previous): ?>
  <div class="sec-head"><span>🗂️ كشوف صادرة سابقاً (<?= intf(count($previous)) ?>)</span></div>
  <div class="card mb" style="padding:0;overflow:hidden">
    <div class="table-wrap"><table class="data">
      <thead><tr>
        <th class="g-identity">رقم المستند</th><th class="g-identity">التاريخ</th>
        <th class="g-vehicle">مستلَمة</th><th class="g-vehicle">باقية</th>
        <th class="g-result">الباقي (كلّي وقتها)</th><th class="g-identity"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($previous as $pv): ?>
        <tr>
          <td style="font-weight:700"><?= e($pv['doc_no'] ?: '#'.$pv['id']) ?></td>
          <td><?= dfmt($pv['statement_date']) ?></td>
          <td><?= intf($pv['paid_count']) ?></td>
          <td><?= intf($pv['remaining_count']) ?></td>
          <td style="font-weight:800;color:var(--navy)"><?= money($pv['stat_remaining'],false) ?></td>
          <td><div class="actions">
            <a class="edit" href="<?= url('statements/'.$pv['id']) ?>" target="_blank" title="طباعة">🖨️</a>
            <?php if(($pv['status']??'issued')!=='void'): ?><form method="post" action="<?= url('statements/'.$pv['id'].'/delete') ?>" style="display:inline"
                  data-danger="1" data-confirm="إلغاء الكشف مع الاحتفاظ برقمه؟">
              <?= csrfField() ?><input type="hidden" name="lock_version" value="<?= (int)($pv['lock_version']??1) ?>"><input name="reason" required placeholder="سبب الإلغاء" style="width:100px"><button class="del" title="إلغاء">⛔</button>
            </form><?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
<?php endif; ?>

<style>
  .st-tiles{display:grid;grid-template-columns:repeat(3,1fr)}
  .stile{padding:16px;text-align:center;border:0;border-inline-start:1px solid var(--line);background:#fff;font-family:inherit;cursor:pointer;transition:.16s}
  .stile:first-child{border-inline-start:none}
  .stile:hover{background:#f8fbff;box-shadow:inset 0 -3px var(--gold,#C9A227)}
  .stile b{display:block;font-size:1.15rem;color:var(--navy)}.stile span{font-size:.74rem;color:var(--grey)}
  .stile small{display:block;margin-top:5px;color:#8a6d10;font-size:.67rem;font-weight:700}
  .stile b.pos{color:var(--green,#1f7a52)}.stile b.neg{color:var(--red,#B4383E)}
  .st-range{display:block;width:100%;padding:10px 16px;border:0;border-top:1px solid var(--line);background:#fff;text-align:right;font-family:inherit;font-size:.85rem;color:var(--grey);cursor:pointer}
  .st-range:hover{background:#f8fbff}
  .st-range b{color:var(--navy)}
  .st-pending-note{display:block;width:100%;padding:10px 16px;border:0;border-top:1px solid var(--line);text-align:right;font-family:inherit;font-size:.85rem;color:#5A6472;background:#f7f8fb;cursor:pointer}
  .st-pending-note:hover{background:#fff8df}
  .st-pending-note b{color:var(--navy)}
  .st-drill-card{width:min(1180px,96vw);max-height:90vh;overflow:hidden}
  .st-drill-card .d-modal-body{max-height:calc(90vh - 62px)}
  .st-drill-table th,.st-drill-table td{white-space:nowrap;vertical-align:middle}
  .st-quick-freight{display:flex;align-items:center;gap:7px;min-width:270px;margin:0}
  .st-quick-freight input{width:125px;padding:8px 9px;border:1px solid var(--line);border-radius:8px;font-family:inherit}
  .sec-head{display:flex;justify-content:space-between;align-items:center}
  .chk-all{font-size:.8rem;font-weight:700;color:var(--navy);cursor:pointer;display:inline-flex;align-items:center;gap:6px}
  .st-list td,.st-list th{white-space:nowrap}
  .st-list .pick{width:17px;height:17px;accent-color:var(--gold,#C9A227);cursor:pointer}
  .paid-summary-pick{display:grid;grid-template-columns:auto repeat(2,1fr) 1.5fr;gap:16px;align-items:center;padding:18px;cursor:pointer;background:#fffdf6}
  .paid-summary-pick>input{width:19px;height:19px;accent-color:var(--gold,#C9A227)}
  .paid-summary-pick span{text-align:center}.paid-summary-pick span b{display:block;color:var(--navy);font-size:1.05rem}.paid-summary-pick span small{color:var(--grey);font-weight:700}
  .paid-summary-pick em{font-style:normal;color:#7a5f0e;font-size:.8rem;line-height:1.7}
  .ct-chip{display:inline-block;background:#eef4ff;color:#2a5aa5;border-radius:6px;padding:1px 8px;font-size:.72rem;font-weight:700}
  .st-summary{margin-top:16px;max-width:400px;margin-inline-start:auto;border:1px solid var(--line);border-radius:12px;overflow:hidden}
  .st-summary .r{display:flex;justify-content:space-between;padding:10px 16px;font-size:.95rem;border-bottom:1px solid var(--line)}
  .st-summary .r b{font-variant-numeric:tabular-nums;font-weight:700}
  .st-summary .r.grand{background:var(--navy);color:#fff;border:none;font-size:1.1rem}
  .st-summary .r.grand b{color:var(--gold,#C9A227);font-weight:800}
  .del{background:none;border:none;cursor:pointer;font-size:1rem}
  @media(max-width:760px){.paid-summary-pick{grid-template-columns:auto 1fr}.paid-summary-pick em{grid-column:1/-1}.st-tiles{grid-template-columns:1fr}.stile{border-inline-start:0;border-top:1px solid var(--line)}.stile:first-child{border-top:0}.st-drill-card{width:98vw}}
</style>

<script>
(function(){
  var form = document.getElementById('stForm');
  function fmt(n){ return (Math.round(n*100)/100).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
  function fmtDate(v){ var p=(v||'').split('-'); return p.length===3 ? p[2]+'/'+p[1]+'/'+p[0] : v; }

  // مجموع عمود معيّن للصفوف المحدّدة في جدول بنطاق معيّن
  function scopeTable(scope){ return form.querySelector('table.st-list[data-scope="'+scope+'"]'); }

  function recalc(){
    var cntPaid=0,sumPaid=0,cntRem=0,sumRem=0;
    var inc = document.getElementById('includePaid');
    var ps  = document.getElementById('paidSummary');
    if(inc && ps && inc.checked){
      cntPaid = parseInt(ps.getAttribute('data-count')||'0',10)||0;
      sumPaid = parseFloat(ps.getAttribute('data-paid')||'0')||0;
    }
    var rt = scopeTable('rem');
    if(rt){ rt.querySelectorAll('tbody tr').forEach(function(tr){
      var c = tr.querySelector('.pick'); if(c && c.checked){ cntRem++;
        sumRem += parseFloat(c.getAttribute('data-remaining')||'0')||0; }
    }); }
    document.getElementById('cntPaid').textContent = cntPaid;
    document.getElementById('sumPaid').textContent = fmt(sumPaid);
    document.getElementById('cntRem').textContent = cntRem;
    document.getElementById('sumRem').textContent = fmt(sumRem);
  }

  form.querySelectorAll('.pick').forEach(function(c){ c.addEventListener('change', recalc); });
  var includePaid = document.getElementById('includePaid');
  if(includePaid) includePaid.addEventListener('change', recalc);
  var statementDate = form.querySelector('input[name="statement_date"]');
  var paidUntilLabel = document.getElementById('paidUntilLabel');
  function syncPaidDate(){ if(statementDate && paidUntilLabel) paidUntilLabel.textContent=fmtDate(statementDate.value); }
  if(statementDate) statementDate.addEventListener('change', syncPaidDate);
  form.querySelectorAll('.pick-all').forEach(function(a){
    a.addEventListener('change', function(){
      var scope = a.getAttribute('data-scope');
      var t = scopeTable(scope);
      if(t){ t.querySelectorAll('.pick').forEach(function(c){ c.checked = a.checked; }); }
      recalc();
    });
  });

  form.addEventListener('submit', function(e){
    var hasPaid = includePaid && includePaid.checked;
    if(!hasPaid && form.querySelectorAll('.pick:checked').length === 0){
      e.preventDefault(); window.AppUI.notice('اختر بوليصة واحدة على الأقل لإدراجها في الكشف.','بيانات ناقصة');
    }
  });

  syncPaidDate(); recalc();
})();
</script>

<script>
(function(){
  var modal = document.getElementById('stDrillModal');
  var store = document.getElementById('stDrillStore');
  var body = document.getElementById('stDrillBody');
  var title = document.getElementById('stDrillTitle');
  if(!modal || !store || !body || !title) return;

  function closeModal(){
    modal.hidden = true;
    body.innerHTML = '';
    document.body.style.overflow = '';
  }
  function openModal(key, heading){
    var tpl = store.querySelector('[data-st-template="'+key+'"]');
    if(!tpl) return;
    title.textContent = heading || 'تفاصيل البوالص';
    body.innerHTML = tpl.innerHTML;
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    var first = body.querySelector('input:not([type="hidden"]),a,button');
    if(first) setTimeout(function(){ first.focus(); }, 0);
  }

  document.querySelectorAll('[data-st-pop]').forEach(function(trigger){
    trigger.addEventListener('click', function(){
      openModal(trigger.getAttribute('data-st-pop'), trigger.getAttribute('data-st-title'));
    });
  });
  modal.querySelectorAll('[data-st-close]').forEach(function(close){ close.addEventListener('click', closeModal); });
  document.addEventListener('keydown', function(e){ if(e.key === 'Escape' && !modal.hidden) closeModal(); });
})();
</script>
