<?php
/**
 * تقرير التشغيل — صفحة A4 للطباعة أو الحفظ PDF.
 *
 * ⚠️ **الطباعة دايمًا فاتحة** (المرحلة ١٥) — الوضع الليلي بياكل الحبر.
 * ⚠️ توكنز الهوية من `partials/print_base.php` (مصدر واحد لكل مستندات A4).
 * ⚠️ **مفيش نولون في المستند ده عن قصد** — قرار المالك في الوحدة كلها.
 *
 * `?detail=1` بيضيف جدول كل بوليصة؛ من غيره التقرير ملخّص في صفحة أو اتنين.
 *
 * متغيّرات: $period $totals $ports $companies $agencies $dests $clients
 *           $mix $permits $trips $detail
 */
use App\Core\App;

$co = (string) App::setting('company_name', 'شركة المتوكل');
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
  .nums{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-bottom:12px}
  .num{border:1px solid var(--line);padding:9px;text-align:center}
  .num em{font-style:normal;display:block;color:var(--muted);font-size:10px;margin-bottom:4px}
  .num b{font-size:15px;color:var(--navy)}
  .num.hi{border-color:var(--gold);background:#fdf9ec}
  .num.hi b{color:var(--gold-d)}
  .eq{border:1px dashed var(--line);padding:9px 12px;margin-bottom:14px;font-size:11px;color:var(--muted);
      line-height:1.7}
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
    table{page-break-inside:auto}
    tr{page-break-inside:avoid}
    @page{size:A4;margin:10mm}
  }
  @media (max-width:640px){ .nums{grid-template-columns:repeat(2,1fr)} .two{grid-template-columns:1fr} }
</style>
</head>
<body>

<div class="noprint">
  <button type="button" onclick="window.print()">🖨️ اطبع أو احفظ PDF</button>
</div>

<div class="sheet">
  <div class="hd">
    <div>
      <h1>تقرير التشغيل</h1>
      <div class="co"><?= e($co) ?></div>
    </div>
    <div class="rt">
      اتطبع: <?= e(dfmt(date('Y-m-d'))) ?><br>
      <?= $detail ? 'نسخة مفصّلة' : 'نسخة ملخّصة' ?>
    </div>
  </div>

  <div class="idbox">
    <div><em>الفترة</em><b><?= e($period['label']) ?></b></div>
    <?php if ($period['from'] !== null): ?>
      <div><em>من</em><b><?= e(dfmt($period['from'])) ?></b></div>
    <?php endif; ?>
    <?php if ($period['to'] !== null): ?>
      <div><em>إلى</em><b><?= e(dfmt($period['to'])) ?></b></div>
    <?php endif; ?>
    <div><em>موانئ</em><b><?= e(intf($totals['ports'])) ?></b></div>
    <div><em>شركات</em><b><?= e(intf($totals['companies'])) ?></b></div>
  </div>

  <div class="nums">
    <div class="num hi"><em>حاويات اتداولت</em><b><?= e(intf($totals['containers'])) ?></b></div>
    <div class="num"><em>بوالص</em><b><?= e(intf($totals['trips'])) ?></b></div>
    <div class="num"><em>أذونات وشهادات</em><b><?= e(intf($totals['permits'])) ?></b></div>
    <div class="num hi"><em>عهد اتدفعت</em><b><?= e(money0($totals['custody'], false)) ?></b></div>
    <div class="num"><em>المخصوم من الخزنة</em><b><?= e(money0($totals['deducted'], false)) ?></b></div>
  </div>

  <div class="eq">
    <b>«العهد اللي اتدفعت»</b> = الفلوس اللي خرجت من إيد الشركة للسواقين كاملة.
    <b>«المخصوم من الخزنة»</b> أقل منها لأن كل بوليصة بيتطرح منها ١٠٠
    (والسيارة المستثناة بتتخصم كاملة) — نفس حساب رصيد خزنة المكتب.<br>
    <b>النولون مش داخل في التقرير ده</b>، والبوالص المُرحَّلة من الأرشيف والبوالص بلا تاريخ مستبعدة.
  </div>

  <?php
  /* جدول تقسيم — نسخة واحدة لكل الأبعاد عشان الأعمدة ما تفترقش بينهم. */
  $dimTable = static function (string $head, array $rows, string $unit, int $totalC): void { ?>
    <h2><?= e($head) ?></h2>
    <?php if (!$rows): ?>
      <p style="color:var(--muted);font-size:11px">مفيش حركة في الفترة دي.</p>
    <?php else: ?>
      <table>
        <thead><tr>
          <th><?= e($unit) ?></th><th class="n">حاويات</th><th class="n">٪</th>
          <th class="n">بوالص</th><th class="n">أذونات</th><th class="n">عهد</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= e($r['name']) ?></td>
            <td class="n"><?= e(intf($r['containers'])) ?></td>
            <td class="n"><?= $totalC > 0 ? round($r['containers'] / $totalC * 100) : 0 ?>٪</td>
            <td class="n"><?= e(intf($r['trips'])) ?></td>
            <td class="n"><?= e(intf($r['permits'])) ?></td>
            <td class="n"><?= e(money0($r['custody'], false)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php };
  ?>

  <?php $dimTable('الموانئ', $ports, 'الميناء', $totals['containers']); ?>
  <?php $dimTable('الشركات', $companies, 'الشركة', $totals['containers']); ?>

  <h2>تصنيف الحاويات</h2>
  <?php if (!$mix): ?>
    <p style="color:var(--muted);font-size:11px">مفيش حاويات في الفترة دي.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>التصنيف</th><th class="n">عدد الحاويات</th><th class="n">٪</th><th class="n">بوالص</th></tr></thead>
      <tbody>
      <?php foreach ($mix as $m): ?>
        <tr>
          <td><?= e($m['name']) ?></td>
          <td class="n"><?= e(intf($m['containers'])) ?></td>
          <td class="n"><?= $totals['containers'] > 0 ? round($m['containers'] / $totals['containers'] * 100) : 0 ?>٪</td>
          <td class="n"><?= e(intf($m['trips'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr>
        <td>الإجمالي</td><td class="n"><?= e(intf($totals['containers'])) ?></td>
        <td class="n">١٠٠٪</td><td class="n"><?= e(intf($totals['trips'])) ?></td>
      </tr></tfoot>
    </table>
  <?php endif; ?>

  <?php $dimTable('التوكيلات الملاحية', $agencies, 'التوكيل', $totals['containers']); ?>
  <?php $dimTable('الوجهات', $dests, 'الوجهة', $totals['containers']); ?>
  <?php $dimTable('العملاء', $clients, 'العميل', $totals['containers']); ?>

  <h2>الأذونات والشهادات اللي اتحرّك عليها</h2>
  <?php if (!$permits): ?>
    <p style="color:var(--muted);font-size:11px">مفيش أذونات اتحرّك عليها في الفترة دي.</p>
  <?php else: ?>
    <table>
      <thead><tr>
        <th>الإذن / الشهادة</th><th>العميل</th><th>الشركة</th><th>الميناء</th>
        <th class="n">حاويات اتحرّكت</th><th class="n">بوالص</th><th class="n">عهد</th>
      </tr></thead>
      <tbody>
      <?php foreach ($permits as $p): ?>
        <tr>
          <td><?= e(($p['permit_type'] === 'warid' ? 'شهادة ' : 'إذن ') . ($p['permit_no'] ?: '#' . (int) $p['id'])) ?></td>
          <td><?= e((string) ($p['client'] ?? '')) ?></td>
          <td><?= e((string) ($p['company'] ?? '')) ?></td>
          <td><?= e((string) ($p['port'] ?? '')) ?></td>
          <td class="n"><?= e(intf((int) $p['containers'])) ?><?= (int) $p['permit_containers'] > 0 ? ' / ' . e(intf((int) $p['permit_containers'])) : '' ?></td>
          <td class="n"><?= e(intf((int) $p['trips'])) ?></td>
          <td class="n"><?= e(money0($p['custody'], false)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p style="color:var(--muted);font-size:10px;margin-top:5px">
      «حاويات اتحرّكت / حاويات الإذن» — الأول من البوالص اللي في الفترة، والتاني إجمالي الإذن.
    </p>
  <?php endif; ?>

  <?php if ($detail): ?>
    <h2>كل البوالص بالتفصيل</h2>
    <?php if (!$trips): ?>
      <p style="color:var(--muted);font-size:11px">مفيش بوالص في الفترة دي.</p>
    <?php else: ?>
      <table>
        <thead><tr>
          <th class="n">#</th><th>التاريخ</th><th>البوليصة</th><th>الإذن</th><th>الميناء</th>
          <th>الشركة</th><th>الوجهة</th><th>السيارة</th><th>السواق</th>
          <th class="n">حاويات</th><th>التصنيف</th><th class="n">عهدة</th>
        </tr></thead>
        <tbody>
        <?php $i = 0; foreach ($trips as $t): $i++; ?>
          <tr>
            <td class="n"><?= e(intf($i)) ?></td>
            <td><?= e(dfmt($t['bill_date'])) ?></td>
            <td><?= e((string) ($t['bill_number'] ?? '')) ?></td>
            <td><?= e((string) ($t['permit_no'] ?? '')) ?></td>
            <td><?= e((string) ($t['port'] ?? '')) ?></td>
            <td><?= e((string) ($t['company'] ?? '')) ?></td>
            <td><?= e((string) ($t['destination'] ?? '')) ?></td>
            <td><?= e((string) ($t['car_no'] ?? '')) ?></td>
            <td><?= e((string) ($t['driver'] ?? '')) ?></td>
            <td class="n"><?= e(intf((int) $t['container_count'])) ?></td>
            <td><?= e((string) (container_norm($t['container_type'] ?? '') ?? '')) ?></td>
            <td class="n"><?= e(money0($t['custody'], false)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr>
          <td colspan="9">الإجمالي</td>
          <td class="n"><?= e(intf($totals['containers'])) ?></td>
          <td></td>
          <td class="n"><?= e(money0($totals['custody'], false)) ?></td>
        </tr></tfoot>
      </table>
    <?php endif; ?>
  <?php endif; ?>

  <div class="ft">
    <span><?= e($co) ?> — تقرير التشغيل</span>
    <span>الأرقام كلها بالجنيه المصري · النولون غير مشمول</span>
  </div>
</div>

</body>
</html>
