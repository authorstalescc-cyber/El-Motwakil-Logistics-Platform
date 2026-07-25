<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * أداة لمرة واحدة لتسوية حادثة ledger المثبتة على v2–v48.
 *
 * المعاينة لا تكتب:
 *   php bin/reconcile_migrations.php plan [--json]
 *
 * التطبيق يتطلب قاعدة + بصمة الخطة + بصمة backup + اسم المنفذ:
 *   php bin/reconcile_migrations.php apply --confirm-database=DB \
 *     --confirm-plan-sha256=HEX --backup-sha256=HEX --operator=NAME
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\MigrationReconciler;

try {
    $arguments = array_slice($argv, 1);
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

    if (!in_array($mode, ['plan', 'apply'], true)) {
        throw new RuntimeException('استخدم plan للمعاينة أو apply للتنفيذ.');
    }
    $allowed = $mode === 'plan'
        ? ['json']
        : ['confirm-database', 'confirm-plan-sha256', 'backup-sha256', 'operator'];
    foreach (array_keys($options) as $name) {
        if (!in_array($name, $allowed, true)) {
            throw new RuntimeException("خيار غير صالح مع $mode: --$name");
        }
    }

    if ($mode === 'plan') {
        $plan = MigrationReconciler::buildPlan();
        if (isset($options['json'])) {
            echo json_encode(
                $plan,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . PHP_EOL;
        } else {
            echo "خطة reconciliation بلا كتابة\n";
            echo "database={$plan['database']}\n";
            echo 'checksum_updates=' . count($plan['checksum_updates']) . " (v2…v41)\n";
            echo 'adoptions=' . count($plan['adoptions']) . " (v44…v48)\n";
            echo "plan_sha256={$plan['plan_sha256']}\n";
            echo "لم تُنفذ أي كتابة. راجع البصمة وخذ backup قبل apply.\n";
        }
        exit(0);
    }

    $database = (string) App::config('db')['name'];
    if ((string) ($options['confirm-database'] ?? '') !== $database) {
        throw new RuntimeException('--confirm-database لا يطابق قاعدة الاتصال.');
    }
    $result = MigrationReconciler::apply(
        strtolower((string) ($options['confirm-plan-sha256'] ?? '')),
        strtolower((string) ($options['backup-sha256'] ?? '')),
        (string) ($options['operator'] ?? '')
    );
    echo "✓ اكتملت التسوية: updated={$result['updated']} adopted={$result['adopted']}\n";
    echo "plan_sha256={$result['plan_sha256']}\n";
} catch (Throwable $exception) {
    fwrite(STDERR, '✗ ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
