<?php
/**
 * جدول — المكوّن الموحّد.
 *
 * ليه موجود: **١٢ جدول في النظام كانوا بلا حاوية تمرير** (أعرضهم جدول بـ١٤
 * عمود)، فكانوا بيخرجوا برّه الشاشة على الموبايل. المكوّن ده بيضمن `.table-wrap`
 * دايمًا، وبيدمج حالة الفراغ جوّه الجدول بدل ما كل شاشة تكتبها بإيدها.
 *
 * ⚠️ أدوات الجداول العامة (ترتيب/إخفاء أعمدة/كثافة) بتتفعّل تلقائيًّا من
 *    `app.js` على `table.data` — فما تكتبش ترتيبًا خاصًّا لأي صفحة.
 *
 * المتغيّرات:
 *   cols    array           رؤوس الأعمدة: نص، أو ['label'=>, 'class'=>, 'attrs'=>]
 *   rows    string|callable صفوف `<tr>` جاهزة أو دالة بتطبعها
 *   count   ?int            عدد الصفوف — لو صفر بتتعرض حالة الفراغ
 *   empty   ?array          متغيّرات `partial('empty', …)`
 *   foot    ?string         `<tfoot>` جاهز — **مش بيتهرّب**
 *   class   ?string         كلاسات إضافية على `<table>` (الافتراضي `data`)
 *   wrapClass ?string       كلاسات إضافية على `.table-wrap`
 */
$cols      = $cols      ?? [];
$rows      = $rows      ?? '';
$count     = $count     ?? null;
$empty     = $empty     ?? [];
$foot      = $foot      ?? null;
$class     = $class     ?? '';
$wrapClass = $wrapClass ?? '';
$isEmpty   = ($count !== null && (int) $count === 0);
?>
<div class="table-wrap<?= $wrapClass !== '' ? ' ' . e($wrapClass) : '' ?>">
  <table class="data<?= $class !== '' ? ' ' . e($class) : '' ?>">
    <thead>
      <tr>
        <?php foreach ($cols as $col):
          $label = is_array($col) ? ($col['label'] ?? '') : (string) $col;
          $cls   = is_array($col) ? ($col['class'] ?? '')  : '';
          $attrs = is_array($col) ? ($col['attrs'] ?? '')  : '';
        ?>
          <th<?= $cls !== '' ? ' class="' . e($cls) . '"' : '' ?><?= $attrs ?>><?= e($label) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php if ($isEmpty): ?>
        <tr class="norec"><td colspan="<?= max(1, count($cols)) ?>"><?php partial('empty', $empty); ?></td></tr>
      <?php else: partial_body($rows); endif; ?>
    </tbody>
    <?php if ($foot !== null && !$isEmpty): ?><tfoot><?= $foot ?></tfoot><?php endif; ?>
  </table>
</div>
