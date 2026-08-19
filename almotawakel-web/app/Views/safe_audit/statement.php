<?php
/**
 * كشف جرد الخزينة (v108) — صفحة A4 مستقلّة للطباعة/حفظ PDF.
 *
 * ⚠️ **هوية الطباعة من `partials/print_base.php`** — المصدر الوحيد لتوكنز كل
 *    مستندات A4. ممنوع أي مستند يكتب اسم خط أو لون هوية بإيده
 *    (`verify_typography` بيحرس ده).
 *
 * ⚠️ **الورقة بتقول الخزنتين وبتقفل حسابيًّا**: لكل خزنة أول المدة + داخل −
 *    خارج = آخر المدة، والمجمّع تحتهم. أي فرق ترحيل بيتكتب صراحةً بدل ما
 *    الطرح يكدب بالسكوت.
 *
 * ⚠️ **التحويلات بين الخزنتين بتتوسم في الجدول** — بتتعد مرتين في الإجمالي
 *    الخام وبتلغي نفسها في الصافي، والورقة بتقول الرقم صراحةً في التذييل.
 *
 * ⚠️ `@page{margin:0}` **إجبارية** — من غيرها المتصفح بيحطّ هامشه فوق الـ297مم
 *    وكل طبعة بتطلع صفحة تانية شبه فاضية (اتصاد في `company_safe/statement`).
 *
 * @var array $st      مخرَج `SafeInventory::statement()`
 * @var array $snap    مخرَج `SafeInventory::snapshot()` — أرصدة لحظة الطباعة
 * @var array $audits  عمليات الجرد في الفترة
 */
use App\Core\App;
use App\Models\SafeInventory;

$company  = App::setting('company_name', 'شركة المتوكل');
$currency = App::setting('currency', 'ج.م');
$total    = $st['total'];

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
  .tt.cl .l,.tt.cl .n{color:#cfdcec}
  table{width:100%;border-collapse:collapse;font-size:8.6pt}
  th,td{border:1px solid var(--line);padding:6px 7px;text-align:center}
  th{background:var(--navy);color:#fff;font-weight:500;font-size:8.2pt}
  td.r{text-align:right}
  thead{display:table-header-group}
  /* ⚠️ المتصفح بيكرّر `tfoot` في آخر **كل** صفحة بالإجمالي الكامل — `table-row-group`
     بيخلّيه صفًّا عاديًا يظهر مرة واحدة في مكانه (اتصاد في PDF فعلي). */
  tfoot{display:table-row-group}
  tfoot td{background:var(--gold-l);font-weight:700}
  .tsec{margin-top:15px}
  .tsec .hd{display:flex;flex-wrap:wrap;align-items:baseline;gap:9px;
    border:1px solid var(--line);border-bottom:0;border-top:3px solid var(--navy);
    border-radius:6px 6px 0 0;background:var(--bg);padding:8px 11px;
    break-inside:avoid;break-after:avoid;page-break-after:avoid}
  .tsec .hd .t{font-size:11pt;font-weight:700;color:var(--navy)}
  .tsec .hd .m{color:var(--muted);font-size:8.2pt}
  .tsec .hd .s{margin-inline-start:auto;font-weight:700;font-size:12pt;color:var(--navy);
    font-variant-numeric:tabular-nums}
  .tsec table{margin-top:0;border-top-left-radius:0;border-top-right-radius:0}
  .tsec tbody tr:nth-child(even) td{background:var(--bg)}
  .num{font-variant-numeric:tabular-nums;white-space:nowrap}
  .amt{font-weight:700;font-variant-numeric:tabular-nums;white-space:nowrap}
  .cum{color:var(--muted);font-variant-numeric:tabular-nums;white-space:nowrap}
  .tag{border:1px solid var(--line);border-radius:20px;padding:1px 7px;font-size:7.6pt;white-space:nowrap}
  .empty-row td{padding:20px;color:var(--muted)}
  .closing{display:flex;flex-wrap:wrap;align-items:baseline;gap:10px;margin-top:15px;
    border:1px solid var(--navy);border-top-width:3px;border-radius:6px;
    background:var(--bg);padding:9px 12px;break-inside:avoid}
  .closing .t{font-size:10.5pt;font-weight:700;color:var(--navy)}
  .closing .m{color:var(--muted);font-size:8.2pt;font-variant-numeric:tabular-nums}
  .closing .s{margin-inline-start:auto;font-weight:700;font-size:14pt;color:var(--navy);
    font-variant-numeric:tabular-nums}
  .sign{display:flex;gap:30px;margin-top:22px;break-inside:avoid}
  .sign div{flex:1;border-top:1px solid var(--ink);padding-top:6px;text-align:center;
    color:var(--muted);font-size:8.4pt}
  .pos{color:var(--green)}
  .neg{color:var(--red)}
  .foot{margin-top:14px;color:var(--muted);font-size:8.2pt;display:flex;justify-content:space-between;
    border-top:1px solid var(--line);padding-top:9px}
  .noprint{margin:14px auto;width:210mm;display:flex;gap:10px;justify-content:center}
  .btn{padding:9px 18px;border-radius:5px;border:1px solid var(--navy);background:var(--navy);color:#fff;
    font-weight:500;cursor:pointer;text-decoration:none;font-family:inherit;font-size:9.5pt}
  .btn.g{background:#fff;color:var(--navy)}
  @media print{ @page{size:A4 portrait;margin:0}
    body{background:#fff} .sheet{box-shadow:none;margin:0} .noprint{display:none} }
</style>
</head>
<body>
<div class="noprint">
  <button class="btn" onclick="window.print()">طباعة / حفظ PDF</button>
  <?php /* ⚠️ رابط تنقّل مش زر «رجوع»: الصفحة بتتفتح في تبويب جديد فمفيش «ورا». */ ?>
  <a class="btn g" href="<?= url('safe-audit') ?>">جرد الخزينة</a>
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
        <div class="t">كشف جرد الخزينة</div>
        <div class="d"><?= e(dfmt($st['from'])) ?> — <?= e(dfmt($st['to'])) ?></div>
      </div>
    </div>

    <?php /* ⚠️ الأربعة بيقفلوا حسابيًّا: أول المدة + داخل − خارج = آخر المدة.
             «داخل/خارج» هنا **بعد شيل التحويلات الداخلية** عشان ما يتعدّوش مرتين. */ ?>
    <div class="totals">
      <div class="tt op"><div class="l">أول المدة</div>
        <div class="v"><?= money($total['opening'], false) ?></div>
        <div class="n">الخزنتين مع بعض</div></div>
      <div class="tt"><div class="l">داخل من برّه</div>
        <div class="v pos"><?= money($total['in'], false) ?></div>
        <div class="n">بلا التحويلات الداخلية</div></div>
      <div class="tt"><div class="l">خارج لبرّه</div>
        <div class="v neg"><?= money($total['out'], false) ?></div>
        <div class="n">بلا التحويلات الداخلية</div></div>
      <div class="tt cl"><div class="l">آخر المدة</div>
        <div class="v"><?= money($total['closing'], false) ?></div>
        <?php if (abs((float) $total['drift']) >= 0.01): ?>
          <div class="n">فرق ترحيل <?= money((float) $total['drift'], false) ?></div>
        <?php endif; ?></div>
    </div>

    <?php /* ═══ كل خزنة لوحدها ═══ */ ?>
    <div class="tsec">
      <div class="hd">
        <span class="t">الخزنتان في الفترة</span>
        <span class="m"><?= intf((int) $st['days']) ?> يوم</span>
        <span class="s"><?= money($total['closing'], false) ?> <?= e($currency) ?></span>
      </div>
      <table>
        <thead><tr>
          <th>الخزنة</th><th>أول المدة</th><th>داخل</th><th>خارج</th><th>آخر المدة</th>
        </tr></thead>
        <tbody>
          <?php foreach ($st['safes'] as $s): ?>
            <tr>
              <td class="r"><?= e((string) $s['label']) ?></td>
              <td class="num"><?= money($s['opening'], false) ?></td>
              <td class="amt pos"><?= money($s['in'], false) ?></td>
              <td class="amt neg"><?= money($s['out'], false) ?></td>
              <td class="amt"><?= money($s['closing'], false) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot><tr>
          <td class="r">الإجمالي</td>
          <td class="num"><?= money($total['opening'], false) ?></td>
          <td class="amt pos"><?= money($total['in_gross'], false) ?></td>
          <td class="amt neg"><?= money($total['out_gross'], false) ?></td>
          <td class="amt"><?= money($total['closing'], false) ?></td>
        </tr></tfoot>
      </table>
    </div>

    <?php /* ═══ الكشف الواحد ═══ */ ?>
    <div class="tsec">
      <div class="hd">
        <span class="t">حركة الخزنتين</span>
        <span class="m"><?= intf(count($st['rows'])) ?> حركة<?php
          if ($st['rows_total'] > count($st['rows'])): ?> من <?= intf((int) $st['rows_total']) ?><?php
          endif; ?></span>
        <span class="s"><?= money($total['net'], false) ?> <?= e($currency) ?></span>
      </div>
      <table>
        <thead><tr>
          <th style="width:11%">التاريخ</th>
          <th style="width:13%">الخزنة</th>
          <th>البيان</th>
          <th style="width:13%">داخل</th>
          <th style="width:13%">خارج</th>
          <th style="width:13%">الرصيد</th>
        </tr></thead>
        <tbody>
          <?php if (!$st['rows']): ?>
            <tr class="empty-row"><td colspan="6">
              <?= $st['rows_capped']
                  ? 'الفترة أطول من ' . intf(SafeInventory::MAX_ROW_DAYS) . ' يوم — الأرقام فوق كاملة'
                  : 'مفيش حركة في الفترة دي' ?>
            </td></tr>
          <?php else: ?>
            <?php $run = (float) $total['opening']; ?>
            <?php foreach ($st['rows'] as $r): ?>
              <?php
                $run  = round($run + (float) $r['signed'], 2);
                $desc = array_filter([$r['party'] ?? null, $r['extra'] ?? null, $r['note'] ?? null]);
              ?>
              <tr>
                <td class="num"><?= e(dfmt((string) $r['date'])) ?></td>
                <td><?= e(SafeInventory::label((string) $r['safe'])) ?></td>
                <td class="r"><?= e((string) $r['title']) ?><?php
                  if ($desc): ?> — <?= e(implode(' · ', $desc)) ?><?php endif;
                  if (!empty($r['transfer'])): ?> <span class="tag">تحويل داخلي</span><?php endif; ?></td>
                <td class="amt pos"><?= $r['signed'] > 0.005 ? money((float) $r['signed'], false) : '' ?></td>
                <td class="amt neg"><?= $r['signed'] < -0.005 ? money(-(float) $r['signed'], false) : '' ?></td>
                <td class="cum"><?= money($run, false) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="closing">
      <span class="t">رصيد آخر المدة</span>
      <span class="m">أول المدة <?= money($total['opening'], false) ?>
        + داخل <?= money($total['in'], false) ?> − خارج <?= money($total['out'], false) ?><?php
        if ($st['transfer_count'] > 0): ?> · تحويلات داخلية <?= money((float) $st['transfers'], false) ?><?php
        endif; ?></span>
      <span class="s"><?= money($total['closing'], false) ?> <?= e($currency) ?></span>
    </div>

    <?php /* ═══ الجرد الفعلي في الفترة ═══ */ ?>
    <?php if ($audits): ?>
      <div class="tsec">
        <div class="hd">
          <span class="t">الجرد الفعلي</span>
          <span class="m"><?= intf(count($audits)) ?> عملية</span>
        </div>
        <table>
          <thead><tr>
            <th style="width:14%">التاريخ</th><th style="width:18%">الخزنة</th>
            <th style="width:16%">الدفتري</th><th style="width:16%">المعدود</th>
            <th style="width:16%">الفرق</th><th>ملاحظة</th>
          </tr></thead>
          <tbody>
            <?php foreach ($audits as $a): ?>
              <tr>
                <td class="num"><?= e(dfmt((string) $a['audit_date'])) ?></td>
                <td><?= e((string) $a['safe_label']) ?></td>
                <td class="num"><?= money((float) $a['book_balance'], false) ?></td>
                <td class="num"><?= money((float) $a['counted'], false) ?></td>
                <td class="amt <?= $a['state'] === 'match' ? '' : ($a['state'] === 'over' ? 'pos' : 'neg') ?>">
                  <?= $a['state'] === 'match' ? 'مطابق' : money((float) $a['diff'], false) ?></td>
                <td class="r"><?= e((string) ($a['note'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php /* ⚠️ أرصدة **لحظة الطباعة** مش أرقام الفترة — الورقة بتقول كده صراحةً
             وإلا القارئ يجمعها على آخر المدة وهي محفظة تانية. */ ?>
    <div class="totals">
      <div class="tt"><div class="l">في درج المكتب دلوقتي</div>
        <div class="v"><?= money($snap['safes']['office']['book'], false) ?></div>
        <div class="n"><?= e(dfmt(date('Y-m-d'))) ?></div></div>
      <div class="tt"><div class="l">في خزنة الشركة دلوقتي</div>
        <div class="v"><?= money($snap['safes']['company']['book'], false) ?></div>
        <div class="n"><?= e(dfmt(date('Y-m-d'))) ?></div></div>
      <div class="tt"><div class="l">مع المناديب</div>
        <div class="v"><?= money($snap['reps'], false) ?></div>
        <div class="n"><?= intf((int) $snap['reps_count']) ?> مندوب — برّه الجرد</div></div>
    </div>

    <div class="sign">
      <div>المحاسب</div>
      <div>أمين الخزنة</div>
      <div>المدير</div>
    </div>

    <div class="foot">
      <span><?= e($company) ?> — الفترة من <?= e(dfmt($st['from'])) ?> إلى <?= e(dfmt($st['to'])) ?></span>
      <span>اتطبع <?= e(dfmt(date('Y-m-d'))) ?></span>
    </div>

  </div>
</div>
</body>
</html>
