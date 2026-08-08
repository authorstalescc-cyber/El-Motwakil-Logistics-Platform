<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\FormState;
use App\Models\Audit;
use App\Models\OfficeCar;

/**
 * سيارات المكتب — أداء كل سيارة ورحلاتها ومصاريفها (v54).
 *
 * ⚠️ **الوحدة قراءة فقط من البوالص.** الرحلات والحاويات والعهد بتتقرا من
 *    `bawalis` بمطابقة اللوحة، والكتابة الوحيدة هنا على `office_cars`
 *    (سجل السيارات) و`car_expenses` (مصاريف السيارة، **معزولة عن الخزنة**).
 *
 * ⚠️ **الفترة بتتمرّر لكل نافذة تفاصيل** (`?p=` و`?d=`). لو النافذة اتحسبت
 *    على فترة تانية هتقول رقمًا مختلفًا عن الشاشة — نفس درس `/payments`
 *    الموثَّق في المرحلة ١٤.
 */
class OfficeCarController extends Controller
{
    /** تفاصيل أرقام الأسطول (بلا سيارة محدَّدة). */
    private const DRILL_FLEET = ['trips', 'containers', 'freight', 'custody', 'expenses', 'net', 'cars'];

    /** تفاصيل أرقام سيارة واحدة — لازم `?car=` لسيارة موجودة فعلاً. */
    private const DRILL_CAR = [
        'car_trips', 'car_containers', 'car_freight', 'car_custody', 'car_expenses', 'car_net',
        'car_companies', 'car_ports', 'car_agencies', 'car_destinations', 'car_drivers', 'car_mix',
    ];

    // ═══════════════════════════════════════════════ اللوحة (الأسطول)

    public function index(): void
    {
        $this->requireAuth();
        $p = $this->period();
        $fleet = OfficeCar::fleet($p['from'], $p['to']);
        $prev  = OfficeCar::fleet($p['prev_from'], $p['prev_to']);

        foreach ($fleet as &$c) { $c['last_activity'] = OfficeCar::lastActivity((int) $c['id']); }
        unset($c);

        $ids     = array_map(static fn ($c) => (int) $c['id'], $fleet);
        $summary = OfficeCar::fleetSummary($fleet);
        $prevSum = $p['prev_from'] === null ? null : OfficeCar::fleetSummary($prev);
        // ⚠️ `fleetSummary` بترجّع **نفس مفاتيح `perf`** (trips/containers/
        //    freight/custody/expenses/net) عن قصد، فالمعدلات والقراءة السريعة
        //    بتشتغل على الأسطول وعلى السيارة الواحدة بنفس الكود بلا تحويل.
        $daily  = OfficeCar::daily($ids, $p['from'], $p['to']);
        $trend  = OfficeCar::trend($ids, $p['kind'], 12, $_GET['d'] ?? null);
        $rates  = OfficeCar::rates($summary, $p, $daily);
        $trips  = [];
        foreach ($ids as $cid) { $trips = array_merge($trips, OfficeCar::trips($cid, $p['from'], $p['to'])); }

        $this->render('office_cars/index', [
            'title'    => 'سيارات المكتب',
            'period'   => $p,
            'fleet'    => $fleet,
            'summary'  => $summary,
            'prevSum'  => $prevSum,
            'trend'    => $trend,
            'rates'    => $rates,
            'insights' => OfficeCar::insights($summary, $prevSum, $rates, $trips, $p, $trend),
            'orphans'  => OfficeCar::unregisteredOfficePlates(),
            'ignored'  => OfficeCar::ignoredDetail(),
        ]);
    }

    // ═══════════════════════════════════════════════ كارت السيارة

    public function card(string $id): void
    {
        $this->requireAuth();
        $car = OfficeCar::find((int) $id);
        if (!$car) { $this->notFound('السيارة دي مش موجودة في السجل.'); }

        $p = $this->period();
        $trips = OfficeCar::trips((int) $id, $p['from'], $p['to']);

        // ⚠️ وضع تعديل مصروف — `?edit_exp=` بيقلب نموذج «سجّل مصروف» لنموذج
        //    «عدّل المصروف». المصروف لازم يكون **بتاع السيارة دي**: الرابط مش
        //    مصدر ثقة، ومن غير الفحص ده تقدر تعدّل مصروف سيارة تانية من هنا.
        $editExp = null;
        if (($_GET['edit_exp'] ?? '') !== '') {
            $row = OfficeCar::findExpense((int) $_GET['edit_exp']);
            if ($row && (int) $row['car_id'] === (int) $id) { $editExp = $row; }
        }
        $state = $editExp
            ? FormState::consume('cars.expense.edit.' . (int) $editExp['id'], $this->carUrl((int) $id))
            : FormState::consume('cars.expense.' . (int) $id, $this->carUrl((int) $id));

        $perf  = OfficeCar::perf((int) $id, $p['from'], $p['to']);
        $prevP = $p['prev_from'] === null ? null : OfficeCar::perf((int) $id, $p['prev_from'], $p['prev_to']);
        $daily = OfficeCar::daily([(int) $id], $p['from'], $p['to']);
        $trend = OfficeCar::trend([(int) $id], $p['kind'], 12, $_GET['d'] ?? null);
        $rates = OfficeCar::rates($perf, $p, $daily);

        $this->render('office_cars/card', [
            'title'    => 'سيارة: ' . $car['name'],
            'car'      => $car,
            'period'   => $p,
            'perf'     => $perf,
            'prevPerf' => $prevP,
            'trend'    => $trend,
            'rates'    => $rates,
            'insights' => OfficeCar::insights($perf, $prevP, $rates, $trips, $p, $trend),
            'lifetime' => OfficeCar::perf((int) $id),
            'trips'    => $trips,
            'expenses' => OfficeCar::expenses((int) $id, $p['from'], $p['to']),
            'byCat'    => OfficeCar::expensesByCategory((int) $id, $p['from'], $p['to']),
            'mix'      => OfficeCar::containerMix($trips),
            'companies'=> OfficeCar::breakdown($trips, 'company'),
            'ports'    => OfficeCar::breakdown($trips, 'port'),
            'drivers'  => OfficeCar::breakdown($trips, 'driver'),
            'expState' => $state,
            'editExp'  => $editExp,
        ]);
    }

    /** تقرير أداء A4 للطباعة/حفظ PDF — صفحة مستقلة بلا القائمة الجانبية. */
    public function report(string $id): void
    {
        $this->requireAuth();
        $car = OfficeCar::find((int) $id);
        if (!$car) { $this->notFound('السيارة دي مش موجودة في السجل.'); }
        $p = $this->period();
        $trips = OfficeCar::trips((int) $id, $p['from'], $p['to']);
        $this->render('office_cars/report', [
            'title'     => 'تقرير أداء — ' . $car['name'],
            'car'       => $car,
            'period'    => $p,
            'perf'      => OfficeCar::perf((int) $id, $p['from'], $p['to']),
            'trips'     => $trips,
            'byCat'     => OfficeCar::expensesByCategory((int) $id, $p['from'], $p['to']),
            'companies' => OfficeCar::breakdown($trips, 'company'),
            'mix'       => OfficeCar::containerMix($trips),
        ], false);
    }

    // ═══════════════════════════════════════════════ نوافذ التفاصيل

    /**
     * ⚠️ **مسار خاص بالوحدة — مش مسار تفاصيل عام** ياخد اسم جدول ويرجّع صفوف.
     *    النوع محصور في قائمة بيضا، وبيمرّ على `Access::enforce` زي أي مسار
     *    (الرفض هو الافتراضي — تحذير ٥). بيرندر بلا layout ويرجّع HTML عشان
     *    الصفوف تستخدم نفس `row_item` بتاع الشاشة.
     */
    public function drill(string $what): void
    {
        $this->requireAuth();
        $isCar = in_array($what, self::DRILL_CAR, true);
        if (!$isCar && !in_array($what, self::DRILL_FLEET, true)) {
            $this->notFound('نوع التفاصيل ده مش موجود.');
        }

        $p = $this->period();
        $data = ['what' => $what, 'period' => $p];

        if ($isCar) {
            $carId = (int) ($_GET['car'] ?? 0);
            $car = OfficeCar::find($carId);
            if (!$car) { $this->notFound('السيارة دي مش موجودة في السجل.'); }
            $data['car']   = $car;
            $data['trips'] = OfficeCar::trips($carId, $p['from'], $p['to']);
            $data['perf']  = OfficeCar::perf($carId, $p['from'], $p['to']);
            $data['expenses'] = OfficeCar::expenses($carId, $p['from'], $p['to']);
            $data['byCat'] = OfficeCar::expensesByCategory($carId, $p['from'], $p['to']);
        } else {
            $fleet = OfficeCar::fleet($p['from'], $p['to']);
            $data['fleet'] = $fleet;
            $data['summary'] = OfficeCar::fleetSummary($fleet);
        }

        // ⚠️ محروسة بـ`headers_sent()`: أي مخرَج سابق بيخلّي `header()` ترمي
        //    Warning **جوّه جسم الرد** فالنافذة تستقبل HTML متلوّث (باج v50).
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: private, no-store, max-age=0');
        }
        $this->render('office_cars/_drill', $data, false);
    }

    /**
     * فحص حيّ: اللوحة دي سيارة مكتب؟ (بيستخدمه فورم البوليصة)
     *
     * ⚠️ **موجود عشان التطبيع يفضل في مكان واحد.** فورم البوليصة محتاج يعرف
     *    وهو شغّال إذا كانت السيارة بتاعتنا عشان يظهر خانات المصروفات؛ ونسخة
     *    من `plateKey()` في الجافاسكريبت كانت هتفترق مع أول تعديل فالخانات
     *    تختفي عن سيارة مكتب **بلا أي خطأ ظاهر**. نفس نمط فحص رقم البوليصة.
     */
    public function isOffice(): void
    {
        $this->requireAuth();
        $car = OfficeCar::matchPlate((string) ($_GET['plate'] ?? ''));
        $this->json([
            'office' => $car !== null,
            'name'   => $car['name'] ?? '',
            'plate'  => $car['plate'] ?? '',
        ]);
        exit;   // ⚠️ إجبارية — `json()` بتطبع وما بتوقفش (باج v50)
    }

    // ═══════════════════════════════════════════════ إضافة/تعديل سيارة

    public function create(): void
    {
        $this->requireAuth();
        $state = FormState::consume('cars.car.create', 'cars');
        $defaults = ['name' => '', 'plate' => '', 'aliases' => '', 'model' => '',
                     'driver' => '', 'status' => 'active', 'note' => ''];
        $this->render('office_cars/form', [
            'title'  => 'إضافة سيارة',
            'row'    => array_replace($defaults, $state['old']),
            'action' => url('cars'),
            'isEdit' => false,
            'errors' => $state['errors'],
            'formToken' => $state['token'],
            'back'   => $state['back'],
        ]);
    }

    public function store(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $d = $this->collect();
        $errors = $this->validate($d, 0);
        if ($errors) {
            $this->formFailure('cars.car.create', 'cars/new', $_POST, $errors, 'cars');
        }
        $this->claimForm('cars');
        $id = OfficeCar::create($d);
        Audit::log('create', 'office_car', $id, null, $d, 'إضافة سيارة مكتب: ' . $d['plate']);
        $this->done('اتضافت السيارة.', $this->carUrl($id));
    }

    public function edit(string $id): void
    {
        $this->requireAuth();
        $row = OfficeCar::find((int) $id);
        if (!$row) { $this->notFound('السيارة دي مش موجودة في السجل.'); }
        $state = FormState::consume('cars.car.edit.' . (int) $id, $this->carUrl((int) $id));
        $this->render('office_cars/form', [
            'title'  => 'تعديل سيارة',
            'row'    => array_replace($row, $state['old']),
            'action' => url('cars/' . (int) $id),
            'isEdit' => true,
            'errors' => $state['errors'],
            'formToken' => $state['token'],
            'back'   => $state['back'],
        ]);
    }

    public function update(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $old = OfficeCar::find((int) $id);
        if (!$old) { $this->fail('السيارة دي مش موجودة في السجل.', 'cars'); }
        $d = $this->collect();
        $errors = $this->validate($d, (int) $id);
        if ($errors) {
            $this->formFailure('cars.car.edit.' . (int) $id, 'cars/' . (int) $id . '/edit',
                $_POST, $errors, $this->carUrl((int) $id));
        }
        $this->claimForm($this->carUrl((int) $id));
        OfficeCar::update((int) $id, $d);
        Audit::log('update', 'office_car', (int) $id, $old, $d, 'تعديل سيارة مكتب: ' . $d['plate']);
        $this->done('اتحفظت التعديلات.', $this->carUrl((int) $id));
    }

    public function destroy(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $car = OfficeCar::find((int) $id);
        if (!$car) { $this->fail('السيارة دي مش موجودة في السجل.', 'cars'); }
        $this->claimForm('cars');
        OfficeCar::delete((int) $id);
        Audit::log('delete', 'office_car', (int) $id, $car, null,
            'حذف سيارة مكتب: ' . $car['plate'] . ' (البوالص ما اتلمستش)');
        $this->done('اتشالت السيارة من السجل. رحلاتها في البوالص زي ما هي.', 'cars');
    }

    // ═══════════════════════════════════════════════ اللوحات المتجاهَلة

    /**
     * «اللوحة دي مش بتاعتنا» — بتتشال من تنبيه اللوحات غير المسجَّلة.
     *
     * ⚠️ لازمة لأن `bawalis.car_owner` بيتكتب بالإيد، ولوحات عربيات مستأجرة
     *    اتكتب قدامها «المكتب» غلط. من غير الإخفاء ده التنبيه بيفضل يزنّ
     *    للأبد و**إعادة تشغيل ترحيل v54 كانت هترجّع تسجّلها**.
     *    البوليصة نفسها ما بتتلمسش — تصحيح `car_owner` فيها قرار المالك.
     */
    public function ignorePlate(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $plate = trim((string) ($_POST['plate'] ?? ''));
        if ($plate === '' || OfficeCar::plateKey($plate) === '') {
            $this->fail('اللوحة دي مش مفهومة.', 'cars');
        }
        $this->claimForm('cars');
        OfficeCar::ignorePlate($plate);
        OfficeCar::clearCache();
        $this->done('تمام — «' . $plate . '» مش هتظهر في التنبيه تاني.', 'cars');
    }

    public function unignorePlate(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $key = trim((string) ($_POST['key'] ?? ''));
        if ($key === '') { $this->fail('اللوحة دي مش مفهومة.', 'cars'); }
        $this->claimForm('cars');
        OfficeCar::unignorePlate($key);
        OfficeCar::clearCache();
        $this->done('رجعت تظهر في التنبيه.', 'cars');
    }

    // ═══════════════════════════════════════════════ مصاريف السيارة

    public function storeExpense(string $id): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $car = OfficeCar::find((int) $id);
        if (!$car) { $this->fail('السيارة دي مش موجودة في السجل.', 'cars'); }

        $d = $this->collectExpense() + ['created_by' => (string) (Auth::user() ?? '')];
        $errors = $this->validateExpense($d);
        if ($errors) {
            $this->formFailure('cars.expense.' . (int) $id, 'cars/' . (int) $id . '#expense-form',
                $_POST, $errors, $this->carUrl((int) $id));
        }

        $this->claimForm($this->carUrl((int) $id, 'expense-form'));
        $eid = OfficeCar::addExpense((int) $id, $d);
        Audit::log('create', 'car_expense', $eid, null, $d,
            'مصروف سيارة (' . $car['plate'] . '): ' . OfficeCar::kindLabel($d['category']),
            ['work_date' => $d['exp_date']]);
        $this->done('اتسجّل المصروف على السيارة.', $this->carUrl((int) $id), 'expenses-panel');
    }

    /**
     * تعديل مصروف مسجَّل.
     *
     * ⚠️ الفشل بيرجّع الفورم على **وضع التعديل** (`?edit_exp=`) لا على وضع
     *    الإضافة، وإلا المالك يلاقي بياناته في نموذج «سجّل مصروف» فيضيف نسخة
     *    تانية بدل ما يصلّح الأصلية.
     */
    public function updateExpense(string $eid): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $old = OfficeCar::findExpense((int) $eid);
        if (!$old) { $this->fail('المصروف ده مش موجود أو اتمسح قبل كده.', 'cars'); }
        $carId = (int) $old['car_id'];
        $car = OfficeCar::find($carId);
        if (!$car) { $this->fail('السيارة دي مش موجودة في السجل.', 'cars'); }

        $d = $this->collectExpense();
        $errors = $this->validateExpense($d);
        if ($errors) {
            $this->formFailure('cars.expense.edit.' . (int) $eid,
                $this->carUrl($carId, 'expense-form', ['edit_exp' => (int) $eid]),
                $_POST, $errors, $this->carUrl($carId));
        }

        $this->claimForm($this->carUrl($carId, 'expense-form'));
        OfficeCar::updateExpense((int) $eid, $d);
        Audit::log('update', 'car_expense', (int) $eid, $old, $d,
            'تعديل مصروف سيارة (' . $car['plate'] . '): ' . OfficeCar::kindLabel($d['category']),
            ['work_date' => $d['exp_date']]);
        $this->done('اتحفظ تعديل المصروف.', $this->carUrl($carId), 'expenses-panel');
    }

    public function deleteExpense(string $eid): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $row = OfficeCar::findExpense((int) $eid);
        if (!$row) { $this->fail('المصروف ده مش موجود أو اتمسح قبل كده.', 'cars'); }
        $carId = (int) $row['car_id'];
        $this->claimForm($this->carUrl($carId));
        OfficeCar::deleteExpense((int) $eid);
        Audit::log('delete', 'car_expense', (int) $eid, $row, null, 'حذف مصروف سيارة');
        $this->done('اتشال المصروف.', $this->carUrl($carId), 'expenses-panel');
    }

    // ═══════════════════════════════════════════════ أدوات

    /**
     * ⚠️ **المصدر الوحيد لمسار كارت السيارة** — لازم يحمل الفترة معاه، وإلا
     *    الرجوع بعد الحفظ بيرمي المالك على فترة تانية وأرقام مختلفة.
     */
    private function carUrl(int $id, string $anchor = '', array $extra = []): string
    {
        $q = [];
        if (($_POST['p'] ?? $_GET['p'] ?? '') !== '') { $q['p'] = (string) ($_POST['p'] ?? $_GET['p']); }
        if (($_POST['d'] ?? $_GET['d'] ?? '') !== '') { $q['d'] = (string) ($_POST['d'] ?? $_GET['d']); }
        $q += $extra;
        return 'cars/' . $id . ($q ? '?' . http_build_query($q) : '') . ($anchor !== '' ? '#' . $anchor : '');
    }

    /**
     * حقول مصروف السيارة — **مصدر واحد للإضافة والتعديل**.
     *
     * ⚠️ لو اتكتبت مرتين، أول حقل جديد يتضاف في ناحية وينساه التانية فيتحفظ
     *    في الإضافة ويضيع في التعديل **بلا أي خطأ ظاهر**.
     */
    private function collectExpense(): array
    {
        return [
            'exp_date' => $this->cleanDate($_POST['exp_date'] ?? '') ?: work_date(),
            'category' => (string) ($_POST['category'] ?? 'other'),
            'amount'   => ($_POST['amount'] ?? '') === '' ? 0 : (float) $_POST['amount'],
            'ref_no'   => trim((string) ($_POST['ref_no'] ?? '')),
            'vendor'   => trim((string) ($_POST['vendor'] ?? '')),
            'note'     => trim((string) ($_POST['note'] ?? '')),
        ];
    }

    private function validateExpense(array $d): array
    {
        $errors = [];
        if ((float) $d['amount'] <= 0) { $errors['amount'] = 'اكتب مبلغ أكبر من صفر.'; }
        if (!isset(OfficeCar::EXPENSE_KINDS[$d['category']])) { $errors['category'] = 'اختار نوع المصروف.'; }
        return $errors;
    }

    /** الفترة المختارة من الرابط (`p` = day|week|month|all، `d` = يوم الارتكاز). */
    private function period(): array
    {
        $kind = (string) ($_GET['p'] ?? 'month');
        $anchor = $this->cleanDate($_GET['d'] ?? '') ?: work_date();
        return OfficeCar::period($kind, $anchor);
    }

    private function collect(): array
    {
        return [
            'name'    => trim((string) ($_POST['name'] ?? '')),
            'plate'   => trim((string) ($_POST['plate'] ?? '')),
            'aliases' => trim((string) ($_POST['aliases'] ?? '')),
            'model'   => trim((string) ($_POST['model'] ?? '')),
            'driver'  => trim((string) ($_POST['driver'] ?? '')),
            'status'  => ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            'note'    => trim((string) ($_POST['note'] ?? '')),
        ];
    }

    private function validate(array $d, int $exceptId): array
    {
        $errors = [];
        if ($d['plate'] === '') {
            $errors['plate'] = 'اكتب رقم لوحة السيارة.';
            return $errors;
        }
        $key = OfficeCar::plateKey($d['plate']);
        if ($key === '') {
            $errors['plate'] = 'رقم اللوحة مش مفهوم — اكتبه بالأرقام والحروف.';
        } elseif (OfficeCar::plateTaken($key, $exceptId)) {
            $errors['plate'] = 'فيه سيارة مسجَّلة بنفس اللوحة دي بالفعل.';
        }
        return $errors;
    }

    private function cleanDate($v): string
    {
        $v = trim((string) $v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    }
}
