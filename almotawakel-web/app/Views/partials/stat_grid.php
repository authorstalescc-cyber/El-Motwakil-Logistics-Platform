<?php
/**
 * شبكة أرقام النظام (`.d-kpis`).
 *
 * ⚠️ **ممنوع `grid-template-columns` inline** — الـinline أقوى من الـstylesheet
 *    فبيدهس نقاط الكسر المتجاوبة ويفضل ٤-٦ أعمدة على الموبايل (باج موثَّق).
 *    العدد بيتحدّد بكلاس وليه نقاط كسر حقيقية.
 *
 * المتغيّرات:
 *   items int             عدد الأعمدة (3..6)
 *   body  string|callable بطاقات `stat`
 *   class ?string
 */
$map   = [3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six'];
$items = (int) ($items ?? 4);
$cols  = $map[$items] ?? 'four';
$body  = $body ?? '';
$class = $class ?? '';
?>
<div class="d-kpis <?= e($cols) ?><?= $class !== '' ? ' ' . e($class) : '' ?>"><?php partial_body($body); ?></div>
