<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * مزامنة بوالص النظام من ملف الإكسل (ورقة «بوالص» مُصدَّرة CSV) — عملية لمرة واحدة.
 * ------------------------------------------------------------------------------
 * الخلفية: أثناء بناء النظام فضل الإكسل شغّال بالتوازي، فبنود اتسجّلت فيه من غير
 * النظام. السكربت ده بيسدّ الفرق ده **بنطاق محدود ومُوافَق عليه من المالك**:
 *
 *   ① النولون (freight)  — الإكسل هو المرجع. يشمل البوالص اللي نولونها صفر
 *                          (حالة «النولون المؤجَّل» pending) واللي قيمتها مختلفة.
 *   ② الدفعات (payment)  — الفرق بين «دفعه» في الإكسل والمسجَّل في النظام يتسجّل
 *                          دفعة تاريخية `bill_payments.is_legacy=1` بتاريخ البوليصة.
 *   ③ البوالص الناقصة    — تتضاف كبوليصة أرشيفية (is_legacy=1, legacy_id=NULL).
 *
 * ⚠️⚠️ برّه النطاق عن قصد (قرار المالك 2026-08-02) — لا تضيفها بلا مراجعة:
 *   • **العهدة (custody)** — بتمسّ رصيد الخزنة والأيام متقفلة. تتقفل في التقرير بس.
 *   • **الأسماء** (صاحب البيان/الشركة/الجهة/السائق/صاحب السيارة) — النظام أنضف من
 *     الإكسل (توحيد التهجئة + السجل المرجعي للجهات v59 + الاسم القانوني v38).
 *     الكتابة فوقها بتلغي شغل تنظيف البيانات كله.
 *   • **التصنيف (classification)** — النظام مطبَّع بـ`container_norm` (v30).
 *   • **أرقام البوالص** — تتصلّح بـ`--fix-numbers` صراحةً بس (تفاصيل في التقرير).
 *
 * ⚠️ العزل المالي: `freight` **مالوش أي أثر** على الخزنة/التقفيل/العهدة — متحقَّق
 *    إنه مش موجود في `Closing` ولا `Custody`، ومبلغ بند البوليصة في شاشة مراجعة
 *    التقفيل محسوب من `Custody::billDeduction` (العهدة) لا من النولون، فعلامات
 *    المراجعة ما بتسقطش. و`bill_payments` مش داخل رصيد الخزنة، والدفعة الموسومة
 *    `is_legacy=1` مستبعدة كمان من مؤشرات التحصيل اليومية (`is_legacy=0`).
 *    السكربت بيقيس رصيد الخزنة قبل↔بعد ويرفض التنفيذ لو اتغيّر.
 *
 * ⚠️ كل الحسابات بتمرّ على `Calc::bawala` و`Bill::recalc` — **مفيش تعريف تاني للدين**.
 *
 * idempotent: إعادة التشغيل ما بتعملش أي تغيير (بيقارن قبل ما يكتب).
 *
 * التشغيل:
 *   php bin/sync_bawalis_from_excel.php --csv=/path/to/بوالص.csv            # معاينة
 *   php bin/sync_bawalis_from_excel.php --csv=... --apply                   # تنفيذ
 *   php bin/sync_bawalis_from_excel.php --csv=... --apply --fix-numbers     # + تصحيح الأرقام
 *   php bin/sync_bawalis_from_excel.php --csv=... --report=/path/report.csv  # كشف المراجعة
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Calc;
use App\Core\Database;
use App\Models\Bill;

/* ─────────────────────────── المعاملات ─────────────────────────── */
$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) { $opts[$m[1]] = $m[2] ?? true; }
}
$APPLY      = isset($opts['apply']);
$FIX_NUM    = isset($opts['fix-numbers']);
$CSV        = (string) ($opts['csv'] ?? '');
$REPORT     = (string) ($opts['report'] ?? '');
$YEAR       = (int) ($opts['year'] ?? 2026);

if ($CSV === '' || !is_file($CSV)) {
    fwrite(STDERR, "لازم تحدّد ملف الإكسل المُصدَّر: --csv=/path/to/file.csv\n");
    exit(1);
}

$c = fn(string $s, string $col = '') => $col === '' ? $s : "\033[{$col}m{$s}\033[0m";
$line = fn(string $ch = '─') => str_repeat($ch, 78);

/* ─────────────────────────── أدوات التنظيف ─────────────────────────── */
/** نفس منطق export_legacy_bawalis.py — الفراغ والرموز غير المفيدة → null */
function xclean($v): ?string {
    if ($v === null) { return null; }
    $v = trim((string) $v);
    if ($v === '' || in_array($v, ['؟؟','؟','??','#','-','—'], true)) { return null; }
    if (str_starts_with($v, '#')) { return null; }
    return $v;
}
function xnum($v): float {
    $v = xclean($v);
    if ($v === null) { return 0.0; }
    $v = str_replace([',', '٬', ' '], '', ar2en_digits($v));
    return is_numeric($v) ? round((float) $v, 2) : 0.0;
}
function xtidy($v): ?string {
    $v = xclean($v);
    if ($v === null) { return null; }
    $v = preg_replace('/\s+/u', ' ', $v);
    return trim($v) ?: null;
}
/** رقم بوليصة مُطبّع للمطابقة: أرقام إنجليزية بلا أصفار بادئة ولا مسافات */
function bkey(?string $n): string {
    if ($n === null) { return ''; }
    $s = preg_replace('/\s+/u', '', ar2en_digits(trim($n)));
    if ($s === '') { return ''; }
    return preg_match('/^\d+$/', $s) ? (string) (int) $s : $s;
}
/** بصمة العربية: أرقام + حروف مرتّبة — فالترتيب والمسافات ما يفرقوش */
function carkey(?string $v): string {
    if ($v === null) { return ''; }
    $s = ar2en_digits($v);
    $digits = preg_replace('/\D+/u', '', $s);
    preg_match_all('/[^\d\s]/u', $s, $m);
    $letters = $m[0] ?? [];
    sort($letters);
    return $digits . '|' . implode('', $letters);
}
/** رقم حاوية مجرّد للمقارنة: أرقام إنجليزية بلا فواصل ولا أصفار بادئة */
function cnorm(?string $v): string {
    if ($v === null) { return ''; }
    $s = preg_replace('/[^0-9A-Za-z]+/u', '', ar2en_digits($v));
    if ($s === '') { return ''; }
    return ltrim($s, '0') !== '' ? ltrim($s, '0') : $s;
}
/** '1-Jan' → '2026-01-01' */
function xdate(?string $v, int $year): ?string {
    $v = trim((string) $v);
    if ($v === '') { return null; }
    if (preg_match('/^(\d{1,2})-([A-Za-z]{3})$/', $v, $m)) {
        $mo = date_parse($m[2])['month'] ?? false;
        if ($mo) {
            $d = sprintf('%04d-%02d-%02d', $year, $mo, (int) $m[1]);
            return checkdate($mo, (int) $m[1], $year) ? $d : null;
        }
    }
    foreach (['Y-m-d', 'd/m/Y', 'm/d/Y'] as $f) {
        $dt = DateTime::createFromFormat($f, $v);
        if ($dt && $dt->format($f) === $v) { return $dt->format('Y-m-d'); }
    }
    return null;
}

/* ─────────────────────────── قراءة الـCSV ─────────────────────────── */
/** فهارس أعمدة ورقة «بوالص» (0-based) — نفس ترتيب export_legacy_bawalis.py */
const COL = [
    'declaration_owner' => 0, 'date' => 1, 'bill_number' => 2, 'classification' => 3,
    'company' => 4, 'destination' => 5, 'container_no' => 6, 'car_no' => 7,
    'driver' => 8, 'car_owner' => 9,
    'freight' => 10, 'custody' => 11, 'transfer' => 12, 'discount' => 13,
    'overnight_collection' => 14, 'remaining' => 15, 'payment' => 16, 'total' => 17,
];

$fh = fopen($CSV, 'r');
if (!$fh) { fwrite(STDERR, "تعذّر فتح الملف\n"); exit(1); }
$excel = [];
$rowNo = 0;
while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {   // $escape صريح (PHP 8.4)
    $rowNo++;
    if ($rowNo === 1) {                                   // ترويسة (مع تنظيف BOM)
        continue;
    }
    $r = array_pad($r, 40, '');
    if (trim((string) $r[COL['date']]) === '' && trim((string) $r[COL['bill_number']]) === '') { continue; }
    $bn = xclean($r[COL['bill_number']]);
    $excel[] = [
        'src_row'     => $rowNo,
        'bill_number' => $bn === null ? null : (string) $bn,
        'bill_date'   => xdate($r[COL['date']], $YEAR),
        'raw_date'    => trim((string) $r[COL['date']]),
        'car_no'      => xtidy($r[COL['car_no']]),
        'company'     => xtidy($r[COL['company']]),
        'driver'      => xtidy($r[COL['driver']]),
        'declaration_owner' => xtidy($r[COL['declaration_owner']]),
        'destination' => xtidy($r[COL['destination']]),
        'classification'    => xtidy($r[COL['classification']]),
        'container_no'=> xtidy($r[COL['container_no']]),
        'car_owner'   => xtidy($r[COL['car_owner']]),
        'freight'     => xnum($r[COL['freight']]),
        'custody'     => xnum($r[COL['custody']]),
        'transfer'    => xnum($r[COL['transfer']]),
        'discount'    => xnum($r[COL['discount']]),
        'overnight_collection' => xnum($r[COL['overnight_collection']]),
        'payment'     => xnum($r[COL['payment']]),
    ];
}
fclose($fh);

$badDate = array_filter($excel, fn($e) => $e['bill_date'] === null);

/* ─────────────────────────── تحميل النظام ─────────────────────────── */
$sys = Database::all(
    'SELECT id, bill_number, bill_date, car_no, is_legacy, freight, custody, transfer,
            discount, overnight_collection, payment, paid, debt, remaining, pay_status,
            declaration_owner, company, destination, driver, car_owner, container_no, classification
       FROM bawalis ORDER BY id'
);
$sysById = [];
foreach ($sys as $b) { $sysById[(int) $b['id']] = $b; }

/* ─────────────────────────── المطابقة ─────────────────────────── */
/* ① بالرقم المُطبّع (واحد لواحد) ② بالرقم + البصمة للمكرّرات
   ③ بالبصمة وحدها (تاريخ+عربية+عهدة) — بتلقط الصفوف اللي رقمها اتصلّح في الإكسل */
$fp = fn(?string $date, ?string $car, float $cust) => ($date ?? '') . '#' . carkey($car) . '#' . number_format($cust, 2, '.', '');

$sysByKey = $csvByKey = [];
foreach ($sys as $b) {
    $k = bkey($b['bill_number']);
    if ($k !== '') { $sysByKey[$k][] = (int) $b['id']; }
}
foreach ($excel as $i => $e) {
    $k = bkey($e['bill_number']);
    if ($k !== '') { $csvByKey[$k][] = $i; }
}

$pairs = [];                 // excelIndex => ['sid'=>int,'how'=>string]
$usedSys = $usedCsv = [];

foreach ($csvByKey as $k => $idxs) {
    $sids = $sysByKey[$k] ?? [];
    if (count($idxs) === 1 && count($sids) === 1) {
        $pairs[$idxs[0]] = ['sid' => $sids[0], 'how' => 'bill'];
        $usedCsv[$idxs[0]] = true; $usedSys[$sids[0]] = true;
    }
}
foreach ($csvByKey as $k => $idxs) {
    $sids = array_values(array_filter($sysByKey[$k] ?? [], fn($s) => !isset($usedSys[$s])));
    foreach ($idxs as $i) {
        if (isset($usedCsv[$i])) { continue; }
        $e = $excel[$i];
        $want = $fp($e['bill_date'], $e['car_no'], $e['custody']);
        foreach ($sids as $j => $sid) {
            $b = $sysById[$sid];
            if ($fp(substr((string) $b['bill_date'], 0, 10), $b['car_no'], (float) $b['custody']) === $want) {
                $pairs[$i] = ['sid' => $sid, 'how' => 'bill+fp'];
                $usedCsv[$i] = true; $usedSys[$sid] = true;
                unset($sids[$j]);
                break;
            }
        }
    }
}
$fpSys = [];
foreach ($sys as $b) {
    $sid = (int) $b['id'];
    if (isset($usedSys[$sid])) { continue; }
    $key = $fp(substr((string) $b['bill_date'], 0, 10), $b['car_no'], (float) $b['custody']);
    if (substr($key, 0, 1) !== '#') { $fpSys[$key][] = $sid; }
}
foreach ($excel as $i => $e) {
    if (isset($usedCsv[$i]) || $e['bill_date'] === null || $e['car_no'] === null) { continue; }
    $key = $fp($e['bill_date'], $e['car_no'], $e['custody']);
    $cand = array_values(array_filter($fpSys[$key] ?? [], fn($s) => !isset($usedSys[$s])));
    if (count($cand) === 1) {
        $pairs[$i] = ['sid' => $cand[0], 'how' => 'fingerprint'];
        $usedCsv[$i] = true; $usedSys[$cand[0]] = true;
    }
}

$onlyExcel = array_values(array_filter(array_keys($excel), fn($i) => !isset($usedCsv[$i])));
$onlySys   = array_values(array_filter(array_map(fn($b) => (int) $b['id'], $sys), fn($s) => !isset($usedSys[$s])));

/* ⚠️⚠️ حارس النطاق الزمني — أهم حارس في السكربت.
   البوليصة الناقصة بتتضاف كأرشيف `is_legacy=1`، وده معناه إنها **خارج رصيد
   الخزنة والتقفيل تمامًا** (v26). ده صح للبوالص القديمة اللي فترتها خلصت
   وأيامها متقفلة، وغلط تمامًا لبوليصة في فترة النظام الحي: عهدتها **المفروض**
   تخصم من الخزنة وتترّبط بإذن وحركة، وإضافتها أرشيفية بتخبّي الكاش ده بالسكوت.
   عشان كده أي بوليصة ناقصة تاريخها بعد آخر يوم أرشيفي بتترفض وتتقفل في التقرير
   عشان تتدخل يدويًا من جوّه الإذن. (ولو القاعدة اللي بتشتغل عليها متأخرة عن
   الإنتاج، الحارس ده هو اللي بيمنعها من زرع مئات البوالص المكرّرة أرشيفيًا.) */
$legacyCutoff = (string) (Database::scalar('SELECT MAX(bill_date) FROM bawalis WHERE is_legacy = 1') ?: '');
$addable = $deferred = [];
foreach ($onlyExcel as $i) {
    $d = $excel[$i]['bill_date'];
    if ($legacyCutoff !== '' && $d !== null && $d <= $legacyCutoff) { $addable[] = $i; }
    else { $deferred[] = $i; }
}

/* ─────────────────────────── حساب التغييرات ─────────────────────────── */
/* الحقول النصّية اللي بيتملا **الفاضي** منها من الإكسل. `container_no` مستثنى
   (له فرع «الإكسل أشمل»)، و`classification` مستثنى كمان لأن نفس البيان مخزَّن
   مطبَّعًا في `container_type` (متحقَّق: متطابقين في كل الـ1738 صف اللي فيهم
   الاتنين) — فملؤه بيكرّر بيانًا موجودًا بصيغة قديمة (`1*40` بدل `40 قدم`). */
const BLANK_FILL = [
    'declaration_owner' => 'صاحب البيان',
    'company'           => 'الشركة',
    'destination'       => 'الجهة',
    'car_owner'         => 'صاحب السيارة',
    'driver'            => 'السائق',
    'car_no'            => 'رقم السيارة',
];

$chFreight = [];   // تغيير النولون
$chBlank   = [];   // خانة نصّية فاضية في النظام والإكسل فيه قيمة
$chDebtFld = [];   // تحويلة/خصم/بياتة ناقصة من النظام
$chContain = [];   // رقم حاوية الإكسل أشمل من النظام
$chPayment = [];   // دفعات ناقصة
$chNumber  = [];   // رقم بوليصة مختلف
$revCustody = [];  // مراجعة: فروق العهدة (مش بتتنفّذ)
$revNames   = [];  // مراجعة: فروق الأسماء (مش بتتنفّذ)
$payLower   = [];  // مراجعة: النظام فيه دفعات أعلى من الإكسل

$NAMES = ['declaration_owner','company','destination','driver','car_owner','container_no'];

foreach ($pairs as $i => $p) {
    $e = $excel[$i]; $b = $sysById[$p['sid']];

    if (abs($e['freight'] - (float) $b['freight']) > 0.009) {
        $chFreight[] = ['sid' => $p['sid'], 'bill' => $e['bill_number'], 'date' => $e['bill_date'],
                        'from' => (float) $b['freight'], 'to' => $e['freight'], 'legacy' => (int) $b['is_legacy']];
    }
    /* ⚠️ تحويلة/خصم/بياتة: **الناقص من النظام بس** (قيمة في الإكسل والنظام مختلف عنها).
       ممنوع نكتب صفر الإكسل فوق قيمة موجودة في النظام — الحقول دي بتتدخل في النظام
       بعد إنشاء البوليصة (الخصم بيتحط لاحقًا)، فالكتابة بالصفر بتمسح إدخال مشروع.
       زيّهم زي النولون: بيدخلوا `Calc::bawala` ومالهمش أي أثر على الخزنة/التقفيل. */
    foreach (['transfer' => 'تحويلة', 'discount' => 'خصم', 'overnight_collection' => 'بياتة'] as $fld => $lbl) {
        if (abs($e[$fld]) > 0.005 && abs($e[$fld] - (float) $b[$fld]) > 0.009) {
            $chDebtFld[] = ['sid' => $p['sid'], 'bill' => $e['bill_number'], 'date' => $e['bill_date'],
                            'field' => $fld, 'label' => $lbl,
                            'from' => (float) $b[$fld], 'to' => $e[$fld]];
        }
    }

    /* ⚠️ رقم الحاوية: يتكتب **بس لو الإكسل أشمل** — يعني كل رقم حاوية موجود في
       النظام موجود في الإكسل كمان والإكسل فيه زيادة. التفكيك بـ`container_nos_split()`
       (المصدر الوحيد في المشروع) والمقارنة بالأرقام المجرّدة فالفواصل والأصفار
       البادئة ما تفرقش. الاختلاف الحقيقي (رقم مختلف مش زيادة) بيتقفل للمراجعة —
       حقل وصفي بلا أي أثر مالي. */
    $exParts  = array_values(array_filter(array_map('cnorm', container_nos_split($e['container_no']))));
    $sysParts = array_values(array_filter(array_map('cnorm', container_nos_split($b['container_no']))));
    if ($exParts && count($exParts) > count($sysParts) && !array_diff($sysParts, $exParts)) {
        $chContain[] = ['sid' => $p['sid'], 'bill' => $e['bill_number'],
                        'from' => (string) ($b['container_no'] ?? ''), 'to' => $e['container_no']];
    }

    /* ⚠️ ملء الخانات **الفاضية** بس — الخانة الفاضية مش خلاف تهجئة، دي بيانات
       ناقصة ومفيهاش قرار. أي خانة فيها قيمة **ما تتلمسش إطلاقًا** (قرار المالك:
       النظام أنضف من الإكسل في الأسماء — «العاشر من رمضان» مقابل «العاشر»).
       ⚠️ `container_no` مستثنى: بيتعامل معاه فرع «الإكسل أشمل» فوق. */
    foreach (BLANK_FILL as $fld => $lbl) {
        $sv = trim((string) ($b[$fld] ?? ''));
        $ev = $e[$fld] ?? null;
        if ($sv !== '' || $ev === null || $ev === '') { continue; }
        $chBlank[] = ['sid' => $p['sid'], 'bill' => $e['bill_number'],
                      'field' => $fld, 'label' => $lbl, 'to' => $ev];
    }

    $diffPay = round($e['payment'] - (float) $b['paid'], 2);
    if ($diffPay > 0.009) {
        $chPayment[] = ['sid' => $p['sid'], 'bill' => $e['bill_number'], 'date' => $e['bill_date'],
                        'amount' => $diffPay, 'excel' => $e['payment'], 'system' => (float) $b['paid']];
    } elseif ($diffPay < -0.009) {
        $payLower[] = ['sid' => $p['sid'], 'bill' => $e['bill_number'], 'excel' => $e['payment'], 'system' => (float) $b['paid']];
    }
    if (abs($e['custody'] - (float) $b['custody']) > 0.009) {
        $revCustody[] = ['sid' => $p['sid'], 'bill' => $e['bill_number'], 'date' => $e['bill_date'],
                         'excel' => $e['custody'], 'system' => (float) $b['custody'], 'legacy' => (int) $b['is_legacy']];
    }
    if (bkey($e['bill_number']) !== bkey($b['bill_number'])) {
        $chNumber[] = ['sid' => $p['sid'], 'from' => (string) $b['bill_number'], 'to' => (string) $e['bill_number'],
                       'date' => $e['bill_date'], 'car' => $e['car_no']];
    }
    foreach ($NAMES as $f) {
        $a = (string) ($e[$f] ?? ''); $s = (string) ($b[$f] ?? '');
        if ($a !== $s) {
            $revNames[] = ['bill' => $e['bill_number'], 'field' => $f, 'excel' => $a, 'system' => $s,
                           'legacy' => (int) $b['is_legacy']];
        }
    }
}

/* ─────────────────────────── لقطة الثوابت المالية ─────────────────────────── */
$snapshot = function (): array {
    return [
        'safe'     => round((float) \App\Models\Closing::currentBalance(), 2),
        'custody'  => round((float) Database::scalar('SELECT COALESCE(SUM(custody),0) FROM bawalis WHERE is_legacy=0'), 2),
        'closings' => (string) Database::scalar('SELECT COALESCE(SUM(closing_balance),0) FROM daily_closings'),
        'ndays'    => (int) Database::scalar('SELECT COUNT(*) FROM daily_closings'),
        'expenses' => round((float) Database::scalar('SELECT COALESCE(SUM(amount),0) FROM manual_expenses'), 2),
    ];
};

/* ─────────────────────────── التقرير ─────────────────────────── */
echo $line('═') . PHP_EOL;
echo "  مزامنة البوالص من الإكسل — " . ($APPLY ? $c('تنفيذ', '1;31') : $c('معاينة (بلا كتابة)', '1;36')) . PHP_EOL;
echo $line('═') . PHP_EOL;
echo "  الملف        : " . basename($CSV) . PHP_EOL;
echo "  صفوف الإكسل  : " . count($excel) . "   (تواريخ غير مفهومة: " . count($badDate) . ")" . PHP_EOL;
echo "  صفوف النظام  : " . count($sys) . PHP_EOL;
$howCount = array_count_values(array_column($pairs, 'how'));
echo "  متطابقة      : " . count($pairs) . "  " . json_encode($howCount) . PHP_EOL;
echo "  في الإكسل بس : " . count($onlyExcel) . PHP_EOL;
echo "  في النظام بس : " . count($onlySys) . PHP_EOL;
echo PHP_EOL;

$sumF = array_sum(array_map(fn($x) => $x['to'] - $x['from'], $chFreight));
$zeroF = array_filter($chFreight, fn($x) => $x['from'] == 0.0);
echo $c('① النولون (بيتنفّذ)', '1;33') . PHP_EOL;
echo "   بوالص هيتغيّر نولونها : " . count($chFreight)
   . "   (منها " . count($zeroF) . " نولونها صفر دلوقتي)" . PHP_EOL;
echo "   إجمالي الزيادة في الدين: " . number_format($sumF, 0) . " ج" . PHP_EOL;
echo PHP_EOL;
echo $c('①ب تحويلة / خصم / بياتة — الناقص من النظام (بيتنفّذ)', '1;33') . PHP_EOL;
$byFld = [];
foreach ($chDebtFld as $x) { $byFld[$x['label']][] = $x; }
if (!$byFld) { echo "   مفيش" . PHP_EOL; }
foreach ($byFld as $lbl => $v) {
    echo "   {$lbl} : " . count($v) . " بوليصة   (Δ "
       . number_format(array_sum(array_map(fn($x) => $x['to'] - $x['from'], $v)), 0) . " ج)" . PHP_EOL;
}
echo PHP_EOL;
echo $c('①ب٢ ملء الخانات النصّية الفاضية (بيتنفّذ)', '1;33') . PHP_EOL;
$byBlank = [];
foreach ($chBlank as $x) { $byBlank[$x['label']][] = $x; }
if (!$byBlank) { echo "   مفيش" . PHP_EOL; }
foreach (BLANK_FILL as $f => $lbl) {
    if (empty($byBlank[$lbl])) { continue; }
    echo "   {$lbl} : " . count($byBlank[$lbl]) . " بوليصة" . PHP_EOL;
}
echo "   (الخانة اللي فيها قيمة ما بتتلمسش — الأسماء في النظام أنضف من الإكسل)" . PHP_EOL;
echo PHP_EOL;
echo $c('①ج رقم الحاوية — الإكسل أشمل (بيتنفّذ)', '1;33') . PHP_EOL;
echo "   بوالص هيتكمّل رقم حاويتها : " . count($chContain) . PHP_EOL;
foreach (array_slice($chContain, 0, 5) as $x) {
    echo "     • بوليصة {$x['bill']}: «{$x['from']}» ← «{$x['to']}»" . PHP_EOL;
}
if (count($chContain) > 5) { echo '     • … و' . (count($chContain) - 5) . ' غيرها' . PHP_EOL; }
echo PHP_EOL;
echo $c('② الدفعات (بيتنفّذ)', '1;33') . PHP_EOL;
echo "   دفعات هتتسجّل : " . count($chPayment)
   . "   بإجمالي " . number_format(array_sum(array_column($chPayment, 'amount')), 0) . " ج" . PHP_EOL;
echo "   (تاريخ كل دفعة = تاريخ البوليصة، وموسومة is_legacy=1 فمعزولة عن الخزنة والمؤشرات)" . PHP_EOL;
echo PHP_EOL;
echo $c('③ بوالص ناقصة', '1;33') . "   (آخر يوم أرشيفي: {$legacyCutoff})" . PHP_EOL;
echo "   بتتضاف كأرشيف is_legacy=1 : " . count($addable) . PHP_EOL;
foreach ($addable as $i) {
    $e = $excel[$i];
    echo "     • بوليصة {$e['bill_number']} | {$e['bill_date']} | {$e['company']} | {$e['car_no']}"
       . " | نولون " . number_format($e['freight'], 0) . " | عهدة " . number_format($e['custody'], 0) . PHP_EOL;
}
if ($deferred) {
    echo '   ' . $c('⚠️ مؤجَّلة للإدخال اليدوي', '1;31') . ' : ' . count($deferred)
       . "  (تاريخها بعد آخر يوم أرشيفي — عهدتها لازم تخصم من الخزنة وتترّبط بإذن،" . PHP_EOL;
    echo "      فإضافتها أرشيفية هتخبّي الكاش. أدخلها من جوّه الإذن في النظام.)" . PHP_EOL;
    $show = array_slice($deferred, 0, 8);
    foreach ($show as $i) {
        $e = $excel[$i];
        echo "       · بوليصة {$e['bill_number']} | {$e['bill_date']} | {$e['company']} | {$e['car_no']}"
           . " | عهدة " . number_format($e['custody'], 0) . PHP_EOL;
    }
    if (count($deferred) > count($show)) { echo '       · … و' . (count($deferred) - count($show)) . ' غيرها في كشف المراجعة' . PHP_EOL; }
}
echo PHP_EOL;
echo $c('④ أرقام بوالص مختلفة', '1;35') . " — " . ($FIX_NUM ? 'هتتصلّح' : 'للمراجعة بس (استخدم --fix-numbers)') . PHP_EOL;
foreach ($chNumber as $x) {
    $clash = Database::scalar('SELECT COUNT(*) FROM bawalis WHERE bill_number = ? AND id <> ?', [$x['to'], $x['sid']]);
    $flag = $clash > 0 ? $c('  ⚠️ الرقم ده موجود بالفعل لبوليصة تانية', '1;31') : '';
    echo "     • id {$x['sid']}: «{$x['from']}» ← «{$x['to']}» | {$x['date']} | {$x['car']}{$flag}" . PHP_EOL;
}
echo PHP_EOL;
echo $c('⑤ برّه النطاق — للمراجعة بس (قرار المالك)', '1;36') . PHP_EOL;
echo "   فروق العهدة        : " . count($revCustody) . "  (بتمسّ الخزنة والأيام متقفلة)" . PHP_EOL;
echo "   فروق الأسماء       : " . count($revNames) . PHP_EOL;
echo "   النظام دفعاته أعلى : " . count($payLower) . PHP_EOL;

/* كشف المراجعة CSV */
if ($REPORT !== '') {
    $rh = fopen($REPORT, 'w');
    fwrite($rh, "\xEF\xBB\xBF");
    fputcsv($rh, ['النوع','رقم البوليصة','التاريخ','الحقل','قيمة الإكسل','قيمة النظام','ملاحظة']);
    foreach ($revCustody as $x) {
        $d = $x['excel'] - $x['system'];
        fputcsv($rh, ['عهدة', $x['bill'], $x['date'], 'custody', $x['excel'], $x['system'],
                      abs(abs($d) - 100) < 0.01 ? 'فرق ±100 (قاعدة المية)' : 'فرق حقيقي']);
    }
    foreach ($revNames as $x) {
        fputcsv($rh, ['اسم', $x['bill'], '', $x['field'], $x['excel'], $x['system'],
                      $x['legacy'] ? 'أرشيفية' : 'حية']);
    }
    foreach ($payLower as $x) {
        fputcsv($rh, ['دفعة أعلى في النظام', $x['bill'], '', 'paid', $x['excel'], $x['system'], 'مش بيتغيّر']);
    }
    foreach ($chNumber as $x) {
        fputcsv($rh, ['رقم بوليصة', $x['to'], $x['date'], 'bill_number', $x['to'], $x['from'], 'id ' . $x['sid']]);
    }
    foreach ($onlySys as $sid) {
        $b = $sysById[$sid];
        fputcsv($rh, ['في النظام مش في الإكسل', $b['bill_number'], substr((string) $b['bill_date'], 0, 10),
                      '', '', '', ((int) $b['is_legacy'] ? 'أرشيفية' : 'حية') . ' — id ' . $sid]);
    }
    foreach ($deferred as $i) {
        $e = $excel[$i];
        fputcsv($rh, ['ناقصة — إدخال يدوي', $e['bill_number'], $e['bill_date'], 'العهدة', $e['custody'], '',
                      'تاريخها بعد الأرشيف — أدخلها من جوّه الإذن عشان العهدة تخصم من الخزنة']);
    }
    fclose($rh);
    echo PHP_EOL . "   → كشف المراجعة: {$REPORT}" . PHP_EOL;
}

if (!$APPLY) {
    echo PHP_EOL . $line() . PHP_EOL;
    echo "  معاينة بس — مفيش أي حاجة اتكتبت. للتنفيذ ضيف --apply" . PHP_EOL;
    exit(0);
}

/* ─────────────────────────── التنفيذ ─────────────────────────── */
$before = $snapshot();
echo PHP_EOL . $line() . PHP_EOL;
echo "  رصيد الخزنة قبل: " . number_format($before['safe'], 2) . " ج" . PHP_EOL;

$pdo = Database::conn();
$pdo->beginTransaction();
try {
    $nF = $nP = $nB = $nN = 0;

    // ① النولون → إعادة حساب الدين بـCalc::bawala ثم Bill::recalc
    foreach ($chFreight as $x) {
        $row = Database::row('SELECT * FROM bawalis WHERE id = ?', [$x['sid']]);
        if (!$row) { continue; }
        $next = array_merge($row, ['freight' => $x['to']]);
        $debt = Calc::bawala($next)['debt'];
        Database::exec('UPDATE bawalis SET freight = ?, debt = ? WHERE id = ?', [$x['to'], $debt, $x['sid']]);
        Bill::recalc((int) $x['sid']);
        $nF++;
    }

    // ①ب تحويلة/خصم/بياتة → نفس مسار النولون (Calc::bawala ثم Bill::recalc)
    $nD = 0;
    foreach ($chDebtFld as $x) {
        $row = Database::row('SELECT * FROM bawalis WHERE id = ?', [$x['sid']]);
        if (!$row) { continue; }
        $next = array_merge($row, [$x['field'] => $x['to']]);
        $debt = Calc::bawala($next)['debt'];
        Database::exec(
            "UPDATE bawalis SET `{$x['field']}` = ?, debt = ? WHERE id = ?",
            [$x['to'], $debt, $x['sid']]
        );
        Bill::recalc((int) $x['sid']);
        $nD++;
    }

    /* ①ب٢ ملء الخانات الفاضية.
       ⚠️ صاحب البيان والشركة بيمرّوا على **`PartyMaster::ensure()`** زي مسار
          الحفظ العادي بالظبط (`BawalisController::prepareSaveData`) — بترجّع
          الاسم القانوني **والـid**. ملء النص وحده كان هيسيب `declaration_owner_id`
          فاضي، و`OwnerStatement` بيجمّع أصحاب البيان بـ`declaration_owner_id
          IS NOT NULL` فالبوليصة ما كانتش هتظهر في كشوف الحساب.
       ⚠️ باقي الأسماء بتمرّ على `NameSuggestion::resolve` عشان ما نزرعش تهجئة
          جديدة جنب واحدة موجودة (تحذير ٣). */
    $nBlank = 0;
    foreach ($chBlank as $x) {
        $fld = $x['field'];
        $val = (string) $x['to'];
        if ($fld === 'declaration_owner' || $fld === 'company') {
            $type = $fld === 'company' ? 'company' : 'owner';
            $party = \App\Models\PartyMaster::ensure($type, $val);
            if (!$party) { continue; }
            $idCol = $fld === 'company' ? 'company_id' : 'declaration_owner_id';
            Database::exec(
                "UPDATE bawalis SET `{$fld}` = ?, `{$idCol}` = ? WHERE id = ?",
                [$party['name'], $party['id'], $x['sid']]
            );
        } else {
            /* ⚠️ `car_no` مالوش نوع في `NameSuggestion::SOURCES` (مش محروس
               بحارس الأسماء) — بيتحط زي ما هو. الباقي بيمرّ على الحارس. */
            $use = $val;
            if ($fld !== 'car_no') {
                $snap = \App\Models\NameSuggestion::resolve($fld, $val);
                if (($snap['value'] ?? '') !== '') { $use = $snap['value']; }
            }
            Database::exec("UPDATE bawalis SET `{$fld}` = ? WHERE id = ?", [$use, $x['sid']]);
        }
        $nBlank++;
    }

    // ①ج رقم الحاوية (وصفي بحت — بلا أي إعادة حساب)
    $nC = 0;
    foreach ($chContain as $x) {
        Database::exec('UPDATE bawalis SET container_no = ? WHERE id = ?', [$x['to'], $x['sid']]);
        $nC++;
    }

    // ② الدفعات التاريخية (is_legacy=1) بتاريخ البوليصة
    foreach ($chPayment as $x) {
        $d = $x['date'] ?: Database::scalar('SELECT bill_date FROM bawalis WHERE id = ?', [$x['sid']]);
        Database::exec(
            'INSERT INTO bill_payments (bill_id, pay_date, amount, note, is_legacy) VALUES (?,?,?,?,1)',
            [$x['sid'], $d, $x['amount'], 'دفعة من ملف الإكسل — مزامنة ' . date('Y-m-d')]
        );
        Bill::recalc((int) $x['sid']);
        $nP++;
    }

    // ③ البوالص الناقصة (اللي قبل حد الأرشيف بس) → أرشيفية معزولة عن الخزنة
    foreach ($addable as $i) {
        $e = $excel[$i];
        $debt = Calc::bawala($e)['debt'];
        Database::exec(
            'INSERT INTO bawalis (bill_date, bill_number, declaration_owner, classification, company,
                                  destination, container_no, car_no, driver, car_owner, freight, custody,
                                  transfer, discount, overnight_collection, payment, debt, paid, remaining,
                                  pay_status, is_legacy, legacy_id, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,?,0,?,?,1,NULL,NOW())',
            [$e['bill_date'], $e['bill_number'], $e['declaration_owner'], $e['classification'], $e['company'],
             $e['destination'], $e['container_no'], $e['car_no'], $e['driver'], $e['car_owner'],
             $e['freight'], $e['custody'], $e['transfer'], $e['discount'], $e['overnight_collection'],
             $debt, $debt, $debt > 0 ? 'unpaid' : 'pending']
        );
        $newId = Database::lastId();
        if ($e['payment'] > 0.009) {
            Database::exec(
                'INSERT INTO bill_payments (bill_id, pay_date, amount, note, is_legacy) VALUES (?,?,?,?,1)',
                [$newId, $e['bill_date'], $e['payment'], 'دفعة من ملف الإكسل — مزامنة ' . date('Y-m-d')]
            );
        }
        Bill::recalc($newId);
        $nB++;
    }

    // ④ أرقام البوالص (اختياري صراحةً)
    if ($FIX_NUM) {
        foreach ($chNumber as $x) {
            $clash = (int) Database::scalar('SELECT COUNT(*) FROM bawalis WHERE bill_number = ? AND id <> ?',
                                            [$x['to'], $x['sid']]);
            if ($clash > 0) {
                echo "     ⚠️ اتخطّى id {$x['sid']}: الرقم «{$x['to']}» موجود لبوليصة تانية" . PHP_EOL;
                continue;
            }
            Database::exec('UPDATE bawalis SET bill_number = ? WHERE id = ?', [$x['to'], $x['sid']]);
            $nN++;
        }
    }

    // ─── التحقّق من الثوابت المالية ───
    $after = $snapshot();
    $bad = [];
    foreach (['safe','custody','closings','ndays','expenses'] as $k) {
        if ((string) $before[$k] !== (string) $after[$k]) {
            $bad[] = "{$k}: {$before[$k]} ← {$after[$k]}";
        }
    }
    if ($bad) {
        throw new RuntimeException("الثوابت المالية اتغيّرت — تراجع كامل:\n   " . implode("\n   ", $bad));
    }

    $pdo->commit();
    echo PHP_EOL . $c('  ✓ اتنفّذ', '1;32') . PHP_EOL;
    echo "     نولون اتحدّث   : {$nF}" . PHP_EOL;
    echo "     تحويلة/خصم/بياتة: {$nD}" . PHP_EOL;
    echo "     خانات فاضية اتملت: {$nBlank}" . PHP_EOL;
    echo "     رقم حاوية اتكمّل: {$nC}" . PHP_EOL;
    echo "     دفعات اتسجّلت  : {$nP}" . PHP_EOL;
    echo "     بوالص اتضافت   : {$nB}" . PHP_EOL;
    if ($FIX_NUM) { echo "     أرقام اتصلّحت  : {$nN}" . PHP_EOL; }
    echo PHP_EOL . "  رصيد الخزنة بعد: " . number_format($after['safe'], 2) . " ج  "
       . $c('(ما اتغيّرش ✓)', '1;32') . PHP_EOL;
    echo "  أيام متقفلة: {$after['ndays']} — مجموع أرصدتها ثابت ✓" . PHP_EOL;
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    fwrite(STDERR, PHP_EOL . "✗ فشل — اترجع كل شيء:" . PHP_EOL . '   ' . $ex->getMessage() . PHP_EOL);
    exit(1);
}
