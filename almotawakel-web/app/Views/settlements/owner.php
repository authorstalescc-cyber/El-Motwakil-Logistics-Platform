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
<?php partial('owner_tabs', ['active' => 'settlements']); ?>
<div class="page-actions">
  <a class="btn ghost" href="<?= url('settlements') ?>">‹ رجوع لأصحاب البيان</a>
  <span class="ps-sp"></span>
  <a class="btn gold" href="<?= url('statements/form?owner=' . urlencode($owner)) ?>"><?= icon('layers', 14) ?> إصدار كشف حساب</a>
  <span class="pill bs-pill st-<?= (int)$stats['due_bills']>0?'unpaid':'paid' ?>">
    <?= (int)$stats['due_bills']>0 ? intf($stats['due_bills']).' بوليصة غير مسدّدة' : 'كل البوالص مسدّدة' ?>
  </span>
</div>

<!-- هوية صاحب البيان -->
<div class="set-hero">
  <div class="set-hero-av"><?= e(initials($ownerLabel)) ?></div>
  <div class="st-grow">
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
<div class="kpi-grid mb six">
  <a class="kpi navy" href="<?= $ob ?>" title="عرض كل بوالصه"><div class="lbl">إجمالي المديونية</div><div class="val"><?= intf($stats['debt']) ?><span class="cur">ج.م</span></div></a>
  <a class="kpi green" href="<?= $ob ?>&amp;status=paid" title="عرض البوالص المسدّدة"><div class="lbl">إجمالي المدفوع</div><div class="val"><?= intf($stats['paid']) ?><span class="cur">ج.م</span></div></a>
  <a class="kpi red" href="<?= $ob ?>&amp;status=due" title="عرض البوالص المتبقّية"><div class="lbl">إجمالي المتبقّي</div><div class="val"><?= intf($stats['remaining']) ?><span class="cur">ج.م</span></div></a>
  <a class="kpi gold" href="<?= $ob ?>" title="عرض كل بوالصه"><div class="lbl">عدد البوالص</div><div class="val"><?= intf($stats['bills']) ?></div></a>
  <a class="kpi purple" href="<?= $ob ?>&amp;status=due" title="عرض غير المسدّدة"><div class="lbl">غير مسدّدة</div><div class="val"><?= intf($stats['due_bills']) ?></div></a>
  <a class="kpi teal" href="<?= $ob ?>&amp;status=paid" title="عرض المسدّدة"><div class="lbl">مسدّدة بالكامل</div><div class="val"><?= intf($stats['paid_bills']) ?></div></a>
</div>

<!-- بحث + فلترة قوية داخل بوالص صاحب البيان -->
<div class="toolbar stack">
  <form method="get" action="<?= url('settlements') ?>" class="st-form">
    <input type="hidden" name="owner" value="<?= e($owner) ?>">
    <div class="dir-search full">
      <span class="ic"><?= icon('search', 14) ?></span>
      <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="بحث عن بوليصة…">
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
      <button class="btn sm" type="submit">تصفية</button>
      <?php if ($filtered): ?><a class="btn ghost sm" href="<?= url('settlements') ?>?owner=<?= urlencode($owner) ?>"><?= icon('x', 14) ?> مسح الفلاتر</a><?php endif; ?>
    </div>
  </form>
  <datalist id="dl_company"><?php foreach ($companies as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
  <datalist id="dl_dest"><?php foreach ($destinations as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
  <datalist id="dl_class"><?php foreach ($classifs as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
  <datalist id="dl_ctype"><?php foreach ($ctypes as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
</div>

<?php if ($filtered): ?>
  <div class="ct-alloc mb">
    المعروض بعد الفلترة: <b><?= intf($shown['n']) ?></b> بوليصة —
    الدين <b><?= money($shown['debt'],false) ?></b> ·
    المدفوع <b class="pos"><?= money($shown['paid'],false) ?></b> ·
    المتبقّي <b class="<?= (float)$shown['remaining']>0.005?'neg':'pos' ?>"><?= money($shown['remaining'],false) ?></b>
  </div>
<?php endif; ?>

<!-- جدول البوالص + الدفع الجماعي (ملف مشترك) -->
<?php $showOwner = false; require __DIR__ . '/_bills.php'; ?>

<?php
/* ══════════════════════════════════════════════════════════════════════════
   كشوف حساب صاحب البيان — المرحلة ٩ (دمج المحتوى)
   ─────────────────────────────────────────────────────────────────────────
   قبل كده: كشوف صاحب البيان كانت في **وحدة تانية خالص** (`/statements`)،
   فالمالك وهو واقف على صفحة صاحب البيان شايف رصيده وبوالصه، لكن عشان يشوف
   كشوفه السابقة أو يصدر كشفًا جديدًا كان لازم يرجع للقائمة الجانبية ويدخل
   الوحدة التانية **ويدوّر على نفس الاسم من الأول**.
   دلوقتي كشوفه معروضة هنا، وزر الإصدار محمّل باسمه.

   ⚠️ `OwnerStatement::forOwner()` بتمرّ على `PartyMaster` فبتلمّ **تهجئات
      الاسم المختلفة** (تحذير ٣: الربط نصّي بلا FK)، وبترجّع [] بأمان لو
      الجدول لسه مش موجود.
   ══════════════════════════════════════════════════════════════════════════ */
$statements = $statements ?? [];
$stNewUrl   = url('statements/form?owner=' . urlencode($owner));
?>
<div class="card mb" id="owner-statements">
  <div class="card-head">
    <div class="card-head-txt">
      <div class="section-head"><?= icon('receipt', 17) ?> كشوف حساب <?= e($ownerLabel) ?></div>
      <span class="d-badge navy"><?= intf(count($statements)) ?></span>
    </div>
    <div class="card-head-tools">
      <a class="btn gold sm" href="<?= e($stNewUrl) ?>"><?= icon('plus', 14) ?> إصدار كشف جديد</a>
    </div>
  </div>
  <?php if (!$statements): ?>
    <?php partial('empty', [
        'emoji'  => '📑',
        'title'  => 'مفيش كشوف حساب لصاحب البيان ده',
        'action' => ['label' => '➕ إصدار كشف جديد', 'url' => $stNewUrl],
    ]); ?>
  <?php else: ?>
    <div class="table-wrap"><table class="data">
      <thead><tr>
        <th class="g-identity">رقم المستند</th>
        <th class="g-identity">التاريخ</th>
        <th class="g-vehicle">بوالص مستلَمة</th>
        <th class="g-vehicle">بوالص باقية</th>
        <th class="g-revenue">استلم (المدرَج)</th>
        <th class="g-result">فاضل له (كلّي)</th>
        <th class="g-identity" data-no-sort data-no-hide></th>
      </tr></thead>
      <tbody>
        <?php foreach ($statements as $st): ?>
          <tr<?= (($st['status'] ?? 'issued') === 'void') ? ' class="st-void"' : '' ?>>
            <td class="dbt-tot"><?= e($st['doc_no'] ?: '#' . $st['id']) ?></td>
            <td><?= dfmt($st['statement_date']) ?></td>
            <td><?= intf($st['paid_count']) ?></td>
            <td><?= intf($st['remaining_count']) ?></td>
            <td class="pos"><?= money($st['paid_sum'], false) ?></td>
            <td class="dbt-tot"><?= money($st['stat_remaining'], false) ?></td>
            <td><div class="actions">
              <a class="edit" href="<?= url('statements/' . $st['id']) ?>" target="_blank" title="طباعة الكشف"><?= icon('file-text', 14) ?></a>
              <?php if (($st['status'] ?? 'issued') === 'void'): ?>
                <span class="pill st-unpaid">ملغى</span>
              <?php endif; ?>
            </div></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>


