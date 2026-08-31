<?php
/**
 * خزنة المكتب — الغلاف (docs/21 المرحلة ٣).
 *
 * ⚠️ **التبويبات روابط حقيقية** (`?tab=`) لا تبديل بالجافاسكريبت.
 * ⚠️ **ممنوع رقم أصمّ** — كل رقم بيفتح بنوده عبر `/custody/drill/{what}`.
 * ⚠️ **صفر جملة تعليمية** (قانون التصميم بند ١) — اللي فاضل تحذيرات وظيفية
 *    في ≤٦ كلمات بس.
 * ⚠️ **صفر `<style>` محلي** — كل الكلاسات `.cs-*` موجودة في `app.css` أصلاً
 *    (اتبنت لخزنة الشركة في v68)، فالخزنتان بيبانوا من نظام واحد.
 *
 * متغيّرات: $tab $day $from $to $rate $wallet $breakdown $lastClosed $aggregate
 *           $repList $drawer $topupState $repDepState $move (+ $series في overview)
 */
$cd     = static fn (string $w): string => url('custody/drill/' . $w) . '?from=' . $from . '&to=' . $to;
$tabUrl = static fn (string $t): string => url('custody') . '?tab=' . $t . '&from=' . $from . '&to=' . $to;
$retPath = current_app_path() ?: 'custody';
?>
<?php partial('page_head', [
  'title'   => 'خزنة المكتب',
  'primary' => ['label' => '+ إضافة عهدة',
                'url'   => url('custody') . '?tab=' . $tab . '&from=' . $from . '&to=' . $to . '&new=topup',
                'class' => 'btn gold'],
  'actions' => function () use ($from, $to) { ?>
    <a class="btn ghost" target="_blank" rel="noopener"
       href="<?= url('custody/statement') ?>?from=<?= e($from) ?>&to=<?= e($to) ?>">
      <?= icon('file-text', 15) ?> كشف حساب
    </a>
    <a class="btn ghost" href="<?= url('closing') ?>">التقفيل اليومي ›</a>
  <?php },
]); ?>

<?php /* تحذير وظيفي — مش شرح تعليمي. */ ?>
<?php if (!$lastClosed): ?>
  <div class="card mb p5-pad-sm p5-edge-gold-r">
    <?= icon('info', 15) ?> مفيش يوم متقفل — الرصيد محسوب من كل الحركة.
    <a href="<?= url('closing') ?>">اقفل اليوم</a>
  </div>
<?php endif; ?>

<?php partial('tabs', [
  'label' => 'أقسام خزنة المكتب',
  'items' => [
    ['label' => 'نظرة عامة',    'icon' => 'dashboard', 'url' => $tabUrl('overview'), 'active' => $tab === 'overview'],
    ['label' => 'الحركات',      'icon' => 'layers',    'url' => $tabUrl('moves'),    'active' => $tab === 'moves'],
    ['label' => 'عهد السائقين', 'icon' => 'truck',     'url' => $tabUrl('drivers'),  'active' => $tab === 'drivers'],
    ['label' => 'المناديب',     'icon' => 'user',      'url' => $tabUrl('reps'),     'active' => $tab === 'reps'],
  ],
]); ?>

<?php
/* ⚠️ جسم التبويب في ملف مستقل — الغلاف كان هيبقى ٥٠٠ سطر لو الأربعة جوّه،
   وكل تبويب بياخد متغيّراته هو بس من الكنترولر (`match`). */
include __DIR__ . '/_tab_' . $tab . '.php';
?>

<?php include __DIR__ . '/_drawer.php'; ?>
