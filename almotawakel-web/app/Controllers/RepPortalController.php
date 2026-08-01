<?php
namespace App\Controllers;

use App\Core\App;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\RepAuth;
use App\Core\PrivateFile;
use App\Models\PartyDoc;
use App\Models\Permit;
use App\Models\PermitTrack;
use App\Models\RepDriverHandover;
use App\Models\PushNotify;
use App\Models\RepPortal;
use App\Models\RepSubmissionValidationException;

/**
 * بوابة المندوب على الموبايل (v35) — المسارات `/m`.
 *
 * ⚠️ دي بوابة منفصلة تمامًا عن نظام المكتب:
 *   • كل action هنا بينادي requireRep() (مش requireAuth) — تحذير 5 بيتطبّق زي
 *     ما هو: الحراسة يدوية جوّه كل action.
 *   • جلسة المندوب ما فيهاش `uid`، فمفيش أي احتمال إنه يوصل لصفحة مكتب.
 *   • الشاشة **ممنوع تعرض أي سعر بيخصّ العميل** (actual_amount) ولا رصيد
 *     الخزنة ولا أرباح — بس اللي دفعه هو وعهدته هو.
 *   • CSRF مطلوب على كل POST (بعكس رابط السائق العام /t/{token} اللي بلا
 *     حساب — هنا فيه حساب فعلاً، فمفيش سبب نتساهل).
 */
class RepPortalController extends Controller
{
    /** حارس بوابة المندوب — بديل requireAuth لكل action هنا. */
    private function requireRep(): void
    {
        if (!RepAuth::check()) { redirect('m/login'); }
    }

    // ═══════════════════════════════════════════ الدخول

    public function showLogin(): void
    {
        if (RepAuth::check()) { redirect('m'); }
        $this->render('m/login', ['title' => 'دخول المندوب'], false);
    }

    public function login(): void
    {
        $this->requireCsrf();
        $res = RepAuth::attempt(
            (string) ($_POST['username'] ?? ''),
            (string) ($_POST['pin'] ?? '')
        );
        if (!$res['ok']) {
            flash('error', $res['msg']);
            redirect('m/login');
        }
        // نسأله بيشتغل بتاريخ إمتى قبل ما يبدأ (زي بوابة المكتب) — الافتراضي النهاردة.
        redirect('m/work-date');
    }

    public function logout(): void
    {
        $this->requireCsrf();
        RepAuth::logout();
        redirect('m/login');
    }

    // ═══════════════════════════════════════════ الشاشة الرئيسية

    public function home(): void
    {
        $this->requireRep();
        $repId = RepAuth::repId();
        $tab = (string) ($_GET['tab'] ?? 'permits');
        if (!in_array($tab, ['permits', 'entries'], true)) { $tab = 'permits'; }
        $status = (string) ($_GET['status'] ?? '');
        if (!in_array($status, ['', 'pending', 'approved', 'rejected'], true)) { $status = ''; }
        $clearDraft = (string) ($_SESSION['_m_clear_draft'] ?? '');
        unset($_SESSION['_m_clear_draft']);
        $workDate = RepAuth::workDate();
        $this->render('m/home', [
            'title'       => 'شغلي',
            'tab'         => $tab,
            'status'      => $status,
            'clearDraft'  => $clearDraft,
            'workDate'    => $workDate,                // عهدة اليوم اللي كل الأرقام تحته
            'canAddPermit'=> RepPortal::canCreatePermit($repId),   // v44 — الزرار بيظهر للمسموح لهم بس
            'canWatchTeam'=> RepPortal::canWatchReps($repId),      // v47 — تبويب «المناديب» للمشرف بس
            // ⚠️ v49 — كل أرقام الشاشة دي **ليوم العمل ده وبس** (قرار المالك):
            //    عهدة اليوم والمدخلات اللي اتدخلت عليها. أي بند أو تنزيل من يوم
            //    تاني بيبان وكأنه خصم من عهدة النهاردة، وده اللي اتشال.
            'pocket'      => RepPortal::dayPocket($repId, $workDate),
            'permits'     => RepPortal::myPermits($repId),
            'submissions' => RepPortal::mySubmissions($repId, 40, $status, $workDate),
            'counts'      => RepPortal::myCounts($repId, $workDate),
            'deposits'    => RepPortal::recentDeposits($repId, 5, $workDate),   // تنبيه «نزلت عهدة» لليوم ده
        ], false);
    }

    /**
     * حالة خفيفة للإشعارات الحيّة (بينادَى بالـpolling والتطبيق مفتوح).
     * قراءة فقط، بلا أي أثر — بيرجّع JSON بأحدث أختام الأذونات/العهدة والمستني.
     */
    public function ping(): void
    {
        $this->requireRep();
        // `build` بتغذّي التحديث الإجباري من **نفس النداء** — صفر طلب زيادة.
        // بتتحطّ في الكنترولر مش في `pingState` لأنها شأن عميل لا بيانات مندوب.
        $this->json(RepPortal::pingState(RepAuth::repId(), RepAuth::workDate())
            + ['build' => \App\Core\App::buildId()]);
        exit;
    }

    /**
     * إشعارات المندوب (المرحلة ٦ من docs/15) — **قراءة فقط**.
     *
     * ⚠️ نفس مصدر الحقيقة بتاع المكتب بالحرف (`Notify::feed`)، فمستحيل الجرس
     *    يقول حاجة والشاشة تقول غيرها. والهوية من الجلسة عبر `Notify::me()`.
     *
     * ⚠️ قاعدة v35 سارية: البنود اللي بتوصل المندوب أصلاً مفيهاش سعر العميل
     *    (`Notify::ROUTES` بتوجّه له قرار بنده بس، ونصّه فيه **مبلغه هو**).
     */
    public function notifications(): void
    {
        $this->requireRep();
        [$pt, $pk] = \App\Models\Notify::me();
        $this->render('m/notifications', [
            'title'   => 'الإشعارات',
            'items'   => \App\Models\Notify::feed($pt, $pk, 60),
            'unread'  => \App\Models\Notify::unreadCount($pt, $pk),
            'snoozed' => \App\Models\Notify::snoozedCount($pt, $pk),
        ], false);
    }

    // ═══════════════════════════════════════════ تاريخ العمل

    /**
     * شاشة «بتشتغل بتاريخ إمتى؟» — بتظهر عقب الدخول، وكمان لما يدوس «غيّر»
     * من بانر الصفحة الرئيسية. نفس فكرة WorkDateController بتاع المكتب، بس
     * بجلسة المندوب (RepAuth) وشكل الموبايل.
     */
    public function workDateForm(): void
    {
        $this->requireRep();
        $this->render('m/workdate', [
            'title'   => 'تاريخ العمل',
            'current' => RepAuth::workDate(),
            'today'   => date('Y-m-d'),
        ], false);
    }

    /** حفظ تاريخ العمل ثم الرجوع للشاشة الرئيسية. */
    public function workDateSave(): void
    {
        $this->requireRep();
        $this->requireCsrf();
        $date = trim((string) ($_POST['work_date'] ?? ''));
        if (!RepAuth::setWorkDate($date)) {
            flash('error', 'التاريخ اللي اخترته مش صح — اختار يوم مش في المستقبل.');
            redirect('m/work-date');
        }
        // v49: اليوم المتقفل ينفع يتفرّج عليه، إنما الكتابة عليه مرفوضة — نقولها
        // من هنا بدل ما يملا فورم كامل ويترفض في آخر خطوة.
        $lock = \App\Models\RepCustody::dayLockReason($date);
        if ($lock !== null) {
            // ⚠️ شاشات /m بتقرا `error`/`success` بس (مش داخل الـlayout بتاع المكتب)،
            //    فاستخدام نوع تالت هنا معناه رسالة تروح في السكوت.
            flash('error', $lock);
        } elseif (RepAuth::isWorkDateToday()) {
            flash('success', 'هتشتغل بتاريخ النهاردة.');
        } else {
            flash('success', 'دلوقتي شغّال بيوم ' . dfmt($date) . ' — كل اللي هتسجّله هيتحسب على عهدة اليوم ده.');
        }
        redirect('m');
    }

    // ═══════════════════════════════════════════ متابعة المناديب (v47)

    /**
     * شاشة المشرف: عهدة كل مندوب وإدخالاته.
     *
     * ⚠️ **قراءة فقط** — مفيش هنا اعتماد ولا رفض ولا تنزيل عهدة؛ دي شغل المكتب.
     *    والحارس الحقيقي في `RepWatch::assertAllowed` (بيتنادى جوّه الموديل قبل
     *    أي قراءة)، والفحص هنا عشان نوجّه المندوب برسالة واضحة مش صفحة خطأ.
     */
    public function team(): void
    {
        $this->requireRep();
        $repId = RepAuth::repId();
        if (!RepPortal::canWatchReps($repId)) {
            flash('error', 'مالكش صلاحية تتابع المناديب. كلّم المكتب لو محتاجها.');
            redirect('m');
        }
        try {
            $data = \App\Models\RepWatch::team($repId);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('m');
            return;
        }
        $this->render('m/team', [
            'title'  => 'المناديب',
            'reps'   => $data['reps'],
            'totals' => $data['totals'],
        ], false);
    }

    /** تفصيل مندوب واحد: كل حركة طلعت من عهدته + بنوده + تنزيلاته. */
    public function teamRep(string $rid): void
    {
        $this->requireRep();
        $repId = RepAuth::repId();
        if (!RepPortal::canWatchReps($repId)) {
            flash('error', 'مالكش صلاحية تتابع المناديب. كلّم المكتب لو محتاجها.');
            redirect('m');
        }
        try {
            $data = \App\Models\RepWatch::rep($repId, (int) $rid);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('m');
            return;
        }
        if (!$data) {
            flash('error', 'المندوب ده مش موجود.');
            redirect('m/team');
        }
        $tab = (string) ($_GET['tab'] ?? 'spend');
        if (!in_array($tab, ['spend', 'entries', 'in'], true)) { $tab = 'spend'; }

        $this->render('m/team_rep', [
            'title' => $data['rep']['name'],
            'tab'   => $tab,
            'd'     => $data,
        ], false);
    }

    // ═══════════════════════════════════════════ فتح إذن جديد (v43)

    /**
     * المندوب هو اللي بيفتح الإذن/الشهادة في الغالب، فبقى يقدر يضيفه من موبايله.
     * **الفورم بلا أي رقم مالي** — النِسَب (الكشف/الضمان) شغل المكتب (RepPortal::createPermit).
     */
    public function permitForm(): void
    {
        $this->requireRep();
        // v44: الصلاحية لكل مندوب على حِدة. الحارس الحقيقي في RepPortal::createPermit،
        // وده بس عشان ما نعرضش فورم هيترفض بعد ما يملاه.
        if (!RepPortal::canCreatePermit(RepAuth::repId())) {
            flash('error', 'مالكش صلاحية تفتح إذن. كلّم المكتب لو محتاج تفتحه.');
            redirect('m');
        }
        $old = $_SESSION['_m_old'] ?? [];
        $errors = $_SESSION['_m_errors'] ?? [];
        unset($_SESSION['_m_old'], $_SESSION['_m_errors']);
        $this->render('m/permit_new', [
            'title'   => 'إذن جديد',
            'old'     => $old,
            'errors'  => $errors,
            'lookups' => \App\Models\Lookup::allGrouped(),
        ], false);
    }

    public function permitStore(): void
    {
        $this->requireRep();
        $this->requireCsrf();
        try {
            $res = RepPortal::createPermit(RepAuth::repId(), [
                'permit_type'    => (string) ($_POST['permit_type'] ?? ''),
                'permit_no'      => (string) ($_POST['permit_no'] ?? ''),
                'permit_date'    => (string) ($_POST['permit_date'] ?? ''),
                'company'        => (string) ($_POST['company'] ?? ''),
                'client'         => (string) ($_POST['client'] ?? ''),
                'destination'    => (string) ($_POST['destination'] ?? ''),
                'shipping_co'    => (string) ($_POST['shipping_co'] ?? ''),
                'agency'         => (string) ($_POST['agency'] ?? ''),
                'port'           => (string) ($_POST['port'] ?? ''),
                'containers'     => (int) ($_POST['containers'] ?? 0),
                'container_type' => (string) ($_POST['container_type'] ?? ''),
                'note'           => (string) ($_POST['note'] ?? ''),
                'newname_company'     => $_POST['newname_company'] ?? '',
                'newname_client'      => $_POST['newname_client'] ?? '',
                'newname_destination' => $_POST['newname_destination'] ?? '',
            ]);
        } catch (\Throwable $e) {
            $this->submitFailure($e, 'm/permit/new');
            return;   // submitFailure بتعمل redirect/exit؛ ده للوضوح بس
        }
        // نفس إشعار إذن المكتب بالظبط — الحدث واحد مهما كان اللي فتحه
        PushNotify::permitCreated((int) $res['id'], (string) ($_POST['permit_no'] ?? ''),
            (string) ($_POST['company'] ?? ''), (string) ($_POST['client'] ?? ''),
            'مندوب: ' . (string) ($_SESSION['rep_name'] ?? ''));
        flash('success', 'اتفتح ' . $res['label'] . ' ✓ — تقدر تسجّل عليه بنودك دلوقتي.');
        redirect('m/permit/' . $res['id']);
    }

    // ═══════════════════════════════════════════ فورم البند

    public function entryForm(string $id): void
    {
        $this->requireRep();
        $repId  = RepAuth::repId();
        $permit = RepPortal::myPermit($repId, (int) $id);
        // v43: كل إذن مفتوح ظاهر للكل، فالسبب الوحيد إنه مش موجود هنا = اتقفل أو اتمسح.
        if (!$permit) { flash('error', 'الإذن ده اتقفل أو مش موجود — كلّم المكتب.'); redirect('m'); }

        $old = $_SESSION['_m_old'] ?? [];
        $errors = $_SESSION['_m_errors'] ?? [];
        unset($_SESSION['_m_old'], $_SESSION['_m_errors']);

        // «صحّح وأعد الإرسال» ينسخ النصوص فقط؛ الصورة لا تُنسخ حفاظًا على الوضوح.
        $copyId = (int) ($_GET['copy'] ?? 0);
        if (!$old && $copyId > 0) {
            $copy = RepPortal::findMine($repId, $copyId);
            if ($copy && (int) $copy['permit_id'] === (int) $id && $copy['status'] === 'rejected') {
                $old = RepPortal::formValues($copy);
            }
        }
        $old['client_token'] = $old['client_token'] ?? bin2hex(random_bytes(16));

        $this->renderEntry($permit, $old, $errors, null);
    }

    // ═══════════════════════════════════ رحلة الشحنة من الميدان (v62)

    /**
     * شاشة تحديث حالة الرحلة على موبايل المندوب.
     *
     * ⚠️ **ولا رقم مالي فيها** (قاعدة v35: ممنوع أي مبلغ بيخصّ العميل يوصل
     *    شاشة المندوب) — دي حالة تشغيلية بحتة.
     */
    public function trackForm(string $id): void
    {
        $this->requireRep();
        $permit = RepPortal::myPermit(RepAuth::repId(), (int) $id);
        if (!$permit) { flash('error', 'الإذن ده اتقفل أو مش موجود — كلّم المكتب.'); redirect('m'); }
        if (!PermitTrack::ready()) { flash('error', 'تتبّع الرحلة لسه مش مفعّل.'); redirect('m/permit/' . (int) $id); }

        $pid = (int) $permit['id'];
        $events = PermitTrack::events($pid);
        $this->render('m/track', [
            'title'   => 'حالة الرحلة — ' . $permit['no'],
            'permit'  => $permit,
            'journey' => PermitTrack::summary($pid, $permit['type'] ?? null, PermitTrack::WHOLE_PERMIT, $events),
            'bills'   => PermitTrack::billSummaries($pid, $permit['type'] ?? null, $events),
        ], false);
    }

    /**
     * حفظ تحديث الحالة من الميدان.
     *
     * ⚠️ **الحارس الحقيقي `RepPortal::assertAssigned`** (الإذن مفتوح — v43)
     *    بيتنادى قبل أي كتابة؛ الفورم مش مصدر ثقة. و`PermitTrack::record`
     *    بتتأكد إن المرحلة من نوع الإذن ده وإن البوليصة تابعة له.
     *
     * ⚠️ **مفيش `Closing::guard` عن قصد** — الحدث وصفي بحت وما بيدخلش أي
     *    تقفيل، فتسجيله على يوم متقفل ما بيغيّرش أي رصيد مثبَّت (نفس قرار
     *    اعتماد بنود المناديب في v35).
     */
    public function trackStore(string $id): void
    {
        $this->requireRep();
        $this->requireCsrf();
        $repId = RepAuth::repId();
        $pid   = (int) $id;

        try {
            RepPortal::assertAssigned($repId, $pid);
            PermitTrack::record(
                $pid,
                (string) ($_POST['stage_key'] ?? ''),
                (int) ($_POST['bill_id'] ?? 0),
                PermitTrack::SRC_REP,
                (string) ($_POST['note'] ?? ''),
                RepAuth::workDate(),
                null,
                $repId
            );
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('m/permit/' . $pid . '/track');
        }

        $label = PermitTrack::labelOf((string) ($_POST['stage_key'] ?? ''));
        $permitNo = (string) \App\Core\Database::scalar('SELECT permit_no FROM permits WHERE id = ?', [$pid]);
        // ⚠️ الإشعار بعد نجاح العملية وخارج أي transaction (docs/15 §٨.٢)
        PushNotify::trackStage($pid, $permitNo, $label, (string) ($_SESSION['rep_name'] ?? 'مندوب'));
        flash('success', 'الحالة اتحدّثت: ' . $label);
        redirect('m/permit/' . $pid . '/track');
    }

    /**
     * مستندات أطراف الإذن على موبايل المندوب (v56) — يفتحها أو يحمّلها أو يطبعها.
     *
     * ⚠️ **قراءة فقط بالكامل** — مفيش فورم ولا رقم مالي (قاعدة v35: ممنوع أي
     *    مبلغ بيخصّ العميل يوصل شاشة المندوب). الحدّ الحقيقي على الملف نفسه في
     *    `FileController::partyDoc` (شرط `rep_visible`)، مش في إخفاء الزرار.
     */
    public function permitDocs(string $id): void
    {
        $this->requireRep();
        $permit = RepPortal::myPermit(RepAuth::repId(), (int) $id);
        if (!$permit) { flash('error', 'الإذن ده اتقفل أو مش موجود — كلّم المكتب.'); redirect('m'); }

        $this->render('m/docs', [
            'title'  => 'مستندات ' . $permit['no'],
            'permit' => $permit,
            'groups' => PartyDoc::forPermit($permit, true),
        ], false);
    }

    public function entryStore(string $id): void
    {
        $this->requireRep();
        $this->requireCsrf();
        $repId    = RepAuth::repId();
        $permitId = (int) $id;

        $photo = null;
        try {
            $photo = $this->handlePhoto();
            $data = $this->postedData($permitId, true);   // v49: على عهدة يوم العمل
            $data['photo'] = $photo ?? '';
            $subId = RepPortal::submit($repId, $data);

            // لو دي إعادة لنفس الطلب بعد ضياع الرد، لا نترك صورة ثانية يتيمة.
            if ($photo) {
                $saved = RepPortal::findMine($repId, $subId);
                if (($saved['photo'] ?? '') !== $photo) { $this->deletePhoto($photo); }
            }
        } catch (\Throwable $e) {
            if ($photo) { $this->deletePhoto($photo); }
            $this->submitFailure($e, 'm/permit/' . $permitId);
        }

        // إشعار للمكتب إن فيه بند مستنّي مراجعة (بعد نجاح الحفظ وخارج أي transaction)
        $this->notifyOffice($repId, $data ?? [], false);

        $draftKey = $this->draftKey('new', $permitId);
        $_SESSION['_m_clear_draft'] = $draftKey;
        flash('success', 'اتسجّل ✓ — راح للمكتب للمراجعة.');
        if ($this->wantsJson()) {
            $this->json(['ok' => true, 'id' => $subId, 'clearDraft' => $draftKey,
                         'redirect' => url('m?tab=entries&status=pending')]);
            exit;
        }
        redirect('m?tab=entries&status=pending');
    }

    // ═══════════════════════════════════════════ النثريات (v39)

    /**
     * فورم النثرية — **مصروف عام مش مربوط بأي إذن** (مواصلات، شاي، تصوير…).
     * مفيش هنا اختيار إذن ولا حركة، ومفيش أي سعر بيخصّ عميل: النثرية بتروح
     * للمصروفات وبس وبتتخصم من عهدة المندوب.
     */
    public function miscForm(): void
    {
        $this->requireRep();
        $old = $_SESSION['_m_old'] ?? [];
        $errors = $_SESSION['_m_errors'] ?? [];
        unset($_SESSION['_m_old'], $_SESSION['_m_errors']);

        // «صحّح وأعد الإرسال» لنثرية مرفوضة
        $copyId = (int) ($_GET['copy'] ?? 0);
        if (!$old && $copyId > 0) {
            $copy = RepPortal::findMine(RepAuth::repId(), $copyId);
            if ($copy && $copy['status'] === 'rejected' && RepPortal::isMisc((string) $copy['kind'])) {
                $old = RepPortal::formValues($copy);
            }
        }
        $old['client_token'] = $old['client_token'] ?? bin2hex(random_bytes(16));

        $this->renderMisc($old, $errors, null);
    }

    public function miscStore(): void
    {
        $this->requireRep();
        $this->requireCsrf();
        $repId = RepAuth::repId();

        $photo = null;
        try {
            $photo = $this->handlePhoto();
            $data = $this->postedData(0, true);   // v49: على عهدة يوم العمل
            $data['kind']  = 'misc';   // الخادم هو اللي بيقرّر، مش الفورم
            $data['photo'] = $photo ?? '';
            $subId = RepPortal::submit($repId, $data);

            if ($photo) {
                $saved = RepPortal::findMine($repId, $subId);
                if (($saved['photo'] ?? '') !== $photo) { $this->deletePhoto($photo); }
            }
        } catch (\Throwable $e) {
            if ($photo) { $this->deletePhoto($photo); }
            $this->submitFailure($e, 'm/misc');
        }

        $this->notifyOffice($repId, $data ?? [], true);

        $draftKey = $this->draftKey('misc', 0);
        $_SESSION['_m_clear_draft'] = $draftKey;
        flash('success', 'اتسجّلت النثرية ✓ — راحت للمكتب للمراجعة.');
        if ($this->wantsJson()) {
            $this->json(['ok' => true, 'id' => $subId, 'clearDraft' => $draftKey,
                         'redirect' => url('m?tab=entries&status=pending')]);
            exit;
        }
        redirect('m?tab=entries&status=pending');
    }

    /** نموذج تعديل البند المعلّق. */
    public function entryEditForm(string $sid): void
    {
        $this->requireRep();
        $repId = RepAuth::repId();
        try {
            $submission = RepPortal::assertEditable($repId, (int) $sid);
            // النثرية مالهاش إذن — فورم تاني خالص
            if (RepPortal::isMisc((string) $submission['kind'])) {
                $old = $_SESSION['_m_old'] ?? RepPortal::formValues($submission);
                $errors = $_SESSION['_m_errors'] ?? [];
                unset($_SESSION['_m_old'], $_SESSION['_m_errors']);
                $this->renderMisc($old, $errors, $submission);
                return;
            }
            $permit = RepPortal::myPermit($repId, (int) $submission['permit_id']);
            if (!$permit) { throw new \RuntimeException('الإذن ما بقاش منزّل لك؛ كلّم المكتب.'); }
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('m?tab=entries');
        }

        $old = $_SESSION['_m_old'] ?? RepPortal::formValues($submission);
        $errors = $_SESSION['_m_errors'] ?? [];
        unset($_SESSION['_m_old'], $_SESSION['_m_errors']);
        $this->renderEntry($permit, $old, $errors, $submission);
    }

    /** حفظ تعديل البند، بشرط أن المكتب لم يبدأ مراجعته. */
    public function entryUpdate(string $sid): void
    {
        $this->requireRep();
        $this->requireCsrf();
        $repId = RepAuth::repId();
        $subId = (int) $sid;
        $photo = null;
        try {
            $current = RepPortal::assertEditable($repId, $subId);
            $photo = $this->handlePhoto();
            $data = $this->postedData((int) $current['permit_id']);
            if ($photo !== null) {
                $data['photo'] = $photo;
            } elseif (!empty($_POST['remove_photo'])) {
                $data['photo'] = '';
            }
            $old = RepPortal::updateMine($repId, $subId, $data);
            if (!empty($old['photo']) && ($photo !== null || !empty($_POST['remove_photo']))) {
                $this->deletePhoto((string) $old['photo']);
            }
        } catch (\Throwable $e) {
            if ($photo) { $this->deletePhoto($photo); }
            $this->submitFailure($e, 'm/entry/' . $subId . '/edit');
        }

        $draftKey = $this->draftKey('edit', $subId);
        $_SESSION['_m_clear_draft'] = $draftKey;
        flash('success', 'اتحفظ التعديل ✓');
        if ($this->wantsJson()) {
            $this->json(['ok' => true, 'id' => $subId, 'clearDraft' => $draftKey,
                         'redirect' => url('m?tab=entries&status=pending')]);
            exit;
        }
        redirect('m?tab=entries&status=pending');
    }

    public function entryDelete(string $sid): void
    {
        $this->requireRep();
        $this->requireCsrf();
        try {
            $photo = RepPortal::deleteMine(RepAuth::repId(), (int) $sid);
            if ($photo) { $this->deletePhoto($photo); }
            flash('success', 'اتمسح.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('m?tab=entries&status=pending');
    }

    // ═══════════════════════════════════════════ تسليم عهدة لسائق (v45)

    /**
     * المندوب بيسلّم كاش لسائق على بوليصة معيّنة.
     *
     * ⚠️ **بيتخصم من عهدته على طول بلا مراجعة** (قرار المالك) — بعكس البنود
     *    اللي بتقعد pending. والأثر الوحيد هو ده: لا البوليصة ولا الخزنة ولا
     *    المصروفات بتتغيّر (شوف App\Models\RepDriverHandover).
     */
    // ═══════════════════════════════════════════ إضافة بوليصة (v60)

    /**
     * شاشة «بوليصة جديدة» — بديل شاشة «تسليم عهدة لسائق» القديمة (v45).
     *
     * بدل ما المندوب يسجّل تسليم كاش مجرّد، بقى بيضيف **البوليصة نفسها**
     * وبيكتب عهدة السائق جوّاها — فالبيان بيدخل النظام مرّة واحدة صح، والعهدة
     * بتتخصم من محفظته زي ما كان التسليم بيعمل بالظبط.
     */
    public function billForm(): void
    {
        $this->requireRep();
        $repId = RepAuth::repId();
        $old = $_SESSION['_m_old'] ?? [];
        $errors = $_SESSION['_m_errors'] ?? [];
        unset($_SESSION['_m_old'], $_SESSION['_m_errors']);

        $day = RepAuth::workDate();
        $permits = RepPortal::myPermits($repId);

        // سعة الحاويات لكل حركة (والإذن نفسه) بتتحمّل **مرة واحدة** والاختيار
        // بيفلتر بالـJS — شبكة الميناء بطيئة والمندوب ما يستناش تحميل صفحة عند
        // كل تغيير إذن. الخادم بيعيد الفحص وقت الحفظ فالقيم دي إرشاد للعرض بس.
        $caps = [];
        foreach ($permits as $p) {
            $pid = (int) $p['id'];
            $caps[$pid] = ['permit' => Permit::billContainerCapacity($pid, null)];
            foreach ($p['movements'] as $mv) {
                $caps[$pid]['mv'][(int) $mv['id']] = Permit::billContainerCapacity($pid, (int) $mv['id']);
            }
        }

        $this->render('m/bill_new', [
            'title'     => 'بوليصة جديدة',
            'old'       => $old,
            'errors'    => $errors,
            // رمز لمرة واحدة يستهلكه `claimForm` — البوليصة ما ينفعش تتسجّل مرتين
            // على شبكة بطيئة، والوسم بيخصم من عهدة المندوب مرتين لو حصل.
            'formToken' => \App\Core\FormState::token(),
            'permits'   => $permits,
            'caps'      => $caps,
            // زرار «عربية المكتب» — بيملا اللوحة والسائق وصاحب العربية بضغطة.
            // ⚠️ السائق بيتقرا من سجل السيارة (`office_cars.driver`) مش مكتوب في
            //    الكود، فالمالك بيغيّره من `/cars` بلا تعديل كود.
            'officeCars' => \App\Models\OfficeCar::available()
                ? \App\Models\OfficeCar::fillPresets() : [],
            'recent'    => RepPortal::myBills($repId, $day),
            'pocket'    => RepPortal::dayPocket($repId, $day),
            'workDate'  => $day,
        ], false);
    }

    public function billStore(): void
    {
        $this->requireRep();
        $this->requireCsrf();
        $repId = RepAuth::repId();
        $this->claimForm('m/bill/new');

        try {
            $res = RepPortal::createBill($repId, [
                'permit_id'       => (int) ($_POST['permit_id'] ?? 0),
                'movement_id'     => (int) ($_POST['movement_id'] ?? 0),
                'bill_number'     => (string) ($_POST['bill_number'] ?? ''),
                'container_count' => (int) ($_POST['container_count'] ?? 0),
                'container_type'  => (string) ($_POST['container_type'] ?? ''),
                // bootstrap بيلمّ `container_no[]` في نصّ واحد قبل ما نوصل هنا
                'container_no'    => (string) ($_POST['container_no'] ?? ''),
                'driver'          => (string) ($_POST['driver'] ?? ''),
                'car_no'          => (string) ($_POST['car_no'] ?? ''),
                'car_owner'       => (string) ($_POST['car_owner'] ?? ''),
                'custody'         => (string) ($_POST['custody'] ?? ''),
                // ⚠️ التاريخ من الجلسة مش من الفورم (قاعدة v49)
                'bill_date'       => RepAuth::workDate(),
                'newname_driver'    => $_POST['newname_driver'] ?? '',
                'newname_car_owner' => $_POST['newname_car_owner'] ?? '',
            ]);
        } catch (\Throwable $e) {
            $this->submitFailure($e, 'm/bill/new');
            return;   // submitFailure بتعمل redirect/exit؛ ده للوضوح بس
        }

        // ⚠️ الإشعار **بعد** نجاح العملية وخارج أي transaction (docs/15 §٨.٢)
        PushNotify::billLinked((int) $res['id'], (string) ($_POST['bill_number'] ?? ''),
            (int) $res['permit_id'], '', 'مندوب: ' . (string) ($_SESSION['rep_name'] ?? ''));
        flash('success', 'اتسجّلت ' . $res['label'] . ' ✓'
            . ((float) ($_POST['custody'] ?? 0) > 0 ? ' — العهدة اتخصمت من عهدتك.' : ''));
        redirect('m/bill/new');
    }

    /** المندوب بيمسح بوليصة سجّلها بالغلط النهاردة. */
    public function billDelete(string $bid): void
    {
        $this->requireRep();
        $this->requireCsrf();
        try {
            RepPortal::deleteMyBill(RepAuth::repId(), (int) $bid, RepAuth::workDate());
            flash('success', 'اتمسحت البوليصة — العهدة رجعت لعهدتك.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('m/bill/new');
    }

    // ═════════════════════════ «سلّمت السائق عهدته» على بوليصة موجودة (المرحلة ٣.٣)

    /**
     * الشاشة اللي رجّعت الإجراء الناقص: المكتب بيكون مسجّل البوليصة خلاص،
     * والمندوب هو اللي سلّم السائق عهدتها — فبيقول كده بضغطة.
     *
     * ⚠️ ليه دي مش نفس `/m/bill/new`: v60 بيسمح **بإنشاء بوليصة جديدة** بس،
     *    وده السبب إن استخدامه **صفر** — الغالب إن المكتب عامل البوليصة قبله.
     *    بلا الشاشة دي عهدة السائق تفضل متخصومة من **خزنة المكتب** وهو اللي
     *    دفعها، وده بالظبط الفرق اللي المرحلة ١ نضّفته وكان بيتراكم تاني.
     *
     * ⚠️ **وسم مش إدخال مبلغ** — الشرح الكامل في `RepPortal::claimableBills`.
     */
    public function billCustodyForm(): void
    {
        $this->requireRep();
        $repId = RepAuth::repId();
        $day = RepAuth::workDate();

        $this->render('m/bill_custody', [
            'title'     => 'سلّمت السائق عهدته',
            'formToken' => \App\Core\FormState::token(),
            // البوالص المتاحة للوسم + اللي وسمها هو في يوم العمل (قاعدة v49:
            // شاشة المندوب تعرض يوم العمل وبس)
            'bills'     => RepPortal::claimableBills($repId, $day),
            'mine'      => RepPortal::myBills($repId, $day),
            'pocket'    => RepPortal::dayPocket($repId, $day),
            'ready'     => \App\Models\RepBillCustody::ready(),
            'workDate'  => $day,
        ], false);
    }

    public function billCustodyStore(): void
    {
        $this->requireRep();
        $this->requireCsrf();
        // ⚠️ رمز لمرة واحدة — ضغطة مكرّرة على شبكة بطيئة كانت هتوسم البوليصة
        //    ثم ترجّع «حد سبقك وسجّلها» ورسالة مقلقة بلا سبب.
        $this->claimForm('m/bill/custody');

        try {
            $res = RepPortal::claimBillCustody(RepAuth::repId(), (int) ($_POST['bill_id'] ?? 0));
            flash('success', 'تم ✓ عهدة ' . $res['label'] . ' اتحسبت عليك — '
                . number_format($res['amount'], 0) . ' جنيه اتخصمت من عهدتك.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('m/bill/custody');
    }

    /** إلغاء وسم سجّله بالغلط — العهدة ترجع على خزنة المكتب زي ما كانت. */
    public function billCustodyUnclaim(string $bid): void
    {
        $this->requireRep();
        $this->requireCsrf();
        try {
            $res = RepPortal::unclaimBillCustody(RepAuth::repId(), (int) $bid);
            flash('success', 'اتلغى تسجيل عهدة ' . $res['label'] . ' — رجعت على المكتب.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('m/bill/custody');
    }

    /* ⚠️ v60 — شاشة «تسليم عهدة لسائق» (v45) اتشالت من بوابة المندوب بقرار
       المالك، ومكانها «بوليصة جديدة» فوق: بدل ما يسجّل تسليم كاش مجرّد، بقى
       بيضيف البوليصة نفسها وبيكتب عهدة السائق جوّاها.

       ⚠️ **جدول `rep_driver_handovers` والموديل `RepDriverHandover` ما اتلمسوش**:
       السجلات الموجودة (٨ على الإنتاج بـ٤٠,٠٠٠ ج) لسه بتتخصم من عهدة المناديب
       وبتظهر في كارت المندوب بالمكتب وفي `RepCustody::spending` بـ`src='handover'`.
       حذفها كان هيغيّر أرصدة تاريخية. اللي اتشال هو **مدخل الإنشاء من الموبايل**
       بس (المسارات والشاشة). */

    // ═══════════════════════════════════════════ تصفية العهدة مع المكتب

    /**
     * شاشة «صفّي العهدة مع المكتب» — المندوب بيسلّم كل اللي معاه ويرجع صفر.
     *
     * ⚠️ **دي الشاشة الوحيدة في البوابة اللي بتعرض رقمًا تراكميًّا** (كل الأيام)،
     *    وده مقصود: هو بيسلّم الكاش اللي في جيبه فعلاً مش عهدة يوم واحد. باقي
     *    الشاشات بتفضل على عهدة اليوم بالحرف (قاعدة v49). وبرضه **مفيش أي رقم
     *    بيخصّ العميل** هنا (قاعدة v35) — بس فلوس المندوب.
     */
    public function settleForm(): void
    {
        $this->requireRep();
        $repId = RepAuth::repId();
        $this->render('m/settle', [
            'title'    => 'تصفية العهدة مع المكتب',
            'snap'     => \App\Models\RepCustody::clearSnapshot($repId),
            'recent'   => \App\Models\RepCustody::settlements($repId),
            'pocket'   => RepPortal::dayPocket($repId, RepAuth::workDate()),
            'workDate' => RepAuth::workDate(),
        ], false);
    }

    /**
     * تنفيذ التصفية من الموبايل.
     *
     * ⚠️ **المبلغ بيتحسب في الموديل مش من الفورم** — الفورم مش مصدر ثقة، والرقم
     *    اللي في الشاشة ممكن يكون قديم لو المكتب اعتمد له بندًا وهو فاتح الصفحة.
     * ⚠️ **المندوب يصفّي بس لما يكون معاه فلوس** (موجب): الرصيد السالب معناه
     *    المكتب مدين له، وتسجيل إن المكتب دفعه قرار مكتب مش قرار مندوب.
     */
    public function settleStore(): void
    {
        $this->requireRep();
        $this->requireCsrf();
        $repId = RepAuth::repId();
        $name  = (string) ($_SESSION['rep_name'] ?? 'مندوب');
        $date  = RepAuth::workDate();
        try {
            // ⚠️ المندوب بيصفّي **كل الأيام المفتوحة** (اللي في إيده فعلاً)،
            //    وكل يوم بيتقفل على تاريخه هو فما بيلوّثش يوم تاني.
            $snap = \App\Models\RepCustody::clearSnapshot($repId);
            if (!empty($snap['blocked'])) {
                throw new \RuntimeException(
                    'فيه ' . $snap['pending_cnt'] . ' بند لسه مستني مراجعة المكتب بـ'
                    . number_format($snap['pending'], 2) . ' ج — استنى يتراجعوا الأول.');
            }
            if ($snap['amount'] < 0.005) {
                throw new \RuntimeException($snap['amount'] < -0.005
                    ? 'رصيدك سالب — يعني المكتب مستحق لك فلوس. التصفية دي بيعملها المكتب.'
                    : 'عهدتك مصفّرة أصلاً — مفيش مبلغ تسلّمه.');
            }
            $res = \App\Models\RepCustody::settleZero($repId, null, 'تصفية من المندوب', 'مندوب: ' . $name);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('m/settle');
        }

        // ⚠️ الإشعار **بعد** نجاح العملية وخارج أي transaction (docs/15 §٨.٢)
        PushNotify::repSettled($repId, $name, $res['amount'], $date, $name);
        $msg = 'اتصفّت عهدتك ✓ — سلّمت ' . number_format($res['amount'], 2) . ' ج ورصيدك بقى صفر.';
        flash('success', $msg);
        redirect('m/settle');
    }

    // ═══════════════════════════════════════════ تحويل عهدة لمندوب (v46)

    /**
     * المندوب بيحوّل كاش من عهدته لمندوب تاني.
     *
     * ⚠️ **بيتخصم من عهدته ويتزاد لعهدة التاني على طول بلا مراجعة** (زي تسليم
     *    السائق). الخزنة والمكتب ما بيتأثروش — تحويل داخلي بين المحافظ.
     */
    public function transferForm(): void
    {
        $this->requireRep();
        $repId = RepAuth::repId();
        if (!\App\Models\RepTransfer::available()) {
            flash('error', 'الخاصية دي لسه مش مفعّلة — كلّم المكتب.');
            redirect('m');
        }
        $old = $_SESSION['_m_old'] ?? [];
        $errors = $_SESSION['_m_errors'] ?? [];
        unset($_SESSION['_m_old'], $_SESSION['_m_errors']);

        // المناديب النشطين ما عدا نفسه
        $mates = [];
        foreach (\App\Models\RepCustody::activeReps() as $id => $name) {
            if ((int) $id !== $repId) { $mates[] = ['id' => (int) $id, 'name' => $name]; }
        }

        $this->render('m/transfer', [
            'title'   => 'تحويل عهدة لمندوب',
            'old'     => $old,
            'errors'  => $errors,
            // رمز لمرة واحدة يستهلكه `claimForm` في transferStore — اقرأ التعليق
            // المقابل في billForm.
            'formToken' => \App\Core\FormState::token(),
            'mates'   => $mates,
            'recent'  => \App\Models\RepTransfer::outgoingFor($repId, 10, RepAuth::workDate()),   // v49 — يوم العمل بس
            'pocket'  => RepPortal::dayPocket($repId, RepAuth::workDate()),   // v49 — عهدة اليوم
            'workDate'=> RepAuth::workDate(),
        ], false);
    }

    public function transferStore(): void
    {
        $this->requireRep();
        $this->requireCsrf();
        $repId = RepAuth::repId();

        // إرسال نفس الشاشة مرتين يُرفض بدل أن يحوّل المبلغ مرتين
        $this->claimForm('m/transfer');

        try {
            \App\Models\RepTransfer::create($repId, [
                'to_rep_id'     => (int) ($_POST['to_rep_id'] ?? 0),
                'amount'        => (string) ($_POST['amount'] ?? ''),
                'transfer_date' => RepAuth::workDate(),   // v49: بيتحرّك على عهدة يوم العمل
                'note'          => (string) ($_POST['note'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            $this->submitFailure($e, 'm/transfer');
        }
        // ⚠️ الإشعار **بعد** نجاح العملية وخارج أي transaction (docs/15 §٨.٢)
        $toName = (string) (\App\Core\Database::scalar(
            'SELECT name FROM reps WHERE id = ?', [(int) ($_POST['to_rep_id'] ?? 0)]) ?? 'زميله');
        PushNotify::repTransfer(
            (string) ($_SESSION['rep_name'] ?? 'مندوب'),
            $toName,
            (float) ($_POST['amount'] ?? 0),
            RepAuth::workDate()
        );
        flash('success', 'اتحوّل ✓ — اتخصم من عهدتك وزاد لعهدة زميلك.');
        redirect('m/transfer');
    }

    /** المندوب بيلغي تحويل سجّله بالغلط (بشرط إن الزميل ما صرفهوش). */
    public function transferDelete(string $tid): void
    {
        $this->requireRep();
        $this->requireCsrf();
        try {
            $row = \App\Models\RepTransfer::findFrom(RepAuth::repId(), (int) $tid);
            if (!$row) { throw new \RuntimeException('التحويل مش موجود.'); }
            \App\Models\RepTransfer::delete((int) $tid);
            flash('success', 'اتلغى التحويل — رجع لعهدتك.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('m/transfer');
    }

    // ═══════════════════════════════════════════ صورة الإيصال

    /** رفع صورة الإيصال — بترجع المسار النسبي أو null. */
    private function handlePhoto(): ?string
    {
        if (empty($_FILES['photo']) || ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        try {
            return PrivateFile::storeUploaded($_FILES['photo'], 'reps', PrivateFile::PHOTO_MIME, 12 * 1024 * 1024);
        } catch (\RuntimeException $e) {
            throw new RepSubmissionValidationException(['photo' => $e->getMessage()]);
        }
    }

    private function deletePhoto(string $rel): void
    {
        PrivateFile::delete($rel);
    }

    /**
     * القيم الخام؛ التحقق والتطبيع النهائي داخل RepPortal.
     *
     * ⚠️ v49: **البند الجديد بياخد يوم العمل إجباريًّا** ($forceWorkDate) — هو
     *    اليوم اللي المندوب شايف عهدته وبيسحب منها، فمينفعش الفورم يبعت يوم تاني
     *    ويخصم من عهدة يوم مش شايفه. التعديل بيفضل على تاريخ البند الأصلي.
     */
    private function postedData(int $permitId, bool $forceWorkDate = false): array
    {
        $date = $forceWorkDate
            ? RepAuth::workDate()
            : trim((string) ($_POST['entry_date'] ?? ''));
        return [
            'permit_id'   => $permitId,
            'movement_id' => (int) ($_POST['movement_id'] ?? 0),
            'kind'        => (string) ($_POST['kind'] ?? ''),
            'note'        => (string) ($_POST['note'] ?? ''),
            'qty'         => (int) ($_POST['qty'] ?? 1),
            'unit_amount' => (string) ($_POST['unit_amount'] ?? ''),
            'entry_date'  => $date,
            'ref_no'      => (string) ($_POST['ref_no'] ?? ''),
            'client_token'=> trim((string) ($_POST['client_token'] ?? '')),
            // v45 — الوسم والكارت؛ RepPortal بيتحقق إن الكارت شغّال فعلاً
            'is_visa'     => !empty($_POST['is_visa']),
            'visa_card_id'=> (int) ($_POST['visa_card_id'] ?? 0),
            // v57 — «غير مدفوع»: البند بيتسجّل على الإذن بلا خصم من عهدته ولا
            // من الخزنة. RepPortal بيفرض إنه لبنود الأذونات بس ومش مع الفيزا.
            'unpaid'      => !empty($_POST['unpaid']),
        ];
    }

    /**
     * كروت الفيزا النشطة لشاشة المندوب (v45).
     * ⚠️ الأسامي بس — **ممنوع أي رصيد يوصل شاشة المندوب** (قاعدة v35).
     */
    private function visaCards(): array
    {
        $out = [];
        foreach (\App\Models\VisaCard::all(true) as $c) {
            $out[] = ['id' => (int) $c['id'], 'name' => $c['name'], 'owner' => $c['owner']];
        }
        return $out;
    }

    private function renderEntry(array $permit, array $old, array $errors, ?array $submission): void
    {
        $repId = RepAuth::repId();
        $mode = $submission ? 'edit' : 'new';
        $keyId = $submission ? (int) $submission['id'] : (int) $permit['id'];
        $this->render('m/entry', [
            'title'       => $submission ? 'تعديل بند' : 'بند جديد',
            'permit'      => $permit,
            'visaCards'   => $this->visaCards(),
            'old'         => $old,
            'errors'      => $errors,
            'submission'  => $submission,
            'pocket'      => RepPortal::dayPocket($repId, RepAuth::workDate()),   // v49 — عهدة اليوم
            'workDate'    => RepAuth::workDate(),   // تاريخ البند = يوم العمل (إجباري للجديد)
            'draftKey'    => $this->draftKey($mode, $keyId),
        ], false);
    }

    /** شاشة النثرية — بلا إذن وبلا حركة، فمفيش $permit تتبعت للview. */
    private function renderMisc(array $old, array $errors, ?array $submission): void
    {
        $repId = RepAuth::repId();
        $this->render('m/misc', [
            'title'      => $submission ? 'تعديل نثرية' : 'نثرية جديدة',
            'visaCards'  => $this->visaCards(),
            'old'        => $old,
            'errors'     => $errors,
            'submission' => $submission,
            'pocket'     => RepPortal::dayPocket($repId, RepAuth::workDate()),   // v49 — عهدة اليوم
            'workDate'   => RepAuth::workDate(),   // تاريخ الصرف = يوم العمل (إجباري للجديد)
            'draftKey'   => $this->draftKey(
                $submission ? 'editmisc' : 'misc',
                $submission ? (int) $submission['id'] : 0
            ),
        ], false);
    }

    /**
     * رد موحّد: JSON للواجهة الحديثة، وsession+redirect كـ fallback بلا JS.
     * ⚠️ الاسم مقصود مختلف عن Controller::formFailure — دي آلية المناديب
     * (_m_old/_m_errors) مش آلية FormState بتاعة شاشات المكتب.
     */
    private function submitFailure(\Throwable $e, string $redirectTo): void
    {
        $errors = $e instanceof RepSubmissionValidationException
            ? $e->errors() : ['_form' => $e->getMessage()];
        if ($this->wantsJson()) {
            http_response_code(422);
            $this->json(['ok' => false, 'errors' => $errors]);
            exit;
        }
        $_SESSION['_m_old'] = array_diff_key($_POST, ['_csrf' => true]);
        $_SESSION['_m_errors'] = $errors;
        redirect($redirectTo);
    }

    private function draftKey(string $mode, int $id): string
    {
        return 'alm:m:draft:' . $mode . ':' . RepAuth::repId() . ':' . $id;
    }

    /**
     * إشعار للمكتب إن مندوب سجّل بندًا جديدًا مستنّي مراجعة (v51).
     *
     * ⚠️ المبلغ اللي بيتبعت هو **اللي المندوب دفعه**، مش سعر العميل — الشاشة
     *    دي أصلاً ما بتشوفش `actual_amount` (قاعدة v35).
     */
    private function notifyOffice(int $repId, array $data, bool $isMisc): void
    {
        $qty  = max(1, (int) ($data['qty'] ?? 1));
        $unit = (float) ($data['unit_amount'] ?? $data['amount'] ?? 0);
        // رقم الإذن في نص الإشعار (المرحلة ٣) عشان المكتب يعرف البند على إيه
        // من الإشعار نفسه بدل ما يفتح الصندوق يدوّر. قراءة خفيفة وفشلها مبلوع.
        $permitNo = '';
        try {
            $pid = (int) ($data['permit_id'] ?? 0);
            if ($pid > 0 && !$isMisc) {
                $permitNo = (string) (\App\Core\Database::scalar(
                    'SELECT permit_no FROM permits WHERE id = ?', [$pid]) ?? '');
            }
        } catch (\Throwable $e) { $permitNo = ''; }

        PushNotify::submissionNew(
            (string) ($_SESSION['rep_name'] ?? 'مندوب'),
            (string) ($data['note'] ?? ''),
            round($unit * $qty, 2),
            $isMisc,
            $permitNo
        );
    }
}
