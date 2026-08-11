<?php
/**
 * تبويب «الكارت» — بيانات المندوب وصلاحياته وتقفيلاته وحذفه.
 *
 * ⚠️ الصلاحيتان بيتظبّطوا من شاشة عهدة المناديب (`/rep-custody`) — بيتعرضوا
 *    هنا **للعلم**: زرار تاني لنفس الإجراء بيفترق أول تعديل (قاعدة ②).
 *
 * @var array $rep @var array $card @var int $rid
 */
?>
<div class="two-col">
  <div class="card oc-pad">
    <div class="section-head oc-sec"><?= icon('user', 17) ?> بيانات الكارت</div>
    <div class="rp-calc">
      <div class="rp-line"><span class="rp-k">الاسم</span><span class="rp-v"><?= e($rep['name']) ?></span></div>
      <div class="rp-line"><span class="rp-k">التليفون</span>
        <span class="rp-v"><?= $rep['phone'] ? '<bdi>' . e($rep['phone']) . '</bdi>' : '—' ?></span></div>
      <div class="rp-line"><span class="rp-k">الراتب الثابت</span>
        <span class="rp-v"><?= money((float) $rep['monthly_salary'], false) ?></span></div>
      <div class="rp-line"><span class="rp-k">تاريخ التعيين</span>
        <span class="rp-v"><?= $rep['hire_date'] ? e(dfmt((string) $rep['hire_date'])) : '—' ?></span></div>
      <div class="rp-line"><span class="rp-k">الحالة</span>
        <span class="rp-v"><?= $rep['status'] === 'active' ? 'شغّال' : 'موقوف' ?></span></div>
      <?php if (!empty($rep['note'])): ?>
        <div class="rp-line"><span class="rp-k">ملاحظة</span><span class="rp-v"><?= e((string) $rep['note']) ?></span></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card oc-pad">
    <div class="section-head oc-sec"><?= icon('shield', 17) ?> صلاحياته على الموبايل</div>
    <div class="rp-calc">
      <div class="rp-line">
        <span class="rp-k">يفتح إذن من موبايله</span>
        <span class="rp-v"><?= $card['permReady']
          ? ($card['canCreatePermit'] ? icon('check', 16) : icon('x', 16))
          : '—' ?></span>
      </div>
      <div class="rp-line">
        <span class="rp-k">يتابع باقي المناديب</span>
        <span class="rp-v"><?= $card['watchReady']
          ? ($card['canWatchReps'] ? icon('check', 16) : icon('x', 16))
          : '—' ?></span>
      </div>
      <div class="rp-line">
        <span class="rp-k">أذونات مثبّتة عنده</span>
        <span class="rp-v"><?= intf(count($card['pins'])) ?></span>
      </div>
    </div>
    <a class="btn ghost" href="<?= url('rep-custody') ?>"><?= icon('briefcase', 15) ?> ظبّط من عهدة المناديب</a>
  </div>
</div>

<div class="card mb">
  <div class="section-head rts-head"><?= icon('receipt', 17) ?> كل التقفيلات</div>
  <?php partial('table', [
    'cols'  => [['label' => 'تاريخ الصرف'], ['label' => 'الفترة'], ['label' => 'الصافي'],
                ['label' => 'الطريقة'], ['label' => '']],
    'count' => count($card['settlements']),
    'empty' => ['title' => 'لسه مفيش تقفيلات'],
    'rows'  => function () use ($card, $rid) {
      foreach ($card['settlements'] as $st): ?>
        <tr>
          <td><?= e(dfmt((string) ($st['pay_date'] ?: substr((string) $st['settled_at'], 0, 10)))) ?></td>
          <td class="rts-sm"><?= $st['period_from'] || $st['period_to']
              ? e(dfmt((string) $st['period_from'])) . ' – ' . e(dfmt((string) $st['period_to']))
              : 'الكل' ?></td>
          <td class="mv-ref"><?= money((float) $st['net'], false) ?></td>
          <td><span class="chip"><?= !empty($st['is_visa']) ? 'فيزا' : 'كاش' ?></span></td>
          <td><a class="btn ghost sm" target="_blank" rel="noopener"
                 href="<?= e(url('reps/' . $rid . '/settlement/' . (int) $st['id'] . '/print')) ?>">
              <?= icon('file-text', 14) ?> طباعة</a></td>
        </tr>
      <?php endforeach;
    },
  ]); ?>
</div>

<div class="card oc-pad">
  <div class="section-head oc-sec"><?= icon('trash', 17) ?> حذف المندوب</div>
  <form method="post" action="<?= url('reps/' . $rid . '/delete') ?>" class="confirm-delete"
        data-confirm="حذف المندوب وكل سجله نهائيًا؟">
    <?= csrfField() ?>
    <button class="btn ghost danger-tx" type="submit"><?= icon('trash', 15) ?> حذف نهائي</button>
  </form>
</div>
