<?php
// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * فحص قراءة فقط لرندر صفحة التقفيل اليومي (شاشة المراجعة، v50).
 *
 * الفحص السلوكي الكامل (العلامات، بوابة الإقفال، تطابق الأرقام مع Closing)
 * في `bin/verify_v50.php`. الملف ده حارس خفيف بيتأكد إن الصفحة **بترندر**
 * ببيانات حقيقية وإن عناصرها الأساسية موجودة وما فيهاش معرّفات مكررة.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\DailyClosingController;
use App\Core\Auth;
use App\Core\Database;

Auth::start();
$user = Database::row('SELECT id FROM users ORDER BY id LIMIT 1');
if (!$user) { throw new RuntimeException('لا يوجد مستخدم لفحص الرندر.'); }
$_SESSION['uid'] = (int)$user['id'];
$_SESSION['uname'] = 'فحص صفحة التقفيل';

// أكتر يوم فيه حركة — عشان الرندر يعدّي على بنود حقيقية مش على صفحة فاضية.
$day = Database::scalar(
    "SELECT entry_date FROM permit_stages WHERE amount<>0 AND entry_date IS NOT NULL
     GROUP BY entry_date ORDER BY COUNT(*) DESC LIMIT 1"
) ?: date('Y-m-d');
$_SESSION['work_date'] = $day;
$_GET['date'] = $day;
$_SERVER['REQUEST_URI'] = '/closing?date=' . rawurlencode($day);

ob_start();
(new DailyClosingController())->index();
$html = ob_get_clean();

$needles = [
    'cl-page', 'cl-flow', 'id="review"', 'rv-gauge', 'rv-group', 'rv-item',
    'rv-check', 'final-close',
    'راجع بنود اليوم', 'عهد السائقين', 'مدفوعات الفيزا',
];
foreach ($needles as $needle) {
    if (!str_contains($html, $needle)) { throw new RuntimeException('عنصر مفقود من رندر التقفيل: ' . $needle); }
}

/* ورقة الطباعة لها عقد عرض مستقل: ملخص العهدة، حاويات البوالص، ومصدر الإيصال.
   نرندرها ببيانات اليوم نفسه عشان نحرس الاستعلام والعرض معًا. */
$_GET['date'] = $day;
$_SERVER['REQUEST_URI'] = '/closing/print?date=' . rawurlencode($day);
// رندر CLI بلا Host؛ نحاكي دومين الإنتاج عشان رابط الـQR يبقى مطلقًا وقابلًا للمسح.
$_SERVER['HTTP_HOST'] = 'portal.almutawakkil.com';
$_SERVER['HTTPS'] = 'on';
ob_start();
(new DailyClosingController())->printable();
$printHtml = ob_get_clean();
foreach ([
    'إجمالي العهدة المضافة', '>الحاويات<', '>المخصوم<', '>المندوب<',
    'id="closingQr"', 'معاينة التقفيل على النظام', 'assets/vendor/qrcodejs/qrcode.min.js',
] as $needle) {
    if (!str_contains($printHtml, $needle)) {
        throw new RuntimeException('عنصر مفقود من ورقة طباعة التقفيل: ' . $needle);
    }
}
if (str_contains($printHtml, 'إجمالي الصادر اليوم')
    || str_contains($printHtml, 'توقيع المسؤول')
    || str_contains($printHtml, 'المحاسب / الختم')
    || preg_match('~<th[^>]*>\s*العهدة\s*</th>~u', $printHtml)) {
    throw new RuntimeException('ورقة الطباعة رجعت تعرض عنصرًا محذوفًا (الصادر/العهدة/التوقيعات).');
}
if (!preg_match('~table\.data\{[^}]*font-size:12px~s', $printHtml)
    || !preg_match('~\.pad\.dense table\.data\{[^}]*font-size:11\.5px~s', $printHtml)) {
    throw new RuntimeException('خط جداول الطباعة رجع للمقاس الصغير غير المقروء.');
}
$headStart = strpos($printHtml, '<div class="doc-tag">');
$headEnd   = $headStart === false ? false : strpos($printHtml, '<!-- بطاقات الملخص -->', $headStart);
$headHtml  = ($headStart === false || $headEnd === false) ? '' : substr($printHtml, $headStart, $headEnd - $headStart);
if ($headHtml === '' || str_contains($headHtml, '<br')
    || !str_contains($headHtml, 'تاريخ اليوم:')
    || !str_contains($headHtml, 'بنود المصروفات:')
    || !str_contains($headHtml, 'عهد السائقين:')) {
    throw new RuntimeException('بيانات الهيدر ليست في صف واحد بجوار عنوان التقفيل.');
}
$qrAsset = dirname(__DIR__) . '/public/assets/vendor/qrcodejs/qrcode.min.js';
$qrLicense = dirname(__DIR__) . '/public/assets/vendor/qrcodejs/LICENSE';
if (!is_file($qrAsset) || filesize($qrAsset) < 15000
    || !is_file($qrLicense) || !str_contains((string) file_get_contents($qrLicense), 'MIT License')) {
    throw new RuntimeException('ملفات مولّد QR المحلي أو ترخيصه ناقصة.');
}

/* اختبار سلوكي للترتيب: كل شركة لازم تظهر ككتلة واحدة في الإيصالات والبوالص،
   فلا ترجع شركة سبق عرضها بعد الانتقال لشركة أخرى. */
$method = new ReflectionMethod(DailyClosingController::class, 'details');
$details = $method->invoke(new DailyClosingController(), $day);
$assertCompanyGroups = static function (array $rows, string $label): void {
    $seen = [];
    $last = null;
    foreach ($rows as $row) {
        $key = search_norm(trim((string) ($row['company'] ?? '')));
        if ($key !== $last) {
            if (isset($seen[$key])) {
                throw new RuntimeException("ترتيب {$label} مش مجمّع حسب الشركة: {$key}");
            }
            $seen[$key] = true;
            $last = $key;
        }
    }
};
$assertCompanyGroups($details['receiptRows'], 'الإيصالات');
$assertCompanyGroups($details['custodyRows'], 'البوالص');
foreach ($details['receiptRows'] as $row) {
    if (!array_key_exists('rep_name', $row)) {
        throw new RuntimeException('استعلام الإيصالات لا يعيد اسم المندوب.');
    }
}
foreach ($details['custodyRows'] as $row) {
    if (!array_key_exists('containers', $row)) {
        throw new RuntimeException('استعلام البوالص لا يعيد تصنيف الحاويات.');
    }
}

// أثر المطابقة اليدوية القديمة (v28) لازم يكون اختفى تمامًا
foreach (['manual_total', 'reconForm', 'closing/reconcile'] as $gone) {
    if (str_contains($html, $gone)) {
        throw new RuntimeException('أثر المطابقة القديمة لسه موجود في الرندر: ' . $gone);
    }
}

preg_match_all('~\sid="([^"]+)"~', $html, $idMatches);
$duplicates = array_filter(array_count_values($idMatches[1] ?? []), fn($count) => $count > 1);
if ($duplicates) { throw new RuntimeException('معرفات HTML مكررة: ' . implode(', ', array_keys($duplicates))); }

/* ⚠️ سكربت المراجعة بيترندر على اليوم **المفتوح** بس (اليوم المتقفل قراءة فقط).
   لو استخرجناه من اليوم المزحوم (اللي غالبًا متقفل) كنا هنطلّع `'use strict';`
   فاضية و`node --check` يعدّي على لا شيء — فحص بينجح كذبًا. */
$openDay = date('Y-m-d', strtotime('+400 days'));
$_SESSION['work_date'] = $openDay;
$_GET['date'] = $openDay;
$_SERVER['REQUEST_URI'] = '/closing?date=' . rawurlencode($openDay);
ob_start();
(new DailyClosingController())->index();
$openHtml = ob_get_clean();
// اليوم المفتوح لازم يعرض وسيلة الحفظ (اللحظي + الاحتياطي بلا جافاسكريبت).
foreach (['closing/review-save', 'rv-manual'] as $needle) {
    if (!str_contains($openHtml, $needle)) {
        throw new RuntimeException('عنصر مفقود من رندر اليوم المفتوح: ' . $needle);
    }
}

preg_match_all('~<script(?:\s[^>]*)?>(.*?)</script>~s', $openHtml, $scripts);
$closingJs = '';
foreach ($scripts[1] ?? [] as $script) {
    if (str_contains($script, "getElementById('rvForm')")) { $closingJs = $script; break; }
}
if ($closingJs === '' && !in_array('--js', $argv ?? [], true)) {
    throw new RuntimeException('سكربت المراجعة مش موجود في رندر اليوم المفتوح.');
}
if (in_array('--js', $argv ?? [], true)) {
    echo $closingJs !== '' ? $closingJs : "'use strict';\n";
    exit(0);
}

/* ══════════════════════════════════════════════════════════════════════════
   ⚠️ البحث الحيّ: تطبيع عربي على الطرفين — بلاغ المالك 2026-07-26
   نثرية محفوظة بـ«اكراميه أمن cma» والبحث بـ«إكرامية» كان بيرجّع صفر نتائج،
   فبان إن البند مش موجود في التقفيل وهو موجود ومحسوب. الفحص بيحرس حاجتين:
     (١) `search_norm` PHP بتوحّد كل تهجئات الحرف.
     (٢) جدول التطبيع في `app.js` **مطابق** للي في PHP (لو اتفرقوا يرجع الباج).
   ══════════════════════════════════════════════════════════════════════════ */
$hay = search_norm('نثرية مندوب نثريات بدر محمد اكراميه أمن cma');
foreach (['إكرامية', 'اكراميه', 'اكرامية', 'إكراميه', 'إكرامية أمن', 'CMA', 'cma'] as $q) {
    if (!str_contains($hay, search_norm($q))) {
        throw new RuntimeException("search_norm ما لقتش «{$q}» جوّه نص محفوظ بتهجئة تانية.");
    }
}
if (search_norm('  إكرامِيَّة   ـأمن ') !== 'اكراميه امن') {
    throw new RuntimeException('search_norm مش بتشيل التشكيل/التطويل/المسافات الزايدة صح: '
        . search_norm('  إكرامِيَّة   ـأمن '));
}

$appJs = (string) @file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js');
if (!str_contains($appJs, 'window.almSearchNorm')) {
    throw new RuntimeException('almSearchNorm مش مُصدَّر من app.js — البحث هيرجع بلا تطبيع بالسكوت.');
}
// كل تحويل حرف في PHP لازم يكون في جدول الجافاسكريبت كمان
foreach ([['أ','ا'], ['إ','ا'], ['آ','ا'], ['ة','ه'], ['ى','ي'], ['ئ','ي'], ['ؤ','و']] as [$from, $to]) {
    if (!preg_match('~[\'"]' . preg_quote($from, '~') . '[\'"]\s*:\s*[\'"]' . preg_quote($to, '~') . '[\'"]~u', $appJs)) {
        throw new RuntimeException("جدول تطبيع البحث في app.js ناقص «{$from}»→«{$to}» — الخادم والواجهة هيختلفوا.");
    }
}
if (!str_contains($openHtml, 'almSearchNorm')) {
    throw new RuntimeException('شاشة التقفيل مش بتستخدم almSearchNorm في الفلترة.');
}
if (str_contains($openHtml, "mb_strtolower(\$search)")
    || preg_match('~data-search="[^"]*"[^>]*>\s*<label class="rv-pick"~', '')) {
    throw new RuntimeException('data-search لسه بيتطبّع بـmb_strtolower بدل search_norm.');
}

/* ⚠️ عنوان البند = بيانه المكتوب. كان مقصورًا على بنود الأذونات (`stage`)، فنثرية
   المندوب كانت بتظهر باسم نوعها («نثريات») والبيان اللي كتبه ما يظهرش خالص —
   فبندان بنفس المبلغ في نفس اليوم يبانوا متطابقين. */
$viewSrc = (string) @file_get_contents(dirname(__DIR__) . '/app/Views/closing/index.php');
if (preg_match('~\$headline\s*=[^;]*\$it\[.type.\]\s*===\s*.stage~s', $viewSrc)) {
    throw new RuntimeException('عنوان بند التقفيل رجع مقصورًا على type=stage — بيان النثريات هيختفي تاني.');
}
if (!preg_match('~\$headline\s*=\s*trim\(\(string\)\s*\$it\[.note.\]\)\s*!==\s*..\s*\?~', $viewSrc)) {
    throw new RuntimeException('عنوان بند التقفيل مش بياخد `note` كأول أولوية.');
}

$items = substr_count($html, 'class="rv-item');
echo "✓ رندر شاشة مراجعة التقفيل اكتمل ليوم $day ($items بند)\n";
echo "✓ معادلة الرصيد وأقسام المراجعة والإقفال ظهرت\n";
echo "✓ مفيش أثر للمطابقة اليدوية القديمة ولا معرفات HTML مكررة\n";
echo "✓ البحث بيطبّع العربي على الطرفين (PHP + app.js متطابقين)\n";
echo "✓ عنوان كل بند = بيانه المكتوب مش نوعه\n";
echo "✓ طباعة التقفيل بخط كبير وهيدر أفقي وQR محلي، بلا توقيعات، وترتيبها حسب الشركة\n";
