<?php
namespace App\Core;

/**
 * المتحكّم الأساسي: عرض القوالب داخل الـ layout.
 */
class Controller
{
    /**
     * عرض قالب. $layout=true يلفّه بالتخطيط العام (الشريط الجانبي...).
     */
    public function render(string $view, array $data = [], bool $layout = true): void
    {
        extract($data, EXTR_SKIP);
        $viewFile = App::basePath('app/Views/' . $view . '.php');
        if (!is_file($viewFile)) {
            http_response_code(500);
            echo "القالب غير موجود: " . e($view);
            return;
        }

        if (!$layout) {
            require $viewFile;
            return;
        }

        // التقاط محتوى القالب داخل متغيّر $content ثم تمريره للـ layout
        ob_start();
        require $viewFile;
        $content = ob_get_clean();
        require App::basePath('app/Views/layout.php');
    }

    /** يرندر قالبًا (بدون layout) ويُرجعه كنص — لردود AJAX الجزئية. */
    protected function renderToString(string $view, array $data = []): string
    {
        ob_start();
        $this->render($view, $data, false);
        return ob_get_clean();
    }

    /** هل الطلب عبر AJAX (fetch/XMLHttpRequest)؟ */
    protected function wantsJson(): bool
    {
        return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    }

    protected function requireAuth(): void
    {
        if (!Auth::check()) {
            redirect('login');
        }
    }

    protected function requireCsrf(): void
    {
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            die('انتهت صلاحية الجلسة — أعد تحميل الصفحة وحاول مجدداً.');
        }
    }

    protected function json($data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
