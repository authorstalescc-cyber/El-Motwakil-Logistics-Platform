<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\App;

class BackupController extends Controller
{
    /** تنزيل نسخة احتياطية SQL من قاعدة البيانات */
    public function download(): void
    {
        $this->requireAuth();
        $this->requireCsrf();

        $cfg = App::config('db');
        $stamp = date('Y-m-d_H-i');
        $filename = "almotawakel-backup-{$stamp}.sql";

        // مسار mysqldump (Homebrew أو النظام)
        $dump = $this->findMysqldump();
        if (!$dump) {
            http_response_code(500);
            die('تعذّر إيجاد أداة mysqldump. تأكد من تثبيت MySQL.');
        }

        $cmd = sprintf(
            '%s --host=%s --port=%d --user=%s %s --default-character-set=utf8mb4 --no-tablespaces %s 2>/dev/null',
            escapeshellarg($dump),
            escapeshellarg($cfg['host']),
            (int)$cfg['port'],
            escapeshellarg($cfg['user']),
            $cfg['pass'] !== '' ? '--password=' . escapeshellarg($cfg['pass']) : '',
            escapeshellarg($cfg['name'])
        );

        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        passthru($cmd);
    }

    private function findMysqldump(): ?string
    {
        foreach (['/opt/homebrew/bin/mysqldump', '/usr/local/bin/mysqldump', '/usr/bin/mysqldump', 'mysqldump'] as $p) {
            if ($p === 'mysqldump' || is_executable($p)) {
                return $p;
            }
        }
        return null;
    }
}
