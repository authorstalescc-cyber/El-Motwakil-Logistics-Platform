<?php
/**
 * تبويب «أذوناته» — تنزيل الأذونات وجدول المنزَّل.
 *
 * ⚠️ التنزيل من v43 بقى **شارة وأولوية ترتيب** مش شرط رؤية ولا كتابة: المندوب
 *    بيشوف كل الأذونات المفتوحة على موبايله. الحد الأمني الوحيد الباقي هو
 *    **قفل الإذن** — والمقفول ما بيظهرش هنا أصلاً (`RepAssignment::available`).
 *
 * متغيّرات جاية من `card.php`: $repId $assigns $openPermits $assignState…
 */
use App\Core\FormState;
?>
<div class="d-card mb" id="assign-form">
  <div class="d-card-head"><h3><?= icon('folder', 18) ?> <span>نزّل أذونات للمندوب</span></h3></div>
  <?= formErrorSummary($assignErrors) ?>
  <form method="post" action="<?= url('rep-custody/' . $repId . '/assign') ?>" style="padding:14px 18px" id="asgForm">
    <?= csrfField() ?>
    <?= formStateFields($assignState['token'], $assignState['back']) ?>

    <?php if (!$openPermits): ?>
      <?php partial('empty', [
        'emoji' => '📭',
        'title' => 'مفيش أذونات مفتوحة متاحة للتنزيل',
        'action' => ['label' => '📄 الأذونات والشهادات', 'url' => url('permits')],
      ]); ?>
    <?php else: ?>
      <input type="search" class="asg-q" placeholder="بحث"
             autocomplete="off" aria-label="بحث في الأذونات المتاحة">

      <div class="asg-list">
        <?php foreach ($openPermits as $p): ?>
          <?php
            // نص البحث: كل حاجة ظاهرة في الكارت عشان البحث يلاقيها
            $hay = mb_strtolower(trim(implode(' ', array_filter([
                $p['permit_no'], $p['company'], $p['client'], $p['destination'],
                \App\Models\Permit::noLabel($p['permit_type']),
            ]))));
          ?>
          <label class="asg-item" data-q="<?= e($hay) ?>">
            <input type="checkbox" name="permit_ids[]" value="<?= (int) $p['id'] ?>" class="js-asg"
                   <?= in_array((int) $p['id'], $selectedPermits, true) ? 'checked' : '' ?><?= fieldErrorAttrs('permit_ids', $assignErrors) ?>>
            <span class="asg-body">
              <span class="asg-top">
                <strong><?= e(\App\Models\Permit::noLabel($p['permit_type'])) ?>
                        <?= e($p['permit_no'] ?: '#' . $p['id']) ?></strong>
                <?php if ((int) $p['containers']): ?>
                  <span class="pill st-partial"><?= icon('package', 14) ?> <?= (int) $p['containers'] ?><?=
                    $p['container_type'] ? ' × ' . e(container_norm($p['container_type'])) : '' ?></span>
                <?php endif; ?>
                <?php if ((int) $p['other_reps']): ?>
                  <span class="pill st-partial" title="منزّل كمان لمندوب تاني"><?= icon('user', 14) ?>‍<?= icon('briefcase', 14) ?> <?= (int) $p['other_reps'] ?></span>
                <?php endif; ?>
              </span>
              <span class="asg-sub">
                <?php if (!empty($p['company'])): ?><span><?= icon('building', 14) ?> <?= e($p['company']) ?></span><?php endif; ?>
                <?php if (!empty($p['client']) && $p['client'] !== $p['company']): ?>
                  <span>· <?= icon('user', 14) ?> <?= e($p['client']) ?></span><?php endif; ?>
                <?php if (!empty($p['destination'])): ?><span>· <?= icon('map-pin', 14) ?> <?= e($p['destination']) ?></span><?php endif; ?>
                <span>· <?= icon('calendar', 14) ?> <?= e(dfmt($p['permit_date'])) ?></span>
                <span>· <?= (int) $p['movs'] ?> حركة</span>
              </span>
            </span>
          </label>
        <?php endforeach; ?>
        <div class="asg-none" hidden>مفيش إذن بالبحث ده.</div>
      </div>
      <?= fieldError('permit_ids', $assignErrors) ?>

      <div class="field" style="margin-top:10px"><label>ملاحظة للمندوب (اختيارية)</label>
        <input type="text" name="note" maxlength="255" value="<?= e($assignOld['note'] ?? '') ?>"
               placeholder="مثلاً: خلّص التعتيق الأول"<?= fieldErrorAttrs('note', $assignErrors) ?>>
        <?= fieldError('note', $assignErrors) ?></div>

      <div class="asg-foot">
        <button class="btn" type="submit" id="asgGo" disabled data-primary-submit><?= icon('plus', 15) ?> نزّلهم له</button>
        <span class="asg-count">ما اخترتش حاجة</span>
      </div>
      <div class="hint">
        هيلاقيهم على موبايله فورًا. <strong>الأذونات المقفولة مش بتظهر هنا</strong> —
        الإذن لازم يكون مفتوح عشان ينفع تنزّله.
      </div>
    <?php endif; ?>
  </form>
</div>

<?php /* الجدول (٥ أعمدة) اتشال: كل صف هنا **إذن** مش سطر مقارنة رقمية. */ ?>
<?php partial('panel', [
  'title'  => 'الأذونات المنزّلة له',
  'icon'   => 'folder',
  'badge'  => $assigns ? ['text' => intf(count($assigns)), 'tone' => 'navy'] : null,
  'scroll' => true,
  'class'  => 'mb',
  'body'   => function () use ($assigns, $repId) {
    if (!$assigns) {
      partial('empty', [
        'emoji' => '📋',
        'title' => 'مفيش أذونات منزّلة له',
      ]);
      return;
    }
    foreach ($assigns as $a) {
      $active = $a['status'] === 'active';
      $meta = [];
      if (!empty($a['company'])) { $meta[] = ['icon' => 'home', 'text' => $a['company']]; }
      if (!empty($a['client']) && $a['client'] !== $a['company']) {
        $meta[] = ['icon' => 'user', 'text' => $a['client']];
      }
      $meta[] = ['icon' => 'layers', 'text' => intf((int) $a['subs']) . ' بند'];
      $chips = [['icon' => $active ? 'check-circle' : 'clock', 'text' => $active ? 'نشط' : 'مقفول']];
      /* الإذن ممكن يتقفل بعد ما اتنزّل — تنبيه بدل ما التنزيل يفضل «نشط» بالسكوت */
      if ($active && ($a['permit_status'] ?? 'open') !== 'open') {
        $chips[] = ['icon' => 'alert', 'text' => 'الإذن نفسه مقفول'];
      }
      partial('row_item', [
        'title'  => \App\Models\Permit::noLabel($a['permit_type'] ?? 'sader')
                    . ' ' . ($a['permit_no'] ?: '#' . $a['permit_id']),
        'icon'   => 'folder',
        'url'    => url('permits/' . $a['permit_id'] . '/manage'),
        'meta'   => $meta,
        'chips'  => $chips,
        'extra'  => function () use ($a, $repId) {
          $act = $a['status'] === 'active' ? 'done' : 'back'; ?>
          <form method="post" action="<?= url('rep-custody/assign/' . $a['id'] . '/' . $act) ?>">
            <?= csrfField() ?>
            <?= formStateFields(FormState::token(), 'rep-custody/' . $repId . '?tab=permits') ?>
            <input type="hidden" name="rep_id" value="<?= $repId ?>">
            <button class="edit" type="submit" title="<?= $a['status'] === 'active' ? 'اقفل التنزيل' : 'رجّعه نشط' ?>">
              <?= icon($a['status'] === 'active' ? 'clock' : 'check', 13) ?></button>
          </form>
          <?php if ((int) $a['subs'] === 0): ?>
            <form method="post" action="<?= url('rep-custody/assign/' . $a['id'] . '/remove') ?>"
                  class="confirm-delete" data-confirm="تشيل التنزيل ده؟">
              <?= csrfField() ?>
              <?= formStateFields(FormState::token(), 'rep-custody/' . $repId . '?tab=permits') ?>
              <input type="hidden" name="rep_id" value="<?= $repId ?>">
              <button class="edit" type="submit" title="شيل"><?= icon('trash', 13) ?></button>
            </form>
          <?php endif;
        },
      ]);
    }
  },
]); ?>

<style>
/* ── قائمة تنزيل الأذونات: بحث + كروت قابلة للاختيار ── */
.asg-q{width:100%;padding:9px 12px;border:1px solid var(--line2);border-radius:var(--radius-2);
  font-family:inherit;font-size:var(--fs-2);background:var(--card);margin-bottom:9px}
.asg-q:focus{outline:none;border-color:var(--navy)}

.asg-list{max-height:340px;overflow-y:auto;border:1px solid var(--line);border-radius:var(--radius-3);background:var(--card)}
.asg-item{display:flex;align-items:flex-start;gap:10px;padding:10px 12px;cursor:pointer;
  border-bottom:1px solid var(--line)}
.asg-item:last-of-type{border-bottom:0}
.asg-item:hover{background:var(--zebra)}
.asg-item input{width:17px;height:17px;margin:2px 0 0;flex:none;cursor:pointer}
/* الكارت المختار يبان فورًا — المالك بيختار كذا واحد ولازم يشوف اللي علّمه */
.asg-item:has(input:checked){background:var(--green-lt)}

.asg-body{flex:1;min-width:0}
.asg-top{display:flex;align-items:center;gap:7px;flex-wrap:wrap;font-size:var(--fs-2)}
.asg-sub{display:flex;gap:5px;flex-wrap:wrap;font-size:var(--fs-1);color:var(--grey);margin-top:3px}
.asg-none{padding:22px 12px;text-align:center;color:var(--grey);font-size:var(--fs-2)}

.asg-foot{display:flex;align-items:center;gap:10px;margin-top:12px;flex-wrap:wrap}
.asg-count{font-size:var(--fs-1);color:var(--grey);font-weight:500}
.asg-count.on{color:var(--green)}
</style>

<script>
(function () {
  var form = document.getElementById('asgForm');
  if (!form) { return; }
  var q     = form.querySelector('.asg-q');
  var items = Array.prototype.slice.call(form.querySelectorAll('.asg-item'));
  var none  = form.querySelector('.asg-none');
  var go    = document.getElementById('asgGo');
  var lbl   = form.querySelector('.asg-count');
  if (!items.length) { return; }

  // عدّاد المختار + تفعيل الزرار (ما ينفعش يبعت فورم فاضي)
  function sync() {
    var n = form.querySelectorAll('.js-asg:checked').length;
    go.disabled = !n;
    lbl.textContent = n ? ('مختار ' + n + ' إذن') : 'ما اخترتش حاجة';
    lbl.classList.toggle('on', !!n);
  }
  form.querySelectorAll('.js-asg').forEach(function (c) { c.addEventListener('change', sync); });
  sync();

  // بحث حي — المختار بيفضل ظاهر دايمًا عشان ما يضيعش من تحت إيد المالك
  q.addEventListener('input', function () {
    var s = q.value.trim().toLowerCase();
    var shown = 0;
    items.forEach(function (it) {
      var hit = !s || it.dataset.q.indexOf(s) !== -1 || it.querySelector('.js-asg').checked;
      it.hidden = !hit;
      if (hit) { shown++; }
    });
    none.hidden = shown > 0;
  });
})();
</script>
