<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Models\Closing;
use App\Models\Custody;
use App\Models\DailyReview;
use App\Models\Permit;
use App\Models\RepCustody;

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
            /* المرحلة ٤.٣ — «الكاش برّه البنك فين؟» مقسومًا على المحافظ.
               ⚠️ المفتاح اسمه `wallets` مش `data`/`view`/`layout` (مصيدة تصادم
                  مفاتيح `Controller::render` الموثَّقة — `extract(EXTR_SKIP)`). */
            'wallets' => Closing::walletSplit(),
            /* طلب المالك: الضغط على «عهدة مضافة» يوري **كل مندوب نزله كام وصرف
               كام** في اليوم ده.
               ⚠️ من `RepCustody::dayTotalsByRep()` نفسها اللي بتغذّي `/rep-custody`
                  والداشبورد — استعلام واحد ومفيش تعريف تاني لأي رقم. */
            'repDay'  => \App\Models\RepCustody::available()
                ? \App\Models\RepCustody::dayTotalsByRep($date) : [],
            'repNames' => Database::all('SELECT id, name FROM reps'),
            /* «استلمت كام من المناديب في اليوم ده» + إجماليه.
               ⚠️ من `settlementsOn()` — نفس جدول التسويات اللي بيقرا منه كارت
                  المندوب، فمستحيل الشاشتين يقولوا رقمين. */
            'settled'    => RepCustody::available() ? RepCustody::settlementsOn($date) : [],
            'settledSum' => RepCustody::available() ? RepCustody::settledTotalOn($date) : 0.0,
        ]);
    }

    public function printable(): void
    {
        $this->requireAuth();
        $date = $this->requestedDate();
        // ⚠️ حصيلة المراجعة اتشالت من ورقة الطباعة بقرار المالك (2026-08-03) —
        //    فما بقاش فيه داعي نحسب `DailyReview::view()` هنا أصلاً.
        $this->render('closing/print', $this->base($date) + $this->details($date), false);
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
                /* ⚠️ **`dtfmt()` على الوقت قبل ما يترحّل للمتصفح** — الشاشة بتطبع
                   `it.at` زي ما هو، والصفحة المرندَرة بتطبع `dtfmt($it['reviewed_at'])`.
                   بلا التصحيح هنا، ختم البند بيقول وقتًا بعد الضغط ووقتًا تانيًا
                   بعد الريفرش (فرق ساعة القاعدة ١٨٠ دقيقة على الإنتاج — v48). */
                'items' => array_map(
                    static fn($item) => [
                        'key' => $item['key'], 'reviewed' => $item['reviewed'], 'stale' => $item['stale'],
                        'by' => $item['reviewed_by'],
                        'at' => $item['reviewed_at'] ? dtfmt((string) $item['reviewed_at']) : null,
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

    /**
     * «استلمت الفلوس من المندوب» — بيصفّر عهدة **اليوم المعروض** ويسجّل تسوية
     * بتاريخه هو.
     *
     * ⚠️ **مفيش خانة مبلغ ولا تعريف تاني للرقم.** المبلغ بيتحسب في
     *    `RepCustody::settleZero()` من `clearSnapshot()` بعد قفل صف المندوب —
     *    الفورم مش مصدر ثقة، والمالك ممكن يكون فاتح الشاشة من نص ساعة والرصيد
     *    اتغيّر. نفس الدالة اللي بيستخدمها زرار كارت المندوب بالحرف.
     *
     * ⚠️ **التسوية بتتسجّل بتاريخ اليوم اللي بتقفله** مش تاريخ النهاردة (قاعدة
     *    2026-07-27)، فاستلام من شاشة تقفيل يوم قديم ما بيلوّثش عهدة النهاردة.
     *
     * ⚠️ **مفيش `Closing::guard`** عن قصد: الاستلام تحويل داخلي بين محفظتين
     *    (جيب المندوب ← الدرج) و**ما بيغيّرش رصيد الخزنة** — فمفيش رصيد مثبَّت
     *    بيتغيّر حتى لو اليوم متقفل. نفس منطق التصفية في `settleZero`.
     */
    public function receiveRep(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $date  = trim($_POST['date'] ?? '');
        $repId = (int) ($_POST['rep_id'] ?? 0);
        // ⚠️ `#receive` مش `#wallets` — قسم «الكاش برّه البنك» اتشال والكروت مكانه فوق.
        $back  = 'closing?date=' . urlencode($date) . '#receive';

        if (!$this->validDate($date)) { $this->fail('تاريخ غير صالح.', $back); }
        if ($repId <= 0)              { $this->fail('اختر المندوب الأول.', $back); }

        try {
            $res = RepCustody::settleZero($repId, $date, 'استلام من شاشة التقفيل', Auth::user());
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), $back);
        }

        $amount = (float) $res['amount'];
        $this->done($amount < 0
            ? 'اتسجّل إنك دفعت ' . money(abs($amount), false) . ' للمندوب، وعهدته في اليوم ده بقت صفر.'
            : 'استلمت ' . money($amount, false) . ' من المندوب، وعهدته في اليوم ده بقت صفر.',
            $back);
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
            /* ⚠️ **معنى «رصيد الخزنة» له نموذجان، والشاشة لازم تسمّي الصح.**
               مجمّع (`bankTopupActive`) = الدرج + جيوب المناديب، والعهدة النازلة
               للمندوب من البنك **بتتحسب في المرحّل**. غير مجمّع = الدرج لوحده.
               من غير العلم ده الشاشة بتقول «عهدة مضافة للدرج» على رقم فيه فلوس
               ما دخلتش الدرج أصلاً — وده كان بيتقري مرتين مع سطر التفكيك تحته. */
            'pooled'        => RepCustody::bankTopupActive(),
            // نافذة «رصيد أول اليوم» بتقول الرقم جاي من إقفال أنهي يوم (ممنوع رقم أصمّ)
            'openingFrom'   => Closing::openingSource($date),
            'prevBalance'   => $day['opening'],
            'totTopups'     => $day['topups'],
            /* ⚠️⚠️ **«عهدة مسحوبة من الدرج» خانة مستقلة في المعادلة** (طلب المالك
               2026-08-01) — وهي **جزء من `expenses`** جوّه `Closing` مش رقم زيادة،
               فبتتطرح منها هنا عشان الجمع ما يتعملش مرتين. المعادلة بتفضل مقفولة:
               `opening + topups − expenseCash − officeDep − driver = closing`.
               ⚠️ المصدر `Closing::dayOfficeDeposits()` — نفس شرط `sumExpenses`
                  بالحرف؛ أي حساب موازٍ هنا بيخلّي المعادلة ما تقفلش. */
            'totOfficeDep'  => $officeDep = Closing::dayOfficeDeposits($date),
            /* ⚠️⚠️ **«عهدة منزلة للمناديب» خانة مستقلة كمان** (طلب المالك 2026-08-01)
               — وهي **جزء من `topups`** جوّه `Closing` مش رقم زيادة، فبتتطرح من
               خانة «عهدة مضافة للدرج». المعادلة بتفضل مقفولة:
               `opening + topupsDrawer + bankToReps − officeDep − expenseCash − driver = closing`.
               ⚠️ في النموذج المجمّع `officeDep` = 0 (تحويل داخلي) وفي نموذج الدرج
                  `bankToReps` = 0 — فخانة منهم بتختفي حسب النموذج، والمعادلة صح
                  في الحالتين بلا أي شرط مكتوب بالإيد. */
            'totBankToReps' => $bankDep = Closing::dayBankDeposits($date),
            // للعرض بس — الرقم الحقيقي حتى في النموذج اللي مابيخصمهوش (السطر التاني)
            'totDrawerToReps' => Closing::dayDrawerToReps($date),
            'totTopupsDrawer' => round($day['topups'] - $bankDep, 2),
            'totExpense'    => round($day['expenses'] - $officeDep, 2),
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

        $stageSafeAmount = \App\Models\OfficeDriverStatement::stageSafeAmountSql('s');
        $expenses = Database::all(
            "SELECT s.id, $stageSafeAmount AS amount, s.ref_no, s.note, s.stage_key,
                    rp.name AS rep_name,
                    p.id AS permit_id, p.permit_no, p.company, p.client,
                    p.destination, p.shipping_co, p.agency, p.port
             FROM permit_stages s
             JOIN permits p ON p.id = s.permit_id
             LEFT JOIN reps rp ON rp.id = s.paid_by_rep_id
             WHERE $expSql AND " . \App\Models\Permit::cashOutSql('s')
             . \App\Models\RepCustody::safeChargeSql('s.entry_date', 's.paid_by_rep_id')
             . " AND $stageSafeAmount <> 0 AND s.entry_date = ?
             ORDER BY p.permit_no, s.id", [$date]
        );
        /* ⚠️ طلب المالك (2026-08-03): ورقة الطباعة تفصل **الإيصالات** عن باقي
           المصروفات والنثريات في جدولين. الوسم `grp` هنا هو المصدر الوحيد للتصنيف
           — الإيصال = `stage_key = 'receipts'` (v14)، وأي بند تاني = «نثريات
           ومصروفات». مجموع الجدولين = مجموع كل صفوف `$expenses` بالحرف. */
        foreach ($expenses as &$ex) {
            $ex['stage_label'] = Permit::label($ex['stage_key']);
            $ex['grp']         = $ex['stage_key'] === 'receipts' ? 'receipt' : 'misc';
        }
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
            /* ⚠️ الوسم (v71) بيتقرا هنا عشان الورقة تفصل نثرية **حوض مصروف
               المناديب** (مواصلات · تصوير) عن المصروف العام — والسؤال بيتجاوب
               من `Payroll::isAllowedKind()` لا بشرط مكتوب هنا. وقبل الترحيل
               العمود مش موجود، فالوسم بيرجع فاضي وكل النثريات بتفضل في الجدول
               العام زي ما كانت بالحرف. */
            $kindReady = \App\Models\RepMiscExpense::kindReady();
            $kindCol   = $kindReady ? 'x.misc_kind' : "'' AS misc_kind";
            foreach (Database::all(
                "SELECT x.amount, x.category, x.note, x.ref_no, r.name, $kindCol FROM rep_misc_expenses x
                 JOIN reps r ON r.id = x.rep_id
                 WHERE x.amount <> 0 AND $cashOnly AND x.exp_date = ?"
                 . \App\Models\RepCustody::safeChargeSql('x.exp_date')
                 . " ORDER BY x.id", [$date]) as $r) {
                $fromPool = \App\Models\Payroll::isAllowedKind($r['misc_kind'] ?? null);
                $expenses[] = ['permit_no'=>'نثرية','company'=>$r['name'] ?: '—',
                    'stage_label'=>$r['category'] ?: \App\Models\RepMiscExpense::CATEGORY,
                    'note'=>$r['note'],'ref_no'=>$r['ref_no'],'amount'=>$r['amount'],
                    'rep_name'=>$r['name'],'misc_kind'=>(string) ($r['misc_kind'] ?? ''),
                    'grp'=>$fromPool ? 'rep' : 'misc'];
            }
        }
        /* ⚠️ المرحلة ٢ — التنزيل من الدرج بقى مصروفًا نقديًّا، فلازم يظهر في ورقة
           الطباعة زي أي مصروف وإلا مجموع الجدول يخالف الرصيد المطبوع. */
        if (\App\Models\RepCustody::newSafeRuleActive() && $this->tableExists('rep_custody_deposits')) {
            foreach (Database::all(
                "SELECT d.amount, d.note, r.name FROM rep_custody_deposits d
                 JOIN reps r ON r.id = d.rep_id
                 WHERE d.amount <> 0 AND d.dep_date = ?"
                 . \App\Models\RepCustody::officeDepositSql('d.dep_date')
                 . " ORDER BY d.id", [$date]) as $r) {
                $expenses[] = ['permit_no'=>'تنزيل عهدة','company'=>$r['name'] ?: '—',
                    'stage_label'=>'كاش نزل من الدرج للمندوب',
                    'note'=>$r['note'],'ref_no'=>null,'amount'=>$r['amount']];
            }
        }

        $visaRows = Database::all(
            "SELECT s.amount, s.note, s.stage_key, s.visa_card_id, p.permit_no, p.company
             FROM permit_stages s JOIN permits p ON p.id = s.permit_id
             WHERE $expSql AND s.is_visa = 1 AND s.amount <> 0 AND s.entry_date = ?
             ORDER BY p.permit_no, s.id", [$date]
        );
        foreach ($visaRows as &$v) { $v['stage_label'] = Permit::label($v['stage_key']); }
        unset($v);
        foreach (Database::all(
            "SELECT amount, category, beneficiary, note, visa_card_id FROM manual_expenses
             WHERE is_visa = 1 AND exp_date = ? ORDER BY id", [$date]) as $mv) {
            $visaRows[] = ['amount'=>$mv['amount'],'note'=>$mv['note'],
                'stage_label'=>$mv['category'] ?: 'مصروف يدوي','permit_no'=>'يدوي',
                'company'=>$mv['beneficiary'] ?: '—','visa_card_id'=>$mv['visa_card_id']];
        }
        if ($this->tableExists('rep_misc_expenses') && \App\Models\RepMiscExpense::visaReady()) {
            foreach (Database::all(
                "SELECT x.amount, x.category, x.note, x.visa_card_id, r.name FROM rep_misc_expenses x
                 JOIN reps r ON r.id = x.rep_id
                 WHERE x.amount <> 0 AND x.is_visa = 1 AND x.exp_date = ? ORDER BY x.id", [$date]) as $mv) {
                $visaRows[] = ['amount'=>$mv['amount'],'note'=>$mv['note'],
                    'stage_label'=>$mv['category'] ?: \App\Models\RepMiscExpense::CATEGORY,
                    'permit_no'=>'نثرية','company'=>$mv['name'] ?: '—',
                    'visa_card_id'=>$mv['visa_card_id']];
            }
        }
        /* ⚠️ طلب المالك (2026-08-03): الورقة تقول **كل بند اتدفع بأنهي كارت** وكام
           اتسحب من كل واحد. اسم الكارت = `visa_cards.name` (هو رقم/اسم الكارت زي
           ما هو معرّف في v22) — و`VisaCard::nameMap()` بتلزق الاسم بصاحبه في نصّ
           واحد، فبنقرا العمودين هنا عشان الجدول يفصلهم. */
        $cardMap = [];
        if ($this->tableExists('visa_cards')) {
            foreach (Database::all('SELECT id, name, owner FROM visa_cards') as $c) {
                $cardMap[(int) $c['id']] = ['name' => (string) $c['name'], 'owner' => (string) ($c['owner'] ?? '')];
            }
        }
        $visaByCard = [];
        foreach ($visaRows as &$v) {
            $cid  = (int) ($v['visa_card_id'] ?? 0);
            $card = $cardMap[$cid] ?? null;
            // كارت متشال أو بند قديم بلا نسبة — بيتجمّع في دلو واضح بدل ما يختفي
            $v['card_name']  = $card['name']  ?? ($cid > 0 ? 'كارت #' . $cid : 'بدون كارت');
            $v['card_owner'] = $card['owner'] ?? '';
            $key = $cid > 0 ? $cid : 0;
            if (!isset($visaByCard[$key])) {
                $visaByCard[$key] = ['name' => $v['card_name'], 'owner' => $v['card_owner'],
                                     'count' => 0, 'total' => 0.0];
            }
            $visaByCard[$key]['count']++;
            $visaByCard[$key]['total'] += (float) $v['amount'];
        }
        unset($v);
        foreach ($visaByCard as &$vc) { $vc['total'] = round($vc['total'], 2); }
        unset($vc);
        usort($visaByCard, static fn($a, $b) => $b['total'] <=> $a['total']);

        /* ⚠️ بيشمل عهد السائقين اللي سلّمها مندوب من عهدته (تصحيح 2026-07-31) —
              `officeExcludeSql` هو اللي بيقرّر حسب نموذج الخزنة الشغّال. عمود
              «سلّمها» بيوضّح الكاش خرج من الدرج ولا من جيب المندوب. */
        $custodyRows = Database::all(
            "SELECT b.id, b.bill_number, b.driver, b.company, b.custody, b.car_no,
                    b.container_count, b.container_type, p.permit_no"
             . \App\Models\RepBillCustody::payerSelectSql() . "
             FROM bawalis b LEFT JOIN permits p ON p.id = b.permit_id"
             . \App\Models\RepBillCustody::payerJoinSql('b') . "
             WHERE b.is_legacy = 0 AND b.bill_date = ? AND b.custody > 0"
             . \App\Models\RepBillCustody::officeExcludeSql('b') . " ORDER BY b.id", [$date]
        );
        foreach ($custodyRows as &$c) {
            $c['deduction'] = Custody::billDeduction((float) $c['custody'], $c['car_no'] ?? null, $date);
            /* v30: أي عرض لتصنيف حاوية يمرّ على المصدر الموحّد، حتى لو السجل
               قديم ولسه مخزّن فيه العدد والتصنيف في نص واحد. */
            $c['containers'] = Permit::containerLabel(
                [],
                (int) ($c['container_count'] ?? 0),
                container_norm($c['container_type'] ?? null)
            );
        }
        unset($c);

        $sum = static fn(array $rows, string $col): float => round(
            (float) array_sum(array_map(static fn($x) => (float) $x[$col], $rows)), 2);

        /* المصروفات غير الإيصالات تفضل مرتبة وفقًا للإذن: الأذونات الرقمية أولًا،
           وبعدها البنود اللي مالهاش إذن (يدوي / نثرية / مندوب / تنزيل عهدة). */
        $byPermit = static function (array $a, array $b): int {
            $ka = (string) ($a['permit_no'] ?? '');
            $kb = (string) ($b['permit_no'] ?? '');
            $na = ctype_digit($ka);
            $nb = ctype_digit($kb);
            if ($na && $nb)   { return (int) $ka <=> (int) $kb; }
            if ($na !== $nb)  { return $na ? -1 : 1; }
            return strcmp($ka, $kb);
        };
        /* طلب المالك: الإيصالات والبوالص تتجمّع إجباريًّا حسب الشركة. داخل
           الشركة نستخدم رقم الإذن ثم رقم البوليصة ثم id عشان الناتج يفضل ثابتًا. */
        $byCompany = static function (array $a, array $b) use ($byPermit): int {
            $ca = search_norm(trim((string) ($a['company'] ?? '')));
            $cb = search_norm(trim((string) ($b['company'] ?? '')));
            if ($ca === '' xor $cb === '') { return $ca === '' ? 1 : -1; }
            $cmp = strnatcasecmp($ca, $cb);
            if ($cmp !== 0) { return $cmp; }
            $cmp = $byPermit($a, $b);
            if ($cmp !== 0) { return $cmp; }
            $cmp = strnatcasecmp(
                (string) ($a['bill_number'] ?? ''),
                (string) ($b['bill_number'] ?? '')
            );
            return $cmp !== 0 ? $cmp : ((int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0));
        };
        /* ⚠️ التصنيف كله بيتقرا من الوسم `grp` اللي اتحطّ فوق — تلات دلاء
           متنافية ومجموعهم = مجموع `$expenses` بالحرف: إيصال · نثرية بتتحاسب
           من حوض مصروف المناديب · أي مصروف تاني. أي صف بلا وسم بيقع على
           «عام»، فمستحيل يختفي بند من الورقة مهما كان مصدره. */
        $grpOf       = static fn(array $r): string => (string) ($r['grp'] ?? 'misc');
        $receiptRows = array_values(array_filter($expenses, static fn($r) => $grpOf($r) === 'receipt'));
        $repRows     = array_values(array_filter($expenses, static fn($r) => $grpOf($r) === 'rep'));
        $miscRows    = array_values(array_filter(
            $expenses,
            static fn($r) => !in_array($grpOf($r), ['receipt', 'rep'], true)
        ));
        usort($receiptRows, $byCompany);
        usort($custodyRows, $byCompany);
        usort($miscRows, $byPermit);
        /* مصروفات المناديب بتتجمّع حسب المندوب: الورقة بتتقرا «فلان صرف كام
           النهاردة» بدل سطور متبعترة على ترتيب الإدخال. */
        usort($repRows, static function (array $a, array $b): int {
            $cmp = strnatcasecmp(
                search_norm(trim((string) ($a['rep_name'] ?? $a['company'] ?? ''))),
                search_norm(trim((string) ($b['rep_name'] ?? $b['company'] ?? '')))
            );
            return $cmp !== 0 ? $cmp : strcmp(
                (string) ($a['stage_label'] ?? ''),
                (string) ($b['stage_label'] ?? '')
            );
        });

        return [
            'expenses'    => $expenses,
            'receiptRows' => $receiptRows,
            'repRows'     => $repRows,
            'miscRows'    => $miscRows,
            'totReceipts' => $sum($receiptRows, 'amount'),
            'totRep'      => $sum($repRows, 'amount'),
            'totMisc'     => $sum($miscRows, 'amount'),
            'visaRows'    => $visaRows,
            'visaByCard'  => array_values($visaByCard),
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
