<?php
/**
 * فحص صحة النظام + التصدير والاستيراد (docs/19 · المرحلة ٧ · v79).
 *
 * ⚠️ جزء بيتحمّل من `settings/index.php` — **صفر `<style>` محلي**،
 *    الكلاسات كلها في `app.css` تحت «مركز التحكّم».
 *
 * ⚠️ **كل بند بيقول المشكلة والحل** — «الرقم ده غلط» بلا «أعمل إيه» بيخلّي
 *    المالك يقفل الشاشة.
 *
 * @var array $health
 */
$errors = array_values(array_filter($health, static fn($h) => $h[0] === 'error'));
$warns  = array_values(array_filter($health, static fn($h) => $h[0] !== 'error'));
?>
<div class="card set-pad">
  <div class="section-head"><?= icon('shield', 17) ?> صحة الإعدادات</div>

  <?php if (!$health): ?>
    <p class="st-note">
      <?= icon('check', 15) ?> كل الإعدادات سليمة ومتّسقة — مفيش قيمة برّه نطاقها
      ولا تعارض بين قاعدتين.
    </p>
  <?php else: ?>
    <p class="st-note<?= $errors ? ' warn' : '' ?>">
      <?= icon('alert', 15) ?>
      <b><?= count($errors) ?></b> لازم يتصلّح · <b><?= count($warns) ?></b> يستاهل نظرة.
    </p>
    <div class="table-wrap"><table class="data wrap-normal">
      <thead><tr><th class="g-identity w110">الحالة</th><th class="g-identity">المشكلة</th><th>تعمل إيه</th></tr></thead>
      <tbody>
        <?php foreach (array_merge($errors, $warns) as $h): ?>
          <tr>
            <td><?= $h[0] === 'error'
                  ? '<span class="pill st-unpaid">لازم يتصلّح</span>'
                  : '<span class="pill">نظرة</span>' ?></td>
            <td><b><?= e($h[1]) ?></b></td>
            <td class="dim"><?= e($h[2]) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>

<div class="st-grid">
  <div class="card set-pad">
    <div class="section-head"><?= icon('download', 17) ?> تصدير الإعدادات</div>
    <?php /* تحذير **وظيفي**: بيقول اللي مش هيتصدّر ونتيجته. */ ?>
    <p class="st-note">
      <?= icon('lock', 15) ?> المفاتيح السرّية (المساعد الذكي · VAPID)
      <b>مش بتتصدّر</b> — الملف ممكن يتبعت بالإيميل.
    </p>
    <a class="btn" href="<?= url('settings/export') ?>">
      <?= icon('download', 14) ?> نزّل ملف الإعدادات
    </a>
  </div>

  <div class="card set-pad">
    <div class="section-head"><?= icon('inbox', 17) ?> استيراد ملف إعدادات</div>
    <p class="st-note">
      <?= icon('info', 15) ?> كل قيمة بتعدّي على نفس التحقّق والتسجيل —
      المرفوض بيتقال بالاسم والباقي بيتطبّق.
    </p>
    <form method="post" action="<?= url('settings/import') ?>" enctype="multipart/form-data">
      <?= csrfField() ?>
      <div class="field">
        <label for="imp-file">ملف JSON</label>
        <input type="file" id="imp-file" name="file" accept="application/json,.json" required>
      </div>
      <button class="btn gold mt-4" type="submit"><?= icon('check', 14) ?> طبّق الملف</button>
    </form>
  </div>
</div>
