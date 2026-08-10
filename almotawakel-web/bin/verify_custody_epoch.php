<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص «خط الفصل — بداية النظام الجديد لعهدة المناديب» (المرحلة ١).
 *
 * الخطة الكاملة: docs/16-custody-unification-plan.md
 *
 * ═══ المشكلة اللي الميزة اتعملت عشانها ═══
 * قبل 2026-07-29 كانت عهدة السائق بتتخصم من **خزنة المكتب** والمندوب هو اللي
 * دفعها من محفظته — فرصيد كل مندوب فضل متضخّم بمقدار اللي سلّمه للسواقين.
 * الإثبات المقيس على الإنتاج:
 *     برّه مع المناديب (704,605) − خزنة 2026-07-27 (17,575) = 687,030
 *     عهد السواقين من 2026-07-20                            = 690,500
 * فرق ~3.5k على شهر شغل. بدل ما نمسح أو نعدّل صفوفًا قديمة (وده بيضيّع تاريخ
 * الشغل ويخالف قرار المالك «البنود ما تتلمسش إطلاقًا») بنبدأ العدّ من تاريخ معلوم.
 *
 * ═══ أخطر ٦ حاجات محروسة هنا ═══
 *   ① **الميزة مطفية = السلوك القديم بالحرف.** بلا الإعداد مفيش رقم واحد بيتغيّر،
 *      فالكود ينفع يترفع قبل التفعيل، والتراجع = مسح إعداد واحد.
 *   ② **تغطية نقاط المزامنة.** الفلتر لازم يوصل كل موديل بيحسب رصيد مندوب.
 *      نسيان موضع واحد = كارت يقول رقم وكشف يقول رقم تاني **بلا أي خطأ ظاهر**.
 *   ③ **حدّ النطاق.** ممنوع الفلتر يوصل `Closing` أو `Custody` (خزنة المكتب) —
 *      الخزنة مربوطة بـ`daily_closings` والقفل هو اللي بيجمّد ما قبل خط الفصل.
 *      لو وصلها، الرصيد المرحّل بيتغيّر ويوم متقفل يتعاد حسابه غلط.
 *   ④ **العزل المالي.** تفعيل الميزة ما بيغيّرش رصيد الخزنة ولا صف إقفال واحد.
 *   ⑤ **البنود ما بتتلمسش.** عدد الصفوف ومجموعها وتوزيع حالاتها قبل = بعد.
 *   ⑥ **حارس الاعتماد في الموديل مش في الشاشة** — والبند القديم بيترفض فعليًّا.
 *
 * كله جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_custody_epoch.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Database;
use App\Models\Closing;
use App\Models\Custody;
use App\Models\RepBillCustody;
use App\Models\RepCustody;
use App\Models\RepDriverHandover;
use App\Models\RepInbox;
use App\Models\RepMiscExpense;
use App\Models\RepPortal;
use App\Models\RepTransfer;
use App\Models\RepWatch;

const EPOCH = '2026-07-29';

$pass = 0; $fail = 0; $notes = [];
function ok(string $m): void   { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  ✗ $m\n"; }
function note(string $m): void { global $notes; $notes[] = $m; echo "  … $m\n"; }
function sec(string $m): void  { echo "\n" . $m . "\n" . str_repeat('─', 66) . "\n"; }
function near(string $l, $a, $e): void {
    abs((float) $a - (float) $e) <= 0.005 ? ok($l)
        : bad("$l — متوقع " . number_format((float) $e, 2) . " / فعلي " . number_format((float) $a, 2));
}
function src(string $file): string {
    $p = dirname(__DIR__) . '/' . $file;
    return is_file($p) ? (string) file_get_contents($p) : '';
}
/** ⚠️ التعليقات بتتشال قبل أي فحص نصّي — التعليق اللي بيوثّق قاعدة بيذكر نصّها
 *  فبيدّي نجاحًا أو فشلًا كاذبًا (مصيدة موثَّقة في verify_security و verify_ux). */
function code_only(string $s): string {
    $s = preg_replace('~/\*.*?\*/~s', '', $s) ?? $s;
    return preg_replace('~^\s*//.*$~m', '', $s) ?? $s;
}

echo "\n═══ فحص خط الفصل لعهدة المناديب (المرحلة ١) ═══\n";

// ─────────────────────────────────────────────────────────── ① الآلية
sec('① الآلية — مصدر واحد ومطفي افتراضيًّا');

$rc = src('app/Models/RepCustody.php');
foreach (['epoch()' => 'public static function epoch(): string',
          'epochSql()' => 'public static function epochSql(string $col): string',
          'refreshEpoch()' => 'public static function refreshEpoch(): void'] as $lbl => $needle) {
    str_contains($rc, $needle) ? ok("$lbl موجودة في RepCustody") : bad("$lbl ناقصة");
}
str_contains($rc, "preg_match('/^\\d{4}-\\d{2}-\\d{2}\$/', \$v) ? \$v : ''")
    ? ok('التاريخ متحقَّق من شكله قبل اللزق في SQL (YYYY-MM-DD حرفيًّا)')
    : bad('⚠️ التاريخ مش متحقَّق من شكله — لزقه في SQL خطر');

$savedEpoch = (string) App::setting('rep_custody_epoch', '');
App::setSetting('rep_custody_epoch', ''); RepCustody::refreshEpoch();
RepCustody::epochSql('dep_date') === ''
    ? ok('بلا الإعداد → epochSql بترجّع "" (السلوك القديم بالحرف)')
    : bad('⚠️ الميزة شغّالة بلا إعداد — الرفع قبل التفعيل هيغيّر أرقامًا');
App::setSetting('rep_custody_epoch', 'كلام غلط'); RepCustody::refreshEpoch();
RepCustody::epochSql('dep_date') === ''
    ? ok('إعداد بشكل غلط → بتترفض وبيرجع للسلوك القديم بدل ما يكسر')
    : bad('إعداد بشكل غلط عدّى — ممكن يلزق نص عشوائي في SQL');

// ─────────────────────────────────────────────────────────── ② التغطية
sec('② تغطية نقاط المزامنة — كل موديل بيحسب رصيد مندوب');

$models = [
    'app/Models/RepCustody.php'        => 14,
    'app/Models/RepTransfer.php'       => 6,
    'app/Models/RepMiscExpense.php'    => 5,
    'app/Models/RepDriverHandover.php' => 5,
    'app/Models/RepBillCustody.php'    => 5,
    'app/Models/RepPortal.php'         => 4,
    'app/Models/RepWatch.php'          => 4,
];
$total = 0;
foreach ($models as $file => $min) {
    $n = substr_count(code_only(src($file)), 'epochSql(');
    if ($file === 'app/Models/RepCustody.php') { $n--; }   // التعريف نفسه
    $total += $n;
    $n >= $min ? ok(basename($file) . " — $n موضع (الحد الأدنى $min)")
               : bad(basename($file) . " — $n موضع بس، المتوقع $min على الأقل");
}
$total >= 43 ? ok("الإجمالي $total نقطة مزامنة") : bad("الإجمالي $total — أقل من المتوقع");

// ⚠️ ممنوع أي موديل يكتب شرط التاريخ بإيده بدل ما ينادي المصدر الوحيد.
$hand = 0;
foreach (array_keys($models) as $file) {
    if (preg_match('~>=\s*[\'"]20\d\d-\d\d-\d\d[\'"]~', code_only(src($file)))) {
        bad(basename($file) . ' — فيه تاريخ مكتوب بالإيد بدل epochSql'); $hand++;
    }
}
$hand === 0 ? ok('مفيش أي شرط تاريخ مكتوب بالإيد في موديلات المناديب') : null;

// ─────────────────────────────────────────────────────────── ③ حدّ النطاق
sec('③ حدّ النطاق — الفلتر ممنوع يوصل خزنة المكتب');

$safeFiles = [
    'app/Models/Closing.php', 'app/Models/Custody.php', 'app/Models/DailyReview.php',
    'app/Models/Expense.php', 'app/Models/Metrics.php',
    'app/Controllers/DailyClosingController.php', 'bin/backfill_closings.php',
];
$leak = 0;
foreach ($safeFiles as $f) {
    if (str_contains(code_only(src($f)), 'epochSql') || str_contains(code_only(src($f)), 'rep_custody_epoch')) {
        bad(basename($f) . ' — ⚠️ استخدم خط فصل المناديب في حساب خزنة!'); $leak++;
    }
}
$leak === 0 ? ok('ولا ملف من ملفات الخزنة السبعة استخدم خط الفصل') : null;

// ─────────────────────────────────────────────────────────── لقطة ما قبل
// ⚠️ **اللقطة بتتاخد بالإعداد الحقيقي مش بإطفاء قسري.** القسم ① فوق بيطفّي
//    الميزة عشان يختبرها، ولو سِبنا الإطفاء ده واللقطة اتاخدت عليه، القسم ⑧
//    هيقارن «قبل والميزة مطفية» بـ«بعد والميزة شغّالة» ويفشل بإنذار كاذب
//    (اتصاد فعليًّا أول ما الميزة اتفعّلت على الإنتاج).
App::setSetting('rep_custody_epoch', $savedEpoch); RepCustody::refreshEpoch();
$reps = Database::all('SELECT id, name FROM reps ORDER BY id');
$snapshot = static function () use ($reps): array {
    $o = ['safe' => Closing::currentBalance(), 'out' => RepCustody::totalOutstanding()];
    foreach ($reps as $r) { $o['rep' . $r['id']] = RepCustody::balance((int) $r['id']); }
    return $o;
};
$subsFingerprint = static function (): string {
    $r = Database::row("SELECT COUNT(*) c, COALESCE(SUM(amount),0) s,
                               SUM(status='pending') p, SUM(status='approved') a,
                               SUM(status='rejected') j FROM rep_submissions");
    return json_encode($r, JSON_UNESCAPED_UNICODE) ?: '';
};
$closingsFingerprint = static function (): string {
    return (string) Database::scalar(
        "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS('|',close_date,opening,topups,expenses,
                driver_custody,closing_balance) ORDER BY close_date),'') FROM daily_closings");
};
$before  = $snapshot();
$subsBef = $subsFingerprint();
$clsBef  = $closingsFingerprint();

// ─────────────────────────────────────────────────────────── ④ + ⑤ + ⑥ سلوكيًّا
$pdo = Database::conn();
$pdo->beginTransaction();
try {
    sec('④ التفعيل — كل الدوال بتشتغل والأرقام متسقة');

    App::setSetting('rep_custody_epoch', EPOCH); RepCustody::refreshEpoch();
    RepCustody::epoch() === EPOCH ? ok('الإعداد اتقرا: ' . EPOCH) : bad('الإعداد ما اتقراش');
    RepCustody::epochSql('dep_date') === " AND dep_date >= '" . EPOCH . "'"
        ? ok('شرط SQL مبني صح') : bad('شرط SQL شكله غلط');

    // ⚠️ **لقطة الخزنة بتتاخد هنا بالظبط** — بعد التفعيل وقبل أي fixture.
    //    الفحوصات اللاحقة بتعتمد بنودًا (⑥) وكل اعتماد بيخصم من الخزنة (قاعدة
    //    v42)، فقياس الفرق في آخر الفاحص بيدّي **فشلًا كاذبًا** بمقدار الـfixtures.
    //    السؤال الصح: «هل *تفعيل الميزة* لوحده غيّر الخزنة؟» — والإجابة لازم تبقى لأ.
    $safeOnEnable = Closing::currentBalance();

    $rid = (int) ($reps[0]['id'] ?? 0);
    $calls = [
        'RepCustody::balance'            => fn() => RepCustody::balance($rid),
        'RepCustody::breakdown'          => fn() => RepCustody::breakdown($rid),
        'RepCustody::dayLedger'          => fn() => RepCustody::dayLedger($rid),
        'RepCustody::dayTotalsByRep'     => fn() => RepCustody::dayTotalsByRep(EPOCH),
        'RepCustody::daySpending'        => fn() => RepCustody::daySpending(EPOCH),
        'RepCustody::dayDeposits'        => fn() => RepCustody::dayDeposits(EPOCH),
        'RepCustody::cards'              => fn() => RepCustody::cards(),
        'RepCustody::deposits'           => fn() => RepCustody::deposits($rid),
        'RepCustody::spending'           => fn() => RepCustody::spending($rid),
        'RepCustody::settlements'        => fn() => RepCustody::settlements($rid),
        'RepCustody::openDays'           => fn() => RepCustody::openDays($rid),
        'RepCustody::clearSnapshot'      => fn() => RepCustody::clearSnapshot($rid),
        'RepCustody::dayActivity'        => fn() => RepCustody::dayActivity($rid, EPOCH),
        'RepCustody::dashboard'          => fn() => RepCustody::dashboard(EPOCH),
        'RepCustody::totalOutstanding'   => fn() => RepCustody::totalOutstanding(),
        'RepTransfer::inTotal'           => fn() => RepTransfer::inTotal($rid),
        'RepTransfer::inTotalsByRep'     => fn() => RepTransfer::inTotalsByRep(),
        'RepTransfer::inTotalsByDay'     => fn() => RepTransfer::inTotalsByDay($rid),
        'RepTransfer::totalAll'          => fn() => RepTransfer::totalAll(),
        'RepTransfer::outgoingFor'       => fn() => RepTransfer::outgoingFor($rid),
        'RepTransfer::incomingFor'       => fn() => RepTransfer::incomingFor($rid),
        'RepMiscExpense::totalFor'       => fn() => RepMiscExpense::totalFor($rid),
        'RepMiscExpense::totalsByDay'    => fn() => RepMiscExpense::totalsByDay($rid),
        'RepMiscExpense::totalsByRep'    => fn() => RepMiscExpense::totalsByRep(),
        'RepMiscExpense::totalAll'       => fn() => RepMiscExpense::totalAll(),
        'RepMiscExpense::forRep'         => fn() => RepMiscExpense::forRep($rid),
        'RepDriverHandover::totalFor'    => fn() => RepDriverHandover::totalFor($rid),
        'RepDriverHandover::totalsByDay' => fn() => RepDriverHandover::totalsByDay($rid),
        'RepDriverHandover::totalsByRep' => fn() => RepDriverHandover::totalsByRep(),
        'RepDriverHandover::totalAll'    => fn() => RepDriverHandover::totalAll(),
        'RepDriverHandover::forRep'      => fn() => RepDriverHandover::forRep($rid),
        'RepBillCustody::totalFor'       => fn() => RepBillCustody::totalFor($rid),
        'RepBillCustody::totalsByDay'    => fn() => RepBillCustody::totalsByDay($rid),
        'RepBillCustody::totalsByRep'    => fn() => RepBillCustody::totalsByRep(),
        'RepBillCustody::totalAll'       => fn() => RepBillCustody::totalAll(),
        'RepBillCustody::forRep'         => fn() => RepBillCustody::forRep($rid),
        'RepPortal::pendingAmount'       => fn() => RepPortal::pendingAmount($rid),
        'RepPortal::myCounts'            => fn() => RepPortal::myCounts($rid, EPOCH),
        'RepPortal::mySubmissions'       => fn() => RepPortal::mySubmissions($rid, 40, '', EPOCH),
        'RepPortal::dayPocket'           => fn() => RepPortal::dayPocket($rid, EPOCH),
    ];
    $broken = [];
    foreach ($calls as $n => $f) { try { $f(); } catch (\Throwable $e) { $broken[] = "$n → " . $e->getMessage(); } }
    $broken ? bad(count($broken) . ' دالة رمت خطأ: ' . implode(' | ', array_slice($broken, 0, 3)))
            : ok(count($calls) . ' دالة اشتغلت بلا أي خطأ SQL');

    // اتساق داخلي: الرصيد = المعادلة المعلنة، لكل مندوب.
    $bad2 = 0;
    foreach ($reps as $r) {
        $b = RepCustody::breakdown((int) $r['id']);
        $calc = $b['deposits'] + $b['transfer_in'] - $b['spent'] - $b['returned'] - $b['transfer_out'];
        if (abs($calc - $b['balance']) > 0.005) { $bad2++; }
    }
    $bad2 === 0 ? ok('الرصيد = نزل + من زميل − صرف − رجّع − لزميل، لكل مندوب')
                : bad("$bad2 مندوب رصيده مش مطابق للمعادلة");

    // الكارت (استعلام مجمَّع) لازم يطابق balance() (استعلامات مفردة) بالمليم —
    // ⚠️ ده الفحص اللي بيمسك «موضع اتنسي في cards بس».
    $mismatch = [];
    foreach (RepCustody::cards() as $c) {
        $exp = RepCustody::balance((int) $c['id']);
        if (abs((float) $c['balance'] - $exp) > 0.005) { $mismatch[] = $c['name']; }
    }
    $mismatch ? bad('كارت المناديب مخالف لـbalance(): ' . implode('، ', $mismatch))
              : ok('كارت المناديب = balance() بالمليم لكل مندوب');

    // ─────────────────────────────────────────────── ⑤ أرقام خطة §5
    sec('⑤ أرقام بداية النظام الجديد (docs/16 §5)');

    $has29 = (bool) Database::scalar(
        'SELECT COUNT(*) FROM rep_custody_deposits WHERE dep_date = ?', [EPOCH]);
    if (!$has29) {
        note('مفيش بيانات ' . EPOCH . ' في القاعدة دي (محلية غالبًا) — فحص الأرقام اتخطّى');
    } else {
        // ⚠️ التنزيلان الناقصان (خطوة 1.3) بيتزرعوا هنا لو لسه مش موجودين، عشان
        //    الفاحص يشتغل قبل التنفيذ وبعده بنفس النتيجة.
        /* ⚠️ **الفحص على تنزيلات `bank` بس، مش على مجموع اليوم.**
           المرحلة ٢ ضافت تنزيلات `carry` بنفس التاريخ (الكاش اللي كان في جيب
           عبدالرحمن والسيد آخر يوم ٢٨ — تصحيح Q4)، فمجموع اليوم بقى 29,000 و
           12,930 بدل 21,000 و10,000. الرقمان اللي المالك قرّرهم في المرحلة ١
           هما **عهدة البنك** تحديدًا، والفحص لازم يبقى عليهم بالظبط وإلا
           بيفشل بإنذار كاذب مع كل إضافة مشروعة على نفس اليوم. */
        $srcCol = RepCustody::sourceReady() ? " AND source = 'bank'" : '';
        foreach (['السيد' => 21000.0, 'عبدالرحمن' => 10000.0] as $needle => $amt) {
            foreach ($reps as $r) {
                if (!str_contains((string) $r['name'], $needle)) { continue; }
                $sum = static fn(int $id): float => (float) Database::scalar(
                    'SELECT COALESCE(SUM(amount),0) FROM rep_custody_deposits
                      WHERE rep_id=? AND dep_date=?' . $srcCol, [$id, EPOCH]);
                $have = $sum((int) $r['id']);
                if ($have < $amt - 0.005) {
                    RepCustody::createDeposit((int) $r['id'], $amt - $have, EPOCH, 'عهدة ' . EPOCH);
                }
                near("«{$needle}» عهدة البنك في " . EPOCH, $sum((int) $r['id']), $amt);
            }
        }

        // ⚠️⚠️ **الفحص هيكلي مش بأرقام محفوظة** — الإنتاج بيتحرّك (اتصاد فعليًّا:
        //    تحويل جديد اتسجّل بين سحب الأرقام وتشغيل الفاحص فغيّر رصيد مندوب).
        //    فبنحسب المتوقع بـSQL خام **مستقل عن الموديل** من الصفوف المؤرَّخة
        //    من خط الفصل ورايح، ونقارنه بالموديل. ده بيمسك أي موضع مزامنة اتنسي
        //    مهما اتغيّرت البيانات.
        $one = static fn (string $sql, array $a): float => round((float) Database::scalar($sql, $a), 2);
        $mismatch = [];
        foreach ($reps as $r) {
            $id = (int) $r['id'];
            $exp = $one("SELECT COALESCE(SUM(amount),0) FROM rep_custody_deposits WHERE rep_id=? AND dep_date>=?", [$id, EPOCH])
                 + $one("SELECT COALESCE(SUM(amount),0) FROM rep_custody_transfers WHERE to_rep_id=? AND transfer_date>=?", [$id, EPOCH])
                 - $one("SELECT COALESCE(SUM(amount),0) FROM rep_custody_transfers WHERE from_rep_id=? AND transfer_date>=?", [$id, EPOCH])
                 - $one("SELECT COALESCE(SUM(amount),0) FROM permit_stages WHERE paid_by_rep_id=? AND is_visa=0 AND entry_date>=?", [$id, EPOCH])
                 - $one("SELECT COALESCE(SUM(amount),0) FROM rep_misc_expenses WHERE rep_id=? AND is_visa=0 AND exp_date>=?", [$id, EPOCH])
                 - $one("SELECT COALESCE(SUM(amount),0) FROM rep_driver_handovers WHERE rep_id=? AND handover_date>=?", [$id, EPOCH])
                 /* ⚠️ **العهدة بعد طرح الـ100 مش الخام** — السائق بياخد
                    `custody − 100` فعلاً (قاعدة v60 المصحَّحة)، والموديل بيطرحها
                    عبر `Custody::deductionSql`. الحساب الخام هنا كان بيجمع
                    الإجمالي، فأول ما اتوسمت بوالص فعليًّا على الإنتاج (ميزة ٣.٣)
                    الفرق ظهر: إبراهيم 100 (بوليصة) والسيد 200 (بوليصتين).
                    ⚠️ بننادي نفس الدالة عن قصد — كتابة قاعدة الـ100 بالإيد هنا
                       معناها تعريفان ممكن يفترقوا. */
                 - (static function () use ($id): float {
                       [$ded, $dp] = Custody::deductionSql('custody', 'car_no');
                       $args = $dp; $args[] = $id; $args[] = EPOCH;
                       return round((float) Database::scalar(
                           "SELECT COALESCE(SUM($ded),0) FROM bawalis
                             WHERE custody_rep_id=? AND is_legacy=0 AND bill_date>=?", $args), 2);
                   })()
                 - $one("SELECT COALESCE(SUM(returned),0) FROM rep_custody_settlements WHERE rep_id=? AND settle_date>=?", [$id, EPOCH]);
            if (abs(RepCustody::balance($id) - $exp) > 0.005) {
                $mismatch[] = $r['name'] . ' (موديل ' . number_format(RepCustody::balance($id), 2)
                            . ' / SQL خام ' . number_format($exp, 2) . ')';
            }
        }
        $mismatch ? bad('رصيد مخالف للحساب المستقل: ' . implode('، ', $mismatch))
                  : ok(count($reps) . ' مندوب — الموديل = حساب SQL مستقل من ' . EPOCH . ' بالمليم');

        // ⚠️ **الفحص اللي بيثبت إن الفلتر بيشتغل أصلاً**: لازم يكون فيه مندوب
        //    واحد على الأقل رصيده التراكمي (بلا فلتر) **أكبر** من رصيده المفلتر،
        //    وإلا يبقى الفلتر مش بيستبعد حاجة والفاحص كله بينجح كذبًا على لا شيء.
        $filtered = [];
        foreach ($reps as $r) { $filtered[(int) $r['id']] = RepCustody::balance((int) $r['id']); }
        App::setSetting('rep_custody_epoch', ''); RepCustody::refreshEpoch();
        $excluded = 0;
        foreach ($reps as $r) {
            if (RepCustody::balance((int) $r['id']) - $filtered[(int) $r['id']] > 0.005) { $excluded++; }
        }
        App::setSetting('rep_custody_epoch', EPOCH); RepCustody::refreshEpoch();
        $excluded > 0 ? ok("الفلتر استبعد فعلاً أرصدة ما قبل خط الفصل عند $excluded مندوب")
                      : bad('⚠️ الفلتر ما استبعدش أي حاجة — يبقى مش شغّال والفحوصات فوق بتنجح على لا شيء');

        // «فاضل معاه» = الرصيد − المستنّي الكاش. تعريف واحد في كل شاشة.
        $bad3 = 0;
        foreach ($reps as $r) {
            $id = (int) $r['id'];
            $p = $one("SELECT COALESCE(SUM(amount),0) FROM rep_submissions
                       WHERE rep_id=? AND status='pending' AND " . RepPortal::pendingCashSql()
                       . " AND entry_date>=?", [$id, EPOCH]);
            if (abs(RepPortal::pendingAmount($id) - $p) > 0.005) { $bad3++; }
        }
        $bad3 === 0 ? ok('المستنّي الكاش لكل مندوب = حساب مستقل (الفيزا وغير المدفوع مستثنيان)')
                    : bad("$bad3 مندوب المستنّي بتاعه مخالف");
    }

    // ─────────────────────────────────────────────── ⑥ حارس الاعتماد
    sec('⑥ حارس اعتماد البنود القديمة');

    $ri = code_only(src('app/Models/RepInbox.php'));
    str_contains($ri, 'private static function epochBlock(array $s): ?string')
        ? ok('الحارس متعرَّف في الموديل (RepInbox::epochBlock)') : bad('الحارس مش متعرَّف');
    str_contains($ri, 'self::epochBlock($s)')
        ? ok('بيتنادى جوّه approve() — قبل أي كتابة') : bad('مش متنادى في approve()');
    preg_match('~function approveMany.*?self::approve\(~s', $ri)
        ? ok('approveMany بتلفّ على approve فالإجراء الجماعي محمي') : bad('approveMany مش بتمرّ على approve');

    $pid = (int) Database::scalar('SELECT id FROM permits ORDER BY id DESC LIMIT 1');
    $mid = (int) Database::scalar('SELECT id FROM permit_movements WHERE permit_id=? LIMIT 1', [$pid]);
    if ($pid && $mid && $rid) {
        $mk = static function (string $d) use ($rid, $pid, $mid): int {
            Database::exec(
                "INSERT INTO rep_submissions (rep_id,permit_id,movement_id,kind,note,qty,unit_amount,amount,entry_date,status)
                 VALUES (?,?,?,'receipt','فحص خط الفصل',1,100,100,?,'pending')", [$rid, $pid, $mid, $d]);
            return Database::lastId();
        };
        // ⚠️ لازم نفحص **سبب** الرفض مش مجرد إنه اترفض: على الإنتاج يوم 2026-07-28
        //    متقفل، فـ`Closing::guard` بترفض البند برضه — ولو اكتفينا بـ«اترفض»
        //    الفحص بينجح كذبًا وهو ما جرّبش الحارس بتاعنا أصلاً (مصيدة v49).
        $why = static function (int $id): string {
            try { RepInbox::approve($id); return ''; } catch (\Throwable $e) { return $e->getMessage(); }
        };
        $m = $why($mk('2026-07-28'));
        str_contains($m, 'بداية النظام الجديد')
            ? ok('بند 2026-07-28 اترفض **بسبب خط الفصل بالتحديد** (مش بسبب قفل اليوم)')
            : bad($m === '' ? '⚠️ بند من قبل خط الفصل اتعمد — الخصم هيتكرر!'
                            : 'اترفض بسبب تاني مش خط الفصل: ' . mb_substr($m, 0, 60));
        $why($mk(EPOCH)) === '' ? ok('بند تاريخه ' . EPOCH . ' اتعمد عادي')
                                : bad('بند بعد خط الفصل اترفض — الحارس واسع أكتر من اللازم');
        App::setSetting('rep_custody_epoch', ''); RepCustody::refreshEpoch();
        !str_contains($why($mk('2026-07-28')), 'بداية النظام الجديد')
            ? ok('بإطفاء الميزة حارس خط الفصل بيسكت (السلوك القديم محفوظ)')
            : bad('الحارس شغّال حتى والميزة مطفية');
        App::setSetting('rep_custody_epoch', EPOCH); RepCustody::refreshEpoch();
    } else {
        note('مفيش إذن/حركة صالحة للزرع — فحص الحارس السلوكي اتخطّى');
    }

    // ─────────────────────────────────────────────── ⑨ قاعدة الـ100
    sec('⑨ عهدة بوليصة دفعها مندوب — نفس قاعدة خصم الخزنة');

    $rbc = code_only(src('app/Models/RepBillCustody.php'));
    !str_contains($rbc, 'SUM(custody)')
        ? ok('مفيش `SUM(custody)` خام — كله بيمرّ على قاعدة الخصم')
        : bad('⚠️ لسه فيه SUM(custody) — بيخصم من المندوب 100 زيادة عن كل بوليصة');
    /* ⚠️ **الفحص على الاستدعاء مش على نصّه الحرفي.** كان مكتوب
       `deductionSql('custody', 'car_no')` بالحرف، فأول ما v74 ضاف عمود التاريخ
       (`'bill_date'`) الفحص فشل بإنذار كاذب رغم إن الاستدعاء بقى **أصحّ**.
       اللي يهمّ هنا: بيمرّ على المصدر الوحيد ولا بيحسب بإيده. */
    preg_match('~Custody::deductionSql\s*\(~', $rbc)
        ? ok('بينادي `Custody::deductionSql` — نفس مصدر خصم الخزنة') : bad('مش بينادي المصدر الوحيد');

    $bill = Database::row(
        'SELECT id, custody, car_no FROM bawalis WHERE is_legacy=0 AND custody>0 ORDER BY id DESC LIMIT 1');
    if ($bill && $rid) {
        $expect = Custody::billDeduction((float) $bill['custody'], $bill['car_no'] ?? null);
        $b0 = RepBillCustody::totalFor($rid);
        Database::exec('UPDATE bawalis SET custody_rep_id=?, bill_date=? WHERE id=?',
            [$rid, EPOCH, (int) $bill['id']]);
        $delta = round(RepBillCustody::totalFor($rid) - $b0, 2);
        near('المخصوم من المندوب = العهدة − 100 (نفس خصم الخزنة)', $delta, $expect);
        $gross = round((float) $bill['custody'], 2);
        abs($delta - $gross) > 0.005 || $expect == $gross
            ? ok('مش بيخصم العهدة كاملة (إلا للسيارات المستثناة)')
            : bad('⚠️ بيخصم العهدة كاملة — باج v60 لسه موجود');
        // كشف الصرف لازم يقول نفس الرقم وإلا الكشف يخالف الرصيد
        $row = null;
        foreach (RepCustody::spending($rid, EPOCH) as $r2) {
            if (($r2['src'] ?? '') === 'bill' && (int) $r2['id'] === (int) $bill['id']) { $row = $r2; break; }
        }
        $row ? near('كشف الصرف بيعرض نفس المبلغ', (float) $row['amount'], $expect)
             : note('البوليصة مش ظاهرة في كشف الصرف — اتخطّى');

        // ⚠️⚠️ **أخطر فحص في الميزة: الاتجاهان لازم يتحركوا مع بعض.**
        //    استثناء من الخزنة بلا إضافة لمحفظة المندوب = فلوس بتتبخّر من النظام.
        Database::exec('UPDATE bawalis SET custody_rep_id=NULL WHERE id=?', [(int) $bill['id']]);
        $safeOffice = Closing::currentBalance();
        $repOffice  = RepBillCustody::totalFor($rid);
        Database::exec('UPDATE bawalis SET custody_rep_id=? WHERE id=?', [$rid, (int) $bill['id']]);
        $safeRep = Closing::currentBalance();
        $repRep  = RepBillCustody::totalFor($rid);
        /* ⚠️ **التوقّع بيختلف حسب نموذج الخزنة الشغّال — والاتنين صح:**
             • **الخزنة = الدرج بس**: الوسم بيشيل العهدة من الدرج فالرقم **بيزيد**.
             • **الخزنة = كل كاش الشركة** (`bankTopupActive`): محفظة المندوب جوّه
               الرقم، والكاش راح للسائق في الحالتين — فالرقم **ما بيتغيّرش**،
               واللي بيتغيّر هو مين دفع.
           الثابت في النموذجين إن **محفظة المندوب بتزيد بالمبلغ** — ده اللي
           بيمنع الفلوس تتبخّر، وهو المحروس أصلاً. */
        near(RepCustody::bankTopupActive()
                ? 'لما المندوب يدفع: إجمالي الخزنة ما بيتغيّرش (الكاش خرج في الحالتين)'
                : 'لما المندوب يدفع: الخزنة بتزيد بنفس المبلغ',
             round($safeRep - $safeOffice, 2), RepCustody::bankTopupActive() ? 0.0 : $expect);
        near('ولمحفظة المندوب بتزيد بنفس المبلغ',          round($repRep - $repOffice, 2), $expect);
        // الرجوع للخزنة لازم يعكس التمام — التعديل بينقل الفلوس مش بيكرّرها
        Database::exec('UPDATE bawalis SET custody_rep_id=NULL WHERE id=?', [(int) $bill['id']]);
        near('الرجوع للخزنة بيعكس التمام (الخزنة)', Closing::currentBalance(), $safeOffice);
        near('الرجوع للخزنة بيعكس التمام (المندوب)', RepBillCustody::totalFor($rid), $repOffice);

        // الحارس: الفورم مش مصدر ثقة
        $pc = code_only(src('app/Controllers/PermitController.php'));
        str_contains($pc, 'private function custodyPayerId(float $custody')
            ? ok('حارس الدافع متعرَّف في الكنترولر') : bad('الحارس مش متعرَّف');
        substr_count($pc, '$this->custodyPayerId(') >= 2
            ? ok('بيتنادى في مساري الكتابة (إنشاء + تعديل)')
            : bad('⚠️ مسار كتابة واحد بس — التاني هيتجاهل الاختيار بالسكوت');
        str_contains($pc, "status = 'active'")
            ? ok('بيتحقق إن المندوب نشط قبل ما يخزّن (الفورم مش مصدر ثقة)') : bad('مفيش تحقّق');
        str_contains(code_only(src('app/Core/helpers.php')), 'function rep_custody_select')
            ? ok('مكوّن الاختيار موجود في helpers') : bad('المكوّن ناقص');
        str_contains(code_only(src('app/Views/permits/_movement.php')), 'rep_custody_select(')
            ? ok('فورم الإذن بينادي المكوّن') : bad('فورم الإذن مش بينادّيه');

        // شاشة /bawalis العامة — نفس الحقل بنفس الحارس
        // ⚠️ الشرطة المائلة إجبارية: `App` مستورَدة كـ`App\Core\App` فوق، فـ`App\Schema`
//    بتتحلّ لـ`App\Core\App\Schema` (وقعت مرتين في الملف ده).
        $ent = \App\Schema::get('bawalis');
        $fld = null;
        foreach (($ent['fields'] ?? []) as $x) { if (($x['key'] ?? '') === 'custody_rep_id') { $fld = $x; } }
        $fld && ($fld['type'] ?? '') === 'rep_payer'
            ? ok('/bawalis: الحقل في Schema بنوع rep_payer') : bad('/bawalis: الحقل مش في Schema');
        str_contains(code_only(src('app/Views/crud/form.php')), "rep_payer")
            ? ok('/bawalis: الفورم بيرسمه') : bad('/bawalis: الفورم مش بيرسمه');
        $cc = code_only(src('app/Controllers/CrudController.php'));
        str_contains($cc, "'rep_payer' =>") && str_contains($cc, "status = 'active'")
            ? ok('/bawalis: collect بتتحقق إن المندوب نشط') : bad('/bawalis: مفيش تحقّق في collect');
        str_contains($cc, "RepBillCustody::ready()")
            ? ok('/bawalis: الحقل بيتخطّى قبل migrate_v60') : bad('/bawalis: هيكسر الحفظ قبل الترحيل');

        // ⚠️ الحارس سلوكيًّا — الفورم مش مصدر ثقة (نفس فحص PermitController)
        $dead = (int) Database::scalar('SELECT COALESCE(MAX(id),0)+500 FROM reps');
        $probe = new class extends \App\Controllers\CrudController {
            public function probe(array $e, $v) {
                $_POST['custody_rep_id'] = $v; $d = $this->collect($e);
                return array_key_exists('custody_rep_id', $d) ? $d['custody_rep_id'] : 'MISSING';
            }
        };
        // ⚠️ `custody` و`bill_date` لازم يبقوا في $_POST — الحارس بقى بيرفض
        //    الوسم على عهدة صفر وعلى تاريخ قبل خط الفصل كمان.
        $_POST['custody'] = 1000; $_POST['bill_date'] = EPOCH;
        $cases = [['صفر', 0, null], ['فاضي', '', null], ['id مش موجود', $dead, null],
                  ['نص عشوائي', 'xyz', null], ['سالب', -5, null], ['مندوب نشط', $rid, $rid]];
        $bad4 = [];
        foreach ($cases as [$lbl, $in, $exp]) {
            if ($probe->probe($ent, $in) !== $exp) { $bad4[] = $lbl; }
        }
        $bad4 ? bad('حارس /bawalis فشل في: ' . implode('، ', $bad4))
              : ok(count($cases) . ' حالة إدخال — الحارس بيرجّع للخزنة في كل قيمة مش مندوب نشط');
        // ⚠️ حالتان جديدتان: عهدة صفر · وتاريخ قبل خط الفصل (تسريب «فلوس بتتبخّر»)
        $_POST['custody'] = 0;
        $probe->probe($ent, $rid) === null
            ? ok('/bawalis: عهدة صفر ⇒ مفيش دافع (خزنة)') : bad('/bawalis: وسم على عهدة صفر');
        $_POST['custody'] = 1000; $_POST['bill_date'] = '2026-07-01';
        $probe->probe($ent, $rid) === null
            ? ok('/bawalis: بوليصة قبل خط الفصل ⇒ بترجع للخزنة')
            : bad('⚠️⚠️ /bawalis: وسم بوليصة قبل خط الفصل — فلوس بتتبخّر!');
        unset($_POST['custody'], $_POST['bill_date'], $_POST['custody_rep_id']);
    } else {
        note('مفيش بوليصة بعهدة للاختبار — قسم ⑨ اتخطّى');
    }

    // ───────────────────────────── ⑩ «سلّمت السائق عهدته» على بوليصة موجودة (٣.٣)
    sec('⑩ المرحلة ٣.٣ — المندوب بيوسم بوليصة موجودة من موبايله');

    /* ═══ ليه الشاشة دي موجودة ═══
       v60 بيسمح للمندوب **بإنشاء بوليصة جديدة** بس، وده سبب إن استخدامه صفر:
       الغالب إن المكتب عامل البوليصة قبله. فعهدة السائق كانت تفضل متخصومة من
       **خزنة المكتب** والمندوب هو اللي دفعها — وده بالظبط الفرق اللي المرحلة ١
       نضّفته (623,605) وكان بيتراكم تاني من أول يوم. */

    // ① الحارس المشترك — مصدر واحد لسؤال «ينفع أوسم البوليصة دي؟»
    $rbcSrc = code_only(src('app/Models/RepBillCustody.php'));
    str_contains($rbcSrc, 'function payerBlock') && str_contains($rbcSrc, 'function payerAllowed')
        ? ok('`payerBlock`/`payerAllowed` متعرَّفين في RepBillCustody')
        : bad('الحارس المشترك ناقص');
    str_contains($pc, 'RepBillCustody::payerAllowed')
        ? ok('فورم الإذن بيمرّ على الحارس المشترك') : bad('فورم الإذن ما بيمرّش على الحارس');
    str_contains($cc, 'RepBillCustody::payerAllowed')
        ? ok('/bawalis بيمرّ على الحارس المشترك') : bad('/bawalis ما بيمرّش على الحارس');
    substr_count($pc, '$this->custodyPayerId(') >= 2
      && substr_count($pc, "\$data['bill_date']") >= 2
        ? ok('التاريخ بيوصل الحارس في مساري الكتابة') : bad('التاريخ مش بيوصل الحارس');

    // ② الموديل — الحُرّاس جوّاه مش في الشاشة
    $rpSrc = code_only(src('app/Models/RepPortal.php'));
    foreach ([
        'assertAssigned'                => 'الإذن مفتوح وظاهر للمندوب (v43)',
        'RepBillCustody::payerBlock'    => 'حارس خط الفصل',
        'RepCustody::dayLockReason'     => 'حارس اليوم المتقفل',
        'custody_rep_id IS NULL'        => 'الوسم الذرّي (مندوبان في نفس اللحظة)',
    ] as $needle => $why) {
        // القصّ على جسم الدالة عشان الفحص ما يلقطش نداءً في دالة تانية
        $body = '';
        if (preg_match('~function claimBillCustody\(.*?\n    \}~s', $rpSrc, $mm)) { $body = $mm[0]; }
        str_contains($body, $needle) ? ok("claimBillCustody: $why")
                                     : bad("claimBillCustody: ناقص — $why");
    }
    !str_contains(src('app/Views/m/bill_custody.php'), 'name="custody"')
        ? ok('الشاشة **مالهاش خانة مبلغ** — وسم مش إدخال (الدين ما بيتأثرش)')
        : bad('⚠️ فيه خانة مبلغ — تعديل `custody` بيغيّر دين العميل!');

    // ③ المسار الحرفي قبل الجشع (تحذير 4)
    $idx = src('public/index.php');
    $pC = strpos($idx, "'/m/bill/custody'");
    $pG = strpos($idx, "'/m/bill/{bid}");
    $pC !== false && $pG !== false && $pC < $pG
        ? ok('`/m/bill/custody` حرفي قبل `/m/bill/{bid}`') : bad('ترتيب المسارات غلط');

    // ④ كل كلاس مطبوع له شكل في m.css (مصيدة `.table-mobile-scroll`)
    $mcss = src('public/assets/css/m.css');
    preg_match_all('/class="([^"]*bc-[^"]*)"/', src('app/Views/m/bill_custody.php'), $cm);
    $missing = [];
    foreach ($cm[1] ?? [] as $attr) {
        foreach (preg_split('/\s+/', $attr) as $cls) {
            if (str_starts_with($cls, 'bc-') && !str_contains($mcss, '.' . $cls)) { $missing[] = $cls; }
        }
    }
    $missing ? bad('كلاسات بلا شكل في m.css: ' . implode('، ', array_unique($missing)))
             : ok('كل كلاسات `.bc-*` معرَّفة في m.css');

    // ⑤ سلوكيًّا — fixtures حقيقية والاتجاهان لازم يتحركوا مع بعض
    if ($rid) {
        // إذن مفتوح + بوليصة بعهدة على تاريخ خط الفصل
        Database::exec(
            "INSERT INTO permits (permit_type, permit_no, permit_date, status, company, client)
             VALUES ('sader', 'V16-3.3', ?, 'open', 'شركة فحص', 'عميل فحص')", [EPOCH]);
        $pidT = Database::lastId();
        $mk = static function (int $pidT, string $date, float $custody): int {
            Database::exec(
                "INSERT INTO bawalis (permit_id, bill_type, bill_date, bill_number, car_no,
                        driver, car_owner, container_count, container_type, freight, custody,
                        transfer, discount, overnight_collection, debt, is_legacy)
                 VALUES (?, 'sader', ?, ?, 'ف ح ص 9001', 'سائق فحص', 'صاحب فحص', 1, '40 قدم',
                        0, ?, 0, 0, 0, 0, 0)",
                [$pidT, $date, 'V16-' . $date . '-' . random_int(1000, 9999), $custody]);
            return Database::lastId();
        };
        $bNew = $mk($pidT, EPOCH, 5000.0);
        $expect2 = Custody::billDeduction(5000.0, 'ف ح ص 9001');

        $list = RepPortal::claimableBills($rid, EPOCH);
        $ids  = array_map(static fn($x) => (int) $x['id'], $list);
        in_array($bNew, $ids, true)
            ? ok('البوليصة الجديدة ظهرت في قايمة الوسم') : bad('البوليصة مش في القايمة');

        $safeA = Closing::currentBalance();
        $repA2 = RepBillCustody::totalFor($rid);
        $res2  = RepPortal::claimBillCustody($rid, $bNew);
        // ⚠️ نفس اختلاف النموذجين الموضَّح فوق — الثابت هو محفظة المندوب.
        near(RepCustody::bankTopupActive()
                ? 'الوسم: إجمالي الخزنة ما بيتغيّرش (الكاش خرج في الحالتين)'
                : 'الوسم: الخزنة بتزيد بالمخصوم',
             round(Closing::currentBalance() - $safeA, 2),
             RepCustody::bankTopupActive() ? 0.0 : $expect2);
        near('الوسم: محفظة المندوب بتزيد بنفس المبلغ',
             round(RepBillCustody::totalFor($rid) - $repA2, 2), $expect2);
        near('الرسالة بتقول نفس الرقم', (float) $res2['amount'], $expect2);
        // العهدة نفسها والدين ما اتغيّروش — وسم بحت
        $af = Database::row('SELECT custody, debt FROM bawalis WHERE id = ?', [$bNew]);
        near('مبلغ العهدة في البوليصة ما اتغيّرش', (float) $af['custody'], 5000.0);
        near('دين العميل ما اتغيّرش', (float) $af['debt'], 0.0);

        // مش ظاهرة تاني في القايمة (موسومة خلاص) + الوسم المكرّر بيترفض
        !in_array($bNew, array_map(static fn($x) => (int) $x['id'],
                  RepPortal::claimableBills($rid, EPOCH)), true)
            ? ok('اختفت من القايمة بعد الوسم') : bad('لسه في القايمة — قابلة للوسم مرتين');
        try { RepPortal::claimBillCustody($rid, $bNew); bad('الوسم المكرّر عدّى!'); }
        catch (\Throwable $e) { ok('الوسم المكرّر بيترفض: ' . mb_substr($e->getMessage(), 0, 40)); }

        // الإلغاء بيعكس التمام — الحالة رجعت بالحرف
        RepPortal::unclaimBillCustody($rid, $bNew);
        near('الإلغاء بيعكس التمام (الخزنة)', Closing::currentBalance(), $safeA);
        near('الإلغاء بيعكس التمام (المندوب)', RepBillCustody::totalFor($rid), $repA2);

        // ⚠️⚠️ التسريب المحروس: بوليصة قبل خط الفصل — الوسم لازم يترفض **بسببه**
        $bOld = $mk($pidT, '2026-07-01', 5000.0);
        try {
            RepPortal::claimBillCustody($rid, $bOld);
            bad('⚠️⚠️ وسم بوليصة قبل خط الفصل عدّى — فلوس بتتبخّر!');
        } catch (\Throwable $e) {
            // ⚠️ مصيدة v49: «اترفض» بلا فحص السبب بينجح كذبًا (اليوم متقفل برضه)
            str_contains($e->getMessage(), 'بداية النظام الجديد')
                ? ok('بوليصة قبل خط الفصل بترفض **بسبب خط الفصل** بالنص')
                : bad('اترفضت بسبب تاني: ' . mb_substr($e->getMessage(), 0, 60));
        }
        // ومش ظاهرة في القايمة أصلاً
        !in_array($bOld, array_map(static fn($x) => (int) $x['id'],
                  RepPortal::claimableBills($rid, EPOCH)), true)
            ? ok('بوليصة قبل خط الفصل مش في القايمة خالص') : bad('ظاهرة في القايمة');

        // الإذن المقفول — كتابته مرفوضة (قاعدة v43)
        Database::exec("UPDATE permits SET status = 'closed' WHERE id = ?", [$pidT]);
        try { RepPortal::claimBillCustody($rid, $bNew); bad('وسم على إذن مقفول عدّى!'); }
        catch (\Throwable $e) { ok('الإذن المقفول بيترفض: ' . mb_substr($e->getMessage(), 0, 40)); }
        count(RepPortal::claimableBills($rid, EPOCH)) === 0
          || !in_array($bNew, array_map(static fn($x) => (int) $x['id'],
                       RepPortal::claimableBills($rid, EPOCH)), true)
            ? ok('بوالص الإذن المقفول مش في القايمة') : bad('بوليصة إذن مقفول في القايمة');
        Database::exec("UPDATE permits SET status = 'open' WHERE id = ?", [$pidT]);

        // ⑥ الشاشة بترندر فعليًّا وفيها البوليصة (بيمسك الفشل الصامت في render)
        $sess = $_SESSION ?? [];
        $_SESSION = ['rep_id' => $rid, 'rep_name' => 'فحص ٣.٣', 'rep_user' => 'v16',
                     'rep_seen' => time(), 'rep_check' => time(),
                     'rep_work_date' => EPOCH, 'rep_work_date_set' => date('Y-m-d')];
        ob_start();
        try { (new \App\Controllers\RepPortalController())->billCustodyForm(); $html = (string) ob_get_clean(); }
        catch (\Throwable $e) { ob_end_clean(); $html = ''; bad('الشاشة رمت استثناء: ' . $e->getMessage()); }
        $_SESSION = $sess;
        if ($html !== '') {
            preg_match('/Fatal error|Warning:|Undefined variable|Undefined array key/', $html, $mE)
                ? bad('الشاشة فيها خطأ PHP: ' . ($mE[0] ?? '')) : ok('الشاشة بترندر بلا أخطاء PHP');
            substr_count($html, 'name="bill_id"') > 0
                ? ok('الشاشة بتعرض بوالص فعلاً (مش قايمة فاضية)') : bad('الشاشة فاضية');
            !str_contains($html, '2026-07-01')
                ? ok('بوليصة ما قبل خط الفصل مش معروضة') : bad('بوليصة قديمة معروضة');
            str_contains($html, 'name="_form_token"')
                ? ok('رمز المرة الواحدة موجود (ضغطة مكرّرة مرفوضة)') : bad('رمز claimForm ناقص');
            str_contains($html, '_csrf') ? ok('CSRF موجود') : bad('CSRF ناقص');
        }
    } else {
        note('مفيش مندوب نشط — قسم ⑩ اتخطّى');
    }

    // ─────────────────────────────────────────────── ⑦ العزل المالي
    sec('⑦ العزل المالي — الخزنة والبنود والإقفالات');

    near('رصيد الخزنة قبل = بعد التفعيل مباشرةً (بلا fixtures)', $safeOnEnable, $before['safe']);
    $closingsFingerprint() === $clsBef
        ? ok('كل صفوف daily_closings متطابقة حرفيًّا قبل = بعد')
        : bad('⚠️ صف إقفال اتغيّر — الرصيد المرحّل بيتعاد حسابه!');
} finally {
    $pdo->rollBack();
    App::setSetting('rep_custody_epoch', $savedEpoch);
    RepCustody::refreshEpoch();
}

// ─────────────────────────────────────────────────────────── ⑧ الرجوع
sec('⑧ الرجوع — الحالة رجعت زي ما كانت');

$after = $snapshot();
$diff = [];
foreach ($before as $k => $v) { if (abs($v - ($after[$k] ?? 0)) > 0.005) { $diff[] = $k; } }
$diff ? bad('اتغيّر: ' . implode('، ', $diff)) : ok('كل الأرصدة رجعت زي ما كانت بعد الـrollback');
$subsFingerprint() === $subsBef ? ok('rep_submissions: العدد والمجموع والحالات ما اتغيّروش')
                                : bad('⚠️ صفوف البنود اتغيّرت — قرار المالك «ما تتلمسش»');
(string) App::setting('rep_custody_epoch', '') === $savedEpoch
    ? ok('الإعداد رجع لقيمته الأصلية') : bad('الإعداد ما رجعش');

// ─────────────────────────────────────────────────────────── النتيجة
echo "\n" . str_repeat('═', 66) . "\n";
foreach ($notes as $n) { echo "… $n\n"; }
echo ($fail === 0 ? "✅ " : "❌ ") . "نجح $pass / فشل $fail\n";
exit($fail === 0 ? 0 : 1);
