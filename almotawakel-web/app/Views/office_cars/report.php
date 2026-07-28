<?php
/**
 * تقرير أداء سيارة — صفحة A4 للطباعة/حفظ PDF (v54).
 *
 * ⚠️ **الطباعة دايمًا فاتحة** (المرحلة ١٥) — الوضع الليلي بياكل الحبر.
 * ⚠️ توكنز الهوية من `partials/print_base.php` (مصدر واحد لكل مستندات A4).
 *
 * متغيّرات: $car $period $perf $trips $byCat $companies $mix
 */
use App\Core\App;
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<?php partial('print_base'); ?>
<style>
  *{box-sizing:border-box}
  body{margin:0;padding:16px;background:var(--bg);color:var(--ink);
       font-family:var(--font);font-size:12px}
  .sheet{max-width:820px;margin:0 auto;background:#fff;padding:24px;border:1px solid var(--line)}
  .hd{display:flex;align-items:flex-start;gap:14px;border-bottom:3px solid var(--gold);padding-bottom:12px}
  .hd h1{margin:0;font-size:19px;color:var(--navy)}
  .hd .co{font-size:12px;color:var(--muted);margin-top:3px}
  .hd .rt{margin-inline-start:auto;text-align:end;font-size:11px;color:var(--muted)}
  .idbox{display:flex;gap:22px;flex-wrap:wrap;background:var(--bg);border:1px solid var(--line);
         padding:11px 14px;margin:14px 0}
  .idbox div{font-size:12px}
  .idbox em{font-style:normal;color:var(--muted);display:block;font-size:10px}
  .idbox b{color:var(--ink)}
  .nums{display:grid;grid-template-columns:repeat(6,1fr);gap:8px;margin-bottom:14px}
  .num{border:1px solid var(--line);padding:9px;text-align:center}
  .num em{font-style:normal;display:block;color:var(--muted);font-size:10px;margin-bottom:4px}
  .num b{font-size:14px;color:var(--navy)}
  .num.net{border-color:var(--gold);background:#fdf9ec}
  .num.net b{color:var(--gold-d)}
  .eq{border:1px dashed var(--line);padding:9px 12px;margin-bottom:14px;font-size:11px;color:var(--muted)}
  h2{font-size:13px;color:var(--navy);margin:16px 0 7px;border-bottom:1px solid var(--line);padding-bottom:4px}
  table{width:100%;border-collapse:collapse;font-size:11px}
  th,td{border:1px solid var(--line);padding:5px 7px;text-align:start}
  th{background:var(--bg);color:var(--navy);font-weight:700}
  tfoot td{background:var(--bg);font-weight:700}
  .n{text-align:end;white-space:nowrap}
  .two{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  .ft{margin-top:18px;border-top:1px solid var(--line);padding-top:9px;font-size:10px;color:var(--muted);
      display:flex;justify-content:space-between;gap:12px}
  .noprint{text-align:center;margin:14px auto;max-width:820px}
  .noprint button{background:var(--navy);color:#fff;border:0;padding:9px 22px;border-radius:6px;
                  cursor:pointer;font-size:13px;font-family:inherit}
  @media print{
    body{background:#fff;padding:0}
    .sheet{border:0;max-width:none;padding:10mm}
    .noprint{display:none}
    @page{size:A4;margin:10mm}
  }
  @media (max-width:640px){ .nums{grid-template-columns:repeat(2,1fr)} .two{grid-template-columns:1fr} }
</style>
</head>
<body>
<div class="noprint"><button type="button" onclick="window.print()"><?= icon('file-text', 14) ?> اطبع / احفظ PDF</button></div>

<div class="sheet">
  <div class="hd">
    <div>
      <h1>تقرير أداء سيارة</h1>
      <div class="co"><?= e(App::setting('company_name', 'شركة المتوكل')) ?> — <?= e(App::setting('company_tagline', 'لخدمات النقل والتخليص الجمركي')) ?></div>
    </div>
    <div class="rt">
      <div><b><?= e($period['label']) ?></b></div>
      <div>تاريخ الطباعة: <?= e(dfmt(date('Y-m-d'))) ?></div>
    </div>
  </div>

  <div class="idbox">
    <div><em>السيارة</em><b><?= e($car['name']) ?></b></div>
    <div><em>اللوحة</em><b><?= e($car['plate']) ?></b></div>
    <?php if ($car['model']): ?><div><em>الموديل</em><b><?= e($car['model']) ?></b></div><?php endif; ?>
    <?php if ($car['driver']): ?><div><em>السائق الثابت</em><b><?= e($car['driver']) ?></b></div><?php endif; ?>
    <div><em>الحالة</em><b><?= $car['status'] === 'inactive' ? 'متوقفة' : 'شغّالة' ?></b></div>
  </div>

  <div class="nums">
    <div class="num"><em>رحلات</em><b><?= e(intf($perf['trips'])) ?></b></div>
    <div class="num"><em>حاويات</em><b><?= e(intf($perf['containers'])) ?></b></div>
    <div class="num"><em>النولون</em><b><?= e(money0($perf['freight'], false)) ?></b></div>
    <div class="num"><em>عهد للسواق</em><b><?= e(money0($perf['custody'], false)) ?></b></div>
    <div class="num"><em>مصاريف</em><b><?= e(money0($perf['expenses'], false)) ?></b></div>
    <div class="num net"><em>الصافي</em><b><?= e(money0($perf['net'], false)) ?></b></div>
  </div>

  <div class="eq">
    <b>صافي السيارة</b> = النولون − العهد المدفوعة للسواق − مصاريف السيارة.
    رقم استرشادي لأداء السيارة، مش ربح محاسبي.
    ومصاريف السيارة سجل خاص بالسيارات — مش داخلة خزنة المكتب ولا التقفيل اليومي.
  </div>

  <h2>الرحلات (<?= e(intf(count($trips))) ?>)</h2>
  <?php if (!$trips): ?>
    <p style="color:var(--muted)">مفيش رحلات في الفترة دي.</p>
  <?php else: ?>
    <table>
      <thead><tr>
        <th>التاريخ</th><th>البوليصة</th><th>الشركة</th><th>الوجهة</th>
        <th>السائق</th><th>حاويات</th><th class="n">النولون</th><th class="n">العهدة</th>
      </tr></thead>
      <tbody>
      <?php foreach ($trips as $t): ?>
        <tr>
          <td><?= e($t['bill_date'] ? dfmt($t['bill_date']) : '—') ?></td>
          <td><?= e($t['bill_number'] ?: ('#' . (int) $t['id'])) ?></td>
          <td><?= e($t['company'] ?: '—') ?></td>
          <td><?= e($t['destination'] ?: '—') ?></td>
          <td><?= e($t['driver'] ?: '—') ?></td>
          <td><?= e((int) $t['container_count'] > 0
                ? intf((int) $t['container_count']) . '×' . (container_norm($t['container_type']) ?: '—')
                : '—') ?></td>
          <td class="n"><?= e(money0($t['freight'], false)) ?></td>
          <td class="n"><?= e(money0($t['custody'], false)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr>
        <td colspan="5">الإجمالي</td>
        <td><?= e(intf($perf['containers'])) ?></td>
        <td class="n"><?= e(money0($perf['freight'], false)) ?></td>
        <td class="n"><?= e(money0($perf['custody'], false)) ?></td>
      </tr></tfoot>
    </table>
  <?php endif; ?>

  <div class="two">
    <div>
      <h2>مصاريف السيارة</h2>
      <?php if (!$byCat): ?>
        <p style="color:var(--muted)">مفيش مصاريف مسجَّلة.</p>
      <?php else: ?>
        <table>
          <thead><tr><th>النوع</th><th>عدد</th><th class="n">المبلغ</th></tr></thead>
          <tbody>
          <?php foreach ($byCat as $c): ?>
            <tr><td><?= e($c['label']) ?></td><td><?= e(intf($c['cnt'])) ?></td>
                <td class="n"><?= e(money0($c['total'], false)) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr><td colspan="2">الإجمالي</td>
            <td class="n"><?= e(money0($perf['expenses'], false)) ?></td></tr></tfoot>
        </table>
      <?php endif; ?>
    </div>
    <div>
      <h2>الشركات</h2>
      <?php if (!$companies): ?>
        <p style="color:var(--muted)">مفيش رحلات.</p>
      <?php else: ?>
        <table>
          <thead><tr><th>الشركة</th><th>رحلات</th><th class="n">النولون</th></tr></thead>
          <tbody>
          <?php foreach ($companies as $c): ?>
            <tr><td><?= e($c['name']) ?></td><td><?= e(intf($c['trips'])) ?></td>
                <td class="n"><?= e(money0($c['freight'], false)) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
      <?php if ($mix): ?>
        <h2>الحاويات</h2>
        <table>
          <thead><tr><th>التصنيف</th><th class="n">عدد</th></tr></thead>
          <tbody>
          <?php foreach ($mix as $type => $n): ?>
            <tr><td><?= e((string) $type) ?></td><td class="n"><?= e(intf($n)) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <div class="ft">
    <span><?= e(App::setting('company_name', 'شركة المتوكل')) ?> — تقرير داخلي</span>
    <span>المبالغ بالجنيه المصري</span>
  </div>
</div>
</body>
</html>
