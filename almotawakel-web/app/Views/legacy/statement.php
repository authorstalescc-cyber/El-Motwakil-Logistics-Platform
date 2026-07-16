<?php
use App\Core\App;

/**
 * كشف حساب أرشيفي لصاحب البيان — من جدول legacy_bawalis (قراءة فقط).
 * معاينة/طباعة فورية: لا يُحفَظ مستند ولا يمسّ أي حساب في النظام الحالي.
 * A4 عرضي (landscape) لعرض كل بيانات البوليصة.
 * @var array  $st     ['owner','variants','rows_due','rows_paid','sums']
 * @var string $title  يُحقَن من Controller::render عبر extract()
 */
$company  = App::setting('company_name', 'شركة المتوكل');
$currency = App::setting('currency', 'ج.م');
$phone    = App::setting('company_phone', '');
$email    = App::setting('company_email', '');
$address  = App::setting('company_address', '');

$due   = $st['rows_due'];
$paid  = $st['rows_paid'];
$sums  = $st['sums'];
$today = date('Y-m-d');

$logoRel = null;
foreach (['assets/img/logo.png', 'assets/img/logo.jpg', 'assets/img/logo.jpeg'] as $cand) {
    if (is_file(App::basePath('public/' . $cand))) { $logoRel = $cand; break; }
}

/**
 * أعمدة الجدول الموحّدة للقسمين: [key, label, type]
 * type: text | date | money | calc (محسوب في PHP: due/paid/net)
 * كل بيانات هوية/سيارة/تحصيل البوليصة — بدون مصروفات الشركة الداخلية (دور سواق/كرتات…).
 */
$cols = [
    ['bill_number',          'رقم البوليصة', 'text'],
    ['bill_date',            'التاريخ',      'date'],
    ['classification',       'التصنيف',      'text'],
    ['company',              'الشركة',       'text'],
    ['destination',          'الجهة',        'text'],
    ['container_no',         'الحاوية',      'text'],
    ['car_no',               'السيارة',      'text'],
    ['driver',               'السائق',       'text'],
    ['freight',              'نولون',        'money'],
    ['custody',              'عهدة',         'money'],
    ['transfer',             'تحويلة',       'money'],
    ['discount',             'خصم',          'money'],
    ['overnight_collection', 'بياتة',        'money'],
    ['due',                  'المستحق',      'calc'],
    ['paid',                 'استلم',        'calc'],
    ['net',                  'الباقي',       'calc'],
];

/** رسم جدول بوالص بنفس الأعمدة (يُستخدم للقسمين) */
$renderTable = function (array $rows) use ($cols) {
    ?>
    <table class="bills">
      <thead><tr>
        <th>#</th>
        <?php foreach ($cols as [$k,$lbl,$t]): ?><th><?= e($lbl) ?></th><?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $i => $b): ?>
        <tr>
          <td><?= $i+1 ?></td>
          <?php foreach ($cols as [$k,$lbl,$t]): $v = $b[$k] ?? null; ?>
            <?php if ($t === 'date'): ?>
              <td><?= dfmt($v) ?></td>
            <?php elseif ($t === 'money' || $t === 'calc'): ?>
              <td class="num <?= $k==='paid' ? 'pos' : ($k==='net' && (float)$v > 0.005 ? 'neg' : '') ?>"><?= money($v, false) ?></td>
            <?php elseif ($k === 'bill_number'): ?>
              <td class="r" style="font-weight:700"><?= ($v !== null && $v !== '') ? e($v) : '—' ?></td>
            <?php elseif ($k === 'classification'): // توحيد عرض التصنيف: «1*40» → «40 قدم» (الأرشيف الخام لا يُلمَس) ?>
              <td><?php $cv = container_norm($v); echo ($cv !== null && $cv !== '') ? e($cv) : '—'; ?></td>
            <?php else: ?>
              <td><?= ($v !== null && $v !== '') ? e($v) : '—' ?></td>
            <?php endif; ?>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr>
        <td colspan="9" style="text-align:left">الإجمالي (<?= intf(count($rows)) ?> بوليصة)</td>
        <?php foreach (array_slice($cols, 8) as [$k,$lbl,$t]): ?>
          <td class="num"><?= money(array_sum(array_map(fn($r) => (float)($r[$k] ?? 0), $rows)), false) ?></td>
        <?php endforeach; ?>
      </tr></tfoot>
    </table>
    <?php
};
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — <?= e($company) ?></title>
<style>
  :root{ --navy:#15355B; --navy2:#1e4a7a; --gold:#C9A227; --gold-l:#f3e6bd;
         --ink:#1A2433; --muted:#5A6472; --line:#E2E7EE; --bg:#F4F6FA; --red:#B4383E; --green:#1f7a52; }
  *{box-sizing:border-box;margin:0;padding:0}
  html,body{background:var(--bg);color:var(--ink);
    font-family:'Segoe UI',Tahoma,'Cairo',sans-serif;direction:rtl;text-align:right;
    -webkit-print-color-adjust:exact;print-color-adjust:exact}
  /* A4 عرضي */
  .sheet{width:297mm;min-height:210mm;margin:16px auto;background:#fff;position:relative;
    box-shadow:0 10px 40px rgba(21,53,91,.15);overflow:hidden}
  .pad{padding:10mm 10mm}
  .topbar{height:8px;background:linear-gradient(90deg,var(--gold),var(--navy))}
  .head{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;
    padding-bottom:12px;border-bottom:2px solid var(--line)}
  .brand{display:flex;align-items:center;gap:12px}
  .brandlogo{height:64px;width:auto;max-width:200px;object-fit:contain}
  .brand .logo{width:64px;height:64px;border-radius:14px;background:var(--navy);color:var(--gold);
    display:flex;align-items:center;justify-content:center;font-size:34px;font-weight:800}
  .brand .cap{color:var(--navy);font-size:14px;font-weight:800;letter-spacing:.5px}
  .inv-tag{text-align:left;min-width:200px}
  .inv-tag .word{display:inline-block;background:var(--gold);color:var(--navy);font-size:18px;font-weight:800;
    padding:8px 16px;border-radius:10px;letter-spacing:.5px}
  .inv-tag .arch{display:inline-block;margin-inline-start:8px;background:var(--navy);color:#fff;font-size:11px;
    font-weight:800;padding:5px 12px;border-radius:8px;vertical-align:middle}
  .inv-tag .meta{margin-top:8px;font-size:12px;color:var(--muted);line-height:1.9}
  .inv-tag .meta b{color:var(--navy);font-size:13px}
  /* صاحب البيان + الملخّص في صفّ واحد لتوفير الارتفاع */
  .top-row{margin-top:12px;display:grid;grid-template-columns:1.2fr 2fr;gap:10px;align-items:stretch}
  .client-card{border:1px solid var(--line);border-radius:12px;padding:11px 14px;background:#fcfdfe}
  .client-card .t{font-size:10.5px;color:var(--gold);font-weight:800;letter-spacing:.5px;margin-bottom:4px}
  .client-card .name{font-size:18px;font-weight:800;color:var(--navy)}
  .client-card .vars{margin-top:4px;font-size:10.5px;color:var(--muted)}
  .sum-cards{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
  .sc{border:1px solid var(--line);border-radius:11px;padding:10px 10px;text-align:center;background:#fcfdfe;
    display:flex;flex-direction:column;justify-content:center}
  .sc .l{font-size:10px;color:var(--muted);font-weight:700;margin-bottom:4px}
  .sc .v{font-size:15px;font-weight:800;color:var(--navy);font-variant-numeric:tabular-nums}
  .sc.paid .v{color:var(--green)} .sc.rem{background:var(--navy)} .sc.rem .l{color:#dfe8f2} .sc.rem .v{color:var(--gold)}
  .sec{margin-top:14px}
  .sec-t{display:flex;justify-content:space-between;align-items:center;background:var(--navy);color:#fff;
    padding:7px 14px;border-radius:9px 9px 0 0;font-size:13px;font-weight:800}
  .sec-t.gold{background:var(--gold);color:var(--navy)}
  .sec-t .n{font-size:11px;font-weight:700;opacity:.9}
  table.bills{width:100%;border-collapse:collapse;font-size:10.5px;border:1px solid var(--line);border-top:none}
  table.bills thead th{background:#eef2f7;color:var(--navy);padding:6px 4px;font-weight:800;text-align:center;
    border-bottom:1px solid var(--line);white-space:nowrap}
  table.bills tbody td{padding:5px 4px;border-bottom:1px solid var(--line);text-align:center;white-space:nowrap}
  table.bills td.r{text-align:right}
  table.bills td.num{font-variant-numeric:tabular-nums}
  table.bills tbody tr:nth-child(even){background:#f8fafc}
  table.bills .pos{color:var(--green);font-weight:700}
  table.bills .neg{color:var(--red);font-weight:800}
  table.bills tfoot td{padding:7px 4px;font-weight:800;background:#f3e6bd;color:#7a5f0e;border-top:2px solid var(--gold)}
  .empty-row{padding:13px;text-align:center;color:var(--muted);border:1px solid var(--line);border-top:none;font-size:12px}
  .words{margin-top:12px;border:1px dashed var(--gold);background:var(--gold-l);border-radius:12px;padding:10px 14px}
  .words .t{font-size:10.5px;color:#8a6d10;font-weight:800;margin-bottom:4px}
  .words .v{font-size:13.5px;font-weight:700;color:var(--navy);line-height:1.7}
  .arch-note{margin-top:10px;border:1px solid var(--line);border-radius:10px;padding:8px 14px;
    font-size:11px;color:var(--muted);background:#fcfdfe}
  .foot{margin-top:16px;padding-top:12px;border-top:2px solid var(--line);
    display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:end}
  .sign{text-align:center}
  .sign .line{margin-top:24px;border-top:1.5px dashed var(--muted);padding-top:5px;color:var(--muted);font-size:12px;font-weight:700}
  .contact-bar{margin-top:14px;padding-top:10px;border-top:1px solid var(--line);display:flex;gap:24px;flex-wrap:wrap}
  .contact-bar .cline{font-size:11.5px;color:var(--ink);font-weight:700;line-height:1.6}
  .contact-bar .ci{display:inline-block;width:18px;color:var(--navy)}
  .pagefoot{text-align:center;color:var(--muted);font-size:10px;padding:8px 0 14px}
  .wm{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
    font-size:170px;font-weight:900;color:rgba(21,53,91,.04);pointer-events:none;z-index:0;
    transform:rotate(-18deg);letter-spacing:20px}
  .pad>*{position:relative;z-index:1}
  .bar{max-width:297mm;margin:0 auto;display:flex;gap:10px;justify-content:center;padding:6px}
  .btn{padding:10px 22px;border:none;border-radius:10px;font-weight:800;font-size:14px;cursor:pointer;
    font-family:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:8px}
  .btn.gold{background:var(--gold);color:var(--navy)}
  .btn.ghost{background:#fff;color:var(--navy);border:1.5px solid var(--navy)}
  @media print{
    @page{size:A4 landscape;margin:0}
    html,body{background:#fff}
    .noprint{display:none!important}
    .sheet{margin:0;box-shadow:none;width:auto;min-height:auto}
    table.bills{font-size:9.5px}
    .sec{break-inside:avoid-page}
    table.bills tr{break-inside:avoid}
  }
</style>
</head>
<body>

<div class="bar noprint">
  <button class="btn gold" onclick="window.print()">🖨️ طباعة / حفظ PDF</button>
  <a class="btn ghost" href="<?= url('legacy') ?>">‹ رجوع للأرشيف</a>
</div>

<div class="sheet">
  <div class="topbar"></div>
  <div class="wm">أرشيف</div>
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
      <div class="inv-tag">
        <span class="word">كشف حساب</span><span class="arch">📦 من أرشيف البوالص القديمة</span>
        <div class="meta">تاريخ الإصدار: <b><?= e(dfmt($today)) ?></b></div>
      </div>
    </div>

    <!-- صاحب البيان + الملخّص (باقي كام) -->
    <div class="top-row">
      <div class="client-card">
        <div class="t">كشف حساب صاحب البيان (أرشيفي)</div>
        <div class="name"><?= e($st['owner']) ?></div>
        <?php if (count($st['variants']) > 1): ?>
          <div class="vars">شاملًا التهجئات: <?= e(implode('، ', $st['variants'])) ?></div>
        <?php endif; ?>
      </div>
      <div class="sum-cards">
        <div class="sc"><div class="l">إجمالي البوالص</div><div class="v"><?= intf($sums['bills']) ?></div></div>
        <div class="sc"><div class="l">إجمالي المستحق له</div><div class="v"><?= money($sums['debt'], false) ?></div></div>
        <div class="sc paid"><div class="l">استلم (المدفوع له)</div><div class="v"><?= money($sums['paid'], false) ?></div></div>
        <div class="sc rem"><div class="l">الباقي له</div><div class="v"><?= money($sums['net'], false) ?></div></div>
      </div>
    </div>

    <!-- (1) البوالص التي عليها باقٍ -->
    <div class="sec">
      <div class="sec-t"><span>البوالص الباقية له بالتفصيل</span><span class="n"><?= intf(count($due)) ?> بوليصة</span></div>
      <?php if (!$due): ?>
        <div class="empty-row">لا توجد بوالص عليها باقٍ — كل المستحقات مسدّدة ✓</div>
      <?php else: $renderTable($due); endif; ?>
    </div>

    <!-- (2) البوالص المسدّدة -->
    <div class="sec">
      <div class="sec-t gold"><span>البوالص المسدّدة بالكامل</span><span class="n"><?= intf(count($paid)) ?> بوليصة</span></div>
      <?php if (!$paid): ?>
        <div class="empty-row">لا توجد بوالص مسدّدة في الأرشيف لهذا الاسم.</div>
      <?php else: $renderTable($paid); endif; ?>
    </div>

    <!-- الباقي له بالحروف -->
    <div class="words">
      <div class="t">الباقي له بالحروف (إجمالي مستحقاته من الأرشيف)</div>
      <div class="v"><?= e(tafqit($sums['net'])) ?></div>
    </div>

    <div class="arch-note">
      ⚠️ هذا كشف <b>أرشيفي</b> من بيانات النظام القديم (قبل التشغيل على البرنامج الحالي) — للمراجعة والمطابقة فقط،
      ولا يدخل في حسابات العهدة أو التقفيل أو مديونيات النظام الحالي.
    </div>

    <!-- التوقيعات -->
    <div class="foot">
      <div class="sign"><div class="line">توقيع صاحب البيان (بالاطّلاع)</div></div>
      <div class="sign"><div class="line">المحاسب / الختم</div></div>
    </div>

    <!-- التواصل -->
    <?php if ($phone || $email || $address): ?>
    <div class="contact-bar">
      <?php if ($phone): ?><div class="cline"><span class="ci">📞</span> <bdi dir="ltr"><?= e($phone) ?></bdi></div><?php endif; ?>
      <?php if ($email): ?><div class="cline"><span class="ci">✉️</span> <bdi dir="ltr"><?= e($email) ?></bdi></div><?php endif; ?>
      <?php if ($address): ?><div class="cline"><span class="ci">📍</span> <?= e($address) ?></div><?php endif; ?>
    </div>
    <?php endif; ?>

  </div>
  <div class="pagefoot">
    <?= e($company) ?> — لمقاولات النقل &nbsp;•&nbsp; كشف أرشيفي صدر بتاريخ <?= e(dfmt($today)) ?>
    &nbsp;•&nbsp; يُرجى مطابقة البيانات والإبلاغ عن أي خطأ خلال 7 أيام
  </div>
</div>

</body>
</html>
