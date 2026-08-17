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

// ═══ ① الطبقات الأربعة
sec('① البناء الجديد — أربع طبقات بأولوية واضحة');
chk(str_contains($idxC, "partial('day_bar'"), '① اليوم: شريط يوم العمل الموحّد');
chk(str_contains($idxC, 'خزنة المكتب') && str_contains($idxC, "'facts'"),
    'ورصيد الخزنة ظاهر جنب التاريخ');
chk(str_contains($idxC, 'id="quickEntryOpen"') && str_contains($idxC, "'actions'"),
    'وزر الإدخال السريع جوّه الشريط نفسه');
chk(str_contains($idxC, 'Notification::feed()'), '② محتاج منك: من مجمّع الإشعارات');
chk(str_contains($idxC, "partial('stat_grid'"), '③ الأرقام: شبكة `stat_grid`');
/* خمس استدعاءات لأن الرابع فرعان (عهدة المناديب / أذونات مفتوحة حسب توفّر
   الوحدة) — العدد **المرسوم** أربعة، والفحص عليه في القسم ⑥. */
/* ٤ أرقام الطبقة ③ (٥ استدعاءات بفرعين) + كارتا إجمالي عهدة المناديب
   اللي طلبهم المالك (نزل / اتصرف) = ٦ استدعاءات. */
chk(substr_count($idxC, "partial('stat',") === 6, 'ست بطاقات رقم (٤ للطبقة ③ + كارتا عهدة المناديب)');
chk(str_contains($idxC, 'الشغل الجاري'), '④ الشغل الجاري: قسم واحد للمتابعة');
/* ⚠️ اتزادوا ٣ لوحات في «حركة اليوم» (طلب المالك 2026-07-27): أذونات
   اتحرّك عليها · أذونات اتفتحت · عهدة المناديب النهاردة. */
chk(substr_count($idxC, "partial('panel'") === 6,
    'وفيه ست لوحات (٣ للشغل الجاري + ٣ لحركة اليوم)');
chk(str_contains($idxC, 'حركة يوم'), '④ حركة اليوم: قسم بعدسة اليوم بلا أي تراكمي');

// ═══ ② اللي اتشال عن قصد
sec('② اللي اتشال — والسبب');
chk(!str_contains($idxC, 'd-hero'),
    'ترويسة التحية والوقت اتشالت (كانت بتاخد شاشة وما بتقولش حاجة)');
chk(!str_contains($idxC, 'rp-panel') && !str_contains($idxC, 'rp-strip'),
    'لوحة المناديب المفصّلة اتشالت (كانت بتكرّر صفحة /rep-custody كلها)');
chk(!str_contains($ctrl, 'Metrics::recentActivity(10)'),
    'استعلام «آخر ما اتسجّل» ما بقاش بيتدفع مع كل فتحة — بقى في مسار التفاصيل');
chk(str_contains($ctrl, "case 'activity'") || str_contains($ctrl, 'Metrics::recentActivity(20)'),
    'والمعلومة نفسها لسه موجودة — نافذة من ترويسة ④');
chk(!str_contains($ctrl, "'custodyDay'") && !str_contains($ctrl, "'dayExp'"),
    'تفاصيل الخزنة ومصروفات/إيصالات اليوم بقت كسولة');
/* ملاحظة موثَّقة من المرحلة ٤: الداشبورد **بلا نولون وبلا «المتبقي للعملاء»** */
chk(!str_contains($idxC, 'المتبقي للعملاء'), 'ومفيش «المتبقي للعملاء» (قرار المالك من المرحلة ٤)');
chk(!str_contains($idxC, 'Chart') && !str_contains($idxC, 'chart.js'), 'ومفيش رسوم — مكانها /reports');

// ═══ ③ ⚠️ ممنوع رقم أصمّ
sec('③ ⚠️ ممنوع رقم أصمّ (قاعدة المالك الدائمة)');
/* ٥ أرقام + ٤ لوحات ليها نافذة تفاصيل (جاهز للفوترة · فلوس اليوم ·
   أذونات اتحرّك · أذونات اتفتحت) = ٩. */
chk(substr_count($idxC, "'drill' =>") === substr_count($idxC, "partial('stat',") + 5,
    'كل رقم معاه مصدر تفاصيل (+ خمس لوحات/أزرار بنافذة تفاصيل)');
chk(!preg_match('/class="d-kpi(?![^>]*data-drill)/', $idxC), 'مفيش رقم مرسوم بالإيد بلا تفاصيل');
chk(str_contains($idxC, 'data-drill=') && str_contains($idxC, 'd-sec-act'),
    'وحتى «آخر ما اتسجّل» بقى نافذة تفاصيل مش قسم');
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

/* ① البند المستنّي **لازم** يتعدّ — لو الاتحاد اتشال، المالك يفتكر إن مفيش
   حاجة مستنية قراره وهي موجودة. */
chk(str_contains($metrics, 'rep_submissions') && str_contains($metrics, "b.status = 'pending'"),
    '⚠️ `permitsTouched` بتضم البنود **المستنّية** كمان (طلب المالك: حتى لو مش معتمدة)');
chk(str_contains($metrics, "s.entry_date = ?"),
    'وبتفلتر بـ`entry_date` — يوم صرف البند مش يوم تسجيله (قاعدة v29)');
chk(str_contains($rowsV, 'مستنّي اعتمادك'),
    'والصف بيفرّق المستنّي بشارة لوحده — مش مخلوط بالمعتمَد');

/* ② التاريخ من `audit_logs.work_date` — `created_at` غلط لسببين موثَّقين. */
chk(str_contains($metrics, "a.entity = 'permits' AND a.action = 'create' AND a.work_date = ?"),
    '⚠️ `permitsOpened` بتقرا `audit_logs.work_date` — مش `permits.created_at`');
chk(!preg_match("~permitsOpened.*?DATE\(p\.created_at\)~s", $metrics),
    'ومفيش أي اعتماد على `created_at` (توقيت السيرفر UTC — فرق ٣ ساعات، قاعدة v48)');

/* ③ عهدة المناديب: يومية، ومن **نفس** دالة شاشة العهدة. */
chk(str_contains($ctrl, 'RepCustody::dayTotalsByRep($date)'),
    '⚠️ عهدة المناديب من `dayTotalsByRep($day)` — نفس دالة `/rep-custody` فمستحيل الرقمان يختلفوا');
chk(!str_contains($ctrl, 'RepCustody::totalOutstanding'),
    'ومفيش رقم تراكمي للمناديب في الداشبورد (التراكمي مكانه `/rep-custody`)');
chk(str_contains($rowsV, "\$r['pocket']") || str_contains($rowsV, "'pocket'"),
    'والرقم المعروض `pocket` (بيخصم المستنّي) مش `balance`');

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
chk(str_contains($ctrl, 'Metrics::openPermitsList(8, $date)')
    && str_contains($ctrl, 'Metrics::readyToInvoiceDashboard(8, $date)'),
    '⚠️ قائمتا الأذونات بتاخدا يوم العمل حدًّا أعلى');
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
chk(str_contains($idxC, 'نزل عهدة النهاردة') && str_contains($idxC, 'اللي اتصرف النهاردة'),
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
chk(substr_count($drillV, "partial('drill'") === 10, 'كل نوع تفاصيل بيستخدم مكوّن `drill` الموحّد');
$feedNow = Notification::feed();
chk(is_array($feedNow) && array_key_exists('count', $feedNow) && array_key_exists('items', $feedNow),
    'مجمّع الإشعارات بيرد بنفس الشكل اللي الداشبورد بتقراه');

// ═══ ⑤ ⚠️ مسار التفاصيل — محصور وبيمرّ على الصلاحيات
sec('⑤ ⚠️ مسار التفاصيل مش عام');
chk(str_contains($routes, "\$r->get('/dashboard/drill/{what}'"), 'المسار مسجَّل');
chk(preg_match("/in_array\(\\\$what, \['expenses', 'receipts', 'custody', 'reps', 'ready', 'permits_open', 'activity',\s*'touched', 'opened', 'reps_day'\], true\)/s", $ctrl) === 1,
    'النوع محصور في قائمة بيضا — مش اسم جدول جاي من المستخدم');
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
    chk(substr_count($html, 'class="d-kpi ') === 6, 'ستة أرقام اتطبعوا فعلاً');
    chk(substr_count($html, 'data-drill=') >= 5, 'وكلهم + «آخر ما اتسجّل» ليهم مصدر تفاصيل');
    chk(!str_contains($html, 'ممنوع رقم أصمّ'),
        '⚠️ مفيش رقم اترفض رندره (المكوّن بيسيب أثرًا في المصدر لو حصل)');
    chk(!str_contains($html, 'id="popStore"'), 'ومفيش مخزن تفاصيل مرندَر مسبقًا في الصفحة');
}

// ═══ ⑦ رندر كل نافذة تفاصيل
sec('⑦ نوافذ التفاصيل السبعة بترندر فعليًّا');
foreach (['expenses', 'receipts', 'custody', 'reps', 'ready', 'permits_open', 'activity',
          'touched', 'opened', 'reps_day'] as $what) {
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
