<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص تحسينات شاشة المندوب (v63).
 *
 * القواعد المحروسة هنا:
 *   ① **العزل بالبناء** — ولا عمود مالي في `rep_permit_pins`، وولا ملف خزنة
 *      بيعرف الجدول، ورصيد الخزنة وعهد المناديب قبل = بعد.
 *   ② **التثبيت تفضيل عرض مش صلاحية** — ما بيغيّرش مين يشوف إيه ولا مين
 *      يكتب على إيه، وإذن مقفول بيترفض تثبيته.
 *   ③ **`myPermit()` بتجيب إذنًا واحدًا** بنفس حزمة `myPermits()` بالحرف.
 *   ④ **شاشة «يوم تاني» قراءة فقط** — ولا فورم كتابة، ولا بتلمس يوم العمل.
 *   ⑤ **كل كلاس بيتطبع له CSS في `m.css`** (مصيدة `.table-mobile-scroll`).
 *   ⑥ **المسارات الحرفية قبل الجشعة** (تحذير ٤).
 *
 * كله جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_v63.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\RepAuth;
use App\Models\Closing;
use App\Models\RepCustody;
use App\Models\RepPin;
use App\Models\RepPortal;

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function sec(string $m): void { echo "\n" . $m . "\n" . str_repeat('─', 62) . "\n"; }
function near(string $l, $a, $e): void {
    abs((float) $a - (float) $e) <= 0.005 ? ok($l)
        : bad("{$l} — متوقع " . number_format((float) $e, 2) . " / فعلي " . number_format((float) $a, 2));
}
function src(string $file): string {
    $p = dirname(__DIR__) . '/' . $file;
    return is_file($p) ? (string) file_get_contents($p) : '';
}
/** كود بلا تعليقات — عشان تعليق بيوثّق القاعدة ما يديش نجاحًا/فشلًا كاذبًا. */
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

echo "فحص تحسينات شاشة المندوب (v63)\n" . str_repeat('=', 62) . "\n";

if (!RepPin::ready()) {
    echo "\n⚠️ جدول rep_permit_pins مش موجود — شغّل php bin/migrate_v63.php الأول.\n";
    exit(1);
}

$treasuryBefore = Closing::currentBalance();
$repsBefore     = RepCustody::available() ? RepCustody::totalOutstanding() : 0.0;

// ══════════════════════════════════════════ ① العزل بالبناء
sec('① العزل بالبناء — ولا ملف خزنة بيعرف الوحدة');

/* ⚠️ ده الحارس اللي بيخلّي العزل «بالبناء» مش «باستثناء بنفتكره».
   نفس نمط `rep_misc_expenses` (v39) و`car_expenses` (v54) و`permit_track_events` (v62). */
$safeFiles = [
    'app/Models/Closing.php', 'app/Models/Custody.php', 'app/Models/DailyReview.php',
    'app/Models/Expense.php', 'app/Models/Metrics.php', 'app/Models/RepCustody.php',
    'app/Controllers/DailyClosingController.php', 'bin/backfill_closings.php',
    'app/Calc.php',
];
foreach ($safeFiles as $f) {
    $code = code_only($f);
    (!str_contains($code, 'rep_permit_pins') && !str_contains($code, 'RepPin'))
        ? ok(basename($f) . ' عمره ما سمع عن الوحدة')
        : bad(basename($f) . ' بيذكر الوحدة — العزل بالبناء اتكسر');
}

$cols = array_column(Database::all(
    "SELECT column_name cn FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'rep_permit_pins'"), 'cn');
$money = array_values(array_filter($cols, function ($c) {
    foreach (['amount','custody','freight','debt','paid','price','total','balance','visa'] as $b) {
        if (str_contains((string) $c, $b)) { return true; }
    }
    return false;
}));
!$money ? ok('ولا عمود مالي في الجدول (' . count($cols) . ' عمود)')
        : bad('أعمدة مالية: ' . implode(', ', $money));

$pdo = Database::conn();
$pdo->beginTransaction();

try {
    // ══════════════════════════════════════ ② بيئة الاختبار
    sec('② التثبيت: بيشتغل وما بيكرّرش');

    $day = '2037-11-03';
    Database::exec(
        "INSERT INTO permits (permit_no, permit_type, permit_date, status, company, client, port)
         VALUES ('__pin_open', 'sader', ?, 'open', '__pin_co', '__pin_cl', '__pin_port')", [$day]);
    $pid = Database::lastId();
    Database::exec(
        "INSERT INTO permits (permit_no, permit_type, permit_date, status)
         VALUES ('__pin_closed', 'warid', ?, 'closed')", [$day]);
    $closedPid = Database::lastId();

    $rep = (int) Database::scalar("SELECT id FROM reps WHERE status = 'active' ORDER BY id LIMIT 1");
    if (!$rep) { throw new RuntimeException('مفيش مندوب نشط في القاعدة دي — الفحص محتاج واحد.'); }

    RepPin::toggle($rep, $pid) === true ? ok('التثبيت اشتغل') : bad('التثبيت ما اشتغلش');
    RepPin::has($rep, $pid) ? ok('وhas بتشوفه') : bad('has ما شافتهوش');

    // التكرار ممنوع بالبناء (المفتاح المركّب) — ضغطتين ما ينفعش يدّوا صفّين
    Database::exec('INSERT IGNORE INTO rep_permit_pins (rep_id, permit_id) VALUES (?,?)', [$rep, $pid]);
    (int) Database::scalar(
        'SELECT COUNT(*) FROM rep_permit_pins WHERE rep_id = ? AND permit_id = ?', [$rep, $pid]) === 1
        ? ok('★ صف واحد بعد إدخال متكرر (المفتاح المركّب شغّال)') : bad('التثبيت اتكرّر');

    RepPin::toggle($rep, $pid) === false ? ok('والضغطة التانية بتفكّه') : bad('فكّ التثبيت ما اشتغلش');
    !RepPin::has($rep, $pid) ? ok('واتشال فعلاً') : bad('فضل مثبَّتًا بعد الفكّ');

    /* ⚠️ الفورم مش مصدر ثقة: الحارس في الموديل لا في الكنترولر. */
    RepPin::toggle($rep, $closedPid) === false ? ok('★ وإذن مقفول بيترفض تثبيته') : bad('اتثبّت إذن مقفول');
    !RepPin::has($rep, $closedPid) ? ok('وما اتسجّلش صف له') : bad('اتسجّل صف لإذن مقفول');
    RepPin::toggle($rep, 99999999) === false ? ok('وإذن مش موجود بيترفض') : bad('اتثبّت إذن وهمي');

    // ══════════════════════════════════════ ③ التثبيت تفضيل عرض مش صلاحية
    sec('③ ★ التثبيت ما بيفتحش ولا بيقفل أي إذن');

    $before = array_column(RepPortal::myPermits($rep), 'id');
    RepPin::toggle($rep, $pid);
    $after = array_column(RepPortal::myPermits($rep), 'id');
    sort($before); $bs = $before; sort($after);
    ($bs === $after)
        ? ok('نفس مجموعة الأذونات قبل وبعد التثبيت (اللي بيتغيّر الترتيب بس)')
        : bad('التثبيت غيّر مين ظاهر — ده بقى صلاحية مش عرض');

    $list = RepPortal::myPermits($rep);
    ((int) ($list[0]['id'] ?? 0) === $pid)
        ? ok('★ والمثبَّت طلع أول القايمة') : bad('المثبَّت ما طلعش فوق');
    !empty($list[0]['pinned']) ? ok('وموسوم pinned في الحزمة') : bad('مفتاح pinned ناقص');

    /* الكتابة لسه محكومة بـ«الإذن مفتوح» وبس — التثبيت ما بيمسّهاش. */
    $threw = false;
    try { RepPortal::assertAssigned($rep, $closedPid); } catch (\Throwable $e) { $threw = true; }
    $threw ? ok('★ والكتابة على المقفول لسه بترفض (assertAssigned ما اتلمستش)')
           : bad('الكتابة على إذن مقفول عدّت');

    // ══════════════════════════════════════ ④ myPermit استعلام واحد
    sec('④ myPermit(): إذن واحد بنفس الحزمة بالحرف');

    $one = RepPortal::myPermit($rep, $pid);
    $fromList = null;
    foreach ($list as $p) { if ((int) $p['id'] === $pid) { $fromList = $p; break; } }

    ($one !== null && $fromList !== null && $one == $fromList)
        ? ok('★ نفس القيم بالحرف زي ما بتيجي من myPermits()')
        : bad('حزمة myPermit() اختلفت عن myPermits()');

    RepPortal::myPermit($rep, $closedPid) === null
        ? ok('والمقفول بيرجّع null') : bad('المقفول رجع من myPermit');
    RepPortal::myPermit($rep, 0) === null ? ok('و0 بترجّع null') : bad('id صفر رجّع حزمة');

    /* ⚠️ الحارس البنيوي: `myPermit` **ممنوع** ترجع تلفّ على `myPermits()` —
       ده كان بيبني الـ٣٧ إذن كلهم عشان ياخد واحد (٦ استعلامات على كل فتحة). */
    $rp = code_only('app/Models/RepPortal.php');
    $body = preg_match('~function myPermit\(int \$repId, int \$permitId\).*?\n    \}~s', $rp, $m) ? $m[0] : '';
    ($body !== '' && !str_contains($body, 'myPermits('))
        ? ok('★ وما بتلفّش على myPermits() تاني') : bad('myPermit رجعت تبني القايمة كلها');
    str_contains($body, 'buildPermits')
        ? ok('وبتستخدم buildPermits للإذن الواحد') : bad('buildPermits مش مستخدَمة');

    // ══════════════════════════════════════ ⑤ العزل المالي فعليًّا
    sec('⑤ ★ العزل المالي — ولا مليم اتحرّك');

    near('رصيد خزنة المكتب زي ما هو بالمليم', Closing::currentBalance(), $treasuryBefore);
    if (RepCustody::available()) {
        near('وإجمالي عهد المناديب زي ما هو', RepCustody::totalOutstanding(), $repsBefore);
    }

    // ══════════════════════════════════════ ⑥ الرندر الفعلي
    sec('⑥ رندر الشاشات فعليًّا');

    $render = function (string $view, array $data): string {
        extract($data, EXTR_SKIP);
        ob_start();
        include dirname(__DIR__) . '/app/Views/' . $view . '.php';
        return (string) ob_get_clean();
    };

    $subs = RepPortal::mySubmissions($rep, 20, '', $day);
    $html = $render('m/entries_day', [
        'day' => $day, 'workDate' => RepAuth::workDate(), 'status' => '',
        'submissions' => $subs, 'pocket' => RepPortal::dayPocket($rep, $day),
    ]);
    !preg_match('/Fatal error|Warning:|Undefined variable|Undefined array key/', $html)
        ? ok('شاشة «يوم تاني» بترندر بلا أي خطأ PHP') : bad('خطأ PHP في شاشة يوم تاني');

    /* ⚠️⚠️ أهم فحص في الشاشة دي: **ولا فورم كتابة واحد**. لو اتضاف تعديل أو
       حذف أو تسجيل هنا، المندوب هيكتب على يوم مش يوم شغله بلا ما ياخد باله. */
    (substr_count($html, '<form') === 0)
        ? ok('★ وولا فورم كتابة واحد فيها (قراءة فقط بالكامل)')
        : bad('فيه فورم في شاشة القراءة — الباج رجع');
    (!str_contains($html, '/edit') && !str_contains($html, '/delete'))
        ? ok('★ وولا رابط تعديل ولا حذف') : bad('رابط تعديل/حذف في شاشة القراءة');
    str_contains($html, 'hm-readonly')
        ? ok('وبتقول للمندوب إنها عرض بس') : bad('تنبيه «عرض بس» ناقص');
    str_contains($html, 'assets/css/m.css')
        ? ok('وبتحمّل m.css (البوابة برّه تخطيط المكتب)') : bad('بتحمّل ملف تنسيق غلط');

    /* ⚠️ قاعدة v35: ممنوع أي مبلغ بيخصّ العميل يوصل شاشة المندوب. */
    !preg_match('/actual_amount|actual_unit/', $html)
        ? ok('★ وولا سعر عميل فيها (قاعدة v35)') : bad('سعر العميل وصل شاشة المندوب');

} catch (\Throwable $e) {
    bad('استثناء غير متوقّع: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}

// ══════════════════════════════════════════ ⑦ الكود والمسارات
sec('⑦ المسارات والكنترولر');

$routes = src('public/index.php');
str_contains($routes, "\$r->get('/m/entries', 'RepPortalController@entriesDay')")
    ? ok('مسار «بنود يوم تاني» مسجَّل') : bad('المسار ناقص');
str_contains($routes, "'/m/permit/{id}/pin'") ? ok('ومسار التثبيت') : bad('مسار التثبيت ناقص');

/* ⚠️ تحذير ٤: الحرفي قبل الجشع، وإلا `/m/entries` تتلقّط كـ`entry/{sid}`. */
$aEntries = strpos($routes, "'/m/entries'");
$aEntry   = strpos($routes, "'/m/entry/{sid}");
($aEntries !== false && $aEntry !== false && $aEntries < $aEntry)
    ? ok('و/m/entries حرفي قبل /m/entry/{sid}') : bad('ترتيب مسارات البنود غلط');
$aPin     = strpos($routes, "'/m/permit/{id}/pin'");
$aGreedy  = strpos($routes, "'/m/permit/{id}',");
($aPin !== false && $aGreedy !== false && $aPin < $aGreedy)
    ? ok('ومسار التثبيت قبل الجشع /m/permit/{id}') : bad('ترتيب مسار التثبيت غلط');

$ctl = code_only('app/Controllers/RepPortalController.php');

/* ⚠️⚠️ شاشة القراءة **ممنوع** تلمس يوم العمل — ده كل سبب وجودها. */
$edBody = preg_match('~function entriesDay\(\).*?\n    \}~s', $ctl, $m) ? $m[0] : '';
($edBody !== '' && !str_contains($edBody, 'setWorkDate'))
    ? ok('★ entriesDay ما بتلمسش يوم العمل') : bad('شاشة القراءة بتغيّر يوم العمل — الباج رجع');
str_contains($edBody, "\$_GET['day']")
    ? ok('وبتقرا اليوم من الرابط') : bad('اليوم مش جاي من الرابط');
str_contains($edBody, 'preg_match') && str_contains($edBody, 'strtotime')
    ? ok('وبتتحقّق من صيغة التاريخ (الرابط مش مصدر ثقة)') : bad('صيغة التاريخ مش متفحوصة');

/* الحزمة الكاملة على تبويب الأذونات بس، والشارة من العدّاد الرخيص. */
$homeBody = preg_match('~function home\(\).*?\n    \}~s', $ctl, $m) ? $m[0] : '';
str_contains($homeBody, "\$tab === 'permits' ? RepPortal::myPermits")
    ? ok('★ والقايمة بتتبني على تبويب الأذونات بس') : bad('القايمة لسه بتتبني على كل التبويبات');
str_contains($homeBody, 'openPermitCount')
    ? ok('والشارة من العدّاد الرخيص') : bad('الشارة لسه بتعدّ القايمة');
/* ⚠️ `count($permits)` **مسموحة جوّه تبويب الأذونات** (القايمة محمّلة هناك)،
   وممنوعة في شريط التبويبات ورأس القسم — دول بيترسموا على التبويبين. */
$homeSrc = code_only('app/Views/m/home.php');
$navBlock = preg_match('~<nav class="hm-tabs".*?</nav>~s', $homeSrc, $m) ? $m[0] : '';
($navBlock !== '' && !str_contains($navBlock, 'count($permits)') && str_contains($navBlock, 'permitCount'))
    ? ok('★ وشارة التبويب من العدّاد الرخيص لا من القايمة')
    : bad('شارة التبويب لسه بتعدّ القايمة — هتقول صفر غلط على تبويب البنود');
$headBlock = preg_match('~<div class="hm-sheet-head">.*?</div>~s', $homeSrc, $m) ? $m[0] : '';
($headBlock !== '' && !str_contains($headBlock, 'count($permits)'))
    ? ok('ورأس القسم كمان') : bad('رأس القسم لسه بيعدّ القايمة');

// ══════════════════════════════════════════ ⑦ب بلاغات المالك (2026-08-02)
sec('⑦ب «جديد» بالهوية · الجرس ظاهر · لوحا الشرح اتشالوا');

$home = src('app/Views/m/home.php');

/* ⚠️⚠️ «جديد» = إذن **أول مرة يشوفه**، بالهوية لا بعلامة زمنية.
   العلامة الزمنية القديمة ما كانتش بتتقدّم غير بضغطة «تمام»، فأي إذن اتفتح
   بعدها يفضل «جديد» للأبد والبانر يرجع كل فتحة (بلاغ المالك). */
str_contains($home, "'alm:m:seen:permit-ids'")
    ? ok('★ الأساس بقى أرقام الأذونات المتشافة') : bad('لسه بيعتمد على علامة زمنية');
(!str_contains($home, 'alm:m:seen:permits') && !str_contains($home, 'maxCardTime'))
    ? ok('وعلامة الوقت القديمة اتشالت خالص') : bad('العلامة الزمنية القديمة لسه موجودة');
str_contains($home, 'data-pid=')
    ? ok('والكارت بيحمل رقم الإذن') : bad('data-pid ناقصة من الكارت');
(!str_contains($home, 'data-created'))
    ? ok('وdata-created اتشالت (مبقتش أساس أي حاجة)') : bad('data-created لسه مستعملة');

/* بيتعلّم «متشاف» فورًا — من غير كده الشارة بتفضل ظاهرة كل مرة. */
str_contains($home, 'writeSeenIds(shownIds)')
    ? ok('★ وبيتعلّم متشاف فورًا فالشارة تبان مرة واحدة') : bad('ما بيتعلّمش متشاف');
/* ⚠️ الكروت بترسم على تبويب الأذونات بس — لمس المخزَّن من غيرها بيصفّر كل حاجة. */
str_contains($home, 'if (cards.length) {')
    ? ok('وما بيلمسش المخزَّن لو مفيش كروت (تبويب بنودي)') : bad('ممكن يمسح المتشاف من تبويب البنود');

/* ⚠️ التنبيه المحلي كان **خبرًا تانيًا لنفس الحاجة** — نظام الإشعارات الحقيقي
   بيبعت `permit.new` للمناديب أصلاً (جمهوره both). */
(!str_contains($home, 'new Notification('))
    ? ok('★ والتنبيه المحلي المكرَّر اتشال') : bad('لسه فيه تنبيه محلي مكرّر مع نظام الإشعارات');

/* ⚠️⚠️ الجرس كان مختفي: `.hm-top` خلفيتها غامقة وبلا لون نص، و`.hm-body`
   بتحطّ لون شبه أسود، والأيقونة `stroke:currentColor` ⇒ غامق على غامق. */
$mcssB = src('public/assets/css/m.css');
$bell = preg_match('~\.nt-bell\{[^}]*\}~s', $mcssB, $m) ? $m[0] : '';
($bell !== '' && str_contains($bell, 'color:'))
    ? ok('★ الجرس بيحدّد لونه صراحةً (كان مختفي على الهيدر الغامق)')
    : bad('الجرس بلا لون — هيرجع يختفي على الخلفية الغامقة');
str_contains($mcssB, '.nt-bell svg')
    ? ok('وله مقاس أيقونة محدَّد') : bad('مقاس أيقونة الجرس مش محدَّد');
str_contains($home, 'id="repBell"')
    ? ok('وموجود في ماركب الشاشة') : bad('الجرس مش في الشاشة');

/* لوحا الشرح اتشالوا بقرار المالك — والحماية البديلة محروسة في verify_v49. */
(!str_contains($home, 'hm-pastday') && !str_contains($home, 'hm-nodep'))
    ? ok('★ ولوحا الشرح («شغّال على يوم» · «ماحدش نزّل عهدة») اتشالوا')
    : bad('لوح شرح مشال رجع تاني');
(!str_contains($mcssB, '.hm-pastday') && !str_contains($mcssB, '.hm-nodep'))
    ? ok('وتنسيقهم اتشال كمان (مفيش CSS ميت)') : bad('CSS ميت للوحين المشالين');

// ══════════════════════════════════════════ ⑦ج شكل الكارت (طلب المالك)
sec('⑦ج ختم الميناء · شريط الحالة · التوكيل البارز · وسم المبرّد');

$homeC = src('app/Views/m/home.php');
/* ⚠️ بلا تعليقات — التعليق اللي بيوثّق الممنوع بيذكره فيدّي فشلًا كاذبًا. */
$mcssC = preg_replace('~/\*.*?\*/~s', '', src('public/assets/css/m.css'));

/* ⚠️ **رسم بالكود لا صور** — قرار المالك بعد المفاضلة: ٢٩ إذن من ٣٧ على نفس
   الميناء يعني ٧٨٪ من الكروت كانت هتطلع بنفس الصورة، ومعاها ~٩٠٠ك على موبايل
   المندوب في الميدان. الفحص بيمنع رجوع الصور للكارت. */
/* ⚠️ الممنوع هو **الصورة** (`url(`) لا `background-image` نفسها — النسيج
   تدرّجات وبيستخدمها بشكل مشروع. أول صياغة منعت الخاصية كلها فمنعت النسيج. */
(!preg_match('~<img[^>]*hm-stub~s', $homeC)
 && !preg_match('~hm-stub[^{]*\{[^}]*url\(~s', $mcssC))
    ? ok('★ الختم والنسيج مرسومين بالكود — ولا صورة في الكارت')
    : bad('صورة رجعت للكارت (وزن على موبايل المندوب)');

str_contains($homeC, '$portMark = function')
    ? ok('وفيه خريطة علامة لكل ميناء') : bad('خريطة علامات الموانئ ناقصة');
str_contains($homeC, 'Maintenance::normkey')
    ? ok('★ والمطابقة بـnormkey (تهجئة الميناء مش بتفرق)') : bad('مطابقة الميناء نصّية خام');

foreach (['lighthouse', 'crane', 'ship', 'containers', 'snowflake'] as $ic) {
    icon($ic, 14) !== icon('__لا_يوجد__', 14)
        ? ok("علامة {$ic} موجودة في icon()") : bad("علامة {$ic} ناقصة — هتقع على دايرة فاضية");
}

/* ⚠️ الختم زخرفي: لازم `aria-hidden` (قارئ الشاشة ما يقراهوش) و
   `pointer-events:none` (وإلا بيلقط ضغطة زرار التثبيت اللي تحته). */
preg_match('~<span class="hm-stamp"[^>]*aria-hidden~', $homeC)
    ? ok('★ والختم aria-hidden (زخرفي)') : bad('الختم مش مخفي عن قارئ الشاشة');

/* ⚠️ الشكل بيترسم **مرّة واحدة** والكروت بتشاور عليه — رسمه كامل جوّه كل كارت
   كان بيزوّد ٥٢ك على ٣٧ كارت، وده بيناقض سبب اختيار الرسم بدل الصور. */
str_contains($homeC, '<use href="#stamp-')
    ? ok('★ والكروت بتشاور على رمز مشترك مش بترسمه كل مرة') : bad('الختم مرسوم كامل في كل كارت');
str_contains($homeC, 'class="hm-stamp-lib"')
    ? ok('ومكتبة الأشكال مرّة واحدة في الصفحة') : bad('مكتبة الأشكال ناقصة');
/* ⚠️ `<use>` مش بيورّث خصائص الخط من `icon()` — لازم تتحط على الـsvg المضيف. */
($stampSvgCss = preg_match('~\.hm-stamp svg\{[^}]*\}~s', $mcssC, $m) ? $m[0] : '')
    && str_contains($stampSvgCss, 'fill:none') && str_contains($stampSvgCss, 'stroke:currentColor')
    ? ok('★ وخصائص الخط على الـsvg المضيف (وإلا يترسم معبّى أسود)')
    : bad('الختم هيترسم معبّى — خصائص الخط ناقصة');
$stampCss = preg_match('~\.hm-stamp\{[^}]*\}~s', $mcssC, $m) ? $m[0] : '';
str_contains($stampCss, 'pointer-events:none')
    ? ok('★ وما بيلقطش الضغطات (زرار التثبيت تحته)') : bad('الختم هيسرق ضغطة زرار التثبيت');
str_contains($mcssC, '.hm-stub-body{position:relative; z-index:1}')
    ? ok('وجسم الكارت فوق الختم') : bad('النص هيتلغبط مع الختم');

/* ⚠️ شريط الحافة بيقول النوع **والحالة** مع بعض — توكنان مستقلان بدل ٨ قواعد. */
str_contains($mcssC, '--stub-edge') && str_contains($mcssC, '--stub-cap')
    ? ok('★ شريط الحافة: لون النوع + قبّة الحالة') : bad('شريط الحافة لسه بلون واحد');
foreach (['is-done', 'is-pinned', 'is-new'] as $st) {
    str_contains($mcssC, ".hm-stub.{$st}{--stub-cap")
        ? ok("وحالة {$st} ليها لون في الشريط") : bad("حالة {$st} بلا لون في الشريط");
}
str_contains($homeC, "' is-done' : ''")
    ? ok('والرحلة المخلَّصة بتاخد كلاسها من الخادم') : bad('is-done مش بتتحط على الكارت');

/* التوكيل بارز — كان مدفونًا في القايمة المطويّة. */
str_contains($homeC, 'class="hm-agency"')
    ? ok('★ التوكيل الملاحي بارز في الكارت') : bad('التوكيل لسه مدفون');
(!str_contains($homeC, "'🏢 التوكيل'"))
    ? ok('واتشال من القايمة المطويّة (مش مكرَّر)') : bad('التوكيل متكرّر في مكانين');
str_contains($mcssC, '.hm-agency{') ? ok('وله تنسيقه') : bad('.hm-agency بلا CSS');

/* المبرّد — بيغلب ختم الميناء لأنه شغل تشغيلي مختلف. */
str_contains($homeC, '$isReefer = function')
    ? ok('★ كشف الحاويات المبرّدة موجود') : bad('كشف المبرّد ناقص');
str_contains($homeC, "\$cold ? 'snowflake' :")
    ? ok('والمبرّد بيغلب ختم الميناء') : bad('المبرّد مش بياخد ختمه');
str_contains($mcssC, '.hm-cold{') && str_contains($mcssC, '.hm-stub.is-cold .hm-stamp')
    ? ok('وله وسم أزرق + ختم مميّز') : bad('وسم المبرّد بلا تنسيق');

/* ⚠️ سلوكي: «40 مبرّد» فيها شدّة و«مبرد» من غيرها — لازم يتطابقوا. */
$k = \App\Models\Maintenance::normkey('2×40 مبرّد');
str_contains($k, 'مبرد')
    ? ok('★ و«40 مبرّد» بالشدّة بتتطابق فعلاً') : bad('الشدّة بتكسر كشف المبرّد');
!str_contains(\App\Models\Maintenance::normkey('40 هاي كيوب'), 'مبرد')
    ? ok('و«هاي كيوب» ما بتتلقّطش غلط') : bad('تصنيف تاني اتلقّط كمبرّد');

// ══════════════════════════════════════════ ⑦د نسيج الكارت
sec('⑦د نسيج «ورق الأمان» + الميل بلون النوع');

/* ⚠️ لازم نشيل تعليقات CSS قبل أي بحث نصّي — **التعليق اللي بيوثّق القاعدة
   بيذكر الممنوع نفسه** فبيدّي فشلًا كاذبًا (اتصاد فعليًّا هنا على `feTurbulence`).
   نفس مصيدة `code_only()` الموثَّقة في `verify_security` و`verify_ux`. */
$mcssD = preg_replace('~/\*.*?\*/~s', '', src('public/assets/css/m.css'));

/* ⚠️ **تدرّجات CSS بس** — فلتر الضوضاء (`feTurbulence`) بيتعاد حسابه مع كل
   تمرير على ٣٧ كارت، وموبايل المندوب رخيص وفي الشمس. */
(!str_contains($mcssD, 'feTurbulence') && !str_contains($mcssD, 'filter:url('))
    ? ok('★ ولا فلتر ضوضاء (بيتقل على التمرير)') : bad('فلتر ضوضاء في نسيج الكارت');
(!preg_match('~\.hm-stub\{[^}]*background-image:[^}]*url\(~s', $mcssD))
    ? ok('وولا صورة في النسيج') : bad('صورة في نسيج الكارت');
str_contains($mcssD, 'repeating-linear-gradient')
    ? ok('النسيج تدرّجات متكرّرة') : bad('النسيج ناقص');

/* ⚠️ الميل بيختلف مع النوع — النسيج **بيحمل معنى** لا زخرفة. */
str_contains($mcssD, '--stub-tilt:45deg') && str_contains($mcssD, '--stub-tilt:135deg')
    ? ok('★ والميل معكوس بين صادر ووارد (بيفرّق من بعيد)') : bad('نفس الميل للنوعين');
str_contains($mcssD, '--stub-weave:rgba(178,106,27') && str_contains($mcssD, '--stub-weave:rgba(14,124,134')
    ? ok('ولون النسيج من لون كل نوع') : bad('لون النسيج واحد للنوعين');

/* ⚠️ الشفافية سقفها ٤٪ — فوق كده بتقلّل تباين النص العربي الصغير في الشمس. */
preg_match_all('~--stub-weave:rgba\([^)]*,\s*\.(\d+)\)~', $mcssD, $wm);
$maxA = 0; foreach ($wm[1] as $a) { $maxA = max($maxA, (float) ('.' . $a)); }
($maxA > 0 && $maxA <= 0.04)
    ? ok('★ وشفافيته ' . ($maxA * 100) . '٪ (السقف ٤٪ — الوضوح في الشمس)')
    : bad('نسيج أقوى من السقف: ' . ($maxA * 100) . '٪');

/* ⚠️ الختم جوّه الكارت بالكامل — الإزاحة السالبة مع `overflow:hidden` كانت
   بتقصّه فيقرا كغلطة مش كعلامة مائية (اتصاد في لقطة فعلية). */
$stampBox = preg_match('~\.hm-stamp\{[^}]*\}~s', $mcssD, $m) ? $m[0] : '';
($stampBox !== '' && !preg_match('~inset-(block-start|inline-end):-~', $stampBox))
    ? ok('★ والختم جوّه الكارت (مش مقصوص من الحافة)') : bad('الختم بيتقص من حافة الكارت');

/* ⚠️ `background` الاختصار بيمسح صف التقب — لازم `background-color`. */
(!preg_match('~\.hm-stub-go:active\{background:~', $mcssD))
    ? ok('وحالة الضغط ما بتمسحش صف التقب') : bad('background الاختصار هيمسح التقب');

// ══════════════════════════════════════════ ⑦هـ شكل الحاوية
sec('⑦هـ شكل الحاوية: العدد×المقاس جوّه الرسمة');

$homeE = src('app/Views/m/home.php');
$mcssE = preg_replace('~/\*.*?\*/~s', '', src('public/assets/css/m.css'));

/* ⚠️ **سلوكي على القيم الحقيقية اللي في القاعدة** — مش فحص وجود دالة. */
$cases = [
    '2×20 قدم'      => ['dry',    '2×20'],
    '4×40 قدم'      => ['dry',    '4×40'],
    '2×40 مبرّد'    => ['reefer', '2×40'],
    '3×40 هاي كيوب' => ['hc',     '3×40'],
    '1×تانك'        => ['tank',   '1'],
    '1×جامبو'       => ['jumbo',  '1'],
    '1×أوبن توب'    => ['open',   '1'],
    '2×فلات راك'    => ['flat',   '2'],
];
$bad = [];
foreach ($cases as $in => [$kind, $label]) {
    $a = container_art($in);
    if (!$a || $a['kind'] !== $kind || $a['label'] !== $label) { $bad[] = $in; }
}
/* ⚠️ التصنيف **كامل جوّه الرسمة** (قرار المالك بعد ما اترفض تقسيمه) —
   والحاوية بتتوسّع عشانه. لو صغّرت المقاسات الرقم بيتداخل تاني. */
(!str_contains($homeE, "\$art['badge']") && !str_contains($homeE, "\$art['inside']"))
    ? ok('★ والتصنيف كامل جوّه الرسمة (مش متقسّم)') : bad('التصنيف رجع متقسّم');
preg_match('~\.hm-box\.sz-20\{width:(\d+)px~', $mcssE, $z20);
(!empty($z20[1]) && (int) $z20[1] >= 88)
    ? ok('★ والعشرين واسعة كفاية للتصنيف (' . $z20[1] . 'px)')
    : bad('العشرين ضيّقة — «١٢×٤٠» هتتداخل مع الأضلاع');
!$bad ? ok('★ الـ٨ تصنيفات بتتفكّك لشكلها ورقمها صح')
      : bad('تفكيك غلط في: ' . implode(' · ', $bad));

/* ⚠️⚠️ **ممنوع نخترع شكلًا لوصف مش عارفينه** — شكل غلط بيتقري كمعلومة غلط.
   القيم دي موجودة فعلاً في بوالص الإنتاج. */
$unknown = ['1×10 طر د', '1×دبابة', '4 حاوية', '2×19 طرد', ''];
$leaked = array_values(array_filter($unknown, fn($u) => container_art($u) !== null));
!$leaked ? ok('★ والوصف المجهول بيرجّع null (شريحة نصّية زي الأول)')
         : bad('اتخترع شكل لوصف مجهول: ' . implode(' · ', $leaked));

/* المقاس بيحدّد طول الرسمة — العشرين لازم تطلع **أقصر** من الأربعين. */
preg_match('~\.hm-box\.sz-20\{width:(\d+)px~', $mcssE, $w20);
preg_match('~\.hm-box\.sz-40\{width:(\d+)px~', $mcssE, $w40);
(!empty($w20[1]) && !empty($w40[1]) && (int) $w20[1] < (int) $w40[1])
    ? ok('★ والعشرين أقصر من الأربعين (' . $w20[1] . ' مقابل ' . $w40[1] . ' بكسل)')
    : bad('العشرين والأربعين بنفس الطول — المقاس مش بيبان');
str_contains($homeE, 'preserveAspectRatio="none"')
    ? ok('والرسمة بتتمدّ بالعرض مش بتتشوّه بالنسبة') : bad('الرسمة هتحافظ على نسبتها فالطول ما يبانش');

/* نفس مبدأ الأختام: الشكل مرّة واحدة والكروت بتشاور. */
str_contains($homeE, '<use href="#box-')
    ? ok('★ والكروت بتشاور على شكل مشترك') : bad('الشكل مرسوم كامل في كل حاوية');
str_contains($homeE, 'container_box_svg($bk)')
    ? ok('والمكتبة بترسم المستعمل بس') : bad('مكتبة الأشكال ناقصة');

/* ⚠️ `<use>` ما بيورّثش خصائص الرسم — من غيرها الحاوية بتترسم معبّاة سودا. */
$artCss = preg_match('~\.hm-box-art\{[^}]*\}~s', $mcssE, $m) ? $m[0] : '';
(str_contains($artCss, 'fill:none') && str_contains($artCss, 'stroke:currentColor'))
    ? ok('★ وخصائص الرسم على الـsvg المضيف') : bad('الحاوية هتترسم معبّاة سودا');

/* ⚠️ «مبرّد» مرّة واحدة في الكارت — الوسم في سطر الميناء + الرسمة الزرقا
   بيقولوها، فكتابتها جنب الشكل كمان = نفس الخبر مرتين. */
str_contains($homeE, "\$art['kind'] !== 'reefer'")
    ? ok('★ و«مبرّد» ما بتتكتبش مرتين في نفس الكارت') : bad('«مبرّد» متكرّرة في الكارت');

foreach (['hm-box', 'hm-box-art', 'hm-box-lbl', 'hm-box-sub'] as $c) {
    str_contains($mcssE, '.' . $c) ? ok(".{$c} معرَّف في m.css") : bad(".{$c} بلا CSS");
}

// ══════════════════════════════════════════ ⑦و صفحة تفاصيل الإذن
sec('⑦و صفحة تفاصيل الإذن + رقم الإذن جوّه الشريحة');

$homeF  = src('app/Views/m/home.php');
$infoF  = src('app/Views/m/permit_info.php');
$mcssF  = preg_replace('~/\*.*?\*/~s', '', src('public/assets/css/m.css'));
$routesF = src('public/index.php');

/* ⚠️ حرفي قبل الجشع `/m/permit/{id}` (تحذير ٤). */
$aInfo = strpos($routesF, "'/m/permit/{id}/info'");
$aGrd  = strpos($routesF, "'/m/permit/{id}',");
($aInfo !== false && $aGrd !== false && $aInfo < $aGrd)
    ? ok('مسار التفاصيل حرفي قبل الجشع') : bad('مسار التفاصيل ناقص أو بعد الجشع');

/* الكارت كله بقى رابط — الضغط عليه كان ما بيعملش حاجة (بلاغ المالك). */
str_contains($homeF, 'class="hm-stub-open"')
    ? ok('★ الكارت كله بقى رابط للتفاصيل') : bad('الضغط على الكارت لسه ما بيعملش حاجة');
/* ⚠️ فورم جوّه رابط ماركب باطل — التغطية هي البديل، وزرار التثبيت لازم فوقها. */
$pinZ = preg_match('~\.hm-pin-f\{[^}]*\}~s', $mcssF, $m) ? $m[0] : '';
(str_contains($pinZ, 'z-index:3') && str_contains($mcssF, '.hm-stub-open{position:absolute'))
    ? ok('★ وزرار التثبيت فوق التغطية (وإلا الضغط عليه بيفتح التفاصيل)')
    : bad('زرار التثبيت تحت التغطية — هيتسرق منه الضغط');

/* رقم الإذن جوّه شريحة النوع — كان بياخد سطرًا كامل من كارت طويل. */
str_contains($homeF, '<span class="hm-kind"><?= e($p[\'type_label\']) ?> <b>')
    ? ok('★ ورقم الإذن جوّه شريحة النوع') : bad('رقم الإذن لسه في سطر لوحده');
(!str_contains($homeF, 'class="hm-ref"'))
    ? ok('وسطر الرقم القديم اتشال') : bad('سطر الرقم القديم لسه موجود');
str_contains($mcssF, '.hm-kind b{') ? ok('وله تنسيقه البارز') : bad('.hm-kind b بلا CSS');

/* ⚠️⚠️ قاعدة v35 على أقوى صورها هنا: الصفحة بتعرض بنود **كل** المناديب. */
$model = code_only('app/Models/RepPortal.php');
$fnBills = preg_match('~function permitBills\(.*?\n    \}~s', $model, $m) ? $m[0] : '';
($fnBills !== '' && !str_contains($fnBills, 'SELECT *')
 && !preg_match('~\bfreight\b|\bcustody\b|\bdiscount\b~', $fnBills))
    ? ok('★ permitBills بتختار الأعمدة بالاسم — ولا عمود مالي')
    : bad('permitBills بتسرّب أعمدة مالية');

$fnEnt = preg_match('~function permitEntries\(.*?\n    \}~s', $model, $m) ? $m[0] : '';
(!preg_match('~actual_amount|actual_unit~', $fnEnt))
    ? ok('★ وpermitEntries مفيهاش سعر العميل (قاعدة v35)') : bad('سعر العميل في استعلام البنود');
str_contains($fnEnt, "\$row['amount'] = null")
    ? ok('★ ومبلغ بند الزميل بيتخفي **في الموديل** لا في العرض')
    : bad('مبلغ الزملاء مكشوف — أو الإخفاء في العرض (شاشة جديدة هتفوّته)');

/* ⚠️ الصفحة قراءة فقط بالكامل. */
/* ⚠️ الصفحة كانت «صفر فورم»، وبقى فيها **رفع ورقة** بطلب المالك (v64).
   الفحص اتحوّل من «مفيش فورم» لـ«الفورمات هي دي بالظبط» — ولا يزيد. */
$formActions = [];
if (preg_match_all('~<form[^>]*action="([^"]*)"~', $infoF, $fm)) { $formActions = $fm[1]; }
$allowed = array_filter($formActions, fn($a) =>
    str_contains($a, "/file'") || str_contains($a, 'permit-file/'));
(count($formActions) === count($allowed) && count($formActions) <= 2)
    ? ok('★ وفورمات الصفحة هي رفع الورقة وشيلها بس') : bad('فورم غير متوقّع في صفحة التفاصيل');
str_contains($infoF, 'assets/css/m.css')
    ? ok('وبتحمّل m.css') : bad('بتحمّل ملف تنسيق غلط');

foreach (['pi-hero', 'pi-h', 'pi-facts', 'pi-car', 'pi-car-plate', 'pi-entry',
          'pi-who', 'pi-acts', 'pi-boxes', 'pi-body'] as $c) {
    str_contains($mcssF, '.' . $c) ? ok(".{$c} معرَّف في m.css") : bad(".{$c} بلا CSS");
}

$a = container_art('2×20 قدم');
($a && $a['label'] === '2×20' && !isset($a['badge']) && !isset($a['inside']))
    ? ok('★ والتصنيف رقم واحد كامل («٢×٢٠») بلا تقسيم') : bad('التصنيف لسه متقسّم');

// ══════════════════════════════════════════ ⑧ الكلاسات والتنسيق
sec('⑧ كل كلاس بيتطبع له CSS في m.css (مصيدة .table-mobile-scroll)');

$mcss = src('public/assets/css/m.css');
foreach (['hm-fchips', 'hm-pin', 'hm-pin-f', 'hm-otherday', 'hm-daynav',
          'hm-readonly', 'hm-daysum'] as $c) {
    str_contains($mcss, '.' . $c)
        ? ok(".{$c} معرَّف في m.css") : bad(".{$c} بيتطبع وملوش CSS — هيقع على الشكل الافتراضي بالسكوت");
}
str_contains($mcss, '.hm-stub.is-pinned')
    ? ok('.hm-stub.is-pinned معرَّف') : bad('حالة المثبَّت بلا شكل');

/* ⚠️ الأيقونة الناقصة بتقع على دايرة فاضية **بالسكوت** (مصيدة موثَّقة). */
foreach (['star', 'anchor', 'eye', 'clock'] as $ic) {
    icon($ic, 14) !== icon('__لا_يوجد__', 14)
        ? ok("أيقونة {$ic} موجودة في icon()") : bad("أيقونة {$ic} ناقصة — هتقع على شكل افتراضي");
}

/* ⚠️ مُطبِّق واحد للبحث والفلترة: لو كل واحد أخفى لوحده، الكتابة في البحث
   بترجّع اللي الفلتر خبّاه (والعكس). */
$home = src('app/Views/m/home.php');
(substr_count($home, 'function applyPermitFilters') === 1)
    ? ok('★ مُطبِّق واحد للبحث والفلترة') : bad('البحث والفلترة بيشتغلوا كلٌّ لوحده');
str_contains($home, "search.addEventListener('input', applyPermitFilters)")
    ? ok('والبحث بيمرّ عليه') : bad('البحث ما بيمرّش على المُطبِّق');

echo "\n" . str_repeat('═', 62) . "\n";
echo "اترجّع كل شيء (rollback) — مفيش أي أثر.\n";
near('رصيد الخزنة قبل = بعد', Closing::currentBalance(), $treasuryBefore);
if (RepCustody::available()) {
    near('وإجمالي عهد المناديب قبل = بعد', RepCustody::totalOutstanding(), $repsBefore);
}
echo "\nنجح: $pass | فشل: $fail\n";
exit($fail > 0 ? 1 : 0);
