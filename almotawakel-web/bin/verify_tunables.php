<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص الثوابت المدفونة اللي بقت إعدادات — المرحلة ٦ من docs/19.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  ليه الفاحص ده موجود
 * ═══════════════════════════════════════════════════════════════════════
 * ١١ قرار عمل كانوا **مدفونين كـ`const` في الكود**: عتبات الإشعارات الستة
 * (متأخّر · عاجل · إذن واقف · تأخّر التقفيل · العهدة قربت تخلص · ميعاد
 * الملخّص) وحدود الأمان الخمسة (محاولات الدخول · مدة القفل · الخمول · طول
 * كلمة السر · خمول بوابة المندوب). كلها قرارات المالك ومحبوسة في ملف PHP.
 *
 * ⚠️ **أخطر حاجة في التحويل ده إنه يغيّر سلوكًا بالسكوت.** الفاحص بيقفل ده
 *    بشرطين متعاكسين:
 *    ① **مفيش إعداد ⇒ الرقم = الثابت بالحرف** (قاعدة فاضية = صفر تغيير).
 *    ② **فيه إعداد ⇒ الرقم بيتغيّر فعلاً** (وإلا الإعداد زينة).
 *
 * ⚠️ وحدود الأمان ليها شرط تالت: **السقوط على الثابت لو القاعدة مش متاحة** —
 *    `Auth` بيشتغل في مسار الدخول نفسه وقبل ما القاعدة تبقى جاهزة، ولحظة بلا
 *    حماية أسوأ من إعداد مش شغّال.
 *
 * التشغيل:  php bin/verify_tunables.php
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Settings;
use App\Core\SettingsSchema;

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function sec(string $m): void { echo "\n" . $m . "\n" . str_repeat('─', 66) . "\n"; }

/** ⚠️ إجبارية قبل أي فحص نصّي — التعليق بيذكر أسماء الثوابت. */
function code_only(string $src): string
{
    return preg_replace('~/\*.*?\*/|(?<!:)//[^\n]*~s', '', $src) ?? $src;
}

$root = dirname(__DIR__);
echo "فحص الثوابت المتحوّلة لإعدادات (docs/19 · المرحلة ٦)\n" . str_repeat('=', 66) . "\n";

/**
 * الجدول ده هو **عقد التحويل**: المفتاح · الثابت الأصلي · قيمته الأصلية.
 * ⚠️ القيمة هنا مكتوبة بالإيد **عن قصد** — لو حد غيّر الثابت في الكود ونسي
 *    الافتراضي في السجل (أو العكس)، الفحص بيمسك الفرق. مصدران بيتقارنوا،
 *    مش مصدر بيتأكد من نفسه.
 */
$contract = [
    ['notify_submission_warn_hours',   'app/Models/NotifyWatch.php', 'SUBMISSION_WARN',     '4'],
    ['notify_submission_urgent_hours', 'app/Models/NotifyWatch.php', 'SUBMISSION_URGENT',   '24'],
    ['notify_permit_idle_days',        'app/Models/NotifyWatch.php', 'PERMIT_IDLE_DAYS',    '7'],
    ['notify_closing_late_days',       'app/Models/NotifyWatch.php', 'CLOSING_LATE_DAYS',   '2'],
    ['notify_custody_low',             'app/Models/NotifyWatch.php', 'CUSTODY_LOW',         '500'],
    ['notify_digest_hour',             'app/Models/NotifyWatch.php', 'DIGEST_HOUR',         '8'],
    ['auth_max_tries',                 'app/Core/Auth.php',          'MAX_TRIES',           '5'],
    ['auth_lock_minutes',              'app/Core/Auth.php',          'LOCK_MINUTES',        '10'],
    ['auth_idle_minutes',              'app/Core/Auth.php',          'IDLE_MINUTES',        '120'],
    ['auth_min_password',              'app/Core/Auth.php',          'MIN_PASSWORD_LENGTH', '8'],
    ['rep_idle_minutes',               'app/Core/RepAuth.php',       'IDLE_MINUTES',        '480'],
];

// ═══════════════════════════════════════════════════════════ ①
sec('① الافتراضي في السجل = الثابت في الكود بالحرف');

$src = [];
$mismatch = 0;
foreach ($contract as [$key, $file, $const, $value]) {
    $src[$file] = $src[$file] ?? code_only((string) file_get_contents($root . '/' . $file));
    // الثابت لسه موجود في الكود؟ (هو اللي بيتقع عليه لو القاعدة مش متاحة)
    if (!preg_match('~const\s+' . preg_quote($const, '~') . '\s*=\s*([0-9.]+)~', $src[$file], $m)) {
        bad("الثابت {$const} اتشال من {$file} — مفيش سقوط آمن"); $mismatch++; continue;
    }
    /* ⚠️ **المقارنة رقمية مش نصّية**: أول نسخة كانت بتقصّ الأصفار بـ`rtrim`
       عشان `500.0` تساوي `500` — و`rtrim('10','0')` بتدّي **`1`**، فالفحص فشل
       على ٣ قيم سليمة. المقارنة كـ`float` بتحلّ الاتنين. */
    $codeVal   = $m[1];
    $schemaVal = SettingsSchema::defaultOf($key);
    if ((float) $codeVal !== (float) $schemaVal || (float) $codeVal !== (float) $value) {
        bad("«{$key}»: الكود {$codeVal} · السجل {$schemaVal} · العقد {$value}");
        $mismatch++;
    }
}
$mismatch === 0 ? ok('الـ' . count($contract) . ' ثابت: الكود والسجل والعقد متطابقين') : null;

// ═══════════════════════════════════════════════════════════ ②
sec('② مفيش إعداد ⇒ السلوك = الثابت بالحرف');

$pdo = Database::conn();
$pdo->beginTransaction();
try {

foreach ($contract as [$key, , , $value]) {
    Database::exec('DELETE FROM settings WHERE setting_key = ?', [$key]);
}
\App\Core\App::flushSettingsCache();

$viaSettings = (string) Settings::get('auth_min_password');
$viaAuth     = (string) Auth::minPasswordLength();
($viaAuth === '8' && $viaSettings === '8')
    ? ok('طول كلمة السر بلا إعداد = ٨ (الثابت)')
    : bad("طول كلمة السر بلا إعداد = {$viaAuth}");

/* ⚠️ الفحص على **السلوك** مش على القيمة المخزَّنة: بنسأل الدالة اللي النظام
   بينادي عليها فعلاً، مش `Settings::get` اللي ممكن ترجّع الافتراضي وتخفي
   إن نقطة الاستدعاء لسه بتقرا الثابت مباشرةً. */
$rp = new ReflectionClass(\App\Models\NotifyWatch::class);
$methods = ['submissionWarn' => 4, 'submissionUrgent' => 24, 'permitIdleDays' => 7,
            'closingLateDays' => 2, 'custodyLow' => 500.0, 'digestHour' => 8];
$bad = 0;
foreach ($methods as $name => $expected) {
    if (!$rp->hasMethod($name)) { bad("دالة {$name} مش موجودة"); $bad++; continue; }
    $got = $rp->getMethod($name)->invoke(null);
    if (abs((float) $got - (float) $expected) > 0.001) { bad("{$name} رجّعت {$got} بدل {$expected}"); $bad++; }
}
$bad === 0 ? ok('عتبات الإشعارات الستة بترجع قيمها الأصلية بلا إعداد') : null;

// ═══════════════════════════════════════════════════════════ ③
sec('③ فيه إعداد ⇒ السلوك بيتغيّر فعلاً');

$_SESSION = ['uid' => (int) Database::scalar("SELECT MIN(id) FROM users WHERE role='owner'"),
             'role' => 'owner', 'uname' => 'فحص', 'last_activity' => time(), 'uid_checked' => time()];

Settings::set('auth_min_password', '12');
(int) Auth::minPasswordLength() === 12
    ? ok('تغيير طول كلمة السر بيوصل للحارس فعلاً') : bad('الإعداد ما أثّرش على Auth');

Settings::set('notify_custody_low', '1500');
abs((float) $rp->getMethod('custodyLow')->invoke(null) - 1500.0) < 0.001
    ? ok('وعتبة «العهدة قربت تخلص» بتتغيّر') : bad('عتبة العهدة ما اتغيّرتش');

Settings::set('notify_digest_hour', '6');
(int) $rp->getMethod('digestHour')->invoke(null) === 6 ? ok('وميعاد الملخّص اليومي') : bad('ميعاد الملخّص ما اتغيّرش');

// ═══════════════════════════════════════════════════════════ ④
sec('④ النطاقات محروسة');

$denied = static function (callable $fn): bool {
    try { $fn(); return false; } catch (\Throwable $e) { return true; }
};
$denied(static fn() => Settings::set('auth_min_password', '2'))
    ? ok('طول كلمة سر تحت الحد بيترفض — الإعداد مش باب لإضعاف الأمان')
    : bad('اتقبل طول كلمة سر خطر');
$denied(static fn() => Settings::set('notify_digest_hour', '30'))
    ? ok('ساعة برّه اليوم بترفض') : bad('اتقبلت ساعة مستحيلة');
$denied(static fn() => Settings::set('auth_max_tries', '1'))
    ? ok('محاولة واحدة بترفض — كانت هتقفل الناس على أول غلطة')
    : bad('اتقبلت محاولة واحدة');

// ═══════════════════════════════════════════════════════════ ⑤
sec('⑤ السقوط الآمن لو القاعدة مش متاحة');

/* ⚠️ `Auth` و`RepAuth` بيشتغلوا في مسار الدخول وقبل ما القاعدة تبقى جاهزة.
   القراءة **لازم** تكون في `try` والسقوط على الثابت — لحظة بلا حماية أسوأ
   من إعداد مش شغّال. الفحص على وجود الحارس في الكود. */
foreach (['app/Core/Auth.php' => 'limit', 'app/Core/RepAuth.php' => 'idleMinutes'] as $file => $fn) {
    $code = code_only((string) file_get_contents($root . '/' . $file));
    if (!preg_match('~function\s+' . $fn . '\([^)]*\)\s*:\s*int\s*\{(.*?)\n    \}~s', $code, $m)) {
        bad("دالة {$fn} مش موجودة في {$file}"); continue;
    }
    (str_contains($m[1], 'try') && str_contains($m[1], 'catch'))
        ? ok("{$file}: القراءة محميّة بـtry والسقوط على الثابت")
        : bad("{$file}: القراءة بلا try — عطل قاعدة بيرمي في مسار الدخول");
}

// ═══════════════════════════════════════════════════════════ ⑥
sec('⑥ الشاشة بتتبع الإعداد مش رقمًا مكتوبًا');

/* ⚠️ الشاشة كانت بتقول `minlength="8"` بالإيد و`Auth` بيتحقّق برقمه — أول ما
   المالك يغيّر الإعداد كان المتصفح هيقول رقمًا والخادم يرفض برقم تاني. */
$usersView = (string) file_get_contents($root . '/app/Views/settings/sections/users.php');
(str_contains($usersView, 'minPasswordLength()') && !preg_match('~minlength="8"~', $usersView))
    ? ok('شاشة المستخدمين بتقرا طول كلمة السر من الإعداد')
    : bad('الشاشة لسه فيها رقم مكتوب بالإيد');

} finally {
    $_SESSION = [];
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    \App\Core\App::flushSettingsCache();
}

echo "\n" . str_repeat('=', 66) . "\n";
echo "النتيجة: $pass ناجح · $fail فاشل\n";
if ($fail > 0) { echo "\n⚠️ التحويل غيّر سلوكًا أو الإعداد مش واصل — راجع الفوق.\n"; exit(1); }
echo "✓ الثوابت بقت إعدادات، والافتراضي زي ما كان بالحرف.\n";
exit(0);
