<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص نافذة «نظرة عامة» على يوم الأذونات (v99).
 *
 * طلب المالك بالحرف: «عايز في صفحات الأذونات والإصدارات حاجة اسمها نظرة عامة
 * تضغط تفتح نافذة … تظهر الإيصالات اللي أدخلها المناديب النهاردة في الأذون،
 * لأن صاحب العمل بيحب يشوف كل إذن جاله إيصالات إيه انهارده **حتى لو لم يتم
 * اعتمادها** وإحصائية فيها كام إيصال وهكذا.»
 *
 * المحروس هنا **سلوك مش نصوص**:
 *   ① البند المستنّي (غير المعتمَد) بيظهر في النافذة **فورًا** — جوهر الطلب.
 *   ② واعتماده ما بيضاعفوش — بيختفي من `rep_submissions` المستنّية ويظهر في
 *      `permit_stages`، فالعدّ يفضل واحد.
 *   ③ البند بيتحسب بـ`entry_date` (يوم صرفه) مش `created_at` (قاعدة v29).
 *   ④ علامة «مش محتاجه» (`status='none'`) مش بند اتصرف — بتتشال.
 *   ⑤ الإحصائية على **اليوم كله** والفلتر بيصفّي **العرض** بس.
 *   ⑥ الفلتر قائمة بيضا — أي قيمة من المستخدم بترجع `all`.
 *   ⑦ العزل: رصيد خزنة المكتب قبل = بعد (الشاشة عرض بحت).
 *
 * كل الزرع جوّه transaction وبيترجع — الفحص ما بيسيبش أثر.
 * التشغيل:  php bin/verify_permit_overview.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\PermitController;
use App\Core\Database;
use App\Models\Closing;
use App\Models\Metrics;

$pass = 0; $failures = [];
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $failures; $failures[] = $m; echo "  ✗ $m\n"; }
function chk(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function sec(string $m): void { echo "\n$m\n" . str_repeat('─', 64) . "\n"; }

/* ⚠️ التعليقات بتوثّق النصوص الممنوعة، فلازم تتشال قبل أي فحص نصّي وإلا
   إنذار كاذب (نفس مصيدة `code_only` الموثَّقة). */
function php_code(string $s): string {
    $s = preg_replace('~/\*.*?\*/~s', '', $s) ?? $s;
    return preg_replace('~^\s*(//|\*|#).*$~m', '', $s) ?? $s;
}

echo "فحص نافذة «نظرة عامة» على يوم الأذونات (v99)\n" . str_repeat('=', 64) . "\n";

$root   = dirname(__DIR__);
$view   = (string) file_get_contents($root . '/app/Views/permits/_overview.php');
$idx    = (string) file_get_contents($root . '/app/Views/permits/index.php');
$bar    = (string) file_get_contents($root . '/app/Views/permits/_bar.php');
$ctrl   = (string) file_get_contents($root . '/app/Controllers/PermitController.php');
$routes = (string) file_get_contents($root . '/public/index.php');
$css    = (string) file_get_contents($root . '/public/assets/css/app.css');
$metricsSrc = php_code((string) file_get_contents($root . '/app/Models/Metrics.php'));

$balanceBefore = Closing::currentBalance();

// ═══════════════════════════════════════════════════════════════════════
sec('① الباب: زرار في الشاشتين ومسار مسجَّل قبل الجشع');

chk(str_contains($idx, "data-drill=\"<?= url('permits/overview') ?>\""),
    'زرار «نظرة عامة» في شاشة الأذونات بيفتح النافذة العامة');
chk(str_contains($bar, "data-drill=\"<?= url('permits/overview') ?>\""),
    'وفي شريط صفحة الإذن كمان — نفس الزرار ونفس النافذة');
chk(str_contains($idx, 'نظرة عامة') && str_contains($bar, 'نظرة عامة'),
    'والاسم اللي المالك طلبه بالحرف مكتوب على الزرار');

/* ⚠️ **ترتيب المسارات حسّاس** — الراوتر first-match-wins، فـ`/permits/overview`
   لازم تسبق `/permits/{id}` وإلا «overview» تتقري كأنها معرّف إذن. */
$posOverview = strpos($routes, "\$r->get('/permits/overview'");
$posGreedy   = strpos($routes, "\$r->get('/permits/{id}'");
chk($posOverview !== false, 'المسار `/permits/overview` مسجَّل');
chk($posOverview !== false && $posGreedy !== false && $posOverview < $posGreedy,
    '⚠️ وقبل المسار الجشع `/permits/{id}` — وإلا «overview» تتقري معرّف إذن');
chk(str_contains($ctrl, 'public function overview(): void')
    && preg_match('~function overview.*?\$this->requireAuth\(\);~s', $ctrl) === 1,
    'والأكشن بيبدأ بـ`requireAuth()` — مفيش middleware في النظام');

// ═══════════════════════════════════════════════════════════════════════
sec('② اليوم عدسة واحدة: يوم العمل، والمستقبل مرفوض');

chk(str_contains($ctrl, 'Auth::workDate()'),
    '⚠️ اليوم من `Auth::workDate()` مش `date()` — المالك ممكن يكون على يوم فات');
chk(str_contains($ctrl, "\$day > \$work"),
    'و`?d=` محدود بيوم العمل — ممنوع يشوف بنود يوم لسه ما جاش');
chk(str_contains($metricsSrc, "s.entry_date = ?") && str_contains($metricsSrc, "b.entry_date = ?"),
    'والبند بيتفلتر بـ`entry_date` — يوم صرفه لا يوم تسجيله (قاعدة v29)');
chk(str_contains($metricsSrc, "b.status = 'pending'"),
    '⚠️ والبنود المستنّية داخلة في الاتحاد (طلب المالك: حتى لو مش معتمدة)');
chk(str_contains($metricsSrc, "s.status <> 'none'"),
    "وعلامة «مش محتاجه» (`status='none'`) مستثناة — دي مش بند اتصرف");

// ═══════════════════════════════════════════════════════════════════════
sec('③ المصدر الوحيد — الشروط مش مكتوبة بالإيد');

chk(str_contains($metricsSrc, "Permit::cashOutSql('s')") && str_contains($metricsSrc, "Permit::cashOutSql('b')"),
    '⚠️ «طلّع كاش؟» من `Permit::cashOutSql()` في المصدرين — ممنوع يتكتب بالإيد');
chk(!preg_match('~permitDayItems.*?is_visa\s*=\s*0~s', $metricsSrc),
    'ومفيش نسخة يدوية من شرط الفيزا جوّه الدالة');
chk(str_contains($metricsSrc, 'Permit::DIR_REVENUE'),
    'وتصنيف «محصَّل» من ثابت `Permit::DIR_REVENUE` مش من نص');
chk(str_contains($metricsSrc, 'ROUND(b.actual_unit * b.qty'),
    '⚠️ وسعر العميل في بند المندوب × الكمية — زي `RepInbox::approve()` بالظبط');

// ═══════════════════════════════════════════════════════════════════════
sec('④ الفلتر قائمة بيضا — القيمة الجاية من الرابط ما بتتصدّقش');

chk(str_contains($metricsSrc, 'DAY_FILTERS'),
    'أسماء الفلاتر في ثابت واحد (`Metrics::DAY_FILTERS`)');
$day0 = date('Y-m-d');
$evil = Metrics::permitDayOverview($day0, "'; DROP TABLE permits; --");
chk(($evil['filter'] ?? '') === 'all', 'وأي قيمة برّه القائمة بترجع `all` بلا استعلام');
chk((int) Database::scalar("SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'permits'") === 1,
    'وجدول الأذونات لسه موجود بعد المحاولة');

// ═══════════════════════════════════════════════════════════════════════
sec('⑤ ⚠️ السلوك الحقيقي — زرع بيانات ورجوعها بالكامل');

$repsReady = (int) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = 'rep_submissions'");
$pid = (int) (Database::scalar('SELECT MAX(id) FROM permits') ?? 0);
$rid = (int) (Database::scalar("SELECT id FROM reps WHERE status = 'active' LIMIT 1") ?? 0);

if ($pid <= 0) {
    echo "  ⓘ مفيش أذونات في القاعدة — فحص السلوك اتخطّى\n";
} else {
    $pdo = Database::conn();
    $pdo->beginTransaction();
    try {
        $day = '2026-01-07';   // يوم بعيد عشان ما يختلطش بأي بيانات حقيقية

        $base = Metrics::permitDayOverview($day);
        chk(($base['stats']['items'] ?? 0) === 0, "اليوم المزروع فاضي قبل الزرع ({$day})");

        // ① بند مكتب معتمَد: إيصال بمبلغ ٥٠٠ ناقص رقمه وفعليه
        Database::exec(
            "INSERT INTO permit_stages (permit_id, stage_key, amount, qty, entry_date, note, status)
             VALUES (?, 'receipts', 500, 1, ?, 'إيصال فحص مكتب', 'done')", [$pid, $day]);
        // ② علامة «مش محتاجه» — **ما ينفعش تتعدّ**
        Database::exec(
            "INSERT INTO permit_stages (permit_id, stage_key, amount, qty, entry_date, note, status)
             VALUES (?, 'receipts', 0, 1, ?, 'علامة فحص', 'none')", [$pid, $day]);
        // ③ بند بيوم تاني — **ما ينفعش يتسرّب** لليوم ده
        Database::exec(
            "INSERT INTO permit_stages (permit_id, stage_key, amount, qty, entry_date, note, status)
             VALUES (?, 'receipts', 900, 1, ?, 'إيصال يوم تاني', 'done')",
            [$pid, date('Y-m-d', strtotime($day . ' -1 day'))]);

        $o = Metrics::permitDayOverview($day);
        chk(($o['stats']['items'] ?? 0) === 1, 'البند المعتمَد ظهر — وعلامة «مش محتاجه» ما اتعدّتش');
        chk(abs(($o['stats']['out_val'] ?? 0) - 500) < 0.01, 'والمبلغ ٥٠٠ بالظبط — مفيش تسريب من يوم تاني');
        chk(($o['stats']['missing_cnt'] ?? 0) === 1, 'واتوسم «ناقص بياناته» (بلا فعلي ولا رقم إيصال)');
        chk(($o['stats']['office_cnt'] ?? 0) === 1 && ($o['stats']['rep_cnt'] ?? 0) === 0,
            'واتحسب على المكتب مش على مندوب');

        if ($repsReady > 0 && $rid > 0) {
            // ④ **بند مندوب لسه مستنّي اعتماد** — جوهر طلب المالك
            Database::exec(
                "INSERT INTO rep_submissions (rep_id, permit_id, kind, note, qty, unit_amount, amount,
                                              entry_date, status, created_at)
                 VALUES (?, ?, 'receipt', 'إيصال فحص مندوب', 1, 300, 300, ?, 'pending', NOW())",
                [$rid, $pid, $day]);

            $o2 = Metrics::permitDayOverview($day);
            chk(($o2['stats']['pending_cnt'] ?? 0) === 1
                && abs(($o2['stats']['pending_val'] ?? 0) - 300) < 0.01,
                '⚠️ **بند مندوب غير معتمَد بيظهر في النافذة فورًا** — جوهر طلب المالك');
            chk(($o2['stats']['rep_cnt'] ?? 0) === 1,
                'واتنسب للمندوب اللي بعته (شريط المناديب)');
            chk(count($o2['reps']) === 1 && ($o2['reps'][0]['pending_cnt'] ?? 0) === 1,
                'وشريحة المندوب بتعدّ المستنّي لوحده');
            chk(($o2['stats']['items'] ?? 0) === 2,
                'وإجمالي بنود اليوم بقى ٢ (معتمَد + مستنّي) بلا عدّ مزدوج');

            /* ⑤ **الاعتماد ما بيضاعفش**: البند بيتحوّل لصف `permit_stages`
               وبيختفي من المستنّية، فالإجمالي يفضل ٢ لا ٣. */
            Database::exec("UPDATE rep_submissions SET status = 'approved' WHERE permit_id = ? AND entry_date = ?", [$pid, $day]);
            Database::exec(
                "INSERT INTO permit_stages (permit_id, stage_key, amount, qty, entry_date, note, status, paid_by_rep_id)
                 VALUES (?, 'receipts', 300, 1, ?, 'إيصال فحص مندوب', 'done', ?)", [$pid, $day, $rid]);

            $o3 = Metrics::permitDayOverview($day);
            chk(($o3['stats']['items'] ?? 0) === 2,
                '⚠️ وبعد الاعتماد الإجمالي لسه ٢ — البند ما اتعدّش مرتين');
            chk(($o3['stats']['pending_cnt'] ?? 0) === 0 && ($o3['stats']['rep_cnt'] ?? 0) === 1,
                'واتنقل من «مستنّي» لـ«من المندوب»');

            // ⑥ الفلتر بيصفّي العرض والإحصائية بتفضل على اليوم كله
            $f = Metrics::permitDayOverview($day, 'reps');
            chk(($f['stats']['items'] ?? 0) === 2,
                '⚠️ الأرقام على اليوم كله حتى مع الفلتر — الرقم ما يتغيّرش تحت إيد المالك');
            $shownItems = array_sum(array_map(static fn ($p) => $p['cnt'], $f['permits']));
            chk($shownItems === 1, 'والمعروض بند المندوب بس (الفلتر بيصفّي العرض)');

            $fm = Metrics::permitDayOverview($day, 'missing');
            $shownMissing = array_sum(array_map(static fn ($p) => $p['cnt'], $fm['permits']));
            chk($shownMissing === 2, 'وفلتر «ناقص بياناته» بيطلّع البندين (مفيش فعلي ولا رقم)');

            // ⑦ فلتر المندوب
            $fr = Metrics::permitDayOverview($day, 'all', $rid);
            chk(array_sum(array_map(static fn ($p) => $p['cnt'], $fr['permits'])) === 1,
                'وشريحة المندوب بتعرض بنوده هو بس');
            chk(array_sum(array_map(static fn ($p) => $p['cnt'],
                Metrics::permitDayOverview($day, 'all', 999999)['permits'])) === 0,
                'ومندوب مالوش بنود بيدّي قايمة فاضية بلا خطأ');
        } else {
            echo "  ⓘ مفيش مندوب نشط أو جدول بنود المناديب — فحص المستنّي اتخطّى\n";
        }

        // ⑧ رندر فعلي للنافذة على البيانات المزروعة
        $uid = (int) Database::scalar('SELECT MIN(id) FROM users');
        $_SESSION = ['uid' => $uid, 'role' => 'owner', 'uname' => 'فحص',
                     'last_activity' => time(), 'uid_checked' => time()];
        $_GET = ['d' => $day];
        ob_start();
        try {
            (new PermitController())->overview();
            $html = (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean(); $html = ''; bad('النافذة رمت استثناء: ' . $e->getMessage());
        }
        chk($html !== '' && !preg_match('~Fatal error|Warning:|Undefined variable|Undefined array key|partial مفقود~', $html),
            'النافذة بترندر بلا أي خطأ PHP ولا مكوّن مفقود');
        chk(str_contains($html, 'class="povw"'), 'وبتطبع جسم النافذة');
        chk(str_contains($html, 'إيصال فحص مكتب'), 'والبند المزروع ظاهر فيها بالاسم');
        chk(!str_contains($html, 'علامة فحص'), 'وعلامة «مش محتاجه» مش ظاهرة');
        chk(!str_contains($html, 'إيصال يوم تاني'), 'ولا بند اليوم التاني — احترام التاريخ ١٠٠٪');
        chk(!str_contains($html, '<!DOCTYPE'), 'وبترندر **بلا layout** (جسم نافذة مش صفحة)');

        /* ⚠️ الأيقونة الغلط ما بترميش خطأ — بترسم دايرة فاضية. الفحص بيقارن كل
           اسم أيقونة في الشاشة بمكتبة `icon()` الفعلية. */
        preg_match_all("~icon\('([a-z0-9-]+)'~", $view, $im);
        $badIcons = [];
        foreach (array_unique($im[1] ?? []) as $name) {
            if (!str_contains(icon($name, 10), '<path') && !str_contains(icon($name, 10), '<rect')
                && !str_contains(icon($name, 10), '<circle') && !str_contains(icon($name, 10), '<line')
                && !str_contains(icon($name, 10), '<poly')) { $badIcons[] = $name; }
        }
        chk($badIcons === [], 'وكل أيقونة في الشاشة موجودة في المكتبة'
            . ($badIcons ? ' — ناقصة: ' . implode(', ', $badIcons) : ''));
    } finally {
        $pdo->rollBack();
    }

    chk((int) Database::scalar("SELECT COUNT(*) FROM permit_stages WHERE note LIKE 'إيصال فحص%'") === 0,
        'والزرع اترجع بالكامل — الفحص ما بيسيبش أثر');
}

// ═══════════════════════════════════════════════════════════════════════
sec('⑥ لغة التصميم — صفر ماركب يتيم');

/* ⚠️ `php_code()` **إلزامية**: تعليق الملف بيوثّق الممنوع نفسه (`<style>`)،
   فالفحص على النص الخام بيفشل بإنذار كاذب — مصيدة `code_only` الموثَّقة،
   وقعت فعليًّا وأنا بكتب الفاحص ده. */
$viewCode = php_code($view);
chk(preg_match_all('~\sstyle="~', $viewCode) === 0, 'صفر `style=` inline في النافذة');
chk(!str_contains($viewCode, '<style'), 'وصفر `<style>` محلي — الكلاسات كلها في `app.css`');

/* ⚠️ الكلاس اللي بيتطبع بلا CSS بيقع على الشكل الافتراضي **بالسكوت**
   (مصيدة `.table-mobile-scroll` اللي عاشت شهور). */
preg_match_all('~class="([^"<]*povw[^"<]*)"~', $view, $cm);
$classes = [];
foreach ($cm[1] ?? [] as $chunk) {
    foreach (preg_split('~\s+~', trim($chunk)) as $c) {
        $c = trim((string) $c);
        /* ⚠️ الكلاس المبني في PHP بيسيب علامة تنصيص في الآخر
           (`class="povw-f' . $tone`) — بنقصّها وإلا الفحص يدوّر على `.povw-f'`. */
        $c = rtrim($c, "'\"");
        if ($c !== '' && str_starts_with($c, 'povw')) { $classes[$c] = true; }
    }
}
/* الكلاسات المبنية في PHP (`'povw-f' . $tone`) مش بتتمسك بالتعبير فوق — بنضيفها. */
foreach (['povw-f', 'povw-rep', 'povw-day-btn'] as $c) { $classes[$c] = true; }
$noCss = array_values(array_filter(array_keys($classes),
    static fn ($c) => !str_contains($css, '.' . $c)));
chk($noCss === [], 'وكل كلاس `povw-*` في الماركب له CSS'
    . ($noCss ? ' — بلا تعريف: ' . implode(', ', $noCss) : ''));

/* والاتجاه التاني: قاعدة CSS بلا ماركب = كود ميت. */
preg_match_all('~\.(povw[a-z-]*)~', $css, $csm);
$deadCss = array_values(array_unique(array_filter($csm[1] ?? [],
    static fn ($c) => !str_contains($view, $c))));
chk($deadCss === [], 'ومفيش قاعدة CSS بلا ماركب'
    . ($deadCss ? ' — ميتة: ' . implode(', ', $deadCss) : ''));

/* ⚠️ `var(--توكن-مش-موجود)` بيبطّل الإعلان كله بالسكوت — اتصاد ١٩ مرة. */
$block = substr($css, (int) strpos($css, '.povw{'));
preg_match_all('~var\((--[a-z0-9-]+)~', $block, $tm);
$missingTokens = [];
foreach (array_unique($tm[1] ?? []) as $tok) {
    if (!preg_match('~' . preg_quote($tok, '~') . '\s*:~', $css)) { $missingTokens[] = $tok; }
}
chk($missingTokens === [], 'وكل توكن مستعمل في النافذة معرَّف'
    . ($missingTokens ? ' — ناقص: ' . implode(', ', $missingTokens) : ''));

/* النافذة **مش شاشة**: لازم تفضل فيها طريق للشاشة الكاملة. */
chk(str_contains($view, "url('permits')") && str_contains($view, "url('rep-custody/inbox')"),
    'والنافذة فيها طريق للشاشة الكاملة ولصندوق المراجعة');

// ═══════════════════════════════════════════════════════════════════════
sec('⑦ العزل — الشاشة عرض بحت');

$touched = preg_match('~\b(INSERT|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b~i', php_code($view));
chk(!$touched, 'مفيش استعلام كتابة في ملف العرض');
/* ⚠️ **محصور في جسم الأكشن**: `.*?` بـ`s` من غير حدّ بيعدّي على باقي
   الكنترولر كله (٢٠٠٠ سطر فيهم كتابة مشروعة) فيفشل بإنذار كاذب. */
$ctrlCode = php_code($ctrl);
$ovStart  = (int) strpos($ctrlCode, 'public function overview(): void');
$ovEnd    = (int) strpos($ctrlCode, 'public function index(): void', $ovStart);
$ovBody   = substr($ctrlCode, $ovStart, max(0, $ovEnd - $ovStart));
chk($ovBody !== '' && !preg_match('~\b(INSERT|UPDATE|DELETE)\b~i', $ovBody),
    'ولا في الأكشن');
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
