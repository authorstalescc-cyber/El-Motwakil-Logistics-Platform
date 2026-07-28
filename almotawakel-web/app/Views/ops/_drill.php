<?php
/**
 * أجسام نوافذ التفاصيل لتقارير التشغيل.
 *
 * ⚠️ بترندر **بلا layout** وبترجع HTML للنافذة العامة (`#uiDrill`). كل صف
 *    بيستخدم نفس مكوّن `row_item` بتاع الشاشة، فالتفاصيل شكلها زي القوايم.
 *
 * ⚠️ **كل الأرقام على نفس فترة الشاشة** — الفترة جاية في الرابط
 *    (`?p=&d=&from=&to=`) فمستحيل النافذة تقول رقمًا مختلفًا عن اللي فوق.
 *
 * متغيّرات: $what $period $isDim $rows $totals
 */
use App\Models\OpsReport;

$pLbl = $period['label'];
$q = array_filter([
    'p'    => $period['kind'],
    'd'    => $_GET['d'] ?? null,
    'from' => $period['kind'] === 'custom' ? $period['from'] : null,
    'to'   => $period['kind'] === 'custom' ? $period['to']   : null,
]);
$qs = $q ? '?' . http_build_query($q) : '';

/* ═══ بوالص قيمة واحدة من التوزيع («بوالص ميناء الإسكندرية بس») ═══
   ⚠️ ده اللي بيمنع التكرار: الضغط على صف في التوزيع بيفتح **بنوده هو** مش
      نسخة تانية من نفس القايمة. */
if ($what === 'rows' && $slice !== null):
    $sumC = 0; $sumU = 0.0;
    foreach ($rows as $r) { $sumC += max(0, (int) $r['container_count']); $sumU += (float) $r['custody']; }
    partial('drill', [
        'how'   => $slice['label'] . ': «' . $slice['value'] . '» — بوالصها في ' . $pLbl . '.',
        'count' => count($rows),
        'total' => ['label' => 'حاويات البند ده', 'value' => intf($sumC)
                    . ($sumU > 0 ? ' · عهد ' . money0($sumU) : '')],
        'more'  => ['label' => 'افتح البوالص', 'url' => url('bawalis')],
        'empty' => ['title' => 'مفيش بوالص للبند ده في الفترة'],
        'body'  => function () use ($rows) {
            foreach ($rows as $t) { include __DIR__ . '/_trip_row.php'; }
        },
    ]);

/* ═══ تقسيم (ميناء / شركة / توكيل / وجهة / عميل / صاحب بيان / سواق / سيارة) ═══ */
elseif ($isDim):
    [$label, , $ic] = OpsReport::DIMS[$what];
    partial('drill', [
        'how'   => 'كل ' . $label . ' اتحرّك عليها بوليصة في ' . $pLbl
                 . ' — والنسبة من إجمالي ' . intf($totals['containers']) . ' حاوية.',
        'count' => count($rows),
        'total' => ['label' => 'إجمالي الحاويات', 'value' => intf($totals['containers'])],
        'more'  => ['label' => 'افتح البوالص', 'url' => url('bawalis')],
        'empty' => ['title' => 'مفيش حركة في الفترة دي'],
        'body'  => function () use ($rows, $totals, $ic) {
            foreach ($rows as $r) {
                $pct = $totals['containers'] > 0
                    ? round($r['containers'] / $totals['containers'] * 100) : 0;
                partial('row_item', [
                    'title'  => $r['name'],
                    'icon'   => $ic,
                    'meta'   => array_values(array_filter([
                        ['icon' => 'truck', 'text' => intf($r['trips']) . ' بوليصة'],
                        $r['permits'] > 0 ? ['icon' => 'folder', 'text' => intf($r['permits']) . ' إذن'] : null,
                        $r['custody'] > 0 ? ['icon' => 'wallet', 'text' => 'عهد ' . money0($r['custody'])] : null,
                    ])),
                    'amount' => intf($r['containers']),
                    'sub'    => 'حاوية · ' . $pct . '٪',
                ]);
            }
        },
    ]);

/* ═══ تصنيف الحاويات ═══ */
elseif ($what === 'mix'):
    partial('drill', [
        'how'   => 'الحاويات مقسّمة بتصنيفها في ' . $pLbl
                 . ' — التصنيف موحَّد تلقائيًّا («1*40» و«40 قدم» نفس النوع).',
        'count' => count($rows),
        'total' => ['label' => 'إجمالي الحاويات', 'value' => intf($totals['containers'])],
        'empty' => ['title' => 'مفيش حاويات في الفترة دي'],
        'body'  => function () use ($rows, $totals) {
            foreach ($rows as $m) {
                $pct = $totals['containers'] > 0 ? round($m['containers'] / $totals['containers'] * 100) : 0;
                partial('row_item', [
                    'title'  => $m['name'],
                    'icon'   => 'package',
                    'meta'   => [['icon' => 'truck', 'text' => intf($m['trips']) . ' بوليصة']],
                    'amount' => intf($m['containers']),
                    'sub'    => 'حاوية · ' . $pct . '٪',
                ]);
            }
        },
    ]);

/* ═══ الأذونات والشهادات ═══ */
elseif ($what === 'permits'):
    partial('drill', [
        'how'   => 'الأذونات والشهادات اللي اتحرّك عليها بوليصة واحدة على الأقل في ' . $pLbl
                 . '. «حاويات اتحرّكت» ممكن تكون أقل من حاويات الإذن كله.',
        'count' => count($rows),
        'total' => ['label' => 'عدد الأذونات', 'value' => intf($totals['permits'])],
        'more'  => ['label' => 'افتح الأذونات', 'url' => url('permits')],
        'empty' => ['title' => 'مفيش أذونات اتحرّك عليها في الفترة دي'],
        'body'  => function () use ($rows) {
            foreach ($rows as $p) {
                $full = (int) $p['permit_containers'];
                $moved = (int) $p['containers'];
                partial('row_item', [
                    'title'  => ($p['permit_type'] === 'warid' ? 'شهادة ' : 'إذن ')
                              . ($p['permit_no'] ?: '#' . (int) $p['id']),
                    'glyph'  => $p['permit_type'] === 'warid' ? '↘' : '↗',
                    'tone'   => $p['permit_type'] === 'warid' ? 'warid' : 'sader',
                    'url'    => url('permits/' . (int) $p['id']),
                    'meta'   => array_values(array_filter([
                        !empty($p['client'])  ? ['icon' => 'users',    'text' => $p['client']] : null,
                        !empty($p['company']) ? ['icon' => 'building', 'text' => $p['company']] : null,
                        !empty($p['port'])    ? ['icon' => 'anchor',   'text' => $p['port']] : null,
                        ['icon' => 'truck',    'text' => intf((int) $p['trips']) . ' بوليصة'],
                        ['icon' => 'calendar', 'text' => dfmt($p['first_move'])
                            . ($p['first_move'] !== $p['last_move'] ? ' ← ' . dfmt($p['last_move']) : '')],
                    ])),
                    'chips'  => (float) $p['custody'] > 0
                        ? [['icon' => 'wallet', 'text' => 'عهد ' . money0($p['custody'])]] : [],
                    'amount' => intf($moved) . ($full > 0 ? '/' . intf($full) : ''),
                    'sub'    => 'حاوية اتحرّكت',
                ]);
            }
        },
    ]);

/* ═══ الأرقام المبنية على البوالص (حاويات / بوالص / عهد / مخصوم) ═══ */
else:
    $how = match ($what) {
        'containers' => 'كل بوليصة اتحرّكت في ' . $pLbl . ' وعدد حاوياتها.',
        'trips'      => 'كل بوليصة اتحرّكت في ' . $pLbl . '.',
        'custody'    => 'العهدة كاملة زي ما اتسلّمت للسواق في ' . $pLbl . '.',
        'deducted'   => 'نفس بوالص العهد — والمخصوم من الخزنة أقل بـ١٠٠ لكل بوليصة '
                      . '(والسيارة المستثناة بتتخصم كاملة).',
        default      => 'بوالص ' . $pLbl . '.',
    };
    $totalRow = match ($what) {
        'containers' => ['label' => 'إجمالي الحاويات', 'value' => intf($totals['containers'])],
        'trips'      => ['label' => 'عدد البوالص',     'value' => intf($totals['trips'])],
        'deducted'   => ['label' => 'المخصوم من الخزنة', 'value' => money0($totals['deducted'])],
        default      => ['label' => 'إجمالي العهد',    'value' => money0($totals['custody'])],
    };
    partial('drill', [
        'how'   => $how,
        'count' => count($rows),
        'total' => $totalRow,
        'more'  => ['label' => 'افتح البوالص', 'url' => url('bawalis')],
        'empty' => ['title' => 'مفيش بوالص اتحرّكت في الفترة دي'],
        'body'  => function () use ($rows) {
            foreach ($rows as $t) { include __DIR__ . '/_trip_row.php'; }
        },
    ]);
endif;
