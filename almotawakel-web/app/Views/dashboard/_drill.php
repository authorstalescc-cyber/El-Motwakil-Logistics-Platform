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

case 'safe_day':
    /* ⚠️ **المعادلة بترسم بالترتيب اللي على الشاشة بالحرف** — النافذة بتشرح
       سطر المعادلة مش بتقدّم حساب تاني. `office` جزء من `expenses` فبيتطرح
       منها هنا زي ما بيتطرح فوق (وإلا الفلوس تتقري مرتين). */
    $sExp = round((float) $day['expenses'] - (float) $office, 2);
    partial('drill', [
        'how'   => 'خزنة المكتب في ' . $dayTxt . ': فتحت بـ' . money0($day['opening'], false)
                 . ' · دخلها ' . money0($day['topups'], false)
                 . ' · نزل للمكتب ' . money0($office, false)
                 . ' · مصروفات ' . money0($sExp, false)
                 . ' · عهدة سواقين ' . money0($day['driver'], false) . '.',
        'total' => ['label' => $day['is_locked'] ? 'قفلنا على' : 'الرصيد دلوقتي',
                    'value' => money($day['closing'], false)],
        'more'  => ['label' => 'شاشة التقفيل', 'url' => url('closing')],
        'empty' => ['title' => 'مفيش حركة خزنة في ' . $dayTxt],
        'body'  => function () use ($day, $office, $sExp, $dayTxt, $cday) {
            partial('row_item', ['title' => 'فتحنا اليوم بـ', 'icon' => 'briefcase',
                'meta' => [['icon' => 'info', 'text' => 'المرحَّل من آخر إقفال']],
                'amount' => money($day['opening'], false)]);
            partial('row_item', ['title' => 'دخل الخزنة في ' . $dayTxt, 'icon' => 'wallet',
                'meta' => [['icon' => 'info', 'text' => 'عهد مضافة للدرج']],
                'amount' => '+ ' . money($day['topups'], false), 'amount_tone' => 'pos']);
            if ($office > 0.005) {
                partial('row_item', ['title' => 'نزل للمكتب (عهدة مناديب)', 'icon' => 'users',
                    'meta' => [['icon' => 'info', 'text' => 'مسحوب من الدرج للمناديب']],
                    'amount' => '- ' . money($office, false), 'amount_tone' => 'ex']);
            }
            partial('row_item', ['title' => 'مصروفات ' . $dayTxt, 'icon' => 'trending-down',
                'meta' => [['icon' => 'info', 'text' => 'أذونات ومصروفات يدوية وكاش مناديب']],
                'amount' => '- ' . money($sExp, false), 'amount_tone' => 'ex']);
            partial('row_item', ['title' => 'عهد سائقين في ' . $dayTxt, 'icon' => 'truck',
                'meta' => [['icon' => 'info', 'text' => 'المخصوم بعد خصم المستحق']],
                'amount' => '- ' . money($day['driver'], false), 'amount_tone' => 'ex']);
            // بنود العهد الفعلية — نفس مصدر نافذة «رصيد الخزنة» بالحرف
            foreach ($cday['topups'] as $t) {
                partial('row_item', ['title' => 'إضافة عهدة', 'icon' => 'wallet',
                    'meta' => array_values(array_filter([
                        $t['driver'] ? ['icon' => 'user', 'text' => $t['driver']] : null,
                        ['icon' => 'file-text', 'text' => $t['note'] ?: 'بلا ملاحظة'],
                    ])),
                    'amount' => '+ ' . money($t['amount'], false), 'amount_tone' => 'pos',
                    'sub' => dfmt($t['d'])]);
            }
            foreach ($cday['drivers'] as $t) {
                partial('row_item', ['title' => 'عهدة سائق', 'icon' => 'truck',
                    'meta' => [['icon' => 'user', 'text' => $t['driver'] ?: '—'],
                               ['icon' => 'file-text', 'text' => $t['note'] ?: 'بلا ملاحظة']],
                    'amount' => money($t['amount'], false), 'amount_tone' => 'ex',
                    'sub' => dfmt($t['d'])]);
            }
        },
    ]);
    break;

case 'cars':
    $cFreight = 0.0; $cConts = 0;
    foreach ($rows as $c) { $cFreight += (float) $c['freight']; $cConts += (int) $c['container_count']; }
    partial('drill', [
        'how'   => 'كل بوليصة اتسجّلت على تاريخ العمل ' . $dayTxt . ' — البوليصة الواحدة = نقلة عربية واحدة.',
        'count' => count($rows),
        'total' => ['label' => 'نولون اليوم', 'value' => money($cFreight, false)],
        'more'  => ['label' => 'سجل البوالص', 'url' => url('bawalis')],
        'empty' => ['title' => 'مفيش عربية خرجت في ' . $dayTxt],
        'body'  => function () use ($rows) { foreach ($rows as $c) { dash_car_row($c); } },
    ]);
    break;

case 'needs_closing':
    /* ⚠️ التعريف قرار المالك: خرجت كل سياراته **و**إيصالاته مكتملة. النافذة
       بتقرا من `PermitReadiness` نفسها اللي عدّت الكارت — بلا حساب تاني. */
    partial('drill', [
        'how'   => 'أذونات خرجت كل عربياتها وإيصالاتها مكتملة — يعني خلصت شغلها وفاضل تقفلها.',
        'count' => count($rows),
        'total' => ['label' => 'من أصل مفتوح', 'value' => intf($counts['open']) . ' إذن'],
        'more'  => ['label' => 'كل الأذونات المفتوحة', 'url' => url('permits?view=records&st=open')],
        'empty' => ['title' => 'مفيش إذن خلص شغله لسه'],
        'body'  => function () use ($rows) { foreach ($rows as $r) { dash_readiness_row($r); } },
    ]);
    break;

case 'receipt_gaps':
    partial('drill', [
        'how'   => 'أذونات خرجت كل عربياتها لكن مرشد الإيصالات شايف إن فيه بنود ناقصة — يا غايبة خالص يا عددها أقل من المتوقّع.',
        'count' => count($rows),
        'total' => ['label' => 'من أصل مفتوح', 'value' => intf($counts['open']) . ' إذن'],
        'more'  => ['label' => 'موديل الإيصالات', 'url' => url('receipt-model')],
        'empty' => ['title' => 'مفيش إذن ناقصه إيصالات'],
        'body'  => function () use ($rows) { foreach ($rows as $r) { dash_readiness_row($r); } },
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

/* ⚠️ **`permits_open` هي الحالة الافتراضية عن قصد** — النوع محصور في قائمة
   بيضا في `DashboardController::drill()`، فالوصول هنا بنوع مجهول مستحيل.
   وخليها حالة حقيقية أأمن من `default` فاضية بترجّع HTML فاضي بلا سبب ظاهر.

   ⚠️ **`activity` («آخر ما اتسجّل») اتشالت (2026-08-17)** — الزرار اللي كان
      بيفتحها اتشال من الصفحة، والسجل الكامل موجود في `/audit` بفلاتر أكتر.
      نافذة بلا نقطة استدعاء = كود ميت بيتصان مجانًا. */
default:   // permits_open
    partial('drill', [
        'how'   => 'الأذونات والشهادات اللي لسه شغّالة — بيتسجّل عليها إيصالات ومصروفات، وما ينفعش تتفوتر قبل ما تتقفل.',
        'count' => $permits['open'],
        'total' => ['label' => 'إجمالي الحاويات', 'value' => intf($permits['containers'])],
        'more'  => ['label' => 'متابعة كل الأذونات', 'url' => url('permits')],
        'empty' => ['title' => 'مفيش أذونات مفتوحة'],
        'body'  => function () use ($rows) { foreach ($rows as $p) { dash_permit_row($p, false); } },
    ]);
}
