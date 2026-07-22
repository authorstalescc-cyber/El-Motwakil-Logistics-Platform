<?php
/**
 * «غير موجود» للمستخدم المسجَّل — بتترندر **جوّه الـlayout** (المرحلة ٢).
 * قبل كده كانت صفحة سودا مستقلة بلا قائمة ولا توب-بار، يعني المستخدم كان
 * بيتقذف برّه النظام ومعاه زر واحد بس. النسخة المستقلة بقت `404_guest.php`.
 */
$backUrl = safe_back('', referer_path());
$here = rawurldecode((string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? ''));
?>
<div class="err-page">
  <div class="err-code">404</div>
  <?php /* `$notFoundMsg` بتيجي من `Controller::notFound()` وبتقول بالظبط
           إيه اللي مش موجود (إصدار / بوليصة / حركة / بند) بدل رسالة عامة. */ ?>
  <h1 class="err-title"><?= e($notFoundMsg ?? 'الصفحة دي مش موجودة') ?></h1>
  <p class="err-sub">
    يمكن الرابط اتغيّر، أو السجل اللي بتدوّر عليه اتمسح.
    <?php if ($here !== ''): ?><br><code class="err-path"><?= e($here) ?></code><?php endif; ?>
  </p>
  <div class="err-actions">
    <?php if ($backUrl !== ''): ?>
      <a class="btn" href="<?= url($backUrl) ?>"><?= icon('undo', 16) ?> ارجع للي كنت فيه</a>
    <?php endif; ?>
    <a class="btn ghost" href="<?= url('') ?>"><?= icon('dashboard', 16) ?> لوحة التحكم</a>
    <button type="button" class="btn ghost" data-open-search><?= icon('search', 16) ?> دوّر في النظام</button>
  </div>
</div>
