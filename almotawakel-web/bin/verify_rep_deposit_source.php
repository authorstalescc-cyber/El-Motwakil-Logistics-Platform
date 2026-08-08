<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص **«نزّل عهدة لمندوب من الخزنة نفسها»** — خانة «الفلوس جاية منين؟» ونقطة
 * الدخول من صفحة الخزنة.
 *
 * ═══ الباج اللي اتقفل (مقيس على الإنتاج) ═══
 * خانة المصدر كانت مشروطة بـ`newSafeRuleActive()` **لوحده**. ولما اتفعّل نموذج
 * «الخزنة = كل كاش الشركة» (`rep_bank_topup_from`) لوحده، الخانة فضلت **مخفية**
 * ⇒ كل تنزيل بيتسجّل `bank` غصبًا، و`bank` **بيزوّد الخزنة**
 * (`Closing::sumBankDeposits`). فالمالك اللي بياخد كاش من الدرج ويسلّمه لمندوب
 * كان بيلاقي الخزنة **زادت** بالمبلغ وهو ما دخلش أصلاً — الفلوس تتقري مرتين.
 *
 * ═══ أخطر ٥ حاجات محروسة هنا ═══
 *   ① **المصدر الواحد.** `sourceMatters()` و`depositAffectsSafe()` هما المرجع،
 *      وممنوع أي كنترولر يكتب شرط المصدر بإيده.
 *   ② **الخانة بتظهر في النموذجين.** رندر فعلي لكارت المندوب.
 *   ③ **الفلوس محفوظة.** تنزيل `office` = تحويل داخلي ⇒ (خزنة + مناديب) ثابت،
 *      وتنزيل `bank` = فلوس جديدة ⇒ الإجمالي بيزيد. ده الفحص اللي بيمسك
 *      «الازدواج» اللي الميزة اتعملت عشانه.
 *   ④ **حارس اليوم المتقفل بيتبع الأثر مش اسم المصدر.** التنزيل اللي بيغيّر
 *      رصيد الخزنة (إنشاءً أو حذفًا) بيترفض على يوم متقفل، واللي مالوش أثر بيعدّي.
 *   ⑤ **صفحة الخزنة بتثبّت `office` في الكود مش في الفورم** — الشاشة معناها
 *      «الفلوس طلعت من الدرج اللي قدّامي»، فقبول مصدر من الفورم كان بيسمح
 *      بتسجيل «فلوس جديدة داخلة» من مكان بيقول العكس.
 *
 * كله جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_rep_deposit_source.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Database;
use App\Models\Closing;
use App\Models\RepCustody;

/**
 * ⚠️⚠️ **يوم الاختبار بيتحسب مش مكتوب بالإيد** (تصحيح 2026-08-08).
 *
 * كان ثابتًا `'2026-07-29'`. الشغل مشى قدّام والمالك قفل الأيام لحد ٠٣/٠٨، فاليوم
 * ده بقى **متقفل** — و`Closing::currentBalance` بتقرا الرصيد المثبَّت من
 * `daily_closings` بدل ما تعيد حسابه، فالصفوف المزروعة ما بتحرّكش أي رقم
 * والفاحص بيفشل في ٩ فحص **بلا أي عيب في الكود**. متحقَّق على الإنتاج:
 * نفس الفاحص بيوم مفتوح = صفر فشل.
 *
 * ⚠️ **فاحص مالي فاضل أحمر بيبطّل يكون إشارة** — ده أسوأ من غيابه، لأن أول
 *    ارتداد حقيقي بيضيع وسط الأحمر القديم.
 *
 * القاعدة: **أول يوم مفتوح بعد آخر إقفال**، وما يقلّش عن خط الفصل
 * (`rep_custody_epoch`) وإلا الصفوف المزروعة تتفلتر منه وتختفي من كل حساب.
 */
function EPOCH_D_day(): string
{
    $epoch  = trim((string) App::setting('rep_custody_epoch', ''));
    $last   = Closing::lastClosedDate();
    $first  = $last !== null
        ? (new DateTimeImmutable($last))->modify('+1 day')->format('Y-m-d')
        : ($epoch !== '' ? $epoch : work_date());
    return ($epoch !== '' && $first < $epoch) ? $epoch : $first;
}
define('EPOCH_D', EPOCH_D_day());

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
/** ⚠️ التعليقات بتتشال قبل أي فحص نصّي — التعليق اللي بيوثّق القاعدة بيذكر نصّها
 *  فبيدّي نجاحًا أو فشلًا كاذبًا (مصيدة موثَّقة في verify_security و verify_ux). */
function code_only(string $s): string {
    $s = preg_replace('~/\*.*?\*/~s', '', $s) ?? $s;
    return preg_replace('~^\s*//.*$~m', '', $s) ?? $s;
}

echo "\n═══ فحص «نزّل عهدة لمندوب من الخزنة» ═══\n";

$savedRule = (string) App::setting('rep_safe_rule_from', '');
$savedBank = (string) App::setting('rep_bank_topup_from', '');
/** يضبط النموذجين ويصفّر الكاش — الكاش الساكن بيفضل بالقيمة القديمة (مصيدة v57). */
$setModel = static function (string $safeRule, string $bankTopup): void {
    App::setSetting('rep_safe_rule_from', $safeRule); RepCustody::refreshSafeRule();
    App::setSetting('rep_bank_topup_from', $bankTopup); RepCustody::refreshBankTopup();
};

// ───────────────────────────────────────────── ① المصدر الواحد وجدول الحقيقة
sec('① `sourceMatters` و`depositAffectsSafe` — جدول الحقيقة في النماذج الأربعة');

if (!RepCustody::sourceReady()) {
    note('عمود `source` مش موجود (migrate_v61 ما اتشغّلش) — فحوصات السلوك هتتخطّى');
}

/* [safeRule, bankTopup] => [sourceMatters, office يمسّ؟, bank يمسّ؟, carry يمسّ؟] */
$truth = [
    'النموذجان مطفيان'          => ['', '',        false, false, false, false],
    'قاعدة المرحلة ٢ بس'        => [EPOCH_D, '',   true,  true,  false, false],
    'الخزنة = كل كاش الشركة بس' => ['', EPOCH_D,   true,  false, true,  false],
    'الاتنين مع بعض'            => [EPOCH_D, EPOCH_D, true, true, true,  false],
];
foreach ($truth as $label => [$sr, $bt, $matters, $off, $bank, $carry]) {
    $setModel($sr, $bt);
    // ⚠️ من غير عمود `source` النموذجان مطفيان بالبناء — الجدول بيتقاس على المتاح
    $expMatters = RepCustody::sourceReady() ? $matters : false;
    RepCustody::sourceMatters() === $expMatters
        ? ok("$label: `sourceMatters` = " . ($expMatters ? 'true' : 'false'))
        : bad("$label: `sourceMatters` غلط");
    if (!RepCustody::sourceReady()) { continue; }
    $got = [RepCustody::depositAffectsSafe('office'), RepCustody::depositAffectsSafe('bank'),
            RepCustody::depositAffectsSafe('carry')];
    $got === [$off, $bank, $carry]
        ? ok("$label: أثر (office/bank/carry) صح")
        : bad("$label: أثر غلط — " . json_encode($got));
}
// ⚠️ `carry` عمره ما يمسّ الخزنة في أي نموذج — رصيد متحسوب خلاص في مرحّل امبارح
RepCustody::depositAffectsSafe('carry') === false
    ? ok('⚠️ `carry` مالوش أثر في أي نموذج (ما يتعدّش مرتين)') : bad('`carry` بقى ليه أثر');
// الفورم مش مصدر ثقة: قيمة غلط بتتعامل كـ`bank`
RepCustody::depositAffectsSafe('حاجة-غلط') === RepCustody::depositAffectsSafe('bank')
    ? ok('قيمة غلط بتقع على `bank` (نفس `cleanSource`)') : bad('قيمة غلط ليها سلوك تالت');

sec('② الشرط مكتوب من مصدر واحد — مفيش نسخة موازية');

$ctlRep = code_only(src('app/Controllers/RepCustodyController.php'));
$ctlCus = code_only(src('app/Controllers/CustodyController.php'));
$viewDay = code_only(src('app/Views/rep_custody/_tab_day.php'));

substr_count($ctlRep, 'depositAffectsSafe') >= 2
    ? ok('`RepCustodyController` بينادي `depositAffectsSafe` في الإنشاء والحذف')
    : bad('⚠️ الحذف أو الإنشاء بلا `depositAffectsSafe` — حذف من يوم متقفل بيغيّر رصيدًا مثبَّتًا');
preg_match("~\\\$source\s*===\s*'(office|bank|carry)'~", $ctlRep)
    ? bad('⚠️ شرط المصدر مكتوب بالإيد في `RepCustodyController` — لازم من `depositAffectsSafe`')
    : ok('و`RepCustodyController` مش بيكتب شرط المصدر بإيده');
str_contains($viewDay, 'RepCustody::sourceMatters()')
    ? ok('كارت المندوب بيقرا الظهور من `sourceMatters`') : bad('الظهور مكتوب بشرط تاني');
!str_contains($viewDay, 'newSafeRuleActive')
    ? ok('⚠️ ومش مشروط بـ`newSafeRuleActive` لوحده (ده كان الباج)')
    : note('لسه فيه `newSafeRuleActive` في الشاشة — اتأكد إنه للشرح مش للظهور');

// ⑤ صفحة الخزنة: المصدر مثبَّت في الكود
str_contains($ctlCus, "\$source = 'office'")
    ? ok('صفحة الخزنة بتثبّت `office` في الكنترولر') : bad('المصدر مش مثبَّت في الكنترولر');
!preg_match('~\$_POST\[.source.\]~', $ctlCus)
    ? ok('⚠️ وما بتقراش المصدر من الفورم إطلاقًا') : bad('⚠️ بتقرا المصدر من الفورم — ممكن تتسجّل `bank`');
!preg_match('~name="source"~', src('app/Views/custody/index.php'))
    ? ok('وفورم الخزنة مفهوش خانة مصدر') : bad('فورم الخزنة فيه خانة مصدر');
str_contains($ctlCus, 'activeReps()[$repId]')
    ? ok('والمندوب بيتفحص على المناديب النشطين (الفورم مش مصدر ثقة)')
    : bad('مفيش تحقّق من المندوب');
str_contains(src('public/index.php'), "post('/custody/rep-deposit'")
    ? ok('المسار `POST /custody/rep-deposit` متسجّل') : bad('المسار مش متسجّل');

// ───────────────────────────────────────────── ③→⑥ سلوكيًّا داخل transaction
/* ⚠️⚠️ **اللقطة بتقرا من `Closing::walletSplit()` مش بتجمع بإيدها** — و«الكاش كله»
   ليه تعريفان حسب النموذج: في «الخزنة = الدرج بس» الإجمالي = الرصيد + المناديب،
   وفي «الخزنة = كل كاش الشركة» الرصيد **هو** الإجمالي والدرج = الرصيد − المناديب.
   أول نسخة من الفاحص جمعت `currentBalance + أرصدة المناديب` في الحالتين، فعدّت
   جيوب المناديب **مرتين** في النموذج التاني وأدّت فشلًا كاذبًا في «الفلوس محفوظة».
   نفس مصيدة «تعريف تاني لنفس الرقم» الموثَّقة — اقرا من مصدر الشاشة نفسه. */
$snapshot = static function (): array {
    $w = Closing::walletSplit();
    return ['safe'   => round(Closing::currentBalance(), 2),
            'drawer' => round((float) $w['drawer'], 2),
            'reps'   => round((float) $w['reps_total'], 2),
            'total'  => round((float) $w['total'], 2)];
};

$pdo = Database::conn();
$pdo->beginTransaction();
try {
    if (!RepCustody::sourceReady()) {
        note('عمود `source` مش موجود — الأقسام ③→⑥ اتخطّت');
        throw new RuntimeException('__SKIP__');
    }
    $rid = (int) Database::scalar("SELECT id FROM reps WHERE status='active' ORDER BY id LIMIT 1");
    if (!$rid) { note('مفيش مندوب نشط — الأقسام ③→⑥ اتخطّت'); throw new RuntimeException('__SKIP__'); }

    sec('③ نموذج «الخزنة = كل كاش الشركة» — التنزيل من الدرج تحويل داخلي');

    // ده النموذج الفعّال على الإنتاج، وهو اللي كانت الخانة مخفية فيه
    $setModel('', EPOCH_D);
    $base = $snapshot();

    $depOff = RepCustody::createDeposit($rid, 3000, EPOCH_D, '__vrds_office', 'office');
    $s1 = $snapshot();
    near('تنزيل من الدرج: رقم الخزنة زي ما هو (الفلوس لسه مع الشركة)', $s1['safe'], $base['safe']);
    near('تنزيل من الدرج: الدرج −3000', $s1['drawer'] - $base['drawer'], -3000);
    near('تنزيل من الدرج: محفظة المندوب +3000', $s1['reps'] - $base['reps'], 3000);
    near('⚠️⚠️ الفلوس محفوظة — كل الكاش برّه البنك ثابت', $s1['total'], $base['total']);

    /* ⚠️ **ده الفحص اللي بيمسك الباج الأصلي**: نفس المبلغ لو اتسجّل `bank` (اللي
       كان بيحصل غصبًا وقت ما الخانة كانت مخفية) بيزوّد الخزنة — يعني كاش طلع من
       الدرج بيتسجّل على إنه فلوس جديدة داخلة، فيتعدّ مرتين. */
    $depBank = RepCustody::createDeposit($rid, 3000, EPOCH_D, '__vrds_bank', 'bank');
    $s2 = $snapshot();
    near('ونفس المبلغ من البنك: الخزنة +3000 (فلوس جديدة)', $s2['safe'] - $s1['safe'], 3000);
    near('ومن البنك: الدرج ما اتحركش (الكاش ما دخلهوش أصلاً)', $s2['drawer'], $s1['drawer']);
    near('ومن البنك: كل الكاش برّه البنك +3000', $s2['total'] - $s1['total'], 3000);
    abs(($s2['safe'] - $s1['safe']) - ($s1['safe'] - $base['safe'])) > 0.005
        ? ok('⚠️⚠️ الفرق بين المصدرين حقيقي — إخفاء الخانة كان بيعدّ الكاش مرتين')
        : bad('⚠️ المصدران بيدّوا نفس الأثر — الخانة بلا معنى، راجع `bankTopupSql`');

    RepCustody::deleteDeposit($depBank);
    RepCustody::deleteDeposit($depOff);
    near('وحذف التنزيلين بيرجّع كل رقم لمكانه', $snapshot()['total'], $base['total']);

    sec('④ قاعدة المرحلة ٢ — التنزيل من الدرج مصروف يوم تنزيله');

    $setModel(EPOCH_D, '');
    $b2 = $snapshot();
    $dep2 = RepCustody::createDeposit($rid, 2000, EPOCH_D, '__vrds_off2', 'office');
    $s3 = $snapshot();
    near('تنزيل من الدرج: الخزنة −2000', $s3['safe'] - $b2['safe'], -2000);
    near('والمحفظة +2000', $s3['reps'] - $b2['reps'], 2000);
    near('⚠️ والفلوس محفوظة برضه (بالتعريف التاني للإجمالي)', $s3['total'], $b2['total']);
    RepCustody::deleteDeposit($dep2);

    sec('⑤ حارس اليوم المتقفل بيتبع الأثر مش اسم المصدر');

    $locked = (string) (Closing::lastClosedDate() ?? '');
    if ($locked === '') {
        note('مفيش يوم متقفل — فحص الحارس اتخطّى');
    } else {
        // في نموذج «كل كاش الشركة»: `bank` بيغيّر الخزنة ⇒ لازم يترفض
        $setModel('', EPOCH_D);
        RepCustody::depositAffectsSafe('bank') && Closing::guard($locked) !== null
            ? ok("تنزيل «من البنك» على يوم متقفل ($locked) بيغيّر الخزنة ⇒ بيترفض")
            : bad('⚠️ تنزيل من البنك على يوم متقفل بيعدّي — بيعيد كتابة رصيد مثبَّت');
        // وفي نفس النموذج: `office` تحويل داخلي ⇒ يعدّي
        !RepCustody::depositAffectsSafe('office')
            ? ok('وتنزيل «من الدرج» في نفس النموذج تحويل داخلي ⇒ بيعدّي')
            : bad('التحويل الداخلي بيترفض بلا سبب');
        // وفي قاعدة المرحلة ٢ العكس بالظبط
        $setModel(EPOCH_D, '');
        RepCustody::depositAffectsSafe('office') && !RepCustody::depositAffectsSafe('bank')
            ? ok('وفي قاعدة المرحلة ٢ الاتجاه بينقلب (الدرج بيترفض والبنك بيعدّي)')
            : bad('الاتجاه ما اتقلبش مع النموذج');
    }

    sec('⑥ التقفيل اليومي — التنزيل من الدرج بيظهر ككارت في النموذجين');

    /* ⚠️ **الباج اللي القسم ده بيحرسه:** القسم كان مشروطًا بقاعدة المرحلة ٢،
       والإنتاج شغّال بالنموذج المجمّع ⇒ الكاش الخارج من الدرج كان **مختفي
       تمامًا** من شاشة التقفيل (⑤ج بتتخطّى `office` كمان). */
    $day6 = EPOCH_D;
    /** مجموع الأقسام النقدية الطالعة — لازم يساوي مصروفات `Closing` بالمليم (قاعدة v50). */
    $cashOut = static function (array $view): float {
        $s = 0.0;
        foreach (\App\Models\DailyReview::BLOCKING as $g) {
            if (($view['groups'][$g]['dir'] ?? '') === 'out') { $s += (float) $view['groups'][$g]['total']; }
        }
        return round($s, 2);
    };

    // أ) النموذج المجمّع (الفعّال) — معلوماتي، ما بيمنعش الإقفال، وبرّه النقدي
    $setModel('', EPOCH_D);
    /* ⚠️⚠️ **القياس على الفرق مش على رقم مطلق.** يوم الاختبار بقى مشتقًّا (أول
       يوم مفتوح) فممكن يكون فيه تنزيلات حقيقية — على الإنتاج لقينا ١٠,٠٠٠ من
       الدرج على نفس اليوم، فتوقّع «١٢٣٤ بالظبط» فشل بإنذار كاذب. الثابت اللي
       بنحرسه هو **أثر الصف المزروع**، لا مجموع اليوم. */
    $base6 = (float) (\App\Models\DailyReview::view($day6)['groups']['rep_drawer']['total'] ?? 0);
    /* ⚠️ أساس «المسحوب من الدرج» لازم يتقاس **بنفس النموذج** اللي هيتقارن فيه:
       `dayOfficeDeposits` بترجّع صفر في النموذج المجمّع (تحويل داخلي)، فأخذ
       الأساس هنا كان بيدّي فرقًا = مجموع اليوم كله بدل الصف المزروع. */
    $setModel(EPOCH_D, '');
    $baseOd6 = Closing::dayOfficeDeposits($day6);
    $setModel('', EPOCH_D);
    $d6 = RepCustody::createDeposit($rid, 1234, $day6, '__vrds_show', 'office');
    $v1 = \App\Models\DailyReview::view($day6);
    $cl1 = Closing::dayView($day6);
    ($v1['groups']['rep_drawer']['count'] ?? 0) > 0
        ? ok('كارت «نزلت للمناديب من الدرج» ظاهر في النموذج المجمّع')
        : bad('⚠️⚠️ الكارت مش ظاهر — الكاش الخارج من الدرج مختفي من التقفيل');
    near('ومبلغه صح (الفرق عن الأساس)',
         round((float) ($v1['groups']['rep_drawer']['total'] ?? 0) - $base6, 2), 1234);
    ($v1['groups']['rep_drawer']['kind'] ?? '') === 'info'
        ? ok('وكارت معلوماتي (تحويل داخلي — الإجمالي ما اتغيّرش)') : bad('اتصنّف نقدي بالغلط');
    !in_array('rep_drawer', \App\Models\DailyReview::BLOCKING, true)
        ? ok('⚠️ ومش مانع للإقفال') : bad('⚠️ مانع للإقفال — تحويل داخلي بيوقف التقفيل');
    near('⚠️⚠️ ومطابقة v50 سليمة: مجموع الأقسام النقدية = مصروفات `Closing`',
         $cashOut($v1), round((float) $cl1['expenses'] + (float) $cl1['driver'], 2));
    near('ورقمه له مفتاح مستقل في الإجماليات',
         round((float) ($v1['totals']['rep_drawer'] ?? -1) - $base6, 2), 1234);
    near('وما دخلش النقدي', (float) $v1['totals']['cash'], $cashOut($v1));

    // ب) قاعدة المرحلة ٢ — نفس الصف بيتحوّل لقسم نقدي مانع
    $setModel(EPOCH_D, '');
    $v2 = \App\Models\DailyReview::view($day6);
    $cl2 = Closing::dayView($day6);
    ($v2['groups']['rep_deposit']['count'] ?? 0) > 0
        ? ok('وفي قاعدة المرحلة ٢ نفس الصف بيتحوّل لقسم «من الدرج» النقدي')
        : bad('الصف اختفى في قاعدة المرحلة ٢');
    ($v2['groups']['rep_drawer']['count'] ?? 0) === 0
        ? ok('⚠️ ومش بيتعدّ مرتين (قسم واحد بس بياخده)') : bad('⚠️⚠️ الصف ظهر في القسمين — بيتعدّ مرتين');
    near('⚠️⚠️ ومطابقة v50 سليمة في النموذج ده كمان',
         $cashOut($v2), round((float) $cl2['expenses'] + (float) $cl2['driver'], 2));

    // ج) نوع البند ثابت — وإلا علامات المراجعة القديمة تتيتّم
    $keys1 = array_column($v1['groups']['rep_drawer']['items'] ?? [], 'key');
    $keys2 = array_column($v2['groups']['rep_deposit']['items'] ?? [], 'key');
    array_intersect($keys1, $keys2) === array_values(array_intersect($keys1, $keys2)) && $keys1 && $keys1 === $keys2
        ? ok('⚠️ ومفتاح البند واحد في النموذجين (علامات المراجعة ما تتيتّمش)')
        : bad('⚠️ المفتاح اتغيّر مع النموذج — كل علامة قديمة هتتيتّم');

    /* د) ⚠️⚠️ **نموذج «الخزنة = الدرج بس»** — الاختبار اللي بيحرس التحويل.
          هنا `Closing::sumTopups` بطّلت تحسب تنزيلات البنك، فلو فضلت في قسم
          «عهدة مضافة» النقدي يبقى القسم بيقول رقمًا والرصيد يقول رقمًا تاني. */
    $setModel(EPOCH_D, '');   // safeRule ON · bankTopup OFF
    /* ⚠️ لقطة **قبل** الزرع — الإنتاج فيه عهد بنك حقيقية على نفس اليوم، فتوقّع
       رقم مطلق بيدّي فشلًا كاذبًا. القياس على **الفرق** بيشتغل في أي بيانات. */
    $bankBase = (float) (\App\Models\DailyReview::view($day6)['groups']['topups']['total'] ?? 0);
    $dBank = RepCustody::createDeposit($rid, 2500, $day6, '__vrds_bnk2', 'bank');
    $v3 = \App\Models\DailyReview::view($day6);
    $cl3 = Closing::dayView($day6);
    /* ⚠️ **طلب المالك 2026-08-01**: العهدة من البنك لازم تبان في كارت «العهد
       المضافة» — مش في كارت منفصل. الكارت بيعرضها، والمطابقة بتفضل سليمة لأن
       المجموع المحسوب مشتقّ من `countedTopupTypes()`. */
    near('عهدة البنك ظاهرة في كارت «العهد المضافة»',
         round((float) $v3['groups']['topups']['total'] - $bankBase, 2), 2500);
    !isset($v3['groups']['rep_bank'])
        ? ok('⚠️ ومفيش كارت منفصل ليها (اتشال بطلب المالك)') : bad('لسه فيه كارت منفصل');
    /* ⚠️ الاسم ما ينفعش يقول «للخزنة» — الكارت فيه عهد بنك ما دخلتش الدرج،
       والمعادلة فوق اسمها «عهدة مضافة للدرج» فالاتنين كانوا هيتلخبطوا. */
    !str_contains((string) $v3['groups']['topups']['title'], 'للخزنة')
        ? ok('واسم الكارت ما بيدّعيش إن كلها دخلت الخزنة')
        : bad('⚠️ الاسم بيقول «للخزنة» والرقم فيه عهد بنك ما دخلتهاش');
    /* ⚠️⚠️ المجموع المحسوب **مشتقّ من الموديل مش مكتوب بالإيد** — لو اتكتب
       `['topup','rep_bank']` حرفيًّا كان بينجح في نموذج وبيفشل في التاني. */
    $counted = static function (array $v): float {
        $s = 0.0; $types = \App\Models\DailyReview::countedTopupTypes();
        foreach ($v['groups']['topups']['items'] as $it) {
            if (in_array($it['type'] ?? '', $types, true)) { $s += (float) $it['amount']; }
        }
        return round($s, 2);
    };
    near('⚠️⚠️ ومطابقة الكارت مع `Closing::sumTopups` بالمليم',
         $counted($v3), round((float) $cl3['topups'], 2));
    !in_array('rep_bank', \App\Models\DailyReview::countedTopupTypes(), true)
        ? ok('⚠️ وعهدة البنك مستثناة من المجموع المحسوب (عمرها ما دخلت الدرج)')
        : bad('⚠️⚠️ اتحسبت في الرصيد وهي ما دخلتش الدرج');
    near('وسطر التفكيك بيقول الجزء اللي دخل الدرج',
         (float) ($v3['totals']['topups_drawer'] ?? -1), $counted($v3));
    near('والباقي متنسوب للمناديب',
         round((float) ($v3['totals']['rep_bank'] ?? 0), 2),
         round((float) $v3['groups']['topups']['total'] - $counted($v3)
               - (float) ($v3['totals']['rep_carry'] ?? 0), 2));
    // وفي النموذج التاني البنك بيتحسب فعلاً — نفس الدالة بترجّع قايمة مختلفة
    $setModel('', EPOCH_D);
    in_array('rep_bank', \App\Models\DailyReview::countedTopupTypes(), true)
        ? ok('وفي النموذج المجمّع بتترجع للمجموع المحسوب تلقائيًّا')
        : bad('فضلت مستثناة — الدالة مش بتتبع النموذج');
    $v4 = \App\Models\DailyReview::view($day6);
    near('⚠️⚠️ والمطابقة سليمة هناك كمان', $counted($v4),
         round((float) Closing::dayView($day6)['topups'], 2));
    $setModel(EPOCH_D, '');
    RepCustody::deleteDeposit($dBank);

    /* هـ) ⚠️⚠️ **المعادلة لازم تقفل** بعد ما «عهدة مسحوبة من الدرج» بقت خانة
          مستقلة (طلب المالك 2026-08-01). هي **جزء من `expenses`** مش رقم زيادة،
          فالشاشة بتطرحها من خانة المصروفات — ولو الطرح اتنسي بتتحسب مرتين
          والمرحّل يطلع غلط **بلا أي خطأ ظاهر**. */
    $setModel(EPOCH_D, '');
    $clE = Closing::dayView($day6);
    $od  = Closing::dayOfficeDeposits($day6);
    near('«عهدة مسحوبة من الدرج» بتزيد ببند اليوم من الدرج',
         round($od - $baseOd6, 2), 1234);
    $expCash = round((float) $clE['expenses'] - $od, 2);
    near('⚠️⚠️ المعادلة بتقفل: أول اليوم + مضاف − مسحوب − مصروفات − سواقين = المرحّل',
         round((float) $clE['opening'] + (float) $clE['topups'] - $od - $expCash - (float) $clE['driver'], 2),
         round((float) $clE['closing'], 2));
    $od <= (float) $clE['expenses'] + 0.005
        ? ok('والمسحوب جزء من المصروفات مش رقم زيادة') : bad('⚠️ أكبر من المصروفات — بيتجمع مرتين');
    /* ⚠️⚠️ **تفكيك الإضافات لخانتين** (طلب المالك 2026-08-01): «عهدة مضافة للدرج»
       + «عهدة منزلة للمناديب» لازم مجموعهم = `topups` بالظبط، وإلا المعادلة
       بتتكسر أو رقم بيتعدّ مرتين. */
    near('تفكيك الإضافات: للدرج + للمناديب = `topups`',
         round(Closing::dayBankDeposits($day6) + ((float) $clE['topups'] - Closing::dayBankDeposits($day6)), 2),
         round((float) $clE['topups'], 2));
    // وفي النموذج المجمّع الاتجاه بينقلب: البنك بيتحسب والدرج بيبقى تحويل داخلي
    $setModel('', EPOCH_D);
    /* ⚠️ **الزرع إلزامي** — الفحص الأول كان بيقرا اللي في القاعدة، وبند الاختبار
       كان اتمسح فوق فطلع صفر و«فشل» بإنذار كاذب. أي فحص على وجود رقم لازم يزرعه. */
    $dBank2 = RepCustody::createDeposit($rid, 700, $day6, '__vrds_bnk3', 'bank');
    $clF = Closing::dayView($day6);
    $bank = Closing::dayBankDeposits($day6);
    $drawerOut = Closing::dayDrawerToReps($day6);
    $bank >= 700 ? ok('في النموذج المجمّع عهدة البنك بتتحسب في `topups`') : bad('مش بتتحسب');
    near('⚠️⚠️ والمعادلة بتقفل: أول اليوم + للدرج + للمناديب − مصروفات − سواقين = المرحّل',
         round((float) $clF['opening'] + (float) $clF['topups']
               - (float) $clF['expenses'] - (float) $clF['driver'], 2),
         round((float) $clF['closing'], 2));
    /* ⚠️ الرقم التاني (اللي نزل من الدرج) **بيتعرض ولا يتطرح** — لو اتطرح
       المعادلة تتكسر لأن الدرج وجيوب المناديب الاتنين جوّه الرقم. */
    Closing::dayOfficeDeposits($day6) === 0.0 && $drawerOut > 0
        ? ok('⚠️ واللي نزل من الدرج بيتعرض كتحويل داخلي بلا طرح')
        : bad('⚠️⚠️ بيتطرح في النموذج المجمّع — المعادلة هتتكسر');
    RepCustody::deleteDeposit($dBank2);
    $setModel(EPOCH_D, '');
    // ومطابقة قسم المراجعة مع خانة المعادلة الجديدة
    $v5 = \App\Models\DailyReview::view($day6);
    near('وقسم «عهدة نزلت للمناديب من الدرج» = خانة المعادلة',
         round((float) ($v5['groups']['rep_deposit']['total'] ?? 0), 2), $od);
    // وفي النموذج المجمّع الخانة بتختفي لأن مفيش سحب من الدرج بيتخصم
    $setModel('', EPOCH_D);
    Closing::dayOfficeDeposits($day6) === 0.0
        ? ok('وفي النموذج المجمّع بترجّع صفر (الخانة بتختفي — تحويل داخلي)')
        : bad('بترجّع رقم في نموذج مالوش فيه أثر');
    $setModel(EPOCH_D, '');

    RepCustody::deleteDeposit($d6);

    sec('⑦ الرندر — الخانة والفورم والكارت ظاهرين فعليًّا');

    $setModel('', EPOCH_D);   // النموذج الفعّال على الإنتاج
    $sess = $_SESSION ?? [];
    $_SESSION = ['uid' => (int) Database::scalar('SELECT MIN(id) FROM users'), 'role' => 'owner',
                 'uname' => 'فحص عهدة الخزنة', 'last_activity' => time(), 'uid_checked' => time(),
                 'work_date' => EPOCH_D, 'work_date_set' => date('Y-m-d')];

    // أ) كارت المندوب — تبويب اليوم
    $_GET['tab'] = 'day';
    ob_start();
    try { (new \App\Controllers\RepCustodyController())->card((string) $rid); $html = (string) ob_get_clean(); }
    catch (\Throwable $e) { ob_end_clean(); $html = ''; bad('كارت المندوب رمى استثناء: ' . $e->getMessage()); }
    unset($_GET['tab']);
    if ($html !== '') {
        preg_match('/Fatal error|Warning:|Undefined variable|Undefined array key/', $html, $m1)
            ? bad('كارت المندوب فيه خطأ PHP: ' . ($m1[0] ?? '')) : ok('كارت المندوب بيرندر بلا أخطاء PHP');
        /* ⚠️ **ده الفحص اللي بيمنع رجوع الباج**: الخانة لازم تظهر في نموذج
           «الخزنة = كل كاش الشركة» كمان، مش في قاعدة المرحلة ٢ بس. */
        str_contains($html, 'name="source"') && str_contains($html, 'الفلوس جاية منين؟')
            ? ok('⚠️⚠️ خانة «الفلوس جاية منين؟» ظاهرة في نموذج «كل كاش الشركة»')
            : bad('⚠️⚠️ الخانة مخفية — كل تنزيل هيتسجّل `bank` والكاش يتعدّ مرتين');
        str_contains($html, 'من درج المكتب')
            ? ok('وخيار «من درج المكتب» موجود') : bad('خيار الدرج مش موجود');
    }

    // ب) صفحة الخزنة
    ob_start();
    try { (new \App\Controllers\CustodyController())->index(); $html2 = (string) ob_get_clean(); }
    catch (\Throwable $e) { ob_end_clean(); $html2 = ''; bad('صفحة الخزنة رمت استثناء: ' . $e->getMessage()); }
    $_SESSION = $sess;
    if ($html2 !== '') {
        preg_match('/Fatal error|Warning:|Undefined variable|Undefined array key/', $html2, $m2)
            ? bad('صفحة الخزنة فيها خطأ PHP: ' . ($m2[0] ?? '')) : ok('صفحة الخزنة بترندر بلا أخطاء PHP');
        str_contains($html2, 'id="rep-deposit"') && str_contains($html2, 'نزّل عهدة لمندوب من الدرج')
            ? ok('وفورم «نزّل عهدة لمندوب من الدرج» ظاهر فيها') : bad('الفورم مش ظاهر في صفحة الخزنة');
        str_contains($html2, 'name="rep_id"')
            ? ok('وفيه اختيار المندوب') : bad('مفيش اختيار مندوب');
        !str_contains($html2, 'name="source"')
            ? ok('⚠️ ومفيهوش خانة مصدر — الشاشة معناها «من الدرج» وبس') : bad('فيه خانة مصدر');
    }

    /* ج) شاشة التقفيل — الكارت لازم **يترندر فعليًّا**، مش يكفي إنه في `view()`.
          قسم بلا عنوان أو بأيقونة ناقصة بيقع على شكل افتراضي **بالسكوت** (نفس
          مصيدة `.table-mobile-scroll` و`icon('archive')` الموثَّقتين). */
    $dep7 = RepCustody::createDeposit($rid, 1234, EPOCH_D, '__vrds_render', 'office');
    $_SESSION = ['uid' => (int) Database::scalar('SELECT MIN(id) FROM users'), 'role' => 'owner',
                 'uname' => 'فحص عهدة الخزنة', 'last_activity' => time(), 'uid_checked' => time(),
                 'work_date' => EPOCH_D, 'work_date_set' => date('Y-m-d')];
    $_GET['date'] = EPOCH_D;   // ⚠️ `date` مش `d` — `requestedDate` بتقرا `date`
    ob_start();
    try { (new \App\Controllers\DailyClosingController())->index(); $html3 = (string) ob_get_clean(); }
    catch (\Throwable $e) { ob_end_clean(); $html3 = ''; bad('شاشة التقفيل رمت استثناء: ' . $e->getMessage()); }
    unset($_GET['date']);
    $_SESSION = $sess;
    if ($html3 !== '') {
        preg_match('/Fatal error|Warning:|Undefined variable|Undefined array key/', $html3, $m3)
            ? bad('شاشة التقفيل فيها خطأ PHP: ' . ($m3[0] ?? '')) : ok('شاشة التقفيل بترندر بلا أخطاء PHP');
        str_contains($html3, 'id="rvg-rep_drawer"') && str_contains($html3, 'نزلت للمناديب من الدرج')
            ? ok('⚠️⚠️ كارت «نزلت للمناديب من الدرج» ظاهر في شاشة التقفيل')
            : bad('⚠️⚠️ الكارت مش ظاهر في الشاشة');
        str_contains($html3, '1,234')
            ? ok('والمبلغ ظاهر فيه (مش كارت فاضي)') : bad('المبلغ مش ظاهر');
        /* ⚠️ الوسم كله لازم يتقصّ — `class` بتتكتب **قبل** `id` في القالب، فبحث
           بيبدأ من `id="rvg-…"` بيفوّت `is-info` ويدّي فشلًا كاذبًا. */
        preg_match('~<section[^>]*id="rvg-rep_drawer"[^>]*>~', $html3, $mc)
            && str_contains($mc[0] ?? '', 'is-info')
            ? ok('والكارت متعلّم معلوماتي في الشاشة') : bad('الكارت مش متعلّم معلوماتي');
    }
    RepCustody::deleteDeposit($dep7);

    throw new RuntimeException('__DONE__');
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    if (!in_array($e->getMessage(), ['__DONE__', '__SKIP__'], true)) {
        bad('استثناء: ' . $e->getMessage());
    }
}

// ⚠️ `App::setSetting` بتكاش في الذاكرة فما بترجعش مع الـrollback — لازم ترجيع صريح
App::setSetting('rep_safe_rule_from', $savedRule); RepCustody::refreshSafeRule();
App::setSetting('rep_bank_topup_from', $savedBank); RepCustody::refreshBankTopup();
$okRestore = RepCustody::safeRuleFrom() === $savedRule && RepCustody::bankTopupFrom() === $savedBank;
$okRestore ? ok('الإعدادات رجعت لحالتها الأصلية') : bad('⚠️ الإعدادات ما رجعتش — راجعها يدويًّا');

echo "\n" . str_repeat('═', 70) . "\n";
foreach ($notes as $n) { echo "  … $n\n"; }
echo "النتيجة: $pass ناجح / $fail فاشل\n";
exit($fail > 0 ? 1 : 0);
