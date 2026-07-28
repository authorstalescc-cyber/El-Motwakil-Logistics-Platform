<?php
/**
 * تقارير التشغيل — الشاشة الرئيسية (النسخة التانية، بقرار المالك).
 *
 * ⚠️ **إيه اللي اتغيّر وليه — لا ترجّع اللي اتشال:**
 *    النسخة الأولى كانت **بتقول نفس الحاجة أربع مرات**: ٥ كروت أرقام، وبعدها
 *    لوح «قراءة سريعة» بيعيد صياغة نفس الأرقام في جُمل، وبعدها ٦ لوحات تقسيم
 *    متطابقة الشكل، وبعدها عيّنة بوالص كل رقم بيدرِّل عليها أصلاً.
 *    دلوقتي: **رقم بطل واحد** ← **شريط فلوس** ← **لوح توزيع واحد بمبدّل
 *    عدسة** ← **إيقاع + محتاج انتباهك**. صفر تكرار.
 *
 * ⚠️ **قاعدة «ممنوع رقم أصمّ» محفوظة بالكامل** — الرقم البطل وكل شريحة وكل
 *    خانة فلوس وكل صف في التوزيع بيفتحوا تفاصيلهم. والصف بيفتح **بنوده هو**
 *    (بوالص الميناء ده) مش نفس القايمة تاني.
 *
 * ⚠️ **التبويبات روابط حقيقية `?dim=`** مش تبديل بجافاسكريبت (قاعدة `tabs`):
 *    الرجوع بالمتصفح والمشاركة بيشتغلوا، والصفحة بتعمل **استعلام تقسيم واحد
 *    بدل تسعة**.
 *
 * ⚠️ **مفيش نولون عن قصد** — طلب المالك الحرفي «بعيدًا عن الناولون».
 *
 * متغيّرات: $period $totals $prev $rates $trend $dim $tabs $rows $attention $undated
 */
use App\Models\OpsReport;

$q = array_filter([
    'p'    => $period['kind'],
    'd'    => $_GET['d'] ?? null,
    'from' => $period['kind'] === 'custom' ? $period['from'] : null,
    'to'   => $period['kind'] === 'custom' ? $period['to']   : null,
]);
$qs = $q ? '?' . http_build_query($q) : '';

/** رابط نافذة تفاصيل — الفترة بتتمرّر دايمًا وإلا النافذة تقول رقمًا تاني. */
$drill = static function (string $what, array $extra = []) use ($q): string {
    return url('ops/drill/' . $what . '?' . http_build_query(array_merge($q, $extra)));
};
/** رابط الشاشة بنفس الفترة والعدسة، مع تغيير محدَّد. */
$screen = static function (array $over) use ($q, $dim): string {
    $a = array_merge($q, ['dim' => $dim], $over);
    return url('ops?' . http_build_query(array_filter($a,
        static fn ($v) => $v !== null && $v !== '')));
};
$dlt = static function (string $k) use ($totals, $prev): ?float {
    return $prev === null ? null : OpsReport::delta((float) $totals[$k], (float) $prev[$k]);
};
/** رقم عشري للعرض — مفيش helper عام للكسور (`intf` للصحيح و`money` للفلوس). */
$dec = static fn ($v, int $p = 1): string => number_format((float) $v, $p);

$isMix  = $dim === 'mix';
$dimLbl = $tabs[$dim]['label'] ?? '';
$maxRow = 0;
foreach ($rows as $r) { $maxRow = max($maxRow, (int) $r['containers']); }
/** الصفوف المعروضة في اللوح — الباقي وراء «كل الصفوف» والطباعة. */
$cap = 12;
?>
<div class="toolbar">
  <div class="section-head" style="margin:0"><?= icon('package', 18) ?> تقارير التشغيل</div>
  <div class="spacer"></div>
  <a class="btn ghost" target="_blank" rel="noopener"
     href="<?= url('ops/print' . $qs) ?>"><?= icon('file-text', 16) ?> تقرير للطباعة</a>
  <a class="btn gold" target="_blank" rel="noopener"
     href="<?= url('ops/print' . ($qs !== '' ? $qs . '&' : '?') . 'detail=1') ?>"><?= icon('download', 16) ?> تقرير مفصّل</a>
</div>

<?php /* ═══ شريط الفترة ═══ */ ?>
<div class="day-bar cars-period">
  <span class="day-bar-ic" aria-hidden="true"><?= icon('calendar', 20) ?></span>
  <div class="day-bar-txt">
    <strong><?= e($period['label']) ?></strong>
  </div>
  <div class="cars-pchips">
    <?php foreach (OpsReport::PERIODS as $k => $lbl): ?>
      <?php /* الشريحة بتحافظ على العدسة المختارة — تغيير الفترة ما يرجّعكش لأول تبويب */ ?>
      <a class="cars-pchip<?= $period['kind'] === $k ? ' on' : '' ?>"
         href="<?= e($screen($k === 'custom'
             ? ['p' => $k, 'from' => $period['from'], 'to' => $period['to']]
             : ['p' => $k, 'from' => null, 'to' => null])) ?>"><?= e($lbl) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<form class="toolbar ops-range" method="get" action="<?= url('ops') ?>">
  <input type="hidden" name="p" value="custom">
  <input type="hidden" name="dim" value="<?= e($dim) ?>">
  <label class="field"><span>من تاريخ</span>
    <input type="date" name="from" value="<?= e((string) ($period['from'] ?? '')) ?>">
  </label>
  <label class="field"><span>إلى تاريخ</span>
    <input type="date" name="to" value="<?= e((string) ($period['to'] ?? '')) ?>">
  </label>
  <button class="btn navy" type="submit"><?= icon('search', 16) ?> اعرض الفترة دي</button>
</form>

<?php /* ═══ ① الرقم البطل ═══ */ ?>
<section class="ops-hero">
  <div class="ops-hero-top">
    <div>
      <div class="ops-hero-num">
        <b><?= e(intf($totals['containers'])) ?></b>
        <span class="ops-hero-unit">حاوية</span>
        <?php $dc = $dlt('containers'); if ($dc !== null): ?>
          <span class="ops-hero-trend <?= $dc >= 0 ? 'up' : 'down' ?>">
            <?= icon($dc >= 0 ? 'trending-up' : 'trending-down', 15) ?>
            <?= e(($dc >= 0 ? '+' : '−') . abs($dc)) ?>٪ عن <?= e($period['prev_label']) ?>
          </span>
        <?php endif; ?>
      </div>
      <div class="ops-hero-lbl"><?= icon('package', 16) ?> اتداولت في <?= e($period['label']) ?></div>
    </div>

    <?php /* الاتجاه جنب الرقم مش في لوح لوحده — نفس المساحة، معلومة أكتر */ ?>
    <?php if (count($trend) > 2): ?>
      <?php
      $tKind = in_array($period['kind'], ['day', 'week', 'month'], true) ? $period['kind'] : 'month';
      $bars = [];
      foreach ($trend as $t) {
          $bars[] = [
              'label'   => $t['label'],
              'value'   => (float) $t['containers'],
              'display' => '',
              'url'     => $screen(['p' => $tKind, 'd' => $t['from'], 'from' => null, 'to' => null]),
              'on'      => ($period['from'] ?? null) === $t['from'],
              'title'   => dfmt($t['from']) . ' ← ' . dfmt($t['to']) . ' · '
                         . intf($t['containers']) . ' حاوية · ' . intf($t['trips']) . ' بوليصة',
          ];
      }
      ?>
      <div class="ops-hero-spark">
        <?php partial('mini_bars', ['bars' => $bars, 'tone' => 'gold', 'height' => 64,]); ?>
      </div>
    <?php endif; ?>
  </div>

  <?php /* شرايح مساندة — أرقام تانية مهمة بس مش بطلة، وكلها بتفتح تفاصيلها */ ?>
  <div class="ops-hero-chips">
    <button type="button" class="ops-chip" data-drill="<?= e($drill('trips')) ?>"
            data-drill-title="بوالص الفترة"><b><?= e(intf($totals['trips'])) ?></b> <span>بوليصة</span></button>
    <button type="button" class="ops-chip" data-drill="<?= e($drill('permits')) ?>"
            data-drill-title="الأذونات اللي اتحرّك عليها"><b><?= e(intf($totals['permits'])) ?></b> <span>إذن وشهادة</span></button>
    <button type="button" class="ops-chip" data-drill="<?= e($drill('port')) ?>"
            data-drill-title="الموانئ"><b><?= e(intf($totals['ports'])) ?></b> <span>ميناء</span></button>
    <button type="button" class="ops-chip" data-drill="<?= e($drill('company')) ?>"
            data-drill-title="الشركات"><b><?= e(intf($totals['companies'])) ?></b> <span>شركة</span></button>
    <?php if ($rates['per_day'] !== null): ?>
      <button type="button" class="ops-chip" data-drill="<?= e($drill('containers')) ?>"
              data-drill-title="كل بوالص الفترة"><b><?= e($dec($rates['per_day'])) ?></b> <span>حاوية / يوم شغل</span></button>
    <?php endif; ?>
  </div>
</section>

<?php /* ═══ ② الفلوس — تلات أرقام في صف، مش خمس كروت ═══ */ ?>
<div class="ops-money">
  <button type="button" class="ops-money-cell" data-drill="<?= e($drill('custody')) ?>"
          data-drill-title="العهد اللي اتدفعت للسواقين">
    <span class="ops-money-lbl"><?= icon('wallet', 15) ?> عهد اتدفعت للسواقين</span>
    <span class="ops-money-val"><?= e(money0($totals['custody'])) ?></span>
  </button>
  <button type="button" class="ops-money-cell" data-drill="<?= e($drill('deducted')) ?>"
          data-drill-title="المخصوم من خزنة المكتب">
    <span class="ops-money-lbl"><?= icon('briefcase', 15) ?> المخصوم من الخزنة</span>
    <span class="ops-money-val"><?= e(money0($totals['deducted'])) ?></span>
  </button>
  <button type="button" class="ops-money-cell" data-drill="<?= e($drill('containers')) ?>"
          data-drill-title="البوالص اللي اتحسب منها المتوسط">
    <span class="ops-money-lbl"><?= icon('coins', 15) ?> عهدة لكل حاوية</span>
    <span class="ops-money-val"><?= $rates['per_container'] !== null ? e(money0($rates['per_container'])) : '—' ?></span>
  </button>
</div>

<?php /* ⚠️ **تحذير وظيفي — لازم يفضل ظاهر**: من غيره المالك يفتكر التقرير
         ناقص. بس بقى **شارة** بدل فقرة من ٣ أسطر (قانون التصميم، بند ١:
         التحذير الوظيفي مسموح في ≤٦ كلمات أو شارة). التفصيل في `title`. */ ?>
<p class="ops-note">
  <span class="pill" title="التقرير ده عن الحاويات والموانئ والعهد بس. النولون مكانه «سيارات المكتب» و«التقارير المالية»."><?= icon('info', 13) ?> بلا نولون</span>
  <a href="<?= url('cars') ?>">سيارات المكتب</a>
  <a href="<?= url('reports') ?>">التقارير المالية</a>
</p>

<?php /* ═══ ③ التوزيع — لوح واحد بمبدّل عدسة بدل ٦ لوحات متطابقة ═══ */ ?>
<div class="d-card ops-dist">
  <div class="d-card-head">
    <h3><?= icon('bar-chart', 18) ?>
      <span>التوزيع</span>
      <?php /* العدد شارة — العدسة نفسها ظاهرة في التبويب النشط تحت */ ?>
      <span class="d-badge navy"><?= e(intf(count($rows))) ?></span>
    </h3>
    <?php /* ⚠️ الزرار بيظهر **بس لو فيه صفوف مقطوعة** — من غير الشرط ده
             بيفتح نفس القايمة المعروضة بالحرف، وده بالظبط التكرار اللي
             إعادة التصميم اتعملت عشانه. */ ?>
    <?php if (count($rows) > $cap): ?>
      <button type="button" class="btn ghost sm" data-drill="<?= e($drill($isMix ? 'mix' : $dim)) ?>"
              data-drill-title="<?= e($dimLbl) ?>">كل الـ<?= e(intf(count($rows))) ?> <?= icon('chevron', 15) ?></button>
    <?php endif; ?>
  </div>

  <nav class="ops-seg" aria-label="عدسة التوزيع">
    <?php foreach ($tabs as $k => $t): ?>
      <a class="<?= $k === $dim ? 'on' : '' ?>" href="<?= e($screen(['dim' => $k])) ?>"
         <?= $k === $dim ? 'aria-current="page"' : '' ?>><?= icon($t['icon'], 14) ?> <?= e($t['label']) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php if (!$rows): ?>
    <?php partial('empty', ['emoji' => '📭', 'title' => 'مفيش حركة في الفترة دي',]); ?>
  <?php else: ?>
    <div class="ops-rank">
      <?php foreach (array_slice($rows, 0, $cap) as $i => $r):
        $pct  = $totals['containers'] > 0 ? $r['containers'] / $totals['containers'] * 100 : 0;
        $wide = $maxRow > 0 ? max(1.5, $r['containers'] / $maxRow * 100) : 1.5;
      ?>
        <?php /* الصف بيفتح **بنوده هو** (بوالص الميناء ده) مش نفس القايمة تاني */ ?>
        <button type="button" class="ops-rank-row<?= $i === 0 ? ' top' : '' ?>"
                data-drill="<?= e($drill('rows', ['dim' => $dim, 'v' => $r['name']])) ?>"
                data-drill-title="<?= e($r['name']) ?>">
          <span class="ops-rank-i"><?= e(intf($i + 1)) ?></span>
          <span class="ops-rank-body">
            <span class="ops-rank-top">
              <span class="ops-rank-name"><?= e($r['name']) ?></span>
              <span class="ops-rank-val"><?= e(intf($r['containers'])) ?><i>حاوية</i></span>
            </span>
            <span class="ops-rank-track">
              <span class="ops-rank-fill" style="width:<?= number_format($wide, 2, '.', '') ?>%"></span>
            </span>
            <span class="ops-rank-meta">
              <span><b><?= e((string) round($pct)) ?>٪</b> من الحاويات</span>
              <span><?= e(intf($r['trips'])) ?> بوليصة</span>
              <?php if (!$isMix && (int) ($r['permits'] ?? 0) > 0): ?>
                <span><?= e(intf((int) $r['permits'])) ?> إذن</span>
              <?php endif; ?>
              <?php if (!$isMix && (float) ($r['custody'] ?? 0) > 0): ?>
                <span>عهد <?= e(money0($r['custody'])) ?></span>
              <?php endif; ?>
            </span>
          </span>
        </button>
      <?php endforeach; ?>
    </div>
    <?php partial('count_note', [
      'shown' => min($cap, count($rows)), 'total' => count($rows), 'unit' => 'بند',
    ]); ?>
  <?php endif; ?>
</div>

<?php /* ═══ ④ الإيقاع + محتاج انتباهك ═══ */ ?>
<div class="grid-2">
  <?php partial('panel', [
    'title' => 'إيقاع الشغل',
    'icon'  => 'clock',
    'body'  => function () use ($rates, $dec) { ?>
      <div class="ops-facts">
        <div class="ops-fact">
          <div class="ops-fact-val"><?= e(intf($rates['work_days'])) ?></div>
          <div class="ops-fact-lbl">يوم فيه حركة فعلية</div>
        </div>
        <div class="ops-fact">
          <div class="ops-fact-val"><?= $rates['per_trip'] !== null ? e($dec($rates['per_trip'], 2)) : '—' ?></div>
          <div class="ops-fact-lbl">متوسط الحاويات في البوليصة</div>
        </div>
        <div class="ops-fact">
          <div class="ops-fact-val"><?= $rates['per_permit'] !== null ? e($dec($rates['per_permit'])) : '—' ?></div>
          <div class="ops-fact-lbl">متوسط الحاويات في الإذن</div>
        </div>
        <div class="ops-fact">
          <?php if ($rates['busiest']): ?>
            <div class="ops-fact-val"><?= e(intf($rates['busiest']['containers'])) ?></div>
            <div class="ops-fact-lbl">أعلى يوم — <?= e(dfmt($rates['busiest']['date'])) ?></div>
          <?php else: ?>
            <div class="ops-fact-val">—</div>
            <div class="ops-fact-lbl">أعلى يوم</div>
          <?php endif; ?>
        </div>
      </div>
    <?php },
  ]); ?>

  <?php
  /* ⚠️ اللوح ده **مش تكرار لأي رقم فوق** — بيقول بنود تصرّف: أذونات لسه فيها
     حاويات ما اتحركتش، وبيانات ناقصة بتخلّي التقرير نفسه أقل دقّة. */
  $gaps = $attention['gaps'];
  $gapRows = array_values(array_filter([
      $gaps['no_port']   > 0 ? ['n' => $gaps['no_port'],   't' => 'بوليصة الإذن بتاعها بلا ميناء', 's' => 'مش داخلة في تقسيم الموانئ'] : null,
      $gaps['no_type']   > 0 ? ['n' => $gaps['no_type'],   't' => 'بوليصة بلا تصنيف حاوية',        's' => 'بتظهر «غير محدَّد» في التصنيف'] : null,
      $gaps['no_count']  > 0 ? ['n' => $gaps['no_count'],  't' => 'بوليصة بعدد حاويات صفر',        's' => 'مش بتضيف للعدّاد فوق'] : null,
      $gaps['no_permit'] > 0 ? ['n' => $gaps['no_permit'], 't' => 'بوليصة مش مربوطة بإذن',          's' => 'بلا ميناء ولا توكيل ولا عميل'] : null,
  ]));
  $attnCount = count($attention['partial']) + count($gapRows) + ($undated > 0 ? 1 : 0);
  partial('panel', [
    'title'  => 'محتاج انتباهك',
    'icon'   => 'alert',
    'badge'  => $attnCount > 0 ? ['text' => intf($attnCount), 'tone' => 'red'] : null,
    'scroll' => true,
    'body'   => function () use ($attention, $gapRows, $undated) {
      if (!$attention['partial'] && !$gapRows && $undated === 0) {
        partial('empty', ['emoji' => '✅', 'title' => 'كله تمام',]);
        return;
      }
      foreach ($attention['partial'] as $p): ?>
        <a class="ops-attn-row warn" href="<?= url('permits/' . (int) $p['id']) ?>">
          <span class="ops-attn-ic"><?= icon('folder', 16) ?></span>
          <span class="ops-attn-body">
            <span class="ops-attn-title"><?= e(($p['permit_type'] === 'warid' ? 'شهادة ' : 'إذن ')
                . ($p['permit_no'] ?: '#' . (int) $p['id'])) ?></span>
            <span class="ops-attn-sub">اتحرّك <?= e(intf((int) $p['moved'])) ?> من
              <?= e(intf((int) $p['total'])) ?> حاوية<?php
              if (!empty($p['client'])) { echo ' · ' . e($p['client']); } ?></span>
          </span>
          <span class="ops-attn-val">باقي <?= e(intf((int) $p['left'])) ?></span>
        </a>
      <?php endforeach;

      foreach ($gapRows as $g): ?>
        <div class="ops-attn-row">
          <span class="ops-attn-ic"><?= icon('alert', 16) ?></span>
          <span class="ops-attn-body">
            <span class="ops-attn-title"><?= e($g['t']) ?></span>
            <span class="ops-attn-sub"><?= e($g['s']) ?></span>
          </span>
          <span class="ops-attn-val"><?= e(intf($g['n'])) ?></span>
        </div>
      <?php endforeach;

      if ($undated > 0): ?>
        <a class="ops-attn-row warn" href="<?= url('bawalis') ?>">
          <span class="ops-attn-ic"><?= icon('calendar', 16) ?></span>
          <span class="ops-attn-body">
            <span class="ops-attn-title">بوليصة بلا تاريخ</span>
            <span class="ops-attn-sub">مش داخلة في أي رقم في التقرير — حطّ تاريخها</span>
          </span>
          <span class="ops-attn-val"><?= e(intf($undated)) ?></span>
        </a>
      <?php endif;
    },
  ]);
  ?>
</div>
