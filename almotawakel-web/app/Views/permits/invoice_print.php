<?php
use App\Core\App;
use App\Models\PermitInvoice;

/** فاتورة/كشف حساب العميل الخاص بالأذونات — صفحة مستقلة للطباعة/حفظ PDF (RTL) */
$company  = App::setting('company_name', 'شركة المتوكل');
$currency = App::setting('currency', 'ج.م');
$phone    = App::setting('company_phone', '');
$email    = App::setting('company_email', '');
$address  = App::setting('company_address', '');

$preview     = !empty($preview);                 // وضع المعاينة قبل الإصدار (بلا حفظ)
$post        = $post     ?? [];                  // قيم الفورم لإعادة إرسالها عند التأكيد
$storeUrl    = $storeUrl ?? '';                  // مسار الإصدار الفعلي

$isStatement = ($inv['doc_kind'] === PermitInvoice::KIND_STATEMENT);
$isClaim     = ($inv['doc_kind'] === PermitInvoice::KIND_CLAIM);
$isTaxable   = ($inv['doc_kind'] === PermitInvoice::KIND_INVOICE) && (int)$inv['taxable'] === 1;
// العنوان الافتراضي حسب النوع، مع أولوية للعنوان البديل الذي أدخله المستخدم (إن وُجد)
$defaultWord = PermitInvoice::kindLabel($inv['doc_kind']);
$docWord     = trim((string)($inv['custom_title'] ?? '')) !== '' ? $inv['custom_title'] : $defaultWord;
$vatPct      = rtrim(rtrim(number_format(((float)$inv['vat_rate']) * 100, 2), '0'), '.');
$typeLabel   = ($inv['permit_type'] ?? '') === 'warid' ? 'وارد' : 'صادر';
$noLabel     = ($inv['permit_type'] ?? '') === 'warid' ? 'رقم الشهادة' : 'رقم الإذن';

$lines    = PermitInvoice::lines($inv);
$subtotal = (float)$inv['subtotal'];
$vat      = (float)$inv['vat_amount'];
$total    = (float)$inv['total'];

// اللوجو: يُستخدم ملف الصورة إن وُجد، وإلا شعار نصّي بألوان الشركة
$logoRel = null;
foreach (['assets/img/logo.png', 'assets/img/logo.jpg', 'assets/img/logo.jpeg'] as $cand) {
    if (is_file(App::basePath('public/' . $cand))) { $logoRel = $cand; break; }
}

// ⚠️ اتشال كود الـQR (2026-07-22، المرحلة 3 من خطة الأمان). كان بيتولّد من
//    `https://api.qrserver.com/...?data=<اسم الشركة + رقم الفاتورة + اسم العميل
//    + الإجمالي>` — يعني **بيانات كل فاتورة كانت بتتبعت لشركة تانية وتتسجّل في
//    سجلاتها كل مرة يتطبع مستند**. والكود ما كانش بيضيف أي معلومة: كل اللي فيه
//    مكتوب على الورقة أصلاً. قرار المالك: يتشال خالص بدل ما نستضيفه محليًّا.
//    **ما ترجّعش أي `<img>` بمصدر خارجي في المستندات المطبوعة.**
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
    font-family:'Segoe UI',Tahoma,'Cairo',sans-serif;direction:rtl;text-align:right;
    -webkit-print-color-adjust:exact;print-color-adjust:exact}
  .sheet{width:210mm;min-height:297mm;margin:16px auto;background:#fff;position:relative;
    box-shadow:0 10px 40px rgba(21,53,91,.15);overflow:hidden}
  .pad{padding:16mm 14mm}
  .topbar{height:8px;background:linear-gradient(90deg,var(--gold),var(--navy))}
  /* الترويسة */
  .head{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;
    padding-bottom:18px;border-bottom:2px solid var(--line)}
  .brand{display:flex;flex-direction:column;align-items:center;gap:6px}
  .brandlogo{height:82px;width:auto;max-width:240px;object-fit:contain}
  .brand .logo{width:82px;height:82px;border-radius:16px;background:var(--navy);color:var(--gold);
    display:flex;align-items:center;justify-content:center;font-size:44px;font-weight:800}
  .brand .cap{color:var(--navy);font-size:15px;font-weight:800;letter-spacing:.5px}
  .inv-tag{text-align:left;min-width:200px}
  .inv-tag .word{display:inline-block;background:var(--navy);color:#fff;font-size:22px;font-weight:800;
    padding:9px 26px;border-radius:10px;letter-spacing:1px}
  .inv-tag .word.stmt{background:var(--gold);color:var(--navy)}
  .inv-tag .word.claim{background:#1f7a52;color:#fff;font-size:18px}
  .inv-tag .meta{margin-top:12px;font-size:12.5px;color:var(--muted);line-height:2}
  .inv-tag .meta b{color:var(--navy);font-size:14px}
  .badge-tax{display:inline-block;margin-top:8px;padding:4px 12px;border-radius:999px;font-weight:800;font-size:11.5px;
    background:#e4f5ec;color:var(--green)}
  .badge-notax{display:inline-block;margin-top:8px;padding:4px 12px;border-radius:999px;font-weight:800;font-size:11.5px;
    background:#eef1f6;color:var(--muted)}
  /* العميل + تفاصيل الإذن */
  .client-card{margin-top:18px;border:1px solid var(--line);border-radius:12px;padding:14px 16px;background:#fcfdfe}
  .client-card .t{font-size:11px;color:var(--gold);font-weight:800;letter-spacing:.5px;margin-bottom:6px}
  .client-card .name{font-size:19px;font-weight:800;color:var(--navy)}
  .details{margin-top:14px;border:1px solid var(--line);border-radius:12px;overflow:hidden;
    display:grid;grid-template-columns:repeat(4,1fr)}
  .details .cell{padding:11px 14px;border-bottom:1px solid var(--line);border-inline-start:1px solid var(--line)}
  .details .cell .l{font-size:10.5px;color:var(--muted);font-weight:700;margin-bottom:3px}
  .details .cell .v{font-weight:800;color:var(--ink);font-size:13px}
  /* جدول البنود */
  table.items{width:100%;border-collapse:collapse;margin-top:16px;font-size:13px;
    border:1px solid var(--line);border-radius:12px;overflow:hidden}
  table.items thead th{background:var(--navy);color:#fff;padding:11px 12px;font-weight:700;text-align:center}
  table.items thead th.bayan{text-align:right}
  table.items tbody td{padding:11px 12px;border-bottom:1px solid var(--line);text-align:center}
  table.items tbody td.bayan{text-align:right}
  table.items tbody td.num{font-variant-numeric:tabular-nums;white-space:nowrap}
  table.items tbody td.total{font-weight:800;color:var(--navy)}
  table.items tbody tr:nth-child(even){background:#f8fafc}
  table.items td.idx{color:var(--muted);width:34px}
  table.items tr.vat td{background:var(--gold-l);font-weight:800;color:#7a5f0e}
  /* الإجماليات */
  .totals{display:grid;grid-template-columns:1.25fr .9fr;gap:16px;margin-top:16px;align-items:start}
  .words{border:1px dashed var(--gold);background:var(--gold-l);border-radius:12px;padding:14px 16px}
  .words .t{font-size:11px;color:#8a6d10;font-weight:800;margin-bottom:6px}
  .words .v{font-size:14px;font-weight:700;color:var(--navy);line-height:1.9}
  .sumbox{border:1px solid var(--line);border-radius:12px;overflow:hidden}
  .sumbox .r{display:flex;justify-content:space-between;padding:10px 16px;font-size:13.5px;border-bottom:1px solid var(--line)}
  .sumbox .r .l{color:var(--muted)}
  .sumbox .r .v{font-weight:700;font-variant-numeric:tabular-nums}
  .sumbox .r.sub{background:#f8fafc}
  .sumbox .r.grand{background:var(--navy);color:#fff;font-size:16px;padding:14px 16px;border:none}
  .sumbox .r.grand .l{color:#dfe8f2}.sumbox .r.grand .v{color:var(--gold);font-weight:800}
  .note-box{margin-top:14px;border:1px solid var(--line);border-radius:10px;padding:10px 14px;font-size:12.5px;color:var(--ink);background:#fcfdfe}
  .note-box .t{color:var(--gold);font-weight:800;font-size:11px;margin-bottom:3px}
  .foot{margin-top:22px;padding-top:16px;border-top:2px solid var(--line);
    display:grid;grid-template-columns:1fr 1fr;gap:40px;align-items:end}
  .sign{text-align:center}
  .sign .line{margin-top:30px;border-top:1.5px dashed var(--muted);padding-top:6px;color:var(--muted);font-size:12.5px;font-weight:700}
  /* شريط التواصل + QR */
  .contact-bar{margin-top:20px;padding-top:14px;border-top:1px solid var(--line);
    display:flex;align-items:center;gap:16px}
  .qr{flex:0 0 auto;text-align:center}
  .qr img{width:88px;height:88px;border:1px solid var(--line);border-radius:8px;padding:3px;background:#fff}
  .qr span{display:block;font-size:9.5px;color:var(--muted);margin-top:2px;font-weight:700}
  .contact{flex:1;display:flex;flex-direction:column;gap:5px}
  .contact .cline{font-size:12px;color:var(--ink);font-weight:700;line-height:1.6}
  .contact .ci{display:inline-block;width:20px;color:var(--navy)}
  .pagefoot{text-align:center;color:var(--muted);font-size:10.5px;padding:10px 0 16px}
  .wm{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
    font-size:340px;font-weight:900;color:rgba(21,53,91,.035);pointer-events:none;z-index:0}
  .wm img{width:62%;max-width:430px;height:auto;object-fit:contain;opacity:.05}
  .pad>*{position:relative;z-index:1}
  .pv-banner{max-width:210mm;margin:10px auto 0;background:#fff4d6;border:1.5px solid var(--gold);
    color:#7a5f0e;border-radius:10px;padding:11px 16px;font-size:13.5px;font-weight:700;text-align:center}
  .pv-banner b{color:var(--navy)}
  /* ووتر مارك «معاينة» مائل يوضّح أن المستند غير نهائي (يُخفى عند الطباعة) */
  .pv-stamp{position:absolute;top:50%;right:50%;transform:translate(50%,-50%) rotate(-32deg);
    font-size:120px;font-weight:900;color:rgba(180,56,62,.10);letter-spacing:8px;
    pointer-events:none;z-index:2;white-space:nowrap}
  .bar{max-width:210mm;margin:0 auto;display:flex;gap:10px;justify-content:center;padding:6px}
  .btn{padding:10px 22px;border:none;border-radius:10px;font-weight:800;font-size:14px;cursor:pointer;
    font-family:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:8px}
  .btn.gold{background:var(--gold);color:var(--navy)}
  .btn.ghost{background:#fff;color:var(--navy);border:1.5px solid var(--navy)}
  @media print{
    @page{size:A4;margin:0}
    html,body{background:#fff}
    .noprint{display:none!important}
    /* صفحة A4 واحدة دائمًا: عرض ثابت + توسيط + بلا حد أدنى للارتفاع، والتصغير التلقائي (script) يمنع الورقة الثانية */
    .sheet{margin:0 auto;box-shadow:none;width:210mm;min-height:auto;overflow:hidden}
    .pad{padding:12mm 14mm}
  }
</style>
</head>
<body>

<?php if ($preview): ?>
  <div class="pv-banner noprint">
    👁️ هذه <b>معاينة</b> فقط — لم يتم إصدار المستند أو حفظه بعد. راجع البيانات ثم اضغط «تأكيد الإصدار».
  </div>
  <div class="bar noprint">
    <form method="post" action="<?= e($storeUrl) ?>" style="display:contents">
      <?= csrfField() ?>
      <?php foreach ($post as $k => $v): if ($k === '_csrf') { continue; }
              if (is_array($v)): foreach ($v as $vv): ?>
                <input type="hidden" name="<?= e($k) ?>[]" value="<?= e((string)$vv) ?>">
              <?php endforeach; else: ?>
                <input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>">
              <?php endif; endforeach; ?>
      <button class="btn gold" type="submit">✅ تأكيد الإصدار وحفظ</button>
    </form>
    <button class="btn ghost" type="button" onclick="history.back()">✏️ رجوع للتعديل</button>
  </div>
<?php else: ?>
  <div class="bar noprint">
    <button class="btn gold" onclick="fitAndPrint()">🖨️ طباعة / حفظ PDF</button>
    <a class="btn ghost" href="<?= url('permits/'.$inv['permit_id'].'/invoice') ?>">‹ رجوع</a>
  </div>
<?php endif; ?>

<div class="sheet">
  <div class="topbar"></div>
  <?php if ($preview): ?><div class="pv-stamp noprint">معاينة</div><?php endif; ?>
  <div class="wm"><?php if ($logoRel): ?><img src="<?= url($logoRel) ?>" alt=""><?php else: ?>م<?php endif; ?></div>
  <div class="pad">

    <!-- الترويسة: اللوجو + «لمقاولات النقل» -->
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
        <span class="word <?= $isStatement?'stmt':($isClaim?'claim':'') ?>"><?= e($docWord) ?></span>
        <div class="meta">
          التاريخ: <b><?= e(dfmt($inv['invoice_date'])) ?></b>
          <?php if (!empty($inv['serial_no'])): ?><br>رقم مسلسل: <b><?= e($inv['serial_no']) ?></b><?php endif; ?>
          <?php if (!empty($inv['internal_no'])): ?><br>رقم الفاتورة: <b><?= e($inv['internal_no']) ?></b><?php endif; ?>
        </div>
        <?php if ($isTaxable): ?><div><span class="badge-tax">فاتورة ضريبية (<?= $vatPct ?>%)</span></div><?php endif; ?>
      </div>
    </div>

    <!-- العميل -->
    <div class="client-card">
      <div class="t"><?= $isStatement ? 'كشف حساب إلى' : ($isClaim ? 'مطالبة إلى' : 'فاتورة إلى') ?> (العميل)</div>
      <div class="name"><?= e(($inv['display_client'] ?? '') ?: ($inv['client'] ?: ($inv['company'] ?: '—'))) ?></div>
    </div>

    <!-- تفاصيل الإذن -->
    <div class="details">
      <?php
      $cells = [
        ['الحالة', $typeLabel],
        [$noLabel, $inv['permit_no'] ?: '—'],
        ['التاريخ', dfmt($inv['invoice_date']) ?: '—'],
        ['عدد الحاويات', intf($inv['containers'])],
        ['لصالح شركة', $inv['company'] ?: '—'],
        ['الجهة', $inv['destination'] ?: '—'],
        ['توكيل ملاحي', ($inv['agency'] ?? '') ?: '—'],
        ['الميناء', ($inv['port'] ?? '') ?: '—'],
      ];
      foreach ($cells as [$l,$v]): ?>
        <div class="cell"><div class="l"><?= $l ?></div><div class="v"><?= e($v) ?></div></div>
      <?php endforeach; ?>
    </div>

    <!-- البنود: بيان / كمية / سعر / إجمالي -->
    <table class="items">
      <thead>
        <tr>
          <th class="idx">م</th>
          <th class="bayan">البيان</th>
          <th style="width:80px">الكمية</th>
          <th style="width:130px">السعر (<?= e($currency) ?>)</th>
          <th style="width:140px">الإجمالي (<?= e($currency) ?>)</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lines as $i => $ln): ?>
          <tr>
            <td class="idx"><?= $i+1 ?></td>
            <td class="bayan"><?= e($ln['label']) ?></td>
            <td class="num"><?= e(rtrim(rtrim(number_format((float)($ln['qty'] ?? 1),2,'.',''),'0'),'.') ?: '0') ?></td>
            <td class="num"><?= money((float)($ln['price'] ?? 0), false) ?></td>
            <td class="num total"><?= money((float)($ln['total'] ?? 0), false) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($isTaxable): ?>
          <tr class="vat">
            <td class="idx"><?= count($lines)+1 ?></td>
            <td class="bayan">ضريبة القيمة المضافة (<?= $vatPct ?>%) على النولون</td>
            <td class="num">—</td>
            <td class="num">—</td>
            <td class="num total"><?= money($vat, false) ?></td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>

    <!-- الإجماليات والمبلغ بالحروف -->
    <div class="totals">
      <div class="words">
        <div class="t">المبلغ الإجمالي بالحروف</div>
        <div class="v"><?= e(tafqit($total)) ?></div>
      </div>
      <div class="sumbox">
        <div class="r sub"><span class="l">إجمالي البنود</span><span class="v"><?= money($subtotal, false) ?></span></div>
        <?php if ($isTaxable): ?>
          <div class="r"><span class="l">ق. مضافة (<?= $vatPct ?>%)</span><span class="v"><?= money($vat, false) ?></span></div>
        <?php endif; ?>
        <div class="r grand"><span class="l">الإجمالي الكلي</span><span class="v"><?= money($total) ?></span></div>
      </div>
    </div>

    <?php if (!empty($inv['note'])): ?>
      <div class="note-box"><div class="t">ملاحظة</div><?= e($inv['note']) ?></div>
    <?php endif; ?>

    <!-- شريط التواصل (اتشال الـQR — كان بيتولّد من موقع خارجي) -->
    <div class="contact-bar">
      <div class="contact">
        <?php if ($phone): ?><div class="cline"><span class="ci">📞</span> <bdi dir="ltr"><?= e($phone) ?></bdi></div><?php endif; ?>
        <?php if ($email): ?><div class="cline"><span class="ci">✉️</span> <bdi dir="ltr"><?= e($email) ?></bdi></div><?php endif; ?>
        <?php if ($address): ?><div class="cline"><span class="ci">📍</span> <?= e($address) ?></div><?php endif; ?>
      </div>
    </div>

  </div>
  <div class="pagefoot">
    <?= e($company) ?> — لمقاولات النقل &nbsp;•&nbsp; صدر بتاريخ <?= e(dfmt($inv['invoice_date'])) ?>
    &nbsp;•&nbsp; يُرجى مطابقة البيانات والإبلاغ عن أي خطأ خلال 7 أيام
  </div>
</div>

<script>
  // ضمان طباعة الفاتورة في صفحة A4 واحدة:
  // نقيس ارتفاع الورقة فعليًا، ولو تعدّى ارتفاع صفحة A4 نُصغّرها تلقائيًا (zoom) لتدخل كلها في ورقة واحدة.
  (function () {
    var A4_H = 1122;               // ارتفاع صفحة A4 بالبكسل عند 96dpi (297مم)
    function sheet() { return document.querySelector('.sheet'); }
    function fit() {
      var s = sheet(); if (!s) return;
      s.style.zoom = '';           // إعادة الضبط قبل القياس
      var prevMin = s.style.minHeight;
      s.style.minHeight = '0';     // إلغاء الحد الأدنى (297مم) مؤقتًا لقياس الارتفاع الحقيقي للمحتوى
      var h = s.offsetHeight;
      s.style.minHeight = prevMin;
      if (h > A4_H) s.style.zoom = (A4_H / h).toFixed(4);
    }
    function reset() { var s = sheet(); if (s) s.style.zoom = ''; }
    window.fitAndPrint = function () { fit(); window.print(); };
    window.addEventListener('beforeprint', fit);
    window.addEventListener('afterprint', reset);
  })();
</script>
</body>
</html>
