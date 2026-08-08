<?php
/**
 * وحدة المناديب — قائمة الكروت.
 * @var array  $reps       المناديب + رصيد كل واحد (balance = المستحق له علينا)
 * @var string $q          بحث
 * @var string $status     فلتر الحالة ('', active, inactive)
 * @var float  $totalOwed  إجمالي المستحق لكل المناديب
 */
?>
<style>
.reps-top{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:16px}
.reps-top .fig{background:var(--card);border:1px solid var(--line);border-radius:var(--radius-2);padding:14px 12px;text-align:center}
.reps-top .fig .lbl{font-size:var(--fs-2);color:var(--grey);font-weight:500}
.reps-top .fig .val{font-size:var(--fs-6);font-weight:600;margin-top:4px;color:var(--navy)}
.reps-top .fig .val.neg{color:var(--red)}
.rep-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}
.rep-card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius-3);padding:16px 18px;
  display:flex;flex-direction:column;gap:10px;text-decoration:none;color:inherit;transition:box-shadow .15s,transform .15s}
.rep-card:hover{box-shadow:0 8px 24px rgba(21,53,91,.12);transform:translateY(-2px)}
.rep-card .rc-head{display:flex;align-items:center;gap:12px}
.rep-card .rc-av{width:46px;height:46px;border-radius:50%;background:var(--navy);color:var(--gold);
  display:flex;align-items:center;justify-content:center;font-weight:600;font-size:var(--fs-5);flex:0 0 auto}
.rep-card .rc-name{font-weight:600;font-size:var(--fs-4);color:var(--navy)}
.rep-card .rc-phone{font-size:var(--fs-2);color:var(--grey);direction:ltr;text-align:right}
.rep-card .rc-row{display:flex;justify-content:space-between;font-size:var(--fs-3);padding:4px 0;border-top:1px dashed var(--line)}
.rep-card .rc-row .k{color:var(--grey)}
.rep-card .rc-row .v{font-weight:500}
.rep-card .rc-bal{font-weight:600}
.rep-card .rc-bal.pos{color:var(--green)} .rep-card .rc-bal.neg{color:var(--red)}
.rep-card .rc-status{font-size:var(--fs-1);font-weight:600;padding:2px 10px;border-radius:var(--radius-4)}
.rc-status.active{background:#e6f4ec;color:var(--green)} .rc-status.inactive{background:#f1f1f4;color:var(--grey)}
</style>

<div class="toolbar">
  <div class="section-head" ><?= icon('user',20) ?> المناديب</div>
  <div class="spacer"></div>
  <a class="btn gold" href="<?= url('reps/new') ?>"><?= icon('plus',16) ?> إضافة مندوب</a>
</div>

<div class="reps-top">
  <div class="fig"><div class="lbl">عدد المناديب</div><div class="val"><?= intf(count($reps)) ?></div></div>
  <div class="fig"><div class="lbl">إجمالي المستحق لهم علينا</div><div class="val <?= $totalOwed>0?'':'' ?>"><?= money($totalOwed) ?></div></div>
</div>

<form method="get" action="<?= url('reps') ?>" class="filters" class="p5-row end mb">
  <div class="field flush grow200">
    <label>بحث بالاسم أو التليفون</label>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="اكتب اسم المندوب…">
  </div>
  <div class="field flush w160">
    <label>الحالة</label>
    <select name="status">
      <option value="">الكل</option>
      <option value="active"   <?= $status==='active'?'selected':'' ?>>شغّال</option>
      <option value="inactive" <?= $status==='inactive'?'selected':'' ?>>موقوف</option>
    </select>
  </div>
  <button class="btn" type="submit"><?= icon('search',16) ?> بحث</button>
  <?php if($q||$status): ?><a class="btn ghost" href="<?= url('reps') ?>">مسح</a><?php endif; ?>
</form>

<?php if(!$reps): ?>
  <div class="card"><div class="empty"><div class="ic"><?= icon('user', 14) ?>‍<?= icon('briefcase', 14) ?></div>مفيش مناديب بعد. اضغط «إضافة مندوب» لبدء أول كارت.</div></div>
<?php else: ?>
  <div class="rep-grid">
    <?php foreach ($reps as $r): $bal = (float) $r['balance']; ?>
      <a class="rep-card" href="<?= url('reps/'.$r['id']) ?>">
        <div class="rc-head">
          <span class="rc-av"><?= e(initials($r['name'])) ?></span>
          <div class="p5-grow0">
            <div class="rc-name"><?= e($r['name']) ?></div>
            <?php if($r['phone']): ?><div class="rc-phone"><?= e($r['phone']) ?></div><?php endif; ?>
          </div>
          <span class="rc-status <?= $r['status']==='active'?'active':'inactive' ?>"><?= $r['status']==='active'?'شغّال':'موقوف' ?></span>
        </div>
        <div class="rc-row"><span class="k">المرتب الشهري</span><span class="v"><?= money($r['monthly_salary'],false) ?></span></div>
        <div class="rc-row"><span class="k">عدد بنود السجل</span><span class="v"><?= intf($r['entries']) ?></span></div>
        <div class="rc-row"><span class="k">المستحق له علينا</span><span class="rc-bal <?= $bal>0.005?'pos':($bal<-0.005?'neg':'') ?>"><?= money($bal,false) ?></span></div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
