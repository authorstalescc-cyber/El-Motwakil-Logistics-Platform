<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص وحدة رواتب المناديب (v87).
 *
 * أهم ٨ حاجات بيتأكد منها:
 *   ① **مفيش تحسيب مزدوج**: ولا نوع من `Payroll::ENTRY_KINDS` في
 *      `Rep::CASH_KINDS` — وده الحارس الوحيد اللي بيمنع السلفة تتخصم من
 *      الخزنة مرتين. وبعد الاعتماد: **رصيد الخزنة والمصروفات ما اتغيّروش**.
 *   ② **القسمة ما بتضيّعش ولا قرش**: Σ الحصص = الحوض بالظبط عند أي عدد.
 *   ③ **المصدر الوحيد**: الـ١٠٠ بتيجي من `Custody::retained` والمعدّل من
 *      `Settings::valueOn`، ومفيش رقم مكتوب بالإيد في الموديل.
 *   ④ **الاستثناء بيشتغل**: جامبو/تانك بأي تهجئة برّه الحوضين، والعادي جوّه.
 *   ⑤ **الحُرّاس في الموديل**: اعتماد الفترة مرتين مرفوض، والنطاق المقلوب
 *      مرفوض، وإلغاء فترة بنودها اتحاسبت في تقفيل مرفوض.
 *   ⑥ **اللقطة ثابتة**: تغيير المعدّل بعد الاعتماد ما بيغيّرش أرقام الفترة.
 *   ⑦ **الكارت = الكشف**: `RepAccount::summary` بيقرا نفس حصة `Payroll`.
 *   ⑧ **المسارات والواجهة**: الحرفي قبل الجشع، وكل كلاس له CSS، وكل أيقونة
 *      موجودة في `icon()`.
 *
 * كله جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_payroll.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\Settings;
use App\Core\SettingsSchema;
use App\Models\Closing;
use App\Models\Custody;
use App\Models\Payroll;
use App\Models\Rep;
use App\Models\RepAccount;
use App\Models\RepMiscKind;

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function chk(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function sec(string $m): void { echo "\n$m\n" . str_repeat('─', 62) . "\n"; }
function near(string $l, $a, $e): void {
    abs((float) $a - (float) $e) <= 0.005 ? ok($l)
        : bad("{$l} — متوقع " . number_format((float) $e, 2) . " / فعلي " . number_format((float) $a, 2));
}
/** ⚠️ كود بلا تعليقات — التعليق اللي بيوثّق القاعدة بيذكر النص الممنوع. */
function codeP(string $f): string {
    $p = dirname(__DIR__) . '/' . $f;
    if (!is_file($p)) { return ''; }
    $out = '';
    foreach (token_get_all((string) file_get_contents($p)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}
function srcP(string $f): string {
    $p = dirname(__DIR__) . '/' . $f;
    return is_file($p) ? (string) file_get_contents($p) : '';
}

echo "فحص رواتب المناديب (v87)\n" . str_repeat('=', 62) . "\n";

// ═══════════════════════════════ ① حارس التحسيب المزدوج (بلا قاعدة بيانات)
sec('① الحارس ضد التحسيب المزدوج — أخطر سطر في الوحدة');

foreach (Payroll::ENTRY_KINDS as $slot => $kind) {
    chk(!in_array($kind, Rep::CASH_KINDS, true),
        "★★ بند «{$slot}» بيتسجّل «{$kind}» — مش من أنواع الكاش");
    chk(isset(Rep::KINDS[$kind]), "ونوع «{$kind}» معروف في سجل بنود المندوب");
}
chk(Payroll::ENTRY_KINDS['advances'] !== 'advance',
    '★★ السلفة ما بتتسجّلش «advance» (كانت هتخرج من الخزنة تاني)');

$model = codeP('app/Models/Payroll.php');
chk(str_contains($model, 'Custody::retained'),
    '★ الـ١٠٠ بتيجي من Custody::retained (المصدر الوحيد) مش محسوبة هنا');
/* ⚠️ تحويل الجنيه لقروش (`* 100` و`/ 100`) **مش معدّلًا** — هو حساب القسمة
   بلا فقد قرش. بيتشال قبل الفحص وإلا الفاحص بيفشل بإنذار كاذب على كود سليم. */
$noCents = preg_replace('~[*/]\s*100\b~', '', $model);
chk(!preg_match('~\b(100|150)\b~', $noCents),
    '★ ومفيش معدّل ١٠٠ ولا ١٥٠ مكتوب بالإيد في الموديل');
chk(str_contains($model, 'Settings::valueOn'),
    '★ والمعدّل بيتقرا مؤرَّخًا (valueOn) مش السارى النهارده');
chk(str_contains($model, 'container_norm'),
    'وتوحيد نوع الحاوية بيمرّ على container_norm');
chk(str_contains($model, 'RepMiscKind::salaryKeys'),
    'وأنواع السلف بتتجاب من الكتالوج لا مكتوبة');

/* ⚠️ الكتابة في `rep_entries` بتاعة الرواتب لازم تفضل في مكان **واحد**: لو
   كنترولر أو شاشة كتبت بند بنفسها، القاعدة اللي فوق بتتخطّى بالسكوت. */
foreach (glob(dirname(__DIR__) . '/app/Controllers/*.php') ?: [] as $f) {
    $c = codeP('app/Controllers/' . basename($f));
    chk(!str_contains($c, 'payroll_period_id'),
        '★ ' . basename($f) . ' ما بيكتبش بند رواتب بنفسه');
}

// ═══════════════════════════════ ② القسمة بالتساوي
sec('② القسمة ما بتضيّعش ولا قرش');

foreach ([[1000.0, 3], [100.0, 7], [0.03, 4], [12345.67, 5], [1.0, 3], [0.0, 4]] as [$tot, $n]) {
    $sh = Payroll::split($tot, $n);
    chk(count($sh) === $n, "قسمة {$tot} على {$n} بتدّي {$n} حصص");
    near("★ ومجموعها = {$tot} بالظبط", array_sum($sh), $tot);
}
$neg = Payroll::split(-100.0, 3);
near('★ والسالب بيتقسم سالبًا بلا فقد قرش', array_sum($neg), -100.0);
chk(Payroll::split(50.0, 0) === [], 'والقسمة على صفر بترجّع فاضي بدل ما ترمي خطأ');

$sh = Payroll::split(1000.0, 3);
chk(abs(max($sh) - min($sh)) <= 0.011, 'والفرق بين أكبر وأصغر حصة قرش واحد بالكتير');

// ═══════════════════════════════ ③ المحتجز من البوليصة
sec('③ المحتجز من البوليصة = العهدة − المخصوم (دايمًا)');

foreach ([[500.0, 100.0], [60.0, 60.0], [0.0, 0.0], [100.0, 100.0]] as [$cus, $expect]) {
    $ded = Custody::billDeduction($cus, 'ﻻ-عربية-معفاة-خالص');
    $ret = Custody::retained($cus, 'ﻻ-عربية-معفاة-خالص');
    near("عهدة {$cus}: المحتجز + المخصوم = العهدة", $ded + $ret, $cus);
}

$exempt = array_key_first(Custody::exemptCars());
if ($exempt !== null) {
    $raw = (string) \App\Core\App::setting('custody_deduction_exempt_cars', '');
    $one = trim(explode("\n", str_replace(',', "\n", $raw))[0] ?? '');
    if ($one !== '') {
        near('★ السيارة المعفاة ما بتحتجزش (بوليصتها بلا كومسيون)',
            Custody::retained(500.0, $one), 0.0);
    }
}

// ═══════════════════════════════ ④ استثناء نوع الشحنة
sec('④ الشحنات اللي برّه الحساب');

foreach (['جامبو', '1*جامبو', 'تانك', '1*20 تنك', 'جامبو 40'] as $t) {
    chk(Payroll::isExcludedType($t), "«{$t}» برّه حساب المناديب");
}
foreach (['40 قدم', '2*20', 'مبرّد', ''] as $t) {
    chk(!Payroll::isExcludedType($t), "و«{$t}» جوّه الحساب");
}

/* ⚠️ الأنواع اللي بتتخصم من الراتب (السلفة) **ممنوع** تدخل حوض المصروف —
   كانت هتتخصم مرتين: من راتبه ومن الحوض. */
foreach (Payroll::allowedKinds() as $k) {
    chk(!RepMiscKind::deductsSalary($k), "نوع «{$k}» في الحوض ومش بيتخصم من الراتب");
}
chk(RepMiscKind::has('printing') && !RepMiscKind::deductsSalary('printing'),
    '★ «تصوير وطباعة» نوع مستقل ومصروف شركة');

// ═══════════════════════════════ ⑤ الإعدادات
sec('⑤ الإعدادات معرَّفة والمعدّل مؤرَّخ');

foreach (Payroll::SETTINGS as $key) {
    chk(SettingsSchema::has($key), "الإعداد «{$key}» معرَّف في السجل");
}
chk(in_array(Payroll::SETTING_EXPENSE_RATE, Settings::DATED_KEYS, true),
    '★★ معدّل مصروف البوليصة مؤرَّخ (تغييره ما بيعيدش حساب الماضي)');
chk(in_array(Payroll::SETTING_COMMISSION_RATE, Settings::DATED_KEYS, true),
    'ومعدّل الكومسيون مؤرَّخ كمان');
chk(Payroll::SETTING_COMMISSION_RATE === 'custody_bill_deduction',
    '★ ومعدّل الكومسيون **هو نفسه** المخصوم من البوليصة (مش إعداد تاني)');

// ═══════════════════════════════ ⑥ المسارات والواجهة
sec('⑥ المسارات الحرفية قبل الجشعة، والواجهة بلا كلاس ميت');

$routes = srcP('public/index.php');
$posApprove = strpos($routes, "/payroll/approve");
$posGreedy  = strpos($routes, "/payroll/{id}/unapprove");
chk($posApprove !== false && $posGreedy !== false && $posApprove < $posGreedy,
    '★ /payroll/approve قبل /payroll/{id}/unapprove');
$posDrill = strpos($routes, "/payroll/drill/{what}");
chk($posDrill !== false && $posDrill < $posGreedy, 'و/payroll/drill/{what} قبل الجشع');

/* ⚠️ الكلاس اللي بيتطبع بلا CSS بيقع على الشكل الافتراضي **بالسكوت**. */
$css  = srcP('public/assets/css/app.css');
$view = srcP('app/Views/payroll/index.php');
foreach (['num-btn', 'inline-form', 'grow200', 'section-head', 'toolbar', 'chip'] as $cls) {
    chk(str_contains($view, $cls) === false || str_contains($css, '.' . $cls),
        "الكلاس «{$cls}» له CSS");
}

/* ⚠️ **صفر `<style>` محلي أو `style=`** (قانون التصميم بند ٧).
   ⚠️ على **الكود بلا تعليقات**: التعليق اللي بيوثّق القاعدة نفسه بيذكر النص
      الممنوع، فالفحص على النص الخام بيفشل كذبًا (المصيدة الموثَّقة — وقعت هنا). */
foreach (['app/Views/payroll/index.php', 'app/Views/payroll/_drill.php'] as $v) {
    $s = codeP($v);
    chk(!str_contains($s, '<style'), basename($v) . ' بلا <style> محلي');
    chk(!preg_match('~\sstyle=~', $s), basename($v) . ' بلا style= inline');
}

/* ⚠️ `icon('اسم-غلط')` ما بترميش خطأ — بترسم دايرة فاضية. */
$icons = srcP('app/Core/helpers.php');
foreach (['app/Views/payroll/index.php', 'app/Views/payroll/_drill.php', 'app/Views/m/account.php'] as $v) {
    preg_match_all("~icon\('([a-z0-9-]+)'~", srcP($v), $m);
    foreach (array_unique($m[1]) as $ic) {
        chk(str_contains($icons, "'{$ic}'" . ' ') || preg_match("~'" . preg_quote($ic, '~') . "'\s*=>~", $icons),
            "الأيقونة «{$ic}» موجودة (" . basename($v) . ')');
    }
}

// ═══════════════════════════════ ⑦ السلوك على القاعدة (جوّه transaction)
if (!Payroll::available()) {
    echo "\n(جداول الرواتب لسه ما اترحّلتش — فحص السلوك اتخطّى. شغّل bin/migrate_v87.php)\n";
} else {
    $treasuryBefore = Closing::currentBalance();

    $pdo = Database::conn();
    $pdo->beginTransaction();
    try {
        sec('⑦ الاعتماد: لقطة ثابتة + بنود استحقاق بلا مساس بالخزنة');

        // مندوبان جديدان — الحوض بيتقسم عليهم مع الموجودين
        Database::exec("INSERT INTO reps (name, monthly_salary, status) VALUES ('__pr_a', 8000, 'active')");
        $repA = Database::lastId();
        Database::exec("INSERT INTO reps (name, monthly_salary, status) VALUES ('__pr_b', 6000, 'active')");
        $repB = Database::lastId();

        $period = RepAccount::period();
        $calc   = Payroll::compute($period);

        chk($calc['reps_count'] >= 2, 'المندوبان الجديدان دخلوا القسمة');

        $inLines = array_values(array_filter($calc['lines'], static fn ($l) => $l['included']));
        near('★★ Σ حصص الكومسيون = الحوض بالظبط',
            array_sum(array_column($inLines, 'commission_share')), $calc['commission_pool']);
        $expSum = array_sum(array_column($inLines, 'expense_share'));
        near('★★ Σ حصص المصروف = الباقي المقسوم بالظبط',
            $expSum, $calc['deficit'] > 0 ? 0.0 : $calc['expense_remainder']);

        foreach ($calc['lines'] as $l) {
            near("صافي «{$l['rep_name']}» = ثابت − سلف + الحصتين", $l['net'],
                $l['base_salary'] - $l['advances'] + $l['commission_share'] + $l['expense_share']);
        }

        // ─── الاعتماد
        $pid = Payroll::approve($period);
        chk($pid > 0, 'الفترة اتعتمدت');

        $entA = Database::all('SELECT kind, amount FROM rep_entries WHERE payroll_period_id = ? AND rep_id = ?',
            [$pid, $repA]);
        chk(count($entA) > 0, 'واتسجّلت بنود في سجل المندوب');
        foreach ($entA as $e) {
            chk(!Rep::isCashKind((string) $e['kind']),
                "★★ البند «{$e['kind']}» مش كاش — الخزنة ما اتلمستش");
        }

        $lineA = Database::row('SELECT * FROM payroll_lines WHERE period_id = ? AND rep_id = ?', [$pid, $repA]);
        chk($lineA !== null, 'وسطر اللقطة اتخزّن');
        near('★ رصيد المندوب في سجله = صافي اللقطة',
            Rep::balance($repA), (float) $lineA['net']);

        near('★★ رصيد الخزنة ما اتغيّرش بعد الاعتماد',
            Closing::currentBalance(), $treasuryBefore);

        // ─── الحُرّاس
        $twice = false;
        try { Payroll::approve($period); } catch (\RuntimeException $e) { $twice = true; }
        chk($twice, '★ اعتماد نفس الفترة مرتين مرفوض');

        $bad = false;
        try { Payroll::approve(['from' => '2026-09-30', 'to' => '2026-09-01']); }
        catch (\RuntimeException $e) { $bad = true; }
        chk($bad, 'والنطاق المقلوب مرفوض');

        // ─── اللقطة ثابتة: غيّر المعدّل وشوف
        $saved = Payroll::findPeriod($period['from'], $period['to']);
        $poolBefore = (float) $saved['commission_pool'];
        Settings::set(Payroll::SETTING_EXPENSE_RATE, '999');
        $saved2 = Payroll::findPeriod($period['from'], $period['to']);
        near('★★ اللقطة ما اتغيّرتش بعد تغيير المعدّل',
            (float) $saved2['commission_pool'], $poolBefore);

        // ─── الكارت = الكشف
        $share = Payroll::repShare($repA, $period);
        chk($share['approved'], 'حصة المندوب بتتقرا من اللقطة بعد الاعتماد');
        near('★★ شاشة «حسابي» بتقرا نفس حصة الكومسيون',
            RepAccount::summary($repA, $period)['commission'], (float) $lineA['commission_share']);

        // ─── إلغاء الاعتماد بيشيل كل حاجة
        Payroll::unapprove($pid);
        chk(Payroll::findPeriod($period['from'], $period['to']) === null, 'إلغاء الاعتماد شال الفترة');
        chk((int) Database::scalar('SELECT COUNT(*) FROM rep_entries WHERE payroll_period_id = ?', [$pid]) === 0,
            '★ وشال بنودها من سجل المناديب');
        chk((int) Database::scalar('SELECT COUNT(*) FROM payroll_lines WHERE period_id = ?', [$pid]) === 0,
            'وشال سطور اللقطة');

        // ─── الإلغاء مرفوض لو البند اتحاسب في تقفيل
        $pid2 = Payroll::approve($period);
        /* ⚠️ `settlement_id` عليه **FK حقيقي** (`fk_repentry_settlement`) — رقم
           مخترع بيترفض من القاعدة نفسها، فالتقفيل لازم يتعمل صف حقيقي. */
        Database::exec(
            "INSERT INTO rep_settlements (rep_id, period_from, period_to, net, paid_amount, pay_date)
             VALUES (?,?,?,0,0,?)", [$repA, $period['from'], $period['to'], $period['to']]);
        $sid = Database::lastId();
        Database::exec(
            'UPDATE rep_entries SET settlement_id = ? WHERE payroll_period_id = ? LIMIT 1', [$sid, $pid2]);
        $locked = false;
        try { Payroll::unapprove($pid2); } catch (\RuntimeException $e) { $locked = true; }
        chk($locked, '★★ وإلغاء فترة بنودها اتحاسبت في تقفيل مرفوض');

        near('★★ ورصيد الخزنة لسه هو هو في آخر الفحص',
            Closing::currentBalance(), $treasuryBefore);

    } finally {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
    }
}

echo "\n" . str_repeat('=', 62) . "\n";
echo "ناجح: $pass   ·   فاشل: $fail\n";
exit($fail > 0 ? 1 : 0);
