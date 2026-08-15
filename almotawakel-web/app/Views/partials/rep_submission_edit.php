<?php
/**
 * درج تعديل بند مندوب (v95) — **المصدر الوحيد** لماركب التعديل.
 *
 * بيتنادى من مكانين في صندوق المراجعة:
 *   • تبويب «مستني»  → `$approved = false` — بيحفظ أو يحفظ ويعتمد.
 *   • تبويب «معتمد»  → `$approved = true`  — بيحفظ بس، والتعديل بيتنقل على
 *     البند الفعلي في الإذن/المصروفات وعلى عهدة المندوب مع بعض.
 *
 * ⚠️ **ليه بارشيال مش نسختين:** الدرج فيه ١٢ حقل كل واحد فيهم له قاعدة
 *    (النثرية مالهاش «فعلي للعميل»، والسلفة بتلغي الفيزا، و«غير مدفوع» متنافي
 *    مع الفيزا…). نسخ الماركب كان معناه إن أي حقل جديد يتعمل مرتين — ونسيان
 *    واحدة = تبويب بيقبل حاجة والتاني بيقبل غيرها على **نفس البند**.
 *
 * ⚠️ **كل الحقول مربوطة بفورمها بـ`form="{$eid}f"`** وهي جوّه فورم الصفحة (أو
 *    جوّه `<table>` في تبويب «معتمد»). سمة `form` بتنقل ملكية الحقل للفورم
 *    المذكور، فأسماء زي `entry_date`/`note`/`qty` ما بتتصادمش مع حقول الإجراء
 *    الجماعي. لو شيلت السمة دي، التعديل الفردي هيبعت قيمه مع الاعتماد الجماعي
 *    ويبوّظ الاتنين.
 *
 * ⚠️ `approve_now` **حقل مخفي بيتملّى بالـJS** — مش `name/value` على الزرار.
 *    الزرار المربوط بفورم بيبعت قيمته **حتى لو مدوستش عليه** (متحقَّق في v37).
 *
 * ⚠️⚠️ **وضع اللوحة (`$panel`) مش رفاهية — الدرج جوّه خلية جدول ديزاينه بيتكسر.**
 *    `.ib-grid` شبكة `auto-fit` بـ`minmax(170px,1fr)`، وعرض خلية الجدول
 *    بيتحدّد بخوارزمية الجدول (shrink-to-fit) **مش بعرض الصفحة** — فالشبكة
 *    بتتحسب على عرض غير محدَّد، كل الحقول بتقع في سطر واحد وبتطلّع برّه حافة
 *    الجدول، والعناوين بتقف جنب خاناتها بدل ما تكون فوقها. متحقَّق بلقطة فعلية.
 *    عشان كده تبويب «معتمد» بيرندر الدرج **جوّه لوحة برّه الجدول** (`$panel`)،
 *    وتبويب «مستني» بيرنده inline لأنه أصلاً جوّه كارت مش جوّه جدول.
 *
 * @var array  $r            صف البند (من `RepInbox::listing`/`find`)
 * @var string $eid          بادئة معرّفات الدرج؛ الفورم اسمه "{$eid}f"
 * @var bool   $approved     البند معتمد؟ (بيغيّر أزرار الحفظ وبس)
 * @var bool   $panel        الدرج جوّه لوحة منبثقة؟ (الغلاف واللي بيتطوى بيتغيّروا)
 * @var bool   $editFailed   محاولة سابقة فشلت على البند ده؟
 * @var array  $inboxOld     القيم المرتجعة من المحاولة الفاشلة
 * @var array  $inboxErrors  أخطاء الحقول
 * @var array  $movs         حركات الإذن (فاضية للنثرية)
 * @var string $today @var string $yesterday @var string $workDate
 */
use App\Models\RepMiscKind;
use App\Models\RepPortal;   // ⚠️ الـ`use` بتاع الشاشة الأم ما بيوصلش هنا

$approved   = $approved ?? false;
$panel      = $panel ?? false;
$editFailed = $editFailed ?? false;
$inboxOld   = $inboxOld ?? [];
$inboxErrors = $inboxErrors ?? [];
$movs       = $movs ?? [];

$isMisc    = RepPortal::isMisc((string) $r['kind']);
$hasActual = $r['actual_unit'] !== null && $r['actual_unit'] !== '';
?>
<?php if ($panel): ?>
  <?php /* اللوحة هي اللي بتتطوى — والدرج جوّاها دايمًا مفتوح. */ ?>
  <div class="ib-panel ib-edit-panel" id="<?= $eid ?>" <?= $editFailed ? '' : 'hidden' ?>
       role="dialog" aria-modal="true" aria-labelledby="<?= $eid ?>t">
    <div class="ib-panel-box ib-edit-box">
      <div class="ib-edit-head">
        <h3 id="<?= $eid ?>t"><?= icon('edit', 16) ?> تعديل بند معتمد</h3>
        <button type="button" class="ib-btn" title="اقفل" data-ib-close-edit><?= icon('x', 14) ?></button>
      </div>
<?php endif; ?>
<div class="ib-edit"<?= $panel ? '' : ' id="' . $eid . '"' ?> <?= $panel || $editFailed ? '' : 'hidden' ?>>
  <?php if ($editFailed): ?><?= formErrorSummary($inboxErrors) ?><?php endif; ?>

  <?php if ($approved): ?>
    <?php /* تحذير وظيفي — البند ده ليه أثر مالي قايم، والمالك لازم يعرف
             التعديل بيمشي فين قبل ما يكتب. */ ?>
    <p class="ib-warn"><?= icon('alert', 14) ?> معتمد — التعديل هيتنقل على الإذن والخزنة وعهدة المندوب.</p>
  <?php endif; ?>

  <div class="ib-edit-sec">
    <h4>البند</h4>
    <div class="ib-grid">
      <label class="wide">البيان
        <input type="text" name="note" form="<?= $eid ?>f" maxlength="255" required
               value="<?= e($editFailed ? ($inboxOld['note'] ?? '') : $r['note']) ?>"
               <?= $editFailed ? fieldErrorAttrs('note', $inboxErrors) : '' ?>><?= $editFailed ? fieldError('note', $inboxErrors) : '' ?></label>
      <?php if ($isMisc): ?>
        <?php /* النثرية ما تتحوّلش لبند على إذن (الخادم بيرفضها كمان):
                 مالهاش إذن ولا حركة، فالقلب كان هيسيب بند ناقص. */ ?>
        <input type="hidden" name="kind" form="<?= $eid ?>f" value="misc">
        <?php /* v71 — المالك يصحّح وسم المندوب: الوسم ده هيتحاسب عليه في الراتب. */ ?>
        <label>نوع النثرية
          <?php $editMk = $editFailed ? (string) ($inboxOld['misc_kind'] ?? '') : (string) ($r['misc_kind'] ?? ''); ?>
          <select name="misc_kind" form="<?= $eid ?>f" required>
            <option value="">— اختر النوع —</option>
            <?php foreach (RepMiscKind::all() as $mkKey => $mkDef): ?>
              <option value="<?= e($mkKey) ?>" <?= $editMk === (string) $mkKey ? 'selected' : '' ?>>
                <?= e($mkDef['label']) ?> — <?= e($mkDef['sub']) ?>
              </option>
            <?php endforeach; ?>
          </select></label>
      <?php else: ?>
        <label>النوع
          <?php $editKind = $editFailed ? ($inboxOld['kind'] ?? '') : $r['kind']; ?>
          <select name="kind" form="<?= $eid ?>f">
            <option value="receipt" <?= $editKind === 'receipt' ? 'selected' : '' ?>>إيصال</option>
            <option value="expense" <?= $editKind === 'expense' ? 'selected' : '' ?>>مصروف</option>
          </select></label>
      <?php endif; ?>
      <label>رقم الإيصال
        <input type="text" name="ref_no" form="<?= $eid ?>f" maxlength="120"
               value="<?= e($editFailed ? ($inboxOld['ref_no'] ?? '') : ($r['ref_no'] ?? '')) ?>"></label>
      <?php if (count($movs) > 1): ?>
        <label>الحركة
          <select name="movement_id" form="<?= $eid ?>f">
            <?php $curMov = (int) ($editFailed ? ($inboxOld['movement_id'] ?? 0) : $r['movement_id']); ?>
            <?php foreach ($movs as $mv): ?>
              <option value="<?= (int) $mv['id'] ?>" <?= (int) $mv['id'] === $curMov ? 'selected' : '' ?>>
                الحركة <?= (int) $mv['seq'] ?><?= $mv['note'] ? ' — ' . e($mv['note']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select></label>
      <?php elseif ($movs): ?>
        <input type="hidden" name="movement_id" form="<?= $eid ?>f" value="<?= (int) $movs[0]['id'] ?>">
      <?php endif; ?>
    </div>
  </div>

  <div class="ib-edit-sec">
    <h4>الفلوس</h4>
    <div class="ib-grid">
      <label>الكمية
        <input type="number" name="qty" form="<?= $eid ?>f" min="1" max="10000"
               value="<?= e($editFailed ? ($inboxOld['qty'] ?? '') : (string) (int) $r['qty']) ?>"
               <?= $editFailed ? fieldErrorAttrs('qty', $inboxErrors) : '' ?>><?= $editFailed ? fieldError('qty', $inboxErrors) : '' ?></label>
      <label>اللي دفعه المندوب (للوحدة)
        <input data-money type="number" step="0.01" min="0.01" name="unit_amount" form="<?= $eid ?>f"
               value="<?= e($editFailed ? ($inboxOld['unit_amount'] ?? '') : (string) round((float) $r['unit_amount'], 2)) ?>"
               <?= $editFailed ? fieldErrorAttrs('unit_amount', $inboxErrors) : '' ?>><?= $editFailed ? fieldError('unit_amount', $inboxErrors) : '' ?></label>
      <?php if (!$isMisc): ?>
        <?php /* مفيش «فعلي للعميل» للنثرية — مفيش عميل يتحاسب أصلاً (v39) */ ?>
        <label class="hi">الفعلي للعميل (للوحدة)
          <input data-money type="number" step="0.01" min="0" name="actual_unit" form="<?= $eid ?>f"
                 placeholder="سيبه فاضي وحطه بعدين"
                 value="<?= e($editFailed ? ($inboxOld['actual_unit'] ?? '') : ($hasActual ? (string) round((float) $r['actual_unit'], 2) : '')) ?>"
                 <?= $editFailed ? fieldErrorAttrs('actual_unit', $inboxErrors) : '' ?>><?= $editFailed ? fieldError('actual_unit', $inboxErrors) : '' ?></label>
      <?php endif; ?>
      <?php $editVisa = $editFailed ? !empty($inboxOld['is_visa']) : !empty($r['is_visa']); ?>
      <label class="ib-visa"><?= icon('credit-card', 14) ?> مدفوع بالفيزا
        <span class="ib-visa-row">
          <input type="checkbox" name="is_visa" form="<?= $eid ?>f" value="1" <?= $editVisa ? 'checked' : '' ?>>
          <?= visa_card_select(
                $editFailed ? (int) ($inboxOld['visa_card_id'] ?? 0) : (int) ($r['visa_card_id'] ?? 0),
                'visa_card_id', '', '', $eid . 'f'
              ) ?>
        </span>
      </label>
      <?php /* v57 — «غير مدفوع»: ⚠️ للبنود على الإذن بس (النثرية بتتدفع من عهدته فعلاً) */ ?>
      <?php if (!$isMisc): ?>
        <?php $editUnpaid = $editFailed ? !empty($inboxOld['unpaid']) : !empty($r['unpaid']); ?>
        <label class="ib-visa"><?= icon('info', 14) ?> غير مدفوع من عهدته
          <span class="ib-visa-row">
            <input type="checkbox" name="unpaid" form="<?= $eid ?>f" value="1" <?= $editUnpaid ? 'checked' : '' ?>>
            <span class="hint">اتحاسب من برّه — مش هيتخصم من عهدته ولا من الخزنة.</span>
          </span>
        </label>
      <?php endif; ?>
    </div>
  </div>

  <div class="ib-edit-sec">
    <h4>التاريخ</h4>
    <div class="ib-date-row">
      <input type="date" name="entry_date" form="<?= $eid ?>f" required
             id="<?= $eid ?>date" class="js-ib-edit-date"
             value="<?= e($editFailed ? ($inboxOld['entry_date'] ?? '') : $r['entry_date']) ?>"
             <?= $editFailed ? fieldErrorAttrs('entry_date', $inboxErrors) : '' ?>>
      <button type="button" class="btn ghost sm" onclick="ibSetDate('<?= $eid ?>date','<?= e($today) ?>')">النهاردة</button>
      <button type="button" class="btn ghost sm" onclick="ibSetDate('<?= $eid ?>date','<?= e($yesterday) ?>')">إمبارح</button>
      <?php if ($workDate !== $today): ?>
        <button type="button" class="btn ghost sm" onclick="ibSetDate('<?= $eid ?>date','<?= e($workDate) ?>')">يوم الشغل</button>
      <?php endif; ?>
      <span class="ib-date-note" data-for="<?= $eid ?>date"></span>
    </div>
    <?= $editFailed ? fieldError('entry_date', $inboxErrors) : '' ?>
  </div>

  <?php if ($approved): ?>
    <div class="ib-edit-acts">
      <button class="btn gold" type="submit" form="<?= $eid ?>f"><?= icon('download', 14) ?> احفظ التعديل</button>
      <button class="btn ghost" type="button" <?= $panel ? 'data-ib-close-edit' : 'onclick="ibToggle(\'' . $eid . '\')"' ?>>إلغاء</button>
    </div>
  <?php else: ?>
    <input type="hidden" name="approve_now" form="<?= $eid ?>f" id="<?= $eid ?>ap"
           value="<?= $editFailed ? e($inboxOld['approve_now'] ?? '') : '' ?>">
    <div class="ib-edit-acts">
      <button class="btn" type="submit" form="<?= $eid ?>f"
              onclick="document.getElementById('<?= $eid ?>ap').value=''"><?= icon('download', 14) ?> احفظ بس</button>
      <button class="btn gold" type="submit" form="<?= $eid ?>f"
              onclick="document.getElementById('<?= $eid ?>ap').value='1'"><?= icon('download', 14) ?> احفظ واعتمد</button>
      <button class="btn ghost" type="button" onclick="ibToggle('<?= $eid ?>')">إلغاء</button>
    </div>
  <?php endif; ?>
</div>
<?php if ($panel): ?>
    </div>
  </div>
<?php endif; ?>
