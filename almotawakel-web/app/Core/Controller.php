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

    /** حارس إضافي للعمليات الحساسة؛ السياسة المركزية في Access تظل الحارس الأول. */
    protected function requireRole(string|array $roles): void
    {
        $this->requireAuth();
        $roles = (array) $roles;
        if (!in_array(Auth::role(), $roles, true)) {
            http_response_code(403);
            if ($this->wantsJson()) {
                $this->json(['ok' => false, 'error' => 'ليست لديك صلاحية لتنفيذ هذا الإجراء.']);
                exit;
            }
            flash('error', 'ليست لديك صلاحية لتنفيذ هذا الإجراء.');
            redirect('');
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

    /** PRG موحد لفشل validation مع عزل القيم والأخطاء لكل تبويب. */
    protected function formFailure(string $formId, string $path, array $old, array $errors, string $fallbackBack): never
    {
        $token = FormState::store($formId, $old, $errors, $fallbackBack);
        redirect(FormState::redirectPath($path, $token));
    }

    /** منع تنفيذ طلب كتابة يحمل نفس رمز النموذج أكثر من مرة. */
    protected function claimForm(string $fallback): void
    {
        if (FormState::claim($_POST['_form_token'] ?? null)) { return; }
        if ($this->wantsJson()) {
            $message = 'هذا الطلب نُفّذ أو انتهت صلاحيته. راجع السجل قبل إعادة المحاولة.';
            $this->json(['ok' => false, 'flash' => $message, 'errors' => ['_form' => $message]]);
            exit;
        }
        flash('error', 'هذا الطلب نُفّذ أو انتهت صلاحيته. راجع السجل قبل إعادة المحاولة.');
        redirect(safe_back($fallback, $_POST['_back'] ?? null));
    }

    /** رد validation موحد لنماذج AJAX مع أخطاء مرتبطة بالحقول. */
    protected function jsonFormFailure(array $errors, string $fallback = 'تعذّر الحفظ'): never
    {
        $message = (string)($errors['_form'] ?? reset($errors) ?: $fallback);
        $this->json(['ok' => false, 'flash' => $message, 'errors' => $errors]);
        exit;
    }
}
