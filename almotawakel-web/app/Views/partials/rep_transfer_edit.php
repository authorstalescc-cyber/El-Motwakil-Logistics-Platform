<?php
/**
 * درج تعديل تحويل عهدة بين مندوبين (v46) — **المصدر الوحيد** لماركب التعديل.
 *
 * بيتنادى من لوحين: «تحويلات حوّلها لزملاء» (كارت المُرسِل) و«تحويلات وصلته من
 * زملاء» (كارت المُستقبِل) — الاتنين بيدّوا **نفس التحكم الكامل**: المُرسِل ·
 * المُستقبِل · المبلغ · التاريخ · الملاحظة · الإلغاء.
 *
 * ⚠️ **ليه بارشيال مش نسختين:** لوح الوارد كان فيه زر حذف بس، فالمالك اللي فتح
 *    كارت المُستقبِل مكانش قدامه غير الإلغاء. نسخ الماركب كان هيخلّي أي تعديل
 *    جاي (حقل جديد، تغيير حارس) يتعمل مرتين — ونسيان واحدة = شاشة بتقول حاجة
 *    والتانية بتقول غيرها.
 *
 * ⚠️ الدرج `<details>` بلا أي جافاسكريبت — الكارت بيترندر في تبويبات وروابط
 *    حقيقية، فأي خطّاف JS هنا نقطة فشل صامت.
 *
 * ⚠️ فورم الإلغاء **برّه فورم التعديل**: فورم جوّه فورم ماركب غير صالح والمتصفح
 *    بيفكّه بصمت فالزرار يبعت على المسار الغلط.
 *
 * @var array  $t          صف التحويل (id, from_rep_id, to_rep_id, amount, transfer_date, note)
 * ⚠️ **المكوّن ما بيلمسش القاعدة ولا أي موديل مالي** (قاعدة `verify_components`):
 *    قايمة المناديب بتوصله جاهزة من الكنترولر — **ولازم تكون شاملة أي مندوب
 *    موقوف مسجَّل عليه تحويل**، لأن غيابه من `<select>` بيخلّي المتصفح يختار أول
 *    اسم تاني بالسكوت، فمجرّد حفظ الملاحظة بينقل الفلوس لمندوب مالوش دعوة.
 *    (`RepCustody::transferParties()` هي اللي بتضمن ده.)
 *
 * @var array  $reps       [id => اسم] كل مندوب ينفع يتحطّ في الخانتين
 * @var string $back       مسار الرجوع (بيتحطّ في `_back`)
 * @var string $anchor     مرساة اللوح اللي الطلب جه منه
 */
use App\Core\FormState;   // ⚠️ الـ`use` بتاع الشاشة الأم ما بيوصلش لملف البارشيال

$tid    = (int) $t['id'];
$tFrom  = (int) $t['from_rep_id'];
$tTo    = (int) $t['to_rep_id'];
$backTo = $back . ($anchor !== '' ? '#' . $anchor : '');
?>
<details class="rc-trf-edit" id="trf-<?= $tid ?>">
  <summary class="rc-trf-sum">تعديل التحويل</summary>
  <form method="post" action="<?= url('rep-custody/transfer/' . $tid . '/update') ?>" class="rc-trf-form">
    <?= csrfField() ?>
    <?= formStateFields(FormState::token(), $backTo) ?>
    <label class="field">
      <span>حوّل من عهدة</span>
      <select name="from_rep_id">
        <?php foreach ($reps as $rid => $rname): ?>
          <option value="<?= (int) $rid ?>" <?= (int) $rid === $tFrom ? 'selected' : '' ?>>
            <?= e((string) $rname) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field">
      <span>وصلت لعهدة</span>
      <select name="to_rep_id">
        <?php foreach ($reps as $rid => $rname): ?>
          <option value="<?= (int) $rid ?>" <?= (int) $rid === $tTo ? 'selected' : '' ?>>
            <?= e((string) $rname) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field">
      <span>المبلغ</span>
      <input data-money type="number" step="0.01" min="0" name="amount"
             value="<?= e((string) (float) $t['amount']) ?>">
    </label>
    <?php /* ⚠️ **يومان مش يوم واحد** (v94): الفلوس ممكن تخرج من عهدة المُرسِل يوم
             وتدخل عهدة المُستقبِل يوم بعده. اليوم الواحد كان بيجبر المالك يغلّط
             في طرف عشان يظبط التاني — الرصيد التراكمي يفضل صح واليوميّة تبوظ. */ ?>
    <label class="field">
      <span>خرجت من عهدته يوم</span>
      <input type="date" name="transfer_date" value="<?= e((string) $t['transfer_date']) ?>">
    </label>
    <label class="field">
      <span>دخلت عهدته يوم</span>
      <input type="date" name="to_date"
             value="<?= e((string) ($t['to_date'] ?? $t['transfer_date'])) ?>">
    </label>
    <label class="field">
      <span>ملاحظة</span>
      <input type="text" name="note" maxlength="255" value="<?= e((string) ($t['note'] ?? '')) ?>">
    </label>
    <div class="rc-trf-acts">
      <button class="btn sm" type="submit"><?= icon('check', 14) ?> احفظ</button>
    </div>
  </form>
  <form method="post" action="<?= url('rep-custody/transfer/' . $tid . '/delete') ?>"
        class="confirm-delete rc-trf-del" data-confirm="إلغاء التحويل ده؟ هيرجع لعهدة المُرسِل.">
    <?= csrfField() ?>
    <?= formStateFields(FormState::token(), $backTo) ?>
    <button class="btn ghost sm danger" type="submit"><?= icon('trash', 14) ?> ألغي التحويل</button>
  </form>
</details>
