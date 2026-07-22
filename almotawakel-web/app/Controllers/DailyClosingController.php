<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Models\Closing;
use App\Models\Custody;
use App\Models\DailyReconciliation;
use App\Models\Permit;

/**
 * التقفيل اليومي — تقفيل يوم للشركة كاملاً (وليس لسائق).
 *
 * القاعدة الأساسية (كما اتفق عليها صاحب الشركة):
 *  • كل بند مصروف يدخل «تقفيل يوم تاريخه» (entry_date) بغضّ النظر عن تاريخ الإصدار نفسه،
 *    ويبقى مسجَّلاً على الإصدار للأبد.
 *  • عهدة السائقين = مصروف (تُخصم من التقفيل، لا تُضاف كإيراد). يُطرح مبلغ ثابت
 *    (افتراضي 100ج) من عهدة كل بوليصة قبل خصمها: المخصوم = العهدة − 100.
 *    راجع محفظة العهدة `App\Models\Custody` (صفحة «رصيد العهدة»).
 *  • الرسوم المحصّلة لا تدخل صندوق التقفيل نهائياً (قرار صاحب الشركة).
 *
 *  المرحّل = باقي سابق − مصروفات اليوم − عهد السائقين (بعد خصم 100 لكل بوليصة)
 *  (باقي سابق = رصيد افتتاحي − مصروفات الأيام السابقة − عهد سائقيها المخصومة)
 */
class DailyClosingController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();
        $this->render('closing/index', $this->compute());
    }

    public function printable(): void
    {
        $this->requireAuth();
        $this->render('closing/print', $this->compute(), false);
    }

    private function compute(): array
    {
        $date = trim($_GET['date'] ?? work_date());
        $expSql = Permit::expenseSql('s');
        $rate   = Custody::deductionRate();

        // مصروفات اليوم — كل بند مصروف تاريخه = اليوم (مهما كان تاريخ الإصدار)
        // بنود «فيزا» (is_visa=1) لا تدخل صندوق التقفيل — تُدفع بالفيزا لا نقداً — لكنها تظل في وحدة المصروفات.
        // بنود «عهدة المناديب» (paid_by_rep_id) تدخل التقفيل عادي من v42: فلوس المندوب
        // أصلها من الدرج، والتنزيل نفسه مالوش أثر، فالخصم بيحصل يوم الصرف.
        $expenses = Database::all(
            "SELECT s.id, s.amount, s.entry_date, s.ref_no, s.note, s.stage_key, s.status,
                    p.id AS permit_id, p.permit_no, p.permit_type, p.company, p.client,
                    p.destination, p.shipping_co, p.agency, p.port
             FROM permit_stages s
             JOIN permits p ON p.id = s.permit_id
             WHERE $expSql AND s.is_visa = 0 AND s.amount <> 0 AND s.entry_date = ?
             ORDER BY p.permit_no, s.id",
            [$date]
        );
        foreach ($expenses as &$ex) { $ex['stage_label'] = Permit::label($ex['stage_key']); }
        unset($ex);

        // المصاريف اليدوية المؤرَّخة باليوم — تدخل تقفيل يومها أيضاً (وحدة المصروفات) — عدا الفيزا
        $manual = Database::all(
            "SELECT id, amount, category, beneficiary, note
             FROM manual_expenses WHERE is_visa = 0 AND exp_date = ? ORDER BY id",
            [$date]
        );
        foreach ($manual as $m) {
            $expenses[] = [
                'is_manual'   => true,
                'manual_id'   => (int) $m['id'],
                'permit_id'   => null,
                'permit_no'   => 'يدوي',
                'permit_type' => null,
                'company'     => $m['beneficiary'] ?: '—',
                'client'      => null,
                'destination' => null,
                'shipping_co' => null,
                'agency'      => null,
                'port'        => null,
                'stage_key'   => 'manual',
                'stage_label' => $m['category'] ?: 'مصروف يدوي',
                'note'        => $m['note'],
                'ref_no'      => null,
                'status'      => 'done',
                'amount'      => $m['amount'],
            ];
        }

        // كاش المناديب (سُلف + دفعات تسوية) جزء من مصروفات الإقفال، لذلك يظهر
        // في جدول التفاصيل أيضًا ولا يُخصم من الرصيد في الخفاء.
        $hasRepEntries = (bool) Database::scalar(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_name='rep_entries'"
        );
        if ($hasRepEntries) {
            foreach (Database::all(
                "SELECT e.rep_id,e.kind,e.amount,e.note,r.name
                 FROM rep_entries e JOIN reps r ON r.id=e.rep_id
                 WHERE e.kind IN ('advance','settlement') AND e.is_visa=0
                   AND e.amount<>0 AND e.entry_date=? ORDER BY e.id", [$date]
            ) as $rep) {
                $expenses[] = [
                    'is_rep'      => true,
                    'rep_id'      => (int) $rep['rep_id'],
                    'permit_id'   => null,
                    'permit_no'   => 'مندوب',
                    'company'     => $rep['name'] ?: '—',
                    'stage_key'   => 'reps',
                    'stage_label' => $rep['kind'] === 'advance' ? 'سُلفة مندوب' : 'تسوية حساب مندوب',
                    'note'        => $rep['note'],
                    'ref_no'      => null,
                    'status'      => 'done',
                    'amount'      => $rep['amount'],
                ];
            }
        }

        // نثريات المناديب (v39) — مصروف شركة بلا إذن. بقت تُخصم من الخزنة في v42
        // فلازم تظهر في جدول التفاصيل ولا تُخصم من الرصيد في الخفاء.
        $hasRepMisc = (bool) Database::scalar(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_name='rep_misc_expenses'"
        );
        if ($hasRepMisc) {
            foreach (Database::all(
                // v45: الكاش بس بيدخل صندوق التقفيل. المدفوع بالفيزا بيظهر تحت
                // في جدول الفيزا المعلوماتي زي أي بند فيزا.
                "SELECT x.id,x.rep_id,x.amount,x.category,x.note,x.ref_no,r.name
                 FROM rep_misc_expenses x JOIN reps r ON r.id=x.rep_id
                 WHERE x.amount<>0 AND " . \App\Models\RepMiscExpense::cashOnlySql('x') . "
                   AND x.exp_date=? ORDER BY x.id", [$date]
            ) as $mx) {
                $expenses[] = [
                    'is_rep_misc' => true,
                    'rep_id'      => (int) $mx['rep_id'],
                    'permit_id'   => null,
                    'permit_no'   => 'نثرية',
                    'company'     => $mx['name'] ?: '—',
                    'stage_key'   => 'rep_misc',
                    'stage_label' => $mx['category'] ?: 'نثريات',
                    'note'        => $mx['note'],
                    'ref_no'      => $mx['ref_no'],
                    'status'      => 'done',
                    'amount'      => $mx['amount'],
                ];
            }
        }

        // مدفوعات الفيزا (is_visa=1) المؤرَّخة باليوم — تُدفع بالفيزا لا نقداً، فلا تدخل صندوق التقفيل،
        // لكن تُعرض في جدول صغير منفصل للاطلاع. من بنود الإصدارات + المصاريف اليدوية.
        $visaRows = Database::all(
            "SELECT s.amount, s.note, s.stage_key, p.permit_no, p.company
             FROM permit_stages s
             JOIN permits p ON p.id = s.permit_id
             WHERE $expSql AND s.is_visa = 1 AND s.amount <> 0 AND s.entry_date = ?
             ORDER BY p.permit_no, s.id",
            [$date]
        );
        foreach ($visaRows as &$v) { $v['stage_label'] = Permit::label($v['stage_key']); }
        unset($v);
        foreach (Database::all(
            "SELECT amount, category, beneficiary, note FROM manual_expenses
             WHERE is_visa = 1 AND exp_date = ? ORDER BY id", [$date]) as $mv) {
            $visaRows[] = [
                'amount'      => $mv['amount'],
                'note'        => $mv['note'],
                'stage_label' => $mv['category'] ?: 'مصروف يدوي',
                'permit_no'   => 'يدوي',
                'company'     => $mv['beneficiary'] ?: '—',
            ];
        }
        // نثريات المناديب المدفوعة بالفيزا (v45) — نفس المعاملة: معلوماتية بس.
        if ($hasRepMisc && \App\Models\RepMiscExpense::visaReady()) {
            foreach (Database::all(
                "SELECT x.amount, x.category, x.note, r.name
                 FROM rep_misc_expenses x JOIN reps r ON r.id=x.rep_id
                 WHERE x.amount<>0 AND x.is_visa=1 AND x.exp_date=? ORDER BY x.id", [$date]) as $mv) {
                $visaRows[] = [
                    'amount'      => $mv['amount'],
                    'note'        => $mv['note'],
                    'stage_label' => $mv['category'] ?: 'نثريات',
                    'permit_no'   => 'نثرية',
                    'company'     => $mv['name'] ?: '—',
                ];
            }
        }
        $totVisa = round((float) array_sum(array_map(fn($x)=>(float)$x['amount'], $visaRows)), 2);

        // عهد السائقين — عهدة بوالص تاريخها = اليوم. تُحتسب مصروفاً بعد طرح الـ100 لكل بوليصة.
        $custodyRows = Database::all(
            "SELECT b.id, b.bill_number, b.bill_date, b.driver, b.company, b.custody, b.car_no,
                    b.permit_id, p.permit_no
             FROM bawalis b LEFT JOIN permits p ON p.id = b.permit_id
             WHERE b.is_legacy = 0 AND b.bill_date = ? AND b.custody > 0
             ORDER BY b.id",
            [$date]
        );
        foreach ($custodyRows as &$c) { $c['deduction'] = Custody::billDeduction((float) $c['custody'], $c['car_no'] ?? null); }
        unset($c);

        $totExpense    = round((float) array_sum(array_map(fn($x)=>(float)$x['amount'], $expenses)), 2);
        $totCustody    = round((float) array_sum(array_map(fn($x)=>(float)$x['custody'], $custodyRows)), 2);
        $totCustodyExp = round((float) array_sum(array_map(fn($x)=>(float)$x['deduction'], $custodyRows)), 2);

        // ── نظام الإقفال اليومي والترحيل (App\Models\Closing).
        //    القيم المالية تأتي من dayView: إن كان اليوم متقفلًا تُعرض القيم المجمّدة
        //    المخزَّنة وقت الإقفال، وإلا تُحسب حيًّا انطلاقًا من آخر يوم متقفل (وتخصم المصروفات).
        $day        = Closing::dayView($date);
        $isClosed   = Closing::isClosedExact($date);
        $lastClosed = Closing::lastClosedDate();
        $canReopen  = $isClosed && $lastClosed !== null && $date === $lastClosed;

        $prevBalance = $day['opening'];                              // باقي سابق = رصيد بداية اليوم
        $totTopups   = $day['topups'];                               // عهد مضافة للرصيد اليوم
        $totOutflow  = round($day['expenses'] + $day['driver'], 2);  // صادر = مصروفات + عهد سائقين مخصومة
        $available   = round($prevBalance + $totTopups, 2);          // المتاح = باقي سابق + عهد مضافة
        $carried     = $day['closing'];                             // المرحّل لليوم التالي (مجمّد لو متقفل)
        $reconciliation = DailyReconciliation::view($date);

        return [
            'title'         => 'التقفيل اليومي',
            'date'          => $date,
            'rate'          => $rate,
            'expenses'      => $expenses,
            'visaRows'      => $visaRows,
            'totVisa'       => $totVisa,
            'custodyRows'   => $custodyRows,
            'totExpense'    => $totExpense,     // مصروفات نقدية (بنود المصروفات)
            'totCustody'    => $totCustody,     // إجمالي عهد السائقين قبل الخصم
            'totCustodyExp' => $totCustodyExp,  // عهد السائقين بعد خصم 100 (المخصوم فعلاً)
            'totTopups'     => $totTopups,      // عهد مضافة للرصيد اليوم (إيراد)
            'totOutflow'    => $totOutflow,     // الإجمالي: مصروفات + عهد سائقين مخصومة
            'prevBalance'   => $prevBalance,
            'available'     => $available,
            'carried'       => $carried,
            // ── حالة الإقفال (للعرض والأزرار)
            'isClosed'      => $isClosed,        // لهذا اليوم صفّ إقفال بالضبط
            'isLocked'      => $day['is_locked'],// يقع ضمن فترة متقفلة (لا كتابة)
            'canReopen'     => $canReopen,       // آخر يوم متقفل → يمكن فتحه
            'lastClosed'    => $lastClosed,
            'closedBy'      => $day['closed_by'],
            'closedAt'      => $day['closed_at'],
            // ── مطابقة اليدوي حسب الإذن (v28)
            'reconciliation'=> $reconciliation,
        ];
    }

    /** حفظ مطابقة الإجماليات اليدوية مع إيصالات ومصروفات كل إذن. */
    public function reconcile(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $date = trim((string) ($_POST['date'] ?? ''));
        if (!$this->validDate($date)) {
            flash('error', 'تاريخ المطابقة غير صحيح.');
            redirect('closing');
        }
        if (Closing::isLocked($date)) {
            flash('error', 'اليوم متقفل. افتح الإقفال أولًا قبل تعديل المطابقة.');
            redirect('closing?date=' . urlencode($date) . '#reconciliation');
        }

        try {
            $totalInputs = is_array($_POST['manual_total'] ?? null) ? $_POST['manual_total'] : [];
            $noteInputs = is_array($_POST['row_note'] ?? null) ? $_POST['row_note'] : [];
            $items = [];
            foreach ($totalInputs as $key => $value) {
                if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/', $key)) { continue; }
                $items[$key] = [
                    'total' => $this->postedAmount($value, 'إجمالي العميل اليدوي'),
                    'note' => trim((string) ($noteInputs[$key] ?? '')),
                ];
            }

            $general = $this->postedAmount($_POST['manual_general_expenses'] ?? 0, 'المصروفات العامة اليدوية');
            DailyReconciliation::save(
                $date, $items, $general, trim((string) ($_POST['reconciliation_note'] ?? '')), Auth::user()
            );
            $check = DailyReconciliation::view($date);
            if ($check['matched']) {
                flash('success', 'تم حفظ المطابقة: كل إيصالات ومصروفات اليوم مطابقة للسيستم.');
            } else {
                flash('error', 'تم الحفظ، وما زالت هناك فروق. الصفوف غير المطابقة مميزة بالأحمر.');
            }
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('closing?date=' . urlencode($date) . '#reconciliation');
    }

    /** إقفال يوم (زر «اقفل اليوم»). */
    public function close(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $date = trim($_POST['date'] ?? '');
        if ($this->validDate($date)) {
            $reconciliation = DailyReconciliation::view($date);
            if (!$reconciliation['available']) {
                flash('error', 'شغّل ترحيل المطابقة v28 قبل إقفال اليوم.');
                redirect('closing?date=' . urlencode($date) . '#reconciliation');
            }
            if (!$reconciliation['matched']) {
                flash('error', 'لا يمكن إقفال اليوم قبل حفظ مطابقة الإيصالات والمصروفات بدون فروق.');
                redirect('closing?date=' . urlencode($date) . '#reconciliation');
            }
        }
        $res  = Closing::closeDay($date, Auth::user());
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

    private function validDate(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return false; }
        $d = \DateTime::createFromFormat('!Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }

    /** مبلغ إدخال يدوي غير سالب، مع دعم الأرقام العربية والفواصل. */
    private function postedAmount($value, string $label): float
    {
        $s = trim((string) $value);
        if ($s === '') { return 0.0; }
        $s = strtr($s, [
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
            '٫'=>'.','٬'=>'',','=>'', ' '=>'',
        ]);
        if (!is_numeric($s)) { throw new \InvalidArgumentException($label . ': اكتب رقمًا صحيحًا.'); }
        $amount = round((float) $s, 2);
        if ($amount < 0) { throw new \InvalidArgumentException($label . ': لا يمكن إدخال مبلغ سالب.'); }
        if ($amount > 999999999999.99) { throw new \InvalidArgumentException($label . ': المبلغ أكبر من الحد المسموح.'); }
        return $amount;
    }
}
