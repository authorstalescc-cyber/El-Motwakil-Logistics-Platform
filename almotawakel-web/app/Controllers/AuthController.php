<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\FormState;
use App\Models\Audit;

class AuthController extends Controller
{
    public function showLogin(): void
    {
        if (Auth::check()) { redirect(''); }
        $state = FormState::consume('auth.login', 'login');
        $this->render('auth/login', ['title' => 'تسجيل الدخول', 'formState'=>$state], false);
    }

    public function login(): void
    {
        $this->requireCsrf();
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if (Auth::attempt($username, $password)) {
            $requestedFree = !empty($_POST['free_edit_mode']);
            Auth::setFreeEditMode($requestedFree);
            if ($requestedFree && !Auth::canFreeEdit()) {
                flash('error', 'حسابك لا يملك صلاحية الدخول الحر؛ تم الدخول بالوضع العادي.');
            }
            if (Auth::freeEditMode()) {
                Audit::log('login_free', 'users', (int)($_SESSION['uid'] ?? 0), null, null, 'تسجيل دخول بوضع التعديل الحر');
            }
            unset($_SESSION['work_date'], $_SESSION['work_date_set']); // اسأل عن تاريخ العمل (وختمه) من جديد في كل دخول
            redirect('work-date');
        }
        $this->formFailure('auth.login', 'login', [
            'username'=>$username,
            'free_edit_mode'=>!empty($_POST['free_edit_mode']) ? '1' : '',
        ], ['_form'=>Auth::failureMessage()], 'login');
    }

    public function logout(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        Auth::logout();
        // ⚠️ مش flash: `Auth::logout()` بينادي `session_destroy()` وبيمسح الكوكي،
        // فأي كتابة في $_SESSION بعدها بتضيع بالسكوت. الوسم في الرابط أضمن.
        redirect('login?bye=1');
    }
}
