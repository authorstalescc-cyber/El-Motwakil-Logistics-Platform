<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * نسخة احتياطية من قاعدة البيانات من سطر الأوامر.
 *
 * بيستخدم `App\Core\DatabaseBackup` نفسها اللي بيستخدمها زرار النسخ الاحتياطي
 * في النظام — فمصدر واحد، ومفيش كلمة سر بتظهر في سطر أوامر أي عملية (MYSQL_PWD).
 *
 * بيشتغل على المحلي وعلى سيرفر الإنتاج بنفس الشكل (بيقرا `config/config.php`
 * بتاع البيئة اللي هو فيها).
 *
 * التشغيل:  php bin/backup_db.php [اسم-النسخة]
 * مثال قبل أي رفع:  php bin/backup_db.php pre-deploy
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\DatabaseBackup;

$label = $argv[1] ?? 'manual';

try {
    $res = DatabaseBackup::create($label);
} catch (\Throwable $e) {
    fwrite(STDERR, "✗ فشلت النسخة الاحتياطية: " . $e->getMessage() . "\n");
    exit(1);
}

$rel = str_replace(App::basePath('') . '/', '', $res['path']);
printf("✓ اتعملت نسخة احتياطية\n  الملف: %s\n  الحجم: %s ك.ب\n  البصمة: %s\n",
    $rel, number_format($res['bytes'] / 1024, 1), substr($res['sha256'], 0, 16));
