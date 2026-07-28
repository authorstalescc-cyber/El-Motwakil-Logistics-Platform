<?php use App\Models\Permit; $mode = $mode ?? 'follow'; ?>
<div class="permits-v2">

<!-- ترويسة الصفحة: مرجع الملف + تبويبات كملفات + إجراء رئيسي -->
<div class="pv-ref">سجلّ الإصدارات · MT-PERMITS · <?= date('Y') ?></div>
<div class="pv-head">
  <nav class="pv-tabs">
    <a class="pv-tab <?= $mode==='follow'?'on':'' ?>"    href="<?= url('permits') ?>">
      <span class="pvt-ic"><?= icon('folder', 16) ?></span> متابعة الأذونات
      <?php if($mode==='follow' && ($incomplete??0)>0): ?><span class="pvt-badge"><?= intf($incomplete) ?></span><?php endif; ?>
    </a>
    <a class="pv-tab <?= $mode==='movements'?'on':'' ?>" href="<?= url('permits?view=movements') ?>"><span class="pvt-ic"><?= icon('shuffle', 16) ?></span> الحركات</a>
    <a class="pv-tab <?= $mode==='records'?'on':'' ?>"   href="<?= url('permits?view=records') ?>"><span class="pvt-ic"><?= icon('layers', 16) ?></span> السجلّات</a>
  </nav>
  <span style="flex:1"></span>
  <a class="btn gold pv-new" href="<?= url('permits/new') ?>"><?= icon('plus', 15) ?> إصدار جديد</a>
</div>

<!-- مؤشرات تفاعلية قابلة للنقر -->
<div class="pv-kpis">
  <a class="pv-kpi k-total" href="<?= url('permits?view=records') ?>">
    <span class="pv-kpi-ic"><?= icon('file-text', 18) ?></span>
    <span class="pv-kpi-tx"><span class="pv-kpi-v num"><?= intf($stats['total']) ?></span><span class="pv-kpi-l">إجمالي الأذونات</span></span>
  </a>
  <a class="pv-kpi k-open" href="<?= url('permits?view=records&st=open') ?>">
    <span class="pv-kpi-ic"><?= icon('folder', 18) ?></span>
    <span class="pv-kpi-tx"><span class="pv-kpi-v num warn"><?= intf($stats['open_cnt']) ?></span><span class="pv-kpi-l">مفتوحة</span></span>
  </a>
  <a class="pv-kpi k-closed" href="<?= url('permits?view=records&st=closed') ?>">
    <span class="pv-kpi-ic"><?= icon('check-circle', 18) ?></span>
    <span class="pv-kpi-tx"><span class="pv-kpi-v num good"><?= intf($stats['closed_cnt']) ?></span><span class="pv-kpi-l">مقفولة</span></span>
  </a>
  <?php if ($mode==='follow'): ?>
    <?php if($incomplete>0): ?><a class="pv-kpi k-inc is-alert" href="<?= url('permits?state=warn') ?>">
      <span class="pv-kpi-ic"><?= icon($incomplete>0?'alert':'check-circle', 18) ?></span>
      <span class="pv-kpi-tx"><span class="pv-kpi-v num <?= $incomplete>0?'bad':'good' ?>"><?= intf($incomplete) ?></span><span class="pv-kpi-l">تحتاج استكمال</span></span>
    </a><?php else: ?><div class="pv-kpi k-inc">
      <span class="pv-kpi-ic"><?= icon('check-circle', 18) ?></span><span class="pv-kpi-tx"><span class="pv-kpi-v num good">0</span><span class="pv-kpi-l">تحتاج استكمال</span></span>
    </div><?php endif; ?>
  <?php elseif ($mode==='movements'): ?>
    <div class="pv-kpi k-static">
      <span class="pv-kpi-ic"><?= icon('shuffle', 18) ?></span>
      <span class="pv-kpi-tx"><span class="pv-kpi-v num"><?= intf($movesCount) ?></span><span class="pv-kpi-l">إجمالي الحركات</span></span>
    </div>
  <?php else: ?>
    <div class="pv-kpi k-static">
      <span class="pv-kpi-ic"><?= icon('search', 18) ?></span>
      <span class="pv-kpi-tx"><span class="pv-kpi-v num"><?= intf($total) ?></span><span class="pv-kpi-l">نتيجة العرض</span></span>
    </div>
  <?php endif; ?>
</div>

<?php if ($mode === 'follow'): ?>
  <?php // ============ شاشة المتابعة ============ ?>
  <?php
    $followQ = $followQ ?? ''; $followState = $followState ?? 'all'; $followSort = $followSort ?? 'new';
    $followCo = $followCo ?? ''; $followCl = $followCl ?? ''; $followGroup = $followGroup ?? '';
    $followCounts = $followCounts ?? ['all'=>0,'warn'=>0,'ok'=>0];
    $followQs = static function(array $overrides = []) use ($followQ,$followState,$followSort,$followCo,$followCl,$followGroup): string {
      $query = ['q'=>$followQ,'state'=>$followState,'sort'=>$followSort,'co'=>$followCo,'cl'=>$followCl,'group'=>$followGroup,'page'=>1];
      $query = array_merge($query,$overrides);
      return http_build_query(array_filter($query,static fn($value,$key)=>$value!=='' && !($key==='state'&&$value==='all') && !($key==='sort'&&$value==='new') && !($key==='page'&&(int)$value===1),ARRAY_FILTER_USE_BOTH));
    };
    $followUrl = static fn(array $overrides = []): string => url('permits?'.$followQs($overrides));
  ?>

  <?php if (($stats['open_cnt']??0)>0 || $followQ!=='' || $followState!=='all'): ?>
  <!-- البحث والتصفية على كل الأذونات المفتوحة، لا على الصفحة الظاهرة فقط. -->
  <form class="pv-ftools" method="get" action="<?= url('permits') ?>">
    <div class="pvf-search">
      <span class="pvf-search-ic"><?= icon('search', 14) ?></span>
      <input type="search" name="q" value="<?= e($followQ) ?>" placeholder="بحث" autocomplete="off">
      <?php if($followQ!==''): ?><a class="pvf-clear" href="<?= url('permits?'.$followQs(['q'=>''])) ?>" title="مسح البحث"><?= icon('x', 14) ?></a><?php endif; ?>
    </div>
    <input type="hidden" name="state" value="<?= e($followState) ?>">
    <input type="hidden" name="co" value="<?= e($followCo) ?>">
    <input type="hidden" name="cl" value="<?= e($followCl) ?>">
    <input type="hidden" name="group" value="<?= e($followGroup) ?>">
    <div class="pvf-chips" role="tablist">
      <a class="pvf-chip <?= $followState==='all'?'on':'' ?>" href="<?= url('permits?'.$followQs(['state'=>'all'])) ?>">الكل <span class="pvf-ct num"><?= intf($followCounts['all']) ?></span></a>
      <a class="pvf-chip <?= $followState==='warn'?'on':'' ?>" href="<?= url('permits?'.$followQs(['state'=>'warn'])) ?>"><?= icon('alert', 14) ?> ناقص <span class="pvf-ct num"><?= intf($followCounts['warn']) ?></span></a>
      <a class="pvf-chip <?= $followState==='ok'?'on':'' ?>" href="<?= url('permits?'.$followQs(['state'=>'ok'])) ?>"><?= icon('check', 14) ?> جاهز <span class="pvf-ct num"><?= intf($followCounts['ok']) ?></span></a>
    </div>
    <div class="pvf-spacer"></div>
    <label class="pvf-sort">
      <span>ترتيب</span>
      <select name="sort">
        <option value="new" <?= $followSort==='new'?'selected':'' ?>>الأحدث تاريخاً</option>
        <option value="old" <?= $followSort==='old'?'selected':'' ?>>الأقدم تاريخاً</option>
        <option value="rem-desc" <?= $followSort==='rem-desc'?'selected':'' ?>>الأكبر متبقّياً</option>
      </select>
    </label>
    <button class="btn sm" type="submit">تطبيق</button>
  </form>
  <?php endif; ?>

  <?php // أزرار الشركات/الوكلاء المتكررين + تجميع البطاقات — تظهر تلقائيًا بلا أي إعداد
    $pcCoList = $coChips ?? []; $pcClList = $clChips ?? [];
    $pcCo = $followCo; $pcCl = $followCl; $pcUrl = $followUrl;
    include __DIR__ . '/_party_chips.php';
  ?>

  <?php if ($followCo!=='' || $followCl!=='' || ($coChips??[])): ?>
  <div class="pv-viewbar">
    <?php if ($followCo!=='' || $followCl!==''): ?>
      <span class="pvv-active">
        <?= icon('search', 14) ?> معروض الآن:
        <?php if($followCo!==''): ?><b><?= e($followCo) ?></b><?php endif; ?>
        <?php if($followCo!=='' && $followCl!==''): ?> · <?php endif; ?>
        <?php if($followCl!==''): ?><b><?= e($followCl) ?></b><?php endif; ?>
        — <span class="num"><?= intf($followTotal) ?></span> إذن
      </span>
      <a class="pvv-x" href="<?= e($followUrl(['co'=>'','cl'=>''])) ?>"><?= icon('x', 14) ?> إلغاء التصفية</a>
    <?php endif; ?>
    <span style="flex:1"></span>
    <a class="pvv-toggle<?= $followGroup==='co'?' on':'' ?>" href="<?= e($followUrl(['group'=>$followGroup==='co'?'':'co'])) ?>">
      <?= icon('folder', 14) ?> <?= $followGroup==='co' ? 'إلغاء التجميع' : 'تجميع حسب الشركة' ?>
    </a>
  </div>
  <?php endif; ?>

  <?php if (!$rows): ?>
    <?php if($followQ!=='' || $followState!=='all'): ?>
      <div class="pv-empty"><div class="ic"><?= icon('search', 14) ?></div>لا توجد أذونات مطابقة للبحث أو التصفية.<br><a class="btn ghost" style="margin-top:14px" href="<?= url('permits') ?>">مسح التصفية</a></div>
    <?php else: ?>
      <div class="pv-empty"><div class="ic"><?= icon('check', 14) ?></div>لا أذونات مفتوحة حالياً — كل شيء مغلق.<br><a class="btn gold" style="margin-top:14px" href="<?= url('permits/new') ?>"><?= icon('plus', 14) ?> إصدار جديد</a></div>
    <?php endif; ?>
  <?php else: ?>
    <?php
      // ملخّص كل شركة داخل الصفحة الظاهرة (للتجميع فقط — بلا أي حساب جديد)
      $grpCnt = []; $grpRem = [];
      if ($followGroup === 'co') {
        foreach ($rows as $rr) { $k = (string)($rr['company'] ?? '');
          $grpCnt[$k] = ($grpCnt[$k] ?? 0) + 1;
          $grpRem[$k] = ($grpRem[$k] ?? 0) + (float)$rr['remaining'];
        }
      }
      $grpCur = null;
    ?>
    <div class="dkt-grid<?= $followGroup==='co'?' is-grouped':'' ?>" id="pvFollowGrid">
      <?php foreach ($rows as $r): $inc = $r['is_incomplete']; $prog=(int)$r['progress']; $th = Permit::theme($r['permit_type']);
            $rcps = $r['receipts'] ?? []; $rcTotal = array_sum(array_map(fn($x)=>(float)$x['amount'], $rcps));
            $rcShow = array_slice($rcps, 0, 6); $rcMore = count($rcps) - count($rcShow);
            $ctLbl = Permit::containerLabel($r['ctype_breakdown'], (int)($r['containers']??0), $r['container_type']??null);
            $missTip = $inc ? implode(' · ', $r['missing']) : 'كل البيانات مكتملة';
            $searchKey = mb_strtolower(trim(($r['permit_no']?:('#'.$r['id'])).' '.($r['company']??'').' '.($r['client']??'')));
            $url = url('permits/'.$r['id']); ?>
        <?php if ($followGroup==='co'): $gk = (string)($r['company'] ?? ''); if ($gk !== $grpCur): $grpCur = $gk; ?>
          <div class="dkt-grp">
            <span class="dg-ic"><?= icon('building', 14) ?></span>
            <b class="dg-nm"><?= e($gk !== '' ? $gk : 'بدون شركة') ?></b>
            <span class="dg-ct"><span class="num"><?= intf($grpCnt[$gk] ?? 0) ?></span> إذن</span>
            <?php if(($grpRem[$gk] ?? 0) != 0): ?><span class="dg-rem">المتبقّي <b class="num"><?= money($grpRem[$gk],false) ?></b></span><?php endif; ?>
            <span style="flex:1"></span>
            <?php if ($gk !== '' && $followCo !== $gk): ?>
              <a class="dg-only" href="<?= e($followUrl(['co'=>$gk,'page'=>1])) ?>">اعرضها وحدها ←</a>
            <?php endif; ?>
          </div>
        <?php endif; endif; ?>
        <div class="dkt t-<?= $th['cls'] ?> <?= $inc?'warn':'ok' ?>" onclick="location.href='<?= $url ?>'" tabindex="0" role="link"
             data-status="<?= $inc?'warn':'ok' ?>" data-search="<?= e($searchKey) ?>" data-prog="<?= $prog ?>"
             data-rem="<?= (float)$r['remaining'] ?>" data-date="<?= e($r['permit_date']?:'') ?>" data-seq="<?= (int)$r['id'] ?>"
             onkeydown="if(event.key==='Enter'){location.href='<?= $url ?>'}">
          <header class="dkt-hd">
            <span class="dkt-kind <?= $th['cls'] ?>"><?= $th['full'] ?> <span class="dk-arrow"><?= $th['arrow'] ?></span></span>
            <?php if($ctLbl!==''): ?><span class="dkt-cth"><?= icon('package', 14) ?> <?= e($ctLbl) ?></span><?php endif; ?>
            <span class="dkt-serial num"><?= e($r['permit_no'] ?: '#'.$r['id']) ?></span>
          </header>

          <div class="dkt-bd">
            <div class="dkt-co">
              <span class="dkt-mono"><?= e(initials($r['company'] ?: '؟')) ?></span>
              <span class="dkt-co-tx"><?= e($r['company'] ?: '—') ?></span>
              <?php if(($r['company']??'')!=='' && $followCo!==$r['company']): ?>
                <a class="dkt-filt" href="<?= e($followUrl(['co'=>$r['company'],'page'=>1])) ?>" onclick="event.stopPropagation()"
                   title="اعرض أذونات هذه الشركة فقط">⛃</a>
              <?php endif; ?>
            </div>
            <div class="dkt-meta">
              <?php if($r['client']): ?>
                <?php if($followCl!==$r['client']): ?>
                  <a class="dkt-tag dkt-tag-lnk" href="<?= e($followUrl(['cl'=>$r['client'],'page'=>1])) ?>" onclick="event.stopPropagation()"
                     title="اعرض أذونات هذا الوكيل فقط"><?= icon('user', 14) ?> <?= e($r['client']) ?></a>
                <?php else: ?><span class="dkt-tag"><?= icon('user', 14) ?> <?= e($r['client']) ?></span><?php endif; ?>
              <?php endif; ?>
              <span class="dkt-tag">🗓️ <span class="num"><?= dfmt($r['permit_date']) ?: '—' ?></span></span>
              <span class="dkt-tag"><?= icon('truck', 14) ?> <span class="num"><?= intf($r['bills']) ?></span> بوليصة</span>
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
              <span class="rcp-ttl"><?= icon('receipt', 14) ?></span>
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
            <?php if ($inc): ?><span class="stamp wip" title="<?= e($missTip) ?>"><?= icon('alert', 14) ?> ناقص</span>
            <?php else: ?><span class="stamp ready"><?= icon('check', 14) ?> جاهز</span><?php endif; ?>
            <span class="dkt-ft-sp"></span>
            <span class="dkt-acts" onclick="event.stopPropagation()">
              <a class="dkt-act primary" href="<?= url('permits/'.$r['id'].'/manage') ?>#movementsWrap" title="إضافة إيصال"><?= icon('receipt', 14) ?></a>
              <a class="dkt-act" href="<?= $url ?>" title="فتح الملف"><?= icon('folder', 14) ?></a>
            </span>
          </footer>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="pv-count" style="justify-content:center;margin-top:12px">عرض <?= intf((($followPage-1)*48)+1) ?>–<?= intf((($followPage-1)*48)+count($rows)) ?> من <?= intf($followTotal) ?> إذن مطابق</div>
    <?php partial('pager', ['page' => $followPage, 'pages' => $followPages,
                          'qs' => $followQs, 'label' => 'صفحات متابعة الأذونات']); ?>
  <?php endif; ?>

<?php elseif ($mode === 'movements'): ?>
  <?php // ============ ملخص الحركات ============ ?>
  <div class="pv-tools">
    <form method="get" action="<?= url('permits') ?>">
      <input type="hidden" name="view" value="movements">
      <input type="search" name="q" value="<?= e($q ?? '') ?>" placeholder="بحث">
      <button class="btn sm" type="submit">تصفية</button>
      <?php if(($q ?? '')!==''): ?><a class="btn ghost sm" href="<?= url('permits?view=movements') ?>">إلغاء</a><?php endif; ?>
    </form>
    <div class="spacer"></div>
    <span class="pv-count"><span class="num"><?= intf(count($moves)) ?></span> حركة</span>
  </div>

  <?php if (!$moves): ?>
    <div class="pv-empty"><div class="ic"><?= icon('shuffle', 14) ?></div>لا حركات — أنشئ إذناً وأضف حركاته.</div>
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
              <?php if($m['client']): ?><span class="dkt-tag"><?= icon('user', 14) ?> <?= e($m['client']) ?></span><?php endif; ?>
              <span class="dkt-tag"><?= icon('truck', 14) ?> <span class="num"><?= intf($m['bills']) ?></span> بوليصة</span>
              <span class="dkt-tag"><?= icon('package', 14) ?> <span class="num"><?= intf($m['containers']) ?></span> حاوية</span>
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
  <?php
    $co = $co ?? ''; $cl = $cl ?? '';
    $recordQs = static function(array $overrides=[]) use ($q,$type,$date,$st,$co,$cl): string {
      $query = array_merge(['view'=>'records','q'=>$q,'type'=>$type,'date'=>$date,'st'=>$st,'co'=>$co,'cl'=>$cl,'page'=>1],$overrides);
      return http_build_query(array_filter($query,static fn($value,$key)=>$value!==''&&!($key==='page'&&(int)$value===1),ARRAY_FILTER_USE_BOTH));
    };
    $recordUrl = static fn(array $ov=[]): string => url('permits?'.$recordQs($ov));
  ?>
  <div class="pv-tools">
    <form method="get" action="<?= url('permits') ?>">
      <input type="hidden" name="view" value="records">
      <input type="hidden" name="co" value="<?= e($co) ?>">
      <input type="hidden" name="cl" value="<?= e($cl) ?>">
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="بحث">
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
      <?php if($q||$type||$date||($st??'')||$co||$cl): ?><a class="btn ghost sm" href="<?= url('permits?view=records') ?>">إلغاء</a><?php endif; ?>
    </form>
    <div class="spacer"></div>
    <span class="pv-count"><span class="num"><?= intf($total) ?></span> إصدار</span>
  </div>

  <?php // أزرار الشركات/الوكلاء المتكررين — تظهر تلقائيًا لأي طرف له أكثر من إصدار
    $pcCoList = $coChips ?? []; $pcClList = $clChips ?? [];
    $pcCo = $co; $pcCl = $cl; $pcUrl = $recordUrl;
    include __DIR__ . '/_party_chips.php';
  ?>

  <?php if ($co!=='' || $cl!==''): ?>
    <div class="pv-viewbar">
      <span class="pvv-active"><?= icon('search', 14) ?> معروض الآن:
        <?php if($co!==''): ?><b><?= e($co) ?></b><?php endif; ?>
        <?php if($co!=='' && $cl!==''): ?> · <?php endif; ?>
        <?php if($cl!==''): ?><b><?= e($cl) ?></b><?php endif; ?>
        — <span class="num"><?= intf($total) ?></span> إصدار
      </span>
      <a class="pvv-x" href="<?= e($recordUrl(['co'=>'','cl'=>''])) ?>"><?= icon('x', 14) ?> إلغاء التصفية</a>
    </div>
  <?php endif; ?>

  <?php
    // رجوع محلي آمن لنفس الفلتر الحالي بعد أي إجراء جماعي
    $pvBack = ltrim((string)($_SERVER['REQUEST_URI'] ?? 'permits?view=records'), '/');
    $agentOptions   = $agentOptions   ?? [];
    $companyOptions = $companyOptions ?? [];
  ?>
  <!-- شريط الإجراءات الجماعية (يظهر عند اختيار أذونات) -->
  <div class="pv-batchbar" id="pvBatchBar">
    <label class="pbb-all"><input type="checkbox" id="pvAll"> تحديد الكل</label>
    <span class="pbb-count"><span class="num" id="pvCount">0</span> إذن محدَّد</span>
    <span style="flex:1"></span>
    <button type="button" class="btn gold sm pv-bulk-btn" id="pvPrintBtn" disabled onclick="printSelectedPermits()"><?= icon('file-text', 14) ?> طباعة</button>
    <button type="button" class="btn ghost sm pv-bulk-btn" data-bulk="close"  disabled><?= icon('check', 14) ?> إتمام وإغلاق</button>
    <button type="button" class="btn ghost sm pv-bulk-btn" data-bulk="reopen" disabled><?= icon('alert', 14) ?> إعادة فتح</button>
    <button type="button" class="btn ghost sm pv-bulk-btn" id="pvPartyBtn"    disabled><?= icon('user', 14) ?> تغيير الوكيل/الشركة</button>
    <button type="button" class="btn ghost sm pv-bulk-btn" data-bulk="csv"    disabled>⬇️ تصدير CSV</button>
    <button type="button" class="btn ghost sm pv-bulk-btn pv-danger" data-bulk="delete" disabled><?= icon('trash', 14) ?> حذف</button>
    <button type="button" class="btn ghost sm pv-bulk-btn" id="pvClearSel"    disabled>إلغاء التحديد</button>
  </div>

  <!-- فورم واحد يحمل كل الإجراءات؛ الـ ids تُملأ بالـ JS وقت الإرسال -->
  <form method="post" action="<?= url('permits/bulk') ?>" id="pvBulkForm" style="display:none">
    <?= csrfField() ?>
    <input type="hidden" name="back" value="<?= e($pvBack) ?>">
    <input type="hidden" name="op" id="pvBulkOp" value="">
    <div id="pvBulkIds"></div>
    <div id="pvBulkExtra"></div>
  </form>

  <!-- مودال تغيير الوكيل/الشركة -->
  <div class="pv-modal" id="pvPartyModal" hidden>
    <div class="pv-modal-card" role="dialog" aria-modal="true" aria-labelledby="pvPartyTitle">
      <div class="pv-modal-head">
        <b id="pvPartyTitle">تغيير الوكيل/الشركة للأذونات المحدَّدة</b>
        <button type="button" class="pv-modal-x" aria-label="إغلاق">×</button>
      </div>
      <div class="pv-modal-body">
        <p class="pv-hint">اكتب الاسم اللي عايز توحّده على <b><span id="pvPartyCount">0</span></b> إذن. سيب الخانة فاضية لو مش عايز تغيّرها.
          الأسماء بتمرّ على حوكمة الأسماء زي فورم الإذن (توحيد التهجئة + إيقاف الاسم القريب).</p>
        <label class="pv-lbl">الوكيل (العميل)</label>
        <input type="text" id="pvPartyClient" name="client" form="pvBulkForm" list="pvAgentOpts" maxlength="150" autocomplete="off" data-smart-name="client" placeholder="اتركه فاضي = بلا تغيير">
        <datalist id="pvAgentOpts"><?php foreach ($agentOptions as $n): ?><option value="<?= e($n) ?>"><?php endforeach; ?></datalist>
        <label class="pv-lbl">لصالح شركة</label>
        <input type="text" id="pvPartyCompany" name="company" form="pvBulkForm" list="pvCompanyOpts" maxlength="150" autocomplete="off" data-smart-name="company" placeholder="اتركه فاضي = بلا تغيير">
        <datalist id="pvCompanyOpts"><?php foreach ($companyOptions as $n): ?><option value="<?= e($n) ?>"><?php endforeach; ?></datalist>
        <div class="pv-modal-actions">
          <button type="button" class="btn gold" id="pvPartySave"><?= icon('download', 14) ?> طبّق على المحدَّد</button>
          <button type="button" class="btn ghost pv-modal-x">إلغاء</button>
        </div>
      </div>
    </div>
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
          <tr><td colspan="14"><div class="pv-empty sm"><div class="ic"><?= icon('folder', 14) ?></div>لا نتائج.</div></td></tr>
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
            <td class="txt"><?php if(($r['company']??'')!==''): ?>
              <a class="pv-plnk" href="<?= e($recordUrl(['co'=>$r['company'],'page'=>1])) ?>" onclick="event.stopPropagation()" title="اعرض أذونات هذه الشركة فقط"><?= e($r['company']) ?></a>
            <?php else: ?>—<?php endif; ?></td>
            <td class="txt"><?php if(($r['client']??'')!==''): ?>
              <a class="pv-plnk" href="<?= e($recordUrl(['cl'=>$r['client'],'page'=>1])) ?>" onclick="event.stopPropagation()" title="اعرض أذونات هذا الوكيل فقط"><?= e($r['client']) ?></a>
            <?php else: ?>—<?php endif; ?></td>
            <td class="num"><?= intf($r['bills']) ?></td>
            <td class="num"><?php if(($r['containers']??0)>0): ?><?= intf($r['containers']) ?><?php if(($r['container_type']??'')!==''): ?>×<?= e($r['container_type']) ?><?php endif; else: ?>—<?php endif; ?></td>
            <td class="num pos"><?= money($r['custody'],false) ?></td>
            <td class="num neg"><?= money($r['expense'],false) ?></td>
            <td class="num pos"><?= money($r['fees'],false) ?></td>
            <td class="num"><?= money($r['remaining'],false) ?></td>
            <td><span class="stamp mini <?= ($r['status']??'open')==='closed'?'ready':'wip' ?>"><?= ($r['status']??'open')==='closed'?'مقفول':'جارٍ' ?></span></td>
            <td onclick="event.stopPropagation()"><div class="actions">
              <a class="edit" href="<?= url('permits/'.$r['id']) ?>" title="فتح"><?= icon('folder', 14) ?></a>
              <form class="confirm-delete" method="post" action="<?= url('permits/'.$r['id'].'/delete') ?>" style="margin:0">
                <?= csrfField() ?><button class="del" type="submit" title="حذف"><?= icon('trash', 14) ?></button>
              </form>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="pv-tot">
            <td data-no-sort></td>
            <td class="txt" colspan="2">إجمالي الصفحة (<?= intf(count($rows)) ?>)</td>
            <td></td><td></td><td></td>
            <td class="num"><?= intf($tBills) ?></td>
            <td class="num"><?= $tContainers>0?intf($tContainers):'—' ?></td>
            <td class="num pos"><?= money($tCustody,false) ?></td>
            <td class="num neg"><?= money($tExpense,false) ?></td>
            <td class="num pos"><?= money($tFees,false) ?></td>
            <td class="num"><?= money($tRemaining,false) ?></td>
            <td></td><td></td>
          </tr>
          <?php if(!empty($grand)): ?>
          <tr class="pv-tot pv-grand">
            <td data-no-sort></td>
            <td class="txt" colspan="2">إجمالي كل النتائج (<?= intf($total) ?>)</td>
            <td></td><td></td><td></td>
            <td class="num"><?= intf($grand['bills']) ?></td>
            <td class="num"><?= ((int)$grand['containers'])>0?intf($grand['containers']):'—' ?></td>
            <td class="num pos"><?= money($grand['custody'],false) ?></td>
            <td class="num neg"><?= money($grand['expense'],false) ?></td>
            <td class="num pos"><?= money($grand['fees'],false) ?></td>
            <td class="num"><?= money($grand['remaining'],false) ?></td>
            <td></td><td></td>
          </tr>
          <?php endif; ?>
        </tfoot>
        <?php endif; ?>
    </table>
  </div>
  <?php if(($pages??1)>1): // $recordQs معرَّف أعلى الشاشة ويحمل تصفية الشركة/الوكيل ?>
  <?php partial('pager', ['page' => $page, 'pages' => $pages,
                        'qs' => $recordQs, 'label' => 'صفحات سجل الأذونات']); ?>
  <?php endif; ?>
<?php endif; ?>

<?php if ($mode === 'records'): ?>
<style>
  .pv-batchbar{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin:0 0 10px;
    padding:9px 14px;background:#fff;border:1px solid var(--line,#E2E7EE);border-radius:var(--radius-2)}
  .pv-batchbar .pbb-all{display:inline-flex;align-items:center;gap:7px;font-weight:500;cursor:pointer;color:var(--navy,#15355B)}
  .pv-batchbar .pbb-all input,.pv-tbl .pv-pick input{width:17px;height:17px;cursor:pointer;accent-color:var(--gold,#C9A227)}
  .pv-batchbar .pbb-count{color:var(--muted,#5A6472);font-weight:500}
  .pv-batchbar .pv-bulk-btn:disabled{opacity:.45;cursor:not-allowed}
  .pv-batchbar .pv-bulk-btn.pv-danger{color:#b32020;border-color:#e7bcbc}
  .pv-batchbar .pv-bulk-btn.pv-danger:not(:disabled):hover{background:#fdf1f1}
  .pv-modal[hidden]{display:none}
  .pv-modal{position:fixed;inset:0;z-index:1000;background:rgba(13,29,49,.58);display:flex;align-items:center;justify-content:center;padding:20px}
  .pv-modal-card{width:min(560px,96vw);max-height:88vh;overflow:auto;background:#fff;border-radius:var(--radius-3);box-shadow:0 24px 70px rgba(0,0,0,.28)}
  .pv-modal-head{display:flex;justify-content:space-between;align-items:center;padding:15px 18px;background:var(--navy,#15355B);color:#fff}
  .pv-modal-x{border:0;background:transparent;color:inherit;font-size:var(--fs-6);cursor:pointer;line-height:1}
  .pv-modal-body{padding:18px}
  .pv-modal-body .pv-lbl{display:block;font-weight:600;color:var(--navy,#15355B);font-size:var(--fs-2);margin-top:12px}
  .pv-modal-body input[type=text]{display:block;width:100%;margin-top:6px;border:1px solid var(--line2,#D6DDE6);border-radius:var(--radius-1);padding:9px 11px;font-family:inherit}
  .pv-hint{font-size:var(--fs-1);color:var(--grey,#5A6472);line-height:1.7;margin:0 0 6px}
  .pv-modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:18px}
  .pv-tbl th.pv-pick,.pv-tbl td.pv-pick{width:38px;text-align:center;padding-inline:6px}
  .pv-tbl tfoot .pv-tot td{background:var(--navy,#15355B);color:#fff;font-weight:600;padding:11px 12px;
    text-align:center;white-space:nowrap;border-top:2px solid var(--gold,#C9A227)}
  .pv-tbl tfoot .pv-tot td.txt{text-align:right}
  .pv-tbl tfoot .pv-tot td.num{font-family:var(--mono);font-feature-settings:"tnum" 1}
  .pv-tbl tfoot .pv-tot td.pos{color:#a7e9bf} .pv-tbl tfoot .pv-tot td.neg{color:#ffc4c4}
  .pv-tbl tfoot .pv-grand td{background:#0f2947;border-top:2px solid var(--gold,#C9A227)}
  .pv-tbl tfoot .pv-grand td.txt{color:var(--gold,#C9A227)}
</style>
<script>
(function(){
  function boxes(){ return Array.prototype.slice.call(document.querySelectorAll('.pv-row-pick')); }
  var allTop = document.getElementById('pvAllTop');
  var allBar = document.getElementById('pvAll');
  var cnt = document.getElementById('pvCount');
  var btn = document.getElementById('pvPrintBtn');
  if (!btn) return;
  var actionBtns = Array.prototype.slice.call(document.querySelectorAll('.pv-bulk-btn'));
  function selectedIds(){ return boxes().filter(function(b){ return b.checked; }).map(function(b){ return b.value; }); }
  function sync(){
    var bs = boxes();
    var sel = bs.filter(function(b){ return b.checked; });
    if (cnt) cnt.textContent = sel.length;
    actionBtns.forEach(function(b){ b.disabled = sel.length === 0; });
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
    var ids = selectedIds();
    if (!ids.length) return;
    window.open('<?= url('permits/print-batch') ?>?ids=' + ids.join(','), '_blank');
  };

  // --- الإجراءات الجماعية: فورم واحد يتعبّى وقت الإرسال ---
  var form  = document.getElementById('pvBulkForm');
  var opFld = document.getElementById('pvBulkOp');
  var idBox = document.getElementById('pvBulkIds');
  var exBox = document.getElementById('pvBulkExtra');

  function hidden(name, value){
    var i = document.createElement('input');
    i.type = 'hidden'; i.name = name; i.value = value;
    return i;
  }
  function submitBulk(op, extra){
    var ids = selectedIds();
    if (!ids.length) return;
    opFld.value = op;
    idBox.innerHTML = ''; exBox.innerHTML = '';
    ids.forEach(function(v){ idBox.appendChild(hidden('ids[]', v)); });
    Object.keys(extra || {}).forEach(function(k){ exBox.appendChild(hidden(k, extra[k])); });
    if (form.requestSubmit) form.requestSubmit(); else form.submit();
  }

  var CONFIRM = {
    close:  function(n){ return 'إتمام وإغلاق ' + n + ' إذن؟ الأذونات المقفولة بالفعل هتتخطّى.'; },
    reopen: function(n){ return 'إعادة فتح ' + n + ' إذن؟'; },
    delete: function(n){ return 'حذف ' + n + ' إصدار نهائيًا؟ البوالص المرتبطة هتتفكّ من الإذن ومش هتتحذف. أي إذن له مستند مالي صادر هيتخطّى.'; }
  };
  actionBtns.forEach(function(b){
    var op = b.getAttribute('data-bulk');
    if (!op) return;
    b.addEventListener('click', function(){
      var n = selectedIds().length;
      if (!n) return;
      if (CONFIRM[op]) {
        window.AppUI.confirm(CONFIRM[op](n), function(){ submitBulk(op, {}); }, {danger:op==='delete'});
      } else { submitBulk(op, {}); }
    });
  });
  var clearBtn = document.getElementById('pvClearSel');
  if (clearBtn) clearBtn.addEventListener('click', function(){ selectAll(false); });

  // --- مودال تغيير الوكيل/الشركة ---
  var modal = document.getElementById('pvPartyModal');
  var openBtn = document.getElementById('pvPartyBtn');
  if (modal && openBtn) {
    var clientIn  = document.getElementById('pvPartyClient');
    var companyIn = document.getElementById('pvPartyCompany');
    function closeModal(){ modal.hidden = true; }
    openBtn.addEventListener('click', function(){
      if (!selectedIds().length) return;
      document.getElementById('pvPartyCount').textContent = selectedIds().length;
      modal.hidden = false;
      clientIn.focus();
    });
    modal.addEventListener('click', function(e){
      if (e.target === modal || (e.target.classList && e.target.classList.contains('pv-modal-x'))) closeModal();
    });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && !modal.hidden) closeModal(); });
    document.getElementById('pvPartySave').addEventListener('click', function(){
      var c = clientIn.value.trim(), co = companyIn.value.trim();
      if (!c && !co) { window.AppUI.notice('اكتب الوكيل أو الشركة (أو الاتنين).'); return; }
      // خانتا الاسم مرتبطتان بالفورم عبر form="pvBulkForm" فتُرسَلان تلقائيًا مع __newname_*
      // اللي بيضيفه محرّك حوكمة الأسماء عند تأكيد «ده اسم جديد». الخادم هو الحاكم النهائي.
      var conflict = [clientIn, companyIn].filter(function(i){
        var v = i.value.trim();
        return v && i.dataset.conflict && i.dataset.newConfirmed !== '1' && v !== i.dataset.conflict;
      });
      if (conflict.length) {
        window.AppUI.notice('فيه اسم قريب من اسم مخزّن — اختَر الموجود أو أكّد أنه اسم جديد.');
        conflict[0].focus();
        return;
      }
      window.AppUI.confirm('تطبيق الأسماء على ' + selectedIds().length + ' إذن؟', function(){ submitBulk('party', {}); }, {danger:false});
    });
  }
  sync();
})();
</script>
<?php endif; ?>

</div>
