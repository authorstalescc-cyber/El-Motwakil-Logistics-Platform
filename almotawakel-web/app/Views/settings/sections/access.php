<?php
/**
 * مصفوفة الصلاحيات (docs/19 · المرحلة ٥ · v78).
 *
 * ⚠️ جزء بيتحمّل من `settings/index.php` — **صفر `<style>` محلي**،
 *    الكلاسات كلها في `app.css` تحت «مركز التحكّم».
 *
 * ⚠️ **الإجابة في الجدول محسوبة من `Access::allows()` نفسها** — مش من نسخة
 *    تانية من المنطق. المصفوفة اللي بتحسب بإيدها بتوري حاجة والنظام بيعمل تانية.
 *
 * @var array $accessMatrix @var array $accessUsers @var array $roleLabels @var array $users
 */
$roles = array_filter(array_keys($roleLabels ?? []), static fn($r) => $r !== 'owner');
?>
<div class="card set-pad">
  <div class="section-head"><?= icon('shield', 17) ?> مين يقدر يعمل إيه</div>

  <?php /* تحذير **وظيفي**: بيقول قاعدة القراءة والاستثناء في ≤٦ كلمات مفيدة. */ ?>
  <p class="st-note">
    <?= icon('eye', 15) ?> يقرا · <?= icon('edit', 15) ?> يعدّل —
    <b>المالك مش في الجدول</b> لأنه ما بيتقيّدش، والإعدادات والدخول محميّان
    عشان يفضل فيه طريق رجوع.
  </p>

  <div class="table-wrap"><table class="data">
    <thead><tr>
      <th class="g-identity">الوحدة</th>
      <?php foreach ($roles as $r): ?>
        <th class="center"><?= e($roleLabels[$r]) ?></th>
      <?php endforeach; ?>
    </tr></thead>
    <tbody>
      <?php foreach ($accessMatrix as $ctrl => $row): ?>
        <tr>
          <td>
            <?= e($row['label']) ?>
            <?php if ($row['protected']): ?>
              <span class="pill"><?= icon('lock', 12) ?> محميّة</span>
            <?php endif; ?>
          </td>
          <?php foreach ($roles as $r): $c = $row['cells'][$r] ?? null; ?>
            <td class="center">
              <?php if ($c === null): ?>
                <span class="dim">—</span>
              <?php elseif ($row['protected']): ?>
                <?= $c['read'] ? icon('eye', 14) : '' ?><?= $c['write'] ? icon('edit', 14) : '' ?>
                <?= (!$c['read'] && !$c['write']) ? '<span class="dim">—</span>' : '' ?>
              <?php else: ?>
                <form method="post" action="<?= url('settings/access') ?>" class="inline-form no-confirm">
                  <?= csrfField() ?>
                  <input type="hidden" name="controller" value="<?= e($ctrl) ?>">
                  <input type="hidden" name="role" value="<?= e($r) ?>">
                  <select name="effect" onchange="this.form.submit()" aria-label="<?= e($row['label'] . ' — ' . $roleLabels[$r]) ?>">
                    <option value=""<?= $c['ov'] === '' ? ' selected' : '' ?>>
                      <?= $c['read'] ? ($c['write'] ? 'يقرا ويعدّل' : 'يقرا') : 'ممنوع' ?> (الأصلي)
                    </option>
                    <option value="allow"<?= $c['ov'] === 'allow' ? ' selected' : '' ?>>مسموح</option>
                    <option value="deny"<?= $c['ov'] === 'deny' ? ' selected' : '' ?>>ممنوع</option>
                  </select>
                </form>
              <?php endif; ?>
            </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php /* ══ استثناء لمستخدم بعينه ═══════════════════════════════════════════ */ ?>
<div class="card set-pad">
  <div class="section-head"><?= icon('users', 17) ?> استثناء لمستخدم بعينه</div>
  <p class="st-note">
    <?= icon('info', 15) ?> استثناء المستخدم <b>بيغلب</b> استثناء دوره — «فلان
    بالذات» أدقّ من «كل المحاسبين».
  </p>

  <?php if ($accessUsers): ?>
    <div class="table-wrap"><table class="data">
      <thead><tr><th class="g-identity">المستخدم</th><th>الوحدة</th><th class="g-meta">الاستثناء</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($accessUsers as $u): ?>
          <tr>
            <td><?= e($u['display_name'] ?: $u['username']) ?></td>
            <td class="dim"><?= e((string) $u['controller']) ?></td>
            <td><?= $u['effect'] === 'allow'
                  ? '<span class="pill st-paid">مسموح</span>'
                  : '<span class="pill">ممنوع</span>' ?></td>
            <td class="end">
              <form method="post" action="<?= url('settings/access') ?>" data-confirm="تشيل الاستثناء ده؟">
                <?= csrfField() ?>
                <input type="hidden" name="controller" value="<?= e((string) $u['controller']) ?>">
                <input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                <input type="hidden" name="effect" value="">
                <button type="submit" class="btn ghost sm"><?= icon('trash', 14) ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>

  <form method="post" action="<?= url('settings/access') ?>" class="mt-4">
    <?= csrfField() ?>
    <div class="st-grid">
      <div class="field"><label for="acc-user">المستخدم</label>
        <select id="acc-user" name="user_id" required>
          <option value="">— اختار —</option>
          <?php foreach (($users ?? []) as $u): if (($u['role'] ?? '') === 'owner') { continue; } ?>
            <option value="<?= (int) $u['id'] ?>"><?= e($u['display_name'] ?: $u['username']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label for="acc-ctrl">الوحدة</label>
        <select id="acc-ctrl" name="controller" required>
          <?php foreach ($accessMatrix as $ctrl => $row): if ($row['protected']) { continue; } ?>
            <option value="<?= e($ctrl) ?>"><?= e($row['label']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label for="acc-effect">الاستثناء</label>
        <select id="acc-effect" name="effect" required>
          <option value="allow">مسموح</option>
          <option value="deny">ممنوع</option>
        </select></div>
    </div>
    <button class="btn gold mt-4" type="submit"><?= icon('plus', 14) ?> سجّل الاستثناء</button>
  </form>
</div>
