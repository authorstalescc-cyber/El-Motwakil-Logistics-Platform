<?php
/**
 * القوائم المنسدلة — مفردات كل قائمة (docs/19 · المرحلة ٣).
 *
 * ⚠️ جزء بيتحمّل من `settings/index.php` — **صفر `<style>` محلي**،
 *    الكلاسات كلها في `app.css` تحت «مركز التحكّم».
 *
 * @var array $lookups @var array $types
 */

/* تجميع القوائم في فئات منطقية — كان في ترويسة الشاشة القديمة، ومكانه هنا
   عشان الجزء يبقى مكتفيًا بنفسه (لو اتحمّل من مكان تاني ما يقعش بالسكوت). */
$lookupGroups = [
    'أطراف التعامل'   => ['company', 'client', 'destination'],
    'بيانات الشحن'    => ['shipping_co', 'agency', 'port'],
    'النقل والحاويات' => ['driver', 'car_owner', 'container_type', 'classification'],
    'الفئات المالية'  => ['mv_expense', 'mv_receipt', 'mv_discount', 'expense_cat'],
];
?>
  <?php foreach ($lookupGroups as $gLabel => $keys): ?>
    <div class="section-head set-mt-xs"><?= e($gLabel) ?></div>
    <div class="st-grid set-mb">
      <?php foreach ($keys as $key): if (!isset($types[$key])) continue; ?>
        <div class="st-lk">
          <h4><?= e($types[$key]) ?> <span class="cnt">(<?= count($lookups[$key]) ?>)</span></h4>
          <div class="chip-list set-my">
            <?php foreach ($lookups[$key] as $item): ?>
              <span class="chip"><?= e($item['value']) ?>
                <form class="confirm-delete" method="post" action="<?= url('settings/lookup/'.$item['id'].'/delete') ?>"  data-confirm="حذف «<?= e($item['value']) ?>» من قائمة <?= e($types[$key]) ?>؟">
                  <?= csrfField() ?><button type="submit" title="حذف">×</button>
                </form>
              </span>
            <?php endforeach; ?>
            <?php if(!$lookups[$key]): ?><span class="dbt-sub">— فارغة —</span><?php endif; ?>
          </div>
          <form class="inline-form no-confirm" method="post" action="<?= url('settings/lookup') ?>">
            <?= csrfField() ?>
            <input type="hidden" name="list_type" value="<?= e($key) ?>">
            <input type="text" name="value" placeholder="أضف عنصراً…">
            <button class="btn sm" type="submit">إضافة</button>
            <label class="st-confirm" title="لو النظام قال إن العنصر قريب من عنصر موجود وانت متأكد إنه مختلف فعلاً">
              <input type="checkbox" name="confirm_new" value="1"> مفردة جديدة فعلاً
            </label>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
