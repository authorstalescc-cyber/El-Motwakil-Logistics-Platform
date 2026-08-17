<?php
/**
 * شاشة «اللي النظام اتعلّمه» عن الإيصالات (v101).
 *
 * ⚠️ **الكلاس `data` مش `table`.** `.table` مالوش أي CSS في `app.css`، فالجدول
 *    كان بيقع على عرض المحتوى **بالسكوت** ويسيب نص الشاشة فاضي — نفس المصيدة
 *    الموثّقة في CLAUDE.md («أي كلاس بيتطبع لازم يكون له CSS»).
 *
 * ⚠️ **صفر جملة متكرّرة.** «عدد واحد للإذن مهما كانت السيارات» كانت بتتكتب في
 *    كل سطر (١٧ مرة) فبقت ضوضاء بتغطّي على الأرقام. بقت **أيقونة + كلمتين**
 *    والشرح في `title` (قانون التصميم: الأيقونة بدل الكلمة المتكررة).
 *
 * ⚠️ **عمود «النوع/الإذن» اتشال** واتحوّل لقسمين — كان بيكرّر «إذن (صادر)»
 *    ١٧ مرة ورا بعض عشان يقول حاجة العنوان يقولها مرة واحدة.
 *
 * متغيّرات: $fresh $runs $scope $scopes $rules $baseRules $pairs $catalog
 */
$fresh   = $fresh  ?? ['trained' => false];
$runs    = $runs   ?? [];
$rules   = $rules  ?? [];
$pairs   = $pairs  ?? [];
$catalog = $catalog ?? [];
$scopes  = $scopes ?? [];
$scope   = $scope  ?? 'type';
$baseRules = $baseRules ?? [];
$run     = $fresh['run'] ?? null;

$pct = static fn ($v): string => $v === null ? '—' : number_format((float) $v * 100, 0) . '٪';

/** الأساس: أيقونة + كلمتين — الشرح في `title` بدل ما يتكرّر في كل سطر. */
$basis = static function (array $c): string {
    $rate = (float) $c['qty_rate'];
    $n    = rtrim(rtrim(number_format($rate, 2), '0'), '.');
    /* ⚠️ **«الأساس مضبوط» بقى وسمًا مش عمودًا.** عمود نسبة تانية جنب نسبة
       الاحتمال كان بيتلغبط بيها، والمعلومة الحاكمة منه سؤال بنعم/لا:
       النظام واثق في العدد ولا لأ. الشرح الكامل في `title`. */
    $weak = (float) $c['qty_fit'] < 0.70 || (int) $c['n_hits'] < 3;
    $cls  = $weak ? ' weak' : '';
    $tip  = $weak ? ' — بيتسجّل بأشكال مختلفة فالعدد مش مؤكّد' : '';
    return match ((string) $c['qty_basis']) {
        'per_car'       => '<span class="rm-basis' . $cls . '" title="إيصال لكل سيارة في الإذن' . $tip . '">'
                         . icon('truck', 13) . ' لكل سيارة' . ($rate != 1 ? ' ×' . $n : '') . '</span>',
        'per_container' => '<span class="rm-basis' . $cls . '" title="إيصال لكل حاوية في الإذن' . $tip . '">'
                         . icon('containers', 13) . ' لكل حاوية' . ($rate != 1 ? ' ×' . $n : '') . '</span>',
        default         => '<span class="rm-basis fix' . $cls . '" title="عدد واحد للإذن كله مهما كانت السيارات' . $tip . '">'
                         . icon('clipboard', 13) . ' ثابت' . ($rate > 1 ? ' ×' . $n : '') . '</span>',
    };
};

/**
 * شريط الاحتمال — الرقم هو البطل والبار بيخلّي العين تمسح العمود بنظرة.
 * (`style="width:X%"` هو نمط البارات القايم في النظام: `oc-bar` · `set-bar`
 *  · `pj-prog-bar` — العرض بيانات مش تنسيق.)
 */
$bar = static function ($v): string {
    $p = (float) $v;
    $w = max(2, min(100, (int) round($p * 100)));
    $tone = $p >= 0.75 ? ' hi' : ($p >= 0.45 ? ' mid' : '');
    return '<span class="rm-prob' . $tone . '"><b>' . e(number_format($p * 100, 0) . '٪') . '</b>'
         . '<i><span style="width:' . $w . '%"></span></i></span>';
};
?>
<div class="page-head">
  <div class="page-head-txt">
    <h1><?= icon('cpu', 22) ?> اللي النظام اتعلّمه عن الإيصالات</h1>
  </div>
  <div class="page-head-actions">
    <form method="post" action="<?= url('receipt-model/train') ?>" class="rm-train">
      <?= csrfField() ?>
      <button type="submit" class="btn primary"><?= icon('zap', 15) ?> درّب دلوقتي</button>
    </form>
  </div>
</div>

<?php if (!$fresh['trained']): ?>
  <?php partial('empty', ['emoji' => '🧠', 'title' => 'الموديل لسه ما اتدرّبش']); ?>
<?php else: ?>

<?php /* ═══ الأرقام الحاكمة — الدقة الأول لأنها هي اللي بتحدّد تثق فيه قد إيه ═══ */ ?>
<div class="rm-stats">
  <div class="rm-tile big"><b><?= e($pct($run['prec_score'])) ?></b><i>لما يقول «ناقص» بيطلع صح</i></div>
  <div class="rm-tile"><b><?= e($pct($run['rec_score'])) ?></b><i>بيمسك من الناقص الحقيقي</i></div>
  <div class="rm-tile"><b><?= e($pct($run['qty_exact'])) ?></b><i>العدد مظبوط بالحرف</i></div>
  <div class="rm-tile sep"><b><?= intf((int) $run['n_permits']) ?></b><i>إذن مقفول اتدرّب عليه</i></div>
  <div class="rm-tile"><b><?= intf((int) $run['n_receipts']) ?></b><i>إيصال اتقرا</i></div>
  <div class="rm-tile"><b><?= intf((int) $run['n_kinds']) ?></b><i>نوع إيصال</i></div>
  <div class="rm-tile"><b><?= intf((int) $run['n_cells']) ?></b><i>قاعدة اتولّدت</i></div>
</div>

<div class="rm-meta">
  <span><?= icon('clock', 13) ?> آخر تدريب <?= dtfmt($run['ran_at']) ?><?= $run['source'] === 'auto' ? ' · تلقائي' : '' ?></span>
  <?php if (!empty($fresh['since'])): ?>
    <span class="rm-stale"><?= icon('alert', 13) ?> <?= intf((int) $fresh['since']) ?> إذن اتقفل بعد كده</span>
  <?php endif; ?>
</div>

<?php /* ═══ إجابة سؤال المالك: كل نوع بيظهر في كام إذن، وبكام ═══ */ ?>
<?php
  $groups = ['sader' => ['label' => 'الأذون (صادر)', 'rows' => []],
             'warid' => ['label' => 'الشهادات (وارد)', 'rows' => []]];
  foreach ($baseRules as $c) {
      if ((int) $c['n_hits'] < 3) { continue; }
      $g = ((string) $c['scope_key'] === 'warid') ? 'warid' : 'sader';
      $groups[$g]['rows'][] = $c;
  }
?>
<?php foreach ($groups as $g): if (!$g['rows']) { continue; } ?>
<section class="card rm-sec">
  <div class="rm-head"><?= icon('receipt', 16) ?> <h2><?= e($g['label']) ?></h2>
    <span class="rm-count"><?= intf(count($g['rows'])) ?> نوع</span>
  </div>
  <div class="table-wrap">
    <table class="data rm-table">
      <thead><tr>
        <th class="rm-c-kind">النوع</th>
        <th>بيظهر في</th>
        <th>من كام إذن</th>
        <th>العدد</th>
        <th title="فرق الأيام بين تاريخ البند وتاريخ الإذن">بعد كام يوم</th>
        <th>المبلغ المعتاد</th>
      </tr></thead>
      <tbody>
      <?php foreach ($g['rows'] as $c): ?>
        <tr>
          <td class="rm-c-kind"><?= e((string) $c['kind']) ?></td>
          <td><?= $bar($c['prob']) ?></td>
          <td class="rm-of"><?= intf((int) $c['n_hits']) ?> <span>من</span> <?= intf((int) $c['n_permits']) ?></td>
          <td><?= $basis($c) ?></td>
          <td class="rm-day"><?= $c['day_offset'] === null ? '—'
              : ((int) $c['day_offset'] === 0 ? 'نفس اليوم' : e(intf((int) $c['day_offset']))) ?></td>
          <td class="rm-amt"><?= $c['amount_med'] === null ? '—' : money((float) $c['amount_med']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endforeach; ?>

<?php if ($pairs): ?>
<section class="card rm-sec">
  <div class="rm-head"><?= icon('shuffle', 16) ?> <h2>الاقتران</h2>
    <span class="rm-count"><?= intf(count($pairs)) ?> قاعدة</span>
  </div>
  <div class="rm-pairs">
    <?php foreach ($pairs as $pr): ?>
      <div class="rm-pair">
        <span class="rm-pair-a"><?= e((string) $pr['kind_a']) ?></span>
        <span class="rm-pair-ar"><?= icon('chevron', 15) ?></span>
        <span class="rm-pair-b"><?= e((string) $pr['kind_b']) ?></span>
        <b><?= e($pct($pr['p_ba'])) ?></b>
        <span class="rm-sub"><?= intf((int) $pr['n_both']) ?> من <?= intf((int) $pr['n_a']) ?>
          · <?= $pr['permit_type'] === 'warid' ? 'شهادة' : 'إذن' ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="card rm-sec">
  <div class="rm-head"><?= icon('layers', 16) ?> <h2>القواعد حسب السياق</h2>
    <div class="rm-tabs">
      <?php foreach ($scopes as $key => $meta): ?>
        <a class="rm-tab<?= $key === $scope ? ' on' : '' ?>"
           href="<?= url('receipt-model?scope=' . rawurlencode($key)) ?>"><?= e($meta['label']) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php if (!$rules): ?>
    <?php partial('empty', ['emoji' => '📭', 'title' => 'مفيش دليل كافي في السياق ده']); ?>
  <?php else: ?>
  <div class="table-wrap">
    <table class="data rm-table">
      <thead><tr>
        <th class="rm-c-ctx">السياق</th><th class="rm-c-kind">النوع</th>
        <th>بيظهر في</th><th>من كام إذن</th><th>العدد</th><th title="فرق الأيام بين تاريخ البند وتاريخ الإذن">بعد كام يوم</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rules as $c): ?>
        <tr>
          <td class="rm-c-ctx"><?= e((string) $c['scope_key']) ?></td>
          <td class="rm-c-kind"><?= e((string) $c['kind']) ?></td>
          <td><?= $bar($c['prob']) ?></td>
          <td class="rm-of"><?= intf((int) $c['n_hits']) ?> <span>من</span> <?= intf((int) $c['n_permits']) ?></td>
          <td><?= $basis($c) ?></td>
          <td class="rm-day"><?= $c['day_offset'] === null ? '—'
              : ((int) $c['day_offset'] === 0 ? 'نفس اليوم' : e(intf((int) $c['day_offset']))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<section class="card rm-sec">
  <div class="rm-head"><?= icon('tag', 16) ?> <h2>قاموس الأنواع</h2>
    <span class="rm-count"><?= intf(count($catalog)) ?> نوع</span>
  </div>
  <div class="rm-kinds">
    <?php foreach ($catalog as $k): ?>
      <span class="rm-kchip<?= $k['origin'] === 'learned' ? ' learned' : '' ?>"
            title="<?= e($k['match'] . ($k['except'] ? ' — ما عدا: ' . $k['except'] : '')) ?>">
        <?= e((string) $k['name']) ?>
        <?php if ($k['origin'] === 'learned'): ?><?= icon('sparkles', 12) ?><?php endif; ?>
      </span>
    <?php endforeach; ?>
  </div>
</section>

<?php if (count($runs) > 1): ?>
<section class="card rm-sec">
  <div class="rm-head"><?= icon('clock', 16) ?> <h2>جولات التدريب</h2></div>
  <div class="table-wrap">
    <table class="data rm-table">
      <thead><tr><th>الوقت</th><th>المصدر</th><th>أذون</th><th>دقة</th><th>استرجاع</th><th>العدد</th></tr></thead>
      <tbody>
      <?php foreach ($runs as $r): ?>
        <tr>
          <td><?= dtfmt($r['ran_at']) ?></td>
          <td><?= $r['source'] === 'auto' ? 'تلقائي' : 'بضغطة' ?></td>
          <td><?= intf((int) $r['n_permits']) ?></td>
          <td><?= e($pct($r['prec_score'])) ?></td>
          <td><?= e($pct($r['rec_score'])) ?></td>
          <td><?= e($pct($r['qty_exact'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<?php endif; ?>
