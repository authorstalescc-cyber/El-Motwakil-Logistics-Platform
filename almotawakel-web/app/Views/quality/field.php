<?php
/**
 * تنظيف حقل واحد — المالك بيشوف كل مجموعة ويختار الاسم المعتمد بنفسه.
 *
 * ⚠️ الاسم المُقترَح = **الأكتر استخدامًا** وهو مختار افتراضيًّا، لكنه راديو
 *    قابل للتغيير — الشاشة **ما بتدمجش لوحدها أبدًا**، دي كانت مشكلة زرار
 *    «توحيد المسميات» القديم.
 */
$reasonLabel = [
    'concept'  => 'نفس الاسم بصيغة تانية',
    'subset'   => 'كلمة زايدة أو ناقصة',
    'spelling' => 'غلط مطبعي محتمل',
];

/** يرسم مجموعة واحدة كفورم دمج مستقل. */
$cluster = function (array $c, int $i) use ($fieldKey, $reasonLabel): void {
    $members = $c['members'];
    $isSame  = $c['kind'] === 'same';
    ?>
    <div class="card p5-pad p5-mb">
      <div class="p5-row tight p5-mb-10">
        <?php if ($isSame): ?>
          <span class="pill p5-tag-red">نفس الشيء قطعًا</span>
        <?php else: ?>
          <span class="pill p5-tag-gold">
            <?= e($reasonLabel[$c['reason'] ?? ''] ?? 'متشابه') ?>
          </span>
          <span class="dbt-sub">راجعها — ممكن يكونوا حاجتين مختلفتين فعلاً</span>
        <?php endif; ?>
      </div>

      <?php // ⚠️ الفورم متعرَّف فاضي والحقول مربوطة بيه بـ`form=""` — الصفحة فيها
            //    مجموعات كتير، ولو الفورم لفّ الجدول كانت أسماء الراديو
            //    (`canonical`) هتتصادم بين المجموعات وكل واحدة تبعت قيمة غيرها.
            //    و`data-confirm` مكانها **الفورم** مش الزرار (كده app.js بيقراها). ?>
      <form id="qf<?= $i ?>" method="post" action="<?= url('quality/' . $fieldKey . '/merge') ?>"
            data-confirm="هيتوحّدوا كلهم على الاسم اللي اخترته. تمام؟">
        <?= csrfField() ?>
      </form>
      <div>
        <table class="data p5-mb-10" data-table-tools-off>
          <thead>
            <tr>
              <th data-no-sort>الاسم المعتمد</th>
              <th data-no-sort>التهجئة</th>
              <th data-no-sort>مستخدَمة في</th>
            </tr>
          </thead>
          <tbody>
            <?php $first = true; foreach ($members as $name => $n): ?>
              <tr>
                <td class="w120">
                  <label class="p5-chk">
                    <input type="radio" name="canonical" value="<?= e((string) $name) ?>"
                           <?= $first ? 'checked' : '' ?>
                           form="qf<?= $i ?>" required>
                    <span>اعتمدها</span>
                  </label>
                </td>
                <td><b><?= e((string) $name) ?></b></td>
                <td><?= $n > 0 ? (int) $n . ' سجل' : '<span class="dbt-none">القائمة بس</span>' ?></td>
              </tr>
              <input type="hidden" name="variants[]" value="<?= e((string) $name) ?>" form="qf<?= $i ?>">
            <?php $first = false; endforeach; ?>
          </tbody>
        </table>
        <button class="btn gold sm" type="submit" form="qf<?= $i ?>">
          <?= icon('download', 14) ?> وحّدهم
        </button>
      </div>
    </div>
    <?php
};
?>

<div class="card p5-pad-16 mb">
  <div class="section-head"><?= icon('clipboard', 17) ?> <?= e($group['label']) ?></div>
  <p class="set-p">
    <?= (int) $clusters['total'] ?> قيمة مسجّلة ·
    <b><?= count($clusters['same']) ?></b> مجموعة نفس الشيء ·
    <b><?= count($clusters['similar']) ?></b> مجموعة متشابهة.
    <br>
    اختر الاسم الصح في كل مجموعة ودوس «وحّدهم» — الباقي هيتحوّل ليه في كل السجلات.
    <b>مفيش حاجة بتتمسح</b>، البيانات بتتوحّد بس.
  </p>
</div>

<?php if (count($values) >= 2): ?>
  <div class="card p5-pad-16 mb">
    <div class="section-head"><?= icon('shuffle', 17) ?> دمج يدوي</div>
    <p class="set-p">
      لو الاسمان مختلفان تمامًا لكنك تعرف أنهما لنفس الشيء، اختر القيمة الخاطئة
      والاسم المعتمد. سيُصلَح القديم، وتُحفظ القيمة الخاطئة اسمًا بديلًا حتى
      تتحول تلقائيًا لو كُتبت مرة أخرى.
    </p>
    <form method="post" action="<?= url('quality/' . $fieldKey . '/merge') ?>"
          data-confirm="سيتم توحيد القيمة الخاطئة على الاسم المعتمد وحفظها اسمًا بديلًا. تمام؟">
      <?= csrfField() ?>
      <div class="form-grid">
        <div class="field">
          <label for="manual-variant">القيمة الخاطئة</label>
          <select id="manual-variant" name="variants[]" required>
            <option value="">اختر القيمة…</option>
            <?php foreach ($values as $name => $n): ?>
              <option value="<?= e((string) $name) ?>"><?= e((string) $name) ?> (<?= (int) $n ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="manual-canonical">الاسم المعتمد</label>
          <select id="manual-canonical" name="canonical" required>
            <option value="">اختر الاسم…</option>
            <?php foreach ($values as $name => $n): ?>
              <option value="<?= e((string) $name) ?>"><?= e((string) $name) ?> (<?= (int) $n ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <button class="btn gold sm" type="submit"><?= icon('download', 14) ?> وحّد واحفظ كاسم بديل</button>
    </form>
  </div>
<?php endif; ?>

<?php if (!$clusters['same'] && !$clusters['similar']): ?>
  <?php partial('empty', ['emoji' => '✨', 'title' => 'الحقل ده نضيف — مفيش تهجئات مكرَّرة ولا متشابهة']); ?>
<?php endif; ?>

<?php $i = 0; ?>

<?php if ($clusters['same']): ?>
  <div class="section-head">نفس الشيء بأكتر من تهجئة (<?= count($clusters['same']) ?>)</div>
  <?php foreach ($clusters['same'] as $c) { $cluster($c, $i++); } ?>
<?php endif; ?>

<?php if ($clusters['similar']): ?>
  <div class="section-head p5-mt-20">متشابه — محتاج مراجعتك (<?= count($clusters['similar']) ?>)</div>
  <?php foreach ($clusters['similar'] as $c) { $cluster($c, $i++); } ?>
<?php endif; ?>

<?php
// مفردات في القائمة المنسدلة مش مستخدَمة في أي سجل — خردة تتشال بأمان
$dead = array_filter($values, static fn($n) => $n === 0);
if ($dead && !empty($group['lookup'])):
?>
  <div class="section-head p5-mt-20">مفردات في القائمة مش مستخدَمة في أي سجل (<?= count($dead) ?>)</div>
  <div class="card p5-pad">
    <p class="set-p">
      دي موجودة في القائمة المنسدلة بس مفيش ولا سجل واحد بيستخدمها — شيلها عشان
      ما تظهرش للموظفين كخيار.
    </p>
    <div class="chip-list">
      <?php foreach (array_keys($dead) as $v): ?>
        <span class="chip"><?= e((string) $v) ?>
          <form class="confirm-delete" method="post" action="<?= url('quality/' . $fieldKey . '/drop') ?>"
                 data-confirm="شيل «<?= e((string) $v) ?>» من القائمة؟">
            <?= csrfField() ?>
            <input type="hidden" name="value" value="<?= e((string) $v) ?>">
            <button type="submit" title="شيل من القائمة">×</button>
          </form>
        </span>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>
