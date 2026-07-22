<?php
declare(strict_types=1);


// ═══ حارس: سطر الأوامر فقط ═══
// السكربتات دي بتعدّل القاعدة وبتاخد نسخ احتياطية، ومفيش فيها تسجيل دخول.
// حمايتها الأساسية إن .htaccess بيمنع مجلد bin/ من الويب — والسطر ده طبقة
// تانية مستقلة تشتغل حتى لو ذلك المنع وقع (صيانة استضافة، ملف اتمسح…).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * بوابة بنيوية صغيرة لأساس 07-A. لا تحمل bootstrap ولا تتصل بقاعدة بيانات.
 * الغرض منع رجوع أساسيات الوصولية والكاش عند تعميم المكونات لاحقًا.
 */

$root = dirname(__DIR__);
$sources = [
    'layout' => file_get_contents($root . '/app/Views/layout.php'),
    'login'  => file_get_contents($root . '/app/Views/auth/login.php'),
    'expense'=> file_get_contents($root . '/app/Views/expenses/form.php'),
    'css'    => file_get_contents($root . '/public/assets/css/app.css'),
    'appJs'  => file_get_contents($root . '/public/assets/js/app.js'),
    'loginJs'=> file_get_contents($root . '/public/assets/js/login.js'),
    'expenseJs'=> file_get_contents($root . '/public/assets/js/expenses-form.js'),
];

$failures = [];
$checks = 0;
$check = static function (bool $ok, string $message) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) $failures[] = $message;
};

foreach ($sources as $name => $source) {
    $check(is_string($source), "تعذر تحميل مصدر $name");
}
if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "✗ $failure\n");
    exit(1);
}

$layout = (string) $sources['layout'];
$login = (string) $sources['login'];
$expense = (string) $sources['expense'];
$css = (string) $sources['css'];
$appJs = (string) $sources['appJs'];
$loginJs = (string) $sources['loginJs'];
$expenseJs = (string) $sources['expenseJs'];

$checksBySource = [
    [$layout, 'lang="ar" dir="rtl"', 'التخطيط يعلن اللغة والاتجاه'],
    [$layout, 'class="skip-link" href="#main-content"', 'رابط تجاوز إلى المحتوى موجود'],
    [$layout, '<main class="content" id="main-content" tabindex="-1">', 'المحتوى الرئيسي قابل لاستقبال التركيز'],
    [$layout, 'aria-label="التنقل الرئيسي"', 'معلم التنقل الرئيسي مسمى'],
    [$layout, 'aria-current="page"', 'الصفحة الحالية معلنة لقارئ الشاشة'],
    [$layout, "assets/css/app.css') ?>?v=", 'CSS العام يستخدم cache busting'],
    [$layout, "assets/js/app.js') ?>?v=", 'JavaScript العام يستخدم cache busting'],
    [$layout, 'role="status" aria-live="polite"', 'رسالة النجاح معلنة بلا مقاطعة'],
    [$layout, 'role="alert" aria-live="assertive"', 'رسالة الخطأ معلنة فورًا'],
    [$login, '<main class="login-page" id="main-content">', 'شاشة الدخول تستخدم main'],
    [$login, 'for="login-username"', 'اسم المستخدم مرتبط بحقل صريح'],
    [$login, 'id="login-username"', 'حقل اسم المستخدم يملك id ثابتًا'],
    [$login, 'autocomplete="username"', 'اسم المستخدم يدعم مدير كلمات المرور'],
    [$login, 'for="login-password"', 'كلمة السر مرتبطة بحقل صريح'],
    [$login, 'id="login-password"', 'حقل كلمة السر يملك id ثابتًا'],
    [$login, 'autocomplete="current-password"', 'كلمة السر تعلن غرض الإكمال التلقائي'],
    [$login, "assets/css/app.css') ?>?v=", 'CSS الدخول يستخدم cache busting'],
    [$login, "assets/js/login.js') ?>?v=", 'سلوك الدخول خارجي ويستخدم cache busting'],
    [$css, ':focus-visible', 'حلقة تركيز مرئية موجودة'],
    [$css, '@media(prefers-reduced-motion:reduce)', 'تفضيل تقليل الحركة محترم'],
    [$css, '.form-error-summary', 'مكوّن ملخص أخطاء مشترك موجود'],
    [$css, '.empty{', 'مكوّن الحالة الفارغة المشترك موجود'],
    [$appJs, 'function activateDialog(container, preferred)', 'النوافذ تفعّل حبس التركيز'],
    [$appJs, 'function deactivateDialog(container)', 'النوافذ تعيد التركيز عند الإغلاق'],
    [$appJs, "e.key === 'Escape'", 'النوافذ تدعم زر Escape'],
    [$loginJs, "form.setAttribute('aria-busy', 'true')", 'نموذج الدخول يعلن حالة الإرسال'],
    [$expense, 'for="repSel"', 'اختيار المندوب مرتبط بتسمية صريحة'],
    [$expense, 'for="expense-date"', 'تاريخ المصروف مرتبط بتسمية صريحة'],
    [$expense, 'id="expense-date"', 'تاريخ المصروف يملك id ثابتًا'],
    [$expense, 'for="expense-amount"', 'مبلغ المصروف مرتبط بتسمية صريحة'],
    [$expense, 'id="expense-amount"', 'مبلغ المصروف يملك id ثابتًا'],
    [$expense, 'role="status" aria-live="polite"', 'شرح تخصيص المندوب معلن بلا مقاطعة'],
    [$expense, "assets/js/expenses-form.js') ?>?v=", 'سلوك المصروف خارجي ويستخدم cache busting'],
    [$expense, "visa_card_select(\$val('visa_card_id'), 'visa_card_id', '', 'expense-visa-card')", 'اختيار كارت المصروف يملك id ثابتًا'],
    [$expenseJs, 'kindWrap.hidden = !isRep', 'سلوك تخصيص المندوب يستخدم hidden الدلالية'],
    [$expenseJs, 'expenseOnly.forEach((element) => { element.hidden = isRep; })', 'حقول المصروف العامة تتبدل دلاليًا'],
];
foreach ($checksBySource as [$source, $needle, $message]) {
    $check(str_contains($source, $needle), $message);
}

$check(!preg_match('/<style\b/i', $login), 'شاشة الدخول لا تعيد CSS داخل القالب');
$check(!preg_match('/<script(?!\s+src=)[^>]*>/i', $login), 'شاشة الدخول لا تعيد JavaScript داخل القالب');
$check(!preg_match('/\sstyle\s*=/i', $expense), 'نموذج المصروف لا يعيد CSS inline داخل القالب');
$check(!preg_match('/<script(?!\s+src=)[^>]*>/i', $expense), 'نموذج المصروف لا يعيد JavaScript داخل القالب');

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "✗ $failure\n");
    fwrite(STDERR, 'فشلت ' . count($failures) . " من $checks فحصًا.\n");
    exit(1);
}

echo "✓ أساس الوصولية والعرض: $checks فحصًا بلا قاعدة بيانات.\n";
exit(0);
