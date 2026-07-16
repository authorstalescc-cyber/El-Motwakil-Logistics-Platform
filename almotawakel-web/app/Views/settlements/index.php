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
<!-- شريط البحث + الفلترة الموسّعة (يعمل في الوضعين) -->
<div class="toolbar" style="flex-direction:column;align-items:stretch;gap:12px">
  <form method="get" action="<?= url('settlements') ?>" style="width:100%">
    <div class="dir-search" style="max-width:none;width:100%">
      <span class="ic">🔍</span>
      <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="بحث عن بوليصة…" autofocus>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;width:100%">
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
      <label style="color:var(--grey);font-weight:700">من</label>
      <input type="date" name="from" value="<?= e($f['from']) ?>">
      <label style="color:var(--grey);font-weight:700">إلى</label>
      <input type="date" name="to" value="<?= e($f['to']) ?>">
      <button class="btn sm" type="submit">🔍 بحث</button>
      <?php if ($isSearch): ?><a class="btn ghost sm" href="<?= url('settlements') ?>">✕ مسح</a><?php endif; ?>
    </div>
  </form>
  <datalist id="dl_company"><?php foreach ($companies as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
  <datalist id="dl_dest"><?php foreach ($destinations as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
  <datalist id="dl_class"><?php foreach ($classifs as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
  <datalist id="dl_ctype"><?php foreach ($ctypes as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
</div>

<?php if ($isSearch): /* ============================= وضع نتائج البحث ============================= */ ?>

  <div class="kpi-grid mb four" style="grid-template-columns:repeat(4,1fr)">
    <div class="kpi gold"><div class="lbl">عدد البوالص المطابقة</div><div class="val"><?= intf($resTot['n']) ?></div></div>
    <div class="kpi navy"><div class="lbl">إجمالي الدين</div><div class="val"><?= intf($resTot['debt']) ?><span class="cur">ج.م</span></div></div>
    <div class="kpi green"><div class="lbl">المدفوع</div><div class="val"><?= intf($resTot['paid']) ?><span class="cur">ج.م</span></div></div>
    <div class="kpi red"><div class="lbl">المتبقّي</div><div class="val"><?= intf($resTot['remaining']) ?><span class="cur">ج.م</span></div></div>
  </div>

  <div class="section-head" style="justify-content:flex-start"><h2>🔎 البوالص المطابقة — حدّد المطلوب وادفعه دفعة واحدة</h2></div>

  <?php $showOwner = true; require __DIR__ . '/_bills.php'; ?>

  <?php if (count($bills) >= 500): ?>
    <div style="padding:12px 4px;color:var(--grey)">عُرض أول 500 بوليصة — ضيّق البحث لعرض غيرها.</div>
  <?php endif; ?>

<?php else: /* ================================ وضع الدليل ================================ */ ?>

  <div class="settlement-view-actions">
    <a class="btn <?= empty($dueOnly)?'gold':'ghost' ?>" href="<?= url('settlements') ?>">👥 عرض كل أصحاب البيان</a>
    <a class="btn <?= !empty($dueOnly)?'gold':'ghost' ?>" href="<?= url('settlements?owners=due') ?>">🔴 أصحاب البيان الذين لهم مديونية فقط</a>
    <a class="btn ghost" target="_blank" href="<?= url('statements/batch?scope=' . (!empty($dueOnly)?'due':'all') . '&autoprint=1') ?>">🖨️ طباعة المعروض مجمعًا</a>
  </div>

  <div class="kpi-grid mb four" style="grid-template-columns:repeat(4,1fr)">
    <a class="kpi navy" href="<?= url('settlements') ?>" title="عرض كل أصحاب البيان"><div class="lbl">عدد أصحاب البيان</div><div class="val"><?= intf($tot['owners']) ?></div></a>
    <a class="kpi gold" href="<?= url('settlements') ?>?status=due" title="عرض البوالص غير المسدّدة"><div class="lbl">لهم مديونية</div><div class="val"><?= intf($dueOwners) ?></div></a>
    <a class="kpi green" href="<?= url('settlements') ?>?status=paid" title="عرض البوالص المسدّدة"><div class="lbl">إجمالي المدفوع لهم</div><div class="val"><?= intf($tot['paid']) ?><span class="cur">ج.م</span></div></a>
    <a class="kpi red" href="<?= url('settlements') ?>?status=due" title="عرض البوالص المتبقّية"><div class="lbl">إجمالي المتبقّي لهم</div><div class="val"><?= intf($tot['remaining']) ?><span class="cur">ج.م</span></div></a>
  </div>

  <?php if (($unassigned['n'] ?? 0) > 0): ?>
    <a class="warn-box" href="<?= url('settlements') ?>?unassigned=1">
      <div class="warn-ic">⚠️</div>
      <div class="warn-txt">
        <b><?= intf($unassigned['n']) ?> بوليصة بلا صاحب بيان</b>
        <span>متبقٍّ <b class="neg"><?= money($unassigned['remaining'], false) ?> ج.م</b></span>
      </div>
      <div class="warn-cta">تعيين الآن ←</div>
    </a>
  <?php endif; ?>

  <div class="section-head" style="justify-content:space-between">
    <h2>👤 أصحاب البيان</h2>
    <span class="count-badge"><?= intf(count($owners)) ?> <?= !empty($dueOnly)?'صاحب بيان له مديونية':'صاحب بيان' ?></span>
  </div>

  <?php if (!$owners): ?>
    <div class="card"><div class="empty" style="padding:44px"><div class="ic">🚛</div>لا بوالص بعد.</div></div>
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
      <div style="padding:12px 4px;color:var(--grey)">عُرض أول 600 صاحب بيان — استخدم البحث لإيجاد غيرهم.</div>
    <?php endif; ?>
  <?php endif; ?>

<?php endif; ?>

<style>
.set-card{display:flex;flex-direction:column;gap:12px;background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px;box-shadow:var(--shadow);transition:.18s;position:relative;overflow:hidden;cursor:pointer}
.settlement-view-actions{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin:0 0 16px;padding:11px;border:1px solid var(--line);border-radius:12px;background:#fff}
.set-card::before{content:'';position:absolute;top:0;inset-inline:0;height:4px;background:linear-gradient(90deg,var(--green),var(--gold))}
.set-card.due::before{background:linear-gradient(90deg,var(--red),var(--gold))}
.set-card:hover{transform:translateY(-4px);box-shadow:0 14px 32px rgba(21,53,91,.16);border-color:#cfd9e6}
.set-figs{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;text-align:center;background:var(--zebra);border:1px solid var(--line);border-radius:12px;padding:11px 8px}
.set-figs>div{display:flex;flex-direction:column;gap:3px}
.set-figs span{font-size:.72rem;color:var(--grey);font-weight:700}
.set-figs b{font-size:1.02rem;font-weight:800}
.set-figs b.fig-navy{color:var(--navy)}
.set-figs b.pos{color:var(--green)} .set-figs b.neg{color:var(--red)}
.set-bar{height:7px;background:var(--line);border-radius:6px;overflow:hidden}
.set-bar span{display:block;height:100%;background:linear-gradient(90deg,var(--green),#3a9d5f);border-radius:6px}
.warn-box{display:flex;align-items:center;gap:16px;text-decoration:none;color:inherit;
  background:linear-gradient(90deg,#fff7ed,#fffbeb);border:2px solid #f4c150;border-inline-start:6px solid #e0a500;
  border-radius:14px;padding:16px 20px;margin:6px 0 18px;box-shadow:0 6px 18px rgba(224,165,0,.14);
  transition:.18s;cursor:pointer}
.warn-box:hover{transform:translateY(-2px);box-shadow:0 12px 26px rgba(224,165,0,.22);border-color:#e0a500}
.warn-ic{font-size:2rem;line-height:1;flex-shrink:0}
.warn-txt{display:flex;flex-direction:column;gap:5px;flex:1;min-width:0}
.warn-txt>b{font-size:1.06rem;color:#8a5a00}
.warn-txt>span{font-size:.9rem;color:var(--grey);line-height:1.7}
.warn-cta{flex-shrink:0;font-weight:800;color:#8a5a00;background:#ffe9b0;border:1px solid #e6c766;
  border-radius:10px;padding:9px 14px;white-space:nowrap}
</style>
