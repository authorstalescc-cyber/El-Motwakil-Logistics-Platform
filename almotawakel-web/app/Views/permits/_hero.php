<?php
/**
 * لوحة هوية الإذن + دفتر فلوسه — **الريل الجانبي** لشاشة الملف (v104).
 *
 * بلاغ المالك: «التصميم سيئ جدًا جدًا … والهيرو سيكشن يتغيّر برضه».
 *
 * ═══ اللي اتغيّر ولـيه ═══
 * الهيرو القديم كان **شريطًا أفقيًا** كحلي فيه ٦ «بلاطات» متساوية الوزن،
 * وتحته **٤ كروت أرقام** بعرض الشاشة. النتيجة: ٥٩٥px من ارتفاع الشاشة
 * بيتصرفوا قبل أول بند، والعين مالهاش نقطة تبدأ منها (كل حاجة بنفس الحجم).
 *
 * دلوقتي هو **عمود ملاصق** بيفضل قدّام المالك وهو بيقرا البنود:
 *   ① **لوحة المستند**: النوع · الحالة · الرقم كمسلسل مونو كبير · شريط التقدّم.
 *   ② **حقائق الملف**: تسمية | قيمة، سطر لكل حقل — زي خانات المستند الورقي.
 *   ③ **دفتر الفلوس**: أربعة سطور بدل أربع كروت، وكل سطر **زرار** بيفتح بنوده.
 *
 * ⚠️ **«ممنوع رقم أصمّ» محفوظة بالكامل**: كل سطر في الدفتر `data-drill` على
 *    نفس أجسام النوافذ اللي تحت — اللي اتغيّر شكل الرقم مش وصوله لتفاصيله.
 * ⚠️ **الغياب معلومة**: التوكيل الملاحي بيتعرض حتى لو فاضي («غير محدّد»)
 *    لأن غيابه بيوقّف قواعد مرشد الإيصالات — بس بلون مطفي.
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
/* حقائق الملف — **الترتيب بيتبع السؤال**: مين · فين · إيه · إمتى.
   ⚠️ الشركة والوكيل كلمتان متشابهتان لكيانين مختلفين، فالتسمية كاملة. */
$facts = [
    ['building',  'لصالح شركة',   trim((string) ($p['company'] ?? '')),      false],
    ['user',      'الوكيل',        trim((string) ($p['client'] ?? '')),       false],
    ['landmark',  'التوكيل الملاحي', trim((string) ($p['agency'] ?? '')),     true],
    ['anchor',    'الميناء',       trim((string) ($p['port'] ?? '')),         false],
    ['map-pin',   'الجهة',         trim((string) ($p['destination'] ?? '')),  false],
    ['containers','الحاويات',      trim($ctLbl . ($ctLbl !== '' && $carsLbl !== '' ? ' · ' : '') . $carsLbl), false],
    ['calendar',  'التاريخ',       dfmt($p['permit_date']), false],
    /* ⚠️ الأربعة دول كانوا في كارت «تفاصيل إضافية» تحت الطية في آخر الصفحة —
       واتنين منهم (سعر الكشف والضمان) بيحدّدوا الرسوم الأولية المحسوبة
       تلقائيًّا، فمكانهم الطبيعي جنب باقي بيانات الملف. */
    ['ship',      'شركة الشحن',    trim((string) ($p['shipping_co'] ?? '')), false],
    ['search',    'سعر الكشف',     $p['inspection_rate'] !== null ? money($p['inspection_rate'], false) : '', false],
    ['shield',    'سعر الضمان',    $p['guarantee_rate'] !== null ? money($p['guarantee_rate'], false) : '', false],
    ['edit',      'ملاحظة',        trim((string) ($p['note'] ?? '')), false],
];
?>
<aside class="pf-rail">

  <?php /* ① لوحة المستند */ ?>
  <div class="pf-plate">
    <div class="pf-plate-top">
      <span class="pf-kind"><i><?= $th['arrow'] ?></i> <?= $th['full'] ?></span>
      <span class="pf-state<?= $isClosed ? ' done' : '' ?>">
        <?= $isClosed ? icon('check', 12) . ' مقفول' : icon('clock', 12) . ' جارٍ' ?>
      </span>
    </div>
    <span class="pf-eyebrow"><?= e($noLabel) ?></span>
    <b class="pf-serial"><?= e($p['permit_no'] ?: '#' . $pid) ?></b>
    <div class="pf-prog">
      <?php /* ⚠️ `style="width:%"` هو **الاستثناء الوحيد المسموح** للـinline في
               النظام (قيمة محسوبة لشريط تقدّم — نفس `_journey.php` بالحرف)،
               وفاحص الأذونات بيسمح `style="width:` تحديدًا ويرفض أي inline تاني. */ ?>
      <span class="pf-prog-bar"><i style="width:<?= (int) $progress ?>%"></i></span>
      <b><span class="num"><?= intf($done) ?></span> من <span class="num"><?= intf($tot) ?></span> خطوات</b>
    </div>
  </div>

  <?php /* ② حقائق الملف */ ?>
  <div class="pf-card">
    <div class="pf-facts">
      <?php foreach ($facts as [$ic, $lbl, $val, $isKey]): ?>
        <?php if ($val === '' && !$isKey) { continue; } ?>
        <div class="pf-fact<?= $isKey ? ' key' : '' ?><?= $val === '' ? ' blank' : '' ?>">
          <span class="pf-fact-l"><?= icon($ic, 12) ?> <?= e($lbl) ?></span>
          <b><?= $val !== '' ? e($val) : 'غير محدّد' ?></b>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php /* ③ دفتر الفلوس — أربع سطور، كل سطر بيفتح بنوده */ ?>
  <div class="pf-card">
    <div class="pf-card-h"><?= icon('banknote', 14) ?> فلوس الإذن</div>
    <div class="pf-led">
      <button type="button" class="pf-led-r in" data-drill="#phCustody" data-drill-title="عهدة بوالص الإذن">
        <span>العهدة للسواقين</span><b class="num"><?= money($totals['custody'], false) ?></b><?= icon('search', 13) ?>
      </button>
      <button type="button" class="pf-led-r out" data-drill="#phExpense" data-drill-title="المصروف على الإذن">
        <span>اتصرف فعلاً</span><b class="num"><?= money($totals['expense'], false) ?></b><?= icon('search', 13) ?>
      </button>
      <button type="button" class="pf-led-r" data-drill="#phCharged" data-drill-title="اللي هيتحاسب بيه العميل">
        <span>هيتحاسب عليه العميل</span><b class="num"><?= money($totals['charged'] ?? 0, false) ?></b><?= icon('search', 13) ?>
      </button>
      <?php /* ⚠️ الاتجاه في **الكلمة** والرقم مطلق: علامة الناقص في RTL
               بتتنقل لآخر الرقم فتتقرا شرطة تايهة (مصيدة موثَّقة). */ ?>
      <button type="button" class="pf-led-r sum <?= $heroMargin < 0 ? 'neg' : ($heroMargin > 0 ? 'pos' : '') ?>"
              data-drill="#phMargin" data-drill-title="الفرق بين اللي هيتحاسب واللي اتصرف">
        <span><?= $heroMargin < 0 ? 'ناقص عن المصروف' : ($heroMargin > 0 ? 'زيادة عن المصروف' : 'متعادل مع المصروف') ?></span>
        <b class="num"><?= money(abs($heroMargin), false) ?></b><?= icon('search', 13) ?>
      </button>
    </div>
  </div>
</aside>

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
