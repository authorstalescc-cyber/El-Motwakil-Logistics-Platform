<?php
$recon = $reconciliation;
/* المبالغ المكتوبة اللي اترفضت — بترجع متعبّية بدل ما المالك يعيد كتابة
   عشرات الصفوف من أول وجديد (المرحلة ٢). */
/* ⚠️ الاسم `reconRestore` مقصود: الشاشة دي بتستخدم $reconState لحاجة تانية
   خالص (كلاس حالة المطابقة، سطر 23). تسمية مفتاح render بنفس اسم متغيّر
   جوّه الشاشة بتكسر واحد فيهم بالسكوت — نفس مصيدة $data الموثَّقة. */
$reconOld = $reconRestore['old'] ?? [];
$reconErr = $reconRestore['errors']['_form'] ?? '';
$reconEditable = !$isLocked && !empty($recon['available']);
$reconRows = $recon['rows'] ?? [];
$reconTotals = $recon['totals'] ?? [];
$general = $recon['general'] ?? ['system'=>0,'manual'=>0,'difference'=>0,'visa_excluded'=>0,'details'=>[]];
$dateObject = \DateTimeImmutable::createFromFormat('!Y-m-d', $date) ?: new \DateTimeImmutable(work_date());
$previousDate = $dateObject->modify('-1 day')->format('Y-m-d');
$nextDate = $dateObject->modify('+1 day')->format('Y-m-d');
$today = work_date();
$expenseCount = count($expenses);
$custodyCount = count($custodyRows);
$visaCount = count($visaRows);
$clientCount = count(array_filter($reconRows, fn($row) => empty($row['manual_only'])));
$differentClients = count(array_filter($reconRows, fn($row) => !empty($row['item_saved']) && empty($row['matched'])));
$systemCash = (float)($reconTotals['system_cash'] ?? 0);
$manualCash = (float)($reconTotals['manual_cash'] ?? 0);
$grandDifference = (float)($reconTotals['difference'] ?? 0);
$reconState = !$recon['available'] ? 'unavailable' : ($recon['matched'] ? 'matched' : ($recon['saved'] ? 'different' : 'new'));
?>

<div class="closing-page">
  <header class="cl-hero">
    <div class="cl-hero-main">
      <div class="cl-eyebrow">التقفيل اليومي</div>
      <h1>تقفيل يوم <?= e(dfmt($date)) ?></h1>
      <p>راجع الحركة، طابق اليدوي مع السيستم، ثم ثبّت رصيد اليوم.</p>
    </div>
    <div class="cl-hero-balance <?= $carried < 0 ? 'negative' : '' ?>">
      <span><?= $isClosed ? 'الرصيد المرحّل المثبّت' : 'الرصيد المرحّل الحالي' ?></span>
      <b><?= money($carried) ?></b>
      <small><?= $isClosed ? 'لن يتغيّر إلا بعد فتح الإقفال' : 'يتغيّر مع أي إدخال جديد' ?></small>
    </div>
    <div class="cl-hero-actions">
      <span class="cl-state <?= $isClosed ? 'closed' : 'open' ?>"><?= $isClosed ? '● يوم متقفل' : '● يوم مفتوح' ?></span>
      <a class="btn gold" href="<?= url('closing/print?date='.urlencode($date)) ?>" target="_blank">🖨️ طباعة</a>
    </div>
  </header>

  <nav class="cl-datebar" aria-label="اختيار تاريخ التقفيل">
    <a class="cl-day-arrow" href="<?= url('closing?date='.urlencode($previousDate)) ?>" title="اليوم السابق">‹</a>
    <form method="get" action="<?= url('closing') ?>">
      <label for="closingDate">التاريخ</label>
      <input id="closingDate" type="date" name="date" value="<?= e($date) ?>">
      <button class="btn" type="submit">عرض</button>
    </form>
    <a class="cl-today <?= $date === $today ? 'active' : '' ?>" href="<?= url('closing?date='.urlencode($today)) ?>">اليوم</a>
    <a class="cl-day-arrow" href="<?= url('closing?date='.urlencode($nextDate)) ?>" title="اليوم التالي">›</a>
  </nav>

  <section class="cl-progress" aria-label="خطوات التقفيل">
    <a class="cl-step done" href="#day-review"><i>1</i><span><b>راجع حركة اليوم</b><small><?= intf($expenseCount) ?> مصروف · <?= intf($custodyCount) ?> عهدة سائق</small></span></a>
    <span class="cl-progress-line <?= $recon['matched'] ? 'done' : '' ?>"></span>
    <a class="cl-step <?= $recon['matched'] ? 'done' : ($recon['saved'] ? 'warning' : 'current') ?>" href="#reconciliation"><i>2</i><span><b>طابق مع اليدوي</b><small><?= $recon['matched'] ? 'المطابقة كاملة' : ($recon['saved'] ? intf($differentClients).' عميل به فرق' : 'لم تُحفظ المطابقة') ?></small></span></a>
    <span class="cl-progress-line <?= $isClosed ? 'done' : '' ?>"></span>
    <a class="cl-step <?= $isClosed ? 'done' : ($recon['matched'] ? 'current' : 'locked') ?>" href="#final-close"><i>3</i><span><b>اقفل اليوم</b><small><?= $isClosed ? 'الرصيد تم تثبيته' : ($recon['matched'] ? 'جاهز للإقفال' : 'يتفعّل بعد المطابقة') ?></small></span></a>
  </section>

  <section class="cl-balance-card" aria-labelledby="balanceTitle">
    <div class="cl-section-title compact">
      <div><span>ملخص الحساب</span><h2 id="balanceTitle">معادلة رصيد اليوم</h2></div>
      <?php if ($isClosed): ?><span class="cl-lock-note">🔒 الأرقام مثبّتة</span><?php else: ?><span class="cl-live-note"><i></i> أرقام مباشرة</span><?php endif; ?>
    </div>
    <div class="cl-equation">
      <div class="cl-equation-item opening"><span>رصيد أول اليوم</span><b><?= money($prevBalance) ?></b></div>
      <i class="plus">+</i>
      <div class="cl-equation-item topup"><span>عهدة مضافة</span><b><?= money($totTopups) ?></b></div>
      <i>=</i>
      <div class="cl-equation-item available"><span>المتاح</span><b><?= money($available) ?></b></div>
      <i class="minus">−</i>
      <div class="cl-equation-item expense"><span>مصروفات نقدية</span><b><?= money($totExpense) ?></b></div>
      <i class="minus">−</i>
      <div class="cl-equation-item driver"><span>عهد السائقين</span><b><?= money($totCustodyExp) ?></b><small>بعد خصم <?= intf($rate) ?> لكل بوليصة</small></div>
      <i>=</i>
      <div class="cl-equation-item closing <?= $carried < 0 ? 'negative' : '' ?>"><span>المرحّل للغد</span><b><?= money($carried) ?></b></div>
    </div>
  </section>

  <section class="cl-section" id="day-review">
    <div class="cl-section-title">
      <span class="cl-section-no">1</span>
      <div><span>الخطوة الأولى</span><h2>راجع حركة اليوم</h2><p>الإجماليات ظاهرة أمامك؛ افتح التفاصيل فقط عند المراجعة.</p></div>
    </div>

    <div class="cl-review-cards">
      <details class="cl-review-card" <?= $expenseCount && !$recon['saved'] ? 'open' : '' ?>>
        <summary>
          <span class="cl-review-icon cash">💸</span>
          <span class="cl-review-name"><b>المصروفات النقدية</b><small><?= intf($expenseCount) ?> بند بتاريخ اليوم</small></span>
          <strong class="negative"><?= money($totExpense,false) ?></strong><span class="cl-expand">›</span>
        </summary>
        <div class="cl-review-body">
          <div class="table-wrap"><table class="data cl-detail-table" data-table-tools-off>
            <thead><tr><th>الإذن / المصدر</th><th>الشركة</th><th>البند</th><th>البيان</th><th>المرجع</th><th>المبلغ</th><th></th></tr></thead>
            <tbody><?php if (!$expenses): ?><tr><td colspan="7"><div class="empty">لا مصروفات نقدية في هذا اليوم.</div></td></tr><?php else: foreach ($expenses as $ex): ?><tr>
              <td><b><?= e($ex['permit_no'] ?: '#'.$ex['permit_id']) ?></b></td><td><?= e($ex['company'] ?: '—') ?></td><td><?= e($ex['stage_label']) ?></td><td><?= e($ex['note'] ?: '—') ?></td><td><?= e($ex['ref_no'] ?: '—') ?></td><td class="neg"><b><?= money($ex['amount'],false) ?></b></td>
              <td><?php if (!empty($ex['is_manual'])): ?><a class="edit" href="<?= url('expenses/'.$ex['manual_id'].'/edit') ?>" title="تعديل">✏️</a><?php elseif (!empty($ex['is_rep'])): ?><a class="edit" href="<?= url('reps/'.$ex['rep_id']) ?>" title="فتح حساب المندوب">🔎</a><?php else: ?><a class="edit" href="<?= url('permits/'.$ex['permit_id'].'#stage-'.$ex['stage_key']) ?>" title="فتح الإذن">🔎</a><?php endif; ?></td>
            </tr><?php endforeach; endif; ?></tbody>
            <?php if ($expenses): ?><tfoot><tr><td colspan="5">الإجمالي النقدي</td><td class="neg"><?= money($totExpense,false) ?></td><td></td></tr></tfoot><?php endif; ?>
          </table></div>
        </div>
      </details>

      <details class="cl-review-card">
        <summary>
          <span class="cl-review-icon driver">🚛</span>
          <span class="cl-review-name"><b>عهد السائقين</b><small><?= intf($custodyCount) ?> بوليصة · المخصوم بعد <?= intf($rate) ?></small></span>
          <strong class="negative"><?= money($totCustodyExp,false) ?></strong><span class="cl-expand">›</span>
        </summary>
        <div class="cl-review-body"><div class="table-wrap"><table class="data cl-detail-table" data-table-tools-off>
          <thead><tr><th>البوليصة</th><th>السائق</th><th>العهدة</th><th>المخصوم فعليًا</th></tr></thead>
          <tbody><?php if (!$custodyRows): ?><tr><td colspan="4"><div class="empty">لا عهد سائقين بتاريخ اليوم.</div></td></tr><?php else: foreach ($custodyRows as $c): ?><tr><td><b><?= e($c['bill_number'] ?: ('إذن '.$c['permit_no'])) ?></b></td><td><?= e($c['driver'] ?: '—') ?></td><td><?= money($c['custody'],false) ?></td><td class="neg"><b><?= money($c['deduction'],false) ?></b></td></tr><?php endforeach; endif; ?></tbody>
          <?php if ($custodyRows): ?><tfoot><tr><td colspan="2">الإجمالي</td><td><?= money($totCustody,false) ?></td><td class="neg"><?= money($totCustodyExp,false) ?></td></tr></tfoot><?php endif; ?>
        </table></div></div>
      </details>

      <details class="cl-review-card visa">
        <summary>
          <span class="cl-review-icon visa">💳</span>
          <span class="cl-review-name"><b>مدفوعات الفيزا المستبعدة</b><small><?= intf($visaCount) ?> بند للمراجعة ولا يُخصم من النقدية</small></span>
          <strong><?= money($totVisa,false) ?></strong><span class="cl-expand">›</span>
        </summary>
        <div class="cl-review-body"><div class="cl-info-line">هذه البنود مسجلة في السيستم، لكنها لا تدخل صندوق التقفيل النقدي.</div><div class="table-wrap"><table class="data cl-detail-table" data-table-tools-off>
          <thead><tr><th>الإذن / المصدر</th><th>الشركة</th><th>البند</th><th>البيان</th><th>المبلغ</th></tr></thead>
          <tbody><?php if (!$visaRows): ?><tr><td colspan="5"><div class="empty">لا توجد مدفوعات فيزا في هذا اليوم.</div></td></tr><?php else: foreach ($visaRows as $v): ?><tr><td><b><?= e($v['permit_no'] ?: '—') ?></b></td><td><?= e($v['company'] ?: '—') ?></td><td><?= e($v['stage_label']) ?></td><td><?= e($v['note'] ?: '—') ?></td><td><b><?= money($v['amount'],false) ?></b></td></tr><?php endforeach; endif; ?></tbody>
          <?php if ($visaRows): ?><tfoot><tr><td colspan="4">إجمالي الفيزا المستبعدة</td><td><?= money($totVisa,false) ?></td></tr></tfoot><?php endif; ?>
        </table></div></div>
      </details>
    </div>
  </section>

  <section class="cl-section cl-reconciliation" id="reconciliation">
    <?php if ($reconErr !== ''): ?>
      <div class="flash error" role="alert"><?= icon('alert',18) ?> ما اتحفظتش المطابقة: <?= e($reconErr) ?> — الأرقام اللي كتبتها زي ما هي تحت.</div>
    <?php endif; ?>
    <div class="cl-section-title">
      <span class="cl-section-no">2</span>
      <div><span>الخطوة الثانية</span><h2>طابق اليدوي مع السيستم</h2><p>كل عميل في بطاقة واحدة، مهما كان عدد أذوناته.</p></div>
      <span class="cl-recon-status <?= e($reconState) ?>"><?php if (!$recon['available']): ?>غير مفعّلة<?php elseif ($recon['matched']): ?>✓ مطابقة كاملة<?php elseif ($recon['saved']): ?>⚠ توجد فروق<?php else: ?>لم تُراجع بعد<?php endif; ?></span>
    </div>

    <?php if (!$recon['available']): ?>
      <div class="alert error">شغّل <b>php bin/migrate_v28.php</b> مرة واحدة لتفعيل المطابقة.</div>
    <?php else: ?>
    <form method="post" action="<?= url('closing/reconcile') ?>" id="reconForm">
      <?= csrfField() ?><input type="hidden" name="date" value="<?= e($date) ?>">

      <div class="cl-recon-summary">
        <div><span>عملاء اليوم</span><b><?= intf($clientCount) ?></b></div>
        <div><span>إجمالي السيستم النقدي</span><b><?= money($systemCash,false) ?></b></div>
        <div><span>إجمالي اليدوي</span><b id="manualCashTotal"><?= $recon['saved'] ? money($manualCash,false) : '—' ?></b></div>
        <div class="<?= $recon['saved'] && abs($grandDifference) >= .005 ? 'has-diff' : '' ?>"><span>الفرق النهائي</span><b id="reconGrandDiff"><?= $recon['saved'] ? money($grandDifference,false) : '—' ?></b></div>
      </div>

      <div class="cl-recon-rule"><b>ماذا تكتب؟</b><span>اكتب إجمالي ما عندك يدويًا للعميل. الفرق يظهر فورًا، ويمكنك فتح أذوناته لمعرفة مكانه.</span></div>

      <div class="cl-client-list">
        <?php if (!$reconRows): ?>
          <div class="cl-empty-state"><span>✓</span><b>لا توجد إيصالات أو مصروفات أذونات في هذا اليوم</b><small>راجع المصروفات العامة أدناه ثم احفظ المطابقة.</small></div>
        <?php else: foreach ($reconRows as $rr):
          $saved = !empty($rr['item_saved']);
          $manualOnly = !empty($rr['manual_only']);
          $state = $saved ? ($rr['matched'] ? 'is-match' : 'is-different') : 'is-new';
        ?>
          <article class="cl-client-card recon-row <?= $state ?>" data-system-total="<?= e($rr['system_total']) ?>">
            <div class="cl-client-main">
              <div class="cl-client-identity"><span class="cl-client-mark"><?= e(mb_substr($rr['client_name'],0,1)) ?></span><div><b><?= e($rr['client_name']) ?></b><small><?= intf(count($rr['permits'])) ?> إذن<?= $manualOnly ? ' · لم يعد له حركة اليوم' : '' ?></small></div></div>
              <div class="cl-client-number system"><span>السيستم</span><b><?= money($rr['system_total'],false) ?></b></div>
              <label class="cl-client-input"><span>اليدوي</span><input class="recon-input manual-total" type="number" min="0" step="0.01" inputmode="decimal" name="manual_total[<?= e($rr['client_key']) ?>]" value="<?= e($reconOld['manual_total'][$rr['client_key']] ?? ($saved ? number_format($rr['manual_total'],2,'.','') : '')) ?>" placeholder="0.00" <?= (!$reconEditable || $manualOnly) ? 'disabled' : '' ?>></label>
              <div class="cl-client-number difference"><span>الفرق</span><b class="client-diff <?= $saved && abs($rr['difference']) >= .005 ? 'has-diff' : '' ?>"><?= $saved ? money($rr['difference'],false) : '—' ?></b></div>
              <?php if ($rr['visa_excluded']): ?><div class="cl-visa-mini" title="مدفوعات فيزا مستبعدة">💳 <?= money($rr['visa_excluded'],false) ?></div><?php endif; ?>
            </div>
            <?php if (!$manualOnly): ?><label class="cl-client-note"><span>ملاحظة</span><input type="text" name="row_note[<?= e($rr['client_key']) ?>]" value="<?= e($reconOld['row_note'][$rr['client_key']] ?? $rr['note']) ?>" placeholder="سبب الفرق أو ملاحظة للمراجعة (اختياري)" <?= !$reconEditable ? 'disabled' : '' ?>></label><?php endif; ?>
            <?php if ($rr['permits']): ?><details class="cl-permit-details"><summary><span>عرض تفاصيل الأذونات</span><b><?= intf(count($rr['permits'])) ?></b></summary><div class="cl-permit-list">
              <?php foreach ($rr['permits'] as $permit): ?><article class="cl-permit-card">
                <header><div><a href="<?= url('permits/'.$permit['permit_id'].'/manage') ?>" target="_blank"><?= e($permit['permit_no'] ?: '#'.$permit['permit_id']) ?> ↗</a><span><?= e($permit['company'] ?: '—') ?></span></div><b><?= money($permit['system_total'],false) ?></b></header>
                <div class="table-wrap"><table><thead><tr><th>النوع</th><th>البيان</th><th>الحركة</th><th>الدفع</th><th>المبلغ</th></tr></thead><tbody><?php foreach ($permit['details'] as $detail): ?><tr><td><?= e($detail['bucket'] === 'receipt' ? 'إيصال' : $detail['stage_label']) ?></td><td><?= e($detail['note'] ?: ($detail['ref_no'] ?: '—')) ?></td><td><?= $detail['movement_seq'] ? 'حركة '.intf($detail['movement_seq']) : 'الإذن' ?></td><td><?= $detail['is_visa'] ? 'فيزا' : 'كاش' ?></td><td><b><?= money($detail['amount'],false) ?></b></td></tr><?php endforeach; ?></tbody></table></div>
              </article><?php endforeach; ?>
            </div></details><?php endif; ?>
          </article>
        <?php endforeach; endif; ?>
      </div>

      <article class="cl-general-card recon-row" data-system-total="<?= e($general['system']) ?>">
        <div class="cl-general-title"><span>🧾</span><div><b>مصروفات عامة بدون إذن</b><small>مصروفات يدوية + سُلف وتسويات مناديب نقدية</small></div></div>
        <div class="cl-client-number system"><span>السيستم</span><b><?= money($general['system'],false) ?></b></div>
        <label class="cl-client-input"><span>اليدوي</span><input class="recon-input manual-general" type="number" min="0" step="0.01" inputmode="decimal" name="manual_general_expenses" value="<?= e($reconOld['manual_general_expenses'] ?? ($recon['saved'] ? number_format($general['manual'],2,'.','') : '')) ?>" placeholder="0.00" <?= !$reconEditable ? 'disabled' : '' ?>></label>
        <div class="cl-client-number difference"><span>الفرق</span><b class="general-diff <?= $recon['saved'] && abs($general['difference']) >= .005 ? 'has-diff' : '' ?>"><?= $recon['saved'] ? money($general['difference'],false) : '—' ?></b></div>
        <?php if ($general['visa_excluded']): ?><div class="cl-visa-mini">💳 <?= money($general['visa_excluded'],false) ?></div><?php endif; ?>
        <?php if ($general['details']): ?><details class="cl-permit-details"><summary><span>عرض تفاصيل المصروفات العامة</span><b><?= intf(count($general['details'])) ?></b></summary><div class="cl-general-details table-wrap"><table><tbody><?php foreach ($general['details'] as $detail): ?><tr><td><?= e($detail['label']) ?></td><td><?= e($detail['party']) ?></td><td><?= e($detail['note'] ?: '—') ?></td><td><?= $detail['is_visa'] ? 'فيزا' : 'كاش' ?></td><td><b><?= money($detail['amount'],false) ?></b></td></tr><?php endforeach; ?></tbody></table></div></details><?php endif; ?>
      </article>

      <div class="cl-recon-footer">
        <label class="cl-main-note"><span>ملاحظة عامة للمطابقة</span><textarea name="reconciliation_note" rows="2" placeholder="اختيارية…" <?= !$reconEditable ? 'disabled' : '' ?>><?= e($reconOld['reconciliation_note'] ?? $recon['note']) ?></textarea></label>
        <div class="cl-final-difference <?= $recon['matched'] ? 'matched' : '' ?>">
          <span>الفرق النهائي بعد كل البنود</span><b id="reconGrandDiffBottom"><?= $recon['saved'] ? money($grandDifference,false) : '—' ?></b><small><?= $recon['matched'] ? '✓ الأرقام مطابقة' : 'يجب أن يصبح الفرق 0.00' ?></small>
        </div>
        <?php if ($reconEditable): ?><button class="btn gold cl-save-recon" type="submit">حفظ ومراجعة المطابقة</button><?php endif; ?>
      </div>
      <?php if ($recon['updated_at']): ?><div class="cl-recon-meta">آخر حفظ: <?= e($recon['updated_at']) ?><?= $recon['updated_by'] ? ' — بواسطة '.e($recon['updated_by']) : '' ?></div><?php endif; ?>
    </form>
    <?php endif; ?>
  </section>

  <section class="cl-section" id="final-close">
    <div class="cl-section-title">
      <span class="cl-section-no">3</span><div><span>الخطوة الأخيرة</span><h2>ثبّت رصيد اليوم</h2><p>بعد الإقفال لن يقبل السيستم إدخالات نقدية جديدة بتاريخ هذا اليوم.</p></div>
    </div>
    <div class="cl-close-panel <?= $isClosed ? 'is-closed' : ($recon['matched'] ? 'is-ready' : 'is-blocked') ?>">
      <div class="cl-close-symbol"><?= $isClosed ? '🔒' : ($recon['matched'] ? '✓' : '!') ?></div>
      <div class="cl-close-copy">
        <?php if ($isClosed): ?><b>يوم <?= e(dfmt($date)) ?> متقفل</b><span>تم تثبيت الرصيد المرحّل على <strong><?= money($carried) ?></strong><?= $closedBy ? ' بواسطة '.e($closedBy) : '' ?><?= $closedAt ? ' — '.e($closedAt) : '' ?>.</span>
        <?php elseif ($recon['matched']): ?><b>كل شيء جاهز للإقفال</b><span>المطابقة كاملة وسيتم تثبيت رصيد <strong><?= money($carried) ?></strong> كرصيد أول اليوم التالي.</span>
        <?php else: ?><b>الإقفال غير متاح حاليًا</b><span>احفظ المطابقة بدون فروق أولًا، ثم سيتفعّل زر إقفال اليوم.</span><?php endif; ?>
      </div>
      <div class="cl-close-action">
        <?php if ($isClosed && $canReopen): ?><form method="post" action="<?= url('closing/reopen') ?>" class="confirm-financial" data-confirm="فتح إقفال يوم <?= e(dfmt($date)) ?>؟ ستتمكّن من التعديل، ويجب إقفاله مرة أخرى."><?= csrfField() ?><input type="hidden" name="date" value="<?= e($date) ?>"><button class="btn" type="submit">🔓 فتح الإقفال</button></form>
        <?php elseif ($isClosed): ?><small>لفتح هذا اليوم، افتح الأيام الأحدث أولًا.<br>آخر يوم متقفل: <?= e(dfmt($lastClosed)) ?></small>
        <?php elseif ($recon['matched']): ?><form method="post" action="<?= url('closing/close') ?>" class="confirm-financial" data-confirm="إقفال يوم <?= e(dfmt($date)) ?> على رصيد <?= e(money($carried,false)) ?>؟ سيتم تثبيت الرقم ومنع الكتابة على هذا اليوم."><?= csrfField() ?><input type="hidden" name="date" value="<?= e($date) ?>"><button class="btn gold cl-close-button" type="submit">🔒 إقفال اليوم على <?= money($carried,false) ?></button></form>
        <?php else: ?><a class="btn" href="#reconciliation">اذهب إلى المطابقة ↑</a><?php endif; ?>
      </div>
    </div>
  </section>
</div>

<style>
.closing-page{display:grid;gap:18px;padding-bottom:28px}.cl-hero{position:relative;overflow:hidden;display:grid;grid-template-columns:minmax(280px,1fr) auto auto;align-items:center;gap:22px;padding:23px 25px;border-radius:20px;background:linear-gradient(125deg,#0b294b,#174b78 68%,#126248);color:#fff;box-shadow:0 12px 32px rgba(8,35,65,.18)}.cl-hero:after{content:"";position:absolute;width:260px;height:260px;border:50px solid rgba(255,255,255,.035);border-radius:50%;left:-100px;top:-120px}.cl-hero-main,.cl-hero-balance,.cl-hero-actions{position:relative;z-index:1}.cl-eyebrow{font-size:.69rem;color:#ead477;font-weight:900;letter-spacing:.4px}.cl-hero h1{margin:4px 0 5px;font-size:1.55rem}.cl-hero p{margin:0;color:rgba(255,255,255,.7);font-size:.8rem}.cl-hero-balance{display:grid;gap:2px;min-width:230px;padding:13px 17px;border:1px solid rgba(255,255,255,.16);border-radius:14px;background:rgba(255,255,255,.09);text-align:center}.cl-hero-balance span{font-size:.68rem;color:rgba(255,255,255,.72);font-weight:800}.cl-hero-balance b{font-size:1.45rem;color:#f1d86d;font-variant-numeric:tabular-nums}.cl-hero-balance.negative b{color:#ffaaa5}.cl-hero-balance small{font-size:.59rem;color:rgba(255,255,255,.55)}.cl-hero-actions{display:grid;justify-items:end;gap:9px}.cl-state{font-size:.69rem;font-weight:900;padding:5px 9px;border-radius:20px}.cl-state.open{background:rgba(62,210,139,.15);color:#8aefbd}.cl-state.closed{background:rgba(255,255,255,.14);color:#fff}
.cl-datebar{display:flex;align-items:center;justify-content:center;gap:8px}.cl-datebar form{display:flex;align-items:center;gap:8px;padding:6px 8px 6px 12px;border:1px solid var(--line);border-radius:12px;background:#fff;box-shadow:0 3px 11px rgba(21,53,91,.05)}.cl-datebar label{font-size:.7rem;color:var(--grey);font-weight:900}.cl-datebar input{border:0;background:transparent;font-family:inherit;color:var(--navy);font-weight:800}.cl-datebar input:focus{outline:0}.cl-datebar .btn{min-height:32px;padding:5px 13px}.cl-day-arrow,.cl-today{display:grid;place-items:center;height:42px;border:1px solid var(--line);border-radius:11px;background:#fff;color:var(--navy);text-decoration:none;font-weight:900}.cl-day-arrow{width:42px;font-size:1.5rem}.cl-today{padding:0 14px;font-size:.72rem}.cl-today.active{background:var(--navy);color:#fff;border-color:var(--navy)}
.cl-progress{display:grid;grid-template-columns:1fr 70px 1fr 70px 1fr;align-items:center;padding:14px 18px;border:1px solid var(--line);border-radius:16px;background:#fff}.cl-step{display:flex;align-items:center;gap:10px;color:var(--grey);text-decoration:none;min-width:0}.cl-step i{display:grid;place-items:center;flex:0 0 34px;height:34px;border:2px solid #c8d2dc;border-radius:50%;font-style:normal;font-weight:900;background:#fff}.cl-step span{display:grid;gap:2px;min-width:0}.cl-step b{color:var(--ink);font-size:.78rem}.cl-step small{font-size:.64rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.cl-step.done i{border-color:var(--green);background:var(--green);color:#fff}.cl-step.current i{border-color:var(--gold);background:var(--gold-light);color:#765c0c}.cl-step.warning i{border-color:var(--red);background:var(--red-lt);color:var(--red)}.cl-step.locked{opacity:.55}.cl-progress-line{height:2px;background:#dde3e8;margin:0 8px}.cl-progress-line.done{background:var(--green)}
.cl-balance-card,.cl-section{border:1px solid var(--line);border-radius:18px;background:#fff;box-shadow:0 4px 16px rgba(21,53,91,.045)}.cl-balance-card{padding:18px}.cl-section{padding:20px}.cl-section-title{display:flex;align-items:center;gap:12px;margin-bottom:16px}.cl-section-title.compact{justify-content:space-between;margin-bottom:14px}.cl-section-title>div{min-width:0}.cl-section-title>div>span{font-size:.63rem;color:var(--gold);font-weight:900}.cl-section-title h2{margin:2px 0 0;font-size:1.05rem;color:var(--navy)}.cl-section-title p{margin:4px 0 0;font-size:.72rem;color:var(--grey)}.cl-section-no{display:grid;place-items:center;flex:0 0 38px;height:38px;border-radius:12px;background:var(--navy);color:#fff;font-size:1rem;font-weight:900}.cl-lock-note,.cl-live-note{margin-inline-start:auto;font-size:.66rem;font-weight:900;color:var(--grey)}.cl-live-note{color:var(--green)}.cl-live-note i{display:inline-block;width:7px;height:7px;border-radius:50%;background:#33bf78;box-shadow:0 0 0 4px rgba(51,191,120,.12)}
.cl-equation{display:grid;grid-template-columns:repeat(13,auto);align-items:stretch;gap:7px}.cl-equation>i{align-self:center;font-size:1.1rem;font-style:normal;font-weight:900;color:#92a0ae}.cl-equation>i.minus{color:var(--red)}.cl-equation>i.plus{color:var(--green)}.cl-equation-item{display:grid;align-content:center;gap:3px;min-width:115px;padding:12px 13px;border-radius:12px;background:#f3f6f9;border:1px solid #e2e8ed}.cl-equation-item span{font-size:.63rem;color:var(--grey);font-weight:800}.cl-equation-item b{font-size:.94rem;color:var(--navy);font-variant-numeric:tabular-nums}.cl-equation-item small{font-size:.53rem;color:var(--grey)}.cl-equation-item.topup b{color:var(--green)}.cl-equation-item.expense b,.cl-equation-item.driver b{color:var(--red)}.cl-equation-item.available{background:#edf5fa;border-color:#cbdde9}.cl-equation-item.closing{background:linear-gradient(135deg,#fff7d8,#fff);border-color:#e5cf75;min-width:145px}.cl-equation-item.closing b{color:#80630d;font-size:1.08rem}.cl-equation-item.closing.negative{background:#fff3f2;border-color:#e6b0ac}.cl-equation-item.closing.negative b{color:var(--red)}
.cl-review-cards{display:grid;gap:10px}.cl-review-card{border:1px solid var(--line);border-radius:14px;background:#fff;overflow:hidden}.cl-review-card[open]{border-color:#b8cadb;box-shadow:0 5px 17px rgba(21,53,91,.07)}.cl-review-card summary{display:grid;grid-template-columns:auto minmax(190px,1fr) auto auto;align-items:center;gap:12px;padding:13px 15px;cursor:pointer;list-style:none}.cl-review-card summary::-webkit-details-marker{display:none}.cl-review-icon{display:grid;place-items:center;width:38px;height:38px;border-radius:11px;background:#f4ecec}.cl-review-icon.driver{background:#e9f2f8}.cl-review-icon.visa{background:#f1eafb}.cl-review-name{display:grid;gap:2px}.cl-review-name b{color:var(--navy);font-size:.82rem}.cl-review-name small{font-size:.65rem;color:var(--grey)}.cl-review-card summary>strong{font-size:.9rem;color:var(--navy);font-variant-numeric:tabular-nums}.cl-review-card summary>strong.negative{color:var(--red)}.cl-expand{font-size:1.4rem;color:var(--grey);transition:.15s}.cl-review-card[open] .cl-expand{transform:rotate(-90deg)}.cl-review-body{border-top:1px solid var(--line);background:#fbfcfd}.cl-info-line{padding:10px 14px;background:#f3eef9;color:#684682;font-size:.69rem}.cl-detail-table{min-width:760px}.cl-detail-table th,.cl-detail-table td{padding:9px 11px!important;font-size:.72rem}
.cl-recon-status{margin-inline-start:auto;padding:7px 11px;border-radius:20px;font-size:.67rem;font-weight:900;white-space:nowrap}.cl-recon-status.matched{color:var(--green);background:var(--green-lt)}.cl-recon-status.different{color:var(--red);background:var(--red-lt)}.cl-recon-status.new{color:#84670c;background:var(--gold-light)}.cl-recon-status.unavailable{color:var(--grey);background:#edf0f2}.cl-recon-summary{display:grid;grid-template-columns:.65fr repeat(3,1fr);gap:9px;margin-bottom:12px}.cl-recon-summary>div{display:grid;gap:3px;padding:11px 13px;border:1px solid var(--line);border-radius:11px;background:#f8fafb}.cl-recon-summary span{font-size:.61rem;color:var(--grey);font-weight:800}.cl-recon-summary b{font-size:.92rem;color:var(--navy);font-variant-numeric:tabular-nums}.cl-recon-summary .has-diff{border-color:#e4b0ad;background:#fff5f4}.cl-recon-summary .has-diff b{color:var(--red)}.cl-recon-rule{display:flex;gap:8px;margin-bottom:13px;padding:10px 12px;border:1px solid #e5d28b;border-radius:10px;background:#fff9e4;color:#725a0e;font-size:.69rem}.cl-recon-rule b{white-space:nowrap}.cl-client-list{display:grid;gap:9px}.cl-client-card,.cl-general-card{border:1px solid var(--line);border-radius:13px;background:#fff;overflow:hidden;transition:.15s}.cl-client-card.is-match{border-inline-start:4px solid var(--green)}.cl-client-card.is-different{border-inline-start:4px solid var(--red);background:#fffafa}.cl-client-main{display:grid;grid-template-columns:minmax(200px,1.5fr) minmax(105px,.7fr) 125px minmax(95px,.6fr) auto;align-items:center;gap:11px;padding:12px 14px}.cl-client-identity{display:flex;align-items:center;gap:10px;min-width:0}.cl-client-mark{display:grid;place-items:center;flex:0 0 34px;height:34px;border-radius:10px;background:#eaf1f7;color:var(--navy);font-weight:900}.cl-client-identity>div{display:grid;gap:2px;min-width:0}.cl-client-identity b{font-size:.85rem;color:var(--navy);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.cl-client-identity small{font-size:.61rem;color:var(--grey)}.cl-client-number,.cl-client-input{display:grid;gap:3px}.cl-client-number span,.cl-client-input>span{font-size:.59rem;color:var(--grey);font-weight:800}.cl-client-number b{font-size:.83rem;color:var(--navy);font-variant-numeric:tabular-nums}.cl-client-number.difference b.has-diff{color:var(--red)}.recon-input{width:100%;height:36px;border:1px solid #d1b44d;border-radius:9px;background:#fffbea;padding:6px 8px;text-align:center;font-family:inherit;font-size:.82rem;font-weight:900;color:var(--navy)}.recon-input:focus{outline:0;border-color:var(--gold);box-shadow:0 0 0 3px rgba(201,162,39,.13)}.recon-input:disabled{border-color:var(--line);background:#f2f4f5;color:var(--grey)}.cl-visa-mini{padding:5px 7px;border-radius:7px;background:#f1eafb;color:#65467e;font-size:.62rem;font-weight:900;white-space:nowrap}.cl-client-note{display:flex;align-items:center;gap:8px;padding:0 14px 10px}.cl-client-note span{font-size:.6rem;color:var(--grey);font-weight:800}.cl-client-note input{flex:1;border:1px solid var(--line);border-radius:8px;padding:6px 9px;font-family:inherit;font-size:.68rem}.cl-permit-details{border-top:1px solid #edf0f2;background:#fafbfc}.cl-permit-details>summary{display:flex;align-items:center;gap:8px;padding:8px 14px;cursor:pointer;list-style:none;color:var(--navy);font-size:.68rem;font-weight:900}.cl-permit-details>summary::-webkit-details-marker{display:none}.cl-permit-details>summary b{display:grid;place-items:center;width:22px;height:22px;border-radius:7px;background:#e8eef4}.cl-permit-list{display:grid;gap:8px;padding:0 12px 12px}.cl-permit-card{border:1px solid var(--line);border-radius:10px;background:#fff;overflow:hidden}.cl-permit-card header{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:9px 11px;background:#f2f6f9}.cl-permit-card header>div{display:flex;align-items:center;gap:10px}.cl-permit-card header a{color:var(--navy);font-size:.75rem;font-weight:900}.cl-permit-card header span{font-size:.65rem;color:var(--grey)}.cl-permit-card header>b{font-size:.76rem;color:var(--navy)}.cl-permit-card table,.cl-general-details table{width:100%;border-collapse:collapse;min-width:600px}.cl-permit-card th,.cl-permit-card td,.cl-general-details td{padding:7px 9px;border-bottom:1px solid var(--line);text-align:right;font-size:.66rem}.cl-general-card{display:grid;grid-template-columns:minmax(250px,1.5fr) minmax(105px,.7fr) 125px minmax(95px,.6fr) auto;align-items:center;gap:11px;margin-top:12px;padding:12px 14px;background:#f7f9fb}.cl-general-title{display:flex;align-items:center;gap:9px}.cl-general-title>span{font-size:1.3rem}.cl-general-title>div{display:grid;gap:2px}.cl-general-title b{font-size:.8rem;color:var(--navy)}.cl-general-title small{font-size:.6rem;color:var(--grey)}.cl-general-card .cl-permit-details{grid-column:1/-1;margin:0 -14px -12px}.cl-recon-footer{display:grid;grid-template-columns:minmax(240px,1fr) minmax(210px,.55fr) auto;align-items:end;gap:12px;margin-top:14px;padding-top:14px;border-top:1px solid var(--line)}.cl-main-note{display:grid;gap:4px}.cl-main-note>span{font-size:.66rem;color:var(--navy);font-weight:900}.cl-main-note textarea{width:100%;border:1px solid var(--line);border-radius:9px;padding:8px;font-family:inherit;resize:vertical}.cl-final-difference{display:grid;grid-template-columns:1fr auto;gap:3px 10px;padding:10px 12px;border-radius:10px;background:#fff2f1;border:1px solid #ebc4c1}.cl-final-difference span,.cl-final-difference small{font-size:.6rem;color:var(--grey)}.cl-final-difference b{color:var(--red);font-size:.95rem}.cl-final-difference small{grid-column:1/-1}.cl-final-difference.matched{background:#edf8f1;border-color:#badfc6}.cl-final-difference.matched b{color:var(--green)}.cl-save-recon{min-height:44px}.cl-recon-meta{margin-top:9px;text-align:end;color:var(--grey);font-size:.62rem}
.cl-empty-state{display:grid;justify-items:center;gap:4px;padding:22px;border:1px dashed #b8c8d6;border-radius:12px;background:#f8fafb;text-align:center}.cl-empty-state>span{display:grid;place-items:center;width:34px;height:34px;border-radius:50%;background:var(--green-lt);color:var(--green);font-weight:900}.cl-empty-state b{font-size:.78rem;color:var(--navy)}.cl-empty-state small{font-size:.65rem;color:var(--grey)}
.cl-close-panel{display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:15px;padding:16px;border:1px solid var(--line);border-radius:14px;background:#f6f7f8}.cl-close-panel.is-ready{border-color:#dbc46a;background:#fff9e5}.cl-close-panel.is-closed{border-color:#b7dac2;background:#f0f8f3}.cl-close-symbol{display:grid;place-items:center;width:44px;height:44px;border-radius:13px;background:#e8ecef;color:var(--grey);font-size:1.15rem;font-weight:900}.is-ready .cl-close-symbol{background:#f4dea0;color:#72580b}.is-closed .cl-close-symbol{background:#d8eee0;color:var(--green)}.cl-close-copy{display:grid;gap:3px}.cl-close-copy b{font-size:.87rem;color:var(--navy)}.cl-close-copy span{font-size:.7rem;color:var(--grey)}.cl-close-copy strong{color:var(--navy)}.cl-close-action small{display:block;max-width:280px;color:var(--grey);font-size:.64rem;text-align:end}.cl-close-button{min-height:45px;padding-inline:18px}
@media(max-width:1250px){.cl-equation{grid-template-columns:repeat(7,1fr)}.cl-equation>i{display:none}.cl-equation-item{min-width:0}.cl-equation-item.closing{grid-column:span 2}.cl-client-main{grid-template-columns:minmax(180px,1.3fr) repeat(3,minmax(100px,.7fr))}.cl-client-main .cl-visa-mini{grid-column:2/-1;justify-self:start}.cl-general-card{grid-template-columns:minmax(220px,1.3fr) repeat(3,minmax(100px,.7fr))}.cl-general-card>.cl-visa-mini{grid-column:2/-1;justify-self:start}}
@media(max-width:900px){.cl-hero{grid-template-columns:1fr auto}.cl-hero-balance{grid-column:1/-1;grid-row:2}.cl-progress{grid-template-columns:1fr}.cl-progress-line{width:2px;height:18px;margin:3px 16px}.cl-equation{grid-template-columns:repeat(2,1fr)}.cl-equation-item.closing{grid-column:span 2}.cl-section{padding:15px}.cl-recon-summary{grid-template-columns:repeat(2,1fr)}.cl-client-main,.cl-general-card{grid-template-columns:1fr 1fr}.cl-client-identity,.cl-general-title{grid-column:1/-1}.cl-client-main .cl-visa-mini,.cl-general-card>.cl-visa-mini{grid-column:auto}.cl-recon-footer{grid-template-columns:1fr}.cl-save-recon{width:100%;justify-content:center}.cl-close-panel{grid-template-columns:auto 1fr}.cl-close-action{grid-column:1/-1}.cl-close-action .btn,.cl-close-action form{width:100%}.cl-close-action .btn{justify-content:center}.cl-close-action small{max-width:none;text-align:start}}
@media(max-width:600px){.cl-hero{grid-template-columns:1fr;padding:18px}.cl-hero-actions{justify-items:stretch;grid-template-columns:1fr auto;align-items:center}.cl-hero-actions .btn{justify-content:center}.cl-hero-balance{grid-column:auto;grid-row:auto;min-width:0}.cl-datebar{flex-wrap:wrap}.cl-datebar form{order:-1;width:100%;justify-content:center}.cl-progress{padding:12px}.cl-section-title{align-items:flex-start}.cl-section-title .cl-recon-status{grid-column:1/-1;margin:6px 0 0}.cl-reconciliation>.cl-section-title{display:grid;grid-template-columns:auto 1fr}.cl-equation{grid-template-columns:1fr}.cl-equation-item.closing{grid-column:auto}.cl-review-card summary{grid-template-columns:auto 1fr auto}.cl-review-card summary>strong{grid-column:2}.cl-expand{grid-column:3;grid-row:1/3}.cl-recon-summary{grid-template-columns:1fr 1fr}.cl-recon-rule{display:grid}.cl-client-main,.cl-general-card{grid-template-columns:1fr 1fr;padding:11px}.cl-client-identity,.cl-general-title{grid-column:1/-1}.cl-client-note{display:grid}.cl-close-panel{grid-template-columns:1fr;text-align:center}.cl-close-symbol{margin:auto}.cl-close-action{grid-column:auto}.cl-section-title.compact{align-items:center}}
</style>

<?php if ($recon['available'] && $reconEditable): ?>
<script>
(function(){
  var form=document.getElementById('reconForm');if(!form)return;
  var money=function(n){return(Math.round(n*100)/100).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})};
  var num=function(el){var n=parseFloat(el&&el.value);return isFinite(n)?n:0};
  function paint(el,diff,reviewed){if(!el)return;el.textContent=reviewed?money(diff):'—';el.classList.toggle('has-diff',reviewed&&Math.abs(diff)>=.005)}
  function update(){var systemClients=0,manualClients=0,any=false;
    form.querySelectorAll('.cl-client-card.recon-row').forEach(function(row){var system=parseFloat(row.dataset.systemTotal||0),input=row.querySelector('.manual-total'),reviewed=input&&input.value!=='',manual=num(input),diff=manual-system;systemClients+=system;manualClients+=manual;any=any||reviewed;paint(row.querySelector('.client-diff'),diff,reviewed);row.classList.toggle('is-match',reviewed&&Math.abs(diff)<.005);row.classList.toggle('is-different',reviewed&&Math.abs(diff)>=.005)});
    var general=form.querySelector('.cl-general-card'),generalSystem=0,generalManual=0;if(general){generalSystem=parseFloat(general.dataset.systemTotal||0);var gi=general.querySelector('.manual-general'),reviewed=gi&&gi.value!=='';generalManual=num(gi);any=any||reviewed;paint(general.querySelector('.general-diff'),generalManual-generalSystem,reviewed)}
    var manual=manualClients+generalManual,diff=manual-(systemClients+generalSystem);var manualEl=document.getElementById('manualCashTotal');if(manualEl)manualEl.textContent=any?money(manual):'—';['reconGrandDiff','reconGrandDiffBottom'].forEach(function(id){var el=document.getElementById(id);if(el)el.textContent=any?money(diff):'—'});var summary=document.getElementById('reconGrandDiff')?.parentElement;if(summary)summary.classList.toggle('has-diff',any&&Math.abs(diff)>=.005);var bottom=document.getElementById('reconGrandDiffBottom')?.parentElement;if(bottom){bottom.classList.toggle('matched',any&&Math.abs(diff)<.005);var small=bottom.querySelector('small');if(small)small.textContent=any&&Math.abs(diff)<.005?'✓ الأرقام مطابقة':'يجب أن يصبح الفرق 0.00'}}
  form.addEventListener('input',function(e){if(e.target.classList.contains('recon-input'))update()});update();
})();
</script>
<?php endif; ?>
