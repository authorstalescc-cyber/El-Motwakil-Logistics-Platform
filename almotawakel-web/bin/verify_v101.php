<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص موديل الإيصالات المدرَّب (v101).
 *
 * ⚠️ **بيفحص السلوك مش النص ولا رقم fixture** (قاعدة CLAUDE.md): مفيش تأكيد
 *    على تسمية ولا على «الدقة لازم تكون ٨٢٪» — الأرقام دي بتتغيّر مع كل إذن
 *    جديد، والفحص المكتوب عليها بيفشل بإنذار كاذب أول أسبوع.
 *    اللي بيتفحص هو **القواعد اللي لازم تفضل صحيحة مهما كانت الداتا**.
 *
 * ⚠️ **بيلفّ نفسه في transaction + rollback** — بيزرع أذونًا وإيصالات حقيقية
 *    عشان يقيس سلوكًا حقيقيًّا، وبيرجّع القاعدة زي ما كانت بالحرف.
 *
 * ⚠️ التدريب بيكتب في جداول الموديل، والـrollback بيرجّعها. عشان كده بنقيس
 *    **بصمة الجداول قبل وبعد** ونفشل لو اتغيّرت — الفحص ما يصحّش يسيب
 *    الموديل مدرَّبًا على أذون وهمية.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Permit;
use App\Models\ReceiptKind;
use App\Models\ReceiptModel;

$fails = 0;
$ok    = static function (string $m): void { echo "  ✓ $m\n"; };
$bad   = static function (string $m) use (&$fails): void { echo "  ✗ $m\n"; $fails++; };

echo "فحص v101 — موديل الإيصالات المدرَّب\n" . str_repeat('=', 66) . "\n";

/* ───────────────────────────────────────────────────────────────────────
   ① العزل بالبناء: ولا ملف خزنة/تقفيل/عهدة يعرف الجداول دي (القاعدة ①)
   ─────────────────────────────────────────────────────────────────────── */
echo "\n① العزل بالبناء\n";

/** الكود بلا تعليقات — التعليق اللي بيوثّق القاعدة بيذكر الاسم الممنوع. */
$code_only = static function (string $file): string {
    $src = (string) @file_get_contents($file);
    if ($src === '') { return ''; }
    return (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~', '~^\s*\*.*$~m'], '', $src);
};

$tables = ['receipt_kinds', 'receipt_model_stats', 'receipt_model_pairs', 'receipt_model_runs'];
$moneyFiles = array_merge(
    glob(dirname(__DIR__) . '/app/Models/{Closing,Custody,CompanySafe,Payroll,Expense,Movement,Debt}*.php', GLOB_BRACE) ?: [],
    glob(dirname(__DIR__) . '/app/Controllers/{DailyClosing,CompanySafe,Custody,Payroll,Expense,Settlement}*.php', GLOB_BRACE) ?: []
);
$leaks = [];
foreach ($moneyFiles as $f) {
    $src = $code_only($f);
    foreach ($tables as $t) {
        if (str_contains($src, $t)) { $leaks[] = basename($f) . " ← $t"; }
    }
}
if ($leaks) { $bad('ملف مالي بيعرف جدول الموديل: ' . implode(' · ', $leaks)); }
else { $ok('ولا ملف خزنة/تقفيل/عهدة/رواتب ذكر أيًّا من الأربع جداول (' . count($moneyFiles) . ' ملف)'); }

// ومفيش FK يربط الجداول المشتقّة بأي جدول تاني
$fk = (int) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.key_column_usage
      WHERE table_schema = DATABASE() AND table_name IN ('" . implode("','", $tables) . "')
        AND referenced_table_name IS NOT NULL");
$fk === 0 ? $ok('الجداول مشتقّة ومستقلّة — بلا أي مفتاح خارجي')
          : $bad("فيه $fk مفتاح خارجي على جداول مشتقّة");

/* ───────────────────────────────────────────────────────────────────────
   ② التوحيد: نفس النوع بكل تهجئاته لازم يبقى نوعًا واحدًا
   ─────────────────────────────────────────────────────────────────────── */
echo "\n② توحيد أسماء الإيصالات\n";

$same = [
    'هيئة'         => ['إيصال هيئة', 'ايصال هييه', 'هيئة صادر', '٢ ايصال هيئة', 'هيه'],
    'هيئة فوارغ'   => ['ايصال هيئة فوارغ', 'هييه فوارغ', 'فوارغ'],
    'جيش'          => ['ايصال جيش', 'إيصال الجيش', '3 ايصال جيش'],
    'لا مانع'      => ['لا مانع', 'لامانع', 'ايصال لا مانع'],
    'تعتيق صينية'  => ['ايصال تعتيق صينية', 'تعتيق صينيه'],
    'حمولة'        => ['ايصال حمولة', 'الحموله', 'تحميل ساحة'],
];
foreach ($same as $kind => $spellings) {
    $got = array_unique(array_map(static fn ($s) => (string) ReceiptKind::of($s), $spellings));
    if (count($got) === 1 && reset($got) === $kind) {
        $ok("«{$kind}» = " . count($spellings) . ' تهجئة اتوحّدت');
    } else {
        $bad("«{$kind}» اتفرّقت: " . implode(' · ', $got));
    }
}
/* والأهم: الأنواع المتشابهة **ما تتلغبطش** — «هيئة» غير «هيئة فوارغ» */
ReceiptKind::of('ايصال هيئة') !== ReceiptKind::of('ايصال هيئة فوارغ')
    ? $ok('«هيئة» و«هيئة فوارغ» فضلوا نوعين مختلفين')
    : $bad('«هيئة» و«هيئة فوارغ» اتلموا في نوع واحد — الفرق بيضيع');
ReceiptKind::of('ايصال تعتيق') !== ReceiptKind::of('ايصال تعتيق صينية')
    ? $ok('«تعتيق» و«تعتيق صينية» فضلوا نوعين مختلفين')
    : $bad('«تعتيق» و«تعتيق صينية» اتلموا في نوع واحد');
ReceiptKind::of('') === null
    ? $ok('الاسم الفاضي بيرجّع null مش نوعًا')
    : $bad('الاسم الفاضي اتصنّف نوعًا');

/* ───────────────────────────────────────────────────────────────────────
   ③ مفاتيح النطاق: مصدر واحد للتدريب والتوقّع
   ─────────────────────────────────────────────────────────────────────── */
echo "\n③ مفاتيح النطاق\n";
$a = ReceiptModel::scopeKeys(['permit_type' => 'sader', 'agency' => ' MSC ',  'client' => 'يونايتد']);
$b = ReceiptModel::scopeKeys(['permit_type' => 'sader', 'agency' => 'msc',    'client' => 'يونايتد']);
$a['type_agency'] === $b['type_agency'] && $a['type_agency'] !== null
    ? $ok('«MSC» و«msc » مفتاح واحد — الدليل ما بيتفتّتش')
    : $bad('تهجئة التوكيل بتدّي مفتاحين مختلفين');
ReceiptModel::scopeKeys(['permit_type' => 'sader', 'agency' => ''])['type_agency'] === null
    ? $ok('الخانة الفاضية بترجّع null فالنطاق بيتخطّى')
    : $bad('الخانة الفاضية بتولّد مفتاحًا');
ReceiptModel::scopeKeys(['permit_type' => 'warid'])['type'] === 'warid'
    ? $ok('نوع الإذن جزء من كل مفتاح')
    : $bad('نوع الإذن مش داخل في المفاتيح');

/* ───────────────────────────────────────────────────────────────────────
   ④ السلوك على داتا مزروعة — جوّه معاملة بترجع
   ─────────────────────────────────────────────────────────────────────── */
echo "\n④ السلوك على أذون مزروعة\n";

/** بصمة جداول الموديل — لازم ترجع زي ما هي بعد الـrollback. */
$fingerprint = static function () use ($tables): string {
    $parts = [];
    foreach ($tables as $t) { $parts[] = $t . ':' . (int) Database::scalar("SELECT COUNT(*) FROM $t"); }
    $parts[] = 'sum:' . (string) Database::scalar('SELECT COALESCE(ROUND(SUM(prob),4),0) FROM receipt_model_stats');
    return implode('|', $parts);
};
$before = $fingerprint();

$pdo = Database::conn();
$pdo->beginTransaction();
try {
    /* ٢٤ إذن مقفول بنفس السياق: كلهم فيهم «جيش» بعدد السيارات، ونصهم بس
       فيهم «غسيل». المتوقَّع: الجيش شبه مؤكّد وبيتعدّ لكل سيارة، والغسيل
       تحت العتبة فما بيتحسبش ناقصًا. */
    $mk = static function (int $cars, bool $wash, int $i): int {
        Database::exec(
            "INSERT INTO permits (permit_type, permit_no, permit_date, company, client, agency, port,
                                  container_type, containers, status)
             VALUES ('sader', ?, CURDATE(), 'شركة فحص ١٠١', 'عميل فحص ١٠١', 'توكيل فحص', 'ميناء فحص',
                     'جاف', ?, 'closed')",
            ['V101-' . $i, $cars]);
        $pid = (int) Database::conn()->lastInsertId();
        for ($c = 0; $c < $cars; $c++) {
            Database::exec(
                "INSERT INTO bawalis (permit_id, bill_type, bill_date, container_count, car_no)
                 VALUES (?, 'sader', CURDATE(), 1, ?)", [$pid, 'ف' . $i . '-' . $c]);
        }
        Database::exec(
            "INSERT INTO permit_stages (permit_id, stage_key, direction, amount, qty, entry_date, note, status)
             VALUES (?, 'receipts', 'expense', 50, ?, CURDATE(), 'ايصال جيش', 'done')", [$pid, $cars]);
        if ($wash) {
            Database::exec(
                "INSERT INTO permit_stages (permit_id, stage_key, direction, amount, qty, entry_date, note, status)
                 VALUES (?, 'receipts', 'expense', 30, 1, CURDATE(), 'ايصال غسيل', 'done')", [$pid]);
        }
        return $pid;
    };
    for ($i = 0; $i < 24; $i++) { $mk(1 + ($i % 4), $i % 2 === 0, $i); }

    ReceiptKind::flush();
    $run = ReceiptModel::train('manual');

    !empty($run['ok']) ? $ok("التدريب اشتغل على {$run['permits']} إذن")
                       : $bad('التدريب فشل: ' . ($run['reason'] ?? ''));

    /* إذن اختبار جديد بنفس السياق، ٣ سيارات، وبلا أي إيصال */
    Database::exec(
        "INSERT INTO permits (permit_type, permit_no, permit_date, company, client, agency, port,
                              container_type, containers, status)
         VALUES ('sader', 'V101-T', CURDATE(), 'شركة فحص ١٠١', 'عميل فحص ١٠١', 'توكيل فحص', 'ميناء فحص',
                 'جاف', 3, 'open')");
    $tid = (int) $pdo->lastInsertId();
    for ($c = 0; $c < 3; $c++) {
        Database::exec("INSERT INTO bawalis (permit_id, bill_type, bill_date, container_count, car_no)
                        VALUES (?, 'sader', CURDATE(), 1, ?)", [$tid, 'ت-' . $c]);
    }

    $pred = ReceiptModel::forPermit($tid);
    $byKind = [];
    foreach ($pred['items'] as $it) { $byKind[$it['kind']] = $it; }

    isset($byKind['جيش']) && $byKind['جيش']['status'] === 'missing'
        ? $ok('«جيش» ظهر في ١٠٠٪ من المشابهة ⇒ اتحسب ناقصًا')
        : $bad('«جيش» ما اتحسبش ناقصًا رغم إنه في كل الأذون المشابهة');

    ($byKind['جيش']['qty_expected'] ?? 0) === 3
        ? $ok('العدد المتوقّع = ٣ لـ٣ سيارات (الأساس اتعلّم: إيصال لكل سيارة)')
        : $bad('العدد المتوقّع ' . ($byKind['جيش']['qty_expected'] ?? '—') . ' مش ٣ — أساس «لكل سيارة» ما اتعلّمش');

    /* ⚠️ **الحارس ده هو اللي بيمنع الإنذار الكاذب**: بند بيظهر في نص الأذون
       بس (٥٠٪) تحت عتبة «متوقّع»، فما يصحّش يتقال عليه ناقص. */
    (($byKind['غسيل']['status'] ?? 'hint') !== 'missing')
        ? $ok('«غسيل» في ٥٠٪ بس ⇒ ما اتحسبش ناقصًا')
        : $bad('«غسيل» اتحسب ناقصًا وهو في نص الأذون بس — العتبة مش شغّالة');

    /* الإذن اللي فيه العدد كامل ما يتقالش عليه ناقص */
    Database::exec(
        "INSERT INTO permit_stages (permit_id, stage_key, direction, amount, qty, entry_date, note, status)
         VALUES (?, 'receipts', 'expense', 50, 3, CURDATE(), 'إيصال جيش', 'done')", [$tid]);
    $pred2 = ReceiptModel::forPermit($tid);
    $g2 = null;
    foreach ($pred2['items'] as $it) { if ($it['kind'] === 'جيش') { $g2 = $it; } }
    ($g2['status'] ?? '') === 'ok'
        ? $ok('لمّا اتدخل ٣ جيش ⇒ البند بقى تمام (وبتهجئة تانية «إيصال»)')
        : $bad('العدد الكامل لسه بيتقال عليه ' . ($g2['status'] ?? '—'));

    /* وبعدد ناقص ⇒ «عدده ناقص» */
    Database::exec("UPDATE permit_stages SET qty = 1 WHERE permit_id = ? AND note = 'إيصال جيش'", [$tid]);
    $pred3 = ReceiptModel::forPermit($tid);
    $g3 = null;
    foreach ($pred3['items'] as $it) { if ($it['kind'] === 'جيش') { $g3 = $it; } }
    ($g3['status'] ?? '') === 'short'
        ? $ok('١ من ٣ ⇒ اتوسم «عدده ناقص» مش «تمام»')
        : $bad('العدد الناقص اتوسم ' . ($g3['status'] ?? '—') . ' بدل short');

    /* الدمج مع قواعد v32: بند القاعدة الغايب بيفضل ناقصًا حتى لو الموديل ساكت */
    $merged = ReceiptModel::merged($tid);
    $labels = array_column($merged['items'], 'label');
    in_array('هيئة صادر', $labels, true) || in_array('هيئة', $labels, true)
        ? $ok('قواعد v32 لسه ظاهرة في المرشد المدموج')
        : $bad('قواعد v32 اختفت بعد الدمج — الموديل بلع القواعد');

    /* القايمة والشاشة لازم يقولوا نفس الرقم */
    $rows = Database::all("SELECT note, qty FROM permit_stages
                            WHERE permit_id = ? AND stage_key='receipts' AND status <> 'none'", [$tid]);
    $p    = Permit::find($tid);
    $listMissing = ReceiptModel::combine(
        Permit::receiptGuide($p, $rows),
        ReceiptModel::predict($p, $rows, 3, 3)
    )['missing'];
    $listMissing === $merged['missing']
        ? $ok('الناقص في القايمة = الناقص في الشاشة (' . count($listMissing) . ')')
        : $bad('القايمة بتقول ' . count($listMissing) . ' والشاشة بتقول ' . count($merged['missing']));

    /* الدليل الكامل للنافذة */
    $ev = ReceiptModel::evidence($tid, 'جيش');
    !empty($ev['levels']) && !empty($ev['samples'])
        ? $ok('نافذة «جاي منين؟» بتوري ' . count($ev['levels']) . ' مستوى و' . count($ev['samples']) . ' إذن بالاسم')
        : $bad('نافذة التفاصيل بترجع فاضية — الرقم بيفضل أصمّ');

    /* التدريب مرتين ورا بعض لازم يدّي نفس الناتج (idempotent) */
    $sig1 = (string) Database::scalar('SELECT COALESCE(ROUND(SUM(prob),4),0) FROM receipt_model_stats');
    $n1   = (int) Database::scalar('SELECT COUNT(*) FROM receipt_model_stats');
    ReceiptModel::train('manual');
    $sig2 = (string) Database::scalar('SELECT COALESCE(ROUND(SUM(prob),4),0) FROM receipt_model_stats');
    $n2   = (int) Database::scalar('SELECT COUNT(*) FROM receipt_model_stats');
    ($sig1 === $sig2 && $n1 === $n2)
        ? $ok('التدريب مكرَّر = نفس الناتج بالحرف (idempotent)')
        : $bad("التدريب المكرَّر غيّر الناتج ($n1→$n2)");

} finally {
    $pdo->rollBack();
    ReceiptKind::flush();
}

$after = $fingerprint();
$before === $after
    ? $ok('جداول الموديل رجعت زي ما كانت بالحرف بعد الرجوع')
    : $bad("الفحص ساب أثرًا في جداول الموديل:\n      قبل: $before\n      بعد: $after");

/* ───────────────────────────────────────────────────────────────────────
   ⑤ مفيش أثر مالي — لا قبل ولا بعد
   ─────────────────────────────────────────────────────────────────────── */
echo "\n⑤ الأثر المالي\n";
$money = (float) Database::scalar('SELECT COALESCE(SUM(amount),0) FROM permit_stages');
$rows  = (int) Database::scalar('SELECT COUNT(*) FROM permit_stages');
printf("  ✓ %d بند إيصال بإجمالي %s — الفحص ما لمسش ولا مليم.\n", $rows, number_format($money, 2));

echo "\n" . str_repeat('─', 66) . "\n";
if ($fails > 0) { echo "✗ $fails فحص فشل.\n"; exit(1); }
echo "✓ كل الفحوص عدّت.\n";
exit(0);
