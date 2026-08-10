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
        <div class="st-lk" data-lk="<?= e($key) ?>">
          <?php /* ⚠️ **الفلتر لكل قائمة مش زينة**: الشاشة كانت بترسم ٢١٧٣ مفردة
                   دفعة واحدة — جدار شرايح مستحيل تلاقي فيه حاجة، وكروت بأطوال
                   متنافرة بتكسر الصف. الفلتر + الارتفاع المحدود بيحلّوا الاتنين. */ ?>
          <div class="st-lk-head">
            <h4><?= e($types[$key]) ?> <span class="cnt">(<?= count($lookups[$key]) ?>)</span></h4>
            <?php if (count($lookups[$key]) > 12): ?>
              <input type="search" class="st-lk-filter" data-lk-filter="<?= e($key) ?>"
                     aria-label="<?= e('فلترة ' . $types[$key]) ?>">
            <?php endif; ?>
          </div>
          <div class="chip-list st-lk-chips">
            <?php foreach ($lookups[$key] as $item): ?>
              <span class="chip" data-v="<?= e(mb_strtolower($item['value'])) ?>"><?= e($item['value']) ?>
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

<script>
/* فلترة مفردات القائمة في المتصفح — القوايم بتوصل ٤٥٧ مفردة، والتنقّل للخادم
   لكل حرف كان هيبقى أبطأ من الفايدة. */
(function () {
  document.querySelectorAll('[data-lk-filter]').forEach(function (box) {
    var card = box.closest('[data-lk]');
    if (!card) { return; }
    var wrap  = card.querySelector('.st-lk-chips');
    var chips = Array.prototype.slice.call(card.querySelectorAll('.chip[data-v]'));
    var none  = document.createElement('div');
    none.className = 'st-lk-empty'; none.hidden = true; none.textContent = 'مفيش مفردة بالاسم ده';
    if (wrap) { wrap.appendChild(none); }
    box.addEventListener('input', function () {
      var q = box.value.trim().toLowerCase();
      var shown = 0;
      chips.forEach(function (c) {
        var on = q === '' || (c.dataset.v || '').indexOf(q) >= 0;
        /* ⚠️ `hidden` مش `style.display` — `[hidden]{display:none!important}`
           في `app.css` بتغلب أي `display` من الجافاسكريبت. */
        if (on) { c.removeAttribute('hidden'); shown++; } else { c.setAttribute('hidden', ''); }
      });
      none.hidden = shown > 0;
    });
  });
})();
</script>
