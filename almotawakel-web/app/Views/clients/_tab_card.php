<?php
/**
 * تبويب «البيانات» — كارت العميل + أسماؤه البديلة + مستنداته.
 *
 * ⚠️ المستندات **نفس مكوّن الشركات بالحرف** (`partials/party_docs`) والرفع
 *    بيروح لـ`DirectoryController@addDoc` — مصدر واحد، ولو اتنسخ هيفترق مع
 *    أول تعديل (درس `permits/_hero.php`).
 *
 * @var array $agent @var array $docs @var array $aliases @var array $docParty @var array $docState
 */
$id   = (int) $agent['id'];
?>

<div class="cl-two">
  <div class="card">
    <div class="section-head">
      <?= icon('user', 17) ?> بيانات العميل
      <span class="cl-spacer"></span>
      <a class="btn ghost sm" href="<?= url('clients/' . $id . '/edit') ?>"><?= icon('edit', 14) ?> تعديل</a>
    </div>
    <div class="cl-facts">
      <?php foreach ([
        ['الاسم القانوني', (string) $agent['name'], 'user'],
        ['الهاتف',        (string) ($agent['phone'] ?? ''), 'smartphone'],
        ['البريد',        (string) ($agent['email'] ?? ''), 'send'],
        ['الرقم الضريبي',  (string) ($agent['tax_id'] ?? ''), 'landmark'],
        ['العنوان',       (string) ($agent['address'] ?? ''), 'map-pin'],
        ['مهلة السداد',   intf((int) ($agent['payment_terms_days'] ?? 30)) . ' يوم', 'clock'],
        ['ملاحظة',        (string) ($agent['note'] ?? ''), 'edit'],
      ] as [$lbl, $val, $ic]): ?>
        <div class="cl-fact">
          <span class="cl-fact-l"><?= icon($ic, 13) ?> <?= e($lbl) ?></span>
          <span class="cl-fact-v"><?= $val !== '' ? '<bdi>' . e($val) . '</bdi>' : '—' ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <div class="section-head"><?= icon('tag', 17) ?> أسماء بديلة <span class="cl-mut"><?= intf(count($aliases)) ?></span></div>
    <?php if (!$aliases): ?>
      <?php partial('empty', ['emoji' => '🏷️', 'title' => 'مفيش أسماء بديلة مسجّلة']); ?>
    <?php else: ?>
      <div class="cl-aliases">
        <?php foreach ($aliases as $al): ?>
          <span class="pill <?= !empty($al['is_primary']) ? 'st-paid' : '' ?>"><?= e($al['alias']) ?></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php /* ⚠️ **مستندات الطرف = نفس الجزء المشترك بالحرف** (`directory/_docs.php`)
         اللي بروفايل الشركة بيستعمله — رفع/تعديل/حذف كلهم على نفس المسارات.
         نسخة تانية هنا كانت هتفترق عنها مع أول تعديل. */ ?>
<?php include __DIR__ . '/../directory/_docs.php'; ?>
