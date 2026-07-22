<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\DatabaseBackup;

class BackupController extends Controller
{
    /** تنزيل نسخة احتياطية SQL من قاعدة البيانات */
    public function download(): void
    {
        $this->requireRole('owner');
        $this->requireCsrf();

        $stamp = date('Y-m-d_H-i');
        $filename = "almotawakel-backup-{$stamp}.sql";
        $backup = DatabaseBackup::create('manual-download');
        $tmp = $backup['path'];

        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
    }
}
