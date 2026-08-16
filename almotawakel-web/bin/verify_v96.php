<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص v96 — **وحدة التسعير هي السيارة لا الحاوية**.
 *
 * تصحيح المالك: «ناولون العميل مش بيتحكم فيه عدد الحاويات ولكن عدد السيارات —
 * الكونتر ال٢٠ قدم السيارة تقدر تشيل اتنين فده ناولون واحد، والكونتر ال٤٠
 * متقدرش العربية تشيل غيره».
 *
 * أهم اللي بيتأكد منه:
 *   ① **السلوك** — القاعدة نفسها (سعة · فردة سايبة · حمولة مختلطة · وصف مجهول)،
 *      مقيسة على الدالة لا على رقم مزروع في القاعدة.
 *   ② **المصدر الوحيد** — مفيش ملف تاني بيقسم عدد حاويات بإيده. القسمة اليدوية
 *      هي بالظبط اللي بتخلّي «الفاتورة تقول ٤ والكارت يقول ٨» بلا أي خطأ ظاهر.
 *   ③ **نقاط الاستدعاء موجودة** في الأربع سطوح — شاشة بتسقّط الرقم بالسكوت
 *      أسوأ من شاشة بتوريه غلط، لأن محدّش بيلاحظ الغياب.
 *   ④ **التوحيد بيمرّ على `container_norm()`** — المصدر الوحيد لتصنيف الحاوية.
 *   ⑤ **العزل** — الموديل ما بيلمسش قاعدة بيانات ولا خزنة ولا تقفيل ولا عهدة.
 *   ⑥ **صفر تصميم جديد** — ولا كلاس اتولد، ولا `style=` اتزاد في الشاشات المتغيّرة.
 *
 * ⚠️ **قراءة بحتة** — الفحص ده ما بيكتبش ولا صف، فمش محتاج transaction.
 * التشغيل:  php bin/verify_v96.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Models\Freight;

$pass = 0; $fail = 0;
function ok96(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad96(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function chk96(bool $c, string $m, string $why = ''): void {
    $c ? ok96($m) : bad96($m . ($why !== '' ? " — {$why}" : ''));
}
function sec96(string $m): void { echo "\n$m\n" . str_repeat('─', 62) . "\n"; }

/** ⚠️ كود بلا تعليقات — التعليق اللي بيوثّق القاعدة بيذكر النص الممنوع نفسه. */
function code96(string $file): string {
    $p = dirname(__DIR__) . '/' . $file;
    if (!is_file($p)) { return ''; }
    $out = '';
    foreach (token_get_all((string) file_get_contents($p)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

echo "فحص v96 — وحدة التسعير: السيارة لا الحاوية\n" . str_repeat('=', 62) . "\n";

// ═══════════════════════════════════════════ ① السلوك
sec96('① القاعدة: الـ٢٠ بتركب اتنين والـ٤٠ بتركب لوحدها');

/* ⚠️ الحالات دي **مشتقّة من بيانات فعلية** لا مخترعة: ٢٧ بوليصة ٢٠ قدم
   بحاويتين · ٤١ بوليصة ٤٠ قدم بحاوية واحدة بلا استثناء · وإذن «اكما»
   ٨ حاويات ٢٠ قدم في ٤ بوالص. */
$CASES = [
    ['20 قدم',   8, 4, 'إذن اكما: ٨ حاويات ٢٠ قدم = ٤ سيارات'],
    ['20 قدم',   2, 1, 'حاويتين ٢٠ بيركبوا سيارة واحدة'],
    ['20 قدم',   3, 2, 'الفردة السايبة بتركب لوحدها (مش ١٫٥ ولا ١)'],
    ['20 قدم',   1, 1, 'حاوية ٢٠ لوحدها = سيارة'],
    ['40 قدم',   7, 7, 'الـ٤٠ سيارة لكل حاوية'],
    ['40 مبرّد', 2, 2, 'المبرّد سيارة لكل حاوية'],
    ['1*40',     2, 2, 'الصيغة القديمة «1*40» بتتفهم زي «40 قدم»'],
    ['٢×٢٠',     5, 3, 'الأرقام العربية والصيغة المدموجة بتتوحّد'],
];
foreach ($CASES as [$type, $n, $expect, $why]) {
    $got = Freight::cars($type, $n);
    chk96($got === $expect, $why, 'رجّع ' . var_export($got, true) . ' بدل ' . $expect);
}

chk96(Freight::cars('دبابة', 3) === null,
    'الوصف اللي مش حاوية معروفة بيرجّع NULL مش رقم مخترع',
    'رقم غلط بيتقري كمعلومة صحيحة');
chk96(Freight::cars('20 قدم', 0) === null, 'صفر حاويات = مفيش إجابة مش صفر سيارات');
chk96(Freight::cars('', 4) === null, 'التصنيف الفاضي بيرجّع NULL');

sec96('② الحمولة المختلطة: كل نوع بيتحسب لوحده');

chk96(Freight::carsFor([['type' => '20 قدم', 'cnt' => 2], ['type' => '40 قدم', 'cnt' => 6]]) === 7,
    'سيارة للـ٢٠ + ٦ للـ٤٠ = ٧ — مش قسمة الإجمالي',
    'الـ٢٠ ما بيركبش مع الـ٤٠');
chk96(Freight::carsFor([['type' => '20 قدم', 'cnt' => 2], ['type' => 'دبابة', 'cnt' => 1]]) === null,
    'نوع واحد مجهول بيبطّل الإجابة كلها',
    'رقم ناقص بيتقري كرقم كامل');
chk96(Freight::carsFor([], 8, '20 قدم') === 4,
    'من غير بوالص بيقع على بيانات الإذن نفسه (التخمين قبل الإدخال)');
chk96(Freight::carsFor([], 0, '') === null, 'بلا بوالص وبلا بيانات = صمت');

sec96('③ الرقم المعروض: الواقع بيغلب الاشتقاق');

/* ⚠️ الفرق ده هو **قلب v96**: قبل ما البوالص تتسجّل الرقم اشتقاق، وبعدها
   الرقم الحقيقي هو عدد البوالص (البوليصة = سيارة — متحقَّق على كل فواتير
   النظام). لو الاتنين اتساووا في الكود، الشاشة بتكدب في واحدة من الحالتين. */
$noBills = Freight::summary([['type' => '20 قدم', 'cnt' => 8]], 0);
chk96(str_contains($noBills, 'متوقّعة') && str_contains($noBills, '4'),
    'بلا بوالص: الرقم بيتوسم «متوقّعة» صراحةً', $noBills);

$match = Freight::summary([['type' => '20 قدم', 'cnt' => 8]], 4);
chk96(!str_contains($match, 'متوقّع') && str_contains($match, '4'),
    'البوالص طابقت الاشتقاق: رقم واحد بلا ضجيج', $match);

$clash = Freight::summary([['type' => '20 قدم', 'cnt' => 8]], 5);
chk96(str_contains($clash, '5') && str_contains($clash, 'المتوقّع') && str_contains($clash, '4'),
    'البوالص خالفت الاشتقاق: الاتنين بيتعرضوا — الاختلاف نفسه معلومة', $clash);

chk96(Freight::summary([], 0, 0, '') === '',
    'مفيش أي دليل ⇒ سلسلة فاضية فالشاشة ما بترسمش شارة فاضية');

sec96('④ بصمة الحمولة — أساس تسعير v98');

/* «١×٢٠» و«١×٤٠» سعرين مختلفين لنفس العميل (بيردس: ١٤٬٠٠٠ و١٧٬٥٠٠)، فلازم
   يبقوا مفتاحين مختلفين لا مفتاحًا واحدًا بعدد حاويات. */
$s1 = Freight::loadSignature('20 قدم', 1);
$s2 = Freight::loadSignature('40 قدم', 1);
$s3 = Freight::loadSignature('20 قدم', 2);
chk96($s1 !== null && $s2 !== null && $s3 !== null && $s1 !== $s2 && $s1 !== $s3,
    'كل حمولة بصمة مستقلة (١×٢٠ ≠ ١×٤٠ ≠ ٢×٢٠)');
chk96(Freight::loadSignature('1*20', 2) === $s3,
    'البصمة بتتوحّد قبل ما تتكوّن — الصيغة القديمة والجديدة نفس المفتاح');

// ═══════════════════════════════════════════ ⑤ المصدر الوحيد
sec96('⑤ المصدر الوحيد — مفيش قسمة يدوية على سعة السيارة');

$root = dirname(__DIR__);
$scan = [];
foreach ([$root . '/app/Models', $root . '/app/Controllers', $root . '/app/Views'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $scan[str_replace($root . '/', '', $f->getPathname())] = true;
        }
    }
}
ksort($scan);

/* ⚠️ **المقسوم عليه لازم يكون رقمًا صريحًا** — ودي مش تدقيق زيادة:
   النسخة الأولى من الفحص كانت بتمسك أي قسمة فيها كلمة «حاويات» فأدّت **٣
   إنذارات كاذبة** على كود سليم تمامًا:
     • `ops/_drill.php`  — نسبة مئوية (`/ $totals['containers']`)
     • `OpsReport.php`   — متوسطات (`/ $days` · `/ $t['trips']`)
     • `Debt.php`        — دفعة الكشف والضمان (`/ self::inspectionBatch()`)
       ودي **قاعدة تانية خالص** ليها مصدرها الوحيد بتاعها.
   القسمة على **متغيّر أو دالة** معناها قاعدة ليها مصدرها؛ القسمة على **رقم
   مكتوب** هي وحدها اللي بتعيد اختراع سعة السيارة. */
$BAD_DIV = '~(?:ceil|floor|intdiv|round)\s*\(\s*[^;()]*[Cc]ontainer[^;()]*/\s*\d+~';
$manual = [];
foreach (array_keys($scan) as $rel) {
    if ($rel === 'app/Models/Freight.php') { continue; }
    $code = code96($rel);
    if ($code === '') { continue; }
    if (preg_match($BAD_DIV, $code)) { $manual[] = $rel; }
}
chk96($manual === [], 'ولا ملف بيقسم عدد حاويات على رقم مكتوب — القاعدة في مكان واحد',
    implode(' · ', $manual));

/* ⚠️ **الفحص بيفحص نفسه**: نمط ما بيمسكش حاجة بينجح للأبد ويبان سليمًا.
   العيّنتين دول بيثبتوا إنه لسه بيفرّق بين المخالفة والكود السليم. */
chk96(preg_match($BAD_DIV, 'ceil($containers / 2)') === 1,
    'النمط لسه بيمسك المخالفة الحقيقية (`ceil($containers / 2)`)');
chk96(preg_match($BAD_DIV, 'ceil($containers / self::inspectionBatch())') === 0,
    'والنمط ما بيلزقش في القسمة على قاعدة ليها مصدرها');

sec96('⑥ نقاط الاستدعاء موجودة في كل سطح');

/* ⚠️ الغياب بيفشل بالسكوت: شاشة ما بترسمش الرقم مفيش حاجة بتصرخ فيها.
   الفحص ده هو اللي بيصرخ. */
$SURFACES = [
    'app/Views/permits/invoice_form.php' => 'فورم الفوترة',
    'app/Views/permits/_hero.php'        => 'كارت الإذن (العرض والإدارة)',
    'app/Models/RepPortal.php'           => 'بوابة المندوب /m',
];
foreach ($SURFACES as $file => $label) {
    $code = code96($file);
    chk96($code !== '' && preg_match('~Freight::(summary|carsFor)\s*\(~', $code) === 1,
        "«{$label}» بينده `Freight` مش بيحسب بنفسه");
}
$mHome = code96('app/Views/m/home.php');
chk96(str_contains($mHome, 'cars_expected'),
    '«كارت المندوب» بيعرض العدد المتوقّع الجاي من الموديل');

/* الفاتورة والكارت **لازم** يستخدموا `summary` بالذات لا `carsFor` الخام —
   `summary` هي اللي فيها قاعدة «الواقع يغلب الاشتقاق». واحد يستخدم الخام
   يبقى رقمين مختلفين لنفس الإذن. */
foreach (['app/Views/permits/invoice_form.php', 'app/Views/permits/_hero.php'] as $file) {
    chk96(str_contains(code96($file), 'Freight::summary('),
        "`{$file}` بيستخدم `summary` (اللي فيها قاعدة الواقع يغلب الاشتقاق)");
}

sec96('⑦ التوحيد بيمرّ على المصدر الوحيد للتصنيف');

$freightCode = code96('app/Models/Freight.php');
chk96(substr_count($freightCode, 'container_norm(') >= 2,
    'كل مدخل تصنيف بيعدّي على `container_norm()` قبل المقارنة',
    'من غيرها «1*40» بتبقى نوعًا مجهولاً والرقم يختفي');

// ═══════════════════════════════════════════ ⑧ العزل
sec96('⑧ العزل — الموديل ما بيلمسش قاعدة ولا رصيد');

chk96(!preg_match('~\bDatabase::~', $freightCode),
    'مفيش أي استعلام في `Freight` — حساب خالص');
$forbidden = ['closing', 'custody', 'company_safe', 'balances', 'bill_payments'];
$touched = array_values(array_filter($forbidden,
    static fn ($t) => stripos($freightCode, $t) !== false));
chk96($touched === [], 'ولا ذكر لخزنة ولا تقفيل ولا عهدة', implode(' · ', $touched));

sec96('⑨ صفر تصميم جديد — الشاشات لبست هدومها القديمة');

$css = (string) file_get_contents($root . '/public/assets/css/app.css');
chk96(str_contains($css, '.ct-chip'),
    'شارة السيارات بتستخدم `.ct-chip` القايمة — ولا كلاس اتولد');

/* ⚠️ `style=` inline بيدهس نقاط الكسر المتجاوبة وبيمنع الوضع الليلي
   (اللون بيتكتب في الماركب مش جاي من توكن) — مصيدة موثَّقة. */
foreach (['app/Views/permits/invoice_form.php', 'app/Views/permits/_hero.php'] as $file) {
    $raw = (string) file_get_contents($root . '/' . $file);
    chk96(!str_contains($raw, 'style=') && !str_contains($raw, '<style'),
        "`{$file}` لسه بصفر `style=` محلي");
}

// ═══════════════════════════════════════════ الخلاصة
echo "\n" . str_repeat('=', 62) . "\n";
printf("ناجح: %d · فاشل: %d\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
