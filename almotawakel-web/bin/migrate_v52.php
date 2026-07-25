<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * v52 — أساس نظام الإشعارات الموحَّد (المرحلة ١ من docs/15-notifications-master-plan.md).
 *
 * قبل كده كان فيه **نظامين مالهمش أي علاقة ببعض**: الجرس بيعرض «حالة اللحظة»
 * مجمَّعة وبلا وقت ولا سجل، وإشعارات التطبيق بتبعت «أحداث» بوقتها. النتيجة إن
 * حاجة توصل موبايلك وما تلاقيهاش في النظام خالص، والعكس.
 *
 * الترحيل ده بيعمل **مصدر الحقيقة الواحد**:
 *   • `notifications`            — صف لكل حدث حصل (بوقته وصاحبه وموضوعه).
 *   • `notification_recipients`  — نسخة لكل شخص: هي دي اللي بتخلّي «مقروء»
 *     تخصّ **الشخص** لا المتصفح، وبتخلّي إعادة إرسال الفاشل ممكنة.
 *
 * ⚠️ **المرحلة دي بلا أي تغيير مرئي بالمرّة.** الشاشات زي ما هي بالحرف؛
 *    الأحداث بس بقت تتسجّل. العرض الجديد مكانه المرحلة ٢.
 *
 * ⚠️ **الحالات المستمرة ما بتتخزّنش هنا** (زي «٣ أيام مش متقفلة» و«أرقام سرية
 *    ضعيفة») — دي بتفضل محسوبة لحظيًّا في `App\Models\Notification`. الخلط بين
 *    «حدث وقع» و«حالة مستمرة» هو أصل مشكلة الإشعارات المضلِّلة.
 *
 * ⚠️ **وحدة وصفية بحتة — صفر أثر مالي.** مفيش عمود فلوس، ولا لمسة للخزنة ولا
 *    التقفيل ولا العهدة. الترحيل بيتحقّق إن رصيد الخزنة ما اتغيّرش ولا مليم.
 *
 * idempotent — عادي تشغّلها أكتر من مرة.
 *
 * التشغيل:  php bin/migrate_v52.php        (أو تلقائيًّا من bin/migration.php)
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;

$v52BalanceBefore = Closing::currentBalance();

$v52TableExists = static fn(string $t): bool => (bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = ?", [$t]);

$v52ColExists = static fn(string $t, string $c): bool => (bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?", [$t, $c]);

$v52IndexExists = static fn(string $t, string $i): bool => (bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?", [$t, $i]);

$v52Say = static function (string $line): void { echo $line . PHP_EOL; };

$v52Up = static function () use ($v52TableExists, $v52Say): void {
    if (!$v52TableExists('notifications')) {
        Database::exec(<<<'SQL'
CREATE TABLE notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(40) NOT NULL,
  severity ENUM('info','warn','urgent') NOT NULL DEFAULT 'info',
  actor_type ENUM('user','rep','system') NOT NULL DEFAULT 'system',
  actor_name VARCHAR(120) NULL,
  subject_type VARCHAR(30) NULL,
  subject_id INT UNSIGNED NULL,
  work_date DATE NULL,
  title VARCHAR(120) NOT NULL,
  body VARCHAR(255) NOT NULL DEFAULT '',
  url VARCHAR(255) NULL,
  meta_json TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notif_kind (kind, id),
  INDEX idx_notif_subject (subject_type, subject_id),
  INDEX idx_notif_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $v52Say('  ✚ اتعمل جدول notifications (صف لكل حدث).');
    } else {
        $v52Say('  = جدول notifications موجود بالفعل.');
    }

    if (!$v52TableExists('notification_recipients')) {
        // ⚠️ `party_key` نصّي **بلا FK** (تحذير ٣ في CLAUDE.md): حذف مستخدم أو
        //    مندوب ما يكسرش السجل التاريخي.
        Database::exec(<<<'SQL'
CREATE TABLE notification_recipients (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  notification_id INT UNSIGNED NOT NULL,
  party_type ENUM('user','rep') NOT NULL,
  party_key VARCHAR(120) NOT NULL,
  read_at TIMESTAMP NULL DEFAULT NULL,
  push_state ENUM('skipped','pending','sent','failed') NOT NULL DEFAULT 'pending',
  push_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  push_last_error VARCHAR(160) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_notif_party (notification_id, party_type, party_key),
  INDEX idx_notif_inbox (party_type, party_key, read_at, id),
  INDEX idx_notif_queue (push_state, push_attempts, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $v52Say('  ✚ اتعمل جدول notification_recipients (نسخة لكل شخص + حالة الإرسال).');
    } else {
        $v52Say('  = جدول notification_recipients موجود بالفعل.');
    }
};

$v52Verify = static function () use ($v52TableExists, $v52ColExists, $v52IndexExists, $v52Say, $v52BalanceBefore): bool {
    $ok = true;
    $fail = static function (string $msg) use (&$ok, $v52Say): void { $ok = false; $v52Say('  ✗ ' . $msg); };

    foreach (['notifications', 'notification_recipients'] as $t) {
        if (!$v52TableExists($t)) { $fail("جدول $t مش موجود."); }
    }
    if (!$ok) { return false; }

    foreach (['kind','severity','actor_type','actor_name','subject_type','subject_id',
              'work_date','title','body','url','meta_json','created_at'] as $c) {
        if (!$v52ColExists('notifications', $c)) { $fail("العمود notifications.$c ناقص."); }
    }
    foreach (['notification_id','party_type','party_key','read_at',
              'push_state','push_attempts','push_last_error'] as $c) {
        if (!$v52ColExists('notification_recipients', $c)) { $fail("العمود notification_recipients.$c ناقص."); }
    }

    // ⚠️ المفتاح الفريد هو اللي بيمنع نفس الشخص ياخد نفس الحدث مرتين
    if (!$v52IndexExists('notification_recipients', 'uq_notif_party')) {
        $fail('المفتاح الفريد uq_notif_party ناقص — الشخص ممكن ياخد نفس الإشعار مكرَّر.');
    }
    // فهرس صندوق الوارد: من غيره الجرس هيبطّأ لما الجدول يكبر
    if (!$v52IndexExists('notification_recipients', 'idx_notif_inbox')) {
        $fail('فهرس idx_notif_inbox ناقص — استعلام الجرس هيبقى بطيء.');
    }
    if (!$v52IndexExists('notification_recipients', 'idx_notif_queue')) {
        $fail('فهرس idx_notif_queue ناقص — عامل إعادة الإرسال هيبقى بطيء.');
    }

    // ⚠️ **صفر عمود مالي** — ده جوهر عزل الوحدة
    $money = Database::all(
        "SELECT table_name, column_name FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name IN ('notifications','notification_recipients')
           AND (column_name LIKE '%amount%' OR column_name LIKE '%balance%'
                OR column_name LIKE '%freight%' OR column_name LIKE '%custody%'
                OR column_name LIKE '%debt%')");
    if ($money) { $fail('اتلاقى عمود مالي في جداول الإشعارات — الوحدة دي وصفية بحتة.'); }

    $after = Closing::currentBalance();
    if (abs($after - $v52BalanceBefore) > 0.005) {
        $fail('رصيد الخزنة اتغيّر (' . number_format($v52BalanceBefore, 2)
            . ' ← ' . number_format($after, 2) . ') — الترحيل ده ممنوع يكون له أثر مالي.');
    }

    if ($ok) { $v52Say('  ✓ التحقق بعد التطبيق عدّى.'); }
    return $ok;
};

// ⚠️ مصيدة v49 الموثَّقة: الملف بيتنفّذ بطريقتين ولازم يشتغل صح في الاتنين.
$v52Direct = isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__);
if ($v52Direct) {
    echo "v52 — أساس نظام الإشعارات الموحَّد\n" . str_repeat('=', 60) . "\n";
    $v52Up();
    if ($v52Verify() !== true) {
        fwrite(STDERR, "فشل التحقق بعد تطبيق v52.\n");
        exit(1);
    }
    echo "تمام.\n";
}

return ['up' => $v52Up, 'verify' => $v52Verify];
