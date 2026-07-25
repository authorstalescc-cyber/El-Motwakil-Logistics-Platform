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

$tabUrl = static fn (string $t): string => url('rep-custody/' . $repId . '?tab=' . $t);
$dayLabel = dfmt($day);
$isToday  = $day === date('Y-m-d');
$pendingDaySum = 0.0;
foreach ($pendingAll as $p) {
    if ((string) $p['entry_date'] === $day && empty($p['is_visa'])) { $pendingDaySum += (float) $p['amount']; }
}
$pendingDaySum = round($pendingDaySum, 2);
?>
<div class="toolbar">
  <div class="section-head" style="margin:0"><?= icon('user', 18) ?> <?= e($rep['name']) ?></div>
  <?php if (($rep['status'] ?? 'active') !== 'active'): ?>
    <span class="pill st-partial">متوقف</span>
  <?php endif; ?>
  <div class="spacer"></div>
  <?php /* الأرصدة مقنّعة افتراضيًّا — المعالج عام في app.js (مش سكربت محلي) */ ?>
  <button type="button" class="btn ghost secret-toggle" aria-pressed="false">👁 إظهار الأرصدة</button>
  <a class="btn ghost" href="<?= url('reps/' . $repId) ?>">كشف المرتبات</a>
  <a class="btn ghost" href="<?= url('rep-custody') ?>">‹ كل المناديب</a>
</div>

<?php /* شريط يوم العمل — المكوّن الموحّد (كان مكتوبًا بالإيد هنا `.rc-daybar`
         فبقى نسخة تانية بتفترق عن باقي الشاشات مع أول تعديل). */ ?>
<?php partial('day_bar', [
  'day'    => $day,
  'locked' => $dayLocked,
  'note'   => 'عهدة اليوم مستقلة تمامًا — الفاضل من إمبارح ما بيترحّلش عليها.',
  'facts'  => [[
    'label' => 'برّه معاه (كل الأيام)',
    'value' => intf($b['balance']),
    'tone'  => $b['balance'] < 0 ? 'neg' : 'pos',
  ]],
]); ?>

<?php /* ═══ عهدة اليوم — الأرقام الأساسية للشاشة ═══ */ ?>
<?php /* ⚠️ **ممنوع رقم أصمّ**: كل رقم بيفتح بنوده من
         `GET /rep-custody/drill/{what}?rep={id}` — بيتجابوا عند الضغط بس. */ ?>
<?php $rd = static fn (string $what): string => url('rep-custody/drill/' . $what . '?rep=' . $repId); ?>
<?php partial('stat_grid', ['items' => 4, 'class' => 'mb', 'body' => function () use ($dayB, $pendingDaySum, $rd, $dayLabel) { ?>
  <?php partial('stat', [
    'label' => 'نزل له اليوم', 'value' => intf($dayB['deposits']),
    'icon' => 'wallet', 'tone' => 'green',
    'drill' => $rd('rep_deposits'), 'title' => 'العهدة اللي نزلت له في ' . $dayLabel,
  ]); ?>
  <?php partial('stat', [
    'label' => 'صرفه اليوم', 'value' => intf($dayB['spent']),
    'icon' => 'trending-down', 'tone' => 'red',
    'drill' => $rd('rep_spent'), 'title' => 'اللي صرفه في ' . $dayLabel,
  ]); ?>
  <?php partial('stat', [
    'label' => 'فاضل من عهدة اليوم', 'value' => intf($dayB['balance']),
    'icon' => 'briefcase', 'tone' => 'gold', 'secret' => true,
    'drill' => $rd('rep_spent'), 'title' => 'الفاضل من عهدة ' . $dayLabel,
  ]); ?>
  <?php partial('stat', [
    'label' => 'مستني مراجعتك اليوم', 'value' => intf($pendingDaySum),
    'icon' => 'hourglass', 'tone' => 'sader',
    'drill' => $rd('rep_pending'), 'title' => 'بنود ' . $dayLabel . ' المستنية اعتمادك',
  ]); ?>
<?php }]); ?>

<?php /* ⚠️ سطر «التراكمي» المستقل اتشال (المرحلة ١٢): الرقم نفسه بقى **حقيقة
         في شريط اليوم** فوق، وتفاصيله (نزل إجمالًا · صرف · رجّع) مكانها تبويب
         «الحساب الكامل» — عرض نفس الرقم مرتين في شاشة واحدة زحمة بلا فايدة. */ ?>
<?php if ($pendingAll): ?>
  <div class="note mb rc-pend">
    <?= icon('hourglass', 15) ?> <strong><?= intf(count($pendingAll)) ?> بند</strong> مستني مراجعتك من المندوب ده
    (<?= money(array_sum(array_column($pendingAll, 'amount')), false) ?> على كل الأيام).
    <a href="<?= url('rep-custody/inbox') ?>"><strong>راجعهم دلوقتي ←</strong></a>
  </div>
<?php endif; ?>

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

<style>
.rc-pend{background:var(--gold-light);border-color:var(--gold)}

/* ── شرائح أداء اليوم ── */
.rc-perf{display:flex;gap:10px;flex-wrap:wrap;padding:14px 18px}
.rc-chip{display:flex;flex-direction:column;gap:2px;min-width:120px;flex:1 1 120px;
  border:1px solid var(--line);border-radius:var(--radius-2);padding:10px 12px;background:var(--bg)}
.rc-chip .k{font-size:var(--fs-1);color:var(--grey);font-weight:700}
.rc-chip .v{font-size:var(--fs-4);font-weight:900;color:var(--navy);line-height:1.2}
.rc-chip .n{font-size:var(--fs-1);color:var(--grey)}
.rc-chip.on{border-color:var(--gold);background:var(--gold-light)}

/* ── معادلة عهدة اليوم ── */
.rc-eq{display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:14px 18px;
  font-size:var(--fs-2);color:var(--navy)}
.rc-eq-part{display:flex;flex-direction:column;align-items:center;gap:1px;
  padding:6px 12px;border:1px solid var(--line);border-radius:var(--radius-2);background:var(--bg);min-width:88px}
.rc-eq-part .k{font-size:var(--fs-1);color:var(--grey);font-weight:700;white-space:nowrap}
.rc-eq-part .v{font-weight:900}
.rc-eq-part.res{background:var(--gold-light);border-color:var(--gold)}
.rc-eq-op{font-weight:900;color:var(--grey)}

@media (max-width:640px){
  .rc-eq{gap:6px;font-size:var(--fs-1)}
  .rc-eq-part{min-width:72px;padding:6px 9px}
}
</style>
