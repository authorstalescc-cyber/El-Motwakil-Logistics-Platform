<?php
/**
 * أجسام نوافذ تفاصيل كروت الفيزا — المرحلة ١٤.
 *
 * ⚠️ بترندر بلا layout وبترجع HTML للنافذة العامة (`#uiDrill`)، وبتقرا من نفس
 *    دوال `VisaCard` اللي أرقام الشاشة اتحسبت منها — فمستحيل الرقم يختلف.
 *
 * متغيّرات: $what $cards $summary $byOwner
 */
$cardRow = static function (array $c, string $show): void {
    $meta = [];
    if ($c['owner']) { $meta[] = ['icon' => 'user', 'text' => $c['owner']]; }
    if ($c['bank'])  { $meta[] = ['icon' => 'home', 'text' => $c['bank']]; }
    $meta[] = ['icon' => 'wallet', 'text' => 'شحن ' . money($c['dep_total'] + $c['opening_balance'], false)];
    $meta[] = ['icon' => 'trending-down', 'text' => 'مدفوع ' . money($c['pay_total'], false)];
    $amount = match ($show) {
        'deposits' => money($c['dep_total'] + $c['opening_balance'], false),
        'payments' => money($c['pay_total'], false),
        default    => money($c['balance'], false),
    };
    partial('row_item', [
        'title'  => $c['name'],
        'icon'   => 'credit-card',
        'url'    => url('office/' . (int) $c['id']),
        'meta'   => $meta,
        'chips'  => $c['status'] === 'inactive' ? [['icon' => 'clock', 'text' => 'متوقف']] : [],
        'amount' => $amount,
        'amount_tone' => $show === 'payments' ? 'ex' : ($c['balance'] < 0 ? 'neg' : 'pos'),
    ]);
};

switch ($what) {

case 'deposits':
    partial('drill', [
        'how'   => 'كل شحنة اتحطّت على أي كارت — الأرصدة المبدئية القديمة اتحوّلت لشحنات مؤرَّخة كمان.',
        'count' => count($cards),
        'total' => ['label' => 'إجمالي الشحنات', 'value' => money($summary['deposits'] + $summary['opening'], false)],
        'more'  => ['label' => 'كل الكروت', 'url' => url('office')],
        'empty' => ['title' => 'مفيش كروت فيزا لسه'],
        'body'  => function () use ($cards, $cardRow) { foreach ($cards as $c) { $cardRow($c, 'deposits'); } },
    ]);
    break;

case 'payments':
    partial('drill', [
        'how'   => 'المدفوع بالفيزا = كل عملية معلَّمة «💳 مدفوع بالفيزا» ومنسوبة لكارت — مصروفات وبنود أذونات وبنود حركات وسداد مديونيات وبنود المناديب.',
        'count' => count($cards),
        'total' => ['label' => 'إجمالي المدفوع', 'value' => money($summary['payments'], false)],
        'more'  => ['label' => 'تتبع الفيزا', 'url' => url('debts?tab=visa')],
        'empty' => ['title' => 'مفيش كروت فيزا لسه'],
        'body'  => function () use ($cards, $cardRow) { foreach ($cards as $c) { $cardRow($c, 'payments'); } },
    ]);
    break;

case 'owners':
    partial('drill', [
        'how'   => 'الأرصدة مجمّعة على صاحب الكارت — مفيدة لما يكون لصاحب واحد أكتر من كارت.',
        'count' => count($byOwner),
        'total' => ['label' => 'إجمالي الأرصدة', 'value' => money($summary['balance'], false)],
        'more'  => ['label' => 'كل الكروت', 'url' => url('office')],
        'empty' => ['title' => 'مفيش أصحاب كروت مسجَّلين'],
        'body'  => function () use ($byOwner) {
            foreach ($byOwner as $o) {
                partial('row_item', [
                    'title'  => $o['owner'],
                    'icon'   => 'user',
                    'meta'   => [['icon' => 'credit-card', 'text' => intf($o['cards']) . ' كارت']],
                    'amount' => money($o['balance'], false),
                    'amount_tone' => $o['balance'] < 0 ? 'neg' : 'pos',
                ]);
            }
        },
    ]);
    break;

default:   // balance
    partial('drill', [
        'how'   => 'رصيد الكارت = الشحنات − المدفوع بالفيزا المنسوب له. الكارت معزول عن خزنة المكتب: المدفوع بالفيزا ما بيتخصمش من الخزنة ولا من التقفيل اليومي.',
        'count' => count($cards),
        'total' => ['label' => 'إجمالي الأرصدة المتاحة', 'value' => money($summary['balance'], false)],
        'more'  => ['label' => 'كل الكروت', 'url' => url('office')],
        'empty' => ['title' => 'مفيش كروت فيزا لسه', 'note' => 'ضيف أول كارت عشان تتابع رصيده ومدفوعاته.'],
        'body'  => function () use ($cards, $cardRow) { foreach ($cards as $c) { $cardRow($c, 'balance'); } },
    ]);
}
