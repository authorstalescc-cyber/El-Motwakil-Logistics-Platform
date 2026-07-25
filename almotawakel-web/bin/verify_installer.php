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
$expectedVersions = array_merge(range(2, 41), range(44, 48));
$check(array_keys($migrations) === $expectedVersions, 'اكتشاف v2…v41 ثم v44…v48 بترتيب رقمي، بما فيه اسم v30 ذي اللاحقة');
$check(($migrations[30]['filename'] ?? '') === 'migrate_v30_container_types.php', 'تحليل migrate_v30_container_types.php كإصدار 30');
$appliedBeforeTests = MigrationRunner::applied();
$ledgerV2 = (string) ($appliedBeforeTests[2]['checksum'] ?? '');
$currentV2 = (string) $migrations[2]['checksum'];
$beforeGuardV2 = MigrationRunner::checksumBeforeCliGuard($migrations[2]['path']);
$check(
    $ledgerV2 !== ''
    && (hash_equals($ledgerV2, $currentV2) || hash_equals($ledgerV2, $beforeGuardV2)),
    'v2 إما baseline مُسوّى أو بصمته القديمة مثبتة بحذف حارس CLI فقط'
);
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
    $failureVersion = 999999;
    $failurePath = $tmp . "/migrate_v{$failureVersion}_failure_probe.php";
    file_put_contents($failurePath, "<?php\nfwrite(STDERR, 'synthetic migration failure');\nexit(7);\n");
    $migrations[$failureVersion] = [
        'version' => $failureVersion,
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
    $check(!Database::scalar(
        'SELECT COUNT(*) FROM schema_migrations WHERE version=?',
        [$failureVersion]
    ), 'لا يُسجّل الترحيل الفاشل في ledger');
    $check(
        (int) Database::scalar('SELECT COUNT(*) FROM schema_migrations') === count($appliedBeforeTests),
        'آخر إصدار ناجح محفوظ دون تغيير'
    );

    $structuredVersion = 999998;
    $structuredPath = $tmp . "/migrate_v{$structuredVersion}_contract_probe.php";
    file_put_contents(
        $structuredPath,
        "<?php\nreturn [\n"
        . "  'up' => static function (): void {},\n"
        . "  'verify' => static fn(): bool => true,\n"
        . "];\n"
    );
    $structuredMigrations = MigrationRunner::discover();
    $structuredMigrations[$structuredVersion] = [
        'version' => $structuredVersion,
        'filename' => basename($structuredPath),
        'path' => $structuredPath,
        'checksum' => hash_file('sha256', $structuredPath),
    ];
    ksort($structuredMigrations, SORT_NUMERIC);
    try {
        $result = MigrationRunner::applyOneInProcess(
            $structuredVersion,
            $structuredMigrations,
            ['backup_sha256' => str_repeat('a', 64), 'operator_name' => 'verify_installer']
        );
        $check($result['version'] === $structuredVersion, 'تشغيل structured migration داخل عملية واحدة');
        $check(
            (int) Database::scalar(
                'SELECT COUNT(*) FROM schema_migrations WHERE version=?',
                [$structuredVersion]
            ) === 1,
            'تسجيل structured migration بعد نجاح verify فقط'
        );
        $check(
            (int) Database::scalar(
                'SELECT COUNT(*) FROM schema_migration_events WHERE version=? AND action=?',
                [$structuredVersion, 'applied']
            ) === 1,
            'تسجيل حدث append-only للترحيل الناجح'
        );
    } finally {
        Database::exec('DELETE FROM schema_migration_events WHERE version=?', [$structuredVersion]);
        Database::exec('DELETE FROM schema_migrations WHERE version=?', [$structuredVersion]);
    }
} finally {
    foreach (glob($tmp . '/*') ?: [] as $file) { unlink($file); }
    rmdir($tmp);
}

$installSource = (string) file_get_contents(App::basePath('bin/install.php'));
$check(!str_contains($installSource, 'TRUNCATE TABLE'), 'install/update لا يحتويان مسار TRUNCATE');
$check(!str_contains($installSource, "is_file(\$jsonPath)"), 'وجود old_data.json لا يفعّل الاستيراد تلقائيًا');

$deploySource = (string) file_get_contents(App::basePath('bin/deploy.sh'));
$check(
    !str_contains($deploySource, 'ssh "$HOST" "cd \'$REMOTE\' && php $m"'),
    'deploy لا يشغّل ملف migration خامًا'
);
$check(
    str_contains($deploySource, 'php bin/migration.php apply-one'),
    'deploy يمر عبر بوابة apply-one المتوافقة مع Hostinger'
);
$schemaSource = (string) file_get_contents(App::basePath('database/schema.sql'));
$check(
    str_contains($schemaSource, 'CREATE TABLE IF NOT EXISTS schema_migration_events'),
    'schema الجديدة تحتوي سجل أحداث append-only'
);

if ($failures) {
    echo 'فشل ' . count($failures) . " اختبار.\n";
    exit(1);
}
echo "اكتملت اختبارات المثبّت بنجاح.\n";
