<?php
use App\Models\Freight;
use App\Models\Permit;
use App\Models\PermitInvoice;
use App\Core\FormState;

$pid    = (int)$p['id'];
$type   = $p['permit_type'];
$isClosed = ($p['status'] ?? 'open') === 'closed';
$noLabel  = Permit::noLabel($type);
$vatPct = rtrim(rtrim(number_format($vatRate * 100, 2), '0'), '.');
$formState = $formState ?? ['token'=>FormState::token(),'old'=>[],'errors'=>[],'back'=>'permits/'.$pid];
$old = $formState['old']; $errors = $formState['errors'];
$voidState = $voidState ?? ['token'=>FormState::token(),'old'=>[],'errors'=>[],'back'=>'permits/'.$pid.'/invoice'];
$voidOldId = (int)($voidState['old']['_void_id'] ?? 0);
$docKind = $old['doc_kind'] ?? 'invoice';
$taxableOld = (string)($old['taxable'] ?? '0');
$renderRows = [];
if (is_array($old['item_label'] ?? null)) {
    foreach ($old['item_label'] as $i => $label) {
        $src = (string)($old['item_src'][$i] ?? 'new');
        $renderRows[] = [
            'label'=>(string)$label, 'qty'=>$old['item_qty'][$i] ?? 1,
            'price'=>$old['item_price'][$i] ?? '', 'src'=>$src,
            'role'=>$src === 'freight' ? 'freight' : 'receipt',
        ];
    }
} else {
    $renderRows[] = ['label'=>'النولون','qty'=>1,'price'=>'','src'=>'freight','role'=>'freight'];
    foreach ($candidates as $c) {
        $renderRows[] = [
            'label'=>$c['label'], 'qty'=>(int)($c['qty'] ?? 1),
            'price'=>number_format((float)$c['unit'],2,'.',''),
            'src'=>!empty($c['auto']) ? 'primary_auto' : 'stage:'.(int)$c['stage_id'],
            'role'=>$c['role'] ?? 'receipt',
        ];
    }
}

/* ⚠️ **بوالص الإذن من `Permit::bills`** لا من تجميع بوالص الحركات: العهدة
   (`totals['custody']`) والنولون الفعلي (`PermitInvoice::actualFreight`) الاتنين
   `FROM bawalis WHERE permit_id = ?` — فالنافذة والرقم بيقروا من نفس المصدر
   بالحرف. التجميع من الحركات بيسقّط أي بوليصة مربوطة بالإذن بلا حركة. */
$bills = $bills ?? [];
$expenseRows = $expenseRows ?? [];
$ctBreakdown = $ctBreakdown ?? [];

/* ═══ عدد السيارات — وحدة التسعير الحقيقية (v96) ═══
   النولون بيتحسب **للسيارة** مش للحاوية: الـ٢٠ قدم بتركب اتنين في السيارة
   والـ٤٠ بتركب لوحدها. فـ٨ حاويات ٢٠ قدم = ٤ سيارات = ٤ نولون لا ٨.

   ⚠️ **الرقمين مختلفين عن قصد:** `$carsGuess` اشتقاق من الحاويات (لازم **قبل**
      إدخال البوالص)، و`$carsActual` = عدد البوالص وهو **اليقين** (البوليصة =
      سيارة — متحقَّق على كل فواتير النظام). لما يختلفوا، الاختلاف نفسه معلومة
      تستاهل تتقال: يا إما حاوية اتشالت من سيارة، يا إما بوليصة ناقصة. */
$carsChip = Freight::summary($ctBreakdown, count($bills), (int) ($p['containers'] ?? 0), $p['container_type'] ?? null);
?>
<div class="permit-bar">
  <a class="btn ghost sm" href="<?= url('permits/'.$pid) ?>">‹ رجوع للإذن</a>
  <span class="pb-title"><?= icon('receipt', 14) ?> إصدار فاتورة — <?= Permit::typeLabel($type) ?> <b><?= e($p['permit_no'] ?: '#'.$pid) ?></b></span>
  <span class="pill <?= $isClosed?'st-paid':'st-partial' ?>"><?= $isClosed?'مقفول ✓':'جارٍ' ?></span>
</div>

<?php
/* ⚠️ تنبيه «صدر مستند بالفعل» — لازم يفضل **فوق** الفورم.
   المعلومة دي كانت موجودة في آخر الصفحة (جدول «مستندات صادرة سابقاً») حيث
   لا تُرى قبل الضغط على «إصدار». وأخطر حالة بتوصّل هنا هي **رجوع المتصفح**:
   بعد الحفظ التحويل بيروح لصفحة المستند، فالرجوع بيرجّع الفورم معبّى، ومفيش
   رابط ممكن يمنع ده — الفورم نفسه لازم يقول إن شغله خلص.
   verify_navigation §٦ بيفشل لو التنبيه اتنقل تحت الفورم. */
$issuedDocs = array_values(array_filter($invoices, fn($iv) => ($iv['status'] ?? '') !== 'void'));
?>
<?php if ($issuedDocs): ?>
  <div class="issued-warn js-issued-warn" role="status">
    <span class="iw-ic" aria-hidden="true"><?= icon('alert', 14) ?></span>
    <div class="iw-body">
      <?php /* تحذير **وظيفي** مختصر: الإصدار تاني بيعمل مستنداً برقم جديد.
               التفصيل في `title` — بلا فقرة شرح على الشاشة. */ ?>
      <div class="iw-t" title="رجوع المتصفح بيرجّع الفورم معبّى — والإصدار من هنا بيعمل مستنداً جديداً برقم جديد، مش نسخة من القديم.">
        صدر <?= intf(count($issuedDocs)) ?> مستند — الإصدار بيعمل رقماً جديداً
      </div>
      <div class="iw-list">
        <?php foreach ($issuedDocs as $iv): ?>
          <a class="iw-doc" href="<?= url('permits/invoice/'.(int)$iv['id']) ?>">
            <?= icon('receipt', 14) ?> <?= e($iv['doc_no'] ?: '#'.$iv['id']) ?> · <?= money($iv['total'], false) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- ===== بيانات الإذن ===== -->
<div class="sec-head"><span><?= icon('clipboard', 14) ?> بيانات الإذن</span></div>
<div class="card mb">
  <div class="inv-facts">
    <?php
    $sumRows = [
      ['الحالة', $type==='warid' ? 'وارد (شهادة)' : 'صادر (إذن)'],
      [$noLabel, $p['permit_no'] ?: '—'],
      ['التاريخ', dfmt($p['permit_date']) ?: '—'],
      ['العميل (الوكيل)', $p['client'] ?: '—'],
      ['لصالح شركة', $p['company'] ?: '—'],
      ['الجهة', $p['destination'] ?: '—'],
      ['توكيل ملاحي', ($p['agency'] ?? '') ?: '—'],
      ['الميناء', ($p['port'] ?? '') ?: '—'],
      ['شركة الشحن', ($p['shipping_co'] ?? '') ?: '—'],
      ['عدد الحاويات', intf($p['containers'] ?? 0)],
    ];
    foreach ($sumRows as [$lbl,$val]): ?>
      <div class="inv-fact"><span class="inv-fact-l"><?= e($lbl) ?></span><span class="inv-fact-v"><?= e($val) ?></span></div>
    <?php endforeach; ?>
  </div>
  <?php /* ⚠️ شارة السيارات جنب شارات التصنيف عن قصد: العدد **مشتقّ منها**
           (`Freight::carsFor`)، وفصله في خانة تانية بيخلّي رقمين مرتبطين
           في مكانين. والسهم بيقول إنه ناتج مش بيان مستقل.
           والشرط بقى يشمل الحالة اللي مفيش فيها بوالص لسه — وهي بالظبط
           الحالة اللي التخمين لازم فيها. */ ?>
  <?php if ($ctBreakdown || $carsChip !== ''): ?>
    <div class="inv-ct"><span class="inv-ct-l"><?= icon('package', 13) ?> تصنيف الحاويات</span>
      <?php foreach ($ctBreakdown as $ct): ?><span class="ct-chip"><?= intf($ct['cnt']) ?>×<?= e($ct['type']) ?></span><?php endforeach; ?>
      <?php if ($carsChip !== ''): ?><span class="ct-chip">← <?= e($carsChip) ?></span><?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php /* ⚠️ **ممنوع رقم أصمّ**: كل رقم من الخمسة بيفتح البنود اللي اتحسب منها.
         التفاصيل **جاهزة في الصفحة** (`#invDrill*`) لأن الصفوف محمّلة أصلاً
         للفورم — مش محتاجة طلب تاني للخادم (نفس نمط `custody/index`). */ ?>
<?php partial('stat_grid', ['items' => 5, 'class' => 'mb', 'body' => function () use ($movements, $bills, $totals, $actualFreight) { ?>
  <?php partial('stat', [
    'label' => 'الحركات', 'value' => intf(count($movements)),
    'icon' => 'truck', 'tone' => 'navy',
    'drill' => '#invDrillMoves', 'title' => 'حركات الإذن',
  ]); ?>
  <?php partial('stat', [
    'label' => 'البوالص', 'value' => intf(count($bills)),
    'icon' => 'file-text', 'tone' => 'teal',
    'drill' => '#invDrillBills', 'title' => 'بوالص الإذن',
  ]); ?>
  <?php partial('stat', [
    'label' => 'العهدة', 'value' => money0($totals['custody']),
    'icon' => 'wallet', 'tone' => 'gold',
    'drill' => '#invDrillCustody', 'title' => 'عهدة السائقين',
  ]); ?>
  <?php partial('stat', [
    'label' => 'المصروفات الفعلية', 'value' => money0($totals['expense']),
    'icon' => 'trending-down', 'tone' => 'red',
    'drill' => '#invDrillExpense', 'title' => 'مصروفات الإذن',
  ]); ?>
  <?php partial('stat', [
    'label' => 'النولون الفعلي', 'value' => money0($actualFreight),
    'icon' => 'trending-up', 'tone' => 'green',
    'drill' => '#invDrillFreight', 'title' => 'النولون المسجَّل في البوالص',
  ]); ?>
<?php }]); ?>

<?php
/* أجسام النوافذ — جاهزة في الصفحة ومخفية؛ النافذة العامة بتنسخ محتواها.
   ⚠️ كل مجموع هنا مبني من **نفس الصفوف المعروضة** فمستحيل يخالف الكارت. */
$billRow = function (array $b, string $amount, string $tone): void {
    partial('row_item', [
        'title' => $b['bill_number'] ?: '—',
        'ref'   => $b['bill_date'] ? dfmt($b['bill_date']) : null,
        'icon'  => 'truck',
        'chips' => ($b['container_type'] ?? '') !== '' ? [['icon'=>'package','text'=>intf($b['container_count'] ?? 0).'×'.$b['container_type']]] : [],
        'meta'  => array_values(array_filter([
            ($b['container_no'] ?? '') !== '' ? ['icon'=>'package','text'=>$b['container_no']] : null,
            ($b['driver'] ?? '') !== '' ? ['icon'=>'user','text'=>$b['driver']] : null,
            ($b['destination'] ?? '') !== '' ? ['icon'=>'map-pin','text'=>$b['destination']] : null,
        ])),
        'amount' => $amount, 'amount_tone' => $tone,
    ]);
};
?>
<div id="invDrillMoves" hidden>
  <?php partial('drill', [
    'how'   => 'كل حركة (نقلة) جوّه الإذن وبوالصها.',
    'count' => count($movements),
    'more'  => ['label' => 'شاشة الإذن', 'url' => url('permits/'.$pid)],
    'body'  => function () use ($movements) {
      foreach ($movements as $i => $mv) {
        partial('row_item', [
          'title' => 'حركة ' . intf($i + 1),
          'ref'   => '#' . (int)$mv['id'],
          'icon'  => 'truck',
          'chips' => (int)($mv['container_count'] ?? 0) > 0
              ? [['icon'=>'package','text'=>intf($mv['container_count']).'×'.($mv['container_type'] ?: 'حاوية')]] : [],
          'meta'  => array_values(array_filter([
            ['icon'=>'file-text','text'=>intf(count($mv['bills'])).' بوليصة'],
            ($mv['note'] ?? '') !== '' ? ['icon'=>'info','text'=>$mv['note']] : null,
          ])),
        ]);
      }
    },
  ]); ?>
</div>

<div id="invDrillBills" hidden>
  <?php partial('drill', [
    'how'   => 'البوالص المربوطة بالإذن ده.',
    'count' => count($bills),
    'total' => ['label' => 'الحاويات', 'value' => intf(array_sum(array_column($bills, 'container_count')))],
    'more'  => ['label' => 'شاشة الإذن', 'url' => url('permits/'.$pid)],
    'body'  => function () use ($bills, $billRow) {
      foreach ($bills as $b) { $billRow($b, money((float)$b['freight'], false), 'pos'); }
    },
  ]); ?>
</div>

<div id="invDrillCustody" hidden>
  <?php partial('drill', [
    'how'   => 'عهدة السائق في كل بوليصة — اللي خرج من إيد الشركة.',
    'count' => count($bills),
    'total' => ['label' => 'إجمالي العهدة', 'value' => money($totals['custody'], false)],
    'more'  => ['label' => 'خزنة المكتب', 'url' => url('custody')],
    'body'  => function () use ($bills, $billRow) {
      foreach ($bills as $b) { $billRow($b, money((float)$b['custody'], false), 'rc'); }
    },
  ]); ?>
</div>

<div id="invDrillFreight" hidden>
  <?php partial('drill', [
    'how'   => 'النولون المسجَّل في البوالص — مش اللي هتحاسب بيه العميل في الفاتورة.',
    'count' => count($bills),
    'total' => ['label' => 'إجمالي النولون', 'value' => money($actualFreight, false)],
    'more'  => ['label' => 'شاشة الإذن', 'url' => url('permits/'.$pid)],
    'body'  => function () use ($bills, $billRow) {
      foreach ($bills as $b) { $billRow($b, money((float)$b['freight'], false), 'pos'); }
    },
  ]); ?>
</div>

<div id="invDrillExpense" hidden>
  <?php partial('drill', [
    'how'   => 'كل بند مصروف على الإذن — إيصالات ومصروفات إضافية.',
    'count' => count($expenseRows),
    'total' => ['label' => 'إجمالي المصروفات', 'value' => money($totals['expense'], false)],
    'more'  => ['label' => 'سجل المصروفات', 'url' => url('expenses')],
    'body'  => function () use ($expenseRows) {
      foreach ($expenseRows as $s) {
        $actual = $s['actual_amount'] === null ? null : (float)$s['actual_amount'];
        partial('row_item', [
          'title' => ($s['note'] ?? '') !== '' ? $s['note'] : Permit::label($s["stage_key"]),
          'ref'   => ($s['ref_no'] ?? '') !== '' ? $s['ref_no'] : null,
          'icon'  => 'receipt',
          'meta'  => array_values(array_filter([
            ['icon'=>'calendar','text'=>dfmt($s['entry_date'])],
            (float)($s['qty'] ?? 1) > 1 ? ['icon'=>'package','text'=>intf($s['qty']).' × '.money((float)$s['amount'] / max(1, (float)$s['qty']), false)] : null,
            $actual !== null ? ['icon'=>'trending-up','text'=>'فعلي للعميل: '.money($actual, false)] : null,
          ])),
          'amount' => money((float)$s['amount'], false), 'amount_tone' => 'ex',
        ]);
      }
    },
  ]); ?>
</div>

<?= formErrorSummary($errors) ?>
<form class="js-dirty-form" method="post" action="<?= url('permits/'.$pid.'/invoice') ?>" id="invForm" data-draft-key="permit-invoice-<?= $pid ?>">
  <?= csrfField() ?>
  <?= formStateFields($formState['token'], $formState['back']) ?>

  <!-- (1) نوع المستند + الضريبة -->
  <div class="sec-head"><span>① نوع المستند</span></div>
  <div class="card pad mb">
    <div class="inv-choice">
      <label class="ichip"><input type="radio" name="doc_kind" value="invoice" <?= $docKind==='invoice'?'checked':'' ?>><span>فاتورة</span></label>
      <label class="ichip"><input type="radio" name="doc_kind" value="statement" <?= $docKind==='statement'?'checked':'' ?>><span>كشف حساب</span></label>
      <label class="ichip"><input type="radio" name="doc_kind" value="claim" <?= $docKind==='claim'?'checked':'' ?>><span>مطالبة بالإيصالات</span></label>
    </div>
    <?php /* تحذير **وظيفي**: النوع ده بيستبعد بنودًا فعليًّا من المستند. */ ?>
    <div id="claimHint" class="close-opt" hidden title="مطالبة بالإيصالات تسحب الإيصالات فقط — بلا النولون وبلا الرسوم الأولية وبلا ضريبة القيمة المضافة.">
      <?= icon('alert', 14) ?> إيصالات فقط — بلا نولون ولا ضريبة
    </div>
    <div id="taxBlock">
      <div class="inv-q">خاضعة لضريبة القيمة المضافة (<?= $vatPct ?>%)؟</div>
      <div class="inv-choice">
        <label class="ichip sm"><input type="radio" name="taxable" value="0" <?= $taxableOld!=='1'?'checked':'' ?>><span>غير خاضعة</span></label>
        <label class="ichip sm"><input type="radio" name="taxable" value="1" <?= $taxableOld==='1'?'checked':'' ?>><span>خاضعة للضريبة</span></label>
      </div>
      <div id="internalBlock" class="inv-grid one" hidden>
        <div class="field">
          <label for="internalNo">رقم الفاتورة<span id="internalReq" class="req" aria-hidden="true">*</span></label>
          <input type="text" name="internal_no" id="internalNo" value="<?= e($old['internal_no'] ?? '') ?>">
        </div>
      </div>
    </div>

    <?php /* اسم العميل في الطباعة + العنوان البديل + المسلسل — كلهم اختياريون
             ومالهمش أي أثر على بيانات الإذن (تحذير مختصر على أول واحد). */ ?>
    <div class="inv-grid">
      <div class="field">
        <label for="invoice-display-client">اسم العميل في الطباعة</label>
        <input id="invoice-display-client" type="text" name="display_client" value="<?= e($old['display_client'] ?? ($p['client'] ?? '')) ?>">
        <small class="hint" title="الاسم ده بيظهر على الورقة المطبوعة فقط — ما بيغيّرش اسم العميل المسجّل في الإذن ولا في التقارير.">للطباعة فقط — ما يغيّرش الإذن</small>
      </div>
      <div class="field">
        <label for="invoice-custom-title">عنوان الفاتورة</label>
        <input id="invoice-custom-title" type="text" name="custom_title" value="<?= e($old['custom_title'] ?? '') ?>" placeholder="اتركه فارغاً للعنوان الافتراضي (فاتورة / كشف حساب / مطالبة بالإيصالات)">
      </div>
      <div class="field<?= !empty($errors['serial_no']) ? ' has-error' : '' ?>">
        <label for="invoice-serial-no">رقم مسلسل خاص بالعميل</label>
        <input id="invoice-serial-no" type="text" name="serial_no" value="<?= e($old['serial_no'] ?? '') ?>" placeholder="لا يتكرّر مع نفس العميل"<?= fieldErrorAttrs('serial_no', $errors) ?>>
        <?= fieldError('serial_no', $errors) ?>
      </div>
    </div>
  </div>

  <?php $needAgency = trim((string)($p['agency'] ?? '')) === ''; $needPort = trim((string)($p['port'] ?? '')) === ''; ?>
  <?php if ($needAgency || $needPort): ?>
    <?php /* بيانات ناقصة في الإذن — **تحذير وظيفي**: القيم هتتحفظ في الإذن نفسه. */ ?>
    <div class="card pad mb inv-missing">
      <div class="inv-missing-h"><?= icon('anchor', 14) ?> بيانات ناقصة — هتتحفظ في الإذن</div>
      <div class="inv-grid<?= ($needAgency && $needPort) ? '' : ' one' ?>">
        <?php if ($needAgency): ?>
          <div class="field">
            <label for="invoice-agency">توكيل ملاحي</label>
            <?php partial('lookup_select', ['name'=>'agency','type'=>'agency','value'=>$old['agency'] ?? '','attrs'=>'id="invoice-agency"']); ?>
          </div>
        <?php endif; ?>
        <?php if ($needPort): ?>
          <div class="field">
            <label for="invoice-port">الميناء</label>
            <?php partial('lookup_select', ['name'=>'port','type'=>'port','value'=>$old['port'] ?? '','attrs'=>'id="invoice-port"']); ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- (2) بنود الفاتورة: بيان / كمية / سعر / إجمالي -->
  <div class="sec-head"><span>② بنود الفاتورة</span></div>
  <div class="card mb">
    <div class="table-wrap"><table class="data inv-items" id="itemsTable" data-no-hide>
      <thead><tr>
        <th class="g-identity">البيان</th>
        <th class="g-vehicle col-qty">الكمية</th>
        <th class="g-revenue col-price">السعر</th>
        <th class="g-result col-total">الإجمالي</th>
        <th class="col-del" data-no-sort></th>
      </tr></thead>
      <tbody>
        <?php foreach ($renderRows as $rowIndex => $rr): $isFreightRow = $rr['src'] === 'freight'; ?>
          <tr class="inv-row<?= $isFreightRow?' freight-row':'' ?>" data-role="<?= e($rr['role']) ?>">
            <td>
              <input type="text" name="item_label[]" value="<?= e($rr['label']) ?>" class="i-label" aria-label="بيان البند <?= (int)$rowIndex+1 ?>">
              <input type="hidden" name="item_freight[]" value="<?= $isFreightRow?'1':'0' ?>">
              <input type="hidden" name="item_src[]" value="<?= e($rr['src']) ?>">
              <?php if ($isFreightRow): ?><div class="i-hint">النولون الفعلي: <b><?= money($actualFreight,false) ?></b>
                <?php if ($actualFreight>0): ?><button type="button" class="mini-btn" id="useActual">استخدم الفعلي</button><?php endif; ?></div><?php endif; ?>
            </td>
            <td><input type="number" step="0.01" name="item_qty[]" value="<?= e($rr['qty']) ?>" class="i-qty" aria-label="كمية البند <?= (int)$rowIndex+1 ?>"></td>
            <td><input data-money type="number" step="0.01" name="item_price[]" value="<?= e($rr['price']) ?>" placeholder="0.00" class="i-price" aria-label="سعر البند <?= (int)$rowIndex+1 ?>"></td>
            <td class="i-total">0.00</td>
            <td><?php if(!$isFreightRow): ?><button type="button" class="row-del" title="حذف البند"><?= icon('x', 14) ?></button><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="inv-items-foot">
      <button type="button" class="btn ghost sm" id="addRow"><?= icon('plus', 14) ?> إضافة بند</button>
      <?php /* تحذير **وظيفي**: البند اللي يتكتب هنا مالوش أي أثر على الإذن (قرار المالك 2026-08-01). */ ?>
      <small class="hint" title="البند اللي تكتبه هنا بيظهر في المستند المطبوع فقط — ما بيتسجّلش في الإذن ولا المصروفات ولا الخزنة.">في المستند بس — مش على الإذن</small>
    </div>

    <?php /* ═══ مصاريف الإذن — إضافة اختيارية (طلب المالك 2026-08-01) ═══
             ⚠️ **مصاريف الإذن مش بتتسحب تلقائيًّا** (قرار v20): منها اللي بيتحمّله
                المكتب ومنها اللي بيتحاسب عليه العميل، والسحب الأعمى كان بيحمّل
                العميل حاجات مش بتاعته. فبقت **معروضة والمالك يختار**.
             ⚠️ مطويّة افتراضيًّا — الغالب إن الفاتورة إيصالات ونولون بس.
             ⚠️ البند اللي **متضاف خلاص** بيتقفل زرّاره (المقارنة بـ`stage:{id}`)
                عشان مايتحطش مرتين في نفس الفاتورة. */ ?>
    <?php
      $addedSrc = [];
      foreach ($renderRows as $rr) { $addedSrc[(string) $rr['src']] = true; }
      $expPick = [];
      foreach ($expenseRows as $s) {
          $q = max(1, (int) ($s['qty'] ?? 1));
          // إجمالي العميل = الفعلي لو متحطّ، وإلا المبلغ النقدي (نفس قاعدة `candidateItems`)
          $tot = ($s['actual_amount'] !== null && $s['actual_amount'] !== '')
              ? (float) $s['actual_amount'] : (float) $s['amount'];
          $expPick[] = [
              'id'    => (int) $s['id'],
              'label' => trim((string) $s['note']) !== '' ? (string) $s['note'] : 'مصروف',
              'date'  => $s['entry_date'],
              'qty'   => $q,
              'unit'  => $q > 0 ? round($tot / $q, 2) : round($tot, 2),
              'total' => round($tot, 2),
              'has_actual' => $s['actual_amount'] !== null && $s['actual_amount'] !== '',
              'added' => isset($addedSrc['stage:' . (int) $s['id']]),
          ];
      }
    ?>
    <?php if ($expPick): ?>
      <details class="inv-exp" <?= array_filter($expPick, fn($e) => !$e['added']) ? '' : 'open' ?>>
        <summary>
          <?= icon('receipt', 14) ?> مصاريف الإذن
          <span class="inv-exp-c"><?= intf(count($expPick)) ?></span>
          <small>اختار اللي تحبّ تحطّه في الفاتورة</small>
        </summary>
        <div class="inv-exp-body">
          <?php foreach ($expPick as $e): ?>
            <div class="inv-exp-row<?= $e['added'] ? ' is-added' : '' ?>">
              <div class="inv-exp-main">
                <b><?= e($e['label']) ?></b>
                <span class="inv-exp-meta">
                  <?php if ($e['date']): ?><?= icon('calendar', 12) ?> <?= dfmt($e['date']) ?><?php endif; ?>
                  <?php if ($e['qty'] > 1): ?> · <?= intf($e['qty']) ?> × <?= money($e['unit'], false) ?><?php endif; ?>
                  <?php if (!$e['has_actual']): ?> · <span class="inv-exp-warn">بسعر التكلفة — مفيش سعر فعلي للعميل</span><?php endif; ?>
                </span>
              </div>
              <b class="inv-exp-amt"><?= money($e['total'], false) ?></b>
              <button type="button" class="btn ghost sm js-add-exp"
                      data-id="<?= (int) $e['id'] ?>" data-label="<?= e($e['label']) ?>"
                      data-qty="<?= (int) $e['qty'] ?>" data-unit="<?= number_format($e['unit'], 2, '.', '') ?>"
                      <?= $e['added'] ? 'disabled' : '' ?>>
                <?= $e['added'] ? 'متضاف ✓' : 'أضف للفاتورة' ?>
              </button>
            </div>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endif; ?>
  </div>

  <!-- (3) الإصدار -->
  <div class="sec-head"><span>③ الإجمالي والإصدار</span></div>
  <div class="card pad mb">
    <div class="inv-grid one">
      <div class="field">
        <label for="invoice-note">ملاحظة على الفاتورة</label>
        <textarea id="invoice-note" name="note" rows="2" placeholder="أي بيان إضافي يظهر في المستند"><?= e($old['note'] ?? '') ?></textarea>
      </div>
    </div>

    <div class="inv-grid">
      <div class="field">
        <label for="invoice-date">تاريخ الإصدار<span class="req" aria-hidden="true">*</span></label>
        <input id="invoice-date" type="date" name="invoice_date" value="<?= e($old['invoice_date'] ?? work_date()) ?>" required>
      </div>
      <div class="field">
        <label for="invoice-due-date">تاريخ الاستحقاق</label>
        <input id="invoice-due-date" type="date" name="due_date" value="<?= e($old['due_date'] ?? date('Y-m-d',strtotime(work_date().' +'.(int)($paymentTermsDays ?? 30).' days'))) ?>">
      </div>
    </div>

    <?php if (!$isClosed): ?>
      <?php /* تحذير **وظيفي** — الإصدار بيقفل الإذن، والفتح محتاج إلغاء المستند. */ ?>
      <div class="close-opt" title="بمجرد إصدار المستند بيتقفل الإذن تلقائيًّا. لإعادة فتحه لازم تلغي المستند الأول.">
        <?= icon('lock', 14) ?> الإصدار بيقفل الإذن
      </div>
    <?php endif; ?>

    <div class="inv-summary" data-vat="<?= e($vatRate) ?>">
      <div class="r"><span>إجمالي البنود</span><b id="sumItems">0.00</b></div>
      <div class="r" id="rVat"><span>ق. مضافة (<?= $vatPct ?>%) على النولون</span><b id="sumVat">0.00</b></div>
      <div class="r grand"><span>الإجمالي الكلي</span><b id="sumTotal">0.00</b></div>
    </div>

    <div class="inv-actions">
      <button type="submit" class="btn gold" formaction="<?= url('permits/'.$pid.'/invoice/preview') ?>"><?= icon('search', 14) ?> معاينة قبل الإصدار</button>
      <button type="submit" class="btn ghost" data-primary-submit><?= icon('receipt', 14) ?> إصدار مباشر وحفظ</button>
      <a class="btn ghost" href="<?= url('permits/'.$pid) ?>">إلغاء</a>
    </div>
  </div>
</form>

<!-- سجل الفواتير السابقة لهذا الإذن -->
<?php if ($invoices): ?>
  <div class="sec-head"><span><?= icon('folder', 14) ?> مستندات صادرة سابقاً (<?= intf(count($invoices)) ?>)</span></div>
  <?= formErrorSummary($voidState['errors']) ?>
  <div class="card mb">
    <div class="table-wrap"><table class="data">
      <thead><tr>
        <th class="g-identity">رقم المستند</th><th class="g-identity">النوع</th><th class="g-identity">التاريخ</th>
        <th class="g-identity">الحالة</th><th class="g-result">الإجمالي</th><th class="g-result">المتبقي</th><th class="g-identity" data-no-sort></th>
      </tr></thead>
      <tbody>
      <?php foreach ($invoices as $iv): ?>
        <tr>
          <td class="inv-num"><?= e($iv['doc_no'] ?: '#'.$iv['id']) ?></td>
          <td><span class="pill <?= $iv['doc_kind']==='statement'?'st-partial':'st-paid' ?>"><?= e(PermitInvoice::kindLabel($iv['doc_kind'])) ?><?= $iv['taxable']?' • ضريبية':'' ?></span></td>
          <td><?= dfmt($iv['invoice_date']) ?></td>
          <td><span class="pill"><?= e(PermitInvoice::statusLabel($iv['status'],$iv['due_date'])) ?></span></td>
          <td class="inv-num"><?= money($iv['total'], false) ?></td>
          <td class="inv-num"><?= money($iv['balance_due'],false) ?></td>
          <td><div class="actions">
            <a class="edit" href="<?= url('permits/invoice/'.$iv['id']) ?>" target="_blank" title="طباعة"><?= icon('file-text', 14) ?></a>
            <?php if($iv['status']!=='void'): ?><form class="inv-void" method="post" action="<?= url('permits/invoice/'.$iv['id'].'/delete') ?>"
                  data-danger="1" data-confirm="إلغاء المستند مع الاحتفاظ برقمه؟">
              <?= csrfField() ?><?= formStateFields($voidState['token'], $voidState['back']) ?><input type="hidden" name="_void_id" value="<?= (int)$iv['id'] ?>"><input type="hidden" name="lock_version" value="<?= (int)$iv['lock_version'] ?>">
              <span class="field"><input name="reason" value="<?= $voidOldId===(int)$iv['id'] ? e($voidState['old']['reason'] ?? '') : '' ?>" required placeholder="سبب الإلغاء" aria-label="سبب إلغاء المستند"<?= $voidOldId===(int)$iv['id'] ? fieldErrorAttrs('reason', $voidState['errors']) : '' ?>><?= $voidOldId===(int)$iv['id'] ? fieldError('reason', $voidState['errors']) : '' ?></span><button class="row-del" type="submit" data-primary-submit title="إلغاء المستند"><?= icon('x', 14) ?></button>
            </form><?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
<?php endif; ?>

<script>
(function(){
  var form = document.getElementById('invForm');
  var taxBlock = document.getElementById('taxBlock');
  var internalBlock = document.getElementById('internalBlock');
  var internalNo = document.getElementById('internalNo');
  var internalReq = document.getElementById('internalReq');
  var summary = form.querySelector('.inv-summary');
  var vatRate = parseFloat(summary.getAttribute('data-vat')) || 0;
  var rVat = document.getElementById('rVat');
  var claimHint = document.getElementById('claimHint');
  var tbody = document.querySelector('#itemsTable tbody');

  function kind(){ return form.querySelector('input[name=doc_kind]:checked').value; }
  function taxable(){ return kind()==='invoice' && form.querySelector('input[name=taxable]:checked').value==='1'; }
  function fmt(n){ return (Math.round(n*100)/100).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
  /* ⚠️ `hidden` مش `style.display`: الخصائص الـinline هي اللي كانت بتدهس
     نقاط الكسر المتجاوبة. صفوف الجدول بتفضل على `style.display` لأن `recalc`
     بيقرا `row.style.display` عشان يعرف الصف مستبعَد. */
  function show(el, on){ if(el){ el.hidden = !on; } }

  // «مطالبة بالإيصالات»: تُستبعَد صفوف النولون والرسوم الأولية (تُعطَّل فلا تُرسَل ولا تُحسَب).
  function applyClaim(){
    var isClaim = kind()==='claim';
    tbody.querySelectorAll('.inv-row').forEach(function(row){
      var role = row.getAttribute('data-role');
      var hide = isClaim && (role==='freight' || role==='primary');
      row.style.display = hide ? 'none' : '';
      row.querySelectorAll('input').forEach(function(el){ el.disabled = hide; });
    });
  }

  function syncMode(){
    var k = kind();
    var isInv = k==='invoice';
    show(claimHint, k==='claim');
    show(taxBlock, isInv);
    // خانة رقم الفاتورة اليدوي تظهر للفاتورة فقط، إجبارية للضريبية واختيارية لغيرها.
    show(internalBlock, isInv);
    internalNo.required = (isInv && taxable());
    show(internalReq, taxable());
    show(rVat, taxable());
    applyClaim();
    recalc();
  }

  function recalc(){
    var subtotal = 0, freightTotal = 0;
    tbody.querySelectorAll('.inv-row').forEach(function(row){
      if(row.style.display==='none'){ return; }   // صفوف مستبعَدة (مطالبة بالإيصالات)
      var qty = parseFloat(row.querySelector('.i-qty').value)||0;
      var price = parseFloat(row.querySelector('.i-price').value)||0;
      var t = Math.round(qty*price*100)/100;
      row.querySelector('.i-total').textContent = fmt(t);
      subtotal += t;
      if(row.querySelector('input[name="item_freight[]"]').value==='1'){ freightTotal += t; }
    });
    var vat = taxable() ? Math.round(freightTotal*vatRate*100)/100 : 0;
    document.getElementById('sumItems').textContent = fmt(subtotal);
    document.getElementById('sumVat').textContent = fmt(vat);
    document.getElementById('sumTotal').textContent = fmt(subtotal+vat);
  }

  function wireRow(row){
    row.querySelectorAll('.i-qty,.i-price').forEach(function(el){ el.addEventListener('input', recalc); });
    var del = row.querySelector('.row-del');
    if(del){ del.addEventListener('click', function(){ row.remove(); recalc(); }); }
  }

  tbody.querySelectorAll('.inv-row').forEach(wireRow);
  form.querySelectorAll('input[name=doc_kind],input[name=taxable]').forEach(function(r){ r.addEventListener('change', syncMode); });

  document.getElementById('addRow').addEventListener('click', function(){
    var tr = document.createElement('tr');
    tr.className = 'inv-row';
    tr.setAttribute('data-role', 'new');
    tr.innerHTML =
      '<td><input type="text" name="item_label[]" value="" placeholder="اسم البند" class="i-label" aria-label="بيان البند الجديد">'+
      '<input type="hidden" name="item_freight[]" value="0">'+
      '<input type="hidden" name="item_src[]" value="new"></td>'+
      '<td><input type="number" step="0.01" name="item_qty[]" value="1" class="i-qty" aria-label="كمية البند الجديد"></td>'+
      '<td><input data-money type="number" step="0.01" name="item_price[]" value="" placeholder="0.00" class="i-price" aria-label="سعر البند الجديد"></td>'+
      '<td class="i-total">0.00</td>'+
      '<td><button type="button" class="row-del" title="حذف البند">✕</button></td>';
    tbody.appendChild(tr);
    wireRow(tr);
    tr.querySelector('.i-label').focus();
  });

  /* ═══ إضافة مصروف من مصاريف الإذن (طلب المالك 2026-08-01) ═══
     ⚠️ `item_src = stage:<id>` — نفس مصدر بنود الإيصالات بالظبط، فالمزامنة
        العكسية عند الإصدار بتحدّث `note/qty/actual_amount` للبند ده **بلا ما
        تمسّ `amount` النقدي** (قاعدة v20، والحارس `AND permit_id = ?` في
        الكنترولر فالفورم مش مصدر ثقة).
     ⚠️ الزرار بيتقفل بعد الإضافة عشان البند مايتحطش مرتين في نفس الفاتورة. */
  document.querySelectorAll('.js-add-exp').forEach(function(btn){
    btn.addEventListener('click', function(){
      var tr = document.createElement('tr');
      tr.className = 'inv-row';
      tr.setAttribute('data-role', 'expense');
      var lbl = btn.getAttribute('data-label') || 'مصروف';
      tr.innerHTML =
        '<td><input type="text" name="item_label[]" class="i-label" aria-label="بيان بند المصروف">'+
        '<input type="hidden" name="item_freight[]" value="0">'+
        '<input type="hidden" name="item_src[]"></td>'+
        '<td><input type="number" step="0.01" name="item_qty[]" class="i-qty" aria-label="كمية بند المصروف"></td>'+
        '<td><input data-money type="number" step="0.01" name="item_price[]" class="i-price" aria-label="سعر بند المصروف"></td>'+
        '<td class="i-total">0.00</td>'+
        '<td><button type="button" class="row-del" title="حذف البند">✕</button></td>';
      // ⚠️ القيم بتتحط بـ`value` مش جوّه الـHTML — البيان جاي من إدخال المستخدم
      //    (ملاحظة البند)، ولزقه في نص HTML بيبقى ثغرة XSS.
      tr.querySelector('.i-label').value = lbl;
      tr.querySelector('input[name="item_src[]"]').value = 'stage:' + btn.getAttribute('data-id');
      tr.querySelector('.i-qty').value   = btn.getAttribute('data-qty') || 1;
      tr.querySelector('.i-price').value = btn.getAttribute('data-unit') || '';
      tbody.appendChild(tr);
      wireRow(tr);
      recalc();
      btn.disabled = true;
      btn.textContent = 'متضاف ✓';
      btn.closest('.inv-exp-row').classList.add('is-added');
      tr.scrollIntoView({block:'nearest'});
    });
  });

  var ua = document.getElementById('useActual');
  if(ua){ ua.addEventListener('click', function(){
    document.querySelector('.freight-row .i-price').value = '<?= number_format($actualFreight,2,'.','') ?>'; recalc();
  }); }

  syncMode();
})();
</script>
