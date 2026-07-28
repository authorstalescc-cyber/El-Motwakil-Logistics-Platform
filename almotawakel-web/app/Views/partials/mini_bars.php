<?php
/**
 * مخطط أعمدة صغير — اتجاه رقم عبر فترات متتالية.
 *
 * ⚠️ **CSS خالص بلا أي مكتبة رسم.** المشروع شال Chart.js من الداشبورد
 *    (٢٠٠ كيلوبايت مع كل فتحة)، والـCSP بتمنع أي سكربت من برّه. العمود
 *    `<div>` بارتفاع نسبة مئوية — بيتطبع، وبيشتغل في الوضع الليلي، وبيحترم
 *    `prefers-reduced-motion`.
 *
 * ⚠️ **قاعدة «ممنوع رقم أصمّ»:** كل عمود ليه `url` بينقل الشاشة لفترته، فالرسم
 *    مش صورة — نقطة دخول للتفاصيل.
 *
 * المتغيّرات:
 *   bars   array   كل عنصر: ['label'=>, 'value'=>float, 'display'=>?نص المبلغ,
 *                            'url'=>?, 'title'=>?, 'on'=>?bool (الفترة الحالية)]
 * ⚠️ **مفيش `note` — اتشال (قانون التصميم، بند ١).** كان بيطبع «اضغط أي
 *    عمود عشان الشاشة تنقل للفترة دي» — شرح لطريقة استخدام حاجة مبيّنة
 *    أصلاً (العمود رابط بمؤشّر يد و`title`).
 *   tone   ?string navy|green|gold|red (لون الأعمدة)
 *   height ?int    ارتفاع منطقة الرسم بالبكسل (الافتراضي 120)
 */
$__p_bars = $bars ?? [];
$__p_tone = $tone ?? 'navy';
$__p_h    = (int) ($height ?? 120);

$__p_max = 0.0;
foreach ($__p_bars as $__p_b) { $__p_max = max($__p_max, (float) ($__p_b['value'] ?? 0)); }
?>
<?php if (!$__p_bars): ?>
  <?php partial('empty', ['emoji' => '📉', 'title' => 'مفيش بيانات كفاية للرسم']); ?>
<?php else: ?>
<div class="mb-chart <?= e($__p_tone) ?>" style="--mb-h:<?= $__p_h ?>px">
  <?php foreach ($__p_bars as $__p_b):
      $__p_v   = (float) ($__p_b['value'] ?? 0);
      $__p_pct = $__p_max > 0 ? max(2, $__p_v / $__p_max * 100) : 2;
      $__p_url = $__p_b['url'] ?? null;
      $__p_tag = $__p_url ? 'a' : 'div';
  ?>
    <<?= $__p_tag ?> class="mb-col<?= !empty($__p_b['on']) ? ' on' : '' ?><?= $__p_v <= 0 ? ' zero' : '' ?>"
       <?= $__p_url ? 'href="' . e($__p_url) . '"' : '' ?>
       title="<?= e((string) ($__p_b['title'] ?? ($__p_b['label'] ?? ''))) ?>">
      <span class="mb-val"><?= e((string) ($__p_b['display'] ?? '')) ?></span>
      <span class="mb-bar" style="height:<?= number_format($__p_pct, 2, '.', '') ?>%"></span>
      <span class="mb-lbl"><?= e((string) ($__p_b['label'] ?? '')) ?></span>
    </<?= $__p_tag ?>>
  <?php endforeach; ?>
</div>
<?php endif; ?>
