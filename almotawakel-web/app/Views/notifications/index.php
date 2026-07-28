<?php
/**
 * صفحة السجل الكامل للإشعارات — المرحلة ٢ من docs/15.
 *
 * ⚠️ **ممنوع أي رقم مجمَّع هنا** — السجل بند لكل حدث. الأرقام المجمَّعة مكانها
 *    الشارات و«محتاج منك» في الداشبورد (الحالات المستمرة)، مش السجل.
 *
 * ⚠️ الوقت النسبي بيتحسب في المتصفح من `data-ts` وبيتحدّث كل دقيقة بلا أي
 *    طلب للخادم (`app.js` → `paintNotifTimes`). و`title=` فيه الوقت الكامل
 *    بساعة المستخدم (مصحَّحة بـ`Clock` — فرق ٣ ساعات موثَّق في v48).
 */
$q = static function (array $over = []) use ($filters, $scope): string {
    $base = [
        'scope'  => $scope === 'all' ? 'all' : null,
        'kind'   => $filters['kind']   ?: null,
        'q'      => $filters['q']      ?: null,
        'from'   => $filters['from']   ?: null,
        'to'     => $filters['to']     ?: null,
        'unread' => !empty($filters['unread']) ? '1' : null,
    ];
    $merged = array_filter(array_merge($base, $over), static fn($v) => $v !== null && $v !== '');
    return http_build_query($merged);
};

$hasFilter = ($filters['kind'] ?? '') !== '' || ($filters['q'] ?? '') !== ''
          || ($filters['from'] ?? '') !== '' || ($filters['to'] ?? '') !== '' || !empty($filters['unread']);

/* ترويسة اليوم: النهاردة / إمبارح / التاريخ — بتتحسب من ساعة المستخدم */
$dayLabel = static function (int $ts): string {
    $d = date('Y-m-d', $ts);
    if ($d === date('Y-m-d'))                   { return 'النهاردة'; }
    if ($d === date('Y-m-d', time() - 86400))   { return 'إمبارح'; }
    if ($ts >= time() - 7 * 86400)              { return 'الأسبوع ده'; }
    return dfmt($d);
};
?>

<?php partial('page_head', [
    'title'   => 'الإشعارات',
    'actions' => ($unread > 0 ? '<form method="post" action="' . e(url('notifications/read-all')) . '" style="display:inline">'
        . csrfField()
        . '<button class="btn ghost sm" type="submit">علّم الكل كمقروء (' . intf($unread) . ')</button></form>' : '')
        . '<a class="btn ghost sm" href="' . e(url('notifications/prefs')) . '">⚙️ إعداداتي</a>',
]); ?>

<?php if ($snoozed > 0): ?>
  <?php /* ⚠️ البند المؤجَّل بيختفي من القايمة — لازم نقول إنه موجود وإلا
           المستخدم يفتكر إنه اتمسح. */ ?>
  <div class="nl-snoozed"><?= icon('clock', 15) ?>
    <span>فيه <?= intf($snoozed) ?> إشعار مؤجَّل — هيرجع يظهر لوحده لما وقته ييجي.</span>
  </div>
<?php endif; ?>

<?php if ($canAll): ?>
  <?php partial('tabs', [
      'label' => 'نطاق السجل',
      'items' => [
          ['label' => 'إشعاراتي', 'url' => url('notifications') . ($q(['scope' => null]) ? '?' . $q(['scope' => null]) : ''),
           'icon' => 'bell',  'active' => $scope !== 'all', 'count' => $unread > 0 && $scope !== 'all' ? intf($unread) : null],
          ['label' => 'كل النظام', 'url' => url('notifications') . '?' . $q(['scope' => 'all']),
           'icon' => 'layers', 'active' => $scope === 'all'],
      ],
  ]); ?>
<?php endif; ?>

<?php
$kindOptions = ['' => 'كل الأنواع'];
foreach ($kinds as $k => $lbl) { $kindOptions[$k] = $lbl; }
$fields = [
    ['type' => 'search', 'name' => 'q',    'value' => $filters['q'],    'placeholder' => 'دوّر في العنوان أو التفاصيل أو الاسم'],
    ['type' => 'select', 'name' => 'kind', 'value' => $filters['kind'], 'options' => $kindOptions, 'placeholder' => 'النوع'],
    ['type' => 'date',   'name' => 'from', 'value' => $filters['from'], 'label' => 'من'],
    ['type' => 'date',   'name' => 'to',   'value' => $filters['to'],   'label' => 'إلى'],
];
if ($scope === 'all') { $fields[] = ['type' => 'hidden', 'name' => 'scope', 'value' => 'all']; }
partial('filters', [
    'action' => url('notifications'),
    'fields' => $fields,
    'reset'  => url('notifications') . ($scope === 'all' ? '?scope=all' : ''),
    'active' => $hasFilter,
]);
?>

<?php if ($scope !== 'all'): ?>
  <nav class="tabs nl-quick" aria-label="فلترة سريعة">
    <a class="tab<?= empty($filters['unread']) ? ' active' : '' ?>"
       href="<?= e(url('notifications') . ($q(['unread' => null]) ? '?' . $q(['unread' => null]) : '')) ?>">الكل</a>
    <a class="tab<?= !empty($filters['unread']) ? ' active' : '' ?>"
       href="<?= e(url('notifications') . '?' . $q(['unread' => '1'])) ?>">غير المقروء<?php if ($unread > 0): ?>
      <span class="tab-cnt"><?= intf($unread) ?></span><?php endif; ?></a>
  </nav>
<?php endif; ?>

<?php
ob_start();
if (!$ready) {
    partial('empty', [
        'icon'  => 'bell',
        'title' => 'سجل الإشعارات لسه ما اتفعّلش',
    ]);
} elseif (!$items) {
    partial('empty', [
        'icon'   => 'bell',
        'title'  => $hasFilter ? 'مفيش إشعار مطابق للفلترة' : 'مفيش إشعارات لسه',
        'action' => $hasFilter ? ['label' => 'شيل الفلترة', 'url' => url('notifications'), 'class' => 'btn sm'] : null,
    ]);
} else {
    $lastDay = null;
    foreach ($items as $it) {
        $day = $dayLabel((int) $it['ts']);
        if ($day !== $lastDay) {
            $lastDay = $day;
            echo '<div class="nl-day">' . e($day) . '</div>';
        }
        $meta = [];
        if ($it['actor'] !== '')      { $meta[] = ['icon' => 'user',     'text' => $it['actor']]; }
        if (!empty($it['work_date'])) { $meta[] = ['icon' => 'calendar', 'text' => 'يوم عمل ' . dfmt($it['work_date'])]; }
        if ($it['body'] !== '')       { $meta[] = ['text' => $it['body']]; }

        $chips = [];
        if (!$it['read'] && $scope !== 'all') { $chips[] = ['text' => 'جديد']; }
        $chips[] = ['icon' => $it['icon'], 'text' => $it['kind_label']];

        // ⚠️ `data-ts` هو اللي `app.js` بيحدّث منه «من ٣ دقايق» كل دقيقة.
        //    الرندر الأولي من الخادم (`rel`) عشان المستخدم بلا جافاسكريبت
        //    يشوف وقتًا مفهومًا بدل خانة فاضية.
        echo '<div class="nl-item' . ($it['read'] || $scope === 'all' ? '' : ' unread')
           . '" data-notif-id="' . e((string) $it['id']) . '" data-ts="' . e((string) $it['ts'])
           . '" title="' . e($it['at_label']) . '">';
        partial('row_item', [
            'title'  => $it['title'],
            'url'    => $it['url'] !== '' ? $it['url'] : null,
            'icon'   => $it['icon'],
            'chips'  => $chips,
            'meta'   => $meta,
            'amount' => $it['rel'],
            'sub'    => $it['at_label'],
        ]);
        /* إجراءات البند (المرحلة ٦): تأجيل · مقروء · واتساب للحرج.
           ⚠️ **التأجيل والقراءة POST بتوكن حماية** — مفيش أي إجراء بيغيّر
              حالة عبر GET. والمدّة بتتفحص في الخادم من قائمة بيضا. */
        if ($scope !== 'all') {
            echo '<div class="nl-acts">';
            if (!$it['read']) {
                echo '<button type="button" class="nl-act js-nl-read" data-id="' . e((string) $it['id']) . '">'
                   . icon('check', 14) . ' علّمه مقروء</button>';
            }
            foreach ($snoozeChoices as $m) {
                echo '<button type="button" class="nl-act js-nl-snooze" data-id="' . e((string) $it['id'])
                   . '" data-min="' . e((string) $m) . '">' . icon('clock', 14) . ' أجّل '
                   . e(App\Controllers\NotificationController::snoozeLabel((int) $m)) . '</button>';
            }
            // ⚠️ الواتساب **للحرج بس** (قرار الخطة §٦) وبرابط `wa.me` بيفتح
            //    بضغطة المستخدم — مفيش أي تحميل تلقائي لطرف تالت (قاعدة أمنية).
            if ($it['severity'] === 'urgent' && $waPhone !== '') {
                $msg = $it['title'] . "\n" . $it['body'] . "\n" . $it['at_label'];
                echo '<a class="nl-act wa" target="_blank" rel="noopener" href="https://wa.me/'
                   . e($waPhone) . '?text=' . rawurlencode($msg) . '">ابعتها واتساب</a>';
            }
            echo '</div>';
        }
        echo '</div>';
    }
}
$body = ob_get_clean();

partial('panel', [
    'title' => $scope === 'all' ? 'كل أحداث النظام' : 'سجل إشعاراتي',
    'icon'  => 'bell',
    'badge' => $total > 0 ? ['text' => intf($total) . ' بند', 'tone' => 'navy'] : null,
    'body'  => $body,
]);
?>

<?php partial('pager', ['page' => $page, 'pages' => $pages, 'qs' => static fn(array $o) => $q($o), 'label' => 'صفحات الإشعارات']); ?>
