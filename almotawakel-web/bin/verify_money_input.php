<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص «فواصل الآلاف في خانات المبالغ».
 *
 * المشكلة اللي بتحلّها الوحدة: «2000» و«20000» شكلهم واحد تقريبًا في خانة
 * صغيرة، فصفر زيادة أو ناقصة ما بيتشافش قبل الحفظ. الخانة بقت توري «20,000»
 * وانت بتكتب.
 *
 * بيحرس الحاجات اللي لو وقعت **الفلوس تضيع بالسكوت**:
 *   1) فكّ الفواصل في PHP (`money_unformat`) — الحالة القاتلة إن
 *      `(float)"1,000"` بترجّع **1**، فـ٩٩٩ جنيه يتحفظوا ناقصين بلا أي خطأ.
 *   2) نقطة تنظيف واحدة قبل أي كنترولر، **بعد** توحيد الأرقام العربية.
 *   3) النص اللي مش رقمًا ما يتلمسش (المعرّفات والملاحظات).
 *   4) تغطية الخانات: أي خانة مبلغ في الشاشات لازم عليها `data-money` — الناقصة
 *      بتفضل بلا فواصل **بالسكوت** (نفس عائلة `.table-mobile-scroll`).
 *   5) الوحدة بتتحمّل في المكتب **وفي بوابة المندوب** (`/m` ما بتحمّلش app.js)،
 *      و**قبل** app.js لأن الاتنين بيمسكوا `beforeinput` في طور الالتقاط.
 *   6) `[data-money]` له تعريف CSS في الملفين (كلاس/سمة بلا شكل = فشل صامت).
 *   7) تطابق سلوك PHP وجافاسكريبت على نفس الجدول (لو node موجود — وإلا تخطّي).
 *   8) القيمة اللي بتتبعت للخادم بتتنضّف عند الحفظ (خط الدفاع الأول).
 *
 * آمن تمامًا: **قراءة ملفات + دوال خالصة**، بلا قاعدة بيانات وبلا كتابة.
 *
 * التشغيل:  php bin/verify_money_input.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$pass = 0; $failures = []; $skips = 0;
function ok(string $m): void   { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void  { global $failures; $failures[] = $m; echo "  ✗ $m\n"; }
function chk(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function skip(string $m): void { global $skips; $skips++; echo "  ~ $m (تخطّي)\n"; }
function sec(string $m): void  { echo "\n$m\n" . str_repeat('─', 62) . "\n"; }

/** يشيل تعليقات PHP/JS — التعليق بيشرح النمط فما يعتبرش استخدامًا له. */
function code_only(string $s): string
{
    $s = (string) preg_replace('#/\*.*?\*/#s', '', $s);
    return (string) preg_replace('#^\s*//.*$#m', '', $s);
}

$root = dirname(__DIR__);
$read = static fn(string $rel): string => is_file($root . '/' . $rel) ? (string) file_get_contents($root . '/' . $rel) : '';

echo "\n╔══════════════════════════════════════════════════════════════╗\n";
echo   "║  فحص فواصل الآلاف في خانات المبالغ                            ║\n";
echo   "╚══════════════════════════════════════════════════════════════╝\n";

/* ══════════════ 1) فكّ الفواصل — السلوك ══════════════ */
sec('1) `money_unformat()` — الرقم المجمَّع بيرجع خامًا');

// [الداخل, المتوقّع, الوصف]
$cases = [
    ['1,000',        '1000',        'ألف'],
    ['10,000',       '10000',       'عشرة آلاف'],
    ['100,000',      '100000',      'مية ألف'],
    ['1,250,000',    '1250000',     'مليون وربع'],
    ['12,500.75',    '12500.75',    'آلاف مع قروش'],
    ['1,250,000.50', '1250000.50',  'ملايين مع قروش'],
    ['1500,25',      '1500.25',     'فاصلة بإيد المستخدم = قروش (قرار المالك)'],
    ['1500,2',       '1500.2',      'قرش واحد بعد الفاصلة'],
    ['1000',         '1000',        'رقم عادي بيعدّي زي ما هو'],
    ['12.5',         '12.5',        'نقطة عشرية ما بتتلمسش'],
    ['0',            '0',           'صفر'],
    ['',             '',            'فاضي'],
];
foreach ($cases as [$in, $want, $why]) {
    $got = money_unformat($in);
    chk($got === $want, "«{$in}» ← «{$want}» — {$why}" . ($got === $want ? '' : " (طلع «{$got}»)"));
}

// المصيدة القاتلة نفسها: بعد الفكّ لازم الرقم يبقى صح كـfloat
$lost = (float) '1,000';                      // ← ده اللي كان هيحصل من غير الوحدة
chk(abs($lost - 1.0) < 0.0001, 'مثبَّت: `(float)"1,000"` بترجّع 1 — دي المصيدة اللي الوحدة بتمنعها');
chk(abs(((float) money_unformat('1,000')) - 1000.0) < 0.0001, 'وبعد الفكّ بقت 1000 بالظبط');

sec('2) النص اللي مش رقمًا ما يتلمسش');
$untouched = [
    '286911/9-137074/2' => 'رقم حاوية',
    'المبلغ 1,000 جنيه'  => 'جملة فيها رقم بفاصلة',
    '1,00'              => 'مجموعة ناقصة (خانتين بعد الفاصلة مش تلاتة) — تُقرا قروش لا آلاف',
    'MT-2026-1,000'     => 'معرّف فيه فاصلة',
    'أحمد'              => 'اسم',
];
foreach ($untouched as $in => $why) {
    $got = money_unformat($in);
    if ($in === '1,00') { chk($got === '1.00', "«{$in}» ← «1.00» — {$why}"); continue; }
    chk($got === $in, "«{$in}» ما اتغيّرش — {$why}" . ($got === $in ? '' : " (طلع «{$got}»)"));
}

/* ══════════════ 3) نقطة التنظيف الواحدة ══════════════ */
sec('3) نقطة تنظيف واحدة قبل أي كنترولر');

$bootstrap = code_only($read('app/bootstrap.php'));
chk(strpos($bootstrap, 'money_normalize_request()') !== false,
    '`bootstrap.php` بينادي `money_normalize_request()`');
$posAr = strpos($bootstrap, 'ar2en_normalize_request()');
$posMy = strpos($bootstrap, 'money_normalize_request()');
chk($posAr !== false && $posMy !== false && $posAr < $posMy,
    'الترتيب صح: بعد توحيد الأرقام العربية (فالنمط يشوف [0-9])');

$savedPost = $_POST; $savedGet = $_GET; $savedReq = $_REQUEST;
$_POST = [
    'amount'       => '12,500.50',
    'freight'      => '1,000',
    'items'        => ['amount' => ['3,000', '250']],
    'note'         => '1,000',            // نص حرّ — مستثنى بالاسم
    'bill_number'  => '1,000',            // معرّف — مستثنى بالاسم
    'container_no' => '1,000',            // معرّف — مستثنى بالاسم
    '_csrf'        => 'a1,000b',
];
$_GET = ['fmin' => ['freight' => '2,000']];
$_REQUEST = $_POST;
money_normalize_request();
chk($_POST['amount'] === '12500.50',       'POST: المبلغ اتنضّف');
chk($_POST['freight'] === '1000',          'POST: النولون اتنضّف');
chk($_POST['items']['amount'] === ['3000', '250'], 'POST: المصفوفات المتشعّبة اتنضّفت');
chk($_POST['note'] === '1,000',            'POST: الملاحظة ما اتلمستش (نص حرّ)');
chk($_POST['bill_number'] === '1,000',     'POST: رقم البوليصة ما اتلمسش (معرّف)');
chk($_POST['container_no'] === '1,000',    'POST: رقم الحاوية ما اتلمسش (معرّف)');
chk($_POST['_csrf'] === 'a1,000b',         'POST: التوكن ما اتلمسش');
chk($_GET['fmin']['freight'] === '2000',   'GET: فلاتر المدى اتنضّفت كمان');
$_POST = $savedPost; $_GET = $savedGet; $_REQUEST = $savedReq;

/* ══════════════ 4) تغطية الخانات ══════════════ */
sec('4) كل خانة مبلغ في الشاشات عليها `data-money`');

/* الانتقاء بالسلوك: أي `<input>` اسمه مبلغ (مش عدد ولا نسبة ولا سنة).
   لو خانة جديدة اتضافت بلا السمة، الفاحص ده بيمسكها. */
$moneyNames = '(amount|amt|freight|custody|transfer|discount|price|salary|budget|opening|init_amount|'
            . 'legacy_paid|returned|unit_amount|actual_unit|actual_amount|overnight_collection|item_amount|'
            . 'move_amount|it_amount|item_price|inspection_rate|guarantee_rate|opening_balance|monthly_salary)';
$exempt = [
    // خانات إخفاء بتتعبّى بجافاسكريبت — مش بيكتب فيها المستخدم
    'type="hidden"',
];
$missing = [];
$covered = 0;
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app/Views'));
foreach ($rii as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') { continue; }
    $rel  = str_replace($root . '/', '', $file->getPathname());
    $html = (string) file_get_contents($file->getPathname());
    if (!preg_match_all('/<input\b[^>]*>/i', $html, $m)) { continue; }
    foreach ($m[0] as $tag) {
        if (!preg_match('/\bname="' . $moneyNames . '(\[\])?(\[|")/i', $tag)) { continue; }
        foreach ($exempt as $ex) { if (strpos($tag, $ex) !== false) { continue 2; } }
        if (strpos($tag, 'data-money') !== false) { $covered++; continue; }
        $missing[] = $rel . ' → ' . mb_substr(preg_replace('/\s+/', ' ', $tag), 0, 90);
    }
}
chk($covered >= 40, "الخانات المغطّاة: {$covered} (المتوقَّع ٤٠+)");
if ($missing) {
    bad('خانات مبلغ بلا `data-money` — هتفضل بلا فواصل بالسكوت:');
    foreach (array_slice($missing, 0, 12) as $one) { echo "      · $one\n"; }
} else {
    ok('ولا خانة مبلغ فاتت بلا `data-money`');
}

// وعكسها: السمة ما تتحطّش على خانة عدد/سنة/نسبة (الفاصلة في «2,026» غلط)
$wrong = [];
foreach ($rii as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') { continue; }
    $html = (string) file_get_contents($file->getPathname());
    if (!preg_match_all('/<input\b[^>]*data-money[^>]*>/i', $html, $m)) { continue; }
    foreach ($m[0] as $tag) {
        if (preg_match('/\bname="(qty|containers?|container_count|report_year|vat_rate|payment_terms_days|minutes|stage_order|inspection_batch)/i', $tag)) {
            $wrong[] = mb_substr(preg_replace('/\s+/', ' ', $tag), 0, 80);
        }
    }
}
chk($wrong === [], 'مفيش `data-money` على خانة عدد/سنة/نسبة' . ($wrong ? ': ' . implode(' | ', $wrong) : ''));

/* ══════════════ 5) التحميل في الشاشتين ══════════════ */
sec('5) الوحدة بتتحمّل في المكتب وفي بوابة المندوب');

$layout = $read('app/Views/layout.php');
$mPwa   = $read('app/Views/m/_pwa.php');
chk(is_file($root . '/public/assets/js/money-input.js'), 'ملف `money-input.js` موجود');
chk(strpos($layout, 'money-input.js') !== false, 'شاشات المكتب بتحمّله (`layout.php`)');
chk(strpos($mPwa, 'money-input.js') !== false, 'بوابة المندوب بتحمّله (`m/_pwa.php`) — البوابة ما بتحمّلش app.js');
$pMoney = strpos($layout, 'money-input.js');
$pApp   = strpos($layout, 'assets/js/app.js') !== false ? strrpos($layout, 'assets/js/app.js') : false;
chk($pMoney !== false && $pApp !== false && $pMoney < $pApp,
    '**قبل** app.js — الاتنين بيمسكوا `beforeinput` بالالتقاط، ولو اتعكسوا معالج الأرقام العربية بيقصّ بمؤشّر النص المعروض على القيمة الخام');

/* ══════════════ 6) السمة لها شكل ══════════════ */
sec('6) `[data-money]` له تعريف CSS في الملفين');

$appCss = $read('public/assets/css/app.css');
$mCss   = $read('public/assets/css/m.css');
chk(strpos(code_only($appCss), '[data-money]') !== false, '`app.css` فيه قاعدة لـ`[data-money]`');
chk(strpos(code_only($mCss), '[data-money]') !== false, '`m.css` فيه قاعدة لـ`[data-money]`');
// المصيدة: انتقاء على `type=number` بس بيفقد شكله لما الخانة تتحوّل لنصّية
chk(preg_match('/\.mv-items input\[data-money\]/', code_only($appCss)) === 1,
    '`.mv-items` بتنتقي `[data-money]` كمان — الخانة بتتحوّل لـ`type=text` فالتوسيط كان هيضيع');

/* ══════════════ 7) خط الدفاع الأول: التنظيف عند الحفظ ══════════════ */
sec('7) القيمة بتتنضّف عند الحفظ قبل ما توصل للخادم');

$js = code_only($read('public/assets/js/money-input.js'));
chk(preg_match("/addEventListener\('submit'/", $js) === 1,
    'الوحدة بتمسك `submit` وبتشيل الفواصل قبل الإرسال');
chk(strpos($js, "Object.defineProperty") !== false && strpos($js, "'value'") !== false,
    'خاصية `value` مستبدَلة — أي كود قديم بيقرا الخانة بياخد الرقم الخام (مش «1,000»)');
chk(strpos($js, 'setCustomValidity') !== false,
    '`min`/`max` اللي كان المتصفح بيتحقّق منهم على `type=number` اتعوّضوا');
/* ⚠️ الحكم على الفاصلة **لازم** يبقى على الفرق بين قبل وبعد لا على شكل النص:
   «1,000» + صفر بتدّي «1,0000» — نفس شكل «فاصلة يدوي» بالظبط، فالحكم بالشكل
   كان بيحوّل ١٠ آلاف لـ«1.00». اتصاد في اختبار متصفح فعلي. */
chk(strpos($js, 'userDecimalComma') !== false && strpos($js, '__moneyPrev') !== false,
    'قاعدة الفاصلة بتتحكم بالفرق بين قبل وبعد الكتابة (مش بشكل النص الناتج)');

/* ══════════════ 8) تطابق PHP وجافاسكريبت ══════════════ */
sec('8) نفس الجدول بيدّي نفس الناتج في اللغتين');

/* ⚠️ على الإنتاج **كل دوال تشغيل العمليات مقفولة** (`disable_functions`) —
   ونداء دالة مقفولة في PHP 8 بيرمي `Error: Call to undefined function`
   فيقتل باقي الفحص بلا رسالة. الحارس ده إجباري، مش تجميل. */
$node = null;
if (function_exists('shell_exec')) {
    foreach (['node', '/usr/local/bin/node', '/opt/homebrew/bin/node'] as $cand) {
        $which = @shell_exec('command -v ' . escapeshellarg($cand) . ' 2>/dev/null');
        if (is_string($which) && trim($which) !== '') { $node = trim($which); break; }
    }
}
if ($node === null) {
    skip('node مش متاح — تطابق اللغتين ما اتفحصش (الفحص ده بيعدّي محليًّا)');
} else {
    /* ⚠️ الجدول ده **الأرقام المجمَّعة بس** عن قصد. الفاصلة اليدوي (قروش)
       بتتحدّد في الواجهة من **الفرق بين قبل وبعد الكتابة** لا من شكل النص
       (شوف `userDecimalComma`) — فمقارنتها بدالة PHP اللي بتشوف النص النهائي
       بس هتبقى مقارنة طبقتين مختلفتين. PHP بتتصرّف في الحالة دي كخط أخير لو
       الجافاسكريبت مقفول، وده متفحوص في القسم ١. */
    $parity = ['1,000', '10,000', '100,000', '1,250,000', '12,500.75', '1,250,000.50', '1000', '12.5', '0'];
    $stub = <<<'JS'
    const fs = require('fs');
    const path = process.argv[2];
    const listeners = {};
    global.window = {};
    global.HTMLInputElement = function () {};
    HTMLInputElement.prototype = {};
    Object.defineProperty(HTMLInputElement.prototype, 'value', {
      configurable: true, get() { return this.__v || ''; }, set(v) { this.__v = String(v); }
    });
    global.MutationObserver = function () { return { observe() {} }; };
    global.document = {
      readyState: 'complete',
      documentElement: {},
      addEventListener(t, f) { listeners[t] = f; },
      querySelectorAll() { return []; },
      matches() { return false; }
    };
    global.Event = function () {};
    new Function('require', fs.readFileSync(path, 'utf8'))(require);
    const out = { __raw: {}, __typed: {} };
    for (const c of JSON.parse(process.argv[3])) { out.__raw[c] = window.Money.raw(c); }
    for (const [b, a] of JSON.parse(process.argv[4])) {
      out.__typed[b + '→' + a] = window.Money.typedComma(b, a);
    }
    process.stdout.write(JSON.stringify(out));
JS;
    /* الحالة اللي كسرت التنفيذ الأول — [المعروض قبل, المعروض بعد, المتوقَّع] */
    $typed = [
        ['1,000', '1,0000',    null,      'رقم اتزوّد على «1,000» ← آلاف مش قروش (المصيدة)'],
        ['1,500', '1,5000',    null,      'ونفسها على «1,500»'],
        ['1,500', '1,500,',    '1,500.',  'فاصلة دقّها المستخدم ← قروش'],
        ['',      '1,250,000', null,      'ملزوق مجمَّع من إكسل ← آلاف'],
        ['1,000', '1,00',      null,      'مسح ← مالوش دعوة بالقاعدة'],
    ];
    $tmp = sys_get_temp_dir() . '/alm_money_stub_' . getmypid() . '.js';
    file_put_contents($tmp, $stub);
    $cmd = escapeshellarg($node) . ' ' . escapeshellarg($tmp) . ' '
         . escapeshellarg($root . '/public/assets/js/money-input.js') . ' '
         . escapeshellarg(json_encode($parity, JSON_UNESCAPED_UNICODE)) . ' '
         . escapeshellarg(json_encode(array_map(fn($r) => [$r[0], $r[1]], $typed), JSON_UNESCAPED_UNICODE));
    $raw = @shell_exec($cmd . ' 2>&1');
    @unlink($tmp);
    $jsOut = json_decode((string) $raw, true);
    if (!is_array($jsOut) || !isset($jsOut['__raw'])) {
        bad('ما قدرناش نشغّل `money-input.js` في node: ' . trim((string) $raw));
    } else {
        $diff = [];
        foreach ($parity as $one) {
            $php = money_unformat($one);
            $jsv = (string) ($jsOut['__raw'][$one] ?? '∅');
            if ($php !== $jsv) { $diff[] = "«{$one}»: PHP «{$php}» ≠ JS «{$jsv}»"; }
        }
        chk($diff === [], 'PHP وجافاسكريبت بيدّوا نفس الرقم الخام على كل الحالات المجمَّعة'
            . ($diff ? ' — ' . implode(' · ', $diff) : ''));

        foreach ($typed as [$before, $after, $want, $why]) {
            // ⚠️ `??` بتعتبر `null` غيابًا — والقاعدة دي `null` بتاعها **نتيجة**
            //    مقصودة (يعني «آلاف»)، فلازم `array_key_exists`.
            $key = $before . '→' . $after;
            $got = array_key_exists($key, $jsOut['__typed']) ? $jsOut['__typed'][$key] : '∅';
            chk($got === $want, "«{$before}» ← «{$after}»: {$why}"
                . ($got === $want ? '' : ' (طلع ' . var_export($got, true) . ')'));
        }
    }
}

/* ══════════════ الخلاصة ══════════════ */
$fail = count($failures);
echo "\n" . str_repeat('═', 62) . "\n";
echo "النتيجة: ✓ {$pass} ناجح · ✗ {$fail} فاشل · ~ {$skips} متخطّي\n";
if ($fail) {
    echo "\nالفشل:\n";
    foreach ($failures as $f) { echo "  ✗ $f\n"; }
}
echo str_repeat('═', 62) . "\n";
exit($fail ? 1 : 0);
