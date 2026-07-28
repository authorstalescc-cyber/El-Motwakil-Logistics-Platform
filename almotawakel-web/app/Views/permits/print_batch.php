<?php
use App\Core\App;

/**
 * طباعة مجمّعة لعدة أذونات — كل إذن ورقة كاملة تبدأ في صفحة جديدة عند الطباعة.
 * يستقبل $sheets = مصفوفة، كل عنصر بيانات إذن كاملة (كما يبنيها PermitController::printData).
 */
$company  = App::setting('company_name', 'شركة المتوكل');
$currency = App::setting('currency', 'ج.م');
$sheets   = $sheets ?? [];

// اللوجو (مشترَك لكل الأوراق)
$logoRel = null;
foreach (['assets/img/logo.png', 'assets/img/logo.jpg', 'assets/img/logo.jpeg'] as $cand) {
    if (is_file(App::basePath('public/' . $cand))) { $logoRel = $cand; break; }
}

// closure جدول البنود المشترَك (يستخدمه _print_sheet.php)
$stageTable = require __DIR__ . '/_print_stage_table.php';
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — <?= e($company) ?></title>
<?php include __DIR__ . '/_print_styles.php'; ?>
</head>
<body>

<div class="bar noprint">
  <span class="bar-count">📚 <?= intf(count($sheets)) ?> إذن — كل إذن في صفحة مستقلّة</span>
  <button class="btn gold" onclick="window.print()"><?= icon('file-text', 14) ?> طباعة الكل / حفظ PDF</button>
  <a class="btn ghost" href="<?= url('permits?view=records') ?>">‹ رجوع للسجلّات</a>
</div>

<?php foreach ($sheets as $sheet):
    // تفكيك بيانات كل إذن إلى النطاق قبل تضمين الورقة المشترَكة
    $p            = $sheet['p'];
    $movements    = $sheet['movements'];
    $permitStages = $sheet['permitStages'];
    $generators   = $sheet['generators'];
    $totals       = $sheet['totals'];
    $ctBreakdown  = $sheet['ctBreakdown'];
    $mvCtMap      = $sheet['mvCtMap'];
    $primaryDef   = $sheet['primaryDef'];
    $rcpCoverage  = $sheet['rcpCoverage'];
    include __DIR__ . '/_print_sheet.php';
endforeach; ?>

</body>
</html>
