<?php
/**
 * القراءة الذكية — مشتركة بين شاشة الأسطول وكارت السيارة (v55).
 *
 * ⚠️ **ملف واحد للشاشتين عن قصد.** نسختين كانوا هيفترقوا مع أول تعديل،
 *    والمالك يلاقي «قراءة سريعة» في مكان وشكل تاني في المكان التاني.
 *    بيتضمّ بـ`include __DIR__ . '/_analytics.php'` (نفس نمط `m/_back.php`)
 *    لأن `partial()` بتدوّر في `app/Views/partials/` بس.
 *
 * ⚠️ **كل رقم هنا مشتقّ من الأرقام اللي فوقه في نفس الصفحة** — مفيش استعلام
 *    جديد ولا تعريف تاني لأي رقم، فمستحيل القراءة تقول حاجة والأرقام تقول غيرها.
 *
 * متغيّرات مطلوبة قبل الـinclude:
 *   $period    array   الفترة الحالية
 *   $trend     array   `OfficeCar::trend()`
 *   $rates     array   `OfficeCar::rates()`
 *   $insights  array   `OfficeCar::insights()`
 *   $anaTotals array   `perf` أو `fleetSummary` (نفس المفاتيح)
 *   $anaBase   string  المسار اللي أعمدة الرسم بتنقل ليه (بلا ?)
 */
use App\Models\OfficeCar;

$anaKind = $period['kind'] === 'all' ? 'month' : $period['kind'];
$anaKindLbl = ['day' => 'يوم', 'week' => 'أسبوع', 'month' => 'شهر'][$anaKind] ?? 'شهر';
?>

<?php /* ═══ قراءة سريعة ═══ */ ?>
<?php if ($insights): ?>
  <?php partial('panel', [
    'title' => 'قراءة سريعة',
    'icon'  => 'sparkles',
    'class' => 'mb',
    'body'  => function () use ($insights) {
      echo '<div class="ins-list">';
      foreach ($insights as $i) {
        echo '<div class="ins-row ' . e($i['tone']) . '">' . icon($i['icon'], 17)
           . '<span>' . e($i['text']) . '</span></div>';
      }
      echo '</div>';
    },
  ]); ?>
<?php endif; ?>

<div class="car-grid">
  <?php /* ═══ الاتجاه: آخر ١٢ فترة ═══ */ ?>
  <?php partial('panel', [
    'title' => 'النولون على مدى آخر ١٢ ' . $anaKindLbl,
    'icon'  => 'bar-chart',
    'body'  => function () use ($trend, $anaBase, $period, $anaKind) {
      $bars = [];
      foreach ($trend as $t) {
        $bars[] = [
          'label'   => $t['label'],
          'value'   => (float) $t['freight'],
          'display' => (float) $t['freight'] > 0 ? money0($t['freight'], false) : '',
          'url'     => url($anaBase . '?' . http_build_query(['p' => $anaKind, 'd' => $t['from']])),
          'on'      => ($period['from'] ?? null) === $t['from'],
          'title'   => dfmt($t['from']) . ' ← ' . dfmt($t['to'])
                     . ' · ' . intf((int) $t['trips']) . ' رحلة · نولون ' . money0($t['freight']),
        ];
      }
      partial('mini_bars', ['bars' => $bars, 'tone' => 'green',]);
    },
  ]); ?>

  <?php /* ═══ الاتجاه: عدد الرحلات ═══ */ ?>
  <?php partial('panel', [
    'title' => 'الرحلات على مدى آخر ١٢ ' . $anaKindLbl,
    'icon'  => 'truck',
    'body'  => function () use ($trend, $anaBase, $period, $anaKind) {
      $bars = [];
      foreach ($trend as $t) {
        $bars[] = [
          'label'   => $t['label'],
          'value'   => (float) $t['trips'],
          'display' => (int) $t['trips'] > 0 ? intf((int) $t['trips']) : '',
          'url'     => url($anaBase . '?' . http_build_query(['p' => $anaKind, 'd' => $t['from']])),
          'on'      => ($period['from'] ?? null) === $t['from'],
          'title'   => dfmt($t['from']) . ' ← ' . dfmt($t['to'])
                     . ' · ' . intf((int) $t['trips']) . ' رحلة · ' . intf((int) $t['containers']) . ' حاوية',
        ];
      }
      partial('mini_bars', ['bars' => $bars, 'tone' => 'navy',]);
    },
  ]); ?>
</div>

<?php /* ═══ معدلات مشتقّة ═══ */ ?>
<?php partial('panel', [
  'title' => 'معدلات الفترة',
  'icon'  => 'calculator',
  'class' => 'mb',
  'body'  => function () use ($rates, $anaTotals) {
    /* ⚠️ أي معدل مقامه صفر بيرجع null من الموديل — بنعرض «—» مش صفر،
       لأن «مفيش بيانات» غير «صفر». */
    $cell = static function (string $label, ?string $value, ?string $suffix = null): void {
      echo '<div class="rate-cell"><em>' . e($label) . '</em><b>'
         . e($value ?? '—') . '</b>'
         . ($value !== null && $suffix ? '<span>' . e($suffix) . '</span>' : '')
         . '</div>';
    };
    $m = static fn (?float $v): ?string => $v === null ? null : money0($v, false);
    $p = static fn (?float $v): ?string => $v === null ? null : number_format($v, 0) . '٪';

    echo '<div class="rate-grid">';
    $cell('نولون الرحلة الواحدة', $m($rates['freight_per_trip']), 'ج.م');
    $cell('نولون الحاوية الواحدة', $m($rates['freight_per_container']), 'ج.م');
    $cell('تكلفة الرحلة (عهدة + مصاريف)', $m($rates['cost_per_trip']), 'ج.م');
    $cell('مصاريف الرحلة الواحدة', $m($rates['expense_per_trip']), 'ج.م');
    $cell('العهد من النولون', $p($rates['custody_pct']));
    $cell('المصاريف من النولون', $p($rates['expense_pct']));
    $cell('الصافي من النولون', $p($rates['net_pct']));
    $cell('أيام شغل', $rates['period_days']
        ? intf((int) $rates['work_days']) . ' / ' . intf((int) $rates['period_days'])
        : intf((int) $rates['work_days']),
        $rates['utilization'] !== null ? '(' . number_format((float) $rates['utilization'], 0) . '٪)' : null);
    echo '</div>';
  },
]); ?>
