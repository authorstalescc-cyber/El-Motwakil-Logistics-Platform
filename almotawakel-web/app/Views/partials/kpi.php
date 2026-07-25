<?php
/**
 * بطاقة رقم (KPI) — المكوّن الموحّد.
 *
 * كان فيه **٣ طرق مختلفة** لعمل بطاقة رقم في النظام. الماركب هنا مطابق لـ`.kpi`
 * القائم بالحرف (الشكل معرَّف في `app.css` من قبل المرحلة دي).
 *
 * المتغيّرات:
 *   label  string   السطر الصغير فوق
 *   value  string   الرقم (متنسّق مسبقًا بـ`money()`/`intf()`)
 *   tone   ?string  green|red|gold|teal|purple|navy
 *   unit   ?string  العملة أو الوحدة جنب الرقم
 *   note   ?string  سطر صغير تحت الرقم
 *   bgic   ?string  إيموجي خلفية باهتة
 *   url    ?string  لو موجود البطاقة تبقى رابط (`a.kpi` معرَّف أصلاً)
 *   attrs  ?string  سمات HTML إضافية جاهزة (مثل data-secret) — **مش بتتهرّب**
 */
$label = $label ?? '';
$value = $value ?? '';
$tone  = $tone  ?? '';
$unit  = $unit  ?? null;
$note  = $note  ?? null;
$bgic  = $bgic  ?? null;
$url   = $url   ?? null;
$attrs = $attrs ?? '';
$cls   = 'kpi' . ($tone !== '' ? ' ' . $tone : '');
$tag   = $url ? 'a' : 'div';
?>
<<?= $tag ?> class="<?= e($cls) ?>"<?= $url ? ' href="' . e($url) . '"' : '' ?><?= $attrs ?>>
  <?php if ($bgic): ?><span class="bgic" aria-hidden="true"><?= e($bgic) ?></span><?php endif; ?>
  <div class="lbl"><?= e($label) ?></div>
  <div class="val"><?= e($value) ?><?php if ($unit): ?><span class="cur"><?= e($unit) ?></span><?php endif; ?></div>
  <?php if ($note !== null && $note !== ''): ?><div class="kpi-note"><?= e($note) ?></div><?php endif; ?>
</<?= $tag ?>>
