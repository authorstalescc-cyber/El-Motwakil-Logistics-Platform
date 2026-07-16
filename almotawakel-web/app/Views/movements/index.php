<div class="toolbar">
  <form method="get" action="<?= url('movements') ?>">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="بوليصة / سائق / شركة شحن…">
    <input list="dl_driver" name="driver" value="<?= e($driver) ?>" placeholder="السائق" autocomplete="off">
    <input type="date" name="date" value="<?= e($date) ?>">
    <button class="btn sm" type="submit">تصفية</button>
    <?php if($q||$driver||$date): ?><a class="btn ghost sm" href="<?= url('movements') ?>">إلغاء</a><?php endif; ?>
  </form>
  <datalist id="dl_driver"><?php foreach ($drivers as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist>
  <div class="spacer"></div>
  <span class="count-badge"><?= intf($total) ?> حركة</span>
</div>
<div class="card"><div class="table-wrap">
  <table class="data">
    <thead><tr>
      <th class="g-identity">م</th><th class="g-identity">التاريخ</th><th class="g-identity">البوليصة</th>
      <th class="g-identity">العميل</th><th class="g-vehicle">السائق</th><th class="g-vehicle">مالك السيارة</th>
      <th class="g-identity">شركة الشحن</th><th class="g-identity">توكيل</th><th class="g-identity">الميناء</th>
      <th class="g-expense">مصروفات</th><th class="g-revenue">إيصالات</th><th class="g-result">خصومات</th>
      <th class="g-identity">إجراءات</th>
    </tr></thead>
    <tbody>
      <?php if(!$rows): ?>
        <tr><td colspan="13"><div class="empty"><div class="ic">📭</div>لا حركات — اضغط «حركة جديدة».</div></td></tr>
      <?php else: $i=0; foreach($rows as $r): $i++; ?>
        <tr>
          <td><?= $i ?></td>
          <td><?= dfmt($r['movement_date']) ?></td>
          <td style="font-weight:700"><?= e($r['bill_number'] ?: '—') ?></td>
          <td style="text-align:right"><?= e($r['company'] ?: '') ?></td>
          <td><?= e($r['driver']) ?></td>
          <td><?= e($r['car_owner']) ?></td>
          <td><?= e($r['shipping_co']) ?></td>
          <td><?= e($r['agency']) ?></td>
          <td><?= e($r['port']) ?></td>
          <td class="neg"><?= money($r['total_expense'],false) ?></td>
          <td class="pos"><?= money($r['total_receipt'],false) ?></td>
          <td><?= money($r['total_discount'],false) ?></td>
          <td><div class="actions">
            <a class="edit" href="<?= url('movements/'.$r['id'].'/edit') ?>" title="تعديل">✏️</a>
            <form class="confirm-delete" method="post" action="<?= url('movements/'.$r['id'].'/delete') ?>" style="margin:0">
              <?= csrfField() ?><button class="del" type="submit" title="حذف">🗑️</button>
            </form>
          </div></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div></div>
