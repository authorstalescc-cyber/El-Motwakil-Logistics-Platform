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
        foreach (['ibSearch' => 'بحث', 'ibRep' => 'مندوب', 'ibDay' => 'يوم', 'ibOnlyLocked' => 'المتقفل بس'] as $id => $label) {
            chk(str_contains($html, 'id="' . $id . '"'), "فلتر: $label");
        }

        sec('6) تنبيه اليوم المتقفل (الاعتماد عليه بيترفض)');
        if ($lockedDay === null) {
            echo "  (مفيش يوم متقفل في القاعدة — الفحص ده بيتخطّى)\n";
        } else {
            chk(str_contains($html, 'row-locked'), '★ البند اللي على يوم متقفل متعلّم في صفّه');
            chk(str_contains($html, 'اعتمادها هيترفض'), '★ عدّاد «على أيام متقفلة» فوق الصفحة');
            chk(str_contains($html, 'js-ib-row-date'), 'وفيه زر «غيّر تاريخه» جنب التحذير');
        }

        sec('7) قواعد الواجهة العامة');
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
            chk(str_contains($e->getMessage(), 'متقفل'),
                '★ واعتماده بيترفض برسالة واضحة — ودي اللي الشاشة بتحذّر منها قبل الضغط');
        }
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
