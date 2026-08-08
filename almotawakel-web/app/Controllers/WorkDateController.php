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
        // اليوم اللي اترفض لأنه متقفل — بيتعرض له مخرج «شوفه للعرض» (تحت).
        $rejected = (string) ($_SESSION['_wd_locked'] ?? '');
        unset($_SESSION['_wd_locked']);
        $this->render('workdate/pick', [
            'title'    => 'تاريخ العمل',
            'current'  => Auth::workDate(),
            'today'    => date('Y-m-d'),
            'rejected' => $rejected,
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

        /* منع اختيار يوم متقفل (لن تُقبل عليه أي كتابة) — نوجّه المستخدم لفتحه أو اختيار غيره.
           ⚠️ **الرفض ده كان مصيدة مقفولة** (بلاغ المالك 2026-08-09): الشاشة دي
              برّه التخطيط وبلا زر رجوع، فالرفض بيرجّعك لنفس الشاشة — والمخرج
              الوحيد كان **تسجيل الخروج**. دلوقتي بنسيب أثرًا يخلّي الشاشة تعرض
              مخرجين: «شوف اليوم ده للعرض» (القفل بيمنع الكتابة مش القراءة)
              و«ابدأ بتاريخ النهاردة». */
        if (($msg = Closing::guard($date)) !== null) {
            $_SESSION['_wd_locked'] = $date;
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
    /** يقبل مسارًا داخليًا فقط — نفس مُنضِّف كل الرجوع في النظام. */
    private function safeReturn(string $path): string
    {
        return safe_back('', $path);
    }
}
