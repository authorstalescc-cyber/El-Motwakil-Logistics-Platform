<?php
/**
 * نافذة تفاصيل أي رقم في «جرد الخزينة» — «ممنوع رقم أصمّ».
 *
 * ⚠️ **النافذة بتقول الرقم جاي منين، ومنها باب للشاشة الكاملة** — مش نسخة
 *    فقيرة من الشاشة. عشان كده كل حالة ليها `more` بيوديه للوحدة صاحبة الرقم.
 *
 * ⚠️ **كل نافذة نطاقها نطاق الكارت اللي فوقها بالظبط**: نوافذ الفترة بتقرا
 *    نفس `?period/from/to` بتاع الشاشة — لو اتحسبت على فترة افتراضية هتقول
 *    رقمًا مخالفًا للكارت اللي المالك ضغط عليه.
 *
 * متغيّرات: $what $day $period $st $snap $audits
 */
use App\Models\SafeInventory;

$rowsOf = static function (array $rows, ?callable $filter = null): callable {
    return static function () use ($rows, $filter) {
        $n = 0;
        foreach ($rows as $r) {
            if ($filter !== null && !$filter($r)) { continue; }
            $n++;
            $desc = array_filter([$r['party'] ?? null, $r['extra'] ?? null, $r['note'] ?? null]);
            partial('row_item', [
                'title' => (string) $r['title'],
                'icon'  => (string) $r['icon'],
                'ref'   => SafeInventory::label((string) $r['safe']),
                'meta'  => array_values(array_filter([
                    ['icon' => 'calendar', 'text' => dfmt((string) $r['date'])],
                    $desc ? ['icon' => 'file-text', 'text' => implode(' — ', $desc)] : null,
                    !empty($r['transfer']) ? ['icon' => 'shuffle', 'text' => 'تحويل داخلي'] : null,
                ])),
                'amount' => ($r['signed'] > 0 ? '+ ' : '- ') . money(abs((float) $r['signed']), false),
                'amount_tone' => $r['signed'] > 0 ? 'pos' : 'ex',
            ]);
        }
        if ($n === 0) { partial('empty', ['icon' => 'layers', 'title' => 'مفيش حركات']); }
    };
};

$auditRows = static function () use ($audits) {
    if (!$audits) { partial('empty', ['icon' => 'calculator', 'title' => 'مفيش جرد اتسجّل']); return; }
    foreach ($audits as $r) {
        $diff = (float) $r['diff'];
        partial('row_item', [
            'title' => (string) $r['safe_label'],
            'icon'  => (string) $r['safe_icon'],
            'ref'   => dfmt((string) $r['audit_date']),
            'meta'  => [
                ['icon' => 'wallet', 'text' => 'دفتري ' . money((float) $r['book_balance'], false)],
                ['icon' => 'coins',  'text' => 'معدود ' . money((float) $r['counted'], false)],
            ],
            'amount' => $r['state'] === 'match' ? 'مطابق'
                      : (($diff > 0 ? '+ ' : '- ') . money(abs($diff), false)),
            'amount_tone' => $r['state'] === 'match' ? 'pos' : 'ex',
        ]);
    }
};
?>

<?php if ($what === 'office'): ?>
  <?php partial('drill', [
    'how'   => 'الكاش اللي في درج المكتب دلوقتي — من نفس حساب شاشة التقفيل.',
    'total' => ['label' => 'في الدرج', 'value' => money($snap['safes']['office']['book'], false)],
    'more'  => ['label' => 'خزنة المكتب', 'url' => url('custody')],
    'body'  => function () use ($snap) {
      $w = $snap['wallets'];
      partial('row_item', ['title' => 'درج المكتب', 'icon' => 'briefcase',
        'meta' => [['icon' => 'info', 'text' => 'الكاش اللي تقدر تعدّه بإيدك']],
        'amount' => money((float) $w['drawer'], false), 'amount_tone' => 'pos']);
      partial('row_item', ['title' => 'مع المناديب', 'icon' => 'truck',
        'meta' => [['icon' => 'users', 'text' => intf(count($w['reps'])) . ' مندوب']],
        'amount' => money((float) $w['reps_total'], false), 'amount_tone' => 'rc']);
      foreach ($w['reps'] as $rep) {
        partial('row_item', ['title' => (string) $rep['name'], 'icon' => 'user',
          'meta' => [['icon' => 'briefcase', 'text' => 'عهدة مندوب']],
          'amount' => money((float) $rep['balance'], false), 'amount_tone' => 'rc']);
      }
    },
  ]); ?>

<?php elseif ($what === 'company'): ?>
  <?php partial('drill', [
    'how'   => 'رصيد خزنة الشركة التراكمي — من نفس حساب شاشة خزنة الشركة.',
    'total' => ['label' => 'رصيد الشركة', 'value' => money($snap['safes']['company']['book'], false)],
    'more'  => ['label' => 'خزنة الشركة', 'url' => url('company-safe')],
    'body'  => function () use ($snap) {
      partial('row_item', ['title' => 'رصيد خزنة الشركة', 'icon' => 'home',
        'meta' => [['icon' => 'info', 'text' => 'تراكمي بلا تقفيل يومي']],
        'amount' => money((float) $snap['safes']['company']['book'], false),
        'amount_tone' => 'pos']);
    },
  ]); ?>

<?php elseif ($what === 'reps'): ?>
  <?php partial('drill', [
    'how'   => 'الكاش اللي في جيوب المناديب — مش جزء من الجرد، بيتعدّ في كارت كل مندوب.',
    'total' => ['label' => 'مع المناديب', 'value' => money($snap['reps'], false)],
    'count' => (int) $snap['reps_count'],
    'more'  => ['label' => 'عهدة المناديب', 'url' => url('rep-custody')],
    'body'  => function () use ($snap) {
      foreach ($snap['wallets']['reps'] as $rep) {
        partial('row_item', ['title' => (string) $rep['name'], 'icon' => 'user',
          'url' => url('rep-custody/' . (int) $rep['id']),
          'meta' => [['icon' => 'briefcase', 'text' => 'رصيد العهدة']],
          'amount' => money((float) $rep['balance'], false), 'amount_tone' => 'rc']);
      }
    },
  ]); ?>

<?php elseif ($what === 'grand'): ?>
  <?php partial('drill', [
    'how'   => 'كل الكاش برّه البنك = الدرج + المناديب + خزنة الشركة.',
    'total' => ['label' => 'كل الكاش', 'value' => money($snap['grand'], false)],
    'more'  => ['label' => 'التقفيل اليومي', 'url' => url('closing')],
    'body'  => function () use ($snap) {
      partial('row_item', ['title' => 'درج المكتب', 'icon' => 'briefcase',
        'amount' => money((float) $snap['wallets']['drawer'], false), 'amount_tone' => 'pos']);
      partial('row_item', ['title' => 'مع المناديب', 'icon' => 'truck',
        'meta' => [['icon' => 'users', 'text' => intf((int) $snap['reps_count']) . ' مندوب']],
        'amount' => money((float) $snap['reps'], false), 'amount_tone' => 'pos']);
      partial('row_item', ['title' => 'خزنة الشركة', 'icon' => 'home',
        'amount' => money((float) $snap['safes']['company']['book'], false), 'amount_tone' => 'pos']);
    },
  ]); ?>

<?php elseif ($what === 'audits'): ?>
  <?php partial('drill', [
    'how'   => 'آخر عمليات الجرد — الفرق = المعدود ناقص الدفتري.',
    'more'  => ['label' => 'سجل الجرد', 'url' => url('safe-audit') . '?tab=history'],
    'body'  => $auditRows,
  ]); ?>

<?php else: ?>
  <?php
  /* ⚠️ نوافذ الفترة التلاتة بتقرا **نفس الصفوف** اللي في الكشف، وبتفلترها
     بالإشارة — فمستحيل النافذة تعرض بندًا مش في الجدول اللي تحتها. */
  $cfg = match ($what) {
      'period_in'  => ['how' => 'اللي دخل الخزنتين في الفترة (التحويلات الداخلية موسومة).',
                       'lbl' => 'داخل', 'val' => (float) $st['total']['in'],
                       'f'   => static fn ($r) => $r['signed'] > 0],
      'period_out' => ['how' => 'اللي خرج من الخزنتين في الفترة (التحويلات الداخلية موسومة).',
                       'lbl' => 'خارج', 'val' => (float) $st['total']['out'],
                       'f'   => static fn ($r) => $r['signed'] < 0],
      'transfers'  => ['how' => 'التحويلات بين الخزنتين — بتغيّر مكان الفلوس مش إجماليها.',
                       'lbl' => 'تحويلات داخلية', 'val' => (float) $st['transfers'],
                       'f'   => static fn ($r) => !empty($r['transfer'])],
      default      => ['how' => 'حركة الخزنتين في الفترة — أول المدة ' . money($st['total']['opening'], false)
                                . ' وآخرها ' . money($st['total']['closing'], false) . '.',
                       'lbl' => 'صافي الفترة', 'val' => (float) $st['total']['net'],
                       'f'   => null],
  };
  ?>
  <?php partial('drill', [
    'how'   => $cfg['how'],
    'total' => ['label' => $cfg['lbl'], 'value' => money($cfg['val'], false)],
    'more'  => ['label' => 'الكشف كامل',
                'url'   => url('safe-audit') . '?tab=moves&period=' . rawurlencode($period['key'])
                         . '&from=' . rawurlencode($period['from']) . '&to=' . rawurlencode($period['to'])],
    'body'  => $rowsOf($st['rows'], $cfg['f']),
  ]); ?>
<?php endif; ?>
