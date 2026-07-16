<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Models\Closing;

/**
 * تحديد «تاريخ العمل» (Working Date): التاريخ الذي تُنسب إليه كل المدخلات
 * الجديدة افتراضيًا. يُسأل عنه المستخدم عند كل دخول، ويمكن تغييره في أي وقت
 * من البانر أعلى الصفحات. لا يمسّ لحظة التسجيل الفعلية (created_at).
 */
class WorkDateController extends Controller
{
    /** شاشة اختيار تاريخ العمل (تظهر عقب تسجيل الدخول، وعند التغيير اليدوي). */
    public function show(): void
    {
        $this->requireAuth();
        $this->render('workdate/pick', [
            'title'   => 'تاريخ العمل',
            'current' => Auth::workDate(),
            'today'   => date('Y-m-d'),
        ], false);
    }

    /** حفظ تاريخ العمل المختار ثم العودة للوجهة المطلوبة. */
    public function save(): void
    {
        $this->requireAuth();
        $this->requireCsrf();

        $date = trim($_POST['work_date'] ?? '');
        $back = $this->safeReturn($_POST['return'] ?? '');

        if (!Auth::setWorkDate($date)) {
            flash('error', 'التاريخ المُدخل غير صحيح.');
            redirect('work-date');
        }

        // منع اختيار يوم متقفل (لن تُقبل عليه أي كتابة) — نوجّه المستخدم لفتحه أو اختيار غيره.
        if (($msg = Closing::guard($date)) !== null) {
            flash('error', $msg);
            redirect('work-date');
        }

        if (Auth::isWorkDateToday()) {
            flash('success', 'تعمل الآن بتاريخ اليوم.');
        } else {
            flash('success', 'تم ضبط تاريخ العمل على ' . dfmt($date) . ' — كل المدخلات الجديدة ستُسجَّل بهذا التاريخ.');
        }
        redirect($back);
    }

    /** يقبل فقط مسارًا داخليًا نسبيًا (يمنع الـ open-redirect). */
    private function safeReturn(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) {
            return '';
        }
        // نزيل قاعدة التطبيق إن وُجدت لأن redirect()/url() تعيد إضافتها.
        $base = \App\Core\App::baseUrl();
        if ($base && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        return ltrim($path, '/');
    }
}
