<?php
use App\Models\Permit;
use App\Models\Bill;

/**
 * قالب حركة واحدة (يُستخدَم في صفحة الإدارة وفي ردود الـ AJAX الجزئية).
 * متغيّرات متوقّعة: $p, $mv, $mStages, $mvExpenseKeys, $ctTotal, $ctAllocated, $generators, $primaryDef
 */
$mid       = (int)$mv['id'];
$pid       = (int)$p['id'];
$type      = $p['permit_type'];
$mvBills   = $mv['bills']  ?? [];
$mvStagesG = $mv['stages'] ?? [];
$mvExpenseKeys = $mvExpenseKeys ?? ['receipts'];
$receiptMoveTargets = $receiptMoveTargets ?? [];
$canMoveReceipts = (($p['status'] ?? 'open') === 'open') && !empty($receiptMoveTargets);

// عدد/تصنيف حاويات الحركة (وصفي على مستوى الحركة — v25).
// العدّاد 📦 في بلوك البوليصة يستخدم عدد حاويات الحركة كمقام إن وُجد،
// وإلا يرجع لإجمالي حاويات الإذن (توافقًا مع الحركات القديمة).
$mvCtTotal = (int)($mv['container_count'] ?? 0);
$mvCtType  = trim((string)($mv['container_type'] ?? ''));

// كروت الفيزا (رصيد المكتب) — تُعرض تحت خانة «مدفوع بالفيزا» لنسب العملية لكارت.
// تظهر دائمًا (حتى بدون كروت تعرض رابط «أضف كارت») — بلا سكربت لأن الحركة تُعاد رسمها بالـ AJAX.
$visaField = function(?array $en) {
    $sel = (int)(is_array($en) ? ($en['visa_card_id'] ?? 0) : 0);
    return '<label class="ms-fld"><span>💳 كارت الفيزا (لو مدفوع بالفيزا)</span>'
         . visa_card_select($sel) . '</label>';
};

$statusBadge = fn(string $st) => $st === 'pending'
    ? '<span class="tl-st pending">ناقصة</span>' : '<span class="tl-st done">تمّت</span>';

// ---- إجماليات وحالة كل مرحلة (للستيبر + شريط التقدّم) ----
$mvExp = 0; $mvCust = 0; $mvCharged = 0;
foreach ($mvStagesG as $kk => $es) { foreach ($es as $e) {
    if (($e['status'] ?? '') === 'none') continue;
    if (in_array($kk, $mvExpenseKeys, true) || ($kk === 'custom' && ($e['direction'] ?? '') === 'expense')) $mvExp += (float)$e['amount'];
    if (isset($e['actual_amount']) && $e['actual_amount'] !== null && $e['actual_amount'] !== '') $mvCharged += (float)$e['actual_amount'];
} }
foreach ($mvBills as $b) { $mvCust += (float)$b['custody']; }

$stState = []; $mvDone = 0; $mvTot = 0;
foreach ($mStages as $k => $m) {
    $ents = $mvStagesG[$k] ?? [];
    $noneRow = null; $real = [];
    foreach ($ents as $en) { if (($en['status'] ?? '') === 'none') { $noneRow = $en; } else { $real[] = $en; } }
    $none = ($noneRow !== null && !$real);
    if ($k === 'bills')          { $has = !empty($mvBills); }
    elseif ($k === 'generators') { $has = !empty($generators); }
    else                         { $has = !empty($real) || $none; }
    $stState[$k] = ['has'=>$has, 'none'=>$none, 'real'=>$real, 'noneRow'=>$noneRow];
    if ($k !== 'generators') { $mvTot++; if ($has) $mvDone++; }
}
$mvProg = $mvTot ? (int) round($mvDone / $mvTot * 100) : 0;

// ============ فورم بند إيصال (إضافة/تعديل) — مبلغ + فعلي ظاهرين، الباقي تحت «المزيد» ============
$receiptForm = function(string $key, ?array $en, int $mid) use ($pid, $visaField) {
    $isEdit = $en !== null;
    ob_start(); ?>
    <form class="ms-form js-ajax" method="post" action="<?= url('permits/'.$pid.'/stage') ?>" enctype="multipart/form-data">
      <?= csrfField() ?>
      <input type="hidden" name="stage_key" value="<?= e($key) ?>">
      <input type="hidden" name="movement_id" value="<?= $mid ?>">
      <?php if ($isEdit): ?><input type="hidden" name="stage_id" value="<?= (int)$en['id'] ?>"><?php endif; ?>
      <div class="ms-quick">
        <label class="ms-fld"><span>المبلغ</span>
          <input type="number" step="0.01" name="amount" value="<?= $isEdit ? e($en['amount']) : '' ?>" placeholder="0" required></label>
        <label class="ms-fld"><span>الفعلي (للعميل)</span>
          <input type="number" step="0.01" name="actual_amount" value="<?= $isEdit ? e($en['actual_amount'] ?? '') : '' ?>" placeholder="0"></label>
        <button class="btn gold sm" type="submit">💾 <?= $isEdit ? 'حفظ' : 'إضافة' ?></button>
        <button type="button" class="btn ghost sm ms-more-btn">المزيد ▾</button>
      </div>
      <div class="ms-more" hidden>
        <label class="ms-fld"><span>التاريخ</span>
          <input type="date" name="entry_date" value="<?= $isEdit ? e($en['entry_date']) : work_date() ?>"></label>
        <label class="ms-fld"><span>رقم مرجعي</span>
          <input name="ref_no" value="<?= $isEdit ? e($en['ref_no']) : '' ?>"></label>
        <label class="ms-fld"><span>الحالة</span>
          <select name="status">
            <option value="done" <?= $isEdit && $en['status']==='done' ? 'selected' : '' ?>>تمّت</option>
            <option value="pending" <?= $isEdit && $en['status']==='pending' ? 'selected' : '' ?>>ناقصة</option>
          </select></label>
        <label class="ms-fld wide"><span>البيان</span>
          <input name="note" value="<?= $isEdit ? e($en['note']) : '' ?>"></label>
        <label class="ms-pay"><input type="checkbox" name="is_visa" value="1" <?= $isEdit && !empty($en['is_visa']) ? 'checked' : '' ?>> 💳 مدفوع بالفيزا</label>
        <?= $visaField($en) ?>
        <label class="ms-fld wide"><span>مرفق (صورة / PDF)</span>
          <input type="file" name="attachment" accept="image/*,application/pdf"></label>
      </div>
    </form>
    <?php return ob_get_clean();
};

// ============ فورم إيصال (إضافة/تعديل) بكمية — الإجمالي = سعر الوحدة × الكمية ============
// المخزَّن في amount هو الإجمالي؛ لذلك عند التعديل نعرض سعر الوحدة = amount ÷ qty.
$receiptEntryForm = function(string $key, ?array $en, int $mid, bool $hidden = false) use ($pid, $visaField) {
    $isEdit = $en !== null;
    $qty    = $isEdit ? max(1, (int)($en['qty'] ?? 1)) : 1;
    $unit   = $isEdit ? round((float)$en['amount'] / $qty, 2) : '';
    $unitActual = ($isEdit && isset($en['actual_amount']) && $en['actual_amount'] !== null && $en['actual_amount'] !== '')
        ? round((float)$en['actual_amount'] / $qty, 2) : '';
    ob_start(); ?>
    <form class="ms-form js-ajax<?= $isEdit ? '' : ' ms-add-form' ?>" method="post" action="<?= url('permits/'.$pid.'/stage') ?>" enctype="multipart/form-data" <?= $hidden?'hidden':'' ?>>
      <?= csrfField() ?>
      <input type="hidden" name="stage_key" value="<?= e($key) ?>">
      <input type="hidden" name="movement_id" value="<?= $mid ?>">
      <?php if ($isEdit): ?><input type="hidden" name="stage_id" value="<?= (int)$en['id'] ?>"><?php endif; ?>
      <div class="ms-quick">
        <label class="ms-fld wide"><span>اسم الإيصال (البيان)</span>
          <input list="dl_mv_receipt" name="note" value="<?= $isEdit ? e($en['note']) : '' ?>" placeholder="تخصيص / حمولة / تعتيق / هيئة / رسوم محصّلة"></label>
        <label class="ms-fld"><span>الكمية</span>
          <input type="number" min="1" step="1" name="qty" value="<?= e($qty) ?>" class="js-rc-qty" style="max-width:80px"></label>
        <label class="ms-fld"><span>سعر الوحدة</span>
          <input type="number" step="0.01" name="amount" value="<?= e($unit) ?>" placeholder="0" class="js-rc-unit" required></label>
        <label class="ms-fld"><span>الفعلي للوحدة (للعميل)</span>
          <input type="number" step="0.01" name="actual_amount" value="<?= e($unitActual) ?>" placeholder="0"></label>
        <span class="rc-total">الإجمالي: <b class="js-rc-total neg">0</b></span>
        <button class="btn gold sm" type="submit">💾 <?= $isEdit ? 'حفظ' : 'إضافة' ?></button>
        <button type="button" class="btn ghost sm ms-more-btn">المزيد ▾</button>
      </div>
      <div class="ms-more" hidden>
        <label class="ms-fld"><span>التاريخ</span><input type="date" name="entry_date" value="<?= $isEdit ? e($en['entry_date']) : work_date() ?>"></label>
        <label class="ms-fld"><span>رقم مرجعي</span><input name="ref_no" value="<?= $isEdit ? e($en['ref_no']) : '' ?>"></label>
        <label class="ms-fld"><span>الحالة</span>
          <select name="status">
            <option value="done" <?= $isEdit && $en['status']==='done' ? 'selected' : '' ?>>تمّت</option>
            <option value="pending" <?= $isEdit && $en['status']==='pending' ? 'selected' : '' ?>>ناقصة</option>
          </select></label>
        <label class="ms-pay"><input type="checkbox" name="is_visa" value="1" <?= $isEdit && !empty($en['is_visa']) ? 'checked' : '' ?>> 💳 مدفوع بالفيزا</label>
        <?= $visaField($en) ?>
        <label class="ms-fld wide"><span>مرفق (صورة / PDF)</span><input type="file" name="attachment" accept="image/*,application/pdf"></label>
      </div>
    </form>
    <?php return ob_get_clean();
};

// ============ صف مرحلة إيصال (قيمة واحدة) ============
$receiptRow = function(string $key, array $st, array $S, int $mid) use ($receiptForm, $statusBadge, $pid) {
    $dirCls = $st['dir']===Permit::DIR_EXPENSE ? 'neg' : ($st['dir']===Permit::DIR_REVENUE ? 'pos' : '');
    ob_start(); ?>
    <div class="mstage <?= $S['has']?'has':'' ?>" id="stage-<?= e($key) ?>-<?= $mid ?>">
      <div class="ms-head">
        <span class="ms-ic <?= $S['has']&&!$S['none']?'ok':'' ?>"><?= $S['has']&&!$S['none']?'✓':$st['icon'] ?></span>
        <span class="ms-name"><?= e($st['label']) ?></span>
        <?php if ($st['dir']===Permit::DIR_EXPENSE): ?><span class="tl-dir neg">مصروف</span>
        <?php elseif ($st['dir']===Permit::DIR_REVENUE): ?><span class="tl-dir pos">محصّل</span><?php endif; ?>
        <span class="ms-sp"></span>
        <?php if ($S['none']): ?>
          <span class="ss-none">🚫 لا يوجد</span>
          <form class="js-ajax" method="post" action="<?= url('permits/stage/'.$S['noneRow']['id'].'/delete') ?>" style="margin:0"><?= csrfField() ?><button class="btn ghost xs" type="submit" title="إلغاء التعليم">↩️</button></form>
        <?php elseif ($S['real']): $en = $S['real'][0]; ?>
          <b class="ms-amt <?= $dirCls ?>"><?= money($en['amount'],false) ?></b>
          <?php if (isset($en['actual_amount']) && $en['actual_amount']!==null && $en['actual_amount']!==''): ?><span class="te-actual" title="السعر الفعلي (للعميل)">👤 <?= money($en['actual_amount'],false) ?></span><?php endif; ?>
          <?php if (!empty($en['is_visa'])): ?><span class="tl-visa-badge">💳 فيزا</span><?php endif; ?>
          <?php if ($en['attachment']): ?><a class="te-clip" href="<?= url('attachments/stage/'.(int)$en['id']) ?>" target="_blank" title="المرفق">📎</a><?php endif; ?>
          <?= $statusBadge($en['status']) ?>
          <button type="button" class="btn ghost xs ms-edit-btn" title="تعديل">✏️</button>
          <form class="js-ajax confirm-delete" method="post" action="<?= url('permits/stage/'.$en['id'].'/delete') ?>" style="margin:0"><?= csrfField() ?><button class="btn ghost xs" style="color:var(--red)" type="submit" title="حذف">🗑️</button></form>
        <?php endif; ?>
      </div>
      <?php if ($S['real']): ?>
        <div class="ms-edit-wrap" hidden><?= $receiptForm($key, $S['real'][0], $mid) ?></div>
      <?php elseif (!$S['none']): ?>
        <div class="ms-body">
          <?= $receiptForm($key, null, $mid) ?>
          <form class="js-ajax ms-none-form" method="post" action="<?= url('permits/'.$pid.'/stage') ?>">
            <?= csrfField() ?>
            <input type="hidden" name="stage_key" value="<?= e($key) ?>">
            <input type="hidden" name="movement_id" value="<?= $mid ?>">
            <input type="hidden" name="mark_none" value="1">
            <button class="btn ghost xs" type="submit" title="علّم كمكتمل بلا بند">🚫 لا يوجد</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
    <?php return ob_get_clean();
};

// ============ فورم بند مخصّص (إضافة/تعديل) — باتجاه ============
$customForm = function(string $key, ?array $en, int $mid, bool $hidden = false) use ($pid, $visaField) {
    $isEdit = $en !== null;
    $dir = $isEdit ? ($en['direction'] ?: 'expense') : 'expense';
    ob_start(); ?>
    <form class="ms-form js-ajax<?= $isEdit ? '' : ' ms-add-form' ?>" method="post" action="<?= url('permits/'.$pid.'/stage') ?>" enctype="multipart/form-data" <?= $hidden?'hidden':'' ?>>
      <?= csrfField() ?>
      <input type="hidden" name="stage_key" value="<?= e($key) ?>">
      <input type="hidden" name="movement_id" value="<?= $mid ?>">
      <?php if ($isEdit): ?><input type="hidden" name="stage_id" value="<?= (int)$en['id'] ?>"><?php endif; ?>
      <div class="ms-quick">
        <label class="ms-fld"><span>الاتجاه</span>
          <select name="direction">
            <option value="expense" <?= $dir==='expense'?'selected':'' ?>>مصروف</option>
            <option value="revenue" <?= $dir==='revenue'?'selected':'' ?>>محصّل</option>
          </select></label>
        <label class="ms-fld"><span>المبلغ</span><input type="number" step="0.01" name="amount" value="<?= $isEdit ? e($en['amount']) : '' ?>" placeholder="0" required></label>
        <label class="ms-fld"><span>الفعلي</span><input type="number" step="0.01" name="actual_amount" value="<?= $isEdit ? e($en['actual_amount'] ?? '') : '' ?>" placeholder="0"></label>
        <label class="ms-fld wide"><span>اسم البند</span><input list="dl_mv_expense" name="note" value="<?= $isEdit ? e($en['note']) : '' ?>" placeholder="أرضية / غرامة / عمولة"></label>
        <button class="btn gold sm" type="submit">💾 <?= $isEdit ? 'حفظ' : 'إضافة' ?></button>
        <button type="button" class="btn ghost sm ms-more-btn">المزيد ▾</button>
      </div>
      <div class="ms-more" hidden>
        <label class="ms-fld"><span>التاريخ</span><input type="date" name="entry_date" value="<?= $isEdit ? e($en['entry_date']) : work_date() ?>"></label>
        <label class="ms-fld"><span>رقم مرجعي</span><input name="ref_no" value="<?= $isEdit ? e($en['ref_no']) : '' ?>"></label>
        <label class="ms-pay"><input type="checkbox" name="is_visa" value="1" <?= $isEdit && !empty($en['is_visa']) ? 'checked' : '' ?>> 💳 فيزا</label>
        <?= $visaField($en) ?>
        <label class="ms-fld wide"><span>مرفق</span><input type="file" name="attachment" accept="image/*,application/pdf"></label>
      </div>
    </form>
    <?php return ob_get_clean();
};

// ============ بلوك البنود المخصّصة (متعدّد، باتجاه) ============
$customBlock = function(string $key, array $st, array $S, int $mid) use ($statusBadge, $customForm, $pid) {
    $real = $S['real'];
    ob_start(); ?>
    <div class="mstage <?= $real?'has':'' ?>" id="stage-<?= e($key) ?>-<?= $mid ?>">
      <div class="ms-head">
        <span class="ms-ic <?= $real?'ok':'' ?>"><?= $real?'✓':$st['icon'] ?></span>
        <span class="ms-name"><?= e($st['label']) ?></span>
        <span class="ms-sp"></span>
        <?php if ($real): ?><span class="ss-count"><?= intf(count($real)) ?></span><?php endif; ?>
        <button type="button" class="btn ghost xs ms-add-btn" title="إضافة بند">➕</button>
      </div>
      <div class="ms-body">
        <?php if ($real): ?>
          <div class="ms-entries">
            <?php foreach ($real as $en): $eDir = $en['direction'] ?: 'expense'; ?>
              <div class="ms-entry-wrap">
                <div class="ms-entry">
                  <span class="te-date"><?= dfmt($en['entry_date']) ?></span>
                  <b class="ms-amt <?= $eDir==='revenue'?'pos':'neg' ?>"><?= money($en['amount'],false) ?></b>
                  <span class="tl-dir <?= $eDir==='revenue'?'pos':'neg' ?>"><?= $eDir==='revenue'?'محصّل':'مصروف' ?></span>
                  <?php if(isset($en['actual_amount'])&&$en['actual_amount']!==null&&$en['actual_amount']!==''): ?><span class="te-actual">👤 <?= money($en['actual_amount'],false) ?></span><?php endif; ?>
                  <?php if(!empty($en['is_visa'])): ?><span class="tl-visa-badge">💳</span><?php endif; ?>
                  <?php if($en['note']): ?><span class="te-note"><?= e($en['note']) ?></span><?php endif; ?>
                  <?php if($en['attachment']): ?><a class="te-clip" href="<?= url('attachments/stage/'.(int)$en['id']) ?>" target="_blank">📎</a><?php endif; ?>
                  <span class="ms-sp"></span>
                  <button type="button" class="btn ghost xs ms-edit-btn" title="تعديل البند">✏️</button>
                  <form class="js-ajax confirm-delete" method="post" action="<?= url('permits/stage/'.$en['id'].'/delete') ?>" style="margin:0"><?= csrfField() ?><button class="btn ghost xs" style="color:var(--red)" type="submit">🗑️</button></form>
                </div>
                <div class="ms-edit-wrap" hidden><?= $customForm($key, $en, $mid) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?= $customForm($key, null, $mid, true) ?>
      </div>
    </div>
    <?php return ob_get_clean();
};

// ============ بلوك الإيصالات (متعدّد — كل إيصال باسمه ومبلغه) ============
$receiptsBlock = function(string $key, array $st, array $S, int $mid) use ($statusBadge, $receiptEntryForm, $pid, $canMoveReceipts) {
    $real = $S['real'];
    ob_start(); ?>
    <div class="mstage <?= $real?'has':'' ?>" id="stage-<?= e($key) ?>-<?= $mid ?>">
      <div class="ms-head">
        <span class="ms-ic <?= $real?'ok':'' ?>"><?= $real?'✓':$st['icon'] ?></span>
        <span class="ms-name"><?= e($st['label']) ?></span>
        <span class="tl-dir neg">مصروف</span>
        <span class="ms-sp"></span>
        <?php if ($real): ?><span class="ss-count"><?= intf(count($real)) ?></span>
          <b class="ms-amt neg"><?= money(array_sum(array_map(fn($x)=>(float)$x['amount'],$real)),false) ?></b><?php endif; ?>
        <button type="button" class="btn gold xs ms-add-btn" title="إضافة إيصال">➕ إيصال</button>
      </div>
      <div class="ms-body">
        <?php if ($real): ?>
          <div class="ms-entries">
            <?php foreach ($real as $en): $eq = max(1,(int)($en['qty'] ?? 1)); ?>
              <div class="ms-entry-wrap">
                <div class="ms-entry">
                  <span class="te-date"><?= dfmt($en['entry_date']) ?></span>
                  <b class="te-name"><?= e($en['note'] ?: 'إيصال') ?></b>
                  <?php if($eq>1): ?><span class="te-qty" title="الكمية × سعر الوحدة"><?= intf($eq) ?> × <?= money(round((float)$en['amount']/$eq,2),false) ?></span><?php endif; ?>
                  <b class="ms-amt neg"><?= money($en['amount'],false) ?></b>
                  <?php if(isset($en['actual_amount'])&&$en['actual_amount']!==null&&$en['actual_amount']!==''): ?><span class="te-actual" title="السعر الفعلي (للعميل)">👤 <?= money($en['actual_amount'],false) ?></span><?php endif; ?>
                  <?php if(!empty($en['is_visa'])): ?><span class="tl-visa-badge">💳</span><?php endif; ?>
                  <?php if($en['ref_no']): ?><span class="te-ref">#<?= e($en['ref_no']) ?></span><?php endif; ?>
                  <?= $statusBadge($en['status']) ?>
                  <?php if($en['attachment']): ?><a class="te-clip" href="<?= url('attachments/stage/'.(int)$en['id']) ?>" target="_blank" title="المرفق">📎</a><?php endif; ?>
                  <span class="ms-sp"></span>
                  <?php if($canMoveReceipts): ?><button type="button" class="btn ghost xs receipt-transfer-btn"
                    data-transfer-action="<?= url('permits/stage/'.$en['id'].'/move') ?>"
                    data-receipt-label="<?= e($en['note'] ?: 'إيصال') ?>"
                    data-receipt-date="<?= e(dfmt($en['entry_date'])) ?>"
                    data-receipt-amount="<?= e(money($en['amount'],false)) ?>"
                    title="نقل الإيصال إلى إذن آخر">⇄ نقل</button><?php endif; ?>
                  <button type="button" class="btn ghost xs ms-edit-btn" title="تعديل الإيصال">✏️</button>
                  <form class="js-ajax confirm-delete" method="post" action="<?= url('permits/stage/'.$en['id'].'/delete') ?>" style="margin:0"><?= csrfField() ?><button class="btn ghost xs" style="color:var(--red)" type="submit" title="حذف الإيصال">🗑️</button></form>
                </div>
                <div class="ms-edit-wrap" hidden><?= $receiptEntryForm($key, $en, $mid) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?= $receiptEntryForm($key, null, $mid, true) ?>
      </div>
    </div>
    <?php return ob_get_clean();
};

// ============ شبكة حقول البوليصة (مشتركة بين الإنشاء والتعديل) ============
// $bl = بيانات البوليصة عند التعديل، أو null للإنشاء.
$billGrid = function(?array $bl, array $p) {
    // فصل رقم السيارة إلى حروف + أرقام (للتعبئة عند التعديل)
    $carLetters = ''; $carDigits = '';
    if ($bl && !empty($bl['car_no'])) {
        if (preg_match('/^(.*?)[\s-]*(\d+)\s*$/u', (string)$bl['car_no'], $m)) { $carLetters = trim($m[1]); $carDigits = $m[2]; }
        else { $carLetters = trim((string)$bl['car_no']); }
    }
    $__disc = array_column(\App\Schema::get('bawalis')['fields'], null, 'key')['discount'] ?? [];
    $__discDefault = (float)($__disc['default'] ?? 0);
    $v = fn($k, $d = '') => $bl ? e($bl[$k] ?? $d) : e($d);
    $exceptId = $bl ? (int)$bl['id'] : 0;
    ob_start(); ?>
    <div class="bill-form">
      <!-- بيانات البوليصة -->
      <section class="bf-sec">
        <div class="bf-head"><span class="bf-ic">📄</span> بيانات البوليصة</div>
        <div class="bf-grid">
          <div class="bf-fld field">
            <label>رقم البوليصة</label>
            <input name="bill_number" value="<?= $v('bill_number') ?>" autocomplete="off" inputmode="numeric"
                   data-bill-number-check data-except="<?= $exceptId ?>" placeholder="أدخل رقم البوليصة">
            <small class="field-live-message" data-bill-number-message></small>
          </div>
          <div class="bf-fld">
            <label>التاريخ</label>
            <input type="date" name="bill_date" value="<?= $bl ? e($bl['bill_date']) : e(work_date()) ?>">
          </div>
          <div class="bf-fld">
            <label>صاحب البيان <span class="opt">(اختياري)</span></label>
            <input list="dl_owner_decl" data-smart-name="owner" name="declaration_owner" value="<?= $v('declaration_owner') ?>" autocomplete="off" placeholder="اسم صاحب البيان">
          </div>
        </div>
      </section>

      <!-- السائق والسيارة -->
      <section class="bf-sec">
        <div class="bf-head"><span class="bf-ic">🚚</span> السائق والسيارة</div>
        <div class="bf-grid">
          <div class="bf-fld">
            <label>السائق</label>
            <input list="dl_driver" data-smart-name="driver" name="driver" value="<?= $v('driver') ?>" autocomplete="off" placeholder="اسم السائق">
          </div>
          <div class="bf-fld">
            <label>مالك السيارة</label>
            <input list="dl_owner" data-smart-name="car_owner" name="car_owner" class="js-car-owner" value="<?= $v('car_owner') ?>" autocomplete="off" placeholder="اسم المالك">
          </div>
          <div class="bf-fld bf-car">
            <label>رقم السيارة</label>
            <div class="bf-car-row">
              <input name="car_letters" class="js-car-letters" value="<?= e($carLetters) ?>" autocomplete="off" placeholder="أ ب ج">
              <input name="car_digits" class="js-car-digits" value="<?= e($carDigits) ?>" inputmode="numeric" autocomplete="off" placeholder="١٢٣٤">
            </div>
          </div>
        </div>
      </section>

      <!-- الحاويات -->
      <section class="bf-sec">
        <div class="bf-head"><span class="bf-ic">📦</span> الحاويات</div>
        <div class="bf-grid">
          <div class="bf-fld">
            <label>رقم الحاوية</label>
            <input name="container_no" value="<?= $v('container_no') ?>" autocomplete="off" placeholder="رقم الحاوية">
          </div>
          <div class="bf-fld">
            <label>تصنيف الحاويات</label>
            <input list="dl_ctype" data-smart-name="container_type" name="container_type" value="<?= $bl ? e($bl['container_type'] ?? '') : e($p['container_type'] ?? '') ?>" autocomplete="off" placeholder="40 قدم / 20 قدم">
          </div>
          <div class="bf-fld">
            <label>عدد الحاويات</label>
            <input type="number" min="0" name="container_count" value="<?= $bl ? e($bl['container_count']) : '' ?>" placeholder="0">
          </div>
        </div>
      </section>

      <!-- القيم المالية -->
      <section class="bf-sec bf-money">
        <div class="bf-head"><span class="bf-ic">💰</span> القيم المالية <span class="bf-cur">(ج.م)</span></div>
        <div class="bf-grid bf-grid-money">
          <div class="bf-fld"><label>النولون</label><input type="number" step="0.01" name="freight" value="<?= $bl ? e($bl['freight']) : '' ?>" placeholder="0"></div>
          <div class="bf-fld"><label>العهدة</label><input type="number" step="0.01" name="custody" value="<?= $bl ? e($bl['custody']) : '' ?>" placeholder="0"></div>
          <div class="bf-fld"><label>التحويلة</label><input type="number" step="0.01" name="transfer" value="<?= $bl ? e($bl['transfer']) : '' ?>" placeholder="0"></div>
          <div class="bf-fld"><label>الخصم</label><input type="number" step="0.01" name="discount" value="<?= $bl ? e($bl['discount']) : e($__discDefault) ?>"></div>
          <div class="bf-fld"><label>البياتة</label><input type="number" step="0.01" name="overnight_collection" value="<?= $bl ? e($bl['overnight_collection']) : '' ?>" placeholder="0"></div>
        </div>
      </section>
    </div>
    <?php return ob_get_clean();
};

// ============ بلوك البوالص (جدول + إنشاء/تعديل/ربط عبر نوافذ منبثقة) ============
$billsBlock = function(int $mid, array $bills) use ($pid, $p, $type, $ctTotal, $ctAllocated, $billGrid, $mvCtTotal) {
    // لو الحركة لها عدد حاويات خاص بها: العدّاد على مستوى الحركة (المخصص من بوالصها / عددها).
    // وإلا: يرجع لإجمالي الإذن (السلوك القديم للحركات بلا عدد).
    if ($mvCtTotal > 0) {
        $ctT = $mvCtTotal;
        $ctA = array_sum(array_map(fn($b) => (int)($b['container_count'] ?? 0), $bills));
    } else {
        $ctT = $ctTotal; $ctA = $ctAllocated;
    }
    $ctLeft = $ctT - $ctA;
    ob_start(); ?>
    <div class="mstage <?= $bills?'has':'' ?>" id="stage-bills-<?= $mid ?>">
      <div class="ms-head">
        <span class="ms-ic <?= $bills?'ok':'' ?>"><?= $bills?'✓':'🚛' ?></span>
        <span class="ms-name">البوليصة</span>
        <span class="ms-sp"></span>
        <?php if ($bills): ?><span class="ss-count"><?= intf(count($bills)) ?></span><?php endif; ?>
        <span class="ct-pill">📦 <?= intf($ctA) ?>/<?= intf($ctT) ?> · متبقّي <b class="<?= $ctLeft<0?'neg':'pos' ?>"><?= intf($ctLeft) ?></b></span>
        <button type="button" class="btn gold xs ms-add-btn" title="إنشاء بوليصة">➕ بوليصة</button>
      </div>
      <div class="ms-body">
        <?php if ($bills): ?>
          <div class="table-wrap"><table class="data">
            <thead><tr>
              <th class="g-identity">رقم البوليصة</th><th class="g-identity">التاريخ</th>
              <th class="g-vehicle">السائق</th><th class="g-vehicle">السيارة</th>
              <th class="g-identity">صاحب البيان</th>
              <th class="g-identity">الحاوية</th><th class="g-vehicle">العدد</th><th class="g-revenue">العهدة</th>
              <th class="g-result">الدين</th><th class="g-result">المتبقي</th><th class="g-identity">الحالة</th><th class="g-identity"></th>
            </tr></thead><tbody>
            <?php foreach ($bills as $bl): ?>
              <tr>
                <td style="font-weight:700"><?= e($bl['bill_number'] ?: '—') ?></td>
                <td><?= dfmt($bl['bill_date']) ?></td>
                <td><?= e($bl['driver']) ?></td>
                <td><?= e($bl['car_no']) ?></td>
                <td><?= $bl['declaration_owner'] ? e($bl['declaration_owner']) : '<span class="miss-chip">بلا صاحب بيان</span>' ?></td>
                <td><?= e($bl['container_no'] ?: '—') ?><?php if($bl['container_type']??''): ?> <span class="ctype-chip"><?= e($bl['container_type']) ?></span><?php endif; ?></td>
                <td><?= intf($bl['container_count'] ?? 0) ?></td>
                <td class="pos"><?= money($bl['custody'],false) ?></td>
                <td><?= money($bl['debt'],false) ?></td>
                <td class="<?= $bl['remaining']>0?'neg':'pos' ?>"><?= money($bl['remaining'],false) ?></td>
                <td><span class="pill st-<?= e($bl['pay_status']) ?>"><?= e(Bill::STATUS_LABELS[$bl['pay_status']] ?? '') ?></span></td>
                <td><div class="actions">
                  <a class="edit" href="<?= url('settlements/'.$bl['id']) ?>" title="سداد">💵</a>
                  <button type="button" class="btn ghost xs ms-bill-edit-btn" data-billedit="billedit-<?= (int)$bl['id'] ?>-<?= $mid ?>" title="تعديل البوليصة">✏️</button>
                  <form method="post" action="<?= url('permits/'.$pid.'/delete-bill') ?>" class="js-ajax confirm-delete" style="margin:0" data-confirm="حذف البوليصة <?= e($bl['bill_number'] ?: '') ?> نهائياً؟">
                    <?= csrfField() ?><input type="hidden" name="bill_id" value="<?= (int)$bl['id'] ?>">
                    <button class="del" type="submit" title="حذف نهائياً">🗑️</button>
                  </form>
                </div></td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>

          <?php // نماذج تعديل البوالص (مخفية — تُفتح في نافذة منبثقة) ?>
          <?php foreach ($bills as $bl): ?>
            <div class="ms-bill-edit-wrap" id="billedit-<?= (int)$bl['id'] ?>-<?= $mid ?>" hidden>
              <form class="js-ajax bill-form" method="post" action="<?= url('permits/'.$pid.'/update-bill') ?>">
                <?= csrfField() ?>
                <input type="hidden" name="bill_id" value="<?= (int)$bl['id'] ?>">
                <input type="hidden" name="lock_version" value="<?= (int)($bl['lock_version'] ?? 1) ?>">
                <input type="hidden" name="movement_id" value="<?= $mid ?>">
                <?= $billGrid($bl, $p) ?>
                <div class="ms-form-actions"><button class="btn gold sm" type="submit">💾 حفظ التعديل</button></div>
              </form>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

        <div class="bill-add" hidden>
          <form class="js-ajax bill-form" method="post" action="<?= url('permits/'.$pid.'/create-bill') ?>">
            <?= csrfField() ?><input type="hidden" name="movement_id" value="<?= $mid ?>">
            <?= $billGrid(null, $p) ?>
            <div class="ms-form-actions">
              <button class="btn gold sm" type="submit">➕ إنشاء وربط</button>
              <span class="bill-link-inline">
                أو
                <input class="bill-link-no" form="linkbill-<?= $mid ?>" name="bill_number" placeholder="رقم بوليصة موجودة" autocomplete="off">
                <button class="btn ghost sm" type="submit" form="linkbill-<?= $mid ?>">🔗 ربط</button>
              </span>
            </div>
          </form>
          <form id="linkbill-<?= $mid ?>" class="js-ajax" method="post" action="<?= url('permits/'.$pid.'/link-bill') ?>" style="display:none">
            <?= csrfField() ?><input type="hidden" name="movement_id" value="<?= $mid ?>">
          </form>
        </div>
      </div>
    </div>
    <?php return ob_get_clean();
};

// ============ بلوك المولدات (قراءة) ============
$generatorsBlock = function(array $generators, int $mid) {
    $has = !empty($generators);
    if (!$has) return '';
    ob_start(); ?>
    <div class="mstage has" id="stage-generators-<?= $mid ?>">
      <div class="ms-head">
        <span class="ms-ic ok">✓</span><span class="ms-name">المولدات</span>
        <span class="ms-sp"></span><span class="ss-count"><?= intf(count($generators)) ?></span>
        <button type="button" class="btn ghost xs ms-add-btn" title="عرض">👁</button>
      </div>
      <div class="ms-body" hidden>
        <div class="table-wrap"><table class="data">
          <thead><tr><th class="g-identity">التاريخ</th><th class="g-identity">الشركة</th>
            <th class="g-vehicle">العدد</th><th class="g-revenue">النولون</th><th class="g-result">المتبقي</th></tr></thead>
          <tbody><?php foreach ($generators as $g): ?>
            <tr><td><?= dfmt($g['gen_date']) ?></td><td style="text-align:right"><?= e($g['company']) ?></td>
              <td><?= intf($g['gen_count']) ?></td><td class="pos"><?= money($g['freight'],false) ?></td>
              <td class="<?= $g['remaining']>0?'neg':'pos' ?>"><?= money($g['remaining'],false) ?></td></tr>
          <?php endforeach; ?></tbody></table></div>
        <a class="btn ghost sm" href="<?= url('generators') ?>" style="margin-top:8px">⚙️ إدارة المولدات</a>
      </div>
    </div>
    <?php return ob_get_clean();
};
?>
<details class="movement card" id="movement-<?= $mid ?>" open>
  <summary class="mv-head">
    <span class="mv-seq"><?= icon('shuffle',16) ?> حركة #<?= intf($mv['seq']) ?></span>
    <?php if ($mv['note']): ?><span class="mv-note"><?= e($mv['note']) ?></span><?php endif; ?>
    <span class="mv-prog"><span class="mv-bar"><span style="width:<?= $mvProg ?>%"></span></span><small><?= $mvProg ?>%</small></span>
    <span style="flex:1"></span>
    <span class="mv-stats">
      <?php if ($mvCtTotal > 0 || $mvCtType !== ''): ?>
        <span title="حاويات الحركة">📦 <?= $mvCtTotal>0 ? intf($mvCtTotal) : '' ?><?= ($mvCtTotal>0 && $mvCtType!=='') ? ' · ' : '' ?><?= $mvCtType!=='' ? e($mvCtType) : '' ?></span>
      <?php endif; ?>
      <span title="عدد البوالص">🚛 <?= intf(count($mvBills)) ?></span>
      <span class="pos" title="العهدة">💰 <?= money($mvCust,false) ?></span>
      <span class="neg" title="المصروفات">💸 <?= money($mvExp,false) ?></span>
      <?php if ($mvCharged>0): ?><span class="chg" title="إجمالي السعر الفعلي (للعميل)">👤 <?= money($mvCharged,false) ?></span><?php endif; ?>
    </span>
    <form method="post" action="<?= url('permits/movement/'.$mid.'/delete') ?>" class="js-ajax confirm-delete mv-del" style="margin:0" onclick="event.stopPropagation()">
      <?= csrfField() ?><button class="btn ghost xs" type="submit" style="color:#ffb4a8" title="حذف الحركة">🗑️</button>
    </form>
    <span class="ss-chev">▾</span>
  </summary>
  <div class="mv-body">
    <!-- بيانات الحركة (وصفي): عدد/تصنيف الحاويات + ملاحظة — معزول عن أي حساب -->
    <form class="js-ajax mv-meta-form" method="post" action="<?= url('permits/movement/'.$mid.'/update') ?>"
          style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;padding:10px 4px 14px;border-bottom:1px dashed var(--line2);margin-bottom:12px">
      <?= csrfField() ?>
      <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--muted,#667)">عدد الحاويات
        <input type="number" min="0" name="container_count" value="<?= $mvCtTotal>0 ? intf($mvCtTotal) : '' ?>" placeholder="0" style="width:120px;border:1px solid var(--line2);border-radius:8px;padding:7px" autocomplete="off"></label>
      <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--muted,#667)">تصنيف الحاويات
        <input list="dl_ctype" name="container_type" value="<?= e($mvCtType) ?>" placeholder="40 قدم / 20 قدم" style="width:150px;border:1px solid var(--line2);border-radius:8px;padding:7px" autocomplete="off"></label>
      <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--muted,#667);flex:1;min-width:160px">ملاحظة الحركة
        <input name="note" value="<?= e($mv['note'] ?? '') ?>" placeholder="ملاحظة اختيارية" style="border:1px solid var(--line2);border-radius:8px;padding:7px" autocomplete="off"></label>
      <button class="btn ghost sm" type="submit" title="حفظ بيانات الحركة">💾 حفظ</button>
    </form>
    <!-- شريط المراحل الأفقي -->
    <div class="stepper">
      <?php $sn=0; foreach ($mStages as $k => $m): $sn++; $S = $stState[$k];
        $cls = $S['none'] ? 'done' : ($S['has'] ? 'done' : ''); ?>
        <a class="step-chip <?= $cls ?>" href="#stage-<?= e($k) ?>-<?= $mid ?>" data-scroll>
          <span class="sc-dot"><?= $S['has'] ? '✓' : $sn ?></span><span><?= e($m['label']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
    <!-- صفوف المراحل — المكتمل أولًا ثم غير المكتمل -->
    <div class="mstages">
      <?php
      $rows = [];
      foreach ($mStages as $k => $st):
        if     ($k === 'bills')      { $h = $billsBlock($mid, $mvBills); }
        elseif ($k === 'generators') { $h = $generatorsBlock($generators, $mid); }
        elseif ($k === 'receipts')   { $h = $receiptsBlock($k, $st, $stState[$k], $mid); }
        elseif ($k === 'custom')     { $h = $customBlock($k, $st, $stState[$k], $mid); }
        else                         { $h = $receiptRow($k, $st, $stState[$k], $mid); }
        if (trim($h) !== '') $rows[] = ['has' => !empty($stState[$k]['has']), 'html' => $h];
      endforeach;
      $doneH = ''; $pendH = '';
      foreach ($rows as $r) { if ($r['has']) $doneH .= $r['html']; else $pendH .= $r['html']; }
      echo $doneH . $pendH;
      ?>
    </div>
  </div>
</details>
