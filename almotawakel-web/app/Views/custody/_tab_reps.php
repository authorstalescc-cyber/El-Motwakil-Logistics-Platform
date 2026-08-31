<?php
/**
 * تبويب «المناديب» — مين ماسك كام من كاش المكتب.
 *
 * ⚠️⚠️ **الأرقام من `Closing::walletSplit()`** — نفس الدالة اللي `/closing`
 *    و`/safe-audit` بيقروا منها، فمستحيل التلات شاشات يختلفوا.
 *
 * ⚠️ نموذج «نزّل عهدة من الدرج» **اتنقل للدرج الجانبي** بدل ما يقعد نُصّ
 *    الشاشة الرئيسية. والزرار هنا بيفتحه.
 *
 * متغيّرات: $wallet $aggregate $repList $from $to $tab $cd
 */
use App\Models\RepCustody;

$srcMatters = RepCustody::sourceMatters();
?>

<?php partial('stat_grid', ['items' => 3, 'class' => 'mb',
  'body' => function () use ($wallet, $cd) { ?>
  <?php partial('stat', [
    'label' => 'في درج المكتب', 'value' => intf($wallet['drawer']),
    'unit'  => 'ينعدّ بالإيد',
    'icon'  => 'briefcase', 'tone' => $wallet['drawer'] < 0 ? 'red' : 'gold',
    'drill' => $cd('drawer'), 'title' => 'الفعلي في درج المكتب',
  ]); ?>
  <?php partial('stat', [
    'label' => 'مع المناديب', 'value' => intf($wallet['reps_total']),
    'unit'  => intf(count($wallet['reps'])) . ' مندوب',
    'icon'  => 'user', 'tone' => 'navy', 'drill' => $cd('reps'), 'title' => 'اللي في جيوب المناديب',
  ]); ?>
  <?php partial('stat', [
    'label' => 'إجمالي كاش المكتب', 'value' => intf($wallet['total']),
    'unit'  => 'درج + مناديب',
    'icon'  => 'wallet', 'tone' => 'green', 'drill' => $cd('balance'), 'title' => 'من إيه اتكوّن الرصيد',
  ]); ?>
<?php }]); ?>

<?php partial('panel', [
  'title'  => 'محافظ المناديب',
  'icon'   => 'user',
  'badge'  => $wallet['reps'] ? ['text' => intf(count($wallet['reps'])), 'tone' => 'navy'] : null,
  /* ⚠️ الزرار بيفتح الدرج بالرابط (`?new=rep`) — حالة في الرابط لا في الجافاسكريبت. */
  'action' => $repList && $srcMatters
    ? ['label' => 'نزّل عهدة من الدرج',
       'url'   => url('custody') . '?tab=reps&from=' . $from . '&to=' . $to . '&new=rep']
    : null,
  'body'   => function () use ($wallet) {
    if (!$wallet['reps']) {
      partial('empty', ['emoji' => '👤', 'title' => 'مفيش مندوب ماسك كاش دلوقتي']);
      return;
    }
    foreach ($wallet['reps'] as $rep) {
      partial('row_item', [
        'title'  => $rep['name'],
        'icon'   => 'user',
        'url'    => url('rep-custody/' . (int) $rep['id']),
        'meta'   => [['icon' => 'briefcase', 'text' => 'عهدة مندوب']],
        'amount' => money($rep['balance'], false),
        'amount_tone' => $rep['balance'] < 0 ? 'neg' : 'pos',
      ]);
    }
  },
]); ?>

<?php /* ⚠️⚠️ **الشاشة بتقول ليه الزرار مش موجود بدل ما يختفي بالسكوت.**
         الشاشة القديمة كانت بتخفي القسم كله لما `sourceMatters()` تبقى false،
         فالمالك مش عارف هو مش موجود ولا هو مش شايفه. */ ?>
<?php if ($repList && !$srcMatters): ?>
  <div class="cs-alert">
    <?= icon('info', 15) ?> المصدر مش فارق في النموذج الحالي — نزّل العهدة من كارت المندوب.
  </div>
<?php endif; ?>
