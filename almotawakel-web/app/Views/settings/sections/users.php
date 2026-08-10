<?php
/**
 * المستخدمون والصلاحيات (docs/19 · المرحلة ٣).
 *
 * ⚠️ جزء بيتحمّل من `settings/index.php` — **صفر `<style>` محلي**،
 *    الكلاسات كلها في `app.css` تحت «مركز التحكّم».
 *
 * @var array $users @var array $roleLabels
 *
 * ⚠️ **طول كلمة السر بيتقرا من الإعداد** (v79) — كان مكتوبًا `8` بالإيد في
 *    الشاشة و`Auth` في نفس الوقت، فأول ما المالك يغيّر الإعداد كان المتصفح
 *    هيقول رقمًا والخادم يرفض برقم تاني.
 */
$minPw = \App\Core\Auth::minPasswordLength();
?>
<?php if (($userState['errors']['_form'] ?? '') !== ''): ?>
  <div class="flash error" role="alert"><?= icon('alert',18) ?> <?= e($userState['errors']['_form']) ?> — البيانات اللي كتبتها زي ما هي (عدا كلمة السر).</div>
<?php endif; ?>
  <div class="st-grid">
    <div class="card set-pad">
      <div class="section-head"><?= icon('plus', 17) ?> إضافة مستخدم جديد</div>
      <form method="post" action="<?= url('settings/users') ?>" autocomplete="off">
        <?= csrfField() ?>
        <div class="field">
          <label>اسم الدخول (username)</label>
          <input type="text" name="username" value="<?= e($userState['old']['username'] ?? '') ?>" required autocomplete="off" placeholder="مثال: ahmed">
        </div>
        <div class="field">
          <label>الاسم الظاهر (اختياري)</label>
          <input type="text" name="display_name" value="<?= e($userState['old']['display_name'] ?? '') ?>" autocomplete="off" placeholder="مثال: أحمد محمد">
        </div>
        <div class="field">
          <label>كلمة السر</label>
          <input type="password" name="password" required autocomplete="new-password" minlength="<?= $minPw ?>" placeholder="<?= $minPw ?> أحرف على الأقل">
        </div>
        <div class="field"><label>الدور</label><select name="role" required>
          <?php foreach (($roleLabels ?? []) as $rk => $rl): ?><option value="<?= e($rk) ?>" <?= $rk === ($userState['old']['role'] ?? 'read_only') ? 'selected' : '' ?>><?= e($rl) ?></option><?php endforeach; ?>
        </select></div>
        <label class="free-login-option"><input type="checkbox" name="can_free_edit" value="1" <?= ($userState['old']['can_free_edit'] ?? '') !== '' ? 'checked' : '' ?>><span><b>صلاحية الدخول الحر</b><small>كتابة على الأيام المقفولة</small></span></label>
        <div class="mt-4"><button class="btn gold" type="submit"><?= icon('plus', 14) ?> إضافة المستخدم</button></div>
      </form>
    </div>

    <div class="card set-pad">
      <div class="section-head"><?= icon('users', 17) ?> المستخدمون الحاليون</div>
      <div class="table-wrap">
      <table class="data">
        <thead><tr><th>اسم الدخول</th><th>الاسم الظاهر</th><th>الدور</th><th>الدخول الحر</th><th></th></tr></thead>
        <tbody>
        <?php foreach (($users ?? []) as $u): $uid = (int)$u['id']; $isSelf = $uid === (int)($currentUid ?? 0); ?>
          <tr>
            <td><?= e($u['username']) ?><?php if ($isSelf): ?> <span class="dim">(أنت)</span><?php endif; ?></td>
            <td><?= e($u['display_name'] ?: '—') ?></td>
            <td><span class="pill"><?= e(($roleLabels ?? [])[$u['role'] ?? 'read_only'] ?? 'قراءة فقط') ?></span></td>
            <td><?= !empty($u['can_free_edit']) ? '<span class="pill st-paid">مسموح</span>' : '<span class="pill">عادي</span>' ?></td>
            <td class="end nowrap">
              <button type="button" class="btn ghost sm" data-edit-user="<?= $uid ?>" title="تعديل"><?= icon('edit', 14) ?> تعديل</button>
              <?php if (!$isSelf): ?>
                <form method="post" action="<?= url('settings/users/' . $uid . '/delete') ?>" data-confirm="حذف المستخدم «<?= e($u['username']) ?>»؟" >
                  <?= csrfField() ?>
                  <button type="submit" class="btn ghost sm" title="حذف"><?= icon('trash', 14) ?> حذف</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
          <tr class="user-edit-row" id="user-edit-<?= $uid ?>" hidden>
            <td colspan="5" class="bg-soft">
              <form method="post" action="<?= url('settings/users/' . $uid) ?>" autocomplete="off" class="set-row wide">
                <?= csrfField() ?>
                <div class="field flush f160">
                  <label>اسم الدخول</label>
                  <input type="text" name="username" value="<?= e($u['username']) ?>" required autocomplete="off">
                </div>
                <div class="field flush f160">
                  <label>الاسم الظاهر</label>
                  <input type="text" name="display_name" value="<?= e($u['display_name']) ?>" autocomplete="off">
                </div>
                <div class="field flush f160">
                  <label>كلمة سر جديدة (اختياري)</label>
                  <input type="password" name="password" autocomplete="new-password" minlength="<?= $minPw ?>" placeholder="اتركها فاضية لعدم التغيير">
                </div>
                <div class="field flush f140"><label>الدور</label><select name="role">
                  <?php foreach (($roleLabels ?? []) as $rk => $rl): ?><option value="<?= e($rk) ?>" <?= ($u['role']??'read_only')===$rk?'selected':'' ?>><?= e($rl) ?></option><?php endforeach; ?>
                </select></div>
                <label class="set-chk"><input type="checkbox" name="can_free_edit" value="1" <?= !empty($u['can_free_edit'])?'checked':'' ?>> صلاحية الدخول الحر</label>
                <div class="set-row">
                  <button type="submit" class="btn gold sm"><?= icon('download', 14) ?> حفظ</button>
                  <button type="button" class="btn ghost sm" data-cancel-user="<?= $uid ?>">إلغاء</button>
                </div>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>
