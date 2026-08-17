<?php
/**
 * نافذة «نظرة عامة» — إيصالات وبنود اليوم على الأذونات (v99).
 *
 * طلب المالك: «كل إذن جاله إيصالات إيه النهاردة — حتى لو لم يتم اعتمادها —
 * وإحصائية كام إيصال وهكذا».
 *
 * بتترندر **بلا layout** وبترجّع HTML للنافذة العامة الوحيدة (`#uiDrill`) —
 * نفس نمط `dashboard/_drill.php`.
 *
 * ⚠️ **كل رقم هنا مضغوط** (قاعدة المالك «ممنوع رقم أصمّ»): الأرقام الكبيرة
 *    والشرايح بتعيد فتح **نفس النافذة** مفلترة (`data-drill` بيشتغل جوّه
 *    النافذة زي ما بيشتغل في الصفحة — التفويض على `document`)، والإذن وبنوده
 *    بيوصّلوا لشاشته.
 *
 * ⚠️ **الوقت بيمرّ على `dtfmt()`** — `created_at` جاي خام من القاعدة (UTC على
 *    الإنتاج) والتصحيح في العرض، مرّة واحدة بس (قاعدة v48).
 *
 * ⚠️ **صفر `style=` وصفر `<style>`** — كل الكلاسات (`povw-*`) في `app.css`.
 *
 * متغيّرات: $day $work $filter $rep_id $stats $permits $reps $shown $locked
 */
use App\Models\Permit;

$stats   = $stats   ?? [];
$permits = $permits ?? [];
$reps    = $reps    ?? [];
$filter  = $filter  ?? 'all';
$repId   = (int) ($rep_id ?? 0);
$isToday = $day === ($work ?? $day);

/** رابط النافذة نفسها بعد تغيير فلتر أو يوم — نقطة بناء واحدة بلا تكرار. */
$ovUrl = static function (array $over = []) use ($day, $filter, $repId): string {
    $q = array_merge(['d' => $day, 'f' => $filter, 'r' => $repId], $over);
    $q = array_filter($q, static fn ($v, $k) => $v !== '' && $v !== 0
        && !($k === 'f' && $v === 'all'), ARRAY_FILTER_USE_BOTH);
    return url('permits/overview' . ($q ? '?' . http_build_query($q) : ''));
};

/* ⚠️ **الطيّ الافتراضي بيتقرّر بالعدد**: يوم فيه ٣ أذونات المالك عايز يشوف
   بنوده على طول؛ ويوم فيه ١٥ إذنًا القايمة المفتوحة بتبقى شاشتين تمرير قبل ما
   يوصل للتالت. الحد ٦ — والزرار فوق بيقلب الكل بضغطة. */
$openByDefault = count($permits) <= 6;

/** «٣ بنود» لا «٣ بند» — تصريف عربي بسيط لعدّ البنود. */
$bandf = static fn (int $n): string => $n . ' ' . ($n >= 3 && $n <= 10 ? 'بنود' : 'بند');

/** شريحة رقم قابلة للضغط — بتفتح نفس النافذة مفلترة. */
$chip = static function (string $key, string $label, string $icon, $count, string $tone = '')
        use ($filter, $ovUrl): void {
    if ((int) $count <= 0 && $key !== 'all') { return; }
    $on = $filter === $key ? ' on' : '';
    echo '<button type="button" class="povw-f' . ($tone !== '' ? ' ' . e($tone) : '') . $on . '"'
       . ' data-drill="' . e($ovUrl(['f' => $key])) . '" data-drill-title="نظرة عامة — ' . e($label) . '">'
       . icon($icon, 14) . '<span>' . e($label) . '</span>'
       . '<b class="num">' . intf((int) $count) . '</b></button>';
};
?>
<?php /* ⚠️ `data-wide` = **إشارة للنافذة تتوسّع** (`app.js` بيحط `.wide` على
         `.d-modal-card` لما المحتوى بيطلبها). الشاشة دي فيها ٥ أعمدة معلومات
         في السطر الواحد، وعرض ٦٤٠ الافتراضي كان بيلفّها. */ ?>
<div class="povw" data-wide data-fold-scope>

  <?php /* ① اليوم والتنقّل — البند بيتحسب بيوم صرفه، فالنافذة عدسة يوم واحد.
           ⚠️ **قدّام وورا**: المالك ممكن يرجع ٣ أيام ويحتاج يمشي لقدّام تاني —
           وزرار «قدّام» بيتقفل عند يوم العمل (المستقبل مرفوض من الأكشن أصلاً). */ ?>
  <?php
    $prevDay = date('Y-m-d', strtotime($day . ' -1 day'));
    $nextDay = date('Y-m-d', strtotime($day . ' +1 day'));
    $canNext = $nextDay <= (string) ($work ?? $day);
  ?>
  <div class="povw-top">
    <div class="povw-when">
      <?= icon('calendar', 16) ?>
      <b><?= e(dfmt_long($day)) ?></b>
      <?php if (!$isToday): ?><span class="povw-tag">يوم فات</span><?php endif; ?>
      <?php if (!empty($locked)): ?><span class="povw-tag red"><?= icon('lock', 12) ?> مُقفَل</span><?php endif; ?>
    </div>
    <div class="povw-days">
      <button type="button" class="povw-day-btn prev"
              data-drill="<?= e($ovUrl(['d' => $prevDay, 'f' => 'all', 'r' => 0])) ?>"
              data-drill-title="نظرة عامة — <?= e(dfmt($prevDay)) ?>"><?= icon('chevron', 14) ?> <?= e(dfmt($prevDay)) ?></button>
      <?php if (!$isToday): ?>
        <button type="button" class="povw-day-btn on"
                data-drill="<?= e($ovUrl(['d' => $work, 'f' => 'all', 'r' => 0])) ?>"
                data-drill-title="نظرة عامة — النهاردة">النهاردة</button>
      <?php endif; ?>
      <?php if ($canNext): ?>
        <button type="button" class="povw-day-btn next"
                data-drill="<?= e($ovUrl(['d' => $nextDay, 'f' => 'all', 'r' => 0])) ?>"
                data-drill-title="نظرة عامة — <?= e(dfmt($nextDay)) ?>"><?= e(dfmt($nextDay)) ?> <?= icon('chevron', 14) ?></button>
      <?php endif; ?>
    </div>
  </div>

  <?php /* ② الرقم البطل + توابعه */ ?>
  <div class="povw-hero">
    <div class="povw-hero-main">
      <span class="povw-hero-lbl"><?= icon('trending-down', 14) ?> اتصرف على الأذونات</span>
      <b class="povw-hero-num num"><?= money0($stats['out_val'] ?? 0, false) ?><em>ج.م</em></b>
      <div class="povw-hero-tags">
        <?php if (($stats['cash_val'] ?? 0) > 0): ?>
          <span class="povw-t"><?= icon('wallet', 12) ?> كاش <b class="num"><?= money0($stats['cash_val'], false) ?></b></span>
        <?php endif; ?>
        <?php if (($stats['visa_val'] ?? 0) > 0): ?>
          <span class="povw-t"><?= icon('credit-card', 12) ?> فيزا <b class="num"><?= money0($stats['visa_val'], false) ?></b></span>
        <?php endif; ?>
        <?php if (($stats['client_val'] ?? 0) > 0): ?>
          <span class="povw-t gold"><?= icon('receipt', 12) ?> للعميل <b class="num"><?= money0($stats['client_val'], false) ?></b></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="povw-hero-side">
      <div class="povw-mini">
        <b class="num"><?= intf($stats['permits'] ?? 0) ?></b>
        <span><?= icon('folder', 12) ?> إذن</span>
      </div>
      <div class="povw-mini">
        <b class="num"><?= intf($stats['items'] ?? 0) ?></b>
        <span><?= icon('layers', 12) ?> بند</span>
      </div>
      <div class="povw-mini">
        <b class="num"><?= intf($stats['reps_cnt'] ?? 0) ?></b>
        <span><?= icon('users', 12) ?> مندوب</span>
      </div>
    </div>
  </div>

  <?php /* ③ الشرايح = فلاتر. الرقم اللي على الشريحة بيتحسب على **اليوم كله**
           مهما كان الفلتر الحالي، عشان ما يتغيّرش تحت إيد المالك. */ ?>
  <div class="povw-filters">
    <?php $chip('all', 'الكل', 'layers', $stats['items'] ?? 0); ?>
    <?php $chip('reps', 'من المناديب', 'user', $stats['rep_cnt'] ?? 0, 'teal'); ?>
    <?php $chip('pending', 'مستنّي اعتمادك', 'hourglass', $stats['pending_cnt'] ?? 0, 'wait'); ?>
    <?php $chip('office', 'من المكتب', 'building', $stats['office_cnt'] ?? 0); ?>
    <?php $chip('receipts', 'إيصالات', 'receipt', $stats['receipt_cnt'] ?? 0, 'gold'); ?>
    <?php $chip('expense', 'مصروفات', 'coins', $stats['expense_cnt'] ?? 0); ?>
    <?php $chip('missing', 'ناقص بياناته', 'alert', $stats['missing_cnt'] ?? 0, 'warn'); ?>
    <?php $chip('visa', 'بالفيزا', 'credit-card', $stats['visa_cnt'] ?? 0); ?>
    <?php /* ⚠️ الزرار ده **الإضافة الوحيدة اللي محتاجة جافاسكريبت** في الشاشة —
             طيّ الإذن الواحد `<details>` أصلية شغّالة من غيره. النص بيتقلب في
             `app.js` حسب حالة القوايم. */ ?>
    <?php if ($permits): ?>
      <button type="button" class="povw-f fold" data-fold-all=".povw-g">
        <?= icon('layers', 14) ?><span><?= $openByDefault ? 'اطوي الكل' : 'افتح الكل' ?></span></button>
    <?php endif; ?>
  </div>

  <?php /* ④ شريط المناديب — مين نزل النهاردة وجاب كام بند */ ?>
  <?php if ($reps): ?>
    <div class="povw-reps">
      <?php foreach ($reps as $rp): ?>
        <button type="button" class="povw-rep<?= $repId === $rp['rep_id'] ? ' on' : '' ?>"
                data-drill="<?= e($ovUrl(['r' => $repId === $rp['rep_id'] ? 0 : $rp['rep_id']])) ?>"
                data-drill-title="نظرة عامة — <?= e($rp['name']) ?>">
          <span class="povw-rep-ava"><?= e(mb_substr($rp['name'], 0, 1)) ?></span>
          <span class="povw-rep-b">
            <b><?= e($rp['name']) ?></b>
            <span><?= e($bandf((int) $rp['cnt'])) ?> · <?= money0($rp['val'], false) ?></span>
          </span>
          <?php if ($rp['pending_cnt'] > 0): ?>
            <span class="povw-rep-w num"><?= intf($rp['pending_cnt']) ?></span>
          <?php endif; ?>
        </button>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php /* ⑤ البنود مجمّعة بالإذن — الأكتر حركة الأول */ ?>
  <?php if (!$permits): ?>
    <?php partial('empty', [
      'emoji' => '📭',
      'title' => ($stats['items'] ?? 0) > 0
        ? 'مفيش بند في التصفية دي'
        : 'مفيش إيصالات على الأذونات في ' . dfmt($day),
    ]); ?>
  <?php else: ?>
    <div class="povw-groups">
      <?php foreach ($permits as $p): ?>
        <?php /* ⚠️ `cls` مش `tone`: `TYPE_THEME` مالهاش مفتاح اسمه `tone` خالص،
                 و`$th['tone'] ?? ''` بتطبع كلاسًا فاضيًا **بالسكوت** فالنوع
                 يفضل بلا لون (نفس مصيدة الكلاس بلا CSS). */ ?>
        <?php $th = Permit::theme((string) $p['permit_type']); ?>
        <?php /* ⚠️ `<details>` أصلية مش جافاسكريبت: الطيّ بيشتغل حتى لو `app.js`
                 ما اتحمّلش، وبيشتغل بالكيبورد بلا أي كود. الجافاسكريبت بيزوّد
                 حاجة واحدة بس فوقها: «افتح/اطوي الكل». */ ?>
        <details class="povw-g"<?= $openByDefault ? ' open' : '' ?>>
          <summary class="povw-g-h">
            <span class="povw-g-ic <?= e($th['cls']) ?>"><?= icon('folder', 17) ?></span>
            <span class="povw-g-t">
              <b><?= e(Permit::typeLabel($p['permit_type'])) ?> <?= e($p['permit_no'] ?: '#' . $p['id']) ?></b>
              <span class="povw-g-m">
                <?php /* ⚠️ الحاويات بتصنيفها **أول حاجة** بعد الرقم — دي اللي
                         بيميّز بيها المالك الإذن بعينه (طلبه بالحرف). */ ?>
                <?php if (($p['containers_label'] ?? '') !== ''): ?>
                  <span class="povw-b ct"><?= icon('containers', 11) ?> <?= e($p['containers_label']) ?></span>
                <?php endif; ?>
                <?php if (($p['agency'] ?? '') !== ''): ?><span><?= icon('ship', 11) ?> <?= e($p['agency']) ?></span><?php endif; ?>
                <?php if (($p['port'] ?? '') !== ''): ?><span><?= icon('anchor', 11) ?> <?= e($p['port']) ?></span><?php endif; ?>
                <?php if (($p['company'] ?? '') !== ''): ?><span><?= icon('building', 11) ?> <?= e($p['company']) ?></span><?php endif; ?>
                <?php if (($p['client'] ?? '') !== ''): ?><span><?= icon('user', 11) ?> <?= e($p['client']) ?></span><?php endif; ?>
                <?php if ($p['reps']): ?><span><?= icon('users', 11) ?> <?= e(implode(' · ', $p['reps'])) ?></span><?php endif; ?>
              </span>
            </span>
            <span class="povw-g-badges">
              <?php /* شارات وظيفية بس — بتظهر لما يكون فيه حاجة تتعمل */ ?>
              <?php if ($p['pending_cnt'] > 0): ?>
                <span class="povw-b wait"><?= icon('hourglass', 11) ?> <span class="num"><?= intf($p['pending_cnt']) ?></span> مستنّي</span>
              <?php endif; ?>
              <?php if ($p['missing_cnt'] > 0): ?>
                <span class="povw-b warn"><?= icon('alert', 11) ?> <span class="num"><?= intf($p['missing_cnt']) ?></span> ناقص</span>
              <?php endif; ?>
              <?php if (($p['permit_status'] ?? '') === 'closed'): ?>
                <span class="povw-b done"><?= icon('check', 11) ?> مقفول</span>
              <?php endif; ?>
            </span>
            <span class="povw-g-num">
              <b class="num"><?= money0($p['val'], false) ?></b>
              <span><?= e($bandf((int) $p['cnt'])) ?></span>
            </span>
            <?php /* ⚠️ الرابط **زرار مستقل** جوّه الترويسة مش الترويسة كلها:
                     الضغط على السطر بيطوي/بيفتح، والضغط على السهم بينقل للإذن —
                     وإلا كل محاولة طيّ كانت هتسيب الشاشة. */ ?>
            <a class="povw-g-go" href="<?= url('permits/' . $p['id']) ?>" title="افتح الإذن"><?= icon('external', 15) ?></a>
          </summary>

          <div class="povw-items">
            <?php foreach ($p['items'] as $it): ?>
              <?php
                /* البند المستنّي بيوصّل لصندوق المراجعة (هناك بيتعمد)، والمعتمَد
                   بيوصّل لتايم لاين الإذن حيث بيتعدّل. */
                $href = $it['pending']
                  ? url('rep-custody/inbox')
                  : url('permits/' . $p['id'] . '/manage#stage-data');
                $tone = $it['pending'] ? 'wait' : ($it['kind'] === 'revenue' ? 'rev' : ($it['kind'] === 'receipt' ? 'rc' : 'ex'));
                /* ⚠️ **يوم التسجيل مش دايمًا يوم الصرف** (قاعدة v29): المندوب
                   بيسجّل بند إمبارح النهاردة، والبند بيتحسب على يوم صرفه.
                   الشارة دي بتقول ده صراحةً بدل ما الساعة تضلّل.
                   ⚠️ المقارنة عبر `dtfmt()` لأن `created_at` بتوقيت القاعدة (UTC). */
                $logged = dtfmt($it['created_at'], 'Y-m-d');
              ?>
              <a class="povw-it <?= e($tone) ?>" href="<?= e($href) ?>">
                <span class="povw-it-time num"><?= e(dtfmt($it['created_at'], 'H:i')) ?></span>
                <span class="povw-it-b">
                  <b><?= e(trim((string) $it['note']) !== '' ? $it['note'] : Permit::STAGES[$it['stage_key']]['label'] ?? 'بند') ?></b>
                  <span class="povw-it-m">
                    <?php if ($it['pending']): ?>
                      <span class="povw-b wait"><?= icon('hourglass', 11) ?> مستنّي اعتمادك</span>
                    <?php endif; ?>
                    <?php if ($it['by_rep']): ?>
                      <?php /* ⚠️ **نفس مكوّن الشارة** بتاع شاشة الإذن ووضع التعديل
                               (`partials/rep_badge`) — شكل واحد لنفس المعلومة. */ ?>
                      <?php partial('rep_badge', ['name' => $it['rep_name'] ?: 'مندوب', 'size' => 11]); ?>
                    <?php else: ?>
                      <span><?= icon('building', 11) ?> المكتب</span>
                    <?php endif; ?>
                    <?php if ($it['qty'] > 1): ?><span class="num">×<?= intf($it['qty']) ?></span><?php endif; ?>
                    <?php if ($it['movement_seq']): ?><span><?= icon('shuffle', 11) ?> حركة <span class="num"><?= intf($it['movement_seq']) ?></span></span><?php endif; ?>
                    <?php if (trim((string) $it['ref_no']) !== ''): ?><span><?= icon('tag', 11) ?> <span class="num"><?= e($it['ref_no']) ?></span></span><?php endif; ?>
                    <?php if ($it['is_visa']): ?><span class="povw-b"><?= icon('credit-card', 11) ?> فيزا</span><?php endif; ?>
                    <?php if (!$it['is_cash'] && !$it['is_visa']): ?><span class="povw-b">غير مدفوع</span><?php endif; ?>
                    <?php if ($it['has_photo']): ?><span class="povw-b"><?= icon('camera', 11) ?> صورة</span><?php endif; ?>
                    <?php if ($it['missing']): ?><span class="povw-b warn"><?= icon('alert', 11) ?> ناقص بياناته</span><?php endif; ?>
                    <?php if ($logged !== '' && $logged !== $it['entry_date']): ?>
                      <span><?= icon('clock', 11) ?> اتسجّل <?= e(dfmt($logged)) ?></span>
                    <?php endif; ?>
                  </span>
                </span>
                <span class="povw-it-num">
                  <b class="num"><?= money0($it['amount'], false) ?></b>
                  <?php if ($it['actual_amount'] !== null): ?>
                    <span>للعميل <?= money0($it['actual_amount'], false) ?></span>
                  <?php endif; ?>
                </span>
              </a>
            <?php endforeach; ?>
          </div>
        </details>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php /* ⑥ التذييل: المجموع + طريق الشاشة الكاملة (البوب-أب مش بديل الشاشة) */ ?>
  <div class="povw-foot">
    <span class="povw-foot-t">إجمالي المعروض:
      <b class="num"><?= money0(array_sum(array_map(static fn ($p) => $p['val'], $permits)), false) ?></b>
      <span><?= intf($shown ?? count($permits)) ?> إذن</span>
    </span>
    <?php if (($stats['pending_cnt'] ?? 0) > 0): ?>
      <a class="btn sm" href="<?= url('rep-custody/inbox') ?>"><?= icon('inbox', 14) ?> صندوق المراجعة (<span class="num"><?= intf($stats['pending_cnt']) ?></span>)</a>
    <?php endif; ?>
    <?php if (($stats['missing_cnt'] ?? 0) > 0): ?>
      <a class="btn ghost sm" href="<?= url('expenses/receipts') ?>"><?= icon('edit', 14) ?> استكمال بيانات الإيصالات</a>
    <?php endif; ?>
    <a class="btn ghost sm" href="<?= url('permits') ?>">كل الأذونات <?= icon('external', 14) ?></a>
  </div>
</div>
