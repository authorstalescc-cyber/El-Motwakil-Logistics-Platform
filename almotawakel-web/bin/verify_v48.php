<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص v48 — تقوية دخول المناديب (المرحلة 2 من خطة الأمان).
 *
 * أهم 4 حاجات بيتأكد منها **باختبار حقيقي مش بقراءة الكود**:
 *   1) **القفل بيتصاعد فعلاً** — بيعمل حساب وهمي ويغلط عليه 20 مرة ويتأكد إن
 *      `failed_tries` **ما بيتصفّرش** وإن مدة القفل بتكبر (10→30→120→720).
 *      ده كان الباج الأصلي: التصفير كان بيخلّي التصعيد ما يحصلش أبدًا.
 *   2) **المحاولات بتتسجّل** في `auth_login_attempts` (قبل كده ما كانش فيه أي
 *      أثر لهجوم على بوابة المناديب).
 *   3) **مفيش كشف للحسابات** — رسالة الفشل واحدة سواء الحساب موجود أو لأ أو
 *      متوقف.
 *   4) **العزل المالي** — رصيد الخزنة وعهدة المندوب ما بيتغيّروش.
 *
 * آمن: كل حاجة جوّه transaction وبيعمل rollback في الآخر، فما بيسيبش أي أثر.
 *
 * التشغيل:  php bin/verify_v48.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\RepAuth;
use App\Models\Closing;

$pass = 0; $fail = 0; $skip = 0;

function ok(string $m): void   { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  ✗ $m\n"; }
function skip(string $m): void { global $skip; $skip++; echo "  ~ $m (تخطّي)\n"; }
function sec(string $m): void  { echo "\n" . $m . "\n" . str_repeat('─', 60) . "\n"; }
function is_(string $label, $actual, $expected): void
{
    $actual == $expected
        ? ok($label)
        : bad("$label — متوقع: " . var_export($expected, true) . " / فعلي: " . var_export($actual, true));
}

echo "فحص v48 — تقوية دخول المناديب\n" . str_repeat('=', 60) . "\n";

if (!RepAuth::pinMetaReady()) {
    echo "\n✗ أعمدة v48 مش موجودة — شغّل php bin/migrate_v48.php الأول.\n";
    exit(1);
}

$balanceBefore = Closing::currentBalance();

Database::exec('START TRANSACTION');
$exit = 0;

try {
    // ═══ 1) سياسة الرقم السري ═══════════════════════════════════════════
    sec('1) سياسة الرقم السري');
    is_('الحد الأدنى بقى 6 خانات', RepAuth::PIN_MIN, 6);
    is_('الحد الأقصى 12 خانة', RepAuth::PIN_MAX, 12);

    $pins = [];
    for ($i = 0; $i < 200; $i++) { $pins[] = RepAuth::randomPin(); }
    $allSix   = count(array_filter($pins, fn($p) => preg_match('/^\d{6}$/', $p))) === 200;
    $noRepeat = count(array_filter($pins, fn($p) => preg_match('/^(\d)\1+$/', $p))) === 0;
    $noSeq    = !in_array('123456', $pins, true) && !in_array('654321', $pins, true);
    $variety  = count(array_unique($pins)) > 190;   // عشوائية حقيقية مش قيمة ثابتة
    $allSix   ? ok('randomPin بيطلّع 6 أرقام دايمًا (200 عينة)') : bad('randomPin طلّع طول غلط');
    $noRepeat ? ok('بيتجنّب الأرقام المتكرّرة (111111)')          : bad('طلّع رقم متكرّر');
    $noSeq    ? ok('بيتجنّب المتسلسل (123456/654321)')            : bad('طلّع رقم متسلسل');
    $variety  ? ok('عشوائي فعلاً (200 عينة ≈ كلها مختلفة)')       : bad('العشوائية ضعيفة');

    // ═══ 2) حساب اختبار ══════════════════════════════════════════════════
    sec('2) تجهيز حساب مندوب للاختبار');
    Database::exec("INSERT INTO reps (name, status) VALUES ('__فحص_v48__','active')");
    $repId = Database::lastId();
    ok("اتعمل مندوب اختبار #$repId");

    $user = 'v48test' . $repId;
    $res  = RepAuth::setCredentials($repId, $user, '1234');
    is_('رفض رقم سري 4 خانات (القاعدة القديمة)', $res['ok'], false);
    $res = RepAuth::setCredentials($repId, $user, '12345');
    is_('رفض رقم سري 5 خانات', $res['ok'], false);

    $goodPin = '731904';
    $res = RepAuth::setCredentials($repId, $user, $goodPin);
    is_('قبول رقم سري 6 خانات', $res['ok'], true);

    $acc = RepAuth::accountFor($repId);
    is_('الحساب الجديد مش متعلّم «ضعيف»', (int) $acc['weak_pin'], 0);
    (!empty($acc['pin_set_at'])) ? ok('pin_set_at اتسجّل') : bad('pin_set_at فاضي');

    // ═══ 3) القفل المتصاعد — الاختبار الأهم ══════════════════════════════
    sec('3) القفل المتصاعد (الباج الأصلي)');
    $lockAfter = static function (int $n) use ($user, $repId): array {
        for ($i = 0; $i < $n; $i++) { RepAuth::attempt($user, '000000'); }
        return Database::row("SELECT failed_tries, locked_until FROM rep_users WHERE rep_id = ?", [$repId]);
    };
    // ⚠️ المدة بتتقاس بساعة **MySQL** مش PHP. لو قِسناها بـPHP الفحص بيفشل
    //    غلط على الإنتاج (MySQL بـUTC وPHP بـCairo = 3 ساعات فرق) — وده بالظبط
    //    اللي كشف إن القفل نفسه كان معطّل. القياس على نفس ساعة الكتابة.
    $mins = static fn(?string $until): int => $until
        ? (int) Database::scalar('SELECT TIMESTAMPDIFF(MINUTE, NOW(), ?)', [$until])
        : 0;

    $r = $lockAfter(5);
    is_('بعد 5 غلطات: العدّاد = 5 (ما اتصفّرش)', (int) $r['failed_tries'], 5);
    $m1 = $mins($r['locked_until']);
    ($m1 >= 9 && $m1 <= 11) ? ok("القفل الأول ≈ 10 دقايق (فعلي $m1)") : bad("القفل الأول $m1 دقيقة مش 10");

    // ⚠️ الفحص ده هو اللي بيمسك مصيدة فرق التوقيت: مش مهم إن العمود اتكتب
    //    صح، المهم إن القفل **بيمنع الدخول فعلاً**. قبل الإصلاح كان القفل
    //    القصير (10 دقايق) بيتقرا في PHP على إنه عدّى من 170 دقيقة فما كانش
    //    بيمنع أي حاجة — يعني حماية التخمين كانت معطّلة بالكامل على الإنتاج.
    $blocked = RepAuth::attempt($user, $goodPin);
    is_('القفل الأول (10 دقايق) بيمنع الدخول بالرقم الصح فعلاً', $blocked['ok'], false);

    // نفكّ القفل يدويًّا عشان نكمّل التصعيد (زي ما الوقت هيعدّي)
    $unlock = static fn() => Database::exec(
        "UPDATE rep_users SET locked_until = NULL WHERE rep_id = ?", [$repId]);

    $unlock(); $r = $lockAfter(5);
    is_('بعد 10 غلطات: العدّاد = 10', (int) $r['failed_tries'], 10);
    $m2 = $mins($r['locked_until']);
    ($m2 >= 29 && $m2 <= 31) ? ok("القفل التاني ≈ 30 دقيقة (فعلي $m2)") : bad("القفل التاني $m2 مش 30");

    $unlock(); $r = $lockAfter(5);
    $m3 = $mins($r['locked_until']);
    ($m3 >= 119 && $m3 <= 121) ? ok("القفل التالت ≈ ساعتين (فعلي $m3)") : bad("القفل التالت $m3 مش 120");

    $unlock(); $r = $lockAfter(5);
    $m4 = $mins($r['locked_until']);
    ($m4 >= 719 && $m4 <= 721) ? ok("القفل الرابع ≈ 12 ساعة (فعلي $m4)") : bad("القفل الرابع $m4 مش 720");

    ($m1 < $m2 && $m2 < $m3 && $m3 < $m4)
        ? ok('التصعيد شغّال: كل قفل أطول من اللي قبله')
        : bad('القفل مش بيتصاعد — الباج الأصلي رجع');

    // الحساب المقفول بيترفض حتى بالرقم الصح
    $r = RepAuth::attempt($user, $goodPin);
    is_('الرقم الصح بيترفض والحساب مقفول', $r['ok'], false);

    // ═══ 4) مفيش كشف للحسابات ════════════════════════════════════════════
    sec('4) رسايل الفشل ما بتكشفش الحسابات');
    $unlock();
    Database::exec("UPDATE rep_users SET failed_tries = 0 WHERE rep_id = ?", [$repId]);

    $msgWrongPin  = RepAuth::attempt($user, '999999')['msg'];
    $msgNoUser    = RepAuth::attempt('___مفيش_حساب_كده___', '999999')['msg'];
    is_('نفس الرسالة للحساب الموجود والمش موجود', $msgWrongPin, $msgNoUser);

    RepAuth::setStatus($repId, false);
    $msgDisabled = RepAuth::attempt($user, $goodPin)['msg'];
    is_('نفس الرسالة للحساب المتوقف', $msgDisabled, $msgNoUser);
    (stripos($msgWrongPin, 'فاضلك') === false)
        ? ok('مش بيقول «فاضلك كام محاولة» (كان بيكشف إن الحساب موجود)')
        : bad('لسه بيقول عدد المحاولات الفاضلة');
    RepAuth::setStatus($repId, true);

    // ═══ 5) تسجيل المحاولات ══════════════════════════════════════════════
    sec('5) تسجيل المحاولات (كان مفيش خالص)');
    $before = (int) Database::scalar("SELECT COUNT(*) FROM auth_login_attempts");
    RepAuth::attempt($user, '888888');
    $after = (int) Database::scalar("SELECT COUNT(*) FROM auth_login_attempts");
    ($after > $before)
        ? ok('محاولة المندوب اتسجّلت في auth_login_attempts')
        : bad('المحاولة ما اتسجّلتش — الهجوم هيفضل غير مرئي');

    $hashed = (int) Database::scalar(
        "SELECT COUNT(*) FROM auth_login_attempts WHERE username_hash = ? OR ip_hash = ?",
        [$user, '127.0.0.1']);
    is_('الاسم والـIP متشفّرين مش نص صريح', $hashed, 0);

    // ═══ 6) إبطال الجلسة ═════════════════════════════════════════════════
    sec('6) الجلسة بتتبطّل لما الحساب يتوقف');
    $_SESSION['rep_id']    = $repId;
    $_SESSION['rep_name']  = '__فحص_v48__';
    $_SESSION['rep_seen']  = time();
    $_SESSION['rep_check'] = 0;              // نجبر إعادة التحقق فورًا
    is_('الحساب الشغّال: الجلسة سليمة', RepAuth::check(), true);

    RepAuth::setStatus($repId, false);
    $_SESSION['rep_id']    = $repId;
    $_SESSION['rep_seen']  = time();
    $_SESSION['rep_check'] = 0;
    (new ReflectionProperty(RepAuth::class, 'statusOk'))->setValue(null, null);
    is_('بعد إيقاف الحساب: الجلسة اتبطّلت فورًا', RepAuth::check(), false);
    is_('والجلسة اتمسحت', $_SESSION['rep_id'] ?? null, null);
    RepAuth::setStatus($repId, true);

    sec('7) انتهاء الجلسة بالخمول');
    (new ReflectionProperty(RepAuth::class, 'statusOk'))->setValue(null, null);
    $_SESSION['rep_id']    = $repId;
    $_SESSION['rep_seen']  = time() - (9 * 3600);   // 9 ساعات سكون > 8
    $_SESSION['rep_check'] = time();
    is_('جلسة سكتت 9 ساعات اتقفلت', RepAuth::check(), false);

    (new ReflectionProperty(RepAuth::class, 'statusOk'))->setValue(null, null);
    $_SESSION['rep_id']    = $repId;
    $_SESSION['rep_seen']  = time() - (2 * 3600);   // ساعتين — جوّه المدة
    $_SESSION['rep_check'] = time();
    is_('جلسة سكتت ساعتين لسه شغّالة', RepAuth::check(), true);
    $_SESSION = [];

    // ═══ 8) العزل المالي ═════════════════════════════════════════════════
    sec('8) العزل المالي (v48 وصفية بحتة)');
    $balanceMid = Closing::currentBalance();
    (abs($balanceBefore - $balanceMid) < 0.0001)
        ? ok('رصيد الخزنة ما اتغيّرش خلال الفحص كله')
        : bad("رصيد الخزنة اتغيّر: $balanceBefore → $balanceMid");

} catch (\Throwable $e) {
    bad('استثناء: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    $exit = 1;
} finally {
    Database::exec('ROLLBACK');
    echo "\n(اترجع كل شيء — الفحص ما سابش أي أثر)\n";
}

// ═══ 9) فحوصات على الكود نفسه ════════════════════════════════════════════
sec('9) ضمانات في الكود');
$src = (string) file_get_contents(dirname(__DIR__) . '/app/Core/RepAuth.php');
(!preg_match('/failed_tries\s*=\s*0\s*,\s*\R?\s*locked_until\s*=\s*DATE_ADD/i', $src))
    ? ok('مفيش تصفير للعدّاد وقت القفل (الباج الأصلي)')
    : bad('العدّاد لسه بيتصفّر وقت القفل — التصعيد مش هيشتغل');
(str_contains($src, 'IP_MAX_FAILURES')) ? ok('فيه حد على مستوى الـIP') : bad('مفيش حد IP');
(str_contains($src, 'DUMMY_HASH'))      ? ok('هاش وهمي ضد كشف الحسابات بالتوقيت') : bad('مفيش هاش وهمي');
(str_contains($src, 'IDLE_MINUTES'))    ? ok('فيه انتهاء بالخمول') : bad('مفيش انتهاء بالخمول');
(str_contains($src, 'Auth::logout()'))  ? ok('الخروج بينهي الكوكي كمان') : bad('الخروج مش بينهي الكوكي');
(preg_match('/\\\\d\{\'\s*\.\s*self::PIN_MIN|PIN_MIN\s*=\s*6/', $src))
    ? ok('الحد الأدنى للرقم السري مربوط بثابت واحد') : bad('الحد الأدنى مكتوب بالإيد');

$balanceAfter = Closing::currentBalance();
(abs($balanceBefore - $balanceAfter) < 0.0001)
    ? ok('رصيد الخزنة بعد الـrollback زي ما هو')
    : bad("رصيد الخزنة اتغيّر بعد rollback: $balanceBefore → $balanceAfter");

echo "\n" . str_repeat('=', 60) . "\n";
echo "نجح: $pass | فشل: $fail | تخطّي: $skip\n";
exit($fail > 0 ? 1 : $exit);
