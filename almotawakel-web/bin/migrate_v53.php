<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v53 — تفضيلات الإشعارات وموثوقية الإرسال والتأجيل
 *       (المراحل ٤ + ٥ + ٦ + ٧ من docs/15-notifications-master-plan.md).
 *
 * الترحيل ده بيغطّي **كل السكيما الباقية في الخطة دفعة واحدة** بدل أربع هجرات
 * على نفس الجدولين — كل واحدة كانت هتقفل الجدول من تاني بلا داعي.
 *
 *   • `notification_prefs`                     — نوع × قناة لكل شخص (المرحلة ٤)
 *   • `notification_recipients.in_app`         — إخفاء نوع من جرس شخص معيّن (٤)
 *   • `notification_recipients.push_last_try`  — تباعد متزايد لإعادة المحاولة (٥)
 *   • `notification_recipients.snoozed_until`  — «فكّرني بعدين» (٦)
 *   • فهرس الطابور اتوسّع عشان الاستعلام الجديد
 *
 * ⚠️ **الغياب = مفعّل** (قرار الخطة §٤): مفيش صف تفضيلات معناه إن كل الأنواع
 *    شغّالة على القناتين. يعني الترحيل ده **ما بيغيّرش سلوك أي مستخدم قايم** —
 *    ولا صف بيتزرع، والكل بيفضل ياخد كل حاجة زي ما هو لحد ما يغيّر بنفسه.
 *
 * ⚠️ **`in_app` بيتحسب وقت التسجيل مش وقت العرض.** لو حسبناه وقت العرض، تغيير
 *    التفضيل كان هيخفي أحداثًا **قديمة** من السجل بأثر رجعي — والسجل المفروض
 *    يفضل شاهدًا على اللي حصل. فالتفضيل بيأثّر على الجديد بس.
 *
 * ⛔ **مفيش «ساعات هدوء»** — اتشالت من الخطة بقرار المالك (§٧.٤). ما ترجّعش
 *    الفكرة دي ولا تضيف أعمدة ليها.
 *
 * ⚠️ **وحدة وصفية بحتة — صفر أثر مالي.** الترحيل بيتحقّق إن رصيد الخزنة ما
 *    اتغيّرش ولا مليم.
 *
 * idempotent — عادي تشغّلها أكتر من مرة.
 *
 * التشغيل:  php bin/migrate_v53.php        (أو تلقائيًّا من bin/migration.php)
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;

$v53BalanceBefore = Closing::currentBalance();

$v53TableExists = static fn(string $t): bool => (bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = ?", [$t]);

$v53ColExists = static fn(string $t, string $c): bool => (bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?", [$t, $c]);

$v53IndexExists = static fn(string $t, string $i): bool => (bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?", [$t, $i]);

$v53Say = static function (string $line): void { echo $line . PHP_EOL; };

$v53Up = static function () use ($v53TableExists, $v53ColExists, $v53IndexExists, $v53Say): void {
    // الجدولان الأساسيان من v52 لازم يكونوا موجودين الأول
    if (!$v53TableExists('notification_recipients')) {
        throw new \RuntimeException('v53 محتاجة v52 الأول — شغّل php bin/migrate_v52.php');
    }

    /* ── ① تفضيلات الإشعارات (المرحلة ٤) ─────────────────────────────────── */
    if (!$v53TableExists('notification_prefs')) {
        // ⚠️ `party_key` نصّي **بلا FK** (تحذير ٣): حذف مستخدم ما يكسرش الجدول.
        Database::exec(<<<'SQL'
CREATE TABLE notification_prefs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  party_type ENUM('user','rep') NOT NULL,
  party_key VARCHAR(120) NOT NULL,
  kind VARCHAR(40) NOT NULL,
  in_app TINYINT(1) NOT NULL DEFAULT 1,
  push TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pref_party_kind (party_type, party_key, kind),
  INDEX idx_pref_party (party_type, party_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $v53Say('  ✚ اتعمل جدول notification_prefs (نوع × قناة لكل شخص).');
    } else {
        $v53Say('  = جدول notification_prefs موجود بالفعل.');
    }

    /* ── ② أعمدة على notification_recipients ─────────────────────────────── */
    // in_app: الحدث اتسجّل للشخص ده لكنه مقفول عنده — بيفضل في السجل العام
    // (تبويب المالك «كل النظام») وما بيظهرش في جرسه هو.
    if (!$v53ColExists('notification_recipients', 'in_app')) {
        Database::exec("ALTER TABLE notification_recipients
                        ADD COLUMN in_app TINYINT(1) NOT NULL DEFAULT 1 AFTER party_key");
        $v53Say('  ✚ اتضاف notification_recipients.in_app (الافتراضي 1 = كل القديم زي ما هو).');
    } else {
        $v53Say('  = العمود in_app موجود بالفعل.');
    }

    // push_last_try: أساس **التباعد المتزايد** — من غيره إعادة المحاولة بتفضل
    // تلفّ كل ٥ دقايق على جهاز ميت لحد ما توصل الحد.
    if (!$v53ColExists('notification_recipients', 'push_last_try')) {
        Database::exec("ALTER TABLE notification_recipients
                        ADD COLUMN push_last_try TIMESTAMP NULL DEFAULT NULL AFTER push_attempts");
        $v53Say('  ✚ اتضاف notification_recipients.push_last_try (تباعد متزايد).');
    } else {
        $v53Say('  = العمود push_last_try موجود بالفعل.');
    }

    // snoozed_until: «فكّرني بعد ساعة» — البند بيختفي من الجرس ويرجع لوحده.
    if (!$v53ColExists('notification_recipients', 'snoozed_until')) {
        Database::exec("ALTER TABLE notification_recipients
                        ADD COLUMN snoozed_until TIMESTAMP NULL DEFAULT NULL AFTER read_at");
        $v53Say('  ✚ اتضاف notification_recipients.snoozed_until (تأجيل).');
    } else {
        $v53Say('  = العمود snoozed_until موجود بالفعل.');
    }

    /* ── ③ فهرس الطابور الجديد ───────────────────────────────────────────── */
    // الاستعلام بقى بيفلتر على (push_state, push_attempts, push_last_try)
    if (!$v53IndexExists('notification_recipients', 'idx_notif_retry')) {
        Database::exec("ALTER TABLE notification_recipients
                        ADD INDEX idx_notif_retry (push_state, push_attempts, push_last_try)");
        $v53Say('  ✚ اتعمل فهرس idx_notif_retry (طابور إعادة المحاولة).');
    } else {
        $v53Say('  = فهرس idx_notif_retry موجود بالفعل.');
    }

    // فهرس الجرس بقى لازم يشوف in_app و snoozed_until كمان
    if (!$v53IndexExists('notification_recipients', 'idx_notif_bell')) {
        Database::exec("ALTER TABLE notification_recipients
                        ADD INDEX idx_notif_bell (party_type, party_key, in_app, read_at, id)");
        $v53Say('  ✚ اتعمل فهرس idx_notif_bell (استعلام الجرس بعد in_app).');
    } else {
        $v53Say('  = فهرس idx_notif_bell موجود بالفعل.');
    }
};

$v53Verify = static function () use ($v53TableExists, $v53ColExists, $v53IndexExists, $v53Say, $v53BalanceBefore): bool {
    $ok = true;
    $fail = static function (string $msg) use (&$ok, $v53Say): void { $ok = false; $v53Say('  ✗ ' . $msg); };

    if (!$v53TableExists('notification_prefs')) { $fail('جدول notification_prefs مش موجود.'); }
    foreach (['party_type', 'party_key', 'kind', 'in_app', 'push'] as $c) {
        if (!$v53ColExists('notification_prefs', $c)) { $fail("العمود notification_prefs.$c ناقص."); }
    }
    foreach (['in_app', 'push_last_try', 'snoozed_until'] as $c) {
        if (!$v53ColExists('notification_recipients', $c)) { $fail("العمود notification_recipients.$c ناقص."); }
    }
    foreach (['uq_pref_party_kind'] as $i) {
        if (!$v53IndexExists('notification_prefs', $i)) { $fail("الفهرس notification_prefs.$i ناقص."); }
    }
    foreach (['idx_notif_retry', 'idx_notif_bell'] as $i) {
        if (!$v53IndexExists('notification_recipients', $i)) { $fail("الفهرس $i ناقص."); }
    }

    // ⚠️ **الغياب = مفعّل**: الترحيل ممنوع يزرع أي صف تفضيلات، وإلا يبقى
    //    غيّر سلوك مستخدمين قايمين بلا ما يطلبوا.
    if ($v53TableExists('notification_prefs')) {
        $seeded = (int) Database::scalar("SELECT COUNT(*) FROM notification_prefs");
        // الصفوف الوحيدة المسموحة هي اللي المستخدمون كتبوها بأنفسهم بعد الترحيل،
        // فالتحقق هنا إن **الترحيل نفسه** ما زرعش حاجة عند أول تشغيل.
        if ($seeded > 0) {
            $v53Say('  = فيه ' . $seeded . ' تفضيل محفوظ (من المستخدمين — مش من الترحيل).');
        } else {
            $v53Say('  ✓ مفيش أي صف تفضيلات مزروع — الغياب = مفعّل.');
        }
    }

    // ⚠️ كل السجلات القديمة لازم تفضل ظاهرة في الجرس (in_app = 1)
    $hidden = (int) Database::scalar("SELECT COUNT(*) FROM notification_recipients WHERE in_app = 0");
    if ($hidden > 0) {
        $v53Say('  = فيه ' . $hidden . ' صف مقفول من جرس صاحبه (تفضيل شخصي).');
    } else {
        $v53Say('  ✓ كل السجلات ظاهرة في جرس أصحابها زي ما كانت.');
    }

    // ⚠️ **صفر عمود مالي** — جوهر عزل الوحدة
    $money = Database::all(
        "SELECT table_name, column_name FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name IN ('notifications','notification_recipients','notification_prefs')
           AND (column_name LIKE '%amount%' OR column_name LIKE '%balance%'
                OR column_name LIKE '%freight%' OR column_name LIKE '%custody%'
                OR column_name LIKE '%debt%')");
    if ($money) { $fail('اتلاقى عمود مالي في جداول الإشعارات — الوحدة دي وصفية بحتة.'); }

    // ⛔ ممنوع أي عمود «ساعات هدوء» (قرار المالك ٧.٤)
    $quiet = Database::all(
        "SELECT table_name, column_name FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name LIKE 'notification%'
           AND (column_name LIKE '%quiet%' OR column_name LIKE '%dnd%')");
    if ($quiet) { $fail('⛔ اتلاقى عمود «ساعات هدوء» — الفكرة دي اتشالت بقرار المالك.'); }

    $after = Closing::currentBalance();
    if (abs($after - $v53BalanceBefore) > 0.005) {
        $fail('رصيد الخزنة اتغيّر (' . number_format($v53BalanceBefore, 2)
            . ' ← ' . number_format($after, 2) . ') — الترحيل ده ممنوع يكون له أثر مالي.');
    }

    if ($ok) { $v53Say('  ✓ التحقق بعد التطبيق عدّى.'); }
    return $ok;
};

// ⚠️ مصيدة v49 الموثَّقة: الملف بيتنفّذ بطريقتين ولازم يشتغل صح في الاتنين.
$v53Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
if ($v53Direct) {
    echo "v53 — تفضيلات الإشعارات والموثوقية والتأجيل\n" . str_repeat('=', 60) . "\n";
    $v53Up();
    if ($v53Verify() !== true) {
        fwrite(STDERR, "فشل التحقق بعد تطبيق v53.\n");
        exit(1);
    }
    echo "تمام.\n";
}

return ['up' => $v53Up, 'verify' => $v53Verify];
