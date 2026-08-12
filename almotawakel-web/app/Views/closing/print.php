<?php
use App\Core\App;

/** التقفيل اليومي — صفحة طباعة/حفظ PDF (A4 بستايل الفاتورة، RTL) */
$company  = App::setting('company_name', 'شركة المتوكل');
$currency = App::setting('currency', 'ج.م');
$phone    = App::setting('company_phone', '');
$email    = App::setting('company_email', '');
$address  = App::setting('company_address', '');
$qrBase   = public_base_url();
$qrTarget = $qrBase !== ''
    ? $qrBase . '/closing?date=' . rawurlencode($date)
    : '';
/* ⚠️ طلب المالك (2026-08-03): «حصيلة المراجعة» اتشالت من الورقة خالص (والكنترولر
   بطّل يحسب `DailyReview::view()` للطباعة) — المراجعة شغل الشاشة مش الورقة. */
// اللوجو: يُستخدم ملف الصورة إن وُجد، وإلا شعار نصّي بألوان الشركة
$logoRel = null;
foreach (['assets/img/logo.png', 'assets/img/logo.jpg', 'assets/img/logo.jpeg'] as $cand) {
    if (is_file(App::basePath('public/' . $cand))) { $logoRel = $cand; break; }
}
$dash = fn($v) => ($v === null || $v === '') ? '—' : $v;
/* بنقسّم الجداول الطويلة لعمودين بحد أقصى؛ 3 أعمدة كانت بتجبر الخط يبقى صغير
   جدًا. الأولوية هنا لقراءة واضحة في النسخة المطبوعة. */
$perCol   = 36;
/* اليوم المزدحم جدًّا (عهد + بنود + فيزا) بيشدّ الجداول درجة كمان بدل ما يفتح
   صفحة تالتة. لمسة واحدة على كل الجداول — أضمن من تشتيت مقاسات في كل قسم. */
$sheetDense = (count($custodyRows) + count($expenses) + count($visaRows)) > 85;
$splitCols = static function (int $n) use ($perCol): int {
    if ($n <= 8) { return 1; }
    return (int) min(2, max(2, (int) ceil($n / $perCol)));
};
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>التقفيل اليومي — <?= e(dfmt($date)) ?> — <?= e($company) ?></title>
<?php partial('print_base'); ?>
<?php if ($qrTarget !== ''): ?>
<script src="<?= e(url('assets/vendor/qrcodejs/qrcode.min.js')) ?>"></script>
<?php endif; ?>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  html,body{background:var(--bg);color:var(--ink);
    font-family:var(--font);direction:rtl;text-align:right;
    -webkit-print-color-adjust:exact;print-color-adjust:exact}
  .sheet{width:210mm;min-height:297mm;margin:16px auto;background:#fff;position:relative;
    box-shadow:0 10px 40px rgba(21,53,91,.15);overflow:hidden}
  .pad{padding:9mm 8mm}
  .topbar{height:8px;background:linear-gradient(90deg,var(--gold),var(--navy))}
  /* الترويسة */
  .head{display:flex;justify-content:space-between;align-items:center;gap:12px;
    padding-bottom:7px;border-bottom:2px solid var(--line)}
  .brand{display:flex;flex-direction:column;align-items:center;gap:3px}
  .brandlogo{height:46px;width:auto;max-width:230px;object-fit:contain}
  .brand .logo{width:46px;height:46px;border-radius:var(--radius-3);background:var(--navy);color:var(--gold);
    display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:600}
  .brand .cap{color:var(--navy);font-size:12px;font-weight:600;letter-spacing:.5px}
  .doc-tag{display:flex;align-items:center;gap:12px;flex:1;min-width:0;white-space:nowrap}
  .doc-tag .word{display:inline-block;background:var(--navy);color:#fff;font-size:17px;font-weight:600;
    padding:6px 16px;border-radius:var(--radius-2);letter-spacing:1px}
  .doc-tag .meta{display:flex;align-items:center;gap:10px;font-size:12.5px;color:var(--muted)}
  .doc-tag .meta span{display:inline-flex;align-items:center;gap:3px}
  .doc-tag .meta span+span::before{content:"•";color:var(--gold);font-weight:700;margin-left:7px}
  .doc-tag .meta b{color:var(--navy);font-size:13px}
  /* بطاقات الملخص */
  .sum{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:8px}
  .sum .b{border:1px solid var(--line);border-radius:var(--radius-2);padding:5px 8px;text-align:center;
    border-top:3px solid var(--navy);background:#fcfdfe}
  .sum .b.gold{border-top-color:var(--gold);background:var(--gold-l)}
  .sum .l{font-size:11.5px;color:var(--muted);font-weight:500}
  .sum .v{font-size:15px;font-weight:600;color:var(--navy);margin-top:2px;font-variant-numeric:tabular-nums}
  .sum .b.gold .v{color:#8a6d10}
  /* عناوين الأقسام */
  .sec{display:flex;align-items:center;gap:6px;margin:9px 0 4px;color:var(--navy);
    font-size:13.5px;font-weight:600}
  .sec::before{content:"";width:4px;height:13px;background:var(--gold);border-radius:var(--radius-1)}
  .sec .c{color:var(--muted);font-size:11px;font-weight:500}
  /* الجداول */
  table.data{width:100%;border-collapse:collapse;font-size:12px;line-height:1.32;
    border:1px solid var(--line);border-radius:var(--radius-2);overflow:hidden}
  table.data thead th{background:var(--navy);color:#fff;padding:4px 5px;font-weight:600;text-align:center;
    white-space:nowrap}
  table.data thead th.bayan{text-align:right}
  table.data tbody td{padding:3.5px 5px;border-bottom:1px solid var(--line);text-align:center;
    vertical-align:top;word-break:break-word}
  table.data tbody td.bayan{text-align:right}
  table.data tbody td.num{font-variant-numeric:tabular-nums;white-space:nowrap;font-weight:500}
  table.data tbody td.pno{font-weight:600;color:var(--navy);white-space:nowrap}
  table.data tbody tr:nth-child(even){background:#f8fafc}
  table.data tbody td.empty{color:var(--muted);padding:8px;text-align:center}
  table.data tfoot td{background:var(--navy);color:var(--gold);font-weight:600;padding:4px 5px;text-align:center}
  table.data tfoot td.lbl{text-align:right;color:#fff}
  table.visa{max-width:100%}
  table.data tbody td.card{font-weight:600;color:var(--navy);white-space:nowrap}
  table.data tbody td.containers{font-weight:600;white-space:nowrap}
  /* الجدول الطويل بينقسم نصّين جنب بعض — القراءة يمين لتحت ثم شمال لتحت */
  .split{display:grid;grid-template-columns:1fr;gap:6px;align-items:start}
  .split.c2{grid-template-columns:1fr 1fr}
  .pad.dense table.data{font-size:11.5px;line-height:1.25}
  /* اليوم المزدحم يقلّل الفراغ الرأسي فقط؛ حجم الخط يظل مقروءًا. */
  .pad.dense table.data tbody td{padding:1px 4px}
  .pad.dense table.data thead th,.pad.dense table.data tfoot td{padding:2.5px 4px}
  .totbar{display:flex;justify-content:space-between;align-items:center;background:var(--navy);
    color:var(--gold);font-weight:600;font-size:12px;padding:5px 9px;margin-top:4px;
    border-radius:var(--radius-2)}
  .totbar span{color:#fff}
  .totbar b{font-variant-numeric:tabular-nums}
  /* صندوق الحساب الختامي */
  .calc{margin-top:12px;display:grid;grid-template-columns:1.1fr .9fr;gap:12px;align-items:start}
  .calc .note-words{border:1px dashed var(--gold);background:var(--gold-l);border-radius:var(--radius-2);padding:10px 12px}
  .calc .note-words .t{font-size:12px;color:#8a6d10;font-weight:600;margin-bottom:6px}
  .calc .note-words .v{font-size:13px;font-weight:500;color:var(--navy);line-height:1.75}
  .sumbox{border:1px solid var(--line);border-radius:var(--radius-2);overflow:hidden}
  .sumbox .r{display:flex;justify-content:space-between;padding:5px 11px;font-size:12px;border-bottom:1px solid var(--line)}
  .sumbox .r .l{color:var(--muted)}
  .sumbox .r .v{font-weight:500;font-variant-numeric:tabular-nums}
  .sumbox .r.sub{background:#f8fafc}
  .sumbox .r.neg .v{color:var(--red)}
  .sumbox .r.grand{background:var(--gold);color:var(--navy);font-size:14px;padding:8px 11px;border:none;font-weight:600}
  .sumbox .r.grand .l{color:#7a5f0e}
  /* معاينة التقفيل على النظام — بديل التوقيعات الورقية */
  .verify-row{margin-top:8px;padding-top:7px;border-top:2px solid var(--line);
    display:flex;align-items:center;gap:10px;break-inside:avoid}
  .qrbox{width:124px;height:124px;flex:0 0 124px;padding:6px;background:#fff;border:1px solid var(--line);
    border-radius:var(--radius-2)}
  /* qrcodejs ينشئ canvas وصورة احتياطية ويخفي واحدًا منهما inline؛ لا نجبر
     `display` وإلا يظهر الكود مرتين فوق بعض في الطباعة. */
  .qrbox canvas,.qrbox img{width:112px!important;height:112px!important}
  .verify-copy .t{font-size:13px;font-weight:700;color:var(--navy)}
  .verify-copy .d{font-size:11px;color:var(--muted);margin-top:2px}
  .verify-copy .link{font-size:9.5px;color:var(--navy);margin-top:3px;direction:ltr;text-align:right}
  .contact-bar{margin-top:8px;padding-top:6px;border-top:1px solid var(--line)}
  .contact{display:flex;gap:18px;flex-wrap:wrap;align-items:center}
  .contact .cline{font-size:10.5px;color:var(--ink);font-weight:500;line-height:1.6}
  .contact .ci{display:inline-block;width:14px;color:var(--navy)}
  .pagefoot{text-align:center;color:var(--muted);font-size:10px;padding:7px 0 10px}
  .wm{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
    font-size:340px;font-weight:700;color:rgba(21,53,91,.035);pointer-events:none;z-index:0}
  .pad>*{position:relative;z-index:1}
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
  <div class="pad<?= $sheetDense ? ' dense' : '' ?>">

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
          <span>تاريخ اليوم: <b><?= e(dfmt($date)) ?></b></span>
          <span>بنود المصروفات: <b><?= intf(count($expenses)) ?></b></span>
          <span>عهد السائقين: <b><?= intf(count($custodyRows)) ?></b></span>
        </div>
      </div>
    </div>

    <!-- بطاقات الملخص -->
    <div class="sum">
      <div class="b"><div class="l">باقي رصيد سابق</div><div class="v"><?= money($prevBalance,false) ?></div></div>
      <div class="b"><div class="l">عهد السائقين</div><div class="v"><?= money($totCustodyExp,false) ?></div></div>
      <div class="b"><div class="l">إجمالي العهدة المضافة</div><div class="v"><?= money($totTopups,false) ?></div></div>
      <div class="b gold"><div class="l">رصيد مرحّل للغد</div><div class="v"><?= money($carried,false) ?></div></div>
    </div>

    <!-- عهد السائقين — أول قسم في الورقة (طلب المالك 2026-08-03) -->
    <div class="sec"><?= icon('coins', 14) ?> عهد السائقين <span class="c"><?= intf(count($custodyRows)) ?> بوليصة</span></div>
    <table class="data">
      <thead><tr>
        <th>البوليصة</th><th style="width:58px">رقم الأذون</th><th class="bayan">السائق</th>
        <th class="bayan">الشركة</th><th style="width:78px">الحاويات</th>
        <th class="bayan" style="width:82px">سلّمها</th><th style="width:84px">المخصوم</th>
      </tr></thead>
      <tbody>
        <?php foreach ($custodyRows as $c): ?>
          <tr>
            <td class="pno"><?= e($dash($c['bill_number'])) ?></td>
            <td class="pno"><?= e($dash($c['permit_no'])) ?></td>
            <td class="bayan"><?= e($dash($c['driver'])) ?></td>
            <td class="bayan"><?= e($dash($c['company'])) ?></td>
            <td class="containers"><bdi dir="ltr"><?= e($dash($c['containers'] ?? '')) ?></bdi></td>
            <?php /* فاضي = طلعت من درج المكتب */ ?>
            <td class="bayan"><?= e(trim((string)($c['custody_rep_name'] ?? '')) ?: 'الدرج') ?></td>
            <td class="num"><?= money($c['deduction'],false) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$custodyRows): ?><tr><td colspan="7" class="empty">لا عهد سائقين بتاريخ اليوم</td></tr><?php endif; ?>
      </tbody>
      <?php if($custodyRows): ?>
      <tfoot><tr><td class="lbl" colspan="6">إجمالي المخصوم</td><td class="num"><?= money($totCustodyExp,false) ?></td></tr></tfoot>
      <?php endif; ?>
    </table>

    <?php /* ⚠️ تلات جداول (طلب المالك): الإيصالات · مصروفات المناديب اللي بتتحاسب
             من حوض مصروف المناديب (مواصلات · تصوير) · المصروفات العامة. والتصنيف
             كله جاي من `grp` في الكنترولر — مفيش شرط مكتوب هنا. الإيصالات مرتبة
             حسب الشركة وتعرض اسم المندوب الذي أدخلها، ومصروفات المناديب مجمّعة
             حسب المندوب، والباقي حسب رقم الإذن.
             ⚠️ الجدول الطويل بينقسم **نصّين جنب بعض** (طلب المالك: الورقة صفحتين
             بحد أقصى) — القراءة من اليمين لتحت ثم الشمال لتحت، والترتيب محفوظ. */ ?>
    <?php
      /* «البيان» = ملاحظة البند، وبترجع لاسم البند لو فاضية عشان ما يبقاش فيه
         سطر بلا وصف بعد ما عمود «البند» اتشال. */
      $vCompany = static fn(array $ex) => $dash($ex['company'] ?? '');
      $vBayan   = static fn(array $ex) => $dash(trim((string)($ex['note'] ?? '')) ?: ($ex['stage_label'] ?? ''));
      $vRep     = static fn(array $ex) => trim((string)($ex['rep_name'] ?? '')) ?: 'المكتب';
      $vKind    = static fn(array $ex) => $dash($ex['stage_label'] ?? '');
    ?>
    <?php foreach ([
      ['t'=>'الإيصالات', 'i'=>'file-text', 'rows'=>$receiptRows, 'sum'=>$totReceipts,
       'lbl'=>'إجمالي الإيصالات', 'empty'=>'لا إيصالات بتاريخ اليوم', 'note'=>'',
       'cols'=>[['h'=>'الشركة','cls'=>'bayan','v'=>$vCompany],
                ['h'=>'البيان','cls'=>'bayan','v'=>$vBayan],
                ['h'=>'المندوب','w'=>'72px','v'=>$vRep]]],
      /* اليوم اللي مفيهوش مصروف مندوب ما بياخدش جدولًا فاضيًا — نفس سلوك جدول
         الفيزا، والورقة مقيّدة بصفحتين. */
      ['t'=>'مصروفات المناديب', 'i'=>'wallet', 'rows'=>$repRows ?? [], 'sum'=>$totRep ?? 0,
       'lbl'=>'إجمالي مصروفات المناديب', 'empty'=>'لا مصروفات مناديب بتاريخ اليوم',
       'note'=>'من حوض مصروف المناديب', 'hide_empty'=>true,
       'cols'=>[['h'=>'المندوب','cls'=>'bayan','v'=>$vRep],
                ['h'=>'النوع','cls'=>'bayan','w'=>'88px','v'=>$vKind],
                ['h'=>'البيان','cls'=>'bayan','v'=>$vBayan]]],
      /* ⚠️ السلفة والمسحوب الشخصي **مش مصروف شركة** (طلب المالك 2026-08-12):
         كاش خرج للمندوب نفسه ويترجّع منه أو يتخصم من راتبه، فجدوله لوحده
         عشان «المصروفات العامة» تفضل مصروف الشركة الحقيقي وبس. */
      ['t'=>'سلف ومسحوبات المناديب', 'i'=>'banknote', 'rows'=>$advanceRows ?? [],
       'sum'=>$totAdvance ?? 0, 'lbl'=>'إجمالي السلف والمسحوبات',
       'empty'=>'لا سلف بتاريخ اليوم', 'note'=>'على المندوب لا على الشركة',
       'hide_empty'=>true,
       'cols'=>[['h'=>'المندوب','cls'=>'bayan','v'=>$vRep],
                ['h'=>'النوع','cls'=>'bayan','w'=>'88px','v'=>$vKind],
                ['h'=>'البيان','cls'=>'bayan','v'=>$vBayan]]],
      ['t'=>'المصروفات العامة', 'i'=>'trending-down', 'rows'=>$miscRows, 'sum'=>$totMisc,
       'lbl'=>'إجمالي المصروفات العامة', 'empty'=>'لا مصروفات عامة بتاريخ اليوم', 'note'=>'',
       'cols'=>[['h'=>'الشركة','cls'=>'bayan','v'=>$vCompany],
                ['h'=>'البيان','cls'=>'bayan','v'=>$vBayan]]],
    ] as $blk): ?>
      <?php
        $rows   = $blk['rows'];
        if (!$rows && !empty($blk['hide_empty'])) { continue; }
        $cols   = $splitCols(count($rows));
        $chunks = $rows ? array_chunk($rows, (int) ceil(count($rows) / $cols)) : [[]];
        $span   = count($blk['cols']) + 1;   // + عمود المبلغ
      ?>
      <div class="sec"><?= icon($blk['i'], 14) ?> <?= e($blk['t']) ?>
        <span class="c"><?= intf(count($rows)) ?> بند<?= $blk['note'] !== '' ? ' • ' . e($blk['note']) : '' ?></span></div>
      <div class="split<?= $cols > 1 ? ' c' . $cols : '' ?>">
        <?php foreach ($chunks as $chunk): ?>
          <table class="data">
            <thead><tr>
              <?php foreach ($blk['cols'] as $c): ?>
                <th class="<?= e($c['cls'] ?? '') ?>"<?= isset($c['w']) ? ' style="width:' . e($c['w']) . '"' : '' ?>><?= e($c['h']) ?></th>
              <?php endforeach; ?>
              <th style="width:72px">المبلغ</th>
            </tr></thead>
            <tbody>
              <?php foreach ($chunk as $ex): ?>
                <tr>
                  <?php foreach ($blk['cols'] as $c): ?>
                    <td class="<?= e($c['cls'] ?? '') ?>"><?= e(($c['v'])($ex)) ?></td>
                  <?php endforeach; ?>
                  <td class="num"><?= money($ex['amount'],false) ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if(!$chunk): ?><tr><td colspan="<?= $span ?>" class="empty"><?= e($blk['empty']) ?></td></tr><?php endif; ?>
            </tbody>
          </table>
        <?php endforeach; ?>
      </div>
      <?php if($rows): ?>
        <div class="totbar"><span><?= e($blk['lbl']) ?></span><b><?= money($blk['sum'],false) ?></b></div>
      <?php endif; ?>
    <?php endforeach; ?>

    <!-- مدفوعات الفيزا (إن وُجدت) -->
    <?php if(!empty($visaRows)): ?>
      <div class="sec"><?= icon('credit-card', 14) ?> مدفوعات الفيزا <span class="c">مدفوعة بالفيزا لا نقدًا — لا تُخصم من الرصيد</span></div>
      <div class="split c2">
        <table class="data visa">
          <thead><tr>
            <th class="bayan">الشركة</th>
            <th class="bayan">البيان</th>
            <?php /* رقم/اسم الكارت اللي اتدفع منه — `visa_cards.name` (طلب المالك) */ ?>
            <th style="width:62px">الفيزا</th>
            <th style="width:72px">المبلغ</th>
          </tr></thead>
          <tbody>
            <?php foreach ($visaRows as $v): ?>
              <tr>
                <td class="bayan"><?= e($dash($v['company'])) ?></td>
                <td class="bayan"><?= e($dash(trim((string)($v['note'] ?? '')) ?: ($v['stage_label'] ?? ''))) ?></td>
                <td class="card"><?= e($v['card_name']) ?></td>
                <td class="num"><?= money($v['amount'],false) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot><tr><td class="lbl" colspan="3">إجمالي مدفوعات الفيزا</td><td class="num"><?= money($totVisa,false) ?></td></tr></tfoot>
        </table>
        <?php /* إجمالي المسحوب من كل كارت (طلب المالك) — مجموعه = إجمالي الفيزا فوق */ ?>
        <table class="data visa">
          <thead><tr>
            <th class="bayan">الفيزا</th>
            <th class="bayan">صاحبها</th>
            <th style="width:38px">بنود</th>
            <th style="width:72px">المسحوب</th>
          </tr></thead>
          <tbody>
            <?php foreach (($visaByCard ?? []) as $vc): ?>
              <tr>
                <td class="bayan card"><?= e($vc['name']) ?></td>
                <td class="bayan"><?= e($dash($vc['owner'])) ?></td>
                <td><?= intf($vc['count']) ?></td>
                <td class="num"><?= money($vc['total'],false) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot><tr><td class="lbl" colspan="3">الإجمالي</td><td class="num"><?= money($totVisa,false) ?></td></tr></tfoot>
        </table>
      </div>
    <?php endif; ?>


    <!-- الحساب الختامي -->
    <div class="calc">
      <div class="note-words">
        <div class="t">الرصيد المرحّل للغد بالحروف</div>
        <div class="v"><?= e(tafqit($carried)) ?></div>
      </div>
      <div class="sumbox">
        <div class="r sub"><span class="l">باقي رصيد سابق</span><span class="v"><?= money($prevBalance,false) ?></span></div>
        <?php /* ⚠️ نفس تفكيك الشاشة بالحرف — مجموع السطرين = `topups` اللي
                 `Closing` بيحسبه، فالورقة تفضل بمعادلة مقفولة. */ ?>
        <?php if(($totTopupsDrawer ?? 0) != 0): ?>
          <div class="r"><span class="l">+ عهدة مضافة للدرج</span><span class="v"><?= money($totTopupsDrawer,false) ?></span></div>
        <?php endif; ?>
        <?php if(($totBankToReps ?? 0) != 0): ?>
          <div class="r"><span class="l">+ عهدة منزلة للمناديب (من البنك)</span><span class="v"><?= money($totBankToReps,false) ?></span></div>
        <?php endif; ?>
        <?php if($totTopups != 0): ?>
          <div class="r sub"><span class="l">= المتاح</span><span class="v"><?= money($available,false) ?></span></div>
        <?php endif; ?>
        <?php /* ⚠️ سطر توثيقي بلا عملية حسابية — تحويل داخلي بين الدرج وجيوب
                 المناديب، الإجمالي ما بيتغيّرش (بيتعرض بس في النموذج المجمّع). */ ?>
        <?php if(($totOfficeDep ?? 0) == 0 && ($totDrawerToReps ?? 0) != 0): ?>
          <div class="r"><span class="l">⇄ نزل من الدرج للمناديب (تحويل داخلي)</span><span class="v"><?= money($totDrawerToReps,false) ?></span></div>
        <?php endif; ?>
        <?php /* ⚠️ **لازم يمشي مع معادلة الشاشة بالحرف** — `$totExpense` بقى
                 **بدون** العهدة المسحوبة من الدرج (اتفصلت لخانتها)، فلو السطر ده
                 اتساب لوحده الورقة تطلع بمعادلة **مش مقفولة**. */ ?>
        <?php if (($totOfficeDep ?? 0) != 0): ?>
          <div class="r neg"><span class="l">− عهدة مسحوبة من الدرج للمناديب</span><span class="v"><?= money($totOfficeDep,false) ?></span></div>
        <?php endif; ?>
        <div class="r neg"><span class="l">− مصروفات نقدية</span><span class="v"><?= money($totExpense,false) ?></span></div>
        <div class="r neg"><span class="l">− عهد السائقين</span><span class="v"><?= money($totCustodyExp,false) ?></span></div>
        <div class="r grand"><span class="l">= رصيد مرحّل لليوم التالي</span><span class="v"><?= money($carried,false) ?></span></div>
      </div>
    </div>

    <?php if ($qrTarget !== ''): ?>
      <div class="verify-row">
        <div id="closingQr" class="qrbox" aria-label="QR لمعاينة تقفيل اليوم"></div>
        <div class="verify-copy">
          <div class="t">معاينة التقفيل على النظام</div>
          <div class="d">امسح الكود لفتح تقفيل يوم <?= e(dfmt($date)) ?> — يلزم تسجيل الدخول.</div>
          <div class="link"><bdi dir="ltr"><?= e($qrTarget) ?></bdi></div>
        </div>
      </div>
    <?php endif; ?>

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

<?php if ($qrTarget !== ''): ?>
<script>
(() => {
  const box = document.getElementById('closingQr');
  if (!box || typeof QRCode === 'undefined') return;
  new QRCode(box, {
    text: <?= js_json($qrTarget) ?>,
    width: 112,
    height: 112,
    colorDark: '#15355B',
    colorLight: '#ffffff',
    correctLevel: QRCode.CorrectLevel.L
  });
})();
</script>
<?php endif; ?>
</body>
</html>
