<?php
/**
 * تفصيل مندوب واحد للمشرف (v47) — عهدته وكل حركة طلعت منها وإدخالاته.
 *
 * ⚠️ **عرض فقط** — مفيش فورم واحد في الصفحة دي.
 * ⚠️ **مفيش سعر عميل** (actual_amount/actual_unit) ولا رصيد خزنة — قاعدة v35.
 * ⚠️ صورة الإيصال بتتعرض كشارة 📷 بس: بثّ الصور مسموح لصاحب البند أو للمكتب
 *    (FileController::submission) وسبناه زي ما هو عن قصد.
 *
 * @var array $d @var string $tab
 */
$rep   = $d['rep'];
$b     = $d['b'];
$num   = fn($v) => number_format((float) $v, 0);
$owed  = $d['pocket'] < 0;
$shown = abs((float) $d['pocket']);
$dep   = max((float) $b['deposits'], 0.01);
$usedPct = min(100, max(0, ((float) $b['spent'] / $dep) * 100));
$waitPct = min(100 - $usedPct, max(0, ((float) $d['pending'] / $dep) * 100));
$initial = mb_substr(trim((string) $rep['name']) !== '' ? trim((string) $rep['name']) : '؟', 0, 1);
$wa = wa_phone((string) ($rep['phone'] ?? ''));

/** سطر واحد من حركات العهدة — الشكل بيختلف حسب مصدر الحركة. */
$spendRow = function (array $s) use ($num) {
    $src = $s['src'] ?? 'permit';
    if ($src === 'misc') {
        $icon = '🧷'; $head = $s['category'] ?: 'نثرية'; $sub = 'مصروف عام بلا إذن';
    } elseif ($src === 'handover') {
        $icon = '🚚'; $head = 'تسليم لسائق';
        $sub = trim((string) ($s['driver_name'] ?? '')) !== '' ? (string) $s['driver_name'] : '—';
        if (!empty($s['bill_number'])) { $sub .= ' · بوليصة ' . $s['bill_number']; }
    } elseif ($src === 'transfer') {
        $icon = '🔁'; $head = 'تحويل لمندوب'; $sub = 'لـ ' . ($s['other_name'] ?? '—');
    } else {
        $icon = ($s['stage_key'] ?? '') === 'receipts' ? '🧾' : '💸';
        $head = ($s['permit_type'] ?? 'sader') === 'warid' ? 'شهادة ' : 'إذن ';
        $head .= $s['permit_no'] ?: '#' . (int) $s['permit_id'];
        $sub = trim((string) ($s['client'] ?: $s['company'] ?: '')) ?: '—';
    }
    $visa = !empty($s['is_visa']);
    ?>
    <article class="hm-slip">
      <span class="hm-slip-ic" aria-hidden="true"><?= $icon ?></span>
      <div class="hm-slip-mid">
        <b><?= e($s['note'] ?: $head) ?></b>
        <div class="hm-slip-meta">
          <span><?= e(dfmt($s['d'])) ?></span>
          <span>· <?= e($head) ?></span>
          <?php if ($sub !== '—' && $sub !== ''): ?><span>· <?= e($sub) ?></span><?php endif; ?>
          <?php if ((int) ($s['qty'] ?? 1) > 1): ?><span>· <?= (int) $s['qty'] ?> عدد</span><?php endif; ?>
          <?php if (!empty($s['ref_no'])): ?><span>· إيصال <?= e($s['ref_no']) ?></span><?php endif; ?>
        </div>
      </div>
      <div class="hm-slip-end">
        <span class="hm-slip-amt"><?= $num($s['amount']) ?></span>
        <?php if ($visa): ?>
          <span class="hm-st is-pending" title="اتخصم من كارت المكتب مش من العهدة">💳 فيزا</span>
        <?php endif; ?>
      </div>
    </article>
    <?php
};
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0F151E">
<title><?= e($rep['name']) ?> — المتوكل</title>
<link rel="icon" href="<?= url('assets/img/logo.png') ?>">
<link rel="stylesheet" href="<?= url('assets/css/m.css') ?>?v=<?= @filemtime(\App\Core\App::basePath('public/assets/css/m.css')) ?>">
<?php require __DIR__ . "/_pwa.php"; ?>
</head>
<body class="hm-body">

<div class="hm-top">
  <header class="hm-bar">
    <a class="hf-back" href="<?= url('m/team') ?>" aria-label="رجوع">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 5l-7 7 7 7" fill="none"
           stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </a>
    <span class="hm-avatar" aria-hidden="true"><?= e($initial) ?></span>
    <span class="hm-hi">
      <small><?= ($rep['status'] ?? 'active') === 'active' ? 'مندوب' : 'متوقف' ?></small>
      <b><?= e($rep['name']) ?></b>
    </span>
    <?php if ($wa !== ''): ?>
      <a class="hm-icon-btn" href="https://wa.me/<?= e($wa) ?>" target="_blank" rel="noopener" aria-label="واتساب">💬</a>
    <?php endif; ?>
  </header>

  <section class="hm-balance <?= $owed ? 'is-owed' : '' ?>">
    <div class="hm-balance-head">
      <span><?= $owed ? 'دفع من جيبه — ليه عندنا' : 'الفاضل معاه دلوقتي' ?></span>
    </div>
    <p class="hm-amt"><i>ج</i><b><?= $num($shown) ?></b></p>

    <div class="hm-more-box">
      <div class="hm-track" role="img"
           aria-label="صرف <?= $num($b['spent']) ?> من <?= $num($b['deposits']) ?>">
        <i class="used" style="width:<?= round($usedPct, 1) ?>%"></i>
        <i class="wait" style="width:<?= round($waitPct, 1) ?>%"></i>
      </div>
      <dl class="hm-dl">
        <?php /* v49 — عهدة يوم العمل جوّه التراكمي عشان الفرق يبان بوضوح */ ?>
        <div><dt>عهدة <?= e(dfmt((string) $d['day'])) ?></dt><dd><?= $num($d['day_b']['balance']) ?></dd></div>
        <div><dt>نزل له</dt><dd><?= $num($b['deposits']) ?></dd></div>
        <div><dt>صرف</dt><dd><?= $num($b['spent']) ?></dd></div>
        <?php if ($d['pending'] > 0): ?>
          <div><dt>مستني مراجعة</dt><dd><?= $num($d['pending']) ?></dd></div>
        <?php endif; ?>
        <?php if ((float) $b['returned'] > 0): ?>
          <div><dt>رجّع</dt><dd><?= $num($b['returned']) ?></dd></div>
        <?php endif; ?>
        <?php if ((float) ($b['transfer_in'] ?? 0) > 0): ?>
          <div><dt>وصله من زملاء</dt><dd><?= $num($b['transfer_in']) ?></dd></div>
        <?php endif; ?>
        <?php if ((float) ($b['transfer_out'] ?? 0) > 0): ?>
          <div><dt>حوّل لزملاء</dt><dd><?= $num($b['transfer_out']) ?></dd></div>
        <?php endif; ?>
      </dl>
    </div>
  </section>
</div>

<main class="hm-sheet">
  <span class="hm-grab" aria-hidden="true"></span>

  <?php if ($m = flash('error')): ?><div class="flash err"><?= e($m) ?></div><?php endif; ?>

  <div class="hm-filters" aria-label="أقسام المندوب">
    <?php
      $tabs = [
        'spend'   => 'صرف من العهدة (' . count($d['spending']) . ')',
        'entries' => 'إدخالاته (' . count($d['submissions']) . ')',
        'in'      => 'اللي نزل له',
      ];
      foreach ($tabs as $k => $label): ?>
      <a href="<?= url('m/team/' . (int) $rep['id'] . '?tab=' . $k) ?>"
         class="<?= $tab === $k ? 'is-on' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($tab === 'spend'): ?>
    <?php if (!$d['spending']): ?>
      <div class="hm-empty"><span class="ic">🧾</span><b>لسه ما صرفش حاجة من عهدته</b></div>
    <?php else: ?>
      <?php foreach ($d['spending'] as $s) { $spendRow($s); } ?>
      <p class="tw-note">
        💳 البند المدفوع بالفيزا بيظهر هنا للعلم بس — بيتخصم من كارت المكتب مش من عهدته.
      </p>
    <?php endif; ?>

  <?php elseif ($tab === 'entries'): ?>
    <?php if (!$d['submissions']): ?>
      <div class="hm-empty"><span class="ic">📝</span><b>مفيش إدخالات</b></div>
    <?php else: foreach ($d['submissions'] as $s): ?>
      <?php $isMisc = \App\Models\RepPortal::isMisc((string) $s['kind']); ?>
      <article class="hm-slip is-<?= e($s['status']) ?>">
        <span class="hm-slip-ic" aria-hidden="true"><?= $isMisc ? '🧷' : ($s['kind'] === 'receipt' ? '🧾' : '💸') ?></span>
        <div class="hm-slip-mid">
          <b><?= e($s['note']) ?></b>
          <div class="hm-slip-meta">
            <span><?= e(dfmt($s['entry_date'])) ?></span>
            <span>· <?= $isMisc ? 'نثرية' : e($s['permit_no'] ?: '#' . (int) $s['permit_id']) ?></span>
            <?php if ((int) $s['qty'] > 1): ?><span>· <?= (int) $s['qty'] ?>×<?= $num($s['unit_amount']) ?></span><?php endif; ?>
            <?php if (!empty($s['is_visa'])): ?><span>· 💳</span><?php endif; ?>
            <?php if (!empty($s['has_photo'])): ?><span>· 📷</span><?php endif; ?>
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

  <?php else: ?>
    <div class="hm-sheet-head"><h2>تنزيلات العهدة</h2></div>
    <?php if (!$d['deposits']): ?>
      <div class="hm-empty"><span class="ic">💰</span><b>مفيش تنزيلات</b></div>
    <?php else: foreach ($d['deposits'] as $dep2): ?>
      <article class="hm-slip">
        <span class="hm-slip-ic" aria-hidden="true">💰</span>
        <div class="hm-slip-mid">
          <b><?= e($dep2['note'] ?: 'تنزيل عهدة') ?></b>
          <div class="hm-slip-meta"><span><?= e(dfmt($dep2['dep_date'])) ?></span></div>
        </div>
        <div class="hm-slip-end"><span class="hm-slip-amt"><?= $num($dep2['amount']) ?></span></div>
      </article>
    <?php endforeach; endif; ?>

    <?php if ($d['transfers_in']): ?>
      <div class="hm-sheet-head"><h2>تحويلات وصلته من زملاء</h2></div>
      <?php foreach ($d['transfers_in'] as $t): ?>
        <article class="hm-slip">
          <span class="hm-slip-ic" aria-hidden="true">🔁</span>
          <div class="hm-slip-mid">
            <b>من <?= e($t['other_name'] ?? '—') ?></b>
            <div class="hm-slip-meta">
              <span><?= e(dfmt($t['transfer_date'])) ?></span>
              <?php if (!empty($t['note'])): ?><span>· <?= e($t['note']) ?></span><?php endif; ?>
            </div>
          </div>
          <div class="hm-slip-end"><span class="hm-slip-amt"><?= $num($t['amount']) ?></span></div>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($d['settlements']): ?>
      <div class="hm-sheet-head"><h2>التسويات</h2></div>
      <?php foreach ($d['settlements'] as $st): ?>
        <article class="hm-slip">
          <span class="hm-slip-ic" aria-hidden="true">✅</span>
          <div class="hm-slip-mid">
            <b>رجّع <?= $num($st['returned']) ?></b>
            <div class="hm-slip-meta">
              <span><?= e(dfmt($st['settle_date'])) ?></span>
              <?php if (!empty($st['note'])): ?><span>· <?= e($st['note']) ?></span><?php endif; ?>
            </div>
          </div>
          <div class="hm-slip-end"><span class="hm-slip-amt"><?= $num($st['returned']) ?></span></div>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>

  <p class="tw-note">👀 متابعة بس — الاعتماد والرفض وتنزيل العهدة من المكتب.</p>
</main>

</body>
</html>
