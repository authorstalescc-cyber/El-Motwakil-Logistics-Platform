<?php
namespace App\Core;

/** إنشاء نسخة MySQL دون وضع كلمة المرور في سطر أوامر العملية. */
class DatabaseBackup
{
    /** @return array{path:string,bytes:int,sha256:string} */
    public static function create(string $label = 'backup'): array
    {
        $cfg = App::config('db');
        $dir = App::basePath('storage/backups');
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('تعذّر تجهيز مجلد النسخ الاحتياطية.');
        }
        $safeLabel = preg_replace('/[^a-z0-9_-]+/i', '-', $label) ?: 'backup';
        $path = $dir . '/' . $safeLabel . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sql';

        /* ⚠️ استضافة الإنتاج (Hostinger) بتقفل كل دوال تشغيل العمليات
              (exec/shell_exec/proc_open/popen/passthru)، فـ`mysqldump` **مستحيل
              يتنادى من PHP هناك** حتى وهو موجود في `/usr/bin`. الزرار كان بيرمي
              رسالة «النسخ الاحتياطي متوقف على السيرفر ده» — دلوقتي بيقع على
              `SqlDump` (PDO خالص) فالزرار بيشتغل على الإنتاج زي المحلي بالظبط.
           ⚠️ mysqldump يفضل **المفضَّل** لما يكون متاحًا: هو المرجع، وأي فرق
              بينه وبين `SqlDump` نكتشفه من فرق النتيجة لا من نسخة مش مستعملة. */
        $dump = function_exists('proc_open') ? self::findBinary('mysqldump') : null;
        if ($dump === null) {
            $res = SqlDump::toFile($path);
            @chmod($path, 0600);
            return ['path' => $path, 'bytes' => $res['bytes'], 'sha256' => hash_file('sha256', $path)];
        }

        $cmd = [
            $dump,
            '--host=' . $cfg['host'], '--port=' . (int) $cfg['port'], '--user=' . $cfg['user'],
            '--default-character-set=utf8mb4', '--no-tablespaces', '--single-transaction',
        ];
        // ⚠️ `--set-gtid-purged` خيار **MySQL بس**. سيرفر الإنتاج بيشتغل MariaDB
        //    وmysqldump بتاعها بترمي «unknown variable» وتخرج بكود خطأ — يعني
        //    النسخة بتفشل. بنسأل القاعدة نفسها (بلا أي دالة تشغيل عمليات، لأنها
        //    مقفولة على الاستضافة) بدل ما نفترض.
        if (!self::isMariaDb()) {
            $cmd[] = '--set-gtid-purged=OFF';
        }
        array_push($cmd, '--routines', '--triggers', $cfg['name']);
        self::runToFile($cmd, $path, (string) ($cfg['pass'] ?? ''));
        @chmod($path, 0600);
        return ['path' => $path, 'bytes' => (int) filesize($path), 'sha256' => hash_file('sha256', $path)];
    }

    public static function findBinary(string $name): ?string
    {
        $candidates = [
            '/opt/homebrew/bin/' . $name,
            '/usr/local/bin/' . $name,
            '/usr/bin/' . $name,
        ];
        foreach ($candidates as $path) {
            if (is_executable($path)) { return $path; }
        }
        // shell_exec مقفولة على استضافة الإنتاج — نستخدمها لو متاحة بس.
        if (!function_exists('shell_exec')) { return null; }
        $resolved = trim((string) shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
        return $resolved !== '' && is_executable($resolved) ? $resolved : null;
    }

    /** MariaDB ولا MySQL؟ (من القاعدة نفسها — بلا تشغيل أي عملية) */
    private static function isMariaDb(): bool
    {
        static $maria = null;
        if ($maria === null) {
            try {
                $v = (string) Database::conn()->getAttribute(\PDO::ATTR_SERVER_VERSION);
            } catch (\Throwable $e) {
                $v = '';
            }
            $maria = stripos($v, 'mariadb') !== false;
        }
        return $maria;
    }

    private static function runToFile(array $cmd, string $path, string $password): void
    {
        $env = getenv();
        if ($password !== '') { $env['MYSQL_PWD'] = $password; }
        $pipes = [];
        $process = proc_open($cmd, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $path, 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, $env);
        if (!is_resource($process)) { throw new \RuntimeException('تعذّر تشغيل أداة النسخ الاحتياطي.'); }
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            @unlink($path);
            error_log('mysqldump failed: ' . trim((string) $stderr));
            throw new \RuntimeException('فشل إنشاء النسخة الاحتياطية.');
        }
    }
}
