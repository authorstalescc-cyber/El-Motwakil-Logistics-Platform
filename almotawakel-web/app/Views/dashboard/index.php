<?php
use App\Core\App;
use App\Models\Permit;

/* تحية حسب الوقت */
$h = (int) date('H');
$greet = $h < 12 ? 'صباح الخير' : ($h < 17 ? 'نهارك سعيد' : 'مساء الخير');
$company = App::setting('company_name', 'شركة المتوكل');
$cur = App::setting('currency', 'ج.م');
$isToday = ($date === date('Y-m-d'));

/* بطاقات المؤشرات (بدون نولون، بدون المتبقي) — كل واحدة تفتح بوب-أب بالمستهدف فقط */
$kpis = [
  ['permits_open','أذونات مفتوحة',   'navy',   'folder',        $permits['open'],       0, '',      'الأذونات المفتوحة'],
  ['ready',        'جاهز للفوترة',    'teal',   'receipt',       $readySum['count'],     0, '',      'الأذونات الجاهزة للفوترة'],
  ['expenses',     'مصروفات اليوم',   'red',    'trending-down', $daily['expenses'],     1, $cur,    'مصروفات '.dfmt($date)],
  ['receipts',     'إيصالات اليوم',   'gold',   'clipboard',     $daily['receipts_cnt'], 0, 'إيصال', 'إيصالات '.dfmt($date)],
  ['custody',      'رصيد العهدة',     'purple', 'briefcase',     $custody,               1, $cur,    'تفاصيل رصيد العهدة'],
];
/* عهدة المناديب — كارت سادس يظهر بس لو الوحدة متاحة (v35) */
$rp = $repsPanel;
if ($rp['available']) {
  $kpis[] = ['reps','عهدة المناديب','sader','users',$rp['totals']['outstanding'],1,$cur,'متابعة عهدة المناديب ومدخلاتهم'];
}

/* خريطة أنواع سجل النشاط */
$kindMeta = [
  'bawalis'    => ['truck',    'بوليصة',      'navy',  fn($r)=>url('bawalis/'.$r['id'])],
  /* أرشيف: مفيش صفحة لـshamil فالصف بيتعرض بلا رابط بدل ما يوصّل 404 */
  'shamil'     => ['layers',   'فاتورة (أرشيف)', 'teal',  fn($r)=>null],
  'takhlis'    => ['clipboard','شهادة تخليص',  'purple',fn($r)=>url('takhlis/'.$r['id'].'/edit')],
  'generators' => ['cpu',      'مولدات',       'gold',  fn($r)=>url('generators/'.$r['id'].'/edit')],
  'permits'    => ['folder',   'إصدار',        'green', fn($r)=>url('permits/'.$r['id'])],
  'payment'    => ['banknote', 'سداد',          'green', fn($r)=>url('settlements')],
];

/* ── مُصيّرات مشتركة (لتفادي التكرار بين اللوحات والبوب-أب) ── */
$rowPermit = function(array $p, string $mode) {
  $th = Permit::theme($p['permit_type']);
  ob_start(); ?>
  <?php if ($mode==='ready'): ?><div class="d-row"><?php else: ?><a class="d-row" href="<?= url('permits/'.$p['id']) ?>"><?php endif; ?>
    <span class="d-type <?= e($th['cls']) ?>" title="<?= e($th['full']) ?>"><?= e($th['arrow']) ?></span>
    <div class="d-row-body">
      <div class="d-row-t"><b><?= e($p['party']) ?></b><?php if (!empty($p['permit_no'])): ?><span class="d-ref">#<?= e($p['permit_no']) ?></span><?php endif; ?></div>
      <div class="d-row-m">
        <?php if (!empty($p['ct_label'])): ?><span class="d-cchip"><?= icon('package',12) ?> <?= e($p['ct_label']) ?></span><?php endif; ?>
        <?php if ($mode==='ready'): ?><span><?= icon('truck',12) ?> <?= intf($p['bills']) ?> بوليصة</span><span><?= icon('calendar',12) ?> <?= e(dfmt($p['permit_date'])) ?></span>
        <?php else: ?><span><?= icon('layers',12) ?> <?= intf($p['stages']) ?> بند</span><span><?= icon('clock',12) ?> <?= e(dfmt($p['last_activity'])) ?></span><?php endif; ?>
      </div>
    </div>
    <?php if ($mode==='ready'): ?><a class="d-act-btn green" href="<?= url('permits/'.$p['id'].'/invoice') ?>">فوترة <?= icon('chevron',13) ?></a></div>
    <?php else: ?><span class="d-go"><?= icon('chevron',16) ?></span></a><?php endif; ?>
  <?php return ob_get_clean();
};
$rowFeed = function(array $f, int $i = 0) {
  $rc = !empty($f['is_receipt']);
  ob_start(); ?>
  <div class="d-feed-row" style="--i:<?= $i ?>">
    <span class="d-feed-ic <?= $rc?'rc':'ex' ?>"><?= icon($rc?'clipboard':'trending-down',16) ?></span>
    <div class="d-feed-body">
      <div class="d-feed-t"><b><?= e($f['label'] ?: ($rc?'إيصال':'مصروف')) ?></b>
        <?php if ($f['ref_kind']==='permit' && !empty($f['ref'])): ?><span class="d-feed-ref permit"><?= icon('folder',11) ?> إذن #<?= e($f['ref']) ?></span>
        <?php elseif ($f['ref_kind']==='bill' && !empty($f['ref'])): ?><span class="d-feed-ref bill"><?= icon('truck',11) ?> بوليصة #<?= e($f['ref']) ?></span>
        <?php else: ?><span class="d-feed-ref manual"><?= icon('wallet',11) ?> يدوي</span><?php endif; ?>
      </div>
      <span class="d-feed-note"><?= e(($f['owner'] ? $f['owner'].' — ' : '').($f['note'] ?: '')) ?: '—' ?></span>
    </div>
    <div class="d-feed-side"><b class="<?= $rc?'rc':'ex' ?>"><?= money0($f['amount']) ?></b><span><?= e(dfmt($f['d'])) ?></span></div>
  </div>
  <?php return ob_get_clean();
};

/* صف مُدخَل مندوب — مشترك بين اللوحة والبوب-أب */
$subMeta = [
  'pending'  => ['hourglass',    'مستنّي',  'wait'],
  'approved' => ['check-circle', 'معتمد',   'ok'],
  'rejected' => ['alert',        'مرفوض',   'no'],
];
$rowSub = function(array $s) use ($subMeta) {
  [$sic,$slbl,$scls] = $subMeta[$s['status']] ?? ['clock','—','wait'];
  $isRc = ($s['kind'] === 'receipt');
  ob_start(); ?>
  <div class="rp-sub <?= $scls ?>">
    <span class="rp-sub-ic <?= $isRc?'rc':'ex' ?>"><?= icon($isRc?'clipboard':'trending-down',15) ?></span>
    <div class="rp-sub-body">
      <div class="rp-sub-t">
        <b><?= e($s['note'] ?: ($isRc?'إيصال':'مصروف')) ?></b>
        <?php if ((int)($s['qty'] ?? 1) > 1): ?><span class="rp-qty">×<?= intf($s['qty']) ?></span><?php endif; ?>
        <?php if (!empty($s['photo'])): ?><span class="rp-cam" title="مرفق صورة">📎</span><?php endif; ?>
      </div>
      <div class="rp-sub-m">
        <span class="rp-who"><?= icon('user',11) ?> <?= e($s['rep_name']) ?></span>
        <?php if (!empty($s['permit_no'])): ?>
          <a class="rp-ref" href="<?= url('permits/'.$s['permit_id']) ?>"><?= icon('folder',11) ?> إذن #<?= e($s['permit_no']) ?></a>
        <?php endif; ?>
        <span><?= icon('calendar',11) ?> <?= e(dfmt($s['entry_date'])) ?></span>
      </div>
      <?php if ($s['status']==='rejected' && !empty($s['review_note'])): ?>
        <span class="rp-why"><?= icon('alert',11) ?> <?= e($s['review_note']) ?></span>
      <?php endif; ?>
    </div>
    <div class="rp-sub-side">
      <b><?= money0($s['amount']) ?></b>
      <span class="rp-pill <?= $scls ?>"><?= icon($sic,11) ?> <?= e($slbl) ?></span>
    </div>
  </div>
  <?php return ob_get_clean();
};
?>
<div class="dash2">

<!-- ═══════════════ ترويسة + شريط متحرك ═══════════════ -->
<section class="d-hero">
  <div class="d-hero-glow"></div>
  <div class="d-hero-left">
    <span class="d-hero-eyebrow"><?= icon('sparkles',14) ?> <?= e($company) ?></span>
    <h1 class="d-hero-title"><?= e($greet) ?> <span class="wave">👋</span></h1>
    <p class="d-hero-sub">دي كل الحركة على تاريخ العمل المحدَّد — النقل والتخليص الجمركي.</p>
    <div class="d-hero-chips">
      <span class="d-hchip <?= $isToday?'':'warn' ?>"><?= icon('calendar',14) ?> <?= e(dfmt($date)) ?><?= $isToday?' · النهاردة':'' ?></span>
      <button type="button" class="d-hchip" data-pop="permits_open" data-title="الأذونات المفتوحة"><?= icon('folder',14) ?> <?= intf($permits['open']) ?> إذن مفتوح</button>
      <button type="button" class="d-hchip go" data-pop="ready" data-title="الأذونات الجاهزة للفوترة"><?= icon('receipt',14) ?> <?= intf($readySum['count']) ?> جاهز للفوترة</button>
      <button type="button" class="d-hchip quick" id="quickEntryOpen"><?= icon('plus',14) ?> إدخال سريع للإيصالات والمصاريف</button>
    </div>
  </div>

  <!-- شريط "آخر ما تمت إضافته" -->
  <div class="d-live">
    <div class="d-live-head"><span class="d-live-dot"></span> آخر ما تمت إضافته</div>
    <div class="d-live-stage" id="dLive">
      <?php if (!$feed): ?>
        <div class="d-live-item on"><span class="d-live-empty">لا توجد حركات على هذا التاريخ.</span></div>
      <?php else: foreach ($feed as $i => $f): $rc = !empty($f['is_receipt']); ?>
      <div class="d-live-item<?= $i===0?' on':'' ?>">
        <span class="d-live-ic <?= $rc?'rc':'ex' ?>"><?= icon($rc?'clipboard':'trending-down',18) ?></span>
        <div class="d-live-body">
          <b><?= e($f['label'] ?: ($rc?'إيصال':'مصروف')) ?><?php
            if ($f['ref_kind']==='permit' && !empty($f['ref'])) echo ' <small class="lref">· إذن #'.e($f['ref']).'</small>';
            elseif ($f['ref_kind']==='bill' && !empty($f['ref'])) echo ' <small class="lref">· بوليصة #'.e($f['ref']).'</small>';
            elseif ($f['owner']) echo ' <small>· '.e($f['owner']).'</small>'; ?></b>
          <span><?= e(($f['owner'] && $f['ref_kind']!=='manual' ? $f['owner'].' — ' : '').($f['note'] ?: '')) ?: 'بدون تفاصيل' ?></span>
        </div>
        <div class="d-live-side"><b class="<?= $rc?'rc':'ex' ?>"><?= money0($f['amount']) ?></b><span><?= e(dfmt($f['d'])) ?></span></div>
      </div>
      <?php endforeach; endif; ?>
    </div>
    <div class="d-live-dots" id="dLiveDots"></div>
  </div>
</section>

<!-- ═══════════════ مؤشرات (عدّاد متحرك، تفتح بوب-أب) ═══════════════ -->
<div class="d-kpis <?= count($kpis)>=6 ? 'six' : 'five' ?>">
  <?php foreach ($kpis as [$pop,$lbl,$cls,$ic,$val,$dec,$suf,$ttl]): ?>
  <button type="button" class="d-kpi <?= $cls ?>" data-pop="<?= e($pop) ?>" data-title="<?= e($ttl) ?>">
    <span class="d-kpi-ic"><?= icon($ic,20) ?></span>
    <div class="d-kpi-val"><span class="count" data-to="<?= (float)$val ?>" data-dec="<?= $dec ?>">0</span><?php if ($suf): ?><span class="d-kpi-suf"><?= e($suf) ?></span><?php endif; ?></div>
    <div class="d-kpi-lbl"><?= e($lbl) ?> <span class="d-kpi-eye"><?= icon('search',12) ?></span></div>
  </button>
  <?php endforeach; ?>
</div>

<!-- ═══════════════ جاهز للفوترة + أذونات مفتوحة ═══════════════ -->
<div class="d-grid2 mb">
  <div class="d-card">
    <div class="d-card-head">
      <h3><?= icon('receipt',18) ?> جاهز للفوترة<?php if ($readySum['count']>0): ?><span class="d-badge red"><?= intf($readySum['count']) ?></span><?php endif; ?></h3>
      <button type="button" class="btn ghost sm" data-pop="ready" data-title="الأذونات الجاهزة للفوترة">عرض التفاصيل <?= icon('chevron',15) ?></button>
    </div>
    <div class="d-list" id="dashReadyList">
      <?php if (!$ready): ?>
        <div class="d-empty"><?= icon('check',26) ?><b>تمام!</b><span>مفيش أذونات مُغلقة مستنية فاتورة.</span></div>
      <?php else: foreach (array_slice($ready,0,8) as $p) echo $rowPermit($p,'ready'); endif; ?>
    </div>
  </div>
  <div class="d-card">
    <div class="d-card-head">
      <h3><?= icon('folder',18) ?> أذونات مفتوحة <span class="d-badge navy"><?= intf($permits['open']) ?></span></h3>
      <button type="button" class="btn ghost sm" data-pop="permits_open" data-title="الأذونات المفتوحة">عرض التفاصيل <?= icon('chevron',15) ?></button>
    </div>
    <div class="d-list" id="dashOpenList">
      <?php if (!$openList): ?>
        <div class="d-empty"><?= icon('folder',26) ?><b>لا يوجد</b><span>مفيش أذونات مفتوحة حاليًا.</span></div>
      <?php else: foreach (array_slice($openList,0,8) as $p) echo $rowPermit($p,'open'); endif; ?>
    </div>
  </div>
</div>

<!-- ═══════════════ عهدة المناديب ومدخلاتهم (قراءة فقط؛ الصرف المعتمد يدخل الخزنة) ═══════════════ -->
<?php if ($rp['available']): $rt = $rp['totals']; $rd = $rp['day']; ?>
<div class="d-sec-head">
  <?= icon('users',17) ?><span>عهدة المناديب ومدخلاتهم</span>
  <?php if ($rt['pending_cnt']>0): ?><em class="d-sec-year alert"><?= intf($rt['pending_cnt']) ?> مستنّي المراجعة</em><?php endif; ?>
</div>

<div class="rp-panel mb" id="dashRepsPanel">
  <!-- شريط الملخّص -->
  <div class="rp-strip">
    <div class="rp-stat"><span><?= icon('wallet',15) ?> نازل للمناديب</span><b><?= money0($rt['deposits']) ?></b></div>
    <div class="rp-stat"><span><?= icon('trending-down',15) ?> مصروف معتمد</span><b class="ex"><?= money0($rt['spent']) ?></b></div>
    <div class="rp-stat"><span><?= icon('hourglass',15) ?> مستنّي المراجعة</span><b class="wait"><?= money0($rt['pending_sum']) ?><small><?= intf($rt['pending_cnt']) ?> بند</small></b></div>
    <div class="rp-stat hi"><span><?= icon('users',15) ?> برّه مع المناديب</span><b><?= money0($rt['outstanding']) ?><small><?= intf($rt['reps']) ?> مندوب</small></b></div>
  </div>

  <div class="rp-grid">
    <!-- كروت المناديب -->
    <div class="d-card">
      <div class="d-card-head">
        <h3><?= icon('briefcase',18) ?> أرصدة العهدة<?php if ($rt['low']>0): ?><span class="d-badge red"><?= intf($rt['low']) ?> محتاج تنزيل</span><?php endif; ?></h3>
        <a class="btn ghost sm" href="<?= url('rep-custody') ?>">إدارة العهدة <?= icon('chevron',15) ?></a>
      </div>
      <div class="rp-cards">
        <?php if (!$rp['reps']): ?>
          <div class="d-empty"><?= icon('users',26) ?><b>مفيش عهدة نازلة</b><span>نزّل عهدة لأي مندوب من صفحة عهدة المناديب.</span></div>
        <?php else: foreach ($rp['reps'] as $c): ?>
        <a class="rp-card<?= $c['low']?' low':'' ?>" href="<?= url('rep-custody/'.$c['id']) ?>">
          <div class="rp-card-top">
            <span class="rp-av"><?= e(mb_substr(trim($c['name']),0,1,'UTF-8')) ?></span>
            <div class="rp-id">
              <b><?= e($c['name']) ?></b>
              <span><?php if ($c['last_spend']): ?>آخر حركة <?= e(dfmt($c['last_spend'])) ?><?php else: ?>لسه مفيش حركة<?php endif; ?></span>
            </div>
            <div class="rp-bal">
              <b class="<?= $c['balance']>0?'pos':($c['balance']<0?'neg':'') ?>"><?= money0($c['balance']) ?></b>
              <span>فاضل معاه</span>
            </div>
          </div>
          <!-- شريط استهلاك العهدة: مصروف / مستنّي / مرتجع -->
          <div class="rp-bar" title="نازل <?= money0($c['deposits']) ?> · مصروف <?= money0($c['spent']) ?>">
            <i class="sp" style="width:<?= $c['pc_spent'] ?>%"></i>
            <i class="pd" style="width:<?= $c['pc_pending'] ?>%"></i>
            <i class="rt" style="width:<?= $c['pc_return'] ?>%"></i>
          </div>
          <div class="rp-card-foot">
            <span class="rp-tag"><?= icon('wallet',11) ?> نازل <?= money0($c['deposits']) ?></span>
            <span class="rp-tag ex"><?= icon('trending-down',11) ?> صرف <?= money0($c['spent']) ?></span>
            <?php if ($c['pending_cnt']>0): ?><span class="rp-tag wait"><?= icon('hourglass',11) ?> <?= intf($c['pending_cnt']) ?> مستنّي · <?= money0($c['pending_sum']) ?></span><?php endif; ?>
            <?php if ($c['low']): ?><span class="rp-tag low"><?= icon('alert',11) ?> محتاج تنزيل</span><?php endif; ?>
          </div>
        </a>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- آخر المدخلات -->
    <div class="d-card">
      <div class="d-card-head">
        <h3><?= icon('inbox',18) ?> آخر مدخلات المناديب<?php if ($rt['pending_cnt']>0): ?><span class="d-badge red"><?= intf($rt['pending_cnt']) ?></span><?php endif; ?></h3>
        <a class="btn ghost sm" href="<?= url('rep-custody/inbox') ?>">صندوق المراجعة <?= icon('chevron',15) ?></a>
      </div>
      <div class="rp-day">
        <div><span><?= icon('send',13) ?> اتبعت في <?= e(dfmt($date)) ?></span><b><?= intf($rd['sent_cnt']) ?> بند · <?= money0($rd['sent_sum']) ?></b></div>
        <div><span><?= icon('check-circle',13) ?> معتمد بتاريخه</span><b class="ok"><?= intf($rd['paid_cnt']) ?> بند · <?= money0($rd['paid_sum']) ?></b></div>
      </div>
      <div class="rp-subs">
        <?php if (!$rp['feed']): ?>
          <div class="d-empty"><?= icon('inbox',26) ?><b>الصندوق فاضي</b><span>المناديب لسه مبعتوش أي بند من الموبايل.</span></div>
        <?php else: foreach (array_slice($rp['feed'],0,7) as $s) echo $rowSub($s); endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ═══════════════ سجل المصروفات/الإيصالات + الملاحظات ═══════════════ -->
<div class="d-grid-feed mb">
  <div class="d-card">
    <div class="d-card-head">
      <h3><?= icon('trending-down',18) ?> آخر المصروفات والإيصالات</h3>
      <a class="btn ghost sm" href="<?= url('expenses') ?>">سجل المصروفات <?= icon('chevron',15) ?></a>
    </div>
    <div class="d-feed">
      <?php if (!$feed): ?>
        <div class="d-empty"><?= icon('trending-down',26) ?><b>لا يوجد</b><span>مفيش مصروفات أو إيصالات على هذا التاريخ.</span></div>
      <?php else: foreach ($feed as $i => $f) echo $rowFeed($f,$i); endif; ?>
    </div>
  </div>

  <div class="d-card d-notes">
    <?php $pending = 0; foreach ($notes as $n) if (empty($n['done'])) $pending++; ?>
    <div class="d-card-head"><h3><?= icon('file-text',18) ?> ملاحظات</h3><span class="chip"><?= intf($pending) ?><?php if (count($notes) > $pending): ?> / <?= intf(count($notes)) ?><?php endif; ?></span></div>
    <form class="d-note-form no-confirm" method="post" action="<?= url('notes') ?>">
      <?= csrfField() ?>
      <textarea name="body" rows="2" maxlength="500" placeholder="اكتب تذكير أو ملاحظة سريعة…" required></textarea>
      <div class="d-note-tools">
        <div class="d-note-colors">
          <?php foreach (['gold','navy','green','red','teal','purple'] as $ci): ?>
          <label class="d-note-color <?= $ci ?>"><input type="radio" name="color" value="<?= $ci ?>" <?= $ci==='gold'?'checked':'' ?>><span></span></label>
          <?php endforeach; ?>
        </div>
        <button class="btn sm" type="submit"><?= icon('plus',15) ?> إضافة</button>
      </div>
    </form>
    <div class="d-note-list">
      <?php if (!$notes): ?>
        <div class="d-empty sm"><?= icon('file-text',22) ?><span>مفيش ملاحظات. أضف أول واحدة.</span></div>
      <?php else: foreach ($notes as $n): $done = !empty($n['done']); ?>
      <div class="d-note <?= e($n['color'] ?: 'gold') ?><?= $done ? ' done' : '' ?>" id="note-<?= (int)$n['id'] ?>">
        <div class="d-note-main">
          <form method="post" action="<?= url('notes/'.$n['id'].'/toggle') ?>" class="no-confirm d-note-check-f">
            <?= csrfField() ?>
            <button class="d-note-check" type="submit" title="<?= $done?'إلغاء الإكمال':'تعليم كمكتمل' ?>"><?= $done ? icon('check-circle',16) : '' ?></button>
          </form>
          <p><?= nl2br(e($n['body'])) ?></p>
        </div>
        <div class="d-note-foot">
          <span><?= icon('clock',11) ?> <?php if ($done && $n['done_at']): ?>اكتملت <?= e(dfmt($n['done_at'])) ?><?php else: ?><?= e(dfmt($n['created_at'])) ?><?php endif; ?><?php if ($n['author']): ?> · <?= e($n['author']) ?><?php endif; ?></span>
          <div class="d-note-actions">
            <button type="button" class="d-note-ic" data-edit="<?= (int)$n['id'] ?>" title="تعديل"><?= icon('edit',13) ?></button>
            <form method="post" action="<?= url('notes/'.$n['id'].'/delete') ?>" class="confirm-delete" data-confirm="حذف الملاحظة؟" style="margin:0">
              <?= csrfField() ?>
              <button class="d-note-ic del" type="submit" title="حذف"><?= icon('trash',13) ?></button>
            </form>
          </div>
        </div>
        <!-- فورم التعديل (مخفي) -->
        <form method="post" action="<?= url('notes/'.$n['id']) ?>" class="no-confirm d-note-edit" id="edit-<?= (int)$n['id'] ?>" hidden>
          <?= csrfField() ?>
          <textarea name="body" rows="2" maxlength="500" required><?= e($n['body']) ?></textarea>
          <div class="d-note-tools">
            <div class="d-note-colors">
              <?php foreach (['gold','navy','green','red','teal','purple'] as $ci): ?>
              <label class="d-note-color <?= $ci ?>"><input type="radio" name="color" value="<?= $ci ?>" <?= ($n['color']?:'gold')===$ci?'checked':'' ?>><span></span></label>
              <?php endforeach; ?>
            </div>
            <div class="d-note-edit-btns">
              <button type="button" class="btn ghost sm" data-cancel="<?= (int)$n['id'] ?>">إلغاء</button>
              <button type="submit" class="btn sm"><?= icon('check',14) ?> حفظ</button>
            </div>
          </div>
        </form>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<!-- ═══════════════ الرسوم ═══════════════ -->
<div class="d-sec-head"><?= icon('bar-chart',17) ?><span>التحليلات والرسوم</span><em class="d-sec-year"><?= intf($year) ?></em></div>
<div class="d-card chart-full mb">
  <div class="d-card-head"><h3><?= icon('trending-down',16) ?> الأداء الشهري — المصروفات والمدفوعات (<?= intf($year) ?>)</h3></div>
  <div class="chart-box tall"><canvas id="chMonthly"></canvas></div>
</div>
<div class="d-charts3">
  <div class="d-card"><div class="d-card-head"><h3>حجم العمليات — آخر ١٤ يوم</h3></div><div class="chart-box"><canvas id="chTrend"></canvas></div></div>
  <div class="d-card"><div class="d-card-head"><h3>توزيع المصروفات</h3></div><div class="chart-box"><canvas id="chExpenses"></canvas></div></div>
  <div class="d-card"><div class="d-card-head"><h3>المتبقي للعملاء (الأعلى)</h3></div><div class="chart-box"><canvas id="chClients"></canvas></div></div>
</div>

<!-- ═══════════════ آخر الحركات ═══════════════ -->
<div class="d-card mb" style="margin-top:16px">
  <div class="d-card-head">
    <h3><?= icon('clock',18) ?> آخر الحركات والعمليات</h3>
    <a class="btn ghost sm" href="<?= url('search') ?>">بحث شامل <?= icon('chevron',15) ?></a>
  </div>
  <div class="activity">
    <?php if (!$activity): ?>
      <div class="empty">لا توجد عمليات مسجّلة بعد.</div>
    <?php else: foreach ($activity as $a):
      [$ic,$lbl,$col,$linkFn] = $kindMeta[$a['kind']] ?? ['file-text','عملية','slate',fn($r)=>null];
      $link = $linkFn($a);
      /* الصفوف الأرشيفية (shamil) مالهاش صفحة — تتعرض بلا رابط بدل ما
         href="" يعيد تحميل نفس الصفحة ويوهم المستخدم إنها لينك مكسور. */
      $tag = $link ? 'a' : 'div';
    ?>
    <<?= $tag ?> class="act <?= $col ?><?= $link ? '' : ' act-flat' ?>"<?= $link ? ' href="'.e($link).'"' : '' ?>>
      <span class="act-ic"><?= icon($ic,18) ?></span>
      <span class="act-body">
        <span class="act-top"><b><?= e($lbl) ?></b><?php if ($a['ref'] && $a['ref']!=='—'): ?><span class="act-ref">#<?= e($a['ref']) ?></span><?php endif; ?></span>
        <span class="act-sub"><?= e($a['party'] ?: 'بدون جهة') ?></span>
      </span>
      <span class="act-side">
        <?php if ($a['kind']==='permits'): ?><b class="act-amt"><?= intf($a['amount']) ?> حاوية</b>
        <?php elseif ($a['kind']==='payment'): ?><b class="act-amt pos">+ <?= money0($a['amount']) ?></b>
        <?php else: ?><b class="act-amt"><?= e(dfmt($a['d'])) ?></b><?php endif; ?>
        <span class="act-date"><?= e(dfmt($a['d'])) ?></span>
      </span>
    </<?= $tag ?>>
    <?php endforeach; endif; ?>
  </div>
</div>

</div><!-- /.dash2 -->

<!-- ═══════════════ البوب-أب (المستهدف فقط) ═══════════════ -->
<div class="d-modal" id="dModal" hidden>
  <div class="d-modal-back" data-close></div>
  <div class="d-modal-card" role="dialog" aria-modal="true">
    <div class="d-modal-head"><h3 id="dModalTitle"></h3><button type="button" class="d-modal-x" data-close aria-label="إغلاق">✕</button></div>
    <div class="d-modal-body" id="dModalBody"></div>
  </div>
</div>

<!-- ═══════════════ الإدخال السريع الجماعي ═══════════════ -->
<style>
.qe-restore{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;
  background:#fdf3e0;border:1px solid #f0dca6;color:#7a5e12;border-radius:12px;padding:10px 14px;margin-bottom:12px;font-size:.9rem}
.qe-restore b{color:#5a4310}
.qe-restore-btns{display:flex;gap:8px;flex-shrink:0}
</style>
<div class="qe-modal" id="quickEntryModal" hidden aria-hidden="true">
  <div class="qe-back" data-qe-close></div>
  <div class="qe-panel" role="dialog" aria-modal="true" aria-labelledby="qeTitle">
    <div class="qe-head">
      <div><span class="qe-kicker">إدخال جماعي آمن</span><h2 id="qeTitle">الإيصالات والمصاريف</h2></div>
      <div class="qe-steps"><span class="active" data-qe-dot="edit">1 · الإدخال</span><i></i><span data-qe-dot="review">2 · المراجعة</span></div>
      <button type="button" class="qe-x" data-qe-close aria-label="إغلاق">✕</button>
    </div>
    <aside class="qe-permit-browser" id="qePermitBrowser" hidden aria-label="اختيار الإذن">
      <button type="button" class="qe-browser-back" data-qe-browser-close aria-label="إغلاق"></button>
      <div class="qe-browser-sheet">
        <div class="qe-browser-head">
          <div><span>اختيار الإذن</span><b>ميّزه بالشركة والحاويات</b></div>
          <button type="button" data-qe-browser-close aria-label="إغلاق">✕</button>
        </div>
        <label class="qe-browser-search"><span aria-hidden="true">⌕</span><input type="search" id="qePermitSearch" placeholder="ابحث برقم الإذن، الشركة، العميل أو تصنيف الحاويات…" autocomplete="off"></label>
        <div class="qe-permit-results" id="qePermitResults"></div>
        <div class="qe-browser-empty" id="qePermitEmpty" hidden>لا يوجد إذن مطابق للبحث.</div>
      </div>
    </aside>
    <aside class="qe-permit-browser qe-movement-browser" id="qeMovementBrowser" hidden aria-label="اختيار الحركة">
      <button type="button" class="qe-browser-back" data-qe-movement-close aria-label="إغلاق"></button>
      <div class="qe-browser-sheet">
        <div class="qe-browser-head">
          <div><span>اختيار الحركة</span><b id="qeMovementPermitTitle">حركات الإذن</b></div>
          <button type="button" data-qe-movement-close aria-label="إغلاق">✕</button>
        </div>
        <div class="qe-movement-guide">اختر الحركة من بطاقتها. إذا كانت حركة واحدة فقط يختارها السيستم تلقائيًا.</div>
        <div class="qe-permit-results qe-movement-results" id="qeMovementResults"></div>
      </div>
    </aside>
    <form method="post" action="<?= url('dashboard/quick-entries') ?>" id="quickEntryForm" class="no-confirm">
      <?= csrfField() ?><input type="hidden" name="quick_token" value="<?= e($quickToken) ?>">
      <section class="qe-edit" data-qe-step="edit">
        <div class="qe-guide">
          <div><b>أدخل كل البنود أولًا</b><span>الصف الجديد ينسخ الإذن والحركة والتاريخ من الصف السابق تلقائيًا.</span></div>
          <div><b>راجع قبل التطبيق</b><span>لن يُحفظ أي شيء إلا بعد شاشة المراجعة، وأي خطأ يلغي الدفعة كلها.</span></div>
        </div>
        <div class="qe-rows" id="quickEntryRows"></div>
        <div class="qe-edit-foot">
          <button type="button" class="btn ghost" id="quickEntryAdd"><?= icon('plus',15) ?> إضافة صف آخر</button>
          <span class="qe-live-summary" id="quickLiveSummary">0 بند</span>
          <button type="button" class="btn gold" id="quickEntryReview">مراجعة الإدخالات <?= icon('chevron',15) ?></button>
        </div>
      </section>
      <section class="qe-review" data-qe-step="review" hidden>
        <div class="qe-review-summary">
          <div><span>عدد البنود</span><b id="qeReviewCount">0</b></div>
          <div class="receipt"><span>إجمالي الإيصالات</span><b id="qeReceiptTotal">0</b></div>
          <div class="expense"><span>إجمالي المصاريف</span><b id="qeExpenseTotal">0</b></div>
        </div>
        <div class="table-wrap"><table class="data qe-review-table">
          <thead><tr><th>#</th><th>الإذن والحركة</th><th>النوع</th><th>البيان</th><th>الكمية</th><th>سعر الوحدة</th><th>الإجمالي</th><th>الفعلي</th><th>التاريخ</th><th>الدفع</th></tr></thead>
          <tbody id="qeReviewBody"></tbody>
        </table></div>
        <div class="qe-apply-note"><?= icon('check-circle',17) ?><span><b>سيتم تطبيق كل الصفوف معًا.</b> إذا تعذّر بند واحد فلن يُحفظ أي بند.</span></div>
        <div class="qe-review-foot">
          <button type="button" class="btn ghost" id="quickEntryBack">رجوع للتعديل</button>
          <button type="submit" class="btn gold" id="quickEntryApply"><?= icon('check',16) ?> تطبيق داخل الأذونات</button>
        </div>
      </section>
    </form>
  </div>
</div>

<datalist id="qeReceiptNames"><?php foreach($quickReceipts as $name): ?><option value="<?= e($name) ?>"><?php endforeach; ?></datalist>
<datalist id="qeExpenseNames"><?php foreach($quickExpenses as $name): ?><option value="<?= e($name) ?>"><?php endforeach; ?></datalist>

<div id="popStore" hidden>
  <!-- أذونات مفتوحة -->
  <div data-pop="permits_open">
    <?php if (!$openList): ?><div class="d-empty"><?= icon('folder',26) ?><span>مفيش أذونات مفتوحة.</span></div>
    <?php else: ?><div class="d-modal-strip"><?= icon('folder',14) ?> <b><?= intf($permits['open']) ?></b> إذن مفتوح · <b><?= intf($permits['containers']) ?></b> حاوية</div>
      <div data-clone="#dashOpenList"></div><a class="btn ghost sm" style="margin:12px 14px" href="<?= url('permits') ?>">فتح متابعة كل الأذونات <?= icon('chevron',15) ?></a><?php endif; ?>
  </div>
  <!-- جاهز للفوترة -->
  <div data-pop="ready">
    <?php if (!$ready): ?><div class="d-empty"><?= icon('check',26) ?><b>تمام!</b><span>مفيش أذونات مستنية فاتورة.</span></div>
    <?php else: ?><div class="d-modal-strip gold"><?= icon('receipt',14) ?> <b><?= intf($readySum['count']) ?></b> إذن مُغلق جاهز للفوترة · <b><?= intf($readySum['containers']) ?></b> حاوية</div>
      <div data-clone="#dashReadyList"></div><a class="btn ghost sm" style="margin:12px 14px" href="<?= url('invoices/new') ?>">فتح كل الجاهز للفوترة <?= icon('chevron',15) ?></a><?php endif; ?>
  </div>
  <!-- مصروفات اليوم -->
  <div data-pop="expenses">
    <?php if (!$dayExp): ?><div class="d-empty"><?= icon('trending-down',26) ?><span>مفيش مصروفات على <?= e(dfmt($date)) ?>.</span></div>
    <?php else: ?><div class="d-modal-strip red"><?= icon('trending-down',14) ?> <b><?= intf(count($dayExp)) ?></b> بند · إجمالي <b><?= money0($daily['expenses']) ?></b></div>
      <div class="d-feed flat"><?php foreach (array_slice($dayExp,0,12) as $i=>$f) echo $rowFeed($f,$i); ?></div>
      <?php if(count($dayExp)>12): ?><a class="btn ghost sm" style="margin:12px 14px" href="<?= url('expenses?from='.rawurlencode($date).'&to='.rawurlencode($date)) ?>">عرض سجل اليوم كاملًا <?= icon('chevron',15) ?></a><?php endif; ?><?php endif; ?>
  </div>
  <!-- إيصالات اليوم -->
  <div data-pop="receipts">
    <?php if (!$dayRec): ?><div class="d-empty"><?= icon('clipboard',26) ?><span>مفيش إيصالات على <?= e(dfmt($date)) ?>.</span></div>
    <?php else: ?><div class="d-modal-strip gold"><?= icon('clipboard',14) ?> <b><?= intf($daily['receipts_cnt']) ?></b> إيصال · إجمالي <b><?= money0($daily['receipts_val']) ?></b></div>
      <div class="d-feed flat"><?php foreach (array_slice($dayRec,0,12) as $i=>$f) echo $rowFeed($f,$i); ?></div>
      <?php if(count($dayRec)>12): ?><a class="btn ghost sm" style="margin:12px 14px" href="<?= url('expenses?from='.rawurlencode($date).'&to='.rawurlencode($date)) ?>">عرض سجل اليوم كاملًا <?= icon('chevron',15) ?></a><?php endif; ?><?php endif; ?>
  </div>
  <!-- رصيد العهدة -->
  <div data-pop="custody">
    <div class="d-cust-hero"><span>الرصيد الحالي للعهدة</span><b class="<?= $custody<0?'neg':'pos' ?>"><?= money0($custody) ?></b></div>
    <div class="d-cust-grid">
      <div class="d-cust-tile"><span><?= icon('wallet',16) ?> عهد مضافة اليوم</span><b class="pos">+ <?= money0($custodyDay['topups_sum']) ?></b></div>
      <div class="d-cust-tile"><span><?= icon('truck',16) ?> عهد سائقين اليوم</span><b><?= money0($custodyDay['drivers_sum']) ?></b></div>
      <div class="d-cust-tile"><span><?= icon('trending-down',16) ?> مصروفات الأذونات اليوم</span><b class="neg">- <?= money0($custodyDay['day_expense']) ?></b></div>
    </div>
    <?php if ($custodyDay['topups'] || $custodyDay['drivers']): ?>
    <div class="d-cust-list">
      <?php foreach ($custodyDay['topups'] as $t): ?>
      <div class="d-feed-row"><span class="d-feed-ic rc"><?= icon('wallet',16) ?></span><div class="d-feed-body"><div class="d-feed-t"><b>إضافة عهدة</b><?php if($t['driver']): ?><span class="d-feed-ref manual"><?= e($t['driver']) ?></span><?php endif; ?></div><span class="d-feed-note"><?= e($t['note'] ?: '—') ?></span></div><div class="d-feed-side"><b class="rc">+ <?= money0($t['amount']) ?></b><span><?= e(dfmt($t['d'])) ?></span></div></div>
      <?php endforeach; foreach ($custodyDay['drivers'] as $t): ?>
      <div class="d-feed-row"><span class="d-feed-ic ex"><?= icon('truck',16) ?></span><div class="d-feed-body"><div class="d-feed-t"><b>عهدة سائق</b><span class="d-feed-ref bill"><?= e($t['driver']) ?></span></div><span class="d-feed-note"><?= e($t['note'] ?: '—') ?></span></div><div class="d-feed-side"><b class="ex"><?= money0($t['amount']) ?></b><span><?= e(dfmt($t['d'])) ?></span></div></div>
      <?php endforeach; ?>
    </div>
    <?php else: ?><div class="d-empty sm"><?= icon('briefcase',22) ?><span>مفيش حركة عهدة على <?= e(dfmt($date)) ?>.</span></div><?php endif; ?>
    <a class="btn ghost sm" style="margin:12px 14px" href="<?= url('custody') ?>">فتح محفظة العهدة <?= icon('chevron',15) ?></a>
  </div>
  <!-- عهدة المناديب -->
  <?php if ($rp['available']): $rt = $rp['totals']; ?>
  <div data-pop="reps">
    <div class="d-cust-hero"><span>الفلوس اللي برّه مع المناديب دلوقتي</span><b class="<?= $rt['outstanding']<0?'neg':'pos' ?>"><?= money0($rt['outstanding']) ?></b></div>
    <div class="d-modal-strip">تنزيل العهدة لا يخصم من الخزنة؛ المصروف المعتمد يُخصم بتاريخ صرفه، والمرتجع لا يُضاف للخزنة.</div>
    <div class="d-cust-grid">
      <div class="d-cust-tile"><span><?= icon('wallet',16) ?> نازل للمناديب</span><b class="pos"><?= money0($rt['deposits']) ?></b></div>
      <div class="d-cust-tile"><span><?= icon('trending-down',16) ?> مصروف معتمد</span><b class="neg">- <?= money0($rt['spent']) ?></b></div>
      <div class="d-cust-tile"><span><?= icon('hourglass',16) ?> مستنّي المراجعة</span><b><?= money0($rt['pending_sum']) ?></b></div>
    </div>
    <a class="btn ghost sm" style="margin:12px 14px" href="<?= url('rep-custody') ?>">فتح عهدة المناديب <?= icon('chevron',15) ?></a>
  </div>
  <?php endif; ?>
</div>

<script src="<?= url('assets/js/chart.umd.js') ?>"></script>
<script>
/* ── الإدخال السريع الجماعي ── */
(function(){
  const modal=document.getElementById('quickEntryModal'), form=document.getElementById('quickEntryForm'), rowsEl=document.getElementById('quickEntryRows');
  if(!modal||!form||!rowsEl)return;
  const browser=document.getElementById('qePermitBrowser'),permitSearch=document.getElementById('qePermitSearch'),permitResults=document.getElementById('qePermitResults'),permitEmpty=document.getElementById('qePermitEmpty');
  const movementBrowser=document.getElementById('qeMovementBrowser'),movementResults=document.getElementById('qeMovementResults'),movementTitle=document.getElementById('qeMovementPermitTitle');
  let permits=<?= js_json($quickPermits) ?>;
  let cards=<?= js_json(array_map(fn($c)=>['id'=>(int)$c['id'],'label'=>$c['name'].($c['owner']?' — '.$c['owner']:'')], $quickCards)) ?>;
  const old=<?= js_json($quickOld) ?>;
  let permitMap=new Map(permits.map(p=>[String(p.id),p]));
  let permitsLoaded=permits.length>0, permitsPromise=null;
  function fillDatalist(id,values){const list=document.getElementById(id);if(list)list.innerHTML=values.map(value=>`<option value="${esc(value)}"></option>`).join('');}
  function refreshCardOptions(){[...rowsEl.querySelectorAll('[name="visa_card_id[]"]')].forEach(select=>{const selected=select.value;select.innerHTML=cardOptions(selected);select.value=selected;});}
  async function loadPermits(){
    if(permitsLoaded)return permits;
    if(permitsPromise)return permitsPromise;
    permitsPromise=fetch(<?= js_json(url('dashboard/quick-options')) ?>,{headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'},credentials:'same-origin'})
      .then(async response=>{if(!response.ok)throw new Error('HTTP '+response.status);const data=await response.json();if(!data.ok||!Array.isArray(data.permits)||!Array.isArray(data.cards)||!Array.isArray(data.receipts)||!Array.isArray(data.expenses))throw new Error('invalid payload');
        permits=data.permits;cards=data.cards;permitMap=new Map(permits.map(p=>[String(p.id),p]));permitsLoaded=true;
        fillDatalist('qeReceiptNames',data.receipts);fillDatalist('qeExpenseNames',data.expenses);refreshCardOptions();
        [...rowsEl.children].forEach(row=>{paintPermitButton(row);paintMovementButton(row);});return permits;})
      .catch(error=>{permitsPromise=null;throw error;});
    return permitsPromise;
  }
  /* ── حفظ تلقائي للمسودة (يمنع ضياع البيانات لو انتهت الجلسة أو اتقفلت الصفحة) ── */
  const DRAFT_KEY='almotawakel-quick-entry-draft';
  const quickApplied=<?= !empty($quickApplied)?'true':'false' ?>;
  let autosaveOn=false,pendingRestore=false,restoreOffered=false,draftTimer=null,restoreBanner=null;
  const draftMeaningful=rows=>Array.isArray(rows)&&rows.some(r=>r&&(r.permit_id||(r.note&&String(r.note).trim())||(+r.amount>0)));
  function loadDraft(){try{const d=JSON.parse(localStorage.getItem(DRAFT_KEY)||'null');return d&&draftMeaningful(d.rows)?d:null;}catch(e){return null;}}
  function clearDraft(){try{localStorage.removeItem(DRAFT_KEY);}catch(e){}}
  function writeDraft(){const rows=[...rowsEl.children].map(rowData);if(!draftMeaningful(rows)){clearDraft();return;}try{localStorage.setItem(DRAFT_KEY,JSON.stringify({t:Date.now(),rows}));}catch(e){}}
  function scheduleDraftSave(){if(!autosaveOn||pendingRestore)return;clearTimeout(draftTimer);draftTimer=setTimeout(writeDraft,500);}
  function draftTimeLabel(t){try{return new Date(t).toLocaleString('ar-EG',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});}catch(e){return '';}}
  function removeRestore(){pendingRestore=false;if(restoreBanner){restoreBanner.remove();restoreBanner=null;}}
  function doRestore(draft){rowsEl.innerHTML='';(draft.rows||[]).forEach(d=>addRow(d));if(!rowsEl.children.length)addRow({entry_date:<?= js_json($date) ?>,qty:1});removeRestore();updateLive();}
  function offerRestore(){
    if(restoreOffered)return;restoreOffered=true;
    if(oldCount)return;const draft=loadDraft();if(!draft)return;
    pendingRestore=true;
    const b=document.createElement('div');b.className='qe-restore';
    b.innerHTML=`<span>📝 <b>لقينا بيانات لم تُطبَّق</b> من آخر مرة (${draft.rows.length} صف · ${draftTimeLabel(draft.t)}). عايز ترجّعها؟</span>`+
      `<span class="qe-restore-btns"><button type="button" class="btn gold sm" data-qe-restore>استرجاع البيانات</button><button type="button" class="btn ghost sm" data-qe-fresh>ابدأ من جديد</button></span>`;
    rowsEl.parentNode.insertBefore(b,rowsEl);restoreBanner=b;
    b.querySelector('[data-qe-restore]').addEventListener('click',()=>doRestore(draft));
    b.querySelector('[data-qe-fresh]').addEventListener('click',()=>{clearDraft();removeRestore();});
  }
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const money=v=>new Intl.NumberFormat('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}).format(+v||0);
  const val=(name,i,def='')=>Array.isArray(old[name])?(old[name][i]??def):def;
  const oldCount=Array.isArray(old.permit_id)?old.permit_id.length:0;
  let dirty=false,activePermitRow=null,activeMovementRow=null;

  const norm=v=>String(v??'').toLocaleLowerCase('ar').normalize('NFKD').replace(/[\u064b-\u065f\u0670]/g,'').replace(/[\s\-_/]/g,'');
  function containerChips(p){return String(p?.container_label||'بدون حاويات مسجلة').split('،').map(x=>`<span>${esc(x.trim())}</span>`).join('');}
  function permitButtonHtml(p){return p
    ? `<span class="qe-pick-top"><b>${esc(p.type)} ${esc(p.no)}</b><em>${p.status==='closed'?'مغلق':'جارٍ'}</em></span><span class="qe-pick-company">الشركة: <strong>${esc(p.company)}</strong></span><span class="qe-pick-containers">${containerChips(p)}</span>`
    : `<span class="qe-pick-placeholder"><b>اختر الإذن / الشهادة</b><small>ستظهر الشركة وتصنيف الحاويات هنا</small></span>`;}
  function paintPermitButton(row){const input=row.querySelector('[name="permit_id[]"]'),p=permitMap.get(String(input.value)),btn=row.querySelector('.qe-permit-pick');btn.innerHTML=permitButtonHtml(p);btn.classList.toggle('selected',!!p);btn.classList.remove('invalid');}
  function movementDetail(m){const parts=[];if(+m?.container_count>0)parts.push(`${m.container_count} حاوية`);if(m?.container_type)parts.push(m.container_type);return parts;}
  function movementButtonHtml(p,m){return m
    ? `<span class="qe-pick-top"><b>الحركة ${m.seq}</b>${p?.movements?.length===1?'<em>اختيار تلقائي</em>':''}</span><span class="qe-movement-chips">${movementDetail(m).map(x=>`<span>${esc(x)}</span>`).join('')||'<span>بدون حاويات محددة</span>'}</span>${m.note?`<small>${esc(m.note)}</small>`:''}`
    : `<span class="qe-pick-placeholder"><b>اختر الحركة</b><small>ستظهر الحاويات وملاحظات الحركة هنا</small></span>`;}
  function paintMovementButton(row){const p=permitMap.get(String(row.querySelector('[name="permit_id[]"]').value)),input=row.querySelector('[name="movement_id[]"]'),m=p?.movements?.find(x=>String(x.id)===String(input.value)),btn=row.querySelector('.qe-movement-pick');btn.innerHTML=movementButtonHtml(p,m);btn.classList.toggle('selected',!!m);btn.classList.remove('invalid');}
  function cardOptions(selected){return '<option value="">اختر الكارت…</option>'+cards.map(c=>`<option value="${c.id}" ${String(c.id)===String(selected)?'selected':''}>${esc(c.label)}</option>`).join('');}
  function rowData(row){const q=s=>row.querySelector(s);return {
    permit_id:q('[name="permit_id[]"]').value,movement_id:q('[name="movement_id[]"]').value,kind:q('[name="kind[]"]').value,
    note:q('[name="note[]"]').value.trim(),qty:q('[name="qty[]"]').value,amount:q('[name="amount[]"]').value,actual_amount:q('[name="actual_amount[]"]').value,
    entry_date:q('[name="entry_date[]"]').value,ref_no:q('[name="ref_no[]"]').value.trim(),
    payment_method:q('[name="payment_method[]"]').value,visa_card_id:q('[name="visa_card_id[]"]').value};}
  function addRow(data={}){
    const idx=rowsEl.children.length+1,kind=data.kind||'receipt',payment=data.payment_method||'cash';
    const row=document.createElement('article');row.className='qe-row';
    row.innerHTML=`<div class="qe-row-no"><b>${idx}</b><span>بند</span></div><div class="qe-row-grid">
      <label class="wide qe-permit-wrap"><span>الإذن / الشهادة</span><input type="hidden" name="permit_id[]" value="${esc(data.permit_id||'')}"><button type="button" class="qe-permit-pick">${permitButtonHtml(permitMap.get(String(data.permit_id||'')))}</button></label>
      <label class="qe-movement-wrap"><span>الحركة</span><input type="hidden" name="movement_id[]" value="${esc(data.movement_id||'')}"><button type="button" class="qe-movement-pick">${movementButtonHtml(permitMap.get(String(data.permit_id||'')),permitMap.get(String(data.permit_id||''))?.movements?.find(x=>String(x.id)===String(data.movement_id||'')))}</button></label>
      <div class="qe-kind-field"><span>نوع البند</span><input type="hidden" name="kind[]" class="qe-kind" value="${esc(kind)}"><div class="qe-kind-switch"><button type="button" data-kind="receipt" class="${kind==='receipt'?'active':''}">إيصال</button><button type="button" data-kind="expense" class="${kind==='expense'?'active':''}">مصروف</button></div></div>
      <label class="wide"><span>البيان</span><input name="note[]" class="qe-note" list="${kind==='receipt'?'qeReceiptNames':'qeExpenseNames'}" value="${esc(data.note||'')}" placeholder="${kind==='receipt'?'تخصيص / حمولة / هيئة…':'أرضية / غرامة / عمولة…'}" maxlength="255" required></label>
      <label class="qe-qty"><span>الكمية</span><input type="number" name="qty[]" value="${esc(data.qty||1)}" min="1" max="10000" step="1" required></label>
      <label><span>سعر الوحدة</span><input type="number" name="amount[]" value="${esc(data.amount||'')}" min="0.01" step="0.01" placeholder="0" required></label>
      <label class="qe-actual" ${kind==='expense'?'hidden':''}><span>الفعلي للوحدة <small>اختياري</small></span><input type="number" name="actual_amount[]" value="${kind==='expense'?'':esc(data.actual_amount||'')}" min="0" step="0.01" placeholder="—"></label>
      <label><span>التاريخ</span><input type="date" name="entry_date[]" value="${esc(data.entry_date||<?= js_json($date) ?>)}" required></label>
      <label><span>رقم مرجعي <small>اختياري</small></span><input name="ref_no[]" value="${esc(data.ref_no||'')}" maxlength="120" placeholder="رقم الإيصال"></label>
      <label><span>طريقة الدفع</span><select name="payment_method[]" class="qe-payment"><option value="cash" ${payment==='cash'?'selected':''}>نقدي</option><option value="visa" ${payment==='visa'?'selected':''}>فيزا</option></select></label>
      <label class="qe-card" ${payment==='visa'?'':'hidden'}><span>كارت الفيزا</span><select name="visa_card_id[]">${cardOptions(data.visa_card_id||'')}</select></label>
    </div><button type="button" class="qe-remove" title="حذف الصف">✕</button>`;
    rowsEl.appendChild(row);bindRow(row);renumber();updateLive();return row;
  }
  function bindRow(row){
    const permit=row.querySelector('[name="permit_id[]"]'),movement=row.querySelector('[name="movement_id[]"]'),kind=row.querySelector('.qe-kind'),payment=row.querySelector('.qe-payment');
    row.querySelector('.qe-permit-pick').addEventListener('click',()=>openPermitBrowser(row));
    row.querySelector('.qe-movement-pick').addEventListener('click',()=>openMovementBrowser(row));
    row.querySelectorAll('[data-kind]').forEach(btn=>btn.addEventListener('click',()=>setKind(row,btn.dataset.kind)));
    payment.addEventListener('change',()=>{const wrap=row.querySelector('.qe-card');wrap.hidden=payment.value!=='visa';if(payment.value!=='visa')wrap.querySelector('select').value='';});
    row.querySelector('.qe-remove').addEventListener('click',()=>{if(rowsEl.children.length===1){row.querySelectorAll('input').forEach(i=>{if(i.type!=='date')i.value='';});row.querySelector('[name="qty[]"]').value='1';paintPermitButton(row);paintMovementButton(row);updateLive();return;}row.remove();renumber();updateLive();dirty=true;});
    row.addEventListener('input',()=>{dirty=true;updateLive();});row.addEventListener('change',()=>{dirty=true;updateLive();});
    const p=permitMap.get(String(permit.value));if(p?.movements?.length===1&&!movement.value)movement.value=String(p.movements[0].id);paintMovementButton(row);
  }
  function setKind(row,value){const kind=row.querySelector('.qe-kind'),receipt=value==='receipt',note=row.querySelector('.qe-note'),actual=row.querySelector('.qe-actual');kind.value=receipt?'receipt':'expense';row.querySelectorAll('[data-kind]').forEach(btn=>btn.classList.toggle('active',btn.dataset.kind===kind.value));note.setAttribute('list',receipt?'qeReceiptNames':'qeExpenseNames');note.placeholder=receipt?'تخصيص / حمولة / هيئة…':'أرضية / غرامة / عمولة…';actual.hidden=!receipt;if(!receipt)actual.querySelector('input').value='';dirty=true;updateLive();}
  function renderPermitResults(){const q=norm(permitSearch.value),matches=permits.filter(p=>!q||norm(p.search).includes(q));
    permitResults.innerHTML=matches.slice(0,120).map(p=>`<button type="button" class="qe-permit-option ${activePermitRow?.querySelector('[name="permit_id[]"]')?.value===String(p.id)?'active':''}" data-permit-id="${p.id}">
      <span class="qe-option-top"><i>${esc(p.type)}</i><b>${esc(p.no)}</b><em class="${p.status==='closed'?'closed':''}">${p.status==='closed'?'مغلق':'جارٍ'}</em></span>
      <span class="qe-option-company">الشركة <strong>${esc(p.company)}</strong></span>
      <span class="qe-option-meta"><small>العميل: ${esc(p.client)}</small>${p.date?`<small>التاريخ: ${esc(p.date)}</small>`:''}</span>
      <span class="qe-option-containers"><u>الحاويات</u>${containerChips(p)}</span></button>`).join('');
    permitEmpty.hidden=matches.length>0;}
  async function openPermitBrowser(row){activePermitRow=row;permitSearch.value='';browser.hidden=false;permitSearch.disabled=true;permitEmpty.hidden=true;permitResults.innerHTML='<div class="qe-browser-empty">جارٍ تحميل الأذونات…</div>';
    try{await loadPermits();if(activePermitRow!==row)return;permitSearch.disabled=false;renderPermitResults();setTimeout(()=>permitSearch.focus(),30);}
    catch(error){permitResults.innerHTML='<div class="qe-browser-empty">تعذّر تحميل الأذونات. أغلق النافذة وحاول مرة أخرى.</div>';}}
  function closePermitBrowser(){browser.hidden=true;activePermitRow=null;}
  function selectPermit(pid){if(!activePermitRow)return;const row=activePermitRow,input=row.querySelector('[name="permit_id[]"]'),movement=row.querySelector('[name="movement_id[]"]'),p=permitMap.get(String(pid));if(!p)return;
    input.value=String(p.id);movement.value=p.movements?.length===1?String(p.movements[0].id):'';paintPermitButton(row);paintMovementButton(row);dirty=true;updateLive();closePermitBrowser();}
  function renderMovementResults(){const row=activeMovementRow,p=permitMap.get(String(row?.querySelector('[name="permit_id[]"]')?.value)),selected=row?.querySelector('[name="movement_id[]"]')?.value;movementTitle.textContent=p?`${p.type} ${p.no} — ${p.company}`:'حركات الإذن';
    movementResults.innerHTML=(p?.movements||[]).map(m=>`<button type="button" class="qe-permit-option qe-movement-option ${String(m.id)===String(selected)?'active':''}" data-movement-id="${m.id}"><span class="qe-option-top"><i>حركة</i><b>${m.seq}</b></span><span class="qe-option-containers"><u>الحاويات</u>${movementDetail(m).map(x=>`<span>${esc(x)}</span>`).join('')||'<span>غير محددة</span>'}</span>${m.note?`<span class="qe-movement-note">ملاحظة: <strong>${esc(m.note)}</strong></span>`:''}</button>`).join('');}
  function openMovementBrowser(row){const p=permitMap.get(String(row.querySelector('[name="permit_id[]"]').value));if(!p){openPermitBrowser(row);return;}if(p.movements?.length===1){row.querySelector('[name="movement_id[]"]').value=String(p.movements[0].id);paintMovementButton(row);return;}activeMovementRow=row;renderMovementResults();movementBrowser.hidden=false;setTimeout(()=>movementResults.querySelector('button')?.focus(),30);}
  function closeMovementBrowser(){movementBrowser.hidden=true;activeMovementRow=null;}
  function selectMovement(mid){if(!activeMovementRow)return;const row=activeMovementRow,p=permitMap.get(String(row.querySelector('[name="permit_id[]"]').value)),m=p?.movements?.find(x=>String(x.id)===String(mid));if(!m)return;row.querySelector('[name="movement_id[]"]').value=String(m.id);paintMovementButton(row);dirty=true;updateLive();closeMovementBrowser();}
  function renumber(){[...rowsEl.children].forEach((r,i)=>r.querySelector('.qe-row-no b').textContent=i+1);}
  function filledRows(){return [...rowsEl.children].map(rowData).filter(r=>r.permit_id||r.note||(+r.amount>0));}
  function updateLive(){const rs=filledRows(),total=r=>(+r.amount||0)*Math.max(1,+r.qty||1),rec=rs.filter(r=>r.kind==='receipt').reduce((s,r)=>s+total(r),0),exp=rs.filter(r=>r.kind==='expense').reduce((s,r)=>s+total(r),0);
    document.getElementById('quickLiveSummary').textContent=`${rs.length} بند · إيصالات ${money(rec)} · مصاريف ${money(exp)}`;scheduleDraftSave();}
  function validate(){const rows=[...rowsEl.children];for(const row of rows){const d=rowData(row);if(!d.permit_id&&!d.note&&!(+d.amount))continue;
      if(!d.permit_id){row.querySelector('.qe-permit-pick').classList.add('invalid');openPermitBrowser(row);return false;}
      if(!d.movement_id){row.querySelector('.qe-movement-pick').classList.add('invalid');openMovementBrowser(row);return false;}
      for(const el of row.querySelectorAll('[required]')){if(!el.checkValidity()){el.reportValidity();el.focus();return false;}}
      if(d.payment_method==='visa'&&!d.visa_card_id){const card=row.querySelector('[name="visa_card_id[]"]');card.setCustomValidity('اختر كارت الفيزا');card.reportValidity();card.setCustomValidity('');return false;}}
    if(!filledRows().length){window.AppUI.notice('أضف بندًا واحدًا على الأقل.','بيانات ناقصة');return false;}return true;}
  function review(){if(!validate())return;const rs=filledRows(),body=document.getElementById('qeReviewBody');let rec=0,exp=0;body.innerHTML='';
    rs.forEach((r,i)=>{const p=permitMap.get(String(r.permit_id)),m=p?.movements.find(x=>String(x.id)===String(r.movement_id)),qty=Math.max(1,+r.qty||1),lineTotal=qty*(+r.amount||0);if(r.kind==='receipt')rec+=lineTotal;else exp+=lineTotal;
      const tr=document.createElement('tr');tr.innerHTML=`<td>${i+1}</td><td><b>${esc(p?.no||'—')}</b><small>الشركة: ${esc(p?.company||'—')} · ${esc(p?.container_label||'')}</small><small>${esc(m?.label||'—')}</small></td><td><span class="qe-kind-pill ${r.kind}">${r.kind==='receipt'?'إيصال':'مصروف'}</span></td><td><b>${esc(r.note)}</b>${r.ref_no?`<small>#${esc(r.ref_no)}</small>`:''}</td><td><b>${qty}</b></td><td>${money(r.amount)}</td><td><b>${money(lineTotal)}</b></td><td>${r.actual_amount?money((+r.actual_amount)*qty):'—'}</td><td>${esc(r.entry_date)}</td><td>${r.payment_method==='visa'?'فيزا':'نقدي'}</td>`;body.appendChild(tr);});
    document.getElementById('qeReviewCount').textContent=rs.length;document.getElementById('qeReceiptTotal').textContent=money(rec);document.getElementById('qeExpenseTotal').textContent=money(exp);showStep('review');}
  function showStep(step){modal.querySelectorAll('[data-qe-step]').forEach(s=>s.hidden=s.dataset.qeStep!==step);modal.querySelectorAll('[data-qe-dot]').forEach(s=>s.classList.toggle('active',s.dataset.qeDot===step));}
  function open(){modal.hidden=false;modal.setAttribute('aria-hidden','false');document.body.style.overflow='hidden';showStep('edit');autosaveOn=true;offerRestore();void loadPermits();setTimeout(()=>rowsEl.querySelector('.qe-permit-pick')?.focus(),60);}
  function close(force){if(!movementBrowser.hidden){closeMovementBrowser();return;}if(!browser.hidden){closePermitBrowser();return;}if(force!==true&&dirty&&filledRows().length){window.AppUI.confirm('ستفقد الصفوف غير المطبقة. هل تريد الإغلاق؟',()=>close(true),{title:'إغلاق شاشة الإدخال',okText:'نعم، أغلق'});return;}modal.hidden=true;modal.setAttribute('aria-hidden','true');document.body.style.overflow='';}
  document.getElementById('quickEntryOpen').addEventListener('click',open);modal.querySelectorAll('[data-qe-close]').forEach(el=>el.addEventListener('click',close));
  document.getElementById('quickEntryAdd').addEventListener('click',()=>{const last=rowsEl.lastElementChild,d=last?rowData(last):{};d.note='';d.qty='1';d.amount='';d.actual_amount='';d.ref_no='';addRow(d).querySelector('.qe-note').focus();});
  document.getElementById('quickEntryReview').addEventListener('click',review);document.getElementById('quickEntryBack').addEventListener('click',()=>showStep('edit'));
  permitSearch.addEventListener('input',renderPermitResults);permitResults.addEventListener('click',e=>{const option=e.target.closest('[data-permit-id]');if(option)selectPermit(option.dataset.permitId);});browser.querySelectorAll('[data-qe-browser-close]').forEach(el=>el.addEventListener('click',closePermitBrowser));
  movementResults.addEventListener('click',e=>{const option=e.target.closest('[data-movement-id]');if(option)selectMovement(option.dataset.movementId);});movementBrowser.querySelectorAll('[data-qe-movement-close]').forEach(el=>el.addEventListener('click',closeMovementBrowser));
  form.addEventListener('submit',e=>{if(!validate()){e.preventDefault();showStep('edit');return;}const btn=document.getElementById('quickEntryApply');btn.disabled=true;btn.textContent='جارٍ التطبيق…';dirty=false;});
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!modal.hidden)close();});
  // لو المستخدم بدأ يكتب والبانر ظاهر → يعتبره «ابدأ من جديد» فتُحفظ كتابته الجديدة
  rowsEl.addEventListener('input',()=>{if(pendingRestore){clearDraft();removeRestore();scheduleDraftSave();}});
  if(quickApplied)clearDraft();   // تأكيد نجاح الحفظ من السيرفر → امسح المسودة
  if(oldCount){for(let i=0;i<oldCount;i++)addRow({permit_id:val('permit_id',i),movement_id:val('movement_id',i),kind:val('kind',i,'receipt'),note:val('note',i),qty:val('qty',i,'1'),amount:val('amount',i),actual_amount:val('actual_amount',i),entry_date:val('entry_date',i,<?= js_json($date) ?>),ref_no:val('ref_no',i),payment_method:val('payment_method',i,'cash'),visa_card_id:val('visa_card_id',i)});dirty=true;}else addRow({entry_date:<?= js_json($date) ?>,qty:1});
  <?php if(!empty($_GET['quick'])): ?>open();<?php endif; ?>
})();

/* ── عدّاد متحرك ── */
(function(){
  const fmt=(v,dec)=>new Intl.NumberFormat('en-US',{minimumFractionDigits:dec,maximumFractionDigits:dec}).format(v);
  document.querySelectorAll('.count').forEach(el=>{
    const to=parseFloat(el.dataset.to)||0, dec=parseInt(el.dataset.dec)||0, dur=900; let s=null;
    function step(ts){ if(!s)s=ts; const p=Math.min((ts-s)/dur,1),e=1-Math.pow(1-p,3);
      el.textContent=fmt(to*e,dec); if(p<1)requestAnimationFrame(step); }
    requestAnimationFrame(step);
  });
})();

/* ── شريط متحرك ── */
(function(){
  const stage=document.getElementById('dLive'); if(!stage) return;
  const items=[...stage.querySelectorAll('.d-live-item')], dots=document.getElementById('dLiveDots');
  if(items.length<=1) return;
  items.forEach((_,i)=>{const d=document.createElement('span');d.className='d-live-d'+(i===0?' on':'');dots.appendChild(d);});
  const dotEls=[...dots.children]; let cur=0;
  function show(n){ items[cur].classList.remove('on');dotEls[cur].classList.remove('on');
    cur=n; items[cur].classList.add('on');dotEls[cur].classList.add('on'); }
  setInterval(()=>show((cur+1)%items.length),3000);
})();

/* ── البوب-أب ── */
(function(){
  const modal=document.getElementById('dModal'), body=document.getElementById('dModalBody'),
        titleEl=document.getElementById('dModalTitle'), store=document.getElementById('popStore');
  function open(key,title){ const src=store.querySelector('[data-pop="'+key+'"]'); if(!src)return;
    body.innerHTML=src.innerHTML;body.querySelectorAll('[data-clone]').forEach(slot=>{const source=document.querySelector(slot.dataset.clone);if(!source)return;const clone=source.cloneNode(true);clone.removeAttribute('id');clone.classList.add('flat');slot.replaceWith(clone);});titleEl.textContent=title||''; modal.hidden=false;
    document.body.style.overflow='hidden'; }
  function close(){ modal.hidden=true; body.innerHTML=''; document.body.style.overflow=''; }
  document.querySelectorAll('[data-pop]').forEach(el=>{
    if(el.closest('#popStore')) return;
    el.addEventListener('click',()=>open(el.dataset.pop, el.dataset.title));
  });
  modal.querySelectorAll('[data-close]').forEach(el=>el.addEventListener('click',close));
  document.addEventListener('keydown',e=>{ if(e.key==='Escape'&&!modal.hidden)close(); });
})();

/* ── تعديل الملاحظات (إظهار/إخفاء الفورم) ── */
(function(){
  document.querySelectorAll('[data-edit]').forEach(btn=>btn.addEventListener('click',()=>{
    const id=btn.dataset.edit, f=document.getElementById('edit-'+id), note=document.getElementById('note-'+id);
    if(!f)return; const show=f.hidden; f.hidden=!show; note.classList.toggle('editing',show);
    if(show){ const ta=f.querySelector('textarea'); ta.focus(); ta.setSelectionRange(ta.value.length,ta.value.length); }
  }));
  document.querySelectorAll('[data-cancel]').forEach(btn=>btn.addEventListener('click',()=>{
    const id=btn.dataset.cancel, f=document.getElementById('edit-'+id), note=document.getElementById('note-'+id);
    if(f){ f.hidden=true; note.classList.remove('editing'); }
  }));
})();

/* ── الرسوم ── */
const CH=<?= js_json($charts) ?>;
const C={navy:'#15355B',navyMid:'#1E4B7A',gold:'#C9A227',green:'#1E6E3C',red:'#9B2C2C',teal:'#14746F',purple:'#5B3E7A',slate:'#4a5a70'};
Chart.defaults.font.family="'Segoe UI','Tahoma',sans-serif"; Chart.defaults.font.size=12; Chart.defaults.color='#5A6472';
Chart.defaults.plugins.legend.rtl=true; Chart.defaults.plugins.tooltip.rtl=true;
Chart.defaults.plugins.tooltip.backgroundColor='#15355B'; Chart.defaults.plugins.tooltip.padding=10; Chart.defaults.plugins.tooltip.cornerRadius=8;
const nf=(v)=>new Intl.NumberFormat('en-US').format(Math.round(v));
const AR_MONTHS=['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
function grad(ctx,hex,a1,a2){const g=ctx.createLinearGradient(0,0,0,300);g.addColorStop(0,hex+a1);g.addColorStop(1,hex+a2);return g;}

(function(){ const el=document.getElementById('chMonthly'),cx=el.getContext('2d');
  new Chart(el,{type:'line',data:{labels:AR_MONTHS,datasets:[
    {label:'المصروفات',data:CH.monthly.map(m=>+m.expenses),borderColor:C.red,backgroundColor:grad(cx,C.red,'2b','00'),fill:true,tension:.38,borderWidth:2.5,pointRadius:0,pointHoverRadius:5,pointBackgroundColor:C.red},
    {label:'المدفوعات',data:CH.monthly.map(m=>+m.collections),borderColor:C.green,backgroundColor:grad(cx,C.green,'2b','00'),fill:true,tension:.38,borderWidth:2.5,pointRadius:0,pointHoverRadius:5,pointBackgroundColor:C.green}]},
    options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},
      scales:{y:{beginAtZero:true,ticks:{callback:nf},grid:{color:'#EEF1F6'}},x:{grid:{display:false}}},
      plugins:{legend:{position:'top',align:'end',labels:{usePointStyle:true,boxWidth:8,padding:16}}}}}); })();

new Chart(document.getElementById('chTrend'),{type:'bar',data:{labels:CH.trend.labels,datasets:[{label:'عدد العمليات',data:CH.trend.data,backgroundColor:'rgba(21,53,91,.85)',hoverBackgroundColor:C.gold,borderRadius:6,maxBarThickness:22}]},
  options:{responsive:true,maintainAspectRatio:false,scales:{y:{beginAtZero:true,ticks:{precision:0},grid:{color:'#EEF1F6'}},x:{grid:{display:false}}},plugins:{legend:{display:false}}}});

new Chart(document.getElementById('chClients'),{type:'bar',data:{labels:CH.clients.map(r=>r.name),datasets:[{label:'المتبقي',data:CH.clients.map(r=>+r.remaining),backgroundColor:C.gold,borderRadius:6,maxBarThickness:20}]},
  options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,scales:{x:{ticks:{callback:nf},grid:{color:'#EEF1F6'}},y:{grid:{display:false}}},plugins:{legend:{display:false}}}});

(function(){ const ex=CH.expenses,catSum=(ex.categories||[]).reduce((s,r)=>s+ +r.value,0);
  const slices=[['حركات البوالص',catSum,C.navy],['الأذونات',+ex.permits,C.teal],['التخليص',+ex.takhlis,C.gold],['شامل',+ex.shamil,C.purple],['مصاريف يدوية',+ex.manual,C.slate],['سداد مديونيات',+ex.debt,C.red]].filter(s=>s[1]>0);
  new Chart(document.getElementById('chExpenses'),{type:'doughnut',data:{labels:slices.map(s=>s[0]),datasets:[{data:slices.map(s=>s[1]),backgroundColor:slices.map(s=>s[2]),borderWidth:2,borderColor:'#fff',hoverOffset:6}]},
    options:{responsive:true,maintainAspectRatio:false,cutout:'62%',plugins:{legend:{position:'right',labels:{usePointStyle:true,boxWidth:8,padding:9,font:{size:11}}},tooltip:{callbacks:{label:(c)=>' '+c.label+': '+nf(c.parsed)}}}}}); })();
</script>
