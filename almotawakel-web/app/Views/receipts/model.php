<?php
/**
 * شاشة «اللي النظام اتعلّمه» عن الإيصالات (v101).
 *
 * ⚠️ **صفر جملة تعليمية · صفر رقم أصمّ · صفر `style=`** (قانون التصميم).
 *    كل رقم هنا معاه أساسه في نفس السطر: «٩٤٪» جنبها «٢٩ من ٣١ إذن».
 *
 * متغيّرات: $fresh $runs $scope $scopes $rules $baseRules $pairs $catalog
 */
$fresh  = $fresh  ?? ['trained' => false];
$runs   = $runs   ?? [];
$rules  = $rules  ?? [];
$pairs  = $pairs  ?? [];
$catalog = $catalog ?? [];
$baseRules = $baseRules ?? [];
$scopes = $scopes ?? [];
$scope  = $scope  ?? 'type';
$run    = $fresh['run'] ?? null;

$pct = static fn ($v): string => $v === null ? '—' : number_format((float) $v * 100, 0) . '٪';
$basisWord = [
    'fixed'         => 'واحد للإذن',
    'per_car'       => 'لكل سيارة',
    'per_container' => 'لكل حاوية',
];
$rate = static fn (array $c): string =>
    $c['qty_basis'] === 'fixed'
        ? intf((int) round((float) $c['qty_rate']))
        : '×' . rtrim(rtrim(number_format((float) $c['qty_rate'], 2), '0'), '.');
?>
<div class="page-head">
  <h1><?= icon('cpu', 22) ?> اللي النظام اتعلّمه عن الإيصالات</h1>
  <form method="post" action="<?= url('receipt-model/train') ?>" class="rm-train">
    <?= csrfField() ?>
    <button type="submit" class="btn primary"><?= icon('zap', 15) ?> درّب دلوقتي</button>
  </form>
</div>

<?php if (!$fresh['trained']): ?>
  <?php /* بلا جملة شرح — العنوان بيقول الحالة والزرار بيقول التصرّف (قانون التصميم). */ ?>
  <?php partial('empty', [
      'emoji'  => '🧠',
      'title'  => 'الموديل لسه ما اتدرّبش',
      'action' => ['label' => 'درّبه دلوقتي', 'url' => url('receipt-model'), 'class' => 'btn sm'],
  ]); ?>
<?php else: ?>

<div class="rm-stats">
  <?php
    $tiles = [
        ['n' => intf((int) $run['n_permits']),  'l' => 'إذن مقفول اتدرّب عليه'],
        ['n' => intf((int) $run['n_receipts']), 'l' => 'إيصال اتقرا'],
        ['n' => intf((int) $run['n_kinds']),    'l' => 'نوع إيصال'],
        ['n' => intf((int) $run['n_cells']),    'l' => 'قاعدة سياق'],
        ['n' => $pct($run['prec_score']),       'l' => 'دقة التوقّع'],
        ['n' => $pct($run['rec_score']),        'l' => 'بيمسك من الناقص'],
        ['n' => $pct($run['qty_exact']),        'l' => 'العدد مظبوط بالحرف'],
    ];
    foreach ($tiles as $t): ?>
    <div class="rm-tile"><b><?= e($t['n']) ?></b><i><?= e($t['l']) ?></i></div>
  <?php endforeach; ?>
</div>

<div class="rm-meta">
  <span><?= icon('clock', 13) ?> آخر تدريب <?= dtfmt($run['ran_at']) ?>
    <?= $run['source'] === 'auto' ? '(تلقائي)' : '' ?></span>
  <?php if (!empty($fresh['since'])): ?>
    <span class="rm-stale"><?= icon('alert', 13) ?>
      <?= intf((int) $fresh['since']) ?> إذن اتقفل بعد كده</span>
  <?php endif; ?>
</div>

<?php /* ═══ الإجابة على سؤال المالك المباشر: كل كام عربية بتاخد كام؟ ═══ */ ?>
<section class="card rm-sec">
  <div class="rm-head"><?= icon('truck', 16) ?> <h2>العدد المتوقّع لكل نوع</h2></div>
  <div class="table-wrap">
    <table class="table rm-table">
      <thead><tr>
        <th>النوع</th><th>النوع/الإذن</th><th>بيظهر في</th><th>الأساس</th>
        <th>العدد</th><th>الأساس مضبوط</th><th>اليوم</th><th>المبلغ المعتاد</th>
      </tr></thead>
      <tbody>
      <?php foreach ($baseRules as $c):
          if ((int) $c['n_hits'] < 3) { continue; } ?>
        <tr>
          <td class="rm-kind"><?= e((string) $c['kind']) ?></td>
          <td><?= e($c['scope_key'] === 'warid' ? 'شهادة (وارد)' : 'إذن (صادر)') ?></td>
          <td><b><?= e($pct($c['prob'])) ?></b>
            <span class="rm-sub"><?= intf((int) $c['n_hits']) ?> من <?= intf((int) $c['n_permits']) ?></span></td>
          <td><?= e($basisWord[$c['qty_basis']] ?? (string) $c['qty_basis']) ?></td>
          <td class="rm-num"><?= e($rate($c)) ?></td>
          <td><?= e($pct($c['qty_fit'])) ?></td>
          <td><?= $c['day_offset'] === null ? '—' : e(intf((int) $c['day_offset'])) ?></td>
          <td><?= $c['amount_med'] === null ? '—' : money((float) $c['amount_med']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php if ($pairs): ?>
<section class="card rm-sec">
  <div class="rm-head"><?= icon('shuffle', 16) ?> <h2>الاقتران</h2></div>
  <div class="rm-pairs">
    <?php foreach ($pairs as $pr): ?>
      <div class="rm-pair">
        <span class="rm-pair-a"><?= e((string) $pr['kind_a']) ?></span>
        <?= icon('chevron', 14) ?>
        <span class="rm-pair-b"><?= e((string) $pr['kind_b']) ?></span>
        <b><?= e($pct($pr['p_ba'])) ?></b>
        <span class="rm-sub"><?= intf((int) $pr['n_both']) ?> من <?= intf((int) $pr['n_a']) ?>
          <?= $pr['permit_type'] === 'warid' ? '· شهادة' : '· إذن' ?></span>
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
    <table class="table rm-table">
      <thead><tr><th>السياق</th><th>النوع</th><th>بيظهر في</th><th>الأساس</th><th>العدد</th><th>اليوم</th></tr></thead>
      <tbody>
      <?php foreach ($rules as $c): ?>
        <tr>
          <td class="rm-ctx"><?= e((string) $c['scope_key']) ?></td>
          <td class="rm-kind"><?= e((string) $c['kind']) ?></td>
          <td><b><?= e($pct($c['prob'])) ?></b>
            <span class="rm-sub"><?= intf((int) $c['n_hits']) ?> من <?= intf((int) $c['n_permits']) ?></span></td>
          <td><?= e($basisWord[$c['qty_basis']] ?? (string) $c['qty_basis']) ?></td>
          <td class="rm-num"><?= e($rate($c)) ?></td>
          <td><?= $c['day_offset'] === null ? '—' : e(intf((int) $c['day_offset'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<section class="card rm-sec">
  <div class="rm-head"><?= icon('tag', 16) ?> <h2>قاموس الأنواع</h2></div>
  <div class="rm-kinds">
    <?php foreach ($catalog as $k): ?>
      <span class="rm-kchip<?= $k['origin'] === 'learned' ? ' learned' : '' ?>"
            title="<?= e($k['match'] . ($k['except'] ? ' — ما عدا: ' . $k['except'] : '')) ?>">
        <?= e((string) $k['name']) ?>
        <?php if ($k['origin'] === 'learned'): ?>
          <?= icon('sparkles', 12) ?><span class="rm-sub"><?= intf((int) $k['hits']) ?></span>
        <?php endif; ?>
      </span>
    <?php endforeach; ?>
  </div>
</section>

<?php if (count($runs) > 1): ?>
<section class="card rm-sec">
  <div class="rm-head"><?= icon('clock', 16) ?> <h2>جولات التدريب</h2></div>
  <div class="table-wrap">
    <table class="table rm-table">
      <thead><tr><th>الوقت</th><th>المصدر</th><th>أذون</th><th>إيصالات</th><th>دقة</th><th>استرجاع</th><th>العدد</th><th>ثواني</th></tr></thead>
      <tbody>
      <?php foreach ($runs as $r): ?>
        <tr>
          <td><?= dtfmt($r['ran_at']) ?></td>
          <td><?= $r['source'] === 'auto' ? 'تلقائي' : 'بضغطة' ?></td>
          <td><?= intf((int) $r['n_permits']) ?></td>
          <td><?= intf((int) $r['n_receipts']) ?></td>
          <td><?= e($pct($r['prec_score'])) ?></td>
          <td><?= e($pct($r['rec_score'])) ?></td>
          <td><?= e($pct($r['qty_exact'])) ?></td>
          <td><?= e(number_format((float) $r['secs'], 1)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<?php endif; ?>
