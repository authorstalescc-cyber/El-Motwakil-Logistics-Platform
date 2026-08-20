<?php
/**
 * لوحة توزيع إيصالات بند على عربيات الإذن (v109) — **المصدر الوحيد** لماركب
 * التوزيع، عشان صندوق المراجعة وصفحة الإذن يوزّعوا بنفس الشكل وبنفس القواعد.
 *
 * ⚠️ **سطر لكل إيصال — لا قسمة بالتساوي على العربيات.** الإيصالات أسعارها
 *    مختلفة (ده كل سبب وجود الوحدة)، فتوزيع الإجمالي بالتساوي كان هيحمّل
 *    العربية اللي إيصالها ١٨٠ نفس اللي إيصالها ٤٠٠. كل سطر بيروح لعربيته.
 *
 * ⚠️ **`<select>` مش شرايح** عن قصد: بيشتغل بلا جافاسكريبت خالص (نفس قرار
 *    `visa_card_select()` في v22)، وبيفضل مقروءًا لما الإذن يكون فيه ١٢ عربية.
 *
 * ⚠️ **صفر CSS جديد** (قانون التصميم ⑦): كل الأصناف هنا (`ib-panel` ·
 *    `ib-edit-box` · `ib-edit-sec` · `ib-grid` · `ib-panel-acts`) معرَّفة أصلاً
 *    في `rep_custody/inbox.php`.
 *
 * @var array  $lines   سطور الإيصال (من `ReceiptLine::forStage`)
 * @var array  $cars    عربيات الإذن (من `ReceiptLine::carsOfPermit`)
 * @var string $eid     معرّف اللوحة؛ الفورم اسمه "{$eid}f"
 * @var string $action  مسار الحفظ
 * @var string $back    الرجوع بعد الحفظ
 * @var string $note    بيان البند (للعنوان)
 * @var string $token   رمز FormState
 */
use App\Models\ReceiptLine;

$lines = $lines ?? [];
$cars  = $cars  ?? [];
$open  = $open  ?? false;
$total = array_sum(array_map(static fn ($l) => (float) $l['amount'], $lines));
?>
<div class="ib-panel ib-edit-panel" id="<?= e($eid) ?>" <?= $open ? '' : 'hidden' ?>
     role="dialog" aria-modal="true" aria-labelledby="<?= e($eid) ?>t">
  <div class="ib-panel-box ib-edit-box">
    <div class="ib-edit-head">
      <h3 id="<?= e($eid) ?>t"><?= icon('truck', 16) ?> وزّع «<?= e($note ?? '') ?>» على العربيات</h3>
      <button type="button" class="ib-btn" title="اقفل" data-ib-close-edit><?= icon('x', 14) ?></button>
    </div>

    <div class="ib-edit">
      <?php if (!$cars): ?>
        <?php /* تحذير وظيفي (≤٦ كلمات) — مش شرح تعليمي. */ ?>
        <p class="ib-warn"><?= icon('alert', 14) ?> الإذن ده لسه مالوش بوالص.</p>
      <?php else: ?>
        <form method="post" id="<?= e($eid) ?>f" action="<?= e($action) ?>">
          <?= csrfField() ?><?= formStateFields($token, $back) ?>

          <div class="ib-edit-sec">
            <h4><?= count($lines) ?> إيصال · <?= e(money($total, false)) ?> — <?= e(ReceiptLine::summary(
                  array_map(static fn ($l) => (float) $l['amount'], $lines))) ?></h4>
            <div class="ib-grid">
              <?php foreach ($lines as $i => $l): $lid = (int) $l['id']; ?>
                <label for="<?= e($eid) ?>c<?= $lid ?>">
                  <?= e(money((float) $l['amount'], false)) ?>
                  <select id="<?= e($eid) ?>c<?= $lid ?>" name="car[<?= $lid ?>]" class="js-rd-car"
                          data-idx="<?= $i ?>">
                    <option value="0">— مش متوزّع —</option>
                    <?php foreach ($cars as $c): ?>
                      <option value="<?= (int) $c['id'] ?>"
                        <?= (int) ($l['bawali_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                        <?= e($c['label']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </label>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="ib-panel-acts">
            <button class="btn gold" type="submit"><?= icon('check', 14) ?> احفظ التوزيع</button>
            <?php /* ⚠️ زرار مساعدة بحتة — بيملّى الخانات وبس، والحفظ لسه بضغطة
                     مستقلة. الإذن اللي فيه ٤ عربيات و٤ إيصالات ده أشهر شكل،
                     والتوزيع بالترتيب بيخلّص الحالة دي بضغطة. */ ?>
            <button class="btn ghost" type="button" data-rd-seq="<?= e($eid) ?>">وزّع بالترتيب</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
