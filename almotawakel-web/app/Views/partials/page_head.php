<?php
/**
 * ترويسة صفحة — المكوّن الموحّد (**جديد**).
 *
 * النظام فيه **١٢ نمط ترويسة مختلف**، و**٣٩ شاشة من ٨٥ بلا أي `<h1>`** في
 * المتن (العنوان بيظهر في شريط علوي بس، فقارئ الشاشة والطباعة بيضيع منهم
 * عنوان الصفحة). والإجراء الأساسي مكانه بيختلف من شاشة لشاشة.
 *
 * ⚠️ المكوّن ده **مش مستخدم في أي شاشة قائمة** (المرحلة ٧ بلا تغيير مرئي).
 *    التبنّي بيحصل في المراحل ٨ و٩ شاشة شاشة.
 *
 * القاعدة: **الإجراء الأساسي دايمًا آخر حاجة في الترويسة وبنفس الشكل.**
 *
 * المتغيّرات:
 *   title    string
 * ⚠️ **مفيش `sub` — اتشال (قانون التصميم، بند ١: صفر جملة تعليمية).**
 *    العنوان بيقول إيه ده؛ الجملة اللي تحته كانت بتشرح ليه/إزاي.
 *   actions  ?string|callable إجراءات ثانوية (HTML جاهز)
 *   primary  ?array           ['label'=>, 'url'=>, 'class'=>'btn gold']
 *   back     ?array           ['label'=>, 'url'=>] — زر رجوع بالحرف `‹`
 */
$title   = $title   ?? '';
$actions = $actions ?? null;
$primary = $primary ?? null;
$back    = $back    ?? null;
?>
<div class="page-head">
  <div class="page-head-txt">
    <?php if (is_array($back) && !empty($back['url'])): ?>
      <?php /* ⚠️ الحرف `‹` هو الصح في RTL لأنه bidi-mirrored بيتقلب تلقائيًّا؛
               `←`/`→` مش بيتقلبوا فبيشاوروا في الاتجاه الغلط. */ ?>
      <a class="page-back" href="<?= e($back['url']) ?>"><span aria-hidden="true">‹</span> <?= e($back['label'] ?? 'رجوع') ?></a>
    <?php endif; ?>
    <h1 class="page-h1"><?= e($title) ?></h1>
  </div>
  <div class="page-head-actions">
    <?php if ($actions !== null) { partial_body($actions); } ?>
    <?php if (is_array($primary) && !empty($primary['url']) && !empty($primary['label'])): ?>
      <a class="<?= e($primary['class'] ?? 'btn gold') ?>" href="<?= e($primary['url']) ?>"><?= e($primary['label']) ?></a>
    <?php endif; ?>
  </div>
</div>
