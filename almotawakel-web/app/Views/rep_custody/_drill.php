<?php
/**
 * أجسام نوافذ التفاصيل لعهدة المناديب.
 *
 * ⚠ بترندر **بلا layout** وبترجع HTML للنافذة العامة (`#uiDrill`). كل صف
 *    بيستخدم مكوّن `row_item` نفسه بتاع الصفحة، فالتفاصيل شكلها زي القوايم.
 *
 * متغيّرات: $what $day $rows (+ $rep للأنواع اللي على مندوب واحد)
 */
$dayTxt = dfmt($day);

/* <?= icon('alert', 14) ?> صفّا «صرف مندوب» و«تنزيل» مشتركان بين الشاشة العامة وكارت المندوب —
   نسخة واحدة عشان الشكل ما يفترقش بين النافذتين. */
$spendRow = static function (array $s, bool $withRep = true): void {
    $src = $s['src'] ?? 'permit';
    $isReceipt = $src === 'permit' && ($s['stage_key'] ?? '') === 'receipts';
    [$ic, $kind] = match ($src) {
        'misc'     => ['coins', 'نثرية'],
        'handover' => ['truck', 'تسليم سائق'],
        'bill'     => ['truck', 'عهدة بوليصة'],   // v60
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
    if (!empty($s['unpaid'])) { $meta[] = ['icon' => 'info', 'text' => 'غير مدفوع — مش من العهدة']; }
    partial('row_item', [
        'title'  => $s['note'] ?: $kind,
        'icon'   => $ic,
        'url'    => !empty($s['permit_id']) ? url('permits/' . (int) $s['permit_id']) : null,
        'meta'   => $meta,
        'amount' => money($s['amount'], false),
        'amount_tone' => empty($s['is_visa']) && empty($s['unpaid']) ? 'ex' : '',
        'sub'    => !empty($s['d']) ? dfmt($s['d']) : null,
    ]);
};

/** وسم البنود التي لم تخرج من عهدة المندوب وهي ما زالت في المراجعة. */
$pendingChips = static function (array $p, bool $locked = false): array {
    $chips = [];
    if ($locked) {
        $chips[] = ['icon' => 'alert', 'text' => 'يوم متقفل — اعتماده هيترفض'];
    }
    if (!empty($p['is_visa'])) {
        $chips[] = ['icon' => 'credit-card', 'text' => 'فيزا — مش من العهدة'];
    }
    if (!empty($p['unpaid'])) {
        $chips[] = ['icon' => 'info', 'text' => 'غير مدفوع — مش من العهدة'];
    }
    return $chips;
};

/* ─── أرقام صندوق المراجعة — على كل الأيام مش على يوم العمل ─── */
if (str_starts_with($what, 'inbox_')) {
    $pendRow = static function (array $p) use ($pendingChips): void {
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
            'chips'  => $pendingChips($p, $locked),
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
            'how'   => 'على كل الأيام مش يوم العمل',
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
            : 'كل البنود اللي بعتها المناديب ولسه مستنية اعتمادك — النقدي فقط يُطرح من «فاضل معاه»، والفيزا و«غير مدفوع» معلوماتيان.',
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
        /* ⚠️ **`how` اتشال** (بلاغ المالك 2026-08-09): كان بيعيد عنوان النافذة
           («العهدة اللي نزلت لـفلان في يوم كذا») وبعده محاضرة من `sourceNote()`
           عن نموذج الخزنة. العنوان بيقول النطاق، والصفوف بتقول البنود، والمجموع
           بيقول الرقم — والسطر ده كان بيشرح الشاشة لنفسها. */
        partial('drill', [
            'count' => count($rows),
            'total' => ['label' => 'نزل له اليوم', 'value' => $sum($rows)],
            'more'  => ['label' => 'كارت المندوب', 'url' => $repUrl],
            'empty' => ['title' => 'مفيش عهدة نزلت له في ' . $dayTxt],
            'body'  => function () use ($rows) {
                foreach ($rows as $d) {
                    $src  = \App\Models\RepCustody::sourceBadge($d['source'] ?? null);
                    $meta = [['icon' => 'calendar', 'text' => dfmt($d['dep_date'])]];
                    partial('row_item', [
                        'title'  => $d['note'] ?: 'تنزيل عهدة',
                        'icon'   => 'wallet',
                        'chips'  => $src ? [['icon' => $src['icon'], 'text' => $src['short']]] : [],
                        'meta'   => $src ? array_merge($meta, [['icon' => 'info', 'text' => $src['effect']]]) : $meta,
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
            'how'   => 'مطروحة مؤقتًا من «فاضل معاه»',
            'count' => count($rows),
            'total' => ['label' => 'مستني مراجعتك', 'value' => $sum($rows)],
            'more'  => ['label' => 'صندوق المراجعة', 'url' => url('rep-custody/inbox')],
            'empty' => ['title' => 'مفيش بنود معلّقة له في ' . $dayTxt],
            'body'  => function () use ($rows, $pendingChips) {
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
                        'chips'  => $pendingChips($p),
                        'amount' => money($p['amount'], false),
                        'amount_tone' => 'wait',
                    ]);
                }
            },
        ]);
        return;
    }

    /* ⚠️ **الشروح اتقصّت لحقائق وظيفية بس** (قانون التصميم بند ١): كانت جمل
       بتعيد اسم المندوب والتاريخ (وهُمّ في عنوان النافذة أصلاً) وبتشرح الشاشة
       لنفسها. اللي فضل هو اللي **لو اتشال المالك ياخد قرار غلط**: البند ده
       خصم من أنهي محفظة. واللي عنوانه بيكفيه مالوش سطر خالص. */
    $howMap = [
        'rep_misc'      => 'مصروف شركة بلا إذن',
        'rep_handover'  => 'بتنقص من عهدته وبس',
        'rep_transfer'  => 'بينتقل من محفظة لمحفظة — الإجمالي ثابت',
        'rep_visa'      => 'من الكارت مش من عهدته',
        'rep_unpaid'    => 'ما اتخصمش من أي محفظة',
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
    /* ⚠️ `how` اتشال — نفس سبب تنزيلات المندوب فوق (عنوان + محاضرة). */
    partial('drill', [
        'count' => count($rows),
        'total' => ['label' => 'نزل اليوم', 'value' => money(array_sum(array_column($rows, 'amount')), false)],
        'more'  => ['label' => 'كل المناديب', 'url' => url('rep-custody')],
        'empty' => ['title' => 'مفيش عهدة نزلت في ' . $dayTxt],
        'body'  => function () use ($rows) {
            foreach ($rows as $r) {
                $src  = \App\Models\RepCustody::sourceBadge($r['source'] ?? null);
                $meta = [['icon' => 'file-text', 'text' => $r['note'] ?: 'بلا ملاحظة']];
                partial('row_item', [
                    'title'  => $r['rep_name'],
                    'icon'   => 'wallet',
                    'url'    => url('rep-custody/' . (int) $r['rep_id'] . '?tab=day'),
                    'chips'  => $src ? [['icon' => $src['icon'], 'text' => $src['short']]] : [],
                    'meta'   => $src ? array_merge($meta, [['icon' => 'info', 'text' => $src['effect']]]) : $meta,
                    'amount' => money($r['amount'], false),
                    'amount_tone' => 'pos',
                ]);
            }
        },
    ]);
    break;

case 'spent':
    partial('drill', [
        'how'   => 'الفيزا و«غير مدفوع» مستبعدان',
        'count' => count($rows),
        'total' => ['label' => 'صرفوا اليوم', 'value' => money(array_sum(array_column($rows, 'amount')), false)],
        'more'  => ['label' => 'سجل المصروفات', 'url' => url('expenses')],
        'empty' => ['title' => 'مفيش صرف في ' . $dayTxt],
        'body'  => function () use ($rows, $spendRow) {
            foreach ($rows as $s) { $spendRow($s, true); }
        },
    ]);
    break;

case 'pending':
    partial('drill', [
        'how'   => 'مطروحة مؤقتًا من «فاضل معاهم»',
        'count' => count($rows),
        'total' => ['label' => 'مستني مراجعتك', 'value' => money(array_sum(array_column($rows, 'amount')), false)],
        'more'  => ['label' => 'صندوق المراجعة', 'url' => url('rep-custody/inbox')],
        'empty' => ['title' => 'مفيش بنود معلّقة في ' . $dayTxt],
        'body'  => function () use ($rows, $pendingChips) {
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
                    'chips'  => $pendingChips($p),
                    'amount' => money($p['amount'], false),
                    'amount_tone' => 'wait',
                ]);
            }
        },
    ]);
    break;

default:   // balance — الفاضل من عهدة اليوم، مندوبًا مندوبًا
    partial('drill', [
        'how'   => 'كل يوم عهدة مستقلة — بلا ترحيل',
        'count' => count($rows),
        'total' => ['label' => 'فاضل معاهم من عهدة اليوم', 'value' => money(array_sum(array_column($rows, 'pocket')), false)],
        'more'  => ['label' => 'كل المناديب', 'url' => url('rep-custody')],
        'empty' => ['title' => 'مفيش حركة عهدة في ' . $dayTxt],
        'body'  => function () use ($rows, $day) {
            foreach ($rows as $r) {
                $meta = [
                    ['icon' => 'wallet', 'text' => 'نزل ' . money($r['deposits'], false)],
                    ['icon' => 'trending-down', 'text' => 'صرف ' . money($r['spent'], false)],
                ];
                if ((float) $r['pending'] > 0) {
                    $meta[] = ['icon' => 'hourglass', 'text' => 'مستني ' . money($r['pending'], false)];
                    $meta[] = ['icon' => 'briefcase', 'text' => 'قبل خصم المستني ' . money($r['balance'], false)];
                }
                partial('row_item', [
                    'title'  => $r['name'],
                    'icon'   => 'briefcase',
                    'url'    => url('rep-custody/' . (int) $r['id'] . '?tab=day'),
                    'meta'   => $meta,
                    'amount' => money($r['pocket'], false),
                    'amount_tone' => $r['pocket'] < 0 ? 'neg' : 'pos',
                    'sub'    => $r['pocket'] < 0 ? 'صرف أكتر من عهدة اليوم' : null,
                ]);
            }
        },
    ]);
}
