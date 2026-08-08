<?php
/**
 * سداد البوالص — وضعان:
 *   mode='directory' : بطاقات أصحاب البيان (بلا بحث/فلترة).
 *   mode='search'    : البوالص المستهدفة نفسها (عند وجود بحث أو فلترة) + دفع جماعي.
 * @var string $mode
 * @var array $f
 * @var array $companies
 * @var array $destinations
 * @var array $classifs
 * @var array $ctypes
 * @var array $owners    وضع الدليل
 * @var array $tot       وضع الدليل
 * @var int   $dueOwners وضع الدليل
 * @var array $bills     وضع البحث
 * @var array $resTot    وضع البحث
 * @var string $backQS   وضع البحث
 */
$isSearch = ($mode ?? 'directory') === 'search';
?>
<?php partial('owner_tabs', ['active' => 'settlements']); ?>
<!-- شريط البحث + الفلترة الموسّعة (يعمل في الوضعين) -->
<div class="toolbar stack">
  <form method="get" action="<?= url('settlements') ?>" class="st-form">
    <div class="dir-search full">
      <span class="ic"><?= icon('search', 14) ?></span>
      <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="بحث عن بوليصة…" autofocus>
    </div>
    <div class="st-filters">
      <select name="status">
        <option value="all"     <?= in_array($f['status'],['','all'],true)?'selected':'' ?>>كل الحالات</option>
        <option value="due"     <?= $f['status']==='due'?'selected':'' ?>>غير مسدّد بالكامل</option>
        <option value="unpaid"  <?= $f['status']==='unpaid'?'selected':'' ?>>غير مدفوع</option>
        <option value="partial" <?= $f['status']==='partial'?'selected':'' ?>>مدفوع جزئياً</option>
        <option value="paid"    <?= $f['status']==='paid'?'selected':'' ?>>مدفوع</option>
        <option value="pending" <?= $f['status']==='pending'?'selected':'' ?>>بانتظار النولون</option>
      </select>
      <select name="type">
        <option value="">كل الأنواع</option>
        <option value="sader" <?= $f['type']==='sader'?'selected':'' ?>>صادر</option>
        <option value="warid" <?= $f['type']==='warid'?'selected':'' ?>>وارد</option>
      </select>
      <input list="dl_company" name="company" value="<?= e($f['company']) ?>" placeholder="الشركة / العميل" autocomplete="off">
      <input list="dl_dest" name="destination" value="<?= e($f['destination']) ?>" placeholder="الجهة" autocomplete="off">
      <input list="dl_class" name="classification" value="<?= e($f['classification']) ?>" placeholder="التصنيف" autocomplete="off">
      <input list="dl_ctype" name="container_type" value="<?= e($f['container_type']) ?>" placeholder="تصنيف الحاويات" autocomplete="off">
      <label class="st-lbl">من</label>
      <input type="date" name="from" value="<?= e($f['from']) ?>">
      <label class="st-lbl">إلى</label>
      <input type="date" name="to" value="<?= e($f['to']) ?>">
      <button class="btn sm" type="submit"><?= icon('search', 14) ?> بحث</button>
      <?php if ($isSearch): ?><a class="btn ghost sm" href="<?= url('settlements') ?>"><?= icon('x', 14) ?> مسح</a><?php endif; ?>
    </div>
  </form>
  <datalist id="dl_company"><?php foreach ($companies as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
  <datalist id="dl_dest"><?php foreach ($destinations as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
  <datalist id="dl_class"><?php foreach ($classifs as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
  <datalist id="dl_ctype"><?php foreach ($ctypes as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
</div>

<?php if ($isSearch): /* ============================= وضع نتائج البحث ============================= */ ?>

  <div class="kpi-grid mb four">
    <div class="kpi gold"><div class="lbl">عدد البوالص المطابقة</div><div class="val"><?= intf($resTot['n']) ?></div></div>
    <div class="kpi navy"><div class="lbl">إجمالي الدين</div><div class="val"><?= intf($resTot['debt']) ?><span class="cur">ج.م</span></div></div>
    <div class="kpi green"><div class="lbl">المدفوع</div><div class="val"><?= intf($resTot['paid']) ?><span class="cur">ج.م</span></div></div>
    <div class="kpi red"><div class="lbl">المتبقّي</div><div class="val"><?= intf($resTot['remaining']) ?><span class="cur">ج.م</span></div></div>
  </div>

  <div class="section-head"><h2><?= icon('search', 14) ?> البوالص المطابقة — حدّد المطلوب وادفعه دفعة واحدة</h2></div>

  <?php $showOwner = true; require __DIR__ . '/_bills.php'; ?>

  <?php if (count($bills) >= 500): ?>
    <div class="dbt-cut">عُرض أول 500 بوليصة — ضيّق البحث لعرض غيرها.</div>
  <?php endif; ?>

<?php else: /* ================================ وضع الدليل ================================ */ ?>

  <div class="settlement-view-actions">
    <a class="btn <?= empty($dueOnly)?'gold':'ghost' ?>" href="<?= url('settlements') ?>"><?= icon('users', 14) ?> عرض كل أصحاب البيان</a>
    <a class="btn <?= !empty($dueOnly)?'gold':'ghost' ?>" href="<?= url('settlements?owners=due') ?>">🔴 أصحاب البيان الذين لهم مديونية فقط</a>
    <a class="btn ghost" target="_blank" href="<?= url('statements/batch?scope=' . (!empty($dueOnly)?'due':'all') . '&autoprint=1') ?>"><?= icon('file-text', 14) ?> طباعة المعروض مجمعًا</a>
  </div>

  <div class="kpi-grid mb four">
    <a class="kpi navy" href="<?= url('settlements') ?>" title="عرض كل أصحاب البيان"><div class="lbl">عدد أصحاب البيان</div><div class="val"><?= intf($tot['owners']) ?></div></a>
    <a class="kpi gold" href="<?= url('settlements') ?>?status=due" title="عرض البوالص غير المسدّدة"><div class="lbl">لهم مديونية</div><div class="val"><?= intf($dueOwners) ?></div></a>
    <a class="kpi green" href="<?= url('settlements') ?>?status=paid" title="عرض البوالص المسدّدة"><div class="lbl">إجمالي المدفوع لهم</div><div class="val"><?= intf($tot['paid']) ?><span class="cur">ج.م</span></div></a>
    <a class="kpi red" href="<?= url('settlements') ?>?status=due" title="عرض البوالص المتبقّية"><div class="lbl">إجمالي المتبقّي لهم</div><div class="val"><?= intf($tot['remaining']) ?><span class="cur">ج.م</span></div></a>
  </div>

  <?php if (($unassigned['n'] ?? 0) > 0): ?>
    <a class="warn-box" href="<?= url('settlements') ?>?unassigned=1">
      <div class="warn-ic"><?= icon('alert', 14) ?></div>
      <div class="warn-txt">
        <b><?= intf($unassigned['n']) ?> بوليصة بلا صاحب بيان</b>
        <span>متبقٍّ <b class="neg"><?= money($unassigned['remaining'], false) ?> ج.م</b></span>
      </div>
      <div class="warn-cta">تعيين الآن ←</div>
    </a>
  <?php endif; ?>

  <div class="section-head spread">
    <h2><?= icon('user', 14) ?> أصحاب البيان</h2>
    <span class="count-badge"><?= intf(count($owners)) ?> <?= !empty($dueOnly)?'صاحب بيان له مديونية':'صاحب بيان' ?></span>
  </div>

  <?php if (!$owners): ?>
    <div class="card"><div class="empty empty-mv-in"><div class="ic"><?= icon('truck', 14) ?></div>لا بوالص بعد.</div></div>
  <?php else: ?>
    <div class="dir-grid">
      <?php foreach ($owners as $o):
        $key   = $o['owner_key'];
        $label = $key === '' ? '(بدون صاحب بيان)' : $key;
        $rem   = (float)$o['remaining'];
        $due   = (int)$o['due_bills'];
        $paidAmt = (float)$o['paid'];
        // شريط التقدّم: نسبة المدفوع فعليًا. بلا دين وبلا دفع = 0% (مش مكتمل).
        $paidPct = (float)$o['debt'] > 0 ? min(100, max(0, ($paidAmt/$o['debt'])*100)) : ($paidAmt > 0.005 ? 100 : 0);
        $ourl  = url('settlements') . '?owner=' . urlencode($key);
      ?>
        <div class="set-card<?= $rem>0.005?' due':'' ?>" onclick="location.href='<?= $ourl ?>'">
          <div class="dir-card-head">
            <div class="dir-avatar"><?= e(initials($label)) ?></div>
            <div class="dir-card-title">
              <b title="<?= e($label) ?>"><?= e($label) ?></b>
              <span>صاحب بيان — <?= intf($o['bills']) ?> بوليصة</span>
            </div>
            <?php if ($due>0): ?>
              <span class="pill st-unpaid" title="بوالص غير مسدّدة"><?= intf($due) ?> غير مسدّدة</span>
            <?php elseif ($paidAmt > 0.005): ?>
              <span class="pill st-paid">مسدّد بالكامل</span>
            <?php else: ?>
              <span class="pill st-pending" title="لا مديونية مستحقّة (بلا دفعات)">لا مديونية</span>
            <?php endif; ?>
          </div>
          <div class="set-figs">
            <div><span>المديونية</span><b class="fig-navy"><?= money($o['debt'],false) ?></b></div>
            <div><span>المدفوع</span><b class="pos"><?= money($o['paid'],false) ?></b></div>
            <div><span>المتبقّي</span><b class="<?= $rem>0.005?'neg':'pos' ?>"><?= money($rem,false) ?></b></div>
          </div>
          <div class="set-bar"><span style="width:<?= number_format($paidPct,1) ?>%"></span></div>
          <div class="dir-card-foot">
            <span>آخر بوليصة: <?= $o['last_date']?dfmt($o['last_date']):'—' ?></span>
            <span>فتح السداد ←</span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if (count($owners) >= 600): ?>
      <div class="dbt-cut">عُرض أول 600 صاحب بيان — استخدم البحث لإيجاد غيرهم.</div>
    <?php endif; ?>
  <?php endif; ?>

<?php endif; ?>


