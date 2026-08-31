<?php
/**
 * نوافذ تفاصيل خزنة المكتب — «ممنوع رقم أصمّ».
 *
 * ⚠️⚠️ **الشاشة القديمة كانت بتوجّه أرقامها الأربعة لنفس النافذة بالحرف**
 *    (`drill => '#custodyBreak'` في الأربعة)، يعني تقنيًا مفيش رقم أصمّ لكن
 *    عمليًا ضغط أي رقم بيدّي نفس الجواب. هنا كل رقم له بنوده هو.
 *
 * ⚠️ **ولا استعلام جديد** — كل رقم بيتقرا من نفس الدالة اللي الكارت اتحسب
 *    منها (`walletSplit` · `currentBreakdown` · `movements`).
 *
 * متغيّرات: $what $from $to $rate $wallet $breakdown $aggregate $move
 */
use App\Models\Custody;

/** صفوف الفترة بقسم معيّن — بترجع فاضية لو التبويب ما حمّلش الصفوف. */
$byGroup = static function (array $groups) use ($move): array {
    $out = [];
    foreach (($move['rows'] ?? []) as $r) {
        if (in_array($r['group'], $groups, true)) { $out[] = $r; }
    }
    return $out;
};
/** ⚠️ الصفوف المقطوعة بتتقال بالنص — ممنوع قطع صامت حتى جوّه نافذة. */
$cut = static function () use ($move): ?string {
    if (!$move) { return null; }
    if ($move['capped'])   { return 'الفترة أطول من السقف — الإجمالي كامل والصفوف مخفية'; }
    if ($move['truncated']) { return 'آخر ' . intf(count($move['rows'])) . ' من ' . intf($move['rows_total']); }
    return null;
};
$rangeLbl = $move ? dfmt($from) . ' → ' . dfmt($to) : null;

/** صفوف حركة عادية. */
$moveRows = static function (array $rows): void {
    foreach ($rows as $r) {
        $meta = [['icon' => 'calendar', 'text' => dfmt($r['date'])]];
        if ($r['ref'])   { $meta[] = ['icon' => 'file-text', 'text' => $r['ref']]; }
        if ($r['party']) { $meta[] = ['icon' => 'user', 'text' => $r['party']]; }
        partial('row_item', [
            'title'  => $r['title'],
            'icon'   => $r['icon'],
            'url'    => $r['link'] ? url($r['link']) : null,
            'meta'   => $meta,
            'amount' => ($r['signed'] >= 0 ? '+ ' : '- ') . money(abs($r['signed']), false),
            'amount_tone' => $r['signed'] >= 0 ? 'pos' : 'ex',
        ]);
    }
};
/* ⚠️⚠️ **الإجمالي من `group_totals` (الفترة كلها) لا من الصفوف المعروضة.**
   جمع الصفوف المعروضة بيدّي رقمًا يخالف الكارت أول ما الصفوف تتقص — مقيس على
   الإنتاج: المصروفات من الصفوف المعروضة ٥١١,٠٧١ والحقيقية ١,٢٣٨,٧٣٢
   (**فرق ٧٢٧,٦٦١**). ده نفس مرض «رقمين على نفس الشاشة» في صورة تالتة. */
/* ⚠️ `group_totals` بالإشارة (الخارج سالب) — العرض بياخد المقدار. */
$groupSum = static function (array $groups) use ($move): float {
    $t = 0.0;
    foreach ($groups as $g) { $t += (float) ($move['group_totals'][$g] ?? 0); }
    return round(abs($t), 2);
};
/** عدد البنود الحقيقي في الفترة — لا عدد المعروض. */
$groupCount = static function (array $groups) use ($move): int {
    $n = 0;
    foreach (($move['rows'] ?? []) as $r) { if (in_array($r['group'], $groups, true)) { $n++; } }
    return $n;
};
?>
<?php if ($what === 'balance'): ?>
  <?php partial('drill', [
    'how'   => 'الرصيد = رصيد آخر إقفال مثبَّت + الإضافات بعده − المصروفات − عهد السائقين.',
    'total' => ['label' => 'الرصيد الحالي', 'value' => money($breakdown['balance'], false)],
    'more'  => ['label' => 'التقفيل اليومي', 'url' => url('closing')],
    'body'  => function () use ($breakdown, $rate, $wallet, $aggregate) {
      partial('row_item', [
        'title' => $breakdown['anchorDate'] ? 'رصيد آخر إقفال (' . dfmt($breakdown['anchorDate']) . ')' : 'من البداية',
        'icon'  => 'lock',
        'meta'  => [['icon' => 'info', 'text' => 'نقطة البداية المثبَّتة']],
        'amount' => money($breakdown['anchor'], false),
      ]);
      partial('row_item', [
        'title' => 'إضافات عهدة' . ($breakdown['anchorDate'] ? ' بعده' : ''),
        'icon'  => 'wallet',
        'meta'  => [['icon' => 'info', 'text' => 'بتزوّد الخزنة']],
        'amount' => '+ ' . money($breakdown['topups'], false), 'amount_tone' => 'pos',
      ]);
      partial('row_item', [
        'title' => 'المصروفات',
        'icon'  => 'trending-down',
        'meta'  => [['icon' => 'info', 'text' => 'أذونات ومصروفات يدوية وكاش مناديب']],
        'amount' => '- ' . money($breakdown['expenses'], false), 'amount_tone' => 'ex',
      ]);
      partial('row_item', [
        'title' => 'عهد السائقين (بعد خصم ' . intf($rate) . ')',
        'icon'  => 'truck',
        'meta'  => [['icon' => 'info', 'text' => 'المخصوم من الدرج']],
        'amount' => '- ' . money($breakdown['driver'], false), 'amount_tone' => 'ex',
      ]);
      /* ⚠️ في النموذج المجمّع الرقم فوق شامل جيوب المناديب — لازم النافذة
         تفكّه وإلا «الرصيد» بيتقري كأنه كله في الدرج. */
      if ($aggregate) {
        partial('row_item', [
          'title' => 'منهم في الدرج',
          'icon'  => 'briefcase',
          'meta'  => [['icon' => 'info', 'text' => 'ينعدّ بالإيد']],
          'amount' => money($wallet['drawer'], false),
        ]);
        partial('row_item', [
          'title' => 'ومنهم في جيوب المناديب',
          'icon'  => 'user',
          'meta'  => [['icon' => 'info', 'text' => intf(count($wallet['reps'])) . ' مندوب']],
          'amount' => money($wallet['reps_total'], false),
        ]);
      }
    },
  ]); ?>

<?php elseif ($what === 'drawer'): ?>
  <?php partial('drill', [
    'how'   => $aggregate
      ? 'الدرج = رصيد الخزنة − اللي في جيوب المناديب. ده الرقم اللي ينعدّ بالإيد.'
      : 'الدرج هو رصيد الخزنة نفسه في النموذج الحالي.',
    'total' => ['label' => 'في الدرج', 'value' => money($wallet['drawer'], false)],
    'more'  => ['label' => 'جرد الخزينة', 'url' => url('safe-audit')],
    'body'  => function () use ($wallet, $aggregate) {
      partial('row_item', [
        'title' => 'إجمالي كاش المكتب', 'icon' => 'wallet',
        'meta'  => [['icon' => 'info', 'text' => 'درج + مناديب']],
        'amount' => money($wallet['total'], false),
      ]);
      if ($aggregate) {
        partial('row_item', [
          'title' => 'ناقص اللي في جيوب المناديب', 'icon' => 'user',
          'meta'  => [['icon' => 'info', 'text' => intf(count($wallet['reps'])) . ' مندوب']],
          'amount' => '- ' . money($wallet['reps_total'], false), 'amount_tone' => 'ex',
        ]);
      }
      partial('row_item', [
        'title' => 'الفعلي في الدرج', 'icon' => 'briefcase',
        'meta'  => [['icon' => 'info', 'text' => 'ينعدّ بالإيد']],
        'amount' => money($wallet['drawer'], false),
        'amount_tone' => $wallet['drawer'] < 0 ? 'neg' : 'pos',
      ]);
    },
  ]); ?>

<?php elseif ($what === 'reps'): ?>
  <?php partial('drill', [
    'how'   => 'كل مندوب ورصيد عهدته الحالية — من نفس دالة كارت المندوب.',
    'total' => ['label' => 'مع المناديب', 'value' => money($wallet['reps_total'], false)],
    'count' => count($wallet['reps']),
    'more'  => ['label' => 'عهدة المناديب', 'url' => url('rep-custody')],
    'empty' => ['title' => 'مفيش مندوب ماسك كاش دلوقتي'],
    'body'  => function () use ($wallet) {
      foreach ($wallet['reps'] as $rep) {
        partial('row_item', [
          'title' => $rep['name'], 'icon' => 'user',
          'url'   => url('rep-custody/' . (int) $rep['id']),
          'meta'  => [['icon' => 'briefcase', 'text' => 'عهدة مندوب']],
          'amount' => money($rep['balance'], false),
          'amount_tone' => $rep['balance'] < 0 ? 'neg' : 'pos',
        ]);
      }
    },
  ]); ?>

<?php elseif ($what === 'topups'): ?>
  <?php $rows = $byGroup(['topups']); ?>
  <?php partial('drill', [
    'how'   => 'العهد اللي دخلت الخزنة في الفترة (' . $rangeLbl . ').' . ($cut() ? ' ' . $cut() . '.' : ''),
    'total' => ['label' => 'إجمالي الداخل', 'value' => money($groupSum(['topups']), false)],
    'count' => $groupCount(['topups']),
    'more'  => ['label' => 'كل الحركات', 'url' => url('custody') . '?tab=moves&from=' . $from . '&to=' . $to],
    'empty' => ['title' => 'مفيش إضافات في الفترة دي'],
    'body'  => function () use ($rows, $moveRows) { $moveRows($rows); },
  ]); ?>

<?php elseif ($what === 'expenses'): ?>
  <?php /* ⚠️ التلات أقسام دي هي **بالظبط** اللي `Closing` بيعدّها مصروفات —
           بنود الأذونات · المصروفات العامة وكاش المناديب · التنزيل من الدرج. */ ?>
  <?php $rows = $byGroup(['permits', 'general', 'rep_deposit']); ?>
  <?php partial('drill', [
    'how'   => 'كل اللي خرج من الخزنة مصروفًا في الفترة (' . $rangeLbl . ').' . ($cut() ? ' ' . $cut() . '.' : ''),
    'total' => ['label' => 'إجمالي المصروفات', 'value' => money($groupSum(['permits', 'general', 'rep_deposit']), false)],
    'count' => $groupCount(['permits', 'general', 'rep_deposit']),
    'more'  => ['label' => 'المصروفات', 'url' => url('expenses')],
    'empty' => ['title' => 'مفيش مصروفات في الفترة دي'],
    'body'  => function () use ($rows, $moveRows) { $moveRows($rows); },
  ]); ?>

<?php elseif ($what === 'driver'): ?>
  <?php $rows = $byGroup(['custody']); ?>
  <?php partial('drill', [
    'how'   => 'المخصوم من الدرج لكل بوليصة = العهدة − ' . intf($rate) . ' (والسيارة المعفاة بتتخصم كاملة).',
    'total' => ['label' => 'المخصوم من الدرج', 'value' => money($groupSum(['custody']), false)],
    'count' => $groupCount(['custody']),
    'more'  => ['label' => 'عهد السائقين', 'url' => url('custody') . '?tab=drivers&from=' . $from . '&to=' . $to],
    'empty' => ['title' => 'مفيش عهد سائقين في الفترة دي'],
    'body'  => function () use ($rows, $moveRows) { $moveRows($rows); },
  ]); ?>

<?php else: /* retained */ ?>
  <?php
  $rows = $byGroup(['custody']);
  /* ⚠️ من `retained_total` (محسوب على الفترة كلها قبل القطع) لا من الصفوف
     المعروضة — وإلا النافذة بتقول محتجزًا أقل من الحقيقة بلا ما تقول ليه. */
  $keptTotal = (float) ($move['retained_total'] ?? 0);
  ?>
  <?php partial('drill', [
    'how'   => 'المحتجز = العهدة − المخصوم. الجزء اللي ما خرجش للسائق وفضل في الدرج، وهو مصدر كومسيون المناديب.',
    'total' => ['label' => 'إجمالي المحتجز', 'value' => money(round($keptTotal, 2), false)],
    'count' => $groupCount(['custody']),
    'more'  => ['label' => 'رواتب المناديب', 'url' => url('payroll')],
    'empty' => ['title' => 'مفيش عهد سائقين في الفترة دي'],
    'body'  => function () use ($rows) {
      foreach ($rows as $r) {
        $gross  = (float) ($r['gross'] ?? 0);
        $kept   = Custody::retained($gross, $r['car'] ?? null, $r['date']);
        $exempt = Custody::isExempt($r['car'] ?? null);
        $meta   = [['icon' => 'calendar', 'text' => dfmt($r['date'])]];
        if ($r['ref']) { $meta[] = ['icon' => 'file-text', 'text' => $r['ref']]; }
        $meta[] = ['icon' => 'wallet', 'text' => 'المسلَّم ' . money($gross, false)];
        /* السيارة المعفاة محتجزها صفر — يعني بوليصتها ما جمّعتش كومسيون. */
        if ($exempt) { $meta[] = ['icon' => 'alert', 'text' => 'سيارة معفاة']; }
        partial('row_item', [
          'title'  => $r['party'] ?: 'سائق',
          'icon'   => 'truck',
          'url'    => $r['link'] ? url($r['link']) : null,
          'meta'   => $meta,
          'amount' => money($kept, false),
          'amount_tone' => $kept > 0 ? 'pos' : null,
        ]);
      }
    },
  ]); ?>
<?php endif; ?>
