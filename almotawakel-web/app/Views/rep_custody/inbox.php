<?php
use App\Core\FormState;
use App\Models\Permit;
use App\Models\RepInbox;
use App\Models\RepMiscKind;
use App\Models\RepPortal;

/**
 * صندوق مراجعة بنود المناديب — **معاد بناؤه** (2026-07-25).
 *
 * اللي اتصلح في النسخة دي (كل واحدة كانت بتسبّب لغبطة فعلية):
 *   ١) **التاريخ بقى إجراء من الدرجة الأولى**: كان مدفونًا جوّه درج التعديل، والتغيير
 *      الجماعي كان **سلسلة بوب-أبين** بتطلب كتابة التاريخ بالإيد `YYYY-MM-DD` بلا
 *      أي منتقي تواريخ. دلوقتي فيه لوحة تاريخ واحدة (`<input type="date">` حقيقي +
 *      اختصارات) بتشتغل على المعلَّم، وزر «غيّر تاريخ اليوم كله» في رأس كل يوم.
 *   ٢) **الاختيار بيبدأ فاضي**: كانت كل البنود معلَّمة تلقائيًّا، فضغطة واحدة على
 *      «اعتمد المعلَّم» تعتمد كل حاجة. دلوقتي المالك بيختار بوعي، وشريط إجراءات
 *      بيظهر لما يعلّم وبيقول **عدد وإجمالي** اللي هيتنفّذ عليه.
 *   ٣) **الاختيار بقى على مستوى الصفحة**: كان كل يوم فورم مستقل فما ينفعش تعلّم
 *      بنودًا من يومين. دلوقتي فورم واحد للصفحة كلها.
 *   ٤) **تنبيه اليوم المتقفل**: البند اللي تاريخه في يوم متقفل **اعتماده بيترفض**
 *      (Closing::guard). كان بيترفض بعد الضغط بلا سبب واضح؛ دلوقتي متعلّم قبلها
 *      بشارة 🔒 + عدّاد فوق + اللوحة بتحذّر لو التاريخ الجديد متقفل.
 *   ٥) **فلترة وبحث** بالمندوب/اليوم/النص، وتجميع مطوي لكل مندوب.
 *
 * ⚠️ **حقول درج التعديل مربوطة بفورمها بـ`form="eNf"`** وهي **جوّه** فورم الصفحة.
 *    ده مقصود وسليم: سمة `form` بتنقل ملكية الحقل للفورم المذكور، فأسماء زي
 *    `entry_date`/`note`/`qty` ما بتتصادمش مع حقول الإجراء الجماعي. لو شيلت
 *    السمة دي، التعديل الفردي هيبعت قيمه مع الاعتماد الجماعي ويبوّظ الاتنين.
 *
 * ⚠️ `approve_now` **حقل مخفي بيتملّى بالـJS** — مش `name/value` على الزرار.
 *    الزرار المربوط بفورم بـ`form=""` بيبعت قيمته **حتى لو مدوستش عليه** (متحقَّق
 *    فعليًا في v37)، فكان كل تعديل بيتعتمد غصب.
 *
 * ⚠️ **الحذف النهائي** (زرار 🗑): بيشيل البند من الصندوق خالص وبينزّله سلة
 *    المحذوفات في `/audit`. متاح للمالك والمدير بس (`$canDelete` — والمنع
 *    الحقيقي في `Access::OWNER_MANAGER_ONLY` مش في الإخفاء ده)، و**البند
 *    المعتمد بيترفض** لأن له بند فعلي على الإذن.
 *
 * ⚠️ **عدسة المندوب `?rep=` على الخادم** (2026-08-02): كان الرابط من كارت
 *    المندوب بيوَدّي على كل المناديب فالمالك يدوّر وسطهم. الفلترة بقت على
 *    الخادم عشان الرابط يتشارك ويشتغل مع الرجوع بالمتصفح، وشرايح المناديب فوق
 *    بتنقّل بينهم بضغطة. الفلاتر التانية (بحث/يوم/متقفل) لسه في المتصفح فوقها.
 *
 * @var string $status @var array $groups @var array $rows @var int $pending
 * @var array $inboxState @var ?string $lockedThrough @var string $workDate
 * ⚠️ **تعديل البند المعتمد (v95)**: تبويب «معتمد» بقى فيه درج تعديل كامل —
 *    نفس بارشيال درج «مستني» بالحرف (`partials/rep_submission_edit`) عشان
 *    الحقول وقواعدها تفضل واحدة. الفرق إن الحفظ بيروح
 *    `RepCustodyController@editApproved` اللي بيعدّل **سجل المراجعة والبند
 *    الفعلي مع بعض** — والحُرّاس المالية كلها في `RepInbox::updateApproved`.
 *
 * @var bool $canDelete @var int $repId @var string $repName @var string $dayFilter
 * @var array $byRep @var ?array $repDayPocket @var ?array $pager
 * @var bool $canEditApproved
 */
$canDelete = $canDelete ?? false;
$canEditApproved = $canEditApproved ?? false;
$repId     = (int) ($repId ?? 0);
$repName   = (string) ($repName ?? '');
$dayFilter = (string) ($dayFilter ?? '');
$byRep     = $byRep ?? [];
$repDayPocket = $repDayPocket ?? null;
$pager     = $pager ?? null;

$inboxState  = $inboxState ?? ['token' => FormState::token(), 'old' => [], 'errors' => [], 'back' => 'rep-custody/inbox?status=' . $status];
$inboxOld    = $inboxState['old'];
$inboxErrors = $inboxState['errors'];
$failedAction = (string) ($inboxOld['_action'] ?? '');
$failedSid    = (int) ($inboxOld['submission_id'] ?? 0);
$failedGroup  = (string) ($inboxOld['_group'] ?? '');
$failedIds    = array_map('intval', (array) ($inboxOld['ids'] ?? []));
$bulkFailed   = in_array($failedAction, ['approve-many', 'bulk-edit', 'reject-many'], true);

/** رمز FormState لكل إجراء فردي: المحاولة الفاشلة بترجع بنفس رمزها عشان تعيد الإرسال. */
$rowToken = static fn(string $action, int $sid): string =>
    $failedAction === $action && $failedSid === $sid ? $inboxState['token'] : FormState::token();

/* ⚠️ **كل رابط لازم يحمل العدسة** (`rep`/`day`): من غير كده تبديل التبويب
     بيرجّع المالك لكل المناديب وهو أصلاً بيراجع مندوبًا واحدًا. */
$lens = static function (string $st, ?int $rep = null, ?string $dy = null) use ($repId, $dayFilter): string {
    $r = $rep ?? $repId;
    $d = $dy  ?? $dayFilter;
    $q = 'rep-custody/inbox?status=' . rawurlencode($st);
    if ($r > 0)  { $q .= '&rep=' . (int) $r; }
    if ($d !== '') { $q .= '&day=' . rawurlencode($d); }
    return url($q);
};

$tab = static fn(string $k, string $lbl): string =>
    '<a class="btn ' . ($status === $k ? '' : 'ghost') . '" href="' . $lens($k) . '">' . $lbl . '</a>';

$today     = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$isLocked  = static fn(?string $d): bool => $lockedThrough !== null && $d !== null && $d <= $lockedThrough;

/* ⚠️ البند المعتمد اللي **بنده الفعلي اتمسح** من الإذن (`DeletionPolicy` بتفكّ
   الربط وتسيب السجل شاهدًا) مالوش حاجة تتعدّل — الشاشة ما بتعرضش له درجًا،
   و`RepInbox::updateApproved` بترفضه على الخادم كمان. */
$approvedEditable = static fn(array $r): bool =>
    ($r['stage_id'] ?? null) !== null || ($r['misc_id'] ?? null) !== null;

// إجماليات الشاشة + عدّ البنود اللي اعتمادها هيترفض بسبب يوم متقفل
// ⚠️ ونجمّع أرقام الأذونات في نفس اللفّة عشان نجيب حركاتها **في استعلام واحد**:
//    `RepInbox::movementsOf()` كانت بتتنادى جوّه لوب البنود (N+1).
$totAll = 0.0; $cntAll = 0; $lockedCnt = 0; $miscCnt = 0; $permitIds = [];
foreach ($groups as $g) {
    $totAll += $g['total'];
    $cntAll += $g['count'];
    foreach ($g['days'] as $d) {
        foreach ($d['rows'] as $r) {
            if ($isLocked((string) $r['entry_date'])) { $lockedCnt++; }
            if (RepPortal::isMisc((string) $r['kind'])) { $miscCnt++; }
            elseif ((int) $r['permit_id'] > 0) { $permitIds[] = (int) $r['permit_id']; }
        }
    }
}
$movsByPermit = RepInbox::movementsFor($permitIds);
?>

<div class="toolbar">
  <div class="section-head" style="margin:0">
    <?= icon('inbox', 18) ?> مراجعة بنود المناديب<?= $repId > 0 && $repName !== '' ? ' — ' . e($repName) : '' ?>
  </div>
  <div class="spacer"></div>
  <?= $tab('pending', '⏳ مستني' . ($pending ? " ($pending)" : '')) ?>
  <?= $tab('approved', '✓ معتمد') ?>
  <?= $tab('rejected', '✕ مرفوض') ?>
  <?= $tab('all', 'الكل') ?>
  <a class="btn ghost" href="<?= url('rep-custody') ?>">عهدة المناديب</a>
</div>

<?php /* ═══ شرايح المناديب — عدسة خادمية بضغطة ═══
   ⚠️ **روابط حقيقية مش تبديل بجافاسكريبت**: الرابط يتشارك، والرجوع بالمتصفح
      يشتغل، وبعد أي اعتماد المالك يفضل على نفس المندوب. */ ?>
<?php if ($byRep && (count($byRep) > 1 || $repId > 0)): ?>
  <nav class="ib-lens" aria-label="فلترة بالمندوب">
    <a class="ib-lens-chip<?= $repId === 0 ? ' on' : '' ?>" href="<?= $lens($status, 0) ?>">
      <?= icon('users', 14) ?> كل المناديب
      <b><?= intf(array_sum(array_column($byRep, 'count'))) ?></b>
    </a>
    <?php foreach ($byRep as $rp): ?>
      <a class="ib-lens-chip<?= $repId === $rp['rep_id'] ? ' on' : '' ?>" href="<?= $lens($status, $rp['rep_id']) ?>">
        <?= e($rp['name']) ?> <b><?= intf($rp['count']) ?></b>
      </a>
    <?php endforeach; ?>
    <?php if ($repId > 0): ?>
      <a class="ib-lens-card" href="<?= url('rep-custody/' . $repId) ?>"><?= icon('briefcase', 14) ?> كارت المندوب</a>
    <?php endif; ?>
  </nav>
<?php endif; ?>

<?php if ($status === 'pending'): ?>

  <?php if ($cntAll): ?>
    <?php /* ⚠️ **ممنوع رقم أصمّ**: أرقام الصندوق تفتح تفاصيلها، وأرقام محفظة
             المندوب تنقله إلى حسابه الكامل لأنها أرقام تراكمية وليست قائمة
             البنود الظاهرة فقط. */ ?>
    <?php
      $ibd = static fn (string $w): string => url('rep-custody/drill/' . $w);
    ?>
    <?php partial('stat_grid', ['items' => 4, 'class' => 'mb', 'body' => function () use ($cntAll, $totAll, $groups, $lockedCnt, $ibd, $repId, $repDayPocket) { ?>
      <?php partial('stat', [
        'label' => $repId > 0 ? 'مستني مراجعتك عنده' : 'مستني مراجعتك', 'value' => intf($cntAll), 'unit' => 'بند',
        'icon' => 'hourglass', 'tone' => 'gold',
        'drill' => $ibd('inbox_pending'), 'title' => 'كل البنود المستنية مراجعتك',
      ]); ?>
      <?php partial('stat', [
        'label' => 'إجمالي المبالغ', 'value' => intf($totAll),
        'icon' => 'banknote', 'tone' => 'navy',
        'drill' => $ibd('inbox_pending'), 'title' => 'البنود المستنية ومبالغها',
      ]); ?>
      <?php if ($repId > 0 && $repDayPocket !== null): ?>
        <?php $accountUrl = url('rep-custody/' . $repId . '?tab=account'); ?>
        <?php partial('stat', [
          'label' => 'نزل له عهدة اليوم', 'value' => money0($repDayPocket['deposits']),
          'icon' => 'wallet', 'tone' => 'green',
          'url' => $accountUrl, 'title' => 'العهدة اللي نزلت له في ' . dfmt($repDayPocket['day']),
        ]); ?>
        <?php partial('stat', [
          'label' => 'المتبقي من عهدة اليوم', 'value' => money0($repDayPocket['pocket']),
          'icon' => 'briefcase', 'tone' => $repDayPocket['pocket'] < 0 ? 'red' : 'teal',
          'url' => $accountUrl, 'title' => 'المتبقي الفعلي في ' . dfmt($repDayPocket['day']) . ' بعد البنود المستنية',
        ]); ?>
      <?php else: ?>
        <?php partial('stat', [
          'label' => 'مناديب', 'value' => intf(count($groups)), 'unit' => 'مندوب',
          'icon' => 'users', 'tone' => 'teal',
          'drill' => $ibd('inbox_reps'), 'title' => 'المستنّي مقسّمًا على المناديب',
        ]); ?>
        <?php partial('stat', [
          'label' => 'على أيام متقفلة', 'value' => intf($lockedCnt), 'unit' => $lockedCnt ? 'اعتمادها هيترفض' : 'كله تمام',
          'icon' => $lockedCnt ? 'alert' : 'check-circle', 'tone' => $lockedCnt ? 'red' : 'green',
          'drill' => $ibd('inbox_locked'), 'title' => 'بنود تاريخها في يوم متقفل',
        ]); ?>
      <?php endif; ?>
    <?php }]); ?>
  <?php endif; ?>

  <?php /* ⚠️ لوح الشرح الستة أسطر اتشال (قانون التصميم: **صفر جملة تعليمية**).
           اللي فضل تحذير **وظيفي** واحد: الأيام المتقفلة اعتماد بنودها بيترفض —
           ودي معلومة المالك محتاجها **قبل** ما يدوس مش بعد ما يترفض. */ ?>
  <?php if ($lockedThrough !== null && $lockedCnt > 0): ?>
    <div class="note mb ib-note">
      <?= icon('alert', 14) ?> <strong><?= intf($lockedCnt) ?> بند</strong> تاريخهم في يوم متقفل
      (لحد <?= e(dfmt($lockedThrough)) ?>) — غيّر تاريخهم أو
      <a href="<?= url('closing') ?>">افتح الإقفال</a>، وإلا الاعتماد هيترفض.
    </div>
  <?php endif; ?>

  <?php if (!$groups): ?>
    <div class="card"><div class="empty"><div class="ic"><?= icon('check', 14) ?></div>
      <?= $repId > 0 ? 'مفيش بنود مستنية عند ' . e($repName) . '.' : 'مفيش بنود مستنية. كل حاجة اتراجعت.' ?>
      <?php if ($repId > 0 || $dayFilter !== ''): ?>
        <div class="mt"><a class="btn ghost sm" href="<?= url('rep-custody/inbox?status=pending') ?>">شوف كل المناديب</a></div>
      <?php endif; ?>
    </div></div>
  <?php else: ?>

    <?php if ($bulkFailed): ?>
      <div class="card mb" style="padding:14px 18px"><?= formErrorSummary($inboxErrors) ?></div>
    <?php endif; ?>

    <?php /* فلاتر عرض — كلها في المتصفح على الصفوف المعروضة، بلا أي طلب للخادم */ ?>
    <div class="card mb ib-filters">
      <label class="ib-f-search">
        <span><?= icon('search', 14) ?> بحث</span>
        <input type="search" id="ibSearch" placeholder="بحث" autocomplete="off">
      </label>
      <?php /* ⚠️ قايمة المندوب اتشالت من هنا — بقت **شرايح خادمية** فوق
               الصفحة. قايمة منسدلة تانية لنفس الفلتر كانت هتدّي مصدرين
               للحقيقة: تختار من هنا وتفضل العدسة الخادمية على «الكل». */ ?>
      <label>
        <span>اليوم</span>
        <select id="ibDay">
          <option value="">كل الأيام</option>
          <?php
            $allDays = [];
            foreach ($groups as $g) { foreach ($g['days'] as $d) { $allDays[$d['day']] = true; } }
            krsort($allDays);
            foreach (array_keys($allDays) as $dv): ?>
            <option value="<?= e($dv) ?>"><?= e(dfmt($dv)) ?><?= $isLocked($dv) ? ' 🔒' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="ib-f-chk">
        <input type="checkbox" id="ibOnlyLocked">
        <span>المتقفل بس <?= icon('alert', 14) ?></span>
      </label>
      <button type="button" class="btn ghost sm" id="ibClearFilters">امسح الفلاتر</button>
      <span class="ib-f-count" id="ibCount"></span>
    </div>

    <form method="post" action="<?= url('rep-custody/approve-many') ?>" id="ibForm">
      <?= csrfField() ?>
      <?= formStateFields($bulkFailed ? $inboxState['token'] : FormState::token(), $inboxState['back']) ?>
      <input type="hidden" name="_group" value="inbox">
      <?php /* حقول الإجراء الجماعي — بتتملّى من اللوحات، وملكيتها للفورم ده */ ?>
      <input type="hidden" name="entry_date"  id="ibBulkDate"   value="">
      <input type="hidden" name="kind"        id="ibBulkKind"   value="">
      <input type="hidden" name="actual_unit" id="ibBulkActual" value="">
      <input type="hidden" name="reason"      id="ibBulkReason" value="">

      <?php foreach ($groups as $g): ?>
        <section class="card mb ib-rep" data-rep="<?= (int) $g['rep_id'] ?>">
          <header class="ib-rep-head">
            <label class="ib-pick-all" title="علّم كل بنود المندوب ده">
              <input type="checkbox" class="js-ib-rep-all">
            </label>
            <div class="ib-rep-id">
              <a href="<?= url('rep-custody/' . $g['rep_id']) ?>" class="ib-rep-name"><?= icon('user', 14) ?>‍<?= icon('briefcase', 14) ?> <?= e($g['rep_name']) ?></a>
              <span class="ib-rep-sub"><?= intf($g['count']) ?> بند مستني · إجمالي <?= money($g['total'], false) ?></span>
            </div>
            <button type="button" class="btn ghost sm ib-collapse" aria-expanded="true">اطوِ</button>
          </header>

          <div class="ib-rep-body">
          <?php foreach ($g['days'] as $d): ?>
            <?php $dayLocked = $isLocked((string) $d['day']); ?>
            <div class="ib-day<?= $dayLocked ? ' is-locked' : '' ?>" data-day="<?= e($d['day']) ?>">
              <div class="ib-day-head">
                <label class="ib-pick-all" title="علّم كل بنود اليوم ده">
                  <input type="checkbox" class="js-ib-day-all">
                </label>
                <span class="ib-day-date">
                  <?= icon('calendar', 14) ?> <?= e(dfmt($d['day'])) ?>
                  <?php if ($d['day'] === $today): ?><span class="pill st-done">النهاردة</span><?php endif; ?>
                  <?php if ($dayLocked): ?>
                    <span class="pill st-late" title="اليوم متقفل — اعتماد بنوده هيترفض لحد ما تغيّر تاريخها أو تفتح الإقفال"><?= icon('alert', 14) ?> متقفل</span>
                  <?php endif; ?>
                </span>
                <span class="ib-day-tot"><?= count($d['rows']) ?> بند · <?= money($d['total'], false) ?></span>
                <?php /* ★ تغيير تاريخ يوم كامل بضغطة — كان محتاج بوب-أبين وكتابة تاريخ بالإيد */ ?>
                <button type="button" class="btn ghost sm js-ib-day-date"><?= icon('calendar', 14) ?> غيّر تاريخ اليوم</button>
              </div>

              <div class="ib-items">
                <?php foreach ($d['rows'] as $r): ?>
                  <?php
                    $sid    = (int) $r['id'];
                    $isMisc = RepPortal::isMisc((string) $r['kind']);
                    $movs   = $isMisc ? [] : ($movsByPermit[(int) $r['permit_id']] ?? []);
                    $eid    = 'e' . $sid;
                    $hasActual  = !$isMisc && $r['actual_unit'] !== null && $r['actual_unit'] !== '';
                    $rowFailed  = $failedSid === $sid;
                    $editFailed = $rowFailed && $failedAction === 'edit';
                    $rowLocked  = $isLocked((string) $r['entry_date']);
                    // ⚠️ قبل خط الفصل = اعتماده **بيترفض دايمًا** (`epochBlock`).
                    //    الشاشة بتقول ده قبل الضغط بدل ما المالك يدوس ويستغرب.
                    $preEpoch   = RepInbox::isPreEpoch((string) $r['entry_date']);
                    $inReview   = !empty($r['review_started_at']);
                    // نص البحث الحيّ — كله في سمة واحدة عشان الفلترة تبقى سطرًا واحدًا
                    // ⚠️ `search_norm` مش `mb_strtolower`: توحيد الألف والتاء المربوطة
                    //    والياء إلزامي وإلا «إكرامية» ما تلاقيش «اكراميه» (نفس الباج
                    //    اللي اتصاد في شاشة التقفيل). والواجهة بتطبّع بنفس الدالة.
                    $haystack = search_norm(implode(' ', array_filter([
                        $r['note'], $r['permit_no'], $r['company'], $r['client'], $r['ref_no'], $g['rep_name'],
                    ])));
                  ?>
                  <article class="ib-item<?= $inReview ? ' in-review' : '' ?><?= $rowLocked ? ' row-locked' : '' ?>"
                           id="submission-<?= $sid ?>"
                           data-rep="<?= (int) $g['rep_id'] ?>" data-day="<?= e($r['entry_date']) ?>"
                           data-locked="<?= $rowLocked ? '1' : '0' ?>" data-amount="<?= e((string) round((float) $r['amount'], 2)) ?>"
                           data-search="<?= e($haystack) ?>">

                    <?php if ($rowFailed && !$editFailed): ?>
                      <div class="ib-row-error">
                        <?= formErrorSummary($inboxErrors) ?>
                        <?php if ($failedAction === 'reject'): ?>
                          <div class="ib-retry">
                            <label>سبب الرفض
                              <input type="text" name="reason" form="r<?= $sid ?>f"
                                     value="<?= e($inboxOld['reason'] ?? '') ?>" required<?= fieldErrorAttrs('reason', $inboxErrors) ?>>
                              <?= fieldError('reason', $inboxErrors) ?>
                            </label>
                            <button class="btn" type="submit" form="r<?= $sid ?>f">أعد محاولة الرفض</button>
                          </div>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>

                    <div class="ib-row">
                      <label class="ib-pick" title="علّم البند">
                        <input type="checkbox" name="ids[]" value="<?= $sid ?>" class="js-ib-one"
                               data-amount="<?= e((string) round((float) $r['amount'], 2)) ?>"
                               <?= $bulkFailed && in_array($sid, $failedIds, true) ? 'checked' : '' ?>>
                      </label>

                      <?php if ($r['photo']): ?>
                        <a class="ib-photo" href="<?= url('attachments/submission/' . $sid) ?>" target="_blank"
                           title="افتح صورة الإيصال">
                          <img src="<?= url('attachments/submission/' . $sid) ?>" alt="صورة الإيصال" loading="lazy">
                        </a>
                      <?php else: ?>
                        <span class="ib-photo none" title="مبعتش صورة"><?= icon('file-text', 14) ?></span>
                      <?php endif; ?>

                      <div class="ib-main">
                        <div class="ib-title">
                          <span class="ib-note"><?= e($r['note']) ?></span>
                          <?php if ($isMisc): ?>
                            <span class="pill ib-misc" title="مصروف عام مش على إذن — بيروح للمصروفات مش لحساب أي عميل"><?= icon('anchor', 14) ?> نثرية</span>
                            <?php /* v71 — وسم النثرية. مفيش وسم = بند قديم قبل الترحيل، والشارة بتقولها
                                     عشان المالك يعرف إنه لازم يختار قبل الاعتماد بدل ما يفتكرها متوسّمة. */ ?>
                            <?php $mk = (string) ($r['misc_kind'] ?? ''); ?>
                            <span class="pill ib-mkind<?= $mk === '' ? ' none' : '' ?>">
                              <?= $mk === ''
                                    ? icon('alert', 14) . ' بلا نوع'
                                    : icon(RepMiscKind::icon($mk), 14) . ' ' . e(RepMiscKind::label($mk)) ?>
                            </span>
                          <?php else: ?>
                            <span class="pill <?= $r['kind'] === 'receipt' ? 'st-done' : 'st-partial' ?>">
                              <?= $r['kind'] === 'receipt' ? 'إيصال' : 'مصروف' ?></span>
                          <?php endif; ?>
                          <?php if (!empty($r['is_visa'])): ?>
                            <span class="pill visa-pill" title="مدفوع بكارت الفيزا — يتخصم من الكارت، لا من الخزنة ولا من عهدة المندوب"><?= icon('credit-card', 14) ?> فيزا</span>
                          <?php endif; ?>
                          <?php if (!empty($r['unpaid'])): ?>
                            <span class="pill unpaid-pill" title="غير مدفوع — اتحاسب من برّه عهدة المندوب وبرّه الخزنة؛ بيتسجّل على الإذن ويتفوتر للعميل عادي"><?= icon('info', 14) ?> غير مدفوع</span>
                          <?php endif; ?>
                          <?php if (!empty($r['edited_at'])): ?>
                            <span class="pill st-partial" title="عدّله <?= e($r['edited_by'] ?: '') ?>"><?= icon('edit', 14) ?> متعدّل</span>
                          <?php endif; ?>
                          <?php if ($inReview): ?>
                            <span class="pill st-partial" title="<?= e($r['review_started_by'] ?: '') ?>"><?= icon('alert', 14) ?> قيد المراجعة</span>
                          <?php endif; ?>
                          <?php if ($preEpoch): ?>
                            <span class="pill st-late" title="تاريخه قبل بداية النظام الجديد — عهد سواقين متخصومة أصلاً على بوالصها، فاعتماده هيخصم نفس الفلوس مرتين."><?= icon('alert', 14) ?> مش هيتعتمد</span>
                          <?php endif; ?>
                        </div>

                        <div class="ib-meta">
                          <?php if (!empty($r['created_at'])): ?>
                            <span class="ib-submitted"
                                  title="وقت إدخال البند من المندوب: <?= e(dtfmt($r['created_at'], 'd/m/Y H:i:s')) ?>">
                              <?= icon('clock', 14) ?>
                              <span>إدخال المندوب</span>
                              <bdi dir="ltr"><?= e(dtfmt($r['created_at'], 'd/m/Y · H:i')) ?></bdi>
                            </span>
                          <?php endif; ?>
                          <?php if ($isMisc): ?>
                            <span>مصروف عام بلا إذن — هيروح للمصروفات</span>
                          <?php else: ?>
                            <a href="<?= url('permits/' . $r['permit_id'] . '/manage') ?>">
                              <?= e(Permit::noLabel($r['permit_type'] ?? 'sader')) ?>
                              <?= e($r['permit_no'] ?: '#' . $r['permit_id']) ?></a>
                            <?php if (!empty($r['company'])): ?>
                              <span class="ib-co"><?= icon('building', 14) ?> <?= e($r['company']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($r['client']) && $r['client'] !== $r['company']): ?>
                              <span><?= icon('user', 14) ?> <?= e($r['client']) ?></span>
                            <?php elseif (empty($r['company']) && empty($r['client'])): ?>
                              <span>بدون عميل</span>
                            <?php endif; ?>
                            <?php if ($r['movement_seq']): ?><span>حركة <?= (int) $r['movement_seq'] ?></span><?php endif; ?>
                          <?php endif; ?>
                          <?php if ($r['ref_no']): ?><span>إيصال <?= e($r['ref_no']) ?></span><?php endif; ?>
                          <?php if ((int) $r['qty'] > 1): ?>
                            <span><?= (int) $r['qty'] ?> × <?= money($r['unit_amount'], false) ?></span>
                          <?php endif; ?>
                        </div>

                        <?php if ($rowLocked): ?>
                          <p class="ib-warn"><?= icon('alert', 14) ?> تاريخه <?= e(dfmt($r['entry_date'])) ?> في يوم متقفل —
                            <button type="button" class="ib-link js-ib-row-date" data-sid="<?= $sid ?>">غيّر تاريخه</button>
                            أو <a href="<?= url('closing') ?>">افتح الإقفال</a>، وإلا الاعتماد هيترفض.</p>
                        <?php endif; ?>
                      </div>

                      <div class="ib-money">
                        <span class="ib-paid"><?= money($r['amount'], false) ?></span>
                        <span class="ib-actual <?= $hasActual ? 'has' : '' ?>">
                          <?= $isMisc
                                ? 'مصروف شركة'
                                : ($hasActual
                                    ? 'للعميل ' . money((float) $r['actual_unit'] * (int) $r['qty'], false)
                                    : 'الفعلي فاضي') ?>
                        </span>
                      </div>

                      <div class="ib-acts">
                        <button class="ib-btn ok" type="submit" form="a<?= $sid ?>f" title="اعتمد البند ده"><?= icon('check', 14) ?></button>
                        <button class="ib-btn" type="button" title="غيّر التاريخ بس"
                                onclick="ibRowDate(<?= $sid ?>)"><?= icon('calendar', 14) ?></button>
                        <button class="ib-btn" type="button" title="عدّل البند كله"
                                onclick="ibToggle('<?= $eid ?>')"><?= icon('edit', 14) ?></button>
                        <button class="ib-btn no" type="button" title="ارفض البند"
                                onclick="ibReject(<?= $sid ?>)"><?= icon('x', 14) ?></button>
                        <?php if ($canDelete): ?>
                          <button class="ib-btn del" type="button" title="احذف البند نهائيًّا"
                                  onclick="ibDelete(<?= $sid ?>)"><?= icon('trash', 14) ?></button>
                        <?php endif; ?>
                      </div>
                    </div>

                    <?php /* ─── درج التعديل الكامل — **بارشيال مشترك** مع تبويب
                             «معتمد» عشان الحقول وقواعدها تفضل واحدة (v95) ─── */ ?>
                    <?php partial('rep_submission_edit', [
                      'r' => $r, 'eid' => $eid, 'approved' => false,
                      'editFailed' => $editFailed, 'inboxOld' => $inboxOld,
                      'inboxErrors' => $inboxErrors, 'movs' => $movs,
                      'today' => $today, 'yesterday' => $yesterday, 'workDate' => $workDate,
                    ]); ?>
                  </article>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>
    </form>

    <?php /* شريط الإجراءات — بيطلع من تحت لما تعلّم أول بند، وبيقول عليه إيه بالظبط */ ?>
    <div class="ib-bar" id="ibBar" hidden role="region" aria-label="إجراءات البنود المعلَّمة">
      <div class="ib-bar-info">
        <b id="ibBarCount">0 بند</b>
        <span id="ibBarSum">0</span>
        <span class="ib-bar-lock" id="ibBarLock" hidden><?= icon('alert', 14) ?> <span id="ibBarLockN">0</span> على أيام متقفلة</span>
      </div>
      <div class="ib-bar-acts">
        <button type="button" class="btn gold" id="ibDoApprove"><?= icon('check', 14) ?> اعتمد المعلَّم</button>
        <button type="button" class="btn" id="ibDoDate"><?= icon('calendar', 14) ?> غيّر التاريخ</button>
        <button type="button" class="btn ghost" id="ibDoActual"><?= icon('coins', 14) ?> حط الفعلي للعميل</button>
        <button type="button" class="btn ghost" id="ibDoReject"><?= icon('x', 14) ?> ارفض</button>
        <?php if ($canDelete): ?>
          <button type="button" class="btn ghost ib-bar-del" id="ibDoDelete"><?= icon('trash', 14) ?> احذف نهائيًّا</button>
        <?php endif; ?>
        <button type="button" class="btn ghost" id="ibClearPick">شيل التعليم</button>
      </div>
    </div>

    <?php /* ★ لوحة التاريخ — بديل البوب-أبين المتسلسلين اللي كانوا بيطلبوا كتابة
             التاريخ بالإيد. منتقي تواريخ حقيقي + اختصارات + تحذير اليوم المتقفل. */ ?>
    <div class="ib-panel" id="ibDatePanel" hidden role="dialog" aria-modal="true" aria-labelledby="ibDatePanelTitle">
      <div class="ib-panel-box">
        <h3 id="ibDatePanelTitle"><?= icon('calendar', 14) ?> تغيير تاريخ <span id="ibDateN">0</span> بند</h3>
        <p class="ib-panel-sub">التاريخ ده هو اليوم اللي البند هيتخصم فيه من الخزنة ومن عهدة المندوب.</p>
        <input type="date" id="ibDateInput" max="<?= e($today) ?>" value="<?= e($workDate) ?>">
        <div class="ib-panel-chips">
          <button type="button" class="btn ghost sm" data-date="<?= e($today) ?>">النهاردة</button>
          <button type="button" class="btn ghost sm" data-date="<?= e($yesterday) ?>">إمبارح</button>
          <?php if ($workDate !== $today): ?>
            <button type="button" class="btn ghost sm" data-date="<?= e($workDate) ?>">يوم الشغل <?= e(dfmt($workDate)) ?></button>
          <?php endif; ?>
        </div>
        <p class="ib-panel-warn" id="ibDateWarn" hidden></p>
        <div class="ib-panel-acts">
          <button type="button" class="btn gold" id="ibDateGo">غيّر التاريخ</button>
          <button type="button" class="btn ghost" data-ib-close>إلغاء</button>
        </div>
      </div>
    </div>

    <?php /* فورمات الإجراءات الفردية — برّه فورم الصفحة عشان ما يتداخلوش،
             وكل واحد برمز FormState مستقل عشان إعادة المحاولة تشتغل. */ ?>
    <?php foreach ($groups as $g): foreach ($g['days'] as $d): foreach ($d['rows'] as $r): ?>
      <?php $sid = (int) $r['id']; ?>
      <form method="post" id="a<?= $sid ?>f" action="<?= url('rep-custody/' . $sid . '/approve') ?>" hidden>
        <?= csrfField() ?><?= formStateFields($rowToken('approve', $sid), $inboxState['back']) ?>
      </form>
      <form method="post" id="e<?= $sid ?>f" action="<?= url('rep-custody/' . $sid . '/edit') ?>" hidden>
        <?= csrfField() ?><?= formStateFields($rowToken('edit', $sid), $inboxState['back']) ?>
      </form>
      <form method="post" id="r<?= $sid ?>f" action="<?= url('rep-custody/' . $sid . '/reject') ?>" hidden>
        <?= csrfField() ?><?= formStateFields($rowToken('reject', $sid), $inboxState['back']) ?>
        <?php if (!($failedAction === 'reject' && $failedSid === $sid)): ?>
          <input type="hidden" name="reason" value="">
        <?php endif; ?>
      </form>
      <?php if ($canDelete): ?>
        <form method="post" id="d<?= $sid ?>f" action="<?= url('rep-custody/' . $sid . '/delete') ?>" hidden>
          <?= csrfField() ?><?= formStateFields($rowToken('delete', $sid), $inboxState['back']) ?>
        </form>
      <?php endif; ?>
    <?php endforeach; endforeach; endforeach; ?>

  <?php endif; ?>

<?php else: ?>

  <?php /* المعتمد/المرفوض — جدول عادي بأدوات الجداول العامة (ترتيب/إخفاء أعمدة)
           ⚠️ **مترقّم على الخادم**: «معتمد» فيه ٣٤٦ بند على الإنتاج وكانوا
              بيترسموا كلهم. و«مفيش قطع صامت» — العدّاد بيقول المعروض من الكل. */ ?>
  <?php if ($pager && $pager['total'] > 0): ?>
    <?php partial('count_note', [
      'shown' => count($rows),
      'total' => (int) $pager['total'],
      'unit'  => 'بند',
    ]); ?>
  <?php endif; ?>
  <?php /* حركات كل الأذونات المعروضة في **استعلام واحد** — درج التعديل محتاجها
           لقائمة «الحركة»، ونداؤها جوّه اللوب كان هيبقى N+1 (نفس درس تبويب
           «مستني»). */ ?>
  <?php
    $rowPermitIds = [];
    foreach ($rows as $r) {
        if (!RepPortal::isMisc((string) $r['kind']) && (int) $r['permit_id'] > 0) {
            $rowPermitIds[] = (int) $r['permit_id'];
        }
    }
    $movsByPermit = RepInbox::movementsFor($rowPermitIds);
    $hasActs = $canDelete || $canEditApproved;
  ?>
  <div class="card">
    <div class="table-wrap"><table class="data">
      <thead><tr>
        <th class="g-identity">تاريخ البند</th>
        <th class="g-identity">وقت إدخال المندوب</th>
        <th class="g-identity">المندوب</th>
        <th class="g-identity">البيان</th>
        <th class="g-identity">الإذن</th>
        <th class="g-identity">الشركة / العميل</th>
        <th class="g-expense">المبلغ</th>
        <th class="g-identity">الحالة</th>
        <th class="g-identity">المراجِع</th>
        <?php if ($hasActs): ?><th class="g-identity" data-no-sort data-no-hide></th><?php endif; ?>
      </tr></thead>
      <tbody>
        <?php $cols = $hasActs ? 10 : 9; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="<?= $cols ?>"><div class="empty">مفيش بنود هنا.</div></td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td><?= e(dfmt($r['entry_date'])) ?></td>
            <td>
              <?php if (!empty($r['created_at'])): ?>
                <span class="ib-table-time"
                      title="لحظة وصول البند للنظام">
                  <?= icon('clock', 14) ?>
                  <bdi dir="ltr"><?= e(dtfmt($r['created_at'], 'd/m/Y')) ?></bdi>
                  <small><bdi dir="ltr"><?= e(dtfmt($r['created_at'], 'H:i')) ?></bdi></small>
                </span>
              <?php else: ?>
                <span class="ib-del-na">—</span>
              <?php endif; ?>
            </td>
            <td style="text-align:right"><a href="<?= url('rep-custody/' . $r['rep_id']) ?>"><?= e($r['rep_name']) ?></a></td>
            <td style="text-align:right"><?= e($r['note']) ?></td>
            <td style="text-align:right">
              <?php if (RepPortal::isMisc((string) $r['kind'])): ?>
                <span class="pill ib-misc"><?= icon('anchor', 14) ?> نثرية</span>
                <?php if (($r['misc_kind'] ?? '') !== ''): ?>
                  <span class="pill ib-mkind"><?= icon(RepMiscKind::icon((string) $r['misc_kind']), 14) ?>
                    <?= e(RepMiscKind::label((string) $r['misc_kind'])) ?></span>
                <?php endif; ?>
              <?php else: ?>
                <a href="<?= url('permits/' . $r['permit_id'] . '/manage') ?>"><?= e($r['permit_no'] ?: '#' . $r['permit_id']) ?></a>
              <?php endif; ?>
            </td>
            <td style="text-align:right">
              <?php if (!empty($r['company'])): ?>
                <div style="font-weight:600"><?= icon('building', 14) ?> <?= e($r['company']) ?></div>
              <?php endif; ?>
              <?php if (!empty($r['client']) && $r['client'] !== $r['company']): ?>
                <div style="font-size:var(--fs-2);color:var(--grey)"><?= icon('user', 14) ?> <?= e($r['client']) ?></div>
              <?php elseif (empty($r['company']) && empty($r['client'])): ?>
                <span style="color:var(--grey)">—</span>
              <?php endif; ?>
            </td>
            <td style="font-weight:500"><?= money($r['amount'], false) ?></td>
            <td>
              <?php if ($r['status'] === 'approved'): ?>
                <span class="pill st-done"><?= icon('check', 14) ?> معتمد</span>
              <?php elseif ($r['status'] === 'rejected'): ?>
                <span class="pill st-late" title="<?= e($r['review_note'] ?? '') ?>"><?= icon('x', 14) ?> مرفوض</span>
              <?php else: ?>
                <span class="pill st-partial"><?= icon('hourglass', 14) ?> مستني</span>
              <?php endif; ?>
            </td>
            <td style="font-size:var(--fs-2);color:var(--grey)"><?= e($r['reviewed_by'] ?: '—') ?></td>
            <?php if ($hasActs): ?>
              <td class="ib-acts-cell">
                <?php /* ⚠️ **تلات إجراءات على البند المعتمد** (v95): تعديل ·
                         إلغاء اعتماد (بيشيل الأثر المالي ويرجّعه «مستني») ·
                         حذف نهائي (إلغاء اعتماد + سلة المحذوفات). التلاتة
                         بيمرّوا من `RepInbox` وحُرّاسه — الأزرار عرض بس. */ ?>
                <?php if ($r['status'] === 'approved'): ?>
                  <?php if ($canEditApproved && $approvedEditable($r)): $asid = (int) $r['id']; ?>
                    <span class="ib-acts">
                      <button class="ib-btn" type="button" title="عدّل البند المعتمد"
                              onclick="ibToggle('ea<?= $asid ?>')"><?= icon('edit', 14) ?></button>
                      <?php /* ⚠️ البيان والمبلغ في `data-` مش مقروءين من خلية
                               الجدول: أدوات الجداول بتخفي أعمدة، فالقراءة من
                               الخلية بتدّي تأكيدًا فاضيًا لما العمود يبقى مخفي. */ ?>
                      <button class="ib-btn" type="button" title="ألغِ الاعتماد — يرجع «مستني»"
                              data-note="<?= e($r['note']) ?>" data-amount="<?= e(money($r['amount'], false)) ?>"
                              onclick="ibUnapprove(this, <?= $asid ?>)"><?= icon('undo', 14) ?></button>
                      <button class="ib-btn del" type="button" title="احذف البند نهائيًّا"
                              data-note="<?= e($r['note']) ?>" data-amount="<?= e(money($r['amount'], false)) ?>"
                              onclick="ibDeleteApproved(this, <?= $asid ?>)"><?= icon('trash', 14) ?></button>
                    </span>
                  <?php elseif ($canEditApproved): ?>
                    <span class="ib-del-na" title="بنده الفعلي اتمسح من الإذن — السجل بيفضل شاهدًا على الاعتماد">—</span>
                  <?php else: ?>
                    <span class="ib-del-na" title="البند معتمد وله بند فعلي على الإذن">—</span>
                  <?php endif; ?>
                <?php elseif ($canDelete): ?>
                  <form method="post" action="<?= url('rep-custody/' . (int) $r['id'] . '/delete') ?>"
                        class="confirm-delete inline-form"
                        data-confirm="هيتشال البند «<?= e($r['note']) ?>» خالص. تلاقيه في سلة المحذوفات لو عايز ترجّعه.">
                    <?= csrfField() ?><?= formStateFields(FormState::token(), $inboxState['back']) ?>
                    <button class="ib-btn del" type="submit" title="احذف البند نهائيًّا"><?= icon('trash', 14) ?></button>
                  </form>
                <?php else: ?>
                  <span class="ib-del-na">—</span>
                <?php endif; ?>
              </td>
            <?php endif; ?>
          </tr>
          <?php if ($r['status'] === 'rejected' && $r['review_note']): ?>
            <tr><td colspan="<?= $cols ?>" class="ib-reject-why">
              سبب الرفض: <?= e($r['review_note']) ?>
            </td></tr>
          <?php endif; ?>
        <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>

  <?php /* ══ لوحات التعديل وفورمات الإجراءات — **كلها برّه الجدول** ══
           ⚠️ سببان مستقلان، كل واحد منهم لوحده كافٍ:
           ① `<form>` جوّه `<table>` ماركب غير صالح والمتصفح بيفكّه بصمت،
              فالزرار يبعت على المسار الغلط.
           ② الدرج جوّه `<td>` **ديزاينه بيتكسر**: `.ib-grid` شبكة `auto-fit`
              وعرض الخلية بيتحدّد بخوارزمية الجدول مش بعرض الصفحة، فالحقول
              كلها بتقع في سطر واحد وبتطلع برّه الحافة. (متحقَّق بلقطة.)
           الحقول بتتربط بفورمها بسمة `form=` فمفيش داعي لأي تداخل. */ ?>
  <?php if ($canEditApproved): ?>
    <?php foreach ($rows as $r): ?>
      <?php if ($r['status'] === 'approved' && $approvedEditable($r)):
              $asid = (int) $r['id'];
              $aEditFailed = $failedAction === 'edit-approved' && $failedSid === $asid; ?>
        <?php partial('rep_submission_edit', [
          'r' => $r, 'eid' => 'ea' . $asid, 'approved' => true, 'panel' => true,
          'editFailed' => $aEditFailed, 'inboxOld' => $inboxOld,
          'inboxErrors' => $inboxErrors,
          'movs' => $movsByPermit[(int) $r['permit_id']] ?? [],
          'today' => $today, 'yesterday' => $yesterday, 'workDate' => $workDate,
        ]); ?>
        <form method="post" id="ea<?= $asid ?>f" action="<?= url('rep-custody/' . $asid . '/edit-approved') ?>" hidden>
          <?= csrfField() ?><?= formStateFields($rowToken('edit-approved', $asid), $inboxState['back']) ?>
        </form>
        <form method="post" id="ua<?= $asid ?>f" action="<?= url('rep-custody/' . $asid . '/unapprove') ?>" hidden>
          <?= csrfField() ?><?= formStateFields($rowToken('unapprove', $asid), $inboxState['back']) ?>
        </form>
        <form method="post" id="da<?= $asid ?>f" action="<?= url('rep-custody/' . $asid . '/delete-approved') ?>" hidden>
          <?= csrfField() ?><?= formStateFields($rowToken('delete-approved', $asid), $inboxState['back']) ?>
        </form>
      <?php endif; ?>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($pager): ?>
    <?php partial('pager', [
      'page'  => (int) $pager['page'],
      'pages' => (int) $pager['pages'],
      'label' => 'صفحات البنود',
      /* ⚠️ الترقيم لازم يحافظ على العدسة كلها، وإلا «التالي» بيرجّعك لكل
         المناديب من أول صفحة ٢. */
      'qs'    => static function (array $o) use ($status, $repId, $dayFilter): string {
          $q = ['status' => $status];
          if ($repId > 0)    { $q['rep'] = $repId; }
          if ($dayFilter !== '') { $q['day'] = $dayFilter; }
          return http_build_query($q + $o);
      },
    ]); ?>
  <?php endif; ?>

<?php endif; ?>

<style>
/* ══ صندوق مراجعة بنود المناديب — معاد بناؤه ══ */
.ib-note{font-size:var(--fs-2)}
.kpi-grid.four{grid-template-columns:repeat(4,1fr)}
@media (max-width:900px){ .kpi-grid.four{grid-template-columns:repeat(2,1fr)} }
.kpi.danger{border-color:var(--red-line);background:var(--red-lt)}

/* شرايح عدسة المندوب — فلترة خادمية بضغطة */
.ib-lens{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 var(--space-3)}
.ib-lens-chip{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;
  border:1px solid var(--line);border-radius:var(--radius-pill);background:var(--card);
  color:var(--ink);font-size:var(--fs-2);font-weight:var(--fw-medium);text-decoration:none;min-height:38px}
.ib-lens-chip:hover{border-color:var(--navy);background:var(--zebra)}
.ib-lens-chip b{font-weight:var(--fw-bold);color:var(--grey);font-size:var(--fs-1)}
.ib-lens-chip.on{background:var(--navy);border-color:var(--navy);color:var(--white)}
.ib-lens-chip.on b{color:var(--white);opacity:.85}
.ib-lens-card{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;min-height:38px;
  font-size:var(--fs-1);color:var(--grey);text-decoration:none;margin-inline-start:auto}
.ib-lens-card:hover{color:var(--accent);text-decoration:underline}
@media (max-width:640px){ .ib-lens-card{margin-inline-start:0} }

/* الفلاتر */
.ib-filters{display:flex;align-items:end;gap:12px;flex-wrap:wrap;padding:13px 16px}
.ib-filters label{display:flex;flex-direction:column;gap:4px;font-size:var(--fs-1);font-weight:600;color:var(--ink-mut)}
.ib-filters input[type=search],.ib-filters select{
  min-height:38px;padding:0 10px;border:1px solid var(--line2);border-radius:var(--radius-2);
  font-family:inherit;font-size:var(--fs-2);background:var(--card)}
.ib-f-search{flex:1;min-width:220px}
.ib-f-chk{flex-direction:row!important;align-items:center;gap:7px;min-height:38px}
.ib-f-chk input{width:17px;height:17px;margin:0}
.ib-f-count{margin-inline-start:auto;font-size:var(--fs-1);color:var(--ink-mut);font-weight:500}

/* كارت المندوب */
.ib-rep-head{display:flex;align-items:center;gap:12px;padding:14px 18px;border-bottom:1px solid var(--line)}
.ib-rep-id{flex:1;min-width:0;display:flex;flex-direction:column}
.ib-rep-name{font-size:var(--fs-5);font-weight:700;color:var(--accent)}
.ib-rep-sub{font-size:var(--fs-2);color:var(--grey);font-weight:500}
.ib-pick-all,.ib-pick{flex:none;display:flex;align-items:center;cursor:pointer}
.ib-pick-all input,.ib-pick input{width:18px;height:18px;margin:0;cursor:pointer}
.ib-rep.collapsed .ib-rep-body{display:none}

/* اليوم */
.ib-day{border-bottom:1px solid var(--line)}
.ib-day:last-child{border-bottom:0}
.ib-day-head{display:flex;align-items:center;gap:11px;padding:10px 18px;background:var(--zebra);flex-wrap:wrap}
.ib-day.is-locked .ib-day-head{background:var(--red-lt)}
.ib-day-date{font-weight:600;font-size:var(--fs-3);display:flex;align-items:center;gap:6px}
.ib-day-tot{font-size:var(--fs-2);color:var(--grey);font-weight:500;margin-inline-start:auto}

/* البند */
.ib-item{border-top:1px solid var(--line)}
.ib-item.in-review{background:var(--gold-light)}
.ib-item.row-locked{box-shadow:inset 3px 0 0 var(--red)}
.ib-item.is-hidden{display:none}
.ib-row{display:flex;align-items:center;gap:12px;padding:12px 18px}
.ib-main{flex:1;min-width:0}
.ib-title{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.ib-note{font-weight:600;font-size:var(--fs-4)}
.ib-meta{font-size:var(--fs-1);color:var(--grey);margin-top:3px;display:flex;gap:10px;flex-wrap:wrap}
.ib-meta a{font-weight:500;color:var(--accent)}
.ib-meta .ib-co{font-weight:600;color:var(--accent)}
.ib-submitted{display:inline-flex;align-items:center;gap:5px;padding:2px 8px;
  border:1px solid var(--line);border-radius:var(--radius-pill);background:var(--zebra);
  color:var(--ink-mut);font-weight:var(--fw-medium);white-space:nowrap}
.ib-submitted svg{color:var(--teal-fg);flex:none}
.ib-submitted bdi{color:var(--ink);font-weight:var(--fw-bold);font-variant-numeric:tabular-nums}
.ib-table-time{display:inline-grid;grid-template-columns:auto auto;align-items:center;column-gap:6px;
  color:var(--ink);font-weight:var(--fw-medium);white-space:nowrap}
.ib-table-time svg{grid-row:1/3;color:var(--teal-fg)}
.ib-table-time small{color:var(--grey);font-size:var(--fs-1);font-weight:var(--fw-medium);
  font-variant-numeric:tabular-nums;text-align:start}
.ib-warn{margin:6px 0 0;font-size:var(--fs-1);font-weight:500;color:var(--red-fg);line-height:1.6}
.ib-link{background:0;border:0;padding:0;font:inherit;font-weight:700;color:var(--red-fg);text-decoration:underline;cursor:pointer}
.pill.ib-misc{background:var(--gold-light);color:var(--gold-fg);border:1px solid var(--gold-line)}
/* وسم النثرية (v71) — لون محايد عشان ما يزاحمش شارة «نثرية» الدهبية جنبه،
   و«بلا نوع» بلون التحذير لأنه بند لازم يتوسم قبل الاعتماد. */
.pill.ib-mkind{background:var(--zebra);color:var(--ink);border:1px solid var(--line)}
.pill.ib-mkind.none{background:var(--red-lt);color:var(--red-fg);border-color:var(--red-line)}

.ib-money{flex:none;text-align:center;min-width:112px;display:flex;flex-direction:column}
.ib-paid{font-size:var(--fs-4);font-weight:700;color:var(--red-fg);font-variant-numeric:tabular-nums}
.ib-actual{font-size:var(--fs-1);font-weight:500;color:var(--sader)}
.ib-actual.has{color:var(--green-fg)}

.ib-photo{flex:none;display:flex;align-items:center;justify-content:center;width:46px;height:46px;
  border-radius:var(--radius-1);overflow:hidden;border:1px solid var(--line2);background:var(--card)}
.ib-photo img{width:100%;height:100%;object-fit:cover;display:block}
.ib-photo.none{border-style:dashed;border-color:var(--line);background:var(--zebra);opacity:.45}

.ib-acts{flex:none;display:flex;gap:6px}
.ib-btn{min-width:38px;min-height:38px;padding:0 10px;border:1px solid var(--line2);border-radius:var(--radius-1);
  background:var(--card);font-family:inherit;font-weight:600;font-size:var(--fs-3);cursor:pointer;color:var(--accent)}
.ib-btn:hover{border-color:var(--navy)}
.ib-btn.ok{background:var(--green-lt);border-color:var(--green-line);color:var(--green-fg)}
.ib-btn.ok:hover{background:var(--green);color:#fff}
.ib-btn.no{color:var(--red-fg)}
.ib-btn.no:hover{background:var(--red-lt);border-color:var(--red)}
/* الحذف النهائي: باهت لحد ما تقرب منه — إجراء نادر مش خطوة يومية */
.ib-btn.del{color:var(--grey);opacity:.7}
.ib-btn.del:hover{opacity:1;color:var(--red-fg);background:var(--red-lt);border-color:var(--red)}
.ib-bar-del:hover{border-color:var(--red-line);color:#FFD7D7}
.ib-del-na{color:var(--grey);opacity:.5}

.ib-row-error{padding:10px 18px 0}
.ib-retry{display:flex;align-items:end;gap:8px;padding:10px 0;flex-wrap:wrap}
.ib-retry label{flex:1;min-width:220px;font-size:var(--fs-2);font-weight:600}
.ib-retry input{display:block;width:100%;margin-top:4px;padding:7px 9px;border:1px solid var(--line2);border-radius:var(--radius-1)}

/* سبب الرفض تحت صف الجدول (كان `style=` inline) */
.ib-reject-why{text-align:right;font-size:var(--fs-2);color:var(--red-fg);background:var(--red-lt)}

/* ══ لوحة تعديل البند المعتمد (v95) ══
   ⚠️ **لوحة مش صف في الجدول**: `.ib-grid` شبكة `auto-fit`، وجوّه `<td>` عرض
      الخلية بيتحدّد بخوارزمية الجدول مش بعرض الصفحة — فالحقول كانت بتقع في
      سطر واحد وتطلع برّه الحافة والعناوين تقف جنب خاناتها. اللوحة بتدّي
      الشبكة عرضًا محدَّدًا فبترجع تشتغل صح على الشاشتين. */
/* ⚠️ **الصنفان مع بعض إجباري**: `.ib-panel-box` معرَّف تحت في نفس الملف
   بعرض 420 ونفس الوزن، فالانتقاء بصنف واحد كان بيتغلب عليه بالترتيب —
   واللوحة بتطلع ضيقة والشبكة ترجع عمودًا واحدًا على الشاشة الكبيرة. */
.ib-panel-box.ib-edit-box{width:min(720px,100%);max-height:88vh;overflow-y:auto;padding:0}
.ib-edit-head{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid var(--line);
  position:sticky;top:0;background:var(--card);z-index:1}
.ib-edit-head h3{margin:0;flex:1;font-size:var(--fs-4);display:flex;align-items:center;gap:7px}
.ib-edit-panel .ib-edit{background:var(--card);border-top:0;padding:0 18px 18px}
.ib-edit-panel .ib-edit-acts{position:sticky;bottom:0;background:var(--card);
  padding:12px 0 2px;border-top:1px solid var(--line)}

/* خلية إجراءات البند المعتمد — التلات أزرار جنب بعض بلا لفّ */
.ib-acts-cell .ib-acts{display:inline-flex;gap:5px;justify-content:center}

/* درج التعديل */
.ib-edit{padding:6px 18px 16px;background:var(--zebra);border-top:1px dashed var(--line2)}
.ib-edit-sec{margin-top:12px}
.ib-edit-sec h4{margin:0 0 7px;font-size:var(--fs-1);font-weight:700;color:var(--grey);letter-spacing:.02em}
.ib-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px}
.ib-grid label{display:block;font-size:var(--fs-1);font-weight:600;color:var(--grey)}
.ib-grid label.wide{grid-column:1/-1}
.ib-grid label.hi{color:var(--green-fg)}
.ib-grid input,.ib-grid select{width:100%;margin-top:4px;padding:7px 9px;border:1px solid var(--line2);
  border-radius:var(--radius-1);font-size:var(--fs-3);background:var(--card);font-family:inherit}
.ib-grid label.hi input{border-color:var(--green-line);background:var(--green-lt)}
.ib-visa-row{display:flex;align-items:center;gap:8px;margin-top:4px}
.ib-visa-row input[type=checkbox]{width:17px;height:17px;margin:0;flex:none}
.ib-date-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.ib-date-row input[type=date]{min-height:38px;padding:0 10px;border:1px solid var(--line2);
  border-radius:var(--radius-1);font-family:inherit;font-size:var(--fs-3);background:var(--card)}
.ib-date-note{font-size:var(--fs-1);font-weight:600;color:var(--red-fg)}
.ib-edit-acts{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
.ib-edit-acts .btn{min-height:38px;padding:0 16px;font-size:var(--fs-3)}

/* شريط الإجراءات */
.ib-bar{position:fixed;inset-inline:0;bottom:0;z-index:900;display:flex;align-items:center;
  gap:14px;flex-wrap:wrap;padding:12px 20px;background:var(--navy);color:#fff;
  box-shadow:0 -6px 22px rgba(0,0,0,.22)}
.ib-bar-info{display:flex;align-items:baseline;gap:12px;flex-wrap:wrap}
.ib-bar-info b{font-size:var(--fs-4);font-weight:700}
.ib-bar-info span{font-size:var(--fs-2);opacity:.85;font-variant-numeric:tabular-nums}
.ib-bar-lock{background:rgba(255,120,120,.22);border:1px solid rgba(255,150,150,.5);
  border-radius:var(--radius-1);padding:3px 9px;opacity:1!important;font-weight:600}
.ib-bar-acts{display:flex;gap:8px;flex-wrap:wrap;margin-inline-start:auto}
.ib-bar-acts .btn{min-height:40px}
body.ib-has-bar{padding-bottom:78px}

/* لوحة التاريخ */
/* ⚠ 990 مقصودة: تحت مودال AppUI (--z-modal = 1000) عشان أي تنبيه يطلع فوق
   اللوحة، وفوق شريط الإجراءات (900) عشان اللوحة تغطّيه. */
.ib-panel{position:fixed;inset:0;z-index:990;background:rgba(10,18,30,.5);
  display:flex;align-items:center;justify-content:center;padding:18px}
.ib-panel-box{background:var(--card);border-radius:var(--radius-3);padding:20px;width:min(420px,100%);
  box-shadow:0 18px 50px rgba(0,0,0,.3)}
.ib-panel-box h3{margin:0 0 4px;font-size:var(--fs-4)}
.ib-panel-sub{margin:0 0 14px;font-size:var(--fs-2);color:var(--grey);line-height:1.6}
.ib-panel-box input[type=date]{width:100%;min-height:44px;padding:0 12px;border:1px solid var(--line2);
  border-radius:var(--radius-2);font-family:inherit;font-size:var(--fs-4)}
.ib-panel-chips{display:flex;gap:7px;flex-wrap:wrap;margin-top:10px}
.ib-panel-warn{margin:12px 0 0;padding:9px 11px;border-radius:var(--radius-1);background:var(--red-lt);
  border:1px solid var(--red-line);color:var(--red-fg);font-size:var(--fs-2);font-weight:500;line-height:1.6}
.ib-panel-acts{display:flex;gap:8px;margin-top:16px}

@media (max-width:860px){
  .ib-row{flex-wrap:wrap}
  .ib-main{flex:1 1 100%;order:1}
  .ib-money{order:2;text-align:right;min-width:0}
  .ib-photo{order:3}
  .ib-acts{order:4;margin-inline-start:auto}
  .ib-bar-acts{width:100%;margin-inline-start:0}
  .ib-bar-acts .btn{flex:1}
}
</style>

<script>
(function () {
  'use strict';
  var LOCKED_THROUGH = <?= js_json($lockedThrough) ?>;   // آخر يوم متقفل أو null
  function isLockedDate(v) { return !!LOCKED_THROUGH && !!v && v <= LOCKED_THROUGH; }

  /* ══ الجزء ده **قبل حارس `ibForm`** عن قصد ══
     `ibForm` موجود في تبويب «مستني» بس، لكن درج التعديل بقى في تبويب «معتمد»
     كمان (v95) — فلو الدوال دي فضلت تحت الحارس، زرار «عدّل المعتمد» كان
     هيدوس بلا أي رد فعل، واختصارات التاريخ كمان. */
  window.ibToggle = function (id) {
    var el = document.getElementById(id);
    if (el) { el.hidden = !el.hidden; }
  };
  window.ibSetDate = function (inputId, value) {
    var el = document.getElementById(inputId);
    if (!el) { return; }
    el.value = value;
    el.dispatchEvent(new Event('input', { bubbles: true }));
  };
  // تحذير اليوم المتقفل جنب حقل التاريخ في أي درج تعديل — على المستند عشان
  // يشتغل في التبويبين بنفس الكود.
  document.addEventListener('input', function (ev) {
    if (!ev.target.classList || !ev.target.classList.contains('js-ib-edit-date')) { return; }
    var note = document.querySelector('.ib-date-note[data-for="' + ev.target.id + '"]');
    if (note) { note.textContent = isLockedDate(ev.target.value) ? '🔒 اليوم ده متقفل — الحفظ هيترفض.' : ''; }
  });
  document.querySelectorAll('.js-ib-edit-date').forEach(function (el) {
    el.dispatchEvent(new Event('input', { bubbles: true }));
  });

  // ── لوحة تعديل البند المعتمد: تقفل بالخلفية · بزرار × · بـEsc
  function closeEditPanels() {
    document.querySelectorAll('.ib-edit-panel').forEach(function (p) { p.hidden = true; });
  }
  document.addEventListener('click', function (ev) {
    if (ev.target.classList && ev.target.classList.contains('ib-edit-panel')) { closeEditPanels(); return; }
    if (ev.target.closest && ev.target.closest('[data-ib-close-edit]')) { closeEditPanels(); }
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') { closeEditPanels(); }
  });

  // ── إجراءات البند المعتمد (v95)
  function submitById(id) {
    var f = document.getElementById(id);
    if (!f) { return; }
    if (f.requestSubmit) { f.requestSubmit(); } else { f.submit(); }
  }
  /* ⚠️ التأكيد بيقول **الفلوس رايحة فين** مش «متأكد؟»: الفرق بين الاتنين إن
     المالك يعرف إن المبلغ بيرجع للخزنة والعهدة قبل ما يدوس، مش بعدها. */
  window.ibUnapprove = function (btn, sid) {
    var d = btn.dataset;
    window.AppUI.confirm(
      'البند «' + d.note + '» (' + d.amount + ' ج) هيرجع «مستني» في الصندوق، '
      + 'وهيتشال من الإذن، والمبلغ هيرجع للخزنة ولعهدة المندوب. تقدر تراجعه وتعتمده تاني.',
      function () { submitById('ua' + sid + 'f'); },
      { title: 'إلغاء اعتماد البند', okText: 'ألغِ الاعتماد' });
  };
  window.ibDeleteApproved = function (btn, sid) {
    var d = btn.dataset;
    window.AppUI.confirm(
      'البند «' + d.note + '» (' + d.amount + ' ج) هيتشال خالص: هيخرج من الإذن، '
      + 'والمبلغ هيرجع للخزنة ولعهدة المندوب. وتلاقيه في سلة المحذوفات لو عايز ترجّعه.',
      function () { submitById('da' + sid + 'f'); },
      { title: 'حذف بند معتمد', okText: 'احذف', danger: true });
  };

  var form = document.getElementById('ibForm');
  if (!form) { return; }

  var bar      = document.getElementById('ibBar');
  var barCount = document.getElementById('ibBarCount');
  var barSum   = document.getElementById('ibBarSum');
  var barLock  = document.getElementById('ibBarLock');
  var barLockN = document.getElementById('ibBarLockN');

  function boxes()  { return Array.prototype.slice.call(form.querySelectorAll('.js-ib-one')); }
  function picked() { return boxes().filter(function (b) { return b.checked && !b.closest('.ib-item').classList.contains('is-hidden'); }); }
  function money(v) { return v.toLocaleString('en-US', { maximumFractionDigits: 0 }); }

  // ── شريط الإجراءات: بيقول عليه إيه بالظبط قبل ما تدوس
  function syncBar() {
    var sel = picked();
    var n = sel.length;
    bar.hidden = n === 0;
    document.body.classList.toggle('ib-has-bar', n > 0);
    if (!n) { syncGroupBoxes(); return; }
    var sum = 0, lockedN = 0;
    sel.forEach(function (b) {
      sum += parseFloat(b.dataset.amount || '0') || 0;
      if (b.closest('.ib-item').dataset.locked === '1') { lockedN++; }
    });
    barCount.textContent = n + (n === 1 ? ' بند' : ' بند');
    barSum.textContent = 'إجمالي ' + money(sum) + ' ج';
    barLock.hidden = lockedN === 0;
    barLockN.textContent = lockedN;
    syncGroupBoxes();
  }

  // ── حالة «علّم الكل» لكل مندوب/يوم (بيحترم الفلترة الظاهرة)
  function syncGroupBoxes() {
    form.querySelectorAll('.ib-rep, .ib-day').forEach(function (scope) {
      var master = scope.querySelector(':scope > .ib-rep-head .js-ib-rep-all, :scope > .ib-day-head .js-ib-day-all');
      if (!master) { return; }
      var kids = Array.prototype.slice.call(scope.querySelectorAll('.js-ib-one'))
        .filter(function (b) { return !b.closest('.ib-item').classList.contains('is-hidden'); });
      var on = kids.filter(function (b) { return b.checked; });
      master.checked = kids.length > 0 && on.length === kids.length;
      master.indeterminate = on.length > 0 && on.length < kids.length;
      master.disabled = kids.length === 0;
    });
  }

  form.addEventListener('change', function (ev) {
    var t = ev.target;
    if (t.classList.contains('js-ib-one')) { syncBar(); return; }
    if (t.classList.contains('js-ib-rep-all') || t.classList.contains('js-ib-day-all')) {
      var scope = t.closest('.ib-rep, .ib-day');
      scope.querySelectorAll('.js-ib-one').forEach(function (b) {
        if (!b.closest('.ib-item').classList.contains('is-hidden')) { b.checked = t.checked; }
      });
      syncBar();
    }
  });

  document.getElementById('ibClearPick').addEventListener('click', function () {
    boxes().forEach(function (b) { b.checked = false; });
    syncBar();
  });

  // ── طيّ كارت المندوب
  form.querySelectorAll('.ib-collapse').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var card = btn.closest('.ib-rep');
      var open = !card.classList.toggle('collapsed');
      btn.setAttribute('aria-expanded', String(open));
      btn.textContent = open ? 'اطوِ' : 'افتح';
    });
  });

  // ── الفلترة (كلها في المتصفح)
  var fSearch = document.getElementById('ibSearch');
  var fDay    = document.getElementById('ibDay');
  var fLocked = document.getElementById('ibOnlyLocked');
  var counter = document.getElementById('ibCount');

  /* ⚠️ بيتحلّ وقت النداء مش وقت التحميل — `app.js` بقى `defer` فبيتنفّذ بعد
     السكربت ده، والالتقاط هنا كان بيقع على بديل بلا تطبيع عربي **بالسكوت**
     (نفس الإصلاح في `closing/index.php`). */
  function ibNorm(v) {
    return (window.almSearchNorm || function (x) { return String(x || '').trim().toLowerCase(); })(v);
  }
  function applyFilters() {
    var q = ibNorm(fSearch.value || '');
    var day = fDay.value, onlyLocked = fLocked.checked;
    var shown = 0;
    form.querySelectorAll('.ib-item').forEach(function (item) {
      var ok = (!q || (item.dataset.search || '').indexOf(q) !== -1)
            && (!day || item.dataset.day === day)
            && (!onlyLocked || item.dataset.locked === '1');
      item.classList.toggle('is-hidden', !ok);
      if (ok) { shown++; }
      // البند المخفي ما يتحسبش في أي إجراء جماعي — وإلا المالك يعتمد حاجة مش شايفها
      if (!ok) { var b = item.querySelector('.js-ib-one'); if (b) { b.checked = false; } }
    });
    // نخفي اليوم/المندوب اللي كل بنوده اتفلترت
    form.querySelectorAll('.ib-day').forEach(function (d) {
      d.hidden = d.querySelectorAll('.ib-item:not(.is-hidden)').length === 0;
    });
    form.querySelectorAll('.ib-rep').forEach(function (s) {
      s.hidden = s.querySelectorAll('.ib-item:not(.is-hidden)').length === 0;
    });
    var total = form.querySelectorAll('.ib-item').length;
    counter.textContent = shown === total ? '' : ('ظاهر ' + shown + ' من ' + total);
    syncBar();
  }
  [fSearch, fDay, fLocked].forEach(function (el) {
    el.addEventListener('input', applyFilters);
    el.addEventListener('change', applyFilters);
  });
  document.getElementById('ibClearFilters').addEventListener('click', function () {
    fSearch.value = ''; fDay.value = ''; fLocked.checked = false;
    applyFilters();
  });

  // ── لوحة التاريخ (بديل البوب-أبين المتسلسلين)
  var panel   = document.getElementById('ibDatePanel');
  var dateIn  = document.getElementById('ibDateInput');
  var dateN   = document.getElementById('ibDateN');
  var dateWarn= document.getElementById('ibDateWarn');
  var pendingRowForm = null;    // لو اللوحة اتفتحت لبند واحد

  function syncDateWarn() {
    if (isLockedDate(dateIn.value)) {
      dateWarn.hidden = false;
      dateWarn.textContent = '🔒 اليوم ده متقفل — البند هيتحفظ بالتاريخ ده بس اعتماده هيترفض لحد ما تفتح إقفاله.';
    } else {
      dateWarn.hidden = true;
    }
  }
  dateIn.addEventListener('input', syncDateWarn);
  panel.querySelectorAll('.ib-panel-chips [data-date]').forEach(function (b) {
    b.addEventListener('click', function () { dateIn.value = b.dataset.date; syncDateWarn(); });
  });

  function openPanel(count, rowFormId) {
    pendingRowForm = rowFormId || null;
    dateN.textContent = count;
    syncDateWarn();
    panel.hidden = false;
    setTimeout(function () { dateIn.focus(); }, 30);
  }
  function closePanel() { panel.hidden = true; pendingRowForm = null; }
  panel.querySelectorAll('[data-ib-close]').forEach(function (b) { b.addEventListener('click', closePanel); });
  panel.addEventListener('click', function (ev) { if (ev.target === panel) { closePanel(); } });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !panel.hidden) { closePanel(); } });

  document.getElementById('ibDateGo').addEventListener('click', function () {
    var v = dateIn.value;
    if (!v) { window.AppUI.notice('اختار تاريخ الأول.'); return; }
    if (pendingRowForm) {
      // بند واحد: بنملّى تاريخه في فورم تعديله ونبعته — الحقول التانية موجودة
      // في الدرج بقيمها الحالية، فالتعديل بيحفظ نفس البيانات بتاريخ جديد.
      var f = document.getElementById(pendingRowForm);
      var d = document.querySelector('#' + pendingRowForm.replace(/f$/, '') + 'date');
      if (d) { d.value = v; }
      closePanel();
      if (f.requestSubmit) { f.requestSubmit(); } else { f.submit(); }
      return;
    }
    var n = picked().length;
    if (!n) { window.AppUI.notice('علّم بند واحد على الأقل.'); return; }
    document.getElementById('ibBulkDate').value = v;
    document.getElementById('ibBulkActual').value = '';
    document.getElementById('ibBulkKind').value = '';
    closePanel();
    submitBulk('<?= url('rep-custody/bulk-edit') ?>');
  });

  document.getElementById('ibDoDate').addEventListener('click', function () {
    var n = picked().length;
    if (!n) { window.AppUI.notice('علّم بند واحد على الأقل.'); return; }
    openPanel(n, null);
  });

  // «غيّر تاريخ اليوم» في رأس اليوم: بيعلّم بنود اليوم الظاهرة ثم يفتح اللوحة
  form.querySelectorAll('.js-ib-day-date').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var day = btn.closest('.ib-day');
      boxes().forEach(function (b) { b.checked = false; });
      var n = 0;
      day.querySelectorAll('.js-ib-one').forEach(function (b) {
        if (!b.closest('.ib-item').classList.contains('is-hidden')) { b.checked = true; n++; }
      });
      syncBar();
      if (!n) { window.AppUI.notice('مفيش بنود ظاهرة في اليوم ده.'); return; }
      dateIn.value = day.dataset.day;
      openPanel(n, null);
    });
  });

  // ── تغيير تاريخ بند واحد من زرار 📅 في صفّه
  window.ibRowDate = function (sid) {
    var drawer = document.getElementById('e' + sid);
    if (drawer && drawer.hidden) { drawer.hidden = false; }   // الحقول لازم تكون في الصفحة
    var cur = document.getElementById('e' + sid + 'date');
    dateIn.value = (cur && cur.value) || '<?= e($workDate) ?>';
    openPanel(1, 'e' + sid + 'f');
  };

  // ── إرسال إجراء جماعي على فورم الصفحة
  function submitBulk(action) {
    form.action = action;
    if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
  }

  document.getElementById('ibDoApprove').addEventListener('click', function () {
    var sel = picked();
    if (!sel.length) { window.AppUI.notice('علّم بند واحد على الأقل.'); return; }
    var lockedN = sel.filter(function (b) { return b.closest('.ib-item').dataset.locked === '1'; }).length;
    var msg = 'تعتمد ' + sel.length + ' بند؟ هيتحطوا على أذوناتهم ويتخصموا من الخزنة وعهدة المندوب.';
    if (lockedN) {
      msg = '⚠ فيه ' + lockedN + ' بند على أيام متقفلة — دول اعتمادهم هيترفض والباقي هيعدّي. ' + msg;
    }
    window.AppUI.confirm(msg, function () {
      document.getElementById('ibBulkDate').value = '';
      document.getElementById('ibBulkActual').value = '';
      document.getElementById('ibBulkKind').value = '';
      submitBulk('<?= url('rep-custody/approve-many') ?>');
    }, { title: 'اعتماد بنود المناديب', okText: 'اعتمد' });
  });

  document.getElementById('ibDoActual').addEventListener('click', function () {
    var n = picked().length;
    if (!n) { window.AppUI.notice('علّم بند واحد على الأقل.'); return; }
    window.AppUI.ask('السعر ده للوحدة، وهيتحط على ' + n + ' بند.', function (v) {
      v = (v || '').trim();
      if (v === '') { return; }
      document.getElementById('ibBulkActual').value = v;
      document.getElementById('ibBulkDate').value = '';
      document.getElementById('ibBulkKind').value = '';
      submitBulk('<?= url('rep-custody/bulk-edit') ?>');
    }, { title: 'الفعلي للعميل (للوحدة)', inputType: 'number', okText: 'حط السعر' });
  });

  document.getElementById('ibDoReject').addEventListener('click', function () {
    var n = picked().length;
    if (!n) { window.AppUI.notice('علّم بند واحد على الأقل.'); return; }
    window.AppUI.ask('المندوب هيشوف السبب على موبايله.', function (why) {
      why = (why || '').trim();
      if (!why) { return; }
      document.getElementById('ibBulkReason').value = why;
      submitBulk('<?= url('rep-custody/reject-many') ?>');
    }, { title: 'سبب رفض ' + n + ' بند', okText: 'ارفض البنود' });
  });

  var delBtn = document.getElementById('ibDoDelete');
  if (delBtn) {
    delBtn.addEventListener('click', function () {
      var sel = picked();
      if (!sel.length) { window.AppUI.notice('علّم بند واحد على الأقل.'); return; }
      var sum = 0;
      sel.forEach(function (b) { sum += parseFloat(b.dataset.amount || '0') || 0; });
      window.AppUI.confirm(
        'هيتشال ' + sel.length + ' بند بإجمالي ' + money(sum) + ' ج من الصندوق خالص. '
        + 'مش هيتخصموا من الخزنة ولا من عهدة المندوب (ما اتخصموش أصلاً)، '
        + 'وتلاقيهم في سلة المحذوفات في سجل العمليات لو عايز ترجّعهم.',
        function () { submitBulk('<?= url('rep-custody/delete-many') ?>'); },
        { title: 'حذف ' + sel.length + ' بند نهائيًّا', okText: 'احذف', danger: true });
    });
  }

  // ── إجراءات البند الواحد (`ibToggle`/`ibSetDate` متعرَّفين فوق قبل الحارس)
  // ⚠️ الحذف بيشيل البند من الصندوق خالص — فالتأكيد بيقول إنه بينزل السلة،
  //    عشان المالك يعرف إن فيه طريق رجوع بدل ما يتردّد أو يحذف وهو خايف.
  window.ibDelete = function (sid) {
    var f = document.getElementById('d' + sid + 'f');
    if (!f) { return; }
    var item = document.getElementById('submission-' + sid);
    var note = item ? (item.querySelector('.ib-note') || {}).textContent : '';
    window.AppUI.confirm(
      'هيتشال البند «' + (note || '').trim() + '» من الصندوق خالص. '
      + 'مش هيتخصم من الخزنة ولا من عهدة المندوب (ما اتخصمش أصلاً)، '
      + 'وتلاقيه في سلة المحذوفات في سجل العمليات لو عايز ترجّعه.',
      function () { if (f.requestSubmit) { f.requestSubmit(); } else { f.submit(); } },
      { title: 'حذف البند نهائيًّا', okText: 'احذف', danger: true });
  };
  window.ibReject = function (sid) {
    window.AppUI.ask('المندوب هيشوف السبب على موبايله.', function (why) {
      why = (why || '').trim();
      if (!why) { return; }
      var f = document.getElementById('r' + sid + 'f');
      f.querySelector('input[name=reason]').value = why;
      if (f.requestSubmit) { f.requestSubmit(); } else { f.submit(); }
    }, { title: 'سبب رفض البند', okText: 'رفض البند' });
  };

  applyFilters();
})();
</script>
