<?php
declare(strict_types=1);


// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** تحقق بلا قاعدة من حارس قياس الأداء ومنع تسريب قيم SQL/URL. */
require dirname(__DIR__) . '/app/Core/App.php';
require dirname(__DIR__) . '/app/Core/Performance.php';

use App\Core\App;
use App\Core\Performance;

$log = sys_get_temp_dir() . '/almotawakel-performance-' . bin2hex(random_bytes(6)) . '.log';
$config = new ReflectionProperty(App::class, 'config');
$config->setValue(null, ['db'=>['name'=>'almotawakel_test_perf_foundation']]);

putenv('ALMOTAWAKEL_PERF=1');
putenv('ALMOTAWAKEL_PERF_SLOW_MS=1');
putenv('ALMOTAWAKEL_PERF_LOG=' . $log);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/permits/418?client=عميل-سري&amount=9000';

try {
    Performance::start();
    $result = Performance::span('test.query', static fn(): string => Performance::measure(
        "SELECT * FROM permits WHERE client='عميل-سري' AND amount=9000 AND id=418",
        static function (): string { usleep(2000); return 'ok'; }
    ));
    Performance::finish();
    if ($result !== 'ok') { throw new RuntimeException('القياس غيّر قيمة إرجاع العملية.'); }
    $raw = is_file($log) ? (string) file_get_contents($log) : '';
    $row = json_decode(trim($raw), true);
    if (!is_array($row)) { throw new RuntimeException('لم يُكتب سجل أداء JSON صالح.'); }
    if (($row['query_count'] ?? null) !== 1) { throw new RuntimeException('عداد الاستعلامات غير صحيح.'); }
    if (($row['route'] ?? '') !== '/permits/{id}') { throw new RuntimeException('المسار لم يخف المعرف أو query string.'); }
    if (str_contains($raw, 'عميل-سري') || isset($row['sql']) || isset($row['params'])) {
        throw new RuntimeException('سجل الأداء سرّب SQL أو parameters أو قيمة نصية.');
    }
    $slow = $row['slow_queries'][0] ?? null;
    if (!is_array($slow) || ($slow['kind'] ?? '') !== 'SELECT' || !preg_match('/^[a-f0-9]{12}$/', (string)($slow['id'] ?? ''))) {
        throw new RuntimeException('بصمة الاستعلام البطيء غير آمنة أو ناقصة.');
    }
    $span = $row['spans'][0] ?? null;
    if (!is_array($span) || ($span['name'] ?? '') !== 'test.query' || ($span['queries'] ?? 0) !== 1) {
        throw new RuntimeException('تقسيم كلفة الطلب إلى أجزاء غير صحيح.');
    }
    echo "✓ قياس الأداء اختياري ومحروس بقاعدة اختبار\n";
    echo "✓ سجل الطلب يخفي query string والمعرفات وقيم SQL\n";
    echo "✓ الاستعلام البطيء يُسجل كنوع وبصمة فقط\n";
    echo "✓ أجزاء الطلب تسجل الزمن والعدد بأسماء ثابتة فقط\n";
} finally {
    if (is_file($log)) { unlink($log); }
    putenv('ALMOTAWAKEL_PERF');
    putenv('ALMOTAWAKEL_PERF_SLOW_MS');
    putenv('ALMOTAWAKEL_PERF_LOG');
}
