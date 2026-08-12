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
  .sheet{width:210mm;min-height:297mm;margin:16px auto;background:#fff;position:relative;
    box-shadow:0 10px 40px rgba(21,53,91,.15);overflow:hidden;padding:18mm 14mm}
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
  .totals{display:flex;gap:10px;margin:16px 0}
  .tt{flex:1;border:1px solid var(--line);border-radius:6px;padding:11px 12px;text-align:center}
  .tt .l{color:var(--muted);font-size:8.6pt}
  .tt .v{font-weight:700;font-size:12pt;margin-top:2px}
  .tt.op{background:var(--bg)}
  .tt.cl{background:var(--navy);color:#fff;border-color:var(--navy)}
  .tt.cl .l{color:#cfdcec}
  /* ⚠️ سطر الميتا على السطح الغامق **لازم يقلب لونه** — `--muted` على `--navy`
     بيدّي تباينًا ٢:١ يعني غير مقروء مطبوعًا (قاعدة: اللون له دور تعبئة ودور نص). */
  .tt.cl .n{color:#cfdcec}
  .extra{margin-top:18px;border-top:1px solid var(--line);padding-top:12px;break-inside:avoid}
  .extra h3{font-size:10pt;font-weight:700;color:var(--navy);margin-bottom:8px}
  .extra .totals{margin:0}
  .tt .n{color:var(--muted);font-size:7.8pt;margin-top:3px}
  .kinds{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px}
  .kx{border:1px solid var(--line);border-radius:5px;padding:5px 9px;font-size:8.6pt;background:var(--bg)}
  .kx b{font-weight:700}
  table{width:100%;border-collapse:collapse;margin-top:6px;font-size:8.6pt}
  th,td{border:1px solid var(--line);padding:6px 7px;text-align:center}
  th{background:var(--navy);color:#fff;font-weight:500}
  td.r{text-align:right}
  .pos{color:var(--green);font-weight:600}
  .neg{color:var(--red);font-weight:600}
  tfoot td{background:var(--gold-l);font-weight:700}
  .foot{margin-top:20px;color:var(--muted);font-size:8.6pt;display:flex;justify-content:space-between;
    border-top:1px solid var(--line);padding-top:10px}
  .noprint{margin:14px auto;width:210mm;display:flex;gap:10px;justify-content:center}
  .btn{padding:9px 18px;border-radius:5px;border:1px solid var(--navy);background:var(--navy);color:#fff;
    font-weight:500;cursor:pointer;text-decoration:none;font-family:inherit;font-size:9.5pt}
  .btn.g{background:#fff;color:var(--navy)}
  @media print{ body{background:#fff} .sheet{box-shadow:none;margin:0} .noprint{display:none} }
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
          <div class="n">بعد خصم <?= money($st['other_out'], false) ?> فيزا وعهد مناديب</div>
        <?php endif; ?></div>
    </div>

    <?php if ($st['by_kind']): ?>
      <div class="kinds">
        <?php foreach ($st['by_kind'] as $k => $v): ?>
          <span class="kx"><?= e($labels[$k] ?? $k) ?>:
            <b class="<?= CompanySafe::isIn($k) ? 'pos' : 'neg' ?>">
              <?= money(CompanySafe::isIn($k) ? $v['in'] : $v['out'], false) ?></b>
            (<?= intf($v['count']) ?>)</span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <table>
      <thead>
        <tr>
          <th style="width:8%">م</th>
          <th style="width:14%">التاريخ</th>
          <th style="width:20%">النوع</th>
          <th>الجهة / البيان</th>
          <th style="width:14%">داخل</th>
          <th style="width:14%">خارج</th>
          <th style="width:15%">الرصيد</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$st['rows']): ?>
          <tr><td colspan="7" style="padding:22px;color:var(--muted)">مفيش حركات في الفترة دي</td></tr>
        <?php else: ?>
          <?php foreach ($st['rows'] as $i => $r): ?>
            <?php
              $isIn = $r['signed'] >= 0;
              /* ⚠️ **اسم المحفظة قبل أي شرح** (طلب المالك: «يتم ذكر اسم الفيزا
                 اللي خرج منها المال»). الاسم بيتوسم بمصدره — «كارت» لحركات
                 الفيزا و«مندوب» للعهدة — عشان قارئ الورقة ما يخلطش بين
                 اسم كارت واسم شخص. والصف المتفلَّت (كارت اتمسح) بيعدّي بلا وسم
                 بدل ما نخترع اسمًا. */
              $wallet = trim((string) ($r['wallet'] ?? ''));
              if ($wallet !== '') {
                  $wallet = (in_array((string) $r['kind'], CompanySafe::VISA_KINDS, true)
                      ? 'كارت: ' : 'مندوب: ') . $wallet;
              }
              $desc = array_filter([
                  $wallet !== '' ? $wallet : null,
                  $r['cat_label'] ?? null,
                  $r['source'] ?: null,
                  $r['note'] ?: null,
              ]);
            ?>
            <tr>
              <td><?= intf($i + 1) ?></td>
              <td><?= e(dfmt((string) $r['entry_date'])) ?></td>
              <td class="r"><?= e((string) $r['label']) ?></td>
              <td class="r"><?= $desc ? e(implode(' — ', $desc)) : '—' ?></td>
              <td class="pos"><?= $isIn ? money((float) $r['amount'], false) : '—' ?></td>
              <td class="neg"><?= $isIn ? '—' : money((float) $r['amount'], false) ?></td>
              <td><?= money((float) $r['running'], false) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="4">الإجمالي</td>
          <td><?= money($st['in'], false) ?></td>
          <td><?= money($st['out'], false) ?></td>
          <td><?= money($st['closing'], false) ?> <?= e($currency) ?></td>
        </tr>
      </tfoot>
    </table>

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

    <div class="foot">
      <span>رصيد أول المدة <?= money($st['opening'], false) ?>
            + صافي الفترة <?= money(round($st['in'] - $st['out'], 2), false) ?>
            = رصيد آخر المدة <?= money($st['closing'], false) ?></span>
      <span>اتطبع <?= e(dfmt(date('Y-m-d'))) ?></span>
    </div>

  </div>
</div>
</body>
</html>
