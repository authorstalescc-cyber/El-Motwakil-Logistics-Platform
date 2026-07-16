<?php
$total = 0; foreach (($results ?? []) as $g) { $total += count($g['rows']); }
$from = $from ?? ''; $to = $to ?? '';
$today = date('Y-m-d');
$presets = [
    'الكل'       => ['', ''],
    'اليوم'      => [$today, $today],
    'آخر ٧ أيام' => [date('Y-m-d', strtotime('-6 days')), $today],
    'آخر ٣٠ يوم' => [date('Y-m-d', strtotime('-29 days')), $today],
    'هذا الشهر'  => [date('Y-m-01'), $today],
    'هذا العام'  => [date('Y-01-01'), $today],
];
// تحديد الفلتر النشط
$activePreset = null;
foreach ($presets as $lbl => $rg) { if ($from === $rg[0] && $to === $rg[1]) { $activePreset = $lbl; break; } }
$isCustom = ($activePreset === null) && ($from !== '' || $to !== '');
$types = $types ?? [];
$cats  = $cats ?? [];
$typeStr = implode(',', $types);
$chipUrl = function(string $f, string $t) use ($q, $typeStr) {
    $p = ['q' => $q]; if ($f !== '') $p['from'] = $f; if ($t !== '') $p['to'] = $t;
    if ($typeStr !== '') $p['type'] = $typeStr;
    return url('search') . '?' . http_build_query($p);
};
// رابط تصنيف واحد (اختيار مفرد) مع الحفاظ على التاريخ
$catUrl = function(string $key) use ($q, $from, $to) {
    $p = ['q' => $q]; if ($from !== '') $p['from'] = $from; if ($to !== '') $p['to'] = $to;
    if ($key !== '') $p['type'] = $key;
    return url('search') . '?' . http_build_query($p);
};
$activeCat = (count($types) === 1) ? $types[0] : null;
// هل النتائج تسامح إملائي (تهجئة قريبة) بدل مطابقة دقيقة؟
$anyFuzzy = false; foreach (($results ?? []) as $g) { if (!empty($g['fuzzy'])) { $anyFuzzy = true; break; } }
?>

<!-- رأس البحث -->
<div class="search-hero">
  <form method="get" action="<?= url('search') ?>" class="search-field" role="search">
    <span class="sf-ico"><?= icon('search',20) ?></span>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="ابحث عن رقم بوليصة، إذن، شهادة، حاوية، سيارة، سائق، عميل، جهة…" autofocus>
    <input type="hidden" name="from" value="<?= e($from) ?>">
    <input type="hidden" name="to" value="<?= e($to) ?>">
    <input type="hidden" name="type" value="<?= e($typeStr) ?>">
    <button class="btn" type="submit">بحث</button>
  </form>

  <div class="search-toolbar">
    <!-- التاريخ (شرائح مدمجة) -->
    <div class="stb-seg" role="group" aria-label="فلتر التاريخ">
      <span class="stb-lbl"><?= icon('calendar',14) ?></span>
      <?php foreach ($presets as $lbl => $rg): ?>
        <a class="stb-chip <?= $activePreset === $lbl ? 'active' : '' ?>" href="<?= e($chipUrl($rg[0], $rg[1])) ?>"><?= e($lbl) ?></a>
      <?php endforeach; ?>
      <details class="stb-drop stb-custom" <?= $isCustom ? 'open' : '' ?>>
        <summary class="stb-chip <?= $isCustom ? 'active' : '' ?>"><?= icon('calendar',12) ?> مخصّص</summary>
        <form method="get" action="<?= url('search') ?>" class="stb-menu stb-custom-menu">
          <input type="hidden" name="q" value="<?= e($q) ?>">
          <input type="hidden" name="type" value="<?= e($typeStr) ?>">
          <label>من <input type="date" name="from" value="<?= e($from) ?>"></label>
          <label>إلى <input type="date" name="to" value="<?= e($to) ?>"></label>
          <button class="btn sm" type="submit">تطبيق</button>
        </form>
      </details>
    </div>

    <!-- النوع (قائمة منسدلة مدمجة) -->
    <details class="stb-drop stb-type">
      <summary class="stb-type-btn <?= $activeCat !== null ? 'on' : '' ?>">
        <?= icon('folder',14) ?>
        <span class="stb-type-cap">النوع:</span>
        <b><?= $activeCat !== null && isset($cats[$activeCat]) ? e($cats[$activeCat]['title']) : 'الكل' ?></b>
        <?= icon('chevron',13,'stb-caret') ?>
      </summary>
      <div class="stb-menu stb-type-menu">
        <a class="stm-item <?= $activeCat === null ? 'active' : '' ?>" href="<?= e($catUrl('')) ?>">
          <span class="stm-ic"><?= icon('layers',14) ?></span> كل الأنواع
        </a>
        <?php foreach ($cats as $ck => $cv): ?>
          <a class="stm-item <?= $activeCat === $ck ? 'active' : '' ?>" href="<?= e($catUrl($ck)) ?>">
            <span class="stm-ic"><?= icon($cv['icon'],14) ?></span> <?= e($cv['title']) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </details>
  </div>

  <?php if ($q !== ''): ?>
    <div class="search-summary">
      <?php if ($total): ?>
        <span class="ss-count"><?= intf($total) ?></span> نتيجة لـ «<b><?= e($q) ?></b>»
        <?php if ($from !== '' || $to !== ''): ?>
          <span class="sfil-active"><?= icon('calendar',13) ?> <?= e($activePreset ?? (($from ?: '…') . ' → ' . ($to ?: '…'))) ?></span>
        <?php endif; ?>
        <?php if ($activeCat !== null && isset($cats[$activeCat])): ?>
          <span class="sfil-active"><?= icon('folder',13) ?> <?= e($cats[$activeCat]['title']) ?></span>
        <?php endif; ?>
        <?php foreach ($results as $key=>$grp): ?>
          <a class="ss-chip" href="#grp-<?= e($key) ?>"><?= icon($grp['icon'] ?? 'file-text',15) ?> <?= e($grp['title']) ?> <b><?= count($grp['rows']) ?></b></a>
        <?php endforeach; ?>
      <?php else: ?>
        لا نتائج لـ «<b><?= e($q) ?></b>»
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($q===''): ?>
  <div class="search-empty">
    <span class="se-ic"><?= icon('search',34) ?></span>
    <h3>ابدأ البحث في كل سجلات النظام</h3>
    <p>اكتب أي رقم أو اسم — بوليصة، إذن، شهادة، حاوية، رقم سيارة، سائق، مالك سيارة، عميل أو جهة — وسيبحث النظام في كل الجداول دفعةً واحدة ويصنّف النتائج تلقائيًا.</p>
    <p class="se-hint">جرّب الاختصار <kbd>Ctrl</kbd> + <kbd>K</kbd> من أي صفحة لفتح البحث السريع.</p>
  </div>
<?php elseif (!$results): ?>
  <div class="search-empty">
    <span class="se-ic"><?= icon('inbox',34) ?></span>
    <h3>لا توجد نتائج مطابقة</h3>
    <p>لم نعثر على أي سجل يطابق «<?= e($q) ?>». جرّب كلمة أقصر أو رقمًا جزئيًا.</p>
  </div>
<?php else: ?>
  <?php if ($anyFuzzy): ?>
    <div class="search-fuzzy-note"><?= icon('search',16) ?>
      <span>مفيش مطابقة تامة لـ «<b><?= e($q) ?></b>» — دي أقرب النتائج تهجئةً. لو مش اللي تقصده، جرّب كتابة أوضح.</span>
    </div>
  <?php endif; ?>
  <?php foreach ($results as $key=>$grp): ?>
  <section class="result-group<?= !empty($grp['fuzzy']) ? ' rg-fuzzy' : '' ?>" id="grp-<?= e($key) ?>">
    <div class="rg-head">
      <span class="rg-ic"><?= icon($grp['icon'] ?? 'file-text',18) ?></span>
      <h2><?= e($grp['title']) ?></h2>
      <?php if (!empty($grp['fuzzy'])): ?><span class="rg-fuzzy-badge">تهجئة قريبة</span><?php endif; ?>
      <span class="rg-count"><?= count($grp['rows']) ?></span>
    </div>
    <div class="table-wrap">
      <table class="data search-table">
        <thead>
          <tr>
            <th style="width:110px">التاريخ</th>
            <th class="txt">البيان</th>
            <th style="width:90px">النوع</th>
            <th style="width:130px">المبلغ</th>
            <th style="width:70px"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($grp['rows'] as $row): ?>
            <tr>
              <td class="num"><?= $row['date'] ? dfmt($row['date']) : '—' ?></td>
              <td class="txt">
                <a class="sr-link" href="<?= e($row['url']) ?>"><?= e($row['label']) ?></a>
                <?php if (!empty($row['sub'])): ?><span class="sr-sub"><?= e($row['sub']) ?></span><?php endif; ?>
              </td>
              <td><?php if (!empty($row['field'])): ?><span class="sr-tag"><?= e($row['field']) ?></span><?php endif; ?></td>
              <td class="num"><?= $row['amount'] !== null ? money($row['amount'],false) : '—' ?></td>
              <td><a class="btn ghost xs icon-btn" href="<?= e($row['url']) ?>" title="فتح السجل"><?= icon('external',14) ?></a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endforeach; endif; ?>

<script>
/* إغلاق قوائم الفلاتر المنسدلة عند النقر بالخارج أو Esc */
(function(){
  const drops = [...document.querySelectorAll('.stb-drop')];
  document.addEventListener('click', e => {
    drops.forEach(d => { if (d.open && !d.contains(e.target)) d.open = false; });
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') drops.forEach(d => d.open = false);
  });
})();
</script>
