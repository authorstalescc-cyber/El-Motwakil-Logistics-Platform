<?php
namespace App\Core;

/**
 * قياس أداء اختياري لبيئة الاختبار المحلية فقط.
 *
 * لا يسجل SQL أو parameters أو query string. الفاحص البطيء يُعرّف ببصمة
 * لشكل الاستعلام بعد إزالة النصوص والأرقام، حتى لا تدخل بيانات العمل للسجل.
 */
class Performance
{
    private static bool $enabled = false;
    private static bool $finished = false;
    private static float $startedAt = 0.0;
    private static int $queryCount = 0;
    private static float $databaseMs = 0.0;
    private static float $slowThresholdMs = 100.0;
    /** @var array<int,array{id:string,kind:string,ms:float}> */
    private static array $slowQueries = [];
    /** @var array<int,array{name:string,ms:float,db_ms:float,queries:int}> */
    private static array $spans = [];

    public static function start(): void
    {
        if (getenv('ALMOTAWAKEL_PERF') !== '1') { return; }
        if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) {
            throw new \RuntimeException('قياس الأداء مسموح في CLI أو الخادم المحلي فقط.');
        }
        $database = (string) (App::config('db')['name'] ?? '');
        if (!preg_match('/^almotawakel_test_[a-z0-9_]{6,48}$/', $database)) {
            throw new \RuntimeException('قياس الأداء يتطلب قاعدة اختبار معزولة باسم almotawakel_test_*.');
        }

        $threshold = (float) (getenv('ALMOTAWAKEL_PERF_SLOW_MS') ?: 100);
        self::$slowThresholdMs = max(1.0, min(60000.0, $threshold));
        self::$startedAt = hrtime(true) / 1e6;
        self::$enabled = true;
        register_shutdown_function([self::class, 'finish']);
    }

    public static function enabled(): bool
    {
        return self::$enabled;
    }

    /** قِس عملية قاعدة واحدة مع إبقاء قيمة الإرجاع والاستثناء كما هما. */
    public static function measure(string $sql, callable $operation)
    {
        if (!self::$enabled) { return $operation(); }
        $started = hrtime(true) / 1e6;
        try {
            return $operation();
        } finally {
            $elapsed = (hrtime(true) / 1e6) - $started;
            self::$queryCount++;
            self::$databaseMs += $elapsed;
            if ($elapsed >= self::$slowThresholdMs && count(self::$slowQueries) < 20) {
                self::$slowQueries[] = [
                    'id'   => self::fingerprint($sql),
                    'kind' => self::queryKind($sql),
                    'ms'   => round($elapsed, 2),
                ];
            }
        }
    }

    /** جزء مسمى ثابت من الطلب لتحديد مصدر الكلفة بلا تسجيل مدخلات المستخدم. */
    public static function span(string $name, callable $operation)
    {
        if (!self::$enabled) { return $operation(); }
        $safeName = preg_match('/^[a-z0-9_.-]{1,60}$/', $name) ? $name : 'other';
        $started = hrtime(true) / 1e6;
        $queriesBefore = self::$queryCount;
        $databaseBefore = self::$databaseMs;
        try {
            return $operation();
        } finally {
            if (count(self::$spans) < 50) {
                self::$spans[] = [
                    'name'    => $safeName,
                    'ms'      => round((hrtime(true) / 1e6) - $started, 2),
                    'db_ms'   => round(self::$databaseMs - $databaseBefore, 2),
                    'queries' => self::$queryCount - $queriesBefore,
                ];
            }
        }
    }

    public static function finish(): void
    {
        if (!self::$enabled || self::$finished) { return; }
        self::$finished = true;
        $totalMs = max(0.0, (hrtime(true) / 1e6) - self::$startedAt);
        $payload = [
            'at'            => gmdate('c'),
            'method'        => self::method(),
            'route'         => self::route(),
            'status'        => self::status(),
            'total_ms'      => round($totalMs, 2),
            'db_ms'         => round(self::$databaseMs, 2),
            'query_count'   => self::$queryCount,
            'response_bytes'=> ob_get_length() === false ? null : ob_get_length(),
            'peak_memory_mb'=> round(memory_get_peak_usage(true) / 1048576, 2),
            'slow_queries'  => self::$slowQueries,
            'spans'         => self::$spans,
        ];

        if (!headers_sent()) {
            header(sprintf('Server-Timing: app;dur=%.2f, db;dur=%.2f', $totalMs, self::$databaseMs));
            header('X-Perf-Queries: ' . self::$queryCount);
            header('X-Perf-Slow-Queries: ' . count(self::$slowQueries));
            if (is_int($payload['response_bytes'])) {
                header('X-Perf-Response-Bytes: ' . $payload['response_bytes']);
            }
        }
        self::append($payload);
    }

    private static function append(array $payload): void
    {
        $path = trim((string) getenv('ALMOTAWAKEL_PERF_LOG'));
        if ($path === '') { $path = App::basePath('storage/performance.log'); }
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) { return; }
        $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line !== false) { @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX); }
    }

    private static function method(): string
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'CLI'));
        return preg_match('/^[A-Z]{2,10}$/', $method) ? $method : 'OTHER';
    }

    /** المسار فقط، بلا query string، مع إخفاء المعرفات والتوكنات الطويلة. */
    private static function route(): string
    {
        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/cli'), PHP_URL_PATH) ?: '/');
        $parts = array_map(static function (string $part): string {
            if (preg_match('/^\d+$/', $part)) { return '{id}'; }
            if (preg_match('/^[a-zA-Z0-9_-]{14,}$/', $part)) { return '{token}'; }
            return $part;
        }, explode('/', trim($path, '/')));
        $safe = '/' . implode('/', $parts);
        return $safe === '/' ? '/' : rtrim($safe, '/');
    }

    private static function status(): int
    {
        $status = http_response_code();
        return is_int($status) && $status >= 100 && $status <= 599 ? $status : 200;
    }

    private static function queryKind(string $sql): string
    {
        return preg_match('/^\s*([a-z]+)/i', $sql, $match) ? strtoupper($match[1]) : 'OTHER';
    }

    private static function fingerprint(string $sql): string
    {
        $shape = preg_replace("/'(?:''|[^'])*'/s", '?', $sql) ?? $sql;
        $shape = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $shape) ?? $shape;
        $shape = preg_replace('/\s+/', ' ', trim($shape)) ?? trim($shape);
        return substr(hash('sha256', strtolower($shape)), 0, 12);
    }
}
