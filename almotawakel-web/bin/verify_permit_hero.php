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
chk(str_contains($css, '.hero-tiles'),
    'و`.hero-tiles` لسه موجودة كشبكة — الكلاس اللي `verify_mobile` بيحرسه');
chk(preg_match_all("~partial\('stat',~", $heroCode) === 4,
    'وأربع بطاقات `stat` بالظبط (مش ٨ ولا ٣)');

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
chk(str_contains($heroCode, 'is-key'),
    'وبكلاس التمييز `is-key` — بارز مش بلاطة عادية وسط الباقي');
/* ⚠️ الكلاس اللي بيتطبع بلا CSS بيقع على الشكل الافتراضي **بالسكوت** — نفس
   مصيدة `.table-mobile-scroll` اللي عاشت شهور معطَّلة. */
$cssCode = css_only($css);
foreach (['.hm-pill.is-key', '.hm-pill.is-key.is-blank'] as $cls) {
    chk(str_contains($cssCode, $cls), "وكلاس `$cls` معرَّف في `app.css` (مش ماركب بلا شكل)");
}
/* ⚠️ الحقل اللي بيتعرض في مكانين بيفترق مع أول تعديل — اتشال من «تفاصيل
   إضافية» لما اتحطّ في الهيرو. «شركة الشحن» حاجة تانية وبتفضل مكانها. */
$bodyVCode = code_only($bodyV);
chk(!preg_match("~'[^']*التوكيل[^']*',\s*\(\\\$p\['agency'\]~u", $bodyVCode),
    '⚠️ ومش مكرَّر في «تفاصيل إضافية» تحت (اتشال من هناك)');
chk(str_contains($bodyVCode, "shipping_co"),
    'و«شركة الشحن» فضلت مكانها — حقل تاني مختلف مش نفس الحاجة');

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

        // كل بلاطة في شبكة الهيرو لازم تكون `.d-kpi` بـ`data-drill`
        if (preg_match('~<div class="hero-tiles">(.*?)\n  </div>~s', $html, $m)) {
            $grid = $m[1];
            $cards = preg_match_all('~class="d-kpi ~', $grid);
            $drills = preg_match_all('~data-drill="~', $grid);
            chk($cards === 4, "شبكة الهيرو فيها ٤ كروت أرقام (لقى $cards)");
            chk($cards > 0 && $cards === $drills,
                "⚠️ وكل رقم فيها بيفتح تفاصيله ($drills من $cards)");
        } else {
            bad('ما لقيتش شبكة `.hero-tiles` في الناتج');
        }

        foreach (['phCustody', 'phExpense', 'phCharged', 'phMargin'] as $win) {
            chk(str_contains($html, 'id="' . $win . '"'), "جسم نافذة `$win` موجود في الصفحة");
        }

        /* التوكيل الملاحي **بيتعرض دايمًا** — بقيمته لو متسجّل، ومطفي بـ«غير
           محدّد» لو مش متسجّل (غيابه معلومة: قواعد التوكيل في مرشد الإيصالات
           ما بتشتغلش من غيره). الفحص بيتأكد إن الحالتين بتطلع صح. */
        $permitAgency = trim((string) (\App\Models\Permit::find($pid)['agency'] ?? ''));
        chk(preg_match('~<span class="hm-pill is-key~', $html) === 1,
            'وبلاطة التوكيل الملاحي ظاهرة في ميتا الهيرو');
        chk($permitAgency !== ''
            ? (str_contains($html, e($permitAgency)) && !str_contains($html, 'is-key is-blank'))
            : str_contains($html, 'is-key is-blank'),
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
        chk(substr_count($html, 'الحركات (') > 0 || count($money) === 0,
            'وعدد الحركات لسه ظاهر في عنوان قسم الحركات (مش ضاع مع بلاطته)');

        // ولا أيقونة وقعت على الدايرة الفاضية
        chk(!str_contains($html, 'icon-missing'), 'وولا أيقونة وقعت على الشكل الافتراضي');
    }
}

// ═══════════════════════════════════════════════════════════════════════
sec('④ ⚠️ العزل المالي — الحد الأحمر');
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
