<?php
namespace App\Core;

/** اكتشاف وتشغيل الترحيلات بترتيب صارم مع سجل checksum وfail-fast. */
class MigrationRunner
{
    /** @return array<int,array{version:int,filename:string,path:string,checksum:string}> */
    public static function discover(?string $dir = null): array
    {
        $dir ??= App::basePath('bin');
        $files = glob(rtrim($dir, '/') . '/migrate_v*.php') ?: [];
        $out = [];
        foreach ($files as $path) {
            $filename = basename($path);
            if (!preg_match('/^migrate_v(\d+)(?:_[a-z0-9_]+)?\.php$/', $filename, $m)) {
                throw new \RuntimeException("اسم ترحيل غير صالح: $filename");
            }
            $version = (int) $m[1];
            if ($version < 1) { throw new \RuntimeException("رقم ترحيل غير صالح: $filename"); }
            if (isset($out[$version])) {
                throw new \RuntimeException("يوجد ملفان للإصدار v$version: {$out[$version]['filename']} و$filename");
            }
            $out[$version] = [
                'version' => $version,
                'filename' => $filename,
                'path' => $path,
                'checksum' => hash_file('sha256', $path),
            ];
        }
        ksort($out, SORT_NUMERIC);
        return $out;
    }

    public static function ledgerExists(): bool
    {
        return (bool) Database::scalar(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_name='schema_migrations'"
        );
    }

    public static function ensureLedger(): void
    {
        Database::exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                version       INT UNSIGNED NOT NULL,
                filename      VARCHAR(190) NOT NULL,
                checksum      CHAR(64) NOT NULL,
                apply_mode    VARCHAR(12) NOT NULL DEFAULT 'applied',
                result        VARCHAR(12) NOT NULL DEFAULT 'success',
                execution_ms  INT UNSIGNED NOT NULL DEFAULT 0,
                applied_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (version),
                UNIQUE KEY uq_schema_migration_file (filename)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /** @return array<int,array> */
    public static function applied(): array
    {
        if (!self::ledgerExists()) { return []; }
        $out = [];
        foreach (Database::all('SELECT * FROM schema_migrations ORDER BY version') as $row) {
            $out[(int) $row['version']] = $row;
        }
        return $out;
    }

    /** يرفض تعديل ملف ترحيل سبق تسجيله أو تبديل اسمه. */
    public static function assertLedgerMatches(array $migrations): void
    {
        foreach (self::applied() as $version => $row) {
            if (!isset($migrations[$version])) {
                throw new \RuntimeException("الترحيل المسجل v$version غير موجود على القرص.");
            }
            $file = $migrations[$version];
            if ($row['filename'] !== $file['filename'] || !hash_equals($row['checksum'], $file['checksum'])) {
                throw new \RuntimeException("الترحيل v$version تغير بعد تطبيقه؛ أنشئ إصدارًا جديدًا بدل تعديل التاريخ.");
            }
        }
    }

    /** اعتماد قاعدة قائمة بعد تحقق مستقل؛ لا يشغل أي ملف. */
    public static function adoptExisting(array $migrations): int
    {
        self::ensureLedger();
        if (self::applied()) { throw new \RuntimeException('سجل الترحيلات غير فارغ؛ لا يمكن تنفيذ adopt-existing.'); }
        foreach ($migrations as $migration) {
            Database::exec(
                'INSERT INTO schema_migrations(version,filename,checksum,apply_mode,result,execution_ms) VALUES(?,?,?,?,?,0)',
                [$migration['version'], $migration['filename'], $migration['checksum'], 'adopted', 'success']
            );
        }
        return count($migrations);
    }

    /** @return array<int,array> */
    public static function pending(array $migrations): array
    {
        $applied = self::applied();
        return array_filter($migrations, fn(array $m): bool => !isset($applied[$m['version']]));
    }

    /** @return array{count:int,versions:array<int>,output:array<int,string>} */
    public static function runPending(array $migrations): array
    {
        self::ensureLedger();
        self::assertLedgerMatches($migrations);
        $pending = self::pending($migrations);
        $applied = self::applied();
        $maxApplied = $applied ? max(array_keys($applied)) : 0;
        foreach ($pending as $migration) {
            if ($migration['version'] < $maxApplied) {
                throw new \RuntimeException(
                    "لا يمكن إدخال الترحيل v{$migration['version']} قبل آخر إصدار مطبّق v$maxApplied؛ أنشئ إصدارًا جديدًا."
                );
            }
        }
        $versions = []; $outputs = [];
        foreach ($pending as $migration) {
            $start = hrtime(true);
            [$code, $output] = self::runPhp($migration['path']);
            $ms = max(0, (int) round((hrtime(true) - $start) / 1_000_000));
            if ($code !== 0) {
                $tail = implode("\n", array_slice(preg_split('/\R/', trim($output)) ?: [], -8));
                throw new \RuntimeException("فشل الترحيل v{$migration['version']} ({$migration['filename']}).\n$tail");
            }
            Database::exec(
                'INSERT INTO schema_migrations(version,filename,checksum,apply_mode,result,execution_ms) VALUES(?,?,?,?,?,?)',
                [$migration['version'], $migration['filename'], $migration['checksum'], 'applied', 'success', $ms]
            );
            $versions[] = $migration['version'];
            $outputs[$migration['version']] = trim($output);
        }
        return ['count' => count($versions), 'versions' => $versions, 'output' => $outputs];
    }

    /** تشغيل مهمة تسوية صريحة لا تُعد ترحيلًا جديدًا، مع نفس سلوك fail-fast. */
    public static function runMaintenanceFile(string $path): string
    {
        if (!is_file($path)) { throw new \RuntimeException('ملف التسوية المطلوب غير موجود.'); }
        [$code, $output] = self::runPhp($path);
        if ($code !== 0) {
            $tail = implode("\n", array_slice(preg_split('/\R/', trim($output)) ?: [], -8));
            throw new \RuntimeException("فشلت مهمة التسوية.\n$tail");
        }
        return trim($output);
    }

    /** @return array{0:int,1:string} */
    private static function runPhp(string $path): array
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY ?: 'php', $path], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, App::basePath());
        if (!is_resource($process)) { return [1, 'تعذّر بدء عملية PHP.']; }
        $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $code = proc_close($process);
        return [$code, trim($stdout . "\n" . $stderr)];
    }
}
