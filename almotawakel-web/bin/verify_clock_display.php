<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص «عرض الوقت مضبوطًا على ساعة المستخدم» (2026-07-28).
 *
 * ⚠️⚠️ المصيدة: **MySQL بتشتغل UTC وPHP بـAfrica/Cairo — فرق ٣ ساعات على
 *    الإنتاج**. كل أعمدة الوقت بتتكتب من القاعدة (`NOW()`/`CURRENT_TIMESTAMP`)،
 *    فالبيانات متسقة والغلط في **العرض** بس: سجل العمليات كان بيقول إن الحاجة
 *    حصلت ٣ ساعات بدري، وده بيلخبط أي مراجعة لـ«مين عمل إيه إمتى».
 *
 * ⚠️ **آليتان لتصحيح العرض — وممنوع يجتمعوا على نفس القيمة (تصحيح مرّتين):**
 *    • `Clock::displayExpr()` جوّه SQL — للحالات اللي محتاجة تجميع/فلترة بالساعة
 *      (`SecurityMonitor` بيجمّع محاولات الدخول في شرائح ساعة، `Notify` بيرتّب).
 *    • `dtfmt()` عند العرض — لكل الباقي.
 *
 * ⚠️ **التواريخ بلا وقت (`dfmt`) ما بتتحرّكش أبدًا** — نقل اليوم كان ممكن يغيّر
 *    «يوم العمل» وهو أساس كل الحسابات المالية (العهدة والتقفيل والفواتير).
 *
 * بيحرس:
 *   1) `dtfmt()` موجودة وبتطبّق فرق الساعة من `Clock` (مش رقم مكتوب بالإيد)
 *   2) الفرق **بيتقاس من القاعدة** فبيبطّل نفسه لو الاستضافة ظبّطت التوقيت
 *   3) مفيش شاشة لسه بتعرض وقت خام من القاعدة
 *   4) **مفيش تصحيح مرّتين**: الشاشات اللي مصدرها مصحَّح في SQL ما بتستخدمش dtfmt
 *   5) `dfmt` (التواريخ) ما اتلمستش — ولا `dtfmt` اتحطّت على عمود تاريخ يوم عمل
 *   6) المقارنات لسه جوّه SQL (قاعدة v48 — `Clock` للعرض بس)
 *
 * آمن تمامًا: **قراءة فقط** — بيقرا القاعدة عشان يقيس الفرق وبس.
 *
 * التشغيل:  php bin/verify_clock_display.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Clock;

$pass = 0; $failures = [];
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $failures; $failures[] = $m; echo "  ✗ $m\n"; }
function chk(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function sec(string $m): void { echo "\n$m\n" . str_repeat('─', 62) . "\n"; }

/** يشيل تعليقات PHP — تعليق بيشرح نمطًا ما يعتبرش استخدامًا له. */
function code_only(string $s): string
{
    $s = (string) preg_replace('#/\*.*?\*/#s', '', $s);
    return (string) preg_replace('#^\s*//.*$#m', '', $s);
}

$root    = dirname(__DIR__);
$helpers = (string) file_get_contents($root . '/app/Core/helpers.php');
$clock   = (string) file_get_contents($root . '/app/Core/Clock.php');

echo "فحص عرض الوقت على ساعة المستخدم\n" . str_repeat('=', 62) . "\n";

// ═══ ① الدالة نفسها
sec('① دالة العرض');
chk(function_exists('dtfmt'), '`dtfmt()` متاحة');
chk(str_contains($helpers, 'Clock::dbOffsetMinutes()'),
    'وبتاخد الفرق من `Clock` (مش رقم ٣ ساعات مكتوب بالإيد)');
chk(str_contains(code_only($helpers), 'function dfmt'),
    'و`dfmt()` للتواريخ لسه موجودة منفصلة');

/* ⚠️ الفرق لازم يتقاس من القاعدة فعليًّا: لو الاستضافة ظبّطت التوقيت بعدين،
   الكلاس بيرجّع صفر والتصحيح بيبطّل نفسه بلا ما نلمس أي كود. */
chk(str_contains($clock, "Database::scalar('SELECT NOW()')"),
    '⚠️ والفرق **مقيس من القاعدة** فبيبطّل نفسه لو التوقيت اتظبط');
chk(str_contains($clock, '1440'),
    'وفرق فوق يوم بيتجاهل (ساعة سيرفر بايظة ما تعرضش تواريخ مالهاش معنى)');

// ═══ ② السلوك الفعلي
sec('② السلوك — بيصحّح فعلاً؟');
$skew = Clock::dbOffsetMinutes();
echo '  فرق الساعة المقيس دلوقتي: ' . $skew . " دقيقة\n";
$sample = '2026-07-27 22:29:11';
$shown  = dtfmt($sample, 'Y-m-d H:i:s');
$want   = date('Y-m-d H:i:s', strtotime($sample) + $skew * 60);
chk($shown === $want, "قيمة مخزَّنة {$sample} بتتعرض {$shown}");
chk(dtfmt('') === '' && dtfmt(null) === '', 'والقيمة الفاضية بترجع فاضية (مش 1970)');
chk(dtfmt('نص غلط') === 'نص غلط', 'والقيمة الغلط بترجع زي ما هي بدل ما تكسر الصفحة');

// ═══ ③ مفيش وقت خام لسه معروض
sec('③ مفيش شاشة بتعرض وقت خام');
$rawCols = 'created_at|attempted_at|reviewed_at|opened_at|undone_at|restored_at|last_ping_at|issued_at';
$raw = [];
foreach (glob($root . '/app/Views/*/*.php') ?: [] as $f) {
    $src = code_only((string) file_get_contents($f));
    // `e($x['created_at'])` أو `date(..., strtotime($x['created_at']))` = عرض خام
    if (preg_match('/e\(\s*(?:\(string\)\s*)?\$[a-zA-Z_]+\[[\'"](' . $rawCols . ')[\'"]\]/', $src)
        || preg_match('/date\([^)]*strtotime\(\s*\$[a-zA-Z_]+\[[\'"](' . $rawCols . ')[\'"]\]/', $src)) {
        $raw[] = str_replace($root . '/app/Views/', '', $f);
    }
}
chk($raw === [], 'ولا شاشة بتعرض وقت من القاعدة بلا تصحيح'
    . ($raw ? ' — لقينا: ' . implode(' · ', $raw) : ''));

// ═══ ④ ⚠️ مفيش تصحيح مرّتين
sec('④ ⚠️ مفيش تصحيح مرّتين (٦ ساعات غلط)');
/* الموديلات دي بتصحّح جوّه SQL، فالقيمة اللي بتوصل الشاشة **متظبطة خلاص** —
   لو شاشة بتاعتها استخدمت `dtfmt` كمان يبقى التصحيح اتطبّق مرّتين. */
$sqlFixed = ['SecurityMonitor', 'Notify'];
foreach ($sqlFixed as $m) {
    $src = (string) @file_get_contents($root . '/app/Models/' . $m . '.php');
    chk(str_contains($src, 'Clock::displayExpr'), "`$m` لسه بيصحّح جوّه SQL");
}
/* ⚠️ الفحص على **أسماء الأعمدة المصحَّحة** لا على «الشاشة بتذكر الموديل»:
   أول نسخة كانت بتدوّر على `Notify::` في نص الشاشة، فوقعت على **`PushNotify::`**
   (تطابق جزئي) وبلّغت عن `push/index.php` غلط — ومصدرها `PushNotify::recent()`
   مش مصحَّح أصلاً فـ`dtfmt` عليه صح. الأدق: الاستعلامات المصحَّحة بترجّع
   الوقت بأسماء محدّدة (`at`/`last_at`/`until_at`/`ts`)، فمنع `dtfmt` عليها
   بالتحديد هو الشرط الحقيقي. */
$fixedAliases = 'at|last_at|until_at|ts';
$dbl = [];
foreach (glob($root . '/app/Views/*/*.php') ?: [] as $f) {
    $src = code_only((string) file_get_contents($f));
    if (preg_match('/dtfmt\(\s*\$[a-zA-Z_]+\[[\'"](' . $fixedAliases . ')[\'"]\]/', $src)) {
        $dbl[] = str_replace($root . '/app/Views/', '', $f);
    }
}
chk($dbl === [], '⚠️ ومفيش `dtfmt` على عمود الاستعلام صحّحه خلاص'
    . ($dbl ? ' — لقينا: ' . implode(' · ', $dbl) : ''));

// ═══ ⑤ ⚠️ التواريخ ما اتحركتش
sec('⑤ ⚠️ تواريخ الأيام ما بتتحرّكش (يوم العمل أساس كل الحسابات)');
chk(!preg_match('/function dfmt[^}]*dbOffsetMinutes/s', $helpers),
    '`dfmt()` ما بتطبّقش أي إزاحة — نقل اليوم كان هيغيّر يوم العمل');
$dayCols = 'work_date|permit_date|bill_date|entry_date|close_date|dep_date|record_date|exp_date|settle_date|transfer_date|handover_date';
$moved = [];
foreach (glob($root . '/app/Views/*/*.php') ?: [] as $f) {
    $src = code_only((string) file_get_contents($f));
    if (preg_match('/dtfmt\(\s*\$[a-zA-Z_]+\[[\'"](' . $dayCols . ')[\'"]\]/', $src)) {
        $moved[] = str_replace($root . '/app/Views/', '', $f);
    }
}
chk($moved === [], '⚠️ ومفيش عمود «يوم» اتحطّ عليه `dtfmt`'
    . ($moved ? ' — لقينا: ' . implode(' · ', $moved) : ''));

// ═══ ⑥ المقارنات لسه جوّه SQL (قاعدة v48)
sec('⑥ ⚠️ المقارنات لسه جوّه SQL — `Clock` للعرض بس');
chk(str_contains($clock, 'للعرض فقط') || str_contains($clock, 'استخدمه للعرض بس'),
    'القاعدة موثَّقة جنب الكود');
$cmp = [];
foreach (['Auth.php', 'RepAuth.php'] as $f) {
    $src = code_only((string) @file_get_contents($root . '/app/Core/' . $f));
    if (str_contains($src, 'Clock::')) { $cmp[] = $f; }
}
chk($cmp === [], '⚠️ ومفيش `Clock` في مسار قفل الحسابات (المقارنة جوّه SQL — v48)'
    . ($cmp ? ' — لقينا: ' . implode(' · ', $cmp) : ''));

// ═══ ⑦ العزل
sec('⑦ العزل — عرض بحت');
/* ⚠️ لازم نقصّ **جسم الدالة** الأول: `/function dtfmt.*?X/s` بتمدّ لبقيّة
   الملف كله فبتلقط أي كلمة في أي دالة تانية — نفس مصيدة «القصّ بعدد حروف
   ثابت» الموثَّقة. */
$body = '';
if (preg_match('/function dtfmt\([^)]*\): string\s*\{(.*?)\n\}/s', $helpers, $mm)) { $body = $mm[1]; }
chk($body !== '', 'جسم `dtfmt()` اتقرا للفحص');
chk($body !== '' && !preg_match('/INSERT|UPDATE|DELETE|Database::/i', $body),
    '`dtfmt()` ما بتكتبش ولا بتلمس القاعدة');
chk($body !== '' && !preg_match('/Closing|Custody|amount|balance/i', $body),
    'ومفيهاش أي مفهوم مالي');

echo "\n" . str_repeat('=', 62) . "\n";
echo 'النتيجة: ' . $pass . ' ناجح · ' . count($failures) . " فاشل\n";
if ($failures) { foreach ($failures as $f) { echo "  ✗ $f\n"; } exit(1); }
echo "\n✓ كل الفحوصات عدّت.\n";
exit(0);
