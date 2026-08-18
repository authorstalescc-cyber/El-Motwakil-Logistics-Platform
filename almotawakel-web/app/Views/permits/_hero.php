<?php
/**
 * هيرو الإذن — **شريط أفقي + كروت الأرقام** (v105).
 *
 * ⚠️ **قرار المالك (2026-08-18):** «اللي قبل قبل ده كان جيد — أريد حاجة مشابهة
 *    له بشكل احترافي وقوي». يعني الشكل الأصلي رجع: **هيرو أفقي بعرض الشاشة**
 *    فيه هوية الإذن، وتحته **صف كروت الأرقام الأربعة**، والأقسام تحتهم بعرض
 *    الصفحة. الريل الجانبي والكعب بتوع v104 اتشالوا بالكامل.
 *
 * ⚠️ **ورجع مبنيًّا من جديد لا منسوخًا**: نفس الشكل بتفاصيل أنضف — تباعد
 *    منتظم، وزن بصري متدرّج (الرقم أضخم حاجة، والحقائق أهدى)، وخط النظام
 *    (Cairo) في **كل حاجة حتى الأرقام** (طلب المالك — المونوسبيس اتشال من
 *    الشاشة كلها والاصطفاف بقى `tabular-nums` من نفس الخط).
 *
 * ⚠️ **«ممنوع رقم أصمّ»**: الأربع كروت من مكوّن `stat` اللي **بيرفض يترسم بلا
 *    مصدر تفاصيل**، وأجسام نوافذها تحت في نفس الملف.
 * ⚠️ **الغياب معلومة**: التوكيل الملاحي بيتعرض حتى لو فاضي («غير محدّد») لأن
 *    غيابه بيوقّف قواعد مرشد الإيصالات — بس مطفي عشان ما يسرقش العين.
 * ⚠️ **جزء مشترك بين وضعَي الشاشة** — بيتضمّ **مرة واحدة** من `show.php`.
 *
 * المتغيّرات: $p $pid $th $noLabel $progress $done $tot $movements $totals
 *   $billRows $moneyRows (لنوافذ التفاصيل) · $ctBreakdown (اختياري)
 */
use App\Models\Freight;
use App\Models\Permit;

// نحسبه هنا لو الشاشة ما حسبتهوش — الشاشتان كانتا بتعملاها بطريقتين مختلفتين
if (!isset($ctLbl)) {
    $ctLbl = Permit::containerLabel($ctBreakdown ?? [], (int) ($p['containers'] ?? 0), $p['container_type'] ?? null);
}

$heroBills = $billRows  ?? [];

/* عدد السيارات — **نفس دالة فورم الفوترة بالحرف** (`Freight::summary`)، عشان
   الكارت والفاتورة يقولوا نفس الرقم لنفس الإذن. وضع الإدارة ما بيبعتش
   `$ctBreakdown` أصلاً، فالدالة بتقع على بيانات الإذن نفسه وبتشتغل في الوضعين. */
$carsLbl = Freight::summary($ctBreakdown ?? [], count($heroBills), (int) ($p['containers'] ?? 0), $p['container_type'] ?? null);
$heroMoney = $moneyRows ?? [];
$heroMargin = (float) ($totals['margin'] ?? 0);

/* البنود اللي بتغذّي كل نافذة — **تصفية على نفس الصفوف** الجاية من
   `Permit::moneyRows()`، فمجموع كل نافذة = بلاطتها بالحرف. */
$heroExpenseRows = array_values(array_filter($heroMoney, fn($r) => !empty($r['is_expense'])));
$heroChargedRows = array_values(array_filter($heroMoney, fn($r) => $r['actual_amount'] !== null && $r['actual_amount'] !== ''));

/** مساهمة البند في الفرق: اللي هيتحاسب عليه ناقص اللي اتصرف عليه. */
$heroRowMargin = static function (array $r): float {
    $actual = ($r['actual_amount'] !== null && $r['actual_amount'] !== '') ? (float) $r['actual_amount'] : 0.0;
    $paid   = !empty($r['is_expense']) ? (float) $r['amount'] : 0.0;
    return round($actual - $paid, 2);
};
/* البنود المتعادلة (فرقها صفر) مش بتتعرض: مساهمتها في المجموع صفر فالمجموع
   ما بيتغيّرش، والنافذة بتفضل على اللي فعلاً بيحرّك الرقم. */
$heroMarginRows = array_values(array_filter($heroMoney, fn($r) => abs($heroRowMargin($r)) >= 0.005));

/** سطر البند الواحد في نوافذ الفلوس — شكل واحد للتلات نوافذ. */
$heroMoneyRow = static function (array $r, string $amount, string $tone, array $extraMeta = []) {
    $qty = max(1, (int) ($r['qty'] ?? 1));
    partial('row_item', [
        'title' => trim((string) ($r['note'] ?? '')) !== '' ? $r['note'] : Permit::label($r['stage_key']),
        'ref'   => trim((string) ($r['ref_no'] ?? '')) !== '' ? '#' . $r['ref_no'] : null,
        'icon'  => 'receipt',
        'meta'  => array_values(array_filter(array_merge([
            ['icon' => 'calendar', 'text' => dfmt($r['entry_date'])],
            $qty > 1 ? ['icon' => 'package', 'text' => intf($qty) . ' × ' . money((float) $r['amount'] / $qty, false)] : null,
        ], $extraMeta))),
        'amount' => $amount, 'amount_tone' => $tone,
    ]);
};
?>

<?php
/* حقائق الإذن في الهيرو — **الترتيب بيتبع السؤال**: مين · فين · إيه · إمتى.
   ⚠️ «لصالح شركة» و«الوكيل» كلمتان متشابهتان لكيانين مختلفين، فالتسمية كاملة. */
$facts = [
    ['building',  'لصالح شركة',     trim((string) ($p['company'] ?? '')),      false],
    ['user',      'الوكيل',          trim((string) ($p['client'] ?? '')),       false],
    ['landmark',  'التوكيل الملاحي', trim((string) ($p['agency'] ?? '')),       true],
    ['map-pin',   'الجهة',           trim((string) ($p['destination'] ?? '')),  false],
    ['anchor',    'الميناء',         trim((string) ($p['port'] ?? '')),         false],
    ['containers','الحاويات',        trim($ctLbl . ($ctLbl !== '' && $carsLbl !== '' ? ' · ' : '') . $carsLbl), false],
    ['calendar',  'التاريخ',         dfmt($p['permit_date']), false],
];
?>
<section class="pf-hero t-<?= $th['cls'] ?>">
  <div class="pf-hero-id">
    <div class="pf-hero-top">
      <span class="pf-kind"><i><?= $th['arrow'] ?></i> <?= $th['full'] ?></span>
      <span class="pf-state<?= $isClosed ? ' done' : '' ?>">
        <?= $isClosed ? icon('check', 12) . ' مقفول' : icon('clock', 12) . ' جارٍ' ?>
      </span>
    </div>
    <span class="pf-eyebrow"><?= e($noLabel) ?></span>
    <h1 class="pf-serial"><?= e($p['permit_no'] ?: '#' . $pid) ?></h1>

    <div class="pf-facts">
      <?php foreach ($facts as [$ic, $lbl, $val, $isKey]): ?>
        <?php if ($val === '' && !$isKey) { continue; } ?>
        <span class="pf-fact<?= $isKey ? ' key' : '' ?><?= $val === '' ? ' blank' : '' ?>">
          <span class="pf-fact-ic"><?= icon($ic, 15) ?></span>
          <span class="pf-fact-tx">
            <small><?= e($lbl) ?></small>
            <b><?= $val !== '' ? e($val) : 'غير محدّد' ?></b>
          </span>
        </span>
      <?php endforeach; ?>
    </div>
  </div>

  <?php /* حلقة التقدّم — الرقم اللي بيقول «الملف ده وصل فين» */ ?>
  <div class="pf-ring" role="img" aria-label="اكتمال الإذن <?= (int) $progress ?>٪">
    <svg viewBox="0 0 36 36" aria-hidden="true">
      <path class="pf-ring-bg" pathLength="100" d="M18 2.5a15.5 15.5 0 1 1 0 31 15.5 15.5 0 1 1 0-31"/>
      <path class="pf-ring-fill" pathLength="100" stroke-dasharray="<?= (int) $progress ?>,100"
            d="M18 2.5a15.5 15.5 0 1 1 0 31 15.5 15.5 0 1 1 0-31"/>
    </svg>
    <div class="pf-ring-lbl">
      <b class="num"><?= (int) $progress ?>%</b>
      <span class="num"><?= intf($done) ?>/<?= intf($tot) ?></span>
    </div>
  </div>
</section>

<?php /* ⚠️ **كروت الأرقام الأربعة** — من مكوّن `stat` بلغة النظام، وكل واحد
         بيفتح نافذة تفاصيله (المكوّن نفسه بيرفض الرندر بلا `drill`). */ ?>
<div class="pf-tiles">
  <?php partial('stat', [
    'label' => 'العهدة للسواقين', 'value' => money($totals['custody'], false),
    'icon' => 'banknote', 'tone' => 'green',
    'drill' => '#phCustody', 'title' => 'عهدة بوالص الإذن',
  ]); ?>
  <?php partial('stat', [
    'label' => 'اتصرف فعلاً', 'value' => money($totals['expense'], false),
    'icon' => 'trending-down', 'tone' => 'red',
    'drill' => '#phExpense', 'title' => 'المصروف على الإذن',
  ]); ?>
  <?php partial('stat', [
    'label' => 'هيتحاسب عليه العميل', 'value' => money($totals['charged'] ?? 0, false),
    'icon' => 'coins', 'tone' => 'gold',
    'drill' => '#phCharged', 'title' => 'اللي هيتحاسب بيه العميل',
  ]); ?>
  <?php /* ⚠️ الاتجاه في **التسمية** والرقم مطلق: علامة الناقص في RTL بتتنقل
           لآخر الرقم فتتقرا شرطة تايهة (مصيدة موثَّقة). */ ?>
  <?php partial('stat', [
    'label' => 'الفرق — ' . ($heroMargin < 0 ? 'نقص عن المصروف'
                           : ($heroMargin > 0 ? 'زيادة عن المصروف' : 'متعادل مع المصروف')),
    'value' => money(abs($heroMargin), false),
    'icon' => $heroMargin < 0 ? 'trending-down' : 'trending-up',
    'tone' => $heroMargin < 0 ? 'red' : 'green',
    'drill' => '#phMargin', 'title' => 'الفرق بين اللي هيتحاسب واللي اتصرف',
  ]); ?>
</div>

<?php /* ═══ أجسام نوافذ التفاصيل — جاهزة في الصفحة ومخفيّة، والنافذة العامة
         بتنسخ محتواها (نفس نمط `custody/index.php`). محطوطة هنا جنب
         البلاطات عشان أي تعديل في رقم يشوف نافذته في نفس الملف. ═══ */ ?>

<div id="phCustody" hidden>
  <?php partial('drill', [
    'how'   => 'العهدة اللي المكتب طلّعها للسواقين على بوالص الإذن ده.'
             . (($totals['debt'] != 0 || $totals['remaining'] != 0)
                ? ' دين البوالص ' . money($totals['debt'], false) . ' · المتبقي ' . money($totals['remaining'], false) . '.'
                : ''),
    'count' => count($heroBills),
    'total' => ['label' => 'إجمالي العهدة', 'value' => money($totals['custody'], false)],
    'empty' => ['title' => 'مفيش بوالص على الإذن ده'],
    'body'  => function () use ($heroBills) {
      foreach ($heroBills as $b) {
        $rem = (float) $b['remaining'];
        partial('row_item', [
          'title' => ($b['bill_number'] ?? '') !== '' ? $b['bill_number'] : '#' . (int) $b['id'],
          'url'   => url('bawalis/' . (int) $b['id']),
          'icon'  => 'truck',
          'meta'  => array_values(array_filter([
            ['icon' => 'calendar', 'text' => dfmt($b['bill_date'])],
            ($b['driver'] ?? '') !== '' ? ['icon' => 'user', 'text' => $b['driver']] : null,
            ($b['car_no'] ?? '') !== '' ? ['icon' => 'truck', 'text' => $b['car_no']] : null,
            (float) $b['debt'] != 0 ? ['icon' => 'credit-card', 'text' => 'دين ' . money((float) $b['debt'], false)] : null,
          ])),
          'amount' => money((float) $b['custody'], false), 'amount_tone' => 'rc',
          'sub'    => $rem != 0 ? 'متبقي ' . money($rem, false) : null,
        ]);
      }
    },
  ]); ?>
</div>

<div id="phExpense" hidden>
  <?php partial('drill', [
    'how'   => 'كل بند اتدفع فعلاً على الإذن — إيصالات ورسوم ومصروفات إضافية.',
    'count' => count($heroExpenseRows),
    'total' => ['label' => 'إجمالي المصروف', 'value' => money($totals['expense'], false)],
    'empty' => ['title' => 'لسه مفيش بنود مصروفة'],
    'more'  => ['label' => 'سجل المصروفات', 'url' => url('expenses')],
    'body'  => function () use ($heroExpenseRows, $heroMoneyRow) {
      foreach ($heroExpenseRows as $r) {
        $actual = ($r['actual_amount'] !== null && $r['actual_amount'] !== '') ? (float) $r['actual_amount'] : null;
        $heroMoneyRow($r, money((float) $r['amount'], false), 'ex', [
          $actual !== null
            ? ['icon' => 'coins', 'text' => 'هيتحاسب ' . money($actual, false)]
            : ['icon' => 'alert', 'text' => 'بلا سعر للعميل'],
        ]);
      }
    },
  ]); ?>
</div>

<div id="phCharged" hidden>
  <?php partial('drill', [
    'how'   => 'البنود اللي المكتب حاططلها سعر فعلي للعميل — دي اللي بتتنقل للفاتورة.',
    'count' => count($heroChargedRows),
    'total' => ['label' => 'إجمالي اللي هيتحاسب', 'value' => money($totals['charged'] ?? 0, false)],
    'empty' => ['title' => 'لسه مفيش بند متسعّر للعميل'],
    'more'  => ['label' => 'إصدار فاتورة', 'url' => url('permits/' . $pid . '/invoice')],
    'body'  => function () use ($heroChargedRows, $heroMoneyRow) {
      foreach ($heroChargedRows as $r) {
        $heroMoneyRow($r, money((float) $r['actual_amount'], false), 'rc', [
          !empty($r['is_expense']) ? ['icon' => 'trending-down', 'text' => 'اتصرف ' . money((float) $r['amount'], false)] : null,
        ]);
      }
    },
  ]); ?>
</div>

<div id="phMargin" hidden>
  <?php partial('drill', [
    'how'   => 'الفرق = اللي هيتحاسب عليه العميل ناقص اللي اتصرف فعلاً. السالب معناه بنحاسب بأقل مما دفعنا.',
    'count' => count($heroMarginRows),
    'total' => ['label' => 'صافي الفرق — ' . ($heroMargin < 0 ? 'نقص' : ($heroMargin > 0 ? 'زيادة' : 'متعادل')),
                'value' => money(abs($heroMargin), false)],
    'empty' => ['title' => 'كل بند بيتحاسب بنفس اللي اتصرف'],
    'body'  => function () use ($heroMarginRows, $heroRowMargin, $heroMoneyRow) {
      foreach ($heroMarginRows as $r) {
        $d = $heroRowMargin($r);
        $actual = ($r['actual_amount'] !== null && $r['actual_amount'] !== '') ? (float) $r['actual_amount'] : null;
        /* ⚠️ **الإشارة بكلمة مش بعلامة.** `money(-10)` بتطبع «-10.00»، وفي سياق
           عربي RTL علامة الناقص بتتنقل لآخر الرقم فيبان «10.00-» — شكله رقم
           موجب وراه شرطة. الكلمة قبل الرقم بتتقرا صح في الاتجاهين. */
        $heroMoneyRow($r, ($d < 0 ? 'نقص ' : 'زيادة ') . money(abs($d), false), $d < 0 ? 'ex' : 'rc', array_values(array_filter([
          !empty($r['is_expense']) ? ['icon' => 'trending-down', 'text' => 'اتصرف ' . money((float) $r['amount'], false)] : null,
          $actual !== null
            ? ['icon' => 'coins', 'text' => 'هيتحاسب ' . money($actual, false)]
            : ['icon' => 'alert', 'text' => 'بلا سعر للعميل'],
        ])));
      }
    },
  ]); ?>
</div>
