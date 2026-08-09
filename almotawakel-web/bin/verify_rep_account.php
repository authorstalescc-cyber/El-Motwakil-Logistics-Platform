<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص «حسابي» للمندوب (v72) — بوابة الاختيار بعد الدخول + شاشة حساب المندوب.
 *
 * أهم ٧ حاجات بيتأكد منها:
 *   ① **قراءة بحتة**: ولا `INSERT/UPDATE/DELETE` في `RepAccount`، ورصيد الخزنة
 *      وعهد المناديب قبل = بعد رغم فتح الشاشتين ورندرهم.
 *   ② **فترة الرواتب صح** عند أي يوم بداية: حدود الشهر · التقليب على السنة ·
 *      فبراير · واليوم اللي قبل البداية بيرجع لفترة الشهر اللي فات.
 *   ③ **المتبقي = الراتب − السلف وبس** — المواصلات ما بتتخصمش (قرار المالك).
 *      الفحص بيزوّد مواصلات ويثبت إن «المتبقي» **ما اتغيّرش**.
 *   ④ **الإعداد بيتفلتر من الكتالوج**: الاسم المجهول بيتشال، ونوع السلفة ما
 *      بيتكرّرش في قسم المصروفات، والفاضي = صفر أنواع لا كل الأنواع.
 *   ⑤ **الكارت = القايمة**: مجموع كل قسم يساوي صفوفه بالظبط.
 *   ⑥ **المندوب يشوف حسابه هو وبس** — الـid من الجلسة لا من الرابط.
 *   ⑦ **الواجهة**: كل كلاس بيتطبع له CSS في `m.css`، والمسارات حرفية قبل الجشعة.
 *
 * كله جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_rep_account.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\RepAuth;
use App\Core\App;
use App\Models\Closing;
use App\Models\RepAccount;
use App\Models\RepCustody;
use App\Models\RepMiscKind;

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function chk(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function sec(string $m): void { echo "\n$m\n" . str_repeat('─', 62) . "\n"; }
function near(string $l, $a, $e): void {
    abs((float) $a - (float) $e) <= 0.005 ? ok($l)
        : bad("{$l} — متوقع " . number_format((float) $e, 2) . " / فعلي " . number_format((float) $a, 2));
}
function srcA(string $f): string {
    $p = dirname(__DIR__) . '/' . $f;
    return is_file($p) ? (string) file_get_contents($p) : '';
}
/** ⚠️ كود بلا تعليقات — التعليق اللي بيوثّق القاعدة بيذكر النص الممنوع. */
function codeA(string $f): string {
    $p = dirname(__DIR__) . '/' . $f;
    if (!is_file($p)) { return ''; }
    $out = '';
    foreach (token_get_all((string) file_get_contents($p)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

echo "فحص «حسابي» للمندوب (v72)\n" . str_repeat('=', 62) . "\n";

$treasuryBefore = Closing::currentBalance();
$repsBefore     = RepCustody::available() ? RepCustody::totalOutstanding() : 0.0;

// ═══════════════════════════════ ① قراءة بحتة + المصدر الوحيد
sec('① الوحدة قراءة بحتة — ولا كتابة ولا مفتاح مكتوب بالإيد');

$model = codeA('app/Models/RepAccount.php');
foreach (['INSERT', 'UPDATE ', 'DELETE', 'ALTER', 'REPLACE'] as $w) {
    chk(!preg_match('~\b' . $w . '\b~i', $model), "RepAccount مفهاش «$w»");
}
chk(!str_contains($model, 'setSetting') && !str_contains($model, 'Settings::set'),
    'وما بتكتبش أي إعداد');

/* ⚠️ السؤال «أنهي نوع بيتخصم من الراتب؟» لازم يتجاوب من الكتالوج لا من شرط
   مكتوب هنا — تغيير المفتاح بعدها بيسيب المقارنة بتقارن على قيمة مالهاش وجود
   **وما بترميش خطأ**، بترجّع صفر وبس (قاعدة ②). */
foreach (RepMiscKind::keys() as $k) {
    chk(!preg_match('~[\'"]' . preg_quote($k, '~') . '[\'"]~', $model),
        "وما بتكتبش المفتاح «{$k}» حرفيًّا");
}
chk(str_contains($model, 'salaryKeys'), '★ وبتسأل الكتالوج عن أنواع الخصم (RepMiscKind::salaryKeys)');

/* ⚠️ **الوحدة ما بتعتمدش على وحدة سجل الإعدادات**: بتقرا بـ`App::setting`
   بافتراضٍ مبعوت صراحةً، فبتشتغل سواء اتنشر السجل أو لأ. لكن الافتراضي
   لازم يفضل **مطابقًا** للسجل لما يكون موجودًا، وإلا تعريفان يختلفوا. */
chk(str_contains($model, 'App::setting'), 'بتقرا الإعداد بـApp::setting (بلا اعتماد على وحدة السجل)');
chk(!preg_match('~App::setting\([^,)]+\)~', $model),
    '★ وبتبعت الافتراضي صراحةً في كل نداء (بلا نداء بمعامل واحد)');

if (class_exists(\App\Core\SettingsSchema::class)) {
    foreach ([[RepAccount::SETTING_START_DAY, RepAccount::DEFAULT_START_DAY],
              [RepAccount::SETTING_KINDS,     RepAccount::DEFAULT_KINDS]] as [$key, $def]) {
        chk(\App\Core\SettingsSchema::has($key), "الإعداد «{$key}» معرَّف في SettingsSchema");
        chk(\App\Core\SettingsSchema::defaultOf($key) === $def,
            "★ وافتراضي «{$key}» مطابق للسجل (مفيش تعريفان يختلفوا)");
    }
} else {
    echo "  (وحدة سجل الإعدادات مش منشورة هنا — فحص التطابق اتخطّى)\n";
}

/* ⚠️⚠️ **المعاملة بتفتح من هنا** — الفحص بيغيّر إعدادات حقيقية (يوم بداية
   الرواتب وأنواع المصروفات)، و`App::setSetting` بقت بتكتب في `settings_history`
   كمان (v70). كتابة برّه معاملة كانت هتسيب أثرًا في سجل التغييرات بتاع المالك
   حتى لو رجّعنا القيمة، ولو السكربت وقع في النص كانت هتسيب النظام بإعداد فحص. */
$origDay = (string) App::setting(RepAccount::SETTING_START_DAY, RepAccount::DEFAULT_START_DAY);
$origKin = (string) App::setting(RepAccount::SETTING_KINDS, RepAccount::DEFAULT_KINDS);
$pdo = Database::conn();
$pdo->beginTransaction();

try {

// ═══════════════════════════════ ② حساب فترة الرواتب
sec('② فترة الرواتب: أي يوم بداية · تقليب السنة · فبراير');

/** بيظبّط الإعداد ويرجّع الفترة بتاعة تاريخ معيّن. */
$periodWith = static function (int $day, string $anchor): array {
    App::setSetting(RepAccount::SETTING_START_DAY, (string) $day);
    return RepAccount::period($anchor);
};

$p = $periodWith(1, '2026-08-10');
chk($p['from'] === '2026-08-01' && $p['to'] === '2026-08-31', 'بداية ١: أغسطس = ٠١/٠٨ → ٣١/٠٨');

$p = $periodWith(26, '2026-08-10');
chk($p['from'] === '2026-07-26' && $p['to'] === '2026-08-25',
    '★ بداية ٢٦ ويوم ١٠: الفترة بدأت الشهر اللي فات (٢٦/٠٧ → ٢٥/٠٨)');

$p = $periodWith(26, '2026-08-26');
chk($p['from'] === '2026-08-26' && $p['to'] === '2026-09-25', 'وفي يوم البداية نفسه بتبدأ فترة جديدة');

$p = $periodWith(26, '2026-01-10');
chk($p['from'] === '2025-12-26' && $p['to'] === '2026-01-25', '★ والتقليب على السنة اللي فاتت صح');

$p = $periodWith(28, '2026-03-01');
chk($p['from'] === '2026-02-28' && $p['to'] === '2026-03-27', 'وفبراير ما بيكسرش الفترة (بداية ٢٨)');

/* ⚠️ يوم ٢٩/٣٠/٣١ مالوش مقابل في كل شهر — لازم يتقصّ على ٢٨ وإلا فبراير
   بيتخطّى أو الفترة بتزحلق نفسها شهرًا. */
App::setSetting(RepAccount::SETTING_START_DAY, '31');
chk(RepAccount::startDay() === 28, '★ يوم ٣١ بيتقصّ على ٢٨ (ما فيش شهر أقصر من كده)');
App::setSetting(RepAccount::SETTING_START_DAY, '0');
chk(RepAccount::startDay() === 1, 'وصفر بيرجع ١');

// الفترة السابقة/التالية بتمشي شهرًا بشهر بلا فجوة ولا تداخل
App::setSetting(RepAccount::SETTING_START_DAY, '26');
$cur  = RepAccount::period('2026-08-10');
$prev = RepAccount::shift($cur, -1);
$next = RepAccount::shift($cur, 1);
chk($prev['to'] === date('Y-m-d', strtotime($cur['from'] . ' -1 day')),
    '★ الفترة السابقة بتنتهي اليوم اللي قبل الحالية بالظبط (بلا فجوة)');
chk($next['from'] === date('Y-m-d', strtotime($cur['to'] . ' +1 day')),
    'والتالية بتبدأ اليوم اللي بعدها بالظبط (بلا تداخل)');
chk(RepAccount::isFuture(RepAccount::period(date('Y-m-d'))) === false,
    'وفترة النهاردة مش «مستقبل»');

// ═══════════════════════════════ ③ الإعداد بيتفلتر من الكتالوج
sec('③ إعداد المصروفات: بيتفلتر من الكتالوج ومش بيكرّر السلفة');

$setKinds = static function (string $v): array {
    App::setSetting(RepAccount::SETTING_KINDS, $v);
    return RepAccount::expenseKinds();
};
chk($setKinds('') === [], 'الإعداد الفاضي = صفر أنواع (القسم بيختفي) لا كل الأنواع');
chk($setKinds('__نوع_مخترع__') === [], 'والاسم المجهول بيتشال بدل ما يخترع نوعًا');

$labels = array_map([RepMiscKind::class, 'label'], RepMiscKind::nonSalaryKeys());
$byName = $setKinds(implode('، ', $labels));
chk($byName === RepMiscKind::nonSalaryKeys(),
    '★ الأسماء بالعربي بتتحلّ لمفاتيحها (صاحب الشركة مش هيكتب مفاتيح آلة)');

/* ⚠️ السلفة ليها قسمها الخاص فوق — لو ظهرت في «مصروفاتي» كمان، المندوب
   بيشوفها مرتين ويفتكر إنها اتخصمت مرتين. */
$salaryLabel = RepMiscKind::label(RepMiscKind::salaryKeys()[0] ?? '');
chk($setKinds($salaryLabel) === [], '★ ونوع السلفة بيتشال من قسم المصروفات (مايتعرضش مرتين)');

$dup = $setKinds($labels[0] . '، ' . $labels[0]);
chk(count($dup) === 1, 'والمكرَّر بيتلمّ مرة واحدة');

// ═══════════════════════════════ ④ السلوك بأرقام حقيقية
    $rep = (int) Database::scalar("SELECT id FROM reps WHERE status = 'active' ORDER BY id LIMIT 1");
    if (!$rep) { throw new RuntimeException('مفيش مندوب نشط — الفحص محتاج واحد.'); }

    App::setSetting(RepAccount::SETTING_START_DAY, '1');
    App::setSetting(RepAccount::SETTING_KINDS, implode('، ', $labels));
    $period = RepAccount::period();

    // بيئة نظيفة: بنصفّر أي نثريات سابقة للمندوب في الفترة عشان الأرقام تبقى معروفة
    Database::exec('DELETE FROM rep_misc_expenses WHERE rep_id = ? AND exp_date BETWEEN ? AND ?',
        [$rep, $period['from'], $period['to']]);
    Database::exec('UPDATE reps SET monthly_salary = 9000 WHERE id = ?', [$rep]);

    $day = $period['from'];
    $add = static function (string $kind, float $amt) use ($rep, $day): void {
        Database::exec(
            "INSERT INTO rep_misc_expenses (rep_id, exp_date, category, note, qty, unit_amount, amount, misc_kind)
             VALUES (?,?,?,?,1,?,?,?)",
            [$rep, $day, RepMiscKind::category($kind), '__acct_' . $kind, $amt, $amt, $kind]);
    };

    sec('④ المتبقي = الراتب − السلف وبس (المواصلات ما بتتخصمش)');

    $advKind = RepMiscKind::salaryKeys()[0];
    $add($advKind, 1200);
    $add($advKind, 300);
    $s = RepAccount::summary($rep, $period);
    near('الراتب من كارت المندوب', $s['salary'], 9000);
    near('السلف اتجمعت', $s['advances'], 1500);
    near('★ المتبقي = ٩٠٠٠ − ١٥٠٠', $s['net'], 7500);

    /* ⚠️ **جوهر قرار المالك**: زوّد مواصلات وشوف «المتبقي» — لازم يفضل هو هو
       بالظبط. لو اتغيّر، يبقى المواصلات بقت بتتخصم من راتبه بالسكوت. */
    $expKind = RepMiscKind::nonSalaryKeys()[0];
    $add($expKind, 500);
    $s2 = RepAccount::summary($rep, $period);
    near('★★ المتبقي ما اتغيّرش بعد إضافة ٥٠٠ مواصلات', $s2['net'], $s['net']);
    near('والمصروفات ظهرت في قسمها', $s2['expenses'][$expKind] ?? -1, 500);
    near('وإجمالي المصروفات', $s2['expensesTotal'], 500);

    sec('⑤ الكارت = القايمة (مجموع كل قسم يساوي صفوفه)');

    $advRows = RepAccount::advanceRows($rep, $period);
    near('★ مجموع صفوف السلف = رقم الكارت',
        array_sum(array_column($advRows, 'amount')), $s2['advances']);
    chk(count($advRows) === 2, 'وعدد صفوف السلف صح (٢)');

    $expRows = RepAccount::expenseRows($rep, $period);
    near('★ ومجموع صفوف المصروفات = إجماليها',
        array_sum(array_column($expRows, 'amount')), $s2['expensesTotal']);

    /* ⚠️ صفوف المصروفات **ما تحتويش سلفة** — لو احتوت، القايمة تحت الكارت
       هتقول رقم والكارت يقول تاني. */
    $stray = array_filter($expRows,
        static fn($r) => RepMiscKind::deductsSalary($r['misc_kind'] ?? null));
    chk($stray === [], 'وولا صف سلفة تسرّب لقايمة المصروفات');

    sec('⑥ الفترة بتفلتر فعلاً · والمدفوع بالفيزا مستثنى');

    // بند برّه الفترة ما يظهرش
    $out = date('Y-m-d', strtotime($period['from'] . ' -5 day'));
    Database::exec(
        "INSERT INTO rep_misc_expenses (rep_id, exp_date, category, note, qty, unit_amount, amount, misc_kind)
         VALUES (?,?,?,?,1,777,777,?)",
        [$rep, $out, RepMiscKind::category($advKind), '__acct_برّه', $advKind]);
    near('★ سلفة برّه الفترة ما دخلتش الحساب',
        RepAccount::summary($rep, $period)['advances'], $s2['advances']);

    /* ⚠️ المدفوع بكارت المكتب خرج من الكارت مش من جيبه — ما ينفعش يتخصم من
       راتبه. `cashOnlySql` هو المصدر الوحيد للشرط ده (v45). */
    if (\App\Models\RepMiscExpense::visaReady()) {
        Database::exec(
            "INSERT INTO rep_misc_expenses (rep_id, exp_date, category, note, qty, unit_amount, amount, misc_kind, is_visa)
             VALUES (?,?,?,?,1,400,400,?,1)",
            [$rep, $day, RepMiscKind::category($advKind), '__acct_فيزا', $advKind]);
        near('★ وسلفة بالفيزا ما اتخصمتش من الراتب',
            RepAccount::summary($rep, $period)['advances'], $s2['advances']);
    }

    sec('⑦ المندوب يشوف حسابه هو وبس');

    /* الـid جاي من الجلسة لا من الرابط — ده الحارس الحقيقي. الفحص بيثبت إن
       الكنترولر ما بيقراش أي معرّف مندوب من `$_GET`/`$_POST`. */
    $ctrl = codeA('app/Controllers/RepPortalController.php');
    $acctFn = substr($ctrl, (int) strpos($ctrl, 'function account('), 1200);
    chk(str_contains($acctFn, 'RepAuth::repId()'), 'الكنترولر بياخد الـid من الجلسة');
    chk(!preg_match('~\$_(GET|POST)\[[\'"](rep|rep_id|rid|id)[\'"]\]~', $acctFn),
        '★ وما بيقراش أي معرّف مندوب من الرابط');
    chk(str_contains($acctFn, 'requireRep()'),
        'وحارس الجلسة (وقفل الصيانة معاه) متنادى');

    // رندر حقيقي للشاشتين — بيمسك أي متغيّر ناقص أو أيقونة غلط
    $_SESSION = ['rep_id' => $rep, 'rep_name' => 'فحص', 'rep_last' => time()];
    foreach ([
        ['m/start',   ['repName' => 'فحص', 'account' => RepAccount::summary($rep, $period)]],
        ['m/account', ['repName' => 'فحص', 'period' => $period,
                       'prev' => RepAccount::shift($period, -1), 'next' => null,
                       'sum' => RepAccount::summary($rep, $period),
                       'advances' => RepAccount::advanceRows($rep, $period),
                       'expenses' => RepAccount::expenseRows($rep, $period),
                       'pending' => RepAccount::pendingCount($rep, $period)]],
    ] as [$view, $vars]) {
        ob_start();
        extract($vars, EXTR_SKIP);
        try { require dirname(__DIR__) . '/app/Views/' . $view . '.php'; $html = ob_get_clean(); }
        catch (\Throwable $e) { ob_end_clean(); $html = ''; bad("رندر $view وقع: " . $e->getMessage()); }
        chk(strlen($html) > 800, "شاشة $view بترندر");
        /* ⚠️ الأيقونة الناقصة بترسم **دايرة فاضية بلا خطأ** (مصيدة موثَّقة). */
        chk(!str_contains($html, 'icon-missing'), "و$view مفيهاش أيقونة ناقصة");
    }

} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    /* ⚠️ الـrollback بيرجّع صفوف `settings` و`settings_history`، لكن **الكاش
       الساكن في `App` لأ** — فبنصفّره عشان القراءة اللي بعده تيجي من القاعدة
       المرجَّعة. وبنصفّره **بلا كتابة**: إعادة `setSetting` هنا كانت هتسجّل
       تغييرًا وهميًّا في سجل المالك بعد ما المعاملة رجعت. */
    App::flushSettingsCache();
}

// ═══════════════════════════════ ⑧ الواجهة والمسارات
sec('⑧ الواجهة: كل كلاس له CSS، والمسارات حرفية قبل الجشعة');

/* ⚠️ `/m` **ما بتحمّلش `app.css`** — الكلاس لازم يعيش في `m.css` تحديدًا،
   والناقص بيقع على الشكل الافتراضي **بالسكوت** (مصيدة `.table-mobile-scroll`). */
$mcss  = srcA('public/assets/css/m.css');
$start = srcA('app/Views/m/start.php');
$acct  = srcA('app/Views/m/account.php');
foreach ([['ms-pick', $start], ['ms-ic', $start], ['ms-amt', $start], ['ms-out', $start],
          ['ma-period', $acct], ['ma-arrow', $acct], ['ma-hero', $acct], ['ma-row', $acct],
          ['ma-body', $acct], ['ma-line', $acct], ['ma-head', $acct], ['ma-work', $acct],
          ['hf-sheet', $acct], ['hf-top', $acct]] as [$cls, $src]) {
    chk(str_contains($src, $cls) && str_contains($mcss, '.' . $cls),
        ".{$cls} في الماركب وفي m.css مع بعض");
}
chk(!str_contains($start, '<style') && !str_contains($acct, '<style'),
    'وصفر `<style>` محلي في الشاشتين');

/* ⚠️ `.hf-top`/`.hf-back` بيقروا `--hm-ink*` **المعرَّفة على `.hm-body` وبس**.
   من غير الكلاس ده، `var(--توكن-مش-موجود)` بيبطّل الإعلان **بالسكوت** فالترويسة
   بتطلع بلا خلفية — المصيدة اللي `verify_dark_mode` اتكتب عشانها. */
chk((bool) preg_match('~<body class="[^"]*\bhm-body\b~', $acct),
    '★ وشاشة «حسابي» على `hm-body` (وإلا توكنز الترويسة بتتبخّر بالسكوت)');
chk(str_contains($acct, "_back.php"),
    'وزرار الرجوع من المكوّن الموحّد لا مكتوب بالإيد');

/* ⚠️ `<details>` بيرسم مثلث افتراضي في المحركين بصيغتين مختلفتين — لو واحد
   بس اتشال، السهم بيبان في سفاري وحده. */
chk(str_contains($mcss, '::-webkit-details-marker') && str_contains($mcss, '::marker'),
    'ومثلث `<details>` الافتراضي متشال من المحركين');

/* الراوتر first-match-wins: الحرفي قبل الجشع (تحذير ٤). */
$idx = srcA('public/index.php');
$posStart = strpos($idx, "'/m/start'");
$posAcct  = strpos($idx, "'/m/account'");
$posHome  = strpos($idx, "\$r->get('/m',");
chk($posStart !== false && $posAcct !== false, 'المسارين مسجَّلين');
chk($posStart < $posHome && $posAcct < $posHome, '★ والاتنين قبل المسار الجشع `/m`');
chk(!preg_match('~\$r->post\(\'/m/(start|account)~', $idx),
    'وولا مسار POST عليهم (الشاشتين قراءة بحتة)');

// «حسابي» لازم تكون موصولة من شاشة العمل كمان — المندوب بيفتح من الاختصار
chk(str_contains(srcA('app/Views/m/home.php'), "url('m/account')"),
    '★ و«حسابي» موصولة من الشريط السفلي (مش من بوابة الدخول بس)');

/* ⚠️ `.hm-tabs svg{fill:currentColor}` بتغلب `fill="none"` بتاعة `icon()`
   الخطّية، فأيقونة التبويب لازم تكون **مملوءة** بالإيد وإلا بتطلع كتلة صمّاء. */
$tabsChunk = substr(srcA('app/Views/m/home.php'),
    (int) strpos(srcA('app/Views/m/home.php'), "url('m/account')"), 400);
chk(!str_contains($tabsChunk, 'icon('),
    'وأيقونة تبويب «حسابي» مرسومة مملوءة لا من icon() (وإلا كتلة صمّاء)');

// الدخول بيوَدّي على بوابة الاختيار
chk(str_contains(codeA('app/Controllers/RepPortalController.php'), "redirect('m/start')"),
    '★ والدخول بيوَدّي على بوابة الاختيار');

echo "\n" . str_repeat('═', 62) . "\n";
echo "اترجّع كل شيء (rollback) — مفيش أي أثر.\n";
near('رصيد الخزنة قبل = بعد', Closing::currentBalance(), $treasuryBefore);
if (RepCustody::available()) {
    near('وإجمالي عهد المناديب قبل = بعد', RepCustody::totalOutstanding(), $repsBefore);
}
near('ويوم بداية الرواتب رجع لقيمته',
    (float) App::setting(RepAccount::SETTING_START_DAY, RepAccount::DEFAULT_START_DAY),
    (float) $origDay);

echo "\nنجح: $pass | فشل: $fail\n";
exit($fail > 0 ? 1 : 0);
