<?php
/**
 * موجّه خادم PHP المدمج:
 *   - يخدم الملفات الثابتة (CSS/JS/الصور) مباشرة
 *   - يمرّر كل شيء آخر إلى الواجهة الأمامية index.php
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file) && !is_dir($file)) {
    return false; // دع الخادم المدمج يخدم الملف الثابت
}
require __DIR__ . '/index.php';
