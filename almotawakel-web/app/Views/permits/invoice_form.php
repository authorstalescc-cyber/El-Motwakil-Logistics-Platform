<?php
use App\Models\Permit;
use App\Models\PermitInvoice;

$pid    = (int)$p['id'];
$type   = $p['permit_type'];
$isClosed = ($p['status'] ?? 'open') === 'closed';
$noLabel  = Permit::noLabel($type);
$vatPct = rtrim(rtrim(number_format($vatRate * 100, 2), '0'), '.');

// تجميع كل البوالص عبر الحركات (لملخص سريع)
$allBills = [];
foreach ($movements as $mv) { foreach ($mv['bills'] as $b) { $allBills[] = $b; } }
?>
<div class="permit-bar">
  <a class="btn ghost sm" href="<?= url('permits/'.$pid) ?>">‹ رجوع للإذن</a>
  <span class="pb-title">🧾 إصدار فاتورة — <?= Permit::typeLabel($type) ?> <b><?= e($p['permit_no'] ?: '#'.$pid) ?></b></span>
  <span class="pill <?= $isClosed?'st-paid':'st-partial' ?>"><?= $isClosed?'مقفول ✓':'جارٍ' ?></span>
</div>

<!-- ===== ملخص سريع للإذن ===== -->
<div class="sec-head"><span>📋 ملخص الإذن</span></div>
<div class="card mb" style="padding:0;overflow:hidden">
  <div class="inv-sum-grid">
    <?php
    $sumRows = [
      ['الحالة', $type==='warid' ? 'وارد (شهادة)' : 'صادر (إذن)'],
      [$noLabel, $p['permit_no'] ?: '—'],
      ['التاريخ', dfmt($p['permit_date']) ?: '—'],
      ['العميل (الوكيل)', $p['client'] ?: '—'],
      ['لصالح شركة', $p['company'] ?: '—'],
      ['الجهة', $p['destination'] ?: '—'],
      ['توكيل ملاحي', ($p['agency'] ?? '') ?: '—'],
      ['الميناء', ($p['port'] ?? '') ?: '—'],
      ['شركة الشحن', ($p['shipping_co'] ?? '') ?: '—'],
      ['عدد الحاويات', intf($p['containers'] ?? 0)],
    ];
    foreach ($sumRows as [$lbl,$val]): ?>
      <div class="isg-cell"><span class="isg-l"><?= $lbl ?></span><span class="isg-v"><?= e($val) ?></span></div>
    <?php endforeach; ?>
  </div>
  <?php if ($ctBreakdown): ?>
    <div class="inv-ct"><span class="isg-l">🧱 تصنيف الحاويات:</span>
      <?php foreach ($ctBreakdown as $ct): ?><span class="ct-chip"><?= intf($ct['cnt']) ?>×<?= e($ct['type']) ?></span><?php endforeach; ?>
    </div>
  <?php endif; ?>
  <div class="inv-tiles">
    <div class="itile"><b><?= intf(count($movements)) ?></b><span>الحركات</span></div>
    <div class="itile"><b><?= intf(count($allBills)) ?></b><span>البوالص</span></div>
    <div class="itile"><b class="pos"><?= money($totals['custody'],false) ?></b><span>العهدة</span></div>
    <div class="itile"><b class="neg"><?= money($totals['expense'],false) ?></b><span>المصروفات الفعلية</span></div>
    <div class="itile"><b><?= money($actualFreight,false) ?></b><span>النولون الفعلي (بوالص)</span></div>
  </div>
  <?php if ($allBills): ?>
    <details class="inv-bills">
      <summary>🚛 البوالص التي تمت (<?= intf(count($allBills)) ?>) — اضغط للعرض</summary>
      <div class="table-wrap"><table class="data">
        <thead><tr><th class="g-identity">رقم البوليصة</th><th class="g-identity">التاريخ</th>
          <th class="g-identity">الحاوية</th><th class="g-vehicle">العدد</th>
          <th class="g-revenue">النولون</th><th class="g-revenue">العهدة</th></tr></thead>
        <tbody>
        <?php foreach ($allBills as $b): ?>
          <tr>
            <td style="font-weight:700"><?= e($b['bill_number'] ?: '—') ?></td>
            <td><?= dfmt($b['bill_date']) ?></td>
            <td><?= e($b['container_no'] ?: '—') ?><?php if($b['container_type']??''): ?> <span class="ct-chip"><?= e($b['container_type']) ?></span><?php endif; ?></td>
            <td><?= intf($b['container_count'] ?? 0) ?></td>
            <td class="pos"><?= money($b['freight'],false) ?></td>
            <td class="pos"><?= money($b['custody'],false) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </details>
  <?php endif; ?>
</div>

<form method="post" action="<?= url('permits/'.$pid.'/invoice') ?>" id="invForm">
  <?= csrfField() ?>

  <!-- (1) نوع المستند + الضريبة -->
  <div class="sec-head"><span>① نوع المستند</span></div>
  <div class="card mb" style="padding:16px">
    <div class="inv-choice">
      <label class="ichip"><input type="radio" name="doc_kind" value="invoice" checked><span>فاتورة</span></label>
      <label class="ichip"><input type="radio" name="doc_kind" value="statement"><span>كشف حساب</span></label>
      <label class="ichip"><input type="radio" name="doc_kind" value="claim"><span>مطالبة بالإيصالات</span></label>
    </div>
    <div id="claimHint" class="i-hint" style="display:none;margin-top:10px">🧾 المطالبة بالإيصالات تسحب <b>كل الإيصالات فقط</b> بدون النولون وبدون الرسوم الأولية أو الضريبة.</div>
    <div id="taxBlock" style="margin-top:14px">
      <div style="font-weight:700;color:var(--navy,#15355B);margin-bottom:8px">خاضعة لضريبة القيمة المضافة (<?= $vatPct ?>%)؟</div>
      <div class="inv-choice">
        <label class="ichip sm"><input type="radio" name="taxable" value="0" checked><span>غير خاضعة</span></label>
        <label class="ichip sm"><input type="radio" name="taxable" value="1"><span>خاضعة للضريبة</span></label>
      </div>
      <div id="internalBlock" style="margin-top:12px;display:none">
        <label style="font-size:.8rem;font-weight:700;color:var(--grey,#5A6472)">رقم الفاتورة (يُدخَل يدوياً)<span id="internalReq"> *</span></label>
        <input type="text" name="internal_no" id="internalNo"
               style="display:block;width:100%;max-width:320px;margin-top:5px;padding:9px 12px;border:1px solid var(--line,#E2E7EE);border-radius:9px">
        <div id="internalHint" class="i-hint" style="display:none">اختياري لغير الخاضعة — لو تركته فارغاً يظهر رقم المستند التلقائي فقط.</div>
      </div>
    </div>

    <!-- اسم العميل في الطباعة (اختياري) — للطباعة فقط، لا يمسّ بيانات الإذن -->
    <div style="margin-top:14px">
      <label style="font-size:.8rem;font-weight:700;color:var(--grey,#5A6472)">اسم العميل في الطباعة (اختياري)</label>
      <input type="text" name="display_client" value="<?= e($p['client'] ?? '') ?>"
             style="display:block;width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--line,#E2E7EE);border-radius:9px">
      <div class="i-hint">يظهر هذا الاسم على المستند المطبوع فقط — لا يغيّر اسم العميل المسجّل في الإذن أو التقارير.</div>
    </div>

    <!-- عنوان بديل + رقم مسلسل خاص بالعميل (اختياريان) -->
    <div class="inv-extra">
      <div>
        <label style="font-size:.8rem;font-weight:700;color:var(--grey,#5A6472)">عنوان الفاتورة (اختياري)</label>
        <input type="text" name="custom_title" placeholder="اتركه فارغاً للعنوان الافتراضي (فاتورة / كشف حساب / مطالبة بالإيصالات)"
               style="display:block;width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--line,#E2E7EE);border-radius:9px">
        <div class="i-hint">لو أدخلت عنواناً هنا سيظهر بدل العنوان الافتراضي في الطباعة.</div>
      </div>
      <div>
        <label style="font-size:.8rem;font-weight:700;color:var(--grey,#5A6472)">رقم مسلسل خاص بالعميل (اختياري)</label>
        <input type="text" name="serial_no" placeholder="رقم مرجعي للعميل — لا يتكرّر"
               style="display:block;width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--line,#E2E7EE);border-radius:9px">
        <div class="i-hint">يُطبَع على المستند، ويُرفَض لو كان مستخدَماً من قبل.</div>
      </div>
    </div>
  </div>

  <?php $needAgency = trim((string)($p['agency'] ?? '')) === ''; $needPort = trim((string)($p['port'] ?? '')) === ''; ?>
  <?php if ($needAgency || $needPort): ?>
    <!-- بيانات ناقصة في الإذن: توكيل ملاحي / ميناء — تُدخَل هنا وتُسجَّل في الإذن -->
    <div class="card mb" style="padding:16px;border:1.5px dashed var(--gold,#C9A227);background:#fffdf4">
      <div style="font-weight:800;color:var(--navy,#15355B);margin-bottom:4px">⚓ بيانات ناقصة في الإذن</div>
      <div class="i-hint" style="margin-bottom:10px">التوكيل الملاحي/الميناء غير مسجّلين في هذا الإذن. أدخلهما هنا قبل الإصدار — سيُحفظان في الإذن نفسه تلقائياً.</div>
      <div class="inv-extra">
        <?php if ($needAgency): ?>
          <div>
            <label style="font-size:.8rem;font-weight:700;color:var(--grey,#5A6472)">توكيل ملاحي</label>
            <input type="text" name="agency" style="display:block;width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--line,#E2E7EE);border-radius:9px">
          </div>
        <?php endif; ?>
        <?php if ($needPort): ?>
          <div>
            <label style="font-size:.8rem;font-weight:700;color:var(--grey,#5A6472)">الميناء</label>
            <input type="text" name="port" style="display:block;width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--line,#E2E7EE);border-radius:9px">
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- (2) بنود الفاتورة: بيان / كمية / سعر / إجمالي -->
  <div class="sec-head"><span>② بنود الفاتورة — بيان / كمية / سعر / إجمالي</span></div>
  <div class="card mb" style="padding:0;overflow:hidden">
    <div class="table-wrap"><table class="data inv-items" id="itemsTable">
      <thead><tr>
        <th class="g-identity">البيان</th>
        <th class="g-vehicle" style="width:90px">الكمية</th>
        <th class="g-revenue" style="width:140px">السعر</th>
        <th class="g-result" style="width:150px">الإجمالي</th>
        <th style="width:44px"></th>
      </tr></thead>
      <tbody>
        <!-- صف النولون (يُحسب عليه الضريبة، غير قابل للحذف؛ يُستبعَد في «مطالبة بالإيصالات») -->
        <tr class="inv-row freight-row" data-role="freight">
          <td>
            <input type="text" name="item_label[]" value="النولون" class="i-label">
            <input type="hidden" name="item_freight[]" value="1">
            <input type="hidden" name="item_src[]" value="freight">
            <div class="i-hint">النولون الفعلي المسجّل: <b><?= money($actualFreight,false) ?></b>
              <?php if ($actualFreight>0): ?><button type="button" class="mini-btn" id="useActual">استخدم الفعلي</button><?php endif; ?>
            </div>
          </td>
          <td><input type="number" step="0.01" name="item_qty[]" value="1" class="i-qty"></td>
          <td><input type="number" step="0.01" name="item_price[]" value="" placeholder="0.00" class="i-price"></td>
          <td class="i-total">0.00</td>
          <td></td>
        </tr>
        <!-- بنود الإذن (إيصالات + رسوم أولية) — الكمية وسعر الوحدة مستورَدان -->
        <?php foreach ($candidates as $c): ?>
          <?php $src = !empty($c['auto']) ? 'primary_auto' : 'stage:'.(int)$c['stage_id']; ?>
          <tr class="inv-row" data-role="<?= e($c['role'] ?? 'receipt') ?>">
            <td>
              <input type="text" name="item_label[]" value="<?= e($c['label']) ?>" class="i-label">
              <input type="hidden" name="item_freight[]" value="0">
              <input type="hidden" name="item_src[]" value="<?= e($src) ?>">
              <?php if (!empty($c['auto']) || !empty($c['note'])): ?>
                <div class="i-hint"><?php if(!empty($c['auto'])): ?><span class="auto-tag">تلقائي</span> <?php endif; ?><?= e($c['date'] ? dfmt($c['date']).' — ' : '') ?><?= e($c['note'] ?? '') ?></div>
              <?php endif; ?>
            </td>
            <td><input type="number" step="0.01" name="item_qty[]" value="<?= (int)($c['qty'] ?? 1) ?>" class="i-qty"></td>
            <td><input type="number" step="0.01" name="item_price[]" value="<?= number_format((float)$c['unit'],2,'.','') ?>" class="i-price"></td>
            <td class="i-total">0.00</td>
            <td><button type="button" class="row-del" title="حذف البند">✕</button></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <div style="padding:12px 16px;border-top:1px solid var(--line,#E2E7EE)">
      <button type="button" class="btn ghost sm" id="addRow">➕ إضافة بند</button>
    </div>
  </div>

  <!-- (3) الإصدار -->
  <div class="sec-head"><span>③ الإجمالي والإصدار</span></div>
  <div class="card mb" style="padding:16px">
    <label style="font-size:.8rem;font-weight:700;color:var(--grey,#5A6472)">ملاحظة على الفاتورة (اختياري)</label>
    <textarea name="note" rows="2" placeholder="أي بيان إضافي يظهر في المستند"
              style="display:block;width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--line,#E2E7EE);border-radius:9px;font-family:inherit"></textarea>

    <div class="inv-extra" style="margin-top:14px">
      <div><label style="font-size:.8rem;font-weight:700;color:var(--grey,#5A6472)">تاريخ الإصدار</label>
        <input type="date" name="invoice_date" value="<?= e(work_date()) ?>" required></div>
      <div><label style="font-size:.8rem;font-weight:700;color:var(--grey,#5A6472)">تاريخ الاستحقاق</label>
        <input type="date" name="due_date" value="<?= e(date('Y-m-d',strtotime(work_date().' +'.(int)($paymentTermsDays ?? 30).' days'))) ?>">
        <div class="i-hint">يُحسب افتراضياً من شروط دفع العميل، ويمكن تعديله قبل الإصدار.</div></div>
    </div>

    <?php if (!$isClosed): ?>
      <label class="close-opt"><input type="checkbox" name="close_permit" value="1"><span>🔒 إغلاق الإذن بعد إصدار الفاتورة</span></label>
    <?php endif; ?>

    <div class="inv-summary" data-vat="<?= e($vatRate) ?>">
      <div class="r"><span>إجمالي البنود</span><b id="sumItems">0.00</b></div>
      <div class="r" id="rVat"><span>ق. مضافة (<?= $vatPct ?>%) على النولون</span><b id="sumVat">0.00</b></div>
      <div class="r grand"><span>الإجمالي الكلي</span><b id="sumTotal">0.00</b></div>
    </div>

    <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
      <button type="submit" class="btn gold" formaction="<?= url('permits/'.$pid.'/invoice/preview') ?>">👁️ معاينة قبل الإصدار</button>
      <button type="submit" class="btn ghost">🧾 إصدار مباشر وحفظ</button>
      <a class="btn ghost" href="<?= url('permits/'.$pid) ?>">إلغاء</a>
    </div>
  </div>
</form>

<!-- سجل الفواتير السابقة لهذا الإذن -->
<?php if ($invoices): ?>
  <div class="sec-head"><span>🗂️ مستندات صادرة سابقاً لهذا الإذن (<?= intf(count($invoices)) ?>)</span></div>
  <div class="card mb" style="padding:0;overflow:hidden">
    <div class="table-wrap"><table class="data">
      <thead><tr>
        <th class="g-identity">رقم المستند</th><th class="g-identity">النوع</th><th class="g-identity">التاريخ</th>
        <th class="g-identity">الحالة</th><th class="g-result">الإجمالي</th><th class="g-result">المتبقي</th><th class="g-identity"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($invoices as $iv): ?>
        <tr>
          <td style="font-weight:700"><?= e($iv['doc_no'] ?: '#'.$iv['id']) ?></td>
          <td><span class="pill <?= $iv['doc_kind']==='statement'?'st-partial':'st-paid' ?>"><?= e(PermitInvoice::kindLabel($iv['doc_kind'])) ?><?= $iv['taxable']?' • ضريبية':'' ?></span></td>
          <td><?= dfmt($iv['invoice_date']) ?></td>
          <td><span class="pill"><?= e(PermitInvoice::statusLabel($iv['status'],$iv['due_date'])) ?></span></td>
          <td style="font-weight:800;color:var(--navy,#15355B)"><?= money($iv['total'], false) ?></td>
          <td style="font-weight:800"><?= money($iv['balance_due'],false) ?></td>
          <td><div class="actions">
            <a class="edit" href="<?= url('permits/invoice/'.$iv['id']) ?>" target="_blank" title="طباعة">🖨️</a>
            <?php if($iv['status']!=='void'): ?><form method="post" action="<?= url('permits/invoice/'.$iv['id'].'/delete') ?>" style="display:inline"
                  data-danger="1" data-confirm="إلغاء المستند مع الاحتفاظ برقمه؟">
              <?= csrfField() ?><input type="hidden" name="lock_version" value="<?= (int)$iv['lock_version'] ?>">
              <input name="reason" required placeholder="سبب الإلغاء" style="width:110px"><button class="del" title="إلغاء">⛔</button>
            </form><?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
<?php endif; ?>

<style>
  .inv-sum-grid{display:grid;grid-template-columns:repeat(5,1fr)}
  .isg-cell{padding:10px 14px;border-bottom:1px solid var(--line,#E2E7EE);border-inline-start:1px solid var(--line,#E2E7EE)}
  .isg-l{display:block;font-size:.72rem;color:var(--grey,#5A6472);font-weight:700;margin-bottom:3px}
  .isg-v{font-weight:700;color:var(--navy,#15355B)}
  .inv-ct{padding:10px 14px;border-bottom:1px solid var(--line,#E2E7EE);display:flex;gap:8px;flex-wrap:wrap;align-items:center}
  .ct-chip{display:inline-block;background:#eef4ff;color:#2a5aa5;border-radius:6px;padding:2px 9px;font-size:.75rem;font-weight:700}
  .inv-tiles{display:grid;grid-template-columns:repeat(5,1fr);gap:0}
  .itile{padding:12px;text-align:center;border-inline-start:1px solid var(--line,#E2E7EE)}
  .itile b{display:block;font-size:1.05rem}.itile span{font-size:.72rem;color:var(--grey,#5A6472)}
  .inv-bills{border-top:1px solid var(--line,#E2E7EE)}
  .inv-bills>summary{padding:11px 16px;cursor:pointer;font-weight:700;color:var(--navy,#15355B)}
  .inv-choice{display:flex;gap:10px;flex-wrap:wrap}
  .ichip{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border:1.5px solid var(--line,#E2E7EE);
    border-radius:10px;cursor:pointer;font-weight:700;user-select:none;transition:.15s}
  .ichip.sm{padding:8px 14px}
  .inv-extra{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px}
  @media(max-width:700px){.inv-extra{grid-template-columns:1fr}}
  .ichip input{accent-color:var(--navy,#15355B)}
  .ichip:has(input:checked){border-color:var(--gold,#C9A227);background:#fff9e9;color:var(--navy,#15355B);box-shadow:0 0 0 3px rgba(201,162,39,.12)}
  .inv-items td{vertical-align:top}
  .inv-items input{width:100%;padding:7px 9px;border:1px solid var(--line,#E2E7EE);border-radius:7px;font-family:inherit}
  .inv-items .i-label{font-weight:700}
  .inv-items .i-qty,.inv-items .i-price{text-align:center;font-variant-numeric:tabular-nums}
  .inv-items .i-total{font-weight:800;color:var(--navy,#15355B);font-variant-numeric:tabular-nums;text-align:left;white-space:nowrap;padding-top:14px}
  .freight-row{background:#fffdf4}
  .i-hint{font-size:.72rem;color:var(--grey,#5A6472);margin-top:4px}
  .i-hint b{color:var(--navy,#15355B)}
  .freight-tag{display:inline-block;background:#fff3d6;color:#8a6d10;border-radius:5px;padding:1px 7px;margin-inline-start:4px;font-weight:700}
  .auto-tag{display:inline-block;background:#eef4ff;color:#2a5;border-radius:5px;padding:0 6px;font-weight:700}
  .mini-btn{border:1px solid var(--navy,#15355B);background:#fff;color:var(--navy,#15355B);border-radius:6px;padding:1px 8px;cursor:pointer;font-size:.72rem;font-weight:700;margin-inline-start:4px}
  .row-del{border:none;background:#fbe7e9;color:#b4383e;border-radius:7px;width:30px;height:30px;cursor:pointer;font-weight:800}
  .close-opt{display:flex;align-items:center;gap:8px;margin-top:14px;padding:10px 14px;border:1px dashed var(--gold,#C9A227);
    border-radius:9px;background:#fffdf4;font-weight:700;color:var(--navy,#15355B);cursor:pointer;width:fit-content}
  .close-opt input{width:17px;height:17px;accent-color:var(--gold,#C9A227)}
  .inv-summary{margin-top:16px;max-width:360px;margin-inline-start:auto;border:1px solid var(--line,#E2E7EE);border-radius:12px;overflow:hidden}
  .inv-summary .r{display:flex;justify-content:space-between;padding:10px 16px;font-size:.95rem;border-bottom:1px solid var(--line,#E2E7EE)}
  .inv-summary .r b{font-variant-numeric:tabular-nums;font-weight:700}
  .inv-summary .r.grand{background:var(--navy,#15355B);color:#fff;border:none;font-size:1.1rem}
  .inv-summary .r.grand b{color:var(--gold,#C9A227);font-weight:800}
  .del{background:none;border:none;cursor:pointer;font-size:1rem}
  @media(max-width:700px){.inv-sum-grid,.inv-tiles{grid-template-columns:repeat(2,1fr)}}
</style>

<script>
(function(){
  var form = document.getElementById('invForm');
  var taxBlock = document.getElementById('taxBlock');
  var internalBlock = document.getElementById('internalBlock');
  var internalNo = document.getElementById('internalNo');
  var internalReq = document.getElementById('internalReq');
  var internalHint = document.getElementById('internalHint');
  var summary = form.querySelector('.inv-summary');
  var vatRate = parseFloat(summary.getAttribute('data-vat')) || 0;
  var rVat = document.getElementById('rVat');
  var claimHint = document.getElementById('claimHint');
  var tbody = document.querySelector('#itemsTable tbody');

  function kind(){ return form.querySelector('input[name=doc_kind]:checked').value; }
  function taxable(){ return kind()==='invoice' && form.querySelector('input[name=taxable]:checked').value==='1'; }
  function fmt(n){ return (Math.round(n*100)/100).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }

  // «مطالبة بالإيصالات»: تُستبعَد صفوف النولون والرسوم الأولية (تُعطَّل فلا تُرسَل ولا تُحسَب).
  function applyClaim(){
    var isClaim = kind()==='claim';
    tbody.querySelectorAll('.inv-row').forEach(function(row){
      var role = row.getAttribute('data-role');
      var hide = isClaim && (role==='freight' || role==='primary');
      row.style.display = hide ? 'none' : '';
      row.querySelectorAll('input').forEach(function(el){ el.disabled = hide; });
    });
  }

  function syncMode(){
    var k = kind();
    var isInv = k==='invoice';
    claimHint.style.display = k==='claim' ? '' : 'none';
    taxBlock.style.display = isInv ? '' : 'none';
    // خانة رقم الفاتورة اليدوي تظهر للفاتورة فقط، إجبارية للضريبية واختيارية لغيرها.
    internalBlock.style.display = isInv ? '' : 'none';
    internalNo.required = (isInv && taxable());
    internalReq.style.display = taxable() ? '' : 'none';
    internalHint.style.display = (isInv && !taxable()) ? '' : 'none';
    rVat.style.display = taxable() ? '' : 'none';
    applyClaim();
    recalc();
  }

  function recalc(){
    var subtotal = 0, freightTotal = 0;
    tbody.querySelectorAll('.inv-row').forEach(function(row){
      if(row.style.display==='none'){ return; }   // صفوف مستبعَدة (مطالبة بالإيصالات)
      var qty = parseFloat(row.querySelector('.i-qty').value)||0;
      var price = parseFloat(row.querySelector('.i-price').value)||0;
      var t = Math.round(qty*price*100)/100;
      row.querySelector('.i-total').textContent = fmt(t);
      subtotal += t;
      if(row.querySelector('input[name="item_freight[]"]').value==='1'){ freightTotal += t; }
    });
    var vat = taxable() ? Math.round(freightTotal*vatRate*100)/100 : 0;
    document.getElementById('sumItems').textContent = fmt(subtotal);
    document.getElementById('sumVat').textContent = fmt(vat);
    document.getElementById('sumTotal').textContent = fmt(subtotal+vat);
  }

  function wireRow(row){
    row.querySelectorAll('.i-qty,.i-price').forEach(function(el){ el.addEventListener('input', recalc); });
    var del = row.querySelector('.row-del');
    if(del){ del.addEventListener('click', function(){ row.remove(); recalc(); }); }
  }

  tbody.querySelectorAll('.inv-row').forEach(wireRow);
  form.querySelectorAll('input[name=doc_kind],input[name=taxable]').forEach(function(r){ r.addEventListener('change', syncMode); });

  document.getElementById('addRow').addEventListener('click', function(){
    var tr = document.createElement('tr');
    tr.className = 'inv-row';
    tr.setAttribute('data-role', 'new');
    tr.innerHTML =
      '<td><input type="text" name="item_label[]" value="" placeholder="اسم البند" class="i-label">'+
      '<input type="hidden" name="item_freight[]" value="0">'+
      '<input type="hidden" name="item_src[]" value="new"></td>'+
      '<td><input type="number" step="0.01" name="item_qty[]" value="1" class="i-qty"></td>'+
      '<td><input type="number" step="0.01" name="item_price[]" value="" placeholder="0.00" class="i-price"></td>'+
      '<td class="i-total">0.00</td>'+
      '<td><button type="button" class="row-del" title="حذف البند">✕</button></td>';
    tbody.appendChild(tr);
    wireRow(tr);
    tr.querySelector('.i-label').focus();
  });

  var ua = document.getElementById('useActual');
  if(ua){ ua.addEventListener('click', function(){
    document.querySelector('.freight-row .i-price').value = '<?= number_format($actualFreight,2,'.','') ?>'; recalc();
  }); }

  syncMode();
})();
</script>
