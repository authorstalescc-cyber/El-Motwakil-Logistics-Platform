<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص v49 — عهدة المناديب بقت عهدة يوم.
 *
 * أهم ٤ حاجات بيتأكد منها:
 *   ١) **مفيش ترحيل**: الفاضل من يوم ما بيدخلش عهدة اليوم اللي بعده إطلاقًا.
 *      ده جوهر قرار المالك، ولو اتكسر يبقى النظام رجع لسلوكه القديم بالسكوت.
 *   ٢) **التراكمي ما اتغيّرش**: `balance()` بلا يوم لسه بترجّع الفلوس اللي برّه
 *      مع المندوب فعلاً (أساس التسوية وحد التحويل). عهدة اليوم عدسة فوقه مش بديل.
 *   ٣) **تطابق كل المصادر**: breakdown(day) == dayLedger == dayTotalsByRep ==
 *      dayPocket == dashboard. لو اختلفوا، الشاشة تقول رقم والكشف يقول تاني.
 *   ٤) **اليوم المتقفل**: كل مسارات الكتابة من بوابة المندوب بترفضه (بند/نثرية/
 *      تسليم سائق/تحويل)، والمفتوح بيعدّي.
 *
 * وكمان: تجاوز عهدة اليوم **مسموح بتحذير** (قرار المالك)، والفيزا لسه مستثناة
 * من العهدة (v45)، والعزل المالي محفوظ.
 *
 * كله جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_v49.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;
use App\Models\RepCustody;
use App\Models\RepPortal;
use App\Models\RepDriverHandover;
use App\Models\RepMiscExpense;
use App\Models\RepSubmissionValidationException;
use App\Models\RepTransfer;

$pass = 0; $failures = [];
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $failures; $failures[] = $m; echo "  ✗ $m\n"; }
function chk(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function near(string $l, $actual, $expected): void {
    abs((float) $actual - (float) $expected) <= 0.005 ? ok($l)
        : bad("$l — متوقع " . number_format((float) $expected, 2)
              . " / فعلي " . number_format((float) $actual, 2));
}
function sec(string $m): void { echo "\n$m\n" . str_repeat('─', 62) . "\n"; }

echo "فحص v49 — عهدة المناديب بقت عهدة يوم\n" . str_repeat('=', 62) . "\n";

// ═══ 0) البنية
sec('0) البنية بعد الهجرة');
chk(RepCustody::available(), 'جداول عهدة المناديب موجودة');
$nullable = Database::scalar(
    "SELECT is_nullable FROM information_schema.columns
     WHERE table_schema=DATABASE() AND table_name='rep_custody_deposits' AND column_name='dep_date'");
chk($nullable === 'NO', 'rep_custody_deposits.dep_date إلزامي (كل تنزيل له يوم)');
chk((int) Database::scalar('SELECT COUNT(*) FROM rep_custody_deposits WHERE dep_date IS NULL') === 0,
    'مفيش تنزيل عهدة بلا تاريخ');
foreach ([['permit_stages', 'idx_stage_rep_date'], ['rep_submissions', 'idx_sub_rep_date']] as [$t, $i]) {
    chk((bool) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema=DATABASE() AND table_name=? AND index_name=?", [$t, $i]),
        "فهرس $i موجود على $t");
}

// ═══ 0ب) ⚠️ تاريخ العمل ما بيتجمّدش على يوم فات
// ده كان **أخطر باج في عهدة اليوم**: الاختيار كان بيتخزّن في الجلسة للأبد،
// فالتبويب/التطبيق يفضل مفتوح، النهار يتغيّر، والنظام يكمّل يكتب على اليوم
// القديم بلا أي تنبيه — وشاشة المندوب تعرض «فاضل من عهدة اليوم» وهي عهدة يوم
// فات. متحقَّق في بيانات الإنتاج: تنزيل عهدة created 2026-07-26 11:00 مخزَّن
// بـdep_date 2026-07-25 (وكذلك 22←21 و21←20).
sec('0ب) ⚠️ اختيار «يوم عمل» قديم بيبطل مع أول يوم جديد');
$sessionBackup = $_SESSION ?? [];
$today   = date('Y-m-d');
$pastDay = date('Y-m-d', strtotime('-3 day'));

foreach ([
    ['المكتب',  'work_date',     'work_date_set',     [\App\Core\Auth::class, 'workDate'],
                                                      [\App\Core\Auth::class, 'setWorkDate']],
    ['المندوب', 'rep_work_date', 'rep_work_date_set', [\App\Core\RepAuth::class, 'workDate'],
                                                      [\App\Core\RepAuth::class, 'setWorkDate']],
] as [$who, $key, $stampKey, $get, $set]) {
    // (١) اختيار اتّخذ **النهاردة** على يوم قديم ⇒ محترَم (شغل مشروع)
    $_SESSION = [];
    chk($set($pastDay) === true, "$who: اختيار يوم قديم اتقبل");
    chk($get() === $pastDay, "$who: والاختيار محترَم في نفس اليوم ($pastDay)");
    chk(($_SESSION[$stampKey] ?? '') === $today, "$who: وبيتخزّن معاه ختم اليوم الحقيقي");

    // (٢) نفس الاختيار بس اتّخذ في يوم فات ⇒ **باطل**، ويرجع للنهاردة
    $_SESSION = [$key => $pastDay, $stampKey => $pastDay];
    chk($get() === $today, "★ $who: اختيار من يوم فات بطل ورجع للنهاردة");
    chk(!isset($_SESSION[$key]), "$who: والمفتاح اتمسح فالواجهة ما تكدبش عليه");

    // (٣) جلسة قديمة بلا ختم (توافق رجعي) ⇒ برضه بترجع للنهاردة
    $_SESSION = [$key => $pastDay];
    chk($get() === $today, "$who: جلسة قديمة بلا ختم بترجع للنهاردة كمان");

    // (٤) يوم النهاردة نفسه بيفضل شغّال عادي
    $_SESSION = [];
    chk($set($today) === true && $get() === $today, "$who: اختيار النهاردة شغّال عادي");
}
$_SESSION = $sessionBackup;

// يومان مفتوحان متتاليان (مش متقفلين) — الفحص لازم يشتغل مهما كان آخر إقفال.
$openDays = [];
for ($i = 0; $i < 400 && count($openDays) < 2; $i++) {
    $d = date('Y-m-d', strtotime("-$i day"));
    if (!RepCustody::isDayLocked($d)) { $openDays[] = $d; }
}
if (count($openDays) < 2) {
    echo "\n! مش لاقي يومين مفتوحين — افتح إقفالًا وأعد الفحص.\n";
    exit(1);
}
[$d2, $d1] = $openDays;               // d1 أقدم، d2 أحدث
$lockedDay = Closing::lastClosedDate();
echo "\n  (يوم ١ = $d1 · يوم ٢ = $d2 · آخر يوم متقفل = " . ($lockedDay ?: 'مفيش') . ")\n";

$pdo = Database::conn();
$treasuryBefore = Closing::currentBalance();
$outstandingBefore = RepCustody::totalOutstanding();
$pdo->beginTransaction();
try {
    $tag = '__v49_' . bin2hex(random_bytes(4));
    Database::exec("INSERT INTO reps(name,status) VALUES(?,'active')", [$tag . '_A']);
    $repA = Database::lastId();
    Database::exec("INSERT INTO reps(name,status) VALUES(?,'active')", [$tag . '_B']);
    $repB = Database::lastId();
    Database::exec(
        "INSERT INTO permits(permit_no,permit_date,status,company,client) VALUES(?,?,'open',?,?)",
        [$tag, $d1, $tag, $tag]);
    $permitId = Database::lastId();
    // بوليصة حقيقية — من غيرها تسليم السائق بيترفض على «اختر البوليصة» فما
    // بيوصلش لفحص اليوم المتقفل أصلاً (نجاح كاذب).
    Database::exec(
        "INSERT INTO bawalis(bill_number,permit_id,driver,car_no,bill_date,is_legacy)
         VALUES(?,?,?,?,?,0)", [$tag . '_bill', $permitId, $tag . '_drv', 'س ١٢٣', $d1]);
    $bawaliId = Database::lastId();

    // ═══ 1) جوهر القرار: مفيش ترحيل بين الأيام
    sec('1) كل يوم عهدة مستقلة — مفيش ترحيل (جوهر قرار المالك)');
    RepCustody::createDeposit($repA, 5000, $d1, 'عهدة يوم ١');
    Database::exec(
        "INSERT INTO permit_stages(permit_id,stage_key,direction,amount,entry_date,status,is_visa,paid_by_rep_id)
         VALUES(?,'custom','expense',?,?,'done',0,?)", [$permitId, 3000, $d1, $repA]);

    near('عهدة يوم ١ = 5000 نازل − 3000 صرف', RepCustody::balance($repA, $d1), 2000);
    near('★ عهدة يوم ٢ = صفر (الفاضل من يوم ١ ما اترحّلش)', RepCustody::balance($repA, $d2), 0);

    RepCustody::createDeposit($repA, 1000, $d2, 'عهدة يوم ٢');
    Database::exec(
        "INSERT INTO permit_stages(permit_id,stage_key,direction,amount,entry_date,status,is_visa,paid_by_rep_id)
         VALUES(?,'custom','expense',?,?,'done',0,?)", [$permitId, 400, $d2, $repA]);

    near('★ عهدة يوم ٢ = 600 (1000−400) مش 2600', RepCustody::balance($repA, $d2), 600);
    near('عهدة يوم ١ ما اتأثرتش بحركة يوم ٢', RepCustody::balance($repA, $d1), 2000);

    sec('2) الرصيد التراكمي فضل زي ما هو (الفلوس اللي برّه معاه فعلاً)');
    near('التراكمي = 6000 نازل − 3400 صرف = 2600', RepCustody::balance($repA), 2600);
    near('التراكمي = مجموع كل الأيام',
        RepCustody::balance($repA), RepCustody::balance($repA, $d1) + RepCustody::balance($repA, $d2));

    // ═══ 3) كل مصادر الصرف بتتحسب على يومها هي
    sec('3) كل مصادر الصرف الأربعة بتتخصم من يومها هي بالظبط');
    Database::exec("INSERT INTO rep_misc_expenses(rep_id,exp_date,note,amount,is_visa) VALUES(?,?,?,?,0)",
        [$repA, $d2, $tag . ' نثرية', 100]);
    Database::exec("INSERT INTO rep_driver_handovers(rep_id,driver_name,amount,handover_date,note) VALUES(?,?,?,?,?)",
        [$repA, $tag . '_drv', 150, $d2, $tag]);
    near('النثرية اتخصمت من يوم ٢ بس', RepCustody::balance($repA, $d2), 350);
    near('التسليم دخل يوم ٢ برضه (600−100−150)', RepCustody::spentTotal($repA, $d2), 650);
    near('يوم ١ لسه 2000 — مش متأثر بولا مصدر منهم', RepCustody::balance($repA, $d1), 2000);

    if (RepTransfer::available()) {
        RepTransfer::create($repA, ['to_rep_id' => $repB, 'amount' => 200,
            'transfer_date' => $d2, 'note' => $tag]);
        near('التحويل الصادر خصم من يوم ٢', RepCustody::balance($repA, $d2), 150);
        near('التحويل الوارد زاد عهدة يوم ٢ للمستقبِل', RepCustody::balance($repB, $d2), 200);
        near('يوم ١ للمستقبِل لسه صفر (مفيش ترحيل عكسي)', RepCustody::balance($repB, $d1), 0);
    }

    // ═══ 4) تطابق كل المصادر على نفس الرقم
    sec('4) تطابق: breakdown == dayLedger == dayTotalsByRep == dayPocket');
    $ledger = [];
    foreach (RepCustody::dayLedger($repA) as $row) { $ledger[$row['day']] = $row; }
    chk(isset($ledger[$d1], $ledger[$d2]), 'كشف «عهدة كل يوم» فيه اليومين');
    near('dayLedger يوم ١ = breakdown يوم ١',
        $ledger[$d1]['balance'] ?? -1, RepCustody::balance($repA, $d1));
    near('dayLedger يوم ٢ = breakdown يوم ٢',
        $ledger[$d2]['balance'] ?? -1, RepCustody::balance($repA, $d2));

    $map = RepCustody::dayTotalsByRep($d2);
    near('dayTotalsByRep يوم ٢ = breakdown يوم ٢',
        $map[$repA]['balance'] ?? -1, RepCustody::balance($repA, $d2));
    near('dayTotalsByRep يوم ٢ للمستقبِل',
        $map[$repB]['balance'] ?? 0, RepCustody::balance($repB, $d2));

    $pocket = RepPortal::dayPocket($repA, $d2);
    near('dayPocket.official = عهدة اليوم', $pocket['official'], RepCustody::balance($repA, $d2));
    chk($pocket['locked'] === false, 'dayPocket بيقول إن اليوم المفتوح مش مقفول');

    $panel = RepCustody::dashboard($d2, 10, 1000);
    $card = null;
    foreach ($panel['reps'] as $c) { if ((int) $c['id'] === $repA) { $card = $c; break; } }
    chk($card !== null, 'المندوب ظاهر في لوحة الداشبورد');
    near('كارت الداشبورد: عهدة اليوم', $card['day_balance'] ?? -1, RepCustody::balance($repA, $d2));
    near('كارت الداشبورد: التراكمي زي ما هو', $card['balance'] ?? -1, RepCustody::balance($repA));

    // ═══ 5) اليوم المتقفل
    sec('5) اليوم المتقفل: كل مسارات الكتابة بترفضه');
    if ($lockedDay === null) {
        echo "  (مفيش يوم متقفل في القاعدة — الفحص ده بيتخطّى)\n";
    } else {
        chk(RepCustody::isDayLocked($lockedDay), 'اليوم المتقفل متعلّم مقفول');
        chk(RepCustody::dayLockReason($lockedDay) !== null, 'فيه رسالة عربية واضحة للمندوب');
        chk(RepCustody::dayLockReason($d2) === null, 'اليوم المفتوح بلا أي منع');

        $rejected = 0; $tried = 0;
        $tried++;
        try {
            RepPortal::submit($repA, ['permit_id' => $permitId, 'kind' => 'expense',
                'note' => $tag, 'qty' => 1, 'unit_amount' => 10, 'entry_date' => $lockedDay]);
            bad('★ بند على يوم متقفل اتقبل!');
        } catch (RepSubmissionValidationException $e) {
            $rejected++;
            chk(isset($e->errors()['entry_date']), 'رفض البند برسالة على خانة التاريخ');
        }
        $tried++;
        try {
            RepPortal::submit($repA, ['kind' => 'misc', 'note' => $tag,
                'misc_kind' => 'general',   // v71 — الوسم إجباري
                'qty' => 1, 'unit_amount' => 10, 'entry_date' => $lockedDay]);
            bad('★ نثرية على يوم متقفل اتقبلت!');
        } catch (RepSubmissionValidationException $e) { $rejected++; }
        $tried++;
        try {
            RepDriverHandover::create($repA, ['bawali_id' => $bawaliId, 'driver_name' => $tag,
                'amount' => 10, 'handover_date' => $lockedDay, 'note' => $tag]);
            bad('★ تسليم سائق على يوم متقفل اتقبل!');
        } catch (RepSubmissionValidationException $e) {
            // لازم يترفض **بسبب اليوم** مش بسبب حقل ناقص، وإلا نجاح كاذب.
            chk(isset($e->errors()['handover_date']), 'رفض التسليم بسبب اليوم المتقفل تحديدًا');
            $rejected++;
        }
        if (RepTransfer::available()) {
            $tried++;
            try {
                RepTransfer::create($repA, ['to_rep_id' => $repB, 'amount' => 10,
                    'transfer_date' => $lockedDay, 'note' => $tag]);
                bad('★ تحويل على يوم متقفل اتقبل!');
            } catch (RepSubmissionValidationException $e) { $rejected++; }
        }
        chk($rejected === $tried, "كل مسارات الكتابة ($tried) رفضت اليوم المتقفل");

        chk((int) Database::scalar(
            "SELECT COUNT(*) FROM rep_submissions WHERE rep_id=? AND entry_date=?",
            [$repA, $lockedDay]) === 0, 'ولا صف اتكتب على اليوم المتقفل');
    }

    // ═══ 6) اليوم المفتوح بيعدّي + تجاوز العهدة مسموح بتحذير
    sec('6) اليوم المفتوح بيعدّي، وتجاوز عهدة اليوم مسموح (قرار المالك)');
    $before = RepCustody::balance($repA, $d2);
    $subId = RepPortal::submit($repA, ['permit_id' => $permitId, 'kind' => 'expense',
        'note' => $tag . ' مقبول', 'qty' => 1, 'unit_amount' => 25, 'entry_date' => $d2]);
    chk($subId > 0, 'بند على يوم مفتوح اتقبل');
    near('البند المستنّي ما بيغيّرش عهدة اليوم (لسه ما اتعتمدش)',
        RepCustody::balance($repA, $d2), $before);
    near('لكنه بيقلّ من «الفاضل معاك» (فلوسه خرجت فعلاً)',
        RepPortal::dayPocket($repA, $d2)['pocket'], $before - 25);

    $huge = RepPortal::submit($repA, ['permit_id' => $permitId, 'kind' => 'expense',
        'note' => $tag . ' تجاوز', 'qty' => 1, 'unit_amount' => 999999, 'entry_date' => $d2]);
    chk($huge > 0, '★ بند أكبر من عهدة اليوم اتقبل (تحذير مش منع)');
    chk(RepPortal::dayPocket($repA, $d2)['pocket'] < 0, 'وعهدة اليوم بانت سالبة زي ما المفروض');

    // ═══ 7) الفيزا لسه مستثناة (v45) على مستوى اليوم كمان
    sec('7) المدفوع بالفيزا مستثنى من عهدة اليوم (v45 محفوظة)');
    $visaBase = RepCustody::balance($repA, $d1);
    Database::exec(
        "INSERT INTO permit_stages(permit_id,stage_key,direction,amount,entry_date,status,is_visa,paid_by_rep_id)
         VALUES(?,'custom','expense',?,?,'done',1,?)", [$permitId, 777, $d1, $repA]);
    Database::exec("INSERT INTO rep_misc_expenses(rep_id,exp_date,note,amount,is_visa) VALUES(?,?,?,?,1)",
        [$repA, $d1, $tag . ' فيزا', 88]);
    near('بند الفيزا ما خصمش من عهدة اليوم', RepCustody::balance($repA, $d1), $visaBase);

    // ═══ 8) العزل المالي
    sec('8) العزل: حركة المحفظة لوحدها ما بتلمسش الخزنة');
    /* ⚠️ **التوقّع واعي بالنموذج** — قرار المالك (2026-07-30): «أي عهدة تتضاف
   للمندوب كأنها في الدرج»، فتنزيل البنك بقى **بيزوّد** رصيد الخزنة.
   `RepCustody::bankTopupActive()` هي المصدر الوحيد للفحص. */
    $depAdds = RepCustody::bankTopupActive();
    $safeA = Closing::currentBalance();
    RepCustody::createDeposit($repA, 4321, $d2, 'اختبار عزل');
    near($depAdds ? 'تنزيل العهدة **زوّد** خزنة المكتب (الخزنة = كل كاش الشركة)'
                  : 'تنزيل العهدة ما غيّرش خزنة المكتب',
         Closing::currentBalance(), $safeA + ($depAdds ? 4321 : 0));
    $safeA = Closing::currentBalance();   // نقطة الأساس بقت بعد التنزيل
    if (RepTransfer::available()) {
        RepTransfer::create($repA, ['to_rep_id' => $repB, 'amount' => 21,
            'transfer_date' => $d2, 'note' => $tag]);
        near('التحويل بين المناديب ما غيّرش الخزنة', Closing::currentBalance(), $safeA);
    }
    RepDriverHandover::create($repA, ['bawali_id' => $bawaliId, 'driver_name' => $tag . '_d2',
        'amount' => 33, 'handover_date' => $d2, 'note' => $tag]);
    near('تسليم السائق ما غيّرش الخزنة', Closing::currentBalance(), $safeA);

    // ═══ 9) الأمان: مفيش سعر عميل بيتسرّب لبوابة المندوب
    sec('9) قاعدة v35 لسه سارية: مفيش رقم بيخصّ العميل في عهدة اليوم');
    $keys = array_keys(RepPortal::dayPocket($repA, $d2));
    $leak = array_values(array_filter($keys, static fn($k) => str_contains((string) $k, 'actual')));
    chk(!$leak, 'dayPocket مفيهاش أي مفتاح actual_* (سعر العميل)');
    $ledgerKeys = array_keys(RepCustody::dayLedger($repA)[0] ?? []);
    chk(!array_filter($ledgerKeys, static fn($k) => str_contains((string) $k, 'actual')),
        'كشف عهدة كل يوم مفيهوش سعر العميل');
    // ═══ 9ب) عزل شاشة المندوب عن أي يوم تاني (الباج اللي المالك بلّغ عنه)
    sec('9ب) ★ شاشة المندوب ما بتعرضش ولا رقم ولا بند من يوم تاني');
    // البند ده على يوم ١، والمندوب شغّال بيوم ٢ — ممنوع يبان له خالص.
    RepPortal::submit($repA, ['permit_id' => $permitId, 'kind' => 'receipt',
        'note' => $tag . ' بند يوم قديم', 'qty' => 1, 'unit_amount' => 12, 'entry_date' => $d1]);

    $shown = RepPortal::mySubmissions($repA, 40, '', $d2);
    $wrongDay = array_filter($shown, static fn(array $r): bool => (string) $r['entry_date'] !== $d2);
    chk(!$wrongDay, '★ «بنودي» فيها بنود يوم العمل بس (ولا بند من يوم تاني)');
    chk((bool) array_filter($shown, static fn(array $r): bool => (string) $r['entry_date'] === $d2),
        'وبنود اليوم نفسه ظاهرة عادي');

    $shownD1 = RepPortal::mySubmissions($repA, 40, '', $d1);
    chk((bool) array_filter($shownD1, static fn(array $r): bool => str_contains((string) $r['note'], 'بند يوم قديم')),
        'ولو غيّر يوم العمل ليوم ١ بيشوف بنوده — الأيام بتتصفّح مش بتتخفي');

    $cntDay = RepPortal::myCounts($repA, $d2);
    $cntAll = RepPortal::myCounts($repA);
    chk($cntDay['pending'] < $cntAll['pending'],
        '★ عدّاد البنود بيعدّ يوم العمل بس (أقل من إجمالي كل الأيام)');
    chk($cntDay['pending'] === count(array_filter($shown, static fn(array $r): bool => $r['status'] === 'pending')),
        'العدّاد مطابق للقايمة اللي تحته بالظبط');

    $deps = RepPortal::recentDeposits($repA, 5, $d2);
    $wrongDep = array_filter($deps, static fn(array $r): bool => (string) $r['date'] !== $d2);
    chk(!$wrongDep, '★ تنبيه «نزلت عهدة» بيخصّ تنزيلات اليوم ده بس');

    // ⚠️ الحارس الأهم: ولا مفتاح تراكمي في الحزمة اللي بتروح لشاشة المندوب.
    $bad = array_values(array_filter(array_keys($pocket),
        static fn($k) => str_starts_with((string) $k, 'total')));
    chk(!$bad, '★ dayPocket مفيهاش أي رقم تراكمي (' . (implode(',', $bad) ?: 'نضيفة') . ')');

    $ping = RepPortal::pingState($repA, $d2);
    chk($ping['pending'] === $cntDay['pending'], 'الجرس بيقول نفس رقم الشاشة');

    // ═══ 10) الشاشات بترندر فعلاً
    // ⚠️ الفحص ده بيمسك الفشل الصامت الموثَّق في `Controller::render` (مفتاح
    //    بيتخطّى بـEXTR_SKIP فالصفحة تعرض حاجة غلط بلا أي خطأ PHP)، وكمان أي
    //    متغيّر view نسيت أمرّره من الكنترولر.
    sec('10) شاشات المكتب والمندوب بترندر بلا أخطاء');
    $renderCheck = static function (string $label, callable $run, array $needles): void {
        $_SESSION['uid'] = (int) Database::scalar('SELECT MIN(id) FROM users');
        $_SESSION['role'] = 'owner';
        $_SESSION['uname'] = 'فحص v49';
        $_SESSION['last_activity'] = time();
        $_SESSION['uid_checked'] = time();
        ob_start();
        try { $run(); } catch (\Throwable $e) { ob_end_clean(); bad("$label — استثناء: " . $e->getMessage()); $_SESSION = []; return; }
        $html = (string) ob_get_clean();
        $_SESSION = [];
        preg_match('/Fatal error|Warning:|Undefined variable|Undefined array key/', $html, $m)
            ? bad("$label — فيه خطأ PHP في الصفحة: " . ($m[0] ?? ''))
            : ok("$label بترندر بلا أخطاء PHP");
        foreach ($needles as $needle) {
            str_contains($html, $needle)
                ? ok("{$label} فيها «{$needle}»")
                : bad("{$label} مافيهاش «{$needle}»");
        }
    };
    // كارت المندوب اتقسم تبويبات (إعادة تصميم 2026-07-25): «اليوم» هو الافتراضي
    // وكشف «عهدة كل يوم» انتقل لتبويب «الحساب الكامل». **القاعدة نفسها محروسة**:
    // اليوم بيعرض عهدته لوحدها، والكشف بيقول صراحةً إن مفيش ترحيل.
    $renderCheck('كارت عهدة المندوب — تبويب اليوم',
        static function () use ($repA) {
            $_GET['tab'] = 'day';
            (new \App\Controllers\RepCustodyController())->card((string) $repA);
            unset($_GET['tab']);
        },
        // ⚠️ التسمية بقت «فاضل معاه» لما يكون فيه بنود مستنية اعتماد (بتتخصم من
        //    الرقم عشان يوصف اللي في إيده فعلاً) — الفحص على **المعنى**: الرقم
        //    عن اليوم وبيقول صراحةً إن مفيش ترحيل، مش على نصّ تسمية بعينه.
        ['فاضل معاه', 'ترحيل']);
    $renderCheck('كارت عهدة المندوب — تبويب الحساب',
        static function () use ($repA) {
            $_GET['tab'] = 'account';
            (new \App\Controllers\RepCustodyController())->card((string) $repA);
            unset($_GET['tab']);
        },
        ['عهدة كل يوم', 'فاضل معاه', 'بلا ترحيل']);
    /* ⚠️ المرحلة ١١: لوحة المناديب المفصّلة اتشالت من الداشبورد (كانت بتكرّر
       صفحة `/rep-custody` كلها). عهدة المناديب بقت **رقم بيفتح تفاصيله** —
       فالفحص بقى على المعنى (الرقم موجود وله طريق) مش على ماركب اتشال.
       ⚠️ 2026-07-27: الرقم نفسه بقى **يومي** («فاضل مع المناديب») بدل التراكمي
       («برّه مع المناديب») بقرار المالك — فالفحص على المعنى مش على التسمية،
       والقاعدة اليومية نفسها محروسة في `bin/verify_reps_day_scope.php`. */
    $renderCheck('لوحة التحكم',
        static fn() => (new \App\Controllers\DashboardController())->index(),
        /* ⚠️ 2026-08-17: الكارت الواحد بقى ٣ كروت يومية وجدول بحكم لكل مندوب،
           والنافذة بقت `reps_day`. الفحص على **المعنى** زي ما هو: الرقم اليومي
           موجود وله طريق لتفاصيله. */
        ['نزل عهدة', 'dashboard/drill/reps_day']);

    // شاشة المندوب نفسها — بجلسة مندوب مش جلسة مكتب.
    $renderHome = static function (string $workDay) use ($repA): string {
        // الختم = النهاردة عشان الاختيار يبقى ساري (قاعدة 0ب فوق)
        $_SESSION = ['rep_id' => $repA, 'rep_name' => 'فحص v49', 'rep_user' => 'v49',
                     'rep_seen' => time(), 'rep_check' => time(),
                     'rep_work_date' => $workDay, 'rep_work_date_set' => date('Y-m-d')];
        ob_start();
        try { (new \App\Controllers\RepPortalController())->home(); $out = (string) ob_get_clean(); }
        catch (\Throwable $e) { ob_end_clean(); $out = ''; bad('شاشة المندوب — استثناء: ' . $e->getMessage()); }
        $_SESSION = [];
        return $out;
    };
    $homeHtml = $renderHome($d2);
    if ($homeHtml !== '') {
        preg_match('/Fatal error|Warning:|Undefined variable|Undefined array key/', $homeHtml, $m)
            ? bad('شاشة المندوب — خطأ PHP: ' . ($m[0] ?? ''))
            : ok('شاشة المندوب بترندر بلا أخطاء PHP');
        chk(!str_contains($homeHtml, 'إجمالي اللي معاك من كل الأيام'),
            '★ الشاشة مفيهاش سطر «إجمالي اللي معاك من كل الأيام»');
        chk(!str_contains($homeHtml, 'بند يوم قديم'),
            '★ الشاشة مفيهاش بند اليوم السابق');
        // ⚠️ العنوان بقى بيسمّي اليوم لو مش النهاردة، فالفحص على **الحالة**
        //    الصح لكل حالة مش على نص واحد ثابت.
        $isToday = $d2 === date('Y-m-d');
        chk(str_contains($homeHtml, $isToday ? 'عهدة اليوم' : 'عهدة يوم ' . dfmt($d2)),
            'الشاشة بتسمّي اليوم اللي الرصيد بتاعه صح');
        chk(str_contains($homeHtml, $isToday ? 'نزل لك اليوم' : 'نزل لك يوم'),
            'وتفاصيلها كلها بأرقام نفس اليوم');
    }

    // ★ لو يوم العمل مش النهاردة لازم تحذير صريح — الرقم مش عهدة النهاردة
    $pastOpen = null;
    for ($i = 1; $i < 400 && $pastOpen === null; $i++) {
        $cand = date('Y-m-d', strtotime("-$i day"));
        if (!RepCustody::isDayLocked($cand)) { $pastOpen = $cand; }
    }
    if ($pastOpen === null) {
        echo "  ~ مفيش يوم قديم مفتوح — اتخطّى فحص تحذير اليوم القديم\n";
    } else {
        $pastHtml = $renderHome($pastOpen);
        if ($pastHtml !== '') {
            /* ⚠️ لوح `.hm-pastday` اتشال بقرار المالك (2026-08-02) — كان
               فقرة شرح تلات سطور. **الحماية اللي اتعمل عشانها لازم تفضل**،
               فالفحص اتحوّل من «اللوح موجود» لـ«اليوم بيتقال والطريق مفتوح»:
               عنوان الرصيد بيسمّي اليوم، وشريحة التاريخ بتاخد `is-past` وفيها
               رابط تغيير اليوم. ده تشديد على البديل مش ترخية للقاعدة. */
            chk(str_contains($pastHtml, 'عهدة يوم ' . dfmt($pastOpen)),
                '★ عنوان الرصيد بيقول اسم اليوم مش «اليوم»');
            chk(str_contains($pastHtml, 'hm-wd is-past') || str_contains($pastHtml, 'is-past'),
                '★ وشريحة التاريخ موسومة إنها يوم فات');
            chk(str_contains($pastHtml, url('m/work-date')),
                'وفيه طريق واضح يغيّر بيه اليوم');
            chk(!str_contains($pastHtml, 'hm-pastday') && !str_contains($pastHtml, 'hm-nodep'),
                '★ ولوحا الشرح المشالين ما رجعوش');
        }
    }
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}

sec('11) بعد الـrollback');
near('رصيد الخزنة رجع زي ما كان', Closing::currentBalance(), $treasuryBefore);
near('إجمالي الفلوس برّه مع المناديب رجع زي ما كان',
    RepCustody::totalOutstanding(), $outstandingBefore);

echo "\n" . str_repeat('=', 62) . "\n";
if ($failures) {
    echo 'فشل ' . count($failures) . " فحص من أصل " . ($pass + count($failures)) . ":\n";
    foreach ($failures as $f) { echo "  ✗ $f\n"; }
    exit(1);
}
echo "اكتمل فحص v49 بنجاح — $pass فحص.\n";
