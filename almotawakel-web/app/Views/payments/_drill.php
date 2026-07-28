<?php
/**
 * جسم نافذة تفاصيل تحصيلات العملاء — المرحلة ١٤.
 *
 * ⚠️ بيقرا من `ClientPayment::all()` نفسها اللي السجل بيتبنى منها، **وبنفس
 *    الفلاتر** — لو النافذة جابت الكل والشاشة مفلترة يبقى الرقم كذب.
 *
 * متغيّرات: $what $rows $summary $visaCards
 */
$cardName = [];
foreach ($visaCards as $vc) {
    $cardName[(int) $vc['id']] = $vc['name'] . ($vc['owner'] ? ' — ' . $vc['owner'] : '');
}
$br = $summary['by_route'];
[$howText, $totalLabel, $totalValue] = match ($what) {
    'cash'  => ['التحصيلات اللي المالك وجّهها **للعهدة النقدية**: بتولّد إضافة عهدة بتاريخ الدفعة فتزوّد خزنة اليوم وتدخل التقفيل.',
                'عهدة نقدية', $br['cash']['value']],
    'visa'  => ['التحصيلات اللي اتوجّهت **لشحن كارت فيزا**: بتولّد شحنة على الكارت المختار فتزوّد رصيده — والخزنة ما بتتأثرش.',
                'شحن فيزا', $br['visa']['value']],
    'none'  => ['تحصيلات اتسجّلت في حساب العميل بس — **بلا أي توجيه**: لا عهدة ولا كارت، بتقلّل المتبقّي على العميل وخلاص.',
                'بدون توجيه', $br['none']['value']],
    default => ['كل التحصيلات اللي العملاء سلّموها للشركة على الفلاتر المعروضة. التوجيه بيحدّد الفلوس راحت فين بعد ما اتسلّمت.',
                'إجمالي المحصّل', $summary['grand']],
};
?>
<?php partial('drill', [
    'how'   => str_replace('**', '', $howText),
    'count' => count($rows),
    'total' => ['label' => $totalLabel, 'value' => money($totalValue, false)],
    'more'  => ['label' => 'سجل التحصيلات', 'url' => url('payments')],
    'empty' => ['title' => 'مفيش تحصيلات في النطاق ده'],
    'body'  => function () use ($rows, $cardName) {
        foreach ($rows as $pm) {
            [$ic, $routeTxt] = match ($pm['route']) {
                'cash' => ['wallet', 'عهدة نقدية'],
                'visa' => ['credit-card', $cardName[(int) $pm['visa_card_id']] ?? 'فيزا'],
                default => ['file-text', 'بدون توجيه'],
            };
            $meta = [
                ['icon' => 'calendar', 'text' => $pm['pay_date'] ? dfmt($pm['pay_date']) : '—'],
                ['icon' => 'layers', 'text' => $routeTxt],
            ];
            if ($pm['status'] === 'posted' && (float) $pm['unallocated_amount'] > 0) {
                $meta[] = ['icon' => 'alert', 'text' => 'غير مخصص ' . money($pm['unallocated_amount'], false)];
            }
            if ($pm['note']) { $meta[] = ['icon' => 'file-text', 'text' => $pm['note']]; }
            partial('row_item', [
                'title'  => $pm['client_name'],
                'icon'   => $ic,
                'meta'   => $meta,
                'chips'  => $pm['status'] === 'reversed'
                    ? [['icon' => 'alert', 'text' => 'معكوس']] : [],
                'amount' => money($pm['amount'], false),
                'amount_tone' => $pm['status'] === 'reversed' ? '' : 'pos',
            ]);
        }
    },
]); ?>
