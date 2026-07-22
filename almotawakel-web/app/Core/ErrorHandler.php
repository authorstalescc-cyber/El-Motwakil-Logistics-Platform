<?php
namespace App\Core;

use Throwable;

/** يمنع تسريب الاستثناءات والمسارات وSQL للمتصفح ويحتفظ بالتفاصيل في سجل الخادم. */
class ErrorHandler
{
    private static bool $rendering = false;

    public static function register(): void
    {
        if (PHP_SAPI === 'cli') { return; }
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        set_exception_handler([self::class, 'exception']);
        register_shutdown_function([self::class, 'shutdown']);
    }

    public static function exception(Throwable $e): void
    {
        $id = self::log($e);
        self::render($id);
    }

    public static function shutdown(): void
    {
        $last = error_get_last();
        if (!$last || !in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) { return; }
        $id = bin2hex(random_bytes(6));
        error_log(sprintf('[%s] Fatal: %s in %s:%d', $id, $last['message'], $last['file'], $last['line']));
        self::render($id);
    }

    private static function log(Throwable $e): string
    {
        $id = bin2hex(random_bytes(6));
        error_log(sprintf("[%s] %s: %s in %s:%d\n%s", $id, $e::class, $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()));
        return $id;
    }

    private static function render(string $id): void
    {
        if (self::$rendering) { return; }
        self::$rendering = true;
        if (!headers_sent()) { http_response_code(500); }

        $json = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
            || str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        if ($json) {
            if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
            echo json_encode(['ok' => false, 'error' => 'حدث خطأ غير متوقع.', 'reference' => $id], JSON_UNESCAPED_UNICODE);
            return;
        }

        $errorId = $id;
        $view = App::basePath('app/Views/errors/500.php');
        if (is_file($view)) { require $view; return; }
        echo '<h1>حدث خطأ غير متوقع</h1><p>رقم المرجع: ' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '</p>';
    }
}
