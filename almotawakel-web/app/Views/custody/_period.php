<?php
/**
 * شريط الفترة — مشترك بين «الحركات» و«عهد السائقين».
 *
 * ⚠️ **ملف واحد لا نسختين**: الفترة اللي تبويب بيقراها لازم تبقى نفسها اللي
 *    التاني بيقراها، ونسختان معناهما «الشهر» يعني حاجة هنا وحاجة تانية هناك.
 *
 * ⚠️ الأزرار **روابط حقيقية** بتحافظ على التبويب الحالي — فالرجوع بالمتصفح
 *    والمشاركة شغّالين.
 *
 * متغيّرات: $from $to $tab
 */
$today = work_date();
$pm    = date('Y-m-d', strtotime(date('Y-m-01', strtotime($today)) . ' -1 day'));
$presets = [
    'اليوم'          => [$today, $today],
    'آخر ٧ أيام'     => [date('Y-m-d', strtotime($today . ' -6 day')), $today],
    'الشهر الحالي'   => [date('Y-m-01', strtotime($today)), $today],
    'الشهر اللي فات' => [date('Y-m-01', strtotime($pm)), date('Y-m-t', strtotime($pm))],
];
?>
<form class="cs-filters" method="get" action="<?= url('custody') ?>">
  <input type="hidden" name="tab" value="<?= e($tab) ?>">
  <?php foreach ($presets as $lbl => [$a, $b]):
    $on = $from === $a && $to === $b; ?>
    <a class="cs-period-btn<?= $on ? ' on' : '' ?>"
       href="<?= url('custody') ?>?tab=<?= e($tab) ?>&from=<?= e($a) ?>&to=<?= e($b) ?>"><?= e($lbl) ?></a>
  <?php endforeach; ?>
  <input type="date" name="from" value="<?= e($from) ?>" aria-label="من">
  <input type="date" name="to" value="<?= e($to) ?>" aria-label="إلى">
  <button class="btn" type="submit"><?= icon('filter', 15) ?> فلترة</button>
  <a class="btn ghost" target="_blank" rel="noopener"
     href="<?= url('custody/statement') ?>?from=<?= e($from) ?>&to=<?= e($to) ?>">
    <?= icon('file-text', 15) ?> اطبع
  </a>
</form>
