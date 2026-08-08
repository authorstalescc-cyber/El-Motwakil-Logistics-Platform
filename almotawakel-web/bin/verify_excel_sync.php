<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * فحص آمن لمعاينة مزامنة Excel — قراءة فقط ولا يحتاج قاعدة بيانات.
 *
 * الاستخدام:
 *   php bin/verify_excel_sync.php
 *   ALMOTAWAKEL_EXCEL_SYNC_PATH=/path/to/file.xlsx php bin/verify_excel_sync.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Calc;
use App\Core\Access;
use App\Models\ExcelBillImport;
use App\Models\ExcelBillSync;
use App\Services\XlsxReader;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? "✓ " : "✗ ") . $message . PHP_EOL;
    if (!$ok) { $failures[] = $message; }
};

try {
    $path = ExcelBillSync::sourcePath();
    $reader = new XlsxReader($path);
    $headerRows = $reader->rows(ExcelBillSync::SHEET, 1, 'AI');
    $headers = array_map(static fn($v) => trim((string) $v), $headerRows[1] ?? []);

    $requiredHeaders = [
        'A'=>'صاحب البيان', 'B'=>'تاريخ', 'C'=>'رقم البوليصة', 'D'=>'التصنيف',
        'E'=>'اسم الشركة', 'F'=>'الجهة', 'G'=>'رقم الحاوية', 'H'=>'رقم السيارة',
        'K'=>'نولون', 'M'=>'تحويلة', 'N'=>'خصم', 'Q'=>'دفعه',
        'S'=>'دور سواق', 'T'=>'دور تباع', 'AF'=>'جاز', 'AG'=>'اجمال المصروف',
        'AH'=>'الباقى من العهدة', 'AI'=>'صافى السيارة',
    ];
    foreach ($requiredHeaders as $column=>$label) {
        $check(($headers[$column] ?? '') === $label, "العمود $column = $label");
    }

    $synthetic = ExcelBillSync::mapRow(7, [
        'A'=>'  صاحب   بيان  ', 'B'=>46023.0, 'C'=>1234.0, 'D'=>'1*40', 'E'=>'شركة',
        'F'=>'الميناء', 'G'=>'CONT-1', 'H'=>'س ع م 8737', 'I'=>'السائق', 'J'=>'صاحب السيارة',
        'K'=>10000.0, 'M'=>300.0, 'N'=>999.0, 'Q'=>2500.0,
        'S'=>100.0, 'T'=>200.0, 'AC'=>10.0, 'AD'=>20.0, 'AE'=>30.0, 'AF'=>400.0,
        'AG'=>760.0, 'AH'=>240.0, 'AI'=>9240.0,
    ]);
    $check($synthetic['bill_number'] === '1234', 'رقم البوليصة الرقمي لا يكتسب .0');
    $check($synthetic['declaration_owner'] === 'صاحب بيان', 'توحيد المسافات في صاحب البيان');
    $check($synthetic['expenses']['other_expense'] === 60.0, 'مصاريف أخرى = AC + AD + AE');
    $check($synthetic['expenses']['gas'] === 400.0, 'عمود AF يُقرأ كسولار/جاز');
    $check($synthetic['discount'] === 999.0, 'الخصم N ظاهر للمقارنة المرجعية');
    $check($synthetic['company'] === 'شركة' && $synthetic['container_no'] === 'CONT-1',
        'بيانات البوليصة التفصيلية تُقرأ للمقارنة');
    $check($synthetic['excel_car_net'] === 9240.0, 'صافي السيارة AI ظاهر للمقارنة المرجعية');

    $expenseKeys = array_keys($synthetic['expenses']);
    sort($expenseKeys);
    $calcKeys = Calc::EXPENSE_COLS;
    sort($calcKeys);
    $check($expenseKeys === $calcKeys, 'أعمدة مصروفات Excel تطابق Calc::EXPENSE_COLS');

    $source = file_get_contents(dirname(__DIR__) . '/app/Models/ExcelBillSync.php');
    $check(
        $source !== false && !preg_match('/Database\\s*::\\s*(?:exec|lastId)\\s*\\(/', $source),
        'محرك المعاينة لا يحتوي أي استدعاء كتابة لقاعدة البيانات'
    );
    $check(Access::allows('owner', 'ExcelSyncController@index', 'GET'), 'المالك يستطيع فتح المعاينة');
    $check(Access::allows('manager', 'ExcelSyncController@index', 'GET'), 'المدير يستطيع فتح المعاينة');
    $check(!Access::allows('accountant', 'ExcelSyncController@index', 'GET'), 'المعاينة محجوبة عن المحاسب وباقي الأدوار');
    $check(Access::allows('owner', 'ExcelSyncController@apply', 'POST'), 'المالك يستطيع اعتماد المزامنة');
    $check(Access::allows('manager', 'ExcelSyncController@review', 'GET'), 'المدير يستطيع فتح مقارنة البوليصة');
    $check(Access::allows('manager', 'ExcelSyncController@confirmSelection', 'POST'), 'المدير يستطيع إنشاء شاشة التأكيد');
    $check(Access::allows('manager', 'ExcelSyncController@upload', 'POST'), 'المدير يستطيع تحديث نسخة المراجعة');
    $check(!Access::allows('accountant', 'ExcelSyncController@apply', 'POST'), 'تنفيذ المزامنة محجوب عن المحاسب');

    $routes = file_get_contents(dirname(__DIR__) . '/public/index.php');
    $syncPos = $routes === false ? false : strpos($routes, "/bawalis/excel-sync");
    $uploadPos = $routes === false ? false : strpos($routes, "/bawalis/excel-sync/upload");
    $reviewPos = $routes === false ? false : strpos($routes, "/bawalis/excel-sync/review");
    $confirmPos = $routes === false ? false : strpos($routes, "/bawalis/excel-sync/confirm");
    $applyPos = $routes === false ? false : strpos($routes, "/bawalis/excel-sync/apply");
    $greedyPos = $routes === false ? false : strpos($routes, "/bawalis/{id}");
    $check($syncPos !== false && $greedyPos !== false && $syncPos < $greedyPos, 'مسار المعاينة يسبق مسار البوليصة الجشع');
    $check($uploadPos !== false && $applyPos !== false && $applyPos < $greedyPos, 'مسارا التحديث والاعتماد يسبقان مسار البوليصة الجشع');
    $check($reviewPos !== false && $confirmPos !== false && $confirmPos < $greedyPos,
        'مسارا المقارنة والتأكيد المنفصلان يسبقان مسار البوليصة الجشع');

    $writer = file_get_contents(dirname(__DIR__) . '/app/Models/ExcelBillImport.php');
    $check($writer !== false && str_contains($writer, 'beginTransaction()'), 'الاعتماد داخل transaction واحدة');
    $check($writer !== false && str_contains($writer, 'Closing::guard'), 'الاعتماد يحترم قفل أيام البوالص');
    $check($writer !== false && str_contains($writer, 'Audit::log'), 'الرفع والاعتماد يسجلان في سجل التدقيق');
    $check($writer !== false && str_contains($writer, 'hash_equals($currentToken, $submittedToken)'),
        'التنفيذ يرفض ملفًا أو بوليصة تغيّرت بعد شاشة التأكيد');
    $check($writer !== false && str_contains($writer, 'applySelection('),
        'المنفّذ يعمل على اختيار بوليصة واحدة لا اعتماد جماعي');
    $check($writer !== false && !str_contains($writer, "'discount'"), 'منفّذ المزامنة لا يكتب الخصم');
    $check($writer !== false && !str_contains($writer, "'custody'"), 'منفّذ المزامنة لا يكتب العهدة');
    echo PHP_EOL . 'المصدر: ' . $path . PHP_EOL;
} catch (\Throwable $e) {
    $failures[] = $e->getMessage();
    echo '✗ ' . $e->getMessage() . PHP_EOL;
}

if ($failures) {
    echo PHP_EOL . 'فشل الفحص في ' . count($failures) . ' نقطة.' . PHP_EOL;
    exit(1);
}
echo PHP_EOL . 'نجح فحص معاينة مزامنة Excel بالكامل.' . PHP_EOL;
