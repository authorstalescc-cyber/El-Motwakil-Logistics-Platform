<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص التنقّل بعد الحفظ ومنع التكرار (docs/16-navigation-master-plan.md).
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  ليه الفاحص ده موجود أصلاً — اقرأ الفقرة دي قبل ما تعدّل فيه أي حاجة
 * ═══════════════════════════════════════════════════════════════════════
 *
 * «المرحلة ٢: عقد المسار بعد الحفظ» بنت أدوات صح (`done()`/`safe_back()`/
 * `claimForm()`) وكتبت لها فحصًا في `verify_ux.php`. الفحص ده بيسأل سؤالين:
 * «هل دالة `done()` موجودة؟» و«هل جواها flash وsafe_back؟» — وبيعدّي **١٤٤/١٤٤**.
 *
 * وبرغم كده المالك بلّغ (2026-07-25) إن زرار الرجوع بعد حفظ الفاتورة بيرجّعه
 * لفورم إنشاء الفاتورة معبّى، وإن ده ممكن ينتج مستند مكرر. والسبب إن الأدوات
 * كانت متبنّاة في **٩٪** من النظام، والفحص القديم **مش بيقيس التبنّي أصلاً**.
 *
 * فالفاحص ده **بيقيس الاستعمال لا الوجود**، وبيشتغل بـ**سقف متناقص**:
 * الأرقام الحالية متثبَّتة تحت في `BASELINE`، والفاحص بيفشل لو أي رقم **ساء**.
 * مع كل مرحلة بيتشدّ الحد فيبقى الرقم القديم مستحيل الرجوع ليه.
 *
 * ⚠️ **ممنوع ترخّي أي حد في BASELINE عشان الفحص يعدّي.** الحد بيتشدّ في اتجاه
 *    واحد بس. لو رقم ساء، ده ارتداد حقيقي لازم يتصلّح في الكود مش في الفاحص.
 *
 * الأقسام:
 *   1) تبنّي `done()` مقابل `redirect()` الخام
 *   2) وصول `_back` فعليًا من الفورمات (بغيره `done()` قشرة على السلوك القديم)
 *   3) حماية التكرار على الكتابات المالية (`claimForm`)
 *   4) **زر رجوع بيوصّل لفورم إنشاء** — الباج المُبلَّغ عنه بالحرف
 *   5) عدد أزرار الرجوع المكتوبة بالإيد
 *   6) مفيش أثر مالي — الطبقة دي تنقّل بحت
 *
 * آمن تمامًا: **قراءة فقط**، مفيش أي كتابة ولا transaction ولا اتصال بالقاعدة.
 *
 * التشغيل:  php bin/verify_navigation.php
 */

$pass = 0; $fail = 0; $skip = 0;

function ok(string $m): void   { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  ✗ $m\n"; }
function skip(string $m): void { global $skip; $skip++; echo "  ~ $m (تخطّي)\n"; }
function sec(string $m): void  { echo "\n" . $m . "\n" . str_repeat('─', 66) . "\n"; }

$root = dirname(__DIR__);

/**
 * ═══════════════════════════════════════════════════════════════════════
 *  خط الأساس — السقف المتناقص
 * ═══════════════════════════════════════════════════════════════════════
 * 'min' = الرقم ممنوع ينزل عنه   |   'max' = الرقم ممنوع يزيد عنه
 *
 * سجل الشدّ (كل تعديل هنا لازم يكون **تشديدًا** ومعاه تاريخه ومرحلته):
 *   2026-07-25  المرحلة ٠  خط الأساس الأول كما هو مقيس من الكود
 *   2026-07-26  المرحلة ١  back_to_form 2→0 (تشديد) · money_claim 0→10 (تشديد)
 *                          back_field 25→29 (تشديد) · done_adoption عند 25 (تشديد من 24)
 *
 *   2026-07-26  ⚠️ **إعادة قياس واحدة لـmanual_back: 41→43 — وده مش ترخية.**
 *               خط الأساس الأول (41) اتقاس من **شجرة العمل** وقت كتابة الفاحص،
 *               وكانت فيها شغل تاني نص طريق. المقارنة بـHEAD أثبتت إن الفرق
 *               لينكين في بوابة المناديب (`hf-back` → `url('m')`) من شغل غير
 *               متعلّق بالمرحلة دي، والاتنين **تنقّل سليم لا مخالفة** — الدليل
 *               إن `back_to_form` فضل **صفر**. تعديلات المرحلة ١ نفسها محايدة
 *               على العدّاد ده (استبدلت وجهة لينكين بلا زيادة عددهم).
 *               الحد ده بينزل لصفر في المرحلة ٢ (مكوّن التنقّل الواحد).
 *
 *   2026-07-26  manual_back 43→38 (تشديد) — زر الرجوع في بوابة المندوب اتجمّع
 *               في جزء واحد `app/Views/m/_back.php` بدل ٥ نسخ مكتوبة بالإيد،
 *               وده صلّح كمان إن شاشتين كان سهمهما ناحية الشمال في واجهة RTL.
 */
const BASELINE = [
    'done_adoption'  => ['min' => 25, 'label' => 'نقاط الخروج على done()'],
    'back_field'     => ['min' => 29, 'label' => 'ملفات عرض بتبعت _back'],
    'money_claim'    => ['min' => 10, 'label' => 'كتابات مالية محميّة من التكرار'],
    'back_to_form'   => ['max' => 0,  'label' => 'زر رجوع بيوصّل لفورم إنشاء'],
    'manual_back'    => ['max' => 38, 'label' => 'أزرار رجوع مكتوبة بالإيد'],
];

/**
 * الكتابات المالية اللي **لازم** تكون محميّة من الإرسال المكرر.
 *
 * كل واحدة فيهم بتنشئ صفًّا ماليًّا؛ الإرسال مرتين = صف مكرر حقيقي.
 * القايمة دي **قرار** مش اشتقاق، فمكتوبة بالإيد عن قصد — لكن الفاحص بيتأكد
 * إن كل مسار فيها **لسه موجود في الراوتر**، فأي إعادة تسمية بتتمسك هنا
 * بدل ما الحماية تضيع بالسكوت.
 *
 * ⚠️ **الفحص على الحماية نفسها لا على اسم دالة بعينها.** أول نسخة من الفاحص
 *    كانت بتدوّر على `claimForm` وبس، فبلّغت عن ٣ مسارات **محميّة فعلاً**
 *    بآليات تانية — واتنين منها **أقوى** من `claimForm` لأنها مخزَّنة في
 *    القاعدة لا في الجلسة. لو الفحص فضل زي ما هو كنا هنركّب حماية مكررة
 *    فوق حماية شغّالة. الأنواع التلاتة:
 *
 *    'claim'        → `Controller::claimForm` (رمز نموذج في الجلسة، لمرة واحدة)
 *    'client_token' → عمود `rep_submissions.client_token`: الطلب المعاد بيرجّع
 *                     **نفس البند** بدل ما ينشئ نسخة. الأقوى — بينفع كمان لما
 *                     رد الشبكة يضيع على موبايل المندوب.
 *    'quick_token'  → رمز في الجلسة بيتمسح بعد النجاح (نفس فكرة claim، منفَّذة
 *                     محليًّا في `DashboardController::quickEntries`).
 */
const MONEY_WRITES = [
    'POST /statements'               => ['OwnerStatementController','store',        'claim',        'كشف حساب صاحب البيان'],
    'POST /reps/{id}/entry'          => ['RepController',           'addEntry',     'claim',        'بند استحقاق/خصم لمندوب'],
    'POST /reps/{id}/settle'         => ['RepController',           'settle',       'claim',        'تسوية حساب مندوب'],
    'POST /clients/{id}/pay'         => ['ClientController',        'pay',          'claim',        'دفعة سداد عميل'],
    // v60: «تسليم عهدة لسائق» اتشالت ومكانها «بوليصة جديدة» — نفس الحماية بالظبط
    // (claimForm)، لأن الضغط مرتين كان هيسجّل بوليصة تانية ويخصم العهدة تاني.
    'POST /m/bill/new'               => ['RepPortalController',     'billStore',    'claim',        'بوليصة مندوب بعهدة سائق'],
    'POST /m/transfer'               => ['RepPortalController',     'transferStore','claim',        'تحويل عهدة بين مندوبين'],
    'POST /bawalis/bulk-paste'       => ['BawalisController',       'bulkPaste',    'claim',        'لصق دفعة بوالص'],
    'POST /m/misc'                   => ['RepPortalController',     'miscStore',    'client_token', 'نثرية مندوب'],
    'POST /m/permit/{id}'            => ['RepPortalController',     'entryStore',   'client_token', 'بند مندوب على إذن'],
    // ⚠️ اتنقل من الداشبورد لشاشة الأذونات (2026-08-17) — الحماية زي ما هي
    'POST /permits/quick-entries'    => ['PermitController',        'quickEntries', 'quick_token',  'دفعة إدخال سريع'],
];

// ───────────────────────────────────────────────────────────────────────
// أدوات
// ───────────────────────────────────────────────────────────────────────

/** يشيل تعليقات PHP — تعليق بيشرح كود قديم ما يعتبرش استخدامًا له.
 *  (نفس درس `code_only` في verify_security.php و`php_code` في verify_ux.php:
 *   فحص «النص ده مش موجود» بيدّي إنذارًا كاذبًا لو تعليق بيوثّقه موجود.) */
function nav_code(string $s): string
{
    $s = (string) preg_replace('#/\*.*?\*/#s', '', $s);
    return (string) preg_replace('#^\s*//.*$#m', '', $s);
}

/** كل ملفات العرض (بما فيها بوابة المناديب — التنقّل بيخصّها زي المكتب). */
function nav_views(string $root): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app/Views'));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'php') { continue; }
        $out[$f->getPathname()] = (string) file_get_contents($f->getPathname());
    }
    ksort($out);
    return $out;
}

/** كل ملفات الكنترولرات. */
function nav_controllers(string $root): array
{
    $out = [];
    foreach (glob($root . '/app/Controllers/*.php') ?: [] as $f) {
        $out[$f] = (string) file_get_contents($f);
    }
    return $out;
}

/**
 * يوحّد مسارًا لصيغة مقارنة: بلا سلاش بادئ، بلا query، والأجزاء المتغيّرة `{}`.
 * `/permits/{id}/invoice` و`permits/'.$id.'/invoice` الاتنين ← `permits/{}/invoice`
 */
function nav_norm(string $path): string
{
    $path = ltrim(trim($path), '/');
    $path = explode('?', $path)[0];
    $path = explode('#', $path)[0];
    $path = (string) preg_replace('#\{[^}]*\}#', '{}', $path);
    return rtrim($path, '/');
}

/**
 * يستخرج المسار من تعبير `url(...)`: بيلزق الأجزاء النصّية الحرفية،
 * وبيحطّ `{}` مكان أي تعبير PHP (متغيّر/دالة).
 * بيرجّع null لو التعبير كله متغيّر (مش قادرين نحدّد الوجهة).
 *
 * مثال: `'permits/'.$inv['permit_id'].'/invoice'` ← `permits/{}/invoice`
 */
function nav_target(string $expr): ?string
{
    $out = '';
    $len = strlen($expr);
    $i = 0;
    $sawLiteral = false;
    while ($i < $len) {
        $ch = $expr[$i];
        if ($ch === "'" || $ch === '"') {
            $quote = $ch;
            $i++;
            $buf = '';
            while ($i < $len) {
                if ($expr[$i] === '\\' && $i + 1 < $len) { $buf .= $expr[$i + 1]; $i += 2; continue; }
                if ($expr[$i] === $quote) { $i++; break; }
                $buf .= $expr[$i];
                $i++;
            }
            $out .= $buf;
            $sawLiteral = true;
            continue;
        }
        if ($ch === '.') { $i++; continue; }              // فاصل لصق
        // تعبير PHP: نبلعه لحد أقرب نقطة لصق خارج الأقواس
        $depth = 0;
        $start = $i;
        while ($i < $len) {
            $c = $expr[$i];
            if ($c === '(' || $c === '[') { $depth++; }
            elseif ($c === ')' || $c === ']') { $depth--; }
            elseif ($c === '.' && $depth === 0) { break; }
            elseif (($c === "'" || $c === '"') && $depth === 0) { break; }
            $i++;
        }
        if ($i > $start) { $out .= '{}'; }
        else { $i++; }
    }
    return $sawLiteral ? nav_norm($out) : null;
}

/** يقصّ وسيط `url(` الأول من نص، مع احترام الأقواس المتداخلة. */
function nav_url_arg(string $s, int $from): ?string
{
    $pos = strpos($s, 'url(', $from);
    if ($pos === false) { return null; }
    $i = $pos + 4;
    $depth = 1;
    $out = '';
    $len = strlen($s);
    while ($i < $len && $depth > 0) {
        $c = $s[$i];
        if ($c === '(') { $depth++; }
        elseif ($c === ')') { $depth--; if ($depth === 0) { break; } }
        $out .= $c;
        $i++;
    }
    return $depth === 0 ? $out : null;
}

// ───────────────────────────────────────────────────────────────────────
echo "\n╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  فحص التنقّل بعد الحفظ ومنع التكرار — يقيس الاستعمال لا الوجود  ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n";

$views       = nav_views($root);
$controllers = nav_controllers($root);
$routerSrc   = (string) file_get_contents($root . '/public/index.php');
$metrics     = [];

// ═══════════════════════════════════════════════════════════════════════
sec('١) تبنّي done() — المسار الموحّد بعد الحفظ');
// ═══════════════════════════════════════════════════════════════════════

$doneCount = 0; $redirectCount = 0; $perFile = [];
foreach ($controllers as $path => $src) {
    $code = nav_code($src);
    $d = preg_match_all('/\$this->done\(/', $code);
    $r = preg_match_all('/(?<![>:\w])redirect\(/', $code);
    $doneCount += $d; $redirectCount += $r;
    if ($r > 0) { $perFile[basename($path)] = $r; }
}
$exits = $doneCount + $redirectCount;
$metrics['done_adoption'] = $doneCount;

printf("  الحالي: done()=%d  ·  redirect خام=%d  ·  الإجمالي=%d  (%.0f%%)\n",
    $doneCount, $redirectCount, $exits, $exits ? $doneCount / $exits * 100 : 0);

arsort($perFile);
$worst = array_slice($perFile, 0, 4, true);
echo "  أعلى الملفات في redirect الخام: ";
echo implode('، ', array_map(fn($k, $v) => "$k ($v)", array_keys($worst), $worst)) . "\n";

// ═══════════════════════════════════════════════════════════════════════
sec('٢) وصول _back من الفورمات');
// ═══════════════════════════════════════════════════════════════════════

$backFieldViews = 0;
foreach ($views as $src) {
    if (str_contains(nav_code($src), 'formStateFields')) { $backFieldViews++; }
}
$formCount = 0;
foreach ($views as $src) { $formCount += preg_match_all('/<form/i', nav_code($src)); }
$metrics['back_field'] = $backFieldViews;

printf("  الحالي: %d ملف عرض بيبعت _back  ·  إجمالي الفورمات=%d\n", $backFieldViews, $formCount);

// `done()` بلا `_back` بترجع لوجهة ثابتة = السلوك القديم بالحرف.
$ctrlCore = nav_code((string) file_get_contents($root . '/app/Core/Controller.php'));
str_contains($ctrlCore, "safe_back(\$fallback, \$_POST['_back']")
    ? ok('done() لسه بتقرا _back وبتنضّفه بـsafe_back')
    : bad('done() مش بتقرا _back — آلية «ارجع لمكانك» اتكسرت');

$helpers = nav_code((string) file_get_contents($root . '/app/Core/helpers.php'));
str_contains($helpers, "name=\"_back\"")
    ? ok('formStateFields() لسه بتُصدر حقل _back المخفي')
    : bad('formStateFields() بطّلت تُصدر _back');

// ═══════════════════════════════════════════════════════════════════════
sec('٣) حماية التكرار على الكتابات المالية');
// ═══════════════════════════════════════════════════════════════════════

$protected = 0;
foreach (MONEY_WRITES as $route => [$ctrl, $method, $guard, $desc]) {
    $file = $root . '/app/Controllers/' . $ctrl . '.php';
    if (!is_file($file)) { bad("$desc — الملف $ctrl.php مش موجود"); continue; }
    $src = nav_code((string) file_get_contents($file));

    // المسار لسه في الراوتر؟ (يمسك إعادة التسمية بدل ما الحماية تضيع بالسكوت)
    if (!str_contains($routerSrc, $ctrl . '@' . $method)) {
        bad("$desc — $ctrl@$method مش مربوط في الراوتر (اتغيّر اسمه؟)");
        continue;
    }

    // جسم الدالة: من توقيعها لحد أول قوس إغلاق على مستوى الدالة
    if (!preg_match('/function\s+' . preg_quote($method, '/') . '\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
        bad("$desc — مالقيتش الدالة $method في $ctrl");
        continue;
    }
    $start = $m[0][1] + strlen($m[0][0]);
    $depth = 1; $i = $start; $len = strlen($src);
    while ($i < $len && $depth > 0) {
        if ($src[$i] === '{') { $depth++; }
        elseif ($src[$i] === '}') { $depth--; }
        $i++;
    }
    $body = substr($src, $start, $i - $start);

    // الحماية بالنوع المُعلَن لكل مسار — مش باسم دالة واحدة
    $has = match ($guard) {
        'claim'        => str_contains($body, 'claimForm('),
        'quick_token'  => str_contains($body, 'quick_token')
                          && str_contains($body, "unset(\$_SESSION['_quick_entry_token']"),
        // الحماية هنا في الموديل لا الكنترولر: `RepPortal::submit` بترجّع البند
        // الموجود بدل ما تنشئ نسخة، والشاشة لازم تبعت الرمز عشان الآلية تشتغل.
        'client_token' => str_contains($body, 'RepPortal::submit')
                          || str_contains($body, '$this->postedData('),
        default        => false,
    };

    if ($guard === 'client_token') {
        $model = nav_code((string) file_get_contents($root . '/app/Models/RepPortal.php'));
        $dedupe = str_contains($model, 'WHERE rep_id = ? AND client_token = ?');
        $sent = false;
        foreach (['m/misc.php', 'm/entry.php'] as $v) {
            $vp = $root . '/app/Views/' . $v;
            if (is_file($vp) && str_contains((string) file_get_contents($vp), 'name="client_token"')) { $sent = true; }
        }
        $has = $has && $dedupe && $sent;
        if (!$dedupe) { bad('RepPortal::submit فقدت فحص client_token — بنود المناديب بقت تتكرر'); }
        if (!$sent)   { bad('شاشات البوابة بطّلت تبعت client_token — الحماية اتعطّلت بالسكوت'); }
    }

    if ($has) { $protected++; }
    else { bad("$desc — بلا حماية من التكرار ($guard): تكراره بينشئ صفًّا ماليًّا مكررًا — $route"); }
}
$metrics['money_claim'] = $protected;
printf("  الحالي: %d محميّة من %d كتابة مالية\n", $protected, count(MONEY_WRITES));

if ($protected === count(MONEY_WRITES)) {
    ok('كل الكتابات المالية المُدرَجة محميّة من التكرار (claim / client_token / quick_token)');
}

// claimForm نفسها لسه بتشتغل؟
str_contains($ctrlCore, 'FormState::claim(')
    ? ok('claimForm() لسه بتستهلك رمز النموذج (FormState::claim)')
    : bad('claimForm() مش بتستهلك رمز النموذج — الحماية قشرة');

// ═══════════════════════════════════════════════════════════════════════
sec('٤) ⚠️ زر رجوع بيوصّل لفورم إنشاء — الباج المُبلَّغ عنه');
// ═══════════════════════════════════════════════════════════════════════

// مسارات الفورمات **مشتقّة من الراوتر** لا مكتوبة بالإيد: أي GET معالِجها
// اسمه بينتهي بـform أو هو create/createLegacy = شاشة إدخال.
//
// ⚠️ المطابقة **بلا حساسية لحالة الحروف** عن قصد: `/statements/form` معالِجها
//    اسمه `form` بحروف صغيرة، فـ`/Form$/` كانت بتفوّته — وهو واحد من
//    الشاشتين المُبلَّغ عنهما بالحرف. (اتصادف فعليًّا وأنا بكتب الفاحص.)
//
// وأي مسار بينتهي بـ`/new` بيتحسب نقطة إنشاء كمان حتى لو معالِجه «مُنتقي»
// (زي `/invoices/new` و`/statements/new`) — الرجوع بعد الحفظ لنقطة إنشاء
// دعوة لإصدار مستند تاني، سواء كانت فورمًا معبّى ولا شاشة اختيار.
$formPaths = [];
if (preg_match_all(
    '/->get\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"](\w+)@(\w+)[\'"]/',
    $routerSrc, $mm, PREG_SET_ORDER
)) {
    foreach ($mm as $r) {
        [, $path, , $method] = $r;
        $norm = nav_norm($path);
        if (preg_match('/form$/i', $method)
            || in_array($method, ['create', 'createLegacy'], true)
            || str_ends_with($norm, '/new')) {
            $formPaths[$norm] = $method;
        }
    }
}
// مسارات CRUD العامة (`/$route/new` → create) بتتولّد في حلقة، فبتتضاف صراحةً
$formPaths['{}/new'] = 'create';

count($formPaths) >= 10
    ? ok('اشتُقّت ' . count($formPaths) . ' مسار فورم من الراوتر (مش قائمة يدوية)')
    : bad('اشتقاق مسارات الفورمات فشل — لقينا ' . count($formPaths) . ' بس');

$violations = [];
$manualBack = 0;
foreach ($views as $path => $src) {
    $rel = str_replace($root . '/', '', $path);
    foreach (explode("\n", $src) as $n => $line) {
        if (!str_contains($line, 'رجوع') || !str_contains($line, 'href=')) { continue; }
        $manualBack++;
        $arg = nav_url_arg($line, 0);
        if ($arg === null) { continue; }
        $target = nav_target($arg);
        if ($target === null || $target === '') { continue; }   // وجهة متغيّرة — مش قادرين نحكم

        foreach ($formPaths as $fp => $handler) {
            if ($fp === '' || $fp === '{}') { continue; }
            if ($target === $fp) {
                $violations[] = [$rel, $n + 1, $target, $handler];
                break;
            }
        }
    }
}
$metrics['back_to_form'] = count($violations);
$metrics['manual_back']  = $manualBack;

if ($violations) {
    foreach ($violations as [$file, $line, $target, $handler]) {
        // ⚠️ `{$target}` لا `$target` — بايتات «» بتتحسب جزءًا من اسم المتغيّر
        //    (المصيدة الموثّقة في v38/v49/لوحة الأمان — وقعت هنا للمرة الخامسة)
        bad("رجوع بيوصّل لفورم إنشاء: $file:$line ← «{$target}» (المعالِج {$handler})");
    }
} else {
    ok('مفيش زر رجوع واحد بيوصّل لمسار فورم إنشاء');
}
printf("  أزرار الرجوع المكتوبة بالإيد: %d\n", $manualBack);

// ═══════════════════════════════════════════════════════════════════════
sec('٥) صفحات المستندات المحفوظة — الرجوع للأصل لا للفورم');
// ═══════════════════════════════════════════════════════════════════════

// حراسة صريحة على الشاشتين المُبلَّغ عنهما، عشان الارتداد يتمسك بالاسم
$docPages = [
    'app/Views/permits/invoice_print.php'  => ['permits/{}',      'الفاتورة ترجّع للإذن'],
    'app/Views/statements/print.php'       => ['settlements',     'كشف الحساب يرجّع لصاحب البيان'],
];
foreach ($docPages as $rel => [$expect, $desc]) {
    $file = $root . '/' . $rel;
    if (!is_file($file)) { bad("$desc — الملف $rel مش موجود"); continue; }
    $src = nav_code((string) file_get_contents($file));
    $found = false;
    foreach (explode("\n", $src) as $line) {
        if (!str_contains($line, 'رجوع') || !str_contains($line, 'href=')) { continue; }
        $arg = nav_url_arg($line, 0);
        if ($arg === null) { continue; }
        // ⚠️ مطابقة **تامة** لا str_starts_with: البادئة كانت بتدّي نجاحًا كاذبًا
        //    لأن `permits/{}/invoice` (الفورم الغلط) بتبدأ بـ`permits/{}` (الصح).
        if (nav_target($arg) === nav_norm($expect)) { $found = true; break; }
    }
    $found ? ok($desc) : bad("$desc — مالقيتش رجوعًا لـ«{$expect}» في {$rel}");
}

// ═══════════════════════════════════════════════════════════════════════
sec('٦) الفورم بيحذّر لو صدر مستند بالفعل');
// ═══════════════════════════════════════════════════════════════════════

$warnPages = [
    'app/Views/permits/invoice_form.php' => ['js-issued-warn', 'فورم الفاتورة'],
    'app/Views/statements/form.php'      => ['js-issued-warn', 'فورم كشف الحساب'],
];
foreach ($warnPages as $rel => [$hook, $desc]) {
    $file = $root . '/' . $rel;
    if (!is_file($file)) { bad("$desc — الملف مش موجود"); continue; }
    $src = (string) file_get_contents($file);
    if (!str_contains($src, $hook)) { bad("$desc — مفيش تنبيه «صدر مستند بالفعل» ($hook)"); continue; }
    // لازم يكون **قبل** الفورم عشان يتشاف قبل الإرسال، مش في آخر الصفحة
    $warnPos = strpos($src, $hook);
    $formPos = stripos($src, '<form');
    ($formPos === false || $warnPos < $formPos)
        ? ok("$desc — التنبيه فوق الفورم")
        : bad("$desc — التنبيه تحت الفورم فمش هيتشاف قبل الإرسال");
}

// أي كلاس بيتحقن/بيتعرض لازم يكون له شكل معرَّف
// (درس `.table-mobile-scroll` و`.btn.primary`: مستعمل وغير معرَّف = بيقع
//  على الشكل الافتراضي **بالسكوت**)
$css = (string) file_get_contents($root . '/public/assets/css/app.css');
str_contains($css, '.issued-warn')
    ? ok('.issued-warn معرَّف في app.css')
    : bad('.issued-warn مستعمل في الشاشات وغير معرَّف في app.css');

// ═══════════════════════════════════════════════════════════════════════
sec('٧) مفيش أثر مالي — الطبقة دي تنقّل بحت');
// ═══════════════════════════════════════════════════════════════════════

$sqlInViews = [];
foreach ($views as $path => $src) {
    if (preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b/i', nav_code($src))) {
        $sqlInViews[] = str_replace($root . '/', '', $path);
    }
}
$sqlInViews
    ? bad('كتابة SQL في ملفات عرض: ' . implode('، ', $sqlInViews))
    : ok('مفيش أي كتابة SQL في ملفات العرض');

// ⚠️ الفحص ده كان **بيفشل على نفسه**: أول نسخة دوّرت على السلسلة `Database::`
//    وهي مكتوبة في نمط الفحص نفسه فطابقت نفسها. الفحص الصح على **السلوك**:
//    الفاحص ما بيحمّلش الـbootstrap أصلاً، فمستحيل يفتح اتصالًا بالقاعدة.
// ⚠️ الفحص لازم يدوّر على **جملة الاستدعاء** لا على اسم الملف: البحث عن
//    السلسلة وحدها بيمسك سطر الفحص نفسه (اتصادف مرتين وأنا بكتبه — مرة على
//    `Database::` ومرة على اسم الـbootstrap).
$selfSrc = nav_code((string) file_get_contents(__FILE__));
preg_match('/\b(require|include)(_once)?\b[^;]*bootstrap/i', $selfSrc)
    ? bad('الفاحص بيحمّل bootstrap — المفروض قراءة ملفات بلا اتصال بالقاعدة')
    : ok('الفاحص قراءة ملفات بحتة (ما بيحمّلش bootstrap ولا بيفتح القاعدة)');

// ═══════════════════════════════════════════════════════════════════════
sec('٨) لوحة الأرقام — السقف المتناقص');
// ═══════════════════════════════════════════════════════════════════════

printf("  %-34s %8s %10s   %s\n", 'المؤشّر', 'الحالي', 'الحد', '');
echo '  ' . str_repeat('─', 64) . "\n";
foreach (BASELINE as $key => $rule) {
    $cur   = $metrics[$key] ?? null;
    if ($cur === null) { skip("مؤشّر «{$rule['label']}» ما اتقاسش"); continue; }
    $isMin = isset($rule['min']);
    $limit = $isMin ? $rule['min'] : $rule['max'];
    $good  = $isMin ? ($cur >= $limit) : ($cur <= $limit);
    printf("  %-34s %8d %10s   %s\n",
        $rule['label'], $cur, ($isMin ? '≥ ' : '≤ ') . $limit, $good ? '✓' : '✗');
    if ($good) {
        if ($isMin ? ($cur > $limit) : ($cur < $limit)) {
            ok("{$rule['label']}: تحسّن ($cur مقابل حد $limit) — **شدّ الحد في BASELINE**");
        } else {
            ok("{$rule['label']}: عند الحد ($cur)");
        }
    } else {
        bad("{$rule['label']}: ارتداد — الحالي $cur والحد " . ($isMin ? "≥ $limit" : "≤ $limit"));
    }
}

// ───────────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('=', 66) . "\n";
echo "نجح: $pass   |   فشل: $fail   |   تخطّي: $skip\n\n";
if ($fail === 0) {
    echo "✓ كل الفحوصات عدّت.\n";
    exit(0);
}
echo "✗ فيه ارتداد — راجع السطور المعلَّمة ✗ فوق.\n";
echo "  ⚠️ الحل في الكود لا في ترخية BASELINE.\n";
exit(1);
