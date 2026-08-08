<?php
/**
 * عهدة المناديب — أول شاشة متحوّلة للغة النظام (المرحلة ١٠، 2026-07-25).
 *
 * ⚠️ **الشاشة دي هي النموذج المرجعي.** المرحلة ٧ بنت مكوّنات بعقد «بلا أي
 *    تغيير مرئي» فما حدش استخدمها وفضلت كود ميت. المرحلة ١٠ بتتبع أول شاشة
 *    مع النظام عشان اللغة تبقى مُجرَّبة قبل ما تتعمّم — أي شاشة جاية بتتبع
 *    نفس النمط ده: `day_bar` + `stat_grid`/`stat` + `entity_card` + `panel`.
 *
 * ⚠️ **ممنوع رقم أصمّ** (قاعدة المالك الدائمة): كل رقم فوق ليه `drill` بيفتح
 *    البنود اللي اتحسب منها، وكل كارت مندوب بيوصّل لكارته. مكوّن `stat`
 *    بيرفض يترسم من أصله لو `drill` ناقصة.
 *
 * ⚠️ **الشاشة بعدسة يوم العمل** (`Auth::workDate`): الأرقام والكروت كلها عن
 *    اليوم المختار، لأن عهدة المناديب **يومية بلا ترحيل** (v49). التراكمي
 *    («برّه معاه») بيفضل ظاهر لأنه الحقيقة المالية وأساس التسوية.
 */
$dayTxt = dfmt($day);
/* ⚠️ **`day` لازم تتنقل مع كل رابط.** الرقم بيعرض يوم العرض، فلو النافذة اللي
   بيفتحها فضلت على يوم الشغل تبقى بتوريه بنود يوم تاني — نفس الباج اللي اتصلّح
   في البحث بالظبط. و`$dayArg` بيبقى فاضي على يوم الشغل عشان الروابط تفضل نضيفة. */
$dayArg = $day === $workDay ? '' : '?day=' . rawurlencode($day);
$drill = static fn (string $what): string => url('rep-custody/drill/' . $what . $dayArg);
$repUrl = static fn (int $id, string $tab = 'day'): string => url(
    'rep-custody/' . $id . '?tab=' . $tab . ($dayArg === '' ? '' : '&day=' . rawurlencode($day)));
?>
<div class="toolbar">
  <div class="section-head" style="margin:0"><?= icon('users', 18) ?> عهدة المناديب</div>
  <div class="spacer"></div>
  <form method="get" action="<?= url('rep-custody') ?>" class="rc-search" role="search">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="ابحث باسم المندوب…" aria-label="ابحث باسم المندوب">
    <button class="btn ghost" type="submit" title="بحث" aria-label="بحث"><?= icon('search', 16) ?></button>
  </form>
  <?php /* الأرصدة مقنّعة افتراضيًّا — المعالج عام في app.js مش سكربت محلي */ ?>
  <a class="btn ghost" href="<?= url('rep-custody/inbox') ?>"><?= icon('inbox', 16) ?> مراجعة البنود</a>
  <a class="btn ghost" href="<?= url('reps') ?>">المرتبات والحسابات</a>
</div>

<?php /* ⚠️ **عدسة يوم للعرض** — أسهم بتفتح أي يوم (حتى المتقفل) بلا ما تلمس يوم
         الشغل. زرار «غيّر يوم العمل» جوّه الشريط لسه مكانه لأنه حاجة تانية
         خالص: بيحوّل النظام كله لوضع الكتابة على يوم تاني. */ ?>
<?php partial('day_bar', [
  'day'     => $day,
  'locked'  => $locked,
  'actions' => static function () use ($nav) { partial('day_nav', $nav); },
]); ?>

<?php /* ═══ الأرقام — كل واحد بيفتح بنوده ═══ */ ?>
<?php
/* ⚠️ **تفكيك «نزل للمناديب» بالمصدر** (بلاغ المالك 2026-08-08: «مش باين ده نازل
   منين»). الصياغة من `RepCustody::sourceBadge()` والأرقام من
   `depositSourceTotals()` — نفس نطاق الرقم اللي فوقها بالحرف، فمجموع السطر =
   الكارت. بيختفي لو مصدر واحد بس (سطر بيقول اللي الكارت قايله = تشتيت). */
$srcSplit = static function (array $totals): ?array {
    if (count($totals) < 2) { return null; }
    $parts = [];
    foreach ($totals as $k => $v) {
        $b = \App\Models\RepCustody::sourceBadge($k);
        if ($b) { $parts[] = $b['short'] . ' ' . money0($v); }
    }
    return $parts ? ['dir' => 'flat', 'tone' => 'mute', 'text' => implode(' · ', $parts)] : null;
};
$depSplit = $srcSplit(\App\Models\RepCustody::depositSourceTotals($day));
?>
<?php partial('stat_grid', ['items' => 4, 'body' => function () use ($dsum, $drill, $depSplit) { ?>
  <?php partial('stat', [
    'label' => 'نزل للمناديب اليوم', 'value' => money0($dsum['deposits']),
    'icon'  => 'wallet', 'tone' => 'green',
    'trend' => $depSplit,
    'drill' => $drill('deposits'), 'title' => 'العهدة اللي نزلت اليوم',
  ]); ?>
  <?php partial('stat', [
    'label' => 'صرفوه اليوم', 'value' => money0($dsum['spent']),
    'icon'  => 'trending-down', 'tone' => 'red',
    'drill' => $drill('spent'), 'title' => 'اللي اتصرف من عهدة المناديب اليوم',
  ]); ?>
  <?php /* ⚠️ الرقم ده `pocket` مش `balance`: بيخصم كمان اللي دفعوه ولسه مستني
           اعتماد، لأن فلوسه خرجت من إيدهم فعلاً — ده «الفاضل معاهم» الحقيقي. */ ?>
  <?php partial('stat', [
    'label' => 'فاضل معاهم من عهدة اليوم', 'value' => money0($dsum['pocket']),
    'icon'  => 'briefcase', 'tone' => 'gold', 'drill' => $drill('balance'), 'title' => 'الفاضل من عهدة اليوم (بعد المستني) — مندوبًا مندوبًا',
  ]); ?>
  <?php partial('stat', [
    'label' => 'مستني نقدي', 'value' => money0($dsum['pending']),
    'icon'  => 'hourglass', 'tone' => 'sader',
    'drill' => $drill('pending'), 'title' => 'البنود النقدية اليوم المستنية اعتمادك',
  ]); ?>
<?php }]); ?>

<?php /* ⚠️ **تحذير وظيفي** (قانون التصميم بند ١ — مش جملة تعليمية): البحث
         بيفلتر الكروت تحت بس، والأربع أرقام فوق بتفضل على كل المناديب. من غير
         السطر ده كان المالك يكتب اسمًا فتتغيّر الأرقام وهو فاكرها إجماليات. */ ?>
<?php if ($q !== ''): ?>
  <div class="rc-filter-note">
    <?= icon('search', 15) ?>
    <span><?= e(intf(count($cards))) ?> من <?= e(intf($repsTotal)) ?> — الأرقام فوق لكل المناديب</span>
    <a class="btn ghost" href="<?= url('rep-custody') ?>">شيل البحث</a>
  </div>
<?php endif; ?>

<div class="d-sec-head">
  <?= icon('users', 17) ?><span>المناديب في يوم <?= e($dayTxt) ?></span>
  <?php /* ⚠️ **الرقم ده هو الحقيقة المالية — وكان غايبًا عن الشاشة تمامًا.**
           اللي كان مكانه «مفتوح من أيام سابقة» = التراكمي − رصيد اليوم، ورصيد
           اليوم بيبقى سالب لما المندوب يصرف أكتر من عهدة يومه (مسموح — v49)،
           فكان بيطلع **أكبر من كل الفلوس الموجودة فعلاً**. الفرق ده لسه معروضًا
           على كل كارت مندوب لوحده (حيث معناه واضح: شغل ناقص محتاج تصفية)،
           لكنه اتشال من هنا لأن جمعه على كل المناديب بيدّي رقمًا بلا معنى. */ ?>
  <em class="d-sec-year">برّه معاهم دلوقتي:
    <b><?= e(money0($outstanding)) ?></b></em>
</div>

<?php
/* ⚠️ الجدول اتشال. كان ٧ أعمدة مليانة `—` ولازم تربط بعينك بين «نزل» و«صرف»
   و«فاضل» في كل صف. الكارت بيحطهم في وحدة واحدة تُقرأ بنظرة — نفس تصميم
   كارت المندوب في الداشبورد بالظبط، فالانتقال بينهم مش بيحسّ بقفزة. */
$active = array_values(array_filter($cards, static fn ($c) => $c['day_active']));
$quiet  = array_values(array_filter($cards, static fn ($c) => !$c['day_active']));
?>
<?php partial('panel', [
  'title'  => 'عليهم حركة اليوم',
  'icon'   => 'briefcase',
  'badge'  => $active ? ['text' => intf(count($active)), 'tone' => 'navy'] : null,
  'action' => ['label' => 'سجل المناديب', 'url' => url('reps')],
  'class'  => 'mb',
  'body'   => function () use ($active, $dayTxt, $repUrl) {
    if (!$active) {
      partial('empty', [
        'emoji'  => '🗂️',
        'title'  => 'مفيش مندوب عليه حركة في ' . $dayTxt,
        'action' => ['label' => 'صندوق المراجعة', 'url' => url('rep-custody/inbox')],
      ]);
      return;
    }
    echo '<div class="rp-cards flat">';
    foreach ($active as $c) {
      $dep = (float) $c['day_deposits'];
      $tags = [
        ['icon' => 'wallet', 'text' => 'نزل ' . money0($dep)],
        ['icon' => 'trending-down', 'text' => 'صرف ' . money0($c['day_spent']), 'tone' => 'ex'],
      ];
      if ($c['day_pending'] > 0) {
        $tags[] = ['icon' => 'hourglass', 'text' => 'مستني ' . money0($c['day_pending']), 'tone' => 'wait'];
      }
      if ($c['day_pocket'] < 0) {
        $tags[] = ['icon' => 'alert', 'text' => 'صرف أكتر من عهدة اليوم', 'tone' => 'low'];
      }
      partial('entity_card', [
        'name'  => $c['name'],
        'url'   => $repUrl((int) $c['id']),
        'value' => money0($c['day_pocket']),
        'value_label' => $c['day_pending'] > 0 ? 'فاضل معاه (بعد المستني)' : 'فاضل من عهدة اليوم',
        'value_tone'  => $c['day_pocket'] < 0 ? 'neg' : 'pos',
        'alert'  => $c['day_pocket'] < 0,
        // شريط الاستهلاك: نسبة المصروف والمستنّي من عهدة اليوم (عرض فقط)
        'meter' => $dep > 0 ? [
          ['cls' => 'sp', 'pct' => min(100, $c['day_spent'] / $dep * 100)],
          ['cls' => 'pd', 'pct' => min(100, $c['day_pending'] / $dep * 100)],
        ] : [],
        'note'  => abs($c['carry']) > 0.005 ? [
          'label' => 'مفتوح من أيام سابقة',
          'value' => money0($c['carry']),
          'tone'  => $c['carry'] < 0 ? 'neg' : 'warn',
          'sub'   => $c['carry'] < 0 ? 'مستحق للمندوب' : 'محتاج تصفية',
        ] : null,
        'tags' => $tags,
      ]);
    }
    echo '</div>';
  },
]); ?>

<?php if ($quiet): ?>
  <?php partial('panel', [
    'title'  => 'مفيش حركة ليهم اليوم',
    'icon'   => 'clock',
    'badge'  => ['text' => intf(count($quiet)), 'tone' => 'navy'],
    'scroll' => true,
    'body'   => function () use ($quiet, $repUrl) {
      foreach ($quiet as $c) {
        partial('row_item', [
          'title' => $c['name'],
          'icon'  => 'user',
          'url'   => $repUrl((int) $c['id']),
          'ref'   => $c['status'] === 'inactive' ? 'متوقف' : null,
          'meta'  => array_values(array_filter([
            $c['phone'] ? ['icon' => 'send', 'text' => $c['phone']] : null,
            abs($c['carry']) > 0.005
              ? ['icon' => 'briefcase', 'text' => 'مفتوح من أيام سابقة ' . money0($c['carry'])]
              : null,
          ])),
        ]);
      }
    },
  ]); ?>
<?php endif; ?>

<style>
.rc-search{display:flex;gap:6px}
@media (max-width:640px){ .rc-search{width:100%} .rc-search input{flex:1;min-width:0} }
</style>
