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
         أرقام محسوبة أصلاً — مش محتاج طلب تاني للخادم. */ ?>
<?php partial('stat_grid', ['items' => 4, 'class' => 'mb', 'body' => function () use ($breakdown, $rate) { ?>
  <?php partial('stat', [
    'label' => 'الرصيد الحالي المتاح', 'value' => intf($breakdown['balance']),
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
    'body'  => function () use ($breakdown, $rate) {
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

<?php /* الجدول (٨ أعمدة مليانة `—`) اتشال: كل صف هنا **حركة** — إضافة عهدة أو
         عهدة سائق — مش سطر مقارنة رقمية. الرصيد الجاري بيبان كسطر صغير تحت
         المبلغ بدل عمود لوحده. */ ?>
<?php partial('panel', [
  'title'  => 'سجل حركات الخزنة',
  'icon'   => 'layers',
  'badge'  => $ledger ? ['text' => intf(count($ledger)), 'tone' => 'navy'] : null,
  'scroll' => true,
  'body'   => function () use ($ledger, $returnPath) {
    if (!$ledger) {
      partial('empty', [
        'emoji' => '📭', 'title' => 'لا حركات عهدة بعد',
      ]);
      return;
    }
    foreach ($ledger as $r) {
      $isTopup = $r['kind'] === 'topup';
      $meta = [['icon' => 'calendar', 'text' => $r['d'] ? dfmt($r['d']) : '—']];
      if ($r['party']) { $meta[] = ['icon' => 'user', 'text' => $r['party']]; }
      if (!$isTopup) {
        $meta[] = ['icon' => 'truck', 'text' => 'عهدة ' . money($r['custody'], false)];
        if ($r['note']) { $meta[] = ['icon' => 'file-text', 'text' => $r['note']]; }
      }
      $meta[] = ['icon' => 'briefcase', 'text' => 'الرصيد الجاري ' . money($r['running'], false)];
      partial('row_item', [
        'title'  => $isTopup ? ($r['party'] ?: 'إضافة عهدة يدوية') : ('عهدة سائق — ' . $r['label']),
        'icon'   => $isTopup ? 'wallet' : 'truck',
        'url'    => $isTopup ? null : url('bawalis/' . $r['id']),
        'meta'   => $meta,
        'amount' => $isTopup ? '+ ' . money($r['amount'], false) : '- ' . money($r['deduction'], false),
        'amount_tone' => $isTopup ? 'pos' : 'ex',
        'extra'  => $isTopup ? function () use ($r, $returnPath) { ?>
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
  },
]); ?>
