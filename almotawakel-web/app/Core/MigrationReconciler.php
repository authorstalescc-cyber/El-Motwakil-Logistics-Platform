<?php
namespace App\Core;

/**
 * تسوية الحادثة التاريخية v2–v48 مرة واحدة وبشروط إثبات صارمة.
 *
 * لا تشغّل أي migration. مهمتها الوحيدة:
 *   1) إثبات أن فرق v2–v41 هو حارس CLI المعروف حرفيًا.
 *   2) إثبات postconditions الخاصة بـv44–v48 في schema الحالية.
 *   3) تحديث baseline وتسجيل كل تغيير في سجل append-only.
 */
class MigrationReconciler
{
    /** @return array<string,mixed> */
    public static function buildPlan(?array $migrations = null): array
    {
        $migrations ??= MigrationRunner::discover();
        if (!MigrationRunner::ledgerExists()) {
            throw new \RuntimeException('سجل schema_migrations غير موجود.');
        }

        $applied = MigrationRunner::applied();
        $expectedApplied = range(2, 41);
        if (array_keys($applied) !== $expectedApplied) {
            throw new \RuntimeException(
                'التسوية مخصصة للحالة المثبتة فقط: يجب أن يحتوي ledger على v2…v41 بالضبط.'
            );
        }

        $expectedPending = [44, 45, 46, 47, 48];
        $pending = array_values(array_map(
            'intval',
            array_diff(array_keys($migrations), array_keys($applied))
        ));
        if ($pending !== $expectedPending) {
            throw new \RuntimeException(
                'التسوية مخصصة للحالة المثبتة فقط: يجب أن تكون pending هي v44…v48 بالضبط.'
            );
        }

        $updates = [];
        foreach ($expectedApplied as $version) {
            if (!isset($migrations[$version])) {
                throw new \RuntimeException("ملف v$version غير موجود.");
            }
            $row = $applied[$version];
            $file = $migrations[$version];
            if ((string) $row['filename'] !== $file['filename']) {
                throw new \RuntimeException("اسم ملف v$version تغيّر؛ يُرفض reconciliation.");
            }
            if (hash_equals((string) $row['checksum'], $file['checksum'])) {
                throw new \RuntimeException(
                    "v$version متطابق أصلًا؛ الحالة لم تعد حادثة الحارس ذات الأربعين اختلافًا."
                );
            }

            $beforeGuard = MigrationRunner::checksumBeforeCliGuard($file['path']);
            if (!hash_equals((string) $row['checksum'], $beforeGuard)) {
                throw new \RuntimeException(
                    "فرق v$version ليس حارس CLI فقط؛ يُرفض تعديل baseline."
                );
            }

            $updates[] = [
                'version' => $version,
                'filename' => $file['filename'],
                'old_checksum' => (string) $row['checksum'],
                'new_checksum' => $file['checksum'],
                'proof' => 'exact_cli_guard_removal_matches_old_checksum',
            ];
        }

        $postconditions = self::postconditions();
        foreach ($postconditions['checks'] as $name => $passed) {
            if ($passed !== true) {
                throw new \RuntimeException("فشل postcondition المطلوب قبل adoption: $name");
            }
        }

        $adoptions = [];
        foreach ($expectedPending as $version) {
            $file = $migrations[$version];
            $adoptions[] = [
                'version' => $version,
                'filename' => $file['filename'],
                'new_checksum' => $file['checksum'],
                'proof' => 'schema_postconditions_verified',
            ];
        }

        $plan = [
            'plan_version' => 1,
            'database' => (string) Database::scalar('SELECT DATABASE()'),
            'ledger_before_count' => count($applied),
            'checksum_updates' => $updates,
            'adoptions' => $adoptions,
            'postconditions' => $postconditions,
        ];
        $plan['plan_sha256'] = self::planHash($plan);
        return $plan;
    }

    /**
     * @return array{updated:int,adopted:int,plan_sha256:string}
     */
    public static function apply(
        string $confirmedPlanSha256,
        string $backupSha256,
        string $operatorName
    ): array {
        $backupSha256 = strtolower(trim($backupSha256));
        $operatorName = trim($operatorName);
        if (!preg_match('/^[a-f0-9]{64}$/', $backupSha256)) {
            throw new \RuntimeException('بصمة SHA-256 لنسخة الإنتاج الاحتياطية مطلوبة.');
        }
        if ($operatorName === '' || mb_strlen($operatorName) > 120) {
            throw new \RuntimeException('اسم منفذ التسوية مطلوب وبحد أقصى 120 حرفًا.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', strtolower($confirmedPlanSha256))) {
            throw new \RuntimeException('قيمة --confirm-plan-sha256 غير صالحة.');
        }

        MigrationRunner::acquireLock();
        try {
            $plan = self::buildPlan();
            if (!hash_equals($plan['plan_sha256'], strtolower($confirmedPlanSha256))) {
                throw new \RuntimeException(
                    'خطة التسوية تغيّرت منذ المعاينة؛ أعد dry-run وراجع البصمة الجديدة.'
                );
            }

            // هذا DDL متعمد وخارج transaction لأن MySQL/MariaDB ينفذان implicit
            // commit. لا نلمس baseline قبل التأكد من وجود سجل التدقيق.
            MigrationRunner::ensureEventLedger();

            Database::conn()->beginTransaction();
            try {
                foreach ($plan['checksum_updates'] as $item) {
                    $affected = Database::exec(
                        'UPDATE schema_migrations SET checksum=?
                         WHERE version=? AND filename=? AND checksum=?',
                        [
                            $item['new_checksum'],
                            $item['version'],
                            $item['filename'],
                            $item['old_checksum'],
                        ]
                    );
                    if ($affected !== 1) {
                        throw new \RuntimeException("تغير صف ledger أثناء تسوية v{$item['version']}.");
                    }
                    MigrationRunner::recordEvent([
                        'version' => $item['version'],
                        'action' => 'checksum_rebased',
                        'old_checksum' => $item['old_checksum'],
                        'new_checksum' => $item['new_checksum'],
                        'backup_sha256' => $backupSha256,
                        'evidence_sha256' => $plan['plan_sha256'],
                        'operator_name' => $operatorName,
                        'details' => [
                            'reason' => 'CLI guard added after historical migration adoption',
                            'proof' => $item['proof'],
                        ],
                    ]);
                }

                foreach ($plan['adoptions'] as $item) {
                    Database::exec(
                        'INSERT INTO schema_migrations
                         (version,filename,checksum,apply_mode,result,execution_ms)
                         VALUES(?,?,?,?,?,0)',
                        [
                            $item['version'],
                            $item['filename'],
                            $item['new_checksum'],
                            'reconciled',
                            'success',
                        ]
                    );
                    MigrationRunner::recordEvent([
                        'version' => $item['version'],
                        'action' => 'adopted_existing',
                        'old_checksum' => null,
                        'new_checksum' => $item['new_checksum'],
                        'backup_sha256' => $backupSha256,
                        'evidence_sha256' => $plan['plan_sha256'],
                        'operator_name' => $operatorName,
                        'details' => [
                            'reason' => 'Schema effects existed after direct deploy execution',
                            'proof' => $item['proof'],
                            'postconditions' => $plan['postconditions']['checks'],
                        ],
                    ]);
                }
                Database::conn()->commit();
            } catch (\Throwable $exception) {
                if (Database::conn()->inTransaction()) {
                    Database::conn()->rollBack();
                }
                throw $exception;
            }

            $migrations = MigrationRunner::discover();
            MigrationRunner::assertLedgerMatches($migrations);
            if (MigrationRunner::pending($migrations)) {
                throw new \RuntimeException('اكتملت الكتابة لكن بقيت ترحيلات معلقة؛ راجع فورًا.');
            }

            return [
                'updated' => count($plan['checksum_updates']),
                'adopted' => count($plan['adoptions']),
                'plan_sha256' => $plan['plan_sha256'],
            ];
        } finally {
            MigrationRunner::releaseLock();
        }
    }

    /** @return array{checks:array<string,bool>,evidence:array<string,int>} */
    private static function postconditions(): array
    {
        $checks = [
            'v44.reps.can_create_permit' => self::columnExists('reps', 'can_create_permit'),
            'v45.rep_submissions.is_visa' => self::columnExists('rep_submissions', 'is_visa'),
            'v45.rep_submissions.visa_card_id' => self::columnExists('rep_submissions', 'visa_card_id'),
            'v45.rep_misc_expenses.is_visa' => self::columnExists('rep_misc_expenses', 'is_visa'),
            'v45.rep_misc_expenses.visa_card_id' => self::columnExists('rep_misc_expenses', 'visa_card_id'),
            'v45.rep_driver_handovers' => self::tableExists('rep_driver_handovers'),
            'v45.fk_rdh_rep' => self::constraintExists('rep_driver_handovers', 'fk_rdh_rep'),
            'v46.rep_custody_transfers' => self::tableExists('rep_custody_transfers'),
            'v46.fk_rct_from' => self::constraintExists('rep_custody_transfers', 'fk_rct_from'),
            'v46.fk_rct_to' => self::constraintExists('rep_custody_transfers', 'fk_rct_to'),
            'v47.reps.can_watch_reps' => self::columnExists('reps', 'can_watch_reps'),
            'v48.rep_users.weak_pin' => self::columnExists('rep_users', 'weak_pin'),
            'v48.rep_users.pin_set_at' => self::columnExists('rep_users', 'pin_set_at'),
        ];

        if ($checks['v44.reps.can_create_permit']) {
            $checks['v44.expected_grants'] = (int) Database::scalar(
                "SELECT COUNT(*) FROM rep_users ru
                 JOIN reps r ON r.id=ru.rep_id
                 WHERE ru.username IN ('badr','fekry') AND r.can_create_permit<>1"
            ) === 0;
        } else {
            $checks['v44.expected_grants'] = false;
        }

        if ($checks['v47.reps.can_watch_reps']) {
            $checks['v47.expected_grant'] = (int) Database::scalar(
                "SELECT COUNT(*) FROM rep_users ru
                 JOIN reps r ON r.id=ru.rep_id
                 WHERE ru.username='fekry' AND r.can_watch_reps<>1"
            ) === 0;
        } else {
            $checks['v47.expected_grant'] = false;
        }

        if ($checks['v48.rep_users.weak_pin']) {
            $checks['v48.weak_pin_domain'] = (int) Database::scalar(
                'SELECT COUNT(*) FROM rep_users WHERE weak_pin IS NULL OR weak_pin NOT IN (0,1)'
            ) === 0;
        } else {
            $checks['v48.weak_pin_domain'] = false;
        }

        $evidence = [
            'rep_driver_handovers_rows' => $checks['v45.rep_driver_handovers']
                ? (int) Database::scalar('SELECT COUNT(*) FROM rep_driver_handovers') : 0,
            'rep_custody_transfers_rows' => $checks['v46.rep_custody_transfers']
                ? (int) Database::scalar('SELECT COUNT(*) FROM rep_custody_transfers') : 0,
            'rep_users_rows' => self::tableExists('rep_users')
                ? (int) Database::scalar('SELECT COUNT(*) FROM rep_users') : 0,
        ];

        return ['checks' => $checks, 'evidence' => $evidence];
    }

    private static function tableExists(string $table): bool
    {
        return (bool) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_name=?',
            [$table]
        );
    }

    private static function columnExists(string $table, string $column): bool
    {
        return (bool) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
            [$table, $column]
        );
    }

    private static function constraintExists(string $table, string $constraint): bool
    {
        return (bool) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.table_constraints
             WHERE constraint_schema=DATABASE() AND table_name=? AND constraint_name=?',
            [$table, $constraint]
        );
    }

    /** @param array<string,mixed> $plan */
    private static function planHash(array $plan): string
    {
        unset($plan['plan_sha256']);
        // أعداد السجلات دليل تدقيق فقط وقد تتغير أثناء نافذة الصيانة؛ لا تجعل
        // إضافة حركة تشغيلية سليمة بين plan وapply بصمة الخطة غير صالحة.
        unset($plan['postconditions']['evidence']);
        $canonical = self::canonicalize($plan);
        return hash(
            'sha256',
            json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }
}
