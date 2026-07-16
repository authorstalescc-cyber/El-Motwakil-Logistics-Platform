<?php
use App\Core\App;
use App\Models\VisaCard;
/** كشف حساب كارت فيزا — صفحة مستقلّة للطباعة/حفظ PDF (RTL). @var array $card, $ledger; @var float $balance; @var array $stats */
$company  = App::setting('company_name', 'شركة المتوكل');
$currency = App::setting('currency', 'ج.م');

$depTotal = 0.0; $payTotal = 0.0;
foreach ($ledger as $r) {
    if ($r['amount'] > 0) { $depTotal += $r['amount']; }
    elseif ($r['amount'] < 0) { $payTotal += -$r['amount']; }
}
$fmtTime = fn($ts) => $ts ? substr((string)$ts, 11, 5) : '';

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
<style>
  :root{ --navy:#15355B; --gold:#C9A227; --gold-l:#f3e6bd; --ink:#1A2433; --muted:#5A6472;
         --line:#E2E7EE; --bg:#F4F6FA; --red:#B4383E; --green:#1f7a52; }
  *{box-sizing:border-box;margin:0;padding:0}
  html,body{background:var(--bg);color:var(--ink);font-family:'Segoe UI',Tahoma,'Cairo',sans-serif;
    direction:rtl;text-align:right;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .sheet{width:210mm;min-height:297mm;margin:16px auto;background:#fff;position:relative;
    box-shadow:0 10px 40px rgba(21,53,91,.15);overflow:hidden;padding:20mm 16mm}
  .wm{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;opacity:.05;pointer-events:none;z-index:0}
  .wm img{width:60%}
  .content{position:relative;z-index:1}
  .head{display:flex;align-items:center;gap:16px;border-bottom:3px solid var(--navy);padding-bottom:14px;margin-bottom:6px}
  .head img{height:74px}
  .head .co{font-size:1.5rem;font-weight:900;color:var(--navy)}
  .head .sub{color:var(--muted);font-size:.9rem;margin-top:2px}
  .doc-title{margin-inline-start:auto;text-align:left}
  .doc-title .t{font-size:1.3rem;font-weight:900;color:var(--gold)}
  .doc-title .d{color:var(--muted);font-size:.85rem}
  .card-info{display:flex;gap:10px;flex-wrap:wrap;margin:16px 0}
  .ci{flex:1;min-width:150px;background:var(--bg);border-radius:10px;padding:10px 14px}
  .ci .l{color:var(--muted);font-size:.78rem}
  .ci .v{font-weight:800;font-size:1.05rem}
  .totals{display:flex;gap:10px;margin:14px 0}
  .tt{flex:1;border:1px solid var(--line);border-radius:10px;padding:12px 14px;text-align:center}
  .tt .l{color:var(--muted);font-size:.8rem}
  .tt .v{font-weight:900;font-size:1.25rem;margin-top:2px}
  .tt.bal{background:var(--navy);color:#fff;border-color:var(--navy)}
  table{width:100%;border-collapse:collapse;margin-top:8px;font-size:.85rem}
  th,td{border:1px solid var(--line);padding:7px 8px;text-align:center}
  th{background:var(--navy);color:#fff;font-weight:700}
  td.r{text-align:right}
  .pos{color:var(--green);font-weight:700}.neg{color:var(--red);font-weight:700}
  tfoot td{background:var(--gold-l);font-weight:800}
  .foot{margin-top:22px;color:var(--muted);font-size:.82rem;display:flex;justify-content:space-between;border-top:1px solid var(--line);padding-top:10px}
  .noprint{margin:14px auto;width:210mm;display:flex;gap:10px;justify-content:center}
  .btn{padding:9px 18px;border-radius:8px;border:1px solid var(--navy);background:var(--navy);color:#fff;
    font-weight:700;cursor:pointer;text-decoration:none;font-family:inherit}
  .btn.g{background:#fff;color:var(--navy)}
  @media print{ body{background:#fff} .sheet{box-shadow:none;margin:0} .noprint{display:none} }
</style>
</head>
<body>
<div class="noprint">
  <button class="btn" onclick="window.print()">🖨️ طباعة / حفظ PDF</button>
  <a class="btn g" href="<?= url('office/'.$card['id']) ?>">‹ رجوع للكارت</a>
</div>

<div class="sheet">
  <?php if ($logoRel): ?><div class="wm"><img src="<?= url($logoRel) ?>" alt=""></div><?php endif; ?>
  <div class="content">
    <div class="head">
      <?php if ($logoRel): ?><img src="<?= url($logoRel) ?>" alt="<?= e($company) ?>"><?php endif; ?>
      <div>
        <div class="co"><?= e($company) ?></div>
        <div class="sub">لمقاولات النقل</div>
      </div>
      <div class="doc-title">
        <div class="t">كشف حساب كارت فيزا</div>
        <div class="d">صادر في: <?= e(dfmt(work_date())) ?></div>
      </div>
    </div>

    <div class="card-info">
      <div class="ci"><div class="l">الكارت</div><div class="v"><?= e($card['name']) ?></div></div>
      <div class="ci"><div class="l">صاحب الكارت</div><div class="v"><?= e($card['owner'] ?: '—') ?></div></div>
      <div class="ci"><div class="l">البنك</div><div class="v"><?= e($card['bank'] ?: '—') ?></div></div>
    </div>

    <div class="totals">
      <div class="tt"><div class="l">إجمالي الشحنات</div><div class="v pos"><?= money($depTotal) ?></div></div>
      <div class="tt"><div class="l">إجمالي المدفوع بالفيزا (<?= intf($stats['count']) ?> عملية)</div><div class="v neg"><?= money($payTotal) ?></div></div>
      <div class="tt bal"><div class="l" style="color:#cfe0f5">الرصيد المتاح</div><div class="v"><?= money($balance) ?></div></div>
    </div>

    <table>
      <thead><tr>
        <th style="width:18%">التاريخ / الوقت</th>
        <th style="width:30%">الحركة / البيان</th>
        <th style="width:18%">الطرف</th>
        <th style="width:12%">شحن (+)</th>
        <th style="width:12%">مدفوع (−)</th>
        <th style="width:12%">الرصيد</th>
      </tr></thead>
      <tbody>
        <?php foreach ($ledger as $r): ?>
          <tr>
            <td><?= $r['d'] ? e(dfmt($r['d'])) : '—' ?><?= $fmtTime($r['ts']??null)?'<br><small>'.e($fmtTime($r['ts'])).'</small>':'' ?></td>
            <td class="r">
              <?php if($r['kind']==='opening'): ?>رصيد افتتاحي
              <?php elseif($r['kind']==='deposit'): ?>شحن رصيد
              <?php else: ?><?= e(VisaCard::SOURCES[$r['source']] ?? 'فيزا') ?> — <?= e($r['label']) ?><?php endif; ?>
            </td>
            <td><?= e($r['party'] ?: '—') ?></td>
            <td class="pos"><?= $r['amount']>0 && $r['kind']!=='opening' ? money($r['amount'],false) : '' ?></td>
            <td class="neg"><?= $r['amount']<0 ? money(-$r['amount'],false) : '' ?></td>
            <td style="font-weight:800"><?= money($r['running'],false) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$ledger): ?><tr><td colspan="6" style="padding:20px;color:var(--muted)">لا حركات على هذا الكارت.</td></tr><?php endif; ?>
      </tbody>
      <tfoot><tr>
        <td colspan="3" style="text-align:left">الإجماليات</td>
        <td class="pos"><?= money($depTotal,false) ?></td>
        <td class="neg"><?= money($payTotal,false) ?></td>
        <td><?= money($balance,false) ?></td>
      </tr></tfoot>
    </table>

    <div class="foot">
      <span><?= e($company) ?> — كشف حساب داخلي</span>
      <span>العملة: <?= e($currency) ?></span>
    </div>
  </div>
</div>
</body>
</html>
