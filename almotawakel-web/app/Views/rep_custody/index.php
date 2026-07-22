<div class="toolbar">
  <div class="section-head" style="margin:0">🧑‍💼 عهدة المناديب</div>
  <div class="spacer"></div>
  <form method="get" action="<?= url('rep-custody') ?>" style="display:flex;gap:6px">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="ابحث باسم المندوب…">
    <button class="btn ghost" type="submit">🔎</button>
  </form>
  <a class="btn ghost" href="<?= url('reps') ?>">سجل المناديب (المرتبات)</a>
</div>

<div class="note mb" style="font-size:.85rem">
  💡 <strong>تنزيل العهدة للمندوب لا يخصم من خزنة المكتب.</strong>
  عند اعتماد إيصال أو نثرية دفعها المندوب، يُخصم المصروف من الخزنة بتاريخ الصرف
  ومن عهدة المندوب في كشفه. أما المرتجع فيُقفل عهدة المندوب ولا يُضاف إلى الخزنة.
</div>

<div class="kpi-grid mb" style="grid-template-columns:repeat(4,1fr)">
  <div class="kpi gold">
    <div class="lbl"><?= $sum['balance'] < 0 ? 'إجمالي المستحق للمناديب' : 'الفاضل مع المناديب دلوقتي' ?></div>
    <div class="val" data-secret data-val="<?= e(intf($sum['balance'])) ?>">••••••</div>
    <div style="opacity:.8;font-size:.8rem"><?= intf(count($cards)) ?> مندوب</div>
  </div>
  <div class="kpi">
    <div class="lbl">إجمالي المنزَّل</div>
    <div class="val pos"><?= intf($sum['deposits']) ?></div>
  </div>
  <div class="kpi">
    <div class="lbl">إجمالي المصروف على الأذونات</div>
    <div class="val neg"><?= intf($sum['spent']) ?></div>
  </div>
  <div class="kpi">
    <div class="lbl">إجمالي المرتجع</div>
    <div class="val"><?= intf($sum['returned']) ?></div>
  </div>
</div>

<div class="card mb">
  <div class="section-head" style="padding:16px 18px 0;margin:0">🗂️ المناديب</div>
  <div class="table-wrap"><table class="data">
    <thead><tr>
      <th class="g-identity">المندوب</th>
      <th class="g-identity">الموبايل</th>
      <th class="g-revenue">المنزَّل</th>
      <th class="g-expense">المصروف</th>
      <th class="g-expense">المرتجع</th>
      <th class="g-result">حالة العهدة</th>
      <th class="g-identity" data-no-sort></th>
    </tr></thead>
    <tbody>
      <?php if (!$cards): ?>
        <tr><td colspan="7"><div class="empty"><div class="ic">🧑‍💼</div>
          لا مناديب بعد. أضِف مندوبًا من <a href="<?= url('reps/new') ?>">سجل المناديب</a> الأول.
        </div></td></tr>
      <?php else: foreach ($cards as $c): ?>
        <tr<?= $c['status'] === 'inactive' ? ' style="opacity:.55"' : '' ?>>
          <td style="text-align:right;font-weight:700">
            <a href="<?= url('rep-custody/' . $c['id']) ?>">🧑‍💼 <?= e($c['name']) ?></a>
            <?php if ($c['status'] === 'inactive'): ?><span class="pill st-partial">متوقف</span><?php endif; ?>
          </td>
          <td style="text-align:right"><?= e($c['phone'] ?: '—') ?></td>
          <td class="pos"><?= money($c['deposits'], false) ?></td>
          <td class="neg"><?= money($c['spent'], false) ?></td>
          <td><?= money($c['returned'], false) ?></td>
          <td style="font-weight:800" class="<?= $c['balance'] < 0 ? 'neg' : 'pos' ?>">
            <span data-secret data-val="<?= e(money(abs($c['balance']), false)) ?>">••••••</span>
            <small style="display:block"><?= $c['balance'] < 0 ? 'مستحق للمندوب' : 'مع المندوب' ?></small>
          </td>
          <td style="white-space:nowrap">
            <a class="edit" href="<?= url('rep-custody/' . $c['id']) ?>" title="الكشف والتسوية">🔎</a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>
