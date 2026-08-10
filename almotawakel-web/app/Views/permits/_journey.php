<?php
/**
 * رحلة الشحنة — تايم لاين حالة الإذن التشغيلية (v62).
 *
 * جزء مشترك بين وضعَي الشاشة (عرض/تعديل) — بيتضمّ **مرة واحدة** من الغلاف
 * `permits/show.php` زي `_hero.php` و`_docs.php` بالظبط.
 *
 * ⚠️ **عرض بحت** — ولا رقم مالي فيه. الوحدة وصفية معزولة (شوف `PermitTrack`).
 *
 * المتغيّرات: $p $pid $mode $journey $journeyBills $trackReady $isClosed
 */
use App\Models\PermitTrack;

if (empty($trackReady)) { return; }   // قبل migrate_v62 الوحدة بتختفي بأمان

$jTl       = $journey['timeline'] ?? [];
$jCur      = $journey['current'] ?? null;
$jNext     = $journey['next'] ?? null;
$jPct      = (int) ($journey['percent'] ?? 0);
$jFinished = !empty($journey['finished']);
$jStarted  = !empty($journey['started']);
$jIdle     = $journey['idle_hours'] ?? null;
$jBills    = $journeyBills ?? [];
$jMode     = ($mode ?? 'view') === 'manage' ? 'manage' : 'view';
$jToday    = work_date();

/* حالة الرحلة: منتهية / متأخرة (ساكنة +٤٨ ساعة) / ماشية / ما بدأتش.
   ⚠️ «متأخرة» تنبيه وصفي بحت — ما بيمنعش أي حاجة ولا بيرنّ (زي مرشد v32). */
$jState = !$jStarted ? 'idle' : ($jFinished ? 'done' : (($jIdle !== null && $jIdle >= 48) ? 'stale' : 'live'));
$jStateText = [
    'idle'  => 'لسه ما بدأتش',
    'live'  => 'الرحلة ماشية',
    'stale' => 'ساكنة من ' . PermitTrack::ago($journey['last_at'] ?? null),
    'done'  => 'الرحلة خلصت',
][$jState];

/** صف الأزرار المشترك لتسجيل مرحلة (الإذن كله أو بوليصة). */
$jForm = function (string $stageKey, int $billId, string $label, string $cls, string $body) use ($pid, $jMode, $jToday) {
    ?>
    <form method="post" action="<?= url('permits/' . $pid . '/track') ?>" class="pj-f">
      <?= csrfField() ?>
      <input type="hidden" name="stage_key" value="<?= e($stageKey) ?>">
      <input type="hidden" name="bill_id" value="<?= (int) $billId ?>">
      <input type="hidden" name="work_date" value="<?= e($jToday) ?>">
      <input type="hidden" name="mode" value="<?= $jMode ?>">
      <button class="<?= $cls ?>" type="submit" title="<?= e($label) ?>"><?= $body ?></button>
    </form>
    <?php
};
?>
<section class="pj card mb" id="journey" data-state="<?= $jState ?>">

  <?php /* ── الترويسة: الحالة الحالية + شريط التقدّم ─────────────────── */ ?>
  <header class="pj-head">
    <div class="pj-now">
      <span class="pj-now-ic"><?= icon($journey['icon'] ?? 'clock', 22) ?></span>
      <div class="pj-now-tx">
        <span class="pj-now-l">رحلة الشحنة</span>
        <b class="pj-now-b"><?= e($journey['label'] ?? 'لسه ما بدأتش') ?></b>
        <span class="pj-now-s">
          <?php if ($jCur !== null): ?>
            <?= e(PermitTrack::ago($journey['last_at'] ?? null)) ?>
            <?php if (!empty($jCur['event']['actor_name'])): ?>
              · <?= e($jCur['event']['actor_name']) ?>
            <?php endif; ?>
          <?php else: ?>
            <?php /* حالة مش تعليمات — الزرار الدهبي تحت بيقول الخطوة الجاية */ ?>
            مفيش مراحل مسجّلة
          <?php endif; ?>
        </span>
      </div>
      <span class="pj-badge is-<?= $jState ?>"><?= e($jStateText) ?></span>
    </div>

    <div class="pj-prog" role="img" aria-label="التقدّم <?= $jPct ?>٪">
      <div class="pj-prog-bar"><span style="width:<?= $jPct ?>%"></span></div>
      <b><?= $jPct ?>%</b>
    </div>
  </header>

  <?php /* ── الزرار السريع: المرحلة الجاية ───────────────────────────── */ ?>
  <?php if (!$isClosed && $jNext !== null): ?>
    <div class="pj-next">
      <span class="pj-next-l"><?= icon('chevron', 14) ?> الخطوة الجاية</span>
      <?php $jForm($jNext['key'], 0, $jNext['label'], 'btn gold sm',
            icon('check', 15) . ' سجّل: ' . e($jNext['label'])); ?>
      <span class="pj-next-h"><?= e($jNext['hint']) ?></span>
    </div>
  <?php endif; ?>

  <?php /* ── التايم لاين نفسه ─────────────────────────────────────────── */ ?>
  <ol class="pj-track">
    <?php foreach ($jTl as $s): $ev = $s['event']; ?>
      <li class="pj-step is-<?= $s['state'] ?><?= $s['terminal'] ? ' is-final' : '' ?>">
        <span class="pj-dot" aria-hidden="true"><?= icon($s['icon'], 15) ?></span>
        <div class="pj-body">
          <b class="pj-label"><?= e($s['label']) ?></b>
          <?php if ($ev !== null): ?>
            <span class="pj-when">
              <?= dfmt($ev['work_date']) ?: dtfmt($ev['happened_at'], 'd/m/Y') ?>
              <span class="pj-time"><?= dtfmt($ev['happened_at'], 'H:i') ?></span>
            </span>
            <span class="pj-who">
              <?php if (($ev['source'] ?? '') === 'system'): ?>
                <span class="pj-src is-sys"><?= icon('zap', 12) ?> النظام</span>
              <?php elseif (($ev['source'] ?? '') === 'rep'): ?>
                <span class="pj-src is-rep"><?= icon('user', 12) ?> <?= e($ev['actor_name'] ?: 'مندوب') ?></span>
              <?php else: ?>
                <span class="pj-src"><?= icon('building', 12) ?> <?= e($ev['actor_name'] ?: 'المكتب') ?></span>
              <?php endif; ?>
              <?php if ((int) $ev['bill_id'] > 0): ?>
                <span class="pj-src is-bill"><?= icon('truck', 12) ?> بوليصة واحدة</span>
              <?php endif; ?>
            </span>
            <?php if (!empty($ev['note'])): ?><span class="pj-note"><?= e($ev['note']) ?></span><?php endif; ?>
          <?php elseif ($s['state'] === 'skipped'): ?>
            <span class="pj-when pj-skip">اتخطّت — ما اتسجّلتش</span>
          <?php else: ?>
            <span class="pj-when pj-wait"><?= e($s['hint']) ?></span>
          <?php endif; ?>
        </div>

        <?php if (!$isClosed): ?>
          <div class="pj-act">
            <?php if ($ev === null): ?>
              <?php $jForm($s['key'], 0, 'سجّل ' . $s['label'], 'pj-mark', icon('check', 14) . '<i>سجّل</i>'); ?>
            <?php else: ?>
              <form method="post" action="<?= url('permits/' . $pid . '/track-undo') ?>" class="pj-f confirm-financial"
                    data-confirm="تشيل مرحلة «<?= e($s['label']) ?>» من الرحلة؟">
                <?= csrfField() ?>
                <input type="hidden" name="stage_key" value="<?= e($s['key']) ?>">
                <input type="hidden" name="bill_id" value="0">
                <input type="hidden" name="mode" value="<?= $jMode ?>">
                <button class="pj-undo" type="submit" title="تراجع عن المرحلة دي"><?= icon('undo', 14) ?></button>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>

  <?php /* ── الطبقة التفصيلية: رحلة كل عربية على حِدة ─────────────────── */ ?>
  <?php if ($jBills): ?>
    <details class="pj-bills">
      <summary><?= icon('truck', 15) ?> رحلة كل عربية <span class="pj-cnt"><?= intf(count($jBills)) ?></span></summary>
      <div class="pj-bills-in">
        <?php foreach ($jBills as $b): $bi = $b['bill']; ?>
          <article class="pj-bill">
            <div class="pj-bill-h">
              <span class="pj-bill-no"><?= e($bi['bill_number'] ?: '#' . $bi['id']) ?></span>
              <?php if (!empty($bi['car_no'])): ?><span class="pj-bill-m"><?= icon('truck', 12) ?> <bdi><?= e($bi['car_no']) ?></bdi></span><?php endif; ?>
              <?php if (!empty($bi['driver'])): ?><span class="pj-bill-m"><?= icon('user', 12) ?> <?= e($bi['driver']) ?></span><?php endif; ?>
              <?php if (!empty($bi['destination'])): ?><span class="pj-bill-m"><?= icon('map-pin', 12) ?> <?= e($bi['destination']) ?></span><?php endif; ?>
              <span class="pj-bill-st<?= $b['finished'] ? ' is-done' : '' ?>"><?= e($b['label']) ?></span>
            </div>
            <div class="pj-mini" role="img" aria-label="<?= e($b['label']) ?>">
              <?php foreach ($b['timeline'] as $ms): ?>
                <span class="pj-mini-s is-<?= $ms['state'] ?>" title="<?= e($ms['label']) ?><?= $ms['event'] ? ' — ' . dfmt($ms['event']['work_date']) : '' ?>"></span>
              <?php endforeach; ?>
            </div>
            <?php if (!$isClosed && ($b['next'] ?? null) !== null): ?>
              <div class="pj-bill-a">
                <?php $jForm($b['next']['key'], (int) $bi['id'], $b['next']['label'], 'pj-mark',
                      icon('check', 13) . '<i>' . e($b['next']['label']) . '</i>'); ?>
              </div>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    </details>
  <?php endif; ?>

  <?php /* ── تسجيل مرحلة بتاريخ سابق (للحالات اللي المكتب بيلحقها متأخر) ── */ ?>
  <?php if (!$isClosed): ?>
    <details class="pj-manual">
      <summary><?= icon('calendar', 14) ?> سجّل مرحلة بتاريخ وملاحظة</summary>
      <form method="post" action="<?= url('permits/' . $pid . '/track') ?>" class="pj-manual-f">
        <?= csrfField() ?>
        <input type="hidden" name="mode" value="<?= $jMode ?>">
        <label class="field"><span>المرحلة</span>
          <select name="stage_key" required>
            <?php foreach ($jTl as $s): ?>
              <option value="<?= e($s['key']) ?>"<?= ($jNext && $s['key'] === $jNext['key']) ? ' selected' : '' ?>><?= e($s['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field"><span>العربية</span>
          <select name="bill_id">
            <option value="0">كل الإذن</option>
            <?php foreach ($jBills as $b): $bi = $b['bill']; ?>
              <option value="<?= (int) $bi['id'] ?>"><?= e(($bi['bill_number'] ?: '#' . $bi['id']) . ($bi['car_no'] ? ' — ' . $bi['car_no'] : '')) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field"><span>يوم حصولها</span>
          <input type="date" name="work_date" value="<?= e($jToday) ?>">
        </label>
        <?php /* بلا `placeholder` — قانون التصميم: صفر جملة تعليمية (verify_density) */ ?>
        <label class="field"><span>ملاحظة (اختياري)</span>
          <input type="text" name="note" maxlength="200">
        </label>
        <button class="btn sm" type="submit"><?= icon('check', 14) ?> سجّل المرحلة</button>
      </form>
    </details>
  <?php endif; ?>
</section>
