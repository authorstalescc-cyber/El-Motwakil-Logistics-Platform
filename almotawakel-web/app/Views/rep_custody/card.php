<?php
/**
 * كارت المندوب — **شاشة يوم واحد + ٤ تبويبات**.
 *
 * الشاشة القديمة كانت صفحة واحدة فيها ١١ قسم فوق بعض (حساب دخول + صلاحيات +
 * تنزيل أذونات + جدول أذونات + عهدة كل يوم + تنزيل عهدة + تسوية + كل الصرف +
 * التنزيلات + التسويات + التحويلات) — المالك لازم ينزل نص الصفحة عشان يشوف
 * اللي حصل النهاردة.
 *
 * ⚠️ التبويبات **روابط حقيقية** (`?tab=`) لا تبديل بالجافاسكريبت: الرجوع
 *    بالمتصفح والمشاركة والفتح في تبويب جديد بيشتغلوا، وكل تبويب بيحمّل
 *    بياناته هو بس. وكل `redirect` بعد حفظ بيرجّع لتبويب الفورم نفسه
 *    (`RepCustodyController::cardUrl`) وإلا المرساة تروح على تبويب مش معروض.
 *
 * ⚠️ التبويب الافتراضي «اليوم» = يوم العمل المختار وقت تسجيل الدخول
 *    (`Auth::workDate`). قاعدة v49: **مفيش ترحيل** — عهدة كل يوم مستقلة.
 */
use App\Core\FormState;

$repId = (int) $rep['id'];
$cardBack = 'rep-custody/' . $repId . '?tab=' . $tab;
$blank = ['token' => FormState::token(), 'old' => [], 'errors' => [], 'back' => $cardBack];
$depositState = $depositState ?? $blank;
$settleState  = $settleState  ?? $blank;
$accountState = $accountState ?? $blank;
$resetState   = $resetState   ?? $blank;
$assignState  = $assignState  ?? $blank;
$depositOld = $depositState['old']; $depositErrors = $depositState['errors'];
$settleOld  = $settleState['old'];  $settleErrors  = $settleState['errors'];
$accountOld = $accountState['old']; $accountErrors = $accountState['errors'];
$assignOld  = $assignState['old'];  $assignErrors  = $assignState['errors'];
$selectedPermits = array_map('intval', (array) ($assignOld['permit_ids'] ?? []));

/* ⚠️ يوم العرض بيتنقّل مع كل رابط في الكارت — التبويبات والنوافذ. لو تبويب
   ضاع منه اليوم، الضغط عليه بيرجّعك ليوم الشغل فجأة وانت بتراجع يومًا فات. */
$dayArg = $day === $workDay ? '' : '&day=' . rawurlencode($day);
$tabUrl = static fn (string $t): string => url('rep-custody/' . $repId . '?tab=' . $t . $dayArg);
$dayLabel = dfmt($day);
$isToday  = $day === date('Y-m-d');
// ⚠️ مبلغ المستنّي جاي من الكنترولر (`RepPortal::pendingAmount`) — كان بيتجمّع
//    هنا بالإيد من `$pendingAll` وده بقى تعريفًا تانيًا ممكن يفترق عن باقي
//    الشاشات. ممنوع ترجع تجمعه في العرض.
$pendingDaySum = round((float) ($dayPendingSum ?? 0), 2);
// «فاضل معاه» = رصيد اليوم بعد خصم المستنّي كمان — نفس تعريف شاشة المندوب
// (`RepPortal::dayPocket`) وشاشة كل المناديب (`dayTotalsByRep`).
$dayLeft = round((float) $dayB['balance'] - $pendingDaySum, 2);

/* ⚠️ **ممنوع رقم أصمّ**: كل رقم في الشاشة بيفتح بنوده من
   `GET /rep-custody/drill/{what}?rep={id}` — بيتجابوا عند الضغط بس. */
$rd = static fn (string $what): string => url('rep-custody/drill/' . $what . '?rep=' . $repId);

/* تفكيك «نزل له اليوم» بالمصدر — نفس صياغة `/rep-custody` بالحرف (`sourceBadge`)
   ونفس نطاق الرقم (`depositSourceTotals` بالمندوب واليوم). بيختفي لو مصدر واحد
   بس عشان ما يكررش اللي الرقم قايله. */
$cardSrc = \App\Models\RepCustody::depositSourceTotals($day, $repId);
$cardSplit = null;
if (count($cardSrc) > 1) {
    $parts = [];
    /* ⚠️ **الاسم `$badge` مش `$b`.** `$b` هو رصيد المندوب (`breakdown`) واللوب
       دي كانت **بتدهسه** — فالمندوب اللي نزلت له عهدة من مصدرين في يوم واحد،
       تبويب «الحساب الكامل» (اللي بيتحمّل بعدها) كان بيقرا `$b['balance']` من
       مصفوفة شارة: «الباقي معاه ٠٫٠٠» و**زرار «سجّل التسوية» بيتقفل**، يعني
       مستحيل تقفل حسابه. اتّجرب وأُعيد إنتاجه؛ محروس في `verify_rep_custody_day`. */
    foreach ($cardSrc as $k => $v) {
        $badge = \App\Models\RepCustody::sourceBadge($k);
        if ($badge) { $parts[] = $badge['short'] . ' ' . money0($v); }
    }
    if ($parts) { $cardSplit = ['text' => implode(' · ', $parts)]; }
}
?>
<?php /* ═══ الترويسة الموحّدة ═══
   ⚠️ **كانت ٥ طبقات قبل أي معلومة**: توولبار (اسم + زرارين) ← شريط اليوم ←
      ٤ كروت ← بانر «مستني» ← شريط التبويبات. بقت **٣**: الترويسة دي (مين +
      كام) ← شريط اليوم (سياق + تنقّل) ← التبويبات.
   ⚠️ **وكل رقم بيظهر مرة واحدة.** «فاضل معاه» كان في كارت + معادلة اليوم +
      سطر تحتها، و«المستني» في كارت + بانر + خطوة في المعادلة + لوح كامل —
      البانر اتشال خالص (بقى رقمًا هنا وشارة على التبويب) والسطور التوضيحية
      اتشالت لأن المعادلة نفسها بتوري الخطوة.
   ⚠️ الرقم البطل والأرقام الجانبية كلهم `data-drill` — نفس آلية `stat`
      بالحرف، فقاعدة «ممنوع رقم أصمّ» متحققة والنافذة واحدة في النظام كله. */ ?>
<div class="rc-hero">
  <div class="rc-hero-id">
    <span class="rc-hero-av"><?= icon('user', 20) ?></span>
    <div class="rc-hero-name">
      <b><?= e($rep['name']) ?></b>
      <?php if (($rep['status'] ?? 'active') !== 'active'): ?>
        <span class="pill st-partial">متوقف</span>
      <?php endif; ?>
      <?php if (!empty($rep['phone'])): ?><em><?= e($rep['phone']) ?></em><?php endif; ?>
    </div>
    <div class="rc-hero-acts">
      <?php /* ⚠️ الطريق لمراجعة بنوده **فضل** لكن كزرار بعدّاد بدل فقرة كاملة
               كانت بتكرر رقم «المستني». و`?rep=` إلزامية — من غيرها الرابط
               بيوَدّي على كل المناديب والمالك يدوّر وسطهم. */ ?>
      <?php if ($pendingAll): ?>
        <a class="btn gold" href="<?= url('rep-custody/inbox?status=pending&rep=' . $repId) ?>">
          <?= icon('inbox', 15) ?> راجع <?= intf(count($pendingAll)) ?> بند
        </a>
      <?php endif; ?>
      <a class="btn ghost" href="<?= url('reps/' . $repId) ?>">كشف المرتبات</a>
      <a class="btn ghost" href="<?= url('rep-custody') ?>">‹ كل المناديب</a>
    </div>
  </div>

  <?php /* ⚠️ **الأرقام بمكوّن النظام (`stat_grid` + `stat`) لا ماركب بالإيد.**
           أول نسخة من الترويسة دي كتبت أزرارها بإيدها — و`verify_reps_ui`
           رفضها **بحق**: الماركب اليدوي بينفصل عن لغة النظام مع أول تعديل في
           التوكنز، و`stat` هو اللي بيفرض «ممنوع رقم أصمّ» (بيرفض يترسم بلا
           مصدر تفاصيل). الحل كان **نمط `hero` جديد في المكوّن نفسه** عشان
           الشاشة يبقى ليها رقم مسيطر — مكسب للنظام كله مش ترقيع للشاشة دي. */ ?>
  <?php partial('stat_grid', ['items' => 4, 'class' => 'rc-hero-grid',
    'body' => function () use ($dayB, $pendingDaySum, $dayLeft, $rd, $dayLabel, $b, $tabUrl) { ?>
    <?php partial('stat', [
      'hero'  => true,
      'label' => $pendingDaySum > 0 ? 'فاضل معاه (بعد المستني)' : 'فاضل من عهدة اليوم',
      'value' => intf($dayLeft),
      'icon'  => 'briefcase', 'tone' => $dayLeft < 0 ? 'red' : 'gold',
      'drill' => $rd('rep_spent'), 'title' => 'الفاضل من عهدة ' . $dayLabel,
    ]); ?>
    <?php partial('stat', [
      'label' => 'نزل له اليوم', 'value' => intf($dayB['deposits']),
      'icon'  => 'wallet', 'tone' => 'green',
      'drill' => $rd('rep_deposits'), 'title' => 'العهدة اللي نزلت له في ' . $dayLabel,
    ]); ?>
    <?php partial('stat', [
      'label' => 'صرفه اليوم', 'value' => intf($dayB['spent']),
      'icon'  => 'trending-down', 'tone' => 'red',
      'drill' => $rd('rep_spent'), 'title' => 'اللي صرفه في ' . $dayLabel,
    ]); ?>
    <?php partial('stat', [
      'label' => 'مستني اعتمادك', 'value' => intf($pendingDaySum),
      'icon'  => 'hourglass', 'tone' => 'sader',
      'drill' => $rd('rep_pending'), 'title' => 'البنود النقدية في ' . $dayLabel . ' المستنية اعتمادك',
    ]); ?>
    <?php /* ⚠️ **التراكمي بـ`url` مش `drill`**: بنوده شاشة كاملة (تبويب الحساب
             الكامل) فيها كشوف وتصفية — النافذة كانت هتبقى نسخة أفقر من شاشة
             موجودة. المكوّن بيدعم الوضعين وبيرسم نفس الشكل. */ ?>
    <?php partial('stat', [
      'label' => 'برّه معاه (كل الأيام)', 'value' => intf($b['balance']),
      'icon'  => 'coins', 'tone' => 'navy',
      'url'   => $tabUrl('account'), 'title' => 'كل حركة العهدة معاه',
    ]); ?>
  <?php }]); ?>
  <?php if ($cardSplit): ?>
    <div class="rc-hero-src"><?= e($cardSplit['text']) ?></div>
  <?php endif; ?>
</div>

<?php /* شريط يوم العمل — سياق وتنقّل بس. **«برّه معاه» اتشال منه**: بقى رقمًا
         في الترويسة فوق، وعرضه في الاتنين كان تكرارًا لنفس الرقم في شاشة واحدة. */ ?>
<?php partial('day_bar', [
  'day'     => $day,
  'locked'  => $dayLocked,
  'actions' => static function () use ($nav) { partial('day_nav', $nav); },
]); ?>

<?php partial('tabs', ['label' => 'أقسام كارت المندوب', 'items' => [
  ['label' => 'يوم ' . $dayLabel, 'url' => $tabUrl('day'),      'active' => $tab === 'day'],
  ['label' => 'أذوناته',          'url' => $tabUrl('permits'),  'active' => $tab === 'permits',
   'count' => $assignCount ?: null],
  ['label' => 'الحساب الكامل',    'url' => $tabUrl('account'),  'active' => $tab === 'account'],
  ['label' => 'الدخول والصلاحيات','url' => $tabUrl('settings'), 'active' => $tab === 'settings'],
]]); ?>

<?php
switch ($tab) {
    case 'permits':  include __DIR__ . '/_tab_permits.php';  break;
    case 'account':  include __DIR__ . '/_tab_account.php';  break;
    case 'settings': include __DIR__ . '/_tab_settings.php'; break;
    default:         include __DIR__ . '/_tab_day.php';      break;
}
?>
