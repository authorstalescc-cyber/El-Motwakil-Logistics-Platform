<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص «الإيصال بسعره وعربيته» (v109).
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  القواعد المحروسة هنا — اقرأ قبل ما تعدّل
 * ═══════════════════════════════════════════════════════════════════════
 *   ① **العزل المالي** — ولا ملف خزنة/تقفيل/عهدة بيعرف `receipt_lines`،
 *      والتوزيع **ما بيغيّرش ولا مليم** (بيتقاس بسلوك: نوزّع ونقارن الأرصدة).
 *   ② **المجموع = الإجمالي دايمًا** — `sync()` بترفض أي قايمة مجموعها أو
 *      عددها مخالف للبند. ده الحارس اللي بيمنع «تفصيل بيقول رقم والمال بيقول
 *      رقم تاني».
 *   ③ **`parse()` مصدر وحيد وقواعده بتنزل للموبايل** — الشاشة بتقرا نفس
 *      الثوابت، مش نسخة تانية من القواعد.
 *   ④ **الأسعار المختلفة بتعيش** من الإرسال للاعتماد للتعديل، **وربط
 *      العربيات ما بيضيعش** مع إعادة الكتابة.
 *   ⑤ **الحُرّاس** — عربية من إذن تاني · سطر مش من البند · سعر سالب · نص
 *      جوّه الأرقام · حذف البند بيشيل سطوره (CASCADE).
 *   ⑥ **المصالِح** — تغيير الإجمالي من أي مسار تاني بيصالح التفصيل بدل ما
 *      يسيبه كاذبًا.
 *   ⑦ **كل كلاس مطبوع له CSS، وكل أيقونة موجودة** (درس `.table-mobile-scroll`
 *      و`icon('اسم-غلط')` اللي بترسم دايرة فاضية).
 *   ⑧ **الأسعار المختلفة ممنوع تتعرض كـ«كمية × سعر»** — المتوسط ده رقم
 *      ما دفعوش حد.
 *
 * كله جوّه transaction وبيعمل rollback، فما بيسيبش أي أثر.
 * التشغيل:  php bin/verify_v109.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;
use App\Models\CompanySafe;
use App\Models\ReceiptLine;
use App\Models\RepInbox;
use App\Models\RepPortal;

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function sec(string $m): void { echo "\n" . $m . "\n" . str_repeat('─', 64) . "\n"; }
function near(string $l, $a, $e): void {
    abs((float) $a - (float) $e) <= 0.005 ? ok($l)
        : bad("{$l} — متوقع " . number_format((float) $e, 2) . ' / فعلي ' . number_format((float) $a, 2));
}
/** ⚠️ كود بلا تعليقات — التعليق اللي بيوثّق القاعدة بيذكر نصّها فيدي نتيجة كاذبة. */
function code_only(string $file): string {
    $p = dirname(__DIR__) . '/' . $file;
    if (!is_file($p)) { return ''; }
    $out = '';
    foreach (token_get_all((string) file_get_contents($p)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}
function src(string $file): string {
    $p = dirname(__DIR__) . '/' . $file;
    return is_file($p) ? (string) file_get_contents($p) : '';
}

echo "فحص «الإيصال بسعره وعربيته» (v109)\n" . str_repeat('=', 64) . "\n";

if (!ReceiptLine::ready()) {
    echo "\n⚠️ جدول receipt_lines مش موجود — شغّل bin/migrate_v109.php الأول.\n";
    exit(1);
}

// ═══════════════════════════════════════════════════════════ ①
sec('① العزل المالي — ولا ملف خزنة بيعرف الجدول');

/* ⚠️ الحارس ده هو اللي بيمنع «الاستثناء المنسي»: أول ما ملف مالي يذكر
   `receipt_lines`، معناه إن التفصيل بقى بيدخل حسابًا — والمال المفروض
   يفضل من `permit_stages.amount` وحده. */
$financial = [
    'app/Models/Closing.php', 'app/Models/Custody.php', 'app/Models/CompanySafe.php',
    'app/Models/DailyReview.php', 'app/Models/Expense.php', 'app/Models/Metrics.php',
    'app/Models/RepCustody.php', 'app/Models/VisaCard.php', 'app/Models/Payroll.php',
    'app/Calc.php',
];
$leak = [];
foreach ($financial as $f) {
    if (str_contains(code_only($f), 'receipt_lines')) { $leak[] = $f; }
}
$leak ? bad('ملفات مالية بتعرف الجدول: ' . implode(', ', $leak))
      : ok('ولا ملف خزنة/تقفيل/عهدة/مصروفات بيذكر `receipt_lines`');

/* ⚠️ **المفاتيح الخارجية هنا مطلوبة — عكس v108.** السطر جزء من بنده ومالوش
   معنى بدونه: بلا CASCADE، حذف بند بيسيب سطورًا يتيمة بتحمّل عربية مصروفًا
   اتمسح. الفحص ده بيمنع رجوع القاعدة لـRESTRICT/NO ACTION بالسكوت. */
$rules = [];
foreach (Database::all(
    "SELECT constraint_name AS n, delete_rule AS r
       FROM information_schema.referential_constraints
      WHERE constraint_schema = DATABASE() AND table_name = 'receipt_lines'") as $r) {
    $rules[(string) $r['n']] = strtoupper((string) $r['r']);
}
($rules['fk_rl_stage'] ?? '') === 'CASCADE'
    ? ok('حذف البند بيشيل سطوره (CASCADE)') : bad('fk_rl_stage مش CASCADE');
($rules['fk_rl_bawali'] ?? '') === 'SET NULL'
    ? ok('حذف العربية بيفكّ الربط (SET NULL)') : bad('fk_rl_bawali مش SET NULL');

// ═══════════════════════════════════════════════════════════ ③
sec('③ `parse()` مصدر وحيد — والشاشة بتقرا نفس قواعده');

$cases = [
    '250 250 400'   => [250.0, 250.0, 400.0],
    '250، 250، 400' => [250.0, 250.0, 400.0],
    '250,250,400'   => [250.0, 250.0, 400.0],
    "250\n400"      => [250.0, 400.0],
    '٢٥٠ ٤٠٠'       => [250.0, 400.0],
    '250*3'         => [250.0, 250.0, 250.0],
    '250×2 180'     => [250.0, 250.0, 180.0],
    '250.5 12.25'   => [250.5, 12.25],
    '   '           => [],
];
$bad = [];
foreach ($cases as $in => $want) {
    if (ReceiptLine::parse((string) $in) !== $want) { $bad[] = $in; }
}
$bad ? bad('صيغ اتفكّت غلط: ' . implode(' | ', $bad))
     : ok('كل صيغ الكتابة بتتفكّ صح (مسافة · فاصلة · سطر · عربي · ضرب · كسور)');

foreach (['250 هيئة' => 'نص جوّه الأرقام', '-50' => 'سعر سالب'] as $in => $what) {
    try { ReceiptLine::parse((string) $in); bad("$what اتقبل"); }
    catch (\RuntimeException) { ok("$what بيترفض"); }
}

/* ⚠️ **الثوابت لازم تنزل للشاشة مش تتكتب تاني بجافاسكريبت.** لو الشاشة
   كتبت القواعد بنفسها، المعاينة الحيّة بتقول «٣ إيصالات» والخادم يرفض —
   وده أسوأ من غياب المعاينة أصلاً. */
$entryJs = src('app/Views/m/entry.php');
$usesConsts = str_contains($entryJs, 'ReceiptLine::SEPARATORS')
           && str_contains($entryJs, 'ReceiptLine::TOKEN_RE')
           && str_contains($entryJs, 'ReceiptLine::MULT_RE');
$usesConsts ? ok('شاشة المندوب بتنزّل قواعد `ReceiptLine` مش بتكتبها تاني')
            : bad('شاشة المندوب كتبت قواعد التفكيك بنفسها — هتفترق عن الخادم');

/* ⚠️⚠️ **الفحص ده اتكتب بعد باج حقيقي وقع مرتين** (v72 «نوع النثرية» ·
   v109 «قايمة الأسعار»): `RepPortalController::postedData()` **قايمة بيضا** —
   أي حقل في الفورم مش مذكور فيها بالاسم بيتقصّ **قبل** ما الموديل يشوفه،
   فالمندوب يكتب القيمة صح والموديل يرفض بـ«الحقل ده فاضي». مفيش خطأ ولا
   تحذير — الحلقة بتتقطع في النص بالسكوت.

   الفحص بيقرا أسماء الحقول من الماركب فعلاً ويقارنها بالقايمة. */
$posted = strstr(code_only('app/Controllers/RepPortalController.php'), 'function postedData(') ?: '';
$posted = substr($posted, 0, 2000);
/* ⚠️ **حقول الفورم بس** — `\sname="..."` لوحده بيلقط `<meta name="viewport">`
   ويدّي فشلًا كاذبًا. بنقرا الوسوم الأول وبعدين اسم الحقل من جوّاها. */
$formFields = [];
if (preg_match_all('/<(?:input|select|textarea)\b[^>]*>/i', src('app/Views/m/entry.php'), $tags)) {
    foreach ($tags[0] as $tag) {
        if (preg_match('/\sname="([a-z_]+)"/', $tag, $nm)) { $formFields[] = $nm[1]; }
    }
    $formFields = array_values(array_unique($formFields));
}
/* حقول بتتولّى في مكان تاني عن قصد: `_csrf` حارس، و`photo`/`remove_photo`
   بيتقرا من `$_FILES` مش من `$_POST`، و`entry_date` الخادم بيفرضه (v49). */
$exempt  = ['_csrf', 'photo', 'remove_photo', 'entry_date'];
$dropped = [];
foreach ($formFields as $f) {
    if (in_array($f, $exempt, true)) { continue; }
    if (!str_contains($posted, "'" . $f . "'")) { $dropped[] = $f; }
}
$formFields
    ? ($dropped
        ? bad('حقول بيبعتها فورم المندوب و`postedData` بتقصّها: ' . implode('، ', $dropped))
        : ok('كل حقول فورم المندوب (' . count($formFields) . ') بتوصل للموديل'))
    : bad('ما قدرتش أقرا حقول فورم المندوب — الفحص ده اتعطّل');

// ═══════════════════════════════════════════════════════════ ②④⑤⑥
sec('②④⑤⑥ سلوك حقيقي: إرسال ← اعتماد ← توزيع ← تعديل');

$permit = Database::row(
    "SELECT p.id, m.id AS mv
       FROM permits p
       JOIN permit_movements m ON m.permit_id = p.id
      WHERE p.id IN (SELECT permit_id FROM bawalis WHERE permit_id IS NOT NULL AND is_legacy = 0)
        AND p.status = 'open'
      ORDER BY p.id DESC LIMIT 1");
$rep = Database::row('SELECT id, name FROM reps ORDER BY id LIMIT 1');

if (!$permit || !$rep) {
    echo "  · مفيش إذن مفتوح له بوالص (أو مفيش مناديب) — فحص السلوك اتخطّى\n";
} else {
    $pid = (int) $permit['id'];
    $safeBefore = round((float) Closing::currentBalance(), 2);
    $compBefore = round((float) CompanySafe::balance(), 2);

    Database::conn()->beginTransaction();
    try {
        $subId = RepPortal::submit((int) $rep['id'], [
            'permit_id' => $pid, 'movement_id' => (int) $permit['mv'],
            'kind' => 'receipt', 'note' => 'إيصال هيئة',
            'amounts' => '250 250 400 180', 'entry_date' => date('Y-m-d'),
        ]);
        $sub = Database::row('SELECT * FROM rep_submissions WHERE id=?', [$subId]);
        near('الإجمالي = مجموع الأسعار', (float) $sub['amount'], 1080);
        (int) $sub['qty'] === 4 ? ok('العدد = عدد الأسعار المكتوبة') : bad('العدد غلط');
        ReceiptLine::decode($sub['lines_json']) === [250.0, 250.0, 400.0, 180.0]
            ? ok('الأسعار محفوظة بالحرف في صندوق الانتظار') : bad('lines_json مش مطابق');

        $stageId = RepInbox::approve($subId);
        $lines = ReceiptLine::forStage($stageId);
        count($lines) === 4 ? ok('الاعتماد ولّد سطرًا لكل إيصال') : bad('عدد السطور غلط');
        array_map(static fn ($l) => (float) $l['amount'], $lines) === [250.0, 250.0, 400.0, 180.0]
            ? ok('كل إيصال بسعره هو (مش بالمتوسط)') : bad('الأسعار اتغيّرت في الاعتماد');

        $stage = Database::row('SELECT amount, qty FROM permit_stages WHERE id=?', [$stageId]);
        near('② مجموع السطور = إجمالي البند',
            array_sum(array_map(static fn ($l) => (float) $l['amount'], $lines)), (float) $stage['amount']);

        // ② الحُرّاس: مجموع غلط · عدد غلط
        try { ReceiptLine::sync($stageId, [1.0, 2.0, 3.0, 4.0]); bad('② مجموع مخالف اتقبل'); }
        catch (\RuntimeException) { ok('② القايمة اللي مجموعها ≠ الإجمالي بتترفض'); }
        try { ReceiptLine::sync($stageId, [1080.0]); bad('② عدد مخالف اتقبل'); }
        catch (\RuntimeException) { ok('② القايمة اللي عددها ≠ عدد الإيصالات بتترفض'); }

        // ④ التوزيع + بقاء الربط بعد إعادة الكتابة
        /* ⚠️ **القياس على الفرق قبل/بعد التوزيع وحده** — مش على فرق البند
           المعتمد. الاعتماد **بيخصم فعلاً** (١٬٠٨٠ من عهدة المندوب ومن
           الخزنة)، فمقارنة الرصيد بأول الفحص كانت هتقيس الاعتماد لا التوزيع
           وتنجح أو تفشل لأسباب مالهاش علاقة بالوحدة دي. */
        $beforeDist = round((float) Closing::currentBalance(), 2);

        $cars = ReceiptLine::carsOfPermit($pid);
        $map = [];
        foreach ($lines as $i => $l) { $map[(int) $l['id']] = (int) $cars[$i % count($cars)]['id']; }
        ReceiptLine::assignMany($stageId, $map) === 4
            ? ok('④ التوزيع اتسجّل لكل سطر') : bad('④ التوزيع ما اكتملش');

        near('① التوزيع نفسه ما غيّرش ولا مليم', Closing::currentBalance(), $beforeDist);

        ReceiptLine::sync($stageId, ReceiptLine::amountsOf($stageId));
        count(array_filter(ReceiptLine::forStage($stageId), static fn ($l) => $l['bawali_id'] !== null)) === 4
            ? ok('④ ربط العربيات عاش بعد إعادة كتابة الأسعار') : bad('④ الربط ضاع مع إعادة الكتابة');

        // ⑤ الحُرّاس: عربية من إذن تاني · سطر مش من البند
        $other = Database::scalar(
            'SELECT id FROM bawalis WHERE permit_id IS NOT NULL AND permit_id <> ? LIMIT 1', [$pid]);
        if ($other) {
            $anyLine = (int) ReceiptLine::forStage($stageId)[0]['id'];
            try { ReceiptLine::assign($anyLine, (int) $other); bad('⑤ عربية من إذن تاني اتقبلت'); }
            catch (\RuntimeException) { ok('⑤ عربية من إذن تاني بتترفض'); }
        }
        $foreign = (int) Database::scalar(
            'SELECT id FROM receipt_lines WHERE stage_id <> ? LIMIT 1', [$stageId]);
        ReceiptLine::assignMany($stageId, [($foreign ?: 999999) => (int) $cars[0]['id']]) === 0
            ? ok('⑤ سطر من برّه البند بيتتجاهل') : bad('⑤ سطر غريب اتقبل');

        // ⑥ المصالِح — تغيير الإجمالي من مسار تاني
        Database::exec('UPDATE permit_stages SET amount = amount + 120 WHERE id=?', [$stageId]);
        ReceiptLine::onStageChanged($stageId);
        near('⑥ التفصيل اتصالح مع الإجمالي الجديد',
            array_sum(ReceiptLine::amountsOf($stageId)), 1200);

        /* ⑤ CASCADE — **فكّ الربط قبل الحذف زي المسار الحقيقي**.
           `rep_submissions.stage_id` عليه FK بـ`ON DELETE RESTRICT`، فحذف
           البند المعتمد مباشرةً بيترفض من القاعدة (وده مقصود — `RepInbox`
           بيفكّ الربط الأول). الفحص بيقلّد نفس الترتيب عشان يقيس الـCASCADE
           بتاع السطور لا حارس المراجعة. */
        Database::exec('UPDATE rep_submissions SET stage_id = NULL WHERE stage_id = ?', [$stageId]);
        Database::exec('DELETE FROM permit_stages WHERE id=?', [$stageId]);
        (int) Database::scalar('SELECT COUNT(*) FROM receipt_lines WHERE stage_id=?', [$stageId]) === 0
            ? ok('⑤ حذف البند شال سطوره') : bad('⑤ فضلت سطور يتيمة بعد حذف البند');
    } finally {
        Database::conn()->rollBack();
    }

    near('① الرجوع رجّع رصيد الدرج بالمليم', Closing::currentBalance(), $safeBefore);
    near('① خزنة الشركة ما اتلمستش', CompanySafe::balance(), $compBefore);
}

// ═══════════════════════════════════════════════════════════ ⑧
sec('⑧ الأسعار المختلفة ما بتتعرضش كـ«كمية × سعر»');

ReceiptLine::summary([180, 180, 250]) === '2×180.00 · 1×250.00'
    ? ok('الملخّص بيجمّع المتكرر بترتيب التكرار') : bad('الملخّص مش مظبوط');
ReceiptLine::varied([250, 400]) === true && ReceiptLine::varied([250, 250]) === false
    ? ok('`varied()` بتفرّق بين المختلف والمتكرر') : bad('`varied()` غلط');
ReceiptLine::formatCompact([250, 250, 250, 180]) === '250×3 180'
    ? ok('الخانة بتلمّ التكرار في `250×3`') : bad('`formatCompact` مش مظبوط');
ReceiptLine::parse(ReceiptLine::formatCompact([250, 250, 250, 180])) === [250.0, 250.0, 250.0, 180.0]
    ? ok('المكتوب المختصر بيرجع لنفس الأسعار (رحلة كاملة)') : bad('الاختصار مش بيرجع زي ما هو');

$inbox = code_only('app/Models/RepInbox.php');
str_contains($inbox, 'qtyLabel')
    ? ok('سجل التدقيق بيمرّ على `qtyLabel` (مش بيضرب في المتوسط)')
    : bad('سجل التدقيق لسه بيطبع «كمية × سعر وحدة»');

// ═══════════════════════════════════════════════════════════ ⑦
sec('⑦ كل كلاس مطبوع له CSS، وكل أيقونة موجودة');

/* ⚠️ لوحة الصندوق مبنية على أصناف `ib-*` **معرَّفة في `<style>` محلي جوّه
   `rep_custody/inbox.php`** مش في `app.css`. لو حد نقل البارشيال لصفحة تانية
   من غير ما ينقل التنسيق، هيطلع بلا أي شكل **بالسكوت**. */
$inboxView = src('app/Views/rep_custody/inbox.php');
$panel     = src('app/Views/partials/receipt_distribute.php');
$missing = [];
foreach (['ib-panel', 'ib-edit-box', 'ib-edit-sec', 'ib-grid', 'ib-panel-acts'] as $cls) {
    if (str_contains($panel, $cls) && !str_contains($inboxView, '.' . $cls)) { $missing[] = $cls; }
}
$missing ? bad('أصناف بلا CSS في لوحة الصندوق: ' . implode(', ', $missing))
         : ok('كل أصناف لوحة الصندوق معرَّفة في نفس الشاشة');

/* نفس القاعدة لصفحة الإذن — أصنافها في `app.css`. */
$css  = src('public/assets/css/app.css');
$page = src('app/Views/permits/_receipt_cars.php');
$missing2 = [];
foreach (['tl-add', 'tl-form', 'tl-fields', 'tl-form-actions', 'tl-none'] as $cls) {
    if (str_contains($page, $cls) && !str_contains($css, '.' . $cls)) { $missing2[] = $cls; }
}
$missing2 ? bad('أصناف بلا CSS في صفحة الإذن: ' . implode(', ', $missing2))
          : ok('كل أصناف توزيع صفحة الإذن معرَّفة في `app.css`');

/* ⚠️ `icon('اسم-غلط')` ما بترميش خطأ — بترسم دايرة فاضية. */
$icons = [];
foreach ([$panel, $page] as $blob) {
    if (preg_match_all("/icon\('([a-z0-9\-]+)'/", $blob, $m)) {
        foreach ($m[1] as $n) { $icons[$n] = true; }
    }
}
$helpers = src('app/Core/helpers.php');
$badIcons = [];
foreach (array_keys($icons) as $n) {
    if (!str_contains($helpers, "'" . $n . "'")) { $badIcons[] = $n; }
}
$badIcons ? bad('أيقونات مش موجودة (هترسم دايرة فاضية): ' . implode(', ', $badIcons))
          : ok('كل الأيقونات المستخدَمة موجودة (' . count($icons) . ')');

/* ⚠️ الجافاسكريبت اللي بيشغّل «وزّع بالترتيب» في صفحة الإذن لازم يكون في
   `app.js`، و`/m` مالهاش دعوة بيه (بوابة المندوب ما بتحمّلش app.js أصلاً). */
str_contains(src('public/assets/js/app.js'), 'data-rd-seq-local')
    ? ok('«وزّع بالترتيب» بتاع صفحة الإذن في `app.js`')
    : bad('زرار صفحة الإذن مالوش سلوك — `app.js` مش فيه المعالج');

// ═══════════════════════════════════════════════════════════ المسارات
sec('المسارات والصلاحيات');

$routes = src('public/index.php');
$posDist = strpos($routes, "'/permits/stage/{sid}/distribute'");
$posDel  = strpos($routes, "'/permits/stage/{sid}/delete'");
($posDist !== false && $posDel !== false)
    ? ok('مسارا التوزيع (الإذن والصندوق) مسجَّلين') : bad('مسار التوزيع ناقص');
str_contains($routes, "'/rep-custody/{sid}/distribute'")
    ? ok('مسار توزيع صندوق المراجعة مسجَّل') : bad('مسار صندوق المراجعة ناقص');

/* ⚠️ الحارسان اليدويان إجباريان — مفيش middleware في المشروع (تحذير ٥). */
$rc = code_only('app/Controllers/RepCustodyController.php');
$pc = code_only('app/Controllers/PermitController.php');
$distRc = strstr($rc, 'function distribute(') ?: '';
$distPc = strstr($pc, 'function distributeStage(') ?: '';
(str_contains(substr($distRc, 0, 400), 'requireAuth') && str_contains(substr($distRc, 0, 400), 'requireCsrf'))
    ? ok('`distribute` فيه requireAuth + requireCsrf') : bad('`distribute` ناقصه حارس');
(str_contains(substr($distPc, 0, 400), 'requireAuth') && str_contains(substr($distPc, 0, 400), 'requireCsrf'))
    ? ok('`distributeStage` فيه requireAuth + requireCsrf') : bad('`distributeStage` ناقصه حارس');

/* ⚠️ الإعداد لازم يكون في السجل — المفتاح غير المعرَّف بيفشّل
   `verify_settings_registry` أصلاً، والفحص ده بيقول السبب بوضوح. */
str_contains(src('app/Core/SettingsSchema.php'), "'receipt_car_kinds'")
    ? ok('`receipt_car_kinds` معرَّف في سجل الإعدادات') : bad('الإعداد مش في السجل');
ReceiptLine::needsCar('إيصال هيئة') && ReceiptLine::needsCar('2 ايصال هييه')
    && !ReceiptLine::needsCar('تخصيص')
    ? ok('`needsCar` بتعدّي على `ReceiptKind::of` (بتمسك كل تهجئات الهيئة)')
    : bad('`needsCar` مش بتوحّد التهجئة');

echo "\n" . str_repeat('=', 64) . "\n";
printf("ناجح: %d   |   فاشل: %d\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
