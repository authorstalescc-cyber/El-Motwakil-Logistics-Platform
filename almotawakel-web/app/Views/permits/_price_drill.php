<?php
/**
 * نافذة «سعر السيارة ده جاي منين؟» — فواتير العميل السابقة وأسعارها (v98).
 *
 * ⚠️ **قاعدة المالك الدائمة: ممنوع رقم أصمّ.** ودي فلوس، فالإثبات أهم:
 *    «٣ من ٤» بتُضغَط فتوري الأربع فواتير بأرقامها، والمطابق منها **بيتلوّن**
 *    عشان المالك يعدّهم بعينه بدل ما ياخدها كلامًا.
 *
 * ⚠️ الرقم المعروض هو **سعر الوحدة اللي المالك كتبه بإيده** في كل فاتورة
 *    (`items_json` ← بند النولون)، مش ناتج قسمة — فالمقارنة عادلة.
 */
$hit     = $hit ?? null;
$samples = $samples ?? [];
$match   = $hit !== null ? (float) $hit['value'] : null;
?>
<?php partial('drill', [
    'how'   => $hit !== null
        ? 'سعر السيارة المعتاد لهذا ' . (string) $hit['scope'] . ': ' . money((float) $hit['value'], false)
        : null,
    'count' => count($samples),
    'total' => $hit !== null
        ? ['label' => 'تكرار السعر', 'value' => $hit['hits'] . ' من ' . $hit['total']]
        : null,
    'empty' => ['title' => 'مفيش فواتير سابقة للعميل ده'],
    'more'  => ['label' => 'كل المستندات', 'url' => url('invoices')],
    'body'  => function () use ($samples, $match) {
        foreach ($samples as $s) {
            $price = (float) ($s['price'] ?? 0);
            /* ⚠️ **`rc` مش `pos`**: `.d-feed-side b` معرِّف `ex` و`rc` بس،
               فـ`pos` بتتطبع كلاسًا بلا شكل وتقع على الافتراضي بالسكوت. */
            $same = $match !== null && abs($price - $match) < 0.005;
            $qty  = (float) ($s['qty'] ?? 0);
            partial('row_item', [
                'title' => trim((string) ($s['doc_no'] ?? '')) !== ''
                    ? (string) $s['doc_no'] : '#' . (int) $s['id'],
                'ref'   => $s['date'] ? dfmt($s['date']) : null,
                'icon'  => 'receipt',
                'meta'  => array_values(array_filter([
                    trim((string) ($s['permit_no'] ?? '')) !== ''
                        ? ['icon' => 'clipboard', 'text' => (string) $s['permit_no']] : null,
                    trim((string) ($s['destination'] ?? '')) !== ''
                        ? ['icon' => 'map-pin', 'text' => (string) $s['destination']] : null,
                    trim((string) ($s['container_type'] ?? '')) !== ''
                        ? ['icon' => 'package', 'text' => (string) $s['container_type']] : null,
                ])),
                'amount'      => money($price, false),
                'amount_tone' => $same ? 'rc' : '',
                /* الكمية **بيانات** مش شرح — `row_item.sub` مستثنى عن قصد في
                   `verify_density` لأنه بيسمّي وحدة الرقم اللي جنبه. */
                'sub'         => $qty > 0 ? intf($qty) . ' سيارة' : null,
            ]);
        }
    },
]); ?>
