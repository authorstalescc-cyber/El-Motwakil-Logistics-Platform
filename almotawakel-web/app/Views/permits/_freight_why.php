<?php
/**
 * نافذة «النولون المتوقّع جاي منين؟» (v102).
 *
 * ⚠️ قاعدة «ممنوع رقم أصمّ»: السعر المعروض في الكارت الجانبي لازم يُضغط
 *    ويوري **سطر التعريفة اللي طابق بالحرف** و**الفواتير السابقة بالاسم**.
 *
 * متغيّرات: $q (ناتج `FreightQuote::forPermit`) · $samples (فواتير سابقة)
 */
use App\Models\FreightTariff;

$q       = $q ?? [];
$samples = $samples ?? [];
$t       = $q['tariff']  ?? null;
$h       = $q['history'] ?? null;
?>
<div class="fw-top">
  <?php if ($t !== null): ?>
    <div class="fw-box">
      <span class="fw-box-h"><?= icon('clipboard', 14) ?> قائمة الأسعار</span>
      <b><?= money((float) $t['price'], false) ?></b>
      <span class="fw-box-m"><?= e($t['unit_label']) ?></span>
      <span class="fw-box-m"><?= e($t['port_label']) ?></span>
      <span class="fw-box-m">سطر: <?= e($t['zone']) ?></span>
      <?php if ($t['how'] !== 'exact'): ?>
        <span class="fw-box-w"><?= icon('alert', 12) ?>
          <?= $t['how'] === 'near' ? 'أقرب جهة — مش مطابقة بالحرف' : 'الجهة جزء من السطر' ?></span>
      <?php endif; ?>
      <?php if ($t['genset'] !== null): ?>
        <span class="fw-box-m">نقل <?= money((float) $t['base'], false) ?>
          + مولّد <?= money((float) $t['genset'], false) ?></span>
      <?php endif; ?>
      <?php if (!empty($t['surcharge'])): ?>
        <span class="fw-box-m"><?= e((string) $t['surcharge']['label']) ?>
          + <?= money((float) $t['surcharge']['amount'], false) ?></span>
      <?php endif; ?>
      <?php if (!empty($t['source_ref'])): ?>
        <span class="fw-box-m">مرجع: <?= e((string) $t['source_ref']) ?></span>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($h !== null): ?>
    <div class="fw-box on">
      <span class="fw-box-h"><?= icon('receipt', 14) ?> المعتاد فعليًّا</span>
      <b><?= money((float) $h['price'], false) ?></b>
      <span class="fw-box-m">مطابقة: <?= e((string) $h['scope']) ?></span>
      <span class="fw-box-m"><?= intf((int) $h['hits']) ?> من <?= intf((int) $h['total']) ?> فاتورة</span>
      <?php if (!empty($h['last'])): ?>
        <span class="fw-box-m">آخر مرة <?= dfmt($h['last']) ?></span>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($q['gap'] ?? null): ?>
  <div class="fw-gap"><?= icon('alert', 14) ?>
    الفرق <b><?= e(($q['gap']['pct'] > 0 ? '+' : '') . number_format((float) $q['gap']['pct'], 1)) ?>٪</b>
    (<?= money(abs((float) $q['gap']['diff']), false) ?>) بين القايمة واللي بيتحاسب فعلاً
  </div>
<?php endif; ?>

<?php partial('drill', [
    'how'   => $t !== null
        ? 'قائمة ' . $t['port_label'] . ' · ' . $t['unit_label']
        : ($h !== null ? 'من فواتير سابقة لنفس السياق' : null),
    'count' => count($samples),
    'total' => $q['cars'] ?? null
        ? ['label' => 'الإجمالي المتوقّع', 'value' => money((float) $q['total'], false)
             . ' (' . intf((int) $q['cars']) . ' سيارة)']
        : null,
    'empty' => ['title' => 'مفيش فواتير سابقة لنفس السياق'],
    'more'  => ['label' => 'قائمة الأسعار', 'url' => url('tariffs')],
    'body'  => function () use ($samples) {
        foreach ($samples as $s) {
            partial('row_item', [
                'title' => trim((string) ($s['doc_no'] ?? '')) !== ''
                    ? (string) $s['doc_no'] : ('#' . (int) $s['id']),
                'ref'   => !empty($s['date']) ? dfmt($s['date']) : null,
                'icon'  => 'receipt',
                'meta'  => array_values(array_filter([
                    trim((string) ($s['destination'] ?? '')) !== ''
                        ? ['icon' => 'map-pin', 'text' => (string) $s['destination']] : null,
                    trim((string) ($s['container_type'] ?? '')) !== ''
                        ? ['icon' => 'package', 'text' => (string) $s['container_type']] : null,
                    ((float) ($s['qty'] ?? 0)) > 0
                        ? ['icon' => 'truck', 'text' => intf((int) $s['qty']) . ' سيارة'] : null,
                ])),
                'amount'      => money((float) $s['price'], false),
                'amount_tone' => 'rc',
            ]);
        }
    },
]); ?>
