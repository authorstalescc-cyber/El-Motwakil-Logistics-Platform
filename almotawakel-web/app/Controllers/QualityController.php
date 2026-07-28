<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\DataQuality;
use App\Models\Maintenance;

/**
 * شاشة تنظيف البيانات — `/quality`.
 *
 * بتقفل الدايرة مع حارس الأسماء: الحارس بيمنع الدنس الجديد، والشاشة دي بتنضّف
 * القديم **بقرار المالك** بدل زرار «توحيد المسميات» الأعمى القديم.
 *
 * ⚠️ للمالك والمدير بس (`Access::OWNER_MANAGER_ONLY`) — الدمج بيعدّل مئات
 *    الصفوف دفعة واحدة، وده مش إجراء يومي لأي موظف.
 */
class QualityController extends Controller
{
    /** نظرة عامة: كل حقل وكام مجموعة مشتبه فيها. */
    public function index(): void
    {
        $this->requireRole(['owner', 'manager']);
        // الرقم في الشارة بيتحدّث هنا وفي الكرون — **مش** في الجرس نفسه،
        // عشان `Notification` تفضل قراءة فقط (عقدها الموثَّق).
        DataQuality::refreshBadge();
        $this->render('quality/index', [
            'title'    => 'تنظيف البيانات',
            'overview' => DataQuality::overview(),
        ]);
    }

    /** صفحة حقل واحد — هنا بس بتجري المقارنة الكاملة (بطيئة). */
    public function field(string $key): void
    {
        $this->requireRole(['owner', 'manager']);
        $g = Maintenance::group($key);
        if (!$g) { $this->notFound('الحقل ده مش موجود في خريطة التنظيف.'); }

        $c = DataQuality::clusters($key);
        $this->render('quality/field', [
            'title'    => 'تنظيف — ' . $g['label'],
            'fieldKey' => $key,
            'group'    => $g,
            'clusters' => $c,
            'values'   => DataQuality::values($key),
        ]);
    }

    /** دمج تهجئات في الاسم المعتمد. */
    public function merge(string $key): void
    {
        $this->requireRole(['owner', 'manager']);
        $this->requireCsrf();

        $canonical = trim((string) ($_POST['canonical'] ?? ''));
        $variants  = (array) ($_POST['variants'] ?? []);
        try {
            $r = DataQuality::merge($key, $canonical, $variants);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 'quality/' . $key);
        }
        $this->done(
            'تم التوحيد على «' . $canonical . '» — ' . $r['rows'] . ' سجل اتحدّث'
            . ($r['lookup'] ? ' و' . $r['lookup'] . ' مفردة اتشالت من القائمة' : '') . '.',
            'quality/' . $key
        );
    }

    /** شيل مفردة غير مستخدَمة من القائمة المنسدلة. */
    public function drop(string $key): void
    {
        $this->requireRole(['owner', 'manager']);
        $this->requireCsrf();
        $value = trim((string) ($_POST['value'] ?? ''));
        try {
            DataQuality::dropLookup($key, $value);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 'quality/' . $key);
        }
        $this->done('اتشالت «' . $value . '» من القائمة.', 'quality/' . $key);
    }
}
