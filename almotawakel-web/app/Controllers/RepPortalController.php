<?php
namespace App\Controllers;

use App\Core\App;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\RepAuth;
use App\Core\PrivateFile;
use App\Models\RepDriverHandover;
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
        redirect('m');
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
        $this->render('m/home', [
            'title'       => 'شغلي',
            'tab'         => $tab,
            'status'      => $status,
            'clearDraft'  => $clearDraft,
            'canAddPermit'=> RepPortal::canCreatePermit($repId),   // v44 — الزرار بيظهر للمسموح لهم بس
            'canWatchTeam'=> RepPortal::canWatchReps($repId),      // v47 — تبويب «المناديب» للمشرف بس
            'pocket'      => RepPortal::pocket($repId),
            'permits'     => RepPortal::myPermits($repId),
            'submissions' => RepPortal::mySubmissions($repId, 40, $status),
            'counts'      => RepPortal::myCounts($repId),
            'lastSettle'  => RepPortal::lastSettlement($repId),    // «بنودي» فترة جديدة بعد التسوية
            'deposits'    => RepPortal::recentDeposits($repId),    // لتنبيه «نزلت عهدة جديدة»
        ], false);
    }

    /**
     * حالة خفيفة للإشعارات الحيّة (بينادَى بالـpolling والتطبيق مفتوح).
     * قراءة فقط، بلا أي أثر — بيرجّع JSON بأحدث أختام الأذونات/العهدة والمستني.
     */
    public function ping(): void
    {
        $this->requireRep();
        $this->json(RepPortal::pingState(RepAuth::repId()));
        exit;
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

    public function entryStore(string $id): void
    {
        $this->requireRep();
        $this->requireCsrf();
        $repId    = RepAuth::repId();
        $permitId = (int) $id;

        $photo = null;
        try {
            $photo = $this->handlePhoto();
            $data = $this->postedData($permitId);
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
            $data = $this->postedData(0);
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
    public function handoverForm(): void
    {
        $this->requireRep();
        $repId = RepAuth::repId();
        if (!RepDriverHandover::available()) {
            flash('error', 'الخاصية دي لسه مش مفعّلة — كلّم المكتب.');
            redirect('m');
        }
        $old = $_SESSION['_m_old'] ?? [];
        $errors = $_SESSION['_m_errors'] ?? [];
        unset($_SESSION['_m_old'], $_SESSION['_m_errors']);

        $permits = RepPortal::myPermits($repId);
        // بوالص كل إذن مفتوح — بتتحمّل مرة واحدة والاختيار بيفلتر بالـJS، عشان
        // المندوب ما يستناش تحميل صفحة عند كل تغيير إذن (الشبكة في الميناء بطيئة).
        $bills = [];
        foreach ($permits as $p) {
            $rows = RepDriverHandover::billsOf((int) $p['id']);
            if ($rows) { $bills[(int) $p['id']] = $rows; }
        }

        $this->render('m/handover', [
            'title'     => 'تسليم عهدة لسائق',
            'old'       => $old,
            'errors'    => $errors,
            'permits'   => $permits,
            'bills'     => $bills,
            'recent'    => RepDriverHandover::forRep($repId, 10),
            'pocket'    => RepPortal::pocket($repId),
        ], false);
    }

    public function handoverStore(): void
    {
        $this->requireRep();
        $this->requireCsrf();
        $repId = RepAuth::repId();

        $photo = null;
        try {
            $photo = $this->handlePhoto();
            RepDriverHandover::create($repId, [
                'bawali_id'     => (int) ($_POST['bawali_id'] ?? 0),
                'driver_name'   => (string) ($_POST['driver_name'] ?? ''),
                'car_no'        => (string) ($_POST['car_no'] ?? ''),
                'amount'        => (string) ($_POST['amount'] ?? ''),
                'handover_date' => trim((string) ($_POST['handover_date'] ?? '')),
                'note'          => (string) ($_POST['note'] ?? ''),
                'attachment'    => $photo ?? '',
            ]);
        } catch (\Throwable $e) {
            if ($photo) { $this->deletePhoto($photo); }
            $this->submitFailure($e, 'm/handover');
        }

        flash('success', 'اتسجّل التسليم ✓ — اتخصم من عهدتك.');
        redirect('m/handover');
    }

    /** المندوب بيمسح تسليم سجّله بالغلط. */
    public function handoverDelete(string $hid): void
    {
        $this->requireRep();
        $this->requireCsrf();
        try {
            $row = RepDriverHandover::findMine(RepAuth::repId(), (int) $hid);
            if (!$row) { throw new \RuntimeException('التسليم مش موجود.'); }
            $photo = RepDriverHandover::delete((int) $hid);
            if ($photo) { $this->deletePhoto($photo); }
            flash('success', 'اتمسح التسليم — رجع لعهدتك.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('m/handover');
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
            'mates'   => $mates,
            'recent'  => \App\Models\RepTransfer::outgoingFor($repId, 10),
            'pocket'  => RepPortal::pocket($repId),
        ], false);
    }

    public function transferStore(): void
    {
        $this->requireRep();
        $this->requireCsrf();
        $repId = RepAuth::repId();
        try {
            \App\Models\RepTransfer::create($repId, [
                'to_rep_id'     => (int) ($_POST['to_rep_id'] ?? 0),
                'amount'        => (string) ($_POST['amount'] ?? ''),
                'transfer_date' => trim((string) ($_POST['transfer_date'] ?? '')),
                'note'          => (string) ($_POST['note'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            $this->submitFailure($e, 'm/transfer');
        }
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

    /** القيم الخام؛ التحقق والتطبيع النهائي داخل RepPortal. */
    private function postedData(int $permitId): array
    {
        return [
            'permit_id'   => $permitId,
            'movement_id' => (int) ($_POST['movement_id'] ?? 0),
            'kind'        => (string) ($_POST['kind'] ?? ''),
            'note'        => (string) ($_POST['note'] ?? ''),
            'qty'         => (int) ($_POST['qty'] ?? 1),
            'unit_amount' => (string) ($_POST['unit_amount'] ?? ''),
            'entry_date'  => trim((string) ($_POST['entry_date'] ?? '')),
            'ref_no'      => (string) ($_POST['ref_no'] ?? ''),
            'client_token'=> trim((string) ($_POST['client_token'] ?? '')),
            // v45 — الوسم والكارت؛ RepPortal بيتحقق إن الكارت شغّال فعلاً
            'is_visa'     => !empty($_POST['is_visa']),
            'visa_card_id'=> (int) ($_POST['visa_card_id'] ?? 0),
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
            'pocket'      => RepPortal::pocket($repId),
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
            'pocket'     => RepPortal::pocket($repId),
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
}
