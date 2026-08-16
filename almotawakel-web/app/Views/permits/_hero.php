<?php
/**
 * هيرو الإذن — **جزء مشترك بين شاشة العرض وشاشة الإدارة** (المرحلة ٣).
 *
 * كان الماركب ده مكرَّر **حرفيًا** في `view.php` و`manage.php` (٣٥ سطر × ٢)،
 * والفرق الوحيد كان مكان حساب `$ctLbl`. أي تعديل في بطاقة واحدة كان لازم
 * يتعمل مرتين، وأول مرة تُنسى بتبقى الشاشتان مختلفتين من غير ما حد ياخد باله.
 *
 * ⚠️ **البلاطات أرقام قابلة للضغط، مش زخرفة.** كانت ٨ `<div class="htile">`
 *    صمّاء — مخالفة صريحة لقاعدة المالك «ممنوع رقم أصمّ» (قانون التصميم ⑤).
 *    بقت ٤ أرقام بمكوّن `stat` اللي **بيرفض يترسم بلا مصدر تفاصيل**، فمستحيل
 *    رقم يرجع أصمّ هنا تاني من غير ما المكوّن نفسه يمنعه.
 *
 * ⚠️ **ليه ٤ مش ٨؟** التلاتة اللي اتشالوا (عدد الحاويات · الحركات · البوالص)
 *    كانوا **مكرَّرين حرفيًّا** في نفس الشاشة: الحاويات في `hero-meta` تحت،
 *    والحركات في عنوان «الحركات (N)»، والبوالص في عدّاد بلوك البوالص.
 *    والاتنين الباقيين (دين البوالص · المتبقي) دفتر البوالص — عاشوا جوّه
 *    نافذة العهدة مع بوالصهم بدل ما يقفوا لوحدهم بلا سياق.
 *
 * المتغيّرات المتوقَّعة من الشاشة المستدعية:
 *   $p $pid $th $noLabel $progress $done $tot $movements $totals
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
<div class="permit-hero card mb t-<?= $th['cls'] ?>">
  <div class="hero-main">
    <?php include __DIR__ . '/_hero_bg.php'; ?>
    <div class="hero-id">
      <span class="type-chip <?= $th['cls'] ?>"><span class="tc-arrow"><?= $th['arrow'] ?></span> <?= $th['full'] ?></span>
      <div class="hero-no"><span><?= e($noLabel) ?></span><h1><?= e($p['permit_no'] ?: '#'.$pid) ?></h1></div>
      <div class="hero-meta">
        <?php if($p['company']): ?><span class="hm-pill"><span class="hm-ic"><?= icon('building',16) ?></span><span class="hm-tx"><small>لصالح شركة</small><b><?= e($p['company']) ?></b></span></span><?php endif; ?>
        <?php if($p['client']): ?><span class="hm-pill"><span class="hm-ic"><?= icon('user',16) ?></span><span class="hm-tx"><small>الوكيل</small><b><?= e($p['client']) ?></b></span></span><?php endif; ?>
        <?php /* ⚠️ **التوكيل الملاحي بارز وبيتعرض دايمًا** (طلب المالك 2026-08-10).
                 كان مدفونًا في «تفاصيل إضافية» تحت الطية رغم إنه بيحدّد قواعد
                 مرشد الإيصالات (`receipt_rules_*_agencies`) وبيتطبع في الفاتورة.
                 وغيابه **معلومة**: من غيره قواعد التوكيل في المرشد ما بتشتغلش،
                 فالحالة الفاضية بتتعرض مطفية بدل ما تختفي. والاسم كامل
                 «التوكيل الملاحي» مش «التوكيل» — عشان ما يتلخبطش بـ«الوكيل»
                 اللي فوقه بالظبط (كلمتان متشابهتان لكيانين مختلفين). */ ?>
        <?php $agency = trim((string)($p['agency'] ?? '')); ?>
        <span class="hm-pill is-key<?= $agency==='' ? ' is-blank' : '' ?>">
          <span class="hm-ic"><?= icon('landmark',16) ?></span>
          <span class="hm-tx"><small>التوكيل الملاحي</small><b><?= $agency!=='' ? e($agency) : 'غير محدّد' ?></b></span>
        </span>
        <?php if($p['destination']): ?><span class="hm-pill"><span class="hm-ic"><?= icon('map-pin',16) ?></span><span class="hm-tx"><small>الجهة</small><b><?= e($p['destination']) ?></b></span></span><?php endif; ?>
        <?php if(!empty($p['port'])): ?><span class="hm-pill"><span class="hm-ic"><?= icon('anchor',16) ?></span><span class="hm-tx"><small>الميناء</small><b><?= e($p['port']) ?></b></span></span><?php endif; ?>
        <span class="hm-pill"><span class="hm-ic"><?= icon('calendar',16) ?></span><span class="hm-tx"><small>التاريخ</small><b><?= dfmt($p['permit_date']) ?: '—' ?></b></span></span>
        <?php /* ⚠️ السيارات جوّه **نفس البلاطة** مش بلاطة جديدة: العدد مشتقّ من
                 الحاويات اللي جنبه بالظبط، وبلاطة زيادة كانت هتزوّد الصف لخمسة
                 وتكسر «سطر واحد + meta واحد». */ ?>
        <?php if($ctLbl!=='' || $carsLbl!==''): ?><span class="hm-pill"><span class="hm-ic"><?= icon('package',16) ?></span><span class="hm-tx"><small>الحاويات (عدد×تصنيف)</small><b><?= e(trim($ctLbl . ($ctLbl!=='' && $carsLbl!=='' ? ' · ' : '') . $carsLbl)) ?></b></span></span><?php endif; ?>
      </div>
    </div>
    <div class="hero-ring">
      <svg viewBox="0 0 36 36">
        <path class="ring-bg" pathLength="100" d="M18 2.5a15.5 15.5 0 1 1 0 31 15.5 15.5 0 1 1 0-31"/>
        <path class="ring-fill" pathLength="100" stroke-dasharray="<?= $progress ?>,100" d="M18 2.5a15.5 15.5 0 1 1 0 31 15.5 15.5 0 1 1 0-31"/>
      </svg>
      <div class="ring-lbl"><b><?= $progress ?>%</b><span><?= $done ?>/<?= $tot ?></span></div>
    </div>
  </div>
  <?php /* ⚠️ الغلاف `.hero-tiles` هو الشبكة (٤←٣←٢←١ أعمدة) وجوّاه كروت
           `stat` بلغة النظام. اتساب بنفس الاسم عن قصد: `verify_mobile`
           بيتأكد إن `.hero-tiles` ليها قاعدة متجاوبة، ولو اتسمّى غيره
           الفحص بيعدّي وهو مش شايف الشبكة الحقيقية. */ ?>
  <div class="hero-tiles">
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
    <?php /* الرقم الوحيد اللي بيقول الإذن ده كسبان ولا خسران — وكان مش
             موجود في الشاشة خالص رغم إن طرفيه الاتنين كانوا معروضين. */ ?>
    <?php /* ⚠️ الاتجاه في **التسمية** والرقم مطلق — نفس سبب صفوف النافذة:
             علامة الناقص بتتنقل لآخر الرقم في RTL فتتقرا شرطة تايهة. */ ?>
    <?php partial('stat', [
      'label' => 'الفرق — ' . ($heroMargin < 0 ? 'نقص عن المصروف'
                             : ($heroMargin > 0 ? 'زيادة عن المصروف' : 'متعادل مع المصروف')),
      'value' => money(abs($heroMargin), false),
      'icon' => $heroMargin < 0 ? 'trending-down' : 'trending-up',
      'tone' => $heroMargin < 0 ? 'red' : 'green',
      'drill' => '#phMargin', 'title' => 'الفرق بين اللي هيتحاسب واللي اتصرف',
    ]); ?>
  </div>
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
