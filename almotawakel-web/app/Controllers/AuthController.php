<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Models\Audit;

class AuthController extends Controller
{
    public function showLogin(): void
    {
        if (Auth::check()) { redirect(''); }
        $this->render('auth/login', ['title' => 'تسجيل الدخول'], false);
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
            unset($_SESSION['work_date']); // اسأل عن تاريخ العمل من جديد في كل دخول
            redirect('work-date');
        }
        flash('error', 'اسم المستخدم أو كلمة السر غير صحيحة.');
        $_SESSION['_old']['username'] = $username;
        redirect('login');
    }

    public function logout(): void
    {
        Auth::logout();
        redirect('login');
    }
}
