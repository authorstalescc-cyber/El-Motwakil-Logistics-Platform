<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص بند «غير مدفوع» (v57) — إيصال/مصروف على الإذن بلا خصم من أي محفظة.
 *
 * **طلب المالك (2026-07-27):** المندوب يسجّل إيصال ويدخل قيمته عادي، والمالك
 * يعتمده زي أي بند — الفرق إنه **ما يتخصمش من عهدة المندوب**. و**قرار المالك
 * المحسوم**: ما يتخصمش من **خزنة المكتب** كمان.
 *
 * القاعدة المحروسة (وهي أخطر حاجة في الوحدة):
 *   ⚠️ بند بمبلغ ضخم موسوم «غير مدفوع» **لازم ما يحرّكش ولا مليم** في:
 *      • رصيد خزنة المكتب (`Closing::currentBalance`)
 *      • التقفيل اليومي (`Closing::dayView`)
 *      • رصيد عهدة المندوب (`RepCustody::balance`) — التراكمي واليومي
 *   وفي نفس الوقت **لازم يفضل ظاهر** في تايم لاين الإذن وسجل المصروفات
 *   وشاشة مراجعة التقفيل (في قسم معلوماتي مش مانع).
 *
 * ⚠️ والمصدر الوحيد للشرط `Permit::cashOutSql()` — الفحص بيفشل لو أي كود خزنة
 *    أو عهدة كتب `is_visa = 0` بإيده، لأن ساعتها الاستثناء ما بيسريش هناك
 *    والرصيد يقول رقمًا والشاشة تقول رقمًا تاني **بلا أي خطأ ظاهر**.
 *
 * كله جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_unpaid_stages.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;
use App\Models\DailyReview;
use App\Models\Permit;
use App\Models\RepCustody;

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function sec(string $m): void { echo "\n" . $m . "\n" . str_repeat('─', 62) . "\n"; }
function near(string $l, $a, $e): void {
    abs((float) $a - (float) $e) <= 0.005 ? ok($l)
        : bad("{$l} — متوقع " . number_format((float) $e, 2) . " / فعلي " . number_format((float) $a, 2));
}
/** كود بلا تعليقات — عشان تعليق بيشرح النص القديم ما يديش فشلًا كاذبًا. */
function code_only(string $file): string {
    $p = dirname(__DIR__) . '/' . $file;
    if (!is_file($p)) { return ''; }
    $out = '';
    foreach (token_get_all((string) file_get_contents($p)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}
function has(string $l, string $file, string $needle): void {
    $p = dirname(__DIR__) . '/' . $file;
    $src = is_file($p) ? (string) file_get_contents($p) : '';
    str_contains($src, $needle) ? ok($l) : bad("{$l} — «{$needle}» مش موجودة في {$file}");
}

echo "فحص بند «غير مدفوع» (v57)\n" . str_repeat('=', 62) . "\n";

if (!Permit::unpaidReady()) {
    echo "\n⚠️ عمود permit_stages.unpaid مش موجود — شغّل php bin/migrate_v57.php الأول.\n";
    exit(1);
}

$treasuryBefore = Closing::currentBalance();
$repsBefore     = RepCustody::available() ? RepCustody::totalOutstanding() : 0.0;

// ══════════════════════════════════════════════════ ١) المصدر الوحيد للشرط
sec('١) المصدر الوحيد للشرط');

str_contains(Permit::cashOutSql('s'), 'unpaid')
    ? ok('Permit::cashOutSql بتستثني «غير مدفوع»') : bad('cashOutSql مش بتستثني العمود');
str_contains(Permit::cashOutSql('s'), 's.is_visa')
    ? ok('وبتستثني الفيزا زي ما كانت') : bad('cashOutSql وقّعت استثناء الفيزا!');
!str_contains(Permit::cashOutSql(''), '.')
    ? ok('وبتشتغل بلا بادئة جدول كمان (استعلامات RepCustody)') : bad('صيغة بلا بادئة مكسورة');

// ⚠️ أي ملف بيحسب كاش على permit_stages لازم ينادي cashOutSql — مش يكتب الشرط بإيده.
$cashFiles = [
    'app/Models/Closing.php', 'app/Models/RepCustody.php',
    'app/Controllers/DailyClosingController.php', 'bin/backfill_closings.php',
];
foreach ($cashFiles as $f) {
    $code = code_only($f);
    $handWritten = preg_match('~is_visa\s*=\s*0~', $code) && !str_contains($code, 'cashOutSql');
    !$handWritten ? ok("{$f} بيمرّ على cashOutSql (مش كاتب الشرط بإيده)")
                  : bad("{$f} لسه بيكتب `is_visa = 0` بإيده — الاستثناء مش هيسري فيه");
}
foreach (['app/Models/Closing.php', 'app/Models/RepCustody.php',
          'app/Controllers/DailyClosingController.php', 'bin/backfill_closings.php'] as $f) {
    has('و' . basename($f) . ' بينادي cashOutSql فعلاً', $f, 'cashOutSql');
}

$pdo = Database::conn();
$pdo->beginTransaction();

try {
    // ══════════════════════════════════════════════ ٢) بيئة الاختبار
    sec('٢) بند بمبلغ ضخم موسوم «غير مدفوع»');

    $day = '2037-08-11';   // بعيد عن أي إقفال أو بيانات حقيقية
    $big = 999999.00;

    Database::exec("INSERT INTO reps (name, status) VALUES ('__up_rep', 'active')");
    $repId = Database::lastId();
    Database::exec(
        "INSERT INTO permits (permit_no, permit_date, status, company, client)
         VALUES ('__up_permit', ?, 'open', '__up_co', '__up_cl')", [$day]);
    $permitId = Database::lastId();

    RepCustody::createDeposit($repId, 5000, $day, 'عهدة اختبار');
    $balBefore  = Closing::currentBalance();
    $repBefore  = RepCustody::balance($repId);
    $dayBefore  = RepCustody::balance($repId, $day);
    near('عهدة المندوب قبل البند = 5,000', $dayBefore, 5000);

    Database::exec(
        "INSERT INTO permit_stages
            (permit_id, stage_key, direction, amount, qty, entry_date, note,
             status, is_visa, unpaid, paid_by_rep_id)
         VALUES (?, 'receipts', NULL, ?, 1, ?, '__up_unpaid', 'done', 0, 1, ?)",
        [$permitId, $big, $day, $repId]);
    $unpaidId = Database::lastId();

    // ══════════════════════════════════════════════ ٣) قلب القاعدة
    sec('٣) ★ العزل: البند ما بيحركش أي محفظة');

    near('خزنة المكتب زي ما هي بالمليم', Closing::currentBalance(), $balBefore);
    near('عهدة المندوب التراكمية زي ما هي', RepCustody::balance($repId), $repBefore);
    near('وعهدة يومه زي ما هي', RepCustody::balance($repId, $day), $dayBefore);

    $view = Closing::dayView($day);
    !str_contains(json_encode($view), '999999')
        ? ok('ومش داخل مصروفات التقفيل اليومي') : bad('البند دخل التقفيل اليومي!');

    $totals = RepCustody::dayTotalsByRep($day);
    near('ولا في «صرف اليوم» بتاع لوحة التحكم', $totals[$repId]['spent'] ?? 0, 0);
    near('و«فاضل معاه» لسه 5,000', $totals[$repId]['pocket'] ?? -1, 5000);

    // ══════════════════════════════════════════════ ٤) البند المدفوع لسه بيخصم
    sec('٤) الضمانة العكسية: البند العادي لسه بيخصم');

    Database::exec(
        "INSERT INTO permit_stages
            (permit_id, stage_key, direction, amount, qty, entry_date, note,
             status, is_visa, unpaid, paid_by_rep_id)
         VALUES (?, 'receipts', NULL, 700, 1, ?, '__up_paid', 'done', 0, 0, ?)",
        [$permitId, $day, $repId]);

    near('بند مدفوع بـ700 خصم من الخزنة', Closing::currentBalance(), $balBefore - 700);
    near('وخصم من عهدة المندوب', RepCustody::balance($repId, $day), $dayBefore - 700);
    ok('يعني الاستثناء بيخصّ الموسوم بس — مش بيعطّل الخصم العادي');

    // ══════════════════════════════════════════════ ٥) بيظهر ولا يختفي
    sec('٥) البند بيفضل ظاهر (توثيق مش إخفاء)');

    $stages = Permit::stages($permitId);
    $found = array_values(array_filter($stages, static fn ($s) => (int) $s['id'] === $unpaidId));
    $found ? ok('البند في تايم لاين الإذن') : bad('البند اختفى من الإذن!');
    if ($found) {
        (int) $found[0]['unpaid'] === 1 ? ok('وموسوم unpaid=1') : bad('الوسم ضاع');
    }

    $ledger = \App\Models\Expense::ledger(['from' => $day, 'to' => $day], 100, 0);
    $inLedger = array_values(array_filter($ledger,
        static fn ($r) => (string) ($r['note'] ?? '') === '__up_unpaid'));
    $inLedger ? ok('وفي سجل المصروفات') : bad('البند مش في سجل المصروفات');
    if ($inLedger) {
        !empty($inLedger[0]['unpaid'])
            ? ok('وموسوم في السجل عشان ما يضلّلش') : bad('مش موسوم في سجل المصروفات');
    }

    // شاشة مراجعة التقفيل: قسم معلوماتي مش مانع
    $rv = DailyReview::view($day);
    array_key_exists('unpaid', $rv['groups'])
        ? ok('وله قسم مستقل في شاشة مراجعة التقفيل') : bad('مفيش قسم «غير مدفوع» في المراجعة');
    !in_array('unpaid', DailyReview::BLOCKING, true)
        ? ok('★ والقسم ده **ما بيمنعش** الإقفال (زي الفيزا)') : bad('القسم بيمنع الإقفال — غلط');
    near('ومبلغه في خانة منفصلة عن الفيزا', $rv['totals']['unpaid'] ?? -1, $big);
    near('وما دخلش إجمالي الكاش', $rv['totals']['cash'] ?? -1, 700);

    // ⚠️ الفحص اللي بيمسك أي تسريب: مجموع الأقسام النقدية = مصروفات التقفيل.
    // ⚠️ لازم نعيد قراءة `dayView` **دلوقتي** — النسخة اللي فوق اتقرت قبل ما
    //    نضيف البند المدفوع في القسم ٤، ومقارنتها هتفشل بإنذار كاذب.
    $view = Closing::dayView($day);
    $cashSum = 0.0;
    foreach (DailyReview::BLOCKING as $g) {
        if (($rv['groups'][$g]['dir'] ?? '') === 'out') { $cashSum += $rv['groups'][$g]['total']; }
    }
    near('★ مجموع أقسام المراجعة النقدية = مصروفات التقفيل بالمليم',
         $cashSum, (float) ($view['expenses'] ?? 0) + (float) ($view['driver'] ?? 0));

    // ══════════════════════════════════════════════ ٦) الحُرّاس
    sec('٦) الحُرّاس في مسار المندوب');

    $portal = code_only('app/Models/RepPortal.php');
    str_contains($portal, "!\$misc && !empty(\$d['unpaid'])")
        ? ok('النثرية ما بتاخدش الوسم (قرار المالك)') : bad('حارس النثرية مش موجود');
    str_contains($portal, "'unpaid'] = 'اختار واحدة بس")
        ? ok('و«غير مدفوع» متنافي مع «مدفوع بالفيزا»') : bad('التنافي مع الفيزا مش متحقَّق منه');

    has('الاعتماد بينقل الوسم لبند الإذن', 'app/Models/RepInbox.php', "\$s['unpaid']");
    has('والمالك يقدر يغيّره قبل الاعتماد', 'app/Models/RepInbox.php', "array_key_exists('unpaid', \$d)");
    has('وشاشة المندوب فيها الخانة', 'app/Views/m/entry.php', '_unpaid_field.php');
    has('وشاشة النثرية مالهاش الخانة (قرار المالك)', 'app/Views/m/misc.php', '_visa_field.php');
    $miscView = (string) file_get_contents(dirname(__DIR__) . '/app/Views/m/misc.php');
    !str_contains($miscView, '_unpaid_field.php')
        ? ok('★ ومتأكدين إن النثرية مفيهاش الخانة') : bad('خانة «غير مدفوع» اتحطّت في النثرية');

    // كل كلاس بيتطبع لازم يكون له CSS (مصيدة .table-mobile-scroll)
    $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');
    foreach (['tl-unpaid-badge', 'unpaid-pill'] as $cls) {
        str_contains($css, '.' . $cls)
            ? ok("الكلاس .{$cls} معرَّف في app.css") : bad("الكلاس .{$cls} بيتطبع بلا CSS");
    }
    $closingCss = (string) file_get_contents(dirname(__DIR__) . '/app/Views/closing/index.php');
    str_contains($closingCss, '.rv-tag.unpaid')
        ? ok('وشارة شاشة التقفيل معرَّفة') : bad('.rv-tag.unpaid بلا CSS');

} catch (\Throwable $e) {
    bad('استثناء غير متوقّع: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo "\n" . str_repeat('═', 62) . "\n";
    echo "اترجّع كل شيء (rollback) — مفيش أي أثر.\n";
    near('رصيد الخزنة قبل = بعد', Closing::currentBalance(), $treasuryBefore);
    if (RepCustody::available()) {
        near('وإجمالي عهد المناديب قبل = بعد', RepCustody::totalOutstanding(), $repsBefore);
    }
    echo "\nنجح: $pass | فشل: $fail\n";
    exit($fail > 0 ? 1 : 0);
}
