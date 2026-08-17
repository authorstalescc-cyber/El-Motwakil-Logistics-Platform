<?php
/**
 * قائمة أسعار النولون المعتمدة (v102) — عرض وتعديل.
 *
 * ⚠️ الكلاس `data` (العرض الكامل) مش `table` — `.table` مالوش CSS وبيقع على
 *    عرض المحتوى بالسكوت (مصيدة اتصادفت في v101).
 *
 * متغيّرات: $port $service $rows $ports $units $ready
 */
$rows    = $rows    ?? [];
$ports   = $ports   ?? [];
$units   = $units   ?? [];
$port    = $port    ?? 'alex';
$service = $service ?? '';

$svcWord = ['container' => 'حاويات', 'reefer' => 'ثلاجات', 'lcl' => 'بضائع مجمّعة (LCL)'];
$dirWord = ['import' => 'وارد', 'export' => 'صادر', 'any' => 'وارد وصادر'];

/* تجميع: خدمة ← اتجاه ← جهة، والوحدات أعمدة — عشان الجدول يقرا زي عرض الأسعار
   نفسه اللي المالك متعوّد عليه، لا سطر لكل سعر. */
$groups = [];
foreach ($rows as $r) {
    $key = $r['service'] . '|' . $r['direction'];
    $groups[$key]['service']   = (string) $r['service'];
    $groups[$key]['direction'] = (string) $r['direction'];
    $groups[$key]['zones'][(string) $r['zone_label']][(string) $r['unit']] = $r;
    $groups[$key]['units'][(string) $r['unit']] = true;
}
?>
<div class="page-head">
  <div class="page-head-txt"><h1><?= icon('banknote', 22) ?> قائمة أسعار النولون</h1></div>
</div>

<div class="trf-tabs">
  <?php foreach ($ports as $key => $label): ?>
    <a class="rm-tab<?= $key === $port ? ' on' : '' ?>"
       href="<?= url('tariffs?port=' . rawurlencode($key)) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$rows): ?>
  <?php partial('empty', ['emoji' => '💰', 'title' => 'مفيش أسعار متسجّلة للميناء ده']); ?>
<?php else: ?>
<form method="post" action="<?= url('tariffs/save') ?>" class="js-dirty-form">
  <?= csrfField() ?>
  <input type="hidden" name="port" value="<?= e($port) ?>">

  <?php foreach ($groups as $g):
      $unitKeys = array_keys($g['units']); ?>
    <section class="card rm-sec">
      <div class="rm-head"><?= icon('package', 16) ?>
        <h2><?= e($svcWord[$g['service']] ?? $g['service']) ?> — <?= e($dirWord[$g['direction']] ?? '') ?></h2>
        <span class="rm-count"><?= intf(count($g['zones'])) ?> جهة</span>
      </div>
      <div class="table-wrap">
        <table class="data trf-table">
          <thead><tr>
            <th class="trf-zone">الجهة</th>
            <?php foreach ($unitKeys as $u): ?><th><?= e($units[$u] ?? $u) ?></th><?php endforeach; ?>
          </tr></thead>
          <tbody>
          <?php foreach ($g['zones'] as $zone => $cells): ?>
            <tr>
              <td class="trf-zone"><?= e($zone) ?></td>
              <?php foreach ($unitKeys as $u): $c = $cells[$u] ?? null; ?>
                <td>
                  <?php if ($c === null): ?>
                    <span class="trf-na">—</span>
                  <?php else: ?>
                    <input type="hidden" name="id[]" value="<?= (int) $c['id'] ?>">
                    <input data-money type="number" step="0.01" class="trf-in" name="price[]"
                           value="<?= e(number_format((float) $c['price'], 2, '.', '')) ?>"
                           aria-label="سعر <?= e($zone) ?> — <?= e($units[$u] ?? $u) ?>">
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endforeach; ?>

  <div class="form-actions">
    <button type="submit" class="btn primary"><?= icon('check', 15) ?> احفظ الأسعار</button>
  </div>
</form>
<?php endif; ?>
