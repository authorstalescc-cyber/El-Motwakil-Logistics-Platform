<?php
/**
 * تقرير التشغيل — مستند A4 للطباعة أو الحفظ PDF.
 *
 * ⚠️ **الطباعة دايمًا فاتحة** (المرحلة ١٥) — الوضع الليلي بياكل الحبر.
 * ⚠️ توكنز الهوية من `partials/print_base.php` (مصدر واحد لكل مستندات A4).
 * ⚠️ **مفيش نولون في المستند ده عن قصد** — قرار المالك في الوحدة كلها.
 *
 * ⚠️ **التقرير مش مضغوط في صفحة** (قرار المالك 2026-08-16): «مش لازم تكون في
 *    صفحة واحدة — أهم شيء تعرض كافة المعلومات الصحيح بتنسيق جميل». فالمستند
 *    بيمتدّ على قد ما يحتاج، والشغل كله راح في **إن كل صفحة تقرا لوحدها**:
 *    رؤوس الأعمدة بتتكرر · التذييل ما بيتكررش · العنوان ما ينفصلش عن جدوله.
 *
 * ⚠️⚠️ **تلات مصايد طباعة اتصادت في PDF مطبوع فعلًا (مش بالقراءة):**
 *    ① `tfoot` الافتراضي `table-footer-group` — المتصفح بيكرّره في آخر **كل**
 *       صفحة **بالإجمالي الكامل**، فصفحة فيها ١٨ صف كانت بتقفل بإجمالي الـ٨٥.
 *    ② جدول البوالص المفصّل **١٢ عمودًا ما بيسعوش في A4 طولي** — كان
 *       بيتقصّ من غير أي تحذير، فعمود العهدة بيطلع «٥٠٠,» بدل «١٤٬٥٠٠».
 *       الحل: `@page` مسمّاة **عرضية** للقسم ده وحده.
 *    ③ `position:fixed` هو الطريقة الوحيدة لتذييل بيتكرر في كل صفحة —
 *       و**`bottom:0` لازم مع هامش سفلي في `@page`**، مع `bottom` سالب
 *       التذييل بيختفي من الصفحة الأولى بس (اتجرّب).
 *
 * ⚠️ **حدّ معروف في كروم — مش باج عندنا:** التذييل الجاري **بيسقط من أول
 *    صفحة عرضية** (أول صفحة في سياق `@page` مسمّى جديد) وبيرجع في اللي بعدها.
 *    اتأكّد بصفحة اختبار معزولة بلا أي كود من المشروع، **وبوجود `break-before`
 *    ومن غيره** — النتيجة واحدة. الصفحة دي مش سايبة القارئ تايه: عنوان القسم
 *    نفسه فيها، ورؤوس الأعمدة بتتكرر في اللي بعدها.
 *
 * `?detail=1` بيضيف جدول كل بوليصة (بيطلع عرضي).
 *
 * @var array      $period    مخرَج `OpsReport::period()`
 * @var array      $totals    أرقام الفترة
 * @var array|null $prev      أرقام الفترة السابقة (null في «كل الفترات»)
 * @var array      $rates     مخرَج `OpsReport::rates()`
 * @var array      $dims      [مفتاح => ['label','rows']] — مولَّدة من `DIMS`
 * @var array      $mix       تصنيف الحاويات
 * @var array      $permits   الأذونات اللي اتحرّك عليها
 * @var array      $attention مخرَج `OpsReport::attention()`
 * @var int        $undated   بوالص بلا تاريخ (مستبعدة من كل الأرقام)
 * @var bool       $detail    نسخة مفصّلة؟
 * @var array      $trips     كل البوالص (لو مفصّلة)
 */
use App\Core\App;
use App\Models\OpsReport;

$co  = (string) App::setting('company_name', 'شركة المتوكل');
$act = (string) App::setting('company_activity', 'النقل والتخليص الجمركي');

$logoRel = null;
foreach (['assets/img/logo.png', 'assets/img/logo.jpg', 'assets/img/logo.jpeg'] as $cand) {
    if (is_file(App::basePath('public/' . $cand))) { $logoRel = $cand; break; }
}

/**
 * ⚠️ **الفرق بين الفترتين بيتحسب في `OpsReport::delta()` مش هنا** — هي
 *    المصدر الوحيد (وبترجّع `null` لما المقام صفر بدل ما تقسم على صفر).
 *    كتابته بالإيد كانت هتدّي «+∞٪» أو قسمة على صفر في أول فترة فاضية.
 */
$deltaOf = static function (string $key) use ($totals, $prev): ?float {
    if ($prev === null) { return null; }
    return OpsReport::delta((float) $totals[$key], (float) $prev[$key]);
};

/** نسبة مئوية آمنة — المقام صفر بيدّي صفر لا قسمة على صفر. */
$pct = static fn (float $part, float $whole): float => $whole > 0 ? $part / $whole * 100 : 0.0;

/** ترقيم الأقسام — عدّاد واحد فمستحيل رقمان يتكرروا لو قسم اتنقل. */
$secNo = 0;
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<?php partial('print_base'); ?>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:var(--bg);color:var(--ink);font-family:var(--font);font-size:11.5px;line-height:1.5;
       direction:rtl;text-align:right;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .sheet{max-width:210mm;margin:14px auto;background:#fff;padding:16mm 13mm 18mm;
         border:1px solid var(--line);position:relative}
  .wm{position:absolute;inset:0;display:flex;align-items:flex-start;justify-content:center;
      padding-top:70mm;opacity:.04;pointer-events:none;z-index:0}
  .wm img{width:58%}
  .content{position:relative;z-index:1}

  /* ═══ ترويسة المستند ═══ */
  .hd{display:flex;align-items:center;gap:15px;border-bottom:3px solid var(--navy);padding-bottom:12px}
  .hd img{height:64px}
  .hd .co{font-size:16px;font-weight:700;color:var(--navy)}
  .hd .ac{font-size:10.5px;color:var(--muted);margin-top:2px}
  .hd .rt{margin-inline-start:auto;text-align:end}
  .hd .rt .t{font-size:17px;font-weight:700;color:var(--gold)}
  .hd .rt .d{font-size:10px;color:var(--muted);margin-top:3px}

  /* ═══ شريط تعريف الفترة ═══ */
  .idbox{display:flex;flex-wrap:wrap;gap:0;background:var(--bg);border:1px solid var(--line);
         border-radius:6px;margin:12px 0 14px;overflow:hidden}
  .idbox div{flex:1;min-width:88px;padding:8px 11px;border-inline-start:1px solid var(--line)}
  .idbox div:first-child{border-inline-start:0}
  .idbox em{font-style:normal;color:var(--muted);display:block;font-size:9px}
  .idbox b{font-size:12px;font-variant-numeric:tabular-nums}

  /* ═══ أرقام الفترة ═══ */
  .nums{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}
  .num{border:1px solid var(--line);border-top:3px solid var(--navy);border-radius:6px;
       padding:9px 8px;text-align:center}
  .num em{font-style:normal;display:block;color:var(--muted);font-size:9px;margin-bottom:3px}
  .num b{font-size:17px;color:var(--navy);font-variant-numeric:tabular-nums;display:block}
  .num.hi{border-top-color:var(--gold);background:var(--gold-l)}
  .num.hi b{color:var(--gold-d)}
  /* ⚠️ سهم الفرق **بلون معناه لا بإشارته**: زيادة الحاويات خير وزيادة العهد
     مش بالضرورة، فالتقرير بيقول الاتجاه ويسيب الحكم للقارئ — لون واحد محايد. */
  .num i{font-style:normal;display:block;font-size:8.5px;color:var(--muted);margin-top:3px;
         font-variant-numeric:tabular-nums}

  /* ═══ المعدلات ═══ */
  .rates{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:9px}
  .rate{border:1px solid var(--line);border-radius:6px;padding:7px 10px;background:var(--bg)}
  .rate em{font-style:normal;color:var(--muted);font-size:9px;display:block}
  .rate b{font-size:13px;color:var(--navy);font-variant-numeric:tabular-nums}
  .rate span{color:var(--muted);font-size:9px}

  /* ═══ تعريف الأرقام ═══ */
  .legend{border:1px solid var(--line);border-inline-start:4px solid var(--gold);border-radius:6px;
          padding:9px 12px;margin-top:12px;font-size:10px;color:var(--muted);line-height:1.75}
  .legend b{color:var(--ink)}

  /* ═══ الأقسام ═══ */
  /* ⚠️ العنوان لازم يفضل ملزوقًا بجدوله — `break-after:avoid` بيمنع «عنوان
     في آخر صفحة وجدوله في اللي بعدها»، وهي أكتر حاجة بتوحش تقريرًا مطبوعًا. */
  h2{font-size:13px;color:var(--navy);margin:16px 0 0;padding:7px 11px;background:var(--bg);
     border:1px solid var(--line);border-top:3px solid var(--navy);border-radius:6px 6px 0 0;
     display:flex;align-items:baseline;gap:8px;break-after:avoid;page-break-after:avoid;
     break-inside:avoid}
  /* ⚠️ رقم القسم **شارة دايرية بلا نقطة**: «1.» جوّه نص عربي بيتقلب بصريًّا
     لـ«.1» بقواعد الاتجاه الثنائي ويقرا كأنه غلط مطبعي. الشارة بتشيل المشكلة
     من أصلها بدل ما نحارب الـbidi بمحارف تحكّم. */
  h2 .no{background:var(--navy);color:#fff;border-radius:50%;width:18px;height:18px;flex:none;
    display:inline-flex;align-items:center;justify-content:center;font-size:10px;
    font-variant-numeric:tabular-nums}
  h2 .cnt{margin-inline-start:auto;color:var(--muted);font-size:9.5px;font-weight:400;
          font-variant-numeric:tabular-nums}
  .none{border:1px solid var(--line);border-top:0;padding:14px;color:var(--muted);font-size:10.5px;
        text-align:center}

  /* ═══ الجداول ═══ */
  table{width:100%;border-collapse:collapse;font-size:10.5px}
  th,td{border:1px solid var(--line);padding:5px 7px;text-align:start;vertical-align:middle}
  th{background:var(--navy);color:#fff;font-weight:500;font-size:9.5px}
  tbody tr:nth-child(even) td{background:var(--bg)}
  tfoot td{background:var(--gold-l);font-weight:700}
  .n{text-align:end;white-space:nowrap;font-variant-numeric:tabular-nums}
  .nowrap{white-space:nowrap}
  /* ⚠️ رؤوس الأعمدة بتتكرر في كل صفحة، والتذييل **لا** — الافتراضي بيكرّر
     التذييل بالإجمالي الكامل تحت صفحة فيها جزء من الصفوف (مصيدة ①). */
  thead{display:table-header-group}
  tfoot{display:table-row-group}
  tr{break-inside:avoid;page-break-inside:avoid}

  /* شريط الحصّة — الرقم لوحده ما بيقولش «ده الأكبر ولا التالت». */
  .share{width:78px;padding-inline:6px}
  .bar{display:block;height:7px;background:var(--line);border-radius:4px;overflow:hidden}
  .bar i{display:block;height:100%;background:var(--navy);border-radius:4px}

  /* ═══ محتاج انتباهك ═══ */
  .gaps{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:9px}
  .gap{border:1px solid var(--line);border-radius:6px;padding:8px;text-align:center}
  .gap em{font-style:normal;display:block;color:var(--muted);font-size:9px;margin-bottom:2px}
  .gap b{font-size:14px;font-variant-numeric:tabular-nums;color:var(--navy)}
  .gap.on{border-color:var(--sader)} .gap.on b{color:var(--sader)}

  /* ═══ القسم العرضي (جدول البوالص) ═══ */
  .land{break-before:page;page-break-before:always}

  /* ═══ التذييل الجاري ═══ */
  .rf{position:fixed;bottom:0;inset-inline:0;display:flex;justify-content:space-between;gap:12px;
      border-top:1px solid var(--line);padding-top:4px;font-size:8.5px;color:var(--muted);background:#fff}

  .noprint{text-align:center;margin:14px auto;max-width:210mm;display:flex;gap:9px;justify-content:center}
  .noprint a,.noprint button{background:var(--navy);color:#fff;border:1px solid var(--navy);padding:9px 20px;
    border-radius:6px;cursor:pointer;font-size:12px;font-family:inherit;text-decoration:none}
  .noprint a.g{background:#fff;color:var(--navy)}

  @media print{
    body{background:#fff}
    .sheet{border:0;max-width:none;margin:0;padding:0}
    .noprint,.rf{display:none}
    /* ⚠️ الهامش السفلي ١٤مم بيسيب مكانًا للتذييل الجاري — من غيره بيركب على
       آخر صف في الجدول. والقسم العرضي بصفحته المسمّاة (مصيدة ②). */
    @page{size:A4 portrait;margin:12mm 11mm 14mm}
    @page land{size:A4 landscape;margin:10mm 11mm 12mm}
    .land{page:land}
    .rf{display:flex}
    .wm{display:none}
  }
  /* ⚠️⚠️ **`screen` إجبارية هنا**: عرض صفحة A4 بعد الهوامش = **~718px**، يعني
     أقل من نقطة الكسر — فاستعلام بلا `screen` **بيشتغل وقت الطباعة** وبيرجّع
     كروت الأرقام لعمودين على الورق. اتصاد في PDF مطبوع: الخمس كروت طلعت
     ٢+٢+١ بدل صف واحد. أي نقطة كسر في مستند A4 لازم تتقيّد بـ`screen`. */
  @media screen and (max-width:760px){ .nums{grid-template-columns:repeat(2,1fr)}
    .rates,.gaps{grid-template-columns:repeat(2,1fr)} }
</style>
</head>
<body>

<div class="noprint">
  <button type="button" onclick="window.print()">🖨️ اطبع أو احفظ PDF</button>
  <?php /* ⚠️ رابط تنقّل مش زر «رجوع» — الصفحة بتتفتح في تبويب جديد فمفيش
           «ورا» أصلاً (`verify_navigation` بيعدّ أزرار الرجوع اليدوية). */ ?>
  <a class="g" href="<?= url('ops') ?>">تقارير التشغيل</a>
</div>

<?php /* ⚠️ **التذييل الجاري هو التذييل الوحيد** — كانت فيه كمان كتلة ساكنة
         في آخر المستند بنفس الكلام، فالصفحة الأخيرة كانت بتطلع بتذييلين فوق
         بعض. تاريخ الطبع اتنقل هنا عشان يبان في كل صفحة لا في الأخيرة بس. */ ?>
<div class="rf">
  <span><?= e($co) ?> — تقرير التشغيل · <?= e($period['label']) ?></span>
  <span>اتطبع <?= e(dfmt(date('Y-m-d'))) ?> · الأرقام بالجنيه المصري · النولون مش داخل في التقرير ده</span>
</div>

<div class="sheet">
  <?php if ($logoRel): ?><div class="wm"><img src="<?= e(url($logoRel)) ?>" alt=""></div><?php endif; ?>
  <div class="content">

    <div class="hd">
      <?php if ($logoRel): ?><img src="<?= e(url($logoRel)) ?>" alt=""><?php endif; ?>
      <div>
        <div class="co"><?= e($co) ?></div>
        <div class="ac"><?= e($act) ?></div>
      </div>
      <div class="rt">
        <div class="t">تقرير التشغيل</div>
        <div class="d"><?= e($period['label']) ?><br>
          اتطبع <?= e(dfmt(date('Y-m-d'))) ?> · <?= $detail ? 'نسخة مفصّلة' : 'نسخة ملخّصة' ?></div>
      </div>
    </div>

    <div class="idbox">
      <?php if ($period['from'] !== null): ?>
        <div><em>من</em><b><?= e(dfmt($period['from'])) ?></b></div>
      <?php endif; ?>
      <?php if ($period['to'] !== null): ?>
        <div><em>إلى</em><b><?= e(dfmt($period['to'])) ?></b></div>
      <?php endif; ?>
      <div><em>أيام فيها شغل</em><b><?= e(intf((int) $rates['work_days'])) ?></b></div>
      <div><em>موانئ</em><b><?= e(intf($totals['ports'])) ?></b></div>
      <div><em>شركات</em><b><?= e(intf($totals['companies'])) ?></b></div>
      <?php if ($prev !== null): ?>
        <div><em>مقارنة بـ</em><b><?= e($period['prev_label']) ?></b></div>
      <?php endif; ?>
    </div>

    <?php
      /* ⚠️ الكروت بتتولّد من مصفوفة واحدة عشان سطر الفرق ما يتنسيش في واحد
         منهم — نسخ الكارت ٥ مرات كان بيخلّي رابعه بلا مقارنة بالسكوت. */
      $CARDS = [
        ['containers', 'حاويات اتداولت', true,  false],
        ['trips',      'بوالص',          false, false],
        ['permits',    'أذونات وشهادات', false, false],
        ['custody',    'عهد اتدفعت',     true,  true],
        ['deducted',   'المخصوم من الخزنة', false, true],
      ];
    ?>
    <div class="nums">
      <?php foreach ($CARDS as [$k, $lbl, $hi, $isMoney]): ?>
        <?php $d = $deltaOf($k); ?>
        <div class="num<?= $hi ? ' hi' : '' ?>">
          <em><?= e($lbl) ?></em>
          <b><?= $isMoney ? e(money0((float) $totals[$k], false)) : e(intf((int) $totals[$k])) ?></b>
          <?php if ($prev !== null): ?>
            <?php
              /* ⚠️ **الرقم السابق نفسه بيتكتب دايمًا**، والنسبة بتتزوّد لو
                 المقام مش صفر. «مفيش رقم للمقارنة» لوحدها كانت بتتكرر في
                 الخمس كروت وما بتقولش حاجة — والقارئ عايز يعرف «قبلها كام». */
              $was = $isMoney ? money0((float) $prev[$k], false) : intf((int) $prev[$k]);
            ?>
            <i><?php if ($d !== null): ?><?= $d >= 0 ? '▲' : '▼' ?>
              <?= e(number_format(abs($d), 1)) ?>٪ · <?php endif; ?>قبلها <?= e($was) ?></i>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <?php
      /* ⚠️ **أي معدل مقامه صفر بيرجع `null` من الموديل** — الورقة بتكتب «—»
         بدل ما تخترع صفرًا («مفيش بيانات» غير «صفر»، قاعدة موثَّقة). */
      $RATES = [
        ['متوسط الحاويات في اليوم',  $rates['per_day'],       'حاوية/يوم'],
        ['متوسط الحاويات في البوليصة', $rates['per_trip'],    'حاوية/بوليصة'],
        ['متوسط الحاويات في الإذن',  $rates['per_permit'],    'حاوية/إذن'],
        ['متوسط العهدة للحاوية',     $rates['per_container'], 'جنيه/حاوية'],
      ];
    ?>
    <div class="rates">
      <?php foreach ($RATES as [$lbl, $val, $unit]): ?>
        <div class="rate"><em><?= e($lbl) ?></em>
          <b><?= $val === null ? '—' : e(number_format((float) $val, 2)) ?></b>
          <span><?= e($unit) ?></span></div>
      <?php endforeach; ?>
      <div class="rate"><em>أنشط يوم</em>
        <b><?= $rates['busiest'] ? e(dfmt((string) $rates['busiest']['date'])) : '—' ?></b>
        <span><?= $rates['busiest']
            ? e(intf((int) $rates['busiest']['containers'])) . ' حاوية · '
              . e(intf((int) $rates['busiest']['trips'])) . ' بوليصة'
            : 'مفيش شغل في الفترة' ?></span></div>
      <div class="rate"><em>بوالص بلا تاريخ</em>
        <b><?= e(intf($undated)) ?></b>
        <span><?= $undated > 0 ? 'مستبعدة من كل الأرقام' : 'مفيش' ?></span></div>
    </div>

    <div class="legend">
      <b>«عهد اتدفعت»</b> = الفلوس اللي خرجت من إيد الشركة للسواقين كاملة ·
      <b>«المخصوم من الخزنة»</b> أقل منها لأن كل بوليصة بيتطرح منها ١٠٠
      (والسيارة المستثناة بتتخصم كاملة) — نفس حساب رصيد خزنة المكتب.<br>
      <b>النولون مش داخل في التقرير ده</b>، والبوالص المُرحَّلة من الأرشيف
      والبوالص بلا تاريخ مستبعدة من كل رقم فوق.
    </div>

    <?php
    /**
     * جدول تقسيم — **نسخة واحدة لكل الأبعاد** عشان الأعمدة ما تفترقش بينهم،
     * وعشان تذييل الإجمالي يبقى موجود في كلهم (كان ناقص في الكل ما عدا
     * «تصنيف الحاويات»، فالقارئ ما كانش يقدر يتأكد إن التقسيم مكتمل).
     */
    $dimTable = static function (string $head, array $rows, callable $pct) use (&$secNo): void {
        $secNo++;
        $sumC = array_sum(array_column($rows, 'containers'));
        ?>
        <h2><span class="no"><?= e(intf($secNo)) ?></span> <span><?= e($head) ?></span>
          <span class="cnt"><?= e(intf(count($rows))) ?> صف</span></h2>
        <?php if (!$rows): ?>
          <div class="none">مفيش حركة في الفترة دي</div>
        <?php else: ?>
          <table>
            <thead><tr>
              <?php /* ⚠️ عنوان العمود = **اسم البُعد نفسه** («الموانئ»/«السواقين»).
                       كلمة عامة زي «بند» كانت بتخلّي كل الجداول تبان متطابقة،
                       فالقارئ يتوه بين تسع جداول رؤوسها واحدة. */ ?>
              <th><?= e($head) ?></th>
              <th class="n">حاويات</th>
              <th class="share">الحصّة</th>
              <th class="n">٪</th>
              <th class="n">بوالص</th>
              <th class="n">أذونات</th>
              <th class="n">عهد اتدفعت</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $share = $pct((float) $r['containers'], (float) $sumC); ?>
              <tr>
                <td><?= e($r['name']) ?></td>
                <td class="n"><?= e(intf($r['containers'])) ?></td>
                <td class="share"><span class="bar"><i style="width:<?= round($share, 1) ?>%"></i></span></td>
                <td class="n"><?= e(number_format($share, 1)) ?>٪</td>
                <td class="n"><?= e(intf($r['trips'])) ?></td>
                <td class="n"><?= e(intf($r['permits'])) ?></td>
                <td class="n"><?= e(money0((float) $r['custody'], false)) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
            <?php /* ⚠️ **مجموع العمود مش `$totals`**: بوليصة واحدة ممكن تدخل في
                     صفّين لو القيمة اتغيّرت، والأهم إن `permits` في الإجمالي
                     `COUNT(DISTINCT)` على الفترة كلها فجمع أعمدة التقسيم
                     بيطلع أكبر منه. فالتذييل بيجمع **الصفوف المعروضة** وبس،
                     ودي الحقيقة الوحيدة اللي القارئ يقدر يتأكد منها بنفسه. */ ?>
            <tfoot><tr>
              <td>الإجمالي (<?= e(intf(count($rows))) ?>)</td>
              <td class="n"><?= e(intf($sumC)) ?></td>
              <td class="share"></td>
              <td class="n">100.0٪</td>
              <td class="n"><?= e(intf(array_sum(array_column($rows, 'trips')))) ?></td>
              <td class="n">—</td>
              <td class="n"><?= e(money0(array_sum(array_column($rows, 'custody')), false)) ?></td>
            </tr></tfoot>
          </table>
        <?php endif;
    };
    ?>

    <?php /* ⚠️ **التوزيعات بتتلفّ على `$dims` الجاية من `DIMS`** — مكتوبة
             بالإيد كانت بتطبع ٥ من ٩ وتخفي الباقي بالسكوت. */ ?>
    <?php foreach ($dims as $d): ?>
      <?php $dimTable($d['label'], $d['rows'], $pct); ?>
    <?php endforeach; ?>

    <?php $secNo++; ?>
    <h2><span class="no"><?= e(intf($secNo)) ?></span> <span>تصنيف الحاويات</span>
      <span class="cnt"><?= e(intf(count($mix))) ?> تصنيف</span></h2>
    <?php if (!$mix): ?>
      <div class="none">مفيش حاويات في الفترة دي</div>
    <?php else: ?>
      <table>
        <thead><tr>
          <th>التصنيف</th><th class="n">عدد الحاويات</th>
          <th class="share">الحصّة</th><th class="n">٪</th><th class="n">بوالص</th>
        </tr></thead>
        <tbody>
        <?php foreach ($mix as $m): $share = $pct((float) $m['containers'], (float) $totals['containers']); ?>
          <tr>
            <td><?= e($m['name']) ?></td>
            <td class="n"><?= e(intf($m['containers'])) ?></td>
            <td class="share"><span class="bar"><i style="width:<?= round($share, 1) ?>%"></i></span></td>
            <td class="n"><?= e(number_format($share, 1)) ?>٪</td>
            <td class="n"><?= e(intf($m['trips'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr>
          <td>الإجمالي</td><td class="n"><?= e(intf($totals['containers'])) ?></td>
          <td class="share"></td><td class="n">100.0٪</td>
          <td class="n"><?= e(intf($totals['trips'])) ?></td>
        </tr></tfoot>
      </table>
    <?php endif; ?>

    <?php $secNo++; ?>
    <h2><span class="no"><?= e(intf($secNo)) ?></span> <span>الأذونات والشهادات اللي اتحرّك عليها</span>
      <span class="cnt"><?= e(intf(count($permits))) ?> إذن</span></h2>
    <?php if (!$permits): ?>
      <div class="none">مفيش أذونات اتحرّك عليها في الفترة دي</div>
    <?php else: ?>
      <table>
        <thead><tr>
          <th class="nowrap">الإذن / الشهادة</th><th>العميل</th><th>الشركة</th><th>الميناء</th>
          <th class="n">اتحرّك</th><th class="n">إجمالي الإذن</th><th class="n">باقي</th>
          <th class="n">بوالص</th><th class="n">عهد</th>
        </tr></thead>
        <tbody>
        <?php foreach ($permits as $p):
          $moved = (int) $p['containers'];
          $all   = (int) $p['permit_containers'];
          /* ⚠️ **«الباقي» بيتحسب من عمود الإذن الكامل** — والإذن اللي مالوش
             عدد مسجَّل بيدّي «—» بدل صفر كاذب. */
          $left  = $all > 0 ? max(0, $all - $moved) : null;
        ?>
          <tr>
            <td class="nowrap"><?= e(($p['permit_type'] === 'warid' ? 'شهادة ' : 'إذن ')
                . ($p['permit_no'] ?: '#' . (int) $p['id'])) ?></td>
            <td><?= e((string) ($p['client'] ?? '')) ?></td>
            <td><?= e((string) ($p['company'] ?? '')) ?></td>
            <td><?= e((string) ($p['port'] ?? '')) ?></td>
            <td class="n"><?= e(intf($moved)) ?></td>
            <td class="n"><?= $all > 0 ? e(intf($all)) : '—' ?></td>
            <td class="n"><?= $left === null ? '—' : e(intf($left)) ?></td>
            <td class="n"><?= e(intf((int) $p['trips'])) ?></td>
            <td class="n"><?= e(money0((float) $p['custody'], false)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <?php $secNo++; ?>
    <?php /* ⚠️ **القسم ده بيقول حاجة مش معروضة في أي رقم فوق** (قاعدة
             `OpsReport::attention`): أذونات لسه فيها حاويات ما اتحركتش،
             وبيانات ناقصة بتخلّي التقرير نفسه أقل دقّة. من غيره التقرير
             بيقول «إيه اللي حصل» بس ما بيقولش «إيه اللي محتاج تصرّف». */ ?>
    <h2><span class="no"><?= e(intf($secNo)) ?></span> <span>محتاج انتباهك</span>
      <span class="cnt"><?= e(intf(count($attention['partial']))) ?> إذن لسه مفتوح</span></h2>
    <?php if (!$attention['partial']): ?>
      <div class="none">مفيش أذونات ناقصة حركة في الفترة دي</div>
    <?php else: ?>
      <table>
        <thead><tr>
          <th class="nowrap">الإذن / الشهادة</th><th>العميل</th><th>الشركة</th><th>تاريخه</th>
          <th class="n">اتحرّك</th><th class="n">إجماليه</th><th class="n">لسه باقي</th>
        </tr></thead>
        <tbody>
        <?php foreach ($attention['partial'] as $a): ?>
          <tr>
            <td class="nowrap"><?= e(($a['permit_type'] === 'warid' ? 'شهادة ' : 'إذن ')
                . ($a['permit_no'] ?: '#' . (int) $a['id'])) ?></td>
            <td><?= e((string) ($a['client'] ?? '')) ?></td>
            <td><?= e((string) ($a['company'] ?? '')) ?></td>
            <td class="nowrap"><?= $a['permit_date'] ? e(dfmt((string) $a['permit_date'])) : '—' ?></td>
            <td class="n"><?= e(intf((int) $a['moved'])) ?></td>
            <td class="n"><?= e(intf((int) $a['total'])) ?></td>
            <td class="n"><?= e(intf((int) $a['left'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <?php $g = $attention['gaps']; ?>
    <div class="gaps">
      <div class="gap<?= $g['no_port']   ? ' on' : '' ?>"><em>بوالص بلا ميناء</em><b><?= e(intf($g['no_port'])) ?></b></div>
      <div class="gap<?= $g['no_type']   ? ' on' : '' ?>"><em>بلا تصنيف حاوية</em><b><?= e(intf($g['no_type'])) ?></b></div>
      <div class="gap<?= $g['no_count']  ? ' on' : '' ?>"><em>بلا عدد حاويات</em><b><?= e(intf($g['no_count'])) ?></b></div>
      <div class="gap<?= $g['no_permit'] ? ' on' : '' ?>"><em>بلا إذن مربوط</em><b><?= e(intf($g['no_permit'])) ?></b></div>
    </div>

  </div>
</div>

<?php if ($detail): ?>
  <?php $secNo++; ?>
  <?php /* ⚠️⚠️ **القسم ده عرضي بصفحة `@page` مسمّاة** — ١٢ عمودًا ما بيسعوش
           في A4 طولي، وكان **بيتقصّ بلا أي تحذير** فعمود العهدة يطلع «٥٠٠,»
           بدل «١٤٬٥٠٠». اتصاد في PDF مطبوع، والقصّ ده أخطر من أي تشوّه شكلي
           لأنه بيدّي رقمًا **غلط** مش وحش. */ ?>
  <div class="sheet land">
    <div class="content">
      <h2><span class="no"><?= e(intf($secNo)) ?></span> <span>كل البوالص بالتفصيل</span>
        <span class="cnt"><?= e(intf(count($trips))) ?> بوليصة</span></h2>
      <?php if (!$trips): ?>
        <div class="none">مفيش بوالص في الفترة دي</div>
      <?php else: ?>
        <table>
          <thead><tr>
            <th class="n">#</th><th class="nowrap">التاريخ</th><th>البوليصة</th><th>الإذن</th>
            <th>الميناء</th><th>الشركة</th><th>الوجهة</th><th>السيارة</th><th>السواق</th>
            <th class="n">حاويات</th><th class="nowrap">التصنيف</th><th class="n">عهدة</th>
          </tr></thead>
          <tbody>
          <?php $i = 0; foreach ($trips as $t): $i++; ?>
            <tr>
              <td class="n"><?= e(intf($i)) ?></td>
              <td class="nowrap"><?= e(dfmt($t['bill_date'])) ?></td>
              <td><?= e((string) ($t['bill_number'] ?? '')) ?></td>
              <td><?= e((string) ($t['permit_no'] ?? '')) ?></td>
              <td><?= e((string) ($t['port'] ?? '')) ?></td>
              <td><?= e((string) ($t['company'] ?? '')) ?></td>
              <td><?= e((string) ($t['destination'] ?? '')) ?></td>
              <td class="nowrap"><?= e((string) ($t['car_no'] ?? '')) ?></td>
              <td><?= e((string) ($t['driver'] ?? '')) ?></td>
              <td class="n"><?= e(intf((int) $t['container_count'])) ?></td>
              <td class="nowrap"><?= e((string) (container_norm($t['container_type'] ?? '') ?? '')) ?></td>
              <td class="n"><?= e(money0((float) $t['custody'], false)) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr>
            <td colspan="9">الإجمالي (<?= e(intf(count($trips))) ?> بوليصة)</td>
            <td class="n"><?= e(intf($totals['containers'])) ?></td>
            <td></td>
            <td class="n"><?= e(money0((float) $totals['custody'], false)) ?></td>
          </tr></tfoot>
        </table>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

</body>
</html>
