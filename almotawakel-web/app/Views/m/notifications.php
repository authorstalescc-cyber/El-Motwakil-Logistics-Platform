<?php
/**
 * إشعارات المندوب (المرحلة ٦ من docs/15) — **عرض فقط**.
 *
 * ⚠️ **نفس مصدر الحقيقة بتاع المكتب بالحرف** (`Notify::feed`) — مفيش مُصيِّر
 *    تاني ينفع يقول حاجة تانية.
 * ⚠️ **قاعدة v35 سارية:** مفيش أي مبلغ بيخصّ العميل هنا؛ اللي بيوصل المندوب
 *    قرار بنده هو ونصّه فيه **مبلغه اللي دخّله بنفسه**.
 * ⚠️ الكلاسات ببادئة `nt-` (والمشترك `hm-`) عشان ما تلمسش الشاشات التانية.
 *
 * @var array $items @var int $unread @var int $snoozed
 */
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0F151E">
<title>الإشعارات — المتوكل</title>
<link rel="icon" href="<?= url('assets/img/logo.png') ?>">
<link rel="stylesheet" href="<?= url('assets/css/m.css') ?>?v=<?= @filemtime(\App\Core\App::basePath('public/assets/css/m.css')) ?>">
<?php require __DIR__ . "/_pwa.php"; ?>
</head>
<body class="hm-body">

<div class="hm-top">
  <header class="hm-bar">
    <?php $backTo = 'm'; require __DIR__ . '/_back.php'; ?>
    <span class="hf-title">الإشعارات</span>
    <a class="nt-gear" href="<?= url('m/notifications/prefs') ?>" aria-label="إعدادات الإشعارات"><?= icon('settings', 14) ?></a>
  </header>
</div>

<main class="hm-sheet">
  <?php foreach (flash_all() as $f): ?>
    <div class="flash <?= $f['type'] === 'error' ? 'err' : 'ok' ?>"><?= e($f['msg']) ?></div>
  <?php endforeach; ?>

  <?php if ($unread > 0): ?>
    <form method="post" action="<?= e(url('notifications/read-all')) ?>" class="nt-allform">
      <?= csrfField() ?>
      <button class="nt-all" type="submit"><?= intf($unread) ?> جديد — علّمهم كلهم مقروء</button>
    </form>
  <?php endif; ?>

  <?php if ($snoozed > 0): ?>
    <p class="nt-snoozed">⏰ فيه <?= intf($snoozed) ?> إشعار مؤجَّل هيرجع يظهر لوحده.</p>
  <?php endif; ?>

  <?php if (!$items): ?>
    <div class="nt-empty">
      <b>مفيش إشعارات لسه</b>
      <span>أول ما المكتب ياخد قرار في بند من بنودك، هتلاقيه هنا بوقته.</span>
    </div>
  <?php else: ?>
    <ul class="nt-list">
      <?php
      $lastDay = null;
      foreach ($items as $it):
          $d = date('Y-m-d', (int) $it['ts']);
          $day = $d === date('Y-m-d') ? 'النهاردة'
               : ($d === date('Y-m-d', time() - 86400) ? 'إمبارح' : dfmt($d));
          if ($day !== $lastDay): $lastDay = $day; ?>
            <li class="nt-day"><?= e($day) ?></li>
          <?php endif; ?>
        <li class="nt-item<?= $it['read'] ? '' : ' unread' ?>">
          <?php if ($it['url'] !== ''): ?><a class="nt-link" href="<?= e($it['url']) ?>"><?php endif; ?>
            <b><?= e($it['title']) ?></b>
            <span class="nt-body"><?= e($it['body']) ?></span>
            <span class="nt-when" title="<?= e($it['at_label']) ?>"><?= e($it['rel']) ?></span>
          <?php if ($it['url'] !== ''): ?></a><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</main>
</body>
</html>
