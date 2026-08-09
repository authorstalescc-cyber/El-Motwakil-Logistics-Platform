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
  'actions' => function () use ($tab) { ?>
    <a class="btn ghost" target="_blank" rel="noopener" href="<?= url('company-safe/statement') ?>">
      <?= icon('file-text', 15) ?> كشف حساب
    </a>
  <?php },
]); ?>

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
