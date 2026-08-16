<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص v97 — محرّك العادات: النظام بيقترح من معاملات العميل السابقة.
 *
 * طلب المالك: «عاوز النظام يتوقع من المعاملات السابقة للشركات أو الوكلاء جهة
 * الإذن، لأن بنسبة ٩٩٪ الإذن بيكون لنفس الجهة كل مرة».
 *
 * أهم اللي بيتأكد منه:
 *   ① **السلوك** بـfixtures مزروعة جوّه transaction: سلسلة المفاتيح
 *      (عميل+شركة ← شركة ← عميل) · حد الدليل الأدنى بيسكّت · النافذة الزمنية
 *      بتستبعد القديم · والتهجئات المختلفة بتتجمّع كيانًا واحدًا.
 *   ② **الحارس الأهم: الخادم ما بيملاش.** الفورم بيترندر **من غير أي
 *      جافاسكريبت** والحقول بترجع فاضية رغم إن العادة موجودة في القاعدة.
 *      دي القاعدة اللي بتخلّي الميزة آمنة — أسوأ حاجة إنها ما تشتغلش، مش
 *      إنها تكتب قيمة المالك مشافهاش.
 *   ③ **قائمة الحقول مقفولة** — الحقل بيتحطّ في `GROUP BY` مباشرةً، فالقائمة
 *      هي الحاجز الوحيد ضد حقن SQL.
 *   ④ **قراءة بحتة** — ولا كتابة واحدة في `Habit`.
 *   ⑤ **صفر تصميم جديد** — كل كلاس في الجافاسكريبت له تعريف في `app.css`،
 *      والملف **مش متحمّل في `/m`** لأن البوابة ما بتحمّلش `app.css`.
 *   ⑥ **حُرّاس المسار** — `requireAuth` + `exit` بعد `json()`.
 *
 * الـfixtures جوّه transaction + rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_v97.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Habit;

$pass = 0; $fail = 0;
function ok97(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad97(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function chk97(bool $c, string $m, string $why = ''): void {
    $c ? ok97($m) : bad97($m . ($why !== '' ? " — {$why}" : ''));
}
function sec97(string $m): void { echo "\n$m\n" . str_repeat('─', 62) . "\n"; }

/** ⚠️ كود بلا تعليقات — التعليق اللي بيوثّق القاعدة بيذكر النص الممنوع نفسه. */
function code97(string $file): string {
    $p = dirname(__DIR__) . '/' . $file;
    if (!is_file($p)) { return ''; }
    $src = (string) file_get_contents($p);
    if (str_ends_with($file, '.js')) {
        $src = preg_replace('~/\*.*?\*/~s', '', $src) ?? $src;
        return preg_replace('~^\s*//[^\n]*$~m', '', $src) ?? $src;
    }
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

echo "فحص v97 — محرّك العادات\n" . str_repeat('=', 62) . "\n";

$root = dirname(__DIR__);
$pdo  = Database::conn();
$pdo->beginTransaction();

try {
    // ═══════════════════════════════════════ ① السلوك على بيانات مزروعة
    sec97('① سلسلة المفاتيح: عميل+شركة ← شركة ← عميل');

    $stamp   = 'ف٩٧-' . substr((string) getmypid(), -4);
    $clientA = "عميل فحص {$stamp}";
    $compA   = "شركة ألف {$stamp}";
    $compB   = "شركة باء {$stamp}";

    /** إذن وهمي — الحد الأدنى من الأعمدة. */
    $mk = static function (string $client, string $company, string $dest, string $date, string $port = '') {
        Database::exec(
            'INSERT INTO permits (permit_type, permit_no, permit_date, client, company, destination, port, containers)
             VALUES (?,?,?,?,?,?,?,0)',
            ['sader', 'V97-' . substr(md5(uniqid('', true)), 0, 8), $date, $client, $company, $dest, $port]
        );
    };

    $today = date('Y-m-d');
    $old   = date('Y-m-d', strtotime('-800 days'));

    // الشركة ألف: ٣ أذونات لـ«سفاجا» — دليل قوي على مستوى (عميل+شركة)
    $mk($clientA, $compA, 'سفاجا', $today);
    $mk($clientA, $compA, 'سفاجا', $today);
    $mk($clientA, $compA, 'سفاجا', $today);
    // الشركة باء: جهة تانية مرة واحدة بس — دليل ضعيف
    $mk($clientA, $compB, 'قنا', $today);

    $both = Habit::top('destination', ['client' => $clientA, 'company' => $compA]);
    chk97($both !== null && $both['value'] === 'سفاجا' && $both['scope'] === 'العميل والشركة',
        'المفتاح الأدق (عميل+شركة) بيكسب لما دليله كافي',
        json_encode($both, JSON_UNESCAPED_UNICODE));

    /* الشركة باء عندها ملاحظة واحدة بس ⇒ المفتاح ده بيسقط، والسلسلة بتنزل
       لمستوى العميل اللي فيه ٤ أذونات (٣ سفاجا + ١ قنا = ٧٥٪). */
    $fallback = Habit::top('destination', ['client' => $clientA, 'company' => $compB]);
    chk97($fallback !== null && $fallback['value'] === 'سفاجا' && $fallback['scope'] === 'العميل',
        'الدليل الضعيف بينزل للمفتاح الأعم بدل ما يخمّن',
        json_encode($fallback, JSON_UNESCAPED_UNICODE));

    sec97('② حد الدليل الأدنى — الصمت أنضف من التخمين');

    $lonely = "عميل يتيم {$stamp}";
    $mk($lonely, "شركة يتيمة {$stamp}", 'العريش', $today);
    chk97(Habit::top('destination', ['client' => $lonely]) === null,
        'ملاحظة واحدة = مفيش اقتراح (المصداقية رأس مال الاقتراح)');

    $split = "عميل متردّد {$stamp}";
    $mk($split, "شركة ج {$stamp}", 'طنطا', $today);
    $mk($split, "شركة ج {$stamp}", 'دمياط', $today);
    chk97(Habit::top('destination', ['client' => $split]) === null,
        'نص ونص (٥٠٪) تحت الحد فبيسكت');

    $mk($split, "شركة ج {$stamp}", 'طنطا', $today);
    $now = Habit::top('destination', ['client' => $split]);
    chk97($now !== null && $now['value'] === 'طنطا' && $now['hits'] === 2 && $now['total'] === 3,
        'وأول ما الثبات يعدّي الحد بيتكلّم بالأرقام الصح (٢ من ٣)',
        json_encode($now, JSON_UNESCAPED_UNICODE));

    sec97('③ النافذة الزمنية — العادة الميتة ما بتشدّش الاقتراح');

    $aged = "عميل قديم {$stamp}";
    $mk($aged, "شركة د {$stamp}", 'أسوان', $old);
    $mk($aged, "شركة د {$stamp}", 'أسوان', $old);
    $mk($aged, "شركة د {$stamp}", 'أسوان', $old);
    chk97(Habit::top('destination', ['client' => $aged]) === null,
        'ثلاث أذونات أقدم من سنة = كأنها مش موجودة');

    /* ⚠️ المقارنة بتحصل **جوّه SQL** (`CURDATE()`): قاعدة الإنتاج UTC وPHP
       القاهرة، وحسابها في PHP بيغلط بيوم على الحدود (القاعدة ④ في CLAUDE.md). */
    $habitCode = code97('app/Models/Habit.php');
    chk97(str_contains($habitCode, 'CURDATE()') && !preg_match('~strtotime\(|date\(\s*.Y-m-d~', $habitCode),
        'وحساب النافذة جوّه SQL مش في PHP — فرق التوقيت ما بيدخلش الحساب');

    sec97('④ توحيد التهجئة — نفس العميل مش كيانين');

    /* «المصرية الألمانية» و«المصريه الالمانيه» لازم يتجمّعوا. لو اتفرّقوا،
       الثبات بينزل النص والاقتراح بيسكت غلط. الاختبار على السلوك: بنسأل
       بتهجئة والبيانات مكتوبة بتهجئة تانية. */
    chk97(count(Habit::spellings('agent', $clientA)) >= 1,
        'كل اسم بيرجّع تهجئاته المعروفة (والاسم نفسه على الأقل)');
    chk97(Habit::spellings('agent', '   ') === [],
        'والاسم الفاضي بيرجّع لستة فاضية مش استعلامًا على كل الجدول');
    chk97(str_contains($habitCode, 'PartyMaster::'),
        'والتوحيد بيمرّ على `PartyMaster` — مش مقارنة نصّية خام');

    sec97('⑤ قائمة الحقول مقفولة — الحاجز ضد حقن SQL');

    chk97(Habit::top('destination; DROP TABLE permits', ['client' => $clientA]) === null,
        'حقل برّه القائمة بيرجّع NULL **قبل** أي استعلام');
    chk97(Habit::samples("id`,(SELECT 1)`", ['client' => $clientA]) === [],
        'ونفس الحاجز في نافذة التفاصيل');
    chk97(!isset(Habit::FIELDS['shipping_co']),
        '⚠️ و`shipping_co` مستثناة — عندها قاعدة ثابتة مقيسة، ونظامين على خانة واحدة = تصادم');

    sec97('⑥ ⚠️ الحارس الأهم: الخادم ما بيملاش أي حقل');

    /* ⚠️ **القلب**: بنرندر الفورم على إذن جديد لعميل **عنده عادة قوية**
       (`$clientA` ← سفاجا)، ولو الخادم كتب القيمة يبقى المالك ممكن يحفظ
       بيانات مشافهاش. الجافاسكريبت مش موجود هنا خالص — الرندر خادمي بحت. */
    $ctrl = new class extends App\Core\Controller {
        public function form(array $p, array $lookups): string
        {
            ob_start();
            $this->render('permits/form', [
                'p' => $p, 'isEdit' => false, 'action' => 'permits',
                'formToken' => 'test', 'errors' => [], 'lookups' => $lookups,
                'nameMap' => ['agentCompanies' => [], 'companyAgents' => []],
                'back' => 'permits',
            ], false);
            return (string) ob_get_clean();
        }
    };
    $blank = [
        'id' => 0, 'permit_type' => 'sader', 'permit_no' => '', 'permit_date' => '',
        'client' => $clientA, 'company' => $compA, 'destination' => '', 'shipping_co' => '',
        'agency' => '', 'port' => '', 'containers' => 0, 'container_type' => '',
        'inspection_rate' => '', 'guarantee_rate' => '', 'note' => '',
    ];
    $html = $ctrl->form($blank, \App\Models\Lookup::allGrouped());

    chk97($html !== '' && !str_contains($html, 'Fatal') && !str_contains($html, 'Undefined'),
        'فورم الإذن بيترندر بلا أي تحذير PHP');
    chk97(preg_match('~name="destination"[^>]*value="\s*"~', $html) === 1
          || preg_match('~name="destination"(?![^>]*value="[^"]+")~', $html) === 1,
        '**الجهة رجعت فاضية** رغم إن العادة موجودة في القاعدة — الخادم ما كتبش');
    /* ⚠️ **مصيدة في الفحص نفسه:** النسخة الأولى كانت بتقول «القيمة ما تظهرش
       في الماركب خالص» فأدّت إنذارًا كاذبًا — «سفاجا» بتظهر فعلاً كـ`<option>`
       جوّه `dl_dest`، ودي **قائمة الاقتراحات العادية** مش تعبئة. السؤال الصح
       مش «الكلمة موجودة؟» لأ: **«الخانة مختارة؟»**. */
    foreach (['agency', 'port', 'container_type'] as $sel) {
        $picked = preg_match('~<select name="' . $sel . '".*?</select>~s', $html, $mm) === 1
            && preg_match('~<option value="(?!")[^"]+"[^>]*selected~', $mm[0]) === 1;
        chk97(!$picked, "و`{$sel}` مفيهاش خيار مختار — القائمة المقفولة رجعت على «— اختر —»");
    }
    chk97(substr_count($html, 'data-habit=') === 4,
        'وأربع خانات موسومة للاقتراح (جهة · توكيل · ميناء · تصنيف)',
        'الموجود: ' . substr_count($html, 'data-habit='));

    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    bad97('استثناء أثناء الفحص: ' . $e->getMessage());
}

// ═══════════════════════════════════════ ⑦ قراءة بحتة
sec97('⑦ العزل — `Habit` ما بيكتبش ولا صف');

$habitCode = code97('app/Models/Habit.php');
foreach (['INSERT', 'UPDATE ', 'DELETE', 'ALTER', 'DROP'] as $w) {
    chk97(stripos($habitCode, $w) === false, "مفيش `{$w}` في المحرّك");
}
chk97(!preg_match('~\b(closing|company_safe|driver_custody|bill_payments)\b~i', $habitCode),
    'ولا ذكر لخزنة ولا تقفيل ولا عهدة — الوحدة برّه أي حساب مالي');

// ═══════════════════════════════════════ ⑧ صفر تصميم جديد
sec97('⑧ صفر تصميم جديد — الاقتراح لبس هدوم حارس الأسماء');

$css = (string) file_get_contents($root . '/public/assets/css/app.css');
$js  = code97('public/assets/js/habit-suggest.js');

/* كل كلاس بيتحقن من الجافاسكريبت لازم يكون له تعريف — «كلاس في الماركب بلا
   CSS بيقع على الشكل الافتراضي بالسكوت» (مصيدة `.table-mobile-scroll`). */
$classes = [];
if (preg_match_all('~class="([a-z0-9 \-]+)"~i', $js, $m)) {
    foreach ($m[1] as $chunk) {
        foreach (preg_split('~\s+~', trim($chunk)) as $cls) {
            if ($cls !== '') { $classes[$cls] = true; }
        }
    }
}
if (preg_match_all("~className\s*=\s*'([a-z0-9 \-]+)'~i", $js, $m2)) {
    foreach ($m2[1] as $chunk) {
        foreach (preg_split('~\s+~', trim($chunk)) as $cls) {
            if ($cls !== '') { $classes[$cls] = true; }
        }
    }
}
chk97($classes !== [], 'الفحص لقى كلاسات فعلاً (نمط ما بيمسكش حاجة بينجح للأبد)');
$missing = array_values(array_filter(array_keys($classes),
    static fn (string $c) => !str_contains($css, '.' . $c)));
chk97($missing === [], 'كل كلاس في `habit-suggest.js` له تعريف في `app.css`',
    implode(' · ', $missing));

/* ⚠️ بوابة `/m` ما بتحمّلش `app.css` — فتحميل الملف هناك معناه سطر بكلاسات
   بلا شكل. القرار: مش متحمّل، والفاحص بيحرس القرار ده. */
$pwa = (string) file_get_contents($root . '/app/Views/m/_pwa.php');
$mCss = is_file($root . '/public/assets/css/m.css')
    ? (string) file_get_contents($root . '/public/assets/css/m.css') : '';
chk97(!str_contains($pwa, 'habit-suggest.js') || str_contains($mCss, '.smart-name-note'),
    'الملف مش متحمّل في `/m` (أو `m.css` خدت كلاساته) — مفيش سطر بلا شكل');

$layout = (string) file_get_contents($root . '/app/Views/layout.php');
chk97(str_contains($layout, 'habit-suggest.js') && str_contains($layout, '__HABIT_SUGGEST_API'),
    'ومتحمّل في شاشات المكتب ومعاه مساره');

// ═══════════════════════════════════════ ⑨ حُرّاس المسار
sec97('⑨ حُرّاس المسار — الرفض هو الافتراضي');

$ctrlCode = code97('app/Controllers/SuggestionController.php');
chk97(substr_count($ctrlCode, 'requireAuth()') >= 3,
    'كل أكشن في الكنترولر بينده `requireAuth()` بإيده (مفيش middleware في النظام)');

/* ⚠️ `Controller::json()` بتطبع وما بتوقفش — أي تحذير PHP بعدها بيتلزق جوّه
   الـJSON ويبوّظه على المتصفح. */
chk97(preg_match('~json\(\[.*?\]\);\s*exit;~s', $ctrlCode) === 1,
    'و`json()` وراها `exit` — التحذير الطايش ما بيلزقش في الرد');

$routes = code97('public/index.php');
chk97(str_contains($routes, "'/suggest/habits/{field}'") && str_contains($routes, "'/suggest/habits'"),
    'المساران مسجَّلان — والحرفي الأطول فوق');
chk97(strpos($routes, "'/suggest/habits/{field}'") < strpos($routes, "'/suggest/habits'"),
    'وترتيبهما صح (الراوتر first-match-wins)');

// ═══════════════════════════════════════ الخلاصة
echo "\n" . str_repeat('=', 62) . "\n";
printf("ناجح: %d · فاشل: %d\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
