<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص «جرد الخزينة» (v108) — الخزنتان في كشف واحد + شهادة العدّ الفعلي.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  القواعد المحروسة هنا — اقرأ قبل ما تعدّل
 * ═══════════════════════════════════════════════════════════════════════
 *   ① **العزل بالبناء** — ولا ملف مالي بيعرف `safe_audits`، ومفيش FK، والجرد
 *      **ما بيغيّرش ولا مليم** في أي محفظة (بيتقاس بسلوك حقيقي: نسجّل جرد
 *      بفرق ضخم ونقارن الأرصدة قبل وبعد).
 *   ② **ولا رقم بيتحسب من الأول** — أرقام الوحدة لازم تطابق مصادرها بالمليم:
 *        درج المكتب  = `Closing::walletSplit()['drawer']`
 *        خزنة الشركة = `CompanySafe::balance()`
 *        كل الكاش    = `walletSplit()['grand']`
 *   ③ **المعادلة الكبرى** — مجموع صفوف جهة المكتب في الفترة =
 *      `Closing::rangeMovement()['net']` بالمليم. لو اتكسرت يبقى فيه شرط
 *      اتضاف في `Closing` وما وصلش `DailyReview::officeCashItems`.
 *   ④ **`closingFor` = `openingFor` لليوم اللي بعده** — مفيش معادلة تانية.
 *   ⑤ **التحويلات الداخلية بتتوسم وما بتتعدّش مرتين** — بسلوك حقيقي:
 *      تحويل من الشركة للمكتب لازم يدّي صفّين موسومين وصافي صفر.
 *   ⑥ **الحُرّاس** — خزنة مزوّرة · تاريخ في المستقبل · معدود بالسالب · فواصل
 *      الآلاف · الرصيد الدفتري من الخادم مش من الفورم.
 *   ⑦ **رندر فعلي** لكل تبويب وكل نافذة وكشف الطباعة.
 *   ⑧ **كل كلاس مطبوع له شكل، وكل أيقونة موجودة** (درس `.table-mobile-scroll`
 *      و`icon('اسم-غلط')` اللي بترسم دايرة فاضية).
 *
 * كله جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_v108.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;
use App\Models\CompanySafe;
use App\Models\DailyReview;
use App\Models\SafeAudit;
use App\Models\SafeInventory;

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function sec(string $m): void { echo "\n" . $m . "\n" . str_repeat('─', 64) . "\n"; }
function near(string $l, $a, $e): void {
    abs((float) $a - (float) $e) <= 0.005 ? ok($l)
        : bad("{$l} — متوقع " . number_format((float) $e, 2) . ' / فعلي ' . number_format((float) $a, 2));
}
/** ⚠️ كود بلا تعليقات — التعليق اللي بيوثّق القاعدة بيذكر نصّها فيدي نتيجة كاذبة. */
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
function src(string $file): string {
    $p = dirname(__DIR__) . '/' . $file;
    return is_file($p) ? (string) file_get_contents($p) : '';
}

echo "فحص جرد الخزينة (v108)\n" . str_repeat('=', 64) . "\n";

if (!SafeAudit::ready()) {
    echo "\n⚠️ جدول safe_audits مش موجود — شغّل bin/migrate_v108.php الأول.\n";
    exit(1);
}

$root = dirname(__DIR__);
$day  = work_date();

// ═══════════════════════════════════════════════════════════ ①
sec('① العزل بالبناء — ولا ملف مالي بيعرف الجدول');

/* ⚠️ الحارس ده هو اللي بيمنع رجوع «الاستثناء المنسي»: أول ما ملف خزنة يذكر
   `safe_audits`، معناه إن الجرد بقى بيدخل حسابًا — والفحص بيفشل فورًا. */
$financial = [
    'app/Models/Closing.php', 'app/Models/Custody.php', 'app/Models/CompanySafe.php',
    'app/Models/DailyReview.php', 'app/Models/Expense.php', 'app/Models/Metrics.php',
    'app/Models/RepCustody.php', 'app/Models/VisaCard.php', 'app/Models/Payroll.php',
    'bin/backfill_closings.php',
];
$leak = [];
foreach ($financial as $f) {
    if (str_contains(code_only($f), 'safe_audits')) { $leak[] = $f; }
}
$leak ? bad('ملفات مالية بتعرف الجدول: ' . implode(', ', $leak))
      : ok('ولا ملف خزنة/تقفيل/مصروفات بيذكر `safe_audits`');

$fks = (int) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.key_column_usage
      WHERE table_schema = DATABASE() AND table_name = 'safe_audits'
        AND referenced_table_name IS NOT NULL");
$fks === 0 ? ok('مفيش مفتاح خارجي — شهادة مستقلّة') : bad("فيه $fks مفتاح خارجي");

/* ⚠️ `diff` لازم يفضل عمودًا عاديًّا — العمود المولَّد بيكسر استعادة النسخ. */
$extra = (string) Database::scalar(
    "SELECT COALESCE(EXTRA,'') FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'safe_audits' AND column_name = 'diff'");
stripos($extra, 'GENERATED') === false
    ? ok('`diff` عمود عادي (مش مولَّد)') : bad('`diff` بقى عمودًا مولَّدًا');

// ═══════════════════════════════════════════════════════════ ②
sec('② ولا رقم بيتحسب من الأول — كل رقم من مصدره');

$w    = Closing::walletSplit();
$snap = SafeInventory::snapshot();
near('درج المكتب = walletSplit.drawer', $snap['safes']['office']['book'], $w['drawer']);
near('خزنة الشركة = CompanySafe::balance', $snap['safes']['company']['book'], CompanySafe::balance());
near('مع المناديب = walletSplit.reps_total', $snap['reps'], $w['reps_total']);
near('كل الكاش = walletSplit.grand', $snap['grand'], $w['grand']);
near('bookBalance(office) = الدرج', SafeInventory::bookBalance('office'), $w['drawer']);
near('bookBalance(company) = رصيد الشركة', SafeInventory::bookBalance('company'), CompanySafe::balance());

/* ⚠️ **الدرج مش `currentBalance()`** — في النموذج المجمّع التاني شامل جيوب
   المناديب، والعدّ بيه كان هيدّي «عجز» دايم. الفحص ده بيمسك الرجوع للغلط. */
$mSrc = code_only('app/Models/SafeInventory.php');
(str_contains($mSrc, "Closing::walletSplit()['drawer']") && !str_contains($mSrc, 'Closing::currentBalance'))
    ? ok('العدّ بيتقارن بالدرج لا بالرصيد المجمّع')
    : bad('الوحدة بتقارن العدّ بـ`currentBalance` — عجز كاذب في النموذج المجمّع');

// ═══════════════════════════════════════════════════════════ ③
sec('③ المعادلة الكبرى — صفوف المكتب = حركة `Closing` بالمليم');

/* ⚠️ الفترة بتتاخد من **بيانات القاعدة نفسها** لا تاريخ مزروع: فحص مكتوب على
   شهر ثابت بيفضل ينجح كذبًا لما الشهر ده يفضى. */
$anyDate = (string) (Database::scalar(
    'SELECT MAX(cust_date) FROM manual_custody') ?: $day);
$from = date('Y-m-01', strtotime($anyDate));
$to   = date('Y-m-t', strtotime($anyDate));

$mv  = Closing::rangeMovement($from, $to);
$sum = 0.0; $n = 0;
for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
    foreach (DailyReview::officeCashItems($d) as $it) { $sum += (float) $it['signed']; $n++; }
}
near("مجموع صفوف المكتب ({$n} بند) = صافي حركة الفترة", round($sum, 2), $mv['net']);
near('داخل − خارج = الصافي', round($mv['in'] - $mv['out'], 2), $mv['net']);

$st = SafeInventory::statement($from, $to);
near('كشف المكتب: أول + صافي = آخر',
    $st['safes']['office']['opening'] + $st['safes']['office']['net'],
    $st['safes']['office']['closing'] - $st['safes']['office']['drift']);
near('كشف الشركة: أول + داخل − خارج = آخر',
    $st['safes']['company']['opening'] + $st['safes']['company']['in'] - $st['safes']['company']['out'],
    $st['safes']['company']['closing']);
near('المجمّع = مجموع الخزنتين',
    $st['total']['closing'],
    $st['safes']['office']['closing'] + $st['safes']['company']['closing']);
/* ⚠️ الإجمالي الخام والصافي: الفرق بينهم = التحويلات الداخلية بالظبط. */
near('in_gross − in = التحويلات', $st['total']['in_gross'] - $st['total']['in'], $st['transfers']);
near('out_gross − out = التحويلات', $st['total']['out_gross'] - $st['total']['out'], $st['transfers']);

// ═══════════════════════════════════════════════════════════ ④
sec('④ `closingFor` = `openingFor` لليوم اللي بعده');

$probe = date('Y-m-d', strtotime($to . ' -3 day'));
near('closingFor(يوم) = openingFor(اليوم اللي بعده)',
    Closing::closingFor($probe), Closing::openingFor(date('Y-m-d', strtotime($probe . ' +1 day'))));
$cSrc = code_only('app/Models/Closing.php');
str_contains($cSrc, 'return self::openingFor(date(\'Y-m-d\', strtotime($date . \' +1 day\')));')
    ? ok('`closingFor` بتنادي `openingFor` مش بتكرّر المعادلة')
    : bad('`closingFor` فيها معادلة تانية — هتختلف عن الرصيد أول ما شرط يتضاف');
/* ⚠️ الحدّ الأعلى في `rangeMovement` **شامل** — `movementTotals` بتاخده حصريًّا،
   فنسيان `+1 day` بياكل حركة آخر يوم في كل كشف. */
$oneDay = Closing::rangeMovement($probe, $probe);
$dayView = Closing::dayView($probe);
near('حركة يوم واحد = عهد اليوم (الحدّ الأعلى شامل)', $oneDay['topups'], $dayView['topups']);
near('حركة يوم واحد = مصروفات اليوم', $oneDay['expenses'], $dayView['expenses']);

// ═══════════════════════════════════════════════════════════ ⑤ + ⑥ (سلوك حقيقي)
sec('⑤ سلوك حقيقي جوّه transaction — تحويل داخلي + شهادة العدّ');

$pdo = Database::conn();
$pdo->beginTransaction();
try {
    $_SESSION['uid']   = $_SESSION['uid'] ?? 1;
    $_SESSION['uname'] = 'فحص v108';

    $beforeOffice  = round(Closing::currentBalance(), 2);
    $beforeCompany = round(CompanySafe::balance(), 2);
    $beforeDrawer  = round((float) Closing::walletSplit()['drawer'], 2);

    /* ── تحويل من الشركة للمكتب: لازم يبان صفّين موسومين وصافي صفر ── */
    $tDay = $day;
    if (Closing::isLocked($tDay)) {
        // اليوم متقفل ⇒ التحويل ممنوع أصلاً؛ بنجرّب أول يوم مفتوح بعد آخر إقفال.
        $tDay = date('Y-m-d', strtotime(((string) Closing::lastClosedDate()) . ' +1 day'));
    }
    CompanySafe::create(['entry_date' => $tDay, 'kind' => 'deposit',
                         'amount' => 90000, 'source' => 'فحص v108']);
    CompanySafe::create(['entry_date' => $tDay, 'kind' => 'to_office',
                         'amount' => 40000, 'source' => 'فحص v108']);

    $stT = SafeInventory::statement($tDay, $tDay);
    $marked = array_values(array_filter($stT['rows'], static fn ($r) => !empty($r['transfer'])));
    count($marked) === 2
        ? ok('التحويل بيبان صفّين موسومين «تحويل داخلي» (شركة + مكتب)')
        : bad('صفوف التحويل الموسومة = ' . count($marked) . ' بدل ٢');
    $tNet = 0.0;
    foreach ($marked as $r) { $tNet += (float) $r['signed']; }
    near('صافي التحويل على الخزنتين = صفر', round($tNet, 2), 0);
    near('التحويلات المرصودة = ٤٠٬٠٠٠', $stT['transfers'], 40000);
    near('الداخل الصافي بلا التحويل = ٩٠٬٠٠٠', $stT['total']['in'], 90000);
    near('الداخل الخام معاه = ١٣٠٬٠٠٠', $stT['total']['in_gross'], 130000);

    /* ── شهادة العدّ: بتسجّل الفرق وما بتحركش مليم ── */
    sec('⑥ شهادة العدّ — الحُرّاس وصفر أثر مالي');

    $book = SafeInventory::bookBalance('company');
    $id = SafeAudit::save($day, 'company', (string) ($book - 1500), 'فحص عجز');
    $row = SafeAudit::find($id);
    near('الرصيد الدفتري اتخزّن من الخادم', (float) $row['book_balance'], $book);
    near('الفرق = المعدود − الدفتري', (float) $row['diff'], -1500);

    $afterOffice  = round(Closing::currentBalance(), 2);
    $afterCompany = round(CompanySafe::balance(), 2);
    near('رصيد المكتب ما اتغيّرش بسبب الجرد', $afterOffice, $beforeOffice + 40000);
    near('رصيد الشركة ما اتغيّرش بسبب الجرد', $afterCompany, $beforeCompany + 90000 - 40000);
    near('درج المكتب زاد بالتحويل بس', round((float) Closing::walletSplit()['drawer'], 2),
        $beforeDrawer + 40000);

    // الحفظ التاني على نفس (يوم × خزنة) بيدهس مش بيزوّد صف
    $id2 = SafeAudit::save($day, 'company', (string) $book, 'تصحيح');
    $id2 === $id ? ok('الحفظ التاني بيدهس نفس الصف (جرد واحد لكل يوم/خزنة)')
                 : bad('اتعمل صف تاني لنفس اليوم والخزنة');
    near('الفرق بعد التصحيح = صفر', (float) SafeAudit::find($id)['diff'], 0);

    // فواصل الآلاف — «1,500» لازم تتقرا ١٥٠٠ لا ١
    $idc = SafeAudit::save(date('Y-m-d', strtotime($day . ' -1 day')), 'office', '1,500', null);
    near('فواصل الآلاف بتتفك بـ`money_unformat`', (float) SafeAudit::find($idc)['counted'], 1500);

    // ── الحُرّاس: كلهم لازم يرموا
    $guards = [
        'خزنة مزوّرة'        => static fn () => SafeAudit::save($day, 'bank', '100', null),
        'تاريخ في المستقبل'  => static fn () => SafeAudit::save(date('Y-m-d', strtotime('+3 day')), 'office', '100', null),
        'معدود بالسالب'      => static fn () => SafeAudit::save($day, 'office', '-50', null),
        'معدود فاضي'         => static fn () => SafeAudit::save($day, 'office', '', null),
    ];
    foreach ($guards as $label => $fn) {
        try { $fn(); bad("الحارس «{$label}» عدّى"); }
        catch (\Throwable $e) { ok("الحارس «{$label}» بيرفض"); }
    }

    /* ⚠️ الرصيد الدفتري **مش في الفورم** — الكنترولر بيبعت المعدود بس. */
    $ctrl = code_only('app/Controllers/SafeAuditController.php');
    (!str_contains($ctrl, 'book_balance') && str_contains($ctrl, "\$_POST['counted']"))
        ? ok('الفورم بيبعت المعدود بس — الدفتري من الخادم')
        : bad('الفورم بيبعت الرصيد الدفتري — الفرق يبقى قابل للتزوير');

    // ═══════════════════════════════════════════════════════ ⑦
    sec('⑦ رندر فعلي لكل تبويب وكل نافذة وكشف الطباعة');

    $render = function (string $action, array $get = [], array $args = []) {
        $bak = $_GET; $_GET = $get;
        ob_start();
        try { (new \App\Controllers\SafeAuditController())->{$action}(...$args); }
        catch (\Throwable $e) { ob_end_clean(); $_GET = $bak; return '__ERR__' . $e->getMessage(); }
        $html = (string) ob_get_clean(); $_GET = $bak; return $html;
    };
    $errPat = '/Fatal error|Warning:|Undefined variable|Undefined array key|__ERR__/';

    $tabs = \App\Controllers\SafeAuditController::TABS;
    count($tabs) === 3 ? ok('٣ تبويبات معرَّفة') : bad('عدد التبويبات ' . count($tabs));
    $screens = [];
    foreach ($tabs as $t) {
        $h = $render('index', ['tab' => $t]);
        $screens[$t] = $h;
        !preg_match($errPat, $h) && str_contains($h, 'جرد الخزينة')
            ? ok("تبويب «{$t}» بيرندر")
            : bad("تبويب «{$t}»: " . substr(strip_tags($h), 0, 160));
        // التبويب **رابط حقيقي** — التبديل بالجافاسكريبت بيكسر الرجوع والمشاركة.
        str_contains($h, 'href="' . url('safe-audit') . '?tab=' . $t . '"')
            ? ok("رابط تبويب «{$t}» حقيقي") : bad("تبويب «{$t}» مش رابط");
    }
    // قيمة مجهولة من الرابط بترجع للافتراضي (بلا include متغيّر من المستخدم)
    $h = $render('index', ['tab' => '../../etc/passwd']);
    (!preg_match($errPat, $h) && str_contains($h, 'id="safeCount"'))
        ? ok('تبويب مجهول بيرجع للجرد (بلا include من الرابط)')
        : bad('تبويب مجهول عدّى — خطر include من الرابط');

    foreach (['office', 'company', 'reps', 'grand', 'audits'] as $wq) {
        $h = $render('drill', [], [$wq]);
        !preg_match($errPat, $h) ? ok("نافذة «{$wq}» بترندر")
            : bad("نافذة «{$wq}»: " . substr(strip_tags($h), 0, 140));
    }
    foreach (['period_in', 'period_out', 'period_net', 'transfers'] as $wq) {
        $h = $render('drill', ['period' => 'month'], [$wq]);
        !preg_match($errPat, $h) ? ok("نافذة «{$wq}» بترندر")
            : bad("نافذة «{$wq}»: " . substr(strip_tags($h), 0, 140));
    }

    $sheet = $render('statement', ['period' => 'month']);
    (!preg_match($errPat, $sheet) && str_contains($sheet, 'كشف جرد الخزينة'))
        ? ok('كشف الطباعة A4 بيرندر') : bad('كشف الطباعة: ' . substr(strip_tags($sheet), 0, 160));
    /* ⚠️ من غير `@page{margin:0}` كل طبعة بتطلع صفحة تانية شبه فاضية. */
    str_contains($sheet, '@page{size:A4 portrait;margin:0}')
        ? ok('`@page` مظبوطة (مفيش صفحة زيادة)') : bad('`@page{margin:0}` ناقصة في الكشف');
    /* ⚠️ `tfoot` بيتكرّر في آخر كل صفحة بالإجمالي الكامل من غير القاعدة دي. */
    str_contains($sheet, 'tfoot{display:table-row-group}')
        ? ok('`tfoot` مش بيتكرّر على الصفحات') : bad('`tfoot` هيتكرّر بالإجمالي الكامل');

    $pdo->rollBack();
    ok('كل الكتابة اترجعت (rollback) — الفحص ما سابش أثر');
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    bad('استثناء: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

// ═══════════════════════════════════════════════════════════ ⑧
sec('⑧ كل كلاس له شكل وكل أيقونة موجودة');

$css   = src('public/assets/css/app.css');
$views = glob($root . '/app/Views/safe_audit/*.php') ?: [];
$viewSrc = '';
foreach ($views as $v) { $viewSrc .= (string) file_get_contents($v); }
count($views) >= 6 ? ok('ملفات الشاشة موجودة (' . count($views) . ')') : bad('ملفات الشاشة ناقصة');

/* ⚠️ الكلاس اللي مالوش CSS بيقع على الشكل الافتراضي **بالسكوت**
   (`.table-mobile-scroll` عاش شهور معطَّل كده). بنفحص الكلاسات اللي الشاشة
   بتطبعها **برّه** ملف الطباعة (ده بيعرّف كلاساته جوّاه). */
$printOnly = ['sheet','wm','content','head','co','sub','doc-title','totals','tt','op','cl',
              'tsec','hd','num','amt','cum','tag','empty-row','closing','sign','foot','noprint',
              'btn','g','r','l','v','n','t','m','s','pos','neg'];
$screenSrc = '';
foreach ($views as $v) {
    if (basename($v) === 'statement.php') { continue; }
    $screenSrc .= (string) file_get_contents($v);
}
preg_match_all('/class="([^"$<]+)"/', $screenSrc, $mm);
$classes = [];
foreach ($mm[1] as $chunk) {
    foreach (preg_split('/\s+/', trim($chunk)) as $c) {
        if ($c !== '' && !in_array($c, $printOnly, true)) { $classes[$c] = true; }
    }
}
/* ⚠️ **الشرط «CSS أو سلوك JS»** لا CSS بس: `js-dirty-form` و`confirm-delete`
   كلاسات سلوك بيقراها `app.js` ومالهاش (ولا المفروض يبقى ليها) شكل. */
$js = src('public/assets/js/app.js');
$missing = [];
foreach (array_keys($classes) as $c) {
    if (!str_contains($css, '.' . $c) && !str_contains($js, $c)) { $missing[] = $c; }
}
$missing ? bad('كلاسات بلا CSS ولا سلوك JS: ' . implode(', ', $missing))
         : ok('كل كلاس بتطبعه الشاشة له شكل أو سلوك (' . count($classes) . ' كلاس)');

/* ⚠️ `icon('اسم-غلط')` **ما بترميش خطأ — بترسم دايرة فاضية**.
   ⚠️ و`(?<![:\w])` إجبارية: `SafeInventory::icon('office')` **مش** نداءً
      لدالة الأيقونات — هي بتاخد مفتاح خزنة وبترجّع اسم الأيقونة. من غير
      الشرط ده الفحص بيبلّغ عن «أيقونات» أساميها `office`/`company` كذبًا. */
preg_match_all("/(?<![:\\w])icon\('([a-z0-9-]+)'/", $viewSrc . src('app/Models/SafeInventory.php'), $im);
$helpers = src('app/Core/helpers.php');
$badIcons = [];
foreach (array_unique($im[1]) as $name) {
    if (!preg_match("/'" . preg_quote($name, '/') . "'\s*=>\s*'</", $helpers)) { $badIcons[] = $name; }
}
$badIcons ? bad('أيقونات مش في خريطة `icon()`: ' . implode(', ', array_unique($badIcons)))
          : ok('كل أيقونة مستخدمة موجودة في خريطة `icon()` (' . count(array_unique($im[1])) . ')');

/* ⚠️ صفر `<style>` محلي وصفر `style=` جديد في شاشات النظام (بند ٧ من قانون
   التصميم). ملف الطباعة A4 مستثنى — هو مستند مستقل بهويته.
   ⚠️ **بلا تعليقات** — التعليق اللي بيوثّق القاعدة بيذكر `style=` نفسها
      فيدي إنذارًا كاذبًا (نفس مصيدة `code_only` الموثَّقة في CLAUDE.md). */
$inline = 0;
foreach ($views as $v) {
    if (basename($v) === 'statement.php') { continue; }
    $s = code_only('app/Views/safe_audit/' . basename($v));
    $inline += substr_count($s, '<style') + preg_match_all('/\sstyle="/', $s);
}
$inline === 0 ? ok('صفر `<style>` محلي وصفر `style=` في شاشات الوحدة')
              : bad("فيه {$inline} حالة تنسيق محلي في الشاشات");

// ═══════════════════════════════════════════════════════════ ⑨
sec('⑨ المسارات والقائمة');

$routes = src('public/index.php');
$posDrill = strpos($routes, "'/safe-audit/drill/{what}'");
$posStmt  = strpos($routes, "'/safe-audit/statement'");
$posGreedy = strpos($routes, "'/safe-audit/{id}/delete'");
($posDrill !== false && $posStmt !== false && $posGreedy !== false
    && $posDrill < $posGreedy && $posStmt < $posGreedy)
    ? ok('المسارات الحرفية قبل الجشعة (تحذير ٤)')
    : bad('ترتيب المسارات غلط — الجشع هيبلع الحرفي');

$nav = code_only('app/Models/NavModule.php');
str_contains($nav, "'safe-audit'") && str_contains($nav, 'SafeAuditController@index')
    ? ok('الوحدة مسجَّلة في القائمة الجانبية') : bad('الوحدة مش في `NavModule::DEFAULTS`');

/* ⚠️ **الفترة من `CompanySafe::periodRange` — المصدر الوحيد.** منطق فترة تاني
   كان معناه إن «الشهر» يعني حاجة هنا وحاجة تانية في خزنة الشركة. */
str_contains($ctrl ?? code_only('app/Controllers/SafeAuditController.php'), 'CompanySafe::periodRange')
    ? ok('الفترة من `CompanySafe::periodRange` (المصدر الوحيد)')
    : bad('الوحدة بتحسب فتراتها بنفسها');

echo "\n" . str_repeat('=', 64) . "\n";
printf("ناجح: %d   |   فاشل: %d\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
