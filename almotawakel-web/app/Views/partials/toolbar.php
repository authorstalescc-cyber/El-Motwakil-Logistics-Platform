<?php
/**
 * شريط الأدوات — المكوّن الموحّد.
 *
 * النمط السائد في النظام: `.toolbar` فيها عنوان على اليمين، فراغ مطّاط، وأزرار
 * على الشمال. الماركب مطابق للقائم بالحرف.
 *
 * المتغيّرات:
 *   title   ?string          عنوان القسم (بيستخدم `.section-head` زي القديم)
 *   start   string|callable  محتوى بداية الشريط (فورم فلترة مثلاً) — بيتقدّم على title
 *   end     string|callable  الأزرار على الطرف التاني
 *   count   ?string          شارة العدد (`.count-badge`)
 *   class   ?string
 */
$title = $title ?? null;
$start = $start ?? null;
$end   = $end   ?? null;
$count = $count ?? null;
$class = $class ?? '';
?>
<div class="toolbar<?= $class !== '' ? ' ' . e($class) : '' ?>">
  <?php if ($start !== null): partial_body($start);
  elseif ($title !== null): ?><div class="section-head toolbar-title"><?= e($title) ?></div><?php endif; ?>
  <div class="spacer"></div>
  <?php if ($count !== null && $count !== ''): ?><span class="count-badge"><?= e($count) ?></span><?php endif; ?>
  <?php if ($end !== null) { partial_body($end); } ?>
</div>
