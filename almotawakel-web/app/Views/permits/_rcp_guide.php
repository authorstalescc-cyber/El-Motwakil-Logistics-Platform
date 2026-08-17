<?php
/**
 * مرشد الإيصالات (v32 + الموديل المدرَّب v101) — استرشادي بحت، لا يمنع أي شيء.
 *
 * متغيّرات: $p (صف الإذن، فيه chk_*) · $rcpGuide (ناتج `ReceiptModel::merged`)
 *           · $back ('view'|'manage') · $rcpFresh (حالة التدريب — اختياري)
 *
 * ⚠️ **كل رقم هنا مضغوط** (`data-drill`) وبيفتح الأذون اللي اتحسب منها بالاسم —
 *    قاعدة المالك الدائمة «ممنوع رقم أصمّ». والنسبة من غير دليلها **دعوى**
 *    لا معلومة: «٩٤٪» لوحدها بتتقري تخمينًا، و«٩٤٪ · ظهر في ١٧ من ١٨ إذن
 *    لنفس العميل» بتتقري حقيقة.
 */
$g     = $rcpGuide ?? ['items' => [], 'missing' => [], 'ready' => false];
$pid   = (int)($p['id'] ?? 0);
$type  = ($p['permit_type'] ?? 'sader') === 'warid' ? 'warid' : 'sader';
$back  = $back ?? 'manage';
$ateeq = $p['chk_ateeq'] ?? '';
$load  = $p['chk_load'] ?? '';
$items = $g['items'] ?? [];
if (!$items) { return; }

$missCount = count($g['missing'] ?? []);
$shortCount = count($g['short'] ?? []);
$why = fn(string $kind): string => url('permits/' . $pid . '/rcp-why?k=' . rawurlencode($kind));

?>
<div class="rcp-guide" id="rcp-guide">
  <div class="rg-head">
    <span class="rg-title"><?= icon('receipt', 14) ?> مرشد الإيصالات
      <span class="rg-note" title="بيفكّرك بالمتوقّع — ما بيمنعش أي إدخال ولا فوترة">استرشادي</span>
    </span>

    <?php if (!empty($rcpFresh['trained'])): ?>
      <?php $run = $rcpFresh['run']; ?>
      <a class="rg-model" href="<?= url('receipt-model') ?>"
         title="الموديل اتدرّب على <?= (int)$run['n_permits'] ?> إذن مقفول">
        <?= icon('cpu', 13) ?>
        <?php if ($run['prec_score'] !== null): ?>
          <b><?= number_format((float)$run['prec_score'] * 100, 0) ?>٪</b> دقة
        <?php else: ?>
          مدرَّب
        <?php endif; ?>
        <span class="rg-model-n"><?= intf((int)$run['n_permits']) ?> إذن</span>
        <?php if (!empty($rcpFresh['since'])): ?>
          <span class="rg-model-old"><?= intf((int)$rcpFresh['since']) ?> جديد</span>
        <?php endif; ?>
      </a>
    <?php endif; ?>

    <?php if ($missCount === 0 && $shortCount === 0): ?>
      <span class="rg-ok">كل المتوقّع موجود <?= icon('check', 14) ?></span>
    <?php else: ?>
      <span class="rg-badge">
        <?php if ($missCount): ?><?= intf($missCount) ?> ناقص<?php endif; ?>
        <?php if ($missCount && $shortCount): ?> · <?php endif; ?>
        <?php if ($shortCount): ?><?= intf($shortCount) ?> عدده ناقص<?php endif; ?>
      </span>
    <?php endif; ?>
  </div>

  <div class="rg-chips">
    <?php foreach ($items as $it):
        $label = (string)$it['label'];
        $pct   = $it['p'] !== null ? number_format((float)$it['p'] * 100, 0) . '٪' : '';
        /* العدد ما يتعرضش إلا لما الأساس يكون موثوقًا — «من ١٦» مبنية على
           معدّل كسري بتضلّل أكتر ما بتفيد. */
        $qExp  = !empty($it['qty_trusted']) ? (int)($it['qty_expected'] ?? 0) : 0;
        $qEnt  = (int)($it['qty_entered'] ?? 0);
        $tip   = trim(($it['hint'] ?? '') . ($it['scope_label'] ? ($it['hint'] ? ' · ' : '') . 'من ' . $it['scope_label'] : ''));
    ?>
      <?php if ($it['status'] === 'missing'): ?>
        <span class="miss-chip">
          <button type="button" class="rg-open" data-drill="<?= e($why($label)) ?>"
                  data-drill-title="ليه «<?= e($label) ?>»؟" title="<?= e($tip) ?>">
            بدون <?= e($label) ?>
            <?php if ($qExp > 1): ?><b class="rg-q"><?= intf($qExp) ?></b><?php endif; ?>
            <?php if ($pct !== ''): ?><span class="rg-p"><?= e($pct) ?></span><?php endif; ?>
          </button>
          <form method="post" action="<?= url('permits/'.$pid.'/rcp-dismiss') ?>" class="rg-inline">
            <?= csrfField() ?>
            <input type="hidden" name="label" value="<?= e($label) ?>">
            <input type="hidden" name="back" value="<?= e($back) ?>">
            <button type="submit" class="rg-x" title="مش محتاجه في الإذن ده"><?= icon('x', 14) ?></button>
          </form>
        </span>

      <?php elseif ($it['status'] === 'short'): ?>
        <span class="short-chip">
          <button type="button" class="rg-open" data-drill="<?= e($why($label)) ?>"
                  data-drill-title="ليه «<?= e($label) ?>» <?= intf($qExp) ?>؟" title="<?= e($tip) ?>">
            <?= e($label) ?> <b class="rg-q"><?= intf($qEnt) ?></b> من <b class="rg-q"><?= intf($qExp) ?></b>
          </button>
        </span>

      <?php elseif ($it['status'] === 'unusual'): ?>
        <span class="odd-chip">
          <button type="button" class="rg-open" data-drill="<?= e($why($label)) ?>"
                  data-drill-title="«<?= e($label) ?>» في الأذون المشابهة" title="<?= e($tip) ?>">
            <?= icon('alert', 13) ?> <?= e($label) ?>
            <span class="rg-p">مش معتاد</span>
          </button>
        </span>

      <?php elseif ($it['status'] === 'dismissed'): ?>
        <span class="rg-dismissed" title="أشّرت إنه مش محتاج">
          <?= e($label) ?> · متجاهَل
          <form method="post" action="<?= url('permits/'.$pid.'/rcp-dismiss') ?>" class="rg-inline">
            <?= csrfField() ?>
            <input type="hidden" name="label" value="<?= e($label) ?>">
            <input type="hidden" name="back" value="<?= e($back) ?>">
            <button type="submit" class="rg-x" title="رجّعه للقائمة"><?= icon('undo', 14) ?></button>
          </form>
        </span>

      <?php elseif ($it['status'] === 'hint'): ?>
        <span class="rg-opt">
          <button type="button" class="rg-open" data-drill="<?= e($why($label)) ?>"
                  data-drill-title="«<?= e($label) ?>» في الأذون المشابهة" title="<?= e($tip) ?>">
            <?= e($label) ?>
            <span class="rg-p"><?= e($it['optional'] ? 'اختياري' : ($pct !== '' ? $pct : 'وارد')) ?></span>
          </button>
        </span>

      <?php else: ?>
        <span class="have-chip">
          <button type="button" class="rg-open" data-drill="<?= e($why($label)) ?>"
                  data-drill-title="«<?= e($label) ?>» في الأذون المشابهة" title="<?= e($tip) ?>">
            <?= icon('check', 14) ?> <?= e($label) ?>
            <?php if ($qEnt > 1): ?><b class="rg-q"><?= intf($qEnt) ?></b><?php endif; ?>
          </button>
        </span>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>

  <details class="rg-chk">
    <summary><?= icon('settings', 14) ?> ظبط بيانات الإذن للمرشد</summary>
    <form method="post" action="<?= url('permits/'.$pid.'/checklist') ?>" class="rg-form">
      <?= csrfField() ?>
      <label class="rg-fld">
        <span>مكان التعتيق</span>
        <select name="chk_ateeq">
          <option value="">— غير محدّد —</option>
          <option value="port" <?= $ateeq==='port'?'selected':'' ?>>الميناء</option>
          <option value="yard" <?= $ateeq==='yard'?'selected':'' ?>>ساحة خارجية</option>
          <option value="sacia" <?= $ateeq==='sacia'?'selected':'' ?>>الصينية</option>
          <option value="none" <?= $ateeq==='none'?'selected':'' ?>>لا يوجد تعتيق</option>
        </select>
      </label>

      <?php if ($type === 'sader'): ?>
      <label class="rg-fld">
        <span>مكان الحمولة</span>
        <select name="chk_load">
          <option value="">— غير محدّد —</option>
          <option value="port" <?= $load==='port'?'selected':'' ?>>من الميناء (هيئة فوارغ)</option>
          <option value="outside" <?= $load==='outside'?'selected':'' ?>>من خارج الميناء (حمولة)</option>
        </select>
      </label>
      <?php endif; ?>

      <label class="rg-chkbox">
        <input type="checkbox" name="chk_nabatshia" value="1" <?= !empty($p['chk_nabatshia'])?'checked':'' ?>>
        <span>السائق عدّى 11 ومحمّلش <b>(نبطشية)</b></span>
      </label>

      <?php if ($type === 'warid'): ?>
      <label class="rg-chkbox">
        <input type="checkbox" name="chk_lamanea" value="1" <?= !empty($p['chk_lamanea'])?'checked':'' ?>>
        <span>«لا مانع» علينا احنا <b>(مش على المستخلص)</b></span>
      </label>
      <?php endif; ?>

      <button type="submit" class="btn primary sm">حفظ</button>
    </form>
  </details>
</div>
