<?php
use App\Core\App;
use App\Models\Permit;

/**
 * إيصال تقفيل عهدة مندوب — صفحة A4 مستقلة للطباعة/حفظ PDF (RTL، بلا layout).
 *
 * ⚠️ **الرقم البطل لقطة مثبَّتة** (نفس قاعدة فاتورة الأذونات v20 وكشف صاحب
 *    البيان v12): المبلغ المستلم بيتقرا من `returned` المخزَّن زي ما اتثبّت لحظة
 *    التقفيل — عشان الورقة اللي المندوب وقّع عليها ما تتغيّرش تحت إيده لو اتعدّل
 *    بند بعدين.
 *
 * ⚠️⚠️ **`deposits_total`/`spent_total` متعمّد إنهم مش في الورقة** — ليهم معنيان
 *    مختلفان حسب مسار التقفيل (يوم في `settleZero` · تراكمي في `settle`) ومستحيل
 *    تفرّق بينهم بعد كده، فعرضهم جنب تاريخ اليوم كان بيخلّي الورقة تكدب. المعادلة
 *    المساعدة بتتقرا من **`RepCustody::dayLedger()` نفسها** بتاعة كل الشاشات
 *    ومعنونة صراحةً «وقت الطباعة» فمستحيل تتقري كأنها جزء من اللقطة.
 *
 * ⚠️ جدول التفصيل من `RepCustody::spending()` نفسها، وإجمالي الكاش من **نفس صف
 *    `dayLedger`** — مفيش تعريف تاني للصرف ينفع يختلف عن كارت المندوب.
 *
 * ⚠️ `returned` و`balance` **ممكن يكونوا سالبين** ومعناهم إن المكتب هو اللي دفع
 *    للمندوب (كان فارش من جيبه أكتر من عهدته). ممنوع يتقصّوا على صفر — الاتجاه
 *    بيتقلب في العنوان وفي سطر التوقيع.
 *
 * ⚠️ ولا مبلغ بيخصّ العميل في الورقة دي (`actual_amount` — قاعدة v35): الورقة
 *    بتتسلّم للمندوب.
 *
 * متغيّرات: $rep $s $day $rows $led
 */
$company  = App::setting('company_name', 'شركة المتوكل');
$currency = App::setting('currency', 'ج.م');
$phone    = App::setting('company_phone', '');
$address  = App::setting('company_address', '');

$logoRel = null;
foreach (['assets/img/logo.png', 'assets/img/logo.jpg', 'assets/img/logo.jpeg'] as $cand) {
    if (is_file(App::basePath('public/' . $cand))) { $logoRel = $cand; break; }
}

$ret     = (float) $s['returned'];
$toRep   = $ret < -0.005;                  // سالب = المكتب دفع للمندوب
$amtAbs  = abs($ret);
$headline = $toRep ? 'المكتب دفع للمندوب' : 'المكتب استلم من المندوب';
$signRep  = $toRep ? 'توقيع المندوب (بالاستلام)' : 'توقيع المندوب (بالتسليم)';
$issued   = trim((string) ($s['settled_by'] ?? '')) ?: '—';

// ═══ معادلة اليوم — حيّة من `dayLedger` (مش من أعمدة اللقطة الملتبسة)
$dep   = (float) $led['deposits'];
$tin   = (float) $led['transfer_in'];
$spent = (float) $led['spent'];
$tout  = (float) $led['transfer_out'];
$back2 = (float) $led['returned'];       // كل اللي اتقفل في اليوم ده (شامل الإيصال ده)
$bal   = (float) $led['balance'];
$open  = abs($bal) > 0.005;

// سطور المعادلة — التحويلات بتظهر بس لو ليها قيمة (مفيش صفوف صفر بتزحم الورقة)
$eq = [['عهدة نزلت في اليوم', $dep, '+']];
if (abs($tin) > 0.005)  { $eq[] = ['وصله من زملاء', $tin, '+']; }
$eq[] = ['صرف من عهدته', $spent, '−'];
if (abs($tout) > 0.005) { $eq[] = ['حوّله لزملاء', $tout, '−']; }
$eq[] = ['اتقفل واتسلّم', $back2, '−'];
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
  .pad{padding:14mm 12mm}
  .topbar{height:8px;background:linear-gradient(90deg,var(--gold),var(--navy))}
  .head{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;
    padding-bottom:16px;border-bottom:2px solid var(--line)}
  .brand{display:flex;flex-direction:column;align-items:center;gap:6px}
  .brandlogo{height:78px;width:auto;max-width:230px;object-fit:contain}
  .brand .logo{width:78px;height:78px;border-radius:12px;background:var(--navy);color:var(--gold);
    display:flex;align-items:center;justify-content:center;font-size:42px;font-weight:600}
  .brand .cap{color:var(--navy);font-size:15px;font-weight:600}
  .inv-tag{text-align:left;min-width:210px}
  .inv-tag .word{display:inline-block;background:var(--gold);color:var(--navy);font-size:19px;font-weight:600;
    padding:9px 20px;border-radius:9px}
  .inv-tag .meta{margin-top:12px;font-size:12.5px;color:var(--muted);line-height:2}
  .inv-tag .meta b{color:var(--navy);font-size:14px}

  .rep-card{margin-top:16px;border:1px solid var(--line);border-radius:9px;padding:13px 16px;background:#fcfdfe;
    display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px}
  .rep-card .t{font-size:11px;color:var(--gold);font-weight:600;margin-bottom:5px}
  .rep-card .name{font-size:19px;font-weight:600;color:var(--navy)}
  .rep-card .sub{font-size:12px;color:var(--muted);margin-top:3px}
  .rep-card .side{text-align:left}

  .hero{margin-top:16px;border-radius:11px;background:var(--navy);color:#fff;padding:16px 20px;
    display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}
  .hero .l{font-size:12.5px;color:#dfe8f2;font-weight:500}
  .hero .v{font-size:34px;font-weight:700;color:var(--gold);font-variant-numeric:tabular-nums;line-height:1.3}
  .hero .u{font-size:15px;color:#dfe8f2;font-weight:500}
  .hero .day{text-align:left;font-size:12.5px;color:#dfe8f2;line-height:1.9}
  .hero .day b{display:block;color:#fff;font-size:17px;font-weight:600}

  .sec{margin-top:18px}
  .sec-t{background:var(--navy);color:#fff;padding:8px 14px;border-radius:9px 9px 0 0;font-size:13.5px;font-weight:600;
    display:flex;justify-content:space-between;gap:12px}
  .sec-t span{font-weight:500;color:#dfe8f2;font-size:11.5px}
  table.bills{width:100%;border-collapse:collapse;font-size:11.5px;border:1px solid var(--line);border-top:none}
  table.bills thead th{background:#eef2f7;color:var(--navy);padding:7px 6px;font-weight:600;text-align:center;border-bottom:1px solid var(--line)}
  table.bills tbody td{padding:7px 6px;border-bottom:1px solid var(--line);text-align:center}
  table.bills td.r{text-align:right} table.bills td.num{font-variant-numeric:tabular-nums;font-weight:600}
  table.bills tbody tr:nth-child(even){background:#f8fafc}
  table.bills tfoot td{padding:8px 6px;font-weight:600;color:var(--navy);background:#eef2f7;text-align:center}
  table.bills tfoot td.r{text-align:right}
  table.bills.eq tbody td{font-size:12.5px}
  table.bills tfoot td.zero{color:var(--green)}
  table.bills tfoot td.open{color:var(--red)}
  .off{color:var(--muted);font-weight:500}
  .tag{display:inline-block;border:1px solid var(--line);border-radius:20px;padding:1px 7px;font-size:9.5px;
    color:var(--muted);font-weight:500;margin-inline-start:4px}
  .empty{padding:14px;text-align:center;color:var(--muted);border:1px solid var(--line);border-top:none;font-size:12px}

  .words{margin-top:16px;border:1px dashed var(--gold);background:var(--gold-l);border-radius:9px;padding:12px 16px}
  .words .t{font-size:11px;color:var(--gold-d);font-weight:600;margin-bottom:5px}
  .words .v{font-size:14px;font-weight:500;color:var(--navy);line-height:1.8}
  .words.note{border-style:solid;background:#fcfdfe;border-color:var(--line)}
  .words.note .t{color:var(--gold)}

  .foot{margin-top:26px;padding-top:16px;border-top:2px solid var(--line);display:grid;grid-template-columns:1fr 1fr;gap:40px}
  .sign{text-align:center}
  .sign .line{margin-top:30px;border-top:1.5px dashed var(--muted);padding-top:6px;color:var(--muted);font-size:12.5px;font-weight:500}
  .pagefoot{text-align:center;color:var(--muted);font-size:10.5px;padding:10px 0 16px}
  .wm{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
    font-size:340px;font-weight:700;color:rgba(21,53,91,.035);pointer-events:none;z-index:0}
  .pad>*{position:relative;z-index:1}

  .bar{max-width:210mm;margin:0 auto;display:flex;gap:10px;justify-content:center;padding:6px;flex-wrap:wrap}
  .btn{padding:10px 22px;border:none;border-radius:9px;font-weight:600;font-size:14px;cursor:pointer;
    font-family:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:8px}
  .btn.gold{background:var(--gold);color:var(--navy)} .btn.ghost{background:#fff;color:var(--navy);border:1.5px solid var(--navy)}
  /* ⚠️ رسالة نجاح التقفيل بتتعرض هنا لأن الصفحة دي بقت **وجهة** التسوية نفسها
     (مش بس رابط من الكارت). من غير عرضها كانت هتفضل مخزَّنة وتطلع فجأة على
     أول صفحة عادية بعد كده — والمالك يشوف «تمت التسوية» في مكان مالوش علاقة. */
  .flashbar{max-width:210mm;margin:10px auto 0;padding:0 6px}
  .flashbar .msg{border-radius:9px;padding:10px 14px;font-size:13.5px;font-weight:500;
    background:#e9f7ef;color:#186a3b;border:1px solid #a9dfbf}
  .flashbar .msg.err{background:#fdedec;color:#922b21;border-color:#f5b7b1}
  @media print{ @page{size:A4;margin:0} html,body{background:#fff} .noprint{display:none!important}
    .sheet{margin:0;box-shadow:none;width:auto;min-height:auto} }
</style>
</head>
<body>

<?php $flashes = flash_all(); ?>
<?php if ($flashes): ?>
<div class="flashbar noprint">
  <?php foreach ($flashes as $f): ?>
    <div class="msg<?= $f['type'] === 'success' ? '' : ' err' ?>"><?= e($f['msg']) ?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="bar noprint">
  <button class="btn gold" onclick="window.print()"><?= icon('file-text', 14) ?> طباعة / حفظ PDF</button>
  <?php partial('back_link', ['to' => 'rep-custody/' . (int) $rep['id'] . '?tab=account', 'label' => 'رجوع لكارت المندوب']); ?>
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
        <span class="word">إيصال تقفيل عهدة</span>
        <div class="meta">
          رقم الإيصال: <b>#<?= e($s['id']) ?></b><br>
          تاريخ التقفيل: <b><?= e(dfmt($s['settle_date'])) ?></b>
        </div>
      </div>
    </div>

    <div class="rep-card">
      <div>
        <div class="t">المندوب</div>
        <div class="name"><?= e($rep['name']) ?></div>
        <div class="sub">
          <?php if (!empty($rep['phone'])): ?><bdi dir="ltr"><?= e($rep['phone']) ?></bdi> • <?php endif; ?>
          قفّل الحساب: <?= e($issued) ?>
        </div>
      </div>
      <div class="side">
        <div class="t">وقت التسجيل</div>
        <div class="name" style="font-size:15px"><?= e(dtfmt($s['created_at'])) ?></div>
      </div>
    </div>

    <?php /* ═══ الرقم البطل — المبلغ المثبَّت لحظة التقفيل ═══ */ ?>
    <div class="hero">
      <div>
        <div class="l"><?= e($headline) ?></div>
        <div class="v"><?= e(intf($amtAbs)) ?> <span class="u"><?= e($currency) ?></span></div>
      </div>
      <?php /* ⚠️ نص محايد عن قصد: «اتقفلت بالكامل» كانت بتتناقض مع المعادلة تحت
                 لو اليوم لسه مفتوح (تسوية جزئية أو صرف اتضاف بعد التقفيل). */ ?>
      <div class="day">
        عن عهدة يوم
        <b><?= e(dfmt($day)) ?></b>
      </div>
    </div>

    <?php /* ═══ معادلة اليوم — حيّة من dayLedger، معنونة «وقت الطباعة» ═══ */ ?>
    <div class="sec">
      <div class="sec-t">
        <b>حركة عهدة يوم <?= e(dfmt($day)) ?></b>
        <span>مقروءة وقت الطباعة</span>
      </div>
      <table class="bills eq">
        <tbody>
        <?php foreach ($eq as [$lbl, $val, $sign]): ?>
          <tr><td class="r"><?= e($sign) ?> <?= e($lbl) ?></td>
              <td class="num" style="width:120px"><?= money($val, false) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr>
          <td class="r">= <?= $open ? ($bal < 0 ? 'المكتب لسه مدين له عن اليوم ده' : 'لسه فاضل معاه عن اليوم ده')
                                     : 'اليوم مقفول بالكامل' ?></td>
          <td class="num <?= $open ? 'open' : 'zero' ?>"><?= money(abs($bal), false) ?></td>
        </tr></tfoot>
      </table>
    </div>

    <?php /* ═══ تفصيل صرف اليوم — حيّ من RepCustody::spending() ═══ */ ?>
    <div class="sec">
      <div class="sec-t">
        <b>تفصيل صرف يوم <?= e(dfmt($day)) ?></b>
        <span>مقروء وقت الطباعة</span>
      </div>
      <?php if (!$rows): ?>
        <div class="empty">مفيش بنود اتصرفت من العهدة في اليوم ده.</div>
      <?php else: ?>
        <table class="bills">
          <thead><tr>
            <th style="width:26px">#</th><th style="width:88px">النوع</th>
            <th>البيان</th><th style="width:120px">المرجع</th>
            <th style="width:44px">كمية</th><th style="width:86px">المبلغ</th>
          </tr></thead>
          <tbody>
          <?php foreach ($rows as $i => $r):
            $src = $r['src'] ?? 'permit';
            $isReceipt = $src === 'permit' && ($r['stage_key'] ?? '') === 'receipts';
            $kind = match ($src) {
                'misc'     => $r['category'] ?: 'نثرية',
                'handover' => 'تسليم سائق',
                'bill'     => 'عهدة بوليصة',
                'transfer' => 'تحويل لمندوب',
                default    => $isReceipt ? 'إيصال' : 'مصروف إذن',
            };
            // المرجع: الإذن أو البوليصة أو المندوب المستقبِل — حسب المصدر
            $ref = match ($src) {
                'misc'     => 'مصروف عام بلا إذن',
                'handover',
                'bill'     => trim(($r['driver_name'] ?: '')
                              . (!empty($r['car_no']) ? ' — ' . $r['car_no'] : '')) ?: '—',
                'transfer' => 'لـ ' . ($r['other_name'] ?? '—'),
                default    => !empty($r['permit_no'])
                              ? Permit::noLabel($r['permit_type']) . ' ' . $r['permit_no'] : '—',
            };
            // ⚠️ الفيزا و«غير مدفوع» بيتعرضوا للعلم وما بيتحسبوش في الإجمالي
            //    (نفس شرط `RepCustody::spentTotal` بالظبط).
            // ⚠️⚠️ **والتحويل لزميل زيّهم بالظبط** — بيتخصم من العهدة في المعادلة
            //    فوق («حوّله لزملاء») مش من `spentTotal`، فلو اتعرض هنا كأنه محسوب
            //    يبقى مجموع أعمدة الجدول ≠ إجمالي الجدول نفسه، والقارئ يجمع بإيده
            //    ويلاقي فرقًا بلا تفسير (اتصاد فعليًّا: إيصال #366 — الأعمدة
            //    109,925 والإجمالي 94,925، والفرق هو تحويل 15,000).
            $cash = $src !== 'transfer' && empty($r['is_visa']) && empty($r['unpaid']);
          ?>
            <tr>
              <td><?= $i + 1 ?></td>
              <td><?= e($kind) ?></td>
              <td class="r"><?= e($r['note'] ?: $kind) ?>
                <?php if (!empty($r['is_visa'])): ?><span class="tag">فيزا — مش من العهدة</span><?php endif; ?>
                <?php if (!empty($r['unpaid'])): ?><span class="tag">غير مدفوع</span><?php endif; ?>
                <?php if ($src === 'transfer'): ?><span class="tag">محسوب في «حوّله لزملاء»</span><?php endif; ?>
              </td>
              <td><?= e($ref) ?><?php if (!empty($r['ref_no'])): ?>
                  <span class="tag">إيصال <?= e($r['ref_no']) ?></span><?php endif; ?></td>
              <td><?= (int) ($r['qty'] ?? 1) > 1 ? intf((int) $r['qty']) : '—' ?></td>
              <td class="num<?= $cash ? '' : ' off' ?>"><?= money($r['amount'], false) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <?php /* ⚠️ الإجمالي من نفس صف `dayLedger` بتاع المعادلة فوق — مش مجموع
                     أعمدة الجدول، عشان الفيزا و«غير مدفوع» ما يتحسبوش. */ ?>
          <tfoot><tr>
            <td colspan="5" class="r">إجمالي المصروف كاش من العهدة في اليوم ده</td>
            <td class="num"><?= money($spent, false) ?></td>
          </tr></tfoot>
        </table>
      <?php endif; ?>
    </div>

    <div class="words">
      <div class="t">المبلغ بالحروف</div>
      <div class="v"><?= e(tafqit($amtAbs)) ?></div>
    </div>

    <?php if (trim((string) ($s['note'] ?? '')) !== ''): ?>
      <div class="words note">
        <div class="t">ملاحظة</div><div class="v"><?= e($s['note']) ?></div>
      </div>
    <?php endif; ?>

    <div class="foot">
      <div class="sign"><div class="line"><?= e($signRep) ?></div></div>
      <div class="sign"><div class="line">المحاسب / الختم</div></div>
    </div>

  </div>
  <div class="pagefoot">
    <?= e($company) ?> — لمقاولات النقل
    <?php if ($phone): ?>&nbsp;•&nbsp; <bdi dir="ltr"><?= e($phone) ?></bdi><?php endif; ?>
    <?php if ($address): ?>&nbsp;•&nbsp; <?= e($address) ?><?php endif; ?>
    &nbsp;•&nbsp; صدر بتاريخ <?= e(dfmt($s['settle_date'])) ?>
  </div>
</div>

</body>
</html>
