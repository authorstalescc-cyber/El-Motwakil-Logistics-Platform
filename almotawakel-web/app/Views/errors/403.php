<?php
/**
 * «مالكش صلاحية» — صفحة جديدة (المرحلة ٢).
 *
 * قبل كده كان `Access::deny()` بيعمل `flash('error') + redirect('')`، يعني
 * المستخدم بيتقذف على الداشبورد ويفقد اللي كان بيعمله **من غير ما يعرف
 * أنهي صفحة اترفضت ولا مين يقدر يفتحها**. الصفحة دي بتقول التلاتة.
 */
use App\Core\Access;
use App\Core\Auth;

$backUrl = safe_back('', referer_path());
$here = rawurldecode((string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? ''));
/** مين يقدر يعمل الإجراء ده؟ نجرّب كل دور على نفس السياسة المركزية. */
$allowed = [];
if (($handler = $deniedHandler ?? '') !== '') {
    foreach (array_keys(Access::ROLES) as $role) {
        if (Access::allows($role, $handler, $deniedMethod ?? 'POST')) { $allowed[] = Access::label($role); }
    }
}
?>
<div class="err-page">
  <div class="err-code lock"><?= icon('alert', 46) ?></div>
  <h1 class="err-title">مالكش صلاحية على الإجراء ده</h1>
  <p class="err-sub">
    دورك الحالي <b><?= e(Auth::roleLabel()) ?></b> مش مسموح له بالعملية دي.
    <?php if ($here !== ''): ?><br><code class="err-path"><?= e($here) ?></code><?php endif; ?>
  </p>
  <?php if ($allowed): ?>
    <p class="err-hint"><?= icon('users', 15) ?> الأدوار اللي تقدر تعملها: <b><?= e(implode('، ', $allowed)) ?></b> — اطلب من المالك يظبّط صلاحيتك.</p>
  <?php endif; ?>
  <div class="err-actions">
    <?php if ($backUrl !== ''): ?>
      <a class="btn" href="<?= url($backUrl) ?>"><?= icon('undo', 16) ?> ارجع للي كنت فيه</a>
    <?php endif; ?>
    <a class="btn ghost" href="<?= url('') ?>"><?= icon('dashboard', 16) ?> لوحة التحكم</a>
  </div>
</div>
