<?php
/**
 * رواتب المناديب (v87) — كشف فترة واحدة.
 *
 * ⚠️ **صفر `<style>` محلي وصفر `style=`** (قانون التصميم بند ٧) — كل شكل هنا
 *    بيتعمل بمكوّنات النظام (`page_head` · `stat_grid` · `table` · `row_item`).
 * ⚠️ **ممنوع رقم أصمّ**: كل رقم في الشبكة بيفتح بنوده، وسلف كل مندوب في
 *    الجدول بتفتح صفوفها.
 * ⚠️ **صفر جملة تعليمية**: اللي فاضل تحذيرات وظيفية في ≤٦ كلمات بس.
 *
 * @var array      $period @var array $prev @var array $next @var bool $isFuture
 * @var array      $calc   حساب الفترة (مسوّدة) أو اللقطة (معتمدة)
 * @var array|null $saved  صف `payroll_periods` لو الفترة اتعتمدت
 * @var bool       $ready  الجداول موجودة؟
 */
use App\Models\Payroll;

$at      = $period['from'];
$exclude = array_map('intval', (array) ($_GET['ex'] ?? []));
$dec     = (string) $calc['deficit_decision'];
$approved = $saved !== null;
$dr      = static fn (string $what): string => url('payroll/drill/' . $what) . '?at=' . $at;
?>
<?php if (!$ready): ?>
  <div class="card mb p5-pad-sm p5-edge-gold-r">
    <?= icon('info', 15) ?> وحدة الرواتب مش مفعّلة — شغّل <code>php bin/migrate_v87.php</code>.
  </div>
<?php else: ?>

<?php partial('page_head', [
  'title'   => 'رواتب المناديب',
  'actions' => function () use ($approved, $saved, $at) { ?>
    <?php if ($approved): ?>
      <form method="post" action="<?= url('payroll/' . (int) $saved['id'] . '/unapprove') ?>" class="inline-form">
        <?= csrfField() ?>
        <input type="hidden" name="_back" value="payroll?at=<?= e($at) ?>">
        <button class="btn ghost" type="submit" data-confirm="هتلغي اعتماد الفترة وبنودها؟">
          <?= icon('trash', 15) ?> إلغاء الاعتماد
        </button>
      </form>
    <?php endif; ?>
  <?php },
]); ?>

<?php /* شريط الفترة — فترة رواتب لا شهر تقويمي (يوم البداية من الإعدادات) */ ?>
<div class="toolbar">
  <a class="btn ghost" href="<?= url('payroll') ?>?at=<?= e($prev['from']) ?>"><span aria-hidden="true">‹</span> السابقة</a>
  <div class="section-head"><?= icon('calendar', 18) ?> <?= e($period['label']) ?></div>
  <?php /* سهم «التالية» بيختفي خالص في آخر فترة — زرار مطفي بيتضغط بالغلط. */ ?>
  <?php if (!$isFuture): ?>
    <a class="btn ghost" href="<?= url('payroll') ?>?at=<?= e($next['from']) ?>">التالية <span aria-hidden="true">›</span></a>
  <?php endif; ?>
  <div class="spacer"></div>
  <?php if ($approved): ?>
    <span class="chip"><?= icon('check', 14) ?> متعمدة</span>
  <?php else: ?>
    <span class="chip"><?= icon('edit', 14) ?> مسوّدة</span>
  <?php endif; ?>
</div>

<?php partial('stat_grid', ['items' => 5, 'body' => function () use ($calc, $dr) { ?>
  <?php partial('stat', [
    'label' => 'بوالص الفترة', 'value' => intf((int) $calc['bills_counted']),
    'icon' => 'file-text', 'tone' => 'navy', 'drill' => $dr('bills'),
    'title' => 'البوالص اللي دخلت الحساب',
  ]); ?>
  <?php partial('stat', [
    'label' => 'حوض الكومسيون', 'value' => money0($calc['commission_pool']),
    'icon' => 'coins', 'tone' => 'gold', 'unit' => 'ج', 'drill' => $dr('commission'),
    'title' => 'المحتجز من عهدة البوالص',
  ]); ?>
  <?php partial('stat', [
    'label' => 'حوض المصروف', 'value' => money0($calc['expense_pool']),
    'icon' => 'crane', 'tone' => 'teal', 'unit' => 'ج', 'drill' => $dr('expense'),
    'title' => 'مصروف البوالص قبل الخصم',
  ]); ?>
  <?php partial('stat', [
    'label' => 'المصروف المسموح', 'value' => money0($calc['allowed_expenses']),
    'icon' => 'wallet', 'tone' => 'red', 'unit' => 'ج', 'drill' => $dr('allowed'),
    'title' => 'اللي اتصرف من حوض المصروف',
  ]); ?>
  <?php partial('stat', [
    'label' => 'صافي المستحق', 'value' => money0($calc['net_total']),
    'icon' => 'banknote', 'tone' => 'green', 'unit' => 'ج', 'drill' => $dr('nets'),
    'title' => 'المستحق لكل المناديب',
  ]); ?>
<?php }]); ?>

<?php /* تحذيرات وظيفية — كل واحد ≤٦ كلمات، وبيظهر لما يبقى له لازمة بس */ ?>
<?php if ($calc['bills_excluded'] > 0): ?>
  <div class="card mb p5-pad-sm">
    <?= icon('info', 15) ?> <?= intf((int) $calc['bills_excluded']) ?> بوليصة برّه الحساب (نوع الشحنة).
  </div>
<?php endif; ?>
<?php if ($calc['deficit'] > 0): ?>
  <div class="card mb p5-pad-sm p5-edge-gold-r">
    <?= icon('alert', 15) ?> المصروف عدّى الحوض بـ<?= money($calc['deficit']) ?>.
  </div>
<?php endif; ?>
<?php if ($calc['reps_count'] < 1): ?>
  <div class="card mb p5-pad-sm p5-edge-gold-r">
    <?= icon('alert', 15) ?> مفيش مندوب داخل القسمة.
  </div>
<?php endif; ?>

<form method="get" action="<?= url('payroll') ?>">
  <input type="hidden" name="at" value="<?= e($at) ?>">

  <?php if (!$approved): ?>
    <div class="toolbar">
      <div class="field flush w160">
        <label>لو المصروف عدّى الحوض</label>
        <select name="deficit">
          <?php foreach (Payroll::DEFICIT_DECISIONS as $k => $lbl): ?>
            <option value="<?= e($k) ?>" <?= $dec === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn" type="submit"><?= icon('calculator', 16) ?> حدّث الحساب</button>
    </div>
  <?php endif; ?>

  <?php partial('table', [
    'cols'  => [
      ['label' => 'المندوب'],
      ['label' => 'راتب ثابت'],
      ['label' => 'سلف'],
      ['label' => 'كومسيون'],
      ['label' => 'مصروف'],
      ['label' => 'الصافي'],
      ['label' => 'في القسمة'],
    ],
    'count' => count($calc['lines']),
    'empty' => ['title' => 'مفيش مناديب في الفترة دي'],
    'rows'  => function () use ($calc, $exclude, $approved, $at) {
      foreach ($calc['lines'] as $l):
        $rid = (int) $l['rep_id'];
        $off = $approved ? empty($l['included']) : in_array($rid, $exclude, true) || empty($l['active']);
      ?>
        <tr>
          <td><a href="<?= url('reps/' . $rid) ?>"><?= e((string) $l['rep_name']) ?></a></td>
          <td><?= money($l['base_salary']) ?></td>
          <td>
            <?php if ((float) $l['advances'] > 0): ?>
              <button type="button" class="num-btn" data-drill="<?= e(url('payroll/drill/advances') . '?at=' . $at . '&rep=' . $rid) ?>"
                      data-drill-title="سلف <?= e((string) $l['rep_name']) ?>"><?= money($l['advances']) ?></button>
            <?php else: ?><?= money(0) ?><?php endif; ?>
          </td>
          <td><?= money($l['commission_share']) ?></td>
          <td><?= money($l['expense_share']) ?></td>
          <td><b><?= money($l['net']) ?></b></td>
          <td>
            <?php if ($approved): ?>
              <?= $off ? '—' : icon('check', 15) ?>
            <?php elseif (empty($l['active'])): ?>
              <span class="chip">موقوف</span>
            <?php else: ?>
              <input type="checkbox" name="ex[]" value="<?= $rid ?>" <?= $off ? 'checked' : '' ?>
                     aria-label="اشيله من القسمة">
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach;
    },
  ]); ?>
</form>

<?php if (!$approved): ?>
  <form method="post" action="<?= url('payroll/approve') ?>" class="toolbar">
    <?= csrfField() ?>
    <input type="hidden" name="at" value="<?= e($at) ?>">
    <input type="hidden" name="deficit" value="<?= e($dec) ?>">
    <?php foreach ($exclude as $rid): ?>
      <input type="hidden" name="ex[]" value="<?= (int) $rid ?>">
    <?php endforeach; ?>
    <div class="field flush grow200">
      <label>ملاحظة</label>
      <input type="text" name="note" maxlength="255" placeholder="اختياري">
    </div>
    <button class="btn gold" type="submit" data-confirm="هتعتمد الفترة وتسجّل استحقاقات المناديب؟">
      <?= icon('check', 16) ?> اعتماد الفترة
    </button>
  </form>
<?php endif; ?>

<?php endif; ?>
