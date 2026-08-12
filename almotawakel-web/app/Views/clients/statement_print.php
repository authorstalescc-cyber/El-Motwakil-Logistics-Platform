<?php
use App\Core\App;

/**
 * كشف حساب العميل — صفحة A4 مستقلة للطباعة/حفظ PDF (RTL، بلا layout).
 *
 * ⚠️ المستند ده **بيروح للعميل**، فهويته لازم تبقى نفس باقي مستندات النظام —
 *    التوكنز كلها من `partials/print_base` (مصدر واحد للهوية) و**ممنوع اسم
 *    خط مكتوب بالإيد** (`verify_typography` بيحرس).
 * ⚠️ **ولا مصدر خارجي** (QR/خطوط/صور من الإنترنت): الكشف فيه اسم العميل
 *    وأرقام حسابه، وأي طلب برّه بيسرّبها — نفس قرار المرحلة ٣ من خطة الأمان.
 *
 * @var array $agent @var array $ledger @var array $aging @var array $head
 * @var string $from @var string $to
 */
$company  = App::setting('company_name', 'شركة المتوكل');
$currency = App::setting('currency', 'ج.م');
$phone    = App::setting('company_phone', '');
$email    = App::setting('company_email', '');
$address  = App::setting('company_address', '');

$logoRel = null;
foreach (['assets/img/logo.png', 'assets/img/logo.jpg', 'assets/img/logo.jpeg'] as $cand) {
    if (is_file(App::basePath('public/' . $cand))) { $logoRel = $cand; break; }
}

$rows   = $ledger['rows'];
$period = ($from !== '' || $to !== '')
    ? (($from !== '' ? dfmt($from) : 'البداية') . ' – ' . ($to !== '' ? dfmt($to) : 'النهاية'))
    : 'كل الحركات';
$id = (int) $agent['id'];
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — <?= e($company) ?></title>
<?php partial('print_base'); ?>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  html,body{background:var(--bg);color:var(--ink);font-family:var(--font);
    direction:rtl;text-align:right;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .sheet{width:210mm;min-height:297mm;margin:16px auto;background:#fff;position:relative;
    box-shadow:0 10px 40px rgba(21,53,91,.15);overflow:hidden}
  .pad{padding:12mm 11mm}
  .topbar{height:8px;background:linear-gradient(90deg,var(--gold),var(--navy))}
  .head{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;
    padding-bottom:12px;border-bottom:2px solid var(--line)}
  .brand{display:flex;align-items:center;gap:10px}
  .brandlogo{height:62px;width:auto;max-width:200px;object-fit:contain}
  .brand .logo{width:62px;height:62px;border-radius:var(--radius-2);background:var(--navy);color:var(--gold);
    display:flex;align-items:center;justify-content:center;font-size:34px;font-weight:600}
  .brand .cap{color:var(--navy);font-size:13px;font-weight:600}
  .tag{text-align:left;min-width:190px}
  .tag .word{display:inline-block;background:var(--gold);color:var(--navy);font-size:20px;font-weight:600;
    padding:8px 20px;border-radius:var(--radius-2)}
  .tag .meta{margin-top:10px;font-size:12px;color:var(--muted);line-height:1.9}
  .tag .meta b{color:var(--navy);font-size:13px}
  .client{margin-top:12px;border:1px solid var(--line);border-radius:var(--radius-2);padding:10px 14px;background:#fcfdfe;
    display:flex;justify-content:space-between;align-items:flex-end;gap:14px;flex-wrap:wrap}
  .client .t{font-size:10.5px;color:var(--gold);font-weight:600;margin-bottom:4px}
  .client .name{font-size:19px;font-weight:600;color:var(--navy)}
  .client .cinfo{font-size:11.5px;color:var(--muted);line-height:1.8;text-align:left}
  .cards{margin-top:10px;display:grid;grid-template-columns:repeat(4,1fr);gap:8px}
  .c{border:1px solid var(--line);border-radius:var(--radius-2);padding:9px 10px;text-align:center;background:#fcfdfe}
  .c .l{font-size:10.5px;color:var(--muted);font-weight:500;margin-bottom:4px}
  .c .v{font-size:15.5px;font-weight:600;color:var(--navy);font-variant-numeric:tabular-nums}
  .c.paid .v{color:var(--green)}
  .c.late .v{color:var(--red)}
  .c.rem{background:var(--navy)} .c.rem .l{color:#dfe8f2} .c.rem .v{color:var(--gold)}
  .sec{margin-top:13px}
  .sec-t{display:flex;justify-content:space-between;align-items:center;background:var(--navy);color:#fff;
    padding:7px 13px;border-radius:9px 9px 0 0;font-size:13px;font-weight:600}
  .sec-t .n{font-size:11px;font-weight:500;opacity:.9}
  table.led{width:100%;border-collapse:collapse;font-size:10.5px;border:1px solid var(--line);border-top:none}
  table.led thead th{background:#eef2f7;color:var(--navy);padding:6px 4px;font-weight:600;text-align:center;
    border-bottom:1px solid var(--line);white-space:nowrap}
  table.led tbody td{padding:5px 4px;border-bottom:1px solid var(--line);text-align:center}
  table.led td.r{text-align:right}
  table.led td.num{font-variant-numeric:tabular-nums;white-space:nowrap}
  table.led tbody tr:nth-child(even){background:#f8fafc}
  table.led .pos{color:var(--green);font-weight:500}
  table.led .neg{color:var(--red);font-weight:600}
  table.led .mut{color:var(--muted)}
  table.led tfoot td{padding:8px 6px;font-weight:600;background:#f3e6bd;color:#7a5f0e;border-top:2px solid var(--gold)}
  .aging{margin-top:12px;display:grid;grid-template-columns:repeat(4,1fr);gap:8px}
  .ag{border:1px solid var(--line);border-radius:var(--radius-2);padding:8px 10px;background:#fcfdfe;text-align:center}
  .ag .l{font-size:10px;color:var(--muted);font-weight:500}
  .ag .v{font-size:14px;font-weight:600;color:var(--navy);font-variant-numeric:tabular-nums;margin-top:3px}
  .ag.hot{border-color:var(--red)} .ag.hot .v{color:var(--red)}
  .empty-row{padding:14px;text-align:center;color:var(--muted);border:1px solid var(--line);border-top:none;font-size:12px}
  .words{margin-top:11px;border:1px dashed var(--gold);background:var(--gold-l);border-radius:var(--radius-2);padding:9px 14px}
  .words .t{font-size:10.5px;color:var(--gold-d);font-weight:600;margin-bottom:4px}
  .words .v{font-size:13.5px;font-weight:500;color:var(--navy);line-height:1.8}
  .contact{margin-top:14px;padding-top:10px;border-top:1px solid var(--line);
    display:flex;justify-content:space-between;gap:16px;font-size:11.5px;color:var(--ink);line-height:1.8}
  .sign{margin-top:16px;display:flex;justify-content:space-between;gap:30px}
  .sign div{flex:1;text-align:center;font-size:11.5px;color:var(--muted)}
  .sign .line{margin-top:26px;border-top:1px solid var(--line);padding-top:5px;color:var(--navy);font-weight:600}
  .pagefoot{text-align:center;color:var(--muted);font-size:10px;padding:8px 0 14px}
  .wm{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
    font-size:340px;font-weight:700;color:rgba(21,53,91,.035);pointer-events:none;z-index:0}
  .pad>*{position:relative;z-index:1}
  .bar{max-width:210mm;margin:0 auto;display:flex;gap:10px;justify-content:center;padding:6px;flex-wrap:wrap}
  .btn{padding:10px 20px;border:none;border-radius:var(--radius-2);font-weight:600;font-size:14px;cursor:pointer;
    font-family:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:8px}
  .btn.gold{background:var(--gold);color:var(--navy)}
  .btn.ghost{background:#fff;color:var(--navy);border:1.5px solid var(--navy)}
  .rng{display:flex;align-items:center;gap:6px;background:#fff;border:1.5px solid var(--navy);border-radius:var(--radius-2);
    padding:6px 12px;font-size:12.5px;color:var(--navy);font-family:inherit}
  .rng input{font-family:inherit;font-size:12.5px;border:1px solid var(--line);border-radius:var(--radius-1);padding:4px 6px}
  @media print{
    @page{size:A4 portrait;margin:0}
    html,body{background:#fff}
    .noprint{display:none!important}
    .sheet{margin:0;box-shadow:none;width:auto;min-height:auto}
    table.led tr{break-inside:avoid}
  }
</style>
</head>
<body>

<div class="bar noprint">
  <button class="btn gold" onclick="window.print()"><?= icon('file-text', 14) ?> طباعة / حفظ PDF</button>
  <?php /* الرجوع يوصّل **لحساب العميل** لا للشاشة اللي طلبت الكشف
           (قاعدة التنقّل: الرجوع يروح للي اتصنع — verify_navigation). */ ?>
  <a class="btn ghost" href="<?= url('clients/' . $id) ?>">‹ رجوع لحساب العميل</a>
  <form class="rng" method="get" action="<?= url('clients/' . $id . '/statement') ?>">
    <span>من</span><input type="date" name="from" value="<?= e($from) ?>">
    <span>إلى</span><input type="date" name="to" value="<?= e($to) ?>">
    <button class="btn ghost" type="submit">عرض</button>
  </form>
</div>

<div class="sheet">
  <div class="topbar"></div>
  <div class="wm">م</div>
  <div class="pad">

    <div class="head">
      <div class="brand">
        <?php if ($logoRel): ?>
          <img class="brandlogo" src="<?= url($logoRel) ?>" alt="<?= e($company) ?>">
        <?php else: ?>
          <div class="logo">م</div>
        <?php endif; ?>
        <div class="cap">لمقاولات النقل</div>
      </div>
      <div class="tag">
        <span class="word">كشف حساب</span>
        <div class="meta">
          التاريخ: <b><?= e(dfmt(work_date())) ?></b><br>
          الفترة: <b><?= e($period) ?></b>
        </div>
      </div>
    </div>

    <div class="client">
      <div>
        <div class="t">حساب العميل</div>
        <div class="name"><?= e($agent['name']) ?></div>
      </div>
      <div class="cinfo">
        <?php if (!empty($agent['phone'])): ?>هاتف: <?= e($agent['phone']) ?><br><?php endif; ?>
        <?php if (!empty($agent['tax_id'])): ?>رقم ضريبي: <?= e($agent['tax_id']) ?><br><?php endif; ?>
        <?php if (!empty($agent['address'])): ?><?= e($agent['address']) ?><?php endif; ?>
      </div>
    </div>

    <div class="cards">
      <div class="c"><div class="l">رصيد أول المدة</div><div class="v"><?= money($ledger['opening'], false) ?></div></div>
      <div class="c"><div class="l">إجمالي المستحق</div><div class="v"><?= money($ledger['debit'], false) ?></div></div>
      <div class="c paid"><div class="l">إجمالي المسدَّد</div><div class="v"><?= money($ledger['credit'], false) ?></div></div>
      <div class="c rem"><div class="l">الرصيد المستحق</div><div class="v"><?= money($ledger['balance'], false) ?></div></div>
    </div>

    <div class="sec">
      <div class="sec-t"><span>حركة الحساب</span><span class="n"><?= intf(count($rows)) ?> حركة</span></div>
      <?php if (!$rows): ?>
        <div class="empty-row">مفيش حركات في الفترة دي.</div>
      <?php else: ?>
        <table class="led">
          <thead><tr>
            <th>#</th><th>التاريخ</th><th>البيان</th><th>المرجع</th>
            <th>مدين</th><th>دائن</th><th>الرصيد</th>
          </tr></thead>
          <tbody>
            <?php if (abs($ledger['opening']) > 0.005): ?>
              <tr>
                <td class="mut">—</td>
                <td class="mut"><?= e($from !== '' ? dfmt($from) : '') ?></td>
                <td class="r" colspan="2">رصيد ما قبل الفترة</td>
                <td class="num mut">—</td><td class="num mut">—</td>
                <td class="num"><?= money($ledger['opening'], false) ?></td>
              </tr>
            <?php endif; ?>
            <?php foreach ($rows as $i => $r): ?>
              <tr>
                <td><?= intf($i + 1) ?></td>
                <td class="num"><?= $r['date'] ? e(dfmt($r['date'])) : '—' ?></td>
                <td class="r"><?= e($r['label']) ?><?= $r['void'] ? ' (ملغي)' : '' ?><?= $r['note'] !== '' ? ' — ' . e($r['note']) : '' ?></td>
                <td class="num"><?= e($r['ref']) ?></td>
                <td class="num <?= $r['debit'] > 0.005 ? 'neg' : 'mut' ?>"><?= $r['debit'] > 0.005 ? money($r['debit'], false) : '—' ?></td>
                <td class="num <?= $r['credit'] > 0.005 ? 'pos' : 'mut' ?>"><?= $r['credit'] > 0.005 ? money($r['credit'], false) : '—' ?></td>
                <td class="num"><?= money($r['balance'], false) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot><tr>
            <td colspan="4">الإجمالي</td>
            <td class="num"><?= money($ledger['debit'], false) ?></td>
            <td class="num"><?= money($ledger['credit'], false) ?></td>
            <td class="num"><?= money($ledger['balance'], false) ?></td>
          </tr></tfoot>
        </table>
      <?php endif; ?>
    </div>

    <?php if ($head['open_balance'] > 0.005): ?>
      <div class="aging">
        <?php foreach ($aging as $b): ?>
          <div class="ag<?= $b['tone'] === 'red' && $b['value'] > 0.005 ? ' hot' : '' ?>">
            <div class="l"><?= e($b['label']) ?> · <?= intf($b['count']) ?></div>
            <div class="v"><?= money($b['value'], false) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="words">
      <div class="t">الرصيد المستحق بالحروف</div>
      <div class="v"><?= e(tafqit(abs($ledger['balance']))) ?><?= $ledger['balance'] < -0.005 ? ' (رصيد دائن للعميل)' : '' ?></div>
    </div>

    <div class="contact">
      <div>
        <?= e($company) ?><?= $address !== '' ? ' — ' . e($address) : '' ?><br>
        <?= $phone !== '' ? 'هاتف: ' . e($phone) : '' ?><?= $email !== '' ? ' · ' . e($email) : '' ?>
      </div>
      <div><?= e($currency) ?></div>
    </div>

    <div class="sign">
      <div><div class="line">المحاسب</div></div>
      <div><div class="line">العميل</div></div>
    </div>
  </div>
  <div class="pagefoot"><?= e($company) ?> — كشف حساب <?= e($agent['name']) ?></div>
</div>
</body>
</html>
