<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Calc;
use App\Schema;
use App\Models\Lookup;
use App\Models\Bill;
use App\Models\Audit;

/**
 * متحكّم عام لكل شاشات الإدخال، مُغذّى من تعريفات app/Schema.php.
 * ترث منه المتحكّمات الخاصة وتحدّد $key فقط.
 */
abstract class CrudController extends Controller
{
    protected string $key;      // مفتاح الكيان في Schema
    protected int $perPage = 50;

    protected function entity(): array
    {
        return Schema::get($this->key);
    }

    // ------------------------------------------------------------ قائمة
    public function index(): void
    {
        $this->requireAuth();
        $ent = $this->entity();
        $table = $ent['table'];

        $q      = trim($_GET['q'] ?? '');
        $year   = trim($_GET['year'] ?? '');
        $month  = trim($_GET['month'] ?? '');
        $source = trim($_GET['source'] ?? '');
        $legacy = trim($_GET['legacy'] ?? '');   // '' الكل | '0' الحية | '1' المُرحَّلة من الأرشيف
        $sort   = trim($_GET['sort'] ?? '');
        $dir    = strtolower(trim($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $page   = max(1, (int)($_GET['page'] ?? 1));

        [$where, $params] = $this->buildWhere($ent, $q, $year, $month, $source, $legacy);

        $total = (int) Database::scalar("SELECT COUNT(*) FROM `$table` $where", $params);
        $pages = max(1, (int) ceil($total / $this->perPage));
        $page  = min($page, $pages);
        $offset = ($page - 1) * $this->perPage;

        $dateField = $ent['date_field'];
        $sortFields = array_values(array_unique(array_merge(['id', $dateField], array_column($ent['fields'], 'key'))));
        if (!in_array($sort, $sortFields, true)) { $sort = $dateField; }
        $order = "(`$sort` IS NULL), `$sort` " . strtoupper($dir) . ($sort !== 'id' ? ', id DESC' : '');
        $rows = Database::all(
            "SELECT * FROM `$table` $where ORDER BY $order
             LIMIT {$this->perPage} OFFSET {$offset}",
            $params
        );

        // إجماليات كل النتائج (وليس الصفحة فقط)
        $sums = [];
        if (!empty($ent['sum_fields'])) {
            $selects = [];
            foreach ($ent['sum_fields'] as $f) {
                $selects[] = "SUM(`$f`) AS `$f`";
            }
            $sumRow = Database::row("SELECT " . implode(',', $selects) . " FROM `$table` $where", $params);
            $sums = $sumRow ?: [];
        }

        // سنوات متاحة للفلتر
        $years = Database::all("SELECT DISTINCT YEAR(`$dateField`) y FROM `$table`
                                WHERE `$dateField` IS NOT NULL ORDER BY y DESC");

        $this->render('crud/index', [
            'title'   => $ent['title'],
            'ent'     => $ent,
            'rows'    => $rows,
            'sums'    => $sums,
            'total'   => $total,
            'page'    => $page,
            'pages'   => $pages,
            'q'       => $q,
            'year'    => $year,
            'month'   => $month,
            'source'  => $source,
            'legacy'  => $legacy,
            'sort'    => $sort,
            'dir'     => $dir,
            'years'   => array_column($years, 'y'),
            'lookups' => Lookup::allGrouped(),
            'ownerOptions' => !empty($ent['has_legacy']) ? Bill::ownerOptions() : [],
        ]);
    }

    private function buildWhere(array $ent, string $q, string $year, string $month, string $source, string $legacy = ''): array
    {
        $conds = [];
        $params = [];
        if ($q !== '' && !empty($ent['search'])) {
            $ors = [];
            foreach ($ent['search'] as $col) {
                $ors[] = "`$col` LIKE ?";
                $params[] = '%' . $q . '%';
            }
            $conds[] = '(' . implode(' OR ', $ors) . ')';
        }
        $df = $ent['date_field'];
        if ($year !== '') { $conds[] = "YEAR(`$df`) = ?"; $params[] = (int)$year; }
        if ($month !== ''){ $conds[] = "MONTH(`$df`) = ?"; $params[] = (int)$month; }
        if (!empty($ent['has_source']) && $source !== '') {
            $conds[] = "source = ?"; $params[] = $source;
        }
        if (!empty($ent['has_legacy']) && ($legacy === '0' || $legacy === '1')) {
            $conds[] = "is_legacy = ?"; $params[] = (int)$legacy;
        }
        $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
        return [$where, $params];
    }

    // ------------------------------------------------------------ نموذج جديد
    public function create(): void
    {
        $this->requireAuth();
        $ent = $this->entity();
        $row = $this->prepareCreateRow($ent, $this->emptyRow($ent));
        if (!empty($_SESSION['_old']) && is_array($_SESSION['_old'])) { $row = array_replace($row, $_SESSION['_old']); }
        $this->render('crud/form', [
            'title'   => 'إضافة — ' . $ent['title'],
            'ent'     => $ent,
            'row'     => $row,
            'lookups' => Lookup::allGrouped(),
            'action'  => url($ent['route']),
            'isEdit'  => false,
            'errors'  => $_SESSION['_form_errors'] ?? [],
        ]);
        unset($_SESSION['_old'], $_SESSION['_form_errors']);
    }

    // ------------------------------------------------------------ حفظ جديد
    public function store(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $ent = $this->entity();
        $data = $this->collect($ent);
        $data = Calc::apply($ent['calc'], $data);
        $data = $this->prepareSaveData($data, null);
        if ($err = $this->validate($data, null)) {
            $_SESSION['_old'] = $data;
            $_SESSION['_form_errors'] = ['_form' => $err];
            if (str_contains($err, 'رقم البوليصة')) { $_SESSION['_form_errors']['bill_number'] = $err; }
            flash('error', $err);
            redirect($ent['route'] . '/new');
        }
        $this->insert($ent['table'], $data);
        $id = Database::lastId();
        $this->learnLookups($ent, $data);
        $this->afterSave($id, $data, true);
        $saved = Database::row("SELECT * FROM `{$ent['table']}` WHERE id = ?", [$id]);
        Audit::log('create', $ent['table'], $id, null, $saved, 'إضافة سجل جديد');
        unset($_SESSION['_old'], $_SESSION['_form_errors']);
        flash('success', 'تمت الإضافة بنجاح.');
        redirect($ent['route']);
    }

    // ------------------------------------------------------------ تعديل
    public function edit(string $id): void
    {
        $this->requireAuth();
        $ent = $this->entity();
        $row = Database::row("SELECT * FROM `{$ent['table']}` WHERE id = ?", [(int)$id]);
        if (!$row) { redirect($ent['route']); }
        if (!empty($_SESSION['_old']) && is_array($_SESSION['_old'])) { $row = array_replace($row, $_SESSION['_old']); }
        $this->render('crud/form', [
            'title'   => 'تعديل — ' . $ent['title'],
            'ent'     => $ent,
            'row'     => $row,
            'lookups' => Lookup::allGrouped(),
            'action'  => url($ent['route'] . '/' . $id),
            'isEdit'  => true,
            'errors'  => $_SESSION['_form_errors'] ?? [],
        ]);
        unset($_SESSION['_old'], $_SESSION['_form_errors']);
    }

    // ------------------------------------------------------------ تحديث
    public function update(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $ent = $this->entity();
        $before = Database::row("SELECT * FROM `{$ent['table']}` WHERE id = ?", [(int)$id]);
        $data = $this->collect($ent);
        $data = Calc::apply($ent['calc'], $data);
        $data = $this->prepareSaveData($data, (int)$id);
        if ($err = $this->validate($data, (int)$id)) {
            $_SESSION['_old'] = $data;
            $_SESSION['_form_errors'] = ['_form' => $err];
            if (str_contains($err, 'رقم البوليصة')) { $_SESSION['_form_errors']['bill_number'] = $err; }
            flash('error', $err);
            redirect($ent['route'] . '/' . $id . '/edit');
        }
        $expectedVersion = array_key_exists('lock_version',$before) ? (int)($_POST['lock_version'] ?? 0) : null;
        if (!$this->updateRow($ent['table'], (int)$id, $data, $expectedVersion)) {
            $_SESSION['_old']=$data;
            flash('error','عدّل مستخدم آخر هذا السجل. أعد تحميله وراجع التغييرات قبل الحفظ.');
            redirect($ent['route'].'/'.$id.'/edit');
        }
        $this->learnLookups($ent, $data);
        $this->afterSave((int)$id, $data, false);
        $after = Database::row("SELECT * FROM `{$ent['table']}` WHERE id = ?", [(int)$id]);
        $canUndo = $ent['table'] !== 'bawalis' || !empty($before['is_legacy']);
        $auditId=Audit::log('update', $ent['table'], (int)$id, $before, $after, 'تعديل بيانات السجل', [], $canUndo);
        if ($canUndo) { $_SESSION['_last_undo_audit']=$auditId; }
        unset($_SESSION['_old'], $_SESSION['_form_errors']);
        flash('success', 'تم حفظ التعديلات.');
        redirect($ent['route']);
    }

    // ------------------------------------------------------------ حذف
    public function destroy(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $ent = $this->entity();
        $row = Database::row("SELECT * FROM `{$ent['table']}` WHERE id = ?", [(int)$id]);
        if (!$row) { redirect($ent['route']); }
        $related = [];
        if ($ent['table'] === 'bawalis') {
            $related['bill_payments'] = Database::all('SELECT * FROM bill_payments WHERE bill_id=? ORDER BY id', [(int)$id]);
            $related['movement_ids'] = array_column(Database::all('SELECT id FROM movements WHERE bill_id=? ORDER BY id', [(int)$id]), 'id');
        }
        $label = (string)($row['bill_number'] ?? $row['cert_number'] ?? $row['name'] ?? ('#'.$id));
        $pdo = Database::conn();
        $pdo->beginTransaction();
        try {
            Audit::trash($ent['table'], $row, $label, $related);
            Database::exec("DELETE FROM `{$ent['table']}` WHERE id = ?", [(int)$id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            flash('error', 'تعذّر نقل السجل إلى سلة المحذوفات: '.$e->getMessage());
            redirect($ent['route']);
        }
        flash('success', 'تم نقل السجل إلى سلة المحذوفات ويمكن استرجاعه.');
        redirect($ent['route']);
    }

    /** خطّاف يُستدعى بعد الحفظ (تُعيد تعريفه المتحكّمات الخاصة) */
    protected function afterSave(int $id, array $data, bool $isNew): void {}

    /** يسمح للمتحكّم المتخصص بتجهيز قيم نموذج الإضافة (مثل نسخ آخر بوليصة). */
    protected function prepareCreateRow(array $ent, array $row): array { return $row; }

    /** يضيف المتحكم المتخصص معرفات/حقولاً مشتقة قبل التحقق والحفظ. */
    protected function prepareSaveData(array $data, ?int $id): array { return $data; }

    /**
     * خطّاف تحقّق يُستدعى قبل الحفظ (إضافة/تعديل).
     * يُعيد رسالة خطأ لإيقاف الحفظ، أو null للسماح. $id = null عند الإضافة.
     */
    protected function validate(array $data, ?int $id): ?string { return null; }

    // ------------------------------------------------------------ أدوات داخلية
    protected function emptyRow(array $ent): array
    {
        $row = [];
        foreach ($ent['fields'] as $f) {
            // قيمة ابتدائية مخصّصة للحقل (مثل الخصم الافتراضي 200 في البوالص)
            if (array_key_exists('default', $f)) {
                $row[$f['key']] = $f['default'];
                continue;
            }
            $row[$f['key']] = match ($f['type']) {
                'money','int','pct' => 0,
                'billtype'          => 'sader',
                'date'              => work_date(), // افتراضي تاريخ العمل (بدل ترك الحقل فارغاً)
                default             => '',
            };
        }
        if (!empty($ent['has_source'])) {
            $row['source'] = $_GET['source'] ?? 'egycool';
        }
        return $row;
    }

    /** يجمع المدخلات من $_POST حسب تعريف الحقول (يتجاهل المحسوبة) */
    protected function collect(array $ent): array
    {
        $data = [];
        foreach ($ent['fields'] as $f) {
            if (!empty($f['calc'])) { continue; }
            $key = $f['key'];
            $val = $_POST[$key] ?? null;
            $data[$key] = match ($f['type']) {
                'money' => $val === '' || $val === null ? 0 : (float)$val,
                'int'   => $val === '' || $val === null ? 0 : (int)$val,
                'date'  => $val ?: null,
                'source'=> in_array($val, ['egycool','shujaie']) ? $val : 'egycool',
                'billtype' => in_array($val, ['sader','warid']) ? $val : 'sader',
                default => $val !== null && $val !== '' ? trim($val) : null,
            };
        }
        return $data;
    }

    protected function insert(string $table, array $data): void
    {
        $cols = array_keys($data);
        $ph   = array_map(fn($c) => ':' . $c, $cols);
        $sql  = "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $ph) . ")";
        Database::exec($sql, $this->bind($data));
    }

    private function updateRow(string $table, int $id, array $data, ?int $expectedVersion = null): bool
    {
        $sets = array_map(fn($c) => "`$c` = :$c", array_keys($data));
        if ($expectedVersion !== null) { $sets[]='lock_version=lock_version+1'; }
        $sql  = "UPDATE `$table` SET " . implode(',', $sets) . " WHERE id = :id" . ($expectedVersion!==null?' AND lock_version=:expected_version':'');
        $params = $this->bind($data);
        $params[':id'] = $id;
        if($expectedVersion!==null){$params[':expected_version']=$expectedVersion;}
        return Database::exec($sql, $params) === 1;
    }

    private function bind(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) { $out[':' . $k] = $v; }
        return $out;
    }

    /** يضيف القيم الجديدة تلقائياً إلى القوائم المنسدلة */
    protected function learnLookups(array $ent, array $data): void
    {
        foreach ($ent['fields'] as $f) {
            if (($f['type'] ?? '') === 'select' && !empty($f['lookup'])) {
                $v = $data[$f['key']] ?? null;
                if ($v) { Lookup::add($f['lookup'], $v); }
            }
        }
        // اسم العميل → يُضاف لجدول الأرصدة تلقائياً
    }
}
