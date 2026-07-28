<?php
use App\Core\App;

/** التقفيل اليومي — صفحة طباعة/حفظ PDF (A4 بستايل الفاتورة، RTL) */
$company  = App::setting('company_name', 'شركة المتوكل');
$currency = App::setting('currency', 'ج.م');
$phone    = App::setting('company_phone', '');
$email    = App::setting('company_email', '');
$address  = App::setting('company_address', '');
/* v50: المطابقة اليدوية القديمة اتشالت — اللي بيتطبع دلوقتي هو **حصيلة المراجعة**
   (كام بند اتراجع من كام) زي ما هي في الشاشة، بلا أي رقم بيتحسب هنا. */
$rv        = $review ?? ['available'=>false,'groups'=>[],'totals'=>[],'complete'=>false,'last_at'=>null,'last_by'=>null];
$rvTotals  = $rv['totals'] ?? [];
$totOutflow = round((float) $totExpense + (float) $totCustodyExp, 2);

// اللوجو: يُستخدم ملف الصورة إن وُجد، وإلا شعار نصّي بألوان الشركة
$logoRel = null;
foreach (['assets/img/logo.png', 'assets/img/logo.jpg', 'assets/img/logo.jpeg'] as $cand) {
    if (is_file(App::basePath('public/' . $cand))) { $logoRel = $cand; break; }
}
$dash = fn($v) => ($v === null || $v === '') ? '—' : $v;
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>التقفيل اليومي — <?= e(dfmt($date)) ?> — <?= e($company) ?></title>
<?php partial('print_base'); ?>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  html,body{background:var(--bg);color:var(--ink);
    font-family:var(--font);direction:rtl;text-align:right;
    -webkit-print-color-adjust:exact;print-color-adjust:exact}
  .sheet{width:210mm;min-height:297mm;margin:16px auto;background:#fff;position:relative;
    box-shadow:0 10px 40px rgba(21,53,91,.15);overflow:hidden}
  .pad{padding:14mm 12mm}
  .topbar{height:8px;background:linear-gradient(90deg,var(--gold),var(--navy))}
  /* الترويسة */
  .head{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;
    padding-bottom:16px;border-bottom:2px solid var(--line)}
  .brand{display:flex;flex-direction:column;align-items:center;gap:6px}
  .brandlogo{height:78px;width:auto;max-width:230px;object-fit:contain}
  .brand .logo{width:78px;height:78px;border-radius:var(--radius-3);background:var(--navy);color:var(--gold);
    display:flex;align-items:center;justify-content:center;font-size:42px;font-weight:600}
  .brand .cap{color:var(--navy);font-size:14px;font-weight:600;letter-spacing:.5px}
  .doc-tag{text-align:left;min-width:210px}
  .doc-tag .word{display:inline-block;background:var(--navy);color:#fff;font-size:20px;font-weight:600;
    padding:9px 22px;border-radius:var(--radius-2);letter-spacing:1px}
  .doc-tag .meta{margin-top:12px;font-size:12.5px;color:var(--muted);line-height:2}
  .doc-tag .meta b{color:var(--navy);font-size:14px}
  /* بطاقات الملخص */
  .sum{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:16px}
  .sum .b{border:1px solid var(--line);border-radius:var(--radius-2);padding:11px 10px;text-align:center;
    border-top:3px solid var(--navy);background:#fcfdfe}
  .sum .b.gold{border-top-color:var(--gold);background:var(--gold-l)}
  .sum .l{font-size:10.5px;color:var(--muted);font-weight:500}
  .sum .v{font-size:16px;font-weight:600;color:var(--navy);margin-top:4px;font-variant-numeric:tabular-nums}
  .sum .b.gold .v{color:#8a6d10}
  /* عناوين الأقسام */
  .sec{display:flex;align-items:center;gap:8px;margin:20px 0 8px;color:var(--navy);
    font-size:14px;font-weight:600}
  .sec::before{content:"";width:5px;height:18px;background:var(--gold);border-radius:var(--radius-1)}
  .sec .c{color:var(--muted);font-size:11.5px;font-weight:500}
  /* الجداول */
  table.data{width:100%;border-collapse:collapse;font-size:10.5px;
    border:1px solid var(--line);border-radius:var(--radius-2);overflow:hidden}
  table.data thead th{background:var(--navy);color:#fff;padding:8px 6px;font-weight:500;text-align:center;
    white-space:nowrap}
  table.data thead th.bayan{text-align:right}
  table.data tbody td{padding:7px 6px;border-bottom:1px solid var(--line);text-align:center;
    vertical-align:top;word-break:break-word}
  table.data tbody td.bayan{text-align:right}
  table.data tbody td.num{font-variant-numeric:tabular-nums;white-space:nowrap;font-weight:500}
  table.data tbody td.pno{font-weight:600;color:var(--navy);white-space:nowrap}
  table.data tbody tr:nth-child(even){background:#f8fafc}
  table.data tbody td.empty{color:var(--muted);padding:12px;text-align:center}
  table.data tfoot td{background:var(--navy);color:var(--gold);font-weight:600;padding:8px 6px;text-align:center}
  table.data tfoot td.lbl{text-align:right;color:#fff}
  table.visa{max-width:100%;font-size:10.5px;margin-top:2px}
  /* صندوق الحساب الختامي */
  .calc{margin-top:18px;display:grid;grid-template-columns:1.1fr .9fr;gap:16px;align-items:start}
  .calc .note-words{border:1px dashed var(--gold);background:var(--gold-l);border-radius:var(--radius-2);padding:14px 16px}
  .calc .note-words .t{font-size:11px;color:#8a6d10;font-weight:600;margin-bottom:6px}
  .calc .note-words .v{font-size:13px;font-weight:500;color:var(--navy);line-height:1.9}
  .sumbox{border:1px solid var(--line);border-radius:var(--radius-2);overflow:hidden}
  .sumbox .r{display:flex;justify-content:space-between;padding:9px 14px;font-size:12.5px;border-bottom:1px solid var(--line)}
  .sumbox .r .l{color:var(--muted)}
  .sumbox .r .v{font-weight:500;font-variant-numeric:tabular-nums}
  .sumbox .r.sub{background:#f8fafc}
  .sumbox .r.neg .v{color:var(--red)}
  .sumbox .r.grand{background:var(--gold);color:var(--navy);font-size:15px;padding:13px 14px;border:none;font-weight:600}
  .sumbox .r.grand .l{color:#7a5f0e}
  /* التوقيعات + التواصل */
  .foot{margin-top:24px;padding-top:16px;border-top:2px solid var(--line);
    display:grid;grid-template-columns:1fr 1fr;gap:40px;align-items:end}
  .sign{text-align:center}
  .sign .line{margin-top:28px;border-top:1.5px dashed var(--muted);padding-top:6px;color:var(--muted);font-size:12.5px;font-weight:500}
  .contact-bar{margin-top:18px;padding-top:12px;border-top:1px solid var(--line);
    display:flex;gap:24px;flex-wrap:wrap}
  .contact .cline{font-size:12px;color:var(--ink);font-weight:500;line-height:1.7}
  .contact .ci{display:inline-block;width:20px;color:var(--navy)}
  .pagefoot{text-align:center;color:var(--muted);font-size:10.5px;padding:10px 0 16px}
  .wm{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
    font-size:340px;font-weight:700;color:rgba(21,53,91,.035);pointer-events:none;z-index:0}
  .pad>*{position:relative;z-index:1}
  .rvnote{margin-top:6px;font-size:11px;color:var(--muted)}
  .bar{max-width:210mm;margin:0 auto;display:flex;gap:10px;justify-content:center;padding:6px}
  .btn{padding:10px 22px;border:none;border-radius:var(--radius-2);font-weight:600;font-size:14px;cursor:pointer;
    font-family:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:8px}
  .btn.gold{background:var(--gold);color:var(--navy)}
  .btn.ghost{background:#fff;color:var(--navy);border:1.5px solid var(--navy)}
  @media print{
    @page{size:A4;margin:0}
    html,body{background:#fff}
    .noprint{display:none!important}
    .sheet{margin:0;box-shadow:none;width:auto;min-height:auto}
    table{page-break-inside:auto}
    tr{page-break-inside:avoid}
  }
</style>
</head>
<body>

<div class="bar noprint">
  <button class="btn gold" onclick="window.print()"><?= icon('file-text', 14) ?> طباعة / حفظ PDF</button>
  <a class="btn ghost" href="<?= url('closing?date='.urlencode($date)) ?>">‹ رجوع</a>
</div>

<div class="sheet">
  <div class="topbar"></div>
  <div class="wm">م</div>
  <div class="pad">

    <!-- الترويسة -->
    <div class="head">
      <div class="brand">
        <?php if ($logoRel): ?>
          <img class="brandlogo" src="<?= url($logoRel) ?>" alt="<?= e($company) ?>">
        <?php else: ?>
          <div class="logo">م</div>
        <?php endif; ?>
        <div class="cap">لمقاولات النقل</div>
      </div>
      <div class="doc-tag">
        <span class="word">التقفيل اليومي</span>
        <div class="meta">
          تاريخ اليوم: <b><?= e(dfmt($date)) ?></b><br>
          بنود المصروفات: <b><?= intf(count($expenses)) ?></b> &nbsp;•&nbsp; عهد السائقين: <b><?= intf(count($custodyRows)) ?></b>
        </div>
      </div>
    </div>

    <!-- بطاقات الملخص -->
    <div class="sum">
      <div class="b"><div class="l">باقي رصيد سابق</div><div class="v"><?= money($prevBalance,false) ?></div></div>
      <div class="b"><div class="l">عهد السائقين (بعد خصم <?= intf($rate) ?>)</div><div class="v"><?= money($totCustodyExp,false) ?></div></div>
      <div class="b"><div class="l">إجمالي الصادر اليوم</div><div class="v"><?= money($totOutflow,false) ?></div></div>
      <div class="b gold"><div class="l">رصيد مرحّل للغد</div><div class="v"><?= money($carried,false) ?></div></div>
    </div>

    <!-- المصروفات -->
    <div class="sec"><?= icon('trending-down', 14) ?> المصروفات التي خرجت اليوم <span class="c"><?= intf(count($expenses)) ?> بند</span></div>
    <table class="data">
      <thead><tr>
        <th style="width:58px">رقم الأذون</th>
        <th class="bayan">الشركة</th>
        <th>شركة الشحن</th>
        <th>الجهة</th>
        <th>التوكيل</th>
        <th>الميناء</th>
        <th>البند</th>
        <th class="bayan">البيان</th>
        <th style="width:74px">المبلغ</th>
      </tr></thead>
      <tbody>
        <?php foreach ($expenses as $ex): ?>
          <tr>
            <td class="pno"><?= e($dash($ex['permit_no'])) ?></td>
            <td class="bayan"><?= e($dash($ex['company'])) ?></td>
            <td><?= e($dash($ex['shipping_co'] ?? '')) ?></td>
            <td><?= e($dash($ex['destination'] ?? '')) ?></td>
            <td><?= e($dash($ex['agency'] ?? '')) ?></td>
            <td><?= e($dash($ex['port'] ?? '')) ?></td>
            <td><?= e($dash($ex['stage_label'])) ?></td>
            <td class="bayan"><?= e($dash($ex['note'])) ?></td>
            <td class="num"><?= money($ex['amount'],false) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$expenses): ?><tr><td colspan="9" class="empty">لا مصروفات نقدية بتاريخ اليوم</td></tr><?php endif; ?>
      </tbody>
      <?php if($expenses): ?>
      <tfoot><tr><td class="lbl" colspan="8">إجمالي مصروفات اليوم النقدية</td><td class="num"><?= money($totExpense,false) ?></td></tr></tfoot>
      <?php endif; ?>
    </table>

    <!-- مدفوعات الفيزا (إن وُجدت) -->
    <?php if(!empty($visaRows)): ?>
      <div class="sec"><?= icon('credit-card', 14) ?> مدفوعات الفيزا <span class="c">مدفوعة بالفيزا لا نقدًا — لا تُخصم من الرصيد</span></div>
      <table class="data visa">
        <thead><tr>
          <th style="width:58px">رقم الأذون</th>
          <th class="bayan">الشركة</th>
          <th>البند</th>
          <th class="bayan">البيان</th>
          <th style="width:90px">المبلغ</th>
        </tr></thead>
        <tbody>
          <?php foreach ($visaRows as $v): ?>
            <tr>
              <td class="pno"><?= e($dash($v['permit_no'])) ?></td>
              <td class="bayan"><?= e($dash($v['company'])) ?></td>
              <td><?= e($dash($v['stage_label'])) ?></td>
              <td class="bayan"><?= e($dash($v['note'])) ?></td>
              <td class="num"><?= money($v['amount'],false) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td class="lbl" colspan="4">إجمالي مدفوعات الفيزا</td><td class="num"><?= money($totVisa,false) ?></td></tr></tfoot>
      </table>
    <?php endif; ?>

    <?php if (!empty($rv['available'])): ?>
      <div class="sec"><?= icon('check', 14) ?> حصيلة المراجعة
        <span class="c"><?= !empty($rv['complete']) ? 'كل البنود اتراجعت' : 'فاضل '.intf((int)($rvTotals['blocking_pending'] ?? 0)).' بند بدون علامة' ?></span>
      </div>
      <table class="data">
        <thead><tr><th class="bayan">القسم</th><th>عدد البنود</th><th>اللي اتراجع</th><th>الإجمالي</th></tr></thead>
        <tbody>
        <?php foreach (($rv['groups'] ?? []) as $g): if (!$g['count']) { continue; } ?>
          <tr>
            <td class="bayan"><?= e($g['title']) ?><?= $g['kind'] === 'info' ? ' (لا يمنع الإقفال)' : '' ?></td>
            <td><?= intf($g['count']) ?></td>
            <td><?= intf($g['reviewed']) ?><?= $g['done'] ? ' ✓' : '' ?></td>
            <td class="num"><?= money($g['total'],false) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr>
          <td class="lbl" colspan="2">إجمالي البنود المراجَعة</td>
          <td class="num"><?= intf((int)($rvTotals['reviewed'] ?? 0)) ?> / <?= intf((int)($rvTotals['count'] ?? 0)) ?></td>
          <td class="num"><?= money((float)($rvTotals['cash'] ?? 0),false) ?></td>
        </tr></tfoot>
      </table>
      <?php if (!empty($rv['last_at'])): ?>
        <div class="rvnote">آخر مراجعة: <?= e($rv['last_at']) ?><?= $rv['last_by'] ? ' — بواسطة '.e($rv['last_by']) : '' ?></div>
      <?php endif; ?>
    <?php endif; ?>

    <!-- عهد السائقين -->
    <div class="sec"><?= icon('coins', 14) ?> عهد السائقين <span class="c">مصروف — بعد خصم <?= intf($rate) ?> لكل بوليصة</span></div>
    <table class="data">
      <thead><tr>
        <th>البوليصة</th><th style="width:58px">رقم الأذون</th><th class="bayan">السائق</th>
        <th class="bayan">الشركة</th><th style="width:84px">العهدة</th><th style="width:84px">المخصوم</th>
      </tr></thead>
      <tbody>
        <?php foreach ($custodyRows as $c): ?>
          <tr>
            <td class="pno"><?= e($dash($c['bill_number'])) ?></td>
            <td class="pno"><?= e($dash($c['permit_no'])) ?></td>
            <td class="bayan"><?= e($dash($c['driver'])) ?></td>
            <td class="bayan"><?= e($dash($c['company'])) ?></td>
            <td class="num"><?= money($c['custody'],false) ?></td>
            <td class="num"><?= money($c['deduction'],false) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$custodyRows): ?><tr><td colspan="6" class="empty">لا عهد سائقين بتاريخ اليوم</td></tr><?php endif; ?>
      </tbody>
      <?php if($custodyRows): ?>
      <tfoot><tr><td class="lbl" colspan="4">الإجمالي</td><td class="num"><?= money($totCustody,false) ?></td><td class="num"><?= money($totCustodyExp,false) ?></td></tr></tfoot>
      <?php endif; ?>
    </table>

    <!-- الحساب الختامي -->
    <div class="calc">
      <div class="note-words">
        <div class="t">الرصيد المرحّل للغد بالحروف</div>
        <div class="v"><?= e(tafqit($carried)) ?></div>
      </div>
      <div class="sumbox">
        <div class="r sub"><span class="l">باقي رصيد سابق</span><span class="v"><?= money($prevBalance,false) ?></span></div>
        <?php if($totTopups != 0): ?>
          <div class="r"><span class="l">+ عهدة مضافة للرصيد اليوم</span><span class="v"><?= money($totTopups,false) ?></span></div>
          <div class="r sub"><span class="l">= المتاح</span><span class="v"><?= money($available,false) ?></span></div>
        <?php endif; ?>
        <div class="r neg"><span class="l">− مصروفات نقدية</span><span class="v"><?= money($totExpense,false) ?></span></div>
        <div class="r neg"><span class="l">− عهد السائقين (بعد خصم <?= intf($rate) ?>)</span><span class="v"><?= money($totCustodyExp,false) ?></span></div>
        <div class="r grand"><span class="l">= رصيد مرحّل لليوم التالي</span><span class="v"><?= money($carried,false) ?></span></div>
      </div>
    </div>

    <!-- التوقيعات -->
    <div class="foot">
      <div class="sign"><div class="line">توقيع المسؤول</div></div>
      <div class="sign"><div class="line">المحاسب / الختم</div></div>
    </div>

    <!-- التواصل -->
    <?php if ($phone || $email || $address): ?>
    <div class="contact-bar">
      <div class="contact">
        <?php if ($phone): ?><div class="cline"><span class="ci"><?= icon('send', 14) ?></span> <bdi dir="ltr"><?= e($phone) ?></bdi></div><?php endif; ?>
        <?php if ($email): ?><div class="cline"><span class="ci"><?= icon('send', 14) ?></span> <bdi dir="ltr"><?= e($email) ?></bdi></div><?php endif; ?>
        <?php if ($address): ?><div class="cline"><span class="ci"><?= icon('map-pin', 14) ?></span> <?= e($address) ?></div><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>
  <div class="pagefoot">
    <?= e($company) ?> — لمقاولات النقل &nbsp;•&nbsp; تقفيل يوم <?= e(dfmt($date)) ?>
    &nbsp;•&nbsp; صدر بتاريخ <?= e(dfmt(date('Y-m-d'))) ?>
  </div>
</div>

</body>
</html>
