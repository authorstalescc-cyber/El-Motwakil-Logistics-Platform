<?php
use App\Core\FormState;
use App\Models\OfficeDriverStatement;

$s = $statement;
$cid = (int) $car['id'];
$periodQuery = http_build_query(['from' => $s['from'], 'to' => $s['to']]);
$closingTone = $s['closing'] < -0.005 ? 'neg' : 'pos';
$closingLabel = $s['closing'] < -0.005 ? 'مستحق للسائق' : 'مع السائق';
$spent = (float) $s['totals']['expenses'] + (float) $s['totals']['receipts'];
?>
<div class="dash2 od-page">
  <div class="page-actions">
    <?php partial('back_link', ['to' => 'cars/' . $cid, 'label' => 'كارت السيارة']); ?>
    <span class="spacer"></span>
    <a class="btn ghost" target="_blank" rel="noopener"
       href="<?= e(url('cars/' . $cid . '/driver/print?' . $periodQuery)) ?>">
      <?= icon('file-text', 15) ?> طباعة
    </a>
    <?php if ($ready): ?>
      <form method="post" action="<?= e(url('cars/' . $cid . '/driver/issue')) ?>">
        <?= csrfField() ?>
        <input type="hidden" name="_form_token" value="<?= e($issueToken) ?>">
        <input type="hidden" name="from" value="<?= e($s['from']) ?>">
        <input type="hidden" name="to" value="<?= e($s['to']) ?>">
        <button class="btn primary" type="submit"><?= icon('check', 15) ?> إصدار البيان</button>
      </form>
    <?php endif; ?>
  </div>

  <section class="od-hero">
    <div class="od-driver">
      <span class="od-avatar"><?= e(mb_substr($s['driver'] ?: 'س', 0, 1, 'UTF-8')) ?></span>
      <div>
        <span class="od-kicker"><?= icon('truck', 14) ?> حساب سائق السيارة</span>
        <h1><?= e($s['driver']) ?></h1>
        <div class="od-driver-meta">
          <span><?= icon('truck', 13) ?> <?= e($car['name']) ?></span>
          <span><?= icon('clipboard', 13) ?> <?= e($car['plate']) ?></span>
        </div>
      </div>
    </div>

    <div class="od-balance">
      <span><?= e($closingLabel) ?></span>
      <b class="<?= e($closingTone) ?>"><?= e(money0(abs((float) $s['closing']))) ?></b>
      <small><?= e(dfmt($s['from'])) ?> — <?= e(dfmt($s['to'])) ?></small>
    </div>

    <form method="get" action="<?= e(url('cars/' . $cid . '/driver')) ?>" class="od-period">
      <label><span>من</span><input type="date" name="from" value="<?= e($s['from']) ?>"></label>
      <label><span>إلى</span><input type="date" name="to" value="<?= e($s['to']) ?>"></label>
      <button class="btn" type="submit"><?= icon('search', 14) ?> عرض</button>
    </form>
  </section>

  <?php if (!$ready): ?>
    <div class="alert warning od-alert">وحدة بيان السائق غير مفعّلة.</div>
  <?php endif; ?>

  <?php partial('stat_grid', [
    'items' => 4,
    'class' => 'od-stats',
    'body' => function () use ($s, $spent) {
      $stats = [
        ['رصيد سابق', $s['opening'], 'clock', 'navy'],
        ['عهدة الفترة', $s['totals']['custody'] + $s['totals']['additions'], 'wallet', 'gold'],
        ['المنصرف', $spent, 'trending-down', 'red'],
        ['الرصيد الحالي', abs((float) $s['closing']), 'bar-chart', $s['closing'] < 0 ? 'red' : 'green'],
      ];
      foreach ($stats as [$label, $value, $iconName, $tone]) {
        partial('stat', [
          'label' => $label,
          'value' => money0($value, false),
          'unit' => 'ج.م',
          'icon' => $iconName,
          'tone' => $tone,
          'drill' => '#driver-ledger',
          'title' => 'حركة حساب السائق',
        ]);
      }
    },
  ]); ?>

  <?php partial('panel', [
    'title' => 'حركة الحساب',
    'icon' => 'clipboard',
    'badge' => $s['rows'] ? ['text' => intf(count($s['rows'])), 'tone' => 'navy'] : null,
    'scroll' => count($s['rows']) > 7,
    'class' => 'mb',
    'id' => 'driver-ledger',
    'body' => function () use ($s, $cid, $periodQuery) {
      if (!$s['rows']) {
        partial('empty', ['icon' => 'clipboard', 'title' => 'لا توجد حركة في الفترة']);
        return;
      }

      foreach ($s['rows'] as $row) {
        if ($row['kind'] === 'bill') {
          $b = $row['bill'];
          $billNo = $b['bill_number'] ?: ('#' . (int) $b['id']);
          $meta = [
            ['icon' => 'calendar', 'text' => dfmt($row['date'])],
            ['icon' => 'map-pin', 'text' => $b['destination'] ?: 'بدون جهة'],
            ['icon' => 'wallet', 'text' => 'عهدة ' . money0($row['custody'] + $row['additions'], false)],
            ['icon' => 'trending-down', 'text' => 'منصرف ' . money0($row['expenses'] + $row['receipts'] + $row['returns'], false)],
          ];
          if ($b['permit_no']) {
            $meta[] = ['icon' => 'folder', 'text' => 'إذن ' . $b['permit_no']];
          }
          partial('row_item', [
            'title' => 'بوليصة ' . $billNo,
            'icon' => 'truck',
            'ref' => $b['destination'] ?: null,
            'meta' => $meta,
            'amount' => money0(abs((float) $row['running']), false),
            'amount_tone' => $row['running'] < 0 ? 'neg' : 'pos',
            'sub' => 'صافي ' . (($row['net'] ?? 0) >= 0 ? '+' : '−') . money0(abs((float) $row['net']), false),
            'action' => [
              'label' => 'البيان',
              'url' => url('cars/' . $cid . '/driver/bill/' . (int) $b['id']),
              'class' => 'd-act-btn green',
            ],
          ]);
          continue;
        }

        $m = $row['movement'];
        $delete = function () use ($cid, $m, $periodQuery) {
          ?><form method="post"
                  action="<?= e(url('cars/' . $cid . '/driver/movement/' . (int) $m['id'] . '/delete')) ?>"
                  data-confirm="حذف الحركة؟">
            <?= csrfField() . formStateFields(FormState::token(), 'cars/' . $cid . '/driver?' . $periodQuery) ?>
            <button class="btn ghost sm" type="submit" title="حذف"><?= icon('trash', 14) ?></button>
          </form><?php
        };
        partial('row_item', [
          'title' => OfficeDriverStatement::movementLabel($m['kind']),
          'icon' => OfficeDriverStatement::movementSigned($m) >= 0 ? 'plus' : 'trending-down',
          'meta' => [
            ['icon' => 'calendar', 'text' => dfmt($row['date'])],
            ['icon' => 'file-text', 'text' => $m['note'] ?: 'حركة عامة'],
          ],
          'amount' => money0(abs((float) $row['running']), false),
          'amount_tone' => $row['running'] < 0 ? 'neg' : 'pos',
          'sub' => 'الحركة ' . (OfficeDriverStatement::movementSigned($m) >= 0 ? '+' : '−')
            . money0(abs(OfficeDriverStatement::movementSigned($m)), false),
          'extra' => $delete,
        ]);
      }
    },
  ]); ?>

  <div class="od-bottom-grid">
    <?php if ($ready && $issued): ?>
      <?php partial('panel', [
        'title' => 'البيانات الصادرة',
        'icon' => 'file-text',
        'badge' => ['text' => intf(count($issued)), 'tone' => 'navy'],
        'scroll' => count($issued) > 5,
        'body' => function () use ($issued, $cid) {
          foreach ($issued as $doc) {
            partial('row_item', [
              'title' => 'بيان #' . (int) $doc['id'],
              'icon' => 'file-text',
              'url' => url('cars/' . $cid . '/driver/print/' . (int) $doc['id']),
              'meta' => [
                ['icon' => 'calendar', 'text' => dfmt($doc['from_date']) . ' — ' . dfmt($doc['to_date'])],
                ['icon' => 'user', 'text' => $doc['created_by'] ?: '—'],
              ],
              'amount' => money0(abs((float) $doc['closing']), false),
              'amount_tone' => $doc['closing'] < 0 ? 'neg' : 'pos',
              'sub' => $doc['closing'] < 0 ? 'مستحق للسائق' : 'مع السائق',
            ]);
          }
        },
      ]); ?>
    <?php endif; ?>

    <?php if ($ready): ?>
      <details class="d-card od-fold" id="driver-movements">
        <summary class="d-card-head">
          <h3><?= icon('plus', 18) ?><span>حركة عامة</span></h3>
          <span class="od-fold-arrow"><?= icon('chevron', 15) ?></span>
        </summary>
        <form method="post" action="<?= e(url('cars/' . $cid . '/driver/movement')) ?>"
              class="js-dirty-form od-form">
          <?= csrfField() . formStateFields($moveToken, 'cars/' . $cid . '/driver?' . $periodQuery) ?>
          <div class="form-grid two">
            <div class="field"><label>التاريخ</label><input type="date" name="move_date" value="<?= e(work_date()) ?>" required></div>
            <div class="field"><label>نوع الحركة</label><select name="kind" required>
              <?php foreach (OfficeDriverStatement::MOVEMENT_KINDS as $k => $def): ?>
                <option value="<?= e($k) ?>"><?= e($def['label']) ?></option>
              <?php endforeach; ?>
            </select></div>
            <div class="field"><label>المبلغ</label><input data-money type="number" step="0.01" min="0.01" name="amount" required></div>
            <div class="field"><label>البيان</label><input name="note"></div>
          </div>
          <div class="od-form-actions">
            <button class="btn primary" type="submit"><?= icon('check', 15) ?> تسجيل</button>
          </div>
        </form>
      </details>
    <?php endif; ?>
  </div>
</div>
