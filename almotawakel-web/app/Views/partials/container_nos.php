<?php
/**
 * أرقام الحاويات — خانة لكل حاوية.
 *
 * ليه موجود: البوليصة عليها أكتر من حاوية، وكان فيه **خانة واحدة** فصاحب
 * المشروع بيحشر الرقمين جوّاها («286911/9-137074/2»). المكوّن ده بيرسم خانة
 * لكل حاوية حسب «عدد الحاويات»، والخانات بتتزوّد/تقلّ **حيًّا** من `app.js`.
 *
 * ⚠️ العمود في القاعدة لسه **نصّ واحد `bawalis.container_no`** — مفيش هجرة ولا
 *    أي تغيير في أي حساب. الجمع بيحصل في نقطة واحدة: `container_nos_normalize_request()`
 *    في `bootstrap.php` قبل أي كنترولر. والتفكيك عند التعديل بـ`container_nos_split()`.
 *
 * ⚠️ الخانات اسمها `container_no[]` — لو غيّرت الاسم لازم تغيّر معاه دالة
 *    التطبيع في `helpers.php` وإلا الأرقام تتكتب في القاعدة كـ«Array».
 *
 * المتغيّرات:
 *   value  ?string  قيمة العمود المخزَّنة (أو '' عند الإنشاء)
 *   count  ?int     عدد الحاويات — بيحدّد عدد الخانات الابتدائي
 *   label  ?string  تسمية الحقل (الافتراضي «رقم الحاوية»)
 *   wrap   ?string  كلاس الغلاف حسب الشاشة: `field` (crud) أو `bf-fld` (الإذن)
 *   name   ?string  اسم الحقل (الافتراضي `container_no`)
 */
$value = $value ?? '';
$count = (int) ($count ?? 0);
$label = $label ?? 'رقم الحاوية';
$wrap  = $wrap  ?? 'field';
$name  = $name  ?? 'container_no';

$nos   = container_nos_split($value, $count);
$multi = count($nos) > 1;
?>
<div class="<?= e($wrap) ?> cno-field<?= $multi ? ' multi' : '' ?>" data-container-nos data-cno-name="<?= e($name) ?>">
  <label><?= e($label) ?><?php if ($multi): ?> <span class="cno-n"><?= count($nos) ?></span><?php endif; ?></label>
  <div class="cno-list<?= $multi ? ' multi' : '' ?>">
    <?php foreach ($nos as $i => $one): ?>
      <div class="cno-row">
        <span class="cno-idx" aria-hidden="true"><?= $i + 1 ?></span>
        <input name="<?= e($name) ?>[]" value="<?= e($one) ?>" autocomplete="off"
               aria-label="<?= e($label) ?> <?= $i + 1 ?>"
               placeholder="<?= $multi ? e($label) . ' ' . ($i + 1) : e($label) ?>">
      </div>
    <?php endforeach; ?>
  </div>
  
</div>
