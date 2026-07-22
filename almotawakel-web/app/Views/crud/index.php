<?php
use App\Core\FormState;
$returnPath = current_app_path();
// الأعمدة المعروضة: list_fields إن وُجدت، وإلا كل الحقول
$allFields = $ent['fields'];
if (!empty($ent['list_fields'])) {
    $byKey = [];
    foreach ($allFields as $f) { $byKey[$f['key']] = $f; }
    $fields = array_values(array_filter(array_map(fn($k) => $byKey[$k] ?? null, $ent['list_fields'])));
} else {
    $fields = $allFields;
}
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
$monthNames = ['','يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
$qs = fn(array $ov=[]) => http_build_query(array_merge(
    ['q'=>$q,'year'=>$year,'month'=>$month,'source'=>$source,'legacy'=>$legacy ?? '',
     'sort'=>$sort ?? '', 'dir'=>$dir ?? 'desc'], $ov));
?>
<div class="toolbar">
  <form method="get" action="<?= url($ent['route']) ?>">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="بحث في <?= e($ent['title']) ?>…">
    <select name="year">
      <option value="">كل السنوات</option>
      <?php foreach ($years as $y): ?>
        <option value="<?= e($y) ?>" <?= (string)$year===(string)$y?'selected':'' ?>><?= e($y) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="month">
      <option value="">كل الشهور</option>
      <?php for ($m=1;$m<=12;$m++): ?>
        <option value="<?= $m ?>" <?= (string)$month===(string)$m?'selected':'' ?>><?= $monthNames[$m] ?></option>
      <?php endfor; ?>
    </select>
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
    <?php if ($q||$year||$month||$source||($legacy??'')!==''): ?><a class="btn ghost sm" href="<?= url($ent['route']) ?>">إلغاء</a><?php endif; ?>
  </form>
  <div class="spacer"></div>
  <span class="count-badge"><?= intf($total) ?> سجل</span>
  <?php if ($bulkBills): ?><button class="btn ghost" type="button" id="openBillPaste">📋 لصق من Excel</button><?php endif; ?>
  <?php if (!empty($ent['has_legacy'])): ?><a class="btn ghost" href="<?= url('bawalis/new-legacy?back=' . rawurlencode($returnPath)) ?>">🗂️ إضافة بوليصة قديمة</a><?php endif; ?>
  <?php $newQuery = array_filter(['source'=>(!empty($ent['has_source']) ? $source : ''),'back'=>$returnPath], fn($v)=>$v!==''); ?>
  <a class="btn gold" href="<?= url($ent['route'].'/new?'.http_build_query($newQuery)) ?>">➕ إضافة<?= !empty($ent['has_legacy'])?' بوليصة حالية':'' ?></a>
</div>

<?php if ($bulkBills) { require __DIR__ . '/../bawalis/_bulk_actions.php'; } ?>

<?php if ($bulkBills): ?>
<div class="bulk-modal" id="billPasteModal" hidden<?= ($pasteState['errors']['rows'] ?? '') !== '' ? ' data-reopen="1"' : '' ?>><div class="bulk-modal-card" role="dialog" aria-modal="true" aria-labelledby="billPasteTitle">
  <div class="bulk-modal-head"><b id="billPasteTitle">إضافة بوالص بسرعة من Excel</b><button type="button" class="bulk-close" aria-label="إغلاق">×</button></div>
  <form method="post" action="<?= url('bawalis/bulk-paste') ?>" class="no-confirm" style="padding:18px"><?= csrfField() ?>
    <p class="bulk-hint">انسخ العناوين والصفوف من Excel كما هي. العملية كاملة: إذا أخطأ صف واحد فلن تُضاف أي بوليصة.</p>
    <div class="paste-template">رقم البوليصة &nbsp; التاريخ &nbsp; النوع &nbsp; صاحب البيان &nbsp; التصنيف &nbsp; الشركة &nbsp; الجهة &nbsp; الحاوية &nbsp; تصنيف الحاوية &nbsp; عدد الحاويات &nbsp; السيارة &nbsp; السائق &nbsp; صاحب السيارة &nbsp; النولون &nbsp; العهدة &nbsp; تحويلة &nbsp; خصم &nbsp; بياتة</div>
    <?php /* إعادة التعبئة بعد الفشل — اللصق كله كان بيضيع ويضطر ينسخ من أول وجديد */
          $pasteErr = $pasteState['errors']['rows'] ?? ''; ?>
    <?php if ($pasteErr !== ''): ?>
      <div class="flash error" role="alert" style="margin-top:10px"><?= icon('alert',17) ?> <?= e($pasteErr) ?></div>
    <?php endif; ?>
    <textarea name="rows" rows="12" required spellcheck="false" dir="rtl" placeholder="الصق هنا من Excel…" style="width:100%;margin-top:10px;padding:12px;border:1px solid var(--line2);border-radius:10px;font-family:inherit;white-space:pre"><?= e($pasteState['old']['rows'] ?? '') ?></textarea>
    <div id="billPastePreview" class="paste-preview" hidden></div>
    <div class="bulk-modal-actions"><button class="btn ghost" type="button" id="previewBillPaste">👁 معاينة</button><button class="btn gold" type="submit" id="submitBillPaste" disabled>➕ إضافة الصفوف المعاينة</button><button class="btn ghost bulk-close" type="button">إلغاء</button></div>
  </form>
</div></div>
<script>(function(){var b=document.getElementById('openBillPaste'),m=document.getElementById('billPasteModal');if(!b||!m)return;var ta=m.querySelector('textarea'),preview=document.getElementById('billPastePreview'),submit=document.getElementById('submitBillPaste');function close(){m.hidden=true;document.body.style.overflow=''}b.addEventListener('click',function(){m.hidden=false;document.body.style.overflow='hidden';setTimeout(function(){ta.focus()},20)});m.querySelectorAll('.bulk-close').forEach(function(x){x.addEventListener('click',close)});m.addEventListener('click',function(e){if(e.target===m)close()});ta.addEventListener('input',function(){submit.disabled=true;preview.hidden=true});if(m.dataset.reopen==='1'){m.hidden=false;document.body.style.overflow='hidden';setTimeout(function(){ta.focus();ta.setSelectionRange(ta.value.length,ta.value.length)},20);}document.getElementById('previewBillPaste').addEventListener('click',function(){var lines=ta.value.trim().split(/\r?\n/).filter(Boolean),rows=lines.map(function(l){return l.split('\t')});if(lines.length<2){preview.hidden=false;preview.innerHTML='<b class="neg">الصق صف العناوين وصف بيانات واحدًا على الأقل.</b>';return}var max=Math.min(6,rows.length),html='<b>سيتم إرسال '+(rows.length-1)+' صف. معاينة أول '+(max-1)+':</b><div class="table-wrap"><table class="data"><tbody>';for(var i=0;i<max;i++){html+='<tr>'+rows[i].slice(0,8).map(function(v){return '<'+(i?'td':'th')+'>'+v.replace(/[&<>]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;'}[c]})+'</'+(i?'td':'th')+'>'}).join('')+'</tr>'}preview.innerHTML=html+'</tbody></table></div>';preview.hidden=false;submit.disabled=false});})();</script>
<style>.paste-template{direction:rtl;padding:9px 11px;border:1px dashed #c9a227;border-radius:9px;background:#fff9e8;color:#6d5510;font-size:.76rem;line-height:1.8}.paste-preview{margin-top:12px;padding:10px;border:1px solid var(--line);border-radius:10px;background:#f8fafc;max-height:230px;overflow:auto}.paste-preview table{margin-top:8px;font-size:.72rem}</style>
<?php endif; ?>

<div class="card">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <?php if ($bulkBills): ?><th class="g-result" style="width:38px"><input type="checkbox" class="bill-pick js-bill-pick-all" title="تحديد كل بوالص الصفحة"></th><?php endif; ?>
          <th class="g-identity"><a class="server-sort" href="?<?= $qs(['sort'=>'id','dir'=>(($sort??'')==='id'&&($dir??'')==='asc')?'desc':'asc','page'=>1]) ?>">م <?= ($sort??'')==='id'?(($dir??'')==='asc'?'↑':'↓'):'⇅' ?></a></th>
          <?php foreach ($fields as $f): ?>
            <th class="g-<?= $f['group'] ?>"><a class="server-sort" href="?<?= $qs(['sort'=>$f['key'],'dir'=>(($sort??'')===$f['key']&&($dir??'')==='asc')?'desc':'asc','page'=>1]) ?>"><?= e($f['label']) ?> <?= ($sort??'')===$f['key']?(($dir??'')==='asc'?'↑':'↓'):'⇅' ?></a></th>
          <?php endforeach; ?>
          <th class="g-identity">إجراءات</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="<?= count($fields)+2+($bulkBills?1:0) ?>">
            <div class="empty"><div class="ic">📭</div>لا توجد بيانات — اضغط «إضافة» لبدء الإدخال.</div>
          </td></tr>
        <?php else: $i = ($page-1)*50; foreach ($rows as $row): $i++; ?>
          <?php $isLegacyRow = !empty($ent['has_legacy']) && (int)($row['is_legacy'] ?? 0) === 1; ?>
          <tr<?= $detail ? ' class="rowlink'.($isLegacyRow?' legacy-row':'').'" data-href="'.url($ent['route'].'/'.$row['id']).'"' : ($isLegacyRow?' class="legacy-row"':'') ?>>
            <?php if ($bulkBills): ?><td><input type="checkbox" class="bill-pick js-bill-pick" value="<?= (int)$row['id'] ?>"
              data-no="<?= e($row['bill_number'] ?: '#'.$row['id']) ?>" data-date="<?= e($row['bill_date'] ?? '') ?>"
              data-freight="<?= e(number_format((float)($row['freight'] ?? 0),2,'.','')) ?>" data-legacy="<?= (int)($row['is_legacy'] ?? 0) ?>" title="تحديد البوليصة"></td><?php endif; ?>
            <td><?= intf($i) ?><?php if ($isLegacyRow): ?><span class="legacy-pill" title="بوليصة قديمة معزولة عن العهدة والتقفيل">قديم</span><?php endif; ?></td>
            <?php foreach ($fields as $f): [$txt,$cls] = $fmtCell($f, $row[$f['key']] ?? null); ?>
              <td class="<?= !empty($f['calc'])?'calc':'' ?> <?= $cls ?>"><?= $txt ?></td>
            <?php endforeach; ?>
            <td>
              <div class="actions">
                <?php if ($detail): ?><a href="<?= url($ent['route'].'/'.$row['id']) ?>" title="تفاصيل">👁️</a><?php endif; ?>
                <?php if (!empty($ent['invoice'])): ?><a href="<?= url($ent['route'].'/'.$row['id'].'/invoice') ?>" target="_blank" title="فاتورة (طباعة / PDF)">🧾</a><?php endif; ?>
                <a class="edit" href="<?= url($ent['route'].'/'.$row['id'].'/edit?back=' . rawurlencode($returnPath)) ?>" title="تعديل">✏️</a>
                <form class="confirm-delete" method="post" action="<?= url($ent['route'].'/'.$row['id'].'/delete') ?>" style="margin:0">
                  <?= csrfField() ?>
                  <?= formStateFields(FormState::token(), $returnPath) ?>
                  <button class="del" type="submit" title="حذف">🗑️</button>
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

<?php if ($detail): ?>
<style>
tr.rowlink{cursor:pointer}tr.rowlink:hover{background:var(--gold-light)!important}
tr.legacy-row{background:#fff9e8}tr.legacy-row:hover{background:#fff1c6!important}
.legacy-pill{display:block;width:max-content;margin:4px auto 0;padding:2px 7px;border-radius:999px;background:#7a5f0e;color:#fff;font-size:.66rem;font-weight:800}
.server-sort{color:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:5px;white-space:nowrap}.server-sort:hover{text-decoration:underline}
</style>
<script>
document.querySelectorAll('tr.rowlink').forEach(function(tr){
  tr.addEventListener('click', function(e){
    if (e.target.closest('a,button,form,input')) return;  // لا تتدخل مع الأزرار
    window.location = tr.dataset.href;
  });
});
</script>
<?php endif; ?>
