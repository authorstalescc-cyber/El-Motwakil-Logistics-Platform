<?php
/**
 * تبويب «الحساب الكامل» — السجل التراكمي والتسوية.
 *
 * ⚠️ التسوية بتقرا **الرصيد التراكمي** (`$b`) مش عهدة اليوم: هو «الفلوس اللي
 *    برّه معاه فعلاً» على كل الأيام. لو خلّيتها تقرا يومًا، الفلوس تضيع (v49).
 *
 * متغيّرات جاية من `card.php`: $repId $b $day $dayLedger $spending $deposits
 *                              $settlements $transfersIn $settleState…
 */
use App\Core\FormState;
$back = 'rep-custody/' . $repId . '?tab=account';
?>

<?php /* ═══ عهدة كل يوم (v49) ═══
   ⚠️ كل صف يوم مستقل بذاته — **مفيش عمود «مرحّل»** لأن مفيش ترحيل (قرار المالك:
   الفاضل من إمبارح ما بيدخلش عهدة النهاردة). */ ?>
<?php /* ⚠️ الجدول هنا **صح** ومقصود: ده مقارنة رقمية بين أيام (٨ أعمدة رقم)،
   وده بالظبط اللي الجدول أحسن فيه من الصف. الصفوف بقت `row_item` في القوايم
   اللي كل صف فيها **كيان** (بند / تنزيل / تسوية) مش سطر مقارنة. */ ?>
<div class="d-card mb" id="day-custody">
  <div class="d-card-head"><h3><?= icon('calendar', 18) ?>
    <span>عهدة كل يوم</span>
    <?php /* ⚠️ تحذير وظيفي (قاعدة v49) — الجدول ده أيام جنب بعض، ومن غير
             الشارة دي حد يجمعهم أو يفتكر إن الفاضل بيترحّل. */ ?>
    <span class="d-badge navy" title="كل يوم عهدة مستقلة — الفاضل من اليوم اللي قبله ما بيترحّلش عليها">بلا ترحيل</span></h3></div>
  <div class="pv-tbl-wrap"><table class="data pv-tbl">
    <thead><tr>
      <th>اليوم</th>
      <th>نزل له</th>
      <th>صرف</th>
      <th>وصله من زميل</th>
      <th>حوّل لزميل</th>
      <th>رجّع</th>
      <th>فاضل معاه</th>
      <th>مستني مراجعة</th>
      <th data-no-sort></th>
    </tr></thead>
    <tbody>
      <?php if (!$dayLedger): ?>
        <tr><td colspan="9">
          <?php partial('empty', [
            'emoji' => '📆',
            'title' => 'مفيش أي حركة عهدة له لسه',
            'action' => ['label' => '💰 نزّله عهدة', 'url' => url('rep-custody/' . $repId . '?tab=day#deposit-form')],
          ]); ?>
        </td></tr>
      <?php else: foreach ($dayLedger as $d): ?>
        <tr<?= $d['day'] === $day ? ' class="rc-row-today"' : '' ?>>
          <td style="white-space:nowrap;font-weight:600">
            <a href="<?= url('rep-custody/' . $repId . '?tab=day') ?>"><?= e(dfmt($d['day'])) ?></a>
            <?php if ($d['day'] === $day): ?><span class="pill st-done">يوم الشغل</span><?php endif; ?>
          </td>
          <td><?= $d['deposits'] > 0 ? money($d['deposits']) : '—' ?></td>
          <td><?= $d['spent'] > 0 ? money($d['spent']) : '—' ?></td>
          <td><?= $d['transfer_in'] > 0 ? money($d['transfer_in']) : '—' ?></td>
          <td><?= $d['transfer_out'] > 0 ? money($d['transfer_out']) : '—' ?></td>
          <?php /* سالب = تصفية رصيد سالب (المكتب دفع له) — لازم تبان مش تختفي */ ?>
          <td><?= abs($d['returned']) > 0.005
                 ? ($d['returned'] < 0 ? 'دفعناله ' . money(abs($d['returned'])) : money($d['returned']))
                 : '—' ?></td>
          <?php /* «فاضل معاه» = `pocket` (بعد خصم المستنّي كمان) — نفس تعريف
                   كل شاشات العهدة. `balance` (بعد المعتمد بس) بيبان تحته لو فيه
                   فرق، عشان الفرق يتشرح بدل ما يختفي. */ ?>
          <td style="font-weight:700;<?= $d['pocket'] < 0 ? 'color:#B3261E' : '' ?>">
            <?= money($d['pocket']) ?>
            <?php if ($d['pending'] > 0): ?>
              <div style="font-size:var(--fs-1);font-weight:400;color:var(--grey)">قبل خصم المستني <?= money($d['balance'], false) ?></div>
            <?php endif; ?>
            <?php if ($d['pocket'] < 0): ?>
              <div style="font-size:var(--fs-1);font-weight:400;color:var(--grey)">صرف أكتر من عهدة اليوم</div>
            <?php endif; ?>
          </td>
          <td><?= $d['pending'] > 0 ? money($d['pending']) : '—' ?></td>
          <td style="white-space:nowrap">
            <?php if ($d['locked']): ?>
              <span class="pill st-partial" title="اليوم متقفل — المندوب مش هيقدر يسجّل عليه"><?= icon('alert', 14) ?> متقفل</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<?php /* ═══ تصفية العهدة بضغطة (الطريقة المعتمدة) ═══
   الجزء نفسه اللي في تبويب «اليوم» — مصدر واحد للرقم وللزرار. */ ?>
<?php include __DIR__ . '/_settle_zero.php'; ?>

<?php /* ═══ تسوية جزئية (المندوب رجّع جزء بس) ═══
   ⚠️ فضلت للحالات النادرة اللي المندوب بيسلّم فيها جزء من الفاضل. الطريقة
   المعتمدة للتقفيل هي زرار التصفية فوق — كتابة الرقم بالإيد هي اللي كانت
   بتسيب فروق فالرصيد يفضل مترحّل. */ ?>
<?php /* ⚠️ بتتفتح لوحدها لو الخادم رجّع خطأ — وإلا المالك يشوف رسالة فشل
         لفورم مطويّ ومش عارف منين يصلّحه. */ ?>
<details class="d-card mb" id="settle-form"<?= $settleErrors ? ' open' : '' ?>>
  <summary class="d-card-head" style="cursor:pointer"><h3><?= icon('coins', 18) ?>
    <span>تسوية جزئية</span></h3></summary>
  <?= formErrorSummary($settleErrors) ?>
  <form class="js-dirty-form" method="post" action="<?= url('rep-custody/' . $repId . '/settle') ?>" style="padding:14px 18px">
    <?= csrfField() ?>
    <?= formStateFields($settleState['token'], $settleState['back']) ?>
    <?php if ($b['balance'] < 0): ?>
      <div class="note mb">الرصيد سالب: الشركة مستحق عليها للمندوب <?= money(abs($b['balance']), false) ?>. هذا وضع عمل مسموح، ولا يوجد مبلغ يرجعه الآن.</div>
    <?php endif; ?>
    <div class="form-grid two">
      <div class="field"><label>رجّع كام؟ (<?= $b['balance'] < 0 ? 'مستحق له ' . money(abs($b['balance']), false) : 'الباقي معاه ' . money($b['balance'], false) ?>)</label>
        <input type="number" step="0.01" min="0" name="returned" required
               max="<?= e((string) max($b['balance'], 0)) ?>"
               value="<?= e($settleOld['returned'] ?? (string) max($b['balance'], 0)) ?>"<?= fieldErrorAttrs('returned', $settleErrors) ?>>
        <?= fieldError('returned', $settleErrors) ?></div>
      <div class="field"><label>تاريخ التسوية</label>
        <input type="date" name="settle_date" value="<?= e($settleOld['settle_date'] ?? $day) ?>"<?= fieldErrorAttrs('settle_date', $settleErrors) ?>>
        <?= fieldError('settle_date', $settleErrors) ?></div>
    </div>
    <div class="field"><label>ملاحظة (اختياري)</label>
      <input type="text" name="note" value="<?= e($settleOld['note'] ?? '') ?>" maxlength="255"></div>
    <div class="note mb" style="font-size:var(--fs-1)">
      المرتجع بيقفل حساب العهدة مع المندوب بس — <b>مش بيتضاف للخزنة</b>، لأن الفلوس
      دي أصلاً ماخرجتش منها: الخزنة بتنقص وقت ما المندوب يصرف، مش وقت ما تنزّلها له.
    </div>
    <button class="btn" type="submit" data-primary-submit <?= $b['balance'] <= 0 ? 'disabled' : '' ?>><?= icon('check-circle', 15) ?> سجّل التسوية</button>
  </form>
</details>

<?php /* ═══ كل اللي صرفه (تراكمي) ═══
   الجدول (٦ أعمدة) اتشال: كل صف هنا **بند** مش سطر مقارنة رقمية. */ ?>
<?php partial('panel', [
  'title'  => 'كل اللي دفعه من عهدته',
  'icon'   => 'clipboard',
  'badge'  => $spending ? ['text' => intf(count($spending)), 'tone' => 'navy'] : null,
  'scroll' => true,
  'class'  => 'mb',
  'body'   => function () use ($spending, $day) {
    if (!$spending) {
      partial('empty', ['emoji' => '🧾', 'title' => 'لسه ما صرفش حاجة من عهدته',]);
      return;
    }
    foreach ($spending as $s) {
      $src = $s['src'] ?? 'permit';
      $isReceipt = $src === 'permit' && ($s['stage_key'] ?? '') === 'receipts';
      [$ic, $kind] = match ($src) {
        'misc'     => ['coins', $s['category'] ?: 'نثرية'],
        'handover' => ['truck', 'تسليم سائق'],
        'bill'     => ['truck', 'عهدة بوليصة'],   // v60 — أضافها من موبايله
        'transfer' => ['shuffle', 'تحويل لمندوب'],
        default    => $isReceipt ? ['clipboard', 'إيصال'] : ['trending-down', 'مصروف إذن'],
      };
      $meta = [['icon' => 'layers', 'text' => $kind]];
      if ($src === 'misc') {
        $meta[] = ['icon' => 'file-text', 'text' => 'مصروف عام بلا إذن'];
      } elseif ($src === 'handover') {
        $meta[] = ['icon' => 'user', 'text' => $s['driver_name'] ?: '—'];
        if (!empty($s['bill_number'])) { $meta[] = ['icon' => 'truck', 'text' => 'بوليصة ' . $s['bill_number']]; }
      } elseif ($src === 'bill') {
        $meta[] = ['icon' => 'user', 'text' => $s['driver_name'] ?: '—'];
        if (!empty($s['car_no'])) { $meta[] = ['icon' => 'truck', 'text' => $s['car_no']]; }
        if (!empty($s['permit_no'])) { $meta[] = ['icon' => 'folder', 'text' => 'إذن ' . $s['permit_no']]; }
      } elseif ($src === 'transfer') {
        $meta[] = ['icon' => 'user', 'text' => 'لـ ' . ($s['other_name'] ?? '—')];
      } else {
        $meta[] = ['icon' => 'folder', 'text' => \App\Models\Permit::noLabel($s['permit_type'])
                   . ' ' . ($s['permit_no'] ?: '#' . $s['permit_id'])];
        $meta[] = ['icon' => 'user', 'text' => $s['client'] ?: $s['company'] ?: '—'];
      }
      if (!empty($s['ref_no'])) { $meta[] = ['icon' => 'clipboard', 'text' => 'إيصال ' . $s['ref_no']]; }
      if ((int) $s['qty'] > 1) { $meta[] = ['icon' => 'package', 'text' => 'كمية ' . intf((int) $s['qty'])]; }
      partial('row_item', [
        'title'  => $s['note'] ?: $kind,
        'icon'   => $ic,
        'url'    => !empty($s['permit_id']) ? url('permits/' . (int) $s['permit_id']) : null,
        'meta'   => $meta,
        'chips'  => !empty($s['is_visa']) ? [['icon' => 'credit-card', 'text' => 'فيزا — مش من العهدة']] : [],
        'amount' => money($s['amount'], false),
        'amount_tone' => empty($s['is_visa']) ? 'ex' : '',
      ]);
    }
  },
]); ?>

<div class="grid-2">
  <?php /* التحويلات الواردة من زملاء (v46) — بتزيد رصيده، فبتتعرض جنب التنزيلات */ ?>
  <?php if (!empty($transfersIn)): ?>
    <?php partial('panel', [
      'title'  => 'تحويلات وصلته من زملاء',
      'icon'   => 'shuffle',
      'badge'  => ['text' => intf(count($transfersIn)), 'tone' => 'navy'],
      'scroll' => true,
      'body'   => function () use ($transfersIn, $back) {
        foreach ($transfersIn as $t) {
          partial('row_item', [
            'title'  => $t['note'] ?: 'تحويل من زميل',
            'icon'   => 'shuffle',
            'meta'   => [['icon' => 'user', 'text' => 'من ' . ($t['other_name'] ?? '—')],
                         ['icon' => 'calendar', 'text' => dfmt($t['transfer_date'])]],
            'amount' => money($t['amount'], false),
            'amount_tone' => 'pos',
            'extra'  => function () use ($t, $back) { ?>
              <form method="post" action="<?= url('rep-custody/transfer/' . (int) $t['id'] . '/delete') ?>"
                    class="confirm-delete" data-confirm="إلغاء التحويل ده؟ هيرجع لعهدة المُرسِل.">
                <?= csrfField() ?>
                <?= formStateFields(FormState::token(), $back) ?>
                <button class="edit" type="submit" title="إلغاء التحويل"><?= icon('trash', 13) ?></button>
              </form>
            <?php },
          ]);
        }
      },
    ]); ?>
  <?php endif; ?>

  <?php partial('panel', [
    'title'  => 'كل تنزيلات العهدة',
    'icon'   => 'wallet',
    'badge'  => $deposits ? ['text' => intf(count($deposits)), 'tone' => 'navy'] : null,
    'scroll' => true,
    'body'   => function () use ($deposits, $day, $back) {
      if (!$deposits) {
        partial('empty', ['emoji' => '💰', 'title' => 'لا تنزيلات بعد',]);
        return;
      }
      foreach ($deposits as $d) {
        partial('row_item', [
          'title'  => $d['note'] ?: 'تنزيل عهدة',
          'icon'   => 'wallet',
          'meta'   => [['icon' => 'calendar', 'text' => dfmt($d['dep_date'])]],
          'amount' => money($d['amount'], false),
          'amount_tone' => 'pos',
          'extra'  => function () use ($d, $back) { ?>
            <form method="post" action="<?= url('rep-custody/deposit/' . $d['id'] . '/delete') ?>"
                  class="confirm-delete" data-confirm="حذف تنزيل العهدة ده؟">
              <?= csrfField() ?>
              <?= formStateFields(FormState::token(), $back) ?>
              <button class="edit" type="submit" title="حذف"><?= icon('trash', 13) ?></button>
            </form>
          <?php },
        ]);
      }
    },
  ]); ?>

  <?php partial('panel', [
    'title'  => 'التسويات',
    'icon'   => 'check-circle',
    'badge'  => $settlements ? ['text' => intf(count($settlements)), 'tone' => 'navy'] : null,
    'scroll' => true,
    'body'   => function () use ($settlements, $back) {
      if (!$settlements) {
        partial('empty', ['emoji' => '🤝', 'title' => 'لا تسويات بعد',]);
        return;
      }
      foreach ($settlements as $s) {
        // ⚠️ `returned` ممكن يكون **سالبًا** بعد تصفية رصيد سالب (المكتب هو اللي
        //    دفع للمندوب). لازم يتعرض بالمعنى الصح مش بالرقم الخام.
        $ret = (float) $s['returned'];
        partial('row_item', [
          'title'  => $ret < 0 ? 'تصفية — المكتب دفع له' : 'تسوية حساب',
          'icon'   => 'check-circle',
          'meta'   => [['icon' => 'calendar', 'text' => dfmt($s['settle_date'])],
                       ['icon' => 'info', 'text' => trim((string) ($s['note'] ?? '')) !== ''
                        ? (string) $s['note'] : 'بدون أثر على الخزنة']],
          'amount' => money(abs($ret), false),
          'extra'  => function () use ($s, $back) { ?>
            <form method="post" action="<?= url('rep-custody/settlement/' . $s['id'] . '/delete') ?>"
                  class="confirm-delete" data-confirm="حذف التسوية دي؟">
              <?= csrfField() ?>
              <?= formStateFields(FormState::token(), $back) ?>
              <button class="edit" type="submit" title="حذف"><?= icon('trash', 13) ?></button>
            </form>
          <?php },
        ]);
      }
    },
  ]); ?>
</div>

<style>
/* صف يوم العمل بيتلوّن في كل جداول الحساب عشان المالك يلاقيه بسرعة */
.rc-row-today > td{background:var(--gold-light)}
</style>
