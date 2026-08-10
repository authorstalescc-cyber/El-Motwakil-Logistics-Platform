<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\FormState;
use App\Core\Database;
use App\Core\PrivateFile;
use App\Core\App;
use App\Calc;
use App\Models\Permit;
use App\Models\PushNotify;
use App\Models\PermitInvoice;
use App\Models\Bill;
use App\Models\Closing;
use App\Models\Lookup;
use App\Models\Audit;
use App\Models\PartyMaster;
use App\Models\DeletionPolicy;
use App\Models\PermitTrack;

/**
 * الإصدارات (إذن للصادر / شهادة للوارد) — أكبر عملية في النظام.
 * صفحة التفاصيل هي «التايم لاين»: بيانات الإذن ← التخصيص ← الحمولة ← البوليصة ←
 * الرسوم ← التعتيق ← الهيئة ← المصاريف الإضافية ← المولدات.
 */
class PermitController extends Controller
{
    /**
     * تفصيل الحاويات حسب النوع من البوالص، مجمّعاً بالاستعلام الواحد.
     * $col: عمود الربط ('permit_id' أو 'movement_id')، $ids: مصفوفة المعرّفات.
     * يُرجع خريطة [id => [ ['type'=>..., 'cnt'=>...], ... ]].
     */
    private static function containerBreakdown(string $col, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) { return []; }
        $col = $col === 'movement_id' ? 'movement_id' : 'permit_id';   // قائمة بيضاء
        $in  = implode(',', $ids);
        $map = [];
        foreach (Database::all(
            "SELECT $col AS gid, container_type AS type, COALESCE(SUM(container_count),0) AS cnt
               FROM bawalis
              WHERE $col IN ($in) AND container_type IS NOT NULL AND container_type <> ''
              GROUP BY $col, container_type
              ORDER BY container_type") as $row) {
            $map[(int)$row['gid']][] = ['type' => $row['type'], 'cnt' => (int)$row['cnt']];
        }
        return $map;
    }

    /**
     * أزرار الأطراف المتكررة (شركة/وكيل) داخل نتيجة معيّنة.
     * تُرجع الأسماء التي لها أكثر من إصدار واحد فقط — لأن الزر لا معنى له لإذن يتيم.
     * $where يبدأ بـ WHERE ويستخدم الاسم المستعار p. العرض فقط، بلا أي أثر على أي حساب.
     */
    private static function partyChips(string $col, string $where, array $params, int $limit = 30): array
    {
        $col = $col === 'client' ? 'client' : 'company';   // قائمة بيضاء
        $rows = Database::all(
            "SELECT p.$col AS name, COUNT(*) AS c
               FROM permits p
               $where AND COALESCE(p.$col,'') <> ''
              GROUP BY p.$col
             HAVING c > 1
              ORDER BY c DESC, p.$col ASC
              LIMIT $limit",
            $params
        );
        return array_map(static fn($r) => ['name' => (string)$r['name'], 'c' => (int)$r['c']], $rows);
    }

    public function index(): void
    {
        $this->requireAuth();
        $v = $_GET['view'] ?? '';
        $mode = in_array($v, ['records','movements'], true) ? $v : 'follow';

        // إحصائيات عامة (تظهر في كل الأوضاع)
        $stats = Database::row(
            "SELECT
                COUNT(*) AS total,
                COALESCE(SUM(status='open'),0)   AS open_cnt,
                COALESCE(SUM(status='closed'),0) AS closed_cnt
             FROM permits"
        ) ?: ['total'=>0,'open_cnt'=>0,'closed_cnt'=>0];

        if ($mode === 'movements') {
            // ملخص الحركات عبر كل الأذونات
            $q = trim($_GET['q'] ?? '');
            $conds = []; $params = [];
            if ($q !== '') {
                $conds[] = '(p.permit_no LIKE ? OR p.company LIKE ? OR p.client LIKE ?)';
                array_push($params, "%$q%", "%$q%", "%$q%");
            }
            $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
            $moves = Database::all(
                "SELECT m.id, m.seq, m.note,
                        p.id AS permit_id, p.permit_no, p.permit_type, p.company, p.client, p.status,
                        (SELECT COUNT(*) FROM bawalis b WHERE b.movement_id = m.id) AS bills,
                        (SELECT COALESCE(SUM(b.container_count),0) FROM bawalis b WHERE b.movement_id = m.id) AS containers,
                        (SELECT COALESCE(SUM(b.custody),0) FROM bawalis b WHERE b.movement_id = m.id) AS custody,
                        (SELECT COALESCE(SUM(b.remaining),0) FROM bawalis b WHERE b.movement_id = m.id) AS remaining,
                        (SELECT COALESCE(SUM(s.amount),0) FROM permit_stages s WHERE s.movement_id = m.id AND ".Permit::expenseSql('s').") AS expense
                 FROM permit_movements m JOIN permits p ON p.id = m.permit_id
                 $where
                 ORDER BY m.id DESC
                 LIMIT 300",
                $params
            );
            // تفصيل الحاويات حسب النوع لكل حركة (النوع + عدده)
            $ctMap = self::containerBreakdown('movement_id', array_map(fn($m)=>(int)$m['id'], $moves));
            foreach ($moves as &$mv) { $mv['ctype_breakdown'] = $ctMap[(int)$mv['id']] ?? []; }
            unset($mv);
            $this->render('permits/index', [
                'title' => 'ملخص الحركات',
                'mode'  => 'movements',
                'moves' => $moves,
                'stats' => $stats,
                'movesCount' => (int) Database::scalar("SELECT COUNT(*) FROM permit_movements"),
                'q' => $q,
            ]);
            return;
        }

        if ($mode === 'follow') {
            // شاشة المتابعة: بحث/تصفية على الخادم ثم صفحة محدودة بدل تحميل كل الملفات.
            $followQ = trim((string)($_GET['q'] ?? ''));
            $followState = in_array($_GET['state'] ?? '', ['warn', 'ok'], true)
                ? (string) $_GET['state'] : 'all';
            $followSort = in_array($_GET['sort'] ?? '', ['old', 'rem-desc'], true)
                ? (string) $_GET['sort'] : 'new';
            // تصفية بطرف واحد (شركة/وكيل) + تجميع البطاقات حسب الشركة — عرض فقط، بلا أي أثر مالي
            $followCo    = trim((string)($_GET['co'] ?? ''));
            $followCl    = trim((string)($_GET['cl'] ?? ''));
            $followGroup = ($_GET['group'] ?? '') === 'co' ? 'co' : '';
            $incompleteSql = "(
                NOT EXISTS (SELECT 1 FROM bawalis bx WHERE bx.permit_id=p.id)
                OR NOT EXISTS (SELECT 1 FROM permit_stages sx WHERE sx.permit_id=p.id)
                OR EXISTS (SELECT 1 FROM permit_stages sp WHERE sp.permit_id=p.id AND sp.status='pending')
            )";
            $baseConds = ["p.status='open'"];
            $baseParams = [];
            if ($followQ !== '') {
                $baseConds[] = '(p.permit_no LIKE ? OR p.company LIKE ? OR p.client LIKE ? OR p.destination LIKE ? OR p.agency LIKE ?)';
                array_push($baseParams, "%$followQ%", "%$followQ%", "%$followQ%", "%$followQ%", "%$followQ%");
            }
            $baseWhere = 'WHERE ' . implode(' AND ', $baseConds);
            $followCounts = Database::row(
                "SELECT COUNT(*) total, COALESCE(SUM($incompleteSql),0) warn
                 FROM permits p $baseWhere",
                $baseParams
            ) ?: ['total'=>0, 'warn'=>0];
            $globalIncomplete = $followQ === ''
                ? (int)$followCounts['warn']
                : (int)Database::scalar("SELECT COALESCE(SUM($incompleteSql),0) FROM permits p WHERE p.status='open'");

            $rowConds = $baseConds;
            $rowParams = $baseParams;
            if ($followState === 'warn') { $rowConds[] = $incompleteSql; }
            if ($followState === 'ok') { $rowConds[] = "NOT $incompleteSql"; }

            // أزرار الأطراف المتكررة: تُحسب قبل تصفية الطرف نفسه حتى يمكن التنقّل بينها
            $chipWhere = 'WHERE ' . implode(' AND ', $rowConds);
            $coChips = self::partyChips('company', $chipWhere, $rowParams);
            $clChips = self::partyChips('client',  $chipWhere, $rowParams);

            if ($followCo !== '') { $rowConds[] = 'p.company = ?'; $rowParams[] = $followCo; }
            if ($followCl !== '') { $rowConds[] = 'p.client = ?';  $rowParams[] = $followCl; }

            $rowWhere = 'WHERE ' . implode(' AND ', $rowConds);
            $followTotal = (int) Database::scalar("SELECT COUNT(*) FROM permits p $rowWhere", $rowParams);
            $perPage = 48;
            $followPages = max(1, (int)ceil($followTotal / $perPage));
            $followPage = min(max(1, (int)($_GET['page'] ?? 1)), $followPages);
            $offset = ($followPage - 1) * $perPage;
            $order = match ($followSort) {
                'old' => 'p.permit_date ASC, p.id ASC',
                'rem-desc' => 'remaining DESC, p.id DESC',
                default => 'p.permit_date DESC, p.id DESC',
            };
            // التجميع حسب الشركة: نرتّب بالشركة أولاً حتى تتجاور بطاقات كل شركة
            if ($followGroup === 'co') { $order = "COALESCE(p.company,'') ASC, $order"; }

            $rows = Database::all(
                "SELECT p.*,
                        (SELECT COUNT(*) FROM bawalis b WHERE b.permit_id = p.id) AS bills,
                        (SELECT COUNT(*) FROM permit_stages s WHERE s.permit_id = p.id) AS stage_count,
                        (SELECT COUNT(*) FROM permit_stages s WHERE s.permit_id = p.id AND s.status='pending') AS pending,
                        (SELECT COALESCE(SUM(b.custody),0) FROM bawalis b WHERE b.permit_id = p.id) AS custody,
                        (SELECT COALESCE(SUM(b.remaining),0) FROM bawalis b WHERE b.permit_id = p.id) AS remaining,
                        (SELECT COALESCE(SUM(s.amount),0) FROM permit_stages s WHERE s.permit_id = p.id AND ".Permit::expenseSql('s').") AS expense,
                        (SELECT COALESCE(SUM(s.amount),0) FROM permit_stages s WHERE s.permit_id = p.id AND ".Permit::revenueSql('s').") AS fees
                 FROM permits p
                 $rowWhere
                 ORDER BY $order
                 LIMIT $perPage OFFSET $offset",
                $rowParams
            );

            // خريطة مراحل كل إذن (لعرض نقاط التقدّم)
            $ids = array_map(fn($r)=>(int)$r['id'], $rows);
            $keysMap = [];
            if ($ids) {
                $in = implode(',', $ids);
                foreach (Database::all("SELECT permit_id, stage_key FROM permit_stages WHERE permit_id IN ($in) GROUP BY permit_id, stage_key") as $k) {
                    $keysMap[(int)$k['permit_id']][$k['stage_key']] = true;
                }
            }

            // تفصيل الحاويات حسب النوع لكل إذن (النوع + عدده) من البوالص
            $ctMap = self::containerBreakdown('permit_id', $ids);

            // الإيصالات المضافة لكل إذن (البيان + الإجمالي) — عرضها على البطاقة بدل تفصيل المصاريف
            $rcMap = [];
            if ($ids) {
                foreach (Database::all(
                    "SELECT permit_id, note, amount FROM permit_stages
                     WHERE permit_id IN ($in) AND stage_key='receipts' AND status <> 'none'
                     ORDER BY entry_date, id"
                ) as $rc) {
                    $rcMap[(int)$rc['permit_id']][] = $rc;
                }
            }

            $active = Permit::activeStages();
            $incomplete = 0;
            foreach ($rows as &$r) {
                $r['ctype_breakdown'] = $ctMap[(int)$r['id']] ?? [];
                $r['receipts'] = $rcMap[(int)$r['id']] ?? [];
                // الإحصاء الذكي: أي الإيصالات المتوقّعة ناقصة (لعرض «بدون هيئة» … على البطاقة)
                // $r = صف الإذن كاملاً (SELECT p.*) فيشمل chk_* اللازمة للقواعد المشروطة
                $r['rcp_missing'] = Permit::receiptGuide($r, $r['receipts'])['missing'];
                $have = $keysMap[(int)$r['id']] ?? [];
                $dots = []; $done = 0; $tot = 0;
                foreach ($active as $k => $m) {
                    if ($k === 'generators') { continue; }   // اختيارية — لا تدخل التقدّم
                    $tot++;
                    $isDone = $k === 'data' ? true
                            : ($k === 'bills' ? ((int)$r['bills'] > 0) : isset($have[$k]));
                    if ($isDone) { $done++; }
                    $dots[] = ['icon' => $m['icon'], 'label' => $m['label'], 'done' => $isDone];
                }
                $r['dots'] = $dots;
                $r['progress'] = $tot ? (int) round($done / $tot * 100) : 0;

                $missing = [];
                if ((int)$r['bills'] === 0)       { $missing[] = 'لا بوالص مرتبطة'; }
                if ((int)$r['pending'] > 0)       { $missing[] = 'بنود ناقصة (' . (int)$r['pending'] . ')'; }
                if ((int)$r['stage_count'] === 0) { $missing[] = 'لا بنود بعد'; }
                $r['missing'] = $missing;
                $r['is_incomplete'] = !empty($missing);
                if ($r['is_incomplete']) { $incomplete++; }
            }
            unset($r);

            $this->render('permits/index', [
                'title'      => 'متابعة الأذونات',
                'mode'       => 'follow',
                'rows'       => $rows,
                'stats'      => $stats,
                'incomplete' => $globalIncomplete,
                'followQ' => $followQ,
                'followState' => $followState,
                'followSort' => $followSort,
                'followCo' => $followCo,
                'followCl' => $followCl,
                'followGroup' => $followGroup,
                'coChips' => $coChips,
                'clChips' => $clChips,
                'followCounts' => [
                    'all' => (int)$followCounts['total'],
                    'warn' => (int)$followCounts['warn'],
                    'ok' => (int)$followCounts['total'] - (int)$followCounts['warn'],
                ],
                'followTotal' => $followTotal,
                'followPage' => $followPage,
                'followPages' => $followPages,
            ]);
            return;
        }

        // وضع السجلات: قائمة كاملة قابلة للبحث
        $q    = trim($_GET['q'] ?? '');
        $type = trim($_GET['type'] ?? '');
        $date = array_key_exists('date', $_GET) ? trim($_GET['date']) : '';
        $st   = trim($_GET['st'] ?? '');

        $conds = []; $params = [];
        if ($q !== '') {
            $conds[] = '(p.permit_no LIKE ? OR p.company LIKE ? OR p.client LIKE ?)';
            array_push($params, "%$q%", "%$q%", "%$q%");
        }
        if ($type !== '') { $conds[] = 'p.permit_type = ?'; $params[] = $type; }
        if ($date !== '') { $conds[] = 'p.permit_date = ?'; $params[] = $date; }
        if ($st === 'open' || $st === 'closed') { $conds[] = 'p.status = ?'; $params[] = $st; }

        // أزرار الأطراف المتكررة: قبل تصفية الطرف نفسه حتى يمكن التنقّل بينها
        $co = trim($_GET['co'] ?? '');
        $cl = trim($_GET['cl'] ?? '');
        $chipWhere  = $conds ? 'WHERE ' . implode(' AND ', $conds) : 'WHERE 1';
        $coChips = self::partyChips('company', $chipWhere, $params);
        $clChips = self::partyChips('client',  $chipWhere, $params);
        if ($co !== '') { $conds[] = 'p.company = ?'; $params[] = $co; }
        if ($cl !== '') { $conds[] = 'p.client = ?';  $params[] = $cl; }

        $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

        $total = (int) Database::scalar("SELECT COUNT(*) FROM permits p $where", $params);
        $perPage = 60;
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
        $offset = ($page - 1) * $perPage;
        $rows = Database::all(
            "SELECT p.*,
                    (SELECT COUNT(*) FROM bawalis b WHERE b.permit_id = p.id) AS bills,
                    (SELECT COALESCE(SUM(b.custody),0) FROM bawalis b WHERE b.permit_id = p.id) AS custody,
                    (SELECT COALESCE(SUM(b.remaining),0) FROM bawalis b WHERE b.permit_id = p.id) AS remaining,
                    (SELECT COALESCE(SUM(s.amount),0) FROM permit_stages s WHERE s.permit_id = p.id AND ".Permit::expenseSql('s').") AS expense,
                    (SELECT COALESCE(SUM(s.amount),0) FROM permit_stages s WHERE s.permit_id = p.id AND ".Permit::revenueSql('s').") AS fees
             FROM permits p
             $where
             ORDER BY p.permit_date DESC, p.id DESC
             LIMIT $perPage OFFSET $offset",
            $params
        );

        // إجمالي كل النتائج (لا الصفحة وحدها) — عرض فقط، ويُتخطّى لو النتيجة ضخمة
        $grand = null;
        if ($total > count($rows) && $total <= 3000) {
            $grand = Database::row(
                "SELECT COALESCE(SUM((SELECT COUNT(*) FROM bawalis b WHERE b.permit_id = p.id)),0) AS bills,
                        COALESCE(SUM(COALESCE(p.containers,0)),0) AS containers,
                        COALESCE(SUM((SELECT COALESCE(SUM(b.custody),0)   FROM bawalis b WHERE b.permit_id = p.id)),0) AS custody,
                        COALESCE(SUM((SELECT COALESCE(SUM(b.remaining),0) FROM bawalis b WHERE b.permit_id = p.id)),0) AS remaining,
                        COALESCE(SUM((SELECT COALESCE(SUM(s.amount),0) FROM permit_stages s WHERE s.permit_id = p.id AND ".Permit::expenseSql('s').")),0) AS expense,
                        COALESCE(SUM((SELECT COALESCE(SUM(s.amount),0) FROM permit_stages s WHERE s.permit_id = p.id AND ".Permit::revenueSql('s').")),0) AS fees
                 FROM permits p $where",
                $params
            );
        }

        $this->render('permits/index', [
            'title' => 'سجلات الأذونات والشهادات',
            'mode'  => 'records',
            'rows'  => $rows,
            'total' => $total,
            'stats' => $stats,
            'page' => $page, 'pages' => $pages,
            'q' => $q, 'type' => $type, 'date' => $date, 'st' => $st,
            'co' => $co, 'cl' => $cl,
            'coChips' => $coChips, 'clChips' => $clChips,
            'grand' => $grand,
            // حالة رحلة الشحنة (v62) — **استعلامان للصفحة كلها** عبر `summaryFor`
            // لا `summary()` في حلقة على ٥٠ صف. وصفي بحت.
            'trackMap' => PermitTrack::summaryFor(array_column($rows, 'id')),
            // اقتراحات أسماء لشريط الإجراءات الجماعية (تغيير الوكيل/الشركة)
            'agentOptions'   => array_column(PartyMaster::all('agent'), 'name'),
            'companyOptions' => array_column(PartyMaster::all('company'), 'name'),
        ]);
    }

    public function create(): void
    {
        $this->requireAuth();
        $this->form(null);
    }

    public function edit(string $id): void
    {
        $this->requireAuth();
        $p = Permit::find((int)$id);
        if (!$p) { $this->notFound('الإصدار ده مش موجود أو اتمسح.'); }
        $this->form($p);
    }

    private function form(?array $p): void
    {
        $formId = $p ? 'permits.edit.' . (int) $p['id'] : 'permits.create';
        $state = FormState::consume($formId, 'permits');
        $defaults = $p ?? ['permit_type'=>'sader','permit_no'=>'','permit_date'=>work_date(),'company'=>'','client'=>'','destination'=>'','shipping_co'=>'','agency'=>'','port'=>'','containers'=>0,'container_type'=>'','inspection_rate'=>null,'guarantee_rate'=>null,'note'=>''];
        if ($state['old']) { $defaults = array_replace($defaults, $state['old']); }
        $this->render('permits/form', [
            'title'   => $p ? 'تعديل بيانات الإصدار' : 'إصدار جديد',
            'p'       => $defaults,
            'action'  => $p ? url('permits/'.$p['id']) : url('permits'),
            'isEdit'  => (bool)$p,
            'lookups' => Lookup::allGrouped(),
            'nameMap' => \App\Models\Party::nameMap(),
            'errors'  => $state['errors'],
            'formToken' => $state['token'],
            'back'    => $state['back'],
        ]);
    }

    public function store(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        if ($err = $this->permitNoError(null)) { $this->formFailure('permits.create', 'permits/new', $_POST, ['permit_no'=>$err], 'permits'); }
        if ($err = $this->nameGuardError(self::NAME_GUARD_PERMIT)) { $this->formFailure('permits.create', 'permits/new', $_POST, ['_form'=>$err], 'permits'); }
        $this->claimForm('permits');
        $id = $this->save(null);
        Permit::createMovement($id);   // أول حركة جاهزة فورًا لإضافة البوالص/البنود
        // إشعار للمكتب والمناديب. بعد الحفظ وخارج أي transaction — لو اتنادى
        // قبل كده وحصل rollback كنا بعتنا خبرًا عن إذن ما اتعملش.
        PushNotify::permitCreated($id, (string) ($_POST['permit_no'] ?? ''),
            (string) ($_POST['company'] ?? ''), (string) ($_POST['client'] ?? ''), (string) \App\Core\Auth::user());
        flash('success', 'تم إنشاء الإصدار — أكمِل بياناته من التايم لاين.');
        redirect('permits/'.$id.'/manage');
    }

    public function update(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $manageId = 'permits.manage.' . (int) $id;
        $formId = ($_POST['_form_id'] ?? '') === $manageId ? $manageId : 'permits.edit.' . (int) $id;
        $formPath = $formId === $manageId ? 'permits/' . (int) $id . '/manage#stage-data' : 'permits/' . (int) $id . '/edit';
        if ($err = $this->permitNoError((int)$id)) { $this->formFailure($formId, $formPath, $_POST, ['permit_no'=>$err], 'permits'); }
        if ($err = $this->nameGuardError(self::NAME_GUARD_PERMIT, Database::row('SELECT * FROM permits WHERE id = ?', [(int)$id]) ?? [])) { $this->formFailure($formId, $formPath, $_POST, ['_form'=>$err], 'permits'); }
        $this->claimForm('permits/' . (int) $id . '/manage#stage-data');
        try {
            $this->save((int)$id);
            flash('success', 'تم حفظ بيانات الإصدار.');
        } catch (\Throwable $e) {
            $this->formFailure($formId, $formPath, $_POST, ['_form'=>$e->getMessage()], 'permits');
        }
        redirect('permits/'.$id.'/manage#stage-data');
    }

    /**
     * طبقة حماية: يُعيد رسالة خطأ إن كان رقم الإذن/الشهادة مكرَّرًا ضمن نفس النوع، وإلا null.
     * تُستدعى قبل الحفظ في store()/update().
     */
    private function permitNoError(?int $id): ?string
    {
        $type = ($_POST['permit_type'] ?? 'sader') === 'warid' ? 'warid' : 'sader';
        $no   = trim($_POST['permit_no'] ?? '');
        if ($no !== '' && Permit::noExists($no, $type, $id)) {
            return Permit::noLabel($type) . ' «' . $no . '» مُستخدَم من قبل — لا يمكن تكراره. '
                 . 'راجع الإصدار الموجود أو اختر رقمًا مختلفًا.';
        }
        return null;
    }

    /**
     * حقول الإذن اللي بتتحرس أسماؤها.
     * ⚠️ الموانئ والتوكيلات وشركات الشحن وتصنيف الحاويات **كانت خارج الحراسة تمامًا**
     *    (والموانئ بالذات هي مصدر «ميناء الإسكندرية / الإسكندرية / اسكندرية»).
     */
    private const NAME_GUARD_PERMIT = [
        'company'        => 'company',
        'client'         => 'client',
        'destination'    => 'destination',
        'port'           => 'port',
        'agency'         => 'agency',
        'shipping_co'    => 'shipping_co',
        'container_type' => 'container_type',
    ];

    private function save(?int $id): int
    {
        $before = $id !== null ? Database::row('SELECT * FROM permits WHERE id = ?', [$id]) : null;
        $type = ($_POST['permit_type'] ?? 'sader') === 'warid' ? 'warid' : 'sader';
        $agent = \App\Models\PartyMaster::ensure('agent', trim($_POST['client'] ?? ''));
        $company = \App\Models\PartyMaster::ensure('company', trim($_POST['company'] ?? ''));
        $data = [
            'permit_type' => $type,
            'permit_no'   => trim($_POST['permit_no'] ?? '') ?: null,
            'permit_date' => $_POST['permit_date'] ?: null,
            'company'     => $company['name'] ?? null,
            'company_id'  => $company['id'] ?? null,
            'client'      => $agent['name'] ?? null,
            'agent_id'    => $agent['id'] ?? null,
            'destination' => trim($_POST['destination'] ?? '') ?: null,
            'shipping_co' => trim($_POST['shipping_co'] ?? '') ?: null,
            'agency'      => trim($_POST['agency'] ?? '') ?: null,
            'port'        => trim($_POST['port'] ?? '') ?: null,
            'containers'  => max(0, (int)($_POST['containers'] ?? 0)),
            'container_type' => trim($_POST['container_type'] ?? '') ?: null,
            // سعر الكشف والضمان لكل إذن — فارغ = NULL (لا قيمة افتراضية)
            'inspection_rate' => ($_POST['inspection_rate'] ?? '') === '' ? null : (float)$_POST['inspection_rate'],
            'guarantee_rate'  => ($_POST['guarantee_rate'] ?? '') === '' ? null : (float)$_POST['guarantee_rate'],
            'note'        => trim($_POST['note'] ?? '') ?: null,
        ];
        if ($id === null) {
            $cols = array_keys($data);
            Database::exec(
                "INSERT INTO permits (`".implode('`,`',$cols)."`) VALUES (".implode(',', array_map(fn($c)=>":$c",$cols)).")",
                array_combine(array_map(fn($c)=>":$c",$cols), array_values($data))
            );
            $id = Database::lastId();
        } else {
            $sets = implode(',', array_map(fn($c)=>"`$c`=:$c", array_keys($data)));
            $params = array_combine(array_map(fn($c)=>":$c",array_keys($data)), array_values($data));
            $params[':id'] = $id;
            $params[':version'] = max(1,(int)($_POST['lock_version'] ?? 1));
            $changed = Database::exec("UPDATE permits SET $sets,lock_version=lock_version+1 WHERE id=:id AND lock_version=:version", $params);
            if ($changed !== 1) { throw new \RuntimeException('عدّل مستخدم آخر هذا الإذن. أعد تحميل الصفحة وراجع التغييرات قبل الحفظ.'); }
        }
        // تعلّم الشركة/العميل/الجهة/بيانات الشحن للقوائم
        if (!empty($data['company']))     { Lookup::add('company', $data['company']); }
        if (!empty($data['client']))      { Lookup::add('client', $data['client']); }
        if (!empty($data['destination'])) { Lookup::add('destination', $data['destination']); }
        if (!empty($data['shipping_co'])) { Lookup::add('shipping_co', $data['shipping_co']); }
        if (!empty($data['agency']))      { Lookup::add('agency', $data['agency']); }
        if (!empty($data['port']))        { Lookup::add('port', $data['port']); }
        if (!empty($data['container_type'])) { Lookup::add('container_type', $data['container_type']); }
        $saved = Database::row('SELECT * FROM permits WHERE id = ?', [$id]);
        $label = Permit::noLabel($type) . ' ' . ($data['permit_no'] ?: '#'.$id);
        Audit::log(
            $before ? 'update' : 'create', 'permits', $id, $before, $saved,
            ($before ? 'تعديل ' : 'إضافة ') . $label,
            ['permit_id'=>$id, 'permit_no'=>$data['permit_no'], 'record_date'=>$data['permit_date']]
        );
        return $id;
    }

    /** يجمّع بيانات الإصدار الكاملة (تُستخدم في العرض والإدارة) */
    private function gather(int $pid): array
    {
        $p = Permit::find($pid);
        if (!$p) { $this->fail('الإصدار ده مش موجود أو اتمسح.', 'permits'); }

        $movements = Permit::movements($pid);
        foreach ($movements as &$mv) {
            $mv['bills']  = Permit::movementBills((int)$mv['id']);
            $mv['stages'] = Permit::movementStagesGrouped((int)$mv['id']);
        }
        unset($mv);

        /* رحلة الشحنة (v62) — وصفي بحت. الأحداث بتتجاب **مرة واحدة** وبتتوزّع
           على ملخّص الإذن وملخّص كل بوليصة، فمفيش استعلام لكل بوليصة. */
        $trackEvents = PermitTrack::events($pid);
        $journey     = PermitTrack::summary($pid, $p['permit_type'], PermitTrack::WHOLE_PERMIT, $trackEvents);
        $journeyBills = PermitTrack::billSummaries($pid, $p['permit_type'], $trackEvents);

        return [
            'p'            => $p,
            'journey'      => $journey,
            'journeyBills' => $journeyBills,
            'trackReady'   => PermitTrack::ready(),
            'movements'    => $movements,
            'permitStages' => Permit::permitStagesGrouped($pid),
            'generators'   => Permit::generators($p['permit_no']),
            'totals'       => Permit::totals($pid),
            /* ⚠️ صفوف نوافذ أرقام الهيرو — **بتتجاب من نفس مصادر الأرقام**:
               `Permit::bills` مصدر `totals['custody'/'debt'/'remaining']`،
               و`Permit::moneyRows` مصدر `totals['expense'/'charged'/'margin']`.
               استعلامان زيادة على الشاشة، والبديل (التجميع من `$movements`)
               بيقلّ عن الإجمالي لأن فيه بنودًا بلا حركة — النافذة تكدب بالسكوت. */
            'billRows'     => Permit::bills($pid),
            'moneyRows'    => Permit::moneyRows($pid),
            'lookups'      => Lookup::allGrouped(),
            // مرشد الإيصالات الذكي (استرشادي — v32): المتوقّع/الناقص حسب النوع والتوكيل والـchecklist
            'rcpGuide'     => Permit::receiptGuideForPermit($pid),
        ];
    }

    /** صفحة عرض الإذن — للقراءة فقط بشكل احترافي (الضغط على الإذن يفتحها) */
    /**
     * شاشة الإذن — **وضع العرض**.
     *
     * ⚠️ من المرحلة ١٣ الشاشتان بقوا **شاشة واحدة بوضعين** (`permits/show`):
     *    شريط الإجراءات والهيرو ومرشد الإيصالات بيترسموا مرة واحدة في الغلاف.
     *    المساران القديمان زي ما هما عشان الروابط المحفوظة تفضل شغّالة.
     */
    public function view(string $id): void
    {
        $this->showPage((int) $id, 'view');
    }

    /** غلاف مشترك للوضعين — نقطة واحدة تجمع بيانات الشاشة وتقرّر عنوانها. */
    private function showPage(int $pid, string $mode): void
    {
        $this->requireAuth();
        $data = $this->gather($pid);
        $data['mode'] = $mode;
        $label = Permit::typeLabel($data['p']['permit_type']) . ' — ' . ($data['p']['permit_no'] ?: '#' . $pid);

        if ($mode === 'manage') {
            $state = FormState::consume('permits.manage.' . $pid, 'permits/' . $pid . '/manage#stage-data');
            if ($state['old']) { $data['p'] = array_replace($data['p'], $state['old']); }
            $data['title']   = 'إدارة: ' . $label;
            $data['nameMap'] = \App\Models\Party::nameMap();
            $data['carMap']  = \App\Models\Party::carOwnerMap();
            $data['declOwners'] = self::declarationOwners();
            $data['receiptMoveTargets'] = Permit::receiptMoveTargets($pid);
            $data['permitFormState'] = $state;
        } else {
            $data['title'] = $label;
            // تفصيل الحاويات (عدد×تصنيف) + تغطية الإيصالات المتوقّعة — للعرض الذكي
            $data['ctBreakdown'] = self::containerBreakdown('permit_id', [$pid])[$pid] ?? [];
            $data['rcpCoverage'] = Permit::receiptCoverageForPermit($pid);
        }

        $this->render('permits/show', $data);
    }

    /**
     * حفظ إجابات checklist مرشد الإيصالات (استرشادي — لا يمسّ أي حساب/تقفيل/عهدة).
     * الحقول: chk_ateeq (port|yard|sacia|none) · chk_load (port|outside) · chk_nabatshia · chk_lamanea
     */
    public function saveChecklist(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $pid = (int)$id;
        if (!Permit::find($pid)) { $this->fail('الإصدار ده مش موجود أو اتمسح.', 'permits'); }
        Permit::saveChecklist($pid, $_POST);
        flash('success', 'تم حفظ بيانات المرشد.');
        redirect('permits/'.$pid.'/manage#rcp-guide');
    }

    // ═══════════════════════════════════ رحلة الشحنة (v62)

    /**
     * تسجيل مرحلة في رحلة الشحنة.
     *
     * ⚠️ **وصفي بحت** — ولا مليم بيتحرّك، فمفيش `Closing::guard` عن قصد
     *    (البند ما بيدخلش أي تقفيل أصلاً — نفس قرار v39/v54).
     *
     * ⚠️ **الفورم مش مصدر ثقة**: `PermitTrack::record` هي اللي بتتحقّق إن
     *    المرحلة من مراحل نوع الإذن ده وإن البوليصة تابعة للإذن ده — الفحص
     *    في الموديل لا هنا، فأي مسار كتابة تاني بياخد نفس الحماية.
     */
    public function trackStage(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $pid = (int) $id;
        $p = Permit::find($pid);
        if (!$p) { $this->fail('الإصدار ده مش موجود أو اتمسح.', 'permits'); }
        if (!PermitTrack::ready()) { $this->fail('وحدة تتبّع الرحلة لسه مش مفعّلة — شغّل migrate_v62.', 'permits/'.$pid); }

        $stage  = (string) ($_POST['stage_key'] ?? '');
        $billId = (int) ($_POST['bill_id'] ?? 0);
        $back   = 'permits/' . $pid . ($this->trackBackMode() === 'manage' ? '/manage' : '');

        /* ⚠️ **قبل** الكتابة: `record` بتعمل `ON DUPLICATE KEY UPDATE` فمستحيل
           نعرف بعدها هل دي مرحلة جديدة ولا تصحيح لواحدة مسجَّلة أصلاً. وإعادة
           تسجيل نفس المرحلة (تعديل ملاحظة أو تاريخ) مش «حالة اتغيّرت» فما
           تستاهلش إشعار — من غير الفحص ده كل حفظة بترنّ من جديد. */
        $isNew = !PermitTrack::hasEvent($pid, $stage, $billId);

        try {
            PermitTrack::record(
                $pid, $stage, $billId, PermitTrack::SRC_OFFICE,
                (string) ($_POST['note'] ?? ''), (string) ($_POST['work_date'] ?? '')
            );
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage(), $back, 'journey');
        }

        $label = PermitTrack::labelOf($stage);
        Audit::log('update', 'permits', $pid, null, ['stage_key' => $stage, 'bill_id' => $billId],
            'تحديث حالة الرحلة إلى «' . $label . '» على ' . Permit::noLabel($p['permit_type']) . ' ' .
            ($p['permit_no'] ?: '#' . $pid), ['permit_id' => $pid]);

        /* ⚠️ الإشعار بعد نجاح العملية وخارج أي transaction (docs/15 §٨.٢).
           كان **ناقص تمامًا** في مسار المكتب: المندوب يحدّث فالمكتب يعرف،
           والمكتب يحدّث فمحدش يعرف. */
        if ($isNew) {
            PushNotify::trackStage([
                'permit_id' => $pid,
                'stage_key' => $stage,
                'bill_id'   => $billId,
                'source'    => PermitTrack::SRC_OFFICE,
                'by'        => (string) (\App\Core\Auth::user() ?? 'المكتب'),
                'actor_key' => (string) (\App\Core\Auth::user() ?? ''),
            ]);
        }

        $this->done('الحالة اتحدّثت: ' . $label, $back, 'journey');
    }

    /** تراجع عن مرحلة مسجَّلة (التايم لاين شهادة على اللي حصل فعلاً). */
    public function trackUndo(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $pid = (int) $id;
        $p = Permit::find($pid);
        if (!$p) { $this->fail('الإصدار ده مش موجود أو اتمسح.', 'permits'); }

        $stage  = (string) ($_POST['stage_key'] ?? '');
        $billId = (int) ($_POST['bill_id'] ?? 0);
        $back   = 'permits/' . $pid . ($this->trackBackMode() === 'manage' ? '/manage' : '');

        if (!PermitTrack::undo($pid, $stage, $billId)) {
            $this->fail('المرحلة دي مش مسجَّلة أصلاً.', $back, 'journey');
        }
        $label = PermitTrack::labelOf($stage);
        Audit::log('delete', 'permits', $pid, ['stage_key' => $stage, 'bill_id' => $billId], null,
            'تراجع عن مرحلة «' . $label . '» في رحلة ' . Permit::noLabel($p['permit_type']) . ' ' .
            ($p['permit_no'] ?: '#' . $pid), ['permit_id' => $pid]);

        /* ⚠️ التراجع بيرجّع الحالة **لورا** — والناس اللي شافت الإشعار الأول
           لازم تعرف إنه اتلغى، وإلا يفضلوا فاكرين الشحنة وصلت. severity=warn. */
        PushNotify::trackStage([
            'permit_id' => $pid,
            'stage_key' => $stage,
            'bill_id'   => $billId,
            'source'    => PermitTrack::SRC_OFFICE,
            'by'        => (string) (\App\Core\Auth::user() ?? 'المكتب'),
            'actor_key' => (string) (\App\Core\Auth::user() ?? ''),
            'undo'      => true,
        ]);

        $this->done('اتشالت مرحلة: ' . $label, $back, 'journey');
    }

    /** وضع الرجوع بعد التحديث (عرض/تعديل) — قائمة بيضا لا نص من الفورم. */
    private function trackBackMode(): string
    {
        return ($_POST['mode'] ?? '') === 'manage' ? 'manage' : 'view';
    }

    /** تأشير/إلغاء «مش محتاجه في الإذن ده» لإيصال متوقّع (استرشادي بحت). */
    public function receiptDismiss(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $pid = (int)$id;
        if (!Permit::find($pid)) { $this->fail('الإصدار ده مش موجود أو اتمسح.', 'permits'); }
        $label = (string)($_POST['label'] ?? '');
        $now = Permit::toggleDismissedReceipt($pid, $label);
        $back = ($_POST['back'] ?? '') === 'view' ? 'permits/'.$pid : 'permits/'.$pid.'/manage#rcp-guide';
        $this->done(($now ? 'اتشال من المطلوب: ' : 'رجع للمطلوب: ') . $label, $back);
    }

    /**
     * يجمّع كل بيانات الإذن اللازمة لورقة المراجعة (يُرجِع null لو الإذن غير موجود).
     * يُشارَك بين الطباعة المفردة والمجمّعة. لا يعيد التوجيه (بعكس gather).
     */
    private function printData(int $pid): ?array
    {
        $p = Permit::find($pid);
        if (!$p) { return null; }
        $movements = Permit::movements($pid);
        foreach ($movements as &$mv) {
            $mv['bills']  = Permit::movementBills((int)$mv['id']);
            $mv['stages'] = Permit::movementStagesGrouped((int)$mv['id']);
        }
        unset($mv);
        $mvIds = array_map(fn($m)=>(int)$m['id'], $movements);
        return [
            'p'            => $p,
            'movements'    => $movements,
            'permitStages' => Permit::permitStagesGrouped($pid),
            'generators'   => Permit::generators($p['permit_no']),
            'totals'       => Permit::totals($pid),
            'ctBreakdown'  => self::containerBreakdown('permit_id', [$pid])[$pid] ?? [],
            'mvCtMap'      => self::containerBreakdown('movement_id', $mvIds),
            'primaryDef'   => Permit::primaryFeesDefault($pid),
            'rcpCoverage'  => Permit::receiptCoverageForPermit($pid),
        ];
    }

    /**
     * كشف مراجعة كامل للإذن — صفحة طباعة A4 مستقلّة (بلا التخطيط العام).
     * تعرض كل بيانات الإذن + كل الحركات والبوالص + كل بنود المراحل (رسوم/إيصالات/مصاريف/مولدات)
     * + الإجماليات، للمراجعة اليدوية والأرشفة الورقية.
     */
    public function printSheet(string $id): void
    {
        $this->requireAuth();
        $pid  = (int)$id;
        $data = $this->printData($pid);
        if (!$data) { $this->notFound('الإصدار ده مش موجود أو اتمسح.'); }
        $data['title'] = 'كشف مراجعة — ' . Permit::typeLabel($data['p']['permit_type']) . ' ' . ($data['p']['permit_no'] ?: '#'.$pid);
        $this->render('permits/print', $data, false);
    }

    /**
     * طباعة مجمّعة لعدة أذونات دفعة واحدة — كل إذن ورقة مستقلّة في صفحة منفصلة.
     * المعرّفات تأتي في ?ids=1,2,3 (أو ids[]=). الأذونات غير الموجودة تُتخطّى بلا خطأ.
     */
    public function printBatch(): void
    {
        $this->requireAuth();
        $raw = $_GET['ids'] ?? '';
        $ids = is_array($raw) ? $raw : explode(',', (string)$raw);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($n) => $n > 0)));
        if (!$ids) {
            flash('error', 'اختر إذنًا واحدًا على الأقل للطباعة.');
            redirect('permits?view=records');
        }
        $ids = array_slice($ids, 0, 60);   // حدّ أمان أعلى لعدد الأوراق في الدفعة الواحدة

        $sheets = [];
        foreach ($ids as $pid) {
            $d = $this->printData($pid);
            if ($d) { $sheets[] = $d; }
        }
        if (!$sheets) {
            flash('error', 'لم يُعثر على الأذونات المحدَّدة.');
            redirect('permits?view=records');
        }

        $this->render('permits/print_batch', [
            'title'  => 'طباعة مجمّعة — ' . count($sheets) . ' إذن',
            'sheets' => $sheets,
        ], false);
    }

    /** صفحة إدارة/تعديل الإذن — التايم لاين التفاعلي (يُفتح بزر «تعديل») */
    /** شاشة الإذن — **وضع التعديل** (نفس الشاشة، الغلاف واحد). */
    public function manage(string $id): void
    {
        $this->showPage((int) $id, 'manage');
    }

    /** أسماء أصحاب البيان المستخدَمة سابقًا (لاقتراحها في نموذج البوليصة) */
    private static function declarationOwners(): array
    {
        return array_column(Database::all(
            "SELECT DISTINCT declaration_owner FROM bawalis
             WHERE declaration_owner IS NOT NULL AND declaration_owner <> ''
             ORDER BY declaration_owner"
        ), 'declaration_owner');
    }

    /**
     * يجمّع بيانات حركة واحدة لرندر قالب `_movement.php` (في ردود الـ AJAX).
     * يُرجِع null لو الحركة غير موجودة.
     */
    private function movementPayload(int $mid): ?array
    {
        $mv = Permit::movement($mid);
        if (!$mv) { return null; }
        $pid = (int)$mv['permit_id'];
        $p   = Permit::find($pid);
        if (!$p) { return null; }
        $mv['bills']  = Permit::movementBills($mid);
        $mv['stages'] = Permit::movementStagesGrouped($mid);
        $ctTotal     = (int)($p['containers'] ?? 0);
        $ctAllocated = (int) Database::scalar('SELECT COALESCE(SUM(container_count),0) FROM bawalis WHERE permit_id = ?', [$pid]);
        return [
            'p'             => $p,
            'mv'            => $mv,
            'mStages'       => Permit::scopedStages('movement'),
            'mvExpenseKeys' => ['receipts'],
            'ctTotal'       => $ctTotal,
            'ctAllocated'   => $ctAllocated,
            'generators'    => Permit::generators($p['permit_no']),
            'primaryDef'    => Permit::primaryFeesDefault($pid),
            'receiptMoveTargets' => Permit::receiptMoveTargets($pid),
        ];
    }

    /**
     * رد AJAX موحّد: يرندر قالب الحركة المحدّثة ويُرجعه JSON.
     * $mid = الحركة المتأثّرة (null = محذوفة). $removedMovementId عند حذف حركة.
     */
    private function ajaxMovement(?int $mid, string $flash = '', ?int $removedMovementId = null): void
    {
        $payload = ['ok' => true, 'flash' => $flash];
        if ($removedMovementId) {
            $payload['removed'] = $removedMovementId;
        } elseif ($mid !== null) {
            $data = $this->movementPayload($mid);
            $payload['html'] = $data ? $this->renderToString('permits/_movement', $data) : '';
            $payload['movementId'] = $mid;
        }
        $this->json($payload);
    }

    // ==================================================================
    //  فوترة العميل الخاصة بالأذونات (النظام الأول)
    // ==================================================================

    /** شاشة إعداد الفاتورة: ملخص الإذن + بنود بيان/كمية/سعر/إجمالي + خيارات الضريبة والإغلاق. */
    public function invoiceForm(string $id): void
    {
        $this->requireAuth();
        $pid  = (int)$id;
        $data = $this->gather($pid);   // p, movements (بـ bills+stages), totals, ...
        $p    = $data['p'];
        $ctMap = self::containerBreakdown('permit_id', [$pid]);
        $agentMaster = !empty($p['agent_id'])
            ? PartyMaster::find('agent', (int)$p['agent_id'])
            : PartyMaster::resolve('agent', (string)($p['client'] ?? ''));
        $state = FormState::consume('permits.invoice.' . $pid, 'permits/' . $pid);
        $voidState = FormState::consume('invoices.void', 'permits/' . $pid . '/invoice');

        $this->render('permits/invoice_form', [
            'title'         => 'إصدار فاتورة — ' . Permit::typeLabel($p['permit_type']) . ' ' . ($p['permit_no'] ?: '#'.$pid),
            'p'             => $p,
            'movements'     => $data['movements'],
            'totals'        => $data['totals'],
            /* ⚠️ نوافذ التفاصيل بتقرا من **نفس مصادر الأرقام المعروضة**:
               `Permit::bills` = مصدر `totals['custody']` و`actualFreight`
               (الاتنين `FROM bawalis WHERE permit_id = ?`)، و`expenseRows`
               بتستخدم `expenseSql` نفسها بتاعة `totals['expense']`.
               تجميع البوالص من الحركات أو البنود من `movementStagesGrouped`
               بيقلّ عن الإجمالي (متحقَّق: بند واحد على الأقل بلا حركة). */
            'bills'         => Permit::bills($pid),
            'expenseRows'   => Permit::expenseRows($pid),
            'ctBreakdown'   => $ctMap[$pid] ?? [],
            'candidates'    => PermitInvoice::candidateItems($pid),
            'actualFreight' => PermitInvoice::actualFreight($pid),
            'vatRate'       => (float) App::setting('vat_rate', 0.14),
            'currency'      => App::setting('currency', 'ج.م'),
            'invoices'      => PermitInvoice::forPermit($pid),
            'paymentTermsDays' => (int)($agentMaster['payment_terms_days'] ?? 30),
            'formState'     => $state,
            'voidState'     => $voidState,
        ]);
    }

    /** حفظ الفاتورة كلقطة ثابتة ثم فتح صفحة الطباعة. */
    public function invoiceStore(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $pid = (int)$id;
        $p = Permit::find($pid);
        if (!$p) { $this->fail('الإصدار ده مش موجود أو اتمسح.', 'permits'); }

        $validKinds = array_keys(PermitInvoice::KINDS);
        $kind = in_array($_POST['doc_kind'] ?? '', $validKinds, true) ? $_POST['doc_kind'] : 'invoice';
        // الضريبة تُقبل فقط للفاتورة (كشف الحساب والمطالبة بالإيصالات لا يخضعان للضريبة أبداً)
        $taxable = ($kind === 'invoice' && ($_POST['taxable'] ?? '') === '1') ? 1 : 0;

        // رقم مسلسل خاص بالعميل (اختياري) — يجب ألا يتكرّر
        $serial = trim($_POST['serial_no'] ?? '');
        if ($serial !== '' && PermitInvoice::serialExists($serial)) {
            $this->formFailure('permits.invoice.' . $pid, 'permits/'.$pid.'/invoice', $_POST,
                ['serial_no' => 'الرقم المسلسل «' . $serial . '» مستخدَم من قبل — اختر رقماً آخر.'],
                'permits/' . $pid);
        }

        if (!self::invoiceRowsFromPost()) {
            $this->formFailure('permits.invoice.' . $pid, 'permits/'.$pid.'/invoice', $_POST,
                ['items' => 'أضف بندًا واحدًا على الأقل بقيمة أو بيان قبل الإصدار.'], 'permits/' . $pid);
        }
        $this->claimForm('permits/' . $pid . '/invoice');

        $pdo = Database::conn();
        $pdo->beginTransaction();
        try {

        // توكيل ملاحي / ميناء: لو ناقصان في الإذن وأدخلهما المستخدم هنا → سجّلهما في الإذن نفسه
        $agencyIn = trim($_POST['agency'] ?? '');
        $portIn   = trim($_POST['port'] ?? '');
        $agency   = ($p['agency'] ?? '') !== '' ? $p['agency'] : ($agencyIn ?: null);
        $port     = ($p['port'] ?? '') !== '' ? $p['port']   : ($portIn   ?: null);
        if (($p['agency'] ?? '') === '' && $agencyIn !== '') {
            Database::exec('UPDATE permits SET agency = ? WHERE id = ?', [$agencyIn, $pid]);
        }
        if (($p['port'] ?? '') === '' && $portIn !== '') {
            Database::exec('UPDATE permits SET port = ? WHERE id = ?', [$portIn, $pid]);
        }

        // بنود الفاتورة: بيان + كمية + سعر (+ علم النولون لحساب الضريبة عليه فقط)
        // ⚠️⚠️ قرار المالك (2026-08-01): **البند اللي يتكتب في الفاتورة يفضل في الفاتورة وبس**
        //    — ممنوع الإصدار يضيف أي بند جديد على الإذن. الإصدار بقى **لقطة** لا نقطة إدخال:
        //    بند جديد هنا = سطر في المستند المطبوع فقط، بلا `permit_stages` وبلا أثر على
        //    المصروفات ولا الخزنة ولا التقفيل. الإدخال على الإذن مكانه شاشة الإذن نفسها.
        //    المزامنة العكسية الباقية = **تحديث بند قائم** (بيانه/كميته/سعر العميل) لا إنشاء.
        $rows  = self::invoiceRowsFromPost();
        $items = [];

        foreach ($rows as $r) {
            $label     = $r['label'];
            $qty       = $r['qty'];
            $price     = $r['price'];   // سعر الوحدة (سعر العميل)
            $src       = $r['src'];
            $isFreight = $r['freight'];

            $items[] = [
                'label'   => $label,
                'qty'     => $qty,
                'price'   => $price,
                'freight' => $isFreight,
                'source'  => $src,
            ];

            // ===== تحديث بند قائم فقط (النولون مستثنى — رقم فاتورة مستقل) =====
            if ($isFreight) { continue; }
            $clientTotal = $r['total'];
            if (strncmp($src, 'stage:', 6) === 0) {
                // تعديل بند قائم: نحدّث البيان/الكمية/سعر العميل فقط (لا نمسّ المبلغ النقدي حفاظاً على الإقفال)
                $sid = (int) substr($src, 6);
                if ($sid > 0) {
                    Database::exec(
                        'UPDATE permit_stages SET note = ?, qty = ?, actual_amount = ? WHERE id = ? AND permit_id = ?',
                        [$label ?: null, (int)$qty, $clientTotal, $sid, $pid]
                    );
                }
            }
            // ⚠️ `src === 'new'` (بند كتبه المالك في الفاتورة) و`primary_auto` (رسوم أولية
            //    معروضة) **الاتنين ما بيتسجّلوش في الإذن إطلاقًا** — يظهروا في المستند وبس.
        }

        $invId = PermitInvoice::create(self::invoicePayload($p, $items, $agency, $port));

        // قرار المالك: أي إذن يصدر له مستند مالي يُغلَق تلقائياً — لا خيار ولا استثناء.
        $msg = 'تم إصدار المستند وحفظه ✓';
        if (($p['status'] ?? 'open') !== 'closed') {
            Database::exec('UPDATE permits SET status = ? WHERE id = ?', ['closed', $pid]);
            Audit::log('update', 'permits', $pid, ['status' => $p['status'] ?? 'open'], ['status' => 'closed'],
                'إغلاق تلقائي للإذن عند إصدار مستند مالي — ' . Permit::noLabel($p['permit_type']) . ' ' . ($p['permit_no'] ?: '#'.$pid),
                ['permit_id' => $pid, 'invoice_id' => $invId]);
            $msg .= ' وتم إغلاق الإذن تلقائياً.';
        }
        $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $duplicate = (string)$e->getCode() === '23000';
            $this->formFailure('permits.invoice.' . $pid, 'permits/'.$pid.'/invoice', $_POST,
                ['_form' => $duplicate
                    ? 'تعذّر الإصدار لأن رقماً فريداً استُخدم بالتزامن. راجع رقم المسلسل.'
                    : 'تعذّر إصدار المستند، ولم تُحفظ أي تعديلات جزئية. حاول مرة أخرى.'],
                'permits/' . $pid);
        }
        // ⚠️ الإشعار **بعد** `commit` وخارج أي transaction (docs/15 §٨.٢):
        //    جوّه المعاملة، أي rollback بعده = خبر عن حاجة ما حصلتش.
        $issued = PermitInvoice::find($invId);
        PushNotify::invoiceIssued(
            $invId,
            PermitInvoice::KINDS[$issued['doc_kind'] ?? 'invoice'] ?? 'مستند',
            (string) ($p['client'] ?? ''),
            (float) ($issued['total'] ?? 0),
            (string) (\App\Core\Auth::user() ?? 'المكتب')
        );
        flash('success', $msg);
        redirect('permits/invoice/'.$invId);
    }

    /**
     * معاينة الفاتورة قبل إصدارها: يعرض نفس صفحة الطباعة A4 من مدخلات الفورم
     * **بلا أي حفظ أو أثر جانبي** (لا مزامنة مع الإذن، لا إغلاق، لا إدراج في قاعدة البيانات).
     * من المعاينة يؤكّد المستخدم الإصدار (POST إلى invoiceStore) أو يرجع للتعديل.
     */
    public function invoicePreview(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $pid = (int)$id;
        $p = Permit::find($pid);
        if (!$p) { $this->fail('الإصدار ده مش موجود أو اتمسح.', 'permits'); }

        $serial = trim($_POST['serial_no'] ?? '');
        if ($serial !== '' && PermitInvoice::serialExists($serial)) {
            $this->formFailure('permits.invoice.' . $pid, 'permits/'.$pid.'/invoice', $_POST,
                ['serial_no' => 'الرقم المسلسل «' . $serial . '» مستخدَم من قبل — اختر رقماً آخر.'],
                'permits/' . $pid);
        }
        if (!self::invoiceRowsFromPost()) {
            $this->formFailure('permits.invoice.' . $pid, 'permits/'.$pid.'/invoice', $_POST,
                ['items' => 'أضف بندًا واحدًا على الأقل بقيمة أو بيان قبل المعاينة.'], 'permits/' . $pid);
        }

        // نفس تحليل البنود المستخدَم في الإصدار، لكن بلا أي كتابة (نبني البنود فقط)
        $items = [];
        foreach (self::invoiceRowsFromPost() as $r) {
            $items[] = ['label' => $r['label'], 'qty' => $r['qty'], 'price' => $r['price'], 'freight' => $r['freight']];
        }
        // التوكيل الملاحي/الميناء للعرض فقط (لا يُحفَظ في الإذن أثناء المعاينة)
        $agencyIn = trim($_POST['agency'] ?? '');
        $portIn   = trim($_POST['port'] ?? '');
        $agency   = ($p['agency'] ?? '') !== '' ? $p['agency'] : ($agencyIn ?: null);
        $port     = ($p['port'] ?? '') !== '' ? $p['port']   : ($portIn   ?: null);

        $inv = PermitInvoice::preview(self::invoicePayload($p, $items, $agency, $port));

        $this->render('permits/invoice_print', [
            'title'    => 'معاينة — ' . PermitInvoice::kindLabel($inv['doc_kind']),
            'inv'      => $inv,
            'preview'  => true,          // وضع المعاينة: شريط تأكيد/تعديل بدل الطباعة/الرجوع
            'post'     => $_POST,        // لإعادة إرسال نفس القيم عند التأكيد
            'storeUrl' => url('permits/' . $pid . '/invoice'),
        ], false);
    }

    /**
     * يبني بنود الفاتورة من مدخلات POST (بلا أي كتابة في القاعدة).
     * كل عنصر: [label, qty, price(سعر الوحدة), freight(bool), src, total].
     */
    private static function invoiceRowsFromPost(): array
    {
        $rows   = [];
        $labels = $_POST['item_label'] ?? [];
        $qtys   = $_POST['item_qty']   ?? [];
        $prices = $_POST['item_price'] ?? [];
        $srcs   = $_POST['item_src']   ?? [];   // freight | stage:<id> | primary_auto | new
        foreach ($labels as $i => $lbl) {
            $label     = trim((string)$lbl);
            $qty       = max(1, (float)($qtys[$i] ?? 1));
            $price     = (float)($prices[$i] ?? 0);
            $src       = (string)($srcs[$i] ?? 'new');
            $isFreight = ($src === 'freight');
            if ($label === '' && $price == 0.0) { continue; }   // تجاهل الصفوف الفارغة
            $rows[] = [
                'label'   => $label,
                'qty'     => $qty,
                'price'   => $price,
                'freight' => $isFreight,
                'src'     => $src,
                'total'   => round($qty * $price, 2),
            ];
        }
        return $rows;
    }

    /** يجمع بيانات الفاتورة المشتركة بين المعاينة والإصدار من POST + الإذن + البنود. */
    private static function invoicePayload(array $p, array $items, ?string $agency, ?string $port): array
    {
        $validKinds = array_keys(PermitInvoice::KINDS);
        $kind    = in_array($_POST['doc_kind'] ?? '', $validKinds, true) ? $_POST['doc_kind'] : 'invoice';
        $taxable = ($kind === 'invoice' && ($_POST['taxable'] ?? '') === '1') ? 1 : 0;
        return [
            'permit_id'      => (int)$p['id'],
            'doc_kind'       => $kind,
            'taxable'        => $taxable,
            'internal_no'    => $_POST['internal_no'] ?? '',
            'serial_no'      => trim($_POST['serial_no'] ?? ''),
            'custom_title'   => $_POST['custom_title'] ?? '',
            'invoice_date'   => $_POST['invoice_date'] ?: work_date(),
            'due_date'       => $_POST['due_date'] ?? '',
            'client'         => $p['client'],
            // اسم العميل للطباعة فقط (لا يمسّ client الحقيقي المربوط بالإذن)
            'display_client' => $_POST['display_client'] ?? '',
            'company'        => $p['company'],
            'destination'    => $p['destination'],
            'permit_no'      => $p['permit_no'],
            'permit_type'    => $p['permit_type'],
            'agency'         => $agency,
            'port'           => $port,
            'containers'     => (int)($p['containers'] ?? 0),
            'vat_rate'       => (float) App::setting('vat_rate', 0.14),
            'note'           => $_POST['note'] ?? '',
            'items'          => $items,
        ];
    }

    /** صفحة الطباعة A4 (مستقلة بدون التخطيط العام). */
    public function invoicePrint(string $invId): void
    {
        $this->requireAuth();
        $inv = PermitInvoice::find((int)$invId);
        if (!$inv) { $this->notFound('المستند ده مش موجود أو اتمسح.'); }
        $this->render('permits/invoice_print', [
            'title' => PermitInvoice::kindLabel($inv['doc_kind']) . ' ' . ($inv['doc_no'] ?: '#'.$inv['id']),
            'inv'   => $inv,
        ], false);
    }

    /** إلغاء فاتورة محفوظة مع بقاء المستند ورقمه في السجل. */
    public function invoiceDelete(string $invId): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $inv = PermitInvoice::find((int) $invId);
        $pid = (int) ($inv['permit_id'] ?? 0);
        $fallback = $pid ? 'permits/' . $pid . '/invoice' : 'invoices';
        $back = safe_back($fallback, $_POST['_back'] ?? ($_POST['back'] ?? null));
        if (trim($_POST['reason'] ?? '') === '') {
            $this->formFailure('invoices.void', $back, $_POST,
                ['reason' => 'اكتب سبب إلغاء المستند.'], $fallback);
        }
        $this->claimForm($back);
        try {
            $pid = PermitInvoice::void(
                (int)$invId,
                trim($_POST['reason'] ?? ''),
                isset($_POST['lock_version']) ? (int)$_POST['lock_version'] : null
            );
            flash('success', 'تم إلغاء المستند مع الاحتفاظ برقمه وسجله.');
        } catch (\Throwable $e) {
            $pid = (int)(PermitInvoice::find((int)$invId)['permit_id'] ?? 0);
            flash('error', $e->getMessage());
        }
        // العودة للمكان الذي جاء منه الحذف (back آمن داخلي)، وإلا شاشة فاتورة الإذن.
        redirect($back);
    }

    /* `safeBack()` القديمة اتشالت في المرحلة ٢: كانت **مُنضِّف خامس** مكتوب
       بالإيد و**بصفر مستدعين** — كود ميت. وكمان كان أضيق من اللازم: الـregex
       بتاعها بترفض أي حرف عربي، فمسار رجوع فيه استعلام عربي كان بيترفض.
       المُنضِّف الوحيد المعتمد دلوقتي هو `safe_back()` في helpers.php. */

    /** إنشاء حركة جديدة داخل الإذن */
    public function createMovement(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $pid = (int)$id;
        $permit = Permit::find($pid);
        if (!$permit) { $this->fail('الإصدار ده مش موجود أو اتمسح.', 'permits'); }
        [$ctCount, $ctType] = $this->movementContainerInput();
        $this->claimForm('permits/'.$pid.'/manage');
        $mid = Permit::createMovement($pid, trim($_POST['note'] ?? '') ?: null, $ctCount, $ctType);
        if ($ctType !== null) { Lookup::add('container_type', $ctType); }
        if ($this->wantsJson()) { $this->ajaxMovement($mid, 'تمت إضافة حركة جديدة.'); return; }
        flash('success', 'تمت إضافة حركة جديدة.');
        redirect('permits/'.$pid.'/manage#movement-'.$mid);
    }

    /** تعديل وصف الحركة: ملاحظة + عدد/تصنيف الحاويات (حقول وصفية معزولة) */
    public function updateMovement(string $mid): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $mv = Permit::movement((int)$mid);
        if (!$mv) { $this->fail('الحركة دي مش موجودة أو اتمسحت.', 'permits'); }
        $pid = (int)$mv['permit_id'];
        if (\App\Models\OfficeDriverStatement::movementAllocationTotal((int)$mid) > 0.005) {
            $this->fail('الحركة فيها إيصال مربوط ببيان سائق سيارة المكتب. فكّ الربط من بيان السائق أولاً.',
                'permits/'.$pid.'/manage#movement-'.(int)$mid);
        }
        [$ctCount, $ctType] = $this->movementContainerInput();
        $this->claimForm('permits/'.$pid.'/manage#movement-'.(int)$mid);
        Permit::updateMovement((int)$mid, trim($_POST['note'] ?? '') ?: null, $ctCount, $ctType);
        if ($ctType !== null) { Lookup::add('container_type', $ctType); }
        if ($this->wantsJson()) { $this->ajaxMovement((int)$mid, 'تم حفظ بيانات الحركة.'); return; }
        flash('success', 'تم حفظ بيانات الحركة.');
        redirect('permits/'.$pid.'/manage#movement-'.(int)$mid);
    }

    /** يقرأ عدد/تصنيف الحاويات من الفورم (وصفي على مستوى الحركة). */
    private function movementContainerInput(): array
    {
        $rawCount = trim($_POST['container_count'] ?? '');
        $ctCount  = $rawCount === '' ? null : max(0, (int)$rawCount);
        $ctType   = trim($_POST['container_type'] ?? '') ?: null;
        return [$ctCount, $ctType];
    }

    /** حذف حركة (تُحذف بنودها؛ وبوالصها تُعاد لحركة أخرى إن وُجدت) */
    public function deleteMovement(string $mid): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $mv = Permit::movement((int)$mid);
        if (!$mv) { $this->fail('الحركة دي مش موجودة أو اتمسحت.', 'permits'); }
        $pid = (int)$mv['permit_id'];

        // لا تسمح بحذف آخر حركة إن كانت بها بوالص (لتفادي بوالص بلا حركة)
        $billCount = (int) Database::scalar('SELECT COUNT(*) FROM bawalis WHERE movement_id = ?', [(int)$mid]);
        $other = Database::scalar('SELECT id FROM permit_movements WHERE permit_id = ? AND id <> ? ORDER BY seq LIMIT 1', [$pid, (int)$mid]);
        if ($billCount > 0 && !$other) {
            $err = 'لا يمكن حذف آخر حركة بها بوالص — انقل بوالصها أو احذفها أولاً، أو أضف حركة أخرى.';
            if ($this->wantsJson()) { $this->json(['ok'=>false, 'flash'=>$err]); return; }
            flash('error', $err);
            redirect('permits/'.$pid.'/manage#movement-'.(int)$mid);
        }

        // القفل الصارم: امنع حذف حركة تحوي بند مصروف نقدي مؤرَّخًا في يوم متقفل
        $maxClosed = Closing::lastClosedDate();
        if ($maxClosed !== null) {
            $expSql = Permit::expenseSql('s');
            // v42: بنود عهدة المناديب بقت تدخل التقفيل، فحذفها بيغيّر رصيدًا مقفولاً — امنعه زي أي بند.
            $blocked = (int) Database::scalar(
                "SELECT COUNT(*) FROM permit_stages s
                 WHERE s.movement_id = ? AND s.is_visa = 0 AND $expSql AND s.entry_date <= ?",
                [(int)$mid, $maxClosed]);
            if ($blocked) {
                $err = 'الحركة تحوي بنود مصروفات في أيام متقفلة. افتح الإقفال أولاً قبل حذفها.';
                if ($this->wantsJson()) { $this->json(['ok'=>false, 'flash'=>$err]); return; }
                flash('error', $err);
                redirect('permits/'.$pid.'/manage#movement-'.(int)$mid);
            }
        }

        $this->claimForm('permits/'.$pid.'/manage#movement-'.(int)$mid);

        // احذف مرفقات بنود الحركة
        foreach (Database::all('SELECT id, attachment FROM permit_stages WHERE movement_id = ?', [(int)$mid]) as $s) {
            $this->deleteFile($s['attachment']);
            // فكّ ارتباط أي اعتماد مندوب قبل الحذف — السجل يفضل محفوظًا (قرار المالك)
            DeletionPolicy::detachStage((int) $s['id']);
        }
        Database::exec('DELETE FROM permit_stages WHERE movement_id = ?', [(int)$mid]);

        // أعِد بوالص هذه الحركة لأقدم حركة أخرى
        Database::exec('UPDATE bawalis SET movement_id = ? WHERE movement_id = ?', [$other ? (int)$other : null, (int)$mid]);

        Database::exec('DELETE FROM permit_movements WHERE id = ?', [(int)$mid]);
        if ($this->wantsJson()) { $this->ajaxMovement(null, 'تم حذف الحركة.', (int)$mid); return; }
        flash('success', 'تم حذف الحركة.');
        redirect('permits/'.$pid.'/manage#stage-bills');
    }

    /** حفظ/إضافة بند مرحلة (يقبل رفع ملف) */
    public function saveStage(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $pid = (int)$id;
        $permit = Permit::find($pid);
        if (!$permit) { $this->fail('الإصدار ده مش موجود أو اتمسح.', 'permits'); }

        $key = trim($_POST['stage_key'] ?? '');
        if (!in_array($key, Permit::stageKeys(), true)) {
            if ($this->wantsJson()) { $this->jsonFormFailure(['stage_key' => 'مرحلة غير معروفة.']); }
            flash('error', 'مرحلة غير معروفة.');
            redirect('permits/'.$pid.'/manage');
        }
        $stageId = (int)($_POST['stage_id'] ?? 0);   // 0 = بند جديد
        // الحركة: مطلوبة للمراحل ذات النطاق movement، وNULL لمراحل الإذن (رسوم أولية)
        $scope = Permit::STAGES[$key]['scope'] ?? 'movement';
        $movementId = $scope === 'movement' ? ((int)($_POST['movement_id'] ?? 0) ?: null) : null;

        // القفل الصارم: بنود المصروفات النقدية (التي تدخل التقفيل) لا تُكتَب على أيام متقفلة.
        // نفحص التاريخ الجديد، وعند التعديل التاريخ القديم للبند أيضًا. الفيزا والبنود المحصّلة مستثناة.
        $newEntry = ($_POST['entry_date'] ?? '') ?: work_date();
        $affectsClosing = empty($_POST['is_visa'])
            && (in_array($key, Permit::expenseKeys(), true)
                || ($key === 'custom' && ($_POST['direction'] ?? 'expense') !== 'revenue'));
        if ($affectsClosing) {
            $lockErr = Closing::guard($newEntry);
            if (!$lockErr && $stageId > 0) {
                $oldEntry = Database::scalar('SELECT entry_date FROM permit_stages WHERE id=? AND permit_id=?', [$stageId, $pid]);
                if ($oldEntry) { $lockErr = Closing::guard($oldEntry); }
            }
            if ($lockErr) {
                if ($this->wantsJson()) { $this->jsonFormFailure(['entry_date' => $lockErr]); }
                flash('error', $lockErr);
                redirect('permits/'.$pid.'/manage');
            }
        }

        // «لا يوجد»: تعليم المرحلة كمكتملة بلا بند (صف علامة بمبلغ 0 وحالة none)
        if (!empty($_POST['mark_none'])) {
            $this->claimForm('permits/'.$pid.'/manage');
            // امسح أي علامة سابقة لنفس المرحلة/الحركة ثم أدرج واحدة (منع التكرار)
            if ($movementId) {
                Database::exec("DELETE FROM permit_stages WHERE permit_id=? AND movement_id=? AND stage_key=? AND status='none'", [$pid, $movementId, $key]);
            } else {
                Database::exec("DELETE FROM permit_stages WHERE permit_id=? AND movement_id IS NULL AND stage_key=? AND status='none'", [$pid, $key]);
            }
            Database::exec(
                "INSERT INTO permit_stages (permit_id, movement_id, stage_key, amount, entry_date, note, status, is_visa)
                 VALUES (?,?,?,0,?, 'لا يوجد', 'none', 0)",
                [$pid, $movementId, $key, work_date()]
            );
            $noneId = Database::lastId();
            $noneRow = Database::row('SELECT * FROM permit_stages WHERE id = ?', [$noneId]);
            Audit::log('create', 'permit_stages', $noneId, null, $noneRow,
                'إثبات «لا يوجد» لبند '.Permit::label($key).' — '.Permit::noLabel($permit['permit_type']).' '.($permit['permit_no'] ?: '#'.$pid),
                ['permit_id'=>$pid, 'permit_no'=>$permit['permit_no'], 'record_date'=>work_date()]);
            $msg = 'تم تعليم «' . Permit::label($key) . '» كمكتملة (لا يوجد).';
            if ($this->wantsJson()) {
                if ($movementId) { $this->ajaxMovement($movementId, $msg); } else { $this->json(['ok'=>true, 'flash'=>$msg, 'reload'=>true]); }
                return;
            }
            flash('success', $msg);
            redirect('permits/'.$pid . '/manage' . ($movementId ? '#movement-'.$movementId : '#stage-'.$key));
        }

        // اتجاه البند المخصّص (مصروف/محصّل) — للبنود المخصّصة فقط
        $direction = null;
        if ($key === 'custom') {
            $direction = ($_POST['direction'] ?? 'expense') === 'revenue' ? 'revenue' : 'expense';
        }

        // الكمية (للإيصالات): الإجمالي المخزَّن = سعر الوحدة × الكمية.
        // البنود التي لا تُرسِل qty (رسوم أولية/مخصّصة) → qty=1 فلا يتغيّر سلوكها.
        $qty      = max(1, (int)($_POST['qty'] ?? 1));
        $unit     = (float)($_POST['amount'] ?? 0);
        $amount   = round($qty * $unit, 2);
        if ($stageId > 0) {
            $driverAllocated = \App\Models\OfficeDriverStatement::stageAllocationTotal($stageId);
            if ($amount + 0.005 < $driverAllocated) {
                $message = 'المبلغ الجديد أقل من الجزء (' . money0($driverAllocated)
                    . ') المربوط ببيانات سائق سيارة المكتب. عدّل ربط الإيصال من بيان السائق أولاً.';
                if ($this->wantsJson()) { $this->jsonFormFailure(['amount' => $message]); }
                flash('error', $message);
                redirect('permits/'.$pid.'/manage');
            }
        }

        // السعر الفعلي (اللي هنحاسب بيه العميل) — لكل البنود ماعدا البوالص والرسوم الأولية (الكشف والضمان)
        // يُدخَل كسعر وحدة أيضاً ويُضرب في الكمية ليعطي الإجمالي الفعلي.
        $actual = ($key === 'primary_fees' || ($_POST['actual_amount'] ?? '') === '')
            ? null : round($qty * (float)$_POST['actual_amount'], 2);

        $data = [
            'direction'  => $direction,
            'amount'     => $amount,
            'qty'        => $qty,
            'actual_amount' => $actual,
            'entry_date' => ($_POST['entry_date'] ?? '') ?: work_date(),
            'ref_no'     => trim($_POST['ref_no'] ?? '') ?: null,
            'note'       => trim($_POST['note'] ?? '') ?: null,
            'status'     => ($_POST['status'] ?? 'done') === 'pending' ? 'pending' : 'done',
            'is_visa'    => !empty($_POST['is_visa']) ? 1 : 0,   // فيزا: لا يُحتسب في التقفيل
            'visa_card_id' => (!empty($_POST['is_visa']) && !empty($_POST['visa_card_id'])) ? (int)$_POST['visa_card_id'] : null,
            // v57 — «غير مدفوع»: البند اتحاسب من برّه الخزنة وبرّه عهدة المندوب.
            // بيتسجّل على الإذن وبيتفوتر للعميل عادي، و`Permit::cashOutSql`
            // بتستثنيه من رصيد الخزنة والتقفيل ومن عهدة المندوب مع بعض.
            // ⚠️ متنافي مع الفيزا: الفيزا معناها اتدفع من كارت المكتب فعلاً.
            'unpaid'     => (!empty($_POST['unpaid']) && empty($_POST['is_visa'])) ? 1 : 0,
        ];

        $this->claimForm('permits/'.$pid.'/manage'.($movementId ? '#movement-'.$movementId : '#stage-'.$key));

        // رفع مرفق إن وُجد
        $attach = $this->handleUpload('attachment', $pid);

        $beforeStage = $stageId > 0
            ? Database::row('SELECT * FROM permit_stages WHERE id = ? AND permit_id = ?', [$stageId, $pid])
            : null;
        if ($stageId > 0) {
            // تعديل بند قائم
            $set = 'direction=:direction, amount=:amount, qty=:qty, actual_amount=:actual_amount, entry_date=:entry_date, ref_no=:ref_no, note=:note, status=:status, is_visa=:is_visa, visa_card_id=:visa_card_id, unpaid=:unpaid';
            $prm = array_combine(array_map(fn($c)=>":$c",array_keys($data)), array_values($data));
            $prm[':id'] = $stageId; $prm[':pid'] = $pid;
            if ($attach !== null) { $set .= ', attachment=:attachment'; $prm[':attachment'] = $attach; }
            Database::exec("UPDATE permit_stages SET $set WHERE id=:id AND permit_id=:pid", $prm);
        } else {
            Database::exec(
                'INSERT INTO permit_stages (permit_id, movement_id, stage_key, direction, amount, qty, actual_amount, entry_date, ref_no, note, status, attachment, is_visa, visa_card_id, unpaid)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$pid, $movementId, $key, $direction, $data['amount'], $data['qty'], $data['actual_amount'], $data['entry_date'], $data['ref_no'], $data['note'], $data['status'], $attach, $data['is_visa'], $data['visa_card_id'], $data['unpaid']]
            );
            $stageId = Database::lastId();
        }
        $savedStage = Database::row('SELECT * FROM permit_stages WHERE id = ?', [$stageId]);
        $entryKind = $key === 'receipts' ? 'إيصال'
            : ((in_array($key, Permit::expenseKeys(), true) || ($key === 'custom' && $direction !== 'revenue')) ? 'مصروف' : 'بند');
        $entryText = ($beforeStage ? 'تعديل ' : 'إضافة ') . $entryKind . ' «' . Permit::label($key) . '»';
        if (!empty($data['note'])) { $entryText .= ' — ' . $data['note']; }
        if ((float)$data['amount'] !== 0.0) { $entryText .= ' بقيمة ' . number_format((float)$data['amount'], 2, '.', ','); }
        $entryText .= ' — ' . Permit::noLabel($permit['permit_type']) . ' ' . ($permit['permit_no'] ?: '#'.$pid);
        Audit::log($beforeStage ? 'update' : 'create', 'permit_stages', $stageId, $beforeStage, $savedStage,
            $entryText, ['permit_id'=>$pid, 'permit_no'=>$permit['permit_no'], 'movement_id'=>$movementId, 'record_date'=>$data['entry_date']]);
        // تعلّم فئة القسم الموحّد (مصاريف إضافية / بنود مخصّصة) من الملاحظة
        if ($key === 'custom'   && !empty($data['note'])) { Lookup::add('mv_expense', $data['note']); }
        if ($key === 'receipts' && !empty($data['note'])) { Lookup::add('mv_receipt', $data['note']); }

        $msg = 'تم حفظ بند ' . Permit::label($key) . '.';
        if ($this->wantsJson()) {
            if ($movementId) { $this->ajaxMovement($movementId, $msg); } else { $this->json(['ok'=>true, 'flash'=>$msg, 'reload'=>true]); }
            return;
        }
        flash('success', $msg);
        redirect('permits/'.$pid . '/manage' . ($movementId ? '#movement-'.$movementId : '#stage-'.$key));
    }

    /** نقل إيصال كما هو إلى حركة في إذن آخر، بلا نسخ أو تغيير لأي قيمة مالية/تاريخية. */
    public function moveReceipt(string $sid): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $sourcePermitId = 0;
        try {
            $stageId = (int)$sid;
            if (\App\Models\OfficeDriverStatement::stageAllocationTotal($stageId) > 0.005) {
                throw new \RuntimeException('الإيصال مربوط ببيان سائق سيارة المكتب. فكّ الربط من بيان السائق قبل نقله.');
            }
            $existing = Database::row('SELECT permit_id FROM permit_stages WHERE id = ?', [$stageId]);
            $sourcePermitId = (int)($existing['permit_id'] ?? 0);
            $result = Permit::moveReceipt($stageId, (int)($_POST['target_movement_id'] ?? 0));
            flash('success', 'تم نقل الإيصال بكامل بياناته وتاريخه إلى '.Permit::noLabel($result['target_permit_type']).' '.$result['target_permit_no'].'.');
            redirect('permits/'.$result['target_permit_id'].'/manage#stage-receipts-'.$result['target_movement_id']);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect($sourcePermitId ? 'permits/'.$sourcePermitId.'/manage#stage-receipts' : 'permits');
        }
    }

    public function deleteStage(string $sid): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        if (\App\Models\OfficeDriverStatement::stageAllocationTotal((int)$sid) > 0.005) {
            $this->fail('الإيصال مربوط ببيان سائق سيارة المكتب. فكّ الربط من بيان السائق أولاً.', 'permits');
        }
        $row = Database::row('SELECT permit_id, movement_id, attachment, entry_date, stage_key, direction, is_visa FROM permit_stages WHERE id = ?', [(int)$sid]);
        if ($row) {
            // بند ناتج عن اعتماد مندوب: الأدمن له حرية الحذف (قرار المالك) — بنفكّ الربط
            // وسجل الاعتماد نفسه بيفضل محفوظ في `rep_submissions` بحالته ومعاه ملاحظة الحذف.
            $repSubmission = DeletionPolicy::stageSubmission((int) $sid);
            // القفل الصارم: لا حذف لبند مصروف نقدي تاريخه في يوم متقفل (يغيّر رقمًا مثبّتًا)
            $affects = ((int)$row['is_visa'] === 0)
                && (in_array($row['stage_key'], Permit::expenseKeys(), true)
                    || ($row['stage_key'] === 'custom' && ($row['direction'] ?? 'expense') !== 'revenue'));
            if ($affects && ($err = Closing::guard($row['entry_date']))) {
                if ($this->wantsJson()) { $this->json(['ok'=>false, 'flash'=>$err]); return; }
                flash('error', $err);
                redirect('permits/'.(int)$row['permit_id'].'/manage');
            }
            $this->claimForm('permits/'.(int)$row['permit_id'].'/manage');
            if ($repSubmission) { DeletionPolicy::detachStage((int) $sid); }
            Database::exec('DELETE FROM permit_stages WHERE id = ?', [(int)$sid]);
            $this->deleteFile($row['attachment']);
            $mvId = $row['movement_id'] ? (int)$row['movement_id'] : null;
            if ($this->wantsJson()) {
                if ($mvId) { $this->ajaxMovement($mvId, 'تم حذف البند.'); } else { $this->json(['ok'=>true, 'flash'=>'تم حذف البند.', 'reload'=>true]); }
                return;
            }
            flash('success', 'تم حذف البند.');
            redirect('permits/'.(int)$row['permit_id'].'/manage');
        }
        $this->fail('البند ده مش موجود أو اتمسح.', 'permits');
    }

    /** ربط بوليصة موجودة بالإصدار (عبر رقم البوليصة) */
    public function linkBill(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $pid = (int)$id;
        if (!Permit::find($pid)) { $this->fail('الإصدار ده مش موجود أو اتمسح.', 'permits'); }
        $movementId = (int)($_POST['movement_id'] ?? 0) ?: null;
        $billNo = trim($_POST['bill_number'] ?? '');
        $ok = false; $msg = '';
        if ($billNo !== '') {
            // البوالص المُرحَّلة من الأرشيف لا تُربط بإذن أو حركة تحت أي ظرف.
            $bill = Database::row(
                'SELECT id FROM bawalis WHERE is_legacy=0 AND bill_number = ? ORDER BY id DESC LIMIT 1',
                [$billNo]
            );
            if ($bill) {
                $this->claimForm('permits/'.$pid.'/manage'.($movementId ? '#movement-'.$movementId : '#stage-bills'));
                Database::exec('UPDATE bawalis SET permit_id = ?, movement_id = ? WHERE id = ?', [$pid, $movementId, (int)$bill['id']]);
                $ok = true; $msg = 'تم ربط البوليصة ' . $billNo . ' بالحركة.';
                flash('success', $msg);
            } else {
                $msg = 'لم يتم العثور على بوليصة بالرقم ' . $billNo . '.';
                if (!$this->wantsJson()) { flash('error', $msg); }
            }
        } else {
            $msg = 'اكتب رقم البوليصة المراد ربطها.';
        }
        if ($this->wantsJson()) {
            if ($ok && $movementId) { $this->ajaxMovement($movementId, $msg); return; }
            if (!$ok) { $this->jsonFormFailure(['bill_number'=>$msg]); }
            $this->json(['ok'=>true, 'flash'=>$msg, 'reload'=>true]);
            return;
        }
        redirect('permits/'.$pid . '/manage' . ($movementId ? '#movement-'.$movementId : '#stage-bills'));
    }

    public function unlinkBill(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $pid = (int)$id;
        $billId = (int)($_POST['bill_id'] ?? 0);
        Database::exec('UPDATE bawalis SET permit_id = NULL, movement_id = NULL WHERE id = ? AND permit_id = ?', [$billId, $pid]);
        flash('success', 'تم فك ربط البوليصة.');
        redirect('permits/'.$pid.'/manage#stage-bills');
    }

    /** حذف بوليصة نهائياً من داخل الإذن */
    public function deleteBill(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $pid = (int)$id;
        $billId = (int)($_POST['bill_id'] ?? 0);
        if (\App\Models\OfficeDriverStatement::billAllocationTotal($billId) > 0.005) {
            $this->fail('البوليصة مرتبطة بإيصال دفعه سائق سيارة المكتب. فكّ الربط من بيان السائق أولاً.',
                'permits/'.$pid.'/manage');
        }
        $bill = Database::row('SELECT movement_id, custody, bill_date FROM bawalis WHERE id = ? AND permit_id = ?', [$billId, $pid]);
        if ($bill) {
            // القفل الصارم: لا حذف لبوليصة بعهدة تاريخها في يوم متقفل
            if ((float)$bill['custody'] > 0 && ($err = Closing::guard($bill['bill_date']))) {
                if ($this->wantsJson()) { $this->json(['ok'=>false, 'flash'=>$err]); return; }
                flash('error', $err);
                redirect('permits/'.$pid.'/manage');
            }
            $this->claimForm('permits/'.$pid.'/manage');
            Database::exec('DELETE FROM bawalis WHERE id = ?', [$billId]);   // bill_payments تُحذف بالـ CASCADE
            $mvId = $bill['movement_id'] ? (int)$bill['movement_id'] : null;
            if ($this->wantsJson()) {
                if ($mvId) { $this->ajaxMovement($mvId, 'تم حذف البوليصة نهائياً.'); } else { $this->json(['ok'=>true, 'flash'=>'تم حذف البوليصة نهائياً.', 'reload'=>true]); }
                return;
            }
            flash('success', 'تم حذف البوليصة نهائياً.');
            redirect('permits/'.$pid . '/manage' . ($mvId ? '#movement-'.$mvId : '#stage-bills'));
        }
        $this->fail('البوليصة دي مش موجودة أو اتمسحت.', 'permits/'.$pid.'/manage');
    }

    /** إنشاء بوليصة جديدة وربطها بالإصدار مباشرةً من التايم لاين */
    /**
     * مين سلّم السائق عهدته؟ — بيرجّع `rep_id` أو **null = خزنة المكتب** (الافتراضي).
     *
     * ⚠️⚠️ **الوسم ده بيعمل حاجتين لازم يمشوا مع بعض** (`RepBillCustody`):
     *    ① بيستثني البوليصة من خزنة المكتب.  ② بيضيف مبلغها لصرف المندوب.
     *    استثناء بلا إضافة = **فلوس بتتبخّر من النظام**.
     *
     * ⚠️ **الفورم مش مصدر ثقة:** أي رقم مش مندوب **نشط** بيترجّع null (خزنة) بدل
     *    ما يتخزّن id وهمي فتختفي العهدة من الخزنة وما تظهرش عند حد.
     *
     * ⚠️ عهدة = صفر → null دايمًا (مفيش دافع أصلاً).
     *
     * ⚠️⚠️ **بوليصة تاريخها قبل خط الفصل بترجّع null كمان** — جانب المحفظة بيمرّ
     *    على خط الفصل وجانب الخزنة لأ، فالوسم على تاريخ قديم = فلوس بتتبخّر.
     *    المصدر الوحيد للفحص `RepBillCustody::payerAllowed()`.
     */
    private function custodyPayerId(float $custody, ?string $billDate = null): ?int
    {
        if ($custody <= 0 || !\App\Models\RepBillCustody::ready()) { return null; }
        $rid = (int) ($_POST['custody_rep_id'] ?? 0);
        if ($rid <= 0) { return null; }
        if (!\App\Models\RepBillCustody::payerAllowed($billDate)) { return null; }
        return Database::scalar(
            "SELECT id FROM reps WHERE id = ? AND status = 'active'", [$rid]) ? $rid : null;
    }

    public function createBill(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $pid = (int)$id;
        $p = Permit::find($pid);
        if (!$p) { $this->fail('الإصدار ده مش موجود أو اتمسح.', 'permits'); }

        $type = $p['permit_type'];
        $movementId = (int)($_POST['movement_id'] ?? 0) ?: null;
        if ($movementId && !Database::scalar('SELECT id FROM permit_movements WHERE id=? AND permit_id=?', [$movementId, $pid])) {
            $err = 'الحركة المختارة لا تتبع هذا الإذن. أعد تحميل الصفحة وحاول مرة أخرى.';
            if ($this->wantsJson()) { $this->jsonFormFailure(['movement_id'=>$err]); }
            flash('error', $err); redirect('permits/'.$pid.'/manage#stage-bills');
        }

        // القفل الصارم: لا عهدة سائق ببوليصة تاريخها في يوم متقفل (تغيّر عهد يوم مثبّت)
        $billDate = ($_POST['bill_date'] ?? '') ?: work_date();
        if ((float)($_POST['custody'] ?? 0) > 0 && ($err = Closing::guard($billDate))) {
            if ($this->wantsJson()) { $this->jsonFormFailure(['bill_date'=>$err]); }
            flash('error', $err);
            redirect('permits/'.$pid.'/manage'.($movementId?'#movement-'.$movementId:'#stage-bills'));
        }

        // منع تكرار رقم البوليصة — طبقة حماية ضد الخطأ في الإدخال
        $billNo = trim($_POST['bill_number'] ?? '');
        if ($billNo !== '' && Bill::numberExists($billNo)) {
            $err = 'رقم البوليصة «'.$billNo.'» مُستخدَم من قبل — لا يمكن تكراره. اختر رقمًا مختلفًا.';
            if ($this->wantsJson()) { $this->jsonFormFailure(['bill_number'=>$err]); }
            flash('error', $err);
            redirect('permits/'.$pid . '/manage' . ($movementId ? '#movement-'.$movementId : '#stage-bills'));
        }

        // حوكمة الأسماء: توحيد التهجئة تلقائيًا + إيقاف الأسماء القريبة غير المؤكَّدة
        if ($err = $this->nameGuardError(['declaration_owner'=>'owner','driver'=>'driver','car_owner'=>'car_owner','destination'=>'destination','company'=>'company','container_type'=>'container_type','classification'=>'classification'])) {
            if ($this->wantsJson()) { $this->jsonFormFailure(['_form'=>$err]); }
            flash('error', $err);
            redirect('permits/'.$pid . '/manage' . ($movementId ? '#movement-'.$movementId : '#stage-bills'));
        }

        // العدد يلتزم بسعة الحركة الظاهرة (مثلاً 6/7). الحركات القديمة بلا سعة
        // ترجع تلقائيًا لإجمالي الإذن كحد احتياطي.
        $newCount = max(0, (int)($_POST['container_count'] ?? 0));
        $capacity = Permit::billContainerCapacity($pid, $movementId);
        if ($capacity['total'] > 0) {
            if ($capacity['allocated'] + $newCount > $capacity['total']) {
                $whereLabel = $capacity['scope'] === 'movement' ? 'الحركة' : 'إجمالي الإذن';
                $err = 'عدد الحاويات ('.$newCount.') يتجاوز المتبقّي من '.$whereLabel.' (المتبقّي '.
                    $capacity['left'].' من '.$capacity['total'].'). لم تُحفظ البوليصة.';
                if ($this->wantsJson()) { $this->jsonFormFailure(['container_count'=>$err]); }
                flash('error', $err);
                redirect('permits/'.$pid . '/manage' . ($movementId ? '#movement-'.$movementId : '#stage-bills'));
            }
        }

        // الخصم الافتراضي (200) يُطبَّق هنا تماماً كما في نموذج البوالص القياسي.
        // المصدر الوحيد للقيمة الافتراضية هو Schema (bawalis.discount.default) حتى لا تتكرّر.
        $discField   = array_column(\App\Schema::get('bawalis')['fields'], null, 'key')['discount'] ?? [];
        $discDefault = (float)($discField['default'] ?? 0);
        $discount    = ($_POST['discount'] ?? '') === '' ? $discDefault : (float)$_POST['discount'];

        $this->claimForm('permits/'.$pid.'/manage'.($movementId?'#movement-'.$movementId:'#stage-bills'));
        $ownerMaster = \App\Models\PartyMaster::ensure('owner', trim($_POST['declaration_owner'] ?? ''));
        $data = [
            'permit_id'         => $pid,
            'movement_id'       => $movementId,
            'bill_type'         => $type,
            'bill_date'         => $billDate,
            'bill_number'       => trim($_POST['bill_number'] ?? '') ?: null,
            'permit_no'         => $type === 'sader' ? ($p['permit_no'] ?: null) : null,
            'cert_number'       => $type === 'warid' ? ($p['permit_no'] ?: null) : null,
            // صاحب البيان يُدخَل يدويًا (اختياري) — ليس هو العميل/الوكيل. فارغ = بوليصة بلا صاحب بيان تُعيَّن لاحقًا.
            'declaration_owner' => $ownerMaster['name'] ?? null,
            'declaration_owner_id' => $ownerMaster['id'] ?? null,
            'company'           => $p['company'] ?: null,
            'company_id'        => $p['company_id'] ?? null,
            'destination'       => $p['destination'] ?: null,
            'container_no'      => trim($_POST['container_no'] ?? '') ?: null,
            // تصنيف الحاويات: يؤخذ من المدخل، وإلا يُملأ تلقائيًا من تصنيف الإذن
            'container_type'    => (trim($_POST['container_type'] ?? '') ?: null) ?: ($p['container_type'] ?? null),
            'container_count'   => $newCount,
            'car_no'            => $this->composeCarNo() ?: null,
            'driver'            => trim($_POST['driver'] ?? '') ?: null,
            'car_owner'         => trim($_POST['car_owner'] ?? '') ?: null,
            'freight'           => (float)($_POST['freight'] ?? 0),
            'custody'           => (float)($_POST['custody'] ?? 0),
            'transfer'          => (float)($_POST['transfer'] ?? 0),
            'discount'          => $discount,
            'overnight_collection' => (float)($_POST['overnight_collection'] ?? 0),
        ];
        $data['debt'] = Calc::bawala($data)['debt'];
        // ⚠️ بعد `Calc::bawala` عن قصد — ده وسم مش مدخل حسابي، وممنوع يدخل المعادلة.
        if (\App\Models\RepBillCustody::ready()) {
            $data['custody_rep_id'] = $this->custodyPayerId(
                (float) $data['custody'], (string) $data['bill_date']);
        }

        $cols = array_keys($data);
        Database::exec(
            "INSERT INTO bawalis (`".implode('`,`',$cols)."`) VALUES (".implode(',', array_map(fn($c)=>":$c",$cols)).")",
            array_combine(array_map(fn($c)=>":$c",$cols), array_values($data))
        );
        $billId = Database::lastId();
        Bill::recalc($billId);
        \App\Models\Movement::ensureForBill($billId);   // تُنشئ الحركة وتنقل بيانات الشحن من الإذن

        if (!empty($data['driver']))    { Lookup::add('driver', $data['driver']); }
        if (!empty($data['car_owner'])) { Lookup::add('car_owner', $data['car_owner']); }

        // بعد ensureForBill قد تُنشأ حركة للبوليصة إن لم تكن محدّدة
        $targetMv = $movementId ?: (int) Database::scalar('SELECT movement_id FROM bawalis WHERE id = ?', [$billId]);
        $savedBill = Database::row('SELECT * FROM bawalis WHERE id = ?', [$billId]);
        Audit::log('create', 'bawalis', $billId, null, $savedBill,
            'إضافة بوليصة '.($data['bill_number'] ?: '#'.$billId).' من داخل '.Permit::noLabel($type).' '.($p['permit_no'] ?: '#'.$pid),
            ['permit_id'=>$pid, 'permit_no'=>$p['permit_no'], 'movement_id'=>$targetMv, 'record_date'=>$billDate]);
        // ⚠️ الإشعار **قبل** أي رد (بما فيه رد الـAJAX) عشان ما يتخطّاش في
        //    مسار الـAJAX — وهو المسار الغالب هنا. الإرسال نفسه مؤجَّل لآخر
        //    الطلب فما بيأخّرش الرد.
        PushNotify::billLinked($billId, (string) ($data['bill_number'] ?? ''), $pid,
                               (string) ($p['permit_no'] ?? ''), (string) ($data['declaration_owner'] ?? ''));
        /* رحلة الشحنة (v62): أول بوليصة على العربية = بداية الرحلة (استلام
           الحاوية). بيتسجّل على **البوليصة نفسها** لأن كل عربية ليها رحلتها،
           و**بس لو مش مسجَّل**. ملفوف في try/catch — تسجيل حالة وصفية ممنوع
           يوقف حفظ بوليصة. */
        try {
            $first = PermitTrack::stages($type)[0] ?? null;
            if ($first) { PermitTrack::systemMark($pid, $first['key'], $billId, 'اتسجّلت بوليصة'); }
        } catch (\Throwable $e) { /* وصفي بحت — ما يوقفش حفظ البوليصة */ }
        if ($this->wantsJson()) {
            if ($targetMv) { $this->ajaxMovement((int)$targetMv, 'تم إنشاء البوليصة وربطها بالحركة.'); } else { $this->json(['ok'=>true, 'flash'=>'تم إنشاء البوليصة.', 'reload'=>true]); }
            return;
        }
        flash('success', 'تم إنشاء البوليصة وربطها بالحركة.');
        redirect('permits/'.$pid . '/manage' . ($movementId ? '#movement-'.$movementId : '#stage-bills'));
    }

    /**
     * تعديل بوليصة في مكانها (AJAX) — من نافذة منبثقة داخل صفحة الإدارة،
     * بدل الانتقال لصفحة البوليصة. يُعيد رندر الحركة المحدَّثة كردّ JSON.
     * يطبّق نفس حمايات الإنشاء: القفل، منع تكرار الرقم، سقف العهدة، سقف الحاويات.
     */
    public function updateBill(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $pid = (int)$id;
        $p = Permit::find($pid);
        if (!$p) { $this->fail('الإصدار ده مش موجود أو اتمسح.', 'permits'); }

        $billId = (int)($_POST['bill_id'] ?? 0);
        $bill = Database::row('SELECT * FROM bawalis WHERE id = ? AND permit_id = ?', [$billId, $pid]);
        if (!$bill) {
            if ($this->wantsJson()) { $this->json(['ok'=>false, 'flash'=>'البوليصة غير موجودة.']); return; }
            flash('error', 'البوليصة غير موجودة.');
            redirect('permits/'.$pid.'/manage');
        }
        $movementId = $bill['movement_id'] ? (int)$bill['movement_id'] : null;
        $anchor = $movementId ? '#movement-'.$movementId : '#stage-bills';

        // مساعد إظهار خطأ (JSON أو flash+redirect) — يُرجِع true دائمًا لإيقاف المتابعة
        $fail = function (string $err, string $field = '_form') use ($pid, $anchor): bool {
            if ($this->wantsJson()) { $this->jsonFormFailure([$field=>$err]); }
            flash('error', $err);
            redirect('permits/'.$pid.'/manage'.$anchor);
            return true;
        };

        $newDate    = ($_POST['bill_date'] ?? '') ?: ($bill['bill_date'] ?: work_date());
        $newCustody = (float)($_POST['custody'] ?? 0);

        // (1) القفل الصارم: لا كتابة على يوم متقفل (التاريخ الجديد أو القديم)
        if ($err = Closing::guard($newDate))        { if ($fail($err, 'bill_date')) return; }
        if ($err = Closing::guard($bill['bill_date'])) { if ($fail($err, 'bill_date')) return; }

        // (2) منع تكرار رقم البوليصة (باستثناء نفسها)
        $billNo = trim($_POST['bill_number'] ?? '');
        if ($billNo !== '' && Bill::numberExists($billNo, $billId)) {
            if ($fail('رقم البوليصة «'.$billNo.'» مُستخدَم من قبل — لا يمكن تكراره. اختر رقمًا مختلفًا.', 'bill_number')) return;
        }

        // (3) سقف العهدة — الفحص كله في Custody::capError (المصدر الوحيد).
        //     تعديل: بيتفحص **الفرق** بس، فتغيير التاريخ أو أي حقل بلا زيادة في
        //     المبلغ ما بيترفضش (المالك له حرية كاملة في تعديل البوليصة).
        if ($newCustody > 0) {
            if ($err = \App\Models\Custody::capError($newCustody, $this->composeCarNo() ?: null, $billId)) {
                if ($fail($err, 'custody')) return;
            }
        }

        // (4) عدد الحاويات لا يتجاوز سعة الحركة الظاهرة (مع استثناء البوليصة نفسها).
        $newCount = max(0, (int)($_POST['container_count'] ?? 0));
        $capacity = Permit::billContainerCapacity($pid, $movementId, $billId);
        if ($capacity['total'] > 0) {
            if ($capacity['allocated'] + $newCount > $capacity['total']) {
                $whereLabel = $capacity['scope'] === 'movement' ? 'الحركة' : 'إجمالي الإذن';
                if ($fail('عدد الحاويات ('.$newCount.') يتجاوز المتبقّي من '.$whereLabel.' (المتبقّي '.
                    $capacity['left'].' من '.$capacity['total'].').', 'container_count')) return;
            }
        }

        // (5) حوكمة الأسماء: توحيد التهجئة تلقائيًا + إيقاف الأسماء القريبة غير المؤكَّدة
        //     `$bill` = القيم القديمة، فقيمة قائمة مقفولة قديمة تعدّي على سجلها
        if ($err = $this->nameGuardError(['declaration_owner'=>'owner','driver'=>'driver','car_owner'=>'car_owner','destination'=>'destination','company'=>'company','container_type'=>'container_type','classification'=>'classification'], $bill)) {
            if ($fail($err)) return;
        }

        $discount = ($_POST['discount'] ?? '') === '' ? (float)$bill['discount'] : (float)$_POST['discount'];
        $this->claimForm('permits/'.$pid.'/manage'.$anchor);
        $ownerMaster = \App\Models\PartyMaster::ensure('owner', trim($_POST['declaration_owner'] ?? ''));
        $data = [
            'bill_date'         => $newDate,
            'bill_number'       => $billNo ?: null,
            'declaration_owner' => $ownerMaster['name'] ?? null,
            'declaration_owner_id' => $ownerMaster['id'] ?? null,
            'container_no'      => trim($_POST['container_no'] ?? '') ?: null,
            'container_type'    => trim($_POST['container_type'] ?? '') ?: null,
            'container_count'   => $newCount,
            'car_no'            => $this->composeCarNo() ?: null,
            'driver'            => trim($_POST['driver'] ?? '') ?: null,
            'car_owner'         => trim($_POST['car_owner'] ?? '') ?: null,
            'freight'           => (float)($_POST['freight'] ?? 0),
            'custody'           => $newCustody,
            'transfer'          => (float)($_POST['transfer'] ?? 0),
            'discount'          => $discount,
            'overnight_collection' => (float)($_POST['overnight_collection'] ?? 0),
        ];
        $data['debt'] = Calc::bawala($data)['debt'];
        // ⚠️ **التعديل بينقل الفلوس مش بيكرّرها**: تغيير الدافع من الخزنة لمندوب
        //    (أو العكس) بيتكتب في نفس الـUPDATE، فالخزنة والمحفظة بيتعدّلوا مع بعض
        //    في خطوة واحدة — مستحيل المبلغ يتحسب في التنين ولا يختفي من التنين.
        if (\App\Models\RepBillCustody::ready()) {
            $data['custody_rep_id'] = $this->custodyPayerId(
                (float) $data['custody'], (string) $data['bill_date']);
        }

        $sets   = implode(',', array_map(fn($c)=>"`$c`=:$c", array_keys($data)));
        $params = array_combine(array_map(fn($c)=>":$c", array_keys($data)), array_values($data));
        $params[':id'] = $billId; $params[':pid'] = $pid;
        $params[':version'] = max(1,(int)($_POST['lock_version'] ?? ($bill['lock_version'] ?? 1)));
        $changed = Database::exec("UPDATE bawalis SET $sets,lock_version=lock_version+1 WHERE id=:id AND permit_id=:pid AND lock_version=:version", $params);
        if ($changed !== 1) { if ($fail('عدّل مستخدم آخر هذه البوليصة. أعد تحميل الصفحة قبل الحفظ.')) return; }

        Bill::recalc($billId);
        \App\Models\Movement::ensureForBill($billId);

        if (!empty($data['driver']))    { Lookup::add('driver', $data['driver']); }
        if (!empty($data['car_owner'])) { Lookup::add('car_owner', $data['car_owner']); }

        $msg = 'تم حفظ تعديلات البوليصة ✓';
        if ($this->wantsJson()) {
            if ($movementId) { $this->ajaxMovement($movementId, $msg); } else { $this->json(['ok'=>true, 'flash'=>$msg, 'reload'=>true]); }
            return;
        }
        flash('success', $msg);
        redirect('permits/'.$pid.'/manage'.$anchor);
    }

    /**
     * نسخ بوليصة: يُنشئ بوليصة جديدة بنفس بياناتها (بدون الدفعات) داخل نفس
     * الحركة والإذن، ثم يفتحها للتعديل مباشرةً.
     */
    public function copyBill(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $pid = (int)$id;
        if (!Permit::find($pid)) { $this->fail('الإصدار ده مش موجود أو اتمسح.', 'permits'); }
        $billId = (int)($_POST['bill_id'] ?? 0);
        $src = Database::row('SELECT * FROM bawalis WHERE id = ? AND permit_id = ?', [$billId, $pid]);
        if (!$src) {
            flash('error', 'البوليصة غير موجودة.');
            redirect('permits/'.$pid.'/manage#stage-bills');
        }
        // النسخة سجل جديد؛ تاريخها هو تاريخ العمل لا تاريخ البوليصة الأصلية.
        $copyDate = work_date();
        if ((float)($src['custody'] ?? 0) > 0 && ($err = Closing::guard($copyDate))) {
            flash('error', $err);
            redirect('permits/'.$pid.'/manage#stage-bills');
        }

        // ننسخ كل الأعمدة عدا المفاتيح والقيم المحسوبة/الدفعات
        $skip = ['id','paid','remaining','pay_status','created_at','updated_at'];
        $data = [];
        foreach ($src as $col => $val) {
            if (in_array($col, $skip, true)) { continue; }
            $data[$col] = $val;
        }
        $data['bill_date'] = $copyDate;
        // رقم بوليصة مميّز للنسخة (يعدّله المستخدم) — نضمن عدم التكرار بإضافة عدّاد إن لزم
        if (!empty($data['bill_number'])) {
            $base = $data['bill_number'] . ' - نسخة';
            $cand = $base; $i = 2;
            while (Bill::numberExists($cand)) { $cand = $base . ' ' . $i; $i++; }
            $data['bill_number'] = $cand;
        }

        // لا تُكرِّر حاويات تتجاوز سعة الحركة — تُنسخ النسخة بصفر ويُنبَّه المستخدم.
        $copyNote = '';
        $copyCapacity = Permit::billContainerCapacity($pid, !empty($data['movement_id']) ? (int)$data['movement_id'] : null);
        if ($copyCapacity['total'] > 0) {
            $srcCount = max(0, (int) ($data['container_count'] ?? 0));
            if ($copyCapacity['allocated'] + $srcCount > $copyCapacity['total']) {
                $data['container_count'] = 0;
                $copyNote = ' (صُفِّر عدد الحاويات لأن الحركة مكتملة — عدّله لو فيها متبقٍّ.)';
            }
        }

        $cols = array_keys($data);
        Database::exec(
            "INSERT INTO bawalis (`".implode('`,`',$cols)."`) VALUES (".implode(',', array_map(fn($c)=>":$c",$cols)).")",
            array_combine(array_map(fn($c)=>":$c",$cols), array_values($data))
        );
        $newId = Database::lastId();
        Bill::recalc($newId);
        \App\Models\Movement::ensureForBill($newId);

        $savedCopy = Database::row('SELECT * FROM bawalis WHERE id = ?', [$newId]);
        Audit::log('create', 'bawalis', $newId, null, $savedCopy,
            'نسخ بوليصة '.($src['bill_number'] ?: '#'.$billId).' كبوليصة جديدة '.($data['bill_number'] ?: '#'.$newId),
            ['permit_id'=>$pid, 'source_bill_id'=>$billId, 'record_date'=>$copyDate]);

        flash('success', 'تم نسخ البوليصة — عدّل بياناتها ثم احفظ.' . $copyNote);
        redirect('bawalis/'.$newId.'/edit');
    }

    /** إتمام / إعادة فتح الإذن */
    public function close(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $pid = (int)$id;
        $p = Permit::find($pid);
        if (!$p) { $this->fail('الإصدار ده مش موجود أو اتمسح.', 'permits'); }
        $new = ($p['status'] ?? 'open') === 'closed' ? 'open' : 'closed';
        // أي إذن له مستند مالي غير ملغى يفضل مقفولاً — لإعادة فتحه ألغِ المستند أولاً.
        if ($new === 'open' && PermitInvoice::hasActive($pid)) {
            flash('error', 'لا يمكن إعادة فتح الإذن لأن له مستنداً مالياً صادراً. ألغِ المستند أولاً ثم أعد الفتح.');
            redirect('permits/'.$pid);
        }
        Database::exec('UPDATE permits SET status = ? WHERE id = ?', [$new, $pid]);
        /* رحلة الشحنة (v62): إقفال الإذن = وصول المرحلة الأخيرة. **بيتسجّل بس
           لو مش مسجَّل** فما بيدهسش تحديثًا كتبه المكتب أو المندوب بإيده،
           و**ملفوف في try/catch**: فشل تسجيل حالة وصفية ممنوع يوقف الإقفال. */
        if ($new === 'closed') {
            try {
                $st = PermitTrack::stages($p['permit_type'] ?? null);
                $last = end($st);
                if ($last) { PermitTrack::systemMark($pid, $last['key'], PermitTrack::WHOLE_PERMIT, 'الإذن اتقفل'); }
            } catch (\Throwable $e) { /* وصفي بحت — ما يوقفش الإقفال */ }
        }
        // ⚠️ الإشعار **بعد** نجاح العملية وخارج أي transaction (docs/15 §٨.٢)
        PushNotify::permitClosed($pid, (string) ($p['permit_no'] ?? ''), $new === 'closed',
                                 (string) (\App\Core\Auth::user() ?? 'المكتب'));
        flash('success', $new === 'closed' ? 'تم إتمام الإذن وإغلاقه ✓' : 'تم إعادة فتح الإذن.');
        redirect('permits/'.$pid);   // صفحة العرض
    }

    public function destroy(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $pid = (int)$id;
        if (\App\Models\OfficeDriverStatement::permitAllocationTotal($pid) > 0.005) {
            $this->fail('الإذن فيه إيصال مربوط ببيان سائق سيارة المكتب. فكّ الربط من بيان السائق أولاً.',
                'permits/'.$pid);
        }
        $blockers = DeletionPolicy::permit($pid);
        if ($blockers['invoices'] > 0) {
            flash('error','لا يمكن حذف إذن له مستندات مالية صادرة. ألغِ المستندات أولاً أو اترك الإذن محفوظاً.');
            redirect('permits/'.$pid);
        }
        if ($blockers['submissions'] > 0) {
            flash('error','لا يمكن حذف الإذن لأن له سجل إدخال من مندوب. أغلق الإذن واترك السجل محفوظًا.');
            redirect('permits/'.$pid);
        }
        $permit = Permit::find($pid);
        if (!$permit) { $this->fail('الإصدار ده مش موجود أو اتمسح.', 'permits'); }
        $files = array_column(Permit::stages($pid), 'attachment');
        $pdo = Database::conn();
        try {
            $pdo->beginTransaction();
            Database::row('SELECT id FROM permits WHERE id=? FOR UPDATE', [$pid]);
            Database::exec('DELETE FROM permit_assignments WHERE permit_id=?', [$pid]);
            Database::exec('UPDATE bawalis SET permit_id = NULL WHERE permit_id = ?', [$pid]);
            Database::exec('DELETE FROM permits WHERE id = ?', [$pid]);
            Audit::log('delete', 'permits', $pid, $permit, null, 'حذف إذن بلا مستندات أو إدخالات مناديب', [], false);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            flash('error', 'لم يُحذف الإذن: ' . $e->getMessage());
            redirect('permits/'.$pid);
        }
        foreach ($files as $file) { $this->deleteFile($file); }
        flash('success', 'تم حذف الإصدار.');
        redirect('permits');
    }

    // ------------------------------------------------------------------
    //  الإجراءات الجماعية على الأذونات المحدَّدة (وضع السجلات)
    //  نقطة دخول واحدة POST /permits/bulk تتفرّع على op — نفس نمط بوالص bulk.
    // ------------------------------------------------------------------

    public function bulk(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $op  = trim((string)($_POST['op'] ?? ''));
        $ids = $this->bulkIds((array)($_POST['ids'] ?? []));
        if (!$ids) {
            flash('error', 'حدّد إذنًا واحدًا على الأقل (أو العدد أكبر من المسموح).');
            redirect($this->bulkBack());
        }
        switch ($op) {
            case 'close':  $this->bulkStatus($ids, 'closed'); return;
            case 'reopen': $this->bulkStatus($ids, 'open');   return;
            case 'delete': $this->bulkDelete($ids);           return;
            case 'party':  $this->bulkParty($ids);            return;
            case 'csv':    $this->bulkCsv($ids);              return;
        }
        flash('error', 'إجراء غير معروف.');
        redirect($this->bulkBack());
    }

    /** إغلاق/إعادة فتح دفعة واحدة — نفس منطق close() لكن بلا قلب حالة كل إذن على حدة. */
    private function bulkStatus(array $ids, string $status): void
    {
        $ph    = implode(',', array_fill(0, count($ids), '?'));
        $found = Database::all("SELECT id,status,permit_no FROM permits WHERE id IN ($ph)", $ids);
        if (!$found) { flash('error', 'لم يُعثر على الأذونات المحدَّدة.'); redirect($this->bulkBack()); }

        $changed = array_values(array_filter($found, fn($p) => ($p['status'] ?? 'open') !== $status));
        // إعادة الفتح الجماعية تتخطّى أي إذن له مستند مالي غير ملغى (نفس قاعدة close()).
        $blocked = 0;
        if ($status === 'open' && $changed) {
            $invoiced = PermitInvoice::permitsWithActive(array_map(fn($p) => (int)$p['id'], $changed));
            if ($invoiced) {
                $before  = count($changed);
                $changed = array_values(array_filter($changed, fn($p) => !in_array((int)$p['id'], $invoiced, true)));
                $blocked = $before - count($changed);
            }
        }
        if (!$changed) {
            if ($blocked) {
                flash('error', 'لم تُفتح أي أذونات: ' . $blocked . ' منها له مستند مالي صادر. ألغِ المستند أولاً.');
                redirect($this->bulkBack());
            }
            flash('success', 'كل الأذونات المحدَّدة بالفعل ' . ($status === 'closed' ? 'مقفولة.' : 'مفتوحة.'));
            redirect($this->bulkBack());
        }
        $cids = array_map(fn($p) => (int)$p['id'], $changed);
        $cph  = implode(',', array_fill(0, count($cids), '?'));

        $pdo = Database::conn();
        try {
            $pdo->beginTransaction();
            Database::exec("UPDATE permits SET status = ? WHERE id IN ($cph)", array_merge([$status], $cids));
            Audit::log('bulk_update', 'permits', null, ['before' => $changed], ['status' => $status],
                $status === 'closed' ? 'إغلاق مجموعة أذونات' : 'إعادة فتح مجموعة أذونات', ['ids' => $cids]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            flash('error', 'لم يتم تغيير أي إذن: ' . $e->getMessage());
            redirect($this->bulkBack());
        }

        $skipped = count($found) - count($changed);
        flash('success', ($status === 'closed' ? 'تم إتمام وإغلاق ' : 'تمت إعادة فتح ') . count($cids) . ' إذن ✓'
            . ($skipped ? ' (تم تخطّي ' . $skipped . ' كانت بالفعل بنفس الحالة)' : '')
            . ($blocked ? ' — وتم منع فتح ' . $blocked . ' لأن له مستندات مالية صادرة.' : ''));
        redirect($this->bulkBack());
    }

    /**
     * حذف جماعي بنفس حماية destroy(): أي إذن له مستند مالي صادر (غير ملغى) يُتخطّى ويُبلَّغ.
     * الحذف نفسه transaction واحدة، والمرفقات تُمسح بعد نجاحها فقط (لا نفقد ملفات على rollback).
     */
    private function bulkDelete(array $ids): void
    {
        $ph      = implode(',', array_fill(0, count($ids), '?'));
        $found   = Database::all("SELECT * FROM permits WHERE id IN ($ph)", $ids);
        if (!$found) { flash('error', 'لم يُعثر على الأذونات المحدَّدة.'); redirect($this->bulkBack()); }

        $blockedInvoices = array_column(Database::all(
            "SELECT DISTINCT permit_id FROM permit_invoices WHERE permit_id IN ($ph) AND status <> 'void'", $ids
        ), 'permit_id');
        $blockedHistory = array_column(Database::all(
            "SELECT DISTINCT permit_id FROM rep_submissions WHERE permit_id IN ($ph)", $ids
        ), 'permit_id');
        $blocked = array_values(array_unique(array_map('intval', array_merge($blockedInvoices, $blockedHistory))));

        $target = array_values(array_filter($found, fn($p) => !in_array((int)$p['id'], $blocked, true)));
        if (!$target) {
            flash('error', 'كل الأذونات المحدَّدة لها مستندات مالية صادرة — ألغِ المستندات أولاً أو اترك الأذونات محفوظة.');
            redirect($this->bulkBack());
        }
        $tids  = array_map(fn($p) => (int)$p['id'], $target);
        $tph   = implode(',', array_fill(0, count($tids), '?'));

        // مسارات المرفقات تُقرأ قبل الحذف (permit_stages تُحذف بالـ CASCADE)
        $files = array_column(Database::all(
            "SELECT attachment FROM permit_stages WHERE permit_id IN ($tph) AND attachment IS NOT NULL AND attachment <> ''", $tids
        ), 'attachment');

        $pdo = Database::conn();
        try {
            $pdo->beginTransaction();
            Database::exec("DELETE FROM permit_assignments WHERE permit_id IN ($tph)", $tids);
            Database::exec("UPDATE bawalis SET permit_id = NULL WHERE permit_id IN ($tph)", $tids);
            Database::exec("DELETE FROM permits WHERE id IN ($tph)", $tids);
            Audit::log('bulk_delete', 'permits', null, ['permits' => $target], null,
                'حذف مجموعة أذونات', ['ids' => $tids], false);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            flash('error', 'لم يُحذف أي إذن: ' . $e->getMessage());
            redirect($this->bulkBack());
        }

        foreach ($files as $f) { $this->deleteFile($f); }
        flash('success', 'تم حذف ' . count($tids) . ' إصدار.'
            . ($blocked ? ' وتم تخطّي ' . count($blocked) . ' لها مستندات مالية أو سجل مناديب محفوظ.' : ''));
        redirect($this->bulkBack());
    }

    /** توحيد الوكيل و/أو الشركة لمجموعة أذونات — يمرّ على حوكمة الأسماء زي فورم الإذن. */
    private function bulkParty(array $ids): void
    {
        $client  = trim((string)($_POST['client'] ?? ''));
        $company = trim((string)($_POST['company'] ?? ''));
        if ($client === '' && $company === '') {
            flash('error', 'اكتب الوكيل أو الشركة (أو الاتنين) قبل الحفظ.');
            redirect($this->bulkBack());
        }
        $map = [];
        if ($client !== '')  { $map['client']  = 'client'; }
        if ($company !== '') { $map['company'] = 'company'; }
        if ($err = $this->nameGuardError($map)) { flash('error', $err); redirect($this->bulkBack()); }
        // nameGuardError وحّد التهجئة في $_POST — أعِد القراءة منها
        $client  = trim((string)($_POST['client'] ?? ''));
        $company = trim((string)($_POST['company'] ?? ''));

        $ph    = implode(',', array_fill(0, count($ids), '?'));
        $found = Database::all("SELECT id,permit_no,client,agent_id,company,company_id FROM permits WHERE id IN ($ph)", $ids);
        if (!$found) { flash('error', 'لم يُعثر على الأذونات المحدَّدة.'); redirect($this->bulkBack()); }

        $pdo = Database::conn();
        try {
            $pdo->beginTransaction();
            $sets = []; $params = []; $labels = [];
            if ($client !== '') {
                $agent = PartyMaster::ensure('agent', $client);
                $sets[] = 'client = ?'; $sets[] = 'agent_id = ?';
                $params[] = $agent['name'] ?? null; $params[] = $agent['id'] ?? null;
                $labels[] = 'الوكيل «' . ($agent['name'] ?? $client) . '»';
            }
            if ($company !== '') {
                $co = PartyMaster::ensure('company', $company);
                $sets[] = 'company = ?'; $sets[] = 'company_id = ?';
                $params[] = $co['name'] ?? null; $params[] = $co['id'] ?? null;
                $labels[] = 'الشركة «' . ($co['name'] ?? $company) . '»';
            }
            Database::exec('UPDATE permits SET ' . implode(', ', $sets) . " WHERE id IN ($ph)",
                array_merge($params, $ids));
            Audit::log('bulk_update', 'permits', null, ['before' => $found],
                ['client' => $client ?: null, 'company' => $company ?: null],
                'تغيير الوكيل/الشركة لمجموعة أذونات', ['ids' => $ids]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            flash('error', 'لم يتم تعديل أي إذن: ' . $e->getMessage());
            redirect($this->bulkBack());
        }

        flash('success', 'تم ضبط ' . implode(' و', $labels) . ' لعدد ' . count($found) . ' إذن ✓');
        redirect($this->bulkBack());
    }

    /** تصدير المحدَّد CSV بنفس أعمدة جدول السجلات (أرقام خام بلا تنسيق لتفتح في إكسل). */
    private function bulkCsv(array $ids): void
    {
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::all(
            "SELECT p.*,
                    (SELECT COUNT(*) FROM bawalis b WHERE b.permit_id = p.id) AS bills,
                    (SELECT COALESCE(SUM(b.container_count),0) FROM bawalis b WHERE b.permit_id = p.id) AS containers,
                    (SELECT COALESCE(SUM(b.custody),0) FROM bawalis b WHERE b.permit_id = p.id) AS custody,
                    (SELECT COALESCE(SUM(b.remaining),0) FROM bawalis b WHERE b.permit_id = p.id) AS remaining,
                    (SELECT COALESCE(SUM(s.amount),0) FROM permit_stages s WHERE s.permit_id = p.id AND ".Permit::expenseSql('s').") AS expense,
                    (SELECT COALESCE(SUM(s.amount),0) FROM permit_stages s WHERE s.permit_id = p.id AND ".Permit::revenueSql('s').") AS fees
             FROM permits p WHERE p.id IN ($ph)
             ORDER BY p.permit_date DESC, p.id DESC",
            $ids
        );
        if (!$rows) { flash('error', 'لم يُعثر على الأذونات المحدَّدة.'); redirect($this->bulkBack()); }

        while (ob_get_level() > 0) { ob_end_clean(); }   // لا تسمح لأي مخرَج سابق بإفساد الملف
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="permits-' . date('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");   // BOM — إكسل يقرأ العربي صح
        fputcsv($out, ['النوع','الرقم','التاريخ','لصالح شركة','الوكيل','الوجهة','بوالص','حاويات','التصنيف',
                       'العهدة','مصروفات','محصّل إضافي','متبقٍ البوالص','الحالة']);
        foreach ($rows as $r) {
            fputcsv($out, [
                Permit::typeLabel($r['permit_type'] ?? ''),
                $r['permit_no'] ?: '#'.(int)$r['id'],
                $r['permit_date'] ?? '',
                $r['company'] ?? '',
                $r['client'] ?? '',
                $r['destination'] ?? '',
                (int)$r['bills'],
                (int)$r['containers'],
                $r['container_type'] ?? '',
                (float)$r['custody'],
                (float)$r['expense'],
                (float)$r['fees'],
                (float)$r['remaining'],
                ($r['status'] ?? 'open') === 'closed' ? 'مقفول' : 'جارٍ',
            ]);
        }
        fclose($out);
        exit;
    }

    /** IDs موجبة فريدة مع سقف يحمي POST/SQL من الأحجام غير المقصودة. */
    private function bulkIds(array $raw): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $raw), fn($id) => $id > 0)));
        return count($ids) <= 400 ? $ids : [];
    }

    /** رجوع محلي آمن لنفس فلتر السجلات. */
    private function bulkBack(): string
    {
        // بيمرّ على safe_back() زي أي رجوع تاني في النظام. النسخة القديمة
        // كانت بتفحص '://' و'//' والسطر الجديد بس — أضعف من المُنضِّف الموحّد.
        // بنقبل `_back` (المعتمد) و`back` (القديم) للتوافق مع الشاشات اللي لسه بتبعته.
        return safe_back('permits?view=records', $_POST['_back'] ?? $_POST['back'] ?? null);
    }

    /** يجمّع رقم السيارة من خانتَي الحروف والأرقام (حروف ثم أرقام) */
    private function composeCarNo(): string
    {
        $letters = trim($_POST['car_letters'] ?? '');
        $digits  = trim($_POST['car_digits'] ?? '');
        $combined = trim($letters . ' ' . $digits);
        // دعم الإدخال المدمج القديم إن وُجد
        return $combined !== '' ? $combined : trim($_POST['car_no'] ?? '');
    }

    // ------------------------------------------------------------------
    //  رفع/حذف المرفقات الخاصة خارج public
    // ------------------------------------------------------------------
    private function handleUpload(string $field, int $permitId): ?string
    {
        if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        try {
            return PrivateFile::storeUploaded($_FILES[$field], 'permits', PrivateFile::PERMIT_MIME, 12 * 1024 * 1024);
        } catch (\RuntimeException $e) {
            if ($this->wantsJson()) {
                $this->json(['ok' => false, 'flash' => $e->getMessage()]);
                exit;
            }
            flash('error', $e->getMessage());
            redirect('permits/' . $permitId . '/manage');
        }
    }

    private function deleteFile(?string $rel): void
    {
        PrivateFile::delete($rel);
    }
}
