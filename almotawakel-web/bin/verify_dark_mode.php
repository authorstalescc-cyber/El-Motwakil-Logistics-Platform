<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص الوضع الليلي — إعادة البناء (2026-08-09).
 *
 * ⚠️⚠️ **العطل الأصلي مكانش «الألوان وحشة» — كان دور واحد بيخدم حاجتين.**
 *    كل لون هوية كان مستعملًا **تعبئةً ونصًّا** بنفس التوكن. لما الوضع الليلي
 *    خلّى الأسطح غامقة، النص الكحلي (`--navy` #15355B) على `--card` الغامق
 *    بقى تباينه **1.3:1** — يعني **٣٢٠ موضع** نص مش مقروء خالص. ولما جرّبنا
 *    نصلّحها بتفتيح `--red` نفسها، كسرنا الاتجاه التاني: «أبيض على أحمر»
 *    بقى **2.99:1**.
 *
 *    فالحل اللي الملف ده بيحرسه: **دورَان، توكنان.**
 *      • التوكن الأصلي (`--navy`/`--red`/`--green`/…) = **تعبئة**، ثابت.
 *      • `‑fg` (`--accent`/`--red-fg`/`--green-fg`/…) = **نص**، بيتكيّف.
 *
 * والفحوصات هنا كلها **بنيوية** (بتقيس القاعدة نفسها) مش لقطات نصّ:
 *   ① البلوكان الليليان متطابقان حرفيًّا — CSS ما بتسمحش بدمجهم فالتكرار
 *     إجباري، والافتراق بينهم بيدّي جهازًا غامقًا ألوانًا غير الاختيار الصريح.
 *   ② مفيش `color:var(<توكن تعبئة>)` — ولا `background:var(--white)`.
 *   ③ كل توكن بيتكيّف في الليلي **بيترجّع لقيمته الفاتحة في `@media print`**.
 *   ④ كل توكن مستعمل بـ`var()` **معرَّف فعلاً** — `var()` لتوكن مش موجود
 *     بيبطّل الإعلان **بالسكوت** (اللون يرجع موروثًا)، وده أخطر عطل في الباب
 *     ده لأنه ما بيرميش خطأ ولا بيبان في مراجعة الكود.
 *   ⑤ الخريطة ليها مرشِّح ليلي — بلاطاتها صور فاتحة جاية من الخادم.
 *
 * قراءة فقط بالكامل — ما بيلمسش القاعدة.
 * التشغيل:  php bin/verify_dark_mode.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$pass = 0; $failures = [];
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $failures; $failures[] = $m; echo "  ✗ $m\n"; }
function chk(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function sec(string $m): void { echo "\n$m\n" . str_repeat('─', 62) . "\n"; }
/** ⚠️ إجبارية قبل أي فحص نصّي — التعليق اللي بيوثّق القاعدة بيذكر النص الممنوع. */
function code_only(string $s): string { return (string) preg_replace('~/\*.*?\*/~s', '', $s); }

echo "فحص الوضع الليلي — دورَان، توكنان\n" . str_repeat('=', 62) . "\n";

$root = dirname(__DIR__);
$cssPath = $root . '/public/assets/css/app.css';
$css     = (string) file_get_contents($cssPath);
$cssCode = code_only($css);

/** الشاشات اللي بتحمّل `app.css` فعلاً. المستندات المستقلة (A4) ليها `:root`
 *  خاص بها وبتفضل فاتحة عن قصد — فتوكنز النظام ما تنطبقش عليها. */
$standalone = '~(print_base|_print_styles|invoice_print|settlement_print|batch_print'
            . '|/print\.php|/statement\.php|/report\.php|errors/500|tracking/driver)~';
$screens = [];
foreach (glob($root . '/app/Views/**/*.php', GLOB_BRACE) as $f) { $screens[] = $f; }
foreach (glob($root . '/app/Views/*.php') as $f) { $screens[] = $f; }
foreach (glob($root . '/app/Views/*/*.php') as $f) { $screens[] = $f; }
$screens = array_values(array_unique(array_filter($screens, fn($f) => !preg_match($standalone, $f))));

/** بلوكات `<style>` بتاعة الشاشات (الكود بس، بلا تعليقات). */
$screenCss = [];
foreach ($screens as $f) {
    $src = (string) file_get_contents($f);
    if (preg_match_all('~<style[^>]*>(.*?)</style>~s', $src, $m)) {
        $screenCss[str_replace($root . '/', '', $f)] = code_only(implode("\n", $m[1]));
    }
}

// ═══════════════════════════════════════════════════════════════════
sec('① البلوكان الليليان متطابقان حرفيًّا');
/* CSS ما بتسمحش بجمع `@media` مع مُحدِّد في قاعدة واحدة، فنفس التوكنز مكتوبة
   مرتين بالضرورة. لو افترقوا: الجهاز الغامق بيدّي ألوانًا **غير** اللي
   الاختيار الصريح بيدّيه — وتشخيص أي عطل بعدها بيبقى مستحيلًا. */
$grab = static function (string $css, string $selRe): ?string {
    if (!preg_match('~' . $selRe . '\s*\{(.*?)\n\s*\}~s', $css, $m)) { return null; }
    // نطبّع المسافات عشان المقارنة تبقى على **القيم** مش على الإزاحة
    return trim((string) preg_replace('/\s+/', ' ', $m[1]));
};
$blkAuto = $grab($cssCode, ':root:not\(\[data-theme="light"\]\)');
$blkPick = $grab($cssCode, ':root\[data-theme="dark"\]');
chk($blkAuto !== null, 'بلوك «يتبع الجهاز» موجود');
chk($blkPick !== null, 'بلوك «الاختيار الصريح» موجود');
chk($blkAuto !== null && $blkPick !== null && $blkAuto === $blkPick,
    'والبلوكان **متطابقان** — نفس التوكنز بنفس القيم');

// ═══════════════════════════════════════════════════════════════════
sec('② الدورين متفصولين — تعبئة ≠ نص');
/* التوكنز اللي **تعبئة** بس: ممنوع تتكتب `color:` لأنها ما بتتكيّفش. */
$fillTokens = ['navy', 'navy-mid', 'red', 'green', 'gold-dark', 'teal', 'purple', 'sader-dk', 'warid-dk'];
$fgOf = ['navy' => 'accent', 'navy-mid' => 'accent-2', 'red' => 'red-fg', 'green' => 'green-fg',
         'gold-dark' => 'gold-fg', 'teal' => 'teal-fg', 'purple' => 'purple-fg',
         'sader-dk' => 'sader-fg', 'warid-dk' => 'warid-fg'];
$sources = ['public/assets/css/app.css' => $cssCode] + $screenCss;
foreach ($fillTokens as $tok) {
    $hits = [];
    foreach ($sources as $name => $code) {
        // ⚠️ الـlookbehind بيمنع `background-color:` و`border-color:` من المطابقة
        if (preg_match_all('~(?<![-\w])color:\s*var\(--' . preg_quote($tok, '~') . '\)~', $code, $m)) {
            $hits[] = $name . ' ×' . count($m[0]);
        }
    }
    chk(!$hits, "مفيش `color:var(--$tok)` — التعبئة دي بديلها النصّي `--{$fgOf[$tok]}`"
        . ($hits ? ' — لقينا: ' . implode(' · ', array_slice($hits, 0, 4)) : ''));
}
$whiteBg = [];
foreach ($sources as $name => $code) {
    if (preg_match('~background(-color)?:\s*var\(--white\)~', $code)) { $whiteBg[] = $name; }
}
chk(!$whiteBg, '⚠️ ومفيش `background:var(--white)` — `--white` نص أبيض على داكن مش سطح'
    . ($whiteBg ? ' — لقينا: ' . implode(' · ', $whiteBg) : ''));

// ═══════════════════════════════════════════════════════════════════
sec('③ كل توكن بيتكيّف بيترجّع فاتحًا في الطباعة');
/* من غير كده: طباعة من جهاز غامق بتطلع بنص فاتح على ورق أبيض. */
$printBlk = null;
if (preg_match('~@media print\{\s*:root, :root\[data-theme="dark"\][^{]*\{(.*?)\n\s*\}~s', $cssCode, $m)) {
    $printBlk = $m[1];
}
chk($printBlk !== null, 'قاعدة الطباعة موجودة');
if ($printBlk !== null && $blkPick !== null) {
    preg_match_all('/(--[a-z0-9-]+)\s*:/i', $blkPick, $dm);
    preg_match_all('/(--[a-z0-9-]+)\s*:/i', $printBlk, $pm);
    /* `--white` و`--overlay` و`--shadow*` مالهاش معنى على الورق (الظل ما بيتطبعش
       والأبيض أبيض في الحالتين) — فاستثناؤها مقصود مش سهو. */
    $skip = ['--white', '--overlay', '--shadow', '--shadow-lg', '--shadow-1', '--shadow-2', '--shadow-3',
             '--auth-a', '--auth-b'];
    $missing = array_values(array_diff(array_unique($dm[1]), array_unique($pm[1]), $skip));
    chk(!$missing, 'كل توكن ليلي له قيمة فاتحة في الطباعة'
        . ($missing ? ' — ناقص: ' . implode(' · ', $missing) : ''));
}

// ═══════════════════════════════════════════════════════════════════
sec('④ ⚠️ مفيش `var()` لتوكن مش معرَّف (بيفشل بالسكوت)');
/* `var(--مش-موجود)` من غير قيمة احتياطية بيبطّل **الإعلان كله** — اللون بيرجع
   موروثًا والمتصفح ما بيقولش حاجة. ده أخطر عطل في الباب ده: اتصاد فعليًّا لما
   طبقة التوكنز اتشالت من `app.css` والصفحة فضلت «شكلها مظبوط» بالصدفة. */
preg_match_all('/(--[a-z0-9-]+)\s*:/i', $cssCode, $defs);
$defined = array_unique($defs[1]);
/* توكنز **محلّية** بتتعرّف على العنصر نفسه (أو من `style=` في الـPHP) فمش
   موجودة في `:root` — و`var()` معاها سليمة. */
$localOK = ['--spine', '--spine-dk', '--spine-fg', '--spine-lt', '--tile-fg', '--tile-lt',
            '--i', '--accent-lt'];
$undef = [];
foreach ($sources as $name => $code) {
    /* ⚠️ `var(--x, احتياطي)` **مش** عطلًا — الاحتياطي بيشتغل. الخطر في
       `var(--x)` الجافّة بس، فالنمط ده بيمسك الجافّة وحدها. */
    preg_match_all('/var\(\s*(--[a-z0-9-]+)\s*\)/i', $code, $uses);
    foreach (array_unique($uses[1]) as $u) {
        if (!in_array($u, $defined, true) && !in_array($u, $localOK, true)) { $undef[] = "$name: $u"; }
    }
}
$undef = array_values(array_unique($undef));
chk(!$undef, 'كل `var()` بتشاور على توكن معرَّف'
    . ($undef ? ' — مش معرَّف: ' . implode(' · ', array_slice($undef, 0, 6)) : ''));

// ═══════════════════════════════════════════════════════════════════
sec('⑤ الخريطة والأسطح المتبقّية');
chk(str_contains($cssCode, '.leaflet-tile-pane') && str_contains($cssCode, 'var(--map-filter)'),
    'بلاطات الخريطة ليها مرشِّح ليلي — صور فاتحة جاية من الخادم');
chk(preg_match('~--map-filter:\s*invert~', $blkPick ?? '') === 1,
    'والمرشِّح فعّال في البلوك الليلي');
/* حدّ أقصى للألوان الفاتحة المكتوبة بالإيد كخلفية. الرقم **ما يترفعش** —
   الحل في الكود: استعمل `--card`/`--zebra`/`--tint-*`/`--*-lt`. */
const BASELINE_LIGHT_BG = 0;
/* ألوان علامات تجارية خارجية — بتفضل زي ما هي في الوضعين (الأخضر ده هو
   أخضر أندرويد في شريط تثبيت التطبيق، وتغييره بيخلّيه مش متعرَّف عليه). */
$brand = ['#3ddc84', '#25d366', '#1eb356', '#1e9e5a', '#127a45'];
$lightBg = [];
foreach ($sources as $name => $code) {
    if (preg_match_all('~background(?:-color)?\s*:\s*([^;{}]*)~i', $code, $mm)) {
        foreach ($mm[1] as $val) {
            if (preg_match_all('~#([0-9a-f]{3}|[0-9a-f]{6})\b~i', $val, $hm)) {
                foreach ($hm[1] as $hex) {
                    if (strlen($hex) === 3) { $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2]; }
                    $c = array_map(static fn($h) => hexdec($h) / 255, str_split($hex, 2));
                    $c = array_map(static fn($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);
                    if (in_array('#' . strtolower($hex), $brand, true)) { continue; }
                    if (0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2] > 0.5) { $lightBg[] = "$name: #$hex"; }
                }
            }
        }
    }
}
$lightBg = array_values(array_unique($lightBg));
chk(count($lightBg) <= BASELINE_LIGHT_BG,
    sprintf('خلفيات فاتحة مكتوبة بالإيد: %d (الحد %d)', count($lightBg), BASELINE_LIGHT_BG)
    . ($lightBg ? ' — ' . implode(' · ', array_slice($lightBg, 0, 6)) : ''));

echo "\n" . str_repeat('=', 62) . "\n";
echo 'النتيجة: ' . $pass . ' ناجح · ' . count($failures) . " فاشل\n";
if ($failures) { foreach ($failures as $f) { echo "  ✗ $f\n"; } exit(1); }
exit(0);
