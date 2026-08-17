<?php
// ═══ حارس: سطر الأوامر فقط ═══
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص — **حاويات كارت المندوب = أصل الإذن لا اللي خرج فعلاً**.
 *
 * تصحيح المالك: «في شاشة المندوب يظهر له في الأذون عدد الحاويات × تصنيف
 * الحاوية وفقًا للسيارات المسجلة على الإذن وهذا خطأ — المفترض يظهر له أصل
 * الإذن وليس ما خرج بالفعل في الكارت».
 *
 * قبل التصحيح كانت الشارة مبنية على `SUM(container_count)` من `bawalis`،
 * فالإذن اللي أصله ١٨×٢٠ ولسه اتسجّلت عليه ٣ حاويات كان بيقول **٣×٢٠ قدم**:
 * الرقم بيكبر تحت إيد المندوب كل ما يسجّل بوليصة، وهو محتاج **حمولة الإذن
 * كلها** عشان يعرف فاضل كام — لا اللي عدّى (ده ظاهر أصلاً في «N بوليصة / عربية»).
 *
 * اللي بيتأكد منه:
 *   ① **السلوك على بيانات حقيقية** — أصل ≠ منفَّذ ⇒ الشارة بتقول الأصل،
 *      مقيسًا على `RepPortal::myPermit()` لا على نص في الشاشة.
 *   ② **الملجأ الأخير** — الإذن القديم اللي أصله بلا عدد بيرجع للبوالص بدل الفراغ.
 *   ③ **مصدر واحد** — `container_parts` و`container_label` مستحيل يختلفوا.
 *   ④ **مفيش قراءة تانية بإيدها** — ولا ملف في بوابة `/m` بيبني شارة حاويات
 *      من `bawalis` بنفسه (نقطة تانية = «الكارت يقول رقم والتفاصيل رقم تاني»).
 *   ⑤ **العزل** — الفحص وكل fixtures جوّه transaction بترجع بالكامل.
 *
 * التشغيل:  php bin/verify_rep_permit_origin.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\RepPortal;

$pass = 0; $fail = 0;
function okO(string $m): void  { global $pass; $pass++; echo "  ✓ $m\n"; }
function badO(string $m): void { global $fail; $fail++; echo "  ✗ $m\n"; }
function chkO(bool $c, string $m, string $why = ''): void {
    $c ? okO($m) : badO($m . ($why !== '' ? " — {$why}" : ''));
}
function secO(string $m): void { echo "\n$m\n" . str_repeat('─', 62) . "\n"; }

/** ⚠️ كود بلا تعليقات — التعليق اللي بيوثّق القاعدة بيذكر النص الممنوع نفسه. */
function codeO(string $file): string {
    $p = dirname(__DIR__) . '/' . $file;
    if (!is_file($p)) { return ''; }
    $out = '';
    foreach (token_get_all((string) file_get_contents($p)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

echo "فحص — حاويات كارت المندوب: أصل الإذن لا المنفَّذ\n" . str_repeat('=', 62) . "\n";

$tag = 'V-ORIGIN-' . substr((string) getmypid(), -5);
$pdo = Database::conn();
$pdo->beginTransaction();
try {
    $day = date('Y-m-d');

    /** إذن مفتوح + حركة + بوالص (عدد حاويات مسجَّل) — بيرجّع حزمة العرض. */
    $build = function (int $containers, string $type, array $billCounts) use ($day, $tag): array {
        Database::exec(
            "INSERT INTO permits (permit_type, permit_no, permit_date, company, client,
                                  status, containers, container_type)
             VALUES ('sader', ?, ?, ?, ?, 'open', ?, ?)",
            [$tag . '-' . Database::scalar('SELECT COALESCE(MAX(id),0)+1 FROM permits'),
             $day, 'شركة ' . $tag, 'عميل ' . $tag, $containers, $type]);
        $pid = (int) Database::lastId();
        Database::exec('INSERT INTO permit_movements (permit_id, seq) VALUES (?,1)', [$pid]);
        $mid = (int) Database::lastId();
        foreach ($billCounts as [$cnt, $ctype]) {
            Database::exec(
                'INSERT INTO bawalis (permit_id, movement_id, bill_date, container_count,
                                      container_type, is_legacy)
                 VALUES (?,?,?,?,?,0)', [$pid, $mid, $day, $cnt, $ctype]);
        }
        $p = RepPortal::myPermit(1, $pid);
        if (!$p) { throw new \RuntimeException('myPermit رجّعت null للإذن ' . $pid); }
        return $p;
    };

    // ═════════════════════════════════════ ① أصل ≠ منفَّذ
    secO('① الأصل بيغلب المنفَّذ');

    /* ⚠️ الحالة دي **مأخوذة من إذن حقيقي**: EGLV598600007808 أصله ١٨×٢٠ قدم. */
    $partial = $build(18, '20 قدم', [[3, '20 قدم']]);
    chkO($partial['container_parts'] === ['18×20 قدم'],
        'أصل ١٨×٢٠ وعليه ٣ حاويات ⇒ الشارة بتقول ١٨ مش ٣',
        'رجّعت [' . implode(' ، ', $partial['container_parts']) . ']');
    chkO($partial['bills'] === 1,
        'اللي خرج فعلاً بيفضل ظاهر في عدّاد البوالص — الخبرين مش بيتعارضوا');

    /* المنفَّذ أكتر من الأصل (بيانات متعدّية) — الشارة برضه بتقول الأصل: الرقم
       ده «حمولة الإذن»، وتضخيمه من البوالص بيخلّي المندوب يفتكر فيه فاضل. */
    $over = $build(2, '40 قدم', [[2, '40 قدم'], [3, '40 قدم']]);
    chkO($over['container_parts'] === ['2×40 قدم'],
        'المنفَّذ عدّى الأصل ⇒ الشارة لسه بتقول الأصل',
        'رجّعت [' . implode(' ، ', $over['container_parts']) . ']');

    /* نوع البوالص مختلف عن نوع الأصل — الشارة ما تخلطش الاتنين. */
    $mixed = $build(4, '20 قدم', [[1, '40 مبرّد']]);
    chkO($mixed['container_parts'] === ['4×20 قدم'],
        'تصنيف البوليصة المختلف ما بيدخلش شارة الأصل',
        'رجّعت [' . implode(' ، ', $mixed['container_parts']) . ']');

    // ═════════════════════════════════════ ② الأصل بلا تصنيف / بلا عدد
    secO('② الأصل الناقص');

    $noType = $build(5, '', [[2, '20 قدم']]);
    chkO($noType['container_parts'] === ['5 حاوية'],
        'أصل بعدد بلا تصنيف ⇒ «٥ حاوية» — العدد صح والتصنيف مش مخترع',
        'رجّعت [' . implode(' ، ', $noType['container_parts']) . ']');

    /* ⚠️ الملجأ الأخير: إذن قديم أصله صفر. البوالص أحسن من شارة فاضية —
       الغياب محدّش بيلاحظه، والرقم الموجود على الأقل بيقول «فيه حاجة». */
    $zero = $build(0, '', [[6, '20 قدم']]);
    chkO($zero['container_parts'] === ['6×20 قدم'],
        'أصل صفر ⇒ بيقع على البوالص بدل الفراغ',
        'رجّعت [' . implode(' ، ', $zero['container_parts']) . ']');

    $blank = $build(0, '', []);
    chkO($blank['container_parts'] === [] && $blank['container_label'] === '',
        'بلا أصل وبلا بوالص ⇒ صمت، مش شارة فاضية');

    /* ⚠️ الصيغة القديمة الخام — لازم تمرّ على `container_norm()` في **الشارة
       والنص** الاتنين، وإلا نفس الإذن بيقول رقمين في نفس البوابة. */
    $raw = $build(3, '1*40', []);
    chkO($raw['container_parts'] === ['3×40 قدم'],
        'التصنيف الخام «1*40» بيتوحّد قبل العرض',
        'رجّعت [' . implode(' ، ', $raw['container_parts']) . ']');

    // ═════════════════════════════════════ ③ مصدر واحد
    secO('③ الشارة والنص من نفس المصدر');

    /* ⚠️ `container_parts` بترسمها الشارة و`container_label` بيستخدمها سطر
       شاشة تسجيل البند — لو اتبنوا من مصدرين، شاشتان في نفس البوابة بيقولوا
       رقمين لنفس الإذن. */
    $same = true;
    foreach ([$partial, $over, $mixed, $noType, $zero, $raw] as $p) {
        $joined = implode('، ', $p['container_parts']);
        if ($joined !== $p['container_label']) {
            badO("الشارة «{$joined}» مخالفة للنص «{$p['container_label']}»");
            $same = false;
        }
    }
    if ($same) { okO('الشارة والنص متطابقان في كل الحالات — مصدر واحد'); }

    $pdo->rollBack();
    okO('كل الـfixtures اترجعت (rollback) — مفيش أي أثر');
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    badO('استثناء أثناء الفحص: ' . $e->getMessage()
        . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

// ═════════════════════════════════════ ④ مفيش قراءة تانية بإيدها
secO('④ نقطة بناء واحدة');

$model = codeO('app/Models/RepPortal.php');
chkO(str_contains($model, "\$p['containers'] > 0"),
    'RepPortal بيسأل على أصل الإذن قبل أي حاجة تانية');

/* ⚠️ أي شاشة في `/m` بتجمع `container_count` من البوالص بنفسها بترجّع
   السلوك القديم من الباب الخلفي — والفرق ما بيبانش غير لما الإذن يبقى
   نص منفَّذ. (شاشة البوليصة بتعرض حاوية **بوليصة واحدة** بعينها، وده مسموح
   لأنه خبر عن البوليصة نفسها لا عن الإذن.) */
foreach (glob(dirname(__DIR__) . '/app/Views/m/*.php') ?: [] as $f) {
    $rel  = 'app/Views/m/' . basename($f);
    $code = codeO($rel);
    if (preg_match('/SUM\s*\(\s*container_count/i', $code)) {
        badO("{$rel} بيجمع container_count بإيده — نقطة بناء تانية لشارة الإذن");
    }
}
okO('ولا شاشة في بوابة المندوب بتجمع الحاويات من البوالص بنفسها');

/* الأذون في الشاشة الرئيسية والتفاصيل لازم يقروا من نفس المفتاح. */
foreach (['app/Views/m/home.php', 'app/Views/m/permit_info.php'] as $v) {
    chkO(str_contains(codeO($v), "container_parts"),
        basename($v) . ' بترسم الشارة من `container_parts` (المفتاح الموحّد)');
}

echo "\n" . str_repeat('=', 62) . "\n";
echo "النتيجة: {$pass} ناجح · {$fail} فاشل\n";
if ($fail > 0) { echo "\n⚠️ الفحص فشل — الكود ده ما يترفعش.\n"; exit(1); }
echo "\n✅ كارت المندوب بيقول أصل الإذن.\n";
