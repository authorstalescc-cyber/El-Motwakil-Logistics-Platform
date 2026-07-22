<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص v46 — تحويل عهدة من مندوب لمندوب.
 *
 * أهم فحصين:
 *   ١) **الرصيد صح في كل مكان:** المُرسِل ينقص والمُستقبِل يزيد، ونفس الرقم من
 *      balance / breakdown / cards / dashboard (لو اختلفوا، كارت يقول رقم وكشف
 *      يقول تاني).
 *   ٢) **العزل:** الخزنة ما بتتأثرش، وإجمالي الفلوس اللي برّه مع المناديب ثابت
 *      (التحويل داخلي)، ومفيش ملف خزنة بيعرف بالجدول.
 *
 * كله جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_v46.php
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
function sec(string $m): void  { echo "\n" . $m . "\n" . str_repeat('─', 60) . "\n"; }
function near(string $l, $a, $e): void {
    abs((float) $a - (float) $e) <= 0.005 ? ok($l)
        : bad("$l — متوقع " . number_format((float) $e, 2) . " / فعلي " . number_format((float) $a, 2));
}
function same(string $l, $a, $e): void { $a == $e ? ok($l) : bad("$l — متوقع " . var_export($e, true) . " / فعلي " . var_export($a, true)); }

echo "فحص v46 — تحويل عهدة بين المناديب\n" . str_repeat('=', 60) . "\n";

sec('0) البنية + العزل');
RepTransfer::available() ? ok('جدول rep_custody_transfers موجود') : bad('الجدول ناقص — شغّل migrate_v46');
$treasuryFiles = ['app/Models/Closing.php','app/Models/DailyReconciliation.php','app/Models/Metrics.php',
    'app/Models/Expense.php','app/Models/Custody.php','app/Controllers/DailyClosingController.php','bin/backfill_closings.php'];
$leak = [];
foreach ($treasuryFiles as $f) {
    $p = dirname(__DIR__) . '/' . $f;
    if (is_file($p) && str_contains((string) file_get_contents($p), 'rep_custody_transfers')) { $leak[] = $f; }
}
$leak ? bad('✗✗ الجدول اتذكر في ملف خزنة: ' . implode(', ', $leak)) : ok('ولا ملف خزنة بيعرف الجدول (' . count($treasuryFiles) . ' اتفحصوا)');

$pdo = Database::conn();
$pdo->beginTransaction();
try {
    $t0 = Closing::currentBalance();
    $out0 = RepCustody::totalOutstanding();

    Database::exec("INSERT INTO reps (name, status) VALUES ('__v46_A','active')"); $a = Database::lastId();
    Database::exec("INSERT INTO reps (name, status) VALUES ('__v46_B','active')"); $b = Database::lastId();
    RepCustody::createDeposit($a, 5000, date('Y-m-d'), '');
    RepCustody::createDeposit($b, 1000, date('Y-m-d'), '');

    sec('1) التحويل بيحرّك الرصيد صح');
    RepTransfer::create($a, ['to_rep_id' => $b, 'amount' => 1500, 'transfer_date' => date('Y-m-d'), 'note' => 't']);
    near('عهدة المُرسِل نقصت 1500 (5000→3500)', RepCustody::balance($a), 3500);
    near('عهدة المُستقبِل زادت 1500 (1000→2500)', RepCustody::balance($b), 2500);

    sec('2) نفس الرقم من كل المصادر (تطابق)');
    near('breakdown المُرسِل', RepCustody::breakdown($a)['balance'], 3500);
    near('breakdown المُستقبِل', RepCustody::breakdown($b)['balance'], 2500);
    $cards = [];
    foreach (RepCustody::cards() as $c) { $cards[(int) $c['id']] = $c['balance']; }
    near('cards المُرسِل', $cards[$a] ?? -1, 3500);
    near('cards المُستقبِل', $cards[$b] ?? -1, 2500);
    $dash = [];
    foreach (RepCustody::dashboard(date('Y-m-d'))['reps'] as $c) { $dash[(int) $c['id']] = $c['balance']; }
    near('dashboard المُرسِل', $dash[$a] ?? -1, 3500);
    near('dashboard المُستقبِل', $dash[$b] ?? -1, 2500);

    sec('3) ⚠️ العزل: الخزنة والإجمالي');
    near('الخزنة ما اتغيّرتش', Closing::currentBalance(), $t0);
    near('إجمالي الفلوس برّه ثابت (التحويل داخلي)', RepCustody::totalOutstanding(), $out0 + 6000);
    // (+6000 من التنزيلين، والتحويل نفسه net=0)

    sec('4) الحُرّاس');
    try { RepTransfer::create($a, ['to_rep_id' => $b, 'amount' => 99999, 'transfer_date' => date('Y-m-d')]);
          bad('✗ اتقبل تحويل أكبر من الرصيد'); }
    catch (RepSubmissionValidationException $e) { ok('اترفض تحويل أكبر من الرصيد'); }
    try { RepTransfer::create($a, ['to_rep_id' => $a, 'amount' => 10, 'transfer_date' => date('Y-m-d')]);
          bad('✗ اتقبل تحويل لنفسه'); }
    catch (RepSubmissionValidationException $e) { ok('اترفض تحويل لنفسه'); }
    try { RepTransfer::create($a, ['to_rep_id' => $b, 'amount' => 0, 'transfer_date' => date('Y-m-d')]);
          bad('✗ اتقبل مبلغ صفر'); }
    catch (RepSubmissionValidationException $e) { ok('اترفض مبلغ صفر'); }

    sec('5) الحذف بيرجّع الفلوس + بيترفض لو المستقبِل صرف');
    $tid = RepTransfer::create($a, ['to_rep_id' => $b, 'amount' => 500, 'transfer_date' => date('Y-m-d')]);
    near('بعد تحويل 500 تاني: A=3000', RepCustody::balance($a), 3000);
    near('B=3000', RepCustody::balance($b), 3000);
    RepTransfer::delete($tid);
    near('بعد الحذف: A رجعت 3500', RepCustody::balance($a), 3500);
    near('B رجعت 2500', RepCustody::balance($b), 2500);
    // المستقبِل يصرف كل رصيده ثم نحاول نلغي التحويل الأصلي → لازم يترفض
    Database::exec("INSERT INTO rep_custody_settlements (rep_id, settle_date, deposits_total, spent_total, returned, to_custody, note, settled_by)
                    VALUES (?,?,?,?,?,0,?,?)", [$b, date('Y-m-d'), 2500, 0, 2500, 'صرف', 'x']);
    near('المستقبِل صفّر رصيده', RepCustody::balance($b), 0);
    $firstTransfer = (int) Database::scalar("SELECT id FROM rep_custody_transfers WHERE from_rep_id=? ORDER BY id LIMIT 1", [$a]);
    try { RepTransfer::delete($firstTransfer); bad('✗ اتقبل حذف تحويل المستقبِل صرفه'); }
    catch (\Throwable $e) { ok('اترفض حذف تحويل بعد ما المستقبِل صرفه'); }

} catch (\Throwable $e) {
    bad('استثناء غير متوقّع: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo "\n" . str_repeat('═', 60) . "\n";
    echo "اترجّع كل شيء (rollback) — مفيش أي أثر.\n";
    echo "رصيد الخزنة دلوقتي: " . number_format(Closing::currentBalance(), 2) . "\n";
    echo "\nنجح: $pass | فشل: $fail | تخطّي: $skip\n";
    exit($fail > 0 ? 1 : 0);
}
