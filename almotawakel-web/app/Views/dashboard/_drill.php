<?php
/**
 * أجسام نوافذ تفاصيل الداشبورد — المرحلة ١١.
 *
 * ⚠ بترندر **بلا layout** وبترجع HTML للنافذة العامة (`#uiDrill`)، وكل صف
 *    بيستخدم مكوّن `row_item` نفسه بتاع الصفحة — فمستحيل شكل التفاصيل يفترق
 *    عن القايمة اللي جاية منها.
 *
 * <?= icon('alert', 14) ?> ده بديل `#popStore` القديم: كان بيرندر تفاصيل كل البوب-أبات مع **كل**
 *    فتحة للصفحة حتى لو ما اتفتحتش، ونافذته كانت محلية بلا حبس تركيز.
 *
 * متغيّرات: $what $date (+ حسب النوع)
 */
require __DIR__ . '/_rows.php';   // نفس مُصيّري الصفحة بالحرف

$dayTxt = dfmt($date);

switch ($what) {

case 'expenses':
    partial('drill', [
        'how'   => 'كل مصروف اتسجّل على تاريخ العمل ' . $dayTxt . ' — من الأذونات والبوالص والمصروفات اليدوية وكاش المناديب.',
        'count' => count($rows),
        'total' => ['label' => 'مصروفات اليوم', 'value' => money($daily['expenses'], false)],
        'more'  => ['label' => 'سجل المصروفات', 'url' => url('expenses?from=' . rawurlencode($date) . '&to=' . rawurlencode($date))],
        'empty' => ['title' => 'مفيش مصروفات في ' . $dayTxt],
        'body'  => function () use ($rows) { foreach ($rows as $f) { dash_feed_row($f); } },
    ]);
    break;

case 'receipts':
    partial('drill', [
        'how'   => 'الإيصالات اللي اتسجّلت على تاريخ العمل ' . $dayTxt . ' جوّه الأذونات. المبلغ ده اللي اتدفع فعلاً، والفعلي للعميل بيتحطّ بعدين.',
        'count' => count($rows),
        'total' => ['label' => 'إجمالي الإيصالات', 'value' => money($daily['receipts_val'], false)],
        'more'  => ['label' => 'استكمال بيانات الإيصالات', 'url' => url('expenses/receipts')],
        'empty' => ['title' => 'مفيش إيصالات في ' . $dayTxt],
        'body'  => function () use ($rows) { foreach ($rows as $f) { dash_feed_row($f); } },
    ]);
    break;

case 'custody':
    partial('drill', [
        'how'   => 'خزنة المكتب = المرحَّل من الإقفال السابق + العهد المضافة − المصروفات − عهد السائقين. الرقم ده هو نفسه اللي بيظهر في شاشة التقفيل.',
        'total' => ['label' => 'رصيد الخزنة دلوقتي', 'value' => money($balance, false)],
        'more'  => ['label' => 'محفظة العهدة', 'url' => url('custody')],
        'empty' => ['title' => 'مفيش حركة عهدة في ' . $dayTxt],
        'body'  => function () use ($cday, $dayTxt) {
            partial('row_item', [
                'title' => 'عهد مضافة في ' . $dayTxt, 'icon' => 'wallet',
                'meta'  => [['icon' => 'info', 'text' => 'بتزوّد الخزنة']],
                'amount' => '+ ' . money($cday['topups_sum'], false), 'amount_tone' => 'pos',
            ]);
            partial('row_item', [
                'title' => 'عهد سائقين في ' . $dayTxt, 'icon' => 'truck',
                'meta'  => [['icon' => 'info', 'text' => 'المخصوم بعد خصم المستحق']],
                'amount' => '- ' . money($cday['drivers_sum'], false), 'amount_tone' => 'ex',
            ]);
            partial('row_item', [
                'title' => 'مصروفات ' . $dayTxt, 'icon' => 'trending-down',
                'meta'  => [['icon' => 'info', 'text' => 'أذونات ومصروفات يدوية وكاش مناديب']],
                'amount' => '- ' . money($cday['day_expense'], false), 'amount_tone' => 'ex',
            ]);
            foreach ($cday['topups'] as $t) {
                partial('row_item', [
                    'title' => 'إضافة عهدة', 'icon' => 'wallet',
                    'meta'  => array_values(array_filter([
                        $t['driver'] ? ['icon' => 'user', 'text' => $t['driver']] : null,
                        ['icon' => 'file-text', 'text' => $t['note'] ?: 'بلا ملاحظة'],
                    ])),
                    'amount' => '+ ' . money($t['amount'], false), 'amount_tone' => 'pos',
                    'sub' => dfmt($t['d']),
                ]);
            }
            foreach ($cday['drivers'] as $t) {
                partial('row_item', [
                    'title' => 'عهدة سائق', 'icon' => 'truck',
                    'meta'  => [['icon' => 'user', 'text' => $t['driver'] ?: '—'],
                                ['icon' => 'file-text', 'text' => $t['note'] ?: 'بلا ملاحظة']],
                    'amount' => money($t['amount'], false), 'amount_tone' => 'ex',
                    'sub' => dfmt($t['d']),
                ]);
            }
        },
    ]);
    break;

case 'reps':
    /* ⚠️ **اليوم ده بس** (قرار المالك): عهدة المناديب يومية بلا ترحيل (v49)،
       فولا رقم تراكمي هنا. اللي مالوش حركة النهاردة مش بيظهر أصلاً. */
    $rt = $reps['totals'];
    partial('drill', [
        'how'   => 'عهدة يوم ' . dfmt($date) . ' بس — بلا ترحيل من أي يوم قبله. فاضل معاه = اللي نزل له النهاردة − اللي صرفه المعتمد − اللي دفعه ولسه مستني اعتمادك. التراكمي (كل الأيام) مكانه صفحة عهدة المناديب.',
        'count' => count($reps['reps']),
        'total' => ['label' => 'فاضل معاهم النهاردة', 'value' => money($rt['day_pocket'], false)],
        'more'  => ['label' => 'عهدة المناديب', 'url' => url('rep-custody')],
        'empty' => ['title' => 'مفيش حركة عهدة في اليوم ده'],
        'body'  => function () use ($reps) {
            foreach ($reps['reps'] as $c) {
                $tags = [
                    ['icon' => 'wallet', 'text' => 'نزل ' . money($c['day_deposits'], false)],
                    ['icon' => 'trending-down', 'text' => 'صرف ' . money($c['day_spent'], false)],
                ];
                if (($c['day_transfer_in'] ?? 0) > 0) {
                    $tags[] = ['icon' => 'shuffle', 'text' => 'من زميل ' . money($c['day_transfer_in'], false)];
                }
                if (($c['day_transfer_out'] ?? 0) > 0) {
                    $tags[] = ['icon' => 'shuffle', 'text' => 'لزميل ' . money($c['day_transfer_out'], false)];
                }
                if (($c['day_pending_cnt'] ?? 0) > 0) {
                    $tags[] = ['icon' => 'hourglass',
                               'text' => intf($c['day_pending_cnt']) . ' مستنّي · ' . money($c['day_pending'], false)];
                }
                partial('row_item', [
                    'title' => $c['name'],
                    'icon'  => 'user',
                    'url'   => url('rep-custody/' . (int) $c['id'] . '?tab=day'),
                    'meta'  => $tags,
                    'chips' => $c['low'] ? [['icon' => 'alert', 'text' => 'محتاج تنزيل']] : [],
                    'amount' => money($c['day_pocket'], false),
                    'amount_tone' => $c['day_pocket'] < 0 ? 'neg' : 'pos',
                    'sub'   => 'فاضل معاه',
                ]);
            }
        },
    ]);
    break;

case 'touched':
    /* الإجمالي بيجمع المعتمَد **والمستنّي** — عشان الرقم يطابق اللي في اللوح. */
    $tSum = 0.0; $tPend = 0;
    foreach ($rows as $r) { $tSum += $r['rc_val'] + $r['ex_val'] + $r['pend_val']; $tPend += $r['pend_cnt']; }
    partial('drill', [
        'how'   => 'كل إذن اتضاف له إيصال أو مصروف على تاريخ العمل ' . $dayTxt
                 . ($tPend > 0 ? ' — ومنهم ' . intf($tPend) . ' بند لسه مستنّي اعتمادك.' : '.'),
        'count' => count($rows),
        'total' => ['label' => 'إجمالي اليوم', 'value' => money($tSum, false)],
        'more'  => ['label' => 'الأذونات', 'url' => url('permits')],
        'empty' => ['title' => 'مفيش إيصالات ولا مصروفات في ' . $dayTxt],
        'body'  => function () use ($rows) { foreach ($rows as $t) { dash_touched_row($t); } },
    ]);
    break;

case 'opened':
    partial('drill', [
        'how'   => 'الأذونات والشهادات اللي اتفتحت على تاريخ العمل ' . $dayTxt . '.',
        'count' => count($rows),
        'more'  => ['label' => 'الأذونات', 'url' => url('permits')],
        'empty' => ['title' => 'مفيش إذن اتفتح في ' . $dayTxt],
        'body'  => function () use ($rows) { foreach ($rows as $o) { dash_opened_row($o); } },
    ]);
    break;

case 'reps_day':
    /* الإجماليات هنا **نفس** اللي في الكارتين فوق — محسوبة من نفس المصفوفة. */
    $rDep = 0.0; $rSp = 0.0; $rPoc = 0.0;
    foreach ($rows as $r) {
        $rDep += (float) ($r['deposits'] ?? 0);
        $rSp  += (float) ($r['spent'] ?? 0);
        $rPoc += (float) ($r['pocket'] ?? 0);
    }
    partial('drill', [
        'how'   => 'عهدة كل مندوب في ' . $dayTxt . ' — نزل ' . money0($rDep, false)
                 . ' · اتصرف ' . money0($rSp, false) . ' · فاضل ' . money0($rPoc, false) . '. بلا أي تراكمي.',
        'count' => count($rows),
        'total' => ['label' => 'فاضل معاهم النهاردة', 'value' => money($rPoc, false)],
        'more'  => ['label' => 'إدارة العهدة', 'url' => url('rep-custody')],
        'empty' => ['title' => 'مفيش حركة عهدة في ' . $dayTxt],
        'body'  => function () use ($rows, $names) { dash_rep_rows($rows, $names); },
    ]);
    break;

case 'ready':
    partial('drill', [
        'how'   => 'أذونات اتقفلت وما اتعملّهاش فاتورة لسه. دي فلوس مستحقّة للشركة مستنية إصدار مستند.',
        'count' => $summary['count'],
        'total' => ['label' => 'إجمالي الحاويات', 'value' => intf($summary['containers'])],
        'more'  => ['label' => 'كل الجاهز للفوترة', 'url' => url('invoices/new')],
        'empty' => ['title' => 'تمام! مفيش إذن مستني فاتورة'],
        'body'  => function () use ($rows) { foreach ($rows as $p) { dash_permit_row($p, true); } },
    ]);
    break;

case 'permits_open':
    partial('drill', [
        'how'   => 'الأذونات والشهادات اللي لسه شغّالة — بيتسجّل عليها إيصالات ومصروفات، وما ينفعش تتفوتر قبل ما تتقفل.',
        'count' => $permits['open'],
        'total' => ['label' => 'إجمالي الحاويات', 'value' => intf($permits['containers'])],
        'more'  => ['label' => 'متابعة كل الأذونات', 'url' => url('permits')],
        'empty' => ['title' => 'مفيش أذونات مفتوحة'],
        'body'  => function () use ($rows) { foreach ($rows as $p) { dash_permit_row($p, false); } },
    ]);
    break;

default:   // activity — آخر ما اتسجّل في كل الوحدات (مش مربوط بتاريخ العمل)
    $kindMeta = [
        'bawalis'    => ['truck',     'بوليصة',         static fn ($r) => url('bawalis/' . $r['id'])],
        /* أرشيف: مفيش صفحة لـshamil فالصف بيتعرض بلا رابط بدل ما يوصّل 404 */
        'shamil'     => ['layers',    'فاتورة (أرشيف)',  static fn ($r) => null],
        'takhlis'    => ['clipboard', 'شهادة تخليص',    static fn ($r) => url('takhlis/' . $r['id'] . '/edit')],
        'generators' => ['cpu',       'مولدات',          static fn ($r) => url('generators/' . $r['id'] . '/edit')],
        'permits'    => ['folder',    'إصدار',           static fn ($r) => url('permits/' . $r['id'])],
        'payment'    => ['banknote',  'سداد',            static fn ($r) => url('settlements')],
    ];
    partial('drill', [
        'how'   => 'أحدث السجلات من كل الوحدات — مش مربوط بتاريخ العمل. ده سؤال تاني غير «فلوس اليوم».',
        'count' => count($rows),
        'more'  => ['label' => 'سجل العمليات', 'url' => url('audit')],
        'empty' => ['title' => 'لا توجد عمليات مسجّلة بعد'],
        'body'  => function () use ($rows, $kindMeta) {
            foreach ($rows as $a) {
                [$ic, $lbl, $linkFn] = $kindMeta[$a['kind']] ?? ['file-text', 'عملية', static fn ($r) => null];
                $amount = match ($a['kind']) {
                    'permits' => intf($a['amount']) . ' حاوية',
                    'payment' => '+ ' . money($a['amount'], false),
                    default   => null,
                };
                partial('row_item', [
                    'title'  => $lbl,
                    'ref'    => ($a['ref'] && $a['ref'] !== '—') ? '#' . $a['ref'] : null,
                    'icon'   => $ic,
                    'url'    => $linkFn($a),
                    'meta'   => [['icon' => 'user', 'text' => $a['party'] ?: 'بدون جهة']],
                    'amount' => $amount,
                    'amount_tone' => $a['kind'] === 'payment' ? 'pos' : '',
                    'sub'    => dfmt($a['d']),
                ]);
            }
        },
    ]);
}
