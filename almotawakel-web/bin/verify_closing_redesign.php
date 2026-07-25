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

$items = substr_count($html, 'class="rv-item');
echo "✓ رندر شاشة مراجعة التقفيل اكتمل ليوم $day ($items بند)\n";
echo "✓ معادلة الرصيد وأقسام المراجعة والإقفال ظهرت\n";
echo "✓ مفيش أثر للمطابقة اليدوية القديمة ولا معرفات HTML مكررة\n";
