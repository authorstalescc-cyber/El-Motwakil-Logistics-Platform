<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * إدارة دورة قاعدة البيانات بأوضاع صريحة وآمنة.
 *
 *   php bin/install.php install
 *   php bin/install.php update
 *   php bin/install.php update --adopt-existing --adopt-through=40
 *   php bin/install.php import-legacy --fresh --import-legacy --confirm-database=NAME
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Database;
use App\Core\DatabaseBackup;
use App\Core\LegacyImporter;
use App\Core\MigrationRunner;

function cliSay(string $message, string $color = ''): void
{
    $colors = ['ok' => "\033[32m", 'err' => "\033[31m", 'hi' => "\033[36m", 'warn' => "\033[33m"];
    echo ($colors[$color] ?? '') . $message . ($color ? "\033[0m" : '') . PHP_EOL;
}

function cliUsage(): void
{
    echo <<<'TXT'
الاستخدام:
  php bin/install.php install [--fresh --confirm-database=NAME] [--dry-run|--verify-only]
  php bin/install.php update [--dry-run|--verify-only]
  php bin/install.php update --adopt-existing --adopt-through=N [--dry-run|--verify-only]
  php bin/install.php import-legacy --fresh --import-legacy --confirm-database=NAME [--dry-run|--verify-only]

الأمان:
  --fresh لا يعمل على قاعدة موجودة إلا مع --confirm-database المطابق حرفيًا.
  وجود old_data.json لا يسبب أي استيراد تلقائي.
  --dry-run و--verify-only لا يكتبان في القاعدة ولا ينشئان نسخة احتياطية.
TXT;
}

/** @return array{mode:string,flags:array<string,bool>,values:array<string,string>} */
function cliParse(array $arguments): array
{
    array_shift($arguments);
    $mode = '';
    $flags = [];
    $values = [];
    foreach ($arguments as $argument) {
        if (!str_starts_with($argument, '--')) {
            if ($mode !== '') { throw new RuntimeException("معامل غير معروف: $argument"); }
            $mode = $argument;
            continue;
        }
        $option = substr($argument, 2);
        if (str_contains($option, '=')) {
            [$name, $value] = explode('=', $option, 2);
            $values[$name] = $value;
        } else {
            $flags[$option] = true;
        }
    }
    return ['mode' => $mode, 'flags' => $flags, 'values' => $values];
}

function cliHas(array $options, string $name): bool
{
    return isset($options['flags'][$name]);
}

function cliValue(array $options, string $name): ?string
{
    return $options['values'][$name] ?? null;
}

function assertKnownOptions(array $options): void
{
    $allowedFlags = ['fresh', 'import-legacy', 'adopt-existing', 'dry-run', 'verify-only', 'help'];
    $allowedValues = ['confirm-database', 'adopt-through'];
    foreach (array_keys($options['flags']) as $name) {
        if (!in_array($name, $allowedFlags, true)) { throw new RuntimeException("خيار غير معروف: --$name"); }
    }
    foreach (array_keys($options['values']) as $name) {
        if (!in_array($name, $allowedValues, true)) { throw new RuntimeException("خيار غير معروف: --$name"); }
    }
}

function assertPrerequisites(bool $backupRequired): void
{
    foreach (['pdo_mysql', 'json', 'mbstring'] as $extension) {
        if (!extension_loaded($extension)) { throw new RuntimeException("امتداد PHP المطلوب غير موجود: $extension"); }
    }
    foreach (['database/schema.sql', 'config/config.php'] as $relative) {
        if (!is_readable(App::basePath($relative))) { throw new RuntimeException("ملف مطلوب غير مقروء: $relative"); }
    }
    if ($backupRequired && !DatabaseBackup::findBinary('mysqldump')) {
        throw new RuntimeException('mysqldump مطلوب قبل أي عملية تغيّر قاعدة موجودة.');
    }
}

function safeDatabaseName(): string
{
    $name = (string) App::config('db')['name'];
    if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $name)) {
        throw new RuntimeException('اسم قاعدة البيانات في الإعدادات غير آمن.');
    }
    return $name;
}

function databaseExists(PDO $server, string $name): bool
{
    $statement = $server->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name=?');
    $statement->execute([$name]);
    return (bool) $statement->fetchColumn();
}

function requireExactConfirmation(array $options, string $database): void
{
    if (cliValue($options, 'confirm-database') !== $database) {
        throw new RuntimeException("العملية مدمّرة؛ أعدها مع --confirm-database=$database");
    }
}

function loadSchema(): int
{
    $schema = (string) file_get_contents(App::basePath('database/schema.sql'));
    $lines = array_filter(explode("\n", $schema), fn(string $line): bool => !preg_match('/^\s*--/', $line));
    $statements = array_filter(array_map('trim', explode(';', implode("\n", $lines))));
    foreach ($statements as $statement) { Database::conn()->exec($statement); }
    return count($statements);
}

function seedOwner(): void
{
    $default = App::config('default_user');
    $username = trim((string) ($default['username'] ?? ''));
    $password = (string) ($default['password'] ?? '');
    if ($username === '' || strlen($password) < 8 || $password === 'CHANGE_ME') {
        throw new RuntimeException('اضبط default_user باسم صالح وكلمة مرور أولية قوية (8 أحرف على الأقل) قبل التثبيت.');
    }
    if (!Database::scalar('SELECT COUNT(*) FROM users WHERE username=?', [$username])) {
        Database::exec(
            'INSERT INTO users(username,password_hash,display_name,role) VALUES(?,?,?,?)',
            [$username, password_hash($password, PASSWORD_DEFAULT), 'المالك', 'owner']
        );
    }
}

function assertAdoptable(int $through): void
{
    $requirements = [
        2 => ['tables' => ['users', 'bawalis', 'takhlis', 'generators'], 'columns' => []],
        35 => ['tables' => ['rep_users', 'permit_assignments', 'rep_custody_deposits', 'rep_custody_settlements', 'rep_submissions'], 'columns' => []],
        36 => ['tables' => [], 'columns' => [['rep_submissions', 'client_token'], ['rep_submissions', 'review_started_at']]],
        37 => ['tables' => [], 'columns' => [['rep_submissions', 'actual_unit'], ['rep_submissions', 'edited_at']]],
        39 => ['tables' => ['rep_misc_expenses'], 'columns' => [['rep_submissions', 'misc_id']]],
        40 => ['tables' => ['auth_login_attempts'], 'columns' => [['users', 'role'], ['users', 'locked_until'], ['users', 'last_login_at']]],
    ];
    foreach ($requirements as $version => $checks) {
        if ($version > $through) { continue; }
        foreach ($checks['tables'] as $table) {
            if (!Database::scalar(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', [$table]
            )) { throw new RuntimeException("لا يمكن اعتماد v$through: الجدول $table مفقود."); }
        }
        foreach ($checks['columns'] as [$table, $column]) {
            if (!Database::scalar(
                'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
                [$table, $column]
            )) { throw new RuntimeException("لا يمكن اعتماد v$through: العمود $table.$column مفقود."); }
        }
    }
}

function printMigrationPreview(array $migrations, array $pending): void
{
    $all = array_keys($migrations);
    $waiting = array_keys($pending);
    cliSay('• الترحيلات المكتشفة: ' . count($all) . ($all ? ' (v' . min($all) . '…v' . max($all) . ')' : ''), 'hi');
    cliSay('• المعلّق: ' . ($waiting ? implode(', ', array_map(fn(int $v): string => "v$v", $waiting)) : 'لا شيء'), $waiting ? 'warn' : 'ok');
}

try {
    $options = cliParse($argv);
    assertKnownOptions($options);
    if (cliHas($options, 'help') || $options['mode'] === '') { cliUsage(); exit($options['mode'] === '' ? 2 : 0); }
    $mode = $options['mode'];
    if (!in_array($mode, ['install', 'update', 'import-legacy'], true)) {
        throw new RuntimeException("وضع غير معروف: $mode");
    }
    if (cliHas($options, 'dry-run') && cliHas($options, 'verify-only')) {
        throw new RuntimeException('اختر --dry-run أو --verify-only، وليس الاثنين معًا.');
    }
    $readOnly = cliHas($options, 'dry-run') || cliHas($options, 'verify-only');
    $database = safeDatabaseName();
    $server = Database::serverConn();
    $exists = databaseExists($server, $database);
    $migrations = MigrationRunner::discover();

    cliSay('═══════════════════════════════════════════════', 'hi');
    cliSay("  إدارة قاعدة المتوكل — $mode" . ($readOnly ? ' [معاينة بلا كتابة]' : ''), 'hi');
    cliSay('═══════════════════════════════════════════════', 'hi');

    if ($mode === 'install') {
        if (cliHas($options, 'import-legacy') || cliHas($options, 'adopt-existing')) {
            throw new RuntimeException('الاستيراد والاعتماد لهما وضعان مستقلان عن install.');
        }
        $fresh = cliHas($options, 'fresh');
        if ($exists && !$fresh) { throw new RuntimeException('القاعدة موجودة؛ استخدم update، أو --fresh مع التأكيد لإعادة إنشائها.'); }
        if ($fresh) { requireExactConfirmation($options, $database); }
        assertPrerequisites($exists);
        printMigrationPreview($migrations, $migrations);
        if ($readOnly) { cliSay('✓ متطلبات التثبيت سليمة، ولم تُنفذ أي كتابة.', 'ok'); exit(0); }
        if ($exists) {
            $backup = DatabaseBackup::create('pre-fresh-install');
            cliSay("✓ نسخة احتياطية: {$backup['path']} ({$backup['sha256']})", 'ok');
            $server->exec("DROP DATABASE `$database`");
        }
        $server->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        cliSay('✓ أُنشئت قاعدة البيانات.', 'ok');
        cliSay('✓ نُفذ المخطط الأساسي: ' . loadSchema() . ' أمر.', 'ok');
        seedOwner();
        $result = MigrationRunner::runPending($migrations);
        cliSay("✓ طُبّق {$result['count']} ترحيل بترتيب صارم.", 'ok');
    } elseif ($mode === 'update') {
        if (!$exists) { throw new RuntimeException('القاعدة غير موجودة؛ استخدم install أولًا.'); }
        if (cliHas($options, 'fresh') || cliHas($options, 'import-legacy')) {
            throw new RuntimeException('update لا يقبل --fresh أو --import-legacy.');
        }
        $adopt = cliHas($options, 'adopt-existing');
        $throughText = cliValue($options, 'adopt-through');
        if ($adopt && ($throughText === null || !ctype_digit($throughText))) {
            throw new RuntimeException('--adopt-existing يتطلب --adopt-through=N صريحًا.');
        }
        if (!$adopt && $throughText !== null) { throw new RuntimeException('--adopt-through لا يُستخدم دون --adopt-existing.'); }
        assertPrerequisites(true);
        $ledgerExists = MigrationRunner::ledgerExists();
        if (!$ledgerExists && !$adopt) {
            throw new RuntimeException('قاعدة قديمة بلا سجل ترحيلات؛ افحصها ثم استخدم --adopt-existing --adopt-through=N مرة واحدة.');
        }
        if ($ledgerExists && $adopt) { throw new RuntimeException('سجل الترحيلات موجود بالفعل؛ لا حاجة إلى adopt-existing.'); }
        if ($adopt) {
            $through = (int) $throughText;
            if (!isset($migrations[$through])) { throw new RuntimeException("لا يوجد ملف ترحيل v$through."); }
            assertAdoptable($through);
            $adoptSet = array_filter($migrations, fn(array $migration): bool => $migration['version'] <= $through);
            $pending = array_filter($migrations, fn(array $migration): bool => $migration['version'] > $through);
            printMigrationPreview($migrations, $pending);
            cliSay('• سيُعتمد دون تشغيل: ' . count($adoptSet) . " ترحيل حتى v$through.", 'warn');
            if ($readOnly) { cliSay('✓ القاعدة قابلة للاعتماد، ولم تُنفذ أي كتابة.', 'ok'); exit(0); }
            $backup = DatabaseBackup::create('pre-adopt-update');
            cliSay("✓ نسخة احتياطية: {$backup['path']} ({$backup['sha256']})", 'ok');
            cliSay('✓ اعتُمد في السجل: ' . MigrationRunner::adoptExisting($adoptSet) . ' ترحيل.', 'ok');
        } else {
            MigrationRunner::assertLedgerMatches($migrations);
            $pending = MigrationRunner::pending($migrations);
            printMigrationPreview($migrations, $pending);
            if ($readOnly) { cliSay('✓ سجل الترحيلات متسق، ولم تُنفذ أي كتابة.', 'ok'); exit(0); }
            if ($pending) {
                $backup = DatabaseBackup::create('pre-update');
                cliSay("✓ نسخة احتياطية: {$backup['path']} ({$backup['sha256']})", 'ok');
            }
        }
        $result = MigrationRunner::runPending($migrations);
        cliSay($result['count'] ? "✓ طُبّق {$result['count']} ترحيل." : '✓ القاعدة محدثة؛ لا توجد ترحيلات معلّقة.', 'ok');
    } else {
        if (!$exists) { throw new RuntimeException('القاعدة غير موجودة؛ ثبّت النظام قبل الاستيراد القديم.'); }
        if (!cliHas($options, 'fresh') || !cliHas($options, 'import-legacy')) {
            throw new RuntimeException('الاستيراد القديم يتطلب معًا: --fresh --import-legacy.');
        }
        requireExactConfirmation($options, $database);
        if (cliHas($options, 'adopt-existing') || cliValue($options, 'adopt-through') !== null) {
            throw new RuntimeException('اعتماد الترحيلات لا يتم داخل import-legacy.');
        }
        assertPrerequisites(true);
        if (!MigrationRunner::ledgerExists()) {
            throw new RuntimeException('شغّل update واعتمد سجل الترحيلات قبل استيراد البيانات القديمة.');
        }
        MigrationRunner::assertLedgerMatches($migrations);
        if (MigrationRunner::pending($migrations)) {
            throw new RuntimeException('حدّث القاعدة أولًا؛ لا يُسمح بالاستيراد مع ترحيلات معلّقة.');
        }
        $legacyPath = App::basePath('database/old_data.json');
        $preview = LegacyImporter::inspect($legacyPath);
        cliSay('• محتوى الاستيراد: ' . json_encode($preview, JSON_UNESCAPED_UNICODE), 'hi');
        if ($readOnly) { cliSay('✓ ملف الاستيراد والقاعدة صالحان، ولم تُنفذ أي كتابة.', 'ok'); exit(0); }
        $backup = DatabaseBackup::create('pre-legacy-import');
        cliSay("✓ نسخة احتياطية: {$backup['path']} ({$backup['sha256']})", 'ok');
        $counts = LegacyImporter::import($legacyPath);
        MigrationRunner::runMaintenanceFile(App::basePath('bin/migrate_v31.php'));
        cliSay('✓ اكتمل الاستيراد والتسوية: ' . json_encode($counts, JSON_UNESCAPED_UNICODE), 'ok');
    }

    cliSay('═══════════════════════════════════════════════', 'hi');
    cliSay('  اكتملت العملية بنجاح ✓', 'ok');
    cliSay('═══════════════════════════════════════════════', 'hi');
    exit(0);
} catch (Throwable $exception) {
    cliSay('✗ ' . $exception->getMessage(), 'err');
    cliSay('توقفت العملية عند أول خطأ؛ لم تُطبع رسالة اكتمال.', 'warn');
    exit(1);
}
