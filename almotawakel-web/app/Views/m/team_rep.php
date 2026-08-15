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
// ⚠️ الرقم الكبير = **عهدة يوم العمل بعد المستنّي** (`day_pocket`)، مش التراكمي.
//    عهدة المناديب يومية بلا ترحيل (v49)، والمستنّي فلوس خرجت من إيده فعلاً —
//    فده الرقم الصادق: «فاضل معاه من عهدة اليوم كام». التراكمي جوّه التفاصيل
//    بتسمية «برّه معاه (كل الأيام)» لأنه أساس التسوية مش باقي عهدة اليوم.
$dp    = $d['day_pocket'];
$owed  = (float) $dp['pocket'] < 0;
$shown = abs((float) $dp['pocket']);
$dep   = max((float) $dp['deposits'], 0.01);
$usedPct = min(100, max(0, ((float) $dp['spent'] / $dep) * 100));
$waitPct = min(100 - $usedPct, max(0, ((float) $dp['pending'] / $dep) * 100));
$initial = mb_substr(trim((string) $rep['name']) !== '' ? trim((string) $rep['name']) : '؟', 0, 1);
$wa = wa_phone((string) ($rep['phone'] ?? ''));

/** سطر واحد من حركات العهدة — الشكل بيختلف حسب مصدر الحركة. */
$spendRow = function (array $s) use ($num) {
    $src = $s['src'] ?? 'permit';
    if ($src === 'misc') {
        $ic = 'tag'; $head = $s['category'] ?: 'نثرية'; $sub = 'مصروف عام بلا إذن';
    } elseif ($src === 'handover') {
        $ic = 'truck'; $head = 'تسليم لسائق';
        $sub = trim((string) ($s['driver_name'] ?? '')) !== '' ? (string) $s['driver_name'] : '—';
        if (!empty($s['bill_number'])) { $sub .= ' · بوليصة ' . $s['bill_number']; }
    } elseif ($src === 'bill') {
        $ic = 'truck'; $head = 'عهدة بوليصة';
        $sub = trim((string) ($s['driver_name'] ?? '')) !== '' ? (string) $s['driver_name'] : '—';
        if (!empty($s['bill_number'])) { $sub .= ' · بوليصة ' . $s['bill_number']; }
    } elseif ($src === 'transfer') {
        $ic = 'shuffle'; $head = 'تحويل لمندوب'; $sub = 'لـ ' . ($s['other_name'] ?? '—');
    } else {
        $ic = ($s['stage_key'] ?? '') === 'receipts' ? 'receipt' : 'banknote';
        $head = ($s['permit_type'] ?? 'sader') === 'warid' ? 'شهادة ' : 'إذن ';
        $head .= $s['permit_no'] ?: '#' . (int) $s['permit_id'];
        $sub = trim((string) ($s['client'] ?: $s['company'] ?: '')) ?: '—';
    }
    $visa = !empty($s['is_visa']);
    ?>
    <article class="hm-slip">
      <span class="hm-slip-ic" aria-hidden="true"><?= icon($ic, 18) ?></span>
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
          <span class="hm-st is-pending" title="اتخصم من كارت المكتب مش من العهدة"><?= icon('credit-card', 14) ?> فيزا</span>
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
    <?php $backTo = 'm/team'; require __DIR__ . '/_back.php'; ?>
    <span class="hm-avatar" aria-hidden="true"><?= e($initial) ?></span>
    <span class="hm-hi">
      <small><?= ($rep['status'] ?? 'active') === 'active' ? 'مندوب' : 'متوقف' ?></small>
      <b><?= e($rep['name']) ?></b>
    </span>
    <?php if ($wa !== ''): ?>
      <a class="hm-icon-btn" href="https://wa.me/<?= e($wa) ?>" target="_blank" rel="noopener" aria-label="واتساب"><?= icon('send', 16) ?></a>
    <?php endif; ?>
  </header>

  <section class="hm-balance <?= $owed ? 'is-owed' : '' ?>">
    <div class="hm-balance-head">
      <span><?= $owed ? 'دفع أكتر من عهدة اليوم — ليه عندنا' : 'الفاضل من عهدة ' . e(dfmt((string) $d['day'])) ?></span>
    </div>
    <p class="hm-amt"><i>ج</i><b><?= $num($shown) ?></b></p>

    <div class="hm-more-box">
      <div class="hm-track" role="img"
           aria-label="صرف <?= $num($dp['spent']) ?> من <?= $num($dp['deposits']) ?> النازل اليوم">
        <i class="used" style="width:<?= round($usedPct, 1) ?>%"></i>
        <i class="wait" style="width:<?= round($waitPct, 1) ?>%"></i>
      </div>
      <?php /* ⚠️ **كل رقم هنا عن يوم العمل بس** (قرار المالك 2026-07-26). الأرقام
               التراكمية («برّه معاه كل الأيام» · «نزل له كله» · «صرف كله» ·
               «مستني كله») اتشالت — كانت بتخلط أيامًا تانية على شاشة المفروض
               توصف اليوم. التراكمي شغل المكتب (`/rep-custody`). */ ?>
      <dl class="hm-dl">
        <div><dt>نزل له</dt><dd><?= $num($dp['deposits']) ?></dd></div>
        <div><dt>صرف</dt><dd><?= $num($dp['spent']) ?></dd></div>
        <?php if ((float) $dp['pending'] > 0): ?>
          <div><dt>مستني مراجعة</dt><dd><?= $num($dp['pending']) ?></dd></div>
        <?php endif; ?>
        <?php if ((float) ($dp['transfer_in'] ?? 0) > 0): ?>
          <div><dt>وصله من زميل</dt><dd><?= $num($dp['transfer_in']) ?></dd></div>
        <?php endif; ?>
        <?php if ((float) ($dp['transfer_out'] ?? 0) > 0): ?>
          <div><dt>حوّل لزميل</dt><dd><?= $num($dp['transfer_out']) ?></dd></div>
        <?php endif; ?>
        <?php /* سالب = تصفية رصيد سالب (المكتب دفع له) */ ?>
        <?php if ((float) $dp['returned'] > 0.005): ?>
          <div><dt>رجّع</dt><dd><?= $num($dp['returned']) ?></dd></div>
        <?php elseif ((float) $dp['returned'] < -0.005): ?>
          <div><dt>المكتب دفعله</dt><dd><?= $num(abs((float) $dp['returned'])) ?></dd></div>
        <?php endif; ?>
      </dl>
      <p class="tw-hint">كل الأرقام والبنود في الشاشة دي عن <b>يوم <?= e(dfmt((string) $d['day'])) ?></b> بس. و«الفاضل» بيخصم اللي دفعه <b>حتى لو لسه مستني اعتماد</b>.</p>
    </div>
  </section>

  <?php /* شريط اليوم — إلزامي بعد ما الشاشة بقت يوم واحد، وإلا القوايم تبان
           فاضية والمشرف مايعرفش إن السبب إنه شغّال على يوم تاني. */ ?>
  <a class="hm-wd <?= $d['day'] === date('Y-m-d') ? '' : 'is-past' ?>" href="<?= url('m/work-date') ?>">
    <span class="hm-wd-ic"><?= icon('calendar', 16) ?></span>
    <span class="hm-wd-txt">
      <small>بتشوف بيانات يوم</small>
      <b><?= e(dfmt((string) $d['day'])) ?><?= $d['day'] === date('Y-m-d') ? ' (النهاردة)' : ' — مش النهاردة' ?></b>
    </span>
    <span class="hm-wd-go">غيّر</span>
  </a>
</div>

<main class="hm-sheet">
  <span class="hm-grab" aria-hidden="true"></span>

  <?php if ($m = flash('error')): ?><div class="flash err"><?= e($m) ?></div><?php endif; ?>

  <div class="hm-filters" aria-label="أقسام المندوب">
    <?php
      // العدّادات كلها عن يوم العمل بس — نفس نطاق القوايم تحتها بالظبط
      $tabs = [
        'spend'   => 'صرف من العهدة (' . count($d['spending']) . ')',
        'entries' => 'إدخالاته (' . count($d['submissions']) . ')',
        'in'      => 'اللي نزل له (' . count($d['deposits']) . ')',
      ];
      foreach ($tabs as $k => $label): ?>
      <a href="<?= url('m/team/' . (int) $rep['id'] . '?tab=' . $k) ?>"
         class="<?= $tab === $k ? 'is-on' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($tab === 'spend'): ?>
    <?php if (!$d['spending']): ?>
      <div class="hm-empty"><span class="ic"><?= icon('receipt', 14) ?></span>
        <b>ما صرفش حاجة في يوم <?= e(dfmt((string) $d['day'])) ?></b>
        <p>الشاشة بتعرض اليوم ده بس — لو بتدوّر على بند يوم تاني غيّر اليوم من فوق.</p>
      </div>
    <?php else: ?>
      <?php foreach ($d['spending'] as $s) { $spendRow($s); } ?>
      <p class="tw-note">
        <?= icon('credit-card', 14) ?> البند المدفوع بالفيزا بيظهر هنا للعلم بس — بيتخصم من كارت المكتب مش من عهدته.
      </p>
    <?php endif; ?>

  <?php elseif ($tab === 'entries'): ?>
    <?php if (!$d['submissions']): ?>
      <div class="hm-empty"><span class="ic"><?= icon('edit', 14) ?></span>
        <b>مفيش إدخالات في يوم <?= e(dfmt((string) $d['day'])) ?></b>
        <p>غيّر اليوم من فوق لو بتدوّر على إدخالات يوم تاني.</p>
      </div>
    <?php else: foreach ($d['submissions'] as $s): ?>
      <?php $isMisc = \App\Models\RepPortal::isMisc((string) $s['kind']); ?>
      <article class="hm-slip is-<?= e($s['status']) ?>">
        <span class="hm-slip-ic" aria-hidden="true"><?= $isMisc ? icon('tag', 18) : ($s['kind'] === 'receipt' ? icon('receipt', 18) : icon('banknote', 18)) ?></span>
        <div class="hm-slip-mid">
          <b><?= e($s['note']) ?></b>
          <div class="hm-slip-meta">
            <span><?= e(dfmt($s['entry_date'])) ?></span>
            <span>· <?= $isMisc ? 'نثرية' : e($s['permit_no'] ?: '#' . (int) $s['permit_id']) ?></span>
            <?php if ((int) $s['qty'] > 1): ?><span>· <?= (int) $s['qty'] ?>×<?= $num($s['unit_amount']) ?></span><?php endif; ?>
            <?php if (!empty($s['is_visa'])): ?><span>· <?= icon('credit-card', 14) ?></span><?php endif; ?>
            <?php if (!empty($s['has_photo'])): ?><span>· <?= icon('search', 14) ?></span><?php endif; ?>
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
    <div class="hm-sheet-head"><h2>تنزيلات عهدة يوم <?= e(dfmt((string) $d['day'])) ?></h2></div>
    <?php if (!$d['deposits']): ?>
      <div class="hm-empty"><span class="ic"><?= icon('coins', 14) ?></span>
        <b>ماحدش نزّل له عهدة في اليوم ده</b>
        <p>التنزيل بيتعمل من المكتب — والشاشة دي عرض بس.</p>
      </div>
    <?php else: foreach ($d['deposits'] as $dep2): ?>
      <?php /* ⚠️ مصدر التنزيل من `RepCustody::sourceBadge()` زي شاشات المكتب —
               نفس الصياغة بالحرف. و**الأثر على الخزنة مش معروض هنا** عن قصد:
               رصيد الدرج شغل مكتب، والمشرف محتاج يعرف الفلوس جت منين وبس. */ ?>
      <?php $src2 = \App\Models\RepCustody::sourceBadge($dep2['source'] ?? null); ?>
      <article class="hm-slip">
        <span class="hm-slip-ic" aria-hidden="true"><?= icon('coins', 14) ?></span>
        <div class="hm-slip-mid">
          <b><?= e($dep2['note'] ?: 'تنزيل عهدة') ?></b>
          <div class="hm-slip-meta"><span><?= e(dfmt($dep2['dep_date'])) ?></span><?php
            if ($src2): ?><span><?= e($src2['short']) ?></span><?php endif; ?></div>
        </div>
        <div class="hm-slip-end"><span class="hm-slip-amt"><?= $num($dep2['amount']) ?></span></div>
      </article>
    <?php endforeach; endif; ?>

    <?php if ($d['transfers_in']): ?>
      <div class="hm-sheet-head"><h2>تحويلات وصلته من زملاء</h2></div>
      <?php foreach ($d['transfers_in'] as $t): ?>
        <article class="hm-slip">
          <span class="hm-slip-ic" aria-hidden="true"><?= icon('shuffle', 14) ?></span>
          <div class="hm-slip-mid">
            <b>من <?= e($t['other_name'] ?? '—') ?></b>
            <div class="hm-slip-meta">
              <span><?= e(dfmt($t['wallet_date'] ?? $t['transfer_date'])) ?></span>
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
          <span class="hm-slip-ic" aria-hidden="true"><?= icon('check', 14) ?></span>
          <div class="hm-slip-mid">
            <b><?= (float) $st['returned'] < 0 ? 'المكتب دفعله ' : 'رجّع ' ?><?= $num(abs((float) $st['returned'])) ?></b>
            <div class="hm-slip-meta">
              <span><?= e(dfmt($st['settle_date'])) ?></span>
              <?php if (!empty($st['note'])): ?><span>· <?= e($st['note']) ?></span><?php endif; ?>
            </div>
          </div>
          <div class="hm-slip-end"><span class="hm-slip-amt"><?= $num(abs((float) $st['returned'])) ?></span></div>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>

  <p class="tw-note"><?= icon('search', 14) ?> متابعة بس — الاعتماد والرفض وتنزيل العهدة من المكتب.</p>
</main>

</body>
</html>
