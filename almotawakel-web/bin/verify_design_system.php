<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص نظام التصميم — المرحلة ١٠ (2026-07-25).
 *
 * المشكلة اللي المرحلة دي بتحلّها: `app.css` فيها **٣٠١ قاعدة** للغة تصميم
 * حديثة (`.d-*`/`.rp-*`) كانت بتستخدمها **شاشة واحدة من ١١٧** (الداشبورد)،
 * وباقي النظام واقع على ١٨ قاعدة عامة — فالنظام شكله «جدول إداري قديم»
 * برّه الداشبورد.
 *
 * ⚠️ **الفاحص ده هو اللي بيخلّي القاعدة تلتزم**، مش التوثيق. أهم اللي بيحرسه:
 *   • **ممنوع رقم أصمّ** (قاعدة المالك الدائمة): مكوّن `stat` بيرفض يترسم بلا
 *     مصدر تفاصيل، وأي `.d-kpi` في شاشة متحوّلة لازم يكون معاه `data-drill`.
 *   • **نافذة واحدة عامة** في `layout.php` — ممنوع نافذة محلية في أي شاشة
 *     (نفس مصيدة سكربت «إظهار الأرصدة» اللي كان محليًّا في `office/index.php`
 *     فالأرصدة فضلت `••••••` للأبد في شاشتين تانيين).
 *   • **كل كلاس بيتحقن بالجافاسكريبت له CSS** — مصيدة `.table-mobile-scroll`
 *     المتكرّرة (خطّاف بيتحقن ومفيش له تنفيذ فيفشل بالسكوت).
 *   • **مسارات التفاصيل محصورة وبتمرّ على الصلاحيات** — مفيش مسار عام ياخد
 *     اسم جدول ويرجّع صفوف.
 *
 * بيرندر المكوّنات **فعليًّا** بلا أي كتابة على القاعدة.
 * التشغيل:  php bin/verify_design_system.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Access;

$pass = 0; $failures = [];
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $failures; $failures[] = $m; echo "  ✗ $m\n"; }
function chk(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function sec(string $m): void { echo "\n$m\n" . str_repeat('─', 62) . "\n"; }

/** يشيل تعليقات PHP/HTML قبل الفحص — تعليق بيشرح قاعدة قديمة كان بيدّي إنذارًا كاذبًا. */
function code_only(string $src): string
{
    // ⚠️ الفاصل `!` كان بيتصادم مع `!` اللي جوّه `(?<!:)` فالنمط كان بيتقفل
    //    بدري ويرمي «Unknown modifier» — استخدم فاصلًا مش موجود في النمط.
    $src = preg_replace('~<!--.*?-->~s', '', $src) ?? $src;
    return preg_replace('~/\*.*?\*/|(?<!:)//[^\n]*~s', '', $src) ?? $src;
}

$root = dirname(__DIR__);
$views = glob($root . '/app/Views/**/*.php') ?: [];
$views = array_merge($views, glob($root . '/app/Views/*.php') ?: []);
$css   = (string) file_get_contents($root . '/public/assets/css/app.css');
$js    = (string) file_get_contents($root . '/public/assets/js/app.js');
$layout = (string) file_get_contents($root . '/app/Views/layout.php');

echo "فحص نظام التصميم — المرحلة ١٠\n" . str_repeat('=', 62) . "\n";

// ═══ ① لغة النظام موثّقة ومش «ستايل الداشبورد»
sec('① لغة النظام معلَنة في app.css');
chk(str_contains($css, 'لغة تصميم النظام'),
    'قسم اللغة موسوم إنه لكل الشاشات مش للداشبورد');
chk(str_contains($css, 'ممنوع تكتب كارت أو صف أو رقم بستايل جديد في شاشة'),
    'القاعدة مكتوبة جنب الكود: استخدم المكوّنات');

// ═══ ② المكوّنات موجودة وبترندر
sec('② مكوّنات لغة النظام بترندر فعليًّا');
$need = ['stat', 'stat_grid', 'row_item', 'entity_card', 'panel', 'day_bar', 'drill'];
foreach ($need as $n) {
    chk(is_file($root . '/app/Views/partials/' . $n . '.php'), "مكوّن `$n` موجود");
}
$render = static function (string $name, array $vars): string {
    ob_start();
    try { partial($name, $vars); return (string) ob_get_clean(); }
    catch (\Throwable $e) { ob_end_clean(); bad("رندر `$name` رمى استثناء: " . $e->getMessage()); return ''; }
};

$statHtml = $render('stat', ['label' => 'اختبار', 'value' => '1,234', 'drill' => '/x', 'icon' => 'wallet']);
chk(str_contains($statHtml, 'class="d-kpi') && str_contains($statHtml, 'data-drill="/x"'),
    '`stat` بيطبع لغة النظام ومعاه مصدر التفاصيل');
chk(str_contains($statHtml, '<svg') && !preg_match('/[\x{1F300}-\x{1FAFF}]/u', $statHtml),
    '`stat` بيستخدم أيقونة SVG مش إيموجي');

$rowHtml = $render('row_item', ['title' => 'بند', 'icon' => 'truck', 'url' => '/y',
    'meta' => [['icon' => 'user', 'text' => 'فلان']], 'amount' => '500']);
chk(str_contains($rowHtml, 'class="d-row"') && str_contains($rowHtml, 'href="/y"'),
    '`row_item` بيطبع صف لغة النظام');

$cardHtml = $render('entity_card', ['name' => 'محمد', 'value' => '2,400', 'url' => '/z',
    'meter' => [['cls' => 'sp', 'pct' => 40]], 'tags' => [['text' => 'تاج']]]);
chk(str_contains($cardHtml, 'class="rp-card') && str_contains($cardHtml, 'rp-av')
    && str_contains($cardHtml, 'rp-bar'), '`entity_card` بيطبع كارت كيان بأڤاتار وشريط');

$dayHtml = $render('day_bar', ['day' => date('Y-m-d'), 'note' => 'ملاحظة']);
chk(str_contains($dayHtml, 'class="day-bar') && str_contains($dayHtml, 'غيّر يوم العمل'),
    '`day_bar` بيعرض اليوم وطريق تغييره');
chk(str_contains($render('day_bar', ['day' => date('Y-m-d'), 'locked' => true]), 'day-bar locked'),
    '`day_bar` بيبان مقفول لما اليوم يبقى متقفل');

// ═══ ③ ⚠️ ممنوع رقم أصمّ — الإجبار في المكوّن نفسه
sec('③ ⚠️ ممنوع رقم أصمّ (قاعدة المالك الدائمة)');
$noDrill = $render('stat', ['label' => 'رقم بلا تفاصيل', 'value' => '9']);
chk(!str_contains($noDrill, '<button'),
    '`stat` **بيرفض يترسم** بلا مصدر تفاصيل — الإجبار في المكوّن مش في المراجعة');
chk(str_contains($noDrill, 'ممنوع رقم أصمّ'),
    'وبيسيب أثرًا ظاهرًا في مصدر الصفحة بدل ما يعدّي بالسكوت');
chk(str_contains(file_get_contents($root . '/app/Views/partials/stat.php'), 'ممنوع رقم أصمّ'),
    'القاعدة مكتوبة في رأس المكوّن للي هيقراه بعدين');

// كل .d-kpi في شاشة متحوّلة لازم معاه data-drill
$converted = [
    $root . '/app/Views/rep_custody/index.php',
    $root . '/app/Views/dashboard/index.php',
];
foreach ($converted as $f) {
    $src = code_only((string) file_get_contents($f));
    $name = basename(dirname($f)) . '/' . basename($f);
    chk(!preg_match('/class="d-kpi(?![^>]*data-drill)/', $src),
        "$name: مفيش رقم مرسوم بالإيد بلا تفاصيل");
}

// ═══ ④ نافذة واحدة عامة
sec('④ نافذة التفاصيل — نسخة واحدة لكل النظام');
chk(str_contains($layout, 'id="uiDrill"'), 'النافذة العامة في layout.php');
chk(str_contains($layout, 'aria-modal="true"'), 'نافذة حقيقية (aria-modal) — القديمة ما كانتش');
chk(str_contains($js, 'activateDialog(modal'), 'بتاخد حبس التركيز من activateDialog القائمة');
/* ⚠️ نافذة محلية واحدة **قائمة من قبل المرحلة ١٠** — مسموح بها مؤقتًا لحد ما
      شاشتها تتحوّل، والفحص بيفشل لو **اتزادت واحدة جديدة**. الاستثناء مؤرَّخ
      عن قصد: قايمة استثناءات بلا موعد بتتحوّل لتصريح دائم بالتكرار.
        • statements/form.php → المرحلة ١٥ (شاشة صغيرة)
      (dashboard/index.php اتحوّلت في المرحلة ١١ فاتشالت من القايمة) */
$knownLocalModals = ['statements/form.php'];
$localModals = [];
foreach ($views as $f) {
    if (str_ends_with($f, '/layout.php')) { continue; }
    $src = code_only((string) file_get_contents($f));
    if (preg_match('/class="d-modal"/', $src)) { $localModals[] = str_replace($root . '/app/Views/', '', $f); }
}
$newLocal = array_values(array_diff($localModals, $knownLocalModals));
chk(!$newLocal,
    'مفيش نافذة محلية **جديدة** في أي شاشة' . ($newLocal ? ' — لقينا: ' . implode(' · ', $newLocal) : ''));
$goneLocal = array_values(array_diff($knownLocalModals, $localModals));
chk(!$goneLocal,
    'قايمة الاستثناءات مضبوطة' . ($goneLocal ? ' — اتحوّلت خلاص، شيلها: ' . implode(' · ', $goneLocal) : ''));

// ═══ ⑤ محرّك التفاصيل
sec('⑤ محرّك التفاصيل في app.js');
chk(str_contains($js, 'drillDown'), 'المحرّك موجود');
chk(str_contains($js, "source.charAt(0) === '#'"), 'بيدعم الوضع الجاهز (inline)');
chk(str_contains($js, 'await fetch(source'), 'وبيدعم الجلب عند الضغط (lazy) — الافتراضي');
chk(str_contains($js, 'cache.set(source'), 'بيكاش الرد فالضغط التاني فوري');
chk(str_contains($js, 'mine !== token'), 'رد قديم ما بيدهسش نافذة اتفتحت بعده');
chk(str_contains($js, 'تعذّر جلب التفاصيل'),
    '⚠️ الفشل بيبان — النافذة ما تفضلش بتلفّ للأبد والمالك مستني');
chk(!str_contains($js, '__almTableTools'),
    'مفيش نداء لدالة أدوات جداول مش موجودة (كان هيبقى كود ميت)');

// ═══ ⑥ الصلاحيات على مسارات التفاصيل
sec('⑥ ⚠️ مسارات التفاصيل مش عامة');
$routes = (string) file_get_contents($root . '/public/index.php');
chk(str_contains($routes, "\$r->get('/rep-custody/drill/{what}'"), 'مسار تفاصيل عهدة المناديب مسجَّل');
$idx = strpos($routes, "/rep-custody/drill/{what}");
$greedy = strpos($routes, "\$r->get('/rep-custody/{id}'");
chk($idx !== false && $greedy !== false && $idx < $greedy,
    'وهو **حرفي قبل** المسار الجشع `/rep-custody/{id}` (تحذير ٤)');
$ctrl = code_only((string) file_get_contents($root . '/app/Controllers/RepCustodyController.php'));
chk(str_contains($ctrl, 'private const DRILL_ALL')
    && str_contains($ctrl, 'private const DRILL_REP')
    && str_contains($ctrl, 'private const DRILL_INBOX')
    && str_contains($ctrl, 'array_merge(self::DRILL_ALL, self::DRILL_REP, self::DRILL_INBOX)'),
    'نوع التفاصيل محصور في قوايم بيضا مقفولة — مش اسم جدول جاي من المستخدم');
chk(str_contains($ctrl, '$this->requireAuth();'), 'المسار بيطلب تسجيل دخول');
// الصلاحية المركزية: الرفض هو الافتراضي، فلازم الدور المسموح يعدّي والممنوع يترفض
chk(Access::allows('owner', 'RepCustodyController@drill', 'GET'), 'المالك مسموح له');
chk(!Access::allows('guest', 'RepCustodyController@drill', 'GET')
    || Access::allows('guest', 'RepCustodyController@index', 'GET'),
    'الصلاحية بتمرّ على Access نفسها مش شرط مكتوب بالإيد');

// ═══ ⑦ كل كلاس بيتحقن بالجافاسكريبت له CSS
sec('⑦ ⚠️ كل كلاس محقون له CSS (مصيدة .table-mobile-scroll)');
foreach (['drill-load', 'drill-spin', 'drill-err', 'day-bar', 'drill-how', 'drill-foot', 'drill-total'] as $cls) {
    $inJs  = str_contains($js, $cls) || str_contains($layout, $cls);
    $inCss = str_contains($css, '.' . $cls);
    chk(!$inJs || $inCss, "`.$cls` معرَّف في app.css");
}

// ═══ ⑧ الشاشة المرجعية اتحوّلت فعلاً
sec('⑧ الشاشة المرجعية (rep_custody/index) اتحوّلت للغة النظام');
$refSrc = code_only((string) file_get_contents($root . '/app/Views/rep_custody/index.php'));
chk(!str_contains($refSrc, '<table'),
    'الجدول اتشال — القايمة بقت كروت وصفوف');
foreach (['day_bar', 'stat_grid', 'stat', 'entity_card', 'panel', 'row_item'] as $c) {
    chk(str_contains($refSrc, "partial('$c'"), "بتستخدم مكوّن `$c`");
}
chk(substr_count($refSrc, "partial('stat',") === 4, 'أربع أرقام، كلها بمكوّن `stat`');
chk(substr_count($refSrc, "'drill' =>") === 4, 'وكل واحد فيهم معاه مصدر تفاصيل');
chk(!preg_match('/style="[^"]*grid-template-columns/', $refSrc),
    'مفيش شبكة inline (الـinline بيدهس نقاط الكسر المتجاوبة)');
chk(!str_contains($refSrc, 'json_encode'), 'مفيش json_encode (قاعدة js_json الأمنية)');

// ═══ ⑨ الداشبورد اتحوّلت (المرحلة ١١)
sec('⑨ الداشبورد اتحوّلت للغة النظام (المرحلة ١١)');
$dash = code_only((string) file_get_contents($root . '/app/Views/dashboard/index.php'));
chk(!str_contains($dash, 'id="dModal"') && !str_contains($dash, 'id="popStore"'),
    'البوب-أب القديم (#dModal + #popStore) اتشال — النافذة بقت العامة');
foreach (['day_bar', 'stat_grid', 'stat', 'panel'] as $c) {
    chk(str_contains($dash, "partial('$c'"), "الداشبورد بتستخدم مكوّن `$c`");
}
chk(!preg_match('/class="d-kpi(?![^>]*data-drill)/', $dash),
    'مفيش رقم مرسوم بالإيد بلا تفاصيل في الداشبورد');

echo "\n" . str_repeat('=', 62) . "\n";
echo 'النتيجة: ' . $pass . ' ناجح · ' . count($failures) . " فاشل\n";
if ($failures) { foreach ($failures as $f) { echo "  ✗ $f\n"; } exit(1); }
exit(0);
