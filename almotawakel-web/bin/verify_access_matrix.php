<?php
declare(strict_types=1);


// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * فحص وحدة/بنية لمصفوفة route action × role.
 *
 * لا يحمل bootstrap ولا يفتح اتصال قاعدة بيانات. يختبر السياسة النقية في
 * Access::allows، ويثبت أن المسارات الحساسة ما زالت مسجلة بالـhandler المتوقع
 * وأن Router يطبق Access قبل استدعاء الكنترولر.
 */

require dirname(__DIR__) . '/app/Core/Access.php';
require dirname(__DIR__) . '/app/Core/Router.php';

use App\Core\Access;
use App\Core\Router;

$failures = [];
$checks = 0;

$check = static function (bool $ok, string $message) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures[] = $message;
    }
};

$roles = ['owner', 'manager', 'accountant', 'operator', 'read_only'];
$expectedRoles = [
    'owner'      => 'مالك',
    'manager'    => 'مدير',
    'accountant' => 'محاسب',
    'operator'   => 'تشغيل',
    'read_only'  => 'قراءة فقط',
];
$check(Access::ROLES === $expectedRoles, 'قائمة الأدوار الخمسة تغيرت دون تحديث المصفوفة');
$check(Access::normalizeRole('unexpected-role') === 'read_only', 'الدور غير المعروف يجب أن يهبط إلى read_only');

/**
 * allowed هي القائمة الكاملة للأدوار المسموح لها؛ غياب الدور يعني اختبار منع.
 * المسارات المختارة تغطي عمليات P0 المالية والأمنية، مع عينات تشغيل وقراءة.
 */
$matrix = [
    ['النسخ الاحتياطي',       'POST', '/backup',                              'BackupController@download',             ['owner']],
    ['إضافة مستخدم',           'POST', '/settings/users',                      'SettingsController@addUser',             ['owner']],
    ['تعديل مستخدم',          'POST', '/settings/users/{id}',                 'SettingsController@updateUser',          ['owner']],
    ['حذف مستخدم',            'POST', '/settings/users/{id}/delete',          'SettingsController@deleteUser',          ['owner']],
    ['حفظ مفتاح AI',            'POST', '/settings/ai',                         'SettingsController@saveAi',              ['owner']],
    ['اختبار مفتاح AI',       'POST', '/settings/ai/test',                    'SettingsController@testAi',              ['owner']],
    ['إعدادات عامة',          'POST', '/settings/general',                    'SettingsController@saveGeneral',         ['owner', 'manager']],
    ['فتح الإعدادات',         'GET',  '/settings',                            'SettingsController@index',               ['owner', 'manager']],
    ['تسجيل تحصيل',          'POST', '/payments/pay',                        'PaymentController@pay',                  ['owner', 'manager', 'accountant']],
    ['حذف تحصيل',            'POST', '/payments/{id}/delete',                'PaymentController@delete',               ['owner', 'manager', 'accountant']],
    ['إقفال اليوم',            'POST', '/closing/close',                       'DailyClosingController@close',           ['owner', 'manager', 'accountant']],
    ['إعادة فتح اليوم',      'POST', '/closing/reopen',                      'DailyClosingController@reopen',          ['owner', 'manager', 'accountant']],
    ['شحن عهدة المكتب',      'POST', '/custody/topup',                       'CustodyController@storeTopup',           ['owner', 'manager', 'accountant']],
    ['حذف شحن عهدة',         'POST', '/custody/topup/{id}/delete',           'CustodyController@deleteTopup',          ['owner', 'manager', 'accountant']],
    ['إيداع في كارت المكتب', 'POST', '/office/{id}/deposit',                  'OfficeController@storeDeposit',          ['owner', 'manager', 'accountant']],
    ['حذف إيداع كارت',      'POST', '/office/deposit/{depId}/delete',       'OfficeController@deleteDeposit',         ['owner', 'manager', 'accountant']],
    ['إصدار فاتورة إذن',    'POST', '/permits/{id}/invoice',                'PermitController@invoiceStore',          ['owner', 'manager', 'accountant']],
    ['حذف فاتورة إذن',      'POST', '/permits/invoice/{invId}/delete',      'PermitController@invoiceDelete',         ['owner', 'manager', 'accountant']],
    ['إنشاء مطالبة مالية',  'POST', '/permits/{id}/create-bill',            'PermitController@createBill',            ['owner', 'manager', 'accountant']],
    ['إنشاء إذن تشغيل',    'POST', '/permits',                             'PermitController@store',                 ['owner', 'manager', 'operator']],
    ['إنشاء رابط تتبع',     'POST', '/tracking',                            'TrackController@store',                  ['owner', 'manager', 'operator']],
    ['عكس عملية من السجل', 'POST', '/audit/{id}/undo',                    'AuditController@undo',                   ['owner', 'manager']],
    ['سداد عميل',             'POST', '/directory/agent/{id}/pay',            'DirectoryController@addPayment',         ['owner', 'manager', 'accountant']],
    ['دمج طرفين',             'POST', '/directory/master/{type}/{id}/merge',  'DirectoryController@mergeParty',         ['owner', 'manager']],
    ['قراءة التقارير',          'GET',  '/reports',                             'ReportsController@index',                $roles],
    ['حفظ تاريخ العمل',      'POST', '/work-date',                           'WorkDateController@save',                $roles],
];

$indexSource = file_get_contents(dirname(__DIR__) . '/public/index.php');
$check(is_string($indexSource), 'تعذر قراءة public/index.php');

foreach ($matrix as [$label, $method, $path, $handler, $allowed]) {
    if (is_string($indexSource)) {
        $registration = '/\\$r->' . strtolower($method)
            . '\\s*\\(\\s*' . preg_quote(var_export($path, true), '/')
            . '\\s*,\\s*' . preg_quote(var_export($handler, true), '/') . '\\s*\\)/';
        $check(
            preg_match($registration, $indexSource) === 1,
            "$method $path لم يعد مسجلًا على $handler"
        );
    }

    foreach ($roles as $role) {
        $expected = in_array($role, $allowed, true);
        $actual = Access::allows($role, $handler, $method);
        $check(
            $actual === $expected,
            "$label [$method $path]: الدور $role كان ينبغي أن "
                . ($expected ? 'يُسمح له' : 'يُمنع')
                . ' ولكن Access::allows أعاد '
                . ($actual ? 'allow' : 'deny')
        );
    }
}

$routerSource = file_get_contents(dirname(__DIR__) . '/app/Core/Router.php');
$check(class_exists(Router::class, false), 'تعذر تحميل Router للفحص');
if (is_string($routerSource)) {
    $enforceAt = strpos($routerSource, 'Access::enforce((string) $handler, $httpMethod)');
    $constructAt = strpos($routerSource, '$controller = new $class()');
    $invokeAt = strpos($routerSource, 'call_user_func_array([$controller, $action], $params)');
    $check(
        $enforceAt !== false && $constructAt !== false && $invokeAt !== false
            && $enforceAt < $constructAt && $enforceAt < $invokeAt,
        'Router يجب أن يستدعي Access::enforce قبل إنشاء/استدعاء الكنترولر'
    );
} else {
    $check(false, 'تعذر قراءة app/Core/Router.php');
}

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "✗ $failure" . PHP_EOL);
    }
    fwrite(STDERR, 'فشلت ' . count($failures) . " من $checks فحصًا." . PHP_EOL);
    exit(1);
}

echo "✓ مصفوفة المسارات × الأدوار: $checks فحصًا بلا قاعدة بيانات." . PHP_EOL;
exit(0);
