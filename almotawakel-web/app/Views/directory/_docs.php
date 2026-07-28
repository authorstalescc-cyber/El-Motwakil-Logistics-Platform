<?php
/**
 * قسم «مستندات الطرف» في بروفايل الشركة/الوكيل (v56).
 *
 * جزء مشترك بين `directory/agent.php` و`directory/company.php` — ممنوع
 * ينتسخ في الاتنين وإلا يفترقوا مع أول تعديل (درس `permits/_hero.php`).
 *
 * المتغيّرات: $docs $docParty (['type','id','name']) $docState
 */
use App\Models\PartyDoc;

$docs      = $docs ?? [];
$docParty  = $docParty ?? ['type' => 'company', 'id' => 0, 'name' => ''];
$docState  = $docState ?? ['token' => '', 'old' => [], 'errors' => [], 'back' => ''];
$dOld      = $docState['old'] ?? [];
$dErr      = $docState['errors'] ?? [];
$dv        = fn(string $k, $def = '') => e((string) ($dOld[$k] ?? $def));

$groups = $docs ? [[
    'type'       => $docParty['type'],
    'type_label' => PartyDoc::partyLabel($docParty['type']),
    'name'       => $docParty['name'],
    'party_id'   => $docParty['id'],
    'docs'       => $docs,
]] : [];
?>
<div class="card" id="party-docs" style="margin-top:18px">
  <div class="section-head">
    <?= icon('folder', 17) ?> مستندات الطرف
    <?php if ($docs): ?><span class="pdoc-cnt">(<?= count($docs) ?>)</span><?php endif; ?>
  </div>

  <p class="pdoc-hint">
    البطاقة الضريبية والسجل التجاري وأي ورقة تخصّ الطرف ده. بترفعها هنا مرّة واحدة،
    وبتظهر <b>تلقائيًّا</b> في كل إذن يخصّه — وعلى موبايل المندوب يحمّلها ويطبعها.
  </p>

  <?= partial_str('party_docs', ['groups' => $groups, 'manage' => true, 'show_party' => false,
        'empty_note' => 'مفيش مستندات مرفوعة للطرف ده لسه.']) ?>

  <?php if (!empty($dErr['_form'])): ?>
    <div class="field-error" role="alert"><?= e((string) $dErr['_form']) ?> — اختار الملف تاني.</div>
  <?php endif; ?>

  <details class="pdoc-add" <?= $dErr ? 'open' : '' ?>>
    <summary><?= icon('plus', 14) ?> ارفع مستند جديد</summary>
    <form method="post" enctype="multipart/form-data"
          action="<?= url('directory/doc/add/' . e($docParty['type']) . '/' . (int) $docParty['id']) ?>">
      <?= csrfField() ?>
      <input type="hidden" name="_back" value="<?= e(current_app_path()) ?>">
      <div class="pdoc-grid">
        <label class="field"><span>نوع المستند</span>
          <select name="doc_kind" id="pdocKind">
            <?php foreach (PartyDoc::KINDS as $k => $meta): ?>
              <option value="<?= e($k) ?>" <?= ($dOld['doc_kind'] ?? '') === $k ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
            <?php endforeach; ?>
          </select></label>
        <label class="field"><span>الاسم الظاهر <small>(سيبه فاضي = اسم النوع)</small></span>
          <input type="text" name="title" id="pdocTitle" value="<?= $dv('title') ?>" placeholder="البطاقة الضريبية"></label>
        <label class="field"><span>رقم المستند</span>
          <input type="text" name="doc_no" value="<?= $dv('doc_no') ?>" placeholder="اختياري"></label>
        <label class="field"><span>تاريخ الإصدار</span>
          <input type="date" name="issue_date" value="<?= $dv('issue_date') ?>"></label>
        <label class="field"><span>تاريخ الانتهاء</span>
          <input type="date" name="expires_at" value="<?= $dv('expires_at') ?>"></label>
        <label class="field"><span>ملاحظة</span>
          <input type="text" name="note" value="<?= $dv('note') ?>" placeholder="اختياري"></label>
        <label class="field" style="grid-column:1/-1"><span>الملف (صورة أو PDF — حتى ١٢ ميجا)</span>
          <input type="file" name="doc_file" accept="image/*,application/pdf" required></label>
      </div>
      <label class="pdoc-check">
        <input type="checkbox" name="rep_visible" value="1" <?= empty($dOld) || !empty($dOld['rep_visible']) ? 'checked' : '' ?>>
        <span>يظهر للمناديب على الموبايل <small>(اقفلها لو المستند حسّاس)</small></span>
      </label>
      <div style="margin-top:12px">
        <button type="submit" class="btn gold"><?= icon('download', 14) ?> ارفع المستند</button>
      </div>
    </form>
  </details>
</div>
