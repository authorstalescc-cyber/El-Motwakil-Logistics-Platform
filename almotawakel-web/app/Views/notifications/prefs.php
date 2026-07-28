<?php
/**
 * إعدادات الإشعارات — نوع × قناة (المرحلة ٤ من docs/15).
 *
 * ⚠️ **الغياب = مفعّل.** كل الخانات بتبدأ معلَّمة، والجدول في القاعدة بيفضل
 *    فاضي لحد ما تقفل حاجة. يعني «مفيش صف» معناه «الكل شغّال».
 *
 * ⚠️ **الجرس هو الأساس والموبايل قناة فوقه.** لو قفلت الجرس، الموبايل بيتقفل
 *    معاه (`NotifyPref::save` بتفرضها على الخادم كمان) — البند اللي بيرنّ
 *    وما تلاقيهوش في النظام أسوأ من إنه ما يرنّش.
 *
 * ⛔ **مفيش «ساعات هدوء»** — اتشالت بقرار المالك (docs/15 §٧.٤).
 */
?>
<?php partial('page_head', [
    'title' => 'إعدادات الإشعارات',
    'back'  => ['label' => 'الإشعارات', 'url' => url('notifications')],
]); ?>

<?php if (!$ready): ?>
  <?php partial('empty', [
      'icon'  => 'bell',
      'title' => 'التفضيلات لسه ما اتفعّلتش',
  ]); ?>
<?php else: ?>

<form method="post" action="<?= e(url('notifications/prefs')) ?>" class="np-form">
  <?= csrfField() ?>

  <?php
  ob_start(); ?>
    <div class="np-head">
      <span class="np-h-kind">نوع الإشعار</span>
      <span class="np-h-ch"><?= icon('bell', 15) ?> الجرس</span>
      <span class="np-h-ch"><?= icon('send', 15) ?> الموبايل</span>
    </div>
    <?php foreach ($rows as $r): ?>
      <label class="np-row" for="ia-<?= e($r['kind']) ?>">
        <span class="np-kind">
          <span class="np-ic"><?= icon($r['icon'], 17) ?></span>
          <b><?= e($r['label']) ?></b>
        </span>
        <span class="np-ch">
          <input type="checkbox" id="ia-<?= e($r['kind']) ?>" class="np-bell"
                 name="in_app[<?= e($r['kind']) ?>]" value="1"
                 data-kind="<?= e($r['kind']) ?>"<?= $r['in_app'] ? ' checked' : '' ?>>
        </span>
        <span class="np-ch">
          <input type="checkbox" class="np-push"
                 name="push[<?= e($r['kind']) ?>]" value="1"
                 data-kind="<?= e($r['kind']) ?>"<?= $r['push'] ? ' checked' : '' ?>
                 <?= $r['in_app'] ? '' : ' disabled' ?>>
        </span>
      </label>
    <?php endforeach; ?>
  <?php $body = ob_get_clean();

  partial('panel', [
      'title' => 'نوع × قناة',
      'icon'  => 'bell',
      'body'  => $body,
  ]);
  ?>

  <div class="np-actions">
    <button class="btn gold" type="submit">احفظ</button>
    <button class="btn ghost" type="submit" name="_reset" value="1"
            onclick="return confirm('ترجّع كل الإشعارات للوضع الافتراضي؟ هتوصلك كلها تاني.')">
      رجّع الافتراضي
    </button>
  </div>
</form>

<div class="np-note">
  <?= icon('info', 15) ?>
  <span>
    الإشعار اللي بتقفله ما بيتسجّلش عندك من ساعة ما تقفله — بس اللي اتسجّل قبل كده
    بيفضل في سجلك زي ما هو. وقفل الجرس بيقفل الموبايل معاه.
  </span>
</div>

<script>
/* الموبايل بلا جرس مالوش معنى: البند هيرنّ ومش هتلاقيه في النظام.
   الخادم بيفرض القاعدة دي كمان (`NotifyPref::save`) — ده تحسين عرض بس. */
document.querySelectorAll('.np-bell').forEach(function (bell) {
  bell.addEventListener('change', function () {
    var row = bell.closest('.np-row');
    var push = row && row.querySelector('.np-push');
    if (!push) return;
    push.disabled = !bell.checked;
    if (!bell.checked) push.checked = false;
  });
});
</script>
<?php endif; ?>
