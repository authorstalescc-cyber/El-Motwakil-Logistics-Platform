<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** بصمة schema للقراءة فقط، مطبعة بين MySQL وMariaDB. */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\SchemaFingerprint;

try {
    $json = in_array('--json', $argv, true);
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument !== '--json') {
            throw new RuntimeException("خيار غير معروف: $argument");
        }
    }
    $fingerprint = SchemaFingerprint::capture();
    if ($json) {
        echo json_encode(
            $fingerprint,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        exit(0);
    }
    foreach ($fingerprint['categories'] as $name => $category) {
        echo $name . ' ' . $category['count'] . ' ' . $category['sha256'] . PHP_EOL;
    }
    echo 'overall ' . $fingerprint['overall_sha256'] . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, '✗ ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
