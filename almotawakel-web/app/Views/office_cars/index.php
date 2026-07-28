<?php
/**
 * سيارات المكتب — لوحة الأسطول (v54).
 *
 * ⚠️ **ممنوع رقم أصمّ**: كل رقم فوق ليه `drill` بيفتح البنود اللي اتحسب منها،
 *    وكل كارت سيارة بيوصّل لصفحتها.
 *
 * ⚠️ **كل الأرقام عن الفترة المختارة**، والمقارنة مع الفترة اللي قبلها بنفس
 *    الطول. الفترة بتتمرّر لنوافذ التفاصيل كمان (`?p=&d=`) وإلا النافذة
 *    تقول رقمًا والشاشة تقول غيره.
 *
 * متغيّرات: $period $fleet $summary $prevSum $orphans
 */
use App\Models\OfficeCar;

$q = array_filter(['p' => $period['kind'], 'd' => $_GET['d'] ?? null]);
$drill = static fn (string $what): string => url('cars/drill/' . $what . '?' . http_build_query($q));
$carUrl = static fn (int $id): string => url('cars/' . $id . ($q ? '?' . http_build_query($q) : ''));
$d = static function (string $k) use ($summary, $prevSum): ?float {
    if ($prevSum === null) { return null; }
    return OfficeCar::delta((float) $summary[$k], (float) $prevSum[$k]);
};
$prevLbl = $period['prev_label'] ? 'عن ' . $period['prev_label'] : null;
?>
<div class="toolbar">
  <div class="section-head" style="margin:0"><?= icon('truck', 18) ?> سيارات المكتب</div>
  <div class="spacer"></div>
  <button type="button" class="btn ghost secret-toggle" aria-pressed="false"><?= icon('search', 14) ?> إظهار الأرصدة</button>
  <a class="btn gold" href="<?= url('cars/new') ?>"><?= icon('plus', 16) ?> أضف سيارة</a>
</div>

<?php /* ═══ شريط الفترة — نفس منطق `day_bar` بس على فترة مش يوم ═══ */ ?>
<div class="day-bar cars-period">
  <span class="day-bar-ic" aria-hidden="true"><?= icon('calendar', 20) ?></span>
  <div class="day-bar-txt">
    <strong><?= e($period['label']) ?></strong>
  </div>
  <div class="cars-pchips">
    <?php foreach (OfficeCar::PERIODS as $k => $lbl): ?>
      <a class="cars-pchip<?= $period['kind'] === $k ? ' on' : '' ?>"
         href="<?= url('cars?' . http_build_query(array_filter(['p' => $k, 'd' => $_GET['d'] ?? null]))) ?>"><?= e($lbl) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<?php /* ═══ أرقام الأسطول ═══ */ ?>
<?php partial('stat_grid', ['items' => 6, 'body' => function () use ($summary, $drill, $d, $prevLbl) { ?>
  <?php partial('stat', [
    'label' => 'رحلات', 'value' => intf($summary['trips']),
    'icon' => 'truck', 'tone' => 'navy',
    'delta' => $d('trips'), 'delta_label' => $prevLbl,
    'drill' => $drill('trips'), 'title' => 'رحلات سيارات المكتب في الفترة',
  ]); ?>
  <?php partial('stat', [
    'label' => 'حاويات', 'value' => intf($summary['containers']),
    'icon' => 'package', 'tone' => 'teal',
    'delta' => $d('containers'), 'delta_label' => $prevLbl,
    'drill' => $drill('containers'), 'title' => 'الحاويات اللي شالتها سيارات المكتب',
  ]); ?>
  <?php partial('stat', [
    'label' => 'النولون', 'value' => money0($summary['freight']),
    'icon' => 'banknote', 'tone' => 'green',
    'delta' => $d('freight'), 'delta_label' => $prevLbl,
    'drill' => $drill('freight'), 'title' => 'نولون رحلات سيارات المكتب',
  ]); ?>
  <?php partial('stat', [
    'label' => 'عهد للسواقين', 'value' => money0($summary['custody']),
    'icon' => 'wallet', 'tone' => 'gold',
    'delta' => $d('custody'), 'delta_label' => $prevLbl, 'delta_good' => 'down',
    'drill' => $drill('custody'), 'title' => 'العهد اللي اتدفعت لسواقين سيارات المكتب',
  ]); ?>
  <?php partial('stat', [
    'label' => 'مصاريف السيارات', 'value' => money0($summary['expenses']),
    'icon' => 'trending-down', 'tone' => 'red',
    'delta' => $d('expenses'), 'delta_label' => $prevLbl, 'delta_good' => 'down',
    'drill' => $drill('expenses'), 'title' => 'سولار وإصلاحات وغيره',
  ]); ?>
  <?php partial('stat', [
    'label' => 'صافي الأسطول', 'value' => money0($summary['net']),
    'icon' => 'bar-chart', 'tone' => 'purple', 'secret' => true,
    'delta' => $d('net'), 'delta_label' => $prevLbl,
    'drill' => $drill('net'), 'title' => 'نولون − عهد − مصاريف',
  ]); ?>
<?php }]); ?>

<?php /* ⚠️ سطر لازم يفضل ظاهر — قرار المالك إن مصاريف السيارة معزولة عن
         الخزنة، يعني خزنة المكتب بتقول رقمًا أعلى من الحقيقة بمقدارها. */ ?>
<div class="cars-note">
  <?= icon('info', 16) ?>
  <span>مصاريف السيارات دي <b>مش داخلة خزنة المكتب ولا التقفيل اليومي</b> — سجل خاص بالسيارات بس.
    يعني رصيد الخزنة أعلى من الحقيقة بمقدار <b><?= e(money0($summary['expenses'])) ?></b> في الفترة دي.</span>
</div>

<?php /* ═══ القراءة الذكية — نفس الملف المستعمل في كارت السيارة ═══ */ ?>
<?php $anaTotals = $summary; $anaBase = 'cars'; include __DIR__ . '/_analytics.php'; ?>

<?php /* ═══ كروت السيارات ═══ */ ?>
<?php
$busy  = array_values(array_filter($fleet, static fn ($c) => (int) $c['trips'] > 0 || (float) $c['expenses'] > 0));
$quiet = array_values(array_filter($fleet, static fn ($c) => (int) $c['trips'] === 0 && (float) $c['expenses'] <= 0));
$maxFreight = 0.0;
foreach ($busy as $c) { $maxFreight = max($maxFreight, (float) $c['freight']); }
?>
<?php partial('panel', [
  'title'  => 'السيارات في ' . $period['label'],
  'icon'   => 'truck',
  'badge'  => $busy ? ['text' => intf(count($busy)), 'tone' => 'navy'] : null,
  'class'  => 'mb',
  'body'   => function () use ($busy, $carUrl, $maxFreight, $fleet) {
    if (!$busy) {
      partial('empty', [
        'emoji'  => '🚚',
        'title'  => $fleet ? 'مفيش حركة على أي سيارة في الفترة دي' : 'مفيش سيارات مسجَّلة لسه',
        'action' => $fleet ? null : ['label' => 'أضف سيارة', 'url' => url('cars/new')],
      ]);
      return;
    }
    echo '<div class="rp-cards flat">';
    foreach ($busy as $c) {
      $tags = [
        ['icon' => 'truck', 'text' => intf($c['trips']) . ' رحلة'],
        ['icon' => 'package', 'text' => intf($c['containers']) . ' حاوية'],
        ['icon' => 'banknote', 'text' => 'نولون ' . money0($c['freight'])],
      ];
      if ((float) $c['expenses'] > 0) {
        $tags[] = ['icon' => 'trending-down', 'text' => 'مصاريف ' . money0($c['expenses']), 'tone' => 'ex'];
      }
      if ($c['status'] === 'inactive') {
        $tags[] = ['icon' => 'alert', 'text' => 'السيارة متوقفة', 'tone' => 'low'];
      }
      partial('entity_card', [
        'name'  => $c['name'],
        'url'   => $carUrl((int) $c['id']),
        'value' => money0($c['net']),
        'value_label' => 'صافي السيارة',
        'value_tone'  => (float) $c['net'] < 0 ? 'neg' : 'pos',
        'secret' => true,
        'alert'  => (float) $c['net'] < 0,
        'meter'  => $maxFreight > 0 ? [
          ['cls' => 'sp', 'pct' => min(100, (float) $c['freight'] / $maxFreight * 100)],
        ] : [],
        'note'  => [
          'label' => 'عهد للسواق',
          'value' => money0($c['custody']),
          'sub'   => $c['last_trip'] ? 'آخر رحلة ' . dfmt($c['last_trip']) : null,
        ],
        'tags' => $tags,
      ]);
    }
    echo '</div>';
  },
]); ?>

<?php if ($quiet): ?>
  <?php partial('panel', [
    'title'  => 'مفيش عليها حركة في الفترة دي',
    'icon'   => 'clock',
    'badge'  => ['text' => intf(count($quiet)), 'tone' => 'navy'],
    'scroll' => true,
    'body'   => function () use ($quiet, $carUrl) {
      foreach ($quiet as $c) {
        partial('row_item', [
          'title' => $c['name'],
          'icon'  => 'truck',
          'url'   => $carUrl((int) $c['id']),
          'ref'   => $c['status'] === 'inactive' ? 'متوقفة' : null,
          'meta'  => array_values(array_filter([
            ['icon' => 'clipboard', 'text' => $c['plate']],
            $c['driver'] ? ['icon' => 'user', 'text' => $c['driver']] : null,
            $c['last_activity'] ? ['icon' => 'calendar', 'text' => 'آخر نشاط ' . dfmt($c['last_activity'])] : null,
          ])),
        ]);
      }
    },
  ]); ?>
<?php endif; ?>

<?php if ($orphans): ?>
  <?php /* لوحات مكتوب عليها «المكتب» في البوالص ومش في السجل.
           ⚠️ `car_owner` بيتكتب بالإيد، ومتحقَّق إن عربيات مستأجرة اتكتب
           قدامها «المكتب» غلط — فالمالك له طريقان: يسجّلها سيارة، أو يقول
           «مش بتاعتنا» فتختفي من التنبيه. التصادمات ما بتتدمجش تلقائيًّا
           (تحذير ٣) والبوليصة نفسها ما بتتلمسش. */ ?>
  <?php partial('panel', [
    'title'  => 'لوحات مكتوب عليها «المكتب» ومش في السجل',
    'icon'   => 'alert',
    'badge'  => ['text' => intf(count($orphans)), 'tone' => 'red'],
    'class'  => 'mb',
    'body'   => function () use ($orphans) {
      foreach ($orphans as $o) {
        partial('row_item', [
          'title'  => (string) $o['car_no'],
          'icon'   => 'truck',
          'meta'   => array_values(array_filter([
            ['icon' => 'truck', 'text' => intf((int) $o['trips']) . ' رحلة'],
            $o['last_d'] ? ['icon' => 'calendar', 'text' => 'آخر رحلة ' . dfmt($o['last_d'])] : null,
          ])),
          'action' => ['label' => 'سجّلها سيارة', 'url' => url('cars/new')],
          'extra'  => '<form method="post" action="' . e(url('cars/ignore')) . '" style="display:inline"'
                    . ' data-confirm="تمام — «' . e((string) $o['car_no']) . '» مش سيارة بتاعتنا؟">'
                    . csrfField()
                    . '<input type="hidden" name="plate" value="' . e((string) $o['car_no']) . '">'
                    . '<button class="btn ghost sm" type="submit">مش بتاعتنا</button></form>',
        ]);
      }
    },
  ]); ?>
<?php endif; ?>

<?php if ($ignored): ?>
  <details class="cars-ignored">
    <summary><?= icon('x', 15) ?> لوحات قلت عليها «مش بتاعتنا» (<?= e(intf(count($ignored))) ?>)</summary>
    <div class="d-list flat">
      <?php foreach ($ignored as $ig): ?>
        <?php partial('row_item', [
          'title' => (string) $ig['plate'],
          'icon'  => 'truck',
          'meta'  => array_values(array_filter([
            (int) $ig['trips'] > 0 ? ['icon' => 'truck', 'text' => intf((int) $ig['trips']) . ' رحلة في البوالص'] : null,
            $ig['last_d'] ? ['icon' => 'calendar', 'text' => 'آخر رحلة ' . dfmt($ig['last_d'])] : null,
          ])),
          'extra' => '<form method="post" action="' . e(url('cars/unignore')) . '" style="display:inline">'
                   . csrfField()
                   . '<input type="hidden" name="key" value="' . e((string) $ig['key']) . '">'
                   . '<button class="btn ghost sm" type="submit">رجّعها للتنبيه</button></form>',
        ]); ?>
      <?php endforeach; ?>
    </div>
  </details>
<?php endif; ?>

<?php /* ⚠️ ستايل شريط الفترة والتنبيه في `app.css` مش هنا — الاتنين مستعملين
         في `index` و`card` مع بعض، ونسختين في `<style>` كانوا هيفترقوا. */ ?>
