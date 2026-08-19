<?php
/**
 * تبويب «الجرد» — سؤال واحد: **اللي في الدرج هو اللي في الورق؟**
 *
 * الترتيب: كروت الكاش (البطل) ← خانة العدّ لكل خزنة ← آخر عمليات الجرد.
 *
 * ⚠️ **الرقم اللي بيتقارن بيه العدّ هو `SafeInventory::bookBalance()`** —
 *    وهو **الدرج لوحده** للمكتب لا `Closing::currentBalance()`. في النموذج
 *    المجمّع الرقم التاني شامل جيوب المناديب، والمقارنة بيه كانت هتدّي «عجز»
 *    دايم بمقدار اللي مع المناديب.
 *
 * ⚠️ **المبلغ الدفتري مش في الفورم** — الفورم بيبعت المعدود بس، والخادم بيجيب
 *    الدفتري بنفسه. قيمة جاية من الشاشة كانت بتخلّي «الفرق» رقمًا يقدر
 *    المستخدم يزوّره.
 *
 * متغيّرات: $snap $today $recent $stats $day
 */
use App\Models\SafeInventory;

$cd  = static fn (string $what): string => url('safe-audit/drill/' . $what);
$cur = App\Core\App::setting('currency', 'ج.م');
?>

<?php partial('day_bar', ['day' => $day, 'prefix' => 'جرد يوم']); ?>

<?php /* ═══ ① الكاش فين دلوقتي — أرصدة تراكمية، مش أرقام اليوم ═══ */ ?>
<?php partial('stat_grid', ['items' => 4, 'class' => 'mb', 'body' => function () use ($snap, $cd, $cur) { ?>
  <?php partial('stat', [
    'label' => 'درج المكتب', 'icon' => 'briefcase', 'tone' => 'navy', 'unit' => $cur,
    'value' => money0($snap['safes']['office']['book'], false),
    'drill' => $cd('office'), 'title' => 'اللي في درج المكتب',
  ]); ?>
  <?php partial('stat', [
    'label' => 'خزنة الشركة', 'icon' => 'home', 'tone' => 'gold', 'unit' => $cur,
    'value' => money0($snap['safes']['company']['book'], false),
    'drill' => $cd('company'), 'title' => 'رصيد خزنة الشركة',
  ]); ?>
  <?php partial('stat', [
    'label' => 'مع المناديب', 'icon' => 'truck', 'tone' => 'teal', 'unit' => $cur,
    'value' => money0($snap['reps'], false),
    'drill' => $cd('reps'), 'title' => 'الكاش اللي في جيوب المناديب',
  ]); ?>
  <?php partial('stat', [
    'label' => 'كل الكاش', 'icon' => 'coins', 'tone' => 'green', 'unit' => $cur,
    'value' => money0($snap['grand'], false), 'hero' => true,
    'drill' => $cd('grand'), 'title' => 'كل الكاش برّه البنك',
  ]); ?>
<?php }]); ?>

<?php /* ═══ ② خانة العدّ — خزنة خزنة، والفرق بيبان قبل ما تحفظ حتى ═══
         ⚠️ **الفرق تحذير وظيفي مش شرح**: «عجز ١٢٠٠» في ≤٦ كلمات. */ ?>
<div class="kpi-grid two mb" id="safeCount">
  <?php foreach (SafeInventory::SAFES as $key):
    $s     = $snap['safes'][$key];
    $row   = $today[$key] ?? null;
    $diff  = $row ? round((float) $row['diff'], 2) : null;
    $state = $diff === null ? '' : (abs($diff) < 0.005 ? 'ok' : ($diff > 0 ? 'over' : 'short'));
  ?>
    <div class="card oc-pad">
      <div class="section-head oc-sec">
        <?= icon($s['icon'], 17) ?> <?= e($s['label']) ?>
        <span class="spacer"></span>
        <b class="<?= $s['book'] < -0.005 ? 'neg' : '' ?>"><?= money($s['book'], false) ?></b>
      </div>

      <?php if ($row): ?>
        <?php /* اتجرد النهاردة — الشاشة بتقول النتيجة، والخانة بتفضل مفتوحة للتصحيح. */ ?>
        <div class="cs-alert<?= $state === 'ok' ? '' : ' bad' ?>">
          <?= icon($state === 'ok' ? 'check' : 'alert', 17) ?>
          <?php if ($state === 'ok'): ?>
            مطابقة — عدّيت <?= money((float) $row['counted'], false) ?>
          <?php else: ?>
            <?= $diff > 0 ? 'زيادة' : 'عجز' ?> <?= money(abs($diff), false) ?>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <form class="js-dirty-form p5-row end" method="post" action="<?= url('safe-audit') ?>">
        <?= csrfField() ?>
        <input type="hidden" name="_back" value="<?= e(safe_back('', current_app_path() ?: 'safe-audit?tab=count')) ?>">
        <input type="hidden" name="safe" value="<?= e($key) ?>">
        <div class="field flush w160">
          <label for="sa-date-<?= e($key) ?>">تاريخ الجرد</label>
          <input id="sa-date-<?= e($key) ?>" type="date" name="audit_date"
                 value="<?= e($row['audit_date'] ?? $day) ?>" max="<?= e(date('Y-m-d')) ?>">
        </div>
        <div class="field flush w150">
          <label for="sa-cnt-<?= e($key) ?>">المعدود فعلاً</label>
          <input id="sa-cnt-<?= e($key) ?>" data-money type="number" step="0.01" min="0"
                 name="counted" inputmode="decimal" placeholder="0"
                 value="<?= e($row ? rtrim(rtrim(number_format((float) $row['counted'], 2, '.', ''), '0'), '.') : '') ?>" required>
        </div>
        <div class="field flush grow180">
          <label for="sa-note-<?= e($key) ?>">ملاحظة</label>
          <input id="sa-note-<?= e($key) ?>" type="text" name="note"
                 value="<?= e((string) ($row['note'] ?? '')) ?>" placeholder="اختياري">
        </div>
        <button class="btn gold" type="submit" data-primary-submit>
          <?= $row ? 'عدّل الجرد' : 'سجّل الجرد' ?>
        </button>
      </form>
    </div>
  <?php endforeach; ?>
</div>

<?php /* ⚠️ تحذير وظيفي: النموذج المجمّع بيخلّي «خزنة المكتب» في شاشة التقفيل
         شاملة جيوب المناديب — والجرد هنا بيعدّ الدرج لوحده عن قصد. المالك
         لازم يعرف السبب من غير ما يقارن رقمين ويستنتج. */ ?>
<?php if ($snap['office_is_aggregate']): ?>
  <div class="cs-alert">
    <?= icon('info', 17) ?> الجرد بيعدّ الدرج لوحده — المناديب في كارت منفصل
  </div>
<?php endif; ?>

<?php /* ═══ ③ آخر عمليات الجرد ═══ */ ?>
<?php partial('panel', [
  'title'  => 'آخر عمليات الجرد',
  'icon'   => 'clipboard',
  'badge'  => $stats['count'] > 0
      ? ['text' => intf($stats['match']) . ' من ' . intf($stats['count']) . ' مطابق',
         'tone' => $stats['count'] === $stats['match'] ? 'navy' : 'red']
      : null,
  'action' => ['label' => 'كل السجل', 'url' => url('safe-audit') . '?tab=history'],
  'body'   => function () use ($recent) {
    if (!$recent) {
      partial('empty', ['icon' => 'calculator', 'title' => 'مفيش جرد اتسجّل لسه']);
      return;
    }
    foreach ($recent as $r) {
      $diff = (float) $r['diff'];
      partial('row_item', [
        'title' => (string) $r['safe_label'],
        'icon'  => (string) $r['safe_icon'],
        'meta'  => array_values(array_filter([
          ['icon' => 'calendar', 'text' => dfmt((string) $r['audit_date'])],
          ['icon' => 'wallet',   'text' => 'دفتري ' . money((float) $r['book_balance'], false)],
          ['icon' => 'coins',    'text' => 'معدود ' . money((float) $r['counted'], false)],
          $r['note'] ? ['icon' => 'file-text', 'text' => (string) $r['note']] : null,
        ])),
        'amount' => $r['state'] === 'match'
            ? 'مطابق'
            : (($diff > 0 ? '+ ' : '- ') . money(abs($diff), false)),
        'amount_tone' => $r['state'] === 'match' ? 'pos' : 'ex',
      ]);
    }
  },
]); ?>
