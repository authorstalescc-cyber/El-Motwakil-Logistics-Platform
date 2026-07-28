<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص «تصفية العهدة بضغطة» — زرار التصفية في المكتب وفي موبايل المندوب.
 *
 * المشكلة اللي الميزة اتعملت عشانها: التسوية اليدوية كانت بتطلب من المالك يكتب
 * المبلغ بإيده، فأي فرق صغير بيخلّي الرصيد يفضل مفتوح و«يترحّل» من يوم ليوم
 * للأبد. الزرار الجديد بيقفل الحساب كله مرة واحدة والرصيد يقع **صفر بالظبط**.
 *
 * أهم ٤ حاجات محروسة هنا:
 *   ١) الرصيد بعد التصفية = صفر بالمليم (موجب وسالب).
 *   ٢) **البنود المستنية مستثناة**: التصفية بتقفل الكاش اللي في إيده بس،
 *      والرصيد يفضل = المستنّي فيقع صفر لوحده أول ما يتعتمد. لو دخلت في
 *      المبلغ، الرصيد كان هيقع سالب بعد الاعتماد بلا سبب مفهوم.
 *   ٣) **العزل المالي (v42):** التصفية ما بتمسّش خزنة المكتب خالص.
 *   ٤) **المندوب ما يقدرش يصفّي رصيدًا سالبًا** — ده معناه المكتب مدين له،
 *      وتسجيل إن المكتب دفعه قرار مكتب.
 *
 * كله جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_rep_settle_zero.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;
use App\Models\RepCustody;

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function sec(string $m): void { echo "\n" . $m . "\n" . str_repeat('─', 62) . "\n"; }
function near(string $l, $a, $e): void {
    abs((float) $a - (float) $e) <= 0.005 ? ok($l)
        : bad("$l — متوقع " . number_format((float) $e, 2) . " / فعلي " . number_format((float) $a, 2));
}
function has(string $l, string $file, string $needle): void {
    $p = dirname(__DIR__) . '/' . $file;
    $src = is_file($p) ? (string) file_get_contents($p) : '';
    str_contains($src, $needle) ? ok($l) : bad("{$l} — «{$needle}» مش موجودة في {$file}");
}
function hasnt(string $l, string $file, string $needle): void {
    $p = dirname(__DIR__) . '/' . $file;
    $src = is_file($p) ? (string) file_get_contents($p) : '';
    !str_contains($src, $needle) ? ok($l) : bad("{$l} — «{$needle}» لسه موجودة في {$file}");
}

echo "فحص تصفية العهدة بضغطة\n" . str_repeat('=', 62) . "\n";

// ══════════════════════════════════════════════════ ١) البنية
sec('١) البنية — الموديل والكنترولرات والمسارات');
method_exists(RepCustody::class, 'clearSnapshot')
    ? ok('RepCustody::clearSnapshot موجودة (المصدر الوحيد لمبلغ التصفية)')
    : bad('RepCustody::clearSnapshot ناقصة');
method_exists(RepCustody::class, 'settleZero')
    ? ok('RepCustody::settleZero موجودة') : bad('RepCustody::settleZero ناقصة');
method_exists(\App\Controllers\RepCustodyController::class, 'settleAll')
    ? ok('RepCustodyController::settleAll موجودة (زرار المكتب)') : bad('settleAll ناقصة');
foreach (['settleForm', 'settleStore'] as $m) {
    method_exists(\App\Controllers\RepPortalController::class, $m)
        ? ok("RepPortalController::$m موجودة (زرار المندوب)") : bad("RepPortalController::$m ناقصة");
}
has('مسار المكتب POST /rep-custody/{id}/settle-all', 'public/index.php', "settle-all");
has('مسار المندوب GET /m/settle',  'public/index.php', "\$r->get('/m/settle'");
has('مسار المندوب POST /m/settle', 'public/index.php', "\$r->post('/m/settle'");

// ⚠️ المسار الحرفي لازم يفضل **قبل** الجشع `/m/permit/{id}` (تحذير ٤).
$routes = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
$posSettle = strpos($routes, "\$r->get('/m/settle'");
$posGreedy = strpos($routes, "\$r->get('/m/permit/{id}'");
($posSettle !== false && $posGreedy !== false && $posSettle < $posGreedy)
    ? ok('⚠️ /m/settle حرفي قبل الجشع /m/permit/{id} (تحذير ٤)')
    : bad('/m/settle لازم يتسجّل قبل /m/permit/{id}');

sec('٢) الشاشة — جزء واحد مشترك مش نسختين');
$partial = 'app/Views/rep_custody/_settle_zero.php';
is_file(dirname(__DIR__) . '/' . $partial)
    ? ok('جزء التصفية المشترك موجود') : bad("$partial ناقص");
has('تبويب «اليوم» بيستدعي الجزء المشترك', 'app/Views/rep_custody/_tab_day.php', "_settle_zero.php");
has('تبويب «الحساب الكامل» بيستدعي نفس الجزء', 'app/Views/rep_custody/_tab_account.php', "_settle_zero.php");
has('الجزء بيبعت على مسار التصفية', $partial, "'/settle-all'");
has('⚠️ وفيه تأكيد قبل التنفيذ (data-confirm)', $partial, 'data-confirm');
has('وفيه رمز CSRF', $partial, 'csrfField()');
has('⚠️ ورمز محاولة (claimForm بترفض الفورم بدونه)', $partial, 'formStateFields');
has('شاشة الموبايل موجودة', 'app/Views/m/settle.php', 'm/settle');
has('ولها رابط في شاشة المندوب الرئيسية', 'app/Views/m/home.php', "url('m/settle')");
// ⚠️ قاعدة v35: ممنوع أي سعر بيخصّ العميل يوصل شاشة المندوب.
hasnt('⚠️ شاشة المندوب مفيهاش actual_amount (قاعدة v35)', 'app/Views/m/settle.php', 'actual_amount');
hasnt('⚠️ ولا actual_unit', 'app/Views/m/settle.php', 'actual_unit');
// ⚠️ المبلغ بيتحسب في الخادم — الفورم مش مصدر ثقة.
hasnt('⚠️ فورم المكتب مش بيبعت المبلغ (الخادم بيحسبه)', $partial, 'name="returned"');
hasnt('⚠️ وفورم الموبايل كمان', 'app/Views/m/settle.php', 'name="amount"');

$pdo = Database::conn();
$pdo->beginTransaction();
try {
    $day = date('Y-m-d');
    $mk = static function (string $name): int {
        Database::exec("INSERT INTO reps (name, status) VALUES (?, 'active')", [$name]);
        return Database::lastId();
    };
    /** نثرية معتمدة = صرف من عهدة المندوب (v39). */
    $spend = static function (int $rid, float $amount, string $day): void {
        Database::exec(
            "INSERT INTO rep_misc_expenses (rep_id, exp_date, category, note, qty, unit_amount, amount, created_by)
             VALUES (?,?,?,?,1,?,?,'فحص')",
            [$rid, $day, 'نثريات', '__zero_test', $amount, $amount]);
    };
    // ⚠️ `rep_submissions.permit_id` عليه FK على `permits` — البند لازم يبقى على
    //    إذن موجود فعلاً (النثرية بس هي اللي بتبقى NULL). بناخد أي إذن قايم.
    $anyPermit = Database::scalar("SELECT id FROM permits ORDER BY id LIMIT 1");
    /** بند مستني مراجعة — فلوسه خرجت من إيده بس لسه ما اتخصمتش. */
    $pend = static function (int $rid, float $amount, string $day) use ($anyPermit): void {
        Database::exec(
            "INSERT INTO rep_submissions (rep_id, permit_id, kind, note, qty, unit_amount, amount, entry_date, status)
             VALUES (?,?,'expense','__zero_test',1,?,?,?,'pending')",
            [$rid, $anyPermit, $amount, $amount, $day]);
    };

    // ─────────────────────────────────────── ٣) الحالة العادية
    sec('٣) رصيد موجب — التصفية بترجّعه صفر بالمليم');
    $a = $mk('__zero_A');
    RepCustody::createDeposit($a, 5000, $day, '');
    $spend($a, 3250.75, $day);
    near('قبل التصفية: الرصيد 1,749.25', RepCustody::balance($a), 1749.25);
    $snap = RepCustody::clearSnapshot($a);
    near('لقطة التصفية بتقول نفس الرقم', $snap['amount'], 1749.25);

    $t0 = Closing::currentBalance();
    $res = RepCustody::settleZero($a, null, '', 'فحص');
    near('التصفية سجّلت نفس المبلغ', $res['amount'], 1749.25);
    near('⚠️ الرصيد بعد التصفية = صفر بالظبط', RepCustody::balance($a), 0);
    near('⚠️ وبرضه من breakdown (مفيش تعريف تاني للرصيد)', RepCustody::breakdown($a)['balance'], 0);
    $cards = [];
    foreach (RepCustody::cards() as $c) { $cards[(int) $c['id']] = $c['balance']; }
    near('⚠️ وبرضه من cards (كارت المكتب)', $cards[$a] ?? -1, 0);

    sec('٤) ⚠️ العزل المالي — التصفية ما بتمسّش الخزنة (قاعدة v42)');
    near('رصيد الخزنة قبل التصفية = بعدها', Closing::currentBalance(), $t0);
    hasnt('⚠️ ولا كود الخزنة بيعرف عن settleZero', 'app/Models/Closing.php', 'settleZero');

    sec('٥) تصفية مرتين — التانية بترفض بدل ما تسجّل صفر');
    try {
        RepCustody::settleZero($a, null, '', 'فحص');
        bad('✗ اتقبلت تصفية تانية والرصيد صفر');
    } catch (\RuntimeException $e) {
        str_contains($e->getMessage(), 'مصفّرة') || str_contains($e->getMessage(), 'يوم مفتوح')
            ? ok('اترفضت برسالة عربية واضحة') : bad('اترفضت برسالة غير متوقّعة: ' . $e->getMessage());
    }

    // ─────────────────────────────────────── ٦) البنود المستنية
    /* ⚠️⚠️ **القاعدة اتقلبت بقرار المالك (2026-07-27) — ده مش ترخية للفحص.**
       كانت: `amount = الرصيد − المستنّي`، فالزرار بيسيب وراه رصيدًا = المستنّي
       والمالك بيقول «مش بيصفّر». وأسوأ: لو المستنّي أكبر من الرصيد كان بيسجّل
       تسوية **بالسالب** — حصل فعليًّا مرتين على الإنتاج (−2,830 و−4,845).
       بقت: التصفية **بترفض** لحد ما البنود تتراجع، وبعدها بتصفّر بالمليم. */
    sec('٦) ⚠️ البنود المستنية بتوقف التصفية (مش بتتخصم منها)');
    $b = $mk('__zero_B');
    RepCustody::createDeposit($b, 5000, $day, '');
    $spend($b, 2000, $day);
    $pend($b, 1000, $day);
    $snapB = RepCustody::clearSnapshot($b);
    near('الرصيد المعتمَد 3,000', $snapB['balance'], 3000);
    near('المستنّي 1,000', $snapB['pending'], 1000);
    !empty($snapB['blocked'])
        ? ok('★ اللقطة بتقول إن التصفية متوقّفة') : bad('اللقطة مش بتوقف التصفية');
    near('★ والمبلغ بقى الرصيد كامل 3,000 (مش 2,000)', $snapB['amount'], 3000);
    try {
        RepCustody::settleZero($b, null, '', 'فحص');
        bad('✗ التصفية عدّت والبند لسه مستنّي');
    } catch (\RuntimeException $e) {
        str_contains($e->getMessage(), 'مستني مراجعتك')
            ? ok('★ اترفضت برسالة بتقول «راجعهم الأول»')
            : bad('اترفضت برسالة غير متوقّعة: ' . $e->getMessage());
    }
    near('والرصيد ما اتغيّرش لأن مفيش حاجة اتكتبت', RepCustody::balance($b), 3000);
    // بعد المراجعة: البند بيتحوّل لصرف معتمد
    $spend($b, 1000, $day);
    Database::exec("UPDATE rep_submissions SET status='approved' WHERE rep_id=? AND status='pending'", [$b]);
    $resB = RepCustody::settleZero($b, null, '', 'فحص');
    near('بعد المراجعة التصفية سجّلت 2,000', $resB['amount'], 2000);
    near('★ والرصيد وقع صفر بالمليم', RepCustody::balance($b), 0);

    // ─────────────────────────────────────── ٦ب) كل يوم بيتقفل على تاريخه
    /* ⚠️ **قلب الإصلاح (2026-07-27).** التصفية القديمة كانت بتاخد مبلغًا
       **تراكميًّا** وتسجّله على **يوم واحد**، فاليوم ده بيتلوّث برقم من أيام
       تانية (اتصاد فعليًّا: تسوية −4,845 على 07-26 خلّت عهدة اليوم 6,845
       والمنزَّل فيه 2,000 بس). دلوقتي كل يوم بياخد تسويته على تاريخه. */
    sec('٦ب) ★ تصفية أيام متعددة — كل يوم بيقع صفر لوحده');
    $d1 = '2037-09-10'; $d2 = '2037-09-11'; $d3 = '2037-09-12';
    $e = $mk('__zero_E');
    RepCustody::createDeposit($e, 4000, $d1, '');
    $spend($e, 1500, $d1);                       // يوم ١ فاضل 2,500
    RepCustody::createDeposit($e, 1000, $d2, '');
    $spend($e, 1800, $d2);                       // يوم ٢ فاضل −800
    RepCustody::createDeposit($e, 600, $d3, '');  // يوم ٣ فاضل 600
    near('يوم ١ = 2,500', RepCustody::balance($e, $d1), 2500);
    near('يوم ٢ = −800',  RepCustody::balance($e, $d2), -800);
    near('يوم ٣ = 600',   RepCustody::balance($e, $d3), 600);
    near('★ والتراكمي = مجموعهم بالظبط', RepCustody::balance($e), 2300);

    $open = RepCustody::openDays($e);
    count($open) === 3 ? ok('openDays لقت ٣ أيام مفتوحة') : bad('openDays لقت ' . count($open));

    // تصفية يوم واحد بس
    $resD = RepCustody::settleZero($e, $d2, '', 'فحص');
    near('تصفية يوم ٢ سجّلت −800', $resD['amount'], -800);
    near('★ يوم ٢ بقى صفر', RepCustody::balance($e, $d2), 0);
    near('★ ويوم ١ ما اتلمسش', RepCustody::balance($e, $d1), 2500);
    near('★ ويوم ٣ ما اتلمسش', RepCustody::balance($e, $d3), 600);

    // تصفية الباقي
    $resAll = RepCustody::settleZero($e, null, '', 'فحص');
    ((int) $resAll['days']) === 2 ? ok('تصفية الباقي قفلت يومين') : bad('قفلت ' . $resAll['days'] . ' يوم');
    near('بإجمالي 3,100', $resAll['amount'], 3100);
    near('★ يوم ١ = صفر', RepCustody::balance($e, $d1), 0);
    near('★ يوم ٣ = صفر', RepCustody::balance($e, $d3), 0);
    near('★★ والتراكمي وقع صفر لوحده', RepCustody::balance($e), 0);
    count(RepCustody::openDays($e)) === 0
        ? ok('★ مفيش أي يوم مفتوح فاضل') : bad('لسه فيه أيام مفتوحة بعد التصفية');

    // ⚠️ الضمانة اللي بتمنع رجوع الباج: التسوية اتسجّلت على تاريخ اليوم نفسه
    $wrongDate = (int) Database::scalar(
        "SELECT COUNT(*) FROM rep_custody_settlements
         WHERE rep_id = ? AND settle_date NOT IN (?,?,?)", [$e, $d1, $d2, $d3]);
    $wrongDate === 0
        ? ok('★ كل تسوية اتسجّلت على تاريخ اليوم اللي بتقفله')
        : bad("{$wrongDate} تسوية اتسجّلت على تاريخ غلط — اليوم ده هيتلوّث");

    // ─────────────────────────────────────── ٧) الرصيد السالب
    sec('٧) رصيد سالب — المكتب بيدفع له والرصيد يرجع صفر');
    $c = $mk('__zero_C');
    RepCustody::createDeposit($c, 1000, $day, '');
    $spend($c, 1500, $day);
    near('قبل: الرصيد −500 (صرف من جيبه)', RepCustody::balance($c), -500);
    $resC = RepCustody::settleZero($c, null, '', 'فحص');
    near('المبلغ اتسجّل بالسالب (المكتب دفع له)', $resC['amount'], -500);
    near('⚠️ والرصيد رجع صفر', RepCustody::balance($c), 0);
    $ret = (float) Database::scalar(
        "SELECT returned FROM rep_custody_settlements WHERE id = ?", [$resC['ids'][0]]);
    near('العمود returned بيقبل السالب في القاعدة', $ret, -500);

    sec('٨) ⚠️ المندوب ما يقدرش يصفّي رصيدًا سالبًا (قرار مكتب)');
    has('الكنترولر بيرفض السالب قبل ما يكتب', 'app/Controllers/RepPortalController.php',
        "رصيدك سالب");
    has('والشاشة بتقول له يكلّم المكتب', 'app/Views/m/settle.php', 'المكتب هو اللي مستحق لك');

    sec('٩) العرض بيفهم المبلغ السالب (مش بيخبّيه)');
    has('كشف التسويات بيقول «دفعناله»', 'app/Views/rep_custody/_tab_account.php', 'دفعناله');
    has('و«عهدة كل يوم» بتوريه مش بتحطّ شرطة', 'app/Views/rep_custody/_tab_account.php',
        "abs(\$d['returned'])");
    has('ومعادلة اليوم بتزوّده مش بتخصمه', 'app/Views/rep_custody/_tab_day.php', 'دفعناله');
    has('وشاشة المشرف كمان', 'app/Views/m/team_rep.php', 'المكتب دفعله');

    // ─────────────────────────────────────── ١٠) رندر فعلي
    sec('١٠) الشاشتان بترندروا فعليًّا (فشل صامت ما يعدّيش)');
    $render = static function (callable $fn): string {
        ob_start();
        try { $fn(); return (string) ob_get_clean(); }
        catch (\Throwable $e) { ob_end_clean(); bad('استثناء وقت الرندر: ' . $e->getMessage()); return ''; }
    };
    $noPhpError = static function (string $h, string $where) {
        preg_match('/Fatal error|Warning:|Undefined variable|Undefined array key/', $h, $m)
            ? bad("خطأ PHP في {$where}: " . ($m[0] ?? '')) : ok("{$where} بيرندر بلا أخطاء PHP");
    };

    // كارت المكتب — تبويب اليوم (الزرار المفروض يبان فيه)
    $_SESSION = ['uid' => (int) Database::scalar('SELECT MIN(id) FROM users'), 'role' => 'owner',
                 'uname' => 'فحص', 'last_activity' => time(), 'uid_checked' => time(),
                 'work_date' => $day];
    $d = $mk('__zero_D');
    RepCustody::createDeposit($d, 2400, $day, '');
    $ctrl = new \App\Controllers\RepCustodyController();
    $_GET = ['tab' => 'day'];
    $html = $render(static fn () => $ctrl->card((string) $d));
    if ($html === '') { bad('كارت المندوب ما رندرش'); } else {
        $noPhpError($html, 'كارت المكتب (تبويب اليوم)');
        // ⚠️ نص الزرار بقى بيحمل اليوم («صفّي عهدة يوم كذا») — افحص المعنى
        //    مش تسمية بعينها، وإلا الفحص يفشل مع أول تغيير في الصياغة.
        str_contains($html, 'صفّي عهدة يوم') || str_contains($html, 'صفّي كل الأيام المفتوحة')
            ? ok('زرار «صفّي العهدة» ظاهر في كارت المكتب') : bad('زرار التصفية مش ظاهر في الكارت');
        str_contains($html, '/settle-all')
            ? ok('وبيبعت على مسار التصفية') : bad('الزرار مش مربوط بمساره');
    }

    // موبايل المندوب
    $_SESSION = ['rep_id' => $d, 'rep_name' => '__zero_D', 'rep_seen' => time(),
                 'rep_check' => time(), 'work_date' => $day];
    $portal = new \App\Controllers\RepPortalController();
    $_GET = [];
    $mHtml = $render(static fn () => $portal->settleForm());
    if ($mHtml === '') { bad('/m/settle ما رندرش'); } else {
        $noPhpError($mHtml, 'شاشة المندوب /m/settle');
        str_contains($mHtml, 'صفّي العهدة — سلّمت')
            ? ok('زرار التصفية ظاهر للمندوب') : bad('زرار التصفية مش ظاهر للمندوب');
        str_contains($mHtml, '2,400') || str_contains($mHtml, '2400')
            ? ok('والمبلغ الصح ظاهر عليه (2,400)') : bad('المبلغ مش ظاهر صح في شاشة المندوب');
    }

    sec('١١) الإشعار للمكتب لما المندوب يصفّي من موبايله');
    has('PushNotify::repSettled بتتنادى من البوابة', 'app/Controllers/RepPortalController.php',
        'PushNotify::repSettled');
    has('ومن المكتب كمان', 'app/Controllers/RepCustodyController.php', 'PushNotify::repSettled');

} catch (\Throwable $e) {
    bad('استثناء غير متوقّع: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo "\n" . str_repeat('═', 62) . "\n";
    echo "اترجّع كل شيء (rollback) — مفيش أي أثر.\n";
    echo "رصيد الخزنة دلوقتي: " . number_format(Closing::currentBalance(), 2) . "\n";
    echo "\nنجح: $pass | فشل: $fail\n";
    exit($fail > 0 ? 1 : 0);
}
