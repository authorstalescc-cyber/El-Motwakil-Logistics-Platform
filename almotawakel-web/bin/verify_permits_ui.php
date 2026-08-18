<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص وحدة الأذونات — المرحلة ١٣ (2026-07-25).
 *
 * قرار المالك من المرحلة ٣ كان **دمج شاشتي الإذن**. المرحلة ٣ عملت نص الشغل
 * (الهيرو والشريط بقوا أجزاء مشتركة + مفتاح وضع)، لكن الفصل نفسه فضل: ملفان
 * كاملان وأكشنان — فأي تعديل على الغلاف لازم يتعمل مرتين، وأول مرة تُنسى
 * بتبقى الشاشتان مختلفتين من غير ما حد ياخد باله.
 *
 * أهم المحروس:
 *   • **غلاف واحد** (`permits/show.php`) بيرسم الشريط والهيرو والمرشد مرة واحدة.
 *   • **الجسمان منفصلان عن قصد** — التحرير مربوط بعقد AJAX في `_movement.php`.
 *   • **المساران القديمان زي ما هما** — الروابط المحفوظة عند المالك.
 *   • **التقدّم بيتحسب في الغلاف** — الهيرو بيقراه قبل الجسم (اتصاد فعليًّا:
 *     أربع تحذيرات PHP جوّه الصفحة لما كان في الجسم).
 *   • **العزل المالي** — رصيد الخزنة قبل = بعد.
 *
 * بيرندر الشاشة **بوضعيها فعليًّا** ببيانات حقيقية بلا أي كتابة.
 * التشغيل:  php bin/verify_permits_ui.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\PermitController;
use App\Core\Database;
use App\Models\Closing;

$pass = 0; $failures = [];
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $failures; $failures[] = $m; echo "  ✗ $m\n"; }
function chk(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function sec(string $m): void { echo "\n$m\n" . str_repeat('─', 62) . "\n"; }

echo "فحص وحدة الأذونات — المرحلة ١٣\n" . str_repeat('=', 62) . "\n";

$root = dirname(__DIR__);
$show  = (string) file_get_contents($root . '/app/Views/permits/show.php');
$bodyV = (string) file_get_contents($root . '/app/Views/permits/_body_view.php');
$bodyM = (string) file_get_contents($root . '/app/Views/permits/_body_manage.php');
$ctrl  = (string) file_get_contents($root . '/app/Controllers/PermitController.php');
$idx   = (string) file_get_contents($root . '/app/Views/permits/index.php');
$routes = (string) file_get_contents($root . '/public/index.php');
$appCssIdx = (string) file_get_contents($root . '/public/assets/css/app.css');

$treasuryBefore = Closing::currentBalance();

// ═══ ① الدمج حصل فعلاً
sec('① الشاشتان بقوا شاشة واحدة بوضعين');
chk(!is_file($root . '/app/Views/permits/view.php')
    && !is_file($root . '/app/Views/permits/manage.php'),
    'الملفان المنفصلان اتشالوا');
chk(is_file($root . '/app/Views/permits/show.php'), 'والغلاف الواحد `show.php` موجود');
chk(str_contains($show, "include __DIR__ . '/_body_' . \$mode . '.php'"),
    'الغلاف بيضمّ جسم الوضع المطلوب');
foreach (['_hero.php' => 'الهيرو', '_bar.php' => 'شريط الإجراءات', '_rcp_guide.php' => 'مرشد الإيصالات'] as $part => $label) {
    chk(str_contains($show, "include __DIR__ . '/$part'")
        && !str_contains($bodyV, "include __DIR__ . '/$part'")
        && !str_contains($bodyM, "include __DIR__ . '/$part'"),
        "{$label} بيترسم **مرة واحدة** في الغلاف مش في الجسمين");
}
chk(str_contains($show, 'الجسمان فضلوا منفصلين عن قصد'),
    '⚠️ وسبب بقاء الجسمين منفصلين مكتوب في رأس الغلاف (عقد AJAX في `_movement.php`)');

// ═══ ② الكنترولر نقطة واحدة
sec('② الكنترولر — نقطة تجميع واحدة');
chk(str_contains($ctrl, 'private function showPage(int $pid, string $mode): void'),
    'دالة `showPage` واحدة بتخدم الوضعين');
chk(substr_count($ctrl, "\$this->render('permits/show', \$data)") === 1,
    'ورندر واحد بس للشاشة');
chk(str_contains($ctrl, "\$this->showPage((int) \$id, 'view')")
    && str_contains($ctrl, "\$this->showPage((int) \$id, 'manage')"),
    'والأكشنان بيمرّوا عليها');
chk(!str_contains($ctrl, "render('permits/view'") && !str_contains($ctrl, "render('permits/manage'"),
    'مفيش رندر باقي للقوالب القديمة');

// ═══ ③ ⚠️ المسارات القديمة زي ما هي
sec('③ ⚠️ الروابط المحفوظة عند المالك لازم تفضل شغّالة');
chk(str_contains($routes, "\$r->get('/permits/{id}'"), 'مسار العرض `/permits/{id}` موجود');
chk(str_contains($routes, "\$r->get('/permits/{id}/manage'"), 'ومسار التعديل `/permits/{id}/manage` موجود');
$greedy = strpos($routes, "\$r->get('/permits/{id}'");
$manageAt = strpos($routes, "\$r->get('/permits/{id}/manage'");
chk($manageAt !== false && $greedy !== false && $manageAt < $greedy,
    'والحرفي قبل الجشع (تحذير ٤ — الراوتر first-match-wins)');

// ═══ ④ ⚠️ التقدّم في الغلاف
sec('④ ⚠️ التقدّم بيتحسب قبل الهيرو');
/* ⚠️ العدّ نفسه اتنقل لـ`Permit::completionOf()` (2026-08-10) — كان مكتوبًا
   بالإيد هنا وبقاعدة تانية في كنترولر شاشة المتابعة، فالإذن بأكتر من حركة
   كان بيقول نسبتين. الغلاف بيجهّز المدخلات وبيستدعي الدالة، **مش بيعدّ**. */
chk(str_contains($show, 'Permit::completionOf(') && str_contains($show, "'percent' => \$progress"),
    'التقدّم محسوب في الغلاف — ومن `Permit::completionOf()` مش بالإيد');
chk(!str_contains($bodyV, '$progress =') && !str_contains($bodyM, '$progress ='),
    'ومش محسوب في أي جسم — لو رجع هناك الهيرو بيقرا متغيّر لسه ما اتحسبش');
chk(str_contains($show, 'اتصاد فعليًّا'),
    'والباج ده موثَّق جنب الكود عشان ما يترجعش');

// ═══ ⑤ الأيقونات في شاشة الأذونات
sec('⑤ شاشة الأذونات — أيقونات SVG مش إيموجي في الترويسة');
chk(!preg_match('/class="pvt-ic">[^<]*[\x{1F300}-\x{1FAFF}]/u', $idx),
    'تبويبات الصفحة بأيقونات SVG');
chk(substr_count($idx, "icon('") >= 8, 'واستخدام `icon()` بقى فعليًّا في الشاشة');
chk(!preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u',
        preg_replace('~<\?(php|=).*?\?>|/\*[\s\S]*?\*/~s', '', $idx) ?? ''),
    'وصفر إيموجي في الجزء الظاهر (كانوا 🗓️ · ⬇️ · ⛃ · ▾)');

/* ⚠️ مؤشرات الشاشة دي **روابط لقايمة مفلترة** لا نوافذ — وده شكل أقوى من
   البوب-أب: بيوصّل لشاشة كاملة فيها الفلاتر والتصدير والإجراءات الجماعية.
   قاعدة «ممنوع رقم أصمّ» بتتحقق: الرقم بيتضغط وبيوصّل لبنوده.
   ⚠️ **الفحص على المعنى مش على اسم كلاس**: البطاقات كانت ماركب مكتوب بالإيد
      بكلاسات `.pv-kpi` تخصّ الشاشة دي وحدها، وبقت مكوّن `stat` المشترك
      بمعامل `url`. اللي بيهمّنا إن الأربعة موجودين وكل واحد بيوصّل لقايمته. */
/* ⚠️ **العدّ على المصدر بيغلط هنا**: الملف فيه ٦ نداءات `stat` — تلاتة ثابتة
   وتلاتة كل واحد لوضعه، وواحد بس بيترندر في المرة. العدّ الصح على **الناتج**
   المرندَر، وده بيحصل في القسم ⑥ب تحت. هنا بنتأكد من الشكل بس. */
chk(substr_count($idx, "partial('stat',") >= 4 && substr_count($idx, "'url' =>") >= 4,
    '⚠️ البطاقات بمكوّن `stat` المشترك، وكل واحدة معاها `url` لقايمتها');

/* ⚠️ **مصيدة موثَّقة وقعت هنا وأنا بكتب الفحص**: الملف فيه تعليق بيوثّق
   الكلاس القديم، ففحص «مش موجود» على النص الخام بيفشل بإنذار كاذب.
   لازم نشيل تعليقات PHP الأول (نفس `code_only` في verify_security). */
$idxCode = preg_replace('~/\*[\s\S]*?\*/|<\?php\s*//[^\n]*~', '', $idx) ?? $idx;
chk(!str_contains($idxCode, 'pv-kpi') && !str_contains($appCssIdx, 'pv-kpi'),
    'و`.pv-kpi` اتشالت من الماركب **ومن `app.css`** مع بعض (مفيش كود ميت ولا كلاس بلا شكل)');
chk(str_contains($idx, "url('permits?view=records&st=open')")
    && str_contains($idx, "url('permits?view=records&st=closed')"),
    'و«مفتوحة»/«مقفولة» لسه بيوصّلوا لقايمتهم المفلترة بالظبط');

/* ⚠️ `use (...)` في الـclosure **بتربط وقت الإنشاء لا وقت الاستعمال**، وكل وضع
   بيبعت متغيّراته هو بس — فبلا التصفير فوق الصفحة بيطلع «Undefined variable»
   في وضعين من التلاتة. اتصاد فعليًّا وقت التحويل. */
chk(str_contains($idx, '$incomplete = $incomplete ?? 0')
    && str_contains($idx, '$movesCount = $movesCount ?? 0')
    && str_contains($idx, '$total      = $total      ?? 0'),
    '⚠️ ومتغيّرات الأوضاع متصفّرة قبل الـclosure (وإلا تحذير في وضعين من التلاتة)');

/* الشاشة دي بتتفتح كل يوم — الكثافة والوضع الليلي بيعتمدوا على إن مفيش
   لون ولا تخطيط مكتوب في الماركب. */
chk(preg_match_all('/\sstyle="(?!width:)/', $idx) === 0,
    'وصفر `style=` inline ثابت (الديناميكي `width:%` للأشرطة بس هو المسموح)');
chk(!str_contains($idx, '<style'),
    'وصفر `<style>` محلي — شريط الإجراءات والمودال اتنقلوا لـ`app.css` بتوكنز');
chk(str_contains($idx, 'title="الجهة"')
    && str_contains($idx, 'title="التوكيل الملاحي"')
    && str_contains($idx, "\$r['destination']")
    && str_contains($idx, "\$r['agency']"),
    'وكارت متابعة الإذن يعرض الجهة والتوكيل الملاحي');
chk(str_contains($ctrl, 'p.destination LIKE ?')
    && str_contains($ctrl, 'p.agency LIKE ?'),
    'وبحث متابعة الأذونات يشمل الجهة والتوكيل');

// ═══ ⑥ رندر فعلي للوضعين
sec('⑥ الشاشة بترندر بوضعيها فعليًّا');
$pid = (int) (Database::scalar('SELECT MAX(id) FROM permits') ?? 0);
$uid = (int) Database::scalar('SELECT MIN(id) FROM users');
$_SESSION = ['uid' => $uid, 'role' => 'owner', 'uname' => 'فحص',
             'last_activity' => time(), 'uid_checked' => time()];
$_GET = [];
if ($pid <= 0) {
    ok('مفيش أذونات في القاعدة — تخطّي الرندر');
} else {
    foreach (['view', 'manage'] as $mode) {
        ob_start();
        try {
            $c = new PermitController();
            $mode === 'view' ? $c->view((string) $pid) : $c->manage((string) $pid);
            $html = (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean(); bad("وضع `$mode` رمى استثناء: " . $e->getMessage()); continue;
        }
        preg_match('/Fatal error|Warning:|Undefined variable|Undefined array key/', $html, $m)
            ? bad("خطأ PHP في وضع `$mode`: " . ($m[0] ?? ''))
            : ok("وضع `$mode` بيرندر بلا أخطاء PHP");
        chk(str_contains($html, 'pf-plate'), "وضع `$mode` فيه الهيرو");
        chk(str_contains($html, 'pb-modes'), "ووضع `$mode` فيه مفتاح الوضع");
    }
}

// ═══════════════════════════════════════════════════════════════════════
sec('⑥ب سجلّ الأذونات بيرندر بأوضاعه التلاتة');
// ═══════════════════════════════════════════════════════════════════════

/* ⚠️ الرندر الفعلي هو الفحص الوحيد اللي بيمسك: (أ) عدد البطاقات الحقيقي
   (المصدر فيه ٦ نداءات `stat` وواحد بس بيترندر لكل وضع، فالعدّ على المصدر
   بيغلط) · (ب) تحذير `use()` على متغيّر مش متبعوت في الوضع ده · (ج) أيقونة
   وقعت على الشكل الافتراضي بالسكوت. */
$modesToRender = [
    'متابعة'   => [],
    'الحركات'  => ['view' => 'movements'],
    'السجلّات' => ['view' => 'records'],
];
/* ⚠️ **«متابعة» بلا شبكة أرقام عن قصد** (2026-08-10): كانت بتعرض «إجمالي
   الأذونات» اللي بيعدّ **كل** الأذونات، وتحتها أزرار «الكل/ناقص/جاهز» اللي
   بتعدّ **المفتوحة بس** — رقمان جنب بعض معناهم «الكل» ومختلفين. والأزرار
   بتفلتر فعلاً فهي أنفع. الوضعان التانيان نطاقهما واحد فالشبكة بتفضل. */
$expectKpis = ['متابعة' => 0, 'الحركات' => 4, 'السجلّات' => 4];
$savedGet = $_GET;
foreach ($modesToRender as $mname => $mget) {
    $_GET = $mget; $_REQUEST = $mget;
    ob_start();
    try { (new PermitController())->index(); $mh = (string) ob_get_clean(); }
    catch (\Throwable $e) { ob_end_clean(); bad("وضع «{$mname}» رمى استثناء: " . $e->getMessage()); continue; }

    preg_match('/Fatal error|Warning:|Undefined variable|Undefined array key/', $mh, $mm)
        ? bad("وضع «{$mname}»: " . ($mm[0] ?? ''))
        : ok("وضع «{$mname}» بيرندر بلا أخطاء PHP");
    $kpiCount = preg_match_all('/class="d-kpi /', $mh);
    chk($kpiCount === $expectKpis[$mname],
        "و«{$mname}» فيه {$expectKpis[$mname]} بطاقة رقم مرسومة فعلاً (لقى {$kpiCount})");
    chk(!str_contains($mh, 'stat بلا drill'), "ومفيش رقم أصمّ في «{$mname}»");
    chk(preg_match_all('~<circle cx="12" cy="12" r="9"/></svg>~', $mh) === 0,
        "وولا أيقونة وقعت على الشكل الافتراضي في «{$mname}»");
}
$_GET = $savedGet; $_REQUEST = $savedGet;

// ═══════════════════════════════════════════════════════════════════════
sec('⑦ فورم إصدار المستند — كثافة وأرقام قابلة للفتح (2026-07-28)');
// ═══════════════════════════════════════════════════════════════════════

/* الشاشة دي كانت **أكبر تجمّع `style=` inline في النظام (٤٣ موضع)** + بلوك
   `<style>` محلي فيه `.ct-chip` **مكرّرة حرفيًّا** مع `statements/form.php`
   بقيمتين مختلفتين. اللون المكتوب في الماركب بيمنع الوضع الليلي، والـinline
   بيدهس نقاط الكسر المتجاوبة — فالاتنين اتنقلوا لـ`app.css` بتوكنز. */
$invForm = (string) file_get_contents($root . '/app/Views/permits/invoice_form.php');
$stForm  = (string) file_get_contents($root . '/app/Views/statements/form.php');
$appCss  = (string) file_get_contents($root . '/public/assets/css/app.css');

chk(preg_match_all('/\sstyle="/', $invForm) === 0,
    '⚠️ صفر `style=` inline في الفورم — كانوا ٤٣');
chk(!str_contains($invForm, '<style'),
    'وصفر `<style>` محلي — الكلاسات كلها في `app.css`');
chk(preg_match('/^\.ct-chip\{/m', $appCss) === 1 && !preg_match('/\.ct-chip\{/', $stForm),
    '⚠️ `.ct-chip` مصدرها واحد في `app.css` — مش مكرّرة في `<style>` الشاشتين');
chk(str_contains($invForm, 'class="ct-chip"') && str_contains($stForm, 'class="ct-chip"'),
    'والشاشتان لسه بتستعملاها (الكلاس مش كود ميت)');

/* ⚠️ `textarea` كان **ناقصًا** من قاعدة `.field` رغم إن `partials/field.php`
   بيرندره — فكان بياخد شكل المتصفح الافتراضي أو `style=` inline في الشاشة.
   نفس عائلة `.table-mobile-scroll`: ماركب موجود وشكل ناقص = فشل صامت. */
chk(preg_match('/\.field input,\.field select,\.field textarea\{/', $appCss) === 1,
    '⚠️ و`.field textarea` بقت معرَّفة في `app.css` (كانت ناقصة فبتقع على شكل المتصفح)');
$taBad = [];
foreach (['settings/index.php', 'push/index.php', 'permits/invoice_form.php'] as $v) {
    $s = (string) file_get_contents($root . '/app/Views/' . $v);
    if (preg_match('/<textarea[^>]*\sstyle="/', $s)) { $taBad[] = $v; }
}
chk($taBad === [], 'ومفيش `textarea` بيتنسّق `style=` inline'
    . ($taBad ? ': ' . implode(', ', $taBad) : ''));

/* **ممنوع رقم أصمّ**: الخمسة كلهم بيفتحوا بنودهم. `stat` بيرفض الرندر بلا
   `drill` فبيطبع تعليقًا — الفحص بيمسك التعليق ده لو حصل. */
chk(preg_match_all("~partial\('stat'~", $invForm) === 5,
    'خمس بطاقات `stat` بدل الخمس تايلات الصمّاء');
chk(preg_match_all('~id="invDrill~', $invForm) === 5,
    'ولكل واحدة جسم نافذة جاهز في الصفحة (`#invDrill*`)');

/* ⚠️⚠️ أهم فحص في القسم: **مجموع صفوف النافذة = الرقم في الكارت بالمليم.**
   لو اختلفوا، النافذة بتكدب على المالك. الضمانة إن الاتنين بيقروا من نفس
   المصدر: `Permit::bills` (نفس مصدر `totals['custody']` و`actualFreight`)
   و`Permit::expenseRows` (نفس `expenseSql` بتاعة `totals['expense']`).
   ⚠️ متحقَّق إن فيه بندًا مربوطًا بالإذن **بلا حركة**، فتجميع البنود من
      `movementStagesGrouped` كان هيقلّ عن الإجمالي — عشان كده الموديل. */
$pidInv = (int) (Database::scalar(
    'SELECT s.permit_id FROM permit_stages s GROUP BY s.permit_id ORDER BY COUNT(*) DESC LIMIT 1'
) ?? 0);
if ($pidInv <= 0) {
    ok('مفيش بنود مراحل في القاعدة — تخطّي فحص مطابقة النوافذ');
} else {
    $t  = \App\Models\Permit::totals($pidInv);
    $bl = \App\Models\Permit::bills($pidInv);
    $ex = \App\Models\Permit::expenseRows($pidInv);
    $fr = \App\Models\PermitInvoice::actualFreight($pidInv);
    $sumEx = array_sum(array_map(static fn ($r) => (float) $r['amount'], $ex));
    $sumCu = array_sum(array_map(static fn ($b) => (float) $b['custody'], $bl));
    $sumFr = array_sum(array_map(static fn ($b) => (float) $b['freight'], $bl));
    chk(abs($t['expense'] - $sumEx) < 0.005,
        '⚠️ مجموع نافذة المصروفات = رقم الكارت (' . money($t['expense'], false) . ')');
    chk(abs($t['custody'] - $sumCu) < 0.005,
        'ومجموع نافذة العهدة = رقم الكارت (' . money($t['custody'], false) . ')');
    chk(abs($fr - $sumFr) < 0.005,
        'ومجموع نافذة النولون = رقم الكارت (' . money($fr, false) . ')');
    chk($ex !== [] && $bl !== [],
        'والفحص جرى على إذن فيه بنود فعلاً (' . count($ex) . ' بند · ' . count($bl) . ' بوليصة)');

    // ═══ رندر فعلي للأكشن نفسه — بيمرّ على وصلة الكنترولر←الـview
    ob_start();
    try {
        (new PermitController())->invoiceForm((string) $pidInv);
        $ih = (string) ob_get_clean();
    } catch (\Throwable $e) {
        ob_end_clean(); $ih = ''; bad('`invoiceForm` رمى استثناء: ' . $e->getMessage());
    }
    if ($ih !== '') {
        preg_match('/Fatal error|Warning:|Undefined variable|Undefined array key/', $ih, $m)
            ? bad('خطأ PHP في فورم الفاتورة: ' . ($m[0] ?? ''))
            : ok('الفورم بيرندر من الأكشن بلا أخطاء PHP');
        chk(!str_contains($ih, 'stat بلا drill'),
            '⚠️ ومفيش بطاقة رقم بلا مصدر تفاصيل (المكوّن بيرفض ويطبع تعليقًا)');
        chk(preg_match_all('/class="d-kpi /', $ih) === 5, 'وخمس بطاقات اترسمت فعلاً');
        /* ⚠️ الأيقونة الناقصة بتقع على `<circle r="9"/>` **بالسكوت** — كان
           `box` مستعملًا وهو غير معرَّف (الصح `package`). */
        chk(preg_match_all('~<circle cx="12" cy="12" r="9"/></svg>~', $ih) === 0,
            'وولا أيقونة وقعت على الشكل الافتراضي (دايرة فاضية)');
    }
}

// ═══════════════════════════════════════════════════════════════════════
sec('⑦ج إضافة مصاريف الإذن للفاتورة (طلب المالك 2026-08-01)');
// ═══════════════════════════════════════════════════════════════════════

/* ⚠️ **مصاريف الإذن مش بتتسحب تلقائيًّا** (قرار v20 — منها اللي بيتحمّله المكتب)،
   فبقت **معروضة والمالك يختار**. الفحص بيغطّي: اللوح موجود · البند بيتضاف
   بـ`stage:{id}` · والأهم إن الإصدار **ما بيمسّش المبلغ النقدي** فالخزنة
   والإقفال ما يتأثروش. */
chk(str_contains($invForm, 'js-add-exp') && str_contains($invForm, 'class="inv-exp"'),
    'لوح «مصاريف الإذن» موجود في الفورم بزرار إضافة لكل بند');
chk(str_contains($invForm, "'stage:' + btn.getAttribute('data-id')"),
    '⚠️ والبند بيتضاف بـ`item_src = stage:{id}` — نفس مصدر بنود الإيصالات');
/* ⚠️ البيان جاي من إدخال المستخدم (ملاحظة البند) — لزقه في نص HTML ثغرة XSS. */
chk(preg_match("~\.i-label'\)\.value = lbl~", $invForm) === 1
    && !preg_match('~innerHTML[^;]*data-label~', $invForm),
    '⚠️ والبيان بيتحطّ بـ`value` مش جوّه `innerHTML` (البيان إدخال مستخدم)');
/* ⚠️ كل كلاس بيحقنه الجافاسكريبت لازم يكون له شكل — مصيدة `.table-mobile-scroll`. */
$expClasses = ['inv-exp', 'inv-exp-body', 'inv-exp-row', 'inv-exp-main',
               'inv-exp-meta', 'inv-exp-amt', 'inv-exp-c', 'inv-exp-warn'];
$noCss = array_values(array_filter($expClasses,
    static fn ($c) => !preg_match('~\.' . preg_quote($c, '~') . '[\{,\s>:]~', $appCss)));
chk($noCss === [], 'وكل كلاسات اللوح معرَّفة في `app.css`'
    . ($noCss ? ' — ناقص: ' . implode('، ', $noCss) : ''));
chk(str_contains($invForm, 'is-added'),
    'والبند المتضاف بيتقفل زرّاره (مايتحطش مرتين)');

/* ⚠️⚠️ **الحد الأحمر**: المزامنة العكسية بتحدّث `note/qty/actual_amount` بس.
   لو مسّت `amount` كان مصروف الإذن هيتغيّر رقمه النقدي عند إصدار الفاتورة —
   يعني الخزنة والتقفيل المثبَّت يتغيّروا من شاشة فوترة. */
$ctlSrc = (string) file_get_contents($root . '/app/Controllers/PermitController.php');
chk(preg_match('~UPDATE permit_stages SET note = \?, qty = \?, actual_amount = \? WHERE id = \? AND permit_id = \?~', $ctlSrc) === 1,
    '⚠️⚠️ المزامنة العكسية بتحدّث `note/qty/actual_amount` بس — `amount` النقدي ما بيتمسّش');

/* ⚠️⚠️ **حد أحمر تاني (قرار المالك 2026-08-01)**: الإصدار **لقطة لا نقطة إدخال**.
   البند اللي المالك يكتبه في الفاتورة يفضل في المستند وبس — ممنوع `INSERT` على
   `permit_stages` من `invoiceStore`. لو رجع، أي بند فاتورة بيتحوّل لمصروف حقيقي
   على الإذن وبيدخل المصروفات وشاشة مراجعة التقفيل **بلا ما حد يقصد**.
   ⚠️ القصّ لحد أول `public function` بعدها — القصّ بعدد حروف ثابت بيبلع الدالة
      اللي وراها ويدّي إنذارًا كاذبًا (مصيدة موثَّقة). */
$storeStart = strpos($ctlSrc, 'public function invoiceStore(');
$storeBody  = '';
if ($storeStart !== false) {
    $nextFn    = strpos($ctlSrc, "\n    public function ", $storeStart + 10);
    $storeBody = substr($ctlSrc, $storeStart, ($nextFn === false ? strlen($ctlSrc) : $nextFn) - $storeStart);
}
chk($storeBody !== '', 'جسم `invoiceStore` اتقصّ للفحص');
chk(!preg_match('~INSERT\s+INTO\s+permit_stages~i', $storeBody),
    '⚠️⚠️ إصدار المستند ما بيضيفش أي بند على الإذن — مفيش `INSERT INTO permit_stages` في `invoiceStore`');
chk(!preg_match("~\\\$src\s*===\s*'new'~", $storeBody),
    'ومفيش أي فرع بيتعامل مع البند الجديد كإدخال على الإذن');
/* والتحذير لازم يفضل ظاهر للمالك — من غيره هيفتكر البند اتسجّل على الإذن. */
chk(str_contains($invForm, 'في المستند بس — مش على الإذن'),
    'والفورم بيقول صراحةً إن البند في المستند بس');

/* رندر فعلي على إذن فيه مصاريف — اللوح لازم يبان ببنوده مش فاضي. */
$expPid = (int) Database::scalar(
    'SELECT s.permit_id FROM permit_stages s WHERE ' . \App\Models\Permit::expenseSql('s')
    . ' GROUP BY s.permit_id ORDER BY COUNT(*) DESC LIMIT 1');
if ($expPid > 0) {
    $expCount = (int) Database::scalar(
        'SELECT COUNT(*) FROM permit_stages s WHERE s.permit_id = ? AND ' . \App\Models\Permit::expenseSql('s'),
        [$expPid]);
    ob_start();
    try { (new PermitController())->invoiceForm((string) $expPid); $ih = (string) ob_get_clean(); }
    catch (\Throwable $e) { ob_end_clean(); $ih = ''; bad('فورم الفاتورة رمى استثناء: ' . $e->getMessage()); }
    if ($ih !== '') {
        chk(!preg_match('/Fatal error|Warning:|Undefined variable|Undefined array key/', $ih),
            'الفورم بيرندر بلا أخطاء PHP (إذن #' . $expPid . ' فيه ' . $expCount . ' مصروف)');
        chk(str_contains($ih, 'class="inv-exp"'), 'واللوح ظاهر فيه');
        /* ⚠️ العدّ على **الناتج المرندَر** مش على المصدر — الأزرار مشروطة.
           ⚠️ والعدّ على `js-add-exp"` بعلامة التنصيص المزدوجة عن قصد: الاسم
              بيتكرر في الجافاسكريبت كمان (`.js-add-exp'`) فالعدّ الخام بيزيد ١. */
        chk(substr_count($ih, 'js-add-exp"') === $expCount,
            'وفيه زرار لكل مصروف (' . $expCount . ')');
        chk(str_contains($ih, 'data-unit='), 'وكل زرار شايل سعر الوحدة جاهز');
    }
} else {
    echo "  … مفيش إذن فيه مصاريف — الرندر اتخطّى\n";
}

// ═══ ⑧ العزل المالي
sec('⑦ب ⚠️ عقد الـAJAX بتاع بلوك الحركة (`_movement.php`)');

/* ⚠️⚠️ **أخطر عقد في وحدة الأذونات.** `PermitController::ajaxMovement` بيرندر
   `permits/_movement.php` ويرجّعه HTML، و`applyMovement` في `app.js` بياخد
   **`tmp.firstElementChild`** ويبدّل بيه `#movement-{id}` القديم. يعني:
     • لازم **عنصر جذر واحد** — لو بقى اتنين، التاني بيضيع بالسكوت.
     • الجذر لازم يفضل `<details class="movement …" id="movement-{id}">` —
       لو الوسم أو الـid اتغيّر، التبديل بيفشل **والصفحة ما بتتحدّثش** بلا
       أي رسالة خطأ.
   الفحص ده بيرندر القالب فعليًّا ويفكّه بـDOM زي ما المتصفح هيعمل. */
$mvId = (int) (Database::scalar(
    'SELECT m.id FROM permit_movements m JOIN bawalis b ON b.movement_id = m.id
     GROUP BY m.id ORDER BY COUNT(b.id) DESC LIMIT 1'
) ?: (Database::scalar('SELECT MAX(id) FROM permit_movements') ?? 0));

if ($mvId <= 0) {
    ok('مفيش حركات في القاعدة — تخطّي فحص عقد الـAJAX');
} else {
    $mvRow = \App\Models\Permit::movement($mvId);
    $mvPid = (int) $mvRow['permit_id'];
    $mvP   = \App\Models\Permit::find($mvPid);
    $mvRow['bills']  = \App\Models\Permit::movementBills($mvId);
    $mvRow['stages'] = \App\Models\Permit::movementStagesGrouped($mvId);
    $mvVars = [
        'p' => $mvP, 'mv' => $mvRow,
        'mStages' => \App\Models\Permit::scopedStages('movement'),
        'mvExpenseKeys' => ['receipts'],
        'ctTotal' => (int) ($mvP['containers'] ?? 0),
        'ctAllocated' => (int) Database::scalar(
            'SELECT COALESCE(SUM(container_count),0) FROM bawalis WHERE permit_id = ?', [$mvPid]),
        'generators' => \App\Models\Permit::generators($mvP['permit_no']),
        'primaryDef' => \App\Models\Permit::primaryFeesDefault($mvPid),
        'receiptMoveTargets' => \App\Models\Permit::receiptMoveTargets($mvPid),
    ];
    $mvHtml = (static function (array $__v): string {
        extract($__v, EXTR_SKIP);
        ob_start();
        include dirname(__DIR__) . '/app/Views/permits/_movement.php';
        return trim((string) ob_get_clean());
    })($mvVars);

    preg_match('/Fatal error|Warning:|Undefined variable|Undefined array key/', $mvHtml, $mvErr)
        ? bad('بلوك الحركة فيه خطأ PHP: ' . ($mvErr[0] ?? ''))
        : ok('بلوك الحركة بيرندر بلا أخطاء PHP');

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="__t">' . $mvHtml . '</div>',
        LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    $holder = $doc->getElementById('__t');
    $roots = [];
    if ($holder) {
        foreach ($holder->childNodes as $n) {
            if ($n->nodeType === XML_ELEMENT_NODE) { $roots[] = $n; }
        }
    }
    chk(count($roots) === 1, '⚠️ عنصر جذر **واحد** بالظبط (الزيادة بتضيع في التبديل)');
    $root = $roots[0] ?? null;
    chk($root !== null && $root->tagName === 'details'
        && $root->getAttribute('id') === 'movement-' . $mvId
        && str_contains($root->getAttribute('class'), 'movement'),
        '⚠️ والجذر `<details class="movement" id="movement-{id}">` زي ما `applyMovement` متوقّع');

    /* الخطّافات اللي الجافاسكريبت بيدوّر عليها جوّه البلوك — لو اتشال واحد
       الميزة بتقف **بلا أي خطأ**. (`receipt-transfer-btn` مشروط بوجود هدف نقل
       فمش في القايمة الإجبارية.) */
    $mvHooks = ['js-ajax', 'ms-more-btn', 'ms-more', 'js-rc-qty', 'js-rc-unit',
                'js-rc-total', 'step-chip', 'mv-del'];
    $lostHooks = array_values(array_filter($mvHooks,
        static fn ($h) => !str_contains($mvHtml, $h)));
    chk($lostHooks === [], 'وكل خطّافات الجافاسكريبت لسه موجودة'
        . ($lostHooks ? ': ' . implode('، ', $lostHooks) : ''));

    chk(preg_match_all('/\sstyle="(?!width:)/', $mvHtml) === 0,
        'وصفر `style=` inline ثابت في الناتج (كانوا ٢٠ — الديناميكي `width:%` بس)');
    chk(preg_match_all('~<circle cx="12" cy="12" r="9"/></svg>~', $mvHtml) === 0,
        'وولا أيقونة وقعت على الشكل الافتراضي');
}

// ═══════════════════════════════════════════════════════════════════════
sec('⑧ ⚠️ العزل المالي — الحد الأحمر');
chk(abs($treasuryBefore - Closing::currentBalance()) < 0.005,
    'رصيد الخزنة قبل = بعد (' . money($treasuryBefore, false) . ')');

echo "\n" . str_repeat('=', 62) . "\n";
echo 'النتيجة: ' . $pass . ' ناجح · ' . count($failures) . " فاشل\n";
if ($failures) { foreach ($failures as $f) { echo "  ✗ $f\n"; } exit(1); }
exit(0);
