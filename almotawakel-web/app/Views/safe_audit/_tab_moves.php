<?php
/**
 * تبويب «الكشف» — **الخزنتان في جدول واحد** (لبّ طلب المالك).
 *
 * ⚠️⚠️ **التحويل بين الخزنتين بيبان مرتين — وده صح.** التحويل من الشركة
 *    للمكتب حدث واحد بوجهين: خارج من الشركة وداخل للدرج. في **الصافي**
 *    بيلغي نفسه، لكن في **الإجمالي الخام** بيضخّم الداخل والخارج. عشان كده
 *    كروت الإجمالي بتعرض الرقم **بعد شيل التحويلات**، والصفوف بتتوسم
 *    «تحويل داخلي» — وإخفاء الفرق ده كان بيدّي «إجمالي داخل» أكبر من الحقيقة
 *    بلا أي خطأ ظاهر.
 *
 * ⚠️ **`drift`** = (آخر المدة − أول المدة) − صافي الحركة. المفروض صفر؛ لو طلع
 *    رقم يبقى فيه **إقفال مثبَّت اتقادم** بعد تعديل على يوم متقفل. الشاشة
 *    بتقوله صراحةً — ده بالظبط نوع الغلط اللي الجرد موجود عشانه.
 *
 * متغيّرات: $period $choices $st $day
 */
use App\Models\SafeInventory;

$cd       = static fn (string $w): string => url('safe-audit/drill/' . $w)
             . '?period=' . rawurlencode($period['key'])
             . '&from=' . rawurlencode($period['from']) . '&to=' . rawurlencode($period['to']);
$pUrl     = static fn (string $k): string => url('safe-audit') . '?tab=moves&period=' . $k;
$isCustom = $period['key'] === 'custom';
$total    = $st['total'];
$cur      = App\Core\App::setting('currency', 'ج.م');
?>

<?php /* ═══ شريط الفترة — نفس مكوّن خزنة الشركة بالحرف ═══ */ ?>
<form class="cs-period" method="get" action="<?= url('safe-audit') ?>">
  <input type="hidden" name="tab" value="moves">
  <div class="cs-period-tabs">
    <?php foreach ($choices as $k => $lbl): ?>
      <?php if ($k === 'custom'): ?>
        <button type="button" class="cs-period-btn<?= $isCustom ? ' on' : '' ?>"
                data-cs-period-toggle><?= icon('calendar', 13) ?> <?= e($lbl) ?></button>
      <?php else: ?>
        <a class="cs-period-btn<?= $period['key'] === $k ? ' on' : '' ?>"
           href="<?= e($pUrl($k)) ?>"><?= e($lbl) ?></a>
      <?php endif; ?>
    <?php endforeach; ?>
    <span class="cs-period-now"><?= e($period['label']) ?></span>
  </div>
  <div class="cs-period-custom" id="csPeriodCustom" <?= $isCustom ? '' : 'hidden' ?>>
    <input type="hidden" name="period" value="custom">
    <label>من <input type="date" name="from" value="<?= e($period['from']) ?>" required></label>
    <label>إلى <input type="date" name="to" value="<?= e($period['to']) ?>" required></label>
    <button class="btn" type="submit"><?= icon('filter', 14) ?> اعرض</button>
  </div>
</form>

<?php /* ═══ ① الفترة في ٤ أرقام — للخزنتين مع بعض ═══ */ ?>
<?php partial('stat_grid', ['items' => 4, 'class' => 'mb', 'body' => function () use ($total, $cd, $cur) { ?>
  <?php partial('stat', [
    'label' => 'أول المدة', 'value' => money0($total['opening'], false), 'unit' => $cur,
    'icon'  => 'calendar', 'tone' => 'navy',
    'drill' => $cd('period_net'), 'title' => 'حركة الفترة',
  ]); ?>
  <?php partial('stat', [
    'label' => 'داخل من برّه', 'value' => money0($total['in'], false), 'unit' => $cur,
    'icon'  => 'wallet', 'tone' => 'green',
    'drill' => $cd('period_in'), 'title' => 'الداخل للخزنتين',
  ]); ?>
  <?php partial('stat', [
    'label' => 'خارج لبرّه', 'value' => money0($total['out'], false), 'unit' => $cur,
    'icon'  => 'trending-down', 'tone' => 'red',
    'drill' => $cd('period_out'), 'title' => 'الخارج من الخزنتين',
  ]); ?>
  <?php partial('stat', [
    'label' => 'آخر المدة', 'value' => money0($total['closing'], false), 'unit' => $cur,
    'icon'  => 'coins', 'tone' => 'gold', 'hero' => true,
    'drill' => $cd('period_net'), 'title' => 'حركة الفترة',
  ]); ?>
<?php }]); ?>

<?php /* ⚠️ تحذيرات وظيفية — بتظهر بس لما يلزم، وكل واحدة ≤٦ كلمات. */ ?>
<?php if (abs((float) $total['drift']) >= 0.01): ?>
  <div class="cs-alert bad">
    <?= icon('alert', 17) ?> فرق ترحيل <?= money(abs((float) $total['drift']), false) ?> — راجع التقفيل
    <span class="spacer"></span>
    <?php /* ⚠️ `sm` مش `small` — `.btn.small` **مالهاش تعريف في `app.css`**
             فالزرار كان هيقع على المقاس الافتراضي بالسكوت (درس
             `.table-mobile-scroll`). `verify_v108` §⑧ بيمسكها. */ ?>
    <a class="btn sm" href="<?= url('closing') ?>"><?= icon('calculator', 14) ?> التقفيل</a>
  </div>
<?php endif; ?>
<?php if ($st['rows_capped']): ?>
  <div class="cs-alert">
    <?= icon('info', 17) ?> الصفوف لأقصى <?= intf(SafeInventory::MAX_ROW_DAYS) ?> يوم — الأرقام فوق كاملة
  </div>
<?php endif; ?>

<?php /* ═══ ② كل خزنة لوحدها — أول المدة · داخل · خارج · آخر المدة ═══ */ ?>
<div class="kpi-grid two mb">
  <?php foreach ($st['safes'] as $s): ?>
    <div class="card oc-pad">
      <div class="section-head oc-sec">
        <?= icon((string) $s['icon'], 17) ?> <?= e((string) $s['label']) ?>
        <span class="spacer"></span>
        <b class="<?= $s['closing'] < -0.005 ? 'neg' : '' ?>"><?= money($s['closing'], false) ?></b>
      </div>
      <div class="chip-list">
        <span class="chip">أول المدة <b><?= money($s['opening'], false) ?></b></span>
        <span class="chip">داخل <b class="pos"><?= money($s['in'], false) ?></b></span>
        <span class="chip">خارج <b class="neg"><?= money($s['out'], false) ?></b></span>
        <?php if (abs((float) $s['drift']) >= 0.01): ?>
          <span class="chip">فرق ترحيل <b class="neg"><?= money((float) $s['drift'], false) ?></b></span>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php /* ═══ ③ الكشف الواحد — الحركتين مدموجتين زمنيًّا ═══
         ⚠️ **الرصيد الجاري بيبدأ من `opening` المجمّع** — لو بدأ من صفر كان
            هيكدب على كل صف. */ ?>
<?php
$running = (float) $total['opening'];
$rowsHtml = function () use ($st, &$running) {
    foreach ($st['rows'] as $r) {
        $running = round($running + (float) $r['signed'], 2);
        $in  = $r['signed'] > 0 ? (float) $r['signed'] : 0.0;
        $out = $r['signed'] < 0 ? -(float) $r['signed'] : 0.0;
        $desc = array_filter([$r['party'] ?? null, $r['extra'] ?? null, $r['note'] ?? null]);
        ?>
        <tr>
          <td class="num"><?= e(dfmt((string) $r['date'])) ?></td>
          <td>
            <span class="pill <?= $r['safe'] === 'office' ? 'pill-sader' : 'pill-warid' ?>">
              <?= e(App\Models\SafeInventory::label((string) $r['safe'])) ?>
            </span>
          </td>
          <td>
            <?= icon((string) $r['icon'], 14) ?> <?= e((string) $r['title']) ?>
            <?php if (!empty($r['transfer'])): ?>
              <span class="pill off">تحويل داخلي</span>
            <?php endif; ?>
            <?php if ($desc): ?><small class="hint"><?= e(implode(' — ', $desc)) ?></small><?php endif; ?>
          </td>
          <td class="num pos"><?= $in > 0.005 ? money($in, false) : '' ?></td>
          <td class="num neg"><?= $out > 0.005 ? money($out, false) : '' ?></td>
          <td class="num"><?= money($running, false) ?></td>
        </tr>
        <?php
    }
};
?>
<?php partial('table', [
  'cols'  => [
    'التاريخ', 'الخزنة', 'البيان',
    ['label' => 'داخل', 'class' => 'num'],
    ['label' => 'خارج', 'class' => 'num'],
    ['label' => 'الرصيد', 'class' => 'num'],
  ],
  'count' => count($st['rows']),
  'rows'  => $rowsHtml,
  'empty' => ['icon' => 'layers', 'title' => 'مفيش حركة في الفترة دي'],
]); ?>

<?php partial('count_note', [
  'shown' => count($st['rows']), 'total' => (int) $st['rows_total'], 'unit' => 'حركة',
]); ?>

<?php if ($st['transfer_count'] > 0): ?>
  <div class="cs-alert">
    <?= icon('shuffle', 17) ?> تحويلات داخلية <?= money((float) $st['transfers'], false) ?>
    — <?= intf((int) $st['transfer_count']) ?> صف
  </div>
<?php endif; ?>
