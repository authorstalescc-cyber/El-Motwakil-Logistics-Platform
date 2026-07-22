<div class="toolbar">
  <div class="section-head" style="margin:0">💳 رصيد المكتب — كروت الفيزا</div>
  <div class="spacer"></div>
  <button type="button" class="btn ghost secret-toggle">👁 إظهار الأرصدة</button>
  <a class="btn gold" href="<?= url('office/new') ?>?back=<?= urlencode(current_app_path() ?: 'office') ?>">➕ إضافة كارت</a>
</div>

<!-- بطاقات النظرة العامة -->
<div class="kpi-grid mb three">
  <div class="kpi gold">
    <div class="lbl">إجمالي الأرصدة المتاحة</div>
    <div class="val" data-secret data-val="<?= e(intf($summary['balance'])) ?>">••••••</div>
    <div style="opacity:.8;font-size:.8rem"><?= intf($summary['count']) ?> كارت</div>
  </div>
  <div class="kpi">
    <div class="lbl">إجمالي الشحنات</div>
    <div class="val pos"><?= intf($summary['deposits'] + $summary['opening']) ?></div>
  </div>
  <div class="kpi">
    <div class="lbl">إجمالي المدفوع بالفيزا</div>
    <div class="val neg"><?= intf($summary['payments']) ?></div>
  </div>
</div>

<!-- جدول الكروت -->
<div class="card mb">
  <div class="section-head" style="padding:16px 18px 0;margin:0">🗂️ الكروت</div>
  <div class="table-wrap"><table class="data">
    <thead><tr>
      <th class="g-identity">الكارت</th>
      <th class="g-identity">صاحب الكارت</th>
      <th class="g-identity">البنك</th>
      <th class="g-revenue">شحنات الرصيد</th>
      <th class="g-expense">مدفوع (فيزا)</th>
      <th class="g-result">الرصيد المتاح</th>
      <th class="g-identity"></th>
    </tr></thead>
    <tbody>
      <?php if(!$cards): ?>
        <tr><td colspan="7"><div class="empty"><div class="ic">💳</div><b>مفيش كروت فيزا</b><span>ضيف أول كارت عشان تتابع رصيده ومدفوعاته.</span><a class="btn sm" href="<?= url('office/new') ?>">➕ إضافة كارت</a></div></td></tr>
      <?php else: foreach ($cards as $c): ?>
        <tr<?= $c['status']==='inactive'?' style="opacity:.55"':'' ?>>
          <td style="text-align:right;font-weight:700">
            <a href="<?= url('office/'.$c['id']) ?>">💳 <?= e($c['name']) ?></a>
            <?php if($c['status']==='inactive'): ?><span class="pill st-partial">متوقف</span><?php endif; ?>
          </td>
          <td style="text-align:right"><?= e($c['owner'] ?: '—') ?></td>
          <td style="text-align:right"><?= e($c['bank'] ?: '—') ?></td>
          <td class="pos"><?= money($c['dep_total'] + $c['opening_balance'],false) ?></td>
          <td class="neg"><?= money($c['pay_total'],false) ?></td>
          <td style="font-weight:800" class="<?= $c['balance']<0?'neg':'pos' ?>" data-secret data-val="<?= e(money($c['balance'],false)) ?>">••••••</td>
          <td style="white-space:nowrap">
            <a class="edit" href="<?= url('office/'.$c['id']) ?>" title="التفاصيل والسجل">🔎</a>
            <a class="edit" href="<?= url('office/'.$c['id'].'/edit') ?>?back=<?= urlencode(current_app_path() ?: 'office') ?>" title="تعديل">✏️</a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<!-- الأرصدة حسب صاحب الكارت -->
<?php if($byOwner): ?>
<div class="card">
  <div class="section-head" style="padding:16px 18px 0;margin:0">👥 الأرصدة حسب صاحب الكارت</div>
  <div class="table-wrap"><table class="data">
    <thead><tr>
      <th class="g-identity">صاحب الكارت</th>
      <th class="g-identity">عدد الكروت</th>
      <th class="g-result">إجمالي الرصيد المتاح</th>
    </tr></thead>
    <tbody>
      <?php foreach ($byOwner as $o): ?>
        <tr>
          <td style="text-align:right;font-weight:700"><?= e($o['owner']) ?></td>
          <td><?= intf($o['cards']) ?></td>
          <td style="font-weight:800" class="<?= $o['balance']<0?'neg':'pos' ?>" data-secret data-val="<?= e(money($o['balance'],false)) ?>">••••••</td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<script>
(function(){
  // الأرصدة مخفية افتراضيًّا (بيانات حسّاسة) — زر واحد يُظهر/يخفي الكل
  var shown = false;
  document.querySelectorAll('[data-secret]').forEach(function(el){ el.dataset.mask = el.textContent; });
  function apply(){
    document.querySelectorAll('[data-secret]').forEach(function(el){
      el.textContent = shown ? (el.getAttribute('data-val') || '') : (el.dataset.mask || '••••••');
    });
    document.querySelectorAll('.secret-toggle').forEach(function(b){ b.textContent = shown ? '🙈 إخفاء الأرصدة' : '👁 إظهار الأرصدة'; });
  }
  document.addEventListener('click', function(e){ if (e.target.closest('.secret-toggle')) { shown = !shown; apply(); } });
})();
</script>
