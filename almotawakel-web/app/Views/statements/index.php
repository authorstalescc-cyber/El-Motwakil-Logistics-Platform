<?php
/** @var array $rows @var array $sums @var int $total @var int $page @var int $pages */
?>
<div class="page-head">
  <div><h1 style="margin:0">📑 كشوف أصحاب البيان</h1></div>
  <div style="display:flex;gap:8px;flex-wrap:wrap"><a class="btn ghost" target="_blank" href="<?= url('statements/batch?scope=all&autoprint=1') ?>">🖨️ كشف مجمع لكل أصحاب البيان</a><a class="btn ghost" target="_blank" href="<?= url('statements/batch?scope=due&autoprint=1') ?>">🔴 كشف مجمع لأصحاب المديونية</a><a class="btn gold" href="<?= url('statements/new') ?>">➕ إصدار كشف جديد</a></div>
</div>

<!-- بطاقات ملخّص -->
<div class="stat-row mb">
  <div class="stat-item"><div class="si-ic navy">📑</div><div><b><?= intf($total) ?></b><span>إجمالي الكشوف</span></div></div>
  <div class="stat-item"><div class="si-ic gold">👤</div><div><b><?= intf($sums['owners']) ?></b><span>أصحاب بيان</span></div></div>
  <div class="stat-item"><div class="si-ic green">💰</div><div><b><?= money($sums['paid'],false) ?></b><span>مجموع المستلَم المدرَج</span></div></div>
  <div class="stat-item"><div class="si-ic red">🧾</div><div><b><?= money($sums['remaining'],false) ?></b><span>مجموع الباقي المدرَج</span></div></div>
</div>

<!-- فلترة -->
<form class="card mb" method="get" action="<?= url('statements') ?>" style="padding:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:end">
  <div><label class="flt-l">بحث</label>
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="رقم المستند / صاحب البيان"
           style="width:300px;padding:8px 11px;border:1px solid var(--line);border-radius:8px"></div>
  <div><label class="flt-l">من</label><input type="date" name="from" value="<?= e($from) ?>" style="padding:8px;border:1px solid var(--line);border-radius:8px"></div>
  <div><label class="flt-l">إلى</label><input type="date" name="to" value="<?= e($to) ?>" style="padding:8px;border:1px solid var(--line);border-radius:8px"></div>
  <button class="btn" type="submit">🔍 بحث</button>
  <?php if($q||$from||$to): ?><a class="btn ghost" href="<?= url('statements') ?>">مسح</a><?php endif; ?>
</form>

<!-- الجدول -->
<div class="card" style="padding:0;overflow:hidden">
  <?php if (!$rows): ?>
    <div class="empty" style="padding:40px"><div class="ic">📑</div>لا توجد كشوف مطابقة.
      <div style="margin-top:12px"><a class="btn gold" href="<?= url('statements/new') ?>">➕ إصدار كشف جديد</a></div></div>
  <?php else: ?>
    <div class="table-wrap"><table class="data">
      <thead><tr>
        <th class="g-identity">رقم المستند</th><th class="g-identity">التاريخ</th><th class="g-identity">صاحب البيان</th>
        <th class="g-vehicle">بوالص مستلَمة</th><th class="g-vehicle">بوالص باقية</th>
        <th class="g-revenue">استلم (المدرَج)</th><th class="g-result">فاضل له (كلّي)</th><th class="g-identity"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $st): ?>
        <tr>
          <td style="font-weight:800"><?= e($st['doc_no'] ?: '#'.$st['id']) ?></td>
          <td><?= dfmt($st['statement_date']) ?></td>
          <td style="font-weight:700"><a href="<?= url('settlements?owner=' . urlencode($st['owner'] ?? '')) ?>"><?= e($st['owner'] ?: '(بدون صاحب بيان)') ?></a></td>
          <td><?= intf($st['paid_count']) ?></td>
          <td><?= intf($st['remaining_count']) ?></td>
          <td class="pos"><?= money($st['paid_sum'],false) ?></td>
          <td style="font-weight:800;color:var(--navy)"><?= money($st['stat_remaining'],false) ?></td>
          <td><div class="actions">
            <a class="edit" href="<?= url('statements/'.$st['id']) ?>" target="_blank" title="طباعة">🖨️</a>
            <a class="edit" href="<?= url('statements/form?owner=' . urlencode($st['owner'] ?? '')) ?>" title="إصدار كشف آخر لنفس صاحب البيان">➕</a>
            <?php if(($st['status']??'issued')!=='void'): ?><form method="post" action="<?= url('statements/'.$st['id'].'/delete') ?>" style="display:inline"
                  data-danger="1" data-confirm="إلغاء الكشف مع الاحتفاظ برقمه؟">
              <?= csrfField() ?><input type="hidden" name="lock_version" value="<?= (int)($st['lock_version']??1) ?>"><input name="reason" required placeholder="سبب الإلغاء" style="width:100px"><button class="del" title="إلغاء">⛔</button>
            </form><?php else: ?><span class="pill st-unpaid">ملغى</span><?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>

<?php if ($pages > 1): ?>
  <div class="pager">
    <?php for ($i=1;$i<=$pages;$i++):
      $qs = http_build_query(array_filter(['q'=>$q,'from'=>$from,'to'=>$to,'page'=>$i])); ?>
      <a class="<?= $i===$page?'active':'' ?>" href="<?= url('statements').'?'.$qs ?>"><?= $i ?></a>
    <?php endfor; ?>
  </div>
<?php endif; ?>

<style>
  .page-head{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:16px;flex-wrap:wrap}
  .stat-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
  .stat-item{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid var(--line);border-radius:12px;padding:12px 14px}
  .si-ic{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:19px;flex:0 0 auto}
  .si-ic.navy{background:#eaf0f8}.si-ic.gold{background:#fff4d9}.si-ic.green{background:#e4f5ec}.si-ic.red{background:#fbe7e9}
  .stat-item b{display:block;font-size:1.05rem;color:var(--navy)}.stat-item span{font-size:.74rem;color:var(--grey)}
  .flt-l{display:block;font-size:.72rem;color:var(--grey);font-weight:700;margin-bottom:3px}
  .del{background:none;border:none;cursor:pointer;font-size:1rem}
  .pager{display:flex;gap:6px;justify-content:center;margin-top:16px;flex-wrap:wrap}
  .pager a{padding:7px 13px;border:1px solid var(--line);border-radius:8px;background:#fff;color:var(--navy);font-weight:700;text-decoration:none}
  .pager a.active{background:var(--navy);color:#fff}
  @media(max-width:800px){.stat-row{grid-template-columns:repeat(2,1fr)}}
</style>
