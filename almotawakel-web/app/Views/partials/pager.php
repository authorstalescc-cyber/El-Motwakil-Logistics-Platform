<?php
/**
 * ترقيم الصفحات — المكوّن الموحّد.
 *
 * نفس المنطق بالحرف كان **مكرَّرًا في ٦ شاشات** (`expenses` · `permits` ·
 * `invoices` · `statements` · `legacy` · `crud`) — نفس الـ١٢ سطر منسوخة، وأي
 * إصلاح كان لازم يتعمل ٦ مرات. الماركب المطبوع هنا **مطابق للقديم بالحرف**.
 *
 * المتغيّرات:
 *   page  int       الصفحة الحالية
 *   pages int       إجمالي الصفحات
 *   qs    callable  دالة بتاخد ['page'=>N] وترجّع نص الـquery بلا «?»
 *   span  ?int      كام صفحة حوالين الحالية (الافتراضي ٢ — زي القديم)
 *   label ?string   لو موجود بيبقى `<nav aria-label=…>` بدل `<div>` (زي الأذونات)
 */
$page  = max(1, (int) ($page ?? 1));
$pages = (int) ($pages ?? 1);
$span  = (int) ($span ?? 2);
$label = $label ?? null;
if ($pages <= 1 || !isset($qs) || !is_callable($qs)) { return; }

$link = static fn(int $p): string => '?' . $qs(['page' => $p]);
$start = max(1, $page - $span);
$end   = min($pages, $page + $span);
$tag   = $label !== null ? 'nav' : 'div';
?>
<<?= $tag ?> class="pager"<?= $label !== null ? ' aria-label="' . e($label) . '"' : '' ?>>
  <?php if ($page > 1): ?><a href="<?= e($link($page - 1)) ?>">‹ السابق</a><?php endif; ?>
  <?php if ($start > 1): ?>
    <a href="<?= e($link(1)) ?>">1</a>
    <?php if ($start > 2): ?><span class="dots">…</span><?php endif; ?>
  <?php endif; ?>
  <?php for ($p = $start; $p <= $end; $p++): ?>
    <?php if ($p === $page): ?><span class="cur"><?= $p ?></span>
    <?php else: ?><a href="<?= e($link($p)) ?>"><?= $p ?></a><?php endif; ?>
  <?php endfor; ?>
  <?php if ($end < $pages): ?>
    <?php if ($end < $pages - 1): ?><span class="dots">…</span><?php endif; ?>
    <a href="<?= e($link($pages)) ?>"><?= $pages ?></a>
  <?php endif; ?>
  <?php if ($page < $pages): ?><a href="<?= e($link($page + 1)) ?>">التالي ›</a><?php endif; ?>
</<?= $tag ?>>
