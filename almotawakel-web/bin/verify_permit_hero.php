<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص هيرو الإذن — أرقام قابلة للضغط + ترتيب مراحل ثابت (2026-08-10).
 *
 * الحاجتان اللي بيحرسهما ومتصادفتان فعليًّا في مراجعة المالك:
 *
 * ① **ممنوع رقم أصمّ** — الهيرو كان فيه ٨ `<div class="htile">` مالهاش أي
 *   مصدر تفاصيل، وهي مخالفة صريحة لقاعدة المالك الدائمة (قانون التصميم ⑤)
 *   المطبَّقة في باقي النظام عبر مكوّن `stat`. الفحص بيرندر الشاشة فعليًّا
 *   ويتأكد إن **كل** رقم في الهيرو له نافذة، وإن **مجموع كل نافذة = رقم
 *   بلاطتها** (النافذة اللي بتقول رقمًا مخالفًا لكارتها أسوأ من غيابها).
 *
 * ② **ترتيب المراحل ثابت** — كان بيتعاد ترتيبه «المكتمل أولًا»، فالخطوات
 *   المرقّمة كانت بتتعرض برّه ترتيبها الرقمي (٢ ← ٣ ← ١)، وكان مكان كل قسم
 *   بيتغيّر مع أول بند بيتسجّل في شاشة إدخال يومي.
 *
 * ⚠️ **الفحص على السلوك لا على النص**: بيقارن مجاميع محسوبة من القاعدة
 *    بأرقام الشاشة، وبيعدّ العناصر في HTML مرندَر — مش بيدوّر على تسمية
 *    ولا على مبلغ مزروع.
 *
 * ⚠️ قراءة فقط — ولا بايت بيتكتب. رصيد الخزنة قبل = بعد.
 *
 * التشغيل:  php bin/verify_permit_hero.php
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
function sec(string $m): void { echo "\n$m\n" . str_repeat('─', 62) . "\n"; }

/** الكود بلا تعليقات — التعليق اللي بيوثّق القاعدة بيذكر النص الممنوع. */
function code_only(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

/** نفس القاعدة للـCSS — التعليق اللي بيشرح ليه `.htile` اتشالت بيذكر اسمها. */
function css_only(string $src): string
{
    return (string) preg_replace('~/\*.*?\*/~s', '', $src);
}

echo "فحص هيرو الإذن — أرقام مضغوطة وترتيب ثابت\n" . str_repeat('=', 62) . "\n";

$root  = dirname(__DIR__);
$hero  = (string) file_get_contents($root . '/app/Views/permits/_hero.php');
$bodyV = (string) file_get_contents($root . '/app/Views/permits/_body_view.php');
$mvSrc = (string) file_get_contents($root . '/app/Views/permits/_movement.php');
$model = (string) file_get_contents($root . '/app/Models/Permit.php');
$css   = (string) file_get_contents($root . '/public/assets/css/app.css');

$treasuryBefore = Closing::currentBalance();

// ═══════════════════════════════════════════════════════════════════════
sec('① البلاطات الصمّاء اتشالت من الجذر');
// ═══════════════════════════════════════════════════════════════════════

$heroCode = code_only($hero);
chk(!str_contains($heroCode, 'htile'),
    '⚠️ مفيش أي `htile` في الهيرو — البلاطة الصمّاء اتشالت مش اتخبّت');
chk(!str_contains(css_only($css), '.htile'),
    'وقواعد `.htile` اتشالت من `app.css` (مفيش CSS ميت وراها)');
/* ⚠️ **إعادة بناء v104:** الهيرو الأفقي + شبكة الأربع كروت اتشالوا. الأرقام
   بقت **دفتر فلوس** (`.pf-led`) في ريل ملاصق — أربع سطور بدل أربع كروت
   بعرض الشاشة، وكل سطر لسه زرار بيفتح نفس نافذة تفاصيله.
   القاعدة زي ما هي: **أربعة أرقام، كلها مضغوطة، ومجموع كل نافذة = رقمها.** */
chk(!str_contains(css_only($css), '.hero-tiles'),
    'و`.hero-tiles` اتشالت من `app.css` مع الهيرو القديم (مفيش CSS ميت وراها)');
chk(preg_match_all("~partial\('stat',~", $heroCode) === 4,
    'وأربع بطاقات `stat` بالظبط (مش ٨ ولا ٣)');
chk(preg_match_all('~#ph(Custody|Expense|Charged|Margin)~', $heroCode) >= 4,
    '⚠️ وكل كارت بيفتح نافذة تفاصيله — «ممنوع رقم أصمّ» زي ما هي');

/* ⚠️ الرقم الجديد **لازم** يعيش في `totals()` مش في الـview: هو حرفيًّا طرح
   بلاطتين معروضتين جنبه، ولو اتحسب في الشاشة ممكن يفترق عنهم بالسكوت. */
chk(str_contains(code_only($model), "'margin'"),
    "⚠️ «الفرق» بيتحسب في `Permit::totals()` — مصدر واحد مع طرفيه");
chk(!preg_match('~\$totals\[.charged.\]\s*-\s*\$totals\[.expense.\]~', $heroCode),
    '⚠️ والشاشة ما بتحسبهوش بإيدها (ممنوع تكتب المعادلة تاني)');

// ═══════════════════════════════════════════════════════════════════════
sec('①ب التوكيل الملاحي بارز في الهيرو ومش مكرَّر تحت');
// ═══════════════════════════════════════════════════════════════════════

chk(str_contains($heroCode, 'التوكيل الملاحي'),
    'التوكيل الملاحي في الهيرو **بالاسم الكامل** (مش «التوكيل» اللي بيتلخبط بـ«الوكيل»)');
chk(preg_match('~\$facts\s*=.*التوكيل الملاحي.*true~su', $heroCode) === 1,
    'وموسوم **مفتاحيًّا** في قايمة الحقائق (بيتعرض حتى لو فاضي)');
/* ⚠️ الكلاس اللي بيتطبع بلا CSS بيقع على الشكل الافتراضي **بالسكوت** — نفس
   مصيدة `.table-mobile-scroll` اللي عاشت شهور معطَّلة. */
$cssCode = css_only($css);
foreach (['.pf-fact.key', '.pf-fact.blank'] as $cls) {
    chk(str_contains($cssCode, $cls), "وكلاس `$cls` معرَّف في `app.css` (مش ماركب بلا شكل)");
}
/* ⚠️ الحقل اللي بيتعرض في مكانين بيفترق مع أول تعديل — اتشال من «تفاصيل
   إضافية» لما اتحطّ في الهيرو. «شركة الشحن» حاجة تانية وبتفضل مكانها. */
$bodyVCode = code_only($bodyV);
chk(!preg_match("~'[^']*التوكيل[^']*',\s*\(\\\$p\['agency'\]~u", $bodyVCode),
    '⚠️ ومش مكرَّر في «تفاصيل إضافية» تحت (اتشال من هناك)');
chk(!str_contains($heroCode, 'shipping_co') && str_contains($bodyVCode, 'shipping_co'),
    'و«شركة الشحن» مكانها «تفاصيل الإذن» تحت — حقل تاني مختلف، ومكان واحد بس');

// ═══════════════════════════════════════════════════════════════════════
sec('② ترتيب المراحل ثابت — مش بيتعاد ترتيبه حسب الاكتمال');
// ═══════════════════════════════════════════════════════════════════════

/* الفحص على **السلوك**: مفيش أي فرز بيقسّم المراحل لمكتمل/ناقص قبل الطباعة.
   الأسماء القديمة كانت `$doneS/$pendS` و`$dH/$pH` و`$doneH/$pendH`. */
foreach ([
    '_body_view.php'  => $bodyV,
    '_movement.php'   => $mvSrc,
] as $file => $src) {
    $c = code_only($src);
    chk(!preg_match('~if\s*\(\s*\$\w+\[.has.\]\s*\)\s*\$\w+\s*\.=~', $c)
        && !preg_match('~\$done\w*\s*\.\s*\$pend\w*~', $c),
        "`$file` ما بيقسّمش المراحل لمكتمل/ناقص قبل الطباعة");
}

// ═══════════════════════════════════════════════════════════════════════
sec('②ب ⚠️⚠️ الاكتمال دالة واحدة — البطاقة والشاشة لازم يقولوا نفس الرقم');
// ═══════════════════════════════════════════════════════════════════════

/* ⚠️ **الحد الأحمر لهذا القسم.** الرقمان كانا متحسوبين بقاعدتين مختلفتين
   (البطاقة: كل مرحلة مرة واحدة للإذن · الشاشة: مراحل كل حركة على حدة)،
   فالإذن بأكتر من حركة كان بيقول نسبتين. الفحص بيمشي على **كل** الأذونات
   ويقارن مسار البطاقة (`completionMap` — الاستعلام المجمّع) بمسار الشاشة
   (`completionOf` من `$movements` زي `show.php` بالحرف).
   ⚠️ **لازم يشمل أذونات بأكتر من حركة** وإلا بينجح كذبًا — بحركة واحدة
   القاعدتان القديمتان كانتا بتديا نفس الرقم. */
$allIds = array_map(fn($x) => (int) $x['id'], Database::all('SELECT id FROM permits ORDER BY id'));
if (!$allIds) {
    ok('مفيش أذونات — تخطّي مقارنة الاكتمال');
} else {
    $map = Permit::completionMap($allIds);
    $mismatch = []; $multi = 0;
    foreach ($allIds as $pid2) {
        $mvs = Permit::movements($pid2);
        foreach ($mvs as &$mv) {
            $mv['bills']  = Permit::movementBills((int) $mv['id']);
            $mv['stages'] = Permit::movementStagesGrouped((int) $mv['id']);
        }
        unset($mv);
        if (count($mvs) >= 2) { $multi++; }
        $ps = Permit::permitStagesGrouped($pid2);
        $ph = []; foreach (Permit::scopedStages('permit') as $k => $_) { $ph[$k] = !empty($ps[$k] ?? []); }
        $mh = [];
        foreach ($mvs as $mv) {
            $row = [];
            foreach (Permit::scopedStages('movement') as $k => $_) {
                $row[$k] = $k === 'bills' ? !empty($mv['bills']) : !empty($mv['stages'][$k] ?? []);
            }
            $mh[] = $row;
        }
        if (Permit::completionOf($ph, $mh) !== ($map[$pid2] ?? null)) { $mismatch[] = $pid2; }
    }
    chk($mismatch === [],
        '⚠️⚠️ نسبة الاكتمال واحدة في البطاقة والشاشة على ' . count($allIds) . ' إذن'
        . ($mismatch ? ' — مختلفة في: ' . implode(', ', array_slice($mismatch, 0, 8)) : ''));
    chk($multi > 0,
        "والعيّنة فيها $multi إذن بأكتر من حركة (الحالة اللي كانت بتفرق فعلاً)");
}

/* والقاعدة نفسها مش مكتوبة تاني في أي شاشة */
chk(!preg_match('~\$tot\s*\+\+~', code_only((string) file_get_contents($root . '/app/Views/permits/show.php')))
    && !preg_match('~\$tot\s*\+\+~', code_only($ctlSrc = (string) file_get_contents($root . '/app/Controllers/PermitController.php'))),
    '⚠️ ومفيش عدّ مكتوب بالإيد في `show.php` ولا في الكنترولر');

// ═══════════════════════════════════════════════════════════════════════
sec('③ الرندر الفعلي — كل رقم في الهيرو له نافذة، ومجموعها = رقمه');
// ═══════════════════════════════════════════════════════════════════════

/* ⚠️ إذن **فيه بنود فعلاً**: الفحص على إذن فاضي بينجح كذبًا (كل المجاميع صفر
   فأي نافذة غلط بتساوي أي بلاطة غلط). */
$pid = (int) Database::scalar(
    'SELECT s.permit_id FROM permit_stages s
      WHERE s.actual_amount IS NOT NULL
      GROUP BY s.permit_id ORDER BY COUNT(*) DESC LIMIT 1'
) ?: (int) Database::scalar('SELECT id FROM permits ORDER BY id DESC LIMIT 1');

if ($pid <= 0) {
    ok('مفيش أذونات في القاعدة — تخطّي الرندر');
} else {
    /* ⚠️ `view()` بتنادي `requireAuth()` — وبلا جلسة بتعمل `redirect()` اللي
       جوّاها `exit`، فالفاحص كان بيموت في نصّه بلا رسالة فشل. */
    $uid = (int) Database::scalar('SELECT MIN(id) FROM users');
    $_SESSION = ['uid' => $uid, 'role' => 'owner', 'uname' => 'فحص',
                 'last_activity' => time(), 'uid_checked' => time()];

    $totals = Permit::totals($pid);
    $money  = Permit::moneyRows($pid);
    $bills  = Permit::bills($pid);

    $_GET = [];
    ob_start();
    try {
        (new PermitController())->view((string) $pid);
        $html = (string) ob_get_clean();
    } catch (\Throwable $e) {
        ob_end_clean(); bad('الشاشة رمت استثناء: ' . $e->getMessage()); $html = '';
    }

    if ($html !== '') {
        chk(!preg_match('/Fatal error|Warning:|Undefined variable|Undefined array key/', $html),
            "الشاشة بترندر بلا أخطاء PHP (إذن #$pid · " . count($money) . ' بند · ' . count($bills) . ' بوليصة)');

        /* ⚠️ **المكوّن `stat` بيطبع تعليق HTML بدل الكارت** لو اتبعت بلا مصدر
           تفاصيل — فوجود التعليق ده معناه رقم أصمّ عدّى. */
        chk(!str_contains($html, 'ممنوع رقم أصمّ'),
            '⚠️ ولا بطاقة رقم بلا مصدر تفاصيل في الشاشة كلها');

        // كل كارت في صف الأرقام لازم يكون `.d-kpi` بـ`data-drill`
        if (preg_match('~<div class="pf-tiles">(.*?)\n</div>~s', $html, $m)) {
            $grid   = $m[1];
            $cards  = preg_match_all('~class="d-kpi ~', $grid);
            $drills = preg_match_all('~data-drill="~', $grid);
            chk($cards === 4, "صف الأرقام فيه ٤ كروت (لقى $cards)");
            chk($cards > 0 && $cards === $drills,
                "⚠️ وكل رقم فيه بيفتح تفاصيله ($drills من $cards)");
        } else {
            bad('ما لقيتش صف الأرقام `.pf-tiles` في الناتج');
        }

        foreach (['phCustody', 'phExpense', 'phCharged', 'phMargin'] as $win) {
            chk(str_contains($html, 'id="' . $win . '"'), "جسم نافذة `$win` موجود في الصفحة");
        }

        /* التوكيل الملاحي **بيتعرض دايمًا** — بقيمته لو متسجّل، ومطفي بـ«غير
           محدّد» لو مش متسجّل (غيابه معلومة: قواعد التوكيل في مرشد الإيصالات
           ما بتشتغلش من غيره). الفحص بيتأكد إن الحالتين بتطلع صح. */
        $permitAgency = trim((string) (\App\Models\Permit::find($pid)['agency'] ?? ''));
        chk(preg_match('~<span class="pf-fact key~', $html) === 1,
            'وشريحة التوكيل الملاحي ظاهرة في حقائق الهيرو');
        chk($permitAgency !== ''
            ? (str_contains($html, e($permitAgency)) && !str_contains($html, 'pf-fact key blank'))
            : str_contains($html, 'pf-fact key blank'),
            $permitAgency !== ''
                ? "وبتعرض قيمته الفعلية ({$permitAgency})"
                : 'وبتعرض حالة «غير محدّد» المطفية (الإذن ده بلا توكيل)');

        /* ⚠️⚠️ **الحد الأحمر**: مجموع كل نافذة = رقم بلاطتها. النافذة اللي
           بتقول رقمًا مخالفًا لكارتها بتدّي «الكارت يقول رقم والكشف يقول
           رقم تاني» بلا أي خطأ ظاهر. */
        $sumCustody = array_sum(array_map(fn($b) => (float) $b['custody'], $bills));
        $sumExpense = array_sum(array_map(fn($r) => !empty($r['is_expense']) ? (float) $r['amount'] : 0.0, $money));
        $sumCharged = array_sum(array_map(
            fn($r) => ($r['actual_amount'] !== null && $r['actual_amount'] !== '') ? (float) $r['actual_amount'] : 0.0,
            $money
        ));
        chk(abs($sumCustody - $totals['custody']) < 0.01,
            'مجموع بنود نافذة العهدة = بلاطتها (' . money($totals['custody'], false) . ')');
        chk(abs($sumExpense - $totals['expense']) < 0.01,
            'ومجموع نافذة «اتصرف فعلاً» = بلاطتها (' . money($totals['expense'], false) . ')');
        chk(abs($sumCharged - $totals['charged']) < 0.01,
            'ومجموع نافذة «هيتحاسب» = بلاطتها (' . money($totals['charged'], false) . ')');
        chk(abs(($sumCharged - $sumExpense) - $totals['margin']) < 0.01,
            'و«الفرق» = طرح البلاطتين اللي جنبه بالحرف (' . money($totals['margin'], false) . ')');

        /* البلاطات التلاتة اللي اتشالت كانت مكرَّرة — الفحص بيتأكد إن
           معلومتها **لسه في الشاشة** مش ضاعت مع البلاطة. */
        /* ⚠️ **المكان اتغيّر والمعلومة فضلت** (v103): عنوان «الحركات (N)» اتشال
           مع إعادة بناء الجسم، والعدد بقى في **الشريط الملاصق** فوق (`pv3-nav`)
           اللي بيقفّز للقسم كمان. الفحص بيقيس **وجود المعلومة** مش تهجئة
           العنوان القديم — وإلا يبقى فحصًا على نص لا على سلوك. */
        chk(preg_match('~pf-count[^>]*>.*?الحركات.*?<b[^>]*>\s*[\d٠-٩,،]+~su', $html) === 1
            || count($money) === 0,
            'وعدد الحركات لسه ظاهر (اتنقل لسطر أدوات الشغل — مش ضاع مع بلاطته)');

        // ولا أيقونة وقعت على الدايرة الفاضية
        chk(!str_contains($html, 'icon-missing'), 'وولا أيقونة وقعت على الشكل الافتراضي');
    }
}

// ═══════════════════════════════════════════════════════════════════════
sec('④ نظام أيقونات واحد · صفر جملة تعليمية · شريط لاصق · صف إجمالي');
// ═══════════════════════════════════════════════════════════════════════

/* ⚠️ الفحص على **الناتج المرندَر** مش على المصدر: الإيموجي ممكن تيجي من
   `Permit::STAGES` أو من الـview أو من `icon()` — والمرندَر بيمسك التلاتة. */
$permitHtml = '';
if ($pid > 0) {
    foreach (['view', 'manage'] as $m) {
        $_GET = [];
        ob_start();
        try { $c = new PermitController(); $m === 'view' ? $c->view((string) $pid) : $c->manage((string) $pid); $permitHtml .= (string) ob_get_clean(); }
        catch (\Throwable $e) { ob_end_clean(); bad("وضع `$m` رمى استثناء: " . $e->getMessage()); }
    }
}

if ($permitHtml === '') {
    ok('مفيش ناتج مرندَر — تخطّي');
} else {
    /* ① نظام أيقونات واحد.
       ⚠️ **الفحص على مصادر الشاشتين مش على الناتج المرندَر**: الناتج بيشمل
       الغلاف العام (`layout.php`) اللي لسه فيه إيموجي في لوحة الأوامر ورسايل
       الإشعارات — ودي **برّه نطاق التعديل ده** (شاشتا الإذن). فحص الناتج كان
       بيفشل على حاجة مش بتاعة الوحدة دي.
       ⚠️ و`code_only()` إجبارية: التعليق اللي بيوثّق إن الإيموجي اتشالت
       **بيذكرها**، فالفحص كان هيفشل على توثيق نفسه. */
    $screenFiles = [
        '_hero.php', '_bar.php', '_body_view.php', '_body_manage.php', '_movement.php',
        '_journey.php', '_rcp_guide.php', '_party_chips.php', '_docs.php', 'show.php',
        'index.php', 'form.php',
    ];
    $strayGlyphs = ['📄','🪙','🚛','🧾','➕','⚙️','📦','🚢','📝','✅','↩️','▾','▴','⇄','✓','✕','🏢','👤'];
    $dirty = [];
    foreach ($screenFiles as $fn) {
        $src = code_only((string) file_get_contents($root . '/app/Views/permits/' . $fn));
        foreach ($strayGlyphs as $g) { if (str_contains($src, $g)) { $dirty[] = "$fn:$g"; } }
    }
    foreach ($strayGlyphs as $g) { if (str_contains(code_only($model), $g)) { $dirty[] = "Permit.php:$g"; } }
    chk($dirty === [],
        '⚠️ شاشتا الإذن بنظام أيقونات واحد (`icon()`) — مفيش إيموجي ولا محرف رسم'
        . ($dirty ? ' — لسه في: ' . implode(' · ', $dirty) : ''));
    /* والأيقونة في `Permit::STAGES` اسم صالح مش إيموجي */
    foreach (\App\Models\Permit::STAGES as $sk => $sm) {
        chk(preg_match('~^[a-z0-9-]+$~', (string) $sm['icon']) === 1
            && !str_contains(icon((string) $sm['icon'], 12), 'r="9"'),
            "أيقونة مرحلة `$sk` اسم موجود في `icon()` (مش إيموجي ولا اسم غلط)");
    }
    /* ⚠️ والاسم الغلط ما بيرميش خطأ — بيرسم دايرة فاضية */
    chk(preg_match_all('~<circle cx="12" cy="12" r="9"/></svg>~', $permitHtml) === 0,
        'وولا أيقونة وقعت على الدايرة الفاضية (الاسم الغلط ما بيرميش خطأ)');

    /* ② صفر جملة تعليمية — النصوص اللي اتشالت بالحرف */
    $lectures = [
        'يفكّرك بس'                  => 'مرشد الإيصالات',
        'عشان تبدأ التتبّع'          => 'رحلة الشحنة',
        'دون تغيير المبلغ أو الكمية'  => 'نقل الإيصال',
    ];
    foreach ($lectures as $txt => $where) {
        chk(!str_contains($permitHtml, $txt), "مفيش جملة تعليمية في «{$where}»");
    }
    /* ⚠️ دي كانت بتتحقن من `app.js` على **كل** جدول في النظام، و`verify_density`
       بيفحص `app/Views` بس فما كانش بيشوفها.
       ⚠️ ولازم نقصّ تعليقات الجافاسكريبت الأول — التعليق اللي بيوثّق إن الجملة
       اتشالت **بيقتبسها**، فالفحص كان بيفشل على توثيق نفسه (اتصاد فعليًّا).
       نفس قاعدة `code_only()`/`css_only()` بالظبط. */
    $jsCode = (string) preg_replace('~/\*.*?\*/|(?<![:\w])//[^\n]*~s', '',
        (string) file_get_contents($root . '/public/assets/js/app.js'));
    chk(!str_contains($jsCode, 'اضغط بجوار عنوان العمود'),
        '⚠️ وجملة ترتيب الأعمدة اتشالت من `app.js` (كانت فوق كل جدول في النظام)');

    /* ③ الشريط اللاصق بيقف **تحت** الترويسة مش وراها */
    chk(str_contains($cssCode, '--topbar-h'),
        'ارتفاع الترويسة توكن مشترك (`--topbar-h`) مش رقم مكتوب بالإيد');
    chk(preg_match('~\.permit-bar\{[^}]*top:calc\(var\(--topbar-h\)~', $cssCode) === 1,
        '⚠️ وشريط إجراءات الإذن لاصق **تحتها** — كان `top:8px` يعني مخبّي وراها بالكامل');

    /* ④ صف الإجمالي — والأهم إن أعمدته **مطابقة لرأس الجدول** */
    $dom = new \DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?>' . $permitHtml);
    libxml_clear_errors();
    $xp = new \DOMXPath($dom);
    $span = static function (\DOMElement $row) use ($xp): int {
        $n = 0;
        foreach ($xp->query('./td|./th', $row) as $cell) { $n += max(1, (int) $cell->getAttribute('colspan')); }
        return $n;
    };
    $withFoot = 0; $badFoot = [];
    foreach ($xp->query('//table[tfoot]') as $tbl) {
        $withFoot++;
        $headRows = $xp->query('./thead/tr', $tbl);
        if (!$headRows->length) { continue; }
        $cols = $span($headRows->item(0));
        foreach ($xp->query('./tfoot/tr', $tbl) as $fr) {
            if ($span($fr) !== $cols) { $badFoot[] = $span($fr) . '≠' . $cols; }
        }
    }
    /* ⚠️ **إعادة بناء v103 شالت الجداول من وضع العرض** واستبدلتها بصفوف
       (`pv3-it`) — فالإجمالي مابقاش في `tfoot` بل في **ترويسة كل مجموعة**
       (`pv3-grp-t`) وفي ترويسة الحركة. القاعدة اللي الفحص قايم عليها زي ما هي:
       «المجموع لازم يبان جنب بنوده لا في الهيرو على بُعد ١٥٠٠ بكسل تمرير» —
       والمقياس اتحوّل للمكان الجديد. الجداول اللي لسه موجودة (وضع التعديل)
       بيفضل شرط تطابق الأعمدة عليها زي ما هو. */
    $groupTotals = substr_count($permitHtml, 'pv3-grp-t');
    $mvTotals    = substr_count($permitHtml, 'class="pf-n"');
    chk($groupTotals + $mvTotals >= 3,
        "المجموع بيبان جنب بنوده ({$groupTotals} مجموعة + {$mvTotals} ترويسة حركة)");
    /* ⚠️ **`colspan` غلط بيزحلق كل الأرقام عمودًا** فيبان مبلغ تحت عنوان تاني
       — وما بيرميش أي خطأ. ده الفحص الوحيد اللي بيمسكه. */
    chk($badFoot === [],
        '⚠️ وأعمدة كل تذييل مطابقة لرأس جدوله' . ($badFoot ? ' — مختلف: ' . implode(', ', $badFoot) : ''));
}

// ═══════════════════════════════════════════════════════════════════════
sec('⑤ ⚠️ العزل المالي — الحد الأحمر');
// ═══════════════════════════════════════════════════════════════════════
$treasuryAfter = Closing::currentBalance();
chk(abs($treasuryBefore - $treasuryAfter) < 0.001,
    'رصيد الخزنة قبل = بعد (' . money($treasuryAfter, false) . ')');

echo "\n" . str_repeat('=', 62) . "\n";
if ($failures) {
    echo 'النتيجة: ' . $pass . " ناجح · " . count($failures) . " فاشل\n";
    foreach ($failures as $f) { echo "  ✗ $f\n"; }
    exit(1);
}
echo "النتيجة: $pass ناجح · 0 فاشل\n";
