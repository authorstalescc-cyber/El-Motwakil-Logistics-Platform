<?php
use App\Core\App;
use App\Models\Rep;
/**
 * إيصال تسوية حساب مندوب — صفحة مستقلة للطباعة/حفظ PDF (RTL، بلا layout).
 * @var array $rep @var array $s @var array $entries @var array $kinds
 */
$company  = App::setting('company_name', 'شركة المتوكل');
$currency = App::setting('currency', 'ج.م');
$phone    = App::setting('company_phone', '');
$address  = App::setting('company_address', '');

$logoRel = null;
foreach (['assets/img/logo.png', 'assets/img/logo.jpg', 'assets/img/logo.jpeg'] as $cand) {
    if (is_file(App::basePath('public/' . $cand))) { $logoRel = $cand; break; }
}
$credit = Rep::CREDIT_KINDS;
$period = ($s['period_from'] || $s['period_to'])
    ? (dfmt($s['period_from']) . ' – ' . dfmt($s['period_to'])) : 'كل البنود غير المتحاسبة';
$payDate = $s['pay_date'] ?: substr($s['settled_at'], 0, 10);
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — <?= e($company) ?></title>
<style>
  :root{ --navy:#15355B; --gold:#C9A227; --gold-l:#f3e6bd; --ink:#1A2433; --muted:#5A6472;
         --line:#E2E7EE; --bg:#F4F6FA; --red:#B4383E; --green:#1f7a52; }
  *{box-sizing:border-box;margin:0;padding:0}
  html,body{background:var(--bg);color:var(--ink);font-family:'Segoe UI',Tahoma,'Cairo',sans-serif;
    direction:rtl;text-align:right;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .sheet{width:210mm;min-height:297mm;margin:16px auto;background:#fff;position:relative;
    box-shadow:0 10px 40px rgba(21,53,91,.15);overflow:hidden}
  .pad{padding:14mm 12mm}
  .topbar{height:8px;background:linear-gradient(90deg,var(--gold),var(--navy))}
  .head{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;
    padding-bottom:16px;border-bottom:2px solid var(--line)}
  .brand{display:flex;flex-direction:column;align-items:center;gap:6px}
  .brandlogo{height:78px;width:auto;max-width:230px;object-fit:contain}
  .brand .logo{width:78px;height:78px;border-radius:16px;background:var(--navy);color:var(--gold);
    display:flex;align-items:center;justify-content:center;font-size:42px;font-weight:800}
  .brand .cap{color:var(--navy);font-size:15px;font-weight:800}
  .inv-tag{text-align:left;min-width:200px}
  .inv-tag .word{display:inline-block;background:var(--gold);color:var(--navy);font-size:20px;font-weight:800;
    padding:9px 20px;border-radius:10px}
  .inv-tag .meta{margin-top:12px;font-size:12.5px;color:var(--muted);line-height:2}
  .inv-tag .meta b{color:var(--navy);font-size:14px}
  .rep-card{margin-top:16px;border:1px solid var(--line);border-radius:12px;padding:13px 16px;background:#fcfdfe;
    display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px}
  .rep-card .t{font-size:11px;color:var(--gold);font-weight:800;margin-bottom:5px}
  .rep-card .name{font-size:19px;font-weight:800;color:var(--navy)}
  .rep-card .sub{font-size:12px;color:var(--muted);margin-top:3px}
  .sec{margin-top:18px}
  .sec-t{background:var(--navy);color:#fff;padding:8px 14px;border-radius:9px 9px 0 0;font-size:13.5px;font-weight:800}
  table.bills{width:100%;border-collapse:collapse;font-size:11.5px;border:1px solid var(--line);border-top:none}
  table.bills thead th{background:#eef2f7;color:var(--navy);padding:7px 6px;font-weight:800;text-align:center;border-bottom:1px solid var(--line)}
  table.bills tbody td{padding:7px 6px;border-bottom:1px solid var(--line);text-align:center}
  table.bills td.r{text-align:right} table.bills td.num{font-variant-numeric:tabular-nums}
  table.bills tbody tr:nth-child(even){background:#f8fafc}
  table.bills .pos{color:var(--green);font-weight:700} table.bills .neg{color:var(--red);font-weight:800}
  .totals{margin-top:14px;display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
  .tc{border:1px solid var(--line);border-radius:11px;padding:11px 12px;text-align:center;background:#fcfdfe}
  .tc .l{font-size:10.5px;color:var(--muted);font-weight:700;margin-bottom:4px}
  .tc .v{font-size:16px;font-weight:800;color:var(--navy);font-variant-numeric:tabular-nums}
  .tc.net{background:var(--navy)} .tc.net .l{color:#dfe8f2} .tc.net .v{color:var(--gold);font-size:19px}
  .words{margin-top:16px;border:1px dashed var(--gold);background:var(--gold-l);border-radius:12px;padding:12px 16px}
  .words .t{font-size:11px;color:#8a6d10;font-weight:800;margin-bottom:5px}
  .words .v{font-size:14px;font-weight:700;color:var(--navy);line-height:1.8}
  .foot{margin-top:26px;padding-top:16px;border-top:2px solid var(--line);display:grid;grid-template-columns:1fr 1fr;gap:40px}
  .sign{text-align:center}
  .sign .line{margin-top:30px;border-top:1.5px dashed var(--muted);padding-top:6px;color:var(--muted);font-size:12.5px;font-weight:700}
  .pagefoot{text-align:center;color:var(--muted);font-size:10.5px;padding:10px 0 16px}
  .wm{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
    font-size:340px;font-weight:900;color:rgba(21,53,91,.035);pointer-events:none;z-index:0}
  .pad>*{position:relative;z-index:1}
  .bar{max-width:210mm;margin:0 auto;display:flex;gap:10px;justify-content:center;padding:6px}
  .btn{padding:10px 22px;border:none;border-radius:10px;font-weight:800;font-size:14px;cursor:pointer;
    font-family:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:8px}
  .btn.gold{background:var(--gold);color:var(--navy)} .btn.ghost{background:#fff;color:var(--navy);border:1.5px solid var(--navy)}
  @media print{ @page{size:A4;margin:0} html,body{background:#fff} .noprint{display:none!important}
    .sheet{margin:0;box-shadow:none;width:auto;min-height:auto} }
</style>
</head>
<body>

<div class="bar noprint">
  <button class="btn gold" onclick="window.print()">🖨️ طباعة / حفظ PDF</button>
  <a class="btn ghost" href="<?= url('reps/'.$rep['id']) ?>">‹ رجوع لكارت المندوب</a>
</div>

<div class="sheet">
  <div class="topbar"></div>
  <div class="wm">م</div>
  <div class="pad">

    <div class="head">
      <div class="brand">
        <?php if ($logoRel): ?><img class="brandlogo" src="<?= url($logoRel) ?>" alt="<?= e($company) ?>">
        <?php else: ?><div class="logo">م</div><?php endif; ?>
        <div class="cap">لمقاولات النقل</div>
      </div>
      <div class="inv-tag">
        <span class="word">تسوية حساب مندوب</span>
        <div class="meta">
          رقم التسوية: <b>#<?= e($s['id']) ?></b><br>
          تاريخ الدفع: <b><?= e(dfmt($payDate)) ?></b>
        </div>
      </div>
    </div>

    <div class="rep-card">
      <div>
        <div class="t">المندوب</div>
        <div class="name"><?= e($rep['name']) ?></div>
        <div class="sub"><?php if($rep['phone']): ?><bdi dir="ltr"><?= e($rep['phone']) ?></bdi> • <?php endif; ?>الفترة: <?= e($period) ?></div>
      </div>
      <div style="text-align:left">
        <div class="t">طريقة الدفع</div>
        <div class="name" style="font-size:15px"><?= $s['is_visa']?'💳 فيزا':'💵 نقدًا' ?></div>
      </div>
    </div>

    <!-- بنود الفترة -->
    <div class="sec">
      <div class="sec-t">بنود الفترة</div>
      <?php if(!$entries): ?>
        <div style="padding:14px;text-align:center;color:var(--muted);border:1px solid var(--line);border-top:none">لا بنود مفصّلة.</div>
      <?php else: ?>
        <table class="bills">
          <thead><tr><th>#</th><th>التاريخ</th><th>النوع</th><th>البيان</th><th>له (+)</th><th>عليه (−)</th></tr></thead>
          <tbody>
          <?php foreach ($entries as $i => $en): $isCredit = in_array($en['kind'], $credit, true); ?>
            <tr>
              <td><?= $i+1 ?></td>
              <td><?= $en['d']?dfmt($en['d']):'—' ?></td>
              <td><?= e($kinds[$en['kind']] ?? $en['kind']) ?></td>
              <td class="r"><?= e($en['note'] ?: '—') ?></td>
              <td class="num pos"><?= $isCredit?money($en['amount'],false):'—' ?></td>
              <td class="num neg"><?= !$isCredit?money($en['amount'],false):'—' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <!-- الإجماليات -->
    <div class="totals">
      <div class="tc"><div class="l">إجمالي المستحقات</div><div class="v"><?= money($s['total_due'],false) ?></div></div>
      <div class="tc"><div class="l">− الخصومات</div><div class="v"><?= money($s['total_deductions'],false) ?></div></div>
      <div class="tc"><div class="l">− السُلف المصروفة</div><div class="v"><?= money($s['total_advances'],false) ?></div></div>
      <div class="tc net"><div class="l">الصافي المدفوع</div><div class="v"><?= money($s['net'],false) ?></div></div>
    </div>

    <div class="words">
      <div class="t">الصافي المدفوع بالحروف</div>
      <div class="v"><?= e(tafqit($s['net'])) ?></div>
    </div>

    <?php if(!empty($s['note'])): ?>
      <div class="words" style="border-style:solid;background:#fcfdfe;border-color:var(--line)">
        <div class="t" style="color:var(--gold)">ملاحظة</div><div class="v"><?= e($s['note']) ?></div>
      </div>
    <?php endif; ?>

    <div class="foot">
      <div class="sign"><div class="line">توقيع المندوب (بالاستلام)</div></div>
      <div class="sign"><div class="line">المحاسب / الختم</div></div>
    </div>

  </div>
  <div class="pagefoot">
    <?= e($company) ?> — لمقاولات النقل
    <?php if($phone): ?>&nbsp;•&nbsp; <bdi dir="ltr"><?= e($phone) ?></bdi><?php endif; ?>
    <?php if($address): ?>&nbsp;•&nbsp; <?= e($address) ?><?php endif; ?>
    &nbsp;•&nbsp; صدر بتاريخ <?= e(dfmt($payDate)) ?>
  </div>
</div>

</body>
</html>
