<?php
/** @var array $owners @var string $q */
?>
<?php partial('owner_tabs', ['active' => 'statements']); ?>
<div class="page-head">
  <div><h1 ><?= icon('layers', 14) ?> إصدار كشف حساب — اختر صاحب البيان</h1></div>
  <a class="btn ghost" href="<?= url('statements') ?>">‹ سجل الكشوف</a>
</div>

<form class="card mb" method="get" action="<?= url('statements/new') ?>" class="p5-pad p5-row end">
  <div><label class="flt-l">بحث عن صاحب البيان</label>
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="اسم صاحب البيان" autofocus
           class="p5-fin w320"></div>
  <button class="btn" type="submit"><?= icon('search', 14) ?> بحث</button>
  <?php if($q): ?><a class="btn ghost" href="<?= url('statements/new') ?>">مسح</a><?php endif; ?>
</form>

<div class="card" >
  <?php if (!$owners): ?>
    <div class="empty p5-pad-xl"><div class="ic"><?= icon('user', 14) ?></div>لا يوجد أصحاب بيان مطابقون.</div>
  <?php else: ?>
    <div class="table-wrap"><table class="data">
      <thead><tr>
        <th class="g-identity">صاحب البيان</th><th class="g-vehicle">إجمالي البوالص</th>
        <th class="g-vehicle">استلم عليها</th><th class="g-vehicle">باقية</th>
        <th class="g-revenue">إجمالي المستلَم</th><th class="g-result">إجمالي الباقي له</th>
        <th class="g-identity">آخر بوليصة</th><th class="g-identity"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($owners as $o): $ow = $o['owner']; ?>
        <tr>
          <td class="dbt-tot"><a href="<?= url('settlements?owner=' . urlencode($ow)) ?>"><?= e($ow) ?></a></td>
          <td><?= intf($o['bills']) ?></td>
          <td><?= intf($o['paid_bills']) ?></td>
          <td><?= intf($o['due_bills']) ?></td>
          <td class="pos"><?= money($o['paid'],false) ?></td>
          <td class="dbt-tot <?= (float)$o['remaining']>0.005?'neg':'' ?>"><?= money($o['remaining'],false) ?></td>
          <td><?= dfmt($o['last_date']) ?></td>
          <td><a class="btn gold sm" href="<?= url('statements/form?owner=' . urlencode($ow)) ?>"><?= icon('layers', 14) ?> إصدار</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>

<style>
  .page-head{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:16px;flex-wrap:wrap}
  .flt-l{display:block;font-size:var(--fs-1);color:var(--grey);font-weight:500;margin-bottom:3px}
</style>
