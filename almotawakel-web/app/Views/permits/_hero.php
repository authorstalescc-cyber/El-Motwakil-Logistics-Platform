<?php
/**
 * هيرو الإذن — **جزء مشترك بين شاشة العرض وشاشة الإدارة** (المرحلة ٣).
 *
 * كان الماركب ده مكرَّر **حرفيًا** في `view.php` و`manage.php` (٣٥ سطر × ٢)،
 * والفرق الوحيد كان مكان حساب `$ctLbl`. أي تعديل في بطاقة واحدة كان لازم
 * يتعمل مرتين، وأول مرة تُنسى بتبقى الشاشتان مختلفتين من غير ما حد ياخد باله.
 *
 * المتغيّرات المتوقَّعة من الشاشة المستدعية:
 *   $p $pid $th $noLabel $progress $done $tot $movements $totals
 *   $ctBreakdown (اختياري)
 */
use App\Models\Permit;

// نحسبه هنا لو الشاشة ما حسبتهوش — الشاشتان كانتا بتعملاها بطريقتين مختلفتين
if (!isset($ctLbl)) {
    $ctLbl = Permit::containerLabel($ctBreakdown ?? [], (int) ($p['containers'] ?? 0), $p['container_type'] ?? null);
}
?>
<div class="permit-hero card mb t-<?= $th['cls'] ?>">
  <div class="hero-main">
    <?php include __DIR__ . '/_hero_bg.php'; ?>
    <div class="hero-id">
      <span class="type-chip <?= $th['cls'] ?>"><span class="tc-arrow"><?= $th['arrow'] ?></span> <?= $th['full'] ?></span>
      <div class="hero-no"><span><?= e($noLabel) ?></span><h1><?= e($p['permit_no'] ?: '#'.$pid) ?></h1></div>
      <div class="hero-meta">
        <?php if($p['company']): ?><span class="hm-pill"><span class="hm-ic"><?= icon('building',16) ?></span><span class="hm-tx"><small>لصالح شركة</small><b><?= e($p['company']) ?></b></span></span><?php endif; ?>
        <?php if($p['client']): ?><span class="hm-pill"><span class="hm-ic"><?= icon('user',16) ?></span><span class="hm-tx"><small>الوكيل</small><b><?= e($p['client']) ?></b></span></span><?php endif; ?>
        <?php if($p['destination']): ?><span class="hm-pill"><span class="hm-ic"><?= icon('map-pin',16) ?></span><span class="hm-tx"><small>الجهة</small><b><?= e($p['destination']) ?></b></span></span><?php endif; ?>
        <?php if(!empty($p['port'])): ?><span class="hm-pill"><span class="hm-ic"><?= icon('anchor',16) ?></span><span class="hm-tx"><small>الميناء</small><b><?= e($p['port']) ?></b></span></span><?php endif; ?>
        <span class="hm-pill"><span class="hm-ic"><?= icon('calendar',16) ?></span><span class="hm-tx"><small>التاريخ</small><b><?= dfmt($p['permit_date']) ?: '—' ?></b></span></span>
        <?php if($ctLbl!==''): ?><span class="hm-pill"><span class="hm-ic"><?= icon('package',16) ?></span><span class="hm-tx"><small>الحاويات (عدد×تصنيف)</small><b><?= e($ctLbl) ?></b></span></span><?php endif; ?>
      </div>
    </div>
    <div class="hero-ring">
      <svg viewBox="0 0 36 36">
        <path class="ring-bg" pathLength="100" d="M18 2.5a15.5 15.5 0 1 1 0 31 15.5 15.5 0 1 1 0-31"/>
        <path class="ring-fill" pathLength="100" stroke-dasharray="<?= $progress ?>,100" d="M18 2.5a15.5 15.5 0 1 1 0 31 15.5 15.5 0 1 1 0-31"/>
      </svg>
      <div class="ring-lbl"><b><?= $progress ?>%</b><span><?= $done ?>/<?= $tot ?></span></div>
    </div>
  </div>
  <div class="hero-tiles">
    <div class="htile c-blue"><span class="ht-ic"><?= icon('package',20) ?></span><span class="ht-txt"><b><?= intf($p['containers'] ?? 0) ?></b><span class="ht-l">عدد الحاويات</span></span></div>
    <div class="htile c-teal"><span class="ht-ic"><?= icon('shuffle',20) ?></span><span class="ht-txt"><b><?= intf(count($movements)) ?></b><span class="ht-l">الحركات</span></span></div>
    <div class="htile c-indigo"><span class="ht-ic"><?= icon('truck',20) ?></span><span class="ht-txt"><b><?= intf($totals['bills']) ?></b><span class="ht-l">البوالص</span></span></div>
    <div class="htile c-green"><span class="ht-ic"><?= icon('banknote',20) ?></span><span class="ht-txt"><b class="pos"><?= money($totals['custody'],false) ?></b><span class="ht-l">العهدة</span></span></div>
    <div class="htile c-red"><span class="ht-ic"><?= icon('trending-down',20) ?></span><span class="ht-txt"><b class="neg"><?= money($totals['expense'],false) ?></b><span class="ht-l">المصروفات</span></span></div>
    <div class="htile c-gold"><span class="ht-ic"><?= icon('coins',20) ?></span><span class="ht-txt"><b><?= money($totals['charged'] ?? 0,false) ?></b><span class="ht-l">السعر الفعلي (للعميل)</span></span></div>
    <div class="htile c-purple"><span class="ht-ic"><?= icon('credit-card',20) ?></span><span class="ht-txt"><b><?= money($totals['debt'],false) ?></b><span class="ht-l">دين البوالص</span></span></div>
    <div class="htile c-amber"><span class="ht-ic"><?= icon('hourglass',20) ?></span><span class="ht-txt"><b class="<?= $totals['remaining']>0?'neg':'pos' ?>"><?= money($totals['remaining'],false) ?></b><span class="ht-l">المتبقي</span></span></div>
  </div>
</div>
