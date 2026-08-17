<?php
/**
 * نافذة «التوقّع ده جاي منين؟» — دليل توقّع نوع إيصال في إذن (v101).
 *
 * ⚠️ **دي مش زينة — دي شرط.** قاعدة المالك الدائمة «ممنوع رقم أصمّ»: «٩٤٪»
 *    اللي جنب البند لازم تُضغَط وتوري الأذون اللي اتحسبت منها **بالاسم
 *    والتاريخ**، وكل واحد فيهم فيه ولا مفيش.
 *
 * ⚠️ **الأساس بيتكتب مرة واحدة فوق مش في كل سطر.** كان بيتكرّر في الستة
 *    مستويات («عدد واحد للإذن مهما كانت السيارات» ×٤) فبقى حيطة كلام بتغطّي
 *    على الأرقام اللي الشباك أصلاً موجود عشانها. المستويات دلوقتي أرقام بس.
 *
 * ⚠️ **بيوري المستويات كلها مش المستوى اللي اتاخد بس** — «ليه اتحسبت من
 *    العميل مش من التوكيل؟» سؤال مشروع، وإجابته إن التوكيل دليله أقل.
 *
 * متغيّرات: $ev (ناتج `ReceiptModel::evidence`) · $kind
 */
$ev      = $ev ?? [];
$kind    = (string) ($kind ?? ($ev['kind'] ?? ''));
$item    = $ev['item'] ?? null;
$levels  = $ev['levels'] ?? [];
$samples = $ev['samples'] ?? [];
$pairs   = $ev['pairs'] ?? [];

$pct = static fn (?float $v): string => $v === null ? '—' : number_format($v * 100, 0) . '٪';

/** الأساس: أيقونة + كلمتين — مرة واحدة بس فوق. */
$basisChip = static function (?string $b, float $rate): string {
    $n = rtrim(rtrim(number_format($rate, 2), '0'), '.');
    return match ($b) {
        'per_car'       => '<span class="rw-basis">' . icon('truck', 14) . ' إيصال لكل سيارة'
                         . ($rate != 1 ? ' ×' . $n : '') . '</span>',
        'per_container' => '<span class="rw-basis">' . icon('containers', 14) . ' إيصال لكل حاوية'
                         . ($rate != 1 ? ' ×' . $n : '') . '</span>',
        'fixed'         => '<span class="rw-basis fix">' . icon('clipboard', 14) . ' عدد ثابت للإذن'
                         . ($rate > 1 ? ' ×' . $n : '') . '</span>',
        default         => '',
    };
};
?>
<?php if ($item !== null): ?>
<div class="rw-top">
  <?php if ($item['qty_expected'] !== null && !empty($item['qty_trusted'])): ?>
    <span class="rw-stat lead"><b><?= intf((int) $item['qty_entered']) ?></b>
      <em>من</em><b><?= intf((int) $item['qty_expected']) ?></b><i>مُدخَل من المتوقّع</i></span>
  <?php elseif ($item['p'] !== null): ?>
    <span class="rw-stat lead"><b><?= e($pct((float) $item['p'])) ?></b><i>احتمال وجوده</i></span>
  <?php endif; ?>

  <?php if ($item['day'] !== null): ?>
    <span class="rw-stat"><b><?= (int) $item['day'] === 0 ? 'نفس اليوم' : intf((int) $item['day']) ?></b><i><?= (int) $item['day'] === 0 ? 'بيتسجّل مع الإذن' : 'يوم بعد الإذن عادةً' ?></i></span>
  <?php endif; ?>
  <?php if (!empty($item['amount'])): ?>
    <span class="rw-stat"><b><?= money((float) $item['amount']) ?></b><i>المبلغ المعتاد</i></span>
  <?php endif; ?>

  <span class="rw-top-tags">
    <?php if (!empty($item['qty_trusted'])): ?>
      <?= $basisChip((string) $item['qty_basis'], (float) $item['qty_rate']) ?>
    <?php elseif ($item['qty_basis'] !== null): ?>
      <span class="rw-warn"><?= icon('alert', 13) ?> العدد بيتسجّل بأشكال مختلفة — مفيش رقم مؤكّد</span>
    <?php endif; ?>
  </span>
</div>
<?php endif; ?>

<?php if ($pairs): ?>
<div class="rw-pairs">
  <?php foreach (array_slice($pairs, 0, 3) as $pr): ?>
    <span class="rw-pair"><?= icon('shuffle', 13) ?>
      مع «<?= e((string) $pr['kind_a']) ?>» بيبقى فيه «<?= e($kind) ?>»
      <b><?= e($pct((float) $pr['p_ba'])) ?></b>
      <span class="rw-sub">من <?= intf((int) $pr['n_a']) ?> إذن</span></span>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="rw-levels">
  <?php foreach ($levels as $l): ?>
    <div class="rw-lvl<?= $l['used'] ? ' on' : '' ?><?= $l['found'] ? '' : ' off' ?>">
      <span class="rw-lvl-name"><?= e((string) $l['label']) ?></span>
      <?php if ($l['found']): ?>
        <span class="rw-lvl-bar"><span style="width:<?= max(2, (int) round((float) $l['prob'] * 100)) ?>%"></span></span>
        <span class="rw-lvl-p"><?= e($pct($l['prob'])) ?></span>
        <span class="rw-lvl-hits"><?= intf((int) $l['hits']) ?> من <?= intf((int) $l['n']) ?></span>
      <?php else: ?>
        <span class="rw-lvl-none">مفيش سابقة</span>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php partial('drill', [
    /* السطر ده بلغة المالك — بلا مصطلح إحصائي واحد. */
    'how'   => $item !== null && !empty($item['scope_label'])
        ? 'الرقم اتحسب من الأذون المشابهة على مستوى ' . $item['scope_label']
        : null,
    'count' => count($samples),
    'total' => $item !== null && $item['n_permits'] !== null
        ? ['label' => $kind, 'value' => intf((int) $item['n_hits']) . ' من ' . intf((int) $item['n_permits'])]
        : null,
    'empty' => ['title' => 'مفيش أذون مقفولة مشابهة لسه'],
    'more'  => ['label' => 'اللي النظام اتعلّمه', 'url' => url('receipt-model')],
    'body'  => function () use ($samples) {
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
