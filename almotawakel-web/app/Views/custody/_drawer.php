<?php
/**
 * درج إدخال خزنة المكتب — لوح بيدخل من جنب الشاشة.
 *
 * ═══ ليه درج مش نموذجين مضمَّنين ═══
 * الشاشة القديمة كان فيها **نموذجا إدخال مزروعين في نُصّها**، بياخدوا مساحة
 * دايمًا حتى وانت بتقرا بس — نفس الشكل اللي المالك رفضه في خزنة الشركة قبل v68.
 *
 * ⚠️⚠️ **الحالة في الرابط لا في الجافاسكريبت** (`?new=topup` / `?new=rep`):
 *    • الحفظ لو فشل بيرجّعك والدرج **مفتوح وفيه اللي كتبته**.
 *    • الرجوع بالمتصفح بيقفل الدرج، والرابط قابل للمشاركة.
 *    • ومن غير جافاسكريبت خالص الدرج بيترندر كقسم عادي والفورم شغّال بالكامل.
 *
 * ⚠️ **ولا سطر من منطق الحفظ اتغيّر** — نفس `action` ونفس الخانات ونفس
 *    `Closing::guard` في الكنترولر. ده نقل مكان عرض بس.
 *
 * متغيّرات: $drawer $tab $from $to $topupState $repDepState $repList $lastClosed
 */
use App\Core\FormState;
use App\Models\RepCustody;

$tState  = $topupState  ?? ['token' => FormState::token(), 'old' => [], 'errors' => [], 'back' => 'custody'];
$rState  = $repDepState ?? ['token' => FormState::token(), 'old' => [], 'errors' => [], 'back' => 'custody'];
$tOld    = $tState['old'];   $tErr = $tState['errors'];
$rOld    = $rState['old'];   $rErr = $rState['errors'];
/* الدرج بيفتح كمان لو الحفظ فشل — وإلا رسالة الخطأ بتظهر ورا لوح مقفول. */
$open    = $drawer !== '' || $tErr || $rErr;
$kind    = $drawer !== '' ? $drawer : ($rErr ? 'rep' : 'topup');
$closeUrl = url('custody') . '?tab=' . $tab . '&from=' . $from . '&to=' . $to;
?>
<div class="cs-drawer<?= $open ? ' open' : '' ?>" id="custodyDrawer" <?= $open ? '' : 'hidden' ?>
     role="dialog" aria-modal="true" aria-labelledby="custodyDrawerTitle">
  <a class="cs-drawer-scrim" href="<?= e($closeUrl) ?>" aria-label="إغلاق"></a>

  <div class="cs-drawer-card">
    <div class="cs-drawer-head">
      <span id="custodyDrawerTitle">
        <?= $kind === 'rep' ? icon('wallet', 17) . ' نزّل عهدة لمندوب من الدرج'
                            : icon('plus', 17) . ' إضافة عهدة للرصيد' ?>
      </span>
      <a class="cs-drawer-x" href="<?= e($closeUrl) ?>" aria-label="إغلاق">×</a>
    </div>

    <?php if ($kind === 'rep' && $repList && RepCustody::sourceMatters()): ?>
      <form class="js-dirty-form" method="post" action="<?= url('custody/rep-deposit') ?>">
        <?= csrfField() ?>
        <?= formStateFields($rState['token'], $rState['back']) ?>
        <div class="cs-drawer-body">
          <?= formErrorSummary($rErr) ?>
          <div class="field">
            <label>المندوب</label>
            <select name="rep_id" required<?= fieldErrorAttrs('rep_id', $rErr) ?>>
              <option value="">— اختار —</option>
              <?php foreach ($repList as $rid => $rname): ?>
                <option value="<?= (int) $rid ?>" <?= (string) ($rOld['rep_id'] ?? '') === (string) $rid ? 'selected' : '' ?>><?= e($rname) ?></option>
              <?php endforeach; ?>
            </select>
            <?= fieldError('rep_id', $rErr) ?>
          </div>
          <div class="field">
            <label>التاريخ</label>
            <input type="date" name="dep_date" value="<?= e($rOld['dep_date'] ?? work_date()) ?>"<?= fieldErrorAttrs('dep_date', $rErr) ?>>
            <?= fieldError('dep_date', $rErr) ?>
          </div>
          <div class="field">
            <label>ملاحظة (اختياري)</label>
            <input type="text" name="note" maxlength="255" value="<?= e($rOld['note'] ?? '') ?>" placeholder="عهدة يوم…">
          </div>
          <div class="cs-amt-field">
            <label>المبلغ</label>
            <div class="cs-amt-box<?= isset($rErr['amount']) ? ' bad' : '' ?>">
              <input data-money type="number" step="0.01" min="0.01" name="amount"
                     value="<?= e($rOld['amount'] ?? '') ?>" placeholder="0" required>
            </div>
            <?= fieldError('amount', $rErr) ?>
          </div>
          <?php /* ⚠️ الأثر بيختلف مع النموذج الفعّال — والسطر ده **بيقول الأثر**
                   لا بيشرح القاعدة (≤٦ كلمات). */ ?>
          <div class="cs-effect">
            <?= icon('info', 14) ?>
            <?= RepCustody::newSafeRuleActive()
                ? 'الخزنة تنقص · جيب المندوب يزيد'
                : 'الدرج ينقص · جيب المندوب يزيد · الخزنة زي ما هي' ?>
          </div>
        </div>
        <div class="cs-drawer-foot">
          <a class="btn ghost" href="<?= e($closeUrl) ?>">إلغاء</a>
          <button class="btn gold" type="submit" data-primary-submit><?= icon('plus', 15) ?> نزّل من الدرج</button>
        </div>
      </form>
    <?php else: ?>
      <form class="js-dirty-form" method="post" action="<?= url('custody/topup') ?>">
        <?= csrfField() ?>
        <?= formStateFields($tState['token'], $tState['back']) ?>
        <div class="cs-drawer-body">
          <?= formErrorSummary($tErr) ?>
          <div class="field">
            <label>التاريخ</label>
            <input type="date" name="cust_date" value="<?= e($tOld['cust_date'] ?? work_date()) ?>"<?= fieldErrorAttrs('cust_date', $tErr) ?>>
            <?= fieldError('cust_date', $tErr) ?>
          </div>
          <div class="field">
            <label>البيان / المصدر</label>
            <input type="text" name="note" value="<?= e($tOld['note'] ?? '') ?>" placeholder="مصدر العهدة (اختياري)">
          </div>
          <div class="cs-amt-field">
            <label>المبلغ</label>
            <div class="cs-amt-box<?= isset($tErr['amount']) ? ' bad' : '' ?>">
              <input data-money type="number" step="0.01" min="0.01" name="amount"
                     value="<?= e($tOld['amount'] ?? '') ?>" placeholder="0" required>
            </div>
            <?= fieldError('amount', $tErr) ?>
          </div>
          <div class="cs-effect"><?= icon('info', 14) ?> الخزنة تزيد بالمبلغ</div>
          <?php if ($lastClosed): ?>
            <div class="cs-locked">
              <?= icon('lock', 14) ?> متقفل لحد <?= e(dfmt($lastClosed)) ?>
            </div>
          <?php endif; ?>
        </div>
        <div class="cs-drawer-foot">
          <a class="btn ghost" href="<?= e($closeUrl) ?>">إلغاء</a>
          <button class="btn gold" type="submit" data-primary-submit>إضافة للرصيد</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>
