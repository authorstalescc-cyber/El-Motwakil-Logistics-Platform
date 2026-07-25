<?php
/**
 * أجسام نوافذ تفاصيل الداشبورد — المرحلة ١١.
 *
 * ⚠️ بترندر **بلا layout** وبترجع HTML للنافذة العامة (`#uiDrill`)، وكل صف
 *    بيستخدم مكوّن `row_item` نفسه بتاع الصفحة — فمستحيل شكل التفاصيل يفترق
 *    عن القايمة اللي جاية منها.
 *
 * ⚠️ ده بديل `#popStore` القديم: كان بيرندر تفاصيل كل البوب-أبات مع **كل**
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
        'empty' => ['title' => 'مفيش مصروفات في ' . $dayTxt, 'note' => 'غيّر يوم العمل أو سجّل مصروفًا جديدًا.'],
        'body'  => function () use ($rows) { foreach ($rows as $f) { dash_feed_row($f); } },
    ]);
    break;

case 'receipts':
    partial('drill', [
        'how'   => 'الإيصالات اللي اتسجّلت على تاريخ العمل ' . $dayTxt . ' جوّه الأذونات. المبلغ ده اللي اتدفع فعلاً، والفعلي للعميل بيتحطّ بعدين.',
        'count' => count($rows),
        'total' => ['label' => 'إجمالي الإيصالات', 'value' => money($daily['receipts_val'], false)],
        'more'  => ['label' => 'استكمال بيانات الإيصالات', 'url' => url('expenses/receipts')],
        'empty' => ['title' => 'مفيش إيصالات في ' . $dayTxt, 'note' => 'استخدم «الإدخال السريع» لتسجيل إيصالات اليوم.'],
        'body'  => function () use ($rows) { foreach ($rows as $f) { dash_feed_row($f); } },
    ]);
    break;

case 'custody':
    partial('drill', [
        'how'   => 'خزنة المكتب = المرحَّل من الإقفال السابق + العهد المضافة − المصروفات − عهد السائقين. الرقم ده هو نفسه اللي بيظهر في شاشة التقفيل.',
        'total' => ['label' => 'رصيد الخزنة دلوقتي', 'value' => money($balance, false)],
        'more'  => ['label' => 'محفظة العهدة', 'url' => url('custody')],
        'empty' => ['title' => 'مفيش حركة عهدة في ' . $dayTxt, 'note' => 'الرصيد فوق تراكمي من كل الأيام.'],
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
    $rt = $reps['totals'];
    partial('drill', [
        'how'   => 'الفلوس اللي برّه مع المناديب = اللي نزل لهم − اللي صرفوه المعتمد − اللي رجّعوه. تنزيل العهدة ما بيخصمش من الخزنة؛ الخزنة بتنقص وقت ما البند يتعتمد بتاريخ صرفه.',
        'count' => count($reps['reps']),
        'total' => ['label' => 'برّه مع المناديب', 'value' => money($rt['outstanding'], false)],
        'more'  => ['label' => 'عهدة المناديب', 'url' => url('rep-custody')],
        'empty' => ['title' => 'مفيش عهدة نازلة لأي مندوب', 'note' => 'نزّل عهدة من كارت المندوب.'],
        'body'  => function () use ($reps) {
            foreach ($reps['reps'] as $c) {
                $tags = [
                    ['icon' => 'wallet', 'text' => 'نازل ' . money($c['deposits'], false)],
                    ['icon' => 'trending-down', 'text' => 'صرف ' . money($c['spent'], false)],
                ];
                if ($c['pending_cnt'] > 0) {
                    $tags[] = ['icon' => 'hourglass', 'text' => intf($c['pending_cnt']) . ' مستنّي · ' . money($c['pending_sum'], false)];
                }
                partial('row_item', [
                    'title' => $c['name'],
                    'icon'  => 'user',
                    'url'   => url('rep-custody/' . (int) $c['id'] . '?tab=day'),
                    'meta'  => $tags,
                    'chips' => $c['low'] ? [['icon' => 'alert', 'text' => 'محتاج تنزيل']] : [],
                    'amount' => money($c['balance'], false),
                    'amount_tone' => $c['balance'] < 0 ? 'neg' : 'pos',
                    'sub'   => 'برّه معاه',
                ]);
            }
        },
    ]);
    break;

case 'ready':
    partial('drill', [
        'how'   => 'أذونات اتقفلت وما اتعملّهاش فاتورة لسه. دي فلوس مستحقّة للشركة مستنية إصدار مستند.',
        'count' => $summary['count'],
        'total' => ['label' => 'إجمالي الحاويات', 'value' => intf($summary['containers'])],
        'more'  => ['label' => 'كل الجاهز للفوترة', 'url' => url('invoices/new')],
        'empty' => ['title' => 'تمام! مفيش إذن مستني فاتورة', 'note' => 'كل الأذونات المقفولة اتعملّها مستند.'],
        'body'  => function () use ($rows) { foreach ($rows as $p) { dash_permit_row($p, true); } },
    ]);
    break;

case 'permits_open':
    partial('drill', [
        'how'   => 'الأذونات والشهادات اللي لسه شغّالة — بيتسجّل عليها إيصالات ومصروفات، وما ينفعش تتفوتر قبل ما تتقفل.',
        'count' => $permits['open'],
        'total' => ['label' => 'إجمالي الحاويات', 'value' => intf($permits['containers'])],
        'more'  => ['label' => 'متابعة كل الأذونات', 'url' => url('permits')],
        'empty' => ['title' => 'مفيش أذونات مفتوحة', 'note' => 'افتح إذنًا جديدًا من شاشة الأذونات.'],
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
