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
<?php /* ⚠️ الاستعلام سقفه `LIMIT 300` بينما العدّاد فوق بيعرض الإجمالي الحقيقي —
         يعني الشاشة كانت بتقول «١٢٤٠ حركة» وترسم ٣٠٠ **بلا أي إشارة**، فالمستخدم
         يفتكر إنه شايف كل حاجة ويبني عليها قرار. (المرحلة ٨ج) */ ?>
<?php partial('count_note', ['shown' => count($rows), 'total' => (int) $total, 'unit' => 'حركة']); ?>
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
        <?php /* ⚠️ زر التصرّف بيوصّل **للمكان اللي بتتعمل فيه الحركة فعلاً**
                 (جوّه الإذن) — الشاشة دي عرض بس. الحالة كانت بتقول «اضغط
                 حركة جديدة» والزرار ده **مش موجود في الصفحة أصلاً**، و
                 `verify_ux` بيحرس إنه ما يرجعش. */ ?>
        <tr><td colspan="13"><?php partial('empty', ['icon' => 'inbox', 'title' => 'مفيش حركات',
              'action' => ['label' => 'افتح الأذونات', 'url' => url('permits'), 'class' => 'btn sm']]); ?></td></tr>
      <?php else: $i=0; foreach($rows as $r): $i++; ?>
        <tr>
          <td><?= $i ?></td>
          <td><?= dfmt($r['movement_date']) ?></td>
          <td class="mv-ref"><?= e($r['bill_number'] ?: '—') ?></td>
          <td class="txt"><?= e($r['company'] ?: '') ?></td>
          <td><?= e($r['driver']) ?></td>
          <td><?= e($r['car_owner']) ?></td>
          <td><?= e($r['shipping_co']) ?></td>
          <td><?= e($r['agency']) ?></td>
          <td><?= e($r['port']) ?></td>
          <td class="neg"><?= money($r['total_expense'],false) ?></td>
          <td class="pos"><?= money($r['total_receipt'],false) ?></td>
          <td><?= money($r['total_discount'],false) ?></td>
          <td><div class="actions">
            <a class="edit" href="<?= url('movements/'.$r['id'].'/edit') ?>" title="تعديل"><?= icon('edit', 14) ?></a>
            <form class="confirm-delete" method="post" action="<?= url('movements/'.$r['id'].'/delete') ?>">
              <?= csrfField() ?><button class="del" type="submit" title="حذف"><?= icon('trash', 14) ?></button>
            </form>
          </div></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div></div>
