<?php
use App\Core\FormState;
$returnPath = current_app_path();
/* ⚠️ الجدول بيعرض **كل** أعمدة الكيان (طلب المالك) — `list_fields` بقت
   «الأعمدة الأساسية» للتمييز البصري بس، مش قصّ للعرض. إدارة الزحمة عن طريق:
   مبدّل المجموعات تحت + منتقي الأعمدة العام في `app.js` (محفوظ في localStorage).
   ⚠️ مفتاح تخزين المنتقي مشتقّ من بصمة عناوين الأعمدة، فتغيير عددها بيبدأ
      حالة جديدة نظيفة ومفيش إزاحة في الفهارس المحفوظة. */
$fields    = $ent['fields'];
$primary   = array_flip($ent['list_fields'] ?? []);
$groupMeta = ['identity'=>'البيانات','vehicle'=>'السيارة','revenue'=>'الإيراد','expense'=>'المصروفات','result'=>'النتيجة'];
$groupsUsed = [];
foreach ($fields as $f) { $groupsUsed[$f['group'] ?? 'identity'] = true; }
$detail = !empty($ent['detail']);
$bulkBills = !empty($ent['has_legacy']);
// دالة تنسيق قيمة الخلية حسب النوع
$fmtCell = function(array $f, $v): array {
    $type = $f['type'];
    if ($type === 'source') {
        $label = $v === 'shujaie' ? 'الشجيع' : 'ايجي كول';
        return [$label, ''];
    }
    if ($type === 'billtype') {
        $label = $v === 'warid' ? 'وارد' : 'صادر';
        return ['<span class="pill pill-'.($v==='warid'?'warid':'sader').'">'.$label.'</span>', ''];
    }
    if ($type === 'status') {
        $lbl = \App\Models\Bill::STATUS_LABELS[$v] ?? '';
        return ['<span class="pill st-'.e($v).'">'.e($lbl).'</span>', ''];
    }
    if ($type === 'date')  return [dfmt($v), ''];
    if ($type === 'money') return [$v !== null ? money($v, false) : '', signClass($v)];
    if ($type === 'pct')   return [$v !== null && $v !== '' ? pct($v) : '', signClass($v)];
    if ($type === 'int')   return [$v !== null ? intf($v) : '', ''];
    return [e($v), ''];
};
/* ⚠️ الفلاتر المتقدّمة لازم تتحمل في `$qs` كمان — من غير كده أول ضغطة على
   ترتيب عمود أو صفحة تانية بتفضّي الفلاتر بالسكوت. */
$flt        = $flt ?? ['fs'=>[],'ft'=>[],'fmin'=>[],'fmax'=>[],'from'=>'','to'=>''];
$filterSpec = $filterSpec ?? [];
$fltActive  = count($flt['fs']) + count($flt['ft']) + count($flt['fmin']) + count($flt['fmax'])
            + ($flt['from'] !== '' ? 1 : 0) + ($flt['to'] !== '' ? 1 : 0);
$qs = fn(array $ov=[]) => http_build_query(array_merge(
    ['q'=>$q,'year'=>$year,'month'=>$month,'source'=>$source,'legacy'=>$legacy ?? '',
     'sort'=>$sort ?? '', 'dir'=>$dir ?? 'desc',
     'fs'=>$flt['fs'], 'ft'=>$flt['ft'], 'fmin'=>$flt['fmin'], 'fmax'=>$flt['fmax'],
     'from'=>$flt['from'], 'to'=>$flt['to']], $ov));
?>
<?php /* ⚠️ صف الإجراءات منفصل عن صف الفلاتر (طلب المالك: «تحسين العرض والتنظيم»).
         كانوا مكدّسين في شريط واحد مع ٦ عناصر فلترة و٤ أزرار، فالإجراء الأساسي
         («إضافة بوليصة») كان بيضيع وسطهم. */ ?>
<div class="page-actions">
  <span class="count-badge"><?= intf($total) ?> سجل</span>
  <div class="spacer"></div>
  <?php if (!empty($ent['has_legacy'])): ?>
    <a class="btn ghost" href="<?= url('statements/new') ?>"><?= icon('receipt', 14) ?> إصدار كشف حساب</a>
  <?php endif; ?>
  <?php $newQuery = array_filter(['source'=>(!empty($ent['has_source']) ? $source : ''),'back'=>$returnPath], fn($v)=>$v!==''); ?>
  <a class="btn gold" href="<?= url($ent['route'].'/new?'.http_build_query($newQuery)) ?>"><?= icon('plus', 14) ?> إضافة<?= !empty($ent['has_legacy'])?' بوليصة':'' ?></a>
</div>

<div class="toolbar">
  <form method="get" action="<?= url($ent['route']) ?>">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="بحث في <?= e($ent['title']) ?>…">
    <?php /* ⚠️ فلترا السنة والشهر اتشالوا (طلب المالك) — «من تاريخ / إلى تاريخ» في
             اللوحة المتقدّمة بيغطّوهم وأدق منهم. الخادم لسه بيقرا `year`/`month`
             عشان أي رابط قديم متحفوظ يفضل شغّال، وزرار «إلغاء» بيمسحهم. */ ?>
    <?php if (!empty($ent['has_source'])): ?>
    <select name="source">
      <option value="">كل الجهات</option>
      <option value="egycool" <?= $source==='egycool'?'selected':'' ?>>ايجي كول</option>
      <option value="shujaie" <?= $source==='shujaie'?'selected':'' ?>>الشجيع</option>
    </select>
    <?php endif; ?>
    <?php if (!empty($ent['has_legacy'])): ?>
    <select name="legacy" aria-label="مصدر البوليصة">
      <option value="" <?= ($legacy??'')===''?'selected':'' ?>>كل البوالص</option>
      <option value="0" <?= ($legacy??'')==='0'?'selected':'' ?>>البوالص الحالية فقط</option>
      <option value="1" <?= ($legacy??'')==='1'?'selected':'' ?>>البوالص القديمة فقط</option>
    </select>
    <?php endif; ?>
    <button class="btn sm" type="submit">تصفية</button>
    <?php if ($q||$year||$month||$source||($legacy??'')!==''||$fltActive): ?><a class="btn ghost sm" href="<?= url($ent['route']) ?>">إلغاء</a><?php endif; ?>
    <?php /* الفلاتر المتقدّمة بتتبعت من نفس الفورم ده — القيم الحالية بتتحفظ في
             حقول مخفية لو اللوحة مقفولة، فالبحث السريع ما بيلغيش فلترة شغّالة. */ ?>
    <button class="btn ghost sm" type="button" id="advFltToggle" aria-expanded="<?= $fltActive?'true':'false' ?>" aria-controls="advFlt">
      <?= icon('filter', 14) ?> فلترة متقدمة<?= $fltActive ? ' <span class="adv-flt-count">'.intf($fltActive).'</span>' : '' ?>
    </button>

    <div class="adv-flt" id="advFlt"<?= $fltActive ? '' : ' hidden' ?>>
      <div class="adv-flt-grid">
        <label class="field"><span>من تاريخ</span>
          <input type="date" name="from" value="<?= e($flt['from']) ?>"></label>
        <label class="field"><span>إلى تاريخ</span>
          <input type="date" name="to" value="<?= e($flt['to']) ?>"></label>
        <?php foreach ($filterSpec as $k => $s):
          $lk = $s['lookup'] ? ($lookups[$s['lookup']] ?? []) : []; ?>
          <?php if ($s['kind'] === 'choice'): ?>
            <label class="field"><span><?= e($s['label']) ?></span>
              <select name="fs[<?= e($k) ?>]">
                <option value="">الكل</option>
                <?php
                  $opts = [];
                  if ($s['type'] === 'billtype')      { $opts = ['sader'=>'صادر','warid'=>'وارد']; }
                  elseif ($s['type'] === 'status')    { $opts = \App\Models\Bill::STATUS_LABELS; }
                  elseif ($s['type'] === 'source')    { $opts = ['egycool'=>'ايجي كول','shujaie'=>'الشجيع']; }
                  else { foreach ($lk as $v) { $opts[$v] = $v; } }
                  $cur = $flt['fs'][$k] ?? '';
                  foreach ($opts as $val => $lbl):
                ?><option value="<?= e($val) ?>" <?= (string)$cur===(string)$val?'selected':'' ?>><?= e($lbl) ?></option><?php
                  endforeach; ?>
              </select></label>
          <?php elseif ($s['kind'] === 'text'): ?>
            <label class="field"><span><?= e($s['label']) ?></span>
              <input type="search" name="ft[<?= e($k) ?>]" value="<?= e($flt['ft'][$k] ?? '') ?>"></label>
          <?php elseif ($s['kind'] === 'range'): ?>
            <div class="field adv-flt-range"><span><?= e($s['label']) ?></span>
              <div>
                <input type="number" step="any" name="fmin[<?= e($k) ?>]" value="<?= e($flt['fmin'][$k] ?? '') ?>" aria-label="<?= e($s['label']) ?> — من">
                <input type="number" step="any" name="fmax[<?= e($k) ?>]" value="<?= e($flt['fmax'][$k] ?? '') ?>" aria-label="<?= e($s['label']) ?> — إلى">
              </div>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <div class="adv-flt-actions">
        <button class="btn gold sm" type="submit"><?= icon('filter', 14) ?> طبّق الفلترة</button>
        <a class="btn ghost sm" href="<?= url($ent['route']) ?>">مسح الكل</a>
      </div>
    </div>
  </form>
</div>

<?php /* شرايح الفلاتر الشغّالة — مع ٦٢ خانة فلترة، من غيرها المستخدم بيبص على
         نتيجة مفلترة ومايعرفش ليه. كل شريحة بتشيل فلترها هي بس. */
$chips = [];
$rm = function (string $bag, string $key) use ($qs, $flt) {
    $b = $flt[$bag]; unset($b[$key]);
    return '?' . $qs([$bag => $b, 'page' => 1]);
};
foreach ($flt['fs'] as $k => $v) {
    $lbl = $filterSpec[$k]['label'] ?? $k;
    $show = $v;
    if (($filterSpec[$k]['type'] ?? '') === 'billtype') { $show = $v === 'warid' ? 'وارد' : 'صادر'; }
    elseif (($filterSpec[$k]['type'] ?? '') === 'status') { $show = \App\Models\Bill::STATUS_LABELS[$v] ?? $v; }
    $chips[] = [$lbl . ': ' . $show, $rm('fs', $k)];
}
foreach ($flt['ft'] as $k => $v) { $chips[] = [($filterSpec[$k]['label'] ?? $k) . ': ' . $v, $rm('ft', $k)]; }
foreach ($flt['fmin'] as $k => $v) { $chips[] = [($filterSpec[$k]['label'] ?? $k) . ' ≥ ' . $v, $rm('fmin', $k)]; }
foreach ($flt['fmax'] as $k => $v) { $chips[] = [($filterSpec[$k]['label'] ?? $k) . ' ≤ ' . $v, $rm('fmax', $k)]; }
if ($flt['from'] !== '') { $chips[] = ['من ' . dfmt($flt['from']), '?' . $qs(['from' => '', 'page' => 1])]; }
if ($flt['to']   !== '') { $chips[] = ['إلى ' . dfmt($flt['to']), '?' . $qs(['to' => '', 'page' => 1])]; }
if ($q !== '')           { $chips[] = ['بحث: ' . $q, '?' . $qs(['q' => '', 'page' => 1])]; }
?>
<?php if ($chips): ?>
<div class="flt-chips">
  <?php foreach ($chips as [$lbl, $href]): ?>
    <span class="flt-chip"><?= e($lbl) ?><a href="<?= e($href) ?>" aria-label="شيل الفلتر ده">×</a></span>
  <?php endforeach; ?>
  <a class="btn ghost sm" href="<?= url($ent['route']) ?>">مسح الكل</a>
</div>
<?php endif; ?>

<?php if ($bulkBills) { require __DIR__ . '/../bawalis/_bulk_actions.php'; } ?>

<?php /* ⚠️ «لصق من Excel» اتشال بقرار المالك (2026-08-03) مع «إضافة بوليصة قديمة»
         و«معاينة مزامنة Excel». **المسار `POST /bawalis/bulk-paste` والكنترولر
         `BawalisController::bulkPaste` ما اتلمسوش** — الواجهة بس هي اللي اتشالت،
         و`verify_navigation` لسه بيحرس إن الأكشن بيمرّ على `claimForm`. */ ?>

<?php /* ⚠️ الجدول بقى بكل الأعمدة، فمبدّل المجموعات ضروري مش رفاهية: بيخفي/يظهر
         مجموعة كاملة بضغطة (المصروفات ١٢ عمود بتكون أصفارًا في العربية المستأجرة).
         الحالة محفوظة في localStorage لكل شاشة، ومنتقي الأعمدة العام في `app.js`
         لسه شغّال جنبه للتحكّم في عمود بعينه. */ ?>
<div class="col-groups" id="colGroups" data-key="<?= e($ent['route']) ?>">
  <span class="col-groups-lbl"><?= icon('columns', 14) ?> مجموعات الأعمدة:</span>
  <?php foreach ($groupMeta as $gk => $glbl): if (empty($groupsUsed[$gk])) continue;
    $n = count(array_filter($fields, fn($f) => ($f['group'] ?? 'identity') === $gk)); ?>
    <button type="button" class="col-group-btn is-on" data-group="<?= e($gk) ?>" aria-pressed="true">
      <?= e($glbl) ?> <span class="col-group-n"><?= intf($n) ?></span>
    </button>
  <?php endforeach; ?>
  <button type="button" class="btn ghost sm" id="colGroupsReset">إظهار الكل</button>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <?php if ($bulkBills): ?><th class="g-result col-pick" data-no-sort><input type="checkbox" class="bill-pick js-bill-pick-all" title="تحديد كل بوالص الصفحة"></th><?php endif; ?>
          <th class="g-identity"><a class="server-sort" href="?<?= $qs(['sort'=>'id','dir'=>(($sort??'')==='id'&&($dir??'')==='asc')?'desc':'asc','page'=>1]) ?>">م <?= ($sort??'')==='id'?(($dir??'')==='asc'?'↑':'↓'):'⇅' ?></a></th>
          <?php foreach ($fields as $f): ?>
            <th class="g-<?= $f['group'] ?><?= isset($primary[$f['key']]) ? ' col-primary' : '' ?>" data-col-group="<?= e($f['group'] ?? 'identity') ?>"><a class="server-sort" href="?<?= $qs(['sort'=>$f['key'],'dir'=>(($sort??'')===$f['key']&&($dir??'')==='asc')?'desc':'asc','page'=>1]) ?>"><?= e($f['label']) ?> <?= ($sort??'')===$f['key']?(($dir??'')==='asc'?'↑':'↓'):'⇅' ?></a></th>
          <?php endforeach; ?>
          <th class="g-identity">إجراءات</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="<?= count($fields)+2+($bulkBills?1:0) ?>">
            <div class="empty"><div class="ic"><?= icon('inbox', 14) ?></div>لا توجد بيانات — اضغط «إضافة» لبدء الإدخال.</div>
          </td></tr>
        <?php else: $i = ($page-1)*50; foreach ($rows as $row): $i++; ?>
          <?php $isLegacyRow = !empty($ent['has_legacy']) && (int)($row['is_legacy'] ?? 0) === 1; ?>
          <tr<?= $detail ? ' class="rowlink'.($isLegacyRow?' legacy-row':'').'" data-href="'.url($ent['route'].'/'.$row['id']).'"' : ($isLegacyRow?' class="legacy-row"':'') ?>>
            <?php if ($bulkBills): ?><td><input type="checkbox" class="bill-pick js-bill-pick" value="<?= (int)$row['id'] ?>"
              data-no="<?= e($row['bill_number'] ?: '#'.$row['id']) ?>" data-date="<?= e($row['bill_date'] ?? '') ?>"
              data-freight="<?= e(number_format((float)($row['freight'] ?? 0),2,'.','')) ?>" data-legacy="<?= (int)($row['is_legacy'] ?? 0) ?>"
              data-owner="<?= e(trim((string)($row['declaration_owner'] ?? ''))) ?>" title="تحديد البوليصة"></td><?php endif; ?>
            <td><?= intf($i) ?><?php if ($isLegacyRow): ?><span class="legacy-pill" title="بوليصة قديمة معزولة عن العهدة والتقفيل">قديم</span><?php endif; ?></td>
            <?php foreach ($fields as $f): [$txt,$cls] = $fmtCell($f, $row[$f['key']] ?? null); ?>
              <td class="<?= !empty($f['calc'])?'calc':'' ?> <?= $cls ?>"><?= $txt ?></td>
            <?php endforeach; ?>
            <td>
              <div class="actions">
                <?php if ($detail): ?><a href="<?= url($ent['route'].'/'.$row['id']) ?>" title="تفاصيل"><?= icon('search', 14) ?></a><?php endif; ?>
                <?php if (!empty($ent['invoice'])): ?><a href="<?= url($ent['route'].'/'.$row['id'].'/invoice') ?>" target="_blank" title="فاتورة (طباعة / PDF)"><?= icon('receipt', 14) ?></a><?php endif; ?>
                <a class="edit" href="<?= url($ent['route'].'/'.$row['id'].'/edit?back=' . rawurlencode($returnPath)) ?>" title="تعديل"><?= icon('edit', 14) ?></a>
                <form class="confirm-delete" method="post" action="<?= url($ent['route'].'/'.$row['id'].'/delete') ?>">
                  <?= csrfField() ?>
                  <?= formStateFields(FormState::token(), $returnPath) ?>
                  <button class="del" type="submit" title="حذف"><?= icon('trash', 14) ?></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
      <?php if ($rows && !empty($ent['sum_fields'])): ?>
      <tfoot>
        <tr>
          <?php if ($bulkBills): ?><td></td><?php endif; ?>
          <td>Σ</td>
          <?php foreach ($fields as $f):
            if (in_array($f['key'], $ent['sum_fields'])):
              $v = $sums[$f['key']] ?? 0;
              echo '<td>'.($f['type']==='int'?intf($v):money($v,false)).'</td>';
            else: echo '<td></td>'; endif;
          endforeach; ?>
          <td></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>

  <?php if ($pages > 1): ?>
  <?php /* الترقيم بقى مكوّنًا واحدًا بدل نسخة مكرّرة في كل شاشة (المرحلة ٧) */ ?>
  <?php partial('pager', ['page' => $page, 'pages' => $pages, 'qs' => $qs]); ?>
  <?php endif; ?>
</div>

<script>
/* لوحة الفلترة المتقدّمة + مبدّل مجموعات الأعمدة */
(function () {
  var t = document.getElementById('advFltToggle'), p = document.getElementById('advFlt');
  if (t && p) {
    t.addEventListener('click', function () {
      var open = p.hidden;
      p.hidden = !open;
      t.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  var bar = document.getElementById('colGroups');
  if (!bar) return;
  var table = document.querySelector('.card table.data');
  if (!table) { bar.hidden = true; return; }
  var KEY = 'almotawakel-col-groups-v1:' + bar.dataset.key;
  var off = {};
  try { off = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { off = {}; }

  /* ⚠️ بنستخدم نفس كلاس `tbl-col-hidden` بتاع منتقي الأعمدة في app.js عن قصد:
     تصدير CSV بيستثني الخانات اللي عليها الكلاس ده، فلو عملنا كلاس تاني كان
     التصدير هيطلع أعمدة المستخدم مخفيها. */
  function cellsOfGroup(g) {
    var idx = [], out = [];
    Array.prototype.forEach.call(table.tHead.rows[0].cells, function (th, i) {
      if (th.getAttribute('data-col-group') === g) idx.push(i);
    });
    if (!idx.length) return out;
    Array.prototype.forEach.call(table.rows, function (row) {
      idx.forEach(function (i) { if (row.cells[i]) out.push(row.cells[i]); });
    });
    return out;
  }

  function apply(g, on) {
    cellsOfGroup(g).forEach(function (c) { c.classList.toggle('tbl-col-hidden', !on); });
    var b = bar.querySelector('[data-group="' + g + '"]');
    if (b) { b.classList.toggle('is-on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); }
  }

  bar.querySelectorAll('.col-group-btn').forEach(function (b) {
    var g = b.dataset.group;
    if (off[g]) apply(g, false);
    b.addEventListener('click', function () {
      var on = !b.classList.contains('is-on');
      apply(g, on);
      if (on) { delete off[g]; } else { off[g] = 1; }
      try { localStorage.setItem(KEY, JSON.stringify(off)); } catch (e) {}
    });
  });

  var reset = document.getElementById('colGroupsReset');
  if (reset) reset.addEventListener('click', function () {
    bar.querySelectorAll('.col-group-btn').forEach(function (b) { apply(b.dataset.group, true); });
    off = {}; try { localStorage.removeItem(KEY); } catch (e) {}
  });
})();
</script>

<?php if ($detail): ?>

<script>
document.querySelectorAll('tr.rowlink').forEach(function(tr){
  tr.addEventListener('click', function(e){
    if (e.target.closest('a,button,form,input')) return;  // لا تتدخل مع الأزرار
    window.location = tr.dataset.href;
  });
});
</script>
<?php endif; ?>
