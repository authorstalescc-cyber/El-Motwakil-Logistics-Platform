<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\FormState;
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

        $flt = self::readFilters($ent);
        [$where, $params] = $this->buildWhere($ent, $q, $year, $month, $source, $legacy, $flt);

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

        /* ⚠️ استعلام «السنوات المتاحة» اتشال مع فلتر السنة (2026-08-03) — كان
           بيتنفّذ مع كل فتحة للصفحة وبقى بلا قارئ. مدى التاريخ في الفلترة
           المتقدّمة بيغطّيه. */
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
            'flt'        => $flt,
            'filterSpec' => self::filterSpec($ent),
            'lookups' => Lookup::allGrouped(),
            'ownerOptions' => !empty($ent['has_legacy']) ? Bill::ownerOptions() : [],
            // إعادة تعبئة نافذة لصق الإكسل بعد الفشل — كان اللصق كله بيضيع
            'pasteState' => FormState::consume('bawalis.paste', $ent['route']),
        ]);
    }

    /**
     * وصف الفلاتر المتقدّمة المشتقّ من `Schema` — **المصدر الوحيد** لأي فلتر.
     * بيحدّد لكل حقل نوع الفلتر المناسب من نوع الحقل نفسه، فأي حقل جديد بياخد
     * فلتره تلقائيًّا بلا تعديل هنا.
     *
     * ⚠️ ده كمان **القائمة البيضا**: `applyFilters` بترفض أي مفتاح مش هنا، فاسم
     *    العمود اللي بيتحقن في SQL بييجي من `Schema` لا من المستخدم أبدًا.
     *
     * @return array<string,array{key:string,label:string,kind:string,group:string,lookup?:string}>
     */
    public static function filterSpec(array $ent): array
    {
        $KIND = [
            'billtype'   => 'choice', 'status'  => 'choice', 'select' => 'choice', 'source' => 'choice',
            'text'       => 'text',   'containers' => 'text',
            'money'      => 'range',  'int'     => 'range',  'pct' => 'range',
            'date'       => 'daterange',
        ];
        $out = [];
        foreach ($ent['fields'] as $f) {
            $kind = $KIND[$f['type']] ?? null;
            if ($kind === null) { continue; }                      // rep_payer وغيره: مالوش فلتر
            if ($f['key'] === $ent['date_field']) { continue; }    // ليه from/to الرئيسيين
            $out[$f['key']] = [
                'key'   => $f['key'],
                'label' => $f['label'],
                'kind'  => $kind,
                'group' => $f['group'] ?? 'identity',
                'lookup'=> $f['lookup'] ?? null,
                'type'  => $f['type'],
            ];
        }
        return $out;
    }

    /** قراءة الفلاتر المتقدّمة من الرابط بعد تنقيتها بالقائمة البيضا. */
    public static function readFilters(array $ent): array
    {
        $spec = self::filterSpec($ent);
        $get  = fn(string $k) => is_array($_GET[$k] ?? null) ? $_GET[$k] : [];
        $keep = function (array $raw, array $allow) use ($spec) {
            $o = [];
            foreach ($raw as $k => $v) {
                if (!isset($spec[$k]) || !in_array($spec[$k]['kind'], $allow, true)) { continue; }
                $v = is_array($v) ? '' : trim((string) $v);
                if ($v !== '') { $o[$k] = $v; }
            }
            return $o;
        };
        $d = fn(string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) ($_GET[$k] ?? ''))) ? trim($_GET[$k]) : '';
        return [
            'fs'   => $keep($get('fs'), ['choice']),
            'ft'   => $keep($get('ft'), ['text']),
            'fmin' => $keep($get('fmin'), ['range']),
            'fmax' => $keep($get('fmax'), ['range']),
            'from' => $d('from'),
            'to'   => $d('to'),
        ];
    }

    private function buildWhere(array $ent, string $q, string $year, string $month, string $source, string $legacy = '', array $flt = []): array
    {
        $conds = [];
        $params = [];

        /* ── الفلاتر المتقدّمة ── أسماء الأعمدة من `filterSpec` (Schema) لا من المستخدم */
        if ($flt) {
            $spec = self::filterSpec($ent);
            foreach ($flt['fs'] ?? [] as $k => $v) {
                $conds[] = "`$k` = ?"; $params[] = $v;
            }
            foreach ($flt['ft'] ?? [] as $k => $v) {
                [$sql, $sp] = \App\Models\Search::likeWhere([$k], $v);
                if ($sql !== '') { $conds[] = $sql; $params = array_merge($params, $sp); }
            }
            foreach ($flt['fmin'] ?? [] as $k => $v) {
                if (is_numeric($v)) { $conds[] = "`$k` >= ?"; $params[] = (float) $v; }
            }
            foreach ($flt['fmax'] ?? [] as $k => $v) {
                if (is_numeric($v)) { $conds[] = "`$k` <= ?"; $params[] = (float) $v; }
            }
            unset($spec);
            $df = $ent['date_field'];
            if (($flt['from'] ?? '') !== '') { $conds[] = "`$df` >= ?"; $params[] = $flt['from']; }
            if (($flt['to'] ?? '')   !== '') { $conds[] = "`$df` <= ?"; $params[] = $flt['to']; }
        }
        /* ⚠️ البحث بيمرّ على `Search::likeWhere()` — **نفس محرّك البحث الشامل**
           (تطبيع الألف/التاء المربوطة/الياء/الهمزات/التشكيل/الأرقام العربية +
           تقطيع لكلمات: كل كلمة في أي عمود، وكل الكلمات مطلوبة). ممنوع نرجع
           لـ`LIKE '%$q%'` الخام: كان بيرجّع **صفر** لـ«الإسماعيلية» بينما
           المخزَّن «الاسماعيليه»، و«جلال العاشر» ما كانتش تلاقي حاجة لأنها
           كانت بتتبحث كتلة واحدة جوّه عمود واحد. */
        if ($q !== '' && !empty($ent['search'])) {
            [$sql, $sp] = \App\Models\Search::likeWhere($ent['search'], $q);
            if ($sql !== '') {
                $conds[] = $sql;
                $params = array_merge($params, $sp);
            }
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
        $formId = 'crud.' . $ent['route'] . '.create';
        $state = FormState::consume($formId, $ent['route']);
        if ($state['old']) { $row = array_replace($row, $state['old']); }
        $this->render('crud/form', [
            'title'   => 'إضافة — ' . $ent['title'],
            'ent'     => $ent,
            'row'     => $row,
            'lookups' => Lookup::allGrouped(),
            'action'  => url($ent['route']),
            'isEdit'  => false,
            'errors'  => $state['errors'],
            'formToken' => $state['token'],
            'back'    => $state['back'],
        ]);
    }

    // ------------------------------------------------------------ حفظ جديد
    public function store(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $ent = $this->entity();
        // ⚠️ قبل `collect` — الحارس بيوحّد التهجئة في `$_POST` نفسها
        if ($err = $this->nameGuardError($this->nameGuardMap($ent))) {
            $this->formFailure('crud.' . $ent['route'] . '.create', $ent['route'] . '/new', $_POST, ['_form' => $err], $ent['route']);
        }
        $data = $this->collect($ent);
        $data = Calc::apply($ent['calc'], $data);
        $data = $this->prepareSaveData($data, null);
        if ($err = $this->validate($data, null)) {
            $errors = ['_form' => $err];
            if (str_contains($err, 'رقم البوليصة')) { $errors['bill_number'] = $err; }
            $this->formFailure('crud.' . $ent['route'] . '.create', $ent['route'] . '/new', $_POST, $errors, $ent['route']);
        }
        $this->claimForm($ent['route']);
        $this->insert($ent['table'], $data);
        $id = Database::lastId();
        $this->learnLookups($ent, $data);
        $this->afterSave($id, $data, true);
        $saved = Database::row("SELECT * FROM `{$ent['table']}` WHERE id = ?", [$id]);
        Audit::log('create', $ent['table'], $id, null, $saved, 'إضافة سجل جديد');
        flash('success', 'تمت الإضافة بنجاح.');
        if (!empty($_POST['save_and_new'])) {
            redirect($ent['route'] . '/new?back=' . rawurlencode(safe_back($ent['route'], $_POST['_back'] ?? null)));
        }
        redirect(safe_back($ent['route'], $_POST['_back'] ?? null));
    }

    // ------------------------------------------------------------ تعديل
    public function edit(string $id): void
    {
        $this->requireAuth();
        $ent = $this->entity();
        $row = Database::row("SELECT * FROM `{$ent['table']}` WHERE id = ?", [(int)$id]);
        if (!$row) { $this->notFound('السجل ده مش موجود أو اتمسح.'); }
        $formId = 'crud.' . $ent['route'] . '.edit.' . (int) $id;
        $state = FormState::consume($formId, $ent['route']);
        if ($state['old']) { $row = array_replace($row, $state['old']); }
        $this->render('crud/form', [
            'title'   => 'تعديل — ' . $ent['title'],
            'ent'     => $ent,
            'row'     => $row,
            'lookups' => Lookup::allGrouped(),
            'action'  => url($ent['route'] . '/' . $id),
            'isEdit'  => true,
            'errors'  => $state['errors'],
            'formToken' => $state['token'],
            'back'    => $state['back'],
        ]);
    }

    // ------------------------------------------------------------ تحديث
    public function update(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $ent = $this->entity();
        $before = Database::row("SELECT * FROM `{$ent['table']}` WHERE id = ?", [(int)$id]);
        // ⚠️ قبل `collect` — الحارس بيوحّد التهجئة في `$_POST` نفسها
        if ($err = $this->nameGuardError($this->nameGuardMap($ent), $before ?? [])) {
            $this->formFailure('crud.' . $ent['route'] . '.edit.' . (int) $id,
                $ent['route'] . '/' . (int) $id . '/edit', $_POST, ['_form' => $err], $ent['route']);
        }
        $data = $this->collect($ent);
        $data = Calc::apply($ent['calc'], $data);
        $data = $this->prepareSaveData($data, (int)$id);
        if ($err = $this->validate($data, (int)$id)) {
            $errors = ['_form' => $err];
            if (str_contains($err, 'رقم البوليصة')) { $errors['bill_number'] = $err; }
            $this->formFailure('crud.' . $ent['route'] . '.edit.' . (int) $id,
                $ent['route'] . '/' . (int) $id . '/edit', $_POST, $errors, $ent['route']);
        }
        $this->claimForm($ent['route']);
        $expectedVersion = array_key_exists('lock_version',$before) ? (int)($_POST['lock_version'] ?? 0) : null;
        if (!$this->updateRow($ent['table'], (int)$id, $data, $expectedVersion)) {
            $this->formFailure('crud.' . $ent['route'] . '.edit.' . (int) $id,
                $ent['route'] . '/' . (int) $id . '/edit', $_POST,
                ['_form'=>'عدّل مستخدم آخر هذا السجل. أعد تحميله وراجع التغييرات قبل الحفظ.'], $ent['route']);
        }
        $this->learnLookups($ent, $data);
        $this->afterSave((int)$id, $data, false);
        $after = Database::row("SELECT * FROM `{$ent['table']}` WHERE id = ?", [(int)$id]);
        $canUndo = $ent['table'] !== 'bawalis' || !empty($before['is_legacy']);
        $auditId=Audit::log('update', $ent['table'], (int)$id, $before, $after, 'تعديل بيانات السجل', [], $canUndo);
        if ($canUndo) { $_SESSION['_last_undo_audit']=$auditId; }
        flash('success', 'تم حفظ التعديلات.');
        redirect(safe_back($ent['route'], $_POST['_back'] ?? null));
    }

    // ------------------------------------------------------------ حذف
    public function destroy(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $ent = $this->entity();
        $row = Database::row("SELECT * FROM `{$ent['table']}` WHERE id = ?", [(int)$id]);
        $back = safe_back($ent['route'], $_POST['_back'] ?? null);
        if (!$row) { redirect($back); }
        $this->claimForm($back);
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
            redirect($back);
        }
        flash('success', 'تم نقل السجل إلى سلة المحذوفات ويمكن استرجاعه.');
        redirect($back);
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
            // ⚠️ الدافع بيتخطّى بالكامل قبل migrate_v60 — العمود مش موجود،
            //    فإدخاله في الـINSERT/UPDATE كان هيكسر حفظ أي بوليصة.
            if (($f['type'] ?? '') === 'rep_payer' && !\App\Models\RepBillCustody::ready()) { continue; }
            $key = $f['key'];
            $val = $_POST[$key] ?? null;
            $data[$key] = match ($f['type']) {
                // ⚠️ **الفورم مش مصدر ثقة** — نفس حارس `PermitController::custodyPayerId`:
                //    أي قيمة مش مندوب **نشط** بترجع NULL (خزنة المكتب). لو اتخزّن id
                //    وهمي، العهدة بتتستثنى من الخزنة وما تظهرش في محفظة أي مندوب —
                //    يعني فلوس بتتبخّر بلا أي خطأ ظاهر.
                // ⚠️ ونفس السبب بالظبط بيمنع الوسم على بوليصة **قبل خط الفصل**:
                //    جانب المحفظة بيمرّ على خط الفصل وجانب الخزنة لأ. المصدر
                //    الوحيد للفحص `RepBillCustody::payerAllowed()`.
                'rep_payer' => ((int) $val) > 0
                    && (float) ($_POST['custody'] ?? 0) > 0
                    && \App\Models\RepBillCustody::payerAllowed((string) ($_POST['bill_date'] ?? ''))
                    && Database::scalar(
                        "SELECT id FROM reps WHERE id = ? AND status = 'active'", [(int) $val])
                        ? (int) $val : null,
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

    /**
     * حقول الشاشة اللي أسماؤها بتتحرس — **مشتقّة من `Schema` نفسها** لا مكتوبة بالإيد،
     * فأي حقل جديد مربوط بقائمة منسدلة بياخد الحراسة تلقائيًّا بلا ما حد يفتكر.
     *
     * ⚠️ شاشة `/bawalis` كانت **بلا أي حارس خالص** — بتروح من `collect()` للحفظ على طول
     *    وبعدين `learnLookups()` بتحقن أي غلط في القائمة المنسدلة. وهي أكبر مصدر إدخال
     *    في النظام (١٨٢٢ بوليصة)، فدي كانت أوسع ثغرة.
     */
    protected function nameGuardMap(array $ent): array
    {
        $map = [];
        foreach ($ent['fields'] as $f) {
            if (!empty($f['lookup']) && isset(Lookup::TYPES[$f['lookup']])) {
                $map[$f['key']] = $f['lookup'];
            }
        }
        // حقول اسم حرّة (بلا قائمة منسدلة) لكن ليها نوع اقتراح معروف
        static $extra = ['bawalis' => ['declaration_owner' => 'owner']];
        foreach ($extra[$ent['table']] ?? [] as $field => $type) {
            if (array_key_exists($field, $_POST)) { $map[$field] = $type; }
        }
        return $map;
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
