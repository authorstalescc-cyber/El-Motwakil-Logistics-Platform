<?php
/**
 * نافذة «التوقّع ده جاي منين؟» — دليل توقّع نوع إيصال في إذن (v101).
 *
 * ⚠️ **دي مش زينة — دي شرط.** قاعدة المالك الدائمة «ممنوع رقم أصمّ»: «٩٤٪»
 *    اللي جنب البند لازم تُضغَط وتوري الأذون اللي اتحسبت منها **بالاسم
 *    والتاريخ**، وكل واحد فيهم فيه ولا مفيش. من غير كده النظام بيقول رقمًا
 *    والمالك مالوش طريقة يتأكد منه — وأول ما يشكّ فيه بيبطّل يبصّ للسطر خالص.
 *
 * ⚠️ **بيوري المستويات كلها مش المستوى اللي اتاخد بس.** «ليه اتحسبت من العميل
 *    مش من التوكيل؟» سؤال مشروع، وإجابته إن التوكيل دليله أقل. إخفاء المستويات
 *    التانية بيخلّي الاختيار سحرًا.
 *
 * بيترندر HTML للنافذة العامة الوحيدة (`#uiDrill`) — مش JSON، عشان الصفوف
 * تستخدم نفس `row_item` بتاع باقي النظام.
 *
 * متغيّرات: $ev (ناتج `ReceiptModel::evidence`) · $kind
 */
use App\Models\Permit;

$ev     = $ev ?? [];
$kind   = (string) ($kind ?? ($ev['kind'] ?? ''));
$item   = $ev['item'] ?? null;
$levels = $ev['levels'] ?? [];
$samples = $ev['samples'] ?? [];
$pairs  = $ev['pairs'] ?? [];

$basisWord = [
    'fixed'         => 'عدد واحد للإذن مهما كانت السيارات',
    'per_car'       => 'إيصال لكل سيارة',
    'per_container' => 'إيصال لكل حاوية',
];
$pct = static fn (?float $v): string => $v === null ? '—' : number_format($v * 100, 0) . '٪';

/* السطر الحاكم: الخلاصة بلغة المالك — من غير مصطلح إحصائي واحد. */
$how = null;
if ($item !== null && $item['p'] !== null) {
    $how = 'ظهر في ' . $pct((float) $item['p']) . ' من الأذون المشابهة';
    if (!empty($item['scope_label'])) { $how .= ' (' . $item['scope_label'] . ')'; }
    if (!empty($item['qty_trusted']) && !empty($item['qty_basis'])) {
        $how .= ' · ' . ($basisWord[$item['qty_basis']] ?? '');
    }
}
?>
<?php if ($item !== null): ?>
<div class="rw-top">
  <?php if ($item['qty_expected'] !== null && !empty($item['qty_trusted'])): ?>
    <span class="rw-stat"><b><?= intf((int) $item['qty_expected']) ?></b><i>المتوقّع</i></span>
    <span class="rw-stat"><b><?= intf((int) $item['qty_entered']) ?></b><i>المُدخَل</i></span>
  <?php endif; ?>
  <?php if ($item['day'] !== null): ?>
    <span class="rw-stat"><b><?= intf((int) $item['day']) ?></b><i>اليوم المعتاد</i></span>
  <?php endif; ?>
  <?php if (!empty($item['amount'])): ?>
    <span class="rw-stat"><b><?= money((float) $item['amount']) ?></b><i>المبلغ المعتاد</i></span>
  <?php endif; ?>
  <?php if (empty($item['qty_trusted']) && $item['qty_basis'] !== null): ?>
    <span class="rw-warn"><?= icon('alert', 13) ?> العدد بيتسجّل بأشكال مختلفة — فمفيش رقم مؤكّد</span>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($pairs): ?>
<div class="rw-pairs">
  <?php foreach (array_slice($pairs, 0, 3) as $pr): ?>
    <span class="rw-pair">لما يبقى فيه «<?= e((string) $pr['kind_a']) ?>»
      بيبقى فيه «<?= e($kind) ?>» في <b><?= e($pct((float) $pr['p_ba'])) ?></b>
      من <?= intf((int) $pr['n_a']) ?> إذن</span>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="rw-levels">
  <?php foreach ($levels as $l): ?>
    <div class="rw-lvl<?= $l['used'] ? ' on' : '' ?>">
      <span class="rw-lvl-name"><?= e((string) $l['label']) ?></span>
      <?php if ($l['found']): ?>
        <span class="rw-lvl-hits"><b><?= intf((int) $l['hits']) ?></b> من <b><?= intf((int) $l['n']) ?></b></span>
        <span class="rw-lvl-p"><?= e($pct($l['prob'])) ?></span>
        <?php if ($l['basis'] !== null && (float) $l['fit'] > 0): ?>
          <span class="rw-lvl-b"><?= e($basisWord[$l['basis']] ?? (string) $l['basis']) ?>
            <?php if ($l['basis'] !== 'fixed' && abs((float) $l['rate'] - 1) > 0.01): ?>
              ×<?= e(rtrim(rtrim(number_format((float) $l['rate'], 2), '0'), '.')) ?>
            <?php endif; ?>
          </span>
        <?php endif; ?>
      <?php else: ?>
        <span class="rw-lvl-none">مفيش سابقة</span>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php partial('drill', [
    'how'   => $how,
    'count' => count($samples),
    'total' => $item !== null && $item['n_permits'] !== null
        ? ['label' => $kind, 'value' => intf((int) $item['n_hits']) . ' من ' . intf((int) $item['n_permits'])]
        : null,
    'empty' => ['title' => 'مفيش أذون مقفولة مشابهة لسه'],
    'more'  => ['label' => 'اللي النظام اتعلّمه', 'url' => url('receipt-model')],
    'body'  => function () use ($samples, $kind) {
        foreach ($samples as $s) {
            /* ⚠️ **`rc` مش `pos`** — `.d-feed-side b` معرِّف `ex` و`rc` بس،
               وأي نغمة تانية بتقع على الشكل الافتراضي بالسكوت. */
            partial('row_item', [
                'title' => trim((string) ($s['permit_no'] ?? '')) !== ''
                    ? (string) $s['permit_no'] : '#' . (int) $s['id'],
                'ref'   => $s['permit_date'] ? dfmt($s['permit_date']) : null,
                'icon'  => 'clipboard',
                'meta'  => array_values(array_filter([
                    trim((string) ($s['client'] ?? '')) !== ''
                        ? ['icon' => 'user', 'text' => (string) $s['client']] : null,
                    trim((string) ($s['agency'] ?? '')) !== ''
                        ? ['icon' => 'anchor', 'text' => (string) $s['agency']] : null,
                    (int) $s['cars'] > 0
                        ? ['icon' => 'truck', 'text' => intf((int) $s['cars']) . ' سيارة'] : null,
                ])),
                'amount'      => $s['has'] ? intf((int) $s['qty']) : 'مفيش',
                'amount_tone' => $s['has'] ? 'rc' : 'ex',
            ]);
        }
    },
]); ?>
