<?php
namespace App\Core;

/** اكتشاف وتشغيل الترحيلات بترتيب صارم مع سجل checksum وfail-fast. */
class MigrationRunner
{
    private const LOCK_NAME = 'almotawakel_schema_migrations';
    private const STRUCTURED_FROM_VERSION = 49;

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
        self::ensureEventLedger();
        if (self::applied()) { throw new \RuntimeException('سجل الترحيلات غير فارغ؛ لا يمكن تنفيذ adopt-existing.'); }
        foreach ($migrations as $migration) {
            Database::conn()->beginTransaction();
            try {
                Database::exec(
                    'INSERT INTO schema_migrations(version,filename,checksum,apply_mode,result,execution_ms) VALUES(?,?,?,?,?,0)',
                    [$migration['version'], $migration['filename'], $migration['checksum'], 'adopted', 'success']
                );
                self::recordEvent([
                    'version' => $migration['version'],
                    'action' => 'adopted_existing',
                    'old_checksum' => null,
                    'new_checksum' => $migration['checksum'],
                    'operator_name' => 'install.php',
                    'evidence_sha256' => hash('sha256', $migration['filename'] . '|' . $migration['checksum']),
                    'details' => ['runner' => 'adopt-existing'],
                ]);
                Database::conn()->commit();
            } catch (\Throwable $exception) {
                if (Database::conn()->inTransaction()) {
                    Database::conn()->rollBack();
                }
                throw $exception;
            }
        }
        return count($migrations);
    }

    /** @return array<int,array> */
    public static function pending(array $migrations): array
    {
        $applied = self::applied();
        return array_filter($migrations, fn(array $m): bool => !isset($applied[$m['version']]));
    }

    /**
     * تشغيل إصدار واحد داخل عملية PHP الحالية.
     *
     * صُمم هذا المسار للاستضافات التي تعطل proc_open: يستدعي deploy.sh أمر
     * CLI مستقلًا لكل إصدار، فيظل كل ملف migration داخل عملية معزولة، لكن لا
     * يمكنه تجاوز فحص ledger أو التسجيل بعد النجاح.
     *
     * @return array{version:int,filename:string,execution_ms:int,output:string}
     */
    public static function applyOneInProcess(
        int $version,
        ?array $migrations = null,
        array $auditContext = []
    ): array
    {
        $migrations ??= self::discover();
        if (!isset($migrations[$version])) {
            throw new \RuntimeException("لا يوجد ملف ترحيل v$version.");
        }
        self::assertAuditContext($auditContext);

        self::acquireLock();
        $buffering = false;
        try {
            self::ensureLedger();
            if (!self::eventLedgerExists()) {
                throw new \RuntimeException(
                    'جدول schema_migration_events غير موجود؛ أكمل baseline/reconciliation قبل أي ترحيل جديد.'
                );
            }
            self::assertLedgerMatches($migrations);

            $pending = self::pending($migrations);
            if (!isset($pending[$version])) {
                throw new \RuntimeException("الترحيل v$version مسجل بالفعل؛ لن يُعاد تشغيله.");
            }
            $firstPending = (int) array_key_first($pending);
            if ($version !== $firstPending) {
                throw new \RuntimeException("الترتيب صارم؛ يجب تشغيل v$firstPending قبل v$version.");
            }

            $applied = self::applied();
            $maxApplied = $applied ? max(array_keys($applied)) : 0;
            if ($version < $maxApplied) {
                throw new \RuntimeException(
                    "لا يمكن إدخال v$version قبل آخر إصدار مطبّق v$maxApplied؛ يلزم reconciliation صريح."
                );
            }

            $migration = $migrations[$version];
            $start = hrtime(true);
            ob_start();
            $buffering = true;
            $definition = require $migration['path'];

            if ($version >= self::STRUCTURED_FROM_VERSION) {
                self::runStructuredDefinition($version, $definition);
            }

            $output = trim((string) ob_get_clean());
            $buffering = false;
            $ms = max(0, (int) round((hrtime(true) - $start) / 1_000_000));

            if (Database::conn()->inTransaction()) {
                throw new \RuntimeException(
                    "الترحيل v$version ترك transaction مفتوحة؛ لن يُسجل نجاح غير مكتمل."
                );
            }

            Database::conn()->beginTransaction();
            try {
                Database::exec(
                    'INSERT INTO schema_migrations(version,filename,checksum,apply_mode,result,execution_ms)
                     VALUES(?,?,?,?,?,?)',
                    [$version, $migration['filename'], $migration['checksum'], 'applied', 'success', $ms]
                );
                self::recordEvent([
                    'version' => $version,
                    'action' => 'applied',
                    'old_checksum' => null,
                    'new_checksum' => $migration['checksum'],
                    'backup_sha256' => $auditContext['backup_sha256'],
                    'evidence_sha256' => hash('sha256', $migration['checksum'] . '|' . $ms . '|' . $output),
                    'operator_name' => $auditContext['operator_name'],
                    'details' => [
                        'filename' => $migration['filename'],
                        'execution_ms' => $ms,
                        'runner' => 'in_process_single_version',
                    ],
                ]);
                Database::conn()->commit();
            } catch (\Throwable $exception) {
                if (Database::conn()->inTransaction()) {
                    Database::conn()->rollBack();
                }
                throw $exception;
            }

            return [
                'version' => $version,
                'filename' => $migration['filename'],
                'execution_ms' => $ms,
                'output' => $output,
            ];
        } finally {
            if ($buffering) {
                ob_end_clean();
            }
            self::releaseLock();
        }
    }

    /**
     * البنية الإلزامية للترحيلات الجديدة من v49:
     *   return ['up' => callable, 'verify' => callable];
     *
     * verify يجب أن يعيد true بعد التطبيق. وجوده يجعل نجاح ملف PHP وحده غير
     * كافٍ لتسجيل الإصدار.
     */
    private static function runStructuredDefinition(int $version, mixed $definition): void
    {
        if (!is_array($definition)
            || !isset($definition['up'], $definition['verify'])
            || !is_callable($definition['up'])
            || !is_callable($definition['verify'])) {
            throw new \RuntimeException(
                "الترحيل v$version يجب أن يعيد up وverify قابلتين للاستدعاء."
            );
        }

        ($definition['up'])();
        if (($definition['verify'])() !== true) {
            throw new \RuntimeException("فشل تحقق ما بعد الشروط للترحيل v$version؛ لم يُسجل في ledger.");
        }
    }

    public static function acquireLock(int $timeoutSeconds = 10): void
    {
        $locked = Database::scalar('SELECT GET_LOCK(?, ?)', [self::LOCK_NAME, $timeoutSeconds]);
        if ((int) $locked !== 1) {
            throw new \RuntimeException('تعذّر حجز قفل المايجريشن؛ توجد عملية أخرى أو القفل غير متاح.');
        }
    }

    public static function releaseLock(): void
    {
        try {
            Database::scalar('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
        } catch (\Throwable) {
            // إغلاق اتصال CLI يحرر advisory lock تلقائيًا. لا نخفي خطأ
            // الترحيل الأصلي إذا تعذر استعلام التحرير أثناء انهيار الاتصال.
        }
    }

    public static function eventLedgerExists(): bool
    {
        return (bool) Database::scalar(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_name='schema_migration_events'"
        );
    }

    public static function ensureEventLedger(): void
    {
        Database::exec(
            "CREATE TABLE IF NOT EXISTS schema_migration_events (
                id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                version          INT UNSIGNED NOT NULL,
                action           VARCHAR(32) NOT NULL,
                old_checksum     CHAR(64) NULL,
                new_checksum     CHAR(64) NOT NULL,
                backup_sha256    CHAR(64) NULL,
                evidence_sha256  CHAR(64) NULL,
                operator_name    VARCHAR(120) NOT NULL,
                details_json     LONGTEXT NULL,
                created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_schema_migration_event_version (version, created_at),
                KEY idx_schema_migration_event_action (action, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public static function recordEvent(array $event): void
    {
        $details = isset($event['details'])
            ? json_encode($event['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            : null;
        Database::exec(
            'INSERT INTO schema_migration_events
             (version,action,old_checksum,new_checksum,backup_sha256,evidence_sha256,operator_name,details_json)
             VALUES(?,?,?,?,?,?,?,?)',
            [
                (int) $event['version'],
                (string) $event['action'],
                $event['old_checksum'] ?? null,
                (string) $event['new_checksum'],
                $event['backup_sha256'] ?? null,
                $event['evidence_sha256'] ?? null,
                (string) $event['operator_name'],
                $details,
            ]
        );
    }

    private static function assertAuditContext(array $context): void
    {
        $operator = trim((string) ($context['operator_name'] ?? ''));
        $backup = strtolower(trim((string) ($context['backup_sha256'] ?? '')));
        if ($operator === '' || mb_strlen($operator) > 120) {
            throw new \RuntimeException('اسم منفذ الترحيل مطلوب وبحد أقصى 120 حرفًا.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $backup)) {
            throw new \RuntimeException('بصمة SHA-256 لنسخة ما قبل الترحيل مطلوبة.');
        }
    }

    /**
     * بصمة الملف قبل إضافة حارس CLI المعروف.
     *
     * لا يحذف «أول خمسة أسطر» عميانيًا؛ يجب أن يطابق النص الأمني حرفيًا حتى
     * لا تتحول أداة reconciliation إلى وسيلة لاعتماد تعديل وظيفي قديم.
     */
    public static function checksumBeforeCliGuard(string $path): string
    {
        $source = (string) file_get_contents($path);
        $guardedPrefix = <<<'PHP'
<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
PHP;
        $guardedPrefix .= "\n";
        if (!str_starts_with($source, $guardedPrefix)) {
            throw new \RuntimeException('ملف الترحيل لا يبدأ بحارس CLI المعروف حرفيًا: ' . basename($path));
        }

        $legacySource = "<?php\n" . substr($source, strlen($guardedPrefix));
        return hash('sha256', $legacySource);
    }

    /** @return array{count:int,versions:array<int>,output:array<int,string>} */
    public static function runPending(array $migrations): array
    {
        self::ensureLedger();
        if (!self::eventLedgerExists()) {
            throw new \RuntimeException(
                'جدول schema_migration_events غير موجود؛ نفّذ reconciliation/ترقية baseline قبل التحديث.'
            );
        }
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
            Database::conn()->beginTransaction();
            try {
                Database::exec(
                    'INSERT INTO schema_migrations(version,filename,checksum,apply_mode,result,execution_ms) VALUES(?,?,?,?,?,?)',
                    [$migration['version'], $migration['filename'], $migration['checksum'], 'applied', 'success', $ms]
                );
                self::recordEvent([
                    'version' => $migration['version'],
                    'action' => 'applied',
                    'old_checksum' => null,
                    'new_checksum' => $migration['checksum'],
                    'operator_name' => 'install.php',
                    'evidence_sha256' => hash('sha256', $migration['filename'] . '|' . $ms),
                    'details' => [
                        'filename' => $migration['filename'],
                        'execution_ms' => $ms,
                        'runner' => 'proc_open_single_child',
                    ],
                ]);
                Database::conn()->commit();
            } catch (\Throwable $exception) {
                if (Database::conn()->inTransaction()) {
                    Database::conn()->rollBack();
                }
                throw $exception;
            }
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
