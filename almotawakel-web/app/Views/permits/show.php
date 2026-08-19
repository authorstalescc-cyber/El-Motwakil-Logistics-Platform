<?php
/**
 * شاشة الإذن/الشهادة — **شاشة واحدة بوضعين** (المرحلة ١٣، 2026-07-25).
 *
 * قرار المالك من المرحلة ٣ كان دمج `view` و`manage`، والمرحلة ٣ عملت نص الشغل:
 * الهيرو وشريط الإجراءات بقوا أجزاء مشتركة ومفتاح الوضع اتحطّ. اللي كان باقي هو
 * **الفصل نفسه**: ملفان كاملان وأكشنان في الكنترولر، فأي تعديل على الغلاف كان
 * لازم يتعمل مرتين — وده بالظبط اللي بيخلّي الشاشتين يفترقوا مع الوقت.
 *
 * دلوقتي: **غلاف واحد** (الملف ده) بيرسم شريط الإجراءات والهيرو ومرشد
 * الإيصالات مرة واحدة، وبعدين بيضمّ جسم الوضع المطلوب.
 *
 * ⚠️ **الجسمان فضلوا منفصلين عن قصد** (`_body_view.php` · `_body_manage.php`):
 *    مُصيّر المراحل للقراءة ومُصيّرها للتحرير **مكوّنان مختلفان فعلاً** —
 *    التحرير مربوط بعقد AJAX في `_movement.php` (التايم لاين بيتعاد رسمه من
 *    الخادم)، فدمجهم في مُصيِّر واحد بشروط كان هيعقّد الاتنين بلا مكسب.
 *
 * ⚠️ **المساران القديمان زي ما هما**: `/permits/{id}` و`/permits/{id}/manage`
 *    — الروابط المحفوظة عند المالك لازم تفضل شغّالة (قاعدة الخطة).
 *
 * متغيّرات: $p $movements $permitStages $mode ('view' | 'manage') + بيانات الوضع
 */
use App\Models\Permit;

$mode = ($mode ?? 'view') === 'manage' ? 'manage' : 'view';
$pid  = (int) $p['id'];
$type = $p['permit_type'];
$th   = Permit::theme($type);
$noLabel  = Permit::noLabel($type);
$isClosed = ($p['status'] ?? 'open') === 'closed';

/* ⚠️ التقدّم بيتحسب **هنا** لأن الهيرو بيتطبع في الغلاف قبل الجسم. لو رجع
   للجسم يبقى الهيرو بيقرا متغيّرًا لسه ما اتحسبش (اتصاد فعليًّا: أربع
   تحذيرات PHP جوّه الصفحة).
   ⚠️ **العدّ نفسه في `Permit::completionOf()` مش هنا** — كان مكتوبًا بالإيد
   هنا وبقاعدة تانية في كنترولر شاشة المتابعة، فالإذن اللي فيه أكتر من حركة
   كان بيقول نسبتين مختلفتين في الشاشتين. هنا بنجهّز **المدخلات** بس. */
$pStages = Permit::scopedStages('permit');
$mStages = Permit::scopedStages('movement');
$permitHas = [];
foreach ($pStages as $k => $m) { $permitHas[$k] = !empty($permitStages[$k] ?? []); }
$movementHas = [];
foreach ($movements as $mv) {
    $row = [];
    foreach ($mStages as $k => $m) {
        $row[$k] = $k === 'bills' ? !empty($mv['bills']) : !empty($mv['stages'][$k] ?? []);
    }
    $movementHas[] = $row;
}
['done' => $done, 'tot' => $tot, 'percent' => $progress] = Permit::completionOf($permitHas, $movementHas);

$permitFormState = $permitFormState ?? [
    'token' => '', 'old' => [], 'errors' => [], 'back' => 'permits/' . $pid . '/manage#stage-data',
];
?>
<?php if ($mode === 'manage'): ?>
  <?= formErrorSummary($permitFormState['errors']) ?>
<?php endif; ?>

<?php /* شريط الإجراءات + مفتاح الوضع — مرة واحدة للوضعين */ ?>
<?php include __DIR__ . '/_bar.php'; ?>

<?php /* ⚠️ الغلاف `.permit-manage` لوضع التعديل بس — بيتقفل جوّه
         `_body_manage.php` (قبل الـdatalists) زي ما كان بالظبط. وضع العرض
         مالوش غلاف أصلاً، فما نضفش واحدًا بلا CSS (مصيدة الكلاس اللي بيتحقن
         ومفيش له تعريف). */ ?>
<?php if ($mode === 'manage'): ?><div class="permit-manage"><?php endif; ?>

<?php /* الهيرو — كان مكرَّرًا حرفيًّا في الشاشتين (المرحلة ٣) */ ?>
<?php include __DIR__ . '/_hero.php'; ?>

<?php /* رحلة الشحنة — تايم لاين الحالة التشغيلية (v62، وصفي بحت) */ ?>
<?php include __DIR__ . '/_journey.php'; ?>

<?php /* مستندات الأطراف — البطاقة الضريبية/السجل التجاري (v56، عرض بحت) */ ?>
<?php include __DIR__ . '/_docs.php'; ?>

<?php /* مرشد الإيصالات الذكي (استرشادي — v32) */ ?>
<?php $back = $mode; include __DIR__ . '/_rcp_guide.php'; ?>

<?php include __DIR__ . '/_body_' . $mode . '.php'; ?>
