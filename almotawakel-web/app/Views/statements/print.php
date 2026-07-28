<?php
use App\Core\App;
use App\Models\OwnerStatement;

/** كشف حساب صاحب البيان — صفحة مستقلة للطباعة/حفظ PDF (RTL). @var array $st */
$company  = App::setting('company_name', 'شركة المتوكل');
$currency = App::setting('currency', 'ج.م');
$phone    = App::setting('company_phone', '');
$email    = App::setting('company_email', '');
$address  = App::setting('company_address', '');

$lines   = OwnerStatement::lines($st);
$rem     = $lines['remaining'];
$docNo   = $st['doc_no'] ?: ('#'.$st['id']);

// اللوجو: صورة إن وُجدت، وإلا شعار نصّي
$logoRel = null;
foreach (['assets/img/logo.png', 'assets/img/logo.jpg', 'assets/img/logo.jpeg'] as $cand) {
    if (is_file(App::basePath('public/' . $cand))) { $logoRel = $cand; break; }
}

// ⚠️ اتشال كود الـQR (2026-07-22، المرحلة 3 من خطة الأمان). كان بيبعت **اسم
//    صاحب البيان والباقي له** لـapi.qrserver.com كل مرة يتطبع كشف. شوف الشرح
//    الكامل في `permits/invoice_print.php`. ما ترجّعش أي مصدر خارجي هنا.

$billNo = fn($b) => ($b['number'] ?? '') !== '' ? $b['number'] : '—';
$typeAr = fn($t) => $t === 'warid' ? 'وارد' : 'صادر';
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — <?= e($company) ?></title>
<?php partial('print_base'); ?>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  html,body{background:var(--bg);color:var(--ink);
    font-family:var(--font);direction:rtl;text-align:right;
    -webkit-print-color-adjust:exact;print-color-adjust:exact}
  .sheet{width:297mm;min-height:210mm;margin:16px auto;background:#fff;position:relative;
    box-shadow:0 10px 40px rgba(21,53,91,.15);overflow:hidden}
  .pad{padding:9mm 10mm}
  .topbar{height:8px;background:linear-gradient(90deg,var(--gold),var(--navy))}
  .head{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;
    padding-bottom:11px;border-bottom:2px solid var(--line)}
  .brand{display:flex;align-items:center;gap:10px}
  .brandlogo{height:58px;width:auto;max-width:200px;object-fit:contain}
  .brand .logo{width:58px;height:58px;border-radius:var(--radius-2);background:var(--navy);color:var(--gold);
    display:flex;align-items:center;justify-content:center;font-size:32px;font-weight:600}
  .brand .cap{color:var(--navy);font-size:13px;font-weight:600;letter-spacing:.5px}
  .inv-tag{text-align:left;min-width:200px}
  .inv-tag .word{display:inline-block;background:var(--gold);color:var(--navy);font-size:21px;font-weight:600;
    padding:9px 22px;border-radius:var(--radius-2);letter-spacing:.5px}
  .inv-tag .meta{margin-top:12px;font-size:12.5px;color:var(--muted);line-height:2}
  .inv-tag .meta b{color:var(--navy);font-size:14px}
  .client-card{margin-top:11px;border:1px solid var(--line);border-radius:var(--radius-2);padding:10px 14px;background:#fcfdfe}
  .client-card .t{font-size:11px;color:var(--gold);font-weight:600;letter-spacing:.5px;margin-bottom:5px}
  .client-card .name{font-size:19px;font-weight:600;color:var(--navy)}
  /* بطاقات الملخّص */
  .sum-cards{margin-top:10px;display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
  .sc{border:1px solid var(--line);border-radius:var(--radius-2);padding:8px 10px;text-align:center;background:#fcfdfe}
  .sc .l{font-size:10.5px;color:var(--muted);font-weight:500;margin-bottom:4px}
  .sc .v{font-size:16px;font-weight:600;color:var(--navy);font-variant-numeric:tabular-nums}
  .sc.paid .v{color:var(--green)} .sc.rem{background:var(--navy)} .sc.rem .l{color:#dfe8f2} .sc.rem .v{color:var(--gold)}
  /* الأقسام */
  .sec{margin-top:12px}
  .sec-t{display:flex;justify-content:space-between;align-items:center;background:var(--navy);color:#fff;
    padding:8px 14px;border-radius:9px 9px 0 0;font-size:13.5px;font-weight:600}
  .sec-t.gold{background:var(--gold);color:var(--navy)}
  .sec-t .n{font-size:11.5px;font-weight:500;opacity:.9}
  table.bills{width:100%;border-collapse:collapse;font-size:9px;border:1px solid var(--line);border-top:none}
  table.bills thead th{background:#eef2f7;color:var(--navy);padding:5px 3px;font-weight:600;text-align:center;border-bottom:1px solid var(--line);white-space:nowrap}
  table.bills tbody td{padding:4px 3px;border-bottom:1px solid var(--line);text-align:center;white-space:nowrap}
  table.bills td.r{text-align:right}
  table.bills td.num{font-variant-numeric:tabular-nums}
  table.bills tbody tr:nth-child(even){background:#f8fafc}
  table.bills .pos{color:var(--green);font-weight:500}
  table.bills .neg{color:var(--red);font-weight:600}
  table.bills tfoot td{padding:8px 6px;font-weight:600;background:#f3e6bd;color:#7a5f0e;border-top:2px solid var(--gold)}
  .empty-row{padding:14px;text-align:center;color:var(--muted);border:1px solid var(--line);border-top:none;font-size:12px}
  .words{margin-top:11px;border:1px dashed var(--gold);background:var(--gold-l);border-radius:var(--radius-2);padding:9px 14px}
  .words .t{font-size:11px;color:#8a6d10;font-weight:600;margin-bottom:5px}
  .words .v{font-size:14px;font-weight:500;color:var(--navy);line-height:1.8}
  .note-box{margin-top:12px;border:1px solid var(--line);border-radius:var(--radius-2);padding:9px 14px;font-size:12.5px;color:var(--ink);background:#fcfdfe}
  .note-box .t{color:var(--gold);font-weight:600;font-size:11px;margin-bottom:3px}
  .contact-bar{margin-top:12px;padding-top:10px;border-top:1px solid var(--line);display:flex;align-items:center;gap:16px}
  .qr{flex:0 0 auto;text-align:center}
  .qr img{width:84px;height:84px;border:1px solid var(--line);border-radius:var(--radius-1);padding:3px;background:#fff}
  .qr span{display:block;font-size:9.5px;color:var(--muted);margin-top:2px;font-weight:500}
  .contact{flex:1;display:flex;flex-direction:column;gap:5px}
  .contact .cline{font-size:12px;color:var(--ink);font-weight:500;line-height:1.6}
  .contact .ci{display:inline-block;width:20px;color:var(--navy)}
  .pagefoot{text-align:center;color:var(--muted);font-size:10.5px;padding:10px 0 16px}
  .wm{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
    font-size:340px;font-weight:700;color:rgba(21,53,91,.035);pointer-events:none;z-index:0}
  .pad>*{position:relative;z-index:1}
  .bar{max-width:297mm;margin:0 auto;display:flex;gap:10px;justify-content:center;padding:6px}
  .btn{padding:10px 22px;border:none;border-radius:var(--radius-2);font-weight:600;font-size:14px;cursor:pointer;
    font-family:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:8px}
  .btn.gold{background:var(--gold);color:var(--navy)}
  .btn.ghost{background:#fff;color:var(--navy);border:1.5px solid var(--navy)}
  @media print{
    @page{size:A4 landscape;margin:0}
    html,body{background:#fff}
    .noprint{display:none!important}
    .sheet{margin:0;box-shadow:none;width:auto;min-height:auto}
    table.bills{font-size:8.5px}
    table.bills tr{break-inside:avoid}
  }
</style>
</head>
<body>

<div class="bar noprint">
  <button class="btn gold" onclick="window.print()"><?= icon('file-text', 14) ?> طباعة / حفظ PDF</button>
  <?php /* ⚠️ الرجوع يوصّل لـ**صاحب البيان** لا لفورم إعداد الكشف.
           كان بيوصّل لـ`statements/form?owner=` — أي أن كل من يطبع كشفًا يجد
           نفسه أمام شاشة إصدار كشف جديد معبّأة، فيصدر كشفًا مكررًا.
           القاعدة: الرجوع يذهب لما صُنع لا للشاشة التي صنعته
           (docs/16-navigation-master-plan.md §٤ — يحرسها verify_navigation). */ ?>
  <a class="btn ghost" href="<?= url('settlements?owner=' . urlencode($st['owner'] ?? '')) ?>">‹ رجوع لصاحب البيان</a>
  <a class="btn ghost" href="<?= url('statements') ?>"><?= icon('folder', 14) ?> سجل الكشوف</a>
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
      <div class="inv-tag">
        <span class="word">كشف حساب</span>
        <div class="meta">
          رقم المستند: <b><?= e($docNo) ?></b><br>
          التاريخ: <b><?= e(dfmt($st['statement_date'])) ?></b>
        </div>
      </div>
    </div>

    <!-- صاحب البيان -->
    <div class="client-card">
      <div class="t">كشف حساب صاحب البيان</div>
      <div class="name"><?= e($st['owner'] ?: '(بدون صاحب بيان)') ?></div>
    </div>

    <!-- بطاقات الملخّص (استلم كام / فاضل كام) -->
    <div class="sum-cards">
      <div class="sc"><div class="l">إجمالي البوالص</div><div class="v"><?= intf($st['stat_bills']) ?></div></div>
      <div class="sc paid"><div class="l">استلم (المدفوع له)</div><div class="v"><?= money($st['stat_paid'], false) ?></div></div>
      <div class="sc rem"><div class="l">الباقي له</div><div class="v"><?= money($st['stat_remaining'], false) ?></div></div>
    </div>

    <!-- (1) ملخّص ما استلمه صاحب البيان -->
    <div class="sec">
      <div class="sec-t gold"><span>ملخّص البوالص التي سبق الدفع عليها</span></div>
      <?php if ((int)$st['paid_count'] > 0): ?>
        <table class="bills paid-one-line">
          <thead><tr><th>البيان</th><th>إجمالي المدفوع له</th></tr></thead>
          <tbody><tr>
            <td class="r" style="font-weight:600">عدد <?= intf($st['paid_count']) ?> بوليصة حتى تاريخ <?= e(dfmt($st['statement_date'])) ?></td>
            <td class="num pos" style="font-weight:600"><?= money($st['paid_sum'], false) ?></td>
          </tr></tbody>
        </table>
      <?php else: ?>
        <div class="empty-row">لا توجد بوالص مستلَمة مدرَجة في هذا الكشف.</div>
      <?php endif; ?>
    </div>

    <!-- (2) البوالص الباقية -->
    <div class="sec">
      <div class="sec-t"><span>البوالص الباقية له بالتفصيل</span><span class="n"><?= intf($st['remaining_count']) ?> بوليصة</span></div>
      <?php if (!$rem): ?>
        <div class="empty-row">لا توجد بوالص باقية مدرَجة — كل المستحقات مسدّدة <?= icon('check', 14) ?></div>
      <?php else: ?>
        <table class="bills">
          <thead><tr>
            <th>#</th><th>رقم البوليصة</th><th>النوع</th><th>التاريخ</th><th>الشركة</th>
            <th>الحاوية</th><th>العدد</th><th>الجهة</th><th>التصنيف</th>
            <th>نولون</th><th>عهدة</th><th>تحويلة</th><th>خصم</th><th>بياتة</th><th>استلم</th><th>الباقي</th>
          </tr></thead>
          <tbody>
          <?php foreach ($rem as $i => $b): ?>
            <tr>
              <td><?= $i+1 ?></td>
              <td class="r" style="font-weight:500"><?= e($billNo($b)) ?></td>
              <td><?= $typeAr($b['type'] ?? '') ?></td>
              <td><?= dfmt($b['date'] ?? null) ?></td>
              <td><?= e($b['company'] ?: '—') ?></td>
              <td><?= e($b['container_no'] ?: '—') ?></td>
              <td><?= intf($b['container_count'] ?? 0) ?></td>
              <td><?= e($b['destination'] ?: '—') ?></td>
              <td><?= e($b['classification'] ?: '—') ?></td>
              <td class="num"><?= array_key_exists('freight',$b) ? money($b['freight'], false) : '—' ?></td>
              <td class="num"><?= array_key_exists('custody',$b) ? money($b['custody'], false) : '—' ?></td>
              <td class="num"><?= array_key_exists('transfer',$b) ? money($b['transfer'], false) : '—' ?></td>
              <td class="num"><?= array_key_exists('discount',$b) ? money($b['discount'], false) : '—' ?></td>
              <td class="num"><?= array_key_exists('overnight_collection',$b) ? money($b['overnight_collection'], false) : '—' ?></td>
              <td class="num pos"><?= money($b['paid'], false) ?></td>
              <td class="num neg"><?= money($b['remaining'], false) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr>
            <td colspan="9" style="text-align:left">إجمالي القائمة</td>
            <td class="num"><?= money(array_sum(array_column($rem,'freight')), false) ?></td>
            <td class="num"><?= money(array_sum(array_column($rem,'custody')), false) ?></td>
            <td class="num"><?= money(array_sum(array_column($rem,'transfer')), false) ?></td>
            <td class="num"><?= money(array_sum(array_column($rem,'discount')), false) ?></td>
            <td class="num"><?= money(array_sum(array_column($rem,'overnight_collection')), false) ?></td>
            <td class="num"><?= money(array_sum(array_column($rem,'paid')), false) ?></td>
            <td class="num"><?= money($st['remaining_sum'], false) ?></td>
          </tr></tfoot>
        </table>
      <?php endif; ?>
    </div>

    <!-- الباقي له بالحروف -->
    <div class="words">
      <div class="t">الباقي له بالحروف (إجمالي مستحقاته)</div>
      <div class="v"><?= e(tafqit($st['stat_remaining'])) ?></div>
    </div>

    <?php if (!empty($st['note'])): ?>
      <div class="note-box"><div class="t">ملاحظة</div><?= e($st['note']) ?></div>
    <?php endif; ?>

    <!-- شريط التواصل (اتشال الـQR — كان بيتولّد من موقع خارجي) -->
    <div class="contact-bar">
      <div class="contact">
        <?php if ($phone): ?><div class="cline"><span class="ci"><?= icon('send', 14) ?></span> <bdi dir="ltr"><?= e($phone) ?></bdi></div><?php endif; ?>
        <?php if ($email): ?><div class="cline"><span class="ci"><?= icon('send', 14) ?></span> <bdi dir="ltr"><?= e($email) ?></bdi></div><?php endif; ?>
        <?php if ($address): ?><div class="cline"><span class="ci"><?= icon('map-pin', 14) ?></span> <?= e($address) ?></div><?php endif; ?>
      </div>
    </div>

  </div>
  <div class="pagefoot">
    <?= e($company) ?> — لمقاولات النقل &nbsp;•&nbsp; صدر بتاريخ <?= e(dfmt($st['statement_date'])) ?>
    &nbsp;•&nbsp; يُرجى مطابقة البيانات والإبلاغ عن أي خطأ خلال 7 أيام
  </div>
</div>

</body>
</html>
