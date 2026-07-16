<?php
/**
 * صفحة صاحب البيان: إحصائياته الكاملة + بحث/فلترة قوية + اختيار بوالص ودفعها دفعة واحدة.
 * @var string $owner
 * @var string $ownerLabel
 * @var array  $stats
 * @var array  $shown
 * @var array  $bills
 * @var array  $f
 * @var bool   $filtered
 * @var string $backQS
 * @var array  $companies
 * @var array  $destinations
 * @var array  $classifs
 * @var array  $ctypes
 */
?>
<div class="page-actions">
  <a class="btn ghost" href="<?= url('settlements') ?>">‹ رجوع لأصحاب البيان</a>
  <span style="flex:1"></span>
  <a class="btn gold" href="<?= url('statements/form?owner=' . urlencode($owner)) ?>">📑 إصدار كشف حساب</a>
  <span class="pill st-<?= (int)$stats['due_bills']>0?'unpaid':'paid' ?>" style="font-size:.9rem;padding:6px 14px">
    <?= (int)$stats['due_bills']>0 ? intf($stats['due_bills']).' بوليصة غير مسدّدة' : 'كل البوالص مسدّدة' ?>
  </span>
</div>

<!-- هوية صاحب البيان -->
<div class="set-hero">
  <div class="set-hero-av"><?= e(initials($ownerLabel)) ?></div>
  <div style="min-width:0;flex:1">
    <h2 title="<?= e($ownerLabel) ?>"><?= e($ownerLabel) ?></h2>
    <?php if ($stats['first_date'] || $stats['last_date']): ?>
      <p>بوالص من <?= dfmt($stats['first_date']) ?> إلى <?= dfmt($stats['last_date']) ?></p>
    <?php endif; ?>
  </div>
  <div class="set-hero-rem">
    <span>المتبقّي له (دين علينا)</span>
    <b class="<?= (float)$stats['remaining']>0.005?'neg':'pos' ?>"><?= money($stats['remaining']) ?></b>
  </div>
</div>

<!-- إحصائيات صاحب البيان (فورية بمجرد اختياره) — كل كارت يعرض بوالصه المقابلة -->
<?php $ob = url('settlements') . '?owner=' . urlencode($owner); ?>
<div class="kpi-grid mb" style="grid-template-columns:repeat(6,1fr);gap:12px">
  <a class="kpi navy" href="<?= $ob ?>" title="عرض كل بوالصه"><div class="lbl">إجمالي المديونية</div><div class="val"><?= intf($stats['debt']) ?><span class="cur">ج.م</span></div></a>
  <a class="kpi green" href="<?= $ob ?>&amp;status=paid" title="عرض البوالص المسدّدة"><div class="lbl">إجمالي المدفوع</div><div class="val"><?= intf($stats['paid']) ?><span class="cur">ج.م</span></div></a>
  <a class="kpi red" href="<?= $ob ?>&amp;status=due" title="عرض البوالص المتبقّية"><div class="lbl">إجمالي المتبقّي</div><div class="val"><?= intf($stats['remaining']) ?><span class="cur">ج.م</span></div></a>
  <a class="kpi gold" href="<?= $ob ?>" title="عرض كل بوالصه"><div class="lbl">عدد البوالص</div><div class="val"><?= intf($stats['bills']) ?></div></a>
  <a class="kpi purple" href="<?= $ob ?>&amp;status=due" title="عرض غير المسدّدة"><div class="lbl">غير مسدّدة</div><div class="val"><?= intf($stats['due_bills']) ?></div></a>
  <a class="kpi teal" href="<?= $ob ?>&amp;status=paid" title="عرض المسدّدة"><div class="lbl">مسدّدة بالكامل</div><div class="val"><?= intf($stats['paid_bills']) ?></div></a>
</div>

<!-- بحث + فلترة قوية داخل بوالص صاحب البيان -->
<div class="toolbar" style="flex-direction:column;align-items:stretch;gap:12px">
  <form method="get" action="<?= url('settlements') ?>" style="width:100%">
    <input type="hidden" name="owner" value="<?= e($owner) ?>">
    <div class="dir-search" style="max-width:none;width:100%">
      <span class="ic">🔍</span>
      <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="بحث عن بوليصة…">
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
      <button class="btn sm" type="submit">تصفية</button>
      <?php if ($filtered): ?><a class="btn ghost sm" href="<?= url('settlements') ?>?owner=<?= urlencode($owner) ?>">✕ مسح الفلاتر</a><?php endif; ?>
    </div>
  </form>
  <datalist id="dl_company"><?php foreach ($companies as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
  <datalist id="dl_dest"><?php foreach ($destinations as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
  <datalist id="dl_class"><?php foreach ($classifs as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
  <datalist id="dl_ctype"><?php foreach ($ctypes as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
</div>

<?php if ($filtered): ?>
  <div class="ct-alloc" style="margin-bottom:14px">
    المعروض بعد الفلترة: <b><?= intf($shown['n']) ?></b> بوليصة —
    الدين <b><?= money($shown['debt'],false) ?></b> ·
    المدفوع <b class="pos"><?= money($shown['paid'],false) ?></b> ·
    المتبقّي <b class="<?= (float)$shown['remaining']>0.005?'neg':'pos' ?>"><?= money($shown['remaining'],false) ?></b>
  </div>
<?php endif; ?>

<!-- جدول البوالص + الدفع الجماعي (ملف مشترك) -->
<?php $showOwner = false; require __DIR__ . '/_bills.php'; ?>

<style>
.set-hero{display:flex;align-items:center;gap:18px;padding:20px 24px;background:linear-gradient(135deg,var(--navy),var(--navy-mid));color:#fff;border-radius:18px;box-shadow:var(--shadow-lg);margin-bottom:16px;flex-wrap:wrap}
.set-hero-av{width:60px;height:60px;border-radius:16px;flex:none;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.4rem;background:linear-gradient(135deg,var(--gold),#a5811a);color:var(--navy-dark);box-shadow:0 4px 12px rgba(201,162,39,.35)}
.set-hero h2{font-size:1.4rem;font-weight:800;color:var(--gold);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.set-hero p{font-size:.82rem;color:rgba(255,255,255,.78);font-weight:600;margin-top:2px}
.set-hero-rem{text-align:center;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);border-radius:14px;padding:10px 20px}
.set-hero-rem span{display:block;font-size:.74rem;color:rgba(255,255,255,.75);font-weight:700;margin-bottom:3px}
.set-hero-rem b{font-size:1.5rem;font-weight:800}
.set-hero-rem b.neg{color:#ff9d9d} .set-hero-rem b.pos{color:#a7e9bf}
</style>
