<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص جودة المدخلات — المرحلة ١: حزمة المنع.
 *
 * المشكلة اللي الشغل ده بيحلّها: نفس الشيء متسجَّل بأكتر من اسم
 * («ميناء الإسكندرية» / «الإسكندرية» / «اسكندرية» · «40 قدم» / «40»)،
 * فالتقارير بتتفتّت على قيم هي أصلاً حاجة واحدة.
 *
 * أهم أربع حاجات محروسة هنا:
 *   ١) **الحارس بيفهم اختلاف التسمية مش الإملا بس**: `conceptkey` بتلمّ
 *      «ميناء X» مع «X»، و«الاحتواء» بيلمّ «40» مع «40 قدم».
 *   ٢) **أسماء الأشخاص مستثناة من الاحتواء** — «محمد السيد» و«محمد السيد احمد»
 *      شخصان مختلفان فعلاً، ولو الحارس وقفهم كل يوم الموظف هيتعلّم يتجاهله.
 *   ٣) **القائمة المنسدلة بطّلت تتعلّم من الكتابة** — دي كانت مضخّم التلوّث:
 *      أول ما حد يكتب «40» كانت تدخل القائمة وتبقى اختيارًا شرعيًّا للكل بعد كده.
 *   ٤) **مفيش شاشة إدخال بلا حارس** — `/bawalis` (أكبر مصدر إدخال في النظام)
 *      كانت بلا أي حارس خالص، والموانئ والتوكيلات وشركات الشحن كانوا بلا حارس
 *      في أي شاشة.
 *
 * كله جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_data_quality.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Custody;
use App\Models\Lookup;
use App\Models\Maintenance;
use App\Models\NameSuggestion as NS;

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function sec(string $m): void { echo "\n" . $m . "\n" . str_repeat('─', 62) . "\n"; }
function yes(string $l, bool $c): void { $c ? ok($l) : bad($l); }
function same(string $l, $a, $e): void {
    $a === $e ? ok($l) : bad("$l — متوقع " . var_export($e, true) . " / فعلي " . var_export($a, true));
}

$root = dirname(__DIR__);
/** بيشيل التعليقات قبل أي فحص على الكود — تعليق بيشرح القاعدة ما يعديش كإنذار كاذب. */
function php_code(string $path): string
{
    if (!is_file($path)) { return ''; }
    $out = '';
    foreach (token_get_all((string) file_get_contents($path)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}
function readf(string $p): string { return is_file($p) ? (string) file_get_contents($p) : ''; }

echo "فحص جودة المدخلات — حزمة المنع\n" . str_repeat('=', 62) . "\n";

$balanceBefore = (float) Custody::balance();

/* ⚠️ بيتقاس **قبل** أي تعديل: الأقسام اللي تحت بتزرع جهات وهمية وبتخبّي
   عمود الجهات، فقياسه بعدها بيدّي **فشلًا كاذبًا** بجهات الفحص نفسه. */
$orphanDestAtStart = null;
if (Database::scalar("SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = 'destination_points'")) {
    $knownStart = [];
    foreach (Database::all('SELECT normkey, aliases FROM destination_points') as $p) {
        $knownStart[(string) $p['normkey']] = true;
        foreach (explode('|', (string) $p['aliases']) as $a) {
            $a = trim($a); if ($a !== '') { $knownStart[$a] = true; }
        }
    }
    $orphanDestAtStart = 0;
    foreach (Database::all(
        "SELECT DISTINCT destination v FROM bawalis WHERE destination IS NOT NULL AND TRIM(destination) <> ''"
    ) as $r) {
        $k = Maintenance::normkey((string) $r['v']);
        if ($k === '' || isset($knownStart[$k])) { continue; }
        // نفس قاعدة `migrate_v59`: الجزئي «معروف» كمان
        $hit = \App\Models\Track::resolveDestination((string) $r['v']);
        if ($hit && in_array($hit['match'] ?? '', ['exact', 'alias', 'partial'], true)) { continue; }
        $orphanDestAtStart++;
    }
}

Database::conn()->beginTransaction();

try {

// ═════════════════════════════════════ ١) مفتاح المفهوم
sec('١) مفتاح المفهوم — «ميناء X» ≡ «X»');

same('«ميناء الإسكندرية» و«الإسكندرية» ليهم نفس مفتاح المفهوم',
    NS::conceptkey('port', 'ميناء الإسكندرية'), NS::conceptkey('port', 'الإسكندرية'));
same('«اسكندرية» (بلا ال وبلا همزة) نفس المفتاح كمان',
    NS::conceptkey('port', 'اسكندرية'), NS::conceptkey('port', 'ميناء الإسكندرية'));
same('«الدخيلة» ≡ «ميناء الدخيلة»',
    NS::conceptkey('port', 'الدخيلة'), NS::conceptkey('port', 'ميناء الدخيلة'));
yes('ميناءان مختلفان يفضلوا مختلفين',
    NS::conceptkey('port', 'ميناء دمياط') !== NS::conceptkey('port', 'ميناء السخنة'));
yes('كلمة الوصف بتتشال من البداية بس (مش من وسط الاسم)',
    NS::conceptkey('destination', 'طريق التحدي') !== NS::conceptkey('destination', 'التحدي طريق'));
yes('اسم كله كلمة وصف ما يفضاش',
    NS::conceptkey('port', 'ميناء') !== '');

// ═════════════════════════════════════ ٢) سلوك الحارس
sec('٢) الحارس — يوقف ويخيّر (قرار المالك)');

/** يرجّع snap|stop|ok */
$verdict = static function (string $type, string $raw): string {
    $g = NS::resolve($type, $raw);
    return $g['conflict'] !== null ? 'stop' : ($g['snapped'] ? 'snap' : 'ok');
};

// ⚠️ أسماء **مصطنعة** مش موجودة في البيانات عن قصد. لو استخدمنا «الإسكندرية»
//    الفحص بيفشل بإنذار كاذب: القيمة دي **متسجّلة فعلاً** في `permits.port`،
//    فالمستوى ١ بيطابقها حرفيًّا ويقبلها — وده سلوك صحيح (شوف القسم ٢ب).
//    الفحص هنا على **السلوك** لا على حالة بيانات اليوم.
Database::exec("DELETE FROM lookups WHERE list_type IN ('port','container_type')");
foreach (['ميناء طرابلس', 'ميناء بنغازي'] as $v) { Lookup::add('port', $v, true); }
foreach (['77 قدم', '77 مبرّد'] as $v) { Lookup::add('container_type', $v, true); }
NS::clearNamesCache();

same('«طرابلس» توقف وتخيّر — نفس مثال المالك (ميناء X ≡ X)', $verdict('port', 'طرابلس'), 'stop');
same('«الطرابلس» توقف وتخيّر كمان', $verdict('port', 'الطرابلس'), 'stop');
same('«ميناء طرابلس» تعدّي (مخزَّنة سليمة)', $verdict('port', 'ميناء طرابلس'), 'ok');
same('«ميناء العريش» تعدّي (ميناء جديد فعلاً)', $verdict('port', 'ميناء العريش'), 'ok');

same('«77» توقف وتخيّر — نفس مثال المالك (الوحدة ناقصة)', $verdict('container_type', '77'), 'stop');
same('«77 قدم» تعدّي', $verdict('container_type', '77 قدم'), 'ok');
same('«77 مبرد» تتوحّد تلقائيًا (ناقصة الشدة)', $verdict('container_type', '77 مبرد'), 'snap');
same('«77 قرش» توقف وتخيّر (غلط مطبعي عن «77 قدم»)', $verdict('container_type', '77 قرش'), 'stop');
same('سبب التعارض «مفهوم» للميناء', NS::resolve('port', 'طرابلس')['reason'], 'concept');
same('وسبب تعارض الوحدة «احتواء»', NS::resolve('container_type', '77')['reason'], 'subset');

// ── ٢ب) الحد المعروف: الحارس بيمنع الجديد، وما بينضّفش القديم ─────────────
sec('٢ب) الحد المعروف — القيمة المتسجّلة بالفعل بتعدّي (شغل شاشة التنظيف)');

Lookup::add('port', 'طرابلس', true);   // نتخيّل إنها اتسجّلت قبل التحصين
NS::clearNamesCache();
same('قيمة ملوَّثة **متسجّلة بالفعل** بتعدّي — الحارس مش بديل التنظيف',
    $verdict('port', 'طرابلس'), 'ok');
Database::exec("DELETE FROM lookups WHERE list_type='port' AND value='طرابلس'");
NS::clearNamesCache();
same('وأول ما تتشال من السجل، الحارس بيمسكها تاني', $verdict('port', 'طرابلس'), 'stop');

// ═════════════════════════════════════ ٣) أسماء الأشخاص
sec('٣) أسماء الأشخاص — مستثناة من الاحتواء عن قصد');

Database::exec("DELETE FROM lookups WHERE list_type = 'driver'");
foreach (['محمد السيد', 'احمد خطاب'] as $v) { Lookup::add('driver', $v, true); }
NS::clearNamesCache();

same('«محمد السيد احمد» تعدّي — اسم تلاتي لشخص تاني', $verdict('driver', 'محمد السيد احمد'), 'ok');
same('«احمد خطاب» تعدّي (مخزَّنة)', $verdict('driver', 'احمد خطاب'), 'ok');
same('«محمد خطاب» تعدّي — أول حرفين مختلفين', $verdict('driver', 'محمد خطاب'), 'ok');
yes('السائق مش في أنواع الاحتواء',
    !in_array('driver', (new ReflectionClass(NS::class))->getConstant('SUBSET_TYPES'), true));
yes('صاحب البيان وصاحب السيارة والعميل كمان مستثنيين',
    !array_intersect(['owner', 'car_owner', 'client'], (new ReflectionClass(NS::class))->getConstant('SUBSET_TYPES')));

// ═════════════════════════════════════ ٣ب) معدّل الإزعاج
sec('٣ب) معدّل الإزعاج — حارس بيزعّق كتير بيتجاهله الموظف');

/**
 * محاكاة «لو القيم الموجودة كانت بتتكتب لأول مرة، الحارس هيوقف كام منها؟»
 * بنشيل القيمة من مصادرها مؤقتًا (جوّه الـtransaction) وبنسأل الحارس.
 * ⚠️ ده أهم فحص في الملف: بفرق حرفين على أسماء الأشخاص المعدّل كان **٣٠٪**،
 *    يعني الموظف بيتعلّم يدوس «ده اسم جديد» من غير ما يقرا — وساعتها الحارس
 *    مش بيحمي حاجة. **لو الرقم ده طلع فوق السقف يبقى الحارس اتوسّع غلط.**
 */
$stopRate = static function (string $type, string $t, string $c): array {
    $rows = Database::all("SELECT `$c` v FROM `$t` WHERE `$c` IS NOT NULL AND TRIM(`$c`) <> '' GROUP BY `$c`");
    $stop = 0;
    foreach ($rows as $r) {
        $v = (string) $r['v'];
        Database::exec("UPDATE `$t` SET `$c` = '__dq_tmp__' WHERE `$c` = ?", [$v]);
        Database::exec("DELETE FROM lookups WHERE list_type = ? AND value = ?", [$type, $v]);
        NS::clearNamesCache();
        if (NS::resolve($type, $v)['conflict'] !== null) { $stop++; }
        Database::exec("UPDATE `$t` SET `$c` = ? WHERE `$c` = '__dq_tmp__'", [$v]);
    }
    $n = count($rows);
    return [$stop, $n, $n ? $stop * 100 / $n : 0.0];
};

[$s, $n, $pct] = $stopRate('driver', 'bawalis', 'driver');
$pct <= 10.0
    ? ok(sprintf('أسماء السائقين: يوقف %d من %d (%.1f%%) — تحت سقف ١٠%%', $s, $n, $pct))
    : bad(sprintf('⚠️ الحارس بيزعّق كتير على السائقين: %.1f%% (السقف ١٠%%)', $pct));

[$s, $n, $pct] = $stopRate('destination', 'bawalis', 'destination');
$pct <= 10.0
    ? ok(sprintf('الجهات: يوقف %d من %d (%.1f%%) — تحت سقف ١٠%%', $s, $n, $pct))
    : bad(sprintf('⚠️ الحارس بيزعّق كتير على الجهات: %.1f%% (السقف ١٠%%)', $pct));

NS::clearNamesCache();

// ═════════════════════════════════════ ٤) التهجئة المعتمدة = الأكثر استخدامًا
sec('٤) التوحيد بيشدّ ناحية التهجئة الغالبة');

$code = php_code("$root/app/Models/NameSuggestion.php");
yes('الأسماء بترجع مرتَّبة بالوزن (استخدام + اسم قانوني)', str_contains($code, 'uksort'));
yes('الاسم القانوني من جدول الأطراف بياخد أولوية مطلقة', str_contains($code, 'MASTER'));
yes('⚠️ الاسم بيترجّع نصًّا لا رقمًا (مفاتيح PHP الرقمية بتبقى int)',
    str_contains($code, '$name = (string) $name'));
same('اسم رقمي بحت بيرجع نصًّا مش int', gettype(NS::resolve('container_type', '40')['value']), 'string');

// أوزان حقيقية: التهجئة الأكتر استخدامًا هي اللي بيتحفظ بيها
Database::exec("DELETE FROM lookups WHERE list_type = 'destination'");
NS::clearNamesCache();
// ⚠️ الفحص على **السائقين** مش الجهات: من v59 الجهات ليها سجل مرجعي وهو
//    اللي بيحكم (الاسم البديل بيتوحّد على اسم السجل حتى لو أقل استخدامًا) —
//    فالجهات ما بتقيسش ترتيب الوزن. السائقين بلا سجل فبيقيسوه صافي.
$dom = Database::row(
    "SELECT driver v, COUNT(*) c FROM bawalis
      WHERE driver IS NOT NULL AND TRIM(driver) <> ''
      GROUP BY driver ORDER BY c DESC LIMIT 1"
);
if ($dom) {
    $r = NS::resolve('driver', (string) $dom['v']);
    same('أكتر سائق استخدامًا بتفضل تهجئته هي المعتمدة', $r['value'], (string) $dom['v']);
    $alt = str_replace(['ي', 'ة'], ['ى', 'ه'], (string) $dom['v']);
    if ($alt !== (string) $dom['v'] && Maintenance::normkey($alt) === Maintenance::normkey((string) $dom['v'])) {
        same('تهجئة بديلة بترجع للغالبة', NS::resolve('driver', $alt)['value'], (string) $dom['v']);
    } else { ok('تهجئة بديلة بترجع للغالبة (لا تنطبق على البيانات الحالية)'); }
} else { ok('لا يوجد سائقون لاختبارهم'); ok('—'); }

// ═════════════════════════════════════ ٥) القائمة بطّلت تتعلّم من الكتابة
sec('٥) القائمة المنسدلة = مفردات معتمدة مش سجلّ لكل ما كُتِب');

// ⚠️ على قائمة **مفتوحة** (`destination`) — القوائم المقفولة ليها فحصها في ٧ب،
//    والإضافة التلقائية فيها مرفوضة أصلاً فما بتقيسش سلوك التحصين ده.
Database::exec("DELETE FROM lookups WHERE list_type = 'destination'");
Database::exec("UPDATE bawalis SET destination = '__dq_hide__' WHERE destination <> ''");
Database::exec("UPDATE permits SET destination = NULL WHERE destination IS NOT NULL");
Lookup::add('destination', 'مدينة طرابلس', true);
NS::clearNamesCache();
$count = static fn(string $t): int => count(Lookup::ofType($t));

Lookup::add('destination', 'طرابلس');
same('«طرابلس» ما دخلتش القائمة (تعارض مفهوم)', $count('destination'), 1);
Lookup::add('destination', 'مدينة طرابلس ');
same('تهجئة تانية لنفس الاسم ما دخلتش (نفس المفتاح)', $count('destination'), 1);
Lookup::add('destination', 'مدينة العريش');
same('جهة جديدة فعلاً بتدخل عادي', $count('destination'), 2);
Lookup::add('destination', 'طرابلس', true);
same('force بيسمح بالإضافة المتعمَّدة من الإعدادات', $count('destination'), 3);

$lk = php_code("$root/app/Models/Lookup.php");
yes('add بتاخد $force للإضافة المتعمَّدة', str_contains($lk, 'bool $force = false'));
yes('add بتنضّف كاش الأسماء بعد الإضافة', str_contains($lk, 'clearNamesCache'));
yes('delete بتنضّف الكاش كمان', substr_count($lk, 'clearNamesCache') >= 2);

/* ⚠️ **شاشة الإعدادات اتقسمت لأجزاء في v76** — قراءة `index.php` لوحده بقت
   بتشوف الهيكل بس. الدالة دي بتجمع الهيكل وكل أجزائه فالفحص بيقيس **الشاشة**
   زي ما المستخدم بيشوفها، مش ملفًا بعينه. */
$settingsTree = static function () use ($root): string {
    $out = readf("$root/app/Views/settings/index.php");
    foreach (glob("$root/app/Views/settings/sections/*.php") ?: [] as $f) { $out .= "\n" . readf($f); }
    return $out;
};
$set = php_code("$root/app/Controllers/SettingsController.php");
yes('شاشة الإعدادات بتوقف وتخيّر قبل ما تفرض', str_contains($set, 'confirm_new'));
yes('وبتضيف بـforce بعد التأكيد', str_contains($set, "Lookup::add(\$type, \$value, true)"));
yes('خانة التأكيد موجودة في الشاشة', str_contains($settingsTree(), 'confirm_new'));
/* الكلاس لازم يكون **متعرَّف** — الخطّاف اللي بيتطبع بلا شكل بيفشل بالسكوت
   (درس `.table-mobile-scroll`). ⚠️ اتنقل من `<style>` محلي لـ`app.css` في v76
   لما الشاشة اتقسمت، والاسم بقى `.st-confirm` — الفحص على **وجود تعريف**
   للكلاس اللي الماركب بيطبعه فعلاً، مش على اسم بعينه في ملف بعينه. */
$confirmClass = preg_match('~class="([a-z-]*st-confirm[a-z- ]*)"~', $settingsTree()) ? 'st-confirm' : '';
yes('كلاس خانة التأكيد متعرَّف (مش خطّاف بلا شكل)',
    $confirmClass !== '' && str_contains(readf("$root/public/assets/css/app.css"), '.' . $confirmClass));

// ═════════════════════════════════════ ٦) مفيش شاشة إدخال بلا حارس
sec('٦) الحارس مركَّب على كل شاشات الإدخال');

$ctrl = php_code("$root/app/Core/Controller.php");
yes('nameGuardError في الكلاس الأساسي (نقطة اختناق واحدة)',
    str_contains($ctrl, 'protected function nameGuardError'));
yes('بيقرا تأكيد «اسم جديد» بنفس عقد الواجهة __newname_',
    str_contains($ctrl, '__newname_'));
yes('الرسالة بتشرح سبب التعارض للمستخدم', str_contains($ctrl, "'concept'") && str_contains($ctrl, "'subset'"));

// ⚠️ تضييق مستوى الوصول في وارث = خطأ قاتل و`php -l` ما بيمسكوش
$narrow = [];
foreach (glob("$root/app/Controllers/*.php") as $f) {
    if (preg_match('/(private|public)\s+function\s+nameGuardError/', php_code($f))) { $narrow[] = basename($f); }
}
yes('مفيش كنترولر بيعيد تعريف nameGuardError (تضييق الوصول = fatal)', $narrow === []);

$crud = php_code("$root/app/Controllers/CrudController.php");
yes('شاشة البوالص /bawalis بقى ليها حارس عند الإضافة والتعديل',
    substr_count($crud, 'nameGuardError($this->nameGuardMap($ent)') >= 2);
yes('والتعديل بيمرّر الصف القديم (عشان قيمة القائمة المقفولة القديمة تعدّي)',
    str_contains($crud, 'nameGuardMap($ent), $before'));
yes('الحارس بيشتغل قبل collect (عشان توحيد التهجئة يوصل للحفظ)',
    strpos($crud, 'nameGuardError') < strpos($crud, '$data = $this->collect($ent)'));
yes('خريطة الحقول مشتقّة من Schema مش مكتوبة بالإيد',
    str_contains($crud, "\$f['lookup']") && str_contains($crud, 'nameGuardMap'));
yes('صاحب البيان (حقل حرّ) محروس كمان', str_contains($crud, "'declaration_owner' => 'owner'"));

$perm = php_code("$root/app/Controllers/PermitController.php");
foreach (['port', 'agency', 'shipping_co', 'container_type'] as $f) {
    // ⚠️ `{$f}` إجبارية — بايتات «» بتتحسب جزءًا من اسم المتغيّر (مصيدة موثَّقة في CLAUDE.md)
    yes("حقل «{$f}» دخل حراسة الإذن (كان بلا حارس خالص)",
        (bool) preg_match("/'$f'\s*=>\s*'$f'/", $perm));
}
yes('الجهة والشركة وتصنيف الحاويات دخلوا حراسة البوليصة جوّه الإذن',
    str_contains($perm, "'destination'=>'destination','company'=>'company','container_type'=>'container_type'"));

// ═════════════════════════════════════ ٧) الواجهة
sec('٧) الواجهة — نفس العقد بين الخادم والمتصفح');

$js = readf("$root/public/assets/js/app.js");
yes('الواجهة بتوقف الحفظ لو فيه اسم متعارض غير محسوم', str_contains($js, 'data-conflict'));
yes('وبتبعت تأكيد «اسم جديد» للخادم', str_contains($js, '__newname_'));
foreach (['dl_port', 'dl_agency', 'dl_shipping', 'dl_ctype'] as $l) {
    yes("الواجهة عارفة قائمة $l", str_contains($js, $l));
}
$form = readf("$root/app/Views/crud/form.php");
yes('حقل نصّي حرّ بياخد data-smart-name من Schema', str_contains($form, "\$f['smart']"));
yes('Schema بيوسم صاحب البيان بنوع اقتراحه', str_contains(readf("$root/app/Schema.php"), "'smart'=>'owner'"));

// ═════════════════════════════════════ ٧ب) القوائم المقفولة
sec('٧ب) القوائم المقفولة — تختار من المعتمد وبس');

same('الأربع قوائم اللي المالك اختارها', Lookup::STRICT, ['port', 'container_type', 'agency', 'shipping_co']);
yes('الجهة والسائق وصاحب البيان **مفتوحين** (مفرداتهم بتكبر كل يوم)',
    !array_intersect(['destination', 'driver', 'car_owner', 'client', 'company'], Lookup::STRICT));

// القائمة المقفولة ما بتتغذّاش من الإدخال اليومي
Database::exec("DELETE FROM lookups WHERE list_type = 'port'");
Lookup::add('port', 'ميناء طرابلس', true);
Lookup::add('port', 'ميناء العريش');            // مسار تلقائي
same('الإضافة التلقائية مرفوضة على القائمة المقفولة', count(Lookup::ofType('port')), 1);
Lookup::add('port', 'ميناء العريش', true);      // قرار متعمَّد من الإعدادات
same('الإضافة المتعمَّدة من الإعدادات شغّالة', count(Lookup::ofType('port')), 2);

// ── الخادم بيرفض أي قيمة برّه القائمة (الفورم مش مصدر ثقة) ──────────────
NS::clearNamesCache();
$guard = new class extends \App\Core\Controller {
    public function check(array $map, array $existing = []): ?string { return $this->nameGuardError($map, $existing); }
};
$_POST = ['port' => 'ميناء طرابلس'];
yes('قيمة من القائمة بتعدّي', $guard->check(['port' => 'port']) === null);
$_POST = ['port' => 'ميناء طرابلس '];
$guard->check(['port' => 'port']);
same('التهجئة بتتوحّد على المعتمدة', $_POST['port'], 'ميناء طرابلس');
$_POST = ['port' => 'ميناء صلالة'];
yes('قيمة برّه القائمة بترفض', $guard->check(['port' => 'port']) !== null);
$_POST = ['port' => 'ميناء صلالة', '__newname_port' => '1'];
yes('⚠️ «ده اسم جديد» **مش** بيتخطّى القائمة المقفولة (ده الفرق عن الحارس)',
    $guard->check(['port' => 'port']) !== null);
$_POST = ['port' => 'الإسكندرية'];
yes('⚠️ القيمة القديمة بتعدّي على سجلها (وإلا تعديل إذن قديم يغيّر ميناءه)',
    $guard->check(['port' => 'port'], ['port' => 'الإسكندرية']) === null);
$_POST = ['port' => 'الإسكندرية'];
yes('لكنها بترفض على سجل تاني', $guard->check(['port' => 'port'], ['port' => 'ميناء دمياط']) !== null);
$_POST = [];

// ── الشاشات ──────────────────────────────────────────────────────────────
$screens = [
    'app/Views/permits/form.php'          => ['port', 'agency', 'shipping_co', 'container_type'],
    'app/Views/permits/_body_manage.php'  => ['port', 'agency', 'shipping_co', 'container_type'],
    'app/Views/permits/_movement.php'     => ['container_type'],
    'app/Views/permits/invoice_form.php'  => ['port', 'agency'],
    'app/Views/movements/form.php'        => ['port', 'agency', 'shipping_co'],
    'app/Views/m/permit_new.php'          => ['container_type'],
];
foreach ($screens as $file => $fields) {
    $src = readf("$root/$file");
    $freeText = false;
    foreach ($fields as $f) {
        // خانة كتابة حرة باسم الحقل = ثغرة
        if (preg_match('/<input[^>]*name="' . $f . '"/', $src)) { $freeText = true; }
    }
    yes(basename($file) . ' — مفيش خانة كتابة حرة لحقل مقفول', !$freeText);
    yes(basename($file) . ' — بيستخدم المكوّن المشترك', str_contains($src, "partial('lookup_select'"));
}
yes('شاشة البوالص بتفرّق بين المقفول والمفتوح',
    str_contains(readf("$root/app/Views/crud/form.php"), 'Lookup::isStrict'));

$ls = readf("$root/app/Views/partials/lookup_select.php");
yes('⚠️ المكوّن بيحطّ القيمة القديمة كخيار (وإلا select بيوقع على أول خيار ويغيّرها بالسكوت)',
    str_contains($ls, '$legacy') && str_contains($ls, 'قيمة قديمة'));
yes('المكوّن بلا سكربت (تايم لاين الأذونات بيتعاد رسمه بالـAJAX)', !str_contains($ls, '<script'));
yes('بوابة المندوب بتفرض القائمة على الخادم كمان',
    str_contains(php_code("$root/app/Models/RepPortal.php"), 'strictValue'));

/* ── ٧ب-٢) شركة الشحن بتقبل الوكلاء كمان (قرار المالك 2026-08-02) ──────────
   بلاغ المالك: «شركة الشحن بتكون هي نفس الوكيل لكنه بيحجمني في اختيارها».
   مقيس وقتها: ٥٦ من ٨٧ إذن شركة الشحن فيهم = العميل بالحرف.
   ⚠️ الفحص على **الثابت** لا على عدد بيانات اليوم: (أ) المسموح ⊇ القائمة الخام،
      (ب) الوكيل المسجَّل مقبول، (ج) الاسم المخترع لسه مرفوض (حماية الأخطاء
      الإملائية ما اتفكّتش)، (د) الأنواع التانية ما اتغيّرتش. */
$rawShip = Lookup::ofType('shipping_co');
$allShip = Lookup::allowedFor('shipping_co');
yes('المسموح في شركة الشحن ⊇ القائمة الخام', count($allShip) >= count($rawShip));
yes('وبلا تكرار بالمفتاح',
    count($allShip) === count(array_unique(array_map([Maintenance::class, 'normkey'], $allShip))));
foreach (['port', 'agency', 'container_type'] as $t) {
    yes("القائمة المقفولة «$t» ما اتغيّرتش", Lookup::ofType($t) === Lookup::allowedFor($t));
}
$anAgent = Database::scalar('SELECT name FROM agents ORDER BY id LIMIT 1');
if ($anAgent !== null && $anAgent !== '') {
    $inList = false;
    foreach ($allShip as $v) { if (Maintenance::normkey((string) $v) === Maintenance::normkey((string) $anAgent)) { $inList = true; break; } }
    yes('★ وكيل مسجَّل مقبول كشركة شحن', $inList);
}
$fake = 'شركة شحن مخترعة ' . bin2hex(random_bytes(3));
$bad = true;
foreach ($allShip as $v) { if (Maintenance::normkey((string) $v) === Maintenance::normkey($fake)) { $bad = false; break; } }
yes('★ واسم مخترع لسه مرفوض (الحماية ما اتفكّتش)', $bad);
/* ⚠️ **الثلاثة لازم يقروا من `allowedFor` مع بعض** — لو المكوّن بيرسم خيارًا
     والخادم بيرفضه، المستخدم مش فاهم غلط في إيه. */
yes('المكوّن بيقرا `allowedFor` مش `ofType`', str_contains($ls, 'Lookup::allowedFor('));
yes('و`Controller::strictListError` كمان',
    str_contains(php_code("$root/app/Core/Controller.php"), 'Lookup::allowedFor('));
yes('و`RepPortal::strictValue` كمان',
    str_contains(php_code("$root/app/Models/RepPortal.php"), 'Lookup::allowedFor('));

/* ── ٧ب-٣) التعبئة التلقائية: شركة الشحن = العميل ─────────────────────────
   ⚠️ **الشاشة بس — مفيش تعبئة على الخادم**: كتابة قيمة المستخدم مشافهاش في
      حقل بيدخل بيانات الإذن أسوأ من إنه يختارها بنفسه. */
$autoJs = "$root/public/assets/js/lookup-autofill.js";
yes('ملف التعبئة التلقائية موجود', is_file($autoJs));
$aj = (string) @file_get_contents($autoJs);
yes('★ ما بيدهسش اختيار المستخدم (بيملا الفاضي أو اللي هو حطّه)',
    str_contains($aj, "target.dataset.auto !== '1'"));
yes('والاستماع في مرحلة الالتقاط (فيه معالجات تانية بتوقف الحدث)',
    substr_count($aj, ', true);') >= 2);
foreach ([['app/Views/layout.php', 'شاشات المكتب'], ['app/Views/m/_pwa.php', 'بوابة المندوب']] as [$f, $lbl]) {
    yes("الملف متحمَّل في $lbl", str_contains((string) @file_get_contents("$root/$f"), 'lookup-autofill.js'));
}
foreach ([['app/Views/permits/form.php', 'إنشاء إذن'],
          ['app/Views/permits/_body_manage.php', 'تعديل إذن'],
          ['app/Views/m/permit_new.php', 'إذن المندوب']] as [$f, $lbl]) {
    yes("خانة شركة الشحن موسومة في $lbl",
        str_contains((string) @file_get_contents("$root/$f"), 'data-autofill-from'));
}
/* ⚠️ **التسمية لازم تبقى واحدة في الشاشتين** — كانت `shipping_co` مكتوب عليها
     «التوكيل الملاحي» في شاشة المندوب و`agency` «التوكيل»، فالمندوب يشوف حقلين
     بنفس الاسم (بلاغ المالك 2026-08-02). */
// ⚠️ `php_code` مش `file_get_contents`: التعليق اللي بيوثّق القاعدة بيذكر
//    النصّين، فالعدّ الخام بيدّي **فشلًا كاذبًا** (المصيدة الموثَّقة في
//    `verify_security` و`verify_ux` — وقعت هنا فعلاً وأنا بكتب الفحص).
$mp = php_code("$root/app/Views/m/permit_new.php");
yes('★ شاشة المندوب: «شركة الشحن» مرة واحدة', substr_count($mp, 'شركة الشحن') === 1);
yes('★ و«التوكيل الملاحي» مرة واحدة', substr_count($mp, 'التوكيل الملاحي') === 1);
yes('وحقول القوائم المقفولة بقت `lookup_select` (كانت كتابة حرة بتترفض)',
    substr_count($mp, "'type'  => \$name") >= 1 || substr_count($mp, "partial('lookup_select'") >= 2);

// ── against() بتقارن بالقائمة وحدها ──────────────────────────────────────
$ap = ['ميناء الإسكندرية', 'ميناء دمياط'];
same('against() بتمسك «الإسكندرية» مقابل القائمة', NS::against('port', 'الإسكندرية', $ap)['reason'], 'concept');
yes('وبتعدّي ميناء جديد فعلاً', NS::against('port', 'ميناء العريش', $ap)['conflict'] === null);

// ═════════════════════════════════════ ٧ج) شاشة التنظيف
sec('٧ج) شاشة التنظيف — بتنضّف القديم بقرار المالك');

$dq = 'App\Models\DataQuality';
yes('خريطة الحقول من `Maintenance::groups()` (مصدر واحد)',
    str_contains(php_code("$root/app/Models/DataQuality.php"), 'Maintenance::groups()'));
yes('كل حقل محروس ليه مدخل في الخريطة',
    !array_diff(['driver','car_owner','company','client','destination','classification',
                 'container_type','owner','port','agency','shipping_co'], array_keys($dq::fields())));

// ⚠️ الأداء: الشارة بتتنادى كل ٦٠ ثانية لكل مستخدم
$t = microtime(true); $dq::badgeCount(); $badgeMs = (microtime(true) - $t) * 1000;
$badgeMs < 20
    ? ok(sprintf('الشارة مكاشة (%.1f مللي ثانية) — الجرس ما بيحسبش', $badgeMs))
    : bad(sprintf('⚠️ الشارة بطيئة (%.0f مللي) — الجرس بينادي كل ٦٠ث لكل مستخدم', $badgeMs));
$nw = php_code("$root/app/Models/NotifyWatch.php");
yes('إعادة الحساب في الكرون لا في الجرس', str_contains($nw, 'refreshBadge'));
$nf = php_code("$root/app/Models/Notification.php");
yes('⚠️ `Notification` فضلت قراءة فقط (بتنادي المكاش مش الحساب)',
    str_contains($nf, 'badgeCount') && !str_contains($nf, 'refreshBadge'));

// ── دمج حقيقي + الحمايات ─────────────────────────────────────────────────
// ⚠️ **بنزرع المجموعة بنفسنا.** الاعتماد على بيانات اليوم بيدّي **نجاحًا كاذبًا**:
//    القسم ٥ بيفضّي عمود الجهات، فلو دوّرنا على مجموعة موجودة مش هنلاقي حاجة
//    والفحص «يعدّي» وهو ما جرّبش الدمج أصلاً (اتصادف فعليًّا).
$ids = array_column(Database::all('SELECT id FROM bawalis ORDER BY id LIMIT 3'), 'id');
if (count($ids) >= 3) {
    $ph3 = implode(',', array_fill(0, 3, '?'));
    Database::exec("UPDATE bawalis SET destination = 'مدينة طرابلس' WHERE id IN ($ph3)", $ids);
    Database::exec('UPDATE bawalis SET destination = ? WHERE id = ?', ['مدينه طرابلس', $ids[2]]);
    Database::exec("DELETE FROM lookups WHERE list_type = 'destination'");
    NS::clearNamesCache();

    $c = $dq::clusters('destination');
    $cluster = null;
    foreach ($c['same'] as $g) {
        if (isset($g['members']['مدينة طرابلس'], $g['members']['مدينه طرابلس'])) { $cluster = $g; break; }
    }
    yes('التهجئتان اتلمّوا في مجموعة واحدة', $cluster !== null);

    if ($cluster) {
        $canon    = 'مدينة طرابلس';   // ٢ سجل مقابل ١
        $variants = ['مدينه طرابلس'];
        $r = $dq::merge('destination', $canon, $variants);
        same('كل السجلات اتحوّلت للاسم المعتمد',
            (int) Database::scalar('SELECT COUNT(*) FROM bawalis WHERE destination = ?', ['مدينه طرابلس']), 0);
        same('والتلات سجلات بقوا على الاسم المعتمد',
            (int) Database::scalar('SELECT COUNT(*) FROM bawalis WHERE destination = ?', [$canon]), 3);
        yes('الدمج بيرجّع عدد الصفوف اللي اتغيّرت', $r['rows'] >= 1);
        yes('الاسم المعتمد اتحطّ في القائمة المنسدلة',
            in_array($canon, Lookup::ofType('destination'), true));
        same('ومفيش مجموعة «نفس الشيء» فاضلة للاسم ده', $dq::sameCount('destination'), 0);
    } else { bad('الزرع فشل — الفحص ما جرّبش الدمج'); }
} else { bad('مفيش بوالص كفاية للزرع'); }

$partyBlocked = false;
try { $dq::merge('company', 'أ', ['ب']); } catch (\Throwable $e) { $partyBlocked = true; }
yes('⚠️ الأسماء اللي ليها سجل قانوني بترفض الدمج من هنا (لازم PartyMaster::merge)', $partyBlocked);

$usedBlocked = false;
$used = array_filter($dq::values('destination'), static fn($n) => $n > 0);
if ($used) {
    try { $dq::dropLookup('destination', (string) array_key_first($used)); }
    catch (\Throwable $e) { $usedBlocked = true; }
}
yes('المفردة المستخدَمة مش بتتشال من القائمة (بترشد للدمج)', $usedBlocked);

// ── الصلاحية والمسارات ───────────────────────────────────────────────────
yes('التنظيف للمالك والمدير بس', str_contains(php_code("$root/app/Core/Access.php"), "'QualityController@*'"));
$idx = readf("$root/public/index.php");
yes('المسارات الحرفية قبل الجشع /quality/{key}',
    strpos($idx, "/quality/{key}/merge") < strpos($idx, "\$r->get('/quality/{key}'"));
yes('زرار «توحيد المسميات» الأعمى اتشال من الإعدادات',
    !str_contains($settingsTree(), "settings/normalize"));
yes('وبقى رابط لشاشة التنظيف', str_contains($settingsTree(), "url('quality')"));
yes('بند القائمة الجانبية موجود', str_contains(readf("$root/app/Models/NavModule.php"), "'QualityController@index'"));

// ═════════════════════════════════════ ٧د) السجل المرجعي للجهات
sec('٧د) السجل المرجعي — الغلطة تتصلّح مرة واحدة وتفضل متصلّحة');

$hasReg = (bool) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = 'destination_points'"
);
if (!$hasReg) {
    bad('جدول destination_points مش موجود — شغّل migrate_v33/34 ثم v59');
} else {
    $coordNullable = (string) Database::scalar(
        "SELECT is_nullable FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'destination_points' AND column_name = 'lat'"
    );
    same('الإحداثيات اختيارية (الجهة تتسجّل بالاسم بس)', $coordNullable, 'YES');
    yes('⚠️ تقدير المسافة بيتخطّى الجهة بلا إحداثيات (مش بيحسب من 0,0)',
        str_contains(php_code("$root/app/Models/Track.php"), "\$p['lat'] === null"));

    // كل جهة مستخدَمة لازم تكون معروفة (بالمفتاح أو باسم بديل)
    $known = [];
    foreach (Database::all('SELECT normkey, aliases FROM destination_points') as $p) {
        $known[(string) $p['normkey']] = true;
        foreach (explode('|', (string) $p['aliases']) as $a) {
            $a = trim($a); if ($a !== '') { $known[$a] = true; }
        }
    }
    same('كل جهة مستخدَمة معروفة للسجل (مقاسة قبل زرع الفحص)', $orphanDestAtStart, 0);
    yes('⚠️ الترحيل ما بيسجّلش المتغيّرات اللي بتتحلّ جزئيًّا (بتكسر تقدير المسافة)',
        str_contains(readf("$root/bin/migrate_v59.php"), "'exact', 'alias', 'partial'"));

    // ⚠️ الترحيل ما يسجّلش نقطة لاسم هو أصلاً **اسم بديل** لنقطة تانية
    $dupAlias = 0;
    foreach (Database::all('SELECT id, normkey FROM destination_points') as $p) {
        foreach (Database::all('SELECT id, aliases FROM destination_points WHERE id <> ?', [$p['id']]) as $o) {
            foreach (explode('|', (string) $o['aliases']) as $a) {
                if (trim($a) !== '' && trim($a) === (string) $p['normkey']) { $dupAlias++; }
            }
        }
    }
    same('⚠️ مفيش نقطة اسمها اسم بديل لنقطة تانية (باج الترحيل الأول)', $dupAlias, 0);

    // ── التوحيد التلقائي عبر السجل ───────────────────────────────────────
    NS::clearNamesCache();
    $seedKey = Maintenance::normkey('مدينة طنطا');
    Database::exec('DELETE FROM destination_points WHERE normkey = ?', [$seedKey]);
    Database::exec('INSERT INTO destination_points (name, normkey, aliases, lat, lng) VALUES (?,?,?,NULL,NULL)',
        [$Ndest = 'مدينة طنطا', $seedKey, Maintenance::normkey('طنطا البلد')]);
    \App\Models\Track::clearPointsCache(); NS::clearNamesCache();

    same('الاسم البديل بيتوحّد على المعتمد تلقائيًا',
        NS::resolve('destination', 'طنطا البلد')['value'], $Ndest);
    same('والاسم المعتمد بيعدّي زي ما هو',
        NS::resolve('destination', $Ndest)['value'], $Ndest);
    yes('⚠️ التطابق الجزئي/الإملائي **مش** بيوحّد تلقائيًا (بدرشين ≠ بدر)',
        str_contains(php_code("$root/app/Models/NameSuggestion.php"), "['exact', 'alias']"));

    // ── الدمج بيعلّم السجل ───────────────────────────────────────────────
    $ids = array_column(Database::all('SELECT id FROM bawalis ORDER BY id LIMIT 2'), 'id');
    if (count($ids) >= 2) {
        Database::exec('UPDATE bawalis SET destination = ? WHERE id = ?', [$Ndest, $ids[0]]);
        Database::exec('UPDATE bawalis SET destination = ? WHERE id = ?', ['طنطا الجديدة', $ids[1]]);
        NS::clearNamesCache();
        $dq::merge('destination', $Ndest, ['طنطا الجديدة']);
        \App\Models\Track::clearPointsCache(); NS::clearNamesCache();

        $al = (string) Database::scalar('SELECT aliases FROM destination_points WHERE normkey = ?', [$seedKey]);
        yes('الدمج سجّل التهجئة المدموجة اسمًا بديلًا',
            str_contains($al, Maintenance::normkey('طنطا الجديدة')));
        same('فأول ما تتكتب تاني تتوحّد لوحدها — مفيش دمج يدوي مرّة تانية',
            NS::resolve('destination', 'طنطا الجديدة')['value'], $Ndest);
    }

    // ── الوسم الاسترشادي ─────────────────────────────────────────────────
    $hasTag = (bool) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'bawalis' AND column_name = 'destination_id'"
    );
    yes('وسم رقم الجهة موجود على البوالص والأذونات', $hasTag);
    yes('⚠️ الوسم استرشادي — مفيش حساب مالي بيقرا destination_id',
        !preg_match('/destination_id/', php_code("$root/app/Models/Closing.php")
            . php_code("$root/app/Models/Custody.php") . php_code("$root/app/Models/Calc.php")));
}

// ═════════════════════════════════════ ٨) العزل المالي
sec('٨) العزل — شغل جودة البيانات ما بيمسّش أي فلوس');

foreach (['NameSuggestion', 'Lookup'] as $m) {
    $c = php_code("$root/app/Models/$m.php");
    yes("$m ما بيلمسش أي جدول مالي",
        !preg_match('/\b(bill_payments|manual_expenses|manual_custody|daily_closings|permit_stages)\b/', $c));
}

} finally {
    Database::conn()->rollBack();
    NS::clearNamesCache();
}

$balanceAfter = (float) Custody::balance();
abs($balanceAfter - $balanceBefore) < 0.005
    ? ok('رصيد الخزنة قبل = بعد (' . number_format($balanceBefore, 2) . ')')
    : bad('⚠️ رصيد الخزنة اتغيّر: ' . $balanceBefore . ' ← ' . $balanceAfter);

echo "\n" . str_repeat('=', 62) . "\n";
echo "نجح: $pass   |   فشل: $fail\n\n";
echo $fail === 0 ? "✓ كل الفحوصات عدّت.\n" : "✗ فيه فحوصات فشلت.\n";
exit($fail === 0 ? 0 : 1);
