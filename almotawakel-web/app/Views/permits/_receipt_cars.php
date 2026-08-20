<?php
/**
 * توزيع إيصالات بند على عربيات الإذن — **من داخل صفحة الإذن** (v109).
 *
 * ⚠️ **ليه نسخة تانية غير `partials/receipt_distribute`؟** لأن لوحة الصندوق
 *    مبنية على أصناف `ib-*` **معرَّفة في `<style>` محلي جوّه
 *    `rep_custody/inbox.php`** — مش في `app.css`. رندرتها هنا كانت هتطلع
 *    بلا أي تنسيق (المصيدة الموثّقة: أي كلاس بيتطبع لازم يكون له CSS، والناقص
 *    بيقع على الشكل الافتراضي **بالسكوت**). الملف ده بيستخدم أصناف الصفحة
 *    نفسها (`tl-fields` · `tl-form-actions`) فمفيش سطر CSS جديد.
 *
 * ⚠️ **التوزيع وصفي بحت** — مفيش مبلغ بيتغيّر هنا، فمفيش حارس تقفيل ولا يوم.
 *
 * @var array  $lines سطور البند @var array $cars عربيات الإذن
 * @var int    $stageId @var int $pid
 */
$lines = $lines ?? [];
$cars  = $cars  ?? [];
if (!$lines) { return; }

$left = count(array_filter($lines, static fn ($l) => $l['bawali_id'] === null));
?>
<details class="tl-add">
  <summary>
    <?= icon('truck', 14) ?>
    <?php /* تحذير وظيفي بالرقم — مش جملة تعليمية. */ ?>
    <?= $left
        ? 'العربيات — ' . (int) $left . ' من ' . count($lines) . ' لسه بلا عربية'
        : 'العربيات — كله متوزّع' ?>
  </summary>

  <?php if (!$cars): ?>
    <div class="tl-none"><span><?= icon('alert', 14) ?> الإذن ده لسه مالوش بوالص.</span></div>
  <?php else: ?>
    <form class="tl-form" method="post" action="<?= url('permits/stage/' . (int) $stageId . '/distribute') ?>">
      <?= csrfField() ?>
      <div class="tl-fields">
        <?php foreach ($lines as $i => $l): $lid = (int) $l['id']; ?>
          <label>
            <?= e(money((float) $l['amount'], false)) ?>
            <select name="car[<?= $lid ?>]" class="js-rd-car">
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
      <div class="tl-form-actions">
        <button class="btn gold sm" type="submit"><?= icon('check', 14) ?> احفظ التوزيع</button>
        <button class="btn ghost sm" type="button" data-rd-seq-local>وزّع بالترتيب</button>
      </div>
    </form>
  <?php endif; ?>
</details>
