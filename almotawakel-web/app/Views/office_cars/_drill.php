<?php
/**
 * أجسام نوافذ التفاصيل لسيارات المكتب (v54).
 *
 * ⚠ بترندر **بلا layout** وبترجع HTML للنافذة العامة (`#uiDrill`). كل صف
 *    بيستخدم مكوّن `row_item` نفسه بتاع الشاشة، فالتفاصيل شكلها زي القوايم.
 *
 * **كل الأرقام على نفس فترة الشاشة** — الفترة جاية في الرابط (`?p=&d=`)
 *    فمستحيل النافذة تقول رقمًا مختلفًا عن اللي فوق.
 *
 * متغيّرات: $what $period (+ $fleet $summary للأسطول | $car $trips $perf
 *           $expenses $byCat لسيارة واحدة)
 */
use App\Models\OfficeCar;

$pLbl = $period['label'];
$q = array_filter(['p' => $period['kind'], 'd' => $_GET['d'] ?? null]);
$qs = $q ? '?' . http_build_query($q) : '';

/* صف رحلة — نسخة واحدة مشتركة بين كل النوافذ عشان الشكل ما يفترقش. */
$tripRow = static function (array $t, string $money = 'freight'): void {
    $meta = array_values(array_filter([
        $t['bill_date'] ? ['icon' => 'calendar', 'text' => dfmt($t['bill_date'])] : null,
        $t['company'] ? ['icon' => 'building', 'text' => $t['company']] : null,
        $t['destination'] ? ['icon' => 'map-pin', 'text' => $t['destination']] : null,
        $t['driver'] ? ['icon' => 'user', 'text' => $t['driver']] : null,
        $t['port'] ? ['icon' => 'anchor', 'text' => $t['port']] : null,
        $t['permit_no'] ? ['icon' => 'folder', 'text' => 'إذن ' . $t['permit_no']] : null,
    ]));
    $chips = [];
    if ((int) $t['container_count'] > 0) {
        $chips[] = ['icon' => 'package',
                    'text' => intf((int) $t['container_count']) . '×' . (container_norm($t['container_type']) ?: 'حاوية')];
    }
    if (!empty($t['owner_mismatch'])) {
        $chips[] = ['icon' => 'alert', 'text' => 'مالك العربية مكتوب: ' . ($t['car_owner'] ?: 'فاضي')];
    }
    $amt = $money === 'custody' ? (float) $t['custody'] : (float) $t['freight'];
    partial('row_item', [
        'title'  => $t['bill_number'] ? 'بوليصة ' . $t['bill_number'] : 'بوليصة #' . (int) $t['id'],
        'icon'   => 'truck',
        'tone'   => (string) $t['permit_type'] === 'warid' ? 'warid' : 'sader',
        'url'    => url('bawalis/' . (int) $t['id']),
        'meta'   => $meta,
        'chips'  => $chips,
        'amount' => money($amt, false),
        'amount_tone' => $money === 'custody' ? 'rc' : '',
        'sub'    => $money === 'custody' ? 'عهدة' : 'نولون',
    ]);
};

/* صف سيارة — للنوافذ على مستوى الأسطول. */
$carRow = static function (array $c, string $key, string $label) use ($qs): void {
    $val = $c[$key] ?? 0;
    $isMoney = in_array($key, ['freight', 'custody', 'expenses', 'net'], true);
    partial('row_item', [
        'title'  => (string) $c['name'],
        'icon'   => 'truck',
        'url'    => url('cars/' . (int) $c['id'] . $qs),
        'meta'   => array_values(array_filter([
            ['icon' => 'clipboard', 'text' => (string) $c['plate']],
            ['icon' => 'truck', 'text' => intf((int) $c['trips']) . ' رحلة'],
            ['icon' => 'package', 'text' => intf((int) $c['containers']) . ' حاوية'],
            $c['driver'] ? ['icon' => 'user', 'text' => (string) $c['driver']] : null,
        ])),
        'amount' => $isMoney ? money((float) $val, false) : intf((int) $val),
        'amount_tone' => in_array($key, ['expenses'], true) ? 'ex'
                        : ($key === 'net' && (float) $val < 0 ? 'neg' : ''),
        'sub'    => $label,
    ]);
};

/* صف تقسيم (شركة / ميناء / وجهة / سواق). */
$dimRow = static function (array $r, string $icon, string $money = 'freight'): void {
    partial('row_item', [
        'title'  => (string) $r['name'],
        'icon'   => $icon,
        'meta'   => [['icon' => 'truck', 'text' => intf((int) $r['trips']) . ' رحلة'],
                     ['icon' => 'package', 'text' => intf((int) $r['containers']) . ' حاوية']],
        'amount' => money((float) $r[$money], false),
        'amount_tone' => $money === 'custody' ? 'rc' : '',
        'sub'    => $money === 'custody' ? 'عهد' : 'نولون',
    ]);
};

// ═══════════════════════════════════════════════ نوافذ الأسطول

if (!str_starts_with($what, 'car_')) {
    $fleet = $fleet ?? [];
    $sum = $summary ?? [];

    if ($what === 'expenses') {
        partial('drill', [
            'how'   => 'مصاريف سيارات المكتب في ' . $pLbl . ' — سولار وإصلاحات وغيره. '
                     . 'مش داخلة خزنة المكتب ولا التقفيل اليومي.',
            'count' => count(array_filter($fleet, static fn ($c) => (float) $c['expenses'] > 0)),
            'total' => ['label' => 'إجمالي المصاريف', 'value' => money($sum['expenses'] ?? 0, false)],
            'more'  => ['label' => 'سيارات المكتب', 'url' => url('cars' . $qs)],
            'empty' => ['title' => 'مفيش مصاريف مسجَّلة في الفترة دي'],
            'body'  => function () use ($fleet, $carRow) {
                foreach ($fleet as $c) {
                    if ((float) $c['expenses'] <= 0) { continue; }
                    $carRow($c, 'expenses', 'مصاريف');
                }
            },
        ]);
        return;
    }

    [$key, $label, $how, $totalLbl, $totalVal] = match ($what) {
        'trips'      => ['trips', 'رحلة', 'عدد رحلات كل سيارة في ' . $pLbl . '.',
                         'إجمالي الرحلات', intf($sum['trips'] ?? 0)],
        'containers' => ['containers', 'حاوية', 'الحاويات اللي شالتها كل سيارة في ' . $pLbl . '.',
                         'إجمالي الحاويات', intf($sum['containers'] ?? 0)],
        'freight'    => ['freight', 'نولون', 'نولون رحلات كل سيارة في ' . $pLbl . '.',
                         'إجمالي النولون', money($sum['freight'] ?? 0, false)],
        'custody'    => ['custody', 'عهد', 'العهد اللي اتدفعت لسواقين كل سيارة في ' . $pLbl . '.',
                         'إجمالي العهد', money($sum['custody'] ?? 0, false)],
        default      => ['net', 'صافي',
                         'صافي كل سيارة = نولون − عهد للسواق − مصاريف السيارة. رقم استرشادي لأداء السيارة مش ربح محاسبي.',
                         'صافي الأسطول', money($sum['net'] ?? 0, false)],
    };

    partial('drill', [
        'how'   => $how,
        'count' => count($fleet),
        'total' => ['label' => $totalLbl, 'value' => $totalVal],
        'more'  => ['label' => 'سيارات المكتب', 'url' => url('cars' . $qs)],
        'empty' => ['title' => 'مفيش سيارات في السجل'],
        'body'  => function () use ($fleet, $carRow, $key, $label) {
            foreach ($fleet as $c) { $carRow($c, $key, $label); }
        },
    ]);
    return;
}

// ═══════════════════════════════════════════════ نوافذ سيارة واحدة

$trips = $trips ?? [];
$perf  = $perf ?? [];
$carUrl = url('cars/' . (int) $car['id'] . $qs);
$carTitle = $car['name'] . ' — ' . $pLbl;

switch ($what) {
    case 'car_expenses':
        partial('drill', [
            'how'   => 'مصاريف ' . $carTitle . '. سجل خاص بالسيارة — مش داخل الخزنة ولا التقفيل.',
            'count' => count($expenses ?? []),
            'total' => ['label' => 'إجمالي المصاريف', 'value' => money($perf['expenses'] ?? 0, false)],
            'more'  => ['label' => 'صفحة السيارة', 'url' => $carUrl],
            'empty' => ['title' => 'مفيش مصاريف في الفترة دي'],
            'body'  => function () use ($expenses) {
                foreach (($expenses ?? []) as $x) {
                    partial('row_item', [
                        'title'  => OfficeCar::kindLabel($x['category']),
                        'icon'   => OfficeCar::kindIcon($x['category']),
                        'ref'    => $x['note'] ?: null,
                        'meta'   => array_values(array_filter([
                            ['icon' => 'calendar', 'text' => dfmt($x['exp_date'])],
                            $x['vendor'] ? ['icon' => 'building', 'text' => $x['vendor']] : null,
                            $x['ref_no'] ? ['icon' => 'clipboard', 'text' => 'إيصال ' . $x['ref_no']] : null,
                        ])),
                        'amount' => money($x['amount'], false),
                        'amount_tone' => 'ex',
                    ]);
                }
            },
        ]);
        return;

    case 'car_net':
        // النافذة دي بتشرح **المعادلة** مش بتعرض بنودًا — الرقم مشتق.
        partial('drill', [
            'how'   => 'صافي السيارة = النولون − العهد اللي اتدفعت للسواق − مصاريف السيارة. '
                     . 'رقم استرشادي لأداء السيارة، **مش** ربح محاسبي: مفيهوش التحويلة ولا الخصم '
                     . 'ولا أي تكلفة عامة على الشركة.',
            'total' => ['label' => 'الصافي في ' . $pLbl, 'value' => money($perf['net'] ?? 0, false)],
            'more'  => ['label' => 'صفحة السيارة', 'url' => $carUrl],
            'body'  => function () use ($perf) {
                partial('row_item', ['title' => 'النولون', 'icon' => 'banknote',
                    'meta' => [['icon' => 'truck', 'text' => intf($perf['trips']) . ' رحلة']],
                    'amount' => money($perf['freight'], false), 'sub' => '+']);
                partial('row_item', ['title' => 'العهد المدفوعة للسواق', 'icon' => 'wallet',
                    'meta' => [['icon' => 'info', 'text' => 'المخصوم من الخزنة منها: '
                        . money0($perf['custody_deducted'])]],
                    'amount' => money($perf['custody'], false), 'amount_tone' => 'rc', 'sub' => '−']);
                partial('row_item', ['title' => 'مصاريف السيارة', 'icon' => 'trending-down',
                    'meta' => [['icon' => 'alert', 'text' => 'مش داخلة الخزنة']],
                    'amount' => money($perf['expenses'], false), 'amount_tone' => 'ex', 'sub' => '−']);
            },
        ]);
        return;

    case 'car_containers':
    case 'car_mix':
        $mix = OfficeCar::containerMix($trips);
        partial('drill', [
            'how'   => 'الحاويات اللي شالتها ' . $carTitle . ' مقسّمة بالتصنيف.',
            'count' => count($mix),
            'total' => ['label' => 'إجمالي الحاويات', 'value' => intf($perf['containers'] ?? 0)],
            'more'  => ['label' => 'صفحة السيارة', 'url' => $carUrl],
            'empty' => ['title' => 'مفيش حاويات في الفترة دي'],
            'body'  => function () use ($mix) {
                foreach ($mix as $type => $n) {
                    partial('row_item', ['title' => (string) $type, 'icon' => 'package',
                        'amount' => intf($n), 'sub' => 'حاوية']);
                }
            },
        ]);
        return;

    case 'car_companies':
        $rows = OfficeCar::breakdown($trips, 'company');
        partial('drill', [
            'how'   => 'الشركات اللي شالت لها ' . $carTitle . '.',
            'count' => count($rows),
            'total' => ['label' => 'إجمالي النولون', 'value' => money($perf['freight'] ?? 0, false)],
            'more'  => ['label' => 'صفحة السيارة', 'url' => $carUrl],
            'empty' => ['title' => 'مفيش رحلات في الفترة دي'],
            'body'  => function () use ($rows, $dimRow) {
                foreach ($rows as $r) { $dimRow($r, 'building'); }
            },
        ]);
        return;

    case 'car_ports':
    case 'car_agencies':
    case 'car_destinations':
        $ports  = OfficeCar::breakdown($trips, 'port');
        $agency = OfficeCar::breakdown($trips, 'agency');
        $dest   = OfficeCar::breakdown($trips, 'destination');
        partial('drill', [
            'how'   => 'الموانئ والتوكيلات (من الإذن المرتبط) والوجهات لرحلات ' . $carTitle . '.',
            'total' => ['label' => 'إجمالي الرحلات', 'value' => intf($perf['trips'] ?? 0)],
            'more'  => ['label' => 'صفحة السيارة', 'url' => $carUrl],
            'empty' => ['title' => 'مفيش رحلات في الفترة دي'],
            'body'  => function () use ($ports, $agency, $dest, $dimRow) {
                $sec = static function (string $t): void {
                    echo '<div class="drill-sec">' . e($t) . '</div>';
                };
                $real = static fn (array $rows): array => array_values(array_filter($rows,
                    static fn ($r) => $r['name'] !== '(غير محدَّد)'));
                if ($p = $real($ports))  { $sec('الموانئ');   foreach ($p as $r) { $dimRow($r, 'anchor'); } }
                if ($a = $real($agency)) { $sec('التوكيلات'); foreach ($a as $r) { $dimRow($r, 'landmark'); } }
                if ($d = $real($dest))   { $sec('الوجهات');   foreach ($d as $r) { $dimRow($r, 'map-pin'); } }
            },
        ]);
        return;

    case 'car_drivers':
        $rows = OfficeCar::breakdown($trips, 'driver');
        partial('drill', [
            'how'   => 'السواقين اللي مشّوا ' . $carTitle . ' والعهد اللي اتدفعت لكل واحد.',
            'count' => count($rows),
            'total' => ['label' => 'إجمالي العهد', 'value' => money($perf['custody'] ?? 0, false)],
            'more'  => ['label' => 'صفحة السيارة', 'url' => $carUrl],
            'empty' => ['title' => 'مفيش سواقين في الفترة دي'],
            'body'  => function () use ($rows, $dimRow) {
                foreach ($rows as $r) { $dimRow($r, 'user', 'custody'); }
            },
        ]);
        return;

    case 'car_custody':
        partial('drill', [
            'how'   => 'العهد اللي اتدفعت لسواقين ' . $carTitle . ' — عهدة كل رحلة. '
                     . 'المخصوم من خزنة المكتب منها ' . money0($perf['custody_deducted'] ?? 0)
                     . ' (الفرق هو الخصم الثابت لكل بوليصة).',
            'count' => count($trips),
            'total' => ['label' => 'إجمالي العهد', 'value' => money($perf['custody'] ?? 0, false)],
            'more'  => ['label' => 'صفحة السيارة', 'url' => $carUrl],
            'empty' => ['title' => 'مفيش رحلات في الفترة دي'],
            'body'  => function () use ($trips, $tripRow) {
                foreach ($trips as $t) { if ((float) $t['custody'] > 0) { $tripRow($t, 'custody'); } }
            },
        ]);
        return;

    default:   // car_trips | car_freight
        $isFreight = $what === 'car_freight';
        partial('drill', [
            'how'   => $isFreight
                ? 'نولون كل رحلة من رحلات ' . $carTitle . '.'
                : 'رحلات ' . $carTitle . ' — كل بوليصة اتسجّلت على لوحة السيارة دي.',
            'count' => count($trips),
            'total' => ['label' => $isFreight ? 'إجمالي النولون' : 'عدد الرحلات',
                        'value' => $isFreight ? money($perf['freight'] ?? 0, false) : intf($perf['trips'] ?? 0)],
            'more'  => ['label' => 'صفحة السيارة', 'url' => $carUrl],
            'empty' => ['title' => 'مفيش رحلات في الفترة دي'],
            'body'  => function () use ($trips, $tripRow) {
                foreach ($trips as $t) { $tripRow($t); }
            },
        ]);
}
