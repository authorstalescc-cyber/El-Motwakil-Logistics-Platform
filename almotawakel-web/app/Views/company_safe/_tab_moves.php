<?php
/**
 * تبويب «الحركات» — السجل الكامل بفلترة.
 *
 * ⚠️ الصفوف مجمّعة **بالأيام**: المدير بيقرا «إيه اللي حصل امبارح» مش «الصف
 *    رقم ٤٠». الترويسة بتحمل صافي اليوم فبيقارن الأيام ببعض بلا ما يفتح حاجة.
 *
 * متغيّرات: $rows $total $limit $filters $labels $cats $retPath $tab
 */
use App\Core\FormState;
use App\Models\CompanySafe;

$q = static function (array $over = []) use ($filters): string {
    $p = array_filter(array_merge(['tab' => 'moves'], $filters, $over),
                      static fn ($v) => $v !== '' && $v !== null);
    return url('company-safe') . '?' . http_build_query($p);
};
?>

<?php /* ═══ شريط الفلترة ═══ */ ?>
<form class="cs-filters" method="get" action="<?= url('company-safe') ?>">
  <input type="hidden" name="tab" value="moves">
  <div class="cs-search">
    <?= icon('search', 16) ?>
    <input type="search" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="جهة أو ملاحظة">
  </div>
  <select name="kind" aria-label="النوع">
    <option value="">كل الأنواع</option>
    <?php foreach ($labels as $k => $lbl): ?>
      <option value="<?= e($k) ?>" <?= ($filters['kind'] ?? '') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="cat" aria-label="فئة المصروف">
    <option value="">كل الفئات</option>
    <?php foreach ($cats as $ck => $cl): ?>
      <option value="<?= e($ck) ?>" <?= ($filters['cat'] ?? '') === $ck ? 'selected' : '' ?>><?= e($cl) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="date" name="from" value="<?= e($filters['from'] ?? '') ?>" aria-label="من">
  <input type="date" name="to" value="<?= e($filters['to'] ?? '') ?>" aria-label="إلى">
  <button class="btn" type="submit"><?= icon('filter', 15) ?> فلترة</button>
  <a class="btn ghost" target="_blank" rel="noopener"
     href="<?= url('company-safe/statement') ?>?from=<?= e($filters['from'] ?? '') ?>&to=<?= e($filters['to'] ?? '') ?>">
    <?= icon('file-text', 15) ?> اطبع
  </a>
</form>

<?php if (array_filter($filters)): ?>
  <div class="flt-chips">
    <?php
    $chipLabel = ['kind' => 'النوع', 'cat' => 'الفئة', 'from' => 'من', 'to' => 'إلى', 'q' => 'بحث'];
    foreach ($filters as $k => $v):
      if ($v === '' || $v === null) { continue; }
      $show = match ($k) {
        'kind'  => $labels[$v] ?? $v,
        'cat'   => CompanySafe::catLabel((string) $v),
        'q'     => $v,
        default => dfmt((string) $v),
      };
    ?>
      <span class="flt-chip"><?= e($chipLabel[$k] ?? $k) ?>: <b><?= e($show) ?></b>
        <a href="<?= e($q([$k => null])) ?>" title="شيل">✕</a></span>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php partial('panel', [
  'title'  => 'سجل الحركات',
  'icon'   => 'layers',
  'badge'  => $rows ? ['text' => intf($total), 'tone' => 'navy'] : null,
  /* ⚠️ **بلا `scroll`**: التبويب ده كله هو القايمة، وصندوق بيتمرّر جوّه صفحة
     بتتمرّر (تمرير متداخل) بيخلّي العجلة تحرّك الصندوق مرة والصفحة مرة —
     وأسوأ حاجة إنه بيقصّ الصفوف في الطباعة ولقطة الشاشة. */
  'body'   => function () use ($rows, $retPath) {
    if (!$rows) {
      partial('empty', ['icon' => 'home', 'title' => 'مفيش حركات']);
      return;
    }
    /* ⚠️ الإجماليات بتتحسب **مرّة واحدة قبل الحلقة** — حسابها جوّه الحلقة
       كان O(n²) (٢٠٠ صف = ٤٠ ألف عملية على كل فتحة). */
    $dayNet = []; $dayCnt = [];
    foreach ($rows as $x) {
      $k = (string) $x['entry_date'];
      $dayNet[$k] = ($dayNet[$k] ?? 0) + (float) $x['signed'];
      $dayCnt[$k] = ($dayCnt[$k] ?? 0) + 1;
    }
    $curDay = null;
    foreach ($rows as $r) {
      $d = (string) $r['entry_date'];
      if ($d !== $curDay) {
        $curDay = $d;
        $net = round($dayNet[$d], 2);
        ?>
        <div class="cs-day">
          <?= icon('calendar', 14) ?> <?= e(dfmt($d)) ?>
          <span class="spacer"></span>
          <em><?= intf($dayCnt[$d]) ?></em>
          <b class="<?= $net < 0 ? 'neg' : 'pos' ?>"><?= ($net >= 0 ? '+ ' : '- ') . money0(abs($net), false) ?></b>
        </div>
        <?php
      }
      $isIn = (bool) $r['is_in'];
      /* ⚠️ العنوان = الجهة، والنوع في الميتا (`subtitle` من الموديل) —
         قانون التصميم: الأيقونة بدل الكلمة المتكررة. */
      $meta = [];
      if ($r['subtitle'])          { $meta[] = ['icon' => (string) $r['icon'], 'text' => (string) $r['subtitle']]; }
      if (!empty($r['cat_label'])) { $meta[] = ['icon' => 'tag', 'text' => (string) $r['cat_label']]; }
      if ($r['note'])   { $meta[] = ['icon' => 'file-text', 'text' => (string) $r['note']]; }
      if (!empty($r['custody_id']))      { $meta[] = ['icon' => 'landmark', 'text' => 'مسّت خزنة المكتب']; }
      if (!empty($r['visa_deposit_id'])) { $meta[] = ['icon' => 'credit-card', 'text' => 'مسّت كارت الفيزا']; }
      if (!empty($r['rep_deposit_id']))  { $meta[] = ['icon' => 'truck', 'text' => 'نزلت لمندوب']; }
      $meta[] = ['icon' => 'home', 'text' => 'الرصيد ' . money((float) $r['running'], false)];
      partial('row_item', [
        'title'  => (string) $r['title'],
        'icon'   => (string) $r['icon'],
        'meta'   => $meta,
        'amount' => ($isIn ? '+ ' : '- ') . money((float) $r['amount'], false),
        'amount_tone' => $isIn ? 'pos' : 'ex',
        'extra'  => empty($r['editable']) ? null : function () use ($r, $retPath) { ?>
          <?php if (!empty($r['attachment'])): ?>
            <a class="edit" target="_blank" rel="noopener" title="الإيصال"
               href="<?= url('attachments/company-safe/' . (int) $r['id']) ?>"><?= icon('eye', 13) ?></a>
          <?php endif; ?>
          <a class="edit" title="تعديل"
             href="<?= url('company-safe') ?>?tab=moves&edit=<?= (int) $r['id'] ?>"><?= icon('edit', 13) ?></a>
          <form method="post" action="<?= url('company-safe/' . (int) $r['id'] . '/delete') ?>"
                class="confirm-delete" data-confirm="حذف الحركة دي؟<?= !empty($r['custody_id']) ? ' هتتشال من خزنة المكتب كمان.' : (!empty($r['visa_deposit_id']) ? ' هتتشال من كارت الفيزا كمان.' : (!empty($r['rep_deposit_id']) ? ' هتتشال من عهدة المندوب كمان.' : '')) ?>">
            <?= csrfField() ?>
            <?= formStateFields(FormState::token(), $retPath) ?>
            <button class="edit" type="submit" title="حذف"><?= icon('trash', 13) ?></button>
          </form>
        <?php },
      ]);
    }
  },
]); ?>

<?php partial('count_note', ['shown' => count($rows), 'total' => $total, 'unit' => 'حركة']); ?>
