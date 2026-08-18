<?php
/**
 * جسم شاشة الإذن — **وضع العرض** · إعادة بناء كاملة (v103، 2026-08-17).
 *
 * طلب المالك: «محتاج نعيد بناء صفحة الإذن المنفرد بتصميم مختلف وشكل أفضل
 * وتجربة مستخدم أفضل بكثير — مش مشكلة لو كسرت التصميم الحالي».
 *
 * ═══ اللي كان غلط في النسخة القديمة (وده اللي البناء ده بيحلّه) ═══
 *   ① **جداول جوّه طيّات جوّه طيّات**: كل حركة فيها `<details>` لكل مرحلة،
 *      وجوّه كل واحدة `<table>` بـ٧ أو ١١ عمودًا. على أي شاشة أقل من ١٤٠٠px
 *      الجدول بيتمرّر أفقيًّا، وعلى الموبايل بيتحوّل كروت — يعني **تلات أشكال
 *      لنفس المعلومة** حسب عرض الشاشة.
 *   ② **صفحة طولها كيلومتر بلا خريطة**: هيرو ← رحلة ← مستندات ← مرشد ←
 *      تفاصيل ← رسوم ← حركات، وكل ده تمرير رأسي واحد بلا أي وسيلة قفز.
 *   ③ **البند ما كانش بيقول مين دخّله** — والمالك بيسأل السؤال ده كل يوم.
 *
 * ═══ البناء الجديد ═══
 *   • **شريط ملاصق** (`pv3-nav`) فيه القفز للأقسام + طيّ الكل + الإجراء الأهم.
 *   • **عمودان**: الحركات (المحتوى الحيّ) | جنبها الثابت (رسوم الإذن + تفاصيل).
 *   • **صفوف بدل الجداول**: سطر واحد للبند وسطر واحد للبوليصة — نفس الشكل
 *     بالظبط من ٣٢٠px لـ٢٥٦٠px، بلا تمرير أفقي ولا مُصيِّر تاني للموبايل.
 *   • **شارة المندوب جنب كل بند** (`partials/rep_badge`) — نفس الشارة اللي في
 *     وضع التعديل وفي نافذة النظرة العامة.
 *
 * ⚠️ الغلاف مشترك (`permits/show.php`): الشريط والهيرو والرحلة والمستندات
 *    والمرشد بيترسموا **مرة واحدة** هناك — ممنوع أي واحد منهم يتضمّ هنا.
 * ⚠️ **عرض بحت**: كل تعديل بيروح لوضع التعديل بمرساة (`#stage-...`).
 * ⚠️ صفر `style=` وصفر `<style>` — كل الكلاسات `pv3-*` في `app.css`.
 */
use App\Models\Permit;
use App\Models\Bill;

$pid  = (int) $p['id'];
$type = $p['permit_type'];
$isClosed   = ($p['status'] ?? 'open') === 'closed';
$pStages    = Permit::scopedStages('permit');
$mStages    = Permit::scopedStages('movement');
$primaryDef = Permit::primaryFeesDefault($pid);

/** رابط التعديل على مرحلة بعينها — نقطة بناء واحدة. */
$manageUrl = static fn (string $key, int $mid = 0): string
    => url('permits/' . $pid . '/manage') . '#stage-' . $key . ($mid ? '-' . $mid : '');

/* ⚠️ **الطيّ الافتراضي بالعدد** (نفس قاعدة نافذة النظرة العامة): إذن بحركة أو
   اتنين المالك عايز يشوفهم مفتوحين؛ إذن بـ٨ حركات القايمة المفتوحة بتبقى
   أربع شاشات تمرير. */
$mvOpenDefault = count($movements) <= 3;

/* اسم المرحلة اللي البند تابع لها — بيتعرض كعنوان مجموعة صغير فوق بنودها. */
$stageLabel = static fn (string $key): string => Permit::STAGES[$key]['label'] ?? $key;

/**
 * سطر بند واحد (إيصال · رسوم · مصروف إضافي · محصَّل) — **الشكل الوحيد للبند**
 * في الشاشة دي، سواء كان بند إذن أو بند حركة.
 */
$itemRow = static function (array $en, string $key, array $st) use ($stageLabel): void {
    $dir = $key === 'custom' ? (($en['direction'] ?? '') ?: 'expense') : ($st['dir'] ?? 'expense');
    $isRevenue = $dir === Permit::DIR_REVENUE;
    $qty    = max(1, (int) ($en['qty'] ?? 1));
    $actual = (isset($en['actual_amount']) && $en['actual_amount'] !== null && $en['actual_amount'] !== '')
        ? (float) $en['actual_amount'] : null;
    ?>
    <div class="pv3-it <?= $isRevenue ? 'rev' : 'ex' ?>">
      <div class="pv3-it-b">
        <b><?= e(trim((string) ($en['note'] ?? '')) !== '' ? $en['note'] : $stageLabel($key)) ?></b>
        <div class="pv3-it-m">
          <span><?= icon('calendar', 11) ?> <span class="num"><?= dfmt($en['entry_date']) ?></span></span>
          <?php if ($qty > 1): ?>
            <span title="الكمية × سعر الوحدة"><?= icon('package', 11) ?>
              <span class="num"><?= intf($qty) ?> × <?= money(round((float) $en['amount'] / $qty, 2), false) ?></span></span>
          <?php endif; ?>
          <?php if (trim((string) ($en['ref_no'] ?? '')) !== ''): ?>
            <span><?= icon('tag', 11) ?> <span class="num"><?= e($en['ref_no']) ?></span></span>
          <?php endif; ?>
          <?php /* ⚠️ **شارة المندوب** — طلب المالك المباشر. مكوّن واحد مشترك مع
                   وضع التعديل ونافذة النظرة العامة. */ ?>
          <?php partial('rep_badge', ['name' => $en['rep_name'] ?? '']); ?>
          <?php if (!empty($en['is_visa'])): ?><span class="pv3-tag"><?= icon('credit-card', 11) ?> فيزا</span><?php endif; ?>
          <?php if (!empty($en['unpaid'])): ?><span class="pv3-tag" title="اتحاسب من برّه الخزنة والعهدة">غير مدفوع</span><?php endif; ?>
          <?php if (($en['status'] ?? 'done') === 'pending'): ?><span class="pv3-tag warn">ناقصة</span><?php endif; ?>
          <?php if (!empty($en['attachment'])): ?>
            <a class="pv3-tag link" href="<?= url('attachments/stage/' . (int) $en['id']) ?>" target="_blank"><?= icon('file-text', 11) ?> مرفق</a>
          <?php endif; ?>
        </div>
      </div>
      <div class="pv3-it-n">
        <b class="num"><?= money((float) $en['amount'], false) ?></b>
        <?php if ($actual !== null): ?>
          <span title="السعر الفعلي للعميل">للعميل <span class="num"><?= money($actual, false) ?></span></span>
        <?php else: ?>
          <span class="mut">بلا سعر للعميل</span>
        <?php endif; ?>
      </div>
    </div>
    <?php
};

/**
 * مجموعة بنود مرحلة واحدة: عنوان صغير + إجمالي + بنودها.
 * بترجع '' لو المرحلة فاضية — الفراغ ما بياخدش مساحة في الشاشة الجديدة.
 */
$stageGroup = static function (string $key, array $st, array $entries, int $mid, bool $isPermitLevel = false)
        use ($itemRow, $manageUrl, $primaryDef): string {
    $noneRow = null; $real = [];
    foreach ($entries as $en) {
        if (($en['status'] ?? '') === 'none') { $noneRow = $en; } else { $real[] = $en; }
    }
    $isNone = ($noneRow !== null && !$real);
    $autoPrimary = ($key === 'primary_fees' && !$real && !$isNone
        && ($primaryDef['containers'] ?? 0) > 0 && !empty($primaryDef['rates_set']));
    if (!$real && !$isNone && !$autoPrimary) { return ''; }

    $total = array_sum(array_map(static fn ($x) => (float) $x['amount'], $real));
    if ($autoPrimary) { $total = (float) $primaryDef['total']; }
    ob_start(); ?>
    <div class="pv3-grp" id="stage-<?= e($key) ?><?= $mid ? '-' . $mid : '' ?>">
      <div class="pv3-grp-h">
        <span class="pv3-grp-ic"><?= icon($st['icon'] ?? 'receipt', 13) ?></span>
        <b><?= e($st['label']) ?></b>
        <?php if ($real): ?><span class="pv3-grp-c num"><?= intf(count($real)) ?></span><?php endif; ?>
        <span class="pv3-sp"></span>
        <?php if ($total > 0): ?><span class="pv3-grp-t num"><?= money($total, false) ?></span><?php endif; ?>
        <a class="pv3-grp-a" href="<?= $manageUrl($key, $mid) ?>" title="تعديل / إضافة بند"><?= icon('edit', 13) ?></a>
      </div>
      <?php if ($isNone): ?>
        <div class="pv3-none"><?= icon('x', 12) ?> معلَّمة «لا يوجد» — محسوبة مكتملة</div>
      <?php elseif ($autoPrimary): ?>
        <div class="pv3-auto">
          <div class="pv3-auto-r"><span>الكشف</span>
            <span class="num"><?= intf($primaryDef['containers']) ?> × <?= money($primaryDef['inspection_rate'], false) ?></span>
            <b class="num"><?= money($primaryDef['inspection'], false) ?></b></div>
          <div class="pv3-auto-r"><span>الضمان</span>
            <span class="num"><?= intf($primaryDef['containers']) ?> × <?= money($primaryDef['guarantee_rate'], false) ?></span>
            <b class="num"><?= money($primaryDef['guarantee'], false) ?></b></div>
          <div class="pv3-auto-r tot"><span>محسوبة تلقائيًا</span><span></span>
            <b class="num"><?= money($primaryDef['total'], false) ?></b></div>
        </div>
      <?php else: ?>
        <div class="pv3-items"><?php foreach ($real as $en) { $itemRow($en, $key, $st); } ?></div>
      <?php endif; ?>
    </div>
    <?php return (string) ob_get_clean();
};

/* أرقام الترويسة الثابتة للشريط الملاصق */
$billsCount = 0; $itemsCount = 0;
foreach ($movements as $mv) {
    $billsCount += count($mv['bills']);
    foreach ($mv['stages'] as $es) {
        foreach ($es as $e) { if (($e['status'] ?? '') !== 'none') { $itemsCount++; } }
    }
}
foreach ($permitStages as $es) {
    foreach ($es as $e) { if (($e['status'] ?? '') !== 'none') { $itemsCount++; } }
}
?>
<div class="pv3" data-fold-scope>

  <?php /* ① سطر الأدوات — **مش خريطة تانية**: الهوية والأرقام في الريل جنبك،
           فاللي فاضل هنا عدّادات الشغل والإجراء الأهم وطيّ الكل. */ ?>
  <div class="pf-tools">
    <span class="pf-count"><?= icon('shuffle', 14) ?> الحركات <b><?= intf(count($movements)) ?></b></span>
    <span class="pf-count"><?= icon('truck', 14) ?> البوالص <b><?= intf($billsCount) ?></b></span>
    <span class="pf-count"><?= icon('receipt', 14) ?> البنود <b><?= intf($itemsCount) ?></b></span>
    <span class="pf-sp"></span>
    <?php if ($movements): ?>
      <button type="button" class="pf-tool" data-fold-all=".pf-mv">
        <?= icon('layers', 14) ?><span><?= $mvOpenDefault ? 'اطوي الكل' : 'افتح الكل' ?></span></button>
    <?php endif; ?>
    <?php if (!$isClosed): ?>
      <a class="btn gold sm" href="<?= $manageUrl('receipts', (int) ($movements[0]['id'] ?? 0)) ?>"><?= icon('plus', 14) ?> إضافة بند</a>
    <?php endif; ?>
  </div>

  <?php /* ② الحركات — كارت بعرض الصفحة لكل حركة، ورقمها في ختم على ترويستها */ ?>
  <div class="pf-list" id="pv3-movements">
      <?php if (!$movements): ?>
        <?php partial('empty', ['emoji' => '🚚', 'title' => 'لا حركات على الإذن ده']); ?>
      <?php else: ?>
        <?php foreach ($movements as $mvIdx => $mv):
          $mid = (int) $mv['id'];
          $mvBills = $mv['bills']; $mvStagesG = $mv['stages'];
          $mvExp = 0.0; $mvCust = 0.0; $mvCharged = 0.0; $mvCt = 0; $mvItems = 0; $mvReps = [];
          foreach ($mvStagesG as $kk => $es) {
            foreach ($es as $e) {
              if (($e['status'] ?? '') === 'none') { continue; }
              $mvItems++;
              if ($kk === 'receipts' || $kk === 'primary_fees'
                  || ($kk === 'custom' && (($e['direction'] ?? '') === 'expense'))) {
                  $mvExp += (float) $e['amount'];
              }
              if (isset($e['actual_amount']) && $e['actual_amount'] !== null && $e['actual_amount'] !== '') {
                  $mvCharged += (float) $e['actual_amount'];
              }
              if (trim((string) ($e['rep_name'] ?? '')) !== '') { $mvReps[$e['rep_name']] = true; }
            }
          }
          foreach ($mvBills as $b) { $mvCust += (float) $b['custody']; $mvCt += (int) ($b['container_count'] ?? 0); }
          $mvRem = array_sum(array_map(static fn ($b) => (float) $b['remaining'], $mvBills));
        ?>
        <details class="pf-mv" id="movement-<?= $mid ?>"<?= $mvOpenDefault || $mvIdx === 0 ? ' open' : '' ?>>
          <summary class="pf-mv-h">
            <?php /* رقم الحركة في ختم على ترويستها — نفس `seq` اللي بيتكتب على
                     الورق وبيتقال في التليفون، مش ترقيم زخرفي. */ ?>
            <span class="pf-stamp num"><?= intf($mv['seq']) ?></span>
            <span class="pf-mv-t">
              <b>حركة <em><?= intf($mv['seq']) ?></em></b>
              <span class="pf-mv-m">
                <span><?= icon('truck', 11) ?> <span class="num"><?= intf(count($mvBills)) ?></span> بوليصة</span>
                <?php if ($mvCt > 0): ?><span><?= icon('containers', 11) ?> <span class="num"><?= intf($mvCt) ?></span> حاوية</span><?php endif; ?>
                <span><?= icon('receipt', 11) ?> <span class="num"><?= intf($mvItems) ?></span> بند</span>
                <?php foreach (array_keys($mvReps) as $rn): ?>
                  <?php partial('rep_badge', ['name' => $rn, 'short' => true]); ?>
                <?php endforeach; ?>
                <?php if (trim((string) ($mv['note'] ?? '')) !== ''): ?>
                  <span><?= icon('edit', 11) ?> <?= e($mv['note']) ?></span>
                <?php endif; ?>
              </span>
            </span>
            <span class="pf-mv-n">
              <span class="pf-n"><small>عهدة</small><b class="pos"><?= money($mvCust, false) ?></b></span>
              <span class="pf-n"><small>اتصرف</small><b class="neg"><?= money($mvExp, false) ?></b></span>
              <span class="pf-n"><small>للعميل</small><b><?= $mvCharged > 0 ? money($mvCharged, false) : '—' ?></b></span>
            </span>
            <span class="pf-chev"><?= icon('chevron', 15) ?></span>
          </summary>

          <div class="pf-mv-b">

            <?php /* ══ البوالص = **لوحة تشغيل**: كل بوليصة كارت عربية ══
                     ⚠️ التقسيمة القديمة (عمودان متجاوران: بوالص | بنود) كانت
                        بتسيب **فراغ كبير** تحت الأقصر منهم (بوليصتان مقابل ٦
                        بنود)، وبتنصّ عرض السطر لنُصّين رغم إن الصفحة عندها
                        ١٤٠٠px. دلوقتي البوالص شبكة كروت بعرض الحركة، والبنود
                        تحتها بعرض كامل — مفيش فراغ ومفيش سطر مزنوق.
                     ⚠️ ورقم العربية هو **بطل الكارت** (زي لوحة السيارة على
                        لوحة التشغيل) لأنه اللي بيتقال في التليفون والورق. */ ?>
            <section class="pf-sec">
              <div class="pf-sec-h">
                <?= icon('truck', 15) ?> <b>البوالص</b>
                <span class="pf-badge num"><?= intf(count($mvBills)) ?></span>
                <?php if ($mvCt > 0): ?><span class="pf-sec-m"><?= icon('containers', 12) ?> <span class="num"><?= intf($mvCt) ?></span> حاوية</span><?php endif; ?>
                <span class="pf-sp"></span>
                <?php if ($mvRem != 0): ?><span class="pv3-tag warn">متبقّي <span class="num"><?= money($mvRem, false) ?></span></span><?php endif; ?>
                <span class="pf-sec-t">عهدة <b class="num"><?= money($mvCust, false) ?></b></span>
                <a class="pv3-grp-a" href="<?= $manageUrl('bills', $mid) ?>" title="تعديل البوالص"><?= icon('edit', 13) ?></a>
              </div>
              <?php if (!$mvBills): ?>
                <div class="pv3-none"><?= icon('x', 12) ?> لا بوالص في الحركة دي</div>
              <?php else: ?>
                <div class="pf-cars">
                  <?php foreach ($mvBills as $bl): ?>
                    <?php
                      $rem  = (float) $bl['remaining'];
                      $paid = $rem == 0;
                    ?>
                    <a class="pf-car<?= $paid ? ' paid' : ' due' ?>" href="<?= url('bawalis/' . (int) $bl['id']) ?>">
                      <div class="pf-car-top">
                        <span class="pf-plate num"><?= e($bl['car_no'] ?: '—') ?></span>
                        <span class="pill st-<?= e($bl['pay_status']) ?>"><?= e(Bill::STATUS_LABELS[$bl['pay_status']] ?? '') ?></span>
                      </div>
                      <div class="pf-car-who">
                        <?= icon('user', 12) ?> <b><?= e($bl['driver'] ?: 'بلا سائق') ?></b>
                      </div>
                      <div class="pf-car-m">
                        <span><?= icon('truck', 11) ?> بوليصة <span class="num"><?= e($bl['bill_number'] ?: '#' . (int) $bl['id']) ?></span></span>
                        <span><?= icon('calendar', 11) ?> <span class="num"><?= dfmt($bl['bill_date']) ?></span></span>
                        <?php if (($bl['container_no'] ?? '') !== ''): ?>
                          <span><?= icon('containers', 11) ?> <span class="num"><?= e($bl['container_no']) ?></span></span>
                        <?php endif; ?>
                        <?php if (($bl['container_type'] ?? '') !== ''): ?>
                          <span class="pv3-tag ct"><span class="num"><?= intf($bl['container_count'] ?? 0) ?></span>×<?= e($bl['container_type']) ?></span>
                        <?php endif; ?>
                      </div>
                      <div class="pf-car-f">
                        <span class="pf-car-n"><small>عهدة</small><b class="num"><?= money((float) $bl['custody'], false) ?></b></span>
                        <?php if ($rem != 0): ?>
                          <span class="pf-car-n due"><small>متبقّي</small><b class="num"><?= money($rem, false) ?></b></span>
                        <?php elseif ((float) $bl['debt'] != 0): ?>
                          <span class="pf-car-n"><small>دين</small><b class="num"><?= money((float) $bl['debt'], false) ?></b></span>
                        <?php else: ?>
                          <span class="pf-car-n ok"><small>الحساب</small><b>مسدَّدة</b></span>
                        <?php endif; ?>
                      </div>
                    </a>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </section>

            <?php /* ══ البنود بعرض الحركة كلها، مجمّعة بمرحلتها ══ */ ?>
            <section class="pf-sec">
              <div class="pf-sec-h">
                <?= icon('receipt', 15) ?> <b>البنود</b>
                <span class="pf-badge num"><?= intf($mvItems) ?></span>
                <span class="pf-sp"></span>
                <span class="pf-sec-t">اتصرف <b class="num"><?= money($mvExp, false) ?></b></span>
                <?php if ($mvCharged > 0): ?>
                  <span class="pf-sec-t gold">للعميل <b class="num"><?= money($mvCharged, false) ?></b></span>
                <?php endif; ?>
                <a class="pv3-grp-a" href="<?= $manageUrl('receipts', $mid) ?>" title="إضافة بند"><?= icon('plus', 13) ?></a>
              </div>
              <?php
                $groups = '';
                foreach ($mStages as $key => $st) {
                    if ($key === 'bills' || $key === 'generators') { continue; }
                    $groups .= $stageGroup($key, $st, $mvStagesG[$key] ?? [], $mid);
                }
              ?>
              <?php if ($groups === ''): ?>
                <div class="pv3-none"><?= icon('x', 12) ?> لا بنود في الحركة دي</div>
              <?php else: ?>
                <?= $groups ?>
              <?php endif; ?>
            </section>
          </div>
        </details>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php /* المولدات — بتظهر بس لو ليها سجل مطابق لرقم الإذن */ ?>
      <?php if (!empty($generators)): ?>
        <section class="pv3-panel pv3-card" id="pv3-generators">
          <div class="pv3-panel-h">
            <?= icon('settings', 14) ?> <b>المولدات</b>
            <span class="pv3-grp-c num"><?= intf(count($generators)) ?></span>
          </div>
          <div class="pv3-items">
            <?php foreach ($generators as $g): ?>
              <div class="pv3-it">
                <div class="pv3-it-b">
                  <b><?= e($g['company'] ?: 'مولد') ?></b>
                  <div class="pv3-it-m">
                    <span><?= icon('calendar', 11) ?> <span class="num"><?= dfmt($g['gen_date']) ?></span></span>
                    <span><?= icon('package', 11) ?> <span class="num"><?= intf($g['gen_count']) ?></span></span>
                  </div>
                </div>
                <div class="pv3-it-n">
                  <b class="num"><?= money((float) $g['freight'], false) ?></b>
                  <?php if ((float) $g['remaining'] != 0): ?>
                    <span class="neg">متبقّي <span class="num"><?= money((float) $g['remaining'], false) ?></span></span>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>
    </div>

        <?php /* رسوم الإذن — بنود على مستوى الإذن نفسه (مش على حركة).
               مكانها آخر الكعب لأنها **مش خطوة في التسلسل**، هي مصاريف الملف. */ ?>
      <?php
        $permitMoney = array_filter($pStages,
            static fn ($k) => $k !== 'data' && $k !== 'generators', ARRAY_FILTER_USE_KEY);
        $feeGroups = '';
        foreach ($permitMoney as $key => $st) {
            $feeGroups .= $stageGroup($key, $st, $permitStages[$key] ?? [], 0, true);
        }
      ?>
      <?php if ($feeGroups !== ''): ?>
        <section class="pv3-panel pv3-card" id="pv3-fees">
          <div class="pv3-panel-h">
            <?= icon('coins', 14) ?> <b>رسوم الإذن</b>
            <span class="pv3-sp"></span>
            <a class="pv3-grp-a" href="<?= $manageUrl('primary_fees') ?>" title="تعديل الرسوم"><?= icon('edit', 13) ?></a>
          </div>
          <?= $feeGroups ?>
        </section>
      <?php endif; ?>

      <?php /* ⚠️ **تفاصيل ما بتتكرّرش مع الهيرو**: الهيرو فيه الشركة والوكيل
               والتوكيل والجهة والميناء والتاريخ والحاويات — فولا واحد فيهم هنا. */ ?>
      <?php
        $facts = array_values(array_filter([
          ['search', 'سعر الكشف لكل حاوية', $p['inspection_rate'] !== null ? money($p['inspection_rate'], false) : ''],
          ['shield', 'سعر الضمان لكل حاوية', $p['guarantee_rate'] !== null ? money($p['guarantee_rate'], false) : ''],
          ['ship',   'شركة الشحن', trim((string) ($p['shipping_co'] ?? ''))],
          ['package','تصنيف الحاويات', trim((string) ($p['container_type'] ?? ''))],
          ['edit',   'ملاحظة', trim((string) ($p['note'] ?? ''))],
        ], static fn ($r) => $r[2] !== ''));
      ?>
      <?php if ($facts): ?>
        <section class="pv3-panel pv3-card" id="pv3-facts">
          <div class="pv3-panel-h"><?= icon('file-text', 14) ?> <b>تفاصيل الإذن</b></div>
          <div class="pf-facts-grid">
            <?php foreach ($facts as [$ic, $lbl, $val]): ?>
              <div class="pf-fact-cell">
                <span><?= icon($ic, 13) ?> <?= e($lbl) ?></span>
                <b><?= e($val) ?></b>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>
  </div>
</div>

<script>
/* فتح القسم المقصود لما الرابط جاي بمرساة (`#stage-receipts-12`) — نفس سلوك
   النسخة القديمة بالحرف، لأن كل أزرار «تعديل» في الشاشة بتعتمد عليه. */
(function(){
  if (!location.hash) return;
  var t = document.querySelector(location.hash);
  if (!t) return;
  var d = t.closest ? t.closest('details') : null;
  if (d) d.open = true;
  if (t.tagName === 'DETAILS') t.open = true;
  setTimeout(function(){ t.scrollIntoView({block:'center'}); }, 60);
})();
</script>
