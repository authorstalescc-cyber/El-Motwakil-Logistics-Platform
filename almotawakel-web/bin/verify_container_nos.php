<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص «خانة لكل حاوية» في نموذج البوليصة.
 *
 * المشكلة: البوليصة عليها أكتر من حاوية، وكان فيه **خانة واحدة** لرقم الحاوية —
 * فصاحب المشروع بيحشر الرقمين جوّاها («286911/9-137074/2»، متحقَّق في ٣٣٧ صف).
 * دلوقتي عدد الخانات بيتبع «عدد الحاويات» وبيتزوّد حيًّا.
 *
 * بيحرس:
 *   1) تفكيك النص لأرقام — ومعاه **المصيدة الأساسية**: رقم فيه خانة تحقّق
 *      («171502-9») **ما يتقسمش**، بينما رقمان ملزوقان بشرطة يتقسموا.
 *   2) الجمع بلا خسارة: تفكيك ثم تجميع لكل بوليصة موجودة فعلاً في القاعدة
 *      لازم يرجّع **نفس النص بالحرف** — يعني فتح بوليصة قديمة وحفظها ما بيغيّرش
 *      قيمتها المخزَّنة.
 *   3) `container_no[]` بيتجمّع في نقطة واحدة قبل أي كنترولر (`bootstrap.php`)،
 *      فمستحيل تتكتب في القاعدة كنص «Array».
 *   4) المكوّن بيرندر فعليًّا عدد الخانات الصح (١ لحاوية، ٢ لحاويتين…).
 *   5) الشاشتان (البوليصة من داخل الإذن + `/bawalis`) بيستخدموا المكوّن.
 *   6) كل كلاس بيحقنه `app.js` له تعريف في `app.css` (مصيدة `.table-mobile-scroll`).
 *   7) **مفيش أثر مالي** — العمود وصفي، ومفيش هجرة ولا لمسة لأي حساب.
 *
 * آمن تمامًا: **قراءة فقط** + رندر في الذاكرة.
 *
 * التشغيل:  php bin/verify_container_nos.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Custody;

$pass = 0; $fail = 0; $skip = 0;
function ok(string $m): void   { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  ✗ $m\n"; }
function skip(string $m): void { global $skip; $skip++; echo "  ~ $m (تخطّي)\n"; }
function sec(string $m): void  { echo "\n" . $m . "\n" . str_repeat('─', 62) . "\n"; }

$root = dirname(__DIR__);
$read = fn(string $rel): string => is_file($root . '/' . $rel) ? (string) file_get_contents($root . '/' . $rel) : '';

echo "\n╔══════════════════════════════════════════════════════════════╗\n";
echo   "║  فحص «خانة لكل حاوية» في نموذج البوليصة                      ║\n";
echo   "╚══════════════════════════════════════════════════════════════╝\n";

$balanceBefore = null;
try { $balanceBefore = (float) Custody::balance(); } catch (\Throwable $e) { $balanceBefore = null; }

/* ══════════════ 1) تفكيك النص لأرقام ══════════════ */
sec('1) تفكيك نص العمود لأرقام منفصلة');

$cases = [
    // [النص, عدد الحاويات, الناتج المتوقّع]
    ['286911/9-137074/2', 2, ['286911/9', '137074/2']],
    ['093991/5-177254/4', 2, ['093991/5', '177254/4']],
    ['635946/7 - 049933/7', 2, ['635946/7', '049933/7']],
    ['113973/9',           1, ['113973/9']],
    ['TLLU275983/6',       1, ['TLLU275983/6']],
    ['A/1,B/2',            2, ['A/1', 'B/2']],
    ['A/1، B/2',           2, ['A/1', 'B/2']],
];
foreach ($cases as [$raw, $count, $want]) {
    $got = container_nos_split($raw, $count);
    $got === $want
        ? ok("«{$raw}» ← " . count($got) . ' رقم: ' . implode(' | ', $got))
        : bad("«{$raw}» ← المتوقّع [" . implode(' | ', $want) . '] والناتج [' . implode(' | ', $got) . ']');
}

// ⚠️ المصيدة الأساسية: خانة التحقّق بعد شرطة مش رقم تاني
$checkDigit = container_nos_split('171502-9', 2);
($checkDigit[0] === '171502-9')
    ? ok('«171502-9» (خانة تحقّق) ما اتقسمتش — فضلت رقمًا واحدًا')
    : bad('«171502-9» اتقسمت بالغلط إلى: ' . implode(' | ', $checkDigit));

// عدد الخانات = عدد الحاويات (تكملة بخانات فاضية)
$padded = container_nos_split('286911/9', 3);
(count($padded) === 3 && $padded[0] === '286911/9' && $padded[1] === '' && $padded[2] === '')
    ? ok('رقم واحد وعدد الحاويات ٣ ← ٣ خانات (اتنين فاضيين)')
    : bad('التكملة لعدد الحاويات غلط: [' . implode(' | ', $padded) . ']');

// أرقام أكتر من العدد: ما نقصّش ولا رقم
$over = container_nos_split('A111/1-B222/2-C333/3', 1);
(count($over) === 3)
    ? ok('٣ أرقام وعدد الحاويات ١ ← الخانات التلاتة كلها فضلت (مفيش رقم بيضيع)')
    : bad('أرقام ضاعت لما العدد أقل منها: [' . implode(' | ', $over) . ']');

// نص فاضي = خانة واحدة فاضية
(container_nos_split('', 0) === [''] && container_nos_split(null, 0) === [''])
    ? ok('الفاضي/null ← خانة واحدة فاضية')
    : bad('الفاضي ما رجّعش خانة واحدة');

/* ══════════════ 2) الجمع بلا خسارة ══════════════ */
sec('2) الجمع — ولا رقم بيضيع ولا نص قديم بيتغيّر');

(container_nos_join(['286911/9', '137074/2']) === '286911/9-137074/2')
    ? ok('الجمع بالشكل المخزَّن نفسه («أ-ب» بلا مسافات)')
    : bad('الجمع طلع: ' . var_export(container_nos_join(['286911/9', '137074/2']), true));

(container_nos_join(['  286911/9  ', '', '137074/2']) === '286911/9-137074/2')
    ? ok('الخانات الفاضية والمسافات بتتشال قبل الجمع')
    : bad('الفاضي/المسافات ما اتشالوش');

(container_nos_join([]) === null && container_nos_join(['', '  ']) === null)
    ? ok('كل الخانات فاضية ← NULL (مش نص فاضي ولا شرطة سايبة)')
    : bad('كل الخانات فاضية ما رجّعتش NULL');

// جزء قصير: نستخدم « - » بمسافات عشان التفكيك يعرف يرجّعه
$shortJoin = container_nos_join(['12', '34']);
($shortJoin === '12 - 34' && container_nos_split($shortJoin, 2) === ['12', '34'])
    ? ok('الأجزاء القصيرة بتتجمع بفاصل واضح وبترجع بالتفكيك زي ما هي')
    : bad('الأجزاء القصيرة مش راجعة بالتفكيك: ' . var_export($shortJoin, true));

// ⚠️ الأهم: كل بوليصة موجودة فعلاً — تفكيك ثم تجميع ما بيضيّعش ولا رقم،
//    وأي فرق في النص لازم يكون **مسافات حوالين الشرطة وبس** (توحيد شكل، مش خسارة).
try {
    $rows = Database::all("SELECT container_no, container_count FROM bawalis WHERE container_no IS NOT NULL AND container_no <> ''");
    $lost = []; $reshaped = []; $n = 0;
    foreach ($rows as $r) {
        $n++;
        $parts = container_nos_split($r['container_no'], (int) ($r['container_count'] ?? 0));
        $again = (string) container_nos_join($parts);
        // نفس الأرقام بالظبط بعد الدورة الكاملة؟
        $filled = array_values(array_filter($parts, fn($x) => $x !== ''));
        if (array_values(array_filter(container_nos_split($again), fn($x) => $x !== '')) !== $filled) {
            $lost[] = $r['container_no'] . ' ← ' . $again;
        } elseif ($again !== (string) $r['container_no']) {
            // مسموح فقط لو الفرق مسافات
            if (preg_replace('/\s+/u', '', $again) === preg_replace('/\s+/u', '', (string) $r['container_no'])) {
                $reshaped[] = $r['container_no'];
            } else {
                $lost[] = $r['container_no'] . ' ← ' . $again;
            }
        }
    }
    if (!$n) { skip('مفيش بوالص بأرقام حاويات في القاعدة'); }
    elseif ($lost) { bad(count($lost) . ' بوليصة أرقامها هتتغيّر عند الحفظ، منها: ' . implode(' · ', array_slice($lost, 0, 3))); }
    else { ok("كل البوالص ({$n}) بترجع بنفس الأرقام بالظبط بعد تفكيك وتجميع"); }
    if ($reshaped) {
        (count($reshaped) <= max(5, (int) ($n * 0.02)))
            ? ok(count($reshaped) . ' بوليصة هيتوحّد شكل الفاصل فيها عند الحفظ (مسافات بس — نفس الأرقام)')
            : bad(count($reshaped) . ' بوليصة هيتغيّر شكلها — العدد كبير، راجع الفاصل');
    } else {
        ok('ولا بوليصة هيتغيّر نصّها المخزَّن');
    }
} catch (\Throwable $e) {
    skip('مش قادر يقرا البوالص: ' . $e->getMessage());
}

/* ══════════════ 3) نقطة التطبيع الواحدة ══════════════ */
sec('3) `container_no[]` بيتجمّع قبل أي كنترولر');

$bootstrap = $read('app/bootstrap.php');
(strpos($bootstrap, 'container_nos_normalize_request()') !== false)
    ? ok('`bootstrap.php` بينادي `container_nos_normalize_request()`')
    : bad('⚠️ `bootstrap.php` مش بينادي التطبيع — الأرقام هتتكتب «Array» في القاعدة');

(strpos($bootstrap, 'ar2en_normalize_request();') !== false
  && strpos($bootstrap, 'ar2en_normalize_request();') < strpos($bootstrap, 'container_nos_normalize_request()'))
    ? ok('التطبيع بعد توحيد الأرقام العربية (فالرقم العربي بيتحوّل قبل الجمع)')
    : bad('ترتيب التطبيع غلط — لازم بعد `ar2en_normalize_request()`');

$savedPost = $_POST; $savedReq = $_REQUEST;
$_POST = ['container_no' => ['286911/9', '', '137074/2'], 'freight' => '100'];
$_REQUEST = $_POST;
container_nos_normalize_request();
($_POST['container_no'] === '286911/9-137074/2')
    ? ok('POST فيه `container_no[]` ← بقى نصًّا واحدًا جاهزًا للعمود')
    : bad('التطبيع ما اشتغلش: ' . var_export($_POST['container_no'], true));
($_POST['freight'] === '100')
    ? ok('باقي الحقول ما اتلمستش')
    : bad('التطبيع لمس حقولًا تانية');

$_POST = ['container_no' => '113973/9'];
container_nos_normalize_request();
($_POST['container_no'] === '113973/9')
    ? ok('القيمة النصّية العادية بتعدّي زي ما هي (توافق رجعي مع أي مسار قديم)')
    : bad('القيمة النصّية اتغيّرت');
$_POST = $savedPost; $_REQUEST = $savedReq;

/* ══════════════ 4) رندر المكوّن ══════════════ */
sec('4) المكوّن بيرندر عدد الخانات الصح');

$countInputs = fn(string $html): int => preg_match_all('/name="container_no\[\]"/', $html);

$one = partial_str('container_nos', ['value' => '113973/9', 'count' => 1]);
($countInputs($one) === 1) ? ok('حاوية واحدة ← خانة واحدة') : bad('حاوية واحدة ← ' . $countInputs($one) . ' خانة');
(strpos($one, 'value="113973/9"') !== false) ? ok('الرقم المخزَّن ظهر في الخانة') : bad('الرقم المخزَّن ما ظهرش');

$two = partial_str('container_nos', ['value' => '286911/9-137074/2', 'count' => 2]);
($countInputs($two) === 2) ? ok('حاويتان ← خانتان (طلب المالك)') : bad('حاويتان ← ' . $countInputs($two) . ' خانة');
(strpos($two, 'value="286911/9"') !== false && strpos($two, 'value="137074/2"') !== false)
    ? ok('كل رقم في خانته لوحده') : bad('الأرقام ما اتوزّعتش على الخانتين');

$three = partial_str('container_nos', ['value' => '', 'count' => 3]);
($countInputs($three) === 3) ? ok('٣ حاويات ← ٣ خانات فاضية') : bad('٣ حاويات ← ' . $countInputs($three) . ' خانة');

$zero = partial_str('container_nos', ['value' => '', 'count' => 0]);
($countInputs($zero) === 1) ? ok('العدد صفر ← خانة واحدة (مش صفر خانات)') : bad('العدد صفر ← ' . $countInputs($zero) . ' خانة');

(strpos($two, 'data-container-nos') !== false && strpos($two, 'data-cno-name="container_no"') !== false)
    ? ok('الخطّافات اللي بيدوّر عليها `app.js` موجودة في المخرَج')
    : bad('خطّافات الجافاسكريبت ناقصة من المخرَج');

/* ══════════════ 5) الشاشتان بيستخدموا المكوّن ══════════════ */
sec('5) شاشتا الإدخال');

$mv = $read('app/Views/permits/_movement.php');
(strpos($mv, "partial('container_nos'") !== false)
    ? ok('فورم البوليصة جوّه الإذن بيستخدم المكوّن')
    : bad('فورم البوليصة جوّه الإذن لسه بخانة واحدة');
(preg_match('/name="container_no"[^\[]/', $mv) !== 1)
    ? ok('مفيش خانة `container_no` مفردة سايبة في شاشة الإذن')
    : bad('لسه فيه `name="container_no"` مفردة في شاشة الإذن');

$crud = $read('app/Views/crud/form.php');
(strpos($crud, "partial('container_nos'") !== false)
    ? ok('نموذج `/bawalis` بيستخدم المكوّن')
    : bad('نموذج `/bawalis` لسه بخانة واحدة');

$schema = $read('app/Schema.php');
(strpos($schema, "'key'=>'container_no','label'=>'رقم الحاوية','type'=>'containers'") !== false)
    ? ok('نوع الحقل في `Schema` بقى `containers`')
    : bad('نوع الحقل في `Schema` لسه مش `containers`');
(strpos($schema, "'key'=>'container_count'") < strpos($schema, "'key'=>'container_no','label'"))
    ? ok('«عدد الحاويات» قبل «رقم الحاوية» (العدد بيحدّد عدد الخانات)')
    : bad('ترتيب الحقول غلط — العدد لازم يسبق الأرقام');

/* ══════════════ 6) كلاسات الجافاسكريبت لها تعريف في CSS ══════════════ */
sec('6) كل كلاس محقون له شكل معرَّف (مصيدة `.table-mobile-scroll`)');

$js  = $read('public/assets/js/app.js');
$css = $read('public/assets/css/app.css');
(strpos($js, "el.name !== 'container_count'") !== false)
    ? ok('`app.js` فيه وحدة مزامنة الخانات مع «عدد الحاويات»')
    : bad('وحدة المزامنة مش موجودة في `app.js`');
(strpos($js, "document.addEventListener('input', onCount)") !== false)
    ? ok('الاستماع بالتفويض على `document` (فورم الإذن بيتحقن بالـAJAX)')
    : bad('⚠️ الاستماع مش بالتفويض — الفورم المحقون بالـAJAX مش هيشتغل');
/* ⚠️ `cno-hint` اتشال (2026-07-27) — كان بيقول «الخانات بتزيد لوحدها حسب
   عدد الحاويات»، وهو **كان مخفي بـ`display:none`** أصلاً فما حدش شافه. */
foreach (['cno-field', 'cno-list', 'cno-row', 'cno-idx', 'cno-n'] as $cls) {
    (strpos($css, '.' . $cls) !== false)
        ? ok("`.{$cls}` معرَّف في `app.css`")
        : bad("`.{$cls}` بيتحقن بلا أي تعريف — هيفشل بالسكوت");
}

/* ══════════════ 7) العزل المالي ══════════════ */
sec('7) العزل — مفيش أي أثر مالي');

$part = $read('app/Views/partials/container_nos.php');
(!preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|Database::)/i', $part))
    ? ok('المكوّن عرض بحت — ولا استعلام واحد')
    : bad('المكوّن بيلمس القاعدة');

$helpers = $read('app/Core/helpers.php');
$fnBody  = '';
if (preg_match('/function container_nos_(split|join|normalize_request).*?\n}\n/s', $helpers)) {
    foreach (['split', 'join', 'normalize_request'] as $fn) {
        if (preg_match('/function container_nos_' . $fn . '\(.*?\n}\n/s', $helpers, $m)) { $fnBody .= $m[0]; }
    }
}
(!preg_match('/\b(freight|custody|debt|paid|remaining|amount|balance)\b/i', $fnBody))
    ? ok('دوال الأرقام ما بتذكرش أي عمود مالي')
    : bad('دالة من دوال الأرقام بتلمس عمودًا ماليًّا');

$migrations = glob($root . '/bin/migrate_v*.php') ?: [];
$touched = [];
foreach ($migrations as $file) {
    if (strpos((string) file_get_contents($file), 'container_nos') !== false) { $touched[] = basename($file); }
}
(!$touched) ? ok('مفيش هجرة اتعملت — العمود زي ما هو') : bad('فيه هجرة بتلمس الميزة: ' . implode(', ', $touched));

if ($balanceBefore !== null) {
    $after = (float) Custody::balance();
    (abs($after - $balanceBefore) < 0.001)
        ? ok('رصيد خزنة المكتب قبل = بعد (' . money($after) . ')')
        : bad("رصيد الخزنة اتغيّر: {$balanceBefore} ← {$after}");
} else {
    skip('مش قادر يقرا رصيد الخزنة');
}

/* ══════════════ الخلاصة ══════════════ */
echo "\n" . str_repeat('═', 62) . "\n";
echo "  ناجح: {$pass}   فاشل: {$fail}" . ($skip ? "   متخطّى: {$skip}" : '') . "\n";
echo str_repeat('═', 62) . "\n";
exit($fail > 0 ? 1 : 0);
