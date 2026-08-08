<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص صندوق مراجعة بنود المناديب `/rep-custody/inbox` بعد إعادة بنائه (2026-07-25).
 *
 * الفحص بيرندر الصفحة **فعليًّا** ببيانات حقيقية — مش فحص نصّي على الملف — عشان
 * يمسك الفشل الصامت الموثَّق في `Controller::render` (مفتاح بيتخطّى بـEXTR_SKIP
 * فالصفحة تعرض حاجة غلط بلا أي خطأ PHP).
 *
 * أهم الحاجات المحروسة هنا (كل واحدة كانت لغبطة فعلية قبل إعادة البناء):
 *   • **مفيش بند معلَّم افتراضيًّا** — كانت كلها `checked` فضغطة واحدة تعتمد كله.
 *   • **فورم واحد للصفحة** — كان كل يوم فورم مستقل فما ينفعش تعلّم عبر يومين.
 *   • **حقول درج التعديل مربوطة بـ`form="eNf"`** — من غيرها بتتصادم أسماؤها مع
 *     حقول الإجراء الجماعي في نفس الفورم ويبوّظوا بعض.
 *   • **`approve_now` حقل مخفي** مش `name/value` على الزرار (مصيدة v37).
 *   • **تنبيه اليوم المتقفل** ظاهر قبل الضغط — الاعتماد على يوم متقفل بيترفض.
 *
 * كله جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_rep_inbox.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;
use App\Models\RepInbox;

$pass = 0; $failures = [];
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $failures; $failures[] = $m; echo "  ✗ $m\n"; }
function chk(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function sec(string $m): void { echo "\n$m\n" . str_repeat('─', 62) . "\n"; }

echo "فحص صندوق مراجعة بنود المناديب\n" . str_repeat('=', 62) . "\n";

$pdo = Database::conn();
$treasuryBefore = Closing::currentBalance();
$lockedDay = Closing::lastClosedDate();

// يوم مفتوح للاختبار
$openDay = date('Y-m-d');
for ($i = 0; $i < 400 && $lockedDay !== null && $openDay <= $lockedDay; $i++) {
    $openDay = date('Y-m-d', strtotime("+$i day"));
}

$pdo->beginTransaction();
try {
    $tag = '__ibx_' . bin2hex(random_bytes(4));
    Database::exec("INSERT INTO reps(name,status) VALUES(?,'active')", [$tag]);
    $repId = Database::lastId();
    Database::exec(
        "INSERT INTO permits(permit_no,permit_date,status,company,client) VALUES(?,?,'open',?,?)",
        [$tag, $openDay, $tag . ' شركة', $tag . ' وكيل']);
    $permitId = Database::lastId();
    Database::exec("INSERT INTO permit_movements(permit_id,seq) VALUES(?,1)", [$permitId]);
    $movId = Database::lastId();

    $mk = static function (string $day, string $kind, string $note, int $qty, float $unit)
                 use ($repId, $permitId, $movId): int {
        $onPermit = $kind !== 'misc';
        Database::exec(
            "INSERT INTO rep_submissions
                (rep_id, client_token, permit_id, movement_id, kind, note, qty, unit_amount,
                 amount, entry_date, status)
             VALUES (?,?,?,?,?,?,?,?,?,?, 'pending')",
            [$repId, bin2hex(random_bytes(16)),
             $onPermit ? $permitId : null, $onPermit ? $movId : null,
             $kind, $note, $qty, $unit, $unit * $qty, $day]);
        return Database::lastId();
    };

    $s1 = $mk($openDay, 'receipt', $tag . ' تخصيص', 2, 150);
    $s2 = $mk($openDay, 'expense', $tag . ' مواصلات', 1, 80);
    $s3 = $mk($openDay, 'misc', $tag . ' شاي', 1, 45);
    $sLocked = $lockedDay !== null ? $mk($lockedDay, 'receipt', $tag . ' على يوم متقفل', 1, 300) : 0;

    // ═══ 1) الصفحة بترندر
    sec('1) الصفحة بترندر ببيانات حقيقية');
    $_SESSION = ['uid' => (int) Database::scalar('SELECT MIN(id) FROM users'), 'role' => 'owner',
                 'uname' => 'فحص', 'last_activity' => time(), 'uid_checked' => time()];
    $_GET['status'] = 'pending';
    ob_start();
    try { (new \App\Controllers\RepCustodyController())->inbox(); $html = (string) ob_get_clean(); }
    catch (\Throwable $e) { ob_end_clean(); $html = ''; bad('استثناء وقت الرندر: ' . $e->getMessage()); }
    $_SESSION = []; unset($_GET['status']);

    if ($html === '') {
        echo "\nالصفحة ما رندرتش — باقي الفحوصات اتخطّت.\n";
    } else {
        preg_match('/Fatal error|Warning:|Undefined variable|Undefined array key/', $html, $m)
            ? bad('خطأ PHP في الصفحة: ' . ($m[0] ?? ''))
            : ok('بترندر بلا أخطاء PHP');
        chk(str_contains($html, $tag . ' تخصيص') && str_contains($html, $tag . ' شاي'),
            'البنود المستنية ظاهرة (على إذن + نثرية)');

        sec('2) اللغبطة القديمة: ولا بند معلَّم افتراضيًّا');
        // كانت كل الـcheckboxes بـchecked، فضغطة واحدة على «اعتمد المعلَّم» تعتمد كله.
        chk(!preg_match('/class="js-ib-one"[^>]*\schecked/', $html),
            '★ مفيش بند معلَّم لوحده — المالك بيختار بوعي');
        chk(substr_count($html, 'name="ids[]"') >= 3, 'كل بند له خانة تعليم');

        sec('3) بنية الفورم — أخطر جزء في الشاشة');
        chk(substr_count($html, 'id="ibForm"') === 1, '★ فورم واحد للصفحة كلها (التعليم عبر الأيام والمناديب)');
        chk(str_contains($html, 'form="e' . $s1 . 'f"'),
            '★ حقول درج التعديل مربوطة بفورمها (مش بتتصادم مع الإجراء الجماعي)');
        chk((bool) preg_match('/<input type="hidden" name="approve_now"/', $html),
            '★ approve_now حقل مخفي مش name/value على الزرار (مصيدة v37)');
        chk(!preg_match('/<button[^>]+name="approve_now"/', $html),
            'وما اترجعش زرار بيبعت approve_now لوحده');
        foreach (['a' . $s1 . 'f', 'e' . $s1 . 'f', 'r' . $s1 . 'f'] as $fid) {
            chk(str_contains($html, 'id="' . $fid . '"'), "فورم الإجراء الفردي $fid موجود");
        }

        sec('4) التاريخ بقى إجراء من الدرجة الأولى');
        chk(str_contains($html, 'id="ibDatePanel"'), '★ لوحة تاريخ واحدة بدل البوب-أبين المتسلسلين');
        chk(str_contains($html, 'type="date" id="ibDateInput"'), '★ منتقي تواريخ حقيقي (مش كتابة YYYY-MM-DD بالإيد)');
        chk(str_contains($html, 'js-ib-day-date'), '★ زر «غيّر تاريخ اليوم» في رأس كل يوم');
        chk(str_contains($html, 'js-ib-edit-date'), 'وحقل تاريخ مستقل جوّه درج التعديل');
        chk(substr_count($html, 'ibSetDate(') >= 2, 'اختصارات النهاردة/إمبارح موجودة');

        sec('5) شريط الإجراءات والفلاتر');
        chk(str_contains($html, 'id="ibBar"'), 'شريط الإجراءات موجود');
        foreach (['ibDoApprove' => 'اعتماد', 'ibDoDate' => 'تغيير تاريخ',
                  'ibDoActual' => 'سعر فعلي', 'ibDoReject' => 'رفض'] as $id => $label) {
            chk(str_contains($html, 'id="' . $id . '"'), "إجراء جماعي: $label");
        }
        /* ⚠️ فلتر المندوب **اتنقل من المتصفح للخادم** (2026-08-02): كان
           `<select id="ibRep">` بيفلتر الصفوف المرندَرة، فالرابط من كارت
           المندوب كان بيوَدّي على كل المناديب والمالك يدوّر. بقى شرايح
           `?rep=` — الرابط يتشارك، والرجوع بالمتصفح يشتغل، وبعد كل اعتماد
           المالك بيفضل على نفس المندوب. */
        foreach (['ibSearch' => 'بحث', 'ibDay' => 'يوم', 'ibOnlyLocked' => 'المتقفل بس'] as $id => $label) {
            chk(str_contains($html, 'id="' . $id . '"'), "فلتر (متصفح): $label");
        }
        chk(!str_contains($html, 'id="ibRep"'),
            'مفيش قايمة مندوب في المتصفح — مصدر واحد للفلتر مش اتنين');

        /* ═══ 5ب) عدسة المندوب على الخادم (2026-08-02) ═══
           الشكوى: «عند الضغط على inbox الخاصة بمراجعة بنود مندوب معين يظهر كل
           المناديب». الفحص بيقيس **السلوك**: الشرايح موجودة، والرابط بـ`?rep=`
           بيرجّع بنود المندوب ده بس، والمندوب الوهمي بيرجع لكل المناديب. */
        sec('5ب) عدسة المندوب — فلترة خادمية');
        /* ⚠️ الفحص على **الماركب** (`class="ib-lens-chip"`) مش على الاسم المجرّد:
           الاسم موجود في الـCSS كمان فالبحث المجرّد بينجح كذبًا حتى لو الشرايح
           ما اترسمتش خالص. */
        $render = static function (array $q) {
            $_SESSION = ['uid' => (int) Database::scalar('SELECT MIN(id) FROM users'), 'role' => 'owner',
                         'uname' => 'فحص', 'last_activity' => time(), 'uid_checked' => time()];
            $_GET = $q;
            ob_start();
            try { (new \App\Controllers\RepCustodyController())->inbox(); $out = (string) ob_get_clean(); }
            catch (\Throwable $e) { ob_end_clean(); $out = ''; }
            $_SESSION = []; $_GET = [];
            return $out;
        };
        // مندوب تاني ببند مستني — عشان الفلترة يبقى ليها معنى
        Database::exec("INSERT INTO reps (name, status) VALUES (?, 'active')", [$tag . ' زميل']);
        $otherRep = Database::lastId();
        Database::exec(
            "INSERT INTO rep_submissions (rep_id, client_token, kind, note, qty, unit_amount, amount, entry_date, status)
             VALUES (?,?, 'misc', ?, 1, 70, 70, ?, 'pending')",
            [$otherRep, bin2hex(random_bytes(16)), $tag . ' بند الزميل', $openDay]);

        $mine  = $render(['status' => 'pending', 'rep' => $repId]);
        $all   = $render(['status' => 'pending']);
        $ghost = $render(['status' => 'pending', 'rep' => 99999999]);

        chk(substr_count($all, 'class="ib-lens-chip') >= 3,
            '★ شرايح المناديب اترسمت فعلاً (كل المناديب + مندوبين)');
        chk(str_contains($all, 'rep=' . $repId), 'وفيها رابط للمندوب صاحب البنود');
        chk(str_contains($all, $tag . ' بند الزميل') && str_contains($all, $tag . ' تخصيص'),
            'بلا فلتر: بنود كل المناديب ظاهرة');
        chk(str_contains($mine, $tag . ' تخصيص') && !str_contains($mine, $tag . ' بند الزميل'),
            '★ بـ`?rep=`: بنود المندوب ده **بس** — ولا بند لزميله');
        chk(str_contains($ghost, $tag . ' بند الزميل'),
            '★ مندوب مش موجود بيرجع لكل المناديب (الرابط مش مصدر ثقة)');
        chk(str_contains($mine, 'status=pending&amp;rep=' . $repId)
            || str_contains($mine, 'status=pending&rep=' . $repId),
            '★ روابط التبويبات بتحافظ على العدسة (مش بترجّعك لكل المناديب)');
        chk(substr_count($mine, 'class="ib-lens-card"') === 1, 'ورابط لكارت المندوب لما تكون العدسة عليه');
        chk(str_contains($mine, 'نزل له عهدة اليوم') && str_contains($mine, 'المتبقي من عهدة اليوم')
            && !str_contains($mine, 'أيام عليها بنود'),
            '★ عدسة المندوب تعرض العهدة والمتبقي بدل عدد الأيام');
        $pocket = \App\Models\RepPortal::dayPocket($repId, work_date());
        chk(str_contains($mine, money0($pocket['deposits']))
            && str_contains($mine, money0($pocket['pocket'])),
            'وأرقام الكارتين من `RepPortal::dayPocket` نفسه (عهدة اليوم فقط)');

        /* الترقيم الخادمي — «معتمد» كان بيرندر ٣٤٦ صف على الإنتاج */
        sec('5ج) الترقيم الخادمي للتبويبات غير المستنية');
        $listAll = RepInbox::listing('approved', 0, '', 2, 0);
        chk(count($listAll) <= 2, '★ `listing` بتحترم الحدّ (مفيش رسم كل الصفوف)');
        chk(RepInbox::countListing('approved') >= count($listAll),
            'و`countListing` بتعدّ بنفس الفلتر (الترقيم ما يكدبش)');
        chk(count(RepInbox::listing('pending', $repId)) === count(RepInbox::listing('pending', $repId, '', 0, 0)),
            'limit=0 = بلا حدّ (السلوك القديم بالحرف)');

        /* N+1: حركات كل الأذونات في استعلام واحد */
        chk(RepInbox::movementsFor([$permitId])[$permitId][0]['id'] === RepInbox::movementsOf($permitId)[0]['id'],
            '★ `movementsFor` (استعلام واحد) بترجّع نفس `movementsOf` (كانت N+1)');

        sec('6) تنبيه اليوم المتقفل (الاعتماد عليه بيترفض)');
        if ($lockedDay === null) {
            echo "  (مفيش يوم متقفل في القاعدة — الفحص ده بيتخطّى)\n";
        } else {
            chk(str_contains($html, 'row-locked'), '★ البند اللي على يوم متقفل متعلّم في صفّه');
            chk(str_contains($html, 'اعتمادها هيترفض'), '★ عدّاد «على أيام متقفلة» فوق الصفحة');
            chk(str_contains($html, 'js-ib-row-date'), 'وفيه زر «غيّر تاريخه» جنب التحذير');
        }

        sec('7) الحذف النهائي — الواجهة');
        chk(str_contains($html, 'ibDelete(' . $s1 . ')'), '★ كل بند له زرار حذف نهائي');
        chk(str_contains($html, 'id="d' . $s1 . 'f"'), 'وفورم حذف مستقل برمز FormState بتاعه');
        chk(str_contains($html, 'id="ibDoDelete"'), '★ وفيه حذف جماعي في شريط الإجراءات');
        chk(str_contains($html, 'سلة المحذوفات'),
            '★ التأكيد بيقول إن فيه طريق رجوع (وإلا المالك يتردّد أو يحذف وهو خايف)');

        sec('7ب) الحذف مخفي عن الأدوار اللي مش هتقدر تسترجع');
        // ⚠️ الإخفاء ده **مش حماية** — المنع الحقيقي في Access::OWNER_MANAGER_ONLY.
        //    الفحص هنا على الاتنين: القاعدة نفسها، والشاشة اللي بتحترمها.
        foreach (['owner' => true, 'manager' => true, 'accountant' => false,
                  'operator' => false, 'read_only' => false] as $role => $may) {
            chk(\App\Core\Access::allows($role, 'RepCustodyController@deleteSubmission', 'POST') === $may
                && \App\Core\Access::allows($role, 'RepCustodyController@deleteMany', 'POST') === $may,
                'دور ' . $role . ': ' . ($may ? 'يقدر يحذف' : 'ما يقدرش يحذف'));
        }
        chk(\App\Core\Access::allows('accountant', 'RepCustodyController@approve', 'POST'),
            'والمحاسب لسه يقدر يعتمد ويرفض (الحذف بس هو اللي اتضيّق)');

        sec('7ج) قواعد الواجهة العامة');
        chk(!preg_match('/style="[^"]*grid-template-columns:\s*repeat\(\s*[4-9]/', $html),
            'مفيش شبكة inline بـ4 أعمدة أو أكتر (بتدهس التجاوب)');
        chk(!preg_match('/\bjson_encode\s*\(/', (string) file_get_contents(
                \App\Core\App::basePath('app/Views/rep_custody/inbox.php'))),
            'مفيش json_encode في الـview (ممنوع — استخدم js_json)');
    }

    // ═══ 8) السلوك الحقيقي ورا الشاشة
    sec('8) السلوك: تغيير التاريخ الجماعي + حارس اليوم المتقفل');
    $newDay = date('Y-m-d', strtotime($openDay . ' -1 day'));
    if ($lockedDay !== null && $newDay <= $lockedDay) { $newDay = $openDay; }
    RepInbox::bulkUpdate([$s1, $s2], ['entry_date' => $newDay]);
    $after1 = Database::scalar('SELECT entry_date FROM rep_submissions WHERE id=?', [$s1]);
    $after2 = Database::scalar('SELECT entry_date FROM rep_submissions WHERE id=?', [$s2]);
    chk($after1 === $newDay && $after2 === $newDay, '★ تغيير التاريخ الجماعي بيشتغل على البنود المعلَّمة');
    chk(Database::scalar('SELECT entry_date FROM rep_submissions WHERE id=?', [$s3]) === $openDay,
        'والبند غير المعلَّم ما اتغيّرش');

    if ($lockedDay !== null) {
        // البند لسه pending فتعديله مسموح، لكن **اعتماده** لازم يترفض.
        RepInbox::update($sLocked, ['entry_date' => $lockedDay]);
        ok('تعديل بند على يوم متقفل مسموح (لسه مستني، مالوش أثر مالي)');
        try {
            RepInbox::approve($sLocked);
            bad('★ اعتماد بند على يوم متقفل عدّى! المفروض يترفض.');
        } catch (\Throwable $e) {
            /* ⚠️ **إنذار كاذب اتصاد على الإنتاج (2026-08-02):** الفحص كان
               بيتوقّع كلمة «متقفل» بالحرف، لكن على الإنتاج آخر يوم متقفل
               (٢٨/٧) واقع **قبل خط الفصل** (٢٩/٧) — فـ`epochBlock` بترفض
               الاعتماد **الأول** برسالتها هي. الرفض صح في الحالتين، والفحص
               الصح على **المعنى**: اتـرفض وقال السبب. (القاعدة الموثَّقة:
               افحص السلوك مش نصّ تسمية بعينه.) */
            $msg = $e->getMessage();
            chk(str_contains($msg, 'متقفل') || str_contains($msg, 'النظام الجديد'),
                '★ واعتماده بيترفض برسالة واضحة — ودي اللي الشاشة بتحذّر منها قبل الضغط');
        }
    }
    // ═══ 8ب) الحذف النهائي — السلوك
    sec('8ب) الحذف النهائي: بينزل السلة، والمعتمد بيترفض');
    $custodyBeforeDelete = Closing::currentBalance();

    $gone = RepInbox::delete($s3);
    chk(!Database::scalar('SELECT COUNT(*) FROM rep_submissions WHERE id=?', [$s3]),
        'البند المستني اتشال من الصندوق');
    chk((int) ($gone['id'] ?? 0) === $s3, 'والدالة بترجّع صفّه قبل الحذف (الكنترولر محتاجه للإشعار)');

    $trash = Database::row(
        "SELECT * FROM deleted_records WHERE entity='rep_submissions' AND record_id=? ORDER BY id DESC LIMIT 1",
        [(string) $s3]);
    chk((bool) $trash, '★ ونزل سلة المحذوفات (فيه طريق رجوع)');
    chk(str_contains((string) ($trash['label'] ?? ''), $tag), 'وباسم واضح فيه بيان البند');
    chk(in_array('rep_submissions', \App\Models\Audit::restorableEntities(), true),
        '★ والنوع مسجَّل في RESTORABLE — من غيره Audit::trash بترمي استثناء');
    chk((bool) Database::scalar(
        "SELECT COUNT(*) FROM audit_logs WHERE entity='rep_submissions' AND action='delete' AND record_id=?",
        [(string) $s3]), 'واتسجّل في سجل العمليات');

    // ⚠️ نسخة السلة جاية من `RepInbox::find()` اللي بترجّع أعمدة JOIN
    //    (rep_name/permit_no…). `Audit::restore` بتفلترها بأعمدة الجدول الحقيقية
    //    قبل الإدخال — من غير الفلترة دي الاسترجاع بيقع بـ«Unknown column».
    $snap = json_decode((string) $trash['data_json'], true) ?: [];
    $cols = array_column(Database::all('SHOW COLUMNS FROM rep_submissions'), 'Field');
    chk(isset($snap['rep_name']) && !isset(array_intersect_key($snap, array_flip($cols))['rep_name']),
        '★ أعمدة الـJOIN بتتفلتر عند الاسترجاع (وإلا بيقع بـUnknown column)');

    // البند المرفوض بيتحذف برضو — مالوش أي أثر مالي زي المستني بالظبط
    RepInbox::reject($s2, 'سبب فحص');
    RepInbox::delete($s2);
    chk(!Database::scalar('SELECT COUNT(*) FROM rep_submissions WHERE id=?', [$s2]),
        'والبند المرفوض بيتحذف برضو');

    // ⚠️ الخصم بيتقاس **قبل** الاعتماد اللي بعده — الاعتماد بيغيّر الخزنة فعلاً
    //    (وده صح)، والمقصود هنا إن **الحذف** لوحده ما بيلمسهاش.
    chk(abs(Closing::currentBalance() - $custodyBeforeDelete) <= 0.005,
        '★ ولا خصم من الخزنة بسبب الحذف (البند المستني ما كانش متخصمًا أصلاً)');

    // ⚠️ أهم حارس: البند المعتمد **ما بيتحذفش** — له بند فعلي على الإذن
    RepInbox::approve($s1);
    try {
        RepInbox::delete($s1);
        bad('★ حذف بند معتمد عدّى! المفروض يترفض.');
    } catch (\Throwable $e) {
        chk(str_contains($e->getMessage(), 'معتمد'),
            '★ حذف البند المعتمد بيترفض برسالة واضحة');
        chk((bool) Database::scalar('SELECT COUNT(*) FROM rep_submissions WHERE id=?', [$s1]),
            'وبيفضل مكانه بلا أي تغيير');
    }
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}

sec('9) بعد الـrollback');
abs(Closing::currentBalance() - $treasuryBefore) <= 0.005
    ? ok('رصيد الخزنة رجع زي ما كان')
    : bad('رصيد الخزنة اتغيّر بعد الفحص');

echo "\n" . str_repeat('=', 62) . "\n";
if ($failures) {
    echo 'فشل ' . count($failures) . ' فحص من أصل ' . ($pass + count($failures)) . ":\n";
    foreach ($failures as $f) { echo "  ✗ $f\n"; }
    exit(1);
}
echo "اكتمل الفحص بنجاح — $pass فحص.\n";
