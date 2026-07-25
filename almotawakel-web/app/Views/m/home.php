<?php
/**
 * شاشة المندوب الرئيسية — نمط المحفظة (هيدر غامق + شيت أبيض) + مزايا تطبيق.
 *
 * الجديد في الدفعة دي:
 *   • تطبيق قابل للتثبيت (PWA) — زرار «ثبّت التطبيق» بيظهر لما المتصفح يسمح.
 *   • معلومات أغنى على كارت الإذن: عدد البوالص + السائقين + العربيات.
 *   • إشعارات «جديد»: بانر + شارة على الأذونات/العهدة اللي نزلت من آخر زيارة
 *     (المقارنة كلها في المتصفح عبر localStorage — بلا هجرة ولا عمود جديد).
 *   • تنبيه العهدة المنخفضة + زرار واتساب/اتصال بالمكتب.
 *   • لمسات: عدّ الرقم، نعومة الضغط، سحب للتحديث، دخول الكروت متدرّج، اهتزاز خفيف.
 *
 * ⚠️ كل الكلاسات ببادئة `hm-` عن قصد: الشاشات التانية بتشارك m.css.
 * ⚠️ **مفيش أي رقم بيخصّ المكتب على الشاشة دي** (قاعدة v35) — بس عهدة المندوب.
 *
 * @var array $pocket @var array $permits @var array $submissions @var array $counts
 * @var array $deposits @var string $tab @var string $status @var string $clearDraft
 * @var bool $canAddPermit @var string $workDate
 */
$workDate = $workDate ?? date('Y-m-d');
$wdToday  = $workDate === date('Y-m-d');
// ⚠️ v49 — الأرقام دي كلها **عهدة يوم العمل بس** (RepPortal::dayPocket).
//    قرار المالك: الشاشة دي تعرض عهدة اليوم والمدخلات اللي اتدخلت عليها **وبس**؛
//    ممنوع أي رقم من يوم تاني (تراكمي أو بند قديم) يبان هنا، لأنه بيتقري وكأنه
//    خصم من عهدة النهاردة. التراكمي شغل المكتب وشاشة المشرف.
$wdLocked = !empty($pocket['locked']);
$owed  = $pocket['pocket'] < 0;         // صرف في اليوم ده أكتر من اللي نزل له فيه
$shown = abs($pocket['pocket']);
$dep   = max($pocket['deposits'], 0.01);
$usedPct = min(100, max(0, ($pocket['spent'] / $dep) * 100));
$waitPct = min(100 - $usedPct, max(0, ($pocket['pending'] / $dep) * 100));

// عهدة اليوم «قربت تخلص»: صرف ≥80% أو الفاضل أقل من 500، ومش لو مدين (دفع من جيبه)
$lowCustody = !$owed && !$wdLocked && $pocket['deposits'] > 0 && ($shown <= 500 || $usedPct >= 80);

$repName = (string) \App\Core\RepAuth::repName();
$initial = mb_substr(trim($repName) !== '' ? trim($repName) : '؟', 0, 1);

// تواصل المكتب (واتساب/اتصال) — الرقم من الإعدادات
$officePhone = trim((string) \App\Core\App::setting('company_phone', ''));
$officeWa  = wa_phone($officePhone);
$officeTel = preg_replace('/[^\d+]/', '', ar2en_digits($officePhone));

// الأذونات المنزّلة له بتتفصل في مجموعة لوحدها
$mine = $others = [];
foreach ($permits as $p) { if (!empty($p['mine'])) { $mine[] = $p; } else { $others[] = $p; } }

$num = fn($v) => number_format((float) $v, 0);

/** كارت الإذن — كعب إذن ورقي: تعريف فوق، إحصائيات، تفاصيل مطويّة، إجراء تحت. */
$permitCard = function (array $p) use ($num) {
    $details = array_filter([
        '⚓ الميناء'  => $p['port'],
        '🏢 التوكيل'  => $p['agency'],
        '🚢 الملاحية' => $p['shipping_co'],
        '📍 الوجهة'   => $p['destination'],
    ], fn($v) => trim((string) $v) !== '');
    $drivers = $p['drivers'] ?? [];
    $bills   = (int) ($p['bills'] ?? 0);
    $cars    = (int) ($p['cars'] ?? 0);
    $movs    = count($p['movements']);
    ?>
    <article class="hm-stub <?= $p['type'] === 'warid' ? 'is-warid' : 'is-sader' ?>"
             data-created="<?= e((string) ($p['created_at'] ?? '')) ?>"
             data-search="<?= e(mb_strtolower($p['no'] . ' ' . $p['company'] . ' ' . $p['client'] . ' ' . $p['destination'] . ' ' . implode(' ', $drivers))) ?>">
      <span class="hm-new-tag" hidden>🆕 جديد</span>
      <div class="hm-stub-body">
        <div class="hm-stub-head">
          <h3><?= e($p['company'] ?: $p['client'] ?: 'بدون شركة') ?></h3>
          <span class="hm-kind"><?= e($p['type_label']) ?></span>
        </div>
        <div class="hm-ref"><?= e($p['no']) ?></div>

        <?php if ($p['container_parts']): ?>
          <div class="hm-chips">
            <?php foreach ($p['container_parts'] as $part): ?>
              <?php /* bdi dir=ltr إلزامي: من غيرها «8×20 قدم» بتتعرض «20×8 قدم» */ ?>
              <span class="hm-chip">📦 <bdi dir="ltr"><?= e($part) ?></bdi></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if ($bills || $movs > 1 || $cars): ?>
          <div class="hm-metrics">
            <?php if ($bills): ?><span><b><?= $num($bills) ?></b> بوليصة</span><?php endif; ?>
            <?php if ($cars): ?><span><b><?= $num($cars) ?></b> عربية</span><?php endif; ?>
            <?php if (count($drivers)): ?><span><b><?= $num(count($drivers)) ?></b> سائق</span><?php endif; ?>
            <?php if ($movs > 1): ?><span><b><?= $num($movs) ?></b> حركات</span><?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($details || $drivers): ?>
          <details class="hm-more">
            <summary>تفاصيل الإذن</summary>
            <?php if ($details): ?>
              <dl>
                <?php foreach ($details as $label => $value): ?>
                  <dt><?= $label ?></dt><dd><?= e($value) ?></dd>
                <?php endforeach; ?>
              </dl>
            <?php endif; ?>
            <?php if ($drivers): ?>
              <div class="hm-drivers">
                <span class="hm-drivers-lbl">🚚 السائقين</span>
                <?php foreach ($drivers as $d): ?><span class="hm-driver"><?= e($d) ?></span><?php endforeach; ?>
              </div>
            <?php endif; ?>
          </details>
        <?php endif; ?>
      </div>

      <a class="hm-stub-go" href="<?= url('m/permit/' . $p['id']) ?>">
        <span>سجّل بند</span>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 5l-7 7 7 7" fill="none"
             stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </a>
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
<title>شغلي — المتوكل</title>
<link rel="icon" href="<?= url('assets/img/logo.png') ?>">
<link rel="stylesheet" href="<?= url('assets/css/m.css') ?>?v=<?= @filemtime(\App\Core\App::basePath('public/assets/css/m.css')) ?>">
<?php require __DIR__ . "/_pwa.php"; ?>
</head>
<body class="hm-body">

<div class="hm-ptr" id="ptr" aria-hidden="true"><span class="hm-ptr-spin"></span></div>

<div class="hm-top">
  <header class="hm-bar">
    <span class="hm-avatar" aria-hidden="true"><?= e($initial) ?></span>
    <span class="hm-hi">
      <small>أهلاً</small>
      <b><?= e($repName) ?></b>
    </span>
    <form method="post" action="<?= url('m/logout') ?>" id="logoutForm">
      <?= csrfField() ?>
      <button type="submit" aria-label="خروج">خروج</button>
    </form>
  </header>

  <!-- زرار تثبيت التطبيق (بيظهر بالـJS لما المتصفح يسمح) -->
  <button type="button" class="hm-install" id="installBtn" hidden>
    <span class="ic">⬇️</span>
    <span>ثبّت التطبيق على شاشتك — يفتح أسرع وزي أبليكيشن</span>
    <span class="x" id="installDismiss" role="button" aria-label="إخفاء">✕</span>
  </button>

  <!-- تفعيل الإشعارات (v51) — بيظهر بس لو الجهاز يقدر ولسه مش مفعّلها -->
  <button type="button" class="hm-install" id="pushBtn" hidden>
    <span class="ic">🔔</span>
    <span id="pushBtnTxt">فعّل الإشعارات — عشان يوصلك قرار بنودك فورًا</span>
    <span class="x" id="pushDismiss" role="button" aria-label="إخفاء">✕</span>
  </button>

  <!-- عهدة يوم العمل: الرقم الوحيد اللي بيسحب منه؛ الباقي جوّه «التفاصيل» -->
  <section class="hm-balance <?= $owed ? 'is-owed' : '' ?>">
    <div class="hm-balance-head">
      <span><?= $owed ? 'صرفت أكتر من عهدة اليوم' : 'فاضل من عهدة اليوم' ?></span>
      <button type="button" class="hm-toggle" id="detailsBtn" aria-expanded="false" aria-controls="balanceMore">
        التفاصيل
      </button>
    </div>
    <p class="hm-amt"><i>ج</i><b data-count="<?= (int) round($shown) ?>"><?= $num($shown) ?></b></p>

    <div class="hm-more-box" id="balanceMore" hidden>
      <div class="hm-track" role="img"
           aria-label="صرفت <?= $num($pocket['spent']) ?> من <?= $num($pocket['deposits']) ?>">
        <i class="used" style="width:<?= round($usedPct, 1) ?>%"></i>
        <i class="wait" style="width:<?= round($waitPct, 1) ?>%"></i>
      </div>
      <dl class="hm-dl">
        <div><dt>نزل لك اليوم</dt><dd><?= $num($pocket['deposits']) ?></dd></div>
        <div><dt>صرفت اليوم</dt><dd><?= $num($pocket['spent']) ?></dd></div>
        <?php if ($pocket['pending'] > 0): ?>
          <div><dt>مستني مراجعة</dt><dd><?= $num($pocket['pending']) ?></dd></div>
        <?php endif; ?>
        <?php if (($pocket['transfer_in'] ?? 0) > 0): ?>
          <div><dt>وصلك من زميل</dt><dd><?= $num($pocket['transfer_in']) ?></dd></div>
        <?php endif; ?>
        <?php if (($pocket['transfer_out'] ?? 0) > 0): ?>
          <div><dt>حوّلت لزميل</dt><dd><?= $num($pocket['transfer_out']) ?></dd></div>
        <?php endif; ?>
        <?php if ($pocket['returned'] > 0): ?>
          <div><dt>رجّعت</dt><dd><?= $num($pocket['returned']) ?></dd></div>
        <?php endif; ?>
      </dl>
    </div>
  </section>

  <?php /* عهدة يوم — اليوم اللي كل البنود بتتسجّل عليه وبتتخصم من عهدته */ ?>
  <a class="hm-wd <?= $wdLocked ? 'is-locked' : ($wdToday ? '' : 'is-past') ?>" href="<?= url('m/work-date') ?>">
    <span class="hm-wd-ic"><?= $wdLocked ? '🔒' : '📅' ?></span>
    <span class="hm-wd-txt">
      <small><?= $wdLocked ? 'اليوم ده اتقفل في المكتب' : 'بتسجّل على عهدة يوم' ?></small>
      <b><?= e(dfmt($workDate)) ?><?= $wdToday ? '' : ' — مش النهاردة' ?></b>
    </span>
    <span class="hm-wd-go">غيّر</span>
  </a>

  <?php if ($wdLocked): ?>
    <div class="hm-locked" role="alert">
      <b>مش هتقدر تسجّل على اليوم ده</b>
      <span><?= e((string) $pocket['lock_reason']) ?></span>
      <a class="hm-locked-btn" href="<?= url('m/work-date') ?>">اختار يوم تاني</a>
    </div>
  <?php endif; ?>

  <?php if ($lowCustody): ?>
    <div class="hm-low">
      <div class="hm-low-txt"><b>عهدتك قربت تخلص</b><span>فاضل معاك <?= $num($shown) ?> ج بس</span></div>
      <?php if ($officeWa !== ''): ?>
        <a class="hm-low-btn" target="_blank" rel="noopener"
           href="https://wa.me/<?= e($officeWa) ?>?text=<?= rawurlencode('السلام عليكم، أنا ' . $repName . ' — عهدتي قربت تخلص، محتاج تنزيل جديد.') ?>">اطلب عهدة</a>
      <?php elseif ($officeTel !== ''): ?>
        <a class="hm-low-btn" href="tel:<?= e($officeTel) ?>">اتصل بالمكتب</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php /* بانر «جديد» — بيتملّى بالـJS من مقارنة localStorage بأختام الأذونات/العهدة */ ?>
  <div class="hm-fresh" id="freshBanner" hidden>
    <span class="hm-fresh-ic">🔔</span>
    <span class="hm-fresh-txt" id="freshText"></span>
    <button type="button" class="hm-fresh-ok" id="freshOk">تمام</button>
  </div>

  <?php /* إجراءات مش على إذن — صف واحد مضغوط عشان الأذونات تفضل هي البطل. */ ?>
  <nav class="hm-actions" aria-label="إجراءات سريعة">
    <a href="<?= url('m/misc') ?>"><span class="ic">🧷</span>نثرية</a>
    <?php if (\App\Models\RepDriverHandover::available()): ?>
      <a href="<?= url('m/handover') ?>"><span class="ic">🚚</span>تسليم سائق</a>
    <?php endif; ?>
    <?php if (\App\Models\RepTransfer::available()): ?>
      <a href="<?= url('m/transfer') ?>"><span class="ic">🔁</span>حوّل لمندوب</a>
    <?php endif; ?>
    <?php if (!empty($canAddPermit)): ?>
      <a href="<?= url('m/permit/new') ?>"><span class="ic">📄</span>إذن جديد</a>
    <?php endif; ?>
    <?php /* v47 — للمشرف بس: متابعة باقي المناديب (عرض فقط) */ ?>
    <?php if (!empty($canWatchTeam)): ?>
      <a href="<?= url('m/team') ?>"><span class="ic">👀</span>المناديب</a>
    <?php endif; ?>
  </nav>

  <?php if ($counts['pending'] > 0): ?>
    <a class="hm-alert" href="<?= url('m?tab=entries&status=pending') ?>">
      <b><?= $counts['pending'] ?> بند مستني المراجعة</b>
      <span><?= $num($pocket['pending']) ?> جنيه لسه ما اتعتمدوش</span>
    </a>
  <?php endif; ?>
</div>

<main class="hm-sheet">
  <span class="hm-grab" aria-hidden="true"></span>

  <?php if ($m = flash('success')): ?><div class="flash ok"><?= e($m) ?></div><?php endif; ?>
  <?php if ($m = flash('error')): ?><div class="flash err"><?= e($m) ?></div><?php endif; ?>

  <?php if ($tab === 'permits'): ?>
    <div class="hm-sheet-head">
      <h2>أذوناتي</h2>
      <?php if ($permits): ?><span class="hm-count"><?= count($permits) ?></span><?php endif; ?>
    </div>

    <?php if (count($permits) > 5): ?>
      <div class="hm-search">
        <input type="search" id="permitSearch" placeholder="دوّر برقم الإذن أو الشركة أو السائق"
               autocomplete="off" aria-label="بحث في الأذونات">
      </div>
    <?php endif; ?>

    <?php if (!$permits): ?>
      <div class="hm-empty">
        <span class="ic">📋</span>
        <b>مفيش أذونات مفتوحة</b>
        <p><?= !empty($canAddPermit)
              ? 'لو فتحت إذن جديد سجّله من «إذن جديد» فوق.'
              : 'المكتب هو اللي بيفتح الأذونات.' ?></p>
      </div>
    <?php else: ?>
      <?php if ($mine): ?>
        <h3 class="hm-group">منزّل لك <span><?= count($mine) ?></span></h3>
        <?php foreach ($mine as $p) { $permitCard($p); } ?>
      <?php endif; ?>
      <?php if ($others): ?>
        <h3 class="hm-group"><?= $mine ? 'أذونات تانية' : 'كل الأذونات المفتوحة' ?> <span><?= count($others) ?></span></h3>
        <?php foreach ($others as $p) { $permitCard($p); } ?>
      <?php endif; ?>
    <?php endif; ?>

    <div class="hm-empty" id="permitNoResults" hidden>
      <span class="ic">🔎</span><b>مفيش إذن مطابق</b>
    </div>

  <?php else: ?>
    <div class="hm-sheet-head">
      <h2>بنود يوم <?= e(dfmt($workDate)) ?></h2>
      <span class="hm-sheet-sub">اللي دخّلته على عهدة اليوم ده بس</span>
    </div>

    <div class="hm-filters" aria-label="فلترة البنود">
      <?php foreach (['' => 'الكل', 'pending' => 'مستني', 'approved' => 'اتقبل', 'rejected' => 'اترفض'] as $k => $label): ?>
        <a href="<?= url('m?tab=entries' . ($k !== '' ? '&status=' . $k : '')) ?>"
           class="<?= $status === $k ? 'is-on' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>

    <?php if (!$submissions): ?>
      <div class="hm-empty"><span class="ic">🧾</span><b>مفيش بنود في يوم <?= e(dfmt($workDate)) ?></b>
        <p>اختار إذن من «أذوناتي» وسجّل أول بند، أو <a href="<?= url('m/work-date') ?>">غيّر اليوم</a> لو بتدوّر على بند يوم تاني.</p></div>
    <?php else: foreach ($submissions as $s): ?>
      <?php $isMisc = \App\Models\RepPortal::isMisc((string) $s['kind']); ?>
      <article class="hm-slip is-<?= e($s['status']) ?>">
        <span class="hm-slip-ic" aria-hidden="true"><?= $isMisc ? '🧷' : ($s['kind'] === 'receipt' ? '🧾' : '💸') ?></span>
        <div class="hm-slip-mid">
          <b><?= e($s['note']) ?></b>
          <div class="hm-slip-meta">
            <span><?= e(dfmt($s['entry_date'])) ?></span>
            <?php /* النثرية مالهاش إذن — من غير الفحص ده كانت هتعرض «#0» (v39) */ ?>
            <span>· <?= $isMisc ? 'نثرية' : e($s['permit_no'] ?: '#' . $s['permit_id']) ?></span>
            <?php if ((int) $s['qty'] > 1): ?><span>· <?= (int) $s['qty'] ?>×<?= $num($s['unit_amount']) ?></span><?php endif; ?>
            <?php if (!empty($s['is_visa'])): ?><span>· 💳</span><?php endif; ?>
            <?php if ($s['photo']): ?><span>· 📷</span><?php endif; ?>
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

        <?php if ($s['status'] === 'pending' && empty($s['review_started_at'])): ?>
          <div class="hm-slip-acts">
            <a href="<?= url('m/entry/' . $s['id'] . '/edit') ?>">عدّل</a>
            <form method="post" action="<?= url('m/entry/' . $s['id'] . '/delete') ?>"
                  class="confirm-delete" data-confirm="تمسح البند ده؟">
              <?= csrfField() ?>
              <button type="submit" aria-label="امسح البند">امسح</button>
            </form>
          </div>
        <?php endif; ?>

        <?php if ($s['status'] === 'rejected' && $s['review_note']): ?>
          <div class="hm-why">
            <p><?= e($s['review_note']) ?></p>
            <a href="<?= $isMisc
                  ? url('m/misc?copy=' . $s['id'])
                  : url('m/permit/' . $s['permit_id'] . '?copy=' . $s['id']) ?>">صحّح وأعد الإرسال</a>
          </div>
        <?php endif; ?>
      </article>
    <?php endforeach; endif; ?>
  <?php endif; ?>
</main>

<!-- التبويبات تحت: متناول الإبهام -->
<nav class="hm-tabs" aria-label="أقسام">
  <a href="<?= url('m?tab=permits') ?>" class="<?= $tab === 'permits' ? 'is-on' : '' ?>"
     <?= $tab === 'permits' ? 'aria-current="page"' : '' ?>>
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h7l5 5v13a1 1 0 01-1 1H7a1 1 0 01-1-1V4a1 1 0 011-1z"/><path d="M14 3v6h5" class="cut"/></svg>
    <span>أذوناتي</span>
    <?php if ($permits): ?><i class="hm-badge"><?= count($permits) ?></i><?php endif; ?>
  </a>
  <a href="<?= url('m?tab=entries') ?>" class="<?= $tab === 'entries' ? 'is-on' : '' ?>"
     <?= $tab === 'entries' ? 'aria-current="page"' : '' ?>>
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2l1.5 1.5L9 2l1.5 1.5L12 2l1.5 1.5L15 2l1.5 1.5L18 2v20l-1.5-1.5L15 22l-1.5-1.5L12 22l-1.5-1.5L9 22l-1.5-1.5L6 22V2z"/></svg>
    <span>بنودي</span>
    <?php if ($counts['pending']): ?><i class="hm-badge is-wait"><?= $counts['pending'] ?></i><?php endif; ?>
  </a>
  <?php /* v47 — تبويب المتابعة بيظهر للمشرف بس (Rep::canWatchReps) */ ?>
  <?php if (!empty($canWatchTeam)): ?>
    <a href="<?= url('m/team') ?>">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 11a4 4 0 100-8 4 4 0 000 8zm0 2c-3.3 0-7 1.7-7 4v3h14v-3c0-2.3-3.7-4-7-4zm8.5-2a3 3 0 100-6 3 3 0 000 6zm.5 2c-1 0-2 .2-2.8.6 1.4 1 2.3 2.3 2.3 3.9V20h4v-3c0-2.1-2.6-3.4-5.5-4h2z"/></svg>
      <span>المناديب</span>
    </a>
  <?php endif; ?>
</nav>

<dialog class="m-confirm" id="mConfirm" aria-labelledby="mConfirmTitle">
  <div class="m-confirm-box">
    <b id="mConfirmTitle">تأكيد الحذف</b>
    <p id="mConfirmText"></p>
    <div><button type="button" class="ghost" data-m-cancel>إلغاء</button><button type="button" class="danger" data-m-ok>نعم، احذف</button></div>
  </div>
</dialog>

<script>
(function () {
  'use strict';
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var vibe = function (ms) { try { if (navigator.vibrate) navigator.vibrate(ms); } catch (e) {} };

  // ── تأكيد الحذف ──────────────────────────────────────
  var confirmBox = document.getElementById('mConfirm'), pendingForm = null;
  document.querySelectorAll('form.confirm-delete').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (form.dataset.confirmed === '1') { form.dataset.confirmed = ''; return; }
      e.preventDefault(); pendingForm = form;
      document.getElementById('mConfirmText').textContent = form.dataset.confirm || 'هل أنت متأكد؟';
      confirmBox.showModal();
    });
  });
  confirmBox.querySelector('[data-m-cancel]').addEventListener('click', function () { pendingForm = null; confirmBox.close(); });
  confirmBox.querySelector('[data-m-ok]').addEventListener('click', function () {
    var form = pendingForm; pendingForm = null; confirmBox.close();
    if (!form) { return; }
    form.dataset.confirmed = '1';
    if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
  });

  // ── تفاصيل الرصيد ────────────────────────────────────
  var dBtn = document.getElementById('detailsBtn'), dBox = document.getElementById('balanceMore');
  if (dBtn && dBox) {
    dBtn.addEventListener('click', function () {
      var open = dBtn.getAttribute('aria-expanded') === 'true';
      dBtn.setAttribute('aria-expanded', String(!open));
      dBox.hidden = open; vibe(8);
    });
  }

  // ── عدّ الرقم من صفر ─────────────────────────────────
  var amt = document.querySelector('.hm-amt b[data-count]');
  if (amt && !reduce) {
    var target = parseInt(amt.dataset.count, 10) || 0;
    if (target > 0) {
      var dur = 650, t0 = null, fmt = function (n) { return n.toLocaleString('en-US'); };
      var step = function (ts) {
        if (t0 === null) { t0 = ts; }
        var k = Math.min(1, (ts - t0) / dur), eased = 1 - Math.pow(1 - k, 3);
        amt.textContent = fmt(Math.round(target * eased));
        if (k < 1) { requestAnimationFrame(step); }
      };
      amt.textContent = '0'; requestAnimationFrame(step);
    }
  }

  // ── مسح مسودة بعد نجاح ───────────────────────────────
  var clearKey = <?= js_json($clearDraft) ?>;
  if (clearKey) { try { localStorage.removeItem(clearKey); } catch (e) {} }

  // ── تثبيت التطبيق (PWA) ──────────────────────────────
  var installBtn = document.getElementById('installBtn');
  function showInstall() {
    if (!installBtn || localStorage.getItem('alm:m:install-hide') === '1') { return; }
    if (window.matchMedia && matchMedia('(display-mode: standalone)').matches) { return; }
    if (window.__almInstall) { installBtn.hidden = false; }
  }
  window.addEventListener('alm-installable', showInstall); showInstall();
  if (installBtn) {
    installBtn.addEventListener('click', function (e) {
      if (e.target.id === 'installDismiss') {
        installBtn.hidden = true; try { localStorage.setItem('alm:m:install-hide', '1'); } catch (x) {}
        return;
      }
      var d = window.__almInstall; if (!d) { return; }
      d.prompt(); d.userChoice.finally(function () { window.__almInstall = null; installBtn.hidden = true; });
    });
  }

  // ── تفعيل الإشعارات (v51) ────────────────────────────
  // بيظهر بس لو الجهاز يقدر يستقبل ولسه مش مفعّل، وبيختفي بعد التفعيل.
  var pushBtn = document.getElementById('pushBtn');
  var pushTxt = document.getElementById('pushBtnTxt');
  if (pushBtn && window.almPush) {
    var hidden = false;
    try { hidden = localStorage.getItem('alm:m:push-hide') === '1'; } catch (e) {}
    if (!hidden) {
      window.almPush.state().then(function (s) {
        if (s.canEnable) { pushBtn.hidden = false; }
      });
    }
    pushBtn.addEventListener('click', function (e) {
      if (e.target.id === 'pushDismiss') {
        pushBtn.hidden = true;
        try { localStorage.setItem('alm:m:push-hide', '1'); } catch (x) {}
        return;
      }
      pushTxt.textContent = 'لحظة…';
      window.almPush.enable().then(function (s) {
        if (s.subscribed) { pushBtn.hidden = true; }
        else { pushTxt.textContent = s.message; }
      });
    });
  }

  // ── بحث في الأذونات ──────────────────────────────────
  var search = document.getElementById('permitSearch');
  if (search) {
    search.addEventListener('input', function () {
      var q = search.value.trim().toLocaleLowerCase('ar'), shown = 0;
      document.querySelectorAll('.hm-stub[data-search]').forEach(function (card) {
        card.hidden = q !== '' && card.dataset.search.indexOf(q) === -1;
        if (!card.hidden) { shown++; }
      });
      document.querySelectorAll('.hm-group').forEach(function (head) {
        var any = false, node = head.nextElementSibling;
        while (node && node.classList.contains('hm-stub')) {
          if (!node.hidden) { any = true; break; }
          node = node.nextElementSibling;
        }
        head.hidden = !any;
      });
      document.getElementById('permitNoResults').hidden = shown !== 0;
    });
  }

  // ── إشعارات «جديد» (مقارنة محلية بلا هجرة) ───────────
  var SEEN_P = 'alm:m:seen:permits', SEEN_C = 'alm:m:seen:custody';
  var DEPOSITS = <?= js_json(array_map(fn($d) => ['at' => $d['at'], 'amount' => $d['amount']], $deposits)) ?>;
  var ms = function (v) { var t = Date.parse((v || '').replace(' ', 'T')); return isNaN(t) ? 0 : t; };
  var maxCardTime = 0;
  document.querySelectorAll('.hm-stub[data-created]').forEach(function (c) { maxCardTime = Math.max(maxCardTime, ms(c.dataset.created)); });
  var maxDepTime = DEPOSITS.reduce(function (a, d) { return Math.max(a, ms(d.at)); }, 0);

  var seenP = parseInt(localStorage.getItem(SEEN_P) || '0', 10);
  var seenC = parseInt(localStorage.getItem(SEEN_C) || '0', 10);
  // أول مرة خالص: نضبط الأساس على دلوقتي فما نزعّقش بكل الأذونات القديمة
  var firstRun = !seenP && !seenC;
  if (firstRun) {
    try { localStorage.setItem(SEEN_P, String(maxCardTime || Date.now())); localStorage.setItem(SEEN_C, String(maxDepTime || Date.now())); } catch (e) {}
    seenP = maxCardTime; seenC = maxDepTime;
  }

  function computeFresh() {
    var newP = 0;
    document.querySelectorAll('.hm-stub[data-created]').forEach(function (c) {
      var isNew = ms(c.dataset.created) > seenP;
      var tag = c.querySelector('.hm-new-tag');
      if (tag) { tag.hidden = !isNew; }
      c.classList.toggle('is-new', isNew);
      if (isNew) { newP++; }
    });
    var newDeps = DEPOSITS.filter(function (d) { return ms(d.at) > seenC; });
    var parts = [];
    if (newP) { parts.push('🆕 نزل لك ' + newP + (newP === 1 ? ' إذن جديد' : ' أذونات جديدة')); }
    if (newDeps.length) {
      var sum = newDeps.reduce(function (a, d) { return a + (+d.amount || 0); }, 0);
      parts.push('💰 نزلت عهدة جديدة ' + sum.toLocaleString('en-US') + ' ج');
    }
    var banner = document.getElementById('freshBanner');
    if (parts.length) {
      document.getElementById('freshText').textContent = parts.join(' · ');
      banner.hidden = false;
      notify(parts.join('\n'));
    } else {
      banner.hidden = true;
    }
    return newP + newDeps.length;
  }

  // تنبيه نظام (لو المندوب سمح) — بيشتغل والتطبيق مفتوح
  var lastNotified = '';
  function notify(body) {
    if (!('Notification' in window) || Notification.permission !== 'granted') { return; }
    if (body === lastNotified) { return; }        // ما نكررش نفس التنبيه
    lastNotified = body;
    try { new Notification('المتوكل', { body: body, icon: '<?= url('assets/img/logo.png') ?>', tag: 'alm-fresh' }); } catch (e) {}
  }

  computeFresh();

  document.getElementById('freshOk').addEventListener('click', function () {
    seenP = Math.max(seenP, maxCardTime, Date.now());
    seenC = Math.max(seenC, maxDepTime, Date.now());
    try { localStorage.setItem(SEEN_P, String(seenP)); localStorage.setItem(SEEN_C, String(seenC)); } catch (e) {}
    computeFresh();
    // أول ضغطة «تمام» فرصة كويسة نطلب إذن التنبيهات
    if ('Notification' in window && Notification.permission === 'default') {
      Notification.requestPermission().catch(function () {});
    }
    vibe(10);
  });

  // بوول خفيف كل 60ث والتطبيق مفتوح: يجيب أحدث الأختام فيحدّث الجديد حيًّا
  function poll() {
    if (document.hidden) { return; }
    fetch('<?= url('m/ping') ?>', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d) { return; }
        var pAt = ms(d.permit_at), cAt = ms(d.deposit_at);
        if (pAt > maxCardTime) { maxCardTime = pAt; }
        if (cAt > seenC) {
          // عهدة جديدة نزلت والتطبيق مفتوح — نضيفها كـ«جديدة» دلوقتي
          DEPOSITS.push({ at: d.deposit_at, amount: 0 });
          maxDepTime = Math.max(maxDepTime, cAt);
        }
        if (pAt > seenP || cAt > seenC) { computeFresh(); }
      }).catch(function () {});
  }
  setInterval(poll, 60000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { poll(); } });

  // ── سحب للتحديث ──────────────────────────────────────
  var ptr = document.getElementById('ptr'), startY = 0, pulling = false, THRESH = 80;
  window.addEventListener('touchstart', function (e) {
    if (window.scrollY <= 0 && e.touches.length === 1) { startY = e.touches[0].clientY; pulling = true; }
  }, { passive: true });
  window.addEventListener('touchmove', function (e) {
    if (!pulling) { return; }
    var dy = e.touches[0].clientY - startY;
    if (dy > 0 && window.scrollY <= 0) {
      var pull = Math.min(dy, 120);
      ptr.style.height = pull + 'px';
      ptr.classList.toggle('ready', pull >= THRESH);
    }
  }, { passive: true });
  window.addEventListener('touchend', function () {
    if (!pulling) { return; }
    pulling = false;
    var ready = ptr.classList.contains('ready');
    if (ready) { ptr.classList.add('loading'); vibe(14); location.reload(); }
    else { ptr.style.height = '0px'; }
    ptr.classList.remove('ready');
  });

  // ── اهتزاز خفيف عند فتح فورم التسجيل ──────────────────
  document.querySelectorAll('.hm-stub-go, .hm-actions a').forEach(function (a) {
    a.addEventListener('click', function () { vibe(8); });
  });

  // ── مسح مسودات عند الخروج ────────────────────────────
  document.getElementById('logoutForm').addEventListener('submit', function () {
    try {
      Object.keys(localStorage).forEach(function (key) {
        if (key.indexOf('alm:m:draft:') === 0) { localStorage.removeItem(key); }
      });
    } catch (e) {}
  });
})();
</script>

</body>
</html>
