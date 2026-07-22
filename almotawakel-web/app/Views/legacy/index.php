<?php
/** @var array $f @var array $rows @var array $sums @var int $page @var int $pages @var int $total @var int $per @var array $stats */
$active = ($f['q'] !== '' || $f['from'] !== '' || $f['to'] !== '');
$qs = fn(array $ov = []) => http_build_query(array_merge(
    ['q'=>$f['q'], 'from'=>$f['from'], 'to'=>$f['to']], $ov));
// أعمدة القائمة: [key, label, group, money?]
$cols = [
    ['bill_number','رقم البوليصة','identity',false],
    ['bill_date','التاريخ','identity',false],
    ['declaration_owner','صاحب البيان','identity',false],
    ['company','الشركة','identity',false],
    ['destination','الجهة','identity',false],
    ['car_no','رقم السيارة','vehicle',false],
    ['driver','السائق','vehicle',false],
    ['freight','نولون','revenue',true],
    ['custody','عهدة','revenue',true],
    ['remaining','باقى','revenue',true],
];
?>
<div class="flash" style="background:var(--gold-light);border-color:var(--gold);color:var(--navy)">
  <?= icon('inbox',18) ?>
  <b>أرشيف تاريخي للقراءة فقط</b> — بوالص مستوردة من النظام القديم.
  <span style="color:var(--grey)">معزولة تمامًا: لا تدخل في العهدة أو التقفيل أو أي حساب في البرنامج الحالي.</span>
</div>

<div class="kpi-grid mb five">
  <div class="card" style="padding:14px 16px">
    <div style="font-size:.78rem;color:var(--grey);font-weight:700">إجمالي البوالص<?= $active ? ' <span style="color:#8a6d10">(حسب الفلتر)</span>' : '' ?></div>
    <div style="font-size:1.5rem;font-weight:800;color:var(--navy)"><?= intf($stats['n']) ?></div>
  </div>
  <div class="card" style="padding:14px 16px">
    <div style="font-size:.78rem;color:var(--grey);font-weight:700">الفترة</div>
    <div style="font-size:1rem;font-weight:800;color:var(--navy)"><?= dfmt($stats['min_d']) ?> — <?= dfmt($stats['max_d']) ?></div>
  </div>
  <div class="card" style="padding:14px 16px">
    <div style="font-size:.78rem;color:var(--grey);font-weight:700">إجمالي النولون</div>
    <div style="font-size:1.3rem;font-weight:800;color:var(--navy)"><?= money0($stats['freight']) ?></div>
  </div>
  <div class="card" style="padding:14px 16px">
    <div style="font-size:.78rem;color:var(--grey);font-weight:700">إجمالي العهدة</div>
    <div style="font-size:1.3rem;font-weight:800;color:var(--navy)"><?= money0($stats['custody']) ?></div>
  </div>
  <div class="card" style="padding:14px 16px">
    <div style="font-size:.78rem;color:var(--grey);font-weight:700">إجمالي الباقي</div>
    <div style="font-size:1.3rem;font-weight:800;color:var(--navy)"><?= money0($stats['remaining'] ?? 0) ?></div>
  </div>
</div>

<!-- كشف حساب صاحب البيان (أرشيفي) — اقتراح أسماء يتحمّل الأخطاء الإملائية -->
<div class="card" style="padding:14px 16px;margin-bottom:14px;border-inline-start:4px solid var(--gold)">
  <div style="font-weight:800;color:var(--navy);margin-bottom:8px">📑 كشف حساب صاحب البيان (من الأرشيف)</div>
  <form method="get" action="<?= url('legacy/statement') ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <div style="position:relative;flex:1;min-width:280px;max-width:420px">
      <input type="text" name="owner" id="lgOwner" autocomplete="off" required
             placeholder="اكتب اسم صاحب البيان… (الاقتراحات تشمل الأسماء المتشابهة إملائيًّا)"
             style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px">
      <div id="lgSug" class="lg-sug" hidden></div>
    </div>
    <button class="btn gold sm" type="submit">👁️ معاينة الكشف</button>
    <button class="btn sm" type="submit" formtarget="_blank">🖨️ فتح للطباعة</button>
    <span style="font-size:.78rem;color:var(--grey)">يعرض المدفوع والباقي لكل بوليصة — معاينة فقط، لا يُحفَظ ولا يمسّ أي حساب.</span>
  </form>
</div>

<div class="toolbar">
  <form method="get" action="<?= url('legacy') ?>">
    <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="بحث برقم البوليصة / صاحب البيان / الشركة / السائق / الحاوية / السيارة…" style="min-width:340px">
    <label style="color:var(--grey);font-weight:700">من</label>
    <input type="date" name="from" value="<?= e($f['from']) ?>">
    <label style="color:var(--grey);font-weight:700">إلى</label>
    <input type="date" name="to" value="<?= e($f['to']) ?>">
    <button class="btn sm" type="submit"><?= icon('search',15) ?> تصفية</button>
    <?php if ($active): ?><a class="btn ghost sm" href="<?= url('legacy') ?>">إلغاء</a><?php endif; ?>
  </form>
  <div class="spacer"></div>
  <span class="count-badge"><?= intf($total) ?> نتيجة</span>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th class="g-identity">م</th>
          <?php foreach ($cols as [$k,$lbl,$grp,$m]): ?>
            <th class="g-<?= $grp ?>"><?= e($lbl) ?></th>
          <?php endforeach; ?>
          <th class="g-identity">عرض</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="<?= count($cols)+2 ?>">
            <div class="empty" style="padding:40px"><div class="ic">📭</div>
              <?= $active ? 'لا نتائج مطابقة للبحث.' : 'لا بيانات في الأرشيف.' ?></div>
          </td></tr>
        <?php else: $i = ($page-1)*$per; foreach ($rows as $row): $i++; ?>
          <tr class="rowlink" data-href="<?= url('legacy/'.$row['id']) ?>">
            <td><?= intf($i) ?></td>
            <?php foreach ($cols as [$k,$lbl,$grp,$m]):
              $v = $row[$k] ?? null;
              if ($k === 'bill_date') { $txt = dfmt($v); }
              elseif ($m)             { $txt = $v !== null ? money($v, false) : ''; }
              else                    { $txt = e($v); }
            ?>
              <td class="<?= $m ? signClass($v) : '' ?>"><?= $k==='bill_number' ? '<b>'.$txt.'</b>' : ($txt !== '' ? $txt : '—') ?></td>
            <?php endforeach; ?>
            <td><a href="<?= url('legacy/'.$row['id']) ?>" title="تفاصيل">👁️</a></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
      <?php if ($rows): ?>
      <tfoot>
        <tr>
          <td>Σ</td>
          <td colspan="6" style="text-align:start;color:var(--grey)">إجمالي الصفحات المطابقة (<?= intf($sums['n']) ?> بوليصة)</td>
          <td><?= money($sums['freight'], false) ?></td>
          <td><?= money($sums['custody'], false) ?></td>
          <td><?= money($sums['remaining'], false) ?></td>
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

<style>
tr.rowlink{cursor:pointer}tr.rowlink:hover{background:var(--gold-light)!important}
.lg-sug{position:absolute;top:calc(100% + 4px);inset-inline:0;z-index:50;background:#fff;
  border:1px solid var(--line);border-radius:10px;box-shadow:0 8px 24px rgba(21,53,91,.15);
  max-height:320px;overflow:auto}
.lg-sug .it{display:flex;align-items:center;gap:8px;padding:9px 12px;cursor:pointer;border-bottom:1px solid var(--line)}
.lg-sug .it:last-child{border-bottom:none}
.lg-sug .it:hover,.lg-sug .it.on{background:var(--gold-light)}
.lg-sug .nm{font-weight:800;color:var(--navy);flex:1}
.lg-sug .ct{font-size:.75rem;color:var(--grey)}
.lg-sug .sim{font-size:.7rem;font-weight:800;color:#8a6d10;background:var(--gold-light);
  border:1px solid var(--gold);border-radius:6px;padding:1px 7px}
.lg-sug .none{padding:12px;color:var(--grey);text-align:center;font-size:.85rem}
</style>
<script>
document.querySelectorAll('tr.rowlink').forEach(function(tr){
  tr.addEventListener('click', function(e){
    if (e.target.closest('a,button,form,input')) return;
    window.location = tr.dataset.href;
  });
});

// اقتراح أسماء أصحاب البيان (يشمل الأسماء المتشابهة إملائيًّا)
(function(){
  var inp = document.getElementById('lgOwner'), box = document.getElementById('lgSug');
  if (!inp) return;
  var timer = null, idx = -1, items = [];

  function hide(){ box.hidden = true; idx = -1; }
  function pick(name){ inp.value = name; hide(); inp.focus(); }

  function draw(list){
    items = list;
    if (!list.length){
      box.innerHTML = '<div class="none">لا توجد أسماء مطابقة في الأرشيف</div>';
      box.hidden = false; return;
    }
    box.innerHTML = list.map(function(o,i){
      return '<div class="it" data-i="'+i+'">'
           + '<span class="nm"></span>'
           + (o.kind==='similar' ? '<span class="sim">مشابه</span>' : '')
           + '<span class="ct">'+o.bills+' بوليصة</span></div>';
    }).join('');
    box.querySelectorAll('.it').forEach(function(el,i){
      el.querySelector('.nm').textContent = list[i].name;   // نص آمن
      el.addEventListener('mousedown', function(e){ e.preventDefault(); pick(list[i].name); });
    });
    box.hidden = false; idx = -1;
  }

  inp.addEventListener('input', function(){
    clearTimeout(timer);
    var q = inp.value.trim();
    if (q.length < 2){ hide(); return; }
    timer = setTimeout(function(){
      fetch('<?= url('legacy/owners') ?>?q=' + encodeURIComponent(q), {headers:{'Accept':'application/json'}})
        .then(function(r){ return r.json(); })
        .then(function(d){ if (inp.value.trim() === d.q) draw(d.items || []); })
        .catch(function(){ hide(); });
    }, 220);
  });

  inp.addEventListener('keydown', function(e){
    if (box.hidden || !items.length) return;
    var els = box.querySelectorAll('.it');
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp'){
      e.preventDefault();
      idx = e.key === 'ArrowDown' ? Math.min(idx+1, items.length-1) : Math.max(idx-1, 0);
      els.forEach(function(el,i){ el.classList.toggle('on', i===idx); });
      els[idx].scrollIntoView({block:'nearest'});
    } else if (e.key === 'Enter' && idx >= 0){
      e.preventDefault(); pick(items[idx].name);
    } else if (e.key === 'Escape'){ hide(); }
  });

  inp.addEventListener('blur', function(){ setTimeout(hide, 150); });
})();
</script>
