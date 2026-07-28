<?php
/**
 * تفاصيل أرقام التقارير — بيترندر **بلا layout** جوّه النافذة العامة `#uiDrill`.
 *
 * ⚠️ بيرجّع **HTML مش JSON** عن قصد: عشان الصفوف تستخدم نفس مكوّن `row_item`
 *    بتاع الشاشة، فالمالك ما يحسّش إنه دخل مكان تاني (قرار المرحلة ١٠).
 *
 * متغيّرات: $what $rows $totals $from $to
 */
$cur = \App\Core\App::setting('currency', 'ج.م');

/** نطاق الفترة كنص — بيتحطّ في سطر «إزاي اتحسب الرقم ده». */
$span = ($from !== '' || $to !== '')
    ? (($from !== '' ? dfmt($from) : 'البداية') . ' ← ' . ($to !== '' ? dfmt($to) : 'النهاية'))
    : 'كل الفترات';

/** رابط بروفايل العميل بنفس النطاق. */
$prof = static fn (string $n): string => url('reports/client?name=' . urlencode($n));

$M = [
    'freight' => [
        'how'   => 'النولون حسب الشركة في ' . $span . '.',
        'total' => ['label' => 'إجمالي النولون', 'value' => money0($totals['freight'], false) . ' ' . $cur],
        'empty' => ['title' => 'مفيش نولون في الفترة دي'],
        'more'  => ['label' => 'كل العملاء', 'url' => url('reports?tab=customers'
                    . ($from !== '' ? '&from=' . e($from) : '') . ($to !== '' ? '&to=' . e($to) : ''))],
    ],
    'custody' => [
        'how'   => 'العهد المدفوعة للسواقين، مقسّمة على العملاء في ' . $span . '.',
        'total' => ['label' => 'إجمالي العهد', 'value' => money0($totals['custody'], false) . ' ' . $cur],
        'empty' => ['title' => 'مفيش عهد في الفترة دي'],
        'more'  => ['label' => 'كل العملاء', 'url' => url('reports?tab=customers')],
    ],
    'expenses' => [
        'how'   => 'المصروفات مقسّمة على بنودها في ' . $span . '.',
        'total' => ['label' => 'إجمالي المصروفات', 'value' => money0($totals['expenses'], false) . ' ' . $cur],
        'empty' => ['title' => 'مفيش مصروفات في الفترة دي'],
        'more'  => ['label' => 'سجل المصروفات', 'url' => url('expenses')],
    ],
    'collections' => [
        'how'   => 'الدفعات الصادرة للعملاء في ' . $span . '.',
        'total' => ['label' => 'إجمالي المدفوعات', 'value' => money0($totals['collections'], false) . ' ' . $cur],
        'empty' => ['title' => 'مفيش مدفوعات في الفترة دي'],
        'more'  => ['label' => 'كل المدفوعات', 'url' => url('reports?tab=payments')],
    ],
    'outstanding' => [
        'how'   => 'المتبقّي لكل عميل — إجمالي تعامله ناقص اللي اتدفع له.',
        'total' => ['label' => 'إجمالي المتبقّي', 'value' => money0($totals['outstanding'], false) . ' ' . $cur],
        'empty' => ['title' => 'مفيش متبقّي لأي عميل'],
        'more'  => ['label' => 'المديونيات', 'url' => url('debts')],
    ],
][$what];

partial('drill', [
    'how'        => $M['how'],
    'total'      => $M['total'],
    'more'       => $M['more'],
    'count'      => count($rows),
    'empty'      => $M['empty'],
    'body'       => function () use ($what, $rows, $prof) {
        foreach ($rows as $r) {
            /* كل نوع له شكل صف مختلف — بس كلهم `row_item` واحد. */
            if ($what === 'freight') {
                partial('row_item', [
                    'title'  => $r['name'], 'icon' => 'building', 'url' => $prof($r['name']),
                    'amount' => money0($r['freight'], false), 'sub' => 'نولون',
                ]);
            } elseif ($what === 'custody') {
                partial('row_item', [
                    'title'  => $r['name'], 'icon' => 'wallet', 'url' => $prof($r['name']),
                    'meta'   => [intf($r['trips']) . ' حركة'],
                    'amount' => money0($r['custody'], false), 'amount_tone' => 'rc', 'sub' => 'عهد',
                ]);
            } elseif ($what === 'expenses') {
                /* `cnt` بيبقى null للبنود المجمّعة (تخليص/أذونات/يدوي) — مالهاش عدّاد مرات */
                partial('row_item', [
                    'title'  => $r['label'], 'icon' => 'trending-down',
                    'meta'   => $r['cnt'] !== null ? [intf($r['cnt']) . ' مرة'] : [],
                    'amount' => money0($r['value'], false), 'amount_tone' => 'ex',
                ]);
            } elseif ($what === 'collections') {
                partial('row_item', [
                    'title'  => $r['party'] !== '' ? $r['party'] : 'بلا جهة',
                    'icon'   => 'banknote',
                    'ref'    => $r['bill_number'] !== '' ? 'بوليصة ' . $r['bill_number'] : null,
                    'meta'   => array_values(array_filter([dfmt($r['d']), $r['note'] ?: null])),
                    'amount' => money0($r['amount'], false), 'amount_tone' => 'pos',
                ]);
            } else { // outstanding
                partial('row_item', [
                    'title'  => $r['name'], 'icon' => 'user', 'url' => $prof($r['name']),
                    'meta'   => ['تعامل ' . money0($r['invoices'], false)],
                    'amount' => money0($r['remaining'], false),
                    'amount_tone' => $r['remaining'] > 0 ? 'ex' : null, 'sub' => 'متبقّي',
                ]);
            }
        }
    },
]);
