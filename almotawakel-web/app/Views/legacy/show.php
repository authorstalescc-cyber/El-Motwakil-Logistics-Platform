<?php
/** @var array $bill @var array $fields */
$groups = [
    'identity' => 'بيانات التعريف',
    'vehicle'  => 'السيارة',
    'revenue'  => 'الإيراد / التحصيل',
    'expense'  => 'المصروفات',
    'result'   => 'محسوبة في النظام القديم',
];
$byGroup = [];
foreach ($fields as $key => [$label, $group, $type]) {
    $byGroup[$group][] = [$key, $label, $type];
}
$fmt = function(string $type, $v) {
    if ($type === 'date')  return dfmt($v) ?: '—';
    if ($type === 'money') return money($v);
    $s = trim((string)$v);
    return $s !== '' ? e($s) : '—';
};
?>
<div class="page-actions">
  <a class="btn ghost" href="<?= url('legacy') ?>">‹ رجوع للأرشيف</a>
  <span class="ps-sp"></span>
  <span class="pill bs-pill bg-gold">
    <?= icon('inbox',15) ?> أرشيف قديم — للقراءة فقط
  </span>
</div>

<div class="flash p5-chip-on">
  <?= icon('alert',18) ?>
  بوليصة تاريخية مستوردة من النظام القديم (صف Excel رقم <?= intf($bill['src_row']) ?>).
  <span class="dbt-none">لا تدخل في أي حساب من حسابات البرنامج الحالي (العهدة/التقفيل/المديونيات/التقارير).</span>
</div>

<div class="card mb" >
  <?php foreach ($groups as $gk => $gLabel): if (empty($byGroup[$gk])) continue; ?>
    <div class="form-group-title gt-<?= $gk ?>"><?= e($gLabel) ?></div>
    <div class="grid-auto g4 g0">
      <?php foreach ($byGroup[$gk] as [$key, $label, $type]): ?>
        <div class="bs-fact">
          <div class="bs-fact-l"><?= e($label) ?></div>
          <div class="bs-fact-v<?= $type==='money'?' num':'' ?>"><?= $fmt($type, $key==='classification' ? container_norm($bill[$key] ?? '') : ($bill[$key] ?? '')) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>
