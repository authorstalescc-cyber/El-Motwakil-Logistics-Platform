<?php
/**
 * إقلاع التطبيق: المُحمّل التلقائي + الإعدادات + الدوال المساعدة + الجلسة.
 */

// بعض أدوات CLI المحكومة تحمّل bootstrap أولًا لفحص السجل ثم تضمّن ملف
// migration قديمًا يحتوي `require app/bootstrap.php` بنفسه. جعل الإقلاع
// idempotent يمنع إعادة تعريف helpers أو بدء الجلسة مرتين، من غير تعديل ملفات
// الترحيلات التاريخية وبصماتها مرة أخرى.
if (defined('ALMOTAWAKEL_BOOTSTRAPPED')) {
    return;
}
define('ALMOTAWAKEL_BOOTSTRAPPED', true);

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
use App\Core\ErrorHandler;
use App\Core\Performance;
use App\Core\SecurityHeaders;

App::boot();
ErrorHandler::register();
Performance::start();
Auth::start();
SecurityHeaders::send();

// توحيد كل الأرقام الواردة في الطلب لتكون إنجليزية (حتى لو كُتبت بالعربية)
// طبقة أمان خلف تحويل الواجهة — تعمل قبل أن يقرأ أي كنترولر $_POST/$_GET.
ar2en_normalize_request();

// خانات المبالغ بتوري فواصل آلاف («1,000») لمنع خطأ الإدخال؛ نفكّها هنا قبل
// أي كنترولر — لازم **بعد** توحيد الأرقام العربية عشان النمط يشوف [0-9].
// ⚠️ الواجهة بتشيلها عند الحفظ، بس `FormData(form)` بتقرا النص المعروض.
money_normalize_request();

// نموذج البوليصة يرسل خانة لكل حاوية (`container_no[]`)؛ نجمّعها هنا في نصّ
// العمود الواحد قبل أن يقرأها أي كنترولر — فمفيش كنترولر محتاج يعرف بالتغيير.
container_nos_normalize_request();
