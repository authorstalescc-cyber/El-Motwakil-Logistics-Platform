<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص v94 — يوم عهدة المُستقبِل منفصل عن يوم عهدة المُرسِل.
 *
 * ⚠️ **السلوك اللي بيتفحص، مش أسماء الأعمدة:** التحويل اللي خرج يوم ١٢ ووصل
 *    يوم ١٣ لازم يظهر في **عهدة المُرسِل ليوم ١٢** وفي **عهدة المُستقبِل ليوم
 *    ١٣** — ولا واحد منهم في يوم التاني. ده هو الغلط اللي المالك لقاه:
 *    اليوم الواحد كان بيجبره يحطّ رقمًا غلط في طرف عشان يظبط التاني.
 *
 * ⚠️ **والرصيد التراكمي وخزنة المكتب ما بيتغيّروش** بأي حال — التاريخ بيوزّع
 *    الحركة على الأيام، ما بيخلقش ولا بيمسح فلوس.
 *
 * كله جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_v94.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;
use App\Models\RepCustody;
use App\Models\RepSubmissionValidationException;
use App\Models\RepTransfer;

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

echo "فحص v94 — يوم عهدة المُستقبِل منفصل\n" . str_repeat('=', 60) . "\n";

sec('0) البنية');
if (!RepTransfer::available()) { bad('جدول التحويلات مش موجود — شغّل migrate_v46'); exit(1); }
RepTransfer::toDateReady() ? ok('عمود to_date موجود') : bad('عمود to_date ناقص — شغّل migrate_v94');
same('dayCol للمُرسِل = transfer_date', RepTransfer::dayCol('from_rep_id'), 'transfer_date');
same('⚠️ dayCol للمُستقبِل = to_date', RepTransfer::dayCol('to_rep_id'), 'to_date');

$pdo = Database::conn();
$pdo->beginTransaction();
try {
    /* يومان مفتوحان ومتتاليان. لو أقرب يومين مقفولين بنتخطّى بدل ما نفشل —
       القفل قرار عمل مش ارتداد كود. */
    $d2 = date('Y-m-d');
    $d1 = date('Y-m-d', strtotime('-1 day'));
    if (RepCustody::isDayLocked($d1) || RepCustody::isDayLocked($d2)) {
        skp('اليومان الأخيران متقفلان — الفحص محتاج يومين مفتوحين');
        throw new RuntimeException('__skip__');
    }

    Database::exec("INSERT INTO reps (name, status) VALUES ('__v94_A','active')"); $a = Database::lastId();
    Database::exec("INSERT INTO reps (name, status) VALUES ('__v94_B','active')"); $b = Database::lastId();
    RepCustody::createDeposit($a, 5000, $d1, '');

    $safe0 = Closing::currentBalance();
    $out0  = RepCustody::totalOutstanding();

    sec('1) ⚠️ الحركة بتقع في يوم كل طرف — مش في يوم واحد للطرفين');
    /* الحالة اللي المالك وصفها بالحرف: خرجت من عهدة A يوم أمس، ودخلت عهدة B
       النهاردة. */
    $t = RepTransfer::create($a, ['to_rep_id' => $b, 'amount' => 1200,
                                  'transfer_date' => $d1, 'to_date' => $d2, 'note' => 'عبر يومين']);

    near("المُرسِل: خرجت من عهدة يوم $d1", RepCustody::breakdown($a, $d1)['transfer_out'], 1200);
    near("ومفيش حاجة خرجت من عهدة يوم $d2", RepCustody::breakdown($a, $d2)['transfer_out'], 0);
    near("⚠️ المُستقبِل: دخلت عهدة يوم $d2", RepCustody::breakdown($b, $d2)['transfer_in'], 1200);
    near("⚠️ ومفيش حاجة دخلت عهدة يوم $d1", RepCustody::breakdown($b, $d1)['transfer_in'], 0);

    sec('2) عهدة اليوم عند الطرفين');
    near("عهدة المُرسِل ليوم $d1 = 5000 − 1200", RepCustody::balance($a, $d1), 3800);
    near("وعهدة المُستقبِل ليوم $d2 = 1200", RepCustody::balance($b, $d2), 1200);
    near("وعهدة المُستقبِل ليوم $d1 = صفر", RepCustody::balance($b, $d1), 0);

    sec('3) كشف «عهدة كل يوم» بيقول نفس الكلام');
    $ledA = []; foreach (RepCustody::dayLedger($a) as $r) { $ledA[$r['day']] = $r; }
    $ledB = []; foreach (RepCustody::dayLedger($b) as $r) { $ledB[$r['day']] = $r; }
    near("كشف المُرسِل: transfer_out في $d1", $ledA[$d1]['transfer_out'] ?? 0, 1200);
    near("كشف المُستقبِل: transfer_in في $d2", $ledB[$d2]['transfer_in'] ?? 0, 1200);
    same("ومفيش صف ليوم $d1 في كشف المُستقبِل", isset($ledB[$d1]), false);

    sec('4) لوحة اليوم (dashboard) بتوزّع صح كمان');
    $dash = [];
    foreach (RepCustody::dashboard($d2)['reps'] as $c) { $dash[(int) $c['id']] = $c; }
    near("لوحة يوم $d2: المُستقبِل استلم 1200", $dash[$b]['day_transfer_in'] ?? $dash[$b]['transfer_in'] ?? 0, 1200);

    sec('5) ⚠️ العزل: التراكمي والخزنة ما بيتأثروش بتوزيع الأيام');
    near('الرصيد التراكمي للمُرسِل', RepCustody::balance($a), 3800);
    near('والرصيد التراكمي للمُستقبِل', RepCustody::balance($b), 1200);
    near('⚠️ الخزنة ما اتغيّرتش', Closing::currentBalance(), $safe0);
    near('⚠️ وإجمالي الفلوس برّه ثابت (التحويل داخلي)', RepCustody::totalOutstanding(), $out0);

    sec('6) العرض: كل كشف بيقول يوم محفظته هو');
    $outRows = RepTransfer::outgoingFor($a);
    $inRows  = RepTransfer::incomingFor($b);
    same('كشف المُرسِل بيقول يوم الخروج', (string) ($outRows[0]['wallet_date'] ?? ''), $d1);
    same('⚠️ كشف المُستقبِل بيقول يوم الدخول', (string) ($inRows[0]['wallet_date'] ?? ''), $d2);
    same('والصف بيحمل اليومين للعرض المقارن', (string) ($inRows[0]['transfer_date'] ?? ''), $d1);

    sec('7) الحُرّاس');
    try {
        RepTransfer::update($t, ['to_date' => $d1, 'transfer_date' => $d2]);
        bad('✗ اتقبل وصول قبل الخروج');
    } catch (RepSubmissionValidationException $e) { ok('⚠️ اترفض يوم وصول أقدم من يوم الخروج'); }

    try {
        RepTransfer::create($a, ['to_rep_id' => $b, 'amount' => 10, 'transfer_date' => $d2,
                                 'to_date' => date('Y-m-d', strtotime('+3 days'))]);
        bad('✗ اتقبل يوم وصول في المستقبل');
    } catch (RepSubmissionValidationException $e) { ok('اترفض يوم وصول في المستقبل'); }

    /* ⚠️ **الحقل الغايب = يوم المُرسِل** (بوابة المندوب ما بتبعتوش) — لو بقى
       فاضي أو صفرًا، كل تحويل جاي من الموبايل بيختفي من عهدة أيام المُستقبِل. */
    $t2 = RepTransfer::create($a, ['to_rep_id' => $b, 'amount' => 50, 'transfer_date' => $d2]);
    same('التحويل بلا to_date بياخد يوم المُرسِل',
         (string) Database::scalar("SELECT to_date FROM rep_custody_transfers WHERE id = ?", [$t2]), $d2);

    /* ⚠️ **التعديل الجزئي**: بعتنا المبلغ بس — فاليومان **والملاحظة** لازم
       يفضلوا زي ما هما. الملاحظة تحديدًا كانت بتتمسح بالسكوت. */
    RepTransfer::update($t, ['amount' => 1300]);
    same('تعديل المبلغ لوحده ما مسحش الملاحظة',
         (string) Database::scalar("SELECT note FROM rep_custody_transfers WHERE id = ?", [$t]), 'عبر يومين');
    $row = Database::row("SELECT * FROM rep_custody_transfers WHERE id = ?", [$t]);
    same('تعديل المبلغ لوحده ما لمسش يوم الخروج', (string) $row['transfer_date'], $d1);
    same('ولا يوم الدخول', (string) $row['to_date'], $d2);
    // 1300 (اللي عدّلناه) + 50 (التحويل التاني اللي اتسجّل على نفس اليوم فوق)
    near("والتوزيع فضل صح: المُستقبِل في $d2", RepCustody::breakdown($b, $d2)['transfer_in'], 1350);

    sec('8) التوجيه بيحرّك اليوم الصح');
    // نرجّع اليومين لنفس اليوم: عهدة المُستقبِل ليوم d2 لازم تفضى
    // ⚠️ بلا `amount` عن قصد — التواريخ لوحدها لازم تعدّي (الغايب = ما اتغيّرش)
    RepTransfer::update($t, ['transfer_date' => $d1, 'to_date' => $d1]);
    near("بعد التوحيد: المُستقبِل في $d1", RepCustody::breakdown($b, $d1)['transfer_in'], 1300);
    near("و $d2 رجعت لـ50 (التحويل التاني بس)", RepCustody::breakdown($b, $d2)['transfer_in'], 50);
    near('والتراكمي ما اتغيّرش', RepCustody::balance($b), 1350);
    near('والخزنة برضه', Closing::currentBalance(), $safe0);

    sec('9) الشاشة والمسار');
    $trf = (string) file_get_contents(dirname(__DIR__) . '/app/Views/partials/rep_transfer_edit.php');
    str_contains($trf, 'name="to_date"')
        ? ok('درج التعديل فيه خانة يوم المُستقبِل') : bad('الخانة ناقصة — المالك مايقدرش يفصل اليومين');
    str_contains($trf, 'name="transfer_date"')
        ? ok('وخانة يوم المُرسِل') : bad('خانة يوم المُرسِل ناقصة');
    $ctl = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/RepCustodyController.php');
    str_contains($ctl, "'to_date'")
        ? ok('والكنترولر بيمرّرها') : bad('الكنترولر مش بيمرّر to_date — الخانة هتتجاهَل بالسكوت');

    /* ⚠️ **ولا شاشة تعرض `transfer_date` خام لمُستقبِل.** العرض لازم يمرّ على
       `wallet_date` وإلا كشف المُستقبِل يقول يوم زميله. */
    $leak = [];
    foreach (['app/Views/reps/_tab_custody.php', 'app/Views/m/team_rep.php',
              'app/Views/rep_custody/_tab_account.php', 'app/Models/RepWatch.php'] as $f) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/' . $f);
        if (!str_contains($src, 'wallet_date')) { $leak[] = $f; }
    }
    $leak ? bad('شاشة بتعرض تاريخ التحويل بلا wallet_date: ' . implode(', ', $leak))
          : ok('⚠️ كل شاشات التحويل بتقرا wallet_date');

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
