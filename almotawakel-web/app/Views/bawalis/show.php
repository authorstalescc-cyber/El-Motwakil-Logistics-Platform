<?php
use App\Models\Bill;
use App\Models\Movement;
// تنسيق قيمة حقل للعرض
$fmt = function(array $f, $v) {
    switch ($f['type']) {
        case 'billtype': return $v==='warid'?'وارد':'صادر';
        case 'status':   return Bill::STATUS_LABELS[$v] ?? '';
        case 'date':     return dfmt($v);
        case 'money':    return money($v);
        case 'pct':      return pct($v);
        default:         return e($v);
    }
};
$groups = ['identity'=>'بيانات التعريف','vehicle'=>'السيارة','revenue'=>'قيم الدين','result'=>'الدين والسداد'];
$byGroup = [];
foreach ($ent['fields'] as $f) { $byGroup[$f['group']][] = $f; }
$itemsByType = ['expense'=>[],'receipt'=>[],'discount'=>[]];
foreach ($items as $it) { $itemsByType[$it['item_type']][] = $it; }
$sum = fn($arr) => array_sum(array_map(fn($x)=>(float)$x['amount'], $arr));
?>
<div class="page-actions">
  <a class="btn ghost" href="<?= url('bawalis') ?>">‹ رجوع للبوالص</a>
  <a class="btn" href="<?= url('bawalis/'.$bill['id'].'/edit') ?>">✏️ تعديل البوليصة</a>
  <?php if ($movement): ?><a class="btn" href="<?= url('movements/'.$movement['id'].'/edit') ?>">🔄 تعديل الحركة</a><?php endif; ?>
  <a class="btn ghost" href="<?= url('settlements/'.$bill['id']) ?>">💵 السداد</a>
  <span style="flex:1"></span>
  <?php if ((int)($bill['is_legacy'] ?? 0) === 1): ?><span class="pill" style="font-size:.95rem;padding:6px 16px;background:#7a5f0e;color:#fff">📦 قديمة</span><?php endif; ?>
  <span class="pill st-<?= e($bill['pay_status']) ?>" style="font-size:.95rem;padding:6px 16px"><?= e(Bill::STATUS_LABELS[$bill['pay_status']]) ?></span>
  <span class="pill pill-<?= e($bill['bill_type']) ?>" style="font-size:.95rem;padding:6px 16px"><?= $bill['bill_type']==='warid'?'وارد':'صادر' ?></span>
</div>

<?php if ((int)($bill['is_legacy'] ?? 0) === 1): ?>
<div class="card mb" style="padding:13px 16px;border-inline-start:5px solid #c9a227;background:#fff9e8;color:#6d5510">
  هذه البوليصة مُرحَّلة من الأرشيف للمتابعة وكشوف أصحاب البيان فقط؛ لا تدخل العهدة أو التقفيل أو التقارير، ولا ترتبط بإذن أو حركة.
</div>
<?php endif; ?>

<!-- بيانات البوليصة -->
<div class="card mb" style="padding:0;overflow:hidden">
  <?php foreach ($groups as $gk=>$gLabel): if (empty($byGroup[$gk])) continue; ?>
    <div class="form-group-title gt-<?= $gk ?>"><?= $gLabel ?></div>
    <div class="grid-auto g4" style="gap:0;padding:0">
      <?php foreach ($byGroup[$gk] as $f):
        // في النوع صادر نخفي الشهادة، وفي وارد نخفي الإذن
        if (($f['key']==='cert_number' && $bill['bill_type']!=='warid') ||
            ($f['key']==='permit_no'   && $bill['bill_type']!=='sader')) continue;
        $rawValue = $bill[$f['key']] ?? '';
        $isPermitRef = in_array($f['key'], ['permit_no','cert_number'], true);
        $linkedPermitId = !empty($permit['id']) ? (int)$permit['id'] : 0;
        $permitRef = trim((string)$rawValue) ?: trim((string)($permit['permit_no'] ?? ''));
      ?>
        <div style="padding:11px 16px;border-bottom:1px solid var(--line);border-inline-start:1px solid var(--line)">
          <div style="font-size:.76rem;color:var(--grey);font-weight:700;margin-bottom:3px"><?= e($f['label']) ?></div>
          <div style="font-weight:700;<?= in_array($f['type'],['money','pct'])?'color:var(--navy)':'' ?>">
            <?php if ($isPermitRef && $linkedPermitId): ?>
              <a class="bill-permit-link" href="<?= url('permits/'.$linkedPermitId) ?>" aria-label="فتح الإذن <?= e($permitRef ?: '#'.$linkedPermitId) ?>">
                <?= e($permitRef ?: '#'.$linkedPermitId) ?><span aria-hidden="true">↗</span>
              </a>
            <?php else: ?>
              <?= $fmt($f,$rawValue) ?: '—' ?>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>

<style>
.bill-permit-link{display:inline-flex;align-items:center;gap:6px;color:var(--teal);font-weight:800;text-decoration:none;border-bottom:1px dashed currentColor}
.bill-permit-link:hover{color:var(--navy)}
.bill-permit-link:focus-visible{outline:2px solid var(--teal);outline-offset:4px;border-radius:3px}
</style>

<div class="two-col">
  <!-- الحركة المرتبطة -->
  <div class="card" style="padding:20px">
    <div class="section-head">الحركة المرتبطة</div>
    <?php
      // بيانات الشحن: من الحركة أولاً، وإلا من الإذن المرتبط
      $permit  = $permit ?? null;
      $shipCo  = ($movement['shipping_co'] ?? '') ?: ($permit['shipping_co'] ?? '');
      $agency  = ($movement['agency'] ?? '')      ?: ($permit['agency'] ?? '');
      $port    = ($movement['port'] ?? '')        ?: ($permit['port'] ?? '');
    ?>
    <div class="grid-2 mb">
      <div><span style="color:var(--grey);font-weight:700">🚢 شركة الشحن:</span> <?= e($shipCo ?: '—') ?></div>
      <div><span style="color:var(--grey);font-weight:700">🧾 التوكيل:</span> <?= e($agency ?: '—') ?></div>
      <div><span style="color:var(--grey);font-weight:700">⚓ الميناء:</span> <?= e($port ?: '—') ?></div>
      <div><span style="color:var(--grey);font-weight:700">مالك السيارة:</span> <?= e($movement['car_owner'] ?? '' ?: '—') ?></div>
    </div>
    <?php if (!$movement): ?>
      <div class="empty"><?= (int)($bill['is_legacy'] ?? 0) === 1 ? 'بوليصة قديمة — لا يُنشأ لها حركة أو بنود.' : 'لا توجد حركة/بنود بعد.' ?></div>
    <?php else: ?>
      <?php foreach (['expense'=>['المصروفات','neg'],'receipt'=>['الإيصالات','pos'],'discount'=>['الخصومات','']] as $tp=>[$lbl,$cls]): ?>
        <?php if ($itemsByType[$tp]): ?>
          <table class="data" style="margin-bottom:10px"><thead><tr>
            <th class="g-identity"><?= $lbl ?></th><th class="g-result">المبلغ</th></tr></thead><tbody>
            <?php foreach ($itemsByType[$tp] as $it): ?>
              <tr><td style="text-align:right"><?= e($it['category'] ?: $it['note']) ?><?php if(!empty($it['is_visa'])): ?> <span class="tl-visa-badge">💳 فيزا</span><?php endif; ?></td><td class="<?= $cls ?>"><?= money($it['amount'],false) ?></td></tr>
            <?php endforeach; ?>
          </tbody><tfoot><tr><td>إجمالي <?= $lbl ?></td><td><?= money($sum($itemsByType[$tp]),false) ?></td></tr></tfoot></table>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if (!$items): ?><div class="empty">لا بنود بعد — اضغط «تعديل الحركة» لإضافتها.</div><?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- الدفعات -->
  <div class="card" style="padding:20px">
    <div class="section-head">دفعات السداد (<?= count($payments) ?>)</div>
    <div class="table-wrap"><table class="data">
      <thead><tr><th class="g-identity">التاريخ</th><th class="g-revenue">المبلغ</th><th class="g-identity">ملاحظة</th></tr></thead>
      <tbody>
        <?php $t=0; foreach ($payments as $p): $t+=$p['amount']; ?>
          <tr><td><?= dfmt($p['pay_date']) ?></td><td class="pos"><?= money($p['amount'],false) ?></td><td><?= e($p['note']) ?><?php if ((int)($p['is_legacy'] ?? 0) === 1): ?> <span class="pill" style="background:#7a5f0e;color:#fff">تاريخي</span><?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if(!$payments): ?><tr><td colspan="3"><div class="empty">لا دفعات بعد</div></td></tr><?php endif; ?>
      </tbody>
      <?php if($payments): ?><tfoot><tr><td>الإجمالي</td><td><?= money($t,false) ?></td><td></td></tr></tfoot><?php endif; ?>
    </table></div>
    <div style="margin-top:14px;display:flex;justify-content:space-between;font-weight:800">
      <span>الدين: <span style="color:var(--navy)"><?= money($bill['debt']) ?></span></span>
      <span>المتبقي: <span class="<?= $bill['remaining']>0?'neg':'pos' ?>"><?= money($bill['remaining']) ?></span></span>
    </div>
  </div>
</div>
