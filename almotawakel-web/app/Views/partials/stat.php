<?php
/**
 * رقم (KPI) — المكوّن الموحّد للغة النظام.
 *
 * ⚠️ **قاعدة المالك الدائمة: ممنوع رقم أصمّ.** المكوّن ده **بيرفض يترسم بلا
 *    مصدر تفاصيل** — الإجبار هنا في المكوّن نفسه مش في مراجعة بشرية، عشان
 *    مستحيل شاشة جديدة تعرض رقم مالوش تفاصيل ورا.
 *    التفاصيل في docs/14-design-language-plan.md §5أ.
 *
 * الفرق بينه وبين `kpi` القديم: ده بيطبع لغة النظام (`.d-kpi`) اللي كانت
 * حبيسة الداشبورد، و`kpi` بيطبع `.kpi` القديم. الشاشات بتتحوّل ليه بالتدريج.
 *
 * المتغيّرات:
 *   label  string   السطر الصغير تحت الرقم
 *   value  string   الرقم (متنسّق مسبقًا بـ`money0()`/`intf()`)
 *   drill  string   **إجباري** — `#id` لمحتوى جاهز في الصفحة، أو مسار يُجلب عند الضغط
 *   title  ?string  عنوان النافذة (الافتراضي = label)
 *   icon   ?string  اسم أيقونة من `icon()` — **مش إيموجي**
 *   tone   ?string  navy|green|gold|purple|teal|red|sader
 *   unit   ?string  العملة أو الوحدة جنب الرقم
 *   secret ?bool    الرقم يتقنّع لحد ما المالك يضغط «إظهار الأرصدة»
 */
$label = $label ?? '';
$value = (string) ($value ?? '');
$drill = trim((string) ($drill ?? ''));
$title = $title ?? $label;
$icon  = $icon  ?? 'bar-chart';
$tone  = $tone  ?? 'navy';
$unit  = $unit  ?? null;
$secret = !empty($secret);

if ($drill === '') {
    // الفشل ظاهر في مصدر الصفحة بدل ما يعدّي بالسكوت (نفس أسلوب `partial()`)
    echo '<!-- ⚠️ stat بلا drill: «' . e((string) $label) . '» — ممنوع رقم أصمّ -->';
    return;
}
?>
<button type="button" class="d-kpi <?= e($tone) ?>"
        data-drill="<?= e($drill) ?>" data-drill-title="<?= e((string) $title) ?>">
  <span class="d-kpi-ic"><?= icon($icon, 20) ?></span>
  <div class="d-kpi-val">
    <?php if ($secret): ?>
      <span data-secret data-val="<?= e($value) ?>">••••••</span>
    <?php else: ?>
      <?= e($value) ?>
    <?php endif; ?>
    <?php if ($unit): ?><span class="d-kpi-suf"><?= e($unit) ?></span><?php endif; ?>
  </div>
  <div class="d-kpi-lbl"><?= e((string) $label) ?> <span class="d-kpi-eye"><?= icon('search', 12) ?></span></div>
</button>
