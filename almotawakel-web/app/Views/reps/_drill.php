<?php
/**
 * تفاصيل أرقام بروفايل المندوب — بترندر **بلا layout** للنافذة العامة.
 *
 * ⚠️ كل صف بيستخدم `row_item` نفسه بتاع باقي النظام، فالتفاصيل شكلها زي أي
 *    قايمة تانية — مش شاشة بلغة تانية.
 *
 * @var string $what @var int $repId @var array $period @var array $rows @var ?array $pay
 */
$money = static fn ($v) => money((float) $v, false);
ob_start();

if ($what === 'balance') {
    foreach ($rows as $r) {
        $credit = in_array($r['kind'], \App\Models\Rep::CREDIT_KINDS, true);
        partial('row_item', [
            'title'       => (string) ($r['note'] ?: $r['label']),
            'icon'        => $credit ? 'trending-up' : 'trending-down',
            'meta'        => [['icon' => 'layers', 'text' => (string) $r['label']]],
            'chips'       => !empty($r['payroll_period_id'])
                ? [['icon' => 'banknote', 'text' => 'من اعتماد الراتب']] : [],
            'amount'      => $money($r['amount']),
            'amount_tone' => $credit ? 'pos' : 'ex',
            'sub'         => $r['d'] ? dfmt((string) $r['d']) : null,
        ]);
    }
} elseif ($what === 'net' && $pay !== null) {
    /* ⚠️ التفاصيل هنا **معادلة** مش قايمة صفوف: الرقم مركّب من ٥ بنود، وعرض
       البنود اليدوية بس كان هيخلّي «الإجمالي» تحت مش مساوي الرقم فوق. */
    foreach ([
        ['راتب ثابت',            $pay['salary'],      'banknote'],
        ['سلف الفترة',           -$pay['advances'],   'wallet'],
        ['كومسيون البوالص',      $pay['commission'],  'coins'],
        ['حصة مصروف البوالص',    $pay['expense'],     'crane'],
        ['زيادة / خصم / جزاءات', $pay['adjustments'], 'edit'],
    ] as [$label, $v, $ic]) {
        partial('row_item', [
            'title'       => $label,
            'icon'        => $ic,
            'amount'      => $money($v),
            'amount_tone' => $v < 0 ? 'ex' : 'pos',
        ]);
    }
} elseif ($what === 'permits') {
    /* ⚠️ الحاوية هنا من `permits.containers` — **نفس** الرقم اللي الكشف
       والضمان بيتحسبوا عليه، مش عدّ بوالص (البوليصة ممكن تشيل أكتر من واحدة). */
    foreach ($rows as $p) {
        $meta = [];
        if ((int) $p['containers'] > 0) {
            $meta[] = ['icon' => 'containers', 'text' => intf((int) $p['containers']) . ' حاوية'];
        }
        if (!empty($p['company'])) { $meta[] = ['icon' => 'building', 'text' => (string) $p['company']]; }
        $meta[] = ['icon' => 'clipboard', 'text' => intf((int) $p['subs']) . ' بند'];
        partial('row_item', [
            'title'  => (string) ($p['permit_no'] ?: '#' . (int) $p['id']),
            'icon'   => 'folder',
            'url'    => url('permits/' . (int) $p['id']),
            'meta'   => $meta,
            'amount' => $money($p['spent']),
            'sub'    => !empty($p['permit_date']) ? dfmt((string) $p['permit_date']) : null,
        ]);
    }
} elseif ($what === 'entries') {
    foreach ($rows as $s) {
        $st = (string) ($s['status'] ?? '');
        partial('row_item', [
            'title'  => (string) ($s['note'] ?: 'بند'),
            'icon'   => $st === 'rejected' ? 'x' : ($st === 'approved' ? 'check' : 'clock'),
            'meta'   => !empty($s['permit_no'])
                ? [['icon' => 'folder', 'text' => 'إذن ' . $s['permit_no']]] : [],
            'chips'  => $st === 'rejected' && !empty($s['review_note'])
                ? [['icon' => 'alert', 'text' => (string) $s['review_note']]] : [],
            'amount' => $money($s['amount'] ?? 0),
            'sub'    => !empty($s['entry_date']) ? dfmt((string) $s['entry_date']) : null,
        ]);
    }
} elseif ($what === 'custody') {
    foreach ($rows as $s) {
        $meta = [];
        if (!empty($s['permit_no'])) { $meta[] = ['icon' => 'folder', 'text' => 'إذن ' . $s['permit_no']]; }
        if (!empty($s['is_visa']))   { $meta[] = ['icon' => 'credit-card', 'text' => 'فيزا — مش من العهدة']; }
        partial('row_item', [
            'title'       => (string) ($s['note'] ?: 'بند عهدة'),
            'icon'        => 'briefcase',
            'meta'        => $meta,
            'amount'      => $money($s['amount']),
            'amount_tone' => empty($s['is_visa']) ? 'ex' : '',
            'sub'         => !empty($s['d']) ? dfmt((string) $s['d']) : null,
        ]);
    }
} elseif ($what === 'pending') {
    foreach ($rows as $p) {
        partial('row_item', [
            'title'  => (string) ($p['note'] ?: 'بند تحت المراجعة'),
            'icon'   => 'clock',
            'meta'   => !empty($p['permit_no'])
                ? [['icon' => 'folder', 'text' => 'إذن ' . $p['permit_no']]] : [],
            'amount' => $money($p['amount'] ?? 0),
            'sub'    => !empty($p['entry_date']) ? dfmt((string) $p['entry_date']) : null,
        ]);
    }
} else {   // advances
    foreach ($rows as $r) {
        partial('row_item', [
            'title'       => (string) ($r['note'] ?: \App\Models\RepMiscKind::label($r['misc_kind'] ?? null)),
            'icon'        => \App\Models\RepMiscKind::icon($r['misc_kind'] ?? null),
            'meta'        => [['icon' => 'layers',
                               'text' => \App\Models\RepMiscKind::label($r['misc_kind'] ?? null)]],
            'amount'      => $money($r['amount']),
            'amount_tone' => 'ex',
            'sub'         => !empty($r['exp_date']) ? dfmt((string) $r['exp_date']) : null,
        ]);
    }
}

$body = ob_get_clean();

$total = match ($what) {
    'net'     => $pay['net'] ?? 0,
    'permits' => array_sum(array_column($rows, 'spent')),
    'balance' => array_sum(array_map(
        static fn ($r) => \App\Models\Rep::sign((string) $r['kind']) * (float) $r['amount'], $rows)),
    default   => array_sum(array_column($rows, 'amount')),
};

partial('drill', [
    'body'  => $body,
    'count' => $what === 'net' ? 5 : count($rows),
    'total' => ['label' => 'الإجمالي', 'value' => money((float) $total)],
    'more'  => ['label' => 'افتح البروفايل', 'url' => url('reps/' . $repId)],
]);
