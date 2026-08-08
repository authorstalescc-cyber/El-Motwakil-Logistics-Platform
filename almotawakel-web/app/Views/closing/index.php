<?php
/**
 * التقفيل اليومي — شاشة مراجعة (v50).
 *
 * الفكرة كلها: كل بند في اليوم له **علامة ✓ واحدة**. المالك بيبص على البند
 * ويعلّمه، والإقفال بيتفتح لما كل بند نقدي يتعلّم. مفيش أرقام تتكتب تاني —
 * مطابقة v28 اليدوية (اكتب إجمالي كل عميل وطارد الفرق) اتشالت بالكامل.
 *
 * ⚠️ مصيدة تصادم مفاتيح render (نفس مصيدة `$data` الموثَّقة): ممنوع تسمّي أي
 *    مفتاح render باسم متغيّر بيتحسب هنا. المفاتيح المستعملة: review + مفاتيح
 *    الأرقام من `base()` فقط.
 */
$rv        = $review;
$groups    = $rv['groups'];
$t         = $rv['totals'];
$ready     = !empty($rv['available']);
$canReview = $ready && !$isLocked;
$complete  = !empty($rv['complete']);

$dateObject   = \DateTimeImmutable::createFromFormat('!Y-m-d', $date) ?: new \DateTimeImmutable(work_date());
$previousDate = $dateObject->modify('-1 day')->format('Y-m-d');
$nextDate     = $dateObject->modify('+1 day')->format('Y-m-d');
$today        = work_date();

$percent = (int) ($t['percent'] ?? 0);
?>

<div class="cl-page" id="clPage" data-date="<?= e($date) ?>">

  <!-- ═══════════ الترويسة: اليوم + حالته + التنقل ═══════════ -->
  <header class="cl-top">
    <div class="cl-top-id">
      <span class="cl-kicker">التقفيل اليومي</span>
      <h1>يوم <?= e(dfmt($date)) ?></h1>
      <span class="cl-chip <?= $isClosed ? 'closed' : 'open' ?>">
        <?= $isClosed ? '🔒 متقفل' : '● مفتوح' ?>
      </span>
    </div>

    <nav class="cl-dates" aria-label="اختيار يوم التقفيل">
      <a class="cl-arrow" href="<?= url('closing?date='.urlencode($previousDate)) ?>" title="اليوم السابق" aria-label="اليوم السابق">‹</a>
      <form method="get" action="<?= url('closing') ?>">
        <input id="closingDate" type="date" name="date" value="<?= e($date) ?>" aria-label="تاريخ التقفيل">
        <button class="btn sm" type="submit">عرض</button>
      </form>
      <a class="cl-arrow" href="<?= url('closing?date='.urlencode($nextDate)) ?>" title="اليوم التالي" aria-label="اليوم التالي">›</a>
      <a class="cl-todaybtn <?= $date === $today ? 'active' : '' ?>" href="<?= url('closing?date='.urlencode($today)) ?>">اليوم</a>
    </nav>

    <div class="cl-top-actions">
      <a class="btn ghost sm" href="<?= url('closing/print?date='.urlencode($date)) ?>" target="_blank"><?= icon('file-text', 14) ?> طباعة</a>
    </div>
  </header>

  <?php /* ═══════════ معادلة رصيد اليوم ═══════════
           ⚠️ **كل كارت زرار بيفتح تفاصيله** — قاعدة «ممنوع رقم أصمّ» (طلب المالك).
              النوافذ **جاهزة في الصفحة** لأن كل بياناتها محمّلة أصلاً في `$groups`
              و`$wallets`، فمفيش أي استعلام زيادة (نفس نمط `#topupsDrill`).
           ⚠️ **صفر جملة تفسيرية** (طلب المالك): الشرح مكانه النافذة لا الكارت.
              اللي فضل تحت الأرقام حالة وظيفية بس («مجمّد» على اليوم المتقفل).
           ⚠️ اسم «عهدة مضافة» بيتبع النموذج: مجمّع ⇒ الرقم بيشمل عهدة المناديب
              فبلا «للدرج»؛ غير مجمّع ⇒ الرقم الدرج لوحده فـ«للدرج» إلزامية. */ ?>
  <section class="cl-flow" aria-label="معادلة رصيد اليوم">
    <button type="button" class="cl-cell" data-drill="#drillOpening" data-drill-title="رصيد أول اليوم">
      <span>رصيد أول اليوم</span><b><?= money($prevBalance,false) ?></b>
    </button>
    <?php /* ⚠️ **الإضافات اتفصلت لخانتين** (طلب المالك 2026-08-01): «عهدة مضافة
             للدرج» كانت بتقول صفر في أغلب الأيام (الفلوس بقت تروح من البنك
             للمناديب على طول) والمالك مش شايف الرقم اللي بيتعامل معاه فعلاً.
             ⚠️ الاتنين **جزء من `topups`** فمجموعهم = الرقم اللي `Closing` بيحسبه
                — أي واحدة تتشال من غير التانية بتكسر المعادلة. */ ?>
    <?php if ((float) $totTopupsDrawer != 0.0 || (float) $totBankToReps == 0.0): ?>
      <i class="op plus">+</i>
      <button type="button" class="cl-cell in" data-drill="#topupsDrill" data-drill-title="عهدة مضافة — التفكيك">
        <span>عهدة مضافة للدرج</span><b><?= money($totTopupsDrawer,false) ?></b>
      </button>
    <?php endif; ?>
    <?php if ((float) $totBankToReps != 0.0): ?>
      <i class="op plus">+</i>
      <button type="button" class="cl-cell in" data-drill="#topupsDrill" data-drill-title="عهدة منزلة للمناديب">
        <span>عهدة منزلة للمناديب</span><b><?= money($totBankToReps,false) ?></b>
      </button>
    <?php endif; ?>
    <i class="op eq">=</i>
    <?php /* كارت «الإجمالي» (طلب المالك) — `$available` محسوبة في الكنترولر
             (`opening + topups`) فمفيش تعريف تاني للرقم. */ ?>
    <button type="button" class="cl-cell total" data-drill="#drillAvailable" data-drill-title="الإجمالي المتاح">
      <span>الإجمالي</span><b><?= money($available,false) ?></b>
    </button>
    <?php /* ⚠️ **خانة مستقلة للكاش اللي خرج من الدرج لجيب مندوب** (طلب المالك
             2026-08-01): كانت مدفونة جوّه «مصروفات نقدية»، فالمالك يشوف رقمًا
             كبيرًا ومايعرفش منه كام راح لمندوب وكام اتصرف فعلاً.
             ⚠️ بتظهر بس لو القاعدة بتخصمه من الدرج — في النموذج المجمّع ده تحويل
                داخلي مالوش أثر، وخانة بصفر في معادلة بتبقى تشتيت. */ ?>
    <?php if (\App\Models\RepCustody::newSafeRuleActive()): ?>
      <i class="op minus">−</i>
      <button type="button" class="cl-cell out" data-drill="#drillOfficeDep" data-drill-title="عهدة مسحوبة من الدرج">
        <span>عهدة مسحوبة من الدرج</span><b><?= money($totOfficeDep,false) ?></b>
      </button>
    <?php elseif ((float) $totDrawerToReps != 0.0): ?>
      <?php /* ⚠️⚠️ **علامة تحويل مش عملية حسابية** — في النموذج المجمّع الدرج
               وجيوب المناديب **الاتنين جوّه الرقم**، فالكاش اللي اتنقل بينهم
               ما بيغيّرش الإجمالي. طرحه هنا كان هيكسر المعادلة. المالك طلب
               يشوف السطر (2026-08-01) فبيتعرض بعلامة `⇄` ولون محايد عشان
               يبان إنه نقل مكان مش خروج فلوس. */ ?>
      <i class="op xfer" aria-hidden="true">⇄</i>
      <button type="button" class="cl-cell xfer" data-drill="#drillOfficeDep"
              data-drill-title="نزلت من الدرج للمناديب — تحويل داخلي">
        <span>نزلت من الدرج للمناديب</span><b><?= money($totDrawerToReps,false) ?></b>
        <small>تحويل داخلي</small>
      </button>
    <?php endif; ?>
    <i class="op minus">−</i>
    <button type="button" class="cl-cell out" data-drill="#drillExpenses" data-drill-title="مصروفات نقدية">
      <span>مصروفات نقدية</span><b><?= money($totExpense,false) ?></b>
    </button>
    <i class="op minus">−</i>
    <button type="button" class="cl-cell out" data-drill="#drillCustody" data-drill-title="عهد السائقين">
      <span>عهد السائقين</span><b><?= money($totCustodyExp,false) ?></b>
    </button>
    <i class="op eq">=</i>
    <button type="button" class="cl-cell result <?= $carried < 0 ? 'negative' : '' ?>"
            data-drill="#drillCarried" data-drill-title="<?= $isClosed ? 'المرحّل المثبّت' : 'المرحّل للغد' ?>">
      <span><?= $isClosed ? 'المرحّل المثبّت' : 'المرحّل للغد' ?></span>
      <b><?= money($carried,false) ?></b>
      <?php if ($isClosed): ?><small>مجمّد</small><?php endif; ?>
    </button>
  </section>

  <?php /* ═══════════ الكاش فين دلوقتي — الدرج والمناديب ═══════════
           ⚠️ **الرقمان تراكميان (دلوقتي) مش بتوع اليوم المعروض** — سؤال «الكاش
              فين؟» تراكمي بطبيعته (قاعدة v49). عشان الشاشة ما تكدبش، السطر
              اللي تحت بيتطبع **بس لو** المجموع بيساوي مرحّل اليوم فعلاً؛ غير
              كده الكروت بتقول «دلوقتي» ومفيش ادّعاء إنها بتاعة اليوم ده.
           ⚠️ **ولا رقم بيتحسب من جديد:** `Closing::walletSplit()` بتنادي
              `currentBalance()` و`RepCustody::balance()` نفسهم بتوع كل شاشة
              تانية، فمستحيل تخالف كارت المندوب.
           ⚠️ **السالب بيعدّي بلا `max(0,…)`** — معناه المكتب مدين للمندوب. */ ?>
  <?php $tiesToDay = abs((float) $wallets['total'] - (float) $carried) < 0.005; ?>
  <section class="cl-split" aria-label="الكاش برّه البنك دلوقتي">
    <button type="button" class="cl-cell drawer" data-drill="#drillDrawer"
            data-drill-title="رصيد الخزنة الفعلي">
      <span>رصيد الخزنة الفعلي<?= $tiesToDay ? '' : ' دلوقتي' ?></span>
      <b><?= money((float) $wallets['drawer'], false) ?></b>
    </button>
    <i class="op plus">+</i>
    <button type="button" class="cl-cell reps<?= (float) $wallets['reps_total'] < 0 ? ' neg' : '' ?>"
            data-drill="#drillReps" data-drill-title="الفلوس اللي مع المناديب">
      <span>مع المناديب برّه<?= $tiesToDay ? '' : ' دلوقتي' ?></span>
      <b><?= money((float) $wallets['reps_total'], false) ?></b>
    </button>
    <i class="op eq">=</i>
    <div class="cl-cell sum">
      <span>كل الكاش برّه البنك</span>
      <b><?= money((float) $wallets['total'], false) ?></b>
    </div>
  </section>

  <!-- ═══════════ المراجعة: علامة جنب كل بند ═══════════ -->
  <section class="cl-review" id="review">

    <?php if (!$ready): ?>
      <div class="alert error">
        شغّل <b>php bin/migrate_v50.php</b> مرة واحدة لتفعيل مراجعة البنود، ثم افتح الصفحة تاني.
      </div>
    <?php endif; ?>

    <div class="rv-head">
      <?php /* ⚠️ سطر التعليمات اتشال بطلب المالك — العنوان والمؤشّر جنبه
               بيقولوا المطلوب، والعدّاد «X من Y بند» هو المعلومة الفعلية. */ ?>
      <div class="rv-head-txt">
        <h2>راجع بنود اليوم</h2>
      </div>

      <div class="rv-gauge <?= $complete ? 'done' : '' ?>" id="rvGauge">
        <div class="rv-gauge-num"><b id="rvPercent"><?= intf($percent) ?></b><i>%</i></div>
        <div class="rv-gauge-txt">
          <b><span id="rvReviewed"><?= intf((int)$t['blocking_reviewed']) ?></span> من <?= intf((int)$t['blocking']) ?> بند</b>
          <small id="rvPendingTxt"><?= $complete ? 'كل البنود اتراجعت ✓' : 'فاضل '.intf((int)$t['blocking_pending']).' بند' ?></small>
        </div>
        <div class="rv-gauge-bar"><i id="rvBar" style="width:<?= $percent ?>%"></i></div>
      </div>
    </div>

    <?php if ($isLocked): ?>
      <div class="rv-locked"><?= icon('alert', 14) ?> اليوم متقفل — المراجعة مثبّتة كما هي. افتح الإقفال لو محتاج تعدّل.</div>
    <?php endif; ?>

    <div class="rv-error" id="rvError" role="alert" hidden></div>

    <?php if ((int)$t['stale'] > 0): ?>
      <div class="rv-stale-note"><?= icon('alert', 14) ?> فيه <?= intf((int)$t['stale']) ?> بند اتغيّر مبلغه بعد ما اتراجع — علامته اتشالت لوحدها ومحتاج مراجعة تانية.</div>
    <?php endif; ?>

    <div class="rv-tools">
      <label class="rv-search">
        <span aria-hidden="true"><?= icon('search', 14) ?></span>
        <input type="search" id="rvSearch" placeholder="بحث" aria-label="بحث في بنود اليوم" autocomplete="off">
      </label>
      <div class="rv-filters" role="group" aria-label="فلترة البنود">
        <button type="button" class="rv-fbtn active" data-filter="all">الكل <b id="rvCntAll"><?= intf((int)$t['count']) ?></b></button>
        <button type="button" class="rv-fbtn" data-filter="pending">بدون علامة <b id="rvCntPending"><?= intf((int)$t['pending']) ?></b></button>
        <button type="button" class="rv-fbtn" data-filter="done">متراجَع <b id="rvCntDone"><?= intf((int)$t['reviewed']) ?></b></button>
      </div>
      <label class="rv-sort">
        <span aria-hidden="true"><?= icon('sort', 14) ?></span>
        <select id="rvSort" aria-label="ترتيب البنود">
          <option value="">الترتيب الافتراضي</option>
          <option value="ref">الإذن / البوليصة</option>
          <option value="rep">المندوب</option>
          <option value="company">الشركة</option>
          <option value="client">العميل</option>
          <option value="party">الطرف (سائق/مستفيد/مصدر)</option>
          <option value="kind">نوع البند</option>
          <option value="amount-desc">المبلغ — الأكبر أولاً</option>
          <option value="amount-asc">المبلغ — الأصغر أولاً</option>
        </select>
      </label>
    </div>

    <form method="post" action="<?= url('closing/review-save') ?>" id="rvForm">
      <?= csrfField() ?>
      <input type="hidden" name="date" value="<?= e($date) ?>">

      <?php foreach ($groups as $key => $g): ?>
        <?php if (!$g['count']) { continue; } ?>
        <section class="rv-group<?= $g['kind'] === 'info' ? ' is-info' : '' ?><?= $g['done'] ? ' is-done' : '' ?>"
                 data-group="<?= e($key) ?>" id="rvg-<?= e($key) ?>">
          <header class="rv-gh">
            <span class="rv-gicon" aria-hidden="true"><?= $g['icon'] ?></span>
            <?php /* ⚠️ **صفر جملة تفسيرية** (طلب المالك 2026-07-31): وصف القسم
                     اتنقل لـ`title` بدل ما ياخد سطرًا تحت كل عنوان. العنوان
                     نفسه بيكفي، والتفصيل في نافذة الكارت فوق. */ ?>
            <div class="rv-gtitle" title="<?= e($g['hint']) ?>">
              <b><?= e($g['title']) ?></b>
            </div>
            <div class="rv-gstat">
              <span class="rv-gcount" data-count-for="<?= e($key) ?>"><?= intf($g['reviewed']) ?>/<?= intf($g['count']) ?></span>
              <?php /* ⚠️ قاعدة «ممنوع رقم أصمّ»: رقم «عهدة مضافة» بقى بيفتح
                       تفكيكه — كل مندوب نزله كام وصرف كام (طلب المالك). النافذة
                       **جاهزة في الصفحة** لأن الأرقام محسوبة أصلاً في الكنترولر
                       فمفيش استعلام زيادة عند الضغط. */ ?>
              <?php if ($key === 'topups' && $g['count'] > 0): ?>
                <button type="button" class="rv-gsum in" data-drill="#topupsDrill"
                        data-drill-title="عهدة مضافة — التفكيك">
                  <?= money($g['total'],false) ?> <?= icon('search', 13) ?>
                </button>
              <?php else: ?>
                <b class="rv-gsum <?= $g['dir'] === 'in' ? 'in' : 'out' ?>"><?= money($g['total'],false) ?></b>
              <?php endif; ?>
            </div>
            <?php if ($canReview): ?>
              <button type="button" class="rv-gall" data-group-toggle="<?= e($key) ?>">
                <?= $g['done'] ? 'شيل علامات القسم' : 'علّم القسم كله' ?>
              </button>
            <?php endif; ?>
          </header>

          <?php /* ⚠️ لوحا الشرح («البنود دي اتدفعت من كارت المكتب…» و«منها كذا
                   للدرج وكذا للمناديب») اتشالوا بطلب المالك. التفكيك موجود في
                   نافذة «عهدة مضافة» (زر 🔍 جنب الرقم فوق) وهي مكانه الصح. */ ?>

          <div class="rv-list">
            <?php $seq = 0; ?>
            <?php foreach ($g['items'] as $it): ?>
              <?php
                /* مفاتيح الترتيب — وصفية بحتة للعرض في المتصفح، ولا تدخل أي حساب.
                   ⚠️ كل نوع بند بيسمّي طرفه باسم مختلف (`party_label`)، فبند المندوب
                      طرفه المندوب وبند الإذن طرفه العميل. عشان كده المفتاح بيلمّ
                      الاتنين: `by_rep` (مين دفع من عهدته) **أو** الطرف نفسه لو
                      البند أصلاً بتاع مندوب — وإلا الترتيب بالمندوب يسيب نثريات
                      المناديب ومصروفاتهم برّه وهي بنودهم فعلاً. */
                $sRep = (string) ($it['by_rep'] ?: ($it['party_label'] === 'المندوب' ? $it['party'] : ''));
                $sClient = (string) ($it['client'] ?: ($it['party_label'] === 'العميل' ? $it['party'] : ''));
                $sKeys = [
                    'ref'     => (string) $it['ref'],
                    'rep'     => $sRep,
                    'company' => (string) ($it['company'] ?? ''),
                    'client'  => $sClient,
                    'party'   => (string) $it['party'],
                    'kind'    => (string) $it['title'],
                ];
                $search = trim(implode(' ', array_filter([
                    $it['ref'], $it['title'], $it['party'], $it['note'], $it['ref_no'], $it['movement'],
                    $it['company'], $it['client'], $it['destination'], $it['containers'],
                    $it['by_rep'], $it['car'], $it['car_owner'], $it['permit_ref'],
                    $it['agency'], $it['shipping_co'], $it['port'], $it['owner'],
                    // المبلغ كمان: الدوران على بند بمبلغه أسرع حاجة («50»)
                    number_format((float) $it['amount'], 2, '.', ''),
                    number_format((float) $it['amount'], 0, '.', ''),
                ])));
                /* سطر التفاصيل: كل الحقائق ظاهرة بلا فتح أي شيء. الترتيب مقصود —
                   الطرف الأساسي الأول ثم التوكيل والجهة ثم أرقام المستندات. */
                $facts = [$it['party_label'] => $it['party']];
                if ($it['client'] && $it['client'] !== $it['party'])   { $facts['العميل'] = $it['client']; }
                if ($it['company'] && $it['company'] !== $it['party']) { $facts['الشركة'] = $it['company']; }
                if ($it['agency'])       { $facts['التوكيل'] = $it['agency']; }
                if ($it['shipping_co'])  { $facts['شركة الشحن'] = $it['shipping_co']; }
                if ($it['port'])         { $facts['الميناء'] = $it['port']; }
                if ($it['owner'] && $it['owner'] !== ($it['client'] ?? null)) { $facts['صاحب البيان'] = $it['owner']; }
                if ($it['permit_ref'])   { $facts['الإذن'] = $it['permit_ref']; }
                if ($it['destination'])  { $facts['الجهة'] = $it['destination']; }
                if ($it['car'])          { $facts['العربية'] = $it['car']; }
                if ($it['car_owner'])    { $facts['مالك العربية'] = $it['car_owner']; }
                if ($it['ref_no'])       { $facts['رقم الإيصال'] = $it['ref_no']; }
                if ($it['freight'] !== null && $it['freight'] > 0) { $facts['النولون'] = money($it['freight'], false); }
                $facts = array_filter($facts, fn($v) => $v !== null && $v !== '' && $v !== '—');
                /* عنوان البند = **اسمه المكتوب** إن وُجد، وإلا نوعه.
                   ⚠️ كان مقصورًا على `type === 'stage'` (بنود الأذونات) وبس، فبنود
                      المناديب والنثريات والمصروفات اليدوية كانت بتظهر بعنوان
                      **نوعها** («نثريات») والبيان اللي كتبه المندوب («اكراميه أمن
                      cma») ما بيظهرش على الشاشة **خالص** — كان جوّه `data-search`
                      بس. فبندان في نفس اليوم بنفس المبلغ يبانوا متطابقين تمامًا،
                      والمالك يدوّر على البند بالاسم وما يلاقيهوش فيفتكره ناقص.
                      (اتصادف فعليًّا: نثرية بدر ٥٠ ج يوم ٢٥/٧.) */
                $headline = trim((string) $it['note']) !== '' ? trim((string) $it['note']) : $it['title'];
                $subline  = $headline === $it['title'] ? null : $it['title'];
              ?>
              <div class="rv-item<?= $it['reviewed'] ? ' is-done' : '' ?><?= $it['stale'] ? ' is-stale' : '' ?>"
                   data-key="<?= e($it['key']) ?>"
                   data-group="<?= e($key) ?>"
                   data-blocking="<?= $g['kind'] === 'cash' ? '1' : '0' ?>"
                   data-search="<?= e(search_norm($search)) ?>"
                   data-seq="<?= $seq++ ?>"
                   data-amount="<?= e(number_format((float) $it['amount'], 2, '.', '')) ?>"
                   <?php foreach ($sKeys as $sk => $sv): ?>
                   data-s-<?= $sk ?>="<?= e($sv === '' || $sv === '—' ? '' : search_norm($sv)) ?>"
                   <?php endforeach; ?>>
                <label class="rv-pick">
                  <input type="checkbox" class="rv-check" name="reviewed[]"
                         value="<?= e($it['key']) ?>"
                         data-type="<?= e($it['type']) ?>" data-id="<?= (int)$it['id'] ?>"
                         <?= $it['reviewed'] ? 'checked' : '' ?>
                         <?= $canReview ? '' : 'disabled' ?>>
                  <span class="rv-box" aria-hidden="true"></span>
                  <span class="sr-only">علّم البند <?= e($it['ref'].' '.$it['title']) ?> كمراجَع</span>
                </label>

                <div class="rv-info">
                  <!-- ① اسم البند: أوضح سطر في الصف -->
                  <h3 class="rv-title"><?= e($headline) ?><?php if ($subline): ?><span class="rv-title-sub"><?= e($subline) ?></span><?php endif; ?></h3>

                  <!-- ② شارات التعريف: الإذن ونوعه والحاويات والحركة -->
                  <div class="rv-l1">
                    <span class="rv-ref"><?= e($it['ref']) ?></span>
                    <?php if ($it['permit_type_label']): ?><span class="rv-tag type"><?= e($it['permit_type_label']) ?></span><?php endif; ?>
                    <?php if ($it['containers'] !== ''): ?><span class="rv-tag box"><?= icon('package', 14) ?> <?= e($it['containers']) ?></span><?php endif; ?>
                    <?php if ($it['movement']): ?><span class="rv-tag"><?= e($it['movement']) ?></span><?php endif; ?>
                    <?php if (!empty($it['visa'])): ?><span class="rv-tag visa"><?= icon('credit-card', 14) ?> فيزا</span><?php endif; ?>
                    <?php if (!empty($it['unpaid'])): ?><span class="rv-tag unpaid" title="اتحاسب من برّه الخزنة وبرّه عهدة المندوب"><?= icon('info', 14) ?> غير مدفوع</span><?php endif; ?>
                    <?php if ($it['by_rep']): ?><span class="rv-tag rep"><?= icon('user', 14) ?>‍<?= icon('briefcase', 14) ?> دفعها المندوب: <?= e($it['by_rep']) ?></span><?php endif; ?>
                  </div>

                  <!-- ③ الحقائق: العميل والشركة والتوكيل والجهة… -->
                  <?php if ($facts): ?>
                    <dl class="rv-facts">
                      <?php foreach ($facts as $label => $value): ?>
                        <div><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd></div>
                      <?php endforeach; ?>
                    </dl>
                  <?php endif; ?>

                  <div class="rv-stamp"<?= $it['reviewed'] ? '' : ' hidden' ?>>
                    <?= icon('check', 14) ?> راجعه <?= e($it['reviewed_by'] ?: '—') ?><?= $it['reviewed_at'] ? ' — '.e(dtfmt($it['reviewed_at'])) : '' ?>
                  </div>
                  <?php if ($it['stale']): ?>
                    <div class="rv-warn"><?= icon('alert', 14) ?> المبلغ اتغيّر بعد المراجعة — راجعه تاني</div>
                  <?php endif; ?>
                </div>

                <div class="rv-money">
                  <b class="rv-amt <?= $g['dir'] === 'in' ? 'in' : 'out' ?>"><?= money($it['amount'],false) ?></b>
                  <?php if ((int)$it['qty'] > 1 && $it['unit'] !== null): ?>
                    <span class="rv-calc"><?= intf($it['qty']) ?> × <?= money($it['unit'],false) ?></span>
                  <?php endif; ?>
                  <?php if ($it['actual'] !== null): ?>
                    <span class="rv-actual" title="السعر الفعلي للعميل">فعلي: <?= money($it['actual'],false) ?></span>
                  <?php elseif ($it['type'] === 'stage' && !$it['visa'] && empty($it['unpaid'])): ?>
                    <span class="rv-actual missing" title="لم يُسجَّل السعر الفعلي للعميل بعد">فعلي: —</span>
                  <?php endif; ?>
                  <?php if ($it['gross'] !== null): ?>
                    <span class="rv-calc">العهدة <?= money($it['gross'],false) ?></span>
                  <?php endif; ?>
                </div>

                <?php if ($it['link']): ?>
                  <a class="rv-open" href="<?= url($it['link']) ?>" target="_blank" rel="noopener" title="افتح المصدر" aria-label="افتح مصدر البند">↗</a>
                <?php else: ?>
                  <span class="rv-open placeholder" aria-hidden="true"></span>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>

      <?php if ((int)$t['count'] === 0): ?>
        <div class="rv-empty">
          <span><?= icon('check', 14) ?></span>
          <b>مفيش أي حركة مسجّلة في يوم <?= e(dfmt($date)) ?></b>
          
        </div>
      <?php endif; ?>

      <div class="rv-nores" id="rvNoRes" hidden>مفيش بند مطابق للبحث.</div>

      <?php if ($canReview): ?>
        <div class="rv-manual">
          <button class="btn gold" type="submit"><?= icon('download', 14) ?> احفظ المراجعة</button>
          
        </div>
      <?php endif; ?>
    </form>
  </section>

  <!-- ═══════════ الإقفال ═══════════ -->
  <section class="cl-close <?= $isClosed ? 'is-closed' : ($complete ? 'is-ready' : 'is-blocked') ?>" id="final-close">
    <div class="cl-close-icon"><?= $isClosed ? '🔒' : ($complete ? '✓' : '⏳') ?></div>
    <div class="cl-close-txt">
      <?php if ($isClosed): ?>
        <b>يوم <?= e(dfmt($date)) ?> متقفل</b>
        <?php /* ⚠️ **`dtfmt()` إلزامية على أي وقت جاي من القاعدة** — ساعة MySQL
                 على الإنتاج UTC وPHP القاهرة (فرق ١٨٠ دقيقة مقيس)، فالعرض الخام
                 بيقول إن الإقفال حصل بدري ٣ ساعات. وختم البند تحت بيستخدم
                 `dtfmt` أصلاً، فالسطرين كانوا بيقولوا وقتين لنفس الحاجة. */ ?>
        <span>الرصيد المرحّل مثبّت على <strong><?= money($carried) ?></strong><?= $closedBy ? ' — بواسطة '.e($closedBy) : '' ?><?= $closedAt ? ' — '.e(dtfmt($closedAt)) : '' ?>.</span>
      <?php elseif ($complete): ?>
        <b>كل البنود اتراجعت — جاهز للإقفال</b>
        <span>هيتثبّت رصيد <strong><?= money($carried) ?></strong> كرصيد أول اليوم التالي، وهيتقفل الكتابة على اليوم ده.</span>
      <?php else: ?>
        <b>الإقفال لسه مقفول</b>
        <span id="clBlockTxt">فاضل <?= intf((int)$t['blocking_pending']) ?> بند من غير علامة مراجعة.</span>
      <?php endif; ?>
      <?php if ($rv['last_at']): ?>
        <small class="cl-close-meta">آخر مراجعة: <?= e(dtfmt($rv['last_at'])) ?><?= $rv['last_by'] ? ' — '.e($rv['last_by']) : '' ?></small>
      <?php endif; ?>
    </div>
    <div class="cl-close-act">
      <?php if ($isClosed && $canReopen): ?>
        <form method="post" action="<?= url('closing/reopen') ?>" class="confirm-financial" data-confirm="فتح إقفال يوم <?= e(dfmt($date)) ?>؟ هتقدر تعدّل، ولازم تقفله تاني بعدها.">
          <?= csrfField() ?><input type="hidden" name="date" value="<?= e($date) ?>">
          <button class="btn" type="submit"><?= icon('alert', 14) ?> فتح الإقفال</button>
        </form>
      <?php elseif ($isClosed): ?>
        <small>لفتح اليوم ده، افتح الأيام الأحدث الأول.<br>آخر يوم متقفل: <?= e(dfmt($lastClosed)) ?></small>
      <?php else: ?>
        <form method="post" action="<?= url('closing/close') ?>" class="confirm-financial" id="clCloseForm"
              data-confirm="إقفال يوم <?= e(dfmt($date)) ?> على رصيد <?= e(money($carried,false)) ?>؟ الرقم هيتثبّت والكتابة على اليوم هتتمنع.">
          <?= csrfField() ?><input type="hidden" name="date" value="<?= e($date) ?>">
          <button class="btn gold" type="submit" id="clCloseBtn" <?= $complete ? '' : 'disabled' ?>>
            <?= icon('alert', 14) ?> اقفل اليوم على <?= money($carried,false) ?>
          </button>
        </form>
      <?php endif; ?>
    </div>
  </section>

  <?php /* ═══ نوافذ تفاصيل كروت المعادلة — كلها جاهزة في الصفحة ═══
           ⚠️ **مفيش استعلام واحد زيادة**: البنود من `$groups` اللي الشاشة
              محمّلاها أصلاً للمراجعة تحت، فمستحيل النافذة تقول رقمًا يخالف
              القسم اللي جاي منه. */ ?>
  <?php
  /** صفوف بند المعادلة من أقسام المراجعة — نفس البنود اللي تحت بالحرف. */
  $clRows = static function (array $keys) use ($groups): array {
      $out = [];
      foreach ($keys as $k) {
          foreach (($groups[$k]['items'] ?? []) as $it) { $out[] = $it + ['_g' => $k]; }
      }
      return $out;
  };
  /** صفّ واحد في نافذة المعادلة — عنوانه بيانه، والميتا بتقول مين ومنين. */
  $clRow = static function (array $it): void {
      $ref   = trim((string) ($it['ref'] ?? ''));
      $title = trim((string) ($it['title'] ?? ''));
      $meta  = [];
      // ⚠️ بيان البند في الميتا مش في `sub` — `sub` مكانها تحت المبلغ.
      if ($title !== '' && $title !== $ref) { $meta[] = ['icon' => 'tag', 'text' => $title]; }
      if (!empty($it['party']) && $it['party'] !== '—') { $meta[] = ['icon' => 'user', 'text' => (string) $it['party']]; }
      if (!empty($it['by_rep']))    { $meta[] = ['icon' => 'briefcase', 'text' => 'دفعها ' . $it['by_rep']]; }
      if (!empty($it['permit_ref'])){ $meta[] = ['icon' => 'file-text', 'text' => (string) $it['permit_ref']]; }
      partial('row_item', [
          'title'  => $ref !== '' ? $ref : ($title !== '' ? $title : '—'),
          'icon'   => 'coins',
          'meta'   => $meta,
          'amount' => money((float) $it['amount'], false),
          'url'    => !empty($it['link']) ? url((string) $it['link']) : null,
      ]);
  };
  ?>

  <div id="drillOpening" hidden>
    <?php partial('drill', [
      'how'   => $openingFrom !== null
          ? 'الرقم ده هو المرحّل المثبَّت من إقفال يوم ' . dfmt($openingFrom) . '.'
          : 'مفيش يوم متقفل قبل كده — الرقم جاي من الرصيد الافتتاحي في الإعدادات.',
      'total' => ['label' => 'رصيد أول اليوم', 'value' => money($prevBalance, false)],
      'more'  => $openingFrom !== null
          ? ['label' => 'افتح إقفال ' . dfmt($openingFrom), 'url' => url('closing?date=' . urlencode($openingFrom))]
          : ['label' => 'الإعدادات', 'url' => url('settings')],
      'body'  => static function () use ($openingFrom, $prevBalance) {
          partial('row_item', [
              'title' => $openingFrom !== null ? 'مرحّل من يوم ' . dfmt($openingFrom) : 'رصيد افتتاحي',
              'icon'  => 'landmark',
              'meta'  => [['icon' => 'lock', 'text' => $openingFrom !== null ? 'رقم مثبَّت — ما بيتغيّرش' : 'من الإعدادات']],
              'amount'=> money($prevBalance, false),
          ]);
      },
    ]); ?>
  </div>

  <?php /* ⚠️ **العهدة اتقسمت هنا زي المعادلة فوق بالظبط** — المعادلة بتعرض
           خانتين («للدرج» و«للمناديب») والنافذة كانت بتلمّهم في سطر واحد، فالمالك
           يقارن سطر النافذة بالكارت ويلاقي رقمين مختلفين. أي تقسيم في المعادلة
           لازم يتكرّر هنا وإلا الشاشة بتناقض نفسها. */ ?>
  <div id="drillAvailable" hidden>
    <?php $availSplit = (float) $totBankToReps != 0.0; ?>
    <?php partial('drill', [
      'how'   => 'رصيد أول اليوم + العهدة اللي اتضافت النهاردة، قبل أي صرف.',
      'count' => $availSplit ? 3 : 2,
      'total' => ['label' => 'الإجمالي المتاح', 'value' => money($available, false)],
      'body'  => static function () use ($prevBalance, $totTopups, $totTopupsDrawer, $totBankToReps, $openingFrom, $availSplit) {
          partial('row_item', [
              'title' => 'رصيد أول اليوم',
              'icon'  => 'landmark',
              'meta'  => [['icon' => 'clock', 'text' => $openingFrom !== null ? 'مرحّل من ' . dfmt($openingFrom) : 'رصيد افتتاحي']],
              'amount'=> money($prevBalance, false),
          ]);
          if ($availSplit) {
              /* ⚠️ **ممنوع نص «عهدة مضافة للدرج» في أي مكان في الصفحة** لما
                 النموذج المجمّع شغّال — `verify_safe_rule` بيحرسها على **الصفحة
                 كلها** لا على كارت المعادلة وحده (الرقم ساعتها بيشمل عهد
                 المناديب فالاسم بيكدب). فالسطر معنون بنفس صياغة رأس القسم في
                 نافذة التفكيك: «دخل درج المكتب». */
              partial('row_item', [
                  'title' => 'دخل درج المكتب',
                  'icon'  => 'landmark',
                  'meta'  => [['icon' => 'info', 'text' => 'الكاش اللي دخل الدرج فعلاً']],
                  'amount'=> money($totTopupsDrawer, false),
              ]);
              partial('row_item', [
                  'title' => 'عهدة منزلة للمناديب',
                  'icon'  => 'building',
                  'meta'  => [['icon' => 'info', 'text' => 'اتسحبت من البنك ونزلت للمندوب على طول']],
                  'amount'=> money($totBankToReps, false),
              ]);
          } else {
              partial('row_item', [
                  'title' => 'عهدة مضافة النهاردة',
                  'icon'  => 'trending-up',
                  'meta'  => [['icon' => 'info', 'text' => 'فلوس دخلت النظام في اليوم ده']],
                  'amount'=> money($totTopups, false),
              ]);
          }
      },
    ]); ?>
  </div>

  <?php /* ⚠️ نوافذ المعادلة = **أقسام `BLOCKING` الطالعة**، كل خانة وأقسامها:
           «مصروفات نقدية» ← `permits` + `general` · «عهد السائقين» ← `custody`
           · «عهدة مسحوبة من الدرج» ← `rep_deposit`. لو ضفت قسم نقدي جديد في
           `DailyReview::BLOCKING` لازم يتحط في خانة منهم وإلا يختفي من القراءة
           بينما رقمه داخل في الرصيد. */ ?>
  <?php /* ⚠️ **`rep_deposit` اتشال من هنا** (2026-08-01): بقى له خانته ونافذته
           في المعادلة، فسيبانه هنا كان بيخلّي النافذة تقول رقمًا أكبر من الكارت
           وبيتعدّ مرتين في القراءة. */ ?>
  <?php /* ⚠️⚠️ **القسم اللي بيغذّي النافذة بيتغيّر مع النموذج** — `DailyReview`
           بتحطّ نفس الصف في `rep_deposit` (نقدي) أو `rep_drawer` (تحويل داخلي)
           حسب النموذج. تثبيت واحد منهم كان بيخلّي النافذة **فاضية** في النموذج
           التاني والزرار يفتح على لا شيء.
           ⚠️ والنافذة **مش مشروطة بالنموذج** — الخانة بتظهر في الحالتين (طلب
              المالك: يشوف السطر حتى لما ما يتخصمش)، فلو النافذة اتخفت الضغط
              كان هيدّي رسالة فشل. */ ?>
  <?php $isDrawerRule = \App\Models\RepCustody::newSafeRuleActive();
        $odRows = $clRows([$isDrawerRule ? 'rep_deposit' : 'rep_drawer']);
        $odTotal = $isDrawerRule ? $totOfficeDep : $totDrawerToReps; ?>
  <div id="drillOfficeDep" hidden>
    <?php partial('drill', [
      'how'   => $isDrawerRule
        ? 'كاش خرج من درج المكتب ونزل عهدة لمندوب — بيتخصم من الخزنة يوم تنزيله وبيدخل محفظته.'
        : 'كاش اتنقل من درج المكتب لجيب المندوب — الاتنين جوّه رقم الخزنة، فالإجمالي ما بيتغيّرش.',
      'count' => count($odRows),
      'total' => ['label' => 'إجمالي اللي نزل من الدرج', 'value' => money($odTotal, false)],
      'more'  => ['label' => 'افتح عهدة المناديب', 'url' => url('rep-custody')],
      'empty' => ['title' => 'مفيش عهدة نزلت من الدرج النهاردة'],
      'body'  => static function () use ($odRows, $clRow) { foreach ($odRows as $it) { $clRow($it); } },
    ]); ?>
  </div>

  <?php $expKeys = ['permits', 'general']; $expRows = $clRows($expKeys); ?>
  <div id="drillExpenses" hidden>
    <?php partial('drill', [
      'how'   => 'إيصالات ومصروفات الأذونات + المصروفات العامة. العهدة اللي نزلت للمناديب من الدرج ليها خانتها.',
      'count' => count($expRows),
      'total' => ['label' => 'إجمالي المصروفات النقدية', 'value' => money($totExpense, false)],
      'more'  => ['label' => 'افتح المصروفات', 'url' => url('expenses')],
      'empty' => ['title' => 'مفيش مصروفات نقدية النهاردة'],
      'body'  => static function () use ($expRows, $clRow) { foreach ($expRows as $it) { $clRow($it); } },
    ]); ?>
  </div>

  <?php $cusRows = $clRows(['custody']); ?>
  <div id="drillCustody" hidden>
    <?php partial('drill', [
      'how'   => 'المخصوم من كل بوليصة بعد خصم ' . intf($rate) . ' جنيه، ومعاه مين سلّم السائق.',
      'count' => count($cusRows),
      'total' => ['label' => 'إجمالي عهد السائقين', 'value' => money($totCustodyExp, false)],
      'more'  => ['label' => 'افتح خزنة المكتب', 'url' => url('custody')],
      'empty' => ['title' => 'مفيش عهد سائقين النهاردة'],
      'body'  => static function () use ($cusRows, $clRow) { foreach ($cusRows as $it) { $clRow($it); } },
    ]); ?>
  </div>

  <?php /* نافذتا «الكاش فين» — الدرج **مشتقّ** (الإجمالي − المحافظ) فنافذته
           بتوري الطرح صريحًا بدل ما يبقى رقمًا أصمّ. */ ?>
  <div id="drillDrawer" hidden>
    <?php partial('drill', [
      'how'   => 'الدرج = كل الكاش برّه البنك ناقص اللي في جيوب المناديب.',
      'count' => 3,
      'total' => ['label' => 'الكاش في الدرج', 'value' => money((float) $wallets['drawer'], false)],
      'body'  => static function () use ($wallets) {
          partial('row_item', ['title' => 'كل الكاش برّه البنك', 'icon' => 'wallet',
              'amount' => money((float) $wallets['total'], false)]);
          partial('row_item', ['title' => 'ناقص اللي مع المناديب', 'icon' => 'user',
              'amount' => '− ' . money((float) $wallets['reps_total'], false)]);
          partial('row_item', ['title' => 'يبقى في الدرج', 'icon' => 'landmark',
              'amount' => money((float) $wallets['drawer'], false)]);
      },
    ]); ?>
  </div>

  <div id="drillReps" hidden>
    <?php partial('drill', [
      'how'   => 'رصيد كل مندوب تراكمي — الفلوس اللي برّه معاه فعلاً على كل الأيام.',
      'count' => count($wallets['reps'] ?? []),
      'total' => ['label' => 'إجمالي اللي مع المناديب',
                  'value' => money((float) $wallets['reps_total'], false)],
      'more'  => ['label' => 'افتح عهدة المناديب', 'url' => url('rep-custody')],
      'empty' => ['title' => 'مفيش فلوس برّه مع أي مندوب'],
      'body'  => static function () use ($wallets) {
          foreach (($wallets['reps'] ?? []) as $w) {
              $bal = (float) $w['balance'];
              partial('row_item', [
                  'title'  => (string) $w['name'],
                  'icon'   => 'user',
                  // ⚠️ السالب معناه المكتب مدين للمندوب — ممنوع يتقصّ على صفر.
                  'meta'   => $bal < 0
                      ? [['icon' => 'alert', 'text' => 'فرش من جيبه — المكتب مدين له']] : [],
                  'amount' => money($bal, false),
                  'amount_tone' => $bal < 0 ? 'neg' : '',
                  'url'    => url('rep-custody/' . (int) $w['id']),
              ]);
          }
      },
    ]); ?>
  </div>

  <?php /* ⚠️⚠️ **الطرح لازم يطابق المعادلة فوق بالحرف** — «عهدة مسحوبة من الدرج»
           خانة مستقلة في المعادلة و`$totExpense` **مطروح منها أصلاً**
           (`expenses − officeDep`). سيبانها برّه النافذة كان بيخلّي التفكيك يقول
           رقمًا أكبر من المرحّل بمقدار `officeDep` بالظبط — نافذة بتشرح رقمًا
           وبتطلّع رقمًا تاني. متحقَّق: بتنحرف بـ٢,٠٠٠ على تنزيل ٢,٠٠٠ من الدرج.
           ⚠️ بتظهر بس لو النموذج بيخصمها (`officeDep != 0` = القاعدة فعّالة)،
              وإلا سطر بصفر في تفكيك من ٣ سطور بيبقى تشتيت. */ ?>
  <?php $carriedOfficeDep = (float) $totOfficeDep; ?>
  <div id="drillCarried" hidden>
    <?php partial('drill', [
      'how'   => $carriedOfficeDep != 0.0
          ? 'الإجمالي المتاح ناقص المصروفات والعهدة المسحوبة من الدرج وعهد السائقين.'
          : 'الإجمالي المتاح ناقص المصروفات وعهد السائقين.',
      'count' => $carriedOfficeDep != 0.0 ? 4 : 3,
      'total' => ['label' => $isClosed ? 'المرحّل المثبّت' : 'المرحّل للغد', 'value' => money($carried, false)],
      'body'  => static function () use ($available, $totExpense, $totCustodyExp, $carriedOfficeDep) {
          partial('row_item', ['title' => 'الإجمالي المتاح', 'icon' => 'wallet',
              'amount' => money($available, false)]);
          partial('row_item', ['title' => 'ناقص مصروفات نقدية', 'icon' => 'trending-down',
              'amount' => '− ' . money($totExpense, false)]);
          if ($carriedOfficeDep != 0.0) {
              partial('row_item', ['title' => 'ناقص عهدة مسحوبة من الدرج', 'icon' => 'landmark',
                  'meta'   => [['icon' => 'user', 'text' => 'نزلت لمناديب من كاش الدرج']],
                  'amount' => '− ' . money($carriedOfficeDep, false)]);
          }
          partial('row_item', ['title' => 'ناقص عهد السائقين', 'icon' => 'truck',
              'amount' => '− ' . money($totCustodyExp, false)]);
      },
    ]); ?>
  </div>

  <?php /* ═══ تفكيك «عهدة مضافة» — جاهز في الصفحة (طلب المالك) ═══
           «كل واحد نزله كام وصرف كام» في اليوم ده. الأرقام من
           `RepCustody::dayTotalsByRep()` نفسها بتاعة `/rep-custody` والداشبورد.

           ⚠️⚠️ **الصفوف اتقسمت بالمصدر** (طلب المالك 2026-08-08: «ميّز بين العهد
              اللي اتنزلت من الدرج والعهد التانية»). قبل كده كان صف المندوب
              بيعرض `deposits` بتاعة اليوم **مخلوطة** (بنك + درج + مرحَّل)، فالرقم
              اللي في الصف ما كانش بيطابق كارت «عهدة منزلة للمناديب» (بنك بس)
              ولا كارت «عهدة مضافة للدرج» — والمالك يبص على رقمين مختلفين لنفس
              الحاجة ومايعرفش الفلوس دي طلعت من الدرج ولا من البنك.
           ⚠️ **الصفوف بقت من بنود المراجعة نفسها** (`$groups`) لا من
              `dayTotalsByRep` — دي **نفس البنود اللي بتغذّي الكروت**، فمستحيل
              النافذة تقول رقمًا يخالف الكارت اللي فتحها. `$repDay` فضل للسياق
              بس (صرف كام وفاضل كام) مش لأي مبلغ في العمود الجانبي.
           ⚠️ قسم «من الدرج» **مش داخل إجمالي النافذة** — ده كاش خارج من الدرج
              (قسم `rep_deposit`/`rep_drawer`) مش عهدة داخلة، فعنوان القسم بيقول
              كده صراحةً وإلا المجموع يتقري غلط. */ ?>
  <?php /* ⚠️ **بترندر دايمًا — كان مشروطًا بوجود بنود في اليوم.** خانة «عهدة
           مضافة للدرج» في المعادلة بتظهر حتى في يوم بلا أي حركة (بصفر)، فالضغط
           عليها كان بيدوّر على نافذة **مش موجودة** ويدّي «تعذّر تحميل التفاصيل».
           للمكوّن حالة فراغ جاهزة فالرندر الدايم أرخص من شرط تاني ممكن يفترق. */ ?>
    <div id="topupsDrill" hidden>
      <?php
      $repNameById = [];
      foreach (($repNames ?? []) as $rn) { $repNameById[(int) $rn['id']] = (string) $rn['name']; }

      /** تجميع بنود المراجعة على المندوب — رقم المندوب من `link` بتاع البند. */
      $repAgg = static function (array $items, array $types): array {
          $out = [];
          foreach ($items as $it) {
              if (!in_array($it['type'] ?? '', $types, true)) { continue; }
              $rid = preg_match('~rep-custody/(\d+)~', (string) ($it['link'] ?? ''), $m) ? (int) $m[1] : 0;
              $k = $rid ?: 'n:' . ($it['party'] ?? '—');
              if (!isset($out[$k])) {
                  $out[$k] = ['id' => $rid, 'name' => (string) ($it['party'] ?? '—'), 'amount' => 0.0, 'n' => 0];
              }
              $out[$k]['amount'] += (float) $it['amount'];
              $out[$k]['n']++;
          }
          return $out;
      };
      $topItems    = $groups['topups']['items'] ?? [];
      $bankByRep   = $repAgg($topItems, ['rep_bank']);
      $carryByRep  = $repAgg($topItems, ['rep_carry']);
      // ⚠️ `$odRows` محسوبة فوق وبتختار القسم الصح حسب النموذج (`rep_deposit`
      //    نقدي أو `rep_drawer` تحويل داخلي) — ممنوع تثبّت واحد منهم هنا.
      $drawerByRep = $repAgg($odRows, ['rep_deposit']);
      $drawerSum   = array_sum(array_column($drawerByRep, 'amount'));
      $rowCount    = 1 + count($bankByRep) + count($carryByRep) + count($drawerByRep);
      // هل عهدة البنك داخلة في رصيد اليوم؟ (نموذج «الخزنة = كل كاش الشركة»)
      $bankCounts  = \App\Models\RepCustody::bankTopupActive();

      /** صفّ مندوب — المبلغ من مصدره، والسياق (صرف/فاضل) من `dayTotalsByRep`. */
      $repSrcRow = static function (array $r, string $icon) use ($repDay): void {
          $rid = (int) $r['id'];
          $d   = $repDay[$rid] ?? null;
          $meta = [];
          if ((int) $r['n'] > 1) { $meta[] = ['icon' => 'layers', 'text' => intf((int) $r['n']) . ' تنزيلات']; }
          /* ⚠️ الميتا **إجمالي يومه من كل المصادر** والمبلغ على الجنب **من المصدر
             ده بس** — لازم الكلمة تقول كده، وإلا المندوب اللي ظاهر في قسمين
             يتقري كأن الرقمين متعارضين. */
          if ($d !== null) {
              $meta[] = ['icon' => 'trending-up', 'text' => 'نزل له اليوم كله '
                  . money((float) ($d['deposits'] ?? 0), false)];
              $meta[] = ['icon' => 'trending-down', 'text' => 'صرف ' . money((float) ($d['spent'] ?? 0), false)];
              $meta[] = ['icon' => 'wallet', 'text' => 'فاضل '
                  . money((float) ($d['deposits'] ?? 0) - (float) ($d['spent'] ?? 0), false)];
          }
          partial('row_item', [
              'title'  => $r['name'] !== '' && $r['name'] !== '—' ? $r['name'] : ('مندوب #' . $rid),
              'icon'   => $icon,
              'meta'   => $meta,
              'amount' => money((float) $r['amount'], false),
              'url'    => $rid > 0 ? url('rep-custody/' . $rid) : null,
          ]);
      };

      partial('drill', [
        'how'   => 'كل عهدة اتضافت النهاردة مقسومة بمصدرها: اللي دخل الدرج، واللي نزل للمندوب من البنك على طول.',
        'count' => $rowCount,
        /* ⚠️⚠️ **الإجمالي = اللي `Closing` بيحسبه فعلاً (`$totTopups`) مش مجموع
           المعروض.** القسم بيعرض حاجات **مش داخلة في الرصيد** عن قصد (الرصيد
           المرحَّل دايمًا، وعهدة البنك في نموذج «الدرج بس»)، فمجموع المعروض بيطلع
           أكبر من المعادلة فوق. كان مكتوب «إجمالي المضاف النهاردة» على مجموع
           القسم — رقم مالوش وجود في أي مكان تاني في الشاشة، والمالك يقارنه
           بالكارت ويلاقي فرق بلا تفسير. دلوقتي الإجمالي بيطابق المعادلة، وكل قسم
           مش داخل فيه مكتوب على رأسه كده.
           ⚠️ `$groups['topups']` صراحةً لو احتجته — `$g` برّه الحلقة بيحمل **آخر
              قسم** اتلفّ عليه (unpaid) فالرقم بيطلع صفر بلا أي خطأ ظاهر. */
        'total' => ['label' => 'الداخل في حساب اليوم', 'value' => money($totTopups, false)],
        'more'  => ['label' => 'افتح عهدة المناديب', 'url' => url('rep-custody')],
        'empty' => ['title' => 'مفيش عهدة اتضافت النهاردة'],
        'body'  => function () use ($t, $bankByRep, $carryByRep, $drawerByRep, $drawerSum, $repSrcRow, $isDrawerRule, $bankCounts) {
          $sec = static function (string $title, ?string $note = null): void {
              echo '<div class="drill-sec">' . e($title)
                 . ($note !== null ? ' <em>' . e($note) . '</em>' : '') . '</div>';
          };

          $sec('دخل درج المكتب');
          partial('row_item', [
            'title' => 'درج المكتب',
            'icon'  => 'landmark',
            'meta'  => [['icon' => 'info', 'text' => 'الكاش اللي دخل الدرج فعلاً']],
            'amount'=> money($t['topups_drawer'] ?? 0, false),
          ]);

          if ($bankByRep) {
            /* ⚠️ عهدة البنك بتتحسب في الرصيد **بس** في نموذج «الخزنة = كل كاش
               الشركة»؛ في نموذج «الدرج بس» هي فلوس ما دخلتش الدرج فبرّه الحساب.
               الملحوظة بتتبع النموذج الفعّال عشان الرأس ما يكدبش. */
            $sec('نزلت للمناديب من البنك — ' . money((float) ($t['rep_bank'] ?? 0), false),
                 $bankCounts ? 'ما دخلتش الدرج — بس داخلة في حساب اليوم'
                             : 'ما دخلتش الدرج — مش في حساب اليوم');
            foreach ($bankByRep as $r) { $repSrcRow($r, 'building'); }
          }

          if ($carryByRep) {
            $sec('رصيد مرحَّل مع المناديب — ' . money((float) ($t['rep_carry'] ?? 0), false),
                 'كان معاهم من قبل — مش إضافة ومش في حساب اليوم');
            foreach ($carryByRep as $r) { $repSrcRow($r, 'undo'); }
          }

          if ($drawerByRep) {
            $sec('نزلت للمناديب من الدرج — ' . money($drawerSum, false),
                 $isDrawerRule ? 'كاش خرج من الدرج — مش إضافة، بتتطرح تحت'
                               : 'تحويل داخلي — مش إضافة جديدة');
            foreach ($drawerByRep as $r) { $repSrcRow($r, 'shuffle'); }
          }
        },
      ]);
      ?>
    </div>

  <?php
  /* ═══ استلام كاش من المناديب (طلب المالك 2026-07-31) ═══
     ⚠️ **قايمة اليوم مش التراكمي** عن قصد: الزرار بينادي
        `settleZero($rep, $date)` اللي بيقفل **اليوم المعروض** ويسجّل التسوية
        بتاريخه هو (قاعدة 2026-07-27). لو عرضنا التراكمي هنا، المالك يدوس على
        رقم والنظام يسجّل رقمًا تاني.
     ⚠️ **المبلغ عرض بس** — الخادم بيعيد حسابه بعد قفل صف المندوب. الفورم مش
        مصدر ثقة والرصيد ممكن يكون اتغيّر والشاشة مفتوحة.
     ⚠️ **البند المستنّي بيوقف الاستلام** (قرار المالك) — الزرار بيتقفل والسبب
        مكتوب، لكن الحارس الحقيقي في `RepCustody::settleZero` مش هنا. */
  $recvRows = [];
  foreach (($repDay ?? []) as $rid => $x) {
      $bal = round((float) ($x['balance'] ?? 0), 2);
      if (abs($bal) < 0.005) { continue; }
      $recvRows[(int) $rid] = ['balance' => $bal, 'pending' => round((float) ($x['pending'] ?? 0), 2)];
  }
  $repNameById = [];
  foreach (($repNames ?? []) as $rn) { $repNameById[(int) $rn['id']] = (string) $rn['name']; }
  /* «مفتوح من أيام سابقة» = التراكمي − رصيد اليوم (نفس مفهوم `/rep-custody`).
     ⚠️ **بلا زرار استلام هنا عن قصد:** الزرار بيقفل **اليوم المعروض** ويسجّل
        التسوية بتاريخه (قاعدة 2026-07-27). فلوس يوم فات بتتقفل من كارت المندوب
        أو من شاشة تقفيل يومها، وإلا التسوية تتسجّل على يوم مش بتاعها. */
  $prevOpen = [];
  foreach (($wallets['reps'] ?? []) as $w) {
      $rid  = (int) $w['id'];
      $rest = round((float) $w['balance'] - (float) ($repDay[$rid]['balance'] ?? 0), 2);
      if (abs($rest) > 0.005) { $prevOpen[$rid] = $rest; }
  }
  ?>
  <?php if ($recvRows || $prevOpen || abs((float) $settledSum) > 0.005): ?>
    <section class="d-card cl-recv" id="receive">
      <div class="d-card-head">
        <h3><?= icon('wallet', 18) ?> <span>استلام من المناديب</span></h3>
        <?php if (abs((float) $settledSum) > 0.005): ?>
          <span class="cl-wal-sum <?= $settledSum < 0 ? 'is-neg' : '' ?>">
            <?= money((float) $settledSum, false) ?> <small><?= $settledSum < 0 ? 'دفعتها' : 'استلمتها' ?></small>
          </span>
        <?php endif; ?>
      </div>
      <div class="d-card-body">
        <?php if ($recvRows): ?>
          <ul class="cl-wal-list">
            <?php foreach ($recvRows as $rid => $x): ?>
              <?php $blocked = $x['pending'] > 0.005; ?>
              <li class="cl-wal-row cl-recv-row<?= $x['balance'] < 0 ? ' is-neg' : '' ?>">
                <span class="cl-wal-ic"><?= icon('user', 15) ?></span>
                <a class="cl-wal-name" href="<?= url('rep-custody/' . $rid) ?>"><?= e($repNameById[$rid] ?? ('مندوب #' . $rid)) ?></a>
                <b class="cl-wal-amt"><?= money($x['balance'], false) ?></b>
                <?php /* ⚠️ **الرابط بعدسة المندوب واليوم** — من غيرها المالك اللي
                         بيراجع بنود مندوب واحد بيتقذف على كل المناديب وكل الأيام.
                         المصدر الوحيد `RepCustodyController::inboxUrl()` — ممنوع
                         تكتب `rep-custody/inbox?status=…` بالإيد. */ ?>
                <?php if ($blocked): ?>
                  <a class="btn ghost sm"
                     href="<?= url(\App\Controllers\RepCustodyController::inboxUrl('pending', $rid, $date)) ?>"
                     title="فيه بنود مستنية مراجعتك بـ<?= e(money($x['pending'], false)) ?> — راجعها الأول">
                    <?= icon('alert', 14) ?> راجع البنود
                  </a>
                <?php else: ?>
                  <form method="post" action="<?= url('closing/receive-rep') ?>" class="confirm-financial"
                        data-confirm="<?= $x['balance'] < 0
                            ? 'تسجيل إنك دفعت ' . e(money(abs($x['balance']), false)) . ' للمندوب وتصفير عهدة يوم ' . e(dfmt($date)) . '؟'
                            : 'استلمت ' . e(money($x['balance'], false)) . ' من المندوب وتصفير عهدة يوم ' . e(dfmt($date)) . '؟' ?>">
                    <?= csrfField() ?>
                    <?= formStateFields(\App\Core\FormState::token(), 'closing?date=' . urlencode($date) . '#receive') ?>
                    <input type="hidden" name="date" value="<?= e($date) ?>">
                    <input type="hidden" name="rep_id" value="<?= $rid ?>">
                    <button class="btn sm" type="submit">
                      <?= icon('check', 14) ?> <?= $x['balance'] < 0 ? 'دفعت له' : 'استلمت' ?>
                    </button>
                  </form>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if ($prevOpen): ?>
          <ul class="cl-wal-list cl-recv-prev">
            <?php foreach ($prevOpen as $rid => $rest): ?>
              <li class="cl-wal-row is-prev<?= $rest < 0 ? ' is-neg' : '' ?>">
                <span class="cl-wal-ic"><?= icon('clock', 15) ?></span>
                <a class="cl-wal-name" href="<?= url('rep-custody/' . $rid) ?>"><?= e($repNameById[$rid] ?? ('مندوب #' . $rid)) ?></a>
                <b class="cl-wal-amt"><?= money($rest, false) ?></b>
                <span class="cl-recv-tag">من أيام سابقة</span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if (($groups['rep_settle']['count'] ?? 0) > 0): ?>
          <ul class="cl-wal-list cl-recv-done">
            <?php foreach ($groups['rep_settle']['items'] as $it): ?>
              <li class="cl-wal-row is-done<?= $it['amount'] < 0 ? ' is-neg' : '' ?>">
                <span class="cl-wal-ic"><?= icon('check', 15) ?></span>
                <a class="cl-wal-name" href="<?= url((string) $it['link']) ?>"><?= e((string) $it['party']) ?></a>
                <b class="cl-wal-amt"><?= money((float) $it['amount'], false) ?></b>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php /* ═══ قسم «الكاش برّه البنك» اتشال من هنا (2026-07-31، طلب المالك) ═══
           بقى **كروت تحت المعادلة فوق** (`.cl-split`) بنفس الأرقام بالحرف
           (`Closing::walletSplit()`) وبتفاصيل جوّه نافذتين. سيبانه هنا كان
           بيكرّر نفس الأرقام مرتين في نفس الشاشة. */ ?>

  <!-- شريط سفلي لاصق: التقدّم + الإقفال دايمًا في المتناول (مهم على الهاتف) -->
  <?php if (!$isClosed && (int)$t['blocking'] > 0): ?>
    <div class="cl-bar" id="clBar" <?= $complete ? 'data-ready="1"' : '' ?>>
      <div class="cl-bar-txt">
        <b id="clBarNum"><?= intf((int)$t['blocking_reviewed']) ?>/<?= intf((int)$t['blocking']) ?></b>
        <span id="clBarMsg"><?= $complete ? 'كل البنود اتراجعت' : 'بند اتراجع' ?></span>
      </div>
      <div class="cl-bar-line"><i id="clBarFill" style="width:<?= $percent ?>%"></i></div>
      <a class="btn sm" href="#final-close" id="clBarGo"><?= $complete ? 'اقفل اليوم ↓' : 'اذهب للإقفال ↓' ?></a>
    </div>
  <?php endif; ?>
</div>

<style>
/* ═══════════════ التقفيل اليومي — شاشة مراجعة (v50) ═══════════════ */
.cl-page{display:grid;gap:var(--space-4);padding-bottom:var(--space-6)}

/* الترويسة */
.cl-top{display:grid;grid-template-columns:1fr auto auto;align-items:center;gap:var(--space-4);
  padding:var(--space-4) var(--space-5);border-radius:var(--radius-3);
  background:linear-gradient(120deg,var(--navy-dark),var(--navy-mid));color:var(--white);box-shadow:var(--shadow-2)}
.cl-top-id{display:flex;align-items:center;flex-wrap:wrap;gap:var(--space-2) var(--space-3);min-width:0}
.cl-kicker{font-size:var(--fs-1);font-weight:var(--fw-black);color:var(--gold-light);opacity:.85;width:100%}
.cl-top h1{margin:0;font-size:var(--fs-6);line-height:var(--lh-tight)}
.cl-chip{padding:4px 10px;border-radius:var(--radius-pill);font-size:var(--fs-1);font-weight:var(--fw-black)}
.cl-chip.open{background:rgba(62,210,139,.16);color:#8aefbd}
.cl-chip.closed{background:rgba(255,255,255,.16);color:var(--white)}
.cl-dates{display:flex;align-items:center;gap:var(--space-2)}
.cl-dates form{display:flex;align-items:center;gap:var(--space-2);padding:4px 4px 4px var(--space-3);
  border-radius:var(--radius-2);background:rgba(255,255,255,.12)}
.cl-dates input[type=date]{border:0;background:transparent;color:var(--white);font-family:inherit;
  font-weight:var(--fw-bold);min-height:34px}
.cl-dates input[type=date]::-webkit-calendar-picker-indicator{filter:invert(1);opacity:.8}
.cl-arrow,.cl-todaybtn{display:grid;place-items:center;min-height:38px;min-width:38px;padding:0 var(--space-2);
  border-radius:var(--radius-1);background:rgba(255,255,255,.12);color:var(--white);
  text-decoration:none;font-weight:var(--fw-black);font-size:var(--fs-4)}
.cl-todaybtn{font-size:var(--fs-1);padding:0 var(--space-3)}
.cl-todaybtn.active{background:var(--gold);color:var(--navy-dark)}
.cl-arrow:hover,.cl-todaybtn:hover{background:rgba(255,255,255,.22)}

/* معادلة الرصيد */
.cl-flow{display:flex;flex-wrap:wrap;align-items:stretch;gap:var(--space-2);padding:var(--space-4);
  border:1px solid var(--line);border-radius:var(--radius-3);background:var(--card);box-shadow:var(--shadow-1)}
/* ⚠️ الكروت بقت `<button>` (قاعدة «ممنوع رقم أصمّ») — لازم تصفير أنماط الزرار
   الافتراضية وإلا الخط والمحاذاة يختلفوا عن باقي الشاشة. */
.cl-cell{flex:1 1 140px;display:grid;align-content:center;gap:2px;padding:var(--space-3);
  border:1px solid var(--line);border-radius:var(--radius-2);background:var(--zebra);
  font:inherit;text-align:start;cursor:pointer;position:relative;
  transition:border-color var(--dur-1) var(--ease),background var(--dur-1) var(--ease)}
.cl-cell:hover{border-color:var(--navy);background:var(--card)}
.cl-cell:focus-visible{outline:2px solid var(--navy);outline-offset:2px}
/* علامة إن الكارت بيفتح تفاصيل — بديل الجملة التفسيرية */
.cl-cell::after{content:'';position:absolute;inset-block-start:6px;inset-inline-start:8px;
  width:5px;height:5px;border-radius:50%;background:var(--ink-mut);opacity:.35}
.cl-cell:hover::after{opacity:.9;background:var(--navy)}
.cl-cell.total{background:var(--card);border-color:var(--navy)}
.cl-cell.total b{color:var(--navy);font-size:var(--fs-4)}
/* ═══ «الكاش فين دلوقتي» — الدرج والمناديب (طلب المالك 2026-07-31) ═══
   نفس ماركب المعادلة عشان الشاشة تفضل بلغة واحدة، بشريط علوي مميّز يوضّح
   إنه سطر تاني بيجاوب على سؤال تاني («الكاش فين» مش «اليوم عمل إيه»). */
.cl-split{display:flex;flex-wrap:wrap;align-items:stretch;gap:var(--space-2);
  margin-block-start:var(--space-3);padding:var(--space-4);
  border:1px solid var(--line);border-radius:var(--radius-3);background:var(--card);
  box-shadow:var(--shadow-1);border-block-start:3px solid var(--gold)}
.cl-split .cl-cell.drawer b{color:var(--gold-dark)}
.cl-split .cl-cell.reps b{color:var(--navy)}
.cl-split .cl-cell.reps.neg b{color:var(--red)}
/* ⚠️ المجموع `<div>` مش `<button>` — مالوش تفاصيل تانية غير اللي في الكارتين،
   وزرار بيفتح نفس المعلومة تاني بيبقى تكرار. */
.cl-split .cl-cell.sum{background:var(--zebra);border-color:var(--navy);cursor:default}
.cl-split .cl-cell.sum::after{display:none}
.cl-split .cl-cell.sum b{color:var(--ink);font-size:var(--fs-4)}
.cl-cell span{font-size:var(--fs-1);color:var(--grey);font-weight:var(--fw-bold)}
.cl-cell b{font-size:var(--fs-3);color:var(--navy);font-variant-numeric:tabular-nums}
.cl-cell small{font-size:var(--fs-1);color:var(--grey);opacity:.85}
.cl-cell.in b{color:var(--green)}
.cl-cell.out b{color:var(--red)}
.cl-cell.result{flex:1 1 180px;background:linear-gradient(135deg,var(--gold-light),var(--white));border-color:var(--gold)}
.cl-cell.result b{font-size:var(--fs-5);color:var(--gold-dark)}
.cl-cell.result.negative{background:var(--red-lt);border-color:var(--red)}
.cl-cell.result.negative b{color:var(--red)}
.cl-flow .op{align-self:center;font-style:normal;font-weight:var(--fw-black);font-size:var(--fs-4);color:var(--ink-mut)}
.cl-flow .op.minus{color:var(--red)}
.cl-flow .op.plus{color:var(--green)}
/* ⚠️ **علامة تحويل داخلي — محايدة عن قصد.** الكاش بينتقل من الدرج لجيب مندوب
   والاتنين جوّه الرقم، فالإجمالي ما بيتغيّرش. لون أحمر (خروج) أو أخضر (دخول)
   كان هيقول إن فيه فلوس راحت أو جت وهو مش بيحصل. */
.cl-flow .op.xfer{color:var(--ink-mut)}
.cl-cell.xfer{border-style:dashed;background:var(--zebra)}
.cl-cell.xfer b{color:var(--ink-mut)}
.cl-cell.xfer small{font-size:var(--fs-1);color:var(--grey);font-weight:var(--fw-normal)}

/* رأس المراجعة */
.cl-review{padding:var(--space-4);border:1px solid var(--line);border-radius:var(--radius-3);
  background:var(--card);box-shadow:var(--shadow-1)}
.rv-head{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:var(--space-4)}
.rv-head-txt h2{margin:0;font-size:var(--fs-5);color:var(--navy)}
.rv-head-txt p{margin:2px 0 0;font-size:var(--fs-2);color:var(--grey)}
.rv-gauge{display:grid;grid-template-columns:auto 1fr;align-items:center;gap:2px var(--space-3);
  min-width:250px;padding:var(--space-3);border:1px solid var(--line);border-radius:var(--radius-2);background:var(--zebra)}
.rv-gauge.done{border-color:var(--green);background:var(--green-lt)}
.rv-gauge-num{display:flex;align-items:baseline;gap:1px;grid-row:span 2}
.rv-gauge-num b{font-size:var(--fs-7);line-height:1;color:var(--navy);font-variant-numeric:tabular-nums}
.rv-gauge-num i{font-style:normal;font-size:var(--fs-2);color:var(--grey);font-weight:var(--fw-black)}
.rv-gauge.done .rv-gauge-num b{color:var(--green)}
.rv-gauge-txt b{display:block;font-size:var(--fs-2);color:var(--ink)}
.rv-gauge-txt small{font-size:var(--fs-1);color:var(--grey)}
.rv-gauge-bar{grid-column:2;height:7px;border-radius:var(--radius-pill);background:var(--line);overflow:hidden}
.rv-gauge-bar i{display:block;height:100%;border-radius:var(--radius-pill);background:var(--gold);
  transition:width var(--dur-base) var(--ease)}
.rv-gauge.done .rv-gauge-bar i{background:var(--green)}

.rv-locked,.rv-stale-note{margin-top:var(--space-3);padding:var(--space-3);border-radius:var(--radius-2);
  font-size:var(--fs-2);font-weight:var(--fw-bold)}
.rv-locked{background:var(--zebra);border:1px solid var(--line2);color:var(--grey)}
.rv-error{margin-top:var(--space-3);padding:var(--space-3);border-radius:var(--radius-2);
  background:var(--red-lt);border:1px solid var(--red);color:var(--red);
  font-size:var(--fs-2);font-weight:var(--fw-black)}
.rv-error[hidden]{display:none}
.rv-stale-note{background:var(--gold-light);border:1px solid var(--gold);color:var(--gold-dark)}

/* أدوات البحث والفلترة */
.rv-tools{display:flex;flex-wrap:wrap;gap:var(--space-2);margin:var(--space-4) 0 var(--space-3)}
.rv-search{flex:1 1 240px;display:flex;align-items:center;gap:var(--space-2);padding:0 var(--space-3);
  border:1px solid var(--line);border-radius:var(--radius-2);background:var(--white)}
.rv-search input{flex:1;min-height:42px;border:0;background:transparent;font-family:inherit;font-size:var(--fs-2)}
.rv-search input:focus{outline:0}
.rv-filters{display:flex;gap:var(--space-1);flex-wrap:wrap}
.rv-sort{display:flex;align-items:center;gap:var(--space-2);padding:0 var(--space-3);
  border:1px solid var(--line);border-radius:var(--radius-2);background:var(--white);color:var(--grey)}
.rv-sort select{min-height:42px;border:0;background:transparent;font-family:inherit;
  font-size:var(--fs-2);color:var(--ink);cursor:pointer}
.rv-sort select:focus{outline:0}
.rv-fbtn{display:inline-flex;align-items:center;gap:6px;min-height:42px;padding:0 var(--space-3);
  border:1px solid var(--line);border-radius:var(--radius-2);background:var(--white);
  color:var(--grey);font-family:inherit;font-size:var(--fs-2);font-weight:var(--fw-bold);cursor:pointer}
.rv-fbtn b{padding:1px 7px;border-radius:var(--radius-pill);background:var(--zebra);
  font-size:var(--fs-1);color:var(--ink)}
.rv-fbtn.active{background:var(--navy);border-color:var(--navy);color:var(--white)}
.rv-fbtn.active b{background:rgba(255,255,255,.2);color:var(--white)}

/* الأقسام */
.rv-group{margin-bottom:var(--space-3);border:1px solid var(--line);border-radius:var(--radius-2);
  background:var(--white);overflow:hidden}
.rv-group.is-done{border-color:#bfe0cb}
.rv-group.is-info{background:var(--zebra)}
.rv-gh{display:grid;grid-template-columns:auto 1fr auto auto;align-items:center;gap:var(--space-3);
  padding:var(--space-3);background:var(--zebra);border-bottom:1px solid var(--line)}
.rv-group.is-done .rv-gh{background:var(--green-lt)}
.rv-gicon{display:grid;place-items:center;width:38px;height:38px;border-radius:var(--radius-1);
  background:var(--white);border:1px solid var(--line);font-size:var(--fs-4)}
.rv-gtitle{min-width:0}
.rv-gtitle b{display:block;font-size:var(--fs-3);color:var(--navy)}
.rv-gtitle small{font-size:var(--fs-1);color:var(--grey)}
.rv-gstat{display:grid;justify-items:end;gap:2px}
.rv-gcount{font-size:var(--fs-1);color:var(--grey);font-weight:var(--fw-black);font-variant-numeric:tabular-nums}
.rv-gsum{font-size:var(--fs-3);font-variant-numeric:tabular-nums}
.rv-gsum.out{color:var(--red)}
.rv-gsum.in{color:var(--green)}
.rv-gall{min-height:38px;padding:0 var(--space-3);border:1px solid var(--line2);border-radius:var(--radius-1);
  background:var(--white);color:var(--navy);font-family:inherit;font-size:var(--fs-1);
  font-weight:var(--fw-black);cursor:pointer;white-space:nowrap}
.rv-gall:hover{background:var(--navy);color:var(--white);border-color:var(--navy)}
.rv-ginfo{padding:var(--space-2) var(--space-3);background:#f3eefa;color:#5d4180;font-size:var(--fs-1)}

/* البند — قلب الشاشة */
.rv-list{display:grid}
.rv-item{display:grid;grid-template-columns:auto 1fr auto auto;align-items:center;gap:var(--space-3);
  padding:var(--space-3);border-bottom:1px solid var(--line);
  transition:background var(--dur-fast) var(--ease)}
.rv-item:last-child{border-bottom:0}
.rv-item.is-done{background:#f4faf6}
.rv-item.is-stale{background:#fffaf0;box-shadow:inset 3px 0 0 var(--gold)}
.rv-item[hidden]{display:none}
.rv-pick{display:grid;place-items:center;width:44px;height:44px;cursor:pointer;margin:0}
.rv-check{position:absolute;opacity:0;width:1px;height:1px}
.rv-box{display:grid;place-items:center;width:26px;height:26px;border:2px solid var(--line2);
  border-radius:var(--radius-1);background:var(--white);transition:all var(--dur-fast) var(--ease)}
.rv-box:after{content:"✓";font-size:var(--fs-2);font-weight:var(--fw-black);color:var(--white);
  transform:scale(.4);opacity:0;transition:all var(--dur-fast) var(--ease)}
.rv-check:checked + .rv-box{background:var(--green);border-color:var(--green)}
.rv-check:checked + .rv-box:after{transform:scale(1);opacity:1}
.rv-check:focus-visible + .rv-box{box-shadow:0 0 0 3px rgba(30,110,60,.25)}
.rv-check:disabled + .rv-box{background:var(--zebra);border-color:var(--line);cursor:not-allowed}
.rv-check:disabled:checked + .rv-box{background:var(--grey);border-color:var(--grey)}
/* ⚠ التسلسل البصري مقصود (طلب المالك: «اسم البند صغير جدًا ومش ظاهر»):
   ① اسم البند   fs-4 أسود   ← أول حاجة العين تقع عليها
   ② شارات التعريف fs-2       ← الإذن ونوعه والحاويات والحركة
   ③ الحقائق      fs-2       ← العميل/الشركة/التوكيل/الجهة…
   ممنوع أي نص هنا ينزل تحت fs-2 غير الختم والتحذير. */
.rv-info{min-width:0;display:grid;gap:5px}
.rv-title{margin:0;font-size:var(--fs-4);line-height:var(--lh-tight);
  color:var(--navy);font-weight:var(--fw-black)}
.rv-title-sub{margin-inline-start:var(--space-2);font-size:var(--fs-2);
  color:var(--grey);font-weight:var(--fw-bold)}
.rv-l1{display:flex;flex-wrap:wrap;align-items:center;gap:6px}
.rv-ref{padding:2px 9px;border-radius:var(--radius-pill);background:var(--navy);color:var(--white);
  font-size:var(--fs-2);font-weight:var(--fw-black);letter-spacing:.2px}
.rv-tag{padding:2px 9px;border-radius:var(--radius-pill);background:var(--zebra);border:1px solid var(--line);
  font-size:var(--fs-2);color:var(--grey);font-weight:var(--fw-bold)}
.rv-tag.visa{background:#f3eefa;border-color:#ddd0ee;color:#5d4180}
/* v57 — «غير مدفوع»: اتحاسب من برّه الخزنة والعهدة، فمعلوماتي زي الفيزا */
.rv-tag.unpaid{background:var(--gold-light);border-color:var(--gold);color:var(--gold-dark)}
.rv-tag.box{background:#eef4fa;border-color:#cddeee;color:var(--navy)}
.rv-tag.type{background:var(--gold-light);border-color:#e0cf8f;color:var(--gold-dark)}
.rv-tag.rep{background:#eaf6ef;border-color:#c3e2ce;color:var(--green)}
/* سطر الحقائق: العميل/الشركة/التوكيل/الجهة/العربية… ظاهرة بلا فتح أي شيء */
.rv-facts{display:flex;flex-wrap:wrap;gap:4px var(--space-4);margin:0}
.rv-facts>div{display:flex;align-items:baseline;gap:5px;min-width:0}
.rv-facts dt{font-size:var(--fs-2);color:var(--grey);white-space:nowrap}
.rv-facts dt:after{content:":"}
.rv-facts dd{margin:0;font-size:var(--fs-2);color:var(--ink);font-weight:var(--fw-bold)}
.rv-stamp{font-size:var(--fs-1);color:var(--green);font-weight:var(--fw-bold)}
.rv-warn{font-size:var(--fs-2);color:var(--gold-dark);font-weight:var(--fw-black)}
.rv-money{display:grid;justify-items:end;gap:3px;white-space:nowrap}
.rv-amt{font-size:var(--fs-5);font-weight:var(--fw-black);font-variant-numeric:tabular-nums;white-space:nowrap}
.rv-amt.out{color:var(--red)}
.rv-amt.in{color:var(--green)}
.rv-calc{font-size:var(--fs-2);color:var(--grey);font-variant-numeric:tabular-nums}
.rv-actual{font-size:var(--fs-2);color:var(--green);font-weight:var(--fw-black);font-variant-numeric:tabular-nums}
.rv-actual.missing{color:var(--grey);font-weight:var(--fw-bold)}
.rv-open{display:grid;place-items:center;width:36px;height:36px;border-radius:var(--radius-1);
  background:var(--zebra);color:var(--navy);text-decoration:none;font-weight:var(--fw-black)}
.rv-open:hover{background:var(--navy);color:var(--white)}
.rv-open.placeholder{background:transparent}

.rv-empty{display:grid;justify-items:center;gap:var(--space-1);padding:var(--space-6);
  border:1px dashed var(--line2);border-radius:var(--radius-2);background:var(--zebra);text-align:center}
.rv-empty>span{display:grid;place-items:center;width:38px;height:38px;border-radius:50%;
  background:var(--green-lt);color:var(--green);font-weight:var(--fw-black)}
.rv-empty b{font-size:var(--fs-3);color:var(--navy)}
.rv-empty small{font-size:var(--fs-2);color:var(--grey)}
.rv-nores{padding:var(--space-5);text-align:center;color:var(--grey);font-size:var(--fs-2)}
.rv-manual{display:flex;flex-wrap:wrap;align-items:center;gap:var(--space-3);margin-top:var(--space-3)}
.rv-manual small{font-size:var(--fs-1);color:var(--grey)}
/* ⚠ الإخفاء بالجافاسكريبت نفسه (`js-review` بتتحط من السكربت) مش من الخادم:
   لو اتخفى بشرط الخادم، المتصفح اللي الجافاسكريبت متعطّل فيه كان هيفضل بلا أي
   طريقة يحفظ بيها — الحفظ التلقائي والزرار الاحتياطي ما ينفعش يتشالوا مع بعض. */
.cl-page.js-review .rv-manual{display:none}

/* الإقفال */
.cl-close{display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:var(--space-4);
  padding:var(--space-4);border:1px solid var(--line);border-radius:var(--radius-3);background:var(--card)}
.cl-close.is-ready{border-color:var(--gold);background:linear-gradient(135deg,var(--gold-light),var(--white))}
.cl-close.is-closed{border-color:#bfe0cb;background:var(--green-lt)}
.cl-close-icon{display:grid;place-items:center;width:48px;height:48px;border-radius:var(--radius-2);
  background:var(--zebra);font-size:var(--fs-5)}
.cl-close.is-ready .cl-close-icon{background:var(--gold);color:var(--white)}
.cl-close.is-closed .cl-close-icon{background:#cfe9d8}
.cl-close-txt{display:grid;gap:2px;min-width:0}
.cl-close-txt b{font-size:var(--fs-3);color:var(--navy)}
.cl-close-txt span{font-size:var(--fs-2);color:var(--grey)}
.cl-close-txt strong{color:var(--navy)}
.cl-close-meta{font-size:var(--fs-1);color:var(--grey)}
.cl-close-act small{display:block;max-width:280px;text-align:end;font-size:var(--fs-1);color:var(--grey)}
.cl-close-act .btn{min-height:46px}
.cl-close-act .btn:disabled{opacity:.55;cursor:not-allowed}

/* شريط لاصق سفلي */
/* زرار مجموع القسم اللي بيفتح التفكيك — نفس شكل الرقم بالظبط + مؤشّر إنه يُضغط.
   ⚠️ كل كلاس مطبوع لازم له شكل (درس `.table-mobile-scroll`). */
button.rv-gsum{display:inline-flex;align-items:center;gap:6px;border:0;background:none;
  padding:0;font:inherit;font-weight:700;cursor:pointer;font-variant-numeric:tabular-nums}
button.rv-gsum svg{opacity:.55}
button.rv-gsum:hover{text-decoration:underline}
button.rv-gsum:hover svg{opacity:1}
/* ═══ تفكيك المحافظ (المرحلة ٤.٣ من docs/16) ═══
   ⚠️ الكلاسات هنا في `<style>` الشاشة عن قصد — مستعملة في **شاشة واحدة** بس.
      لو اتنادت من شاشة تانية لازم تتنقل لـ`app.css` (درس `.cars-period` v54). */
.cl-recv .cl-wal-sum{font-weight:700;font-size:var(--fs-5);color:var(--ink);
  font-variant-numeric:tabular-nums}
.cl-wal-list{list-style:none;margin:0;padding:0;display:grid;gap:2px}
.cl-wal-row{display:flex;align-items:center;gap:var(--space-3);min-height:44px;
  padding:var(--space-2) 0;border-bottom:1px solid var(--line)}
.cl-wal-row:last-child{border-bottom:0}
.cl-wal-ic{display:grid;place-items:center;inline-size:30px;block-size:30px;flex:none;
  border-radius:var(--radius-2);background:var(--bg-soft,#F2F6FC);color:var(--grey)}
.cl-wal-row.is-drawer .cl-wal-ic{background:var(--gold-lt,#FBF3DE);color:var(--gold-dark)}
.cl-wal-name{flex:1;min-width:0;font-weight:500;color:var(--ink);
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
a.cl-wal-name:hover{color:var(--navy);text-decoration:underline}
.cl-wal-amt{flex:none;font-weight:700;font-variant-numeric:tabular-nums;color:var(--ink)}
.cl-wal-row.is-neg .cl-wal-amt{color:var(--red)}
.cl-wal-row.is-drawer .cl-wal-amt{font-size:var(--fs-4)}
.cl-wal-note{margin:var(--space-3) 0 0;font-size:var(--fs-2);line-height:1.8;color:var(--grey)}
.cl-wal-note b{color:var(--ink);font-variant-numeric:tabular-nums}
/* ═══ استلام من المناديب (2026-07-31) — بيشارك ماركب `.cl-wal-*` ═══ */
.cl-recv .cl-wal-sum{font-weight:700;font-size:var(--fs-5);color:var(--green);
  font-variant-numeric:tabular-nums}
.cl-recv .cl-wal-sum.is-neg{color:var(--red)}
.cl-recv .cl-wal-sum small{font-weight:500;font-size:var(--fs-2);color:var(--grey)}
.cl-recv-row form{margin:0;flex:none}
/* الصف المتسوّى بيفضل ظاهر كـ«خلاص اتعمل» بدل ما يختفي بالسكوت */
.cl-recv-done,.cl-recv-prev{margin-block-start:var(--space-3);padding-block-start:var(--space-2);
  border-block-start:1px dashed var(--line)}
.cl-wal-row.is-prev .cl-wal-ic{background:var(--gold-lt,#FBF3DE);color:var(--gold-dark)}
.cl-recv-tag{flex:none;font-size:var(--fs-1);color:var(--grey);
  padding:2px var(--space-2);border:1px solid var(--line);border-radius:var(--radius-pill)}
.cl-wal-row.is-done .cl-wal-ic{background:var(--green-lt,#E3F1E7);color:var(--green)}
.cl-wal-row.is-done .cl-wal-amt{color:var(--green)}
.cl-wal-row.is-done.is-neg .cl-wal-amt{color:var(--red)}
.cl-bar{position:sticky;bottom:0;z-index:var(--z-sticky);display:grid;
  grid-template-columns:auto 1fr auto;align-items:center;gap:var(--space-3);
  padding:var(--space-2) var(--space-3);border:1px solid var(--line);border-radius:var(--radius-2);
  background:rgba(255,255,255,.96);backdrop-filter:blur(6px);box-shadow:var(--shadow-3)}
.cl-bar[data-ready="1"]{border-color:var(--green);background:rgba(227,241,231,.97)}
.cl-bar-txt{display:flex;align-items:baseline;gap:6px;white-space:nowrap}
.cl-bar-txt b{font-size:var(--fs-3);color:var(--navy);font-variant-numeric:tabular-nums}
.cl-bar-txt span{font-size:var(--fs-1);color:var(--grey)}
.cl-bar-line{height:8px;border-radius:var(--radius-pill);background:var(--line);overflow:hidden}
.cl-bar-line i{display:block;height:100%;background:var(--gold);transition:width var(--dur-base) var(--ease)}
.cl-bar[data-ready="1"] .cl-bar-line i{background:var(--green)}

/* ═══════════ الهاتف ═══════════ */
@media(max-width:900px){
  .cl-top{grid-template-columns:1fr;gap:var(--space-3)}
  .cl-dates{flex-wrap:wrap}
  .cl-dates form{flex:1 1 160px}
  .cl-top-actions .btn{width:100%;justify-content:center}
  .cl-flow .op{display:none}
  .cl-cell{flex:1 1 calc(50% - var(--space-2))}
  .cl-cell.result{flex:1 1 100%}
  .rv-gauge{width:100%}
  .rv-gh{grid-template-columns:auto 1fr;gap:var(--space-2) var(--space-3)}
  .rv-gstat{grid-column:2;justify-items:start;grid-auto-flow:column;justify-content:start;gap:var(--space-3);align-items:baseline}
  .rv-gall{grid-column:1/-1;width:100%}
  .cl-close{grid-template-columns:auto 1fr}
  .cl-close-act{grid-column:1/-1}
  .cl-close-act .btn,.cl-close-act form{width:100%}
  .cl-close-act .btn{justify-content:center}
  .cl-close-act small{max-width:none;text-align:start}
}
@media(max-width:640px){
  .cl-page{gap:var(--space-3)}
  .cl-top{padding:var(--space-3)}
  .cl-top h1{font-size:var(--fs-5)}
  .cl-review{padding:var(--space-3)}
  .cl-cell{flex:1 1 100%}
  .rv-head{gap:var(--space-3)}
  .rv-filters{width:100%}
  .rv-fbtn{flex:1;justify-content:center}
  .rv-sort{width:100%}
  .rv-sort select{flex:1;width:100%}
  /* البند بيتحوّل لكارت: العلامة والمبلغ في صف، والتفاصيل تحتهم */
  .rv-item{grid-template-columns:auto 1fr auto;gap:var(--space-2) var(--space-3);
    padding:var(--space-3);align-items:start}
  .rv-info{grid-column:2/-1;grid-row:1}
  .rv-money{grid-column:2;grid-row:2;justify-items:start;grid-auto-flow:column;
    justify-content:start;gap:var(--space-3);align-items:baseline}
  .rv-amt{font-size:var(--fs-4)}
  .rv-open{grid-column:3;grid-row:2;justify-self:end}
  .rv-pick{grid-row:1/3;align-self:start}
  .rv-facts{gap:var(--space-1) var(--space-2)}
  .cl-close{grid-template-columns:1fr;text-align:center;justify-items:center}
  .cl-close-txt{justify-items:center}
  .cl-bar{grid-template-columns:1fr auto;gap:var(--space-2)}
  .cl-bar-line{grid-column:1/-1;grid-row:2}
}
@media(prefers-reduced-motion:reduce){
  .rv-box,.rv-box:after,.rv-gauge-bar i,.cl-bar-line i,.rv-item{transition:none}
}
</style>

<script>
/* أدوات عرض البنود: بحث · فلترة · ترتيب.
   ⚠️ سكربت **مستقل عن سكربت المراجعة عن قصد** — سكربت المراجعة محكوم بـ
      `$canReview` (اليوم المتقفل ما بيتكتبش عليه)، ودي أدوات **عرض بحت**
      لازم تشتغل على اليوم المتقفل كمان: المالك بيفتح يومًا قديم عشان يراجعه
      بعينه، ومحتاج يدوّر ويرتّب فيه زي أي يوم. كانوا جوّه سكربت المراجعة،
      فكانوا **معطّلين بالسكوت** على كل يوم متقفل — الخانة بتترسم وما بتعملش
      حاجة (نفس عائلة باج `.table-mobile-scroll`). */
(function () {
  if (!document.getElementById('clPage')) { return; }

  /* ① بحث وفلترة — كلها في المتصفح بلا أي طلب للخادم */
  var search = document.getElementById('rvSearch');
  var mode = 'all';
  /* ⚠️ التطبيع إلزامي على الطرفين: `data-search` بيتطبّع في الخادم بـ`search_norm()`
     واللي المستخدم بيكتبه بيتطبّع هنا بـ`almSearchNorm` (نفس الجدول بالحرف).
     من غيره «إكرامية» ما بتلاقي «اكراميه» فالبند يبان مش موجود وهو موجود. */
  var norm = window.almSearchNorm || function (v) { return String(v || '').trim().toLowerCase(); };
  function applyFilter() {
    var q = norm(search && search.value || '');
    var shown = 0;
    document.querySelectorAll('.rv-item').forEach(function (row) {
      var done = row.classList.contains('is-done');
      var okMode = mode === 'all' || (mode === 'pending' ? !done : done);
      var okText = !q || (row.dataset.search || '').indexOf(q) !== -1;
      var show = okMode && okText;
      row.hidden = !show;
      if (show) { shown++; }
    });
    document.querySelectorAll('.rv-group').forEach(function (g) {
      g.hidden = !g.querySelector('.rv-item:not([hidden])');
    });
    var none = document.getElementById('rvNoRes');
    if (none) { none.hidden = shown !== 0 || !document.querySelector('.rv-item'); }
  }
  /* سكربت المراجعة بينادّيها بعد كل علامة عشان الفلتر يفضل صادق */
  window.__rvApplyFilter = applyFilter;
  if (search) { search.addEventListener('input', applyFilter); }
  document.querySelectorAll('.rv-fbtn').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('.rv-fbtn').forEach(function (x) { x.classList.remove('active'); });
      b.classList.add('active');
      mode = b.dataset.filter;
      applyFilter();
    });
  });

  /* ② ترتيب البنود — عرض بحت: ولا بيمسّ أي حساب ولا الإقفال ولا بيبعت للخادم.
     ⚠️ الترتيب **جوّه كل قسم** (`.rv-list`) والأقسام نفسها ما بتتحركش: الأقسام
        فئات مالية (داخل/خارج · كاش/فيزا/غير مدفوع) والإقفال مربوط بيها
        (`DailyReview::BLOCKING`)، فتحريكها بيكسر معنى الشاشة وإجمالياتها. */
  var sortSel = document.getElementById('rvSort');
  var SORT_KEY = 'almotawakel:closing-sort';
  function sortRows(by) {
    document.querySelectorAll('.rv-list').forEach(function (list) {
      var rows = Array.prototype.slice.call(list.querySelectorAll('.rv-item'));
      if (rows.length < 2) { return; }
      rows.sort(function (a, b) {
        var d = 0;
        if (by === 'amount-desc' || by === 'amount-asc') {
          var na = parseFloat(a.dataset.amount) || 0, nb = parseFloat(b.dataset.amount) || 0;
          d = by === 'amount-desc' ? nb - na : na - nb;
        } else if (by) {
          var key = 's' + by.charAt(0).toUpperCase() + by.slice(1);
          var va = a.dataset[key] || '', vb = b.dataset[key] || '';
          /* البند اللي مالوش قيمة (مفيش مندوب مثلاً) بيروح آخر القايمة —
             ما ينفعش يتصدّر ترتيبًا هو مش داخل فيه أصلاً. */
          if (va === '' && vb !== '') { return 1; }
          if (vb === '' && va !== '') { return -1; }
          d = va.localeCompare(vb, 'ar');
        }
        /* الترتيب الثانوي دايمًا الترتيب الأصلي — فالنتيجة ثابتة ومتوقّعة،
           والبنود المتساوية ما بتتنطّطش من ترتيب لترتيب. */
        return d || ((+a.dataset.seq || 0) - (+b.dataset.seq || 0));
      });
      rows.forEach(function (r) { list.appendChild(r); });
    });
  }
  if (sortSel) {
    try {
      var saved = localStorage.getItem(SORT_KEY);
      if (saved && sortSel.querySelector('option[value="' + saved + '"]')) {
        sortSel.value = saved;
        sortRows(saved);
      }
    } catch (e) { /* التخزين مقفول في المتصفح — الترتيب الافتراضي وخلاص */ }
    sortSel.addEventListener('change', function () {
      sortRows(sortSel.value);
      try { localStorage.setItem(SORT_KEY, sortSel.value); } catch (e) {}
    });
  }
})();
</script>

<?php if ($canReview): ?>
<script>
/* مراجعة بنود التقفيل — حفظ فوري لكل علامة (مع مسار رجوع كامل بلا JS).
   ⚠ الفورم بيفضل موجود وشغّال: لو النداء فشل بنرجّع العلامة زي ما كانت
      وبنوضّح السبب، فمستحيل المالك يفتكر إنه علّم بند وهو ما اتحفظش. */
(function () {
  var page = document.getElementById('clPage');
  var form = document.getElementById('rvForm');
  if (!page || !form) { return; }

  var URL_ITEM  = <?= js_json(url('closing/review')) ?>;
  var URL_GROUP = <?= js_json(url('closing/review-group')) ?>;
  var CSRF      = <?= js_json(\App\Core\Auth::csrf()) ?>;
  var DATE      = page.dataset.date;

  var bar = document.getElementById('clBar');
  var closeBtn = document.getElementById('clCloseBtn');
  page.classList.add('js-review');   // الحفظ بقى تلقائي → الزرار الاحتياطي يتخفى

  function post(url, data) {
    var body = new FormData();
    body.append('_csrf', CSRF);
    body.append('date', DATE);
    Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
    return fetch(url, { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json().catch(function () { throw new Error('رد غير متوقّع من الخادم'); }); })
      .then(function (j) { if (!j || !j.ok) { throw new Error((j && j.flash) || 'تعذّر الحفظ'); } return j; });
  }

  /* ⚠ ممنوع alert/confirm الأصلية في الواجهات (قاعدة verify_forms_feedback):
     الرسالة بتتعرض في شريط ثابت جوّه الشاشة، و`AppUI.notice` فوقه لو موجودة. */
  var errBar = document.getElementById('rvError');
  function fail(msg) {
    if (errBar) {
      errBar.textContent = '⚠ ما اتحفظتش المراجعة: ' + msg;
      errBar.hidden = false;
    }
    // ⚠ توقيع الدالة `notice(message, title)` — التاني **نص** مش كائن.
    //    تمرير كائن كان بيطبع «[object Object]» مكان العنوان. اتصادف فعليًّا.
    if (window.AppUI && window.AppUI.notice) { window.AppUI.notice(msg, 'ما اتحفظتش المراجعة'); }
  }
  function clearFail() { if (errBar) { errBar.hidden = true; } }

  /* ترسم حالة اليوم كله من رد الخادم — الخادم هو مصدر الحقيقة مش المتصفح. */
  function paint(j) {
    var t = j.totals || {};
    var pct = t.percent || 0;
    set('rvPercent', pct);
    set('rvReviewed', t.blocking_reviewed);
    set('rvCntAll', t.count);
    set('rvCntPending', t.pending);
    set('rvCntDone', t.reviewed);
    var pendTxt = document.getElementById('rvPendingTxt');
    if (pendTxt) { pendTxt.textContent = t.blocking_pending ? ('فاضل ' + t.blocking_pending + ' بند') : 'كل البنود اتراجعت ✓'; }
    var g = document.getElementById('rvGauge');
    if (g) { g.classList.toggle('done', !!j.complete); }
    var barEl = document.getElementById('rvBar');
    if (barEl) { barEl.style.width = pct + '%'; }

    (j.items || []).forEach(function (it) {
      var row = form.querySelector('.rv-item[data-key="' + cssEscape(it.key) + '"]');
      if (!row) { return; }
      row.classList.toggle('is-done', !!it.reviewed);
      row.classList.toggle('is-stale', !!it.stale);
      var box = row.querySelector('.rv-check');
      if (box) { box.checked = !!it.reviewed; }
      var stamp = row.querySelector('.rv-stamp');
      if (stamp) {
        stamp.hidden = !it.reviewed;
        if (it.reviewed) { stamp.textContent = '✓ راجعه ' + (it.by || '—') + (it.at ? ' — ' + it.at : ''); }
      }
      if (!it.stale) { var w = row.querySelector('.rv-warn'); if (w) { w.remove(); } }
    });

    Object.keys(j.groups || {}).forEach(function (key) {
      var gi = j.groups[key];
      var el = document.querySelector('.rv-group[data-group="' + cssEscape(key) + '"]');
      if (!el) { return; }
      el.classList.toggle('is-done', !!gi.done);
      var cnt = el.querySelector('[data-count-for]');
      if (cnt) { cnt.textContent = gi.reviewed + '/' + gi.count; }
      var all = el.querySelector('[data-group-toggle]');
      if (all) { all.textContent = gi.done ? 'شيل علامات القسم' : 'علّم القسم كله'; }
    });

    if (bar) {
      // القيمة "1" بالتحديد — مُحدِّد الـCSS هو [data-ready="1"] وtoggleAttribute بتحط قيمة فاضية
      if (j.complete) { bar.setAttribute('data-ready', '1'); } else { bar.removeAttribute('data-ready'); }
      set('clBarNum', (t.blocking_reviewed || 0) + '/' + (t.blocking || 0));
      var msg = document.getElementById('clBarMsg');
      if (msg) { msg.textContent = j.complete ? 'كل البنود اتراجعت' : 'بند اتراجع'; }
      var fill = document.getElementById('clBarFill');
      if (fill) { fill.style.width = pct + '%'; }
      var go = document.getElementById('clBarGo');
      if (go) { go.textContent = j.complete ? 'اقفل اليوم ↓' : 'اذهب للإقفال ↓'; }
    }
    if (closeBtn) { closeBtn.disabled = !j.complete; }
    var blockTxt = document.getElementById('clBlockTxt');
    if (blockTxt && !j.complete) { blockTxt.textContent = 'فاضل ' + (t.blocking_pending || 0) + ' بند من غير علامة مراجعة.'; }
    /* ⚠️ أدوات العرض في سكربت مستقل (بيشتغل على اليوم المتقفل كمان)، فالنداء
       دفاعي: البند اللي اتعلّم لازم يختفي فورًا لو الفلتر «بدون علامة» شغّال. */
    if (window.__rvApplyFilter) { window.__rvApplyFilter(); }
  }

  function set(id, val) { var el = document.getElementById(id); if (el && val !== undefined && val !== null) { el.textContent = val; } }
  function cssEscape(s) { return String(s).replace(/["\\]/g, '\\$&'); }

  /* علامة بند واحد */
  form.addEventListener('change', function (e) {
    var box = e.target;
    if (!box.classList || !box.classList.contains('rv-check')) { return; }
    var wanted = box.checked;
    box.disabled = true;
    post(URL_ITEM, { type: box.dataset.type, item_id: box.dataset.id, state: wanted ? '1' : '0' })
      .then(function (j) { clearFail(); paint(j); })
      .catch(function (err) { box.checked = !wanted; fail(err.message || 'تعذّر الحفظ'); })
      .then(function () { box.disabled = false; });
  });

  /* علامة قسم كامل */
  document.querySelectorAll('[data-group-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var key = btn.dataset.groupToggle;
      var el = document.querySelector('.rv-group[data-group="' + cssEscape(key) + '"]');
      var wanted = !(el && el.classList.contains('is-done'));
      btn.disabled = true;
      post(URL_GROUP, { group: key, state: wanted ? '1' : '0' })
        .then(function (j) { clearFail(); paint(j); })
        .catch(function (err) { fail(err.message || 'تعذّر الحفظ'); })
        .then(function () { btn.disabled = false; });
    });
  });


  /* الضغط على أي مكان في صف البند بيعلّمه — أسهل بكتير على الهاتف */
  form.addEventListener('click', function (e) {
    if (e.target.closest('a, label, input, button')) { return; }
    var row = e.target.closest('.rv-item');
    if (!row) { return; }
    var box = row.querySelector('.rv-check');
    if (box && !box.disabled) { box.checked = !box.checked; box.dispatchEvent(new Event('change', { bubbles: true })); }
  });
})();
</script>
<?php endif; ?>
