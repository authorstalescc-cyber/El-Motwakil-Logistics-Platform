<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** فحوص بنيوية غير مدمرة لترحيل الأدوار وحماية الدخول. */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Access;
use App\Core\Auth;
use App\Core\Database;

$fail = 0;
function v40check(bool $ok, string $label): void {
    global $fail;
    echo ($ok ? '✓ ' : '✗ ') . $label . PHP_EOL;
    if (!$ok) { $fail++; }
}

$cols = (int) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema=DATABASE() AND table_name='users'
       AND column_name IN ('role','failed_tries','locked_until','last_failed_at','last_login_at')"
);
v40check($cols === 5, 'أعمدة حماية users موجودة');
v40check((bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='auth_login_attempts'"
), 'جدول محاولات الدخول موجود');
v40check((int) Database::scalar("SELECT COUNT(*) FROM users WHERE role='owner'") >= 1, 'يوجد مالك واحد على الأقل');
v40check((int) Database::scalar(
    "SELECT COUNT(*) FROM users WHERE role NOT IN ('owner','manager','accountant','operator','read_only')"
) === 0, 'كل الأدوار ضمن القائمة المسموحة');

v40check(Access::allows('owner', 'BackupController@download', 'POST'), 'المالك يستطيع النسخ الاحتياطي');
v40check(!Access::allows('manager', 'BackupController@download', 'POST'), 'المدير لا يستطيع النسخ الاحتياطي');
v40check(Access::allows('manager', 'SettingsController@saveGeneral', 'POST'), 'المدير يعدل الإعدادات غير السرية');
v40check(!Access::allows('accountant', 'SettingsController@index', 'GET'), 'المحاسب لا يفتح الإعدادات');
v40check(Access::allows('accountant', 'PaymentController@pay', 'POST'), 'المحاسب يسجل تحصيلًا');
v40check(!Access::allows('operator', 'PaymentController@pay', 'POST'), 'التشغيل لا يسجل تحصيلًا');
v40check(Access::allows('operator', 'TrackController@store', 'POST'), 'التشغيل يدير التتبع');
v40check(!Access::allows('read_only', 'TrackController@store', 'POST'), 'القراءة فقط لا تكتب');
v40check(Access::allows('read_only', 'ReportsController@index', 'GET'), 'القراءة فقط تستطيع القراءة');
v40check(Auth::MIN_PASSWORD_LENGTH === 8, 'الحد الأدنى لكلمات المرور الجديدة 8');

exit($fail ? 1 : 0);
