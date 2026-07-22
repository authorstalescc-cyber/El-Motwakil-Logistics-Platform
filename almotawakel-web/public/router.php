<?php
/**
 * موجّه خادم PHP المدمج:
 *   - يخدم الملفات الثابتة (CSS/JS/الصور) مباشرة
 *   - يمرّر كل شيء آخر إلى الواجهة الأمامية index.php
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;
// المرفقات القديمة كانت داخل public. نمنع خدمتها مباشرة؛ العرض يمر عبر
// FileController بعد التحقق من جلسة المكتب أو ملكية المندوب.
if (preg_match('#^/uploads/(permits|reps)/#', (string) $path)) {
    http_response_code(404);
    exit('Not Found');
}
if ($path !== '/' && is_file($file) && !is_dir($file)) {
    return false; // دع الخادم المدمج يخدم الملف الثابت
}
require __DIR__ . '/index.php';
