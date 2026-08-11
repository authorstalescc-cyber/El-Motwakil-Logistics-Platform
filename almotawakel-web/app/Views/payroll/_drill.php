<?php
/**
 * تفاصيل أرقام شاشة الرواتب — بترندر **بلا layout** وبترجع HTML للنافذة العامة.
 *
 * ⚠️ كل صف بيستخدم `row_item` نفسه بتاع باقي النظام، فالتفاصيل شكلها زي أي
 *    قايمة تانية — مش شاشة بلغة تانية.
 *
 * @var string $what  bills|commission|expense|allowed|advances|nets
 * @var array  $rows  @var array $period  @var bool $approved
 */
$money = static fn ($v) => money((float) $v, false);

/* ⚠️ الفترة المعتمدة **لقطة**: الأرقام فوق اتجمّدت يوم الاعتماد، والقايمة دي
   بتتحسب من البيانات الحالية — فلو حد عدّل بوليصة قديمة الاتنين ممكن يفترقوا.
   ده تحذير وظيفي (٥ كلمات) مش شرح. */
$how = $approved && in_array($what, ['bills', 'commission', 'expense'], true)
    ? 'الأرقام فوق لقطة يوم الاعتماد.' : null;

ob_start();

if ($what === 'nets') {
    foreach ($rows as $l) {
        $meta = [
            ['icon' => 'banknote', 'text' => 'ثابت ' . $money($l['base_salary'])],
            ['icon' => 'wallet',   'text' => 'سلف ' . $money($l['advances'])],
            ['icon' => 'coins',    'text' => 'كومسيون ' . $money($l['commission_share'])],
            ['icon' => 'crane',    'text' => 'مصروف ' . $money($l['expense_share'])],
        ];
        partial('row_item', [
            'title'       => (string) $l['rep_name'],
            'icon'        => 'user',
            'url'         => url('reps/' . (int) $l['rep_id']),
            'meta'        => $meta,
            'chips'       => empty($l['included']) ? [['icon' => 'info', 'text' => 'برّه القسمة']] : [],
            'amount'      => $money($l['net']),
            'amount_tone' => (float) $l['net'] < 0 ? 'ex' : 'pos',
        ]);
    }
} elseif ($what === 'allowed' || $what === 'advances') {
    foreach ($rows as $r) {
        $meta = [];
        if (!empty($r['rep_name'])) { $meta[] = ['icon' => 'user', 'text' => (string) $r['rep_name']]; }
        $meta[] = ['icon' => 'layers', 'text' => \App\Models\RepMiscKind::label($r['misc_kind'] ?? null)];
        partial('row_item', [
            'title'       => (string) ($r['note'] ?: \App\Models\RepMiscKind::label($r['misc_kind'] ?? null)),
            'icon'        => \App\Models\RepMiscKind::icon($r['misc_kind'] ?? null),
            'meta'        => $meta,
            'amount'      => $money($r['amount']),
            'amount_tone' => 'ex',
            'sub'         => !empty($r['exp_date']) ? dfmt((string) $r['exp_date']) : null,
        ]);
    }
} else {
    // bills | commission | expense — نفس البوالص، والمبلغ المعروض هو عدسة الرقم.
    foreach ($rows as $b) {
        $meta = [];
        if (!empty($b['company']))        { $meta[] = ['icon' => 'building', 'text' => (string) $b['company']]; }
        if (!empty($b['car_no']))         { $meta[] = ['icon' => 'truck', 'text' => (string) $b['car_no']]; }
        if (!empty($b['container_type'])) { $meta[] = ['icon' => 'package', 'text' => (string) container_norm($b['container_type'])]; }
        $amount = match ($what) {
            'commission' => $b['retained'],
            'expense'    => $b['rate'],
            default      => $b['custody'],
        };
        /* ⚠️ البوليصة اللي سيارتها معفاة ما احتجزتش ١٠٠ — الشارة بتقول ده بدل
           ما المالك يقعد يدوّر ليه الصف ده بصفر. */
        $chips = ($what === 'commission' && (float) $b['retained'] <= 0)
            ? [['icon' => 'info', 'text' => 'بلا احتجاز']] : [];
        partial('row_item', [
            'title'  => 'بوليصة ' . ($b['bill_number'] !== null && $b['bill_number'] !== ''
                            ? (string) $b['bill_number'] : '#' . (int) $b['id']),
            'icon'   => 'file-text',
            'url'    => url('bawalis/' . (int) $b['id']),
            'meta'   => $meta,
            'chips'  => $chips,
            'amount' => $money($amount),
            'sub'    => !empty($b['bill_date']) ? dfmt((string) $b['bill_date']) : null,
        ]);
    }
}

$body = ob_get_clean();

$total = match ($what) {
    'nets'       => array_sum(array_column($rows, 'net')),
    'commission' => array_sum(array_column($rows, 'retained')),
    'expense'    => array_sum(array_column($rows, 'rate')),
    'bills'      => array_sum(array_column($rows, 'custody')),
    default      => array_sum(array_column($rows, 'amount')),
};

partial('drill', [
    'how'   => $how,
    'body'  => $body,
    'count' => count($rows),
    'total' => ['label' => 'الإجمالي', 'value' => money((float) $total)],
]);
