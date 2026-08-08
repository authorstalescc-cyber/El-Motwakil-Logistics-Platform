<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\FormState;
use App\Models\ExcelBillImport;
use App\Models\ExcelBillSync;

/**
 * مراجعة Excel الانتقائية — يرى المستخدم القيمتين ويختار حقولًا بعينها،
 * ثم ينتقل إلى تأكيد مستقل قبل أي كتابة.
 */
class ExcelSyncController extends Controller
{
    private const CONFIRM_TTL = 1800;

    public function index(): void
    {
        $this->requireRole(['owner', 'manager']);

        $filter = (string) ($_GET['filter'] ?? 'changes');
        if (!in_array($filter, ['changes', 'issues', 'office', 'unchanged', 'all'], true)) {
            $filter = 'changes';
        }
        $q = trim((string) ($_GET['q'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 25;
        $error = null;
        $preview = ['rows' => [], 'summary' => [], 'source' => null];
        $sourceState = ['ready' => false, 'meta' => null, 'reason' => null];

        try {
            $preview = ExcelBillSync::preview();
            $sourceState = ExcelBillImport::sourceState($preview['source']);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $filtered = array_values(array_filter($preview['rows'], function (array $row) use ($filter, $q): bool {
            $passes = match ($filter) {
                'issues'    => $row['match'] !== 'matched' || !empty($row['conflicts']) || !empty($row['warnings']),
                'office'    => !empty($row['is_office_excel']),
                'unchanged' => $row['match'] === 'matched' && empty($row['has_changes'])
                    && empty($row['conflicts']) && empty($row['warnings']),
                'all'       => true,
                default     => $row['match'] === 'matched' && !empty($row['has_changes']),
            };
            if (!$passes || $q === '') { return $passes; }
            $haystack = implode(' ', [
                $row['bill_number'] ?? '', $row['declaration_owner'] ?? '', $row['car_no'] ?? '',
                $row['bill']['company'] ?? '', $row['bill']['declaration_owner'] ?? '',
            ]);
            return mb_stripos($haystack, $q, 0, 'UTF-8') !== false;
        }));

        $total = count($filtered);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $rows = array_slice($filtered, ($page - 1) * $perPage, $perPage);

        $this->render('excel_sync/index', [
            'title' => 'مراجعة مزامنة Excel',
            'rows' => $rows,
            'summary' => $preview['summary'],
            'source' => $preview['source'],
            'sourceState' => $sourceState,
            'uploadFormToken' => FormState::token(),
            'error' => $error,
            'filter' => $filter,
            'q' => $q,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    public function review(): void
    {
        $this->requireRole(['owner', 'manager']);
        $excelRow = (int) ($_GET['row'] ?? 0);
        try {
            [$preview, $row, $sourceState] = $this->loadRow($excelRow);
            $this->render('excel_sync/review', [
                'title' => 'مراجعة البوليصة ' . ($row['bill_number'] ?? ''),
                'row' => $row,
                'fields' => ExcelBillSync::comparisonFields($row),
                'groups' => ExcelBillSync::GROUP_LABELS,
                'sourceState' => $sourceState,
                'rowToken' => ExcelBillImport::rowToken($row, $preview['source']),
                'formToken' => FormState::token(),
            ]);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('bawalis/excel-sync');
        }
    }

    /** يحفظ الاختيار في الجلسة وينقل المستخدم إلى شاشة التأكيد فقط. */
    public function confirmSelection(): void
    {
        $this->requireRole(['owner', 'manager']);
        $this->requireCsrf();
        $excelRow = (int) ($_POST['excel_row'] ?? 0);
        $back = 'bawalis/excel-sync/review?row=' . $excelRow;
        $this->claimForm($back);

        try {
            [$preview, $row, $sourceState] = $this->loadRow($excelRow);
            if (empty($sourceState['ready'])) {
                throw new \RuntimeException((string) ($sourceState['reason'] ?? 'حدّث نسخة Excel أولًا.'));
            }
            $submitted = trim((string) ($_POST['row_token'] ?? ''));
            $current = ExcelBillImport::rowToken($row, $preview['source']);
            if (!preg_match('/^[a-f0-9]{64}$/', $submitted) || !hash_equals($current, $submitted)) {
                throw new \RuntimeException('تغيّر ملف Excel أو بيانات البوليصة. راجع المقارنة الجديدة قبل التأكيد.');
            }
            $selected = is_array($_POST['fields'] ?? null) ? $_POST['fields'] : [];
            ExcelBillImport::selectionPreview($row, $selected);

            $token = bin2hex(random_bytes(20));
            $_SESSION['_excel_sync_confirm'][$token] = [
                'at' => time(),
                'excel_row' => $excelRow,
                'bill_id' => (int) $row['bill']['id'],
                'fields' => array_values(array_unique(array_map('strval', $selected))),
                'row_token' => $current,
            ];
            $this->pruneConfirmations();
            flash('success', 'تم تثبيت اختياراتك مؤقتًا. راجع القائمة النهائية قبل التنفيذ.');
            redirect('bawalis/excel-sync/confirm?token=' . rawurlencode($token));
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), $back);
        }
    }

    public function confirm(): void
    {
        $this->requireRole(['owner', 'manager']);
        $token = trim((string) ($_GET['token'] ?? ''));
        try {
            $confirmation = $this->confirmation($token);
            [$preview, $row, $sourceState] = $this->loadRow((int) $confirmation['excel_row']);
            if (empty($sourceState['ready'])) {
                throw new \RuntimeException((string) ($sourceState['reason'] ?? 'حدّث نسخة Excel أولًا.'));
            }
            if ((int) ($row['bill']['id'] ?? 0) !== (int) $confirmation['bill_id']
                || !hash_equals(
                    (string) $confirmation['row_token'],
                    ExcelBillImport::rowToken($row, $preview['source'])
                )) {
                throw new \RuntimeException('تغيّرت بيانات البوليصة بعد الاختيار. ارجع للمقارنة واختر من جديد.');
            }
            $selection = ExcelBillImport::selectionPreview($row, $confirmation['fields']);
            $this->render('excel_sync/confirm', [
                'title' => 'تأكيد تعديلات البوليصة ' . ($row['bill_number'] ?? ''),
                'row' => $row,
                'selection' => $selection,
                'confirmationToken' => $token,
                'formToken' => FormState::token(),
            ]);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('bawalis/excel-sync');
        }
    }

    /** ينفذ فقط محتوى شاشة التأكيد الحالية. */
    public function apply(): void
    {
        $this->requireRole(['owner', 'manager']);
        $this->requireCsrf();
        $token = trim((string) ($_POST['confirmation_token'] ?? ''));
        $confirmation = null;
        try {
            if (empty($_POST['confirmed'])) {
                throw new \RuntimeException('علّم على التأكيد بعد مراجعة قائمة التغييرات النهائية.');
            }
            $confirmation = $this->confirmation($token);
            $this->claimForm('bawalis/excel-sync/confirm?token=' . rawurlencode($token));
            unset($_SESSION['_excel_sync_confirm'][$token]);
            $result = ExcelBillImport::applySelection(
                (int) $confirmation['excel_row'],
                (int) $confirmation['bill_id'],
                $confirmation['fields'],
                (string) $confirmation['row_token']
            );
            $message = 'تم تعديل البوليصة واعتماد ' . count($result['fields']) . ' حقل/بند فقط'
                . ' — رقم العملية ' . $result['batch_id'] . '.';
            if ((float) $result['payment_delta'] > 0) {
                $message .= ' أضيف فرق مدفوع ' . money($result['payment_delta'], false) . '.';
            }
            $this->done($message, 'bawalis/' . (int) $result['bill_id']);
        } catch (\Throwable $e) {
            if ($confirmation !== null) { unset($_SESSION['_excel_sync_confirm'][$token]); }
            $this->fail('لم يتم حفظ أي تعديل: ' . $e->getMessage(), 'bawalis/excel-sync');
        }
    }

    /** رفع أحدث نسخة يدوية من OneDrive إلى مساحة الخادم المحمية. */
    public function upload(): void
    {
        $this->requireRole(['owner', 'manager']);
        $this->requireCsrf();
        $this->claimForm('bawalis/excel-sync');
        try {
            $meta = ExcelBillImport::storeUpload($_FILES['excel_file'] ?? []);
            $this->done(
                'تم تحديث نسخة Excel بنجاح: «' . ($meta['original_name'] ?? 'الملف')
                . '». راجع كل بوليصة واختر حقولها قبل التنفيذ.',
                'bawalis/excel-sync'
            );
        } catch (\Throwable $e) {
            $this->fail('لم يتم تحديث ملف Excel: ' . $e->getMessage(), 'bawalis/excel-sync');
        }
    }

    /** @return array{0:array,1:array,2:array} */
    private function loadRow(int $excelRow): array
    {
        if ($excelRow <= 1) { throw new \RuntimeException('صف Excel المطلوب غير صالح.'); }
        $preview = ExcelBillSync::preview();
        $row = ExcelBillSync::findPreviewRow($preview, $excelRow);
        if (!$row) { throw new \RuntimeException('صف Excel المطلوب لم يعد موجودًا.'); }
        if (($row['match'] ?? '') !== 'matched' || empty($row['bill'])) {
            throw new \RuntimeException('هذا الصف غير مربوط ببوليصة واحدة في النظام، لذلك هو للعرض فقط.');
        }
        return [$preview, $row, ExcelBillImport::sourceState($preview['source'])];
    }

    private function confirmation(string $token): array
    {
        $this->pruneConfirmations();
        if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
            throw new \RuntimeException('رابط التأكيد غير صالح.');
        }
        $row = $_SESSION['_excel_sync_confirm'][$token] ?? null;
        if (!is_array($row) || (int) ($row['at'] ?? 0) < time() - self::CONFIRM_TTL) {
            unset($_SESSION['_excel_sync_confirm'][$token]);
            throw new \RuntimeException('انتهت صلاحية شاشة التأكيد. راجع البوليصة واختر التغييرات من جديد.');
        }
        return $row;
    }

    private function pruneConfirmations(): void
    {
        $rows = $_SESSION['_excel_sync_confirm'] ?? [];
        if (!is_array($rows)) { $rows = []; }
        foreach ($rows as $token => $row) {
            if (!is_array($row) || (int) ($row['at'] ?? 0) < time() - self::CONFIRM_TTL) {
                unset($rows[$token]);
            }
        }
        if (count($rows) > 20) {
            uasort($rows, static fn(array $a, array $b): int => (int) $b['at'] <=> (int) $a['at']);
            $rows = array_slice($rows, 0, 20, true);
        }
        $_SESSION['_excel_sync_confirm'] = $rows;
    }
}
