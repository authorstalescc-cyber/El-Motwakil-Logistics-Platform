<?php
/**
 * شريط يوم العمل — المكوّن الموحّد.
 *
 * كل بيانات النظام بتحترم `Auth::workDate()`، لكن الشاشات كانت بتقول ده
 * بطرق مختلفة (أو ما بتقولوش خالص) — فالمالك بيبصّ على أرقام يوم قديم
 * وهو فاكرها بتاعة النهاردة. المكوّن ده بيوحّد الرسالة وطريق التغيير.
 *
 * المتغيّرات:
 *   day     string   تاريخ العمل (Y-m-d)
 * ⚠️ **مفيش `note` — اتشال (قانون التصميم، بند ١).** التحذيرات الوظيفية
 *    (مُقفَل · مش تاريخ النهاردة) بيطبعها المكوّن بنفسه كشارات.
 *   locked  ?bool    اليوم متقفل (تقفيل الخزنة v18) → الشريط بلون تنبيه
 *   prefix  ?string  كلمة قبل التاريخ (الافتراضي «يوم»)
 *   change  ?bool    عرض زر «غيّر يوم العمل» (الافتراضي نعم)
 *   facts   ?array   حقائق اليوم جنب التاريخ: كل عنصر ['label'=>, 'value'=>, 'tone'=>?]
 *   actions string|callable  أزرار إضافية قبل «غيّر يوم العمل» (الإدخال السريع مثلاً)
 */
$day    = (string) ($day ?? work_date());
$locked = !empty($locked);
$prefix = $prefix ?? 'يوم';
$change = !isset($change) || $change;
$facts  = $facts ?? [];
$actions = $actions ?? '';
$isToday = $day === date('Y-m-d');
?>
<?php /* ⚠️ الشريط ده بيخفي شريط تاريخ العمل بتاع التخطيط (`.content:has(.day-bar) .workbar`)
         عشان التاريخ ما يتعرضش مرتين — فلازم ياخد تحذير «مش النهاردة» بنفسه،
         وإلا التحذير يضيع في الشاشات اللي بترسمه. */ ?>
<div class="day-bar<?= $locked ? ' locked' : ($isToday ? '' : ' warn') ?>">
  <span class="day-bar-ic" aria-hidden="true"><?= icon($locked ? 'alert' : 'calendar', 20) ?></span>
  <div class="day-bar-txt">
    <strong><?= e($prefix) ?> <?= e(dfmt($day)) ?><?= $isToday ? ' (النهاردة)' : ' — مش تاريخ النهاردة، خلّي بالك!' ?></strong>
  </div>
  <?php if ($facts): ?>
    <div class="day-bar-facts">
      <?php foreach ($facts as $f): ?>
        <span class="day-fact">
          <em><?= e((string) ($f['label'] ?? '')) ?></em>
          <b class="<?= e((string) ($f['tone'] ?? '')) ?>"><?= e((string) ($f['value'] ?? '')) ?></b>
        </span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($locked): ?>
    <span class="pill st-partial" title="اليوم اتقفل في المكتب — الكتابة عليه مرفوضة">مُقفَل</span>
  <?php endif; ?>
  <?php partial_body($actions); ?>
  <?php if ($change): ?>
    <a class="btn ghost" href="<?= url('work-date') ?>">غيّر يوم العمل</a>
  <?php endif; ?>
</div>
