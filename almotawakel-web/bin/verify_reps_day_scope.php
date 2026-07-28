<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص «عهدة المناديب يوم واحد بس» في لوحة التحكم وشاشات المندوب.
 *
 * بلاغ المالك (2026-07-27): «عهدة المناديب كل يوم لوحده والعهدة ما بتترحلش —
 * لكن لسه بيظهر تفاصيل ومصروفات من يوم سابق، وفي الداشبورد بشوف صرفهم
 * الإجمالي. أنا عايز أدخل أشوف أخدوا كام اليوم ده وصرفوا كام اليوم ده بس.»
 *
 * القاعدة المحروسة هنا:
 *   ١) `RepCustody::dashboard($day)` بترجّع **بنود وأرقام اليوم ده بس** —
 *      المندوب اللي حركته امبارح مش بيظهر، وبنود امبارح مش في الـfeed.
 *   ٢) لوحة التحكم بتعرض **فاضل عهدة اليوم** (`day_pocket`) مش التراكمي.
 *   ٣) شاشات المندوب `/m/bill/new` و`/m/transfer` بتعرض حركة اليوم بس.
 *   ٤) ⚠️ **التراكمي ما اتشالش من الحزمة** — هو الحقيقة المالية وأساس التسوية
 *      وحد التحويل (قاعدة v49)، بس **الشاشة ما بتعرضهوش**. لو حد شاله،
 *      `verify_rep_dashboard` هيفشل — وده مقصود.
 *
 * كله جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_reps_day_scope.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;
use App\Models\RepCustody;

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function sec(string $m): void { echo "\n" . $m . "\n" . str_repeat('─', 62) . "\n"; }
function near(string $l, $a, $e): void {
    abs((float) $a - (float) $e) <= 0.005 ? ok($l)
        : bad("{$l} — متوقع " . number_format((float) $e, 2) . " / فعلي " . number_format((float) $a, 2));
}
function has(string $l, string $file, string $needle): void {
    $p = dirname(__DIR__) . '/' . $file;
    $src = is_file($p) ? (string) file_get_contents($p) : '';
    str_contains($src, $needle) ? ok($l) : bad("{$l} — «{$needle}» مش موجودة في {$file}");
}

echo "فحص «عهدة المناديب يوم واحد بس»\n" . str_repeat('=', 62) . "\n";

if (!RepCustody::available()) {
    echo "\n⚠️ عهدة المناديب مش متاحة (migrate_v35 لسه ما اتشغّلش) — تخطّي.\n";
    exit(0);
}

$treasuryBefore = Closing::currentBalance();
$outstandingBefore = RepCustody::totalOutstanding();

$pdo = Database::conn();
$pdo->beginTransaction();

try {
    // ══════════════════════════════════════════════ ١) بيئة الاختبار
    sec('١) مندوبان: واحد حركته النهاردة والتاني حركته امبارح بس');

    // ⚠️ تاريخان بعيدان عن أي إقفال أو بيانات حقيقية، عشان الفحص ما يتأثرش
    //    بحالة القاعدة (الدرس من verify_custody_cap: ما تفترضش نقطة بداية).
    $today = '2037-05-20';
    $yday  = '2037-05-19';

    Database::exec("INSERT INTO reps (name, status) VALUES ('__ds_today', 'active')");
    $repToday = Database::lastId();
    Database::exec("INSERT INTO reps (name, status) VALUES ('__ds_past', 'active')");
    $repPast = Database::lastId();

    // `permit_stages.permit_id` مش بيقبل NULL — لازم إذن حقيقي.
    Database::exec(
        "INSERT INTO permits (permit_no, permit_date, status, company, client)
         VALUES ('__ds_permit', ?, 'open', '__ds', '__ds')", [$today]);
    $permitId = Database::lastId();

    // النهاردة: نزل 3000 وصرف 800 معتمد + 200 مستنّي
    Database::exec("INSERT INTO rep_custody_deposits (rep_id, amount, dep_date) VALUES (?,3000,?)",
        [$repToday, $today]);
    Database::exec(
        "INSERT INTO permit_stages (permit_id, movement_id, stage_key, direction, amount, qty,
                                    entry_date, note, is_visa, paid_by_rep_id)
         VALUES (?, NULL, 'custom', 'expense', 800, 1, ?, '__ds_stage', 0, ?)",
        [$permitId, $today, $repToday]);
    Database::exec(
        "INSERT INTO rep_submissions (rep_id, permit_id, kind, note, qty, amount, entry_date, status, is_visa)
         VALUES (?, NULL, 'misc', '__ds_pending', 1, 200, ?, 'pending', 0)",
        [$repToday, $today]);

    // امبارح: نزل 5000 وصرف 1000 — ولا حاجة النهاردة
    Database::exec("INSERT INTO rep_custody_deposits (rep_id, amount, dep_date) VALUES (?,5000,?)",
        [$repPast, $yday]);
    Database::exec(
        "INSERT INTO permit_stages (permit_id, movement_id, stage_key, direction, amount, qty,
                                    entry_date, note, is_visa, paid_by_rep_id)
         VALUES (?, NULL, 'custom', 'expense', 1000, 1, ?, '__ds_stage_old', 0, ?)",
        [$permitId, $yday, $repPast]);
    Database::exec(
        "INSERT INTO rep_submissions (rep_id, permit_id, kind, note, qty, amount, entry_date, status, is_visa)
         VALUES (?, NULL, 'misc', '__ds_old_item', 1, 300, ?, 'pending', 0)",
        [$repPast, $yday]);

    ok('اتزرع مندوب حركته النهاردة ومندوب حركته امبارح بس');

    // ══════════════════════════════════════════════ ٢) قلب البلاغ
    sec('٢) لوحة التحكم — اليوم ده بس');

    $panel = RepCustody::dashboard($today, 20, 100);
    $ids = array_map(static fn (array $c): int => (int) $c['id'], $panel['reps']);

    in_array($repToday, $ids, true)
        ? ok('مندوب النهاردة ظاهر في اللوحة') : bad('مندوب النهاردة مش ظاهر');
    !in_array($repPast, $ids, true)
        ? ok('★ مندوب امبارح **مش** ظاهر (عهدته ما اترحّلتش)')
        : bad('★ مندوب امبارح لسه ظاهر في لوحة النهاردة — القاعدة مكسورة');

    $card = null;
    foreach ($panel['reps'] as $c) { if ((int) $c['id'] === $repToday) { $card = $c; break; } }
    if ($card === null) { bad('كارت مندوب النهاردة مش موجود — باقي القسم اتخطّى'); }
    else {
        near('نزل النهاردة = 3,000', $card['day_deposits'], 3000);
        near('صرف النهاردة = 800',   $card['day_spent'], 800);
        near('مستنّي النهاردة = 200', $card['day_pending'], 200);
        (int) $card['day_pending_cnt'] === 1
            ? ok('عدد المستنّي النهاردة = 1') : bad('عدد المستنّي النهاردة غلط: ' . $card['day_pending_cnt']);
        // فاضل معاه = 3000 − 800 − 200
        near('فاضل معاه (day_pocket) = 2,000', $card['day_pocket'], 2000);
        near('وهو = dayTotalsByRep نفسها (مفيش تعريف تاني)',
             $card['day_pocket'], RepCustody::dayTotalsByRep($today)[$repToday]['pocket'] ?? -1);
    }

    // الإجماليات: بنود اليوم بس
    near('إجمالي «نزل» في اللوحة = 3,000', $panel['totals']['day_deposits'], 3000);
    near('إجمالي «صرف» في اللوحة = 800',   $panel['totals']['day_spent'], 800);
    near('إجمالي «فاضل معاهم» = 2,000',     $panel['totals']['day_pocket'], 2000);

    // ══════════════════════════════════════════════ ٣) قايمة البنود
    sec('٣) آخر المدخلات — بنود اليوم بس');

    $notes = array_column($panel['feed'], 'note');
    in_array('__ds_pending', $notes, true)
        ? ok('بند النهاردة في القايمة') : bad('بند النهاردة مش في القايمة');
    !in_array('__ds_old_item', $notes, true)
        ? ok('★ بند امبارح **مش** في القايمة')
        : bad('★ بند امبارح لسه بيظهر في لوحة النهاردة');

    $offDay = array_values(array_filter($panel['feed'],
        static fn (array $s): bool => (string) $s['entry_date'] !== $today));
    !$offDay ? ok('ولا بند واحد في القايمة من يوم تاني')
             : bad(count($offDay) . ' بند من يوم تاني في القايمة');

    // ══════════════════════════════════════════════ ٤) التراكمي لسه موجود للحساب
    sec('٤) التراكمي باقٍ في الحزمة (الحقيقة المالية) بس مش معروض');

    $all = RepCustody::dashboard($yday, 20, 100);
    $pastIds = array_map(static fn (array $c): int => (int) $c['id'], $all['reps']);
    in_array($repPast, $pastIds, true)
        ? ok('لو غيّرت يوم العمل لامبارح بتشوف مندوب امبارح — العدسة بتتحرّك')
        : bad('مندوب امبارح مش ظاهر حتى في يومه');

    near('رصيد المندوب التراكمي لسه صح (3000−800=2200)',
         RepCustody::balance($repToday), 2200);
    if ($card !== null) {
        near('ومفتاح balance التراكمي لسه في الكارت', $card['balance'], 2200);
    }

    // ══════════════════════════════════════════════ ٥) الشاشة نفسها
    sec('٥) ماركب الشاشات');

    has('الداشبورد بتعرض فاضل عهدة اليوم', 'app/Views/dashboard/index.php', "day_pocket");
    has('وبعنوان يومي مش تراكمي', 'app/Views/dashboard/index.php', "'label' => 'فاضل مع المناديب'");
    has('نافذة التفاصيل بتعرض «نزل» اليوم', 'app/Views/dashboard/_drill.php', "day_deposits");
    has('ونافذة التفاصيل بتعرض فاضل اليوم', 'app/Views/dashboard/_drill.php', "day_pocket");

    // ⚠️ اللي بيمنع رجوع التراكمي للشاشة: مفيش أي مفتاح تراكمي في ماركب الداشبورد.
    $drill = (string) file_get_contents(dirname(__DIR__) . '/app/Views/dashboard/_drill.php');
    $repsBlock = '';
    if (preg_match("~case 'reps':(.*?)\n    break;~s", $drill, $m)) { $repsBlock = $m[1]; }
    $repsBlock !== '' ? ok('لقينا قسم المناديب في نافذة التفاصيل') : bad('قسم المناديب مش موجود');
    foreach (["\$c['deposits']", "\$c['spent']", "\$c['balance']", "outstanding"] as $needle) {
        !str_contains($repsBlock, $needle)
            ? ok("مفيش «{$needle}» التراكمي في النافذة")
            : bad("«{$needle}» التراكمي رجع للنافذة — ده اللي المالك رفضه");
    }

    // شاشات المندوب
    // ⚠️ v60: شاشة «تسليم عهدة لسائق» اتشالت ومكانها «بوليصة جديدة» — نفس
    //    القاعدة بالحرف (الشاشة تعرض حركة يوم العمل بس) بس على الشاشة الجديدة.
    has('/m/bill/new بتعرض بوالص اليوم بس', 'app/Controllers/RepPortalController.php',
        'RepPortal::myBills($repId, $day)');
    has('/m/transfer بتعرض تحويلات اليوم بس', 'app/Controllers/RepPortalController.php',
        'outgoingFor($repId, 10, RepAuth::workDate())');

    // ══════════════════════════════════════════════ ٦) رندر فعلي
    sec('٦) رندر فعلي للوحة التحكم');

    $_SESSION['uid'] = $_SESSION['uid'] ?? 1;
    $_SESSION['role'] = 'owner';
    $_SESSION['uname'] = 'verify';
    $_SESSION['work_date'] = $today;
    $_SESSION['work_date_set'] = date('Y-m-d');

    ob_start();
    try { (new \App\Controllers\DashboardController())->index(); }
    catch (\Throwable $e) { ob_end_clean(); throw $e; }
    $html = (string) ob_get_clean();

    $html !== '' ? ok('اللوحة رندرت') : bad('اللوحة ما رندرتش');
    // ⚠️ النمط لازم يكون دقيقًا: البحث عن «Notice» حرّة بيقع على `AppUI.notice(`
    //    في جافاسكريبت الصفحة ويدّي **فشلًا كاذبًا** (اتصاد فعليًّا).
    !preg_match('/Fatal error|Warning:|Undefined variable|Undefined array key/', $html, $phpErr)
        ? ok('بلا أي تحذير PHP جوّه الصفحة') : bad('فيه تحذير PHP في الصفحة: ' . ($phpErr[0] ?? ''));
    str_contains($html, 'فاضل مع المناديب')
        ? ok('العنوان اليومي ظاهر للمالك') : bad('العنوان اليومي مش ظاهر');
    !str_contains($html, 'برّه مع المناديب')
        ? ok('★ العنوان التراكمي القديم اختفى من اللوحة')
        : bad('★ «برّه مع المناديب» التراكمي لسه في اللوحة');

} catch (\Throwable $e) {
    bad('استثناء غير متوقّع: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo "\n" . str_repeat('═', 62) . "\n";
    echo "اترجّع كل شيء (rollback) — مفيش أي أثر.\n";
    near('رصيد الخزنة قبل = بعد', Closing::currentBalance(), $treasuryBefore);
    near('إجمالي الفلوس برّه مع المناديب قبل = بعد',
         RepCustody::totalOutstanding(), $outstandingBefore);
    echo "\nنجح: $pass | فشل: $fail\n";
    exit($fail > 0 ? 1 : 0);
}
