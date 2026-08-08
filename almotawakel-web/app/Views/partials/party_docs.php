<?php
/**
 * قائمة مستندات الأطراف — البطاقة الضريبية / السجل التجاري / … (v56).
 *
 * مكوّن واحد بيخدم **٣ شاشات**: بروفايل الشركة، بروفايل الوكيل، وشاشة الإذن.
 * لو اتفرّع لنسخ، أول تعديل هيخلّي الإذن يعرض حاجة والبروفايل يعرض غيرها.
 *
 * ⚠️ **عرض بحت — ولا رقم مالي.** أي إضافة هنا لازم تفضل وصفية.
 *
 * المتغيّرات:
 *   groups  array   [['type'=>,'type_label'=>,'name'=>,'party_id'=>,'docs'=>[…]], …]
 *   manage  ?bool   يعرض «تعديل/حذف» (شاشات المكتب بس)
 *   show_party ?bool يعرض اسم الطرف فوق كل مجموعة (شاشة الإذن)
 *   empty_note ?string
 */
use App\Models\PartyDoc;

$groups     = $groups ?? [];
$manage     = !empty($manage);
$showParty  = !empty($show_party);
$emptyNote  = (string) ($empty_note ?? '');

$totalDocs = 0;
foreach ($groups as $g) { $totalDocs += count($g['docs'] ?? []); }
?>
<?php if ($totalDocs === 0): ?>
  <?php if ($emptyNote !== ''): ?>
    <p class="pdoc-empty"><?= icon('folder', 15) ?> <?= e($emptyNote) ?></p>
  <?php endif; ?>
<?php else: ?>
  <?php foreach ($groups as $g): ?>
    <?php if (empty($g['docs'])) { continue; } ?>
    <?php if ($showParty): ?>
      <div class="pdoc-party">
        <?= icon($g['type'] === 'company' ? 'building' : 'user', 15) ?>
        <b><?= e((string) $g['name']) ?></b>
        <span><?= e((string) $g['type_label']) ?></span>
      </div>
    <?php endif; ?>

    <div class="pdoc-list">
      <?php foreach ($g['docs'] as $d): ?>
        <?php
          $docId  = (int) $d['id'];
          $exp    = PartyDoc::expiryState($d);
          $isPdf  = PartyDoc::isPdf($d);
          $size   = PartyDoc::fileSize($d);
        ?>
        <article class="pdoc-item<?= $exp['state'] === 'expired' ? ' is-expired' : '' ?>">
          <span class="pdoc-ic"><?= icon(PartyDoc::kindIcon($d['doc_kind'] ?? null), 18) ?></span>

          <div class="pdoc-main">
            <b class="pdoc-title"><?= e((string) $d['title']) ?></b>
            <div class="pdoc-meta">
              <span class="pdoc-kind"><?= e(PartyDoc::kindLabel($d['doc_kind'] ?? null)) ?></span>
              <?php if (!empty($d['doc_no'])): ?>
                <span>رقم: <bdi dir="ltr"><?= e((string) $d['doc_no']) ?></bdi></span>
              <?php endif; ?>
              <span class="pdoc-ext"><?= $isPdf ? 'PDF' : 'صورة' ?><?= $size > 0 ? ' · ' . e(sizef($size)) : '' ?></span>
              <?php if ($exp['state'] === 'expired'): ?>
                <span class="pdoc-flag bad"><?= icon('alert', 13) ?> <?= e($exp['label']) ?> (<?= dfmt($d['expires_at']) ?>)</span>
              <?php elseif ($exp['state'] === 'soon'): ?>
                <span class="pdoc-flag warn"><?= icon('clock', 13) ?> <?= e($exp['label']) ?></span>
              <?php elseif ($exp['state'] === 'ok'): ?>
                <span class="pdoc-flag ok"><?= icon('check', 13) ?> سارٍ حتى <?= dfmt($d['expires_at']) ?></span>
              <?php endif; ?>
              <?php if ($manage && (int) ($d['rep_visible'] ?? 0) !== 1): ?>
                <span class="pdoc-flag mute"><?= icon('x', 13) ?> مخفي عن المناديب</span>
              <?php endif; ?>
            </div>
            <?php if (!empty($d['note'])): ?><p class="pdoc-note"><?= e((string) $d['note']) ?></p><?php endif; ?>
          </div>

          <div class="pdoc-acts">
            <a class="btn sm ghost" href="<?= url('attachments/party-doc/' . $docId) ?>" target="_blank" rel="noopener">
              <?= icon('external', 14) ?> فتح</a>
            <a class="btn sm ghost" href="<?= url('attachments/party-doc/' . $docId . '?dl=1') ?>">
              <?= icon('download', 14) ?> تحميل</a>
            <a class="btn sm ghost" href="<?= url('attachments/party-doc/' . $docId . '/print') ?>" target="_blank" rel="noopener">
              <?= icon('receipt', 14) ?> طباعة</a>
            <?php if ($manage): ?>
              <button type="button" class="btn sm ghost js-pdoc-edit" data-doc="<?= $docId ?>">
                <?= icon('edit', 14) ?> تعديل</button>
              <form method="post" action="<?= url('directory/doc/' . $docId . '/delete') ?>" class="confirm-delete"
                    data-confirm="حذف «<?= e((string) $d['title']) ?>»؟ الملف هيتمسح خالص." data-danger="1">
                <?= csrfField() ?>
                <input type="hidden" name="_back" value="<?= e(current_app_path()) ?>">
                <button type="submit" class="btn sm red" title="حذف المستند" aria-label="حذف المستند"><?= icon('trash', 14) ?></button>
              </form>
            <?php endif; ?>
          </div>

          <?php if ($manage): ?>
            <form method="post" action="<?= url('directory/doc/' . $docId . '/update') ?>" class="pdoc-edit" id="pdoc-edit-<?= $docId ?>" hidden>
              <?= csrfField() ?>
              <input type="hidden" name="_back" value="<?= e(current_app_path()) ?>">
              <div class="pdoc-grid">
                <label class="field"><span>نوع المستند</span>
                  <select name="doc_kind">
                    <?php foreach (PartyDoc::KINDS as $k => $meta): ?>
                      <option value="<?= e($k) ?>" <?= ($d['doc_kind'] ?? '') === $k ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                    <?php endforeach; ?>
                  </select></label>
                <label class="field"><span>الاسم الظاهر</span>
                  <input type="text" name="title" value="<?= e((string) $d['title']) ?>"></label>
                <label class="field"><span>رقم المستند</span>
                  <input type="text" name="doc_no" value="<?= e((string) ($d['doc_no'] ?? '')) ?>"></label>
                <label class="field"><span>تاريخ الإصدار</span>
                  <input type="date" name="issue_date" value="<?= e((string) ($d['issue_date'] ?? '')) ?>"></label>
                <label class="field"><span>تاريخ الانتهاء</span>
                  <input type="date" name="expires_at" value="<?= e((string) ($d['expires_at'] ?? '')) ?>"></label>
                <label class="field"><span>ملاحظة</span>
                  <input type="text" name="note" value="<?= e((string) ($d['note'] ?? '')) ?>"></label>
              </div>
              <label class="pdoc-check">
                <input type="checkbox" name="rep_visible" value="1" <?= (int) ($d['rep_visible'] ?? 0) === 1 ? 'checked' : '' ?>>
                <span>يظهر للمناديب على الموبايل</span>
              </label>
              <div class="pdoc-edit-acts">
                <button type="submit" class="btn sm gold"><?= icon('check', 14) ?> حفظ</button>
                <button type="button" class="btn sm ghost js-pdoc-cancel" data-doc="<?= $docId ?>">إلغاء</button>
              </div>
            </form>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
