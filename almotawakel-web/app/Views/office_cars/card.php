<?php
/**
 * كارت سيارة المكتب — أداء السيارة في الفترة (v54).
 *
 * الترتيب: هوية السيارة ← شريط الفترة ← ٦ أرقام بالمقارنة ← معادلة الصافي
 * ← لمين شغّالة (شركات/موانئ/سواقين/حاويات) ← الرحلات ← المصاريف.
 *
 * ⚠️ **ممنوع رقم أصمّ** — كل رقم ليه `drill` بيفتح بنوده، والفترة بتتمرّر
 *    للنافذة (`?p=&d=&car=`) وإلا تقول رقمًا مختلفًا عن الشاشة.
 *
 * متغيّرات: $car $period $perf $prevPerf $lifetime $trips $expenses $byCat
 *           $mix $companies $ports $drivers $expState
 */
use App\Core\FormState;
use App\Models\OfficeCar;

$cid = (int) $car['id'];
$q = array_filter(['p' => $period['kind'], 'd' => $_GET['d'] ?? null]);
$qs = $q ? '?' . http_build_query($q) : '';
$drill = static fn (string $what): string =>
    url('cars/drill/' . $what . '?' . http_build_query($q + ['car' => $cid]));
$dlt = static function (string $k) use ($perf, $prevPerf): ?float {
    if ($prevPerf === null) { return null; }
    return OfficeCar::delta((float) $perf[$k], (float) $prevPerf[$k]);
};
$prevLbl = $period['prev_label'] ? 'عن ' . $period['prev_label'] : null;
$aliases = OfficeCar::aliasList($car);
?>
<div class="page-actions">
  <?php partial('back_link', ['to' => 'cars' . $qs, 'label' => 'رجوع لسيارات المكتب']); ?>
</div>

<?php /* ═══ هوية السيارة ═══ */ ?>
<div class="car-hero<?= $car['status'] === 'inactive' ? ' off' : '' ?>">
  <span class="car-hero-ic"><?= icon('truck', 30) ?></span>
  <div class="car-hero-id">
    <h1><?= e($car['name']) ?></h1>
    <div class="car-hero-facts">
      <span><?= icon('clipboard', 14) ?> <?= e($car['plate']) ?></span>
      <?php if ($car['model']): ?><span><?= icon('layers', 14) ?> <?= e($car['model']) ?></span><?php endif; ?>
      <?php if ($car['driver']): ?><span><?= icon('user', 14) ?> <?= e($car['driver']) ?></span><?php endif; ?>
      <?php if ($car['status'] === 'inactive'): ?><span class="pill st-partial">متوقفة</span><?php endif; ?>
    </div>
    <?php if ($aliases): ?>
      <div class="car-hero-alias"><?= icon('shuffle', 13) ?>
        تهجئات بتتحسب لنفس السيارة: <?= e(implode(' · ', $aliases)) ?></div>
    <?php endif; ?>
    <?php if ($car['note']): ?><div class="car-hero-note"><?= e($car['note']) ?></div><?php endif; ?>
  </div>
  <div class="car-hero-life">
    <em>من أول يوم</em>
    <b><?= e(intf($lifetime['trips'])) ?> رحلة · <?= e(intf($lifetime['containers'])) ?> حاوية</b>
    <span>نولون <?= e(money0($lifetime['freight'])) ?></span>
  </div>
  <div class="car-hero-acts">
    <a class="btn primary sm" href="<?= url('cars/' . $cid . '/driver') ?>">
      <?= icon('wallet', 15) ?> بيان السائق</a>
    <a class="btn ghost sm" href="<?= url('cars/' . $cid . '/report' . $qs) ?>" target="_blank" rel="noopener">
      <?= icon('file-text', 15) ?> تقرير للطباعة</a>
    <a class="btn ghost sm" href="<?= url('cars/' . $cid . '/edit') ?>"><?= icon('edit', 15) ?> تعديل</a>
  </div>
</div>

<?php /* ═══ شريط الفترة ═══ */ ?>
<div class="day-bar cars-period">
  <span class="day-bar-ic" aria-hidden="true"><?= icon('calendar', 20) ?></span>
  <div class="day-bar-txt">
    <strong><?= e($period['label']) ?></strong>
  </div>
  <div class="cars-pchips">
    <?php foreach (OfficeCar::PERIODS as $k => $lbl): ?>
      <a class="cars-pchip<?= $period['kind'] === $k ? ' on' : '' ?>"
         href="<?= url('cars/' . $cid . '?' . http_build_query(array_filter(['p' => $k, 'd' => $_GET['d'] ?? null]))) ?>"><?= e($lbl) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<?php /* ═══ أرقام السيارة ═══ */ ?>
<?php partial('stat_grid', ['items' => 6, 'body' => function () use ($perf, $drill, $dlt, $prevLbl) { ?>
  <?php partial('stat', [
    'label' => 'رحلات', 'value' => intf($perf['trips']), 'icon' => 'truck', 'tone' => 'navy',
    'delta' => $dlt('trips'), 'delta_label' => $prevLbl,
    'drill' => $drill('car_trips'), 'title' => 'رحلات السيارة في الفترة',
  ]); ?>
  <?php partial('stat', [
    'label' => 'حاويات', 'value' => intf($perf['containers']), 'icon' => 'package', 'tone' => 'teal',
    'delta' => $dlt('containers'), 'delta_label' => $prevLbl,
    'drill' => $drill('car_containers'), 'title' => 'الحاويات حسب التصنيف',
  ]); ?>
  <?php partial('stat', [
    'label' => 'النولون', 'value' => money0($perf['freight']), 'icon' => 'banknote', 'tone' => 'green',
    'delta' => $dlt('freight'), 'delta_label' => $prevLbl,
    'drill' => $drill('car_freight'), 'title' => 'نولون كل رحلة',
  ]); ?>
  <?php partial('stat', [
    'label' => 'عهد للسواق', 'value' => money0($perf['custody']), 'icon' => 'wallet', 'tone' => 'gold',
    'delta' => $dlt('custody'), 'delta_label' => $prevLbl, 'delta_good' => 'down',
    'drill' => $drill('car_custody'), 'title' => 'العهد اللي اتدفعت على رحلات السيارة',
  ]); ?>
  <?php partial('stat', [
    /* ⚠️ الرقم هنا **إجمالي المصدرين** (مصاريف مسجَّلة على السيارة + بنود
       مصروفات البوالص) عشان يطابق المطروح في معادلة الصافي تحت. التفصيل في
       النافذة، والمعادلة بتفصل المصدرين عشان الازدواج يبان لو حصل. */
    'label' => 'مصاريف السيارة', 'value' => money0($perf['expenses_total'] ?? $perf['expenses']),
    'icon' => 'trending-down', 'tone' => 'red',
    'delta' => $dlt('expenses_total'), 'delta_label' => $prevLbl, 'delta_good' => 'down',
    'drill' => $drill('car_expenses'), 'title' => 'سولار وإصلاحات وبنود البوالص',
  ]); ?>
  <?php partial('stat', [
    'label' => 'صافي السيارة', 'value' => money0($perf['net']), 'icon' => 'bar-chart', 'tone' => 'purple',
    'delta' => $dlt('net'), 'delta_label' => $prevLbl,
    'drill' => $drill('car_net'), 'title' => 'إزاي اتحسب الصافي',
  ]); ?>
<?php }]); ?>

<?php /* ═══ معادلة الصافي — بلغة المالك ═══ */ ?>
<div class="car-eq">
  <span class="car-eq-t"><?= icon('info', 16) ?> صافي السيارة</span>
  <span class="car-eq-i"><em>نولون</em><b><?= e(money0($perf['freight'])) ?></b></span>
  <span class="car-eq-op">−</span>
  <span class="car-eq-i"><em>عهد للسواق</em><b><?= e(money0($perf['custody'])) ?></b></span>
  <span class="car-eq-op">−</span>
  <span class="car-eq-i"><em>مصاريف مسجَّلة</em><b><?= e(money0($perf['expenses'])) ?></b></span>
  <span class="car-eq-op">−</span>
  <span class="car-eq-i"><em>مصروفات البوالص</em><b><?= e(money0($perf['bill_expenses'] ?? 0)) ?></b></span>
  <span class="car-eq-op">=</span>
  <span class="car-eq-i res <?= (float) $perf['net'] < 0 ? 'neg' : '' ?>"><em>الصافي</em>
    <b><?= e(money0($perf['net'])) ?></b></span>
</div>
<div class="cars-note">
  <?= icon('info', 16) ?>
  <span>رقم استرشادي لأداء السيارة — <b>مش ربح محاسبي</b>. مصاريف السيارة
    <b>مش داخلة خزنة المكتب ولا التقفيل اليومي</b> (سجل خاص بالسيارات).</span>
</div>

<?php /* ═══ القراءة الذكية — نفس الملف المستعمل في شاشة الأسطول ═══ */ ?>
<?php $anaTotals = $perf; $anaBase = 'cars/' . $cid; include __DIR__ . '/_analytics.php'; ?>

<?php /* ═══ لمين شغّالة ═══ */ ?>
<div class="car-grid">
  <?php partial('panel', [
    'title' => 'الشركات', 'icon' => 'building', 'scroll' => true,
    'badge' => $companies ? ['text' => intf(count($companies)), 'tone' => 'navy'] : null,
    'action' => ['label' => 'الكل', 'drill' => $drill('car_companies'), 'title' => 'الشركات اللي شغّالة معاها'],
    'body' => function () use ($companies) {
      if (!$companies) { partial('empty', ['emoji' => '🏢', 'title' => 'مفيش رحلات في الفترة دي']); return; }
      foreach (array_slice($companies, 0, 6) as $r) {
        partial('row_item', [
          'title' => $r['name'], 'icon' => 'building',
          'meta' => [['icon' => 'truck', 'text' => intf($r['trips']) . ' رحلة'],
                     ['icon' => 'package', 'text' => intf($r['containers']) . ' حاوية']],
          'amount' => money0($r['freight'], false),
        ]);
      }
    },
  ]); ?>

  <?php partial('panel', [
    'title' => 'الموانئ والوجهات', 'icon' => 'anchor', 'scroll' => true,
    'action' => ['label' => 'الكل', 'drill' => $drill('car_ports'), 'title' => 'الموانئ والتوكيلات والوجهات'],
    'body' => function () use ($ports, $trips) {
      $dest = \App\Models\OfficeCar::breakdown($trips, 'destination');
      $any = false;
      foreach (array_slice($ports, 0, 4) as $r) {
        if ($r['name'] === '(غير محدَّد)') { continue; }
        $any = true;
        partial('row_item', [
          'title' => $r['name'], 'icon' => 'anchor',
          'meta' => [['icon' => 'truck', 'text' => intf($r['trips']) . ' رحلة']],
          'chips' => [['text' => 'ميناء']],
        ]);
      }
      foreach (array_slice($dest, 0, 5) as $r) {
        if ($r['name'] === '(غير محدَّد)') { continue; }
        $any = true;
        partial('row_item', [
          'title' => $r['name'], 'icon' => 'map-pin',
          'meta' => [['icon' => 'truck', 'text' => intf($r['trips']) . ' رحلة'],
                     ['icon' => 'package', 'text' => intf($r['containers']) . ' حاوية']],
          'chips' => [['text' => 'وجهة']],
        ]);
      }
      if (!$any) { partial('empty', ['emoji' => '📍', 'title' => 'مفيش موانئ ولا وجهات مسجَّلة في الفترة']); }
    },
  ]); ?>

  <?php partial('panel', [
    'title' => 'السواقين', 'icon' => 'user', 'scroll' => true,
    'action' => ['label' => 'الكل', 'drill' => $drill('car_drivers'), 'title' => 'السواقين اللي مشّوا السيارة'],
    'body' => function () use ($drivers) {
      if (!$drivers) { partial('empty', ['emoji' => '🧑‍✈️', 'title' => 'مفيش سواقين في الفترة دي']); return; }
      foreach (array_slice($drivers, 0, 6) as $r) {
        partial('row_item', [
          'title' => $r['name'], 'icon' => 'user',
          'meta' => [['icon' => 'truck', 'text' => intf($r['trips']) . ' رحلة']],
          'amount' => money0($r['custody'], false), 'amount_tone' => 'rc',
        ]);
      }
    },
  ]); ?>

  <?php partial('panel', [
    'title' => 'الحاويات حسب التصنيف', 'icon' => 'package', 'scroll' => true,
    'action' => ['label' => 'الكل', 'drill' => $drill('car_mix'), 'title' => 'تقسيم الحاويات'],
    'body' => function () use ($mix) {
      if (!$mix) { partial('empty', ['emoji' => '📦', 'title' => 'مفيش حاويات في الفترة دي']); return; }
      foreach ($mix as $type => $n) {
        partial('row_item', [
          'title' => (string) $type, 'icon' => 'package',
          'amount' => intf($n),
        ]);
      }
    },
  ]); ?>
</div>

<?php /* ═══ الرحلات ═══ */ ?>
<?php partial('panel', [
  'title'  => 'رحلات السيارة',
  'icon'   => 'truck',
  'badge'  => $trips ? ['text' => intf(count($trips)), 'tone' => 'navy'] : null,
  'scroll' => true,
  'class'  => 'mb',
  'body'   => function () use ($trips) {
    if (!$trips) {
      partial('empty', ['emoji' => '🚚', 'title' => 'مفيش رحلات في الفترة دي',]);
      return;
    }
    foreach ($trips as $t) {
      $meta = array_values(array_filter([
        $t['company'] ? ['icon' => 'building', 'text' => $t['company']] : null,
        $t['destination'] ? ['icon' => 'map-pin', 'text' => $t['destination']] : null,
        $t['driver'] ? ['icon' => 'user', 'text' => $t['driver']] : null,
        $t['port'] ? ['icon' => 'anchor', 'text' => $t['port']] : null,
        $t['permit_no'] ? ['icon' => 'folder', 'text' => 'إذن ' . $t['permit_no']] : null,
      ]));
      $chips = [];
      if ((int) $t['container_count'] > 0) {
        $chips[] = ['icon' => 'package',
                    'text' => intf((int) $t['container_count']) . '×' . (container_norm($t['container_type']) ?: 'حاوية')];
      }
      if ((float) $t['custody'] > 0) {
        $chips[] = ['icon' => 'wallet', 'text' => 'عهدة ' . money0($t['custody'])];
      }
      if (!empty($t['owner_mismatch'])) {
        $chips[] = ['icon' => 'alert', 'text' => 'مالك العربية مكتوب: ' . ($t['car_owner'] ?: 'فاضي')];
      }
      partial('row_item', [
        'title'  => $t['bill_number'] ? 'بوليصة ' . $t['bill_number'] : 'بوليصة #' . (int) $t['id'],
        'icon'   => 'truck',
        'tone'   => (string) $t['permit_type'] === 'warid' ? 'warid' : 'sader',
        'url'    => url('bawalis/' . (int) $t['id']),
        'meta'   => $meta,
        'chips'  => $chips,
        'amount' => money0($t['freight'], false),
        'sub'    => $t['bill_date'] ? dfmt($t['bill_date']) : null,
      ]);
    }
  },
]); ?>

<?php /* ═══ المصاريف ═══ */ ?>
<div id="expenses-panel"></div>
<?php partial('panel', [
  'title'  => 'مصاريف السيارة',
  'icon'   => 'trending-down',
  'badge'  => $expenses ? ['text' => money0($perf['expenses']), 'tone' => 'red'] : null,
  'scroll' => true,
  'class'  => 'mb',
  'body'   => function () use ($expenses, $byCat, $cid, $qs, $q, $editExp) {
    if ($byCat) {
      echo '<div class="car-cats">';
      foreach ($byCat as $c) {
        echo '<span class="car-cat">' . icon($c['icon'], 14) . ' <em>' . e($c['label']) . '</em> <b>'
           . e(money0($c['total'])) . '</b></span>';
      }
      echo '</div>';
    }
    if (!$expenses) {
      partial('empty', ['emoji' => '⛽', 'title' => 'مفيش مصاريف مسجَّلة في الفترة دي',]);
      return;
    }
    foreach ($expenses as $x) {
      $isEditing = $editExp && (int) $editExp['id'] === (int) $x['id'];
      $meta = array_values(array_filter([
        ['icon' => 'calendar', 'text' => dfmt($x['exp_date'])],
        $x['vendor'] ? ['icon' => 'building', 'text' => $x['vendor']] : null,
        $x['ref_no'] ? ['icon' => 'clipboard', 'text' => 'إيصال ' . $x['ref_no']] : null,
        $x['created_by'] ? ['icon' => 'user', 'text' => $x['created_by']] : null,
      ]));
      // زر التعديل رابط عادي بيفتح نموذج المصروف على وضع التعديل — بلا
      // جافاسكريبت، فالرجوع بالمتصفح والفتح في تبويب جديد شغّالين.
      $editUrl = url('cars/' . $cid . '?' . http_build_query($q + ['edit_exp' => (int) $x['id']]) . '#expense-form');
      partial('row_item', [
        'title'  => \App\Models\OfficeCar::kindLabel($x['category']),
        'icon'   => \App\Models\OfficeCar::kindIcon($x['category']),
        'ref'    => $x['note'] ?: null,
        'meta'   => $meta,
        'chips'  => $isEditing ? [['icon' => 'edit', 'text' => 'بتعدّله دلوقتي']] : [],
        'amount' => money0($x['amount'], false),
        'amount_tone' => 'ex',
        'extra'  => '<a class="btn ghost sm" href="' . e($editUrl) . '" aria-label="تعديل المصروف" '
                  . 'title="تعديل المصروف">' . icon('edit', 14) . '</a>'
                  . '<form method="post" action="' . e(url('cars/expense/' . (int) $x['id'] . '/delete'))
                  . '" data-confirm="تشيل المصروف ده؟" style="display:inline">'
                  . csrfField()
                  // ⚠️ `_form_token` **إجباري** — `claimForm` بترفض أي طلب كتابة
                  //    من غيره برسالة «هذا الطلب نُفّذ أو انتهت صلاحيته»، وده
                  //    كان بيخلّي زر الحذف ما يشتغلش خالص.
                  . formStateFields(FormState::token(), 'cars/' . $cid . $qs)
                  . '<button class="btn ghost sm" type="submit" aria-label="حذف المصروف">' . icon('trash', 14) . '</button>'
                  . '</form>',
      ]);
    }
  },
]); ?>

<?php
/* ═══ نموذج مصروف — إضافة أو تعديل ═══
   ⚠️ نموذج واحد للوضعين عن قصد: نسخة تانية للتعديل معناها أول حقل جديد
      يتضاف في واحد وينسى في التاني فيضيع **بلا أي خطأ ظاهر**. */
$expOld = $expState['old'] ?: ($editExp ? [
  'category' => $editExp['category'], 'amount' => $editExp['amount'],
  'exp_date' => $editExp['exp_date'], 'vendor' => $editExp['vendor'],
  'ref_no'   => $editExp['ref_no'],   'note'   => $editExp['note'],
] : []);
$expAction = $editExp ? url('cars/expense/' . (int) $editExp['id'] . '/update')
                      : url('cars/' . $cid . '/expense');
?>
<div class="card<?= $editExp ? ' editing' : '' ?>" id="expense-form">
  <div class="section-head">
    <?= $editExp ? icon('edit', 17) . ' عدّل المصروف' : icon('plus', 17) . ' سجّل مصروف على السيارة' ?>
  </div>
  <?= formErrorSummary($expState['errors']) ?>
  <form method="post" action="<?= e($expAction) ?>" class="js-dirty-form">
    <?= csrfField() ?>
    <?= formStateFields($expState['token'], $expState['back']) ?>
    <input type="hidden" name="p" value="<?= e($period['kind']) ?>">
    <?php if (($_GET['d'] ?? '') !== ''): ?>
      <input type="hidden" name="d" value="<?= e((string) $_GET['d']) ?>">
    <?php endif; ?>
    <div class="form-grid">
      <div class="field">
        <label for="ce-cat">نوع المصروف *</label>
        <select id="ce-cat" name="category" required<?= fieldErrorAttrs('category', $expState['errors']) ?>>
          <?php foreach (OfficeCar::EXPENSE_KINDS as $k => $lbl): ?>
            <option value="<?= e($k) ?>"<?= ($expOld['category'] ?? 'fuel') === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
        <?= fieldError('category', $expState['errors']) ?>
      </div>
      <div class="field">
        <label for="ce-amt">المبلغ *</label>
        <input data-money id="ce-amt" type="number" step="0.01" min="0" name="amount" required
               value="<?= e($expOld['amount'] ?? '') ?>"<?= fieldErrorAttrs('amount', $expState['errors']) ?>>
        <?= fieldError('amount', $expState['errors']) ?>
      </div>
      <div class="field">
        <label for="ce-date">التاريخ</label>
        <input id="ce-date" type="date" name="exp_date" value="<?= e($expOld['exp_date'] ?? work_date()) ?>">
      </div>
      <div class="field">
        <label for="ce-vendor">المحطة / الورشة</label>
        <input id="ce-vendor" type="text" name="vendor" value="<?= e($expOld['vendor'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="ce-ref">رقم الإيصال</label>
        <input id="ce-ref" type="text" name="ref_no" value="<?= e($expOld['ref_no'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="ce-note">بيان</label>
        <input id="ce-note" type="text" name="note" value="<?= e($expOld['note'] ?? '') ?>"
               placeholder="مثال: تغيير طلمبة سولار">
      </div>
    </div>
    <div class="form-actions">
      <?php if ($editExp): ?>
        <button class="btn gold" type="submit" data-primary-submit><?= icon('check', 15) ?> احفظ التعديل</button>
        <a class="btn ghost" href="<?= e(url('cars/' . $cid . $qs . '#expenses-panel')) ?>">إلغاء</a>
      <?php else: ?>
        <button class="btn gold" type="submit" data-primary-submit><?= icon('plus', 15) ?> سجّل المصروف</button>
      <?php endif; ?>
    </div>
  </form>
</div>

<style>
.car-hero{display:flex;gap:16px;align-items:flex-start;background:var(--card);border:1px solid var(--line);
  border-radius:var(--radius-3);padding:18px;margin-bottom:16px;flex-wrap:wrap;
  border-inline-start:4px solid var(--navy)}
.car-hero.off{border-inline-start-color:var(--grey);opacity:.88}
.car-hero-ic{width:56px;height:56px;border-radius:var(--radius-2);flex:none;display:flex;align-items:center;
  justify-content:center;background:var(--navy);color:var(--white)}
.car-hero-id{flex:1;min-width:200px}
.car-hero-id h1{margin:0 0 6px;font-size:var(--fs-5);color:var(--ink)}
.car-hero-facts{display:flex;gap:14px;flex-wrap:wrap;color:var(--grey);font-size:var(--fs-2);align-items:center}
.car-hero-facts span{display:inline-flex;align-items:center;gap:5px}
.car-hero-alias,.car-hero-note{margin-top:7px;font-size:var(--fs-1);color:var(--grey);
  display:flex;align-items:center;gap:5px}
.car-hero-life{text-align:center;padding:9px 15px;border-radius:var(--radius-2);background:var(--bg);min-width:170px}
.car-hero-life em{display:block;font-style:normal;font-size:var(--fs-1);color:var(--grey)}
.car-hero-life b{display:block;font-size:var(--fs-2);color:var(--ink);margin:3px 0}
.car-hero-life span{font-size:var(--fs-1);color:var(--grey)}
.car-hero-acts{display:flex;gap:7px;flex-wrap:wrap;align-items:flex-start}

.car-eq{display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--card);border:1px solid var(--line);
  border-radius:var(--radius-3);padding:13px 16px;margin-bottom:10px}
.car-eq-t{display:inline-flex;align-items:center;gap:6px;font-weight:700;color:var(--ink);font-size:var(--fs-2)}
.car-eq-i{display:flex;flex-direction:column;gap:1px}
.car-eq-i em{font-style:normal;font-size:var(--fs-1);color:var(--grey)}
.car-eq-i b{font-size:var(--fs-3);color:var(--ink)}
.car-eq-i.res b{color:var(--green-fg)}
.car-eq-i.res.neg b{color:var(--red-fg)}
.car-eq-op{font-size:var(--fs-4);color:var(--grey);font-weight:700}

.car-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-bottom:16px}
.car-cats{display:flex;gap:8px;flex-wrap:wrap;padding:11px 13px;border-bottom:1px solid var(--line)}
.car-cat{display:inline-flex;align-items:center;gap:5px;background:var(--bg);border:1px solid var(--line);
  border-radius:999px;padding:5px 11px;font-size:var(--fs-1);color:var(--ink)}
.car-cat em{font-style:normal;color:var(--grey)}

@media (max-width:900px){ .car-grid{grid-template-columns:1fr} }
@media (max-width:640px){
  .car-hero-life,.car-hero-acts{width:100%}
  .car-hero-acts .btn{flex:1;justify-content:center}
}
</style>
