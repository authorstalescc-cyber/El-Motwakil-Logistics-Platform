<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص v95 — تعديل بند المندوب **بعد اعتماده** من صندوق المراجعة.
 *
 * ⚠️ **السلوك اللي بيتفحص، مش أسماء الأعمدة ولا نصوص الشاشة:**
 *   ① التعديل بيمسّ **الصفّين مع بعض** — سجل المراجعة والبند الفعلي على الإذن
 *     (أو النثرية). لو واحد اتعدّل والتاني لأ، الصندوق يقول رقم والإذن يقول
 *     رقم تاني بلا أي خطأ ظاهر.
 *   ② **عهدة المندوب بتتحرّك بالفرق بالظبط** — لا أكتر ولا أقل.
 *   ③ **الخزنة بتتحرّك بنفس الفرق كمان** (البند بيتخصم يوم صرفه من v42).
 *   ④ **نقل التاريخ بينقل الحركة كلها** من يوم ليوم في الخزنة وفي العهدة.
 *   ⑤ الحُرّاس: اليوم المتقفل (القديم والجديد) · البند اللي بنده الفعلي اتمسح ·
 *     البند المستني · قلب نثرية لبند إذن.
 *
 * كله جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_v95.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;
use App\Models\RepCustody;
use App\Models\RepInbox;
use App\Models\RepMiscExpense;

$pass = 0; $fail = 0; $skip = 0;
function ok(string $m): void   { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  ✗ $m\n"; }
function skp(string $m): void  { global $skip; $skip++; echo "  ~ $m\n"; }
function sec(string $m): void  { echo "\n" . $m . "\n" . str_repeat('─', 60) . "\n"; }
function near(string $l, $a, $e): void {
    abs((float) $a - (float) $e) <= 0.005 ? ok($l)
        : bad("$l — متوقع " . number_format((float) $e, 2) . " / فعلي " . number_format((float) $a, 2));
}
function same(string $l, $a, $e): void {
    $a == $e ? ok($l) : bad("$l — متوقع " . var_export($e, true) . " / فعلي " . var_export($a, true));
}
/** بيتوقّع رفضًا برسالة — الرفض الصامت أخطر من الرفض الصريح. */
function refused(string $l, callable $fn): void {
    try { $fn(); bad("✗ $l — عدّى وهو المفروض يترفض"); }
    catch (\Throwable $e) { trim($e->getMessage()) !== '' ? ok($l) : bad("$l — اترفض بلا رسالة"); }
}

echo "فحص v95 — تعديل بند المندوب بعد اعتماده\n" . str_repeat('=', 60) . "\n";

sec('0) البنية والصلاحية');
method_exists(RepInbox::class, 'updateApproved')
    ? ok('RepInbox::updateApproved موجودة') : bad('RepInbox::updateApproved ناقصة');
method_exists(RepMiscExpense::class, 'update')
    ? ok('RepMiscExpense::update موجودة (تطبيع الوسم في مكان واحد)')
    : bad('RepMiscExpense::update ناقصة — التطبيع هيتكرر في المستدعي');

/* ⚠️ **الصلاحية أضيق من الاعتماد عن قصد**: البند ده خلاص دخل الخزنة وفاتورة
   العميل. المحاسب بيعتمد، بس اللي بيعدّل المعتمد هو اللي بيحذف. */
same('المالك يقدر يعدّل المعتمد',
     \App\Core\Access::allows('owner', 'RepCustodyController@editApproved', 'POST'), true);
same('والمدير كمان',
     \App\Core\Access::allows('manager', 'RepCustodyController@editApproved', 'POST'), true);
same('⚠️ والمحاسب لأ (بيعتمد بس)',
     \App\Core\Access::allows('accountant', 'RepCustodyController@editApproved', 'POST'), false);
same('والقراءة فقط لأ',
     \App\Core\Access::allows('read_only', 'RepCustodyController@editApproved', 'POST'), false);

sec('0ب) درج التعديل **بارشيال واحد** للتبويبين');
$partial = dirname(__DIR__) . '/app/Views/partials/rep_submission_edit.php';
is_file($partial) ? ok('البارشيال موجود') : bad('البارشيال ناقص');
$inbox = (string) file_get_contents(dirname(__DIR__) . '/app/Views/rep_custody/inbox.php');
/* ⚠️ **العدّ مش الوجود**: نداءان بالظبط (مستني + معتمد). نسخة ماركب تالتة
   مكتوبة بالإيد في الشاشة معناها إن حقلًا جديدًا هيتعمل مرتين. */
same('الشاشة بتنادي البارشيال مرتين (مستني + معتمد)',
     substr_count($inbox, "partial('rep_submission_edit'"), 2);
/* ⚠️ **الماركب مش الـCSS**: الشاشة لازم تفضل معرّفة `.ib-edit-sec` في مقطع
   الأنماط (الدرج بيترسم جوّها)، فالبحث عن الاسم مجرَّدًا بيفشل بإنذار كاذب.
   اللي ممنوع هو **عنصر** مكتوب بالإيد. */
!preg_match('/<div class="ib-edit-sec"/', $inbox)
    ? ok('⚠️ ومفيش أي نسخة ماركب باقية في الشاشة نفسها')
    : bad('لسه فيه ماركب درج مكتوب في الشاشة — نسختان للحقول');
/* `ibToggle`/`ibSetDate` لازم يكونوا **قبل** حارس `ibForm` وإلا الزرار في تبويب
   «معتمد» بيدوس بلا رد فعل (الفورم ده موجود في تبويب «مستني» بس). */
$posToggle = strpos($inbox, 'window.ibToggle');
$posGuard  = strpos($inbox, "if (!form) { return; }");
($posToggle !== false && $posGuard !== false && $posToggle < $posGuard)
    ? ok('⚠️ ibToggle متعرَّف قبل حارس ibForm — الزرار يشتغل في التبويبين')
    : bad('ibToggle تحت الحارس — زرار «عدّل المعتمد» مش هيرد');

$pdo = Database::conn();
$pdo->beginTransaction();
try {
    $d1 = date('Y-m-d', strtotime('-1 day'));
    $d0 = date('Y-m-d');
    if (RepCustody::isDayLocked($d1) || RepCustody::isDayLocked($d0)) {
        skp('اليومان الأخيران متقفلان — الفحص محتاج يومين مفتوحين');
        throw new RuntimeException('__skip__');
    }

    // ── fixtures: مندوب + إذن + حركة + عهدة
    Database::exec("INSERT INTO reps (name, status) VALUES ('__v95_rep','active')");
    $rep = Database::lastId();
    Database::exec(
        "INSERT INTO permits (permit_no, permit_type, company, client, created_at)
         VALUES ('__V95_P', 'sader', '__v95_co', '__v95_cl', NOW())");
    $permit = Database::lastId();
    Database::exec("INSERT INTO permit_movements (permit_id, seq) VALUES (?, 1)", [$permit]);
    $mov = Database::lastId();
    RepCustody::createDeposit($rep, 10000, $d1, '');

    $safe0 = Closing::currentBalance();
    $bal0  = RepCustody::balance($rep);

    /** بند مستني جاهز للاعتماد. */
    $seed = static function (array $over = []) use ($rep, $permit, $mov, $d1): int {
        $d = array_merge([
            'kind' => 'receipt', 'note' => 'إيصال __v95', 'qty' => 1,
            'unit_amount' => 1000, 'entry_date' => $d1,
        ], $over);
        Database::exec(
            "INSERT INTO rep_submissions
                (rep_id, client_token, permit_id, movement_id, kind, note, qty, unit_amount,
                 amount, entry_date, status, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?, 'pending', NOW())",
            [$rep, bin2hex(random_bytes(16)),
             ($d['kind'] === 'misc' ? null : $permit), ($d['kind'] === 'misc' ? null : $mov),
             $d['kind'], $d['note'], $d['qty'], $d['unit_amount'],
             round($d['unit_amount'] * $d['qty'], 2), $d['entry_date']]);
        return Database::lastId();
    };

    sec('1) ⚠️ التعديل بيمسّ سجل المراجعة **والبند الفعلي** مع بعض');
    $sub = $seed();
    $stage = RepInbox::approve($sub);
    near('بعد الاعتماد: العهدة اتخصمت 1000', $bal0 - RepCustody::balance($rep), 1000);

    RepInbox::updateApproved($sub, ['unit_amount' => 1500, 'note' => 'إيصال __v95 معدَّل']);

    $s = Database::row('SELECT * FROM rep_submissions WHERE id=?', [$sub]);
    $g = Database::row('SELECT * FROM permit_stages WHERE id=?', [$stage]);
    near('سجل المراجعة بقى 1500', $s['amount'], 1500);
    near('⚠️ والبند على الإذن بقى 1500 كمان', $g['amount'], 1500);
    same('والبيان اتنقل للاتنين', (string) $g['note'], 'إيصال __v95 معدَّل');
    same('والحالة فضلت معتمدة', (string) $s['status'], 'approved');
    same('والمندوب صاحب العهدة ما اتغيّرش', (int) $g['paid_by_rep_id'], $rep);

    sec('2) ⚠️ العهدة والخزنة اتحرّكوا بالفرق بالظبط (500)');
    near('عهدة المندوب', $bal0 - RepCustody::balance($rep), 1500);
    near('⚠️ والخزنة نزلت 1500 (البند بيتخصم يوم صرفه)', $safe0 - Closing::currentBalance(), 1500);
    near('وعهدة يوم البند نفسه', RepCustody::breakdown($rep, $d1)['spent'], 1500);

    sec('3) ⚠️ نقل التاريخ بينقل الحركة كلها من يوم ليوم');
    RepInbox::updateApproved($sub, ['entry_date' => $d0]);
    near("اليوم القديم ($d1) فضي", RepCustody::breakdown($rep, $d1)['spent'], 0);
    near("واليوم الجديد ($d0) فيه 1500", RepCustody::breakdown($rep, $d0)['spent'], 1500);
    near('⚠️ والتراكمي ما اتغيّرش (نقل مش خلق فلوس)', $bal0 - RepCustody::balance($rep), 1500);
    near('والخزنة برضه', $safe0 - Closing::currentBalance(), 1500);

    sec('4) ⚠️ وسم الفيزا بيطلّع البند من الخزنة ومن العهدة مع بعض');
    $card = Database::scalar("SELECT id FROM visa_cards WHERE status='active' ORDER BY id LIMIT 1");
    if (!$card) {
        skp('مفيش كارت فيزا شغّال — فحص الفيزا محتاج كارت');
    } else {
        RepInbox::updateApproved($sub, ['is_visa' => true, 'visa_card_id' => (int) $card]);
        near('الخزنة رجعت زي ما كانت', Closing::currentBalance(), $safe0);
        near('⚠️ وعهدة المندوب رجعت كمان (خرج من الكارت مش من جيبه)',
             RepCustody::balance($rep), $bal0);
        RepInbox::updateApproved($sub, ['is_visa' => false]);
        near('وشيل الوسم رجّع الخصم', $safe0 - Closing::currentBalance(), 1500);
    }

    sec('5) النثرية: نفس الكلام على `rep_misc_expenses`');
    $msub = $seed(['kind' => 'misc', 'note' => 'مواصلات __v95', 'unit_amount' => 300]);
    Database::exec("UPDATE rep_submissions SET misc_kind='transport_in' WHERE id=?", [$msub]);
    $mid  = RepInbox::approve($msub);
    $balM = RepCustody::balance($rep);

    RepInbox::updateApproved($msub, ['unit_amount' => 450, 'note' => 'مواصلات __v95 معدَّلة']);
    $m = RepMiscExpense::find($mid);
    near('صف النثرية بقى 450', $m['amount'], 450);
    same('والبيان اتنقل', (string) $m['note'], 'مواصلات __v95 معدَّلة');
    near('وعهدة المندوب نزلت 150 كمان', $balM - RepCustody::balance($rep), 150);
    /* ⚠️ التصنيف المعروض بيتولّد من الوسم جوّه `RepMiscExpense` — مش مكتوب
       في المستدعي، وإلا النثرية المعدَّلة تتعرض في /expenses باسم مخالف. */
    same('والتصنيف اتولّد من الوسم مش من المستدعي',
         (string) $m['category'], \App\Models\RepMiscKind::category('transport_in'));

    sec('6) الحُرّاس');
    refused('البند المستني بيترفض (مساره التاني)', static fn () => RepInbox::updateApproved($seed(), []));
    refused('وقلب النثرية لبند على إذن بيترفض',
            static fn () => RepInbox::updateApproved($msub, ['kind' => 'receipt']));
    refused('والمبلغ صفر بيترفض',
            static fn () => RepInbox::updateApproved($sub, ['unit_amount' => 0]));

    /* ⚠️ **البند اللي بنده الفعلي اتمسح**: السجل بيفضل شاهدًا على الاعتماد
       (`DeletionPolicy::detachStage` بتفكّ الربط) — وتعديله ما لهوش معنى
       لأن مفيش صف فعلي يستقبل التعديل. */
    $orphan = $seed(['note' => 'يتيم __v95']);
    $oStage = RepInbox::approve($orphan);
    \App\Models\DeletionPolicy::detachStage($oStage);
    Database::exec('DELETE FROM permit_stages WHERE id=?', [$oStage]);
    refused('⚠️ والبند اللي بنده الفعلي اتمسح بيترفض',
            static fn () => RepInbox::updateApproved($orphan, ['unit_amount' => 99]));

    sec('7) ⚠️ اليوم المتقفل — القديم والجديد');
    /* الحارس لازم يمسك **اليومين**: البند بيخرج من رصيد يومه القديم ويدخل
       رصيد يومه الجديد، فقفل أي واحد فيهم معناه تغيير رصيد مثبَّت في الخفا. */
    $model = (string) file_get_contents(dirname(__DIR__) . '/app/Models/RepInbox.php');
    $body  = substr($model, (int) strpos($model, 'function updateApproved'));
    $body  = substr($body, 0, (int) strpos($body, 'public static function') ?: null);
    same('الموديل بينادي Closing::guard مرتين (اليوم القديم والجديد)',
         substr_count($body, 'Closing::guard('), 2);
    str_contains($body, 'isPreEpoch')
        ? ok('⚠️ وبيمنع النقل لما قبل خط الفصل (البند كان هيختفي من العهدة)')
        : bad('مفيش حارس خط الفصل — نقل البند لقبله بيخفيه من عهدة المندوب');
    str_contains($body, 'stageAllocationTotal')
        ? ok('وبيحرس الإيصال المربوط ببيان سائق المكتب')
        : bad('مفيش حارس بيان السائق — المبلغ ممكن ينزل تحت الجزء المربوط');
    str_contains($body, 'FOR UPDATE')
        ? ok('وبيقفل الصفوف قبل ما يقيس') : bad('مفيش قفل — سباق بين تعديلين');

    sec('7ب) الشاشة بترسم الدرج فعلاً في تبويب «معتمد»');
    /* ⚠️ **رندر حقيقي مش بحث في الملف**: الملف ممكن يبقى فيه النداء والشاشة
       ما ترسمش حاجة (شرط غلط، استثناء مبلوع). ودي كمان بتحمّل الكنترولر
       فبتمسك أي تصادم دوال. */
    $render = static function (array $q): string {
        $_SESSION = ['uid' => (int) Database::scalar('SELECT MIN(id) FROM users'), 'role' => 'owner',
                     'uname' => 'فحص v95', 'last_activity' => time(), 'uid_checked' => time()];
        $_GET = $q;
        ob_start();
        try { (new \App\Controllers\RepCustodyController())->inbox(); $out = (string) ob_get_clean(); }
        catch (\Throwable $e) { ob_end_clean(); $out = 'استثناء: ' . $e->getMessage(); }
        $_SESSION = []; $_GET = [];
        return $out;
    };
    $html = $render(['status' => 'approved', 'rep' => $rep]);
    str_contains($html, 'استثناء: ')
        ? bad('الشاشة رمت استثناء — ' . substr($html, 0, 160))
        : ok('تبويب «معتمد» بيترسم بلا استثناء');
    str_contains($html, 'id="ea' . $sub . '"')
        ? ok('⚠️ ودرج تعديل البند المعتمد موجود في الصفحة')
        : bad('الدرج ما اترسمش — زرار التعديل هيدوس على العدم');
    str_contains($html, 'rep-custody/' . $sub . '/edit-approved')
        ? ok('وفورم الحفظ بيوَدّي على مسار التعديل المعتمد')
        : bad('فورم الحفظ مش موجود');
    /* ⚠️ الفورم لازم يبقى **برّه الجدول**: `<form>` جوّه `<table>` المتصفح
       بيفكّه بصمت فالحفظ بيروح على المسار الغلط. */
    $tableEnd = strrpos($html, '</table>');
    $formPos  = strpos($html, 'id="ea' . $sub . 'f"');
    ($tableEnd !== false && $formPos !== false && $formPos > $tableEnd)
        ? ok('⚠️ وفورم الحفظ برّه الجدول (الماركب صالح)')
        : bad('فورم الحفظ جوّه الجدول — المتصفح هيفكّه بصمت');
    /* اليتيم ما بيتعرضش له درج — الشاشة بتقول نفس اللي الخادم بيقوله. */
    !str_contains($html, 'id="ea' . $orphan . '"')
        ? ok('والبند اليتيم مالوش درج (نفس رفض الخادم)')
        : bad('الشاشة بتعرض درجًا لبند الخادم بيرفضه');

    sec('8) ⚠️ العزل: التعديل **تعديل** — ما بيخلقش صفًّا ولا بيمسح');
    /* ⚠️ **فرق قبل/بعد مش رقم مزروع**: العدد المطلق بيتغيّر مع أي fixture جديدة
       في الفحص، فبيفشل بإنذار كاذب أول ما نضيف حالة. اللي بيهمّنا إن نداء
       واحد للتعديل ما بيغيّرش أي عدّاد. */
    $cnt = static fn (): array => [
        'stages' => (int) Database::scalar('SELECT COUNT(*) FROM permit_stages WHERE paid_by_rep_id=?', [$rep]),
        'misc'   => (int) Database::scalar('SELECT COUNT(*) FROM rep_misc_expenses WHERE rep_id=?', [$rep]),
        'subs'   => (int) Database::scalar('SELECT COUNT(*) FROM rep_submissions WHERE rep_id=?', [$rep]),
    ];
    $c0 = $cnt();
    RepInbox::updateApproved($sub, ['note' => 'إيصال __v95 تالت', 'qty' => 3]);
    RepInbox::updateApproved($msub, ['unit_amount' => 600]);
    $c1 = $cnt();
    same('عدد بنود الإذن ما اتغيّرش', $c1['stages'], $c0['stages']);
    same('وعدد النثريات', $c1['misc'], $c0['misc']);
    same('وعدد سجلات المراجعة', $c1['subs'], $c0['subs']);
    /* والقيم وصلت فعلاً — عشان «ما اتغيّرش» ما تبقاش لأن التعديل ما اشتغلش أصلاً */
    near('والكمية الجديدة اتنقلت للبند الفعلي',
         Database::scalar('SELECT amount FROM permit_stages WHERE id=?', [$stage]), 4500);

} catch (\Throwable $e) {
    if ($e->getMessage() !== '__skip__') {
        bad('استثناء غير متوقّع: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    }
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo "\n" . str_repeat('═', 60) . "\n";
    echo "اترجّع كل شيء (rollback) — مفيش أي أثر.\n";
    echo "رصيد الخزنة دلوقتي: " . number_format(Closing::currentBalance(), 2) . "\n";
    echo "\nنجح: $pass | فشل: $fail | تخطّي: $skip\n";
    exit($fail > 0 ? 1 : 0);
}
