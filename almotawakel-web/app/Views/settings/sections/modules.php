<?php
/**
 * الوحدات والقائمة الجانبية (docs/19 · المرحلة ٤ · v77).
 *
 * ⚠️ جزء بيتحمّل من `settings/index.php` — **صفر `<style>` محلي**،
 *    الكلاسات كلها في `app.css` تحت «مركز التحكّم».
 *
 * @var array $modules
 */
?>
<div class="card set-pad">
  <div class="section-head"><?= icon('menu', 17) ?> ترتيب القائمة وإظهار الوحدات</div>

  <?php /* تحذير **وظيفي**: بيقول الفرق بين الخيارين ونتيجة كل واحد — مش بيشرح
           الشاشة (قانون التصميم ⑤). */ ?>
  <p class="st-note warn">
    <?= icon('alert', 15) ?>
    <b>مخفية</b> = تختفي من القائمة والرابط لسه شغّال ·
    <b>مطفية</b> = الرابط نفسه بيرفض. <b>ولا بيان بيتمسح في الحالتين.</b>
  </p>

  <form method="post" action="<?= url('settings/modules') ?>">
    <?= csrfField() ?>
    <div class="table-wrap"><table class="data">
      <thead><tr>
        <th class="g-identity w90">الترتيب</th>
        <th class="g-identity">الوحدة</th>
        <th class="g-identity">اسم بديل</th>
        <th class="g-meta w110">مخفية</th>
        <th class="g-meta w110">مطفية</th>
      </tr></thead>
      <tbody>
        <?php foreach ($modules as $m): $s = $m['slug']; ?>
          <tr>
            <td><input type="number" class="set-num" name="order[<?= e($s) ?>]" value="<?= (int) $m['order'] ?>"></td>
            <td>
              <?= icon($m['icon'], 15) ?> <?= e($m['default']) ?>
              <?php if ($m['locked']): ?>
                <span class="pill"><?= icon('lock', 12) ?> طريق الرجوع</span>
              <?php endif; ?>
            </td>
            <td><input type="text" class="set-in" name="label[<?= e($s) ?>]"
                       value="<?= e($m['label']) ?>" maxlength="80"></td>
            <td class="center">
              <?php if ($m['locked']): ?>
                <span class="dim">—</span>
              <?php else: ?>
                <label class="pointer"><input type="checkbox" class="set-cbx"
                  name="hidden[<?= e($s) ?>]" <?= $m['hidden'] ? 'checked' : '' ?>></label>
              <?php endif; ?>
            </td>
            <td class="center">
              <?php if ($m['locked']): ?>
                <span class="dim">—</span>
              <?php else: ?>
                <label class="pointer"><input type="checkbox" class="set-cbx"
                  name="disabled[<?= e($s) ?>]" <?= $m['disabled'] ? 'checked' : '' ?>></label>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="mt-4"><button class="btn gold" type="submit"><?= icon('download', 14) ?> حفظ القائمة</button></div>
  </form>
</div>
