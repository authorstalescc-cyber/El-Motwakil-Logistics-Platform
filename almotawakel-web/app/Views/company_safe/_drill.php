<?php
/**
 * أجسام نوافذ تفاصيل خزنة الشركة (v66 · موسّعة في v67).
 *
 * ⚠️ بترندر بلا layout وبترجع HTML للنافذة العامة (`#uiDrill`)، وبتقرا من نفس
 *    دوال `CompanySafe` اللي أرقام الشاشة اتحسبت منها — فمستحيل الرقم يختلف.
 * ⚠️ **الاتجاه والأيقونة من الموديل** (`isIn()`/`icon()`) مش من خريطة محلية —
 *    النسخة القديمة كانت بتفترض إن `to_office` هو الوحيد الخارج، فأول نوع
 *    خارج جديد (مصروف · شحن فيزا · عهدة مندوب) كان هيتعرض **موجب** بالسكوت.
 *
 * متغيّرات: $what $day $mStart $mEnd $breakdown $balance $rows $budget $cats $parties $wallets
 */
use App\Models\CompanySafe;

$labels = CompanySafe::labels();

/** صف حركة واحد — نفس شكل صفوف الشاشة بالظبط. */
$entryRow = static function (array $r) use ($labels): void {
    $kind = (string) $r['kind'];
    $isIn = CompanySafe::isIn($kind);
    $meta = [['icon' => 'calendar', 'text' => dfmt((string) $r['entry_date'])]];
    // ⚠️ نفس قاعدة الشاشة: الجهة عنوان والنوع ميتا — النافذة والقايمة لازم يتطابقوا.
    if (!empty($r['subtitle'])) { $meta[] = ['icon' => CompanySafe::icon($kind), 'text' => (string) $r['subtitle']]; }
    /* ⚠️ **اسم الكارت/المندوب** (طلب المالك: «لو من فيزا يتم كتابة أي فيزا») —
       «سحب كاش من كارت فيزا» من غير اسم الكارت ما بتجاوبش على السؤال. */
    if (!empty($r['wallet'])) {
        $meta[] = ['icon' => in_array($kind, CompanySafe::VISA_KINDS, true) ? 'credit-card' : 'truck',
                   'text' => (string) $r['wallet']];
    }
    if (!empty($r['category'])) { $meta[] = ['icon' => 'tag', 'text' => CompanySafe::catLabel((string) $r['category'])]; }
    if ($r['note'])   { $meta[] = ['icon' => 'file-text', 'text' => (string) $r['note']]; }
    if (!empty($r['custody_id']))      { $meta[] = ['icon' => 'landmark', 'text' => 'أثّرت على خزنة المكتب']; }
    if (!empty($r['visa_deposit_id'])) { $meta[] = ['icon' => 'landmark', 'text' => 'أثّرت على الحساب البنكي']; }
    if (!empty($r['rep_deposit_id']))  { $meta[] = ['icon' => 'truck', 'text' => 'نزلت لمندوب']; }
    partial('row_item', [
        'title'  => (string) ($r['title'] ?? ($labels[$kind] ?? $kind)),
        'icon'   => CompanySafe::icon($kind),
        'meta'   => $meta,
        'amount' => ($isIn ? '+ ' : '- ') . money((float) $r['amount'], false),
        'amount_tone' => $isIn ? 'pos' : 'ex',
    ]);
};

/** صافي مجموعة صفوف — بنفس تعبير الاتجاه بتاع `balance()`. */
$sumOf = static function (array $rows): float {
    $t = 0.0;
    foreach ($rows as $r) {
        $t += CompanySafe::isIn((string) $r['kind']) ? (float) $r['amount'] : -(float) $r['amount'];
    }
    return round($t, 2);
};

/** إجمالي الداخل / الخارج من التفكيك — المصدر الوحيد `isIn()`. */
$sideTotal = static function (bool $in) use ($breakdown): float {
    $t = 0.0;
    foreach (CompanySafe::KINDS as $k) {
        if (CompanySafe::isIn($k) === $in) { $t += (float) ($breakdown[$k] ?? 0); }
    }
    return round($t, 2);
};

switch ($what) {

/* ⚠️ **مفيش رقم بيتحسب من جديد**: `Closing::walletSplit()` بتنادي
   `currentBalance` و`RepCustody::balance` و`CompanySafe::balance` نفسهم بتوع
   كل شاشة تانية — فمستحيل النافذة دي تقول رقمًا يخالف كارت المندوب أو التقفيل. */
case 'wallets':
    partial('drill', [
        'how'   => 'كل الكاش اللي برّه البنك دلوقتي وهو موزّع على المحافظ. خزنة الشركة بتغذّي درج المكتب، والدرج بينزّل عهد للمناديب.',
        'count' => 2 + count($wallets['reps']),
        'total' => ['label' => 'كل الكاش برّه البنك', 'value' => money($wallets['grand'], false)],
        'more'  => ['label' => 'خزنة المكتب', 'url' => url('custody')],
        'body'  => function () use ($wallets) {
            partial('row_item', [
                'title' => 'خزنة الشركة', 'icon' => 'home',
                'amount' => money($wallets['company'], false),
                'amount_tone' => $wallets['company'] < 0 ? 'neg' : 'pos',
            ]);
            partial('row_item', [
                'title' => 'درج المكتب', 'icon' => 'landmark',
                'url'   => url('custody'),
                'amount' => money($wallets['drawer'], false),
                'amount_tone' => $wallets['drawer'] < 0 ? 'neg' : 'pos',
            ]);
            foreach ($wallets['reps'] as $w) {
                partial('row_item', [
                    'title' => $w['name'], 'icon' => 'user',
                    'url'   => url('rep-custody/' . (int) $w['id']),
                    'meta'  => [['icon' => 'truck', 'text' => 'عهدة مندوب']],
                    'amount' => money($w['balance'], false),
                    'amount_tone' => $w['balance'] < 0 ? 'neg' : 'pos',
                ]);
            }
        },
    ]);
    break;

/* ═══ نوافذ كروت النظرة العامة — كل واحدة **بنطاق كارتها بالظبط** ═══
   ⚠️ المجموع بيتحسب من **نفس الصفوف المعروضة** لا من استعلام تاني: أي اختلاف
      في الشرط بين الرقم وبنوده هو بالظبط «البيان المضلِّل» اللي المالك رفضه. */
case 'day_in':
    partial('drill', [
        'how'   => 'كل اللي دخل خزنة الشركة في يوم العمل ده — بمصدره: بنك ولا عميل ولا رجوع من حساب بنكي.',
        'count' => count($rows),
        'total' => ['label' => 'دخل يوم ' . dfmt($day),
                    'value' => money(array_sum(array_map(static fn ($r) => (float) $r['amount'], $rows)), false)],
        'more'  => ['label' => 'كل الداخل', 'url' => url('company-safe') . '?tab=moves&from=' . $day . '&to=' . $day],
        'empty' => ['title' => 'مفيش حاجة دخلت النهارده'],
        'body'  => function () use ($rows, $entryRow) { foreach ($rows as $r) { $entryRow($r); } },
    ]);
    break;

case 'day_to_office':
    partial('drill', [
        'how'   => 'اللي اتحوّل من خزنة الشركة لخزنة المكتب في اليوم ده — وبيبان في التقفيل اليومي كإضافة عهدة.',
        'count' => count($rows),
        'total' => ['label' => 'نزل للمكتب يوم ' . dfmt($day),
                    'value' => money(array_sum(array_map(static fn ($r) => (float) $r['amount'], $rows)), false)],
        'more'  => ['label' => 'خزنة المكتب', 'url' => url('custody')],
        'empty' => ['title' => 'مفيش تحويل للمكتب النهارده'],
        'body'  => function () use ($rows, $entryRow) { foreach ($rows as $r) { $entryRow($r); } },
    ]);
    break;

case 'day_expense':
    partial('drill', [
        'how'   => 'المصروفات اللي خرجت من خزنة الشركة في اليوم ده. معزولة عن سجل المصروفات العام.',
        'count' => count($rows),
        'total' => ['label' => 'مصروفات يوم ' . dfmt($day),
                    'value' => money(array_sum(array_map(static fn ($r) => (float) $r['amount'], $rows)), false)],
        'more'  => ['label' => 'كل المصروفات', 'url' => url('company-safe') . '?tab=expenses'],
        'empty' => ['title' => 'مفيش مصروفات النهارده'],
        'body'  => function () use ($rows, $entryRow) { foreach ($rows as $r) { $entryRow($r); } },
    ]);
    break;

/* ⚠️⚠️ **الكارتان دول رقمان مختلفان فعلاً** — «عهدة المكتب» بتشمل اللي في جيوب
   المناديب، و«الفعلي في الدرج» هو اللي المالك يقدر يعدّه بإيده. النافذتان
   بتوضّحا الفرق صراحةً بدل ما المالك يفتكرهم نفس الرقم. */
case 'office_total':
    partial('drill', [
        'how'   => 'عهدة المكتب = الكاش اللي في الدرج + اللي في جيوب المناديب. دي مش «الفعلي في الدرج».',
        'count' => 1 + count($wallets['reps']),
        'total' => ['label' => 'عهدة المكتب الحالية', 'value' => money($wallets['total'], false)],
        'more'  => ['label' => 'خزنة المكتب', 'url' => url('custody')],
        'body'  => function () use ($wallets) {
            partial('row_item', [
                'title' => 'الفعلي في الدرج', 'icon' => 'landmark',
                'url'   => url('custody'),
                'amount' => money($wallets['drawer'], false),
                'amount_tone' => $wallets['drawer'] < 0 ? 'neg' : 'pos',
            ]);
            foreach ($wallets['reps'] as $w) {
                partial('row_item', [
                    'title' => $w['name'], 'icon' => 'truck',
                    'url'   => url('rep-custody/' . (int) $w['id']),
                    'meta'  => [['icon' => 'user', 'text' => 'في جيب المندوب']],
                    'amount' => money($w['balance'], false),
                    'amount_tone' => $w['balance'] < 0 ? 'neg' : 'pos',
                ]);
            }
        },
    ]);
    break;

case 'office_drawer':
    partial('drill', [
        'how'   => 'الكاش اللي في درج المكتب دلوقتي — من غير اللي مع المناديب. ده الرقم اللي المفروض تعدّه بإيدك.',
        'count' => 2,
        'total' => ['label' => 'الفعلي في الدرج', 'value' => money($wallets['drawer'], false)],
        'more'  => ['label' => 'افتح خزنة المكتب', 'url' => url('custody')],
        'body'  => function () use ($wallets) {
            partial('row_item', [
                'title' => 'عهدة المكتب كلها', 'icon' => 'wallet',
                'amount' => money($wallets['total'], false), 'amount_tone' => 'pos',
            ]);
            partial('row_item', [
                'title' => 'ناقص اللي مع المناديب', 'icon' => 'truck',
                'meta'  => [['icon' => 'users', 'text' => intf(count($wallets['reps'])) . ' مندوب']],
                'amount' => '- ' . money($wallets['reps_total'], false), 'amount_tone' => 'ex',
            ]);
        },
    ]);
    break;

case 'in':
    $rows = array_values(array_filter($rows, static fn ($r) => CompanySafe::isIn((string) $r['kind'])));
    partial('drill', [
        'how'   => 'كل الكاش اللي دخل خزنة الشركة: إيداعات ومسحوبات البنك + تحصيل العملاء + اللي رجع من خزنة المكتب أو من حساب بنكي.',
        'count' => count($rows),
        'total' => ['label' => 'إجمالي الداخل', 'value' => money($sideTotal(true), false)],
        'more'  => ['label' => 'كل الحركات', 'url' => url('company-safe')],
        'empty' => ['title' => 'مفيش حركات داخلة لسه'],
        'body'  => function () use ($rows, $entryRow) { foreach ($rows as $r) { $entryRow($r); } },
    ]);
    break;

case 'out':
    $rows = array_values(array_filter($rows, static fn ($r) => !CompanySafe::isIn((string) $r['kind'])));
    partial('drill', [
        'how'   => 'كل الكاش اللي خرج من خزنة الشركة: تحويل للمكتب + مصروفات + تحويلات للحسابات البنكية + عهد المناديب.',
        'count' => count($rows),
        'total' => ['label' => 'إجمالي الخارج', 'value' => money($sideTotal(false), false)],
        'more'  => ['label' => 'كل الحركات', 'url' => url('company-safe')],
        'empty' => ['title' => 'مفيش حركات خارجة لسه'],
        'body'  => function () use ($rows, $entryRow) { foreach ($rows as $r) { $entryRow($r); } },
    ]);
    break;

case 'to_office':
    partial('drill', [
        'how'   => 'كل تحويل من خزنة الشركة لخزنة المكتب. كل تحويل بيتسجّل كإضافة عهدة في خزنة المكتب وبيبان في التقفيل اليومي.',
        'count' => count($rows),
        'total' => ['label' => 'إجمالي المحوَّل للمكتب', 'value' => money($breakdown['to_office'], false)],
        'more'  => ['label' => 'خزنة المكتب', 'url' => url('custody')],
        'empty' => ['title' => 'مفيش تحويلات للمكتب لسه'],
        'body'  => function () use ($rows, $entryRow) { foreach ($rows as $r) { $entryRow($r); } },
    ]);
    break;

/* ⚠️ المصروفات والميزانية على **نفس شهر** الكارت اللي فوقهم بالظبط — الفترة
   بتيجي من الكنترولر (`$mStart`/`$mEnd`) مش بتتحسب هنا من جديد. */
case 'expense':
    partial('drill', [
        'how'   => 'مصروفات خزنة الشركة في الشهر ده. معزولة عن سجل المصروفات العام — مش داخلة في تقارير المصروفات ولا الداشبورد.',
        'count' => count($rows),
        'total' => ['label' => 'مصروفات ' . dfmt($mStart) . ' — ' . dfmt($mEnd),
                    'value' => money(array_sum(array_map(static fn ($r) => (float) $r['amount'], $rows)), false)],
        'more'  => ['label' => 'كل المصروفات', 'url' => url('company-safe') . '?kind=expense'],
        'empty' => ['title' => 'مفيش مصروفات الشهر ده'],
        'body'  => function () use ($rows, $entryRow) { foreach ($rows as $r) { $entryRow($r); } },
    ]);
    break;

case 'budget':
    partial('drill', [
        'how'   => 'السقف الشهري تنبيه بس — ما بيمنعش أي تسجيل. المصروف بيتخصم من رصيد خزنة الشركة عادي.',
        'count' => count($rows),
        'total' => ['label' => $budget['on'] ? 'المصروف من ' . money($budget['budget'], false) : 'مصروفات الشهر',
                    'value' => money($budget['spent'], false)],
        'more'  => ['label' => 'كل المصروفات', 'url' => url('company-safe') . '?kind=expense'],
        'empty' => ['title' => 'مفيش مصروفات الشهر ده'],
        'body'  => function () use ($rows, $entryRow) { foreach ($rows as $r) { $entryRow($r); } },
    ]);
    break;

case 'cats':
    partial('drill', [
        'how'   => 'مصروفات الشهر مقسومة بالفئة. اضغط أي فئة تشوف بنودها.',
        'count' => count($cats),
        'total' => ['label' => 'إجمالي مصروفات الشهر',
                    'value' => money(array_sum(array_column($cats, 'total')), false)],
        'empty' => ['title' => 'مفيش مصروفات الشهر ده'],
        'body'  => function () use ($cats) {
            foreach ($cats as $c) {
                partial('row_item', [
                    'title' => $c['label'], 'icon' => 'tag',
                    'url'   => url('company-safe') . '?cat=' . urlencode($c['key']),
                    'meta'  => [['icon' => 'layers', 'text' => intf($c['count']) . ' بند'],
                                ['icon' => 'bar-chart', 'text' => $c['pct'] . '%']],
                    'amount' => money($c['total'], false), 'amount_tone' => 'ex',
                ]);
            }
        },
    ]);
    break;

case 'parties':
    partial('drill', [
        'how'   => 'صرفنا كام لكل جهة الشهر ده. التهجئات المختلفة بتفضل صفوفًا منفصلة — دمجها قرار من «البيانات الأساسية».',
        'count' => count($parties),
        'total' => ['label' => 'إجمالي مصروفات الشهر',
                    'value' => money(array_sum(array_column($parties, 'total')), false)],
        'empty' => ['title' => 'مفيش مصروفات الشهر ده'],
        'body'  => function () use ($parties) {
            foreach ($parties as $p) {
                partial('row_item', [
                    'title' => $p['party'], 'icon' => 'user',
                    'meta'  => [['icon' => 'layers', 'text' => intf($p['count']) . ' بند']],
                    'amount' => money($p['total'], false), 'amount_tone' => 'ex',
                ]);
            }
        },
    ]);
    break;

case 'day':
    partial('drill', [
        'how'   => 'كل حركات خزنة الشركة في يوم العمل الحالي.',
        'count' => count($rows),
        'total' => ['label' => 'صافي حركة اليوم', 'value' => money($sumOf($rows), false)],
        'more'  => ['label' => 'كل الحركات', 'url' => url('company-safe')],
        'empty' => ['title' => 'مفيش حركة في اليوم ده'],
        'body'  => function () use ($rows, $entryRow) { foreach ($rows as $r) { $entryRow($r); } },
    ]);
    break;

default:   // balance
    partial('drill', [
        'how'   => 'رصيد خزنة الشركة = كل الداخل − كل الخارج. رصيد جارٍ مستمر بلا إقفال يومي.',
        'total' => ['label' => 'رصيد خزنة الشركة', 'value' => money($balance, false)],
        'more'  => ['label' => 'كل الحركات', 'url' => url('company-safe')],
        'body'  => function () use ($breakdown, $labels) {
            foreach (CompanySafe::KINDS as $k) {
                if (abs((float) ($breakdown[$k] ?? 0)) < 0.005) { continue; }
                $in = CompanySafe::isIn($k);
                partial('row_item', [
                    'title'  => $labels[$k], 'icon' => CompanySafe::icon($k),
                    'amount' => ($in ? '+ ' : '- ') . money((float) $breakdown[$k], false),
                    'amount_tone' => $in ? 'pos' : 'ex',
                ]);
            }
        },
    ]);
}
