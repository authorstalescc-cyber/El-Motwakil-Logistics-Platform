<?php
use App\Core\FormState;
$topupState = $topupState ?? ['token'=>FormState::token(), 'old'=>[], 'errors'=>[], 'back'=>'custody'];
$topupOld = $topupState['old'];
$topupErrors = $topupState['errors'];
$repList = $repList ?? [];
$repDepState = $repDepState ?? ['token'=>FormState::token(), 'old'=>[], 'errors'=>[], 'back'=>'custody'];
$repDepOld = $repDepState['old'];
$repDepErrors = $repDepState['errors'];
$returnPath = current_app_path() ?: 'custody';
?>
<div class="toolbar">
  <div class="section-head" ><?= icon('briefcase', 18) ?> خزنة المكتب</div>
  <div class="spacer"></div>
  <a class="btn ghost" href="<?= url('closing') ?>">التقفيل اليومي ›</a>
</div>

<?php if(!$lastClosed): ?>
<div class="card mb p5-pad-sm p5-edge-gold-r">
  <?= icon('info', 15) ?> لسه مفيش أي يوم متقفل — الرصيد الحالي محسوب من كل الحركة. لتثبيت الأرصدة يومًا بيوم افتح <a href="<?= url('closing') ?>">التقفيل اليومي</a> واضغط «اقفل اليوم».
</div>
<?php endif; ?>

<?php /* ⚠️ **ممنوع رقم أصمّ**: الرصيد بيفتح تفكيكه، وكل بند في التفكيك بيقول
         جاي منين. التفكيك **جاهز في الصفحة** (`#custodyBreak`) لأنه أربعة
         أرقام محسوبة أصلاً — مش محتاج طلب تاني للخادم.

         ⚠️⚠️ **الكارت الأول اتسمّى بالنموذج الفعّال مش بالكلمة الثابتة.**
            `Closing::currentBalance()` معناه بيتغيّر: في النموذج المجمّع
            (`bankTopupActive`) هو **الدرج + جيوب المناديب**، فتسميته «المتاح»
            كانت بتقول إن فلوس في جيب مندوب متاحة في الدرج. التفكيك من
            `Closing::walletSplit()` — نفس الدالة اللي `/closing` و`/safe-audit`
            بيستخدموها، فمستحيل الشاشات التلاتة يختلفوا. */ ?>
<?php partial('stat_grid', ['items' => 4, 'class' => 'mb', 'body' => function () use ($breakdown, $rate, $wallet, $aggregate) { ?>
  <?php partial('stat', [
    'label' => $aggregate ? 'رصيد الخزنة (شامل جيوب المناديب)' : 'الرصيد الحالي المتاح',
    'value' => intf($breakdown['balance']),
    'unit'  => $aggregate ? 'في الدرج ' . intf($wallet['drawer']) : null,
    'icon'  => 'briefcase', 'tone' => $breakdown['balance'] < 0 ? 'red' : 'gold', 'drill' => '#custodyBreak', 'title' => 'من إيه اتكوّن الرصيد',
  ]); ?>
  <?php partial('stat', [
    'label' => $breakdown['anchorDate'] ? 'رصيد آخر إقفال' : 'من البداية',
    'value' => intf($breakdown['anchor']),
    'unit'  => $breakdown['anchorDate'] ? dfmt($breakdown['anchorDate']) : null,
    'icon'  => 'lock', 'tone' => 'navy',
    'drill' => '#custodyBreak', 'title' => 'من إيه اتكوّن الرصيد',
  ]); ?>
  <?php partial('stat', [
    'label' => 'إضافات عهدة' . ($breakdown['anchorDate'] ? ' بعده' : ''),
    'value' => intf($breakdown['topups']),
    'icon'  => 'wallet', 'tone' => 'green',
    'drill' => '#custodyBreak', 'title' => 'من إيه اتكوّن الرصيد',
  ]); ?>
  <?php partial('stat', [
    'label' => 'المخصوم (مصروفات + عهد سائقين)',
    'value' => intf($breakdown['expenses'] + $breakdown['driver']),
    'icon'  => 'trending-down', 'tone' => 'red',
    'drill' => '#custodyBreak', 'title' => 'من إيه اتكوّن الرصيد',
  ]); ?>
<?php }]); ?>

<?php /* جسم النافذة — جاهز في الصفحة ومخفي؛ النافذة العامة بتنسخ محتواه. */ ?>
<div id="custodyBreak" hidden>
  <?php partial('drill', [
    'how'   => 'الرصيد بيتحسب من آخر إقفال يومي مثبَّت، وبعده كل إضافة عهدة تزوّد وكل مصروف أو عهدة سائق تنقّص. الرقم ده هو نفسه اللي في شاشة التقفيل.',
    'total' => ['label' => 'الرصيد الحالي', 'value' => money($breakdown['balance'], false)],
    'more'  => ['label' => 'التقفيل اليومي', 'url' => url('closing')],
    'body'  => function () use ($breakdown, $rate, $wallet, $aggregate) {
      /* ⚠️ تفكيك المحفظة أول حاجة في النافذة **لما يكون فارقًا بس**: في نموذج
         «الدرج بس» الرقم = الدرج فعلاً، فسطر «في الدرج» ساعتها تكرار مشتِّت. */
      if ($aggregate) {
        partial('row_item', [
          'title' => 'في درج المكتب',
          'icon'  => 'briefcase',
          'meta'  => [['icon' => 'info', 'text' => 'اللي ينعدّ بالإيد']],
          'amount' => money($wallet['drawer'], false),
          'amount_tone' => $wallet['drawer'] < 0 ? 'neg' : 'pos',
        ]);
        foreach ($wallet['reps'] as $rep) {
          partial('row_item', [
            'title' => 'مع ' . $rep['name'],
            'icon'  => 'user',
            'url'   => url('rep-custody/' . (int) $rep['id']),
            'meta'  => [['icon' => 'info', 'text' => 'عهدة مندوب']],
            'amount' => money($rep['balance'], false),
            'amount_tone' => $rep['balance'] < 0 ? 'neg' : 'pos',
          ]);
        }
      }
      partial('row_item', [
        'title' => $breakdown['anchorDate'] ? 'رصيد آخر إقفال (' . dfmt($breakdown['anchorDate']) . ')' : 'من البداية',
        'icon'  => 'lock',
        'meta'  => [['icon' => 'info', 'text' => 'نقطة البداية المثبَّتة']],
        'amount' => money($breakdown['anchor'], false),
      ]);
      partial('row_item', [
        'title' => 'إضافات عهدة' . ($breakdown['anchorDate'] ? ' بعده' : ''),
        'icon'  => 'wallet',
        'meta'  => [['icon' => 'info', 'text' => 'بتزوّد الخزنة']],
        'amount' => '+ ' . money($breakdown['topups'], false), 'amount_tone' => 'pos',
      ]);
      partial('row_item', [
        'title' => 'عهد السائقين (بعد خصم ' . intf($rate) . ')',
        'icon'  => 'truck',
        'meta'  => [['icon' => 'info', 'text' => 'المخصوم بعد خصم المستحق']],
        'amount' => '- ' . money($breakdown['driver'], false), 'amount_tone' => 'ex',
      ]);
      partial('row_item', [
        'title' => 'المصروفات',
        'icon'  => 'trending-down',
        'meta'  => [['icon' => 'info', 'text' => 'أذونات ومصروفات يدوية وكاش مناديب']],
        'amount' => '- ' . money($breakdown['expenses'], false), 'amount_tone' => 'ex',
      ]);
    },
  ]); ?>
</div>

<!-- إضافة عهدة يدوية للرصيد -->
<div class="card mb oc-pad" id="topup">
  <div class="section-head oc-sec"><?= icon('plus', 17) ?> إضافة عهدة للرصيد</div>
  <?= formErrorSummary($topupErrors) ?>
  <form class="js-dirty-form" method="post" action="<?= url('custody/topup') ?>" class="p5-row end">
    <?= csrfField() ?>
    <?= formStateFields($topupState['token'], $topupState['back']) ?>
    <div class="field flush w160">
      <label>التاريخ</label>
      <input type="date" name="cust_date" value="<?= e($topupOld['cust_date'] ?? work_date()) ?>"<?= fieldErrorAttrs('cust_date', $topupErrors) ?>>
      <?= fieldError('cust_date', $topupErrors) ?>
    </div>
    <div class="field flush grow180">
      <label>البيان / المصدر</label>
      <input type="text" name="note" value="<?= e($topupOld['note'] ?? '') ?>" placeholder="مصدر العهدة (اختياري)">
    </div>
    <div class="field flush w150">
      <label>المبلغ</label>
      <input data-money type="number" step="0.01" min="0.01" name="amount" value="<?= e($topupOld['amount'] ?? '') ?>" placeholder="0" required<?= fieldErrorAttrs('amount', $topupErrors) ?>>
      <?= fieldError('amount', $topupErrors) ?>
    </div>
    <button class="btn gold" type="submit" data-primary-submit>إضافة للرصيد</button>
  </form>
</div>

<?php /* ═══ نزّل عهدة لمندوب **من الدرج** ═══
         ⚠️ الكاش بيخرج من الدرج اللي قدّام المالك — فمكانه الطبيعي هنا مش في
            كارت المندوب. المصدر مثبَّت `office` في الكنترولر مش في الفورم.
         ⚠️ بتظهر بس لو `sourceMatters()` — من غير النموذجين كل المصادر واحدة
            فعلاً، والشاشة ساعتها هتوعد بحاجة ما بتحصلش. */ ?>
<?php if ($repList && \App\Models\RepCustody::sourceMatters()): ?>
<div class="card mb oc-pad" id="rep-deposit">
  <div class="section-head oc-sec"><?= icon('wallet', 17) ?> نزّل عهدة لمندوب من الدرج</div>
  <?= formErrorSummary($repDepErrors) ?>
  <form class="js-dirty-form p5-row end" method="post" action="<?= url('custody/rep-deposit') ?>">
    <?= csrfField() ?>
    <?= formStateFields($repDepState['token'], $repDepState['back']) ?>
    <div class="field flush w160">
      <label>المندوب</label>
      <select name="rep_id" required<?= fieldErrorAttrs('rep_id', $repDepErrors) ?>>
        <option value="">— اختار —</option>
        <?php foreach ($repList as $rid => $rname): ?>
          <option value="<?= (int) $rid ?>" <?= (string) ($repDepOld['rep_id'] ?? '') === (string) $rid ? 'selected' : '' ?>><?= e($rname) ?></option>
        <?php endforeach; ?>
      </select>
      <?= fieldError('rep_id', $repDepErrors) ?>
    </div>
    <div class="field flush w160">
      <label>التاريخ</label>
      <input type="date" name="dep_date" value="<?= e($repDepOld['dep_date'] ?? work_date()) ?>"<?= fieldErrorAttrs('dep_date', $repDepErrors) ?>>
      <?= fieldError('dep_date', $repDepErrors) ?>
    </div>
    <div class="field flush grow180">
      <label>ملاحظة (اختياري)</label>
      <input type="text" name="note" maxlength="255" value="<?= e($repDepOld['note'] ?? '') ?>" placeholder="عهدة يوم…">
    </div>
    <div class="field flush w150">
      <label>المبلغ</label>
      <input data-money type="number" step="0.01" min="0.01" name="amount" value="<?= e($repDepOld['amount'] ?? '') ?>" placeholder="0" required<?= fieldErrorAttrs('amount', $repDepErrors) ?>>
      <?= fieldError('amount', $repDepErrors) ?>
    </div>
    <button class="btn gold" type="submit" data-primary-submit><?= icon('plus', 15) ?> نزّل من الدرج</button>
  </form>
  <div class="hint p5-pad-sm">
    <?php if (\App\Models\RepCustody::newSafeRuleActive()): ?>
      الكاش بيتخصم من الخزنة يوم التنزيل وبيتحوّل لعهدة المندوب.
    <?php else: ?>
      تحويل داخلي: الخزنة زي ما هي (الفلوس لسه مع الشركة) — الدرج بس هو اللي بينقص وجيب المندوب بيزيد.
      الفلوس اللي جاية من البنك للمندوب على طول تتسجّل من كارت المندوب باختيار «من البنك».
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php /* ═══ حركة الخزنة في فترة ═══
         ⚠️ **الصفوف والرصيد الجاري من `Custody::movements()`** — المبنية على
            `DailyReview::officeCashItems` + `Closing`. السجل القديم كان بيجمع
            الإضافات ناقص عهد السائقين وبس (بلا مصروفات وبلا رصيد آخر إقفال)
            فكان بيقول رقمًا يخالف الكارت اللي فوقه بمئات الألوف.
         ⚠️ **آخر سطر لازم يساوي رصيد آخر المدة** — ده اللي `verify_custody_ui`
            بيثبته، مش تعليق طموح. */ ?>

<form class="cs-filters" method="get" action="<?= url('custody') ?>">
  <?php
  $today = work_date();
  $pm    = date('Y-m-d', strtotime(date('Y-m-01', strtotime($today)) . ' -1 day'));
  $presets = [
    'اليوم'          => [$today, $today],
    'الشهر الحالي'   => [date('Y-m-01', strtotime($today)), $today],
    'الشهر اللي فات' => [date('Y-m-01', strtotime($pm)), date('Y-m-t', strtotime($pm))],
  ];
  foreach ($presets as $lbl => [$a, $b]):
    $on = $move['from'] === $a && $move['to'] === $b;
  ?>
    <a class="cs-period-btn<?= $on ? ' on' : '' ?>"
       href="<?= url('custody') ?>?from=<?= e($a) ?>&to=<?= e($b) ?>"><?= e($lbl) ?></a>
  <?php endforeach; ?>
  <input type="date" name="from" value="<?= e($move['from']) ?>" aria-label="من">
  <input type="date" name="to" value="<?= e($move['to']) ?>" aria-label="إلى">
  <button class="btn" type="submit"><?= icon('filter', 15) ?> فلترة</button>
</form>

<?php /* تحذير وظيفي في ≤٦ كلمات — ولا واحد منهم شرح تعليمي. */ ?>
<?php if (abs($move['drift']) >= 0.005): ?>
  <div class="card mb p5-pad-sm p5-edge-gold-r">
    <?= icon('alert', 15) ?> لقطة إقفال متقادمة — فرق <?= money($move['drift'], false) ?>.
    <a href="<?= url('closing') ?>">راجع التقفيل</a>
  </div>
<?php endif; ?>
<?php if ($move['capped']): ?>
  <div class="card mb p5-pad-sm p5-edge-gold-r">
    <?= icon('info', 15) ?> الفترة أطول من <?= intf(\App\Models\SafeInventory::MAX_ROW_DAYS) ?> يوم — الإجماليات كاملة والصفوف مخفية.
  </div>
<?php endif; ?>
<?php /* ⚠️ **ممنوع قطع صامت** — والرقم بيتقال بالظبط لا بكلمة «بعض». */ ?>
<?php if ($move['truncated']): ?>
  <div class="card mb p5-pad-sm p5-edge-gold-r">
    <?= icon('info', 15) ?> آخر <?= intf(count($move['rows'])) ?> حركة من <?= intf($move['rows_total']) ?> — قصّر الفترة تشوفها كلها.
  </div>
<?php endif; ?>

<?php partial('panel', [
  'title'  => 'حركة الخزنة',
  'icon'   => 'layers',
  'badge'  => $move['rows_total']
    ? ['text' => $move['truncated']
        ? intf(count($move['rows'])) . ' من ' . intf($move['rows_total'])
        : intf($move['rows_total']), 'tone' => 'navy']
    : null,
  'scroll' => true,
  'body'   => function () use ($move, $returnPath) {
    if (!$move['rows']) {
      partial('empty', ['emoji' => '📭', 'title' => $move['capped']
        ? 'اختار فترة أقصر عشان تشوف الصفوف'
        : 'مفيش حركة في الفترة دي']);
      return;
    }
    /* سطر افتتاحي — من غيره الرصيد الجاري في أول صف بيبان كأنه طالع من العدم.
       ⚠️ لما الصفوف تتقص بيبقى المعروض **آخر** الصفوف، فسطر البداية بيقول
          الرصيد قبل أول صف معروض لا رصيد أول المدة — وإلا الحساب ما يقفلش. */
    partial('row_item', [
      'title'  => $move['truncated']
        ? 'الرصيد قبل أول صف معروض'
        : 'رصيد أول المدة (' . dfmt($move['from']) . ')',
      'icon'   => 'lock',
      'meta'   => [['icon' => 'info', 'text' => $move['truncated']
        ? 'رصيد أول المدة ' . money($move['opening'], false)
        : 'نقطة البداية']],
      'amount' => money($move['lead'], false),
    ]);
    $day = null;
    foreach ($move['rows'] as $r) {
      if ($r['date'] !== $day) {
        $day = $r['date'];
        echo '<div class="cs-day">' . icon('calendar', 15) . ' ' . e(dfmt($day)) . '</div>';
      }
      $meta = [];
      if ($r['ref'])    { $meta[] = ['icon' => 'file-text', 'text' => $r['ref']]; }
      if ($r['party'])  { $meta[] = ['icon' => 'user', 'text' => $r['party']]; }
      if ($r['by_rep']) { $meta[] = ['icon' => 'briefcase', 'text' => 'دفعها ' . $r['by_rep']]; }
      if ($r['note'])   { $meta[] = ['icon' => 'info', 'text' => $r['note']]; }
      $meta[] = ['icon' => 'briefcase', 'text' => 'الرصيد الجاري ' . money($r['running'], false)];
      partial('row_item', [
        'title'  => $r['title'],
        'icon'   => $r['icon'],
        'url'    => $r['link'] ? url($r['link']) : null,
        'meta'   => $meta,
        'amount' => ($r['signed'] >= 0 ? '+ ' : '- ') . money(abs($r['signed']), false),
        'amount_tone' => $r['signed'] >= 0 ? 'pos' : 'ex',
        /* الإضافة اليدوية بس هي اللي بتتعدّل من هنا — الباقي بيتعدّل من مصدره
           (البوليصة · الإذن · المصروف)، والرابط فوق بيوصّل له. */
        'extra'  => $r['type'] === 'topup' ? function () use ($r, $returnPath) { ?>
          <a class="edit" href="<?= url('custody/topup/' . $r['id'] . '/edit') ?>?back=<?= urlencode($returnPath) ?>"
             title="تعديل"><?= icon('edit', 13) ?></a>
          <form method="post" action="<?= url('custody/topup/' . $r['id'] . '/delete') ?>"
                class="confirm-delete" data-confirm="حذف هذه الإضافة؟">
            <?= csrfField() ?>
            <?= formStateFields(FormState::token(), $returnPath) ?>
            <button class="edit" type="submit" title="حذف"><?= icon('trash', 13) ?></button>
          </form>
        <?php } : '',
      ]);
    }
    partial('row_item', [
      'title'  => 'رصيد آخر المدة (' . dfmt($move['to']) . ')',
      'icon'   => 'briefcase',
      'meta'   => [['icon' => 'info', 'text' => 'بعد كل حركة الفترة']],
      'amount' => money($move['closing'], false),
      'amount_tone' => $move['closing'] < 0 ? 'neg' : 'pos',
    ]);
  },
]); ?>
