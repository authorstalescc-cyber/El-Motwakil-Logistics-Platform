<?php
/**
 * مرشد الإيصالات الذكي (v32) — استرشادي بحت، لا يمنع أي شيء.
 * متغيّرات: $p (صف الإذن، فيه chk_*) · $rcpGuide (items/present/missing) · $back ('view'|'manage')
 */
$g    = $rcpGuide ?? ['items' => [], 'present' => [], 'missing' => []];
$pid  = (int)($p['id'] ?? 0);
$type = ($p['permit_type'] ?? 'sader') === 'warid' ? 'warid' : 'sader';
$back = $back ?? 'manage';
$ateeq = $p['chk_ateeq'] ?? '';
$load  = $p['chk_load'] ?? '';
$missCount = count($g['missing']);
if (!$g['items']) { return; }
?>
<div class="rcp-guide" id="rcp-guide">
  <div class="rg-head">
    <span class="rg-title">🧾 مرشد الإيصالات
      <span class="rg-note">استرشادي — يفكّرك بس، مايلزمكش بأي حاجة</span>
    </span>
    <?php if ($missCount === 0): ?>
      <span class="rg-ok">كل المتوقّع موجود ✓</span>
    <?php else: ?>
      <span class="rg-badge"><?= (int)$missCount ?> متوقّع وناقص</span>
    <?php endif; ?>
  </div>

  <div class="rg-chips">
    <?php foreach ($g['items'] as $it): ?>
      <?php if ($it['present']): ?>
        <span class="have-chip" title="<?= e($it['hint']) ?>">✓ <?= e($it['label']) ?></span>
      <?php elseif ($it['dismissed']): ?>
        <span class="rg-dismissed" title="أشّرت إنه مش محتاج">
          <?= e($it['label']) ?> · متجاهَل
          <form method="post" action="<?= url('permits/'.$pid.'/rcp-dismiss') ?>" class="rg-inline">
            <?= csrfField() ?>
            <input type="hidden" name="label" value="<?= e($it['label']) ?>">
            <input type="hidden" name="back" value="<?= e($back) ?>">
            <button type="submit" class="rg-x" title="رجّعه للقائمة">↩</button>
          </form>
        </span>
      <?php elseif (!empty($it['optional'])): ?>
        <span class="rg-opt" title="<?= e($it['hint']) ?>"><?= e($it['label']) ?> · اختياري</span>
      <?php else: ?>
        <span class="miss-chip" title="<?= e($it['hint']) ?>">
          بدون <?= e($it['label']) ?>
          <form method="post" action="<?= url('permits/'.$pid.'/rcp-dismiss') ?>" class="rg-inline">
            <?= csrfField() ?>
            <input type="hidden" name="label" value="<?= e($it['label']) ?>">
            <input type="hidden" name="back" value="<?= e($back) ?>">
            <button type="submit" class="rg-x" title="مش محتاجه في الإذن ده">✕</button>
          </form>
        </span>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>

  <details class="rg-chk">
    <summary>⚙️ ظبط بيانات الإذن للمرشد</summary>
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
