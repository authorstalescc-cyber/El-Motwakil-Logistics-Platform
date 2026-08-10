<?php
/**
 * قسم من **السجل المعرَّف** — بيترسم من `SettingsSchema` بلا أي اسم مفتاح
 * مكتوب هنا (docs/19 · المرحلة ١). القسم بيتحدّد من `$section` الجاي من المسار.
 *
 * ⚠️ القسم اللي مالوش مفاتيح **ما بيرندرش كارت فاضي** — بيقول كده صراحةً،
 *    لأن كارت بعنوان بلا محتوى بيوري إن فيه حاجة اتكسرت.
 *
 * @var string $section  @var array $sections  @var array $sectionMeta  @var array $chains
 */
$sKey    = (string) ($section ?? '');
$sFields = $sections[$sKey] ?? [];
?>
<?php if (!$sFields && empty($chains[array_key_first($chains ?? [])] ?? null)): ?>
  <?php partial('empty', ['title' => 'مفيش إعدادات في القسم ده', 'icon' => 'settings']); ?>
<?php endif; ?>
<?php if ($sFields): ?>

    <div class="card set-pad narrow">
      <div class="section-head"><?= e($sectionMeta[$sKey] ?? $sKey) ?></div>
      <?php
        $hasDanger = false;
        foreach ($sFields as $f) {
          if ($f['def']['tier'] === \App\Core\SettingsSchema::TIER_DANGER) { $hasDanger = true; break; }
        }
      ?>
      <?php if ($hasDanger): ?>
        <?php /* تحذير **وظيفي** لا تعليمي (قانون التصميم ⑤): بيقول نتيجة الفعل
                 مش بيشرح الشاشة، وفي ≤٦ كلمات مفيدة. */ ?>
        <p class="st-note"><?= icon('alert', 15) ?> البنود المعلَّمة ⚠️ بتحرّك أرقامًا محسوبة. كل تغيير بيتسجّل باسمك وبيرجع بضغطة.</p>
      <?php endif; ?>
      <form method="post" action="<?= url('settings/section/' . $sKey) ?>">
        <?= csrfField() ?>
        <div class="st-grid">
          <?php foreach ($sFields as $fKey => $f): ?>
            <?php partial('setting_field', [
              'key'   => $fKey,
              'value' => $f['value'],
              'class' => $f['def']['type'] === \App\Core\SettingsSchema::T_LIST ? 'field-full' : '',
            ]); ?>
          <?php endforeach; ?>
        </div>
        <div class="mt-4"><button class="btn gold" type="submit"><?= icon('download', 14) ?> حفظ <?= e($sectionMeta[$sKey] ?? '') ?></button></div>
      </form>
    </div>
<?php endif; ?>

    <?php /* ══ السلاسل المؤرَّخة (v74) ══════════════════════════════════════
             ⚠️ الإعداد ده **مالوش خانة واحدة عن قصد**: خانة واحدة معناها
                «غيّر القيمة على كل التاريخ»، والرقم بيتضرب في كل بوليصة قديمة.
                هنا بتقول «بقت كذا **من** تاريخ كذا» — واللي قبله ما بيتحركش. */ ?>
    <?php foreach (($chains ?? []) as $cKey => $chain): ?>
      <?php if (($chain['def']['section'] ?? '') !== $sKey) { continue; } ?>
      <div class="card set-pad">
        <div class="section-head"><?= icon('calculator', 17) ?> <?= e($chain['def']['label']) ?></div>
        <p class="st-note">
          <?= icon('alert', 15) ?> السارية دلوقتي <b><?= e($chain['now']) ?></b>.
          التغيير بيسري <b>من التاريخ اللي تختاره ورايح</b> — البوالص اللي قبله بتفضل زي ما هي.
        </p>
        <div class="table-wrap"><table class="data">
          <thead><tr><th class="g-identity">سارية من</th><th>القيمة</th><th class="g-meta">السبب</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($chain['rows'] as $row): ?>
              <tr>
                <td><?= $row['is_base'] ? '<span class="pill">الأساس التاريخي</span>' : '<bdi>' . e((string) $row['effective_from']) . '</bdi>' ?></td>
                <td><b><?= e((string) $row['value']) ?></b></td>
                <td class="dim"><?= e((string) ($row['note'] ?? '—')) ?></td>
                <td class="end">
                  <?php if (!$row['is_base']): ?>
                    <form method="post" action="<?= url('settings/schedule/' . (int) $row['id'] . '/delete') ?>"
                          data-confirm="شيل التغيير السارٍ من <?= e((string) $row['effective_from']) ?>؟">
                      <?= csrfField() ?>
                      <button type="submit" class="btn ghost sm"><?= icon('trash', 14) ?></button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>

        <form method="post" action="<?= url('settings/schedule') ?>" class="mt-4">
          <?= csrfField() ?>
          <input type="hidden" name="setting_key" value="<?= e($cKey) ?>">
          <div class="st-grid">
            <div class="field"><label for="sch-from-<?= e($cKey) ?>">سارية من تاريخ</label>
              <input type="date" id="sch-from-<?= e($cKey) ?>" name="effective_from" required></div>
            <div class="field"><label for="sch-val-<?= e($cKey) ?>">القيمة الجديدة</label>
              <input type="number" step="0.01" data-money id="sch-val-<?= e($cKey) ?>" name="value" required></div>
            <div class="field field-full"><label for="sch-note-<?= e($cKey) ?>">السبب</label>
              <input type="text" id="sch-note-<?= e($cKey) ?>" name="note" maxlength="255"></div>
          </div>
          <button class="btn gold mt-4" type="submit"><?= icon('plus', 14) ?> سجّل التغيير</button>
        </form>
      </div>
    <?php endforeach; ?>
