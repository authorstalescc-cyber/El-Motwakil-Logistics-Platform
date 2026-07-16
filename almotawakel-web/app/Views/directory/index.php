<?php
/** @var string $tab @var string $q @var array $agents @var array $companies @var array $owners */
$isA = $tab === 'agents';
$isC = $tab === 'companies';
$type = $isA ? 'agent' : ($isC ? 'company' : 'owner');
$rows = $isA ? $agents : ($isC ? $companies : $owners);
$label = $isA ? 'وكيل' : ($isC ? 'شركة' : 'صاحب بيان');
?>
<nav class="tabs" aria-label="أقسام الدليل">
  <a class="tab <?= $isA?'active':'' ?>" href="<?= url('directory?tab=agents') ?>">👤 الوكلاء (عملاؤنا) <span class="count-badge"><?= intf(count($agents)) ?></span></a>
  <a class="tab <?= $isC?'active':'' ?>" href="<?= url('directory?tab=companies') ?>">🏢 الشركات <span class="count-badge"><?= intf(count($companies)) ?></span></a>
  <a class="tab <?= $tab==='owners'?'active':'' ?>" href="<?= url('directory?tab=owners') ?>">📄 أصحاب البيان <span class="count-badge"><?= intf(count($owners)) ?></span></a>
</nav>

<form method="post" action="<?= url('directory/master/'.$type) ?>" class="card mb" style="padding:14px;display:flex;gap:10px;align-items:end;flex-wrap:wrap">
  <?= csrfField() ?>
  <div class="field" style="margin:0;min-width:220px"><label>إضافة <?= e($label) ?> إلى البيانات الأساسية</label><input name="name" required placeholder="الاسم القانوني المعتمد"></div>
  <div class="field" style="margin:0;min-width:150px"><label>الهاتف</label><input name="phone"></div>
  <?php if($isA): ?><div class="field" style="margin:0;width:130px"><label>مهلة السداد</label><input type="number" name="payment_terms_days" value="30" min="0" max="365"></div><?php endif; ?>
  <button class="btn gold" type="submit">إضافة</button>
</form>

<div class="dir-toolbar">
  <form method="get" action="<?= url('directory') ?>" class="dir-search">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <span class="ic">🔍</span>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="ابحث باسم <?= e($label) ?> أو اسم بديل…">
  </form>
  <?php if($q!==''): ?><a class="btn ghost sm" href="<?= url('directory?tab='.$tab) ?>">✕ مسح البحث</a><?php endif; ?>
  <div class="dir-hero-spacer"></div>
  <span class="count-badge"><?= intf(count($rows)) ?> <?= e($label) ?></span>
</div>

<?php if(!$rows): ?>
  <div class="card"><div class="empty" style="padding:44px"><div class="ic"><?= $isA?'👤':'🏢' ?></div><?= $q!==''?'لا نتائج مطابقة للبحث.':'لا بيانات.' ?></div></div>
<?php else: ?>
  <div class="dir-grid">
    <?php foreach($rows as $r):
      $url   = $isA ? url('directory/agent/'.$r['id']) : ($isC ? url('directory/company/'.$r['id']) : '');
      $cnt   = (int)($isA ? $r['companies'] : ($isC ? $r['agents'] : ($r['bills'] ?? 0)));
      $names = !empty($r['preview']) ? explode('|', $r['preview']) : [];
      $shown = array_slice($names, 0, 3);
      $extra = count($names) - count($shown);
    ?>
      <div class="dir-card <?= $isA?'':'company' ?>">
        <?php if($url): ?>
        <a class="dir-card-profile" href="<?= $url ?>" aria-label="فتح ملف <?= e($label) ?> <?= e($r['name']) ?>">
        <?php else: ?>
        <div class="dir-card-profile">
        <?php endif; ?>
        <div class="dir-card-head">
          <div class="dir-avatar"><?= e(initials($r['name'])) ?></div>
          <div class="dir-card-title">
            <b title="<?= e($r['name']) ?>"><?= e($r['name']) ?></b>
            <span><?= e($label) ?> — سجل أساسي</span>
          </div>
          <div class="dir-count"><b><?= intf($cnt) ?></b><?= $isA?'شركة':($isC?'وكيل':'بوليصة') ?></div>
        </div>
        <?php if($isA||$isC): ?><div class="dir-chips">
          <?php if($shown): foreach($shown as $n): ?>
            <span class="dir-chip"><?= e($n) ?></span>
          <?php endforeach; if($extra>0): ?>
            <span class="dir-chip more">+<?= intf($extra) ?></span>
          <?php endif; else: ?>
            <span class="dir-chip none"><?= $isA?'عميل مباشر (بدون شركات)':'بدون وكيل مرتبط' ?></span>
          <?php endif; ?>
        </div><?php endif; ?>
        <?php if($isA && (($r['charges'] ?? 0) > 0.005 || ($r['paid'] ?? 0) > 0.005)): $rem=(float)($r['remaining']??0); ?>
        <div class="dir-bal">
          <span class="dir-bal-lbl">المتبقّي عليه</span>
          <span class="dir-bal-val <?= $rem>0.005?'due':($rem<-0.005?'credit':'') ?>"><?= money($rem,false) ?></span>
        </div>
        <?php endif; ?>
        <?php if($url): ?>
          <div class="dir-card-foot"><span>فتح الملف</span><span aria-hidden="true">←</span></div>
        </a>
        <?php else: ?>
        </div>
        <?php endif; ?>
        <details class="master-edit" onclick="event.stopPropagation()">
          <summary>إدارة الاسم والبيانات والدمج</summary>
          <form method="post" action="<?= url('directory/master/'.$type.'/'.$r['id']) ?>" class="master-grid">
            <?= csrfField() ?><input type="hidden" name="lock_version" value="<?= (int)($r['lock_version'] ?? 1) ?>">
            <input name="name" value="<?= e($r['name']) ?>" required aria-label="الاسم">
            <input name="phone" value="<?= e($r['phone'] ?? '') ?>" placeholder="الهاتف">
            <input name="email" value="<?= e($r['email'] ?? '') ?>" placeholder="البريد">
            <input name="tax_id" value="<?= e($r['tax_id'] ?? '') ?>" placeholder="الرقم الضريبي">
            <?php if($isA): ?><input type="number" name="payment_terms_days" value="<?= (int)($r['payment_terms_days'] ?? 30) ?>" min="0" max="365" aria-label="مهلة السداد بالأيام"><?php endif; ?>
            <button class="btn sm" type="submit">حفظ البيانات</button>
          </form>
          <form method="post" action="<?= url('directory/master/'.$type.'/'.$r['id'].'/alias') ?>" class="master-line">
            <?= csrfField() ?><input name="alias" required placeholder="اسم بديل/تهجئة قديمة"><button class="btn ghost sm">إضافة alias</button>
          </form>
          <form method="post" action="<?= url('directory/master/'.$type.'/'.$r['id'].'/merge') ?>" class="master-line" data-danger="1" data-confirm="دمج هذا السجل داخل السجل المختار؟ ستُنقل العلاقات ولا يمكن التراجع تلقائياً.">
            <?= csrfField() ?><input type="hidden" name="lock_version" value="<?= (int)($r['lock_version'] ?? 1) ?>">
            <select name="target_id" required><option value="">دمج داخل…</option><?php foreach($rows as $target): if((int)$target['id']===(int)$r['id'])continue; ?><option value="<?= (int)$target['id'] ?>"><?= e($target['name']) ?></option><?php endforeach; ?></select>
            <button class="btn del-btn sm" type="submit">دمج</button>
          </form>
        </details>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<style>
.dir-bal{display:flex;align-items:center;justify-content:space-between;padding:8px 12px;margin:0 0 2px;
  background:var(--bg);border-radius:9px;border:1px solid var(--line2)}
.dir-bal-lbl{font-size:.78rem;color:var(--grey);font-weight:700}
.dir-bal-val{font-size:1rem;font-weight:800;color:var(--navy)}
.dir-bal-val.due{color:var(--red)}.dir-bal-val.credit{color:var(--green)}
.master-edit{border-top:1px solid var(--line);padding-top:8px;margin-top:8px}.master-edit summary{cursor:pointer;font-weight:700;color:var(--navy)}
.master-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px;margin-top:9px}.master-grid input,.master-line input,.master-line select{padding:7px;border:1px solid var(--line);border-radius:7px;min-width:0}
.master-line{display:flex;gap:7px;margin-top:8px}.master-line input,.master-line select{flex:1}
.dir-card-profile{display:flex;flex-direction:column;gap:13px;color:inherit;text-decoration:none;border-radius:12px;outline:none}
.dir-card-profile:focus-visible{box-shadow:0 0 0 3px rgba(27,143,136,.25);outline:2px solid var(--teal);outline-offset:4px}
.dir-card:has(.dir-card-profile[href]){cursor:default}
@media(max-width:640px){.master-grid{grid-template-columns:1fr}}
</style>
