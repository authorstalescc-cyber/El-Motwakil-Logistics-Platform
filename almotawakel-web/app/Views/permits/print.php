<?php
use App\Core\App;

/**
 * كشف مراجعة كامل لإذن واحد — صفحة مستقلّة للطباعة/حفظ PDF (RTL).
 * الجسم الفعلي في الجزء المشترَك `_print_sheet.php`، والستايل في `_print_styles.php`.
 */
$company  = App::setting('company_name', 'شركة المتوكل');
$currency = App::setting('currency', 'ج.م');
$pid      = (int)$p['id'];

// اللوجو (مشترَك لكل الأوراق)
$logoRel = null;
foreach (['assets/img/logo.png', 'assets/img/logo.jpg', 'assets/img/logo.jpeg'] as $cand) {
    if (is_file(App::basePath('public/' . $cand))) { $logoRel = $cand; break; }
}

/**
 * closure يرندر جدول بنود مرحلة مالية (إيصالات/مصاريف/رسوم). يُستخدَم داخل _print_sheet.php.
 * يُرجع [html, total_amount, total_actual].
 */
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
  <button class="btn gold" onclick="__fitPrint()">🖨️ طباعة / حفظ PDF</button>
  <a class="btn ghost" href="<?= url('permits/'.$pid) ?>">‹ رجوع للإذن</a>
</div>

<?php include __DIR__ . '/_print_sheet.php'; ?>

<?php include __DIR__ . '/_print_fit.php'; ?>
</body>
</html>
