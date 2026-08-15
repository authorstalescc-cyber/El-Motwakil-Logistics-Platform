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
$transferView = (string) file_get_contents(dirname(__DIR__) . '/app/Views/m/transfer.php');
$formOpen = substr_count($transferView, '<form');
$formClose = substr_count($transferView, '</form>');
$formOpen === 1 && $formClose === 1
    ? ok('شاشة التحويل فيها فورم واحد صالح — زر الحفظ يظل مرتبطًا به حتى مع وجود تحويلات سابقة')
    : bad("شاشة التحويل فيها فورمات متداخلة/غير متوازنة ($formOpen فتح / $formClose غلق) — زر الحفظ سيتعطّل");
str_contains($transferView, 'formaction="<?= url(\'m/transfer/\'')
    ? ok('زر إلغاء التحويل يغيّر وجهة الإرسال بلا فورم متداخل')
    : bad('زر إلغاء التحويل غير مربوط بمسار الحذف عبر formaction');
str_contains($transferView, 'id="save" form="f"')
    ? ok('زر «حوّل العهدة» مربوط صراحةً بفورم التحويل')
    : bad('زر «حوّل العهدة» يعتمد على الاحتواء فقط — اربطه صراحةً بـform="f"');
$treasuryFiles = ['app/Models/Closing.php','app/Models/DailyReview.php','app/Models/Metrics.php',
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

    /* ⚠️ الأساس بيتاخد **بعد التنزيلات** — القسم ده بيفحص إن **التحويل** ما
       مسّش الخزنة، والتنزيل بقى بيزوّدها (قرار المالك 2026-07-30: «أي عهدة
       تتضاف للمندوب كأنها في الدرج»). أخذ الأساس قبلهم بيخلط الاتنين. */
    $t0 = Closing::currentBalance();

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

    // ═════════════ 6) تعديل التحويل من المكتب (2026-07-31) ═════════════
    sec('6) تعديل التحويل — بينقل الفلوس مش بيكرّرها');

    /* ⚠️ **الفكرة اللي القسم ده بيحرسها:** الصف الواحد بيوصف الاتجاهين
       (−من المُرسِل · +للمُستقبِل)، فـ`UPDATE` واحدة بتحرّك الطرفين مع بعض.
       لو حد حوّلها لـ«حذف ثم إنشاء»، بينهم لحظة رصيد المُستقبِل فيها ناقص،
       وسجل التدقيق بيبان عمليتين. الفحص بيقيس **صف واحد** بعد التعديل. */

    $c = (int) Database::scalar("SELECT id FROM reps WHERE status='active' AND id NOT IN (?,?) ORDER BY id LIMIT 1", [$a, $b]);
    $day6 = date('Y-m-d');
    $a6 = RepCustody::balance($a); $b6 = RepCustody::balance($b);
    $c6 = $c > 0 ? RepCustody::balance($c) : 0.0;
    $safe6 = Closing::currentBalance();
    $rows6 = (int) Database::scalar("SELECT COUNT(*) FROM rep_custody_transfers");

    $t6 = RepTransfer::create($a, ['to_rep_id' => $b, 'amount' => 300, 'transfer_date' => $day6]);
    RepTransfer::update($t6, ['to_rep_id' => $b, 'amount' => 700, 'transfer_date' => $day6, 'note' => 'معدَّل']);
    near('⚠️ المُرسِل اتخصم منه الجديد بس (مش القديم + الجديد)', RepCustody::balance($a), $a6 - 700);
    near('والمُستقبِل عنده الجديد', RepCustody::balance($b), $b6 + 700);
    near('⚠️⚠️ صف واحد مش اتنين — التعديل نقل مش تكرار',
         (int) Database::scalar("SELECT COUNT(*) FROM rep_custody_transfers"), $rows6 + 1);
    near('⚠️ الخزنة ما اتغيّرتش (تحويل بين محفظتين)', Closing::currentBalance(), $safe6);

    if ($c > 0) {
        RepTransfer::update($t6, ['to_rep_id' => $c, 'amount' => 700, 'transfer_date' => $day6, 'note' => '']);
        near('تغيير المستقبِل: القديم رجع لأصله', RepCustody::balance($b), $b6);
        near('والجديد أخد المبلغ', RepCustody::balance($c), $c6 + 700);
        near('والخزنة لسه ثابتة', Closing::currentBalance(), $safe6);
    }

    /* ⚠️ **الحقل الغايب = ما اتغيّرش.** تعديل الملاحظة لوحدها ما ينفعش يصفّر
       المندوبين ولا يحرّك قرشًا — `(int)(null)` كان هيدّي صفرًا ويرفض العملية
       برسالة «اختر المندوب» وسط تعديل بريء. */
    $curAll   = Database::row("SELECT * FROM rep_custody_transfers WHERE id = ?", [$t6]);
    $noteFrom = RepCustody::balance((int) $curAll['from_rep_id']);
    $noteTo   = RepCustody::balance((int) $curAll['to_rep_id']);
    RepTransfer::update($t6, ['amount' => $curAll['amount'], 'transfer_date' => $curAll['transfer_date'],
                              'note' => 'ملاحظة لوحدها']);
    $afterNote = Database::row("SELECT * FROM rep_custody_transfers WHERE id = ?", [$t6]);
    same('تعديل الملاحظة لوحدها ما غيّرش المُرسِل',
         (int) $afterNote['from_rep_id'], (int) $curAll['from_rep_id']);
    same('ولا المُستقبِل', (int) $afterNote['to_rep_id'], (int) $curAll['to_rep_id']);
    near('ولا حرّك رصيد المُرسِل', RepCustody::balance((int) $curAll['from_rep_id']), $noteFrom);
    near('ولا رصيد المُستقبِل', RepCustody::balance((int) $curAll['to_rep_id']), $noteTo);

    // الحُرّاس التلاتة
    try { RepTransfer::update($t6, ['to_rep_id' => $c > 0 ? $c : $b, 'amount' => 99999999,
            'transfer_date' => $day6, 'note' => '']); bad('✗ اتقبل مبلغ أكبر من رصيد المُرسِل'); }
    catch (\Throwable $e) { ok('اترفض مبلغ أكبر من رصيد المُرسِل'); }
    try { RepTransfer::update($t6, ['to_rep_id' => $a, 'amount' => 700,
            'transfer_date' => $day6, 'note' => '']); bad('✗ اتقبل تحويل لنفسه'); }
    catch (\Throwable $e) { ok('اترفض تحويل لنفسه'); }

    /* ⚠️ **حارس «المستقبِل صرفها»** — نفس منطق `delete()`. لو اتشال، تقليل
       المبلغ بيخلّي رصيد المُستقبِل سالب بلا سبب مفهوم. */
    $recv6 = $c > 0 ? $c : $b;
    Database::exec("INSERT INTO rep_custody_settlements (rep_id, settle_date, deposits_total, spent_total, returned, to_custody, note, settled_by)
                    VALUES (?,?,?,?,?,0,?,?)", [$recv6, $day6, 0, 0, RepCustody::balance($recv6), 'صرف فحص', 'x']);
    try { RepTransfer::update($t6, ['to_rep_id' => $recv6, 'amount' => 10,
            'transfer_date' => $day6, 'note' => '']); bad('✗ اتقبل تقليل بعد ما المستقبِل صرف'); }
    catch (\Throwable $e) { ok('اترفض التقليل بعد ما المستقبِل صرف'); }

    /* ═══ 7) إعادة توجيه المُرسِل نفسه (تحكم كامل) ═══
       ⚠️ **ليه مناديب جداد:** الأرصدة هنا لازم تكون **معلومة بالظبط** عشان
          نقيس الحارس على حافته. الاعتماد على مندوب حقيقي من القاعدة بيدّي
          فحصًا بيعدّي أو يفشل حسب رصيد اليوم — إنذار كاذب متكرر. */
    sec('7) ⚠️ إعادة توجيه المُرسِل — الفلوس بتتنقل من محفظة لمحفظة');
    Database::exec("INSERT INTO reps (name, status) VALUES ('__v46_D','active')"); $dRep = Database::lastId();
    Database::exec("INSERT INTO reps (name, status) VALUES ('__v46_E','active')"); $eRep = Database::lastId();
    RepCustody::createDeposit($dRep, 4000, $day6, '');
    RepCustody::createDeposit($eRep, 200, $day6, '');
    $safe7 = Closing::currentBalance();
    $rows7 = (int) Database::scalar("SELECT COUNT(*) FROM rep_custody_transfers");

    $t7 = RepTransfer::create($dRep, ['to_rep_id' => $eRep, 'amount' => 1000, 'transfer_date' => $day6]);
    near('قبل التوجيه: D=3000', RepCustody::balance($dRep), 3000);
    near('و E=1200', RepCustody::balance($eRep), 1200);

    /* ⚠️ **الحالة اللي حارسان منفصلان بيفوّتوها:** المُرسِل الجديد هو المُستقبِل
       القديم. رصيد E الظاهر 1200، لكن 1000 منهم هُمّ التحويل نفسه اللي بيترجع.
       فاللي معاه فعلاً 200 — وحارس بيبصّ للمُرسِل القديم بس كان هيسمح بـ800. */
    try {
        RepTransfer::update($t7, ['from_rep_id' => $eRep, 'to_rep_id' => $dRep,
                                  'amount' => 800, 'transfer_date' => $day6, 'note' => '']);
        bad('✗✗ اتقبل توجيه من محفظة رصيدها الحقيقي أقل — الحارس الموحَّد مشيل');
    } catch (RepSubmissionValidationException $e) {
        ok('⚠️ اترفض التوجيه لما المُرسِل الجديد (= المُستقبِل القديم) مش معاه المبلغ فعلاً');
    }
    near('والرفض ما غيّرش رصيد D', RepCustody::balance($dRep), 3000);
    near('ولا رصيد E', RepCustody::balance($eRep), 1200);

    RepTransfer::update($t7, ['from_rep_id' => $eRep, 'to_rep_id' => $dRep,
                              'amount' => 150, 'transfer_date' => $day6, 'note' => 'اتوجّه']);
    near('بعد التوجيه: D استرد الألف وأخد الـ150', RepCustody::balance($dRep), 4150);
    near('و E دفع الـ150 من الـ200 اللي معاه فعلاً', RepCustody::balance($eRep), 50);
    same('from_rep_id اتغيّر فعلاً في الصف',
         (int) Database::scalar("SELECT from_rep_id FROM rep_custody_transfers WHERE id = ?", [$t7]), $eRep);
    near('⚠️⚠️ صف واحد مش اتنين — التوجيه نقل مش حذف وإنشاء',
         (int) Database::scalar("SELECT COUNT(*) FROM rep_custody_transfers"), $rows7 + 1);
    near('⚠️ الخزنة ما اتغيّرتش', Closing::currentBalance(), $safe7);
    near('⚠️ ومجموع الفلوس مع الاتنين ثابت (4200)',
         RepCustody::balance($dRep) + RepCustody::balance($eRep), 4200);

    // المسار والواجهة
    $idx = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
    str_contains($idx, "'/rep-custody/transfer/{tid}/update'")
        ? ok('مسار التعديل موجود') : bad('مسار التعديل ناقص');
    // ⚠️ الحرفي لازم يفضل **قبل** الجشع `/{id}` (تحذير ٤)
    strpos($idx, '/rep-custody/transfer/{tid}/update') < strpos($idx, "\$r->post('/rep-custody/{id}")
        ? ok('⚠️ الحرفي قبل الجشع /{id}') : bad('⚠️ الترتيب غلط — الجشع هياكل المسار');
    $ctl = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/RepCustodyController.php');
    str_contains($ctl, 'function updateTransfer') ? ok('الأكشن موجود') : bad('الأكشن ناقص');
    str_contains($ctl, "RepTransfer::outgoingFor(\$repId)")
        ? ok('الكارت بيمرّر التحويلات الصادرة') : bad('الصادرة مش بتتمرّر للشاشة');
    str_contains($ctl, "'from_rep_id'")
        ? ok('الأكشن بيمرّر المُرسِل كمان (إعادة توجيه)') : bad('المُرسِل مش بيتمرّر — التوجيه مقفول من الشاشة');
    $tabSrc = (string) file_get_contents(dirname(__DIR__) . '/app/Views/rep_custody/_tab_account.php');
    str_contains($tabSrc, 'transfers-out') ? ok('لوح الصادر في الشاشة') : bad('لوح الصادر ناقص');
    /* ⚠️ **الدرج بارشيال واحد للوحين** — الوارد كان زر حذف بس. الفحص بيتأكد إن
       اللوحين **الاتنين** بينادوه، فأي رجوع للنسخ اليدوي بيبان فورًا. */
    $trfSrc = dirname(__DIR__) . '/app/Views/partials/rep_transfer_edit.php';
    is_file($trfSrc) ? ok('بارشيال درج التعديل موجود') : bad('بارشيال درج التعديل ناقص');
    $trf = is_file($trfSrc) ? (string) file_get_contents($trfSrc) : '';
    str_contains($trf, "transfer/' . \$tid . '/update")
        ? ok('وفورم التعديل بيبعت على مساره') : bad('فورم التعديل ناقص');
    str_contains($trf, 'name="from_rep_id"') && str_contains($trf, 'name="to_rep_id"')
        ? ok('والدرج فيه الطرفين — تحكم كامل مش المستقبِل بس')
        : bad('الدرج ناقصه أحد الطرفين');
    substr_count($tabSrc, "partial('rep_transfer_edit'") === 2
        ? ok('⚠️ اللوحان (صادر + وارد) بينادوا نفس الدرج')
        : bad('⚠️ لوح من الاتنين بلا درج تعديل — الوارد يرجع حذف بس');

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
