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
<?php /* ⚠️ كان فيه `:root` محلي **ناقص** (`--font`/`--fs-*`/`--radius-*` مش
         معرَّفين) فالمستند كان بيسقط لخط ومقاسات المتصفح **بصمت** — وهو
         الوحيد الخارج عن توحيد `print_base`. دلوقتي بياخد الهوية من المصدر
         الواحد زي كل مستندات A4 (محروس في `verify_dark_print` §⑥+⑥ب). */ ?>
<?php partial('print_base'); ?>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  html,body{background:var(--bg);color:var(--ink);font-family:var(--font);
    direction:rtl;text-align:right;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .sheet{width:210mm;min-height:297mm;margin:16px auto;background:#fff;position:relative;
    box-shadow:0 10px 40px rgba(21,53,91,.15);overflow:hidden;padding:20mm 16mm}
  .wm{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;opacity:.05;pointer-events:none;z-index:0}
  .wm img{width:60%}
  .content{position:relative;z-index:1}
  .head{display:flex;align-items:center;gap:16px;border-bottom:3px solid var(--navy);padding-bottom:14px;margin-bottom:6px}
  .head img{height:74px}
  .head .co{font-size:var(--fs-6);font-weight:700;color:var(--navy)}
  .head .sub{color:var(--muted);font-size:var(--fs-3);margin-top:2px}
  .doc-title{margin-inline-start:auto;text-align:left}
  .doc-title .t{font-size:var(--fs-6);font-weight:700;color:var(--gold)}
  .doc-title .d{color:var(--muted);font-size:var(--fs-2)}
  .card-info{display:flex;gap:10px;flex-wrap:wrap;margin:16px 0}
  .ci{flex:1;min-width:150px;background:var(--bg);border-radius:var(--radius-2);padding:10px 14px}
  .ci .l{color:var(--muted);font-size:var(--fs-1)}
  .ci .v{font-weight:600;font-size:var(--fs-4)}
  .totals{display:flex;gap:10px;margin:14px 0}
  .tt{flex:1;border:1px solid var(--line);border-radius:var(--radius-2);padding:12px 14px;text-align:center}
  .tt .l{color:var(--muted);font-size:var(--fs-2)}
  .tt .v{font-weight:700;font-size:var(--fs-5);margin-top:2px}
  .tt.bal{background:var(--navy);color:#fff;border-color:var(--navy)}
  table{width:100%;border-collapse:collapse;margin-top:8px;font-size:var(--fs-2)}
  th,td{border:1px solid var(--line);padding:7px 8px;text-align:center}
  th{background:var(--navy);color:#fff;font-weight:500}
  td.r{text-align:right}
  .pos{color:var(--green);font-weight:500}.neg{color:var(--red);font-weight:500}
  tfoot td{background:var(--gold-l);font-weight:600}
  .foot{margin-top:22px;color:var(--muted);font-size:var(--fs-2);display:flex;justify-content:space-between;border-top:1px solid var(--line);padding-top:10px}
  .noprint{margin:14px auto;width:210mm;display:flex;gap:10px;justify-content:center}
  .btn{padding:9px 18px;border-radius:var(--radius-1);border:1px solid var(--navy);background:var(--navy);color:#fff;
    font-weight:500;cursor:pointer;text-decoration:none;font-family:inherit}
  .btn.g{background:#fff;color:var(--navy)}
  @media print{ body{background:#fff} .sheet{box-shadow:none;margin:0} .noprint{display:none} }
</style>
</head>
<body>
<div class="noprint">
  <button class="btn" onclick="window.print()"><?= icon('file-text', 14) ?> طباعة / حفظ PDF</button>
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
      <div class="tt bal"><div class="l p5-on-navy">الرصيد المتاح</div><div class="v"><?= money($balance) ?></div></div>
    </div>

    <table>
      <thead><tr>
        <th class="w18p">التاريخ / الوقت</th>
        <th class="w30p">الحركة / البيان</th>
        <th class="w18p">الطرف</th>
        <th class="w12p">شحن (+)</th>
        <th class="w12p">مدفوع (−)</th>
        <th class="w12p">الرصيد</th>
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
            <td class="dbt-tot"><?= money($r['running'],false) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$ledger): ?><tr><td colspan="6" class="dag-pad-lg dbt-none">لا حركات على هذا الكارت.</td></tr><?php endif; ?>
      </tbody>
      <tfoot><tr>
        <td colspan="3" class="end">الإجماليات</td>
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
