<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\FormState;
use App\Core\RepAuth;
use App\Models\Closing;
use App\Models\Rep;
use App\Models\RepAssignment;
use App\Models\RepCustody;
use App\Models\PushNotify;
use App\Models\RepInbox;

/**
 * عهدة المناديب (v35) — الفلوس النازلة للمناديب عشان يدفعوا بيها إيصالات الأذونات.
 *
 * ⚠️ **v42 (قرار المالك — عكس v35):** المحفظة فضلت منفصلة (رصيد كل مندوب لوحده)،
 *    لكن **وقت الخصم من الخزنة اتغيّر**:
 *      • تنزيل العهدة للمندوب → مالوش أثر على الخزنة (تحويل داخلي).
 *      • صرف المندوب المعتمَد  → بيتخصم من الخزنة **يوم صرفه** زي أي مصروف،
 *        وبيدخل التقفيل اليومي ومطابقة v28 — عشان كده الاعتماد بيمرّ بـ
 *        Closing::guard ويترفض لو اليوم متقفل.
 *      • مرتجع التسوية         → مالوش أثر (الفلوس ماخرجتش أصلاً وقت التنزيل،
 *        فترحيلها كان هيبقى تحسيبًا مزدوجًا — الخيار اتشال).
 */
class RepCustodyController extends Controller
{
    // ─────────────────────────────────────────── اللوحة
    public function index(): void
    {
        $this->requireAuth();
        if (!RepCustody::available()) {
            flash('error', 'جداول عهدة المناديب غير موجودة. شغّل php bin/migrate_v35.php أولاً.');
            redirect('reps');
        }
        $q = trim((string) ($_GET['q'] ?? ''));
        $cards = RepCustody::cards(false, $q);

        // ⚠️ الشاشة بقت **بعدسة يوم العمل** (Auth::workDate): الأرقام الكبيرة فوق
        //    وأعمدة الجدول كلها عن اليوم المختار، والتراكمي بيفضل ظاهر جنبه لأنه
        //    الحقيقة المالية (الفلوس اللي برّه مع المندوب فعلاً — قاعدة v49).
        $day = work_date();
        $dayMap = RepCustody::dayTotalsByRep($day);

        $sum = ['deposits' => 0.0, 'spent' => 0.0, 'returned' => 0.0, 'balance' => 0.0];
        $dsum = ['deposits' => 0.0, 'spent' => 0.0, 'returned' => 0.0,
                 'balance' => 0.0, 'pending' => 0.0, 'reps' => 0];
        foreach ($cards as &$c) {
            $sum['deposits'] += $c['deposits'];
            $sum['spent']    += $c['spent'];
            $sum['returned'] += $c['returned'];
            $sum['balance']  += $c['balance'];

            $d = $dayMap[(int) $c['id']] ?? null;
            $c['day_deposits'] = round((float) ($d['deposits'] ?? 0), 2);
            $c['day_spent']    = round((float) ($d['spent'] ?? 0), 2);
            $c['day_returned'] = round((float) ($d['returned'] ?? 0), 2);
            $c['day_pending']  = round((float) ($d['pending'] ?? 0), 2);
            $c['day_balance']  = round((float) ($d['balance'] ?? 0), 2);
            $c['day_active']   = ($c['day_deposits'] > 0 || $c['day_spent'] > 0
                               || $c['day_returned'] > 0 || $c['day_pending'] > 0);

            $dsum['deposits'] += $c['day_deposits'];
            $dsum['spent']    += $c['day_spent'];
            $dsum['returned'] += $c['day_returned'];
            $dsum['balance']  += $c['day_balance'];
            $dsum['pending']  += $c['day_pending'];
            if ($c['day_active']) { $dsum['reps']++; }
        }
        unset($c);
        foreach (['deposits', 'spent', 'returned', 'balance', 'pending'] as $k) {
            $dsum[$k] = round($dsum[$k], 2);
        }

        $this->render('rep_custody/index', [
            'title'  => 'عهدة المناديب',
            'cards'  => $cards,
            'sum'    => $sum,
            'dsum'   => $dsum,
            'day'    => $day,
            'locked' => RepCustody::isDayLocked($day),
            'q'      => $q,
        ]);
    }

    /**
     * تفاصيل رقم من أرقام الشاشة — تُجلب **عند الضغط بس** وتترندر جوّه النافذة.
     *
     * ⚠️ قاعدة المالك الدائمة «ممنوع رقم أصمّ»: أي رقم معروض لازم يفتح تفاصيله.
     *    التفاصيل في docs/14-design-language-plan.md §5أ.
     *
     * ⚠️ **مسار خاص بالوحدة دي — مش مسار تفاصيل عام** ياخد اسم جدول ويرجّع
     *    صفوف؛ ده كان هيبقى باب تسريب. بيمرّ على `Access::enforce` زي أي مسار
     *    (الرفض هو الافتراضي — تحذير ٥)، و`$what` محصورة في قائمة بيضا.
     *
     * ⚠️ بيرندر **بلا layout** ويرجّع HTML — مش JSON — عشان الصفوف تستخدم نفس
     *    مكوّن `row_item` بتاع الصفحة فما يبقاش فيه مُصيِّر تاني يفترق عنه.
     */
    /**
     * أنواع التفاصيل المسموح بها.
     *
     * ⚠️ **قائمة بيضا مقفولة** — مش اسم جدول جاي من المستخدم. الأربعة الأولى
     *    على مستوى كل المناديب (شاشة `/rep-custody`)، والباقي على مندوب واحد
     *    (كارته) وبيتطلبوا `?rep=` صحيح.
     */
    private const DRILL_ALL = ['deposits', 'spent', 'pending', 'balance'];
    private const DRILL_REP = [
        'rep_deposits', 'rep_spent', 'rep_pending', 'rep_receipts',
        'rep_expenses', 'rep_misc', 'rep_handover', 'rep_transfer', 'rep_visa',
    ];
    /** تفاصيل أرقام صندوق المراجعة — على **كل الأيام** مش على يوم العمل. */
    private const DRILL_INBOX = ['inbox_pending', 'inbox_locked', 'inbox_reps'];

    public function drill(string $what): void
    {
        $this->requireAuth();
        if (!in_array($what, array_merge(self::DRILL_ALL, self::DRILL_REP, self::DRILL_INBOX), true)) {
            $this->notFound('نوع التفاصيل ده مش موجود.');
        }
        $day = work_date();

        // ─── أرقام صندوق المراجعة — كل المستنّي على كل الأيام
        if (in_array($what, self::DRILL_INBOX, true)) {
            $all = RepInbox::listing('pending', 0);
            $rows = $what === 'inbox_locked'
                ? array_values(array_filter($all,
                    static fn ($r) => Closing::isLocked((string) $r['entry_date'])))
                : $all;
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=utf-8');
                header('Cache-Control: private, no-store, max-age=0');
            }
            $this->render('rep_custody/_drill', ['what' => $what, 'day' => $day, 'rows' => $rows], false);
            return;
        }

        // ─── تفاصيل مندوب واحد (كارته) — لازم `?rep=` لمندوب موجود فعلاً
        if (in_array($what, self::DRILL_REP, true)) {
            $repId = (int) ($_GET['rep'] ?? 0);
            $rep = $repId > 0 ? Rep::find($repId) : null;
            if (!$rep) { $this->notFound('المندوب ده مش موجود.'); }
            $rows = match ($what) {
                'rep_deposits' => RepCustody::deposits($repId, $day),
                'rep_pending'  => array_values(array_filter(
                    RepInbox::listing('pending', $repId),
                    static fn ($p) => (string) $p['entry_date'] === $day)),
                default        => self::repSpendRows($repId, $day, $what),
            };
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=utf-8');
                header('Cache-Control: private, no-store, max-age=0');
            }
            $this->render('rep_custody/_drill', [
                'what' => $what, 'day' => $day, 'rows' => $rows, 'rep' => $rep,
            ], false);
            return;
        }

        $rows = match ($what) {
            'deposits' => RepCustody::dayDeposits($day),
            'spent'    => RepCustody::daySpending($day),
            'pending'  => RepInbox::listing('pending', 0, $day),
            default    => self::dayBalanceRows($day),
        };

        // ⚠️ محروسة بـ`headers_sent()`: من غيرها أي مخرَج سابق (تحذير PHP طايش،
        //    أو استدعاء من فاحص) بيخلّي `header()` ترمي Warning **جوّه جسم الرد**
        //    فيوصل للنافذة HTML متلوّث — نفس الباج الموثَّق في v50.
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: private, no-store, max-age=0');
        }
        $this->render('rep_custody/_drill', ['what' => $what, 'day' => $day, 'rows' => $rows], false);
    }

    /** صفوف «الفاضل من عهدة اليوم» لكل مندوب — من `dayTotalsByRep` نفسها. */
    private static function dayBalanceRows(string $day): array
    {
        $map = RepCustody::dayTotalsByRep($day);
        if (!$map) { return []; }
        $out = [];
        foreach (RepCustody::cards(false, '') as $c) {
            $d = $map[(int) $c['id']] ?? null;
            if ($d === null) { continue; }
            $out[] = [
                'id' => (int) $c['id'], 'name' => (string) $c['name'],
                'deposits' => $d['deposits'], 'spent' => $d['spent'],
                'pending' => $d['pending'], 'balance' => $d['balance'],
            ];
        }
        usort($out, static fn ($a, $b) => $b['balance'] <=> $a['balance']);
        return $out;
    }

    /**
     * بنود صرف مندوب في يوم، مفلترة بالنوع.
     *
     * ⚠️ **ما بتكتبش استعلامًا جديدًا** — بتفلتر `RepCustody::spending()` نفسها
     *    اللي بتغذّي الكشف والعدّادات، فمستحيل النافذة تقول رقمًا والشريحة اللي
     *    فتحتها تقول غيره.
     */
    private static function repSpendRows(int $repId, string $day, string $what): array
    {
        $rows = RepCustody::spending($repId, $day);
        return array_values(array_filter($rows, static function (array $r) use ($what): bool {
            $src = (string) ($r['src'] ?? 'permit');
            $isReceipt = $src === 'permit' && (string) ($r['stage_key'] ?? '') === 'receipts';
            return match ($what) {
                'rep_receipts' => $isReceipt,
                'rep_expenses' => $src === 'permit' && !$isReceipt,
                'rep_misc'     => $src === 'misc',
                'rep_handover' => $src === 'handover',
                'rep_transfer' => $src === 'transfer',
                'rep_visa'     => !empty($r['is_visa']),
                default        => true,          // rep_spent — كل اللي صرفه
            };
        }));
    }

    // ─────────────────────────────────────────── كارت مندوب
    public function card(string $id): void
    {
        $this->requireAuth();
        $repId = (int) $id;
        $rep = Rep::find($repId);
        if (!$rep) { flash('error', 'المندوب غير موجود.'); redirect('rep-custody'); }

        // بيانات دخول لسه اتعملت دلوقتي — تُعرض مرة واحدة بس عشان تتبعت واتساب
        $cred = null;
        if (!empty($_SESSION['_rep_cred']) && (int) $_SESSION['_rep_cred']['rep_id'] === $repId) {
            $c = $_SESSION['_rep_cred'];
            $link = rtrim(public_base_url() ?: url(''), '/') . '/m';
            $msg  = RepAuth::waSetupMessage($rep['name'], $c['username'], $c['pin'], $link);
            $cred = [
                'username' => $c['username'],
                'pin'      => $c['pin'],
                'link'     => $link,
                'message'  => $msg,
                'wa'       => RepAuth::waLink((string) ($rep['phone'] ?? ''), $msg),
                'public'   => public_base_url() !== '',
            ];
        }
        unset($_SESSION['_rep_cred']);   // one-shot — عمره ما يتعرض تاني

        // ⚠️ الشاشة اتقسمت ٤ تبويبات **بروابط حقيقية** (`?tab=`) لا بالجافاسكريبت:
        //    الرجوع بالمتصفح والمشاركة والفتح في تبويب جديد بيشتغلوا، وكل تبويب
        //    بيحمّل بياناته هو بس. أي redirect بعد حفظ لازم يرجّع المالك لتبويب
        //    الفورم اللي حفظ منه — عشان كده كل مسارات الحفظ تحت بتحمل `?tab=`.
        $tab = (string) ($_GET['tab'] ?? self::TAB_DAY);
        if (!in_array($tab, self::TABS, true)) { $tab = self::TAB_DAY; }
        // بيانات الدخول one-shot: لو اتولّدت دلوقتي لازم تتعرض مهما كان التبويب
        // المطلوب، وإلا الرقم السري يضيع للأبد (متشفّر ومستحيل نستخرجه بعدين).
        if ($cred) { $tab = self::TAB_SETTINGS; }

        $day = work_date();
        $cardBack = 'rep-custody/' . $repId . '?tab=' . $tab;
        $depositState = FormState::consume('rep_custody.deposit.' . $repId, $cardBack);
        $settleState = FormState::consume('rep_custody.settle.' . $repId, $cardBack);
        $accountState = FormState::consume('rep_custody.account.' . $repId, $cardBack);
        $resetState = FormState::consume('rep_custody.account_reset.' . $repId, $cardBack);
        $assignState = FormState::consume('rep_custody.assign.' . $repId, $cardBack);

        $assigns = RepAssignment::forRep($repId);
        $pendingAll = RepInbox::listing('pending', $repId);

        $data = [
            'title'       => 'عهدة: ' . $rep['name'],
            'rep'         => $rep,
            'tab'         => $tab,
            'day'         => $day,
            'dayLocked'   => RepCustody::isDayLocked($day),
            // التراكمي = «الفلوس اللي برّه معاه فعلاً» (أساس التسوية وحد التحويل)
            'b'           => RepCustody::breakdown($repId),
            // عهدة يوم العمل لوحدها — بلا أي ترحيل من اليوم اللي قبله (v49)
            'dayB'        => RepCustody::breakdown($repId, $day),
            'pendingAll'  => $pendingAll,
            'assignCount' => count($assigns),
            'account'     => RepAuth::accountFor($repId),
            'cred'        => $cred,
            'depositState'=> $depositState,
            'settleState' => $settleState,
            'accountState'=> $accountState,
            'resetState'  => $resetState,
            'assignState' => $assignState,
        ];

        if ($tab === self::TAB_DAY) {
            $data['act']         = RepCustody::dayActivity($repId, $day);
            $data['dayDeposits'] = RepCustody::deposits($repId, $day);
            $data['dayPending']  = RepInbox::listing('pending', $repId, $day);
        } elseif ($tab === self::TAB_PERMITS) {
            $data['assigns']     = $assigns;
            $data['openPermits'] = RepAssignment::available($repId);
        } elseif ($tab === self::TAB_ACCOUNT) {
            $data['dayLedger']   = RepCustody::dayLedger($repId);
            $data['spending']    = RepCustody::spending($repId);
            $data['deposits']    = RepCustody::deposits($repId);
            $data['settlements'] = RepCustody::settlements($repId);
            $data['transfersIn'] = \App\Models\RepTransfer::incomingFor($repId);   // v46
        } else {   // settings
            $data['canAddPermit']   = Rep::canCreatePermit($repId);   // v44
            $data['permPermReady']  = Rep::permitPermReady();
            $data['canWatchTeam']   = Rep::canWatchReps($repId);      // v47
            $data['watchPermReady'] = Rep::watchPermReady();
        }

        $this->render('rep_custody/card', $data);
    }

    /** تبويبات كارت المندوب — المصدر الوحيد لأسمائها (الكنترولر والعرض معًا). */
    public const TAB_DAY = 'day';
    public const TAB_PERMITS = 'permits';
    public const TAB_ACCOUNT = 'account';
    public const TAB_SETTINGS = 'settings';
    public const TABS = [self::TAB_DAY, self::TAB_PERMITS, self::TAB_ACCOUNT, self::TAB_SETTINGS];

    /** مسار الرجوع لكارت المندوب على تبويب محدَّد (+ مرساة اختيارية). */
    private static function cardUrl(int $repId, string $tab, string $anchor = ''): string
    {
        $out = 'rep-custody/' . $repId . '?tab=' . $tab;
        return $anchor !== '' ? $out . '#' . $anchor : $out;
    }

    // ═══════════════════════════════════════════ صندوق المراجعة

    /** بنود المناديب المستنية الاعتماد — مجمّعة بالمندوب واليوم. */
    public function inbox(): void
    {
        $this->requireAuth();
        if (!RepCustody::available()) {
            flash('error', 'شغّل php bin/migrate_v35.php أولاً.');
            redirect('reps');
        }
        $status = (string) ($_GET['status'] ?? 'pending');
        if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) { $status = 'pending'; }
        $inboxBack = 'rep-custody/inbox?status=' . $status;
        $inboxState = FormState::consume('rep_custody.inbox', $inboxBack);

        $this->render('rep_custody/inbox', [
            'title'   => 'مراجعة بنود المناديب',
            'status'  => $status,
            'groups'  => $status === 'pending' ? RepInbox::pendingGrouped() : [],
            'rows'    => $status === 'pending' ? [] : RepInbox::listing($status === 'all' ? '' : $status),
            'pending' => RepInbox::pendingCount(),
            'inboxState' => $inboxState,
            // آخر يوم متقفل: البند اللي تاريخه فيه أو قبله **اعتماده هيترفض**
            // (Closing::guard جوّه RepInbox::approve). الشاشة بتنبّه قبل ما
            // المالك يضغط اعتماد ويستغرب الرفض — ودي أكتر لغبطة كانت بتحصل.
            'lockedThrough' => \App\Models\Closing::lastClosedDate(),
            'workDate'      => work_date(),
        ]);
    }

    public function approve(string $sid): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $id = (int) $sid;
        $this->claimForm($this->inboxBack());
        // بنقرا البند **قبل** القرار: بعد الاعتماد بيتغيّر، وبعد الرفض برضو —
        // ومحتاجين المندوب والمبلغ عشان الإشعار.
        $before = RepInbox::find($id);
        try {
            RepInbox::approve($id);
            $this->notifyDecision($before, true);
            flash('success', 'تم الاعتماد — البند اتحط على الإذن واتخصم من عهدة المندوب.');
        } catch (\Throwable $e) {
            $this->inboxFailure('approve', ['submission_id' => $id], ['_form' => $e->getMessage()], 'submission-' . $id);
        }
        redirect($this->inboxBack());
    }

    /** يبدأ المراجعة ويقفل تعديل/حذف البند عند المندوب. */
    public function startReview(string $sid): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $id = (int) $sid;
        $this->claimForm($this->inboxBack());
        try {
            RepInbox::startReview($id);
            flash('success', 'بدأت المراجعة — البند اتقفل عند المندوب لحد قرارك.');
        } catch (\Throwable $e) {
            $this->inboxFailure('review-start', ['submission_id' => $id], ['_form' => $e->getMessage()], 'submission-' . $id);
        }
        redirect($this->inboxBack());
    }

    public function approveMany(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $ids = $this->postedIds();
        $group = trim((string) ($_POST['_group'] ?? ''));
        if (!$ids) {
            $this->inboxFailure('approve-many', ['ids' => [], '_group' => $group],
                ['ids' => 'علّم بندًا واحدًا على الأقل.'], $group);
        }
        $this->claimForm($this->inboxBack());
        $before = $this->repCounts($ids);
        try {
            $n = RepInbox::approveMany($ids);
            $this->notifyBulk($before, true);
            flash('success', "تم اعتماد $n بند — اتحطوا على أذوناتهم واتخصموا من عهدة المندوب.");
        } catch (\Throwable $e) {
            $this->inboxFailure('approve-many', ['ids' => $ids, '_group' => $group],
                ['_form' => $e->getMessage()], $group);
        }
        redirect($this->inboxBack());
    }

    public function reject(string $sid): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $id = (int) $sid;
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($reason === '') {
            $this->inboxFailure('reject', ['submission_id' => $id, 'reason' => ''],
                ['reason' => 'اكتب سبب الرفض — المندوب هيشوفه.'], 'submission-' . $id);
        }
        $this->claimForm($this->inboxBack());
        $before = RepInbox::find($id);
        try {
            RepInbox::reject($id, $reason);
            $this->notifyDecision($before, false, $reason);
            flash('success', 'تم الرفض — المندوب هيشوف السبب على موبايله.');
        } catch (\Throwable $e) {
            $this->inboxFailure('reject', ['submission_id' => $id, 'reason' => $reason],
                ['_form' => $e->getMessage()], 'submission-' . $id);
        }
        redirect($this->inboxBack());
    }

    public function rejectMany(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $ids = $this->postedIds();
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $group = trim((string) ($_POST['_group'] ?? ''));
        $errors = [];
        if (!$ids) { $errors['ids'] = 'علّم بندًا واحدًا على الأقل.'; }
        if ($reason === '') { $errors['reason'] = 'اكتب سبب الرفض — المندوب هيشوفه.'; }
        if ($errors) {
            $this->inboxFailure('reject-many', ['ids' => $ids, 'reason' => $reason, '_group' => $group],
                $errors, $group);
        }
        $this->claimForm($this->inboxBack());
        $before = $this->repCounts($ids);
        try {
            $n = RepInbox::rejectMany($ids, $reason);
            $this->notifyBulk($before, false, $reason);
            flash('success', "تم رفض $n بند — المندوب هيشوف السبب على موبايله.");
        } catch (\Throwable $e) {
            $this->inboxFailure('reject-many', ['ids' => $ids, 'reason' => $reason, '_group' => $group],
                ['_form' => $e->getMessage()], $group);
        }
        redirect($this->inboxBack());
    }

    // ═══════════════════════════════════════════ تعديل البند قبل الاعتماد (v37)

    /** تعديل بند واحد — المالك بيصلّحه بدل ما يرفضه ويستنى المندوب يعيده. */
    public function editSubmission(string $sid): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $id = (int) $sid;
        $old = [
            'submission_id' => $id,
            'note' => trim((string) ($_POST['note'] ?? '')),
            'kind' => (string) ($_POST['kind'] ?? ''),
            'qty' => (string) ($_POST['qty'] ?? ''),
            'unit_amount' => (string) ($_POST['unit_amount'] ?? ''),
            'actual_unit' => (string) ($_POST['actual_unit'] ?? ''),
            'entry_date' => trim((string) ($_POST['entry_date'] ?? '')),
            'ref_no' => trim((string) ($_POST['ref_no'] ?? '')),
            'movement_id' => (string) ($_POST['movement_id'] ?? ''),
            // v45 — وسم الفيزا والكارت؛ RepInbox::update بيتحقق إن الكارت شغّال
            'is_visa' => !empty($_POST['is_visa']) ? '1' : '',
            'visa_card_id' => (string) ($_POST['visa_card_id'] ?? ''),
            'approve_now' => !empty($_POST['approve_now']) ? '1' : '',
        ];
        $errors = [];
        if ($old['note'] === '') { $errors['note'] = 'اكتب البيان.'; }
        elseif (mb_strlen($old['note']) > 255) { $errors['note'] = 'البيان أطول من 255 حرفًا.'; }
        if (!preg_match('/^\d+$/', $old['qty']) || (int) $old['qty'] < 1 || (int) $old['qty'] > 10000) {
            $errors['qty'] = 'الكمية لازم تكون عددًا صحيحًا من 1 لـ10000.';
        }
        if (!is_numeric($old['unit_amount']) || (float) $old['unit_amount'] <= 0) {
            $errors['unit_amount'] = 'المبلغ لازم يكون رقمًا أكبر من صفر.';
        }
        if ($old['actual_unit'] !== '' && (!is_numeric($old['actual_unit']) || (float) $old['actual_unit'] < 0)) {
            $errors['actual_unit'] = 'السعر الفعلي لازم يكون رقمًا صفرًا أو أكبر.';
        }
        if (!self::validDate($old['entry_date'])) { $errors['entry_date'] = 'التاريخ غير صحيح.'; }
        if ($errors) { $this->inboxFailure('edit', $old, $errors, 'edit-' . $id); }
        $this->claimForm($this->inboxBack());
        try {
            RepInbox::update($id, [
                'note'        => $old['note'],
                'kind'        => $old['kind'],
                'qty'         => $old['qty'],
                'unit_amount' => $old['unit_amount'],
                'actual_unit' => $old['actual_unit'],
                'entry_date'  => $old['entry_date'],
                'ref_no'      => $old['ref_no'],
                'movement_id' => $old['movement_id'],
            ]);
            // اعتماد فوري بعد التعديل لو المالك طلب كده
            if (!empty($_POST['approve_now'])) {
                RepInbox::approve($id);
                flash('success', 'اتعدّل واتعتمد — البند اتحط على الإذن واتخصم من عهدة المندوب.');
            } else {
                flash('success', 'اتحفظ التعديل. البند لسه مستني اعتمادك.');
            }
        } catch (\Throwable $e) {
            $this->inboxFailure('edit', $old, ['_form' => $e->getMessage()], 'edit-' . $id);
        }
        redirect($this->inboxBack());
    }

    /** تعديل جماعي — تاريخ/نوع/سعر فعلي لكل البنود المعلَّمة مرة واحدة. */
    public function bulkEdit(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $ids = $this->postedIds();
        $group = trim((string) ($_POST['_group'] ?? ''));
        $old = [
            'ids' => $ids,
            '_group' => $group,
            'entry_date' => trim((string) ($_POST['entry_date'] ?? '')),
            'kind' => trim((string) ($_POST['kind'] ?? '')),
            'actual_unit' => trim((string) ($_POST['actual_unit'] ?? '')),
        ];
        $errors = [];
        if (!$ids) { $errors['ids'] = 'علّم بندًا واحدًا على الأقل.'; }
        if ($old['entry_date'] === '' && $old['kind'] === '' && $old['actual_unit'] === '') {
            $errors['_form'] = 'اختر حاجة واحدة على الأقل تعدّلها.';
        }
        if ($old['entry_date'] !== '' && !self::validDate($old['entry_date'])) {
            $errors['entry_date'] = 'التاريخ غير صحيح.';
        }
        if ($old['actual_unit'] !== '' && (!is_numeric($old['actual_unit']) || (float) $old['actual_unit'] < 0)) {
            $errors['actual_unit'] = 'السعر الفعلي لازم يكون رقمًا صفرًا أو أكبر.';
        }
        if ($errors) { $this->inboxFailure('bulk-edit', $old, $errors, $group); }
        $this->claimForm($this->inboxBack());
        try {
            $n = RepInbox::bulkUpdate($ids, [
                'entry_date'  => $old['entry_date'],
                'kind'        => $old['kind'],
                'actual_unit' => $old['actual_unit'],
            ]);
            flash('success', "اتعدّل $n بند.");
        } catch (\Throwable $e) {
            $this->inboxFailure('bulk-edit', $old, ['_form' => $e->getMessage()], $group);
        }
        redirect($this->inboxBack());
    }

    // ═══════════════════════════════════════════ حساب دخول المندوب

    public function saveAccount(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $repId = (int) $id;
        if (!Rep::find($repId)) { flash('error', 'المندوب غير موجود.'); redirect('rep-custody'); }

        $username = trim((string) ($_POST['username'] ?? ''));
        $pin      = trim((string) ($_POST['pin'] ?? ''));
        // لا نمرر $_POST إلى FormState هنا: الرقم السري لا يُحفظ مطلقًا في old.
        $safeOld = ['username' => $username];
        $errors = [];
        if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) {
            $errors['username'] = 'اسم الدخول لازم يكون حروف إنجليزية وأرقام (3 خانات على الأقل) بلا مسافات.';
        }
        if ($pin === '' && !RepAuth::accountFor($repId)) {
            $errors['pin'] = 'اكتب رقم سري للحساب الجديد (من 4 لـ12 رقم).';
        } elseif ($pin !== '' && !preg_match('/^\d{4,12}$/', $pin)) {
            $errors['pin'] = 'الرقم السري لازم يكون أرقام من 4 لـ12 خانة.';
        }
        if ($errors) {
            $this->formFailure('rep_custody.account.' . $repId, self::cardUrl($repId, self::TAB_SETTINGS, 'account-form'),
                $safeOld, $errors, self::cardUrl($repId, self::TAB_SETTINGS));
        }
        $this->claimForm(self::cardUrl($repId, self::TAB_SETTINGS, 'account-form'));
        try {
            $res = RepAuth::setCredentials($repId, $username, $pin);
        } catch (\Throwable $e) {
            $this->formFailure('rep_custody.account.' . $repId, self::cardUrl($repId, self::TAB_SETTINGS, 'account-form'),
                $safeOld, ['_form' => $e->getMessage()], self::cardUrl($repId, self::TAB_SETTINGS));
        }
        if (!$res['ok']) {
            $field = str_contains((string) $res['msg'], 'اسم الدخول') ? 'username' : 'pin';
            $this->formFailure('rep_custody.account.' . $repId, self::cardUrl($repId, self::TAB_SETTINGS, 'account-form'),
                $safeOld, [$field => $res['msg']], self::cardUrl($repId, self::TAB_SETTINGS));
        }
        // بيانات الواتساب تتعرض بس لو الرقم السري اتغيّر فعلاً — لو غيّر الاسم
        // لوحده مفيش رقم سري نبعته (متشفّر ومش معانا).
        if ($res['ok'] && $res['pin_changed']) { $this->stashCred($repId, $username, $pin); }
        flash('success', $res['msg']);
        redirect(self::cardUrl($repId, self::TAB_SETTINGS));
    }

    /** يولّد رقم سري جديد ويجهّز رسالة الواتساب فورًا (بضغطة واحدة). */
    public function resetPin(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $repId = (int) $id;
        if (!Rep::find($repId)) { flash('error', 'المندوب غير موجود.'); redirect('rep-custody'); }

        $acc = RepAuth::accountFor($repId);
        $username = $acc['username'] ?? RepAuth::suggestUsername($repId);
        $pin = RepAuth::randomPin();

        $this->claimForm(self::cardUrl($repId, self::TAB_SETTINGS, 'account-reset-form'));
        try {
            $res = RepAuth::setCredentials($repId, $username, $pin);
        } catch (\Throwable $e) {
            $this->formFailure('rep_custody.account_reset.' . $repId,
                self::cardUrl($repId, self::TAB_SETTINGS, 'account-reset-form'), [], ['_form' => $e->getMessage()],
                self::cardUrl($repId, self::TAB_SETTINGS));
        }
        if (!$res['ok']) {
            $this->formFailure('rep_custody.account_reset.' . $repId,
                self::cardUrl($repId, self::TAB_SETTINGS, 'account-reset-form'), [], ['_form' => $res['msg']],
                self::cardUrl($repId, self::TAB_SETTINGS));
        }

        $this->stashCred($repId, $username, $pin);
        flash('success', 'اتعمل رقم سري جديد. ابعتهوله دلوقتي — مش هتقدر تشوفه تاني بعد ما تسيب الصفحة.');
        redirect(self::cardUrl($repId, self::TAB_SETTINGS));
    }

    /**
     * بيحطّ البيانات في الجلسة لعرضة واحدة بس.
     * ⚠️ الرقم السري متشفّر في القاعدة ومستحيل نستخرجه بعدين، فالطريقة الوحيدة
     *    لبعته إننا نمسكه في اللحظة دي. بيتمسح أول ما الصفحة تعرضه (one-shot).
     */
    private function stashCred(int $repId, string $username, string $pin): void
    {
        $_SESSION['_rep_cred'] = ['rep_id' => $repId, 'username' => $username, 'pin' => $pin];
    }

    public function toggleAccount(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $repId = (int) $id;
        $acc = RepAuth::accountFor($repId);
        if (!$acc) { flash('error', 'مفيش حساب للمندوب ده.'); redirect(self::cardUrl($repId, self::TAB_SETTINGS)); }
        $this->claimForm(self::cardUrl($repId, self::TAB_SETTINGS, 'account-form'));
        $activate = $acc['status'] !== 'active';
        RepAuth::setStatus($repId, $activate);
        flash('success', $activate ? 'تم تفعيل دخول المندوب.' : 'تم إيقاف دخول المندوب.');
        redirect(self::cardUrl($repId, self::TAB_SETTINGS));
    }

    /** المالك بيلغي تحويل عهدة بين مندوبين (v46) — مرفوض لو المستقبِل صرفه. */
    public function deleteTransfer(string $tid): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $row = \App\Models\RepTransfer::find((int) $tid);
        $back = $row ? self::cardUrl((int) $row['from_rep_id'], self::TAB_ACCOUNT) : 'rep-custody';
        try {
            \App\Models\RepTransfer::delete((int) $tid);
            flash('success', 'اتلغى التحويل — رجع لعهدة المُرسِل.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect($back);
    }

    /**
     * المالك بيدّي/بيسحب حق «فتح إذن من الموبايل» لمندوب واحد (v44).
     * ⚠️ وصفي بحت — مفيش أي أثر مالي، والمنع الحقيقي بيتفرض في
     *    `RepPortal::createPermit` مش في إخفاء الزرار.
     */
    public function togglePermitPerm(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $repId = (int) $id;
        $rep = Rep::find($repId);
        if (!$rep) { flash('error', 'المندوب غير موجود.'); redirect('rep-custody'); }
        $this->claimForm(self::cardUrl($repId, self::TAB_SETTINGS, 'permit-perm'));
        try {
            $allow = !Rep::canCreatePermit($repId);
            Rep::setCanCreatePermit($repId, $allow);
            flash('success', $allow
                ? $rep['name'] . ' بقى يقدر يفتح إذن من موبايله.'
                : $rep['name'] . ' ما بقاش يقدر يفتح إذن من موبايله.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect(self::cardUrl($repId, self::TAB_SETTINGS, 'permit-perm'));
    }

    /**
     * المالك بيدّي/بيسحب حق «متابعة المناديب من الموبايل» لمندوب واحد (v47).
     * ⚠️ وصفي بحت — الشاشة قراءة فقط، والمنع الحقيقي بيتفرض في
     *    `RepWatch::assertAllowed` مش في إخفاء التبويب.
     */
    public function toggleWatchPerm(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $repId = (int) $id;
        $rep = Rep::find($repId);
        if (!$rep) { flash('error', 'المندوب غير موجود.'); redirect('rep-custody'); }
        $this->claimForm(self::cardUrl($repId, self::TAB_SETTINGS, 'watch-perm'));
        try {
            $allow = !Rep::canWatchReps($repId);
            Rep::setCanWatchReps($repId, $allow);
            flash('success', $allow
                ? $rep['name'] . ' بقى يتابع باقي المناديب من موبايله (عرض فقط).'
                : $rep['name'] . ' ما بقاش يتابع باقي المناديب.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect(self::cardUrl($repId, self::TAB_SETTINGS, 'watch-perm'));
    }

    // ═══════════════════════════════════════════ تنزيل الأذونات

    public function assign(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $repId = (int) $id;
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['permit_ids'] ?? [])))));
        $note = trim((string) ($_POST['note'] ?? ''));
        $old = ['permit_ids' => $ids, 'note' => $note];
        $errors = [];
        if (!$ids) { $errors['permit_ids'] = 'اختر إذنًا واحدًا على الأقل.'; }
        if (mb_strlen($note) > 255) { $errors['note'] = 'الملاحظة أطول من 255 حرفًا.'; }
        if ($errors) {
            $this->formFailure('rep_custody.assign.' . $repId, self::cardUrl($repId, self::TAB_PERMITS, 'assign-form'),
                $old, $errors, self::cardUrl($repId, self::TAB_PERMITS));
        }
        $this->claimForm(self::cardUrl($repId, self::TAB_PERMITS, 'assign-form'));

        // النجاح الجزئي لا يعيد إرسال الناجح: حقيبة المحاولة التالية تحمل المعرفات المرفوضة فقط.
        $ok = 0; $failedIds = []; $bad = [];
        foreach ($ids as $pid) {
            try {
                RepAssignment::assign($pid, $repId, $note);
                $ok++;
            } catch (\Throwable $e) {
                $failedIds[] = $pid;
                $bad[] = $e->getMessage();
            }
        }
        if ($ok) {
            flash('success', 'تم تنزيل ' . $ok . ' إذن للمندوب — هيلاقيهم على موبايله فورًا.');
        }
        if ($failedIds) {
            $this->formFailure('rep_custody.assign.' . $repId, self::cardUrl($repId, self::TAB_PERMITS, 'assign-form'),
                ['permit_ids' => $failedIds, 'note' => $note],
                ['permit_ids' => 'تعذر تنزيل ' . count($failedIds) . ' إذن: ' . implode(' · ', array_slice(array_unique($bad), 0, 5))],
                self::cardUrl($repId, self::TAB_PERMITS));
        }
        redirect(self::cardUrl($repId, self::TAB_PERMITS));
    }

    public function assignDone(string $aid): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $repId = (int) ($_POST['rep_id'] ?? 0);
        $this->claimForm(self::cardUrl($repId, self::TAB_PERMITS));
        RepAssignment::markDone((int) $aid);
        flash('success', 'اتقفل التنزيل — الإذن ما بقاش يظهر على موبايل المندوب.');
        redirect(self::cardUrl($repId, self::TAB_PERMITS));
    }

    public function assignBack(string $aid): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $repId = (int) ($_POST['rep_id'] ?? 0);
        $this->claimForm(self::cardUrl($repId, self::TAB_PERMITS));
        try {
            RepAssignment::reactivate((int) $aid);
            flash('success', 'رجع التنزيل نشط.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect(self::cardUrl($repId, self::TAB_PERMITS));
    }

    public function assignRemove(string $aid): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $repId = (int) ($_POST['rep_id'] ?? 0);
        $this->claimForm(self::cardUrl($repId, self::TAB_PERMITS));
        try {
            RepAssignment::remove((int) $aid);
            flash('success', 'اتشال التنزيل.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect(self::cardUrl($repId, self::TAB_PERMITS));
    }

    /** سياق صندوق المراجعة بعد تنقيته؛ يحفظ التبويب ولا يقبل رجوعًا خارجيًا. */
    private function inboxBack(): string
    {
        return safe_back('rep-custody/inbox?status=pending', $_POST['_back'] ?? null);
    }

    /** يعيد فتح نموذج الصندوق المتعثر وحده داخل حقيبة الصفحة الموحدة. */
    private function inboxFailure(string $action, array $old, array $errors, string $anchor = ''): never
    {
        $old['_action'] = $action;
        $back = $this->inboxBack();
        $path = preg_replace('/#.*/', '', $back) ?: 'rep-custody/inbox?status=pending';
        $anchor = ltrim($anchor, '#');
        if ($anchor !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $anchor)) { $path .= '#' . $anchor; }
        $this->formFailure('rep_custody.inbox', $path, $old, $errors, $back);
    }

    /** @return int[] */
    private function postedIds(): array
    {
        return array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))));
    }

    // ─────────────────────────────────────────── تنزيل عهدة
    public function storeDeposit(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $repId = (int) $id;
        if (!Rep::find($repId)) { flash('error', 'المندوب غير موجود.'); redirect('rep-custody'); }

        $amount = round((float) ($_POST['amount'] ?? 0), 2);
        $date   = trim((string) ($_POST['dep_date'] ?? '')) ?: work_date();
        $note   = trim((string) ($_POST['note'] ?? ''));

        if ($amount <= 0) {
            $this->formFailure('rep_custody.deposit.' . $repId, self::cardUrl($repId, self::TAB_DAY, 'deposit-form'),
                $_POST, ['amount' => 'اكتب مبلغ العهدة (أكبر من صفر).'], self::cardUrl($repId, self::TAB_DAY));
        }
        if (!self::validDate($date)) {
            $this->formFailure('rep_custody.deposit.' . $repId, self::cardUrl($repId, self::TAB_DAY, 'deposit-form'),
                $_POST, ['dep_date' => 'التاريخ غير صحيح.'], self::cardUrl($repId, self::TAB_DAY));
        }
        // ملحوظة: مفيش Closing::guard هنا عن قصد — تنزيل العهدة ما بيمسّش الخزنة إطلاقًا.
        $this->claimForm(self::cardUrl($repId, self::TAB_DAY, 'deposit-form'));
        RepCustody::createDeposit($repId, $amount, $date, $note);
        flash('success', 'تم تنزيل عهدة ' . money($amount, false) . ' للمندوب.');
        redirect(self::cardUrl($repId, self::TAB_DAY));
    }

    public function deleteDeposit(string $depId): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $dep = RepCustody::findDeposit((int) $depId);
        if (!$dep) { flash('error', 'التنزيل غير موجود.'); redirect('rep-custody'); }
        $repId = (int) $dep['rep_id'];
        // الزرار موجود في تبويبين (اليوم والحساب) — نرجّع المالك للتبويب اللي حذف منه.
        $back = safe_back(self::cardUrl($repId, self::TAB_ACCOUNT), $_POST['_back'] ?? null);
        $this->claimForm($back);
        RepCustody::deleteDeposit((int) $depId);
        flash('success', 'تم حذف تنزيل العهدة.');
        redirect($back);
    }

    // ─────────────────────────────────────────── تسوية
    public function settle(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $repId = (int) $id;
        $rep = Rep::find($repId);
        if (!$rep) { flash('error', 'المندوب غير موجود.'); redirect('rep-custody'); }

        $returned  = round((float) ($_POST['returned'] ?? 0), 2);
        $date      = trim((string) ($_POST['settle_date'] ?? '')) ?: work_date();
        $note      = trim((string) ($_POST['note'] ?? ''));

        if (!self::validDate($date)) {
            $this->formFailure('rep_custody.settle.' . $repId, self::cardUrl($repId, self::TAB_ACCOUNT, 'settle-form'),
                $_POST, ['settle_date' => 'التاريخ غير صحيح.'], self::cardUrl($repId, self::TAB_ACCOUNT));
        }
        if ($returned < 0) {
            $this->formFailure('rep_custody.settle.' . $repId, self::cardUrl($repId, self::TAB_ACCOUNT, 'settle-form'),
                $_POST, ['returned' => 'المبلغ المرتجع لا يصح أن يكون سالبًا.'], self::cardUrl($repId, self::TAB_ACCOUNT));
        }
        $balance = RepCustody::balance($repId);
        if ($returned > $balance + 0.005) {
            $this->formFailure('rep_custody.settle.' . $repId, self::cardUrl($repId, self::TAB_ACCOUNT, 'settle-form'),
                $_POST, ['returned' => 'المرتجع (' . money($returned, false) . ') أكبر من الباقي مع المندوب ('
                    . money($balance, false) . '). راجع الرقم.'], self::cardUrl($repId, self::TAB_ACCOUNT));
        }
        // v42: التسوية بقت في محفظة المندوب بس ومابتمسّش الخزنة، فمفيش داعي لفحص القفل.
        // (الفلوس اللي رجعت ماخرجتش من الخزنة أصلاً — الخصم بيحصل وقت الصرف.)
        $this->claimForm(self::cardUrl($repId, self::TAB_ACCOUNT, 'settle-form'));
        RepCustody::settle($repId, $returned, $date, false, $note);
        flash('success', 'تمت التسوية وخلص حساب العهدة مع المندوب.');
        redirect(self::cardUrl($repId, self::TAB_ACCOUNT));
    }

    public function deleteSettlement(string $sid): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $row = RepCustody::findSettlement((int) $sid);
        if (!$row) { flash('error', 'التسوية غير موجودة.'); redirect('rep-custody'); }
        $repId = (int) $row['rep_id'];
        // لو المرتجع كان اترحّل للخزنة، حذفه بيغيّر رصيدها → احترم القفل
        if (!empty($row['custody_id']) && ($err = Closing::guard($row['settle_date']))) {
            flash('error', $err);
            redirect(self::cardUrl($repId, self::TAB_ACCOUNT));
        }
        $this->claimForm(self::cardUrl($repId, self::TAB_ACCOUNT));
        RepCustody::deleteSettlement((int) $sid);
        flash('success', 'تم حذف التسوية.');
        redirect(self::cardUrl($repId, self::TAB_ACCOUNT));
    }

    private static function validDate(string $v): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { return false; }
        [$y, $m, $d] = array_map('intval', explode('-', $v));
        return checkdate($m, $d, $y);
    }

    // ═══════════════════════════════════════════ إشعارات قرار البند (v51)

    /**
     * إشعار للمندوب **صاحب البند بس** بقرار المكتب (قرار المالك).
     *
     * ⚠️ قاعدة v35: اللي بيتبعت هو **مبلغ المندوب** اللي دخّله بنفسه — ممنوع
     *    `actual_unit` (سعر العميل) يوصل شاشته ولا إشعاره.
     */
    private function notifyDecision(?array $sub, bool $approved, string $reason = ''): void
    {
        if (!$sub) { return; }
        PushNotify::submissionDecided((int) $sub['rep_id'], $approved,
            (string) $sub['note'], (float) $sub['amount'], $reason);
    }

    /** إشعار واحد لكل مندوب في القرار الجماعي — مش رنّة لكل بند. */
    private function notifyBulk(array $countsByRep, bool $approved, string $reason = ''): void
    {
        foreach ($countsByRep as $repId => $count) {
            PushNotify::submissionsDecidedBulk((int) $repId, $approved, (int) $count, $reason);
        }
    }

    /** عدد البنود لكل مندوب في قايمة معلَّمة — بتتقرا **قبل** تنفيذ القرار. */
    private function repCounts(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $row = RepInbox::find((int) $id);
            if (!$row) { continue; }
            $rid = (int) $row['rep_id'];
            $out[$rid] = ($out[$rid] ?? 0) + 1;
        }
        return $out;
    }
}
