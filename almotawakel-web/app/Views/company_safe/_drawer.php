<?php
/**
 * درج تسجيل حركة خزنة الشركة (v68) — لوح بيدخل من جنب الشاشة.
 *
 * ═══ ليه درج مش نموذج مضمَّن (بلاغ المالك: «الإدخال مش احترافي») ═══
 * النموذج المضمَّن كان بياخد ثلث الصفحة **دايمًا** حتى وانت بتقرا بس، وكان
 * بيعرض خانات كل الأنواع مع بعض فالمالك بيبص على ٩ خانات عشان يسجّل مصروف
 * محتاج ٣. الدرج بيظهر **لما تطلبه** وبيوري **خانات النوع المختار وبس**.
 *
 * ⚠️⚠️ **الحالة في الرابط لا في الجافاسكريبت** (`?new=1` / `?edit={id}`):
 *    • الحفظ لو فشل بيرجّعك والدرج **مفتوح وفيه اللي كتبته**.
 *    • الرجوع بالمتصفح بيقفل الدرج، والرابط قابل للمشاركة.
 *    • ومن غير جافاسكريبت خالص الدرج بيترندر كقسم عادي والفورم شغّال بالكامل —
 *      الجافاسكريبت بيضيف الفتح/القفل الفوري والمعاينة الحيّة **وبس**.
 *
 * ⚠️ **معاينة الأثر (`data-cs-preview`) عرض لا حساب.** الرقم الحقيقي بيتحسب في
 *    `CompanySafe::create()` على الخادم؛ ده تطمين بصري قبل الضغط. لو الجافاسكريبت
 *    وقع بيفضل مكتوب فيه الرصيد الحالي وبس — **مايكدبش أبدًا**.
 *
 * متغيّرات: $st $editing $editRow $val $day $labels $sources $reps $cats
 *           $errors $balance $lastClosed
 */
use App\Models\CompanySafe;

/** ⚠️ الوصف تحت اسم النوع بيقول **الأثر** لا شرحًا تعليميًّا — ≤٥ كلمات. */
$kindHint = [
    'deposit'     => 'الشركة تزيد',
    'to_office'   => 'الشركة تنقص · الدرج يزيد',
    'from_office' => 'الدرج ينقص · الشركة تزيد',
    'expense'     => 'الشركة تنقص',
    'to_visa'     => 'الشركة تنقص · الكارت يزيد',
    'from_visa'   => 'الكارت ينقص · الشركة تزيد',
    'to_rep'      => 'الشركة تنقص · المندوب يزيد',
];
$selKind = (string) $val('kind', 'to_office');
?>
<div class="cs-drawer<?= $drawer ? ' open' : '' ?>" id="csDrawer" <?= $drawer ? '' : 'hidden' ?>
     role="dialog" aria-modal="true" aria-labelledby="csDrawerTitle">
  <a class="cs-drawer-scrim" href="<?= url('company-safe') ?>?tab=<?= e($tab) ?>" aria-label="إغلاق"></a>

  <div class="cs-drawer-card">
    <form class="js-dirty-form" method="post" enctype="multipart/form-data" id="csForm"
          action="<?= url($editing ? 'company-safe/' . (int) $editRow['id'] . '/update' : 'company-safe') ?>">
      <?= csrfField() ?>
      <?= formStateFields($st['token'], $st['back']) ?>

      <div class="cs-drawer-head">
        <h2 id="csDrawerTitle"><?= $editing ? 'تعديل حركة' : 'حركة جديدة' ?></h2>
        <a class="cs-drawer-x" href="<?= url('company-safe') ?>?tab=<?= e($tab) ?>" aria-label="إغلاق">
          <?= icon('x', 16) ?>
        </a>
      </div>

      <div class="cs-drawer-body">
        <?= formErrorSummary($errors) ?>

        <?php /* ═══ ① النوع — كروت لا قائمة منسدلة ═══
                 ⚠️ في التعديل النوع **مقفول**: تغييره معناه نقل فلوس من محفظة
                    لمحفظة، وده حذف + إضافة مش تعديل (الموديل بيرفضه صراحةً). */ ?>
        <?php if ($editing): ?>
          <div class="cs-picked">
            <span class="ic"><?= icon(CompanySafe::icon((string) $editRow['kind']), 18) ?></span>
            <span class="t"><?= e($labels[$editRow['kind']] ?? $editRow['kind']) ?></span>
            <span class="h"><?= e($kindHint[$editRow['kind']] ?? '') ?></span>
          </div>
          <input type="hidden" name="kind" value="<?= e((string) $editRow['kind']) ?>">
        <?php else: ?>
          <fieldset class="cs-types">
            <legend>نوع الحركة</legend>
            <div class="cs-types-grid">
            <?php foreach (CompanySafe::MANUAL_KINDS as $k): ?>
              <?php /* ⚠️ **بلا `required`**: واحد منهم `checked` دايمًا، و`app.js`
                       بيحقن نجمة «مطلوب» على كل حقل `required` — فكانت بتطلع
                       نجوم حمرا على الكروت وتشوّه الشبكة بلا فايدة. */ ?>
              <label class="cs-type<?= CompanySafe::isIn($k) ? ' in' : ' out' ?>">
                <input type="radio" name="kind" value="<?= e($k) ?>" <?= $selKind === $k ? 'checked' : '' ?>>
                <span class="ic"><?= icon(CompanySafe::icon($k), 18) ?></span>
                <span class="t"><?= e($labels[$k]) ?></span>
                <span class="h"><?= e($kindHint[$k] ?? '') ?></span>
              </label>
            <?php endforeach; ?>
            </div>
          </fieldset>
          <?= fieldError('kind', $errors) ?>
        <?php endif; ?>

        <?php /* ═══ ② المبلغ — بطل الشاشة ═══ */ ?>
        <div class="cs-amt-field">
          <label for="csAmount">المبلغ</label>
          <div class="cs-amt-box<?= isset($errors['amount']) ? ' bad' : '' ?>">
            <input type="number" step="0.01" min="0.01" name="amount" id="csAmount" inputmode="decimal"
                   value="<?= e((string) $val('amount')) ?>" placeholder="0" required autocomplete="off"
                   data-cs-amount-input>
            <span class="cur"><?= e(App\Core\App::config('currency', 'ج.م')) ?></span>
          </div>
          <?= fieldError('amount', $errors) ?>
          <div class="cs-quick">
            <?php foreach ([1000, 5000, 10000, 20000, 50000, 100000] as $q): ?>
              <button type="button" class="cs-chip" data-cs-amount="<?= $q ?>"><?= intf($q) ?></button>
            <?php endforeach; ?>
          </div>
        </div>

        <?php /* ═══ ③ الخانات المشروطة بالنوع ═══
                 ⚠️ `data-cs-for` بيقول النوع اللي الخانة بتخصّه. **الإخفاء من
                    الجافاسكريبت لا من الخادم** (نفس قاعدة خانات سيارات المكتب):
                    لو السكربت وقع المالك شايف كل الخانات ويقدر يسجّل، والخادم
                    هو اللي بيفرض القواعد في `CompanySafe::normalise`.
                 ⚠️ والإخفاء **ما بيمسحش** أي قيمة متكتبة (بعكس `data-dep`) —
                    تبديل النوع بالغلط كان هيضيّع اللي اتكتب. */ ?>
        <div class="cs-field" data-cs-for="expense"
             <?= $editing && $editRow['kind'] !== 'expense' ? 'hidden' : '' ?>>
          <label for="csCat">فئة المصروف</label>
          <select name="category" id="csCat">
            <?php $cSel = (string) $val('category', 'other'); ?>
            <?php foreach ($cats as $ck => $cl): ?>
              <option value="<?= e($ck) ?>" <?= $cSel === $ck ? 'selected' : '' ?>><?= e($cl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="cs-field" data-cs-for="to_visa from_visa"
             <?= $editing && !in_array($editRow['kind'], CompanySafe::VISA_KINDS, true) ? 'hidden' : '' ?>>
          <label>كارت الفيزا</label>
          <?php /* ⚠️ `visa_card_select()` المشتركة — المصدر الوحيد لأي قايمة
                   كروت في النظام. نسخة محلية كانت هتتفرّع مع أول تعديل. */ ?>
          <?= visa_card_select((int) $val('visa_card_id', 0)) ?>
          <?= fieldError('visa_card_id', $errors) ?>
        </div>

        <div class="cs-field" data-cs-for="to_rep"
             <?= $editing && $editRow['kind'] !== 'to_rep' ? 'hidden' : '' ?>>
          <label for="csRep">المندوب</label>
          <select name="rep_id" id="csRep">
            <option value="">— اختر المندوب —</option>
            <?php $rSel = (int) $val('rep_id', 0); ?>
            <?php foreach ($reps as $rid => $rname): ?>
              <option value="<?= (int) $rid ?>" <?= $rSel === (int) $rid ? 'selected' : '' ?>><?= e((string) $rname) ?></option>
            <?php endforeach; ?>
          </select>
          <?= fieldError('rep_id', $errors) ?>
        </div>

        <?php /* ═══ ④ الخانات المشتركة ═══ */ ?>
        <div class="cs-grid2">
          <div class="cs-field">
            <label for="csDate">التاريخ</label>
            <input type="date" name="entry_date" id="csDate" value="<?= e($val('entry_date', $day)) ?>"
                   <?= fieldErrorAttrs('entry_date', $errors) ?>>
            <?= fieldError('entry_date', $errors) ?>
          </div>
          <div class="cs-field">
            <label for="csSource">الجهة</label>
            <input type="text" name="source" id="csSource" maxlength="180" list="csSources"
                   value="<?= e((string) $val('source')) ?>" autocomplete="off">
            <datalist id="csSources">
              <?php foreach ($sources as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?>
            </datalist>
          </div>
        </div>

        <div class="cs-field">
          <label for="csNote">ملاحظة</label>
          <input type="text" name="note" id="csNote" maxlength="255" value="<?= e((string) $val('note')) ?>">
        </div>

        <div class="cs-field">
          <label for="csReceipt">إيصال — صورة أو PDF</label>
          <input type="file" name="receipt" id="csReceipt" accept="image/*,application/pdf" capture="environment">
          <?= fieldError('receipt', $errors) ?>
          <?php if ($editing && !empty($editRow['attachment'])): ?>
            <a class="cs-att" target="_blank" rel="noopener"
               href="<?= url('attachments/company-safe/' . (int) $editRow['id']) ?>">
              <?= icon('eye', 13) ?> الإيصال الحالي
            </a>
          <?php endif; ?>
        </div>

        <?php if ($lastClosed): ?>
          <div class="cs-locked"><?= icon('lock', 13) ?> متقفل حتى <?= dfmt($lastClosed) ?></div>
        <?php endif; ?>
      </div>

      <?php /* ═══ ⑤ التذييل — معاينة الأثر + الحفظ ═══
               ⚠️ **العرض بس**: `data-cs-balance` بيحمل الرصيد الحالي والجافاسكريبت
                  بيحسب «بعد» منه. الرقم المعتمد بيتحسب على الخادم. من غير
                  جافاسكريبت بيفضل مكتوب الرصيد الحالي وبس — مايكدبش. */ ?>
      <div class="cs-drawer-foot">
        <div class="cs-effect" data-cs-preview data-cs-balance="<?= e((string) round((float) $balance, 2)) ?>">
          <span class="l">رصيد خزنة الشركة</span>
          <b class="now"><?= money0($balance, false) ?></b>
          <span class="arw" hidden>←</span>
          <b class="after" hidden></b>
        </div>
        <div class="cs-drawer-acts">
          <a class="btn ghost" href="<?= url('company-safe') ?>?tab=<?= e($tab) ?>">إلغاء</a>
          <button class="btn gold" type="submit" data-primary-submit>
            <?= icon('check', 15) ?> <?= $editing ? 'احفظ' : 'سجّل' ?>
          </button>
        </div>
      </div>
    </form>
  </div>
</div>
