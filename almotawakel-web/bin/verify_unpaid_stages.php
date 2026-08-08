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
use App\Models\RepPortal;

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
str_contains(RepPortal::pendingCashSql('s'), 's.unpaid = 0')
    ? ok('والمستنّي النقدي بيستثني «غير مدفوع» من «فاضل معاه»')
    : bad('pendingCashSql لا تستثني «غير مدفوع»!');
str_contains(RepPortal::pendingCashSql('s'), 's.is_visa = 0')
    ? ok('والمستنّي النقدي ما زال يستثني الفيزا')
    : bad('pendingCashSql وقّعت استثناء الفيزا!');
has('وحسابات العهدة تستخدم شرط المستنّي الموحّد', 'app/Models/RepCustody.php',
    'RepPortal::pendingCashSql()');

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

    // نفس الشكوى حرفيًا قبل الاعتماد: «غير مدفوع» يظل في الصندوق، لكنه لا
    // يُطرح من «فاضل معاه». البند النقدي العادي وحده هو الذي يُطرح.
    Database::exec(
        "INSERT INTO rep_submissions
            (rep_id, client_token, permit_id, kind, note, qty, unit_amount, amount,
             entry_date, is_visa, unpaid, status)
         VALUES (?, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', ?, 'receipt',
                 '__up_pending_unpaid', 1, 8000, 8000, ?, 0, 1, 'pending')",
        [$repId, $permitId, $day]);
    $pendingUnpaidId = Database::lastId();
    Database::exec(
        "INSERT INTO rep_submissions
            (rep_id, client_token, permit_id, kind, note, qty, unit_amount, amount,
             entry_date, is_visa, unpaid, status)
         VALUES (?, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', ?, 'receipt',
                 '__up_pending_cash', 1, 350, 350, ?, 0, 0, 'pending')",
        [$repId, $permitId, $day]);
    $pendingCashId = Database::lastId();

    near('المستنّي النقدي = 350 فقط (من غير 8,000 غير مدفوعة)',
         RepPortal::pendingAmount($repId, $day), 350);
    $pendingTotals = RepCustody::dayTotalsByRep($day);
    near('و«فاضل معاه» = 4,650، ما اتخصمش منه غير النقدي',
         $pendingTotals[$repId]['pocket'] ?? -1, 4650);
    near('وتجميع كل المناديب شايف المستنّي النقدي فقط',
         $pendingTotals[$repId]['pending'] ?? -1, 350);

    Database::exec("DELETE FROM rep_submissions WHERE id IN (?,?)",
        [$pendingUnpaidId, $pendingCashId]);
    near('بعد حذف fixtures المستنّية رجع «فاضل معاه» 5,000',
         RepCustody::dayTotalsByRep($day)[$repId]['pocket'] ?? -1, 5000);

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

    /* ⚠️ **بندان مش بند واحد — والسبب إن القسم ده بيفحص محفظتين.**
       البند الأصلي كان موسومًا لمندوب وبيتفحص على الخزنة **و**على عهدة المندوب
       مع بعض. المرحلة ٢ من docs/16 قلبت القاعدة: البند المدفوع من محفظة مندوب
       **مبقاش يمسّ الدرج** — فالفحص الواحد بقى بيخلط متغيّرين ويفشل بإنذار
       كاذب (اتصاد فعليًّا بعد التفعيل على الإنتاج).
       التفكيك: بند **مكتب** يفحص الخزنة (بيتخصم تحت القاعدتين)، وبند **مندوب**
       يفحص محفظته. وكل واحد بيقيس اللي يخصّه بس. */
    Database::exec(
        "INSERT INTO permit_stages
            (permit_id, stage_key, direction, amount, qty, entry_date, note,
             status, is_visa, unpaid, paid_by_rep_id)
         VALUES (?, 'receipts', NULL, 700, 1, ?, '__up_office', 'done', 0, 0, NULL)",
        [$permitId, $day]);
    near('بند مكتب بـ700 خصم من الخزنة', Closing::currentBalance(), $balBefore - 700);

    Database::exec(
        "INSERT INTO permit_stages
            (permit_id, stage_key, direction, amount, qty, entry_date, note,
             status, is_visa, unpaid, paid_by_rep_id)
         VALUES (?, 'receipts', NULL, 700, 1, ?, '__up_paid', 'done', 0, 0, ?)",
        [$permitId, $day, $repId]);
    near('وبند مندوب بـ700 خصم من عهدته', RepCustody::balance($repId, $day), $dayBefore - 700);
    ok('يعني الاستثناء بيخصّ الموسوم `unpaid` بس — مش بيعطّل الخصم العادي');

    $activity = RepCustody::dayActivity($repId, $day);
    near('ملخص «الكاش اللي طلع من إيده» = 700 فقط', $activity['cash'] ?? -1, 700);
    near('والبند غير المدفوع ظاهر في خانته المعلوماتية', $activity['unpaid']['sum'] ?? -1, $big);
    ($activity['unpaid']['cnt'] ?? 0) === 1
        ? ok('وعدد البنود غير المدفوعة في الملخص = 1')
        : bad('ملخص اليوم لم يفصل البند غير المدفوع!');
    $daySpend = RepCustody::daySpending($day);
    near('وتفاصيل «صرف اليوم» لا تضم غير الكاش الفعلي',
         array_sum(array_column($daySpend, 'amount')), 700);

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
    /* ⚠️ **الفحص على المطابقة مش على رقم محفوظ.** كان مكتوبًا `700` حرفيًّا —
       وهو مبلغ بند آخر في الـfixtures، فأي تعديل في الزرع بيفشّله بإنذار كاذب
       (حصل فعلاً لما البند اتقسم بندين فوق). القاعدة الحقيقية اللي عايزين
       نحرسها هي قاعدة v50: **مجموع أقسام المراجعة النقدية = مصروفات Closing**،
       وهي صح تحت القاعدتين، فبند «غير مدفوع» لو دخل الكاش بالغلط بتتكسر فورًا. */
    $dv = Closing::dayView($day);
    near('وما دخلش إجمالي الكاش (مطابقة أقسام المراجعة مع Closing)',
         $rv['totals']['cash'] ?? -1,
         round((float) $dv['expenses'] + (float) $dv['driver'], 2));
    ($rv['totals']['cash'] ?? 0) < $big - 0.005
        ? ok('ومبلغ البند غير المدفوع (' . number_format($big, 2) . ') برّه الكاش')
        : bad('مبلغ البند غير المدفوع داخل الكاش!');

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
    has('كارت المندوب يوضح أن غير المدفوع مش من العهدة',
        'app/Views/rep_custody/_tab_day.php', 'غير مدفوع — مش من العهدة');
    has('والكشف التراكمي يوضّح نفس الوسم',
        'app/Views/rep_custody/_tab_account.php', 'غير مدفوع — مش من العهدة');
    has('ونوافذ التفاصيل تميّزه عن الكاش',
        'app/Views/rep_custody/_drill.php', 'غير مدفوع — مش من العهدة');

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
