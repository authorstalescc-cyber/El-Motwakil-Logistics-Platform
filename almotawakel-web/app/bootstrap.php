<?php
/**
 * إقلاع التطبيق: المُحمّل التلقائي + الإعدادات + الدوال المساعدة + الجلسة.
 */

// مُحمّل تلقائي بسيط (PSR-4) لمساحة الأسماء App\
spl_autoload_register(function (string $class) {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

require __DIR__ . '/Core/helpers.php';

use App\Core\App;
use App\Core\Auth;

App::boot();
Auth::start();

// توحيد كل الأرقام الواردة في الطلب لتكون إنجليزية (حتى لو كُتبت بالعربية)
// طبقة أمان خلف تحويل الواجهة — تعمل قبل أن يقرأ أي كنترولر $_POST/$_GET.
ar2en_normalize_request();
