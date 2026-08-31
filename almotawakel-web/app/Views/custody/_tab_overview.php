<?php
/**
 * تبويب «نظرة عامة» — سؤال واحد: فيه كام في الدرج، وراح فين؟
 *
 * ⚠️⚠️ **التبويب ده بيستعمل الأرقام المجمّعة الرخيصة بس + حركة النهارده.**
 *    سجل الفترة الكامل في تبويب «الحركات». مقيس على الإنتاج: الشهر ٠.٦٧ ثانية
 *    والمجمّعات ٠.٠٩ — تحميل الشهر هنا كان هيبطّئ الشاشة الافتتاحية بلا داعي.
 *
 * ⚠️ كل رقم **زرار** بيفتح بنوده — «ممنوع رقم أصمّ». والأربعة بيفتحوا **نوافذ
 *    مختلفة** (الشاشة القديمة كانت بتوجّه أرقامها الأربعة لنفس النافذة بالحرف).
 *
 * متغيّرات: $breakdown $wallet $aggregate $rate $move (النهارده) $series
 *           $lastClosed $day $cd $tabUrl
 */
$bal = (float) $breakdown['balance'];
?>

<?php /* ═══ ① الرصيد — الرقم هو البطل ═══ */ ?>
<section class="cs-card hero">
  <div class="cs-card-main">
    <button type="button" class="cs-big" data-drill="<?= e($cd('balance')) ?>"
            data-drill-title="من إيه اتكوّن الرصيد">
      <?php /* ⚠️ التسمية بتتغيّر مع **النموذج الفعّال**: في النموذج المجمّع الرقم
               ده = الدرج + جيوب المناديب، فكلمة «متاح» عليه كذب. */ ?>
      <span class="lbl"><?= icon('briefcase', 14) ?>
        <?= $aggregate ? 'رصيد الخزنة (شامل جيوب المناديب)' : 'رصيد خزنة المكتب' ?></span>
      <span class="num<?= $bal < 0 ? ' neg' : '' ?>">
        <?= money0($bal, false) ?>
        <em><?= e(App\Core\App::config('currency', 'ج.م')) ?></em>
      </span>
    </button>

    <div class="cs-kpis">
      <?php /* ⚠️⚠️ **الرقمان دول مختلفان فعلاً**: «في الدرج» هو اللي المالك
               يقدر يعدّه بإيده، و«مع المناديب» فلوس بره الدرج. عرض المجمّع
               باسم «المتاح» هو بالظبط البيان المضلِّل. */ ?>
      <button type="button" class="cs-kpi" data-drill="<?= e($cd('drawer')) ?>"
              data-drill-title="الفعلي في درج المكتب">
        <span class="k">في الدرج</span>
        <b class="<?= $wallet['drawer'] < 0 ? 'neg' : '' ?>"><?= money0($wallet['drawer'], false) ?></b>
        <em>ينعدّ بالإيد</em>
      </button>

      <button type="button" class="cs-kpi" data-drill="<?= e($cd('reps')) ?>"
              data-drill-title="اللي في جيوب المناديب">
        <span class="k">مع المناديب</span>
        <b><?= money0($wallet['reps_total'], false) ?></b>
        <em><?= intf(count($wallet['reps'])) ?> مندوب</em>
      </button>

      <button type="button" class="cs-kpi" data-drill="<?= e($cd('topups')) ?>"
              data-drill-title="إضافات العهدة">
        <span class="k">إضافات<?= $breakdown['anchorDate'] ? ' بعد الإقفال' : '' ?></span>
        <b class="pos">+<?= money0($breakdown['topups'], false) ?></b>
        <em>دخلت الخزنة</em>
      </button>

      <button type="button" class="cs-kpi" data-drill="<?= e($cd('expenses')) ?>"
              data-drill-title="المصروفات">
        <span class="k">مصروفات</span>
        <b class="<?= $breakdown['expenses'] > 0 ? 'neg' : '' ?>"><?= money0($breakdown['expenses'], false) ?></b>
        <em>خرجت من الخزنة</em>
      </button>

      <button type="button" class="cs-kpi" data-drill="<?= e($cd('driver')) ?>"
              data-drill-title="عهد السائقين">
        <span class="k">عهد سائقين</span>
        <b class="<?= $breakdown['driver'] > 0 ? 'neg' : '' ?>"><?= money0($breakdown['driver'], false) ?></b>
        <em>بعد خصم <?= intf($rate) ?></em>
      </button>
    </div>
  </div>
</section>

<?php /* ═══ ② اتجاه الرصيد — أعمدة الأيام المتقفلة ═══
         ⚠️ دي **آخر الأيام المتقفلة** لا آخر ١٤ يوم بالتقويم (اليوم المفتوح
            مالوش صف مثبَّت)، والشاشة بتقول كده صراحةً في العنوان.
         ⚠️ الرسم بيتخفى لو فيه رصيد سالب — `mini_bars` بيقيس بالنسبة لأكبر
            قيمة، والسالب بيدّي عمودًا مقلوبًا يكدب. */ ?>
<?php
$bars = [];
$anyNeg = false;
foreach (($series ?? []) as $p) {
    if ((float) $p['v'] < 0) { $anyNeg = true; }
    $bars[] = [
        'label'   => (int) substr((string) $p['d'], 8, 2),
        'value'   => max((float) $p['v'], 0),
        'display' => money0((float) $p['v'], false),
        'url'     => url('custody') . '?tab=moves&from=' . $p['d'] . '&to=' . $p['d'],
        'title'   => dfmt((string) $p['d']) . ' — رصيد آخر اليوم ' . money((float) $p['v'], false),
    ];
}
?>
<?php if ($bars && !$anyNeg): ?>
  <?php partial('panel', [
    'title' => 'رصيد آخر الأيام المتقفلة',
    'icon'  => 'bar-chart',
    'class' => 'mb',
    'body'  => function () use ($bars) { partial('mini_bars', ['bars' => $bars, 'tone' => 'gold']); },
  ]); ?>
<?php endif; ?>

<?php /* ═══ ③ حركة النهارده ═══ */ ?>
<?php partial('panel', [
  'title'  => 'حركة النهارده',
  'icon'   => 'layers',
  'badge'  => $move['rows_total'] ? ['text' => intf($move['rows_total']), 'tone' => 'navy'] : null,
  'action' => ['label' => 'كل الحركات', 'url' => url('custody') . '?tab=moves'],
  'body'   => function () use ($move) {
    if (!$move['rows']) {
      partial('empty', ['emoji' => '📭', 'title' => 'مفيش حركة النهارده']);
      return;
    }
    foreach ($move['rows'] as $r) {
      $meta = [];
      if ($r['ref'])   { $meta[] = ['icon' => 'file-text', 'text' => $r['ref']]; }
      if ($r['party']) { $meta[] = ['icon' => 'user', 'text' => $r['party']]; }
      $meta[] = ['icon' => 'briefcase', 'text' => 'الرصيد الجاري ' . money($r['running'], false)];
      partial('row_item', [
        'title'  => $r['title'],
        'icon'   => $r['icon'],
        'url'    => $r['link'] ? url($r['link']) : null,
        'meta'   => $meta,
        'amount' => ($r['signed'] >= 0 ? '+ ' : '- ') . money(abs($r['signed']), false),
        'amount_tone' => $r['signed'] >= 0 ? 'pos' : 'ex',
      ]);
    }
  },
]); ?>
