<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص إعادة بناء شاشة الإذن — وضع العرض (v103، 2026-08-17).
 *
 * طلب المالك بالحرف: «محتاج نعيد بناء صفحة الإذن المنفرد بتصميم مختلف وشكل
 * أفضل وتجربة مستخدم أفضل بكثير … + في البنود يجب أن يكون بجانبها badge
 * للمندوب الذي قام بإدخال هذا البند».
 *
 * المحروس هنا **سلوك مش شكل**:
 *   ① **شارة المندوب** بتترسم جنب البند فعلاً، ومن **مكوّن واحد** بيخدم شاشة
 *      العرض ووضع التعديل ونافذة النظرة العامة — والاسم جاي من الموديل
 *      (`rep_name`) لا من استعلام في الشاشة.
 *   ② **بند المكتب مالوش شارة** — الغياب معلومة، والشارة على كل سطر ضجيج.
 *   ③ **صفر جداول في وضع العرض** — الجدول بـ١١ عمودًا كان بياخد تلات أشكال
 *      حسب عرض الشاشة؛ الصف شكله واحد.
 *   ④ **الأرقام بتطابق مصادرها**: مجموع بنود الحركة المعروض = مجموع البنود
 *      اللي في القاعدة لنفس الحركة.
 *   ⑤ **عرض بحت**: ولا استعلام كتابة، ورصيد الخزنة قبل = بعد.
 *   ⑥ **الغلاف زي ما هو**: الهيرو والشريط والرحلة والمرشد لسه بيترسموا مرة
 *      واحدة في `show.php` — الجسم الجديد ما نسخش ولا واحد منهم.
 *
 * بيرندر الشاشة **فعليًّا** ببيانات حقيقية + fixture مزروع داخل transaction
 * بيترجع بالكامل.
 * التشغيل:  php bin/verify_permit_page.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\PermitController;
use App\Core\Database;
use App\Models\Closing;
use App\Models\Permit;

$pass = 0; $failures = [];
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $failures; $failures[] = $m; echo "  ✗ $m\n"; }
function chk(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function sec(string $m): void { echo "\n$m\n" . str_repeat('─', 64) . "\n"; }

/** التعليق بيوثّق الممنوع نفسه — لازم يتشال قبل أي فحص نصّي (مصيدة `code_only`). */
function php_code(string $s): string {
    $s = preg_replace('~/\*.*?\*/~s', '', $s) ?? $s;
    return preg_replace('~^\s*(//|\*|#).*$~m', '', $s) ?? $s;
}

echo "فحص إعادة بناء شاشة الإذن — وضع العرض (v103)\n" . str_repeat('=', 64) . "\n";

$root   = dirname(__DIR__);
$view   = (string) file_get_contents($root . '/app/Views/permits/_body_view.php');
$viewC  = php_code($view);
$show   = (string) file_get_contents($root . '/app/Views/permits/show.php');
$mvV    = (string) file_get_contents($root . '/app/Views/permits/_movement.php');
$badge  = (string) file_get_contents($root . '/app/Views/partials/rep_badge.php');
$model  = php_code((string) file_get_contents($root . '/app/Models/Permit.php'));
$css    = (string) file_get_contents($root . '/public/assets/css/app.css');

$balanceBefore = Closing::currentBalance();

// ═══════════════════════════════════════════════════════════════════════
sec('① شارة المندوب — مكوّن واحد لكل الشاشات');

chk(is_file($root . '/app/Views/partials/rep_badge.php'), 'المكوّن موجود في مكتبة المكوّنات');
chk(str_contains($badge, 'rep-badge') && str_contains($css, '.rep-badge{'),
    'وله CSS — الكلاس اللي بلا شكل بيقع على الافتراضي بالسكوت');
chk(preg_match('~if\s*\(\s*\$__rb_name\s*===\s*\'\'\s*\)\s*\{\s*return~', $badge) === 1,
    '⚠️ وبند المكتب (بلا مندوب) الشارة بتختفي عنده — الغياب معلومة مش فراغ');
foreach ([
    '_body_view.php'  => $viewC,
    '_movement.php'   => php_code($mvV),
    '_overview.php'   => php_code((string) file_get_contents($root . '/app/Views/permits/_overview.php')),
] as $file => $src) {
    chk(str_contains($src, "partial('rep_badge'"),
        "و`{$file}` بينادي المكوّن مش بيرسم شارة بإيده");
}
chk(substr_count($viewC, 'rep-badge') === 0 && substr_count(php_code($mvV), 'rep-badge') === 0,
    'ومفيش ماركب شارة مكتوب بالإيد في أي شاشة');

/* الاسم من الموديل — استعلام واحد بـLEFT JOIN، مش استعلام لكل بند في الشاشة. */
chk(str_contains($model, 'STAGE_SELECT') && str_contains($model, 'LEFT JOIN reps r ON r.id = s.paid_by_rep_id'),
    '⚠️ و`rep_name` جاي من **مصدر قراءة واحد** للبنود (`Permit::STAGE_SELECT`)');
/* ⚠️ الاسم بيتجاب مع البند نفسه — ممنوع أي شاشة تروح تسأل عن اسم المندوب
   (استعلام لكل سطر = N+1، ونسخة تانية من نفس السؤال). */
$viewsWithRepQuery = [];
foreach (glob($root . '/app/Views/permits/*.php') ?: [] as $f) {
    $src = php_code((string) file_get_contents($f));
    if (preg_match('~FROM\s+reps|Rep::(all|find)~i', $src)) { $viewsWithRepQuery[] = basename($f); }
}
chk($viewsWithRepQuery === [], 'ومفيش استعلام أسماء مناديب جوّه أي شاشة'
    . ($viewsWithRepQuery ? ' — في: ' . implode(', ', $viewsWithRepQuery) : ''));

// ═══════════════════════════════════════════════════════════════════════
sec('② البناء الجديد — صفوف مش جداول، وخريطة للصفحة');

chk(!str_contains($viewC, '<table'), '⚠️ صفر `<table>` في وضع العرض (كانت ٤ جداول لحد ١١ عمودًا)');
chk(str_contains($viewC, 'pf-tools') && str_contains($css, '.pf-tools{'),
    'وفيه سطر أدوات للشغل (عدّادات + طيّ الكل + إضافة بند)');
chk(str_contains($viewC, 'data-fold-all=".pf-mv"') && str_contains($viewC, 'data-fold-scope'),
    'وزرار «افتح/اطوي الكل» على الآلية العامة نفسها بتاعة نافذة النظرة العامة');
chk(str_contains($viewC, '<details class="pf-mv"'),
    'والطيّ `<details>` أصلية — شغّالة بالكيبورد وبلا جافاسكريبت');
chk(str_contains($viewC, '$mvOpenDefault = count($movements) <= 3'),
    'والطيّ الافتراضي بيتقرّر بعدد الحركات');
chk(preg_match_all('~\sstyle="~', $viewC) === 0 && !str_contains($viewC, '<style'),
    'وصفر `style=` inline وصفر `<style>` محلي');

/* الغلاف ما اتلمسش: الأجزاء المشتركة لسه بتترسم مرة واحدة فيه. */
foreach (['_bar.php', '_hero.php', '_journey.php', '_docs.php', '_rcp_guide.php'] as $part) {
    chk(str_contains($show, "include __DIR__ . '/$part'") && !str_contains($view, "include __DIR__ . '/$part'"),
        "و`{$part}` لسه بيترسم مرة واحدة في الغلاف مش في الجسم");
}

/* كل كلاس `pv3-*` له CSS والعكس — الاتجاهين مع بعض (مصيدة `.table-mobile-scroll`). */
preg_match_all('~class="([^"<]*(?:pv3|pf-)[^"<]*)"~', $view, $cm);
$classes = [];
foreach ($cm[1] ?? [] as $chunk) {
    foreach (preg_split('~\s+~', trim($chunk)) as $c) {
        $c = rtrim(trim((string) $c), "'\"");
        if ($c !== '' && (str_starts_with($c, 'pv3') || str_starts_with($c, 'pf-'))) { $classes[$c] = true; }
    }
}
$noCss = array_values(array_filter(array_keys($classes), static fn ($c) => !str_contains($css, '.' . $c)));
chk($noCss === [], 'وكل كلاس `pv3-*`/`pf-*` في الماركب له CSS' . ($noCss ? ' — ناقص: ' . implode(', ', $noCss) : ''));
/* ⚠️ الماركب اللي بيستعمل الكلاسات دي **موزّع على تلات ملفات** (الغلاف
   `show.php` · الريل `_hero.php` · الجسم) — الفحص على ملف واحد كان بيدّي
   «قاعدة ميتة» كذبًا لكل كلاس في الريل. */
$cssCode = (string) preg_replace('~/\*.*?\*/~s', '', $css);
$markup  = $view . $show . (string) file_get_contents($root . '/app/Views/permits/_movement.php')
         . (string) file_get_contents($root . '/app/Views/permits/_hero.php')
         . (string) file_get_contents($root . '/app/Views/permits/_journey.php');
preg_match_all('~\.((?:pv3|pf)[a-z0-9-]*)~', $cssCode, $csm);
$deadCss = array_values(array_unique(array_filter($csm[1] ?? [],
    static fn ($c) => !str_contains($markup, $c))));
chk($deadCss === [], 'ومفيش قاعدة CSS بلا ماركب' . ($deadCss ? ' — ميتة: ' . implode(', ', $deadCss) : ''));

/* الأيقونة الغلط بترسم دايرة فاضية بلا أي خطأ. */
preg_match_all("~icon\('([a-z0-9-]+)'~", $view, $im);
$badIcons = array_values(array_filter(array_unique($im[1] ?? []),
    static fn ($n) => !preg_match('~<(path|rect|circle|line|poly)~', icon($n, 10))));
chk($badIcons === [], 'وكل أيقونة موجودة في المكتبة' . ($badIcons ? ' — ناقصة: ' . implode(', ', $badIcons) : ''));

// ═══════════════════════════════════════════════════════════════════════
sec('③ ⚠️ رندر حقيقي + بند مندوب مزروع (transaction + rollback)');

$pid = (int) (Database::scalar(
    'SELECT permit_id FROM permit_movements ORDER BY id DESC LIMIT 1') ?? 0);
$rid = (int) (Database::scalar("SELECT id FROM reps WHERE status = 'active' LIMIT 1") ?? 0);

if ($pid <= 0) {
    echo "  ⓘ مفيش إذن بحركات — فحص الرندر اتخطّى\n";
} else {
    $mid = (int) Database::scalar('SELECT id FROM permit_movements WHERE permit_id = ? ORDER BY id LIMIT 1', [$pid]);
    $uid = (int) Database::scalar('SELECT MIN(id) FROM users');
    $render = static function (int $pid) use ($uid): string {
        $_SESSION = ['uid' => $uid, 'role' => 'owner', 'uname' => 'فحص',
                     'last_activity' => time(), 'uid_checked' => time()];
        $_GET = [];
        ob_start();
        try { (new PermitController())->view((string) $pid); return (string) ob_get_clean(); }
        catch (\Throwable $e) { ob_end_clean(); return 'EXCEPTION: ' . $e->getMessage(); }
    };

    $pdo = Database::conn();
    $pdo->beginTransaction();
    try {
        Database::exec(
            "INSERT INTO permit_stages (permit_id, movement_id, stage_key, amount, qty, entry_date,
                                        note, status, paid_by_rep_id)
             VALUES (?, ?, 'receipts', 321, 1, ?, 'بند فحص الشاشة', 'done', ?)",
            [$pid, $mid, work_date(), $rid > 0 ? $rid : null]);
        Database::exec(
            "INSERT INTO permit_stages (permit_id, movement_id, stage_key, amount, qty, entry_date,
                                        note, status)
             VALUES (?, ?, 'receipts', 123, 1, ?, 'بند فحص المكتب', 'done')",
            [$pid, $mid, work_date()]);

        $html = $render($pid);
        chk(!str_starts_with($html, 'EXCEPTION:'), 'الشاشة بترندر بلا استثناء' . (str_starts_with($html, 'EXCEPTION:') ? " — {$html}" : ''));
        chk(!preg_match('~Fatal error|Warning:|Undefined variable|Undefined array key|partial مفقود~', $html),
            'وبلا أي خطأ PHP ولا مكوّن مفقود');
        chk(str_contains($html, 'class="pv3"'), 'وبتطبع الجسم الجديد');
        chk(str_contains($html, 'بند فحص الشاشة') && str_contains($html, 'بند فحص المكتب'),
            'والبندان المزروعان ظاهران');

        /* ⚠️ **جوهر طلب المالك**: البند اللي دخّله مندوب معاه شارة، واللي من
           المكتب مالوش — والفحص بيقيس السطرين مع بعض لا وجود الكلمة في الصفحة. */
        if ($rid > 0) {
            $repName = (string) Database::scalar('SELECT name FROM reps WHERE id = ?', [$rid]);
            $rowRep = preg_match('~بند فحص الشاشة(?:(?!</div>\s*</div>).){0,900}?rep-badge~su', $html) === 1;
            $rowOffice = preg_match('~بند فحص المكتب(?:(?!pv3-it ).){0,900}?rep-badge~su', $html) === 1;
            chk($rowRep, '⚠️ **بند المندوب معاه شارة باسمه في سطره** — طلب المالك المباشر');
            chk(!$rowOffice, 'وبند المكتب **مالوش شارة** — الغياب معلومة');
            chk(str_contains($html, $repName) || $repName === '',
                'واسم المندوب مكتوب فعلاً (مش «مندوب» عامة)');
        } else {
            echo "  ⓘ مفيش مندوب نشط — فحص الشارة اتخطّى\n";
        }

        /* ④ الرقم المعروض = مجموع القاعدة لنفس الحركة (مش تجميع تاني في الشاشة) */
        $dbSum = (float) Database::scalar(
            "SELECT COALESCE(SUM(amount),0) FROM permit_stages
              WHERE movement_id = ? AND stage_key = 'receipts' AND status <> 'none'", [$mid]);
        $shown = 0.0;
        foreach (Permit::movementStagesGrouped($mid)['receipts'] ?? [] as $r) {
            if (($r['status'] ?? '') !== 'none') { $shown += (float) $r['amount']; }
        }
        chk(abs($dbSum - $shown) < 0.01,
            'ومجموع بنود الحركة في الشاشة = مجموعها في القاعدة (' . number_format($dbSum, 2) . ')');
    } finally {
        $pdo->rollBack();
    }
    chk((int) Database::scalar("SELECT COUNT(*) FROM permit_stages WHERE note LIKE 'بند فحص%'") === 0,
        'والزرع اترجع بالكامل — الفحص ما بيسيبش أثر');
}

// ═══════════════════════════════════════════════════════════════════════
sec('④ العزل — الشاشة عرض بحت');

chk(!preg_match('~\b(INSERT|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b~i', $viewC),
    'مفيش استعلام كتابة في الجسم');
chk(str_contains($viewC, "url('permits/' . \$pid . '/manage')"),
    'وكل تعديل بيروح لوضع التعديل بمرساة');
$balanceAfter = Closing::currentBalance();
chk(abs($balanceBefore - $balanceAfter) < 0.001,
    'ورصيد خزنة المكتب قبل = بعد (' . number_format($balanceAfter, 2) . ') — الحد الأحمر');

// ═══════════════════════════════════════════════════════════════════════
echo "\n" . str_repeat('=', 64) . "\n";
echo 'نجح: ' . $pass . '   |   فشل: ' . count($failures) . "\n";
if ($failures) {
    echo "\n✗ الفحص فشل — الكود ده ما يترفعش.\n";
    foreach ($failures as $f) { echo "   · $f\n"; }
    exit(1);
}
echo "\n✓ كل الفحوصات عدّت.\n";
