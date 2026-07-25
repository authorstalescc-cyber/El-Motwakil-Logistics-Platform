<?php
/**
 * كارت — المكوّن الموحّد.
 *
 * النظام كان فيه **١٣ طريقة مختلفة** لعمل «كارت». الماركب هنا مطابق للنمط
 * السائد: `.card` وجوّاها `.section-head` اختيارية.
 *
 * المتغيّرات:
 *   title  ?string    عنوان الكارت
 *   sub    ?string    سطر وصف تحت العنوان
 *   tools  ?string    HTML جاهز يتحط في يمين الترويسة (أزرار) — **مش بيتهرّب**
 *   body   string|callable   المحتوى: نص HTML جاهز أو دالة بتطبع
 *   class  ?string    كلاسات إضافية على `.card`
 *   id     ?string
 */
$title = $title ?? null;
$sub   = $sub   ?? null;
$tools = $tools ?? null;
$body  = $body  ?? '';
$class = $class ?? '';
$id    = $id    ?? null;
?>
<div class="card<?= $class !== '' ? ' ' . e($class) : '' ?>"<?= $id ? ' id="' . e($id) . '"' : '' ?>>
  <?php if ($title !== null || $tools !== null): ?>
  <div class="card-head">
    <div class="card-head-txt">
      <?php if ($title !== null): ?><div class="section-head"><?= e($title) ?></div><?php endif; ?>
      <?php if ($sub !== null && $sub !== ''): ?><div class="card-head-sub"><?= e($sub) ?></div><?php endif; ?>
    </div>
    <?php if ($tools !== null): ?><div class="card-head-tools"><?= $tools ?></div><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php partial_body($body); ?>
</div>
