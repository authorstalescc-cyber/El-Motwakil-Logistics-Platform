<?php
/**
 * التقارير المالية والإدارية.
 *
 * ⚠️ **إيه اللي اتغيّر وليه — لا ترجّع اللي اتشال (2026-07-27):**
 *   ① **٥ بطاقات `.kpi` كانت أرقامًا صمّاء** بلا أي طريق للتفاصيل — دي كانت
 *      أوضح مخالفة لقاعدة المالك «ممنوع رقم أصمّ» في النظام كله. بقت `stat`
 *      وكل واحدة بتفتح **المصدر اللي اتحسبت منه فعلاً**.
 *   ② **التبويبات كانت بتتبدّل بالجافاسكريبت** والعنوان ما بيتغيّرش — فالرجوع
 *      بالمتصفح كان بيطلّعك من الصفحة كلها، والرابط مش قابل للمشاركة. بقت
 *      **روابط `?tab=`**، والكنترولر بقى **يحمّل بيانات تبويب واحد بدل الستة**.
 *   ③ **Chart.js (٢٠٠KB) اتشالت** — اترفعت من الداشبورد في المرحلة ٤ وفضلت
 *      هنا. الرسوم بقت `mini_bars` (SVG/CSS على الخادم، وكل عمود رابط).
 *      والدوناتس اتشالت خالص: ١٢ بند في دونات = غير مقروء، والجدول تحتها
 *      بيقول نفس الحاجة بدقّة.
 *   ④ **صفر `style=` inline** (كانوا ٣١).
 *
 * متغيّرات دايمًا: $totals $tab $tabs $from $to $year $monthNames
 * ومتغيّرات التبويب النشط بس — فكل قراءة هنا محروسة بـ`??`.
 */
use App\Core\App;
$cur = App::setting('currency', 'ج.م');

$qs = static function (array $over = []) use ($from, $to, $tab): string {
    $q = array_filter(array_merge(['tab' => $tab, 'from' => $from, 'to' => $to], $over),
        static fn ($v) => $v !== '' && $v !== null);
    return $q ? '?' . http_build_query($q) : '';
};
/** رابط نافذة التفاصيل — النطاق بيتمرّر وإلا النافذة تقول رقمًا تاني. */
$drill = static function (string $what) use ($from, $to): string {
    $q = array_filter(['from' => $from, 'to' => $to], static fn ($v) => $v !== '');
    return url('reports/drill/' . $what . ($q ? '?' . http_build_query($q) : ''));
};
$hasFilter = ($from !== '' || $to !== '');
$printQs   = http_build_query(array_filter(['from' => $from, 'to' => $to]));
?>
<div class="reports-page">

<div class="toolbar">
  <div class="section-head toolbar-title"><?= icon('bar-chart', 18) ?> التقارير</div>
  <div class="spacer"></div>
  <a class="btn gold sm" target="_blank" rel="noopener"
     href="<?= url('reports/print' . ($printQs ? '?' . $printQs : '')) ?>"><?= icon('file-text', 15) ?> طباعة / PDF</a>
</div>

<form method="get" id="filterForm" class="filter-bar" aria-label="فلترة التقارير">
  <input type="hidden" name="tab" value="<?= e($tab) ?>">
  <div class="fld"><label for="report-from">من تاريخ</label><input id="report-from" type="date" name="from" value="<?= e($from) ?>"></div>
  <div class="fld"><label for="report-to">إلى تاريخ</label><input id="report-to" type="date" name="to" value="<?= e($to) ?>"></div>
  <button class="btn navy sm" type="submit"><?= icon('search', 14) ?> تطبيق</button>
  <?php if ($hasFilter): ?>
    <a class="btn ghost sm" href="<?= url('reports' . $qs(['from' => null, 'to' => null])) ?>"><?= icon('x', 14) ?> تفريغ</a>
  <?php endif; ?>
  <span class="presets" id="presets">
    <button type="button" data-preset="today">النهاردة</button>
    <button type="button" data-preset="week">الأسبوع</button>
    <button type="button" data-preset="month">الشهر</button>
    <button type="button" data-preset="quarter">الربع</button>
    <button type="button" data-preset="year">السنة</button>
    <button type="button" data-preset="all">الكل</button>
  </span>
</form>

<?php if ($hasFilter): ?>
  <div class="filter-tag">
    <?= icon('calendar', 14) ?>
    <strong><?= e($from !== '' ? dfmt($from) : 'البداية') ?></strong> ← <strong><?= e($to !== '' ? dfmt($to) : 'النهاية') ?></strong>
    <a href="<?= url('reports' . $qs(['from' => null, 'to' => null])) ?>"><?= icon('x', 14) ?> إلغاء</a>
  </div>
<?php endif; ?>

<?php /* ═══ الأرقام — كل واحد بيفتح المصدر اللي اتحسب منه ═══ */ ?>
<?php
/* ⚠️ `stat_grid` بياخد **عدد الأعمدة** رقمًا و`body` مُصيِّرًا — مش مصفوفة بطاقات.
   لو بعتّ مصفوفة، `(int)` بتاعها = 1 والشبكة بترندر **فاضية بالسكوت**. */
$rpStats = [
  ['label' => 'إجمالي النولون',   'value' => money0($totals['freight'], false),     'unit' => $cur,
   'icon' => 'banknote',      'tone' => 'navy',   'drill' => $drill('freight'),     'title' => 'النولون حسب الشركة'],
  ['label' => 'إجمالي العهد',     'value' => money0($totals['custody'], false),     'unit' => $cur,
   'icon' => 'wallet',        'tone' => 'gold',   'drill' => $drill('custody'),     'title' => 'العهد حسب العميل'],
  ['label' => 'إجمالي المصاريف',  'value' => money0($totals['expenses'], false),    'unit' => $cur,
   'icon' => 'trending-down', 'tone' => 'red',    'drill' => $drill('expenses'),    'title' => 'المصروفات حسب البند'],
  ['label' => 'إجمالي المدفوعات', 'value' => money0($totals['collections'], false), 'unit' => $cur,
   'icon' => 'coins',         'tone' => 'green',  'drill' => $drill('collections'), 'title' => 'الدفعات الصادرة'],
  ['label' => 'المتبقي للعملاء',  'value' => money0($totals['outstanding'], false), 'unit' => $cur,
   'icon' => 'credit-card',   'tone' => 'purple', 'drill' => $drill('outstanding'), 'title' => 'المتبقّي لكل عميل'],
];
partial('stat_grid', ['items' => count($rpStats), 'class' => 'mb', 'body' => function () use ($rpStats) {
    foreach ($rpStats as $st) { partial('stat', $st); }
}]);
?>

<?php
/* ═══ التبويبات — روابط حقيقية، والكنترولر بيحمّل المعروض بس ═══ */
$tabMeta = [
  'overview'  => ['نظرة عامة', 'bar-chart'],
  'customers' => ['العملاء',   'users'],
  'payments'  => ['المدفوعات', 'banknote'],
  'expenses'  => ['المصاريف',  'trending-down'],
  'movements' => ['الحركات',   'shuffle'],
  'permits'   => ['الأذون',    'folder'],
];
$tabUrl = static function (string $k) use ($from, $to): string {
    $q = array_filter(['tab' => $k, 'from' => $from, 'to' => $to], static fn ($v) => $v !== '' && $v !== null);
    return url('reports' . ($q ? '?' . http_build_query($q) : ''));
};
partial('tabs', ['label' => 'أقسام التقرير', 'items' => array_map(
  static fn (string $k): array => [
    'label'  => $tabMeta[$k][0],
    'icon'   => $tabMeta[$k][1],
    'url'    => $tabUrl($k),
    'active' => $tab === $k,
  ], $tabs)]);
?>

<?php if ($tab === 'overview'): ?>
  <?php
  $sumRow = ['freight' => 0, 'custody' => 0, 'expenses' => 0, 'collections' => 0];
  foreach ($monthly as $m) { foreach ($sumRow as $k => $_) { $sumRow[$k] += $m[$k]; } }
  /* الرسم بيدي **شكل** الاتجاه، والجدول تحته بيدي **الأربع سلاسل بالأرقام** —
     كل واحد بيعمل حاجة التاني مش كويس فيها. */
  $mBars = [];
  foreach ($monthNames as $i => $mn) {
      $mBars[] = ['label' => mb_substr($mn, 0, 3), 'value' => (float) $monthly[$i + 1]['freight'],
                  'display' => '', 'title' => $mn . ' — نولون ' . money0($monthly[$i + 1]['freight'])];
  }
  ?>
  <?php partial('panel', [
    'title' => 'النولون شهريًا — ' . $year, 'icon' => 'trending-up',
    'body'  => function () use ($mBars) { partial('mini_bars', ['bars' => $mBars, 'tone' => 'navy', 'height' => 110]); },
  ]); ?>

  <?php if (!empty($trend['labels'])): ?>
    <?php $tBars = [];
      foreach ($trend['labels'] as $i => $lb) {
          $tBars[] = ['label' => $lb, 'value' => (float) ($trend['data'][$i] ?? 0),
                      'display' => (string) ($trend['data'][$i] ?? 0), 'title' => $lb . ' — ' . ($trend['data'][$i] ?? 0) . ' عملية'];
      } ?>
    <?php partial('panel', [
      'title' => 'حجم العمليات — آخر ١٤ يوم', 'icon' => 'shuffle', 'class' => 'mt',
      'body'  => function () use ($tBars) { partial('mini_bars', ['bars' => $tBars, 'tone' => 'gold', 'height' => 96]); },
    ]); ?>
  <?php endif; ?>

  <div class="card mt pad">
    <div class="section-head"><?= icon('calendar', 16) ?> الأداء الشهري — <?= e((string) $year) ?></div>
    <div class="table-wrap"><table class="data">
      <thead><tr><th class="g-identity">الشهر</th><th class="g-revenue">النولون</th><th class="g-revenue">العهد</th>
        <th class="g-expense">مصروف تشغيلي</th><th class="g-revenue">المدفوعات</th></tr></thead>
      <tbody>
        <?php foreach ($monthNames as $idx => $mn): $m = $monthly[$idx + 1]; ?>
          <tr><td class="strong"><?= e($mn) ?></td>
            <td><?= money($m['freight'], false) ?></td>
            <td><?= money($m['custody'], false) ?></td>
            <td class="neg"><?= money($m['expenses'], false) ?></td>
            <td class="pos"><?= money($m['collections'], false) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td>الإجمالي</td><td><?= money($sumRow['freight'], false) ?></td><td><?= money($sumRow['custody'], false) ?></td>
        <td><?= money($sumRow['expenses'], false) ?></td><td><?= money($sumRow['collections'], false) ?></td></tr></tfoot>
    </table></div>
  </div>

  <div class="two-col mt">
    <div class="card pad">
      <div class="tbl-head"><div class="section-head"><?= icon('building', 16) ?> النولون حسب الشركة</div>
        <input type="search" class="table-search" data-target="tblCompany" aria-label="بحث في جدول الشركات" placeholder="بحث"></div>
      <div class="table-wrap"><table class="data" id="tblCompany">
        <thead><tr><th class="g-identity">الشركة</th><th class="g-revenue">النولون</th></tr></thead>
        <tbody>
          <?php foreach ($byCompany as $r): ?>
            <tr><td class="strong"><a class="lnk" href="<?= url('reports/client?name=' . urlencode($r['name'])) ?>"><?= e($r['name']) ?></a></td>
              <td><?= money($r['freight'], false) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$byCompany): ?><tr><td colspan="2"><?php partial('empty', ['icon' => 'inbox', 'title' => 'مفيش بيانات']); ?></td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>

    <div class="card pad">
      <div class="tbl-head"><div class="section-head"><?= icon('credit-card', 16) ?> مستحقات العملاء</div>
        <input type="search" class="table-search" data-target="tblDebts" aria-label="بحث في جدول المستحقات" placeholder="بحث"></div>
      <div class="table-wrap"><table class="data" id="tblDebts">
        <thead><tr><th class="g-identity">العميل</th><th class="g-revenue">إجمالي التعامل</th><th class="g-expense">المتبقي له</th><th class="g-identity" data-no-sort>‏</th></tr></thead>
        <tbody>
          <?php foreach ($debts as $r): ?>
            <tr><td class="strong"><?= e($r['name']) ?></td><td><?= money($r['invoices'], false) ?></td>
              <td class="<?= $r['remaining'] > 0 ? 'neg' : '' ?>"><?= money($r['remaining'], false) ?></td>
              <td><a class="btn ghost sm" href="<?= url('reports/client?name=' . urlencode($r['name'])) ?>"><?= icon('chevron', 14) ?></a></td></tr>
          <?php endforeach; ?>
          <?php if (!$debts): ?><tr><td colspan="4"><?php partial('empty', ['icon' => 'inbox', 'title' => 'مفيش بيانات']); ?></td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>

<?php elseif ($tab === 'customers'): ?>
  <?php $cusTot = ['freight' => 0, 'custody' => 0, 'expense' => 0, 'paid' => 0, 'remaining' => 0];
        foreach ($customers as $c) { foreach ($cusTot as $k => $_) { $cusTot[$k] += $c[$k]; } } ?>
  <div class="card pad">
    <div class="tbl-head"><div class="section-head"><?= icon('users', 16) ?> العملاء — النولون والعهد والمدفوعات</div>
      <input type="search" class="table-search" data-target="tblCustomers" aria-label="بحث في جدول العملاء" placeholder="بحث"></div>
    <div class="table-wrap"><table class="data" id="tblCustomers">
      <thead><tr>
        <th class="g-identity">العميل</th><th class="g-identity">حركات</th>
        <th class="g-revenue">النولون</th><th class="g-revenue">العهد</th><th class="g-expense">المصروف</th>
        <th class="g-revenue">المدفوع له</th><th class="g-expense">المتبقي له</th><th class="g-identity" data-no-sort>‏</th>
      </tr></thead>
      <tbody>
        <?php foreach ($customers as $c): ?>
          <tr>
            <td class="strong"><a class="lnk" href="<?= url('reports/client?name=' . urlencode($c['name'])) ?>"><?= e($c['name']) ?></a></td>
            <td><?= intf($c['trips']) ?></td>
            <td><?= money($c['freight'], false) ?></td>
            <td><?= money($c['custody'], false) ?></td>
            <td class="neg"><?= money($c['expense'], false) ?></td>
            <td class="pos"><?= money($c['paid'], false) ?></td>
            <td class="<?= $c['remaining'] > 0 ? 'neg' : '' ?>"><?= money($c['remaining'], false) ?></td>
            <td><a class="btn ghost sm" href="<?= url('reports/client?name=' . urlencode($c['name'])) ?>"><?= icon('chevron', 14) ?></a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$customers): ?><tr><td colspan="8"><?php partial('empty', ['icon' => 'users', 'title' => 'مفيش عملاء في الفترة دي']); ?></td></tr><?php endif; ?>
      </tbody>
      <?php if ($customers): ?>
        <tfoot><tr><td>الإجمالي</td><td></td>
          <td><?= money($cusTot['freight'], false) ?></td><td><?= money($cusTot['custody'], false) ?></td>
          <td><?= money($cusTot['expense'], false) ?></td><td><?= money($cusTot['paid'], false) ?></td>
          <td><?= money($cusTot['remaining'], false) ?></td><td></td></tr></tfoot>
      <?php endif; ?>
    </table></div>
  </div>

<?php elseif ($tab === 'payments'): ?>
  <div class="card pad">
    <div class="tbl-head"><div class="section-head"><?= icon('banknote', 16) ?> المدفوعات — دفعات صادرة للعملاء</div>
      <input type="search" class="table-search" data-target="tblPays" aria-label="بحث في جدول المدفوعات" placeholder="بحث"></div>
    <div class="table-wrap"><table class="data" id="tblPays">
      <thead><tr><th class="g-identity">التاريخ</th><th class="g-identity">رقم البوليصة</th><th class="g-identity">العميل / الجهة</th>
        <th class="g-revenue">المبلغ</th><th class="g-identity">ملاحظة</th></tr></thead>
      <tbody>
        <?php foreach ($payments['rows'] as $p): ?>
          <tr><td><?= dfmt($p['d']) ?></td><td class="strong"><?= e($p['bill_number']) ?></td>
            <td class="start"><?= e($p['party']) ?></td>
            <td class="pos"><?= money($p['amount'], false) ?></td><td><?= e($p['note']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$payments['rows']): ?><tr><td colspan="5"><?php partial('empty', ['icon' => 'banknote', 'title' => 'مفيش مدفوعات في الفترة دي']); ?></td></tr><?php endif; ?>
      </tbody>
      <?php if ($payments['rows']): ?>
        <tfoot><tr><td colspan="3">الإجمالي</td><td><?= money($payments['total'], false) ?></td><td></td></tr></tfoot>
      <?php endif; ?>
    </table></div>
    <?php partial('count_note', ['shown' => count($payments['rows']), 'total' => (int) $payments['count'], 'unit' => 'دفعة']); ?>
  </div>

<?php elseif ($tab === 'expenses'): ?>
  <?php
  $expCatTotal = 0; foreach ($expenses['categories'] as $r) { $expCatTotal += $r['value']; }
  /* إجمالي المصروفات = نفس رقم صفحة المصروفات (حركات + أذونات + يدوي + تخليص + فواتير).
     «سداد المديونيات» مش مصروف فما بيدخلش الإجمالي ده. */
  $expGrand = $expCatTotal + $expenses['takhlis'] + $expenses['shamil']
            + ($expenses['permits'] ?? 0) + ($expenses['manual'] ?? 0);
  $extra = array_values(array_filter([
    $expenses['takhlis']         > 0 ? ['label' => 'مصروفات التخليص',        'value' => $expenses['takhlis']]  : null,
    $expenses['shamil']          > 0 ? ['label' => 'مصروفات الفواتير (شامل)', 'value' => $expenses['shamil']]   : null,
    ($expenses['permits'] ?? 0)  > 0 ? ['label' => 'مصروفات الأذونات',        'value' => $expenses['permits']]  : null,
    ($expenses['manual'] ?? 0)   > 0 ? ['label' => 'مصاريف يدوية',            'value' => $expenses['manual']]   : null,
  ]));
  $eBars = [];
  foreach (array_slice($expenses['categories'], 0, 10) as $r) {
      $eBars[] = ['label' => mb_substr($r['label'], 0, 10), 'value' => (float) $r['value'],
                  'display' => '', 'title' => $r['label'] . ' — ' . money0($r['value'])];
  }
  ?>
  <?php if ($eBars): ?>
    <?php partial('panel', [
      'title' => 'أعلى بنود المصروفات', 'icon' => 'trending-down',
      'body'  => function () use ($eBars) { partial('mini_bars', ['bars' => $eBars, 'tone' => 'red', 'height' => 100]); },
    ]); ?>
  <?php endif; ?>
  <div class="card mt pad">
    <div class="section-head"><?= icon('trending-down', 16) ?> تفصيل المصروفات</div>
    <div class="table-wrap"><table class="data">
      <thead><tr><th class="g-identity">البند</th><th class="g-identity">عدد المرات</th><th class="g-expense">القيمة</th><th class="g-result">النسبة</th></tr></thead>
      <tbody>
        <?php foreach ($expenses['categories'] as $r): ?>
          <tr><td class="strong"><?= e($r['label']) ?></td><td><?= intf($r['cnt']) ?></td>
            <td class="neg"><?= money($r['value'], false) ?></td><td><?= pct($expGrand != 0 ? $r['value'] / $expGrand : 0) ?></td></tr>
        <?php endforeach; ?>
        <?php foreach ($extra as $x): ?>
          <tr><td class="strong"><?= e($x['label']) ?></td><td>—</td>
            <td class="neg"><?= money($x['value'], false) ?></td><td><?= pct($expGrand != 0 ? $x['value'] / $expGrand : 0) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$expenses['categories'] && $expGrand == 0): ?>
          <tr><td colspan="4"><?php partial('empty', ['icon' => 'trending-down', 'title' => 'مفيش مصروفات في الفترة دي']); ?></td></tr>
        <?php endif; ?>
      </tbody>
      <tfoot><tr><td colspan="2">الإجمالي</td><td><?= money($expGrand, false) ?></td><td>100%</td></tr></tfoot>
    </table></div>
  </div>

<?php elseif ($tab === 'movements'): ?>
  <div class="card pad">
    <div class="tbl-head"><div class="section-head"><?= icon('shuffle', 16) ?> الحركات — مصروفات وإيصالات وخصومات البوالص</div>
      <input type="search" class="table-search" data-target="tblMoves" aria-label="بحث في جدول الحركات" placeholder="بحث"></div>
    <div class="table-wrap"><table class="data" id="tblMoves">
      <thead><tr>
        <th class="g-identity">التاريخ</th><th class="g-identity">رقم البوليصة</th><th class="g-identity">العميل</th>
        <th class="g-vehicle">السائق</th><th class="g-vehicle">صاحب السيارة</th>
        <th class="g-expense">المصروف</th><th class="g-revenue">الإيصالات</th><th class="g-revenue">الخصومات</th><th class="g-identity">ملاحظة</th>
      </tr></thead>
      <tbody>
        <?php foreach ($movements['rows'] as $mv): ?>
          <tr><td><?= dfmt($mv['d']) ?></td><td class="strong"><?= e($mv['bill_number']) ?></td>
            <td class="start"><?= e($mv['company']) ?></td>
            <td><?= e($mv['driver']) ?></td><td><?= e($mv['car_owner']) ?></td>
            <td class="neg"><?= money($mv['total_expense'], false) ?></td>
            <td><?= money($mv['total_receipt'], false) ?></td>
            <td><?= money($mv['total_discount'], false) ?></td>
            <td><?= e($mv['note']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$movements['rows']): ?><tr><td colspan="9"><?php partial('empty', ['icon' => 'shuffle', 'title' => 'مفيش حركات في الفترة دي']); ?></td></tr><?php endif; ?>
      </tbody>
      <?php if ($movements['rows']): ?>
        <tfoot><tr><td colspan="5">الإجمالي</td>
          <td><?= money($movements['totals']['e'], false) ?></td>
          <td><?= money($movements['totals']['r'], false) ?></td>
          <td><?= money($movements['totals']['d'], false) ?></td><td></td></tr></tfoot>
      <?php endif; ?>
    </table></div>
    <?php partial('count_note', ['shown' => count($movements['rows']), 'total' => (int) $movements['totals']['c'], 'unit' => 'حركة']); ?>
  </div>

<?php else: /* permits */ ?>
  <?php $permitTypes = ['sader' => 'إذن (صادر)', 'warid' => 'شهادة (وارد)']; ?>
  <?php
  $pmStats = [
    ['label' => 'إجمالي الأذون',  'value' => intf($permitsList['totals']['c']),          'icon' => 'folder',
     'tone' => 'navy',  'drill' => '#rpPermits', 'title' => 'الأذون والشهادات'],
    ['label' => 'مفتوحة',         'value' => intf($permitsList['totals']['open_cnt']),   'icon' => 'folder',
     'tone' => 'green', 'drill' => '#rpPermits', 'title' => 'الأذون والشهادات'],
    ['label' => 'مقفولة',         'value' => intf($permitsList['totals']['closed_cnt']), 'icon' => 'check-circle',
     'tone' => 'teal',  'drill' => '#rpPermits', 'title' => 'الأذون والشهادات'],
    ['label' => 'إجمالي الحاويات','value' => intf($permitsList['totals']['containers']), 'icon' => 'package',
     'tone' => 'gold',  'drill' => '#rpPermits', 'title' => 'الأذون والشهادات'],
  ];
  partial('stat_grid', ['items' => count($pmStats), 'body' => function () use ($pmStats) {
      foreach ($pmStats as $st) { partial('stat', $st); }
  }]);
  ?>
  <div class="card mt pad">
    <div class="tbl-head"><div class="section-head"><?= icon('folder', 16) ?> الأذون والشهادات</div>
      <input type="search" class="table-search" data-target="tblPermits" aria-label="بحث في جدول الأذونات" placeholder="بحث"></div>
    <div class="table-wrap"><table class="data" id="tblPermits">
      <thead><tr>
        <th class="g-identity">رقم الإذن</th><th class="g-identity">النوع</th><th class="g-identity">التاريخ</th>
        <th class="g-identity">الشركة</th><th class="g-identity">العميل</th>
        <th class="g-vehicle">الحاويات</th><th class="g-vehicle">البوالص</th><th class="g-expense">المصروف</th><th class="g-identity">الحالة</th>
      </tr></thead>
      <tbody>
        <?php foreach ($permitsList['rows'] as $pm): ?>
          <tr><td class="strong"><a class="lnk" href="<?= url('permits/' . $pm['id']) ?>"><?= e($pm['permit_no'] ?: '—') ?></a></td>
            <td><?= e($permitTypes[$pm['permit_type']] ?? $pm['permit_type']) ?></td>
            <td><?= dfmt($pm['d']) ?></td>
            <td class="start"><?= e($pm['company']) ?></td>
            <td class="start"><?= e($pm['client']) ?></td>
            <td><?= intf($pm['containers']) ?></td>
            <td><?= intf($pm['bills']) ?></td>
            <td class="neg"><?= money($pm['expense'], false) ?></td>
            <td><span class="pill st-<?= $pm['status'] === 'closed' ? 'paid' : 'partial' ?>"><?= $pm['status'] === 'closed' ? 'مقفول' : 'جارٍ' ?></span></td></tr>
        <?php endforeach; ?>
        <?php if (!$permitsList['rows']): ?><tr><td colspan="9"><?php partial('empty', ['icon' => 'folder', 'title' => 'مفيش أذون في الفترة دي']); ?></td></tr><?php endif; ?>
      </tbody>
    </table></div>
    <?php partial('count_note', ['shown' => count($permitsList['rows']), 'total' => (int) $permitsList['totals']['c'], 'unit' => 'إذن']); ?>
  </div>
  <?php /* محتوى نافذة عدّادات الأذونات — جاهز في الصفحة (أربعة أرقام محسوبة أصلاً) */ ?>
  <div id="rpPermits" hidden>
    <?php partial('drill', [
      'how'   => 'الأذونات والشهادات في الفترة المعروضة، وحاوياتها.',
      'total' => ['label' => 'إجمالي الحاويات', 'value' => intf($permitsList['totals']['containers'])],
      'count' => count($permitsList['rows']),
      'body'  => function () use ($permitsList, $permitTypes) {
        foreach (array_slice($permitsList['rows'], 0, 40) as $pm) {
            partial('row_item', [
                'title'  => ($pm['permit_no'] ?: '—'),
                'ref'    => $permitTypes[$pm['permit_type']] ?? null,
                'icon'   => 'folder',
                'tone'   => $pm['permit_type'] === 'warid' ? 'warid' : 'sader',
                'url'    => url('permits/' . $pm['id']),
                'meta'   => array_values(array_filter([$pm['company'] ?: null, dfmt($pm['d'])])),
                'amount' => intf($pm['containers']), 'sub' => 'حاوية',
            ]);
        }
      },
    ]); ?>
  </div>
<?php endif; ?>

</div>

<script>
/* اختصارات الفترة — بتملّى الحقلين وبتبعت الفورم (التبويب محفوظ في حقل مخفي) */
(function () {
  var form = document.getElementById('filterForm');
  if (!form) { return; }
  var pad = function (n) { return String(n).padStart(2, '0'); };
  var iso = function (d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); };
  var fFrom = form.querySelector('[name=from]'), fTo = form.querySelector('[name=to]');
  document.querySelectorAll('#presets button').forEach(function (b) {
    b.addEventListener('click', function () {
      var now = new Date(), from = '', to = '', p = b.dataset.preset;
      if (p === 'today') { from = to = iso(now); }
      else if (p === 'week') { var s = new Date(now); s.setDate(now.getDate() - ((now.getDay() + 1) % 7)); from = iso(s); to = iso(now); } // الأسبوع يبدأ السبت
      else if (p === 'month') { from = iso(new Date(now.getFullYear(), now.getMonth(), 1)); to = iso(now); }
      else if (p === 'quarter') { var q = Math.floor(now.getMonth() / 3) * 3; from = iso(new Date(now.getFullYear(), q, 1)); to = iso(now); }
      else if (p === 'year') { from = now.getFullYear() + '-01-01'; to = now.getFullYear() + '-12-31'; }
      fFrom.value = from; fTo.value = to;
      form.submit();
    });
  });
})();
</script>
