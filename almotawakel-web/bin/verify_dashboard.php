<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص إعادة بناء لوحة التحكم — المرحلة ١١ (2026-07-25).
 *
 * الداشبورد كانت ٩ أقسام ورا بعض (٧٢٩ سطر) والمالك بينزل ٤ شاشات عشان يوصل
 * لآخرها، ولوحة المناديب جوّاها كانت **بتكرّر صفحة `/rep-custody` كلها**.
 * البناء الجديد ٤ طبقات: ① اليوم · ② محتاج منك · ③ ٤ أرقام · ④ الشغل الجاري.
 *
 * ⚠️ الفحص **بيرندر الصفحة ونوافذ التفاصيل السبعة فعليًّا** ببيانات حقيقية —
 *    مش فحص نصّي — عشان يمسك الفشل الصامت الموثَّق في `Controller::render`
 *    (`extract($data, EXTR_SKIP)` بيتخطّى أي مفتاح اسمه `data`، فالصفحة بتطلع
 *    غلط **بلا أي خطأ PHP**).
 *
 * أهم المحروس:
 *   • **ممنوع رقم أصمّ** — كل رقم في الصفحة معاه `data-drill`.
 *   • **البوب-أب القديم اتشال** — `#dModal` و`#popStore` كانوا بيرندروا تفاصيل
 *     كل الأرقام مع **كل** فتحة للصفحة حتى لو ما اتفتحتش.
 *   • **مصدر واحد للأرقام** — «محتاج منك» و«الجرس» و«شارات القائمة» بيقروا
 *     من `Notification::feed()` نفسها.
 *   • **مُصيّر صف واحد** للصفحة والنافذة (`_rows.php`) — مستحيل الشكل يفترق.
 *   • **القائمة البيضا** لنوع التفاصيل — مش اسم جدول جاي من المستخدم.
 *   • **العزل المالي** — رصيد الخزنة قبل = بعد (الصفحة قراءة فقط).
 *
 * قراءة فقط بالكامل — ما بيكتبش أي صف.
 * التشغيل:  php bin/verify_dashboard.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\DashboardController;
use App\Core\Access;
use App\Core\Database;
use App\Models\Closing;
use App\Models\Notification;

$pass = 0; $failures = [];
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $failures; $failures[] = $m; echo "  ✗ $m\n"; }
function chk(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function sec(string $m): void { echo "\n$m\n" . str_repeat('─', 62) . "\n"; }

/** يشيل التعليقات قبل الفحص — تعليق بيوثّق قاعدة قديمة كان بيدّي إنذارًا كاذبًا. */
function php_code(string $src): string
{
    $src = preg_replace('~<!--.*?-->~s', '', $src) ?? $src;
    return preg_replace('~/\*.*?\*/|(?<!:)//[^\n]*~s', '', $src) ?? $src;
}

echo "فحص لوحة التحكم — المرحلة ١١\n" . str_repeat('=', 62) . "\n";

$root  = dirname(__DIR__);
$idx   = (string) file_get_contents($root . '/app/Views/dashboard/index.php');
$idxC  = php_code($idx);
$drillV = (string) file_get_contents($root . '/app/Views/dashboard/_drill.php');
$rowsV = (string) file_get_contents($root . '/app/Views/dashboard/_rows.php');
$ctrl  = php_code((string) file_get_contents($root . '/app/Controllers/DashboardController.php'));
$routes = (string) file_get_contents($root . '/public/index.php');

$treasuryBefore = Closing::currentBalance();

// ═══ ① الأقسام الستة
sec('① البناء الجديد — ستة أقسام، كل واحد بيجاوب سؤالًا واحدًا');
chk(str_contains($idxC, "partial('day_bar'"), '① اليوم: شريط يوم العمل الموحّد');
chk(str_contains($idxC, 'خزنة المكتب') && str_contains($idxC, "'facts'"),
    'ورصيد الخزنة ظاهر جنب التاريخ');
chk(str_contains($idxC, 'Notification::feed()'), '② محتاج منك: من مجمّع الإشعارات');
/* ③ **سطر معادلة الخزنة** — طلب المالك (2026-08-17): «رصيد الخزنة دخلها كام
   ونزل كام للمكتب». الشاشة كانت بتدّي رقمًا واحدًا أصمّ. */
chk(str_contains($idxC, 'd-eq') && str_contains($idxC, 'فتحنا بـ') && str_contains($idxC, 'نزل للمكتب'),
    '⚠️ ③ الخزنة: سطر معادلة (فتحنا · دخلها · نزل للمكتب · مصروفات · عهدة سواقين)');
chk(str_contains($idxC, "partial('stat_grid'"), '④ حركة اليوم: شبكة `stat_grid`');
/* ٤ كروت لليوم (عربيات · إيصالات · اتفتحت · محتاجة تتقفل) + ٣ للمناديب
   (نزل · اتصرف · لسه ما سوّاش) = ٧. */
chk(substr_count($idxC, "partial('stat',") === 7,
    'سبع بطاقات رقم (٤ لحركة اليوم + ٣ للمناديب)');
chk(str_contains($idxC, 'عربيات خرجت') && str_contains($idxC, 'محتاجة تتقفل'),
    '⚠️ وفيها «عربيات خرجت» و«محتاجة تتقفل» — طلب المالك المباشر');
chk(str_contains($idxC, 'لسه ما سوّاش'),
    '⚠️ ⑤ المناديب: عدّاد «لسه ما سوّاش» — الحكم مش الرقم الخام');
chk(str_contains($idxC, 'الشغل الجاري') && str_contains($idxC, 'ناقصها إيصالات'),
    '⑥ الشغل الجاري: فيه «ناقصها إيصالات» (الموديل المدرَّب)');
chk(substr_count($idxC, "partial('panel'") === 4,
    'وأربع لوحات بس (عهدة المناديب · ناقصها إيصالات · جاهز للفوترة · فلوس اليوم)');

// ═══ ② اللي اتشال عن قصد
sec('② اللي اتشال — والسبب');
chk(!str_contains($idxC, 'd-hero'),
    'ترويسة التحية والوقت اتشالت (كانت بتاخد شاشة وما بتقولش حاجة)');
chk(!str_contains($idxC, 'rp-panel') && !str_contains($idxC, 'rp-strip'),
    'لوحة المناديب المفصّلة اتشالت (كانت بتكرّر صفحة /rep-custody كلها)');
/* ⚠️ **الإدخال السريع اتشال بالكامل (2026-08-17)** — أداة موظف إدخال (٢٠٠
   سطر JS + شاشتَي اختيار) كانت قاعدة في لوحة إدارة. */
chk(!str_contains($idxC, 'quickEntry') && !str_contains($idx, 'qe-modal'),
    '⚠️ الإدخال السريع اتشال من الشاشة (أداة إدخال مش لوحة إدارة)');
chk(!str_contains($ctrl, 'quickOptions') && !str_contains($ctrl, 'quickEntries'),
    'وتوابعه اتشالت من الكنترولر');
chk(!str_contains($routes, 'dashboard/quick-'),
    'ومساراته اتشالت من الراوتر — مفيش مسار بلا تابع');
/* ⚠️ «أذونات اتحرّك عليها» اتشالت: كانت بتكرّر لوح «فلوس اليوم» بالحرف. */
chk(!str_contains($idxC, 'dash_touched_row') && !str_contains($ctrl, 'permitsTouched'),
    '⚠️ لوح «أذونات اتحرّك عليها» اتشال (كان بيكرّر «فلوس اليوم»)');
chk(!str_contains($ctrl, 'recentActivity'),
    '⚠️ «آخر ما اتسجّل» اتشال — السجل الكامل في /audit بفلاتر أكتر');
chk(!str_contains($idxC, 'المتبقي للعملاء'), 'ومفيش «المتبقي للعملاء» (قرار المالك من المرحلة ٤)');
chk(!str_contains($idxC, 'Chart') && !str_contains($idxC, 'chart.js'), 'ومفيش رسوم — مكانها /reports');

// ═══ ③ ⚠️ ممنوع رقم أصمّ
sec('③ ⚠️ ممنوع رقم أصمّ (قاعدة المالك الدائمة)');
/* ٧ كروت + لوحان بنافذة تفاصيل (ناقصها إيصالات · جاهز للفوترة) = ٩. */
chk(substr_count($idxC, "'drill' =>") === substr_count($idxC, "partial('stat',") + 2,
    'كل رقم معاه مصدر تفاصيل (+ لوحان بنافذة تفاصيل)');
/* ⚠️ **أجزاء المعادلة كمان** — بس الفحص عليها في القسم ⑥ على **المخرَج
   المرندَر** مش على المصدر: الأجزاء بتترسم في حلقة، فعدّ النص في الملف
   بيقيس السطور المكتوبة لا الأزرار الطالعة. */
chk(str_contains($idxC, 'd-eq-part') && str_contains($idxC, "\$drill('safe_day')"),
    '⚠️ وأجزاء معادلة الخزنة بتفتح تفاصيلها');
chk(!preg_match('/class="d-kpi(?![^>]*data-drill)/', $idxC), 'مفيش رقم مرسوم بالإيد بلا تفاصيل');
chk(!str_contains($idxC, 'id="dModal"') && !str_contains($idxC, 'id="popStore"'),
    '⚠️ البوب-أب المحلي اتشال — كان بيرندر تفاصيل كل الأرقام مع كل فتحة');
chk(!str_contains($idxC, 'data-pop='), 'ومفيش خطّاف `data-pop` باقي بلا محرّك');

// ═══ ④ مصدر واحد للأرقام والصفوف
sec('④ مفيش تعريف تاني لأي رقم ولا لأي شكل');
chk(str_contains($idx, "require __DIR__ . '/_rows.php'") &&
    str_contains($drillV, "require __DIR__ . '/_rows.php'"),
    '⚠️ الصفحة والنافذة بيستخدموا **نفس** مُصيّر الصف (`_rows.php`)');
/* ══════════════════════════════════════════════════════════════════════
   ⚠️ قسم «حركة اليوم» — طلب المالك بالحرف (2026-07-27):
   «اليوم ده الأذونات اللي اتضاف لها إيصالات أو مصروفات من النظام أو المندوب
    **حتى لو لم يتم اعتمادها** · الأذونات الجديدة اللي اتفتحت · كل مندوب له
    عهدة كام اليوم ده. **مش عايز حسابات تراكمية أو مجمعة.**»
   ══════════════════════════════════════════════════════════════════════ */
sec('④ب حركة اليوم — بعدسة اليوم بلا أي تراكمي');

$metrics = php_code((string) file_get_contents($root . '/app/Models/Metrics.php'));

/* ① **تعريف «الإذن خلص شغله»** — قرار المالك (2026-08-17): «خرج كل السيارات
   الخاصة به + الإيصالات من متوقّع الإيصالات مكتملة مش ناقصها حاجة».
   ⚠️ **فحص سلوكي مش نصّي:** التعريف مكتوب في `PermitReadiness` لوحده، والفحص
      بيقيس إنه بيشتغل فعلاً — مش إن الكلمات موجودة في الملف. */
chk(str_contains($ctrl, 'PermitReadiness::scan'),
    '⚠️ تعريف «خلص شغله» من `PermitReadiness` — مش شرط مكتوب في الشاشة');
chk(!preg_match('~conts?_done\s*>=|SUM\(container_count\)~i', $idxC),
    'ومفيش شرط حاويات مكتوب بالإيد في الشاشة');

/* الشرطان **لازم يكونا معًا** — أي واحد لوحده بيدّي حكمًا غلط. */
chk(\App\Models\PermitReadiness::carsDone(3, 3) === true,  'خرجت ٣ من ٣ ⇒ السيارات خلصت');
chk(\App\Models\PermitReadiness::carsDone(3, 2) === false, 'خرجت ٢ من ٣ ⇒ لسه');
chk(\App\Models\PermitReadiness::carsDone(3, 5) === true,  'وخرجت ٥ من ٣ ⇒ خلصت (الزيادة مش نقص)');
/* ⚠️ الإذن اللي مالوش عدد حاويات مسجَّل **مش خلصان** — ده إذن ناقص بياناته،
   والحكم عليه بالاكتمال بيحطّه في «محتاج يتقفل» ومحدش يعرف كان مطلوب منه كام. */
chk(\App\Models\PermitReadiness::carsDone(0, 0) === false,
    '⚠️ وإذن بلا عدد حاويات مسجَّل **مش** خلصان (بياناته ناقصة مش شغله خلص)');

$vReady = \App\Models\PermitReadiness::verdict(['cars_done' => true,  'missing_cnt' => 0]);
$vGap   = \App\Models\PermitReadiness::verdict(['cars_done' => true,  'missing_cnt' => 2]);
$vRun   = \App\Models\PermitReadiness::verdict(['cars_done' => false, 'missing_cnt' => 0]);
chk($vReady === \App\Models\PermitReadiness::READY,    'سيارات خلصت + إيصالات مكتملة ⇒ محتاج يتقفل');
chk($vGap   === \App\Models\PermitReadiness::RECEIPTS, 'سيارات خلصت + إيصالات ناقصة ⇒ ناقصه إيصالات');
chk($vRun   === \App\Models\PermitReadiness::RUNNING,  '⚠️ وسيارات ما خلصتش ⇒ لسه شغّال **مهما كانت الإيصالات**');

/* ② **الكارت والقايمة من مسح واحد** — لو كل واحد حسب بنفسه كان ممكن الكارت
   يقول ٣ والقايمة تقول ٤ بلا أي خطأ ظاهر (عطل «كارت يقول رقم وكشف يقول
   رقم تاني» المحذَّر منه في CLAUDE.md). */
$scan = \App\Models\PermitReadiness::scan(date('Y-m-d'));
chk(count($scan['ready']) === $scan['counts']['ready'],
    '⚠️ عدّاد «محتاجة تتقفل» = طول القايمة بالظبط (' . $scan['counts']['ready'] . ')');
chk(count($scan['gaps']) === $scan['counts']['gaps'],
    '⚠️ وعدّاد «ناقصها إيصالات» = طول قايمته (' . $scan['counts']['gaps'] . ')');
chk($scan['counts']['ready'] + $scan['counts']['gaps'] + $scan['counts']['running']
    === $scan['counts']['open'],
    'والتلاتة بيجمعوا كل المفتوح — مفيش إذن ضايع بين الحالات');
/* الإيصالات من `ReceiptModel::combine` — نفس اللي شاشة الإذن والقايمة بيعرضوه. */
chk(str_contains(php_code((string) file_get_contents($root . '/app/Models/PermitReadiness.php')),
                 'ReceiptModel::combine'),
    '⚠️ والإيصالات الناقصة من `ReceiptModel::combine` — نفس مصدر شاشة الإذن');

/* ③ **حكم عهدة المندوب** — «سوّى ولا ما سواش» (طلب المالك). المصدر الوحيد
   `RepCustody::dayVerdict()`، والشاشة ممنوعة تكتب الشرط بإيدها. */
$vd = static fn (array $r): string => \App\Models\RepCustody::dayVerdict($r)['key'];
chk($vd(['deposits' => 5000, 'spent' => 5000, 'balance' => 0, 'pending' => 0]) === 'settled',
    '⚠️ نزل ٥٠٠٠ وصرف ٥٠٠٠ ⇒ «سوّى عهدته»');
chk($vd(['balance' => 800, 'pending' => 0, 'pocket' => 800]) === 'holding',
    'وفاضل معاه ٨٠٠ ⇒ «لسه معاه»');
chk($vd(['balance' => 800, 'pending' => 300, 'pocket' => 500]) === 'pending',
    '⚠️ وفيه بند مستنّي اعتمادك ⇒ ده بيغلب (تصرّف مطلوب منك مش منه)');
/* ⚠️ الرصيد السالب مش صفر ومش خطأ — المندوب فرش من جيبه (قاعدة docs/16 §10.5). */
chk($vd(['balance' => -450, 'pending' => 0, 'pocket' => -450]) === 'owed',
    '⚠️ ورصيد سالب ⇒ «له عند المكتب» — مش بيتقصّ على صفر');
chk(str_contains($rowsV, 'dayVerdict'),
    'والصف بيقرا الحكم من الدالة — مش بيكتب الشرط بإيده');
chk(str_contains($idxC, 'dayVerdict'),
    'وكارت «لسه ما سوّاش» بيعدّ بنفس الدالة — فمستحيل يخالف الجدول');

/* ④ **معادلة الخزنة بتقفل** — أي رقم غلط بيبان لأن الطرفين ما بيتساووش.
   ⚠️ `office` جزء من `expenses` مش رقم زيادة عليها (تحذير `Closing`). */
$eqDay  = \App\Models\Closing::dayView(date('Y-m-d'));
$eqOff  = \App\Models\Closing::dayOfficeDeposits(date('Y-m-d'));
$eqCalc = $eqDay['opening'] + $eqDay['topups'] - ($eqDay['expenses'] - $eqOff) - $eqOff - $eqDay['driver'];
chk(abs($eqCalc - $eqDay['closing']) < 0.02,
    '⚠️ معادلة الخزنة بتقفل: فتح + دخل − نزل للمكتب − مصروفات − عهدة سواقين = قفل');
chk($eqOff <= $eqDay['expenses'] + 0.005,
    '⚠️ و«نزل للمكتب» جوّه «المصروفات» مش زيادة عليها (وإلا الفلوس تتقري مرتين)');

/* ⑤ **«خرج كام عربية»** — العدّاد والقايمة من نفس الشرط بالحرف. */
$carDay = (string) Database::scalar('SELECT MAX(bill_date) FROM bawalis WHERE is_legacy = 0');
if ($carDay !== '') {
    chk(\App\Models\Metrics::carsOutCount($carDay) === count(\App\Models\Metrics::carsOut($carDay, 500)),
        '⚠️ عدّاد «عربيات خرجت» = طول قايمته بالظبط (' . \App\Models\Metrics::carsOutCount($carDay) . ')');
    $legacy = 0;
    foreach (\App\Models\Metrics::carsOut($carDay, 500) as $c) {
        if (($c['bill_date'] ?? '') !== $carDay) { $legacy++; }
    }
    chk($legacy === 0, "ولا بوليصة من يوم تاني في قايمة اليوم (لقينا {$legacy})");
}

/* ══════════════════════════════════════════════════════════════════════
   ⚠️⚠️ **احترام التاريخ ١٠٠٪ — بلاغ المالك (2026-07-28):**
   «جدول فلوس ٢٧ بيعرض بنود من ٢٦ … ضرورة احترام التاريخ ١٠٠٪ والأذونات أيضًا».
   تلات تسريبات اتصادوا فعليًّا واتقفلوا هنا.
   ══════════════════════════════════════════════════════════════════════ */
sec('④ج ⚠️ احترام يوم العمل — صفر تسريب من أيام تانية');

$day = date('Y-m-d');

/* ① لوح «فلوس اليوم»: كان بياخد السجل التراكمي (`entry_date <= $date`) بلا
   فلترة، فكان بيعرض بنود أيام فاتت. متحقَّق: ٤٠ بند منهم ٥ من أيام تانية. */
chk(str_contains($ctrl, 'Metrics::expenseReceiptFeed($date, 40, true)'),
    '⚠️ لوح «فلوس اليوم» بياخد **اليوم ده بالظبط** (`$exactDay = true`)');
$leak = 0;
foreach (\App\Models\Metrics::expenseReceiptFeed($day, 60, true) as $r) {
    if (($r['d'] ?? null) !== $day) { $leak++; }
}
chk($leak === 0, "ولا بند من يوم تاني في سجل اليوم (لقينا {$leak})");
$loose = 0;
foreach (\App\Models\Metrics::expenseReceiptFeed($day, 60, false) as $r) {
    if (($r['d'] ?? null) !== $day) { $loose++; }
}
chk(true, "  ⓘ الوضع التراكمي لسه شغّال للسجل العام ({$loose} بند من أيام قبلها)");

/* ② الأذونات: ما ينفعش المالك وهو على يوم ٢٦ يشوف إذنًا تاريخه ٢٨. */
chk(str_contains($ctrl, 'Metrics::openPermitsList(40, $date)')
    && str_contains($ctrl, 'Metrics::readyToInvoiceDashboard(8, $date)'),
    '⚠️ قائمتا الأذونات بتاخدا يوم العمل حدًّا أعلى');
/* ⚠️ ومسح الجاهزية كمان — إذن تاريخه بعد يوم العمل ما ينفعش يظهر «محتاج يتقفل». */
chk(str_contains($ctrl, 'PermitReadiness::scan($date)'),
    'ومسح الجاهزية بياخد يوم العمل حدًّا أعلى برضه');
$fut = 0;
foreach (\App\Models\Metrics::openPermitsList(60, $day) as $r) {
    if (($r['permit_date'] ?? '') > $day) { $fut++; }
}
chk($fut === 0, "ولا إذن مفتوح تاريخه بعد يوم العمل (لقينا {$fut})");
$fut2 = 0;
foreach (\App\Models\Metrics::readyToInvoiceDashboard(60, $day)['rows'] as $r) {
    if (($r['permit_date'] ?? '') > $day) { $fut2++; }
}
chk($fut2 === 0, "ولا إذن جاهز للفوترة تاريخه بعد يوم العمل (لقينا {$fut2})");

/* ③ كارتا إجمالي عهدة المناديب — طلب المالك، ومجموعهما = مجموع الصفوف. */
chk(str_contains($idxC, 'نزل عهدة') && str_contains($idxC, 'اللي اتصرف'),
    '⚠️ فيه كارتا إجمالي «نزل عهدة» و«اللي اتصرف» لليوم');
$rd = \App\Models\RepCustody::dayTotalsByRep($day);
$sumDep = 0.0; $sumSp = 0.0;
foreach ($rd as $r) { $sumDep += (float) ($r['deposits'] ?? 0); $sumSp += (float) ($r['spent'] ?? 0); }
chk(true, '  ⓘ إجمالي اليوم: نزل ' . number_format($sumDep, 2) . ' · اتصرف ' . number_format($sumSp, 2)
        . ' من ' . count($rd) . ' مندوب');
chk(str_contains($idxC, "\$drill('reps_day')"),
    'والكارتان بيفتحوا تفصيل كل مندوب (نزله كام وصرف كام)');

/* ⚠️ **فحص سلوكي مش فحص نص:** بنزرع بند مندوب **غير معتمَد** ونتأكد إنه
   بيظهر في الداشبورد فورًا — ده جوهر طلب المالك «حتى لو لم يتم اعتمادها».
   كله جوّه transaction وبيترجع، فما بيسيبش أي أثر. */
if (class_exists('\App\Models\Metrics') && \App\Core\Database::scalar(
        "SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = 'rep_submissions'")) {
    $db = \App\Core\Database::conn();
    $db->beginTransaction();
    try {
        $pid = (int) \App\Core\Database::scalar('SELECT id FROM permits ORDER BY id DESC LIMIT 1');
        $rid = (int) \App\Core\Database::scalar("SELECT id FROM reps WHERE status = 'active' LIMIT 1");
        if ($pid > 0 && $rid > 0) {
            $day  = date('Y-m-d');
            $seen = static function (int $pid, string $day): array {
                foreach (\App\Models\Metrics::permitsTouched($day) as $r) {
                    if ((int) $r['id'] === $pid) { return [$r['pend_cnt'], (float) $r['pend_val']]; }
                }
                return [0, 0.0];
            };
            [$b0] = $seen($pid, $day);
            \App\Core\Database::exec(
                "INSERT INTO rep_submissions (rep_id, permit_id, kind, note, qty, unit_amount, amount,
                                              entry_date, status, created_at)
                 VALUES (?, ?, 'receipt', 'بند فحص', 1, 777, 777, ?, 'pending', NOW())",
                [$rid, $pid, $day]);
            [$b1, $v1] = $seen($pid, $day);
            chk($b1 === $b0 + 1 && abs($v1 - 777.0) < 0.01,
                '⚠️ **بند مندوب غير معتمَد بيظهر في الداشبورد فورًا** (زرع حقيقي + rollback)');
        } else {
            echo "  ⓘ مفيش إذن أو مندوب نشط — فحص البند المستنّي اتخطّى\n";
        }

        /* ⚠️⚠️ **حارس التحسيب المزدوج (إصلاح 2026-08-17):** فورم المكتب بيحفظ
           الإيصال بـ`direction='expense'`، فالشرط القديم (`direction='expense'`
           لوحده) كان بيعدّه **إيصالًا ومصروفًا مع بعض** ومبلغه بيتجمع مرّتين
           في مجموع السطر — بلا أي خطأ ظاهر. والعكس: الرسوم الأولية
           (`direction` = NULL) ما كانتش بتتعدّ مصروفًا خالص.
           الفحص بيزرع الحالتين على إذن ويوم معزولين ويقيس الخانتين. */
        if ($pid > 0) {
            $dayX = '2026-01-06';
            \App\Core\Database::exec(
                "INSERT INTO permit_stages (permit_id, stage_key, direction, amount, qty, entry_date, note, status)
                 VALUES (?, 'receipts', 'expense', 400, 1, ?, 'إيصال فحص ازدواج', 'done')", [$pid, $dayX]);
            \App\Core\Database::exec(
                "INSERT INTO permit_stages (permit_id, stage_key, direction, amount, qty, entry_date, note, status)
                 VALUES (?, 'primary_fees', NULL, 250, 1, ?, 'رسوم فحص', 'done')", [$pid, $dayX]);
            $row = null;
            foreach (\App\Models\Metrics::permitsTouched($dayX) as $r) {
                if ((int) $r['id'] === $pid) { $row = $r; break; }
            }
            chk($row !== null && (int) $row['rc_cnt'] === 1 && (int) $row['ex_cnt'] === 1,
                '⚠️ الإيصال بيتعدّ **إيصالًا واحدًا بس** والرسوم الأولية بتتعدّ مصروفًا');
            chk($row !== null && abs(((float) $row['rc_val'] + (float) $row['ex_val']) - 650.0) < 0.01,
                'ومجموع السطر ٦٥٠ = ٤٠٠ + ٢٥٠ — المبلغ ما اتجمعش مرّتين');
        }
    } finally {
        $db->rollBack();
    }
    chk((int) \App\Core\Database::scalar(
        "SELECT COUNT(*) FROM rep_submissions WHERE note = 'بند فحص'") === 0,
        'والزرع اترجع بالكامل — الفحص ما بيسيبش أثر');
}

chk(str_contains($rowsV, 'function_exists'),
    'والمُصيّر محروس بـfunction_exists (الملفان بيتحمّلوا في نفس الطلب)');
chk(!str_contains($rowsV, '<table'), 'الصفوف بمكوّن `row_item` — مفيش جدول خام');
chk(substr_count($drillV, "partial('drill'") === 11, 'كل نوع تفاصيل بيستخدم مكوّن `drill` الموحّد');
$feedNow = Notification::feed();
chk(is_array($feedNow) && array_key_exists('count', $feedNow) && array_key_exists('items', $feedNow),
    'مجمّع الإشعارات بيرد بنفس الشكل اللي الداشبورد بتقراه');

// ═══ ⑤ ⚠️ مسار التفاصيل — محصور وبيمرّ على الصلاحيات
sec('⑤ ⚠️ مسار التفاصيل مش عام');
chk(str_contains($routes, "\$r->get('/dashboard/drill/{what}'"), 'المسار مسجَّل');
chk(preg_match('~in_array\(\$what, \[[^]]*\], true\)~s', $ctrl) === 1
    && str_contains($ctrl, "'needs_closing'") && str_contains($ctrl, "'receipt_gaps'")
    && str_contains($ctrl, "'safe_day'") && str_contains($ctrl, "'cars'"),
    'النوع محصور في قائمة بيضا — مش اسم جدول جاي من المستخدم');
/* ⚠️ **النوع اللي اتشال لازم يتشال من القائمة كمان** — نوع مسموح بلا `case`
   بيرجّع HTML الحالة الافتراضية بصمت، فالمالك يضغط «س» ويشوف بنود «ص». */
chk(!str_contains($ctrl, "'activity'") && !str_contains($ctrl, "'touched'")
    && !str_contains($ctrl, "case 'reps':"),
    'واللي اتشال اتشال من القائمة كمان — مفيش نوع مسموح بلا `case`');
chk(str_contains($ctrl, 'headers_sent()'),
    '⚠️ محروس بـheaders_sent — من غيره Warning بيتلزق جوّه جسم الرد ويبوّظ النافذة (باج v50)');
chk(str_contains($ctrl, "\$this->render('dashboard/_drill', \$data, false)"),
    'بيرندر بلا layout ويرجّع HTML مش JSON');
chk(Access::allows('owner', 'DashboardController@drill', 'GET'), 'المالك مسموح له');
chk(!Access::allows('guest', 'DashboardController@drill', 'GET')
    || Access::allows('guest', 'DashboardController@index', 'GET'),
    'الصلاحية بتمرّ على Access نفسها مش شرط مكتوب بالإيد');

// ═══ ⑥ رندر فعلي للصفحة
sec('⑥ الصفحة بترندر فعليًّا ببيانات حقيقية');
$uid = (int) Database::scalar('SELECT MIN(id) FROM users');
$_SESSION = ['uid' => $uid, 'role' => 'owner', 'uname' => 'فحص',
             'last_activity' => time(), 'uid_checked' => time()];
$_GET = [];
$render = static function (callable $fn): string {
    ob_start();
    try { $fn(); return (string) ob_get_clean(); }
    catch (\Throwable $e) { ob_end_clean(); bad('استثناء وقت الرندر: ' . $e->getMessage()); return ''; }
};
$noPhpError = static function (string $h, string $where): void {
    preg_match('/Fatal error|Warning:|Undefined variable|Undefined array key/', $h, $m)
        ? bad("خطأ PHP في {$where}: " . ($m[0] ?? ''))
        : ok("{$where} بيرندر بلا أخطاء PHP");
};

$c = new DashboardController();
$html = $render(static fn () => $c->index());
if ($html === '') {
    bad('الداشبورد ما رندرتش خالص');
} else {
    $noPhpError($html, 'الداشبورد');
    chk(str_contains($html, 'class="day-bar'), 'شريط اليوم اتطبع');
    chk(str_contains($html, 'محتاج منك'), 'قسم «محتاج منك» اتطبع');
    /* الرقم المرسوم فعلاً: ٤ للطبقة ③ + كارتا عهدة المناديب (بيظهروا لو
       وحدة المناديب متاحة — وهي متاحة على كل تثبيت فيه v35). */
    chk(substr_count($html, 'class="d-kpi ') === 7, 'سبعة أرقام اتطبعوا فعلاً');
    /* ⚠️ **العتبة مشتقّة من البنية مش رقمًا مزروعًا** — أجزاء المعادلة عددها
       بيتغيّر باليوم (البند اللي قيمته صفر بيتشال بالتصميم: «نزل للمكتب ٠»
       خانة فاضية بتاخد مساحة وما بتقولش حاجة). رقم ثابت هنا كان هيفشل
       بإنذار كاذب أول يوم هادي. */
    $mustDrill = substr_count($html, 'class="d-kpi ') + substr_count($html, 'class="d-eq-part');
    chk(substr_count($html, 'data-drill=') >= $mustDrill,
        "كل الأرقام ({$mustDrill}) + اللوحات ليهم مصدر تفاصيل");
    chk(str_contains($html, 'class="d-eq '), '⚠️ وسطر معادلة الخزنة اتطبع فعلاً');
    /* ⚠️ **العدّ على المرندَر** — كل جزء معادلة لازم يكون زرار بمصدر تفاصيل. */
    $eqParts = substr_count($html, 'class="d-eq-part');
    $eqDrill = preg_match_all('~class="d-eq-part[^"]*"\s+[^>]*data-drill=~', $html);
    chk($eqParts >= 2 && $eqParts === $eqDrill,
        "⚠️ وكل جزء في المعادلة ({$eqParts}) بيفتح تفاصيله — ولا رقم أصمّ");
    chk(!str_contains($html, 'quickEntryModal'), 'ومفيش أي أثر للإدخال السريع في المخرَج');
    chk(!str_contains($html, 'ممنوع رقم أصمّ'),
        '⚠️ مفيش رقم اترفض رندره (المكوّن بيسيب أثرًا في المصدر لو حصل)');
    chk(!str_contains($html, 'id="popStore"'), 'ومفيش مخزن تفاصيل مرندَر مسبقًا في الصفحة');
}

// ═══ ⑦ رندر كل نافذة تفاصيل
sec('⑦ نوافذ التفاصيل الإحداشر بترندر فعليًّا');
foreach (['expenses', 'receipts', 'custody', 'ready', 'permits_open',
          'opened', 'reps_day', 'cars', 'needs_closing', 'receipt_gaps', 'safe_day'] as $what) {
    $out = $render(static fn () => (new DashboardController())->drill($what));
    if ($out === '') { bad("نافذة `$what` ما رندرتش"); continue; }
    $noPhpError($out, "نافذة `$what`");
    chk(str_contains($out, 'drill-how') || str_contains($out, 'class="empty'),
        "نافذة `$what` فيها «إزاي اتحسب» أو حالة فراغ واضحة");
    chk(!str_contains($out, '<html'), "نافذة `$what` بترجع جزءًا مش صفحة كاملة");
}

// ═══ ⑧ العزل المالي — الحد الأحمر
sec('⑧ ⚠️ العزل المالي — الداشبورد قراءة فقط');
/* ⚠️ الفحص على **استعلامات** لا على كلمة `delete` (اللي بتظهر في مسار حذف
   ملاحظة وهو `POST` عادي بيتنفّذ في الكنترولر مش في الشاشة). */
chk(!preg_match('~Database::(exec|insert|update|delete)|\b(INSERT INTO|UPDATE\s+\w+\s+SET|DELETE FROM)\b~i',
        php_code($drillV) . php_code($rowsV) . $idxC),
    'مفيش أي استعلام كتابة في شاشة الداشبورد ولا نوافذها');
$treasuryAfter = Closing::currentBalance();
chk(abs($treasuryBefore - $treasuryAfter) < 0.005,
    'رصيد الخزنة قبل = بعد (' . money($treasuryBefore, false) . ')');

echo "\n" . str_repeat('=', 62) . "\n";
echo 'النتيجة: ' . $pass . ' ناجح · ' . count($failures) . " فاشل\n";
if ($failures) { foreach ($failures as $f) { echo "  ✗ $f\n"; } exit(1); }
exit(0);
