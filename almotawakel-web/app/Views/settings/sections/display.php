<?php
/**
 * تفضيلات العرض — تفضيلاتي وشكل الشركة (v75) (docs/19 · المرحلة ٣).
 *
 * ⚠️ جزء بيتحمّل من `settings/index.php` — **صفر `<style>` محلي**،
 *    الكلاسات كلها في `app.css` تحت «مركز التحكّم».
 *
 * @var bool $prefReady @var array $prefCompany
 */
?>
  <div class="st-grid">
    <div class="card set-pad">
      <div class="section-head"><?= icon('columns', 17) ?> تفضيلاتي</div>
      <?php /* تحذير **وظيفي**: بيقول حالة ونتيجة، مش بيشرح الشاشة (قانون التصميم ⑤). */ ?>
      <p class="st-note">
        <?= icon('monitor', 15) ?> عندك <b><?= (int) $prefMine ?></b> تفضيلة محفوظة على حسابك —
        بتتبعك على أي جهاز تدخل منه.
      </p>
      <form method="post" action="<?= url('prefs/reset') ?>"
            data-confirm="ترجّع كل جداولك لشكل الشركة؟">
        <?= csrfField() ?>
        <button class="btn" type="submit"><?= icon('edit', 14) ?> رجّعني لشكل الشركة</button>
      </form>
    </div>

    <?php if ($canManageSecrets): ?>
    <div class="card set-pad">
      <div class="section-head"><?= icon('users', 17) ?> شكل الشركة</div>
      <p class="st-note">
        <?= icon('alert', 15) ?> <b><?= (int) $prefUsers ?></b> مستخدم ظابط جداوله بنفسه.
        المثبَّت بيتفرض عليهم كلهم — والمثبَّت مش بيتغيّر من عندهم حتى لو حاولوا.
      </p>
      <?php if ($prefCompany): ?>
        <div class="table-wrap"><table class="data">
          <thead><tr><th class="g-identity">المفتاح</th><th class="g-meta">الحالة</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($prefCompany as $pr): ?>
              <tr>
                <td><bdi class="dim"><?= e(mb_substr((string) $pr['pref_key'], 0, 60)) ?></bdi></td>
                <td><?= (int) $pr['is_locked'] === 1
                      ? '<span class="pill st-paid">مثبَّت</span>'
                      : '<span class="pill">افتراضي</span>' ?></td>
                <td class="end">
                  <form method="post" action="<?= url('settings/prefs/company/delete') ?>"
                        data-confirm="تشيل افتراضي الشركة للمفتاح ده؟">
                    <?= csrfField() ?>
                    <input type="hidden" name="pref_key" value="<?= e((string) $pr['pref_key']) ?>">
                    <button type="submit" class="btn ghost sm"><?= icon('trash', 14) ?></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php else: ?>
        <?php partial('empty', ['title' => 'مفيش شكل شركة متسجَّل', 'icon' => 'columns']); ?>
      <?php endif; ?>
      <p class="set-p">
        افتح أي جدول، ظبّطه زي ما تحب، وبعدين اضغط <b>«اعمله شكل الشركة»</b> من شريط أدوات الجدول.
      </p>
    </div>
    <?php endif; ?>
  </div>
