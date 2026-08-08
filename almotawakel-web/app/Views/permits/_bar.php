<?php
/**
 * شريط إجراءات الإذن — **مشترك بين العرض والإدارة** (المرحلة ٣).
 *
 * المشكلة اللي بيحلّها: كان فيه شاشتان بنفس الهيرو ونفس المراحل تقريبًا،
 * وكل واحدة فيها لينك باسم مختلف للتانية («✏️ تعديل / إدارة» مقابل
 * «👁 عرض الإذن»)، فالمستخدم لازم يقرّر «عرض» ولا «إدارة» **قبل** ما يشوف
 * اللي جوّه. دلوقتي فيه **مفتاح وضع** واضح: نفس الشاشة، وضعان، والتنقّل
 * بينهم بضغطة واحدة والوضع الحالي مُعلَّم.
 *
 * المتغيّرات: $pid $type $isClosed $mode ('view' | 'manage')
 */
use App\Models\Permit;

$mode = $mode ?? 'view';
?>
<div class="permit-bar">
  <a class="btn ghost sm" href="<?= url('permits') ?>">‹ رجوع للإصدارات</a>
  <span class="pb-title"><?= Permit::typeLabel($type) ?> <b><?= e($p['permit_no'] ?: '#'.$pid) ?></b></span>
  <span class="pill <?= $isClosed?'st-paid':'st-partial' ?>"><?= $isClosed?'مقفول ✓':'جارٍ' ?></span>

  <?php /* مفتاح الوضع — بيوضّح إن دي شاشة واحدة بوضعين مش شاشتين */ ?>
  <div class="pb-modes" role="group" aria-label="وضع العرض">
    <a class="pb-mode <?= $mode==='view'?'on':'' ?>" href="<?= url('permits/'.$pid) ?>"
       <?= $mode==='view' ? 'aria-current="page"' : '' ?>><?= icon('search',15) ?> عرض</a>
    <a class="pb-mode <?= $mode==='manage'?'on':'' ?>" href="<?= url('permits/'.$pid.'/manage') ?>"
       <?= $mode==='manage' ? 'aria-current="page"' : '' ?>><?= icon('edit',15) ?> تعديل</a>
  </div>

  <span class="ps-sp"></span>
  <a class="btn ghost sm" href="<?= url('permits/'.$pid.'/print') ?>" target="_blank"><?= icon('file-text', 14) ?> طباعة المراجعة</a>
  <a class="btn ghost sm" href="<?= url('permits/'.$pid.'/invoice') ?>"><?= icon('receipt', 14) ?> إصدار فاتورة</a>
  <?php if ($mode === 'manage'): ?>
    <button class="btn sm" type="submit" form="permitDataForm"><?= icon('download', 14) ?> حفظ الإذن</button>
    <form method="post" action="<?= url('permits/'.$pid.'/close') ?>" <?= $isClosed?'':'class="confirm-financial" data-confirm="تأكيد إتمام وإغلاق الإذن؟"' ?>>
      <?= csrfField() ?>
      <button class="btn sm <?= $isClosed?'ghost':'gold' ?>" type="submit"><?= $isClosed?'↩️ إعادة فتح':'✅ إتمام الإذن' ?></button>
    </form>
  <?php endif; ?>
</div>
