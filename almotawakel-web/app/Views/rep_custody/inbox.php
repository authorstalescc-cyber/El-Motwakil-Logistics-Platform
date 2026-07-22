<?php
use App\Core\FormState;
/** @var string $status @var array $groups @var array $rows @var int $pending */
$inboxState = $inboxState ?? ['token'=>FormState::token(),'old'=>[],'errors'=>[],'back'=>'rep-custody/inbox?status='.$status];
$inboxOld = $inboxState['old'];
$inboxErrors = $inboxState['errors'];
$failedAction = (string) ($inboxOld['_action'] ?? '');
$failedSid = (int) ($inboxOld['submission_id'] ?? 0);
$failedGroup = (string) ($inboxOld['_group'] ?? '');
$failedIds = array_map('intval', (array) ($inboxOld['ids'] ?? []));
$rowToken = static function (string $action, int $sid) use ($inboxState, $failedAction, $failedSid): string {
  return $failedAction === $action && $failedSid === $sid ? $inboxState['token'] : FormState::token();
};
$tab = fn(string $k, string $lbl) =>
  '<a class="btn ' . ($status === $k ? '' : 'ghost') . '" href="' . url('rep-custody/inbox?status=' . $k) . '">' . $lbl . '</a>';

// إجماليات الشاشة
$totAll = 0.0; $cntAll = 0;
foreach ($groups as $g) { $totAll += $g['total']; $cntAll += $g['count']; }
?>
<div class="toolbar">
  <div class="section-head" style="margin:0">📥 مراجعة بنود المناديب</div>
  <div class="spacer"></div>
  <?= $tab('pending', '⏳ مستني' . ($pending ? " ($pending)" : '')) ?>
  <?= $tab('approved', '✓ معتمد') ?>
  <?= $tab('rejected', '✕ مرفوض') ?>
  <a class="btn ghost" href="<?= url('rep-custody') ?>">عهدة المناديب</a>
</div>

<?php if ($status === 'pending' && $cntAll): ?>
  <div class="kpi-grid mb" style="grid-template-columns:repeat(3,1fr)">
    <div class="kpi gold">
      <div class="lbl">مستني مراجعتك</div>
      <div class="val"><?= intf($cntAll) ?></div>
      <div style="opacity:.8;font-size:.8rem">بند</div>
    </div>
    <div class="kpi">
      <div class="lbl">إجمالي المبالغ</div>
      <div class="val neg"><?= intf($totAll) ?></div>
    </div>
    <div class="kpi">
      <div class="lbl">مناديب</div>
      <div class="val"><?= intf(count($groups)) ?></div>
    </div>
  </div>
<?php endif; ?>

<div class="note mb" style="font-size:.85rem">
  💡 تقدر <strong>تعدّل البند وتصلّحه</strong> قبل ما تعتمده — أحسن من إنك ترفضه وتستنى المندوب يعيده.
  البند اللي تعتمده بيتحط على <strong>تايم لاين الإذن</strong> وبيدخل <strong>فاتورة العميل والمصروفات</strong>،
  و<strong>بيتخصم من الخزنة بتاريخ الصرف ومن عهدة المندوب في كشفه</strong>.
  <br>🧷 أما <strong>النثرية</strong> فمالهاش إذن ولا عميل: بتروح <strong>للمصروفات وبس</strong>
  (تصنيف «نثريات المناديب» في <a href="<?= url('expenses') ?>">سجل المصروفات</a>)
  وبتتخصم من الخزنة وعهدة المندوب برضو — <strong>وعمرها ما تدخل حساب أي عميل</strong>.
</div>

<?php if ($status === 'pending'): ?>

  <?php if (!$groups): ?>
    <div class="card"><div class="empty"><div class="ic">✓</div>
      مفيش بنود مستنية. كل حاجة اتراجعت.
    </div></div>
  <?php else: foreach ($groups as $g): ?>
    <div class="card mb rc-rep">
      <div class="rc-rep-head">
        <div>
          <a href="<?= url('rep-custody/' . $g['rep_id']) ?>" class="rc-rep-name">🧑‍💼 <?= e($g['rep_name']) ?></a>
          <div class="rc-rep-sub"><?= intf($g['count']) ?> بند مستني · إجمالي <?= money($g['total'], false) ?></div>
        </div>
      </div>

      <?php foreach ($g['days'] as $d): ?>
        <?php
          $fid = 'd' . $g['rep_id'] . '_' . preg_replace('/\D/', '', $d['day']);
          $dayFailed = $failedGroup === $fid && in_array($failedAction, ['approve-many','bulk-edit','reject-many'], true);
          $dayToken = $dayFailed ? $inboxState['token'] : FormState::token();
        ?>
        <form method="post" action="<?= url('rep-custody/approve-many') ?>" class="rc-day" id="f<?= $fid ?>">
          <?= csrfField() ?>
          <?= formStateFields($dayToken, $inboxState['back']) ?>
          <input type="hidden" name="_group" value="<?= e($fid) ?>">
          <?php if ($dayFailed): ?><?= formErrorSummary($inboxErrors) ?><?php endif; ?>

          <div class="rc-day-head">
            <label class="rc-all">
              <input type="checkbox" class="js-rc-all" checked aria-label="علّم كل بنود اليوم">
              <span>📅 <?= e(dfmt($d['day'])) ?></span>
            </label>
            <span class="rc-day-tot"><?= count($d['rows']) ?> بند · <?= money($d['total'], false) ?></span>
          </div>

          <div class="rc-items">
            <?php foreach ($d['rows'] as $r): ?>
              <?php
                // النثرية (v39) مالهاش إذن ولا حركة ولا سعر فعلي للعميل
                $isMisc = \App\Models\RepPortal::isMisc((string) $r['kind']);
                $movs = $isMisc ? [] : \App\Models\RepInbox::movementsOf((int) $r['permit_id']);
                $eid  = 'e' . (int) $r['id'];
                $hasActual = !$isMisc && $r['actual_unit'] !== null && $r['actual_unit'] !== '';
              ?>
              <?php
                $rowFailed = $failedSid === (int) $r['id'];
                $editFailed = $rowFailed && $failedAction === 'edit';
                $checked = $dayFailed ? in_array((int) $r['id'], $failedIds, true) : true;
              ?>
              <div class="rc-item<?= !empty($r['review_started_at']) ? ' locked' : '' ?>" id="submission-<?= (int)$r['id'] ?>">

                <?php if ($rowFailed && !$editFailed): ?>
                  <?= formErrorSummary($inboxErrors) ?>
                  <?php if ($failedAction === 'reject'): ?>
                    <div class="rc-inline-retry">
                      <label>سبب الرفض
                        <input type="text" name="reason" form="r<?= (int)$r['id'] ?>f"
                               value="<?= e($inboxOld['reason'] ?? '') ?>" required<?= fieldErrorAttrs('reason', $inboxErrors) ?>>
                        <?= fieldError('reason', $inboxErrors) ?>
                      </label>
                      <button class="btn" type="submit" form="r<?= (int)$r['id'] ?>f">أعد محاولة الرفض</button>
                    </div>
                  <?php endif; ?>
                <?php endif; ?>

                <!-- الصف المقروء -->
                <div class="rc-row">
                  <input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" <?= $checked ? 'checked' : '' ?>
                         class="js-rc-one" aria-label="اعتمد البند">

                  <div class="rc-main">
                    <div class="rc-title">
                      <span class="rc-note"><?= e($r['note']) ?></span>
                      <?php if ($isMisc): ?>
                        <span class="pill rc-misc" title="مصروف عام مش على إذن — بيروح للمصروفات مش لحساب أي عميل">🧷 نثرية</span>
                      <?php else: ?>
                        <span class="pill <?= $r['kind'] === 'receipt' ? 'st-done' : 'st-partial' ?>">
                          <?= $r['kind'] === 'receipt' ? 'إيصال' : 'مصروف' ?></span>
                      <?php endif; ?>
                      <?php if (!empty($r['is_visa'])): ?>
                        <span class="pill visa-pill" title="مدفوع بكارت الفيزا — يتخصم من الكارت، لا من الخزنة ولا من عهدة المندوب">💳 فيزا</span>
                      <?php endif; ?>
                      <?php if (!empty($r['edited_at'])): ?>
                        <span class="pill st-partial" title="عدّله <?= e($r['edited_by'] ?: '') ?>">✏️ متعدّل</span>
                      <?php endif; ?>
                      <?php if (!empty($r['review_started_at'])): ?>
                        <span class="pill st-partial" title="<?= e($r['review_started_by'] ?: '') ?>">🔒 قيد المراجعة</span>
                      <?php endif; ?>
                    </div>
                    <div class="rc-meta">
                      <?php if ($isMisc): ?>
                        <?php /* النثرية مالهاش إذن — من غير الفحص ده كان يطلع رابط لإذن #0 */ ?>
                        <span>مصروف عام بلا إذن — هيروح للمصروفات</span>
                      <?php else: ?>
                      <a href="<?= url('permits/' . $r['permit_id'] . '/manage') ?>">
                        <?= e(\App\Models\Permit::noLabel($r['permit_type'] ?? 'sader')) ?>
                        <?= e($r['permit_no'] ?: '#' . $r['permit_id']) ?></a>
                      <?php /* الشركة (لصالحها) والعميل (الوكيل) كيانان مختلفان — المالك عايز
                               يشوف الاتنين عشان يعرف البند تبع مين. المتطابق ما يتكررش. */ ?>
                      <?php if (!empty($r['company'])): ?>
                        <span class="rc-co">· 🏢 <?= e($r['company']) ?></span>
                      <?php endif; ?>
                      <?php if (!empty($r['client']) && $r['client'] !== $r['company']): ?>
                        <span>· 👤 <?= e($r['client']) ?></span>
                      <?php elseif (empty($r['company']) && empty($r['client'])): ?>
                        <span>· بدون عميل</span>
                      <?php endif; ?>
                      <?php if ($r['movement_seq']): ?><span>· حركة <?= (int) $r['movement_seq'] ?></span><?php endif; ?>
                      <?php endif; /* ‎$isMisc */ ?>
                      <?php if ($r['ref_no']): ?><span>· إيصال <?= e($r['ref_no']) ?></span><?php endif; ?>
                      <?php if ((int) $r['qty'] > 1): ?>
                        <span>· <?= (int) $r['qty'] ?> × <?= money($r['unit_amount'], false) ?></span>
                      <?php endif; ?>
                    </div>
                  </div>

                  <div class="rc-money">
                    <div class="rc-paid"><?= money($r['amount'], false) ?></div>
                    <div class="rc-actual <?= $hasActual ? 'has' : '' ?>">
                      <?php /* النثرية مش بتتحاسب على عميل، فـ«الفعلي فاضي» هنا
                               كانت هتبان كأنها بيانات ناقصة والحقيقة إنها مالهاش (v39) */ ?>
                      <?= $isMisc
                            ? 'مصروف شركة'
                            : ($hasActual
                                ? 'للعميل ' . money((float) $r['actual_unit'] * (int) $r['qty'], false)
                                : 'الفعلي فاضي') ?>
                    </div>
                  </div>

                  <?php /* مفيش صورة = خانة فاضية هادية، مش أيقونة تحذير حمرا:
                           الصورة اختيارية أصلاً وغيابها مش خطأ. */ ?>
                  <?php if ($r['photo']): ?>
                    <a class="rc-photo" href="<?= url('attachments/submission/'.(int)$r['id']) ?>" target="_blank" title="افتح صورة الإيصال">
                      <img src="<?= url('attachments/submission/'.(int)$r['id']) ?>" alt="صورة الإيصال" loading="lazy">
                    </a>
                  <?php else: ?>
                    <span class="rc-photo none" title="مبعتش صورة"></span>
                  <?php endif; ?>

                  <div class="rc-acts">
                    <button class="rc-btn ok" type="submit" form="a<?= (int)$r['id'] ?>f"
                            title="اعتمد البند ده">✓ اعتمد</button>
                    <button class="rc-btn" type="button" onclick="rcToggle('<?= $eid ?>')" title="عدّل البند">✏️</button>
                    <button class="rc-btn no" type="button" title="ارفض البند"
                            onclick="repReject(<?= (int) $r['id'] ?>)">✕</button>
                  </div>
                </div>

                <!-- فورم التعديل (مطوي) -->
                <div class="rc-edit" id="<?= $eid ?>" <?= $editFailed ? '' : 'hidden' ?>>
                  <?php if ($editFailed): ?><?= formErrorSummary($inboxErrors) ?><?php endif; ?>
                  <div class="rc-grid">
                    <label>البيان
                      <input type="text" name="note" form="<?= $eid ?>f" value="<?= e($editFailed ? ($inboxOld['note'] ?? '') : $r['note']) ?>" maxlength="255" required<?= $editFailed ? fieldErrorAttrs('note', $inboxErrors) : '' ?>><?= $editFailed ? fieldError('note', $inboxErrors) : '' ?></label>
                    <?php /* النثرية ما تتحوّلش لبند على إذن من هنا (الخادم بيرفضها كمان):
                             مالهاش إذن ولا حركة، فالقلب كان هيسيب بند ناقص. */ ?>
                    <?php if ($isMisc): ?>
                      <input type="hidden" name="kind" form="<?= $eid ?>f" value="misc">
                    <?php else: ?>
                      <label>النوع
                        <select name="kind" form="<?= $eid ?>f">
                          <?php $editKind = $editFailed ? ($inboxOld['kind'] ?? '') : $r['kind']; ?>
                          <option value="receipt" <?= $editKind === 'receipt' ? 'selected' : '' ?>>إيصال</option>
                          <option value="expense" <?= $editKind === 'expense' ? 'selected' : '' ?>>مصروف</option>
                        </select></label>
                    <?php endif; ?>
                    <label>الكمية
                      <input type="number" name="qty" form="<?= $eid ?>f" min="1" max="10000" value="<?= e($editFailed ? ($inboxOld['qty'] ?? '') : (string)(int)$r['qty']) ?>"<?= $editFailed ? fieldErrorAttrs('qty', $inboxErrors) : '' ?>><?= $editFailed ? fieldError('qty', $inboxErrors) : '' ?></label>
                    <label>اللي دفعه المندوب (للوحدة)
                      <input type="number" step="0.01" min="0.01" name="unit_amount" form="<?= $eid ?>f"
                             value="<?= e($editFailed ? ($inboxOld['unit_amount'] ?? '') : (string)round((float)$r['unit_amount'], 2)) ?>"<?= $editFailed ? fieldErrorAttrs('unit_amount', $inboxErrors) : '' ?>><?= $editFailed ? fieldError('unit_amount', $inboxErrors) : '' ?></label>
                    <?php /* مفيش «فعلي للعميل» للنثرية — مفيش عميل يتحاسب أصلاً (v39) */ ?>
                    <?php if (!$isMisc): ?>
                      <label class="hi">الفعلي للعميل (للوحدة)
                        <input type="number" step="0.01" min="0" name="actual_unit" form="<?= $eid ?>f"
                               value="<?= e($editFailed ? ($inboxOld['actual_unit'] ?? '') : ($hasActual ? (string)round((float)$r['actual_unit'], 2) : '')) ?>"
                               placeholder="سيبه فاضي وحطه بعدين"<?= $editFailed ? fieldErrorAttrs('actual_unit', $inboxErrors) : '' ?>><?= $editFailed ? fieldError('actual_unit', $inboxErrors) : '' ?></label>
                    <?php endif; ?>
                    <label>التاريخ
                      <input type="date" name="entry_date" form="<?= $eid ?>f" value="<?= e($editFailed ? ($inboxOld['entry_date'] ?? '') : $r['entry_date']) ?>" required<?= $editFailed ? fieldErrorAttrs('entry_date', $inboxErrors) : '' ?>><?= $editFailed ? fieldError('entry_date', $inboxErrors) : '' ?></label>
                    <label>رقم الإيصال
                      <input type="text" name="ref_no" form="<?= $eid ?>f" value="<?= e($editFailed ? ($inboxOld['ref_no'] ?? '') : ($r['ref_no'] ?? '')) ?>" maxlength="120"></label>
                    <?php if (count($movs) > 1): ?>
                      <label>الحركة
                        <select name="movement_id" form="<?= $eid ?>f">
                          <?php foreach ($movs as $mv): ?>
                            <option value="<?= (int) $mv['id'] ?>" <?= (int) $mv['id'] === (int) ($editFailed ? ($inboxOld['movement_id'] ?? 0) : $r['movement_id']) ? 'selected' : '' ?>>
                              الحركة <?= (int) $mv['seq'] ?><?= $mv['note'] ? ' — ' . e($mv['note']) : '' ?>
                            </option>
                          <?php endforeach; ?>
                        </select></label>
                    <?php elseif ($movs): ?>
                      <input type="hidden" name="movement_id" form="<?= $eid ?>f" value="<?= (int) $movs[0]['id'] ?>">
                    <?php endif; ?>
                    <?php /* الفيزا (v45) — المالك يصحّح الوسم أو الكارت قبل الاعتماد.
                             البند الموسوم بيتخصم من الكارت وبس: لا الخزنة ولا عهدة المندوب. */ ?>
                    <?php $editVisa = $editFailed ? !empty($inboxOld['is_visa']) : !empty($r['is_visa']); ?>
                    <label class="rc-visa">💳 مدفوع بالفيزا
                      <span class="rc-visa-row">
                        <input type="checkbox" name="is_visa" form="<?= $eid ?>f" value="1" <?= $editVisa ? 'checked' : '' ?>>
                        <?= visa_card_select(
                              $editFailed ? (int) ($inboxOld['visa_card_id'] ?? 0) : (int) ($r['visa_card_id'] ?? 0),
                              'visa_card_id', '', '', $eid . 'f'
                            ) ?>
                      </span>
                    </label>
                  </div>
                  <?php /* ⚠️ approve_now لازم يكون حقل مخفي بيتملّى بالـJS، مش
                           name/value على الزرار: الزرار مربوط بالفورم بـform=""
                           فبيتبعت دايمًا حتى لو مدوستش عليه، وكان كل تعديل
                           هيتعتمد غصب. (متحقَّق فعليًا من الفورم في المتصفح.) */ ?>
                  <input type="hidden" name="approve_now" form="<?= $eid ?>f" id="<?= $eid ?>ap" value="<?= $editFailed ? e($inboxOld['approve_now'] ?? '') : '' ?>">
                  <div class="rc-edit-acts">
                    <button class="btn" type="submit" form="<?= $eid ?>f"
                            onclick="document.getElementById('<?= $eid ?>ap').value=''">💾 احفظ بس</button>
                    <button class="btn gold" type="submit" form="<?= $eid ?>f"
                            onclick="document.getElementById('<?= $eid ?>ap').value='1'">💾 احفظ واعتمد</button>
                    <button class="btn ghost" type="button" onclick="rcToggle('<?= $eid ?>')">إلغاء</button>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="rc-day-foot">
            <button class="btn gold" type="submit">✓ اعتمد المعلَّم</button>
            <button class="btn ghost" type="submit" formaction="<?= url('rep-custody/bulk-edit') ?>"
                    <?= $dayFailed && $failedAction === 'bulk-edit' ? 'data-ready="1"' : '' ?>
                    onclick="return rcBulkEdit(this)">✏️ <?= $dayFailed && $failedAction === 'bulk-edit' ? 'أعد محاولة التعديل' : 'عدّل المعلَّم' ?></button>
            <button class="btn ghost" type="submit" formaction="<?= url('rep-custody/reject-many') ?>"
                    <?= $dayFailed && $failedAction === 'reject-many' ? 'data-ready="1"' : '' ?>
                    onclick="return rcBulkReject(this)">✕ <?= $dayFailed && $failedAction === 'reject-many' ? 'أعد محاولة الرفض' : 'ارفض المعلَّم' ?></button>
            <span class="rc-hint">شيل العلامة عن أي بند مش عايز تعتمده</span>
          </div>

          <!-- حقول التعديل الجماعي (تتملّى من البوب-أب) -->
          <input type="hidden" name="entry_date" value="<?= e($dayFailed ? ($inboxOld['entry_date'] ?? '') : '') ?>">
          <input type="hidden" name="kind" value="<?= e($dayFailed ? ($inboxOld['kind'] ?? '') : '') ?>">
          <input type="hidden" name="actual_unit" value="<?= e($dayFailed ? ($inboxOld['actual_unit'] ?? '') : '') ?>">
          <input type="hidden" name="reason" value="<?= e($dayFailed ? ($inboxOld['reason'] ?? '') : '') ?>">
        </form>
      <?php endforeach; ?>
    </div>
  <?php endforeach; endif; ?>

  <!-- فورمات الإجراءات الفردية: رمز مستقل لكل محاولة، وبرّه فورم اليوم لمنع التداخل. -->
  <?php foreach ($groups as $g): foreach ($g['days'] as $d): foreach ($d['rows'] as $r): ?>
    <form method="post" id="a<?= (int) $r['id'] ?>f"
          action="<?= url('rep-custody/' . $r['id'] . '/approve') ?>" style="display:none">
      <?= csrfField() ?>
      <?= formStateFields($rowToken('approve', (int)$r['id']), $inboxState['back']) ?>
    </form>
    <form method="post" id="e<?= (int) $r['id'] ?>f"
          action="<?= url('rep-custody/' . $r['id'] . '/edit') ?>" style="display:none">
      <?= csrfField() ?>
      <?= formStateFields($rowToken('edit', (int)$r['id']), $inboxState['back']) ?>
    </form>
    <form method="post" id="r<?= (int) $r['id'] ?>f"
          action="<?= url('rep-custody/' . $r['id'] . '/reject') ?>" style="display:none">
      <?= csrfField() ?>
      <?= formStateFields($rowToken('reject', (int)$r['id']), $inboxState['back']) ?>
      <?php if (!($failedAction === 'reject' && $failedSid === (int)$r['id'])): ?>
        <input type="hidden" name="reason" value="">
      <?php endif; ?>
    </form>
  <?php endforeach; endforeach; endforeach; ?>

<?php else: ?>

  <div class="card">
    <div class="table-wrap"><table class="data">
      <thead><tr>
        <th class="g-identity">التاريخ</th>
        <th class="g-identity">المندوب</th>
        <th class="g-identity">البيان</th>
        <th class="g-identity">الإذن</th>
        <th class="g-identity">الشركة / العميل</th>
        <th class="g-expense">المبلغ</th>
        <th class="g-identity">الحالة</th>
        <th class="g-identity">المراجِع</th>
      </tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8"><div class="empty">مفيش بنود هنا.</div></td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td><?= e(dfmt($r['entry_date'])) ?></td>
            <td style="text-align:right"><a href="<?= url('rep-custody/' . $r['rep_id']) ?>"><?= e($r['rep_name']) ?></a></td>
            <td style="text-align:right"><?= e($r['note']) ?></td>
            <td style="text-align:right">
              <?php if (\App\Models\RepPortal::isMisc((string) $r['kind'])): ?>
                <span class="pill rc-misc">🧷 نثرية</span>
              <?php else: ?>
                <a href="<?= url('permits/' . $r['permit_id'] . '/manage') ?>"><?= e($r['permit_no'] ?: '#' . $r['permit_id']) ?></a>
              <?php endif; ?>
            </td>
            <td style="text-align:right">
              <?php if (!empty($r['company'])): ?>
                <div style="font-weight:800">🏢 <?= e($r['company']) ?></div>
              <?php endif; ?>
              <?php if (!empty($r['client']) && $r['client'] !== $r['company']): ?>
                <div style="font-size:.8rem;color:var(--grey)">👤 <?= e($r['client']) ?></div>
              <?php elseif (empty($r['company']) && empty($r['client'])): ?>
                <span style="color:var(--grey)">—</span>
              <?php endif; ?>
            </td>
            <td style="font-weight:700"><?= money($r['amount'], false) ?></td>
            <td>
              <?php if ($r['status'] === 'approved'): ?>
                <span class="pill st-done">✓ معتمد</span>
              <?php elseif ($r['status'] === 'rejected'): ?>
                <span class="pill st-late" title="<?= e($r['review_note'] ?? '') ?>">✕ مرفوض</span>
              <?php else: ?>
                <span class="pill st-partial">⏳ مستني</span>
              <?php endif; ?>
            </td>
            <td style="font-size:.8rem;color:var(--grey)"><?= e($r['reviewed_by'] ?: '—') ?></td>
          </tr>
          <?php if ($r['status'] === 'rejected' && $r['review_note']): ?>
            <tr><td colspan="8" style="text-align:right;font-size:.8rem;color:var(--red);background:var(--red-lt)">
              سبب الرفض: <?= e($r['review_note']) ?>
            </td></tr>
          <?php endif; ?>
        <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>

<?php endif; ?>

<style>
/* ── شاشة المراجعة: كارت لكل مندوب ← يوم ← بنود ── */
.rc-rep-head{padding:15px 18px 12px;border-bottom:1px solid var(--line)}
.rc-rep-name{font-size:1.1rem;font-weight:900;color:var(--navy)}
.rc-rep-sub{font-size:.82rem;color:var(--grey);font-weight:700;margin-top:2px}

.rc-day{border-bottom:1px solid var(--line)}
.rc-day:last-child{border-bottom:0}
.rc-day-head{display:flex;align-items:center;gap:12px;padding:11px 18px;background:var(--zebra)}
.rc-all{display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:800;font-size:.88rem}
.rc-all input{width:17px;height:17px;margin:0;cursor:pointer}
.rc-day-tot{margin-inline-start:auto;font-size:.82rem;color:var(--grey);font-weight:700}

.rc-item{border-top:1px solid var(--line)}
.rc-item.locked{background:#FFFDF5}
.rc-row{display:flex;align-items:center;gap:12px;padding:12px 18px}
.rc-row > input[type=checkbox]{width:17px;height:17px;margin:0;flex:none;cursor:pointer}
.rc-main{flex:1;min-width:0}
.rc-title{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.rc-note{font-weight:800;font-size:1rem}
.rc-meta{font-size:.78rem;color:var(--grey);margin-top:3px;display:flex;gap:6px;flex-wrap:wrap}
.rc-meta a{font-weight:700;color:var(--navy)}
.rc-meta .rc-co{font-weight:800;color:var(--navy)}

/* النثرية (v39): لون ذهبي مستقل عن ألوان الإيصال/المصروف — مش نوع بند على إذن */
.pill.rc-misc{background:#FBF3D9;color:#6B540A;border:1px solid #E6D49B}

.rc-money{flex:none;text-align:center;min-width:104px}
.rc-paid{font-size:1.05rem;font-weight:900;color:var(--red);font-variant-numeric:tabular-nums}
.rc-actual{font-size:.72rem;font-weight:700;color:var(--sader);margin-top:1px}
.rc-actual.has{color:var(--green)}

.rc-photo{flex:none;display:block;width:46px;height:46px;border-radius:8px;overflow:hidden;border:1px solid var(--line2)}
.rc-photo img{width:100%;height:100%;object-fit:cover;display:block}
.rc-photo.none{border-style:dashed;border-color:var(--line);background:var(--zebra);opacity:.5}

.rc-acts{flex:none;display:flex;gap:6px}
.rc-btn{min-height:36px;padding:0 12px;border:1px solid var(--line2);border-radius:8px;
  background:#fff;font-family:inherit;font-weight:800;font-size:.85rem;cursor:pointer;color:var(--navy)}
.rc-btn:hover{border-color:var(--navy)}
.rc-btn.ok{background:var(--green-lt);border-color:#B9DCC6;color:var(--green)}
.rc-btn.ok:hover{background:var(--green);color:#fff}
.rc-btn.no{color:var(--red)}
.rc-btn.no:hover{background:var(--red-lt);border-color:var(--red)}

.rc-edit{padding:4px 18px 16px;background:var(--zebra);border-top:1px dashed var(--line2)}
.rc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin:12px 0}
.rc-grid label{display:block;font-size:.78rem;font-weight:800;color:var(--grey)}
.rc-grid label.hi{color:var(--green)}
.rc-grid input,.rc-grid select{width:100%;margin-top:4px;padding:7px 9px;border:1px solid var(--line2);
  border-radius:8px;font-size:.9rem;background:#fff}
.rc-grid label.hi input{border-color:#B9DCC6;background:#F6FCF8}
.rc-edit-acts{display:flex;gap:8px;flex-wrap:wrap}
.rc-edit-acts .btn{min-height:38px;padding:0 16px;font-size:.88rem}
.rc-inline-retry{display:flex;align-items:end;gap:8px;padding:10px 18px;background:var(--red-lt);flex-wrap:wrap}
.rc-inline-retry label{flex:1;min-width:220px;font-size:.8rem;font-weight:800}
.rc-inline-retry input{display:block;width:100%;margin-top:4px;padding:7px 9px;border:1px solid var(--line2);border-radius:8px}

.rc-day-foot{display:flex;align-items:center;gap:8px;padding:13px 18px;flex-wrap:wrap}
.rc-hint{font-size:.76rem;color:var(--grey);margin-inline-start:auto}

@media (max-width:820px){
  .rc-row{flex-wrap:wrap}
  .rc-main{flex:1 1 100%;order:1}
  .rc-money{order:2;text-align:right;min-width:0}
  .rc-photo{order:3}
  .rc-acts{order:4;margin-inline-start:auto}
}
</style>

<script>
// فتح/قفل فورم تعديل البند
function rcToggle(id) {
  var el = document.getElementById(id);
  if (el) { el.hidden = !el.hidden; }
}

// «علّم الكل» لكل يوم
document.querySelectorAll('.js-rc-all').forEach(function (master) {
  var form = master.closest('form');
  master.addEventListener('change', function () {
    form.querySelectorAll('.js-rc-one').forEach(function (c) { c.checked = master.checked; });
  });
  form.querySelectorAll('.js-rc-one').forEach(function (c) {
    c.addEventListener('change', function () {
      var all = form.querySelectorAll('.js-rc-one');
      var on  = form.querySelectorAll('.js-rc-one:checked');
      master.checked = all.length === on.length;
      master.indeterminate = on.length > 0 && on.length < all.length;
    });
  });
});

function rcPicked(form) { return form.querySelectorAll('.js-rc-one:checked').length; }

// تعديل جماعي — يملّى الحقول المخفية في نفس الفورم
function rcBulkEdit(button) {
  if (button.dataset.ready === '1') { button.dataset.ready = ''; return true; }
  var form = button.form;
  var n = rcPicked(form);
  if (!n) { window.AppUI.notice('علّم بند واحد على الأقل.'); return false; }
  window.AppUI.ask('سيبه فاضي لو مش عايز تغيّر التاريخ.', function(d){
    window.AppUI.ask('سيبه فاضي لو مش عايز تغيّر السعر.', function(a){
      d=(d||'').trim();a=(a||'').trim();
      if (!d&&!a) { window.AppUI.notice('ما اخترتش حاجة تتعدّل.'); return; }
      if (d&&!/^\d{4}-\d{2}-\d{2}$/.test(d)) { window.AppUI.notice('التاريخ لازم يكون بالشكل ده: 2026-07-18'); return; }
      window.AppUI.confirm('تعدّل '+n+' بند؟',function(){form.querySelector('input[name=entry_date]').value=d;form.querySelector('input[name=actual_unit]').value=a;button.dataset.ready='1';button.click();},{danger:false});
    },{title:'السعر الفعلي للوحدة',required:false,inputType:'number'});
  },{title:'تاريخ جديد لـ'+n+' بند',required:false,placeholder:'YYYY-MM-DD'});
  return false;
}

// رفض جماعي بنفس السبب
function rcBulkReject(button) {
  if (button.dataset.ready === '1') { button.dataset.ready = ''; return true; }
  var form = button.form;
  var n = rcPicked(form);
  if (!n) { window.AppUI.notice('علّم بند واحد على الأقل.'); return false; }
  window.AppUI.ask('المندوب هيشوف السبب على موبايله.',function(why){why=(why||'').trim();if(!why)return;form.querySelector('input[name=reason]').value=why;button.dataset.ready='1';button.click();},{title:'سبب رفض '+n+' بند',okText:'رفض البنود'});
  return false;
}

// رفض بند واحد
function repReject(id) {
  window.AppUI.ask('المندوب هيشوف السبب على موبايله.',function(why){why=(why||'').trim();if(!why)return;var f=document.getElementById('r'+id+'f');var input=f.querySelector('input[name=reason]');input.value=why;if(f.requestSubmit)f.requestSubmit();else f.submit();},{title:'سبب رفض البند',okText:'رفض البند'});
}
</script>
