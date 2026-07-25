<?php
/**
 * أجسام نوافذ التفاصيل لعهدة المناديب.
 *
 * ⚠️ بترندر **بلا layout** وبترجع HTML للنافذة العامة (`#uiDrill`). كل صف
 *    بيستخدم مكوّن `row_item` نفسه بتاع الصفحة، فالتفاصيل شكلها زي القوايم.
 *
 * متغيّرات: $what $day $rows (+ $rep للأنواع اللي على مندوب واحد)
 */
$dayTxt = dfmt($day);

/* ⚠️ صفّا «صرف مندوب» و«تنزيل» مشتركان بين الشاشة العامة وكارت المندوب —
   نسخة واحدة عشان الشكل ما يفترقش بين النافذتين. */
$spendRow = static function (array $s, bool $withRep = true): void {
    $src = $s['src'] ?? 'permit';
    $isReceipt = $src === 'permit' && ($s['stage_key'] ?? '') === 'receipts';
    [$ic, $kind] = match ($src) {
        'misc'     => ['coins', 'نثرية'],
        'handover' => ['truck', 'تسليم سائق'],
        'transfer' => ['shuffle', 'تحويل لزميل'],
        default    => $isReceipt ? ['clipboard', 'إيصال'] : ['trending-down', 'مصروف إذن'],
    };
    $meta = [];
    if ($withRep && !empty($s['rep_name'])) { $meta[] = ['icon' => 'user', 'text' => $s['rep_name']]; }
    $meta[] = ['icon' => 'layers', 'text' => $kind];
    if (!empty($s['permit_no'])) { $meta[] = ['icon' => 'folder', 'text' => 'إذن ' . $s['permit_no']]; }
    if (!empty($s['ref_no'])) { $meta[] = ['icon' => 'clipboard', 'text' => 'إيصال ' . $s['ref_no']]; }
    if ((int) ($s['qty'] ?? 1) > 1) { $meta[] = ['icon' => 'package', 'text' => 'كمية ' . intf((int) $s['qty'])]; }
    if (!empty($s['is_visa'])) { $meta[] = ['icon' => 'credit-card', 'text' => 'فيزا — مش من العهدة']; }
    partial('row_item', [
        'title'  => $s['note'] ?: $kind,
        'icon'   => $ic,
        'url'    => !empty($s['permit_id']) ? url('permits/' . (int) $s['permit_id']) : null,
        'meta'   => $meta,
        'amount' => money($s['amount'], false),
        'amount_tone' => empty($s['is_visa']) ? 'ex' : '',
        'sub'    => !empty($s['d']) ? dfmt($s['d']) : null,
    ]);
};

/* ─── أرقام صندوق المراجعة — على كل الأيام مش على يوم العمل ─── */
if (str_starts_with($what, 'inbox_')) {
    $pendRow = static function (array $p): void {
        $kind = match ((string) $p['kind']) {
            'receipt' => ['clipboard', 'إيصال'],
            'misc'    => ['coins', 'نثرية'],
            default   => ['trending-down', 'مصروف'],
        };
        $locked = \App\Models\Closing::isLocked((string) $p['entry_date']);
        $meta = [['icon' => 'user', 'text' => $p['rep_name']],
                 ['icon' => 'layers', 'text' => $kind[1]],
                 ['icon' => 'calendar', 'text' => dfmt($p['entry_date'])]];
        if (!empty($p['permit_no'])) { $meta[] = ['icon' => 'folder', 'text' => 'إذن ' . $p['permit_no']]; }
        partial('row_item', [
            'title'  => $p['note'] ?: $kind[1],
            'icon'   => $kind[0],
            'url'    => url('rep-custody/inbox'),
            'meta'   => $meta,
            'chips'  => $locked ? [['icon' => 'alert', 'text' => 'يوم متقفل — اعتماده هيترفض']] : [],
            'amount' => money($p['amount'], false),
            'amount_tone' => $locked ? 'neg' : 'wait',
        ]);
    };
    if ($what === 'inbox_reps') {
        $byRep = [];
        foreach ($rows as $r) {
            $rid = (int) $r['rep_id'];
            $byRep[$rid] ??= ['name' => (string) $r['rep_name'], 'cnt' => 0, 'sum' => 0.0];
            $byRep[$rid]['cnt']++;
            $byRep[$rid]['sum'] += (float) $r['amount'];
        }
        uasort($byRep, static fn ($a, $b) => $b['sum'] <=> $a['sum']);
        partial('drill', [
            'how'   => 'المناديب اللي عندهم بنود مستنية مراجعتك — على كل الأيام مش على يوم العمل بس.',
            'count' => count($byRep),
            'total' => ['label' => 'إجمالي المستنّي', 'value' => money(array_sum(array_column($byRep, 'sum')), false)],
            'more'  => ['label' => 'عهدة المناديب', 'url' => url('rep-custody')],
            'empty' => ['title' => 'مفيش بنود مستنية من أي مندوب'],
            'body'  => function () use ($byRep) {
                foreach ($byRep as $rid => $r) {
                    partial('row_item', [
                        'title'  => $r['name'],
                        'icon'   => 'user',
                        'url'    => url('rep-custody/' . $rid . '?tab=day'),
                        'meta'   => [['icon' => 'hourglass', 'text' => intf($r['cnt']) . ' بند مستني']],
                        'amount' => money($r['sum'], false),
                        'amount_tone' => 'wait',
                    ]);
                }
            },
        ]);
        return;
    }
    $isLockedList = ($what === 'inbox_locked');
    partial('drill', [
        'how'   => $isLockedList
            ? 'البنود دي تاريخها في يوم متقفل، فاعتمادها هيترفض. غيّر تاريخ البند أو افتح إقفال يومه الأول.'
            : 'كل البنود اللي بعتها المناديب من موبايلاتهم ولسه مستنية اعتمادك — على كل الأيام.',
        'count' => count($rows),
        'total' => ['label' => $isLockedList ? 'على أيام متقفلة' : 'مستني مراجعتك',
                    'value' => money(array_sum(array_column($rows, 'amount')), false)],
        'more'  => ['label' => $isLockedList ? 'شاشة التقفيل' : 'صندوق المراجعة',
                    'url' => url($isLockedList ? 'closing' : 'rep-custody/inbox')],
        'empty' => ['title' => $isLockedList ? 'مفيش بند على يوم متقفل — كله تمام' : 'مفيش بنود مستنية'],
        'body'  => function () use ($rows, $pendRow) { foreach ($rows as $p) { $pendRow($p); } },
    ]);
    return;
}

/* ─── أنواع كارت المندوب (بتاخد `$rep`) ─── */
if (str_starts_with($what, 'rep_')) {
    $name = (string) $rep['name'];
    $repUrl = url('rep-custody/' . (int) $rep['id'] . '?tab=day');
    $sum = static fn (array $rs): string => money(array_sum(array_column($rs, 'amount')), false);

    if ($what === 'rep_deposits') {
        partial('drill', [
            'how'   => 'العهدة اللي نزلت لـ' . $name . ' في يوم ' . $dayTxt . '. تنزيل العهدة ما بيخصمش من خزنة المكتب.',
            'count' => count($rows),
            'total' => ['label' => 'نزل له اليوم', 'value' => $sum($rows)],
            'more'  => ['label' => 'كارت المندوب', 'url' => $repUrl],
            'empty' => ['title' => 'مفيش عهدة نزلت له في ' . $dayTxt],
            'body'  => function () use ($rows) {
                foreach ($rows as $d) {
                    partial('row_item', [
                        'title'  => $d['note'] ?: 'تنزيل عهدة',
                        'icon'   => 'wallet',
                        'meta'   => [['icon' => 'calendar', 'text' => dfmt($d['dep_date'])]],
                        'amount' => money($d['amount'], false),
                        'amount_tone' => 'pos',
                    ]);
                }
            },
        ]);
        return;
    }

    if ($what === 'rep_pending') {
        partial('drill', [
            'how'   => 'بنود بعتها ' . $name . ' من موبايله في ' . $dayTxt . ' ولسه مستنية اعتمادك. الفلوس خرجت من إيده، لكنها ما دخلتش أي حساب لحد ما تعتمدها.',
            'count' => count($rows),
            'total' => ['label' => 'مستني مراجعتك', 'value' => $sum($rows)],
            'more'  => ['label' => 'صندوق المراجعة', 'url' => url('rep-custody/inbox')],
            'empty' => ['title' => 'مفيش بنود معلّقة له في ' . $dayTxt],
            'body'  => function () use ($rows) {
                foreach ($rows as $p) {
                    $kind = match ((string) $p['kind']) {
                        'receipt' => ['clipboard', 'إيصال'],
                        'misc'    => ['coins', 'نثرية'],
                        default   => ['trending-down', 'مصروف'],
                    };
                    $meta = [['icon' => 'layers', 'text' => $kind[1]]];
                    if (!empty($p['permit_no'])) { $meta[] = ['icon' => 'folder', 'text' => 'إذن ' . $p['permit_no']]; }
                    if (!empty($p['photo'])) { $meta[] = ['icon' => 'clipboard', 'text' => 'مرفق صورة']; }
                    partial('row_item', [
                        'title'  => $p['note'] ?: $kind[1],
                        'icon'   => $kind[0],
                        'url'    => url('rep-custody/inbox'),
                        'meta'   => $meta,
                        'amount' => money($p['amount'], false),
                        'amount_tone' => 'wait',
                    ]);
                }
            },
        ]);
        return;
    }

    $howMap = [
        'rep_spent'     => 'كل اللي صرفه ' . $name . ' من عهدته في ' . $dayTxt . '.',
        'rep_receipts'  => 'إيصالات الأذونات اللي دفعها ' . $name . ' في ' . $dayTxt . '.',
        'rep_expenses'  => 'مصروفات الأذونات (مش إيصالات) اللي دفعها ' . $name . ' في ' . $dayTxt . '.',
        'rep_misc'      => 'نثريات ' . $name . ' في ' . $dayTxt . ' — مصروف شركة بلا إذن، ما بيدخلش أي حساب عميل.',
        'rep_handover'  => 'عهد سلّمها ' . $name . ' لسائقين في ' . $dayTxt . ' — بتنقص من عهدته وبس.',
        'rep_transfer'  => 'كاش حوّله ' . $name . ' لزملاء في ' . $dayTxt . ' — بينتقل من محفظة لمحفظة والإجمالي ثابت.',
        'rep_visa'      => 'بنود ' . $name . ' المدفوعة بكارت الفيزا في ' . $dayTxt . ' — اتخصمت من الكارت مش من عهدته.',
    ];
    partial('drill', [
        'how'   => $howMap[$what] ?? '',
        'count' => count($rows),
        'total' => ['label' => 'الإجمالي', 'value' => $sum($rows)],
        'more'  => ['label' => 'كارت المندوب', 'url' => $repUrl],
        'empty' => ['title' => 'مفيش بنود من النوع ده في ' . $dayTxt],
        'body'  => function () use ($rows, $spendRow) {
            foreach ($rows as $s) { $spendRow($s, false); }
        },
    ]);
    return;
}

switch ($what) {

case 'deposits':
    partial('drill', [
        'how'   => 'مجموع كل عهدة نزلت للمناديب بتاريخ ' . $dayTxt . '. تنزيل العهدة ما بيخصمش من خزنة المكتب — الخزنة بتنقص وقت ما المندوب يصرف فعلاً.',
        'count' => count($rows),
        'total' => ['label' => 'نزل اليوم', 'value' => money(array_sum(array_column($rows, 'amount')), false)],
        'more'  => ['label' => 'كل المناديب', 'url' => url('rep-custody')],
        'empty' => ['title' => 'مفيش عهدة نزلت في ' . $dayTxt, 'note' => 'نزّل عهدة لأي مندوب من كارته.'],
        'body'  => function () use ($rows) {
            foreach ($rows as $r) {
                partial('row_item', [
                    'title'  => $r['rep_name'],
                    'icon'   => 'wallet',
                    'url'    => url('rep-custody/' . (int) $r['rep_id'] . '?tab=day'),
                    'meta'   => [['icon' => 'file-text', 'text' => $r['note'] ?: 'بلا ملاحظة']],
                    'amount' => money($r['amount'], false),
                    'amount_tone' => 'pos',
                ]);
            }
        },
    ]);
    break;

case 'spent':
    partial('drill', [
        'how'   => 'كل بند اتصرف من عهدة المناديب بتاريخ ' . $dayTxt . ' — إيصالات ومصروفات أذونات ونثريات وتسليمات سائقين وتحويلات. المدفوع بالفيزا بيظهر للعلم وما بيتخصمش من العهدة.',
        'count' => count($rows),
        'total' => ['label' => 'صرفوا اليوم', 'value' => money(array_sum(array_column($rows, 'amount')), false)],
        'more'  => ['label' => 'سجل المصروفات', 'url' => url('expenses')],
        'empty' => ['title' => 'مفيش صرف في ' . $dayTxt, 'note' => 'البنود بتظهر هنا بعد اعتمادها من صندوق المراجعة.'],
        'body'  => function () use ($rows, $spendRow) {
            foreach ($rows as $s) { $spendRow($s, true); }
        },
    ]);
    break;

case 'pending':
    partial('drill', [
        'how'   => 'بنود بعتها المناديب من موبايلاتهم بتاريخ ' . $dayTxt . ' ولسه مستنية اعتمادك. الفلوس خرجت من إيدهم فعلاً، لكنها ما دخلتش أي حساب لحد ما تعتمدها.',
        'count' => count($rows),
        'total' => ['label' => 'مستني مراجعتك', 'value' => money(array_sum(array_column($rows, 'amount')), false)],
        'more'  => ['label' => 'صندوق المراجعة', 'url' => url('rep-custody/inbox')],
        'empty' => ['title' => 'مفيش بنود معلّقة في ' . $dayTxt, 'note' => 'كل اللي بعتوه اتراجع.'],
        'body'  => function () use ($rows) {
            foreach ($rows as $p) {
                $kind = match ((string) $p['kind']) {
                    'receipt' => ['clipboard', 'إيصال'],
                    'misc'    => ['coins', 'نثرية'],
                    default   => ['trending-down', 'مصروف'],
                };
                $meta = [['icon' => 'user', 'text' => $p['rep_name']], ['icon' => 'layers', 'text' => $kind[1]]];
                if (!empty($p['permit_no'])) { $meta[] = ['icon' => 'folder', 'text' => 'إذن ' . $p['permit_no']]; }
                if (!empty($p['photo'])) { $meta[] = ['icon' => 'clipboard', 'text' => 'مرفق صورة']; }
                partial('row_item', [
                    'title'  => $p['note'] ?: $kind[1],
                    'icon'   => $kind[0],
                    'url'    => url('rep-custody/inbox'),
                    'meta'   => $meta,
                    'amount' => money($p['amount'], false),
                    'amount_tone' => 'wait',
                ]);
            }
        },
    ]);
    break;

default:   // balance — الفاضل من عهدة اليوم، مندوبًا مندوبًا
    partial('drill', [
        'how'   => 'الفاضل من عهدة يوم ' . $dayTxt . ' = اللي نزل في اليوم ده − اللي اتصرف فيه. كل يوم عهدة مستقلة: الفاضل من إمبارح ما بيترحّلش على النهاردة.',
        'count' => count($rows),
        'total' => ['label' => 'فاضل من عهدة اليوم', 'value' => money(array_sum(array_column($rows, 'balance')), false)],
        'more'  => ['label' => 'كل المناديب', 'url' => url('rep-custody')],
        'empty' => ['title' => 'مفيش حركة عهدة في ' . $dayTxt, 'note' => 'مفيش مندوب نزلت له أو صرف في اليوم ده.'],
        'body'  => function () use ($rows, $day) {
            foreach ($rows as $r) {
                partial('row_item', [
                    'title'  => $r['name'],
                    'icon'   => 'briefcase',
                    'url'    => url('rep-custody/' . (int) $r['id'] . '?tab=day'),
                    'meta'   => [
                        ['icon' => 'wallet', 'text' => 'نزل ' . money($r['deposits'], false)],
                        ['icon' => 'trending-down', 'text' => 'صرف ' . money($r['spent'], false)],
                        ['icon' => 'hourglass', 'text' => 'مستني ' . money($r['pending'], false)],
                    ],
                    'amount' => money($r['balance'], false),
                    'amount_tone' => $r['balance'] < 0 ? 'neg' : 'pos',
                    'sub'    => $r['balance'] < 0 ? 'صرف أكتر من عهدة اليوم' : null,
                ]);
            }
        },
    ]);
}
