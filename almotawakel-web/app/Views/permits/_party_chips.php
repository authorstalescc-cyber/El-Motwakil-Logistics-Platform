<?php
/**
 * أزرار الأطراف المتكررة (شركة / وكيل) — تظهر تلقائيًا لأي طرف له أكثر من إذن واحد.
 * عرض وتصفية فقط — لا تمسّ أي حساب ولا تقفيل ولا عهدة.
 *
 * المتغيّرات المتوقَّعة قبل التضمين:
 *   $pcCoList / $pcClList : [['name'=>..,'c'=>..], ...]
 *   $pcCo / $pcCl         : الاسم المُصفّى حاليًا ('' = بلا تصفية)
 *   $pcUrl                : closure(array $overrides): string — يبني رابط الصفحة بنفس الفلاتر
 */
$pcCoList = $pcCoList ?? []; $pcClList = $pcClList ?? [];
$pcCo = (string)($pcCo ?? ''); $pcCl = (string)($pcCl ?? '');
$pcUrl = $pcUrl ?? static fn(array $overrides = []): string => url('permits?'.http_build_query(array_filter($overrides)));

// اضمن ظهور المُصفّى حاليًا كزر حتى لو له إذن واحد (وإلا يختفي زر إلغائه)
$pcHas = static function (array $list, string $n): bool {
    foreach ($list as $x) { if (($x['name'] ?? '') === $n) { return true; } }
    return false;
};
if ($pcCo !== '' && !$pcHas($pcCoList, $pcCo)) { array_unshift($pcCoList, ['name'=>$pcCo, 'c'=>null]); }
if ($pcCl !== '' && !$pcHas($pcClList, $pcCl)) { array_unshift($pcClList, ['name'=>$pcCl, 'c'=>null]); }

if (!$pcCoList && !$pcClList) { return; }
$pcVisible = 8;   // الباقي خلف زر «المزيد»

$pcRow = static function (string $icon, string $label, array $list, string $active, string $key, callable $url) use ($pcVisible): void {
    if (!$list) { return; }
    $extra = max(0, count($list) - $pcVisible);
    ?>
    <div class="pp-row<?= $extra ? ' has-extra' : '' ?>">
      <span class="pp-lbl"><?= $icon ?> <?= e($label) ?></span>
      <div class="pp-chips">
        <?php if ($active !== ''): ?>
          <a class="pp-chip pp-clear" href="<?= e($url([$key => '', 'page' => 1])) ?>" title="إلغاء التصفية"><?= icon('x', 14) ?> الكل</a>
        <?php endif; ?>
        <?php foreach ($list as $i => $x): $on = ($x['name'] === $active); ?>
          <a class="pp-chip<?= $on ? ' on' : '' ?><?= $i >= $pcVisible ? ' pp-hid' : '' ?>"
             href="<?= e($url([$key => $on ? '' : $x['name'], 'page' => 1])) ?>"
             title="<?= e($on ? 'إلغاء تصفية: '.$x['name'] : 'اعرض أذونات: '.$x['name']) ?>">
            <span class="pp-nm"><?= e($x['name']) ?></span>
            <?php if ($x['c'] !== null): ?><span class="pp-ct num"><?= intf($x['c']) ?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
        <?php if ($extra): ?>
          <button type="button" class="pp-chip pp-more" onclick="this.closest('.pp-row').classList.toggle('open')">
            <span class="pp-m-open">+<span class="num"><?= intf($extra) ?></span> أخرى ▾</span>
            <span class="pp-m-close">أقلّ ▴</span>
          </button>
        <?php endif; ?>
      </div>
    </div>
    <?php
};
?>
<div class="pv-parties">
  <?php $pcRow('🏢', 'شركات متكررة', $pcCoList, $pcCo, 'co', $pcUrl); ?>
  <?php $pcRow('👤', 'وكلاء متكررون', $pcClList, $pcCl, 'cl', $pcUrl); ?>
</div>
