<?php
/**
 * حالة رحلة الشحنة على موبايل المندوب (v62).
 *
 * ⚠️ **ولا رقم مالي واحد في الشاشة دي** (قاعدة v35: ممنوع أي مبلغ بيخصّ
 *    العميل يوصل شاشة المندوب). دي حالة تشغيلية بحتة.
 *
 * ⚠️ الكلاسات في **`m.css`** لا `app.css` — بوابة `/m` ما بتحمّلش app.css
 *    (درس `.table-mobile-scroll` الموثَّق: ماركب موجود وشكل ناقص = فشل صامت).
 *
 * المتغيّرات: $permit $journey $bills
 */
use App\Models\PermitTrack;

$tl   = $journey['timeline'] ?? [];
$next = $journey['next'] ?? null;
$pct  = (int) ($journey['percent'] ?? 0);
$pid  = (int) $permit['id'];
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0F151E">
<title>حالة الرحلة — <?= e($permit['no']) ?></title>
<link rel="icon" href="<?= url('assets/img/logo.png') ?>">
<link rel="stylesheet" href="<?= url('assets/css/m.css') ?>?v=<?= @filemtime(\App\Core\App::basePath('public/assets/css/m.css')) ?>">
<?php require __DIR__ . "/_pwa.php"; ?>
</head>
<body class="hm-body hf-body">

<div class="hf-top">
  <header class="hf-bar">
    <?php $backTo = 'm/permit/' . $pid; require __DIR__ . '/_back.php'; ?>
    <span class="hf-title">حالة الرحلة</span>
  </header>

  <div class="hf-context">
    <span class="hf-kind <?= ($permit['type'] ?? 'sader') === 'warid' ? 'is-warid' : 'is-sader' ?>"><?= e($permit['type_label']) ?></span>
    <b><?= e($permit['company'] ?: $permit['client'] ?: 'بدون شركة') ?></b>
    <span class="hf-no"><?= e($permit['no']) ?></span>
  </div>
</div>

<main class="hf-sheet">
  <span class="hm-grab" aria-hidden="true"></span>

  <?php /* نفس نمط باقي شاشات البوابة (`bill_new` · `settle`) — البوابة برّه
           تخطيط المكتب فما بتحمّلش كومة التوستات. */ ?>
  <?php if ($m = flash('success')): ?><div class="flash ok"><?= e($m) ?></div><?php endif; ?>
  <?php if ($m = flash('error')): ?><div class="flash err"><?= e($m) ?></div><?php endif; ?>

  <?php /* ── الحالة الحالية ─────────────────────────────────────────── */ ?>
  <section class="mt-now<?= !empty($journey['finished']) ? ' is-done' : '' ?>">
    <span class="mt-now-ic"><?= icon($journey['icon'] ?? 'clock', 22) ?></span>
    <div class="mt-now-tx">
      <span>الشحنة دلوقتي</span>
      <b><?= e($journey['label'] ?? 'لسه ما بدأتش') ?></b>
      <?php if (!empty($journey['last_at'])): ?>
        <em><?= e(PermitTrack::ago($journey['last_at'])) ?></em>
      <?php endif; ?>
    </div>
    <span class="mt-pct"><?= $pct ?>%</span>
  </section>

  <?php /* ── الزرار الكبير: المرحلة الجاية ──────────────────────────── */ ?>
  <?php if ($next !== null): ?>
    <form method="post" action="<?= url('m/permit/' . $pid . '/track') ?>" class="mt-next-f">
      <?= csrfField() ?>
      <input type="hidden" name="stage_key" value="<?= e($next['key']) ?>">
      <input type="hidden" name="bill_id" value="0">
      <button class="mt-next" type="submit">
        <span class="mt-next-ic"><?= icon($next['icon'], 20) ?></span>
        <span class="mt-next-tx"><small>الخطوة الجاية — دوس عشان تسجّلها</small><b><?= e($next['label']) ?></b></span>
        <span class="mt-next-go"><?= icon('check', 20) ?></span>
      </button>
    </form>
  <?php else: ?>
    <div class="mt-fin"><?= icon('check-circle', 18) ?> الرحلة خلصت — مفيش خطوات فاضلة</div>
  <?php endif; ?>

  <?php /* ── التايم لاين ─────────────────────────────────────────────── */ ?>
  <ol class="mt-track">
    <?php foreach ($tl as $s): $ev = $s['event']; ?>
      <li class="mt-step is-<?= $s['state'] ?>">
        <span class="mt-dot"><?= icon($s['icon'], 14) ?></span>
        <div class="mt-b">
          <b><?= e($s['label']) ?></b>
          <?php if ($ev !== null): ?>
            <span><?= dfmt($ev['work_date']) ?: dtfmt($ev['happened_at'], 'd/m/Y') ?> · <?= dtfmt($ev['happened_at'], 'H:i') ?>
              <?php if (!empty($ev['actor_name'])): ?>· <?= e($ev['actor_name']) ?><?php endif; ?></span>
            <?php if (!empty($ev['note'])): ?><em><?= e($ev['note']) ?></em><?php endif; ?>
          <?php elseif ($s['state'] === 'skipped'): ?>
            <span class="mt-skip">اتخطّت</span>
          <?php else: ?>
            <span class="mt-wait"><?= e($s['hint']) ?></span>
          <?php endif; ?>
        </div>
        <?php if ($ev === null): ?>
          <form method="post" action="<?= url('m/permit/' . $pid . '/track') ?>" class="mt-f">
            <?= csrfField() ?>
            <input type="hidden" name="stage_key" value="<?= e($s['key']) ?>">
            <input type="hidden" name="bill_id" value="0">
            <button class="mt-mark" type="submit" aria-label="سجّل <?= e($s['label']) ?>"><?= icon('check', 15) ?></button>
          </form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>

  <?php /* ── العربية اللي انت معاها ─────────────────────────────────── */ ?>
  <?php if ($bills): ?>
    <section class="mt-bills">
      <h2><?= icon('truck', 15) ?> حدّث عربية لوحدها</h2>
      <p class="mt-bills-h">لو عربية واحدة بس اللي وصلت، سجّل عليها هي بدل الإذن كله.</p>
      <?php foreach ($bills as $b): $bi = $b['bill']; ?>
        <article class="mt-bill">
          <div class="mt-bill-h">
            <b><?= e($bi['bill_number'] ?: '#' . $bi['id']) ?></b>
            <?php if (!empty($bi['car_no'])): ?><span><bdi><?= e($bi['car_no']) ?></bdi></span><?php endif; ?>
            <?php if (!empty($bi['driver'])): ?><span><?= e($bi['driver']) ?></span><?php endif; ?>
            <i><?= e($b['label']) ?></i>
          </div>
          <?php if (($b['next'] ?? null) !== null): ?>
            <form method="post" action="<?= url('m/permit/' . $pid . '/track') ?>" class="mt-f">
              <?= csrfField() ?>
              <input type="hidden" name="stage_key" value="<?= e($b['next']['key']) ?>">
              <input type="hidden" name="bill_id" value="<?= (int) $bi['id'] ?>">
              <button class="mt-bill-btn" type="submit"><?= icon('check', 14) ?> <?= e($b['next']['label']) ?></button>
            </form>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
</main>
</body>
</html>
