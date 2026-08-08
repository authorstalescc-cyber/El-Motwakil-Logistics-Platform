<?php
namespace App\Models;

use App\Calc;
use App\Core\App;
use App\Core\Auth;
use App\Core\Database;
use App\Services\XlsxReader;

/**
 * معاينة ربط ورقة «بوالص» بالسجل الحي.
 *
 * هذه الطبقة قراءة فقط: لا UPDATE ولا INSERT ولا حذف دفعات. التنفيذ الفعلي
 * سيأتي كمرحلة منفصلة بعد مراجعة المطابقة والقواعد مع المالك.
 */
class ExcelBillSync
{
    public const SHEET = 'بوالص';

    public const GROUP_LABELS = [
        'identity' => 'بيانات البوليصة والحمولة',
        'vehicle'  => 'بيانات السيارة والسائق',
        'money'    => 'الحساب والتحصيل',
        'office'   => 'مصروفات سيارة المكتب',
    ];

    /** الأعمدة التي اتفق عليها المالك؛ N (الخصم) غير موجودة عمدًا. */
    public const FIELD_LABELS = [
        'declaration_owner' => 'صاحب البيان',
        'bill_date'         => 'التاريخ',
        'classification'    => 'التصنيف',
        'company'           => 'اسم الشركة',
        'destination'       => 'الجهة',
        'container_no'      => 'رقم الحاوية',
        'car_no'            => 'رقم السيارة',
        'driver'            => 'اسم السائق',
        'car_owner'         => 'صاحب السيارة',
        'freight'           => 'النولون',
        'transfer'          => 'التحويلة',
        'overnight_collection' => 'بياتة تحصيل',
        'paid'              => 'المدفوع',
        'driver_share'      => 'دور سواق',
        'assistant_share'   => 'دور تبّاع',
        'overnight_expense' => 'بياتة مصروف',
        'expense'           => 'مصروف',
        'cards'             => 'كرتات',
        'tahia_masr'        => 'تحيا مصر',
        'thabtan'           => 'ثبتان',
        'z_kawtsh'          => 'ظ كاوتش',
        'permits'           => 'تصاريح',
        'kawtsh'            => 'كاوتش',
        'other_expense'     => 'مصاريف أخرى',
        'gas'               => 'سولار / جاز',
    ];

    /** مسار النسخة المحلية؛ وجّه المتغير إلى ملف OneDrive المتزامن ليصبح حيًا. */
    public static function sourcePath(): string
    {
        $env = trim((string) getenv('ALMOTAWAKEL_EXCEL_SYNC_PATH'));
        $configured = App::config('excel_sync');
        $configPath = is_array($configured) ? trim((string) ($configured['path'] ?? '')) : '';
        if ($env !== '') {
            $candidate = $env;
        } elseif ($configPath !== '') {
            $candidate = $configPath;
        } else {
            // الإنتاج يحتفظ بنسخة قراءة فقط داخل storage المحجوب من الويب.
            // التطوير القديم يظل يرجع للملف الموجود بجوار مجلد التطبيق.
            $serverSnapshot = App::basePath('storage/excel_sync/2026622.xlsx');
            $candidate = is_file($serverSnapshot)
                ? $serverSnapshot
                : dirname(App::basePath()) . '/2026622.xlsx';
        }
        $real = realpath($candidate);
        if ($real === false) {
            throw new \RuntimeException(
                'نسخة ملف Excel المحلية غير موجودة. اضبط ALMOTAWAKEL_EXCEL_SYNC_PATH على مسار الملف المتزامن من OneDrive.'
            );
        }
        return $real;
    }

    public static function sourceInfo(): array
    {
        $path = self::sourcePath();
        $size = filesize($path);
        $mtime = filemtime($path);
        return [
            'path'     => $path,
            'name'     => basename($path),
            'size'     => $size === false ? 0 : $size,
            'modified' => $mtime === false ? null : date('Y-m-d H:i:s', $mtime),
            'mode'     => self::sourceMode($path),
        ];
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>,summary:array<string,int>,source:array<string,mixed>}
     */
    public static function preview(): array
    {
        $source = self::sourceInfo();
        $sheetRows = (new XlsxReader($source['path']))->rows(self::SHEET, 10000, 'AI');
        $excelRows = [];
        foreach ($sheetRows as $rowNo => $cells) {
            if ($rowNo === 1) { continue; }
            $number = self::identifier($cells['C'] ?? null);
            if ($number === '') { continue; }
            $excelRows[] = self::mapRow($rowNo, $cells, $number);
        }

        $billsByNumber = self::billsByNumber(array_column($excelRows, 'bill_number'));
        $excelCounts = array_count_values(array_column($excelRows, 'bill_number'));
        $rows = [];
        $summary = [
            'excel_rows' => count($excelRows), 'matched' => 0, 'unmatched' => 0,
            'ambiguous' => 0, 'changed' => 0, 'unchanged' => 0, 'conflicts' => 0,
            'office_rows' => 0, 'warnings' => 0,
        ];

        foreach ($excelRows as $excel) {
            if (($excelCounts[$excel['bill_number']] ?? 0) > 1) {
                $rows[] = $excel + [
                    'match' => 'ambiguous', 'bill' => null, 'changes' => [],
                    'conflicts' => ['رقم البوليصة مكرر في Excel ('
                        . (int) $excelCounts[$excel['bill_number']] . ' صفوف).'],
                    'warnings' => [], 'has_changes' => false,
                ];
                $summary['ambiguous']++;
                $summary['conflicts']++;
                continue;
            }
            $matches = $billsByNumber[$excel['bill_number']] ?? [];
            if (!$matches) {
                $rows[] = $excel + [
                    'match' => 'unmatched', 'bill' => null, 'changes' => [],
                    'conflicts' => [], 'warnings' => ['رقم البوليصة غير موجود في النظام.'],
                    'has_changes' => false,
                ];
                $summary['unmatched']++;
                $summary['warnings']++;
                continue;
            }
            if (count($matches) !== 1) {
                $rows[] = $excel + [
                    'match' => 'ambiguous', 'bill' => null, 'changes' => [],
                    'conflicts' => ['رقم البوليصة مكرر في النظام (' . count($matches) . ' سجلات).'],
                    'warnings' => [], 'has_changes' => false,
                ];
                $summary['ambiguous']++;
                $summary['conflicts']++;
                continue;
            }

            $row = self::compareAgainstBill($excel, $matches[0]);
            $rows[] = $row;
            $summary['matched']++;
            if ($row['has_changes']) { $summary['changed']++; } else { $summary['unchanged']++; }
            if ($row['conflicts']) { $summary['conflicts']++; }
            if ($row['warnings']) { $summary['warnings']++; }
            if ($row['is_office_excel']) { $summary['office_rows']++; }
        }

        return ['rows' => $rows, 'summary' => $summary, 'source' => $source];
    }

    /** تحويل صف Excel إلى الحقول المتفق عليها مع الاحتفاظ بحالة الخلية الفارغة. */
    public static function mapRow(int $rowNo, array $c, ?string $number = null): array
    {
        $number ??= self::identifier($c['C'] ?? null);
        $expenses = [
            'driver_share'      => self::amount($c['S'] ?? null),
            'assistant_share'   => self::amount($c['T'] ?? null),
            'overnight_expense' => self::amount($c['U'] ?? null),
            'expense'           => self::amount($c['V'] ?? null),
            'cards'             => self::amount($c['W'] ?? null),
            'tahia_masr'        => self::amount($c['X'] ?? null),
            'thabtan'           => self::amount($c['Y'] ?? null),
            'z_kawtsh'          => self::amount($c['Z'] ?? null),
            'permits'           => self::amount($c['AA'] ?? null),
            'kawtsh'            => self::amount($c['AB'] ?? null),
            'gas'               => self::amount($c['AF'] ?? null),
        ];
        $otherCols = ['AC', 'AD', 'AE'];
        $hasOther = false;
        $other = 0.0;
        foreach ($otherCols as $col) {
            if (array_key_exists($col, $c) && self::amount($c[$col]) !== null) {
                $hasOther = true;
                $other += self::amount($c[$col]) ?? 0;
            }
        }
        $expenses['other_expense'] = $hasOther ? round($other, 2) : null;

        return [
            'excel_row'        => $rowNo,
            'bill_number'      => $number,
            'bill_date'        => self::excelDate($c['B'] ?? null),
            'declaration_owner'=> self::text($c['A'] ?? null),
            'classification'   => container_norm(self::text($c['D'] ?? null)),
            'company'          => self::text($c['E'] ?? null),
            'destination'      => self::text($c['F'] ?? null),
            'container_no'     => self::text($c['G'] ?? null),
            'car_no'           => self::text($c['H'] ?? null),
            'driver'           => self::text($c['I'] ?? null),
            'car_owner'        => self::text($c['J'] ?? null),
            'freight'          => self::amount($c['K'] ?? null),
            'custody'          => self::amount($c['L'] ?? null),
            'transfer'         => self::amount($c['M'] ?? null),
            'discount'         => self::amount($c['N'] ?? null),
            'overnight_collection' => self::amount($c['O'] ?? null),
            'excel_debt'       => self::amount($c['P'] ?? null),
            'paid_target'      => self::amount($c['Q'] ?? null),
            'excel_total'      => self::amount($c['R'] ?? null),
            'expenses'         => $expenses,
            'excel_total_expense' => self::amount($c['AG'] ?? null),
            'excel_custody_remaining' => self::amount($c['AH'] ?? null),
            'excel_car_net'    => self::amount($c['AI'] ?? null),
        ];
    }

    /**
     * يقارن صف Excel ببوليصة مقفولة/مقروءة من القاعدة.
     *
     * عام عمدًا حتى يعيد منفّذ المزامنة المقارنة بعد قفل السجل مباشرةً؛
     * فلا يعتمد التنفيذ على معاينة قديمة لو عدّل مستخدم آخر البوليصة.
     */
    public static function compareAgainstBill(array $excel, array $bill): array
    {
        $changes = [];
        $warnings = [];
        $conflicts = [];
        $proposed = $bill;

        self::compareText(
            $changes,
            $proposed,
            'declaration_owner',
            self::ownerTarget($excel['declaration_owner']),
            $bill
        );
        if ($excel['bill_date'] !== null
            && !self::sameExcelDate($excel['bill_date'], (string) ($bill['bill_date'] ?? ''))) {
            $changes[] = self::change('bill_date', (string) ($bill['bill_date'] ?? ''), $excel['bill_date'], 'date');
            $proposed['bill_date'] = $excel['bill_date'];
        }
        self::compareText($changes, $proposed, 'classification', $excel['classification'], $bill);
        self::compareText($changes, $proposed, 'company', self::partyTarget('company', $excel['company']), $bill);
        self::compareText($changes, $proposed, 'destination', $excel['destination'], $bill);
        self::compareText($changes, $proposed, 'container_no', $excel['container_no'], $bill);
        self::compareText($changes, $proposed, 'car_no', $excel['car_no'], $bill);
        self::compareText($changes, $proposed, 'driver', $excel['driver'], $bill);
        self::compareText($changes, $proposed, 'car_owner', $excel['car_owner'], $bill);
        self::compareMoney($changes, $proposed, 'freight', $excel['freight'], $bill);
        self::compareMoney($changes, $proposed, 'transfer', $excel['transfer'], $bill);
        self::compareMoney($changes, $proposed, 'overnight_collection', $excel['overnight_collection'], $bill);

        $currentPaid = round((float) ($bill['payments_total'] ?? $bill['paid'] ?? 0), 2);
        $paidTarget = $excel['paid_target'];
        $paymentDelta = null;
        if ($paidTarget !== null) {
            $paymentDelta = round($paidTarget - $currentPaid, 2);
            if ($paymentDelta > 0.005) {
                $changes[] = self::change('paid', $currentPaid, $paidTarget, 'payment_add', $paymentDelta);
            } elseif ($paymentDelta < -0.005) {
                $conflicts[] = 'مدفوع Excel أقل من دفعات النظام بـ' . money(abs($paymentDelta), false)
                    . '؛ لا تُحذف أي دفعة تلقائيًا.';
            }
        }

        $excelCar = self::officeCarFor($excel['car_no']);
        $systemCar = self::officeCarFor((string) ($bill['car_no'] ?? ''));
        $hasExpenseData = self::hasExpenseData($excel['expenses']);
        $officeExpensesAllowed = false;
        if ($excelCar) {
            $officeExpensesAllowed = true;
            if (!$systemCar || (int) $systemCar['id'] !== (int) $excelCar['id']) {
                $warnings[] = 'مصروفات Excel تخص سيارة مكتب مختلفة عن سيارة النظام؛ اختر تغيير رقم السيارة مع المصروفات إن كان ذلك مقصودًا.';
            }
            foreach (Calc::EXPENSE_COLS as $field) {
                self::compareMoney($changes, $proposed, $field, $excel['expenses'][$field] ?? null, $bill);
            }
        } elseif ($hasExpenseData) {
            $warnings[] = 'يوجد مبلغ في أعمدة مصروفات السيارة، لكن اللوحة ليست مسجلة كسيارة مكتب؛ لن تُزامَن المصروفات.';
        }

        $calculated = Calc::bawala($proposed);
        return array_merge($excel, [
            'match'           => 'matched',
            'bill'            => $bill,
            'changes'         => $changes,
            'conflicts'       => $conflicts,
            'warnings'        => $warnings,
            'has_changes'     => (bool) $changes,
            'payment_delta'   => $paymentDelta,
            'is_office_excel' => $excelCar !== null,
            'office_expenses_allowed' => $officeExpensesAllowed,
            'office_car'      => $excelCar,
            'calculated'      => [
                'debt' => (float) ($calculated['debt'] ?? 0),
                'total_expense' => (float) ($calculated['total_expense'] ?? 0),
                'custody_remaining' => (float) ($calculated['custody_remaining'] ?? 0),
                'car_net' => (float) ($calculated['car_net'] ?? 0),
            ],
        ]);
    }

    /**
     * المقارنة الكاملة التي تظهر في شاشة اختيار بوليصة واحدة.
     * لا تعني `changed=true` أن الحقل سيُحفظ؛ الحفظ لا يتم إلا إذا اختاره المستخدم.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function comparisonFields(array $row): array
    {
        $bill = is_array($row['bill'] ?? null) ? $row['bill'] : [];
        if (!$bill) { return []; }

        $paid = round((float) ($bill['payments_total'] ?? $bill['paid'] ?? 0), 2);
        $remaining = round((float) ($bill['remaining'] ?? ((float) ($bill['debt'] ?? 0) - $paid)), 2);
        $lockedReason = null;
        if (empty($bill['is_legacy'])
            && !Auth::freeEditMode()
            && Closing::isLocked((string) ($bill['bill_date'] ?? ''))) {
            $lockedReason = 'تاريخ البوليصة على يوم متقفل؛ افتح الإقفال أو استخدم الدخول الحر قبل التنفيذ.';
        }

        $fields = [
            self::field('bill_type', 'نوع البوليصة', 'identity',
                Bill::TYPE_LABELS[(string) ($bill['bill_type'] ?? '')] ?? ($bill['bill_type'] ?? null),
                null, 'text', false, 'هذا الحقل موجود في النظام فقط.'),
            self::field('bill_date', 'التاريخ', 'identity', $bill['bill_date'] ?? null,
                $row['bill_date'] ?? null, 'date', true),
            self::field('bill_number', 'رقم البوليصة', 'identity', $bill['bill_number'] ?? null,
                $row['bill_number'] ?? null, 'text', false, 'رقم البوليصة هو مفتاح المطابقة ولا يتغير من المزامنة.'),
            self::field('cert_number', 'رقم الشهادة', 'identity', $bill['cert_number'] ?? null,
                null, 'text', false, 'هذا الحقل موجود في النظام فقط.'),
            self::field('permit_no', 'رقم الإذن', 'identity', $bill['permit_no'] ?? null,
                null, 'text', false, 'هذا الحقل موجود في النظام فقط.'),
            self::field('declaration_owner', 'صاحب البيان', 'identity', $bill['declaration_owner'] ?? null,
                self::ownerTarget($row['declaration_owner'] ?? null), 'text', true),
            self::field('classification', 'التصنيف', 'identity', $bill['classification'] ?? null,
                $row['classification'] ?? null, 'text', true),
            self::field('company', 'اسم الشركة', 'identity', $bill['company'] ?? null,
                self::partyTarget('company', $row['company'] ?? null), 'text', true),
            self::field('destination', 'الجهة', 'identity', $bill['destination'] ?? null,
                $row['destination'] ?? null, 'text', true),
            self::field('container_count', 'عدد الحاويات', 'identity', $bill['container_count'] ?? null,
                null, 'number', false, 'هذا الحقل موجود في النظام فقط.'),
            self::field('container_type', 'تصنيف الحاوية', 'identity', $bill['container_type'] ?? null,
                null, 'text', false, 'هذا الحقل موجود في النظام فقط.'),
            self::field('container_no', 'رقم الحاوية', 'identity', $bill['container_no'] ?? null,
                $row['container_no'] ?? null, 'text', true),

            self::field('car_no', 'رقم السيارة', 'vehicle', $bill['car_no'] ?? null,
                $row['car_no'] ?? null, 'text', true),
            self::field('driver', 'اسم السائق', 'vehicle', $bill['driver'] ?? null,
                $row['driver'] ?? null, 'text', true),
            self::field('car_owner', 'صاحب السيارة', 'vehicle', $bill['car_owner'] ?? null,
                $row['car_owner'] ?? null, 'text', true),

            self::field('freight', 'النولون', 'money', $bill['freight'] ?? 0,
                $row['freight'] ?? null, 'money', true),
            self::field('custody', 'العهدة', 'money', $bill['custody'] ?? 0,
                $row['custody'] ?? null, 'money', false, 'العهدة للعرض والمراجعة فقط ولا تتغير من Excel.'),
            self::field('transfer', 'التحويلة', 'money', $bill['transfer'] ?? 0,
                $row['transfer'] ?? null, 'money', true),
            self::field('discount', 'الخصم', 'money', $bill['discount'] ?? 0,
                $row['discount'] ?? null, 'money', false, 'الخصم يديره النظام فقط (الافتراضي 200 جنيه).'),
            self::field('overnight_collection', 'بياتة التحصيل', 'money', $bill['overnight_collection'] ?? 0,
                $row['overnight_collection'] ?? null, 'money', true),
            self::field('excel_debt', 'الباقي في Excel', 'money', $bill['debt'] ?? 0,
                $row['excel_debt'] ?? null, 'money', false, 'رقم مرجعي؛ النظام يعيد حساب المستحق من النولون والعهدة والتحويلة والخصم والبياتة.'),
            self::field('paid', 'المدفوع', 'money', $paid,
                $row['paid_target'] ?? null, 'payment', true),
            self::field('remaining', 'المتبقي في النظام', 'money', $remaining,
                null, 'money', false, 'محسوب تلقائيًا من المستحق ناقص الدفعات.'),
            self::field('pay_status', 'حالة السداد', 'money',
                Bill::STATUS_LABELS[(string) ($bill['pay_status'] ?? '')] ?? ($bill['pay_status'] ?? null),
                null, 'text', false, 'تُحسب تلقائيًا بعد أي تعديل.'),
            self::field('excel_total', 'الإجمالي في Excel', 'money', null,
                $row['excel_total'] ?? null, 'money', false, 'رقم مرجعي من Excel ولا يُكتب مباشرة في النظام.'),
        ];

        $excelOffice = !empty($row['is_office_excel']);
        $carChanged = self::different($bill['car_no'] ?? null, $row['car_no'] ?? null, 'text');
        foreach (Calc::EXPENSE_COLS as $key) {
            $reason = null;
            if (!$excelOffice) {
                $reason = 'المصروفات لا تُزامَن إلا إذا كانت لوحة Excel مسجلة في «سيارات المكتب».';
            }
            $field = self::field(
                $key,
                self::FIELD_LABELS[$key] ?? $key,
                'office',
                $bill[$key] ?? 0,
                $row['expenses'][$key] ?? null,
                'money',
                $excelOffice,
                $reason
            );
            $field['requires_car_no'] = $excelOffice && $carChanged;
            if ($field['requires_car_no'] && $field['changed']) {
                $field['note'] = 'اختيار هذا البند يتطلب اختيار «رقم السيارة» أيضًا لأن سيارة Excel مختلفة.';
            }
            $fields[] = $field;
        }
        $fields[] = self::field('total_expense', 'إجمالي المصروف', 'office',
            $bill['total_expense'] ?? 0, $row['excel_total_expense'] ?? null, 'money', false,
            'محسوب تلقائيًا من بنود مصروفات السيارة.');
        $fields[] = self::field('custody_remaining', 'الباقي من العهدة', 'office',
            $bill['custody_remaining'] ?? 0, $row['excel_custody_remaining'] ?? null, 'money', false,
            'محسوب تلقائيًا ولا يُكتب مباشرة.');
        $fields[] = self::field('car_net', 'صافي السيارة', 'office',
            $bill['car_net'] ?? 0, $row['excel_car_net'] ?? null, 'money', false,
            'محسوب تلقائيًا ولا يُكتب مباشرة.');

        foreach ($fields as &$field) {
            if ($lockedReason !== null && !empty($field['selectable'])) {
                $field['selectable'] = false;
                $field['blocked_reason'] = $lockedReason;
            }
            if ($field['key'] === 'bill_date' && str_starts_with((string) ($field['excel'] ?? ''), '1900-')) {
                $field['selectable'] = false;
                $field['blocked_reason'] = 'تاريخ Excel لا يحتوي سنة كاملة؛ يظهر للمقارنة فقط.';
            }
            if ($field['key'] === 'paid' && $field['has_excel']) {
                $delta = round((float) $field['excel'] - (float) $field['system'], 2);
                $field['delta'] = $delta;
                if ($delta < -0.005) {
                    $field['selectable'] = false;
                    $field['blocked_reason'] = 'Excel أقل من الدفعات المسجلة؛ لا نحذف دفعات تلقائيًا.';
                } elseif ($delta <= 0.005) {
                    $field['selectable'] = false;
                } else {
                    $field['note'] = 'سيُضاف فرق مدفوع قدره ' . money($delta, false) . ' بتاريخ العمل الحالي.';
                }
            }
        }
        unset($field);
        return $fields;
    }

    public static function findPreviewRow(array $preview, int $excelRow): ?array
    {
        foreach ($preview['rows'] ?? [] as $row) {
            if ((int) ($row['excel_row'] ?? 0) === $excelRow) { return $row; }
        }
        return null;
    }

    private static function field(
        string $key,
        string $label,
        string $group,
        mixed $system,
        mixed $excel,
        string $kind,
        bool $writable,
        ?string $blockedReason = null
    ): array {
        $hasExcel = $excel !== null && trim((string) $excel) !== '';
        $changed = $hasExcel && self::different($system, $excel, $kind);
        return [
            'key' => $key,
            'label' => $label,
            'group' => $group,
            'kind' => $kind,
            'system' => $system,
            'excel' => $excel,
            'has_excel' => $hasExcel,
            'changed' => $changed,
            'selectable' => $writable && $changed,
            'blocked_reason' => $blockedReason,
            'requires_car_no' => false,
            'note' => null,
        ];
    }

    private static function different(mixed $system, mixed $excel, string $kind): bool
    {
        if (in_array($kind, ['money', 'payment', 'number'], true)) {
            return abs((float) $system - (float) $excel) > 0.005;
        }
        if ($kind === 'date' && $excel !== null) {
            return !self::sameExcelDate((string) $excel, (string) $system);
        }
        return trim((string) $system) !== trim((string) $excel);
    }

    private static function compareText(array &$changes, array &$proposed, string $field, ?string $excel, array $bill): void
    {
        if ($excel === null || $excel === '') { return; }
        $current = trim((string) ($bill[$field] ?? ''));
        if ($current === $excel) { return; }
        $changes[] = self::change($field, $current, $excel, 'text');
        $proposed[$field] = $excel;
    }

    private static function compareMoney(array &$changes, array &$proposed, string $field, ?float $excel, array $bill): void
    {
        if ($excel === null) { return; }
        $current = round((float) ($bill[$field] ?? 0), 2);
        if (abs($current - $excel) <= 0.005) { return; }
        $changes[] = self::change($field, $current, $excel, 'money');
        $proposed[$field] = $excel;
    }

    private static function change(string $field, mixed $current, mixed $excel, string $kind, ?float $delta = null): array
    {
        return [
            'field' => $field, 'label' => self::FIELD_LABELS[$field] ?? $field,
            'current' => $current, 'excel' => $excel, 'kind' => $kind, 'delta' => $delta,
        ];
    }

    /** @return array<string,array<int,array<string,mixed>>> */
    private static function billsByNumber(array $numbers): array
    {
        $numbers = array_values(array_unique(array_filter(array_map('strval', $numbers))));
        $out = [];
        foreach (array_chunk($numbers, 400) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $rows = Database::all(
                "SELECT b.*,
                        COALESCE((SELECT SUM(bp.amount) FROM bill_payments bp WHERE bp.bill_id=b.id),0) payments_total
                 FROM bawalis b
                 WHERE TRIM(b.bill_number) IN ($marks)",
                $chunk
            );
            foreach ($rows as $row) {
                $key = trim((string) ($row['bill_number'] ?? ''));
                if ($key !== '') { $out[$key][] = $row; }
            }
        }
        return $out;
    }

    private static function amount(mixed $value): ?float
    {
        if ($value === null || $value === '') { return null; }
        if (is_string($value)) {
            $value = strtr(ar2en_digits(trim($value)), ['٬'=>'', '،'=>'', ','=>'', '٫'=>'.']);
        }
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private static function identifier(mixed $value): string
    {
        if ($value === null || $value === '') { return ''; }
        if (is_float($value) && floor($value) === $value) { return (string) (int) $value; }
        return trim(ar2en_digits((string) $value));
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null) { return null; }
        $value = preg_replace('/\s+/u', ' ', trim((string) $value)) ?? trim((string) $value);
        return $value === '' ? null : $value;
    }

    private static function excelDate(mixed $value): ?string
    {
        if ($value === null || $value === '') { return null; }
        if (is_numeric($value)) {
            $days = (int) floor((float) $value);
            if ($days > 0 && $days < 100000) {
                return (new \DateTimeImmutable('1899-12-30'))->modify('+' . $days . ' days')->format('Y-m-d');
            }
        }
        $text = trim(ar2en_digits((string) $value));
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $text);
            if ($date && $date->format($format) === $text) { return $date->format('Y-m-d'); }
        }
        return null;
    }

    private static function hasExpenseData(array $expenses): bool
    {
        foreach ($expenses as $value) {
            if ($value !== null && abs((float) $value) > 0.005) { return true; }
        }
        return false;
    }

    /**
     * الاسم القانوني إن كان نص Excel اسمًا بديلًا مسجّلًا.
     * بدونه كانت المعاينة تقترح تغييرًا في كل قراءة، ثم PartyMaster يعيده
     * للاسم القانوني وقت الحفظ فتظهر نفس الفروق إلى الأبد.
     */
    private static function ownerTarget(?string $name): ?string
    {
        return self::partyTarget('owner', $name);
    }

    private static function partyTarget(string $type, ?string $name): ?string
    {
        if ($name === null || trim($name) === '') { return null; }
        static $cache = [];
        $key = $type . ':' . PartyMaster::normalizeName($name);
        if (!array_key_exists($key, $cache)) {
            $resolved = PartyMaster::resolve($type, $name);
            $cache[$key] = $resolved ? (string) $resolved['name'] : trim($name);
        }
        return $cache[$key];
    }

    /**
     * بعض الصفوف القديمة تحفظ 9/7 كـ 1900-07-09 لأن الخلية بلا سنة.
     * التاريخ ليس حقل مزامنة أصلًا؛ نعدّه مطابقًا إذا وافق الشهر واليوم فقط.
     */
    private static function sameExcelDate(string $excel, string $system): bool
    {
        if ($excel === $system) { return true; }
        return str_starts_with($excel, '1900-')
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $system)
            && substr($excel, 5) === substr($system, 5);
    }

    private static function looksLikeSyncedPath(string $path): bool
    {
        return (bool) preg_match('/(?:onedrive|sharepoint)/iu', str_replace('\\', '/', $path));
    }

    private static function sourceMode(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        if (self::looksLikeSyncedPath($normalized)) { return 'onedrive_local'; }
        if (str_contains($normalized, '/storage/excel_sync/')) { return 'server_snapshot'; }
        return 'local_snapshot';
    }

    /**
     * كاش داخل المعاينة فقط؛ يظل OfficeCar::matchPlate هو مصدر قرار المطابقة،
     * لكننا لا نعيد نفس استعلام سجل السيارات آلاف المرات للوحة المتكررة.
     */
    private static function officeCarFor(?string $plate): ?array
    {
        static $cache = [];
        $key = OfficeCar::plateKey($plate);
        if ($key === '') { return null; }
        if (!array_key_exists($key, $cache)) {
            $cache[$key] = OfficeCar::matchPlate($plate);
        }
        return $cache[$key];
    }
}
