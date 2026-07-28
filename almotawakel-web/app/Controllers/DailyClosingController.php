<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Models\Closing;
use App\Models\Custody;
use App\Models\DailyReview;
use App\Models\Permit;

/**
 * التقفيل اليومي — تقفيل يوم للشركة كاملاً (وليس لسائق).
 *
 * القاعدة الأساسية (كما اتفق عليها صاحب الشركة):
 *  • كل بند مصروف يدخل «تقفيل يوم تاريخه» (entry_date) بغضّ النظر عن تاريخ الإصدار نفسه،
 *    ويبقى مسجَّلاً على الإصدار للأبد.
 *  • عهدة السائقين = مصروف (تُخصم من التقفيل، لا تُضاف كإيراد). يُطرح مبلغ ثابت
 *    (افتراضي 100ج) من عهدة كل بوليصة قبل خصمها: المخصوم = العهدة − 100.
 *    راجع محفظة العهدة `App\Models\Custody` (صفحة «خزنة المكتب»).
 *  • الرسوم المحصّلة لا تدخل صندوق التقفيل نهائياً (قرار صاحب الشركة).
 *
 *  المرحّل = باقي سابق − مصروفات اليوم − عهد السائقين (بعد خصم 100 لكل بوليصة)
 *
 * ⚠️ **المراجعة قبل الإقفال بقت علامة ✓ جنب كل بند (v50).** المطابقة اليدوية
 *    القديمة (v28: اكتب إجمالي كل عميل وطارد الفرق) اتشالت بالكامل بقرار المالك.
 *    المصدر الوحيد لحالة المراجعة `App\Models\DailyReview`.
 */
class DailyClosingController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();
        $date = $this->requestedDate();
        $this->render('closing/index', $this->base($date) + [
            'review' => DailyReview::view($date),
        ]);
    }

    public function printable(): void
    {
        $this->requireAuth();
        $date = $this->requestedDate();
        $this->render('closing/print', $this->base($date) + $this->details($date) + [
            'review' => DailyReview::view($date),
        ], false);
    }

    // ───────────────────────────────────────────── علامات المراجعة

    /** تعليم/إلغاء بند واحد (نداء AJAX من الشاشة، وله مسار رجوع بلا جافاسكريبت). */
    public function review(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $date = trim((string) ($_POST['date'] ?? ''));
        $back = 'closing?date=' . urlencode($date) . '#review';
        if (!$this->validDate($date)) { $this->fail('تاريخ غير صحيح.', 'closing'); }
        if ($msg = $this->lockMessage($date)) { $this->fail($msg, $back); }

        $type  = preg_replace('/[^a-z_]/', '', strtolower((string) ($_POST['type'] ?? '')));
        $id    = (int) ($_POST['item_id'] ?? 0);
        $state = (string) ($_POST['state'] ?? '1') === '1';
        try {
            $view = DailyReview::mark($date, (string) $type, $id, $state, Auth::user());
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), $back);
        }
        $this->respond($view, $state ? 'تم تعليم البند كمراجَع.' : 'تم إلغاء علامة المراجعة.', $back);
    }

    /** تعليم/إلغاء كل بنود مجموعة دفعة واحدة. */
    public function reviewGroup(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $date = trim((string) ($_POST['date'] ?? ''));
        $back = 'closing?date=' . urlencode($date) . '#review';
        if (!$this->validDate($date)) { $this->fail('تاريخ غير صحيح.', 'closing'); }
        if ($msg = $this->lockMessage($date)) { $this->fail($msg, $back); }

        $group = preg_replace('/[^a-z_]/', '', strtolower((string) ($_POST['group'] ?? '')));
        $state = (string) ($_POST['state'] ?? '1') === '1';
        try {
            $view = DailyReview::markGroup($date, (string) $group, $state, Auth::user());
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), $back);
        }
        $this->respond($view, $state ? 'تم تعليم كل بنود القسم.' : 'تم إلغاء علامات القسم.', $back);
    }

    /**
     * حفظ كل العلامات من الفورم — مسار الرجوع لو الجافاسكريبت متعطّل.
     * اللي متعلّم يتسجّل واللي مش متعلّم تتشال علامته، فالشاشة هي مصدر الحقيقة.
     */
    public function reviewSave(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $date = trim((string) ($_POST['date'] ?? ''));
        $back = 'closing?date=' . urlencode($date) . '#review';
        if (!$this->validDate($date)) { $this->fail('تاريخ غير صحيح.', 'closing'); }
        if ($msg = $this->lockMessage($date)) { $this->fail($msg, $back); }

        $keys = is_array($_POST['reviewed'] ?? null) ? $_POST['reviewed'] : [];
        try {
            $view = DailyReview::saveSet($date, $keys, Auth::user());
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), $back);
        }
        $pending = (int) $view['totals']['blocking_pending'];
        $this->respond(
            $view,
            $pending === 0
                ? 'تمت مراجعة كل بنود اليوم. تقدر تقفل اليوم دلوقتي.'
                : 'اتحفظت المراجعة. فاضل ' . intf($pending) . ' بند بدون علامة.',
            $back,
            $pending === 0 ? 'success' : 'warning'
        );
    }

    /**
     * رد موحّد: JSON لنداء الشاشة، أو رجوع عادي للمسار بلا جافاسكريبت.
     *
     * ⚠️ **`exit` بعد `json()` إجبارية** (نفس نمط `fail()`/`notFound()` في الكلاس
     *    الأساسي): `json()` بتطبع بس وما بتوقفش التنفيذ، فمن غير `exit` الكود كان
     *    بيكمّل لـ`done()` → `redirect()` → `header()` بعد ما الخرج اتبعت، فPHP
     *    بتلزق تحذير «Cannot modify header information» جوّه الرد نفسه فيبوظ الـJSON
     *    والمتصفح يقول «رد غير متوقّع من الخادم». اتصادفت فعليًّا.
     */
    private function respond(array $view, string $msg, string $back, string $type = 'success'): never
    {
        if ($this->wantsJson()) {
            $groups = [];
            foreach ($view['groups'] as $key => $group) {
                $groups[$key] = [
                    'count' => $group['count'], 'reviewed' => $group['reviewed'],
                    'pending' => $group['pending'], 'stale' => $group['stale'], 'done' => $group['done'],
                ];
            }
            $this->json([
                'ok' => true, 'flash' => $msg, 'totals' => $view['totals'],
                'groups' => $groups, 'complete' => $view['complete'],
                'items' => array_map(
                    static fn($item) => [
                        'key' => $item['key'], 'reviewed' => $item['reviewed'], 'stale' => $item['stale'],
                        'by' => $item['reviewed_by'], 'at' => $item['reviewed_at'],
                    ],
                    array_merge(...array_values(array_map(static fn($g) => $g['items'], $view['groups']))) ?: []
                ),
            ]);
            exit;   // ⚠️ لازمة — اقرا التعليق فوق
        }
        $this->done($msg, $back, '', $type);
    }

    /** رسالة منع الكتابة على يوم متقفل، أو null. */
    private function lockMessage(string $date): ?string
    {
        if (!DailyReview::available()) {
            return 'شغّل php bin/migrate_v50.php مرة واحدة لتفعيل المراجعة.';
        }
        if (Closing::isLocked($date)) {
            return 'اليوم متقفل. افتح الإقفال أولًا قبل تعديل المراجعة.';
        }
        return null;
    }

    // ───────────────────────────────────────────── إقفال / فتح

    /** إقفال يوم (زر «اقفل اليوم») — مشروط بمراجعة كل بنود اليوم. */
    public function close(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $date = trim($_POST['date'] ?? '');
        if ($this->validDate($date)) {
            $review = DailyReview::view($date);
            if (!$review['available']) {
                $this->fail('شغّل php bin/migrate_v50.php قبل إقفال اليوم.',
                    'closing?date=' . urlencode($date) . '#review');
            }
            if (!$review['complete']) {
                $this->fail(
                    'فاضل ' . intf((int) $review['totals']['blocking_pending'])
                    . ' بند من غير علامة مراجعة. علّم كل البنود الأول ثم اقفل اليوم.',
                    'closing?date=' . urlencode($date) . '#review'
                );
            }
        }
        $res = Closing::closeDay($date, Auth::user());
        flash($res['ok'] ? 'success' : 'error', $res['msg']);
        redirect('closing?date=' . urlencode($date));
    }

    /** فتح إقفال يوم (زر «افتح الإقفال»). */
    public function reopen(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $date = trim($_POST['date'] ?? '');
        $res  = Closing::reopenDay($date);
        flash($res['ok'] ? 'success' : 'error', $res['msg']);
        redirect('closing?date=' . urlencode($date));
    }

    // ───────────────────────────────────────────── بيانات الشاشة

    private function requestedDate(): string
    {
        $date = trim($_GET['date'] ?? '');
        return $this->validDate($date) ? $date : work_date();
    }

    /** أرقام اليوم وحالة إقفاله — تشترك فيها شاشتا العرض والطباعة. */
    private function base(string $date): array
    {
        // ── نظام الإقفال اليومي والترحيل (App\Models\Closing).
        //    القيم المالية تأتي من dayView: إن كان اليوم متقفلًا تُعرض القيم المجمّدة
        //    المخزَّنة وقت الإقفال، وإلا تُحسب حيًّا انطلاقًا من آخر يوم متقفل.
        $day        = Closing::dayView($date);
        $isClosed   = Closing::isClosedExact($date);
        $lastClosed = Closing::lastClosedDate();

        return [
            'title'         => 'التقفيل اليومي',
            'date'          => $date,
            'rate'          => Custody::deductionRate(),
            'prevBalance'   => $day['opening'],
            'totTopups'     => $day['topups'],
            'totExpense'    => $day['expenses'],
            'totCustodyExp' => $day['driver'],
            'available'     => round($day['opening'] + $day['topups'], 2),
            'carried'       => $day['closing'],
            'isClosed'      => $isClosed,
            'isLocked'      => $day['is_locked'],
            'canReopen'     => $isClosed && $lastClosed !== null && $date === $lastClosed,
            'lastClosed'    => $lastClosed,
            'closedBy'      => $day['closed_by'],
            'closedAt'      => $day['closed_at'],
        ];
    }

    /** جداول التفاصيل المسطّحة — تستخدمها صفحة الطباعة فقط. */
    private function details(string $date): array
    {
        $expSql = Permit::expenseSql('s');

        $expenses = Database::all(
            "SELECT s.id, s.amount, s.ref_no, s.note, s.stage_key,
                    p.id AS permit_id, p.permit_no, p.company, p.client,
                    p.destination, p.shipping_co, p.agency, p.port
             FROM permit_stages s
             JOIN permits p ON p.id = s.permit_id
             WHERE $expSql AND " . \App\Models\Permit::cashOutSql('s') . " AND s.amount <> 0 AND s.entry_date = ?
             ORDER BY p.permit_no, s.id", [$date]
        );
        foreach ($expenses as &$ex) { $ex['stage_label'] = Permit::label($ex['stage_key']); }
        unset($ex);
        foreach (Database::all(
            "SELECT id, amount, category, beneficiary, note FROM manual_expenses
             WHERE is_visa = 0 AND exp_date = ? ORDER BY id", [$date]) as $m) {
            $expenses[] = ['permit_no'=>'يدوي','company'=>$m['beneficiary'] ?: '—',
                'stage_label'=>$m['category'] ?: 'مصروف يدوي','note'=>$m['note'],
                'ref_no'=>null,'amount'=>$m['amount']];
        }
        // ⚠️ كاش المناديب ونثرياتهم بيتخصموا من الخزنة (v42) — لازم يظهروا في ورقة
        //    الطباعة زي أي مصروف، وإلا مجموع الجدول يقول رقم والرصيد يقول رقم تاني.
        if ($this->tableExists('rep_entries')) {
            $cash = \App\Models\Rep::cashKindsList();
            foreach (Database::all(
                "SELECT e.kind, e.amount, e.note, r.name FROM rep_entries e
                 JOIN reps r ON r.id = e.rep_id
                 WHERE e.kind IN ($cash) AND e.is_visa = 0 AND e.amount <> 0 AND e.entry_date = ?
                 ORDER BY e.id", [$date]) as $r) {
                $expenses[] = ['permit_no'=>'مندوب','company'=>$r['name'] ?: '—',
                    'stage_label'=>\App\Models\Rep::kindLabel((string) $r['kind']),
                    'note'=>$r['note'],'ref_no'=>null,'amount'=>$r['amount']];
            }
        }
        if ($this->tableExists('rep_misc_expenses')) {
            $cashOnly = \App\Models\RepMiscExpense::cashOnlySql('x');
            foreach (Database::all(
                "SELECT x.amount, x.category, x.note, x.ref_no, r.name FROM rep_misc_expenses x
                 JOIN reps r ON r.id = x.rep_id
                 WHERE x.amount <> 0 AND $cashOnly AND x.exp_date = ? ORDER BY x.id", [$date]) as $r) {
                $expenses[] = ['permit_no'=>'نثرية','company'=>$r['name'] ?: '—',
                    'stage_label'=>$r['category'] ?: \App\Models\RepMiscExpense::CATEGORY,
                    'note'=>$r['note'],'ref_no'=>$r['ref_no'],'amount'=>$r['amount']];
            }
        }

        $visaRows = Database::all(
            "SELECT s.amount, s.note, s.stage_key, p.permit_no, p.company
             FROM permit_stages s JOIN permits p ON p.id = s.permit_id
             WHERE $expSql AND s.is_visa = 1 AND s.amount <> 0 AND s.entry_date = ?
             ORDER BY p.permit_no, s.id", [$date]
        );
        foreach ($visaRows as &$v) { $v['stage_label'] = Permit::label($v['stage_key']); }
        unset($v);
        foreach (Database::all(
            "SELECT amount, category, beneficiary, note FROM manual_expenses
             WHERE is_visa = 1 AND exp_date = ? ORDER BY id", [$date]) as $mv) {
            $visaRows[] = ['amount'=>$mv['amount'],'note'=>$mv['note'],
                'stage_label'=>$mv['category'] ?: 'مصروف يدوي','permit_no'=>'يدوي',
                'company'=>$mv['beneficiary'] ?: '—'];
        }
        if ($this->tableExists('rep_misc_expenses') && \App\Models\RepMiscExpense::visaReady()) {
            foreach (Database::all(
                "SELECT x.amount, x.category, x.note, r.name FROM rep_misc_expenses x
                 JOIN reps r ON r.id = x.rep_id
                 WHERE x.amount <> 0 AND x.is_visa = 1 AND x.exp_date = ? ORDER BY x.id", [$date]) as $mv) {
                $visaRows[] = ['amount'=>$mv['amount'],'note'=>$mv['note'],
                    'stage_label'=>$mv['category'] ?: \App\Models\RepMiscExpense::CATEGORY,
                    'permit_no'=>'نثرية','company'=>$mv['name'] ?: '—'];
            }
        }

        $custodyRows = Database::all(
            "SELECT b.id, b.bill_number, b.driver, b.company, b.custody, b.car_no, p.permit_no
             FROM bawalis b LEFT JOIN permits p ON p.id = b.permit_id
             WHERE b.is_legacy = 0 AND b.bill_date = ? AND b.custody > 0"
             . \App\Models\RepBillCustody::officeExcludeSql('b') . " ORDER BY b.id", [$date]
        );
        foreach ($custodyRows as &$c) {
            $c['deduction'] = Custody::billDeduction((float) $c['custody'], $c['car_no'] ?? null);
        }
        unset($c);

        $sum = static fn(array $rows, string $col): float => round(
            (float) array_sum(array_map(static fn($x) => (float) $x[$col], $rows)), 2);

        return [
            'expenses'    => $expenses,
            'visaRows'    => $visaRows,
            'custodyRows' => $custodyRows,
            'totVisa'     => $sum($visaRows, 'amount'),
            'totCustody'  => $sum($custodyRows, 'custody'),
        ];
    }

    /** وجود جدول اختياري (وحدات المناديب ممكن تكون لسه ما اترحّلتش). */
    private function tableExists(string $name): bool
    {
        static $cache = [];
        if (!array_key_exists($name, $cache)) {
            $cache[$name] = (bool) Database::scalar(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name=?', [$name]);
        }
        return $cache[$name];
    }

    private function validDate(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return false; }
        $d = \DateTime::createFromFormat('!Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }
}
