<?php
/** فحص قراءة فقط لرندر وتنظيم صفحة التقفيل اليومي. */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\DailyClosingController;
use App\Core\Auth;
use App\Core\Database;

Auth::start();
$user = Database::row('SELECT id FROM users ORDER BY id LIMIT 1');
if (!$user) { throw new RuntimeException('لا يوجد مستخدم لفحص الرندر.'); }
$_SESSION['uid'] = (int)$user['id'];
$_SESSION['uname'] = 'فحص صفحة التقفيل';
$_SESSION['work_date'] = date('Y-m-d');
$_GET['date'] = Auth::workDate();
$_SERVER['REQUEST_URI'] = '/closing?date=' . rawurlencode($_GET['date']);

ob_start();
(new DailyClosingController())->index();
$html = ob_get_clean();

$needles = [
    'closing-page', 'cl-hero', 'cl-progress', 'cl-equation', 'day-review',
    'reconciliation', 'final-close', 'راجع حركة اليوم',
    'طابق اليدوي مع السيستم', 'ثبّت رصيد اليوم',
    'المصروفات النقدية', 'عهد السائقين', 'مدفوعات الفيزا المستبعدة',
];
foreach ($needles as $needle) {
    if (!str_contains($html, $needle)) { throw new RuntimeException('عنصر مفقود من رندر التقفيل: ' . $needle); }
}

preg_match_all('~\sid="([^"]+)"~', $html, $idMatches);
$duplicates = array_filter(array_count_values($idMatches[1] ?? []), fn($count) => $count > 1);
if ($duplicates) { throw new RuntimeException('معرفات HTML مكررة: ' . implode(', ', array_keys($duplicates))); }

preg_match_all('~<script(?:\s[^>]*)?>(.*?)</script>~s', $html, $scripts);
$closingJs = '';
foreach ($scripts[1] ?? [] as $script) {
    if (str_contains($script, "document.getElementById('reconForm')")) { $closingJs = $script; break; }
}
if (in_array('--js', $argv ?? [], true)) {
    echo $closingJs !== '' ? $closingJs : "'use strict';\n";
    exit(0);
}

echo "✓ رندر صفحة التقفيل الجديدة اكتمل بخطواتها الثلاث\n";
echo "✓ معادلة الرصيد وتفاصيل النقدي/السائقين/الفيزا ظهرت\n";
echo "✓ لا توجد معرفات HTML مكررة\n";
