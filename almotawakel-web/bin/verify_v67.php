<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص خزنة الشركة الموسّعة (v67) — مصروفات · فيزا · عهدة مندوب · مرفقات ·
 * تعديل · ميزانية · كشف حساب.
 *
 * القواعد المحروسة هنا:
 *   ① **العزل بالبناء لسه قايم** — ولا ملف خزنة بيعرف الوحدة، **ولا leg في
 *      `Expense::unionSql()`** (قرار المالك: المصروف معزول عن `/expenses`).
 *   ② **الاتجاهان بيمشوا مع بعض** — كل نوع بيخصم من الشركة بيضيف في محفظته،
 *      والحذف بيعكس الاتنين. **الفلوس محفوظة** في كل الحالات.
 *   ③ **مفيش نوع بلا عكس** — `LINKED_KINDS` = اللي `writeLinked` بيكتبهم =
 *      اللي `removeLinked` بيشيلهم.
 *   ④ **مصدر `company` محصّن** — مش في `MANUAL_SOURCES`، والفورم بيمرّ على
 *      `cleanManualSource`، وحذف التنزيل من كارت المندوب مرفوض.
 *   ⑤ **الحُرّاس** — كارت/مندوب مزوّر · فوق الرصيد · تغيير النوع · قفل اليوم.
 *   ⑥ **الأرقام متسقة** — التفكيك = الرصيد، وكشف الحساب: افتتاحي + صافي = ختامي.
 *   ⑦ **المسارات الحرفية قبل الجشعة** (تحذير ٤) + صلاحية المرفق.
 *   ⑧ **رندر فعلي** للشاشة وكل النوافذ وكشف الطباعة.
 *   ⑨ **كل كلاس مطبوع له شكل** (درس `.table-mobile-scroll`) + الأيقونات موجودة.
 *
 * كله جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_v67.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\PrivateFile;
use App\Models\Closing;
use App\Models\CompanySafe;
use App\Models\Custody;
use App\Models\RepCustody;
use App\Models\VisaCard;

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function sec(string $m): void { echo "\n" . $m . "\n" . str_repeat('─', 62) . "\n"; }
function note(string $m): void { echo "  • $m\n"; }
function near(string $l, $a, $e): void {
    abs((float) $a - (float) $e) <= 0.005 ? ok($l)
        : bad("{$l} — متوقع " . number_format((float) $e, 2) . " / فعلي " . number_format((float) $a, 2));
}
function src(string $file): string {
    $p = dirname(__DIR__) . '/' . $file;
    return is_file($p) ? (string) file_get_contents($p) : '';
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
function has(string $l, string $file, string $needle): void {
    str_contains(src($file), $needle) ? ok($l) : bad("{$l} — «{$needle}» مش موجودة في {$file}");
}
/** جسم دالة واحدة — ⚠️ القصّ بعدد حروف ثابت بيبلع الدالة اللي بعدها (مصيدة موثَّقة). */
function fn_body(string $file, string $name): string {
    $s = code_only($file);
    $i = strpos($s, 'function ' . $name . '(');
    if ($i === false) { return ''; }
    $j = strpos($s, '{', $i);
    if ($j === false) { return ''; }
    $d = 0; $n = strlen($s);
    for ($k = $j; $k < $n; $k++) {
        if ($s[$k] === '{') { $d++; }
        elseif ($s[$k] === '}') { $d--; if ($d === 0) { return substr($s, $j, $k - $j + 1); } }
    }
    return substr($s, $j);
}

echo "فحص خزنة الشركة الموسّعة (v67)\n" . str_repeat('=', 62) . "\n";

if (!CompanySafe::ready()) {
    echo "\n⚠️ جدول company_safe_entries مش موجود — شغّل php bin/migrate_v66.php الأول.\n";
    exit(1);
}
$colsNow = array_column(Database::all('SHOW COLUMNS FROM company_safe_entries'), 'Field');
if (!in_array('category', $colsNow, true)) {
    echo "\n⚠️ أعمدة v67 مش موجودة — شغّل php bin/migrate_v67.php الأول.\n";
    exit(1);
}

// ═════════════════════════════════════════════ ① العزل بالبناء
sec('① العزل بالبناء — الوحدة لسه مجهولة لكل كود الخزنة');

/* ⚠️⚠️ أهم فحص في الوحدة. ظهور الجدول في أي ملف خزنة معناه إن العزل اتكسر
   وبقى فيه نقطة مزامنة جديدة لازم تتفكر في ٨ أماكن — وهو بالظبط اللي الوحدة
   اتصممت عشان تتجنّبه (نفس حارس v39/v45/v54/v62). */
$safeFiles = [
    'app/Models/Closing.php', 'app/Models/Custody.php', 'app/Models/DailyReview.php',
    'app/Models/Expense.php', 'app/Models/Metrics.php', 'app/Models/RepCustody.php',
    'app/Controllers/DailyClosingController.php', 'bin/backfill_closings.php',
    'app/Models/Calc.php',
];
$leak = [];
foreach ($safeFiles as $f) {
    $s = code_only($f);
    if ($s === '') { continue; }
    if (str_contains($s, 'company_safe_entries')) { $leak[] = $f . ' (الجدول)'; }
    /* `Closing::walletSplit` استثناء **مقصود ومحدود** (موثَّق من v66): بتقرا
       الرصيد للعرض بس، والفحص تحته بيتأكد إنها مرة واحدة وإن معنى `total`
       ما اتغيّرش. */
    if ($f !== 'app/Models/Closing.php' && str_contains($s, 'CompanySafe')) {
        $leak[] = $f . ' (الكلاس)';
    }
}
$leak ? bad('ملفات خزنة بتعرف الوحدة: ' . implode('، ', $leak))
      : ok('ولا ملف خزنة بيذكر الوحدة (' . count($safeFiles) . ' ملف، ماعدا walletSplit للعرض)');
substr_count(code_only('app/Models/Closing.php'), 'CompanySafe') === 1
    ? ok('`Closing` بتذكر CompanySafe مرة واحدة بس (walletSplit — عرض)')
    : bad('`Closing` بتذكر CompanySafe أكتر من مرة — العزل اتكسر');

/* ⚠️ الأعمدة الرابطة **مقصورة على قايمة معروفة**: كل واحد فيهم بيربط الصف
   بالصف المُولَّد في محفظة تانية عشان الحذف يشيل الاتنين. أي عمود تاني بيربط
   الوحدة بحساب قائم (إذن · بوليصة · بند · إقفال) معناه إن العزل اتكسر. */
$linkCols = ['custody_id', 'visa_card_id', 'visa_deposit_id', 'rep_id', 'rep_deposit_id'];
$badCols  = array_values(array_filter($colsNow, static fn ($c) =>
    preg_match('/(permit|bawali|bill|stage|closing|visa|rep_|custody)/i', (string) $c)
    && !in_array($c, $linkCols, true)));
$badCols ? bad('عمود بيربط الوحدة بحساب قائم: ' . implode('، ', $badCols))
         : ok('الأعمدة الرابطة هي المعروفة بس (' . implode('، ', $linkCols) . ')');

/* ⚠️ قرار المالك: المصروف **معزول عن `/expenses`** — مفيش leg في الاتحاد ولا
   مصدر في `SOURCES`. لو اتضاف بعدين، ده قرار مالك جديد مش تحسين تقني. */
$expSrc = code_only('app/Models/Expense.php');
(!str_contains($expSrc, 'company_safe') && !isset(\App\Models\Expense::SOURCES['company_safe']))
    ? ok('مصروف خزنة الشركة مش في سجل المصروفات العام (قرار المالك)')
    : bad('leg/مصدر company_safe ظهر في Expense — ده بيغيّر أرقام الداشبورد والتقارير');

// `walletSplit['total']` لازم يفضل الدرج + المناديب بس (فحص `verify_safe_rule` بيعتمد عليه).
$ws = Closing::walletSplit();
near('walletSplit.total = الدرج + المناديب (خزنة الشركة برّه)',
     $ws['total'], round($ws['drawer'] + $ws['reps_total'], 2));
near('walletSplit.grand = total + company', $ws['grand'], round($ws['total'] + $ws['company'], 2));

// ═════════════════════════════════════════════ ② مفيش نوع بلا عكس
sec('② كل نوع بيكتب صفًّا في محفظة تانية له عكس');

$wl = fn_body('app/Models/CompanySafe.php', 'writeLinked');
$rl = fn_body('app/Models/CompanySafe.php', 'removeLinked');
foreach (['custody_id' => 'Custody::deleteTopup',
          'visa_deposit_id' => 'VisaCard::deleteDeposit',
          'rep_deposit_id' => 'RepCustody::deleteDeposit'] as $key => $undo) {
    str_contains($rl, $undo)
        ? ok("removeLinked بتعكس {$key} عبر {$undo}")
        : bad("removeLinked ما بتعكسش {$key} — الفلوس هتتكرّر عند الحذف");
}
foreach (['Custody::createTopup', 'VisaCard::createDeposit', 'RepCustody::createDeposit'] as $w) {
    str_contains($wl, $w) ? ok("writeLinked بتنادي {$w}") : bad("writeLinked ما بتنادّيش {$w}");
}
// ⚠️ القايمة الموثَّقة لازم تطابق الواقع — نوع جديد بيتضاف لواحدة وينسى في التانية.
$linked = CompanySafe::LINKED_KINDS;
$expectLinked = ['to_office', 'from_office', 'to_visa', 'from_visa', 'to_rep'];
(array_values($linked) === $expectLinked)
    ? ok('LINKED_KINDS مطابقة لأنواع الربط الفعلية')
    : bad('LINKED_KINDS اختلفت: ' . implode(',', $linked));

// كل نوع في MANUAL_KINDS لازم يكون في KINDS وله تسمية وأيقونة.
$missLbl = $missIco = [];
foreach (CompanySafe::KINDS as $k) {
    if (!isset(CompanySafe::labels()[$k])) { $missLbl[] = $k; }
    if (icon(CompanySafe::icon($k)) === icon('__none__')) { $missIco[] = $k; }
}
$missLbl ? bad('أنواع بلا تسمية: ' . implode(',', $missLbl)) : ok('كل نوع له تسمية');
/* ⚠️ الأيقونة الناقصة بترسم **دايرة فاضية بالسكوت** — مصيدة موثَّقة اتصادت
   أكتر من مرة (`box`/`unlock`/`home`). */
$missIco ? bad('أنواع بأيقونة مش في icon(): ' . implode(',', $missIco)) : ok('كل أيقونة نوع موجودة في icon()');

// ═════════════════════════════════════════════ ③ مصدر company محصّن
sec('③ مصدر تنزيل العهدة `company` محصّن');

in_array('company', RepCustody::MANUAL_SOURCES, true)
    ? bad('`company` في MANUAL_SOURCES — الفورم هيسمح بتنزيل عهدة بلا خصم من خزنة الشركة')
    : ok('`company` مستبعد من MANUAL_SOURCES');
(RepCustody::cleanManualSource('company') === RepCustody::SOURCE_DEFAULT)
    ? ok('cleanManualSource بترفض company وبترجّع الافتراضي')
    : bad('cleanManualSource بتقبل company — فلوس بتتولّد من العدم');
(RepCustody::cleanSource('company') === 'company')
    ? ok('cleanSource (للقراءة من القاعدة) بتقبل company')
    : bad('cleanSource بترفض company — الصفوف المحفوظة هتتقري غلط');
!isset(RepCustody::manualSources()['company'])
    ? ok('manualSources() بلا company')
    : bad('manualSources() فيها company');

// الفورم لازم يقرا manualSources لا SOURCES
$depForm = code_only('app/Views/rep_custody/_tab_day.php');
(str_contains($depForm, 'manualSources()') && !preg_match('/RepCustody::SOURCES\s+as/', $depForm))
    ? ok('فورم تنزيل العهدة بيقرا manualSources() مش SOURCES')
    : bad('فورم تنزيل العهدة لسه بيعرض كل SOURCES');
str_contains(code_only('app/Controllers/RepCustodyController.php'), 'cleanManualSource')
    ? ok('depositStore بيمرّ على cleanManualSource')
    : bad('depositStore لسه بيستخدم cleanSource — الفورم هيقبل company');
// ⚠️ حذف التنزيل من كارت المندوب لازم يترفض وإلا صف خزنة الشركة يبقى يتيم.
str_contains(fn_body('app/Controllers/RepCustodyController.php', 'deleteDeposit'), "'company'")
    ? ok('حذف تنزيل مصدره company مرفوض من كارت المندوب')
    : bad('حذف تنزيل company مسموح — هيسيب صف خزنة الشركة يتيم والفلوس تتبخّر');

// حساب `company` لازم يطابق `bank` بالحرف في الاتجاهين.
(RepCustody::depositAffectsSafe('company') === RepCustody::depositAffectsSafe('bank'))
    ? ok('depositAffectsSafe(company) = depositAffectsSafe(bank)')
    : bad('حساب company بيخالف bank — الفلوس هتتبخّر في نموذج من الاتنين');
$btSql = RepCustody::bankTopupSql();
($btSql === '' || str_contains($btSql, "'company'"))
    ? ok('bankTopupSql شاملة company (أو القاعدة مطفية)')
    : bad('bankTopupSql مش شاملة company — عهدة من خزنة الشركة هتتبخّر في النموذج المجمّع');
(RepCustody::sourceBadge('company')['short'] ?? '') !== ''
    ? ok('sourceBadge بتعرف company')
    : bad('sourceBadge مش بتعرف company — الشاشة هترمي notice');

// ═════════════════════════════════════════════ ④ السلوك الفعلي (fixtures)
sec('④ السلوك الفعلي — الفلوس محفوظة والحذف بيعكس');

$pdo = Database::conn();
$pdo->beginTransaction();
try {
    $day     = date('Y-m-d');
    $safe0   = round(Custody::balance(), 2);
    $co0     = round(CompanySafe::balance(), 2);
    $card    = VisaCard::all(true)[0] ?? null;
    $reps    = RepCustody::activeReps();
    $repId   = (int) (array_key_first($reps) ?? 0);
    $rep0    = $repId ? round(RepCustody::balance($repId), 2) : 0.0;
    $cardId  = $card ? (int) $card['id'] : 0;
    $card0   = $cardId ? round(VisaCard::balanceOf($cardId), 2) : 0.0;

    CompanySafe::create(['entry_date' => $day, 'kind' => 'deposit', 'amount' => 500000,
                         'source' => 'فحص v67']);
    near('الإيداع بيزوّد رصيد الشركة', CompanySafe::balance(), $co0 + 500000);
    near('الإيداع ما بيمسّش خزنة المكتب', Custody::balance(), $safe0);

    // ── مصروف
    $eId = CompanySafe::create(['entry_date' => $day, 'kind' => 'expense', 'amount' => 3000,
                                'category' => 'rent', 'source' => 'مالك العقار']);
    near('المصروف بينقّص رصيد الشركة', CompanySafe::balance(), $co0 + 497000);
    near('المصروف ما بيمسّش خزنة المكتب', Custody::balance(), $safe0);
    (CompanySafe::find($eId)['category'] === 'rent') ? ok('فئة المصروف اتخزنت')
        : bad('فئة المصروف ما اتخزنتش');
    /* ⚠️ فئة على غير مصروف = بيان مضلِّل بيخلّي تقرير «راح فين» يعدّ حركات. */
    $dId = CompanySafe::create(['entry_date' => $day, 'kind' => 'deposit', 'amount' => 10,
                                'category' => 'rent']);
    (CompanySafe::find($dId)['category'] === null) ? ok('الفئة بتتجاهل على غير المصروف')
        : bad('الفئة اتخزنت على نوع مش مصروف');
    CompanySafe::delete($dId);

    // ── فيزا
    if ($cardId) {
        $v1 = CompanySafe::create(['entry_date' => $day, 'kind' => 'to_visa', 'amount' => 7000,
                                   'visa_card_id' => $cardId]);
        near('شحن الفيزا بينقّص الشركة', CompanySafe::balance(), $co0 + 490000);
        near('شحن الفيزا بيزوّد الكارت', VisaCard::balanceOf($cardId), $card0 + 7000);
        near('شحن الفيزا ما بيمسّش خزنة المكتب', Custody::balance(), $safe0);
        $v2 = CompanySafe::create(['entry_date' => $day, 'kind' => 'from_visa', 'amount' => 2000,
                                   'visa_card_id' => $cardId]);
        near('السحب من الفيزا بيزوّد الشركة', CompanySafe::balance(), $co0 + 492000);
        near('السحب من الفيزا بينقّص الكارت', VisaCard::balanceOf($cardId), $card0 + 5000);
        CompanySafe::delete($v1); CompanySafe::delete($v2);
        near('حذف حركات الفيزا بيرجّع رصيد الكارت', VisaCard::balanceOf($cardId), $card0);
        near('حذف حركات الفيزا بيرجّع رصيد الشركة', CompanySafe::balance(), $co0 + 497000);
    } else { note('مفيش كارت فيزا نشط — قسم الفيزا اتخطّى'); }

    // ── عهدة مندوب
    if ($repId) {
        /* ⚠️⚠️ **الثابت هنا مستقل عن النموذج — ولا تكتبه على `Custody::balance()`.**
           `to_rep` بيحرّك الفلوس من خزنة الشركة لمحفظة المندوب، والدرج **ما
           بيتمسّش أبدًا**. لكن معنى `Custody::balance()` بيتغيّر مع النموذج
           (v61): في «الخزنة = كل كاش الشركة» بترجّع الدرج + المناديب فبتزيد
           بالمبلغ (وده **صح**: الفلوس دخلت الإجمالي من برّه)، وفي «الدرج بس»
           بتفضل ثابتة. الفحص القديم كان مكتوب على النموذج التاني فكان بيفشل
           على الإنتاج بإنذار كاذب. الثابت الصح: **الدرج ثابت وإجمالي الكاش
           محفوظ**. */
        $w0 = Closing::walletSplit();
        $r1 = CompanySafe::create(['entry_date' => $day, 'kind' => 'to_rep', 'amount' => 4000,
                                   'rep_id' => $repId]);
        $w1 = Closing::walletSplit();
        near('عهدة المندوب بتنقّص الشركة', CompanySafe::balance(), $co0 + 493000);
        near('عهدة المندوب بتزوّد محفظته', RepCustody::balance($repId), $rep0 + 4000);
        near('عهدة المندوب ما بتمسّش درج المكتب', $w1['drawer'], $w0['drawer']);
        near('عهدة المندوب: إجمالي الكاش محفوظ', $w1['grand'], $w0['grand']);
        $dep = Database::row('SELECT source FROM rep_custody_deposits WHERE id=?',
                             [CompanySafe::find($r1)['rep_deposit_id']]);
        (($dep['source'] ?? '') === 'company') ? ok('التنزيل المُولَّد مصدره company')
            : bad('التنزيل المُولَّد مصدره ' . ($dep['source'] ?? 'NULL'));
        CompanySafe::delete($r1);
        near('حذف عهدة المندوب بيرجّع محفظته', RepCustody::balance($repId), $rep0);
    } else { note('مفيش مندوب نشط — قسم العهدة اتخطّى'); }

    // ── تحويل للمكتب (النوع الوحيد اللي بيمسّ الدرج)
    $orphanBefore = (int) Database::scalar(
        "SELECT COUNT(*) FROM manual_custody WHERE note LIKE '%خزنة الشركة%'");
    $t1 = CompanySafe::create(['entry_date' => $day, 'kind' => 'to_office', 'amount' => 6000]);
    near('التحويل للمكتب بيزوّد الدرج', Custody::balance(), $safe0 + 6000);
    CompanySafe::delete($t1);
    near('حذف التحويل بيرجّع الدرج', Custody::balance(), $safe0);
    /* ⚠️ **على الفرق لا على عدّاد مطلق**: الإنتاج فيه تحويلات حقيقية من خزنة
       الشركة بنفس الوسم، فالعدّاد المطلق كان بيفشل على بيانات صحيحة تمامًا
       (نفس درس «افحص السلوك مش رقم fixture»). */
    ($orphanAfter = (int) Database::scalar(
        "SELECT COUNT(*) FROM manual_custody WHERE note LIKE '%خزنة الشركة%'")) === $orphanBefore
        ? ok('مفيش صف manual_custody يتيم بعد الحذف')
        : bad("فضل {$orphanAfter} صف manual_custody مقابل {$orphanBefore} قبل الاختبار — فلوس مالهاش مصدر");

    // ── التعديل
    CompanySafe::update($eId, ['kind' => 'expense', 'entry_date' => $day, 'amount' => 3500,
                               'category' => 'utilities', 'source' => 'شركة الكهربا']);
    $r = CompanySafe::find($eId);
    (abs((float) $r['amount'] - 3500) < 0.005 && $r['category'] === 'utilities')
        ? ok('التعديل غيّر المبلغ والفئة') : bad('التعديل ما اشتغلش');
    near('التعديل بينعكس في الرصيد', CompanySafe::balance(), $co0 + 496500);

    // ═══ الحُرّاس
    sec('⑤ الحُرّاس — الفورم مش مصدر ثقة');
    $reject = function (string $label, callable $fn, string $needle) {
        try { $fn(); bad("{$label} — عدّى وهو المفروض يترفض"); }
        catch (\Throwable $e) {
            str_contains($e->getMessage(), $needle) ? ok($label)
                : bad("{$label} — اترفض بسبب تاني: " . $e->getMessage());
        }
    };
    $reject('تغيير نوع الحركة مرفوض',
        fn () => CompanySafe::update($eId, ['kind' => 'deposit', 'amount' => 100]),
        'ما ينفعش تغيّر نوع');
    $reject('السحب فوق رصيد الشركة مرفوض',
        fn () => CompanySafe::create(['entry_date' => $day, 'kind' => 'expense', 'amount' => 9e8]),
        'أكبر من رصيد');
    $reject('كارت فيزا مزوّر مرفوض',
        fn () => CompanySafe::create(['entry_date' => $day, 'kind' => 'to_visa', 'amount' => 10,
                                      'visa_card_id' => 999999]),
        'كارت الفيزا');
    $reject('مندوب مزوّر مرفوض',
        fn () => CompanySafe::create(['entry_date' => $day, 'kind' => 'to_rep', 'amount' => 10,
                                      'rep_id' => 999999]),
        'المندوب');
    $reject('نوع مجهول مرفوض',
        fn () => CompanySafe::create(['entry_date' => $day, 'kind' => 'hack', 'amount' => 10]),
        'نوع حركة غير معروف');
    $reject('مبلغ صفر مرفوض',
        fn () => CompanySafe::create(['entry_date' => $day, 'kind' => 'expense', 'amount' => 0]),
        'أكبر من صفر');

    /* ⚠️ القفل على التحويلات بس — الباقي ما بيمسّش رصيدًا مثبَّتًا. */
    $locked = Closing::lastClosedDate();
    if ($locked) {
        $reject('التحويل على يوم متقفل مرفوض',
            fn () => CompanySafe::create(['entry_date' => $locked, 'kind' => 'to_office', 'amount' => 10]),
            'متقفل');
        try {
            $x = CompanySafe::create(['entry_date' => $locked, 'kind' => 'expense', 'amount' => 10]);
            ok('المصروف على يوم متقفل مسموح (ما بيمسّش الدرج)');
            CompanySafe::delete($x);
        } catch (\Throwable $e) { bad('المصروف اترفض على يوم متقفل: ' . $e->getMessage()); }
    } else { note('مفيش يوم متقفل — فحص القفل اتخطّى'); }

    /* ⚠️⚠️ **حارس باج اتصاد على الإنتاج**: `ledger()` كانت `ORDER BY ... ASC
       LIMIT n` فبترجّع **أقدم** n لا أحدث n — «آخر الحركات» كانت بتعرض أول
       حركات النظام. محليًّا كان فيه صفوف قليلة فالفرق ما بانش. */
    for ($i = 6; $i >= 0; $i--) {
        CompanySafe::create(['entry_date' => date('Y-m-d', strtotime("-{$i} day")),
                             'kind' => 'deposit', 'amount' => 100 + $i, 'source' => "حارس-{$i}"]);
    }
    $recent = CompanySafe::ledger([], 3);
    (count($recent) === 3 && (string) $recent[0]['source'] === 'حارس-0')
        ? ok('`ledger()` بترجّع **الأحدث** أولاً (مش الأقدم)')
        : bad('`ledger()` بترجّع الأقدم — «آخر الحركات» بتعرض أول حركات النظام');
    near('الرصيد الجاري لأحدث صف = الرصيد الكلي',
         (float) $recent[0]['running'], CompanySafe::balance());

    /* ⚠️⚠️ **أهم حارس في الشاشة (طلب المالك: «أبعد عن أي بيانات مضللة»)**:
       رقم كل كارت لازم يساوي **مجموع بنود نافذته بالمليم**، والنافذة ما
       تعرضش ولا صف برّه نطاقها. كارت بيقول رقمًا ونافذته بتقول غيره هو
       بالظبط الشكوى اللي الشاشة اتعادت عشانها. */
    $dt   = CompanySafe::dayTotals($day);
    $sumOf = static fn (array $f) => round(array_sum(array_map(
        static fn ($r) => (float) $r['amount'], CompanySafe::ledger($f, 500))), 2);
    near('كارت «دخل النهارده» = مجموع نافذته',
         $dt['in'],        $sumOf(['dir' => 'in', 'from' => $day, 'to' => $day]));
    near('كارت «نزل للمكتب» = مجموع نافذته',
         $dt['to_office'], $sumOf(['kind' => 'to_office', 'from' => $day, 'to' => $day]));
    near('كارت «مصروفات النهارده» = مجموع نافذته',
         $dt['expense'],   $sumOf(['kind' => 'expense', 'from' => $day, 'to' => $day]));
    $w2 = Closing::walletSplit();
    near('كارت «عهدة المكتب» = الدرج + المناديب', $w2['total'],
         round($w2['drawer'] + $w2['reps_total'], 2));

    // ولا صف برّه نطاق الكارت — لا يوم تاني ولا اتجاه تاني.
    $dayInRows = CompanySafe::ledger(['dir' => 'in', 'from' => $day, 'to' => $day], 500);
    $stray = array_filter($dayInRows, static fn ($r) =>
        (string) $r['entry_date'] !== $day || !$r['is_in']);
    $stray ? bad('نافذة «دخل النهارده» فيها ' . count($stray) . ' صف برّه نطاقها')
           : ok('نافذة «دخل النهارده» يومها واتجاهها بس');
    $dayExpRows = CompanySafe::ledger(['kind' => 'expense', 'from' => $day, 'to' => $day], 500);
    $stray2 = array_filter($dayExpRows, static fn ($r) =>
        (string) $r['entry_date'] !== $day || (string) $r['kind'] !== 'expense');
    $stray2 ? bad('نافذة «مصروفات النهارده» فيها صف برّه نطاقها')
            : ok('نافذة «مصروفات النهارده» يومها ونوعها بس');

    /* ⚠️ اسم كارت الفيزا/المندوب لازم يبان (طلب المالك: «لو من فيزا يتم كتابة
       أي فيزا») — «سحب كاش من كارت فيزا» بلا اسم مابيجاوبش على السؤال. */
    if ($cardId) {
        // ⚠️ بنزرع صفًّا هنا: صفوف الفيزا اللي فوق اتمسحت في اختبار الحذف.
        $vId = CompanySafe::create(['entry_date' => $day, 'kind' => 'from_visa',
                                    'amount' => 900, 'visa_card_id' => $cardId]);
        $vRows = array_values(array_filter(
            CompanySafe::ledger(['kind' => 'from_visa'], 50),
            static fn ($r) => (int) $r['id'] === $vId));
        ($vRows && ($vRows[0]['wallet'] ?? '') !== '')
            ? ok('اسم كارت الفيزا بيظهر على بند الفيزا (' . ($vRows[0]['wallet'] ?? '') . ')')
            : bad('بند الفيزا بلا اسم كارت');
        /* ⚠️ **وفي كشف الطباعة كمان** — الاسم كان بيتحلّ في `ledger()` بس،
           فورقة A4 كانت بتقول «سحب كاش من كارت فيزا» بلا ما تقول أنهي كارت.
           الفحص على **الصف نفسه** لا على نص الورقة (النص بيتغيّر مشروعًا). */
        $sRows = array_values(array_filter(
            CompanySafe::statement($day, $day)['rows'],
            static fn ($r) => (int) $r['id'] === $vId));
        ($sRows && ($sRows[0]['wallet'] ?? '') !== '')
            ? ok('اسم الكارت بيظهر في كشف الطباعة كمان')
            : bad('كشف الطباعة بيعرض بند فيزا بلا اسم كارت');
        CompanySafe::delete($vId);
    }

    // ⚠️ الفترات: كل مفتاح بيدّي مدى صحيح، والمجهول بيرجع لليوم.
    foreach (['day' => [$day, $day],
              'month' => [date('Y-m-01', strtotime($day)), date('Y-m-t', strtotime($day))],
              'year'  => [date('Y-01-01', strtotime($day)), date('Y-12-31', strtotime($day))]] as $k => [$a, $b]) {
        $p = CompanySafe::periodRange($k, $day);
        ($p['from'] === $a && $p['to'] === $b) ? ok("فترة «{$k}» مداها صح")
            : bad("فترة «{$k}»: {$p['from']} → {$p['to']}");
    }
    (CompanySafe::periodRange('__hack__', $day)['key'] === 'day')
        ? ok('فترة مجهولة بترجع لليوم (الافتراضي اللي طلبه المالك)')
        : bad('فترة مجهولة مش بترجع لليوم');
    // ⚠️ الترتيب المقلوب بيتصلّح بدل ما يرجّع فترة فاضية بلا سبب ظاهر.
    $rev = CompanySafe::periodRange('custom', $day, '2026-08-09', '2026-08-01');
    ($rev['from'] === '2026-08-01' && $rev['to'] === '2026-08-09')
        ? ok('فترة مخصصة مقلوبة بتتصلّح') : bad('الفترة المقلوبة ما اتصلّحتش');

    // ═══ الأرقام متسقة
    sec('⑥ الأرقام متسقة — التفكيك والكشف');
    $bd = CompanySafe::breakdown();
    near('التفكيك = الرصيد بالمليم', $bd['balance'], CompanySafe::balance());
    $stm = CompanySafe::statement(date('Y-m-01'), date('Y-m-t'));
    near('الكشف: افتتاحي + داخل − خارج = ختامي',
         $stm['opening'] + $stm['in'] - $stm['out'], $stm['closing']);
    /* ⚠️ كروت الكشف الأربعة (قرار المالك): **الإجمالي** = أول المدة + الداخل،
       و«خارج» بتتقسم لـ`to_office` و`expense` والباقي. لازم يساووا التفكيك
       بالمليم — لو اتحسبوا في الـview بشرط مكتوب بالإيد، الكارت يقول رقمًا
       والتفكيك تحته يقول تاني. */
    near('كارت «الإجمالي» = أول المدة + الداخل',
         $stm['gross'], $stm['opening'] + $stm['in']);
    near('كارت «اتسلّم للمكتب» = to_office في التفكيك',
         $stm['to_office'], $stm['by_kind']['to_office']['out'] ?? 0.0);
    near('كارت «مصروفات الخزنة» = expense في التفكيك',
         $stm['expense'], $stm['by_kind']['expense']['out'] ?? 0.0);
    /* ⚠️⚠️ **الكروت الأربعة بتقفل حسابيًّا** — الإجمالي ناقص التلاتة = الختامي.
       دي الحارس الحقيقي: أي نوع خارج جديد يتضاف بلا عرض بيقع في `other_out`
       فالطرح يفضل قافل، ولو حد شال `other_out` من الورقة الفحص ده بيفشل. */
    near('الإجمالي − المكتب − المصروفات − الباقي = الختامي',
         $stm['gross'] - $stm['to_office'] - $stm['expense'] - $stm['other_out'],
         $stm['closing']);
    ($stm['other_out'] >= -0.005)
        ? ok('باقي الخارج مش سالب (ولا نوع اتعدّ مرتين)')
        : bad('other_out سالب — نوع اتحسب في كارت وفي الباقي');
    near('ختامي الكشف = رصيد الشركة (الشهر الحالي)', $stm['closing'], CompanySafe::balance());
    // مجموع الفئات = مصروفات الشهر في `budgetProgress` (مصدرين لنفس الرقم).
    $bp   = CompanySafe::budgetProgress($day);
    $cats = CompanySafe::byCategory(date('Y-m-01'), date('Y-m-t'));
    near('مجموع الفئات = مصروفات الشهر', array_sum(array_column($cats, 'total')), $bp['spent']);
    $parties = CompanySafe::byParty(date('Y-m-01'), date('Y-m-t'));
    near('مجموع الجهات = مصروفات الشهر', array_sum(array_column($parties, 'total')), $bp['spent']);

    // ═══ رندر فعلي
    sec('⑧ رندر فعلي للشاشة والنوافذ وكشف الطباعة');
    $_SESSION['uid'] = $_SESSION['uid'] ?? 1;
    $render = function (string $action, array $get = []) {
        $bak = $_GET; $_GET = $get;
        ob_start();
        try { (new \App\Controllers\CompanySafeController())->{$action}(...array_values($get['__args'] ?? [])); }
        catch (\Throwable $e) { ob_end_clean(); $_GET = $bak; return '__ERR__' . $e->getMessage(); }
        $html = (string) ob_get_clean(); $_GET = $bak; return $html;
    };
    $errPat = '/Fatal error|Warning:|Undefined variable|Undefined array key/';

    /* ⚠️ **كل تبويب يترندر لوحده** — التبويبات بتحمّل بيانات مختلفة (`match`
       في الكنترولر) فمتغيّر ناقص في واحد منهم ما يظهرش في اختبار الافتراضي. */
    $tabs = \App\Controllers\CompanySafeController::TABS;
    (count($tabs) === 4) ? ok('٤ تبويبات معرَّفة') : bad('عدد التبويبات ' . count($tabs));
    $home = '';
    foreach ($tabs as $t) {
        $h = $render('index', ['tab' => $t]);
        $good = !str_starts_with($h, '__ERR__') && !preg_match($errPat, $h) && str_contains($h, 'cs-drawer');
        $good ? ok("تبويب «{$t}» بيرندر") : bad("تبويب «{$t}»: " . substr(strip_tags($h), 0, 150));
        if ($t === 'overview') { $home = $h; }
        // التبويب لازم يكون **رابط حقيقي** — التبديل بالجافاسكريبت بيكسر الرجوع والمشاركة.
        str_contains($h, 'href="' . url('company-safe') . '?tab=' . $t . '"')
            ? ok("رابط تبويب «{$t}» حقيقي") : bad("تبويب «{$t}» مش رابط");
    }
    // قيمة `tab` مجهولة من الرابط بترجع للافتراضي بدل ما تحاول تحمّل ملف مش موجود.
    $h = $render('index', ['tab' => '../../etc/passwd']);
    (!str_starts_with($h, '__ERR__') && str_contains($h, 'cs-card hero'))
        ? ok('تبويب مجهول بيرجع للنظرة العامة (بلا include متغيّر)')
        : bad('تبويب مجهول عدّى — خطر include من الرابط');

    /* الدرج: **مقفول افتراضيًّا ومفتوح بـ`?new=1`** — الحالة في الرابط. */
    (str_contains($home, 'id="csDrawer"') && preg_match('/id="csDrawer"[^>]*\shidden/', $home))
        ? ok('الدرج مقفول افتراضيًّا') : bad('الدرج مفتوح بلا طلب');
    $open = $render('index', ['tab' => 'overview', 'new' => '1']);
    (str_contains($open, 'cs-drawer open') && !preg_match('/id="csDrawer"[^>]*\shidden/', $open))
        ? ok('`?new=1` بيفتح الدرج من الخادم (شغّال بلا جافاسكريبت)')
        : bad('`?new=1` ما بيفتحش الدرج — الإدخال بيعتمد على الجافاسكريبت');
    str_contains($open, 'name="receipt"') ? ok('خانة إيصال المرفق موجودة') : bad('خانة الإيصال ناقصة');
    str_contains($open, 'data-cs-for="expense"') ? ok('خانة فئة المصروف المشروطة موجودة')
        : bad('خانة فئة المصروف ناقصة');
    str_contains($open, 'data-cs-preview') ? ok('معاينة أثر الحركة موجودة') : bad('المعاينة ناقصة');
    substr_count($open, 'class="cs-type in"') + substr_count($open, 'class="cs-type out"')
        === count(CompanySafe::MANUAL_KINDS)
        ? ok('كل الأنواع اليدوية ككروت (' . count(CompanySafe::MANUAL_KINDS) . ')')
        : bad('عدد كروت النوع مش ' . count(CompanySafe::MANUAL_KINDS));
    // الميزانية عاشت في تبويب المصروفات بعد إعادة التنظيم.
    str_contains($render('index', ['tab' => 'expenses']), 'cs-budget-set')
        ? ok('ضبط سقف الميزانية في تبويب المصروفات') : bad('ضبط الميزانية ناقص');

    /* ⚠️ **الدرج مقسوم لداخل وخارج** (طلب المالك: «افصل بين الصرف والإيداع») —
       والقسمة من `isIn()` فأي نوع جديد بيقع في قسمه تلقائيًّا. */
    (substr_count($open, 'class="cs-types in"') === 1 && substr_count($open, 'class="cs-types out"') === 1)
        ? ok('كروت النوع مقسومة لقسمين: داخل وخارج') : bad('أقسام الدرج مش اتنين');
    $inCount  = count(array_filter(CompanySafe::MANUAL_KINDS, [CompanySafe::class, 'isIn']));
    $outCount = count(CompanySafe::MANUAL_KINDS) - $inCount;
    (substr_count($open, 'class="cs-type in"') === $inCount
        && substr_count($open, 'class="cs-type out"') === $outCount)
        ? ok("قسمة الكروت مطابقة لـ`isIn()` ({$inCount} داخل · {$outCount} خارج)")
        : bad('قسمة كروت النوع مش مطابقة لـ`isIn()`');

    /* ⚠️ **التقارير بتفتح على اليوم** — الشهر كان بيخالف كروت النظرة العامة. */
    $rep = $render('index', ['tab' => 'reports']);
    (str_contains($rep, 'cs-period') && str_contains($rep, 'داخل اليوم'))
        ? ok('التقارير بتفتح على اليوم ومعاها شريط الفترة')
        : bad('التقارير مش بتفتح على اليوم');
    foreach (['month', 'prev_month', 'year'] as $pk) {
        $h2 = $render('index', ['tab' => 'reports', 'period' => $pk]);
        (!str_starts_with($h2, '__ERR__') && !preg_match($errPat, $h2))
            ? ok("تبويب التقارير بفترة «{$pk}» بيرندر") : bad("التقارير بفترة «{$pk}» وقعت");
    }
    $h2 = $render('index', ['tab' => 'reports', 'period' => 'custom',
                            'from' => date('Y-m-01'), 'to' => date('Y-m-d')]);
    (!str_starts_with($h2, '__ERR__') && str_contains($h2, 'cs-period-custom'))
        ? ok('الفترة المخصصة بترندر') : bad('الفترة المخصصة وقعت');

    // نافذة اختيار فترة كشف الحساب — محتوى في الصفحة وفورم GET حقيقي.
    (str_contains($home, 'id="csStmtPick"') && str_contains($home, 'cs-pick-range'))
        ? ok('نافذة اختيار فترة الكشف موجودة (فورم GET حقيقي)')
        : bad('زرار كشف الحساب بيطبع بلا ما يسأل عن الفترة');

    foreach (['balance','in','out','to_office','expense','day','wallets','cats','parties','budget',
              'day_in','day_to_office','day_expense','office_total','office_drawer'] as $w) {
        $h = $render('drill', ['__args' => [$w]]);
        (!str_starts_with($h, '__ERR__') && !preg_match($errPat, $h) && trim($h) !== '')
            ? ok("نافذة «{$w}» بترندر") : bad("نافذة «{$w}»: " . substr(strip_tags($h), 0, 140));
    }
    /* ⚠️ **ممنوع نستدعي `drill()` بنوع غلط هنا**: بترمي على `notFound()` اللي
       بتعمل `exit` فبتقتل باقي الفاحص **بلا أي رسالة** (مصيدة موثَّقة اتصادت
       في `verify_notifications_ui` و`verify_ops_report`). الفحص على القايمة
       البيضا نفسها + وجود الحارس قبل أي قراءة. */
    $rc  = new \ReflectionClass(\App\Controllers\CompanySafeController::class);
    $wl  = $rc->getConstant('DRILL');
    $dbo = fn_body('app/Controllers/CompanySafeController.php', 'drill');
    (is_array($wl) && $wl !== [] && str_contains($dbo, 'in_array($what, self::DRILL, true)')
        && str_contains($dbo, 'notFound'))
        ? ok('drill بقايمة بيضا (' . count($wl) . ' نوع) وبيرفض المجهول قبل أي قراءة')
        : bad('drill بلا قايمة بيضا — أي نص من الرابط بيوصل الاستعلام');

    $h = $render('statement', ['from' => date('Y-m-01'), 'to' => date('Y-m-t')]);
    (!str_starts_with($h, '__ERR__') && !preg_match($errPat, $h)
        && str_contains($h, 'كشف حساب خزنة الشركة') && str_contains($h, 'رصيد أول المدة'))
        ? ok('كشف الطباعة A4 بيرندر') : bad('كشف الطباعة: ' . substr(strip_tags($h), 0, 160));
    // ⚠️ ممنوع أي مستند يكتب اسم خط بإيده — الهوية من print_base (قاعدة موثَّقة).
    (str_contains(src('app/Views/company_safe/statement.php'), "partial('print_base')")
        && !preg_match('/font-family:\s*[\'"]?(Segoe|Tahoma|Cairo|IBM)/i', src('app/Views/company_safe/statement.php')))
        ? ok('كشف الطباعة بياخد الخط والهوية من print_base')
        : bad('كشف الطباعة بيكتب خطًّا بإيده');

    // وضع التعديل بيرندر معبّى
    /* ⚠️ المبلغ بيرجع من القاعدة كنص `DECIMAL` (`3500.00`) — مقارنة بـ`3500`
       حرفيًّا بتفشل بإنذار كاذب. */
    $h = $render('index', ['tab' => 'moves', 'edit' => (string) $eId]);
    (str_contains($h, 'cs-drawer open') && preg_match('/name="amount"[^>]*value="3500(\.00)?"/', $h))
        ? ok('`?edit=` بيفتح الدرج معبّى بالبيانات')
        : bad('وضع التعديل مش بيتعبّى');
    /* ⚠️ النوع **مقفول** في التعديل — الدرج بيعرض `cs-picked` وحقل مخفي بدل
       الكروت، لأن تغيير النوع نقل فلوس من محفظة لمحفظة (الموديل بيرفضه). */
    (str_contains($h, 'cs-picked') && !str_contains($h, 'class="cs-type'))
        ? ok('النوع مقفول في التعديل (كروت النوع مخفية)')
        : bad('كروت النوع ظاهرة في التعديل — تغييره بيترفض من الموديل فالخيار كذب');

    $pdo->rollBack();
    near('الرولباك رجّع خزنة المكتب', Custody::balance(), $safe0);
    near('الرولباك رجّع خزنة الشركة', CompanySafe::balance(), $co0);
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    bad('استثناء أثناء الفحص: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

// ═════════════════════════════════════════════ ⑦ المسارات والصلاحيات
sec('⑦ المسارات الحرفية قبل الجشعة + المرفق');

/* ⚠️ **`code_only` مش `src`** — التعليق فوق المسارات بيذكر المسار الجشع بالنص،
   فالبحث الخام بيلاقيه قبل الحرفي ويدّي فشلًا كاذبًا (مصيدة موثَّقة). */
$routes = code_only('public/index.php');
$posOf = static fn (string $needle) => strpos($routes, $needle);
foreach ([['/company-safe/statement', '/company-safe/{id}/update'],
          ['/company-safe/budget',    '/company-safe/{id}/update'],
          ['/company-safe/drill/{what}', '/company-safe/{id}/update'],
          ['/company-safe/{id}/update', '/company-safe/{id}/delete']] as [$a, $b]) {
    $pa = $posOf($a); $pb = $posOf($b);
    ($pa !== false && $pb !== false && $pa < $pb)
        ? ok("«{$a}» قبل «{$b}»") : bad("ترتيب المسارات غلط: {$a} / {$b}");
}
str_contains($routes, "FileController@companySafeFile") ? ok('مسار إيصال خزنة الشركة مسجّل')
    : bad('مسار الإيصال مش مسجّل');
/* ⚠️ المرفق لجلسة المكتب بس — المندوب مرفوض عن قصد (إيصالات إيجار ومرتبات). */
$fc = fn_body('app/Controllers/FileController.php', 'companySafeFile');
(str_contains($fc, 'requireAuth') && !str_contains($fc, 'RepAuth'))
    ? ok('إيصال خزنة الشركة لجلسة المكتب بس (المندوب مرفوض)')
    : bad('إيصال خزنة الشركة متاح للمندوب — بيحتوي بيانات مالية للشركة');
in_array('company_safe', ['permits', 'reps', 'party_docs', 'company_safe'], true)
    && str_contains(src('app/Core/PrivateFile.php'), 'company_safe')
    ? ok('تصنيف company_safe متسجّل في PrivateFile') : bad('تصنيف company_safe ناقص');
// التصنيف لازم يكون في `assertCategory` **و**regex الـ`resolve` مع بعض.
substr_count(src('app/Core/PrivateFile.php'), 'company_safe') >= 2
    ? ok('company_safe في assertCategory و resolve الاتنين')
    : bad('company_safe في مكان واحد بس — الرفع أو القراءة هيفشل');
PrivateFile::resolve('private/company_safe/' . str_repeat('a', 40) . '.jpg') === null
    ? ok('resolve بترجّع null لملف مش موجود (بلا استثناء)') : bad('resolve بتتصرّف غلط');

// ═════════════════════════════════════════════ ⑨ كل كلاس مطبوع له شكل
sec('⑨ كل كلاس بتطبعه الشاشة له شكل في app.css');

$css = src('public/assets/css/app.css') . src('public/assets/css/m.css');
$js  = '';
foreach (glob(dirname(__DIR__) . '/public/assets/js/*.js') ?: [] as $j) { $js .= (string) file_get_contents($j); }
/* ⚠️ `code_only` — الدوك بلوك فوق الشاشة بيوثّق قاعدة «صفر style محلي»
   وبيذكر الوسم نفسه، فالبحث الخام بيفشل بإنذار كاذب. */
// ⚠️ **كل ملفات الوحدة** — التبويبات والدرج اتقسموا لملفات في v68، وفحص
//    ملفين بس كان هيسيب كلاسات الدرج بلا حارس.
$viewSrc = '';
foreach (glob(dirname(__DIR__) . '/app/Views/company_safe/*.php') ?: [] as $vf) {
    if (basename($vf) === 'statement.php') { continue; }   // مستند A4 مستقل بستايله
    $viewSrc .= code_only('app/Views/company_safe/' . basename($vf));
}
preg_match_all('/class="([^"<?]+)"/', $viewSrc, $m);
$orphan = [];
foreach ($m[1] as $blob) {
    foreach (preg_split('/\s+/', trim($blob)) as $c) {
        if ($c === '' || !preg_match('/^cs-[a-z0-9-]+$/', $c)) { continue; }
        if (!str_contains($css, '.' . $c) && !str_contains($js, $c)) { $orphan[$c] = true; }
    }
}
$orphan ? bad('كلاسات بلا شكل ولا سلوك: ' . implode(', ', array_keys($orphan)))
        : ok('كل كلاسات `.cs-*` المطبوعة معرَّفة');
// ⚠️ الكلاسات المشتركة بين الشاشة والنافذة ممنوع تعيش في `<style>` محلي.
(!str_contains($viewSrc, '<style'))
    ? ok('صفر `<style>` محلي في شاشة خزنة الشركة') : bad('فيه `<style>` محلي — هيفشل في النافذة');
str_contains($js, 'data-cs-for') ? ok('خطّاف الحقول المشروطة موجود في app.js')
    : bad('data-cs-for بلا معالج — الحقول هتفضل كلها ظاهرة');
str_contains($js, 'data-cs-preview') ? ok('خطّاف معاينة الأثر موجود في app.js')
    : bad('data-cs-preview بلا معالج — المعاينة مش هتتحدّث');
/* ⚠️ الدرج لازم يفضل شغّالًا **بلا جافاسكريبت**: الخلفية وزرار الإغلاق روابط
   حقيقية، فلو السكربت وقع المالك لسه يقدر يقفل ويسجّل. */
$drawerSrc = src('app/Views/company_safe/_drawer.php');
(substr_count($drawerSrc, 'cs-drawer-scrim') >= 1 && str_contains($drawerSrc, '<a class="cs-drawer-scrim"'))
    ? ok('خلفية الدرج رابط حقيقي (بتقفل بلا جافاسكريبت)')
    : bad('خلفية الدرج مش رابط — القفل بيعتمد على الجافاسكريبت');

echo "\n" . str_repeat('=', 62) . "\n";
echo ($fail === 0 ? "✅ كل الفحوصات نجحت" : "❌ فيه فحوصات فشلت") . " — نجح {$pass} · فشل {$fail}\n";
exit($fail === 0 ? 0 : 1);
