<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** تمرين استعادة حقيقي؛ يرفض قطعًا أي قاعدة لا تحمل بادئة الاختبار. */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Database;
use App\Core\DatabaseBackup;

$config = App::config('db');
$database = (string) $config['name'];
if (!preg_match('/^almotawakel_test_[a-z0-9_]{6,48}$/', $database)) {
    fwrite(STDERR, "يُرفض اختبار الاستعادة على قاعدة غير معزولة.\n");
    exit(2);
}

$snapshot = function (): array {
    return [
        'tables' => (int) Database::scalar('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()'),
        'migrations' => (int) Database::scalar('SELECT COUNT(*) FROM schema_migrations'),
        'users' => (int) Database::scalar('SELECT COUNT(*) FROM users'),
        'balances_count' => (int) Database::scalar('SELECT COUNT(*) FROM balances'),
        'balances_sum' => (string) Database::scalar('SELECT COALESCE(SUM(balance),0) FROM balances'),
    ];
};

$before = $snapshot();
$mysql = DatabaseBackup::findBinary('mysql');
if (!$mysql) { fwrite(STDERR, "أداة mysql غير موجودة للاستعادة.\n"); exit(1); }
$backup = DatabaseBackup::create('restore-drill');
echo "✓ أُنشئت نسخة تمرين بحجم {$backup['bytes']} وبصمة {$backup['sha256']}\n";

$server = Database::serverConn();
$server->exec("DROP DATABASE `$database`");
$server->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
Database::disconnect();

$environment = getenv();
if ((string) ($config['pass'] ?? '') !== '') { $environment['MYSQL_PWD'] = (string) $config['pass']; }
$pipes = [];
$process = proc_open([
    $mysql,
    '--host=' . $config['host'], '--port=' . (int) $config['port'], '--user=' . $config['user'],
    '--default-character-set=utf8mb4', $database,
], [
    0 => ['file', $backup['path'], 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $pipes, App::basePath(), $environment);
if (!is_resource($process)) { fwrite(STDERR, "تعذّر بدء الاستعادة.\n"); exit(1); }
$stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
$stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
$code = proc_close($process);
if ($code !== 0) {
    error_log('restore drill failed: ' . trim($stdout . "\n" . $stderr));
    fwrite(STDERR, "فشلت الاستعادة؛ احتُفظ بملف النسخة: {$backup['path']}\n");
    exit(1);
}

Database::disconnect();
$after = $snapshot();
if ($after !== $before) {
    fwrite(STDERR, 'عدم تطابق بعد الاستعادة: ' . json_encode(['before' => $before, 'after' => $after]) . "\n");
    exit(1);
}
unlink($backup['path']);
echo '✓ نجحت الاستعادة وتطابقت أعداد الجداول والترحيلات والمستخدمين والأرصدة.' . PHP_EOL;
