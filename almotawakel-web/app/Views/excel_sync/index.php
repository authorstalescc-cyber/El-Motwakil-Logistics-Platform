<?php
$filters = [
    'changes' => 'بها فروق',
    'issues' => 'تحتاج مراجعة',
    'office' => 'سيارات المكتب',
    'unchanged' => 'متطابقة',
    'all' => 'كل الصفوف',
];
$matchLabels = ['matched'=>'مرتبطة', 'unmatched'=>'غير موجودة', 'ambiguous'=>'رقم مكرر'];
$query = static fn(array $override = []) => http_build_query(array_merge(
    ['filter'=>$filter, 'q'=>$q], $override
));
?>

<div class="page-head">
  <div>
    <h1><?= icon('clipboard', 24) ?> مراجعة مزامنة Excel</h1>
    <p>افتح كل بوليصة، قارن النظام مع Excel، واختر بنفسك الحقول التي تريد اعتمادها.</p>
  </div>
  <?php partial('back_link', ['to' => 'bawalis', 'label' => 'رجوع للبوالص']); ?>
</div>

<?php if ($error): ?>
  <div class="flash error" role="alert"><?= icon('alert', 17) ?> <?= e($error) ?></div>
<?php else: ?>
  <div class="excel-source card">
    <div>
      <b><?= e($source['name'] ?? '') ?></b>
      <span class="muted">آخر تعديل: <?= e($source['modified'] ?? 'غير معروف') ?> — <?= money(($source['size'] ?? 0) / 1048576, false) ?> MB</span>
    </div>
    <span class="pill <?= !empty($sourceState['ready']) ? 'st-paid' : 'st-partial' ?>">
      <?= !empty($sourceState['ready']) ? 'جاهز للمراجعة والتنفيذ' : 'يحتاج أحدث نسخة' ?>
    </span>
  </div>

  <?php if (($source['mode'] ?? '') === 'server_snapshot'): ?>
    <div class="card excel-upload-card">
      <div>
        <b><?= icon('download', 18) ?> حدّث نسخة المراجعة</b>
        <p>نزّل أحدث نسخة XLSX من Excel Online وارفعها هنا. الرفع لا يعدّل أي بوليصة.</p>
        <?php if (!empty($sourceState['meta'])): $meta = $sourceState['meta']; ?>
          <small class="muted">آخر رفع: <?= e($meta['uploaded_at'] ?? '—') ?> — <?= e($meta['original_name'] ?? '') ?></small>
        <?php endif; ?>
      </div>
      <form method="post" action="<?= url('bawalis/excel-sync/upload') ?>" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="_form_token" value="<?= e($uploadFormToken ?? '') ?>">
        <input type="hidden" name="_back" value="bawalis/excel-sync">
        <input type="file" name="excel_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
        <button class="btn gold" type="submit"><?= icon('download', 15) ?> رفع أحدث نسخة</button>
      </form>
    </div>
  <?php endif; ?>

  <?php if (empty($sourceState['ready'])): ?>
    <div class="flash warning"><?= icon('alert', 17) ?> <?= e($sourceState['reason'] ?? 'حدّث ملف Excel أولًا.') ?> يمكنك مشاهدة المقارنة، لكن التنفيذ سيظل مقفولًا.</div>
  <?php endif; ?>

  <div class="excel-rules card">
    <b>طريقة العمل الجديدة</b>
    <span>لا يوجد زر «اعتماد الكل».</span>
    <span>كل بوليصة لها مقارنة كاملة واختيارات مستقلة.</span>
    <span>بعد الاختيار تظهر صفحة ثانية تلخّص ما سيتغير بالضبط.</span>
    <span>الخصم والعهدة والقيم المحسوبة تظهر للمراجعة فقط ولا تُفرض من Excel.</span>
  </div>

  <div class="excel-stats">
    <div class="card"><small>صفوف Excel</small><b><?= intf($summary['excel_rows'] ?? 0) ?></b></div>
    <div class="card"><small>مرتبطة</small><b><?= intf($summary['matched'] ?? 0) ?></b></div>
    <div class="card"><small>بها فروق</small><b><?= intf($summary['changed'] ?? 0) ?></b></div>
    <div class="card"><small>غير موجودة</small><b><?= intf($summary['unmatched'] ?? 0) ?></b></div>
    <div class="card"><small>تعارضات</small><b><?= intf($summary['conflicts'] ?? 0) ?></b></div>
    <div class="card"><small>سيارات مكتب</small><b><?= intf($summary['office_rows'] ?? 0) ?></b></div>
  </div>

  <div class="toolbar excel-toolbar">
    <form method="get" action="<?= url('bawalis/excel-sync') ?>">
      <select name="filter">
        <?php foreach ($filters as $key=>$label): ?>
          <option value="<?= e($key) ?>" <?= $filter===$key?'selected':'' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="بحث…">
      <button class="btn sm" type="submit">عرض</button>
    </form>
    <span class="count-badge"><?= intf($total) ?> نتيجة</span>
  </div>

  <div class="excel-review-list">
    <?php if (!$rows): ?>
      <div class="card empty"><div class="ic"><?= icon('clipboard', 18) ?></div>لا توجد نتائج في هذا العرض.</div>
    <?php else: foreach ($rows as $row): ?>
      <article class="card excel-row-card">
        <div class="excel-row-main">
          <div class="excel-row-title">
            <span class="excel-row-no">صف <?= intf($row['excel_row']) ?></span>
            <h3>بوليصة <?= e($row['bill_number']) ?></h3>
            <span class="pill <?= $row['match']==='matched'?'st-paid':($row['match']==='ambiguous'?'st-partial':'st-unpaid') ?>">
              <?= e($matchLabels[$row['match']] ?? $row['match']) ?>
            </span>
            <?php if (!empty($row['is_office_excel'])): ?><span class="office-mark">سيارة مكتب</span><?php endif; ?>
          </div>
          <div class="excel-row-sides">
            <div>
              <small>في النظام</small>
              <b><?= e($row['bill']['declaration_owner'] ?? '—') ?></b>
              <span><?= e($row['bill']['car_no'] ?? '—') ?> · <?= e($row['bill']['company'] ?? '—') ?></span>
            </div>
            <div>
              <small>في Excel</small>
              <b><?= e($row['declaration_owner'] ?? '—') ?></b>
              <span><?= e($row['car_no'] ?? '—') ?> · <?= e($row['company'] ?? '—') ?></span>
            </div>
          </div>
          <?php if (!empty($row['changes'])): ?>
            <div class="excel-change-chips">
              <?php foreach (array_slice($row['changes'], 0, 8) as $change): ?>
                <span><?= e($change['label']) ?></span>
              <?php endforeach; ?>
              <?php if (count($row['changes']) > 8): ?><span>+<?= intf(count($row['changes']) - 8) ?></span><?php endif; ?>
            </div>
          <?php else: ?><small class="muted">لا توجد فروق في الحقول القابلة للمقارنة.</small><?php endif; ?>
          <?php foreach ($row['conflicts'] as $message): ?><div class="excel-msg conflict"><?= icon('alert', 13) ?> <?= e($message) ?></div><?php endforeach; ?>
          <?php foreach ($row['warnings'] as $message): ?><div class="excel-msg warning"><?= icon('alert', 13) ?> <?= e($message) ?></div><?php endforeach; ?>
        </div>
        <div class="excel-row-action">
          <?php if (($row['match'] ?? '') === 'matched'): ?>
            <a class="btn" href="<?= url('bawalis/excel-sync/review?row=' . (int)$row['excel_row']) ?>">
              <?= icon('search', 15) ?> قارن واختر
            </a>
            <a class="btn ghost sm" href="<?= url('bawalis/' . (int)$row['bill']['id']) ?>">فتح البوليصة</a>
          <?php else: ?>
            <span class="muted">لا يمكن التنفيذ حتى تصبح المطابقة فريدة.</span>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; endif; ?>
  </div>

  <?php if ($pages > 1): ?>
    <nav class="pager" aria-label="صفحات المعاينة">
      <?php if ($page > 1): ?><a class="btn ghost sm" href="?<?= $query(['page'=>$page-1]) ?>">السابق</a><?php endif; ?>
      <span>صفحة <?= intf($page) ?> من <?= intf($pages) ?></span>
      <?php if ($page < $pages): ?><a class="btn ghost sm" href="?<?= $query(['page'=>$page+1]) ?>">التالي</a><?php endif; ?>
    </nav>
  <?php endif; ?>
<?php endif; ?>

<style>
.excel-source,.excel-upload-card{display:flex;justify-content:space-between;align-items:center;gap:18px;margin-bottom:12px}
.excel-source>div,.excel-upload-card>div{display:flex;flex-direction:column;gap:5px}.muted{color:var(--grey)}
.excel-upload-card form{display:flex;align-items:center;gap:9px;flex-wrap:wrap}.excel-upload-card p{margin:6px 0;color:var(--grey)}
.flash.warning{background:var(--gold-light);color:var(--gold-fg);border:1px solid var(--gold)}
.excel-rules{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 18px;margin:14px 0}
.excel-rules b{grid-column:1/-1}.excel-rules span:before{content:"✓";color:var(--green-fg);font-weight:700;margin-left:6px}
.excel-stats{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin:14px 0}
.excel-stats .card{padding:14px}.excel-stats small{display:block;color:var(--grey);margin-bottom:6px}.excel-stats b{font-size:var(--fs-6)}
.excel-toolbar{margin:8px 0 12px}.excel-review-list{display:grid;gap:11px}
.excel-row-card{display:flex;align-items:stretch;justify-content:space-between;gap:18px;border-inline-start:4px solid var(--line2)}
.excel-row-main{flex:1;min-width:0}.excel-row-title{display:flex;align-items:center;gap:9px;flex-wrap:wrap}.excel-row-title h3{margin:0}
.excel-row-no{color:var(--grey);font-size:var(--fs-1)}.office-mark{color:var(--gold-fg);font-size:var(--fs-1);font-weight:700}
.excel-row-sides{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;margin:12px 0}
.excel-row-sides>div{background:var(--zebra);border:1px solid var(--line);border-radius:9px;padding:9px 11px;display:grid;gap:3px}
.excel-row-sides small{color:var(--grey)}.excel-row-sides span{font-size:var(--fs-2);color:var(--grey)}
.excel-change-chips{display:flex;gap:6px;flex-wrap:wrap}.excel-change-chips span{background:var(--gold-light);color:var(--gold-fg);border-radius:999px;padding:4px 8px;font-size:var(--fs-1)}
.excel-row-action{min-width:145px;display:flex;flex-direction:column;justify-content:center;gap:8px}
.excel-msg{padding:7px 8px;border-radius:8px;margin-top:7px;font-size:var(--fs-2)}.excel-msg.conflict{background:var(--red-lt);color:var(--red-fg)}.excel-msg.warning{background:var(--gold-light);color:var(--gold-fg)}
.pager{display:flex;justify-content:center;align-items:center;gap:14px;margin-top:14px}
@media(max-width:900px){.excel-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.excel-rules{grid-template-columns:1fr}.excel-source,.excel-upload-card,.excel-row-card{align-items:flex-start;flex-direction:column}.excel-upload-card form,.excel-row-action{width:100%}.excel-row-sides{grid-template-columns:1fr}}
</style>
