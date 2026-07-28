<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص مرحلة «لغة التصميم أ» — الخط والأوزان والسلالم وشرايط الترويسة (2026-07-26).
 *
 * السبب من أول الطريق: النقد البصري للنظام لقى إن أكبر أربع أسباب لإحساس
 * «القِدَم» مش في التنظيم — في أربع قرارات بصرية:
 *
 *   ① **الخط.** المشروع كان على `'Segoe UI','Tahoma'` — وعلى الماك/الآيفون
 *      مفيش Segoe فكان بيقع على **Tahoma** (خط ويندوز من 1999، إيقاعه العربي
 *      متعب). ده وحده أكبر سبب منفرد. الحل: IBM Plex Sans Arabic **مستضاف
 *      محليًا** (الـCSP بتمنع أي CDN — قرار المرحلة الأمنية ١).
 *
 *   ② **كل حاجة Bold.** كان في `app.css` **٢٠٤ قاعدة بوزن 800** و**١٤٤ بـ700**
 *      و`--fw-normal:500`. لما كل حاجة بارزة، مفيش حاجة بارزة. السلّم الجديد:
 *      ثانوي 400 · جسم/تسميات 500 · عناوين 600 · أرقام بطلة 700.
 *
 *   ③ **مفيش سلّم.** ٦٤ مقاس خط مختلف مكتوب بالإيد و٢٨٦ استدارة، رغم إن
 *      التوكنز موجودة من المرحلة ٠ ومتبنّاة ١٢٪ بس.
 *
 *   ④ **الأصفر لون تحذير — وكان خلفية الحالة الطبيعية.** التاريخ كان بيتعرض
 *      **مرتين فوق بعض** (شريط التخطيط + شريط اليوم) والاتنين ذهبيين.
 *
 * ⚠️ **الفحص ده بيحرس قرارات، مش أرقامًا.** لو غيّرت السلّم غيّر القيم هنا
 *    معاه — بس ما تشيلش الفحص نفسه وإلا الفوضى القديمة بترجع بالتدريج.
 *
 * قراءة فقط بالكامل — ما بيلمسش القاعدة ولا أي رصيد.
 * التشغيل:  php bin/verify_typography.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$pass = 0; $failures = [];
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $failures; $failures[] = $m; echo "  ✗ $m\n"; }
function chk(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function sec(string $m): void { echo "\n$m\n" . str_repeat('─', 62) . "\n"; }
/* التعليقات بتذكر النصوص القديمة عشان توثّقها — لازم تتشال قبل أي فحص «مش موجود»
   وإلا إنذار كاذب (نفس مصيدة `code_only` في verify_security). */
function css_code(string $s): string { return preg_replace('~/\*.*?\*/~s', '', $s) ?? $s; }
function php_code(string $s): string {
    $s = preg_replace('~/\*.*?\*/~s', '', $s) ?? $s;
    return preg_replace('~^\s*(//|\*|#).*$~m', '', $s) ?? $s;
}

echo "فحص لغة التصميم — المرحلة أ (الخط · الأوزان · السلالم · الترويسة)\n" . str_repeat('=', 62) . "\n";

$root = dirname(__DIR__);
$app  = (string) file_get_contents($root . '/public/assets/css/app.css');
$m    = (string) file_get_contents($root . '/public/assets/css/m.css');
$appC = css_code($app);
$mC   = css_code($m);

$views = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app/Views'));
foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') {
        $views[str_replace($root . '/', '', $f->getPathname())] = (string) file_get_contents($f->getPathname());
    }
}

// ═══════════════════════════════════════════════════════════════════════
sec('① الخط — Cairo متغيّر، مستضاف محليًا، وبيغطّي المكتب والبوابة');

chk(str_contains($appC, "--font:'Cairo'"), 'المكتب بيبدأ بـ`Cairo`');
chk(str_contains($mC, "--font:'Cairo'"), 'وبوابة المناديب كمان (`m.css` مستقلة تمامًا عن app.css)');
chk(!preg_match("/--font:\s*'(Segoe UI|Plex Arabic)'/", $appC . $mC),
    "⚠️ ومفيش ملف لسه بيبدأ بـ'Segoe UI' ولا 'Plex Arabic'");

/* ⚠️ كتلة @font-face **مكرَّرة عن قصد** في الملفين: بوابة `/m` ما بتحمّلش
   app.css خالص، والمتصفح بيعمل dedupe بالرابط فالخط بيتنزّل مرّة واحدة —
   يعني التكرار مالوش تكلفة، لكن **التفرّع بينهم** يكسر البوابة بالسكوت. */
preg_match_all("~url\('\.\./fonts/(cairo-[^']+\.woff2)'\)~", $app, $ma);
preg_match_all("~url\('\.\./fonts/(cairo-[^']+\.woff2)'\)~", $m, $mm);
$appFonts = $ma[1]; $mFonts = $mm[1];
chk(count($appFonts) === 2, 'المكتب فيه وجهان بس (عربي + لاتيني) — لقينا ' . count($appFonts));
chk($appFonts === $mFonts, '⚠️ والملفان بيشاورا على **نفس** ملفات الخط بالظبط — أي تفرّع بيكسر البوابة بالسكوت');

$missing = [];
foreach (array_unique($appFonts) as $fn) {
    if (!is_file($root . '/public/assets/fonts/' . $fn)) { $missing[] = $fn; }
}
chk($missing === [], 'وكل ملفات الخط موجودة فعلاً على القرص' . ($missing ? ': ناقص ' . implode(', ', $missing) : ''));
$stale = glob($root . '/public/assets/fonts/plex-ar-*.woff2') ?: [];
chk($stale === [], 'وملفات Plex الثمانية اتمسحت من القرص (' . count($stale) . ' باقي)');
chk(substr_count($app, 'font-display:swap') >= 2,
    'و`font-display:swap` على كل وجه — النص يبان فورًا بخط النظام لحد ما الخط ينزل');
chk(str_contains($app, 'unicode-range:') && str_contains($m, 'unicode-range:'),
    'و`unicode-range` موجودة — اللاتيني ما بينزلش إلا لو الصفحة فيها حروف لاتينية');
chk(!preg_match('~@import|fonts\.googleapis\.com|fonts\.gstatic\.com~', $app . $m),
    '⚠️ ومفيش أي طلب لخط من برّه — الـCSP بتمنعه والخط كان هيفشل بالسكوت');

// ═══════════════════════════════════════════════════════════════════════
sec('①ب ⚠️ الوزن المعلَن = الوزن الحقيقي جوّه الملف (الباج اللي عاش من غير ما حد ياخد باله)');

/* ⚠️⚠️ **الفحص ده هو سبب وجود القسم.** الإعلان القديم كان بيقول إن ملفات
   400 و500 و600 كلها `font-weight:400` وملف 700 `font-weight:500`. النتيجة:
     · نص الجسم (400) كان بيترسم بملف الـ**600 SemiBold** (آخر وجه بيكسب)
     · `600` و`700` ما كانش ليهم وجه فالمتصفح بيزوّد **Bold صناعي**
   يعني سلّم الأوزان الأربعة كان **معطَّلًا بالكامل** والنظام كله تقيل ومتلزّق.
   ما كانش فيه أي فحص بيقارن المعلَن بالحقيقي — عشان كده عاش. */

/** الأوزان اللي الوجوه المعلَنة بتغطّيها فعلاً (نطاق أو قيمة مفردة). */
$declared = [];   // [[min, max, file], …]
/* ⚠️ `\d{3,4}` مش `\d{3}` — النطاق بيوصل لـ`1000` (أربع خانات)، و`\d{3}`
   كانت بتقصّه لـ`100` فيطلع المدى «200–100» ويبان **كل** وزن مستعمل وكأنه
   بلا وجه. اتصادت فعليًّا وأنا بكتب الفحص. */
preg_match_all(
    "~@font-face\{[^}]*?font-weight:\s*(\d{3,4})(?:\s+(\d{3,4}))?[^}]*?url\('\.\./fonts/([^']+)'~s",
    $app, $faces, PREG_SET_ORDER);
chk(count($faces) === 2, 'اتقرا وجهان من `app.css` — لقينا ' . count($faces));

/* ⚠️ **`shell_exec` مقفولة على الاستضافة** (`disable_functions` فيها system ·
   exec · shell_exec · passthru · popen · proc_open) — قاعدة موثَّقة في
   CLAUDE.md. لازم نتأكد **قبل** النداء مش نعتمد على `@`: النداء نفسه بيوقّع
   السكربت (اتصادف فعليًا — exit 255 والفحص بيقف في نصّه على السيرفر).
   الفحص العميق ده مكانه الجهاز المحلي في خطوة 0 من `deploy.sh` قبل الرفع. */
$disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
$canShell = function_exists('shell_exec') && !in_array('shell_exec', $disabled, true);

$fontToolsOk = $canShell;
foreach ($faces as $f) {
    if (!$canShell) { $declared[] = [(int) $f[1], $f[2] !== '' ? (int) $f[2] : (int) $f[1]]; continue; }
    $lo = (int) $f[1];
    $hi = $f[2] !== '' ? (int) $f[2] : $lo;
    $file = $root . '/public/assets/fonts/' . $f[3];
    $declared[] = [$lo, $hi];

    // الوزن الحقيقي من الملف نفسه (fvar للمتغيّر، OS/2 للثابت)
    $py = <<<'PY'
import sys
try:
    from fontTools.ttLib import TTFont
except Exception:
    print("NOFONTTOOLS"); raise SystemExit
f = TTFont(sys.argv[1])
if "fvar" in f:
    for a in f["fvar"].axes:
        if a.axisTag == "wght":
            print(f"{int(a.minValue)} {int(a.maxValue)}"); raise SystemExit
print(f'{f["OS/2"].usWeightClass} {f["OS/2"].usWeightClass}')
PY;
    $out = trim((string) @shell_exec('python3 -c ' . escapeshellarg($py) . ' '
        . escapeshellarg($file) . ' 2>/dev/null'));

    if ($out === '' || $out === 'NOFONTTOOLS') {
        $fontToolsOk = false;
        continue;
    }
    [$realLo, $realHi] = array_map('intval', explode(' ', $out));
    chk($realLo <= $lo && $hi <= $realHi,
        "⚠️ `{$f[3]}`: المعلَن {$lo}–{$hi} جوّه المدى الحقيقي {$realLo}–{$realHi}");
}

if (!$fontToolsOk) {
    /* fontTools أو shell_exec مش متاحين (الإنتاج) — ما نفشّلش الرفع بإنذار
       كاذب؛ الفحص المحلي قبل الرفع هو اللي بيمسك الباج. نفس أسلوب تخطّي
       فحص النحو في `verify_security`. */
    echo "  ⓘ فحص الوزن الحقيقي جوّه ملف الخط اتخطّى هنا "
       . ($canShell ? '(fontTools مش متاحة)' : '(shell_exec مقفولة على الاستضافة)')
       . " — بيتشغّل محليًّا قبل الرفع\n";
}

/* كل وزن مستعمل في CSS لازم يقع جوّه مدى وجه معلَن، وإلا Bold صناعي. */
$usedWeights = [];
preg_match_all('/font-weight:\s*(\d{3})(?!\s+\d)/', $appC . $mC, $uw);
foreach ($uw[1] as $v) { $usedWeights[(int) $v] = true; }
$synthetic = [];
foreach (array_keys($usedWeights) as $w) {
    $covered = false;
    foreach ($declared as [$lo, $hi]) { if ($w >= $lo && $w <= $hi) { $covered = true; break; } }
    if (!$covered) { $synthetic[] = $w; }
}
sort($synthetic);
chk($synthetic === [],
    '⚠️ كل وزن مستعمل في CSS له وجه حقيقي — مفيش Bold صناعي'
    . ($synthetic ? ' — بلا وجه: ' . implode(', ', $synthetic) : ''));

// ═══════════════════════════════════════════════════════════════════════
sec('①ج الخط بيوصل للورق وللشاشات المستقلة كمان');

/* ⚠️ مستندات A4 **مستقلة وما بتحمّلش app.css**، وكانت بتعلن `'Segoe UI',Tahoma`
   بنفسها — يعني **كل فاتورة وكشف حساب راح للعميل كان بخط تاني غير خط النظام**
   (وعلى الماك Tahoma). واتنين منها كانوا بيطلبوا `'IBM Plex Sans Arabic'` وهو
   اسم **ما كانش معلَنًا في أي مكان**. الوجه دلوقتي في `print_base`. */
$pb = (string) file_get_contents($root . '/app/Views/partials/print_base.php');
chk(substr_count($pb, "@font-face{font-family:'Cairo'") === 2,
    '`print_base` فيه وجها الخط — مستندات A4 مستقلة ومحتاجة الإعلان جوّاها');
chk(str_contains($pb, "--font:'Cairo'"), 'وفيه توكن `--font` عشان المستندات تقراه');

$hardFont = [];
foreach ($views as $p => $src) {
    if (preg_match('/font-family:\s*[\'"]?(Segoe UI|Tahoma|IBM Plex)/i', $src)) {
        $hardFont[] = str_replace('app/Views/', '', $p);
    }
}
/* ⚠️ استثناءان **مقصودان وموثَّقان**: شاشة السائق العامة `/t/{token}` (مالهاش
   login والتغيير فيها مخاطرة بلا مكسب) وصفحة الخطأ 500 (لازم تفضل بلا أي
   تبعية عشان تشتغل والنظام واقع). */
$allowedHard = ['tracking/driver.php', 'tracking/driver_closed.php', 'errors/500.php'];
$badHard = array_values(array_diff($hardFont, $allowedHard));
chk($badHard === [], '⚠️ ومفيش شاشة بتكتب اسم خط بإيدها — كانوا ١٣ مستند'
    . ($badHard ? ': ' . implode(', ', $badHard) : ''));

foreach (['offline.html', 'm-offline.html'] as $off) {
    $src = (string) @file_get_contents($root . '/public/assets/' . $off);
    chk(str_contains($src, "@font-face{font-family:'Cairo'") && str_contains($src, "font-family:'Cairo'"),
        "و`{$off}` بتعلن الخط بنفسها (مستقلة، ما بتحمّلش أي ورقة أنماط)");
}
$sw = (string) file_get_contents($root . '/public/sw.js');
chk(str_contains($sw, 'cairo-arabic.woff2'),
    'والـService Worker بيكاش الخط مسبقًا — وإلا صفحة «مفيش نت» تطلع بخط النظام');

/* ⚠️ ارتفاع عناصر الإدخال: المتصفح بيطبّق اختصار `font` عليها في ورقته
   الافتراضية، والاختصار **بيصفّر `line-height` لـ`normal`** — يعني الارتفاع
   بيتحسب من مقاييس الخط. صندوق سطر Cairo العادي أطول من Plex بـ٢٥٪
   (1.874em مقابل 1.500em)، فمن غير رقم صريح كل زرار وخانة كانوا هيطولوا ~٥px. */
chk(preg_match('/button,input,select,textarea\{[^}]*line-height:\s*1\.5/', $appC) === 1,
    '⚠️ وعناصر الإدخال ليها `line-height` رقمية في app.css — ثابتة مهما كان الخط');
chk(preg_match('/button,input,select,textarea\{[^}]*line-height:\s*1\.5/', $mC) === 1,
    'ونفسها في m.css');

// ═══════════════════════════════════════════════════════════════════════
sec('② الأوزان — سلّم من أربع درجات بدل «كله bold»');

$w = [];
preg_match_all('/font-weight:\s*(\d{3})/', $appC, $mw);
foreach ($mw[1] as $v) { $w[$v] = ($w[$v] ?? 0) + 1; }
ksort($w);
/* ⚠️ مصيدة PHP الموثَّقة (v38 · v49 · لوحة الأمان · المرحلة ٩): بايتات `×`
   بتتحسب **جزءًا من اسم المتغيّر** فـ"$k×$v" بتدّي «Undefined variable $k×».
   استخدم {$k} دايمًا مع أي رمز يونيكود ملزوق بمتغيّر. */
echo '     التوزيع في app.css: ' . implode(' · ', array_map(fn($k, $v) => "{$k}×{$v}", array_keys($w), $w)) . "\n";

$heavyCss = array_sum(array_filter($w, fn($k) => (int) $k > 700, ARRAY_FILTER_USE_KEY));
chk($heavyCss === 0, '⚠️ مفيش وزن أتقل من 700 في app.css (كان ٢٠٤×800 + ١٩×900)');

$heavyViews = [];
foreach ($views as $p => $src) {
    if (preg_match('/font-weight:\s*(800|900)/', php_code($src))) { $heavyViews[] = basename($p); }
}
chk($heavyViews === [], 'ولا في أي شاشة (الأوزان التقيلة كانت في ٦٣ ملف عرض كمان)'
    . ($heavyViews ? ': ' . implode(', ', array_slice($heavyViews, 0, 6)) : ''));

chk(str_contains($appC, '--fw-normal:400'), 'التوكن `--fw-normal` بقى 400 (كان 500 — يعني «العادي» نفسه كان تقيل)');
chk(preg_match('/\bb,\s*strong\s*\{[^}]*font-weight:600/', $appC) === 1,
    '⚠️ و`<b>/<strong>` متظبّطين على 600 — الافتراضي في المتصفح 700 وكان بيتسرّب لكل نص مالوش قاعدة');
chk(($w['700'] ?? 0) > 0 && ($w['700'] ?? 0) < 80,
    'ووزن 700 محجوز للأرقام البطلة والعناوين الكبرى بس (' . ($w['700'] ?? 0) . ' قاعدة)');

// ═══════════════════════════════════════════════════════════════════════
sec('③ السلالم — مقاس الخط والاستدارة من التوكنز');

$rawFs = [];
preg_match_all('/font-size:\s*([0-9]*\.?[0-9]+)rem/', $appC, $mf);
foreach ($mf[1] as $v) { $rawFs[$v] = true; }
chk($rawFs === [], '⚠️ مفيش ولا مقاس خط مكتوب بالإيد في app.css — كانوا ٤٦٥ بـ٦٤ قيمة مختلفة'
    . ($rawFs ? ': ' . implode(', ', array_keys($rawFs)) : ''));

$rawViews = [];
foreach ($views as $p => $src) {
    if (preg_match('/font-size:\s*[0-9]*\.?[0-9]+rem/', php_code($src))) { $rawViews[] = basename($p); }
}
chk($rawViews === [], 'ولا في أي شاشة' . ($rawViews ? ': ' . implode(', ', array_slice($rawViews, 0, 6)) : ''));

/* ⚠️ الأرضية دي مكتوبة في توكنز المرحلة ٠ من زمان — وكانت **متكسّرة في ١٦٧
   موضع** (أصغرها .66rem ≈ ١٠px). التلزيق بالسلّم صلّحها كلها. */
chk(!preg_match('/font-size:\s*\.(6[0-9]|7[0-4])rem/', $appC),
    '⚠️ ومفيش نص تحت أرضية .75rem (القاعدة كانت موثَّقة ومكسورة في ١٦٧ موضع)');

$rawR = [];
preg_match_all('/border-radius:\s*(\d+)px(?=\s*[;}!])/', $appC, $mr);
foreach ($mr[1] as $v) { if ((int) $v < 900) { $rawR[$v] = ($rawR[$v] ?? 0) + 1; } }
chk(array_sum($rawR) <= 2, 'الاستدارات من التوكنز (٤ درجات) — الباقي المسموح ≤٢، لقينا ' . array_sum($rawR));

// ═══════════════════════════════════════════════════════════════════════
sec('④ الترويسة — التاريخ مرّة واحدة، والأصفر للتحذير بس');

chk(str_contains($appC, '.content:has(.day-bar) .workbar{display:none}'),
    '⚠️ الشاشة اللي فيها `day_bar` بتخفي شريط التخطيط — التاريخ كان بيتعرض مرتين فوق بعض');
chk(preg_match('/\.workbar\{[^}]*background:var\(--card\)/', $appC) === 1,
    'وشريط تاريخ العمل بقى سطحًا محايدًا (كان ذهبيًا **دايمًا**)');
chk(preg_match('/\.workbar\.warn\{[^}]*background:var\(--gold-light\)/', $appC) === 1,
    'والذهبي محجوز لـ`.warn` — يوم مش النهاردة');
chk(preg_match('/\.day-bar\{[^}]*background:var\(--card\)/', $appC) === 1, 'ونفس القرار على شريط اليوم');
chk(preg_match('/\.day-bar\.warn\{/', $appC) === 1, 'وله حالة `.warn` هو كمان');

$dayBar = (string) file_get_contents($root . '/app/Views/partials/day_bar.php');
chk(str_contains($dayBar, "' — مش تاريخ النهاردة، خلّي بالك!'"),
    '⚠️ و`day_bar` بيقول «مش تاريخ النهاردة» بنفسه — هو بيخفي الشريط اللي كان بيقولها');
chk(str_contains($dayBar, "\$isToday ? '' : ' warn'"), 'وبياخد `.warn` تلقائيًا في اليوم اللي مش النهاردة');

chk(preg_match('/\.content\{[^}]*max-width:1480px/', $appC) === 1,
    'وللمحتوى عرض أقصى — من غيره الصف بيتمدّد على الشاشات العريضة والعين بتضيع');

// ═══════════════════════════════════════════════════════════════════════
sec('⑤ باج العملة المكرّرة (اتصادف أثناء المرحلة)');

/* `money0()` بترجّع العملة جوّه النص، ومكوّن `stat` بيعرض `unit` كمان —
   فالداشبورد كانت بتقول «0 ج.م ج.م» و«245,050 ج.م ج.م». */
$dash = php_code($views['app/Views/dashboard/index.php'] ?? '');
chk(!preg_match("/money0\([^)]*\)\s*,\s*'unit'/", $dash) || str_contains($dash, 'money0($daily[\'expenses\'], false)'),
    '⚠️ الداشبورد بتستخدم `money0(..., false)` مع `unit` — وإلا العملة بتتكرر مرتين');
chk(!str_contains($dash, "money0(\$custody) . ' ' . \$cur"),
    'وحقيقة الخزنة في شريط اليوم بلا عملة مضافة بالإيد');
$hlp = (string) file_get_contents($root . '/app/Core/helpers.php');
chk(preg_match('/function money0\(\$v, bool \$withCurrency = true\)/', $hlp) === 1,
    'و`money0()` بقى ليها مفتاح عملة زي `money()` بالظبط');

// ═══════════════════════════════════════════════════════════════════════
sec('⑥ المرحلة ب — الهوية: سطح محايد ولون بمعنى وحركة بلا زخرفة');

/* ⚠️ فيه **تعريفان** لـ`.sidebar` في الملف (الأول في التخطيط العام والتاني
   في قسم لغة التصميم). الأخير بيغلب — فالفحص بيتأكد إن **مفيش ولا واحد**
   فيهم رجع للتدرّج، مش إن الأول اتغيّر بس. */
chk(!preg_match('/\.sidebar\{[^}]*linear-gradient/', $appC),
    '⚠️ الشريط الجانبي سطح مصمت — مفيش تدرّج في أي من تعريفَيه');
chk(!preg_match('/\.nav \.section\{[^}]*linear-gradient/', $appC),
    'وعناوين الأقسام بلا لوح لون ورا النص');

$lifts = preg_match_all('/transform:translateY\(-\dpx\)/', $appC);
chk($lifts === 0, '⚠️ مفيش رفع عند المرور (`translateY`) — كانت ٢١ حالة، والحركة اتحجزت للمعنى'
    . ($lifts ? " — لقينا $lifts" : ''));
chk(preg_match('/\.btn:hover\{background:var\(--navy-dark\)\}/', $appC) === 1,
    'والزر بيغيّر لونه بس عند المرور');

chk(preg_match('/\.d-kpi::before\{[^}]*background:var\(--line2\)/', $appC) === 1,
    '⚠️ شريط كارت الرقم محايد افتراضيًا — اللون بقى **معنى** (أحمر/أخضر) مش تنويعًا');
chk(preg_match('/\.d-kpi\.red::before\{background:var\(--red\)\}/', $appC) === 1,
    'والأحمر لسه بيوصل لكارت الفلوس الطالعة');
chk(!preg_match('/\.d-kpi-ic\{[^}]*color-mix/', $appC),
    'وأيقونة الكارت بلا مربّع باستيل وراها');

/* ⚠️ الظل والحدّ مع بعض على نفس السطح = «احتواء ثلاثي». التوكن بقى شبه
   معدوم، والظل الحقيقي محجوز للطبقات العايمة (`--shadow-lg`). */
preg_match('/--shadow:([^;]+);/', $appC, $sh);
chk(isset($sh[1]) && str_contains($sh[1], '0 1px 2px'),
    'وظل الأسطح بقى حدًّا شعرة (الظل الحقيقي للمودالات بس)');

/* ⚠️ الإيموجي بيتغيّر شكله من نظام لنظام وبيكسر اللون والمحاذاة. المسموح
   الوحيد جوّه **تعليقات المطوّرين** و`<script>/<style>` (نصوص جافاسكريبت). */
/* ⚠️ **الحدّ ده سقف مش هدف صفري — وده مقصود.** اتحوّل ٦٥٣ إيموجي لأيقونات
   SVG، والباقي (~٣٢) **مالوش مقابل في `icon()`** (🚢 🤝 💡 🎉 🤖 …) — وتحويله
   لأيقونة قريبة-مش-مظبوطة أسوأ من إبقائه. لو ضفت أيقونة مناسبة في `icon()`
   حوّل اللي يخصّها ونزّل السقف. **ممنوع ترفع السقف عشان إيموجي جديد.** */
$emojiRe = '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u';
$emojiCount = 0; $emojiViews = [];
foreach ($views as $p => $src) {
    $code = preg_replace('~<script\b.*?</script>|<style\b.*?</style>|<!--.*?-->~s', '', php_code($src)) ?? '';
    // الجزء الظاهر للمستخدم = برّه كتل PHP
    $html = preg_replace('~<\?(php|=).*?\?>~s', '', $code) ?? '';
    $n = preg_match_all($emojiRe, $html);
    if ($n) { $emojiCount += $n; $emojiViews[] = basename($p) . "($n)"; }
}
chk($emojiCount <= 40,
    '⚠️ الإيموجي الظاهر للمستخدم اتحوّل لأيقونات SVG (كان ٧٤٧) — الباقي '
    . $emojiCount . ' بلا مقابل في icon()' . ($emojiCount > 40 ? ': ' . implode(', ', array_slice($emojiViews, 0, 8)) : ''));

// ═══════════════════════════════════════════════════════════════════════
echo "\n" . str_repeat('=', 62) . "\n";
echo 'نجح: ' . $pass . '   |   فشل: ' . count($failures) . "\n";
if ($failures) {
    echo "\n✗ الفحص فشل — الكود ده ما يترفعش:\n";
    foreach ($failures as $f) { echo "  • $f\n"; }
    exit(1);
}
echo "\n✓ كل الفحوصات عدّت.\n";
