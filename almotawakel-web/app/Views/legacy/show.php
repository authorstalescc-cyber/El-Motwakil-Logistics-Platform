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
  <span style="flex:1"></span>
  <span class="pill" style="background:var(--gold-light);color:var(--navy);font-size:.9rem;padding:6px 16px">
    <?= icon('inbox',15) ?> أرشيف قديم — للقراءة فقط
  </span>
</div>

<div class="flash" style="background:var(--gold-light);border-color:var(--gold);color:var(--navy)">
  <?= icon('alert',18) ?>
  بوليصة تاريخية مستوردة من النظام القديم (صف Excel رقم <?= intf($bill['src_row']) ?>).
  <span style="color:var(--grey)">لا تدخل في أي حساب من حسابات البرنامج الحالي (العهدة/التقفيل/المديونيات/التقارير).</span>
</div>

<div class="card mb" style="padding:0;overflow:hidden">
  <?php foreach ($groups as $gk => $gLabel): if (empty($byGroup[$gk])) continue; ?>
    <div class="form-group-title gt-<?= $gk ?>"><?= e($gLabel) ?></div>
    <div class="grid-auto g4" style="gap:0">
      <?php foreach ($byGroup[$gk] as [$key, $label, $type]): ?>
        <div style="padding:11px 16px;border-bottom:1px solid var(--line);border-inline-start:1px solid var(--line)">
          <div style="font-size:.76rem;color:var(--grey);font-weight:700;margin-bottom:3px"><?= e($label) ?></div>
          <div style="font-weight:700;<?= $type==='money'?'color:var(--navy)':'' ?>"><?= $fmt($type, $key==='classification' ? container_norm($bill[$key] ?? '') : ($bill[$key] ?? '')) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>
