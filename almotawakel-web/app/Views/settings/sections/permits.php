<?php
/**
 * الأذونات والتايم لاين ومرشد الإيصالات (docs/19 · المرحلة ٣).
 *
 * ⚠️ جزء بيتحمّل من `settings/index.php` — **صفر `<style>` محلي**،
 *    الكلاسات كلها في `app.css` تحت «مركز التحكّم».
 *
 * @var array $permitCfg @var array $permitNums @var array $rcpRules
 */

/* ⚠️ `use` كان في ترويسة الشاشة القديمة. من غيره `Permit::DIR_EXPENSE` بتتحلّ
   لـ`App\Views\Permit` و**بترمي Class not found** وقت الرندر بس. */
use App\Models\Permit;
?>
  <div class="card set-pad">
    <div class="section-head"><?= icon('folder', 17) ?> إعدادات الأذونات والتايم لاين</div>
    <form method="post" action="<?= url('settings/permits') ?>">
      <?= csrfField() ?>
      <div class="st-grid set-mb-lg">
        <div class="field"><label>أقصى عدد حاويات للكشف/الضمان الواحد</label>
          <input type="number" step="1" min="1" name="inspection_batch" value="<?= e($permitNums['inspection_batch']) ?>"></div>
        <div class="field"><label>جهة الكشف (المستحق)</label>
          <input type="text" name="inspection_creditor" value="<?= e($permitNums['inspection_creditor']) ?>"></div>
        <div class="field"><label>جهة الضمان (المستحق)</label>
          <input type="text" name="guarantee_creditor" value="<?= e($permitNums['guarantee_creditor']) ?>"></div>
      </div>
      <div class="table-wrap"><table class="data wrap-normal">
        <thead><tr>
          <th class="g-identity w90">الترتيب</th>
          <th class="g-identity w60">الأيقونة</th>
          <th class="g-identity">اسم المرحلة</th>
          <th class="g-identity w120">النوع</th>
          <th class="g-identity w110">الإظهار</th>
        </tr></thead>
        <tbody>
          <?php
            $dirTxt = [Permit::DIR_EXPENSE=>'مصروف', Permit::DIR_REVENUE=>'محصّل', Permit::DIR_LINK=>'مرتبط', Permit::DIR_INFO=>'بيانات'];
            foreach ($permitCfg as $key => $st):
              $core = in_array($key, Permit::ALWAYS_ON, true);
          ?>
            <tr>
              <td><input type="number" name="stage_order[<?= e($key) ?>]" value="<?= (int)$st['order'] ?>" class="set-num"></td>
              <td class="set-lg"><?= $st['icon'] ?></td>
              <td><input name="stage_label[<?= e($key) ?>]" value="<?= e($st['label']) ?>" class="set-in"></td>
              <td><?= $dirTxt[$st['dir']] ?? '' ?></td>
              <td class="center">
                <?php if ($core): ?>
                  <span class="pill st-paid">أساسية</span>
                <?php else: ?>
                  <label class="pointer"><input type="checkbox" name="stage_enabled[<?= e($key) ?>]" <?= $st['enabled']?'checked':'' ?> class="set-cbx"> ظاهرة</label>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>

      <div class="section-head set-mt-lg"><?= icon('receipt', 17) ?> قواعد مرشد الإيصالات</div>
      <p class="set-sub">
        استرشادي بحت — بيقول «الإذن ده المتوقّع فيه كذا» بس، مايلزمش بأي حاجة. اكتب توكيل في كل سطر.
      </p>
      <?php /* الـ`style=` inline اتشال: `.field textarea` بقت معرَّفة في
               `app.css` (كانت ناقصة من القاعدة رغم إن `partials/field.php`
               بيرندر textarea) — فالشكل بقى واحد وبيشتغل في الوضع الليلي. */ ?>
      <div class="st-grid">
        <div class="field"><label>توكيلات التأمين (يُغني عن إيصال التخصيص للصادر)</label>
          <textarea name="insurance_agencies" rows="4" placeholder="ميرسك&#10;هابج لويد"><?= e($rcpRules['insurance'] ?? '') ?></textarea></div>
        <div class="field"><label>توكيلات تعتيق الحاويات (صادر)</label>
          <textarea name="ateeq_agencies" rows="4" placeholder="أركاس"><?= e($rcpRules['ateeq'] ?? '') ?></textarea></div>
      </div>

      <button class="btn gold mt-4" type="submit"><?= icon('download', 14) ?> حفظ إعدادات التايم لاين</button>
    </form>
  </div>
