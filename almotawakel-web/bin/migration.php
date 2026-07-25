<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * بوابة المايجريشن الوحيدة المسموح بها في النشر.
 *
 *   php bin/migration.php status [--json]
 *   php bin/migration.php pending --lines
 *   php bin/migration.php apply-one --version=N --confirm-database=DB \
 *       --backup-sha256=HEX --operator=NAME
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Database;
use App\Core\MigrationRunner;

/** @return array{mode:string,options:array<string,string|bool>} */
function migrationCliParse(array $arguments): array
{
    array_shift($arguments);
    $mode = (string) array_shift($arguments);
    $options = [];
    foreach ($arguments as $argument) {
        if (!str_starts_with($argument, '--')) {
            throw new RuntimeException("معامل غير معروف: $argument");
        }
        $option = substr($argument, 2);
        if (str_contains($option, '=')) {
            [$name, $value] = explode('=', $option, 2);
            $options[$name] = $value;
        } else {
            $options[$option] = true;
        }
    }
    return ['mode' => $mode, 'options' => $options];
}

function migrationCliUsage(): void
{
    echo <<<'TXT'
الاستخدام:
  php bin/migration.php status [--json]
  php bin/migration.php pending --lines
  php bin/migration.php validate-files
  php bin/migration.php apply-one --version=N --confirm-database=DB
      --backup-sha256=HEX --operator=NAME
TXT;
    echo PHP_EOL;
}

try {
    $parsed = migrationCliParse($argv);
    $mode = $parsed['mode'];
    $options = $parsed['options'];
    if ($mode === '' || isset($options['help'])) {
        migrationCliUsage();
        exit($mode === '' ? 2 : 0);
    }

    $allowed = [
        'status' => ['json'],
        'pending' => ['lines'],
        'validate-files' => [],
        'apply-one' => ['version', 'confirm-database', 'backup-sha256', 'operator'],
    ];
    if (!isset($allowed[$mode])) {
        throw new RuntimeException("وضع غير معروف: $mode");
    }
    foreach (array_keys($options) as $name) {
        if (!in_array($name, $allowed[$mode], true)) {
            throw new RuntimeException("الخيار --$name غير صالح مع $mode.");
        }
    }

    $migrations = MigrationRunner::discover();
    if ($mode === 'validate-files') {
        echo 'OK migrations=' . count($migrations)
            . ' versions=' . implode(',', array_keys($migrations)) . PHP_EOL;
        exit(0);
    }

    if (!MigrationRunner::ledgerExists()) {
        throw new RuntimeException('سجل schema_migrations غير موجود؛ لا يمكن التحديث.');
    }
    MigrationRunner::assertLedgerMatches($migrations);
    $applied = MigrationRunner::applied();
    $pending = MigrationRunner::pending($migrations);

    if ($mode === 'status' || $mode === 'pending') {
        if ($mode === 'pending' && isset($options['lines'])) {
            foreach (array_keys($pending) as $version) {
                echo $version . PHP_EOL;
            }
            exit(0);
        }

        $status = [
            'database' => (string) Database::scalar('SELECT DATABASE()'),
            'discovered' => count($migrations),
            'applied' => count($applied),
            'pending' => array_values(array_keys($pending)),
            'ledger_matches' => true,
            'event_ledger' => MigrationRunner::eventLedgerExists(),
        ];
        if (isset($options['json'])) {
            echo json_encode(
                $status,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . PHP_EOL;
        } else {
            echo "database={$status['database']}\n";
            echo "discovered={$status['discovered']} applied={$status['applied']}\n";
            echo 'pending=' . ($status['pending'] ? implode(',', $status['pending']) : 'none') . "\n";
            echo 'ledger=matches events=' . ($status['event_ledger'] ? 'ready' : 'missing') . "\n";
        }
        exit(0);
    }

    $versionText = (string) ($options['version'] ?? '');
    if (!ctype_digit($versionText) || (int) $versionText < 1) {
        throw new RuntimeException('--version=N مطلوب برقم موجب.');
    }
    $database = (string) App::config('db')['name'];
    if ((string) ($options['confirm-database'] ?? '') !== $database) {
        throw new RuntimeException('--confirm-database يجب أن يطابق قاعدة الاتصال حرفيًا.');
    }
    $backupSha = strtolower(trim((string) ($options['backup-sha256'] ?? '')));
    $operator = trim((string) ($options['operator'] ?? ''));

    $result = MigrationRunner::applyOneInProcess(
        (int) $versionText,
        $migrations,
        ['backup_sha256' => $backupSha, 'operator_name' => $operator]
    );
    echo "✓ v{$result['version']} {$result['filename']} ({$result['execution_ms']} ms)\n";
    if ($result['output'] !== '') {
        echo $result['output'] . PHP_EOL;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, '✗ ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
