<?php
/**
 * كشف حساب خزنة المكتب — صفحة A4 مستقلّة للطباعة/حفظ PDF (docs/21 المرحلة ٥).
 *
 * خزنة الشركة والجرد كان لكل منهم كشف، **وخزنة المكتب لأ** — فالمالك اللي عايز
 * يطبع فترة كان لازم يفتح شاشة تانية.
 *
 * ⚠️ **هوية الطباعة من `partials/print_base.php`** — المصدر الوحيد لتوكنز كل
 *    مستندات A4. ممنوع أي مستند يكتب اسم خط أو لون هوية بإيده
 *    (`verify_typography` و`verify_print` بيحرسوا ده).
 *
 * ⚠️⚠️ **الافتتاحي + صافي الفترة = الختامي، وآخر رصيد جارٍ في الجدول = الختامي.**
 *    الرقمين من `Custody::movements()` اللي الشاشة بتقرا منها بالحرف، فمستحيل
 *    الورقة تقول رصيدًا يخالف الشاشة.
 *
 * ⚠️ **`thead` بيتكرر و`tfoot` لأ**: المتصفح بيكرّر التذييل في آخر كل صفحة
 *    بالإجمالي **الكامل**، فصفحة فيها ١١ صف بتقفل بإجمالي الكشف كله وكأنه
 *    مجموعها (اتصاد في PDF فعلي في كشف خزنة الشركة).
 *
 * ⚠️ قسم «دلوقتي» أرصدة **لحظية وقت الطباعة** مش أرقام الفترة — والورقة بتقول
 *    كده صراحةً وإلا القارئ يجمعها على الختامي وهي محفظة تانية.
 *
 * @var array $move   مخرَج `Custody::movements()`
 * @var array $wallet مخرَج `Closing::walletSplit()`
 */
use App\Core\App;

$company  = App::setting('company_name', 'شركة المتوكل');
$currency = App::setting('currency', 'ج.م');

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
  .tt .n{color:var(--muted);font-size:7.8pt;margin-top:3px}
  .tt.op{background:var(--bg)}
  .tt.cl{background:var(--navy);color:#fff;border-color:var(--navy)}
  /* ⚠️ سطر الميتا على السطح الغامق **لازم يقلب لونه** — `--muted` على `--navy`
     بيدّي تباينًا ٢:١ يعني غير مقروء مطبوعًا (اللون له دور تعبئة ودور نص). */
  .tt.cl .l,.tt.cl .n{color:#cfdcec}
  .in .v{color:var(--green)}
  .out .v{color:var(--red)}
  table{width:100%;border-collapse:collapse;font-size:8.6pt;margin-top:0}
  th,td{border:1px solid var(--line);padding:6px 7px;text-align:center}
  th{background:var(--navy);color:#fff;font-weight:500;font-size:8.2pt}
  td.r{text-align:right}
  /* الجدول الطويل بيتقسم على صفحات — من غير كده صفحة ٢ أرقام بلا عناوين. */
  thead{display:table-header-group}
  tfoot{display:table-row-group}
  tbody tr:nth-child(even) td{background:var(--bg)}
  .tsec{margin-top:15px}
  .tsec .hd{display:flex;flex-wrap:wrap;align-items:baseline;gap:9px;
    border:1px solid var(--line);border-bottom:0;border-top:3px solid var(--navy);
    border-radius:6px 6px 0 0;background:var(--bg);padding:8px 11px;
    break-inside:avoid;break-after:avoid;page-break-after:avoid}
  .tsec .hd .t{font-size:11pt;font-weight:700;color:var(--navy)}
  .tsec .hd .m{color:var(--muted);font-size:8.2pt}
  .tsec .hd .s{margin-inline-start:auto;font-weight:700;font-size:12pt;color:var(--navy);
    font-variant-numeric:tabular-nums}
  .num{font-variant-numeric:tabular-nums;white-space:nowrap}
  .amt{font-weight:700;font-variant-numeric:tabular-nums;white-space:nowrap}
  .cum{color:var(--muted);font-variant-numeric:tabular-nums;white-space:nowrap}
  .empty-row td{padding:20px;color:var(--muted)}
  .closing{display:flex;flex-wrap:wrap;align-items:baseline;gap:10px;margin-top:15px;
    border:1px solid var(--navy);border-top-width:3px;border-radius:6px;
    background:var(--bg);padding:9px 12px;break-inside:avoid}
  .closing .t{font-size:10.5pt;font-weight:700;color:var(--navy)}
  .closing .m{color:var(--muted);font-size:8.2pt;font-variant-numeric:tabular-nums}
  .closing .s{margin-inline-start:auto;font-weight:700;font-size:14pt;color:var(--navy);
    font-variant-numeric:tabular-nums}
  .extra{margin-top:14px;border-top:1px solid var(--line);padding-top:12px;break-inside:avoid}
  .extra h3{font-size:10pt;font-weight:700;color:var(--navy);margin-bottom:8px}
  .extra .totals{margin:0}
  .warn{margin-top:12px;border:1px solid var(--gold);background:var(--gold-l);
    border-radius:6px;padding:8px 11px;font-size:8.6pt;break-inside:avoid}
  .pos{color:var(--green)}
  .neg{color:var(--red)}
  .foot{margin-top:14px;color:var(--muted);font-size:8.2pt;display:flex;justify-content:space-between;
    border-top:1px solid var(--line);padding-top:9px}
  @media print{
    body{background:#fff}
    .sheet{margin:0;box-shadow:none;width:auto;min-height:auto}
    .noprint{display:none!important}
  }
  .noprint{text-align:center;margin:14px}
  .btn-print{background:var(--navy);color:#fff;border:0;border-radius:6px;
    padding:9px 22px;font-family:var(--font);font-size:10pt;cursor:pointer}
</style>
</head>
<body>
<div class="noprint"><button class="btn-print" onclick="window.print()">اطبع / احفظ PDF</button></div>

<div class="sheet">
  <?php if ($logoRel): ?><div class="wm"><img src="<?= e(url($logoRel)) ?>" alt=""></div><?php endif; ?>
  <div class="content">

    <div class="head">
      <?php if ($logoRel): ?><img src="<?= e(url($logoRel)) ?>" alt=""><?php endif; ?>
      <div>
        <div class="co"><?= e($company) ?></div>
        <div class="sub">النقل والتخليص الجمركي</div>
      </div>
      <div class="doc-title">
        <div class="t">كشف حساب خزنة المكتب</div>
        <div class="d"><?= e(dfmt($move['from'])) ?> → <?= e(dfmt($move['to'])) ?></div>
        <div class="d">تاريخ الطباعة: <?= e(dfmt(work_date())) ?></div>
      </div>
    </div>

    <div class="totals">
      <div class="tt op">
        <div class="l">رصيد أول المدة</div>
        <div class="v"><?= money($move['opening'], false) ?></div>
        <div class="n"><?= e(dfmt($move['from'])) ?></div>
      </div>
      <div class="tt in">
        <div class="l">الداخل</div>
        <div class="v">+<?= money($move['in'], false) ?></div>
        <div class="n">إضافات عهدة</div>
      </div>
      <div class="tt out">
        <div class="l">الخارج</div>
        <div class="v">-<?= money($move['out'], false) ?></div>
        <div class="n">مصروفات <?= money($move['parts']['expenses'], false) ?>
          · عهد <?= money($move['parts']['driver'], false) ?></div>
      </div>
      <div class="tt cl">
        <div class="l">رصيد آخر المدة</div>
        <div class="v"><?= money($move['closing'], false) ?></div>
        <div class="n"><?= e(dfmt($move['to'])) ?></div>
      </div>
    </div>

    <?php /* ⚠️ تحذير وظيفي — لقطة إقفال متقادمة لازم تتقال على الورقة كمان. */ ?>
    <?php if (abs($move['drift']) >= 0.005): ?>
      <div class="warn">⚠️ لقطة إقفال متقادمة — فرق <?= money($move['drift'], false) ?> بين الختامي والمحسوب.</div>
    <?php endif; ?>
    <?php if ($move['capped']): ?>
      <div class="warn">الفترة أطول من <?= intf(\App\Models\SafeInventory::MAX_ROW_DAYS) ?> يوم — الإجماليات كاملة والصفوف مش مطبوعة.</div>
    <?php elseif ($move['truncated']): ?>
      <div class="warn">مطبوع آخر <?= intf(count($move['rows'])) ?> حركة من <?= intf($move['rows_total']) ?> — قصّر الفترة لطباعتها كلها.</div>
    <?php endif; ?>

    <div class="tsec">
      <div class="hd">
        <span class="t">حركة الخزنة</span>
        <span class="m"><?= intf(count($move['rows'])) ?> حركة</span>
        <span class="s"><?= money($move['net'], false) ?></span>
      </div>
      <table>
        <thead>
          <tr>
            <th style="width:15%">التاريخ</th>
            <th style="width:26%">البيان</th>
            <th style="width:22%">الجهة</th>
            <th style="width:15%">المبلغ</th>
            <th style="width:22%">الرصيد الجاري</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td class="num"><?= e(dfmt($move['from'])) ?></td>
            <td class="r" colspan="3">
              <?= $move['truncated'] ? 'الرصيد قبل أول صف مطبوع' : 'رصيد أول المدة' ?>
            </td>
            <td class="cum"><?= money($move['lead'], false) ?></td>
          </tr>
          <?php if (!$move['rows']): ?>
            <tr class="empty-row"><td colspan="5">مفيش حركة في الفترة دي</td></tr>
          <?php else: foreach ($move['rows'] as $r): ?>
            <tr>
              <td class="num"><?= e(dfmt($r['date'])) ?></td>
              <td class="r"><?= e($r['title']) ?><?= $r['ref'] ? ' — ' . e($r['ref']) : '' ?></td>
              <td class="r"><?= e($r['party'] ?: '—') ?></td>
              <td class="amt <?= $r['signed'] >= 0 ? 'pos' : 'neg' ?>">
                <?= $r['signed'] >= 0 ? '+' : '-' ?><?= money(abs($r['signed']), false) ?>
              </td>
              <td class="cum"><?= money($r['running'], false) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <div class="closing">
      <span class="t">رصيد آخر المدة</span>
      <span class="m">
        <?= money($move['opening'], false) ?> + <?= money($move['in'], false) ?>
        − <?= money($move['out'], false) ?>
      </span>
      <span class="s"><?= money($move['closing'], false) ?> <?= e($currency) ?></span>
    </div>

    <?php /* ⚠️ **أرصدة لحظية مش أرقام الفترة** — والورقة بتقولها صراحةً. */ ?>
    <div class="extra">
      <h3>دلوقتي (وقت الطباعة — مش أرقام الفترة)</h3>
      <div class="totals">
        <div class="tt">
          <div class="l">في درج المكتب</div>
          <div class="v"><?= money($wallet['drawer'], false) ?></div>
          <div class="n">ينعدّ بالإيد</div>
        </div>
        <div class="tt">
          <div class="l">مع المناديب</div>
          <div class="v"><?= money($wallet['reps_total'], false) ?></div>
          <div class="n"><?= intf(count($wallet['reps'])) ?> مندوب</div>
        </div>
        <div class="tt">
          <div class="l">إجمالي كاش المكتب</div>
          <div class="v"><?= money($wallet['total'], false) ?></div>
          <div class="n">درج + مناديب</div>
        </div>
      </div>
    </div>

    <div class="foot">
      <span><?= e($company) ?></span>
      <span>خصم عهدة البوليصة: <?= intf($rate) ?> <?= e($currency) ?></span>
    </div>

  </div>
</div>
</body>
</html>
