<?php
/**
 * لوح (panel) — الحاوية الأساسية في لغة النظام (`.d-card`).
 *
 * الفرق عن `card` القديم: ده بيطبع لغة النظام (ترويسة ملوّنة + شارة + إجراء
 * على الجنب + جسم قابل للتمرير)، و`card` بيطبع `.card` العام. الشاشات
 * بتتحوّل ليه بالتدريج.
 *
 * ⚠️ **مفيش `sub` — اتشال عن قصد (قانون التصميم، بند ١: صفر جملة تعليمية).**
 *    كان بيطبع `<small class="d-head-sub">` بجملة بتشرح اللوح («أرقام مشتقّة
 *    — مالهاش مكان تاني في الشاشة» · «الحركة الفعلية بغضّ النظر عن المبالغ»).
 *    الترويسة بتقول **إيه** اللوح ده — مش **ليه** ولا **إزاي**.
 *    محتاج تعرض عددًا أو حالة؟ استخدم `badge`. `bin/verify_density.php` بيفشل
 *    لو `sub` رجعت لأي نداء.
 *
 * المتغيّرات:
 *   title   string            العنوان
 *   icon    ?string           اسم أيقونة من `icon()` — **مش إيموجي**
 *   badge   ?array            ['text'=>, 'tone'=>'red|navy']
 *   action  ?array            ['label'=>, 'url'=>] أو ['label'=>, 'drill'=>, 'title'=>]
 *   scroll  ?bool             الجسم قابل للتمرير بارتفاع محدود (`.d-list`)
 *   body    string|callable
 *   class   ?string
 */
$title  = (string) ($title ?? '');
$icon   = $icon ?? null;
$badge  = $badge ?? null;
$action = $action ?? null;
$scroll = !empty($scroll);
$body   = $body ?? '';
$class  = $class ?? '';
?>
<div class="d-card<?= $class !== '' ? ' ' . e($class) : '' ?>">
  <div class="d-card-head">
    <h3>
      <?php if ($icon) { echo icon($icon, 18); } ?>
      <span><?= e($title) ?></span>
      <?php if (is_array($badge) && ($badge['text'] ?? '') !== ''): ?>
        <span class="d-badge <?= e($badge['tone'] ?? 'navy') ?>"><?= e((string) $badge['text']) ?></span>
      <?php endif; ?>
    </h3>
    <?php if (is_array($action) && ($action['label'] ?? '') !== ''): ?>
      <?php if (!empty($action['drill'])): ?>
        <button type="button" class="btn ghost sm" data-drill="<?= e($action['drill']) ?>"
                data-drill-title="<?= e((string) ($action['title'] ?? $title)) ?>"><?= e($action['label']) ?> <?= icon('chevron', 15) ?></button>
      <?php elseif (!empty($action['url'])): ?>
        <a class="btn ghost sm" href="<?= e($action['url']) ?>"><?= e($action['label']) ?> <?= icon('chevron', 15) ?></a>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <div<?= $scroll ? ' class="d-list"' : '' ?>><?php partial_body($body); ?></div>
</div>
