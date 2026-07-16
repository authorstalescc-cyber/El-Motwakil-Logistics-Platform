<?php
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

// ---- حساب التقدّم (مراحل الإذن + مراحل كل حركة) ----
$done = 0; $tot = 0;
foreach ($pStages as $k => $m) {
    if ($k === 'generators') continue;
    $tot++;
    $has = $k === 'data' ? true : !empty($permitStages[$k] ?? []);
    if ($has) $done++;
}
foreach ($movements as $mv) {
    foreach ($mStages as $k => $m) {
        if ($k === 'generators') continue;
        $tot++;
        $has = $k === 'bills' ? !empty($mv['bills']) : !empty($mv['stages'][$k] ?? []);
        if ($has) $done++;
    }
}
$progress = $tot ? (int) round($done / $tot * 100) : 0;

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
        <button class="btn gold sm" type="submit">💾 <?= $isEdit?'حفظ التعديل':'إضافة البند' ?></button>
        <?php if ($isEdit && !empty($entry['attachment'])): ?>
          <a class="btn ghost sm" href="<?= url($entry['attachment']) ?>" target="_blank">📎 عرض المرفق</a>
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
        <span style="flex:1"></span>
        <?php if ($isNone): ?><span class="ss-none">🚫 لا يوجد</span>
        <?php elseif (count($realEntries)): ?><span class="ss-count"><?= intf(count($realEntries)) ?></span>
        <?php elseif ($autoPrimary): ?><span class="ss-count" title="محسوبة تلقائيًا من حاويات الإذن">تلقائي</span><?php endif; ?>
        <?php if ($showTotal): ?><span class="ss-amt neg"><?= money($headTotal,false) ?></span><?php endif; ?>
        <span class="ss-chev">▾</span>
      </summary>
      <div class="stage-body">
        <?php if ($autoPrimary): ?>
          <div class="tl-auto">
            <div class="tl-auto-h">🪙 رسوم أولية محسوبة تلقائيًا من حاويات الإذن (<?= intf($primaryDef['containers']) ?> حاوية):</div>
            <div class="tl-auto-row"><span>الكشف</span><span><?= intf($primaryDef['containers']) ?> × <?= money($primaryDef['inspection_rate'],false) ?></span><b><?= money($primaryDef['inspection'],false) ?></b></div>
            <div class="tl-auto-row"><span>الضمان</span><span><?= intf($primaryDef['containers']) ?> × <?= money($primaryDef['guarantee_rate'],false) ?></span><b><?= money($primaryDef['guarantee'],false) ?></b></div>
            <div class="tl-auto-row tot"><span>الإجمالي</span><span></span><b><?= money($primaryDef['total'],false) ?></b></div>
          </div>
        <?php endif; ?>
        <?php if ($isNone): ?>
          <div class="tl-none">
            <span>🚫 هذه المرحلة معلَّمة <b>«لا يوجد»</b> ومحسوبة كمكتملة.</span>
            <form class="js-ajax" method="post" action="<?= url('permits/stage/'.$noneRow['id'].'/delete') ?>" style="margin:0">
              <?= csrfField() ?><button class="btn ghost sm" type="submit">↩️ إلغاء التعليم</button>
            </form>
          </div>
        <?php endif; ?>
        <?php if ($realEntries): foreach ($realEntries as $en): ?>
          <details class="tl-entry">
            <summary>
              <span class="te-date"><?= dfmt($en['entry_date']) ?></span>
              <span class="te-amt neg"><?= money($en['amount'],false) ?></span>
              <?php if(!empty($en['is_visa'])): ?><span class="tl-visa-badge">💳 فيزا</span><?php endif; ?>
              <?php if($en['note']): ?><span class="te-note"><?= e($en['note']) ?></span><?php endif; ?>
              <?= $statusBadge($en['status']) ?>
              <?php if($en['attachment']): ?><a class="te-clip" href="<?= url($en['attachment']) ?>" target="_blank" onclick="event.stopPropagation()">📎</a><?php endif; ?>
              <span class="te-edit">تعديل ▾</span>
            </summary>
            <?= $stageForm($key, $en) ?>
            <form class="js-ajax confirm-delete" method="post" action="<?= url('permits/stage/'.$en['id'].'/delete') ?>" style="margin:6px 0 0">
              <?= csrfField() ?><button class="btn ghost sm" type="submit" style="color:var(--red)">🗑️ حذف</button>
            </form>
          </details>
        <?php endforeach; endif; ?>
        <?php if (!$isNone): ?>
          <details class="tl-add">
            <summary>➕ <?= !$realEntries ? 'إضافة بند' : 'تعديل / إضافة بند' ?></summary>
            <?= $stageForm($key, null) ?>
          </details>
          <?php if (!$realEntries): ?>
            <form class="js-ajax tl-none-btn" method="post" action="<?= url('permits/'.$pid.'/stage') ?>">
              <?= csrfField() ?>
              <input type="hidden" name="stage_key" value="<?= e($key) ?>">
              <input type="hidden" name="mark_none" value="1">
              <button class="btn ghost sm" type="submit">🚫 لا يوجد — علّم كمكتمل</button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </details>
    <?php return ob_get_clean();
};
?>
<!-- شريط الإجراءات -->
<div class="permit-bar">
  <a class="btn ghost sm" href="<?= url('permits/'.$pid) ?>">👁 عرض الإذن</a>
  <span class="pb-title">إدارة: <?= Permit::typeLabel($type) ?> <b><?= e($p['permit_no'] ?: '#'.$pid) ?></b></span>
  <span class="pill <?= $isClosed?'st-paid':'st-partial' ?>"><?= $isClosed?'مقفول ✓':'جارٍ' ?></span>
  <span style="flex:1"></span>
  <a class="btn ghost sm" href="<?= url('permits/'.$pid.'/print') ?>" target="_blank">🖨️ طباعة المراجعة</a>
  <a class="btn ghost sm" href="<?= url('permits/'.$pid.'/invoice') ?>">🧾 إصدار فاتورة</a>
  <button class="btn sm" type="submit" form="permitDataForm">💾 حفظ الإذن</button>
  <form method="post" action="<?= url('permits/'.$pid.'/close') ?>" style="margin:0" <?= $isClosed?'':'class="confirm-financial" data-confirm="تأكيد إتمام وإغلاق الإذن؟"' ?>>
    <?= csrfField() ?>
    <button class="btn sm <?= $isClosed?'ghost':'gold' ?>" type="submit"><?= $isClosed?'↩️ إعادة فتح':'✅ إتمام الإذن' ?></button>
  </form>
</div>

<div class="permit-manage">
<!-- HERO -->
<div class="permit-hero card mb t-<?= $th['cls'] ?>">
  <div class="hero-main">
    <?php include __DIR__ . '/_hero_bg.php'; ?>
    <div class="hero-id">
      <span class="type-chip <?= $th['cls'] ?>"><span class="tc-arrow"><?= $th['arrow'] ?></span> <?= $th['full'] ?></span>
      <div class="hero-no"><span><?= e($noLabel) ?></span><h1><?= e($p['permit_no'] ?: '#'.$pid) ?></h1></div>
      <div class="hero-meta">
        <?php if($p['company']): ?><span class="hm-pill"><span class="hm-ic"><?= icon('building',16) ?></span><span class="hm-tx"><small>لصالح شركة</small><b><?= e($p['company']) ?></b></span></span><?php endif; ?>
        <?php if($p['client']): ?><span class="hm-pill"><span class="hm-ic"><?= icon('user',16) ?></span><span class="hm-tx"><small>الوكيل</small><b><?= e($p['client']) ?></b></span></span><?php endif; ?>
        <?php if($p['destination']): ?><span class="hm-pill"><span class="hm-ic"><?= icon('map-pin',16) ?></span><span class="hm-tx"><small>الجهة</small><b><?= e($p['destination']) ?></b></span></span><?php endif; ?>
        <?php if(!empty($p['port'])): ?><span class="hm-pill"><span class="hm-ic"><?= icon('anchor',16) ?></span><span class="hm-tx"><small>الميناء</small><b><?= e($p['port']) ?></b></span></span><?php endif; ?>
        <span class="hm-pill"><span class="hm-ic"><?= icon('calendar',16) ?></span><span class="hm-tx"><small>التاريخ</small><b><?= dfmt($p['permit_date']) ?: '—' ?></b></span></span>
        <?php if($ctLbl!==''): ?><span class="hm-pill"><span class="hm-ic"><?= icon('package',16) ?></span><span class="hm-tx"><small>الحاويات (عدد×تصنيف)</small><b><?= e($ctLbl) ?></b></span></span><?php endif; ?>
      </div>
    </div>
    <div class="hero-ring">
      <svg viewBox="0 0 36 36">
        <path class="ring-bg" pathLength="100" d="M18 2.5a15.5 15.5 0 1 1 0 31 15.5 15.5 0 1 1 0-31"/>
        <path class="ring-fill" pathLength="100" stroke-dasharray="<?= $progress ?>,100" d="M18 2.5a15.5 15.5 0 1 1 0 31 15.5 15.5 0 1 1 0-31"/>
      </svg>
      <div class="ring-lbl"><b><?= $progress ?>%</b><span><?= $done ?>/<?= $tot ?></span></div>
    </div>
  </div>
  <div class="hero-tiles">
    <div class="htile c-blue"><span class="ht-ic"><?= icon('package',20) ?></span><span class="ht-txt"><b><?= intf($p['containers'] ?? 0) ?></b><span class="ht-l">عدد الحاويات</span></span></div>
    <div class="htile c-teal"><span class="ht-ic"><?= icon('shuffle',20) ?></span><span class="ht-txt"><b><?= intf(count($movements)) ?></b><span class="ht-l">الحركات</span></span></div>
    <div class="htile c-indigo"><span class="ht-ic"><?= icon('truck',20) ?></span><span class="ht-txt"><b><?= intf($totals['bills']) ?></b><span class="ht-l">البوالص</span></span></div>
    <div class="htile c-green"><span class="ht-ic"><?= icon('banknote',20) ?></span><span class="ht-txt"><b class="pos"><?= money($totals['custody'],false) ?></b><span class="ht-l">العهدة</span></span></div>
    <div class="htile c-red"><span class="ht-ic"><?= icon('trending-down',20) ?></span><span class="ht-txt"><b class="neg"><?= money($totals['expense'],false) ?></b><span class="ht-l">المصروفات</span></span></div>
    <div class="htile c-gold"><span class="ht-ic"><?= icon('coins',20) ?></span><span class="ht-txt"><b><?= money($totals['charged'] ?? 0,false) ?></b><span class="ht-l">السعر الفعلي (للعميل)</span></span></div>
    <div class="htile c-purple"><span class="ht-ic"><?= icon('credit-card',20) ?></span><span class="ht-txt"><b><?= money($totals['debt'],false) ?></b><span class="ht-l">دين البوالص</span></span></div>
    <div class="htile c-amber"><span class="ht-ic"><?= icon('hourglass',20) ?></span><span class="ht-txt"><b class="<?= $totals['remaining']>0?'neg':'pos' ?>"><?= money($totals['remaining'],false) ?></b><span class="ht-l">المتبقي</span></span></div>
  </div>
</div>

<!-- ===== مرشد الإيصالات الذكي (استرشادي — v32) ===== -->
<?php $back = 'manage'; include __DIR__ . '/_rcp_guide.php'; ?>

<!-- ===== مراحل على مستوى الإذن (بيانات + رسوم أولية) ===== -->
<div class="sec-head"><span>🗂️ بيانات الإذن ورسومه</span></div>
<div class="stages mb">
  <?php $pi=0; foreach ($pStages as $key => $st): $pi++;
    $entries = $permitStages[$key] ?? [];
  ?>
    <?php if ($key === 'data'): ?>
      <details class="stage has" id="stage-data" open>
        <summary class="stage-sum">
          <span class="ss-ic ok">📄</span><span class="ss-num"><?= $pi ?></span>
          <span class="ss-name">بيانات الإذن</span><span style="flex:1"></span>
          <span class="ss-chev">▾</span>
        </summary>
        <div class="stage-body">
          <form id="permitDataForm" class="tl-form" method="post" action="<?= url('permits/'.$pid) ?>">
            <?= csrfField() ?>
            <input type="hidden" name="lock_version" value="<?= (int)($p['lock_version'] ?? 1) ?>">
            <div class="tl-fields">
              <label>النوع
                <select name="permit_type">
                  <?php foreach (Permit::TYPES as $tk=>$tv): ?>
                    <option value="<?= $tk ?>" <?= $type===$tk?'selected':'' ?>><?= e($tv['label']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label><?= e($noLabel) ?><input name="permit_no" value="<?= e($p['permit_no']) ?>" autocomplete="off"></label>
              <label>التاريخ<input type="date" name="permit_date" value="<?= e($p['permit_date']) ?>"></label>
              <label>عدد الحاويات (الإجمالي)<input type="number" min="0" name="containers" value="<?= e($p['containers'] ?? 0) ?>"></label>
              <label>تصنيف الحاويات<input list="dl_ctype" name="container_type" value="<?= e($p['container_type'] ?? '') ?>" placeholder="40 قدم / 20 قدم" autocomplete="off"></label>
              <label>سعر الكشف لكل حاوية<input type="number" step="0.01" min="0" name="inspection_rate" value="<?= e($p['inspection_rate'] ?? '') ?>" placeholder="0"></label>
              <label>سعر الضمان لكل حاوية<input type="number" step="0.01" min="0" name="guarantee_rate" value="<?= e($p['guarantee_rate'] ?? '') ?>" placeholder="0"></label>
              <label>الوكيل (العميل)<input list="dl_client" id="permit_client" name="client" value="<?= e($p['client']) ?>" autocomplete="off"></label>
              <label>لصالح شركة<input list="dl_company" id="permit_company" name="company" value="<?= e($p['company']) ?>" autocomplete="off"></label>
              <label>الجهة<input list="dl_dest" name="destination" value="<?= e($p['destination']) ?>" autocomplete="off"></label>
              <label>🚢 شركة الشحن<input list="dl_shipping" name="shipping_co" value="<?= e($p['shipping_co'] ?? '') ?>" autocomplete="off"></label>
              <label>🧾 التوكيل<input list="dl_agency" name="agency" value="<?= e($p['agency'] ?? '') ?>" autocomplete="off"></label>
              <label>⚓ الميناء<input list="dl_port" name="port" value="<?= e($p['port'] ?? '') ?>" autocomplete="off"></label>
              <label class="wide">ملاحظة<input name="note" value="<?= e($p['note']) ?>"></label>
            </div>
            <div class="tl-form-actions"><button class="btn gold sm" type="submit">💾 حفظ بيانات الإذن</button></div>
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
  <span>🔀 الحركات (<span id="mvCount"><?= intf(count($movements)) ?></span>)</span>
  <form method="post" action="<?= url('permits/'.$pid.'/movement') ?>" class="js-ajax mv-add-form" id="addMovementForm" style="margin:0;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <?= csrfField() ?>
    <input type="number" min="0" name="container_count" placeholder="عدد الحاويات" title="عدد حاويات الحركة (اختياري)" style="width:120px;border:1px solid var(--line2);border-radius:8px;padding:7px" autocomplete="off">
    <input list="dl_ctype" name="container_type" placeholder="التصنيف" title="تصنيف حاويات الحركة (اختياري)" style="width:130px;border:1px solid var(--line2);border-radius:8px;padding:7px" autocomplete="off">
    <button class="btn gold sm" type="submit">➕ حركة جديدة</button>
  </form>
</div>

<div id="movementsWrap">
  <?php if (!$movements): ?>
    <div class="card empty-mv"><div class="empty" style="padding:32px"><div class="ic">🔀</div>لا حركات بعد — اضغط «➕ حركة جديدة» للبدء.</div></div>
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
  <form class="confirm-delete" method="post" action="<?= url('permits/'.$pid.'/delete') ?>" style="margin:0" data-confirm="حذف الإصدار بالكامل نهائياً؟ لا يمكن التراجع.">
    <?= csrfField() ?><button class="btn ghost sm" type="submit" style="color:var(--red)">🗑️ حذف الإصدار بالكامل</button>
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

<script id="permitMaps" type="application/json"><?= json_encode([
  'nameMap' => $nameMap,
  'carMap'  => $carMap,
  'companies' => array_values($lookups['company'] ?? []),
], JSON_UNESCAPED_UNICODE) ?></script>

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
