<?php
/**
 * جسم شاشة الإذن — **وضع التعديل**.
 *
 * ⚠️ اقرأ رأس `_body_view.php`: الغلاف مشترك والجسمان منفصلان عن قصد.
 */
use App\Models\Permit;

$pid  = (int)$p['id'];
$type = $p['permit_type'];
$noLabel = Permit::noLabel($type);
$isClosed = ($p['status'] ?? 'open') === 'closed';
$th = Permit::theme($type);
$primaryDef = Permit::primaryFeesDefault($pid);
$pStages = Permit::scopedStages('permit');    // data, primary_fees
$mStages = Permit::scopedStages('movement');  // bills, receipts, custom, generators
$mvExpenseKeys = ['receipts'];
$nameMap = $nameMap ?? ['agentCompanies'=>[], 'companyAgents'=>[]];
$carMap  = $carMap ?? [];
$receiptMoveTargets = $receiptMoveTargets ?? [];

/* التقدّم بيتحسب في الغلاف `show.php` — الهيرو محتاجه قبل الجسم. */

// حاويات الإذن: الإجمالي المُدخَل على الإذن مقابل الموزّع على البوالص
$ctTotal = (int)($p['containers'] ?? 0);
$ctAllocated = 0;
$ctByType = [];
foreach ($movements as $mv) { foreach ($mv['bills'] as $b) {
    $ctAllocated += (int)($b['container_count'] ?? 0);
    $bt = trim((string)($b['container_type'] ?? '')); $bn = (int)($b['container_count'] ?? 0);
    if ($bt !== '' && $bn > 0) { $ctByType[$bt] = ($ctByType[$bt] ?? 0) + $bn; }
} }
$ctbRows = []; foreach ($ctByType as $t => $n) { $ctbRows[] = ['type'=>$t, 'cnt'=>$n]; }
$ctLbl = Permit::containerLabel($ctbRows, $ctTotal, $p['container_type'] ?? null);

$statusBadge = fn(string $st) => $st === 'pending'
    ? '<span class="tl-st pending">ناقصة</span>' : '<span class="tl-st done">تمّت</span>';

// نموذج بند مرحلة الإذن (رسوم أولية) — نطاق الإذن فقط
$stageForm = function(string $key, ?array $entry) use ($pid, $primaryDef) {
    $isEdit = $entry !== null;
    $amtVal = $isEdit ? $entry['amount'] : ($key === 'primary_fees' ? $primaryDef['total'] : '');
    ob_start(); ?>
    <form class="tl-form" method="post" action="<?= url('permits/'.$pid.'/stage') ?>" enctype="multipart/form-data">
      <?= csrfField() ?>
      <input type="hidden" name="stage_key" value="<?= e($key) ?>">
      <?php if ($isEdit): ?><input type="hidden" name="stage_id" value="<?= (int)$entry['id'] ?>"><?php endif; ?>
      <div class="tl-fields">
        <label>المبلغ<input type="number" step="0.01" name="amount" value="<?= e($amtVal) ?>" placeholder="0"></label>
        <label>التاريخ<input type="date" name="entry_date" value="<?= $isEdit? e($entry['entry_date']) : work_date() ?>"></label>
        <label>رقم مرجعي<input name="ref_no" value="<?= $isEdit? e($entry['ref_no']) : '' ?>" placeholder="اختياري"></label>
        <label>الحالة
          <select name="status">
            <option value="done" <?= $isEdit&&$entry['status']==='done'?'selected':'' ?>>تمّت</option>
            <option value="pending" <?= $isEdit&&$entry['status']==='pending'?'selected':'' ?>>ناقصة</option>
          </select>
        </label>
        <label class="wide">ملاحظة / البيان<input name="note" value="<?= $isEdit? e($entry['note']) : '' ?>"></label>
        <label class="wide">مرفق (صورة / PDF)<input type="file" name="attachment" accept="image/*,application/pdf"></label>
      </div>
      <div class="tl-form-actions">
        <button class="btn gold sm" type="submit"><?= icon('download', 14) ?> <?= $isEdit?'حفظ التعديل':'إضافة البند' ?></button>
        <?php if ($isEdit && !empty($entry['attachment'])): ?>
          <a class="btn ghost sm" href="<?= url('attachments/stage/'.(int)$entry['id']) ?>" target="_blank"><?= icon('file-text', 14) ?> عرض المرفق</a>
        <?php endif; ?>
      </div>
    </form>
    <?php return ob_get_clean();
};

// بلوك مرحلة مالية على مستوى الإذن (الرسوم الأولية) — أكورديون
$moneyStage = function(string $key, array $st, array $entries, int $idx) use ($stageForm, $primaryDef, $statusBadge, $pid) {
    $noneRow = null; $realEntries = [];
    foreach ($entries as $en) { if (($en['status'] ?? '') === 'none') { $noneRow = $en; } else { $realEntries[] = $en; } }
    $isNone = ($noneRow !== null && !$realEntries);

    $stageTotal = array_sum(array_map(fn($x)=>(float)$x['amount'], $realEntries));
    $autoPrimary = ($key === 'primary_fees' && !$realEntries && !$isNone && ($primaryDef['containers'] ?? 0) > 0 && !empty($primaryDef['rates_set']));
    $headTotal   = $autoPrimary ? (float)$primaryDef['total'] : $stageTotal;
    $hasData     = !empty($realEntries) || $autoPrimary || $isNone;
    $showTotal   = $headTotal > 0;
    ob_start(); ?>
    <details class="stage <?= $hasData?'has':'' ?>" id="stage-<?= e($key) ?>">
      <summary class="stage-sum">
        <span class="ss-ic <?= $hasData?'ok':'' ?>"><?= $hasData?'✓':$st['icon'] ?></span>
        <span class="ss-num"><?= $idx ?></span>
        <span class="ss-name"><?= e($st['label']) ?></span>
        <?php if ($st['dir']===Permit::DIR_EXPENSE): ?><span class="tl-dir neg">مصروف</span><?php endif; ?>
        <span class="ps-sp"></span>
        <?php if ($isNone): ?><span class="ss-none"><?= icon('x', 14) ?> لا يوجد</span>
        <?php elseif (count($realEntries)): ?><span class="ss-count"><?= intf(count($realEntries)) ?></span>
        <?php elseif ($autoPrimary): ?><span class="ss-count" title="محسوبة تلقائيًا من حاويات الإذن">تلقائي</span><?php endif; ?>
        <?php if ($showTotal): ?><span class="ss-amt neg"><?= money($headTotal,false) ?></span><?php endif; ?>
        <span class="ss-chev">▾</span>
      </summary>
      <div class="stage-body">
        <?php if ($autoPrimary): ?>
          <div class="tl-auto">
            <div class="tl-auto-h"><?= icon('coins', 14) ?> رسوم أولية محسوبة تلقائيًا من حاويات الإذن (<?= intf($primaryDef['containers']) ?> حاوية):</div>
            <div class="tl-auto-row"><span>الكشف</span><span><?= intf($primaryDef['containers']) ?> × <?= money($primaryDef['inspection_rate'],false) ?></span><b><?= money($primaryDef['inspection'],false) ?></b></div>
            <div class="tl-auto-row"><span>الضمان</span><span><?= intf($primaryDef['containers']) ?> × <?= money($primaryDef['guarantee_rate'],false) ?></span><b><?= money($primaryDef['guarantee'],false) ?></b></div>
            <div class="tl-auto-row tot"><span>الإجمالي</span><span></span><b><?= money($primaryDef['total'],false) ?></b></div>
          </div>
        <?php endif; ?>
        <?php if ($isNone): ?>
          <div class="tl-none">
            <span><?= icon('x', 14) ?> هذه المرحلة معلَّمة <b>«لا يوجد»</b> ومحسوبة كمكتملة.</span>
            <form class="js-ajax" method="post" action="<?= url('permits/stage/'.$noneRow['id'].'/delete') ?>">
              <?= csrfField() ?><button class="btn ghost sm" type="submit"><?= icon('undo', 14) ?> إلغاء التعليم</button>
            </form>
          </div>
        <?php endif; ?>
        <?php if ($realEntries): foreach ($realEntries as $en): ?>
          <details class="tl-entry">
            <summary>
              <span class="te-date"><?= dfmt($en['entry_date']) ?></span>
              <span class="te-amt neg"><?= money($en['amount'],false) ?></span>
              <?php if(!empty($en['is_visa'])): ?><span class="tl-visa-badge"><?= icon('credit-card', 14) ?> فيزا</span><?php endif; ?>
              <?php if($en['note']): ?><span class="te-note"><?= e($en['note']) ?></span><?php endif; ?>
              <?= $statusBadge($en['status']) ?>
              <?php if($en['attachment']): ?><a class="te-clip" href="<?= url('attachments/stage/'.(int)$en['id']) ?>" target="_blank" onclick="event.stopPropagation()"><?= icon('file-text', 14) ?></a><?php endif; ?>
              <span class="te-edit">تعديل ▾</span>
            </summary>
            <?= $stageForm($key, $en) ?>
            <form class="js-ajax confirm-delete ps-del-form" method="post" action="<?= url('permits/stage/'.$en['id'].'/delete') ?>">
              <?= csrfField() ?><button class="btn ghost sm danger" type="submit"><?= icon('trash', 14) ?> حذف</button>
            </form>
          </details>
        <?php endforeach; endif; ?>
        <?php if (!$isNone): ?>
          <details class="tl-add">
            <summary><?= icon('plus', 14) ?> <?= !$realEntries ? 'إضافة بند' : 'تعديل / إضافة بند' ?></summary>
            <?= $stageForm($key, null) ?>
          </details>
          <?php if (!$realEntries): ?>
            <form class="js-ajax tl-none-btn" method="post" action="<?= url('permits/'.$pid.'/stage') ?>">
              <?= csrfField() ?>
              <input type="hidden" name="stage_key" value="<?= e($key) ?>">
              <input type="hidden" name="mark_none" value="1">
              <button class="btn ghost sm" type="submit"><?= icon('x', 14) ?> لا يوجد — علّم كمكتمل</button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </details>
    <?php return ob_get_clean();
};
?>

<!-- ===== مراحل على مستوى الإذن (بيانات + رسوم أولية) ===== -->
<div class="sec-head"><span><?= icon('folder', 14) ?> بيانات الإذن ورسومه</span></div>
<div class="stages mb">
  <?php $pi=0; foreach ($pStages as $key => $st): $pi++;
    $entries = $permitStages[$key] ?? [];
  ?>
    <?php if ($key === 'data'): ?>
      <details class="stage has" id="stage-data" open>
        <summary class="stage-sum">
          <span class="ss-ic ok"><?= icon('file-text', 14) ?></span><span class="ss-num"><?= $pi ?></span>
          <span class="ss-name">بيانات الإذن</span><span class="ps-sp"></span>
          <span class="ss-chev">▾</span>
        </summary>
        <div class="stage-body">
          <form id="permitDataForm" class="tl-form" method="post" action="<?= url('permits/'.$pid) ?>">
            <?= csrfField() ?>
            <?= formStateFields($permitFormState['token'], $permitFormState['back']) ?>
            <input type="hidden" name="_form_id" value="permits.manage.<?= $pid ?>">
            <input type="hidden" name="lock_version" value="<?= (int)($p['lock_version'] ?? 1) ?>">
            <div class="tl-fields">
              <label>النوع
                <select name="permit_type">
                  <?php foreach (Permit::TYPES as $tk=>$tv): ?>
                    <option value="<?= $tk ?>" <?= $type===$tk?'selected':'' ?>><?= e($tv['label']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label><?= e($noLabel) ?><input name="permit_no" value="<?= e($p['permit_no']) ?>" autocomplete="off"<?= fieldErrorAttrs('permit_no', $permitFormState['errors']) ?>><?= fieldError('permit_no', $permitFormState['errors']) ?></label>
              <label>التاريخ<input type="date" name="permit_date" value="<?= e($p['permit_date']) ?>"></label>
              <label>عدد الحاويات (الإجمالي)<input type="number" min="0" name="containers" value="<?= e($p['containers'] ?? 0) ?>"></label>
              <?php partial('lookup_select', ['name'=>'container_type','type'=>'container_type','value'=>$p['container_type'] ?? '','label'=>'تصنيف الحاويات']); ?>
              <label>سعر الكشف لكل حاوية<input type="number" step="0.01" min="0" name="inspection_rate" value="<?= e($p['inspection_rate'] ?? '') ?>" placeholder="0"></label>
              <label>سعر الضمان لكل حاوية<input type="number" step="0.01" min="0" name="guarantee_rate" value="<?= e($p['guarantee_rate'] ?? '') ?>" placeholder="0"></label>
              <label>الوكيل (العميل)<input list="dl_client" id="permit_client" name="client" value="<?= e($p['client']) ?>" autocomplete="off"></label>
              <label>لصالح شركة<input list="dl_company" id="permit_company" name="company" value="<?= e($p['company']) ?>" autocomplete="off"></label>
              <label>الجهة<input list="dl_dest" name="destination" value="<?= e($p['destination']) ?>" autocomplete="off"></label>
              <?php partial('lookup_select', ['name'=>'shipping_co','type'=>'shipping_co','value'=>$p['shipping_co'] ?? '','label'=>'🚢 شركة الشحن','attrs'=>'data-autofill-from="client" title="بتتملى تلقائيًا من العميل — غيّرها لو شركة الشحن مختلفة"']); ?>
              <?php partial('lookup_select', ['name'=>'agency','type'=>'agency','value'=>$p['agency'] ?? '','labelHtml'=>icon('receipt', 14).' التوكيل']); ?>
              <?php partial('lookup_select', ['name'=>'port','type'=>'port','value'=>$p['port'] ?? '','labelHtml'=>icon('anchor', 14).' الميناء']); ?>
              <label class="wide">ملاحظة<input name="note" value="<?= e($p['note']) ?>"></label>
            </div>
            <div class="tl-form-actions"><button class="btn gold sm" type="submit" data-primary-submit><?= icon('download', 14) ?> حفظ بيانات الإذن</button></div>
          </form>
        </div>
      </details>
    <?php else: /* primary_fees */ ?>
      <?= $moneyStage($key, $st, $entries, $pi) ?>
    <?php endif; ?>
  <?php endforeach; ?>
</div>

<!-- ===== الحركات ===== -->
<div class="sec-head">
  <span><?= icon('shuffle', 14) ?> الحركات (<span id="mvCount"><?= intf(count($movements)) ?></span>)</span>
  <form method="post" action="<?= url('permits/'.$pid.'/movement') ?>" class="js-ajax mv-add-form" id="addMovementForm">
    <?= csrfField() ?>
    <input type="number" min="0" name="container_count" placeholder="عدد الحاويات" title="عدد حاويات الحركة (اختياري)" class="mva-count" autocomplete="off">
    <?php partial('lookup_select', ['name'=>'container_type','type'=>'container_type','blank'=>'التصنيف','attrs'=>'title="تصنيف حاويات الحركة (اختياري)" class="mva-type"']); ?>
    <button class="btn gold sm" type="submit"><?= icon('plus', 14) ?> حركة جديدة</button>
  </form>
</div>

<div id="movementsWrap">
  <?php if (!$movements): ?>
    <div class="card empty-mv"><div class="empty empty-mv-in"><div class="ic"><?= icon('shuffle', 14) ?></div>لا حركات بعد — اضغط «حركة جديدة» للبدء.</div></div>
  <?php else: foreach ($movements as $mv): include __DIR__ . '/_movement.php'; endforeach; endif; ?>
</div>

<?php if ($receiptMoveTargets): ?>
<div id="receiptTransferFormWrap" class="receipt-transfer-wrap" hidden>
  <form id="receiptTransferForm" method="post" action="">
    <?= csrfField() ?>
    <div class="receipt-transfer-summary" id="receiptTransferSummary" aria-live="polite"></div>
    <label class="receipt-transfer-target" for="receiptTransferTarget">
      <span>الإذن والحركة الهدف <b aria-hidden="true">*</b></span>
      <select id="receiptTransferTarget" name="target_movement_id" required>
        <option value="">اختر الإذن الهدف…</option>
        <?php $lastTargetPermit = null; foreach ($receiptMoveTargets as $target):
          $targetPid = (int)$target['permit_id'];
          if ($lastTargetPermit !== $targetPid):
            if ($lastTargetPermit !== null): ?></optgroup><?php endif;
            $targetNo = $target['permit_no'] ?: '#'.$targetPid;
            $targetParty = $target['company'] ?: ($target['client'] ?: 'بدون شركة');
            $targetContainerType = trim((string)($target['container_type'] ?? ''));
            $targetContainerCount = (int)($target['containers'] ?? 0);
            $targetContainers = ($targetContainerType !== '' || $targetContainerCount > 0)
              ? containerLabel($targetContainerType, $targetContainerCount)
              : 'غير محددة'; ?>
            <optgroup label="<?= e(Permit::noLabel($target['permit_type']).' '.$targetNo.' — '.$targetParty.' — الحاويات: '.$targetContainers) ?>">
          <?php $lastTargetPermit = $targetPid; endif; ?>
          <option value="<?= (int)$target['movement_id'] ?>"><?= e(Permit::noLabel($target['permit_type']).' '.$targetNo.' — '.$targetParty.' — الحاويات: '.$targetContainers.' — حركة '.intf($target['movement_seq'])) ?><?= trim((string)($target['movement_note'] ?? '')) !== '' ? ' — '.e($target['movement_note']) : '' ?></option>
        <?php endforeach; if ($lastTargetPermit !== null): ?></optgroup><?php endif; ?>
      </select>
    </label>
    <div class="receipt-transfer-note">سيُنقل نفس السجل دون تغيير المبلغ أو الكمية أو السعر الفعلي أو التاريخ أو المرجع أو المرفق أو وسيلة الدفع.</div>
    <div class="receipt-transfer-actions">
      <button class="btn gold" type="submit" id="receiptTransferSubmit" disabled>⇄ نقل الإيصال</button>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="permit-danger">
  <form class="confirm-delete" method="post" action="<?= url('permits/'.$pid.'/delete') ?>" data-confirm="حذف الإصدار بالكامل نهائياً؟ لا يمكن التراجع.">
    <?= csrfField() ?><button class="btn ghost sm danger" type="submit"><?= icon('trash', 14) ?> حذف الإصدار بالكامل</button>
  </form>
</div>
</div><!-- /.permit-manage -->

<datalist id="dl_driver"><?php foreach (($lookups['driver']??[]) as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist>
<datalist id="dl_owner"><?php foreach (($lookups['car_owner']??[]) as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist>
<datalist id="dl_owner_decl"><?php foreach (($declOwners ?? []) as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist>
<datalist id="dl_mv_expense"><?php foreach (($lookups['mv_expense']??[]) as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist>
<datalist id="dl_mv_receipt"><?php foreach (($lookups['mv_receipt']??[]) as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist>
<datalist id="dl_company"><?php foreach (($lookups['company']??[]) as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
<datalist id="dl_client"><?php foreach (($lookups['client']??[]) as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
<datalist id="dl_dest"><?php foreach (($lookups['destination']??[]) as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
<datalist id="dl_ctype"><?php foreach (($lookups['container_type']??[]) as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
<datalist id="dl_shipping"><?php foreach (($lookups['shipping_co']??[]) as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
<datalist id="dl_agency"><?php foreach (($lookups['agency']??[]) as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
<datalist id="dl_port"><?php foreach (($lookups['port']??[]) as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>

<script id="permitMaps" type="application/json"><?= js_json([
  'nameMap' => $nameMap,
  'carMap'  => $carMap,
  'companies' => array_values($lookups['company'] ?? []),
]) ?></script>

<script>
/* قدوم من صفحة العرض بزر «تعديل/إضافة سريع» — افتح المرحلة المستهدفة واعرض نموذجها */
(function(){
  if (!location.hash) return;
  var id = decodeURIComponent(location.hash.slice(1));
  var el = document.getElementById(id);
  if (!el) return;
  var d = el.closest('details'); if (d) d.open = true;
  if (el.tagName === 'DETAILS') el.open = true;
  if (el.classList.contains('mstage')) {
    var w = el.querySelector('.ms-edit-wrap'); if (w) w.hidden = false;
    var b = el.querySelector('.ms-body');      if (b) b.hidden = false;
    var add = el.querySelector('.tl-add');      if (add) add.open = true;
  }
  setTimeout(function(){
    el.scrollIntoView({block:'center'});
    el.classList.add('mv-flash');
    setTimeout(function(){ el.classList.remove('mv-flash'); }, 1300);
  }, 90);
})();
</script>
