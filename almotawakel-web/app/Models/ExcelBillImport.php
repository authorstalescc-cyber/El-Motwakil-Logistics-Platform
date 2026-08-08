<?php
namespace App\Models;

use App\Calc;
use App\Core\App;
use App\Core\Auth;
use App\Core\Database;
use App\Services\XlsxReader;
use RuntimeException;

/**
 * تحديث نسخة Excel واعتماد الفروق التي مرّت من المعاينة.
 *
 * الكتابة منفصلة تمامًا عن ExcelBillSync حتى تظل المعاينة GET قراءة فقط.
 * كل اعتماد يعيد المقارنة بعد قفل البوالص والدفعات، ثم يحفظ الدفعة كلها
 * داخل transaction واحدة أو يلغيها كلها.
 */
class ExcelBillImport
{
    private const TARGET_FILE = '2026622.xlsx';
    private const PREVIOUS_FILE = '2026622.previous.xlsx';
    private const META_FILE = 'source.json';
    private const LOCK_FILE = '.sync.lock';
    /** حد الرفع العام في النظام؛ القارئ نفسه يقبل 30MB لمسار OneDrive المحلي. */
    private const MAX_BYTES = 12 * 1024 * 1024;

    private const REQUIRED_HEADERS = [
        'A' => 'صاحب البيان',
        'B' => 'تاريخ',
        'C' => 'رقم البوليصة',
        'H' => 'رقم السيارة',
        'K' => 'نولون',
        'M' => 'تحويلة',
        'Q' => 'دفعه',
        'S' => 'دور سواق',
        'T' => 'دور تباع',
        'AF' => 'جاز',
    ];

    /** حالة المصدر الحالية وهل يجوز الاعتماد منه. */
    public static function sourceState(?array $source): array
    {
        if (!$source || empty($source['path']) || !is_file((string) $source['path'])) {
            return ['ready' => false, 'sha256' => null, 'meta' => null,
                'reason' => 'مصدر Excel غير موجود.'];
        }

        $path = (string) $source['path'];
        $sha = hash_file('sha256', $path);
        if ($sha === false) {
            return ['ready' => false, 'sha256' => null, 'meta' => null,
                'reason' => 'تعذّر حساب بصمة ملف Excel.'];
        }

        $mode = (string) ($source['mode'] ?? '');
        if ($mode === 'onedrive_local') {
            return ['ready' => true, 'sha256' => $sha, 'meta' => null, 'reason' => null];
        }

        if ($mode !== 'server_snapshot') {
            return ['ready' => false, 'sha256' => $sha, 'meta' => null,
                'reason' => 'النسخة المحلية الحالية غير موثّقة كتحديث حديث من المستخدم.'];
        }

        $meta = self::readMeta();
        $ready = is_array($meta) && hash_equals((string) ($meta['sha256'] ?? ''), $sha);
        return [
            'ready' => $ready,
            'sha256' => $sha,
            'meta' => $meta,
            'reason' => $ready ? null : 'ارفع أحدث نسخة من OneDrive قبل الاعتماد؛ النسخة الحالية لم تُرفع من شاشة المزامنة.',
        ];
    }

    /** بصمة بوليصة واحدة بكل قيم المقارنة؛ أي تغيير بعد المراجعة يبطلها. */
    public static function rowToken(array $row, array $source): string
    {
        $state = self::sourceState($source);
        $payload = [
            'source_sha256' => $state['sha256'] ?? null,
            'excel_row' => (int) ($row['excel_row'] ?? 0),
            'bill_id' => (int) ($row['bill']['id'] ?? 0),
            'match' => (string) ($row['match'] ?? ''),
            'fields' => array_map(static fn(array $field): array => [
                'key' => $field['key'],
                'system' => $field['system'],
                'excel' => $field['excel'],
                'selectable' => (bool) $field['selectable'],
                'blocked' => $field['blocked_reason'],
            ], ExcelBillSync::comparisonFields($row)),
        ];
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * يثبت اختيار المستخدم ويحسب النتائج المتوقعة لشاشة التأكيد، بلا كتابة.
     *
     * @return array{fields:array<int,array<string,mixed>>,calculated:array<string,float>,payment_delta:float}
     */
    public static function selectionPreview(array $row, array $selected): array
    {
        if (($row['match'] ?? '') !== 'matched' || empty($row['bill'])) {
            throw new RuntimeException('صف Excel غير مربوط ببوليصة واحدة في النظام.');
        }
        if (!empty($row['conflicts'])) {
            throw new RuntimeException('لا يمكن تنفيذ هذه البوليصة قبل حل التعارضات الظاهرة.');
        }

        $selected = array_values(array_unique(array_filter(array_map('strval', $selected))));
        if (!$selected) { throw new RuntimeException('اختر حقلًا واحدًا على الأقل تريد تغييره.'); }

        $byKey = [];
        foreach (ExcelBillSync::comparisonFields($row) as $field) {
            $byKey[$field['key']] = $field;
        }
        $changes = [];
        $next = $row['bill'];
        $paymentDelta = 0.0;
        foreach ($selected as $key) {
            $field = $byKey[$key] ?? null;
            if (!$field || empty($field['selectable']) || empty($field['changed'])) {
                throw new RuntimeException('الحقل «' . ($field['label'] ?? $key) . '» غير متاح للتنفيذ.');
            }
            if (!in_array($key, self::writableFields(), true) && $key !== 'paid') {
                throw new RuntimeException('الحقل «' . $field['label'] . '» للعرض فقط.');
            }
            if (!empty($field['requires_car_no']) && !in_array('car_no', $selected, true)) {
                throw new RuntimeException('اختيار مصروفات سيارة المكتب يتطلب اختيار «رقم السيارة» أيضًا.');
            }
            if ($key === 'paid') {
                $paymentDelta = round((float) ($field['delta'] ?? 0), 2);
            } else {
                $next[$key] = $field['excel'];
            }
            $changes[] = $field;
        }

        $calculated = Calc::bawala($next);
        $debtTouched = (bool) array_intersect(['freight', 'transfer', 'overnight_collection'], $selected);
        $expenseTouched = (bool) array_intersect(Calc::EXPENSE_COLS, $selected);
        if (!$debtTouched) {
            $calculated['debt'] = (float) ($row['bill']['debt'] ?? 0);
        }
        if (!$expenseTouched) {
            $calculated['total_expense'] = (float) ($row['bill']['total_expense'] ?? 0);
            $calculated['custody_remaining'] = (float) ($row['bill']['custody_remaining'] ?? 0);
        }
        if (!$expenseTouched && !in_array('freight', $selected, true)) {
            $calculated['car_net'] = (float) ($row['bill']['car_net'] ?? 0);
        }
        $projectedPaid = round((float) ($row['bill']['payments_total'] ?? $row['bill']['paid'] ?? 0) + $paymentDelta, 2);
        $calculated['paid'] = $projectedPaid;
        $calculated['remaining'] = ($debtTouched || $paymentDelta > 0.005)
            ? round((float) $calculated['debt'] - $projectedPaid, 2)
            : (float) ($row['bill']['remaining'] ?? 0);

        return [
            'fields' => $changes,
            'calculated' => [
                'debt' => (float) $calculated['debt'],
                'paid' => $projectedPaid,
                'remaining' => (float) $calculated['remaining'],
                'total_expense' => (float) $calculated['total_expense'],
                'custody_remaining' => (float) $calculated['custody_remaining'],
                'car_net' => (float) $calculated['car_net'],
            ],
            'payment_delta' => $paymentDelta,
        ];
    }

    /**
     * يستبدل نسخة الخادم بعد فحص البنية، مع الاحتفاظ بالنسخة السابقة.
     *
     * @param array{name?:string,tmp_name?:string,error?:int,size?:int} $file
     */
    public static function storeUpload(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::uploadError($error));
        }

        $original = basename(trim((string) ($file['name'] ?? '')));
        $tmpUpload = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($original === '' || strtolower(pathinfo($original, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new RuntimeException('اختر ملف Excel بصيغة XLSX.');
        }
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new RuntimeException('حجم ملف Excel يجب أن يكون أكبر من صفر وأقل من 12 MB.');
        }
        if ($tmpUpload === '' || !is_uploaded_file($tmpUpload)) {
            throw new RuntimeException('ملف الرفع غير صالح أو انتهت صلاحيته.');
        }

        return self::withLock(function () use ($tmpUpload, $original, $size): array {
            $dir = self::storageDir();
            $tmp = $dir . '/incoming-' . bin2hex(random_bytes(8)) . '.xlsx';
            if (!move_uploaded_file($tmpUpload, $tmp)) {
                throw new RuntimeException('تعذّر نقل ملف Excel إلى مساحة المزامنة المحمية.');
            }
            @chmod($tmp, 0600);

            try {
                self::validateWorkbook($tmp);
                $sha = hash_file('sha256', $tmp);
                if ($sha === false) { throw new RuntimeException('تعذّر حساب بصمة الملف المرفوع.'); }

                $target = $dir . '/' . self::TARGET_FILE;
                $previous = $dir . '/' . self::PREVIOUS_FILE;
                $hadTarget = is_file($target);
                if ($hadTarget && !@rename($target, $previous)) {
                    throw new RuntimeException('تعذّر حفظ نسخة احتياطية من ملف Excel السابق.');
                }
                if (!@rename($tmp, $target)) {
                    if ($hadTarget && is_file($previous)) { @rename($previous, $target); }
                    throw new RuntimeException('تعذّر تثبيت ملف Excel الجديد.');
                }
                @chmod($target, 0600);

                $meta = [
                    'original_name' => $original,
                    'size' => $size,
                    'sha256' => $sha,
                    'uploaded_at' => date('Y-m-d H:i:s'),
                    'uploaded_by' => Auth::user(),
                ];
                self::writeMeta($meta);
                Audit::log('upload', 'excel_sync', substr($sha, 0, 16), null, $meta,
                    'رفع نسخة Excel جديدة للمراجعة', ['source_sha256' => $sha]);
                return $meta;
            } finally {
                if (is_file($tmp)) { @unlink($tmp); }
            }
        });
    }

    /**
     * ينفذ الحقول التي اختارها المستخدم لبوليصة واحدة فقط.
     *
     * @return array{batch_id:string,bill_id:int,fields:array<int,string>,payment_delta:float}
     */
    public static function applySelection(
        int $excelRow,
        int $billId,
        array $selected,
        string $submittedToken
    ): array {
        if ($excelRow <= 1 || $billId <= 0 || !preg_match('/^[a-f0-9]{64}$/', $submittedToken)) {
            throw new RuntimeException('جلسة التأكيد غير صالحة. ارجع للمراجعة واختر التغييرات من جديد.');
        }

        return self::withLock(function () use ($excelRow, $billId, $selected, $submittedToken): array {
            $pdo = Database::conn();
            $ownTransaction = !$pdo->inTransaction();
            if ($ownTransaction) { $pdo->beginTransaction(); }
            try {
                $preview = ExcelBillSync::preview();
                $state = self::sourceState($preview['source'] ?? null);
                if (empty($state['ready'])) {
                    throw new RuntimeException((string) ($state['reason'] ?? 'مصدر Excel غير جاهز للاعتماد.'));
                }
                $reviewed = ExcelBillSync::findPreviewRow($preview, $excelRow);
                if (!$reviewed || (int) ($reviewed['bill']['id'] ?? 0) !== $billId) {
                    throw new RuntimeException('تغيّرت مطابقة صف Excel. ارجع للمراجعة ولا تنفذ اعتمادًا قديمًا.');
                }

                $before = Database::row('SELECT * FROM bawalis WHERE id = ? FOR UPDATE', [$billId]);
                if (!$before) { throw new RuntimeException('البوليصة لم تعد موجودة في النظام.'); }
                Database::all('SELECT id FROM bill_payments WHERE bill_id = ? ORDER BY id FOR UPDATE', [$billId]);
                $before['payments_total'] = (float) Database::scalar(
                    'SELECT COALESCE(SUM(amount),0) FROM bill_payments WHERE bill_id = ?', [$billId]
                );
                $auditBefore = $before;
                unset($auditBefore['payments_total']);
                $fresh = ExcelBillSync::compareAgainstBill($reviewed, $before);
                $currentToken = self::rowToken($fresh, $preview['source']);
                if (!hash_equals($currentToken, $submittedToken)) {
                    throw new RuntimeException('تغيّر ملف Excel أو بيانات البوليصة بعد شاشة التأكيد. لم يتم حفظ أي تعديل.');
                }

                $selection = self::selectionPreview($fresh, $selected);
                $directFields = array_values(array_filter(
                    array_column($selection['fields'], 'key'),
                    static fn(string $field): bool => $field !== 'paid'
                ));
                if (empty($before['is_legacy'])) {
                    if ($guard = Closing::guard((string) ($before['bill_date'] ?? ''))) {
                        throw new RuntimeException($guard);
                    }
                    if (in_array('bill_date', $directFields, true)
                        && ($guard = Closing::guard((string) self::selectedValue($selection['fields'], 'bill_date')))) {
                        throw new RuntimeException($guard);
                    }
                }

                $next = $before;
                foreach ($selection['fields'] as $field) {
                    $key = (string) $field['key'];
                    if ($key !== 'paid') { $next[$key] = $field['excel']; }
                }
                if (in_array('declaration_owner', $directFields, true)) {
                    $owner = PartyMaster::ensure('owner', (string) ($next['declaration_owner'] ?? ''));
                    $next['declaration_owner'] = $owner['name'] ?? null;
                    $next['declaration_owner_id'] = $owner['id'] ?? null;
                }
                if (in_array('company', $directFields, true)) {
                    $company = PartyMaster::ensure('company', (string) ($next['company'] ?? ''));
                    $next['company'] = $company['name'] ?? null;
                    $next['company_id'] = $company['id'] ?? null;
                }

                $calculated = Calc::bawala($next);
                $debtTouched = (bool) array_intersect(
                    ['freight', 'transfer', 'overnight_collection'],
                    $directFields
                );
                $expenseTouched = (bool) array_intersect(Calc::EXPENSE_COLS, $directFields);
                if (!$debtTouched) {
                    $calculated['debt'] = (float) $before['debt'];
                }

                if ($directFields) {
                    $sets = [];
                    $params = [];
                    foreach ($directFields as $field) {
                        $sets[] = "`$field` = ?";
                        $params[] = $calculated[$field] ?? $next[$field] ?? null;
                    }
                    if (in_array('declaration_owner', $directFields, true)) {
                        $sets[] = 'declaration_owner_id = ?';
                        $params[] = $next['declaration_owner_id'];
                    }
                    if (in_array('company', $directFields, true)) {
                        $sets[] = 'company_id = ?';
                        $params[] = $next['company_id'];
                    }
                    $derivedFields = [];
                    if ($debtTouched) { $derivedFields[] = 'debt'; }
                    if ($expenseTouched) {
                        array_push($derivedFields, 'total_expense', 'custody_remaining', 'car_net');
                    } elseif (in_array('freight', $directFields, true)) {
                        $derivedFields[] = 'car_net';
                    }
                    foreach ($derivedFields as $field) {
                        $sets[] = "`$field` = ?";
                        $params[] = $calculated[$field];
                    }
                    $sets[] = 'lock_version = lock_version + 1';
                    $params[] = $billId;
                    Database::exec('UPDATE bawalis SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
                }

                $batch = 'excel-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
                $paymentDelta = round((float) $selection['payment_delta'], 2);
                if ($paymentDelta > 0.005) {
                    $payDate = work_date();
                    $sourceName = (string) ($preview['source']['name'] ?? self::TARGET_FILE);
                    Database::exec(
                        'INSERT INTO bill_payments (bill_id,pay_date,amount,note,is_legacy) VALUES (?,?,?,?,0)',
                        [$billId, $payDate, $paymentDelta, 'مزامنة Excel من ' . $sourceName . ' — ' . $batch]
                    );
                    $paymentId = Database::lastId();
                    Audit::log('create', 'bill_payments', $paymentId, null,
                        Database::row('SELECT * FROM bill_payments WHERE id = ?', [$paymentId]),
                        'إضافة فرق المدفوع المختار من Excel للبوليصة #' . $billId,
                        ['bill_id' => $billId, 'excel_sync_batch' => $batch, 'record_date' => $payDate]);
                }

                if ($debtTouched || $paymentDelta > 0.005) { Bill::recalc($billId); }
                $after = Database::row('SELECT * FROM bawalis WHERE id = ?', [$billId]);
                $fieldKeys = array_column($selection['fields'], 'key');
                Audit::log('update', 'bawalis', $billId, $auditBefore, $after,
                    'اعتماد حقول مختارة من Excel للبوليصة ' . ($before['bill_number'] ?: '#'.$billId),
                    [
                        'excel_sync_batch' => $batch,
                        'source_sha256' => $state['sha256'],
                        'excel_row' => $excelRow,
                        'fields' => $fieldKeys,
                    ]);
                if ($ownTransaction) { $pdo->commit(); }
                return [
                    'batch_id' => $batch,
                    'bill_id' => $billId,
                    'fields' => $fieldKeys,
                    'payment_delta' => $paymentDelta,
                ];
            } catch (\Throwable $e) {
                if ($ownTransaction && $pdo->inTransaction()) { $pdo->rollBack(); }
                throw $e;
            }
        });
    }

    /** الخصم والعهدة والحقول المحسوبة مستبعدة عمدًا. */
    private static function writableFields(): array
    {
        return array_merge([
            'bill_date', 'declaration_owner', 'classification', 'company', 'destination',
            'container_no', 'car_no', 'driver', 'car_owner',
            'freight', 'transfer', 'overnight_collection',
        ], Calc::EXPENSE_COLS);
    }

    private static function selectedValue(array $fields, string $key): mixed
    {
        foreach ($fields as $field) {
            if (($field['key'] ?? '') === $key) { return $field['excel'] ?? null; }
        }
        return null;
    }

    private static function validateWorkbook(string $path): void
    {
        $reader = new XlsxReader($path);
        $rows = $reader->rows(ExcelBillSync::SHEET, 2, 'AF');
        $headers = array_map(static fn($value): string => trim((string) $value), $rows[1] ?? []);
        foreach (self::REQUIRED_HEADERS as $column => $label) {
            if (($headers[$column] ?? '') !== $label) {
                throw new RuntimeException("الملف غير مطابق: العمود $column يجب أن يكون «$label».");
            }
        }
        if (empty($rows[2])) {
            throw new RuntimeException('ورقة «بوالص» لا تحتوي أي صف بيانات.');
        }
    }

    private static function storageDir(): string
    {
        $dir = App::basePath('storage/excel_sync');
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('تعذّر إنشاء مساحة Excel المحمية.');
        }
        @chmod($dir, 0700);
        return $dir;
    }

    private static function readMeta(): ?array
    {
        $path = App::basePath('storage/excel_sync/' . self::META_FILE);
        if (!is_file($path)) { return null; }
        $decoded = json_decode((string) @file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function writeMeta(array $meta): void
    {
        $dir = self::storageDir();
        $tmp = $dir . '/meta-' . bin2hex(random_bytes(6)) . '.json';
        $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException('تعذّر حفظ بيانات نسخة Excel المرفوعة.');
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $dir . '/' . self::META_FILE)) {
            @unlink($tmp);
            throw new RuntimeException('تعذّر تثبيت بيانات نسخة Excel المرفوعة.');
        }
    }

    private static function withLock(callable $callback): mixed
    {
        $lock = fopen(self::storageDir() . '/' . self::LOCK_FILE, 'c+');
        if ($lock === false) { throw new RuntimeException('تعذّر فتح قفل مزامنة Excel.'); }
        try {
            if (!flock($lock, LOCK_EX)) { throw new RuntimeException('تعذّر قفل مزامنة Excel.'); }
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function uploadError(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'ملف Excel أكبر من الحد المسموح على الخادم.',
            UPLOAD_ERR_PARTIAL => 'رفع ملف Excel لم يكتمل. أعد المحاولة.',
            UPLOAD_ERR_NO_FILE => 'اختر ملف Excel أولًا.',
            default => 'تعذّر رفع ملف Excel (رمز ' . $error . ').',
        };
    }
}
