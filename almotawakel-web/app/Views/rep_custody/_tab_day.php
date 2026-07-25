<?php
/**
 * تبويب «اليوم» — كل حاجة عن يوم العمل المختار وبس.
 *
 * الأرقام كلها من `RepCustody::dayActivity()` و`breakdown($rep, $day)` — نفس
 * مصادر الحقيقة بتاعة الرصيد التراكمي بالظبط، فمستحيل الشاشة تقول رقم
 * والكشف يقول رقم تاني.
 *
 * متغيّرات جاية من `card.php`: $rep $repId $day $dayB $dayLocked $act
 *                              $dayDeposits $dayPending $depositState…
 */
use App\Core\FormState;

/** نوع البند في كشف اليوم — تسمية وأيقونة موحّدتان للجدول والشرائح. */
$kindOf = static function (array $r): array {
    return match ((string) ($r['src'] ?? 'permit')) {
        'misc'     => ['misc', 'نثرية', 'coins'],
        'handover' => ['handover', 'تسليم سائق', 'truck'],
        'transfer' => ['transfer', 'تحويل لزميل', 'shuffle'],
        default    => ((string) ($r['stage_key'] ?? '') === 'receipts')
            ? ['receipts', 'إيصال', 'clipboard']
            : ['expenses', 'مصروف إذن', 'trending-down'],
    };
};
$eq = static function (string $label, $value, string $cls = '') {
    echo '<div class="rc-eq-part' . ($cls !== '' ? ' ' . $cls : '') . '">'
       . '<span class="k">' . e($label) . '</span>'
       . '<span class="v">' . e(intf($value)) . '</span></div>';
};
?>

<?php /* ═══ ① معادلة عهدة اليوم — من فين لفين ═══ */ ?>
<?php partial('panel', [
  'title' => 'عهدة يوم ' . dfmt($day),
  'icon'  => 'wallet',
  'sub'   => 'بلا أي ترحيل من اليوم اللي قبله',
  'class' => 'mb',
  'body'  => function () use ($dayB, $eq) { ?>
    <div class="rc-eq">
      <?php $eq('نزل له', $dayB['deposits']); ?>
      <?php if (($dayB['transfer_in'] ?? 0) > 0): ?>
        <span class="rc-eq-op">+</span><?php $eq('من زميل', $dayB['transfer_in']); ?>
      <?php endif; ?>
      <span class="rc-eq-op">−</span><?php $eq('صرف', $dayB['spent']); ?>
      <?php if (($dayB['transfer_out'] ?? 0) > 0): ?>
        <span class="rc-eq-op">−</span><?php $eq('لزميل', $dayB['transfer_out']); ?>
      <?php endif; ?>
      <?php if (($dayB['returned'] ?? 0) > 0): ?>
        <span class="rc-eq-op">−</span><?php $eq('رجّع', $dayB['returned']); ?>
      <?php endif; ?>
      <span class="rc-eq-op">=</span><?php $eq('فاضل من عهدة اليوم', $dayB['balance'], 'res'); ?>
    </div>
    <?php if ($dayB['balance'] < 0): ?>
      <div class="note" style="margin:0 18px 16px;font-size:var(--fs-1)">
        <?= icon('alert', 14) ?> صرف أكتر من عهدة اليوم بـ<b><?= intf(abs($dayB['balance'])) ?></b> —
        ده مسموح (بيغطّيها من فلوس يوم تاني أو من جيبه)، بس يفضل تنزّله عهدة تغطّي اليوم.
      </div>
    <?php endif; ?>
  <?php },
]); ?>

<?php /* ═══ ② أداء المندوب في اليوم ═══
   ⚠️ **ممنوع رقم أصمّ**: كل شريحة زرار بيفتح بنودها بالظبط من نفس
   `RepCustody::spending()` اللي العدّاد اتحسب منها. */ ?>
<?php partial('panel', [
  'title' => 'أداء المندوب اليوم',
  'icon'  => 'bar-chart',
  'class' => 'mb',
  'body'  => function () use ($act, $rd, $dayLabel) { ?>
    <div class="rc-perf">
      <?php
      $chips = [
        ['clipboard',     'إيصالات',            $act['receipts'], 'rep_receipts'],
        ['trending-down', 'مصروفات أذونات',     $act['expenses'], 'rep_expenses'],
        ['coins',         'نثريات',             $act['misc'],     'rep_misc'],
        ['truck',         'تسليم سائقين',       $act['handover'], 'rep_handover'],
        ['shuffle',       'تحويلات لزملاء',     $act['transfer'], 'rep_transfer'],
      ];
      foreach ($chips as [$ic, $lbl, $box, $what]): ?>
        <button type="button" class="rc-chip<?= $box['cnt'] > 0 ? ' on' : '' ?>"
                data-drill="<?= e($rd($what)) ?>" data-drill-title="<?= e($lbl . ' — ' . $dayLabel) ?>">
          <span class="k"><?= icon($ic, 12) ?> <?= e($lbl) ?></span>
          <span class="v"><?= intf($box['cnt']) ?></span>
          <span class="n"><?= $box['cnt'] > 0 ? e(money($box['sum'], false)) : '—' ?></span>
        </button>
      <?php endforeach; ?>
    </div>
    <?php if ($act['visa']['cnt'] > 0): ?>
      <div class="note" style="margin:0 18px 16px;font-size:var(--fs-1)">
        <?= icon('credit-card', 14) ?> منهم
        <button type="button" class="lnk-drill" data-drill="<?= e($rd('rep_visa')) ?>"
                data-drill-title="بنود الفيزا — <?= e($dayLabel) ?>"><b><?= intf($act['visa']['cnt']) ?></b> بند
          بـ<b><?= money($act['visa']['sum'], false) ?></b></button>
        مدفوع بكارت الفيزا — <b>ما اتخصمش من عهدته</b> (خرج من كارت المكتب).
        الكاش اللي طلع من إيده فعلاً: <b><?= money($act['cash'], false) ?></b>.
      </div>
    <?php endif; ?>
  <?php },
]); ?>

<?php /* ═══ ③ الإيصالات والمصروفات بتاعة اليوم ═══
   ⚠️ الجدول (٦ أعمدة مليانة `—`) اتشال لصالح `row_item`: البند كيان مش صف
   أرقام للمقارنة، والصف بيتقرا بنظرة وبيشتغل على الموبايل بلا تمرير أفقي. */ ?>
<?php partial('panel', [
  'title'  => 'اللي دفعه اليوم',
  'icon'   => 'clipboard',
  'badge'  => $act['rows'] ? ['text' => intf(count($act['rows'])), 'tone' => 'navy'] : null,
  'action' => $act['rows'] ? ['label' => 'كل البنود', 'drill' => $rd('rep_spent'),
                              'title' => 'كل اللي صرفه في ' . $dayLabel] : null,
  'scroll' => true,
  'class'  => 'mb',
  'body'   => function () use ($act, $day, $kindOf) {
    if (!$act['rows']) {
      partial('empty', [
        'emoji' => '🧾',
        'title' => 'ما صرفش حاجة في يوم ' . dfmt($day),
        'note'  => 'البنود بتظهر هنا بعد ما تعتمدها من صندوق المراجعة.',
        'action' => ['label' => 'صندوق المراجعة', 'url' => url('rep-custody/inbox')],
      ]);
      return;
    }
    foreach ($act['rows'] as $s) {
      [$k, $kLbl, $kIc] = $kindOf($s);
      $meta = [['icon' => 'layers', 'text' => $kLbl]];
      if ($k === 'misc') {
        $meta[] = ['icon' => 'file-text', 'text' => $s['category'] ?: 'مصروف عام بلا إذن'];
      } elseif ($k === 'handover') {
        $meta[] = ['icon' => 'user', 'text' => $s['driver_name'] ?: '—'];
        if (!empty($s['bill_number'])) { $meta[] = ['icon' => 'truck', 'text' => 'بوليصة ' . $s['bill_number']]; }
      } elseif ($k === 'transfer') {
        $meta[] = ['icon' => 'user', 'text' => 'لـ ' . ($s['other_name'] ?? '—')];
      } else {
        $meta[] = ['icon' => 'folder', 'text' => \App\Models\Permit::noLabel($s['permit_type'])
                   . ' ' . ($s['permit_no'] ?: '#' . $s['permit_id'])];
        $meta[] = ['icon' => 'user', 'text' => $s['client'] ?: $s['company'] ?: '—'];
      }
      if (!empty($s['ref_no'])) { $meta[] = ['icon' => 'clipboard', 'text' => 'إيصال ' . $s['ref_no']]; }
      if ((int) $s['qty'] > 1) { $meta[] = ['icon' => 'package', 'text' => 'كمية ' . intf((int) $s['qty'])]; }
      partial('row_item', [
        'title'  => $s['note'] ?: $kLbl,
        'icon'   => $kIc,
        'url'    => !empty($s['permit_id']) ? url('permits/' . (int) $s['permit_id']) : null,
        'meta'   => $meta,
        'chips'  => !empty($s['is_visa'])
            ? [['icon' => 'credit-card', 'text' => 'فيزا — مش من العهدة']] : [],
        'amount' => money($s['amount'], false),
        'amount_tone' => empty($s['is_visa']) ? 'ex' : '',
      ]);
    }
  },
]); ?>

<?php /* ═══ ④ بنود اليوم المستنية مراجعة ═══ */ ?>
<?php if ($dayPending): ?>
  <?php partial('panel', [
    'title'  => 'بعتهم اليوم ولسه مستنيين مراجعتك',
    'icon'   => 'hourglass',
    'badge'  => ['text' => intf(count($dayPending)), 'tone' => 'red'],
    'action' => ['label' => 'افتح صندوق المراجعة', 'url' => url('rep-custody/inbox')],
    'scroll' => true,
    'class'  => 'mb',
    'body'   => function () use ($dayPending) {
      foreach ($dayPending as $p) {
        $pk = (string) $p['kind'];
        [$ic, $lbl] = $pk === 'receipt' ? ['clipboard', 'إيصال']
                    : ($pk === 'misc' ? ['coins', 'نثرية'] : ['trending-down', 'مصروف']);
        $meta = [['icon' => 'layers', 'text' => $lbl]];
        if (!empty($p['permit_id'])) {
          $meta[] = ['icon' => 'folder', 'text' => \App\Models\Permit::noLabel($p['permit_type'] ?? 'sader')
                     . ' ' . ($p['permit_no'] ?: '#' . (int) $p['permit_id'])];
        } else {
          $meta[] = ['icon' => 'file-text', 'text' => 'بلا إذن'];
        }
        if ((int) $p['qty'] > 1) { $meta[] = ['icon' => 'package', 'text' => 'كمية ' . intf((int) $p['qty'])]; }
        if (!empty($p['photo'])) { $meta[] = ['icon' => 'clipboard', 'text' => 'مرفق صورة']; }
        partial('row_item', [
          'title'  => $p['note'] ?: $lbl,
          'icon'   => $ic,
          'url'    => url('rep-custody/inbox'),
          'meta'   => $meta,
          'chips'  => !empty($p['is_visa']) ? [['icon' => 'credit-card', 'text' => 'فيزا']] : [],
          'amount' => money($p['amount'], false),
          'amount_tone' => 'wait',
        ]);
      }
    },
  ]); ?>
<?php endif; ?>

<div class="grid-2">
  <?php /* ═══ ⑤ تنزيل عهدة لليوم ═══ */ ?>
  <div class="d-card" id="deposit-form">
    <div class="d-card-head"><h3><?= icon('wallet', 18) ?> <span>نزّله عهدة</span></h3></div>
    <?= formErrorSummary($depositErrors) ?>
    <form class="js-dirty-form" method="post" action="<?= url('rep-custody/' . $repId . '/deposit') ?>" style="padding:14px 18px">
      <?= csrfField() ?>
      <?= formStateFields($depositState['token'], $depositState['back']) ?>
      <div class="field"><label>المبلغ</label>
        <input type="number" step="0.01" min="0.01" name="amount" required placeholder="مثلاً 2000"
               value="<?= e($depositOld['amount'] ?? '') ?>"<?= fieldErrorAttrs('amount', $depositErrors) ?>>
        <?= fieldError('amount', $depositErrors) ?></div>
      <div class="field"><label>التاريخ</label>
        <input type="date" name="dep_date" value="<?= e($depositOld['dep_date'] ?? $day) ?>"<?= fieldErrorAttrs('dep_date', $depositErrors) ?>>
        <?= fieldError('dep_date', $depositErrors) ?>
        <div class="hint">الافتراضي يوم العمل المختار — العهدة بتتحسب على اليوم ده لوحده.</div></div>
      <div class="field"><label>ملاحظة (اختياري)</label>
        <input type="text" name="note" maxlength="255" placeholder="عهدة يوم…"
               value="<?= e($depositOld['note'] ?? '') ?>"></div>
      <button class="btn gold" type="submit" data-primary-submit><?= icon('plus', 15) ?> نزّل العهدة</button>
      <div class="hint">ما بيخصمش من خزنة المكتب — عهدة المناديب حساب منفصل.</div>
    </form>
  </div>

  <?php /* ═══ ⑥ تنزيلات اليوم ═══ */ ?>
  <?php partial('panel', [
    'title'  => 'نزل له في يوم ' . dfmt($day),
    'icon'   => 'inbox',
    'badge'  => $dayDeposits ? ['text' => intf(count($dayDeposits)), 'tone' => 'navy'] : null,
    'action' => $dayDeposits ? ['label' => 'التفاصيل', 'drill' => $rd('rep_deposits'),
                                'title' => 'العهدة اللي نزلت له في ' . dfmt($day)] : null,
    'scroll' => true,
    'body'   => function () use ($dayDeposits, $repId) {
      if (!$dayDeposits) {
        partial('empty', ['emoji' => '📥', 'title' => 'مفيش عهدة نزلت له في اليوم ده',
                          'note' => 'استخدم فورم «نزّله عهدة» على الشمال.']);
        return;
      }
      foreach ($dayDeposits as $d) {
        partial('row_item', [
          'title'  => $d['note'] ?: 'تنزيل عهدة',
          'icon'   => 'wallet',
          'meta'   => [['icon' => 'calendar', 'text' => dfmt($d['dep_date'])]],
          'amount' => money($d['amount'], false),
          'amount_tone' => 'pos',
          // زر الحذف جوّه الصف نفسه — مكوّن `row_item` بيوفّر خانة `extra` لده
          'extra'  => function () use ($d, $repId) { ?>
            <form method="post" action="<?= url('rep-custody/deposit/' . $d['id'] . '/delete') ?>"
                  class="confirm-delete" data-confirm="حذف تنزيل العهدة ده؟">
              <?= csrfField() ?>
              <?= formStateFields(\App\Core\FormState::token(), 'rep-custody/' . $repId . '?tab=day') ?>
              <button class="edit" type="submit" title="حذف"><?= icon('trash', 13) ?></button>
            </form>
          <?php },
        ]);
      }
    },
  ]); ?>
</div>
