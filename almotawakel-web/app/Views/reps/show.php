<?php
/**
 * بروفايل المندوب (v88) — الغلاف: ترويسة + أرقام + تبويبات.
 *
 * ═══ اللي اتغيّر ═══
 * الشاشة القديمة كانت **صفحة واحدة طويلة** بتعرض مصدرين من تمانية (الكارت
 * وسجل البنود)، وفيها `<style>` محلي و`style=` inline وجملتان تعليميتان
 * و**ولا رقم واحد بيفتح تفاصيله**. بقت: ترويسة + ٥ أرقام كلها بتُضغط +
 * ٥ تبويبات كل واحد بيجيب بياناته لوحده.
 *
 * ⚠️ **صفر `<style>` محلي وصفر `style=`** — كلاسات `rp-*` في `app.css`.
 * ⚠️ **التبويبات روابط حقيقية** (`?tab=`) فالرجوع بالمتصفح شغّال.
 * ⚠️ **صفر جملة تعليمية** — اللي فاضل تحذيرات وظيفية في ≤٦ كلمات.
 *
 * @var array $rep @var string $tab @var array $period @var array $prev @var array $next
 * @var bool  $isFuture @var array $head @var array $kinds
 */
use App\Models\RepProfile;

$rid = (int) $rep['id'];
$q   = static fn (string $t): string =>
    url('reps/' . $rid) . '?tab=' . $t . '&at=' . $period['from'];
$dr  = static fn (string $w): string =>
    url('reps/' . $rid . '/drill/' . $w) . '?at=' . $period['from'];
?>

<?php partial('page_head', [
  'title'   => $rep['name'],
  'back'    => ['label' => 'المناديب', 'url' => url('reps')],
  'primary' => ['label' => 'تعديل الكارت', 'url' => url('reps/' . $rid . '/edit'), 'class' => 'btn'],
]); ?>

<div class="rp-hero">
  <span class="rp-av"><?= e(initials($rep['name'])) ?></span>
  <div class="rp-id">
    <span class="rp-name"><?= e($rep['name']) ?></span>
    <span class="rp-meta">
      <span class="chip"><?= icon($rep['status'] === 'active' ? 'check' : 'lock', 13) ?>
        <?= $rep['status'] === 'active' ? 'شغّال' : 'موقوف' ?></span>
      <?php if ($rep['phone']): ?>
        <span><?= icon('smartphone', 13) ?> <bdi><?= e($rep['phone']) ?></bdi></span>
      <?php endif; ?>
      <span><?= icon('banknote', 13) ?> <?= money($rep['monthly_salary']) ?></span>
      <?php if ($rep['hire_date']): ?>
        <span><?= icon('calendar', 13) ?> <?= e(dfmt($rep['hire_date'])) ?></span>
      <?php endif; ?>
    </span>
  </div>
</div>

<?php /* ⚠️ التحذير ده وظيفي مش شرح: الراتب الثابت صفر يعني «الصافي» تحت
         ناقص أهم بند فيه، والمالك لازم يعرف قبل ما يصرف. */ ?>
<?php if ((float) $rep['monthly_salary'] <= 0): ?>
  <div class="card mb p5-pad-sm p5-edge-gold-r">
    <?= icon('alert', 15) ?> الراتب الثابت مش متسجّل في الكارت.
  </div>
<?php endif; ?>

<?php partial('stat_grid', ['items' => 5, 'body' => function () use ($head, $dr) { ?>
  <?php partial('stat', [
    'label' => 'المستحق له علينا', 'value' => money0($head['balance']),
    'icon' => 'handshake', 'tone' => 'navy', 'unit' => 'ج', 'drill' => $dr('balance'),
    'title' => 'كشف حسابه كله',
  ]); ?>
  <?php partial('stat', [
    'label' => 'عهدته الميدانية', 'value' => money0($head['custody']),
    'icon' => 'briefcase', 'tone' => 'teal', 'unit' => 'ج', 'drill' => $dr('custody'),
    'title' => 'اللي صرفه من محفظته',
  ]); ?>
  <?php partial('stat', [
    'label' => 'سلف الفترة', 'value' => money0($head['advances']),
    'icon' => 'wallet', 'tone' => 'red', 'unit' => 'ج', 'drill' => $dr('advances'),
    'title' => 'السلف اللي سحبها',
  ]); ?>
  <?php partial('stat', [
    'label' => 'صافي راتب الفترة', 'value' => money0($head['net']),
    'icon' => 'banknote', 'tone' => 'green', 'unit' => 'ج', 'drill' => $dr('net'),
    'title' => 'إزاي اتحسب',
  ]); ?>
  <?php partial('stat', [
    'label' => 'تحت المراجعة', 'value' => intf($head['pending']),
    'icon' => 'clock', 'tone' => 'gold', 'drill' => $dr('pending'),
    'title' => 'بنود لسه ما اتعتمدتش',
  ]); ?>
<?php }]); ?>

<?php /* شريط الفترة — فترة رواتب لا شهر تقويمي (يوم البداية من الإعدادات) */ ?>
<div class="toolbar">
  <a class="btn ghost" href="<?= e(url('reps/' . $rid)) ?>?tab=<?= e($tab) ?>&at=<?= e($prev['from']) ?>"><span aria-hidden="true">‹</span> السابقة</a>
  <div class="section-head"><?= icon('calendar', 18) ?> <?= e($period['label']) ?></div>
  <?php if (!$isFuture): ?>
    <a class="btn ghost" href="<?= e(url('reps/' . $rid)) ?>?tab=<?= e($tab) ?>&at=<?= e($next['from']) ?>">التالية <span aria-hidden="true">›</span></a>
  <?php endif; ?>
</div>

<?php
$tabItems = [];
foreach (RepProfile::TABS as $key => $meta) {
    $tabItems[] = [
        'label'  => $meta['label'],
        'icon'   => $meta['icon'],
        'url'    => $q($key),
        'active' => $tab === $key,
    ];
}
partial('tabs', ['items' => $tabItems, 'label' => 'أقسام بروفايل المندوب']);
?>

<?php require __DIR__ . '/_tab_' . $tab . '.php'; ?>
