<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص v51 — إشعارات التطبيق (Web Push).
 *
 * بيحرس:
 *   1) الجداول والمفاتيح موجودة، والوحدة **معزولة ماليًّا** (صفر عمود فلوس)
 *   2) **التشفير شغّال فعلاً** — بنعمل «جهاز» وهمي، نشفّر له، ونفكّ التشفير من
 *      ناحيته. ده الفحص الوحيد اللي بيمسك غلطة في اشتقاق المفاتيح، لأن
 *      الإرسال بيرجّع نفس رسالة الفشل سواء التشفير غلط ولا التوكن مات.
 *   3) **توقيع VAPID** مضبوط الطول (64 بايت) — التوقيع الأقصر بيترفض بصمت
 *   4) قواعد الجمهور (مين ياخد أنهي إشعار) زي قرار المالك بالظبط
 *   5) الصلاحيات: الإرسال للمالك/المدير بس، والاشتراك لأي مستخدم مسجَّل
 *   6) الـService Worker بيعرض إشعارًا مع **كل** رسالة (وإلا المتصفح بيعرض
 *      «تم تحديث الموقع في الخلفية» بدالنا)
 *   7) سطر الأوامر مكتوم — الهجرات ما تبعتش مئات الإشعارات
 *
 * آمن: كل الكتابة جوّه transaction بيترجع (rollback)، **ومفيش أي إرسال حقيقي**
 * (الإشعارات مكتومة في CLI بالتعريف).
 *
 * التشغيل:  php bin/verify_v51.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Access;
use App\Core\Database;
use App\Core\WebPush;
use App\Models\Closing;
use App\Models\PushNotify;
use App\Models\PushSubscription;

$pass = 0; $fail = 0; $skip = 0;

function ok(string $m): void   { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  ✗ $m\n"; }
function skip(string $m): void { global $skip; $skip++; echo "  ~ $m (تخطّي)\n"; }
function sec(string $m): void  { echo "\n" . $m . "\n" . str_repeat('─', 62) . "\n"; }

$root = dirname(__DIR__);
/** يشيل تعليقات PHP/JS — تعليق بيشرح نمطًا ما يعتبرش استخدامًا له. */
function code_only(string $s): string
{
    $s = (string) preg_replace('#/\*.*?\*/#s', '', $s);
    return (string) preg_replace('#^\s*//.*$#m', '', $s);
}

echo "\n╔══════════════════════════════════════════════════════════════╗\n";
echo   "║  فحص v51 — إشعارات التطبيق (Web Push)                        ║\n";
echo   "╚══════════════════════════════════════════════════════════════╝\n";

$balanceBefore = Closing::currentBalance();

/* ══════════════ 1) الجداول والمفاتيح ══════════════ */
sec('1) الجداول والمفاتيح');

foreach (['push_subscriptions', 'push_messages'] as $t) {
    (bool) Database::scalar("SELECT COUNT(*) FROM information_schema.tables
                             WHERE table_schema=DATABASE() AND table_name=?", [$t])
        ? ok("جدول `$t` موجود")
        : bad("جدول `$t` مش موجود — شغّل php bin/migrate_v51.php");
}
PushSubscription::available() ? ok('`PushSubscription::available()` = true') : bad('الوحدة مش شايفة الجدول');
WebPush::available() ? ok('مفاتيح الإشعارات جاهزة والإرسال متاح') : bad('مفاتيح VAPID ناقصة');

$pub = WebPush::publicKey();
strlen(WebPush::b64urlDecode($pub)) === 65
    ? ok('المفتاح العام 65 بايت (نقطة EC غير مضغوطة زي ما المتصفح مستنّي)')
    : bad('المفتاح العام مقاسه غلط — المتصفح هيرفض الاشتراك');

/* ══════════════ 2) التشفير — الفحص الأهم ══════════════ */
sec('2) التشفير من طرف لطرف (RFC 8291)');

try {
    // «جهاز» وهمي بنفس اللي المتصفح بيعمله بالظبط
    $ua = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $d  = openssl_pkey_get_details($ua);
    $uaPub = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    $auth  = random_bytes(16);

    $secret = 'إذن 12345 — المصرية الألمانية · 4,250.00 ج';
    $m = new ReflectionMethod(WebPush::class, 'encrypt');
    $m->setAccessible(true);
    [$body] = $m->invoke(null, json_encode(['title' => 'اختبار', 'body' => $secret], JSON_UNESCAPED_UNICODE),
                         WebPush::b64url($uaPub), WebPush::b64url($auth));

    // فك التشفير من ناحية الجهاز
    $salt  = substr($body, 0, 16);
    $idlen = ord($body[20]);
    $asPub = substr($body, 21, $idlen);
    $ct    = substr($body, 21 + $idlen);
    $pem = "-----BEGIN PUBLIC KEY-----\n"
         . chunk_split(base64_encode((string) hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $asPub), 64, "\n")
         . "-----END PUBLIC KEY-----\n";
    $shared = openssl_pkey_derive(openssl_pkey_get_public($pem), $ua, 32);
    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\x00" . $uaPub . $asPub, $auth);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $non = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);
    $plain = openssl_decrypt(substr($ct, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $non, substr($ct, -16));

    if ($plain === false) {
        bad('فشل فك التشفير — اشتقاق المفاتيح غلط، والإشعارات مش هتوصل لأي جهاز');
    } else {
        ok('التشفير وفكّه شغّالين (الجهاز قدر يقرا الرسالة)');
        str_contains((string) $plain, $secret)
            ? ok('النص العربي والمبالغ بتوصل سليمة')
            : bad('النص وصل مشوّه');
    }
    // ⚠️ الحمولة **ما تظهرش** لخدمة الدفع — ده جوهر التشفير من طرف لطرف
    !str_contains($body, $secret)
        ? ok('نص الإشعار مش ظاهر في الجسم المرسَل (جوجل/آبل مش شايفينه)')
        : bad('⚠️ نص الإشعار ظاهر بلا تشفير في الجسم المرسَل');
} catch (\Throwable $e) {
    bad('استثناء في اختبار التشفير: ' . $e->getMessage());
}

/* ══════════════ 3) توقيع VAPID ══════════════ */
sec('3) توقيع VAPID');

try {
    $j = new ReflectionMethod(WebPush::class, 'jwt');
    $j->setAccessible(true);
    $jwt = (string) $j->invoke(null, 'https://fcm.googleapis.com');
    $parts = explode('.', $jwt);
    count($parts) === 3 ? ok('الـJWT ٣ أجزاء') : bad('الـJWT مش مكوّن صح');
    strlen(WebPush::b64urlDecode($parts[2] ?? '')) === 64
        ? ok('التوقيع 64 بايت بالظبط (الأقصر بيترفض من خدمة الدفع بصمت)')
        : bad('طول التوقيع غلط — خدمة الدفع هترفض كل الإشعارات');
    $payload = json_decode(WebPush::b64urlDecode($parts[1] ?? ''), true);
    ($payload['aud'] ?? '') === 'https://fcm.googleapis.com' ? ok('النطاق في التوقيع مضبوط') : bad('النطاق غلط');
    ($payload['exp'] ?? 0) > time() ? ok('صلاحية التوقيع في المستقبل') : bad('التوقيع منتهي');
    str_starts_with((string) ($payload['sub'] ?? ''), 'mailto:') ? ok('بريد التواصل موجود') : bad('بريد التواصل ناقص');
} catch (\Throwable $e) {
    bad('استثناء في التوقيع: ' . $e->getMessage());
}

/* ══════════════ 4) قواعد الجمهور ══════════════ */
sec('4) مين ياخد أنهي إشعار (قرار المالك)');

/* ⚠️ **مكان قواعد الجمهور اتنقل في v52** لـ`Notify::ROUTES` — المصدر الوحيد
   اللي بيخدم الجرس والموبايل مع بعض. الفحص بيقرا من هناك دلوقتي. */
$routes = \App\Models\Notify::ROUTES;
$code   = code_only((string) file_get_contents($root . '/app/Models/PushNotify.php'));

($routes['permit.new'][0] ?? '') === 'both'
    ? ok('إذن جديد → المكتب والمناديب')
    : bad('جمهور «إذن جديد» اتغيّر عن قرار المالك: ' . ($routes['permit.new'][0] ?? 'مفقود'));
($routes['day.closed'][0] ?? '') === 'office'
    ? ok('تقفيل اليوم → المكتب') : bad('جمهور «تقفيل اليوم» اتغيّر');
($routes['submission.new'][0] ?? '') === 'office'
    ? ok('بند مندوب جديد → المكتب') : bad('جمهور «بند جديد» اتغيّر');
($routes['bill.new'][0] ?? '') === 'office'
    ? ok('بوليصة جديدة → المكتب') : bad('جمهور «بوليصة جديدة» اتغيّر');
// ⚠️ قرار المالك: قرار البند يروح **للمندوب صاحبه بس** مش لكل المناديب
str_contains($code, "'audience'   => 'rep:' . \$repId")
    ? ok('اعتماد/رفض بند → المندوب صاحب البند بس (مش كل المناديب)')
    : bad('⚠️ قرار البند مش متوجّه لمندوب واحد — كسر لقرار المالك');

// ⚠️ قاعدة v35: ممنوع سعر العميل يوصل إشعار مندوب
!str_contains($code, 'actual_amount') && !str_contains($code, 'actual_unit')
    ? ok('مفيش أي سعر عميل (`actual_*`) في نصوص إشعارات المناديب (قاعدة v35)')
    : bad('⚠️ سعر العميل ممكن يوصل إشعار مندوب — كسر لقاعدة v35');

/* ══════════════ 5) الجمهور من القاعدة ══════════════ */
sec('5) اختيار الأجهزة حسب الجمهور');

$pdo = Database::conn();
$pdo->beginTransaction();
try {
    $mk = static function (string $aud, ?int $repId, string $tag): void {
        Database::exec(
            "INSERT INTO push_subscriptions (audience, user_name, rep_id, endpoint, endpoint_hash, p256dh, auth_secret)
             VALUES (?,?,?,?,?,?,?)",
            [$aud, 'فحص', $repId, 'https://example.com/' . $tag, hash('sha256', 'https://example.com/' . $tag),
             str_repeat('A', 87), str_repeat('B', 22)]);
    };
    $mk('office', null, 'v51-office-1');
    $mk('office', null, 'v51-office-2');
    $mk('rep', 9001, 'v51-rep-a');
    $mk('rep', 9002, 'v51-rep-b');

    count(PushSubscription::forAudience('office')) >= 2 ? ok('`office` بيرجّع أجهزة المكتب') : bad('اختيار المكتب غلط');
    count(PushSubscription::forAudience('rep')) >= 2 ? ok('`rep` بيرجّع كل المناديب') : bad('اختيار المناديب غلط');
    $one = PushSubscription::forAudience('rep:9001');
    count($one) === 1 && (int) $one[0]['rep_id'] === 9001
        ? ok('`rep:{id}` بيرجّع جهاز المندوب ده بس — مفيش تسريب لمندوب تاني')
        : bad('⚠️ اختيار مندوب واحد بيرجّع أجهزة غيره');
    count(PushSubscription::forAudience('all')) >= 4 ? ok('`all` بيرجّع الكل') : bad('اختيار «الكل» غلط');

    // نفس الجهاز مرتين = صف واحد (وإلا الموبايل بياخد نفس الإشعار مكرّر)
    $before = (int) Database::scalar("SELECT COUNT(*) FROM push_subscriptions");
    PushSubscription::save(['endpoint' => 'https://example.com/v51-office-1',
                            'p256dh' => str_repeat('C', 87), 'auth' => str_repeat('D', 22)],
                           'office', 'فحص', null, 'test');
    (int) Database::scalar("SELECT COUNT(*) FROM push_subscriptions") === $before
        ? ok('إعادة التسجيل بتحدّث الصف مش بتعمل صف جديد (مفيش إشعار مكرّر)')
        : bad('نفس الجهاز اتسجّل مرتين');

    // 410 = الاشتراك مات → يتشال فورًا
    $id = (int) Database::scalar("SELECT id FROM push_subscriptions WHERE endpoint_hash=?",
                                 [hash('sha256', 'https://example.com/v51-rep-b')]);
    PushSubscription::recordResult($id, false, true);
    !Database::scalar("SELECT COUNT(*) FROM push_subscriptions WHERE id=?", [$id])
        ? ok('الاشتراك الميت (410) بيتشال تلقائيًّا')
        : bad('الاشتراك الميت بيفضل في الجدول للأبد');
} catch (\Throwable $e) {
    bad('استثناء: ' . $e->getMessage());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}
ok('كل بيانات الفحص اترجعت (rollback) — مفيش أي أثر');

/* ══════════════ 6) الصلاحيات ══════════════ */
sec('6) الصلاحيات');

Access::allows('owner', 'PushController@send', 'POST') ? ok('المالك بيبعت إشعارات') : bad('المالك ممنوع يبعت');
Access::allows('manager', 'PushController@send', 'POST') ? ok('المدير بيبعت إشعارات') : bad('المدير ممنوع يبعت');
foreach (['accountant', 'operator', 'read_only'] as $role) {
    !Access::allows($role, 'PushController@send', 'POST')
        ? ok("«{$role}» ممنوع يبعت إشعار لكل الشركة")
        : bad("⚠️ «{$role}» يقدر يبعت إشعار لكل الشركة");
    !Access::allows($role, 'PushController@index', 'GET')
        ? ok("«{$role}» مش شايف شاشة الإشعارات")
        : bad("⚠️ «{$role}» شايف شاشة الإشعارات");
    Access::allows($role, 'PushController@subscribe', 'POST')
        ? ok("«{$role}» يقدر يفعّل الإشعارات على جهازه")
        : bad("«{$role}» مش قادر يفعّل إشعارات جهازه");
}

/* ══════════════ 7) الـService Worker والواجهة ══════════════ */
sec('7) الـService Worker والواجهة');

$sw = code_only((string) file_get_contents($root . '/public/sw.js'));
str_contains($sw, "addEventListener('push'") ? ok('الـSW بيستقبل الإشعارات') : bad('مفيش معالج `push` في الـSW');
str_contains($sw, 'showNotification')
    ? ok('بيعرض إشعارًا مع كل رسالة (وإلا المتصفح بيعرض «تم تحديث الموقع» بدالنا)')
    : bad('الـSW مش بيعرض إشعار');
str_contains($sw, "addEventListener('notificationclick'")
    ? ok('الضغط على الإشعار بيفتح الصفحة المقصودة')
    : bad('الضغط على الإشعار ما بيعملش حاجة');
preg_match("/VERSION\s*=\s*'([^']+)'/", $sw, $vm) && $vm[1] !== 'alm-v2'
    ? ok("نسخة الكاش اتزوّدت ({$vm[1]}) فالأجهزة هتاخد الـSW الجديد")
    : bad('نسخة الـSW ما اتزوّدتش — الأجهزة المثبَّتة مش هتستقبل إشعارات');

$head = code_only((string) file_get_contents($root . '/app/Views/_pwa_head.php'));
str_contains($head, 'window.almPush') ? ok('واجهة `almPush` موجودة للمكتب والمندوب') : bad('واجهة الاشتراك ناقصة');
str_contains($head, 'Notification.requestPermission')
    ? ok('طلب الإذن موجود')
    : bad('مفيش طلب إذن');
// ⚠️ الطلب لازم يكون جوّه `enable()` اللي الزرار بينادّيها — مش وقت التحميل
!preg_match('/addEventListener\([\'"]load[\'"][^)]*requestPermission/s', $head)
    ? ok('الإذن بيتطلب بضغطة مش وقت التحميل (المتصفح بيحرق النطاق لو اتطلب تلقائي)')
    : bad('⚠️ الإذن بيتطلب وقت التحميل — المتصفح هيرفضه للأبد');

/* ══════════════ 8) العزل والكتم ══════════════ */
sec('8) العزل المالي وكتم سطر الأوامر');

!PushNotify::enabled()
    ? ok('الإشعارات **مكتومة في سطر الأوامر** (الهجرات ما بتبعتش مئات الرسائل)')
    : bad('⚠️ سطر الأوامر بيبعت إشعارات حقيقية');

foreach (['app/Models/PushNotify.php', 'app/Models/PushSubscription.php', 'app/Core/WebPush.php'] as $f) {
    $src = code_only((string) file_get_contents($root . '/' . $f));
    $hit = [];
    foreach (['daily_closings', 'manual_custody', 'permit_stages', 'Custody::', 'Closing::'] as $needle) {
        if (str_contains($src, $needle)) { $hit[] = $needle; }
    }
    $hit ? bad(basename($f) . ' بيلمس حساب مالي: ' . implode(', ', $hit))
         : ok(basename($f) . ' مالوش أي علاقة بالخزنة ولا التقفيل');
}

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
