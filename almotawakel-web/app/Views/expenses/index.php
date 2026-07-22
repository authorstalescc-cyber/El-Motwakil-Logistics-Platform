<?php
/** وحدة المصروفات — السجل الموحّد */
use App\Models\Expense;
use App\Core\FormState;
$returnPath = current_app_path();
$monthNames = ['','يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
$qs = fn(array $ov=[]) => http_build_query(array_merge(
    ['q'=>$f['q'],'source'=>$f['source'],'visa'=>$f['visa']??'','from'=>$f['from'],'to'=>$f['to'],'year'=>$year], $ov));
$hasFilter = $f['q']||$f['source']||($f['visa']??'')||$f['from']||$f['to']||$year;
// «سداد المديونيات» ليس مصروفاً؛ خارج «إجمالي المصاريف» ويظهر كبند منفصل.
$isDebtFilter = ($f['source'] === 'debt');
$debtTotal    = $totals['debt'] ?? 0;
// ألوان شارات المصادر
$srcClass = ['manual'=>'manual','permits'=>'permits','bawalis'=>'bawalis','takhlis'=>'takhlis','shamil'=>'shamil','debt'=>'debt'];
?>
<style>
.exp-cards{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:16px}
.exp-grand{flex:1 1 220px;background:var(--navy);color:#fff;border-radius:14px;padding:16px 20px}
.exp-grand .lbl{font-size:.85rem;opacity:.85}
.exp-grand .val{font-size:1.7rem;font-weight:800;color:var(--gold);margin-top:4px}
.exp-grand .sub{font-size:.8rem;opacity:.8;margin-top:4px}
.exp-src{flex:1 1 150px;background:#fff;border:1px solid var(--line);border-radius:14px;padding:12px 16px}
.exp-src .lbl{font-size:.85rem;color:var(--grey);display:flex;align-items:center;gap:6px}
.exp-src .val{font-size:1.15rem;font-weight:700;margin-top:4px}
.exp-src .cnt{font-size:.75rem;color:var(--grey)}
.src-pill{display:inline-block;padding:2px 10px;border-radius:999px;font-size:.8rem;font-weight:600;white-space:nowrap}
.src-manual{background:#e7f0fb;color:#15355B}
.src-permits{background:#efe7fb;color:#5b2f8f}
.src-bawalis{background:#fbe7ec;color:#8f2f45}
.src-takhlis{background:#e7f6ef;color:#1f7a52}
.src-shamil{background:#fdf3e0;color:#8a6d1a}
.src-debt{background:#eae7f6;color:#4b3b8f}
.dot{width:10px;height:10px;border-radius:50%;display:inline-block}
tr.norec:hover{background:transparent!important}
</style>

<!-- بطاقات الإجمالي -->
<div class="exp-cards">
  <div class="exp-grand">
    <div class="lbl"><?= $isDebtFilter ? '💳 إجمالي سداد المديونيات' : '💸 إجمالي المصروفات' ?><?= $hasFilter?' (بالفلتر الحالي)':'' ?></div>
    <div class="val"><?= money($isDebtFilter ? $debtTotal : $totals['grand']) ?></div>
    <div class="sub"><?= intf($total) ?> بند<?= (!$isDebtFilter && $debtTotal>0) ? ' · سداد المديونيات ('.money($debtTotal,false).') خارج الإجمالي' : '' ?></div>
  </div>
  <?php foreach ($totals['by_source'] as $key => $s): if ($s['count']===0 && $f['source']==='') continue; ?>
    <div class="exp-src">
      <div class="lbl"><span class="src-pill src-<?= $srcClass[$key]??'manual' ?>"><?= e($s['label']) ?></span></div>
      <div class="val"><?= money($s['value'], false) ?></div>
      <div class="cnt"><?= intf($s['count']) ?> بند<?= $key==='debt' ? ' · خارج الإجمالي' : '' ?></div>
    </div>
  <?php endforeach; ?>
</div>

<!-- شريط الأدوات والفلترة -->
<div class="toolbar">
  <form method="get" action="<?= url('expenses') ?>" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
    <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="بحث (فئة / جهة / بيان)…">
    <select name="source">
      <option value="">كل المصادر</option>
      <?php foreach ($sources as $key => $label): ?>
        <option value="<?= e($key) ?>" <?= $f['source']===$key?'selected':'' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="visa">
      <option value="">💳 الكل (نقدي + فيزا)</option>
      <option value="only" <?= ($f['visa']??'')==='only'?'selected':'' ?>>فيزا فقط</option>
      <option value="cash" <?= ($f['visa']??'')==='cash'?'selected':'' ?>>نقدي فقط</option>
    </select>
    <select name="year">
      <option value="">كل السنوات</option>
      <?php foreach ($years as $y): ?>
        <option value="<?= e($y) ?>" <?= (string)$year===(string)$y?'selected':'' ?>><?= e($y) ?></option>
      <?php endforeach; ?>
    </select>
    <label style="font-size:.85rem;color:var(--grey)">من</label>
    <input type="date" name="from" value="<?= e($f['from']) ?>">
    <label style="font-size:.85rem;color:var(--grey)">إلى</label>
    <input type="date" name="to" value="<?= e($f['to']) ?>">
    <button class="btn sm" type="submit">تصفية</button>
    <?php if ($hasFilter): ?><a class="btn ghost sm" href="<?= url('expenses') ?>">إلغاء</a><?php endif; ?>
  </form>
  <div class="spacer"></div>
  <span class="count-badge"><?= intf($total) ?> بند</span>
  <a class="btn ghost" href="<?= url('expenses/receipts') ?>">🧾 استكمال بيانات الإيصالات</a>
  <a class="btn gold" href="<?= url('expenses/new?back=' . rawurlencode($returnPath)) ?>">➕ إضافة مصروف يدوي</a>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th class="g-identity">م</th>
          <th class="g-identity">التاريخ</th>
          <th class="g-identity">المصدر</th>
          <th class="g-identity">الفئة</th>
          <th class="g-identity">الجهة / المستفيد</th>
          <th class="g-identity">البيان</th>
          <th class="g-expense">المبلغ</th>
          <th class="g-identity">إجراءات</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr class="norec"><td colspan="8">
            <div class="empty"><div class="ic">📭</div>لا توجد مصروفات مطابقة — اضغط «إضافة مصروف يدوي» لبدء الإدخال.</div>
          </td></tr>
        <?php else: $i = ($page-1)*50; foreach ($rows as $r): $i++;
          $src = $r['source'];
          $isManual = $src === 'manual';
          $originUrl = $isManual ? null : Expense::originUrl($src, $r['ref_id']);
        ?>
          <tr>
            <td><?= intf($i) ?></td>
            <td><?= dfmt($r['d']) ?></td>
            <td><span class="src-pill src-<?= $srcClass[$src]??'manual' ?>"><?= e($sources[$src] ?? $src) ?></span></td>
            <td><?= e($r['category']) ?><?php if(!empty($r['is_visa'])): ?> <span class="tl-visa-badge">💳 فيزا</span><?php endif; ?></td>
            <td><?= e($r['party']) ?></td>
            <td><?= e($r['note']) ?></td>
            <td class="neg"><?= money($r['amount'], false) ?></td>
            <td>
              <div class="actions">
                <?php if ($isManual): ?>
                  <a class="edit" href="<?= url('expenses/'.$r['ref_id'].'/edit?back=' . rawurlencode($returnPath)) ?>" title="تعديل">✏️</a>
                  <form class="confirm-delete" method="post" action="<?= url('expenses/'.$r['ref_id'].'/delete') ?>" style="margin:0">
                    <?= csrfField() ?>
                    <?= formStateFields(FormState::token(), $returnPath) ?>
                    <button class="del" type="submit" title="حذف">🗑️</button>
                  </form>
                <?php elseif ($originUrl): ?>
                  <a href="<?= e($originUrl) ?>" title="فتح البند الأصلي">↗️</a>
                <?php else: ?>
                  <span style="color:var(--grey)">—</span>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
      <?php if ($rows): ?>
      <tfoot>
        <tr>
          <td colspan="6" style="text-align:left;font-weight:700"><?= $isDebtFilter ? 'إجمالي سداد المديونيات' : 'إجمالي المصاريف (بدون سداد المديونيات)' ?><?= $hasFilter?' (بالفلتر الحالي)':'' ?></td>
          <td class="neg" style="font-weight:800"><?= money($isDebtFilter ? $debtTotal : $totals['grand'], false) ?></td>
          <td></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>

  <?php if ($pages > 1): ?>
  <div class="pager">
    <?php if ($page>1): ?><a href="?<?= $qs(['page'=>$page-1]) ?>">‹ السابق</a><?php endif; ?>
    <?php
      $start=max(1,$page-2); $end=min($pages,$page+2);
      if ($start>1) echo '<a href="?'.$qs(['page'=>1]).'">1</a>'.($start>2?'<span class="dots">…</span>':'');
      for ($p=$start;$p<=$end;$p++):
        echo $p==$page ? '<span class="cur">'.$p.'</span>' : '<a href="?'.$qs(['page'=>$p]).'">'.$p.'</a>';
      endfor;
      if ($end<$pages) echo ($end<$pages-1?'<span class="dots">…</span>':'').'<a href="?'.$qs(['page'=>$pages]).'">'.$pages.'</a>';
    ?>
    <?php if ($page<$pages): ?><a href="?<?= $qs(['page'=>$page+1]) ?>">التالي ›</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
