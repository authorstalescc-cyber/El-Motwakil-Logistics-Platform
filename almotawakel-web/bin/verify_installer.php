<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** اختبارات بنيوية للمثبّت؛ شغّله فقط على ALMOTAWAKEL_TEST_DB. */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Database;
use App\Core\MigrationRunner;

$database = (string) App::config('db')['name'];
if (!str_starts_with($database, 'almotawakel_test_')) {
    fwrite(STDERR, "يُرفض تشغيل الاختبار على قاعدة غير معزولة.\n");
    exit(2);
}

$failures = [];
$check = function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '✓ ' : '✗ ') . $message . PHP_EOL;
    if (!$condition) { $failures[] = $message; }
};

$migrations = MigrationRunner::discover();
$expectedVersions = array_merge(range(2, 41), [44, 45]);
$check(array_keys($migrations) === $expectedVersions, 'اكتشاف v2…v41 ثم v44 وv45 بترتيب رقمي، بما فيه اسم v30 ذي اللاحقة');
$check(($migrations[30]['filename'] ?? '') === 'migrate_v30_container_types.php', 'تحليل migrate_v30_container_types.php كإصدار 30');
$tampered = $migrations;
$tampered[40]['checksum'] = str_repeat('0', 64);
try {
    MigrationRunner::assertLedgerMatches($tampered);
    $check(false, 'رفض تغيير checksum لترحيل مطبّق');
} catch (RuntimeException $e) {
    $check(str_contains($e->getMessage(), 'تغير بعد تطبيقه'), 'رفض تغيير checksum لترحيل مطبّق');
}

$tmp = sys_get_temp_dir() . '/almotawakel-installer-' . bin2hex(random_bytes(5));
mkdir($tmp, 0700, true);
try {
    file_put_contents($tmp . '/migrate_v5.php', "<?php\n");
    file_put_contents($tmp . '/migrate_v5_duplicate.php', "<?php\n");
    try {
        MigrationRunner::discover($tmp);
        $check(false, 'رفض نسختين من رقم الترحيل نفسه');
    } catch (RuntimeException $e) {
        $check(str_contains($e->getMessage(), 'ملفان'), 'رفض نسختين من رقم الترحيل نفسه');
    }

    unlink($tmp . '/migrate_v5.php');
    unlink($tmp . '/migrate_v5_duplicate.php');
    file_put_contents($tmp . '/migrate_vbad.php', "<?php\n");
    try {
        MigrationRunner::discover($tmp);
        $check(false, 'رفض اسم ترحيل غير مطابق بدل اعتباره v0');
    } catch (RuntimeException $e) {
        $check(str_contains($e->getMessage(), 'اسم ترحيل غير صالح'), 'رفض اسم ترحيل غير مطابق بدل اعتباره v0');
    }

    unlink($tmp . '/migrate_vbad.php');
    $failurePath = $tmp . '/migrate_v46_failure_probe.php';
    file_put_contents($failurePath, "<?php\nfwrite(STDERR, 'synthetic migration failure');\nexit(7);\n");
    $migrations[46] = [
        'version' => 46,
        'filename' => basename($failurePath),
        'path' => $failurePath,
        'checksum' => hash_file('sha256', $failurePath),
    ];
    try {
        MigrationRunner::runPending($migrations);
        $check(false, 'fail-fast يعيد خطأ عند فشل ترحيل');
    } catch (RuntimeException $e) {
        $check(str_contains($e->getMessage(), 'synthetic migration failure'), 'fail-fast يعيد خطأ الترحيل بوضوح');
    }
    $check(!Database::scalar('SELECT COUNT(*) FROM schema_migrations WHERE version=46'), 'لا يُسجّل الترحيل الفاشل في ledger');
    $check((int) Database::scalar('SELECT COUNT(*) FROM schema_migrations') === 42, 'آخر إصدار ناجح محفوظ دون تغيير');
} finally {
    foreach (glob($tmp . '/*') ?: [] as $file) { unlink($file); }
    rmdir($tmp);
}

$installSource = (string) file_get_contents(App::basePath('bin/install.php'));
$check(!str_contains($installSource, 'TRUNCATE TABLE'), 'install/update لا يحتويان مسار TRUNCATE');
$check(!str_contains($installSource, "is_file(\$jsonPath)"), 'وجود old_data.json لا يفعّل الاستيراد تلقائيًا');

if ($failures) {
    echo 'فشل ' . count($failures) . " اختبار.\n";
    exit(1);
}
echo "اكتملت اختبارات المثبّت بنجاح.\n";
