<?php
/**
 * تفاصيل أرقام حساب العميل — بترندر **بلا layout** للنافذة العامة.
 *
 * ⚠️ كل صف بيستخدم `row_item` نفسه بتاع باقي النظام، فالتفاصيل شكلها زي أي
 *    قايمة تانية — مش شاشة بلغة تانية.
 *
 * @var string $what @var int $agentId @var string $name
 * @var array $head @var array $aging @var array $rows
 */
use App\Models\ClientPayment;
use App\Models\PermitInvoice;

$money = static fn ($v) => money((float) $v, false);
$today = work_date();
ob_start();

if ($what === 'charges') {
    foreach ($rows as $iv) {
        $void = ($iv['status'] ?? '') === 'void';
        partial('row_item', [
            'title'  => (string) ($iv['doc_no'] ?: '#' . $iv['id']),
            'url'    => url('permits/invoice/' . (int) $iv['id']),
            'icon'   => 'receipt',
            'meta'   => array_values(array_filter([
                ['icon' => 'tag', 'text' => PermitInvoice::kindLabel((string) $iv['doc_kind'])],
                $iv['permit_no'] ? ['icon' => 'folder', 'text' => (string) $iv['permit_no']] : null,
            ])),
            'chips'  => $void ? [['icon' => 'x', 'text' => 'ملغي']] : [],
            'amount' => $money($void ? 0 : $iv['total']),
            'amount_tone' => 'rc',
            'sub'    => $iv['invoice_date'] ? dfmt((string) $iv['invoice_date']) : null,
        ]);
    }
} elseif ($what === 'paid') {
    foreach ($rows as $pm) {
        $rev = ($pm['status'] ?? '') === 'reversed';
        partial('row_item', [
            'title'  => 'تحصيل ' . ClientPayment::routeLabel((string) $pm['route']),
            'icon'   => 'banknote',
            'meta'   => array_values(array_filter([
                ($pm['note'] ?? '') !== '' ? ['icon' => 'edit', 'text' => (string) $pm['note']] : null,
                !$rev && (float) $pm['unallocated_amount'] > 0.005
                    ? ['icon' => 'alert', 'text' => 'غير مخصَّص ' . money((float) $pm['unallocated_amount'], false)] : null,
            ])),
            'chips'  => $rev ? [['icon' => 'undo', 'text' => 'معكوس']] : [],
            'amount' => $money($rev ? 0 : $pm['amount']),
            'amount_tone' => 'pos',
            'sub'    => $pm['pay_date'] ? dfmt((string) $pm['pay_date']) : null,
        ]);
    }
} elseif ($what === 'overdue' || $what === 'open') {
    foreach ($rows as $iv) {
        $late = $iv['due_date'] && $iv['due_date'] < $today;
        if ($what === 'overdue' && !$late) { continue; }
        partial('row_item', [
            'title'  => (string) ($iv['doc_no'] ?: '#' . $iv['id']),
            'url'    => url('permits/invoice/' . (int) $iv['id']),
            'icon'   => 'receipt',
            'meta'   => array_values(array_filter([
                ['icon' => 'coins', 'text' => 'إجمالي ' . money((float) $iv['total'], false)],
                (float) $iv['paid_amount'] > 0.005
                    ? ['icon' => 'banknote', 'text' => 'مدفوع ' . money((float) $iv['paid_amount'], false)] : null,
            ])),
            'chips'  => $late
                ? [['icon' => 'clock', 'text' => 'متأخر ' . intf(\App\Models\ClientAccount::lateDays($iv['due_date'])) . ' يوم']]
                : ($iv['due_date'] ? [['icon' => 'calendar', 'text' => 'يستحق ' . dfmt((string) $iv['due_date'])]] : []),
            'amount' => $money($iv['balance_due']),
            'amount_tone' => 'ex',
        ]);
    }
} elseif ($what === 'aging') {
    /* ⚠️ التفاصيل هنا **توزيع** مش قايمة صفوف: الشرايح الأربعة مجموعها =
       باقي الفواتير المفتوحة بالحرف، فعرض شريحة واحدة كان هيضلّل. */
    foreach ($aging as $b) {
        partial('row_item', [
            'title'  => (string) $b['label'],
            'icon'   => 'clock',
            'meta'   => [['icon' => 'layers', 'text' => intf($b['count']) . ' فاتورة']],
            'amount' => $money($b['value']),
            'amount_tone' => $b['tone'] === 'red' ? 'ex' : 'rc',
        ]);
    }
} else {
    /* المتبقّي = المطلوب − المحصّل. التفاصيل معادلة الرقم نفسه ثم آخر الحركات. */
    foreach ([
        ['كل المستندات الصادرة', $head['charges'], 'receipt', 'rc'],
        ['كل التحصيلات',         -$head['paid'],   'banknote', 'pos'],
    ] as [$label, $v, $ic, $tone]) {
        partial('row_item', ['title' => $label, 'icon' => $ic, 'amount' => $money($v), 'amount_tone' => $tone]);
    }
    foreach (array_slice(array_reverse($rows), 0, 12) as $r) {
        partial('row_item', [
            'title'  => (string) $r['label'],
            'icon'   => $r['kind'] === 'payment' ? 'banknote' : 'receipt',
            'meta'   => [['icon' => 'tag', 'text' => (string) $r['ref']]],
            'amount' => $money($r['debit'] > 0 ? $r['debit'] : -$r['credit']),
            'amount_tone' => $r['debit'] > 0 ? 'ex' : 'pos',
            'sub'    => $r['date'] ? dfmt((string) $r['date']) : null,
        ]);
    }
}

$body = ob_get_clean();

$meta = match ($what) {
    'charges'   => ['كل مستند اتّصدر للعميل ده.', 'المطلوب منه', $head['charges'], ['label' => 'سجل الفواتير', 'url' => url('invoices')]],
    'paid'      => ['كل تحصيل اتسجّل منه (المعكوس بصفر).', 'المحصّل منه', $head['paid'], ['label' => 'سجل التحصيلات', 'url' => url('payments')]],
    'overdue'   => ['الفواتير اللي عدّى ميعاد سدادها ولسه ليها باقي.', 'المتأخر', $head['overdue'], null],
    'open'      => ['الفواتير الصادرة اللي لسه ليها باقي.', 'باقي الفواتير المفتوحة', $head['open_balance'], null],
    'aging'     => ['الباقي المفتوح موزّعًا على مدة التأخير.', 'باقي الفواتير المفتوحة', $head['open_balance'], null],
    default     => ['المطلوب ناقص المحصّل — الموجب مطلوب من العميل.', 'المتبقّي عليه', $head['remaining'], null],
};

partial('drill', [
    'how'   => $meta[0],
    'body'  => $body,
    'total' => ['label' => $meta[1], 'value' => $money($meta[2])],
    'more'  => $meta[3],
    'empty' => ['title' => 'مفيش بنود'],
]);
