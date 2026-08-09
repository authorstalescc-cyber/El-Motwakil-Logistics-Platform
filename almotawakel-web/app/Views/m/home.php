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

$num = fn($v) => number_format((float) $v, 0);

/**
 * ختم الميناء — علامة بتترسم في ركن الكارت.
 *
 * ⚠️ **رسمة بالكود لا صورة** (قرار المالك بعد المفاضلة): صفر كيلوبايت، حادّة
 *    على أي شاشة، وبتاخد لون السمة تلقائيًّا. صور الموانئ كانت هتخلّي ٧٨٪ من
 *    الكروت بنفس الصورة (٢٩ إذن من ٣٧ على نفس الميناء) وتزوّد ~٩٠٠ك على موبايل
 *    المندوب في الميدان.
 *
 * ⚠️ المطابقة بـ`Maintenance::normkey` (بتشيل التشكيل وتوحّد الألف والتاء
 *    المربوطة) — «ميناء أبوقير» و«ميناء ابو قير» لازم يدّوا نفس الختم.
 */
$portMark = function (string $port): string {
    $k = \App\Models\Maintenance::normkey($port);
    if ($k === '') { return ''; }
    foreach ([
        'اسكندر'  => 'lighthouse',   // المنارة — علامة الإسكندرية
        'دخيله'   => 'crane',
        'ابوقير'  => 'anchor',
        'ابو قير' => 'anchor',
        'ادبيه'   => 'containers',
        'سخنه'    => 'ship',
        'شرق'     => 'containers',
        'غرب'     => 'ship',
        'بورسعيد' => 'anchor',
        'دمياط'   => 'crane',
    ] as $needle => $mark) {
        if (str_contains($k, $needle)) { return $mark; }
    }
    return 'anchor';                  // ميناء معروف بلا علامة مخصّصة
};

/** الإذن فيه حاويات مبرّدة؟ — `normkey` بتشيل الشدّة فـ«مبرّد» تطابق «مبرد». */
$isReefer = function (array $p): bool {
    $hay = \App\Models\Maintenance::normkey(
        implode(' ', $p['container_parts'] ?? []) . ' ' . (string) ($p['container_type'] ?? ''));
    return str_contains($hay, 'مبرد');
};

/** كارت الإذن — كعب إذن ورقي: تعريف فوق، إحصائيات، تفاصيل مطويّة، إجراء تحت. */
$permitCard = function (array $p) use ($num, $portMark, $isReefer) {
    /* الميناء والتوكيل طلعوا من القايمة المطويّة: الميناء بقى سطر جنب ختمه،
       والتوكيل بقى بارزًا تحت اسم الشركة (طلب المالك). */
    $details = array_filter([
        'الملاحية' => $p['shipping_co'],
        'الوجهة'   => $p['destination'],
    ], fn($v) => trim((string) $v) !== '');
    $agency = trim((string) ($p['agency'] ?? ''));
    $port   = trim((string) ($p['port'] ?? ''));
    $cold   = $isReefer($p);
    /* ⚠️ المبرّد **بيغلب** ختم الميناء: التبريد شغل تشغيلي مختلف (وقت وكهربا
       وعطل)، والميناء بيفضل مكتوبًا بالاسم تحت فمفيش معلومة بتضيع. */
    $stamp  = $cold ? 'snowflake' : ($port !== '' ? $portMark($port) : '');
    $drivers = $p['drivers'] ?? [];
    $bills   = (int) ($p['bills'] ?? 0);
    $cars    = (int) ($p['cars'] ?? 0);
    $movs    = count($p['movements']);
    ?>
    <article class="hm-stub <?= $p['type'] === 'warid' ? 'is-warid' : 'is-sader' ?><?= !empty($p['pinned']) ? ' is-pinned' : '' ?><?= !empty($p['track']['finished']) ? ' is-done' : '' ?><?= $cold ? ' is-cold' : '' ?>"
             data-pid="<?= (int) $p['id'] ?>"
             data-type="<?= e($p['type']) ?>"
             data-port="<?= e(mb_strtolower((string) ($p['port'] ?? ''))) ?>"
             data-pinned="<?= !empty($p['pinned']) ? '1' : '0' ?>"
             data-worked="<?= !empty($p['worked']) ? '1' : '0' ?>"
             data-search="<?= e(mb_strtolower($p['no'] . ' ' . $p['company'] . ' ' . $p['client'] . ' ' . $p['destination'] . ' ' . implode(' ', $drivers))) ?>">
      <span class="hm-new-tag" hidden><?= icon('sparkles', 12) ?> جديد</span>
      <?php /* ختم الركن — زخرفي بحت فـ`aria-hidden`، وبيتحط **قبل** الجسم
               عشان يفضل وراه في ترتيب الطبقات. */ ?>
      <?php if ($stamp !== ''): ?>
        <span class="hm-stamp" aria-hidden="true"><svg viewBox="0 0 24 24"><use href="#stamp-<?= e($stamp) ?>"/></svg></span>
      <?php endif; ?>
      <div class="hm-stub-body">
        <?php /* ⚠️ الكارت كله بقى رابط لصفحة التفاصيل (طلب المالك: «الضغط على
                 الإذن ما بيعملش حاجة»). الرابط **بيغطّي الجسم كله** بـ`inset:0`
                 وزرار التثبيت مرفوع فوقه بـz-index — فورم جوّه رابط ماركب باطل،
                 والتغطية دي هي البديل المعتمد. */ ?>
        <a class="hm-stub-open" href="<?= url('m/permit/' . $p['id'] . '/info') ?>"
           aria-label="تفاصيل الإذن <?= e($p['no']) ?>"></a>
        <div class="hm-stub-head">
          <h3><?= e($p['company'] ?: $p['client'] ?: 'بدون شركة') ?></h3>
          <?php /* ⚠️ رقم الإذن **جوّه شريحة النوع** لا في سطر لوحده (بلاغ المالك):
                   السطر المستقل كان بياخد ارتفاعًا كامل من كارت طويل أصلاً،
                   وهنا بيبقى بارز أكتر لأنه على خلفية ملوّنة وبخط تقيل. */ ?>
          <span class="hm-kind"><?= e($p['type_label']) ?> <b><bdi dir="ltr"><?= e($p['no']) ?></bdi></b></span>
          <?php /* التثبيت (v63) — تفضيل عرض بحت: بيرفع الإذن فوق القايمة وبس.
                   فورم عادي بلا جافاسكريبت عشان يشتغل حتى لو السكربت وقع. */ ?>
          <form method="post" action="<?= url('m/permit/' . $p['id'] . '/pin') ?>" class="hm-pin-f">
            <?= csrfField() ?>
            <button type="submit" class="hm-pin<?= !empty($p['pinned']) ? ' is-on' : '' ?>"
                    aria-pressed="<?= !empty($p['pinned']) ? 'true' : 'false' ?>"
                    aria-label="<?= !empty($p['pinned']) ? 'شيل التثبيت' : 'ثبّت الإذن فوق' ?>"
                    title="<?= !empty($p['pinned']) ? 'شيل التثبيت' : 'ثبّت فوق' ?>"><?= icon('star', 15) ?></button>
          </form>
        </div>

        <?php /* التوكيل الملاحي — طلب المالك يبقى بارزًا. كان مدفونًا في القايمة
                 المطويّة رغم إنه أول حاجة المندوب بيسأل عنها في الميناء. */ ?>
        <?php if ($agency !== ''): ?>
          <div class="hm-agency">
            <span class="hm-agency-ic"><?= icon('building', 15) ?></span>
            <span class="hm-agency-txt">
              <small>التوكيل الملاحي</small>
              <b><?= e($agency) ?></b>
            </span>
          </div>
        <?php endif; ?>

        <?php if ($port !== '' || $cold): ?>
          <div class="hm-portline">
            <?php if ($port !== ''): ?>
              <span class="hm-port"><?= icon('anchor', 13) ?> <?= e($port) ?></span>
            <?php endif; ?>
            <?php if ($cold): ?><span class="hm-cold"><?= icon('snowflake', 13) ?> مبرّد</span><?php endif; ?>
          </div>
        <?php endif; ?>

        <?php /* ⚠️ **شكل الحاوية** بدل الشريحة النصّية (طلب المالك): الرسمة
                 بتوصّل المقاس والنوع أسرع من الكلام — العشرين أقصر من الأربعين،
                 والمبرّد ليه وحدة تبريد، والتانك أسطوانة. والعدد×المقاس بيتكتب
                 **جوّه** الشكل.
                 ⚠️ الوصف اللي مش حاوية معروفة (زي «١٠ طر د») بيرجع **null**
                 فبيتعرض شريحة نصّية زي الأول — شكل غلط بيتقري كمعلومة غلط. */ ?>
        <?php if ($p['container_parts']): ?>
          <div class="hm-chips">
            <?php foreach ($p['container_parts'] as $part): ?>
              <?php $art = container_art((string) $part); ?>
              <?php if ($art !== null): ?>
                <span class="hm-boxwrap">
                  <span class="hm-box is-<?= e($art['kind']) ?><?= $art['size'] ? ' sz-' . e($art['size']) : ' sz-x' ?>">
                    <svg class="hm-box-art" viewBox="0 0 64 32" preserveAspectRatio="none"
                         aria-hidden="true"><use href="#box-<?= e($art['kind']) ?>"/></svg>
                    <?php /* bdi dir=ltr إلزامي: من غيرها «2×40» بتتعرض «40×2» */ ?>
                    <b class="hm-box-lbl"><bdi dir="ltr"><?= e($art['label']) ?></bdi></b>
                  </span>
                </span>
                <?php /* ⚠️ المبرّد **مالوش كلمة جنبه** — الكارت أصلاً بيعرض وسم
                         «مبرّد» في سطر الميناء والرسمة زرقا بوحدة تبريد، فكتابتها
                         هنا كمان بتخلّي نفس الخبر مرتين في كارت واحد. */ ?>
                <?php if ($art['sub'] !== '' && $art['kind'] !== 'reefer'): ?>
                  <span class="hm-box-sub"><?= e($art['sub']) ?></span>
                <?php endif; ?>
              <?php else: ?>
                <span class="hm-chip"><?= icon('package', 14) ?> <bdi dir="ltr"><?= e($part) ?></bdi></span>
              <?php endif; ?>
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

      </div>

      <?php /* رحلة الشحنة (v62) — الحالة الحالية + رابط التحديث من الميدان.
               الشارة بتظهر بس لو فيه تحديث فعلاً، والرابط بيفضل دايمًا عشان
               يقدر يبدأ الرحلة من هنا. */ ?>
      <a class="hm-stub-track<?= !empty($p['track']['finished']) ? ' is-done' : '' ?>"
         href="<?= url('m/permit/' . $p['id'] . '/track') ?>">
        <span class="ic"><?= icon($p['track']['icon'] ?? 'map-pin', 14) ?></span>
        <?php if (!empty($p['track'])): ?>
          <b><?= e($p['track']['label']) ?></b>
          <span class="hm-stub-track-m"><?= e($p['track']['ago']) ?></span>
        <?php else: ?>
          <b>حدّث حالة الرحلة</b>
          <span class="hm-stub-track-m">لسه ما بدأتش</span>
        <?php endif; ?>
      </a>

      <?php /* مستندات الأطراف (v56) — بتظهر بس لو فيه مستندات فعلاً، مفيش رابط ميت */ ?>
      <?php if (!empty($p['has_docs'])): ?>
        <a class="hm-stub-docs" href="<?= url('m/permit/' . $p['id'] . '/docs') ?>">
          <?= icon('folder', 14) ?> مستندات الشركة (بطاقة ضريبية · سجل تجاري)
        </a>
      <?php endif; ?>

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
    <?php /* جرس المندوب (المرحلة ٦ من docs/15) — العدّاد بيتملّى من `/m/ping`
             اللي بيقراه من `Notify::unreadCount` نفسها اللي بيقرا منها جرس
             المكتب، فمستحيل الرقمين يختلفوا. */ ?>
    <a class="nt-bell" href="<?= url('m/notifications') ?>" id="repBell" aria-label="الإشعارات">
      <?= icon('bell', 14) ?><span class="nt-dot" id="repBellDot" hidden></span>
    </a>
    <form method="post" action="<?= url('m/logout') ?>" id="logoutForm">
      <?= csrfField() ?>
      <button type="submit" aria-label="خروج">خروج</button>
    </form>
  </header>

  <!-- زرار تثبيت التطبيق (بيظهر بالـJS لما المتصفح يسمح) -->
  <button type="button" class="hm-install" id="installBtn" hidden>
    <span class="ic"><?= icon('download', 15) ?></span>
    <span>ثبّت التطبيق على شاشتك — يفتح أسرع وزي أبليكيشن</span>
    <span class="x" id="installDismiss" role="button" aria-label="إخفاء"><?= icon('x', 14) ?></span>
  </button>

  <!-- تفعيل الإشعارات (v51) — بيظهر بس لو الجهاز يقدر ولسه مش مفعّلها -->
  <button type="button" class="hm-install" id="pushBtn" hidden>
    <span class="ic"><?= icon('bell', 14) ?></span>
    <span id="pushBtnTxt">فعّل الإشعارات — عشان يوصلك قرار بنودك فورًا</span>
    <span class="x" id="pushDismiss" role="button" aria-label="إخفاء"><?= icon('x', 14) ?></span>
  </button>

  <!-- عهدة يوم العمل: الرقم الوحيد اللي بيسحب منه؛ الباقي جوّه «التفاصيل» -->
  <?php /* ⚠️ العنوان لازم يقول **اسم اليوم** لو مش النهاردة. قبل الإصلاح كان
           بيقول «عهدة اليوم» على طول، فالمندوب اللي يوم عمله لسه على أمس كان
           بيقرا رقم يوم فات وكأنه اللي معاه دلوقتي. */ ?>
  <section class="hm-balance <?= $owed ? 'is-owed' : '' ?>">
    <div class="hm-balance-head">
      <span><?php
        $wdWhen = $wdToday ? 'اليوم' : 'يوم ' . dfmt($workDate);
        echo e($owed ? 'صرفت أكتر من عهدة ' . $wdWhen : 'فاضل من عهدة ' . $wdWhen);
      ?></span>
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
        <div><dt>نزل لك <?= e($wdWhen) ?></dt><dd><?= $num($pocket['deposits']) ?></dd></div>
        <div><dt>صرفت <?= e($wdWhen) ?></dt><dd><?= $num($pocket['spent']) ?></dd></div>
        <?php if ($pocket['pending'] > 0): ?>
          <div><dt>مستني مراجعة</dt><dd><?= $num($pocket['pending']) ?></dd></div>
        <?php endif; ?>
        <?php if (($pocket['transfer_in'] ?? 0) > 0): ?>
          <div><dt>وصلك من زميل</dt><dd><?= $num($pocket['transfer_in']) ?></dd></div>
        <?php endif; ?>
        <?php if (($pocket['transfer_out'] ?? 0) > 0): ?>
          <div><dt>حوّلت لزميل</dt><dd><?= $num($pocket['transfer_out']) ?></dd></div>
        <?php endif; ?>
        <?php /* سالب = المكتب دفع لك (تصفية رصيد سالب) */ ?>
        <?php if ($pocket['returned'] > 0.005): ?>
          <div><dt>رجّعت</dt><dd><?= $num($pocket['returned']) ?></dd></div>
        <?php elseif ($pocket['returned'] < -0.005): ?>
          <div><dt>المكتب دفعلك</dt><dd><?= $num(abs($pocket['returned'])) ?></dd></div>
        <?php endif; ?>
      </dl>
    </div>
  </section>

  <?php /* عهدة يوم — اليوم اللي كل البنود بتتسجّل عليه وبتتخصم من عهدته */ ?>
  <a class="hm-wd <?= $wdLocked ? 'is-locked' : ($wdToday ? '' : 'is-past') ?>" href="<?= url('m/work-date') ?>">
    <span class="hm-wd-ic"><?= $wdLocked ? icon('lock', 16) : icon('calendar', 16) ?></span>
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
  <?php /* ⚠️ لوح «انت شغّال على يوم مش النهاردة» اتشال بقرار المالك (2026-08-02).
           الحماية من الباج اللي اتعمل عشانه **باقية بدونه**: عنوان الرصيد بيسمّي
           اليوم صراحةً («فاضل من عهدة يوم كذا»)، وشريحة التاريخ بتاخد `is-past`
           وفيها رابط «غيّر». ما تشيلش الاتنين دول. */ ?>

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
    <span class="hm-fresh-ic"><?= icon('bell', 14) ?></span>
    <span class="hm-fresh-txt" id="freshText"></span>
    <button type="button" class="hm-fresh-ok" id="freshOk">تمام</button>
  </div>

  <?php /* إجراءات مش على إذن — صف واحد مضغوط عشان الأذونات تفضل هي البطل. */ ?>
  <nav class="hm-actions" aria-label="إجراءات سريعة">
    <a href="<?= url('m/misc') ?>"><span class="ic"><?= icon('anchor', 14) ?></span>نثرية</a>
    <?php /* ⚠️ «بوليصة جديدة» **اتنقلت جوّه الإذن** (طلب المالك 2026-08-03):
             البوليصة لازمها إذن، وفتحها من هنا كانت بتبدأ بسؤال «أنهي إذن؟»
             على قايمة ٣٧ إذن. المسار `/m/bill/new` زي ما هو والفورم لسه
             بيقبل بلا إذن مختار — اللي اتغيّر مكان الدخول. */ ?>
    <?php /* المرحلة ٣.٣ (docs/16) — البوليصة موجودة والمكتب سجّلها، والمندوب هو
             اللي سلّم سائقها عهدته. بلا الزرار ده العهدة تفضل متخصومة من خزنة
             المكتب وهو اللي دفعها. */ ?>
    <?php if (\App\Models\RepBillCustody::ready()): ?>
      <a href="<?= url('m/bill/custody') ?>"><span class="ic"><?= icon('coins', 14) ?></span>سلّمت سائق عهدة</a>
    <?php endif; ?>
    <?php if (\App\Models\RepTransfer::available()): ?>
      <a href="<?= url('m/transfer') ?>"><span class="ic"><?= icon('shuffle', 14) ?></span>حوّل لمندوب</a>
    <?php endif; ?>
    <?php /* تصفية العهدة مع المكتب — بتقفل الحساب كله ويرجع صفر */ ?>
    <a href="<?= url('m/settle') ?>"><span class="ic"><?= icon('handshake', 15) ?></span>صفّي العهدة</a>
    <?php if (!empty($canAddPermit)): ?>
      <a href="<?= url('m/permit/new') ?>"><span class="ic"><?= icon('file-text', 14) ?></span>إذن جديد</a>
    <?php endif; ?>
    <?php /* v47 — للمشرف بس: متابعة باقي المناديب (عرض فقط) */ ?>
    <?php if (!empty($canWatchTeam)): ?>
      <a href="<?= url('m/team') ?>"><span class="ic"><?= icon('search', 14) ?></span>المناديب</a>
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
      <?php if ($permitCount): ?><span class="hm-count"><?= (int) $permitCount ?></span><?php endif; ?>
    </div>

    <?php if (count($permits) > 5): ?>
      <div class="hm-search">
        <input type="search" id="permitSearch" placeholder="بحث"
               autocomplete="off" aria-label="بحث في الأذونات">
      </div>

      <?php
      /* فلاتر القايمة — **كلها في المتصفح** زي البحث بالظبط، فمفيش أي طلب
         للخادم ولا إعادة تحميل. الشرايح بتتولّد من المعروض فعلاً: ميناء
         مالوش إذن مفتوح ما يظهرش شريحة فاضية. */
      $fPorts = [];
      $fPinned = $fWorked = $fSader = $fWarid = 0;
      foreach ($permits as $fp) {
          if (!empty($fp['pinned'])) { $fPinned++; }
          if (!empty($fp['worked'])) { $fWorked++; }
          ($fp['type'] === 'warid') ? $fWarid++ : $fSader++;
          $pt = trim((string) ($fp['port'] ?? ''));
          if ($pt !== '') { $fPorts[$pt] = ($fPorts[$pt] ?? 0) + 1; }
      }
      arsort($fPorts);
      $fPorts = array_slice($fPorts, 0, 6, true);
      ?>
      <div class="hm-fchips" id="permitFilters" aria-label="فلترة الأذونات">
        <button type="button" class="is-on" data-f="all">الكل <i><?= count($permits) ?></i></button>
        <?php if ($fPinned): ?>
          <button type="button" data-f="pinned"><?= icon('star', 14) ?> مثبَّت <i><?= $fPinned ?></i></button>
        <?php endif; ?>
        <?php if ($fWorked): ?>
          <button type="button" data-f="worked">شغّال عليه <i><?= $fWorked ?></i></button>
        <?php endif; ?>
        <?php if ($fSader && $fWarid): ?>
          <button type="button" data-f="sader">صادر <i><?= $fSader ?></i></button>
          <button type="button" data-f="warid">وارد <i><?= $fWarid ?></i></button>
        <?php endif; ?>
        <?php foreach ($fPorts as $pName => $pCnt): ?>
          <button type="button" data-f="port" data-v="<?= e(mb_strtolower($pName)) ?>">
            <?= icon('anchor', 14) ?> <?= e($pName) ?> <i><?= $pCnt ?></i></button>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!$permits): ?>
      <div class="hm-empty">
        <span class="ic"><?= icon('clipboard', 14) ?></span>
        <b>مفيش أذونات مفتوحة</b>
        <p><?= !empty($canAddPermit)
              ? 'لو فتحت إذن جديد سجّله من «إذن جديد» فوق.'
              : 'المكتب هو اللي بيفتح الأذونات.' ?></p>
      </div>
    <?php else: ?>
      <?php /* ⚠️ قايمة واحدة — التقسيم لـ«منزّل لك» و«أذونات تانية» اتشال
               (2026-07-28، قرار المالك). التنزيل ما بقاش بيفرق في أي حاجة
               من v43، فالتقسيم كان بيوهم المندوب إن فيه أذونات «بتاعته»
               وأذونات مش بتاعته وهو بيشتغل على أي إذن مفتوح. */ ?>
      <h3 class="hm-group">كل الأذونات المفتوحة <span><?= count($permits) ?></span></h3>
      <?php foreach ($permits as $p) { $permitCard($p); } ?>
    <?php endif; ?>

    <div class="hm-empty" id="permitNoResults" hidden>
      <span class="ic"><?= icon('search', 14) ?></span><b>مفيش إذن مطابق</b>
    </div>

  <?php else: ?>
    <div class="hm-sheet-head">
      <h2>بنود يوم <?= e(dfmt($workDate)) ?></h2>
      <span class="hm-sheet-sub">اللي دخّلته على عهدة اليوم ده بس</span>
    </div>

    <?php /* ⚠️ عرض للقراءة بس — **ما بيغيّرش يوم العمل**. قبل كده كان لازم
             المندوب يغيّر يوم العمل كله عشان يبصّ على بند إمبارح، وده بيغيّر
             اليوم اللي هيكتب عليه كمان فيسجّل بند النهاردة على يوم فات. */ ?>
    <a class="hm-otherday" href="<?= url('m/entries?day=' . e(date('Y-m-d', strtotime($workDate . ' -1 day')))) ?>">
      <?= icon('clock', 14) ?> شوف بنود يوم تاني <span>بلا ما تغيّر يوم شغلك</span>
    </a>

    <div class="hm-filters" aria-label="فلترة البنود">
      <?php foreach (['' => 'الكل', 'pending' => 'مستني', 'approved' => 'اتقبل', 'rejected' => 'اترفض'] as $k => $label): ?>
        <a href="<?= url('m?tab=entries' . ($k !== '' ? '&status=' . $k : '')) ?>"
           class="<?= $status === $k ? 'is-on' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>

    <?php if (!$submissions): ?>
      <div class="hm-empty"><span class="ic"><?= icon('receipt', 14) ?></span><b>مفيش بنود في يوم <?= e(dfmt($workDate)) ?></b>
        <p>اختار إذن من «أذوناتي» وسجّل أول بند، أو <a href="<?= url('m/work-date') ?>">غيّر اليوم</a> لو بتدوّر على بند يوم تاني.</p></div>
    <?php else: foreach ($submissions as $s): ?>
      <?php $isMisc = \App\Models\RepPortal::isMisc((string) $s['kind']); ?>
      <article class="hm-slip is-<?= e($s['status']) ?>">
        <span class="hm-slip-ic" aria-hidden="true"><?= $isMisc ? icon('tag', 18) : ($s['kind'] === 'receipt' ? icon('receipt', 18) : icon('banknote', 18)) ?></span>
        <div class="hm-slip-mid">
          <b><?= e($s['note']) ?></b>
          <div class="hm-slip-meta">
            <span><?= e(dfmt($s['entry_date'])) ?></span>
            <?php /* النثرية مالهاش إذن — من غير الفحص ده كانت هتعرض «#0» (v39) */ ?>
            <span>· <?= $isMisc ? 'نثرية' : e($s['permit_no'] ?: '#' . $s['permit_id']) ?></span>
            <?php if ((int) $s['qty'] > 1): ?><span>· <?= (int) $s['qty'] ?>×<?= $num($s['unit_amount']) ?></span><?php endif; ?>
            <?php if (!empty($s['is_visa'])): ?><span>· <?= icon('credit-card', 14) ?></span><?php endif; ?>
            <?php if ($s['photo']): ?><span>· <?= icon('search', 14) ?></span><?php endif; ?>
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
    <?php /* ⚠️ من العدّاد الرخيص لا من `count($permits)` — القايمة مش محمّلة
             أصلاً على تبويب «بنودي»، فالشارة كانت هتقول صفر غلط. */ ?>
    <?php if ($permitCount): ?><i class="hm-badge"><?= (int) $permitCount ?></i><?php endif; ?>
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

<?php /* ⚠️ مكتبة الأختام — كل شكل **مرّة واحدة** في الصفحة والكروت بتشاور عليه
         بـ`<use>`. رسم الـSVG كامل جوّه كل كارت كان بيزوّد ٥٢ كيلوبايت على ٣٧
         كارت، وده بيناقض سبب اختيارنا للرسم بدل الصور من الأساس.
         بترسم أشكال الصفحة الحالية بس (مفيش رمز بلا مستخدِم). */ ?>
<?php
$stampsUsed = [];
foreach (($permits ?? []) as $sp) {
    $c = \App\Models\Maintenance::normkey(
        implode(' ', $sp['container_parts'] ?? []) . ' ' . (string) ($sp['container_type'] ?? ''));
    if (str_contains($c, 'مبرد')) { $stampsUsed['snowflake'] = 1; continue; }
    $pt = trim((string) ($sp['port'] ?? ''));
    if ($pt !== '') { $stampsUsed[$portMark($pt)] = 1; }
}
?>
<?php
/* أشكال الحاويات المستعملة في الصفحة — نفس مبدأ الأختام: الشكل مرّة واحدة. */
$boxesUsed = [];
foreach (($permits ?? []) as $bp) {
    foreach (($bp['container_parts'] ?? []) as $bpart) {
        $ba = container_art((string) $bpart);
        if ($ba !== null) { $boxesUsed[$ba['kind']] = 1; }
    }
}
?>
<?php if ($stampsUsed || $boxesUsed): ?>
<svg class="hm-stamp-lib" aria-hidden="true" focusable="false"><?php
foreach (array_keys($stampsUsed) as $name) {
    // بنقصّ جوف أيقونة `icon()` — المصدر الوحيد للأشكال يفضل خريطة `icon()`
    $svg = icon($name, 24);
    $inner = preg_replace('~^<svg[^>]*>|</svg>$~', '', $svg);
    echo '<symbol id="stamp-' . e($name) . '" viewBox="0 0 24 24">' . $inner . '</symbol>';
}
foreach (array_keys($boxesUsed) as $bk) {
    echo '<symbol id="box-' . e($bk) . '" viewBox="0 0 64 32" preserveAspectRatio="none">'
       . container_box_svg($bk) . '</symbol>';
}
?></svg>
<?php endif; ?>

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

  // ── بحث وفلترة الأذونات ──────────────────────────────
  // ⚠️ **مُطبِّق واحد للاتنين**: البحث والفلتر بيشتغلوا على نفس القايمة، فلو
  //    كل واحد أخفى/أظهر لوحده يبقى التاني بيلغي شغله (تكتب بحث فيرجّع كل
  //    اللي الفلتر خبّاه). `applyPermitFilters` هي المصدر الوحيد للإخفاء.
  var search = document.getElementById('permitSearch');
  var fbar = document.getElementById('permitFilters');
  var curF = 'all', curV = '';

  function applyPermitFilters() {
    var q = search ? search.value.trim().toLocaleLowerCase('ar') : '', shown = 0;
    document.querySelectorAll('.hm-stub[data-search]').forEach(function (card) {
      var d = card.dataset;
      var okQ = q === '' || d.search.indexOf(q) !== -1;
      var okF = true;
      if (curF === 'pinned')      { okF = d.pinned === '1'; }
      else if (curF === 'worked') { okF = d.worked === '1'; }
      else if (curF === 'sader')  { okF = d.type === 'sader'; }
      else if (curF === 'warid')  { okF = d.type === 'warid'; }
      else if (curF === 'port')   { okF = d.port === curV; }
      card.hidden = !(okQ && okF);
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
  }

  if (search) { search.addEventListener('input', applyPermitFilters); }
  if (fbar) {
    fbar.addEventListener('click', function (e) {
      var btn = e.target.closest('button[data-f]');
      if (!btn) { return; }
      fbar.querySelectorAll('button').forEach(function (b) { b.classList.remove('is-on'); });
      btn.classList.add('is-on');
      curF = btn.dataset.f || 'all';
      curV = btn.dataset.v || '';
      vibe(8);
      applyPermitFilters();
    });
  }

  // ── «جديد» = إذن أول مرة يشوفه المندوب ───────────────
  /* ⚠️⚠️ الأساس بقى **هوية الإذن** لا وقت إنشائه.
     الطريقة القديمة كانت علامة زمنية واحدة (`seen = آخر created_at`) وما
     بتتقدّمش غير لما المندوب يدوس «تمام» — فطلعت مشكلتين حقيقيتين بلّغ عنهم
     المالك: (١) أي إذن اتفتح بعد العلامة يفضل «جديد» **للأبد**، فبيتجمّع عليه
     ١٠ و١٥ إذن كلهم مكتوب عليهم جديد؛ (٢) البانر والتنبيه بيرجعوا **كل مرة**
     يفتح فيها التطبيق لأن العلامة مااتحرّكتش.
     دلوقتي: بنخزّن **أرقام الأذونات اللي شافها**، واللي مش فيهم يبان «جديد»
     **المرة دي وبس** ثم يتعلّم متشاف فورًا. يعني حرفيًّا «أول مرة يشوفه». */
  var SEEN_IDS = 'alm:m:seen:permit-ids';
  var SEEN_C   = 'alm:m:seen:custody';
  var DEPOSITS = <?= js_json(array_map(fn($d) => ['at' => $d['at'], 'amount' => $d['amount']], $deposits)) ?>;
  var ms = function (v) { var t = Date.parse((v || '').replace(' ', 'T')); return isNaN(t) ? 0 : t; };

  function readSeenIds() {
    try { var v = JSON.parse(localStorage.getItem(SEEN_IDS) || 'null');
          return Array.isArray(v) ? v : null; } catch (e) { return null; }
  }
  function writeSeenIds(ids) {
    try { localStorage.setItem(SEEN_IDS, JSON.stringify(ids)); } catch (e) {}
  }

  var cards = [].slice.call(document.querySelectorAll('.hm-stub[data-pid]'));
  /* ⚠️ الكروت بترسم على تبويب الأذونات بس — لو مفيش كروت (تبويب «بنودي»)
     **ما نلمسش المخزَّن خالص**، وإلا كل الأذونات تبقى «جديدة» تاني. */
  if (cards.length) {
    var shownIds = cards.map(function (c) { return String(c.dataset.pid); });
    var seen = readSeenIds();
    if (seen !== null) {
      var known = {};
      seen.forEach(function (id) { known[String(id)] = 1; });
      cards.forEach(function (c) {
        var isNew = !known[String(c.dataset.pid)];
        var tag = c.querySelector('.hm-new-tag');
        if (tag) { tag.hidden = !isNew; }
        c.classList.toggle('is-new', isNew);
      });
    }
    /* بيتعلّم «متشاف» فورًا — سواء أول تشغيل (فما يزعّقش بكل الأذونات القديمة)
       أو بعد ما بان «جديد» المرة دي. وبنكتب **المعروض دلوقتي بس** فالمقفول
       بيتشال من المخزَّن لوحده وما يكبرش مع الوقت. */
    writeSeenIds(shownIds);
  }

  /* ── بانر العهدة الجديدة ───────────────────────────────
     ⚠️ **بقى للعهدة بس** — الأذونات بقى ليها شارة على الكارت نفسه، والبانر
        عنها كان بيتكرّر كل فتحة. والتنبيه المحلي (`new Notification`) اتشال
        خالص: نظام الإشعارات الحقيقي (v51/v52) بيبعت `permit.new` للمناديب
        أصلاً، فكان **خبرين لنفس الحاجة** من قناتين. */
  var seenC = parseInt(localStorage.getItem(SEEN_C) || '0', 10);
  var maxDepTime = DEPOSITS.reduce(function (a, d) { return Math.max(a, ms(d.at)); }, 0);
  if (!seenC) { seenC = maxDepTime; try { localStorage.setItem(SEEN_C, String(seenC)); } catch (e) {} }

  function computeFresh() {
    var newDeps = DEPOSITS.filter(function (d) { return ms(d.at) > seenC; });
    var banner = document.getElementById('freshBanner');
    if (newDeps.length) {
      var sum = newDeps.reduce(function (a, d) { return a + (+d.amount || 0); }, 0);
      document.getElementById('freshText').textContent =
        sum > 0 ? ('نزلت عهدة جديدة ' + sum.toLocaleString('en-US') + ' ج') : 'نزلت لك عهدة جديدة';
      banner.hidden = false;
    } else {
      banner.hidden = true;
    }
    return newDeps.length;
  }

  computeFresh();

  document.getElementById('freshOk').addEventListener('click', function () {
    seenC = Math.max(seenC, maxDepTime, Date.now());
    try { localStorage.setItem(SEEN_C, String(seenC)); } catch (e) {}
    computeFresh();
    vibe(10);
  });

  /* ── نداء واحد كل ٦٠ث بيغذّي كل حاجة ────────────────────────────────
     ⚠️ **كان نداءين** (`/m/ping` + `/notifications/ping`) كل واحد كل ٦٠ث =
        ٢٬٨٨٠ طلب في اليوم لكل مندوب على نت موبايله وبطاريته، والاتنين
        بيرجّعوا من نفس الجلسة. دلوقتي `/m/ping` بيرجّع العهدة **وعدّاد
        الجرس** (من `Notify::unreadCount` نفسها) **وحالة الصيانة** مع بعض.
        لو ضفت أي حالة حيّة جديدة للشاشة، ركّبها هنا — ما تفتحش نداءً تالتًا. */
  var bellDot = document.getElementById('repBellDot');

  function poll() {
    if (document.hidden) { return; }
    fetch('<?= url('m/ping') ?>', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d) { return; }

        /* ⚠️ الصيانة بدأت والتطبيق مفتوح في إيده → نوديه على شاشة الصيانة
           **فورًا**. من غير ده كان هيفضل يدوس «سجّل بند» ويترفض من غير ما
           يفهم إن النظام واقف عن قصد. */
        if (d.maintenance) { location.reload(); return; }

        // التحديث الإجباري بيركب على نفس النداء ده — صفر طلب زيادة
        if (window.AppUpdate && d.build) { window.AppUpdate.seen(d.build); }

        if (bellDot && typeof d.unread !== 'undefined') {
          var n = d.unread || 0;
          bellDot.hidden = n < 1;
          bellDot.textContent = n > 9 ? '+9' : String(n);
        }

        var cAt = ms(d.deposit_at);
        if (cAt > seenC) {
          DEPOSITS.push({ at: d.deposit_at, amount: 0 });
          maxDepTime = Math.max(maxDepTime, cAt);
          computeFresh();
        }
      }).catch(function () { /* الشبكة وقعت — الشاشة تفضل بآخر حالة معروفة */ });
  }
  poll();
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
