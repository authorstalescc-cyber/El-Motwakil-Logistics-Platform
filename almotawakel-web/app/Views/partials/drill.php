<?php
/**
 * جسم نافذة التفاصيل — الشكل الموحّد لأي drill-down.
 *
 * ⚠️ **البوب-أب مش بديل الشاشة.** هو إجابة سريعة على «الرقم ده جاي منين؟»
 *    وطريق للشاشة الكاملة — عشان كده التذييل فيه المجموع وزر «افتح الشاشة».
 *
 * ⚠️ بيترندر **على الخادم** ويترجّع HTML للنافذة، مش JSON — عشان الصفوف
 *    تستخدم نفس مكوّن `row_item` بتاع الصفحة، فمستحيل التفاصيل يبقى شكلها
 *    مختلف عن القايمة اللي جاية منها.
 *
 * المتغيّرات:
 *   how    ?string          «إزاي اتحسب الرقم ده» — سطر بلغة المالك
 *   body   string|callable  الصفوف (يُفضَّل `row_item`)
 *   empty  ?array           حالة الفراغ ['title'=>, 'note'=>?]
 *   total  ?array           ['label'=>, 'value'=>]
 *   more   ?array           ['label'=>, 'url'=>] زر الشاشة الكاملة
 *   count  ?int             عدد البنود (يظهر جنب المجموع)
 */
$how   = $how ?? null;
$body  = $body ?? '';
$emptyState = $empty ?? null;
$total = $total ?? null;
$more  = $more ?? null;
$count = $count ?? null;
$rendered = trim(is_callable($body) ? (string) (function ($b) { ob_start(); $b(); return ob_get_clean(); })($body) : (string) $body);
?>
<?php if ($how !== null && $how !== ''): ?>
  <div class="drill-how"><?= icon('info', 16) ?><span><?= e((string) $how) ?></span></div>
<?php endif; ?>

<?php if ($rendered === ''): ?>
  <?php partial('empty', [
      'emoji' => '📭',
      'title' => (string) (($emptyState['title'] ?? null) ?: 'مفيش بنود'),
      'note'  => $emptyState['note'] ?? null,
  ]); ?>
<?php else: ?>
  <div class="d-list flat"><?= $rendered ?></div>
<?php endif; ?>

<?php if ($total !== null || $more !== null): ?>
  <div class="drill-foot">
    <?php if (is_array($total)): ?>
      <span class="drill-total"><?= e((string) ($total['label'] ?? 'الإجمالي')) ?>:
        <b><?= e((string) ($total['value'] ?? '')) ?></b></span>
    <?php endif; ?>
    <?php if ($count !== null): ?><span style="color:var(--grey);font-size:var(--fs-1)"><?= intf((int) $count) ?> بند</span><?php endif; ?>
    <?php if (is_array($more) && !empty($more['url'])): ?>
      <a class="btn ghost sm" href="<?= e($more['url']) ?>"><?= e((string) ($more['label'] ?? 'افتح الشاشة الكاملة')) ?> <?= icon('external', 14) ?></a>
    <?php endif; ?>
  </div>
<?php endif; ?>
