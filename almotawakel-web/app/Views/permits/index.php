<?php use App\Models\Permit; $mode = $mode ?? 'follow'; ?>
<div class="permits-v2">

<!-- ترويسة الصفحة: مرجع الملف + تبويبات كملفات + إجراء رئيسي -->
<div class="pv-ref">سجلّ الإصدارات · MT-PERMITS · <?= date('Y') ?></div>
<div class="pv-head">
  <nav class="pv-tabs">
    <a class="pv-tab <?= $mode==='follow'?'on':'' ?>"    href="<?= url('permits') ?>">
      <span class="pvt-ic">🗂️</span> متابعة الأذونات
      <?php if($mode==='follow' && ($incomplete??0)>0): ?><span class="pvt-badge"><?= intf($incomplete) ?></span><?php endif; ?>
    </a>
    <a class="pv-tab <?= $mode==='movements'?'on':'' ?>" href="<?= url('permits?view=movements') ?>"><span class="pvt-ic">🔀</span> الحركات</a>
    <a class="pv-tab <?= $mode==='records'?'on':'' ?>"   href="<?= url('permits?view=records') ?>"><span class="pvt-ic">📚</span> السجلّات</a>
  </nav>
  <span style="flex:1"></span>
  <a class="btn gold pv-new" href="<?= url('permits/new') ?>">➕ إصدار جديد</a>
</div>

<!-- مؤشرات تفاعلية قابلة للنقر -->
<div class="pv-kpis">
  <a class="pv-kpi k-total" href="<?= url('permits?view=records') ?>">
    <span class="pv-kpi-ic">📄</span>
    <span class="pv-kpi-tx"><span class="pv-kpi-v num"><?= intf($stats['total']) ?></span><span class="pv-kpi-l">إجمالي الأذونات</span></span>
  </a>
  <a class="pv-kpi k-open" href="<?= url('permits?view=records&st=open') ?>">
    <span class="pv-kpi-ic">🟠</span>
    <span class="pv-kpi-tx"><span class="pv-kpi-v num warn"><?= intf($stats['open_cnt']) ?></span><span class="pv-kpi-l">مفتوحة</span></span>
  </a>
  <a class="pv-kpi k-closed" href="<?= url('permits?view=records&st=closed') ?>">
    <span class="pv-kpi-ic">✅</span>
    <span class="pv-kpi-tx"><span class="pv-kpi-v num good"><?= intf($stats['closed_cnt']) ?></span><span class="pv-kpi-l">مقفولة</span></span>
  </a>
  <?php if ($mode==='follow'): ?>
    <button type="button" class="pv-kpi k-inc <?= $incomplete>0?'is-alert':'' ?>" data-jump="warn" <?= $incomplete>0?'':'disabled' ?>>
      <span class="pv-kpi-ic"><?= $incomplete>0?'⚠️':'🎉' ?></span>
      <span class="pv-kpi-tx"><span class="pv-kpi-v num <?= $incomplete>0?'bad':'good' ?>"><?= intf($incomplete) ?></span><span class="pv-kpi-l">تحتاج استكمال</span></span>
    </button>
  <?php elseif ($mode==='movements'): ?>
    <div class="pv-kpi k-static">
      <span class="pv-kpi-ic">🔀</span>
      <span class="pv-kpi-tx"><span class="pv-kpi-v num"><?= intf($movesCount) ?></span><span class="pv-kpi-l">إجمالي الحركات</span></span>
    </div>
  <?php else: ?>
    <div class="pv-kpi k-static">
      <span class="pv-kpi-ic">🔎</span>
      <span class="pv-kpi-tx"><span class="pv-kpi-v num"><?= intf($total) ?></span><span class="pv-kpi-l">نتيجة العرض</span></span>
    </div>
  <?php endif; ?>
</div>

<?php if ($mode === 'follow'): ?>
  <?php // ============ شاشة المتابعة ============ ?>

  <?php if ($rows): ?>
  <!-- شريط أدوات حيّ: بحث + ترتيب + تصفية (client-side بالكامل) -->
  <div class="pv-ftools" id="pvFollowTools">
    <div class="pvf-search">
      <span class="pvf-search-ic">🔍</span>
      <input type="search" id="pvfSearch" placeholder="ابحث برقم الإذن / الشركة / الوكيل…" autocomplete="off">
      <button type="button" class="pvf-clear" id="pvfClear" title="مسح" hidden>✕</button>
    </div>
    <div class="pvf-chips" role="tablist">
      <button type="button" class="pvf-chip on" data-filter="all">الكل <span class="pvf-ct num" data-ct="all">0</span></button>
      <button type="button" class="pvf-chip" data-filter="warn">⚠ ناقص <span class="pvf-ct num" data-ct="warn">0</span></button>
      <button type="button" class="pvf-chip" data-filter="ok">✓ جاهز <span class="pvf-ct num" data-ct="ok">0</span></button>
    </div>
    <div class="pvf-spacer"></div>
    <label class="pvf-sort">
      <span>ترتيب</span>
      <select id="pvfSort">
        <option value="new">الأحدث تاريخاً</option>
        <option value="old">الأقدم تاريخاً</option>
        <option value="prog-asc">الأقل اكتمالاً</option>
        <option value="prog-desc">الأكثر اكتمالاً</option>
        <option value="rem-desc">الأكبر متبقّياً</option>
      </select>
    </label>
  </div>
  <?php endif; ?>

  <?php if (!$rows): ?>
    <div class="pv-empty"><div class="ic">✅</div>لا أذونات مفتوحة حالياً — كل شيء مغلق.<br><a class="btn gold" style="margin-top:14px" href="<?= url('permits/new') ?>">➕ إصدار جديد</a></div>
  <?php else: ?>
    <div class="dkt-grid" id="pvFollowGrid">
      <?php foreach ($rows as $r): $inc = $r['is_incomplete']; $prog=(int)$r['progress']; $th = Permit::theme($r['permit_type']);
            $rcps = $r['receipts'] ?? []; $rcTotal = array_sum(array_map(fn($x)=>(float)$x['amount'], $rcps));
            $rcShow = array_slice($rcps, 0, 6); $rcMore = count($rcps) - count($rcShow);
            $ctLbl = Permit::containerLabel($r['ctype_breakdown'], (int)($r['containers']??0), $r['container_type']??null);
            $missTip = $inc ? implode(' · ', $r['missing']) : 'كل البيانات مكتملة';
            $searchKey = mb_strtolower(trim(($r['permit_no']?:('#'.$r['id'])).' '.($r['company']??'').' '.($r['client']??'')));
            $url = url('permits/'.$r['id']); ?>
        <div class="dkt t-<?= $th['cls'] ?> <?= $inc?'warn':'ok' ?>" onclick="location.href='<?= $url ?>'" tabindex="0" role="link"
             data-status="<?= $inc?'warn':'ok' ?>" data-search="<?= e($searchKey) ?>" data-prog="<?= $prog ?>"
             data-rem="<?= (float)$r['remaining'] ?>" data-date="<?= e($r['permit_date']?:'') ?>" data-seq="<?= (int)$r['id'] ?>"
             onkeydown="if(event.key==='Enter'){location.href='<?= $url ?>'}">
          <header class="dkt-hd">
            <span class="dkt-kind <?= $th['cls'] ?>"><?= $th['full'] ?> <span class="dk-arrow"><?= $th['arrow'] ?></span></span>
            <?php if($ctLbl!==''): ?><span class="dkt-cth">📦 <?= e($ctLbl) ?></span><?php endif; ?>
            <span class="dkt-serial num"><?= e($r['permit_no'] ?: '#'.$r['id']) ?></span>
          </header>

          <div class="dkt-bd">
            <div class="dkt-co"><span class="dkt-mono"><?= e(initials($r['company'] ?: '؟')) ?></span><span class="dkt-co-tx"><?= e($r['company'] ?: '—') ?></span></div>
            <div class="dkt-meta">
              <?php if($r['client']): ?><span class="dkt-tag">👤 <?= e($r['client']) ?></span><?php endif; ?>
              <span class="dkt-tag">🗓️ <span class="num"><?= dfmt($r['permit_date']) ?: '—' ?></span></span>
              <span class="dkt-tag">🚛 <span class="num"><?= intf($r['bills']) ?></span> بوليصة</span>
            </div>

            <div class="dkt-prog">
              <span class="lbl">الاكتمال</span>
              <span class="track"><span style="width:<?= $prog ?>%"></span></span>
              <span class="pct num"><?= $prog ?>%</span>
            </div>
          </div>

          <!-- الإيصالات: سطر مضغوط + قائمة تفصيلية تفتح/تقفل بالضغط -->
          <div class="dkt-rcp<?= $rcps ? ' has-rcps' : '' ?>">
            <div class="rcp-strip"<?= $rcps ? ' onclick="event.stopPropagation();this.closest(\'.dkt-rcp\').classList.toggle(\'open\')" role="button" tabindex="0" onkeydown="if(event.key===\'Enter\'||event.key===\' \'){event.preventDefault();event.stopPropagation();this.closest(\'.dkt-rcp\').classList.toggle(\'open\')}"' : '' ?>>
              <span class="rcp-ttl">🧾</span>
              <span class="rcp-cnt"><?= intf(count($rcps)) ?></span>
              <?php if($rcTotal>0): ?><span class="rcp-sum num"><?= money($rcTotal,false) ?></span><?php endif; ?>
              <?php if (empty($rcps) && empty($r['rcp_missing'])): ?><span class="rcp-empty">لا إيصالات بعد</span><?php endif; ?>
              <?php if (!empty($r['rcp_missing'])): ?>
                <span class="rcp-miss"><?php foreach ($r['rcp_missing'] as $mrc): ?><span class="miss-chip">بدون <?= e($mrc) ?></span><?php endforeach; ?></span>
              <?php endif; ?>
              <?php if ($rcps): ?><span class="rcp-chev" title="مرّر لعرض التفاصيل">▾</span><?php endif; ?>
            </div>
            <?php if ($rcps): ?>
              <div class="rcp-pop">
                <ul class="rcp-list">
                  <?php foreach ($rcShow as $rc): ?>
                    <li><span class="rn"><?= e($rc['note'] ?: 'إيصال') ?></span><span class="ra num"><?= money($rc['amount'],false) ?></span></li>
                  <?php endforeach; ?>
                  <?php if ($rcMore > 0): ?><li class="rcp-more">+<span class="num"><?= intf($rcMore) ?></span> إيصال آخر…</li><?php endif; ?>
                </ul>
              </div>
            <?php endif; ?>
          </div>

          <footer class="dkt-ft">
            <span class="dkt-money"><span class="k">المتبقّي</span><b class="num <?= $r['remaining']>0?'neg':'pos' ?>"><?= money($r['remaining'],false) ?></b></span>
            <?php if ($inc): ?><span class="stamp wip" title="<?= e($missTip) ?>">⚠ ناقص</span>
            <?php else: ?><span class="stamp ready">✓ جاهز</span><?php endif; ?>
            <span class="dkt-ft-sp"></span>
            <span class="dkt-acts" onclick="event.stopPropagation()">
              <a class="dkt-act primary" href="<?= url('permits/'.$r['id'].'/manage') ?>#movementsWrap" title="إضافة إيصال">🧾</a>
              <a class="dkt-act" href="<?= $url ?>" title="فتح الملف">🗂️</a>
            </span>
          </footer>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="pv-empty sm" id="pvFollowNone" hidden><div class="ic">🔍</div>لا أذونات مطابقة لبحثك أو تصفيتك.<br><button type="button" class="btn ghost sm" style="margin-top:12px" onclick="document.getElementById('pvfClear')?.click()">مسح البحث</button></div>
  <?php endif; ?>

<?php elseif ($mode === 'movements'): ?>
  <?php // ============ ملخص الحركات ============ ?>
  <div class="pv-tools">
    <form method="get" action="<?= url('permits') ?>">
      <input type="hidden" name="view" value="movements">
      <input type="search" name="q" value="<?= e($q ?? '') ?>" placeholder="ابحث برقم الإذن / الشركة / الوكيل…">
      <button class="btn sm" type="submit">تصفية</button>
      <?php if(($q ?? '')!==''): ?><a class="btn ghost sm" href="<?= url('permits?view=movements') ?>">إلغاء</a><?php endif; ?>
    </form>
    <div class="spacer"></div>
    <span class="pv-count"><span class="num"><?= intf(count($moves)) ?></span> حركة</span>
  </div>

  <?php if (!$moves): ?>
    <div class="pv-empty"><div class="ic">🔀</div>لا حركات — أنشئ إذناً وأضف حركاته.</div>
  <?php else: ?>
    <div class="dkt-grid">
      <?php foreach ($moves as $m): $closed = ($m['status']??'open')==='closed'; $ctb = $m['ctype_breakdown'] ?? []; $th = Permit::theme($m['permit_type']); ?>
        <a class="dkt t-<?= $th['cls'] ?> <?= $closed?'ok':'warn' ?>" href="<?= url('permits/'.$m['permit_id'].'#movement-'.$m['id']) ?>">
          <header class="dkt-hd">
            <span class="dkt-kind <?= $th['cls'] ?>"><?= $th['full'] ?> <span class="dk-arrow"><?= $th['arrow'] ?></span></span>
            <span class="dkt-move num">حركة #<?= intf($m['id']) ?></span>
            <span class="dkt-serial num"><?= e($m['permit_no'] ?: '#'.$m['permit_id']) ?></span>
          </header>

          <div class="dkt-bd">
            <div class="dkt-co"><?= e($m['company'] ?: '—') ?></div>
            <div class="dkt-meta">
              <?php if($m['client']): ?><span class="dkt-tag">👤 <?= e($m['client']) ?></span><?php endif; ?>
              <span class="dkt-tag">🚛 <span class="num"><?= intf($m['bills']) ?></span> بوليصة</span>
              <span class="dkt-tag">📦 <span class="num"><?= intf($m['containers']) ?></span> حاوية</span>
              <span class="dkt-tag <?= $closed?'':'ct' ?>"><?= $closed?'مقفول':'جارٍ' ?></span>
              <?php foreach ($ctb as $c): ?><span class="dkt-tag ct"><span class="num"><?= intf($c['cnt']) ?></span>×<?= e($c['type']) ?></span><?php endforeach; ?>
            </div>
          </div>

          <div class="dkt-ldg">
            <div class="row"><span class="k">العهدة الصادرة</span><span class="val num"><?= money($m['custody'],false) ?></span></div>
            <div class="row"><span class="k">المصروف الفعلي</span><span class="val num"><?= money($m['expense'],false) ?></span></div>
          </div>

          <footer class="dkt-ft">
            <span class="stamp <?= $closed?'ready':'wip' ?>"><?= $closed?'✓ مقفول':'● جارٍ التنفيذ' ?></span>
          </footer>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php else: ?>
  <?php // ============ وضع السجلات ============ ?>
  <div class="pv-tools">
    <form method="get" action="<?= url('permits') ?>">
      <input type="hidden" name="view" value="records">
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="رقم الإذن/الشهادة، شركة، عميل…">
      <select name="type">
        <option value="">كل الأنواع</option>
        <option value="sader" <?= $type==='sader'?'selected':'' ?>>إذن (صادر)</option>
        <option value="warid" <?= $type==='warid'?'selected':'' ?>>شهادة (وارد)</option>
      </select>
      <select name="st">
        <option value="">كل الحالات</option>
        <option value="open" <?= ($st??'')==='open'?'selected':'' ?>>مفتوح</option>
        <option value="closed" <?= ($st??'')==='closed'?'selected':'' ?>>مقفول</option>
      </select>
      <input type="date" name="date" value="<?= e($date) ?>">
      <button class="btn sm" type="submit">تصفية</button>
      <?php if($q||$type||$date||($st??'')): ?><a class="btn ghost sm" href="<?= url('permits?view=records') ?>">إلغاء</a><?php endif; ?>
    </form>
    <div class="spacer"></div>
    <span class="pv-count"><span class="num"><?= intf($total) ?></span> إصدار</span>
  </div>

  <!-- شريط الطباعة الجماعية (يظهر عند اختيار أذونات) -->
  <div class="pv-batchbar" id="pvBatchBar">
    <label class="pbb-all"><input type="checkbox" id="pvAll"> تحديد الكل</label>
    <span class="pbb-count"><span class="num" id="pvCount">0</span> إذن محدَّد</span>
    <span style="flex:1"></span>
    <button type="button" class="btn gold sm" id="pvPrintBtn" disabled onclick="printSelectedPermits()">🖨️ طباعة المحدَّد</button>
  </div>

  <div class="pv-tbl-wrap">
    <table class="pv-tbl">
      <thead><tr>
        <th class="pv-pick" data-no-sort data-no-hide><input type="checkbox" id="pvAllTop" title="تحديد الكل"></th>
        <th>النوع</th>
        <th>الرقم</th>
        <th>التاريخ</th>
        <th class="txt">لصالح شركة</th>
        <th class="txt">الوكيل</th>
        <th>بوالص</th>
        <th>حاويات</th>
        <th>العهدة</th>
        <th>مصروفات</th>
        <th>محصّل إضافي</th>
        <th>متبقٍ البوالص</th>
        <th>الحالة</th>
        <th>إجراءات</th>
      </tr></thead>
      <tbody>
        <?php if(!$rows): ?>
          <tr><td colspan="14"><div class="pv-empty sm"><div class="ic">🗂️</div>لا نتائج.</div></td></tr>
        <?php else:
          $tCustody=$tExpense=$tFees=$tRemaining=$tBills=$tContainers=0;
          foreach($rows as $r):
            $tCustody += (float)$r['custody']; $tExpense += (float)$r['expense'];
            $tFees += (float)$r['fees']; $tRemaining += (float)$r['remaining'];
            $tBills += (int)$r['bills']; $tContainers += (int)($r['containers']??0);
        ?>
          <tr onclick="location.href='<?= url('permits/'.$r['id']) ?>'" style="cursor:pointer">
            <?php $th = Permit::theme($r['permit_type']); ?>
            <td class="pv-pick" onclick="event.stopPropagation()"><input type="checkbox" class="pv-row-pick" value="<?= (int)$r['id'] ?>"></td>
            <td><span class="kind-tag <?= $th['cls'] ?>"><?= $th['short'] ?> <?= $th['arrow'] ?></span></td>
            <td class="num serial"><?= e($r['permit_no'] ?: '#'.$r['id']) ?></td>
            <td class="num"><?= dfmt($r['permit_date']) ?></td>
            <td class="txt"><?= e($r['company'] ?: '—') ?></td>
            <td class="txt"><?= e($r['client'] ?: '—') ?></td>
            <td class="num"><?= intf($r['bills']) ?></td>
            <td class="num"><?php if(($r['containers']??0)>0): ?><?= intf($r['containers']) ?><?php if(($r['container_type']??'')!==''): ?>×<?= e($r['container_type']) ?><?php endif; else: ?>—<?php endif; ?></td>
            <td class="num pos"><?= money($r['custody'],false) ?></td>
            <td class="num neg"><?= money($r['expense'],false) ?></td>
            <td class="num pos"><?= money($r['fees'],false) ?></td>
            <td class="num"><?= money($r['remaining'],false) ?></td>
            <td><span class="stamp mini <?= ($r['status']??'open')==='closed'?'ready':'wip' ?>"><?= ($r['status']??'open')==='closed'?'مقفول':'جارٍ' ?></span></td>
            <td onclick="event.stopPropagation()"><div class="actions">
              <a class="edit" href="<?= url('permits/'.$r['id']) ?>" title="فتح">🗂️</a>
              <form class="confirm-delete" method="post" action="<?= url('permits/'.$r['id'].'/delete') ?>" style="margin:0">
                <?= csrfField() ?><button class="del" type="submit" title="حذف">🗑️</button>
              </form>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="pv-tot">
            <td data-no-sort></td>
            <td class="txt" colspan="2">الإجمالي (<?= intf(count($rows)) ?>)</td>
            <td></td><td></td><td></td>
            <td class="num"><?= intf($tBills) ?></td>
            <td class="num"><?= $tContainers>0?intf($tContainers):'—' ?></td>
            <td class="num pos"><?= money($tCustody,false) ?></td>
            <td class="num neg"><?= money($tExpense,false) ?></td>
            <td class="num pos"><?= money($tFees,false) ?></td>
            <td class="num"><?= money($tRemaining,false) ?></td>
            <td></td><td></td>
          </tr>
        </tfoot>
        <?php endif; ?>
    </table>
  </div>
<?php endif; ?>

<?php if ($mode === 'records'): ?>
<style>
  .pv-batchbar{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin:0 0 10px;
    padding:9px 14px;background:#fff;border:1px solid var(--line,#E2E7EE);border-radius:12px}
  .pv-batchbar .pbb-all{display:inline-flex;align-items:center;gap:7px;font-weight:700;cursor:pointer;color:var(--navy,#15355B)}
  .pv-batchbar .pbb-all input,.pv-tbl .pv-pick input{width:17px;height:17px;cursor:pointer;accent-color:var(--gold,#C9A227)}
  .pv-batchbar .pbb-count{color:var(--muted,#5A6472);font-weight:700}
  .pv-batchbar #pvPrintBtn:disabled{opacity:.45;cursor:not-allowed}
  .pv-tbl th.pv-pick,.pv-tbl td.pv-pick{width:38px;text-align:center;padding-inline:6px}
  .pv-tbl tfoot .pv-tot td{background:var(--navy,#15355B);color:#fff;font-weight:800;padding:11px 12px;
    text-align:center;white-space:nowrap;border-top:2px solid var(--gold,#C9A227)}
  .pv-tbl tfoot .pv-tot td.txt{text-align:right}
  .pv-tbl tfoot .pv-tot td.num{font-family:var(--mono);font-feature-settings:"tnum" 1}
  .pv-tbl tfoot .pv-tot td.pos{color:#a7e9bf} .pv-tbl tfoot .pv-tot td.neg{color:#ffc4c4}
</style>
<script>
(function(){
  function boxes(){ return Array.prototype.slice.call(document.querySelectorAll('.pv-row-pick')); }
  var allTop = document.getElementById('pvAllTop');
  var allBar = document.getElementById('pvAll');
  var cnt = document.getElementById('pvCount');
  var btn = document.getElementById('pvPrintBtn');
  if (!btn) return;
  function sync(){
    var bs = boxes();
    var sel = bs.filter(function(b){ return b.checked; });
    if (cnt) cnt.textContent = sel.length;
    btn.disabled = sel.length === 0;
    var allChecked = bs.length > 0 && sel.length === bs.length;
    if (allTop) allTop.checked = allChecked;
    if (allBar) allBar.checked = allChecked;
  }
  function selectAll(v){ boxes().forEach(function(b){ b.checked = v; }); sync(); }
  document.addEventListener('change', function(e){
    var t = e.target;
    if (t.classList && t.classList.contains('pv-row-pick')) sync();
    else if (t === allTop || t === allBar) selectAll(t.checked);
  });
  window.printSelectedPermits = function(){
    var ids = boxes().filter(function(b){ return b.checked; }).map(function(b){ return b.value; });
    if (!ids.length) return;
    window.open('<?= url('permits/print-batch') ?>?ids=' + ids.join(','), '_blank');
  };
  sync();
})();
</script>
<?php endif; ?>

<?php if ($mode === 'follow'): ?>
<script>
(function(){
  var grid = document.getElementById('pvFollowGrid');
  if (!grid) return;
  var cards   = Array.prototype.slice.call(grid.querySelectorAll('.dkt'));
  var search  = document.getElementById('pvfSearch');
  var clearBt = document.getElementById('pvfClear');
  var sortSel = document.getElementById('pvfSort');
  var chips   = Array.prototype.slice.call(document.querySelectorAll('.pvf-chip'));
  var noneBox = document.getElementById('pvFollowNone');
  var incKpi  = document.querySelector('.pv-kpi[data-jump="warn"]');
  var filter = 'all', term = '';

  // عدّادات الشرائح (ثابتة — على كل البطاقات)
  var totAll = cards.length;
  var totWarn = cards.filter(function(c){ return c.dataset.status === 'warn'; }).length;
  function setCt(k,v){ var el = document.querySelector('.pvf-ct[data-ct="'+k+'"]'); if(el) el.textContent = v; }
  setCt('all', totAll); setCt('warn', totWarn); setCt('ok', totAll - totWarn);

  function apply(){
    var shown = 0;
    cards.forEach(function(c){
      var okFilter = filter === 'all' || c.dataset.status === filter;
      var okTerm   = !term || (c.dataset.search || '').indexOf(term) !== -1;
      var vis = okFilter && okTerm;
      c.style.display = vis ? '' : 'none';
      if (vis) shown++;
    });
    if (noneBox) noneBox.hidden = shown !== 0;
    if (clearBt) clearBt.hidden = term === '';
  }

  function sortCards(){
    var mode = sortSel ? sortSel.value : 'new';
    var arr = cards.slice();
    arr.sort(function(a,b){
      var da=a.dataset, db=b.dataset;
      switch(mode){
        case 'old':      return (da.date||'').localeCompare(db.date||'') || (+da.seq - +db.seq);
        case 'prog-asc': return (+da.prog - +db.prog) || (+db.seq - +da.seq);
        case 'prog-desc':return (+db.prog - +da.prog) || (+db.seq - +da.seq);
        case 'rem-desc': return (+db.rem - +da.rem)   || (+db.seq - +da.seq);
        default:         return (db.date||'').localeCompare(da.date||'') || (+db.seq - +da.seq); // new
      }
    });
    arr.forEach(function(c){ grid.appendChild(c); });
  }

  if (search) search.addEventListener('input', function(){ term = (this.value||'').toLowerCase().trim(); apply(); });
  if (clearBt) clearBt.addEventListener('click', function(){ if(search){ search.value=''; } term=''; apply(); if(search) search.focus(); });
  if (sortSel) sortSel.addEventListener('change', sortCards);
  chips.forEach(function(ch){
    ch.addEventListener('click', function(){
      chips.forEach(function(x){ x.classList.remove('on'); });
      ch.classList.add('on'); filter = ch.dataset.filter; apply();
    });
  });
  if (incKpi) incKpi.addEventListener('click', function(){
    var warnChip = document.querySelector('.pvf-chip[data-filter="warn"]');
    if (warnChip) warnChip.click();
    grid.scrollIntoView({behavior:'smooth', block:'start'});
  });

  sortCards(); apply();
})();
</script>
<?php endif; ?>

</div>
