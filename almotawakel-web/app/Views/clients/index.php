<?php
/**
 * حسابات العملاء (v93) — القائمة.
 *
 * ⚠️ **صفر `<style>` محلي وصفر `style=`** — كلاسات `cl-*` في `app.css`.
 * ⚠️ **كل رقم بيُضغط** (قاعدة «ممنوع رقم أصمّ»): الخمسة فوق بيفتحوا توزيعهم
 *    على العملاء، والصف نفسه رابط لحساب العميل.
 * ⚠️ الفرز والبحث **روابط/GET حقيقية** فالرجوع بالمتصفح والمشاركة شغّالين.
 *
 * @var string $q @var string $sort @var array $rows @var array $totals
 */
use App\Models\ClientAccount;

$money = static fn ($v) => money0((float) $v, false);
$qs    = static fn (array $over = []) => url('clients') . '?' . http_build_query(
    array_filter(['q' => $q, 'sort' => $sort] + $over, static fn ($v) => $v !== '' && $v !== null));

/** أعلى العملاء في مقياس معيّن — بنود النافذة (نفس الصفوف المعروضة). */
$top = static function (string $key, int $limit = 12) use ($rows): array {
    $out = array_values(array_filter($rows, static fn ($r) => abs((float) $r[$key]) > 0.005));
    usort($out, static fn ($a, $b) => (float) $b[$key] <=> (float) $a[$key]);
    return array_slice($out, 0, $limit);
};
?>

<?php partial('page_head', [
  'title'   => 'حسابات العملاء',
  'primary' => ['label' => 'تحصيلات كل العملاء', 'url' => url('payments'), 'class' => 'btn ghost'],
]); ?>

<?php partial('stat_grid', ['items' => 5, 'body' => function () use ($totals, $money) { ?>
  <?php partial('stat', [
    'label' => 'المطلوب من العملاء', 'value' => $money($totals['charges']),
    'icon' => 'receipt', 'tone' => 'navy', 'unit' => 'ج',
    'drill' => '#clDrillCharges', 'title' => 'المطلوب موزّعًا على العملاء',
  ]); ?>
  <?php partial('stat', [
    'label' => 'المحصّل منهم', 'value' => $money($totals['paid']),
    'icon' => 'banknote', 'tone' => 'green', 'unit' => 'ج',
    'drill' => '#clDrillPaid', 'title' => 'المحصّل موزّعًا على العملاء',
  ]); ?>
  <?php partial('stat', [
    'label' => 'المتبقّي علينا تحصيله', 'value' => $money($totals['remaining']),
    'icon' => 'handshake', 'tone' => 'gold', 'unit' => 'ج',
    'drill' => '#clDrillRemaining', 'title' => 'الرصيد لكل عميل',
  ]); ?>
  <?php partial('stat', [
    'label' => 'متأخر عن ميعاده', 'value' => $money($totals['overdue']),
    'icon' => 'alert', 'tone' => 'red', 'unit' => 'ج',
    'drill' => '#clDrillOverdue', 'title' => 'العملاء المتأخرون',
  ]); ?>
  <?php partial('stat', [
    'label' => 'فواتير مفتوحة', 'value' => intf($totals['open_cnt']),
    'icon' => 'layers', 'tone' => 'teal',
    'drill' => '#clDrillOpen', 'title' => 'الفواتير المفتوحة لكل عميل',
  ]); ?>
<?php }]); ?>

<?php
/* أجسام النوافذ — جاهزة في الصفحة ومخفية؛ النافذة العامة بتنسخ محتواها.
   ⚠️ كل بند هنا **من نفس صفوف القائمة** فمستحيل الرقم يخالف التوزيع. */
$drillRows = static function (array $list, string $key, string $tone) use ($money) {
    foreach ($list as $r) {
        partial('row_item', [
            'title'  => (string) $r['name'],
            'url'    => url('clients/' . (int) $r['id']),
            'icon'   => 'user',
            'meta'   => array_values(array_filter([
                (int) $r['open_cnt'] > 0 ? ['icon' => 'layers', 'text' => intf($r['open_cnt']) . ' فاتورة مفتوحة'] : null,
                (int) $r['late_days'] > 0 ? ['icon' => 'clock', 'text' => 'متأخر ' . intf($r['late_days']) . ' يوم'] : null,
            ])),
            'amount' => $money($r[$key]),
            'amount_tone' => $tone,
        ]);
    }
};
?>
<div id="clDrillCharges" hidden>
  <?php partial('drill', [
    'how' => 'إجمالي فواتير كل عميل من يوم ما اتفتح حسابه.',
    'count' => count($top('charges')),
    'total' => ['label' => 'إجمالي المطلوب', 'value' => $money($totals['charges'])],
    'body' => fn () => $drillRows($top('charges'), 'charges', 'rc'),
  ]); ?>
</div>
<div id="clDrillPaid" hidden>
  <?php partial('drill', [
    'how' => 'مجموع تحصيلات كل عميل (المعكوس مش محسوب).',
    'count' => count($top('paid')),
    'total' => ['label' => 'إجمالي المحصّل', 'value' => $money($totals['paid'])],
    'more' => ['label' => 'سجل التحصيلات', 'url' => url('payments')],
    'body' => fn () => $drillRows($top('paid'), 'paid', 'pos'),
  ]); ?>
</div>
<div id="clDrillRemaining" hidden>
  <?php partial('drill', [
    'how' => 'المطلوب ناقص المحصّل — الموجب مطلوب من العميل.',
    'count' => count($top('remaining')),
    'total' => ['label' => 'إجمالي المتبقّي', 'value' => $money($totals['remaining'])],
    'body' => fn () => $drillRows($top('remaining'), 'remaining', 'ex'),
  ]); ?>
</div>
<div id="clDrillOverdue" hidden>
  <?php partial('drill', [
    'how' => 'الفواتير اللي عدّى ميعاد سدادها ولسه ليها باقي.',
    'count' => count($top('overdue')),
    'total' => ['label' => 'إجمالي المتأخر', 'value' => $money($totals['overdue'])],
    'empty' => ['title' => 'مفيش عميل متأخر — كل الفواتير في ميعادها'],
    'body' => fn () => $drillRows($top('overdue'), 'overdue', 'ex'),
  ]); ?>
</div>
<div id="clDrillOpen" hidden>
  <?php partial('drill', [
    'how' => 'الفواتير الصادرة اللي لسه ليها باقي.',
    'count' => count($top('open_balance')),
    'total' => ['label' => 'باقي الفواتير المفتوحة', 'value' => $money(array_sum(array_column($rows, 'open_balance')))],
    'more' => ['label' => 'سجل الفواتير', 'url' => url('invoices')],
    'body' => fn () => $drillRows($top('open_balance'), 'open_balance', 'rc'),
  ]); ?>
</div>

<div class="cl-bar">
  <form method="get" action="<?= url('clients') ?>" class="cl-search">
    <input type="hidden" name="sort" value="<?= e($sort) ?>">
    <span class="cl-search-ic"><?= icon('search', 15) ?></span>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="اسم العميل أو اسم بديل">
  </form>
  <div class="cl-sorts">
    <?php foreach (ClientAccount::SORTS as $k => $lbl): ?>
      <a class="cl-sort<?= $sort === $k ? ' active' : '' ?>" href="<?= e($qs(['sort' => $k])) ?>"><?= e($lbl) ?></a>
    <?php endforeach; ?>
  </div>
  <span class="count-badge"><?= intf($totals['clients']) ?> عميل<?= $totals['late_clients'] > 0 ? ' · ' . intf($totals['late_clients']) . ' متأخر' : '' ?></span>
</div>

<?php if (!$rows): ?>
  <?php partial('empty', ['emoji' => '👤', 'title' => $q !== '' ? 'مفيش عميل بالاسم ده' : 'مفيش عملاء مسجّلين']); ?>
<?php else: ?>
  <div class="cl-grid">
    <?php foreach ($rows as $r):
      $id   = (int) $r['id'];
      $rem  = (float) $r['remaining'];
      $late = (int) $r['late_days'];
      $ch   = (float) $r['charges'];
      $pct  = $ch > 0.005 ? max(0, min(100, (int) round(((float) $r['paid'] / $ch) * 100))) : 0;
      $cos  = !empty($r['preview']) ? explode('|', (string) $r['preview']) : [];
    ?>
      <article class="cl-card<?= $late > 0 ? ' is-late' : '' ?>">
        <a class="cl-card-main" href="<?= url('clients/' . $id) ?>">
          <span class="cl-ava"><?= e(initials((string) $r['name'])) ?></span>
          <span class="cl-id">
            <b><?= e($r['name']) ?></b>
            <span class="cl-sub">
              <?php if ($cos): ?><?= icon('building', 12) ?> <?= e(implode(' · ', array_slice($cos, 0, 2))) ?><?php if (count($cos) > 2): ?> +<?= intf(count($cos) - 2) ?><?php endif; ?>
              <?php elseif (!empty($r['phone'])): ?><?= icon('smartphone', 12) ?> <bdi><?= e($r['phone']) ?></bdi>
              <?php else: ?><?= icon('user', 12) ?> عميل مباشر<?php endif; ?>
            </span>
          </span>
          <span class="cl-nums">
            <span class="cl-num"><i>المطلوب</i><b><?= $money($r['charges']) ?></b></span>
            <span class="cl-num"><i>المحصّل</i><b class="pos"><?= $money($r['paid']) ?></b></span>
            <span class="cl-num big"><i>المتبقّي</i><b class="<?= $rem > 0.005 ? 'ex' : ($rem < -0.005 ? 'pos' : '') ?>"><?= $money($rem) ?></b></span>
          </span>
        </a>
        <div class="cl-card-foot">
          <?php /* ⚠️ الشريط بيظهر **بس لو فيه حساب فعلاً** — عميل بلا فواتير
                   كان بياخد شريط فاضي و«٠٪» يقروا كأنهم تقصير في التحصيل. */ ?>
          <?php if ($ch > 0.005): ?>
            <span class="cl-prog" role="img" aria-label="نسبة السداد <?= intf($pct) ?>٪">
              <span class="cl-prog-fill cl-w-<?= (int) (round($pct / 5) * 5) ?>"></span>
            </span>
            <span class="cl-prog-n"><?= intf($pct) ?>٪</span>
          <?php else: ?>
            <span class="cl-prog-n cl-mut">بلا حركة</span>
          <?php endif; ?>
          <?php if ($late > 0): ?>
            <span class="pill st-unpaid"><?= icon('clock', 12) ?> متأخر <?= intf($late) ?> يوم · <?= $money($r['overdue']) ?></span>
          <?php elseif ((int) $r['open_cnt'] > 0): ?>
            <span class="pill st-partial"><?= icon('layers', 12) ?> <?= intf($r['open_cnt']) ?> فاتورة مفتوحة</span>
          <?php endif; ?>
          <span class="cl-acts">
            <a class="cl-act" href="<?= url('clients/' . $id) ?>?tab=payments" title="تسجيل تحصيل"><?= icon('banknote', 15) ?></a>
            <a class="cl-act" href="<?= url('clients/' . $id . '/statement') ?>" target="_blank" rel="noopener" title="كشف حساب"><?= icon('file-text', 15) ?></a>
          </span>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
