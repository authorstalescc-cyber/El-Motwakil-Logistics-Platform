<?php
$labels = [
  'bawalis'=>'بوالص', 'permits'=>'أذونات وشهادات', 'permit_stages'=>'مصروفات وإيصالات الأذونات',
  'manual_expenses'=>'مصروفات يدوية', 'bill_payments'=>'سداد بوالص', 'movements'=>'حركات',
  'movement_items'=>'بنود حركات', 'takhlis'=>'تخليص', 'generators'=>'مولدات', 'users'=>'مستخدمون',
  'client_payments'=>'سداد عملاء', 'manual_custody'=>'عهدة', 'rep_entries'=>'حسابات مناديب',
  'visa_deposits'=>'إيداعات فيزا', 'debt_payments'=>'سداد مديونيات',
  'permit_invoices'=>'فواتير العملاء', 'owner_statements'=>'كشوف أصحاب البيان',
  'agents'=>'الوكلاء', 'companies'=>'الشركات', 'declaration_owners'=>'أصحاب البيان',
  'rep_submissions'=>'بنود المناديب',
];
$actions = ['create'=>'إضافة','update'=>'تعديل','delete'=>'حذف','restore'=>'استرجاع','undo'=>'تراجع','login_free'=>'دخول حر','bulk_update'=>'تعديل جماعي',
  'issue'=>'إصدار','void'=>'إلغاء','reverse'=>'عكس','allocate'=>'تخصيص','alias'=>'اسم بديل','merge'=>'دمج','move'=>'نقل'];
$mode = $mode ?? 'operations';
$isEntries = $mode === 'entries';
$diffRows = function(array $log): array {
  $old=json_decode((string)($log['old_json']??''),true)?:[];$new=json_decode((string)($log['new_json']??''),true)?:[];$out=[];
  foreach(array_unique(array_merge(array_keys($old),array_keys($new))) as $key){
    if(in_array($key,['password_hash','created_at','updated_at'],true))continue;
    $a=$old[$key]??null;$b=$new[$key]??null;if((string)$a===(string)$b)continue;
    $out[]=[$key,is_scalar($a)?(string)$a:'…',is_scalar($b)?(string)$b:'…'];if(count($out)>=12)break;
  }return $out;
};
$entryState = function(array $log): string {
  $work=(string)($log['work_date']??'');$record=(string)($log['record_date']??'');
  if($work===''||$record==='')return 'unknown';
  return $work===$record?'matched':'mismatch';
};
$entryHref = function(array $log): ?string {
  $id=(int)($log['record_id']??0);$meta=json_decode((string)($log['meta_json']??''),true)?:[];
  return match($log['entity']) {
    'bawalis' => $id ? url('bawalis/'.$id) : null,
    'permits' => $id ? url('permits/'.$id) : null,
    'permit_stages' => !empty($meta['permit_id']) ? url('permits/'.(int)$meta['permit_id'].'/manage') : null,
    'manual_expenses' => $id ? url('expenses/'.$id.'/edit') : url('expenses'),
    default => null,
  };
};
$matched=$mismatch=$unknown=0;
if($isEntries){foreach($logs as $log){$state=$entryState($log);if($state==='matched')$matched++;elseif($state==='mismatch')$mismatch++;else$unknown++;}}
?>

<div class="audit-tabs">
  <a class="audit-tab <?= !$isEntries?'active':'' ?>" href="<?= url('audit') ?>"><?= icon('receipt', 14) ?> سجل العمليات</a>
  <a class="audit-tab <?= $isEntries?'active':'' ?>" href="<?= url('audit?mode=entries') ?>">✍️ سجل الإدخالات</a>
  <?php /* للمالك بس — والمنع الحقيقي في Access::OWNER_ONLY مش في الإخفاء ده */ ?>
  <?php if (App\Core\Auth::isOwner()): ?>
    <a class="audit-tab" href="<?= url('security') ?>"><?= icon('alert', 14) ?> المراقبة الأمنية</a>
  <?php endif; ?>
</div>

<?php if($isEntries): ?>
<div class="entry-intro">
  <div><strong>مراجعة الإدخالات الجديدة</strong><span>قارن تاريخ العمل وقت الإدخال بتاريخ المستند المحفوظ، وافتح السجل الأصلي مباشرةً عند الحاجة.</span></div>
  <div class="entry-counts">
    <span class="entry-count ok"><?= icon('check', 14) ?> مطابق <b><?= intf($matched) ?></b></span>
    <span class="entry-count bad">! مختلف <b><?= intf($mismatch) ?></b></span>
    <?php if($unknown): ?><span class="entry-count muted">— بلا تاريخ <b><?= intf($unknown) ?></b></span><?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="toolbar">
  <form method="get" action="<?= url('audit') ?>" class="no-confirm audit-filter">
    <?php if($isEntries): ?><input type="hidden" name="mode" value="entries"><?php endif; ?>
    <select name="entity"><option value="">كل الأقسام</option><?php foreach ($labels as $k=>$v): ?><option value="<?= e($k) ?>" <?= $entity===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select>
    <?php if(!$isEntries): ?><select name="action"><option value="">كل العمليات</option><?php foreach ($actions as $k=>$v): ?><option value="<?= e($k) ?>" <?= $action===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select><?php endif; ?>
    <label>من <input type="date" name="from" value="<?= e($from??'') ?>"></label>
    <label>إلى <input type="date" name="to" value="<?= e($to??'') ?>"></label>
    <button class="btn sm" type="submit">تصفية</button>
    <?php if($entity!==''||($from??'')!==''||($to??'')!==''||(!$isEntries&&$action!=='')): ?><a class="btn ghost sm" href="<?= url($isEntries?'audit?mode=entries':'audit') ?>">مسح</a><?php endif; ?>
  </form>
  <?php if(!$isEntries): ?><div class="spacer"></div><a class="btn ghost sm" href="#trash"><?= icon('trash', 14) ?> سلة المحذوفات (<?= intf(count($trash)) ?>)</a><?php endif; ?>
</div>

<?php if($isEntries): ?>
<?php partial('count_note', ['shown'=>count($logs), 'total'=>(int)($logsTotal ?? count($logs)), 'unit'=>'إدخال']); ?>
<div class="card"><div class="table-wrap"><table class="data entry-table">
  <thead><tr><th>وقت الإدخال</th><th>المستخدم</th><th>نوع الإدخال</th><th>البيان</th><th>تاريخ العمل</th><th>تاريخ المستند</th><th>المراجعة</th><th></th></tr></thead>
  <tbody><?php foreach ($logs as $log): $state=$entryState($log);$href=$entryHref($log); ?>
    <tr class="entry-row <?= $state==='mismatch'?'date-alert':'' ?>">
      <td><bdi dir="ltr"><?= e(dtfmt($log['created_at'], 'd/m/Y H:i:s')) ?></bdi></td>
      <td><?= e($log['user_name'] ?: 'النظام') ?></td>
      <td><span class="pill"><?= e($labels[$log['entity']] ?? $log['entity']) ?></span><small class="record-no">#<?= e($log['record_id'] ?: '—') ?></small></td>
      <td class="entry-desc"><?= e($log['description'] ?: 'إضافة سجل جديد') ?></td>
      <td class="audit-date"><bdi dir="ltr"><?= ($log['work_date']??'')!=='' ? e(dfmt($log['work_date'])) : '—' ?></bdi></td>
      <td class="audit-date"><bdi dir="ltr"><?= ($log['record_date']??'')!=='' ? e(dfmt($log['record_date'])) : '—' ?></bdi></td>
      <td><?php if($state==='matched'): ?><span class="date-state ok"><?= icon('check', 14) ?> مطابق</span><?php elseif($state==='mismatch'): ?><span class="date-state bad"><?= icon('alert', 14) ?> تاريخ مختلف</span><?php else: ?><span class="date-state muted">غير متاح</span><?php endif; ?></td>
      <td><?php if($href): ?><a class="btn ghost sm" href="<?= e($href) ?>">فتح</a><?php endif; ?></td>
    </tr>
  <?php endforeach; ?><?php if (!$logs): ?><tr><td colspan="8"><div class="empty">لا توجد إدخالات مطابقة للفلاتر.</div></td></tr><?php endif; ?></tbody>
</table></div></div>
<?php else: ?>
<?php partial('count_note', ['shown'=>count($logs), 'total'=>(int)($logsTotal ?? count($logs)), 'unit'=>'عملية']); ?>
<div class="card"><div class="table-wrap"><table class="data">
  <thead><tr><th>الوقت</th><th>تاريخ العمل</th><th>المستخدم</th><th>العملية</th><th>القسم</th><th>السجل</th><th>البيان</th><th>قبل / بعد</th><th></th></tr></thead>
  <tbody><?php foreach ($logs as $log): $changes=$diffRows($log); ?>
    <tr>
      <td><bdi dir="ltr"><?= e(dtfmt($log['created_at'], 'd/m/Y H:i:s')) ?></bdi></td><td><?= ($log['work_date']??'')!==''?e(dfmt($log['work_date'])):'—' ?></td><td><?= e($log['user_name'] ?: 'النظام') ?></td>
      <td><span class="pill"><?= e($actions[$log['action']] ?? $log['action']) ?></span></td>
      <td><?= e($labels[$log['entity']] ?? $log['entity']) ?></td><td><?= e($log['record_id'] ?: '—') ?></td>
      <td><?= e($log['description'] ?: '—') ?></td>
      <td><?php if($changes): ?><details class="audit-diff"><summary><?= intf(count($changes)) ?> تغيير</summary><div><?php foreach($changes as [$key,$a,$b]): ?><p><b><?= e($key) ?></b><span><?= e($a===''?'—':$a) ?> ← <?= e($b===''?'—':$b) ?></span></p><?php endforeach; ?></div></details><?php else: ?>—<?php endif; ?></td>
      <td><?php if ((int)$log['can_undo'] && !$log['undone_at']): ?><form method="post" action="<?= url('audit/'.$log['id'].'/undo') ?>" data-confirm="استعادة القيم السابقة لهذه العملية؟"><?= csrfField() ?><button class="btn ghost sm" type="submit">↶ تراجع</button></form><?php elseif ($log['undone_at']): ?><span class="pill st-paid">تم التراجع</span><?php endif; ?></td>
    </tr>
  <?php endforeach; ?><?php if (!$logs): ?><tr><td colspan="9"><div class="empty">لا توجد عمليات مطابقة.</div></td></tr><?php endif; ?></tbody>
</table></div></div>

<div class="section-head" id="trash" style="margin-top:24px"><?= icon('trash', 17) ?> ️ سلة المحذوفات</div>
<div class="alert" style="margin-bottom:10px">السلة تسترجع فقط: <?php foreach(($restorableEntities??[]) as $i=>$entity): ?><?= $i?'، ':'' ?><?= e($labels[$entity]??$entity) ?><?php endforeach; ?>. الفواتير والكشوف والتحصيلات المالية لا تُحذف؛ تُلغى أو تُعكس وتظل ظاهرة في سجل العمليات.</div>
<?php partial('count_note', ['shown'=>count($trash), 'total'=>(int)($trashTotal ?? count($trash)), 'unit'=>'سجل']); ?>
<div class="card"><div class="table-wrap"><table class="data">
  <thead><tr><th>وقت الحذف</th><th>المستخدم</th><th>القسم</th><th>السجل</th><th>البيان</th><th></th></tr></thead>
  <tbody><?php foreach ($trash as $item): ?>
    <tr><td><bdi dir="ltr"><?= e(dtfmt($item['created_at'], 'd/m/Y H:i:s')) ?></bdi></td><td><?= e($item['deleted_by_name'] ?: 'النظام') ?></td><td><?= e($labels[$item['entity']] ?? $item['entity']) ?></td><td><?= e($item['record_id'] ?: '—') ?></td><td><?= e($item['label'] ?: '—') ?></td>
    <td><form method="post" action="<?= url('audit/trash/'.$item['id'].'/restore') ?>" data-confirm="استرجاع هذا السجل بكل بياناته؟"><?= csrfField() ?><button class="btn gold sm" type="submit">♻️ استرجاع</button></form></td></tr>
  <?php endforeach; ?><?php if (!$trash): ?><tr><td colspan="6"><div class="empty">سلة المحذوفات فارغة.</div></td></tr><?php endif; ?></tbody>
</table></div></div>
<?php endif; ?>

<style>
.audit-tabs{display:flex;gap:8px;margin-bottom:14px}.audit-tab{padding:10px 16px;border:1px solid var(--line);border-radius:var(--radius-2);background:var(--card);font-weight:600;color:var(--muted)}.audit-tab.active{background:var(--navy);color:#fff;border-color:var(--navy)}
.entry-intro{display:flex;justify-content:space-between;align-items:center;gap:18px;padding:16px 18px;margin-bottom:14px;border:1px solid #cdd9e8;border-radius:var(--radius-2);background:linear-gradient(135deg,#f8fbff,#eef5fc)}.entry-intro>div:first-child{display:grid;gap:4px}.entry-intro strong{font-size:var(--fs-4);color:var(--navy)}.entry-intro span{font-size:var(--fs-1)}.entry-counts{display:flex;gap:8px;flex-wrap:wrap}.entry-count{padding:7px 10px;border-radius:var(--radius-1);font-weight:600;white-space:nowrap}.entry-count.ok,.date-state.ok{background:#e7f6ee;color:#147142}.entry-count.bad,.date-state.bad{background:#fff0ed;color:#b43525}.entry-count.muted,.date-state.muted{background:#f0f2f5;color:#6c7580}
.audit-filter{display:flex;align-items:end;gap:8px;flex-wrap:wrap}.audit-filter label{display:grid;gap:3px;font-size:var(--fs-1);font-weight:600;color:var(--muted)}.audit-filter input{min-width:145px}.entry-row.date-alert td{background:#fff9f7}.entry-row.date-alert td:first-child{border-right:4px solid #d34b38}.entry-desc{min-width:270px;font-weight:500}.audit-date{font-weight:600;white-space:nowrap}.date-state{display:inline-block;padding:6px 9px;border-radius:var(--radius-1);font-size:var(--fs-1);font-weight:700;white-space:nowrap}.record-no{display:block;margin-top:4px;color:var(--muted);direction:ltr;text-align:right}.audit-diff{position:relative}.audit-diff summary{cursor:pointer;color:var(--navy);font-weight:600}.audit-diff>div{min-width:300px;max-width:440px;padding:8px}.audit-diff p{display:grid;grid-template-columns:110px 1fr;gap:8px;margin:0;padding:5px;border-bottom:1px solid var(--line);font-size:var(--fs-1);text-align:right}.audit-diff span{direction:rtl;overflow-wrap:anywhere}
@media(max-width:800px){.entry-intro{align-items:flex-start;flex-direction:column}.audit-tabs{overflow:auto}.audit-tab{white-space:nowrap}}
</style>
