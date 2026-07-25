<?php
use App\Core\FormState;
/**
 * وحدة المديونيات — صفحة موحّدة بتبويبات.
 * @var string $tab            التبويب النشط
 * @var array  $ov             نظرة عامة (إجماليات)
 * @var array  $inspAcc        حساب الكشف والضمان
 * @var array  $inspCharges    تفصيل الكشف والضمان لكل إذن
 * @var array  $inspTotals     ['charges','paid','remaining']
 * @var array  $inspPayments   دفعات ياسر أبو عمر
 * @var array  $ownersSum      ملخّص أصحاب البيان
 * @var array  $topOwners      أعلى أصحاب البيان مديونيةً
 * @var array  $manual         الحسابات اليدوية (+ totals)
 * @var array  $vf             فلاتر الفيزا
 * @var array  $visaRows       صفوف عمليات الفيزا
 * @var array  $visaTotals     إجماليات الفيزا
 * @var array  $visaSources    تسميات مصادر الفيزا
 * @var array  $visaCats       فئات المصروفات (لنموذج إضافة الفيزا)
 */
$typePill = ['sader' => ['pill-sader','صادر'], 'warid' => ['pill-warid','وارد']];
$vqs = fn(array $ov=[]) => http_build_query(array_merge(
    ['tab'=>'visa','q'=>$vf['q'],'source'=>$vf['source'],'from'=>$vf['from'],'to'=>$vf['to']], $ov));
$vHasFilter = $vf['q']||$vf['source']||$vf['from']||$vf['to'];
$srcClass = ['manual'=>'manual','permits'=>'permits','bawalis'=>'bawalis','debt'=>'takhlis'];
$accountState = $accountState ?? ['token'=>FormState::token(),'old'=>[],'errors'=>[],'back'=>'debts?tab=others'];
$visaState = $visaState ?? ['token'=>FormState::token(),'old'=>[],'errors'=>[],'back'=>'debts?tab=visa'];
?>
<style>
.debts-tabs{display:flex;flex-wrap:wrap;gap:8px;margin:6px 0 18px;border-bottom:2px solid var(--line);padding-bottom:2px}
.debts-tabs .tab-btn{background:transparent;border:0;padding:10px 18px;border-radius:10px 10px 0 0;
  font-family:inherit;font-size:.95rem;font-weight:700;color:var(--grey);cursor:pointer;position:relative;top:2px}
.debts-tabs .tab-btn.active{color:var(--navy);background:var(--card);border:2px solid var(--line);border-bottom:2px solid var(--card)}
.tab-panel{display:none;animation:tp .18s ease}
.tab-panel.active{display:block}
@keyframes tp{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}
/* العناوين: ثبّت المحاذاة لليمين (تفادي تعارض space-between العام مع الشريط الذهبي) */
.tab-panel .section-head,#tab-others .section-head{justify-content:flex-start}
.tab-panel .section-head.spread{justify-content:space-between}
.debt-figs{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:16px}
.debt-figs .fig{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px 12px;text-align:center;overflow:hidden}
.debt-figs .fig .lbl{font-size:.8rem;color:var(--grey);font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.debt-figs .fig .val{font-size:1.4rem;font-weight:800;margin-top:4px}
.debt-figs .fig .val.navy{color:var(--navy)} .debt-figs .fig .val.pos{color:var(--green)} .debt-figs .fig .val.neg{color:var(--red)}
.note-line{background:#f6f8ff;border:1px dashed #c5cbf0;border-radius:12px;padding:11px 15px;color:var(--navy);font-size:.86rem;line-height:1.7;margin-bottom:14px}
.mini-owners{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px}
.mo-card{display:flex;flex-direction:column;gap:6px;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px;box-shadow:var(--shadow);cursor:pointer;transition:.16s}
.mo-card:hover{transform:translateY(-3px);box-shadow:0 12px 26px rgba(21,53,91,.14);border-color:#cfd9e6}
.mo-card .nm{display:flex;align-items:center;gap:8px;font-weight:800;color:var(--navy)}
.mo-card .nm .av{width:30px;height:30px;border-radius:50%;background:var(--gold-light,#faf3df);color:var(--navy);display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:800;flex-shrink:0}
.mo-card .row{display:flex;justify-content:space-between;font-size:.85rem}
.mo-card .row b.neg{color:var(--red)} .mo-card .row b.pos{color:var(--green)}
.exp-cards{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:16px}
.exp-grand{flex:1 1 220px;background:var(--navy);color:#fff;border-radius:14px;padding:16px 20px}
.exp-grand .lbl{font-size:.85rem;opacity:.85}
.exp-grand .val{font-size:1.7rem;font-weight:800;color:var(--gold);margin-top:4px}
.exp-grand .sub{font-size:.8rem;opacity:.8;margin-top:4px}
.exp-src{flex:1 1 150px;background:#fff;border:1px solid var(--line);border-radius:14px;padding:12px 16px}
.exp-src .lbl{font-size:.85rem;color:var(--grey)}
.exp-src .val{font-size:1.15rem;font-weight:700;margin-top:4px}
.exp-src .cnt{font-size:.75rem;color:var(--grey)}
.src-pill{display:inline-block;padding:2px 10px;border-radius:999px;font-size:.8rem;font-weight:600;white-space:nowrap}
.src-manual{background:#e7f0fb;color:#15355B}
.src-permits{background:#efe7fb;color:#5b2f8f}
.src-bawalis{background:#fbe7ec;color:#8f2f45}
.src-takhlis{background:#e7f6ef;color:#1f7a52}
.visa-badge{display:inline-block;background:#fdf0e3;color:#8a5a12;border-radius:999px;padding:1px 8px;font-size:.72rem;font-weight:700}
</style>

<!-- ============ نظرة عامة ============ -->
<div class="kpi-grid mb four">
  <?php /* ⚠️ **ممنوع رقم أصمّ**: الرقم ده مجموع التلاتة اللي جنبه، فبيوصّل
           لأول تبويب فيهم بدل ما يفضل رقمًا مالوش طريق. */ ?>
  <a class="kpi red" href="<?= url('debts') ?>?tab=owners" title="تفاصيل المديونيات">
    <div class="lbl">إجمالي المديونيات على الشركة</div>
    <div class="val"><?= intf($ov['grand_remaining']) ?><span class="cur">ج.م</span></div></a>
  <a class="kpi navy" href="<?= url('settlements') ?>" title="أصحاب بيان البوالص">
    <div class="lbl">متبقّي أصحاب البيان</div>
    <div class="val"><?= intf($ov['owners']['remaining']) ?><span class="cur">ج.م</span></div></a>
  <a class="kpi gold" href="<?= url('debts') ?>?tab=inspection" title="الكشف والضمان">
    <div class="lbl">متبقّي الكشف والضمان</div>
    <div class="val"><?= intf($ov['inspection']['remaining']) ?><span class="cur">ج.م</span></div></a>
  <a class="kpi green" href="<?= url('debts') ?>?tab=visa" title="عمليات الفيزا">
    <div class="lbl">إجمالي عمليات الفيزا</div>
    <div class="val"><?= intf($ov['visa']['grand']) ?><span class="cur">ج.م</span></div></a>
</div>

<!-- ============ التبويبات ============ -->
<div class="debts-tabs" role="tablist" aria-label="أنواع المديونيات">
  <button type="button" class="tab-btn<?= $tab==='inspection'?' active':'' ?>" data-tab="inspection">🛡️ الكشف والضمان</button>
  <button type="button" class="tab-btn<?= $tab==='owners'?' active':'' ?>" data-tab="owners">👤 أصحاب بيان البوالص</button>
  <button type="button" class="tab-btn<?= $tab==='others'?' active':'' ?>" data-tab="others">📌 مديونيات أخرى</button>
  <button type="button" class="tab-btn<?= $tab==='visa'?' active':'' ?>" data-tab="visa">💳 تتبع الفيزا</button>
</div>

<!-- ============ تبويب: الكشف والضمان ============ -->
<div class="tab-panel<?= $tab==='inspection'?' active':'' ?>" id="tab-inspection">
  <!-- تفصيل المديونية لكل إذن -->
  <div class="card" style="padding:18px;margin-bottom:16px">
    <div class="section-head spread">
      <span>📄 تفصيل الكشف والضمان على الأذونات</span>
      <span class="count-badge"><?= intf(count($inspCharges['rows'])) ?> إذن</span>
    </div>
    <div class="table-wrap"><table class="data">
      <thead><tr>
        <th class="g-identity">النوع</th><th class="g-identity">رقم الإذن</th>
        <th class="g-identity">الجهة</th><th class="g-identity">حاويات</th><th class="g-identity">عدد الكشوفات</th>
        <th class="g-expense">الكشف</th><th class="g-expense">الضمان</th><th class="g-expense">الإجمالي</th>
      </tr></thead>
      <tbody>
        <?php foreach ($inspCharges['rows'] as $r):
          [$pc,$pl] = $typePill[$r['permit_type']] ?? ['pill-sader',$r['permit_type']]; ?>
          <tr>
            <td><span class="pill <?= $pc ?>"><?= e($pl) ?></span></td>
            <td><a href="<?= url('permits/'.$r['id']) ?>"><?= e($r['permit_no'] ?: '#'.$r['id']) ?></a></td>
            <td><?= e($r['company'] ?: $r['client'] ?: '—') ?></td>
            <td><?= intf($r['containers']) ?></td>
            <td><?= intf($r['units']) ?></td>
            <td><?= money($r['inspection'], false) ?>
              <div style="font-size:.72rem;color:var(--grey)"><?= $r['inspection_rate']>0 ? 'سعر '.money($r['inspection_rate'],false) : 'بلا سعر' ?></div></td>
            <td><?= money($r['guarantee'], false) ?>
              <div style="font-size:.72rem;color:var(--grey)"><?= $r['guarantee_rate']>0 ? 'سعر '.money($r['guarantee_rate'],false) : 'بلا سعر' ?></div></td>
            <td style="font-weight:700"><?= money($r['amount'], false) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$inspCharges['rows']): ?>
          <tr><td colspan="8"><div class="empty">لا توجد أذونات بعد.</div></td></tr>
        <?php endif; ?>
      </tbody>
      <?php if ($inspCharges['rows']): ?>
      <tfoot><tr>
        <td colspan="3" style="text-align:left;font-weight:700">الإجمالي</td>
        <td style="font-weight:800"><?= intf($inspCharges['containers']) ?></td>
        <td style="font-weight:800"><?= intf($inspCharges['units']) ?></td>
        <td style="font-weight:800"><?= money($inspCharges['inspection_total'], false) ?></td>
        <td style="font-weight:800"><?= money($inspCharges['guarantee_total'], false) ?></td>
        <td style="font-weight:800"><?= money($inspCharges['total'], false) ?></td>
      </tr></tfoot>
      <?php endif; ?>
    </table></div>
  </div>

  <!-- حسابان منفصلان: الكشف | الضمان — كل واحد لمستحقه ودفعاته -->
  <?php
    $accounts = [
      ['a'=>$inspAcc, 't'=>$inspTotals, 'pays'=>$inspPayments, 'ic'=>'🔎', 'label'=>'الكشف'],
      ['a'=>$guarAcc, 't'=>$guarTotals, 'pays'=>$guarPayments, 'ic'=>'🛡️', 'label'=>'الضمان'],
    ];
  ?>
  <div class="two-col">
    <?php foreach ($accounts as $blk): $a=$blk['a']; $t=$blk['t']; $pays=$blk['pays'];
      $creditor = trim((string)$a['name']);
      $payState = $paymentStates[(int)$a['id']] ?? ['token'=>FormState::token(),'old'=>[],'errors'=>[],'back'=>'debts?tab=inspection'];
      $payOld = $payState['old']; $payErrors = $payState['errors']; ?>
      <div class="card" style="padding:18px">
        <div class="section-head">
          <?= $blk['ic'] ?> <?= e($blk['label']) ?> — <?= $creditor !== '' ? e($creditor) : '<span style="color:var(--grey);font-weight:700">غير محدّد</span>' ?>
        </div>
        <div class="debt-figs" style="margin-bottom:12px">
          <div class="fig"><div class="lbl">المديونية</div><div class="val navy"><?= intf($t['charges']) ?></div></div>
          <div class="fig"><div class="lbl">المدفوع</div><div class="val pos"><?= intf($t['paid']) ?></div></div>
          <div class="fig"><div class="lbl">المتبقّي</div><div class="val <?= $t['remaining']>0.005?'neg':'pos' ?>"><?= intf($t['remaining']) ?></div></div>
        </div>

        <?= formErrorSummary($payErrors) ?>
        <form class="inline-form js-dirty-form" method="post" action="<?= url('debts/account/'.$a['id'].'/pay') ?>">
          <?= csrfField() ?>
          <?= formStateFields($payState['token'], $payState['back']) ?>
          <div class="field" style="margin:0"><input type="number" step="0.01" min="0.01" name="amount" value="<?= e($payOld['amount'] ?? '') ?>" placeholder="المبلغ" required<?= fieldErrorAttrs('amount', $payErrors) ?>><?= fieldError('amount', $payErrors) ?></div>
          <input type="date" name="pay_date" value="<?= e($payOld['pay_date'] ?? work_date()) ?>">
          <input type="text" name="note" value="<?= e($payOld['note'] ?? '') ?>" placeholder="ملاحظة (اختياري)">
          <label style="display:flex;align-items:center;gap:6px;font-weight:700;font-size:.85rem;white-space:nowrap">
            <input type="checkbox" name="is_visa" value="1" <?= !empty($payOld['is_visa'])?'checked':'' ?> style="width:16px;height:16px;accent-color:var(--gold)">💳 فيزا</label>
          <?= visa_card_select((int)($payOld['visa_card_id'] ?? 0), 'visa_card_id', 'max-width:160px') ?>
          <button class="btn gold sm" type="submit" data-primary-submit>➕ دفع</button>
        </form>

        <div class="table-wrap" style="margin-top:12px"><table class="data">
          <thead><tr><th class="g-identity">التاريخ</th><th class="g-revenue">المبلغ</th><th class="g-identity">ملاحظة</th><th class="g-identity">حذف</th></tr></thead>
          <tbody>
            <?php $tp=0; foreach ($pays as $p): $tp+=$p['amount']; ?>
              <tr>
                <td><?= dfmt($p['pay_date']) ?></td>
                <td class="pos"><?= money($p['amount'],false) ?><?= !empty($p['is_visa'])?' <span class="visa-badge">فيزا</span>':'' ?></td>
                <td><?= e($p['note']) ?></td>
                <td>
                  <form class="confirm-delete" method="post" action="<?= url('debts/payment/'.$p['id'].'/delete') ?>" style="margin:0">
                    <?= csrfField() ?><?= formStateFields(FormState::token(), 'debts?tab=inspection') ?>
                    <button class="del" type="submit" title="حذف">🗑️</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if(!$pays): ?><tr><td colspan="4"><div class="empty">لا دفعات بعد</div></td></tr><?php endif; ?>
          </tbody>
          <?php if($pays): ?><tfoot><tr><td>الإجمالي</td><td><?= money($tp,false) ?></td><td colspan="2"></td></tr></tfoot><?php endif; ?>
        </table></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ============ تبويب: أصحاب بيان البوالص ============ -->
<div class="tab-panel<?= $tab==='owners'?' active':'' ?>" id="tab-owners">
  <div class="debt-figs g4">
    <div class="fig"><div class="lbl">عدد أصحاب البيان</div><div class="val navy"><?= intf($ownersSum['owners']) ?></div></div>
    <div class="fig"><div class="lbl">إجمالي المديونية</div><div class="val navy"><?= intf($ownersSum['debt']) ?></div></div>
    <div class="fig"><div class="lbl">المدفوع لهم</div><div class="val pos"><?= intf($ownersSum['paid']) ?></div></div>
    <div class="fig"><div class="lbl">المتبقّي لهم</div><div class="val <?= $ownersSum['remaining']>0.005?'neg':'pos' ?>"><?= intf($ownersSum['remaining']) ?></div></div>
  </div>

  <div class="page-actions" style="margin-bottom:14px">
    <a class="btn gold" href="<?= url('settlements') ?>">💵 افتح صفحة سداد البوالص</a>
    <a class="btn ghost" href="<?= url('settlements') ?>?status=due">عرض غير المسدّد فقط</a>
  </div>

  <div class="section-head" style="justify-content:space-between">
    <span>🔺 أعلى أصحاب البيان مديونيةً</span>
    <span class="count-badge"><?= intf((int)$ownersSum['due_owners']) ?> لهم متبقٍّ</span>
  </div>
  <?php if (!$topOwners): ?>
    <div class="card"><div class="empty" style="padding:36px">لا مديونيات متبقّية على أصحاب البيان. 🎉</div></div>
  <?php else: ?>
    <div class="mini-owners">
      <?php foreach ($topOwners as $o):
        $label = $o['owner_key'] === '' ? '(بدون صاحب بيان)' : $o['owner_key'];
        $ourl  = url('settlements') . '?owner=' . urlencode($o['owner_key']); ?>
        <div class="mo-card" onclick="location.href='<?= $ourl ?>'">
          <div class="nm"><span class="av"><?= e(initials($label)) ?></span><span title="<?= e($label) ?>"><?= e($label) ?></span></div>
          <div class="row"><span>عدد البوالص</span><b><?= intf($o['bills']) ?></b></div>
          <div class="row"><span>المتبقّي</span><b class="neg"><?= money($o['remaining'],false) ?></b></div>
          <div class="row" style="font-size:.75rem;color:var(--grey)"><span>آخر بوليصة</span><span><?= $o['last_date']?dfmt($o['last_date']):'—' ?></span></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- ============ تبويب: مديونيات أخرى ============ -->
<div class="tab-panel<?= $tab==='others'?' active':'' ?>" id="tab-others">
  <div class="two-col">
    <div class="card" style="padding:18px">
      <div class="section-head"><?= icon('plus', 17) ?> إضافة مديونية جديدة</div>
      <?= formErrorSummary($accountState['errors']) ?>
      <form class="form-card js-dirty-form" method="post" action="<?= url('debts/account') ?>" style="box-shadow:none;padding:0;border:0">
        <?= csrfField() ?>
        <?= formStateFields($accountState['token'], $accountState['back']) ?>
        <div class="form-grid one">
          <div class="field"><label>اسم الجهة الدائنة</label>
            <input type="text" name="name" value="<?= e($accountState['old']['name'] ?? '') ?>" required<?= fieldErrorAttrs('name', $accountState['errors']) ?>><?= fieldError('name', $accountState['errors']) ?></div>
          <div class="field"><label>أصل المديونية</label>
            <input type="number" step="0.01" name="opening" value="<?= e($accountState['old']['opening'] ?? '') ?>" placeholder="0"></div>
          <div class="field"><label>ملاحظة (اختياري)</label>
            <input type="text" name="note" value="<?= e($accountState['old']['note'] ?? '') ?>"></div>
        </div>
        <div class="form-actions" style="margin-top:12px"><button class="btn gold" type="submit" data-primary-submit>💾 حفظ</button></div>
      </form>
    </div>

    <div class="card" style="padding:18px">
      <div class="section-head" style="justify-content:space-between">
        <span>📋 المديونيات الأخرى</span>
        <span class="count-badge"><?= intf(count($manual)) ?> جهة</span>
      </div>
      <div class="table-wrap"><table class="data">
        <thead><tr><th class="g-identity">الجهة</th><th class="g-revenue">الأصل</th><th class="g-revenue">المدفوع</th><th class="g-expense">المتبقّي</th><th class="g-identity">إجراءات</th></tr></thead>
        <tbody>
          <?php $ta=0;$tp=0;$tr=0; foreach ($manual as $a): $ta+=$a['totals']['charges'];$tp+=$a['totals']['paid'];$tr+=$a['totals']['remaining']; ?>
            <tr>
              <td><a href="<?= url('debts/account/'.$a['id']) ?>"><b><?= e($a['name']) ?></b></a>
                  <?php if($a['note']): ?><br><span style="color:var(--grey);font-size:.78rem"><?= e($a['note']) ?></span><?php endif; ?></td>
              <td><?= money($a['totals']['charges'],false) ?></td>
              <td class="pos"><?= money($a['totals']['paid'],false) ?></td>
              <td class="<?= $a['totals']['remaining']>0.005?'neg':'pos' ?>"><?= money($a['totals']['remaining'],false) ?></td>
              <td><div class="actions">
                <a class="edit" href="<?= url('debts/account/'.$a['id']) ?>" title="الدفعات والتفاصيل">💵</a>
                <form class="confirm-delete" method="post" action="<?= url('debts/account/'.$a['id'].'/delete') ?>" style="margin:0">
                  <?= csrfField() ?><?= formStateFields(FormState::token(), 'debts?tab=others') ?><button class="del" type="submit" title="حذف">🗑️</button>
                </form>
              </div></td>
            </tr>
          <?php endforeach; ?>
          <?php if(!$manual): ?><tr><td colspan="5"><div class="empty">لا مديونيات أخرى مسجّلة بعد.</div></td></tr><?php endif; ?>
        </tbody>
        <?php if($manual): ?><tfoot><tr><td>الإجمالي</td><td><?= money($ta,false) ?></td><td class="pos"><?= money($tp,false) ?></td><td class="neg"><?= money($tr,false) ?></td><td></td></tr></tfoot><?php endif; ?>
      </table></div>
    </div>
  </div>
</div>

<!-- ============ تبويب: تتبع الفيزا ============ -->
<div class="tab-panel<?= $tab==='visa'?' active':'' ?>" id="tab-visa">
  <!-- بطاقات إجمالي الفيزا -->
  <div class="exp-cards">
    <div class="exp-grand">
      <div class="lbl">💳 إجمالي عمليات الفيزا<?= $vHasFilter?' (بالفلتر الحالي)':'' ?></div>
      <div class="val"><?= money($visaTotals['grand']) ?></div>
      <div class="sub"><?= intf($visaTotals['count']) ?> عملية</div>
    </div>
    <?php foreach ($visaTotals['by_source'] as $key => $s): if ($s['count']===0 && $vf['source']==='') continue; ?>
      <div class="exp-src">
        <div class="lbl"><span class="src-pill src-<?= $srcClass[$key]??'manual' ?>"><?= e($s['label']) ?></span></div>
        <div class="val"><?= money($s['value'], false) ?></div>
        <div class="cnt"><?= intf($s['count']) ?> عملية</div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- إضافة عملية فيزا -->
  <div class="card" style="padding:16px;margin-bottom:16px">
    <div class="section-head"><?= icon('plus', 17) ?> إضافة عملية مدفوعة بالفيزا</div>
    <?= formErrorSummary($visaState['errors']) ?>
    <form class="inline-form js-dirty-form" method="post" action="<?= url('debts/visa') ?>" style="flex-wrap:wrap">
      <?= csrfField() ?>
      <?= formStateFields($visaState['token'], $visaState['back']) ?>
      <input type="date" name="exp_date" value="<?= e($visaState['old']['exp_date'] ?? work_date()) ?>">
      <input list="dl_visa_cat" name="category" value="<?= e($visaState['old']['category'] ?? '') ?>" placeholder="الفئة (إيجار / رسوم…)" autocomplete="off">
      <input type="text" name="beneficiary" value="<?= e($visaState['old']['beneficiary'] ?? '') ?>" placeholder="الجهة / المستفيد">
      <div class="field" style="margin:0"><input type="number" step="0.01" name="amount" value="<?= e($visaState['old']['amount'] ?? '') ?>" placeholder="المبلغ" required style="max-width:130px"<?= fieldErrorAttrs('amount', $visaState['errors']) ?>><?= fieldError('amount', $visaState['errors']) ?></div>
      <input type="text" name="note" value="<?= e($visaState['old']['note'] ?? '') ?>" placeholder="بيان (اختياري)">
      <?= visa_card_select((int)($visaState['old']['visa_card_id'] ?? 0), 'visa_card_id', 'max-width:170px') ?>
      <button class="btn gold sm" type="submit" data-primary-submit>💳 إضافة</button>
    </form>
    <datalist id="dl_visa_cat"><?php foreach (($visaCats ?? []) as $opt): ?><option value="<?= e($opt) ?>"><?php endforeach; ?></datalist>
  </div>

  <!-- فلترة -->
  <div class="toolbar">
    <form method="get" action="<?= url('debts') ?>" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
      <input type="hidden" name="tab" value="visa">
      <input type="search" name="q" value="<?= e($vf['q']) ?>" placeholder="بحث (فئة / جهة / بيان)…">
      <select name="source">
        <option value="">كل المصادر</option>
        <?php foreach ($visaSources as $key => $label): ?>
          <option value="<?= e($key) ?>" <?= $vf['source']===$key?'selected':'' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <label style="font-size:.85rem;color:var(--grey)">من</label>
      <input type="date" name="from" value="<?= e($vf['from']) ?>">
      <label style="font-size:.85rem;color:var(--grey)">إلى</label>
      <input type="date" name="to" value="<?= e($vf['to']) ?>">
      <button class="btn sm" type="submit">تصفية</button>
      <?php if ($vHasFilter): ?><a class="btn ghost sm" href="<?= url('debts') ?>?tab=visa">إلغاء</a><?php endif; ?>
    </form>
    <div class="spacer"></div>
    <span class="count-badge"><?= intf($visaTotals['count']) ?> عملية</span>
  </div>

  <div class="card">
    <div class="table-wrap"><table class="data">
      <thead><tr>
        <th class="g-identity">م</th><th class="g-identity">التاريخ</th><th class="g-identity">المصدر</th>
        <th class="g-identity">الفئة / البيان</th><th class="g-identity">الجهة</th>
        <th class="g-identity">الكارت</th>
        <th class="g-expense">المبلغ</th><th class="g-identity">الأصل</th>
      </tr></thead>
      <tbody>
        <?php if (!$visaRows): ?>
          <tr><td colspan="8"><div class="empty"><div class="ic">💳</div>لا توجد عمليات فيزا مطابقة — أضف عملية أو فعّل «💳 فيزا» على أي مصروف/دفعة.</div></td></tr>
        <?php else: $i=0; foreach ($visaRows as $r): $i++;
          $src = $r['source'];
          $link = match($src){
            'permits' => url('permits/'.(int)$r['ref_id']),
            'bawalis' => (int)$r['ref_id'] ? url('bawalis/'.(int)$r['ref_id']) : url('movements'),
            'manual'  => url('expenses'),
            'debt'    => url('debts').'?tab=inspection',
            default   => null,
          }; ?>
          <tr>
            <td><?= intf($i) ?></td>
            <td><?= dfmt($r['d']) ?></td>
            <td><span class="src-pill src-<?= $srcClass[$src]??'manual' ?>"><?= e($visaSources[$src] ?? $src) ?></span></td>
            <td><?= e($r['category']) ?><?php if(!empty($r['note']) && $r['note']!==$r['category']): ?><br><span style="color:var(--grey);font-size:.78rem"><?= e($r['note']) ?></span><?php endif; ?></td>
            <td><?= e($r['party']) ?></td>
            <td><?php if(!empty($r['card'])): ?><span class="pill st-paid">💳 <?= e($r['card']) ?></span><?php else: ?><span style="color:var(--grey)">—</span><?php endif; ?></td>
            <td class="neg"><?= money($r['amount'], false) ?></td>
            <td><?php if($link): ?><a href="<?= e($link) ?>" title="فتح الأصل">↗️</a><?php else: ?><span style="color:var(--grey)">—</span><?php endif; ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
      <?php if ($visaRows): ?>
      <tfoot><tr><td colspan="6" style="text-align:left;font-weight:700">إجمالي المعروض</td><td class="neg" style="font-weight:800"><?= money($visaTotals['grand'], false) ?></td><td></td></tr></tfoot>
      <?php endif; ?>
    </table></div>
    <?php if (count($visaRows) >= 500): ?>
      <div style="padding:12px 4px;color:var(--grey)">عُرضت أول 500 عملية — ضيّق بالفلترة لعرض غيرها.</div>
    <?php endif; ?>
  </div>
</div>

<script>
(function(){
  var btns = document.querySelectorAll('.debts-tabs .tab-btn');
  var panels = document.querySelectorAll('.tab-panel');
  function activate(tab){
    btns.forEach(function(b){ var on=b.dataset.tab===tab;b.classList.toggle('active',on);b.setAttribute('aria-selected',on?'true':'false');b.tabIndex=on?0:-1; });
    panels.forEach(function(p){ var on=p.id==='tab-'+tab;p.classList.toggle('active',on);p.hidden=!on; });
    var u = new URL(location.href); u.searchParams.set('tab', tab); history.replaceState(null,'',u);
  }
  btns.forEach(function(b){ b.addEventListener('click', function(){ activate(b.dataset.tab); }); });
})();
</script>
