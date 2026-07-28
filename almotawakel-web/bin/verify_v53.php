<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص v53 — تفضيلات الإشعارات والموثوقية والتأجيل والتذكيرات التلقائية
 *           (المراحل ٤ + ٥ + ٦ + ٧ من docs/15).
 *
 * بيحرس:
 *   ① البنية + **صفر عمود مالي** + ⛔ مفيش «ساعات هدوء»
 *   ② **الغياب = مفعّل** — الترحيل ما زرعش صفوف، والسلوك القديم زي ما هو
 *   ③ التفضيل بيشتغل فعلاً: نوع مقفول = **مفيش صف مستلم**، وباقي الناس ما تتأثّرش
 *   ④ **الجرس هو الأساس** — قفله بيقفل الموبايل معاه (الخادم بيفرضها)
 *   ⑤ **شرط الرؤية مصدر واحد** — البند المؤجَّل/المقفول يختفي من الجرس **و**الصفحة
 *   ⑥ التباعد المتزايد + سقف المحاولات + `push_last_try` بيتختم
 *   ⑦ **`emitOnce` بيمنع التكرار** — من غيره التذكير بيترنّ ٢٨٨ مرّة في اليوم
 *   ⑧ التذكيرات التلقائية بتشتغل من العامل وما بتتكرّرش
 *   ⑨ الصلاحيات: كل مستخدم يعدّل **تفضيلاته هو**، والمندوب يشوف **أنواعه هو**
 *   ⑩ رندر فعلي لأربع شاشات + العزل المالي
 *
 * آمن: كل الكتابة جوّه transaction بيترجع، **وبلا أي إرسال حقيقي**.
 *
 * التشغيل:  php bin/verify_v53.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Access;
use App\Core\Database;
use App\Models\Closing;
use App\Models\Notify;
use App\Models\NotifyPref;
use App\Models\NotifyWatch;
use App\Models\PushNotify;

$pass = 0; $fail = 0; $skip = 0;
function ok(string $m): void   { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  ✗ $m\n"; }
function skip(string $m): void { global $skip; $skip++; echo "  ~ $m (تخطّي)\n"; }
function chk(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function sec(string $m): void  { echo "\n" . $m . "\n" . str_repeat('─', 62) . "\n"; }

$root = dirname(__DIR__);
/** ⚠️ التعليقات بتتشال قبل أي فحص «النص ده مش موجود» — تعليق بيوثّق القاعدة
 *  كان بيدّي إنذارًا كاذبًا (مصيدة `code_only` الموثَّقة). */
function code_only(string $s): string
{
    $s = (string) preg_replace('#/\*.*?\*/#s', '', $s);
    return (string) preg_replace('#^\s*//.*$#m', '', $s);
}

echo "\n╔══════════════════════════════════════════════════════════════╗\n";
echo   "║  فحص v53 — التفضيلات والموثوقية والتأجيل والتذكيرات          ║\n";
echo   "╚══════════════════════════════════════════════════════════════╝\n";

$balanceBefore = Closing::currentBalance();
$notifySrc = (string) file_get_contents($root . '/app/Models/Notify.php');
$prefSrc   = (string) file_get_contents($root . '/app/Models/NotifyPref.php');
$watchSrc  = (string) file_get_contents($root . '/app/Models/NotifyWatch.php');
$pushSrc   = (string) file_get_contents($root . '/app/Models/PushNotify.php');
$ctrlSrc   = (string) file_get_contents($root . '/app/Controllers/NotificationController.php');
$css       = (string) file_get_contents($root . '/public/assets/css/app.css');
$mcss      = (string) file_get_contents($root . '/public/assets/css/m.css');
$js        = (string) file_get_contents($root . '/public/assets/js/app.js');

/* ══════════════ ① البنية ══════════════ */
sec('① البنية');

$hasPrefs = (bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema=DATABASE() AND table_name='notification_prefs'");
chk($hasPrefs, 'جدول `notification_prefs` موجود');
if (!$hasPrefs) {
    echo "\nشغّل php bin/migrate_v53.php الأول.\n";
    exit(1);
}
foreach (['in_app', 'push_last_try', 'snoozed_until'] as $c) {
    chk((bool) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema=DATABASE() AND table_name='notification_recipients' AND column_name=?", [$c]),
        "العمود `notification_recipients.$c` موجود");
}
foreach (['idx_notif_retry' => 'طابور إعادة المحاولة', 'idx_notif_bell' => 'استعلام الجرس بعد in_app'] as $i => $why) {
    chk((bool) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.statistics
          WHERE table_schema=DATABASE() AND table_name='notification_recipients' AND index_name=?", [$i]),
        "فهرس `$i` موجود ($why)");
}

$money = Database::all(
    "SELECT column_name FROM information_schema.columns
      WHERE table_schema=DATABASE() AND table_name='notification_prefs'
        AND (column_name LIKE '%amount%' OR column_name LIKE '%balance%' OR column_name LIKE '%debt%')");
chk(!$money, 'صفر عمود مالي في جدول التفضيلات (وحدة وصفية بحتة)');

// ⛔ قرار المالك ٧.٤ — ساعات الهدوء اتشالت من الخطة خالص
$quiet = Database::all(
    "SELECT column_name FROM information_schema.columns
      WHERE table_schema=DATABASE() AND table_name LIKE 'notification%'
        AND (column_name LIKE '%quiet%' OR column_name LIKE '%dnd%')");
chk(!$quiet, '⛔ مفيش أي عمود «ساعات هدوء» — الفكرة اتشالت بقرار المالك');
chk(!preg_match('~quiet_hours|quietHours~i', code_only($prefSrc) . code_only($notifySrc)),
    '⛔ ومفيش أي كود ليها');

/* ══════════════ ② الغياب = مفعّل ══════════════ */
sec('② الغياب = مفعّل — الترحيل ما غيّرش سلوك حد');

chk(str_contains($prefSrc, 'الغياب = مفعّل'), 'القاعدة موثَّقة في الموديل');
chk(NotifyPref::allows('user', 'شخص-مش-موجود', 'permit.new', 'in_app'),
    'شخص بلا أي صف تفضيلات بياخد كل حاجة (in_app)');
chk(NotifyPref::allows('user', 'شخص-مش-موجود', 'permit.new', 'push'),
    'وكذلك على الموبايل');
// ⚠️ الحفظ بيمسح صف النوع اللي رجع للافتراضي بدل ما يخزّن 1,1
chk(str_contains(code_only($prefSrc), 'DELETE FROM notification_prefs'),
    'والحفظ بيمسح صف النوع اللي رجع للافتراضي — الجدول يفضل صغيرًا ومعناه واضح');

/* ══════════════ ③-⑦ سلوك فعلي داخل transaction ══════════════ */
sec('③ التفضيل والتأجيل وشرط الرؤية — اختبار فعلي');

$pdo = Database::conn();
$pdo->beginTransaction();
try {
    PushNotify::mute(true);           // ⚠️ الفحص ما يبعتش ولا إشعار حقيقي
    NotifyPref::clearCache();

    $u = Database::row('SELECT id, username FROM users ORDER BY id LIMIT 1');
    $me = (string) $u['username'];
    $_SESSION = ['uid' => (int) $u['id'], 'role' => 'owner', 'uname' => $me,
                 'last_activity' => time(), 'uid_checked' => time()];

    // ③أ النوع المفتوح بيوصل
    $a = Notify::emit('bill.new', ['title' => 'فحص ١', 'body' => 'x']);
    $mineA = (int) Database::scalar(
        'SELECT COUNT(*) FROM notification_recipients WHERE notification_id=? AND party_key=?', [$a, $me]);
    chk($mineA === 1, 'النوع المفتوح بيوصلني عادي');

    // ③ب أقفله عندي → مفيش صف مستلم أصلاً
    NotifyPref::save('user', $me, ['bill.new' => ['in_app' => false, 'push' => false]], ['bill.new']);
    $unreadBefore = Notify::unreadCount('user', $me);
    $b = Notify::emit('bill.new', ['title' => 'فحص ٢', 'body' => 'y']);
    $mineB  = (int) Database::scalar(
        'SELECT COUNT(*) FROM notification_recipients WHERE notification_id=? AND party_key=?', [$b, $me]);
    $othersB = count(Notify::recipientsOf($b));
    chk($mineB === 0, 'النوع المقفول ما بيتسجّلش عندي خالص');
    chk($othersB > 0, '⚠️ وباقي المستلمين بياخدوه عادي — التفضيل شخصي مش عام');
    chk(Notify::unreadCount('user', $me) === $unreadBefore, 'وعدّادي ما اتغيّرش');

    // ③ج الحدث نفسه بيفضل متسجّل — السجل شاهد على اللي حصل
    chk((int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE id=?', [$b]) === 1,
        '⚠️ والحدث نفسه بيفضل في الجدول (تبويب المالك «كل النظام» بيشوفه)');

    sec('④ الجرس هو الأساس — الموبايل بلا جرس مالوش معنى');
    NotifyPref::save('user', $me, ['bill.new' => ['in_app' => false, 'push' => true]], ['bill.new']);
    $p = NotifyPref::forParty('user', $me);
    chk(($p['bill.new']['push'] ?? true) === false,
        '⚠️ الخادم بيقفل الموبايل مع الجرس — مش الواجهة بس (الفورم مش مصدر ثقة)');

    sec('⑤ شرط الرؤية — مصدر واحد للجرس والصفحة');
    NotifyPref::reset('user', $me);
    $c = Notify::emit('day.closed', ['title' => 'فحص تأجيل', 'body' => 'z']);
    $u1 = Notify::unreadCount('user', $me);
    chk(Notify::snooze('user', $me, $c, 60), 'التأجيل اتنفّذ');
    $u2 = Notify::unreadCount('user', $me);
    chk($u2 === $u1 - 1, 'وعدّاد غير المقروء قلّ واحد');
    chk(Notify::snoozedCount('user', $me) >= 1, 'وبيتعدّ كمؤجَّل (عشان الشاشة تقول إنه موجود)');

    $inFeed = in_array($c, array_column(Notify::feed('user', $me, 80), 'id'), true);
    $inPage = in_array($c, array_column(Notify::listFor('user', $me, [], 100)['items'], 'id'), true);
    chk(!$inFeed, 'البند المؤجَّل اختفى من الجرس');
    chk(!$inPage, '⚠️ **ومن الصفحة كمان** — شرط واحد (`visibleSql`) بيغذّي الاتنين');
    chk(str_contains(code_only($notifySrc), 'visibleSql'),
        'وشرط الرؤية مكتوب مرّة واحدة مش متكرّر في كل استعلام');

    sec('⑦ emitOnce — بلاه التذكير بيترنّ ٢٨٨ مرّة في اليوم');
    $k = 'alert.permit_idle';
    Database::exec('DELETE FROM notifications WHERE kind = ?', [$k]);
    $e1 = Notify::emitOnce($k, 24, ['title' => 'ساكن', 'body' => 'b', 'subject_type' => 'permit', 'subject_id' => 999999]);
    $e2 = Notify::emitOnce($k, 24, ['title' => 'ساكن', 'body' => 'b', 'subject_type' => 'permit', 'subject_id' => 999999]);
    chk($e1 > 0, 'أول نداء بيسجّل');
    chk($e2 === 0, 'والتاني في نفس النافذة بيترفض');
    // البصمة فيها الموضوع: إذن تاني لازم يعدّي
    $e3 = Notify::emitOnce($k, 24, ['title' => 'ساكن ٢', 'body' => 'b', 'subject_type' => 'permit', 'subject_id' => 999998]);
    chk($e3 > 0, '⚠️ والبصمة فيها الموضوع — إذن تاني بياخد تنبيهه هو مش بيتبلع');

    sec('⑧ التذكيرات التلقائية');
    $w1 = NotifyWatch::run();
    $w2 = NotifyWatch::run();
    chk(is_array($w1) && array_key_exists('digest', $w1), '`NotifyWatch::run()` بترجّع عدّاد كل فحص');
    chk(array_sum($w2) === 0, '⚠️ التشغيلة التانية ورا بعض ما بتولّدش ولا حدث — مفيش تكرار');
    chk(!preg_match('~Notify::emit\(~', code_only($watchSrc)),
        '⚠️ ومفيش نداء لـ`emit` مباشرةً في الواتشر — كله بيمرّ على `emitOnce`');
    chk(!preg_match('~INSERT\s+INTO\s+(?!notification)~i', code_only($watchSrc)),
        'والواتشر ما بيكتبش في أي جدول غير الإشعارات');

    sec('⑨ الصلاحيات والملكية');
    chk(Access::allows('read_only', 'NotificationController@prefsSave', 'POST'),
        'أي مستخدم مسجَّل يعدّل **تفضيلاته هو** (حتى «قراءة فقط»)');
    chk(Access::allows('read_only', 'NotificationController@snooze', 'POST'),
        'وكذلك يأجّل بند من بنوده');
    chk(str_contains($ctrlSrc, 'requireAnySession'),
        '⚠️ الحارس بيقبل جلسة المكتب والمندوب — وهو **الحد الأمني الحقيقي** لأن '
        . '`Access::enforce` بتعدّي جلسة المندوب بلا فحص');
    chk(str_contains(code_only($ctrlSrc), 'Notify::me()'),
        'والهوية من الجلسة لا من الفورم');
    chk(str_contains(code_only($ctrlSrc), 'SNOOZE_CHOICES'),
        '⚠️ ومدّة التأجيل من قائمة بيضا — مستحيل حد يخفي بندًا ١٠ سنين');

    // المندوب بيشوف أنواعه هو بس
    $ref = new ReflectionMethod(App\Controllers\NotificationController::class, 'kindsFor');
    // ⚠️ `setAccessible` اتشالت — مالهاش أثر من PHP 8.1 وبقت deprecated في 8.5
    $repKinds    = $ref->invoke(null, 'rep');
    $officeKinds = $ref->invoke(null, 'user');
    chk(!in_array('day.closed', $repKinds, true),
        '⚠️ المندوب مش بيشوف «تقفيل يوم» في تفضيلاته (مالهاش معنى عنده)');
    chk(in_array('submission.approved', $repKinds, true), 'وبيشوف «بند اتعمد»');
    chk(in_array('day.closed', $officeKinds, true), 'والمكتب بيشوفها عادي');

    sec('⑩ رندر فعلي');
    $render = static function (callable $fn): string {
        ob_start();
        try { $fn(); return (string) ob_get_clean(); }
        catch (\Throwable $e) { ob_end_clean(); bad('استثناء وقت الرندر: ' . $e->getMessage()); return ''; }
    };
    $noErr = static function (string $h, string $where): void {
        preg_match('/Fatal error|Warning:|Undefined variable|Undefined array key/', $h, $m)
            ? bad("خطأ PHP في {$where}: " . ($m[0] ?? ''))
            : ok("{$where} بيرندر بلا أخطاء PHP");
    };
    $_GET = [];

    $h = $render(static fn () => (new App\Controllers\NotificationController())->prefs());
    $noErr($h, 'شاشة إعدادات الإشعارات');
    chk(substr_count($h, 'class="np-row"') === count($officeKinds),
        'وبتعرض صفًّا لكل نوع بيوصل المكتب (' . count($officeKinds) . ')');
    chk(str_contains($h, 'name="in_app['), 'وفيها خانة الجرس');
    chk(str_contains($h, 'name="push['), 'وخانة الموبايل');

    $h = $render(static fn () => (new App\Controllers\NotificationController())->index());
    $noErr($h, 'صفحة السجل');
    chk(str_contains($h, 'js-nl-snooze'), 'وفيها زرار تأجيل لكل بند');
    chk(str_contains($h, 'إعداداتي'), 'ورابط الإعدادات');

    $h = $render(static fn () => (new App\Controllers\PushController())->index());
    $noErr($h, 'شاشة /push');
    chk(str_contains($h, 'nh-grid'), 'وفيها لوحة صحة الإشعارات');
    chk(str_contains($h, 'اتقرا فعلاً'), '⚠️ وبتعرض «اتقرا فعلاً» — مش «وصل» بس');

    // شاشات المندوب
    $rep = Database::row("SELECT id, name FROM reps WHERE status='active' ORDER BY id LIMIT 1");
    if ($rep) {
        $_SESSION = ['rep_id' => (int) $rep['id'], 'rep_name' => (string) $rep['name'],
                     'rep_last' => time(), 'rep_checked' => time(), 'rep_work_date' => date('Y-m-d')];
        NotifyPref::clearCache();
        $h = $render(static fn () => (new App\Controllers\RepPortalController())->notifications());
        $noErr($h, 'شاشة إشعارات المندوب');
        // ⚠️ قاعدة v35: ممنوع أي سعر عميل يوصل شاشة المندوب
        chk(!preg_match('~actual_amount|actual_unit~', $h),
            '⚠️ ومفيش أي سعر بيخصّ العميل فيها (قاعدة v35)');
        $h = $render(static fn () => (new App\Controllers\NotificationController())->prefs());
        $noErr($h, 'شاشة تفضيلات المندوب');
        chk(substr_count($h, 'class="ntp-row"') === count($repKinds),
            'وبتعرض أنواعه هو بس (' . count($repKinds) . ')');
    } else {
        skip('مفيش مندوب نشط — شاشات المندوب ما اتفحصتش');
    }
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    PushNotify::mute(false);
    NotifyPref::clearCache();
    $_SESSION = [];
}
ok('الفحص رجّع كل الكتابة (rollback) — مفيش أثر باقٍ');

/* ══════════════ ⑥ الموثوقية (فحص كود) ══════════════ */
sec('⑥ الموثوقية — تباعد متزايد وسقف محاولات');

$pc = code_only($pushSrc);
chk(str_contains($pc, 'MAX_ATTEMPTS'), 'سقف المحاولات ثابت معرَّف');
chk(preg_match('~CASE push_attempts WHEN 0 THEN \d+ WHEN 1 THEN \d+~', $pc) === 1,
    '⚠️ التباعد متزايد (٥ ← ٣٠ ← ١٢٠ دقيقة) — بلاه بنعيد على جهاز ميت كل ٥ دقايق');
chk(str_contains($pc, 'push_last_try = NOW()'),
    'و`push_last_try` بتتختم مع كل محاولة — وإلا التباعد مالوش أساس يتقاس عليه');
chk(!preg_match('~\bstrtotime\([^)]*\)\s*[<>]~', $pc),
    '⚠️ ومفيش أي مقارنة وقت في PHP — المقارنات جوّه SQL (قاعدة v48)');
chk(str_contains($pc, 'function health('), 'لوحة صحة الإشعارات موجودة');
chk(str_contains($pc, "read_at IS NOT NULL"),
    '⚠️ وبتقيس «اتقرا فعلاً» مش «وصل» بس — ده الفرق اللي الخطة اشتكت منه');

/* ══════════════ ⑧ الواجهة والتوصيل ══════════════ */
sec('⑧ الواجهة');

foreach (['np-row', 'np-ch', 'np-actions', 'nl-acts', 'nl-act', 'nl-snoozed', 'nh-grid', 'nh-box', 'nh-bar'] as $cls) {
    chk(str_contains($css, '.' . $cls), "`.$cls` معرَّف في app.css");
}
foreach (['nt-bell', 'nt-item', 'nt-day', 'ntp-row', 'ntp-ch'] as $cls) {
    chk(str_contains($mcss, '.' . $cls), "`.$cls` معرَّف في m.css (بوابة المندوب)");
}
chk(str_contains($js, 'js-nl-snooze'), 'الواجهة فيها معالج التأجيل');
chk(str_contains($js, '__NOTIF_SNOOZE'), 'ومسار التأجيل متمرَّر من الـlayout');
chk(preg_match('~js-nl-snooze[\s\S]{0,1400}catch\([\s\S]{0,120}AppUI\.toast~', $js) === 1,
    '⚠️ وفشل التأجيل بيبان للمستخدم — لو سكتنا يفتكر إنه أجّل وهو ما أجّلش');
$home = (string) file_get_contents($root . '/app/Views/m/home.php');
chk(str_contains($home, 'repBellDot') && str_contains($home, "notifications/ping"),
    '⚠️ جرس المندوب بيقرا من **نفس** `/notifications/ping` — مصدر واحد للرقم');

$idx = (string) file_get_contents($root . '/public/index.php');
chk(strpos($idx, "'/notifications/prefs'") < strpos($idx, "\$r->get('/notifications',"),
    'المسارات الحرفية قبل الجشعة (تحذير ٤)');
chk(strpos($idx, "'/m/notifications'") < strpos($idx, "'/m/permit/{id}'"),
    'ومسارات `/m/notifications` قبل الجشع `/m/permit/{id}`');

$worker = (string) file_get_contents($root . '/bin/push_worker.php');
chk(str_contains($worker, 'NotifyWatch::run()'), 'العامل بينادي التذكيرات التلقائية');
chk(strpos($worker, 'NotifyWatch::run()') < strpos($worker, 'deliverPending'),
    '⚠️ والتوليد **قبل** الإرسال — فالتذكير بيتبعت في نفس التشغيلة مش بعد ٥ دقايق');

/* ══════════════ العزل المالي ══════════════ */
sec('⑨ العزل المالي');

/* ⚠️ **الفحص هنا لازم يفرّق بين قراءة وكتابة** — أول نسخة كتبتها كانت بتدوّر
   على `Custody::` حرفيًّا، فوقعت على **`RepCustody::dayTotalsByRep`** وهي
   استدعاء **قراءة مقصود**: التنبيه لازم يقرا نفس مصدر الرصيد اللي بتقراه
   الشاشات وإلا يبقى فيه تعريفان للرقم. المرفوض هو خزنة المكتب
   (`App\Models\Custody`) وأي كتابة مالية — مش أي اسم فيه كلمة Custody. */
foreach ([['NotifyPref', $prefSrc], ['NotifyWatch', $watchSrc]] as [$name, $src]) {
    $code = code_only($src);
    chk(!preg_match('~(?<![A-Za-z])Custody::~', $code), "`$name` ما بيلمسش خزنة المكتب (`Custody::`)");
    foreach (['Closing::guard', 'manual_custody', 'Closing::closeDay'] as $needle) {
        chk(!str_contains($code, $needle), "`$name` ما بيلمسش `{$needle}`");
    }
    chk(!preg_match('~(INSERT\s+INTO|UPDATE)\s+(bawalis|permit_stages|manual_expenses|daily_closings|rep_custody)~i', $code),
        "`$name` ما بيكتبش في أي جدول مالي");
}
chk(str_contains(code_only($watchSrc), 'RepCustody::dayTotalsByRep'),
    '⚠️ و`NotifyWatch` بيقرا **نفس** `RepCustody::dayTotalsByRep` بتاعة الشاشات — مفيش تعريف تاني للرصيد');
$after = Closing::currentBalance();
chk(abs($after - $balanceBefore) < 0.005,
    'رصيد الخزنة ما اتغيّرش (' . number_format($balanceBefore, 2) . ')');

echo "\n" . str_repeat('=', 62) . "\n";
echo "نجح: $pass   |   فشل: $fail   |   تخطّي: $skip\n";
if ($fail > 0) { echo "\n✗ الفحص فشل — الكود ده ما يترفعش.\n"; exit(1); }
echo "\n✓ كل الفحوصات عدّت.\n";
exit(0);
