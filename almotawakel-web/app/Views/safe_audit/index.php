<?php
/**
 * جرد الخزينة (v108) — الغلاف.
 *
 * ترويسة + إجراء أساسي واحد → تبويبات → جسم التبويب.
 *
 * ⚠️ **التبويبات روابط حقيقية** (`?tab=`) لا تبديل بالجافاسكريبت — الرجوع
 *    بالمتصفح والمشاركة والفتح في تبويب جديد كلها شغّالة (قاعدة `tabs.php`).
 * ⚠️ **ممنوع رقم أصمّ** — كل رقم بيفتح بنوده (مكوّن `stat` بيرفض يترسم بلا drill).
 * ⚠️ **صفر `<style>` محلي وصفر `style=` جديد** — الشاشة كلها مبنية من مكوّنات
 *    `app/Views/partials`، فمفيش كلاس جديد محتاج CSS ومفيش شكل بيتفرّع عن النظام.
 */
$tabUrl = static fn (string $t): string => url('safe-audit') . '?tab=' . $t;
?>
<?php if (!$ready): ?>
  <div class="card mb p5-pad-sm p5-edge-gold-r">
    <?= icon('info', 15) ?> جرد الخزينة مش مفعّل — شغّل <code>php bin/migrate_v108.php</code>.
  </div>
<?php else: ?>

<?php partial('page_head', [
  'title'   => 'جرد الخزينة',
  'actions' => function () { ?>
    <?php /* الكشف بيسأل عن الفترة **قبل** ما يفتح — نفس نمط كشف خزنة الشركة. */ ?>
    <button type="button" class="btn ghost" data-drill="#saStmtPick"
            data-drill-title="اطبع كشف الجرد">
      <?= icon('file-text', 15) ?> كشف للطباعة
    </button>
  <?php },
]); ?>

<?php /* نافذة اختيار الفترة — محتوى في الصفحة فمفيش رحلة سيرفر، والفورم `GET`
         عادي فشغّال بلا جافاسكريبت لو اتفتح بالرابط. */ ?>
<div id="saStmtPick" hidden>
  <form class="cs-pick" method="get" action="<?= url('safe-audit/statement') ?>" target="_blank">
    <div class="cs-pick-quick">
      <?php
      $today = work_date();
      $pm    = date('Y-m-d', strtotime(date('Y-m-01', strtotime($today)) . ' -1 day'));
      $presets = [
        'اليوم'          => [$today, $today],
        'الشهر الحالي'   => [date('Y-m-01', strtotime($today)), date('Y-m-t', strtotime($today))],
        'الشهر اللي فات' => [date('Y-m-01', strtotime($pm)),    date('Y-m-t', strtotime($pm))],
      ];
      foreach ($presets as $lbl => [$a, $b]): ?>
        <a class="cs-pick-btn" target="_blank" rel="noopener"
           href="<?= url('safe-audit/statement') ?>?period=custom&from=<?= e($a) ?>&to=<?= e($b) ?>"><?= e($lbl) ?></a>
      <?php endforeach; ?>
    </div>
    <div class="cs-pick-range">
      <input type="hidden" name="period" value="custom">
      <label>من <input type="date" name="from" value="<?= e(date('Y-m-01', strtotime($today))) ?>"></label>
      <label>إلى <input type="date" name="to" value="<?= e($today) ?>"></label>
      <button class="btn gold" type="submit"><?= icon('file-text', 15) ?> اطبع</button>
    </div>
  </form>
</div>

<?php partial('tabs', [
  'label' => 'أقسام جرد الخزينة',
  'items' => [
    ['label' => 'الجرد',   'icon' => 'calculator', 'url' => $tabUrl('count'),   'active' => $tab === 'count'],
    ['label' => 'الكشف',   'icon' => 'layers',     'url' => $tabUrl('moves'),   'active' => $tab === 'moves'],
    ['label' => 'السجل',   'icon' => 'clipboard',  'url' => $tabUrl('history'), 'active' => $tab === 'history'],
  ],
]); ?>

<?php
/* ⚠️ جسم التبويب في ملف مستقل — الغلاف كان هيبقى ٥٠٠ سطر لو التلاتة جوّه،
   وكل تبويب بياخد متغيّراته هو بس من الكنترولر (`match`). */
include __DIR__ . '/_tab_' . $tab . '.php';
?>

<?php endif; ?>
