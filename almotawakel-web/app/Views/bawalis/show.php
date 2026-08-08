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
$groups = ['identity'=>'بيانات التعريف','vehicle'=>'السيارة','revenue'=>'قيم الدين',
           'expense'=>'مصروفات السيارة','result'=>'الدين والسداد'];
/* ⚠️ بنود مصروفات السيارة بتظهر **لسيارات المكتب بس** — نفس قاعدة الفورم،
      وبنفس المصدر الوحيد لتطبيع اللوحة (`OfficeCar::matchPlate`). البوليصة
      لعربية مستأجرة بتخفي المجموعة كلها بدل ما تعرض ١٥ خانة كلها صفر. */
$officeCar = \App\Models\OfficeCar::matchPlate((string) ($bill['car_no'] ?? ''));
$byGroup = [];
foreach ($ent['fields'] as $f) {
    if (!empty($f['office']) && $officeCar === null) { continue; }
    $byGroup[$f['group']][] = $f;
}
$itemsByType = ['expense'=>[],'receipt'=>[],'discount'=>[]];
foreach ($items as $it) { $itemsByType[$it['item_type']][] = $it; }
$sum = fn($arr) => array_sum(array_map(fn($x)=>(float)$x['amount'], $arr));
?>
<div class="page-actions">
  <a class="btn ghost" href="<?= url('bawalis') ?>">‹ رجوع للبوالص</a>
  <a class="btn" href="<?= url('bawalis/'.$bill['id'].'/edit') ?>"><?= icon('edit', 14) ?> تعديل البوليصة</a>
  <?php if ($movement): ?><a class="btn" href="<?= url('movements/'.$movement['id'].'/edit') ?>"><?= icon('shuffle', 14) ?> تعديل الحركة</a><?php endif; ?>
  <a class="btn ghost" href="<?= url('settlements/'.$bill['id']) ?>"><?= icon('banknote', 14) ?> السداد</a>
  <span class="bs-sp"></span>
  <?php if ((int)($bill['is_legacy'] ?? 0) === 1): ?><span class="pill legacy-pill"><?= icon('package', 14) ?> قديمة</span><?php endif; ?>
  <span class="pill bs-pill st-<?= e($bill['pay_status']) ?>"><?= e(Bill::STATUS_LABELS[$bill['pay_status']]) ?></span>
  <span class="pill bs-pill pill-<?= e($bill['bill_type']) ?>"><?= $bill['bill_type']==='warid'?'وارد':'صادر' ?></span>
</div>

<?php if ((int)($bill['is_legacy'] ?? 0) === 1): ?>
<div class="card mb legacy-note">
  هذه البوليصة مُرحَّلة من الأرشيف للمتابعة وكشوف أصحاب البيان فقط؛ لا تدخل العهدة أو التقفيل أو التقارير، ولا ترتبط بإذن أو حركة.
</div>
<?php endif; ?>

<!-- بيانات البوليصة -->
<div class="card mb">
  <?php foreach ($groups as $gk=>$gLabel): if (empty($byGroup[$gk])) continue; ?>
    <div class="form-group-title gt-<?= $gk ?>"><?= $gLabel ?></div>
    <div class="bs-facts">
      <?php foreach ($byGroup[$gk] as $f):
        // في النوع صادر نخفي الشهادة، وفي وارد نخفي الإذن
        if (($f['key']==='cert_number' && $bill['bill_type']!=='warid') ||
            ($f['key']==='permit_no'   && $bill['bill_type']!=='sader')) continue;
        $rawValue = $bill[$f['key']] ?? '';
        $isPermitRef = in_array($f['key'], ['permit_no','cert_number'], true);
        $linkedPermitId = !empty($permit['id']) ? (int)$permit['id'] : 0;
        $permitRef = trim((string)$rawValue) ?: trim((string)($permit['permit_no'] ?? ''));
      ?>
        <div class="bs-fact">
          <div class="bs-fact-l"><?= e($f['label']) ?></div>
          <div class="bs-fact-v<?= in_array($f['type'],['money','pct']) ? ' num' : '' ?>">
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
<div class="two-col">
  <!-- الحركة المرتبطة -->
  <div class="card pad">
    <div class="section-head">الحركة المرتبطة</div>
    <?php
      // بيانات الشحن: من الحركة أولاً، وإلا من الإذن المرتبط
      $permit  = $permit ?? null;
      $shipCo  = ($movement['shipping_co'] ?? '') ?: ($permit['shipping_co'] ?? '');
      $agency  = ($movement['agency'] ?? '')      ?: ($permit['agency'] ?? '');
      $port    = ($movement['port'] ?? '')        ?: ($permit['port'] ?? '');
    ?>
    <div class="grid-2 mb">
      <div><span class="bs-k"><?= icon('truck', 14) ?> شركة الشحن:</span> <?= e($shipCo ?: '—') ?></div>
      <div><span class="bs-k"><?= icon('receipt', 14) ?> التوكيل:</span> <?= e($agency ?: '—') ?></div>
      <div><span class="bs-k"><?= icon('anchor', 14) ?> الميناء:</span> <?= e($port ?: '—') ?></div>
      <div><span class="bs-k">مالك السيارة:</span> <?= e($movement['car_owner'] ?? '' ?: '—') ?></div>
    </div>
    <?php if (!$movement): ?>
      <div class="empty"><?= (int)($bill['is_legacy'] ?? 0) === 1 ? 'بوليصة قديمة — لا يُنشأ لها حركة أو بنود.' : 'لا توجد حركة/بنود بعد.' ?></div>
    <?php else: ?>
      <?php foreach (['expense'=>['المصروفات','neg'],'receipt'=>['الإيصالات','pos'],'discount'=>['الخصومات','']] as $tp=>[$lbl,$cls]): ?>
        <?php if ($itemsByType[$tp]): ?>
          <table class="data bs-items"><thead><tr>
            <th class="g-identity"><?= $lbl ?></th><th class="g-result">المبلغ</th></tr></thead><tbody>
            <?php foreach ($itemsByType[$tp] as $it): ?>
              <tr><td class="txt"><?= e($it['category'] ?: $it['note']) ?><?php if(!empty($it['is_visa'])): ?> <span class="tl-visa-badge"><?= icon('credit-card', 14) ?> فيزا</span><?php endif; ?></td><td class="<?= $cls ?>"><?= money($it['amount'],false) ?></td></tr>
            <?php endforeach; ?>
          </tbody><tfoot><tr><td>إجمالي <?= $lbl ?></td><td><?= money($sum($itemsByType[$tp]),false) ?></td></tr></tfoot></table>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if (!$items): ?><div class="empty">لا بنود بعد — اضغط «تعديل الحركة» لإضافتها.</div><?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- الدفعات -->
  <div class="card pad">
    <div class="section-head">دفعات السداد (<?= count($payments) ?>)</div>
    <div class="table-wrap"><table class="data">
      <thead><tr><th class="g-identity">التاريخ</th><th class="g-revenue">المبلغ</th><th class="g-identity">ملاحظة</th></tr></thead>
      <tbody>
        <?php $t=0; foreach ($payments as $p): $t+=$p['amount']; ?>
          <tr><td><?= dfmt($p['pay_date']) ?></td><td class="pos"><?= money($p['amount'],false) ?></td><td><?= e($p['note']) ?><?php if ((int)($p['is_legacy'] ?? 0) === 1): ?> <span class="pill legacy-pill">تاريخي</span><?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if(!$payments): ?><tr><td colspan="3"><div class="empty">لا دفعات بعد</div></td></tr><?php endif; ?>
      </tbody>
      <?php if($payments): ?><tfoot><tr><td>الإجمالي</td><td><?= money($t,false) ?></td><td></td></tr></tfoot><?php endif; ?>
    </table></div>
    <div class="bs-debt">
      <span>الدين: <span class="bs-debt-v"><?= money($bill['debt']) ?></span></span>
      <span>المتبقي: <span class="<?= $bill['remaining']>0?'neg':'pos' ?>"><?= money($bill['remaining']) ?></span></span>
    </div>
  </div>
</div>
