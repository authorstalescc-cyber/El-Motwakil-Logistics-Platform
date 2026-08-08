<?php
/**
 * لوح «مستندات الأطراف» في شاشة الإذن (v56) — البطاقة الضريبية / السجل التجاري.
 *
 * بيتضمّ من `permits/show.php` **مرّة واحدة للوضعين** (عرض وتعديل)، فما
 * ينفعش يتنسخ في `_body_view` و`_body_manage` (درس `_hero.php`).
 *
 * ⚠️ عرض بحت — بيقرا من `PartyDoc::forPermit()` وما بيكتبش حاجة، وما فيهوش
 *    أي رقم مالي. اللوح كله بيختفي لو مفيش مستندات (ما نزحمش الشاشة بفراغ).
 *
 * المتغيّرات: $p (صف الإذن) $partyDocs (من الكنترولر — اختياري)
 */
use App\Models\PartyDoc;

$partyDocs = $partyDocs ?? PartyDoc::forPermit($p);
$docCount = 0;
foreach ($partyDocs as $g) { $docCount += count($g['docs'] ?? []); }
$expired = 0;
foreach ($partyDocs as $g) {
    foreach ($g['docs'] as $d) {
        if (PartyDoc::expiryState($d)['state'] === 'expired') { $expired++; }
    }
}
?>
<?php if ($docCount > 0): ?>
  <div class="card pdoc-card mb" id="permit-docs">
    <div class="section-head">
      <?= icon('folder', 17) ?> مستندات الأطراف
      <span class="pdoc-cnt">(<?= intf($docCount) ?>)</span>
      <?php if ($expired > 0): ?>
        <span class="pdoc-flag bad pdoc-flag-inline">
          <?= icon('alert', 13) ?> <?= intf($expired) ?> منتهي</span>
      <?php endif; ?>
    </div>
    <p class="pdoc-hint">أوراق الشركة والوكيل المرفوعة في البيانات الأساسية — تفتحها أو تحمّلها أو تطبعها من هنا،
      والمندوب شايفها على موبايله في نفس الإذن.</p>

    <?= partial_str('party_docs', ['groups' => $partyDocs, 'manage' => false, 'show_party' => true]) ?>
  </div>
<?php endif; ?>
