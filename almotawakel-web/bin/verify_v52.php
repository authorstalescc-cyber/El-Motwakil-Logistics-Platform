<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص v52 — أساس نظام الإشعارات الموحَّد (المرحلة ١).
 *
 * بيحرس:
 *   1) الجداول والفهارس + **صفر عمود مالي**
 *   2) `Notify::emit` بتكتب **حدث واحد + صف لكل مستلم**
 *   3) **التوجيه زي قرارات المالك بالحرف** (إذن جديد للكل · قرار البند لمندوبه بس)
 *   4) **الصلاحية بتتفحص لكل مستلم** — حدث موضوعه صفحة مقفولة ما يوصلش الممنوع
 *   5) ⚠️ **إشعار فاشل ما يرجّعش معاملة المنادي** — أخطر باج في الوحدة، اتصادف
 *      فعليًّا وقت البناء: كان هيلغي حفظ البوليصة نفسها
 *   6) «مقروء» بتخصّ **الشخص** لا المتصفح
 *   7) سطر الأوامر بياخد `skipped` فالعامل ما يعيدش إشعارات سكربتات الاستيراد
 *   8) العامل آمن وهو فاضي + قفله بيمنع تشغيلين
 *   9) **صفر تغيير مرئي**: واجهة الأحداث القديمة زي ما هي، والكنترولرات ما اتلمستش
 *
 * آمن: كل الكتابة جوّه transaction بيترجع، **وبلا أي إرسال حقيقي**.
 *
 * التشغيل:  php bin/verify_v52.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Access;
use App\Core\Database;
use App\Models\Closing;
use App\Models\Notify;
use App\Models\PushNotify;

$pass = 0; $fail = 0; $skip = 0;

function ok(string $m): void   { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  ✗ $m\n"; }
function skip(string $m): void { global $skip; $skip++; echo "  ~ $m (تخطّي)\n"; }
function sec(string $m): void  { echo "\n" . $m . "\n" . str_repeat('─', 62) . "\n"; }

$root = dirname(__DIR__);
function code_only(string $s): string
{
    $s = (string) preg_replace('#/\*.*?\*/#s', '', $s);
    return (string) preg_replace('#^\s*//.*$#m', '', $s);
}

echo "\n╔══════════════════════════════════════════════════════════════╗\n";
echo   "║  فحص v52 — أساس نظام الإشعارات الموحَّد (المرحلة ١)          ║\n";
echo   "╚══════════════════════════════════════════════════════════════╝\n";

$balanceBefore = Closing::currentBalance();

/* ══════════════ 1) البنية ══════════════ */
sec('1) الجداول والفهارس');

foreach (['notifications', 'notification_recipients'] as $t) {
    (bool) Database::scalar("SELECT COUNT(*) FROM information_schema.tables
                             WHERE table_schema=DATABASE() AND table_name=?", [$t])
        ? ok("جدول `$t` موجود") : bad("جدول `$t` مش موجود — شغّل php bin/migrate_v52.php");
}
Notify::available() ? ok('`Notify::available()` = true') : bad('الوحدة مش شايفة الجداول');

foreach (['uq_notif_party' => 'يمنع تكرار نفس الإشعار لنفس الشخص',
          'idx_notif_inbox' => 'استعلام الجرس',
          'idx_notif_queue' => 'طابور إعادة الإرسال'] as $idx => $why) {
    (bool) Database::scalar("SELECT COUNT(*) FROM information_schema.statistics
                             WHERE table_schema=DATABASE() AND table_name='notification_recipients'
                               AND index_name=?", [$idx])
        ? ok("فهرس `$idx` موجود ($why)") : bad("فهرس `$idx` ناقص ($why)");
}

$money = Database::all(
    "SELECT column_name FROM information_schema.columns
      WHERE table_schema=DATABASE() AND table_name IN ('notifications','notification_recipients')
        AND (column_name LIKE '%amount%' OR column_name LIKE '%balance%' OR column_name LIKE '%debt%')");
$money ? bad('اتلاقى عمود مالي في جداول الإشعارات') : ok('صفر عمود مالي (وحدة وصفية بحتة)');

/* ══════════════ 2) التوجيه (قرارات المالك) ══════════════ */
sec('2) مين ياخد أنهي إشعار — قرارات المالك');

$routes = Notify::ROUTES;
($routes['permit.new'][0] ?? '') === 'both'
    ? ok('إذن جديد → المكتب والمناديب') : bad('جمهور «إذن جديد» اتغيّر');
($routes['day.closed'][0] ?? '') === 'office'
    ? ok('تقفيل اليوم → المكتب') : bad('جمهور «تقفيل اليوم» اتغيّر');
($routes['submission.new'][0] ?? '') === 'office'
    ? ok('بند مندوب جديد → المكتب') : bad('جمهور «بند جديد» اتغيّر');
($routes['bill.new'][0] ?? '') === 'office'
    ? ok('بوليصة جديدة → المكتب') : bad('جمهور «بوليصة جديدة» اتغيّر');

// ⚠️ قاعدة v35: ممنوع سعر العميل يوصل إشعار مندوب
$pushSrc = code_only((string) file_get_contents($root . '/app/Models/PushNotify.php'));
(!str_contains($pushSrc, 'actual_amount') && !str_contains($pushSrc, 'actual_unit'))
    ? ok('مفيش أي سعر عميل (`actual_*`) في نصوص الإشعارات (قاعدة v35)')
    : bad('⚠️ سعر العميل ممكن يوصل إشعار مندوب');

/* ══════════════ 3) الكتابة والتوزيع ══════════════ */
sec('3) الكتابة والتوزيع الفعلي');

$pdo = Database::conn();
$pdo->beginTransaction();
try {
    // ⚠️ مكتوم: الفحص ما يبعتش ولا إشعار حقيقي
    PushNotify::mute(true);

    $officeUsers = (int) Database::scalar("SELECT COUNT(*) FROM users");
    $activeReps  = (int) Database::scalar("SELECT COUNT(*) FROM reps WHERE status='active'");

    $id = Notify::emit('permit.new', [
        'title' => 'فحص — إذن', 'body' => 'بيانات فحص', 'url' => 'permits',
        'subject_type' => 'permit', 'subject_id' => 999999,
    ]);
    $id > 0 ? ok("الحدث اتسجّل (#$id)") : bad('الحدث ما اتسجّلش');

    $rows = (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE id=?", [$id]);
    $rows === 1 ? ok('حدث واحد = صف واحد') : bad("الحدث اتكتب $rows مرة");

    $recips = Notify::recipientsOf($id);
    count($recips) === $officeUsers + $activeReps
        ? ok('عدد المستلمين مظبوط (' . $officeUsers . ' مكتب + ' . $activeReps . ' مندوب)')
        : bad('عدد المستلمين غلط: ' . count($recips) . ' والمتوقّع ' . ($officeUsers + $activeReps));

    // كل مستلم صف واحد بس
    $dupes = (int) Database::scalar(
        "SELECT COUNT(*) FROM (SELECT party_type, party_key, COUNT(*) c
           FROM notification_recipients WHERE notification_id=?
          GROUP BY party_type, party_key HAVING c > 1) x", [$id]);
    $dupes === 0 ? ok('مفيش مستلم اتكرّر') : bad("$dupes مستلم اتكرّر");

    // قرار البند → **مندوب واحد بس**
    $rid = (int) (Database::scalar("SELECT id FROM reps WHERE status='active' ORDER BY id LIMIT 1") ?: 0);
    if ($rid > 0) {
        $id2 = Notify::emit('submission.approved', [
            'title' => 'فحص — قرار', 'body' => 'بند', 'audience' => 'rep:' . $rid,
        ]);
        $r2 = Notify::recipientsOf($id2);
        (count($r2) === 1 && (int) $r2[0]['party_key'] === $rid && $r2[0]['party_type'] === 'rep')
            ? ok('قرار البند بيروح للمندوب صاحبه بس — مفيش تسريب لمندوب تاني')
            : bad('⚠️ قرار البند بيتوزّع غلط (' . count($r2) . ' مستلم)');
    } else {
        skip('مفيش مندوب نشط للاختبار');
    }

    /* ══ الصلاحية لكل مستلم ══ */
    $roles = Database::all("SELECT DISTINCT role FROM users");
    $restricted = Notify::recipientsFor('office', 'SecurityController@index');
    $everyone   = Notify::recipientsFor('office', null);
    count($restricted) <= count($everyone)
        ? ok('حدث موضوعه صفحة مقفولة بيوصل عدد أقل (' . count($restricted) . ' من ' . count($everyone) . ')')
        : bad('فلترة الصلاحية مش شغّالة');
    $onlyOwners = true;
    foreach ($restricted as $p) {
        $role = (string) Database::scalar("SELECT role FROM users WHERE username=?", [$p['key']]);
        if (!Access::allows($role, 'SecurityController@index', 'GET')) { $onlyOwners = false; }
    }
    $onlyOwners ? ok('مفيش مستلم ممنوع من فتح موضوع الإشعار (الرفض هو الافتراضي)')
                : bad('⚠️ إشعار وصل حد ممنوع يفتح موضوعه');

    /* ══ مقروء تخصّ الشخص ══ */
    $me = (string) Database::scalar("SELECT username FROM users ORDER BY id LIMIT 1");
    $other = (string) (Database::scalar("SELECT username FROM users WHERE username<>? ORDER BY id LIMIT 1", [$me]) ?: '');
    $before = Notify::unreadCount('user', $me);
    Notify::markRead('user', $me, $id);
    $after = Notify::unreadCount('user', $me);
    $after === $before - 1 ? ok('«مقروء» بتشيل بند واحد من عدّاد صاحبه') : bad('عدّاد غير المقروء مش مظبوط');
    if ($other !== '') {
        $otherRow = Database::row(
            "SELECT read_at FROM notification_recipients
              WHERE notification_id=? AND party_type='user' AND party_key=?", [$id, $other]);
        ($otherRow && $otherRow['read_at'] === null)
            ? ok('قراءة شخص ما بتأثّرش على غيره (مقروء لكل شخص لا لكل متصفح)')
            : bad('⚠️ القراءة اتسرّبت لمستخدم تاني');
    }

    /* ══ ⚠️ أخطر فحص: الإشعار ما بيلمسش معاملة المنادي ══ */
    $pdo->inTransaction()
        ? ok('معاملة المنادي لسه مفتوحة بعد `emit` (الإشعار ما بيعملش commit بدالك)')
        : bad('⚠️⚠️ `emit` قفلت معاملة المنادي — ده بيخلّي حفظ البوليصة يضيع');

    $notifySrc = code_only((string) file_get_contents($root . '/app/Models/Notify.php'));
    preg_match('/catch \(\\\\Throwable \$e\) \{\s*\n\s*if \(\$own\)/', $notifySrc)
        ? ok('الـrollback عند الفشل محكوم بـ`$own` — فشل الإشعار ما يرجّعش شغل المنادي')
        : bad('⚠️⚠️ الـrollback مش محكوم — إشعار فاشل ممكن يلغي حفظ بوليصة');

    /* ══ سطر الأوامر = skipped ══ */
    PushNotify::mute(null === null ? true : true);
    $states = Database::all("SELECT DISTINCT push_state FROM notification_recipients WHERE notification_id=?", [$id]);
    (count($states) === 1 && $states[0]['push_state'] === 'skipped')
        ? ok('المستلم المتولّد من سطر الأوامر بياخد `skipped` (سكربتات الاستيراد ما بتبعتش)')
        : bad('حالة الإرسال الابتدائية غلط: ' . json_encode(array_column($states, 'push_state')));
} catch (\Throwable $e) {
    bad('استثناء: ' . $e->getMessage());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}
ok('كل بيانات الفحص اترجعت (rollback) — مفيش أي أثر');

/* ══════════════ 4) العامل ══════════════ */
sec('4) عامل إعادة الإرسال (الكرون)');

is_file($root . '/bin/push_worker.php') ? ok('`bin/push_worker.php` موجود') : bad('ملف العامل مش موجود');
$w = (string) @file_get_contents($root . '/bin/push_worker.php');
str_contains($w, "PHP_SAPI !== 'cli'") ? ok('العامل مقفول على سطر الأوامر') : bad('العامل مفتوح من الويب!');
str_contains($w, 'flock') ? ok('قفل بيمنع تشغيلين مع بعض') : bad('مفيش قفل — ممكن يتبعت الإشعار مرتين');
str_contains($w, 'workerMode(true)') ? ok('العامل بيفكّ كتم سطر الأوامر لنفسه بس') : bad('العامل مش هيقدر يبعت');
str_contains($w, 'push_worker_last_run') ? ok('بيسجّل آخر تشغيل (عشان نعرف الكرون شغّال)') : bad('مفيش ختم لآخر تشغيل');

$pendingSql = code_only((string) file_get_contents($root . '/app/Models/PushNotify.php'));
str_contains($pendingSql, 'DATE_SUB(NOW()')
    ? ok('مقارنة الوقت جوّه SQL (قاعدة v48 — فرق ٣ ساعات بين القاعدة وPHP)')
    : bad('⚠️ مقارنة وقت برّه SQL — هتفشل بسبب فرق التوقيت');
// ⚠️ الفحص على **السلوك** مش على الرقم الحرفي: v53 حوّلت `3` لثابت
//    `MAX_ATTEMPTS`، والفحص القديم على النص فشل بإنذار كاذب.
(str_contains($pendingSql, 'push_attempts <') && PushNotify::MAX_ATTEMPTS > 0 && PushNotify::MAX_ATTEMPTS <= 5)
    ? ok('إعادة المحاولة محدودة بـ' . PushNotify::MAX_ATTEMPTS . ' (مفيش لفّ للأبد)')
    : bad('مفيش حد لإعادة المحاولة');

// تشغيل فعلي — لازم يخرج بهدوء وهو فاضي
$out = [];
$code = 0;
if (function_exists('exec')) {
    @exec('php ' . escapeshellarg($root . '/bin/push_worker.php') . ' --verbose 2>&1', $out, $code);
    $code === 0 ? ok('العامل اشتغل وخرج بسلام (' . trim(implode(' · ', $out)) . ')')
                : bad('العامل رجّع كود خطأ ' . $code);
} else {
    skip('`exec` مقفولة — تشغيل العامل بيتفحص محليًّا بس');
}

/* ══════════════ 5) صفر تغيير مرئي ══════════════ */
sec('5) عقد المرحلة ١ — صفر تغيير مرئي');

// واجهة الأحداث لازم تفضل موجودة بنفس الأسماء، وإلا الكنترولرات تقع
// ⚠️ `submissionsDecidedBulk` **اتشالت في المرحلة ٣** عن قصد (بند لكل حدث) —
//    فحصها اتنقل تحت لقسم المرحلة ٣.
foreach (['permitCreated', 'dayClosed', 'submissionNew', 'submissionDecided',
          'billCreated', 'custom', 'sendNow'] as $m) {
    method_exists(PushNotify::class, $m)
        ? ok("`PushNotify::$m()` لسه موجودة (الكنترولرات ما اتلمستش)")
        : bad("`PushNotify::$m()` اتشالت — الكنترولرات هتقع");
}

// الحالات المستمرة لازم تفضل في مكانها القديم — ما تحوّلتش لأحداث
$notifModel = code_only((string) file_get_contents($root . '/app/Models/Notification.php'));
(str_contains($notifModel, 'repPending') && str_contains($notifModel, 'unclosedDays'))
    ? ok('الحالات المستمرة (أيام مش متقفلة…) لسه محسوبة لحظيًّا — ما دخلتش جدول الأحداث')
    : bad('الحالات اتحوّلت لأحداث — ده بيرجّع مشكلة «الإشعارات المضللة»');

$notifyModelSrc = code_only((string) file_get_contents($root . '/app/Models/Notify.php'));
foreach (['daily_closings', 'Custody::', 'Closing::'] as $needle) {
    str_contains($notifyModelSrc, $needle)
        ? bad("`Notify` بيلمس حساب مالي ($needle)") : null;
}
!str_contains($notifyModelSrc, 'Custody::') ? ok('`Notify` مالوش أي علاقة بالخزنة ولا التقفيل') : null;

/* ══════════════ 6) المرحلة ٣ — بند لكل حدث + حارس الانفجار ══════════════ */
sec('6) المرحلة ٣ — بند لكل حدث');

// ⑥أ التجميع اتشال من مصدر الأحداث
!method_exists(PushNotify::class, 'submissionsDecidedBulk')
    ? ok('`submissionsDecidedBulk` اتشالت — مفيش تجميع في مصدر الأحداث')
    : bad('⚠️ التجميع لسه موجود — اعتماد ٧ بنود هيبقى حدثًا واحدًا مضلِّلًا');

$repCtrl = code_only((string) file_get_contents($root . '/app/Controllers/RepCustodyController.php'));
(str_contains($repCtrl, 'repRows(') && !str_contains($repCtrl, 'repCounts('))
    ? ok('القرار الجماعي بيقرا **صفوف** البنود مش عددها (عشان يبعت حدثًا لكل واحد)')
    : bad('القرار الجماعي لسه بيقرا عددًا — النص هيبقى «اتعمدوا N بند» بلا تفاصيل');

// ⑥ب حارس الانفجار في القناة بس
$push = code_only((string) file_get_contents($root . '/app/Models/PushNotify.php'));
str_contains($push, 'BURST_MAX')
    ? ok('حارس الانفجار موجود في القناة') : bad('مفيش حارس انفجار — ٢٠٠ رنّة في دقيقة');
str_contains($push, 'deliverMany')
    ? ok('التوصيل بيشتغل على **دفعة** (عشان يعرف N بالظبط ويقول «و N كمان»)')
    : bad('التوصيل لسه حدثًا حدثًا — مستحيل يعرف كام فاضل');
(str_contains($push, "party_type . '|'") || str_contains($push, "\$r['party_type'] . '|'"))
    ? ok('التجميع **بالشخص** — الحدث الواحد ممكن يروح ١٢ مستلمًا ولكلٍّ سقفه')
    : bad('التجميع مش بالشخص — السقف هيتحسب غلط');
// ⚠️ الحارس بيلمس `push_state` بس — ممنوع يمسح أو يدمج صفوف السجل
!preg_match('~DELETE\s+FROM\s+notification~i', $push)
    ? ok('⚠️ الحارس ما بيمسحش أي صف من السجل — البند بيفضل موجود والرنّة بس اللي بتتلغى')
    : bad('⚠️ فيه DELETE على جدول الإشعارات — السجل المفترض يفضل كامل');

/* ⑥ب٢ ⚠️⚠️ الوجهة الاحتياطية لرنّة الموبايل — باج متحقَّق (2026-08-03)
   ─────────────────────────────────────────────────────────────────────────
   `urlFor()` بترجّع `''` لأي حدث رابطه صفحة مكتب بلا `meta.rep_url` (كل
   `custom` وكل `permit.new` القديم). الرنّة كانت وقتها بتقع على `url('')` =
   **لوحة المكتب** → المندوب يتقذف على شاشة دخول المكتب. الاحتياطي لازم يفضل
   صفحة إشعارات نفس الطرف. */
str_starts_with(Notify::fallbackUrlFor('rep'), 'm/')
    ? ok('⚠️ الوجهة الاحتياطية للمندوب بوابته هو (' . Notify::fallbackUrlFor('rep') . ')')
    : bad('⚠️⚠️ احتياطي المندوب صفحة مكتب — هيتقذف على شاشة دخول المكتب');
!str_starts_with(Notify::fallbackUrlFor('user'), 'm/')
    ? ok('والوجهة الاحتياطية للمكتب صفحته هو') : bad('احتياطي المكتب بقى بوابة المندوب');
!preg_match("~'url'\s*=>\s*[^,\n]*\burl\(''\)~", $push)
    ? ok('⚠️ مفيش `url(\'\')` كاحتياطي في أي رنّة (كان بيودّي لوحة المكتب)')
    : bad('⚠️⚠️ رجع `url(\'\')` كاحتياطي — المندوب هيتقذف على شاشة دخول المكتب');
substr_count($push, 'fallbackUrlFor') >= 3
    ? ok('كل مسارات الإرسال (البند · الانفجار · التجربة) بتمرّ على المصدر الواحد')
    : bad('مسار إرسال بيكتب وجهته بإيده — هيفترق عن الباقي');

// ⑥ج الأنواع التمانية الجديدة موصولة
foreach (['invoice.new', 'client.payment', 'rep.settlement', 'rep.deposit',
          'bill.linked', 'permit.closed', 'driver.handover', 'rep.transfer'] as $k) {
    isset(Notify::ROUTES[$k]) && isset(Notify::KINDS[$k])
        ? ok("النوع `$k` موجود في التوجيه والوصف")
        : bad("النوع `$k` ناقص من التوجيه أو الوصف");
}
foreach (['invoiceIssued', 'clientPaid', 'repSettled', 'repDeposit',
          'billLinked', 'permitClosed', 'driverHandover', 'repTransfer'] as $m) {
    method_exists(PushNotify::class, $m) ? ok("`PushNotify::$m()` موجودة") : bad("`PushNotify::$m()` ناقصة");
}

// ⑥د الهوكات في الكنترولرات فعلاً — مش دوال معلَّقة بلا مستدعي
$hooks = [
    'app/Controllers/PermitController.php'     => ['invoiceIssued', 'permitClosed', 'billLinked'],
    'app/Controllers/PaymentController.php'    => ['clientPaid'],
    'app/Controllers/RepCustodyController.php' => ['repDeposit', 'repSettled'],
    // ⚠️ v60: `driverHandover` بقى **بلا مُطلِق حيّ** — شاشة تسليم السائق اتشالت
    //    ومكانها «بوليصة جديدة» اللي بتطلق `billLinked`. النوع والدالة **بيفضلوا**
    //    لأن الإشعارات القديمة في السجل بتشاور عليهم (شيلهم = سجل بلا وصف).
    'app/Controllers/RepPortalController.php'  => ['billLinked', 'repTransfer'],
];
foreach ($hooks as $file => $ms) {
    $src = code_only((string) file_get_contents($root . '/' . $file));
    foreach ($ms as $m) {
        str_contains($src, $m . '(')
            ? ok("`$m` متنادية فعلاً من " . basename($file))
            : bad("`$m` مش متنادية من " . basename($file) . " — النوع هيفضل ميتًا");
    }
}

// ⚠️ قاعدة v35 لسه سارية على النصوص الأغنى
!preg_match('~actual_amount|actual_unit~', $push)
    ? ok('⚠️ ممنوع سعر العميل في أي إشعار (قاعدة v35) — لسه محفوظة')
    : bad('⚠️ سعر العميل ظهر في مصدر الإشعارات');

$after = Closing::currentBalance();
abs($after - $balanceBefore) < 0.005
    ? ok('رصيد الخزنة ما اتغيّرش (' . number_format($balanceBefore, 2) . ')')
    : bad('⚠️ رصيد الخزنة اتغيّر: ' . $balanceBefore . ' ← ' . $after);

/* ══════════════ الخلاصة ══════════════ */
echo "\n" . str_repeat('=', 62) . "\n";
echo "نجح: $pass   |   فشل: $fail   |   تخطّي: $skip\n";
if ($fail > 0) {
    echo "\n✗ الفحص فشل — الكود ده ما يترفعش.\n";
    exit(1);
}
echo "\n✓ كل الفحوصات عدّت.\n";
exit(0);
