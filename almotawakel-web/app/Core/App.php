<?php
namespace App\Core;

/**
 * حاوية التطبيق: الإعدادات + المسارات + الإقلاع.
 */
class App
{
    private static array $config = [];
    private static array $settingsCache = [];

    public static function boot(): void
    {
        self::$config = require dirname(__DIR__, 2) . '/config/config.php';
        // يسمح لاختبارات المثبّت فقط باستخدام قاعدة معزولة دون لمس config.php.
        // القيد على CLI والبادئة يمنع تحوير اتصال تطبيق الويب أو اختيار قاعدة عامة.
        $testDb = (string) getenv('ALMOTAWAKEL_TEST_DB');
        if (in_array(PHP_SAPI, ['cli', 'cli-server'], true) && $testDb !== '') {
            if (!preg_match('/^almotawakel_test_[a-z0-9_]{6,48}$/', $testDb)) {
                throw new \RuntimeException('اسم قاعدة الاختبار ALMOTAWAKEL_TEST_DB غير آمن.');
            }
            self::$config['db']['name'] = $testDb;
        }
        date_default_timezone_set(self::$config['timezone'] ?? 'Africa/Cairo');
        mb_internal_encoding('UTF-8');
    }

    public static function config(?string $key = null)
    {
        if ($key === null) {
            return self::$config;
        }
        return self::$config[$key] ?? null;
    }

    public static function basePath(string $path = ''): string
    {
        return dirname(__DIR__, 2) . ($path ? '/' . ltrim($path, '/') : '');
    }

    /** الرابط الأساسي للتطبيق (يدعم التشغيل داخل مجلد فرعي) */
    public static function baseUrl(): string
    {
        // مع router script، خادم PHP المدمج يضع مسار الطلب نفسه في SCRIPT_NAME؛
        // اعتباره مجلدًا أساسيًا يكسر كل رابط متداخل مثل /permits/12.
        if (PHP_SAPI === 'cli-server') { return ''; }
        $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $script = rtrim($script, '/');
        // إذا كان جذر الويب هو public/ فالمسار الأساسي هو ''
        return $script === '/public' ? '' : $script;
    }

    public static function url(string $path = ''): string
    {
        return self::baseUrl() . '/' . ltrim($path, '/');
    }

    /** قراءة إعداد من جدول settings (مع كاش) */
    public static function setting(string $key, $default = null)
    {
        if (empty(self::$settingsCache)) {
            foreach (Database::all('SELECT setting_key, setting_value FROM settings') as $row) {
                self::$settingsCache[$row['setting_key']] = $row['setting_value'];
            }
        }
        return self::$settingsCache[$key] ?? $default;
    }

    public static function setSetting(string $key, string $value): void
    {
        Database::exec(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [$key, $value]
        );
        self::$settingsCache[$key] = $value;
    }
}
