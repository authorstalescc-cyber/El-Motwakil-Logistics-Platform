<?php
/**
 * شبكة بطاقات الأرقام.
 *
 * ⚠️ **ممنوع `grid-template-columns` inline هنا ولا في أي شاشة.** كان فيه
 *    **١١ شبكة مكتوبة inline** في النظام، والـinline **أقوى من الـstylesheet**
 *    فكانت بتدهس نقاط الكسر المتجاوبة وتفضل ٤-٦ أعمدة على الموبايل. العدد
 *    بيتحدّد بكلاس (`three`/`four`/`five`/`six`) وليه نقاط كسر حقيقية.
 *
 * المتغيّرات:
 *   items int|string  عدد الأعمدة (2..6) — أو مرّر `cols` كنص الكلاس مباشرة
 *   body  string|callable  البطاقات
 *   class ?string
 */
$map   = [2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six'];
$items = $items ?? 3;
$cols  = $cols ?? ($map[(int) $items] ?? 'three');
$body  = $body ?? '';
$class = $class ?? '';
?>
<div class="kpi-grid <?= e($cols) ?><?= $class !== '' ? ' ' . e($class) : '' ?>"><?php partial_body($body); ?></div>
