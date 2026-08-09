<?php
/**
 * خزنة الشركة — الغلاف (v68).
 *
 * ═══ التنظيم (بلاغ المالك: «الشكل مش عاجبني — عايزها احترافية وعصرية») ═══
 * كانت **صفحة واحدة طويلة** فيها ٧ أقسام تحت بعض ونموذج إدخال في نُصّها. بقت:
 *   ترويسة + إجراء أساسي واحد → تبويبات → جسم التبويب → درج الإدخال.
 *
 * ⚠️ **التبويبات روابط حقيقية** (`?tab=`) لا تبديل بالجافاسكريبت — الرجوع
 *    بالمتصفح والمشاركة والفتح في تبويب جديد كلها شغّالة (قاعدة `tabs.php`).
 * ⚠️ **ممنوع رقم أصمّ**: كل رقم بيفتح بنوده.
 * ⚠️ **صفر جملة تعليمية** (قانون التصميم بند ١) — اللي فاضل تحذيرات وظيفية
 *    في ≤٦ كلمات بس.
 * ⚠️ **صفر `<style>` محلي** — كل الكلاسات `.cs-*` في `app.css` عشان النافذة
 *    والدرج يستخدموا نفس التعريفات.
 */
use App\Core\FormState;
use App\Models\CompanySafe;

$st      = $entryState ?? ['token' => FormState::token(), 'old' => [], 'errors' => [], 'back' => 'company-safe'];
$old     = $st['old'];
$errors  = $st['errors'];
$retPath = current_app_path() ?: 'company-safe';
$cd      = static fn (string $what): string => url('company-safe/drill/' . $what);
$tabUrl  = static fn (string $t): string => url('company-safe') . '?tab=' . $t;
$labels  = CompanySafe::labels();
$editing = !empty($editRow);

/* في وضع التعديل الخانات بتتعبّى من الصف المحفوظ، ولو الحفظ فشل من `old`
   (اللي بترجع من `FormState`) — فالمالك ما يفقدش اللي كتبه. */
$val = static function (string $k, $fallback = '') use ($old, $editRow) {
    if (array_key_exists($k, $old)) { return $old[$k]; }
    return $editRow[$k] ?? $fallback;
};
?>
<?php if (!$ready): ?>
  <div class="card mb p5-pad-sm p5-edge-gold-r">
    <?= icon('info', 15) ?> خزنة الشركة مش مفعّلة — شغّل <code>php bin/migrate_v66.php</code>.
  </div>
<?php else: ?>

<?php /* ═══ الترويسة — الإجراء الأساسي واحد وفي مكانه الثابت ═══ */ ?>
<?php partial('page_head', [
  'title'   => 'خزنة الشركة',
  'primary' => ['label' => '+ حركة جديدة',
                'url'   => url('company-safe') . '?tab=' . $tab . '&new=1',
                'class' => 'btn gold'],
  'actions' => function () { ?>
    <?php /* ⚠️ الكشف بيسأل عن الفترة **قبل** ما يفتح (طلب المالك) — قبل كده كان
             بيطبع الشهر الحالي بلا سؤال، فالمالك اللي عايز يوم واحد كان يطبع
             ورقة غلط ويرجع. النافذة هي نفس مكوّن التفاصيل العام. */ ?>
    <button type="button" class="btn ghost" data-drill="#csStmtPick"
            data-drill-title="اطبع كشف حساب">
      <?= icon('file-text', 15) ?> كشف حساب
    </button>
  <?php },
]); ?>

<?php /* نافذة اختيار فترة الكشف — محتوى في الصفحة (`#csStmtPick`) فمفيش رحلة
         سيرفر، والفورم `GET` عادي فشغّال بلا جافاسكريبت لو اتفتح بالرابط. */ ?>
<div id="csStmtPick" hidden>
  <form class="cs-pick" method="get" action="<?= url('company-safe/statement') ?>" target="_blank">
    <div class="cs-pick-quick">
      <?php
      $today = work_date();
      $pm    = date('Y-m-d', strtotime(date('Y-m-01', strtotime($today)) . ' -1 day'));
      $presets = [
        'اليوم'          => [$today, $today],
        'الشهر الحالي'   => [date('Y-m-01', strtotime($today)), date('Y-m-t', strtotime($today))],
        'الشهر اللي فات' => [date('Y-m-01', strtotime($pm)),    date('Y-m-t', strtotime($pm))],
        'السنة'          => [date('Y-01-01', strtotime($today)), date('Y-12-31', strtotime($today))],
      ];
      foreach ($presets as $lbl => [$a, $b]): ?>
        <a class="cs-pick-btn" target="_blank" rel="noopener"
           href="<?= url('company-safe/statement') ?>?from=<?= e($a) ?>&to=<?= e($b) ?>"><?= e($lbl) ?></a>
      <?php endforeach; ?>
    </div>
    <div class="cs-pick-range">
      <label>من <input type="date" name="from" value="<?= e(date('Y-m-01', strtotime($today))) ?>"></label>
      <label>إلى <input type="date" name="to" value="<?= e($today) ?>"></label>
      <button class="btn gold" type="submit"><?= icon('file-text', 15) ?> اطبع</button>
    </div>
  </form>
</div>

<?php partial('tabs', [
  'label' => 'أقسام خزنة الشركة',
  'items' => [
    ['label' => 'نظرة عامة', 'icon' => 'dashboard',     'url' => $tabUrl('overview'), 'active' => $tab === 'overview'],
    ['label' => 'الحركات',   'icon' => 'layers',        'url' => $tabUrl('moves'),    'active' => $tab === 'moves'],
    ['label' => 'المصروفات', 'icon' => 'trending-down', 'url' => $tabUrl('expenses'), 'active' => $tab === 'expenses'],
    ['label' => 'التقارير',  'icon' => 'bar-chart',     'url' => $tabUrl('reports'),  'active' => $tab === 'reports'],
  ],
]); ?>

<?php
/* ⚠️ جسم التبويب في ملف مستقل — الغلاف ده كان هيبقى ٦٠٠ سطر لو الأربعة جوّه،
   وكل تبويب بياخد متغيّراته هو بس من الكنترولر (`match`). */
include __DIR__ . '/_tab_' . $tab . '.php';
?>

<?php include __DIR__ . '/_drawer.php'; ?>

<?php endif; ?>
