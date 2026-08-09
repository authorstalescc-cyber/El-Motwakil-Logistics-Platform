<?php
/**
 * إعدادات إشعارات المندوب (المرحلة ٤) — نسخة مبسّطة من شاشة المكتب.
 *
 * ⚠️ **المندوب بيشوف أنواعه هو بس** — `NotificationController::kindsFor('rep')`
 *    بتقرا `Notify::ROUTES` نفسها، فمش هيلاقي قدامه «تقفيل يوم» ولا «تحصيل عميل».
 * ⚠️ **الغياب = مفعّل** — كل الخانات بتبدأ معلَّمة والجدول بيفضل فاضي.
 * ⚠️ الجرس هو الأساس: لو قفله، الموبايل بيتقفل معاه (الخادم بيفرضها كمان).
 *
 * @var array $rows @var bool $ready
 */
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0F151E">
<title>إعدادات الإشعارات — المتوكل</title>
<link rel="icon" href="<?= url('assets/img/logo.png') ?>">
<link rel="stylesheet" href="<?= url('assets/css/m.css') ?>?v=<?= @filemtime(\App\Core\App::basePath('public/assets/css/m.css')) ?>">
<?php require __DIR__ . "/_pwa.php"; ?>
</head>
<body class="hm-body">

<div class="hm-top">
  <header class="hm-bar">
    <?php $backTo = 'm/notifications'; require __DIR__ . '/_back.php'; ?>
    <span class="hf-title">إعدادات الإشعارات</span>
  </header>
</div>

<main class="hm-sheet">
  <?php foreach (flash_all() as $f): ?>
    <div class="flash <?= $f['type'] === 'error' ? 'err' : 'ok' ?>"><?= e($f['msg']) ?></div>
  <?php endforeach; ?>

  <?php if (!$ready): ?>
    <div class="nt-empty"><b>لسه مش متاح</b><span>كلّم المكتب.</span></div>
  <?php else: ?>
    <p class="nt-hint">اختار كل نوع يوصلك إزاي. كله شغّال افتراضيًّا.</p>

    <form method="post" action="<?= e(url('m/notifications/prefs')) ?>">
      <?= csrfField() ?>
      <ul class="ntp-list">
        <li class="ntp-head"><span>النوع</span><span><?= icon('bell', 14) ?></span><span><?= icon('smartphone', 14) ?></span></li>
        <?php foreach ($rows as $r): ?>
          <li class="ntp-row">
            <label class="ntp-lbl" for="ia-<?= e($r['kind']) ?>"><?= e($r['label']) ?></label>
            <span class="ntp-ch">
              <input type="checkbox" id="ia-<?= e($r['kind']) ?>" class="ntp-bell"
                     name="in_app[<?= e($r['kind']) ?>]" value="1"<?= $r['in_app'] ? ' checked' : '' ?>>
            </span>
            <span class="ntp-ch">
              <input type="checkbox" class="ntp-push"
                     name="push[<?= e($r['kind']) ?>]" value="1"<?= $r['push'] ? ' checked' : '' ?>
                     <?= $r['in_app'] ? '' : ' disabled' ?>>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>

      <button class="btn gold" type="submit">احفظ</button>
      <button class="btn ghost" type="submit" name="_reset" value="1">رجّع الافتراضي</button>
    </form>

    <script>
    /* الموبايل بلا جرس مالوش معنى — والخادم بيفرضها كمان (`NotifyPref::save`). */
    document.querySelectorAll('.ntp-bell').forEach(function (b) {
      b.addEventListener('change', function () {
        var p = b.closest('.ntp-row').querySelector('.ntp-push');
        if (!p) return;
        p.disabled = !b.checked;
        if (!b.checked) p.checked = false;
      });
    });
    </script>
  <?php endif; ?>
</main>
</body>
</html>
