<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص **قاعدة الخزنة الجديدة** (المرحلة ٢ من docs/16) — أخطر تغيير في الخطة.
 *
 * ═══ اللي اتقلب ═══
 * v42: تنزيل العهدة مالوش أثر على الدرج · صرف المندوب المعتمَد **بيتخصم منه**.
 * دلوقتي: صرف المندوب **ما بيمسّش الدرج** · والتنزيل بيتخصم **بس لو طلع منه**
 * (`source='office'`). السبب: الفلوس بقت تيجي من البنك للمندوب على طول، فالدرج
 * كان بيتخصم لفلوس عمرها ما دخلته وبينزل سالب.
 *
 * ═══ أخطر ٦ حاجات محروسة هنا ═══
 *   ① **الميزة مطفية = سلوك v42 بالحرف.** بلا الإعداد مفيش رقم واحد بيتغيّر،
 *      فالكود ينفع يترفع قبل التفعيل، والتراجع = مسح إعداد واحد.
 *   ② **الفلوس محفوظة.** (خزنة + مناديب) قبل = بعد. ده الفحص اللي بيمسك
 *      «اتجاه واحد اتطبّق والتاني اتنسي» — وهو الفشل اللي بيبخّر فلوس.
 *   ③ **المسارَان في `Closing` متطابقان.** `movementTotals` (السريع) و
 *      `sumExpenses` (المفرد اللي التقفيل بيستخدمه) لازم يقولوا نفس الرقم، وإلا
 *      الرصيد الحيّ يخالف المثبَّت في `daily_closings` بلا أي خطأ ظاهر.
 *   ④ **شاشة المراجعة = `Closing`.** مجموع الأقسام النقدية في `DailyReview`
 *      لازم يساوي مصروفات `Closing::dayView` بالمليم (قاعدة v50).
 *   ⑤ **الأيام المتقفلة ما بتتغيّرش.** الحدّ على عمود تاريخ الصف نفسه، فإعادة
 *      حساب أي يوم قديم (`refreshLockedChainFrom` — بتتنفّذ لوحدها مع الدخول
 *      الحر) بتطلّع **نفس الرقم** المخزَّن.
 *   ⑥ **الشرط من مصدر واحد.** ممنوع أي ملف خزنة يكتب `paid_by_rep_id` أو
 *      `source = 'office'` بإيده.
 *
 * كله جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_safe_rule.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Database;
use App\Models\Closing;
use App\Models\DailyReview;
use App\Models\RepCustody;

/**
 * ⚠️⚠️ **يوم الاختبار بيتحسب مش مكتوب بالإيد** (تصحيح 2026-08-08).
 *
 * كان ثابتًا `'2026-07-29'`. الشغل مشى قدّام والمالك قفل الأيام لحد ٠٣/٠٨، فاليوم
 * ده بقى **متقفل** — و`Closing::currentBalance` بتقرا الرصيد المثبَّت من
 * `daily_closings` بدل ما تعيد حسابه، فالصفوف المزروعة ما بتحرّكش أي رقم
 * والفاحص بيفشل في ٦ فحص **بلا أي عيب في الكود**. متحقَّق على الإنتاج:
 * نفس الفاحص بيوم مفتوح = صفر فشل.
 *
 * ⚠️ **فاحص مالي فاضل أحمر بيبطّل يكون إشارة** — ده أسوأ من غيابه، لأن أول
 *    ارتداد حقيقي بيضيع وسط الأحمر القديم.
 *
 * القاعدة: **أول يوم مفتوح بعد آخر إقفال**، وما يقلّش عن خط الفصل
 * (`rep_custody_epoch`) وإلا الصفوف المزروعة تتفلتر منه وتختفي من كل حساب.
 */
function SAFE_FROM_day(): string
{
    $epoch  = trim((string) App::setting('rep_custody_epoch', ''));
    $last   = Closing::lastClosedDate();
    $first  = $last !== null
        ? (new DateTimeImmutable($last))->modify('+1 day')->format('Y-m-d')
        : ($epoch !== '' ? $epoch : work_date());
    return ($epoch !== '' && $first < $epoch) ? $epoch : $first;
}
define('SAFE_FROM', SAFE_FROM_day());

$pass = 0; $fail = 0; $notes = [];
function ok(string $m): void   { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  ✗ $m\n"; }
function note(string $m): void { global $notes; $notes[] = $m; echo "  … $m\n"; }
function sec(string $m): void  { echo "\n" . $m . "\n" . str_repeat('─', 70) . "\n"; }
function near(string $l, $a, $e): void {
    abs((float) $a - (float) $e) <= 0.005 ? ok($l)
        : bad("$l — متوقع " . number_format((float) $e, 2) . " / فعلي " . number_format((float) $a, 2));
}
function src(string $f): string {
    $p = dirname(__DIR__) . '/' . $f;
    return is_file($p) ? (string) file_get_contents($p) : '';
}
/** ⚠️ التعليقات بتتشال قبل أي فحص نصّي — التعليق اللي بيوثّق قاعدة بيذكر نصّها
 *  فبيدّي نجاحًا أو فشلًا كاذبًا (مصيدة موثَّقة في verify_security و verify_ux). */
function code_only(string $s): string {
    $s = preg_replace('~/\*.*?\*/~s', '', $s) ?? $s;
    return preg_replace('~^\s*//.*$~m', '', $s) ?? $s;
}

echo "\n═══ فحص قاعدة الخزنة الجديدة (المرحلة ٢) ═══\n";

$savedRule = (string) App::setting('rep_safe_rule_from', '');

// ─────────────────────────────────────────────────── ① الآلية والمصدر الواحد
sec('① الآلية — مفتاح تفعيل منفصل، والمطفي = سلوك v42 بالحرف');

App::setSetting('rep_safe_rule_from', ''); RepCustody::refreshSafeRule();
RepCustody::safeChargeSql('s.entry_date', 's.paid_by_rep_id') === ''
    ? ok('مطفي ⇒ `safeChargeSql` بترجّع فاضي (صفر تغيير)') : bad('مطفي وبرضه بترجّع شرط');
RepCustody::officeDepositSql() === ''
    ? ok('مطفي ⇒ `officeDepositSql` بترجّع فاضي') : bad('مطفي وبرضه بترجّع شرط');
RepCustody::newSafeRuleActive() === false ? ok('`newSafeRuleActive` = false') : bad('لسه فعّالة');

App::setSetting('rep_safe_rule_from', SAFE_FROM); RepCustody::refreshSafeRule();
if (!RepCustody::sourceReady()) {
    note('عمود `source` مش موجود (migrate_v61 ما اتشغّلش) — فحوصات السلوك هتتخطّى');
}
RepCustody::safeRuleFrom() === SAFE_FROM ? ok('الإعداد اتقرا: ' . SAFE_FROM) : bad('الإعداد ما اتقراش');

/* ⚠️⚠️ أهم فحص بنيوي: **مفتاح التفعيل منفصل عن `rep_custody_epoch`.**
   خط الفصل مفعّل على الإنتاج، فلو القاعدة اتربطت بيه كان رصيد الخزنة هيتغيّر
   **لحظة وصول الملفات** قبل أي مراجعة. */
$rc = code_only(src('app/Models/RepCustody.php'));
preg_match('~function safeChargeSql\(.*?\n    \}~s', $rc, $m1);
$body = $m1[0] ?? '';
$body !== '' && !str_contains($body, 'self::epoch()')
    ? ok('⚠️ `safeChargeSql` مش مربوطة بـ`epoch()` — مفتاح تفعيل منفصل')
    : bad('⚠️⚠️ مربوطة بخط الفصل — الرفع لوحده هيقلب الفلوس!');
str_contains($rc, "App::setting('rep_safe_rule_from'")
    ? ok('المفتاح `rep_safe_rule_from` موجود') : bad('المفتاح مش موجود');
preg_match('~function newSafeRuleActive\(.*?\n    \}~s', $rc, $m2);
str_contains($m2[0] ?? '', 'sourceReady')
    ? ok('التفعيل بيشترط عمود `source` كمان (الاتجاهان مع بعض)')
    : bad('ينفع يتفعّل بلا عمود المصدر — الدرج هيقول رقمًا أعلى من الحقيقة');

// ─────────────────────────────────────────────────── ② التغطية
sec('② التغطية — كل نقطة خزنة بتمرّ على المصدر الواحد');

$sites = [
    'app/Models/Closing.php'                     => ['safeChargeSql', 'officeDepositSql'],
    'app/Models/DailyReview.php'                 => ['newSafeRuleActive', 'officeDepositSql'],
    'app/Controllers/DailyClosingController.php' => ['safeChargeSql', 'officeDepositSql'],
    'bin/backfill_closings.php'                  => ['safeChargeSql', 'officeDepositSql'],
];
foreach ($sites as $file => $needles) {
    $s = code_only(src($file));
    foreach ($needles as $n) {
        str_contains($s, $n) ? ok(basename($file) . " بينادي $n")
                             : bad(basename($file) . " ما بينادّيش $n");
    }
}
/* المسارات في Closing لازم كلها مغطّية.
   ⚠️ **العدد مشتقّ من الكود مش مكتوب بالإيد**: أول نسخة من الفاحص كتبت «≥4»
      وفشلت بإنذار كاذب — لأن `earliestMovementDate` بتعمل `MIN(s.entry_date)`
      بلا أي شرط مصروف أصلاً (over-broad عن قصد) فمش محتاجة الشرط. القاعدة
      الصح: **كل استعلام فيه `Permit::cashOutSql('s')` لازم معاه الشرط**. */
$cl = code_only(src('app/Models/Closing.php'));
$needCash = substr_count($cl, "Permit::cashOutSql('s')");
$haveCash = substr_count($cl, "safeChargeSql('s.entry_date', 's.paid_by_rep_id')");
$needCash > 0 && $haveCash >= $needCash
    ? ok("شرط بنود الأذونات في كل استعلام نقدي في Closing ($haveCash/$needCash)")
    : bad("ناقص في مسار — الرصيد الحيّ هيخالف المثبَّت ($haveCash/$needCash)");
$needMisc = substr_count($cl, 'RepMiscExpense::cashOnlySql()');
$haveMisc = substr_count($cl, "safeChargeSql('exp_date')");
$needMisc > 0 && $haveMisc >= $needMisc
    ? ok("شرط النثريات في كل استعلام في Closing ($haveMisc/$needMisc)")
    : bad("ناقص في مسار للنثريات ($haveMisc/$needMisc)");

// ⚠️ ممنوع أي ملف خزنة يكتب الشرط بإيده
foreach (['app/Models/Closing.php', 'app/Models/DailyReview.php',
          'app/Controllers/DailyClosingController.php', 'bin/backfill_closings.php'] as $f) {
    $s = code_only(src($f));
    !preg_match("~source\s*=\s*'office'~", $s)
        ? ok(basename($f) . ': مفيش `source = office` مكتوب بالإيد')
        : bad(basename($f) . ': الشرط مكتوب بالإيد — لازم من `officeDepositSql`');
}

// ─────────────────────────────────────────────────── ③ حدّ النطاق
sec('③ حدّ النطاق — الحدّ على عمود تاريخ الصف نفسه (الأيام المتقفلة تفضل صح)');

$sql = RepCustody::safeChargeSql('s.entry_date', 's.paid_by_rep_id');
str_contains($sql, "s.entry_date < '" . SAFE_FROM . "'")
    ? ok('الشرط على تاريخ الصف — مش على «النهاردة»')
    : bad('الحدّ مش على عمود الصف: ' . $sql);
!str_contains($sql, 'CURDATE') && !str_contains($sql, 'NOW()')
    ? ok('مفيش دالة وقت حاضر في الشرط (إعادة حساب يوم قديم بتفضل ثابتة)')
    : bad('⚠️ فيه دالة وقت — إعادة حساب يوم متقفل هتطلّع رقمًا مختلفًا كل مرة');

// ─────────────────────────────────────────────────── لقطة ما قبل
// ⚠️ اللقطة بالإعداد **الحقيقي** مش بإطفاء قسري — القسم ① بيطفّي عشان يختبر،
//    ولو اتاخدت عليه، قسم الرجوع هيقارن حالتين مختلفتين ويفشل بإنذار كاذب
//    (اتصاد فعليًّا في `verify_custody_epoch`).
App::setSetting('rep_safe_rule_from', $savedRule); RepCustody::refreshSafeRule();
$snapshot = static function (): array {
    $safe = round(Closing::currentBalance(), 2);
    $reps = round(RepCustody::totalOutstanding(), 2);
    return ['safe' => $safe, 'reps' => $reps, 'total' => round($safe + $reps, 2)];
};
$closings = static function (): string {
    return (string) Database::scalar(
        "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS('|',close_date,opening,topups,expenses,
                driver_custody,closing_balance) ORDER BY close_date),'') FROM daily_closings");
};
$before = $snapshot();
$clsBef = $closings();

// ─────────────────────────────────────────────────── ④→⑦ سلوكيًّا
$pdo = Database::conn();
$pdo->beginTransaction();
try {
    if (!RepCustody::sourceReady()) {
        note('عمود `source` مش موجود — الأقسام ④→⑦ اتخطّت');
        throw new RuntimeException('__SKIP__');
    }

    App::setSetting('rep_safe_rule_from', SAFE_FROM); RepCustody::refreshSafeRule();

    sec('④ الاتجاهان — الفلوس محفوظة (أخطر فحص في الميزة)');

    $rid = (int) Database::scalar("SELECT id FROM reps WHERE status='active' ORDER BY id LIMIT 1");
    if (!$rid) { note('مفيش مندوب نشط — اتخطّى'); throw new RuntimeException('__SKIP__'); }

    $base = $snapshot();

    /* أ) تنزيل من **البنك**: محفظة المندوب بتزيد والدرج ما يتحركش.
          ⇒ الإجمالي بيزيد بالمبلغ (فلوس جديدة دخلت النظام من برّه). */
    /* ⚠️ أثر تنزيل البنك على الخزنة بيتحدّد من `bankTopupActive` (قرار المالك
       2026-07-30: «أي عهدة تتضاف للمندوب كأنها في الدرج»). */
    $bankAdds = RepCustody::bankTopupActive() ? 4000 : 0;
    $depBank = RepCustody::createDeposit($rid, 4000, SAFE_FROM, '__vsr_bank', 'bank');
    $s1 = $snapshot();
    near($bankAdds ? 'تنزيل من البنك: الخزنة زادت بنفس المبلغ'
                   : 'تنزيل من البنك: الدرج ما اتحركش',
         $s1['safe'], $base['safe'] + $bankAdds);
    near('تنزيل من البنك: محفظة المندوب +4000', $s1['reps'] - $base['reps'], 4000);

    /* ب) تنزيل من **الدرج**: الدرج بينقص والمحفظة بتزيد بنفس المبلغ.
          ⇒ **الإجمالي ثابت** — وده اللي يمنع «الفلوس بتتبخّر». */
    $depOffice = RepCustody::createDeposit($rid, 3000, SAFE_FROM, '__vsr_office', 'office');
    $s2 = $snapshot();
    near('تنزيل من الدرج: الدرج −3000', $s2['safe'] - $s1['safe'], -3000);
    near('تنزيل من الدرج: المحفظة +3000', $s2['reps'] - $s1['reps'], 3000);
    near('⚠️⚠️ الفلوس محفوظة — الإجمالي ثابت', $s2['total'], $s1['total']);

    /* ج) تنزيل **مرحَّل**: زي البنك بالظبط (مالوش أثر على الدرج) — ده اللي
          تصحيح Q4 بيستخدمه للكاش اللي كان في جيب المناديب. */
    $depCarry = RepCustody::createDeposit($rid, 1500, SAFE_FROM, '__vsr_carry', 'carry');
    $s3 = $snapshot();
    near('تنزيل مرحَّل: الدرج ما اتحركش', $s3['safe'], $s2['safe']);
    near('تنزيل مرحَّل: المحفظة +1500', $s3['reps'] - $s2['reps'], 1500);

    // د) الفورم مش مصدر ثقة — أي قيمة برّه القايمة بترجع `bank`
    $bad3 = [];
    foreach (['', 'xyz', 'OFFICE', 'drawer', '0'] as $v) {
        if (RepCustody::cleanSource($v) !== 'bank') { $bad3[] = $v === '' ? '(فاضي)' : $v; }
    }
    $bad3 ? bad('`cleanSource` قبلت قيمة غلط: ' . implode('، ', $bad3))
          : ok('`cleanSource` بترجّع `bank` لأي قيمة برّه القايمة المقفولة');
    RepCustody::cleanSource('office') === 'office' ? ok('و`office` بتعدّي زي ما هي') : bad('office اترفضت');

    sec('⑤ صرف المندوب مبقاش يمسّ الدرج');

    $pid = (int) Database::scalar("SELECT id FROM permits ORDER BY id DESC LIMIT 1");
    if ($pid) {
        $safeA = round(Closing::currentBalance(), 2);
        // بند نقدي عادي (من الدرج) بتاريخ بعد بداية القاعدة
        Database::exec(
            "INSERT INTO permit_stages (permit_id, stage_key, direction, amount, entry_date,
                    note, is_visa)
             VALUES (?, 'custom', 'expense', 777, ?, '__vsr_stage', 0)", [$pid, SAFE_FROM]);
        $sid = Database::lastId();
        /* ⚠️ الخطوة دي **إلزامية**: من غيرها فحص «الدرج ما اتحركش» بينجح كذبًا لو
           البند أصلاً مش داخل الحساب (نوع غلط، أو الإدخال فشل). لازم نثبت الأول
           إن البند **بيتحسب فعلاً**، وبعدها نثبت إن الوسم هو اللي بيشيله. */
        near('بند نقدي عادي بيتخصم من الدرج (إثبات إن الفحص شغّال)',
             round(Closing::currentBalance(), 2), round($safeA - 777, 2));

        Database::exec('UPDATE permit_stages SET paid_by_rep_id = ? WHERE id = ?', [$rid, $sid]);
        near('نفس البند لما يتوسم «دفعه المندوب»: الدرج بيرجع زي ما كان',
             round(Closing::currentBalance(), 2), $safeA);

        /* ⚠️⚠️ **القاعدة القديمة لازم تفضل سارية على ما قبل التاريخ — والفحص ده
           لازم يبقى على الشرط نفسه مش على `currentBalance`.** أول نسخة نقلت البند
           لـ2026-07-01 وقاست الرصيد، وفشلت بإنذار كاذب: `currentBalance` بتبني على
           **آخر إقفال** وما بتحسبش أي حركة قبله. وعلى الإنتاج آخر إقفال 2026-07-28
           والقاعدة بتبدأ 2026-07-29، فمفيش **ولا تاريخ** يكون بعد الإقفال وقبل
           القاعدة في نفس الوقت — يعني الفحص ده مستحيل يتعمل بالرصيد هناك أصلاً. */
        Database::exec('UPDATE permit_stages SET entry_date = ? WHERE id = ?', ['2026-07-01', $sid]);
        $matched = (int) Database::scalar(
            "SELECT COUNT(*) FROM permit_stages s WHERE s.id = ?"
            . RepCustody::safeChargeSql('s.entry_date', 's.paid_by_rep_id'), [$sid]);
        $matched === 1
            ? ok('بند مدفوع من محفظة **قبل** بداية القاعدة: لسه داخل حساب الدرج (القاعدة القديمة)')
            : bad('⚠️⚠️ بند قديم اتشال من حساب الدرج — القاعدة الجديدة بترجع للماضي!');
        // وبعد التاريخ: الشرط لازم يستبعده
        Database::exec('UPDATE permit_stages SET entry_date = ? WHERE id = ?', [SAFE_FROM, $sid]);
        (int) Database::scalar(
            "SELECT COUNT(*) FROM permit_stages s WHERE s.id = ?"
            . RepCustody::safeChargeSql('s.entry_date', 's.paid_by_rep_id'), [$sid]) === 0
            ? ok('وبعد بدايتها: الشرط بيستبعده') : bad('الشرط ما بيستبعدهوش');

        Database::exec('DELETE FROM permit_stages WHERE id = ?', [$sid]);
    } else {
        note('مفيش إذن للاختبار — فحص البند اتخطّى');
    }

    sec('⑥ المسارَان في Closing + شاشة المراجعة بيقولوا نفس الرقم');

    /* ⚠️ `dayView` بيستخدم المسار المفرد (`sumExpenses`) و`currentBalance`
       بيستخدم المسار السريع (`movementTotals`). لو اختلفوا، الرصيد الحيّ يخالف
       اللي بيتثبّت في `daily_closings` عند التقفيل. */
    $view = Closing::dayView(SAFE_FROM);
    $rebuilt = round(Closing::openingFor(SAFE_FROM) + $view['topups']
                     - $view['expenses'] - $view['driver'], 2);
    near('المسار المفرد متسق مع نفسه (opening + داخل − خارج = المرحّل)',
         $rebuilt, $view['closing']);

    // ④ (قاعدة v50) مجموع الأقسام النقدية = مصروفات اليوم
    $rv = DailyReview::view(SAFE_FROM);
    if (!empty($rv['available'])) {
        /* ⚠️ الاشتقاق من `BLOCKING` بشرط `dir='out'` — الأقسام الداخلة
           (عهدة الدرج وعهدة المناديب من البنك) مالهاش علاقة بالمصروفات. */
        $cashOut = 0.0; $repWallet = 0.0;
        foreach ($rv['groups'] as $k => $g) {
            if ($k === 'custody') { continue; }   // عهد السائقين بند مستقل في Closing
            if (($g['dir'] ?? '') === 'out' && in_array($k, DailyReview::BLOCKING, true)) {
                $cashOut += (float) $g['total'];
            }
            if ($k === 'rep_wallet') { $repWallet += (float) $g['total']; }
        }
        near('⚠️ مجموع أقسام المراجعة النقدية = مصروفات Closing بالمليم',
             round($cashOut, 2), round((float) $view['expenses'], 2));
        /* ⚠️ عهدة المندوب بقت **جوّه** قسم «عهدة مضافة» (طلب المالك) مش قسمًا
           جنبه — فالفحص على التفكيك: بنود الدرج لوحدها والمناديب لوحدهم. */
        ($rv['totals']['rep_bank'] ?? -1) >= 0 && ($rv['totals']['topups_drawer'] ?? -1) >= 0
            ? ok('تفكيك «عهدة مضافة» موجود (درج / مناديب)') : bad('التفكيك ناقص');
        /* ⚠️ التفكيك تلات أنواع مش اتنين: الدرج · عهدة البنك (بتتحسب) · الرصيد
           المرحَّل (معروض للتوثيق بس — متحسوب خلاص في مرحّل امبارح). */
        near('ومجموع القسم = الدرج + البنك + المرحَّل',
             (float) $rv['groups']['topups']['total'],
             round((float) $rv['totals']['topups_drawer'] + (float) $rv['totals']['rep_bank']
                 + (float) $rv['totals']['rep_carry'], 2));
        $repWallet > 0
            ? ok('وقسم «دفعها المندوب من عهدته» فيه ' . number_format($repWallet, 2) . ' — برّه النقدي')
            : note('مفيش بنود محفظة في اليوم ده — القسم فاضي');
        !in_array('rep_wallet', DailyReview::BLOCKING, true)
            ? ok('`rep_wallet` مش مانع للإقفال (معلوماتي زي الفيزا)')
            : bad('⚠️ `rep_wallet` مانع للإقفال — بند ما خرجش من الدرج بيمنع التقفيل');
        in_array('rep_deposit', DailyReview::BLOCKING, true)
            ? ok('`rep_deposit` مانع للإقفال (كاش خرج من الدرج فعلاً)')
            : bad('⚠️ التنزيل من الدرج مش مانع — كاش خرج بلا مراجعة');
    } else {
        note('جدول المراجعة مش متاح — القسم اتخطّى');
    }

    sec('⑥ب شاشة التقفيل بترندر القسمين الجديدين فعليًّا');

    /* ⚠️ `app/Views/closing/index.php` بتلفّ على `$groups` ديناميكيًّا فالقسمان
       الجديدان بيظهروا بلا أي تعديل فيها — **بس ده لازم يتأكّد بالرندر**، لأن
       قسم بلا عنوان أو بأيقونة ناقصة بيقع على شكل افتراضي بالسكوت (نفس مصيدة
       `.table-mobile-scroll` و`icon('archive')` الموثَّقتين). */
    $depR = RepCustody::createDeposit($rid, 2500, SAFE_FROM, '__vsr_render', 'office');
    $sess = $_SESSION ?? [];
    $_SESSION = ['uid' => (int) Database::scalar('SELECT MIN(id) FROM users'), 'role' => 'owner',
                 'uname' => 'فحص المرحلة ٢', 'last_activity' => time(), 'uid_checked' => time(),
                 'work_date' => SAFE_FROM, 'work_date_set' => date('Y-m-d')];
    $_GET['date'] = SAFE_FROM;   // ⚠️ `date` مش `d` — `requestedDate` بتقرا `date`
    ob_start();
    try { (new \App\Controllers\DailyClosingController())->index(); $html = (string) ob_get_clean(); }
    catch (\Throwable $e) { ob_end_clean(); $html = ''; bad('شاشة التقفيل رمت استثناء: ' . $e->getMessage()); }
    unset($_GET['date']);
    $_SESSION = $sess;
    if ($html !== '') {
        preg_match('/Fatal error|Warning:|Undefined variable|Undefined array key/', $html, $mR)
            ? bad('شاشة التقفيل فيها خطأ PHP: ' . ($mR[0] ?? '')) : ok('شاشة التقفيل بترندر بلا أخطاء PHP');
        str_contains($html, 'عهدة نزلت للمناديب من الدرج')
            ? ok('قسم «عهدة نزلت للمناديب من الدرج» ظاهر في الشاشة') : bad('القسم مش ظاهر');
        str_contains($html, '2,500')
            ? ok('والمبلغ ظاهر فيه (مش قسم فاضي)') : bad('المبلغ مش ظاهر');
        /* ⚠️ **تفكيك المحافظ (المرحلة ٤.٣) بقى كروت تحت المعادلة** (2026-07-31،
           طلب المالك) بدل القسم اللي كان تحت الشاشة — نفس الأرقام بالحرف من
           `Closing::walletSplit()`. القسم القديم اتشال لأنه كان بيكرّرها.
           ⚠️ **الفحص على المعنى مش على نص بعينه:** المطلوب إن الشاشة تقول
              الكاش فين (درج + مناديب) وإن كل رقم يفتح تفاصيله. */
        str_contains($html, 'class="cl-split"')
            && str_contains($html, 'رصيد الخزنة الفعلي')
            && str_contains($html, 'مع المناديب برّه')
            ? ok('وكروت «الكاش فين دلوقتي» ظاهرة (الدرج + المناديب)')
            : bad('كروت تفكيك المحافظ مش ظاهرة');
        str_contains($html, 'id="drillDrawer"') && str_contains($html, 'id="drillReps"')
            ? ok('وكل رقم فيها بيفتح تفاصيله (ممنوع رقم أصمّ)')
            : bad('كروت الكاش أرقام صمّاء — مفيش تفاصيل');
        // ⚠️ القسم القديم ما يرجعش — رجوعه معناه نفس الأرقام مرتين في شاشة واحدة
        !str_contains($html, 'id="wallets"')
            ? ok('⚠️ والقسم المكرر القديم اتشال') : bad('⚠️ القسم القديم رجع — الأرقام مكرّرة مرتين');
        /* ⚠️ طلب المالك: عهدة المندوب تنزل في «عهدة مضافة»، والرقم يفتح تفكيكه. */
        str_contains($html, 'data-drill="#topupsDrill"') && str_contains($html, 'id="topupsDrill"')
            ? ok('رقم «عهدة مضافة» بيفتح تفكيكه (ممنوع رقم أصمّ)') : bad('الرقم أصمّ — مفيش تفكيك');
        /* ⚠️ **اسم بند المعادلة بيتبع النموذج — والخطر الحقيقي هو الجمع المزدوج:**
           الاسم القديم «عهدة مضافة» كان بيتحطّ على `sumTopups` **كلها** وتحته سطر
           تفكيك بيقول «منها كذا للمناديب»، فالمالك يقراهم **جمعًا** (٨١ ألف + ٨١
           ألف). الحماية الأصلية كانت منع كلمة «للدرج» في النموذج المجمّع.

           ⚠️⚠️ **الحماية دي بقت بايتة بعد تقسيم الكارت لخانتين (2026-08-01):**
           المعادلة دلوقتي بتعرض «عهدة مضافة للدرج» = `topups − bankDeposits`
           (الدرج لوحده فعلاً) **جنب** «عهدة منزلة للمناديب» = `bankDeposits`،
           فالاسم صح والمجموع صح. منع الكلمة كان بيفشل على الإنتاج **في كل يوم
           فيه إضافة للدرج** (متحقَّق 2026-08-08 على نسخة ما قبل التعديل كمان)،
           والفحص اللي بيفشل دايمًا بيبطّل يكون إشارة.

           القاعدة الجديدة بتحمي **نفس الخطر** بدقّة: لو الخانة معنونة «للدرج»
           ولليوم عهد بنك فعلاً، لازم خانة «عهدة منزلة للمناديب» تكون ظاهرة كمان
           — يعني التقسيم مكتمل ومفيش رقم بيتقري مرتين. */
        $bankOnDay = \App\Models\Closing::dayBankDeposits(SAFE_FROM);
        if (\App\Models\RepCustody::bankTopupActive()) {
            if (!str_contains($html, 'عهدة مضافة')) {
                bad('العنوان ناقص — المعادلة مش بتقول إن فيه عهدة اتضافت');
            } elseif (str_contains($html, 'عهدة مضافة للدرج') && abs($bankOnDay) > 0.005
                      && !str_contains($html, 'عهدة منزلة للمناديب')) {
                bad('⚠️ خانة «للدرج» ظاهرة وفيه عهد بنك في اليوم بلا خانتها — الرقم بيتقري مرتين');
            } else {
                ok('النموذج المجمّع: التقسيم مكتمل — مفيش رقم بيتقري مرتين');
            }
        } else {
            str_contains($html, 'عهدة مضافة للدرج')
                ? ok('المعادلة معنونة «للدرج» — الشاشة ما بتناقضش نفسها')
                : bad('العنوان ناقص');
        }
        str_contains($html, 'نزل له') && str_contains($html, 'الكاش اللي دخل الدرج فعلاً')
            ? ok('التفكيك بيعرض الدرج وكل مندوب نزله كام') : bad('التفكيك ناقص');
    }
    Database::exec('DELETE FROM rep_custody_deposits WHERE id = ?', [$depR]);

    sec('⑥ج تفكيك المحافظ في شاشة التقفيل (المرحلة ٤.٣)');

    /* ⚠️ **مفيش رقم بيتحسب من جديد** — القسم بيقرا `Closing::currentBalance` و
       `RepCustody::balance` نفسهم بتوع كل شاشة تانية. الفحص بيقارن كل رقم
       بمصدره، فلو حد كتب حساب موازٍ هنا بيتمسك فورًا. */
    /* ⚠️ معنى `currentBalance` بيتغيّر مع النموذج، والتفكيك بيتبعه:
       «الخزنة = الدرج» ⇒ drawer = currentBalance · «= كل الكاش» ⇒ total = currentBalance. */
    $split = Closing::walletSplit();
    RepCustody::bankTopupActive()
        ? near('الإجمالي في التفكيك = `Closing::currentBalance` (الخزنة = كل الكاش)',
               $split['total'], Closing::currentBalance())
        : near('الدرج في التفكيك = `Closing::currentBalance` (الخزنة = الدرج)',
               $split['drawer'], Closing::currentBalance());
    $sum = 0.0; $mismatch = [];
    foreach ($split['reps'] as $w) {
        $sum += $w['balance'];
        if (abs($w['balance'] - RepCustody::balance((int) $w['id'])) > 0.005) { $mismatch[] = $w['name']; }
    }
    $mismatch ? bad('رصيد مندوب في التفكيك يخالف كارته: ' . implode('، ', $mismatch))
              : ok('رصيد كل مندوب في التفكيك = `RepCustody::balance` بالمليم');
    near('مجموع المناديب متسق', $split['reps_total'], round($sum, 2));
    near('⚠️ الإجمالي = الدرج + المناديب', $split['total'],
         round($split['drawer'] + $split['reps_total'], 2));
    near('والإجمالي = الدرج + المناديب دايمًا (مهما كان النموذج)',
         $split['total'], round($split['drawer'] + $split['reps_total'], 2));

    // ⚠️ الرصيد السالب مشروع (المكتب مدين للمندوب) — ممنوع يتقصّ على صفر
    $anyNeg = false;
    foreach ($split['reps'] as $w) { if ($w['balance'] < 0) { $anyNeg = true; } }
    !$anyNeg || $split['reps_total'] < $sum + 0.005
        ? ok('الرصيد السالب بيعدّي زي ما هو (مفيش max(0,…))') : bad('السالب اتقصّ');

    sec('⑦ الأيام المتقفلة — إعادة الحساب بتطلّع نفس الرقم');

    /* ⚠️ ده الفحص اللي بيمنع أخطر ارتداد ممكن: `refreshLockedChainFrom` بتتنفّذ
       **لوحدها** مع «الدخول الحر»، فلو القاعدة الجديدة اتطبّقت على يوم قديم كانت
       هتعيد كتابة تاريخ مقفول بالسكوت. */
    $lastClosed = Closing::lastClosedDate();
    if ($lastClosed) {
        $stored = Database::row('SELECT * FROM daily_closings WHERE close_date = ?', [$lastClosed]);
        $recomputed = round(Closing::openingFor($lastClosed)
            + (float) $stored['topups'] - (float) $stored['expenses'] - (float) $stored['driver_custody'], 2);
        near("إعادة حساب opening ليوم {$lastClosed} بتطلّع نفس المخزَّن",
             Closing::openingFor($lastClosed), round((float) $stored['opening'], 2));
        Closing::refreshLockedChainFrom($lastClosed);
        $closings() === $clsBef
            ? ok('⚠️ `refreshLockedChainFrom` ما غيّرتش ولا صف إقفال')
            : bad('⚠️⚠️ صفوف الإقفال اتغيّرت — القاعدة الجديدة بتعيد كتابة الماضي!');
        unset($recomputed);
    } else {
        note('مفيش يوم متقفل — القسم اتخطّى');
    }

    // تنضيف الـfixtures عشان قسم الرجوع يقيس القاعدة لوحدها
    foreach ([$depBank, $depOffice, $depCarry] as $d) {
        Database::exec('DELETE FROM rep_custody_deposits WHERE id = ?', [$d]);
    }
} catch (\Throwable $e) {
    if ($e->getMessage() !== '__SKIP__') {
        bad('استثناء: ' . $e->getMessage());
    }
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    App::setSetting('rep_safe_rule_from', $savedRule);
    RepCustody::refreshSafeRule();
}

// ─────────────────────────────────────────────────── ⑧ الرجوع
sec('⑧ الرجوع — الحالة رجعت زي ما كانت');

$after = $snapshot();
$diff = [];
foreach ($before as $k => $v) { if (abs($v - ($after[$k] ?? 0)) > 0.005) { $diff[] = $k; } }
$diff ? bad('اتغيّر: ' . implode('، ', $diff)) : ok('الخزنة والمناديب والإجمالي رجعوا زي ما كانوا');
$closings() === $clsBef ? ok('كل صفوف daily_closings متطابقة حرفيًّا') : bad('صف إقفال اتغيّر');
(string) App::setting('rep_safe_rule_from', '') === $savedRule
    ? ok('الإعداد رجع لقيمته الأصلية') : bad('الإعداد ما رجعش');

echo "\n" . str_repeat('═', 70) . "\n";
foreach ($notes as $n) { echo "… $n\n"; }
echo ($fail === 0 ? "✅ " : "❌ ") . "نجح $pass / فشل $fail\n";
exit($fail === 0 ? 0 : 1);
