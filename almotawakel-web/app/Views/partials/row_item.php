<?php
/**
 * صف قابل للنقر — **بديل الجدول** في أغلب حالات النظام.
 *
 * ٤٧ شاشة من ١١٧ بتعرض `<table class="data">` خام بستة أعمدة أو أكتر مليانة
 * `—`. الجدول مناسب للمقارنة الرقمية بين صفوف كتير؛ لكن أغلب قوايم النظام
 * (أذونات، بنود، مصروفات، مناديب) **قايمة كيانات** — الصف فيها أوضح وأسرع
 * قراءة، وبيشتغل على الموبايل بلا تمرير أفقي ولا طيّ أعمدة.
 *
 * المتغيّرات:
 *   title   string            العنوان (اسم العميل / البيان / رقم الإذن)
 *   url     ?string           لو موجود الصف يبقى رابط
 *   icon    ?string           اسم أيقونة من `icon()`
 *   glyph   ?string           بديل: حرف/سهم قصير (زي ↗ للصادر) — مش إيموجي
 *   tone    ?string           sader|warid — لون مربّع الأيقونة
 *   ref     ?string           مرجع صغير جنب العنوان (#رقم)
 *   meta    ?array            سطر التفاصيل: كل عنصر ['icon'=>?, 'text'=>]
 *   chips   ?array            شارات بارزة: كل عنصر ['icon'=>?, 'text'=>]
 *   amount  ?string           المبلغ على الجنب (متنسّق مسبقًا)
 *   amount_tone ?string       ex (أحمر) | rc (ذهبي) | pos | neg
 *   sub     ?string           سطر صغير تحت المبلغ (تاريخ مثلاً)
 *   action  ?array            زر تصرّف ['label'=>, 'url'=>, 'class'=>'d-act-btn green']
 *   drill   ?string           لو موجود الصف بيفتح تفاصيله في النافذة بدل ما ينقل
 */
$title  = (string) ($title ?? '');
$url    = $url ?? null;
$icon   = $icon ?? null;
$glyph  = $glyph ?? null;
$tone   = $tone ?? '';
$ref    = $ref ?? null;
$meta   = $meta ?? [];
$chips  = $chips ?? [];
$amount = $amount ?? null;
$amountTone = $amount_tone ?? '';
$sub    = $sub ?? null;
$action = $action ?? null;
$drill  = trim((string) ($drill ?? ''));
$extra  = $extra ?? '';

$tag = $url ? 'a' : 'div';
$attrs = $url ? ' href="' . e($url) . '"' : '';
if ($drill !== '') {
    $attrs .= ' data-drill="' . e($drill) . '" data-drill-title="' . e($title) . '"';
}
?>
<<?= $tag ?> class="d-row"<?= $attrs ?>>
  <?php if ($icon || $glyph): ?>
    <span class="d-type<?= $tone !== '' ? ' ' . e($tone) : '' ?>"><?= $glyph !== null ? e($glyph) : icon($icon, 18) ?></span>
  <?php endif; ?>
  <div class="d-row-body">
    <div class="d-row-t">
      <b><?= e($title) ?></b>
      <?php if ($ref !== null && $ref !== ''): ?><span class="d-ref"><?= e($ref) ?></span><?php endif; ?>
    </div>
    <?php if ($chips || $meta): ?>
      <div class="d-row-m">
        <?php foreach ($chips as $c): ?>
          <span class="d-cchip"><?php if (!empty($c['icon'])) { echo icon($c['icon'], 12); } ?> <?= e((string) ($c['text'] ?? '')) ?></span>
        <?php endforeach; ?>
        <?php foreach ($meta as $m): ?>
          <span><?php if (!empty($m['icon'])) { echo icon($m['icon'], 12); } ?> <?= e((string) ($m['text'] ?? '')) ?></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php if ($amount !== null): ?>
    <div class="d-feed-side">
      <b class="<?= e($amountTone) ?>"><?= e((string) $amount) ?></b>
      <?php if ($sub !== null && $sub !== ''): ?><span><?= e((string) $sub) ?></span><?php endif; ?>
    </div>
  <?php endif; ?>
  <?php if (is_array($action) && !empty($action['url']) && !empty($action['label'])): ?>
    <a class="<?= e($action['class'] ?? 'd-act-btn green') ?>" href="<?= e($action['url']) ?>"><?= e($action['label']) ?> <?= icon('chevron', 13) ?></a>
  <?php elseif ($url): ?>
    <span class="d-go"><?= icon('chevron', 16) ?></span>
  <?php endif; ?>
  <?php if ($extra !== '' && $extra !== null): ?>
    <span class="d-row-extra"><?php partial_body($extra); ?></span>
  <?php endif; ?>
</<?= $tag ?>>
