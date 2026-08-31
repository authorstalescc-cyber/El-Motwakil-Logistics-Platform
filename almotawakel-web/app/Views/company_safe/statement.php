<?php
/**
 * كشف حساب خزنة الشركة (v67) — صفحة A4 مستقلّة للطباعة/حفظ PDF.
 *
 * ⚠️ **هوية الطباعة من `partials/print_base.php`** — المصدر الوحيد لتوكنز كل
 *    مستندات A4. ممنوع أي مستند يكتب اسم خط أو لون هوية بإيده
 *    (`bin/verify_typography.php` و`bin/verify_print.php` بيحرسوا ده).
 *
 * ⚠️ **الافتتاحي + صافي الفترة = الختامي** — الرقم بيتحسب في
 *    `CompanySafe::statement()` بنفس تعبير الاتجاه بتاع `balance()`،
 *    فمستحيل الورقة تقول رصيدًا يخالف الشاشة. محروسة بفحص في `verify_v67`.
 *
 * ⚠️ **شكل الكروت قرار مالك صريح**: «أول المدة + الداخل» بيتجمعوا في كارت
 *    واحد اسمه **الإجمالي**، و«خارج» بتتقسم لـ**اتسلّم للمكتب** و**مصروفات
 *    الخزنة** — لأنها كانت بتجمّع خمسة أنواع في رقم واحد ما بيقولش راح فين.
 *    ⚠️ **ولا رقم من الأربعة بيختفي**: أول المدة والداخل بيفضلوا مكتوبين في
 *    سطر الميتا تحت «الإجمالي» — الدمج تجميع مش حذف (اللبس ده وقع فعلاً).
 *
 * ⚠️ **قسم «تفاصيل إضافية» أرصدة لحظية وقت الطباعة** — مش أرقام الفترة، فلازم
 *    الورقة تقول كده صراحةً وإلا القارئ يجمعها على الختامي وهي محفظة تانية.
 *
 * @var array $st       مخرَج `CompanySafe::statement()`
 * @var array $wallets  مخرَج `Closing::walletSplit()` — الدرج والمناديب دلوقتي
 */
use App\Core\App;
use App\Models\CompanySafe;

$company  = App::setting('company_name', 'شركة المتوكل');
$currency = App::setting('currency', 'ج.م');
$labels   = CompanySafe::labels();

$logoRel = null;
foreach (['assets/img/logo.png', 'assets/img/logo.jpg', 'assets/img/logo.jpeg'] as $cand) {
    if (is_file(App::basePath('public/' . $cand))) { $logoRel = $cand; break; }
}
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — <?= e($company) ?></title>
<?php partial('print_base'); ?>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  html,body{background:var(--bg);color:var(--ink);font-family:var(--font);line-height:1.5;
    direction:rtl;text-align:right;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  /* ⚠️ **الهامش الرأسي ١٥مم لا ١٨مم**: الكشف النموذجي (٣ داخل + ٥ خارج) كان
     بيطلع **1144px** والصفحة **1122px** — يعني بيفيض بـ٢٢px فالتذييل لوحده
     بينزل صفحة تانية شبه فاضية. الإيقاع الرأسي اتضبط لحد ما فيه فسحة ~17px،
     والقياس اتاخد من ارتفاع `.sheet` الفعلي مش بالتقدير. */
  .sheet{width:210mm;min-height:297mm;margin:16px auto;background:#fff;position:relative;
    box-shadow:0 10px 40px rgba(21,53,91,.15);overflow:hidden;padding:15mm 14mm}
  .wm{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;opacity:.05;pointer-events:none;z-index:0}
  .wm img{width:60%}
  .content{position:relative;z-index:1}
  .head{display:flex;align-items:center;gap:16px;border-bottom:3px solid var(--navy);padding-bottom:14px}
  .head img{height:70px}
  .head .co{font-size:15pt;font-weight:700;color:var(--navy)}
  .head .sub{color:var(--muted);font-size:9pt;margin-top:2px}
  .doc-title{margin-inline-start:auto;text-align:left}
  .doc-title .t{font-size:13.5pt;font-weight:700;color:var(--gold)}
  .doc-title .d{color:var(--muted);font-size:8.8pt}
  .totals{display:flex;gap:10px;margin:13px 0}
  .tt{flex:1;border:1px solid var(--line);border-radius:6px;padding:11px 12px;text-align:center}
  .tt .l{color:var(--muted);font-size:8.6pt}
  .tt .v{font-weight:700;font-size:12pt;margin-top:2px;font-variant-numeric:tabular-nums}
  .tt.op{background:var(--bg)}
  .tt.cl{background:var(--navy);color:#fff;border-color:var(--navy)}
  .tt.cl .l{color:#cfdcec}
  /* ⚠️ سطر الميتا على السطح الغامق **لازم يقلب لونه** — `--muted` على `--navy`
     بيدّي تباينًا ٢:١ يعني غير مقروء مطبوعًا (قاعدة: اللون له دور تعبئة ودور نص). */
  .tt.cl .n{color:#cfdcec}
  .extra{margin-top:14px;border-top:1px solid var(--line);padding-top:12px;break-inside:avoid}
  .extra h3{font-size:10pt;font-weight:700;color:var(--navy);margin-bottom:8px}
  .extra .totals{margin:0}
  .tt .n{color:var(--muted);font-size:7.8pt;margin-top:3px}
  .kx{border:1px solid var(--line);border-radius:20px;padding:3px 9px;font-size:8pt;background:#fff}
  .kx b{font-weight:700;font-variant-numeric:tabular-nums}
  table{width:100%;border-collapse:collapse;font-size:8.6pt}
  th,td{border:1px solid var(--line);padding:6px 7px;text-align:center}
  th{background:var(--navy);color:#fff;font-weight:500;font-size:8.2pt}
  td.r{text-align:right}
  /* ⚠️ الجدول الطويل بيتقسم على صفحتين — من غير `table-header-group` بتلاقي
     صفحة ٢ أرقامًا بلا عناوين أعمدة، والقارئ ما يعرفش الداخل من الخارج. */
  thead{display:table-header-group}
  /* ⚠️⚠️ **وعكسها في التذييل بالظبط**: المتصفح بيكرّر `tfoot` في آخر **كل**
     صفحة بالإجمالي **الكامل** — فصفحة فيها ١١ صف كانت بتقفل بإجمالي الـ٢٢
     صفًّا وكأنه مجموعهم. اتصاد في PDF فعلي (٤ صفحات)، مش بالقراءة.
     `table-row-group` بيخلّيه صفًّا عاديًا يظهر مرة واحدة في مكانه الصح. */
  tfoot{display:table-row-group}

  /* ═══ قسم الجدول: شريط عنوان ملزوق فوق جدوله ═══
     ⚠️ **الشريط والجدول جسم واحد بصريًّا** — الشريط بياخد حواف علوية مدوّرة
     و`border-bottom:0`، والجدول تحته `margin-top:0`. أي فراغ بينهم بيخلّي
     الشريط يقرا كعنوان طايح مش كرأس للجدول. */
  .tsec{margin-top:15px}
  .tsec .hd{display:flex;flex-wrap:wrap;align-items:baseline;gap:9px;
    border:1px solid var(--line);border-bottom:0;border-top:3px solid var(--navy);
    border-radius:6px 6px 0 0;background:var(--bg);padding:8px 11px;
    break-inside:avoid;break-after:avoid;page-break-after:avoid}
  /* ⚠️ الاتجاه بيتكتب بمنتقٍ بنفس قوة `.tsec .hd` — `.pos` لوحدها (0,1,0)
     بتخسر أمام (0,2,0) فالجدولين كانوا هيطلعوا بلون واحد **بالسكوت**. */
  .tsec.in  .hd{border-top-color:var(--green)}
  .tsec.out .hd{border-top-color:var(--red)}
  .tsec .hd .t{font-size:11pt;font-weight:700;color:var(--navy)}
  .tsec.in  .hd .t{color:var(--green)}
  .tsec.out .hd .t{color:var(--red)}
  .tsec .hd .m{color:var(--muted);font-size:8.2pt}
  .tsec .hd .s{margin-inline-start:auto;font-weight:700;font-size:12pt;color:var(--navy);
    font-variant-numeric:tabular-nums}
  .tsec.in  .hd .s{color:var(--green)}
  .tsec.out .hd .s{color:var(--red)}
  .tsec .hd .kk{flex-basis:100%;display:flex;flex-wrap:wrap;gap:5px;margin-top:2px}
  .tsec table{margin-top:0;border-top-left-radius:0;border-top-right-radius:0}
  .tsec td:first-child{color:var(--muted)}
  /* ⚠️ **عمود النوع بيوسّع نفسه بـ`nowrap` بدل نسبة مظبوطة بالإيد**: أطول
     تسمية («مصروف من خزنة الشركة») كانت بتتلفّ على سطرين مع أي نسبة قدّرناها،
     وأي تسمية جديدة كانت هتكسرها من تاني. القيم محصورة في `CompanySafe::labels()`
     فمفيش خطر فيضان، والجدول auto-layout بيوزّع الباقي على «الجهة / البيان». */
  .tsec td.ty{white-space:nowrap}
  /* التظليل المتبادل بيخلّي عين القارئ تمشي على السطر في جدول عريض. */
  .tsec tbody tr:nth-child(even) td{background:var(--bg)}
  .num{font-variant-numeric:tabular-nums;white-space:nowrap}
  .amt{font-weight:700;font-variant-numeric:tabular-nums;white-space:nowrap}
  .cum{color:var(--muted);font-variant-numeric:tabular-nums;white-space:nowrap}
  .empty-row td{padding:20px;color:var(--muted)}

  /* ═══ شريط القفل: الافتتاحي + الداخل − الخارج = الختامي ═══ */
  .closing{display:flex;flex-wrap:wrap;align-items:baseline;gap:10px;margin-top:15px;
    border:1px solid var(--navy);border-top-width:3px;border-radius:6px;
    background:var(--bg);padding:9px 12px;break-inside:avoid}
  .closing .t{font-size:10.5pt;font-weight:700;color:var(--navy)}
  .closing .m{color:var(--muted);font-size:8.2pt;font-variant-numeric:tabular-nums}
  .closing .s{margin-inline-start:auto;font-weight:700;font-size:14pt;color:var(--navy);
    font-variant-numeric:tabular-nums}

  .pos{color:var(--green)}
  .neg{color:var(--red)}
  tfoot td{background:var(--gold-l);font-weight:700}
  .foot{margin-top:14px;color:var(--muted);font-size:8.2pt;display:flex;justify-content:space-between;
    border-top:1px solid var(--line);padding-top:9px}
  .noprint{margin:14px auto;width:210mm;display:flex;gap:10px;justify-content:center}
  .btn{padding:9px 18px;border-radius:5px;border:1px solid var(--navy);background:var(--navy);color:#fff;
    font-weight:500;cursor:pointer;text-decoration:none;font-family:inherit;font-size:9.5pt}
  .btn.g{background:#fff;color:var(--navy)}
  /* ⚠️ **`@page{margin:0}` إجبارية** — من غيرها المتصفح بيحطّ هامشه الافتراضي
     (١سم) **فوق** الـ297مم بتاعة `.sheet`، فالورقة بتفيض بمقدار الهامش
     و**كل طبعة بتطلع صفحة تانية شبه فاضية**. كل مستندات A4 في المشروع بتكتبها
     والكشف ده كان الوحيد اللي ناسيها (عيب سابق لتقسيم الجدولين — اتأكّد
     بطبع نسخة ما-قبل-التعديل: طلعت صفحتين هي كمان). */
  @media print{ @page{size:A4 portrait;margin:0}
    body{background:#fff} .sheet{box-shadow:none;margin:0} .noprint{display:none} }
</style>
</head>
<body>
<div class="noprint">
  <button class="btn" onclick="window.print()">طباعة / حفظ PDF</button>
  <?php /* ⚠️ **رابط تنقّل مش زر «رجوع»**: الصفحة بتتفتح في تبويب جديد
           (`target="_blank"`) فمفيش «ورا» أصلاً — الرجوع بيتقفل التبويب.
           و`verify_navigation` بيعدّ أزرار الرجوع المكتوبة بالإيد. */ ?>
  <a class="btn g" href="<?= url('company-safe') ?>">خزنة الشركة</a>
</div>

<div class="sheet">
  <?php if ($logoRel): ?><div class="wm"><img src="<?= e(url($logoRel)) ?>" alt=""></div><?php endif; ?>
  <div class="content">

    <div class="head">
      <?php if ($logoRel): ?><img src="<?= e(url($logoRel)) ?>" alt=""><?php endif; ?>
      <div>
        <div class="co"><?= e($company) ?></div>
        <div class="sub"><?= e(App::setting('company_activity', 'النقل والتخليص الجمركي')) ?></div>
      </div>
      <div class="doc-title">
        <div class="t">كشف حساب خزنة الشركة</div>
        <div class="d"><?= e(dfmt($st['from'])) ?> — <?= e(dfmt($st['to'])) ?></div>
      </div>
    </div>

    <?php /* ⚠️ **الأربعة بيقفلوا حسابيًّا**: الإجمالي − المكتب − المصروفات −
             الباقي = الختامي. لو نوع خارج جديد اتضاف ومحدّش عرضه، بيقع في
             `other_out` وبيبان تحت الكارت الأخير بدل ما الطرح يكدب بالسكوت. */ ?>
    <div class="totals">
      <div class="tt op"><div class="l">الإجمالي</div>
        <div class="v"><?= money($st['gross'], false) ?></div>
        <div class="n">أول المدة <?= money($st['opening'], false) ?>
          + داخل <?= money($st['in'], false) ?></div></div>
      <div class="tt"><div class="l">اتسلّم لخزنة المكتب</div>
        <div class="v neg"><?= money($st['to_office'], false) ?></div>
        <div class="n"><?= intf($st['by_kind']['to_office']['count'] ?? 0) ?> تحويل</div></div>
      <div class="tt"><div class="l">مصروفات خزنة الشركة</div>
        <div class="v neg"><?= money($st['expense'], false) ?></div>
        <div class="n"><?= intf($st['by_kind']['expense']['count'] ?? 0) ?> بند</div></div>
      <div class="tt cl"><div class="l">رصيد آخر المدة</div>
        <div class="v"><?= money($st['closing'], false) ?></div>
        <?php if ($st['other_out'] > 0.005): ?>
          <div class="n">بعد خصم <?= money($st['other_out'], false) ?> حسابات بنكية وعهد مناديب</div>
        <?php endif; ?></div>
    </div>

    <?php
      /* ⚠️ **جدولان منفصلان — قرار مالك صريح**: الداخل في جدول والخارج في جدول،
         مش الاتنين في نفس الجدول بعمودين. اللي اتشال هو عمود «الرصيد» التراكمي
         المختلط (ما بقاش له معنى لمّا الصفوف اتقسمت)، وبدله كل جدول له
         **مجمّعه هو** — فمجموع آخر صف في كل جدول = إجمالي الجدول بالظبط.
         ⚠️ **ولا قرش بيضيع في القسمة**: مجموع الجدولين لازم يساوي in/out
         الجايين من الموديل — محروس بفحص في `verify_v67`.
         ⚠️ **الاتجاه بيتقرّر من `CompanySafe::isIn()` لا من إشارة الرقم** —
         هي المصدر الوحيد لتصنيف النوع، وإشارة `signed` مشتقّة منها أصلاً. */
      $inRows = $outRows = [];
      foreach ($st['rows'] as $r) {
          if (CompanySafe::isIn((string) $r['kind'])) { $inRows[] = $r; } else { $outRows[] = $r; }
      }

      /* ⚠️ **اسم المحفظة قبل أي شرح** (طلب المالك: «يتم ذكر اسم الفيزا اللي خرج
         منها المال»). الاسم بيتوسم بمصدره — «كارت» لحركات الفيزا و«مندوب»
         للعهدة — عشان قارئ الورقة ما يخلطش بين اسم كارت واسم شخص. والصف
         المتفلَّت (كارت اتمسح) بيعدّي بلا وسم بدل ما نخترع اسمًا.
         ⚠️ **دالة واحدة للجدولين** — نسخها مرتين كان بيدّي بيانًا مختلفًا في
         كل جدول أول ما حد يعدّل واحدة وينسى التانية. */
      $descOf = static function (array $r): string {
          $wallet = trim((string) ($r['wallet'] ?? ''));
          if ($wallet !== '') {
              $wallet = (in_array((string) $r['kind'], CompanySafe::VISA_KINDS, true)
                  ? 'حساب بنكي: ' : 'مندوب: ') . $wallet;
          }
          $parts = array_filter([
              $wallet !== '' ? $wallet : null,
              $r['cat_label'] ?? null,
              $r['source'] ?: null,
              $r['note'] ?: null,
          ]);
          return $parts ? implode(' — ', $parts) : '—';
      };

      /* ⚠️ **تفكيك الأنواع نزل تحت جدوله بدل ما يعوم فوق الورقة**: صف الشرايط
         القديم (`.kinds`) كان بيخلط أنواع الداخل بأنواع الخارج في سطر واحد،
         فالقارئ بيقرا «مصروف ١٥٬٥٠٠» جنب «تحصيل ٢٥٠٬٠٠٠» بلا ما يعرف مين
         بيطرح من مين. دلوقتي كل نوع بيقعد في شريط عنوان جدوله. */
      $TABLES = [
        ['rows' => $inRows,  'title' => 'الأموال الداخلة',  'dir' => 'in',  'cls' => 'pos',
         'sum' => $st['in'],  'unit' => 'حركة داخلة', 'empty' => 'مفيش حركات داخلة في الفترة'],
        ['rows' => $outRows, 'title' => 'الأموال الخارجة', 'dir' => 'out', 'cls' => 'neg',
         'sum' => $st['out'], 'unit' => 'حركة خارجة', 'empty' => 'مفيش حركات خارجة في الفترة'],
      ];
    ?>
    <?php foreach ($TABLES as $t): ?>
      <?php $isInSec = $t['dir'] === 'in'; ?>
      <div class="tsec <?= $t['dir'] ?>">
      <div class="hd">
        <span class="t"><?= e($t['title']) ?></span>
        <span class="m"><?= intf(count($t['rows'])) ?> <?= e($t['unit']) ?></span>
        <span class="s"><?= money($t['sum'], false) ?> <?= e($currency) ?></span>
        <?php
          $mine = array_filter($st['by_kind'], static fn ($k) => CompanySafe::isIn((string) $k) === $isInSec,
                               ARRAY_FILTER_USE_KEY);
        ?>
        <?php if ($mine): ?>
          <span class="kk">
            <?php foreach ($mine as $k => $v): ?>
              <span class="kx"><?= e($labels[$k] ?? $k) ?>
                <b class="<?= $t['cls'] ?>"><?= money($isInSec ? $v['in'] : $v['out'], false) ?></b>
                <span class="cum">×<?= intf($v['count']) ?></span></span>
            <?php endforeach; ?>
          </span>
        <?php endif; ?>
      </div>
      <table>
        <thead>
          <tr>
            <?php /* ⚠️ **«الجهة / البيان» بياخد الباقي** — لمّا كان ٢٥٪ كان كل
                     بيان بيتلفّ على سطرين ورقم البوليصة بينزل لوحده في سطر
                     تاني (اتشاف في PDF فعلي). الأعمدة الرقمية اتضيّقت لصالحه. */ ?>
            <th style="width:5%">م</th>
            <th style="width:12%">التاريخ</th>
            <th>النوع</th>
            <th>الجهة / البيان</th>
            <th style="width:14%">المبلغ</th>
            <th style="width:13%">المجمّع</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$t['rows']): ?>
            <tr class="empty-row"><td colspan="6"><?= e($t['empty']) ?></td></tr>
          <?php else: ?>
            <?php $run = 0.0; ?>
            <?php foreach ($t['rows'] as $i => $r): ?>
              <?php $run = round($run + (float) $r['amount'], 2); ?>
              <tr>
                <td class="num"><?= intf($i + 1) ?></td>
                <td class="num"><?= e(dfmt((string) $r['entry_date'])) ?></td>
                <td class="r ty"><?= e((string) $r['label']) ?></td>
                <td class="r"><?= e($descOf($r)) ?></td>
                <td class="amt <?= $t['cls'] ?>"><?= money((float) $r['amount'], false) ?></td>
                <td class="cum"><?= money($run, false) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
        <?php /* ⚠️ إجمالي التذييل تحت عمود «المبلغ» بالظبط — دمجه مع «المجمّع»
                 بـcolspan كان بيوسّطه بين عمودين فيقرا كأنه رقم تالت، والعملة
                 لوحدها في خانة «المجمّع» كانت بتقرا كأنها قيمة العمود. */ ?>
        <tfoot>
          <tr>
            <td colspan="4" class="r">إجمالي <?= e($t['title']) ?></td>
            <td class="amt <?= $t['cls'] ?>"><?= money($t['sum'], false) ?></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
      </div>
    <?php endforeach; ?>

    <?php /* ⚠️ شريط القفل بعد الجدولين — بعد ما عمود «الرصيد» المختلط اتشال،
             ده بقى المكان الوحيد اللي بيربط الداخل بالخارج بالختامي. */ ?>
    <div class="closing">
      <span class="t">رصيد آخر المدة</span>
      <span class="m">رصيد أول المدة <?= money($st['opening'], false) ?>
        + داخل <?= money($st['in'], false) ?> − خارج <?= money($st['out'], false) ?></span>
      <span class="s"><?= money($st['closing'], false) ?> <?= e($currency) ?></span>
    </div>

    <?php /* ⚠️ **محافظ تانية مش جزء من كشف الشركة** — الدرج وجيوب المناديب
             فلوس خرجت من خزنة الشركة خلاص، فجمعها على الختامي تحسيب مزدوج.
             القسم منفصل بعنوانه وبسطر تاريخ لحظته تحت كل رقم. */ ?>
    <div class="extra">
      <h3>تفاصيل إضافية</h3>
      <div class="totals">
        <div class="tt"><div class="l">رصيد المكتب الفعلي حاليًا</div>
          <div class="v <?= $wallets['drawer'] < -0.005 ? 'neg' : '' ?>">
            <?= money($wallets['drawer'], false) ?></div>
          <div class="n">كاش في درج المكتب — <?= e(dfmt(date('Y-m-d'))) ?></div></div>
        <div class="tt"><div class="l">آخر رصيد مع المناديب</div>
          <div class="v"><?= money($wallets['reps_total'], false) ?></div>
          <div class="n"><?= $wallets['reps']
              ? intf(count($wallets['reps'])) . ' مندوب معاهم فلوس'
              : 'مفيش عهد مفتوحة' ?> — <?= e(dfmt(date('Y-m-d'))) ?></div></div>
      </div>
    </div>

    <?php /* ⚠️ معادلة القفل اتنقلت لسطر «رصيد آخر المدة» فوق الجدولين مباشرةً —
             تكرارها هنا كان هيدّي نفس الرقم مرتين في نفس الورقة. التذييل بقى
             مدى الفترة وتاريخ الطبع بس. */ ?>
    <div class="foot">
      <span><?= e($company) ?> — الفترة من <?= e(dfmt($st['from'])) ?> إلى <?= e(dfmt($st['to'])) ?></span>
      <span>اتطبع <?= e(dfmt(date('Y-m-d'))) ?></span>
    </div>

  </div>
</div>
</body>
</html>
