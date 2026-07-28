<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص «قانون التصميم» — صفر جملة تعليمية (2026-07-27).
 *
 * بلاغ المالك بالحرف:
 *   «في كل صفحة هناك جملة توضيحية كشرح في البطاقات وغيره وكأنه برنامج أطفال
 *    وهذا خطأ فادح وكبير جدًا وبيكسر التصميم واحترافية البرنامج — يجب حذف
 *    هذه الشروح بشكل كامل.»
 *
 * المقيس قبل الشغل: **١٠٢ معامل** `sub`/`note`/`hint` بيتبعت للمكوّنات
 * · **١٦٤ عنصر** بكلاس شرح · **١٠٥ وسم `<small>`** في ٣٦ ملف
 * · **٤٧ `placeholder`** أطول من ٢٥ حرف · **٨٤ `title`** أطول من ٢٠.
 *
 * ⚠️ **الفرق اللي الفحص ده قايم عليه — قرار المالك:**
 *    · **شرح تعليمي** («اضغط أي عمود عشان الشاشة تنقل» · «أرقام مشتقّة مالهاش
 *      مكان تاني» · «جرّب تغيّر الفلاتر») ← **يتشال بالكامل**.
 *    · **تحذير وظيفي** (اليوم متقفل · بنعرض ٢٥ من ٤٠٠ · النولون مستبعد) ←
 *      **يفضل**، بس في **≤٦ كلمات أو شارة**. لو اتشال، المالك ياخد قرار غلط.
 *
 * الفلسفة: الشاشة تعرض **أرقام وأسماء وأيقونات**. أي معلومة زيادة مكانها
 * **ورا ضغطة** على الرقم نفسه (قاعدة «ممنوع رقم أصمّ» — verify_design_system).
 *
 * قراءة فقط بالكامل — ما بيلمسش القاعدة ولا أي رصيد.
 * التشغيل:  php bin/verify_density.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$pass = 0; $failures = [];
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $failures; $failures[] = $m; echo "  ✗ $m\n"; }
function chk(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function sec(string $m): void { echo "\n$m\n" . str_repeat('─', 66) . "\n"; }

/* ⚠️ التعليقات في الملف ده وفي المكوّنات **بتذكر النصوص القديمة** عشان توثّقها،
   فلازم تتشال قبل أي فحص «مش موجود» وإلا إنذار كاذب (نفس مصيدة `code_only`
   في verify_security و`php_code` في verify_typography). */
function php_code(string $s): string {
    $s = preg_replace('~/\*.*?\*/~s', '', $s) ?? $s;
    return preg_replace('~^\s*(//|\*|#).*$~m', '', $s) ?? $s;
}

echo "فحص قانون التصميم — صفر جملة تعليمية\n" . str_repeat('=', 66) . "\n";

$root  = dirname(__DIR__);
$views = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app/Views'));
foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') {
        $rel = str_replace($root . '/app/Views/', '', $f->getPathname());
        $views[$rel] = (string) file_get_contents($f->getPathname());
    }
}
ksort($views);
$appCss = (string) file_get_contents($root . '/public/assets/css/app.css');
$appJs  = (string) file_get_contents($root . '/public/assets/js/app.js');

// ═══════════════════════════════════════════════════════════════════════
sec('① المكوّنات ما بقاش عندها حقل شرح أصلاً (المنبع)');

/* الإجبار **في المكوّن** مش في مراجعة بشرية: لو الحقل مش موجود، مستحيل شاشة
   جديدة ترجّع الجملة — أسوأ حاجة هيحصل إنها تتجاهل المعامل بصمت. */
$COMPONENTS = [
    'panel.php'          => ['$sub',  'd-head-sub'],
    'card.php'           => ['$sub',  'card-head-sub'],
    'page_head.php'      => ['$sub',  'page-sub'],
    'empty.php'          => ['$note', ''],
    'day_bar.php'        => ['$note', ''],
    'mini_bars.php'      => ['$__p_note', 'mb-note'],
    'count_note.php'     => ['$hint', 'count-note-hint'],
];
foreach ($COMPONENTS as $file => [$var, $cls]) {
    $src = php_code((string) file_get_contents($root . '/app/Views/partials/' . $file));
    $bad = str_contains($src, $var) || ($cls !== '' && str_contains($src, $cls));
    chk(!$bad, "`partials/{$file}` مفيهوش `{$var}`" . ($cls ? " ولا `.{$cls}`" : ''));
}

/* `drill` بيمرّر حالة الفراغ للمكوّن — لو فضل بيمرّر `note` يبقى الباب مفتوح. */
$drill = php_code((string) file_get_contents($root . '/app/Views/partials/drill.php'));
chk(!preg_match("/'note'\s*=>/", $drill),
    "و`partials/drill.php` ما بيمرّرش `note` لحالة الفراغ");

/* ⚠️ `row_item` **مستثنى عن قصد**: `sub` فيه **بيانات** («حاوية» · «عهد» · «+»)
   مش شرح — تسمية وحدة الرقم اللي جنبه. شيلها بيضيّع معلومة. */
$row = (string) file_get_contents($root . '/app/Views/partials/row_item.php');
chk(str_contains($row, '$sub'),
    '⚠️ و`row_item` **لسه** عنده `sub` — ده **بيانات** (وحدة الرقم) مش شرح، لا تشيله');

// ═══════════════════════════════════════════════════════════════════════
sec('② مفيش شاشة بتبعت شرحًا لأي مكوّن');

$KEYS = ['panel' => 'sub', 'card' => 'sub', 'page_head' => 'sub',
         'empty' => 'note', 'day_bar' => 'note', 'mini_bars' => 'note',
         'count_note' => 'hint'];
$offenders = [];
foreach ($views as $rel => $src) {
    if (str_starts_with($rel, 'partials/')) { continue; }
    $code = php_code($src);
    foreach ($KEYS as $comp => $key) {
        // نداء المكوّن ثم المفتاح جوّه أول ~٦٠٠ حرف من المصفوفة
        if (preg_match_all("~partial(?:_str)?\('" . $comp . "'\s*,\s*\[~", $code, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $hit) {
                $chunk = substr($code, $hit[1], 600);
                if (preg_match("~'" . $key . "'\s*=>~", $chunk)) {
                    $offenders[] = "{$rel} → {$comp}.{$key}";
                }
            }
        }
    }
}
chk($offenders === [], 'ولا شاشة بتبعت `sub`/`note`/`hint` لمكوّن — كانوا ٧٢ نداء'
    . ($offenders ? ': ' . implode(' · ', array_slice($offenders, 0, 5)) : ''));

// ═══════════════════════════════════════════════════════════════════════
sec('③ كلاسات الشرح اتشالت من الماركب **ومن CSS** مع بعض');

/* ⚠️ الاتجاهين لازم يمشوا مع بعض:
   · كلاس في الماركب بلا CSS = بيقع على الشكل الافتراضي **بالسكوت**
     (مصيدة `.table-mobile-scroll` الموثَّقة، عاشت شهور).
   · قاعدة CSS بلا ماركب = كود ميت بيدعو حد يستعمله تاني. */
$DEAD = ['d-head-sub', 'card-head-sub', 'page-sub', 'count-note-hint',
         'mb-note', 'ops-money-sub', 'd-hero-sub', 'cno-hint'];
foreach ($DEAD as $cls) {
    $inViews = [];
    foreach ($views as $rel => $src) {
        if (str_contains(php_code($src), $cls)) { $inViews[] = $rel; }
    }
    $inCss = str_contains($appCss, '.' . $cls);
    chk($inViews === [] && !$inCss,
        "`.{$cls}` اتشال من الاتنين"
        . ($inViews ? ' — لسه في: ' . implode(', ', $inViews) : '')
        . ($inCss ? ' — ولسه في app.css' : ''));
}

// ═══════════════════════════════════════════════════════════════════════
sec('④ مفيش جملة طويلة في نص مساعد');

/* الحد ٨ كلمات: التسمية والوحدة والعدّاد كلهم أقصر من كده بكتير، وأي حاجة
   أطول بتبقى جملة بتشرح. */
$longSmall = [];
foreach ($views as $rel => $src) {
    if (preg_match_all('~<small[^>]*>([^<]{1,400})</small>~u', $src, $m)) {
        foreach ($m[1] as $txt) {
            $t = trim($txt);
            // نتخطّى اللي فيه PHP/JS (بيانات مش نص ثابت)
            /* ⚠️ نتخطّى أي نص مبني من كود (PHP أو JS) — ده **بيانات** مش جملة
               ثابتة. `' +` بتاعة JS كانت ناقصة وأدّت إنذارًا كاذبًا في
               `tracking/show.php` و`tracking/index.php`. */
            if (str_contains($t, '<?') || str_contains($t, '${')
                || str_contains($t, "' .") || str_contains($t, "' +")
                || str_contains($t, '" +') || str_contains($t, '. $')) { continue; }
            if (count(preg_split('~\s+~u', $t, -1, PREG_SPLIT_NO_EMPTY)) > 8) {
                $longSmall[] = $rel . ': ' . mb_substr($t, 0, 46) . '…';
            }
        }
    }
}
/* ⚠️ استثناء **مؤرَّخ ومقصود**: شاشة السائق العامة `/t/{token}` — مالهاش
   تسجيل دخول، والسائق بيفتحها مرّة واحدة في الشارع ومحتاج تعليمة صريحة
   («اضغط الزرار ووافق على طلب الموقع»). تغييرها مخاطرة بلا مكسب. */
$allowedSmall = ['tracking/driver.php'];
$longSmall = array_values(array_filter($longSmall,
    static fn ($x) => !in_array(explode(':', $x)[0], $allowedSmall, true)));
chk($longSmall === [], 'مفيش `<small>` فيه جملة أطول من ٨ كلمات'
    . ($longSmall ? ' — ' . implode(' · ', array_slice($longSmall, 0, 4)) : ''));

// ═══════════════════════════════════════════════════════════════════════
sec('⑤ `placeholder` و`title` مختصرين');

/* `placeholder` بيتقرا **جوّه** الخانة الفاضية — لو طويل بيتقصّ في نص كلمة
   والمستخدم يقرا نصّ جملة. الـ٢٥ حرف = حوالي عرض خانة متوسطة. */
$longPh = [];
foreach ($views as $rel => $src) {
    if (preg_match_all('~placeholder="([^"<$]{26,})"~u', $src, $m)) {
        foreach ($m[1] as $t) { $longPh[] = $rel . ': ' . mb_substr($t, 0, 42) . '…'; }
    }
}
/* ⚠️ المسموح: اللي بيقول **قاعدة أو نتيجة** («سيبه فاضي عشان ما يتغيّرش» ·
   «لا يتكرّر» · «هياخد رقم اللوحة») أو **أمثلة** لحقل نص حر — دول بيمنعوا
   غلطة إدخال، مش بيشرحوا الواجهة. */
$ALLOW_PH = [
    'أي بيان إضافي يظهر في المستند',
    'اتركه فارغاً للعنوان الافتراضي (فاتورة / كشف حساب / مطالبة بالإيصالات)',
    'تخصيص / حمولة / تعتيق / هيئة / رسوم محصّلة',
    'رقم مرجعي للعميل — لا يتكرّر',
    'لو سبته فاضي هياخد رقم اللوحة',
    'مثال: permits  أو  closing — سيبها فاضية عشان يفتح الرئيسية',
    'مثال: سلّمته كاش في الميناء',
    'مثال: مواصلات أو تصوير مستندات',
];
$longPh = array_values(array_filter($longPh, static function ($x) use ($ALLOW_PH) {
    foreach ($ALLOW_PH as $a) { if (str_contains($x, mb_substr($a, 0, 20))) { return false; } }
    return true;
}));
chk($longPh === [], 'مفيش `placeholder` بيعدّ حقول البحث ولا بيشرح الواجهة — كانوا ٢١'
    . ($longPh ? ' — ' . implode(' · ', array_slice($longPh, 0, 4)) : ''));

/* `title` بيظهر بعد وقفة بالماوس و**مش موجود خالص على اللمس** — فممنوع يحمل
   معلومة لازمة، لكنه **المكان الصح** لتفصيل تحذير وظيفي اختصرناه لشارة:
   المعلومة موجودة لمين يدوّر عليها، وبرّه الشاشة لمين مش محتاجها.
   ⚠️ الحد **١٢٠ حرف = جملة واحدة**. أطول من كده يبقى فقرة، ومحدّش بيقرا فقرة
      في tooltip. (الحد كان ٨٠ وفشّل ٥ تلميحات مشروعة أطولها ٩٣ — أرفع كان
      أنضف من إني أقصّر شرحًا ماليًّا صحيحًا.) */
$phLong = [];
foreach ($views as $rel => $src) {
    if (preg_match_all('~title="([^"<$]{121,})"~u', $src, $m)) {
        foreach ($m[1] as $t) { $phLong[] = $rel . ' (' . mb_strlen($t) . ' حرف)'; }
    }
}
chk($phLong === [], 'ومفيش `title` أطول من ١٢٠ حرف (= فقرة مش جملة)'
    . ($phLong ? ' — ' . implode(' · ', array_slice($phLong, 0, 3)) : ''));

// ═══════════════════════════════════════════════════════════════════════
sec('⑥ التحذيرات الوظيفية **لسه موجودة** — الشيل كان للشرح بس');

/* ⚠️ القسم ده بيحرس الاتجاه التاني: لو حد شال جملة وظيفية بالغلط وهو
   «بينضّف»، المالك ياخد قرار غلط. كل بند هنا اتقرّر إنه يفضل. */
$KEEP = [
    ['partials/day_bar.php', 'مش تاريخ النهاردة', 'تحذير إنك شغّال على يوم فات'],
    ['partials/day_bar.php', 'مُقفَل', 'شارة اليوم المتقفل'],
    ['partials/count_note.php', 'من', 'إشارة القطع «٢٥ من ٤٠٠»'],
    ['ops/index.php', 'بلا نولون', 'شارة استبعاد النولون من التقرير'],
    ['crud/form.php', 'مش بتدخل التقفيل', 'عزل الدفعة التاريخية'],
    ['auth/login.php', 'كتابة على الأيام المقفولة', 'أثر صلاحية الدخول الحر'],
];
foreach ($KEEP as [$file, $needle, $why]) {
    $src = $views[$file] ?? '';
    /* ⚠️ مصيدة PHP الموثَّقة (v38 · v49 · v50 · لوحة الأمان): بايتات «» بتتحسب
       **جزءًا من اسم المتغيّر** فـ"«$needle»" بتدّي Undefined variable.
       استخدم {$needle} دايمًا. */
    chk(str_contains($src, $needle), "«{$why}» لسه موجود في `{$file}`");
}

/* الشارة لازم يكون معاها التفصيل في `title` — الاختصار مش معناه ضياع السبب. */
$ops = $views['ops/index.php'] ?? '';
chk(preg_match('~class="pill" title="[^"]{30,}"[^>]*>.{0,40}بلا نولون~u', $ops) === 1,
    'وشارة «بلا نولون» معاها التفصيل الكامل في `title`');

// ═══════════════════════════════════════════════════════════════════════
sec('⑦ العزل — الفحص ده عرض بحت');

$touched = 0;
foreach ($views as $rel => $src) {
    if (preg_match('~\b(INSERT|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b~i', php_code($src))) { $touched++; }
}
chk($touched === 0, "مفيش استعلام كتابة في أي ملف عرض (لقينا {$touched})");

$hasBalance = class_exists('\App\Models\Custody');
if ($hasBalance) {
    $before = \App\Models\Custody::balance();
    /* الفحص ما بيكتبش حاجة أصلاً — بنقيس مرّتين عشان نثبّت إن مفيش أثر جانبي. */
    $after = \App\Models\Custody::balance();
    chk(abs($before - $after) < 0.001,
        'ورصيد خزنة المكتب قبل = بعد (' . number_format($before, 2) . ') — الحد الأحمر');
} else {
    echo "  ⓘ `Custody` مش متاحة — فحص الرصيد اتخطّى\n";
}

// ═══════════════════════════════════════════════════════════════════════
echo "\n" . str_repeat('=', 66) . "\n";
echo 'نجح: ' . $pass . '   |   فشل: ' . count($failures) . "\n";
if ($failures) {
    echo "\n✗ الفحص فشل — الكود ده ما يترفعش.\n";
    foreach ($failures as $f) { echo "   · $f\n"; }
    exit(1);
}
echo "\n✓ كل الفحوصات عدّت.\n";
