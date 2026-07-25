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
      <a class="btn ghost sm" href="<?= url('closing/print?date='.urlencode($date)) ?>" target="_blank">🖨️ طباعة</a>
    </div>
  </header>

  <!-- ═══════════ معادلة رصيد اليوم ═══════════ -->
  <section class="cl-flow" aria-label="معادلة رصيد اليوم">
    <div class="cl-cell"><span>رصيد أول اليوم</span><b><?= money($prevBalance,false) ?></b></div>
    <i class="op plus">+</i>
    <div class="cl-cell in"><span>عهدة مضافة</span><b><?= money($totTopups,false) ?></b></div>
    <i class="op minus">−</i>
    <div class="cl-cell out"><span>مصروفات نقدية</span><b><?= money($totExpense,false) ?></b></div>
    <i class="op minus">−</i>
    <div class="cl-cell out"><span>عهد السائقين</span><b><?= money($totCustodyExp,false) ?></b><small>بعد خصم <?= intf($rate) ?> لكل بوليصة</small></div>
    <i class="op eq">=</i>
    <div class="cl-cell result <?= $carried < 0 ? 'negative' : '' ?>">
      <span><?= $isClosed ? 'المرحّل المثبّت' : 'المرحّل للغد' ?></span>
      <b><?= money($carried,false) ?></b>
      <small><?= $isClosed ? 'مجمّد — لا يتغيّر' : 'يتغيّر مع أي إدخال' ?></small>
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
      <div class="rv-head-txt">
        <h2>راجع بنود اليوم</h2>
        <p>بُص على كل بند وعلّمه ✓. الإقفال بيتفتح لما كل البنود النقدية تتعلّم.</p>
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
      <div class="rv-locked">🔒 اليوم متقفل — المراجعة مثبّتة كما هي. افتح الإقفال لو محتاج تعدّل.</div>
    <?php endif; ?>

    <div class="rv-error" id="rvError" role="alert" hidden></div>

    <?php if ((int)$t['stale'] > 0): ?>
      <div class="rv-stale-note">⚠️ فيه <?= intf((int)$t['stale']) ?> بند اتغيّر مبلغه بعد ما اتراجع — علامته اتشالت لوحدها ومحتاج مراجعة تانية.</div>
    <?php endif; ?>

    <div class="rv-tools">
      <label class="rv-search">
        <span aria-hidden="true">🔎</span>
        <input type="search" id="rvSearch" placeholder="دوّر بالإذن أو العميل أو البيان…" aria-label="بحث في بنود اليوم" autocomplete="off">
      </label>
      <div class="rv-filters" role="group" aria-label="فلترة البنود">
        <button type="button" class="rv-fbtn active" data-filter="all">الكل <b id="rvCntAll"><?= intf((int)$t['count']) ?></b></button>
        <button type="button" class="rv-fbtn" data-filter="pending">بدون علامة <b id="rvCntPending"><?= intf((int)$t['pending']) ?></b></button>
        <button type="button" class="rv-fbtn" data-filter="done">متراجَع <b id="rvCntDone"><?= intf((int)$t['reviewed']) ?></b></button>
      </div>
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
            <div class="rv-gtitle">
              <b><?= e($g['title']) ?></b>
              <small><?= e($g['hint']) ?></small>
            </div>
            <div class="rv-gstat">
              <span class="rv-gcount" data-count-for="<?= e($key) ?>"><?= intf($g['reviewed']) ?>/<?= intf($g['count']) ?></span>
              <b class="rv-gsum <?= $g['dir'] === 'in' ? 'in' : 'out' ?>"><?= money($g['total'],false) ?></b>
            </div>
            <?php if ($canReview): ?>
              <button type="button" class="rv-gall" data-group-toggle="<?= e($key) ?>">
                <?= $g['done'] ? 'شيل علامات القسم' : 'علّم القسم كله' ?>
              </button>
            <?php endif; ?>
          </header>

          <?php if ($g['kind'] === 'info'): ?>
            <div class="rv-ginfo">البنود دي اتدفعت من كارت المكتب مش من الدرج، فما بتمنعش الإقفال — علّمها لو راجعتها.</div>
          <?php endif; ?>

          <div class="rv-list">
            <?php foreach ($g['items'] as $it): ?>
              <?php
                $search = trim(implode(' ', array_filter([
                    $it['ref'], $it['title'], $it['party'], $it['note'], $it['ref_no'], $it['movement'],
                    $it['company'], $it['client'], $it['destination'], $it['containers'],
                    $it['by_rep'], $it['car'], $it['car_owner'], $it['permit_ref'],
                    $it['agency'], $it['shipping_co'], $it['port'], $it['owner'],
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
                // عنوان البند = اسمه المكتوب (اسم الإيصال) إن وُجد، وإلا نوعه
                $headline = trim((string) $it['note']) !== '' && $it['type'] === 'stage'
                    ? $it['note'] : $it['title'];
                $subline  = $headline === $it['title'] ? null : $it['title'];
              ?>
              <div class="rv-item<?= $it['reviewed'] ? ' is-done' : '' ?><?= $it['stale'] ? ' is-stale' : '' ?>"
                   data-key="<?= e($it['key']) ?>"
                   data-group="<?= e($key) ?>"
                   data-blocking="<?= $g['kind'] === 'cash' ? '1' : '0' ?>"
                   data-search="<?= e(mb_strtolower($search)) ?>">
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
                    <?php if ($it['containers'] !== ''): ?><span class="rv-tag box">📦 <?= e($it['containers']) ?></span><?php endif; ?>
                    <?php if ($it['movement']): ?><span class="rv-tag"><?= e($it['movement']) ?></span><?php endif; ?>
                    <?php if (!empty($it['visa'])): ?><span class="rv-tag visa">💳 فيزا</span><?php endif; ?>
                    <?php if ($it['by_rep']): ?><span class="rv-tag rep">🧑‍💼 دفعها المندوب: <?= e($it['by_rep']) ?></span><?php endif; ?>
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
                    ✓ راجعه <?= e($it['reviewed_by'] ?: '—') ?><?= $it['reviewed_at'] ? ' — '.e($it['reviewed_at']) : '' ?>
                  </div>
                  <?php if ($it['stale']): ?>
                    <div class="rv-warn">⚠️ المبلغ اتغيّر بعد المراجعة — راجعه تاني</div>
                  <?php endif; ?>
                </div>

                <div class="rv-money">
                  <b class="rv-amt <?= $g['dir'] === 'in' ? 'in' : 'out' ?>"><?= money($it['amount'],false) ?></b>
                  <?php if ((int)$it['qty'] > 1 && $it['unit'] !== null): ?>
                    <span class="rv-calc"><?= intf($it['qty']) ?> × <?= money($it['unit'],false) ?></span>
                  <?php endif; ?>
                  <?php if ($it['actual'] !== null): ?>
                    <span class="rv-actual" title="السعر الفعلي للعميل">فعلي: <?= money($it['actual'],false) ?></span>
                  <?php elseif ($it['type'] === 'stage' && !$it['visa']): ?>
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
          <span>✓</span>
          <b>مفيش أي حركة مسجّلة في يوم <?= e(dfmt($date)) ?></b>
          <small>تقدر تقفل اليوم على طول، أو تراجع تاريخ تاني من فوق.</small>
        </div>
      <?php endif; ?>

      <div class="rv-nores" id="rvNoRes" hidden>مفيش بند مطابق للبحث.</div>

      <?php if ($canReview): ?>
        <div class="rv-manual">
          <button class="btn gold" type="submit">💾 احفظ المراجعة</button>
          <small>الحفظ بيتم تلقائيًّا مع كل علامة. الزر ده احتياطي لو الجافاسكريبت متعطّل.</small>
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
        <span>الرصيد المرحّل مثبّت على <strong><?= money($carried) ?></strong><?= $closedBy ? ' — بواسطة '.e($closedBy) : '' ?><?= $closedAt ? ' — '.e($closedAt) : '' ?>.</span>
      <?php elseif ($complete): ?>
        <b>كل البنود اتراجعت — جاهز للإقفال</b>
        <span>هيتثبّت رصيد <strong><?= money($carried) ?></strong> كرصيد أول اليوم التالي، وهيتقفل الكتابة على اليوم ده.</span>
      <?php else: ?>
        <b>الإقفال لسه مقفول</b>
        <span id="clBlockTxt">فاضل <?= intf((int)$t['blocking_pending']) ?> بند من غير علامة مراجعة.</span>
      <?php endif; ?>
      <?php if ($rv['last_at']): ?>
        <small class="cl-close-meta">آخر مراجعة: <?= e($rv['last_at']) ?><?= $rv['last_by'] ? ' — '.e($rv['last_by']) : '' ?></small>
      <?php endif; ?>
    </div>
    <div class="cl-close-act">
      <?php if ($isClosed && $canReopen): ?>
        <form method="post" action="<?= url('closing/reopen') ?>" class="confirm-financial" data-confirm="فتح إقفال يوم <?= e(dfmt($date)) ?>؟ هتقدر تعدّل، ولازم تقفله تاني بعدها.">
          <?= csrfField() ?><input type="hidden" name="date" value="<?= e($date) ?>">
          <button class="btn" type="submit">🔓 فتح الإقفال</button>
        </form>
      <?php elseif ($isClosed): ?>
        <small>لفتح اليوم ده، افتح الأيام الأحدث الأول.<br>آخر يوم متقفل: <?= e(dfmt($lastClosed)) ?></small>
      <?php else: ?>
        <form method="post" action="<?= url('closing/close') ?>" class="confirm-financial" id="clCloseForm"
              data-confirm="إقفال يوم <?= e(dfmt($date)) ?> على رصيد <?= e(money($carried,false)) ?>؟ الرقم هيتثبّت والكتابة على اليوم هتتمنع.">
          <?= csrfField() ?><input type="hidden" name="date" value="<?= e($date) ?>">
          <button class="btn gold" type="submit" id="clCloseBtn" <?= $complete ? '' : 'disabled' ?>>
            🔒 اقفل اليوم على <?= money($carried,false) ?>
          </button>
        </form>
      <?php endif; ?>
    </div>
  </section>

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
.cl-cell{flex:1 1 140px;display:grid;align-content:center;gap:2px;padding:var(--space-3);
  border:1px solid var(--line);border-radius:var(--radius-2);background:var(--zebra)}
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
  border-radius:8px;background:var(--white);transition:all var(--dur-fast) var(--ease)}
.rv-box:after{content:"✓";font-size:var(--fs-2);font-weight:var(--fw-black);color:var(--white);
  transform:scale(.4);opacity:0;transition:all var(--dur-fast) var(--ease)}
.rv-check:checked + .rv-box{background:var(--green);border-color:var(--green)}
.rv-check:checked + .rv-box:after{transform:scale(1);opacity:1}
.rv-check:focus-visible + .rv-box{box-shadow:0 0 0 3px rgba(30,110,60,.25)}
.rv-check:disabled + .rv-box{background:var(--zebra);border-color:var(--line);cursor:not-allowed}
.rv-check:disabled:checked + .rv-box{background:var(--grey);border-color:var(--grey)}
/* ⚠️ التسلسل البصري مقصود (طلب المالك: «اسم البند صغير جدًا ومش ظاهر»):
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
/* ⚠️ الإخفاء بالجافاسكريبت نفسه (`js-review` بتتحط من السكربت) مش من الخادم:
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

<?php if ($canReview): ?>
<script>
/* مراجعة بنود التقفيل — حفظ فوري لكل علامة (مع مسار رجوع كامل بلا JS).
   ⚠️ الفورم بيفضل موجود وشغّال: لو النداء فشل بنرجّع العلامة زي ما كانت
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

  /* ⚠️ ممنوع alert/confirm الأصلية في الواجهات (قاعدة verify_forms_feedback):
     الرسالة بتتعرض في شريط ثابت جوّه الشاشة، و`AppUI.notice` فوقه لو موجودة. */
  var errBar = document.getElementById('rvError');
  function fail(msg) {
    if (errBar) {
      errBar.textContent = '⚠️ ما اتحفظتش المراجعة: ' + msg;
      errBar.hidden = false;
    }
    // ⚠️ توقيع الدالة `notice(message, title)` — التاني **نص** مش كائن.
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
    applyFilter();
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

  /* بحث وفلترة — كلها في المتصفح بلا أي طلب للخادم */
  var search = document.getElementById('rvSearch');
  var mode = 'all';
  function applyFilter() {
    var q = (search && search.value || '').trim().toLowerCase();
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
  if (search) { search.addEventListener('input', applyFilter); }
  document.querySelectorAll('.rv-fbtn').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('.rv-fbtn').forEach(function (x) { x.classList.remove('active'); });
      b.classList.add('active');
      mode = b.dataset.filter;
      applyFilter();
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
