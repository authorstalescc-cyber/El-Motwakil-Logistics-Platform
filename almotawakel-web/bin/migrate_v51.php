<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v51 — إشعارات التطبيق (Web Push): تلقائية + مخصّصة.
 *
 * المالك بقى يوصله رنّ على موبايله أول ما يحصل حدث مهم (إذن جديد · تقفيل يوم ·
 * بند مندوب جديد · بوليصة جديدة)، والمندوب بيوصله قرار بنده (اعتماد/رفض)،
 * وفيه شاشة `/push` لإرسال إشعار مكتوب باليد لكل الناس أو لفئة.
 *
 * الجداول:
 *   • `push_subscriptions` — جهاز واحد = صف. الاشتراك بيتعمل من المتصفح بعد ما
 *     صاحبه يدوس «فعّل الإشعارات» بنفسه (مفيش طريقة تفعّلها له غصب، وده مقصود
 *     من المتصفحات نفسها).
 *   • `push_messages` — سجل كل إشعار اتبعت: مين بعته ولمين ونجح كام وفشل كام.
 *
 * ومفاتيح VAPID بتتولّد هنا **مرّة واحدة** وبتتخزّن في `settings`:
 *   ⚠️ **لو المفاتيح دي اتغيّرت، كل الأجهزة المشتركة بتبطل** ولازم كل واحد
 *      يفعّل الإشعارات من تاني. الهجرة ما بتولّدهاش تاني لو موجودة.
 *
 * ⚠️ **وحدة معزولة تمامًا — مفيش أي أثر مالي** (نفس عزل `dashboard_notes`
 *    و`shipment_tracks`): مفيش عمود فلوس، ولا بتدخل خزنة ولا تقفيل ولا عهدة.
 *    الهجرة بتتحقّق إن رصيد الخزنة ما اتغيّرش ولا مليم.
 *
 * idempotent — عادي تشغّلها أكتر من مرة.
 *
 * التشغيل:  php bin/migrate_v51.php        (أو تلقائيًّا من bin/migration.php)
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Database;
use App\Core\WebPush;
use App\Models\Closing;

/** رصيد الخزنة قبل أي كتابة — بيتقارن في verify. */
$v51BalanceBefore = Closing::currentBalance();

$v51TableExists = static fn(string $t): bool => (bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = ?", [$t]);

$v51ColExists = static fn(string $t, string $c): bool => (bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?", [$t, $c]);

$v51Say = static function (string $line): void { echo $line . PHP_EOL; };

$v51Up = static function () use ($v51TableExists, $v51Say): void {
    if (!$v51TableExists('push_subscriptions')) {
        Database::exec(<<<'SQL'
CREATE TABLE push_subscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  audience ENUM('office','rep') NOT NULL,
  user_name VARCHAR(120) NULL,
  rep_id INT UNSIGNED NULL,
  endpoint TEXT NOT NULL,
  endpoint_hash CHAR(64) NOT NULL,
  p256dh VARCHAR(255) NOT NULL,
  auth_secret VARCHAR(64) NOT NULL,
  user_agent VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  fail_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_ok_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_push_endpoint (endpoint_hash),
  INDEX idx_push_audience (audience, is_active),
  INDEX idx_push_rep (rep_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $v51Say('  ✚ اتعمل جدول push_subscriptions (جهاز واحد = صف).');
    } else {
        $v51Say('  = جدول push_subscriptions موجود بالفعل.');
    }

    if (!$v51TableExists('push_messages')) {
        Database::exec(<<<'SQL'
CREATE TABLE push_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(30) NOT NULL,
  audience VARCHAR(30) NOT NULL,
  title VARCHAR(120) NOT NULL,
  body VARCHAR(255) NOT NULL,
  url VARCHAR(255) NULL,
  sent_by VARCHAR(120) NULL,
  ok_count INT UNSIGNED NOT NULL DEFAULT 0,
  fail_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_push_msg_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $v51Say('  ✚ اتعمل جدول push_messages (سجل الإشعارات المرسَلة).');
    } else {
        $v51Say('  = جدول push_messages موجود بالفعل.');
    }

    // ⚠️ المفاتيح بتتولّد **مرّة واحدة بس** — تغييرها بيبطّل كل الأجهزة المشتركة.
    if ((string) App::setting('push_vapid_public', '') === ''
        || (string) App::setting('push_vapid_private', '') === '') {
        $keys = WebPush::generateKeys();
        App::setSetting('push_vapid_public', $keys['public']);
        App::setSetting('push_vapid_private', $keys['private']);
        $v51Say('  ✚ اتولّدت مفاتيح الإشعارات (VAPID) وابتخزنت في settings.');
    } else {
        $v51Say('  = مفاتيح الإشعارات موجودة — ما اتلمستش (تغييرها بيبطّل الاشتراكات).');
    }
};

$v51Verify = static function () use ($v51TableExists, $v51ColExists, $v51Say, $v51BalanceBefore): bool {
    $ok = true;
    $fail = static function (string $msg) use (&$ok, $v51Say): void { $ok = false; $v51Say('  ✗ ' . $msg); };

    foreach (['push_subscriptions', 'push_messages'] as $t) {
        if (!$v51TableExists($t)) { $fail("جدول $t مش موجود."); }
    }
    if (!$ok) { return false; }

    foreach (['audience', 'user_name', 'rep_id', 'endpoint', 'endpoint_hash', 'p256dh',
              'auth_secret', 'is_active', 'fail_count'] as $c) {
        if (!$v51ColExists('push_subscriptions', $c)) { $fail("العمود push_subscriptions.$c ناقص."); }
    }
    foreach (['kind', 'audience', 'title', 'body', 'url', 'sent_by', 'ok_count', 'fail_count'] as $c) {
        if (!$v51ColExists('push_messages', $c)) { $fail("العمود push_messages.$c ناقص."); }
    }

    // المفتاح الفريد على الجهاز — بدونه نفس الموبايل بياخد الإشعار مرتين وتلاتة
    $uq = (bool) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = 'push_subscriptions'
           AND index_name = 'uq_push_endpoint'");
    if (!$uq) { $fail('المفتاح الفريد uq_push_endpoint ناقص — الجهاز هياخد الإشعار مكرّر.'); }

    if (!WebPush::available()) {
        $fail('مفاتيح الإشعارات مش جاهزة — `WebPush::available()` بترجّع false.');
    } else {
        $v51Say('  ✓ مفاتيح الإشعارات جاهزة والإرسال متاح على السيرفر ده.');
    }

    // ⚠️ **مفيش أي عمود مالي** — ده جوهر عزل الوحدة
    $money = Database::all(
        "SELECT table_name, column_name FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name IN ('push_subscriptions','push_messages')
           AND (column_name LIKE '%amount%' OR column_name LIKE '%balance%'
                OR column_name LIKE '%freight%' OR column_name LIKE '%custody%')");
    if ($money) { $fail('اتلاقى عمود مالي في جداول الإشعارات — الوحدة دي وصفية بحتة.'); }

    $after = Closing::currentBalance();
    if (abs($after - $v51BalanceBefore) > 0.005) {
        $fail('رصيد الخزنة اتغيّر (' . number_format($v51BalanceBefore, 2)
            . ' ← ' . number_format($after, 2) . ') — الهجرة دي ممنوع يكون لها أثر مالي.');
    }

    if ($ok) { $v51Say('  ✓ التحقق بعد التطبيق عدّى.'); }
    return $ok;
};

// ⚠️ مصيدة v49 الموثَّقة: الملف بيتنفّذ بطريقتين ولازم يشتغل صح في الاتنين.
$v51Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
if ($v51Direct) {
    echo "v51 — إشعارات التطبيق (Web Push)\n" . str_repeat('=', 60) . "\n";
    $v51Up();
    if ($v51Verify() !== true) {
        fwrite(STDERR, "فشل التحقق بعد تطبيق v51.\n");
        exit(1);
    }
    echo "تمام.\n";
}

return ['up' => $v51Up, 'verify' => $v51Verify];
