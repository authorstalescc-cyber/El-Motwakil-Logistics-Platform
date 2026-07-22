<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * ترحيل v40 — أدوار مستخدمي المكتب وحماية محاولات الدخول.
 *
 * - أول مستخدم حالي يصبح owner، وبقية المستخدمين الحاليين manager للحفاظ على العمل.
 * - المستخدمون الجدد افتراضيًا read_only، وSettingsController يكتب الدور صراحة.
 * - جدول المحاولات لا يخزن اسم المستخدم أو عنوان IP خامًا، بل SHA-256 فقط.
 *
 * التشغيل: php bin/migrate_v40.php --dry-run
 *          php bin/migrate_v40.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

$dry = in_array('--dry-run', $argv ?? [], true);
function v40say(string $m): void { echo $m . PHP_EOL; }
function v40col(string $name): bool {
    return (bool) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema=DATABASE() AND table_name='users' AND column_name=?", [$name]
    );
}
function v40table(string $name): bool {
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', [$name]
    );
}
function v40index(string $table, string $name): bool {
    return (bool) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?',
        [$table, $name]
    );
}

v40say('ترحيل v40 — أدوار المكتب وحماية الدخول' . ($dry ? ' [معاينة]' : ''));
if (!v40table('users')) { v40say('✗ جدول users غير موجود.'); exit(1); }

$columns = [
    'role'           => "VARCHAR(20) NOT NULL DEFAULT 'read_only' AFTER display_name",
    'failed_tries'   => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER can_free_edit',
    'locked_until'   => 'DATETIME NULL AFTER failed_tries',
    'last_failed_at' => 'DATETIME NULL AFTER locked_until',
    'last_login_at'  => 'DATETIME NULL AFTER last_failed_at',
];
$missing = array_filter(array_keys($columns), fn(string $c): bool => !v40col($c));
foreach ($missing as $c) { v40say("  + users.$c"); }
if (!v40table('auth_login_attempts')) { v40say('  + auth_login_attempts'); }

if ($dry) {
    v40say('✓ المعاينة انتهت بلا كتابة.');
    exit(0);
}

$roleAdded = in_array('role', $missing, true);
foreach ($missing as $name) {
    Database::exec("ALTER TABLE users ADD COLUMN `$name` {$columns[$name]}");
}

if ($roleAdded) {
    Database::exec("UPDATE users SET role='manager'");
    $ownerId = (int) Database::scalar('SELECT MIN(id) FROM users');
    if ($ownerId > 0) { Database::exec("UPDATE users SET role='owner' WHERE id=?", [$ownerId]); }
}
Database::exec("UPDATE users SET role='read_only' WHERE role NOT IN ('owner','manager','accountant','operator','read_only') OR role IS NULL");

if (!v40index('users', 'idx_users_role')) {
    Database::exec('ALTER TABLE users ADD KEY idx_users_role (role)');
}
if (!v40index('users', 'idx_users_locked')) {
    Database::exec('ALTER TABLE users ADD KEY idx_users_locked (locked_until)');
}

if (!v40table('auth_login_attempts')) {
    Database::exec(
        "CREATE TABLE auth_login_attempts (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            username_hash CHAR(64) NOT NULL,
            ip_hash       CHAR(64) NOT NULL,
            was_success   TINYINT(1) NOT NULL DEFAULT 0,
            attempted_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_login_ip_time (ip_hash, was_success, attempted_at),
            KEY idx_login_user_time (username_hash, attempted_at),
            KEY idx_login_cleanup (attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin"
    );
}

$owners = (int) Database::scalar("SELECT COUNT(*) FROM users WHERE role='owner'");
if ($owners < 1 && (int) Database::scalar('SELECT COUNT(*) FROM users') > 0) {
    Database::exec("UPDATE users SET role='owner' WHERE id=(SELECT id FROM (SELECT MIN(id) id FROM users) x)");
}

v40say('✓ اكتمل v40. أول حساب قائم مالك، وبقية الحسابات القائمة مديرون.');
