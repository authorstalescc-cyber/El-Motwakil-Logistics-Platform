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
    <div class="card" style="padding:14px;margin-bottom:12px">
      <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px">
        <?php if ($isSame): ?>
          <span class="pill" style="background:var(--red-lt);color:var(--red)">نفس الشيء قطعًا</span>
        <?php else: ?>
          <span class="pill" style="background:#FBF3DC;color:var(--gold-dark)">
            <?= e($reasonLabel[$c['reason'] ?? ''] ?? 'متشابه') ?>
          </span>
          <span style="color:var(--grey);font-size:var(--fs-1)">راجعها — ممكن يكونوا حاجتين مختلفتين فعلاً</span>
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
        <table class="data" data-table-tools-off style="margin-bottom:10px">
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
                <td style="width:120px">
                  <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
                    <input type="radio" name="canonical" value="<?= e((string) $name) ?>"
                           <?= $first ? 'checked' : '' ?>
                           form="qf<?= $i ?>" required>
                    <span>اعتمدها</span>
                  </label>
                </td>
                <td><b><?= e((string) $name) ?></b></td>
                <td><?= $n > 0 ? (int) $n . ' سجل' : '<span style="color:var(--grey)">القائمة بس</span>' ?></td>
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

<div class="card" style="padding:16px;margin-bottom:16px">
  <div class="section-head"><?= icon('clipboard', 17) ?> <?= e($group['label']) ?></div>
  <p style="color:var(--grey);margin:0;line-height:1.9">
    <?= (int) $clusters['total'] ?> قيمة مسجّلة ·
    <b><?= count($clusters['same']) ?></b> مجموعة نفس الشيء ·
    <b><?= count($clusters['similar']) ?></b> مجموعة متشابهة.
    <br>
    اختر الاسم الصح في كل مجموعة ودوس «وحّدهم» — الباقي هيتحوّل ليه في كل السجلات.
    <b>مفيش حاجة بتتمسح</b>، البيانات بتتوحّد بس.
  </p>
</div>

<?php if (!$clusters['same'] && !$clusters['similar']): ?>
  <?php partial('empty', ['emoji' => '✨', 'title' => 'الحقل ده نضيف — مفيش تهجئات مكرَّرة ولا متشابهة']); ?>
<?php endif; ?>

<?php $i = 0; ?>

<?php if ($clusters['same']): ?>
  <div class="section-head">نفس الشيء بأكتر من تهجئة (<?= count($clusters['same']) ?>)</div>
  <?php foreach ($clusters['same'] as $c) { $cluster($c, $i++); } ?>
<?php endif; ?>

<?php if ($clusters['similar']): ?>
  <div class="section-head" style="margin-top:20px">متشابه — محتاج مراجعتك (<?= count($clusters['similar']) ?>)</div>
  <?php foreach ($clusters['similar'] as $c) { $cluster($c, $i++); } ?>
<?php endif; ?>

<?php
// مفردات في القائمة المنسدلة مش مستخدَمة في أي سجل — خردة تتشال بأمان
$dead = array_filter($values, static fn($n) => $n === 0);
if ($dead && !empty($group['lookup'])):
?>
  <div class="section-head" style="margin-top:20px">مفردات في القائمة مش مستخدَمة في أي سجل (<?= count($dead) ?>)</div>
  <div class="card" style="padding:14px">
    <p style="color:var(--grey);margin:0 0 10px;line-height:1.9">
      دي موجودة في القائمة المنسدلة بس مفيش ولا سجل واحد بيستخدمها — شيلها عشان
      ما تظهرش للموظفين كخيار.
    </p>
    <div class="chip-list">
      <?php foreach (array_keys($dead) as $v): ?>
        <span class="chip"><?= e((string) $v) ?>
          <form class="confirm-delete" method="post" action="<?= url('quality/' . $fieldKey . '/drop') ?>"
                style="display:inline" data-confirm="شيل «<?= e((string) $v) ?>» من القائمة؟">
            <?= csrfField() ?>
            <input type="hidden" name="value" value="<?= e((string) $v) ?>">
            <button type="submit" title="شيل من القائمة">×</button>
          </form>
        </span>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>
