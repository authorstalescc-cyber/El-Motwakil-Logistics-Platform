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
 *   delta  ?float   نسبة التغيّر عن فترة سابقة (موجب = زيادة). null = بلا مقارنة.
 *   delta_label ?string  اسم الفترة اللي بنقارن بيها («عن الشهر اللي قبله»)
 *   delta_good  ?string  'up' (الزيادة كويسة — الافتراضي) أو 'down' (المصروفات)
 *   trend  ?array   بديل أغنى للمقارنة: ['dir'=>'up|down|flat','text'=>'','tone'=>'good|bad|mute']
 *                   جاي من `Metrics::dashContext()`. **بيتعرض بس لو `delta` غايب**
 *                   فالشاشات اللي بتبعت `delta` رقمي ما بتتأثرش.
 *   spark  ?array   قيم آخر N يوم (أرقام) → رسم خطّي صغير جوّه الكارت
 *
 * ⚠️ المقارنة **إضافة اختيارية** (v54): غيابها = نفس الشكل القديم بالحرف،
 *    فتحويل أي شاشة قايمة ما بيتأثرش.
 *
 * ⚠️ **ليه `trend` نص جاهز مش نسبة مئوية؟** النسبة بتضلّل لما الأساس صغير:
 *    رصيد امبارح ٥٠ والنهاردة ٢٤٥٬٠٥٠ = «+490,000٪» (اتصادف فعليًا). المصدر
 *    (`Metrics::dashContext`) بيحسب الفرق المطلق ويضيف النسبة **بس لو معقولة**،
 *    وهو كمان اللي بيقرّر إن الزيادة كويسة ولا وحشة — لأن اتجاه «الكويس» في
 *    النظام ده مش تلقائي (إحنا مدينين للعملاء — تحذير ١).
 */
$label = $label ?? '';
$value = (string) ($value ?? '');
$drill = trim((string) ($drill ?? ''));
$url   = trim((string) ($url ?? ''));
$title = $title ?? $label;
$icon  = $icon  ?? 'bar-chart';
$tone  = $tone  ?? 'navy';
$unit  = $unit  ?? null;
$delta  = isset($delta) && $delta !== null ? (float) $delta : null;
$deltaLabel = $delta_label ?? null;
$deltaGood  = ($delta_good ?? 'up') === 'down' ? 'down' : 'up';
$trend = (is_array($trend ?? null) && ($trend['text'] ?? '') !== '') ? $trend : null;
$spark = array_values(array_filter((array) ($spark ?? []), 'is_numeric'));

if ($drill === '' && $url === '') {
    // الفشل ظاهر في مصدر الصفحة بدل ما يعدّي بالسكوت (نفس أسلوب `partial()`)
    echo '<!-- ⚠️ stat بلا drill: «' . e((string) $label) . '» — ممنوع رقم أصمّ -->';
    return;
}
/* ⚠️ **وضعان للوصول للبنود، والاتنين بيحققوا «ممنوع رقم أصمّ»:**
     • `drill` → نافذة تفاصيل سريعة (الافتراضي، وأرخص).
     • `url`   → **شاشة كاملة مفلترة** — وده **أقوى** لما البنود ليها شاشة
       فيها فلاتر وتصدير وترتيب (زي `permits?view=records&st=open`). النافذة
       هنا كانت هتبقى نسخة أفقر من شاشة موجودة أصلاً.
   `drill` بتكسب لو الاتنين اتبعتوا. الشكل واحد في الحالتين — الفرق في الوسم
   بس (`<button>` مقابل `<a>`) عشان السلوك يبقى صح للكيبورد وفتح تبويب جديد. */
$asLink = ($drill === '');
$tag    = $asLink ? 'a' : 'button';
$open   = $asLink
    ? '<a class="d-kpi ' . e($tone) . '" href="' . e($url) . '">'
    : '<button type="button" class="d-kpi ' . e($tone) . '"'
      . ' data-drill="' . e($drill) . '" data-drill-title="' . e((string) $title) . '">';
?>
<?= $open ?>
  <span class="d-kpi-ic"><?= icon($icon, 20) ?></span>
  <div class="d-kpi-val">
    <?= e($value) ?>
    <?php if ($unit): ?><span class="d-kpi-suf"><?= e($unit) ?></span><?php endif; ?>
  </div>
  <?php if ($delta !== null):
      /* الاتجاه «كويس» بيعتمد على طبيعة الرقم: زيادة النولون كويسة، وزيادة
         المصروفات وحشة — فالمكوّن بياخد `delta_good` بدل ما يفترض. */
      $rising = $delta > 0;
      $isGood = $rising === ($deltaGood === 'up');
      $cls = abs($delta) < 0.05 ? 'flat' : ($isGood ? 'up' : 'down');
      $arrow = abs($delta) < 0.05 ? '=' : ($rising ? '▲' : '▼');
  ?>
    <div class="d-kpi-delta <?= e($cls) ?>">
      <span aria-hidden="true"><?= $arrow ?></span>
      <b><?= e(number_format(abs($delta), 1)) ?>%</b>
      <?php if ($deltaLabel !== null && $deltaLabel !== ''): ?><em><?= e((string) $deltaLabel) ?></em><?php endif; ?>
    </div>
  <?php elseif ($trend !== null):
      $tdir  = in_array($trend['dir'] ?? 'flat', ['up','down','flat'], true) ? $trend['dir'] : 'flat';
      $ttone = in_array($trend['tone'] ?? 'mute', ['good','bad','mute'], true) ? $trend['tone'] : 'mute';
      $arrow = $tdir === 'up' ? '▲' : ($tdir === 'down' ? '▼' : '=');
  ?>
    <div class="d-kpi-delta t-<?= e($ttone) ?>">
      <span aria-hidden="true"><?= $arrow ?></span>
      <b><?= e((string) $trend['text']) ?></b>
    </div>
  <?php endif; ?>
  <?php /* العدسة = «بيفتح نافذة تفاصيل» · السهم = «بينقلك لشاشة كاملة».
           الرمزان مختلفان عن قصد عشان المستخدم يعرف هيحصل إيه قبل ما يضغط. */ ?>
  <div class="d-kpi-lbl"><?= e((string) $label) ?> <span class="d-kpi-eye"><?= icon($asLink ? 'chevron' : 'search', 12) ?></span></div>
  <?php if (count($spark) >= 2):
      /* رسم خطّي صغير — SVG مرسوم على الخادم بلا أي مكتبة (Chart.js اتشالت
         في المرحلة ٤ عشان ٢٠٠KB على كل فتحة). الرسم **زخرفي**: الرقم والمقارنة
         فوقه بيقولوا كل المعلومة، فهو `aria-hidden` ومش بيحمل بيانات جديدة. */
      $n = count($spark);
      $min = min($spark); $max = max($spark);
      $range = ($max - $min) > 0.0001 ? ($max - $min) : 1.0;
      $pts = [];
      foreach ($spark as $i => $v) {
          $x = $n > 1 ? ($i / ($n - 1)) * 100 : 0;
          // ⚠️ محور Y مقلوب في SVG: القيمة الأكبر لازم تطلع فوق (y أصغر)
          $y = 22 - (((float) $v - $min) / $range) * 20;
          $pts[] = round($x, 2) . ',' . round($y, 2);
      }
      $line = implode(' ', $pts);
  ?>
    <svg class="d-kpi-spark" viewBox="0 0 100 24" preserveAspectRatio="none" aria-hidden="true" focusable="false">
      <polyline points="<?= e($line) ?>" fill="none" stroke="currentColor" stroke-width="1.6"
                stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>
      <circle cx="100" cy="<?= e((string) round(22 - (((float) end($spark) - $min) / $range) * 20, 2)) ?>" r="1.8" fill="currentColor"/>
    </svg>
  <?php endif; ?>
</<?= $tag ?>>
