<?php
/**
 * شريط تبويبات — المكوّن الموحّد (**جديد**).
 *
 * النظام فيه **٥ أنظمة تبويبات مختلفة**، واتنين منهم (`debts` و`settings`)
 * بيبدّلوا بالجافاسكريبت **من غير ما العنوان يتغيّر** — يعني الرجوع بالمتصفح
 * بيوديك لتبويب تاني، والرابط مش قابل للمشاركة.
 *
 * ⚠️ المكوّن ده **مش مستخدم في أي شاشة قائمة** (المرحلة ٧ بلا تغيير مرئي).
 *    بيتبنى دلوقتي عشان المراحل ٨ و٩ تلاقيه جاهز.
 *
 * القاعدة: التبويب **رابط حقيقي** (`<a href>`) فالرجوع بالمتصفح والمشاركة
 * والفتح في تبويب جديد كلها بتشتغل بلا أي جافاسكريبت.
 *
 * المتغيّرات:
 *   items   array  كل عنصر: ['label'=>, 'url'=>, 'count'=>?, 'icon'=>?, 'active'=>?bool]
 *   label   ?string  تسمية المجموعة لقارئ الشاشة
 */
$items = $items ?? [];
$label = $label ?? 'تبويبات';
if (!$items) { return; }
?>
<nav class="tabs" aria-label="<?= e($label) ?>">
  <?php foreach ($items as $t):
    $active = !empty($t['active']);
    $count  = $t['count'] ?? null;
  ?>
    <a class="tab<?= $active ? ' active' : '' ?>" href="<?= e($t['url'] ?? '#') ?>"<?= $active ? ' aria-current="page"' : '' ?>>
      <?php if (!empty($t['icon'])): ?><span class="tab-ic"><?= icon($t['icon'], 16) ?></span><?php endif; ?>
      <span class="tab-lbl"><?= e($t['label'] ?? '') ?></span>
      <?php if ($count !== null && $count !== ''): ?><span class="tab-cnt"><?= e((string) $count) ?></span><?php endif; ?>
    </a>
  <?php endforeach; ?>
</nav>
