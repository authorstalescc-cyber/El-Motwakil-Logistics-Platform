<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\SecurityMonitor;

/**
 * لوحة المراقبة الأمنية — عرض فقط.
 *
 * ⚠️ **للمالك بس.** المنع الحقيقي مفروض مركزيًّا في `App\Core\Access::OWNER_ONLY`
 *    (بيتفحص **قبل** السماح العام لطلبات GET) — يعني الراوتر بيرفض قبل ما
 *    الكنترولر يشتغل أصلاً. الفحص هنا طبقة تانية، وإخفاء الرابط من الواجهة
 *    **مش حماية** (تحذير 5).
 *
 * ⚠️ الصفحة **ما فيهاش ولا فورم واحد** — كل إجراء (فك قفل، إيقاف حساب، تجديد
 *    رقم سري) بيتعمل من مكانه الأصلي في `/settings` و`/rep-custody`. كده مفيش
 *    مسار كتابة جديد نأمّنه.
 */
class SecurityController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();
        $this->requireRole('owner');

        $hours = (int) ($_GET['hours'] ?? SecurityMonitor::WINDOW_HOURS);
        if (!in_array($hours, [24, 72, 168, 720], true)) {
            $hours = SecurityMonitor::WINDOW_HOURS;
        }

        // ⚠️ المفتاح اسمه `sec` مش `data` عن قصد. `Controller::render` بتعمل
        //    `extract($data, EXTR_SKIP)` و**اسم معاملها نفسه `$data`** — يعني
        //    أي مفتاح اسمه `data` بيتخطّى **بصمت** والـview بيستقبل مصفوفة
        //    المتغيّرات كلها بدل بياناته. اتصادف فعليًا: الصفحة كانت بتقول
        //    «السجل مش متفعّل» وهو متفعّل. ما تسمّيش أي مفتاح `data` ولا `view`.
        $this->render('security/index', [
            'title' => 'المراقبة الأمنية',
            'sec'   => SecurityMonitor::overview($hours),
            'hours' => $hours,
        ]);
    }
}
