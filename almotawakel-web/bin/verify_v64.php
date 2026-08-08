<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص ملفات الإذن + أزمنة الطرق (v64).
 *
 * القواعد المحروسة:
 *   ① **العزل بالبناء** — ولا عمود مالي، وولا ملف خزنة بيعرف الجدولين.
 *   ② **الحُرّاس في الموديل** — نوع الملف وحجمه، والمندوب يشيل بتاعه بس.
 *   ③ **الوقت تقدير مش وعد** (قاعدة v34) — الوجهة المجهولة بترجّع null.
 *   ④ **قاعدة v35** — ولا رقم بيخصّ العميل في أي حاجة بتوصل شاشة المندوب.
 *   ⑤ **المسارات الحرفية قبل الجشعة** (تحذير ٤).
 *
 * كله جوّه transaction وبيعمل rollback.
 * التشغيل:  php bin/verify_v64.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Closing;
use App\Models\PermitFile;
use App\Models\RepCustody;
use App\Models\RouteTime;

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function sec(string $m): void { echo "\n" . $m . "\n" . str_repeat('─', 62) . "\n"; }
function near(string $l, $a, $e): void {
    abs((float) $a - (float) $e) <= 0.005 ? ok($l)
        : bad("{$l} — متوقع " . number_format((float) $e, 2) . " / فعلي " . number_format((float) $a, 2));
}
function src(string $f): string {
    $p = dirname(__DIR__) . '/' . $f;
    return is_file($p) ? (string) file_get_contents($p) : '';
}
function code_only(string $f): string {
    $p = dirname(__DIR__) . '/' . $f;
    if (!is_file($p)) { return ''; }
    $out = '';
    foreach (token_get_all((string) file_get_contents($p)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

echo "فحص ملفات الإذن وأزمنة الطرق (v64)\n" . str_repeat('=', 62) . "\n";

if (!PermitFile::ready() || !RouteTime::ready()) {
    echo "\n⚠️ جداول v64 مش موجودة — شغّل php bin/migrate_v64.php الأول.\n";
    exit(1);
}

$treasuryBefore = Closing::currentBalance();
$repsBefore     = RepCustody::available() ? RepCustody::totalOutstanding() : 0.0;

// ══════════════════════════════════════════ ① العزل بالبناء
sec('① العزل بالبناء');

$safeFiles = [
    'app/Models/Closing.php', 'app/Models/Custody.php', 'app/Models/DailyReview.php',
    'app/Models/Expense.php', 'app/Models/Metrics.php', 'app/Models/RepCustody.php',
    'app/Controllers/DailyClosingController.php', 'bin/backfill_closings.php', 'app/Calc.php',
];
foreach ($safeFiles as $f) {
    $c = code_only($f);
    (!str_contains($c, 'permit_files') && !str_contains($c, 'PermitFile')
     && !str_contains($c, 'route_times') && !str_contains($c, 'RouteTime'))
        ? ok(basename($f) . ' عمره ما سمع عن الوحدة')
        : bad(basename($f) . ' بيذكر الوحدة — العزل اتكسر');
}
foreach (['permit_files', 'route_times'] as $t) {
    $cols = array_column(Database::all(
        "SELECT column_name cn FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ?", [$t]), 'cn');
    $money = array_values(array_filter($cols, function ($c) {
        foreach (['amount','custody','freight','debt','paid','price','total','balance','visa'] as $b) {
            if (str_contains((string) $c, $b)) { return true; }
        }
        return false;
    }));
    !$money ? ok("ولا عمود مالي في {$t}") : bad("أعمدة مالية في {$t}: " . implode(', ', $money));
}

// ══════════════════════════════════════════ ② وقت الوصول
sec('② وقت الوصول: تقدير من مسافة طريق حقيقية');

/* ⚠️ الطرق المبذورة لازم تدّي `exact` — لو رجعت `approx` يبقى البذر ما اشتغلش
   أو مفتاح التطبيع اتغيّر، والتقدير هيبقى بالخط المستقيم (أقل دقّة بكتير). */
$routes = [
    ['ميناء الإسكندرية', 'مدينة بدر'],
    ['ميناء الإسكندرية', 'بني سويف'],
    ['ميناء الإسكندرية', 'العاشر من رمضان'],
    ['ميناء الإسكندرية', '6 أكتوبر'],
];
$notExact = [];
foreach ($routes as [$p, $d]) {
    $e = RouteTime::estimate($p, $d);
    if (!$e || $e['source'] !== 'exact') { $notExact[] = $d; }
}
!$notExact ? ok('★ الطرق المعروفة بتدّي مسافة طريق حقيقية')
           : bad('رجعت تقديرًا بالخط المستقيم لـ: ' . implode(' · ', $notExact));

/* ⚠️ الدخيلة وأبوقير بيتجمّعوا على مفتاح الإسكندرية (فرق ٢٠ كم على ٢٥٠). */
$a = RouteTime::estimate('ميناء الدخيلة', 'مدينة بدر');
($a && $a['source'] === 'exact')
    ? ok('وموانئ نفس المنطقة بتشارك الطريق (الدخيلة = الإسكندرية)')
    : bad('ميناء الدخيلة مالوش طريق');

/* ⚠️⚠️ **تقدير مش وعد** (قاعدة v34): الوجهة المجهولة **بترجّع null** —
   الشاشة بتخفي القسم بدل ما تعرض رقمًا مخترعًا. */
$unknown = ['حاجة مش موجودة خالص', '', 'zzz'];
$leaked = array_values(array_filter($unknown,
    fn($u) => RouteTime::estimate('ميناء الإسكندرية', $u) !== null));
!$leaked ? ok('★ والوجهة المجهولة بترجّع null (مفيش رقم مخترع)')
         : bad('اتخترع تقدير لوجهة مجهولة: ' . implode(' · ', $leaked));

/* الوقت لازم يكون معقولًا لشاحنة محمّلة: ٢٥٠ كم ما ينفعش ساعتين. */
$bdr = RouteTime::estimate('ميناء الإسكندرية', 'مدينة بدر');
($bdr && $bdr['minutes'] >= 300 && $bdr['minutes'] <= 480)
    ? ok('★ و٢٥٠ كم بتدّي ' . round($bdr['minutes'] / 60, 1) . ' ساعة (سرعة شاحنة لا سيارة)')
    : bad('الوقت غير معقول لشاحنة: ' . ($bdr['minutes'] ?? 0) . ' دقيقة');
str_contains(RouteTime::humanDuration(345), 'حوالي')
    ? ok('والصيغة تقريبية («حوالي…») مش وقت مضبوط') : bad('الصيغة بتوحي بدقّة مش موجودة');

$model = code_only('app/Models/RouteTime.php');
str_contains($model, 'Track::resolveDestination')
    ? ok('★ والتقدير الاحتياطي بيستخدم مُحلِّل الأماكن القايم (v34) لا نسخة تانية')
    : bad('مُحلِّل أماكن موازٍ — هيفترق عن v34');

$pdo = Database::conn();
$pdo->beginTransaction();

try {
    // ══════════════════════════════════════ ③ ملفات الإذن
    sec('③ ملفات الإذن: الحُرّاس في الموديل');

    Database::exec(
        "INSERT INTO permits (permit_no, permit_type, permit_date, status, company)
         VALUES ('__pf_test', 'sader', '2037-12-01', 'open', '__pf_co')");
    $pid = Database::lastId();

    $reject = function (callable $fn, string $why) {
        try { $fn(); bad("اتقبل واللازم يترفض: {$why}"); }
        catch (\RuntimeException $e) { ok("اترفض: {$why}"); }
    };
    $reject(fn() => PermitFile::add(99999999, ['error' => UPLOAD_ERR_OK], ''), 'إذن مش موجود');
    $reject(fn() => PermitFile::add($pid, ['error' => UPLOAD_ERR_NO_FILE], ''), 'بلا ملف');

    // صف حقيقي بمسار وهمي — بنختبر القراءة والحذف لا التخزين
    Database::exec(
        "INSERT INTO permit_files (permit_id, file_path, orig_name, mime, size_bytes, title, rep_id)
         VALUES (?, 'private/permits/__t.jpg', 't.jpg', 'image/jpeg', 100, 'ورقة', ?)",
        [$pid, 7]);
    $fid = Database::lastId();

    count(PermitFile::forPermit($pid)) === 1 ? ok('الورقة بتترجع للإذن') : bad('القراءة غلط');
    PermitFile::isImage(['mime' => 'image/jpeg']) ? ok('والصورة بتتعرف') : bad('كشف الصورة غلط');
    (PermitFile::countsFor([$pid])[$pid] ?? 0) === 1
        ? ok('والعدّاد المجمَّع صح') : bad('countsFor غلط');

    /* ⚠️ المندوب يشيل **بتاعه هو بس** — الحارس في الموديل لا في الشاشة. */
    PermitFile::remove($fid, 999) === false
        ? ok('★ ومندوب تاني ما يقدرش يشيل ورقة مش بتاعته') : bad('اتشالت ورقة مندوب تاني');
    PermitFile::find($fid) !== null ? ok('والورقة لسه مكانها') : bad('اتشالت رغم الرفض');
    PermitFile::remove($fid, 7) === true ? ok('وصاحبها بيشيلها') : bad('صاحب الورقة ما قدرش يشيلها');
    PermitFile::find($fid) === null ? ok('واتشالت فعلاً') : bad('فضلت بعد الحذف');

    // ══════════════════════════════════════ ④ العزل المالي
    sec('④ ★ العزل المالي — ولا مليم اتحرّك');
    near('رصيد خزنة المكتب زي ما هو', Closing::currentBalance(), $treasuryBefore);
    if (RepCustody::available()) {
        near('وإجمالي عهد المناديب زي ما هو', RepCustody::totalOutstanding(), $repsBefore);
    }

} catch (\Throwable $e) {
    bad('استثناء غير متوقّع: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}

// ══════════════════════════════════════════ ⑤ المسارات والعرض
sec('⑤ المسارات والعرض');

$routesSrc = src('public/index.php');
foreach ([
    "'/m/permit/{id}/file'"        => 'مسار رفع الورقة',
    "'/m/permit-file/{id}/delete'" => 'ومسار حذفها',
    "'/file/permit-file/{id}'"     => 'ومسار تقديمها',
] as $needle => $label) {
    str_contains($routesSrc, $needle) ? ok($label) : bad($label . ' ناقص');
}
/* ⚠️ تحذير ٤: `/m/permit/{id}/file` حرفي قبل الجشع `/m/permit/{id}`. */
$aFile = strpos($routesSrc, "'/m/permit/{id}/file'");
$aGrd  = strpos($routesSrc, "'/m/permit/{id}',");
($aFile !== false && $aGrd !== false && $aFile < $aGrd)
    ? ok('★ والحرفي قبل الجشع') : bad('ترتيب المسارات غلط');

/* ⚠️ تقديم الملف لازم يرفض الزائر بلا جلسة. */
$fc = code_only('app/Controllers/FileController.php');
$fn = preg_match('~function permitFile\(.*?\n    \}~s', $fc, $m) ? $m[0] : '';
(str_contains($fn, '403') && str_contains($fn, 'RepAuth::check'))
    ? ok('★ وتقديم الورقة بيرفض اللي بلا جلسة') : bad('الورقة مفتوحة لأي حد');

$info = src('app/Views/m/permit_info.php');
str_contains($info, 'pi-eta') ? ok('قسم الوصول المتوقّع في الشاشة') : bad('قسم الوصول ناقص');
str_contains($info, '$eta !== null')
    ? ok('★ وبيختفي لو التقدير مش متاح (مفيش رقم مخترع في الشاشة)')
    : bad('القسم بيترسم حتى بلا تقدير');
str_contains($info, 'accept="image/*,application/pdf"')
    ? ok('وفورم الرفع بيقلّل غلط اختيار الملف') : bad('accept ناقصة من الرفع');
str_contains($info, 'capture="environment"')
    ? ok('وبيفتح الكاميرا على الموبايل') : bad('capture ناقصة');

/* ⚠️ **خانتا ملف مش واحدة** (طلب المالك: تصوير أو رفع ملف): الخانة اللي عليها
   `capture` بتفتح الكاميرا على طول وبتمنع اختيار ملف موجود، وبدونها العكس. */
substr_count($info, 'data-pf-pick') >= 2
    ? ok('★ وفيه خيارا «صوّر» و«ارفع ملف» منفصلين') : bad('خيار رفع واحد بس');
/* ⚠️ الاتنين اسمهم `doc` — لازم الجافاسكريبت يفضّي التانية وإلا المتصفح بيبعت
   الأخيرة ويلغبط اللي المندوب شافه في المعاينة. */
str_contains($info, 'if (o !== input) { o.value')
    ? ok('★ والخانة التانية بتتفضّى (وإلا بيترفع ملف غير اللي في المعاينة)')
    : bad('الخانتان ممكن يبعتوا مع بعض');
(str_contains($info, 'createObjectURL') && str_contains($info, 'revokeObjectURL'))
    ? ok('والمعاينة بتتعمل وبتتفضّى من الذاكرة') : bad('معاينة بلا تحرير ذاكرة');
str_contains($info, 'go.disabled = true')
    ? ok('وزرار الرفع بيتقفل بعد الضغط (منع رفع مكرّر)') : bad('ممكن يترفع مرتين');

/* ⚠️⚠️ الصفحة كانت **مش بتعرض أي رسالة** — الرفع بيتم والمندوب ما يعرفش
   نجح ولا فشل (الـflash بيتسجّل في الجلسة ويضيع). */
(str_contains($info, "flash('success')") && str_contains($info, "flash('error')"))
    ? ok('★ ورسالة النجاح/الفشل بتتعرض في الصفحة') : bad('رسالة الرفع بتضيع');

/* ⚠️ **تحسين تدريجي**: الإخفاء من الجافاسكريبت لا من الخادم — لو السكربت وقع
   المندوب لسه بيشوف الفورم كامل ويقدر يرفع. */
str_contains($info, 'stage.hidden = true')
    ? ok('★ وإخفاء الخطوة التانية من الجافاسكريبت (بلا سكربت الفورم شغّال)')
    : bad('الخطوة التانية مخفية من الخادم — بلا سكربت مفيش رفع');

/* الإجراءان الأساسيان في الهيرو (طلب المالك). */
str_contains($info, 'class="pi-hero-acts"')
    ? ok('★ و«سجّل بند» و«حالة الرحلة» في الهيرو') : bad('الإجراءان لسه في آخر الصفحة');

/* ⚠️ قاعدة v35: ولا سعر عميل في أي حاجة بتوصل الشاشة. */
$pf = code_only('app/Models/PermitFile.php');
(!preg_match('~actual_amount|actual_unit~', $pf . $model))
    ? ok('★ وولا سعر عميل في موديلات v64') : bad('سعر العميل في موديل v64');

/* ⚠️⚠️ **باج متحقَّق**: الخانتان كانوا بنفس الاسم `doc`، فالمتصفح بيبعت
   **الاتنين** — الفاضية والمليانة — والفاضية بتغلب لأنها الأخيرة، فالرفع
   بيترفض بـ«اختار الملف الأول» رغم إن المندوب اختار. */
(str_contains($info, 'name="doc_cam"') && str_contains($info, 'name="doc_file"'))
    ? ok('★ وخانتا الرفع بأسماء مختلفة (كانوا بنفس الاسم فالفاضية بتغلب)')
    : bad('الخانتان بنفس الاسم — الرفع هيترفض رغم الاختيار');
str_contains($info, 'o.disabled = true')
    ? ok('والخانة غير المستعملة بتتعطّل فما بتتبعتش خالص') : bad('الخانة الفاضية لسه بتتبعت');
$pfm = code_only('app/Models/PermitFile.php');
str_contains($pfm, 'function pickUpload')
    ? ok('★ والخادم بياخد أول خانة فيها ملف (شغّال حتى بلا جافاسكريبت)')
    : bad('الخادم بيقرا خانة واحدة بالاسم');
$picked = \App\Models\PermitFile::pickUpload(
    ['doc_cam' => ['error' => UPLOAD_ERR_NO_FILE], 'doc_file' => ['error' => UPLOAD_ERR_OK, 'name' => 'x']]);
((int) ($picked['error'] ?? 9) === UPLOAD_ERR_OK)
    ? ok('★ ومتحقَّق سلوكيًّا: الفاضية ما بتغلبش المليانة') : bad('الفاضية لسه بتغلب');
\App\Models\PermitFile::pickUpload(['doc_cam' => ['error' => 4], 'doc_file' => ['error' => 4]])['error']
    === UPLOAD_ERR_NO_FILE
    ? ok('والاتنين فاضيين بيرجّعوا «مفيش ملف»') : bad('رجّع ملف من العدم');

/* بوب-أب التأكيد بعد حفظ البند (طلب المالك). */
$entryView = src('app/Views/m/entry.php');
$ctl2 = code_only('app/Controllers/RepPortalController.php');
str_contains($entryView, 'id="mDone"')
    ? ok('★ وبوب-أب «اتسجّل ✓» بعد حفظ البند') : bad('مفيش بوب-أب تأكيد');

/* ⚠️⚠️ **باج متحقَّق بالقياس**: `*{margin:0}` في الـreset بيمسح `margin:auto`
   بتاع المتصفح اللي بيوسّط النافذة المودالية، فبتلزق في ركن الشاشة (أعلى
   اليمين في RTL — مقيس: مزاحة ٨٠px أفقي و١٩٤px رأسي). القاعدة على `dialog`
   عشان أي نافذة جديدة في البوابة تاخد التوسيط تلقائيًّا. */
$dlg = preg_match('~(^|
)dialog\{[^}]*\}~s', preg_replace('~/\*.*?\*/~s', '', $mcssD ?? src('public/assets/css/m.css')), $dm)
    ? $dm[0] : '';
(str_contains($dlg, 'margin:auto') && str_contains($dlg, 'position:fixed'))
    ? ok('★ وتوسيط النوافذ مرجَّع صراحةً (الـreset بيمسح margin:auto)')
    : bad('النافذة هتلزق في ركن الشاشة — التوسيط ناقص');
(str_contains($entryView, 'سجّل بند تاني') && str_contains($entryView, 'رجوع للأذونات'))
    ? ok('وبيخيّر بين بند تاني والرجوع للأذونات') : bad('الاختيارات ناقصة');
/* ⚠️ one-shot: لازم تتمسح وقت القراءة وإلا بيفتح كل مرة. */
(str_contains($ctl2, "\$_SESSION['_m_saved'] = \$permitId")
 && str_contains($ctl2, "unset(\$_SESSION['_m_saved'])"))
    ? ok('★ والعلامة one-shot (بتتمسح وقت القراءة)') : bad('البوب-أب هيفتح كل مرة');
/* ⚠️ المسارين (AJAX والعادي) لازم يروحوا نفس المكان وإلا البوب-أب يظهر في
   حالة ويختفي في التانية. */
(substr_count($ctl2, "url('m/permit/' . \$permitId)") >= 1
 && str_contains($ctl2, "redirect('m/permit/' . \$permitId)"))
    ? ok('ومسارا الحفظ بيروحوا نفس الفورم') : bad('مسارا الحفظ مختلفين — البوب-أب هيتلخبط');

/* «بوليصة جديدة» اتنقلت جوّه الإذن. */
$homeV = src('app/Views/m/home.php');
(!str_contains($homeV, "url('m/bill/new')"))
    ? ok('★ و«بوليصة جديدة» اتشالت من الرئيسية') : bad('لسه في الإجراءات السريعة');
str_contains($info, "m/bill/new?permit=")
    ? ok('وبقت جوّه الإذن بالإذن مختار مقدّمًا') : bad('مش موجودة في صفحة الإذن');
/* ⚠️ الرابط مش مصدر ثقة — الإذن بيتقبل بس لو في قايمة المندوب. */
str_contains($ctl2, "(int) \$p['id'] === \$wanted")
    ? ok('★ والإذن من الرابط بيتفحص إنه في قايمة المندوب') : bad('الرابط بيتقبل بلا فحص');

$mcss = src('public/assets/css/m.css');
foreach (['pi-eta', 'pi-files', 'pi-file', 'pi-upload', 'pi-up-choice', 'pi-up-prev',
          'pi-up-stage', 'pi-up-x', 'pi-up-go', 'pi-truck', 'pi-office-tag',
          'pi-hero-acts', 'm-done'] as $c) {
    str_contains($mcss, '.' . $c) ? ok(".{$c} معرَّف في m.css") : bad(".{$c} بلا CSS");
}
foreach (['camera', 'clock', 'file-text', 'trash', 'star'] as $ic) {
    icon($ic, 14) !== icon('__لا_يوجد__', 14)
        ? ok("أيقونة {$ic} موجودة") : bad("أيقونة {$ic} ناقصة");
}

/* ⚠️ سيارة المكتب بتتحدّد من سجل السيارات لا من نص في البوليصة. */
str_contains($info, 'OfficeCar::matchPlate')
    ? ok('★ وسيارة المكتب بتتحدّد من سجل السيارات') : bad('تمييز سيارة المكتب بنص مكتوب');

echo "\n" . str_repeat('═', 62) . "\n";
near('رصيد الخزنة قبل = بعد', Closing::currentBalance(), $treasuryBefore);
if (RepCustody::available()) {
    near('وإجمالي عهد المناديب قبل = بعد', RepCustody::totalOutstanding(), $repsBefore);
}
echo "\nنجح: $pass | فشل: $fail\n";
exit($fail > 0 ? 1 : 0);
