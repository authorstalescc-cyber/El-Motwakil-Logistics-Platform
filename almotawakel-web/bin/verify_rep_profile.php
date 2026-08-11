<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص بروفايل المندوب والبنود اليدوية (v88).
 *
 * أهم ٨ حاجات بيتأكد منها:
 *   ① **`RepProfile` قراءة بحتة** — ولا `INSERT/UPDATE/DELETE`، ورصيد الخزنة
 *      قبل = بعد رغم فتح التبويبات الخمسة ورندرهم.
 *   ② **البند اليدوي بيغيّر الصافي بمقداره بالظبط** — مكافأة بتزوّد، وخصم
 *      وجزاء بيقلّلوا، والـ٣ بيدخلوا `payroll_lines` وقت الاعتماد.
 *   ③ **الجزاء وسم مش معاملة**: بيقلّل المستحق، **ومش كاش**، وما بيمسّش خزنة.
 *   ④ **حارس الفترة المعتمدة**: إضافة/حذف بند جوّه فترة اتعتمدت **مرفوضة**،
 *      وبند النظام ولّده مايتحذفش بالإيد.
 *   ⑤ **الكاش برّه التعديلات** — السلفة والتسوية ليهم قناتهم، وحسابهم هنا
 *      كمان = تحسيب مزدوج.
 *   ⑥ **الكارت = الكشف**: `RepProfile::salary` = `RepAccount::summary` بالحرف.
 *   ⑦ **التبويبات**: القيمة المجهولة بتقع على الأول، وكل تبويب له ملف.
 *   ⑧ **الواجهة**: صفر `<style>` محلي، وكل كلاس له CSS، وكل أيقونة موجودة.
 *
 * كله جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_rep_profile.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;
use App\Models\Payroll;
use App\Models\Rep;
use App\Models\RepAccount;
use App\Models\RepProfile;

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
function codeR(string $f): string {
    $p = dirname(__DIR__) . '/' . $f;
    if (!is_file($p)) { return ''; }
    $out = '';
    foreach (token_get_all((string) file_get_contents($p)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}
function srcR(string $f): string {
    $p = dirname(__DIR__) . '/' . $f;
    return is_file($p) ? (string) file_get_contents($p) : '';
}

echo "فحص بروفايل المندوب (v88)\n" . str_repeat('=', 62) . "\n";

// ═══════════════════════════════ ① قراءة بحتة
sec('① RepProfile عدسة قراءة — ولا كلمة كتابة');

$model = codeR('app/Models/RepProfile.php');
chk($model !== '', 'ملف RepProfile موجود');
foreach (['INSERT', 'UPDATE ', 'DELETE', 'ALTER', 'REPLACE', 'TRUNCATE'] as $w) {
    chk(!preg_match('~\b' . $w . '\b~i', $model), "مفهوش «{$w}»");
}
chk(!str_contains($model, 'Settings::set') && !str_contains($model, 'setSetting'),
    'وما بيكتبش أي إعداد');

/* ⚠️ كل رقم لازم ييجي من **الموديل صاحبه** لا باستعلام موازٍ — تعريفان
   بيختلفوا = «الكارت يقول رقم والكشف يقول تاني». */
foreach (['RepAccount::summary', 'RepCustody::balance', 'Rep::summary', 'Payroll::'] as $src) {
    chk(str_contains($model, $src), "★ بيقرا من {$src}");
}

// ═══════════════════════════════ ② الجزاء وسم مش معاملة
sec('② «جزاء» — بيقلّل المستحق ومش بيمسّ خزنة');

chk(isset(Rep::KINDS['penalty']), 'النوع متسجّل في الكتالوج');
chk(!in_array('penalty', Rep::CREDIT_KINDS, true), '★ ومش من المستحقات (مايزوّدش رصيده)');
chk(in_array('penalty', Rep::DEBIT_KINDS, true), 'ومن الخصومات');
chk(!in_array('penalty', Rep::CASH_KINDS, true), '★★ ومش كاش — ما بيتخصمش من الخزنة');
chk(Rep::sign('penalty') === -1, 'وإشارته سالبة في الرصيد');
chk(!str_contains(Rep::expenseUnionSql(), 'penalty'), '★ وما بيدخلش المصروفات العامة');

// ═══════════════════════════════ ③ التعديلات اليدوية
sec('③ البنود اللي بتدخل حساب الفترة');

$adj = Payroll::adjustKinds();
chk(in_array('penalty', $adj, true), '«جزاء» داخل حساب الفترة');
chk(in_array('bonus', $adj, true), 'و«مكافأة» كمان');
foreach (Rep::CASH_KINDS as $c) {
    chk(!in_array($c, $adj, true), "★★ ونوع الكاش «{$c}» برّه (منعًا للتحسيب المزدوج)");
}
/* ⚠️ محسوبة من `Rep::KINDS` لا مكتوبة قايمة: أي نوع جديد بيدخل تلقائيًّا. */
$pay = codeR('app/Models/Payroll.php');
chk(str_contains($pay, 'array_diff'), '★ والقايمة محسوبة من الكتالوج مش مكتوبة بالإيد');

// ═══════════════════════════════ ④ التبويبات
sec('④ التبويبات: المجهول بيقع على الأول وكل واحد له ملف');

chk(RepProfile::tab('__مخترع__') === 'salary', '★ التبويب المجهول بيقع على «الراتب»');
chk(RepProfile::tab('') === 'salary', 'والفاضي كمان');
chk(RepProfile::tab('ledger') === 'ledger', 'والمعروف بيعدّي زي ما هو');
foreach (array_keys(RepProfile::TABS) as $t) {
    chk(is_file(dirname(__DIR__) . "/app/Views/reps/_tab_{$t}.php"), "تبويب «{$t}» له ملف");
}

// ═══════════════════════════════ ⑤ المسارات والواجهة
sec('⑤ المسارات الحرفية قبل الجشعة، والواجهة بلا كلاس ميت');

$routes = srcR('public/index.php');
$posDrill  = strpos($routes, '/reps/{id}/drill/{what}');
$posGreedy = strpos($routes, "\$r->get('/reps/{id}',");
chk($posDrill !== false && $posGreedy !== false && $posDrill < $posGreedy,
    '★ /reps/{id}/drill/{what} قبل /reps/{id} الجشع');

$css = srcR('public/assets/css/app.css');
foreach (['rpf-meta', 'rpf-calc', 'rpf-line', 'rpf-k', 'rpf-v', 'rpf-form',
          'rpf-tight', 'num-btn'] as $cls) {
    chk(str_contains($css, '.' . $cls), "الكلاس «{$cls}» له CSS");
}

/* ⚠️⚠️ **اصطدام أسماء اتصاد فعليًّا**: البروفايل بدأ بالبادئة `rp-`، وطلع
   `.rp-av` و`.rp-id` **موجودين أصلاً** لمكوّن «كارت الكيان» — فالأفاتار كان
   بياخد `width:36px` من مكوّن تاني خالص بلا أي خطأ ولا تحذير. البادئة بقت
   `rpf-`، والحارس ده بيمنع الرجوع لـ`rp-` تاني. */
foreach (glob(dirname(__DIR__) . '/app/Views/reps/*.php') ?: [] as $f) {
    $b = basename($f);
    if (in_array($b, ['form.php', 'index.php', 'settlement_print.php'], true)) { continue; }
    chk(!preg_match('~class="[^"]*\brp-~', srcR('app/Views/reps/' . $b)),
        "★ {$b} مش بيستخدم البادئة المزدحمة rp-");
}

/* ⚠️⚠️ **مصيدة العملة المكرّرة الموثَّقة**: `money0()` بترجّع «ج.م» جوّه النص،
   ومكوّن `stat` بيضيف `unit` جنبه ⇒ «٢٤٥٬٠٥٠ ج.م ج» (اتصاد بلقطة شاشة).
   الحارس بيمسك أي `unit` سطرها السابق فيه `money0(` بلا `false`. */
foreach (array_merge(glob(dirname(__DIR__) . '/app/Views/reps/*.php') ?: [],
                     glob(dirname(__DIR__) . '/app/Views/payroll/*.php') ?: []) as $f) {
    $lines = file($f) ?: [];
    foreach ($lines as $i => $ln) {
        if (!str_contains($ln, "'unit'")) { continue; }
        $prev = $lines[$i - 1] ?? '';
        if (!str_contains($prev, 'money0(')) { continue; }
        chk(str_contains($prev, 'false'),
            '★★ ' . basename($f) . ':' . ($i + 1) . ' العملة مش مكرّرة مع unit');
    }
}

/* ⚠️ `.spacer` معرَّفة لـ`.topbar`/`.toolbar` بس — واستخدامها جوّه
   `.section-head` (٤ مرات في البروفايل) كان بيخلّي الشارة تلزق في العنوان. */
chk(str_contains($css, '.section-head .spacer'), '★ و.spacer شغّالة جوّه .section-head');

/* ⚠️ كل نوع تفاصيل بيتطبع في شاشة لازم يكون **مسموحًا** في الكنترولر، وإلا
   الرقم بيفتح «مش موجود» — عطل ما بيبانش غير بالضغط. */
$ctrlSrc = srcR('app/Controllers/RepController.php');
$viewsAll = '';
foreach (glob(dirname(__DIR__) . '/app/Views/reps/*.php') ?: [] as $f) {
    $viewsAll .= (string) file_get_contents($f);
}
preg_match_all('~drill/([a-z]+)~', $viewsAll, $um);
foreach (array_unique($um[1]) as $w) {
    chk(str_contains($ctrlSrc, "'" . $w . "'"), "نوع التفاصيل «{$w}» مسموح في الكنترولر");
}

$views = ['show', '_tab_salary', '_tab_ledger', '_tab_custody', '_tab_work', '_tab_card', '_drill'];
foreach ($views as $v) {
    $s = codeR("app/Views/reps/{$v}.php");
    chk($s !== '', "الملف {$v}.php موجود");
    chk(!str_contains($s, '<style'), "و{$v}.php بلا <style> محلي");
    chk(!preg_match('~\sstyle=~', $s), "و{$v}.php بلا style= inline");
}

/* ⚠️ `icon('اسم-غلط')` ما بترميش خطأ — بترسم دايرة فاضية. */
$icons = srcR('app/Core/helpers.php');
foreach ($views as $v) {
    preg_match_all("~icon\('([a-z0-9-]+)'~", srcR("app/Views/reps/{$v}.php"), $m);
    foreach (array_unique($m[1]) as $ic) {
        chk((bool) preg_match("~'" . preg_quote($ic, '~') . "'\s*=>~", $icons),
            "الأيقونة «{$ic}» موجودة ({$v})");
    }
}

// ═══════════════════════════════ ⑥ السلوك على القاعدة
if (!Payroll::available()) {
    echo "\n(جداول الرواتب لسه ما اترحّلتش — فحص السلوك اتخطّى)\n";
} else {
    $treasuryBefore = Closing::currentBalance();
    $pdo = Database::conn();
    $pdo->beginTransaction();
    try {
        sec('⑥ البند اليدوي بيغيّر الصافي بمقداره بالظبط');

        Database::exec("INSERT INTO reps (name, monthly_salary, status) VALUES ('__rp_x', 7000, 'active')");
        $rep    = Database::lastId();
        $period = RepAccount::period();
        $day    = $period['to'];

        $net0 = RepProfile::salary($rep, $period)['net'];

        Rep::createEntry($rep, ['entry_date' => $day, 'kind' => 'bonus', 'amount' => 400, 'note' => '__b']);
        $net1 = RepProfile::salary($rep, $period)['net'];
        near('★ المكافأة زوّدت الصافي ٤٠٠', $net1 - $net0, 400);

        Rep::createEntry($rep, ['entry_date' => $day, 'kind' => 'penalty', 'amount' => 150, 'note' => '__p']);
        $net2 = RepProfile::salary($rep, $period)['net'];
        near('★★ والجزاء قلّله ١٥٠', $net2 - $net1, -150);

        near('وصافي التعديلات = ٢٥٠', RepProfile::salary($rep, $period)['adjustments'], 250);
        chk(count(RepProfile::salary($rep, $period)['rows']) === 2, 'والرقم بيفتح صفّيه');

        /* ⚠️⚠️ **الفرق ده هو قلب الوحدة**: المكافأة والجزاء استحقاقات —
           ما بيمسّوش خزنة. السلفة كاش — بتخرج فعلاً. الفحص بيقيس الاتنين
           على نفس المندوب في نفس اللحظة، فلو نوع اتحرّك من ناحية للتانية
           بالسكوت الفحص بيمسكه. */
        near('★★ المكافأة والجزاء ما لمسوش الخزنة',
            Closing::currentBalance(), $treasuryBefore);

        Rep::createEntry($rep, ['entry_date' => $day, 'kind' => 'advance', 'amount' => 900, 'note' => '__a']);
        near('★★ والسلفة ما دخلتش التعديلات (تحسيب مزدوج)',
            RepProfile::salary($rep, $period)['adjustments'], 250);
        near('★★ لكنها خرجت من الخزنة فعلاً (٩٠٠)',
            Closing::currentBalance(), $treasuryBefore - 900);

        sec('⑦ الكارت = الكشف = اللقطة');

        $sal = RepProfile::salary($rep, $period);
        near('★★ صافي البروفايل = صافي «حسابي» على الموبايل',
            $sal['net'], RepAccount::summary($rep, $period)['net']);

        $pid = Payroll::approve($period);
        $line = Database::row('SELECT * FROM payroll_lines WHERE period_id = ? AND rep_id = ?', [$pid, $rep]);
        chk($line !== null, 'سطر اللقطة اتخزّن');
        near('★★ والتعديلات اتخزّنت في اللقطة', (float) $line['adjustments'], 250);
        near('★ وصافي اللقطة = صافي الشاشة قبل الاعتماد', (float) $line['net'], $sal['net']);

        sec('⑧ حارس الفترة المعتمدة');

        $blocked = false;
        try { Rep::createEntry($rep, ['entry_date' => $day, 'kind' => 'penalty', 'amount' => 50]); }
        catch (\RuntimeException $e) { $blocked = true; }
        chk($blocked, '★★ إضافة بند جوّه فترة معتمدة مرفوضة');

        $manual = Database::scalar(
            "SELECT id FROM rep_entries WHERE rep_id = ? AND note = '__p' LIMIT 1", [$rep]);
        $blocked = false;
        try { Rep::deleteEntry((int) $manual); } catch (\RuntimeException $e) { $blocked = true; }
        chk($blocked, '★ وحذف بند من فترة معتمدة مرفوض');

        $gen = Database::scalar(
            'SELECT id FROM rep_entries WHERE payroll_period_id = ? LIMIT 1', [$pid]);
        $blocked = false;
        try { Rep::deleteEntry((int) $gen); } catch (\RuntimeException $e) { $blocked = true; }
        chk($blocked, '★★ وبند النظام ولّده مايتحذفش بالإيد');

        // ⚠️ بعد الإلغاء لازم الكتابة ترجع تشتغل — الحارس قفل مش شلل دائم
        Payroll::unapprove($pid);
        $reopened = true;
        try { Rep::createEntry($rep, ['entry_date' => $day, 'kind' => 'penalty', 'amount' => 50, 'note' => '__p2']); }
        catch (\RuntimeException $e) { $reopened = false; }
        chk($reopened, '★ وبعد إلغاء الاعتماد الكتابة رجعت');

        chk((int) Database::scalar(
            'SELECT COUNT(*) FROM rep_entries WHERE payroll_period_id = ?', [$pid]) === 0,
            'وإلغاء الاعتماد شال بنوده');
        chk((int) Database::scalar(
            "SELECT COUNT(*) FROM rep_entries WHERE rep_id = ? AND note = '__p'", [$rep]) === 1,
            '★★ وما مسحش البنود اليدوية بتاعة المالك');

        /* الفرق الوحيد المشروع في الفحص كله = السلفة الكاش اللي فوق. */
        near('★★ وفرق الخزنة في الفحص كله = السلفة وبس',
            Closing::currentBalance(), $treasuryBefore - 900);

    } finally {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
    }
}

echo "\n" . str_repeat('=', 62) . "\n";
echo "ناجح: $pass   ·   فاشل: $fail\n";
exit($fail > 0 ? 1 : 0);
