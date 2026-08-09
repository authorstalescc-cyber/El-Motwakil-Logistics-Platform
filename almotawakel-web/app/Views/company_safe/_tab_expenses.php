<?php
/**
 * تبويب «المصروفات» — راح فين · لمين · والميزانية.
 *
 * ⚠️ الفئات والجهات على **نفس شهر** شريط الميزانية بالظبط — لو اتحسبوا على
 *    فترات مختلفة الشاشة بتقول رقمين لنفس الحاجة (درس `/payments`).
 *
 * متغيّرات: $catRows $partyRows $rows $budget $cats $filters $mStart $mEnd $st $retPath
 */
use App\Core\FormState;
use App\Models\CompanySafe;

$catUrl = static fn (?string $c): string =>
    url('company-safe') . '?tab=expenses' . ($c ? '&cat=' . urlencode($c) : '');
$selCat = (string) ($filters['cat'] ?? '');
?>

<?php /* ═══ ① الميزانية — شريط واحد بيقول وصلت لكام ═══ */ ?>
<section class="cs-card">
  <div class="cs-budget-row">
    <div class="cs-budget-num">
      <span class="k">مصروفات <?= e(dfmt($mStart)) ?> — <?= e(dfmt($mEnd)) ?></span>
      <b class="<?= $budget['over'] ? 'neg' : '' ?>"><?= money0($budget['spent'], false) ?></b>
    </div>
    <?php if ($budget['on']): ?>
      <div class="cs-budget-meter<?= $budget['over'] ? ' over' : '' ?>">
        <span class="bar"><span style="width:<?= min(100, max(0, $budget['pct'])) ?>%"></span></span>
        <span class="txt">
          <em><?= intf($budget['pct']) ?>%</em> من <?= money0($budget['budget'], false) ?>
          <?php if ($budget['over']): ?><i class="neg">زيادة <?= money0(abs($budget['left']), false) ?></i>
          <?php else: ?><i>فاضل <?= money0($budget['left'], false) ?></i><?php endif; ?>
        </span>
      </div>
    <?php endif; ?>
    <?php /* سقف الشهر — الفاضي = مفيش سقف = مفيش تنبيه. */ ?>
    <form class="cs-budget-set" method="post" action="<?= url('company-safe/budget') ?>">
      <?= csrfField() ?>
      <?= formStateFields($st['token'], $retPath) ?>
      <label for="csBudget">سقف الشهر</label>
      <input type="number" step="0.01" min="0" name="budget" id="csBudget" placeholder="بلا سقف"
             value="<?= $budget['budget'] > 0 ? e((string) $budget['budget']) : '' ?>">
      <button class="btn" type="submit"><?= icon('check', 14) ?></button>
    </form>
  </div>
</section>

<?php /* ═══ ② راح فين — الفئات كشرايح مرتبة بالقيمة ═══ */ ?>
<div class="cs-two">
  <?php partial('panel', [
    'title' => 'راح فين',
    'icon'  => 'tag',
    'body'  => function () use ($catRows, $catUrl, $selCat) {
      if (!$catRows) { partial('empty', ['icon' => 'trending-down', 'title' => 'مفيش مصروفات الشهر ده']); return; }
      $max = max(array_map(static fn ($c) => $c['total'], $catRows)) ?: 1;
      foreach ($catRows as $c): ?>
        <a class="cs-catrow<?= $selCat === $c['key'] ? ' on' : '' ?>" href="<?= e($catUrl($selCat === $c['key'] ? null : $c['key'])) ?>">
          <span class="t"><?= e($c['label']) ?></span>
          <span class="track"><span style="width:<?= round($c['total'] / $max * 100, 1) ?>%"></span></span>
          <b><?= money0($c['total'], false) ?></b>
          <em><?= $c['pct'] ?>%</em>
        </a>
      <?php endforeach;
    },
  ]); ?>

  <?php partial('panel', [
    'title'  => 'لمين',
    'icon'   => 'users',
    'action' => ['label' => 'الكل', 'drill' => url('company-safe/drill/parties'),
                 'title' => 'صرفنا لمين الشهر ده'],
    'body'   => function () use ($partyRows) {
      if (!$partyRows) { partial('empty', ['icon' => 'user', 'title' => 'مفيش جهات صرف']); return; }
      foreach ($partyRows as $p) {
        partial('row_item', [
          'title'  => $p['party'], 'icon' => 'user',
          'meta'   => [['icon' => 'layers', 'text' => intf($p['count']) . ' بند']],
          'amount' => money($p['total'], false), 'amount_tone' => 'ex',
        ]);
      }
    },
  ]); ?>
</div>

<?php /* ═══ ③ بنود الشهر ═══ */ ?>
<?php partial('panel', [
  'title'  => $selCat !== '' ? 'بنود ' . CompanySafe::catLabel($selCat) : 'بنود الشهر',
  'icon'   => 'trending-down',
  'badge'  => $rows ? ['text' => intf(count($rows)), 'tone' => 'navy'] : null,
  'action' => $selCat !== '' ? ['label' => 'كل الفئات', 'url' => $catUrl(null)] : null,
  'body'   => function () use ($rows, $retPath) {
    if (!$rows) { partial('empty', ['icon' => 'trending-down', 'title' => 'مفيش بنود']); return; }
    foreach ($rows as $r) {
      $meta = [['icon' => 'calendar', 'text' => dfmt((string) $r['entry_date'])]];
      if (!empty($r['cat_label'])) { $meta[] = ['icon' => 'tag', 'text' => (string) $r['cat_label']]; }
      if ($r['note'])   { $meta[] = ['icon' => 'file-text', 'text' => (string) $r['note']]; }
      partial('row_item', [
        'title'  => $r['source'] ?: 'بدون جهة',
        'icon'   => 'trending-down',
        'meta'   => $meta,
        'amount' => '- ' . money((float) $r['amount'], false),
        'amount_tone' => 'ex',
        'extra'  => function () use ($r, $retPath) { ?>
          <?php if (!empty($r['attachment'])): ?>
            <a class="edit" target="_blank" rel="noopener" title="الإيصال"
               href="<?= url('attachments/company-safe/' . (int) $r['id']) ?>"><?= icon('eye', 13) ?></a>
          <?php endif; ?>
          <a class="edit" title="تعديل"
             href="<?= url('company-safe') ?>?tab=expenses&edit=<?= (int) $r['id'] ?>"><?= icon('edit', 13) ?></a>
          <form method="post" action="<?= url('company-safe/' . (int) $r['id'] . '/delete') ?>"
                class="confirm-delete" data-confirm="حذف المصروف ده؟">
            <?= csrfField() ?>
            <?= formStateFields(FormState::token(), $retPath) ?>
            <button class="edit" type="submit" title="حذف"><?= icon('trash', 13) ?></button>
          </form>
        <?php },
      ]);
    }
  },
]); ?>
