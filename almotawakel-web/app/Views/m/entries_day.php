<?php
/**
 * بنود يوم تاني — **عرض للقراءة بس**.
 *
 * ⚠️⚠️ **مفيش ولا فورم كتابة واحد في الصفحة دي، ولا زرار تعديل ولا حذف ولا
 *    تسجيل بند.** الشاشة اتعملت عشان المندوب يبصّ على بند يوم فات **بلا ما
 *    يغيّر يوم شغله** — لأن تغيير يوم العمل بيغيّر اليوم اللي هيكتب عليه كمان،
 *    فكان ممكن يسجّل بند النهاردة على يوم فات بلا ما ياخد باله. لو ضفت أي
 *    إجراء هنا، رجّعت الباج ده من الشباك.
 *
 * ⚠️ قاعدة v35 سارية: ولا مبلغ بيخصّ العميل — بس اللي المندوب دفعه بنفسه.
 * ⚠️ كل الكلاسات ببادئة `hm-` (m.css) — البوابة ما بتحمّلش app.css.
 *
 * @var string $day @var string $workDate @var string $status
 * @var array $submissions @var array $pocket
 */
$num  = fn($v) => number_format((float) $v, 0);
$isToday = $day === $workDate;
$prev = date('Y-m-d', strtotime($day . ' -1 day'));
$next = date('Y-m-d', strtotime($day . ' +1 day'));
$dayUrl = fn(string $d, string $st) => url('m/entries?day=' . $d . ($st !== '' ? '&status=' . $st : ''));
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0F151E">
<title>بنود يوم <?= e(dfmt($day)) ?> — المتوكل</title>
<link rel="icon" href="<?= url('assets/img/logo.png') ?>">
<link rel="stylesheet" href="<?= url('assets/css/m.css') ?>?v=<?= @filemtime(\App\Core\App::basePath('public/assets/css/m.css')) ?>">
<?php require __DIR__ . "/_pwa.php"; ?>
</head>
<body class="hm-body">

<div class="hm-top">
  <header class="hm-bar">
    <?php $backTo = 'm?tab=entries'; require __DIR__ . '/_back.php'; ?>
    <span class="hm-hi"><small>بنود يوم</small><b><?= e(dfmt($day)) ?></b></span>
  </header>

  <?php /* شريط تنقّل الأيام — بيغيّر **اللي بتشوفه** لا اللي بتكتب عليه. */ ?>
  <nav class="hm-daynav" aria-label="تنقّل بين الأيام">
    <a href="<?= $dayUrl($prev, $status) ?>" aria-label="اليوم اللي قبله">‹ الأقدم</a>
    <?php if (!$isToday): ?>
      <a class="is-now" href="<?= $dayUrl($workDate, $status) ?>">رجّعني ليوم شغلي</a>
    <?php else: ?>
      <span class="is-now">ده يوم شغلك</span>
    <?php endif; ?>
    <a href="<?= $dayUrl($next, $status) ?>" aria-label="اليوم اللي بعده">الأحدث ›</a>
  </nav>

  <?php /* ⚠️ التنبيه ده مش زخرفة — هو اللي بيمنع المندوب يفتكر إنه بيشتغل على
           اليوم ده. مفيش أي إجراء في الصفحة عن قصد، والسطر ده بيقول السبب. */ ?>
  <div class="hm-readonly" role="note">
    <?= icon('eye', 14) ?>
    <span>عرض بس — عشان تسجّل أو تعدّل لازم <a href="<?= url('m/work-date') ?>">تغيّر يوم شغلك</a></span>
  </div>

  <?php /* عهدة اليوم ده — نفس مصدر الشاشة الرئيسية (`dayPocket`) فمستحيل
           يقولوا رقمين مختلفين لنفس اليوم. */ ?>
  <div class="hm-daysum">
    <span><b><?= $num($pocket['deposits'] ?? 0) ?></b> نزل</span>
    <span><b><?= $num($pocket['spent'] ?? 0) ?></b> صرف</span>
    <?php if (($pocket['pending'] ?? 0) > 0): ?>
      <span><b><?= $num($pocket['pending']) ?></b> مستني</span>
    <?php endif; ?>
  </div>
</div>

<main class="hm-sheet">
  <span class="hm-grab" aria-hidden="true"></span>

  <div class="hm-filters" aria-label="فلترة البنود">
    <?php foreach (['' => 'الكل', 'pending' => 'مستني', 'approved' => 'اتقبل', 'rejected' => 'اترفض'] as $k => $label): ?>
      <a href="<?= $dayUrl($day, $k) ?>" class="<?= $status === $k ? 'is-on' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$submissions): ?>
    <div class="hm-empty">
      <span class="ic"><?= icon('receipt', 14) ?></span>
      <b>مفيش بنود في يوم <?= e(dfmt($day)) ?></b>
      <p>جرّب يوم تاني من الأسهم فوق.</p>
    </div>
  <?php else: foreach ($submissions as $s): ?>
    <?php $isMisc = \App\Models\RepPortal::isMisc((string) $s['kind']); ?>
    <article class="hm-slip is-<?= e($s['status']) ?>">
      <?php /* v71 — أيقونة النثرية بقت أيقونة وسمها، فالمندوب يفرّق بين
               السلفة والمواصلات من الشكل قبل ما يقرا. */ ?>
      <span class="hm-slip-ic" aria-hidden="true"><?= $isMisc
            ? icon(\App\Models\RepMiscKind::icon($s['misc_kind'] ?? null), 18)
            : ($s['kind'] === 'receipt' ? icon('receipt', 18) : icon('banknote', 18)) ?></span>
      <div class="hm-slip-mid">
        <b><?= e($s['note']) ?></b>
        <div class="hm-slip-meta">
          <span><?= e(dfmt($s['entry_date'])) ?></span>
          <span>· <?= $isMisc
                ? e(\App\Models\RepMiscKind::label($s['misc_kind'] ?? null))
                : e($s['permit_no'] ?: '#' . $s['permit_id']) ?></span>
          <?php if ((int) $s['qty'] > 1): ?><span>· <?= (int) $s['qty'] ?>×<?= $num($s['unit_amount']) ?></span><?php endif; ?>
          <?php if (!empty($s['is_visa'])): ?><span>· <?= icon('credit-card', 14) ?></span><?php endif; ?>
        </div>
      </div>
      <div class="hm-slip-end">
        <span class="hm-slip-amt"><?= $num($s['amount']) ?></span>
        <?php if ($s['status'] === 'pending'): ?>
          <span class="hm-st is-pending"><?= !empty($s['review_started_at']) ? 'بيتراجع' : 'مستني' ?></span>
        <?php elseif ($s['status'] === 'approved'): ?>
          <span class="hm-st is-approved">اتقبل</span>
        <?php else: ?>
          <span class="hm-st is-rejected">اترفض</span>
        <?php endif; ?>
      </div>
      <?php if ($s['status'] === 'rejected' && $s['review_note']): ?>
        <div class="hm-why"><p><?= e($s['review_note']) ?></p></div>
      <?php endif; ?>
    </article>
  <?php endforeach; endif; ?>
</main>

</body>
</html>
